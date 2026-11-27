//go:build linux

package main

// De kern van de Go-agent voor Linux (0.8.0): meten wat de bash-agent ook meet (hostnaam, opstarttijd, CPU, geheugen,
// schijven, en vanaf 0.9.0 luisterende poorten en diensten), met precies dezelfde namen in het rapport. In deze eerste versie draait hij als proef náást de bash-agent:
// hij leest diens instellingen en sleutel (alleen lezen), en stuurt zijn metingen naar RzSense om te vergelijken.
// Hij verandert niets op de server en neemt niets over.

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Waar de bash-agent zijn instellingen bewaart (te overschrijven voor tests)
func bashConfPath() string {
	if p := os.Getenv("RZSENSE_BASH_CONF"); p != "" {
		return p
	}
	return "/etc/default/rzmon-agent"
}

// bashKeyDirs: waar de eigen sleutel van de bash-agent kan staan (zelfde volgorde als de bash-agent)
func bashKeyDirs(conf map[string]string) []string {
	if d := os.Getenv("RZSENSE_BASH_KEYS"); d != "" {
		return []string{d}
	}
	var dirs []string
	if d := conf["AGENT_DIR"]; d != "" {
		dirs = append(dirs, filepath.Join(d, "keys"))
	}
	return append(dirs, "/var/lib/rzmon-agent", "/data/rzmon-keys")
}

var confLineRe = regexp.MustCompile(`^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$`)

// readShellConf: NAAM=waarde of NAAM="waarde" per regel (zoals /etc/default/rzmon-agent); er wordt niets uitgevoerd
func readShellConf(path string) (map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	out := map[string]string{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		m := confLineRe.FindStringSubmatch(sc.Text())
		if m == nil {
			continue
		}
		v := strings.TrimSpace(m[2])
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = v[1 : len(v)-1]
		}
		out[m[1]] = v
	}
	return out, sc.Err()
}

// loadBashConfig: adres, reserve-adres, vingerafdrukken en de eigen sleutel van de bash-agent. Alleen lezen.
func loadBashConfig() (*Config, error) {
	conf, err := readShellConf(bashConfPath())
	if err != nil {
		return nil, fmt.Errorf("instellingen van de agent niet te lezen (%s): %w", bashConfPath(), err)
	}
	c := &Config{RemoteUpdate: false, extra: map[string]string{}}
	c.URL = strings.TrimRight(conf["MONITOR_URL"], "/")
	c.URLFallback = strings.TrimRight(conf["MONITOR_URL_FALLBACK"], "/")
	if strings.HasPrefix(conf["PIN"], "sha256//") {
		c.Pin = conf["PIN"]
	}
	for _, dir := range bashKeyDirs(conf) {
		b, err := os.ReadFile(filepath.Join(dir, "agent.key"))
		if err != nil {
			continue
		}
		key := strings.Map(func(r rune) rune {
			if r >= '0' && r <= '9' || r >= 'a' && r <= 'f' {
				return r
			}
			return -1
		}, string(b))
		if len(key) >= 64 && hexKeyRe.MatchString(key[:64]) {
			c.Key = key[:64]
			c.extra["key_dir"] = dir
			// Hoe elk adres gecontroleerd wordt: "adres <spatie> sha256//… of system" (url.pins van de bash-agent)
			if pins, err := os.ReadFile(filepath.Join(dir, "url.pins")); err == nil {
				for _, line := range strings.Split(string(pins), "\n") {
					f := strings.Fields(line)
					if len(f) < 2 || !strings.HasPrefix(f[1], "sha256//") {
						continue
					}
					switch strings.TrimRight(f[0], "/") {
					case c.URL:
						c.Pin = f[1]
					case c.URLFallback:
						c.PinFallback = f[1]
					}
				}
			}
			break
		}
	}
	return c, nil
}

// ---------------------------------------------------------------- meten

// cpuTimes: totaal en idle (idle + iowait) uit de eerste regel van /proc/stat, zoals de bash-agent
func cpuTimes(stat string) (total, idle uint64, ok bool) {
	line, _, _ := strings.Cut(stat, "\n")
	f := strings.Fields(line)
	if len(f) < 5 || f[0] != "cpu" {
		return 0, 0, false
	}
	for i, s := range f[1:] {
		if i >= 8 { // user nice system idle iowait irq softirq steal
			break
		}
		n, err := strconv.ParseUint(s, 10, 64)
		if err != nil {
			return 0, 0, false
		}
		total += n
		if i == 3 || i == 4 {
			idle += n
		}
	}
	return total, idle, true
}

func cpuCores(stat string) int {
	n := 0
	for _, l := range strings.Split(stat, "\n") {
		if len(l) > 3 && strings.HasPrefix(l, "cpu") && l[3] >= '0' && l[3] <= '9' {
			n++
		}
	}
	return n
}

func bootTime(stat string) int64 {
	for _, l := range strings.Split(stat, "\n") {
		if v, ok := strings.CutPrefix(l, "btime "); ok {
			n, _ := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			return n
		}
	}
	return 0
}

// memInfo: bytes uit /proc/meminfo (kB × 1024), met de namen van het rapport
func memInfo(text string) map[string]any {
	want := map[string]string{"MemTotal:": "total", "MemAvailable:": "avail", "SwapTotal:": "swap_total", "SwapFree:": "swap_free"}
	out := map[string]any{"total": int64(0), "avail": int64(0), "swap_total": int64(0), "swap_free": int64(0)}
	for _, l := range strings.Split(text, "\n") {
		f := strings.Fields(l)
		if len(f) < 2 {
			continue
		}
		if k, ok := want[f[0]]; ok {
			n, _ := strconv.ParseInt(f[1], 10, 64)
			out[k] = n * 1024
		}
	}
	return out
}

// De bestandssystemen die de bash-agent overslaat (df -x …)
var skipFS = map[string]bool{"tmpfs": true, "devtmpfs": true, "squashfs": true, "overlay": true, "efivarfs": true}

type mountEntry struct{ dev, mount, fs string }

// parseMounts: /proc/self/mounts; spaties en dergelijke staan er als \040 in
func parseMounts(text string) []mountEntry {
	unesc := strings.NewReplacer(`\040`, " ", `\011`, "\t", `\012`, "\n", `\134`, `\`)
	var out []mountEntry
	for _, l := range strings.Split(text, "\n") {
		f := strings.Fields(l)
		if len(f) < 3 {
			continue
		}
		out = append(out, mountEntry{dev: unesc.Replace(f[0]), mount: unesc.Replace(f[1]), fs: f[2]})
	}
	return out
}

// diskList: per bestandssysteem het koppelpunt, de grootte en de vrije ruimte in bytes, zoals "df -P -B1" ze geeft:
// zonder de overgeslagen soorten, zonder bestandssystemen van 0 blokken (proc, sysfs …), en elk apparaat één keer
// (het kortste koppelpunt, zoals df).
func diskList(mounts []mountEntry) []map[string]any {
	type row struct {
		mount        string
		total, avail uint64
	}
	byDev := map[uint64]row{}
	var order []uint64
	for _, m := range mounts {
		if skipFS[m.fs] {
			continue
		}
		var fs syscall.Statfs_t
		if err := syscall.Statfs(m.mount, &fs); err != nil || fs.Blocks == 0 {
			continue
		}
		var st syscall.Stat_t
		if err := syscall.Stat(m.mount, &st); err != nil {
			continue
		}
		bs := uint64(fs.Frsize)
		if bs == 0 {
			bs = uint64(fs.Bsize)
		}
		r := row{mount: m.mount, total: fs.Blocks * bs, avail: fs.Bavail * bs}
		dev := uint64(st.Dev)
		if old, seen := byDev[dev]; seen {
			if len(r.mount) < len(old.mount) {
				byDev[dev] = r
			}
			continue
		}
		byDev[dev] = r
		order = append(order, dev)
	}
	out := make([]map[string]any, 0, len(order))
	for _, dev := range order {
		r := byDev[dev]
		out = append(out, map[string]any{"mount": r.mount, "total": r.total, "avail": r.avail})
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i]["mount"].(string) < out[j]["mount"].(string) })
	return out
}

func readText(path string) string {
	b, _ := os.ReadFile(path)
	return string(b)
}

// coreCollect: de kernmetingen. wait = hoe lang tussen de twee CPU-metingen (de bash-agent gebruikt 2 seconden).
func coreCollect(wait time.Duration) map[string]any {
	host, _ := os.Hostname()
	s1 := readText("/proc/stat")
	time.Sleep(wait)
	s2 := readText("/proc/stat")
	pct := 0.0
	if t1, i1, ok1 := cpuTimes(s1); ok1 {
		if t2, i2, ok2 := cpuTimes(s2); ok2 && t2 > t1 {
			pct = (1 - float64(i2-i1)/float64(t2-t1)) * 100
		}
	}
	load := []float64{0, 0, 0}
	for i, s := range strings.Fields(readText("/proc/loadavg")) {
		if i >= 3 {
			break
		}
		load[i], _ = strconv.ParseFloat(s, 64)
	}
	procs, procNames := collectProcsLinux() // 0.10.0
	return map[string]any{
		"certs":     collectCertsLinux(), // 0.10.0
		"procs":     procs,
		"proc_list": procNames,
		"svc":       collectSvcDetailLinux(), // 0.11.0
		"svc_tests": collectSvcTestsLinux(),
		"svc_list":  collectSvcListLinux(),
		"hostname":  host,
		"boot":      bootTime(s2),
		"cpu":       map[string]any{"pct": float64(int(pct*10+0.5)) / 10, "load": load, "cores": cpuCores(s2)},
		"mem":       memInfo(readText("/proc/meminfo")),
		"disks":     diskList(parseMounts(readText("/proc/self/mounts"))),
		"ports":     collectPortsLinux(),    // 0.9.0
		"services":  collectServicesLinux(), // 0.9.0
	}
}
