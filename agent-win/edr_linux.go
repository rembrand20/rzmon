//go:build linux

package main

// Endpoint-sensoren (EDR, 0.30.0): dezelfde sensoren als de bash-agent (agent/src/10-edr-sensoren.sh en het EDR-deel van
// 60-ids-en-edr.sh). Aan per server in RzSense (regel "0 <tab> edr <tab> 1"); EDR=0 zet het op deze server altijd uit.
//   - processen (auditd, execve), bewaakte paden (accounts, sudo, cron, systemd, ssh) en kernelmodules;
//   - netwerk (ss, elke 5 s): nieuwe uitgaande verbindingen en nieuwe luisterpoorten;
//   - bestanden (inotify): nieuwe programma's in /tmp, /var/tmp en /dev/shm, en webshells in webmappen;
//   - containers (podman/docker events): exec, en starten met --privileged.
// De sensoren draaien in de watcher (rzsense watch) en zetten elke gebeurtenis in een wachtrij; het rapport neemt er
// hooguit 500 per keer mee. RzSense beoordeelt ze; de agent beslist zelf niets.

import (
	"bufio"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

func edrDir() string { return dataDir() }

// edrJ: tekst veilig binnen "…" (zoals edr_j): \ en " met een backslash, stuurtekens als spatie
func edrJ(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, `"`, `\"`)
	return strings.Map(func(r rune) rune {
		if r >= 1 && r <= 0x1f {
			return ' '
		}
		return r
	}, s)
}

// edrEsc: zoals esc() in de awk-parsers: ook DEL wordt een spatie
func edrEsc(s string) string {
	b := make([]byte, 0, len(s)+8)
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c == '\\':
			b = append(b, '\\', '\\')
		case c == '"':
			b = append(b, '\\', '"')
		case (c >= 1 && c <= 0x1f) || c == 0x7f:
			b = append(b, ' ')
		default:
			b = append(b, c)
		}
	}
	return string(b)
}

func cutBytes(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

// edrEmit: in de wachtrij (hooguit 2 MB); daarboven tellen als weggegooid
func edrEmit(line string) {
	spool := filepath.Join(edrDir(), "edr.spool")
	if st, err := os.Stat(spool); err == nil && st.Size() > 2097152 {
		n := atoiDef(readText(filepath.Join(edrDir(), "edr.drop")), 0)
		_ = os.WriteFile(filepath.Join(edrDir(), "edr.drop"), []byte(fmt.Sprintf("%d\n", n+1)), 0o600)
		return
	}
	if fh, err := os.OpenFile(spool, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
		_, _ = fh.WriteString(line + "\n")
		fh.Close()
	}
}

// ---------- auditd-log → gebeurtenissen ----------

var (
	digitsRe   = regexp.MustCompile(`^[0-9]+$`)
	numRunRe   = regexp.MustCompile(`[0-9]+`)
	hexEvenRe  = regexp.MustCompile(`^[0-9A-Fa-f]+$`)
	ownUnitsRe = regexp.MustCompile(`/(rzmon|rzsense)-[^/]*\.service`)
	phpExeRe   = regexp.MustCompile(`/php[0-9.]*$|php-fpm`)
	susParent  = regexp.MustCompile(`^(nginx|apache2|httpd|lighttpd|caddy|php|uwsgi|gunicorn)`)
	susExe     = regexp.MustCompile(`^/(tmp|var/tmp|dev/shm|run/shm)/`)
	susHidden  = regexp.MustCompile(`/\.[^/]*$`)
	susUser    = regexp.MustCompile(`^(www-data|apache|nginx|http|wwwrun|lighttpd)$`)
	susCmd     = regexp.MustCompile(`(/dev/(tcp|udp)/|\| *(sudo +)?(ba|z|da)?sh( |$)|base64 +(-d|--decode)|(^| )(nc|ncat|netcat|socat|telnet|xmrig|insmod|chattr|mkfifo|curl|wget)( |$)|stratum\+|authorized_keys|history +-c|histfile|ld\.so\.preload|python[0-9.]* +-c|perl +-e|php +-r|ruby +-e)`)
)

type auditParser struct {
	root, user, mark  string // van RzSense zelf (regel edrself)
	pidFile, dropFile string
	proc              string // "/proc" (voor de tests een andere map)
	un                map[string]string
	cur, ekey         string
	argc              int
	av                map[int]string
	pname, cwd        string
	pid, ppid, uid    string
	euid, auid        string
	comm, exe         string
	seen              map[string]bool
	own               map[string]string
	ownN              int
	minute            int64
	sent, drop        int
	reset             int64
	out               func(string)
}

func newAuditParser(root, user, mark string, out func(string)) *auditParser {
	p := &auditParser{root: root, user: user, mark: mark, proc: "/proc", un: map[string]string{}, av: map[int]string{}, seen: map[string]bool{}, own: map[string]string{}, out: out,
		pidFile: filepath.Join(edrDir(), "own.pids"), dropFile: filepath.Join(edrDir(), "edr.drop")}
	for _, l := range strings.Split(readText("/etc/passwd"), "\n") {
		if f := strings.Split(l, ":"); len(f) >= 3 {
			p.un[f[2]] = f[0]
		}
	}
	return p
}

func aNum(v string) string {
	if digitsRe.MatchString(v) {
		return v
	}
	return "0"
}

// firstLine: de eerste regel van een bestand (zoals getline in awk), zonder regeleinde
func firstLine(path string) string {
	s := readText(path)
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return s[:i]
	}
	return s
}

func auditFv(l, k string) string {
	p := strings.Index(l, " "+k+"=")
	if p < 0 {
		return ""
	}
	r := l[p+len(k)+2:]
	if strings.HasPrefix(r, `"`) {
		r = r[1:]
		if q := strings.IndexByte(r, '"'); q >= 0 {
			return r[:q]
		}
		return r
	}
	if q := strings.IndexByte(r, ' '); q >= 0 {
		return r[:q]
	}
	return r
}

func auditQuoted(l, k string) bool {
	p := strings.Index(l, " "+k+"=")
	return p >= 0 && p+len(k)+2 < len(l) && l[p+len(k)+2] == '"'
}

func auditUnhex(s string) string {
	s = strings.ToUpper(s)
	var b strings.Builder
	for i := 0; i+1 < len(s); i += 2 {
		c, _ := strconv.ParseUint(s[i:i+2], 16, 8)
		switch {
		case c >= 32 && c < 127:
			b.WriteByte(byte(c))
		case c == 0:
			b.WriteByte(' ')
		default:
			b.WriteByte('?')
		}
	}
	return b.String()
}

func auditArg(l, k string) string {
	v := auditFv(l, k)
	if auditQuoted(l, k) {
		return v
	}
	if v == "(null)" {
		return ""
	}
	if hexEvenRe.MatchString(v) && len(v)%2 == 0 {
		return auditUnhex(v)
	}
	return v
}

func (p *auditParser) agentPid(pid string) bool {
	if pid == "0" || pid == "" {
		return false
	}
	for _, l := range strings.Split(readText(p.pidFile), "\n") {
		if l == pid {
			return true
		}
	}
	return false
}

// gpid: de ouder van de ouder (uit /proc/<p>/stat, na de naam tussen haakjes)
func (p *auditParser) gpid(pid string) string {
	s := firstLine(p.proc + "/" + pid + "/stat")
	i := strings.Index(s, ") ")
	if pid == "0" || pid == "" || i < 0 {
		return "0"
	}
	if f := strings.Fields(s[i+2:]); len(f) >= 2 {
		return aNum(f[1])
	}
	return "0"
}

// owner: van RzSense zelf? "agent" (de agent en wat hij start), "rzmon" (RzSense op zijn eigen server) of leeg
func (p *auditParser) owner(pc, u, cmd string) string {
	pp := aNum(p.ppid)
	if o, ok := p.own[pp]; ok {
		return o
	}
	cg := firstLine(p.proc+"/"+pp+"/cgroup") + firstLine(p.proc+"/"+aNum(p.pid)+"/cgroup")
	agentName := func(s string) bool { return strings.HasPrefix(s, "rzmon-agent") || strings.HasPrefix(s, "rzsense") }
	switch {
	case ownUnitsRe.MatchString(cg):
		return "agent"
	case p.uid == "0" && (agentName(p.comm) || agentName(pc)):
		return "agent"
	case p.uid == "0" && (p.agentPid(pp) || p.agentPid(p.gpid(pp))):
		return "agent"
	case p.mark != "" && (p.uid == "0" || u == p.user) && strings.Contains(cmd, "rzmon:"+p.mark):
		return "rzmon"
	case p.root != "" && (p.uid == "0" || u == p.user) && strings.Contains(cmd, p.root+"/cron/run.php"):
		return "rzmon"
	case p.root != "" && phpExeRe.MatchString(p.exe) && (p.uid == "0" || u == p.user) && (strings.HasPrefix(p.cwd, p.root) || strings.Contains(cmd, p.root+"/cron/") || strings.Contains(cmd, p.root+"/install/")):
		return "rzmon"
	case p.user != "" && u == p.user && strings.HasPrefix(pc, "php") && strings.HasPrefix(p.cwd, p.root):
		return "rzmon"
	}
	return ""
}

func (p *auditParser) flush() {
	if p.cur == "" || p.ekey == "" {
		p.cur = ""
		return
	}
	defer func() { p.cur = "" }()
	tsS := p.cur
	if i := strings.Index(tsS, "."); i >= 0 {
		tsS = tsS[:i]
	}
	ts := int64(awkNum(tsS))
	if ts-p.reset > 600 {
		p.seen = map[string]bool{}
		p.reset = ts
	}
	u := p.uid
	if n, ok := p.un[p.uid]; ok {
		u = n
	}
	if p.ekey == "rzmon_exec" {
		pc := firstLine(p.proc + "/" + aNum(p.ppid) + "/comm")
		cmd := ""
		for i := 0; i < p.argc && i < 16; i++ {
			if i > 0 {
				cmd += " "
			}
			cmd += p.av[i]
		}
		if ow := p.owner(pc, u, cmd); ow != "" {
			if p.ownN++; p.ownN > 5000 {
				p.own = map[string]string{}
				p.ownN = 0
			}
			p.own[aNum(p.pid)] = ow
			// Eigen processen: per programma en ouder hooguit eens per 10 minuten, en niet binnen de grens van 200
			k := numRunRe.ReplaceAllString("own|"+p.exe+"|"+pc, "#")
			if p.seen[k] {
				return
			}
			p.seen[k] = true
			p.out(fmt.Sprintf(`{"k":"x","t":%d,"pid":%s,"ppid":%s,"uid":%s,"u":"%s","exe":"%s","comm":"%s","pc":"%s","cwd":"%s","cmd":"%s","own":"%s"}`,
				ts, aNum(p.pid), aNum(p.ppid), aNum(p.uid), edrEsc(u), edrEsc(p.exe), edrEsc(p.comm), edrEsc(pc), edrEsc(p.cwd), edrEsc(cutBytes(cmd, 300)), ow))
			return
		}
		sus := susParent.MatchString(pc) || susExe.MatchString(p.exe) || susHidden.MatchString(p.exe) || susUser.MatchString(u) || susCmd.MatchString(strings.ToLower(cmd))
		k := numRunRe.ReplaceAllString(p.exe+"|"+pc+"|"+p.uid+"|"+cutBytes(cmd, 200), "#")
		if !sus && p.seen[k] {
			return
		}
		p.seen[k] = true
		if ts/60 != p.minute {
			p.minute, p.sent = ts/60, 0
		}
		if p.sent++; p.sent > 200 {
			p.drop++
			_ = os.WriteFile(p.dropFile, []byte(fmt.Sprintf("%d\n", p.drop)), 0o600)
			return
		}
		st := ""
		if f := strings.Split(firstLine(p.proc+"/"+aNum(p.pid)+"/stat"), " "); len(f) >= 22 && digitsRe.MatchString(f[21]) {
			st = `,"st":` + f[21]
		}
		var b strings.Builder
		fmt.Fprintf(&b, `{"k":"x","t":%d,"pid":%s,"ppid":%s%s,"uid":%s,"euid":%s,"auid":%s,"u":"%s","exe":"%s","comm":"%s","pc":"%s","cwd":"%s","argv":[`,
			ts, aNum(p.pid), aNum(p.ppid), st, aNum(p.uid), aNum(p.euid), aNum(p.auid), edrEsc(u), edrEsc(p.exe), edrEsc(p.comm), edrEsc(pc), edrEsc(p.cwd))
		for i := 0; i < p.argc && i < 16; i++ {
			if i > 0 {
				b.WriteString(",")
			}
			b.WriteString(`"` + edrEsc(cutBytes(p.av[i], 200)) + `"`)
		}
		b.WriteString(`],"cmd":"` + edrEsc(cutBytes(cmd, 1000)) + `","sc":"execve"}`)
		p.out(b.String())
		return
	}
	nm := p.pname
	if nm != "" && !strings.HasPrefix(nm, "/") && p.cwd != "" {
		nm = p.cwd + "/" + nm
	}
	k := p.ekey + "|" + nm + "|" + p.exe
	if p.seen[k] {
		return
	}
	p.seen[k] = true
	pc := firstLine(p.proc + "/" + aNum(p.ppid) + "/comm")
	ow, ok := p.own[aNum(p.pid)]
	if !ok {
		ow = p.owner(pc, u, "")
	}
	owS := ""
	if ow != "" {
		owS = `,"own":"` + ow + `"`
	}
	p.out(fmt.Sprintf(`{"k":"a","t":%d,"key":"%s","pid":%s,"uid":%s,"auid":%s,"u":"%s","exe":"%s","comm":"%s","name":"%s"%s}`,
		ts, edrEsc(p.ekey), aNum(p.pid), aNum(p.uid), aNum(p.auid), edrEsc(u), edrEsc(p.exe), edrEsc(p.comm), edrEsc(nm), owS))
}

// feed: één regel uit audit.log
func (p *auditParser) feed(line string) {
	if g := strings.IndexByte(line, 0x1d); g >= 0 { // ENRICHED-formaat: alleen het ruwe deel
		line = line[:g]
	}
	line = " " + line
	m := strings.Index(line, "msg=audit(")
	if m < 0 {
		return
	}
	id := line[m+10:]
	if i := strings.Index(id, ")"); i >= 0 {
		id = id[:i]
	} else {
		id = ""
	}
	if id != p.cur {
		p.flush()
		p.cur, p.ekey, p.argc, p.av, p.pname, p.cwd = id, "", 0, map[int]string{}, "", ""
	}
	switch auditFv(line, "type") {
	case "SYSCALL":
		p.ekey = auditFv(line, "key")
		if !strings.HasPrefix(p.ekey, "rzmon_") {
			p.ekey = ""
		}
		p.pid, p.ppid, p.uid, p.euid, p.auid = auditFv(line, "pid"), auditFv(line, "ppid"), auditFv(line, "uid"), auditFv(line, "euid"), auditFv(line, "auid")
		p.comm, p.exe = auditArg(line, "comm"), auditArg(line, "exe")
	case "EXECVE":
		p.argc = int(awkNum(auditFv(line, "argc")))
		for i := 0; i < p.argc && i < 16; i++ {
			p.av[i] = auditArg(line, fmt.Sprintf("a%d", i))
		}
	case "CWD":
		p.cwd = auditArg(line, "cwd")
	case "PATH":
		if auditFv(line, "nametype") != "PARENT" {
			p.pname = auditArg(line, "name")
		}
	case "EOE":
		p.flush()
	}
}

// ---------- Suricata: alerts uit eve.json ----------

func eveStr(l, k string) string {
	p := strings.Index(l, `"`+k+`":"`)
	if p < 0 {
		return ""
	}
	r := l[p+len(k)+4:]
	// tot het eerste aanhalingsteken zonder backslash ervoor
	end := -1
	for i := 0; i < len(r); i++ {
		if r[i] == '"' && (i == 0 || r[i-1] != '\\') {
			end = i
			break
		}
	}
	if end < 0 {
		return ""
	}
	r = r[:end]
	r = strings.ReplaceAll(r, `\"`, `"`)
	return strings.ReplaceAll(r, `\\`, `\`)
}

func eveNum(l, k string) int64 {
	p := strings.Index(l, `"`+k+`":`)
	if p < 0 {
		return 0
	}
	return int64(awkNum(l[p+len(k)+3:]))
}

// eveAlerts: [tijd,bron,poort,doel,poort,protocol,sid,"melding","categorie",ernst,"actie"],… (hooguit 200), als JSON-tekst
func eveAlerts(text string) string {
	var b strings.Builder
	n := 0
	for _, l := range strings.Split(text, "\n") {
		if !strings.Contains(l, `"event_type":"alert"`) || n >= 200 {
			continue
		}
		if n > 0 {
			b.WriteString(",")
		}
		n++
		fmt.Fprintf(&b, `["%s","%s",%d,"%s",%d,"%s",%d,"%s","%s",%d,"%s"]`, edrEsc(cutBytes(eveStr(l, "timestamp"), 32)), edrEsc(eveStr(l, "src_ip")), eveNum(l, "src_port"),
			edrEsc(eveStr(l, "dest_ip")), eveNum(l, "dest_port"), edrEsc(eveStr(l, "proto")), eveNum(l, "signature_id"), edrEsc(cutBytes(eveStr(l, "signature"), 200)),
			edrEsc(cutBytes(eveStr(l, "category"), 80)), eveNum(l, "severity"), edrEsc(eveStr(l, "action")))
	}
	return b.String()
}

// ---------- van RzSense zelf? ----------

type edrSelf struct {
	root, user, mark string
	rzIPs            map[string]bool
}

var markRe = regexp.MustCompile(`^[0-9a-f]{16,64}$`)

// edrSelfFrom: de map en gebruiker van RzSense (alleen op zijn eigen server van belang), het geheime kenmerk in de
// opdrachten van RzSense, en de adressen van RzSense. Dat wordt gelogd als "toegestaan", nooit als aanval.
func edrSelfFrom(tests string, c *Config) edrSelf {
	s := edrSelf{rzIPs: map[string]bool{}}
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "edrself" {
			g := tabRead(strings.Join(f[2:], "\t"), 3)
			s.root, s.user, s.mark = g[0], g[1], g[2]
			break
		}
	}
	if !markRe.MatchString(s.mark) {
		s.mark = ""
	}
	if len(s.root) < 2 || s.root[0] != '/' {
		s.root = ""
	}
	for _, u := range []string{c.URL, c.URLFallback} {
		if u == "" {
			continue
		}
		h := urlHost(u)
		s.rzIPs[h] = true
		for _, a := range hostAddrs(h) {
			s.rzIPs[a] = true
		}
	}
	return s
}

// own: agent, rzmon, net (verkeer met RzSense) of leeg
func (s edrSelf) own(pid int, comm, user, ip string) string {
	if pid > 0 {
		if ownUnitsRe.MatchString(readText(fmt.Sprintf("/proc/%d/cgroup", pid))) {
			return "agent"
		}
		if s.root != "" && (user == "root" || user == s.user) && strings.HasPrefix(comm, "php") {
			cwd, _ := os.Readlink(fmt.Sprintf("/proc/%d/cwd", pid))
			cmd := strings.ReplaceAll(readText(fmt.Sprintf("/proc/%d/cmdline", pid)), "\x00", " ")
			if strings.HasPrefix(cwd, s.root) || strings.Contains(cmd, s.root+"/cron/") {
				return "rzmon"
			}
		}
	}
	if ip != "" && s.rzIPs[ip] {
		return "net"
	}
	return ""
}

// edrProc: starttijd, programma en gebruiker van een proces (leeg als het al weg is)
func edrProc(pid int) (st, exe, user string) {
	if pid <= 0 {
		return
	}
	f := strings.Fields(readText(fmt.Sprintf("/proc/%d/stat", pid)))
	if len(f) == 0 {
		return
	}
	if len(f) >= 22 {
		st = f[21]
	}
	exe, _ = os.Readlink(fmt.Sprintf("/proc/%d/exe", pid))
	if fi, err := os.Stat(fmt.Sprintf("/proc/%d", pid)); err == nil {
		if sys, ok := fi.Sys().(*syscall.Stat_t); ok {
			user = idNames.user(sys.Uid)
		}
	}
	return
}

// ---------- netwerk: nieuwe uitgaande verbindingen en nieuwe luisterpoorten ----------

var ssFirstUserRe = regexp.MustCompile(`users:\(\("([^"]*)",pid=([0-9]+)`)

type netOut struct {
	pid                  int
	comm, rip, rp, lport string
}

// edrNetNew: uit "ss -tnpH state established" de verbindingen die het afgelopen uur nog niet gezien zijn (zelfde
// programma, adres en poort hooguit eens per uur). stateFile onthoudt wat gezien is.
func edrNetNew(established, listen, stateFile string, now int64) []netOut {
	lp := map[string]bool{}
	for _, l := range strings.Split(listen, "\n") {
		if f := strings.Fields(l); len(f) >= 4 {
			lp[f[3][strings.LastIndex(f[3], ":")+1:]] = true
		}
	}
	seen := map[string]int64{}
	order := []string{}
	for _, l := range strings.Split(readText(stateFile), "\n") {
		if a := strings.Split(l, "\t"); len(a) >= 2 {
			if t := int64(awkNum(a[1])); now-t < 3600 {
				if _, ok := seen[a[0]]; !ok {
					order = append(order, a[0])
				}
				seen[a[0]] = t
			}
		}
	}
	out := []netOut{}
	for _, l := range strings.Split(established, "\n") {
		f := strings.Fields(l)
		// awk splitst hier op spaties én tabs met het lege eerste veld erbij: een regel die met een spatie begint schuift op
		if strings.HasPrefix(l, " ") || strings.HasPrefix(l, "\t") {
			f = append([]string{""}, f...)
		}
		if len(f) < 4 {
			continue
		}
		lport := f[2][strings.LastIndex(f[2], ":")+1:]
		if lp[lport] {
			continue
		}
		rport := f[3][strings.LastIndex(f[3], ":")+1:]
		rip := ""
		if n := len(f[3]) - len(rport) - 1; n > 0 {
			rip = f[3][:n]
		}
		rip = strings.TrimSuffix(strings.TrimPrefix(rip, "["), "]")
		rip = strings.TrimPrefix(rip, "::ffff:")
		if strings.HasPrefix(rip, "127.") || rip == "::1" || rip == "" {
			continue
		}
		comm, pid := "", 0
		if m := ssFirstUserRe.FindStringSubmatch(l); m != nil {
			comm, pid = m[1], atoiDef(m[2], 0)
		}
		k := comm + "|" + rip + "|" + rport
		if _, ok := seen[k]; ok {
			continue
		}
		seen[k] = now
		order = append(order, k)
		out = append(out, netOut{pid, comm, rip, rport, lport})
	}
	var b strings.Builder
	for _, k := range order {
		fmt.Fprintf(&b, "%s\t%d\n", k, seen[k])
	}
	_ = os.WriteFile(stateFile, []byte(b.String()), 0o600)
	return out
}

type netListen struct {
	pid         int
	comm, lport string
}

// edrListenNew: nieuwe luisterpoorten (programma + poort); de eerste keer alleen vastleggen
func edrListenNew(listen, stateFile string, first bool) []netListen {
	seen := map[string]bool{}
	for _, l := range strings.Split(readText(stateFile), "\n") {
		seen[l] = true
	}
	now := map[string]bool{}
	out := []netListen{}
	for _, l := range strings.Split(listen, "\n") {
		f := strings.Fields(l)
		if len(f) < 4 {
			continue
		}
		port := f[3][strings.LastIndex(f[3], ":")+1:]
		comm, pid := "", 0
		if m := ssFirstUserRe.FindStringSubmatch(l); m != nil {
			comm, pid = m[1], atoiDef(m[2], 0)
		}
		k := comm + "|" + port
		if !seen[k] && !first {
			out = append(out, netListen{pid, comm, port})
		}
		now[k] = true
	}
	keys := make([]string, 0, len(now))
	for k := range now {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	_ = os.WriteFile(stateFile, []byte(strings.Join(keys, "\n")+"\n"), 0o600)
	return out
}

func digitsOnly(s string) string {
	return strings.Map(func(r rune) rune {
		if r >= '0' && r <= '9' {
			return r
		}
		return -1
	}, s)
}

// edrNetOnce: één ronde van de netwerksensor; geeft de gebeurtenissen terug (de eerste keer geen)
func edrNetOnce(self edrSelf, first bool) []string {
	now := time.Now().Unix()
	listen, _ := stdoutOf(10*time.Second, "ss", "-Htlnp")
	est, _ := stdoutOf(10*time.Second, "ss", "-tnpH", "state", "established")
	ev := []string{}
	opt := func(k, v string) string {
		if v == "" {
			return ""
		}
		return `,"` + k + `":` + v
	}
	orZero := func(s string) string {
		if s == "" {
			return "0"
		}
		return s
	}
	newOut := edrNetNew(est, listen, filepath.Join(edrDir(), "net.state"), now)
	if !first {
		for _, o := range newOut {
			st, exe, u := edrProc(o.pid)
			ow := self.own(o.pid, o.comm, u, o.rip)
			own := ""
			if ow != "" {
				own = `,"own":"` + ow + `"`
			}
			ev = append(ev, fmt.Sprintf(`{"k":"n","t":%d,"dir":"out","pid":%d%s,"comm":"%s","exe":"%s","u":"%s","rip":"%s","rp":%s,"lp":%s%s}`,
				now, o.pid, opt("st", st), edrJ(o.comm), edrJ(exe), edrJ(u), edrJ(o.rip), orZero(digitsOnly(o.rp)), orZero(digitsOnly(o.lport)), own))
		}
	}
	for _, o := range edrListenNew(listen, filepath.Join(edrDir(), "listen.state"), first) {
		st, exe, u := edrProc(o.pid)
		ow := self.own(o.pid, o.comm, u, "")
		own := ""
		if ow != "" {
			own = `,"own":"` + ow + `"`
		}
		ev = append(ev, fmt.Sprintf(`{"k":"n","t":%d,"dir":"listen","pid":%d%s,"comm":"%s","exe":"%s","u":"%s","lp":%s%s}`,
			now, o.pid, opt("st", st), edrJ(o.comm), edrJ(exe), edrJ(u), orZero(digitsOnly(o.lport)), own))
	}
	return ev
}

// ---------- bestanden: nieuwe programma's en webshells ----------

var (
	webExtRe    = regexp.MustCompile(`\.(php|phtml|php[3-8]|phar|jsp|jspx|asp|aspx|cgi)$`)
	tmpScriptRe = regexp.MustCompile(`\.(sh|py|pl|php|elf|bin)$`)
	tmpLibRe    = regexp.MustCompile(`(\.so|\.so\..*|\.o)$`)
	shellCodeRe = regexp.MustCompile(`(?i)(eval|assert|system|passthru|shell_exec|proc_open|popen)[[:space:]]*\(.*\$_(POST|GET|REQUEST|COOKIE)|\$_(POST|GET|REQUEST|COOKIE).*(eval|assert|system|passthru|shell_exec)[[:space:]]*\(|eval[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|str_rot13|gzuncompress)`)
	shellWordRe = regexp.MustCompile(`(?i)(eval|assert|system|passthru|shell_exec|proc_open|popen|base64_decode|gzinflate|str_rot13)`)
	yaraNameRe  = regexp.MustCompile(`^[A-Za-z0-9_]+$`)
)

// edrYara: de namen van de YARA-regels die raak zijn (de regels komen van RzSense), met komma's
func edrYara(path string) string {
	rules := filepath.Join(edrDir(), "rzmon.yar")
	if st, err := os.Stat(rules); err != nil || st.Size() == 0 || !haveCmd("yara") {
		return ""
	}
	out, _ := stdoutOf(10*time.Second, "yara", "-w", rules, path)
	names := []string{}
	for _, l := range lines(out) {
		if f := strings.Fields(l); len(f) > 0 && yaraNameRe.MatchString(f[0]) && len(names) < 8 {
			names = append(names, f[0])
		}
	}
	return strings.Join(names, ",")
}

// edrFile: een nieuw of gewijzigd bestand beoordelen; geeft de gebeurtenis terug, of leeg als het niets bijzonders is
func edrFile(p string) string {
	lst, err := os.Lstat(p)
	if err != nil || !lst.Mode().IsRegular() {
		return ""
	}
	sz := lst.Size()
	if sz <= 0 || sz > 20971520 {
		return ""
	}
	elf, x, susp, yr := 0, 0, "", ""
	if strings.HasPrefix(p, "/tmp/") || strings.HasPrefix(p, "/var/tmp/") || strings.HasPrefix(p, "/dev/shm/") {
		if tmpLibRe.MatchString(p) {
			return ""
		}
		head := make([]byte, 18)
		if fh, err := os.Open(p); err == nil {
			n, _ := io.ReadFull(fh, head)
			fh.Close()
			if n >= 17 && string(head[:4]) == "\x7fELF" && (head[16] == 2 || head[16] == 3) {
				elf = 1
			}
		}
		if syscall.Access(p, 1) == nil { // uitvoerbaar voor de agent (zoals [ -x ])
			x = 1
		}
		if elf != 1 && (x != 1 || !tmpScriptRe.MatchString(p)) {
			return ""
		}
	} else {
		if !webExtRe.MatchString(p) {
			return ""
		}
		// Met YARA elk nieuw script in een webmap; anders alleen als het op webshell-code lijkt
		yr = edrYara(p)
		body := readText(p)
		if yr == "" {
			hit := false
			for _, l := range strings.Split(body, "\n") {
				if shellCodeRe.MatchString(l) {
					hit = true
					break
				}
			}
			if !hit {
				return ""
			}
		}
		words := []string{}
		for _, l := range strings.Split(body, "\n") {
			for _, w := range shellWordRe.FindAllString(l, -1) {
				words = append(words, strings.ToLower(w))
			}
		}
		words = sortedUnique(words)
		if len(words) > 4 {
			words = words[:4]
		}
		susp = strings.Join(words, ",")
	}
	sha, _ := sha256File(p)
	if yr == "" {
		yr = edrYara(p)
	}
	extra := ""
	if susp != "" {
		extra += `,"susp":"` + edrJ(susp) + `"`
	}
	if yr != "" {
		extra += `,"yara":"` + edrJ(yr) + `"`
	}
	return fmt.Sprintf(`{"k":"f","t":%d,"path":"%s","size":%d,"elf":%d,"x":%d,"sha":"%s"%s}`, time.Now().Unix(), edrJ(p), sz, elf, x, sha, extra)
}

// edrFileRoots: de mappen die de bestandssensor volgt (alleen mappen met hooguit 5000 submappen: inotify heeft een grens)
func edrFileRoots() []string {
	roots := []string{}
	for _, d := range []string{"/tmp", "/var/tmp", "/dev/shm", "/run/shm", "/var/www", "/srv/www", "/srv/http", "/usr/share/nginx/html"} {
		lst, err := os.Lstat(d)
		if err != nil || !lst.IsDir() {
			continue
		}
		n := 1
		walkDirsOneFs(d, func() bool { n++; return n <= 5001 })
		if n <= 5000 {
			roots = append(roots, d)
		}
	}
	return roots
}

// walkDirsOneFs: de submappen tellen zonder andere bestandssystemen in te gaan; visit geeft false terug om te stoppen
func walkDirsOneFs(root string, visit func() bool) {
	rst, err := os.Lstat(root)
	if err != nil {
		return
	}
	dev := rst.Sys().(*syscall.Stat_t).Dev
	var walk func(dir string) bool
	walk = func(dir string) bool {
		ents, err := os.ReadDir(dir)
		if err != nil {
			return true
		}
		for _, e := range ents {
			if !e.IsDir() {
				continue
			}
			p := filepath.Join(dir, e.Name())
			st, err := os.Lstat(p)
			if err != nil {
				continue
			}
			if !visit() {
				return false
			}
			if st.Sys().(*syscall.Stat_t).Dev != dev {
				continue
			}
			if !walk(p) {
				return false
			}
		}
		return true
	}
	walk(root)
}

// ---------- containers: exec, en starten (met --privileged gemarkeerd) ----------

var (
	jsonNameRe   = regexp.MustCompile(`.*"Name":"([^"]*)"`)
	jsonImageRe  = regexp.MustCompile(`.*"Image":"([^"]*)"`)
	jsonStatusRe = regexp.MustCompile(`.*"Status":"([^"]*)"`)
	jsonLNameRe  = regexp.MustCompile(`.*"name":"([^"]*)"`)
	jsonLImageRe = regexp.MustCompile(`.*"image":"([^"]*)"`)
	jsonActionRe = regexp.MustCompile(`.*"Action":"([^"]*)"`)
)

func lastMatch(re *regexp.Regexp, l string) string {
	if m := re.FindStringSubmatch(l); m != nil {
		return m[1]
	}
	return ""
}

// edrCtrEvent: één regel van "podman events" of "docker events" → naam, image, actie en opdracht
func edrCtrEvent(rt, l string) (name, img, act, cmd string) {
	if rt == "podman" {
		return lastMatch(jsonNameRe, l), lastMatch(jsonImageRe, l), lastMatch(jsonStatusRe, l), ""
	}
	name, img, act = lastMatch(jsonLNameRe, l), lastMatch(jsonLImageRe, l), lastMatch(jsonActionRe, l)
	if c := strings.TrimPrefix(act, "exec_start: "); c != act {
		cmd = c
	}
	if i := strings.Index(act, ":"); i >= 0 {
		act = act[:i]
	}
	if act == "exec_start" {
		act = "exec"
	}
	return
}

func edrCtrJSON(rt, name, img, act, cmd string, priv int) string {
	return fmt.Sprintf(`{"k":"c","t":%d,"rt":"%s","act":"%s","name":"%s","img":"%s","priv":%d,"cmd":"%s"}`, time.Now().Unix(), rt, edrJ(act), edrJ(name), edrJ(img), priv, edrJ(cutBytes(cmd, 300)))
}

// ---------- auditd-regels ----------

var edrKeys = []string{"rzmon_exec", "rzmon_id", "rzmon_persist", "rzmon_mod"}

func auditRuleCount() int {
	out, _ := stdoutOf(10*time.Second, "auditctl", "-l")
	return strings.Count(out, "key=rzmon_")
}

// edrRules: de regels (sleutels rzmon_*) zetten zolang het in RzSense aan staat, en weghalen als het uit gaat. Ze staan
// alleen in de kernel, niet in /etc/audit: na een herstart zet de eerstvolgende ronde ze terug.
func edrRules(on bool) bool {
	if !haveCmd("auditctl") {
		return false
	}
	have := auditRuleCount()
	del := func() {
		for _, k := range edrKeys {
			_, _ = run(10*time.Second, "auditctl", "-D", "-k", k)
		}
	}
	if !on {
		if have > 0 {
			del()
			actLog("edr", 1, "EDR-regels uit auditd gehaald", "")
		}
		return true
	}
	if have >= 3 {
		return true
	}
	del()
	for _, a := range []string{"b64", "b32"} {
		_, _ = run(10*time.Second, "auditctl", "-a", "always,exit", "-F", "arch="+a, "-S", "execve", "-k", "rzmon_exec")
	}
	watch := func(key string, paths ...string) {
		for _, p := range paths {
			if _, err := os.Lstat(p); err == nil {
				_, _ = run(10*time.Second, "auditctl", "-w", p, "-p", "wa", "-k", key)
			}
		}
	}
	watch("rzmon_id", "/etc/passwd", "/etc/shadow", "/etc/group", "/etc/sudoers", "/etc/sudoers.d")
	watch("rzmon_persist", "/etc/ld.so.preload", "/etc/crontab", "/etc/cron.d", "/var/spool/cron", "/etc/systemd/system", "/root/.ssh")
	_, _ = run(10*time.Second, "auditctl", "-a", "always,exit", "-F", "arch=b64", "-S", "init_module", "-S", "finit_module", "-k", "rzmon_mod")
	have = auditRuleCount()
	note := filepath.Join(edrDir(), "edr.failnote")
	if have > 0 {
		actLog("edr", 1, "EDR-regels in auditd gezet", fmt.Sprintf("%d regels (programma's, gebruikers, persistentie, kernelmodules)", have))
	} else if st, err := os.Stat(note); err != nil || time.Since(st.ModTime()) > time.Hour {
		actLog("edr", 0, "EDR-regels zetten mislukt", "auditctl nam geen regels aan") // hooguit eens per uur
		_ = os.WriteFile(note, nil, 0o600)
	}
	return have > 0
}

// ---------- het onderdeel "edr" van het rapport ----------

func edrWanted(tests string, conf map[string]string) bool {
	return conf["EDR"] != "0" && hasLinePrefix(tests, "0\tedr\t1")
}

// edrInfo: de stand van de sensoren op deze server (zonder iets te veranderen als apply false is: voor de proef)
func edrInfo(conf map[string]string, apply bool) map[string]any {
	audit := "off"
	switch {
	case !isRoot():
		audit = "noroot"
	case !haveCmd("auditctl"):
		audit = "missing"
	case func() bool { _, rc := run(5*time.Second, "pidof", "auditd"); return rc != 0 }():
		audit = "stopped"
	case apply && edrRules(true), !apply && auditRuleCount() >= 3:
		audit = "ok"
	case apply:
		audit = "locked"
	default:
		audit = "locked" // in de proef zet de Go-agent zelf geen regels: hij kijkt alleen of ze er staan
	}
	file := "missing"
	if haveCmd("inotifywait") {
		file = "ok"
	}
	ctr := "none"
	if conf["PODMAN"] != "0" && haveCmd("podman") {
		ctr = "podman"
	} else if haveCmd("docker") {
		ctr = "docker"
	}
	fam, epel := "other", 0
	if haveCmd("rpm") && (haveCmd("dnf") || haveCmd("yum")) {
		fam = "rhel"
		if _, rc := run(10*time.Second, "rpm", "-q", "epel-release"); rc == 0 {
			epel = 1
		}
	} else if haveCmd("apt-get") {
		fam = "deb"
	}
	sys := 1
	if st, err := os.Stat("/run/systemd/system"); err != nil || !st.IsDir() {
		sys = 0
	}
	yara := "missing"
	if haveCmd("yara") {
		yara = "ok"
	}
	return map[string]any{"audit": audit, "file": file, "ctr": ctr, "fam": fam, "epel": epel, "systemd": sys, "yara": yara}
}

type edrRun struct {
	json   map[string]any
	commit func()
}

// eveNew: nieuwe alerts van Suricata sinds de vorige keer (inode + positie; de eerste keer alleen vastleggen)
func eveNew(conf map[string]string) (state, alerts string, commit func()) {
	commit = func() {}
	if conf["SURICATA"] == "0" {
		return "off", "", commit
	}
	eve := conf["SURICATA_EVE"]
	if eve == "" {
		eve = "/var/log/suricata/eve.json"
	}
	if !readable(eve) {
		if haveCmd("suricata") {
			return "noeve", "", commit
		}
		return "off", "", commit
	}
	st, err := os.Stat(eve)
	if err != nil {
		return "off", "", commit
	}
	ino := st.Sys().(*syscall.Stat_t).Ino
	size := st.Size()
	posFile := filepath.Join(edrDir(), "eve.pos")
	if f := strings.Fields(readText(posFile)); len(f) >= 1 && f[0] != "" {
		pos := int64(0)
		if len(f) >= 2 {
			pos, _ = strconv.ParseInt(f[1], 10, 64)
		}
		if f[0] != strconv.FormatUint(ino, 10) || pos > size {
			pos = 0
		}
		if fh, err := os.Open(eve); err == nil {
			if _, err := fh.Seek(pos, io.SeekStart); err == nil {
				b, _ := io.ReadAll(io.LimitReader(fh, size-pos))
				l := strings.Split(strings.TrimSuffix(string(b), "\n"), "\n")
				if len(l) > 50000 {
					l = l[len(l)-50000:]
				}
				alerts = eveAlerts(strings.Join(l, "\n"))
			}
			fh.Close()
		}
	}
	commit = func() { _ = os.WriteFile(posFile, []byte(fmt.Sprintf("%d %d\n", ino, size)), 0o600) }
	return "ok", alerts, commit
}

// collectEdrLinux: het onderdeel "edr": de wachtrij van de sensoren (hooguit 500 per keer, bij een lange wachtrij 2000)
// en de stand. Uit (in RzSense of met EDR=0): regels weg en de wachtrij leeg.
func collectEdrLinux() edrRun {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	conf, _ := readShellConf(bashConfPath())
	dir := edrDir()
	spool, sending := filepath.Join(dir, "edr.spool"), filepath.Join(dir, "edr.sending")
	if !edrWanted(tests, conf) {
		if isRoot() {
			edrRules(false)
			_ = os.Remove(spool)
			_ = os.Remove(sending)
		}
		return edrRun{commit: func() {}}
	}
	info := edrInfo(conf, true)
	watch := "off"
	if f := strings.Fields(firstLine(filepath.Join(dir, "watch.state"))); len(f) >= 5 && f[0] == "active" && f[4] != "-" {
		watch = "on"
	}
	info["watch"] = watch
	drop := 0
	if d := strings.TrimSpace(readText(filepath.Join(dir, "edr.drop"))); digitsRe.MatchString(d) {
		drop = atoiDef(d, 0)
	}
	info["ysha"] = ""
	if h, ok := sha256File(filepath.Join(dir, "rzmon.yar")); ok {
		info["ysha"] = h
	}
	surState, alerts, eveCommit := eveNew(conf)
	info["sur"] = surState
	// wat er nog lag (niet aangekomen) plus de nieuwe gebeurtenissen
	if st, err := os.Stat(spool); err == nil && st.Size() > 0 {
		tmp := spool + ".tmp"
		if os.Rename(spool, tmp) == nil {
			if fh, err := os.OpenFile(sending, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
				_, _ = fh.WriteString(readText(tmp))
				fh.Close()
			}
			_ = os.Remove(tmp)
		}
	}
	queue := lines(readText(sending))
	if len(queue) > 20000 { // RzSense een dag weg: de nieuwste 20.000 bewaren, de rest tellen als weggegooid
		drop += len(queue) - 20000
		_ = os.WriteFile(filepath.Join(dir, "edr.drop"), []byte(fmt.Sprintf("%d\n", drop)), 0o600)
		queue = queue[len(queue)-20000:]
		_ = os.WriteFile(sending, []byte(strings.Join(queue, "\n")+"\n"), 0o600)
	}
	info["drop"] = drop
	take := 500
	if len(queue) > 5000 {
		take = 2000 // lange wachtrij: sneller inhalen
	}
	if take > len(queue) {
		take = len(queue)
	}
	ev := []json.RawMessage{}
	for _, l := range queue[:take] {
		if strings.HasPrefix(l, "{") && strings.HasSuffix(l, "}") && json.Valid([]byte(l)) {
			ev = append(ev, json.RawMessage(l))
		}
	}
	info["queue"] = len(queue)
	out := map[string]any{"ev": ev, "info": info}
	if alerts != "" {
		out["sur"] = json.RawMessage("[" + alerts + "]")
	}
	return edrRun{json: out, commit: func() {
		rest := lines(readText(sending))
		if len(rest) > take {
			_ = os.WriteFile(sending, []byte(strings.Join(rest[take:], "\n")+"\n"), 0o600)
		} else {
			_ = os.Remove(sending)
		}
		_ = os.Remove(filepath.Join(dir, "edr.drop"))
		eveCommit()
	}}
}

// edrYaraUpdate: nieuwe YARA-regels van RzSense (alleen versleuteld ontvangen): eerst controleren of yara ze kan lezen
func edrYaraUpdate(b64 string) {
	if len(b64) < 20 {
		return
	}
	raw, err := base64.StdEncoding.DecodeString(b64)
	if err != nil {
		return
	}
	dir := edrDir()
	tmp := filepath.Join(dir, "rzmon.yar.new")
	if os.WriteFile(tmp, raw, 0o600) != nil {
		return
	}
	if haveCmd("yara") {
		if _, rc := run(20*time.Second, "yara", "-w", tmp, "/dev/null"); rc == 0 {
			_ = os.Rename(tmp, filepath.Join(dir, "rzmon.yar"))
			return
		}
	}
	_ = os.Remove(tmp)
}

// edrKillNow: processen die RzSense meteen wil laten stoppen (antwoord op het rapport). Alleen met de juiste starttijd,
// nooit iets van RzSense of de agent zelf; de uitkomst gaat mee met het volgende rapport.
func edrKillNow(pairs [][]int64, conf map[string]string) {
	if conf["IDS_BLOCK"] == "0" || !isRoot() {
		return
	}
	dir := idsDir()
	_ = os.MkdirAll(dir, 0o700)
	for _, p := range pairs {
		if len(p) != 2 || p[0] <= 1 {
			continue
		}
		pid, start := strconv.FormatInt(p[0], 10), strconv.FormatInt(p[1], 10)
		mark := filepath.Join(dir, "killed."+pid+"."+start)
		if _, err := os.Stat(mark); err == nil {
			continue
		}
		res := "weg"
		if procStart(pid) == start {
			switch {
			case ownUnitRe.MatchString(readText("/proc/" + pid + "/cgroup")), int(p[0]) == os.Getpid():
				res = "eigen"
			case syscall.Kill(int(p[0]), syscall.SIGKILL) == nil:
				res = "ok"
			default:
				res = "fout"
			}
		}
		_ = os.WriteFile(mark, nil, 0o600)
		if fh, err := os.OpenFile(filepath.Join(dir, "killed.pending"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
			fmt.Fprintf(fh, `[%s,%s,"%s"],`, pid, start, res)
			fh.Close()
		}
	}
}

// ownPidsNote: de laatste rondes van de agent onthouden, zodat de sensor hun kinderen herkent (ook als de ronde al klaar is)
func ownPidsNote() {
	file := filepath.Join(edrDir(), "own.pids")
	l := lines(readText(file))
	if len(l) > 7 {
		l = l[len(l)-7:]
	}
	l = append(l, strconv.Itoa(os.Getpid()))
	_ = os.WriteFile(file, []byte(strings.Join(l, "\n")+"\n"), 0o600)
}

// edrParseCLI: rzsense edr-parse audit|eve < bestand (voor de tests, zoals rzmon-agent.sh --edr-parse)
func edrParseCLI(kind string) int {
	switch kind {
	case "audit":
		w := bufio.NewWriter(os.Stdout)
		defer w.Flush()
		p := newAuditParser(os.Getenv("EDR_ROOT"), os.Getenv("EDR_USER"), os.Getenv("EDR_MARK"), func(s string) { fmt.Fprintln(w, s) })
		if v := os.Getenv("RZSENSE_TEST_PROC"); v != "" {
			p.proc = v
		}
		sc := bufio.NewScanner(os.Stdin)
		sc.Buffer(make([]byte, 1024*1024), 4*1024*1024)
		for sc.Scan() {
			p.feed(sc.Text())
		}
		p.flush()
	case "eve":
		b, _ := io.ReadAll(os.Stdin)
		fmt.Println(eveAlerts(string(b)))
	case "swag":
		b, _ := io.ReadAll(os.Stdin)
		fmt.Println(swagReq(string(b)))
	case "npm":
		b, _ := io.ReadAll(os.Stdin)
		fmt.Print(npmNorm(string(b)))
	case "weblog": // het onderdeel "weblog" zoals het in het rapport komt; met "sent" daarna alsof het rapport is aangenomen
		b, _ := json.Marshal(collectWeblogLinux())
		fmt.Println(string(b))
		if len(os.Args) >= 4 && os.Args[3] == "sent" {
			weblogSent()
		}
	case "file":
		if len(os.Args) >= 4 {
			fmt.Println(edrFile(os.Args[3]))
		}
	case "report": // het onderdeel "edr" zoals het in het rapport komt; met "commit" daarna alsof het rapport is aangenomen
		r := collectEdrLinux()
		b, _ := json.Marshal(r.json)
		fmt.Println(string(b))
		if len(os.Args) >= 4 && os.Args[3] == "commit" && r.json != nil {
			r.commit()
		}
	case "net": // één ronde van de netwerksensor ("first" = alleen vastleggen)
		c, err := loadBashConfig()
		if err != nil {
			return 2
		}
		self := edrSelfFrom(readText(filepath.Join(bashCachePath(), "tests.cache")), c)
		for _, ev := range edrNetOnce(self, len(os.Args) >= 4 && os.Args[3] == "first") {
			fmt.Println(ev)
		}
	case "ctr": // regels van "podman events" of "docker events" (op stdin)
		if len(os.Args) < 4 {
			return 2
		}
		sc := bufio.NewScanner(os.Stdin)
		for sc.Scan() {
			name, img, act, cmd := edrCtrEvent(os.Args[3], sc.Text())
			fmt.Printf("%s|%s|%s|%s\n", name, img, act, cmd)
		}
	case "info": // de stand van de sensoren zoals in de proef (zonder iets te veranderen)
		conf, _ := readShellConf(bashConfPath())
		b, _ := json.Marshal(edrInfo(conf, false))
		fmt.Println(string(b))
	default:
		return 2
	}
	return 0
}
