//go:build linux

package main

// Het commando "rzsense" op Linux (0.8.0). Eén commando voor wat je op een server wilt weten of doen:
//
//	rzsense status     hoe de agent ervoor staat
//	rzsense test       kan deze server RzSense bereiken, en vertrouwt RzSense hem?
//	rzsense doctor     alles nalopen en zeggen wat er mis is en hoe je het oplost
//	rzsense collect    laten zien wat de Go-agent meet (zonder te versturen)
//	rzsense shadow     één proefronde: meten en naar RzSense sturen om te vergelijken met de bash-agent
//	rzsense version
//
// In deze versie is de Go-agent een proef naast de bash-agent: hij leest diens instellingen en sleutel, verandert niets
// en neemt niets over. De bash-agent blijft het werk doen.

import (
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"os/exec"
	"strings"
	"time"
)

// cliLinux: op Linux handelt dit alle opdrachten af (geeft altijd true)
func cliLinux(args []string) bool {
	if os.Getenv("RZSENSE_DATA") == "" {
		os.Setenv("RZSENSE_DATA", "/var/lib/rzsense")
	}
	_ = os.MkdirAll(dataDir(), 0o700)
	cmd := ""
	if len(args) > 1 {
		cmd = args[1]
	}
	rest := []string{}
	if len(args) > 2 {
		rest = args[2:]
	}
	has := func(flag string) bool {
		for _, a := range rest {
			if a == flag {
				return true
			}
		}
		return false
	}
	switch cmd {
	case "version", "--version", "-v":
		fmt.Println("rzsense " + Version)
	case "collect":
		b, _ := json.MarshalIndent(coreCollect(2*time.Second), "", "  ")
		fmt.Println(string(b))
	case "status":
		os.Exit(cmdStatus())
	case "test":
		os.Exit(cmdTest())
	case "doctor":
		os.Exit(cmdDoctor(has("--json")))
	case "shadow":
		// 0.13.0: dezelfde timer voor beide: doet de Go-agent het werk, dan is dit de echte ronde
		if modeActive() {
			os.Exit(cmdRun(has("--quiet")))
		}
		os.Exit(cmdShadow(has("--quiet")))
	case "auth-parse": // voor de tests: regels op stdin → wat er naar RzSense zou gaan (zoals rzmon-agent.sh --auth-parse)
		in, _ := io.ReadAll(os.Stdin)
		b, _ := json.Marshal(map[string]any{"auth": authParse(string(in))})
		fmt.Println(string(b))
	case "back":
		os.Exit(cmdBack())
	default:
		fmt.Println(`rzsense ` + Version + ` – de agent van RzSense (proef naast de bash-agent)

  rzsense status     hoe de agent ervoor staat
  rzsense test       kan deze server RzSense bereiken, en vertrouwt RzSense hem?
  rzsense doctor     alles nalopen: wat is er mis en hoe los je het op
  rzsense collect    laten zien wat de Go-agent meet (zonder te versturen)
  rzsense shadow     één ronde: een proefronde, of het echte rapport als de Go-agent het werk doet
  rzsense back       terug naar de bash-agent (als de Go-agent het werk had overgenomen)
  rzsense version

Als proef leest de Go-agent alleen mee. Het werk overnemen doet hij alleen als je dat in RzSense vraagt.`)
		if cmd != "" && cmd != "help" && cmd != "--help" && cmd != "-h" {
			os.Exit(2)
		}
	}
	return true
}

// ---------------------------------------------------------------- doctor

type finding struct {
	Key    string `json:"k"`
	State  string `json:"st"` // ok, warn, bad
	Title  string `json:"t"`
	Detail string `json:"d"`
	Fix    string `json:"fix,omitempty"`
}

func bashAgentVersion() string {
	for _, p := range []string{"/usr/local/bin/rzmon-agent.sh"} {
		out, err := exec.Command(p, "--version").Output()
		if err == nil {
			f := strings.Fields(strings.TrimSpace(string(out)))
			if len(f) > 0 {
				return f[len(f)-1]
			}
		}
	}
	return ""
}

func systemctlActive(unit string) (active, known bool) {
	if _, err := exec.LookPath("systemctl"); err != nil {
		return false, false
	}
	out, _ := exec.Command("systemctl", "is-active", unit).Output()
	return strings.TrimSpace(string(out)) == "active", true
}

// checkAgent: de signed test bij RzSense (agent_check); het antwoord moet met onze sleutel versleuteld zijn
func checkAgent(c *Config) (host, ip string, err error) {
	b, err := signedGet(c, "agent_check", 15*time.Second)
	if err != nil {
		return "", "", err
	}
	var r struct {
		OK     bool   `json:"ok"`
		Host   string `json:"host"`
		YourIP string `json:"your_ip"`
	}
	if err := json.Unmarshal(b, &r); err != nil || !r.OK {
		return "", "", fmt.Errorf("onverwacht antwoord van RzSense")
	}
	return r.Host, r.YourIP, nil
}

// explain: een foutmelding van de verbinding in gewone taal, met wat je eraan doet
func explain(err error) (detail, fix string) {
	s := err.Error()
	switch {
	case err == errPin || strings.Contains(s, "vingerafdruk"):
		return "het certificaat van RzSense is anders dan het certificaat dat deze server kent",
			"Is het certificaat van RzSense vernieuwd? Koppel de agent opnieuw met een koppelcode (RzSense → Agent Manager → 🔗 Koppelcode)."
	case strings.Contains(s, "certificate") || strings.Contains(s, "x509"):
		return "het certificaat van RzSense wordt niet vertrouwd: " + s,
			"Gebruikt RzSense een eigen certificaat? Koppel de agent opnieuw met een koppelcode; dan zet de agent de vingerafdruk vast."
	case strings.Contains(s, "HTTP 403"):
		return "RzSense weigert deze server: " + s,
			"Kijk in RzSense bij Meldingen (⚠️ Niet-geautoriseerd) voor de reden. Meestal helpt opnieuw koppelen met een koppelcode."
	case strings.Contains(s, "HTTP 404"):
		return "op dit adres antwoordt geen RzSense (404)",
			"Klopt MONITOR_URL in " + bashConfPath() + "? Staat RzSense op 'onzichtbaar van buiten', dan moet deze server bekend zijn."
	case strings.Contains(s, "no such host"):
		return "de naam van RzSense is niet te vinden (DNS)", "Controleer de DNS van deze server, of gebruik een IP-adres in MONITOR_URL."
	case strings.Contains(s, "connection refused"):
		return "RzSense weigert de verbinding (er luistert niets op die poort)", "Draait RzSense, en klopt de poort in MONITOR_URL?"
	case strings.Contains(s, "timeout") || strings.Contains(s, "deadline"):
		return "geen antwoord van RzSense binnen de tijd", "Houdt een firewall het verkeer tegen? Probeer: curl -I <adres van RzSense>"
	}
	return s, ""
}

func diagnose() []finding {
	var out []finding
	add := func(k, st, t, d, fix string) { out = append(out, finding{k, st, t, d, fix}) }
	if os.Geteuid() != 0 {
		add("root", "warn", "Rechten", "niet gestart als root: de sleutel van de agent is dan niet te lezen", "Start met sudo: sudo rzsense doctor")
	}
	c, err := loadBashConfig()
	if err != nil {
		add("conf", "bad", "Instellingen", err.Error(), "Is de agent geïnstalleerd? Het installatiecommando staat in RzSense → Agent Manager.")
		return out
	}
	add("conf", "ok", "Instellingen", bashConfPath(), "")
	u, perr := url.Parse(c.URL)
	switch {
	case c.URL == "" || perr != nil || u.Host == "":
		add("url", "bad", "Adres van RzSense", "MONITOR_URL ontbreekt of klopt niet", "Zet MONITOR_URL in "+bashConfPath()+", bijvoorbeeld https://rzsense.voorbeeld")
		return out
	case u.Scheme != "https":
		add("url", "warn", "Adres van RzSense", c.URL+" (via http: de verbinding is alleen versleuteld door de sleutel van de agent)", "Gebruik een https-adres als RzSense dat heeft.")
	default:
		add("url", "ok", "Adres van RzSense", c.URL, "")
	}
	if c.Key == "" {
		add("key", "bad", "Eigen sleutel", "deze server heeft geen eigen sleutel (agent.key)", "Koppel de agent met een koppelcode: RzSense → Agent Manager → 🔗 Koppelcode.")
	} else {
		add("key", "ok", "Eigen sleutel", "aanwezig (kenmerk "+keyID(c.Key)+")", "")
	}
	host := u.Hostname()
	port := u.Port()
	if port == "" {
		port = map[bool]string{true: "443", false: "80"}[u.Scheme == "https"]
	}
	if net.ParseIP(host) == nil {
		if addrs, err := net.LookupHost(host); err != nil || len(addrs) == 0 {
			add("dns", "bad", "Naam opzoeken (DNS)", host+" is niet te vinden", "Controleer de DNS van deze server (cat /etc/resolv.conf), of gebruik een IP-adres in MONITOR_URL.")
			return out
		} else {
			add("dns", "ok", "Naam opzoeken (DNS)", host+" → "+addrs[0], "")
		}
	}
	t0 := time.Now()
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(host, port), 6*time.Second)
	if err != nil {
		d, fix := explain(err)
		add("reach", "bad", "Verbinding", d, fix)
		return out
	}
	conn.Close()
	add("reach", "ok", "Verbinding", fmt.Sprintf("%s:%s bereikbaar in %d ms", host, port, time.Since(t0).Milliseconds()), "")
	if c.Key != "" {
		if name, ip, err := checkAgent(c); err != nil {
			d, fix := explain(err)
			add("trust", "bad", "RzSense herkent deze server", d, fix)
		} else {
			add("trust", "ok", "RzSense herkent deze server", fmt.Sprintf("als %q, vanaf %s; het antwoord is versleuteld met de eigen sleutel", name, ip), "")
		}
	}
	if v := bashAgentVersion(); v != "" {
		add("bash", "ok", "Bash-agent", "versie "+v, "")
	} else {
		add("bash", "warn", "Bash-agent", "/usr/local/bin/rzmon-agent.sh niet gevonden of niet te starten", "Installeer de agent opnieuw met het commando uit RzSense → Agent Manager.")
	}
	if active, known := systemctlActive("rzmon-agent.timer"); known {
		if active {
			add("timer", "ok", "Planning van de bash-agent", "rzmon-agent.timer loopt", "")
		} else if _, err := os.Stat("/etc/cron.d/rzmon-agent"); err == nil {
			add("timer", "ok", "Planning van de bash-agent", "via cron (/etc/cron.d/rzmon-agent)", "")
		} else {
			add("timer", "bad", "Planning van de bash-agent", "rzmon-agent.timer loopt niet", "Start hem: sudo systemctl enable --now rzmon-agent.timer")
		}
	}
	return out
}

func cmdDoctor(asJSON bool) int {
	f := diagnose()
	if asJSON {
		b, _ := json.MarshalIndent(f, "", "  ")
		fmt.Println(string(b))
	}
	bad := 0
	for _, x := range f {
		mark := map[string]string{"ok": "✔", "warn": "!", "bad": "✘"}[x.State]
		if !asJSON {
			fmt.Printf("%s %s: %s\n", mark, x.Title, x.Detail)
			if x.Fix != "" {
				fmt.Printf("    → %s\n", x.Fix)
			}
		}
		if x.State == "bad" {
			bad++
		}
	}
	if !asJSON {
		if bad == 0 {
			fmt.Println("\nAlles in orde.")
		} else {
			fmt.Printf("\n%d punt(en) om op te lossen; begin bij de eerste ✘.\n", bad)
		}
	}
	if bad > 0 {
		return 1
	}
	return 0
}

// ---------------------------------------------------------------- status en test

func cmdStatus() int {
	if modeActive() {
		fmt.Printf("rzsense %s (Go-agent, doet het werk)\n", Version)
	} else {
		fmt.Printf("rzsense %s (Go-agent, proef naast de bash-agent)\n", Version)
	}
	if note := readState("takeover.last"); note != "" {
		fmt.Println("  Overnemen:   ", note)
	}
	if v := bashAgentVersion(); v != "" {
		fmt.Printf("  Bash-agent:   %s (%s)\n", v, map[bool]string{true: "staat uit, blijft geïnstalleerd", false: "doet het werk"}[modeActive()])
	} else {
		fmt.Println("  Bash-agent:   niet gevonden")
	}
	c, err := loadBashConfig()
	if err != nil {
		fmt.Println("  Instellingen:", err)
		return 1
	}
	fmt.Printf("  Instellingen: %s\n  Adres:        %s\n  Reserve:      %s\n  Vastgezet:    %v\n  Eigen sleutel: %v\n",
		bashConfPath(), dash(c.URL), dash(c.URLFallback), c.Pin != "", c.Key != "")
	// 0.9.0: ook zonder systemd (cron) goed melden
	if active, _ := systemctlActive("rzsense-shadow.timer"); active {
		fmt.Println("  Proef:        loopt (rzsense-shadow.timer, elke minuut)")
	} else if _, err := os.Stat("/etc/cron.d/rzsense-shadow"); err == nil {
		fmt.Println("  Proef:        loopt (cronjob /etc/cron.d/rzsense-shadow, elke minuut)")
	} else {
		fmt.Println("  Proef:        loopt niet (geen timer en geen cronjob)")
	}
	if last := readState("shadow.last"); last != "" {
		fmt.Println("  Laatste proefronde:", last)
	}
	if upd := readState("update.last"); upd != "" {
		fmt.Println("  Bijwerken:    ", upd)
	}
	return 0
}

func cmdTest() int {
	c, err := loadBashConfig()
	if err != nil {
		fmt.Fprintln(os.Stderr, "✘", err)
		return 1
	}
	if c.Key == "" {
		fmt.Fprintln(os.Stderr, "✘ Deze server heeft geen eigen sleutel. Koppel de agent met een koppelcode (RzSense → Agent Manager → 🔗 Koppelcode).")
		return 1
	}
	t0 := time.Now()
	name, ip, err := checkAgent(c)
	if err != nil {
		d, fix := explain(err)
		fmt.Fprintln(os.Stderr, "✘ "+d)
		if fix != "" {
			fmt.Fprintln(os.Stderr, "    → "+fix)
		}
		return 1
	}
	fmt.Printf("✔ RzSense (%s) herkent deze server als %q, vanaf %s, in %d ms. Het antwoord is versleuteld met de eigen sleutel (🔒).\n",
		c.URL, name, ip, time.Since(t0).Milliseconds())
	return 0
}

// ---------------------------------------------------------------- proefronde

type shadowReply struct {
	Takeover bool      `json:"takeover"` // 0.13.0: RzSense vraagt de Go-agent het werk over te nemen
	Update   *struct { // 0.12.0: RzSense heeft een nieuwere Go-agent voor deze processor
		Version string `json:"version"`
		SHA     string `json:"sha256"`
	} `json:"update"`
	OK    bool   `json:"ok"`
	Error string `json:"error"`
	Same  int    `json:"same"`
	N     int    `json:"n"`
	Diffs []struct {
		K    string `json:"k"`
		Bash string `json:"bash"`
		Go   string `json:"go"`
	} `json:"diffs"`
}

// cmdShadow: één proefronde. Meten, naar RzSense sturen (actie agent_shadow), en laten zien wat RzSense ervan vond.
// RzSense bewaart dit apart: het raakt de gegevens van de bash-agent niet.
func cmdShadow(quiet bool) int {
	c, err := loadBashConfig()
	if err != nil || c.Key == "" || c.URL == "" {
		msg := "geen eigen sleutel of adres gevonden; draai: sudo rzsense doctor"
		if err != nil {
			msg = err.Error()
		}
		writeState("shadow.last", time.Now().Format("2006-01-02 15:04:05")+" ✘ "+msg)
		fmt.Fprintln(os.Stderr, "✘", msg)
		return 1
	}
	t0 := time.Now()
	core := coreCollect(2 * time.Second)
	rep := map[string]any{"agent_version": Version, "os": "linux", "arch": goArch(), "bash_version": bashAgentVersion(), "core": core,
		"took_ms": time.Since(t0).Milliseconds(), "doctor": diagnoseBrief(), "update_last": readState("update.last"), "ready": takeoverReady(c)}
	plain, _ := json.Marshal(rep)
	body, code, err := sealedCall(c, "agent_shadow", plain, nil, 30*time.Second)
	var r shadowReply
	if err == nil && code == 200 {
		err = json.Unmarshal(body, &r)
	}
	stamp := time.Now().Format("2006-01-02 15:04:05")
	if err != nil || code != 200 || !r.OK {
		msg := fmt.Sprintf("HTTP %d: %s", code, shortErr(body))
		if err != nil {
			d, _ := explain(err)
			msg = d
		}
		writeState("shadow.last", stamp+" ✘ "+msg)
		fmt.Fprintln(os.Stderr, "✘ Proefronde niet aangekomen:", msg)
		return 1
	}
	line := fmt.Sprintf("%s ✔ %d van %d metingen gelijk aan de bash-agent", stamp, r.Same, r.N)
	writeState("shadow.last", line)
	if r.Update != nil {
		selfUpdateLinux(c, r.Update.Version, r.Update.SHA) // de volgende ronde draait de nieuwe versie
	} else if r.Takeover {
		if err := takeOver(c); err != nil {
			writeState("takeover.last", stamp+" overnemen niet gelukt: "+err.Error())
			fmt.Fprintln(os.Stderr, "✘ Overnemen niet gelukt:", err)
		} else if !quiet {
			fmt.Println("✔ Overgenomen: de Go-agent doet nu het werk; de bash-agent staat uit (niet weg).")
		}
	}
	if !quiet {
		fmt.Println(line)
		for _, d := range r.Diffs {
			fmt.Printf("    ≠ %s: bash %s · go %s\n", d.K, d.Bash, d.Go)
		}
		if r.N == 0 {
			fmt.Println("    (RzSense heeft nog geen recent rapport van de bash-agent om mee te vergelijken)")
		}
	}
	return 0
}

// diagnoseBrief: alleen wat niet in orde is, voor in RzSense (zonder de netwerktests: die zijn met dit verzoek al bewezen)
func diagnoseBrief() []finding {
	out := []finding{}
	if v := bashAgentVersion(); v == "" {
		out = append(out, finding{"bash", "warn", "Bash-agent", "niet gevonden of niet te starten", ""})
	}
	if active, known := systemctlActive("rzmon-agent.timer"); known && !active {
		if _, err := os.Stat("/etc/cron.d/rzmon-agent"); err != nil {
			out = append(out, finding{"timer", "bad", "Planning van de bash-agent", "rzmon-agent.timer loopt niet", "sudo systemctl enable --now rzmon-agent.timer"})
		}
	}
	return out
}
