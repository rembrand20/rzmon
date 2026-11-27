//go:build linux

package main

// Uploads scannen (0.36.0), zoals de bash-agent (agent/src/69-proxy.sh, het derde deel). ClamAV als eigen container
// (rzsense-clamav) die de uploadmappen van je apps alleen-lezen ziet. Elke ronde scant de agent de bestanden die sinds de
// vorige ronde nieuw of gewijzigd zijn (en op verzoek alles).
//   0 <tab> av <tab> on|off <tab> melden 0|1 <tab> verplaatsen 0|1
//   0 <tab> avdir <tab> /pad           (alleen binnen AV_DIRS_MAX op deze server; nooit systeemmappen)
//   0 <tab> avfull <tab> id            (volledige scan, op de achtergrond)
//   0 <tab> avrestore|avdelete <tab> qid   (een bestand uit de quarantaine terugzetten of definitief weghalen)
// Verplaatsen = het besmette bestand naar de quarantaine (alleen root, rechten 000), met de oorspronkelijke plek, eigenaar
// en rechten erbij, zodat terugzetten altijd kan. De map met de stand en de quarantaine is die van de bash-agent.

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

var (
	avQidRe    = regexp.MustCompile(`^[0-9]{14}-[0-9a-f]{8}$`)
	avFullIDRe = regexp.MustCompile(`[^0-9a-z-]`)
	avEngRe    = regexp.MustCompile(`[^a-z]`)
)

func dockerIsPodman() bool {
	out, _ := stdoutOf(5*time.Second, "docker", "--version")
	return strings.Contains(strings.ToLower(out), "podman")
}

// avDirOK: een map mag als hij bestaat, echt een map is, binnen het plafond ligt en geen systeemmap of eigen map van
// RzSense is. Geeft de echte map terug, of de reden waarom niet.
func avDirOK(p, max string) (string, bool) {
	r, err := filepath.EvalSymlinks(p)
	if err != nil {
		return "bestaat niet", false
	}
	if r, err = filepath.Abs(r); err != nil {
		return "bestaat niet", false
	}
	if !isDir(r) {
		return "geen map", false
	}
	for _, sys := range []string{"/etc/", "/usr/", "/bin/", "/sbin/", "/lib/", "/lib64/", "/boot/", "/proc/", "/sys/", "/dev/", "/run/", "/root/", "/var/lib/rzsense-"} {
		if strings.HasPrefix(r+"/", sys) {
			return "systeemmap", false
		}
	}
	for _, m := range strings.Fields(max) {
		m = strings.TrimSuffix(m, "/")
		if m != "" && (r == m || strings.HasPrefix(r, m+"/")) {
			return r, true
		}
	}
	return "buiten het plafond (AV_DIRS_MAX)", false
}

type avRun struct {
	dir, ctr, move string
	ok             []string
	found          []map[string]any
}

// hit: een besmet bestand: melden en (als de schakelaar aan staat) naar de quarantaine
func (a *avRun) hit(p, sig string) {
	lst, err := os.Lstat(p)
	if err != nil || lst.Mode()&os.ModeSymlink != 0 {
		return
	}
	r, err := filepath.EvalSymlinks(p)
	if err != nil || !isFile(r) {
		return
	}
	inside := false
	for _, d := range a.ok {
		if strings.HasPrefix(r, d+"/") {
			inside = true
		}
	}
	if !inside {
		return
	}
	sha, _ := sha256File(r)
	st, err := os.Stat(r)
	if err != nil {
		return
	}
	for _, l := range lines(readText(filepath.Join(a.dir, "allow.sha"))) { // teruggezet door de beheerder: niet opnieuw
		if l == sha {
			return
		}
	}
	qid, moved := "", 0
	if a.move == "1" {
		rnd := make([]byte, 4)
		_, _ = rand.Read(rnd)
		qid = time.Now().Format("20060102150405") + "-" + hex.EncodeToString(rnd)
		qd := filepath.Join(a.dir, "quarantine", qid)
		sys := st.Sys().(*syscall.Stat_t)
		meta := fmt.Sprintf("%s\t%s\t%s\t%d\t%d:%d\t%o\t%d\n", r, sig, sha, st.Size(), sys.Uid, sys.Gid, modeBits(st.Mode()), time.Now().Unix())
		if os.Mkdir(qd, 0o700) == nil && os.WriteFile(filepath.Join(qd, "meta"), []byte(meta), 0o644) == nil && moveFile(r, filepath.Join(qd, "file")) == nil && os.Chmod(filepath.Join(qd, "file"), 0) == nil {
			moved = 1
		} else {
			_ = os.RemoveAll(qd)
			qid = ""
		}
	}
	detail := r
	if moved == 1 {
		detail += " → quarantaine " + qid
	}
	actLog("proxy", moved, "ClamAV: "+sig, detail)
	a.found = append(a.found, map[string]any{"p": cleanText(r), "sig": cleanText(sig), "sha": sha, "size": st.Size(), "qid": qid, "moved": moved})
}

// moveFile: verplaatsen, ook naar een ander bestandssysteem (zoals mv)
func moveFile(from, to string) error {
	if err := os.Rename(from, to); err == nil {
		return nil
	}
	_, rc := run(120*time.Second, "mv", "--", from, to)
	if rc != 0 {
		return fmt.Errorf("verplaatsen mislukt")
	}
	return nil
}

// parse: de uitvoer van clamdscan: regels "pad: handtekening FOUND"
func (a *avRun) parse(out string) {
	for _, l := range strings.Split(out, "\n") {
		if !strings.HasSuffix(l, " FOUND") || !strings.Contains(strings.TrimSuffix(l, " FOUND"), ": ") {
			continue
		}
		l = strings.TrimSuffix(l, " FOUND")
		i := strings.LastIndex(l, ": ")
		a.hit(l[:i], l[i+2:])
	}
}

// avStamp: een beginpunt 2 seconden terug (een bestand uit dezelfde seconde wordt anders gemist)
func avStamp(file string) {
	_ = os.WriteFile(file, nil, 0o600)
	t := time.Unix(time.Now().Unix()-2, 0)
	_ = os.Chtimes(file, t, t)
}

// collectAvLinux: het onderdeel "av" van het rapport
func collectAvLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !isRoot() || conf["PROXY_GUARD"] == "0" || !hasLinePrefix(tests, "0\tav\t") {
		return nil
	}
	on, move := "", ""
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 2 && f[0] == "0" && f[1] == "av" {
			g := tabRead(strings.Join(f[2:], "\t"), 3)
			on, move = g[0], g[2]
			break
		}
	}
	const ctrName = "rzsense-clamav"
	cfg := func(k, def string) string {
		if v, ok := conf[k]; ok && (v != "" || k == "AV_DIRS_MAX") {
			return v
		}
		return def
	}
	dir := cfg("AV_DIR", "/var/lib/rzsense-clamav")
	img := cfg("AV_IMAGE", "docker.io/clamav/clamav:1.4")
	max := cfg("AV_DIRS_MAX", "/srv /opt /data /home /mnt /var/lib/docker/volumes /var/lib/containers/storage/volumes")
	minMB := atoiDef(conf["AV_MIN_MB"], 1400)
	maxMB := atoiDef(conf["AV_MAX_MB"], 100)
	mem := 0
	for _, l := range strings.Split(readText("/proc/meminfo"), "\n") {
		if f := strings.Fields(l); len(f) >= 2 && f[0] == "MemAvailable:" {
			mem = atoiDef(f[1], 0) / 1024
		}
	}
	if v := os.Getenv("RZSENSE_TEST_MEM"); v != "" { // alleen voor de tests
		mem = atoiDef(v, mem)
	}
	px := &proxyGuard{conf: conf, tests: tests, state: proxyStateDir()}
	if hasLinePrefix(tests, "0\tproxy\t") {
		px.detect()
	}
	// de engine waarin ClamAV ooit gemaakt is, blijft die engine
	eng := avEngRe.ReplaceAllString(readText(filepath.Join(dir, "engine")), "")
	if (eng != "podman" && eng != "docker") || !haveCmd(eng) {
		eng = ""
	}
	if eng == "" {
		eng = px.eng
	}
	if eng == "" {
		for _, e := range []string{"podman", "docker"} {
			if haveCmd(e) {
				eng = e
				break
			}
		}
	}
	c := &ctrHelper{eng: eng, ctr: ctrName, timeout: 120 * time.Second}
	if !isFile(filepath.Join(dir, "engine")) && isDir(dir) && c.exists() { // bestaande installatie: vastleggen
		_ = os.WriteFile(filepath.Join(dir, "engine"), []byte(eng+"\n"), 0o600)
	}
	// een tweede rzsense-clamav in de andere engine (echte Docker naast Podman) is van ons en dubbel: weg ermee
	other := map[string]string{"podman": "docker", "docker": "podman"}[eng]
	if other != "" && haveCmd(other) && !(other == "docker" && dockerIsPodman()) && !(eng == "docker" && dockerIsPodman()) {
		if _, rc := pxCtr(30, other, "inspect", ctrName); rc == 0 {
			if _, rc := pxCtr(60, other, "rm", "-f", ctrName); rc == 0 {
				actLog("proxy", 1, "ClamAV: dubbele container weggehaald", ctrName+" in "+other+" (hij draait in "+eng+")")
			}
		}
	}
	state, msg, sigs, full, newN := "error", "", "", "none", 0
	dirsJ, sugg, ops, quar := []map[string]any{}, []map[string]any{}, []map[string]any{}, []map[string]any{}
	a := &avRun{dir: dir, ctr: ctrName, move: move, found: []map[string]any{}}
	orMsg := func(def string) {
		if msg == "" {
			msg = def
		}
	}
	result := func() map[string]any {
		return map[string]any{"state": state, "msg": cleanText(msg), "mem": mem, "image": cleanText(img), "sigs": cleanText(sigs), "new": newN, "full": full,
			"full_id": avFullIDRe.ReplaceAllString(readText(filepath.Join(dir, "full.id")), ""), "dirs": dirsJ, "suggest": sugg, "found": a.found, "quarantine": quar, "ops": ops}
	}
	if on != "on" {
		if c.exists() {
			c.x("rm", "-f", ctrName)
			actLog("proxy", 1, "ClamAV uit", "container "+ctrName+" weggehaald (de quarantaine blijft)")
		}
		_ = os.Remove(filepath.Join(dir, "env.hash"))
		state = "off"
		return result()
	}
	if eng == "" {
		state, msg = "unsupported", "geen Podman of Docker gevonden"
		return result()
	}
	for _, d := range []string{"db", "work", "quarantine"} {
		_ = os.MkdirAll(filepath.Join(dir, d), 0o755)
	}
	_ = os.Chmod(dir, 0o700)
	_ = os.Chmod(filepath.Join(dir, "quarantine"), 0o700)
	// 1. Mappen: alleen wat mag; de rest met de reden terug naar RzSense
	okSet := map[string]bool{}
	n := 0
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 2 || f[0] != "0" || f[1] != "avdir" {
			continue
		}
		if n++; n > 30 {
			break
		}
		p := tabRead(l, 3)[2]
		if p == "" {
			continue
		}
		if r, ok := avDirOK(p, max); ok {
			okSet[r] = true
			dirsJ = append(dirsJ, map[string]any{"p": cleanText(p), "ok": 1})
		} else {
			dirsJ = append(dirsJ, map[string]any{"p": cleanText(p), "ok": 0, "why": r})
		}
	}
	for d := range okSet {
		a.ok = append(a.ok, d)
	}
	sort.Strings(a.ok)
	// 2. Voorstellen: de mappen van de andere containers (volumes en bind-mounts) binnen het plafond
	names, _ := c.x("ps", "--format", "{{.Names}}")
	for i, name := range lines(names) {
		if i >= 60 {
			break
		}
		if name == "" || strings.HasPrefix(name, "rzsense-") || name == px.ctr {
			continue
		}
		mounts, _ := c.x("inspect", "-f", `{{range .Mounts}}{{.Source}}{{"\t"}}{{.Destination}}{{"\n"}}{{end}}`, name)
		for j, m := range lines(mounts) {
			if j >= 20 {
				break
			}
			g := tabRead(m, 2)
			if g[0] == "" {
				continue
			}
			if _, ok := avDirOK(g[0], max); ok {
				sugg = append(sugg, map[string]any{"c": cleanText(name), "p": cleanText(g[0]), "d": cleanText(g[1])})
			}
		}
	}
	// 3. Container: (opnieuw) aanmaken als hij er niet is of de mappen veranderden; eerst genoeg geheugen?
	ehash := sha12(img + "|" + strings.Join(a.ok, "|") + map[bool]string{true: "|", false: ""}[len(a.ok) > 0])
	if exists := c.exists(); !exists || strings.TrimSpace(readText(filepath.Join(dir, "env.hash"))) != ehash {
		if !exists && mem < minMB {
			state, msg = "lowmem", fmt.Sprintf("te weinig vrij geheugen: %d MB, nodig %d MB", mem, minMB)
		} else {
			c.x("rm", "-f", ctrName)
			// geen relabel (:Z) op de mappen van je apps: dat zou hun rechten veranderen; daarom label=disable
			args := []string{"run", "-d", "--name", ctrName, "--restart", "unless-stopped", "--security-opt", "label=disable", "-v", dir + "/db:/var/lib/clamav", "-v", dir + "/work:/rzwork:ro"}
			for _, d := range a.ok {
				args = append(args, "-v", d+":"+d+":ro")
			}
			if out, rc := c.xAll(240*time.Second, append(args, img)...); rc == 0 {
				_ = os.WriteFile(filepath.Join(dir, "env.hash"), []byte(ehash+"\n"), 0o600)
				_ = os.WriteFile(filepath.Join(dir, "engine"), []byte(eng+"\n"), 0o600)
				actLog("proxy", 1, "ClamAV gestart", fmt.Sprintf("%s, %d mappen", img, len(a.ok)))
			} else {
				msg = "starten mislukt: " + tail2(out)
			}
		}
	}
	// 5. Klaar? Dan de nieuwe bestanden scannen (de eerste keer alleen een beginpunt: alles = volledige scan)
	runningNow := func() bool {
		out, _ := c.x("inspect", "-f", "{{.State.Running}}", ctrName)
		return strings.TrimSpace(out) == "true"
	}
	last, next := filepath.Join(dir, "last.stamp"), filepath.Join(dir, "next.stamp")
	switch {
	case runningNow():
		if _, rc := c.x("exec", ctrName, "clamdscan", "--no-summary", "/etc/hostname"); rc != 0 {
			state = "starting"
			orMsg("ClamAV start nog (virusdefinities laden; de eerste keer een paar minuten)")
			break
		}
		state = "ok"
		ver, _ := c.x("exec", ctrName, "clamdscan", "--version")
		sigs = cutRunes(firstLineOf(ver), 80)
		if !isFile(last) {
			avStamp(last)
			orMsg("klaar; nieuwe bestanden worden vanaf nu gescand")
		} else if len(a.ok) > 0 {
			avStamp(next)
			ref, _ := os.Stat(last)
			limit := int64(maxMB*1024-1) * 1024
			list := []string{}
			for _, d := range a.ok {
				walkOneFs(d, nil, func(p string, st os.FileInfo) bool {
					if st.Mode().IsRegular() && st.ModTime().After(ref.ModTime()) && st.Size() <= limit && !strings.Contains(filepath.Base(p), "\n") {
						list = append(list, p)
					}
					return len(list) < 2001
				})
				if len(list) >= 2001 {
					break
				}
			}
			body := ""
			if len(list) > 0 {
				body = strings.Join(list, "\n") + "\n"
			}
			_ = os.WriteFile(filepath.Join(dir, "work", "list.txt"), []byte(body), 0o644)
			if newN = len(list); newN > 0 {
				out, rc := c.xAll(240*time.Second, "exec", ctrName, "clamdscan", "--no-summary", "--infected", "--file-list=/rzwork/list.txt")
				a.parse(out)
				if rc > 1 || rc < 0 {
					first := ""
					for _, l := range strings.Split(out, "\n") {
						if !strings.Contains(l, "FOUND") {
							first = l
							break
						}
					}
					msg = fmt.Sprintf("scan gaf fout %d: %s", rc, cutRunes(first, 150))
				}
			}
			if newN > 2000 {
				msg = "meer dan 2000 nieuwe bestanden in één minuut: start een volledige scan"
			}
			_ = os.Rename(next, last)
			_ = os.WriteFile(filepath.Join(dir, "work", "list.txt"), nil, 0o644)
		}
		// Volledige scan op verzoek, op de achtergrond; de uitkomst verwerkt een volgende ronde
		fullID := ""
		for _, l := range strings.Split(tests, "\n") {
			if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "avfull" {
				fullID = cutBytes(avFullIDRe.ReplaceAllString(f[2], ""), 40)
				break
			}
		}
		fp := func(n string) string { return filepath.Join(dir, n) }
		if isFile(fp("full.rc")) && !isFile(fp("full.done")) {
			out := readText(fp("full.out"))
			a.parse(out)
			_ = os.WriteFile(fp("full.done"), []byte(readText(fp("full.id"))), 0o600)
			nFound := 0
			for _, l := range strings.Split(out, "\n") {
				if strings.HasSuffix(l, " FOUND") {
					nFound++
				}
			}
			actLog("proxy", 1, "ClamAV: volledige scan klaar", fmt.Sprintf("%d gevonden", nFound))
		}
		if fullID != "" && strings.TrimSpace(readText(fp("full.id"))) != fullID && len(a.ok) > 0 {
			for _, f := range []string{"full.rc", "full.out", "full.done"} {
				_ = os.Remove(fp(f))
			}
			_ = os.WriteFile(fp("full.id"), []byte(fullID+"\n"), 0o600)
			script := `e=$0; c=$1; d=$2; shift 2; timeout 21600 "$e" exec "$c" clamdscan --no-summary --infected --multiscan "$@" >"$d/full.out" 2>&1; echo $? >"$d/full.rc"`
			args := append([]string{"-c", script, eng, ctrName, dir}, a.ok...)
			if underSystemd() { // een eigen eenheid: anders stopt systemd de scan zodra deze ronde klaar is
				_, _ = run(15*time.Second, "systemd-run", append([]string{"--quiet", "--collect", fmt.Sprintf("--unit=rzsense-avfull-%d", time.Now().Unix()), "sh"}, args...)...)
			} else {
				cmd := exec.Command("sh", args...)
				cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
				if cmd.Start() == nil {
					go func() { _ = cmd.Wait() }()
				}
			}
			actLog("proxy", 1, "ClamAV: volledige scan gestart", fmt.Sprintf("%d mappen", len(a.ok)))
		}
		if isFile(fp("full.id")) {
			full = "running"
			if isFile(fp("full.done")) {
				full = "done"
			}
		}
	case state != "lowmem" && c.exists():
		// gestopt (bv. na een herstart van de server): de reden tonen en hooguit elke 10 minuten opnieuw starten
		stOut, _ := c.x("inspect", "-f", "{{.State.Status}} {{.State.ExitCode}}", ctrName)
		was := strings.TrimSpace(stOut)
		if was == "" {
			was = "onbekend"
		}
		logOut, _ := c.xAll(60*time.Second, "logs", "--tail", "4", ctrName)
		keep := []string{}
		for _, l := range strings.Split(logOut, "\n") {
			if strings.TrimSpace(l) != "" {
				keep = append(keep, l)
			}
		}
		if len(keep) > 2 {
			keep = keep[len(keep)-2:]
		}
		logTxt := ""
		if len(keep) > 0 {
			logTxt = cutRunes(strings.Join(keep, " ")+" ", 220)
		}
		startAt := filepath.Join(dir, "start.at")
		if st, err := os.Stat(startAt); err != nil || time.Since(st.ModTime()) >= 10*time.Minute {
			_ = os.WriteFile(startAt, nil, 0o600)
			now := time.Now()
			_ = os.Chtimes(startAt, now, now)
			if mem < minMB {
				state, msg = "lowmem", fmt.Sprintf("gestopt en te weinig vrij geheugen om opnieuw te starten: %d MB, nodig %d MB", mem, minMB)
			} else if _, rc := c.x("start", ctrName); rc == 0 {
				if os.Getenv("RZSENSE_TEST_FAST") != "1" {
					time.Sleep(3 * time.Second)
				}
				if runningNow() {
					state, msg = "starting", "opnieuw gestart (was: "+was+"); virusdefinities laden"
					actLog("proxy", 1, "ClamAV opnieuw gestart", "was: "+was)
				}
			}
		}
		if state != "starting" && state != "lowmem" {
			def := "de container draait niet (" + was
			if logTxt != "" {
				def += ": " + logTxt
			}
			orMsg(def + "); bekijk: " + eng + " logs --tail 30 " + ctrName)
		}
	case state != "lowmem":
		orMsg("de container draait niet")
	}
	// 6. Quarantaine: terugzetten of definitief weghalen op verzoek, en de lijst meesturen
	nOps := 0
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 2 || f[0] != "0" || (f[1] != "avrestore" && f[1] != "avdelete") {
			continue
		}
		if nOps++; nOps > 50 {
			break
		}
		g := tabRead(l, 3)
		op, qid := g[1], g[2]
		if !avQidRe.MatchString(qid) {
			continue
		}
		qd := filepath.Join(dir, "quarantine", qid)
		ok, m := 1, ""
		switch {
		case !isDir(qd):
			m = "niet (meer) in de quarantaine"
		case op == "avdelete":
			_ = os.RemoveAll(qd)
			m = "definitief weggehaald"
			actLog("proxy", 1, "ClamAV: uit de quarantaine weggehaald", qid)
		default:
			meta := tabRead(firstLine(filepath.Join(qd, "meta")), 7)
			target, sha, own, mode := meta[0], meta[2], meta[4], meta[5]
			if _, err := os.Lstat(target); err == nil {
				ok, m = 0, "er staat al een bestand op "+target
			} else if !isDir(filepath.Dir(target)) {
				ok, m = 0, "de map bestaat niet meer"
			} else {
				ids := strings.SplitN(own, ":", 2)
				perm, perr := strconv.ParseUint(mode, 8, 32)
				if len(ids) == 2 && perr == nil && moveFile(filepath.Join(qd, "file"), target) == nil && os.Chown(target, atoiDef(ids[0], -1), atoiDef(ids[1], -1)) == nil && os.Chmod(target, modeFromBits(uint32(perm))) == nil {
					if fh, err := os.OpenFile(filepath.Join(dir, "allow.sha"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
						_, _ = fh.WriteString(sha + "\n")
						fh.Close()
					}
					_ = os.RemoveAll(qd)
					m = "teruggezet"
					actLog("proxy", 1, "ClamAV: teruggezet", target)
				} else {
					ok, m = 0, "terugzetten mislukt"
				}
			}
		}
		ops = append(ops, map[string]any{"qid": qid, "op": strings.TrimPrefix(op, "av"), "ok": ok, "msg": m})
	}
	qdirs, _ := filepath.Glob(filepath.Join(dir, "quarantine", "*"))
	for _, qd := range qdirs {
		if !isFile(filepath.Join(qd, "meta")) {
			continue
		}
		meta := tabRead(firstLine(filepath.Join(qd, "meta")), 7)
		quar = append(quar, map[string]any{"qid": filepath.Base(qd), "p": cleanText(meta[0]), "sig": cleanText(meta[1]), "sha": meta[2], "size": atoiDef(meta[3], 0), "at": atoiDef(meta[6], 0)})
	}
	return result()
}

func firstLineOf(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return s[:i]
	}
	return s
}

// modeFromBits: de rechten uit een octaal getal (met setuid, setgid en sticky), het omgekeerde van modeBits
func modeFromBits(b uint32) os.FileMode {
	m := os.FileMode(b & 0o777)
	if b&0o4000 != 0 {
		m |= os.ModeSetuid
	}
	if b&0o2000 != 0 {
		m |= os.ModeSetgid
	}
	if b&0o1000 != 0 {
		m |= os.ModeSticky
	}
	return m
}
