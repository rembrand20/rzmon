//go:build linux

package main

import (
	"encoding/json"
	"testing"
)

func TestParseTraceroute(t *testing.T) {
	out := "traceroute to 203.0.113.9 (203.0.113.9), 20 hops max, 60 byte packets\n 1  192.168.2.1  0.512 ms  0.433 ms  0.401 ms\n 2  * * *\n 3  203.0.113.1  11.2 ms *  9.8 ms\n"
	b, _ := json.Marshal(parseTraceroute(out))
	want := `[{"avg":0.45,"best":0.401,"ip":"192.168.2.1","jit":null,"loss":0,"n":1,"sent":3,"worst":0.512},{"avg":null,"best":null,"ip":"","jit":null,"loss":100,"n":2,"sent":3,"worst":null},{"avg":10.50,"best":9.8,"ip":"203.0.113.1","jit":null,"loss":33,"n":3,"sent":3,"worst":11.2}]`
	if string(b) != want {
		t.Fatalf("traceroute:\n%s\nverwacht:\n%s", b, want)
	}
}

func TestParseIperfAndRoute(t *testing.T) {
	out := "[  5]   0.00-3.00   sec   334 MBytes   934 Mbits/sec    0             sender\n[  5]   0.00-3.00   sec   332 MBytes   928 Mbits/sec                  receiver\n"
	if got := parseIperf(out); got != "928" {
		t.Fatalf("iperf3: %q", got)
	}
	if parseIperf("iperf3: error - unable to connect to server") != "" {
		t.Fatal("zonder meting hoort er geen snelheid te zijn")
	}
	r := "192.0.2.9 via 192.168.2.1 dev eth0 src 192.168.2.20 uid 0"
	if routeField(r, "dev") != "eth0" || routeField(r, "src") != "192.168.2.20" || routeField(r, "via") != "192.168.2.1" || routeField("local 127.0.0.1 dev lo", "via") != "" {
		t.Fatal("route: velden niet goed gelezen")
	}
}

func TestSmartScanAndNumbers(t *testing.T) {
	got := smartScan("/dev/sda -d sat # ATA\n/dev/nvme0 -d nvme # NVMe\n/dev/sdx # zonder soort\n# opmerking\n")
	if len(got) != 3 || got[0] != [2]string{"/dev/sda", "sat"} || got[2] != [2]string{"/dev/sdx", "auto"} {
		t.Fatalf("scan: %v", got)
	}
	for in, want := range map[string]float64{"35 Celsius": 35, "097": 97, "1.5x": 1.5, "abc": 0, "": 0, "-3": -3} {
		if awkNum(in) != want {
			t.Fatalf("getal %q: %v", in, awkNum(in))
		}
	}
	d := parseSmart("/dev/sdz", "", 1)
	if d["error"] != "niet uit te lezen" {
		t.Fatalf("een schijf zonder uitvoer: %v", d)
	}
}
