//go:build linux

package main

// De klok en de softwarelijst (0.16.0), zoals de bash-agent (agent/src/85-meesturen.sh en "Software" in 75-…):
//   - klok: gesynchroniseerd en hoeveel ms hij afwijkt (chrony of systemd-timesyncd), voor de check Tijd;
//   - software: de geïnstalleerde pakketten met de distributie, de draaiende en de nieuwste kernel, diensten die een
//     herstart nodig hebben en de pakketten van programma's die op een poort luisteren. RzSense zoekt er de bekende
//     kwetsbaarheden bij. De hele lijst gaat één keer per dag mee, of eerder als de pakketdatabase verandert; anders
//     alleen het controlegetal.

import (
	"bufio"
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// ---------------------------------------------------------------- klok

// parseChrony: uitvoer van "chronyc -n tracking" → gesynchroniseerd, afwijking in ms (nil als die er niet staat)
func parseChrony(out string) (sync bool, off any) {
	for _, l := range strings.Split(out, "\n") {
		k, v, ok := strings.Cut(l, ":")
		if !ok {
			continue
		}
		v = strings.TrimSpace(v)
		switch {
		case strings.HasPrefix(k, "System time"):
			if f := strings.Fields(v); len(f) > 0 {
				x, _ := strconv.ParseFloat(f[0], 64)
				x *= 1000
				if strings.Contains(v, "slow") {
					x = -x
				}
				off = roundTo(x, 3)
			}
		case strings.HasPrefix(k, "Leap status"):
			sync = v == "Normal"
		}
	}
	return sync, off
}

var numOnly = regexp.MustCompile(`[^0-9.+-]`)

// parseTimesyncOffset: de regel "Offset: +1.234ms" uit "timedatectl timesync-status" → ms
func parseTimesyncOffset(out string) any {
	for _, l := range strings.Split(out, "\n") {
		if !strings.Contains(l, "Offset:") {
			continue
		}
		_, v, _ := strings.Cut(l, ":")
		v = strings.TrimSpace(v)
		m := 1.0
		switch {
		case strings.Contains(v, "us"):
			m = 0.001
		case strings.Contains(v, "ms"):
			m = 1
		case strings.HasSuffix(v, "s"):
			m = 1000
		}
		x, _ := strconv.ParseFloat(numOnly.ReplaceAllString(v, ""), 64)
		return roundTo(x*m, 3)
	}
	return nil
}

func roundTo(x float64, n int) float64 {
	v, _ := strconv.ParseFloat(strconv.FormatFloat(x, 'f', n, 64), 64)
	return v
}

// collectTimeLinux: {src, sync, offset_ms}, of nil zonder chrony en zonder timedatectl
func collectTimeLinux() map[string]any {
	if out, code := run(5*time.Second, "chronyc", "-n", "tracking"); code == 0 && strings.TrimSpace(out) != "" {
		sync, off := parseChrony(out)
		return map[string]any{"src": "chrony", "sync": sync, "offset_ms": off}
	}
	if out, code := run(5*time.Second, "timedatectl", "show", "-p", "NTPSynchronized", "--value"); code == 0 && strings.TrimSpace(out) != "" {
		st, _ := run(5*time.Second, "timedatectl", "timesync-status")
		return map[string]any{"src": "timesyncd", "sync": strings.TrimSpace(out) == "yes", "offset_ms": parseTimesyncOffset(st)}
	}
	return nil
}

// ---------------------------------------------------------------- software

var (
	srcRpmTail = regexp.MustCompile(`-[^-]+-[^-]+\.src\.rpm$`)
	unitNameRe = regexp.MustCompile(`^[A-Za-z0-9@._:-]+$`)
	pidRe      = regexp.MustCompile(`pid=([0-9]+)`)
	portTail   = regexp.MustCompile(`:[0-9]+$`)
	verPart    = regexp.MustCompile(`[0-9]+|[^0-9]+`)
)

// dpkgRows: uitvoer van dpkg-query (status, naam, versie, architectuur, bronpakket, bronversie) → alleen wat geïnstalleerd is
func dpkgRows(out string) [][]string {
	rows := [][]string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Split(l, "\t")
		if len(f) >= 6 && strings.HasPrefix(f[0], "ii") {
			rows = append(rows, []string{f[1], f[2], f[3], f[4], f[5]})
		}
	}
	return rows
}

// rpmRows: uitvoer van rpm -qa (naam, epoch:versie-release, architectuur, bron-rpm)
func rpmRows(out string) [][]string {
	rows := [][]string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 4 || f[0] == "gpg-pubkey" {
			continue
		}
		v := strings.TrimPrefix(f[1], "0:")
		rows = append(rows, []string{f[0], v, f[2], srcRpmTail.ReplaceAllString(f[3], ""), v})
	}
	return rows
}

// versionLess: a vóór b, zoals "sort -V" voor kernelversies (getallen als getal, de rest als tekst)
func versionLess(a, b string) bool {
	pa, pb := verPart.FindAllString(a, -1), verPart.FindAllString(b, -1)
	for i := 0; i < len(pa) && i < len(pb); i++ {
		x, ex := strconv.Atoi(pa[i])
		y, ey := strconv.Atoi(pb[i])
		if ex == nil && ey == nil {
			if x != y {
				return x < y
			}
		} else if pa[i] != pb[i] {
			return pa[i] < pb[i]
		}
	}
	return len(pa) < len(pb)
}

// latestKernel: de nieuwste geïnstalleerde kernel in /boot (vmlinuz-…), zonder de rescue-kernel
func latestKernel(names []string) string {
	best := ""
	for _, n := range names {
		v, ok := strings.CutPrefix(n, "vmlinuz-")
		if !ok || strings.Contains(v, "rescue") {
			continue
		}
		if best == "" || versionLess(best, v) {
			best = v
		}
	}
	return best
}

type listenProc struct {
	proto string
	port  int
	pids  []string
}

// exposedFromSs: uitvoer van "ss -Htulnp" → wat er op een poort luistert buiten localhost, met de processen erbij
func exposedFromSs(out string) []listenProc {
	seen := map[string]bool{}
	var res []listenProc
	lines := strings.Split(out, "\n")
	sort.Strings(lines)
	for _, l := range lines {
		f := strings.Fields(l)
		if len(f) < 5 || !portTail.MatchString(f[4]) {
			continue
		}
		addr := portTail.ReplaceAllString(f[4], "")
		addr = strings.NewReplacer("[", "", "]", "").Replace(addr)
		addr, _, _ = strings.Cut(addr, "%")
		if strings.HasPrefix(addr, "127.") || addr == "::1" || addr == "localhost" {
			continue
		}
		var pids []string
		for _, m := range pidRe.FindAllStringSubmatch(l, -1) {
			pids = append(pids, m[1])
		}
		key := f[0] + " " + f[4] + " " + strings.Join(pids, " ")
		if len(pids) == 0 || seen[key] {
			continue
		}
		seen[key] = true
		port, _ := strconv.Atoi(f[4][strings.LastIndexByte(f[4], ':')+1:])
		res = append(res, listenProc{proto: f[0], port: port, pids: pids})
	}
	return res
}

// dpkgOwners: bij welk pakket hoort elk bestand (uit /var/lib/dpkg/info/*.list; /bin/x en /usr/bin/x zijn hetzelfde)
func dpkgOwners(paths []string, infoDir string) map[string]string {
	want := map[string]string{}
	for _, p := range paths {
		want[p] = p
		if rest, ok := strings.CutPrefix(p, "/usr/"); ok {
			want["/"+rest] = p
		} else {
			want["/usr"+p] = p
		}
	}
	out := map[string]string{}
	files, _ := filepath.Glob(filepath.Join(infoDir, "*.list"))
	for _, file := range files {
		fh, err := os.Open(file)
		if err != nil {
			continue
		}
		name := strings.TrimSuffix(filepath.Base(file), ".list")
		name, _, _ = strings.Cut(name, ":")
		sc := bufio.NewScanner(fh)
		for sc.Scan() {
			if p, ok := want[sc.Text()]; ok && out[p] == "" {
				out[p] = name
			}
		}
		fh.Close()
		if len(out) == len(paths) {
			break
		}
	}
	return out
}

// pkgOwners: bestand → pakket, met rpm of dpkg
func pkgOwners(paths []string) map[string]string {
	out := map[string]string{}
	if len(paths) == 0 {
		return out
	}
	_, e1 := os.Stat("/var/lib/rpm")
	_, e2 := os.Stat("/usr/lib/sysimage/rpm")
	if e1 == nil || e2 == nil {
		res, code := run(60*time.Second, "rpm", append([]string{"-qf", "--qf", `%{NAME}\n`, "--"}, paths...)...)
		lines := strings.Split(strings.TrimRight(res, "\n"), "\n")
		if code >= 0 && len(lines) == len(paths) {
			for i, p := range paths {
				if lines[i] != "" && !strings.Contains(lines[i], " ") { // een regel met spaties is een foutmelding
					out[p] = lines[i]
				}
			}
		}
	}
	if _, err := os.Stat("/var/lib/dpkg/info"); err == nil {
		for p, n := range dpkgOwners(paths, "/var/lib/dpkg/info") {
			if out[p] == "" {
				out[p] = n
			}
		}
	}
	return out
}

// buildSoftware: de hele softwarelijst, zoals list.json van de bash-agent. ok = false zonder dpkg of rpm.
func buildSoftware() (map[string]any, string, bool) {
	mgr, db := "", ""
	var rows [][]string
	if _, err := os.Stat("/var/lib/dpkg/status"); err == nil && haveCmd("dpkg-query") {
		mgr, db = "dpkg", "/var/lib/dpkg/status"
		out, _ := run(120*time.Second, "dpkg-query", "-W", `-f=${db:Status-Abbrev}\t${Package}\t${Version}\t${Architecture}\t${source:Package}\t${source:Version}\n`)
		rows = dpkgRows(out)
	} else if haveCmd("rpm") {
		mgr = "rpm"
		for _, f := range []string{"/var/lib/rpm/rpmdb.sqlite", "/var/lib/rpm/Packages", "/usr/lib/sysimage/rpm/rpmdb.sqlite"} {
			if _, err := os.Stat(f); err == nil {
				db = f
				break
			}
		}
		out, _ := run(120*time.Second, "rpm", "-qa", "--qf", `%{NAME}\t%{EPOCHNUM}:%{VERSION}-%{RELEASE}\t%{ARCH}\t%{SOURCERPM}\n`)
		rows = rpmRows(out)
	} else {
		return nil, "", false
	}
	if len(rows) == 0 {
		return nil, "", false // de pakketbeheerder gaf niets: geen lege lijst bewaren of sturen
	}
	osr, _ := readShellConf("/etc/os-release")
	exposed := [][]any{}
	if haveCmd("ss") && isRoot() {
		out, _ := run(20*time.Second, "ss", "-Htulnp")
		procs := exposedFromSs(out)
		exe := map[string]string{}
		var paths []string
		for _, p := range procs {
			for _, pid := range p.pids {
				if _, done := exe[pid]; done {
					continue
				}
				e, err := filepath.EvalSymlinks("/proc/" + pid + "/exe")
				if err != nil {
					e, _ = os.Readlink("/proc/" + pid + "/exe")
					e = strings.TrimSuffix(e, " (deleted)")
				}
				exe[pid] = e
				if e != "" && !hasStr(paths, e) {
					paths = append(paths, e)
				}
			}
		}
		owner := pkgOwners(paths)
		for _, p := range procs {
			for _, pid := range p.pids {
				if o := owner[exe[pid]]; o != "" {
					exposed = append(exposed, []any{o, p.port, p.proto})
				}
			}
		}
	}
	var boot []string
	if ents, err := os.ReadDir("/boot"); err == nil {
		for _, e := range ents {
			boot = append(boot, e.Name())
		}
	}
	restart := []string{}
	var rs string
	if haveCmd("needs-restarting") {
		rs, _ = runOutside(60*time.Second, "needs-restarting", "-s")
	} else if haveCmd("needrestart") {
		out, _ := runOutside(60*time.Second, "needrestart", "-b", "-r", "l")
		for _, l := range strings.Split(out, "\n") {
			if v, ok := strings.CutPrefix(l, "NEEDRESTART-SVC:"); ok {
				rs += strings.TrimSpace(v) + "\n"
			}
		}
	}
	for _, l := range strings.Split(rs, "\n") {
		if unitNameRe.MatchString(l) && len(restart) < 50 {
			restart = append(restart, l)
		}
	}
	uname, _ := run(5*time.Second, "uname", "-r")
	return map[string]any{
		"mgr": mgr, "os": map[string]string{"id": osr["ID"], "version": osr["VERSION_ID"], "like": osr["ID_LIKE"], "name": osr["PRETTY_NAME"]},
		"kernel":  map[string]string{"running": strings.TrimSpace(uname), "latest": latestKernel(boot)},
		"restart": restart, "exposed": exposed, "list": rows,
	}, db, true
}

func haveCmd(name string) bool { _, err := exec.LookPath(name); return err == nil }

func hasStr(l []string, s string) bool {
	for _, x := range l {
		if x == s {
			return true
		}
	}
	return false
}

func softwareFile() string { return filepath.Join(dataDir(), "pkgs.json") }

// softwareFresh: de lijst opnieuw maken als hij ontbreekt, RzSense erom vroeg, de pakketdatabase nieuwer is, of na een dag.
// Geeft de inhoud en of hij zojuist opnieuw gemaakt is.
func softwareFresh() (data []byte, rebuilt bool) {
	st, err := os.Stat(softwareFile())
	need := err != nil || st.Size() == 0 || readState("pkgs.resend") != "" || time.Since(st.ModTime()) >= 24*time.Hour
	if !need {
		for _, db := range []string{"/var/lib/dpkg/status", "/var/lib/rpm/rpmdb.sqlite", "/var/lib/rpm/Packages", "/usr/lib/sysimage/rpm/rpmdb.sqlite"} {
			if d, e := os.Stat(db); e == nil {
				need = d.ModTime().After(st.ModTime())
				break
			}
		}
	}
	if need {
		dropState("pkgs.resend")
		if sw, _, ok := buildSoftware(); ok {
			b, _ := json.Marshal(sw)
			tmp := softwareFile() + ".tmp"
			if os.WriteFile(tmp, b, 0o600) == nil && os.Rename(tmp, softwareFile()) == nil {
				rebuilt = true
			}
		}
	}
	data, _ = os.ReadFile(softwareFile())
	return data, rebuilt
}

var sentPkgsHash string // het controlegetal dat met dit rapport meeging

// collectSoftwareLinux: het onderdeel "packages" van het echte rapport: {hash} of {hash, data}
func collectSoftwareLinux() map[string]any {
	data, _ := softwareFresh()
	if len(data) == 0 {
		return nil
	}
	sum := sha1.Sum(data)
	hash := hex.EncodeToString(sum[:])[:16]
	sentPkgsHash = hash
	// Alles meesturen als RzSense deze stand nog niet heeft. Kwam er de vorige keer geen antwoord, dan eerst alleen het
	// controlegetal: vaak heeft RzSense de lijst toen toch opgeslagen.
	if readState("pkgs.sent") == hash {
		return map[string]any{"hash": hash}
	}
	if readState("pkgs.tried") == hash {
		dropState("pkgs.tried")
		return map[string]any{"hash": hash}
	}
	writeState("pkgs.tried", hash)
	return map[string]any{"hash": hash, "data": json.RawMessage(data)}
}

// softwareReply: het antwoord van RzSense op de softwarelijst
func softwareReply(ok, resend bool) {
	switch {
	case resend:
		writeState("pkgs.resend", "1")
		dropState("pkgs.sent")
	case ok && sentPkgsHash != "":
		writeState("pkgs.sent", sentPkgsHash)
		dropState("pkgs.tried")
	}
}

// softwareForShadow: in de proef: de lijst een paar minuten nadat hij opnieuw gemaakt is één keer meesturen, zodat
// RzSense hem naast die van de bash-agent kan leggen (die heeft hem dan ook opnieuw gestuurd)
func softwareForShadow() any {
	data, rebuilt := softwareFresh()
	now := time.Now().Unix()
	if rebuilt {
		writeState("pkgs.cmp", fmt.Sprint(now+150))
		return nil
	}
	due := int64(atoiDef(readState("pkgs.cmp"), 0))
	if due == 0 || now < due || len(data) == 0 {
		return nil
	}
	dropState("pkgs.cmp")
	return json.RawMessage(data)
}
