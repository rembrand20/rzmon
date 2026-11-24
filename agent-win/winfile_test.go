package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestReadWinFile(t *testing.T) {
	t.Setenv("RZSENSE_DATA", t.TempDir())
	dir := t.TempDir()
	f := filepath.Join(dir, "u_ex261124.log")
	os.WriteFile(f, []byte("#Fields: date time\n2026-11-24 10:00:00 oud\n"), 0o600)
	pat := filepath.Join(dir, "*.log")
	lines, mark, err := readWinFile("7", pat, 1<<20)
	if err != nil || len(lines) != 0 {
		t.Fatalf("eerste keer moet vanaf het eind beginnen: %v %v", lines, err)
	}
	writeState("wf.7", mark)
	fh, _ := os.OpenFile(f, os.O_APPEND|os.O_WRONLY, 0o600)
	fh.WriteString("#Software: IIS\n2026-11-24 10:01:00 nieuw\n2026-11-24 10:02:00 half")
	fh.Close()
	lines, mark, _ = readWinFile("7", pat, 1<<20)
	if strings.Join(lines, "|") != "2026-11-24 10:01:00 nieuw" {
		t.Fatalf("nieuwe regels: %v", lines)
	}
	writeState("wf.7", mark)
	// Een nieuw bestand (de volgende dag) wordt vanaf het begin gelezen; de halve regel komt pas als hij af is
	os.WriteFile(filepath.Join(dir, "u_ex261125.log"), []byte("2026-11-25 00:00:01 morgen\n"), 0o600)
	fh, _ = os.OpenFile(f, os.O_APPEND|os.O_WRONLY, 0o600)
	fh.WriteString(" af\n")
	fh.Close()
	lines, _, _ = readWinFile("7", pat, 1<<20)
	got := strings.Join(lines, "|")
	if !strings.Contains(got, "2026-11-24 10:02:00 half af") || !strings.Contains(got, "2026-11-25 00:00:01 morgen") {
		t.Fatalf("volgende dag en afgemaakte regel: %v", lines)
	}
}
