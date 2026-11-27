//go:build linux

package main

import (
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func hostPort(t *testing.T, addr string) portAddr {
	h, p, err := net.SplitHostPort(addr)
	if err != nil {
		t.Fatal(err)
	}
	return portAddr{p, h}
}

// banner: een server die bij elke verbinding één regel stuurt (of eerst een regel leest als wait gezet is)
func bannerServer(t *testing.T, line string, wait bool) portAddr {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Skip("kan niet luisteren")
	}
	t.Cleanup(func() { ln.Close() })
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func() {
				defer c.Close()
				if wait {
					buf := make([]byte, 64)
					c.Read(buf)
				}
				fmt.Fprint(c, line)
			}()
		}
	}()
	return hostPort(t, ln.Addr().String())
}

func TestProbeServiceLikeBash(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/kapot" {
			w.WriteHeader(503)
			return
		}
		if r.URL.Path == "/door" {
			http.Redirect(w, r, "/elders", 302)
			return
		}
		fmt.Fprint(w, "welkom bij RzSense")
	}))
	defer srv.Close()
	web := hostPort(t, strings.TrimPrefix(srv.URL, "http://"))
	// een webserver die niet op 80/443 luistert: de eerste poort van de dienst
	if p, rc, msg := probeService("nginx.service", []portAddr{web}); p != "http" || rc != 0 || !strings.HasPrefix(msg, "HTTP 200 in ") {
		t.Fatalf("nginx: %q %d %q", p, rc, msg)
	}
	if p, rc, msg := probeService("caddy", nil); p != "http" || rc != 2 || msg != "luistert op geen enkele poort" {
		t.Fatalf("caddy zonder poort: %q %d %q", p, rc, msg)
	}
	if _, rc, _ := probeService("apache2", []portAddr{{"1", "127.0.0.1"}}); rc != 2 {
		t.Fatalf("apache op een dichte poort: %d", rc)
	}
	ssh := bannerServer(t, "SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13\r\n", false)
	if p, rc, msg := probeService("sshd", []portAddr{ssh}); p != "ssh" || rc != 0 || msg != "SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13" {
		t.Fatalf("sshd: %q %d %q", p, rc, msg)
	}
	if _, rc, msg := probeService("ssh", []portAddr{web}); rc != 2 || !strings.HasPrefix(msg, "geen SSH-begroeting") {
		t.Fatalf("ssh op een webserver: %d %q", rc, msg)
	}
	redis := bannerServer(t, "+PONG\r\n", true)
	if p, rc, _ := probeService("redis-server", []portAddr{redis}); p != "redis" || rc != 0 {
		t.Fatalf("redis: %q %d", p, rc)
	}
	noauth := bannerServer(t, "-NOAUTH Authentication required.\r\n", true)
	if _, rc, _ := probeService("valkey", []portAddr{noauth}); rc != 0 {
		t.Fatalf("redis met wachtwoord telt ook als antwoord: %d", rc)
	}
	smtp := bannerServer(t, "220 mail.voorbeeld ESMTP Postfix\r\n", false)
	smtp25 := portAddr{"25", "127.0.0.1"}
	if p, rc, _ := probeService("postfix", []portAddr{smtp}); p != "smtp" || rc != 0 {
		t.Fatalf("postfix op de eerste poort: %q %d", p, rc)
	}
	_ = smtp25
	if p, rc, _ := probeService("dovecot", []portAddr{web}); p != "imap" || rc != -1 {
		t.Fatalf("dovecot zonder poort 143: geen test (%q %d)", p, rc)
	}
	if p, rc, _ := probeService("cloudflared", []portAddr{web}); p != "" || rc != -1 {
		t.Fatalf("een onbekende dienst krijgt geen test: %q %d", p, rc)
	}
	if p, _, _ := probeService("sshguard", nil); p != "" {
		t.Fatalf("sshguard is geen ssh: %q", p)
	}

	// extra tests uit RzSense: URL (met verwachte tekst) en poort
	dir := t.TempDir()
	t.Setenv("RZSENSE_BASH_CACHE", dir)
	os.WriteFile(filepath.Join(dir, "tests.cache"), []byte(strings.Join([]string{
		"31\turl\t" + srv.URL + "/\twelkom",
		"32\turl\t" + srv.URL + "/\tstaat er niet",
		"33\turl\t" + srv.URL + "/kapot\t",
		"34\tport\t" + web.addr + ":" + web.port + "\t",
		"35\tport\t1\t",
		"36\turl\t" + srv.URL + "/door\t",
		"x\turl\t" + srv.URL + "\t",
		"37\tsvc\tnginx\t",
	}, "\n")), 0o600)
	got := collectSvcTestsLinux()
	ok := func(id string) bool { return got[id].(map[string]any)["ok"].(bool) }
	msg := func(id string) string { return got[id].(map[string]any)["msg"].(string) }
	if len(got) != 6 || !ok("31") || !strings.Contains(msg("31"), `bevat "welkom"`) || ok("32") || !strings.Contains(msg("32"), "staat niet in het antwoord") ||
		ok("33") || !strings.HasPrefix(msg("33"), "HTTP 503") || !ok("34") || ok("35") || msg("35") != "poort 1 op 127.0.0.1 antwoordt niet" || !ok("36") {
		t.Fatalf("extra tests: %v", got)
	}
}

func TestSockOpen(t *testing.T) {
	dir := t.TempDir()
	p := filepath.Join(dir, "app.sock")
	ln, err := net.Listen("unix", p)
	if err != nil {
		t.Skip("geen unix-socket")
	}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			c.Write([]byte("HTTP/1.1 200 OK\r\n\r\n"))
			c.Close()
		}
	}()
	if sockOpen(p) != 0 {
		t.Fatal("een socket die antwoordt")
	}
	ln.Close()
	os.WriteFile(filepath.Join(dir, "gewoon"), []byte("x"), 0o600)
	if sockOpen(p) != 1 || sockOpen(filepath.Join(dir, "gewoon")) != 1 || sockOpen(filepath.Join(dir, "weg")) != 1 {
		t.Fatal("een dichte socket, een gewoon bestand en een ontbrekend pad nemen geen verbindingen aan")
	}
}

func TestSvcListAndManual(t *testing.T) {
	files := "nginx.service enabled enabled\nsshd.service enabled enabled\ncups.service disabled enabled\ngetty@.service enabled enabled\noud.service disabled disabled\nkapot.service static -\nfsck@dev\\x2dx.service static -\nsnapd.socket enabled enabled\n"
	units := "nginx.service loaded active running A high performance web server\ncups.service loaded active running CUPS\noud.service loaded failed failed Oud\nkapot.service loaded failed failed Kapot\nsshd.service loaded inactive dead OpenSSH\n"
	got := svcListFrom(files, units)
	want := "[cups active 0] [kapot failed 0] [nginx active 1] [sshd inactive 1]"
	if s := strings.Trim(fmt.Sprint(got), "[]"); "["+s+"]" != want {
		t.Fatalf("lijst met diensten: %v", got)
	}
	m := manualPorts("5\tports\tnginx\t8080 127.0.0.1:9000\n6\tports\tnginx\t/run/app.sock\n7\tsvc\tnginx\t\n8\tports\tsshd\t2222\n")
	if strings.Join(m["nginx"], " ") != "8080 127.0.0.1:9000 /run/app.sock" || strings.Join(m["sshd"], " ") != "2222" {
		t.Fatalf("zelf opgegeven poorten: %v", m)
	}
}
