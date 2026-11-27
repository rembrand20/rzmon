//go:build linux

package main

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestSavePins(t *testing.T) {
	dir := t.TempDir()
	file := filepath.Join(dir, "url.pins")
	pinA := "sha256//" + strings.Repeat("A", 43) + "="
	pinB := "sha256//" + strings.Repeat("B", 43) + "="
	if err := os.WriteFile(file, []byte("https://oud.voorbeeld "+pinA+"\nhttps://rz.voorbeeld "+pinA+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	c := &Config{URL: "https://rz.voorbeeld", URLFallback: "http://10.0.0.5:8080", Pin: pinA, extra: map[string]string{"key_dir": dir}}
	savePins(c, map[string]string{"https://rz.voorbeeld": pinB, "http://10.0.0.5:8080": "system", "https://vreemd.voorbeeld": pinB})
	got := readText(file)
	want := "https://oud.voorbeeld " + pinA + "\nhttps://rz.voorbeeld " + pinB + "\nhttp://10.0.0.5:8080 system\n"
	if got != want {
		t.Fatalf("url.pins:\n%q\nverwacht:\n%q", got, want)
	}
	if c.Pin != pinB {
		t.Fatalf("de nieuwe vingerafdruk geldt niet meteen: %q", c.Pin)
	}
	// een ongeldige waarde verandert niets
	savePins(c, map[string]string{"https://rz.voorbeeld": "sha256//kort; rm -rf /"})
	if readText(file) == want {
		return
	}
	if strings.Contains(readText(file), "rm -rf") {
		t.Fatal("een ongeldige vingerafdruk kwam in url.pins")
	}
}

func TestSaveKey(t *testing.T) {
	dir := t.TempDir()
	c := &Config{extra: map[string]string{"key_dir": dir}}
	key := strings.Repeat("ab", 32)
	if err := saveKey(c, key); err != nil {
		t.Fatal(err)
	}
	if st, err := os.Stat(filepath.Join(dir, "agent.key")); err != nil || st.Mode().Perm() != 0o600 || readText(filepath.Join(dir, "agent.key")) != key+"\n" {
		t.Fatal("de sleutel staat er niet goed (inhoud of rechten)")
	}
	if saveKey(c, "geen-sleutel") == nil || saveKey(&Config{extra: map[string]string{}}, key) == nil {
		t.Fatal("een ongeldige sleutel of een onbekende map hoort geweigerd te worden")
	}
	if n, _ := os.ReadDir(dir); len(n) != 1 {
		t.Fatal("er bleef een tijdelijk bestand achter in de sleutelmap")
	}
}

func TestTakeoverHold(t *testing.T) {
	t.Setenv("RZSENSE_DATA", t.TempDir())
	writeState("takeover.hold", strconv.FormatInt(time.Now().Unix()-120, 10))
	c := &Config{extra: map[string]string{}}
	if err := takeOver(c); err == nil || !strings.Contains(err.Error(), "uur") {
		t.Fatalf("binnen een uur na een terugval hoort overnemen te wachten: %v", err)
	}
	if modeActive() {
		t.Fatal("de agent werd actief tijdens de pauze")
	}
	writeState("takeover.hold", "9999999999") // de klok is verzet: geen eeuwige pauze
	if err := takeOver(c); err == nil || strings.Contains(err.Error(), "uur") {
		t.Fatalf("een pauze in de toekomst hoort niet te tellen: %v", err)
	}
}
