//go:build linux

package main

// Diensten, de diepere controle (0.11.0), met dezelfde velden als de bash-agent:
//   - svc: per dienst de systemd-gegevens, de poorten van zijn processen (getest op deze server), een test die bij de
//     dienst past (HTTP, database, SSH, mail, DNS, tijd), fouten in het log van het laatste kwartier, en zelf opgegeven
//     poorten en sockets;
//   - svc_tests: extra tests uit RzSense (een URL of een poort);
//   - svc_list: de diensten op deze server, om in RzSense uit te kiezen.
// In de proef voert de Go-agent een eigen testscript (SERVICE_TEST_DIR) niet uit: dat doet de bash-agent al, en een
// tweede keer draaien kan iets op de server veranderen.

import (
	"bufio"
	"crypto/tls"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// run: een programma met een tijdslimiet; uitvoer (stdout en stderr samen) en de foutcode (-1 = niet te starten of te laat)
func run(timeout time.Duration, name string, args ...string) (string, int) {
	if _, err := exec.LookPath(name); err != nil {
		return "", -1
	}
	cmd := exec.Command(name, args...)
	cmd.Env = append(os.Environ(), "LC_ALL=C")
	done := make(chan struct{})
	var out []byte
	var err error
	go func() { out, err = cmd.CombinedOutput(); close(done) }()
	select {
	case <-done:
	case <-time.After(timeout):
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
		}
		<-done
		return string(out), -1
	}
	if err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			return string(out), ee.ExitCode()
		}
		return string(out), -1
	}
	return string(out), 0
}

func tcpOpen(addr, port string) bool {
	c, err := net.DialTimeout("tcp", net.JoinHostPort(addr, port), 3*time.Second)
	if err != nil {
		return false
	}
	c.Close()
	return true
}

// tcpBanner: de eerste regel die de server stuurt (na eventueel eerst iets te sturen), binnen 3 seconden
func tcpBanner(addr, port, send string) string {
	c, err := net.DialTimeout("tcp", net.JoinHostPort(addr, port), 5*time.Second)
	if err != nil {
		return ""
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(3 * time.Second))
	if send != "" {
		_, _ = c.Write([]byte(send))
	}
	line, _ := bufio.NewReader(io.LimitReader(c, 512)).ReadString('\n')
	return strings.TrimRight(line, "\r\n")
}

// sockOpen: een unix-socket: 0 = neemt verbindingen aan, 1 = niet, 2 = geen antwoord binnen 3 seconden
func sockOpen(path string) int {
	if st, err := os.Stat(path); err != nil || st.Mode()&os.ModeSocket == 0 {
		return 1
	}
	c, err := net.DialTimeout("unix", path, 3*time.Second)
	if err != nil {
		return 1
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(3 * time.Second))
	_, _ = c.Write([]byte("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"))
	buf := make([]byte, 1)
	if _, err := c.Read(buf); err != nil {
		if ne, ok := err.(net.Error); ok && ne.Timeout() {
			return 2
		}
	}
	return 0
}

func httpProbe(url string, timeout time.Duration) (code int, ms int64, body string) {
	cl := &http.Client{Timeout: timeout, Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}, DisableKeepAlives: true}, // zoals curl -k: alleen "antwoordt hij?"
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	t0 := time.Now()
	r, err := cl.Get(url)
	if err != nil {
		return 0, 0, ""
	}
	defer r.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(r.Body, 4<<20))
	return r.StatusCode, time.Since(t0).Milliseconds(), string(b)
}

type portAddr struct{ port, addr string }

var svcKinds = []struct {
	kind string
	re   *regexp.Regexp
}{
	{"http", regexp.MustCompile(`^(nginx|httpd|apache2|caddy|lighttpd|traefik|haproxy)`)},
	{"database", regexp.MustCompile(`^(mariadb|mysql)`)},
	{"postgres", regexp.MustCompile(`^postgresql`)},
	{"redis", regexp.MustCompile(`^(redis|valkey)`)},
	{"ssh", regexp.MustCompile(`^(ssh|sshd)$`)},
	{"smtp", regexp.MustCompile(`^(postfix|exim|sendmail|opensmtpd)`)},
	{"imap", regexp.MustCompile(`^dovecot`)},
	{"dns", regexp.MustCompile(`^(named|bind9|unbound|pihole-FTL|dnsmasq)$|^(knot|pdns|coredns)`)},
	{"chrony", regexp.MustCompile(`^(chronyd|chrony)$`)},
	{"timesyncd", regexp.MustCompile(`^systemd-timesyncd$`)},
}

// probeService: de test die bij de dienst past. Geeft de soort, de uitkomst (0 goed, 1 let op, 2 fout; -1 = geen) en tekst.
func probeService(name string, list []portAddr) (probe string, rc int, msg string) {
	name = strings.TrimSuffix(name, ".service")
	kind := ""
	for _, k := range svcKinds {
		if k.re.MatchString(name) {
			kind = k.kind
			break
		}
	}
	first := portAddr{}
	if len(list) > 0 {
		first = list[0]
	}
	pick := func(ports ...string) portAddr {
		for _, pa := range list {
			for _, p := range ports {
				if pa.port == p {
					return pa
				}
			}
		}
		return first
	}
	switch kind {
	case "http":
		pa := pick("80", "8080", "443", "8443")
		if pa.port == "" {
			return "http", 2, "luistert op geen enkele poort"
		}
		proto := "http"
		if strings.HasSuffix(pa.port, "443") {
			proto = "https"
		}
		u := proto + "://" + net.JoinHostPort(pa.addr, pa.port) + "/"
		code, ms, _ := httpProbe(u, 5*time.Second)
		switch {
		case code == 0:
			return "http", 2, "geen HTTP-antwoord op " + u
		case code >= 500:
			return "http", 1, fmt.Sprintf("HTTP %d in %d ms (serverfout)", code, ms)
		}
		return "http", 0, fmt.Sprintf("HTTP %d in %d ms", code, ms)
	case "database":
		for _, admin := range []string{"mariadb-admin", "mysqladmin"} {
			if _, err := exec.LookPath(admin); err == nil {
				if _, c := run(8*time.Second, admin, "--connect-timeout=5", "ping"); c == 0 {
					return "database", 0, "database antwoordt (ping)"
				}
				return "database", 2, "database antwoordt niet op ping"
			}
		}
		if first.port != "" {
			if tcpBanner(first.addr, first.port, "") != "" {
				return "database", 0, "database geeft zijn begroeting"
			}
			return "database", 2, "geen begroeting op poort " + first.port
		}
		return "database", -1, ""
	case "postgres":
		if _, err := exec.LookPath("pg_isready"); err != nil {
			return "database", -1, ""
		}
		out, c := run(8*time.Second, "pg_isready", "-t", "5")
		switch c {
		case 0:
			return "database", 0, "database neemt verbindingen aan"
		case 1:
			return "database", 1, "database start op of weigert: " + oneLine(out)
		}
		return "database", 2, "database antwoordt niet"
	case "redis":
		if first.port == "" {
			return "redis", -1, ""
		}
		out := tcpBanner(first.addr, first.port, "PING\r\n")
		if strings.HasPrefix(out, "+PONG") || strings.HasPrefix(out, "-NOAUTH") {
			return "redis", 0, "antwoordt op PING"
		}
		return "redis", 2, "geen antwoord op PING"
	case "ssh":
		if first.port == "" {
			return "ssh", -1, ""
		}
		out := tcpBanner(first.addr, first.port, "")
		if strings.HasPrefix(out, "SSH-") {
			if len(out) > 40 {
				out = out[:40]
			}
			return "ssh", 0, out
		}
		return "ssh", 2, "geen SSH-begroeting op poort " + first.port
	case "smtp":
		pa := pick("25", "587")
		if pa.port == "" {
			return "smtp", -1, ""
		}
		if strings.HasPrefix(tcpBanner(pa.addr, pa.port, ""), "220") {
			return "smtp", 0, "mailserver antwoordt (SMTP 220)"
		}
		return "smtp", 2, "geen SMTP-begroeting op poort " + pa.port
	case "imap":
		pa := pick("143")
		if pa.port != "143" {
			return "imap", -1, ""
		}
		if strings.HasPrefix(tcpBanner(pa.addr, "143", ""), "* OK") {
			return "imap", 0, "IMAP antwoordt"
		}
		return "imap", 2, "geen IMAP-begroeting"
	case "dns":
		pa := pick("53")
		if pa.port == "" {
			return "dns", -1, ""
		}
		if _, err := exec.LookPath("dig"); err != nil {
			return "dns", -1, ""
		}
		out, c := run(6*time.Second, "dig", "+time=2", "+tries=1", "@"+pa.addr, "-p", pa.port, ".", "NS")
		if m := regexp.MustCompile(`status: [A-Z]+`).FindString(out); c == 0 && m != "" {
			return "dns", 0, "DNS antwoordt (" + m + ")"
		}
		return "dns", 2, "DNS geeft geen antwoord"
	case "chrony":
		if _, err := exec.LookPath("chronyc"); err != nil {
			return "ntp", -1, ""
		}
		out, _ := run(6*time.Second, "chronyc", "-n", "tracking")
		if regexp.MustCompile(`Leap status *: Normal`).MatchString(out) {
			return "ntp", 0, "tijd is gesynchroniseerd"
		}
		return "ntp", 1, "tijd is niet gesynchroniseerd"
	case "timesyncd":
		out, _ := run(6*time.Second, "timedatectl", "show", "-p", "NTPSynchronized", "--value")
		if strings.TrimSpace(out) == "yes" {
			return "ntp", 0, "tijd is gesynchroniseerd"
		}
		return "ntp", 1, "tijd is niet gesynchroniseerd"
	}
	return "", -1, ""
}

// cgroupPids: alle PID's in de cgroup van de dienst (met de onderliggende), of nil
func cgroupPids(cg string) map[int]bool {
	if cg == "" {
		return nil
	}
	for _, base := range []string{"/sys/fs/cgroup", "/sys/fs/cgroup/systemd", "/sys/fs/cgroup/unified"} {
		root := base + cg
		if st, err := os.Stat(root); err != nil || !st.IsDir() {
			continue
		}
		out := map[int]bool{}
		_ = filepath.WalkDir(root, func(p string, d os.DirEntry, err error) error {
			if err == nil && !d.IsDir() && d.Name() == "cgroup.procs" {
				for _, f := range strings.Fields(readText(p)) {
					if n, err := strconv.Atoi(f); err == nil {
						out[n] = true
					}
				}
			}
			return nil
		})
		return out
	}
	return nil
}

// manualPorts: zelf opgegeven poorten en sockets per dienst (regels "ports" in de testlijst): naam → items
func manualPorts(testsCache string) map[string][]string {
	out := map[string][]string{}
	for _, l := range strings.Split(testsCache, "\n") {
		f := strings.Split(l, "\t")
		if len(f) >= 4 && f[1] == "ports" {
			out[f[2]] = append(out[f[2]], strings.Fields(f[3])...)
		}
	}
	return out
}

type listenEntry struct {
	port, addr string
	pids       []int
}

// listenWithPids: de luisterende poorten met alle processen die de socket open hebben (adres zonder %interface)
func listenWithPids() []listenEntry {
	var socks []listenSock
	for _, fam := range []uint8{2, 10} {
		if s, err := diagListen(fam); err == nil {
			socks = append(socks, s...)
		}
	}
	if len(socks) == 0 {
		socks = procListen(readText("/proc/net/tcp"), readText("/proc/net/tcp6"))
	}
	byInode := map[uint32][]int{}
	dirs, _ := os.ReadDir("/proc")
	for _, d := range dirs {
		pid, err := strconv.Atoi(d.Name())
		if err != nil {
			continue
		}
		fds, err := os.ReadDir(fmt.Sprintf("/proc/%d/fd", pid))
		if err != nil {
			continue
		}
		for _, fd := range fds {
			link, err := os.Readlink(fmt.Sprintf("/proc/%d/fd/%s", pid, fd.Name()))
			if err != nil {
				continue
			}
			if m := sockLinkRe.FindStringSubmatch(link); m != nil {
				ino, _ := strconv.ParseUint(m[1], 10, 32)
				byInode[uint32(ino)] = append(byInode[uint32(ino)], pid)
			}
		}
	}
	out := make([]listenEntry, 0, len(socks))
	for _, s := range socks {
		addr, _, _ := strings.Cut(s.addr, "%")
		out = append(out, listenEntry{port: strconv.Itoa(s.port), addr: addr, pids: byInode[s.inode]})
	}
	return out
}

func sysUptime() float64 {
	f := strings.Fields(readText("/proc/uptime"))
	if len(f) == 0 {
		return 0
	}
	v, _ := strconv.ParseFloat(f[0], 64)
	return v
}

// svcDetailOne: de details van één dienst
func svcDetailOne(name string, listen []listenEntry, manual []string, now time.Time) map[string]any {
	out, _ := run(8*time.Second, "systemctl", "show", "-p", "ActiveState", "-p", "SubState", "-p", "Type", "-p", "MainPID", "-p", "NRestarts", "-p", "ActiveEnterTimestampMonotonic", "-p", "ControlGroup", name)
	prop := map[string]string{}
	for _, l := range strings.Split(out, "\n") {
		if k, v, ok := strings.Cut(l, "="); ok {
			prop[k] = strings.TrimSpace(v)
		}
	}
	if prop["ActiveState"] != "active" {
		return map[string]any{"sub": prop["SubState"]}
	}
	mainPid, _ := strconv.Atoi(prop["MainPID"])
	var since any
	if mono, err := strconv.ParseFloat(prop["ActiveEnterTimestampMonotonic"], 64); err == nil && mono > 0 {
		since = int64(float64(now.Unix()) - (sysUptime() - mono/1e6))
	}
	pids := cgroupPids(prop["ControlGroup"])
	if len(pids) == 0 && mainPid > 0 {
		pids = map[int]bool{mainPid: true}
		for _, r := range procTable(now) {
			if r.ppid == mainPid {
				pids[r.pid] = true
			}
		}
	}
	ports := []string{}
	have := map[string]bool{}
	var plist []portAddr
	fail := []int{}
	failed := map[string]bool{}
	for _, l := range listen {
		if have[l.port] {
			continue
		}
		mine := false
		for _, p := range l.pids {
			if pids[p] {
				mine = true
				break
			}
		}
		if !mine {
			continue
		}
		taddr := l.addr
		if taddr == "" || taddr == "*" || taddr == "0.0.0.0" || taddr == "::" {
			taddr = "127.0.0.1"
		}
		have[l.port] = true
		ports = append(ports, l.port)
		plist = append(plist, portAddr{l.port, taddr})
		if !tcpOpen(taddr, l.port) && !(l.addr == "::" && tcpOpen("::1", l.port)) {
			failed[l.port] = true
		}
	}
	// Zelf opgegeven poorten en sockets: altijd testen, en de dienst-test gebruikt ze
	var manList []portAddr
	manShow, sockets, sockFail := []string{}, []string{}, []string{}
	for _, m := range manual {
		if strings.HasPrefix(m, "/") {
			sockets = append(sockets, m)
			switch sockOpen(m) {
			case 0:
			case 2:
				sockFail = append(sockFail, m+": reageert niet binnen 3 seconden")
			default:
				if st, err := os.Stat(m); err == nil && st.Mode()&os.ModeSocket != 0 {
					sockFail = append(sockFail, m+": neemt geen verbindingen aan")
				} else {
					sockFail = append(sockFail, m+": bestaat niet")
				}
			}
			continue
		}
		raw := m
		m = strings.TrimPrefix(m, "[")
		mport, maddr := m, "127.0.0.1"
		if i := strings.LastIndexByte(m, ':'); i >= 0 {
			mport, maddr = m[i+1:], strings.TrimSuffix(m[:i], "]")
		}
		if _, err := strconv.Atoi(mport); err != nil {
			continue
		}
		manList = append(manList, portAddr{mport, maddr})
		manShow = append(manShow, raw)
		if !have[mport] {
			have[mport] = true
			ports = append(ports, mport)
		}
		if !tcpOpen(maddr, mport) {
			failed[mport] = true
		}
	}
	for p := range failed {
		n, _ := strconv.Atoi(p)
		fail = append(fail, n)
	}
	sort.Ints(fail)
	use := plist
	if len(manList) > 0 {
		use = manList
	}
	probe, rc, msg := probeService(name, use)
	var logN any
	logLast := ""
	if _, err := exec.LookPath("journalctl"); err == nil {
		log, _ := run(10*time.Second, "journalctl", "-u", name, "-p", "err", "--since", "-15 min", "-q", "--no-pager", "-o", "cat")
		n := 0
		for _, l := range strings.Split(log, "\n") {
			if strings.TrimSpace(l) != "" {
				n++
				logLast = l
			}
		}
		if len(logLast) > 160 {
			logLast = logLast[:160]
		}
		logN = n
	}
	portNums := make([]int, 0, len(ports))
	for _, p := range ports {
		n, _ := strconv.Atoi(p)
		portNums = append(portNums, n)
	}
	var restarts any
	if n, err := strconv.Atoi(prop["NRestarts"]); err == nil {
		restarts = n
	}
	var probeRC any
	if rc >= 0 {
		probeRC = rc
	}
	return map[string]any{"sub": prop["SubState"], "type": prop["Type"], "pid": mainPid, "restarts": restarts, "since": since, "ports": portNums, "ports_fail": fail,
		"manual": manShow, "sockets": sockets, "sockets_fail": sockFail, "probe": probe, "probe_rc": probeRC, "probe_msg": msg, "log_errors": logN, "log_last": logLast,
		"custom_rc": nil, "custom_msg": ""} // het eigen testscript draait de bash-agent; de proef voert het niet nog eens uit
}

// collectSvcDetailLinux: per dienst de details (SERVICE_DEEP=0 zet het uit, zoals bij de bash-agent)
func collectSvcDetailLinux() map[string]any {
	out := map[string]any{}
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if conf["SERVICE_DEEP"] == "0" {
		return out
	}
	if _, err := exec.LookPath("systemctl"); err != nil {
		return out
	}
	cache := readText(filepath.Join(bashCachePath(), "tests.cache"))
	names := wantedServices(conf, cache)
	if len(names) == 0 {
		return out
	}
	manual := manualPorts(cache)
	listen := listenWithPids()
	now := time.Now()
	for _, n := range names {
		out[n] = svcDetailOne(n, listen, manual[n], now)
	}
	return out
}

// collectSvcTestsLinux: extra tests uit RzSense: "id <tab> url|port <tab> doel <tab> verwachte tekst" → id → {ok, msg}
func collectSvcTestsLinux() map[string]any {
	out := map[string]any{}
	for _, l := range strings.Split(readText(filepath.Join(bashCachePath(), "tests.cache")), "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || (f[1] != "url" && f[1] != "port") {
			continue
		}
		if _, err := strconv.Atoi(f[0]); err != nil {
			continue
		}
		target, expect := f[2], ""
		if len(f) > 3 {
			expect = f[3]
		}
		ok, msg := false, ""
		if f[1] == "url" {
			code, ms, body := httpProbe(target, 10*time.Second)
			switch {
			case code == 0:
				msg = "geen antwoord van " + target
			case code >= 400:
				msg = fmt.Sprintf("HTTP %d van %s", code, target)
			case expect != "" && !strings.Contains(body, expect):
				msg = fmt.Sprintf("HTTP %d, maar %q staat niet in het antwoord", code, expect)
			default:
				ok, msg = true, fmt.Sprintf("HTTP %d in %d ms", code, ms)
				if expect != "" {
					msg += fmt.Sprintf(", bevat %q", expect)
				}
			}
		} else {
			host, port := "127.0.0.1", target
			if i := strings.LastIndexByte(target, ':'); i >= 0 {
				host, port = target[:i], target[i+1:]
			}
			if tcpOpen(host, port) {
				ok, msg = true, "poort "+port+" op "+host+" antwoordt"
			} else {
				msg = "poort " + port + " op " + host + " antwoordt niet"
			}
		}
		out[f[0]] = map[string]any{"ok": ok, "msg": msg}
	}
	return out
}

var svcListNameRe = regexp.MustCompile(`^[A-Za-z0-9@._:-]+$`)

// svcListFrom: uit "systemctl list-unit-files" (naam, ingeschakeld) en "list-units --all --plain" (naam, …, actief):
// de diensten die aan staan bij het opstarten, draaien, of mislukt zijn en niet uitgeschakeld. [naam, status, 0/1]
func svcListFrom(unitFiles, units string) [][]any {
	en, st, names := map[string]string{}, map[string]string{}, map[string]bool{}
	take := func(text string, col int, into map[string]string) {
		for _, l := range strings.Split(text, "\n") {
			f := strings.Fields(l)
			if len(f) <= col || !strings.HasSuffix(f[0], ".service") || strings.HasSuffix(f[0], "@.service") {
				continue
			}
			n := strings.TrimSuffix(f[0], ".service")
			if !svcListNameRe.MatchString(n) {
				continue
			}
			into[n] = f[col]
			names[n] = true
		}
	}
	take(unitFiles, 1, en)
	take(units, 2, st)
	keys := make([]string, 0, len(names))
	for n := range names {
		keys = append(keys, n)
	}
	sort.Strings(keys)
	out := [][]any{}
	for _, n := range keys {
		if en[n] == "enabled" || st[n] == "active" || (st[n] == "failed" && en[n] != "disabled") {
			state := st[n]
			if state == "" {
				state = "inactive"
			}
			e := 0
			if en[n] == "enabled" {
				e = 1
			}
			out = append(out, []any{n, state, e})
		}
	}
	return out
}

func collectSvcListLinux() [][]any {
	if _, err := exec.LookPath("systemctl"); err != nil {
		return [][]any{}
	}
	files, _ := run(15*time.Second, "systemctl", "list-unit-files", "--type=service", "--no-legend", "--no-pager")
	units, _ := run(15*time.Second, "systemctl", "list-units", "--type=service", "--all", "--no-legend", "--no-pager", "--plain")
	return svcListFrom(files, units)
}
