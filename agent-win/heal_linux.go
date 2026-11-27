//go:build linux

package main

// Zelf herstellen (0.21.0), zoals de bash-agent (agent/src/40-basis-meten.sh "Zelf herstellen", 85-meesturen.sh en
// 90-versturen.sh). Per check in RzSense aan te zetten:
//   - een dienst die failed of inactive is, herstart de agent (systemctl restart); een container die gestopt is, start hij;
//   - een eigen herstelcommando voert hij alleen uit op verzoek van RzSense, in een versleuteld antwoord, en alleen een
//     bestand uit HEAL_DIR op de server zelf: uitvoerbaar, van root en niet door anderen te wijzigen. RzSense stuurt
//     alleen de naam; wat het commando doet, staat op de server.
// Hooguit één poging per 10 minuten en 3 per dag per check. HEAL=0 in de instellingen van de agent zet het uit.
//
// Alleen als de Go-agent het werk doet. De telling van de pogingen staat in dezelfde map als bij de bash-agent, zodat
// de grens van 3 per dag ook bij overnemen en teruggaan geldt.

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

var (
	healNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)
	healCtrRe  = regexp.MustCompile(`^([a-z_][a-z0-9_.-]*)/([A-Za-z0-9][A-Za-z0-9_.-]*)$`)
	healLineRe = regexp.MustCompile(`^\{"id":[0-9]+,"ok":[01],"t":[0-9]+,"m":"([^"\\]|\\.)*"\}$`)
	healWait   = 5 * time.Second // na een herstart even wachten voor de controle
)

func healDir() string { return sharedDir("heal") }

func init() {
	if os.Getenv("RZSENSE_TEST_FAST") == "1" { // alleen voor de tests: niet 5 seconden wachten na elke herstart
		healWait = 0
	}
}

// healOK: mag er nu een poging voor deze check? (hooguit 3 per dag, en 10 minuten na de vorige)
func healOK(id string) bool {
	now := time.Now().Unix()
	n, last := 0, int64(0)
	for _, l := range strings.Fields(readText(filepath.Join(healDir(), id+".tries"))) {
		t, err := strconv.ParseInt(l, 10, 64)
		if err != nil {
			continue
		}
		if t > now-86400 {
			n++
		}
		last = t
	}
	return n < 3 && now-last >= 600
}

// healLog: de poging onthouden; de uitkomst gaat mee met het volgende rapport
func healLog(id string, ok bool, msg string) {
	dir := healDir()
	now := time.Now().Unix()
	tries := append(strings.Fields(readText(filepath.Join(dir, id+".tries"))), fmt.Sprint(now))
	if len(tries) > 5 {
		tries = tries[len(tries)-5:]
	}
	_ = os.WriteFile(filepath.Join(dir, id+".tries"), []byte(strings.Join(tries, "\n")+"\n"), 0o600)
	m := cleanText(cutRunes(strings.NewReplacer("\n", " ", "\t", " ").Replace(msg), 200)) // eerst regeleindes en tabs naar spaties, dan pas opschonen
	mj, _ := json.Marshal(m)
	okN := 0
	if ok {
		okN = 1
	}
	if fh, err := os.OpenFile(filepath.Join(dir, "done"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
		fmt.Fprintf(fh, `{"id":%s,"ok":%d,"t":%d,"m":%s}`+"\n", id, okN, now, mj)
		fh.Close()
	}
}

func healEnabled(conf map[string]string) bool { return conf["HEAL"] != "0" && isRoot() }

// runHealLinux: de regels "id <tab> heal <tab> svc|ctr <tab> doel": herstarten wat stilligt, vóór de metingen van deze ronde
func runHealLinux() {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !strings.Contains(tests, "\theal\t") {
		return
	}
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if !healEnabled(conf) {
		return
	}
	var eng *ctrEngine
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 4 || f[1] != "heal" || !reDigits.MatchString(f[0]) {
			continue
		}
		id, kind, target := f[0], f[2], f[3]
		switch {
		case kind == "svc" && unitNameRe.MatchString(target) && haveCmd("systemctl"):
			st := svcState(target)
			if (st != "failed" && st != "inactive") || !healOK(id) {
				continue
			}
			if _, code := run(60*time.Second, "systemctl", "restart", target); code == 0 {
				time.Sleep(healWait)
			}
			if st2 := svcState(target); st2 == "active" {
				healLog(id, true, "service "+target+" was "+st+", herstart gelukt")
			} else {
				if st2 == "" {
					st2 = "onbekend"
				}
				healLog(id, false, "service "+target+" was "+st+", herstart mislukt (nu "+st2+")")
			}
		case kind == "ctr" && healCtrRe.MatchString(target):
			if eng == nil {
				eng = &ctrEngine{seen: map[string]bool{}}
				eng.contexts(conf)
			}
			if len(eng.ctxs) == 0 {
				continue
			}
			m := healCtrRe.FindStringSubmatch(target)
			ctx, name := m[1], m[2]
			if ctx != "root" && !userExists(ctx) {
				continue
			}
			state := func() string {
				out, _, _ := eng.pm(ctx, "inspect", "--format", "{{.State.Status}}", name)
				first, _, _ := strings.Cut(out, "\n")
				return strings.TrimSpace(first)
			}
			st := state()
			if (st != "exited" && st != "stopped" && st != "created" && st != "dead") || !healOK(id) {
				continue
			}
			if _, _, rc := eng.pm(ctx, "start", name); rc == 0 {
				time.Sleep(healWait)
			}
			if st2 := state(); st2 == "running" {
				healLog(id, true, "container "+name+" was "+st+", gestart")
			} else {
				if st2 == "" {
					st2 = "onbekend"
				}
				healLog(id, false, "container "+name+" was "+st+", starten mislukt (nu "+st2+")")
			}
		}
	}
}

func svcState(unit string) string {
	out, _ := run(15*time.Second, "systemctl", "is-active", unit)
	first, _, _ := strings.Cut(strings.TrimSpace(out), "\n")
	return first
}

func userExists(name string) bool {
	for _, l := range strings.Split(readText("/etc/passwd"), "\n") {
		if strings.HasPrefix(l, name+":") {
			return true
		}
	}
	_, code := run(5*time.Second, "id", "-u", name)
	return code == 0
}

// healCmdOK: alleen een gewoon bestand (geen symlink), uitvoerbaar, van root en niet door groep of anderen te wijzigen
func healCmdOK(path string) bool {
	fi, err := os.Lstat(path)
	if err != nil || !fi.Mode().IsRegular() || fi.Mode().Perm()&0o111 == 0 || fi.Mode().Perm()&0o022 != 0 {
		return false
	}
	st, ok := fi.Sys().(*syscall.Stat_t)
	return ok && st.Uid == 0
}

func healCmdDir(conf map[string]string) string {
	if d := conf["HEAL_DIR"]; d != "" {
		return d
	}
	return "/etc/rzmon-agent/heal.d"
}

// healReport: voor het rapport: de uitkomsten van herstelpogingen (hooguit 50, de rest blijft voor het volgende) en de
// namen van de herstelcommando's die op deze server staan
func healReport() (results []json.RawMessage, cmds []string) {
	dir := healDir()
	done, sending := filepath.Join(dir, "done"), filepath.Join(dir, "done.sending")
	if b, err := os.ReadFile(done); err == nil && len(b) > 0 {
		var keep []string
		for _, l := range strings.Split(string(b), "\n") {
			if healLineRe.MatchString(l) {
				keep = append(keep, l)
			}
		}
		_ = os.Remove(done)
		if len(keep) > 0 {
			if fh, err := os.OpenFile(sending, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
				_, _ = fh.WriteString(strings.Join(keep, "\n") + "\n")
				fh.Close()
			}
		}
	}
	for i, l := range lines(readText(sending)) {
		if i >= 50 {
			break
		}
		if healLineRe.MatchString(l) && json.Valid([]byte(l)) {
			results = append(results, json.RawMessage(l))
		}
	}
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if ents, err := os.ReadDir(healCmdDir(conf)); err == nil {
		for _, e := range ents {
			if healNameRe.MatchString(e.Name()) && healCmdOK(filepath.Join(healCmdDir(conf), e.Name())) {
				cmds = append(cmds, e.Name())
			}
		}
	}
	return results, cmds
}

// healSent: het rapport is wel of niet aangekomen. Aangekomen: de verstuurde uitkomsten weg, de rest (vanaf de 51e)
// terug in de wachtrij. Niet aangekomen: alles terug, de volgende keer opnieuw.
func healSent(ok bool) {
	dir := healDir()
	done, sending := filepath.Join(dir, "done"), filepath.Join(dir, "done.sending")
	ls := lines(readText(sending))
	if len(ls) == 0 {
		_ = os.Remove(sending)
		return
	}
	if ok {
		if len(ls) <= 50 {
			ls = nil
		} else {
			ls = ls[50:]
		}
	}
	if len(ls) > 0 {
		if fh, err := os.OpenFile(done, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
			_, _ = fh.WriteString(strings.Join(ls, "\n") + "\n")
			fh.Close()
		}
	}
	_ = os.Remove(sending)
}

// runHealCommands: de herstelcommando's waar RzSense in het (versleutelde) antwoord om vraagt: [[check-id, naam] …]
func runHealCommands(orders [][]any) {
	if len(orders) == 0 {
		return
	}
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if !healEnabled(conf) {
		return
	}
	for _, o := range orders {
		if len(o) != 2 {
			continue
		}
		idF, ok1 := o[0].(float64)
		name, ok2 := o[1].(string)
		if !ok1 || !ok2 || idF < 0 || idF != float64(int64(idF)) || !healNameRe.MatchString(name) {
			continue
		}
		id := strconv.FormatInt(int64(idF), 10)
		if !healOK(id) {
			continue
		}
		file := filepath.Join(healCmdDir(conf), name)
		if !healCmdOK(file) {
			healLog(id, false, "herstelcommando "+file+" niet gebruikt: bestaat niet, of is niet uitvoerbaar, niet van root of door anderen te wijzigen")
			continue
		}
		// Buiten de afscherming van de eigen dienst (zoals bij de bash-agent), met een grens van 120 seconden
		argv := []string{"timeout", "120", file}
		if underSystemd() {
			argv = append([]string{"systemd-run", "--quiet", "--collect", "--wait", "--pipe"}, argv...)
		}
		out, rc := run(150*time.Second, argv[0], argv[1:]...)
		msg := ""
		for _, l := range strings.Split(out, "\n") {
			if strings.TrimSpace(l) != "" {
				msg = cutRunes(l, 150)
				break
			}
		}
		tail := ""
		if msg != "" {
			tail = ": " + msg
		}
		switch {
		case rc == 0:
			healLog(id, true, "herstelcommando "+name+" uitgevoerd"+tail)
		case rc == 124 || rc == -1:
			healLog(id, false, "herstelcommando "+name+" duurde langer dan 120 seconden")
		default:
			healLog(id, false, fmt.Sprintf("herstelcommando %s mislukt (code %d)%s", name, rc, tail))
		}
	}
}
