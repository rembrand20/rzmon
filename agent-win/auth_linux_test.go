//go:build linux

package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestAuthParse(t *testing.T) {
	lines := strings.Join([]string{
		"1793700001\tsshd\tInvalid user bob from 203.0.113.7 port 50211",
		"1793700001\tsshd\tFailed password for invalid user bob from 203.0.113.7 port 50211 ssh2",
		"1793700002\tsshd\tFailed password for root from 203.0.113.7 port 50212 ssh2",
		"1793700010\tsshd\tAccepted publickey for piet from 192.168.2.5 port 51544 ssh2: ED25519 SHA256:abc",
		"1793700900\tsshd-session\tDisconnected from user piet 192.168.2.5 port 51544",
		"1793700905\tsudo\t  piet : TTY=pts/0 ; PWD=/home/piet ; USER=root ; COMMAND=/usr/bin/mysql -pGeheim --password hunter2 token=abc",
		"1793700906\tsudo\t  piet : user NOT in sudoers ; TTY=pts/1 ; PWD=/ ; USER=root ; COMMAND=/usr/bin/id",
		"1793700907\tsu-l\tpam_unix(su-l:session): session opened for user postgres(uid=26) by (uid=0)",
		"1793700908\tsu\tpam_unix(su:session): session opened for user root(uid=0) by piet(uid=1000)",
		"rommel zonder tabs",
		"abc\tsshd\tAccepted password for x from 1.2.3.4 port 1 ssh2",
	}, "\n")
	b, _ := json.Marshal(authParse(lines))
	var got struct {
		Events []map[string]any `json:"events"`
		Fails  []map[string]any `json:"fails"`
		Tries  []map[string]any `json:"tries"`
	}
	if err := json.Unmarshal(b, &got); err != nil {
		t.Fatal(err)
	}
	kinds := ""
	for _, e := range got.Events {
		kinds += e["k"].(string) + " "
	}
	if kinds != "ssh ssh_end sudo su " {
		t.Fatalf("gebeurtenissen: %q (su vanuit een dienst en ongeldige regels horen er niet bij)", kinds)
	}
	if cmd := got.Events[2]["cmd"].(string); strings.Contains(cmd, "Geheim") || strings.Contains(cmd, "hunter2") || strings.Contains(cmd, "abc") || !strings.Contains(cmd, "-p••••") {
		t.Fatalf("wachtwoorden zijn niet gemaskeerd: %q", cmd)
	}
	if len(got.Tries) != 2 || got.Tries[0]["u"] != "bob" || got.Tries[0]["p"].(float64) != 50211 || got.Tries[1]["u"] != "root" {
		t.Fatalf("losse pogingen (bob maar één keer): %v", got.Tries)
	}
	if len(got.Fails) != 2 || got.Fails[0]["k"] != "ssh" || got.Fails[0]["n"].(float64) != 2 || got.Fails[1]["k"] != "sudo" || got.Fails[1]["why"] != "user NOT in sudoers" {
		t.Fatalf("opgetelde pogingen: %v", got.Fails)
	}
}

func TestAuthSourceLines(t *testing.T) {
	j := journalToLines("1793700010.123456 nas sshd[812]: Accepted publickey for piet from 192.168.2.5 port 51544 ssh2\n1793700011.5 nas sudo:   piet : TTY=pts/0 ; PWD=/ ; USER=root ; COMMAND=/bin/ls\nkort\n")
	if j != "1793700010\tsshd\tAccepted publickey for piet from 192.168.2.5 port 51544 ssh2\n1793700011\tsudo\t  piet : TTY=pts/0 ; PWD=/ ; USER=root ; COMMAND=/bin/ls\n" {
		t.Fatalf("journal: %q", j)
	}
	f := fileToLines("Oct 21 10:00:01 nas sshd[12]: Failed password for root from 203.0.113.7 port 1 ssh2\n2026-10-21T10:00:02+02:00 nas su: pam_unix(su:session): session opened for user root(uid=0) by piet(uid=1000)\n", 42)
	if f != "42\tsshd\tFailed password for root from 203.0.113.7 port 1 ssh2\n42\tsu\tpam_unix(su:session): session opened for user root(uid=0) by piet(uid=1000)\n" {
		t.Fatalf("logbestand: %q", f)
	}
}

// Het logbestand: de eerste keer vanaf het einde, daarna alleen het nieuwe, een halve regel wacht, een nieuw bestand begint vooraan
func TestAuthFileCursor(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("RZSENSE_DATA", filepath.Join(dir, "data"))
	t.Setenv("RZSENSE_AUTHSTATE", filepath.Join(dir, "authstate"))
	log := filepath.Join(dir, "auth.log")
	conf := map[string]string{"AUTH_SOURCE": "file", "AUTH_LOGS": filepath.Join(dir, "bestaat-niet") + " " + log}
	add := func(s string) {
		fh, _ := os.OpenFile(log, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
		_, _ = fh.WriteString(s)
		_ = fh.Close()
	}
	step := func() string {
		lines, state := authNewLines(conf, 100)
		_ = os.WriteFile(authStatePath(), []byte(state+"\n"), 0o600)
		return lines
	}
	add("Oct 21 10:00:01 nas sshd[1]: oud\n")
	if got := step(); got != "" {
		t.Fatalf("de eerste keer hoort er niets oud mee te komen: %q", got)
	}
	add("Oct 21 10:00:02 nas sshd[1]: een\nOct 21 10:00:03 nas sshd[1]: half")
	if got := step(); got != "100\tsshd\teen\n" {
		t.Fatalf("alleen de volledige nieuwe regel: %q", got)
	}
	add(" af\n")
	if got := step(); got != "100\tsshd\thalf af\n" {
		t.Fatalf("de halve regel komt de volgende keer heel: %q", got)
	}
	if got := step(); got != "" {
		t.Fatalf("niets nieuws, toch regels: %q", got)
	}
	_ = os.Rename(log, log+".1") // logrotate
	add("Oct 21 10:00:05 nas sshd[1]: nieuw bestand\n")
	if got := step(); got != "100\tsshd\tnieuw bestand\n" {
		t.Fatalf("een nieuw bestand hoort vanaf het begin gelezen te worden: %q", got)
	}
	// de stand van de bash-agent wordt overgenomen (zelfde formaat)
	if st := readText(authStatePath()); !strings.HasPrefix(st, "file|") {
		t.Fatalf("stand: %q", st)
	}
}
