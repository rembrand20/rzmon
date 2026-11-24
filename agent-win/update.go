package main

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Bijwerken: RzSense geeft de nieuwe versie en de SHA-256 door. We halen de .exe en zijn handtekening bij RzSense,
// en gebruiken hem alleen als de ed25519-handtekening klopt met de publieke sleutel hieronder (dezelfde als in de
// Linux-agent) en de SHA-256 overeenkomt. Daarna stopt de service met een fout; Windows start hem (herstelopties)
// met de nieuwe .exe. Lukt iets niet, dan blijft de huidige versie draaien.

// agentSignPub: publieke sleutel (SPKI, base64), gelijk aan AGENT_SIGN_PUB in agent/src/80-bijwerken.sh
const agentSignPub = "MCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM="

var verRe = regexp.MustCompile(`^\d+\.\d+\.\d+$`)

func pubKey() (ed25519.PublicKey, error) {
	der, err := base64.StdEncoding.DecodeString(agentSignPub)
	if err != nil || len(der) != 44 {
		return nil, errors.New("publieke sleutel ongeldig")
	}
	return ed25519.PublicKey(der[12:]), nil
}

// verifyExe: klopt de handtekening (base64) bij dit bestand, en de SHA-256?
func verifyExe(exe []byte, sigB64, wantSHA string) error {
	pub, err := pubKey()
	if err != nil {
		return err
	}
	sig, err := base64.StdEncoding.DecodeString(strings.TrimSpace(sigB64))
	if err != nil || len(sig) != ed25519.SignatureSize {
		return errors.New("handtekening onleesbaar")
	}
	if !ed25519.Verify(pub, exe, sig) {
		return errors.New("handtekening klopt niet")
	}
	if wantSHA != "" {
		sum := sha256.Sum256(exe)
		if !strings.EqualFold(hex.EncodeToString(sum[:]), wantSHA) {
			return errors.New("SHA-256 wijkt af van wat RzSense doorgaf")
		}
	}
	return nil
}

func selfUpdate(c *Config, version, sha string) {
	if !verRe.MatchString(version) || version == Version {
		return
	}
	fail := func(why string) {
		logf("Bijwerken naar %s mislukt: %s", version, why)
		writeState("update.result", "error "+why)
	}
	exe, err := call(c.URL, c.Pin, "GET", "agent_win_exe", nil, nil, 120*time.Second)
	if err != nil || exe.Code != 200 {
		fail(fmt.Sprint("downloaden: ", errOrCode(err, exe)))
		return
	}
	sig, err := call(c.URL, c.Pin, "GET", "agent_win_sig", nil, nil, 20*time.Second)
	if err != nil || sig.Code != 200 {
		fail(fmt.Sprint("handtekening ophalen: ", errOrCode(err, sig)))
		return
	}
	if err := verifyExe(exe.Body, string(sig.Body), sha); err != nil {
		fail(err.Error())
		return
	}
	self, err := os.Executable()
	if err != nil {
		fail(err.Error())
		return
	}
	dir := filepath.Dir(self)
	newPath := filepath.Join(dir, "rzsense-agent.exe.new")
	if err := os.WriteFile(newPath, exe.Body, 0o755); err != nil {
		fail("wegschrijven: " + err.Error())
		return
	}
	// Een draaiende .exe mag op Windows wel van naam veranderen: de oude wordt .old, de nieuwe neemt zijn plaats in
	old := filepath.Join(dir, "rzsense-agent.exe.old")
	_ = os.Remove(old)
	if err := os.Rename(self, old); err != nil {
		os.Remove(newPath)
		fail("vervangen: " + err.Error())
		return
	}
	if err := os.Rename(newPath, self); err != nil {
		_ = os.Rename(old, self)
		fail("vervangen: " + err.Error())
		return
	}
	writeState("update.result", fmt.Sprintf("ok %s %s", Version, version))
	logf("Bijgewerkt naar %s; de service start opnieuw", version)
	restartForUpdate()
}

func errOrCode(err error, r *resp) string {
	if err != nil {
		return err.Error()
	}
	return fmt.Sprintf("HTTP %d", r.Code)
}
