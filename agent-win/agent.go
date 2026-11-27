package main

import (
	"bytes"
	"compress/gzip"
	"crypto/sha1"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"regexp"
	"strings"
	"time"
)

// Version: wordt bijgewerkt met tools/bump-version.sh agentwin (gelijk aan agent-win/VERSION)
const Version = "0.30.0"

// ---------------------------------------------------------------- koppelen

type pairReply struct {
	OK       bool   `json:"ok"`
	Host     string `json:"host"`
	AgentKey string `json:"agent_key"`
	Pin      string `json:"pin"`
	Error    string `json:"error"`
}

// pair: eenmalig koppelen met een koppelcode (RzSense → Agent Manager → 🔗 Koppelcode). Het verzoek is versleuteld met
// een sleutel uit de code; RzSense antwoordt, ook versleuteld, met de eigen sleutel van deze server.
// Met "enroll:CODE" (fase 4) meldt de server zich aan met een uitrolcode: RzSense maakt de server dan zelf aan.
func pair(c *Config, code string) (string, error) {
	pk, action, hdr := pairKey(code), "agent_pair", "X-Agent-Pair"
	if strings.HasPrefix(code, "enroll:") {
		pk, action, hdr = enrollKey(strings.TrimPrefix(code, "enroll:")), "agent_enroll", "X-Agent-Enroll"
	}
	host, _ := os.Hostname()
	plain, _ := json.Marshal(map[string]string{"agent_version": Version, "hostname": host, "os": "windows"})
	env, nonce, err := seal(plain, pk, time.Now())
	if err != nil {
		return "", err
	}
	r, err := call(c.URL, c.Pin, "POST", action, env, map[string]string{hdr: keyID(pk)}, 20*time.Second)
	if err != nil {
		return "", err
	}
	if r.Code != 200 || !isSealed(r.Body) {
		return "", fmt.Errorf("koppelen mislukt (HTTP %d): %s", r.Code, shortErr(r.Body))
	}
	body, err := openReply(r.Body, pk, nonce, time.Now())
	if err != nil {
		return "", fmt.Errorf("antwoord van RzSense klopt niet (%v); niet gekoppeld", err)
	}
	var p pairReply
	if err := json.Unmarshal(body, &p); err != nil || !hexKeyRe.MatchString(p.AgentKey) {
		return "", errors.New("geen sleutel in het antwoord")
	}
	c.Key = p.AgentKey
	if p.Pin != "" && strings.HasPrefix(c.URL, "https://") {
		c.Pin = "sha256//" + strings.TrimPrefix(p.Pin, "sha256//")
	} else if p.Pin == "" && c.Pin != "" && !strings.HasPrefix(c.URL, "https://") {
		c.Pin = ""
	}
	return p.Host, c.save()
}

// ---------------------------------------------------------------- testlijst (wat RzSense wil weten)

type procWant struct {
	ID      string
	Pattern bool
	Value   string
}

type tests struct {
	Tick     int               // seconden tussen rapporten
	Svc      map[string]string // check-id → servicenaam
	Procs    []procWant
	Rev      string
	LogSrc   []logSrc   // fase 2: logboeken naar de pagina Logs
	LogShip  int        // om de hoeveel seconden versturen
	LogPause int64      // tot wanneer niets lezen (daglimiet bereikt)
	LogScan  int        // "Opnieuw zoeken" (teller)
	Logs     []logCheck // log-checks
	Auth     bool       // logins doorgeven
	Certs    []string   // certificaten om te lezen
	CertScan int        // "Opnieuw zoeken" naar certificaten (teller)
	IDS      string     // fase 3: "learn" of "block" (leeg = IDS uit)
	Blocks   []blockWant
	Isolate  *isolateWant
	WebLog   string // fase 3: IIS-logs bekijken op webaanvallen (pad, of "auto"); leeg = niet
	Patch    bool   // fase 4: Windows Update meedoen met Updates in RzSense
}

func parseTests(text string) tests {
	t := tests{Tick: 60, Svc: map[string]string{}, LogShip: 60}
	for _, line := range strings.Split(strings.ReplaceAll(text, "\r", ""), "\n") {
		f := strings.Split(line, "\t")
		if len(f) < 3 {
			continue
		}
		switch f[1] {
		case "tick":
			if n := atoiDef(f[2], 60); n >= 10 && n <= 3600 {
				t.Tick = n
			}
		case "svc":
			t.Svc[f[0]] = f[2]
		case "logsrc":
			if len(f) >= 5 {
				spec := f[3]
				if spec == "-" {
					spec = ""
				}
				t.LogSrc = append(t.LogSrc, logSrc{ID: f[0], Kind: f[2], Spec: spec, Level: atoiDef(f[4], 0)})
			}
		case "logship":
			if n := atoiDef(f[2], 60); n >= 10 {
				t.LogShip = n
			}
		case "logpause":
			t.LogPause = int64(atoiDef(f[2], 0))
		case "logscan":
			t.LogScan = atoiDef(f[2], 0)
		case "log":
			if len(f) >= 4 {
				t.Logs = append(t.Logs, logCheck{ID: f[0], Source: f[2], Pattern: f[3]})
			}
		case "auth":
			t.Auth = f[2] == "1"
		case "cert":
			t.Certs = append(t.Certs, f[2])
		case "certscan":
			t.CertScan = atoiDef(f[2], 0)
		case "ids":
			t.IDS = f[2]
		case "patch":
			t.Patch = f[2] == "1"
		case "weblog":
			if f[2] == "swag" { // de reverse proxy van Linux = de webserver (IIS) op Windows
				t.WebLog = "auto"
				if len(f) >= 4 {
					t.WebLog = f[3]
				}
			}
		case "block":
			if len(f) >= 4 {
				t.Blocks = append(t.Blocks, blockWant{IP: f[2], Until: int64(atoiDef(f[3], 0))})
			}
		case "isolate":
			if len(f) >= 3 {
				w := &isolateWant{Until: int64(atoiDef(f[2], 0))}
				if len(f) >= 4 {
					w.Allow = strings.Fields(f[3])
				}
				t.Isolate = w
			}
		case "proc":
			if len(f) >= 4 {
				if v, err := base64.StdEncoding.DecodeString(f[3]); err == nil {
					t.Procs = append(t.Procs, procWant{ID: f[0], Pattern: f[2] == "pattern", Value: string(v)})
				}
			}
		}
	}
	// Zelfde kenmerk als RzSense (agent_tests_rev): sha1 over de lijst met één regeleinde erachter
	sum := sha1.Sum([]byte(strings.TrimRight(text, "\n") + "\n"))
	t.Rev = hex.EncodeToString(sum[:])[:12]
	return t
}

func loadTests(c *Config, force bool) tests {
	cached := readState("tests.txt.b64")
	if !force && cached != "" {
		if b, err := base64.StdEncoding.DecodeString(cached); err == nil {
			return parseTests(string(b))
		}
	}
	b, err := signedGet(c, "agent_tests", 15*time.Second)
	if err != nil {
		logf("Lijst met tests niet opgehaald: %v", err)
		if cached != "" {
			if b, err := base64.StdEncoding.DecodeString(cached); err == nil {
				return parseTests(string(b))
			}
		}
		return parseTests("")
	}
	writeState("tests.txt.b64", base64.StdEncoding.EncodeToString(b))
	return parseTests(string(b))
}

// ---------------------------------------------------------------- het rapport

var svcNameRe = regexp.MustCompile(`^[A-Za-z0-9@._:$-]{1,100}$`)

func buildReport(c *Config, t tests, parts map[string]int) map[string]any {
	lap := func(name string, t0 time.Time) { parts[name] = int(time.Since(t0).Milliseconds()) }
	rep := map[string]any{
		"agent_version": Version,
		"os":            "windows",
		"url":           c.URL,
		"url_fallback":  c.URLFallback,
		"url_pin":       pinSpec(c.Pin),
		"url_fb_pin":    pinSpec(c.PinFallback),
		"update":        map[string]any{"via": map[bool]string{true: "winsvc", false: "off"}[c.RemoteUpdate]},
		"caps":          []string{"windows"},
	}
	t0 := time.Now()
	b := collectBasics()
	lap("basis", t0)
	for k, v := range b {
		rep[k] = v
	}
	t0 = time.Now()
	rep["ports"] = collectPorts()
	lap("ports", t0)
	t0 = time.Now()
	if w := wifiInfo(); w != nil {
		rep["wifi"] = w // 0.7.0: de eigen wifi, voor Netwerk → Netwerksnelheid
	}
	lap("wifi", t0)
	t0 = time.Now()
	names := make([]string, 0, len(t.Svc))
	for _, n := range t.Svc {
		if svcNameRe.MatchString(n) {
			names = append(names, n)
		}
	}
	rep["services"] = serviceStates(names)
	// De hele lijst om uit te kiezen bij "Check toevoegen" (elke ronde, zoals Linux: RzSense ruimt wat ontbreekt op)
	rep["svc_list"] = serviceList()
	lap("services", t0)
	t0 = time.Now()
	list, procs := processInfo(t.Procs)
	rep["proc_list"] = list
	if len(procs) > 0 {
		rep["procs"] = procs
	}
	lap("processen", t0)
	if r := readState("url.result"); r != "" {
		rep["url_result"] = r // fase 4: uitkomst van overstappen naar een ander adres
	}
	if r := readState("update.result"); r != "" {
		rep["update_result"] = r
	}
	// Fase 2: logins, log-checks, certificaten, de logboeken om uit te kiezen, en Windows Update
	t0 = time.Now()
	if t.Auth {
		if a := collectAuth(); a != nil {
			rep["auth"] = a
		}
	}
	if len(t.Logs) > 0 {
		rep["logs"] = runLogChecks(t.Logs)
	}
	lap("logs", t0)
	t0 = time.Now()
	if len(t.Certs) > 0 {
		rep["certs"] = collectCerts(t.Certs)
	}
	// Certificaten en logboeken zoeken: als RzSense erom vraagt, en verder eens per dag
	day := time.Now().Unix() / 86400
	if fmt.Sprint(t.CertScan) != readState("certscan.req") || readState("certscan.day") != fmt.Sprint(day) {
		rep["cert_found"] = certFound()
		writeState("certscan.req", fmt.Sprint(t.CertScan))
		writeState("certscan.day", fmt.Sprint(day))
	}
	if fmt.Sprint(t.LogScan) != readState("logscan.req") || readState("logscan.day") != fmt.Sprint(day) {
		rep["log_scan"] = logScan(t.LogScan)
		writeState("logscan.req", fmt.Sprint(t.LogScan))
		writeState("logscan.day", fmt.Sprint(day))
	}
	lap("certificaten", t0)
	if short, patch := splitWU(windowsUpdate()); short != nil {
		rep["winupdate"] = short
		if patch != nil && t.Patch {
			rep["patch"] = patch // fase 4: voor Updates in RzSense
		}
		if patch != nil {
			rep["win_vulns"] = map[string]any{"at": short["at"], "list": wuCVEs(windowsUpdate())} // fase 4: Kwetsbaarheden
		}
	}
	if r := readState("wu.result"); r != "" {
		rep["pkg_result"] = json.RawMessage(r) // uitkomst van installeren die nog niet aangekomen was
	}
	// Fase 3: IDS en EDR (gebeurtenissen, Defender, blokkeren via de firewall) en isoleren (los van het IDS)
	t0 = time.Now()
	if t.IDS != "" {
		ids := map[string]any{"win": toJSONList(collectIDS())}
		if av := defenderStatus(); av != nil {
			ids["win_av"] = av
		}
		if t.IDS == "block" {
			ids["fw"] = syncBlocks(t.Blocks, hostIPs(c.URL, c.URLFallback))
		}
		rep["ids"] = ids
		if t.WebLog != "" {
			rep["weblog"] = map[string]any{"swag": iisWeblog(t.WebLog)}
		}
	}
	if iso := applyIsolation(c, t.Isolate); iso != nil {
		rep["iso"] = iso
	}
	lap("ids", t0)
	return rep
}

// round: één ronde: testlijst, meten, versturen, antwoord verwerken. Geeft het aantal seconden tot de volgende.
func round(c *Config, force bool) (int, error) {
	start := time.Now()
	t := loadTests(c, force)
	parts := map[string]int{}
	rep := buildReport(c, t, parts)
	rep["timing"] = map[string]any{"total": int(time.Since(start).Milliseconds()), "parts": parts}
	plain, err := json.Marshal(rep)
	if err != nil {
		return t.Tick, err
	}
	// Comprimeren als RzSense dat aankan (dat zei hij bij het vorige antwoord: gz = 1)
	if readState("gz") == "1" {
		var buf bytes.Buffer
		zw := gzip.NewWriter(&buf)
		zw.Write(plain)
		zw.Close()
		plain = buf.Bytes()
	}
	body, code, err := sealedCall(c, "agent", plain, nil, 50*time.Second)
	if err != nil {
		return t.Tick, fmt.Errorf("rapport niet verstuurd: %w", err)
	}
	if code != 200 {
		return t.Tick, fmt.Errorf("rapport niet geaccepteerd (HTTP %d): %s", code, shortErr(body))
	}
	dropState("update.result")
	handleReply(c, t, body)
	shipLogs(c, t) // logboeken naar de pagina Logs (eigen verzoek, zoals de Linux-agent)
	return t.Tick, nil
}

type reply struct {
	OK       bool              `json:"ok"`
	Gz       int               `json:"gz"`
	TestsRev string            `json:"tests_rev"`
	AgentKey string            `json:"agent_key"`
	URLPins  map[string]string `json:"url_pins"`
	Update   *struct {
		Version string `json:"version"`
		ExeSHA  string `json:"exe_sha256"`
	} `json:"update"`
	Reserve *struct { // fase 4: de reserve-RzSense ("" = geen)
		URL string `json:"url"`
		Pin string `json:"pin"`
	} `json:"reserve"`
	URLSet     *urlSet   `json:"url_set"` // fase 4: overstappen naar een ander adres
	PkgUpgrade *struct { // fase 4: Windows-updates installeren (patchbeheer)
		ID   string `json:"id"`
		Mode string `json:"mode"`
	} `json:"pkg_upgrade"`
}

func handleReply(c *Config, t tests, body []byte) {
	var r reply
	if err := json.Unmarshal(body, &r); err != nil {
		logf("Antwoord van RzSense niet te lezen: %v", err)
		return
	}
	writeState("gz", fmt.Sprint(r.Gz))
	dropState("url.result") // de uitkomst van een overstap is doorgegeven
	if lastVia == "reserve" {
		return // fase 4: de reserve-RzSense verandert geen sleutel, adressen of opdrachten
	}
	// Nieuwe sleutel (RzSense vernieuwt hem af en toe); de vorige blijft daar nog 24 uur geldig
	if hexKeyRe.MatchString(r.AgentKey) && r.AgentKey != c.Key {
		c.Key = r.AgentKey
		if err := c.save(); err != nil {
			logf("Nieuwe sleutel niet bewaard: %v", err)
		} else {
			logf("Nieuwe eigen sleutel van RzSense ontvangen")
		}
	}
	// Hoe elk adres gecontroleerd moet worden (vingerafdruk of gewoon)
	changed := false
	for u, spec := range r.URLPins {
		pin := ""
		if strings.HasPrefix(spec, "sha256//") {
			pin = spec
		}
		switch strings.TrimRight(u, "/") {
		case c.URL:
			if c.Pin != pin {
				c.Pin, changed = pin, true
			}
		case c.URLFallback:
			if c.PinFallback != pin {
				c.PinFallback, changed = pin, true
			}
		}
	}
	if changed {
		_ = c.save()
	}
	if r.Reserve != nil {
		setReserve(r.Reserve.URL, r.Reserve.Pin)
	}
	if r.URLSet != nil {
		switchURL(c, *r.URLSet)
	}
	// De testlijst veranderde: meteen opnieuw ophalen (volgende ronde)
	if r.TestsRev != "" && r.TestsRev != t.Rev {
		dropState("tests.txt.b64")
	}
	if r.OK {
		dropState("wu.result") // is met dit rapport aangekomen
	}
	if r.PkgUpgrade != nil {
		installWindowsUpdates(c, r.PkgUpgrade.ID, r.PkgUpgrade.Mode)
	}
	if r.Update != nil && c.RemoteUpdate {
		selfUpdate(c, r.Update.Version, r.Update.ExeSHA)
	}
}
