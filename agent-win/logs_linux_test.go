//go:build linux

package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestLogSecretAndLike(t *testing.T) {
	secret := []string{"/home/piet/.ssh/known_hosts", "/opt/app/secrets/x.log", "/opt/app/server.key", "/opt/app/.env", "/opt/app/app.env.local", "/opt/app/Wachtwoord-PASSWD.log",
		"/opt/app/api-token.txt", "/opt/app/id_rsa", "/root/.bash_history", "/opt/app/config.yaml", "/opt/app/db.sqlite3", "/opt/app/.verborgen.log", "/etc/ssl/x.log", "/opt/app/settings.php"}
	for _, p := range secret {
		if !logSecret(p) {
			t.Errorf("%s hoort als geheim te tellen", p)
		}
	}
	for _, p := range []string{"/var/log/syslog", "/opt/app/logs/app.log", "/opt/app/uitvoer.txt", "/srv/web/access_log"} {
		if logSecret(p) || !logLike(p) {
			t.Errorf("%s is een gewoon logbestand", p)
		}
	}
	if logLike("/opt/app/data.bin") {
		t.Error("data.bin lijkt niet op een log")
	}
}

func TestLogRulesAllowed(t *testing.T) {
	d := t.TempDir()
	for _, p := range []string{"var/log", "opt/dienst/logs", "elders"} {
		_ = os.MkdirAll(filepath.Join(d, p), 0o700)
	}
	w := func(p string) string {
		f := filepath.Join(d, p)
		_ = os.WriteFile(f, []byte("x\n"), 0o600)
		return f
	}
	in, out := w("var/log/app.log"), w("elders/buiten.log")
	remoteLog, remoteKey := w("opt/dienst/logs/dienst.log"), w("opt/dienst/logs/server.key")
	_ = os.Symlink(out, filepath.Join(d, "var/log/link.log"))
	conf := map[string]string{"LOG_DIRS": filepath.Join(d, "var/log"), "LOG_DIRS_MAX": filepath.Join(d, "opt")}
	r := loadLogRules(conf, "0\tlogdirs\t"+filepath.Join(d, "opt/dienst")+" "+filepath.Join(d, "elders")+" relatief\n")
	if len(r.remote) != 1 || len(r.refused) != 2 {
		t.Fatalf("mappen uit RzSense: %v geweigerd %v", r.remote, r.refused)
	}
	if !r.allowed(in) || !r.allowed(remoteLog) {
		t.Fatal("een log binnen LOG_DIRS of in een toegestane map uit RzSense hoort te mogen")
	}
	for _, p := range []string{out, remoteKey, filepath.Join(d, "var/log/link.log"), filepath.Join(d, "var/log/../../elders/buiten.log"), "/etc/shadow"} {
		if r.allowed(p) {
			t.Errorf("%s hoort niet te mogen", p)
		}
	}
	// LOG_DIRS_MAX leeg = uit: geen enkele map uit RzSense
	if r2 := loadLogRules(map[string]string{"LOG_DIRS": filepath.Join(d, "var/log"), "LOG_DIRS_MAX": ""}, "0\tlogdirs\t"+filepath.Join(d, "opt/dienst")+"\n"); len(r2.remote) != 0 || r2.allowed(remoteLog) {
		t.Fatal("met LOG_DIRS_MAX leeg hoort geen map uit RzSense te mogen")
	}
	// de hele schijf mag nooit
	if r3 := loadLogRules(map[string]string{"LOG_DIRS": "/"}, ""); r3.allowed("/etc/passwd") {
		t.Fatal("LOG_DIRS=/ hoort niet de hele schijf vrij te geven")
	}
}

func TestReadNewAndTopIPs(t *testing.T) {
	f := filepath.Join(t.TempDir(), "a.log")
	_ = os.WriteFile(f, []byte("oud\n"), 0o600)
	txt, st, _ := readNew(f, "", 1000)
	if txt != "" {
		t.Fatalf("de eerste keer hoort er niets ouds mee te komen: %q", txt)
	}
	fh, _ := os.OpenFile(f, os.O_APPEND|os.O_WRONLY, 0o600)
	_, _ = fh.WriteString("een\ntwee\nhalf")
	fh.Close()
	if txt, st, _ = readNew(f, st, 1000); txt != "een\ntwee\n" {
		t.Fatalf("alleen volledige regels: %q", txt)
	}
	if txt, _, _ = readNew(f, "999|4", 1000); txt != "oud\neen\ntwee\n" {
		t.Fatalf("een ander bestand (inode) hoort vanaf het begin gelezen te worden: %q", txt)
	}
	_ = st
	b, _ := json.Marshal(topIPsLinux("a 10.0.0.1 b 10.0.0.2\n10.0.0.2 x 10.0.0.3 10.0.0.9\n10.0.0.2"))
	if string(b) != `[["10.0.0.2",3],["10.0.0.9",1],["10.0.0.3",1]]` {
		t.Fatalf("adressen: %s", b)
	}
}
