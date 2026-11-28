//go:build linux

package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const statA = "cpu  100 0 50 800 20 0 5 5 0 0\ncpu0 50 0 25 400 10 0 2 2 0 0\ncpu1 50 0 25 400 10 0 3 3 0 0\nintr 1\nbtime 1795758947\n"
const statB = "cpu  150 0 70 1560 40 0 5 5 0 0\ncpu0 75 0 35 780 20 0 2 2 0 0\ncpu1 75 0 35 780 20 0 3 3 0 0\nintr 2\nbtime 1795758947\n"

func TestCpuLikeBash(t *testing.T) {
	t1, i1, ok1 := cpuTimes(statA)
	t2, i2, ok2 := cpuTimes(statB)
	if !ok1 || !ok2 || t1 != 980 || i1 != 820 || t2 != 1830 || i2 != 1600 {
		t.Fatalf("tellers: %d %d %d %d", t1, i1, t2, i2)
	}
	// zoals de bash-agent: (1 - idle/total) × 100 over het verschil; idle = idle + iowait
	pct := (1 - float64(i2-i1)/float64(t2-t1)) * 100
	if pct < 8.2 || pct > 8.3 {
		t.Fatalf("cpu-gebruik %.2f, verwacht 8.24", pct)
	}
	if cpuCores(statA) != 2 || bootTime(statA) != 1795758947 {
		t.Fatalf("cores %d, boot %d", cpuCores(statA), bootTime(statA))
	}
	if _, _, ok := cpuTimes("onzin"); ok {
		t.Fatal("onleesbare /proc/stat moet geweigerd worden")
	}
}

func TestMemInfo(t *testing.T) {
	m := memInfo("MemTotal:       16473316 kB\nMemFree:          100 kB\nMemAvailable:   15905140 kB\nSwapTotal:       2097148 kB\nSwapFree:        2097000 kB\n")
	if m["total"] != int64(16473316*1024) || m["avail"] != int64(15905140*1024) || m["swap_total"] != int64(2097148*1024) || m["swap_free"] != int64(2097000*1024) {
		t.Fatalf("geheugen: %v", m)
	}
	if e := memInfo(""); e["total"] != int64(0) || len(e) != 4 {
		t.Fatalf("leeg: %v", e)
	}
}

func TestParseMounts(t *testing.T) {
	ms := parseMounts("/dev/sda1 / ext4 rw 0 0\ntmpfs /run tmpfs rw 0 0\n//nas/Mijn\\040Map /mnt/mijn\\040map cifs rw 0 0\n\n")
	if len(ms) != 3 || ms[0].mount != "/" || ms[1].fs != "tmpfs" || ms[2].mount != "/mnt/mijn map" || ms[2].dev != "//nas/Mijn Map" {
		t.Fatalf("koppelpunten: %+v", ms)
	}
	for _, fs := range []string{"tmpfs", "devtmpfs", "squashfs", "overlay", "efivarfs"} {
		if !skipFS[fs] {
			t.Fatalf("%s moet overgeslagen worden, zoals df -x", fs)
		}
	}
	// de schijf van / bestaat altijd en heeft een grootte; tmpfs en een niet-bestaand pad vallen weg
	d := diskList([]mountEntry{{"x", "/", "ext4"}, {"tmpfs", "/", "tmpfs"}, {"y", "/bestaat/niet", "ext4"}})
	if len(d) != 1 || d[0]["mount"] != "/" || d[0]["total"].(uint64) == 0 {
		t.Fatalf("schijven: %v", d)
	}
}

func TestLoadBashConfigReadOnly(t *testing.T) {
	dir := t.TempDir()
	conf := filepath.Join(dir, "rzmon-agent")
	key := strings.Repeat("ab", 32)
	os.WriteFile(conf, []byte("# uitleg\nMONITOR_URL=\"https://rz.voorbeeld/\"\nMONITOR_URL_FALLBACK='http://10.0.0.5:8080'\nPIN=sha256//OUD=\nSERVICES=\"a b\"\n$(rm -rf /)\n"), 0o600)
	os.WriteFile(filepath.Join(dir, "agent.key"), []byte(key+"\n"), 0o600)
	os.WriteFile(filepath.Join(dir, "url.pins"), []byte("https://rz.voorbeeld sha256//NIEUW=\nhttp://10.0.0.5:8080 system\n"), 0o600)
	t.Setenv("RZSENSE_BASH_CONF", conf)
	t.Setenv("RZSENSE_BASH_KEYS", dir)
	before, _ := os.ReadFile(conf)
	c, err := loadBashConfig()
	if err != nil {
		t.Fatal(err)
	}
	if c.URL != "https://rz.voorbeeld" || c.URLFallback != "http://10.0.0.5:8080" || c.Key != key || c.Pin != "sha256//NIEUW=" || c.PinFallback != "" || c.RemoteUpdate {
		t.Fatalf("instellingen: %+v", c)
	}
	after, _ := os.ReadFile(conf)
	if string(before) != string(after) {
		t.Fatal("de instellingen van de bash-agent mogen niet veranderen")
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 3 {
		t.Fatalf("er is iets bijgeschreven in de map van de bash-agent: %d bestanden", len(entries))
	}
	// zonder sleutel: geen fout, maar ook geen sleutel
	os.Remove(filepath.Join(dir, "agent.key"))
	if c, _ := loadBashConfig(); c == nil || c.Key != "" {
		t.Fatal("zonder agent.key hoort er geen sleutel te zijn")
	}
	t.Setenv("RZSENSE_BASH_CONF", filepath.Join(dir, "bestaat-niet"))
	if _, err := loadBashConfig(); err == nil {
		t.Fatal("ontbrekende instellingen moeten een fout geven")
	}
}

func TestExplain(t *testing.T) {
	if d, fix := explain(errPin); !strings.Contains(d, "certificaat") || !strings.Contains(fix, "koppelcode") {
		t.Fatalf("vingerafdruk: %q %q", d, fix)
	}
	for in, want := range map[string]string{"dial tcp: lookup rz: no such host": "DNS", "connect: connection refused": "weigert", "HTTP 403: Geweigerd": "weigert deze server", "context deadline exceeded": "geen antwoord"} {
		if d, _ := explain(errString(in)); !strings.Contains(d, want) {
			t.Fatalf("%q → %q, verwacht %q", in, d, want)
		}
	}
}

type errString string

func (e errString) Error() string { return string(e) }

// Het adres dat RzSense de bash-agent gaf (keys/url) gaat voor op MONITOR_URL, zolang het bij dat adres hoort
func TestLoadBashConfigURLFromRzSense(t *testing.T) {
	dir := t.TempDir()
	conf := filepath.Join(dir, "conf")
	_ = os.WriteFile(conf, []byte("MONITOR_URL=\"http://192.0.2.7/\"\n"), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "agent.key"), []byte(strings.Repeat("ab", 32)+"\n"), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "url"), []byte("https://192.0.2.7\nhttp://192.0.2.7\nhttps://reserve.test/\n"), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "url.pins"), []byte("https://192.0.2.7 sha256//abc=\n"), 0o600)
	t.Setenv("RZSENSE_BASH_CONF", conf)
	t.Setenv("RZSENSE_BASH_KEYS", dir)
	c, err := loadBashConfig()
	if err != nil || c.URL != "https://192.0.2.7" || c.URLFallback != "https://reserve.test" || c.Pin != "sha256//abc=" {
		t.Fatalf("het adres van RzSense hoort te gelden, met zijn vingerafdruk: %+v %v", c, err)
	}
	_ = os.WriteFile(filepath.Join(dir, "url"), []byte("https://192.0.2.7\nhttp://oud.test\n\n"), 0o600)
	if c, _ := loadBashConfig(); c.URL != "http://192.0.2.7" {
		t.Errorf("hoort het bewaarde adres bij een ander adres uit de instellingen, dan geldt MONITOR_URL: %s", c.URL)
	}
	if _, err := os.Stat(filepath.Join(dir, "url")); err != nil {
		t.Error("de Go-agent hoort het bestand van de bash-agent te laten staan")
	}
}
