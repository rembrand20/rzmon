//go:build linux

package main

// Logs van webdiensten (0.33.0), zoals de bash-agent (het blok "Logs van webdiensten" in agent/src/60-ids-en-edr.sh en
// de parsers in 10-edr-sensoren.sh). Regels "0 <tab> weblog <tab> swag|ha|vw <tab> pad, containernaam of auto". Alleen de
// nieuwe regels sinds de vorige keer; per IP samengevat (aantallen, een paar paden met een foutcode, scanner). RzSense
// past de scenario's toe. De agent leest alleen; de positie in elk logbestand wordt pas bewaard als het rapport is
// aangenomen, en staat in de map die de bash-agent ook gebruikt (bij overnemen gaat er geen regel verloren of dubbel mee).

import (
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

func weblogDir() string { return sharedDir("weblog") }

var (
	wlIPRe      = regexp.MustCompile(`^[0-9a-fA-F:.]+$`)
	wlSecretRe  = regexp.MustCompile(`[?&;](pass|passwd|password|pwd|[a-z_]*token|api_?key|apikey|secret|key|auth|authsig|session|sid|jwt|code|sig|signature|otp|ticket)=`)
	wlEndRe     = regexp.MustCompile(`[&;# ]`)
	wlScanRe    = regexp.MustCompile(`(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|dirbuster|gobuster|ffuf|feroxbuster|l9explore|censysinspect)`)
	wlBotRe     = regexp.MustCompile(`(sqlmap|nikto|nuclei|zgrab|masscan|nmap scripting|dirbuster|gobuster|feroxbuster|wpscan|acunetix|netsparker|havij|zmeu|jorgee|fimap|w3af|openvas|nessus)`)
	wlSuspectRe = regexp.MustCompile("(%27|%22|%3c|%3e|%24%7b|%2e%2e|\\.\\./|\\$\\{|\\$\\(|`|;|\\||<|>|'|\\\\x22|union|select|sleep\\(|/etc/|cmd=|exec|eval|system\\(|base64|php://|jndi|\\.env|\\.git/|wp-login|xmlrpc|phpunit|cgi-bin|boaform|hnap1|actuator|/shell\\?|\\(\\) *\\{|sqlmap|nikto|nuclei|masscan|zgrab|wpscan)")
	wlMethodRe  = regexp.MustCompile(`^(GET|POST|HEAD|OPTIONS|-)$`)
	wlHTTPRe    = regexp.MustCompile(` HTTP/[0-9.]+$`)
	wlHaRe      = regexp.MustCompile(`invalid authentication from ([0-9a-fA-F:.]*)`)
	wlVwRe      = regexp.MustCompile(`IP: ([0-9a-fA-F:.]*[0-9a-fA-F])`) // zonder de punt die Vaultwarden achter het adres zet
	wlPortRe    = regexp.MustCompile(`:[0-9]+$`)
	wlQueryRe   = regexp.MustCompile(`[?#;].*$`)
)

// wlRed: tokens en wachtwoorden in een adres weglakken (?token=***)
func wlRed(s string) string {
	var o strings.Builder
	for s != "" {
		loc := wlSecretRe.FindStringIndex(strings.ToLower(s))
		if loc == nil {
			break
		}
		o.WriteString(s[:loc[1]] + "***")
		s = s[loc[1]:]
		if e := wlEndRe.FindStringIndex(s); e != nil {
			s = s[e[0]:]
		} else {
			s = ""
		}
	}
	return o.String() + s
}

// wlJs: zoals js() in awk: \ en " weg, alles buiten gewone tekens als ?, en afkappen
func wlJs(s string, max int) string {
	b := make([]byte, 0, len(s))
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c == '\\' || c == '"':
		case c < ' ' || c > '~':
			b = append(b, '?')
		default:
			b = append(b, c)
		}
	}
	if len(b) > max {
		b = b[:max]
	}
	return string(b)
}

func field(f []string, n int) string { // $n in awk (vanaf 1)
	if n >= 1 && n <= len(f) {
		return f[n-1]
	}
	return ""
}

func word(s string, n int) string { // split(s, a, " "); a[n]
	if w := strings.Fields(s); n >= 1 && n <= len(w) {
		return w[n-1]
	}
	return ""
}

// swagReq: nginx-log van de reverse proxy → verdachte verzoeken als JSON (zonder haakjes), hooguit 100:
// [ip, tijd, methode, pad+query, status, bytes, user-agent, verwijzer, site, dienst]
func swagReq(text string) string {
	var b strings.Builder
	n := 0
	for _, l := range strings.Split(text, "\n") {
		f := strings.Split(l, `"`)
		ip := word(field(f, 1), 1)
		if !wlIPRe.MatchString(ip) {
			continue
		}
		tm := field(f, 1)
		if i := strings.Index(tm, "["); i >= 0 {
			tm = tm[i+1:]
		}
		if i := strings.Index(tm, "]"); i >= 0 {
			tm = tm[:i]
		}
		req := field(f, 2)
		m, uri := req, req
		if i := strings.Index(m, " "); i >= 0 {
			m = m[:i]
		}
		if i := strings.Index(uri, " "); i >= 0 {
			uri = uri[i+1:]
		}
		uri = wlHTTPRe.ReplaceAllString(uri, "")
		st, by := int64(awkNum(word(field(f, 3), 1))), int64(awkNum(word(field(f, 3), 2)))
		ref, ua := field(f, 4), field(f, 6)
		if !wlSuspectRe.MatchString(strings.ToLower(req+" "+ua)) && wlMethodRe.MatchString(m) {
			continue
		}
		if n++; n > 100 {
			break
		}
		if n > 1 {
			b.WriteString(",")
		}
		fmt.Fprintf(&b, `["%s","%s","%s","%s",%d,%d,"%s","%s","%s","%s"]`, ip, edrEsc(tm), edrEsc(cutBytes(m, 20)), edrEsc(cutBytes(wlRed(uri), 512)), st, by,
			edrEsc(cutBytes(ua, 200)), edrEsc(cutBytes(wlRed(ref), 200)), edrEsc(cutBytes(field(f, 8), 120)), edrEsc(cutBytes(field(f, 10), 80)))
	}
	return b.String()
}

// npmNorm: een regel van Nginx Proxy Manager → "combined" met "site" "dienst" erachter
func npmNorm(text string) string {
	var b strings.Builder
	for _, l := range strings.Split(text, "\n") {
		f := strings.Split(l, `"`)
		if len(f) < 6 {
			continue
		}
		a := strings.Fields(f[0])
		w := func(n int) string {
			if n <= len(a) {
				return a[n-1]
			}
			return ""
		}
		tm := strings.NewReplacer("[", "", "]", "").Replace(w(1) + " " + w(2))
		bracket := func(s, key string) (string, bool) {
			i := strings.LastIndex(s, "["+key+" ")
			if i < 0 {
				return "", false
			}
			s = s[i+len(key)+2:]
			if j := strings.Index(s, "]"); j >= 0 {
				s = s[:j]
			}
			return s, true
		}
		ip := f[2]
		if v, ok := bracket(f[2], "Client"); ok {
			ip = v
		} else if j := strings.Index(ip, "]"); j >= 0 {
			ip = ip[:j]
		}
		by := "0"
		if v, ok := bracket(f[2], "Length"); ok {
			by = v
		}
		up := "-"
		if v, ok := bracket(f[2], "Sent-to"); ok && w(4) != "-" {
			up = v
		}
		fmt.Fprintf(&b, "%s - - [%s] \"%s %s HTTP/1.1\" %s %d \"%s\" \"%s\" \"%s\" \"%s\"\n", ip, tm, w(7), f[1], w(5), int64(awkNum(by)), f[5], f[3], w(9), up)
	}
	return b.String()
}

// wlFileNew: de nieuwe regels van een logbestand (inode + positie); de eerste keer alleen vastleggen. De nieuwe positie
// komt in <naam>.pos.new en geldt pas na een aangenomen rapport.
func wlFileNew(name, file string) (string, bool) {
	st, err := os.Stat(file)
	if err != nil {
		return "", false
	}
	ino := st.Sys().(*syscall.Stat_t).Ino
	size := st.Size()
	pos := filepath.Join(weblogDir(), name+".pos")
	prev := strings.Fields(readText(pos))
	if len(prev) == 0 {
		_ = os.WriteFile(pos, []byte(fmt.Sprintf("%d %d\n", ino, size)), 0o600)
		return "", true
	}
	_ = os.WriteFile(pos+".new", []byte(fmt.Sprintf("%d %d\n", ino, size)), 0o600)
	off := int64(0)
	if len(prev) >= 2 {
		off, _ = strconv.ParseInt(prev[1], 10, 64)
	}
	if prev[0] != strconv.FormatUint(ino, 10) || off > size { // geroteerd of nieuw
		off = 0
	}
	fh, err := os.Open(file)
	if err != nil {
		return "", true
	}
	defer fh.Close()
	if _, err := fh.Seek(off, io.SeekStart); err != nil {
		return "", true
	}
	b, _ := io.ReadAll(io.LimitReader(fh, size-off))
	l := strings.Split(strings.TrimSuffix(string(b), "\n"), "\n")
	if len(l) > 20000 {
		l = l[len(l)-20000:]
	}
	return strings.Join(l, "\n"), true
}

// weblogSent: het rapport is aangenomen: de nieuwe posities gelden
func weblogSent() {
	files, _ := filepath.Glob(filepath.Join(weblogDir(), "*.pos.new"))
	for _, f := range files {
		_ = os.Rename(f, strings.TrimSuffix(f, ".new"))
	}
}

func ctrUsable(engine string) bool {
	if !haveCmd(engine) {
		return false
	}
	_, rc := run(8*time.Second, engine, "ps", "-q")
	return rc == 0
}

// wlDocker: de naam van de eerste draaiende container met dat image (docker, anders podman)
func wlDocker(pattern string) string {
	re, err := regexp.Compile(pattern)
	if err != nil {
		return ""
	}
	for _, c := range []string{"docker", "podman"} {
		if !ctrUsable(c) {
			continue
		}
		out, _ := stdoutOf(15*time.Second, c, "ps", "--format", "{{.Names}} {{.Image}}")
		for _, l := range lines(out) {
			if f := strings.Fields(l); len(f) >= 2 && re.MatchString(strings.ToLower(f[1])) {
				return f[0]
			}
		}
	}
	return ""
}

// wlMount: de map op de host bij een pad in de container
func wlMount(ctr, dest string) string {
	for _, c := range []string{"docker", "podman"} {
		if !ctrUsable(c) {
			continue
		}
		out, _ := stdoutOf(15*time.Second, c, "inspect", "-f", `{{range .Mounts}}{{.Destination}} {{.Source}}{{"\n"}}{{end}}`, ctr)
		for _, l := range lines(out) {
			if f := strings.Fields(l); len(f) >= 2 && f[0] == dest {
				return f[1]
			}
		}
	}
	return ""
}

// wlCtrIPs: IP → naam van de draaiende containers: welke dienst zit achter de proxy
func wlCtrIPs() map[string]string {
	m := map[string]string{}
	for _, c := range []string{"docker", "podman"} {
		if !ctrUsable(c) {
			continue
		}
		ids, _ := stdoutOf(15*time.Second, c, "ps", "-q")
		l := lines(ids)
		if len(l) > 200 {
			l = l[:200]
		}
		if len(l) == 0 {
			return m
		}
		out, _ := stdoutOf(15*time.Second, c, append([]string{"inspect", "-f", `{{.Name}}{{range .NetworkSettings.Networks}} {{.IPAddress}}{{end}}`}, l...)...)
		for _, r := range lines(out) {
			f := strings.Fields(r)
			if len(f) == 0 {
				continue
			}
			name := strings.TrimPrefix(f[0], "/")
			for _, ip := range f[1:] {
				m[ip] = name
			}
		}
		return m
	}
	return m
}

// wlIPCount: per IP tellen (de 200 meest voorkomende)
func wlIPCount(ips []string) map[string]int {
	cnt := map[string]int{}
	for _, ip := range ips {
		cnt[ip]++
	}
	keys := make([]string, 0, len(cnt))
	for k := range cnt {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		if cnt[keys[i]] != cnt[keys[j]] {
			return cnt[keys[i]] > cnt[keys[j]]
		}
		return keys[i] > keys[j]
	})
	if len(keys) > 200 {
		keys = keys[:200]
	}
	out := map[string]int{}
	for _, k := range keys {
		if wlIPRe.MatchString(k) {
			out[k] = cnt[k]
		}
	}
	return out
}

// wlSummary: per IP: [aantal, 404, 401/403, 444, scanner, [paden met een foutcode], [[site, dienst, container, aantal, goed] …]]
func wlSummary(text string, cmap map[string]string) map[string]any {
	type ipStat struct {
		n, c404, c40x, c444 int
		flagged             bool
		u                   string
		paths               []string
		seen                map[string]bool
		sites               []string
		sc, so              map[string]int
		su                  map[string]string
	}
	st := map[string]*ipStat{}
	order := []string{}
	for _, l := range strings.Split(text, "\n") {
		f := strings.Split(l, `"`)
		ip := word(field(f, 1), 1)
		if !wlIPRe.MatchString(ip) {
			continue
		}
		code := int(awkNum(word(field(f, 3), 1)))
		p := wlRed(word(field(f, 2), 2))
		ua := strings.ToLower(field(f, 6))
		s := st[ip]
		if s == nil {
			s = &ipStat{seen: map[string]bool{}, sc: map[string]int{}, so: map[string]int{}, su: map[string]string{}}
			st[ip] = s
			order = append(order, ip)
		}
		s.n++
		switch {
		case code == 404:
			s.c404++
			s.flagged = true
		case code == 401 || code == 403:
			s.c40x++
			s.flagged = true
		case code == 444:
			s.c444++
			s.flagged = true
		}
		if m := wlScanRe.FindString(ua); m != "" {
			s.u = m
			s.flagged = true
		}
		if code >= 400 && len(s.paths) < 8 && !s.seen[p] {
			s.seen[p] = true
			s.paths = append(s.paths, wlJs(p, 120))
		}
		if len(f) >= 10 { // met de site en de dienst erachter (hooguit 4 sites per IP)
			h, up := f[7], f[9]
			if h == "" || h == "-" || h == "_" {
				h = "-"
			}
			if code == 444 || up == "" {
				up = "-"
			}
			if _, known := s.sc[h]; !known {
				if len(s.sites) >= 4 {
					continue
				}
				s.sites = append(s.sites, h)
				s.sc[h] = 0
			}
			s.sc[h]++
			if code >= 200 && code < 400 {
				s.so[h]++
			}
			if up != "-" {
				s.su[h] = up
			}
		}
	}
	out := map[string]any{}
	k := 0
	for _, ip := range order {
		s := st[ip]
		if !s.flagged {
			continue
		}
		if k++; k > 300 {
			break
		}
		paths := s.paths
		if paths == nil {
			paths = []string{}
		}
		row := []any{s.n, s.c404, s.c40x, s.c444, s.u, paths}
		if len(s.sites) > 0 {
			sites := [][]any{}
			for _, h := range s.sites {
				up := s.su[h]
				sites = append(sites, []any{wlJs(h, 120), wlJs(up, 120), wlJs(cmap[wlPortRe.ReplaceAllString(up, "")], 120), s.sc[h], s.so[h]})
			}
			row = append(row, sites)
		}
		out[ip] = row
	}
	return out
}

// wlStats: tellingen voor de pagina Reverse proxy (geen logregels): per site het aantal verzoeken per statuscode en
// slechte bots, de 40 meest getroffen paden (zonder querystring) en het aantal nieuwe unieke bezoekers vandaag
func wlStats(text, uniqFile string) map[string]any {
	seen := map[string]bool{}
	for _, l := range lines(readText(uniqFile)) {
		seen[l] = true
	}
	ns := len(seen)
	type site struct{ t, s2, s3, s4, s403, s404, s429, s444, s5, b403 int }
	type pstat struct {
		h, p               string
		n, blk, n403, nbot int
	}
	sites := map[string]*site{}
	paths := map[string]*pstat{}
	pathOrder := []string{}
	nh, nu := 0, 0
	var newIPs strings.Builder
	for _, l := range strings.Split(text, "\n") {
		f := strings.Split(l, `"`)
		ip := word(field(f, 1), 1)
		if !wlIPRe.MatchString(ip) {
			continue
		}
		code := int(awkNum(word(field(f, 3), 1)))
		p := wlQueryRe.ReplaceAllString(word(field(f, 2), 2), "")
		if p == "" {
			p = "/"
		}
		ua := strings.ToLower(field(f, 6))
		h := "-"
		if len(f) >= 10 {
			h = f[7]
		}
		if h == "" || h == "_" {
			h = "-"
		}
		if sites[h] == nil {
			if nh >= 60 {
				h = "(overig)"
			} else {
				nh++
			}
			if sites[h] == nil {
				sites[h] = &site{}
			}
		}
		s := sites[h]
		s.t++
		switch {
		case code >= 200 && code < 300:
			s.s2++
		case code < 400:
			s.s3++
		case code == 403:
			s.s403++
		case code == 404:
			s.s404++
		case code == 429:
			s.s429++
		case code == 444:
			s.s444++
		case code >= 500:
			s.s5++
		default:
			s.s4++
		}
		bot := wlBotRe.MatchString(ua)
		if bot && code == 403 {
			s.b403++
		}
		if bot || code == 400 || code == 401 || code == 403 || code == 404 || code == 405 || code == 429 || code == 444 {
			key := h + "\x1c" + p
			ps := paths[key]
			if ps == nil {
				ps = &pstat{h: h, p: p}
				paths[key] = ps
				pathOrder = append(pathOrder, key)
			}
			ps.n++
			if code == 403 || code == 429 || code == 444 {
				ps.blk++
			}
			if code == 403 {
				ps.n403++
			}
			if bot && code == 403 {
				ps.nbot++
			}
		}
		if !seen[ip] && ns < 200000 {
			seen[ip] = true
			ns++
			nu++
			newIPs.WriteString(ip + "\n")
		}
	}
	if newIPs.Len() > 0 {
		if fh, err := os.OpenFile(uniqFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
			_, _ = fh.WriteString(newIPs.String())
			fh.Close()
		}
	}
	t := map[string][]int{}
	for h, s := range sites {
		t[wlJs(h, 100)] = []int{s.t, s.s2, s.s3, s.s4, s.s403, s.s404, s.s429, s.s444, s.s5, s.b403}
	}
	sort.SliceStable(pathOrder, func(i, j int) bool { return paths[pathOrder[i]].n > paths[pathOrder[j]].n })
	if len(pathOrder) > 40 {
		pathOrder = pathOrder[:40]
	}
	top := [][]any{}
	for _, k := range pathOrder {
		ps := paths[k]
		top = append(top, []any{wlJs(ps.h, 100), wlJs(ps.p, 100), ps.n, ps.blk, ps.n403, ps.nbot})
	}
	return map[string]any{"t": t, "p": top, "u": nu}
}

// collectWeblogLinux: het onderdeel "weblog" van het rapport
func collectWeblogLinux() map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !hasLinePrefix(tests, "0\tweblog\t") {
		return nil
	}
	dir := weblogDir()
	out := map[string]any{}
	for _, l := range strings.Split(tests, "\n") {
		g := tabRead(l, 4)
		if g[0] != "0" || g[1] != "weblog" {
			continue
		}
		src, arg := g[2], g[3]
		e := map[string]any{}
		path, errText := "", ""
		ips := map[string]any{}
		switch src {
		case "swag":
			path = arg
			kind, ctr, full, text := "nginx", "", 0, ""
			if path == "" || path == "auto" {
				// zelf zoeken (één keer per dag): "soort container map"
				find := filepath.Join(dir, "proxy.find")
				if st, err := os.Stat(find); err == nil && time.Since(st.ModTime()) < 24*time.Hour {
					f := strings.Fields(firstLine(find))
					kind, ctr, path = "", "", ""
					if len(f) >= 1 {
						kind = f[0]
					}
					if len(f) >= 2 {
						ctr = f[1]
					}
					if len(f) >= 3 {
						path = strings.Join(f[2:], " ")
					}
				} else {
					kind, ctr, path = "", "", ""
					if c := wlDocker("swag|letsencrypt"); c != "" {
						if m := wlMount(c, "/config"); m != "" && isDir(m+"/log/nginx") {
							kind, ctr, path = "swag", c, m+"/log/nginx"
						}
					}
					if path == "" {
						if c := wlDocker("nginx-proxy-manager|jc21/"); c != "" {
							if m := wlMount(c, "/data"); m != "" && isDir(m+"/logs") {
								kind, ctr, path = "npm", c, m+"/logs"
							}
						}
					}
					if path == "" {
						cands := []string{"/opt/swag/config/log/nginx", "/srv/swag/config/log/nginx", "/docker/swag/config/log/nginx"}
						for _, pat := range []string{"/home/*/swag/config/log/nginx", "/home/*/docker/swag/config/log/nginx"} {
							m, _ := filepath.Glob(pat)
							cands = append(cands, m...)
						}
						for _, c := range cands {
							if isFile(c + "/access.log") {
								kind, path = "swag", c
								break
							}
						}
					}
					if path == "" && isFile("/var/log/nginx/access.log") {
						kind, path = "nginx", "/var/log/nginx"
					}
					dash := func(s string) string {
						if s == "" {
							return "-"
						}
						return s
					}
					_ = os.WriteFile(find, []byte(dash(kind)+" "+dash(ctr)+" "+path+"\n"), 0o600)
				}
				if kind == "-" {
					kind = ""
				}
				if ctr == "-" {
					ctr = ""
				}
				if path != "" && isDir(path) {
					switch {
					case kind == "npm":
						path += "/proxy-host-*_access.log"
					case isFile(path + "/rzsense.log"):
						path += "/rzsense.log"
					default:
						path += "/access.log"
					}
				}
			} else if strings.Contains(path, "swag") {
				kind = "swag"
			} else if strings.Contains(path, "proxy-host-") {
				kind = "npm"
			}
			switch {
			case kind == "npm" && strings.Contains(path, "*"):
				if files, _ := filepath.Glob(path); len(files) > 0 {
					full = 1
					for _, f := range files {
						if !isFile(f) || !readable(f) {
							continue
						}
						t, _ := wlFileNew("npm-"+strings.TrimSuffix(filepath.Base(f), ".log"), f)
						text += npmNorm(t) + "\n"
					}
				} else {
					errText = "niet gevonden"
				}
			case path != "" && readable(path):
				if strings.HasSuffix(path, "/rzsense.log") {
					full = 1
					text, _ = wlFileNew("swagx", path)
				} else {
					text, _ = wlFileNew("swag", path)
				}
			case path != "":
				errText = "niet leesbaar: " + path
			default:
				errText = "niet gevonden"
			}
			if errText == "" {
				cmap := map[string]string{}
				if full == 1 && text != "" {
					cmap = wlCtrIPs()
				}
				if req := swagReq(text); req != "" {
					e["req"] = json.RawMessage("[" + req + "]")
				}
				for ip, v := range wlSummary(text, cmap) {
					ips[ip] = v
				}
				if text != "" {
					today := time.Now().Format("20060102")
					old, _ := filepath.Glob(filepath.Join(dir, "uniq-*"))
					for _, f := range old {
						if filepath.Base(f) != "uniq-"+today {
							_ = os.Remove(f)
						}
					}
					e["stat"] = wlStats(text, filepath.Join(dir, "uniq-"+today))
				}
			}
			if kind != "" {
				e["kind"] = kind
			}
			if ctr != "" {
				e["ctr"] = cleanText(ctr)
			}
			e["full"] = full
		case "ha":
			path = arg
			if path == "" || path == "auto" {
				path = ""
				if c := wlDocker("home-assistant|homeassistant"); c != "" {
					if m := wlMount(c, "/config"); m != "" && isFile(m+"/home-assistant.log") {
						path = m + "/home-assistant.log"
					}
				}
			}
			if path != "" && readable(path) {
				text, _ := wlFileNew("ha", path)
				list := []string{}
				for _, l := range strings.Split(text, "\n") { // zoals grep -i en dan sed: het laatste adres op de regel
					if !strings.Contains(strings.ToLower(l), "invalid authentication from") {
						continue
					}
					if m := wlHaRe.FindAllStringSubmatch(l, -1); len(m) > 0 {
						list = append(list, m[len(m)-1][1])
					}
				}
				for ip, n := range wlIPCount(list) {
					ips[ip] = n
				}
			} else {
				errText, path = "niet gevonden", ""
			}
		case "vw":
			text := ""
			if arg != "" && arg != "auto" && isFile(arg) {
				path = arg
				text, _ = wlFileNew("vw", arg)
			} else {
				c := arg
				if c == "" || c == "auto" {
					c = wlDocker("vaultwarden")
				}
				if c != "" {
					path = "container:" + c
					sinceFile := filepath.Join(dir, "vw.since")
					since := strings.TrimSpace(readText(sinceFile))
					_ = os.WriteFile(sinceFile, []byte(fmt.Sprintf("%d\n", time.Now().Unix())), 0o600)
					if since != "" {
						for _, d := range []string{"docker", "podman"} {
							if haveCmd(d) {
								o, rc := run(60*time.Second, d, "logs", "--since", since, c)
								if rc == 0 {
									l := strings.Split(o, "\n")
									if len(l) > 5000 {
										l = l[len(l)-5000:]
									}
									text = strings.Join(l, "\n")
									break
								}
							}
						}
					}
				} else {
					errText = "niet gevonden"
				}
			}
			list := []string{}
			for _, l := range strings.Split(text, "\n") {
				if strings.Contains(strings.ToLower(l), "username or password is incorrect") {
					if m := wlVwRe.FindAllStringSubmatch(l, -1); len(m) > 0 {
						list = append(list, m[len(m)-1][1])
					}
				}
			}
			for ip, n := range wlIPCount(list) {
				ips[ip] = n
			}
		default:
			continue
		}
		e["path"] = cleanText(path)
		if errText != "" {
			e["err"] = cleanText(errText)
		}
		e["ips"] = ips
		out[src] = e
	}
	if len(out) == 0 {
		return nil
	}
	return out
}

func isDir(p string) bool  { st, err := os.Stat(p); return err == nil && st.IsDir() }
func isFile(p string) bool { st, err := os.Stat(p); return err == nil && st.Mode().IsRegular() }
