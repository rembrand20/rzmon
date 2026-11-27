//go:build linux

package main

// Schijfgezondheid (SMART) en UPS (0.17.0), zoals de bash-agent (agent/src/85-meesturen.sh):
//   - SMART: per schijf het model, of de zelftest goed is, de temperatuur, slijtage en slechte sectoren (smartctl), elk
//     half uur. Een schijf in slaapstand wordt niet wakker gemaakt.
//   - UPS via NUT (upsc): status, lading, resterende tijd, belasting en model.

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var (
	leadNum   = regexp.MustCompile(`^[ \t]*[-+]?([0-9]+\.?[0-9]*|\.[0-9]+)([eE][-+]?[0-9]+)?`)
	smartHead = regexp.MustCompile(`^(Device Model|Model Number|Product):`)
	standbyRe = regexp.MustCompile(`(?i)STANDBY|SLEEP`)
)

// awkNum: een tekst als getal zoals awk dat doet ("35 Celsius" → 35, "abc" → 0)
func awkNum(s string) float64 {
	m := leadNum.FindString(s)
	v, _ := strconv.ParseFloat(strings.TrimSpace(m), 64)
	return v
}

// smartScan: uitvoer van "smartctl --scan" → [apparaat, soort] (hooguit 24)
func smartScan(out string) [][2]string {
	var res [][2]string
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) == 0 || !strings.HasPrefix(f[0], "/dev/") || len(res) >= 24 {
			continue
		}
		typ := "auto"
		if len(f) >= 3 && f[1] == "-d" {
			typ = f[2]
		}
		res = append(res, [2]string{f[0], typ})
	}
	return res
}

// parseSmart: uitvoer van "smartctl -n standby -H -i -A" voor één schijf
func parseSmart(dev, out string, rc int) map[string]any {
	if standbyRe.MatchString(out) && rc&2 != 0 {
		return map[string]any{"dev": dev, "standby": true}
	}
	var model string
	var passed, temp, wear, realloc, pending, media any
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if smartHead.MatchString(l) {
			_, v, _ := strings.Cut(l, ":")
			model = strings.TrimLeft(v, " ")
		}
		if strings.Contains(l, "overall-health self-assessment test result:") || strings.Contains(l, "SMART Health Status:") {
			passed = strings.Contains(l, "PASSED") || strings.Contains(l, ": OK")
		}
		if strings.HasPrefix(l, "Temperature:") && len(f) >= 2 {
			temp = awkNum(f[1])
		}
		if strings.HasPrefix(l, "Percentage Used:") && len(f) >= 3 {
			wear = awkNum(strings.ReplaceAll(f[2], "%", ""))
		}
		if strings.HasPrefix(l, "Media and Data Integrity Errors:") && len(f) > 0 {
			media = awkNum(strings.ReplaceAll(f[len(f)-1], ",", ""))
		}
		if len(f) < 2 {
			continue
		}
		last := f[len(f)-1]
		switch f[1] {
		case "Reallocated_Sector_Ct":
			realloc = awkNum(last)
		case "Current_Pending_Sector":
			pending = awkNum(last)
		case "Temperature_Celsius", "Airflow_Temperature_Cel":
			if temp == nil && len(f) >= 10 {
				temp = awkNum(f[9])
			}
		case "Percent_Lifetime_Remain", "SSD_Life_Left", "Wear_Leveling_Count", "Media_Wearout_Indicator":
			if wear == nil && len(f) >= 4 {
				wear = 100 - awkNum(f[3])
			}
		}
	}
	if passed == nil && model == "" {
		return map[string]any{"dev": dev, "error": "niet uit te lezen"}
	}
	return map[string]any{"dev": dev, "model": model, "passed": passed, "temp": temp, "wear": wear, "realloc": realloc, "pending": pending, "media_err": media}
}

// collectSmartLinux: de lijst met schijven (elk half uur opnieuw gelezen), of nil zonder smartctl of zonder root
func collectSmartLinux() any {
	if !isRoot() || !haveCmd("smartctl") {
		return nil
	}
	cache := filepath.Join(dataDir(), "smart.cache")
	if st, err := os.Stat(cache); err == nil && st.Size() > 0 && time.Since(st.ModTime()) < 30*time.Minute {
		if b, err := os.ReadFile(cache); err == nil && json.Valid(b) {
			return json.RawMessage(b)
		}
	}
	scan, _ := run(20*time.Second, "smartctl", "--scan")
	list := []map[string]any{}
	for _, d := range smartScan(scan) {
		out, rc := run(20*time.Second, "smartctl", "-n", "standby", "-H", "-i", "-A", "-d", d[1], d[0])
		list = append(list, parseSmart(d[0], out, rc))
	}
	b, _ := json.Marshal(list)
	_ = os.WriteFile(cache, b, 0o600)
	return json.RawMessage(b)
}

// parseUps: uitvoer van "upsc naam@localhost"
func parseUps(name, out string) map[string]any {
	var st, model string
	var charge, runtime, load any
	for _, l := range strings.Split(out, "\n") {
		k, v, ok := strings.Cut(l, ": ")
		if !ok {
			continue
		}
		switch k {
		case "ups.status":
			st = v
		case "battery.charge":
			charge = awkNum(v)
		case "battery.runtime":
			runtime = awkNum(v)
		case "ups.load":
			load = awkNum(v)
		case "device.model", "ups.model":
			model = strings.ReplaceAll(v, `"`, "")
		}
	}
	return map[string]any{"name": name, "status": st, "charge": charge, "runtime": runtime, "load": load, "model": model}
}

// collectUpsLinux: de UPS'en die NUT kent (hooguit 8), of nil
func collectUpsLinux() any {
	if !haveCmd("upsc") {
		return nil
	}
	names, _ := run(5*time.Second, "upsc", "-l")
	var list []map[string]any
	for _, n := range strings.Fields(names) {
		if len(list) >= 8 {
			break
		}
		if out, code := run(5*time.Second, "upsc", n+"@localhost"); code == 0 {
			list = append(list, parseUps(n, out))
		}
	}
	if len(list) == 0 {
		return nil
	}
	return list
}
