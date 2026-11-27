//go:build linux

package main

// Processen (0.10.0), met dezelfde uitkomst als de bash-agent, die "ps -eo pid,ppid,user,etimes,pcpu,rss,comm,args"
// leest. Hier komt het rechtstreeks uit /proc:
//   - procs: per proces-check uit RzSense het aantal, de PID's, CPU (%), geheugen (kB) en wanneer het oudste startte;
//   - proc_list: wat er draait (zonder kernelprocessen), om in RzSense uit te kiezen.

import (
	"encoding/base64"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

type procRow struct {
	pid, ppid int
	user      string
	etimes    int64   // seconden sinds de start
	pcpu      float64 // CPU-tijd gedeeld door de looptijd, in %, zoals ps
	rss       int64   // kB
	comm      string  // naam (hooguit 15 tekens)
	args      string  // de opdrachtregel, met enkele spaties
}

const clockTicks = 100 // USER_HZ: op Linux voor /proc altijd 100

// parseProcStat: de velden die we nodig hebben uit /proc/<pid>/stat (na de naam tussen haakjes)
func parseProcStat(stat string) (comm string, ppid int, ticks, start uint64, rssPages int64, ok bool) {
	a, b := strings.IndexByte(stat, '('), strings.LastIndexByte(stat, ')')
	if a < 0 || b <= a {
		return "", 0, 0, 0, 0, false
	}
	f := strings.Fields(stat[b+1:])
	if len(f) < 22 { // f[0] = status (veld 3) … f[21] = rss (veld 24)
		return "", 0, 0, 0, 0, false
	}
	ppid, _ = strconv.Atoi(f[1])
	ut, _ := strconv.ParseUint(f[11], 10, 64)
	st, _ := strconv.ParseUint(f[12], 10, 64)
	start, _ = strconv.ParseUint(f[19], 10, 64)
	rssPages, _ = strconv.ParseInt(f[21], 10, 64)
	return stat[a+1 : b], ppid, ut + st, start, rssPages, true
}

// procTable: alle processen, zoals ps ze geeft. Kernelprocessen en processen zonder opdrachtregel krijgen args "[naam]".
func procTable(now time.Time) []procRow {
	boot := bootTime(readText("/proc/stat"))
	page := int64(os.Getpagesize()) / 1024
	names := map[string]string{}
	dirs, _ := os.ReadDir("/proc")
	out := make([]procRow, 0, len(dirs))
	for _, d := range dirs {
		pid, err := strconv.Atoi(d.Name())
		if err != nil {
			continue
		}
		base := filepath.Join("/proc", d.Name())
		comm, ppid, ticks, start, rssPages, ok := parseProcStat(readText(filepath.Join(base, "stat")))
		if !ok {
			continue
		}
		r := procRow{pid: pid, ppid: ppid, comm: comm, rss: rssPages * page}
		r.etimes = now.Unix() - (boot + int64(start/clockTicks))
		if r.etimes < 0 {
			r.etimes = 0
		}
		if r.etimes > 0 {
			r.pcpu = float64(ticks) / clockTicks / float64(r.etimes) * 100
		}
		if raw := readText(filepath.Join(base, "cmdline")); raw != "" {
			r.args = strings.Join(strings.Fields(strings.ReplaceAll(raw, "\x00", " ")), " ")
		}
		if r.args == "" {
			r.args = "[" + comm + "]"
		}
		uid := ""
		for _, l := range strings.Split(readText(filepath.Join(base, "status")), "\n") {
			if v, ok := strings.CutPrefix(l, "Uid:"); ok {
				if f := strings.Fields(v); len(f) > 1 {
					uid = f[1] // de effectieve gebruiker, zoals ps "user"
				}
				break
			}
		}
		if _, seen := names[uid]; !seen {
			names[uid] = uid
			if u, err := user.LookupId(uid); err == nil && u.Username != "" {
				names[uid] = u.Username
			}
		}
		r.user = names[uid]
		out = append(out, r)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].pid < out[j].pid })
	return out
}

// visible: wat de bash-agent meetelt: geen kernelprocessen (ouder 2, of een naam tussen blokhaken) en niet de agent zelf
func visibleProcs(rows []procRow, self int) []procRow {
	out := rows[:0:0]
	for _, r := range rows {
		if r.ppid == 2 || r.pid == 2 || r.pid == self || r.ppid == self || strings.HasPrefix(r.args, "[") {
			continue
		}
		out = append(out, r)
	}
	return out
}

// procList: [naam, aantal, gebruiker, voorbeeld van de opdrachtregel], hooguit 200 namen
func procList(rows []procRow) [][]any {
	type agg struct {
		n        int
		user, ex string
	}
	by := map[string]*agg{}
	var order []string
	for _, r := range rows {
		a, ok := by[r.comm]
		if !ok {
			ex := r.args
			if len(ex) > 120 {
				ex = ex[:120]
			}
			a = &agg{user: r.user, ex: ex}
			by[r.comm] = a
			order = append(order, r.comm)
		}
		a.n++
	}
	sort.Strings(order)
	if len(order) > 200 {
		order = order[:200]
	}
	out := make([][]any, 0, len(order))
	for _, k := range order {
		out = append(out, []any{k, by[k].n, by[k].user, by[k].ex})
	}
	return out
}

type procCheck struct {
	id, mode, val, user string
}

// procChecksFromCache: regels "id <tab> proc <tab> name|pattern <tab> waarde (base64) <tab> gebruiker" uit de testlijst
func procChecksFromCache(testsCache string) []procCheck {
	var out []procCheck
	for _, l := range strings.Split(testsCache, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 4 || f[1] != "proc" {
			continue
		}
		if _, err := strconv.Atoi(f[0]); err != nil {
			continue
		}
		v, err := base64.StdEncoding.DecodeString(f[3])
		if err != nil || len(v) == 0 {
			continue
		}
		c := procCheck{id: f[0], mode: f[2], val: string(v)}
		if len(f) > 4 {
			c.user = f[4]
		}
		out = append(out, c)
	}
	return out
}

// procMatch: hoort dit proces bij de check? Op naam: de naam van het proces, of het programma uit de opdrachtregel
// (zonder map en zonder dubbele punt erachter), of de eerste 15 tekens van de gezochte naam. Op patroon: de opdrachtregel.
func procMatch(r procRow, c procCheck, re *regexp.Regexp) bool {
	if c.user != "" && r.user != c.user {
		return false
	}
	if c.mode == "pattern" {
		return re != nil && re.MatchString(r.args)
	}
	first, _, _ := strings.Cut(r.args, " ")
	if i := strings.LastIndexByte(first, '/'); i >= 0 {
		first = first[i+1:]
	}
	first = strings.TrimSuffix(first, ":")
	short := c.val
	if len(short) > 15 {
		short = short[:15]
	}
	return r.comm == c.val || first == c.val || short == r.comm
}

// procChecks: id → {n, pids, cpu, rss, start}
func procChecks(rows []procRow, checks []procCheck, now time.Time) map[string]any {
	out := map[string]any{}
	for _, c := range checks {
		var re *regexp.Regexp
		if c.mode == "pattern" {
			var err error
			if re, err = regexp.Compile(c.val); err != nil {
				re, _ = regexp.CompilePOSIX(c.val)
			}
		}
		n, cpu, rss, age := 0, 0.0, int64(0), int64(0)
		var pids []string
		for _, r := range rows {
			if !procMatch(r, c, re) {
				continue
			}
			n++
			pids = append(pids, strconv.Itoa(r.pid))
			cpu += float64(int(r.pcpu*10+0.5)) / 10 // ps toont één decimaal; de bash-agent telt die op
			rss += r.rss
			if r.etimes > age {
				age = r.etimes
			}
		}
		p := strings.Join(pids, " ")
		if len(p) > 200 {
			p = p[:200]
		}
		var start any
		if n > 0 {
			start = now.Unix() - age
		}
		out[c.id] = map[string]any{"n": n, "pids": p, "cpu": float64(int(cpu*10+0.5)) / 10, "rss": rss, "start": start}
	}
	return out
}

// collectProcsLinux: (procs, proc_list) zoals in het rapport van de bash-agent
func collectProcsLinux() (map[string]any, [][]any) {
	now := time.Now()
	rows := visibleProcs(procTable(now), os.Getpid())
	checks := procChecksFromCache(readText(filepath.Join(bashCachePath(), "tests.cache")))
	return procChecks(rows, checks, now), procList(rows)
}

func (r procRow) String() string {
	return fmt.Sprintf("%d %d %s %d %.1f %d %s %s", r.pid, r.ppid, r.user, r.etimes, r.pcpu, r.rss, r.comm, r.args)
}
