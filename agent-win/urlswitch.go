package main

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
	"time"
)

// Fase 4: net als de Linux-agent:
//   - reserve-RzSense: een tweede RzSense die het werk overneemt als de hoofdserver wegvalt. Alleen RzSense zet dit adres
//     (uit een versleuteld antwoord); de agent probeert het pas als het gewone adres en het reserve-adres allebei niet
//     bereikbaar zijn, en alleen versleuteld met zijn eigen sleutel.
//   - overstappen naar een ander adres (Agent Manager → Adres van RzSense): eerst testen met een ondertekend verzoek; alleen
//     als het antwoord met onze eigen sleutel klopt, gaat de agent dat adres gebruiken. De uitkomst gaat mee met het
//     volgende rapport (url_result).

var specRe = regexp.MustCompile(`^(-|sha256//[A-Za-z0-9+/=]{40,64}(;sha256//[A-Za-z0-9+/=]{40,64})?)$`)

// specPin: "-" (gewoon controleren) of de vingerafdruk(ken) → wat httpClient verwacht ("" of "sha256//…")
func specPin(spec string) string {
	if spec == "-" {
		return ""
	}
	return spec
}

// pinSpec: omgekeerd, voor het rapport (url_pin)
func pinSpec(pin string) string {
	if pin == "" {
		return "-"
	}
	return pin
}

func reserveURL(c *Config) (string, string) {
	f := strings.Fields(readState("reserve"))
	if len(f) != 2 || !urlRe.MatchString(f[0]) || !specRe.MatchString(f[1]) || f[0] == c.URL || f[0] == c.URLFallback {
		return "", ""
	}
	return f[0], specPin(f[1])
}

// setReserve: bewaren wat RzSense zegt ("" = er is geen reserve-RzSense meer)
func setReserve(url, spec string) {
	url = strings.TrimRight(url, "/")
	if url == "" {
		dropState("reserve")
		return
	}
	if urlRe.MatchString(url) && specRe.MatchString(spec) && readState("reserve") != url+" "+spec {
		writeState("reserve", url+" "+spec)
	}
}

type urlSet struct {
	URL      string `json:"url"`
	Fallback string `json:"fallback"`
	Pin      string `json:"pin"`
	FbPin    string `json:"fb_pin"`
}

// switchURL: naar een nieuw adres overstappen als dat werkt (met een ondertekende proef); de uitkomst in url.result
func switchURL(c *Config, s urlSet) {
	if readState("url.result") != "" {
		return // vorige uitkomst nog niet doorgegeven
	}
	nu, fb := strings.TrimRight(s.URL, "/"), strings.TrimRight(s.Fallback, "/")
	if !urlRe.MatchString(nu) || (fb != "" && !urlRe.MatchString(fb)) {
		return
	}
	pin := c.Pin
	if specRe.MatchString(s.Pin) {
		pin = specPin(s.Pin)
	}
	fbPin := ""
	if specRe.MatchString(s.FbPin) {
		fbPin = specPin(s.FbPin)
	}
	test := *c
	test.URL, test.Pin = nu, pin
	body, err := signedGet(&test, "agent_check", 15*time.Second)
	var r struct {
		OK bool `json:"ok"`
	}
	if err != nil || json.Unmarshal(body, &r) != nil || !r.OK {
		why := "het antwoord klopt niet met de sleutel van deze agent"
		if err != nil {
			why = oneLine(err.Error())
		}
		writeState("url.result", fmt.Sprintf("error: %s werkt niet vanaf deze server (%s)", nu, why))
		logf("Nieuw adres %s werkt niet; het huidige adres blijft", nu)
		return
	}
	c.URL, c.Pin, c.URLFallback, c.PinFallback = nu, pin, fb, fbPin
	if err := c.save(); err != nil {
		writeState("url.result", "error: instellingen niet te bewaren: "+oneLine(err.Error()))
		return
	}
	writeState("url.result", "ok "+nu)
	logf("Adres van RzSense gewijzigd naar %s", nu)
}
