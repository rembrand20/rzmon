//go:build linux

package main

// Ransomware herkennen (0.31.0), zoals de bash-agent (agent/src/62-ransomware.sh). Aan per server in RzSense, met de
// mappen die je kiest (regels "0 <tab> ransompath <tab> pad (base64) <tab> drempel"). Elke ronde, per map:
//  1. lokaasbestanden: twee bestanden die niemand hoort aan te raken. Gewijzigd, hernoemd of weg = meteen alarm;
//  2. hoeveel bestanden er sinds de vorige ronde veranderden of hernoemd werden (hooguit 15 seconden zoeken);
//  3. kenmerken: bekende ransomware-extensies, losgeldbriefjes, en tekstbestanden die niet meer te comprimeren zijn.
// Bij een sterk signaal, als je dat aanzette: een snapshot (ZFS of btrfs) en/of de schrijvende processen stoppen.
// De stand (welke lokaasbestanden er liggen, met hun vingerafdruk) staat in de map die de bash-agent ook gebruikt, zodat
// overnemen en teruggaan geen vals alarm geven. In de proef raakt de Go-agent hier niets van aan.

import (
	"bytes"
	"compress/gzip"
	"crypto/rand"
	"crypto/sha1"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

var (
	rsExtRe  = regexp.MustCompile(`(?i)\.(locked|encrypted|crypted|crypt|enc[0-9]*|lockbit|conti|ryuk|akira|blackcat|alphv|phobos|djvu|cerber|wncry|wnry|wncryt|locky|zepto|odin|petya|sodinokibi|revil|maze|egregor|clop|cl0p|avaddon|babuk|hive|royal|rhysida|medusa|8base|basta|bianlian|ransom|hellokitty|play|lock|pay2key|nemty|makop|mallox|stop)$`)
	rsNoteRe = regexp.MustCompile(`(how_to_decrypt|how_to_recover|how_to_restore|readme_to_restore|restore_files|restore-my-files|decrypt_instructions|decrypt-files|recover-files|read_me_now|!!!read|_readme\.txt|readme-warning|ransom_note|files_encrypted|info\.hta|decryption_instructions)`)
	rsTextRe = regexp.MustCompile(`(?i)\.(txt|csv|log|md|html?|xml|json|sql|conf|ini|cfg|php|py|js|sh|ya?ml|svg|tex|rtf)$`)
	rsPathRe = regexp.MustCompile(`"path": *"(/[^"]*)"`)
	rsIPRe   = regexp.MustCompile(`^(ipv4:)?[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(:[0-9]+)?$`)
)

const (
	rsC1 = "!0-RzSense-lokaas-niet-aanraken.txt"
	rsC2 = "zz-RzSense-lokaas-niet-aanraken.txt"
)

func ransomDir() string { return sharedDir("ransom") }

// rsCandidates: mappen om uit te kiezen: Samba-shares, NFS-exports, de shares van TrueNAS en /home (hooguit 50)
func rsCandidates() [][2]string {
	out := [][2]string{}
	seen := map[string]bool{}
	add := func(kind, p string) {
		if strings.HasPrefix(p, "/") && !strings.Contains(p, "..") && !seen[p] && len(out) < 50 {
			seen[p] = true
			out = append(out, [2]string{kind, p})
		}
	}
	if haveCmd("testparm") {
		txt, _ := stdoutOf(10*time.Second, "testparm", "-s")
		for _, l := range strings.Split(txt, "\n") {
			f := strings.Split(l, "=")
			if len(f) >= 2 && strings.ToLower(strings.Trim(f[0], " \t")) == "path" {
				add("smb", strings.Trim(f[1], " \t"))
			}
		}
	}
	if readable("/etc/exports") {
		for _, l := range strings.Split(readText("/etc/exports"), "\n") {
			if f := strings.Fields(l); len(f) > 0 && strings.HasPrefix(f[0], "/") {
				add("nfs", f[0])
			}
		}
	}
	if haveCmd("midclt") {
		for _, k := range [][2]string{{"smb", "sharing.smb.query"}, {"nfs", "sharing.nfs.query"}} {
			txt, _ := stdoutOf(15*time.Second, "midclt", "call", k[1])
			for _, part := range strings.Split(txt, ",") {
				if m := rsPathRe.FindAllStringSubmatch(part, -1); len(m) > 0 {
					add(k[0], m[len(m)-1][1])
				}
			}
		}
	}
	if st, err := os.Stat("/home"); err == nil && st.IsDir() {
		add("home", "/home")
	}
	return out
}

// rsCandCached: eens per uur opnieuw zoeken; alleen mappen die er (nog) zijn gaan mee
func rsCandCached(dir string) [][]string {
	file := filepath.Join(dir, "cand")
	if st, err := os.Stat(file); err != nil || st.Size() == 0 || time.Since(st.ModTime()) > time.Hour {
		var b strings.Builder
		for _, c := range rsCandidates() {
			b.WriteString(c[0] + "\t" + c[1] + "\n")
		}
		if os.WriteFile(file+".new", []byte(b.String()), 0o600) == nil {
			_ = os.Rename(file+".new", file)
		}
	}
	out := [][]string{}
	for _, l := range strings.Split(readText(file), "\n") {
		f := tabRead(l, 2)
		if st, err := os.Stat(f[1]); f[1] != "" && err == nil && st.IsDir() {
			out = append(out, []string{f[0], f[1]})
		}
	}
	return out
}

// rsCanaryMake: een lokaasbestand met de eigenaar van de map; geeft "pad <tab> vingerafdruk" terug
func rsCanaryMake(dir, name string) string {
	f := filepath.Join(dir, name)
	rnd := make([]byte, 24)
	_, _ = rand.Read(rnd)
	body := "Dit bestand hoort bij RzSense (bescherming tegen ransomware).\nNiet openen, wijzigen, hernoemen of weggooien: dan volgt een alarm.\n" +
		"This file belongs to RzSense (ransomware protection). Do not open, change, rename or delete it.\n" + base64.StdEncoding.EncodeToString(rnd) + "\n"
	if os.WriteFile(f, []byte(body), 0o664) != nil {
		return ""
	}
	if st, err := os.Stat(dir); err == nil {
		if sys, ok := st.Sys().(*syscall.Stat_t); ok {
			_ = os.Chown(f, int(sys.Uid), int(sys.Gid))
		}
	}
	_ = os.Chmod(f, 0o664)
	sha, _ := sha256File(f)
	return f + "\t" + sha + "\n"
}

// rsChanged: de bestanden die sinds de stempel veranderden of hernoemd werden (statustijd nieuwer), hooguit 8 mappen
// diep en 20.000 stuks; slow = het zoeken duurde langer dan 15 seconden
func rsChanged(root, stamp string) (list []string, slow bool) {
	st, err := os.Stat(stamp)
	if err != nil {
		return nil, false
	}
	ref := st.Sys().(*syscall.Stat_t).Mtim
	rst, err := os.Lstat(root)
	if err != nil {
		return nil, false
	}
	dev := rst.Sys().(*syscall.Stat_t).Dev
	deadline := time.Now().Add(15 * time.Second)
	var walk func(dir string, depth int) bool
	walk = func(dir string, depth int) bool {
		ents, err := os.ReadDir(dir)
		if err != nil {
			return true
		}
		for _, e := range ents {
			if time.Now().After(deadline) {
				slow = true
				return false
			}
			p := filepath.Join(dir, e.Name())
			fi, err := os.Lstat(p)
			if err != nil {
				continue
			}
			sys := fi.Sys().(*syscall.Stat_t)
			if fi.IsDir() {
				if sys.Dev == dev && depth < 8 {
					if !walk(p, depth+1) {
						return false
					}
				}
				continue
			}
			if !fi.Mode().IsRegular() || e.Name() == rsC1 || e.Name() == rsC2 || strings.Contains(p, "/.zfs/") || strings.Contains(p, "/.rzsense-snapshots/") {
				continue
			}
			if sys.Ctim.Sec > ref.Sec || (sys.Ctim.Sec == ref.Sec && sys.Ctim.Nsec > ref.Nsec) {
				list = append(list, p)
				if len(list) >= 20000 {
					return false
				}
			}
		}
		return true
	}
	walk(root, 1)
	return list, slow
}

// rsEncrypted: een tekstbestand dat niet meer te comprimeren is (versleuteld)?
func rsEncrypted(p string) bool {
	st, err := os.Stat(p)
	if err != nil || st.Size() < 4096 {
		return false
	}
	fh, err := os.Open(p)
	if err != nil {
		return false
	}
	defer fh.Close()
	head, _ := io.ReadAll(io.LimitReader(fh, 65536))
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	_, _ = zw.Write(head)
	_ = zw.Close()
	rd := st.Size()
	if rd > 65536 {
		rd = 65536
	}
	return int64(buf.Len())*100 >= rd*97
}

// rsSnapshot: een snapshot van het ZFS-dataset (of btrfs-subvolume) van de map; geeft de naam, of leeg
func rsSnapshot(p string) string {
	ts := time.Now().Format("20060102-150405")
	if haveCmd("zfs") {
		out, _ := stdoutOf(20*time.Second, "zfs", "list", "-H", "-o", "name,mountpoint", "-t", "filesystem")
		best, bl := "", 0
		for _, l := range lines(out) {
			f := tabRead(l, 2)
			if f[1] == "/" || !strings.HasPrefix(f[1], "/") {
				continue
			}
			if strings.HasPrefix(p+"/", f[1]+"/") && len(f[1]) > bl {
				best, bl = f[0], len(f[1])
			}
		}
		if best != "" {
			name := best + "@rzsense-ransom-" + ts
			if _, rc := run(60*time.Second, "zfs", "snapshot", "-r", name); rc == 0 {
				return name
			}
		}
	}
	if out, _ := stdoutOf(10*time.Second, "stat", "-f", "-c", "%T", p); strings.TrimSpace(out) == "btrfs" && haveCmd("btrfs") {
		if st, err := os.Stat(p); err == nil && st.Sys().(*syscall.Stat_t).Ino == 256 {
			_ = os.MkdirAll(filepath.Join(p, ".rzsense-snapshots"), 0o700)
			name := filepath.Join(p, ".rzsense-snapshots", "ransom-"+ts)
			if _, rc := run(60*time.Second, "btrfs", "subvolume", "snapshot", "-r", p, name); rc == 0 {
				return name
			}
		}
	}
	return ""
}

// rsStop: de processen stoppen die nu in deze bestanden schrijven; bij Samba de sessie van die pc, en dat IP in de
// blokkade-set van de firewall. Nooit systeemprocessen of de agent zelf.
func rsStop(p string, files []string) []map[string]any {
	pids := []string{}
	if haveCmd("lsof") && len(files) > 0 {
		out, _ := stdoutOf(10*time.Second, "lsof", append([]string{"-t", "-w", "--"}, files...)...)
		l := sortedUnique(lines(out))
		if len(l) > 20 {
			l = l[:20]
		}
		pids = append(pids, l...)
	}
	if haveCmd("smbstatus") { // sessies met een open bestand in deze map
		out, _ := stdoutOf(10*time.Second, "smbstatus", "-L")
		l := []string{}
		for _, r := range lines(out) {
			if f := strings.Fields(r); strings.Contains(r, p) && len(f) > 0 && digitsRe.MatchString(f[0]) {
				l = append(l, f[0])
			}
		}
		l = sortedUnique(l)
		if len(l) > 20 {
			l = l[:20]
		}
		pids = append(pids, l...)
	}
	res := []map[string]any{}
	for _, ps := range pids {
		pid, err := strconv.Atoi(ps)
		if err != nil || pid <= 1 || pid == os.Getpid() || pid == os.Getppid() {
			continue
		}
		comm := strings.TrimSpace(readText("/proc/" + ps + "/comm"))
		if comm == "" || comm == "sshd" || comm == "init" || comm == "agetty" || comm == "login" || strings.HasPrefix(comm, "systemd") || strings.HasPrefix(comm, "rzmon") ||
			strings.HasPrefix(comm, "rzsense") || strings.HasPrefix(comm, "dbus") {
			continue
		}
		ip := ""
		if comm == "smbd" {
			out, _ := stdoutOf(10*time.Second, "smbstatus", "-p")
		find:
			for _, r := range lines(out) {
				f := strings.Fields(r)
				if len(f) == 0 || f[0] != ps {
					continue
				}
				for _, w := range f {
					if rsIPRe.MatchString(w) {
						ip = strings.TrimPrefix(w, "ipv4:")
						if i := strings.LastIndex(ip, ":"); i >= 0 {
							ip = ip[:i]
						}
						break find
					}
				}
			}
		}
		if syscall.Kill(pid, syscall.SIGKILL) != nil {
			continue
		}
		detail := fmt.Sprintf("pid %d (%s)", pid, comm)
		if ip != "" {
			detail += " · pc " + ip
		}
		actLog("ransom", 1, "Proces gestopt", detail)
		res = append(res, map[string]any{"pid": pid, "comm": cleanText(comm), "ip": ip})
		if ip != "" && nftTableLive("rzmon") {
			if _, rc := run(10*time.Second, "nft", "add", "element", "inet", "rzmon", "bl4", "{ "+ip+" }"); rc == 0 {
				actLog("ransom", 1, "IP geblokkeerd", ip+" (Samba-sessie die bestanden versleutelde)")
			}
		}
	}
	return res
}

type rsLine struct {
	on                 bool
	snap, kill, canary string
	paths              [][2]string // pad, drempel
}

// rsParse: de regels "0 ransom snapshot stoppen lokaas" en "0 ransompath pad(base64) drempel" (hooguit 20 mappen)
func rsParse(tests string) rsLine {
	r := rsLine{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 2 || f[0] != "0" {
			continue
		}
		if f[1] == "ransom" && !r.on {
			g := tabRead(l, 6)
			r.on, r.snap, r.kill, r.canary = true, g[2], g[3], g[4]
		}
		if f[1] == "ransompath" && len(r.paths) < 20 {
			g := tabRead(l, 5)
			raw, err := base64.StdEncoding.DecodeString(g[2])
			if err != nil {
				raw = nil
			}
			r.paths = append(r.paths, [2]string{string(raw), g[3]})
		}
	}
	return r
}

func rsPathOK(p string) bool {
	if !strings.HasPrefix(p, "/") || strings.Contains(p, "..") || p == "/" {
		return false
	}
	lst, err := os.Lstat(p)
	if err != nil || !lst.IsDir() {
		return false
	}
	for _, x := range []string{"/proc", "/sys", "/dev", "/run"} {
		if strings.HasPrefix(p, x) {
			return false
		}
	}
	return true
}

func rsKey(p string) string {
	h := sha1.Sum([]byte(p))
	return hex.EncodeToString(h[:])[:12]
}

func olderThan(file string, d time.Duration) bool {
	st, err := os.Stat(file)
	return err == nil && time.Since(st.ModTime()) > d
}

// collectRansomLinux: het onderdeel "ransom" van het rapport (alleen als root; RANSOM=0 zet het op deze server uit)
func collectRansomLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	if !isRoot() || conf["RANSOM"] == "0" {
		return nil
	}
	dir := ransomDir()
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	cand := rsCandCached(dir)
	cfg := rsParse(tests)
	keep := map[string]bool{}
	var out map[string]any
	if cfg.on {
		paths := []map[string]any{}
		size := 0
		for _, pp := range cfg.paths {
			p := pp[0]
			if !rsPathOK(p) {
				continue
			}
			limit := 300
			if digitsRe.MatchString(pp[1]) {
				limit = atoiDef(pp[1], 300)
			}
			k := rsKey(p)
			keep[k] = true
			st := filepath.Join(dir, k)
			// 1. lokaasbestanden
			cstate, chit := "uit", ""
			if cfg.canary == "1" {
				cstate = "ok"
				if fi, err := os.Stat(st + ".canary"); err != nil || fi.Size() == 0 {
					body := rsCanaryMake(p, rsC1) + rsCanaryMake(p, rsC2)
					_ = os.WriteFile(st+".canary", []byte(body), 0o600)
					_ = os.WriteFile(st+".path", []byte(p+"\n"), 0o600)
					if body != "" {
						actLog("ransom", 1, "Lokaasbestanden geplaatst", p)
					} else {
						cstate = "fout"
					}
				} else {
					for _, l := range lines(readText(st + ".canary")) {
						f := tabRead(l, 2)
						if _, err := os.Lstat(f[0]); err != nil {
							chit += filepath.Base(f[0]) + " is weg; "
						} else if sha, _ := sha256File(f[0]); sha != f[1] {
							chit += filepath.Base(f[0]) + " is gewijzigd; "
						}
					}
					if chit != "" {
						cstate = "alarm"
						// na 10 minuten opnieuw plaatsen (dan zie je ook een tweede golf)
						if _, err := os.Stat(st + ".chit"); err != nil {
							_ = os.WriteFile(st+".chit", nil, 0o600)
						} else if olderThan(st+".chit", 10*time.Minute) {
							_ = os.Remove(st + ".chit")
							_ = os.Remove(st + ".canary")
						}
					}
				}
			}
			// 2. en 3. wat er sinds de vorige ronde veranderde
			nCh, ext, note, enc, slow := 0, 0, 0, 0, 0
			ex := []string{}
			var list []string
			_ = os.WriteFile(st+".stamp.new", nil, 0o600)
			if _, err := os.Stat(st + ".stamp"); err == nil {
				var sl bool
				list, sl = rsChanged(p, st+".stamp")
				if sl {
					slow = 1
				}
				_ = os.WriteFile(st+".list", []byte(strings.Join(list, "\n")+map[bool]string{true: "\n", false: ""}[len(list) > 0]), 0o600)
				nCh = len(list)
				texts := 0
				notes := []string{}
				for _, f := range list {
					if rsExtRe.MatchString(f) {
						ext++
						ex = append(ex, f)
					}
					if rsNoteRe.MatchString(strings.ToLower(filepath.Base(f))) {
						note++
						notes = append(notes, f)
					}
					if rsTextRe.MatchString(f) && texts < 15 {
						texts++
						if rsEncrypted(f) {
							enc++
						}
					}
				}
				ex = append(ex, notes...)
				if len(ex) > 5 {
					ex = ex[:5]
				}
				for i, f := range ex {
					ex[i] = cleanText(cutRunes(strings.TrimPrefix(f, p+"/"), 120))
				}
			}
			_ = os.Rename(st+".stamp.new", st+".stamp")
			// Sterk signaal: lokaas geraakt, extensies, briefjes, versleutelde tekst, of heel veel tegelijk mét een kenmerk
			hit := 0
			if chit != "" || ext >= 3 || note >= 2 || enc >= 3 || (nCh >= limit && ext+enc+note >= 1) {
				hit = 1
			}
			snapName := ""
			killed := []map[string]any{}
			if hit == 1 {
				actLog("ransom", 0, "Mogelijke ransomware", fmt.Sprintf("%s · %s%d gewijzigd · %d met ransomware-extensie · %d losgeldbriefjes · %d versleuteld", p, chit, nCh, ext, note, enc))
				if _, err := os.Stat(st + ".snap"); cfg.snap == "1" && (err != nil || olderThan(st+".snap", 30*time.Minute)) {
					if snapName = rsSnapshot(p); snapName != "" {
						_ = os.WriteFile(st+".snap", nil, 0o600)
						actLog("ransom", 1, "Snapshot gemaakt", snapName)
					} else {
						actLog("ransom", 0, "Snapshot maken mislukt", p+": geen ZFS-dataset of btrfs-subvolume gevonden")
					}
				}
				if cfg.kill == "1" {
					files := []string{}
					for _, f := range list {
						if rsExtRe.MatchString(f) {
							files = append(files, f)
						}
					}
					head := list
					if len(head) > 30 {
						head = head[:30]
					}
					files = append(files, head...)
					if len(files) > 40 {
						files = files[:40]
					}
					killed = rsStop(p, files)
				}
			}
			row := map[string]any{"k": k, "p": cleanText(p), "n": nCh, "ext": ext, "notes": note, "enc": enc, "slow": slow, "canary": cstate, "chit": cleanText(chit), "hit": hit,
				"snap": cleanText(snapName), "ex": ex, "killed": killed}
			paths = append(paths, row)
			if size += 200 + len(p) + len(chit) + 130*len(ex); size > 60000 {
				break
			}
		}
		out = map[string]any{"on": 1, "paths": paths, "cand": cand}
	} else {
		out = map[string]any{"on": 0, "cand": cand}
	}
	// Mappen die niet meer bewaakt worden (of lokaas uit): onze lokaasbestanden weg, alleen als ze nog ongewijzigd zijn
	if files, _ := filepath.Glob(filepath.Join(dir, "*.canary")); len(files) > 0 {
		for _, cf := range files {
			k := strings.TrimSuffix(filepath.Base(cf), ".canary")
			if keep[k] && cfg.canary == "1" {
				continue
			}
			for _, l := range lines(readText(cf)) {
				f := tabRead(l, 2)
				if lst, err := os.Lstat(f[0]); err == nil && lst.Mode().IsRegular() && (f[0] == filepath.Join(filepath.Dir(f[0]), rsC1) || f[0] == filepath.Join(filepath.Dir(f[0]), rsC2)) {
					if sha, _ := sha256File(f[0]); sha == f[1] {
						_ = os.Remove(f[0])
					}
				}
			}
			actLog("ransom", 1, "Lokaasbestanden weggehaald", strings.TrimSpace(readText(filepath.Join(dir, k+".path"))))
			for _, x := range []string{".canary", ".path", ".chit"} {
				_ = os.Remove(filepath.Join(dir, k+x))
			}
		}
	}
	if files, _ := filepath.Glob(filepath.Join(dir, "*.stamp")); len(files) > 0 { // stempels van mappen die weg zijn
		for _, sf := range files {
			k := strings.TrimSuffix(filepath.Base(sf), ".stamp")
			if !keep[k] {
				for _, x := range []string{".stamp", ".list", ".snap"} {
					_ = os.Remove(filepath.Join(dir, k+x))
				}
			}
		}
	}
	return out
}

// ransomObserve: voor de proef: welke mappen bewaakt zouden worden en welke er te kiezen zijn. De Go-agent plaatst in
// de proef geen lokaasbestanden en houdt geen eigen stand bij.
func ransomObserve() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	cfg := rsParse(tests)
	if !isRoot() || conf["RANSOM"] == "0" || !cfg.on {
		return nil
	}
	paths := []string{}
	for _, pp := range cfg.paths {
		if rsPathOK(pp[0]) {
			paths = append(paths, pp[0])
		}
	}
	dir := filepath.Join(dataDir(), "ransom-obs")
	_ = os.MkdirAll(dir, 0o700)
	cand := []string{}
	for _, c := range rsCandCached(dir) {
		cand = append(cand, c[0]+" "+c[1])
	}
	return map[string]any{"paths": paths, "cand": cand}
}
