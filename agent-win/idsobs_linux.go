//go:build linux

package main

// IDS, eerste stap (0.27.0): alleen waarnemen, als proef. De Go-agent leest wat de bash-agent voor het IDS leest
// (agent/src/60-ids-en-edr.sh, onderdelen 1, 2, 3, 6 en 6c) en RzSense legt het naast wat de bash-agent meldde:
//   - gevoelige bestanden (inlog, sudo, SSH, cron, systemd-units, PAM …): alleen hun SHA-256, nooit de inhoud;
//   - gebruikers (naam en UID), leden van groepen die (bijna) root zijn, en kernelmodules;
//   - verdachte processen: uit /tmp, /dev/shm of /var/tmp, zonder bestand (memfd), of een verwijderd programma;
//   - automatisch startende diensten en timers.
// Dit grijpt nergens in: geen firewall, geen gebruikers, geen processen. Het IDS zelf blijft bij de bash-agent, en dit
// onderdeel telt niet mee voor overnemen. Het loopt alleen mee als het IDS voor deze server aan staat.

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// de plekken waar een aanvaller een achterdeur zet (letterlijk de lijst van de bash-agent)
var idsFileGlobs = []string{"/etc/passwd", "/etc/shadow", "/etc/group", "/etc/gshadow", "/etc/sudoers", "/etc/sudoers.d/*", "/etc/ssh/sshd_config", "/etc/ssh/sshd_config.d/*",
	"/etc/crontab", "/etc/cron.d/*", "/var/spool/cron/*", "/var/spool/cron/crontabs/*", "/root/.ssh/authorized_keys", "/home/*/.ssh/authorized_keys",
	"/etc/systemd/system/*.service", "/etc/systemd/system/*.timer", "/etc/ld.so.preload", "/etc/rc.local", "/etc/profile.d/*.sh",
	"/etc/pam.d/*", "/etc/hosts", "/etc/resolv.conf", "/etc/environment", "/var/spool/at/*", "/var/spool/cron/atjobs/*",
	"/root/.bashrc", "/root/.bash_profile", "/root/.profile", "/etc/systemd/user/*.service", "/etc/systemd/user/*.timer",
	"/root/.config/systemd/user/*.service", "/root/.config/systemd/user/*.timer", "/home/*/.config/systemd/user/*.service", "/home/*/.config/systemd/user/*.timer"}

var (
	idsNameRe  = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)
	idsModRe   = regexp.MustCompile(`^[A-Za-z0-9_]+$`)
	idsGroupRe = regexp.MustCompile(`^(wheel|sudo|docker|root|adm|lxd)$`)
)

// idsFileHashes: pad → SHA-256 van de gevoelige bestanden die er zijn en leesbaar zijn (hooguit 1000)
func idsFileHashes(root string) map[string]string {
	out := map[string]string{}
	n := 0
	for _, g := range idsFileGlobs {
		matches := []string{root + g}
		if strings.Contains(g, "*") {
			matches, _ = filepath.Glob(root + g) // gesorteerd, zoals de shell; verborgen namen doen niet mee
			hidden := matches[:0]
			for _, m := range matches {
				if !hiddenInGlob(root+g, m) {
					hidden = append(hidden, m)
				}
			}
			matches = hidden
		}
		for _, f := range matches {
			st, err := os.Stat(f)
			if err != nil || !st.Mode().IsRegular() || !readable(f) {
				continue
			}
			if n++; n > 1000 {
				return out
			}
			fh, err := os.Open(f)
			if err != nil {
				continue
			}
			h := sha256.New()
			_, err = io.Copy(h, fh)
			fh.Close()
			if err == nil {
				out[strings.TrimPrefix(f, root)] = hex.EncodeToString(h.Sum(nil))
			}
		}
	}
	return out
}

// hiddenInGlob: de shell laat een "*" geen naam matchen die met een punt begint; filepath.Glob wel
func hiddenInGlob(pattern, match string) bool {
	pp, mp := strings.Split(pattern, "/"), strings.Split(match, "/")
	if len(pp) != len(mp) {
		return false
	}
	for i := range pp {
		if strings.HasPrefix(pp[i], "*") && strings.HasPrefix(mp[i], ".") {
			return true
		}
	}
	return false
}

// idsUsers: [[naam, uid] …] uit passwd; idsGroups: de leden van wheel, sudo, docker, root, adm en lxd
func idsUsers(passwd string) [][]any {
	out := [][]any{}
	for _, l := range strings.Split(passwd, "\n") {
		f := strings.Split(l, ":")
		if len(f) >= 3 && idsNameRe.MatchString(f[0]) {
			out = append(out, []any{f[0], int(awkNum(f[2]))})
		}
	}
	return out
}

func idsGroups(group string) map[string][]string {
	out := map[string][]string{}
	for _, l := range strings.Split(group, "\n") {
		f := strings.Split(l, ":")
		if len(f) < 4 || !idsGroupRe.MatchString(f[0]) {
			continue
		}
		members := []string{}
		for _, m := range strings.Split(f[3], ",") {
			if idsNameRe.MatchString(m) {
				members = append(members, m)
			}
		}
		out[f[0]] = members
	}
	return out
}

func idsModules(modules string) []string {
	out := []string{}
	for _, l := range strings.Split(modules, "\n") {
		if f := strings.Fields(l); len(f) > 0 && idsModRe.MatchString(f[0]) {
			out = append(out, f[0])
		}
	}
	return out
}

// idsSuspectExe: draait dit programma vanaf een verdachte plek?
func idsSuspectExe(exe string) bool {
	switch {
	case strings.HasPrefix(exe, "/tmp/"), strings.HasPrefix(exe, "/dev/shm/"), strings.HasPrefix(exe, "/var/tmp/"):
		return true
	case strings.HasPrefix(exe, "/memfd:"):
		return !strings.Contains(exe, "runc") && !strings.Contains(exe, "crun") && !strings.Contains(exe, "containerd")
	case strings.HasSuffix(exe, " (deleted)"):
		// Na een update draait een dienst vaak nog met het oude (verwijderde) programma: dat is normaal
		p := strings.TrimSuffix(exe, " (deleted)")
		for _, ok := range []string{"/usr/", "/opt/", "/lib", "/bin/", "/sbin/", "/snap/", "/var/lib/"} {
			if strings.HasPrefix(p, ok) {
				return false
			}
		}
		return true
	}
	return false
}

// idsSuspectProcs: hooguit 20 verdachte processen, met eigenaar en opdrachtregel
func idsSuspectProcs() []map[string]any {
	out := []map[string]any{}
	ents, _ := os.ReadDir("/proc")
	pids := []int{}
	for _, e := range ents {
		if n, err := strconv.Atoi(e.Name()); err == nil {
			pids = append(pids, n)
		}
	}
	// de shell loopt /proc/[0-9]* af in tekstvolgorde
	sort.Slice(pids, func(i, j int) bool { return strconv.Itoa(pids[i]) < strconv.Itoa(pids[j]) })
	for _, pid := range pids {
		dir := fmt.Sprintf("/proc/%d", pid)
		exe, err := os.Readlink(dir + "/exe")
		if err != nil || !idsSuspectExe(exe) {
			continue
		}
		cmd, _ := os.ReadFile(dir + "/cmdline")
		c := strings.ReplaceAll(string(cmd), "\x00", " ")
		if len(c) > 300 {
			c = c[:300]
		}
		usr := ""
		if st, err := os.Stat(dir); err == nil {
			if sys, ok := st.Sys().(*syscall.Stat_t); ok {
				usr = userName(sys.Uid)
			}
		}
		out = append(out, map[string]any{"pid": pid, "exe": cleanText(exe), "user": usr, "cmd": jsonText([]byte(c), 300)})
		if len(out) >= 20 {
			break
		}
	}
	return out
}

func userName(uid uint32) string {
	id := strconv.FormatUint(uint64(uid), 10)
	for _, l := range strings.Split(readText("/etc/passwd"), "\n") {
		if f := strings.Split(l, ":"); len(f) >= 3 && f[2] == id {
			return f[0]
		}
	}
	return id
}

// idsEnabledUnits: de diensten en timers die automatisch starten (hooguit 500), of nil zonder systemd
func idsEnabledUnits() []string {
	if _, err := os.Stat("/run/systemd/system"); err != nil || !haveCmd("systemctl") {
		return nil
	}
	out, _ := stdoutOf(20*time.Second, "systemctl", "list-unit-files", "--type=service,timer", "--state=enabled", "--no-legend", "--no-pager")
	units := []string{}
	for _, l := range lines(out) {
		if f := strings.Fields(l); len(f) > 0 && unitNameRe.MatchString(f[0]) && len(units) < 500 {
			units = append(units, f[0])
		}
	}
	return units
}

// collectIdsObsLinux: wat de Go-agent voor het IDS waarneemt (alleen in de proef, alleen als het IDS voor deze server aan
// staat: de regel "0 <tab> ids <tab> learn|block" in de testlijst van de bash-agent)
func collectIdsObsLinux() map[string]any {
	on := false
	if os.Getenv("RZSENSE_TEST_IDS") != "" { // voor de tests
		on = true
	}
	for _, l := range strings.Split(readText(filepath.Join(bashCachePath(), "tests.cache")), "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "ids" && f[2] != "" {
			on = true
			break
		}
	}
	if !on {
		return nil
	}
	obs := map[string]any{"files": idsFileHashes(""), "users": idsUsers(readText("/etc/passwd")), "groups": idsGroups(readText("/etc/group")),
		"modules": idsModules(readText("/proc/modules")), "procs": idsSuspectProcs()}
	if u := idsEnabledUnits(); u != nil {
		obs["units"] = u
	}
	idsObserveMore(obs, readText(filepath.Join(bashCachePath(), "tests.cache"))) // 0.28.0: de rest van het waarnemen
	return obs
}
