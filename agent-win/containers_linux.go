//go:build linux

package main

// Containers (Podman en Docker) en heartbeats (0.20.0), zoals de bash-agent (agent/src/40-basis-meten.sh "Podman:
// voorbereiding", 70-containers-en-proxmox.sh en 85-meesturen.sh):
//   - per context (root, gebruikers met rootless containers, en "docker"): de versie, de containers (status, health,
//     herstarts, CPU, geheugen, netwerk, schijf, grootte, volumes), de pods en de opslag. Alleen lezen.
//   - heartbeats: de regels die rzmon-hb in de wachtrij zet, gaan mee met het rapport en verdwijnen pas als RzSense ze heeft.
//
// Antwoordt podman of docker niet binnen 8 s, of loopt één commando af, dan deze ronde geen containeronderdelen meer
// (RzSense hoort dat via ctr_busy); de rest van het rapport gaat gewoon door.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

type ctrEngine struct {
	seen map[string]bool
	busy []string
	ctxs []string
}

// out: een containercommando met tijdslimiet, alleen stdout (zoals 2>/dev/null). ok = exitcode 0.
func (e *ctrEngine) out(timeout time.Duration, dir string, env []string, name string, args ...string) (stdout, all string, rc int) {
	if _, err := exec.LookPath(name); err != nil {
		return "", "", 127
	}
	cmd := exec.Command(name, args...)
	cmd.Dir = dir
	cmd.Env = append(append(os.Environ(), "LC_ALL=C"), env...)
	var so, both bytes.Buffer
	cmd.Stdout = &so
	cmd.Stderr = &both
	done := make(chan error, 1)
	if err := cmd.Start(); err != nil {
		return "", "", 127
	}
	go func() { done <- cmd.Wait() }()
	select {
	case err := <-done:
		rc = 0
		if ee, ok := err.(*exec.ExitError); ok {
			rc = ee.ExitCode()
		} else if err != nil {
			rc = 127
		}
	case <-time.After(timeout):
		_ = cmd.Process.Kill()
		<-done
		rc = 124
	}
	return so.String(), so.String() + both.String(), rc
}

func (e *ctrEngine) isBusy(eng string) bool { return hasStr(e.busy, eng) }

// ok: antwoordt podman of docker deze ronde? (één korte meting per ronde)
func (e *ctrEngine) ok(eng string) bool {
	if e.isBusy(eng) {
		return false
	}
	if e.seen[eng] {
		return true
	}
	if !haveCmd(eng) {
		return false
	}
	if _, _, rc := e.out(8*time.Second, "", nil, eng, "ps", "-q"); rc == 0 {
		e.seen[eng] = true
		return true
	}
	e.busy = append(e.busy, eng)
	return false
}

// pm: podman als root, of als die gebruiker (rootless); context "docker" = docker
func (e *ctrEngine) pm(ctx string, args ...string) (stdout, all string, rc int) {
	me := "root"
	if u, err := user.Current(); err == nil {
		me = u.Username
	}
	eng := "podman"
	if ctx == "docker" {
		eng = "docker"
	}
	if !e.ok(eng) {
		return "", "", 125
	}
	if ctx == "docker" || ctx == "root" || ctx == me {
		stdout, all, rc = e.out(60*time.Second, "", nil, eng, args...)
	} else {
		var env []string
		if u, err := user.Lookup(ctx); err == nil {
			if st, err := os.Stat("/run/user/" + u.Uid); err == nil && st.IsDir() {
				env = []string{"XDG_RUNTIME_DIR=/run/user/" + u.Uid}
			}
		}
		stdout, all, rc = e.out(60*time.Second, "/", env, "runuser", append([]string{"-u", ctx, "--", "podman"}, args...)...)
		return stdout, all, rc // rootless: een trage gebruiker houdt de andere contexten niet tegen (zoals de bash-agent)
	}
	if (rc == 124 || rc == 137) && !e.isBusy(eng) {
		e.busy = append(e.busy, eng)
	}
	return stdout, all, rc
}

// contexts: "root" (of de huidige gebruiker), gebruikers met rootless containers, en "docker" als de daemon draait
func (e *ctrEngine) contexts(conf map[string]string) {
	me, uid := "root", "0"
	if u, err := user.Current(); err == nil {
		me, uid = u.Username, u.Uid
	}
	if conf["PODMAN"] != "0" && haveCmd("podman") {
		e.ctxs = append(e.ctxs, me)
		users, set := conf["PODMAN_USERS"]
		if !set {
			users = "auto"
		}
		if uid == "0" && users != "" {
			if users == "auto" {
				// Alleen gebruikers die echt containers hebben: elke container heeft een map <driver>-containers/<id>/userdata
				for _, l := range strings.Split(readText("/etc/passwd"), "\n") {
					f := strings.Split(l, ":")
					if len(f) < 6 {
						continue
					}
					n := atoiDef(f[2], 0)
					if n >= 1000 && n < 65534 {
						if m, _ := filepath.Glob(f[5] + "/.local/share/containers/storage/*-containers/*/userdata"); len(m) > 0 {
							e.ctxs = append(e.ctxs, f[0])
						}
					}
				}
			} else {
				for _, u := range strings.Fields(users) {
					if _, err := user.Lookup(u); err == nil {
						e.ctxs = append(e.ctxs, u)
					}
				}
			}
		}
	}
	// Docker als extra context (alleen als de daemon draait, en niet als "docker" eigenlijk Podman is: pakket podman-docker)
	if conf["DOCKER"] != "0" && haveCmd("docker") && e.ok("docker") {
		ver, _, _ := e.out(5*time.Second, "", nil, "docker", "--version")
		if _, _, rc := e.out(5*time.Second, "", nil, "docker", "info"); rc == 0 && !strings.Contains(strings.ToLower(ver), "podman") {
			e.ctxs = append(e.ctxs, "docker")
		}
	}
}

var sizeHead = regexp.MustCompile(`^[0-9.]+[a-zA-Z]*`)
var plainNum = regexp.MustCompile(`^[0-9]+(\.[0-9]+)?$`)

// unitBytes: "11.8kB (virtual 8.12MB)" → bytes. Podman gebruikt SI (kB = 1000); Docker ook KiB/MiB/GiB (binary = true).
func unitBytes(s string, binary bool) float64 {
	m := sizeHead.FindString(s)
	if m == "" {
		return 0
	}
	n := awkNum(m)
	u := strings.ToLower(strings.TrimLeft(m, "0123456789."))
	mult := map[string]float64{"kb": 1e3, "mb": 1e6, "gb": 1e9, "tb": 1e12}
	if binary {
		for k, v := range map[string]float64{"kib": 1024, "mib": 1048576, "gib": 1073741824, "tib": 1099511627776} {
			mult[k] = v
		}
	}
	if v, ok := mult[u]; ok {
		return n * v
	}
	return n
}

func f0(v float64) string { return strconv.FormatFloat(v, 'f', 0, 64) }

// num: een getal als het er precies zo uitziet (anders 0), zoals num() in de bash-agent
func numTok(v string) json.Number {
	if plainNum.MatchString(v) {
		return json.Number(v)
	}
	return json.Number("0")
}

func lines(s string) []string {
	var out []string
	for _, l := range strings.Split(s, "\n") {
		if l != "" {
			out = append(out, l)
		}
	}
	return out
}

// stats: ruwe tellers per draaiende container ("S|context|id|cpu|sys|mem|limiet|netwerk|blk in|blk uit|pids"); twee
// metingen geven het actuele CPU-gebruik. Docker meet CPU zelf: die waarde gaat als 0 en pct·10⁶ op 10⁸ door dezelfde som.
func (e *ctrEngine) stats(first bool) []string {
	var res []string
	for _, ctx := range e.ctxs {
		if ctx == "docker" {
			if first {
				out, _, _ := e.pm("docker", "ps", "-q", "--no-trunc")
				for _, id := range lines(out) {
					res = append(res, "S|docker|"+id+"|0|0|0|0|0B / 0B|0|0|0")
				}
				continue
			}
			out, _, _ := e.pm("docker", "stats", "--no-stream", "--no-trunc", "--format", "{{.ID}}|{{.CPUPerc}}|{{.MemUsage}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}")
			for _, l := range lines(out) {
				f := strings.Split(l, "|")
				for len(f) < 6 {
					f = append(f, "")
				}
				m := append(strings.Split(f[2], " / "), "")
				b := append(strings.Split(f[4], " / "), "")
				res = append(res, fmt.Sprintf("S|docker|%s|%s|100000000|%s|%s|%s|%s|%s|%s", f[0], f0(awkNum(strings.ReplaceAll(f[1], "%", ""))*1e6),
					f0(unitBytes(m[0], true)), f0(unitBytes(m[1], true)), f[3], f0(unitBytes(b[0], true)), f0(unitBytes(b[1], true)), f[5]))
			}
			continue
		}
		out, _, _ := e.pm(ctx, "stats", "--no-stream", "--no-trunc", "--format",
			"{{.ContainerStats.ContainerID}}|{{.ContainerStats.CPUNano}}|{{.ContainerStats.SystemNano}}|{{.ContainerStats.MemUsage}}|{{.ContainerStats.MemLimit}}|{{.NetIO}}|{{.ContainerStats.BlockInput}}|{{.ContainerStats.BlockOutput}}|{{.ContainerStats.PIDs}}")
		for _, l := range lines(out) {
			res = append(res, "S|"+ctx+"|"+l)
		}
	}
	return res
}

var podmanMsgRe = regexp.MustCompile(`^time="[^"]*" level=[a-z]* msg="`)
var podmanErrRe = regexp.MustCompile(`(?i)error|denied|not permitted|read-only`)

func stripPodmanMsg(s string) string {
	return strings.TrimSuffix(podmanMsgRe.ReplaceAllString(s, ""), `"`)
}

// raw: de regels V (versie), E (fout), C (container), P (pod), D (opslag) en M (volumes) per context
func (e *ctrEngine) raw() []string {
	var res []string
	for _, ctx := range e.ctxs {
		if ctx == "docker" {
			res = append(res, e.dockerLines()...)
			continue
		}
		ver, _, rc := e.pm(ctx, "version", "--format", "{{.Client.Version}}")
		if rc != 0 {
			// Podman zet waarschuwingen vóór de echte fout; die echte fout is wat telt
			_, all, rc2 := e.pm(ctx, "version")
			errTxt := ""
			for _, l := range strings.Split(all, "\n") {
				if podmanErrRe.MatchString(l) {
					errTxt = stripPodmanMsg(l)
					break
				}
			}
			home := ""
			if u, err := user.Lookup(ctx); err == nil {
				home = u.HomeDir
			}
			switch {
			case ctx != "root" && os.Getenv("INVOCATION_ID") != "" && home != "" && !writable(home):
				msg := "home-map is alleen-lezen voor de agent; voer het installatiecommando van de Go-agent opnieuw uit"
				if errTxt != "" {
					msg += " (" + errTxt + ")"
				}
				errTxt = msg
			case rc2 == 124:
				errTxt = "podman gaf binnen 60 s geen antwoord"
			case errTxt == "":
				for _, l := range strings.Split(all, "\n") {
					if strings.TrimSpace(l) != "" {
						errTxt = stripPodmanMsg(l)
					}
				}
				if errTxt == "" {
					errTxt = fmt.Sprintf("podman stopte met code %d", rc2)
				}
			}
			res = append(res, "E|"+ctx+"|"+cutRunes(strings.ReplaceAll(errTxt, "|", " "), 200))
			continue
		}
		res = append(res, "V|"+ctx+"|"+strings.TrimSpace(ver))
		out, _, _ := e.pm(ctx, "ps", "-a", "--pod", "--size", "--no-trunc", "--format",
			"{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.ExitCode}}|{{.Restarts}}|{{.PodName}}|{{.IsInfra}}|{{.StartedAt}}|{{.Size}}")
		for _, l := range lines(out) {
			res = append(res, "C|"+ctx+"|"+l)
		}
		out, _, _ = e.pm(ctx, "pod", "ps", "--format", "{{.Name}}|{{.Status}}|{{.NumberOfContainers}}")
		for _, l := range lines(out) {
			res = append(res, "P|"+ctx+"|"+l)
		}
		out, _, _ = e.pm(ctx, "system", "df", "--format", "{{.Type}}|{{.RawSize}}|{{.RawReclaimable}}|{{.Total}}|{{.Active}}")
		for _, l := range lines(out) {
			res = append(res, "D|"+ctx+"|"+l)
		}
		ids, _, _ := e.pm(ctx, "ps", "-aq", "--no-trunc")
		if f := strings.Fields(ids); len(f) > 0 {
			out, _, _ = e.pm(ctx, append([]string{"inspect", "--format", `{{.ID}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}`}, f...)...)
			for _, l := range lines(out) {
				res = append(res, "M|"+ctx+"|"+l)
			}
		}
	}
	return res
}

func writable(p string) bool {
	f, err := os.CreateTemp(p, ".rzsense-w")
	if err != nil {
		return false
	}
	f.Close()
	_ = os.Remove(f.Name())
	return true
}

// dockerLines: Docker in dezelfde regels als Podman (compose-projecten als pods)
func (e *ctrEngine) dockerLines() []string {
	ver, _, rc := e.pm("docker", "version", "--format", "{{.Server.Version}}")
	if rc != 0 {
		return []string{"E|docker|docker gaf geen antwoord"}
	}
	res := []string{"V|docker|docker " + strings.TrimSpace(ver)}
	idsOut, _, _ := e.pm("docker", "ps", "-aq", "--no-trunc")
	ids := strings.Fields(idsOut)
	if len(ids) == 0 {
		return res
	}
	ins := map[string]string{}
	out, _, _ := e.pm("docker", append([]string{"inspect", "--format",
		`{{.Id}}|{{.State.ExitCode}}|{{.RestartCount}}|{{.State.StartedAt}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}`}, ids...)...)
	for _, l := range lines(out) {
		f := strings.SplitN(l, "|", 5)
		for len(f) < 5 {
			f = append(f, "")
		}
		start := int64(0)
		if t, err := time.Parse(time.RFC3339Nano, f[3]); err == nil {
			start = t.Unix()
		}
		ins[f[0]] = fmt.Sprintf("%s|%s|%d", f[1], f[2], start)
		res = append(res, "M|docker|"+f[0]+"|"+f[4])
	}
	out, _, _ = e.pm("docker", "ps", "-a", "--size", "--no-trunc", "--format", `{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.Label "com.docker.compose.project"}}|{{.Size}}`)
	for _, l := range lines(out) {
		f := strings.SplitN(l, "|", 7)
		for len(f) < 7 {
			f = append(f, "")
		}
		in, ok := ins[f[0]]
		if !ok {
			in = "0|0|0"
		}
		p := strings.Split(in, "|")
		res = append(res, fmt.Sprintf("C|docker|%s|%s|%s|%s|%s|%s|%s|%s|false|%s|%s", f[0], f[1], f[2], f[3], f[4], p[0], p[1], f[5], p[2], f[6]))
	}
	out, _, _ = e.pm("docker", "ps", "-a", "--format", `{{.Label "com.docker.compose.project"}}|{{.State}}`)
	total, running := map[string]int{}, map[string]int{}
	var projects []string
	for _, l := range lines(out) {
		f := strings.SplitN(l, "|", 2)
		if len(f) < 2 || f[0] == "" {
			continue
		}
		if total[f[0]] == 0 {
			projects = append(projects, f[0])
		}
		total[f[0]]++
		if f[1] == "running" {
			running[f[0]]++
		}
	}
	sort.Strings(projects)
	for _, p := range projects {
		st := "Exited"
		if running[p] == total[p] {
			st = "Running"
		} else if running[p] > 0 {
			st = "Degraded"
		}
		res = append(res, fmt.Sprintf("P|docker|%s|%s|%d", p, st, total[p]))
	}
	out, _, _ = e.pm("docker", "system", "df", "--format", "{{.Type}}|{{.Size}}|{{.Reclaimable}}|{{.TotalCount}}|{{.Active}}")
	for _, l := range lines(out) {
		f := strings.Split(l, "|")
		for len(f) < 5 {
			f = append(f, "")
		}
		res = append(res, fmt.Sprintf("D|docker|%s|%s|%s|%s|%s", f[0], f0(unitBytes(f[1], true)), f0(unitBytes(f[2], true)), f[3], f[4]))
	}
	return res
}

// volumeSizes: de grootte van elk volume (du), een uur bewaard: ze kunnen groot zijn en veranderen langzaam
func volumeSizes(raw []string) map[string]float64 {
	set := map[string]bool{}
	for _, l := range raw {
		if f := strings.Split(l, "|"); f[0] == "M" && len(f) >= 4 {
			for _, v := range strings.Split(f[3], ";") {
				if v != "" {
					set[v] = true
				}
			}
		}
	}
	sizes := map[string]float64{}
	if len(set) == 0 {
		return sizes
	}
	cache := filepath.Join(dataDir(), "volumes.cache")
	if st, err := os.Stat(cache); err == nil && time.Since(st.ModTime()) < time.Hour {
		if b, err := os.ReadFile(cache); err == nil && json.Unmarshal(b, &sizes) == nil {
			return sizes
		}
	}
	for v := range set {
		out, _ := run(120*time.Second, "du", "-sb", v)
		if f := strings.Fields(out); len(f) > 0 && plainNum.MatchString(f[0]) {
			sizes[v] = awkNum(f[0])
		}
	}
	if b, err := json.Marshal(sizes); err == nil {
		_ = os.WriteFile(cache, b, 0o600)
	}
	return sizes
}

// podmanJSON: de regels van twee metingen samenvoegen tot het onderdeel "podman" van het rapport
func podmanJSON(a, b, raw []string, vsize map[string]float64) map[string]any {
	type st struct{ cpu, sys float64 }
	aS, bS := map[string]st{}, map[string]st{}
	type more struct{ mem, lim, ni, no, bi, bo, pids json.Number }
	extra := map[string]more{}
	for _, l := range a {
		if f := strings.Split(l, "|"); len(f) >= 5 {
			aS[f[2]] = st{awkNum(f[3]), awkNum(f[4])}
		}
	}
	for _, l := range b {
		f := strings.Split(l, "|")
		if len(f) < 11 {
			continue
		}
		bS[f[2]] = st{awkNum(f[3]), awkNum(f[4])}
		nio := append(strings.Split(f[7], " / "), "")
		extra[f[2]] = more{numTok(f[5]), numTok(f[6]), numTok(f0(unitBytes(nio[0], false))), numTok(f0(unitBytes(nio[1], false))), numTok(f[8]), numTok(f[9]), numTok(f[10])}
	}
	version := ""
	users := []string{}
	var errs []string
	pods := []map[string]any{}
	containers := []map[string]any{}
	dfSize := map[string]float64{}
	reclaim, images := 0.0, 0.0
	cvol := map[string]string{}
	var cLines [][]string
	for _, l := range raw {
		f := strings.Split(l, "|")
		for len(f) < 13 {
			f = append(f, "")
		}
		switch f[0] {
		case "V":
			if version == "" {
				version = f[2]
			}
			users = append(users, f[1])
		case "E":
			errs = append(errs, f[1]+": "+f[2])
		case "C":
			cLines = append(cLines, f)
		case "P":
			pods = append(pods, map[string]any{"name": f[2], "user": f[1], "status": f[3], "containers": int(awkNum(string(numTok(f[4]))))})
		case "D":
			t := strings.ToLower(f[2])
			dfSize[t] += awkNum(string(numTok(f[3])))
			reclaim += awkNum(string(numTok(f[4])))
			if t == "images" {
				images += awkNum(string(numTok(f[5])))
			}
		case "M":
			cvol[f[2]] = f[3]
		}
	}
	for _, f := range cLines {
		if f[10] == "true" { // infra-container van een pod
			continue
		}
		id := f[2]
		health := ""
		switch {
		case strings.Contains(f[6], "(healthy)"):
			health = "healthy"
		case strings.Contains(f[6], "(unhealthy)"):
			health = "unhealthy"
		case strings.Contains(f[6], "(starting)"):
			health = "starting"
		}
		var cpu any
		if x, ok := aS[id]; ok {
			if y, ok := bS[id]; ok && y.sys > x.sys {
				cpu = json.Number(strconv.FormatFloat((y.cpu-x.cpu)/(y.sys-x.sys)*100, 'f', 2, 64))
			}
		}
		vb := 0.0
		for _, v := range strings.Split(cvol[id], ";") {
			if v != "" {
				vb += vsize[v]
			}
		}
		c := map[string]any{"name": f[3], "user": f[1], "pod": f[9], "image": f[4], "state": f[5], "status": f[6], "health": health,
			"exit_code": int(awkNum(string(numTok(f[7])))), "restarts": int(awkNum(string(numTok(f[8])))), "started": json.Number(f0(awkNum(string(numTok(f[11]))))),
			"cpu": cpu, "mem": nil, "mem_limit": nil, "net_in": nil, "net_out": nil, "blk_in": nil, "blk_out": nil, "pids": nil,
			"size_rw": json.Number(f0(unitBytes(f[12], false))), "volumes": json.Number(f0(vb))}
		if m, ok := extra[id]; ok {
			c["mem"], c["mem_limit"], c["net_in"], c["net_out"], c["blk_in"], c["blk_out"], c["pids"] = m.mem, m.lim, m.ni, m.no, m.bi, m.bo, m.pids
		}
		containers = append(containers, c)
	}
	return map[string]any{"version": version, "error": strings.Join(errs, "; "), "users": users, "images": int(images), "images_size": json.Number(f0(dfSize["images"])),
		"containers_size": json.Number(f0(dfSize["containers"])), "volumes_size": json.Number(f0(dfSize["local volumes"])), "reclaimable": json.Number(f0(reclaim)),
		"pods": pods, "containers": containers}
}

// collectContainersLinux: het onderdeel "podman" (nil zonder podman en docker) en wat deze ronde niet antwoordde.
// between: wat er tussen de twee metingen gebeurt (de rest van de ronde); zonder: 2 seconden wachten.
func collectContainersLinux(between func()) (podman map[string]any, busy string) {
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	e := &ctrEngine{seen: map[string]bool{}}
	e.contexts(conf)
	if len(e.ctxs) == 0 {
		if between != nil {
			between()
		}
		return nil, strings.Join(e.busy, " ")
	}
	a := e.stats(true)
	if between != nil {
		between()
	} else {
		time.Sleep(2 * time.Second)
	}
	b := e.stats(false)
	raw := e.raw()
	return podmanJSON(a, b, raw, volumeSizes(raw)), strings.Join(e.busy, " ")
}

// ---------------------------------------------------------------- heartbeats (rzmon-hb)

var hbLineRe = regexp.MustCompile(`^\{"n":"[A-Za-z0-9][A-Za-z0-9._-]{0,63}","s":"(start|ok|fail)","t":[0-9]{1,12}(,"v":-?[0-9]+(\.[0-9]+)?)?,"m":"([^"\\]|\\.)*"\}$`)

func hbDir(conf map[string]string) string {
	if d := conf["HB_DIR"]; d != "" {
		return d
	}
	if d := conf["AGENT_DIR"]; d != "" {
		return d + "/hb"
	}
	return "/var/spool/rzmon-agent"
}

var sentHbFiles []string // de heartbeats die met dit rapport meegingen

// collectHeartbeatsLinux: de regels die rzmon-hb in de wachtrij zette: oudste eerst, hooguit 100, alleen in precies de
// vorm die rzmon-hb schrijft (iedereen mag in de map schrijven). Een ongeldig bestand gaat weg.
func collectHeartbeatsLinux() []json.RawMessage {
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	dir := hbDir(conf)
	sentHbFiles = nil
	ents, err := os.ReadDir(dir)
	if err != nil {
		if os.Geteuid() == 0 && os.MkdirAll(dir, 0o755) == nil {
			_ = os.Chmod(dir, 0o1733)
		}
		return nil
	}
	type hbFile struct {
		path string
		mod  time.Time
	}
	var files []hbFile
	for _, e := range ents {
		if !strings.HasPrefix(e.Name(), "hb.") {
			continue
		}
		fi, err := os.Lstat(filepath.Join(dir, e.Name()))
		if err != nil || !fi.Mode().IsRegular() { // geen symlinks
			continue
		}
		files = append(files, hbFile{filepath.Join(dir, e.Name()), fi.ModTime()})
	}
	sort.SliceStable(files, func(i, j int) bool { return files[i].mod.Before(files[j].mod) })
	if len(files) > 200 {
		files = files[:200]
	}
	var out []json.RawMessage
	for _, f := range files {
		b := make([]byte, 1000)
		fh, err := os.Open(f.path)
		if err != nil {
			continue
		}
		n, _ := fh.Read(b)
		fh.Close()
		line, _, _ := strings.Cut(string(b[:n]), "\n")
		if len([]rune(line)) <= 900 && hbLineRe.MatchString(line) && json.Valid([]byte(line)) {
			out = append(out, json.RawMessage(line))
			sentHbFiles = append(sentHbFiles, f.path)
		} else {
			_ = os.Remove(f.path) // geen geldige regel: weg
		}
		if len(sentHbFiles) >= 100 {
			break
		}
	}
	if os.Geteuid() == 0 {
		_ = os.Chmod(dir, 0o1733) // iedereen mag erin schrijven, niemand mag lezen of andermans regels weghalen
	}
	return out
}

// heartbeatsSent: RzSense heeft het rapport: de meegestuurde heartbeats mogen weg
func heartbeatsSent() {
	for _, f := range sentHbFiles {
		_ = os.Remove(f)
	}
	sentHbFiles = nil
}
