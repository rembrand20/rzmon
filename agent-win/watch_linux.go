//go:build linux

package main

// De watcher (0.30.0): Realtime en de endpoint-sensoren, zoals rzmon-watch van de bash-agent (agent/src/15-realtime.sh).
// Hij doet zelf geen analyse: hij let alleen op en start bij een gebeurtenis meteen een gewone ronde, die alles verwerkt
// en die RzSense direct beoordeelt. Hooguit één extra ronde per 10 seconden (met de endpoint-sensoren: per 5).
//   - systemlog (journalctl -f, of het eerste logbestand uit AUTH_LOGS): logins, sudo, su, en meldingen van systemd over
//     de diensten die RzSense bewaakt;
//   - bestanden (inotifywait): de 🔴-paden van "Bestanden bewaken";
//   - endpoint-sensoren: auditd, netwerk, bestanden en containers (edr_linux.go).
// De watcher werkt alleen als de Go-agent het werk heeft overgenomen; in de proef blijft die van de bash-agent draaien.

import (
	"bufio"
	"context"
	"encoding/base64"
	"fmt"
	"hash/crc32"
	"io"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"regexp"
	"strings"
	"sync/atomic"
	"syscall"
	"time"
)

const watchUnit = "rzsense-watch.service"
const bashWatch = "rzmon-watch.service"

var (
	watchSigRe  = regexp.MustCompile(`^(0\t(realtime|fim|edr|edrself)|[0-9]+\tsvc)\t`)
	watchUnitRe = regexp.MustCompile(`^[A-Za-z0-9@._:-]+$`)
)

func watchStatus(state, j, i, e string) {
	if e == "" {
		e = "-"
	}
	_ = os.WriteFile(filepath.Join(dataDir(), "watch.state"), []byte(fmt.Sprintf("%s %d %s %s %s\n", state, time.Now().Unix(), j, i, e)), 0o600)
}

// watchSig: verandert als de instellingen voor de watcher in RzSense veranderen
func watchSig(tests string) uint32 {
	var b strings.Builder
	for _, l := range strings.Split(tests, "\n") {
		if watchSigRe.MatchString(l) {
			b.WriteString(l + "\n")
		}
	}
	return crc32.ChecksumIEEE([]byte(b.String()))
}

// watchPattern: de regels uit het systemlog waarbij meteen een ronde start
func watchPattern(tests string) *regexp.Regexp {
	pat := `(sshd|sshd-session)\[[0-9]+\]: (Accepted|Failed|Invalid user|Connection closed by authenticating)|sudo(\[[0-9]+\])?: |[ :]su(\[[0-9]+\])?: |login\[[0-9]+\]: `
	units := []string{}
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[1] == "svc" && watchUnitRe.MatchString(f[2]) {
			units = append(units, strings.ReplaceAll(f[2], ".", `\.`))
		}
	}
	if len(units) > 0 {
		pat += `|systemd\[1\]: .*(` + strings.Join(units, "|") + `)(\.service)?[: ]`
	}
	re, err := regexp.Compile(pat)
	if err != nil {
		re = regexp.MustCompile(`sshd\[[0-9]+\]: `)
	}
	return re
}

// watchFimRoots: de 🔴-paden uit "Bestanden bewaken" (bij jokertekens: de map ervoor), alleen die bestaan
func watchFimRoots(tests string) []string {
	roots := []string{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[0] != "0" || f[1] != "fim" || f[2] == "" {
			continue
		}
		raw, err := base64.StdEncoding.DecodeString(f[2])
		if err != nil {
			break
		}
		for _, r := range strings.Split(string(raw), "\n") {
			p := tabRead(r, 3)
			if p[0] != "p" || p[1] != "2" {
				continue
			}
			base := p[2]
			if i := strings.IndexAny(base, "*?["); i >= 0 {
				base = base[:i]
				if j := strings.LastIndex(base, "/"); j >= 0 {
					base = base[:j]
				} else {
					base = ""
				}
			}
			if base == "" {
				base = "/"
			}
			if _, err := os.Stat(base); err == nil {
				roots = append(roots, base)
			}
		}
		break
	}
	return roots
}

// follow: een commando starten en elke regel van zijn uitvoer doorgeven; dead gaat aan als het stopt
func follow(ctx context.Context, dead *atomic.Bool, onLine func(string), name string, args ...string) bool {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error { return syscall.Kill(-cmd.Process.Pid, syscall.SIGTERM) }
	out, err := cmd.StdoutPipe()
	if err != nil || cmd.Start() != nil {
		return false
	}
	go func() {
		sc := bufio.NewScanner(out)
		sc.Buffer(make([]byte, 256*1024), 4*1024*1024)
		for sc.Scan() {
			onLine(sc.Text())
		}
		_ = cmd.Wait()
		if dead != nil && ctx.Err() == nil {
			dead.Store(true)
		}
	}()
	return true
}

// tailFollow: nieuwe regels van een bestand volgen (zoals tail -n 0 -F): ook na roteren of opnieuw aanmaken
func tailFollow(ctx context.Context, path string, onLine func(string)) {
	var fh *os.File
	var ino uint64
	var pos int64
	open := func(atEnd bool) {
		if fh != nil {
			fh.Close()
			fh = nil
		}
		f, err := os.Open(path)
		if err != nil {
			return
		}
		st, err := f.Stat()
		if err != nil {
			f.Close()
			return
		}
		fh, ino, pos = f, st.Sys().(*syscall.Stat_t).Ino, 0
		if atEnd {
			pos = st.Size()
		}
	}
	open(true)
	rest := ""
	buf := make([]byte, 64*1024)
	drain := func() { // alles lezen wat er sinds de vorige keer bij kwam
		for fh != nil {
			n, err := fh.ReadAt(buf, pos)
			if n > 0 {
				pos += int64(n)
				parts := strings.Split(rest+string(buf[:n]), "\n")
				rest = parts[len(parts)-1]
				for _, l := range parts[:len(parts)-1] {
					onLine(l)
				}
			}
			if err != nil || n == 0 {
				if err != nil && err != io.EOF {
					fh.Close()
					fh = nil
				}
				return
			}
		}
	}
	for {
		select {
		case <-ctx.Done():
			if fh != nil {
				fh.Close()
			}
			return
		case <-time.After(time.Second):
		}
		st, err := os.Stat(path)
		if err != nil {
			drain()
			continue
		}
		if fh == nil || st.Sys().(*syscall.Stat_t).Ino != ino || st.Size() < pos {
			if fh != nil && st.Sys().(*syscall.Stat_t).Ino != ino {
				drain() // geroteerd: eerst het oude bestand uitlezen
			}
			open(false) // geroteerd, afgekapt of nieuw: vanaf het begin
			rest = ""
		}
		drain()
	}
}

// cmdWatch: rzsense watch: de dienst die oplet en rondes start
func cmdWatch() int {
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	stopAll := make(chan struct{})
	go func() { <-sig; close(stopAll) }()
	sleep := func(d time.Duration) bool { // false = stoppen
		select {
		case <-stopAll:
			return false
		case <-time.After(d):
			return true
		}
	}
	self, err := os.Executable()
	if err != nil {
		return 1
	}
	for {
		tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
		conf, _ := readShellConf(bashConfPath())
		rtOn := conf["REALTIME"] != "0" && hasLinePrefix(tests, "0\trealtime\t1")
		edrOn := isRoot() && edrWanted(tests, conf)
		if !modeActive() || (!rtOn && !edrOn) {
			watchStatus("off", "-", "-", "-")
			if !sleep(60 * time.Second) {
				return 0
			}
			continue
		}
		wsig := watchSig(tests)
		ctx, cancel := context.WithCancel(context.Background())
		wake := make(chan struct{}, 1)
		poke := func() {
			select {
			case wake <- struct{}{}:
			default:
			}
		}
		var dead atomic.Bool
		j, i, e := "-", "-", "-"
		if rtOn {
			pat := watchPattern(tests)
			onLog := func(l string) {
				if pat.MatchString(l) {
					poke()
				}
			}
			if st, err := os.Stat("/run/systemd/journal"); err == nil && st.IsDir() && haveCmd("journalctl") {
				if follow(ctx, &dead, onLog, "journalctl", "-f", "-n", "0", "-q", "-o", "short") {
					j = "ok"
				}
			} else {
				logs := conf["AUTH_LOGS"]
				if logs == "" {
					logs = "/var/log/auth.log /var/log/secure"
				}
				for _, f := range strings.Fields(logs) {
					if readable(f) {
						go tailFollow(ctx, f, onLog)
						j = "ok"
						break
					}
				}
			}
			if roots := watchFimRoots(tests); len(roots) > 0 {
				if haveCmd("inotifywait") {
					args := append([]string{"-m", "-r", "-q", "-e", "create,modify,attrib,moved_to,delete", "--format", "%w%f"}, roots...)
					if follow(ctx, &dead, func(string) { poke() }, "inotifywait", args...) {
						i = "ok"
					}
				} else {
					i = "missing" // inotify-tools ontbreekt: bestanden alleen via de gewone rondes
				}
			}
		}
		gap := int64(10)
		if edrOn {
			e, gap = "ok", 5
			c, _ := loadBashConfig()
			if c == nil {
				c = &Config{}
			}
			selfInfo := edrSelfFrom(tests, c)
			emit := func(l string) {
				if l != "" {
					edrEmit(l)
					poke()
				}
			}
			if readable("/var/log/audit/audit.log") && haveCmd("auditctl") {
				p := newAuditParser(selfInfo.root, selfInfo.user, selfInfo.mark, emit)
				go tailFollow(ctx, "/var/log/audit/audit.log", p.feed)
			} else {
				e = "noaudit"
			}
			if haveCmd("ss") {
				go func() {
					first := true
					for ctx.Err() == nil {
						for _, ev := range edrNetOnce(selfInfo, first) {
							emit(ev)
						}
						first = false
						select {
						case <-ctx.Done():
						case <-time.After(5 * time.Second):
						}
					}
				}()
			}
			if roots := edrFileRoots(); len(roots) > 0 && haveCmd("inotifywait") {
				last, lastT := "", time.Time{}
				args := append([]string{"-m", "-r", "-q", "-e", "close_write,moved_to", "--format", "%w%f"}, roots...)
				follow(ctx, nil, func(p string) {
					if p == last && time.Since(lastT) < 10*time.Second { // hetzelfde bestand niet vaker dan eens per 10 s
						return
					}
					last, lastT = p, time.Now()
					emit(edrFile(p))
				}, "inotifywait", args...)
			}
			rt := ""
			if _, rc := run(15*time.Second, "podman", "ps", "-q"); conf["PODMAN"] != "0" && rc == 0 {
				rt = "podman"
			} else if _, rc := run(15*time.Second, "docker", "ps", "-q"); rc == 0 {
				rt = "docker"
			}
			if rt != "" {
				args := []string{"events", "--format", "json", "--filter", "event=exec", "--filter", "event=start"}
				if rt == "docker" {
					args = []string{"events", "--format", "{{json .}}", "--filter", "event=exec_start", "--filter", "event=start"}
				}
				follow(ctx, nil, func(l string) {
					name, img, act, cmd := edrCtrEvent(rt, l)
					if name == "" {
						return
					}
					priv := 0
					if act == "start" {
						if out, _ := stdoutOf(15*time.Second, rt, "inspect", "--format", "{{.HostConfig.Privileged}}", name); strings.TrimSpace(out) == "true" {
							priv = 1
						}
					}
					emit(edrCtrJSON(rt, name, img, act, cmd, priv))
				}, rt, args...)
			}
		}
		watchStatus("active", j, i, e)
		lastRun, pending := int64(0), false
		nextCheck := time.Now().Add(60 * time.Second)
		stopped := false
	inner:
		for {
			select {
			case <-stopAll:
				stopped = true
				break inner
			case <-wake:
				pending = true
			case <-time.After(5 * time.Second):
			}
			now := time.Now().Unix()
			if pending && now-lastRun >= gap {
				cmd := exec.Command(self, "run", "--quiet")
				cmd.Env = append(os.Environ(), "RZ_FORCE=1")
				_ = cmd.Run()
				lastRun, pending = time.Now().Unix(), false
			}
			if time.Now().After(nextCheck) {
				nextCheck = time.Now().Add(60 * time.Second)
				// instellingen in RzSense veranderd, de Go-agent doet het werk niet meer, of een volger gestopt: opnieuw opbouwen
				if watchSig(readText(filepath.Join(bashCachePath(), "tests.cache"))) != wsig || dead.Load() || !modeActive() {
					break inner
				}
			}
		}
		cancel()
		if stopped {
			watchStatus("off", "-", "-", "-")
			return 0
		}
		if !sleep(2 * time.Second) {
			return 0
		}
	}
}

// watchEnsure: de watcher laten draaien zolang Realtime of de endpoint-sensoren aan staan (een tijdelijke systemd-dienst,
// die vanzelf terugkomt als hij stopt en na een herstart door de eerstvolgende ronde weer gestart wordt), en stoppen als
// ze uit gaan. Alleen als de Go-agent het werk doet.
func watchEnsure() {
	if !underSystemd() && os.Getenv("RZSENSE_TEST_WATCH") == "" {
		return
	}
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	conf, _ := readShellConf(bashConfPath())
	want := modeActive() && ((conf["REALTIME"] != "0" && hasLinePrefix(tests, "0\trealtime\t1")) || (isRoot() && edrWanted(tests, conf)))
	active, _ := systemctlActive(watchUnit)
	switch {
	case want && !active:
		self, err := os.Executable()
		if err != nil {
			return
		}
		_, _ = run(10*time.Second, "systemctl", "reset-failed", watchUnit)
		_, _ = run(20*time.Second, "systemd-run", "--quiet", "--collect", "--unit="+watchUnit, "--description=RzSense Go-agent: Realtime en endpoint-sensoren",
			"--property=Restart=always", "--property=RestartSec=15", "--setenv=RZSENSE_DATA="+dataDir(), self, "watch")
	case !want && active:
		_ = systemctlDo("stop", watchUnit)
	}
}

// watchStop: bij teruggaan naar de bash-agent: de eigen watcher uit, die van de bash-agent weer aan
func watchStop() {
	if active, _ := systemctlActive(watchUnit); active {
		_ = systemctlDo("stop", watchUnit)
	}
	watchStatus("off", "-", "-", "-")
}

// realtimeReport: het onderdeel "realtime" van het rapport: de stand van de watcher zelf
func realtimeReport() map[string]any {
	f := strings.Fields(firstLine(filepath.Join(dataDir(), "watch.state")))
	if len(f) < 4 || !digitsRe.MatchString(f[1]) {
		return nil
	}
	return map[string]any{"state": f[0], "since": atoiDef(f[1], 0), "journal": f[2], "files": f[3]}
}
