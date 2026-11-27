//go:build linux

package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestFnmatchAll(t *testing.T) {
	for _, c := range []struct {
		p, s string
		want bool
	}{
		{"*.log", "a.log", true}, {"*.log", "a.log.1", false}, {"/srv/*.pem", "/srv/sub/a.pem", true}, {"*/cache/*", "/etc/app/cache/x", true},
		{"/etc/*app.conf", "/etc/a/b/app.conf", true}, {"a?c", "abc", true}, {"a?c", "ac", false}, {"[a-c]x", "bx", true}, {"[!a-c]x", "bx", false},
		{"[!a-c]x", "dx", true}, {"a[", "a[", true}, {`a\*b`, "a*b", true}, {`a\*b`, "axb", false}, {"", "", true}, {"*", "", true},
	} {
		if got := fnmatchAll(c.p, c.s); got != c.want {
			t.Errorf("fnmatchAll(%q, %q) = %v, wil %v", c.p, c.s, got, c.want)
		}
	}
}

func TestIdsPrivatePeer(t *testing.T) {
	for ip, want := range map[string]bool{"10.1.2.3": true, "192.168.0.1": true, "172.16.0.1": true, "172.31.9.9": true, "172.32.0.1": false, "172.15.0.1": false,
		"203.0.113.1": false, "fd00::1": true, "fe80::1": true, "2001:db8::1": false, "::ffff:10.0.0.1": true, "169.254.1.1": true} {
		if got := idsPrivatePeer(ip); got != want {
			t.Errorf("idsPrivatePeer(%s) = %v, wil %v", ip, got, want)
		}
	}
}

func TestFimHashReuseAndBig(t *testing.T) {
	dir := t.TempDir()
	f := filepath.Join(dir, "a")
	if err := os.WriteFile(f, []byte("een\n"), 0o640); err != nil {
		t.Fatal(err)
	}
	c := &fimConf{roots: []fimRoot{{2, dir}}}
	l := fimHash(fimList(c), nil)
	if len(l) != 1 || len(l[0].hash) != 64 || l[0].mode != "640" || l[0].sev != 2 {
		t.Fatalf("onverwachte lijst: %+v", l)
	}
	// dezelfde grootte en tijd: de bewaarde hash blijft, ook als die niet (meer) klopt
	old := map[string][3]string{f: {"4", l[0].mtime, "bewaard"}}
	if l2 := fimHash(fimList(c), old); l2[0].hash != "bewaard" {
		t.Errorf("de hash hoort uit de vorige stand te komen, kreeg %s", l2[0].hash)
	}
	big := []fimEntry{{path: f, size: fimBig + 1, mtime: "1700000000.1234567890"}}
	if l3 := fimHash(big, nil); l3[0].hash != "m1700000000" {
		t.Errorf("een groot bestand krijgt de tijd in plaats van een hash, kreeg %s", l3[0].hash)
	}
	// een bestand dat niet te lezen is, valt weg
	if l4 := fimHash([]fimEntry{{path: filepath.Join(dir, "bestaat-niet"), size: 1, mtime: "1.0"}}, nil); len(l4) != 0 {
		t.Errorf("een onleesbaar bestand hoort weg te vallen: %+v", l4)
	}
}

func TestFimParse(t *testing.T) {
	// e 5 / p 2 /etc / x *.log
	c := fimParse("0\tids\tlearn\n0\tfim\tZQk1CnAJMgkvZXRjCngJKi5sb2cK\n")
	if c == nil || c.epoch != "5" || len(c.roots) != 1 || c.roots[0].root != "/etc" || c.roots[0].sev != 2 || !c.excluded("/etc/x.log") || c.excluded("/etc/x.conf") {
		t.Fatalf("onverwacht: %+v", c)
	}
	if fimParse("0\tids\tlearn\n") != nil || fimParse("0\tfim\tgeen base64!\n") != nil {
		t.Error("zonder (geldige) regel hoort er niets te bewaken te zijn")
	}
}
