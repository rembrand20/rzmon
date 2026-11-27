//go:build linux

package main

import (
	"encoding/json"
	"testing"
)

func TestParseWifi(t *testing.T) {
	out := "Connected to aa:bb:cc:dd:ee:ff (on wlan0)\n\tSSID: Thuis \"5G\"\n\tfreq: 2437\n\tsignal: -61 dBm\n\ttx bitrate: 144.4 MBit/s MCS 15 short GI\n"
	b, _ := json.Marshal(parseWifi("wlan0", out))
	if string(b) != `{"band":"2.4","bssid":"aa:bb:cc:dd:ee:ff","chan":6,"if":"wlan0","rx":null,"sig":-61,"ssid":"Thuis \"5G\"","std":"n","tx":144.4,"width":null}` {
		t.Fatalf("wifi: %s", b)
	}
	if parseWifi("wlan0", "Not connected.\n") != nil {
		t.Fatal("een kaart die niet verbonden is hoort niets op te leveren")
	}
	if w := parseWifi("wlan0", "Connected to 11:22:33:44:55:66 (on wlan0)\n\tfreq: 6115\n\trx bitrate: 2882.3 MBit/s 160MHz EHT-MCS 13 EHT-NSS 2\n"); w["band"] != "6" || w["chan"] != 33.0 || w["std"] != "be" || w["width"] != 160.0 {
		t.Fatalf("6 GHz: %v", w)
	}
}

func TestOpenPortsAndRules(t *testing.T) {
	dial := func(addr string) bool { return addr == "203.0.113.1:443" || addr == "203.0.113.1:22" }
	b, _ := json.Marshal(openPorts("203.0.113.1", []string{"443", "80", "22", "0", "70000", "x"}, dial))
	if string(b) != "[22,443]" {
		t.Fatalf("open poorten: %s", b)
	}
	for _, ip := range []string{"10.1.2.3", "127.0.0.1", "192.168.1.1", "172.16.0.1", "172.31.255.1", "169.254.1.1", "::1", "fd00::1", "fe80::1", "0.0.0.0"} {
		if !xpPrivRe.MatchString(ip) {
			t.Errorf("%s is geen openbaar adres en hoort geweigerd te worden", ip)
		}
	}
	for _, ip := range []string{"203.0.113.1", "172.32.0.1", "8.8.8.8", "2001:db8::1"} {
		if xpPrivRe.MatchString(ip) {
			t.Errorf("%s is een openbaar adres", ip)
		}
	}
	for _, p := range []string{"/etc/ssl/certs", "/opt/app/node_modules", "/srv/x/tests", "/etc/x/CA-Bundle.crt", "/opt/py/site-packages", "/x/cacert.pem"} {
		if !certSkip(p) {
			t.Errorf("%s hoort overgeslagen te worden bij certificaten zoeken", p)
		}
	}
	if certSkip("/etc/letsencrypt/live") || certSkip("/etc/nginx/ssl") {
		t.Error("gewone certificaatmappen horen doorzocht te worden")
	}
}
