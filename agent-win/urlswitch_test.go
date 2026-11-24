package main

import "testing"

func TestReserveAndSpecs(t *testing.T) {
	t.Setenv("RZSENSE_DATA", t.TempDir())
	c := &Config{URL: "https://rz.voorbeeld", URLFallback: "http://192.0.2.1"}
	if u, _ := reserveURL(c); u != "" {
		t.Fatal("zonder reserve geen adres")
	}
	setReserve("https://reserve.voorbeeld/", "sha256//"+"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=")
	if u, p := reserveURL(c); u != "https://reserve.voorbeeld" || p == "" {
		t.Fatalf("reserve: %q %q", u, p)
	}
	setReserve("https://reserve.voorbeeld", "rm -rf") // ongeldig: niets veranderen
	if u, _ := reserveURL(c); u == "" {
		t.Fatal("ongeldige controle mag de reserve niet weghalen")
	}
	setReserve("", "")
	if u, _ := reserveURL(c); u != "" {
		t.Fatal("leeg adres = geen reserve meer")
	}
	setReserve("https://rz.voorbeeld", "-") // zelfde als het gewone adres: niet als reserve gebruiken
	if u, _ := reserveURL(c); u != "" {
		t.Fatal("reserve = gewone adres")
	}
	if pinSpec("") != "-" || specPin("-") != "" || !specRe.MatchString("sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=;sha256//BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=") {
		t.Fatal("specs")
	}
	// Overstappen naar een adres dat niet werkt: uitkomst "error", het adres blijft
	switchURL(c, urlSet{URL: "http://127.0.0.1:1", Fallback: ""})
	if c.URL != "https://rz.voorbeeld" || readState("url.result") == "" || readState("url.result")[:6] != "error:" {
		t.Fatalf("mislukte overstap: %q %q", c.URL, readState("url.result"))
	}
}
