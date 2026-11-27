//go:build linux

package main

// De laatste onderdelen die de bash-agent meestuurt (0.25.0):
//   - wifi: SSID, access point, band, kanaal, kanaalbreedte, signaal en verbindingssnelheid van de eigen wifi-kaart
//     (agent/src/85-meesturen.sh), voor Netwerk → Netwerksnelheid;
//   - certificaten zoeken: bestanden met een certificaat in CERT_DIRS en de mappen uit RzSense, om uit te kiezen
//     (agent/src/75-…, "Certificaten zoeken"); één keer per dag, of meteen bij "Opnieuw zoeken";
//   - de zelftest (🧪 Test in de Agent Manager, agent/src/88-zelftest.sh): alleen vaste controles die lezen;
//   - het meetpunt van buitenaf (agent/src/89-buitenprobe.sh): gewone TCP-verbindingen naar openbare adressen die
//     RzSense opgeeft, zonder gegevens te versturen.

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// ---------------------------------------------------------------- wifi

var (
	mhzRe     = regexp.MustCompile(`^[0-9]+MHz$`)
	wifiLabel = regexp.MustCompile(`^[ \t]*(SSID|freq|signal|tx bitrate|rx bitrate):`)
)

func cut64(s string) string {
	b := []byte(s)
	for i, c := range b {
		if c >= 1 && c <= 0x1f {
			b[i] = 0
		}
	}
	s = strings.ReplaceAll(string(b), "\x00", "")
	if len(s) > 64 {
		s = s[:64]
	}
	return strings.ToValidUTF8(s, "")
}

// parseWifi: uitvoer van "iw dev <kaart> link" → de verbinding, of nil als de kaart niet verbonden is
func parseWifi(iface, out string) map[string]any {
	var bssid, ssid, std string
	var freq, sig, tx, rx, width float64
	hasW := false
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if strings.HasPrefix(l, "Connected to ") && len(f) >= 3 {
			bssid = f[2]
		}
		m := wifiLabel.FindStringSubmatch(l)
		if m == nil || len(f) < 2 {
			continue
		}
		switch m[1] {
		case "SSID":
			ssid = strings.TrimLeft(l[len(m[0]):], " \t")
		case "freq":
			freq = awkNum(f[1])
		case "signal":
			sig = awkNum(f[1])
		case "tx bitrate", "rx bitrate":
			if len(f) >= 3 {
				if f[0] == "tx" {
					tx = awkNum(f[2])
				} else {
					rx = awkNum(f[2])
				}
			}
			for _, w := range f[3:] {
				if mhzRe.MatchString(w) {
					width, hasW = awkNum(w), true
				}
				switch {
				case strings.HasPrefix(w, "EHT"):
					std = "be"
				case strings.HasPrefix(w, "HE") && std != "be":
					std = "ax"
				case strings.HasPrefix(w, "VHT") && std == "":
					std = "ac"
				case w == "MCS" && std == "":
					std = "n"
				}
			}
		}
	}
	if bssid == "" {
		return nil
	}
	band := ""
	var ch any
	switch {
	case freq >= 5925:
		band, ch = "6", (freq-5950)/5
	case freq >= 4900:
		band, ch = "5", (freq-5000)/5
	case freq == 2484:
		band, ch = "2.4", 14.0
	case freq > 0:
		band, ch = "2.4", (freq-2407)/5
	}
	nz := func(v float64) any {
		if v == 0 {
			return nil
		}
		return v
	}
	var w any
	if hasW {
		w = width
	}
	return map[string]any{"if": cut64(iface), "ssid": cut64(ssid), "bssid": cut64(bssid), "band": band, "chan": ch, "width": w, "sig": nz(sig), "tx": nz(tx), "rx": nz(rx), "std": std}
}

// collectWifiLinux: de wifi-verbinding van deze server, {noiw:1} als er wel een wifi-kaart is maar geen iw, of nil
func collectWifiLinux(root string) map[string]any {
	ents, _ := os.ReadDir(root)
	hasIw := haveCmd("iw")
	for _, e := range ents {
		n := e.Name()
		d := filepath.Join(root, n)
		_, e1 := os.Stat(filepath.Join(d, "wireless"))
		_, e2 := os.Lstat(filepath.Join(d, "phy80211"))
		if (e1 != nil && e2 != nil) || !nicNameRe.MatchString(n) {
			continue
		}
		if !hasIw {
			return map[string]any{"noiw": 1, "if": n}
		}
		if strings.TrimSpace(readText(filepath.Join(d, "operstate"))) != "up" {
			continue
		}
		out, _ := stdoutOf(5*time.Second, "iw", "dev", n, "link")
		if w := parseWifi(n, out); w != nil {
			return w
		}
	}
	return nil
}

// ---------------------------------------------------------------- certificaten zoeken

const defaultCertDirs = "/etc/ssl /etc/pki /etc/letsencrypt /etc/nginx /etc/apache2 /etc/httpd /etc/haproxy /etc/lighttpd /etc/caddy /etc/traefik /etc/postfix /etc/dovecot /etc/mosquitto /etc/cockpit /etc/pve /etc/ipa /opt /srv /usr/local/etc /var/lib/docker/volumes /config /ssl"

// certSkip: de CA-lijsten van het systeem en van programmeertalen, testmappen en bundels: niet in zoeken
func certSkip(path string) bool {
	base := filepath.Base(path)
	low := strings.ToLower(base)
	switch path {
	case "/etc/ssl/certs", "/etc/pki/ca-trust", "/etc/letsencrypt/archive", "/etc/pki/tls/certs/ca-bundle.crt":
		return true
	}
	switch base {
	case "ca-certificates", "node_modules", ".git", "site-packages", "dist-packages", "ssl_certs", "certifi", "test", "tests":
		return true
	}
	return low == "cacert.pem" || strings.Contains(low, "ca-bundle")
}

// findCerts: bestanden met een certificaat in deze mappen (hooguit 300, tot 8 mappen diep, kleiner dan 200 kB)
func findCerts(dirs []string) []map[string]any {
	found := []map[string]any{}
	stop := time.Now().Add(120 * time.Second)
	for _, root := range dirs {
		st, err := os.Stat(root)
		if !strings.HasPrefix(root, "/") || strings.Contains(root, "..") || err != nil || !st.IsDir() || certSkip(root) {
			continue
		}
		dev := devOf(root)
		var walk func(dir string, depth int) bool
		walk = func(dir string, depth int) bool {
			ents, err := os.ReadDir(dir)
			if err != nil {
				return true
			}
			for _, e := range ents {
				p := filepath.Join(dir, e.Name())
				if certSkip(p) {
					continue
				}
				if e.IsDir() {
					if depth < 8 && devOf(p) == dev && !walk(p, depth+1) {
						return false
					}
					continue
				}
				low := strings.ToLower(e.Name())
				if !(strings.HasSuffix(low, ".crt") || strings.HasSuffix(low, ".pem") || strings.HasSuffix(low, ".cer") || strings.HasSuffix(low, ".cert")) || depth > 8 {
					continue
				}
				li, err := os.Lstat(p)
				if err != nil || !(li.Mode().IsRegular() || li.Mode()&os.ModeSymlink != 0) || (li.Size()+1023)/1024 >= 200 {
					continue
				}
				fi, err := os.Stat(p) // volgt een symlink, zoals [ -f ]
				if err != nil || !fi.Mode().IsRegular() || !readable(p) {
					continue
				}
				data, err := os.ReadFile(p)
				n := strings.Count(string(data), "-----BEGIN CERTIFICATE-----")
				if err != nil || n == 0 || n > 20 { // meer dan 20: een CA-bundel
					continue
				}
				chain := pemCerts(data)
				if len(chain) == 0 {
					continue
				}
				c := certLineLinux(p, chain, nil, "")
				found = append(found, map[string]any{"path": cleanText(p), "cn": c["cn"], "issuer": c["issuer"], "not_after": c["not_after"], "n": c["chain_n"], "chain_min": c["chain_min"]})
				if len(found) >= 300 || time.Now().After(stop) {
					return false
				}
			}
			return true
		}
		if !walk(root, 1) {
			break
		}
	}
	return found
}

// collectCertFoundLinux: het onderdeel "cert_found": één keer per dag opnieuw gezocht, of meteen als RzSense erom
// vraagt ("certscan") of de mappen veranderen ("certdirs")
func collectCertFoundLinux() any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	dirs := defaultCertDirs
	if v, set := conf["CERT_DIRS"]; set {
		dirs = v
	}
	req := ""
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) >= 3 && f[0] == "0" && f[1] == "certdirs" {
			dirs += " " + f[2]
		}
		if len(f) >= 3 && f[0] == "0" && f[1] == "certscan" && req == "" {
			req = f[2]
		}
	}
	sum := sha1.Sum([]byte(dirs + "|" + req))
	key := hex.EncodeToString(sum[:])[:16]
	cache := filepath.Join(dataDir(), "certfound.json")
	var c struct {
		Key   string           `json:"key"`
		At    int64            `json:"at"`
		Found []map[string]any `json:"found"`
	}
	if b, err := os.ReadFile(cache); err == nil && json.Unmarshal(b, &c) == nil && c.Key == key && time.Now().Unix()-c.At < 86400 {
		return c.Found
	}
	if strings.TrimSpace(dirs) == "" {
		return []map[string]any{}
	}
	c.Key, c.At, c.Found = key, time.Now().Unix(), findCerts(strings.Fields(dirs))
	if b, err := json.Marshal(c); err == nil {
		_ = os.WriteFile(cache, b, 0o600)
	}
	// via JSON heen en terug, zodat een verse lijst er precies zo uitziet als een bewaarde
	var back []map[string]any
	if b, err := json.Marshal(c.Found); err == nil && json.Unmarshal(b, &back) == nil {
		return back
	}
	return c.Found
}

// ---------------------------------------------------------------- zelftest (🧪 Test)

// collectSelftestLinux: op verzoek van RzSense (regel "0 <tab> selftest <tab> id") test de agent zichzelf, één keer per
// vraag. Alleen vaste controles die lezen; RzSense stuurt geen commando's mee.
func collectSelftestLinux(c *Config) map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	id := ""
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "selftest" {
			id = f[2]
			break
		}
	}
	if !regexp.MustCompile(`^[0-9]{1,18}$`).MatchString(id) || strings.TrimSpace(readState("selftest.done")) == id {
		return nil
	}
	items := []map[string]string{}
	add := func(k, ok, d string) { items = append(items, map[string]string{"k": k, "ok": ok, "d": cleanText(d)}) }
	// Planning: de timer van de Go-agent
	if active, known := systemctlActive("rzsense-shadow.timer"); !known {
		add("sched", "warn", "geen systemd gevonden: draait de Go-agent via cron?")
	} else if active {
		add("sched", "ok", "systemd-timer actief (rzsense-shadow.timer; de Go-agent doet het werk)")
	} else {
		add("sched", "bad", "systemd-timer staat niet aan: sudo systemctl enable --now rzsense-shadow.timer")
	}
	// Instellingenbestand (dat van de bash-agent): alleen leesbaar voor root
	if st, err := os.Stat(bashConfPath()); err == nil {
		if perm := fmt.Sprintf("%o", st.Mode().Perm()); perm == "600" {
			add("conf", "ok", bashConfPath()+" (600)")
		} else {
			add("conf", "warn", bashConfPath()+" heeft rechten "+perm+": sudo chmod 600 "+bashConfPath())
		}
	}
	// De naam van RzSense opzoeken, en het adres (en reserve-adres) bereiken
	host := c.URL
	if _, rest, ok := strings.Cut(host, "://"); ok {
		host = rest
	}
	host, _, _ = strings.Cut(host, "/")
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}
	host = strings.Trim(host, "[]")
	if net.ParseIP(host) != nil {
		add("dns", "skip", "adres is een IP-adres ("+host+")")
	} else if ips, err := net.LookupHost(host); err == nil && len(ips) > 0 {
		add("dns", "ok", host+" → "+ips[0])
	} else {
		add("dns", "bad", host+" is niet op te zoeken")
	}
	reach := func(k, url, pin string) {
		t0 := time.Now()
		if r, err := call(url, pin, "GET", "ping", nil, nil, 10*time.Second); err == nil && r != nil {
			add(k, "ok", fmt.Sprintf("%s bereikbaar in %d ms", url, time.Since(t0).Milliseconds()))
		} else {
			lvl := "warn"
			if k == "url" {
				lvl = "bad"
			}
			add(k, lvl, url+" niet bereikbaar ("+cutRunes(oneLine(fmt.Sprint(err)), 120)+")")
		}
	}
	reach("url", c.URL, c.Pin)
	if c.URLFallback != "" {
		reach("fallback", c.URLFallback, c.PinFallback)
	}
	// Klok gesynchroniseerd?
	if t := collectTimeLinux(); t == nil {
		add("clock", "skip", "geen chrony of systemd-timesyncd")
	} else if t["sync"] == true {
		add("clock", "ok", fmt.Sprintf("gesynchroniseerd (%v)", t["src"]))
	} else {
		add("clock", "warn", "niet gesynchroniseerd met een tijdserver")
	}
	// Vrije ruimte waar de agent zijn gegevens bewaart
	var fs syscall.Statfs_t
	if syscall.Statfs(dataDir(), &fs) == nil {
		free := int64(fs.Bavail) * int64(fs.Bsize) / 1048576
		switch {
		case free < 50:
			add("disk", "bad", fmt.Sprintf("nog %d MB vrij in %s", free, dataDir()))
		case free < 500:
			add("disk", "warn", fmt.Sprintf("nog %d MB vrij in %s", free, dataDir()))
		default:
			add("disk", "ok", fmt.Sprintf("%d MB vrij", free))
		}
	}
	add("queue", "ok", "de Go-agent bewaart geen wachtrij")
	// Fouten in het log van de agent (laatste uur)
	if haveCmd("journalctl") {
		out, _ := stdoutOf(10*time.Second, "journalctl", "-u", "rzsense-shadow.service", "-p", "err", "--since", "-1h", "-q", "--no-pager", "-o", "cat")
		if ls := lines(out); len(ls) > 0 {
			add("log", "warn", fmt.Sprintf("%d fout(en), laatste: %s", len(ls), cutRunes(ls[len(ls)-1], 160)))
		} else {
			add("log", "ok", "geen fouten")
		}
	}
	add("tools", "ok", "de Go-agent heeft geen hulpprogramma's nodig (rzsense "+Version+")")
	writeState("selftest.done", id)
	idN, _ := strconv.ParseInt(id, 10, 64)
	return map[string]any{"id": idN, "now": time.Now().Unix(), "items": items}
}

// ---------------------------------------------------------------- meetpunt van buitenaf

var (
	xpIPRe    = regexp.MustCompile(`^[0-9a-fA-F:.]{3,45}$`)
	xpPortsRe = regexp.MustCompile(`^[0-9][0-9,]*$`)
	xpPrivRe  = regexp.MustCompile(`^(10\.|127\.|0\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|::1$|[fF][cdCD]|[fF][eE]80)`)
)

// openPorts: welke van deze poorten nemen een gewone TCP-verbinding aan (64 tegelijk, 2 seconden per poort)
func openPorts(ip string, ports []string, dial func(addr string) bool) []int {
	var mu sync.Mutex
	var wg sync.WaitGroup
	open := []int{}
	sem := make(chan struct{}, 64)
	for i, p := range ports {
		n, err := strconv.Atoi(p)
		if i >= 2000 || err != nil || n < 1 || n > 65535 {
			continue
		}
		wg.Add(1)
		sem <- struct{}{}
		go func(n int) {
			defer wg.Done()
			defer func() { <-sem }()
			if dial(net.JoinHostPort(ip, strconv.Itoa(n))) {
				mu.Lock()
				open = append(open, n)
				mu.Unlock()
			}
		}(n)
	}
	wg.Wait()
	sort.Ints(open)
	return open
}

// collectExprobeLinux: regels "0 <tab> exprobe <tab> id <tab> ip <tab> poorten": één keer per vraag, alleen openbare
// adressen, zonder gegevens te versturen. Na 50 seconden stopt hij met nieuwe adressen ("partial").
func collectExprobeLinux() map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	id := ""
	var rows [][]string
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[0] != "0" || f[1] != "exprobe" {
			continue
		}
		if id == "" {
			id = f[2]
		}
		if f[2] == id && len(rows) < 32 {
			rows = append(rows, f)
		}
	}
	if !regexp.MustCompile(`^[0-9]{1,18}$`).MatchString(id) || strings.TrimSpace(readState("exprobe.done")) == id {
		return nil
	}
	res := map[string][]int{}
	partial := 0
	end := time.Now().Add(50 * time.Second)
	dial := func(addr string) bool {
		c, err := net.DialTimeout("tcp", addr, 2*time.Second)
		if err != nil {
			return false
		}
		c.Close()
		return true
	}
	for _, f := range rows {
		if len(f) < 5 || !xpIPRe.MatchString(f[3]) || !xpPortsRe.MatchString(f[4]) || len(f[4]) > 12000 || xpPrivRe.MatchString(f[3]) || net.ParseIP(f[3]) == nil {
			continue
		}
		if time.Now().After(end) {
			partial = 1
			break
		}
		res[f[3]] = openPorts(f[3], strings.Split(f[4], ","), dial)
	}
	writeState("exprobe.done", id)
	idN, _ := strconv.ParseInt(id, 10, 64)
	return map[string]any{"id": idN, "partial": partial, "res": res}
}

// ---------------------------------------------------------------- diagnose voor AI-advies

var (
	diagDiskRe = regexp.MustCompile(`^/[A-Za-z0-9._/@+-]{0,200}$`)
	diagPortRe = regexp.MustCompile(`^[0-9]{1,5}$`)
)

// collectDiagLinux: op verzoek van RzSense (regel "0 <tab> diag <tab> id <tab> soort <tab> doel") bij een storing: een
// VASTE set commando's die alleen lezen. RzSense stuurt alleen de soort en het doel (een dienst, container, map of
// poort), nooit een commando; het doel wordt hier opnieuw streng gecontroleerd. COMMANDS=0 blokkeert het altijd.
// Elke aanvraag één keer; per onderdeel hooguit 20 seconden en 6 kB uitvoer.
func collectDiagLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	if conf["COMMANDS"] == "0" {
		return nil
	}
	var f []string
	for _, l := range strings.Split(readText(filepath.Join(bashCachePath(), "tests.cache")), "\n") {
		if x := strings.Split(l, "\t"); len(x) >= 3 && x[0] == "0" && x[1] == "diag" {
			f = x
			break
		}
	}
	if f == nil {
		return nil
	}
	for len(f) < 5 {
		f = append(f, "")
	}
	id, kind, target := f[2], f[3], f[4]
	if !regexp.MustCompile(`^[0-9]{1,18}$`).MatchString(id) || strings.TrimSpace(readState("diag.done")) == id {
		return nil
	}
	parts := []map[string]string{}
	add := func(k, out string) {
		parts = append(parts, map[string]string{"k": k, "out": jsonText([]byte(out), 6000)})
	}
	runPart := func(k string, name string, args ...string) {
		out, _ := run(20*time.Second, name, args...)
		add(k, out)
	}
	runPart("uptime", "uptime")
	if haveCmd("journalctl") {
		runPart("errors", "journalctl", "-p", "err", "-n", "30", "--no-pager", "--since", "-3h", "-o", "short-iso")
	}
	if os.Geteuid() == 0 {
		runPart("dmesg", "sh", "-c", "dmesg -T 2>/dev/null | tail -n 25")
	}
	switch kind {
	case "svc":
		if unitNameRe.MatchString(target) && len(target) <= 100 && haveCmd("systemctl") {
			runPart("status", "systemctl", "status", target, "--no-pager", "-l", "-n", "0")
			runPart("journal", "journalctl", "-u", target, "-n", "60", "--no-pager", "-o", "short-iso")
		}
	case "ctr":
		if m := healCtrRe.FindStringSubmatch(target); m != nil && (m[1] == "root" || userExists(m[1])) {
			e := &ctrEngine{seen: map[string]bool{}}
			_, all, _ := e.pm(m[1], "inspect", "--format", "{{.State.Status}} exit={{.State.ExitCode}} restarts={{.RestartCount}} started={{.State.StartedAt}} error={{.State.Error}}", m[2])
			add("state", all)
			_, all, _ = e.pm(m[1], "logs", "--tail", "60", m[2])
			add("logs", all)
		}
	case "disk":
		if diagDiskRe.MatchString(target) && !strings.Contains(target, "..") {
			runPart("df", "df", "-h", target)
			runPart("inodes", "df", "-i", target)
			runPart("du", "sh", "-c", `du -xh --max-depth=2 -- "$1" 2>/dev/null | sort -h | tail -n 15`, "_", target)
		}
	case "proc":
		runPart("top", "sh", "-c", "ps -eo pid,user,%cpu,%mem,etime,comm --sort=-%cpu | head -n 16")
		runPart("mem", "free", "-m")
		if haveCmd("journalctl") {
			runPart("oom", "sh", "-c", `journalctl -k --since -6h --no-pager 2>/dev/null | grep -i -E "out of memory|oom-kill|killed process" | tail -n 10`)
		}
	case "port":
		if diagPortRe.MatchString(target) {
			runPart("listen", "sh", "-c", `ss -ltnp 2>/dev/null | grep -E ":$1([^0-9]|$)" || echo "niets luistert op poort $1"`, "_", target)
		}
	}
	writeState("diag.done", id)
	idN, _ := strconv.ParseInt(id, 10, 64)
	return map[string]any{"id": idN, "items": parts}
}
