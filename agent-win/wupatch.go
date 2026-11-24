package main

import (
	"encoding/json"
	"regexp"
)

// Fase 4: Windows Update in het formaat van "Updates" in RzSense (patchbeheer, net als apt/dnf op Linux):
// [naam (KB-nummer), titel, beveiliging, soort]. Plus de CVE's per update, voor Kwetsbaarheden.

var jobIDRe = regexp.MustCompile(`^[0-9a-f]{16}$`)
var kbRe = regexp.MustCompile(`^(KB[0-9]{4,10}|upd-[0-9a-fA-F-]{8})$`)
var cveRe = regexp.MustCompile(`^CVE-[0-9]{4}-[0-9]{4,7}$`)

// splitWU: het rapport voor de Agent Manager (zonder de volledige lijst) en het rapport voor Updates (nil zonder uitkomst)
func splitWU(wu map[string]any) (map[string]any, map[string]any) {
	if wu == nil {
		return nil, nil
	}
	short := map[string]any{}
	for k, v := range wu {
		if k != "all" && k != "rb" {
			short[k] = v
		}
	}
	if _, ok := wu["error"]; ok {
		return short, nil
	}
	all, _ := wu["all"].([]any)
	if all == nil {
		if one, ok := wu["all"].(map[string]any); ok { // ConvertTo-Json maakt van één element geen lijst
			all = []any{one}
		}
	}
	list := [][]any{}
	sec := 0
	for _, x := range all {
		u, _ := x.(map[string]any)
		kb, _ := u["kb"].(string)
		if !kbRe.MatchString(kb) {
			continue
		}
		t, _ := u["t"].(string)
		if len(t) > 60 {
			t = t[:60]
		}
		s, _ := u["sec"].(bool)
		cat, _ := u["cat"].(string)
		if s {
			sec++
		}
		list = append(list, []any{kb, t, s, cat})
	}
	rb, _ := wu["rb"].(bool)
	return short, map[string]any{"mgr": "wu", "avail": len(list), "sec": sec, "rb": rb, "list": list}
}

// wuCVEs: per update de CVE's die hij oplost (alleen beveiligingsupdates), voor Kwetsbaarheden in RzSense
func wuCVEs(wu map[string]any) [][]any {
	out := [][]any{}
	all, _ := wu["all"].([]any)
	for _, x := range all {
		u, _ := x.(map[string]any)
		kb, _ := u["kb"].(string)
		if !kbRe.MatchString(kb) {
			continue
		}
		var cves []string
		raw, _ := json.Marshal(u["cve"])
		var list []string
		if json.Unmarshal(raw, &list) != nil {
			var one string
			if json.Unmarshal(raw, &one) == nil {
				list = []string{one}
			}
		}
		for _, c := range list {
			if cveRe.MatchString(c) && len(cves) < 100 {
				cves = append(cves, c)
			}
		}
		if len(cves) > 0 {
			sev, _ := u["s"].(string)
			t, _ := u["t"].(string)
			if len(t) > 120 {
				t = t[:120]
			}
			out = append(out, []any{kb, t, sev, cves})
		}
	}
	return out
}
