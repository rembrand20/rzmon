//go:build linux

package main

// Updates (0.15.0), zoals de bash-agent (agent/src/66-updates.sh en pkg_upgrade in 75-…):
//   - eens per uur welke updates er klaarstaan en welke daarvan beveiligingsupdates zijn (dnf/yum check-update
//     [--security], of apt-get -s upgrade), en of een herstart nodig is;
//   - op verzoek van RzSense de updates installeren (alle, alleen beveiliging, of een lijst pakketnamen), met de
//     voortgang naar RzSense. Alleen het vaste commando van de pakketbeheerder, nooit een herstart, nooit op TrueNAS,
//     en niet als commando's op de server zijn uitgezet (COMMANDS=0).
//
// In de proef kijkt de Go-agent alleen (uit de cache van de pakketbeheerder, vlak nadat de bash-agent keek), zodat
// RzSense de twee lijsten kan vergelijken. Installeren doet hij alleen als hij het werk doet.

import (
	"bufio"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

var (
	pkgNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9.+_:~-]{0,99}$`)
	pkgIDRe   = regexp.MustCompile(`^[0-9a-f]{8,32}$`)
	hasDigit  = regexp.MustCompile(`[0-9]`)
	archTail  = regexp.MustCompile(`\.[^.]+$`)
	secWord   = regexp.MustCompile(`[Ss]ecurity`)
	aptVer    = regexp.MustCompile(`^[^(]*\(([^ ]*)`)

	dnfDownload = regexp.MustCompile(`^\(([0-9]+)/([0-9]+)\): ([^ ]+)`)
	dnfStep     = regexp.MustCompile(`^ +([A-Z][a-z]+( scriptlet)?) *: ([^ ]+).* ([0-9]+)/([0-9]+)$`)
	dnf5Step    = regexp.MustCompile(`^\[ *([0-9]+)/([0-9]+)\] (.*)$`)
)

// parseDnfUpdates: uitvoer van "dnf -q check-update": regels met 3 kolommen "naam.arch versie repo" → naam → versie
func parseDnfUpdates(out string) map[string]string {
	m := map[string]string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) == 3 && strings.Contains(f[0], ".") && hasDigit.MatchString(f[1]) {
			m[archTail.ReplaceAllString(f[0], "")+"\t"+f[1]] = f[1]
		}
	}
	return m
}

// dnfList: alle updates met erbij of het een beveiligingsupdate is: [[naam, versie, 0|1] …], gesorteerd
func dnfList(all, sec string) [][]any {
	secNames := map[string]bool{}
	for k := range parseDnfUpdates(sec) {
		secNames[strings.SplitN(k, "\t", 2)[0]] = true
	}
	keys := []string{}
	for k := range parseDnfUpdates(all) {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	list := [][]any{}
	for _, k := range keys {
		p := strings.SplitN(k, "\t", 2)
		s := 0
		if secNames[p[0]] {
			s = 1
		}
		list = append(list, []any{p[0], p[1], s})
	}
	return list
}

// aptList: uitvoer van "apt-get -s upgrade": "Inst naam [oud] (nieuw bron [arch])"; een bron met "security" is een
// beveiligingsupdate
func aptList(out string) [][]any {
	seen := map[string]bool{}
	keys := []string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if !strings.HasPrefix(l, "Inst ") || len(f) < 2 {
			continue
		}
		v := ""
		if m := aptVer.FindStringSubmatch(l); m != nil {
			v = m[1]
		} else {
			v = strings.Fields(l)[0] // geen haakje: awk laat dan het eerste woord over
		}
		s := "0"
		if secWord.MatchString(l) {
			s = "1"
		}
		k := f[1] + "\t" + v + "\t" + s
		if !seen[k] {
			seen[k] = true
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	list := [][]any{}
	for _, k := range keys {
		p := strings.Split(k, "\t")
		list = append(list, []any{p[0], p[1], atoiDef(p[2], 0)})
	}
	return list
}

// runOutside: een commando van de pakketbeheerder buiten de afscherming van de eigen dienst (die mag bijna nergens
// schrijven): via een tijdelijke systemd-eenheid. Zonder systemd gewoon rechtstreeks.
func runOutside(timeout time.Duration, name string, args ...string) (string, int) {
	if _, err := exec.LookPath(name); err != nil {
		return "", -1
	}
	if os.Getenv("INVOCATION_ID") != "" {
		if _, err := exec.LookPath("systemd-run"); err == nil {
			a := append([]string{"--quiet", "--collect", "--wait", "--pipe", "-p", "Nice=19", "--setenv=LC_ALL=C", name}, args...)
			return run(timeout, "systemd-run", a...)
		}
	}
	return run(timeout, name, args...)
}

func isTrueNAS() bool {
	_, err := exec.LookPath("midclt")
	return err == nil || strings.Contains(strings.ToLower(readText("/etc/version")), "truenas")
}

// availableUpdates: wat er klaarstaat. cacheOnly (de proef): niets verversen, alleen wat de pakketbeheerder al weet.
// ok = false: de pakketbeheerder gaf een fout; dan valt er niets te melden.
func availableUpdates(cacheOnly bool) (map[string]any, bool) {
	var list [][]any
	mgr, rb := "", 0
	dnf := ""
	for _, n := range []string{"dnf", "yum"} {
		if _, err := exec.LookPath(n); err == nil {
			dnf = n
			break
		}
	}
	_, aptErr := exec.LookPath("apt-get")
	switch {
	case dnf != "":
		mgr = "rpm"
		pre := []string{"-q"}
		if cacheOnly {
			pre = append(pre, "-C")
		}
		all, code := runOutside(180*time.Second, dnf, append(pre, "check-update")...)
		if code != 0 && code != 100 { // 100 = er staan updates klaar
			return nil, false
		}
		sec, code := runOutside(180*time.Second, dnf, append(pre, "check-update", "--security")...)
		if code != 0 && code != 100 {
			sec, _ = runOutside(180*time.Second, dnf, append(pre, "check-upgrade", "--security")...)
		}
		list = dnfList(all, sec)
		if _, code := runOutside(60*time.Second, "needs-restarting", "-r"); code == 1 {
			rb = 1
		}
	case aptErr == nil:
		mgr = "dpkg"
		if st, err := os.Stat("/var/lib/apt/lists"); !cacheOnly && (err != nil || time.Since(st.ModTime()) > 6*time.Hour) {
			runOutside(300*time.Second, "apt-get", "update", "-qq")
		}
		out, code := runOutside(120*time.Second, "apt-get", "-s", "-o", "Debug::NoLocking=1", "upgrade")
		if code != 0 {
			return nil, false
		}
		list = aptList(out)
		if _, err := os.Stat("/var/run/reboot-required"); err == nil {
			rb = 1
		}
	default:
		return nil, false
	}
	sec := 0
	for _, e := range list {
		sec += e[2].(int)
	}
	n := len(list)
	if len(list) > 300 {
		list = list[:300]
	}
	return map[string]any{"mgr": mgr, "avail": n, "sec": sec, "rb": rb, "at": time.Now().Unix(), "list": list}, true
}

func bashPatchFile() string {
	if p := os.Getenv("RZSENSE_BASH_PATCH"); p != "" { // alleen voor de tests
		return p
	}
	return "/var/cache/rzmon-agent/patch/avail.json"
}

func patchWanted() bool {
	return os.Geteuid() == 0 && !isTrueNAS() && strings.Contains("\n"+readText(filepath.Join(bashCachePath(), "tests.cache")), "\n0\tpatch\t1")
}

// collectPatchLinux: het onderdeel "patch" van het rapport, of nil als er nu niets nieuws te melden is.
// Als de Go-agent het werk doet: eens per uur, en meteen na het installeren. In de proef: vlak nadat de bash-agent keek.
func collectPatchLinux(active bool) map[string]any {
	if !patchWanted() {
		return nil
	}
	last := int64(atoiDef(readState("patch.at"), 0))
	now := time.Now().Unix()
	if active {
		if now-last < 3600 && last <= now {
			return nil
		}
	} else {
		st, err := os.Stat(bashPatchFile())
		if err != nil || st.ModTime().Unix() <= last || now-st.ModTime().Unix() > 900 {
			return nil
		}
	}
	p, ok := availableUpdates(!active)
	writeState("patch.at", fmt.Sprint(now)) // ook na een fout: niet elke minuut opnieuw proberen
	if !ok {
		return nil
	}
	return p
}

// ---------------------------------------------------------------- installeren

type pkgOrder struct {
	ID   string   `json:"id"`
	Pkgs []string `json:"pkgs"`
	Mode string   `json:"mode"`
	Own  bool     `json:"own"`
}

func upgradeResultFile() string { return filepath.Join(dataDir(), "upgrade.result") }

func writeUpgradeResult(id string, ok bool, log string) {
	st := "error"
	if ok {
		st = "ok"
	}
	tmp := upgradeResultFile() + ".tmp"
	if os.WriteFile(tmp, []byte(id+"\t"+st+"\t"+base64.StdEncoding.EncodeToString([]byte(log))+"\n"), 0o600) == nil {
		_ = os.Rename(tmp, upgradeResultFile())
	}
}

// pkgResult: de uitkomst van het laatste installeren, voor het rapport ("pkg_result"), of nil
func pkgResult() map[string]any {
	f := strings.Split(strings.TrimRight(readText(upgradeResultFile()), "\n"), "\t")
	if len(f) != 3 || !pkgIDRe.MatchString(f[0]) {
		return nil
	}
	return map[string]any{"id": f[0], "ok": f[1] == "ok", "log_b64": f[2]}
}

// upgradeArgs: de opdracht nalopen. Geeft de modus en de geldige pakketnamen, of de reden waarom het niet mag.
func upgradeArgs(o pkgOrder, conf map[string]string) (mode string, names []string, why string) {
	mode = "upgrade"
	if o.Mode == "security" || o.Mode == "all" || o.Mode == "install" {
		mode = o.Mode
	}
	for _, p := range o.Pkgs {
		if pkgNameRe.MatchString(p) {
			names = append(names, p)
		}
	}
	switch conf["COMMANDS"] {
	case "0", "off", "no", "nee", "nobody":
		why = "geblokkeerd op de server (COMMANDS=" + conf["COMMANDS"] + " in /etc/default/rzmon-agent)"
	}
	switch {
	case mode == "install":
		why = "de Go-agent installeert geen nieuwe pakketten (alleen updates)"
	case os.Geteuid() != 0:
		why = "de agent draait niet als root"
	case len(names) == 0 && mode == "upgrade":
		why = "geen geldige pakketnamen ontvangen"
	case isTrueNAS():
		why = "TrueNAS: bijwerken via de TrueNAS-interface (System → Update), nooit met apt"
	}
	return mode, names, why
}

// startUpgrade: de opdracht van RzSense uitvoeren, los van deze ronde (het kan minuten duren)
func startUpgrade(o pkgOrder) {
	if !pkgIDRe.MatchString(o.ID) {
		return
	}
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	mode, names, why := upgradeArgs(o, conf)
	if why != "" {
		writeUpgradeResult(o.ID, false, why)
		return
	}
	// Loopt er al een (minder dan 30 minuten geleden gestart)? Dan niet nog een.
	lock := filepath.Join(dataDir(), "upgrade.lock")
	if st, err := os.Stat(lock); err == nil && time.Since(st.ModTime()) < 30*time.Minute {
		return
	}
	_ = os.WriteFile(lock, nil, 0o600)
	self, err := os.Executable()
	if _, e2 := exec.LookPath("systemd-run"); err != nil || e2 != nil {
		writeUpgradeResult(o.ID, false, "systemd-run ontbreekt: de Go-agent kan de updates niet los van zijn eigen ronde starten")
		_ = os.Remove(lock)
		return
	}
	args := append([]string{"--quiet", "--collect", fmt.Sprintf("--unit=rzsense-pkg-upgrade-%d", time.Now().Unix()),
		"--setenv=RZSENSE_DATA=" + dataDir(), self, "pkg-upgrade", o.ID, mode}, names...)
	if out, code := run(20*time.Second, "systemd-run", args...); code != 0 {
		writeUpgradeResult(o.ID, false, "systemd-run mislukt: "+oneLine(out))
		_ = os.Remove(lock)
	}
}

// upgradeCommand: het vaste commando van de pakketbeheerder voor deze modus
func upgradeCommand(mode string, names []string) (cmd []string, apt bool) {
	for _, n := range []string{"dnf", "yum"} {
		if _, err := exec.LookPath(n); err != nil {
			continue
		}
		verb := map[string]string{"dnf": "upgrade", "yum": "update"}[n]
		switch mode {
		case "security":
			return []string{n, "-y", verb, "--security"}, false
		case "all":
			return []string{n, "-y", verb}, false
		}
		return append([]string{n, "-y", verb}, names...), false
	}
	base := []string{"-y", "-q", "-o", "Dpkg::Options::=--force-confold", "-o", "APT::Status-Fd=3"}
	if mode == "all" {
		return append([]string{"apt-get", "upgrade"}, base...), true
	}
	return append(append([]string{"apt-get", "install", "--only-upgrade"}, base...), names...), true
}

// cmdPkgUpgrade: rzsense pkg-upgrade <id> <mode> [pakketten…]: draait in een eigen tijdelijke systemd-eenheid
func cmdPkgUpgrade(args []string) int {
	if len(args) < 2 || !pkgIDRe.MatchString(args[0]) {
		return 2
	}
	id, mode, names := args[0], args[1], []string{}
	for _, p := range args[2:] {
		if pkgNameRe.MatchString(p) {
			names = append(names, p)
		}
	}
	lock := filepath.Join(dataDir(), "upgrade.lock")
	defer os.Remove(lock)
	c, _ := loadBashConfig()
	var log strings.Builder
	stage, pct, detail, count := "update", 2, "", ""
	last := time.Time{}
	tail := func(n int) string {
		s := log.String()
		if len(s) > n {
			s = s[len(s)-n:]
		}
		return s
	}
	post := func(done bool, ok bool) {
		last = time.Now()
		if c == nil || c.Key == "" {
			return
		}
		m := map[string]any{"id": id, "stage": stage, "pct": pct, "detail": cutRunes(cleanText(detail), 120), "count": count,
			"log_b64": base64.StdEncoding.EncodeToString([]byte(tail(map[bool]int{false: 800, true: 1500}[done])))}
		if done {
			m["done"], m["ok"] = true, ok
		}
		b, _ := json.Marshal(m)
		_, _, _ = sealedCall(c, "agent_pkg_progress", b, nil, 4*time.Second)
	}
	tick := func(s string) {
		if s != stage {
			stage = s
			post(false, false)
		} else if time.Since(last) >= 2*time.Second {
			post(false, false)
		}
	}
	post(false, false)
	os.Setenv("LC_ALL", "C")
	os.Setenv("DEBIAN_FRONTEND", "noninteractive")
	cmdline, apt := upgradeCommand(mode, names)
	rc := 0
	if apt {
		out, code := run(10*time.Minute, "apt-get", "update", "-q")
		log.WriteString(out)
		rc = code
		if rc == 0 && mode == "security" { // alleen wat uit een security-bron komt
			sim, _ := run(2*time.Minute, "apt-get", "-s", "upgrade")
			names = nil
			for _, e := range aptList(sim) {
				if e[2].(int) == 1 {
					names = append(names, e[0].(string))
				}
			}
			if len(names) == 0 {
				log.WriteString("Geen beveiligingsupdates beschikbaar\n")
				cmdline = nil
			} else {
				cmdline, _ = upgradeCommand("upgrade", names)
			}
		}
		if rc == 0 {
			stage, pct = "download", 15
			post(false, false)
		}
	}
	if rc == 0 && cmdline != nil {
		cmd := exec.Command(cmdline[0], cmdline[1:]...)
		pr, pw := io.Pipe()
		cmd.Stdout, cmd.Stderr = pw, pw
		var statusR *os.File
		if apt { // apt meldt het percentage zelf op descriptor 3 (dlstatus = downloaden, pmstatus = installeren)
			r, w, err := os.Pipe()
			if err == nil {
				cmd.ExtraFiles = []*os.File{w}
				statusR = r
				defer w.Close()
			}
		}
		if err := cmd.Start(); err != nil {
			log.WriteString(err.Error() + "\n")
			rc = 1
		} else {
			if statusR != nil {
				cmd.ExtraFiles[0].Close()
				go func() {
					sc := bufio.NewScanner(statusR)
					for sc.Scan() {
						f := strings.SplitN(sc.Text(), ":", 4)
						if len(f) < 3 {
							continue
						}
						p, err := strconv.Atoi(strings.SplitN(f[2], ".", 2)[0])
						if err != nil {
							continue
						}
						switch f[0] {
						case "dlstatus":
							pct, detail, count = 15+30*p/100, "", ""
							tick("download")
						case "pmstatus":
							pct, detail, count = 45+53*p/100, f[1], ""
							tick("install")
						}
					}
				}()
			}
			done := make(chan error, 1)
			go func() { done <- cmd.Wait(); pw.Close() }()
			sc := bufio.NewScanner(pr)
			sc.Buffer(make([]byte, 0, 64<<10), 1<<20)
			for sc.Scan() {
				l := sc.Text()
				log.WriteString(l + "\n")
				if apt {
					continue
				}
				if m := dnfDownload.FindStringSubmatch(l); m != nil { // (3/7): openssl-libs-3.2.2.rpm
					a, b := atoiDef(m[1], 0), max(atoiDef(m[2], 1), 1)
					pct, detail, count = 15+30*a/b, m[3], m[1]+"/"+m[2]
					tick("download")
				} else if m := dnfStep.FindStringSubmatch(l); m != nil { //   Upgrading : openssl 3/14
					a, b := atoiDef(m[4], 0), max(atoiDef(m[5], 1), 1)
					detail, count = m[3], m[4]+"/"+m[5]
					if m[1] == "Verifying" {
						pct = 90 + 8*a/b
						tick("verify")
					} else {
						pct = 45 + 45*a/b
						tick("install")
					}
				} else if m := dnf5Step.FindStringSubmatch(l); m != nil { // dnf5: [ 3/14] Upgrading openssl
					a, b := atoiDef(m[1], 0), max(atoiDef(m[2], 1), 1)
					pct, detail, count = 45+50*a/b, m[3], m[1]+"/"+m[2]
					tick("install")
				}
			}
			if err := <-done; err != nil {
				rc = 1
			}
		}
	}
	writeUpgradeResult(id, rc == 0, tail(1400))
	dropState("patch.at") // de volgende ronde kijkt meteen opnieuw wat er nog klaarstaat
	stage, pct, detail, count = "done", 100, "", ""
	post(true, rc == 0)
	return rc
}
