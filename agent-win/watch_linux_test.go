//go:build linux

package main

import (
	"context"
	"encoding/base64"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

func TestWatchPattern(t *testing.T) {
	re := watchPattern("0\ttick\t30\n12\tsvc\tnginx.service\n13\tsvc\tniet geldig\n")
	for line, want := range map[string]bool{
		"Nov 27 10:00:00 h sshd[12]: Accepted password for piet from 1.2.3.4": true,
		"Nov 27 10:00:00 h sshd-session[12]: Failed password for root":        true,
		"Nov 27 10:00:00 h sudo[9]: piet : TTY=pts/0 ; COMMAND=/bin/ls":       true,
		"Nov 27 10:00:00 h su[9]: (to root) piet on pts/0":                    true,
		"Nov 27 10:00:00 h systemd[1]: nginx.service: Main process exited":    true,
		"Nov 27 10:00:00 h systemd[1]: Stopped nginxXservice: iets":           false,
		"Nov 27 10:00:00 h systemd[1]: redis.service: Main process exited":    false,
		"Nov 27 10:00:00 h kernel: iets anders":                               false,
	} {
		if got := re.MatchString(line); got != want {
			t.Errorf("%q: %v, wil %v", line, got, want)
		}
	}
}

func TestWatchFimRootsAndSig(t *testing.T) {
	dir := t.TempDir()
	cfg := "e\t1\np\t2\t" + dir + "\np\t1\t/etc\np\t2\t" + dir + "/sub/*.conf\np\t2\t/bestaat/niet\n"
	tests := "0\ttick\t30\n0\tfim\t" + base64.StdEncoding.EncodeToString([]byte(cfg)) + "\n"
	if r := watchFimRoots(tests); len(r) != 1 || r[0] != dir {
		t.Errorf("alleen de rode paden die bestaan: %v", r)
	}
	if watchSig(tests) == watchSig(tests+"0\trealtime\t1\n") || watchSig(tests) != watchSig(tests+"0\tblock\t1.2.3.4\t0\n") {
		t.Error("de vingerafdruk hoort alleen te veranderen bij regels voor de watcher")
	}
}

func TestTailFollowRotation(t *testing.T) {
	f := filepath.Join(t.TempDir(), "log")
	if err := os.WriteFile(f, []byte("oud\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	got := []string{}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go tailFollow(ctx, f, func(l string) { mu.Lock(); got = append(got, l); mu.Unlock() })
	time.Sleep(300 * time.Millisecond)
	add := func(s string) {
		fh, _ := os.OpenFile(f, os.O_APPEND|os.O_WRONLY, 0o600)
		_, _ = fh.WriteString(s)
		fh.Close()
	}
	add("een\ntwee")
	time.Sleep(1500 * time.Millisecond)
	add("\n")
	_ = os.Rename(f, f+".1") // roteren: een nieuw bestand met dezelfde naam
	_ = os.WriteFile(f, []byte("na roteren\n"), 0o600)
	time.Sleep(2500 * time.Millisecond)
	mu.Lock()
	defer mu.Unlock()
	if len(got) < 3 || got[0] != "een" || got[len(got)-1] != "na roteren" {
		t.Errorf("onverwacht: %q (de oude regel hoort niet mee te komen, de nieuwe na roteren wel)", got)
	}
}

func TestEdrNetNewWithoutProcess(t *testing.T) {
	state := filepath.Join(t.TempDir(), "net.state")
	est := "0 0 192.168.1.10:50003 [::ffff:203.0.113.8]:80\n0 0 192.168.1.10:22 192.168.1.50:51234 users:((\"sshd\",pid=9,fd=4))\n"
	out := edrNetNew(est, "LISTEN 0 128 0.0.0.0:22 0.0.0.0:* users:((\"sshd\",pid=8,fd=3))\n", state, 1000)
	if len(out) != 1 || out[0].rip != "203.0.113.8" || out[0].rp != "80" || out[0].lport != "50003" || out[0].comm != "" {
		t.Fatalf("een verbinding zonder proces hoort met het juiste adres mee te komen: %+v", out)
	}
	if again := edrNetNew(est, "", state, 1100); len(again) != 1 || again[0].lport != "22" {
		t.Errorf("binnen een uur dezelfde verbinding niet opnieuw (zonder luisterpoorten telt 22 wel mee): %+v", again)
	}
	if later := edrNetNew(est, "", state, 1000+3700); len(later) != 2 {
		t.Errorf("na een uur mag hij opnieuw gemeld worden: %+v", later)
	}
}
