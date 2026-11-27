//go:build linux

package main

// Reverse proxy beschermen (0.34.0), zoals de bash-agent (agent/src/69-proxy.sh, het eerste deel). Alleen op een server
// die in RzSense de rol "reverse proxy" heeft (regel "0 <tab> proxy <tab> on|off"). De agent herkent de proxy (SWAG of
// Nginx Proxy Manager, in Podman of Docker), leest de sites uit en voert het beleid per site door.
// Veilig doorvoeren, precies zoals de bash-agent:
//   - alleen eigen bestanden (rzsense-http.conf en de map rzsense/), plus één gemarkeerde regel (# rzsense-hook) in de proxy;
//     een kopie van alles wat verandert gaat eerst naar de eigen map;
//   - eerst "nginx -t" in de container; pas als dat goed is herladen; daarna kijken of de sites nog antwoorden. Antwoordt
//     een site die eerst werkte daarna niet meer, dan gaat de vorige versie terug;
//   - "off" (of de rol uit in RzSense) haalt alles weer weg. PROXY_GUARD=0 zet het op de server uit.
// De sjablonen staan in proxytpl_linux.go en zijn letterlijk die van de bash-agent. De stand (wat is doorgevoerd) deelt
// de Go-agent met de bash-agent: bij overnemen wordt er niets opnieuw doorgevoerd en niets herladen.

import (
	"crypto/md5"
	"crypto/sha1"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

var (
	pxCtrRe      = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`)
	pxNameRe     = regexp.MustCompile(`^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$`)
	pxServerRe   = regexp.MustCompile(`^[[:space:]]*server_name[[:space:]]+([^;]*);`)
	pxUpAppRe    = regexp.MustCompile(`^[[:space:]]*set[[:space:]]+\$upstream_app[[:space:]]+([^;]*);`)
	pxUpPortRe   = regexp.MustCompile(`^[[:space:]]*set[[:space:]]+\$upstream_port[[:space:]]+([^;]*);`)
	pxNpmSrvRe   = regexp.MustCompile(`^[[:space:]]*set[[:space:]]+\$server[[:space:]]+"?([^";]*)"?;`)
	pxNpmPortRe  = regexp.MustCompile(`^[[:space:]]*set[[:space:]]+\$port[[:space:]]+([^;]*);`)
	pxNetRe      = regexp.MustCompile(`^[0-9a-fA-F:.]+(/[0-9]{1,3})?$`)
	pxModeRe     = regexp.MustCompile(`^(off|paths|site)$`)
	pxRateRe     = regexp.MustCompile(`^(off|normal|strict|app)$`)
	pxPath100Re  = regexp.MustCompile(`^/[A-Za-z0-9._~/-]{0,100}$`)
	pxPath1Re    = regexp.MustCompile(`^/[A-Za-z0-9._~/-]{1,100}$`)
	pxPath120Re  = regexp.MustCompile(`^/[A-Za-z0-9._~/-]{1,120}$`)
	pxTagRe      = regexp.MustCompile(`^[0-9a-f]{32}$`)
	pxPortRe     = regexp.MustCompile(`^[0-9]{1,5}$`)
	pxURLCleanRe = regexp.MustCompile(`[^A-Za-z0-9.-]`)
	pxEscRe      = regexp.MustCompile(`[.~]`)
	pxHexRe      = regexp.MustCompile(`[^a-f0-9]`)
)

const pxMark = "# rzsense-hook"

func proxyStateDir() string { return sharedDir(".") }

type pxSite struct {
	name, up, file string
	names          []string
}

type proxyGuard struct {
	conf              map[string]string
	tests, state      string
	kind, eng, ctr    string
	root, cpath, url  string
	port              string
	sites             []pxSite
	alias             map[string][]string
	httpFile, srvFile string
	hooks             [][2]string // bestand, soort (http of server)
	st, msg           string
	hook              int
	nSites            int
	sleepAfterReload  time.Duration
}

// pxCtr: een commando van de container-engine met tijdslimiet (alleen als hij antwoordt)
func pxCtr(sec int, engine string, args ...string) (string, int) {
	if !ctrUsable(engine) {
		return "", 125
	}
	return stdoutOf(time.Duration(sec)*time.Second, engine, args...)
}

func pxFind(pattern string) (string, string) {
	re := regexp.MustCompile(pattern)
	for _, e := range []string{"podman", "docker"} {
		if !haveCmd(e) {
			continue
		}
		out, _ := pxCtr(15, e, "ps", "--format", "{{.Names}} {{.Image}}")
		for _, l := range lines(out) {
			if f := strings.Fields(l); len(f) >= 2 && re.MatchString(strings.ToLower(f[1])) {
				return e, f[0]
			}
		}
	}
	return "", ""
}

func pxMount(engine, ctr, dest string) string {
	out, _ := pxCtr(15, engine, "inspect", "-f", `{{range .Mounts}}{{.Destination}} {{.Source}}{{"\n"}}{{end}}`, ctr)
	for _, l := range lines(out) {
		if f := strings.Fields(l); len(f) >= 2 && f[0] == dest {
			return f[1]
		}
	}
	return ""
}

func pxEnv(engine, ctr, name string) string {
	out, _ := pxCtr(15, engine, "inspect", "-f", `{{range .Config.Env}}{{println .}}{{end}}`, ctr)
	for _, l := range lines(out) {
		if strings.HasPrefix(l, name+"=") {
			return strings.TrimPrefix(l, name+"=")
		}
	}
	return ""
}

func firstSub(re *regexp.Regexp, text string) string {
	for _, l := range strings.Split(text, "\n") {
		if m := re.FindStringSubmatch(l); m != nil {
			return m[1]
		}
	}
	return ""
}

// detect: welke proxy, waar zijn configuratie staat, en welke sites er zijn
func (g *proxyGuard) detect() {
	g.kind, g.st = "none", "pending"
	g.port = "443"
	if pxPortRe.MatchString(g.conf["PROXY_HTTPS_PORT"]) {
		g.port = g.conf["PROXY_HTTPS_PORT"]
	}
	if c := g.conf["PROXY_CTR"]; c != "" && pxCtrRe.MatchString(c) {
		for _, e := range []string{"podman", "docker"} {
			if haveCmd(e) {
				if _, rc := pxCtr(15, e, "inspect", c); rc == 0 {
					g.eng, g.ctr = e, c
					break
				}
			}
		}
		if g.ctr != "" {
			if pxMount(g.eng, g.ctr, "/config") != "" {
				g.kind = "swag"
			} else {
				g.kind = "npm"
			}
		}
	} else {
		for _, k := range [][2]string{{"swag", "swag|letsencrypt"}, {"npm", "nginx-proxy-manager|jc21/"}, {"traefik", "traefik"}, {"caddy", "caddy"}} {
			if e, c := pxFind(k[1]); c != "" {
				g.kind, g.eng, g.ctr = k[0], e, c
				break
			}
		}
	}
	switch g.kind {
	case "swag":
		if m := pxMount(g.eng, g.ctr, "/config"); m != "" {
			g.root = m + "/nginx"
		}
		g.cpath = g.conf["PROXY_CPATH"]
		if g.cpath == "" {
			g.cpath = "/config/nginx"
		}
	case "npm":
		if m := pxMount(g.eng, g.ctr, "/data"); m != "" {
			g.root = m
		}
		g.cpath = g.conf["PROXY_CPATH"]
		if g.cpath == "" {
			g.cpath = "/data"
		}
	}
	switch {
	case g.kind == "none":
		g.st, g.msg = "unsupported", "geen reverse proxy gevonden (SWAG of Nginx Proxy Manager in Podman of Docker)"
	case g.kind == "traefik" || g.kind == "caddy":
		g.st, g.msg = "unsupported", g.kind+" wordt herkend maar nog niet ondersteund"
	case g.root == "" || !isDir(g.root):
		g.st, g.msg = "error", "de configmap van "+g.ctr+" is niet gevonden op deze server"
	}
	g.alias = map[string][]string{}
	if g.root != "" && isDir(g.root) {
		if g.kind == "swag" {
			g.url = strings.ToLower(pxURLCleanRe.ReplaceAllString(pxEnv(g.eng, g.ctr, "URL"), ""))
		}
		var files []string
		if g.kind == "swag" {
			a, _ := filepath.Glob(g.root + "/proxy-confs/*.conf")
			b, _ := filepath.Glob(g.root + "/site-confs/*.conf")
			files = append(a, b...)
		} else {
			files, _ = filepath.Glob(g.root + "/nginx/proxy_host/*.conf")
		}
		for _, f := range files {
			base := filepath.Base(f)
			if !isFile(f) || strings.HasSuffix(base, ".sample") || strings.HasPrefix(base, "rzsense-") {
				continue
			}
			text := readText(f)
			names := []string{}
			for _, l := range strings.Split(text, "\n") {
				if m := pxServerRe.FindStringSubmatch(l); m != nil {
					for _, n := range strings.Split(strings.ToLower(m[1]), " ") {
						if pxNameRe.MatchString(n) {
							names = append(names, n)
						}
					}
				}
			}
			names = sortedUnique(names)
			if len(names) == 0 {
				continue
			}
			if len(names) > 10 {
				names = names[:10]
			}
			up := ""
			if g.kind == "swag" {
				up = firstSub(pxUpAppRe, text) + ":" + firstSub(pxUpPortRe, text)
			} else {
				up = firstSub(pxNpmSrvRe, text) + ":" + firstSub(pxNpmPortRe, text)
			}
			if up == ":" {
				up = ""
			}
			g.alias[names[0]] = names
			g.sites = append(g.sites, pxSite{name: names[0], names: names, up: cutBytes(up, 100), file: base})
			if len(g.sites) >= 200 {
				break
			}
		}
	}
	if g.kind == "swag" {
		g.httpFile, g.srvFile = g.root+"/site-confs/rzsense-http.conf", g.root+"/rzsense/server.conf"
		g.hooks = [][2]string{{g.root + "/ssl.conf", "server"}}
	} else {
		g.httpFile, g.srvFile = g.root+"/rzsense/http.conf", g.root+"/rzsense/server.conf"
		g.hooks = [][2]string{{g.root + "/nginx/custom/http.conf", "http"}, {g.root + "/nginx/custom/server_proxy.conf", "server"}}
	}
	if g.hooked() {
		g.hook = 1
	}
}

func (g *proxyGuard) hookLine(kind string) string {
	if g.kind != "swag" && kind == "http" {
		return "include " + g.cpath + "/rzsense/http.conf; " + pxMark
	}
	return "include " + g.cpath + "/rzsense/server.conf; " + pxMark
}

func (g *proxyGuard) hooked() bool {
	if g.root == "" {
		return false
	}
	for _, h := range g.hooks {
		if !strings.Contains(readText(h[0]), pxMark) {
			return false
		}
	}
	return true
}

func (g *proxyGuard) exec(args ...string) (string, int) {
	if !ctrUsable(g.eng) {
		return "", 125
	}
	return run(30*time.Second, g.eng, append([]string{"exec", g.ctr}, args...)...)
}

// codes: per site met een vaste naam de HTTP-code via 127.0.0.1 (hooguit 15 sites)
func (g *proxyGuard) codes() [][2]string {
	out := [][2]string{}
	for i, s := range g.sites {
		if i >= 15 {
			break
		}
		h := s.name
		if strings.HasSuffix(h, ".*") {
			if g.url == "" {
				continue
			}
			h = strings.TrimSuffix(h, ".*") + "." + g.url
		}
		if strings.HasPrefix(h, "*.") {
			continue
		}
		code, _ := stdoutOf(10*time.Second, "curl", "-sk", "--noproxy", "*", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "5", "--resolve", h+":"+g.port+":127.0.0.1", "https://"+h+":"+g.port+"/")
		if code = strings.TrimSpace(code); code == "" {
			code = "000"
		}
		out = append(out, [2]string{s.name, code})
	}
	return out
}

func pxBakName(dir, f string) string {
	h := md5.Sum([]byte(f))
	return filepath.Join(dir, hex.EncodeToString(h[:])[:16])
}

// backup: een kopie van alles wat verandert (bestaat het niet, dan een leeg merkteken)
func (g *proxyGuard) backup() {
	b := filepath.Join(g.state, "proxy.bak")
	_ = os.RemoveAll(b)
	_ = os.MkdirAll(b, 0o700)
	list := ""
	files := []string{g.httpFile, g.srvFile}
	for _, h := range g.hooks {
		files = append(files, h[0])
	}
	for _, f := range files {
		name := pxBakName(b, f)
		if isFile(f) {
			if data, err := os.ReadFile(f); err == nil {
				_ = os.WriteFile(name, data, 0o600)
			}
		} else {
			_ = os.WriteFile(name+".absent", nil, 0o600)
		}
		list += f + "\n"
	}
	_ = os.WriteFile(filepath.Join(b, "list"), []byte(list), 0o600)
}

func (g *proxyGuard) restore() bool {
	b := filepath.Join(g.state, "proxy.bak")
	list := lines(readText(filepath.Join(b, "list")))
	if len(list) == 0 {
		return false
	}
	for _, f := range list {
		name := pxBakName(b, f)
		if isFile(name + ".absent") {
			_ = os.Remove(f)
		} else if data, err := os.ReadFile(name); err == nil {
			pxWrite(f, string(data)) // eigenaar en rechten van het bestaande bestand blijven
		}
	}
	return true
}

// pxWrite: schrijven zonder de eigenaar van een bestaand bestand te veranderen
func pxWrite(file, content string) {
	_ = os.MkdirAll(filepath.Dir(file), 0o755)
	if fh, err := os.OpenFile(file, os.O_WRONLY|os.O_TRUNC|os.O_CREATE, 0o644); err == nil {
		_, _ = fh.WriteString(content)
		fh.Close()
	}
}

// unhook: de gemarkeerde regels weghalen (de rest van het bestand blijft precies zoals het was)
func (g *proxyGuard) unhook() {
	for _, h := range g.hooks {
		text := readText(h[0])
		if !isFile(h[0]) || !strings.Contains(text, pxMark) {
			continue
		}
		keep := []string{}
		for _, l := range strings.Split(strings.TrimRight(text, "\n"), "\n") {
			if !strings.Contains(l, pxMark) {
				keep = append(keep, l)
			}
		}
		pxWrite(h[0], strings.TrimRight(strings.Join(keep, "\n"), "\n")+"\n")
	}
}

func tailJoin(out string, n, max int) string {
	l := strings.Split(strings.TrimRight(out, "\n"), "\n")
	if len(l) > n {
		l = l[len(l)-n:]
	}
	return cutRunes(strings.Join(l, " ")+" ", max)
}

// testReload: true = goed en herladen; anders staat de fout in g.msg
func (g *proxyGuard) testReload() bool {
	if out, rc := g.exec("nginx", "-t"); rc != 0 {
		g.msg = "nginx -t: " + tailJoin(out, 3, 300)
		return false
	}
	if out, rc := g.exec("nginx", "-s", "reload"); rc != 0 {
		g.msg = "herladen mislukt: " + tailJoin(out, 2, 200)
		return false
	}
	time.Sleep(g.sleepAfterReload)
	return true
}

func pxEsc(p string) string { return pxEscRe.ReplaceAllString(p, `\$0`) }

func (g *proxyGuard) linesOf(kind string, max int) []string {
	out := []string{}
	for _, l := range strings.Split(g.tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 2 && f[0] == "0" && f[1] == kind && len(out) < max {
			out = append(out, l)
		}
	}
	return out
}

// policy: de twee bestanden met het beleid (alles wat binnenkomt opnieuw gecontroleerd)
func (g *proxyGuard) policy() (string, string, int) {
	var geo, hosts, adm, open, tag, nood, rzs, trust, dev, bots, rate, blk strings.Builder
	id := 0
	seen := map[string]bool{}
	sid := map[string]string{}
	if l := g.linesOf("proxylan", 1); len(l) > 0 {
		if f := strings.Split(l[0], "\t"); len(f) >= 3 {
			for _, n := range strings.Fields(f[2]) {
				if pxNetRe.MatchString(n) {
					geo.WriteString("    " + n + " 1;\n")
				}
			}
		}
	}
	for _, l := range g.linesOf("proxysite", 200) {
		f := tabRead(l, 7)
		name, mode, paths, rt, bt := f[2], f[3], f[4], f[5], f[6]
		if !pxNameRe.MatchString(name) || !pxModeRe.MatchString(mode) || !pxRateRe.MatchString(rt) || (bt != "0" && bt != "1") {
			continue
		}
		id++
		s := "s" + strconv.Itoa(id)
		sid[name] = s
		names := g.alias[name]
		if len(names) == 0 {
			names = []string{name}
		}
		for _, a := range names { // elke naam maar één keer (anders weigert nginx de map)
			if !seen[a] {
				seen[a] = true
				hosts.WriteString("    " + a + " " + s + ";\n")
			}
		}
		if mode == "site" {
			adm.WriteString("    \"~^" + s + ":\" 1;\n")
		} else if mode == "paths" && paths != "-" {
			for _, p := range strings.Split(paths, ",") {
				if pxPath100Re.MatchString(p) && !strings.Contains(p, "..") {
					adm.WriteString("    \"~*^" + s + ":" + pxEsc(strings.TrimSuffix(p, "/")) + "(/|$)\" 1;\n")
				}
			}
		}
		if bt == "1" {
			bots.WriteString("    " + s + " 1;\n")
		}
		if rt != "off" {
			rate.WriteString("    " + s + " " + rt + ";\n")
		}
	}
	for _, l := range g.linesOf("proxytags", 10) {
		f := tabRead(l, 4)
		s := sid[f[2]]
		if s == "" {
			continue
		}
		for _, t := range strings.Split(f[3], ",") {
			if pxTagRe.MatchString(t) {
				tag.WriteString("    \"" + s + ":" + t + "\" 1;\n")
			}
		}
		nood.WriteString("    \"~^" + s + `:/(index\.php)?:[A-Za-z0-9._@-]{1,64}:$" 1;` + "\n")
		rzs.WriteString("    " + s + " 1;\n")
		nood.WriteString("    \"~^" + s + `:/(index\.php)?::[0-9a-f]{48}$" 1;` + "\n")
	}
	if l := g.linesOf("proxytrust", 1); len(l) > 0 {
		if f := strings.Split(l[0], "\t"); len(f) >= 3 {
			for _, t := range strings.Fields(strings.ReplaceAll(f[2], ",", " ")) {
				if pxTagRe.MatchString(t) {
					trust.WriteString("    " + t + " 1;\n")
				}
			}
		}
	}
	for _, l := range g.linesOf("proxydev", 200) {
		if s := sid[tabRead(l, 3)[2]]; s != "" {
			dev.WriteString("    " + s + " 1;\n")
		}
	}
	for _, l := range g.linesOf("proxypass", 10) {
		f := tabRead(l, 4)
		s := sid[f[2]]
		if s == "" {
			continue
		}
		for _, p := range strings.Split(f[3], ",") {
			if !pxPath1Re.MatchString(p) || strings.Contains(p, "..") {
				continue
			}
			if strings.HasSuffix(p, "/") {
				open.WriteString("    \"~^" + s + ":" + pxEsc(p) + "\" 0;\n")
			} else {
				open.WriteString("    \"~^" + s + ":" + pxEsc(p) + "$\" 0;\n")
			}
		}
	}
	for _, l := range g.linesOf("proxyblock", 300) {
		f := tabRead(l, 4)
		pre := ""
		if f[2] == "*" {
			pre = "[^:]*"
		} else if pre = sid[f[2]]; pre == "" {
			continue
		}
		for _, p := range strings.Split(f[3], ",") {
			if pxPath120Re.MatchString(p) && !strings.Contains(p, "..") {
				blk.WriteString("    \"~*^" + pre + ":" + pxEsc(p) + "\" 1;\n")
			}
		}
	}
	http := strings.NewReplacer("${px_geo}", geo.String(), "${px_hosts}", hosts.String(), "${px_open}", open.String(), "${px_adm}", adm.String(), "${px_blk}", blk.String(),
		"${px_tag}", tag.String(), "${px_nood}", nood.String(), "${px_trust}", trust.String(), "${px_dev}", dev.String(), "${px_rzs}", rzs.String(), "${px_bots}", bots.String(),
		"${px_rate}", rate.String()).Replace(proxyTplHTTP)
	return http, proxyTplSrv, id
}

func (g *proxyGuard) applyHash() string {
	var b strings.Builder
	for _, l := range strings.Split(g.tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) >= 2 && f[0] == "0" {
			switch f[1] {
			case "proxylan", "proxysite", "proxyblock", "proxytags", "proxypass", "proxytrust", "proxydev":
				b.WriteString(l + "\n")
			}
		}
	}
	h := sha1.Sum([]byte(b.String()))
	return hex.EncodeToString(h[:])[:12]
}

// run: doorvoeren, weghalen of niets doen; geeft het onderdeel "proxy" van het rapport terug
func (g *proxyGuard) run() map[string]any {
	mode := ""
	for _, l := range strings.Split(g.tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "proxy" {
			mode = f[2]
			break
		}
	}
	g.detect()
	st := func(n string) string { return filepath.Join(g.state, n) }
	hash := g.applyHash()
	applied := strings.TrimSpace(readText(st("proxy.applied")))
	tplOK := strings.TrimSpace(readText(st("proxy.tpl"))) == proxyTplVersion
	switch {
	case g.st == "pending" && mode != "on": // uit: alles weghalen
		if g.hook == 1 || isFile(g.httpFile) || isFile(g.srvFile) {
			g.backup()
			g.unhook()
			_ = os.Remove(g.httpFile)
			_ = os.Remove(g.srvFile)
			_ = os.Remove(g.root + "/rzsense") // alleen als de map leeg is
			if g.testReload() {
				g.hook, g.st, g.msg = 0, "off", "bescherming weggehaald"
				_ = os.Remove(st("proxy.applied"))
				actLog("proxy", 1, "Reverse proxy: bescherming uit", g.kind+" "+g.ctr)
			} else {
				g.restore()
				g.exec("nginx", "-s", "reload")
				g.st = "error"
				actLog("proxy", 0, "Reverse proxy: uitzetten mislukt", g.msg)
			}
		} else {
			g.st, g.msg = "off", ""
		}
	case g.st == "pending": // aan: doorvoeren als het beleid veranderde of iets ontbreekt
		failed := strings.Fields(readText(st("proxy.failed")))
		failedRecently := len(failed) >= 2 && failed[0] == hash && time.Now().Unix()-int64(atoiDef(failed[len(failed)-1], 0)) < 3600
		switch {
		case applied == hash && tplOK && g.hook == 1 && isFile(g.httpFile) && isFile(g.srvFile):
			g.st, g.msg = "ok", "doorgevoerd"
		case failedRecently: // een mislukte versie niet elke minuut opnieuw
			g.st, g.msg = "error", readText(st("proxy.failmsg"))
		default:
			http, srv, n := g.policy()
			before := g.codes()
			g.backup()
			pxWrite(g.httpFile, http)
			pxWrite(g.srvFile, srv)
			for _, h := range g.hooks {
				text := readText(h[0])
				if strings.Contains(text, pxMark) {
					continue
				}
				if isFile(h[0]) {
					pxWrite(h[0], strings.TrimRight(text, "\n")+"\n"+g.hookLine(h[1])+"\n")
				} else {
					pxWrite(h[0], g.hookLine(h[1])+"\n")
				}
			}
			if g.testReload() {
				after := map[string]string{}
				for _, c := range g.codes() {
					if _, ok := after[c[0]]; !ok {
						after[c[0]] = c[1]
					}
				}
				alive := func(code string) bool { return code != "" && code[0] >= '1' && code[0] <= '4' }
				broken := ""
				for _, c := range before { // antwoordt een site die eerst werkte nu niet meer? Dan terug.
					a := after[c[0]]
					if a == "" {
						a = "000"
					}
					if alive(c[1]) && !alive(a) {
						broken += c[0] + " (" + c[1] + " → " + a + ") "
					}
				}
				if broken != "" {
					g.restore()
					g.exec("nginx", "-s", "reload")
					g.st, g.msg = "error", "teruggezet: deze sites antwoordden daarna niet meer: "+broken
				} else {
					g.st, g.msg, g.hook = "ok", fmt.Sprintf("doorgevoerd (%d sites)", n), 1
					_ = os.WriteFile(st("proxy.applied"), []byte(hash+"\n"), 0o600)
					_ = os.WriteFile(st("proxy.tpl"), []byte(proxyTplVersion+"\n"), 0o600)
					_ = os.Remove(st("proxy.failed"))
					_ = os.Remove(st("proxy.failmsg"))
					actLog("proxy", 1, "Reverse proxy: beleid doorgevoerd", fmt.Sprintf("%s %s, %d sites", g.kind, g.ctr, n))
				}
			} else {
				g.restore()
				g.st = "error"
			}
			if g.st == "error" {
				_ = os.WriteFile(st("proxy.failed"), []byte(fmt.Sprintf("%s %d\n", hash, time.Now().Unix())), 0o600)
				_ = os.WriteFile(st("proxy.failmsg"), []byte(g.msg), 0o600)
				actLog("proxy", 0, "Reverse proxy: doorvoeren mislukt", g.msg)
				g.hook = 0
				if g.hooked() {
					g.hook = 1
				}
			}
		}
	}
	sites := []map[string]any{}
	for _, s := range g.sites {
		sites = append(sites, map[string]any{"name": cleanText(s.name), "names": s.names, "up": cleanText(s.up), "file": cleanText(s.file)})
	}
	return map[string]any{"kind": g.kind, "engine": g.eng, "ctr": cleanText(g.ctr), "conf": cleanText(g.root), "hook": g.hook, "state": g.st, "msg": cleanText(g.msg),
		"applied": pxHexRe.ReplaceAllString(readText(st("proxy.applied")), ""), "sites": sites}
}

// collectProxyLinux: het onderdeel "proxy" van het rapport (alleen als root en met de rol reverse proxy in RzSense)
func collectProxyLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !isRoot() || conf["PROXY_GUARD"] == "0" || !hasLinePrefix(tests, "0\tproxy\t") {
		return nil
	}
	g := &proxyGuard{conf: conf, tests: tests, state: proxyStateDir(), sleepAfterReload: 2 * time.Second}
	if os.Getenv("RZSENSE_TEST_FAST") == "1" { // alleen voor de tests: niet 2 seconden wachten na het herladen
		g.sleepAfterReload = 0
	}
	return g.run()
}

// proxyObserve: voor de proef: welke proxy en welke sites de Go-agent ziet, en welk beleid hij zou schrijven (de
// vingerafdruk van de twee bestanden). Hij schrijft niets, test niets en herlaadt niets.
func proxyObserve() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !isRoot() || conf["PROXY_GUARD"] == "0" || !hasLinePrefix(tests, "0\tproxy\t") {
		return nil
	}
	g := &proxyGuard{conf: conf, tests: tests, state: proxyStateDir()}
	g.detect()
	names := []string{}
	for _, s := range g.sites {
		names = append(names, s.name)
	}
	sort.Strings(names)
	out := map[string]any{"kind": g.kind, "ctr": cleanText(g.ctr), "sites": names, "hash": g.applyHash()}
	if g.root != "" && isFile(g.httpFile) && isFile(g.srvFile) { // wat de bash-agent schreef, naast wat de Go-agent zou schrijven
		http, srv, _ := g.policy()
		out["same_http"] = readText(g.httpFile) == http
		out["same_srv"] = readText(g.srvFile) == srv
		out["applied"] = strings.TrimSpace(readText(filepath.Join(g.state, "proxy.applied")))
	}
	return out
}
