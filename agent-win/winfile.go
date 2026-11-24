package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

// Fase 3: logbestanden op Windows (bron "winfile"), bv. IIS (C:\inetpub\logs\LogFiles\W3SVC1\*.log) en de Windows
// Firewall (pfirewall.log). Per bestand een plek (offset); de eerste keer vanaf het eind, een nieuw bestand (de
// volgende dag) vanaf het begin. Alleen volledige regels; regels die met # beginnen (kopjes van IIS) worden overgeslagen.

var winPathRe = regexp.MustCompile(`^[A-Za-z]:\\[^\x00-\x1f"<>|]{1,290}$`)

// knownWinFiles: logbestanden die de agent aanbiedt (als ze bestaan): per map één bron
var knownWinFiles = []string{
	`C:\inetpub\logs\LogFiles\*\*.log`,
	`C:\Windows\System32\LogFiles\Firewall\pfirewall.log`,
	`C:\Windows\System32\LogFiles\SMTPSVC1\*.log`,
	`C:\Windows\System32\dns\dns.log`,
}

// winFileScan: [patroon, grootte, laatst gewijzigd] per map met logbestanden
func winFileScan() [][]any {
	out := [][]any{}
	for _, pat := range knownWinFiles {
		files, _ := filepath.Glob(pat)
		byDir := map[string][]os.FileInfo{}
		for _, f := range files {
			if st, err := os.Stat(f); err == nil && !st.IsDir() {
				byDir[filepath.Dir(f)] = append(byDir[filepath.Dir(f)], st)
			}
		}
		for dir, list := range byDir {
			spec := filepath.Join(dir, filepath.Base(pat))
			var size int64
			var mt int64
			for _, st := range list {
				size += st.Size()
				if st.ModTime().Unix() > mt {
					mt = st.ModTime().Unix()
				}
			}
			out = append(out, []any{spec, size, mt})
		}
	}
	return out
}

// readWinFile: nieuwe regels uit de bestanden van een patroon. Geeft de regels en de nieuwe plekken (pas bewaren als
// het versturen gelukt is).
func readWinFile(id, pattern string, maxBytes int64) ([]string, string, error) {
	files, err := filepath.Glob(pattern)
	if err != nil {
		return nil, "", err
	}
	type fi struct {
		p  string
		st os.FileInfo
	}
	var list []fi
	for _, f := range files {
		if st, err := os.Stat(f); err == nil && !st.IsDir() {
			list = append(list, fi{f, st})
		}
	}
	sort.Slice(list, func(i, j int) bool { return list[i].st.ModTime().Before(list[j].st.ModTime()) })
	if len(list) > 3 {
		list = list[len(list)-3:] // alleen de nieuwste drie (bv. gisteren en vandaag)
	}
	var marks map[string]int64
	first := json.Unmarshal([]byte(readState("wf."+id)), &marks) != nil
	if marks == nil {
		marks = map[string]int64{}
	}
	newMarks := map[string]int64{}
	var lines []string
	var budget = maxBytes
	for _, f := range list {
		size := f.st.Size()
		off, known := marks[f.p]
		if first {
			newMarks[f.p] = size // de eerste keer: vanaf nu
			continue
		}
		if !known || off > size {
			off = 0 // nieuw bestand, of opnieuw begonnen
		}
		if off == size || budget <= 0 {
			newMarks[f.p] = off
			continue
		}
		fh, err := os.Open(f.p)
		if err != nil {
			return nil, "", err
		}
		_, _ = fh.Seek(off, io.SeekStart)
		data, _ := io.ReadAll(io.LimitReader(fh, budget))
		fh.Close()
		end := bytes.LastIndexByte(data, '\n')
		if end < 0 {
			newMarks[f.p] = off
			continue
		}
		data = data[:end+1]
		budget -= int64(len(data))
		newMarks[f.p] = off + int64(len(data))
		sc := bufio.NewScanner(bytes.NewReader(data))
		sc.Buffer(make([]byte, 64*1024), 1<<20)
		for sc.Scan() {
			l := strings.TrimRight(sc.Text(), "\r")
			if l == "" || strings.HasPrefix(l, "#") {
				continue
			}
			lines = append(lines, oneLine(l))
		}
	}
	b, _ := json.Marshal(newMarks)
	return lines, string(b), nil
}
