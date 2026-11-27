//go:build linux

package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestHoldAfter(t *testing.T) {
	d := t.TempDir()
	t.Setenv("RZSENSE_DATA", filepath.Join(d, "data"))
	c := &Config{extra: map[string]string{"key_dir": d}}
	if h, _ := holdAfter(c, false, "", 0); h != nil {
		t.Fatal("zonder aankondiging hoort er geen wachtstand te zijn")
	}
	h, _ := holdAfter(c, true, "abcdef0123", 99999)
	if h == nil || h.until-time.Now().Unix() > 3600 || h.since != 0 {
		t.Fatalf("aankondiging: hooguit een uur wachten, nog niet begonnen: %+v", h)
	}
	if h, _ = holdAfter(c, false, "", 0); h == nil || h.since == 0 {
		t.Fatal("een mislukt rapport tijdens de update hoort de wachtstand te starten")
	}
	since := h.since
	if h, _ = holdAfter(c, true, "abcdef0123", 60); h == nil || h.since != since {
		t.Fatal("dezelfde update opnieuw aangekondigd: het begin hoort te blijven staan")
	}
	if cur, released := holdAfter(c, true, "", 0); cur != nil || !released {
		t.Fatal("RzSense is terug zonder aankondiging: vrijgegeven")
	}
	if _, err := os.Stat(filepath.Join(d, "rzhold")); err == nil {
		t.Fatal("het bestand van de wachtstand hoort weg te zijn")
	}
	// een verlopen of ongeldige wachtstand telt niet
	_ = os.WriteFile(filepath.Join(d, "rzhold"), []byte("abcdef0123 5 1\n"), 0o600)
	if h, _ := holdAfter(c, false, "", 0); h != nil {
		t.Fatal("een verlopen wachtstand hoort niet te gelden")
	}
	_ = os.WriteFile(filepath.Join(d, "rzhold"), []byte("../x 9999999999 1\n"), 0o600)
	if readHold(c) != nil {
		t.Fatal("een ongeldig kenmerk hoort genegeerd te worden")
	}
}

func TestWatchdogParse(t *testing.T) {
	w := wdParse("0\ttick\t60\n0\twatchdog\t7\tu\tt\t-1\thttps://ntfy.voorbeeld\tonderwerp_1\t-\n", map[string]string{})
	if w == nil || w.min != 7 || w.prio != -1 || w.ntfySrv != "https://ntfy.voorbeeld" || w.api != "https://api.pushover.net/1/messages.json" {
		t.Fatalf("regel: %+v", w)
	}
	w = wdParse("0\twatchdog\tx\t-\t-\t9\tftp://raar\tonder werp\t-\n", map[string]string{"PUSHOVER_API": "https://eigen.voorbeeld/1"})
	if w == nil || w.min != 5 || w.prio != 1 || w.ntfySrv != "-" || w.api != "https://eigen.voorbeeld/1" {
		t.Fatalf("ongeldige waarden horen naar de veilige standaard te gaan: %+v", w)
	}
	if w.push("titel", "bericht", 1) {
		t.Fatal("zonder gegevens van Pushover of ntfy hoort er niets verstuurd te worden")
	}
	if wdParse("0\ttick\t60\n", map[string]string{}) != nil {
		t.Fatal("zonder regel staat de waakhond uit")
	}
}
