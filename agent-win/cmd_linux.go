//go:build linux

package main

// Commando-checks (0.18.0), zoals de bash-agent (agent/src/50-services-commandos-logs.sh). Regels in de testlijst:
//   id <tab> cmd <tab> commando (base64) <tab> time-out <tab> nobody|root <tab> interval
// RzSense stuurt ze alleen als commando-checks daar aan staan (standaard uit), en alleen versleuteld met de eigen sleutel
// van de server. De agent voert het commando uit in een kale omgeving, met een time-out, standaard als nobody; uitvoer en
// exitcode gaan naar RzSense, dat ze vergelijkt met het verwachte antwoord.
//
// De noodrem op de server zelf blijft: COMMANDS=0 (of off/no/nee) in /etc/default/rzmon-agent blokkeert alles,
// COMMANDS=nobody staat nooit root toe.
//
// Alleen als de Go-agent het werk doet. In de proef voert hij geen commando's uit: dan zou elk commando twee keer lopen.
//
// Zoals de bash-agent: de eerste keer wacht de ronde op het commando; daarna meet de agent op de achtergrond en gaat
// het vorige resultaat mee, zodat een traag commando het rapport niet ophoudt. Alle commando's samen hooguit ~40 s.

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

type cmdCheck struct {
	id      string
	cmd     string // leeg = ongeldig ontvangen
	timeout int
	runas   string
	every   int
}

// parseCmdChecks: de regels "cmd" uit de testlijst
func parseCmdChecks(tests string) []cmdCheck {
	var out []cmdCheck
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[1] != "cmd" || !reDigits.MatchString(f[0]) {
			continue
		}
		for len(f) < 6 {
			f = append(f, "")
		}
		c := cmdCheck{id: f[0], timeout: 10, runas: "nobody", every: 60}
		if n, err := strconv.Atoi(f[3]); err == nil && reDigits.MatchString(f[3]) && n >= 1 && n <= 60 {
			c.timeout = n
		}
		if f[4] == "root" {
			c.runas = "root"
		}
		if reDigits.MatchString(f[5]) {
			c.every = atoiDef(f[5], 60)
		}
		if b, err := base64.StdEncoding.DecodeString(f[2]); err == nil {
			c.cmd = string(b)
		}
		out = append(out, c)
	}
	return out
}

// jsonText: uitvoer van een commando zoals de bash-agent hem doorgeeft: de eerste max bytes, geldige UTF-8, zonder
// stuurtekens (tab en regeleinde blijven), zonder het laatste regeleinde
func jsonText(b []byte, max int) string {
	if len(b) > max {
		b = b[:max]
	}
	s := strings.ToValidUTF8(string(b), "")
	s = strings.Map(func(r rune) rune {
		if r < 0x20 && r != '\t' && r != '\n' {
			return -1
		}
		return r
	}, s)
	return strings.TrimSuffix(s, "\n")
}

func cmdDir() string { return filepath.Join(dataDir(), "cmdstate") }

// cmdArgv: het vaste omhulsel om het commando: kale omgeving, eventueel als nobody, met een time-out
func cmdArgv(c cmdCheck) ([]string, string) {
	argv := []string{"env", "-i", "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "HOME=/", "LANG=C.UTF-8"}
	if c.runas == "nobody" && os.Geteuid() == 0 {
		switch {
		case haveCmd("runuser"):
			argv = append(argv, "runuser", "-u", "nobody", "--")
		case haveCmd("setpriv"):
			argv = append(argv, "setpriv", "--reuid=nobody", "--regid=nogroup", "--init-groups", "--")
		default:
			return nil, "kan niet als nobody uitvoeren (runuser ontbreekt)"
		}
	}
	return append(argv, "timeout", "-k", "2", strconv.Itoa(c.timeout), "/bin/bash", "-c", c.cmd), ""
}

// cmdExec: het commando uitvoeren; uitvoer naar <id>.out en <id>.err. Geeft de exitcode en de duur in ms.
// outside: buiten de afscherming van de eigen dienst (een tijdelijke systemd-eenheid), zoals bij de bash-agent.
func cmdExec(c cmdCheck, argv []string, outside bool) (rc int, ms int64) {
	dir := cmdDir()
	outF, e1 := os.OpenFile(filepath.Join(dir, c.id+".out"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	errF, e2 := os.OpenFile(filepath.Join(dir, c.id+".err"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if e1 != nil || e2 != nil {
		return 126, 0
	}
	defer outF.Close()
	defer errF.Close()
	if outside {
		argv = append([]string{"systemd-run", "--quiet", "--collect", "--wait", "--pipe", "-p", "WorkingDirectory=/"}, argv...)
	}
	cmd := exec.Command(argv[0], argv[1:]...)
	cmd.Dir = "/"
	cmd.Stdout, cmd.Stderr = outF, errF
	t0 := time.Now()
	err := cmd.Run()
	ms = time.Since(t0).Milliseconds()
	if err == nil {
		return 0, ms
	}
	if ee, ok := err.(*exec.ExitError); ok {
		if ee.ExitCode() >= 0 {
			return ee.ExitCode(), ms
		}
		if ws, ok := ee.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
			return 128 + int(ws.Signal()), ms
		}
	}
	return 127, ms
}

// cmdState: uitvoer en exitcode als JSON in <id>.json (dat gaat mee met elk rapport tot de volgende meting)
func cmdState(id string, rc int, ms int64, runas string, at int64) {
	dir := cmdDir()
	out, _ := os.ReadFile(filepath.Join(dir, id+".out"))
	errB, _ := os.ReadFile(filepath.Join(dir, id+".err"))
	b, _ := json.Marshal(map[string]any{"rc": rc, "out": jsonText(out, 4096), "err": jsonText(errB, 500), "ms": ms, "as": runas, "at": at})
	tmp := filepath.Join(dir, id+".json.tmp")
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, filepath.Join(dir, id+".json"))
	}
	_ = os.Remove(filepath.Join(dir, id+".out"))
	_ = os.Remove(filepath.Join(dir, id+".err"))
}

func underSystemd() bool { return os.Getenv("INVOCATION_ID") != "" && haveCmd("systemd-run") }

// collectCmdLinux: het onderdeel "cmd" van het rapport: per check het laatste resultaat, of waarom er geen is
func collectCmdLinux() map[string]any {
	checks := parseCmdChecks(readText(filepath.Join(bashCachePath(), "tests.cache")))
	if len(checks) == 0 {
		return nil
	}
	conf, _ := readShellConf(bashConfPath())
	dir := cmdDir()
	_ = os.MkdirAll(dir, 0o700)
	res := map[string]any{}
	budget := time.Now().Add(40 * time.Second)
	for _, c := range checks {
		switch conf["COMMANDS"] {
		case "0", "off", "no", "nee":
			res[c.id] = map[string]any{"blocked": "COMMANDS=0 in de instellingen van de agent"}
			continue
		case "nobody":
			c.runas = "nobody"
		}
		state := filepath.Join(dir, c.id+".json")
		// Klaar op de achtergrond: dat resultaat wordt nu het laatste
		if f := strings.Fields(readText(filepath.Join(dir, c.id+".done"))); len(f) == 3 {
			rc, e1 := strconv.Atoi(f[0])
			ms, e2 := strconv.ParseInt(f[1], 10, 64)
			at, e3 := strconv.ParseInt(f[2], 10, 64)
			if e1 == nil && e2 == nil && e3 == nil {
				cmdState(c.id, rc, ms, c.runas, at)
				_ = os.Remove(filepath.Join(dir, c.id+".done"))
			}
		}
		last := func() any {
			b, _ := os.ReadFile(state)
			if json.Valid(b) {
				return json.RawMessage(b)
			}
			return map[string]any{"error": "geen resultaat"}
		}
		st, err := os.Stat(state)
		have := err == nil && st.Size() > 0
		if have && time.Since(st.ModTime()) < time.Duration(c.every-5)*time.Second {
			res[c.id] = last()
			continue
		}
		if time.Now().After(budget) {
			res[c.id] = map[string]any{"error": "overgeslagen: de andere commando's duurden samen te lang"}
			continue
		}
		if c.cmd == "" {
			res[c.id] = map[string]any{"error": "ongeldig commando ontvangen"}
			continue
		}
		argv, why := cmdArgv(c)
		if why != "" {
			res[c.id] = map[string]any{"error": why}
			continue
		}
		// Is er al een vorig resultaat, dan opnieuw meten op de achtergrond; nu gaat het vorige mee
		if have {
			lock := filepath.Join(dir, c.id+".lock")
			if ls, err := os.Stat(lock); err != nil || time.Since(ls.ModTime()) >= time.Duration(c.timeout+60)*time.Second {
				_ = os.WriteFile(lock, nil, 0o600)
				if !cmdBackground(c.id) {
					_ = os.Remove(lock)
				}
			}
			res[c.id] = last()
			continue
		}
		rc, ms := cmdExec(c, argv, underSystemd())
		cmdState(c.id, rc, ms, c.runas, time.Now().Unix())
		res[c.id] = last()
	}
	return res
}

// cmdBackground: "rzsense cmd-run <id>" los van deze ronde starten
func cmdBackground(id string) bool {
	self, err := os.Executable()
	if err != nil {
		return false
	}
	if underSystemd() {
		_, code := run(15*time.Second, "systemd-run", "--quiet", "--collect", fmt.Sprintf("--unit=rzsense-cmd-%s-%d", id, time.Now().Unix()),
			"--setenv=RZSENSE_DATA="+dataDir(), self, "cmd-run", id)
		return code == 0
	}
	cmd := exec.Command(self, "cmd-run", id)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if cmd.Start() != nil {
		return false
	}
	go func() { _ = cmd.Wait() }()
	return true
}

// cmdRunOne: rzsense cmd-run <id>: het commando van deze check (uit de testlijst, niet van de opdrachtregel) één keer
// uitvoeren en de uitkomst klaarzetten voor het volgende rapport
func cmdRunOne(id string) int {
	dir := cmdDir()
	defer os.Remove(filepath.Join(dir, id+".lock"))
	if !modeActive() {
		return 1
	}
	conf, _ := readShellConf(bashConfPath())
	for _, c := range parseCmdChecks(readText(filepath.Join(bashCachePath(), "tests.cache"))) {
		if c.id != id || c.cmd == "" {
			continue
		}
		switch conf["COMMANDS"] {
		case "0", "off", "no", "nee":
			return 1
		case "nobody":
			c.runas = "nobody"
		}
		argv, why := cmdArgv(c)
		if why != "" {
			return 1
		}
		rc, ms := cmdExec(c, argv, false)
		tmp := filepath.Join(dir, id+".done.tmp")
		if os.WriteFile(tmp, []byte(fmt.Sprintf("%d %d %d\n", rc, ms, time.Now().Unix())), 0o600) == nil {
			_ = os.Rename(tmp, filepath.Join(dir, id+".done"))
		}
		return 0
	}
	return 1
}
