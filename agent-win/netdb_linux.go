//go:build linux

package main

// Databases, WireGuard en netwerkverkeer (0.24.0), zoals de bash-agent (agent/src/76-database.sh en 85-meesturen.sh):
//   - databasechecks (MariaDB/MySQL en PostgreSQL, lokaal of in een container): status, verbindingen, lopende query's,
//     replicatie en grootte. Alleen lezen, nooit data. Een wachtwoord komt alleen versleuteld van RzSense en gaat via de
//     omgeving naar de client, nooit op de opdrachtregel.
//   - WireGuard: per peer de laatste handshake, het verkeer en het endpoint (alleen als root);
//   - netwerkverkeer: de bytes van de echte netwerkkaarten, voor Netwerk → Verkeer.

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// ---------------------------------------------------------------- netwerkverkeer

var nicNameRe = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,15}$`)

// collectNicLinux: {rx, tx, ifs}: de echte netwerkkaarten (met een apparaat erachter, plus bond en wifi). Een poort van
// een bond telt via de bond zelf (niet dubbel).
func collectNicLinux(root string) map[string]any {
	ents, _ := os.ReadDir(root)
	var rx, tx uint64
	ifs := []string{}
	for _, e := range ents {
		n := e.Name()
		d := filepath.Join(root, n)
		if n == "lo" || !nicNameRe.MatchString(n) {
			continue
		}
		if _, err := os.Lstat(filepath.Join(d, "master")); err == nil {
			continue
		}
		if _, err := os.Stat(filepath.Join(d, "device")); err != nil && !strings.HasPrefix(n, "bond") && !strings.HasPrefix(n, "wl") {
			continue
		}
		r, t := strings.TrimSpace(readText(filepath.Join(d, "statistics/rx_bytes"))), strings.TrimSpace(readText(filepath.Join(d, "statistics/tx_bytes")))
		if !reDigits.MatchString(r) || !reDigits.MatchString(t) {
			continue
		}
		a, _ := strconv.ParseUint(r, 10, 64)
		b, _ := strconv.ParseUint(t, 10, 64)
		rx += a
		tx += b
		ifs = append(ifs, n)
	}
	if len(ifs) == 0 {
		return nil
	}
	return map[string]any{"rx": rx, "tx": tx, "ifs": ifs}
}

// ---------------------------------------------------------------- WireGuard

var (
	wgIfaceRe = regexp.MustCompile(`^[A-Za-z0-9_.-]+$`)
	nonAlnum  = regexp.MustCompile(`[^A-Za-z0-9]`)
	wgEpClean = regexp.MustCompile(`[^0-9A-Za-z.:\[\]-]`)
)

// parseWg: uitvoer van "wg show all dump" → per peer de laatste handshake (seconden geleden, -1 = nog nooit), verkeer en endpoint
func parseWg(out string, now int64) []map[string]any {
	res := []map[string]any{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 9 || !wgIfaceRe.MatchString(f[0]) {
			continue
		}
		k := nonAlnum.ReplaceAllString(f[1], "")
		if len(k) > 8 {
			k = k[:8]
		}
		ep := ""
		if f[3] != "(none)" {
			ep = wgEpClean.ReplaceAllString(f[3], "")
		}
		hs := int64(-1)
		if v, err := strconv.ParseInt(f[5], 10, 64); err == nil && reDigits.MatchString(f[5]) && v > 0 {
			hs = now - v
		}
		num := func(s string) json.Number {
			if reDigits.MatchString(s) {
				return json.Number(s)
			}
			return json.Number("0")
		}
		res = append(res, map[string]any{"id": f[0] + ":" + k, "iface": f[0], "peer": k, "endpoint": ep, "hs": hs, "rx": num(f[6]), "tx": num(f[7])})
		if len(res) >= 64 {
			break
		}
	}
	return res
}

// collectWgLinux: de WireGuard-peers van deze server (alleen als root, en alleen als wg er staat), of nil
func collectWgLinux() any {
	if !isRoot() || !haveCmd("wg") {
		return nil
	}
	out, _ := stdoutOf(5*time.Second, "wg", "show", "all", "dump")
	return parseWg(out, time.Now().Unix())
}

// ---------------------------------------------------------------- databases

var (
	dbKeyRe   = regexp.MustCompile(`^[0-9a-f]{12}$`)
	dbCtRe    = regexp.MustCompile(`^(([A-Za-z0-9_.-]{1,32}/)?[A-Za-z0-9][A-Za-z0-9_.-]{0,127})?$`)
	dbUserRe  = regexp.MustCompile(`^[A-Za-z0-9_.@-]{0,80}$`)
	dbNameRe  = regexp.MustCompile(`^[A-Za-z0-9_$-]{0,64}$`)
	dbReplRe  = regexp.MustCompile(`^ *([A-Za-z_]+): (.*)$`)
	hasLetter = regexp.MustCompile(`[A-Za-z]`)
)

func dbEsc(s string) string {
	b := []byte(s)
	for i, c := range b {
		if c >= 1 && c <= 0x1f {
			b[i] = ' '
		}
	}
	if len(b) > 400 {
		b = b[:400]
	}
	return strings.ToValidUTF8(string(b), "")
}

// dbToJSON: de uitvoer van de client ("sleutel <tab> waarde", en "  Veld: waarde" van SHOW SLAVE STATUS) → alleen de
// bekende sleutels; zonder één bekende sleutel is het een fout, met de eerste regel tekst als melding
func dbToJSON(engine string, ms int64, rc int, raw string) map[string]any {
	kv := map[string]string{}
	nl, ns := 0, 0
	errTxt := ""
	keys := dbMyKeys
	if engine == "pgsql" {
		keys = dbPgKeys
	}
	for _, l := range strings.Split(raw, "\n") {
		if k, v, ok := strings.Cut(l, "\t"); ok {
			switch {
			case k == "longq":
				if nl++; nl <= 5 {
					kv[fmt.Sprintf("longq:%d", nl)] = dbEsc(v)
				}
			case strings.HasPrefix(k, "size:"):
				if ns++; ns <= 60 {
					kv[dbEsc(k)] = dbEsc(v)
				}
			case keys.MatchString(k):
				kv[k] = dbEsc(v)
			}
			continue
		}
		if m := dbReplRe.FindStringSubmatch(l); m != nil && engine == "mysql" {
			if dbReplKeys.MatchString(m[1]) {
				kv["repl:"+m[1]] = dbEsc(m[2])
			}
			continue
		}
		if hasLetter.MatchString(l) && errTxt == "" && !strings.HasPrefix(l, "*") {
			errTxt = l
		}
	}
	now := time.Now().Unix()
	if len(kv) == 0 {
		if errTxt == "" {
			errTxt = fmt.Sprintf("geen antwoord (exit %d)", rc)
		}
		return map[string]any{"ok": 0, "ms": ms, "err": dbEsc(errTxt), "at": now}
	}
	return map[string]any{"ok": 1, "ms": ms, "kv": kv, "at": now}
}

// runWith: een commando met extra omgeving en invoer; stdout en stderr samen, met tijdslimiet
func runWith(timeout time.Duration, dir string, env []string, stdin string, name string, args ...string) (string, int) {
	if _, err := exec.LookPath(name); err != nil {
		return "", 127
	}
	cmd := exec.Command(name, args...)
	cmd.Dir = dir
	cmd.Env = append(os.Environ(), env...)
	cmd.Stdin = strings.NewReader(stdin)
	var out bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &out
	if err := cmd.Start(); err != nil {
		return "", 127
	}
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	select {
	case err := <-done:
		if ee, ok := err.(*exec.ExitError); ok {
			return out.String(), ee.ExitCode()
		} else if err != nil {
			return out.String(), 127
		}
		return out.String(), 0
	case <-time.After(timeout):
		_ = cmd.Process.Kill()
		<-done
		return out.String(), 124
	}
}

// het stukje dat ín de container draait: de client zoeken en het wachtwoord uit de omgeving van de container nemen als
// RzSense er geen meegaf (letterlijk dat van de bash-agent)
const dbCtScript = `c=$(command -v mariadb || command -v mysql) || { echo "geen mariadb/mysql-client in de container"; exit 127; }
                     [ -n "$MYSQL_PWD" ] || MYSQL_PWD=${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}; export MYSQL_PWD
                     exec "$c" -N -B --force --connect-timeout=5 -u "${1:-root}" ${2:+"$2"}`

// dbRun: de query's uitvoeren als alleen-lezen client; geeft de uitvoer en de exitcode
func dbRun(eng *ctrEngine, engine, where, ct, usr, pw, dbn, sql string) (string, int) {
	pgArgs := []string{"psql", "-X", "-A", "-t", "-F", "\t", "-q"}
	pgDB := dbn
	if pgDB == "" {
		pgDB = "postgres"
	}
	sql += "\n"
	if where == "container" {
		ctx := "root"
		if c, rest, ok := strings.Cut(ct, "/"); ok {
			ctx, ct = c, rest
		} else if haveCmd("docker") && eng.ok("docker") {
			if _, _, rc := eng.out(5*time.Second, "", nil, "docker", "inspect", ct); rc == 0 {
				ctx = "docker"
			}
		}
		// podman of docker, als root of als die gebruiker (zoals pm in de bash-agent)
		pre, dir := []string{"podman"}, ""
		var env []string
		me := "root"
		if u, err := user.Current(); err == nil {
			me = u.Username
		}
		engName := "podman"
		switch {
		case ctx == "docker":
			pre, engName = []string{"docker"}, "docker"
		case ctx != "root" && ctx != me:
			pre, dir = []string{"runuser", "-u", ctx, "--", "podman"}, "/"
			if u, err := user.Lookup(ctx); err == nil {
				if st, err := os.Stat("/run/user/" + u.Uid); err == nil && st.IsDir() {
					env = append(env, "XDG_RUNTIME_DIR=/run/user/"+u.Uid)
				}
			}
		}
		if !eng.ok(engName) {
			return "", 125
		}
		var args []string
		switch {
		case engine == "mysql":
			env = append(env, "MYSQL_PWD="+pw)
			args = []string{"exec", "-i", "--env", "MYSQL_PWD", ct, "sh", "-c", dbCtScript, "sh", usr, dbn}
		case usr != "":
			env = append(env, "PGPASSWORD="+pw)
			args = append([]string{"exec", "-i", "--env", "PGPASSWORD", ct}, append(pgArgs, "-h", "127.0.0.1", "-U", usr, "-d", pgDB)...)
		default:
			args = append([]string{"exec", "-i", "-u", "postgres", ct}, append(pgArgs, "-d", pgDB)...)
		}
		full := append(pre[1:], args...)
		return runWith(60*time.Second, dir, env, sql, pre[0], full...)
	}
	if engine == "mysql" {
		client := ""
		for _, c := range []string{"mariadb", "mysql"} {
			if haveCmd(c) {
				client = c
				break
			}
		}
		if client == "" {
			return "mariadb/mysql-client ontbreekt op de server\n", 127
		}
		u := usr
		if u == "" {
			u = "root"
		}
		args := []string{"-N", "-B", "--force", "--connect-timeout=5", "-u", u}
		if dbn != "" {
			args = append(args, dbn)
		}
		return runWith(20*time.Second, "", []string{"MYSQL_PWD=" + pw}, sql, client, args...)
	}
	if !haveCmd("psql") {
		return "psql ontbreekt op de server (postgresql-client)\n", 127
	}
	if usr != "" {
		return runWith(20*time.Second, "", []string{"PGPASSWORD=" + pw, "PGCONNECT_TIMEOUT=5"}, sql, "psql", append(pgArgs[1:], "-h", "127.0.0.1", "-U", usr, "-d", pgDB)...)
	}
	if isRoot() {
		return runWith(20*time.Second, "/", []string{"PGCONNECT_TIMEOUT=5"}, sql, "runuser", append([]string{"-u", "postgres", "--"}, append(pgArgs, "-d", pgDB)...)...)
	}
	return "zonder gebruiker kan alleen een agent als root PostgreSQL lezen (als gebruiker postgres)\n", 1
}

// collectDbLinux: het onderdeel "db" van het rapport: per verbinding (sleutel) één meting, hoe veel checks er ook op staan
func collectDbLinux() map[string]any {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !regexp.MustCompile(`(?m)^[0-9]*\tdb\t`).MatchString(tests) {
		return nil
	}
	dir := filepath.Join(dataDir(), "db")
	_ = os.MkdirAll(dir, 0o700)
	eng := &ctrEngine{seen: map[string]bool{}}
	res := map[string]any{}
	seen := map[string]bool{}
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 9 || f[1] != "db" || !reDigits.MatchString(f[0]) || !dbKeyRe.MatchString(f[8]) || seen[f[8]] {
			continue
		}
		for len(f) < 10 {
			f = append(f, "")
		}
		engine, where, ct, usr, pass, dbn, key := f[2], f[3], f[4], f[5], f[6], f[7], f[8]
		seen[key] = true
		if (engine != "mysql" && engine != "pgsql") || (where != "local" && where != "container") {
			continue
		}
		dash := func(s string) string {
			if s == "-" {
				return ""
			}
			return s
		}
		ct, usr, dbn = dash(ct), dash(usr), dash(dbn)
		if !dbCtRe.MatchString(ct) || !dbUserRe.MatchString(usr) || !dbNameRe.MatchString(dbn) || (where == "container" && ct == "") {
			continue
		}
		pw := ""
		if pass != "-" && pass != "" {
			if b, err := base64.StdEncoding.DecodeString(pass); err == nil {
				pw = string(b)
			}
		}
		ival := 60
		if reDigits.MatchString(f[9]) {
			ival = atoiDef(f[9], 60)
		}
		out := filepath.Join(dir, key+".json")
		if st, err := os.Stat(out); err != nil || st.Size() == 0 || time.Since(st.ModTime()) >= time.Duration(ival-5)*time.Second {
			sql := dbMySQL
			if engine == "pgsql" {
				sql = dbPgSQL
			}
			t0 := time.Now()
			raw, rc := dbRun(eng, engine, where, ct, usr, pw, dbn, sql)
			ms := time.Since(t0).Milliseconds()
			// Grootte per database (MariaDB/MySQL) hooguit eens per half uur: information_schema.tables kan traag zijn
			if engine == "mysql" && (strings.HasPrefix(raw, "Uptime\t") || strings.Contains(raw, "\nUptime\t")) {
				sizeFile := filepath.Join(dir, key+".size")
				if st, err := os.Stat(sizeFile); err != nil || st.Size() == 0 || time.Since(st.ModTime()) >= 1795*time.Second {
					sz, _ := dbRun(eng, "mysql", where, ct, usr, pw, dbn, dbMySize)
					var keep []string
					for _, sl := range strings.Split(sz, "\n") {
						if strings.HasPrefix(sl, "size:") {
							keep = append(keep, sl)
						}
					}
					_ = os.WriteFile(sizeFile, []byte(strings.Join(keep, "\n")+"\n"), 0o600)
				}
				raw += readText(sizeFile)
			}
			b, _ := json.Marshal(dbToJSON(engine, ms, rc, raw))
			tmp := out + ".tmp"
			if os.WriteFile(tmp, b, 0o600) == nil {
				_ = os.Rename(tmp, out)
			}
		}
		if b, err := os.ReadFile(out); err == nil && len(b) > 0 && len(b) <= 20000 && json.Valid(b) {
			res[key] = json.RawMessage(b)
		}
	}
	if len(res) == 0 {
		return nil
	}
	return res
}
