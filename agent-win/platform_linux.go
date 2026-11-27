//go:build linux

package main

// Proxmox VE en TrueNAS SCALE (0.23.0), zoals de bash-agent (agent/src/70-containers-en-proxmox.sh en 72-truenas.sh).
// Alleen lezen, alleen als root, en alleen op een server waar pvesh of midclt staat:
//   - Proxmox: guests (VM's en containers), opslag, ZFS-pools, de cluster, back-ups per guest en de back-uptaken. Guests
//     en opslag elke 2 minuten, back-ups en taken elk kwartier. PVE=0 zet het uit.
//   - TrueNAS: meldingen, taken en apps elke 5 minuten, schijftemperaturen elk kwartier, of er een update is elke 6 uur.
//     TRUENAS=0 zet het uit. De agent leest TrueNAS alleen uit; bijwerken gaat nooit via de agent.
// pvesh en midclt geven JSON; dat gaat ongewijzigd mee (RzSense leest het).

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// jsonOut: uitvoer die JSON hoort te zijn (begint met [ of {), ongewijzigd; anders null
func jsonOut(out string) json.RawMessage {
	s := strings.TrimSpace(out)
	if (strings.HasPrefix(s, "[") || strings.HasPrefix(s, "{")) && json.Valid([]byte(s)) {
		return json.RawMessage(s)
	}
	return json.RawMessage("null")
}

// stdoutOf: alleen de stdout van een commando (stderr weg, zoals 2>/dev/null), met tijdslimiet
func stdoutOf(timeout time.Duration, name string, args ...string) (string, int) {
	e := &ctrEngine{}
	out, _, rc := e.out(timeout, "", nil, name, args...)
	return out, rc
}

// cachedPart: een deel van het blok dat maar af en toe opnieuw gemeten wordt (bestand in de eigen map)
func cachedPart(file string, every time.Duration, build func() map[string]any) map[string]any {
	if st, err := os.Stat(file); err == nil && st.Size() > 0 && time.Since(st.ModTime()) < every-5*time.Second {
		var m map[string]any
		if b, err := os.ReadFile(file); err == nil && json.Unmarshal(b, &m) == nil {
			return m
		}
	}
	m := build()
	if b, err := json.Marshal(m); err == nil {
		tmp := file + ".tmp"
		if os.WriteFile(tmp, b, 0o600) == nil {
			_ = os.Rename(tmp, file)
		}
	}
	// via JSON heen en terug, zodat een verse meting er precies zo uitziet als een bewaarde
	var back map[string]any
	if b, err := json.Marshal(m); err == nil && json.Unmarshal(b, &back) == nil {
		return back
	}
	return m
}

// ---------------------------------------------------------------- Proxmox VE

var (
	pveOnboot   = regexp.MustCompile(`^onboot: *1`)
	pveAgent    = regexp.MustCompile(`^agent: *(1|enabled=1)`)
	pveTemplate = regexp.MustCompile(`^template: *1`)
	pveStoreRe  = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)
	pveBkLocal  = regexp.MustCompile(`[0-9]{4}_[0-9]{2}_[0-9]{2}-[0-9]{2}_[0-9]{2}_[0-9]{2}`)
	pveBkPBS    = regexp.MustCompile(`[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z`)
	zfsScanRe   = regexp.MustCompile(`(?m)^ *scan: *(.*)$`)
)

// pveGuestConf: uit één configuratiebestand (alleen het deel vóór [snapshots]): automatisch starten, guest agent, sjabloon
func pveGuestConf(text string) (onboot, agent, template int) {
	for _, l := range strings.Split(text, "\n") {
		if strings.HasPrefix(l, "[") {
			break
		}
		if pveOnboot.MatchString(l) {
			onboot = 1
		}
		if pveAgent.MatchString(l) {
			agent = 1
		}
		if pveTemplate.MatchString(l) {
			template = 1
		}
	}
	return
}

// pveConfList: [["qemu"|"lxc", vmid, onboot, agent, sjabloon] …] uit /etc/pve
func pveConfList(root string) [][]any {
	out := [][]any{}
	for _, kind := range []string{"qemu-server", "lxc"} {
		files, _ := filepath.Glob(filepath.Join(root, kind, "*.conf"))
		for _, f := range files {
			id := strings.TrimSuffix(filepath.Base(f), ".conf")
			if !reDigits.MatchString(id) {
				continue
			}
			ob, ag, tp := pveGuestConf(readText(f))
			t := "qemu"
			if kind == "lxc" {
				t = "lxc"
			}
			out = append(out, []any{t, json.Number(id), ob, ag, tp})
		}
	}
	return out
}

// pveBackupDate: de datum in de naam van een back-up (vzdump-bestand of Proxmox Backup Server), of ""
func pveBackupDate(volid string) string {
	if m := pveBkLocal.FindString(volid); m != "" {
		d := strings.ReplaceAll(m, "_", "-")
		return d[:10] + " " + d[11:13] + ":" + d[14:16] + ":" + d[17:19]
	}
	if m := pveBkPBS.FindString(volid); m != "" {
		return m[:10] + " " + m[11:19] + "Z"
	}
	return ""
}

// pveBackups: regels "opslag <tab> volume <tab> vmid" → per guest de nieuwste back-up, het aantal en waar hij staat
func pveBackups(rows [][3]string) map[string][]any {
	last, where := map[string]string{}, map[string]string{}
	n := map[string]int{}
	for _, r := range rows {
		d := pveBackupDate(r[1])
		if !reDigits.MatchString(r[2]) || d == "" {
			continue
		}
		n[r[2]]++
		if d > last[r[2]] {
			last[r[2]], where[r[2]] = d, r[0]
		}
	}
	out := map[string][]any{}
	for id := range n {
		out[id] = []any{last[id], n[id], where[id]}
	}
	return out
}

func pvesh(timeout time.Duration, args ...string) json.RawMessage {
	out, _ := stdoutOf(timeout, "pvesh", append(append([]string{"get"}, args...), "--output-format", "json")...)
	return jsonOut(out)
}

// collectPveLinux: het onderdeel "pve" van het rapport, of nil als dit geen Proxmox-host is
func collectPveLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	if conf["PVE"] == "0" || !haveCmd("pvesh") || !isRoot() {
		return nil
	}
	dir := filepath.Join(dataDir(), "pve")
	_ = os.MkdirAll(dir, 0o700)
	node, _ := os.Hostname()
	node, _, _ = strings.Cut(node, ".") // Proxmox noemt de node naar de korte hostnaam
	live := cachedPart(filepath.Join(dir, "live.json"), 120*time.Second, func() map[string]any {
		zfs := []map[string]any{}
		if haveCmd("zpool") {
			out, _ := stdoutOf(10*time.Second, "zpool", "list", "-Hp", "-o", "name,health,size,alloc,capacity")
			for _, l := range lines(out) {
				f := strings.Split(l, "\t")
				for len(f) < 5 {
					f = append(f, "")
				}
				if f[0] == "" {
					continue
				}
				st, _ := stdoutOf(10*time.Second, "zpool", "status", f[0])
				scan := ""
				if m := zfsScanRe.FindStringSubmatch(st); m != nil {
					scan = m[1]
				}
				zfs = append(zfs, map[string]any{"name": cleanText(f[0]), "health": cleanText(f[1]), "size": numTok(f[2]), "alloc": numTok(f[3]),
					"cap": numTok(strings.TrimSuffix(f[4], "%")), "scan": cleanText(scan)})
			}
		}
		ver, _ := stdoutOf(10*time.Second, "pveversion")
		ver, _, _ = strings.Cut(ver, "\n")
		return map[string]any{"node": node, "version": cleanText(ver), "qemu": pvesh(20*time.Second, "/nodes/"+node+"/qemu"), "lxc": pvesh(20*time.Second, "/nodes/"+node+"/lxc"),
			"storage": pvesh(30*time.Second, "/nodes/"+node+"/storage"), "cluster": pvesh(15*time.Second, "/cluster/status"), "conf": pveConfList("/etc/pve"), "zfs": zfs}
	})
	slow := cachedPart(filepath.Join(dir, "slow.json"), 900*time.Second, func() map[string]any {
		// Back-ups per guest: de nieuwste per VMID uit elke opslag met back-ups
		var rows [][3]string
		st, _ := stdoutOf(20*time.Second, "pvesm", "status", "--content", "backup")
		for i, l := range lines(st) {
			f := strings.Fields(l)
			if i == 0 || len(f) < 3 || f[2] != "active" || !pveStoreRe.MatchString(f[0]) {
				continue
			}
			list, _ := stdoutOf(60*time.Second, "pvesm", "list", f[0], "--content", "backup")
			for j, r := range lines(list) {
				if g := strings.Fields(r); j > 0 && len(g) > 0 {
					rows = append(rows, [3]string{f[0], g[0], g[len(g)-1]})
				}
			}
		}
		// Guest agent: antwoordt hij (alleen draaiende VM's met de agent aan)?
		ga := map[string]int{}
		files, _ := filepath.Glob("/etc/pve/qemu-server/*.conf")
		for _, f := range files {
			id := strings.TrimSuffix(filepath.Base(f), ".conf")
			if _, agent, _ := pveGuestConf(readText(f)); agent != 1 {
				continue
			}
			if _, err := os.Stat("/var/run/qemu-server/" + id + ".pid"); err != nil {
				continue
			}
			if _, rc := stdoutOf(4*time.Second, "qm", "agent", id, "ping"); rc == 0 {
				ga[id] = 1
			} else {
				ga[id] = 0
			}
		}
		return map[string]any{"backups": pveBackups(rows), "jobs": pvesh(15*time.Second, "/cluster/backup"),
			"tasks": pvesh(20*time.Second, "/nodes/"+node+"/tasks", "--typefilter", "vzdump", "--limit", "30"), "gagent": ga}
	})
	out := map[string]any{"at": time.Now().Unix()}
	for k, v := range live {
		out[k] = v
	}
	for k, v := range slow {
		out[k] = v
	}
	return out
}

// ---------------------------------------------------------------- TrueNAS SCALE

func midclt(timeout time.Duration, args ...string) json.RawMessage {
	out, _ := stdoutOf(timeout, "midclt", append([]string{"call"}, args...)...)
	if len(out) > 200000 {
		out = out[:200000] // hooguit 200 kB; afgekapt is het geen geldige JSON meer en gaat er null mee
	}
	return jsonOut(out)
}

// collectTruenasLinux: het onderdeel "truenas" van het rapport, of nil als dit geen TrueNAS is
func collectTruenasLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	if conf["TRUENAS"] == "0" || !haveCmd("midclt") || !isRoot() {
		return nil
	}
	dir := filepath.Join(dataDir(), "truenas")
	_ = os.MkdirAll(dir, 0o700)
	out := map[string]any{"at": time.Now().Unix()}
	add := func(m map[string]any) {
		for k, v := range m {
			out[k] = v
		}
	}
	add(cachedPart(filepath.Join(dir, "live.json"), 300*time.Second, func() map[string]any {
		ver := readText("/etc/version")
		if len(ver) > 60 {
			ver = ver[:60]
		}
		return map[string]any{"version": cleanText(strings.TrimRight(ver, "\n")), "alerts": midclt(20*time.Second, "alert.list"),
			"repl":  midclt(20*time.Second, "replication.query", "[]", `{"select":["id","name","enabled","state"]}`),
			"snap":  midclt(20*time.Second, "pool.snapshottask.query", "[]", `{"select":["id","dataset","enabled","state"]}`),
			"cloud": midclt(20*time.Second, "cloudsync.query", "[]", `{"select":["id","description","enabled","job"]}`),
			"rsync": midclt(20*time.Second, "rsynctask.query", "[]", `{"select":["id","desc","path","enabled","job"]}`),
			"apps":  midclt(30*time.Second, "app.query", "[]", `{"select":["name","state","upgrade_available","version"]}`)}
	}))
	add(cachedPart(filepath.Join(dir, "temp.json"), 900*time.Second, func() map[string]any {
		return map[string]any{"temps": midclt(60*time.Second, "disk.temperatures")}
	}))
	add(cachedPart(filepath.Join(dir, "upd.json"), 21600*time.Second, func() map[string]any {
		// Nieuwere TrueNAS: update.status; oudere: update.check_available
		u := midclt(60*time.Second, "update.status")
		if string(u) == "null" {
			u = midclt(60*time.Second, "update.check_available")
		}
		return map[string]any{"update": u}
	}))
	return out
}
