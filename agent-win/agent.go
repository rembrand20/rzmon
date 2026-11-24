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
const Version = "0.1.0"

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
func pair(c *Config, code string) (string, error) {
	pk := pairKey(code)
	host, _ := os.Hostname()
	plain, _ := json.Marshal(map[string]string{"agent_version": Version, "hostname": host, "os": "windows"})
	env, nonce, err := seal(plain, pk, time.Now())
	if err != nil {
		return "", err
	}
	r, err := call(c.URL, c.Pin, "POST", "agent_pair", env, map[string]string{"X-Agent-Pair": keyID(pk)}, 20*time.Second)
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
	Tick  int               // seconden tussen rapporten
	Svc   map[string]string // check-id → servicenaam
	Procs []procWant
	Rev   string
}

func parseTests(text string) tests {
	t := tests{Tick: 60, Svc: map[string]string{}}
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
	if r := readState("update.result"); r != "" {
		rep["update_result"] = r
	}
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
}

func handleReply(c *Config, t tests, body []byte) {
	var r reply
	if err := json.Unmarshal(body, &r); err != nil {
		logf("Antwoord van RzSense niet te lezen: %v", err)
		return
	}
	writeState("gz", fmt.Sprint(r.Gz))
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
	// De testlijst veranderde: meteen opnieuw ophalen (volgende ronde)
	if r.TestsRev != "" && r.TestsRev != t.Rev {
		dropState("tests.txt.b64")
	}
	if r.Update != nil && c.RemoteUpdate {
		selfUpdate(c, r.Update.Version, r.Update.ExeSHA)
	}
}
