package main

import (
	"bufio"
	"encoding/base64"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Instellingen in C:\ProgramData\RzSense\agent.conf, met dezelfde namen als /etc/default/rzmon-agent op Linux.
// De eigen sleutel staat er versleuteld in (DPAPI, aan deze computer gebonden): AGENT_KEY_PROTECTED.
type Config struct {
	URL          string // MONITOR_URL
	URLFallback  string // MONITOR_URL_FALLBACK
	Pin          string // PIN: vingerafdruk van het certificaat van RzSense (sha256//…), leeg = gewoon controleren
	PinFallback  string // PIN_FALLBACK
	RemoteUpdate bool   // REMOTE_UPDATE: bijwerken vanuit RzSense (standaard aan)
	Key          string // eigen sleutel (hex), na het uitpakken
	extra        map[string]string
	path         string
}

var hexKeyRe = regexp.MustCompile(`^[0-9a-f]{64}$`)

func dataDir() string {
	if d := os.Getenv("RZSENSE_DATA"); d != "" {
		return d // voor tests
	}
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "RzSense")
}

func configPath() string { return filepath.Join(dataDir(), "agent.conf") }

func loadConfig() (*Config, error) {
	c := &Config{RemoteUpdate: true, extra: map[string]string{}, path: configPath()}
	f, err := os.Open(c.path)
	if err != nil {
		return c, err
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		v = strings.Trim(strings.TrimSpace(v), `"`)
		switch k {
		case "MONITOR_URL":
			c.URL = strings.TrimRight(v, "/")
		case "MONITOR_URL_FALLBACK":
			c.URLFallback = strings.TrimRight(v, "/")
		case "PIN":
			c.Pin = v
		case "PIN_FALLBACK":
			c.PinFallback = v
		case "REMOTE_UPDATE":
			c.RemoteUpdate = v != "0"
		case "AGENT_KEY_PROTECTED":
			raw, err := base64.StdEncoding.DecodeString(v)
			if err == nil {
				if plain, err := unprotect(raw); err == nil && hexKeyRe.Match(plain) {
					c.Key = string(plain)
				}
			}
		default:
			c.extra[k] = v
		}
	}
	return c, sc.Err()
}

// save: in één keer wegschrijven (eerst een tijdelijk bestand), met rechten alleen voor SYSTEM en beheerders
func (c *Config) save() error {
	if err := os.MkdirAll(filepath.Dir(c.path), 0o700); err != nil {
		return err
	}
	lines := []string{
		"# RzSense-agent voor Windows. Met de hand aanpassen mag; de sleutel staat versleuteld (DPAPI).",
		"MONITOR_URL=" + c.URL,
		"MONITOR_URL_FALLBACK=" + c.URLFallback,
		"PIN=" + c.Pin,
		"PIN_FALLBACK=" + c.PinFallback,
		"REMOTE_UPDATE=" + boolStr(c.RemoteUpdate),
	}
	if c.Key != "" {
		p, err := protect([]byte(c.Key))
		if err != nil {
			return fmt.Errorf("sleutel beveiligen (DPAPI): %w", err)
		}
		lines = append(lines, "AGENT_KEY_PROTECTED="+base64.StdEncoding.EncodeToString(p))
	}
	keys := make([]string, 0, len(c.extra))
	for k := range c.extra {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		lines = append(lines, k+"="+c.extra[k])
	}
	tmp := c.path + ".tmp"
	if err := os.WriteFile(tmp, []byte(strings.Join(lines, "\r\n")+"\r\n"), 0o600); err != nil {
		return err
	}
	if err := lockDown(tmp); err != nil {
		os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, c.path)
}

func boolStr(b bool) string {
	if b {
		return "1"
	}
	return "0"
}

// state: kleine bestanden in de datamap (gz toegestaan, tests-kenmerk, uitkomst van een update …)
func readState(name string) string {
	b, err := os.ReadFile(filepath.Join(dataDir(), name))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

func writeState(name, value string) {
	_ = os.WriteFile(filepath.Join(dataDir(), name), []byte(value+"\n"), 0o600)
}

func dropState(name string) { _ = os.Remove(filepath.Join(dataDir(), name)) }

func atoiDef(s string, def int) int {
	if n, err := strconv.Atoi(strings.TrimSpace(s)); err == nil {
		return n
	}
	return def
}
