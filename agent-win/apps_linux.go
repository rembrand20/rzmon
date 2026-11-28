//go:build linux

package main

// Programma's en jagen op sporen (0.32.0), zoals de bash-agent (agent/src/64-programmas.sh).
//  1. Programma's (regel "0 <tab> apps <tab> stoppen 0|1"): elke ronde de programma's die nu draaien (niet in
//     containers). Een nieuw of veranderd programma krijgt een vingerafdruk (SHA-256) en het pakket waar het bij hoort;
//     dat gaat één keer naar RzSense, die beslist wat onbekend is. Staat de vingerafdruk op de blokkeerlijst (regels
//     "0 <tab> appblock <tab> sha256") en staat stoppen aan, dan stopt de agent dat programma (alleen dat proces).
//  2. Jagen op sporen (regels iochunt, iocsha, iocpath): één keer per opdracht kijken of die paden bestaan en of een
//     draaiend programma of een bestand op de gebruikelijke plekken die vingerafdruk heeft.
// Ook tijdelijk sudo (68-sudo.sh) staat hier, en het hulpmiddel om iets buiten de afscherming van de eigen dienst te doen.

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/unix"
)

// sandboxed: draait de agent binnen de afscherming van zijn systemd-dienst (waar /etc alleen-lezen is)?
func sandboxed() bool {
	return underSystemd() && os.Getenv("RZSENSE_OUTSIDE") == "" && unix.Access("/etc", unix.W_OK) != nil
}

// outsideSelf: een eigen onderdeel buiten de afscherming van de dienst uitvoeren (een tijdelijke systemd-eenheid) en de
// uitvoer teruggeven. Alleen voor de vaste, ingebouwde onderdelen die op de server moeten schrijven.
func outsideSelf(timeout time.Duration, args ...string) (string, bool) {
	self, err := os.Executable()
	if err != nil {
		return "", false
	}
	a := []string{"--quiet", "--collect", "--wait", "--pipe", "--setenv=RZSENSE_OUTSIDE=1", "--setenv=RZSENSE_DATA=" + dataDir()}
	for _, k := range []string{"RZSENSE_BASH_CONF", "RZSENSE_BASH_CACHE", "RZSENSE_BASH_STATE", "RZSENSE_BASH_KEYS"} {
		if v := os.Getenv(k); v != "" {
			a = append(a, "--setenv="+k+"="+v)
		}
	}
	out, rc := stdoutOf(timeout, "systemd-run", append(append(a, self), args...)...)
	return out, rc == 0
}

// outsideJSON: een onderdeel van het rapport dat buiten de afscherming gemeten moet worden; direct als dat niet nodig is
func outsideJSON(timeout time.Duration, direct func() any, args ...string) json.RawMessage {
	if !sandboxed() {
		v := direct()
		b, err := json.Marshal(v)
		if err != nil || string(b) == "null" {
			return nil
		}
		return b
	}
	out, ok := outsideSelf(timeout, args...)
	out = strings.TrimSpace(out)
	if !ok || out == "" || out == "null" || !json.Valid([]byte(out)) {
		return nil
	}
	return json.RawMessage(out)
}

// ---------- Programma's ----------

func appsDir() string { return sharedDir("apps") }

var sha64Re = regexp.MustCompile(`^[0-9a-f]{64}$`)

type appProc struct {
	exe, pid, user string
}

// appsRunning: de programma's die nu draaien, één per programma (niet in een container: die heeft een eigen
// mount-namespace). Programma's zonder bestand of met een verwijderd bestand ziet het IDS al.
func appsRunning() []appProc {
	// 0.36.4: de omgeving van de server zelf (proces 1), niet die van de agent: de dienst draait met PrivateTmp en heeft
	// daardoor een eigen omgeving (dan zag hij vrijwel alleen zijn eigen processen). Containers hebben een andere.
	self, _ := os.Readlink("/proc/1/ns/mnt")
	ents, _ := os.ReadDir("/proc")
	pids := []string{}
	for _, e := range ents {
		if digitsRe.MatchString(e.Name()) {
			pids = append(pids, e.Name())
		}
	}
	sort.Strings(pids) // de shell loopt /proc/[0-9]* af in tekstvolgorde; per programma telt het eerste proces
	seen := map[string]bool{}
	out := []appProc{}
	for _, pid := range pids {
		exe, err := os.Readlink("/proc/" + pid + "/exe")
		if err != nil || exe == "" || strings.HasPrefix(exe, "/memfd:") || strings.HasSuffix(exe, " (deleted)") {
			continue
		}
		if ns, _ := os.Readlink("/proc/" + pid + "/ns/mnt"); self != "" && ns != self {
			continue
		}
		if seen[exe] {
			continue
		}
		seen[exe] = true
		user := ""
		if st, err := os.Stat("/proc/" + pid); err == nil {
			user = idNames.user(st.Sys().(*syscall.Stat_t).Uid)
		}
		out = append(out, appProc{exe, pid, user})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].exe < out[j].exe })
	return out
}

type appSeen struct {
	key, sha, pkg string
}

func appsSeenRead(file string) map[string]appSeen {
	m := map[string]appSeen{}
	for _, l := range strings.Split(readText(file), "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] != "" {
			s := appSeen{key: f[1], sha: f[2]}
			if len(f) >= 4 {
				s.pkg = f[3]
			}
			m[f[0]] = s
		}
	}
	return m
}

func appsFileKey(p string) (string, bool) {
	st, err := os.Stat(p)
	if err != nil {
		return "", false
	}
	sys := st.Sys().(*syscall.Stat_t)
	return fmt.Sprintf("%d:%d:%d:%d", sys.Dev, sys.Ino, st.Size(), st.ModTime().Unix()), true
}

func protectedComm(comm string) bool {
	return comm == "" || comm == "sshd" || comm == "init" || comm == "agetty" || comm == "login" || strings.HasPrefix(comm, "systemd") || strings.HasPrefix(comm, "rzmon") ||
		strings.HasPrefix(comm, "rzsense") || strings.HasPrefix(comm, "dbus")
}

// collectAppsLinux: de onderdelen "apps" en "ioc" van het rapport (alleen als root)
func collectAppsLinux() (apps map[string]any, ioc map[string]any) {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !isRoot() || tests == "" {
		return nil, nil
	}
	dir := appsDir()
	appsOn, killFlag := false, ""
	hunt := ""
	block := map[string]bool{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[0] != "0" {
			continue
		}
		switch f[1] {
		case "apps":
			if !appsOn && conf["APPCTL"] != "0" {
				appsOn, killFlag = true, tabRead(l, 4)[2]
			}
		case "iochunt":
			if hunt == "" && digitsRe.MatchString(f[2]) {
				hunt = f[2]
			}
		case "appblock":
			if sha64Re.MatchString(f[2]) {
				block[f[2]] = true
			}
		}
	}
	if hunt != "" && hunt == strings.TrimSpace(readText(filepath.Join(dir, "ioc.done"))) {
		hunt = ""
	}
	if !appsOn && hunt == "" {
		return nil, nil
	}
	cur := appsRunning()
	// Vingerafdruk en pakket alleen voor wat nieuw of veranderd is (apparaat, inode, grootte, tijd)
	seen := appsSeenRead(filepath.Join(dir, "seen"))
	keys := map[string]string{}
	for f, s := range seen {
		keys[f] = s.key
	}
	todo := []string{}
	for _, p := range cur {
		k, ok := appsFileKey(p.exe)
		if !ok || keys[p.exe] == k {
			continue
		}
		keys[p.exe] = k
		todo = append(todo, p.exe)
	}
	if len(todo) > 150 {
		todo = todo[:150] // de rest volgt de volgende ronde
	}
	newList := []map[string]any{}
	if len(todo) > 0 {
		owners := pkgOwners(todo)
		byExe := map[string]appProc{}
		for _, p := range cur {
			byExe[p.exe] = p
		}
		for _, f := range todo {
			sha, ok := sha256File(f)
			if !ok {
				continue
			}
			pkg := owners[f]
			if pkg == "" {
				switch {
				case strings.HasPrefix(f, "/snap/"):
					pkg = "snap"
				case strings.HasPrefix(f, "/var/lib/flatpak/"):
					pkg = "flatpak"
				case strings.HasPrefix(f, "/nix/store/"):
					pkg = "nix"
				}
			}
			changed := 0
			if old := seen[f].sha; old != "" && old != sha {
				changed = 1
			}
			seen[f] = appSeen{key: keys[f], sha: sha, pkg: pkg}
			p := byExe[f]
			cmd := strings.ReplaceAll(readText("/proc/"+p.pid+"/cmdline"), "\x00", " ")
			newList = append(newList, map[string]any{"p": cleanText(f), "sha": sha, "pkg": cleanText(pkg), "u": cleanText(p.user), "cmd": jsonText([]byte(cmd), 300), "ch": changed})
		}
		// Alleen wat een vingerafdruk kreeg komt in "gezien"
		rows := []string{}
		for f, s := range seen {
			if s.sha != "" && keys[f] != "" {
				rows = append(rows, f+"\t"+keys[f]+"\t"+s.sha+"\t"+s.pkg)
			}
		}
		sort.Strings(rows)
		if os.WriteFile(filepath.Join(dir, "seen.new"), []byte(strings.Join(rows, "\n")+"\n"), 0o600) == nil {
			_ = os.Rename(filepath.Join(dir, "seen.new"), filepath.Join(dir, "seen"))
		}
	}
	if appsOn {
		run := []string{}
		runLen := 0
		blk := []map[string]any{}
		for _, p := range cur {
			s := seen[p.exe]
			if s.sha == "" {
				continue
			}
			if s.pkg == "" && runLen < 14000 { // wat er nu draait zonder pakket
				run = append(run, s.sha)
				runLen += 67
			}
			if !block[s.sha] {
				continue
			}
			killed := 0
			ents, _ := os.ReadDir("/proc")
			for _, e := range ents {
				q := e.Name()
				if !digitsRe.MatchString(q) {
					continue
				}
				if x, _ := os.Readlink("/proc/" + q + "/exe"); x != p.exe {
					continue
				}
				pid, _ := strconv.Atoi(q)
				if killFlag != "1" || pid <= 1 || pid == os.Getpid() || pid == os.Getppid() || protectedComm(strings.TrimSpace(readText("/proc/"+q+"/comm"))) {
					continue
				}
				if x, _ := os.Readlink("/proc/" + q + "/exe"); x != p.exe { // nog steeds hetzelfde programma
					continue
				}
				if syscall.Kill(pid, syscall.SIGKILL) == nil {
					killed++
					actLog("appctl", 1, "Geblokkeerd programma gestopt", fmt.Sprintf("%s · pid %d · %s · sha256 %s…", p.exe, pid, p.user, s.sha[:16]))
				} else {
					actLog("appctl", 0, "Geblokkeerd programma stoppen mislukt", fmt.Sprintf("%s · pid %d", p.exe, pid))
				}
			}
			blk = append(blk, map[string]any{"p": cleanText(p.exe), "sha": s.sha, "u": cleanText(p.user), "killed": killed})
		}
		apps = map[string]any{"on": 1, "n": len(cur), "kill": atoiDef(killFlag, 0), "new": newList, "run": run, "blk": blk}
	}
	if hunt != "" {
		ioc = iocHunt(tests, hunt, cur, dir)
	}
	return apps, ioc
}

// iocHunt: één keer per opdracht: bestaan de gezochte paden, en heeft een draaiend programma of een bestand op de
// gebruikelijke plekken een gezochte vingerafdruk? Hooguit 2 minuten.
func iocHunt(tests, hunt string, cur []appProc, dir string) map[string]any {
	_ = os.WriteFile(filepath.Join(dir, "ioc.done"), []byte(hunt), 0o600)
	t0 := time.Now()
	hits := []map[string]any{}
	want := map[string]bool{}
	nPath, nSha := 0, 0
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[0] != "0" {
			continue
		}
		if f[1] == "iocpath" && nPath < 200 {
			nPath++
			raw, err := base64.StdEncoding.DecodeString(f[2])
			if p := string(raw); err == nil && strings.HasPrefix(p, "/") {
				if _, err := os.Stat(p); err == nil {
					hits = append(hits, map[string]any{"t": "path", "v": cleanText(p), "p": cleanText(p)})
				}
			}
		}
		if f[1] == "iocsha" && sha64Re.MatchString(f[2]) && nSha < 500 {
			nSha++
			want[f[2]] = true
		}
	}
	files := 0
	if len(want) > 0 {
		list := []string{}
		seen := map[string]bool{}
		add := func(p string) bool {
			if !seen[p] {
				seen[p] = true
				list = append(list, p)
			}
			return len(list) < 20000
		}
		for _, p := range cur {
			add(p.exe)
		}
		deadline := time.Now().Add(60 * time.Second)
		for _, d := range []string{"/tmp", "/var/tmp", "/dev/shm", "/usr/local", "/opt", "/root", "/home", "/srv", "/var/www"} {
			if st, err := os.Stat(d); err != nil || !st.IsDir() {
				continue
			}
			walkDepthOneFs(d, 6, func(p string, st os.FileInfo) bool {
				if st.Mode().IsRegular() && st.Size() <= 49*1048576 {
					return add(p) && time.Now().Before(deadline)
				}
				return time.Now().Before(deadline)
			})
		}
		files = len(list)
		stop := time.Now().Add(120 * time.Second)
		n := 0
		for _, p := range list {
			if time.Now().After(stop) || n >= 100 {
				break
			}
			if sha, ok := sha256File(p); ok && want[sha] {
				n++
				hits = append(hits, map[string]any{"t": "sha", "v": sha, "p": cleanText(p)})
			}
		}
	}
	secs := int(time.Since(t0).Seconds())
	if len(hits) > 0 {
		actLog("ioc", 0, "Sporen gevonden", fmt.Sprintf("%d treffers · %d bestanden doorzocht in %d s", len(hits), files, secs))
	} else {
		actLog("ioc", 2, "Gezocht naar sporen", fmt.Sprintf("geen treffers · %d bestanden doorzocht in %d s", files, secs))
	}
	return map[string]any{"id": atoiDef(hunt, 0), "files": files, "secs": secs, "hits": hits}
}

// walkDepthOneFs: een map aflopen tot een bepaalde diepte, zonder andere bestandssystemen in te gaan (find -xdev -maxdepth)
func walkDepthOneFs(root string, maxDepth int, visit func(string, os.FileInfo) bool) {
	rst, err := os.Lstat(root)
	if err != nil {
		return
	}
	dev := rst.Sys().(*syscall.Stat_t).Dev
	var walk func(dir string, depth int) bool
	walk = func(dir string, depth int) bool {
		ents, err := os.ReadDir(dir)
		if err != nil {
			return true
		}
		for _, e := range ents {
			p := filepath.Join(dir, e.Name())
			fi, err := os.Lstat(p)
			if err != nil {
				continue
			}
			if fi.IsDir() {
				if fi.Sys().(*syscall.Stat_t).Dev == dev && depth < maxDepth {
					if !walk(p, depth+1) {
						return false
					}
				}
				continue
			}
			if !visit(p, fi) {
				return false
			}
		}
		return true
	}
	walk(root, 1)
}

// ---------- Tijdelijk sudo ----------

var (
	sudoIDRe    = regexp.MustCompile(`^[0-9]{1,10}$`)
	sudoUserRe  = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)
	sudoUntilRe = regexp.MustCompile(`^[0-9]{9,11}$`)
	sudoMarkRe  = regexp.MustCompile(`(?m)^# rzsense-jit id=([0-9]*) until=([0-9]*)$`)
)

func sudoDir() string {
	if v := os.Getenv("RZSENSE_TEST_SUDOERS"); v != "" { // alleen voor de tests
		return v
	}
	return "/etc/sudoers.d"
}

// collectSudoLinux: tijdelijk sudo (regel "0 <tab> sudo <tab> id <tab> gebruiker <tab> tot"): een eigen bestand
// /etc/sudoers.d/rzsense-jit-<gebruiker>, eerst als verborgen tijdelijk bestand gecontroleerd met visudo, daarna de hele
// configuratie nog eens (klopt die niet, dan meteen weg). Een systemd-timer haalt het op tijd weg, ook als de agent er
// niet meer is. Alleen de eigen rzsense-jit-bestanden worden ooit aangeraakt; nooit voor root. SUDO_JIT=0 zet het uit.
func collectSudoLinux() []map[string]any {
	conf, _ := readShellConf(bashConfPath())
	dir := sudoDir()
	if st, err := os.Stat(dir); !isRoot() || conf["SUDO_JIT"] == "0" || err != nil || !st.IsDir() || !haveCmd("visudo") {
		return nil
	}
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	state := filepath.Join(sharedDir("sudo"), "failed")
	now := time.Now().Unix()
	failed := map[string]bool{}
	for _, l := range strings.Fields(readText(state)) {
		failed[l] = true
	}
	fail := func(id string) { // mislukte aanvragen niet elke ronde opnieuw
		l := append(lines(readText(state)), id)
		if len(l) > 50 {
			l = l[len(l)-50:]
		}
		_ = os.WriteFile(state, []byte(strings.Join(l, "\n")+"\n"), 0o600)
	}
	out := []map[string]any{}
	keep := map[string]bool{}
	n := 0
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 2 || f[0] != "0" || f[1] != "sudo" {
			continue
		}
		if n++; n > 20 {
			break
		}
		g := tabRead(l, 6)
		id, user, until := g[2], g[3], g[4]
		if !sudoIDRe.MatchString(id) || !sudoUserRe.MatchString(user) || !sudoUntilRe.MatchString(until) {
			continue
		}
		untilN, _ := strconv.ParseInt(until, 10, 64)
		if user == "root" || untilN <= now || failed[id] {
			continue
		}
		idN := atoiDef(id, 0)
		keep[user] = true
		file := filepath.Join(dir, "rzsense-jit-"+user)
		mark := "# rzsense-jit id=" + id + " until=" + until
		if st, err := os.Lstat(file); err == nil && st.Mode().IsRegular() {
			hit := false
			for _, r := range strings.Split(readText(file), "\n") {
				if r == mark {
					hit = true
				}
			}
			if hit {
				out = append(out, map[string]any{"id": idN, "st": "active"})
				continue
			}
		}
		if _, rc := run(10*time.Second, "id", "-u", user); rc != 0 {
			actLog("sudo", 0, "Tijdelijk sudo mislukt", user+": gebruiker bestaat niet op deze server")
			out = append(out, map[string]any{"id": idN, "st": "error", "d": "gebruiker bestaat niet"})
			fail(id)
			continue
		}
		tmp, err := os.CreateTemp(dir, ".rzsense-jit.")
		if err != nil {
			continue
		}
		when := time.Unix(untilN, 0).Format("2006-01-02 15:04")
		_, _ = fmt.Fprintf(tmp, "%s\n# Tijdelijk sudo via RzSense tot %s; wordt vanzelf weggehaald.\n%s ALL=(ALL:ALL) ALL\n", mark, when, user)
		tmp.Close()
		_ = os.Chmod(tmp.Name(), 0o440)
		if _, rc := run(20*time.Second, "visudo", "-cqf", tmp.Name()); rc != 0 || os.Rename(tmp.Name(), file) != nil {
			_ = os.Remove(tmp.Name())
			actLog("sudo", 0, "Tijdelijk sudo mislukt", user+": visudo keurt het bestand niet goed")
			out = append(out, map[string]any{"id": idN, "st": "error", "d": "visudo keurt het niet goed"})
			fail(id)
			continue
		}
		if _, rc := run(20*time.Second, "visudo", "-cq"); rc != 0 {
			_ = os.Remove(file)
			actLog("sudo", 0, "Tijdelijk sudo mislukt", user+": de sudo-configuratie klopte daarna niet; meteen weggehaald")
			out = append(out, map[string]any{"id": idN, "st": "error", "d": "visudo keurt de configuratie af"})
			fail(id)
			continue
		}
		// Vangnet: een timer die het bestand op tijd weghaalt, ook als de agent stopt
		if haveCmd("systemd-run") {
			_, _ = run(15*time.Second, "systemd-run", "--quiet", "--collect", "--unit=rzsense-jit-"+user+"-"+id, fmt.Sprintf("--on-active=%d", untilN-now+30), "/bin/rm", "-f", "--", file)
		}
		actLog("sudo", 1, "Tijdelijk sudo gegeven", user+" tot "+when)
		out = append(out, map[string]any{"id": idN, "st": "active"})
	}
	// Weghalen: tijd voorbij, ingetrokken of niet meer gevraagd (alleen onze eigen bestanden)
	own, _ := filepath.Glob(filepath.Join(dir, "rzsense-jit-*"))
	for _, file := range own {
		if st, err := os.Lstat(file); err != nil || !st.Mode().IsRegular() {
			continue
		}
		user := strings.TrimPrefix(filepath.Base(file), "rzsense-jit-")
		id, until := 0, int64(0)
		if m := sudoMarkRe.FindStringSubmatch(readText(file)); m != nil {
			id = atoiDef(m[1], 0)
			until, _ = strconv.ParseInt(m[2], 10, 64)
		}
		if !keep[user] || until <= now {
			_ = os.Remove(file)
			why := " (ingetrokken)"
			if until <= now {
				why = " (tijd voorbij)"
			}
			actLog("sudo", 1, "Tijdelijk sudo weggehaald", user+why)
			out = append(out, map[string]any{"id": id, "st": "removed"})
		}
	}
	if left, _ := filepath.Glob(filepath.Join(dir, ".rzsense-jit.*")); len(left) > 0 {
		for _, f := range left {
			_ = os.Remove(f)
		}
	}
	return out
}
