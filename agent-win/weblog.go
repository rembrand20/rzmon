package main

import (
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// Fase 3: webaanvallen in de logs van IIS, net als de reverse proxy (nginx) op Linux. RzSense krijgt per IP een
// samenvatting ([aantal, 404, 401/403, 0, scanner, [paden met een foutcode]]) en beoordeelt die zelf (aanvalspad, scanner,
// veel 404 of 401/403). Alleen het pad gaat mee, nooit de querystring (daar kunnen wachtwoorden of tokens in staan).

var iisRoot = `C:\inetpub\logs\LogFiles`

var clientIPRe = regexp.MustCompile(`^[0-9a-fA-F:.]{2,45}$`)

var scannerRe = regexp.MustCompile(`(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|dirbuster|gobuster|ffuf|feroxbuster|l9explore|censysinspect)`)

// iisFields: de kolommen van een IIS-logbestand (de laatste regel "#Fields:" bovenin)
func iisFields(file string) map[string]int {
	fh, err := os.Open(file)
	if err != nil {
		return nil
	}
	defer fh.Close()
	buf := make([]byte, 64*1024)
	n, _ := fh.Read(buf)
	var out map[string]int
	for _, l := range strings.Split(string(buf[:n]), "\n") {
		if strings.HasPrefix(l, "#Fields:") {
			out = map[string]int{}
			for i, f := range strings.Fields(strings.TrimPrefix(strings.TrimSpace(l), "#Fields:")) {
				out[strings.ToLower(f)] = i
			}
		}
	}
	return out
}

// iisSummary: per IP de tellingen uit IIS-logregels (W3C-indeling, velden gescheiden door spaties)
func iisSummary(lines []string, fields map[string]int) map[string][]any {
	ci, ok1 := fields["c-ip"]
	st, ok2 := fields["sc-status"]
	if !ok1 || !ok2 {
		return nil
	}
	pi, hasPath := fields["cs-uri-stem"]
	ui, hasUA := fields["cs(user-agent)"]
	type agg struct {
		n, c404, c40x int
		ua            string
		paths         []string
		seen          map[string]bool
	}
	by := map[string]*agg{}
	for _, l := range lines {
		f := strings.Fields(l)
		if ci >= len(f) || st >= len(f) {
			continue
		}
		ip := f[ci]
		if !clientIPRe.MatchString(ip) {
			continue
		}
		a := by[ip]
		if a == nil {
			a = &agg{seen: map[string]bool{}}
			by[ip] = a
		}
		code, _ := strconv.Atoi(f[st])
		a.n++
		switch code {
		case 404:
			a.c404++
		case 401, 403:
			a.c40x++
		}
		if hasUA && ui < len(f) {
			if m := scannerRe.FindString(strings.ToLower(f[ui])); m != "" {
				a.ua = m
			}
		}
		if hasPath && pi < len(f) && code >= 400 && len(a.paths) < 8 && !a.seen[f[pi]] {
			p := f[pi]
			if len(p) > 120 {
				p = p[:120]
			}
			a.seen[f[pi]] = true
			a.paths = append(a.paths, p)
		}
	}
	out := map[string][]any{}
	for ip, a := range by {
		if a.c404 == 0 && a.c40x == 0 && a.ua == "" {
			continue // alleen verdachte adressen
		}
		if len(out) >= 300 {
			break
		}
		paths := a.paths
		if paths == nil {
			paths = []string{}
		}
		out[ip] = []any{a.n, a.c404, a.c40x, 0, a.ua, paths}
	}
	return out
}

// iisWeblog: de nieuwe regels van alle IIS-sites (per site een eigen plek), als samenvatting voor RzSense. De plek
// wordt meteen bewaard: lukt het rapport niet, dan mist RzSense hooguit één minuut (net als bij Linux).
func iisWeblog(path string) map[string]any {
	root := iisRoot
	if path != "" && path != "auto" && winPathRe.MatchString(path) && !strings.Contains(path, "..") {
		root = path
	}
	dirs, _ := filepath.Glob(filepath.Join(root, "W3SVC*"))
	if len(dirs) == 0 {
		return map[string]any{"path": root, "kind": "iis", "err": "geen IIS-logs gevonden", "ips": map[string]any{}}
	}
	ips := map[string][]any{}
	for _, d := range dirs {
		files, _ := filepath.Glob(filepath.Join(d, "*.log"))
		if len(files) == 0 {
			continue
		}
		newest := files[0]
		for _, f := range files {
			a, _ := os.Stat(f)
			b, _ := os.Stat(newest)
			if a != nil && b != nil && a.ModTime().After(b.ModTime()) {
				newest = f
			}
		}
		id := "iis." + strings.ToLower(filepath.Base(d))
		lines, mark, err := readWinFile(id, filepath.Join(d, "*.log"), 8<<20)
		if err != nil {
			continue
		}
		writeState("wf."+id, mark)
		for ip, v := range iisSummary(lines, iisFields(newest)) {
			if old, ok := ips[ip]; ok {
				for i := 0; i < 3; i++ {
					v[i] = v[i].(int) + old[i].(int)
				}
				if v[4] == "" {
					v[4] = old[4]
				}
			}
			ips[ip] = v
		}
	}
	return map[string]any{"path": root, "kind": "iis", "ips": ips}
}
