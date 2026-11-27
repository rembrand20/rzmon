//go:build linux

package main

// WAF op de reverse proxy (0.35.0), zoals de bash-agent (agent/src/69-proxy.sh, het tweede deel). CrowdSec AppSec met de
// OWASP Core Rule Set, als eigen container (rzsense-crowdsec) op een eigen netwerk (rzsense) waar ook SWAG aan hangt.
//   0 <tab> waf <tab> on|off <tab> detect|sure|strict <tab> 0|1 (delen met de CrowdSec-community)
//   0 <tab> wafsite <tab> site <tab> detect|off
//   0 <tab> wafex <tab> site <tab> regel (CRS-id of naam)
// De agent schrijft alleen eigen bestanden in /var/lib/rzsense-crowdsec (de container leest ze), maakt de sleutel voor
// SWAG, en test na elke wijziging of de WAF doet wat de slider zegt (een nep-aanval vanaf 203.0.113.10 naar de WAF
// zelf). Uit: de container weg; SWAG blijft gewoon werken. De bestanden en de opdrachten aan de container-engine zijn
// dezelfde als die van de bash-agent; de map met de stand is ook dezelfde.

import (
	"crypto/rand"
	"crypto/sha1"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

var (
	wfModeRe   = regexp.MustCompile(`^(detect|sure|strict)$`)
	wfRuleIDRe = regexp.MustCompile(`^[0-9]{3,7}$`)
	wfRuleNmRe = regexp.MustCompile(`^[a-z0-9_-]{1,40}/[A-Za-z0-9._-]{1,80}$`)
	wfErrRe    = regexp.MustCompile(`(?i)level=(error|fatal)|appsec.*(error|fail)|cannot|unable`)
	wfHTTPRe   = regexp.MustCompile(`HTTP/[0-9.]* ([0-9][0-9][0-9])`)
	wfSwagRe   = regexp.MustCompile(`"name": *"swag"[^}]*`)
	wfPullRe   = regexp.MustCompile(`"last_pull": *"([^"]*)"`)
	wfKeyRe    = regexp.MustCompile(`[^a-f0-9]`)
)

type ctrHelper struct {
	eng, ctr string
	timeout  time.Duration
}

// x: een opdracht aan de container-engine (stdout; alleen als de engine antwoordt)
func (c *ctrHelper) x(args ...string) (string, int) {
	if c.eng == "" || !ctrUsable(c.eng) {
		return "", 125
	}
	return stdoutOf(c.timeout, c.eng, args...)
}

// xAll: hetzelfde, met stdout en stderr samen (zoals 2>&1)
func (c *ctrHelper) xAll(timeout time.Duration, args ...string) (string, int) {
	if c.eng == "" || !ctrUsable(c.eng) {
		return "", 125
	}
	return run(timeout, c.eng, args...)
}

func (c *ctrHelper) exists() bool {
	if c.eng == "" {
		return false
	}
	_, rc := c.x("inspect", c.ctr)
	return rc == 0
}

// helperEngine: de engine van de proxy, of anders de engine waar de eigen container al staat
func helperEngine(pxEng, ctr string) string {
	if pxEng != "" {
		return pxEng
	}
	for _, e := range []string{"podman", "docker"} {
		if haveCmd(e) {
			if _, rc := pxCtr(15, e, "inspect", ctr); rc == 0 {
				return e
			}
		}
	}
	return ""
}

// wfRe: sitenaam (naam, *.domein of app.*) → regex op de Host, met dubbele backslashes voor YAML
func wfRe(s string) string {
	pre, suf := "^", "$"
	if strings.HasPrefix(s, "*.") {
		s, pre = s[2:], `\.`
	}
	if strings.HasSuffix(s, ".*") {
		s, suf = strings.TrimSuffix(s, ".*"), `\.`
	}
	r := pre + strings.ReplaceAll(s, ".", `\.`) + suf
	return strings.ReplaceAll(r, `\`, `\\`)
}

// lanNets: je eigen netwerken uit RzSense (regel proxylan) plus 127.0.0.1, elk met een masker
func lanNets(tests string) []string {
	out := []string{}
	words := []string{}
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "proxylan" {
			words = strings.Fields(f[2])
			break
		}
	}
	for _, n := range append(words, "127.0.0.1/32") {
		if !pxNetRe.MatchString(n) {
			continue
		}
		if !strings.Contains(n, "/") {
			n += "/32"
		}
		out = append(out, n)
	}
	return out
}

// wfFiles: de drie configuratiebestanden voor CrowdSec (alles opnieuw opgebouwd uit de regels)
func wfFiles(tests, mode string) (conf, acq, wl string) {
	lan := []string{}
	cidr := ""
	for _, n := range lanNets(tests) {
		lan = append(lan, `IpInRange(req.ClientIP, "`+n+`")`) // ClientIP = de bezoeker; RemoteAddr is SWAG (met poort)
		cidr += `    - "` + n + "\"\n"
	}
	off, det := []string{}, []string{}
	ex := ""
	nSite, nEx := 0, 0
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 2 || f[0] != "0" {
			continue
		}
		g := tabRead(l, 4)
		switch {
		case f[1] == "wafsite" && nSite < 200:
			nSite++
			if !pxNameRe.MatchString(g[2]) {
				continue
			}
			switch g[3] {
			case "off":
				off = append(off, wfRe(g[2]))
			case "detect":
				det = append(det, wfRe(g[2]))
			}
		case f[1] == "wafex" && nEx < 200:
			nEx++
			if !pxNameRe.MatchString(g[2]) {
				continue
			}
			a := ""
			switch {
			case wfRuleIDRe.MatchString(g[3]):
				a = "RemoveInBandRuleByID(" + g[3] + ")\n      - RemoveOutBandRuleByID(" + g[3] + ")"
			case wfRuleNmRe.MatchString(g[3]):
				a = `RemoveInBandRuleByName("` + g[3] + `")` + "\n      - " + `RemoveOutBandRuleByName("` + g[3] + `")`
			default:
				continue
			}
			ex += `  - filter: req.Host matches "` + wfRe(g[2]) + "\"\n    apply:\n      - " + a + "\n"
		}
	}
	in, out := "", ""
	switch mode {
	case "sure":
		in, out = "  - crowdsecurity/base-config\n  - crowdsecurity/vpatch-*", "  - crowdsecurity/crs"
	case "strict":
		in, out = "  - crowdsecurity/base-config\n  - crowdsecurity/vpatch-*\n  - crowdsecurity/crs", ""
	default:
		in, out = "  - crowdsecurity/base-config", "  - crowdsecurity/vpatch-*\n  - crowdsecurity/crs"
	}
	allow := "    apply:\n      - SetRemediation(\"allow\")\n      - CancelAlert()\n      - CancelEvent()\n"
	conf = "# RzSense: beheerd door de RzSense-agent (slider: " + mode + "). Wijzigingen hier worden overschreven.\nname: rzsense/appsec\ndefault_remediation: ban\ninband_rules:\n" + in + "\noutofband_rules:"
	if out != "" {
		conf += "\n" + out
	} else {
		conf += " []"
	}
	conf += "\n"
	if ex != "" {
		conf += "pre_eval:\n" + ex
	}
	conf += "on_match:\n  - filter: " + strings.Join(lan, " || ") + "\n" + allow
	if len(off) > 0 {
		conf += `  - filter: req.Host matches "(` + strings.Join(off, "|") + ")\"\n" + allow
	}
	if len(det) > 0 {
		conf += `  - filter: IsInBand && req.Host matches "(` + strings.Join(det, "|") + ")\"\n    apply:\n      - SetRemediation(\"allow\")\n"
	}
	acq = "# RzSense: beheerd door de RzSense-agent\nsource: appsec\nlisten_addr: 0.0.0.0:7422\nappsec_config_path: /etc/crowdsec/rzsense/appsec.yaml\nname: rzsense-appsec\nlabels:\n  type: appsec\n"
	wl = "# RzSense: je eigen netwerk en RzSense nooit blokkeren\nname: rzsense/whitelist\ndescription: \"RzSense: eigen netwerk\"\nwhitelist:\n  reason: \"RzSense: eigen netwerk\"\n  cidr:\n" + cidr
	return
}

// keepWrite: in hetzelfde bestand schrijven (de container heeft het vast); een nieuw bestand alleen voor root
func keepWrite(file, content string) {
	if fh, err := os.OpenFile(file, os.O_WRONLY|os.O_TRUNC|os.O_CREATE, 0o600); err == nil {
		_, _ = fh.WriteString(content)
		fh.Close()
	}
}

func sha12(s string) string {
	h := sha1.Sum([]byte(s))
	return hex.EncodeToString(h[:])[:12]
}

func tail2(out string) string { return tailJoin(out, 2, 200) }

// collectWafLinux: het onderdeel "waf" van het rapport
func collectWafLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !isRoot() || conf["PROXY_GUARD"] == "0" || !hasLinePrefix(tests, "0\twaf\t") {
		return nil
	}
	on, mode, share := "", "", ""
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 2 && f[0] == "0" && f[1] == "waf" {
			g := tabRead(strings.Join(f[2:], "\t"), 3)
			on, mode, share = g[0], g[1], g[2]
			break
		}
	}
	if !wfModeRe.MatchString(mode) {
		mode = "detect"
	}
	px := &proxyGuard{conf: conf, tests: tests, state: proxyStateDir()}
	if hasLinePrefix(tests, "0\tproxy\t") {
		px.detect()
	}
	const ctrName, net = "rzsense-crowdsec", "rzsense"
	dir := conf["WAF_DIR"]
	if dir == "" {
		dir = "/var/lib/rzsense-crowdsec"
	}
	img := conf["WAF_IMAGE"]
	if img == "" {
		img = "docker.io/crowdsecurity/crowdsec:v1.6.4"
	}
	state, msg, test, testMsg, bouncer, running, alerts := "error", "", "skip", "", "none", 0, ""
	c := &ctrHelper{eng: helperEngine(px.eng, ctrName), ctr: ctrName, timeout: 60 * time.Second}
	result := func() map[string]any {
		return map[string]any{"state": state, "running": running, "bouncer": bouncer, "net": net, "image": cleanText(img), "mode": mode, "test": test, "test_msg": cleanText(testMsg),
			"msg": cleanText(msg), "alerts": alerts}
	}
	if on != "on" {
		if c.exists() {
			c.x("rm", "-f", ctrName)
			actLog("proxy", 1, "WAF uit", "container "+ctrName+" weggehaald")
		}
		_ = os.Remove(filepath.Join(dir, "env.hash"))
		_ = os.Remove(filepath.Join(dir, "conf.hash"))
		state = "off"
		return result()
	}
	if px.kind != "swag" || px.eng == "" || px.root == "" {
		found := px.kind
		if found == "" {
			found = "geen proxy"
		}
		state, msg = "unsupported", "de WAF werkt nu alleen met SWAG (gevonden: "+found+")"
		return result()
	}
	_ = os.MkdirAll(filepath.Join(dir, "acquis.d"), 0o755)
	_ = os.MkdirAll(filepath.Join(dir, "data"), 0o755)
	_ = os.MkdirAll(px.root+"/rzsense-waf", 0o755)
	_ = os.Chmod(dir, 0o700)
	_ = os.Chmod(px.root+"/rzsense-waf", 0o700)
	// 1. Sleutel voor SWAG (de bouncer), alleen voor root leesbaar
	keyFile := px.root + "/rzsense-waf/crowdsec.key"
	if st, err := os.Stat(keyFile); err != nil || st.Size() == 0 {
		b := make([]byte, 32)
		_, _ = rand.Read(b)
		_ = os.WriteFile(keyFile, []byte(hex.EncodeToString(b)), 0o600)
		actLog("proxy", 1, "WAF: sleutel voor SWAG gemaakt", keyFile)
	}
	key := cutBytes(wfKeyRe.ReplaceAllString(readText(keyFile), ""), 64)
	// 2. Configuratie
	cf, acq, wl := wfFiles(tests, mode)
	keepWrite(filepath.Join(dir, "rzsense.yaml"), cf)
	keepWrite(filepath.Join(dir, "acquis.d", "rzsense-appsec.yaml"), acq)
	keepWrite(filepath.Join(dir, "whitelist.yaml"), wl)
	chash := sha12(readText(filepath.Join(dir, "rzsense.yaml")) + readText(filepath.Join(dir, "acquis.d", "rzsense-appsec.yaml")) + readText(filepath.Join(dir, "whitelist.yaml")))
	ehash := sha12(img + "|" + share + "|" + key + "|p2") // p2 = de appsec-config staat op een eigen pad
	// 3. Netwerk: rzsense, en SWAG eraan (ook opnieuw als SWAG opnieuw is aangemaakt)
	netOK := true
	if _, rc := c.x("network", "inspect", net); rc != 0 {
		if out, rc := c.xAll(60*time.Second, "network", "create", net); rc == 0 {
			actLog("proxy", 1, "WAF: netwerk "+net+" aangemaakt", "")
		} else {
			netOK = false
			msg = "netwerk " + net + " aanmaken mislukt: " + tail2(out) + " · probeer: sudo " + c.eng + " network create " + net
		}
	}
	if netOK {
		if out, _ := c.x("inspect", "-f", "{{json .NetworkSettings.Networks}}", px.ctr); !strings.Contains(out, `"`+net+`"`) {
			if _, rc := c.x("network", "connect", net, px.ctr); rc == 0 {
				actLog("proxy", 1, "WAF: "+px.ctr+" aan het netwerk "+net+" gehangen", "")
			} else {
				msg = "kon " + px.ctr + " niet aan het netwerk " + net + " hangen"
			}
		}
	}
	// 4. Container: (opnieuw) aanmaken als hij er niet is of de omgeving veranderde; herstarten als alleen de regels veranderden
	switch {
	case !netOK: // zonder netwerk heeft starten geen zin; de fout staat al in msg
	case !c.exists() || strings.TrimSpace(readText(filepath.Join(dir, "env.hash"))) != ehash:
		c.x("rm", "-f", ctrName)
		args := []string{"run", "-d", "--name", ctrName, "--network", net, "--restart", "unless-stopped",
			"-e", "COLLECTIONS=crowdsecurity/appsec-virtual-patching crowdsecurity/appsec-generic-rules crowdsecurity/appsec-crs", "-e", "BOUNCER_KEY_swag=" + key}
		if share != "1" {
			args = append(args, "-e", "DISABLE_ONLINE_API=true")
		}
		args = append(args, "-v", dir+"/acquis.d:/etc/crowdsec/acquis.d:Z", "-v", dir+"/rzsense.yaml:/etc/crowdsec/rzsense/appsec.yaml:Z",
			"-v", dir+"/whitelist.yaml:/etc/crowdsec/parsers/s02-enrich/rzsense-whitelist.yaml:Z", "-v", dir+"/data:/var/lib/crowdsec/data:Z", img)
		if out, rc := c.xAll(240*time.Second, args...); rc == 0 {
			_ = os.WriteFile(filepath.Join(dir, "env.hash"), []byte(ehash+"\n"), 0o600)
			_ = os.WriteFile(filepath.Join(dir, "conf.hash"), []byte(chash+"\n"), 0o600)
			actLog("proxy", 1, "WAF: CrowdSec gestart", img+", slider "+mode)
			_ = os.Remove(filepath.Join(dir, "starting.since")) // nieuwe container: de 10 minuten beginnen opnieuw
		} else {
			msg = "starten mislukt: " + tail2(out)
		}
	case strings.TrimSpace(readText(filepath.Join(dir, "conf.hash"))) != chash:
		if _, rc := c.x("restart", ctrName); rc == 0 {
			_ = os.WriteFile(filepath.Join(dir, "conf.hash"), []byte(chash+"\n"), 0o600)
			actLog("proxy", 1, "WAF: regels doorgevoerd", "slider "+mode)
		} else {
			msg = "herstarten mislukt"
		}
	}
	// de laatste fout uit het log van de container, zonder de lange aanloop van CrowdSec, met een tip
	reason := func() string {
		out, _ := c.xAll(60*time.Second, "logs", "--tail", "300", ctrName)
		r := ""
		for _, l := range lines(out) {
			if wfErrRe.MatchString(l) {
				r = l
			}
		}
		for _, re := range []string{`^time="[^"]*" `, `^level=[a-z]* `, `^msg="`, `"$`, `^crowdsec init: `, `^while loading acquisition config: `,
			`^while configuring datasource of type appsec from [^ ]* \(position: [0-9]*\): `, `^failed to configure datasource appsec: `} {
			r = regexp.MustCompile(re).ReplaceAllString(r, "")
		}
		r = cutRunes(r, 300)
		switch {
		case strings.Contains(r, "no appsec-rules found"):
			r += " (de regels van CrowdSec zijn niet geïnstalleerd: kon de container de CrowdSec-hub bereiken?)"
		case strings.Contains(r, "no appsec-config found"), strings.Contains(r, "unable to load appsec"):
			r += " (de configuratie van RzSense werd niet geladen)"
		case strings.Contains(r, "address already in use"):
			r += " (poort 7422 is al in gebruik)"
		}
		return r
	}
	// 5. Draait hij, antwoordt de WAF, is SWAG gekoppeld, en doet hij wat de slider zegt?
	if out, _ := c.x("inspect", "-f", "{{.State.Running}}", ctrName); strings.TrimSpace(out) == "true" {
		running = 1
	}
	ask := func(uri string) string { // 200 (mag), 403 (tegengehouden) of 000 (geen antwoord)
		o, _ := c.xAll(60*time.Second, "exec", ctrName, "wget", "-q", "-S", "-O", "/dev/null", "--header", "X-Crowdsec-Appsec-Ip: 203.0.113.10", "--header", "X-Crowdsec-Appsec-Uri: "+uri,
			"--header", "X-Crowdsec-Appsec-Host: rzsense-selftest.invalid", "--header", "X-Crowdsec-Appsec-Verb: GET", "--header", "X-Crowdsec-Appsec-Api-Key: "+key, "http://127.0.0.1:7422/")
		_ = os.WriteFile(filepath.Join(dir, "ask.last"), []byte(strings.TrimRight(o, "\n")), 0o600)
		switch {
		case strings.Contains(o, " 200"):
			return "200"
		case strings.Contains(o, " 403"):
			return "403"
		}
		return "000"
	}
	orMsg := func(def string) {
		if msg == "" {
			msg = def
		}
	}
	if running != 1 {
		// stopt hij steeds (CrowdSec start niet), dan meteen de reden uit het log
		def := "de container draait niet"
		if r := reason(); r != "" {
			def += ": " + r
		}
		orMsg(def)
		return result()
	}
	ok := ask("/")
	if ok == "000" {
		// niet eindeloos "starten": na 10 minuten zonder antwoord een fout, met de reden
		sinceFile := filepath.Join(dir, "starting.since")
		if st, err := os.Stat(sinceFile); err != nil || st.Size() == 0 {
			_ = os.WriteFile(sinceFile, []byte(fmt.Sprintf("%d\n", time.Now().Unix())), 0o600)
		}
		since := time.Now().Unix()
		if s := strings.TrimSpace(readText(sinceFile)); digitsRe.MatchString(s) {
			since = int64(atoiDef(s, int(since)))
		}
		if min := (time.Now().Unix() - since) / 60; min >= 10 {
			raw := readText(filepath.Join(dir, "ask.last"))
			why := ""
			if m := wfHTTPRe.FindAllStringSubmatch(raw, -1); len(m) > 0 {
				code := m[len(m)-1][1]
				why = "AppSec gaf HTTP " + code
				switch code {
				case "401":
					why += " (de sleutel van de bouncer wordt geweigerd)"
				case "404":
					why += " (de AppSec-component is niet geladen)"
				}
			} else {
				last := ""
				for _, l := range strings.Split(raw, "\n") {
					if strings.TrimSpace(l) != "" {
						last = l
					}
				}
				why = "geen antwoord op poort 7422: " + cutRunes(last, 120)
			}
			state = "error"
			msg = fmt.Sprintf("de WAF antwoordt na %d minuten nog niet: %s", min, why)
			if r := reason(); r != "" {
				msg += " · log: " + r
			}
		} else {
			state = "starting"
			orMsg("de WAF start nog (regels laden)")
		}
		return result()
	}
	_ = os.Remove(filepath.Join(dir, "starting.since"))
	// Zelftest: alleen zeker = een bekend lek (CVE-2017-9841); streng = SQL-injectie; detecteren = SQL-injectie mag door
	uri, want := "/?id=1%27%20OR%20%271%27%3D%271", "200"
	switch mode {
	case "sure":
		uri, want = "/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php", "403"
	case "strict":
		want = "403"
	}
	if got := ask(uri); got == want && ok == "200" {
		test, testMsg = "ok", "nep-aanval: "+got+" zoals verwacht"
	} else {
		test, testMsg = "fail", "nep-aanval gaf "+got+", verwacht "+want+" (gewoon verzoek: "+ok+")"
	}
	// SWAG gekoppeld? (de bouncer haalt elke paar seconden de lijst op)
	bl, _ := c.x("exec", ctrName, "cscli", "bouncers", "list", "-o", "json")
	if m := wfSwagRe.FindString(strings.ReplaceAll(bl, "\n", "")); m != "" {
		bouncer = "waiting"
		if p := wfPullRe.FindStringSubmatch(m); p != nil && p[1] != "" && !strings.HasPrefix(p[1], "0001") {
			bouncer = "active"
		}
	}
	if bouncer == "active" {
		state = "ok"
		orMsg("actief, slider " + mode)
	} else {
		state = "waiting"
		orMsg("draait; zet de koppeling aan in SWAG (zie RzSense)")
	}
	// Nieuwe meldingen (RzSense slaat elke melding één keer op)
	al, _ := c.x("exec", ctrName, "cscli", "alerts", "list", "-o", "json", "-l", "100", "--since", "20m")
	alerts = base64.StdEncoding.EncodeToString([]byte(cutBytes(al, 300000)))
	return result()
}
