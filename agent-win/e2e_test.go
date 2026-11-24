package main

import (
	"os"
	"testing"
)

// Echte proef tegen een draaiende RzSense (alleen als RZ_E2E_URL en RZ_E2E_PAIR gezet zijn, zie tests/agent-win-e2e.sh):
// koppelen met een koppelcode, de testlijst ophalen, een rapport versturen en het versleutelde antwoord controleren.
func TestEndToEnd(t *testing.T) {
	url, code := os.Getenv("RZ_E2E_URL"), os.Getenv("RZ_E2E_PAIR")
	if url == "" || code == "" {
		t.Skip("geen RZ_E2E_URL/RZ_E2E_PAIR")
	}
	t.Setenv("RZSENSE_DATA", t.TempDir())
	c, _ := loadConfig()
	c.URL = url
	host, err := pair(c, code)
	if err != nil {
		t.Fatalf("koppelen: %v", err)
	}
	if c.Key == "" || host == "" {
		t.Fatal("geen sleutel of servernaam na koppelen")
	}
	c2, err := loadConfig()
	if err != nil || c2.Key != c.Key {
		t.Fatalf("sleutel niet goed bewaard: %v", err)
	}
	for i := 0; i < 2; i++ { // twee keer: de tweede keer gecomprimeerd (gz) en met de testlijst uit de cache
		if _, err := round(c2, i == 0); err != nil {
			t.Fatalf("ronde %d: %v", i+1, err)
		}
	}
	if readState("gz") != "1" {
		t.Log("RzSense meldde geen gz (zlib ontbreekt?)")
	}
}

func TestParseTestsRev(t *testing.T) {
	// Kenmerk zoals RzSense het berekent: sha1(rtrim(lijst) + "\n"), eerste 12 tekens
	got := parseTests("0\ttick\t60\n12\tsvc\tW3SVC\n").Rev
	if got != "a7e84ded27ba" {
		t.Fatalf("rev = %s", got)
	}
	tt := parseTests("0\ttick\t30\n12\tsvc\tW3SVC\n13\tproc\tname\tc3FsLmV4ZQ==\t\n")
	if tt.Tick != 30 || tt.Svc["12"] != "W3SVC" || len(tt.Procs) != 1 || tt.Procs[0].Value != "sql.exe" {
		t.Fatalf("lijst verkeerd gelezen: %+v", tt)
	}
}

func TestVerifyExeRejects(t *testing.T) {
	if err := verifyExe([]byte("niets"), "AAAA", ""); err == nil {
		t.Fatal("ongeldige handtekening geaccepteerd")
	}
	if _, err := pubKey(); err != nil {
		t.Fatal(err)
	}
}
