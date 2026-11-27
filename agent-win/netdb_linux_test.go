//go:build linux

package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestDbToJSON(t *testing.T) {
	raw := "Uptime\t100\nOnbekend\t1\nThreads_connected\t5\nlongq\ta\nlongq\tb\nlongq\tc\nlongq\td\nlongq\te\nlongq\tf\nsize:shop\t10\n   Slave_IO_Running: Yes\n   Niet_Bekend: x\n"
	m := dbToJSON("mysql", 12, 0, raw)
	kv := m["kv"].(map[string]string)
	if m["ok"] != 1 || kv["Uptime"] != "100" || kv["Threads_connected"] != "5" || kv["longq:5"] != "e" || kv["size:shop"] != "10" || kv["repl:Slave_IO_Running"] != "Yes" {
		t.Fatalf("bekende sleutels: %v", m)
	}
	for _, k := range []string{"Onbekend", "longq:6", "repl:Niet_Bekend"} {
		if _, ok := kv[k]; ok {
			t.Errorf("%s hoort niet mee te gaan", k)
		}
	}
	e := dbToJSON("pgsql", 5, 2, "psql: error: connection to server failed\nFATAL: nog iets\n")
	if e["ok"] != 0 || e["err"] != "psql: error: connection to server failed" {
		t.Fatalf("fout: %v", e)
	}
	if e := dbToJSON("mysql", 5, 124, ""); e["err"] != "geen antwoord (exit 124)" {
		t.Fatalf("geen antwoord: %v", e)
	}
	if got := dbEsc("a\x01b\tc"); got != "a b c" {
		t.Fatalf("stuurtekens horen een spatie te worden: %q", got)
	}
}

func TestParseWgAndNic(t *testing.T) {
	out := "wg0\tPRIV=\tPUB=\t51820\toff\nwg0\tAb+Cd/Ef==\t(none)\t203.0.113.7:51820\t10.0.0.2/32\t1000\t11\t22\t25\nwg0\tZz99\t(none)\t(none)\t10.0.0.3/32\t0\tx\t0\toff\nraar naam\tk\t-\t-\t-\t1\t1\t1\t1\n"
	b, _ := json.Marshal(parseWg(out, 1090))
	want := `[{"endpoint":"203.0.113.7:51820","hs":90,"id":"wg0:AbCdEf","iface":"wg0","peer":"AbCdEf","rx":11,"tx":22},{"endpoint":"","hs":-1,"id":"wg0:Zz99","iface":"wg0","peer":"Zz99","rx":0,"tx":0}]`
	if string(b) != want {
		t.Fatalf("wireguard:\n%s\nverwacht:\n%s", b, want)
	}
	d := t.TempDir()
	mk := func(name string, device, master bool, rx, tx string) {
		p := filepath.Join(d, name, "statistics")
		_ = os.MkdirAll(p, 0o700)
		_ = os.WriteFile(filepath.Join(p, "rx_bytes"), []byte(rx+"\n"), 0o600)
		_ = os.WriteFile(filepath.Join(p, "tx_bytes"), []byte(tx+"\n"), 0o600)
		if device {
			_ = os.MkdirAll(filepath.Join(d, name, "device"), 0o700)
		}
		if master {
			_ = os.MkdirAll(filepath.Join(d, name, "master"), 0o700)
		}
	}
	mk("lo", false, false, "999", "999")
	mk("eth0", true, false, "100", "10")
	mk("eth1", true, true, "500", "50") // poort van een bond: telt via de bond
	mk("bond0", false, false, "600", "60")
	mk("veth123", false, false, "7", "7") // virtueel: telt niet
	mk("wlan0", false, false, "5", "5")
	b, _ = json.Marshal(collectNicLinux(d))
	if string(b) != `{"ifs":["bond0","eth0","wlan0"],"rx":705,"tx":75}` {
		t.Fatalf("netwerkverkeer: %s", b)
	}
	if collectNicLinux(filepath.Join(d, "leeg")) != nil {
		t.Fatal("zonder netwerkkaarten hoort er niets mee te gaan")
	}
}
