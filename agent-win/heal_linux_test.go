//go:build linux

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestHealLimits(t *testing.T) {
	d := t.TempDir()
	t.Setenv("RZSENSE_BASH_STATE", d)
	t.Setenv("RZSENSE_DATA", filepath.Join(d, "data"))
	if !healOK("7") {
		t.Fatal("zonder eerdere poging hoort het te mogen")
	}
	healLog("7", false, "regel een\nregel\ttwee \"quote\"")
	if healOK("7") {
		t.Fatal("binnen 10 minuten hoort er geen tweede poging te komen")
	}
	now := time.Now().Unix()
	tries := filepath.Join(d, "heal", "7.tries")
	_ = os.WriteFile(tries, []byte(fmt.Sprintf("%d\n%d\n", now-7200, now-3600)), 0o600)
	if !healOK("7") {
		t.Fatal("na een uur, met 2 pogingen vandaag, hoort een derde te mogen")
	}
	_ = os.WriteFile(tries, []byte(fmt.Sprintf("%d\n%d\n%d\n", now-9000, now-7200, now-3600)), 0o600)
	if healOK("7") {
		t.Fatal("na 3 pogingen in een dag hoort het te stoppen")
	}
	_ = os.WriteFile(tries, []byte(fmt.Sprintf("%d\n%d\n%d\n", now-200000, now-190000, now-3600)), 0o600)
	if !healOK("7") {
		t.Fatal("pogingen van meer dan een dag geleden tellen niet")
	}
	line := strings.TrimSpace(readText(filepath.Join(d, "heal", "done")))
	if !healLineRe.MatchString(line) || !strings.Contains(line, `regel een regel twee \"quote\"`) {
		t.Fatalf("de uitkomst staat niet in de vorm die RzSense verwacht: %s", line)
	}
	res, _ := healReport()
	if len(res) != 1 {
		t.Fatalf("de uitkomst hoort mee te gaan met het rapport: %v", res)
	}
	healSent(false) // rapport niet aangekomen: de volgende keer opnieuw
	if res, _ = healReport(); len(res) != 1 {
		t.Fatal("na een mislukt rapport hoort de uitkomst opnieuw mee te gaan")
	}
	healSent(true)
	if res, _ = healReport(); len(res) != 0 {
		t.Fatal("na een aangenomen rapport hoort de uitkomst weg te zijn")
	}
}

func TestHealCmdRules(t *testing.T) {
	d := t.TempDir()
	w := func(name string, mode os.FileMode) string {
		p := filepath.Join(d, name)
		_ = os.WriteFile(p, []byte("#!/bin/sh\n"), 0o600)
		_ = os.Chmod(p, mode)
		return p
	}
	open, noexec := w("open.sh", 0o777), w("noexec", 0o644)
	good := w("ok.sh", 0o755)
	_ = os.Symlink(good, filepath.Join(d, "link.sh"))
	if healCmdOK(open) || healCmdOK(noexec) || healCmdOK(filepath.Join(d, "link.sh")) || healCmdOK(filepath.Join(d, "bestaat-niet")) || healCmdOK(d) {
		t.Fatal("een bestand dat anderen kunnen wijzigen, niet uitvoerbaar is, een symlink of een map hoort geweigerd te worden")
	}
	if os.Geteuid() == 0 && !healCmdOK(good) {
		t.Fatal("een uitvoerbaar bestand van root dat alleen root kan wijzigen hoort te mogen")
	}
	if os.Geteuid() != 0 && healCmdOK(good) {
		t.Fatal("een bestand dat niet van root is hoort geweigerd te worden")
	}
	for _, n := range []string{"../x", "a b", "", "x;rm", strings.Repeat("a", 65)} {
		if healNameRe.MatchString(n) {
			t.Errorf("naam %q hoort ongeldig te zijn", n)
		}
	}
}
