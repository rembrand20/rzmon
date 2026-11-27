//go:build linux

package main

// Logs (0.19.0), zoals de bash-agent (agent/src/50-services-commandos-logs.sh, 78-logs-vinden.sh en 92-logs.sh):
//   - log-checks: de nieuwe regels van een logbestand of de journal doorzoeken op een patroon;
//   - logs verzamelen: de nieuwe regels van de gekozen bronnen in pakketjes naar RzSense (versleuteld, met een wachtrij);
//   - logs vinden en een map doorbladeren, om in RzSense een bron te kiezen (alleen namen, groottes en tijden).
//
// De regels voor welke bestanden gelezen mogen worden zijn dezelfde: alleen binnen LOG_DIRS van de server, of in een map
// uit RzSense die binnen LOG_DIRS_MAX valt, en daar alleen bestanden die op een log lijken en geen geheim kunnen zijn
// (sleutels, wachtwoorden, instellingen). Nooit de hele schijf. Originelen worden alleen gelezen, nooit veranderd.
//
// Alleen als de Go-agent het werk doet. Hij gebruikt dezelfde bladwijzers en dezelfde wachtrij als de bash-agent, zodat
// er bij het overnemen en teruggaan geen regel verloren gaat of dubbel telt.

import (
	"bytes"
	"compress/gzip"
	"crypto/md5"
	"crypto/sha1"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/unix"
)

// ---------------------------------------------------------------- welke bestanden mogen

// readlinkF: het echte pad, zoals "readlink -f": het laatste deel hoeft niet te bestaan
func readlinkF(p string) (string, bool) {
	if !filepath.IsAbs(p) {
		a, err := filepath.Abs(p)
		if err != nil {
			return "", false
		}
		p = a
	}
	if r, err := filepath.EvalSymlinks(p); err == nil {
		return r, true
	}
	if d, err := filepath.EvalSymlinks(filepath.Dir(p)); err == nil {
		if _, err := os.Lstat(filepath.Join(d, filepath.Base(p))); err != nil {
			return filepath.Join(d, filepath.Base(p)), true
		}
	}
	return "", false
}

// inDirs: valt het echte pad in (of is het gelijk aan) een van de mappen? Nooit de hele schijf.
func inDirs(real string, dirs []string) bool {
	for _, d := range dirs {
		d, ok := readlinkF(d)
		if !ok || d == "/" {
			continue
		}
		if real == d || strings.HasPrefix(real, d+"/") {
			return true
		}
	}
	return false
}

var secretDirs = []string{"/.ssh/", "/.gnupg/", "/.aws/", "/.kube/", "/.docker/", "/.password-store/", "/.config/", "/.local/share/keyrings/", "/secrets/", "/private/", "/ssl/", "/certs/"}

// logSecret: lijkt het pad naar een geheim (sleutels, wachtwoorden, instellingen)? Dan nooit lezen.
func logSecret(p string) bool {
	whole := "/" + p + "/"
	for _, d := range secretDirs {
		if i := strings.Index(whole, d); i >= 0 {
			return true
		}
	}
	b := strings.ToLower(p[strings.LastIndexByte(p, '/')+1:])
	if strings.HasPrefix(b, ".") || strings.HasPrefix(b, "id_") || b == "config.v2.json" || b == "hostconfig.json" {
		return true
	}
	for _, s := range []string{".key", ".pem", ".crt", ".p12", ".pfx", ".jks", ".kdbx", ".keytab", ".env", "_history", ".history", ".cnf", ".ini", ".conf", ".yaml", ".yml", ".php", ".db"} {
		if strings.HasSuffix(b, s) {
			return true
		}
	}
	for _, s := range []string{".env.", "shadow", "passw", "secret", "token", "cred", "apikey", ".sqlite"} {
		if strings.Contains(b, s) {
			return true
		}
	}
	return false
}

// logLike: lijkt de naam op een log (.log, .out, .err, .txt, of "log" in de naam)?
func logLike(p string) bool {
	b := strings.ToLower(p[strings.LastIndexByte(p, '/')+1:])
	return strings.HasSuffix(b, ".log") || strings.HasSuffix(b, ".out") || strings.HasSuffix(b, ".err") || strings.HasSuffix(b, ".txt") || strings.Contains(b, "log")
}

type logRules struct {
	local   []string    // LOG_DIRS van de server zelf
	max     []string    // LOG_DIRS_MAX: de grens voor mappen uit RzSense
	maxRaw  string      // zoals het in de instellingen staat
	want    string      // de regel "logdirs" uit RzSense
	remote  []string    // mappen uit RzSense die mogen (echte paden)
	okDirs  []string    // dezelfde, zoals RzSense ze noemde
	refused [][2]string // [map, reden]
}

// loadLogRules: LOG_DIRS en LOG_DIRS_MAX uit de instellingen van de agent, en de extra mappen uit RzSense
func loadLogRules(conf map[string]string, tests string) *logRules {
	r := &logRules{}
	ld := conf["LOG_DIRS"]
	if ld == "" {
		ld = "/var/log"
	}
	r.local = strings.Fields(ld)
	r.maxRaw = "/var/log /opt /srv /home /var/lib/docker /data"
	if v, set := conf["LOG_DIRS_MAX"]; set {
		r.maxRaw = v // leeg = uit
	}
	r.max = strings.Fields(r.maxRaw)
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "logdirs" {
			r.want = f[2]
			break
		}
	}
	for _, d := range strings.Fields(r.want) {
		why := ""
		real := ""
		st, err := os.Stat(d)
		switch {
		case !strings.HasPrefix(d, "/") || strings.Contains(d, ".."):
			why = "geen volledig pad"
		case err != nil || !st.IsDir():
			why = "bestaat niet"
		default:
			real, _ = filepath.EvalSymlinks(d)
			switch {
			case real == "":
				why = "bestaat niet"
			case len(r.max) == 0:
				why = "LOG_DIRS_MAX staat uit op de server"
			case !inDirs(real, r.max):
				why = "buiten LOG_DIRS_MAX (" + r.maxRaw + ")"
			case logSecret(real):
				why = "map met geheimen"
			}
		}
		if why != "" {
			r.refused = append(r.refused, [2]string{d, why})
		} else {
			r.remote = append(r.remote, real)
			r.okDirs = append(r.okDirs, d)
		}
	}
	return r
}

// allowed: mag de agent dit bestand lezen?
func (r *logRules) allowed(p string) bool {
	real, ok := readlinkF(p)
	if !ok {
		return false
	}
	if inDirs(real, r.local) {
		return true
	}
	return len(r.remote) > 0 && inDirs(real, r.remote) && logLike(real) && !logSecret(real)
}

// refusedText: de melding voor een bestand dat niet gelezen mag worden
func (r *logRules) refusedText(p string) string {
	real, ok := readlinkF(p)
	if !ok {
		real = p
	}
	if len(r.remote) > 0 && inDirs(real, r.remote) {
		return p + " lijkt geen logbestand of kan geheimen bevatten; in mappen uit RzSense leest de agent alleen logs"
	}
	return p + " valt buiten de toegestane mappen (" + strings.Join(append(append([]string{}, r.local...), r.remote...), " ") + "); voeg de map toe aan LOG_DIRS in /etc/default/rzmon-agent"
}

// ---------------------------------------------------------------- hulpjes

// grepText: tekst door het echte grep (dezelfde patronen als de bash-agent). rc 0 = treffers, 1 = geen, 2 = fout.
func grepText(text string, args ...string) (string, int) {
	cmd := exec.Command("grep", args...)
	cmd.Env = os.Environ()
	cmd.Stdin = strings.NewReader(text)
	var out bytes.Buffer
	cmd.Stdout = &out
	err := cmd.Run()
	rc := 0
	if ee, ok := err.(*exec.ExitError); ok {
		rc = ee.ExitCode()
	} else if err != nil {
		rc = 2
	}
	return out.String(), rc
}

// readNew: de nieuwe volledige regels van een bestand sinds de bladwijzer "inode|positie" (de eerste keer: vanaf het
// einde; een nieuw of gedraaid bestand: vanaf het begin). Hooguit max bytes per keer.
func readNew(file, prev string, max int64) (text, state string, err error) {
	fh, err := os.Open(file)
	if err != nil {
		return "", prev, err
	}
	defer fh.Close()
	st, err := fh.Stat()
	if err != nil {
		return "", prev, err
	}
	inode := uint64(0)
	if sys, ok := st.Sys().(*syscall.Stat_t); ok {
		inode = sys.Ino
	}
	size := st.Size()
	pInode, pPos := prev, prev
	if i := strings.Index(prev, "|"); i >= 0 {
		pInode = prev[:i]
	}
	if i := strings.LastIndex(prev, "|"); i >= 0 {
		pPos = prev[i+1:]
	}
	var pos int64
	switch {
	case prev == "":
		pos = size
	case pInode != strconv.FormatUint(inode, 10) || !reDigits.MatchString(pPos):
		pos = 0
	default:
		if pos, _ = strconv.ParseInt(pPos, 10, 64); pos > size {
			pos = 0
		}
	}
	buf := make([]byte, min(max, size-pos))
	n, _ := fh.ReadAt(buf, pos)
	buf = buf[:n]
	if i := bytes.LastIndexByte(buf, '\n'); i >= 0 {
		buf = buf[:i+1]
	} else {
		buf = nil
	}
	return string(buf), fmt.Sprintf("%d|%d", inode, pos+int64(len(buf))), nil
}

func firstLines(s string, n int) string {
	idx := 0
	for i := 0; i < n; i++ {
		j := strings.IndexByte(s[idx:], '\n')
		if j < 0 {
			return s
		}
		idx += j + 1
	}
	return s[:idx]
}

func stripCtl(s string, keep string) string {
	return strings.Map(func(r rune) rune {
		if (r < 0x20 && !strings.ContainsRune(keep, r)) || r == 0x7f {
			return -1
		}
		return r
	}, s)
}

// sharedDir: de map met bladwijzers van de bash-agent (dan gaat hij bij teruggaan verder waar de Go-agent was); kan de
// Go-agent daar niet schrijven, dan zijn eigen map
func sharedDir(name string) string {
	base := filepath.Dir(authStatePath())
	if p := os.Getenv("RZSENSE_BASH_STATE"); p != "" { // alleen voor de tests
		base = p
	}
	d := filepath.Join(base, name)
	if os.MkdirAll(d, 0o700) == nil && unix.Access(d, unix.W_OK) == nil {
		return d
	}
	d = filepath.Join(dataDir(), name)
	_ = os.MkdirAll(d, 0o700)
	return d
}

// ---------------------------------------------------------------- log-checks

var ipv4Re = regexp.MustCompile(`([0-9]{1,3}\.){3}[0-9]{1,3}`)

// topIPsLinux: de 3 adressen die het vaakst voorkomen (bij gelijke stand het "grootste" adres eerst, zoals sort -rn)
func topIPsLinux(text string) [][]any {
	n := map[string]int{}
	for _, ip := range ipv4Re.FindAllString(text, -1) {
		n[ip]++
	}
	keys := make([]string, 0, len(n))
	for k := range n {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		if n[keys[i]] != n[keys[j]] {
			return n[keys[i]] > n[keys[j]]
		}
		return keys[i] > keys[j]
	})
	out := [][]any{}
	for i := 0; i < len(keys) && i < 3; i++ {
		out = append(out, []any{keys[i], n[keys[i]]})
	}
	return out
}

// collectLogChecksLinux: het onderdeel "logs" van het rapport: per log-check het aantal nieuwe treffers
func collectLogChecksLinux() map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !regexp.MustCompile(`(?m)^[0-9]*\tlog\t`).MatchString(tests) {
		return nil
	}
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	rules := loadLogRules(conf, tests)
	ival := map[string]int{}
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[1] == "ival" && reDigits.MatchString(f[0]) && reDigits.MatchString(f[2]) {
			ival[f[0]] = atoiDef(f[2], 0)
		}
	}
	dir := sharedDir("logstate")
	now := time.Now().Unix()
	res := map[string]any{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.SplitN(l, "\t", 4)
		if len(f) < 3 || f[1] != "log" || !reDigits.MatchString(f[0]) {
			continue
		}
		id, src, pat := f[0], f[2], ""
		if len(f) == 4 {
			pat = f[3]
		}
		stFile := filepath.Join(dir, id)
		// Nog niet aan de beurt: "overgeslagen" melden; de volgende keer tellen alle nieuwe regels sinds de vorige keer
		if iv, ok := ival[id]; ok {
			if st, err := os.Stat(stFile); err == nil && st.Size() > 0 && time.Since(st.ModTime()) < time.Duration(iv-5)*time.Second {
				res[id] = map[string]any{"skip": 1}
				continue
			}
		}
		prev := strings.TrimSpace(readText(stFile))
		text, errTxt := "", ""
		switch {
		case src == "journal" || strings.HasPrefix(src, "journal:") || src == "kernel":
			since := now - 60
			if i := strings.LastIndex(prev, "|"); i >= 0 && reDigits.MatchString(prev[i+1:]) {
				since, _ = strconv.ParseInt(prev[i+1:], 10, 64)
			} else if reDigits.MatchString(prev) {
				since, _ = strconv.ParseInt(prev, 10, 64)
			}
			var opt []string
			if src == "kernel" {
				opt = []string{"-k"}
			} else if u, ok := strings.CutPrefix(src, "journal:"); ok {
				opt = []string{"-u", u}
			}
			if haveCmd("journalctl") {
				out, _ := run(60*time.Second, "journalctl", append(opt, "--since", fmt.Sprintf("@%d", since), "--until", fmt.Sprintf("@%d", now-1), "-q", "--no-pager", "-o", "short-iso")...)
				text = firstLines(out, 20000)
			} else {
				errTxt = "journalctl ontbreekt op deze server"
			}
			_ = os.WriteFile(stFile, []byte(fmt.Sprintf("journal|%d\n", now)), 0o600)
		case strings.HasPrefix(src, "/"):
			if !rules.allowed(src) {
				errTxt = rules.refusedText(src)
			} else if unix.Access(src, unix.R_OK) != nil {
				errTxt = src + " bestaat niet of is niet leesbaar"
			} else if t, state, err := readNew(src, prev, 5000000); err != nil {
				errTxt = src + " bestaat niet of is niet leesbaar"
			} else {
				text = t
				_ = os.WriteFile(stFile, []byte(state+"\n"), 0o600)
			}
		case strings.HasPrefix(src, "ha:"):
			errTxt = src + " kan alleen in de Home Assistant-add-on"
			_ = os.Chtimes(stFile, time.Now(), time.Now())
		default:
			errTxt = "onbekende bron: " + src
		}
		text = strings.TrimRight(text, "\n")
		hits := 0
		lines := []string{}
		ips := [][]any{}
		if errTxt == "" && text != "" {
			m, rc := grepText(text+"\n", "-aiE", "--", pat)
			m = strings.TrimRight(m, "\n")
			if rc == 2 {
				errTxt = "ongeldig zoekpatroon: " + pat
			} else if m != "" {
				all := strings.Split(m, "\n")
				for _, x := range all {
					if x != "" {
						hits++
					}
				}
				for _, x := range all[max(0, len(all)-5):] {
					lines = append(lines, cleanText(cutRunes(stripCtl(x, "\t"), 300)))
				}
				ips = topIPsLinux(m)
			}
		}
		res[id] = map[string]any{"new": hits, "lines": lines, "ips": ips, "error": cleanText(errTxt)}
	}
	return res
}

// ---------------------------------------------------------------- logs verzamelen en versturen

func logshipDir(c *Config) string {
	if k := c.extra["key_dir"]; k != "" {
		return filepath.Join(k, "logship")
	}
	return ""
}

// levelFilterText: alleen de regels van dit niveau en erger (op de tekst), met het echte grep
func levelFilterText(text string, lvl int) string {
	var out string
	switch lvl {
	case 1:
		out, _ = grepText(text, "-viE", `(^|[^[:alnum:]])(debug|trace)([^[:alnum:]]|$)`)
	case 2:
		out, _ = grepText(text, "-iE", `warn|err|crit|fatal|alert|emerg|panic|fail|denied|refused`)
	case 3:
		out, _ = grepText(text, "-iE", `err|crit|fatal|alert|emerg|panic|fail`)
	default:
		return text
	}
	return out
}

var ctrNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)

// shipLogsLinux: de nieuwe regels van elke bron in het spoolbestand, en als het tijd is een pakketje naar RzSense
func shipLogsLinux(c *Config) {
	dir := logshipDir(c)
	if dir == "" {
		return
	}
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	t := parseTests(tests)
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if len(t.LogSrc) == 0 || conf["LOG_SHIP"] == "0" {
		if _, err := os.Stat(dir); err == nil {
			_ = os.RemoveAll(dir) // geen bronnen meer (of uitgezet): de wachtrij en de bladwijzers opruimen, zoals de bash-agent
		}
		return
	}
	if os.MkdirAll(filepath.Join(dir, "state"), 0o700) != nil {
		return
	}
	rules := loadLogRules(conf, tests)
	now := time.Now().Unix()
	every := int64(300)
	if strings.Contains(tests, "\tlogship\t") && t.LogShip >= 10 {
		every = int64(t.LogShip)
	}
	pause := t.LogPause
	cur := filepath.Join(dir, "cur")
	since := func(stFile string) int64 { // vanaf wanneer lezen (de eerste keer: nu, dus geen oude regels)
		p := strings.TrimSpace(readText(stFile))
		if v, err := strconv.ParseInt(p, 10, 64); err == nil && reDigits.MatchString(p) && v <= now && v > now-86400 {
			return v
		}
		return now
	}
	var status, spool strings.Builder
	add := func(id, text string) {
		for _, l := range strings.Split(text, "\n") {
			l = strings.TrimSuffix(strings.Map(func(r rune) rune {
				if r < 0x20 && r != '\t' && r != '\r' {
					return -1
				}
				return r
			}, strings.ToValidUTF8(l, "")), "\r")
			if l != "" {
				fmt.Fprintf(&spool, "L\t%s\t%d\t%s\n", id, now, cutRunes(l, 8000))
			}
		}
	}
	for _, s := range t.LogSrc {
		if !reDigits.MatchString(s.ID) || now < pause {
			continue
		}
		lvl := s.Level
		if lvl < 0 || lvl > 3 {
			lvl = 0
		}
		spec := s.Spec
		errTxt, files, text := "", "", ""
		st := filepath.Join(dir, "state", s.ID)
		switch s.Kind {
		case "file":
			n := 0
			matches, _ := filepath.Glob(spec)
			if len(matches) > 20 {
				matches = matches[:20]
			}
			for _, f := range matches {
				fi, err := os.Stat(f)
				if err != nil || !fi.Mode().IsRegular() || unix.Access(f, unix.R_OK) != nil {
					continue
				}
				if strings.HasSuffix(f, ".gz") || strings.HasSuffix(f, ".xz") || strings.HasSuffix(f, ".bz2") || strings.HasSuffix(f, ".zst") {
					continue
				}
				if !rules.allowed(f) {
					errTxt = rules.refusedText(f)
					continue
				}
				n++
				sum := md5.Sum([]byte(f))
				fst := st + "." + hex.EncodeToString(sum[:])[:16]
				// Hooguit 2 MB per bestand per keer, en alleen volledige regels
				chunk, state, err := readNew(f, strings.TrimSpace(readText(fst)), 2000000)
				if err != nil {
					continue
				}
				if chunk != "" {
					text += levelFilterText(chunk, lvl)
				}
				_ = os.WriteFile(fst, []byte(state+"\n"), 0o600)
			}
			files = strconv.Itoa(n)
			if n == 0 && errTxt == "" {
				errTxt = "geen leesbaar bestand gevonden voor " + spec
			}
		case "journal", "unit":
			if !haveCmd("journalctl") {
				errTxt = "journalctl ontbreekt op deze server"
				break
			}
			from := since(st)
			opt := []string{"-q", "--no-pager", "-o", "short-iso"}
			if s.Kind == "unit" {
				opt = append(opt, "-u", spec)
			}
			if p := map[int]string{1: "info", 2: "warning", 3: "err"}[lvl]; p != "" {
				opt = append(opt, "-p", p)
			}
			if from < now {
				out, _ := run(90*time.Second, "journalctl", append(opt, "--since", fmt.Sprintf("@%d", from), "--until", fmt.Sprintf("@%d", now-1))...)
				text = firstLines(out, 50000)
			}
			_ = os.WriteFile(st, []byte(fmt.Sprintf("%d\n", now)), 0o600)
		case "docker":
			dk := ""
			for _, n := range []string{"docker", "podman"} {
				if haveCmd(n) {
					dk = n
					break
				}
			}
			switch {
			case dk == "":
				errTxt = "docker en podman ontbreken op deze server"
			case !ctrNameRe.MatchString(spec):
				errTxt = "ongeldige containernaam"
			default:
				if from := since(st); from < now {
					out, code := run(90*time.Second, dk, "logs", "--timestamps", "--since", fmt.Sprint(from), "--until", fmt.Sprint(now), spec)
					if code != 0 {
						first, _, _ := strings.Cut(out, "\n")
						errTxt = dk + " logs " + spec + ": " + cutRunes(first, 200)
					} else {
						text = levelFilterText(firstLines(out, 50000), lvl)
					}
				}
				if errTxt == "" {
					_ = os.WriteFile(st, []byte(fmt.Sprintf("%d\n", now)), 0o600)
				}
			}
		case "ha":
			errTxt = "Home Assistant-logs kunnen alleen in de Home Assistant-add-on"
		default:
			errTxt = "onbekende soort bron: " + s.Kind
		}
		add(s.ID, text)
		ok := "ok"
		if errTxt != "" {
			ok = "err"
		}
		fmt.Fprintf(&status, "S\t%s\t%s\t%s\t%s\n", s.ID, ok, files, cutRunes(errTxt, 280))
	}
	if spool.Len() > 0 {
		if fh, err := os.OpenFile(cur, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
			_, _ = fh.WriteString(spool.String())
			fh.Close()
		}
	}
	statusFile := filepath.Join(dir, "status")
	if now >= pause {
		_ = os.WriteFile(statusFile, []byte(status.String()), 0o600)
	}
	// Pakketje maken: als het tijd is (en er iets nieuws is), of als het spoolbestand al groot is
	last, _ := strconv.ParseInt(strings.TrimSpace(readText(filepath.Join(dir, "last"))), 10, 64)
	curSt, curErr := os.Stat(cur)
	curSize := int64(0)
	if curErr == nil {
		curSize = curSt.Size()
	}
	statusNow, _ := os.ReadFile(statusFile)
	statusSent, sentErr := os.ReadFile(filepath.Join(dir, "status.sent"))
	if (curSize > 0 || sentErr != nil || !bytes.Equal(statusNow, statusSent)) && (now-last >= every || curSize > 8000000) {
		id := fmt.Sprintf("b%d%s", now, randHex(3))
		dropped := strings.TrimSpace(readText(filepath.Join(dir, "dropped")))
		if !reDigits.MatchString(dropped) {
			dropped = "0"
		}
		var gz bytes.Buffer
		zw, _ := gzip.NewWriterLevel(&gz, 6)
		fmt.Fprintf(zw, "rzlogs1\t%s\t%d\t%s\t%s\n", id, now, dropped, time.Now().Format("-0700"))
		_, _ = zw.Write(statusNow)
		if fh, err := os.Open(cur); err == nil {
			_, _ = io.Copy(zw, fh)
			fh.Close()
		}
		if zw.Close() == nil && os.WriteFile(filepath.Join(dir, id+".gz"), gz.Bytes(), 0o600) == nil {
			_ = os.Remove(cur)
			_ = os.Remove(filepath.Join(dir, "dropped"))
			_ = os.WriteFile(filepath.Join(dir, "status.sent"), statusNow, 0o600)
			_ = os.WriteFile(filepath.Join(dir, "last"), []byte(fmt.Sprintf("%d\n", now)), 0o600)
		}
	}
	// Wachtrij hooguit 50 MB: daarboven de oudste pakketjes weg (en tellen)
	batches, _ := filepath.Glob(filepath.Join(dir, "b*.gz"))
	sort.Strings(batches)
	total := int64(0)
	sizes := map[string]int64{}
	for _, b := range batches {
		if st, err := os.Stat(b); err == nil {
			sizes[b] = st.Size()
			total += st.Size()
		}
	}
	for total > 52428800 && len(batches) > 1 {
		d, _ := strconv.ParseInt(strings.TrimSpace(readText(filepath.Join(dir, "dropped"))), 10, 64)
		_ = os.Remove(batches[0])
		_ = os.WriteFile(filepath.Join(dir, "dropped"), []byte(fmt.Sprintf("%d\n", d+sizes[batches[0]])), 0o600)
		total -= sizes[batches[0]]
		batches = batches[1:]
	}
	// Versturen: versleuteld, hooguit 5 per ronde, oudste eerst. Pas als RzSense het pakketje bevestigt, gaat het weg.
	for i, b := range batches {
		if i >= 5 {
			break
		}
		body, err := os.ReadFile(b)
		if err != nil {
			continue
		}
		out, code, err := sealedCall(c, "agent_logs", body, map[string]string{"Content-Type": "application/octet-stream"}, 60*time.Second)
		if err != nil || code != 200 {
			break
		}
		name := strings.TrimSuffix(filepath.Base(b), ".gz")
		if bytes.HasPrefix(out, []byte(`{"rzpull":1`)) || (bytes.Contains(out, []byte(`"batch":"`+name+`"`)) && bytes.Contains(out, []byte(`"ok":true`))) {
			_ = os.Remove(b)
		} else {
			break
		}
	}
}

// ---------------------------------------------------------------- logs vinden en een map doorbladeren

var (
	rotatedRe = regexp.MustCompile(`(\.(gz|xz|bz2|zst|zip|old|[0-9])|\.[0-9]\.log|-[0-9]{8}|-[0-9]{8}\.log)$`)
	binLogRe  = regexp.MustCompile(`^(wtmp.*|btmp.*|lastlog|faillog|.*\.journal|.*\.journal~|.*\.pid|.*\.lock|.*\.sock|.*\.db|.*\.sqlite.*)$`)
)

func devOf(p string) uint64 {
	var st syscall.Stat_t
	if syscall.Lstat(p, &st) != nil {
		return 0
	}
	return uint64(st.Dev)
}

// walkLimited: bestanden en mappen onder root tot maxDepth diep, zonder een ander bestandssysteem in te gaan (-xdev) en
// zonder symlinks te volgen. skipDir bepaalt welke mappen worden overgeslagen.
func walkLimited(root string, maxDepth int, skipDir func(name string) bool, visit func(path string, isDir bool, depth int) bool) {
	dev := devOf(root)
	var rec func(dir string, depth int) bool
	rec = func(dir string, depth int) bool {
		ents, err := os.ReadDir(dir)
		if err != nil {
			return true
		}
		for _, e := range ents {
			p := filepath.Join(dir, e.Name())
			isDir := e.IsDir()
			if isDir && skipDir != nil && skipDir(e.Name()) {
				continue
			}
			if !visit(p, isDir, depth) {
				return false
			}
			if isDir && depth < maxDepth && devOf(p) == dev {
				if !rec(p, depth+1) {
					return false
				}
			}
		}
		return true
	}
	rec(root, 1)
}

func readable(p string) bool { return unix.Access(p, unix.R_OK) == nil }

// logScanLinux: welke logs er op deze server zijn (alleen namen, groottes en tijden, nooit de inhoud). Eén keer per dag,
// of meteen als RzSense erom vraagt. nil = niets nieuws te melden.
func logScanLinux(force bool) map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if conf["LOG_SHIP"] == "0" {
		return nil
	}
	rules := loadLogRules(conf, tests)
	req := parseTests(tests).LogScan
	now := time.Now().Unix()
	sum := sha1.Sum([]byte(fmt.Sprintf("%s|%d|%s|%s", strings.Join(rules.local, " "), req, rules.maxRaw, rules.want)))
	key := hex.EncodeToString(sum[:])[:16]
	cache := strings.Split(readState("logscan.cache"), "\n")
	if !force && len(cache) >= 2 && cache[0] == key && now-int64(atoiDef(cache[1], 0)) < 86400 {
		return nil
	}
	files := [][]any{}
	noread := []string{}
	seen := map[string]bool{}
	all := append(append([]string{}, rules.local...), rules.remote...)
	for _, d := range all {
		st, err := os.Stat(d)
		if !strings.HasPrefix(d, "/") || strings.Contains(d, "..") || err != nil || !st.IsDir() || len(files) >= 500 {
			continue
		}
		// Mappen zonder leesrechten (tot 2 diep): wel noemen, zodat je weet waarom ze ontbreken
		n := 0
		walkLimited(d, 2, func(name string) bool { return name == "journal" }, func(p string, isDir bool, _ int) bool {
			if !isDir {
				return true
			}
			if n++; n > 200 {
				return false
			}
			if unix.Access(p, unix.R_OK|unix.X_OK) != nil {
				noread = append(noread, p)
			}
			return true
		})
		walkLimited(d, 3, func(name string) bool { return name == "journal" || name == "private" }, func(p string, isDir bool, _ int) bool {
			if isDir {
				return true
			}
			fi, err := os.Lstat(p)
			base := filepath.Base(p)
			if err != nil || !fi.Mode().IsRegular() || rotatedRe.MatchString(base) || binLogRe.MatchString(base) || !readable(p) || !rules.allowed(p) || seen[p] {
				return true
			}
			seen[p] = true
			if fi.Size() > 0 { // binair? (NUL-tekens in het begin)
				if fh, err := os.Open(p); err == nil {
					buf := make([]byte, 2048)
					k, _ := fh.Read(buf)
					fh.Close()
					if bytes.IndexByte(buf[:k], 0) >= 0 {
						return true
					}
				}
			}
			files = append(files, []any{p, fi.Size(), fi.ModTime().Unix()})
			return len(files) < 500
		})
	}
	docker := [][]any{}
	for _, c := range []string{"docker", "podman"} {
		if !haveCmd(c) {
			continue
		}
		out, _ := run(15*time.Second, c, "ps", "-a", "--format", "{{.Names}}\t{{.Image}}\t{{.State}}")
		for i, l := range strings.Split(out, "\n") {
			f := strings.Split(l, "\t")
			if i >= 150 || len(f) < 3 || !ctrNameRe.MatchString(f[0]) {
				continue
			}
			docker = append(docker, []any{f[0], cleanText(cutRunes(f[1], 120)), cleanText(cutRunes(f[2], 40)), c})
		}
		if len(docker) > 0 {
			break
		}
	}
	units := [][]any{}
	if haveCmd("journalctl") {
		out, _ := run(25*time.Second, "journalctl", "-q", "--no-pager", "--since", "-24h", "-o", "export", "--output-fields=_SYSTEMD_UNIT")
		cnt := map[string]int{}
		for i, l := range strings.Split(out, "\n") {
			if i >= 400000 {
				break
			}
			if u, ok := strings.CutPrefix(l, "_SYSTEMD_UNIT="); ok && unitNameRe.MatchString(u) {
				cnt[u]++
			}
		}
		names := make([]string, 0, len(cnt))
		for u := range cnt {
			names = append(names, u)
		}
		sort.Strings(names)
		for i, u := range names {
			if i >= 150 {
				break
			}
			units = append(units, []any{u, cnt[u]})
		}
	}
	refused := [][]string{}
	for _, r := range rules.refused {
		refused = append(refused, []string{r[0], r[1]})
	}
	writeState("logscan.cache", fmt.Sprintf("%s\n%d", key, now))
	return map[string]any{"at": now, "req": req, "dirs": strings.Join(all, " "), "files": files, "noread": noread, "docker": docker, "units": units, "ha": [][]any{},
		"local": strings.Join(rules.local, " "), "max": rules.maxRaw, "refused": refused, "ok": strings.Join(rules.okDirs, " ")}
}

// logBrowseLinux: de inhoud van één map die je in RzSense doorbladert (regel "0 <tab> logbrowse <tab> teller <tab> pad").
// Alleen binnen LOG_DIRS of LOG_DIRS_MAX, alleen namen, groottes en tijden, geen verborgen namen. nil = niets gevraagd.
func logBrowseLinux() map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if conf["LOG_SHIP"] == "0" {
		return nil
	}
	req, path := "", ""
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 4 && f[0] == "0" && f[1] == "logbrowse" {
			req, path = f[2], f[3]
			break
		}
	}
	if !reDigits.MatchString(req) || req == strings.TrimSpace(readState("logbrowse.done")) {
		return nil
	}
	rules := loadLogRules(conf, tests)
	errTxt, real := "", ""
	dirs := [][]any{}
	files := [][]any{}
	st, statErr := os.Stat(path)
	switch {
	case !strings.HasPrefix(path, "/") || strings.Contains(path, ".."):
		errTxt = "geen volledig pad"
	case statErr != nil || !st.IsDir():
		errTxt = "bestaat niet"
	default:
		real, _ = filepath.EvalSymlinks(path)
		switch {
		case real == "":
			errTxt = "bestaat niet"
		case !inDirs(real, rules.local) && !inDirs(real, rules.max):
			errTxt = "buiten LOG_DIRS en LOG_DIRS_MAX"
		case logSecret(real + "/"):
			errTxt = "map met geheimen"
		case unix.Access(real, unix.R_OK|unix.X_OK) != nil:
			errTxt = "geen leesrechten"
		}
	}
	if errTxt == "" {
		ents, _ := os.ReadDir(real)
		for i, e := range ents {
			if i >= 400 {
				break
			}
			name := e.Name()
			if strings.HasPrefix(name, ".") {
				continue
			}
			x := filepath.Join(real, name)
			fi, err := os.Stat(x) // volgt een symlink, zoals [ -d ] en [ -f ]
			if err != nil {
				continue
			}
			if fi.IsDir() {
				n, ok := 0, 1
				if logSecret(x+"/") || unix.Access(x, unix.R_OK|unix.X_OK) != nil {
					ok = 0
				} else {
					m := 0
					walkLimited(x, 2, nil, func(p string, isDir bool, _ int) bool {
						if isDir {
							return true
						}
						if fi, err := os.Lstat(p); err != nil || !fi.Mode().IsRegular() {
							return true
						}
						if m++; m > 500 {
							return false
						}
						if logLike(p) && !logSecret(p) {
							n++
						}
						return true
					})
				}
				dirs = append(dirs, []any{cleanText(name), n, ok})
			} else if fi.Mode().IsRegular() {
				// 1 = de agent leest het, 0 = geen log of een geheim, 2 = geen leesrechten
				ok := 0
				if inDirs(real, rules.local) || (logLike(x) && !logSecret(x)) {
					ok = 1
				}
				if ok == 1 && !readable(x) {
					ok = 2
				}
				files = append(files, []any{cleanText(name), fi.Size(), fi.ModTime().Unix(), ok})
			}
		}
	}
	writeState("logbrowse.done", req)
	return map[string]any{"req": atoiDef(req, 0), "path": cleanText(path), "real": cleanText(real), "err": errTxt, "dirs": dirs, "files": files}
}
