//go:build linux

package main

import (
	"strings"
	"testing"
)

func testFwEnv(conf map[string]string) fwEnv {
	return fwEnv{conf: conf, url: "https://rz.test:8443/x", haveNft: true, now: 1000,
		tableLive: func() bool { return true }, portBusy: func(p string) bool { return p == "2222" },
		addrs: func(h string) []string { return []string{"192.0.2.10"} }}
}

func TestTabReadAndURLHost(t *testing.T) {
	if f := tabRead("0\tblock\t\t1.2.3.4\t5\t6", 4); f[2] != "1.2.3.4" || f[3] != "5\t6" {
		t.Errorf("tabRead: %q", f)
	}
	if f := tabRead("0\tkill", 4); f[0] != "0" || f[1] != "kill" || f[2] != "" || f[3] != "" {
		t.Errorf("tabRead kort: %q", f)
	}
	for in, want := range map[string]string{"https://rz.test:8443/a/b": "rz.test", "http://10.0.0.1": "10.0.0.1", "https://[2001:db8::1]:443/": "2001:db8::1", "rz.test": "rz.test"} {
		if got := urlHost(in); got != want {
			t.Errorf("urlHost(%s) = %s, wil %s", in, got, want)
		}
	}
}

func TestFwPlanNeverBlocksRzSense(t *testing.T) {
	tests := "0\ttick\t30\n0\tids\tblock\n0\tblock\t192.0.2.10\t0\n0\tblock\trz.test\t0\n0\tblock\t198.51.100.7\t0\n0\tblock\t198.51.100.7\t0\n0\tblock\t198.51.100.8\t999\n0\tblock\t198.51.100.9\t2000\n"
	p := fwPlanFrom(tests, testFwEnv(map[string]string{}))
	if p.want4 != "198.51.100.7, 198.51.100.9, " || !p.build || p.state != "" {
		t.Fatalf("onverwacht plan: %+v", p)
	}
	if s := p.script("counter;"); strings.Contains(s, "192.0.2.10") || !strings.Contains(s, "elements = { 198.51.100.7, 198.51.100.9 };") || strings.Contains(s, "chain output") {
		t.Errorf("onverwacht script:\n%s", s)
	}
}

func TestFwPlanStates(t *testing.T) {
	block := "0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.7\t0\n"
	if p := fwPlanFrom(block, testFwEnv(map[string]string{"IDS_BLOCK": "0"})); p.state != "disabled" || p.build {
		t.Errorf("IDS_BLOCK=0: %+v", p)
	}
	e := testFwEnv(nil)
	e.truenas = true
	if p := fwPlanFrom(block, e); p.state != "unsupported" || p.build {
		t.Errorf("TrueNAS: %+v", p)
	}
	if p := fwPlanFrom(block+"0\ttruenas_fw\t1\n", e); p.state != "" || !p.build {
		t.Errorf("TrueNAS met toestemming: %+v", p)
	}
	e = testFwEnv(nil)
	e.haveNft = false
	if p := fwPlanFrom(block+"0\tdecoy\t2223\n", e); p.state != "error" || p.build || p.decoy != "" {
		t.Errorf("zonder nft: %+v", p)
	}
	if p := fwPlanFrom("0\ttick\t30\n0\tids\tlearn\n0\tdecoy\t2222\n0\tblock\t198.51.100.7\t0\n", testFwEnv(nil)); p.decoy != "" || p.decoyErr == "" || p.build || !p.remove || p.want4 != "" {
		t.Errorf("leren, lokaas-poort in gebruik: %+v", p)
	}
	if p := fwPlanFrom("0\tids\tlearn\n", testFwEnv(nil)); p.remove || p.build {
		t.Errorf("zonder complete lijst van RzSense hoort de agent niets aan te raken: %+v", p)
	}
	if p := fwPlanFrom("0\ttick\t30\n", testFwEnv(nil)); !p.remove {
		t.Errorf("IDS uit volgens een complete lijst: de tabel hoort weg: %+v", p)
	}
}

func TestIsoPlan(t *testing.T) {
	addrs := func(h string) []string { return []string{"192.0.2.10"} }
	p := isoPlanFrom("0\tisolate\t2000\t192.168.1.0/24 onzin 192.168.1.0/24 fd00::/8\n", []string{"https://rz.test", ""}, "nameserver 9.9.9.9\nsearch x\n", 1000, addrs)
	if !p.active || p.a4 != "192.168.1.0/24, 192.0.2.10, 9.9.9.9, " || p.a6 != "fd00::/8, " {
		t.Errorf("onverwacht: %+v", p)
	}
	if p := isoPlanFrom("0\tisolate\t500\t10.0.0.0/8\n", nil, "", 1000, addrs); p.active || !p.valid {
		t.Errorf("verlopen isoleren hoort niet actief te zijn: %+v", p)
	}
	if p := isoPlanFrom("0\ttick\t30\n", nil, "", 1000, addrs); p.active || p.valid {
		t.Errorf("zonder regel: %+v", p)
	}
}
