//go:build linux

package main

// IDS, tweede stap (0.28.0): de rest van het waarnemen, nog steeds als proef. De Go-agent leest wat de bash-agent voor het
// IDS leest (agent/src/60-ids-en-edr.sh, onderdelen 4, 5, 6b, 6e, 7 en 8) en RzSense legt het naast de bash-agent:
//   - webshells: een shell of netwerkprogramma dat door een webserver gestart is of als webgebruiker draait;
//   - verbindingen: een shell met een verbinding naar buiten, een verdachte poort, en waarheen de server verbindt;
//   - hardening: de instellingen die het verschil maken (alleen uitlezen; RzSense beoordeelt);
//   - SUID-bestanden (elk uur) en de pakketcontrole (dagelijks), op de achtergrond met lage prioriteit;
//   - bestanden bewaken: hash, grootte, rechten en eigenaar van de paden uit RzSense.
// Ook dit grijpt nergens in en verandert niets op de server.

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

func idsDir() string { return filepath.Join(dataDir(), "ids") }

// ---------- 6b. Webshells ----------

var (
	webToolRe = regexp.MustCompile(`^(sh|bash|dash|zsh|ksh|nc|ncat|netcat|socat|curl|wget|python[0-9.]*|perl|ruby|telnet|busybox)$`)
	webMailRe = regexp.MustCompile(`(sendmail|msmtp|ssmtp|postdrop)`)
	webSrvRe  = regexp.MustCompile(`^(nginx|apache2|httpd|php-fpm.*|php-cgi.*|lighttpd|caddy|uwsgi|gunicorn)$`)
	webUserRe = regexp.MustCompile(`^(www-data|apache|nginx|http|wwwrun|lighttpd)$`)
	psHeadRe  = regexp.MustCompile(`^\s*[0-9]+\s+[0-9]+\s+\S+\s+\S+\s*`)
)

type webHit struct {
	pid                  int
	user, tool, web, cmd string
}

// idsWebFromPs: uit "ps -eo pid=,ppid=,user:32=,comm=,args=" de processen die op een webshell lijken (hooguit 20)
func idsWebFromPs(ps string) []webHit {
	type proc struct {
		ppid           int
		user, comm, ar string
	}
	procs := map[int]proc{}
	order := []int{}
	for _, l := range strings.Split(ps, "\n") {
		f := strings.Fields(l)
		if len(f) < 4 {
			continue
		}
		pid, err1 := strconv.Atoi(f[0])
		ppid, err2 := strconv.Atoi(f[1])
		if err1 != nil || err2 != nil {
			continue
		}
		procs[pid] = proc{ppid, f[2], f[3], psHeadRe.ReplaceAllString(l, "")}
		order = append(order, pid)
	}
	out := []webHit{}
	for _, pid := range order {
		p := procs[pid]
		if !webToolRe.MatchString(p.comm) || webMailRe.MatchString(p.ar) {
			continue
		}
		web := ""
		q := p.ppid
		for d := 0; d < 8 && q > 1 && web == ""; d++ {
			if webSrvRe.MatchString(procs[q].comm) {
				web = procs[q].comm
			}
			q = procs[q].ppid
		}
		if web == "" && webUserRe.MatchString(p.user) {
			web = "user:" + p.user
		}
		if web == "" {
			continue
		}
		out = append(out, webHit{pid, p.user, p.comm, web, p.ar})
		if len(out) >= 20 {
			break
		}
	}
	return out
}

func idsWebshells() []map[string]any {
	out := []map[string]any{}
	if !haveCmd("ps") {
		return out
	}
	ps, _ := stdoutOf(15*time.Second, "ps", "-eo", "pid=,ppid=,user:32=,comm=,args=")
	for _, h := range idsWebFromPs(ps) {
		stat := strings.Fields(readText(fmt.Sprintf("/proc/%d/stat", h.pid)))
		if len(stat) == 0 {
			continue // het proces is alweer weg
		}
		start := 0
		if len(stat) >= 22 {
			start = atoiDef(stat[21], 0)
		}
		out = append(out, map[string]any{"pid": h.pid, "start": start, "user": cleanText(h.user), "tool": cleanText(h.tool), "web": cleanText(h.web), "cmd": jsonText([]byte(h.cmd), 300)})
	}
	return out
}

// ---------- 7. Verbindingen ----------

var ssProcRe = regexp.MustCompile(`\("([^"]+)",pid=([0-9]+)`)

// idsListenPorts: de poorten waarop deze server luistert (uit "ss -Htlnp", kolom 4)
func idsListenPorts(ss string) map[string]bool {
	out := map[string]bool{}
	for _, l := range strings.Split(ss, "\n") {
		if f := strings.Fields(l); len(f) >= 4 {
			out[f[3][strings.LastIndex(f[3], ":")+1:]] = true
		}
	}
	return out
}

func idsPrivatePeer(ip string) bool {
	for _, p := range []string{"10.", "192.168.", "169.254.", "fe80:", "fc", "fd", "::ffff:10.", "::ffff:192.168.", "::ffff:172."} {
		if strings.HasPrefix(ip, p) {
			return true
		}
	}
	if strings.HasPrefix(ip, "172.") { // 172.16 t/m 172.31
		rest := strings.TrimPrefix(ip, "172.")
		if i := strings.Index(rest, "."); i == 2 {
			if n, err := strconv.Atoi(rest[:2]); err == nil && n >= 16 && n <= 31 {
				return true
			}
		}
	}
	return false
}

// idsConns: uit "ss -tnpH state established" de verdachte verbindingen en waarheen de server naar buiten verbindt
func idsConns(established string, listen map[string]bool) (conns []map[string]any, outb [][]any) {
	conns, outb = []map[string]any{}, [][]any{}
	seen := map[string]bool{}
	shells := map[string]bool{"bash": true, "sh": true, "dash": true, "zsh": true, "ksh": true, "nc": true, "ncat": true, "netcat": true, "socat": true, "telnet": true}
	badPorts := map[string]bool{"1337": true, "4444": true, "4445": true, "5555": true, "6666": true, "6667": true, "6697": true, "12345": true, "31337": true}
	n := 0
	for _, l := range strings.Split(established, "\n") {
		m := ssProcRe.FindAllStringSubmatch(l, -1)
		f := strings.Fields(l)
		if len(m) == 0 || len(f) < 4 {
			continue
		}
		if n++; n > 200 {
			break
		}
		local, peer := f[2], f[3]
		pid := atoiDef(m[0][2], 0)
		proc := m[0][1]
		peerIP, peerPort := peer, peer
		if i := strings.LastIndex(peer, ":"); i >= 0 {
			peerIP, peerPort = peer[:i], peer[i+1:]
		}
		peerIP = strings.TrimSuffix(strings.TrimPrefix(peerIP, "["), "]")
		localPort := local[strings.LastIndex(local, ":")+1:]
		if strings.HasPrefix(peerIP, "127.") || peerIP == "::1" || strings.HasPrefix(peerIP, "::ffff:127.") {
			continue
		}
		kind := ""
		for _, x := range m { // een shell die de verbinding vasthoudt, ook als een ander proces hem deelt
			if shells[x[1]] {
				kind, proc = "shell", x[1]
			}
		}
		if kind == "" && !listen[localPort] && badPorts[peerPort] {
			kind = "port"
		}
		if !listen[localPort] && len(outb) < 100 && !idsPrivatePeer(peerIP) {
			key := proc + "|" + peerIP + "|" + peerPort
			if !seen[key] {
				seen[key] = true
				outb = append(outb, []any{cleanText(proc), cleanText(peerIP), atoiDef(peerPort, 0)})
			}
		}
		if kind != "" {
			conns = append(conns, map[string]any{"kind": kind, "proc": cleanText(proc), "pid": pid, "local": cleanText(local), "peer": cleanText(peer)})
		}
	}
	return conns, outb
}

// ---------- 6e. Hardening ----------

var (
	hardKeyRe    = regexp.MustCompile(`^[a-z_0-9]+$`)
	hardDbPortRe = regexp.MustCompile(`^(3306|5432|6379|27017|9200|11211|5984|8086)$`)
	hardAnyRe    = regexp.MustCompile(`^(0\.0\.0\.0|\*|\[::\]|::)$`)
	hardLegacyRe = regexp.MustCompile(`^(21|23|69|512|513|514)$`)
	hardNftRe    = regexp.MustCompile(`hook input .*policy drop`)
	hardIptRe    = regexp.MustCompile(`(?m)^-P INPUT DROP|-j (DROP|REJECT)`)
	hardNopwRe   = regexp.MustCompile(`^[^#]*NOPASSWD`)
	hardFailRe   = regexp.MustCompile(`(?m)^[^#\n]*(pam_faillock|pam_tally2)`)
	hardMinlenRe = regexp.MustCompile(`^[ \t]*minlen[ \t]*$`)
)

func sortedUnique(l []string) []string {
	sort.Strings(l)
	out := l[:0]
	for i, v := range l {
		if i == 0 || v != l[i-1] {
			out = append(out, v)
		}
	}
	return out
}

// sortedNumeric: zoals sort -un
func sortedNumeric(l []string) []string {
	sort.Slice(l, func(i, j int) bool { return atoiDef(l[i], 0) < atoiDef(l[j], 0) })
	out := l[:0]
	for i, v := range l {
		if i == 0 || v != l[i-1] {
			out = append(out, v)
		}
	}
	return out
}

func statPermOwner(p string) string {
	st, err := os.Stat(p)
	if err != nil {
		return ""
	}
	owner := ""
	if sys, ok := st.Sys().(*syscall.Stat_t); ok {
		owner = idNames.user(sys.Uid)
	}
	return fmt.Sprintf("%o %s", modeBits(st.Mode()), owner)
}

// modeBits: de rechten zoals stat %a en find %m ze geven (met setuid, setgid en sticky)
func modeBits(m os.FileMode) uint32 {
	b := uint32(m.Perm())
	if m&os.ModeSetuid != 0 {
		b |= 0o4000
	}
	if m&os.ModeSetgid != 0 {
		b |= 0o2000
	}
	if m&os.ModeSticky != 0 {
		b |= 0o1000
	}
	return b
}

func firstAwk(text, key string) string {
	for _, l := range strings.Split(text, "\n") {
		if f := strings.Fields(l); len(f) >= 1 && f[0] == key {
			if len(f) >= 2 {
				return f[1]
			}
			return ""
		}
	}
	return ""
}

func svcActive(u string) bool {
	_, rc := run(5*time.Second, "systemctl", "is-active", "-q", u)
	return rc == 0
}

func mountNoexec(p string) bool {
	out, _ := stdoutOf(5*time.Second, "findmnt", "-no", "OPTIONS", p)
	return strings.Contains(out, "noexec")
}

// idsHardening: de instellingen, als naam → waarde (alles tekst, zoals de bash-agent ze stuurt)
func idsHardening() map[string]string {
	h := map[string]string{}
	set := func(k, v string) {
		if hardKeyRe.MatchString(k) {
			if i := strings.Index(v, "\n"); i >= 0 {
				v = v[:i]
			}
			h[k] = strings.NewReplacer(`\`, "", `"`, "").Replace(v)
		}
	}
	if haveCmd("sshd") {
		if t, rc := stdoutOf(10*time.Second, "sshd", "-T"); rc == 0 && strings.TrimSpace(t) != "" {
			set("ssh_pw", firstAwk(t, "passwordauthentication"))
			set("ssh_root", firstAwk(t, "permitrootlogin"))
			set("ssh_empty", firstAwk(t, "permitemptypasswords"))
			for k, name := range map[string]string{"maxauthtries": "ssh_maxauth", "x11forwarding": "ssh_x11", "clientaliveinterval": "ssh_idle", "logingracetime": "ssh_grace"} {
				for _, l := range strings.Split(t, "\n") { // de laatste regel telt, zoals bij het inlezen door RzSense
					if f := strings.Fields(l); len(f) >= 1 && f[0] == k {
						v := ""
						if len(f) >= 2 {
							v = f[1]
						}
						set(name, v)
					}
				}
			}
		}
	}
	fw := "none"
	if svcActive("firewalld") {
		fw = "firewalld"
	} else if out, _ := stdoutOf(10*time.Second, "ufw", "status"); haveCmd("ufw") && strings.Contains(out, "Status: active") {
		fw = "ufw"
	} else if out, _ := stdoutOf(10*time.Second, "nft", "list", "ruleset"); hardNftRe.MatchString(out) {
		fw = "nftables"
	} else if out, _ := stdoutOf(10*time.Second, "iptables", "-S", "INPUT"); hardIptRe.MatchString(out) {
		fw = "iptables"
	}
	set("firewall", fw)
	au := "none"
	for _, u := range []string{"dnf-automatic.timer", "dnf-automatic-install.timer", "dnf5-automatic.timer", "unattended-upgrades.service"} {
		if _, rc := run(5*time.Second, "systemctl", "is-enabled", "-q", u); rc == 0 {
			au = u
			break
		}
	}
	if au == "unattended-upgrades.service" && !regexp.MustCompile(`Unattended-Upgrade "1"`).MatchString(readText("/etc/apt/apt.conf.d/20auto-upgrades")) {
		au = "none"
	}
	set("autoupdate", au)
	ssT, _ := stdoutOf(10*time.Second, "ss", "-Htlnp")
	ssTU, _ := stdoutOf(10*time.Second, "ss", "-Htulnp")
	db := []string{}
	docker := "ok"
	for _, l := range strings.Split(ssT, "\n") {
		f := strings.Fields(l)
		if len(f) < 4 {
			continue
		}
		i := strings.LastIndex(f[3], ":")
		port, host := f[3][i+1:], f[3]
		if i >= 0 {
			host = f[3][:i]
		}
		if hardDbPortRe.MatchString(port) && hardAnyRe.MatchString(host) {
			db = append(db, port)
		}
	}
	set("open_db", strings.Join(sortedNumeric(db), ","))
	if st, err := os.Stat("/var/run/docker.sock"); err == nil && st.Mode()&os.ModeSocket != 0 && st.Mode().Perm()&0o002 != 0 {
		docker = "world"
	}
	for _, l := range strings.Split(ssT, "\n") {
		if f := strings.Fields(l); len(f) >= 4 && strings.HasSuffix(f[3], ":2375") {
			docker = "tcp"
		}
	}
	set("docker_sock", docker)
	set("tmp_noexec", map[bool]string{true: "yes", false: "no"}[mountNoexec("/tmp")])
	empty := []string{}
	for _, l := range strings.Split(readText("/etc/shadow"), "\n") {
		if f := strings.Split(l, ":"); len(f) >= 2 && f[1] == "" {
			empty = append(empty, f[0])
		}
	}
	set("empty_pw", strings.Join(empty, ","))
	nopw := []string{}
	sudoers, _ := filepath.Glob("/etc/sudoers.d/*")
	for _, f := range append([]string{"/etc/sudoers"}, sudoers...) {
		for _, l := range strings.Split(readText(f), "\n") {
			if hardNopwRe.MatchString(l) {
				if w := strings.Fields(l); len(w) > 0 {
					nopw = append(nopw, w[0])
				} else {
					nopw = append(nopw, "")
				}
			}
		}
	}
	set("sudo_nopw", strings.Join(sortedUnique(nopw), ","))
	if haveCmd("getenforce") {
		out, _ := stdoutOf(5*time.Second, "getenforce")
		set("selinux", strings.TrimSpace(out))
	}
	set("shadow_perm", statPermOwner("/etc/shadow"))
	set("passwd_perm", statPermOwner("/etc/passwd"))
	set("crontab_perm", statPermOwner("/etc/crontab"))
	defs := readText("/etc/login.defs")
	set("pass_max_days", firstAwk(defs, "PASS_MAX_DAYS"))
	set("umask", firstAwk(defs, "UMASK"))
	uid0 := []string{}
	for _, l := range strings.Split(readText("/etc/passwd"), "\n") {
		if f := strings.Split(l, ":"); len(f) >= 3 && f[0] != "root" && f[2] != "" && awkNum(f[2]) == 0 && regexp.MustCompile(`^[+-]?[0-9. \t]*$`).MatchString(f[2]) {
			uid0 = append(uid0, f[0])
		}
	}
	set("uid0", strings.Join(uid0, ","))
	for _, k := range []string{"kernel/randomize_va_space", "net/ipv4/conf/all/accept_redirects", "net/ipv4/tcp_syncookies", "net/ipv4/conf/all/rp_filter", "kernel/dmesg_restrict", "kernel/yama/ptrace_scope", "fs/suid_dumpable"} {
		if readable("/proc/sys/" + k) {
			set("sys_"+filepath.Base(k), strings.TrimRight(readText("/proc/sys/"+k), "\n"))
		}
	}
	if svcActive("auditd") {
		set("auditd", "yes")
	} else if haveCmd("systemctl") {
		set("auditd", "no")
	}
	ts := "none"
	for _, u := range []string{"chronyd", "chrony", "systemd-timesyncd", "ntpd", "ntp", "ntpsec"} {
		if svcActive(u) {
			ts = u
			break
		}
	}
	set("time_sync", ts)
	ww := []string{}
	deadline := time.Now().Add(20 * time.Second)
	for _, root := range []string{"/etc", "/usr/bin", "/usr/sbin", "/usr/local/bin", "/usr/local/sbin"} {
		walkOneFs(root, nil, func(p string, st os.FileInfo) bool {
			if st.Mode().IsRegular() && st.Mode().Perm()&0o002 != 0 {
				ww = append(ww, p)
			}
			return len(ww) < 5 && time.Now().Before(deadline)
		})
		if len(ww) >= 5 || !time.Now().Before(deadline) {
			break
		}
	}
	set("ww_files", strings.Join(ww, ","))
	legacy := []string{}
	for _, l := range strings.Split(ssTU, "\n") {
		if f := strings.Fields(l); len(f) >= 5 {
			if p := f[4][strings.LastIndex(f[4], ":")+1:]; hardLegacyRe.MatchString(p) {
				legacy = append(legacy, p)
			}
		}
	}
	set("legacy_svc", strings.Join(sortedNumeric(legacy), ","))
	fail := "no"
	pam, _ := filepath.Glob("/etc/pam.d/*")
	for _, f := range pam {
		if hardFailRe.MatchString(readText(f)) {
			fail = "yes"
			break
		}
	}
	set("faillock", fail)
	minlen := ""
	pwq, _ := filepath.Glob("/etc/security/pwquality.conf.d/*.conf")
	for _, f := range append([]string{"/etc/security/pwquality.conf"}, pwq...) {
		for _, l := range strings.Split(readText(f), "\n") {
			if kv := strings.Split(l, "="); len(kv) >= 2 && hardMinlenRe.MatchString(kv[0]) {
				minlen = strings.NewReplacer(" ", "", "\t", "").Replace(kv[1])
			}
		}
	}
	set("pw_minlen", minlen)
	for _, g := range []string{"/boot/grub2/grub.cfg", "/boot/grub/grub.cfg"} {
		if st, err := os.Stat(g); err == nil && st.Mode().IsRegular() {
			set("grub_perm", fmt.Sprintf("%o", modeBits(st.Mode())))
			break
		}
	}
	if mountNoexec("/dev/shm") {
		set("shm_noexec", "yes")
	} else if st, err := os.Stat("/dev/shm"); err == nil && st.IsDir() {
		set("shm_noexec", "no")
	}
	return h
}

// idsHardCached: eens per uur opnieuw meten; tussendoor de bewaarde uitkomst
func idsHardCached() map[string]string {
	file := filepath.Join(idsDir(), "hard.json")
	if st, err := os.Stat(file); err == nil && time.Since(st.ModTime()) < time.Hour {
		var m map[string]string
		if b, err := os.ReadFile(file); err == nil && json.Unmarshal(b, &m) == nil && len(m) > 0 {
			return m
		}
	}
	m := idsHardening()
	if b, err := json.Marshal(m); err == nil && os.MkdirAll(idsDir(), 0o700) == nil {
		_ = os.WriteFile(file, b, 0o600)
	}
	return m
}

// ---------- bestanden aflopen ----------

// walkOneFs: een map aflopen zonder andere bestandssystemen in te gaan (find -xdev) en zonder koppelingen te volgen.
// prune zegt welke mappen worden overgeslagen; visit geeft false terug om te stoppen.
func walkOneFs(root string, prune func(string) bool, visit func(string, os.FileInfo) bool) {
	rst, err := os.Lstat(root)
	if err != nil {
		return
	}
	dev := uint64(0)
	if sys, ok := rst.Sys().(*syscall.Stat_t); ok {
		dev = uint64(sys.Dev)
	}
	stop := false
	_ = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if stop {
			return filepath.SkipAll
		}
		if err != nil {
			if d != nil && d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		st, err := d.Info()
		if err != nil {
			return nil
		}
		if d.IsDir() {
			if prune != nil && prune(p) {
				return filepath.SkipDir
			}
			if sys, ok := st.Sys().(*syscall.Stat_t); ok && uint64(sys.Dev) != dev {
				return filepath.SkipDir
			}
			return nil
		}
		if !visit(p, st) {
			stop = true
			return filepath.SkipAll
		}
		return nil
	})
}

// ---------- 4. SUID en 5. pakketcontrole (op de achtergrond) ----------

var pkgPathRe = regexp.MustCompile(`^/(usr/)?(s?bin|lib|lib64)/`)

// idsSuidScan: de SUID-bestanden op het bestandssysteem van / (hooguit 2000), zonder container-images
func idsSuidScan(root string) []string {
	skip := map[string]bool{}
	for _, p := range []string{"/proc", "/sys", "/dev", "/run", "/var/lib/docker", "/var/lib/containers", "/var/lib/containerd"} {
		skip[root+p] = true
	}
	homes, _ := filepath.Glob(root + "/home/*/.local/share/containers")
	for _, p := range homes {
		skip[p] = true
	}
	out := []string{}
	start := root
	if start == "" {
		start = "/"
	}
	walkOneFs(start, func(p string) bool { return skip[p] }, func(p string, st os.FileInfo) bool {
		if st.Mode().IsRegular() && st.Mode()&os.ModeSetuid != 0 {
			out = append(out, strings.TrimPrefix(p, root))
		}
		return len(out) < 2000
	})
	return out
}

// idsPkgDiffFrom: uit de uitvoer van "rpm -Va --noconfig --nomtime" of "debsums -c" de programma's en bibliotheken
// waarvan de inhoud afwijkt van het pakket (hooguit 100)
func idsPkgDiffFrom(out string, rpm bool) []string {
	res := []string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) == 0 {
			continue
		}
		p := l
		if rpm {
			if len(f[0]) < 3 || f[0][2] != '5' {
				continue
			}
			p = f[len(f)-1]
		}
		if pkgPathRe.MatchString(p) {
			res = append(res, p)
			if len(res) >= 100 {
				break
			}
		}
	}
	return res
}

func idsPkgTool() string {
	if haveCmd("rpm") {
		for _, d := range []string{"/var/lib/rpm", "/usr/lib/sysimage/rpm"} {
			if st, err := os.Stat(d); err == nil && st.IsDir() {
				return "rpm"
			}
		}
	}
	if haveCmd("debsums") {
		return "debsums"
	}
	return ""
}

// idsScanRun: rzsense ids-scan suid|pkgdiff: de trage controle zelf, met lage prioriteit; de uitkomst komt in de eigen map
func idsScanRun(name string) int {
	dir := idsDir()
	defer os.Remove(filepath.Join(dir, name+".lock"))
	_ = syscall.Setpriority(syscall.PRIO_PROCESS, 0, 19)
	var list []string
	switch name {
	case "rehash":
		return fimRehash()
	case "suid":
		list = idsSuidScan("")
	case "pkgdiff":
		switch idsPkgTool() {
		case "rpm":
			out, _ := stdoutOf(2*time.Hour, "nice", "-n", "19", "rpm", "-Va", "--noconfig", "--nomtime")
			list = idsPkgDiffFrom(out, true)
		case "debsums":
			out, _ := stdoutOf(2*time.Hour, "nice", "-n", "19", "debsums", "-c")
			list = idsPkgDiffFrom(out, false)
		default:
			return 0
		}
	default:
		return 2
	}
	if list == nil {
		list = []string{}
	}
	b, _ := json.Marshal(list)
	tmp := filepath.Join(dir, name+".tmp")
	if os.WriteFile(tmp, b, 0o600) != nil || os.Rename(tmp, filepath.Join(dir, name)) != nil {
		return 1
	}
	return 0
}

// idsBackground: de trage controle starten als de vorige uitkomst te oud is (en er niet al een loopt, ook niet die van de
// bash-agent: twee tegelijk zou de server onnodig belasten). Geeft de laatste uitkomst terug, of nil als die er nog niet is.
func idsBackground(name string, every time.Duration) []string {
	dir := idsDir()
	file := filepath.Join(dir, name)
	var have []string
	fresh := false
	if st, err := os.Stat(file); err == nil {
		if b, err := os.ReadFile(file); err == nil && json.Unmarshal(b, &have) == nil && have == nil {
			have = []string{}
		}
		fresh = time.Since(st.ModTime()) < every
	}
	if fresh || os.MkdirAll(dir, 0o700) != nil {
		return have
	}
	for _, lock := range []string{filepath.Join(dir, name+".lock"), filepath.Join(bashCacheRoot(), "ids", name+".lock")} {
		if st, err := os.Stat(lock); err == nil && time.Since(st.ModTime()) < 2*time.Hour {
			return have
		}
	}
	self, err := os.Executable()
	if err != nil || os.WriteFile(filepath.Join(dir, name+".lock"), nil, 0o600) != nil {
		return have
	}
	started := false
	if underSystemd() {
		_, code := run(15*time.Second, "systemd-run", "--quiet", "--collect", fmt.Sprintf("--unit=rzsense-ids-%s-%d", name, time.Now().Unix()),
			"--property=Nice=19", "--property=IOSchedulingClass=idle", "--setenv=RZSENSE_DATA="+dataDir(), self, "ids-scan", name)
		started = code == 0
	} else {
		cmd := exec.Command(self, "ids-scan", name)
		cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
		if cmd.Start() == nil {
			started = true
			go func() { _ = cmd.Wait() }()
		}
	}
	if !started {
		os.Remove(filepath.Join(dir, name+".lock"))
	}
	return have
}

// bashCacheRoot: de map van de bash-agent zelf (ook als de Go-agent het werk doet)
func bashCacheRoot() string {
	if v := os.Getenv("RZSENSE_BASH_CACHE"); v != "" {
		return v
	}
	return "/var/cache/rzmon-agent"
}

// ---------- pakketactiviteit ----------

var pkgBusyRe = regexp.MustCompile(`^(dnf|dnf5|dnf-3|yum|rpm|microdnf|dnf-automatic|apt|apt-get|aptitude|dpkg|unattended-upgr|zypper|apk)$`)

// idsPkgActivity: wanneer de pakketbeheerder het laatst iets deed, en of hij nu bezig is
func idsPkgActivity() (int64, int) {
	var t int64
	for _, f := range []string{"/var/log/dnf.rpm.log", "/var/log/dnf.log", "/var/log/dnf.librepo.log", "/var/log/dnf5.log", "/var/log/yum.log", "/var/log/dpkg.log", "/var/log/apt/history.log", "/var/log/apt/term.log"} {
		if st, err := os.Stat(f); err == nil && st.Mode().IsRegular() && st.ModTime().Unix() > t {
			t = st.ModTime().Unix()
		}
	}
	ents, _ := os.ReadDir("/proc")
	for _, e := range ents {
		if _, err := strconv.Atoi(e.Name()); err != nil {
			continue
		}
		if pkgBusyRe.MatchString(strings.TrimSpace(readText("/proc/" + e.Name() + "/comm"))) {
			return time.Now().Unix(), 1
		}
	}
	return t, 0
}

// ---------- 8. Bestanden bewaken ----------

// idNames: namen bij uid en gid, één keer ingelezen
type idNameCache struct {
	users, groups map[string]string
}

var idNames = &idNameCache{}

func (c *idNameCache) load() {
	if c.users != nil {
		return
	}
	c.users, c.groups = map[string]string{}, map[string]string{}
	for file, m := range map[string]map[string]string{"/etc/passwd": c.users, "/etc/group": c.groups} {
		for _, l := range strings.Split(readText(file), "\n") {
			if f := strings.Split(l, ":"); len(f) >= 3 {
				if _, ok := m[f[2]]; !ok {
					m[f[2]] = f[0]
				}
			}
		}
	}
}

func (c *idNameCache) user(uid uint32) string {
	c.load()
	id := strconv.FormatUint(uint64(uid), 10)
	if n, ok := c.users[id]; ok {
		return n
	}
	return id
}

func (c *idNameCache) group(gid uint32) string {
	c.load()
	id := strconv.FormatUint(uint64(gid), 10)
	if n, ok := c.groups[id]; ok {
		return n
	}
	return id
}

// fnmatchAll: jokertekens zoals find -path en -name ze lezen: * en ? passen ook op een schuine streep, [..] is een verzameling
func fnmatchAll(pattern, s string) bool {
	p, n := []rune(pattern), []rune(s)
	var match func(pi, si int) bool
	match = func(pi, si int) bool {
		for pi < len(p) {
			switch p[pi] {
			case '*':
				for pi < len(p) && p[pi] == '*' {
					pi++
				}
				if pi == len(p) {
					return true
				}
				for k := si; k <= len(n); k++ {
					if match(pi, k) {
						return true
					}
				}
				return false
			case '?':
				if si >= len(n) {
					return false
				}
				pi, si = pi+1, si+1
			case '[':
				end := pi + 1
				if end < len(p) && (p[end] == '!' || p[end] == '^') {
					end++
				}
				if end < len(p) && p[end] == ']' {
					end++
				}
				for end < len(p) && p[end] != ']' {
					end++
				}
				if end >= len(p) { // geen sluithaak: een gewoon teken
					if si >= len(n) || n[si] != '[' {
						return false
					}
					pi, si = pi+1, si+1
					continue
				}
				if si >= len(n) {
					return false
				}
				set := p[pi+1 : end]
				neg := len(set) > 0 && (set[0] == '!' || set[0] == '^')
				if neg {
					set = set[1:]
				}
				hit := false
				for i := 0; i < len(set); i++ {
					if i+2 < len(set) && set[i+1] == '-' {
						if n[si] >= set[i] && n[si] <= set[i+2] {
							hit = true
						}
						i += 2
					} else if set[i] == n[si] {
						hit = true
					}
				}
				if hit == neg {
					return false
				}
				pi, si = end+1, si+1
			case '\\':
				if pi+1 < len(p) {
					pi++
				}
				fallthrough
			default:
				if si >= len(n) || n[si] != p[pi] {
					return false
				}
				pi, si = pi+1, si+1
			}
		}
		return si == len(n)
	}
	return match(0, 0)
}

type fimRoot struct {
	sev  int
	root string
}

type fimConf struct {
	epoch string
	roots []fimRoot
	excl  []string
}

// fimParse: de instellingen uit de testlijst (regel "0 <tab> fim <tab> base64"): e = epoch, p = ernst en pad, x = uitsluiten
func fimParse(tests string) *fimConf {
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[0] != "0" || f[1] != "fim" || f[2] == "" {
			continue
		}
		raw, err := base64.StdEncoding.DecodeString(f[2])
		if err != nil {
			return nil
		}
		c := &fimConf{}
		for _, r := range strings.Split(string(raw), "\n") {
			p := strings.SplitN(r, "\t", 3)
			switch {
			case p[0] == "e" && len(p) >= 2 && c.epoch == "":
				c.epoch = p[1]
			case p[0] == "x" && len(p) >= 2 && p[1] != "":
				c.excl = append(c.excl, strings.Join(p[1:], "\t"))
			case p[0] == "p" && len(p) == 3:
				c.roots = append(c.roots, fimRoot{atoiDef(p[1], 0), p[2]})
			}
		}
		return c
	}
	return nil
}

func (c *fimConf) excluded(p string) bool {
	for _, x := range c.excl {
		switch {
		case strings.HasSuffix(x, "/"):
			if fnmatchAll("*/"+strings.TrimSuffix(x, "/")+"/*", p) {
				return true
			}
		case strings.Contains(x, "/"):
			if fnmatchAll(x, p) {
				return true
			}
		default:
			if fnmatchAll(x, filepath.Base(p)) {
				return true
			}
		}
	}
	return false
}

type fimEntry struct {
	path        string
	size        int64
	mtime       string // seconden met fractie, zoals find %T@
	mode, owner string
	hash        string
	sev         int
	root        string
}

func (e fimEntry) value() string {
	return e.hash + "|" + strconv.FormatInt(e.size, 10) + "|" + e.mode + "|" + e.owner
}

// fimList: de bewaakte bestanden met grootte, tijd, rechten en eigenaar (hooguit 10.000; per pad telt de eerste)
func fimList(c *fimConf) []fimEntry {
	out := []fimEntry{}
	seen := map[string]bool{}
	add := func(p string, st os.FileInfo, r fimRoot) bool {
		if seen[p] {
			return true
		}
		seen[p] = true
		e := fimEntry{path: p, size: st.Size(), mode: fmt.Sprintf("%o", modeBits(st.Mode())), sev: r.sev, root: r.root}
		if sys, ok := st.Sys().(*syscall.Stat_t); ok {
			e.owner = idNames.user(sys.Uid) + ":" + idNames.group(sys.Gid)
			e.mtime = fmt.Sprintf("%d.%09d0", sys.Mtim.Sec, sys.Mtim.Nsec)
		}
		out = append(out, e)
		return len(out) < 10000
	}
	for _, r := range c.roots {
		if len(out) >= 10000 {
			break
		}
		wild := strings.IndexAny(r.root, "*?[")
		if wild >= 0 {
			base := r.root[:wild]
			if i := strings.LastIndex(base, "/"); i >= 0 {
				base = base[:i]
			} else {
				base = ""
			}
			if base == "" {
				base = "/"
			}
			pat := strings.ReplaceAll(r.root, "**/", "*")
			walkOneFs(base, nil, func(p string, st os.FileInfo) bool {
				if st.Mode().IsRegular() && fnmatchAll(pat, p) && !c.excluded(p) {
					return add(p, st, r)
				}
				return true
			})
			continue
		}
		st, err := os.Stat(r.root)
		if err != nil {
			continue
		}
		if st.IsDir() {
			walkOneFs(r.root, nil, func(p string, st os.FileInfo) bool {
				if st.Mode().IsRegular() && !c.excluded(p) {
					return add(p, st, r)
				}
				return true
			})
		} else if lst, err := os.Lstat(r.root); err == nil && lst.Mode().IsRegular() {
			add(r.root, lst, r)
		}
	}
	return out
}

func sha256File(p string) (string, bool) {
	fh, err := os.Open(p)
	if err != nil {
		return "", false
	}
	defer fh.Close()
	h := sha256.New()
	if _, err := io.Copy(h, fh); err != nil {
		return "", false
	}
	return hex.EncodeToString(h.Sum(nil)), true
}

const fimBig = 104857600 // boven 100 MB geen hash: de tijd van het bestand telt

// fimHash: de hash erbij zetten. Uit de vorige stand als grootte en tijd gelijk zijn, anders opnieuw; een bestand dat niet
// te lezen is, valt weg (zoals bij de bash-agent).
func fimHash(list []fimEntry, old map[string][3]string) []fimEntry {
	out := list[:0]
	for _, e := range list {
		switch o, ok := old[e.path]; {
		case e.size > fimBig:
			e.hash = "m" + e.mtime[:strings.Index(e.mtime, ".")]
		case ok && o[0] == strconv.FormatInt(e.size, 10) && o[1] == e.mtime:
			e.hash = o[2]
		default:
			h, ok := sha256File(e.path)
			if !ok {
				continue
			}
			e.hash = h
		}
		out = append(out, e)
	}
	return out
}

// fimStateRead / fimStateWrite: de stand in het formaat van de bash-agent (pad, grootte, tijd, rechten, eigenaar, hash,
// ernst, bewaakt pad; gescheiden door tabs)
func fimStateRead(file string) (map[string][3]string, []fimEntry) {
	old := map[string][3]string{}
	list := []fimEntry{}
	for _, l := range strings.Split(readText(file), "\n") {
		if f := strings.Split(l, "\t"); len(f) == 8 {
			old[f[0]] = [3]string{f[1], f[2], f[5]}
			size, _ := strconv.ParseInt(f[1], 10, 64)
			list = append(list, fimEntry{f[0], size, f[2], f[3], f[4], f[5], atoiDef(f[6], 0), f[7]})
		}
	}
	return old, list
}

func fimStateText(list []fimEntry) string {
	var b strings.Builder
	for _, e := range list {
		fmt.Fprintf(&b, "%s\t%d\t%s\t%s\t%s\t%s\t%d\t%s\n", e.path, e.size, e.mtime, e.mode, e.owner, e.hash, e.sev, e.root)
	}
	return b.String()
}

// idsFimObserve: voor de proef: hoeveel bestanden er bewaakt worden en van een vaste steekproef (hooguit 300) de waarde
// zoals RzSense hem bewaart (hash|grootte|rechten|eigenaar). De eigen stand staat in de eigen map; die van de bash-agent
// blijft onaangeroerd.
func idsFimObserve(tests string) map[string]any {
	c := fimParse(tests)
	if c == nil || os.MkdirAll(filepath.Join(idsDir(), "fim"), 0o700) != nil {
		return nil
	}
	state := filepath.Join(idsDir(), "fim", "state")
	old, _ := fimStateRead(state)
	list := fimHash(fimList(c), old)
	_ = os.WriteFile(state+".tmp", []byte(fimStateText(list)), 0o600)
	_ = os.Rename(state+".tmp", state)
	paths := make([]string, 0, len(list))
	val := map[string]string{}
	for _, e := range list {
		if len(e.path) <= 255 {
			paths = append(paths, e.path)
			val[e.path] = e.value()
		}
	}
	sort.Strings(paths)
	step := len(paths)/300 + 1
	sample := map[string]string{}
	for i := 0; i < len(paths); i += step {
		sample[paths[i]] = val[paths[i]]
	}
	return map[string]any{"n": len(list), "sample": sample}
}

// idsFimCandidates: gangbare mappen die hier bestaan en niet leeg zijn (voorstellen in RzSense)
func idsFimCandidates() []string {
	out := []string{}
	for _, d := range []string{"/usr/local/bin", "/usr/local/sbin", "/opt", "/etc/nginx", "/etc/httpd", "/etc/apache2", "/etc/caddy", "/etc/haproxy", "/etc/postfix", "/var/www", "/srv/www", "/srv/http"} {
		if ents, err := os.ReadDir(d); err == nil && len(ents) > 0 {
			out = append(out, d)
		}
	}
	return out
}

// idsObserveMore: alles van deze stap bij elkaar, voor de proef
func idsObserveMore(obs map[string]any, tests string) {
	obs["web"] = idsWebshells()
	if haveCmd("ss") {
		listen, _ := stdoutOf(10*time.Second, "ss", "-Htlnp")
		est, _ := stdoutOf(10*time.Second, "ss", "-tnpH", "state", "established")
		obs["conns"], obs["out"] = idsConns(est, idsListenPorts(listen))
	}
	obs["hard"] = idsHardCached()
	if l := idsBackground("suid", time.Hour); l != nil {
		obs["suid"] = l
	}
	if idsPkgTool() != "" {
		if l := idsBackground("pkgdiff", 24*time.Hour); l != nil {
			obs["pkgdiff"] = l
		}
	}
	obs["pkg"], obs["pkg_busy"] = idsPkgActivity()
	obs["fimc"] = idsFimCandidates()
	if f := idsFimObserve(tests); f != nil {
		obs["fim"] = f
	}
}
