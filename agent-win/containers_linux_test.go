//go:build linux

package main

import (
	"encoding/json"
	"testing"
)

func TestUnitBytes(t *testing.T) {
	for in, want := range map[string]float64{"11.8kB (virtual 8.12MB)": 11800, "2.5MB": 2.5e6, "0B": 0, "7": 7, "": 0, "raar": 0, "1.5GiB": 1.5} {
		if got := unitBytes(in, false); got != want {
			t.Errorf("podman %q: %v", in, got)
		}
	}
	if unitBytes("12.5MiB", true) != 12.5*1048576 || unitBytes("1.5GiB", true) != 1.5*1073741824 || unitBytes("3.4MB", true) != 3.4e6 {
		t.Error("docker: MiB/GiB en MB horen allebei te kloppen")
	}
}

func TestPodmanJSON(t *testing.T) {
	a := []string{"S|root|aaa|1000|2000|10|20|1kB / 2kB|1|2|3"}
	b := []string{"S|root|aaa|1500|4000|10|20|1kB / 2kB|1|2|3"}
	raw := []string{"V|root|5.2.2", "E|piet|geen toegang", "C|root|aaa|web|nginx|running|Up (healthy)|0|2|pod1|false|1795000000|1kB", "C|root|bbb|infra|pause|running|Up|0|0|pod1|true|1|0B",
		"P|root|pod1|Running|2", "D|root|Images|100|40|3|1", "M|root|aaa|/v1;/v2;"}
	p := podmanJSON(a, b, raw, map[string]float64{"/v1": 100, "/v2": 50})
	out, _ := json.Marshal(p)
	cs := p["containers"].([]map[string]any)
	if len(cs) != 1 || cs[0]["name"] != "web" || cs[0]["health"] != "healthy" || string(cs[0]["cpu"].(json.Number)) != "25.00" || string(cs[0]["volumes"].(json.Number)) != "150" {
		t.Fatalf("containers (de infra-container hoort er niet bij): %s", out)
	}
	if p["version"] != "5.2.2" || p["error"] != "piet: geen toegang" || p["images"] != 3 || string(p["images_size"].(json.Number)) != "100" || string(p["reclaimable"].(json.Number)) != "40" {
		t.Fatalf("samenvatting: %s", out)
	}
}

func TestHeartbeatLine(t *testing.T) {
	ok := []string{`{"n":"backup-db","s":"ok","t":1795000000,"v":3.5,"m":"klaar"}`, `{"n":"x","s":"fail","t":1,"m":"fout: \"vol\" \\ einde"}`}
	bad := []string{`{"n":"x","s":"ok","t":1,"m":"","extra":1}`, `{"n":"../x","s":"ok","t":1,"m":""}`, `{"n":"x","s":"klaar","t":1,"m":""}`, `{"n":"x","s":"ok","t":1,"m":"a"b"}`, ``}
	for _, l := range ok {
		if !hbLineRe.MatchString(l) {
			t.Errorf("hoort geldig te zijn: %s", l)
		}
	}
	for _, l := range bad {
		if hbLineRe.MatchString(l) {
			t.Errorf("hoort ongeldig te zijn: %s", l)
		}
	}
}
