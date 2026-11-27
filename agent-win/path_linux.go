//go:build linux

package main

// Pad naar een doel (0.17.0), zoals de bash-agent (agent/src/74-pad.sh). Per pad-check uit RzSense:
// elke hop met verlies en vertraging (mtr, anders traceroute), een ping naar de opgegeven tussenliggende apparaten, de
// netwerkkaart van de server (snelheid), of hij virtueel draait en, met lldpd, op welke switch en poort hij zit.
// Doorvoer (iperf3), alleen als de Go-agent het werk doet: nu meten naar een apparaat ("pathtp"), of hier kort een
// ontvanger starten die na één meting stopt ("iperfs").

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

var (
	pathHostRe = regexp.MustCompile(`^[A-Za-z0-9.:_-]{1,253}$`)
	portNumRe  = regexp.MustCompile(`^[0-9]{2,5}$`)
	tpKeyRe    = regexp.MustCompile(`^[0-9A-Za-z]{1,40}$`)
	devNameRe  = regexp.MustCompile(`^[A-Za-z0-9._@-]{1,32}$`)
	mtrHopRe   = regexp.MustCompile(`^[0-9]+\.\|--$`)
	numTokRe   = regexp.MustCompile(`^[0-9.]+$`)
	trTimeRe   = regexp.MustCompile(`^[0-9.:a-f]+$`)
)

// rawNum: een getal uit de uitvoer van ping of traceroute ongewijzigd doorgeven, of null
func rawNum(s string) any {
	if numTokRe.MatchString(s) {
		if _, err := strconv.ParseFloat(s, 64); err == nil {
			return json.Number(s)
		}
	}
	return nil
}

// parsePing: uitvoer van "ping -n -c 5" → verlies en vertraging (min/gem/max/mdev)
func parsePing(ip, out string) map[string]any {
	loss := 100.0
	var mn, av, mx, jd any
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if strings.Contains(l, "packet loss") {
			for _, w := range f {
				if strings.HasSuffix(w, "%") {
					loss = awkNum(strings.TrimSuffix(w, "%"))
				}
			}
		}
		if (strings.HasPrefix(l, "rtt") || strings.HasPrefix(l, "round-trip")) && len(f) >= 4 {
			a := strings.Split(f[3], "/")
			for len(a) < 4 {
				a = append(a, "")
			}
			mn, av, mx, jd = rawNum(a[0]), rawNum(a[1]), rawNum(a[2]), rawNum(a[3])
		}
	}
	return map[string]any{"ip": ip, "loss": loss, "avg": av, "best": mn, "worst": mx, "jit": jd}
}

func pingHost(ip string) map[string]any {
	out, _ := run(8*time.Second, "ping", "-n", "-c", "5", "-i", "0.2", "-W", "1", ip)
	return parsePing(ip, out)
}

// parseMtr: "  1.|-- 192.168.2.1   0.0%   5   0.4   0.5   0.3   0.8   0.1"
func parseMtr(out string) []map[string]any {
	hops := []map[string]any{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) < 4 || !mtrHopRe.MatchString(f[0]) {
			continue
		}
		for len(f) < 9 {
			f = append(f, "")
		}
		ip := f[1]
		if ip == "???" {
			ip = ""
		}
		h := map[string]any{"n": int(awkNum(f[0])), "ip": ip, "loss": awkNum(strings.ReplaceAll(f[2], "%", "")), "sent": awkNum(f[3]), "avg": nil, "best": nil, "worst": nil, "jit": nil}
		if ip != "" {
			h["avg"], h["best"], h["worst"], h["jit"] = awkNum(f[5]), awkNum(f[6]), awkNum(f[7]), awkNum(f[8])
		}
		hops = append(hops, h)
	}
	return hops
}

// parseTraceroute: " 1  192.168.2.1  0.512 ms  0.433 ms  0.401 ms" of " 2  * * *"
func parseTraceroute(out string) []map[string]any {
	hops := []map[string]any{}
	for i, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if i == 0 || len(f) == 0 || !reDigits.MatchString(f[0]) {
			continue
		}
		ip, c, stars, sum := "", 0, 0, 0.0
		mn, mx := "", ""
		for j := 1; j < len(f); j++ {
			next := ""
			if j+1 < len(f) {
				next = f[j+1]
			}
			switch {
			case f[j] == "*":
				stars++
			case trTimeRe.MatchString(f[j]) && next == "ms":
				v := awkNum(f[j])
				c++
				sum += v
				if mn == "" || v < awkNum(mn) {
					mn = f[j]
				}
				if mx == "" || v > awkNum(mx) {
					mx = f[j]
				}
			case ip == "" && trTimeRe.MatchString(f[j]) && next != "ms":
				ip = f[j]
			}
		}
		loss := 100
		if c+stars > 0 {
			loss = stars * 100 / (c + stars)
		}
		h := map[string]any{"n": atoiDef(f[0], 0), "ip": ip, "loss": loss, "sent": c + stars, "avg": nil, "best": nil, "worst": nil, "jit": nil}
		if c > 0 {
			h["avg"], h["best"], h["worst"] = json.Number(fmt.Sprintf("%.2f", sum/float64(c))), rawNum(mn), rawNum(mx)
		}
		hops = append(hops, h)
	}
	return hops
}

// routeField: het woord na "dev", "src" of "via" in de uitvoer van "ip route get"
func routeField(route, key string) string {
	f := strings.Fields(route)
	for i := 0; i+1 < len(f); i++ {
		if f[i] == key {
			return f[i+1]
		}
	}
	return ""
}

// parseLldp: uitvoer van "lldpctl -f keyvalue <kaart>" → de switch en de poort, of nil
func parseLldp(dev, out string) map[string]any {
	name, port := "", ""
	for _, l := range strings.Split(out, "\n") {
		f := strings.SplitN(l, "=", 3) // awk -F= neemt alleen het stuk tot het volgende "="
		if len(f) < 2 {
			continue
		}
		switch f[0] {
		case "lldp." + dev + ".chassis.name":
			name = f[1]
		case "lldp." + dev + ".port.descr":
			port = f[1]
		case "lldp." + dev + ".port.ifname":
			if port == "" {
				port = f[1]
			}
		}
	}
	if name == "" && port == "" {
		return nil
	}
	return map[string]any{"sys": cutRunes(name, 80), "port": cutRunes(port, 80)}
}

// measurePath: één pad-check meten
func measurePath(dst, via string, root bool, virt string) map[string]any {
	routeOut, _ := run(5*time.Second, "ip", "route", "get", dst)
	route := strings.SplitN(routeOut, "\n", 2)[0]
	dev := routeField(route, "dev")
	var speed any
	if devNameRe.MatchString(dev) {
		if s := strings.TrimSpace(readText("/sys/class/net/" + dev + "/speed")); reDigits.MatchString(s) {
			speed = json.Number(s)
		}
	}
	tool := ""
	hops := []map[string]any{}
	if haveCmd("mtr") {
		tool = "mtr"
		args := []string{"-r", "-w", "-n", "-c", "5"}
		if root {
			args = append(args, "-i", "0.2")
		}
		out, _ := run(30*time.Second, "mtr", append(args, dst)...)
		hops = parseMtr(out)
	} else if haveCmd("traceroute") {
		tool = "traceroute"
		out, _ := run(40*time.Second, "traceroute", "-n", "-q", "3", "-w", "1", "-m", "20", dst)
		hops = parseTraceroute(out)
	}
	vias := []map[string]any{}
	if via != "-" {
		for _, ip := range strings.Fields(strings.ReplaceAll(via, ",", " ")) {
			if pathHostRe.MatchString(ip) {
				vias = append(vias, pingHost(ip))
			}
		}
	}
	rootN := 0
	if root {
		rootN = 1
	}
	p := map[string]any{"at": time.Now().Unix(), "tool": tool, "dst": dst, "dev": dev, "src": routeField(route, "src"), "gw": routeField(route, "via"),
		"speed": speed, "virt": virt, "root": rootN, "hops": hops, "via": vias, "ping": pingHost(dst)}
	if dev != "" && haveCmd("lldpctl") {
		out, _ := run(5*time.Second, "lldpctl", "-f", "keyvalue", dev)
		if l := parseLldp(dev, out); l != nil {
			p["lldp"] = l
		}
	}
	return p
}

// iperfReceiver: de regel "0 <tab> iperfs <tab> poort <tab> tot": hier kort een iperf3-ontvanger starten (één meting)
func iperfReceiver(tests, dir string) map[string]any {
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 4 || f[1] != "iperfs" || !portNumRe.MatchString(f[2]) || !reDigits.MatchString(f[3]) {
			continue
		}
		until, _ := strconv.ParseInt(f[3], 10, 64)
		if until <= time.Now().Unix() {
			continue
		}
		port := atoiDef(f[2], 0)
		mark := filepath.Join(dir, "iperfs")
		if !haveCmd("iperf3") {
			return map[string]any{"err": "iperf3 ontbreekt"}
		}
		if st, err := os.Stat(mark); err == nil && strings.TrimSpace(readText(mark)) == f[3] {
			return map[string]any{"started": st.ModTime().Unix(), "port": port}
		}
		// Na één meting stopt iperf3 zelf (-1); anders na 2 minuten. Buiten de dienst van de agent, zodat hij blijft draaien.
		if isRoot() && haveCmd("systemd-run") {
			run(10*time.Second, "systemd-run", "--quiet", "--collect", "--unit", "rzsense-iperf3-"+f[3], "timeout", "120", "iperf3", "-s", "-1", "-p", f[2])
		} else {
			cmd := exec.Command("timeout", "120", "iperf3", "-s", "-1", "-p", f[2])
			cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
			if cmd.Start() == nil {
				go func() { _ = cmd.Wait() }()
			}
		}
		_ = os.WriteFile(mark, []byte(f[3]), 0o600)
		return map[string]any{"started": time.Now().Unix(), "port": port}
	}
	return nil
}

// parseIperf: de regel met "receiver" in de uitvoer van iperf3 -f m → Mbit/s, of ""
func parseIperf(out string) string {
	v := ""
	for _, l := range strings.Split(out, "\n") {
		if !strings.Contains(l, "receiver") {
			continue
		}
		f := strings.Fields(l)
		for i := 1; i < len(f); i++ {
			if f[i] == "Mbits/sec" {
				v = f[i-1]
			}
		}
	}
	return v
}

// throughputTests: de regels "id <tab> pathtp <tab> ip <tab> poort <tab> sleutel": nu meten, één keer per sleutel
func throughputTests(tests, dir string) map[string]map[string]any {
	res := map[string]map[string]any{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 5 || f[1] != "pathtp" || !reDigits.MatchString(f[0]) || !pathHostRe.MatchString(f[2]) || !portNumRe.MatchString(f[3]) || !tpKeyRe.MatchString(f[4]) {
			continue
		}
		mark := filepath.Join(dir, f[0]+".tpkey")
		if strings.TrimSpace(readText(mark)) == f[4] {
			continue
		}
		_ = os.WriteFile(mark, []byte(f[4]), 0o600)
		if !haveCmd("iperf3") {
			res[f[0]] = map[string]any{"to": f[2], "err": "iperf3 ontbreekt op deze server", "key": f[4]}
			continue
		}
		out, _ := run(15*time.Second, "iperf3", "-c", f[2], "-p", f[3], "-t", "3", "-f", "m", "--connect-timeout", "3000")
		if mbit := parseIperf(out); rawNum(mbit) != nil {
			res[f[0]] = map[string]any{"to": f[2], "mbit": rawNum(mbit), "key": f[4]}
		} else {
			errLine := ""
			for _, el := range strings.Split(out, "\n") {
				if strings.Contains(strings.ToLower(el), "error") {
					errLine = el
					break
				}
			}
			if len(errLine) > 160 {
				errLine = errLine[:160]
			}
			res[f[0]] = map[string]any{"to": f[2], "err": cleanText(errLine), "key": f[4]}
		}
	}
	return res
}

// collectPathLinux: de onderdelen "path" en "iperfs" van het rapport. In de proef (active = false) alleen de metingen
// van het pad zelf; iperf3 start hij dan niet.
func collectPathLinux(active bool) (paths map[string]any, iperfs map[string]any) {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !strings.Contains(tests, "\tpath\t") && !strings.Contains(tests, "\tpathtp\t") && !strings.Contains(tests, "\tiperfs\t") {
		return nil, nil
	}
	dir := filepath.Join(dataDir(), "path")
	_ = os.MkdirAll(dir, 0o700)
	tp := map[string]map[string]any{}
	if active {
		iperfs = iperfReceiver(tests, dir)
		tp = throughputTests(tests, dir)
	}
	virt, _ := run(5*time.Second, "systemd-detect-virt")
	virt = strings.TrimSpace(virt)
	if virt == "none" {
		virt = ""
	}
	paths = map[string]any{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 4 || f[1] != "path" || !reDigits.MatchString(f[0]) || !pathHostRe.MatchString(f[2]) {
			continue
		}
		iv := 300
		if len(f) >= 5 && reDigits.MatchString(f[4]) {
			iv = atoiDef(f[4], 300)
		}
		file := filepath.Join(dir, f[0]+".json")
		st, err := os.Stat(file)
		fresh := false
		if err != nil || st.Size() == 0 || time.Since(st.ModTime()) >= time.Duration(iv-5)*time.Second {
			b, _ := json.Marshal(measurePath(f[2], f[3], isRoot(), virt))
			tmp := file + ".tmp"
			if os.WriteFile(tmp, b, 0o600) == nil && os.Rename(tmp, file) == nil {
				fresh = true
			}
		}
		// Meegaan: een nieuwe meting, of een uitkomst van de doorvoertest
		if !fresh && tp[f[0]] == nil {
			continue
		}
		body := map[string]any{}
		if b, err := os.ReadFile(file); err != nil || json.Unmarshal(b, &body) != nil || len(body) == 0 {
			body = map[string]any{"none": 1}
		}
		if tp[f[0]] != nil {
			body["tp"] = tp[f[0]]
		}
		if !fresh {
			body["old"] = 1
		}
		paths[f[0]] = body
	}
	if len(paths) == 0 {
		paths = nil
	}
	return paths, iperfs
}
