//go:build linux

package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestNewerVersion(t *testing.T) {
	for _, c := range []struct {
		a, b string
		want bool
	}{{"0.12.0", "0.11.0", true}, {"0.11.0", "0.12.0", false}, {"0.12.0", "0.12.0", false}, {"1.0.0", "0.99.9", true}, {"0.10.0", "0.9.0", true}, {"raar", "0.1.0", false}, {"0.12.0", "", false}} {
		if newerVersion(c.a, c.b) != c.want {
			t.Fatalf("%s nieuwer dan %s: verwacht %v", c.a, c.b, c.want)
		}
	}
	if a := goArch(); a != "amd64" && a != "arm64" && a != "armv7" && a != "" {
		t.Fatalf("processor: %q", a)
	}
}

// Een bestand met een handtekening van iemand anders, of met een verkeerde SHA-256, wordt nooit geïnstalleerd
func TestInstallNewRejects(t *testing.T) {
	dir := t.TempDir()
	self := filepath.Join(dir, "rzsense")
	os.WriteFile(self, []byte("oud"), 0o755)
	body := []byte("#!/bin/sh\necho 'rzsense 9.9.9'\n")
	sum := sha256.Sum256(body)
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	otherSig := base64.StdEncoding.EncodeToString(ed25519.Sign(priv, body))
	for name, sig := range map[string]string{"vreemde sleutel": otherSig, "geen handtekening": "", "onzin": "AAAA"} {
		if err := installNew(body, sig, hex.EncodeToString(sum[:]), "9.9.9", self); err == nil {
			t.Fatalf("%s: toch geïnstalleerd", name)
		}
	}
	if b, _ := os.ReadFile(self); string(b) != "oud" {
		t.Fatal("het huidige bestand is aangeraakt")
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 1 {
		t.Fatalf("er is iets achtergebleven: %d bestanden", len(entries))
	}
	if err := verifyExe(body, otherSig, ""); err == nil || !strings.Contains(err.Error(), "handtekening") {
		t.Fatalf("verwacht een fout over de handtekening, kreeg %v", err)
	}
}

// Na een geldige handtekening: het nieuwe bestand moet hier draaien en de verwachte versie melden, anders blijft het oude staan
func TestPlaceNew(t *testing.T) {
	dir := t.TempDir()
	self := filepath.Join(dir, "rzsense")
	os.WriteFile(self, []byte("oud"), 0o755)
	if err := placeNew([]byte("#!/bin/sh\necho 'rzsense 9.9.8'\n"), "9.9.9", self); err == nil {
		t.Fatal("een bestand dat een andere versie meldt is toch geplaatst")
	}
	if err := placeNew([]byte("geen programma"), "9.9.9", self); err == nil {
		t.Fatal("een bestand dat niet kan draaien is toch geplaatst")
	}
	if b, _ := os.ReadFile(self); string(b) != "oud" {
		t.Fatal("het huidige bestand is aangeraakt")
	}
	if err := placeNew([]byte("#!/bin/sh\necho 'rzsense 9.9.9'\n"), "9.9.9", self); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(self)
	st, _ := os.Stat(self)
	if !strings.Contains(string(b), "9.9.9") || st.Mode().Perm()&0o100 == 0 {
		t.Fatal("het nieuwe bestand staat er niet, of is niet uitvoerbaar")
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 1 {
		t.Fatalf("er is iets achtergebleven: %d bestanden", len(entries))
	}
}
