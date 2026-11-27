//go:build linux

package main

// Logins, sudo en su (0.14.0), met dezelfde uitkomst als de bash-agent (agent/src/17-logins-lezen.sh en het begin van
// 60-ids-en-edr.sh): de nieuwe regels van het auth-log sinds de vorige keer (journal, anders /var/log/secure of
// /var/log/auth.log) worden gebeurtenissen (gelukte logins, sudo, su, einde van een SSH-sessie), opgetelde mislukte
// pogingen en de losse mislukte SSH-pogingen. Wachtwoorden in sudo-commando's worden hier al gemaskeerd.
//
// Alleen als de Go-agent het werk doet; in de proef leest hij geen logins (de bash-agent stuurt ze al). "Waar was ik
// gebleven" staat in hetzelfde bestand als bij de bash-agent (authstate), in hetzelfde formaat: zo gaat er bij het
// overnemen en bij het teruggaan geen login verloren en komt er geen dubbel.

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
)

// POSIX: de langste overeenkomst, zoals de bash-agent ([[ =~ ]] en sed -E)
var (
	reSSHOk      = regexp.MustCompilePOSIX(`^Accepted ([^ ]+) for ([^ ]+) from ([^ ]+) port ([0-9]+)`)
	reSSHFail    = regexp.MustCompilePOSIX(`^Failed ([^ ]+) for (invalid user )?([^ ]*) from ([^ ]+) port ([0-9]+)`)
	reSSHInvalid = regexp.MustCompilePOSIX(`^Invalid user ([^ ]*) from ([^ ]+)( port ([0-9]+))?`)
	reSSHEnd     = regexp.MustCompilePOSIX(`^Disconnected from user ([^ ]+) ([^ ]+) port ([0-9]+)`)
	reLoginOk    = regexp.MustCompilePOSIX(`^pam_unix\(login:session\): session opened for user ([^ (]+)`)
	reLoginFail  = regexp.MustCompilePOSIX(`^pam_unix\(login:auth\): authentication failure;.* tty=([^ ]*).* user=([^ ]+)`)
	reSudo       = regexp.MustCompilePOSIX(`^ *([^ ]+) : (.*)TTY=([^ ]+) ; PWD=(.*) ; USER=([^ ]+) ; (.* ; )?COMMAND=(.*)$`)
	reSuOk       = regexp.MustCompilePOSIX(`^pam_unix\(su(-l)?:session\): session opened for user ([^ (]+)(\(uid=[0-9]+\))? by ([^ (]*)`)
	reSuFail     = regexp.MustCompilePOSIX(`^pam_unix\(su(-l)?:auth\): authentication failure;.* logname=([^ ]*) .* tty=([^ ]*) .* user=([^ ]+)`)

	reMaskWord  = regexp.MustCompile(`(?i)((password|passwd|pass|pwd|token|secret|apikey|api_key)[=:])[^ ]+`)
	reMaskLong  = regexp.MustCompilePOSIX(`(--password[= ])[^ ]+`)
	reMaskMysql = regexp.MustCompilePOSIX(`((mysql|mariadb|mysqldump|mysqladmin)( [^|;&]*)? -p)[^ ]+`)
	reDigits    = regexp.MustCompile(`^[0-9]+$`)
	reSyslogID  = regexp.MustCompile(`(\[[0-9]+\])?:$`)
	reThree     = regexp.MustCompile(`^[^ ]+ [^ ]+ [^ ]+ `)
	reField     = regexp.MustCompile(`^[^ ]+ +`)
)

// maskSecrets: wachtwoorden in een commando onleesbaar maken (mysql -pGeheim, password=…, --password …)
func maskSecrets(s string) string {
	s = reMaskWord.ReplaceAllString(s, "${1}••••")
	s = reMaskLong.ReplaceAllString(s, "${1}••••")
	return reMaskMysql.ReplaceAllString(s, "${1}••••")
}

// cleanText: geldige UTF-8 zonder stuurtekens (tab mag), zoals json_escape en json_text van de bash-agent
func cleanText(s string) string {
	s = strings.ToValidUTF8(s, "")
	return strings.Map(func(r rune) rune {
		if (r < 0x20 && r != '\t') || r == 0x7f {
			return -1
		}
		return r
	}, s)
}

func cutRunes(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return string([]rune(s)[:n])
}

func orQ(s string) string {
	if s == "" {
		return "?"
	}
	return s
}

type authFail struct {
	kind  string
	n     int
	t     int64
	users []string
	extra map[string]any
}

// authParse: regels "epoch <tab> programma <tab> bericht" → {events, fails, tries}, zoals auth_parse van de bash-agent
func authParse(lines string) map[string]any {
	events := []map[string]any{}
	tries := []map[string]any{}
	fails := map[string]*authFail{}
	var order []string
	fail := func(key string, t int64, user string, extra map[string]any) {
		f := fails[key]
		if f == nil {
			f = &authFail{kind: strings.SplitN(key, "|", 2)[0]}
			fails[key] = f
			order = append(order, key)
		}
		f.n++
		f.t, f.extra = t, extra
		if user = cleanText(user); user != "" && len(f.users) < 8 {
			for _, u := range f.users {
				if u == user {
					return
				}
			}
			f.users = append(f.users, user)
		}
	}
	event := func(e map[string]any) {
		if len(events) < 300 {
			events = append(events, e)
		}
	}
	try := func(t int64, ip, user, port string) {
		if len(tries) >= 200 {
			return
		}
		e := map[string]any{"t": t, "ip": cleanText(ip), "u": cleanText(user)}
		if p, err := strconv.Atoi(port); err == nil && port != "" {
			e["p"] = p
		}
		tries = append(tries, e)
	}
	num := func(s string) int { n, _ := strconv.Atoi(s); return n }
	for _, line := range strings.Split(lines, "\n") {
		f := strings.SplitN(line, "\t", 3)
		if len(f) < 3 || f[2] == "" || !reDigits.MatchString(f[0]) {
			continue
		}
		t, _ := strconv.ParseInt(f[0], 10, 64)
		msg := f[2]
		switch f[1] {
		case "sshd", "sshd-session":
			if m := reSSHOk.FindStringSubmatch(msg); m != nil {
				event(map[string]any{"t": t, "k": "ssh", "u": cleanText(m[2]), "ip": cleanText(m[3]), "m": cleanText(m[1]), "p": num(m[4])})
			} else if m := reSSHFail.FindStringSubmatch(msg); m != nil {
				// "Failed … for invalid user x": die poging telt al bij de regel "Invalid user x from …"
				if m[2] == "" {
					fail("ssh|"+m[4], t, m[3], map[string]any{"ip": cleanText(m[4])})
					try(t, m[4], m[3], m[5])
				}
			} else if m := reSSHInvalid.FindStringSubmatch(msg); m != nil {
				fail("ssh|"+m[2], t, orQ(m[1]), map[string]any{"ip": cleanText(m[2])})
				try(t, m[2], orQ(m[1]), m[4])
			} else if m := reSSHEnd.FindStringSubmatch(msg); m != nil {
				event(map[string]any{"t": t, "k": "ssh_end", "u": cleanText(m[1]), "ip": cleanText(m[2]), "p": num(m[3])})
			}
		case "login":
			if m := reLoginOk.FindStringSubmatch(msg); m != nil {
				event(map[string]any{"t": t, "k": "console", "u": cleanText(m[1])})
			} else if m := reLoginFail.FindStringSubmatch(msg); m != nil {
				fail("console|"+m[1], t, m[2], map[string]any{"tty": cleanText(m[1])})
			}
		case "sudo":
			if m := reSudo.FindStringSubmatch(msg); m != nil {
				user, why, tty, pwd, as := m[1], strings.TrimSuffix(m[2], " ; "), m[3], m[4], m[5]
				cmd := cleanText(maskSecrets(cutRunes(m[7], 500)))
				if why == "" {
					event(map[string]any{"t": t, "k": "sudo", "u": cleanText(user), "as": cleanText(as), "cmd": cmd, "tty": cleanText(tty), "pwd": cleanText(cutRunes(pwd, 200))})
				} else {
					fail("sudo|"+user+"|"+as+"|"+cutRunes(cmd, 200), t, user, map[string]any{"as": cleanText(as), "cmd": cmd, "why": cleanText(cutRunes(why, 150))})
				}
			}
		case "su", "su-l":
			// Alleen van een echte gebruiker: su vanuit diensten (bv. logrotate) heeft geen naam achter "by"
			if m := reSuOk.FindStringSubmatch(msg); m != nil && m[4] != "" {
				event(map[string]any{"t": t, "k": "su", "u": cleanText(m[4]), "as": cleanText(m[2])})
			} else if m := reSuFail.FindStringSubmatch(msg); m != nil {
				fail("su|"+m[2]+"|"+m[4], t, orQ(m[2]), map[string]any{"as": cleanText(m[4]), "tty": cleanText(m[3])})
			}
		}
	}
	outFails := []map[string]any{}
	for _, k := range order {
		f := fails[k]
		e := map[string]any{"k": f.kind, "n": f.n, "t": f.t, "users": append([]string{}, f.users...)}
		for ek, ev := range f.extra {
			e[ek] = ev
		}
		outFails = append(outFails, e)
	}
	return map[string]any{"events": events, "fails": outFails, "tries": tries}
}

// journalToLines: uitvoer van "journalctl -o short-unix" → "epoch <tab> programma <tab> bericht" (hooguit 20.000 regels)
func journalToLines(out string) string {
	var b strings.Builder
	sc := bufio.NewScanner(strings.NewReader(out))
	sc.Buffer(make([]byte, 0, 64<<10), 1<<20)
	for n := 0; sc.Scan() && n < 20000; n++ {
		line := sc.Text()
		f := strings.Fields(line)
		loc := reThree.FindStringIndex(line)
		if len(f) < 3 || loc == nil {
			continue
		}
		t, _, _ := strings.Cut(f[0], ".")
		fmt.Fprintf(&b, "%s\t%s\t%s\n", t, reSyslogID.ReplaceAllString(f[2], ""), line[loc[1]:])
	}
	return b.String()
}

// fileToLines: regels uit /var/log/secure of auth.log ("Oct 21 10:00:01 host sshd[12]: …" of met een ISO-tijd vooraan);
// de tijd is nu, zoals bij de bash-agent
func fileToLines(chunk string, now int64) string {
	var b strings.Builder
	for _, line := range strings.Split(chunk, "\n") {
		f := strings.Fields(line)
		if len(f) == 0 {
			continue
		}
		h := 5
		if len(f[0]) >= 5 && reDigits.MatchString(f[0][:4]) && f[0][4] == '-' {
			h = 3
		}
		id := ""
		if len(f) >= h {
			id = reSyslogID.ReplaceAllString(f[h-1], "")
		}
		msg := line
		for i := 0; i < h; i++ {
			if loc := reField.FindStringIndex(msg); loc != nil {
				msg = msg[loc[1]:]
			}
		}
		fmt.Fprintf(&b, "%d\t%s\t%s\n", now, id, msg)
	}
	return b.String()
}

// authStatePath: "waar was ik gebleven", hetzelfde bestand als de bash-agent
func authStatePath() string {
	if p := os.Getenv("RZSENSE_AUTHSTATE"); p != "" { // alleen voor de tests
		return p
	}
	return "/var/cache/rzmon-agent/authstate"
}

// authNewLines: de nieuwe regels sinds de vorige keer, en de nieuwe stand (pas bewaren als het rapport is aangenomen
// hoeft niet: net als de bash-agent schuift de stand meteen op)
func authNewLines(conf map[string]string, now int64) (lines, state string) {
	prev := strings.TrimSpace(readText(authStatePath()))
	if own := strings.TrimSpace(readState("authstate")); own != "" {
		prev = own // kon de Go-agent niet in de map van de bash-agent schrijven, dan geldt zijn eigen stand
	}
	_, jErr := os.Stat("/run/systemd/journal")
	if conf["AUTH_SOURCE"] != "file" && jErr == nil {
		if _, code := run(5*time.Second, "journalctl", "--version"); code == 0 {
			since := now - 60
			if s := strings.TrimPrefix(prev, "journal|"); reDigits.MatchString(s) {
				since, _ = strconv.ParseInt(s, 10, 64)
			}
			// Facility auth (4) en authpriv (10): sshd, login, sudo, su
			out, _ := run(40*time.Second, "journalctl", "SYSLOG_FACILITY=4", "SYSLOG_FACILITY=10", "--since", fmt.Sprintf("@%d", since),
				"--until", fmt.Sprintf("@%d", now-1), "-q", "--no-pager", "-o", "short-unix")
			return journalToLines(out), fmt.Sprintf("journal|%d", now)
		}
	}
	logs := conf["AUTH_LOGS"]
	if logs == "" {
		logs = "/var/log/secure /var/log/auth.log"
	}
	for _, file := range strings.Fields(logs) {
		fh, err := os.Open(file)
		if err != nil {
			continue
		}
		defer fh.Close()
		st, err := fh.Stat()
		if err != nil {
			continue
		}
		inode := uint64(0)
		if sys, ok := st.Sys().(*syscall.Stat_t); ok {
			inode = sys.Ino
		}
		size := st.Size()
		p := strings.Split(prev, "|")
		var pos int64
		switch {
		case len(p) < 3 || p[0] != "file":
			pos = size // de eerste keer vanaf het einde
		case p[1] != strconv.FormatUint(inode, 10) || !reDigits.MatchString(p[2]):
			pos = 0
		default:
			if pos, _ = strconv.ParseInt(p[2], 10, 64); pos > size {
				pos = 0
			}
		}
		// Hooguit 5 MB per keer en alleen volledige regels (een half geschreven regel komt de volgende keer)
		buf := make([]byte, min(size-pos, 5000000))
		n, _ := fh.ReadAt(buf, pos)
		buf = buf[:n]
		if i := strings.LastIndexByte(string(buf), '\n'); i >= 0 {
			buf = buf[:i+1]
		} else {
			buf = nil
		}
		return fileToLines(string(buf), now), fmt.Sprintf("file|%d|%d", inode, pos+int64(len(buf)))
	}
	return "", prev
}

// collectAuthLinux: het onderdeel "auth" van het rapport, of nil als RzSense er niet om vraagt of het hier uit staat
func collectAuthLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	if conf["AUTH_EVENTS"] == "0" || !strings.Contains("\n"+readText(filepath.Join(bashCachePath(), "tests.cache")), "\n0\tauth\t1") {
		return nil
	}
	lines, state := authNewLines(conf, time.Now().Unix())
	// De stand in het bestand van de bash-agent (dan gaat hij bij teruggaan verder waar de Go-agent was); lukt dat niet,
	// dan in de eigen map
	tmp := authStatePath() + ".rzsense"
	if os.WriteFile(tmp, []byte(state+"\n"), 0o600) == nil && os.Rename(tmp, authStatePath()) == nil {
		dropState("authstate")
	} else {
		writeState("authstate", state)
	}
	return authParse(lines)
}
