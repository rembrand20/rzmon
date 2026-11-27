//go:build linux

package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestIdsSuspectExe(t *testing.T) {
	for _, e := range []string{"/tmp/x", "/dev/shm/.a/b", "/var/tmp/miner", "/memfd:payload (deleted)", "/home/piet/tool (deleted)", "/root/x (deleted)"} {
		if !idsSuspectExe(e) {
			t.Errorf("%s hoort verdacht te zijn", e)
		}
	}
	for _, e := range []string{"/usr/sbin/sshd", "/usr/bin/python3 (deleted)", "/opt/app/bin/x (deleted)", "/memfd:runc_cloned:/proc/self/exe (deleted)", "/var/lib/docker/x (deleted)", "/home/piet/tool"} {
		if idsSuspectExe(e) {
			t.Errorf("%s is normaal (bv. een dienst die na een update nog met het oude programma draait)", e)
		}
	}
}

func TestIdsUsersGroupsFiles(t *testing.T) {
	b, _ := json.Marshal(idsUsers("root:x:0:0:root:/root:/bin/bash\npiet:x:1000:1000::/home/piet:/bin/bash\nraar naam:x:5:5::/:/bin/sh\n"))
	if string(b) != `[["root",0],["piet",1000]]` {
		t.Fatalf("gebruikers: %s", b)
	}
	b, _ = json.Marshal(idsGroups("root:x:0:\nwheel:x:10:piet,jan\nusers:x:100:piet\ndocker:x:990:piet,raar naam\n"))
	if string(b) != `{"docker":["piet"],"root":[],"wheel":["piet","jan"]}` {
		t.Fatalf("groepen: %s", b)
	}
	d := t.TempDir()
	for _, p := range []string{"etc/sudoers.d", "home/piet/.ssh", "etc/cron.d"} {
		_ = os.MkdirAll(filepath.Join(d, p), 0o700)
	}
	_ = os.WriteFile(filepath.Join(d, "etc/passwd"), []byte("root:x:0:0\n"), 0o600)
	_ = os.WriteFile(filepath.Join(d, "etc/sudoers.d/piet"), []byte("piet ALL=(ALL) ALL\n"), 0o600)
	_ = os.WriteFile(filepath.Join(d, "etc/sudoers.d/.verborgen"), []byte("x\n"), 0o600)
	_ = os.WriteFile(filepath.Join(d, "home/piet/.ssh/authorized_keys"), []byte("ssh-ed25519 AAAA\n"), 0o600)
	_ = os.MkdirAll(filepath.Join(d, "etc/cron.d/map"), 0o700)
	h := idsFileHashes(d)
	if len(h) != 3 || h["/etc/passwd"] == "" || h["/etc/sudoers.d/piet"] == "" || h["/home/piet/.ssh/authorized_keys"] == "" || len(h["/etc/passwd"]) != 64 {
		t.Fatalf("bestanden (een verborgen naam en een map doen niet mee): %v", h)
	}
}
