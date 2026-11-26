package main

// Wifi (Windows-agent 0.7.0): de eigen wifi-verbinding uit "netsh wlan show interfaces", voor Netwerk → Netwerksnelheid
// in RzSense: SSID, access point (BSSID), band, kanaal, Wi-Fi-standaard, signaal en verbindingssnelheid. netsh praat de
// taal van Windows; daarom herkent dit de waarden aan hun vorm en een paar woorden in het Engels, Nederlands en Duits.

import (
	"regexp"
	"strconv"
	"strings"
)

var (
	netshLine = regexp.MustCompile(`^\s*([^:]+?)\s+:\s?(.*)$`)
	netshMac  = regexp.MustCompile(`^([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2}$`)
	netshNum  = regexp.MustCompile(`^\d+(?:[.,]\d+)?`)
)

// parseNetshWlan leest de uitvoer van netsh en geeft de eerste verbonden wifi-kaart, of nil.
func parseNetshWlan(out string) map[string]any {
	var cur map[string]any
	rateKeys := 0
	done := func(m map[string]any) bool { return m != nil && m["ssid"] != nil && m["bssid"] != nil }
	for _, line := range strings.Split(strings.ReplaceAll(out, "\r", ""), "\n") {
		m := netshLine.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		k, v := strings.TrimSpace(m[1]), strings.TrimSpace(m[2])
		kl := strings.ToLower(k)
		switch {
		case kl == "name" || kl == "naam":
			if done(cur) {
				return cur // de eerste verbonden kaart
			}
			cur = map[string]any{"if": clip(v, 40)}
			rateKeys = 0
		case cur == nil:
			continue
		case kl == "ssid":
			cur["ssid"] = clip(v, 64)
		case strings.Contains(kl, "bssid") && netshMac.MatchString(v):
			cur["bssid"] = strings.ToLower(strings.ReplaceAll(v, "-", ":"))
		case kl == "band":
			b := strings.ReplaceAll(strings.Fields(v + " ")[0], ",", ".")
			if b == "2.4" || b == "5" || b == "6" {
				cur["band"] = b
			}
		case kl == "channel" || kl == "kanaal" || kl == "kanal" || kl == "canal":
			if n, err := strconv.Atoi(v); err == nil && n > 0 && n < 234 {
				cur["chan"] = n
			}
		case strings.HasPrefix(v, "802.11"):
			cur["std"] = clip(strings.TrimPrefix(v, "802.11"), 6)
		case strings.Contains(kl, "(mbps)") || strings.Contains(kl, "(mbit/s)"):
			if f, ok := netshFloat(v); ok {
				rx := strings.Contains(kl, "receiv") || strings.Contains(kl, "ontvang") || strings.Contains(kl, "empfang") || strings.Contains(kl, "récep")
				if !rx && !strings.Contains(kl, "transm") && !strings.Contains(kl, "verzend") && !strings.Contains(kl, "übertrag") && !strings.Contains(kl, "bertrag") {
					rx = rateKeys == 0 // onbekende taal: netsh noemt ontvangen eerst
				}
				rateKeys++
				if rx {
					cur["rx"] = f
				} else {
					cur["tx"] = f
				}
			}
		case strings.HasSuffix(v, "%") && (strings.Contains(kl, "signal") || strings.Contains(kl, "signaal") || cur["sig"] == nil):
			if p, err := strconv.Atoi(strings.TrimSpace(strings.TrimSuffix(v, "%"))); err == nil && p >= 0 && p <= 100 {
				cur["sig"] = p/2 - 100 // Windows geeft procenten; ongeveer in dBm
			}
		}
	}
	if done(cur) {
		return cur
	}
	return nil
}

func netshFloat(v string) (float64, bool) {
	s := netshNum.FindString(v)
	if s == "" {
		return 0, false
	}
	f, err := strconv.ParseFloat(strings.ReplaceAll(s, ",", "."), 64)
	return f, err == nil && f > 0
}

func clip(s string, n int) string {
	r := []rune(s)
	if len(r) > n {
		return string(r[:n])
	}
	return s
}
