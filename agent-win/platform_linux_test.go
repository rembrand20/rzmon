//go:build linux

package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestPveConfAndBackups(t *testing.T) {
	d := t.TempDir()
	_ = os.MkdirAll(filepath.Join(d, "qemu-server"), 0o700)
	_ = os.MkdirAll(filepath.Join(d, "lxc"), 0o700)
	_ = os.WriteFile(filepath.Join(d, "qemu-server", "100.conf"), []byte("agent: enabled=1,fstrim_cloned_disks=1\nonboot: 1\nmemory: 2048\n[snap1]\ntemplate: 1\n"), 0o600)
	_ = os.WriteFile(filepath.Join(d, "qemu-server", "101.conf"), []byte("agent: 0\nonboot: 0\ntemplate: 1\n"), 0o600)
	_ = os.WriteFile(filepath.Join(d, "qemu-server", "geen-nummer.conf"), []byte("onboot: 1\n"), 0o600)
	_ = os.WriteFile(filepath.Join(d, "lxc", "200.conf"), []byte("onboot: 1\n"), 0o600)
	b, _ := json.Marshal(pveConfList(d))
	if string(b) != `[["qemu",100,1,1,0],["qemu",101,0,0,1],["lxc",200,1,0,0]]` {
		t.Fatalf("configuratie (alleen het deel vóór de snapshots telt): %s", b)
	}
	for in, want := range map[string]string{"local:backup/vzdump-qemu-100-2026_11_20-02_00_05.vma.zst": "2026-11-20 02:00:05", "pbs:backup/vm/100/2026-11-21T03:00:00Z": "2026-11-21 03:00:00Z", "local:iso/debian.iso": ""} {
		if got := pveBackupDate(in); got != want {
			t.Errorf("datum van %q: %q", in, got)
		}
	}
	bk := pveBackups([][3]string{{"local", "vzdump-qemu-100-2026_11_19-02_00_01.vma", "100"}, {"pbs", "vm/100/2026-11-21T03:00:00Z", "100"}, {"local", "zonder-datum", "100"}, {"local", "vzdump-2026_11_19-02_00_01", "x"}})
	b, _ = json.Marshal(bk)
	if string(b) != `{"100":["2026-11-21 03:00:00Z",2,"pbs"]}` {
		t.Fatalf("back-ups per guest: %s", b)
	}
}

func TestJSONOut(t *testing.T) {
	if string(jsonOut(` [{"a":1}] `)) != `[{"a":1}]` || string(jsonOut(`{"a":`)) != "null" || string(jsonOut("Traceback: fout")) != "null" || string(jsonOut("")) != "null" {
		t.Fatal("alleen geldige JSON hoort ongewijzigd mee te gaan; al het andere wordt null")
	}
}
