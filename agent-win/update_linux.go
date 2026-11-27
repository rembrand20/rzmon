//go:build linux

package main

// De Go-agent op Linux werkt zichzelf bij (0.12.0). RzSense meldt in het antwoord op een proefronde dat er een nieuwere
// versie is, met de SHA-256. De agent haalt het bestand en de handtekening op en vervangt zichzelf alleen als:
//   - de ed25519-handtekening van de ontwikkelaar klopt (de publieke sleutel zit in de agent zelf) en de SHA-256 klopt;
//   - de versie echt nieuwer is (nooit terug naar een oudere);
//   - het nieuwe bestand hier kan draaien en de verwachte versie meldt;
//   - bijwerken vanuit RzSense niet is uitgezet (REMOTE_UPDATE=0 in de instellingen van de agent).
// Lukt iets niet, dan blijft de huidige versie staan en staat de reden in RzSense en in "rzsense status".

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

// goArch: de naam van het bestand voor deze processor (zoals RzSense ze levert), of "" als er geen is
func goArch() string {
	switch runtime.GOARCH {
	case "amd64", "arm64":
		return runtime.GOARCH
	case "arm":
		return "armv7"
	}
	return ""
}

// newerVersion: is a nieuwer dan b (x.y.z)?
func newerVersion(a, b string) bool {
	if !verRe.MatchString(a) || !verRe.MatchString(b) {
		return false
	}
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < 3; i++ {
		x, _ := strconv.Atoi(pa[i])
		y, _ := strconv.Atoi(pb[i])
		if x != y {
			return x > y
		}
	}
	return false
}

// installNew: het nieuwe bestand controleren en op de plaats van het huidige zetten. Geeft de reden als het niet lukt.
func installNew(body []byte, sig, sha, version, self string) error {
	if err := verifyExe(body, sig, sha); err != nil {
		return err
	}
	return placeNew(body, version, self)
}

// placeNew: een bestand waarvan de handtekening al klopt: proefdraaien en dan pas op de plaats van het huidige zetten
func placeNew(body []byte, version, self string) error {
	tmp := filepath.Join(filepath.Dir(self), ".rzsense-new")
	if err := os.WriteFile(tmp, body, 0o755); err != nil {
		return fmt.Errorf("kan niet schrijven in %s (voer het installatiecommando uit RzSense opnieuw uit): %w", filepath.Dir(self), err)
	}
	defer os.Remove(tmp)
	out, err := exec.Command(tmp, "version").Output()
	if err != nil || strings.TrimSpace(string(out)) != "rzsense "+version {
		return fmt.Errorf("het nieuwe bestand meldt niet versie %s", version)
	}
	if err := os.Rename(tmp, self); err != nil {
		return fmt.Errorf("vervangen: %w", err)
	}
	return nil
}

// selfUpdateLinux: bijwerken naar de versie die RzSense doorgaf. De uitkomst komt in de status "update.last".
func selfUpdateLinux(c *Config, version, sha string) {
	if !newerVersion(version, Version) || goArch() == "" {
		return
	}
	stamp := time.Now().Format("2006-01-02 15:04:05")
	fail := func(why string) { writeState("update.last", stamp+" ✘ bijwerken naar "+version+" mislukt: "+why) }
	if conf, _ := readShellConf(bashConfPath()); conf["REMOTE_UPDATE"] == "0" {
		fail("bijwerken vanuit RzSense staat uit (REMOTE_UPDATE=0); voer het installatiecommando zelf uit")
		return
	}
	bin, err := call(c.URL, c.Pin, "GET", "agent_go_bin&arch="+goArch(), nil, nil, 120*time.Second)
	if err != nil || bin.Code != 200 {
		fail("downloaden: " + errOrCode(err, bin))
		return
	}
	sig, err := call(c.URL, c.Pin, "GET", "agent_go_sig&arch="+goArch(), nil, nil, 20*time.Second)
	if err != nil || sig.Code != 200 {
		fail("handtekening ophalen: " + errOrCode(err, sig))
		return
	}
	self, err := os.Executable()
	if err != nil {
		fail(err.Error())
		return
	}
	if real, err := filepath.EvalSymlinks(self); err == nil {
		self = real
	}
	if err := installNew(bin.Body, string(sig.Body), sha, version, self); err != nil {
		fail(err.Error())
		return
	}
	writeState("update.last", fmt.Sprintf("%s ✔ bijgewerkt van %s naar %s", stamp, Version, version))
}
