//go:build linux

package main

// IDS, derde stap (0.29.0): ingrijpen. Dezelfde regels als de bash-agent (agent/src/60-ids-en-edr.sh): de eigen
// nftables-tabel "inet rzmon" met de blokkades uit RzSense, bekende aanvallers en de lokaas-poort; een proces stoppen;
// de server isoleren (tabel "inet rzmon_iso") en een account vergrendelen.
//
// Twee standen:
//   - in de proef (naast de bash-agent) berekent de Go-agent alleen wát hij zou zetten en legt dat naast wat de
//     bash-agent zette (de vingerafdruk van de lijst). Hij raakt de firewall, processen en accounts dan niet aan;
//   - pas als hij het werk heeft overgenomen, voert hij het uit: dezelfde tabellen met dezelfde namen, zodat teruggaan
//     naar de bash-agent zonder gat verloopt.
// De agent blokkeert nooit het adres van RzSense zelf, en isoleren vervalt vanzelf op de afgesproken tijd.

import (
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
	"syscall"
	"time"
)

// ---------- het logboek van wat de agent zelf verandert (act) ----------

var actN = 0

// actLog: een regel voor het logboek in RzSense (Agent Manager → Logboek). ok: 1 gelukt, 0 mislukt, 2 info.
func actLog(part string, ok int, action, detail string) {
	actN++
	clean := func(s string, max int) string {
		return cleanText(cutRunes(strings.NewReplacer("\n", " ", "\t", " ").Replace(s), max))
	}
	a, _ := json.Marshal(clean(action, 80))
	d, _ := json.Marshal(clean(detail, 900))
	now := time.Now().Unix()
	line := fmt.Sprintf(`{"id":"%d-%d-%d-g","t":%d,"m":"%s","ok":%d,"a":%s,"d":%s}`+"\n", now, os.Getpid(), actN, now, regexp.MustCompile(`[^a-z]`).ReplaceAllString(part, ""), ok, a, d)
	file := filepath.Join(dataDir(), "act.log")
	if fh, err := os.OpenFile(file, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
		_, _ = fh.WriteString(line)
		fh.Close()
	}
	if l := lines(readText(file)); len(l) > 2000 {
		_ = os.WriteFile(file, []byte(strings.Join(l[len(l)-2000:], "\n")+"\n"), 0o600)
	}
}

// actReport: hooguit 100 regels voor dit rapport; actSent haalt ze weg als het rapport is aangenomen
func actReport() []json.RawMessage {
	out := []json.RawMessage{}
	for i, l := range lines(readText(filepath.Join(dataDir(), "act.log"))) {
		if i >= 100 {
			break
		}
		if json.Valid([]byte(l)) {
			out = append(out, json.RawMessage(l))
		}
	}
	return out
}

func actSent() {
	file := filepath.Join(dataDir(), "act.log")
	l := lines(readText(file))
	if len(l) <= 100 {
		_ = os.Remove(file)
		return
	}
	_ = os.WriteFile(file, []byte(strings.Join(l[100:], "\n")+"\n"), 0o600)
}

// ---------- regels uit de testlijst ----------

// tabRead: een regel lezen zoals "IFS=<tab> read -r a b c …" in bash: een rij tabs telt als één scheiding, en de laatste
// variabele krijgt de rest
func tabRead(line string, n int) []string {
	f := strings.FieldsFunc(line, func(r rune) bool { return r == '\t' })
	out := make([]string, n)
	for i := 0; i < n && i < len(f); i++ {
		out[i] = f[i]
	}
	if len(f) > n {
		out[n-1] = strings.Join(f[n-1:], "\t")
	}
	return out
}

func idsMode(tests string) string {
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 3 && f[0] == "0" && f[1] == "ids" {
			return f[2]
		}
	}
	return ""
}

func hasLinePrefix(tests, prefix string) bool {
	return strings.HasPrefix(tests, prefix) || strings.Contains(tests, "\n"+prefix)
}

// urlHost: de hostnaam uit een adres, zoals de bash-agent hem afleidt
func urlHost(u string) string {
	h := u
	if i := strings.Index(h, "://"); i >= 0 {
		h = h[i+3:]
	}
	if i := strings.Index(h, "/"); i >= 0 {
		h = h[:i]
	}
	if i := strings.LastIndex(h, ":"); i >= 0 {
		h = h[:i]
	}
	return strings.TrimSuffix(strings.TrimPrefix(h, "["), "]")
}

// hostAddrs: de adressen bij een hostnaam (getent ahosts, gesorteerd en zonder dubbele)
func hostAddrs(h string) []string {
	if h == "" {
		return nil
	}
	out, _ := stdoutOf(10*time.Second, "getent", "ahosts", h)
	l := []string{}
	for _, r := range lines(out) {
		if f := strings.Fields(r); len(f) > 0 {
			l = append(l, f[0])
		}
	}
	return sortedUnique(l)
}

// ---------- de firewall: blokkades, bekende aanvallers en de lokaas-poort ----------

var (
	fwKn4Re  = regexp.MustCompile(`^[0-9./-]+$`)
	fwKn6Re  = regexp.MustCompile(`^[0-9a-fA-F:./-]+$`)
	fwV4Re   = regexp.MustCompile(`^[0-9]{1,3}(\.[0-9]{1,3}){3}$`)
	fwV6Re   = regexp.MustCompile(`^[0-9a-fA-F:]+$`)
	fwNumRe  = regexp.MustCompile(`^[0-9]+$`)
	fwHitRe  = regexp.MustCompile(`([0-9a-fA-F:.]+) counter packets ([0-9]+)`)
	fwElemRe = regexp.MustCompile(`elements = \{([^}]*)\}`)
	fwIPRe   = regexp.MustCompile(`^[0-9a-fA-F:.]+$`)
)

type fwPlan struct {
	mode                   string // learn, block of leeg (IDS uit)
	want4, want6, kn4, kn6 string // zoals de bash-agent ze opbouwt: "adres, adres, "
	outblock               bool
	decoy, decoyErr        string
	state, err             string // unsupported, disabled, error (zonder nft) of leeg
	build                  bool   // de tabel hoort (opnieuw) gezet te worden
	remove                 bool   // de tabel hoort weg
}

func (p fwPlan) sig() string {
	ob := "0"
	if p.outblock {
		ob = "1"
	}
	h := sha1.Sum([]byte(p.want4 + "|" + p.want6 + "|" + p.decoy + "|" + p.kn4 + "|" + p.kn6 + "|" + ob))
	return hex.EncodeToString(h[:])[:16]
}

func (p fwPlan) count() (int, int) {
	return strings.Count(p.want4+p.want6, ","), strings.Count(p.kn4+p.kn6, ",")
}

// script: de hele tabel in één keer (met of zonder tellers per adres)
func (p fwPlan) script(counter string) string {
	el := func(s string) string {
		if s == "" {
			return ""
		}
		return " elements = { " + strings.TrimSuffix(s, ", ") + " };"
	}
	var b strings.Builder
	b.WriteString("table inet rzmon\ndelete table inet rzmon\ntable inet rzmon {\n")
	fmt.Fprintf(&b, "  set bl4 { type ipv4_addr; %s%s }\n", counter, el(p.want4))
	fmt.Fprintf(&b, "  set bl6 { type ipv6_addr; %s%s }\n", counter, el(p.want6))
	fmt.Fprintf(&b, "  set kn4 { type ipv4_addr; flags interval; auto-merge;%s }\n", el(p.kn4))
	fmt.Fprintf(&b, "  set kn6 { type ipv6_addr; flags interval; auto-merge;%s }\n", el(p.kn6))
	b.WriteString("  set dc4 { type ipv4_addr; flags dynamic,timeout; timeout 1h; }\n")
	b.WriteString("  set dc6 { type ipv6_addr; flags dynamic,timeout; timeout 1h; }\n")
	b.WriteString("  chain pre { type filter hook prerouting priority -150; policy accept; ip saddr @bl4 drop; ip6 saddr @bl6 drop; ip saddr @kn4 drop; ip6 saddr @kn6 drop; }\n")
	dc := ""
	if p.decoy != "" {
		dc = fmt.Sprintf(` iifname != "lo" meta nfproto ipv4 tcp dport %s add @dc4 { ip saddr } drop; iifname != "lo" meta nfproto ipv6 tcp dport %s add @dc6 { ip6 saddr } drop;`, p.decoy, p.decoy)
	}
	fmt.Fprintf(&b, "  chain input { type filter hook input priority -5; policy accept; ip saddr @bl4 drop; ip6 saddr @bl6 drop; ip saddr @kn4 drop; ip6 saddr @kn6 drop;%s }\n", dc)
	if p.outblock {
		b.WriteString("  chain output { type filter hook output priority -5; policy accept; ip daddr @kn4 drop; ip6 daddr @kn6 drop; }\n")
		b.WriteString("  chain out_fw { type filter hook forward priority -5; policy accept; ip daddr @kn4 drop; ip6 daddr @kn6 drop; }\n")
	}
	b.WriteString("}\n")
	return b.String()
}

type fwEnv struct {
	conf      map[string]string
	url       string
	truenas   bool // midclt aanwezig
	haveNft   bool
	tableLive func() bool
	portBusy  func(string) bool
	addrs     func(string) []string
	now       int64
}

// fwPlanFrom: wat er volgens de testlijst hoort te gebeuren (nog niets uitvoeren)
func fwPlanFrom(tests string, e fwEnv) fwPlan {
	p := fwPlan{mode: idsMode(tests)}
	tnBlock := !e.truenas || hasLinePrefix(tests, "0\ttruenas_fw\t1")
	if p.mode != "" {
		for _, l := range strings.Split(tests, "\n") {
			f := strings.Split(l, "\t")
			if len(f) >= 3 && f[0] == "0" && f[1] == "decoy" && fwNumRe.MatchString(f[2]) {
				if n, err := strconv.Atoi(f[2]); err == nil && n >= 1 && n <= 65535 {
					p.decoy = f[2]
					break
				}
			}
		}
	}
	if p.decoy != "" {
		if !tnBlock || !e.haveNft {
			p.decoy = ""
		} else if e.portBusy(p.decoy) {
			p.decoyErr = "poort " + p.decoy + " is hier in gebruik"
			p.decoy = ""
		}
	}
	p.outblock = p.mode == "block" && hasLinePrefix(tests, "0\toutblock\t1")
	switch {
	case p.mode == "block" && !tnBlock:
		p.state, p.err = "unsupported", "TrueNAS: alleen detecteren"
	case p.mode == "block" && e.conf["IDS_BLOCK"] == "0":
		p.state, p.err = "disabled", "IDS_BLOCK=0 op deze server"
		p.build = p.decoy != ""
	case p.mode == "block" && !e.haveNft:
		p.state, p.err = "error", "nft (nftables) ontbreekt"
	case p.mode == "block":
		host := urlHost(e.url)
		rz := map[string]bool{host: true}
		for _, a := range e.addrs(host) {
			rz[a] = true
		}
		have := map[string]bool{}
		for _, l := range strings.Split(tests, "\n") {
			f := tabRead(l, 4)
			if f[0] != "0" {
				continue
			}
			ip, until := f[2], f[3]
			if f[1] == "knet" {
				if fwKn4Re.MatchString(ip) {
					p.kn4 += ip + ", "
				} else if fwKn6Re.MatchString(ip) {
					p.kn6 += ip + ", "
				}
				continue
			}
			if f[1] != "block" {
				continue
			}
			if fwNumRe.MatchString(until) && until != "0" {
				if n, err := strconv.ParseInt(until, 10, 64); err != nil || n <= e.now {
					continue // verlopen
				}
			}
			if rz[ip] || have[ip] { // nooit RzSense zelf; en geen adres twee keer
				continue
			}
			if fwV4Re.MatchString(ip) {
				p.want4 += ip + ", "
				have[ip] = true
			} else if fwV6Re.MatchString(ip) && strings.Contains(ip, ":") {
				p.want6 += ip + ", "
				have[ip] = true
			}
		}
		p.build = true
	case p.decoy != "":
		p.build = true // leren: alleen de lokaas-poort, er wordt niets geblokkeerd
	case hasLinePrefix(tests, "0\ttick\t") && e.haveNft && e.tableLive():
		p.remove = true // leren zonder lokaas-poort, of het IDS staat uit: niets meer blokkeren
	}
	return p
}

func nftRun(script string) (string, bool) {
	cmd := exec.Command("nft", "-f", "-")
	cmd.Stdin = strings.NewReader(script)
	cmd.Env = append(os.Environ(), "LC_ALL=C")
	done := make(chan struct{})
	var out []byte
	var err error
	go func() { _, err = cmd.Output(); close(done) }()
	select {
	case <-done:
	case <-time.After(60 * time.Second):
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
		}
		<-done
		return "te laat", false
	}
	if ee, ok := err.(*exec.ExitError); ok {
		out = ee.Stderr
	}
	return string(out), err == nil
}

func nftTableLive(name string) bool {
	_, rc := run(10*time.Second, "nft", "list", "table", "inet", name)
	return rc == 0
}

// nftSetAddrs: de adressen in een set (hooguit 50), zoals de bash-agent de lokaas-treffers leest
func nftSetAddrs(set string) []string {
	out, _ := stdoutOf(10*time.Second, "nft", "list", "set", "inet", "rzmon", set)
	m := fwElemRe.FindStringSubmatch(strings.ReplaceAll(out, "\n", ""))
	res := []string{}
	if m == nil {
		return res
	}
	for _, e := range strings.Split(m[1], ",") {
		if f := strings.Fields(e); len(f) > 0 && fwIPRe.MatchString(f[0]) && len(res) < 50 {
			res = append(res, f[0])
		}
	}
	return res
}

func fwLiveEnv(c *Config, conf map[string]string) fwEnv {
	return fwEnv{conf: conf, url: c.URL, truenas: haveCmd("midclt"), haveNft: haveCmd("nft"), now: time.Now().Unix(), addrs: hostAddrs,
		tableLive: func() bool { return nftTableLive("rzmon") },
		portBusy: func(port string) bool {
			out, _ := stdoutOf(10*time.Second, "ss", "-Htln", "sport = :"+port)
			return strings.TrimSpace(out) != ""
		}}
}

// fwDryRun: voor de proef: wat de Go-agent zou zetten, naast de vingerafdruk van wat de bash-agent zette. Raakt niets aan.
func fwDryRun(tests string, c *Config, conf map[string]string) map[string]any {
	e := fwLiveEnv(c, conf)
	e.tableLive = func() bool { return false } // in de proef kijkt de Go-agent niet in de firewall
	p := fwPlanFrom(tests, e)
	n, kn := p.count()
	out := map[string]any{"n": n, "kn": kn, "decoy": p.decoy, "state": p.state, "build": p.build}
	if p.build {
		out["sig"] = p.sig()
		out["bash_sig"] = strings.TrimSpace(readText(filepath.Join(bashCacheRoot(), "ids", "fw.sig")))
	}
	return out
}

type fwResult struct {
	fw       map[string]any
	decoy    []string
	decoyErr string
	sent4    []string
	sent6    []string
}

// fwApply: de firewall zetten zoals de bash-agent (alleen als de Go-agent het werk doet)
func fwApply(tests string, c *Config, conf map[string]string) fwResult {
	dir := idsDir()
	_ = os.MkdirAll(dir, 0o700)
	p := fwPlanFrom(tests, fwLiveEnv(c, conf))
	r := fwResult{decoy: []string{}, decoyErr: p.decoyErr}
	if p.decoy != "" && nftTableLive("rzmon") {
		r.sent4, r.sent6 = nftSetAddrs("dc4"), nftSetAddrs("dc6")
		r.decoy = append(append(r.decoy, r.sent4...), r.sent6...)
	}
	fwErr := ""
	if p.build {
		sig := p.sig()
		if !nftTableLive("rzmon") || strings.TrimSpace(readText(filepath.Join(dir, "fw.sig"))) != sig {
			errText, ok := nftRun(p.script("counter;"))
			if !ok {
				errText, ok = nftRun(p.script(""))
			}
			if ok {
				_ = os.WriteFile(filepath.Join(dir, "fw.sig"), []byte(sig+"\n"), 0o600)
				n, kn := p.count()
				detail := fmt.Sprintf("%d geblokkeerde IP's · %d netten van bekende aanvallers", n, kn)
				if p.decoy != "" {
					detail += " · lokaas-poort " + p.decoy
				}
				if p.outblock {
					detail += " · ook uitgaand"
				}
				actLog("firewall", 1, "Firewall bijgewerkt", detail)
			} else {
				fwErr = jsonText([]byte(errText), 200)
				if strings.TrimSpace(readText(filepath.Join(dir, "fw.errsig"))) != sig {
					actLog("firewall", 0, "Firewall bijwerken mislukt", strings.TrimRight(cutRunes(errText, 200), "\n"))
					_ = os.WriteFile(filepath.Join(dir, "fw.errsig"), []byte(sig+"\n"), 0o600)
				}
			}
		}
	}
	switch {
	case p.state != "":
		r.fw = map[string]any{"state": p.state, "err": p.err}
	case p.mode == "block" && fwErr != "":
		r.fw = map[string]any{"state": "error", "err": fwErr}
	case p.mode == "block":
		hits := map[string]int{}
		out, _ := stdoutOf(15*time.Second, "nft", "list", "table", "inet", "rzmon")
		for _, m := range fwHitRe.FindAllStringSubmatch(out, -1) {
			hits[m[1]] = atoiDef(m[2], 0)
		}
		n, _ := p.count()
		r.fw = map[string]any{"state": "active", "n": n, "hits": hits}
	default:
		if p.build && fwErr != "" {
			r.decoyErr = fwErr
		}
		if p.remove {
			_, _ = run(10*time.Second, "nft", "delete", "table", "inet", "rzmon")
			_ = os.Remove(filepath.Join(dir, "fw.sig"))
		}
		r.fw = map[string]any{"state": "off"}
	}
	return r
}

// decoySent: de lokaas-treffers zijn aangekomen: uit de set halen
func decoySent(r fwResult) {
	if len(r.sent4) > 0 {
		_, _ = run(10*time.Second, "nft", "delete", "element", "inet", "rzmon", "dc4", "{ "+strings.Join(r.sent4, ",")+" }")
	}
	if len(r.sent6) > 0 {
		_, _ = run(10*time.Second, "nft", "delete", "element", "inet", "rzmon", "dc6", "{ "+strings.Join(r.sent6, ",")+" }")
	}
}

// ---------- een proces stoppen (regel "0 <tab> kill <tab> pid <tab> starttijd") ----------

var ownUnitRe = regexp.MustCompile(`/(rzmon|rzsense)-[^/]*\.service`)

func procStart(pid string) string {
	f := strings.Fields(readText("/proc/" + pid + "/stat"))
	if len(f) >= 22 {
		return f[21]
	}
	return ""
}

// killWanted: de processen die RzSense wil laten stoppen. Alleen als de starttijd klopt (dus nooit een ander proces dat
// het nummer later kreeg), en nooit iets van RzSense of de agent zelf.
func killWanted(tests string) [][]any {
	dir := idsDir()
	out := [][]any{}
	if b := strings.TrimSpace(readText(filepath.Join(dir, "killed.pending"))); b != "" { // wat de endpoint-bewaking meteen stopte
		var pend [][]any
		if json.Unmarshal([]byte("["+strings.TrimSuffix(b, ",")+"]"), &pend) == nil {
			out = append(out, pend...)
		}
		_ = os.Remove(filepath.Join(dir, "killed.pending"))
	}
	if idsMode(tests) != "block" {
		return out
	}
	for _, l := range strings.Split(tests, "\n") {
		f := tabRead(l, 4)
		if f[0] != "0" || f[1] != "kill" || !fwNumRe.MatchString(f[2]) || !fwNumRe.MatchString(f[3]) {
			continue
		}
		mark := filepath.Join(dir, "killed."+f[2]+"."+f[3])
		if _, err := os.Stat(mark); err == nil {
			continue // al gedaan; de regel kan nog even in de lijst staan
		}
		res := "weg"
		if procStart(f[2]) == f[3] {
			pid, _ := strconv.Atoi(f[2])
			switch {
			case ownUnitRe.MatchString(readText("/proc/" + f[2] + "/cgroup")), pid == os.Getpid(), pid <= 1:
				res = "eigen"
			case syscall.Kill(pid, syscall.SIGKILL) == nil:
				res = "ok"
			default:
				res = "fout"
			}
		}
		_ = os.WriteFile(mark, nil, 0o600)
		pidN, _ := strconv.Atoi(f[2])
		startN, _ := strconv.ParseInt(f[3], 10, 64)
		out = append(out, []any{pidN, startN, res})
	}
	if ents, err := os.ReadDir(dir); err == nil { // markeringen ouder dan een dag weg
		for _, e := range ents {
			if strings.HasPrefix(e.Name(), "killed.") && e.Name() != "killed.pending" {
				if st, err := e.Info(); err == nil && time.Since(st.ModTime()) > 24*time.Hour {
					_ = os.Remove(filepath.Join(dir, e.Name()))
				}
			}
		}
	}
	return out
}

// ---------- isoleren en een account vergrendelen ----------

var (
	isoV4Re   = regexp.MustCompile(`^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$`)
	isoV6Re   = regexp.MustCompile(`^[0-9a-fA-F:]+(/[0-9]{1,3})?$`)
	ulNameRe  = regexp.MustCompile(`^[a-z_][a-z0-9._-]{0,31}$`)
	isoLineRe = regexp.MustCompile(`^0\tisolate\t`)
)

type isoPlan struct {
	until  string
	valid  bool // er is een regel met een tijd
	active bool // de tijd is nog niet voorbij
	a4, a6 string
}

func (p isoPlan) sig() string {
	h := sha1.Sum([]byte(p.a4 + "|" + p.a6))
	return hex.EncodeToString(h[:])[:16]
}

func (p isoPlan) script() string {
	el := func(s string) string {
		if s == "" {
			return ""
		}
		return " elements = { " + strings.TrimSuffix(s, ", ") + " };"
	}
	return "table inet rzmon_iso\ndelete table inet rzmon_iso\ntable inet rzmon_iso {\n" +
		"  set ok4 { type ipv4_addr; flags interval; auto-merge;" + el(p.a4) + " }\n" +
		"  set ok6 { type ipv6_addr; flags interval; auto-merge;" + el(p.a6) + " }\n" +
		"  chain input { type filter hook input priority -10; policy drop; iif lo accept; ip saddr @ok4 accept; ip6 saddr @ok6 accept; icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-advert } accept; }\n" +
		"  chain output { type filter hook output priority -10; policy drop; oif lo accept; ip daddr @ok4 accept; ip6 daddr @ok6 accept; icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit } accept; }\n" +
		"  chain iso_fw { type filter hook forward priority -10; policy drop; }\n}\n"
}

// isoPlanFrom: wat er bij isoleren open blijft: de netwerken uit RzSense, RzSense zelf (en het reserve-adres) en de
// DNS-servers
func isoPlanFrom(tests string, urls []string, resolv string, now int64, addrs func(string) []string) isoPlan {
	p := isoPlan{}
	allow := ""
	for _, l := range strings.Split(tests, "\n") {
		if isoLineRe.MatchString(l) {
			f := strings.Split(l, "\t")
			p.until = f[2]
			if len(f) >= 4 {
				allow = f[3]
			}
			break
		}
	}
	p.valid = fwNumRe.MatchString(p.until)
	if n, err := strconv.ParseInt(p.until, 10, 64); !p.valid || err != nil || n <= now {
		return p
	}
	p.active = true
	list := strings.Fields(allow)
	for _, u := range urls {
		if u == "" {
			continue
		}
		h := urlHost(u)
		list = append(append(list, h), addrs(h)...)
	}
	for _, l := range strings.Split(resolv, "\n") {
		if f := strings.Fields(l); len(f) >= 2 && f[0] == "nameserver" {
			list = append(list, f[1])
		}
	}
	seen := map[string]bool{}
	for _, n := range list {
		if seen[n] {
			continue
		}
		if isoV4Re.MatchString(n) {
			p.a4 += n + ", "
			seen[n] = true
		} else if isoV6Re.MatchString(n) && strings.Contains(n, ":") {
			p.a6 += n + ", "
			seen[n] = true
		}
	}
	return p
}

// isoApply: isoleren of weer opheffen (alleen als de Go-agent het werk doet en als root)
func isoApply(tests string, c *Config) map[string]any {
	dir := filepath.Join(dataDir(), "iso")
	_ = os.MkdirAll(dir, 0o700)
	p := isoPlanFrom(tests, []string{c.URL, c.URLFallback}, readText("/etc/resolv.conf"), time.Now().Unix(), hostAddrs)
	sigFile := filepath.Join(dir, "sig")
	switch {
	case p.active && haveCmd("nft"):
		sig := p.sig()
		if !nftTableLive("rzmon_iso") || strings.TrimSpace(readText(sigFile)) != sig {
			errText, ok := nftRun(p.script())
			if ok {
				_ = os.WriteFile(sigFile, []byte(sig+"\n"), 0o600)
			} else {
				_ = os.WriteFile(filepath.Join(dir, "err"), []byte(errText), 0o600)
			}
		}
		if nftTableLive("rzmon_iso") {
			return map[string]any{"state": "active", "until": atoiDef(p.until, 0)}
		}
		return map[string]any{"state": "error", "err": jsonText([]byte(readText(filepath.Join(dir, "err"))), 200)}
	case haveCmd("nft") && nftTableLive("rzmon_iso"):
		// de tijd is voorbij, of RzSense heeft het opgeheven: alles weer open
		_, _ = run(10*time.Second, "nft", "delete", "table", "inet", "rzmon_iso")
		_ = os.Remove(sigFile)
		return map[string]any{"state": "off"}
	default:
		if _, err := os.Stat(sigFile); err == nil || p.valid {
			_ = os.Remove(sigFile)
			return map[string]any{"state": "off"}
		}
	}
	return nil
}

// userLocks: een account vergrendelen (en de processen ervan stoppen) of weer vrijgeven; nooit root
func userLocks(tests string) [][]string {
	dir := filepath.Join(dataDir(), "iso")
	_ = os.MkdirAll(dir, 0o700)
	out := [][]string{}
	for _, l := range strings.Split(tests, "\n") {
		f := tabRead(l, 4)
		name, act := f[2], f[3]
		if f[0] != "0" || f[1] != "userlock" || !ulNameRe.MatchString(name) || name == "root" || (act != "lock" && act != "unlock") {
			continue
		}
		mark := filepath.Join(dir, "ul."+name+"."+act)
		if _, err := os.Stat(mark); err == nil {
			continue // al gedaan
		}
		res := "fout"
		if _, rc := run(10*time.Second, "getent", "passwd", name); rc != 0 {
			res = "onbekend"
		} else if act == "lock" {
			if _, rc := runOutside(20*time.Second, "usermod", "-L", "-e", "1", name); rc == 0 { // buiten de afscherming: /etc is daar alleen-lezen
				_, _ = run(20*time.Second, "pkill", "-KILL", "-u", name)
				res = "ok"
			}
		} else if _, rc := runOutside(20*time.Second, "usermod", "-U", "-e", "", name); rc == 0 {
			res = "ok"
		}
		for _, a := range []string{"lock", "unlock"} {
			_ = os.Remove(filepath.Join(dir, "ul."+name+"."+a))
		}
		_ = os.WriteFile(mark, nil, 0o600)
		out = append(out, []string{name, act, res})
	}
	return out
}

// isoDryRun: voor de proef: wat er bij isoleren open zou blijven, naast de vingerafdruk van de bash-agent
func isoDryRun(tests string, c *Config) map[string]any {
	p := isoPlanFrom(tests, []string{c.URL, c.URLFallback}, readText("/etc/resolv.conf"), time.Now().Unix(), hostAddrs)
	if !p.active {
		return nil
	}
	return map[string]any{"sig": p.sig(), "bash_sig": strings.TrimSpace(readText(filepath.Join(bashCacheRoot(), "iso", "sig"))),
		"n": strings.Count(p.a4+p.a6, ",")}
}

// ---------- bestanden bewaken: alleen de wijzigingen melden ----------

type fimRun struct {
	json   map[string]any
	commit func()
}

// fimDelta: de bewaakte bestanden naast de vorige stand in dir (state, meta, roots, rehash). c = nieuw of veranderd,
// b = onder een nieuw bewaakt pad, d = verdwenen, u = niet meer bewaakt, full = de hele lijst. De nieuwe stand wordt pas
// bewaard als RzSense het rapport heeft aangenomen (commit).
func fimDelta(c *fimConf, dir string) fimRun {
	_ = os.MkdirAll(dir, 0o700)
	state := filepath.Join(dir, "state")
	full := true
	old := map[string][3]string{}
	oldList := []fimEntry{}
	oldRoots := map[string]bool{}
	if _, err := os.Stat(state); err == nil && strings.TrimSpace(readText(filepath.Join(dir, "meta"))) == c.epoch {
		full = false
		old, oldList = fimStateRead(state)
		for _, r := range strings.Split(readText(filepath.Join(dir, "roots")), "\n") {
			oldRoots[r] = true
		}
	}
	// de dagelijkse controle (alles opnieuw gehasht): gaat voor op de vorige stand, als grootte en tijd nog kloppen
	rehash := filepath.Join(filepath.Dir(dir), "rehash")
	usedRehash := false
	if rs, err := os.Stat(rehash); err == nil {
		if us, err := os.Stat(rehash + ".used"); err != nil || rs.ModTime().After(us.ModTime()) {
			usedRehash = true
			for _, l := range strings.Split(readText(rehash), "\n") {
				if f := strings.Split(l, "\t"); len(f) >= 4 {
					old[f[0]] = [3]string{f[1], f[2], f[3]}
				}
			}
		}
	}
	list := fimHash(fimList(c), old)
	cur := map[string]bool{}
	for _, r := range c.roots {
		cur[r.root] = true
	}
	prev := map[string]fimEntry{}
	for _, e := range oldList {
		prev[e.path] = e
	}
	cL, bL, dL, uL := [][]any{}, [][]any{}, [][]any{}, []string{}
	seen := map[string]bool{}
	key := func(e fimEntry) string {
		return strconv.FormatInt(e.size, 10) + "|" + e.mode + "|" + e.owner + "|" + e.hash
	}
	for _, e := range list {
		seen[e.path] = true
		row := []any{e.path, e.size, e.mode, e.owner, e.hash, e.sev, e.root}
		o, known := prev[e.path]
		switch {
		case full:
			cL = append(cL, row)
		case !oldRoots[e.root]:
			bL = append(bL, row)
		case !known || key(o) != key(e):
			cL = append(cL, row)
		}
	}
	gone := []string{}
	for p := range prev {
		if !seen[p] {
			gone = append(gone, p)
		}
	}
	sort.Strings(gone)
	for _, p := range gone {
		if o := prev[p]; cur[o.root] {
			dL = append(dL, []any{p, o.sev, o.root})
		} else {
			uL = append(uL, p)
		}
	}
	text := fimStateText(list)
	roots := ""
	for _, r := range c.roots {
		roots += r.root + "\n"
	}
	return fimRun{
		json: map[string]any{"n": len(list), "full": map[bool]int{true: 1, false: 0}[full], "c": cL, "b": bL, "d": dL, "u": uL},
		commit: func() {
			_ = os.WriteFile(state+".new", []byte(text), 0o600)
			_ = os.Rename(state+".new", state)
			_ = os.WriteFile(filepath.Join(dir, "meta"), []byte(c.epoch+"\n"), 0o600)
			_ = os.WriteFile(filepath.Join(dir, "roots"), []byte(roots), 0o600)
			if usedRehash {
				_ = os.WriteFile(rehash+".used", nil, 0o600)
			}
		},
	}
}

// fimRehash: rzsense ids-scan rehash: één keer per dag alles opnieuw hashen, voor wie de tijd van een bestand terugzet
func fimRehash() int {
	dir := idsDir()
	defer os.Remove(filepath.Join(dir, "rehash.lock"))
	_ = syscall.Setpriority(syscall.PRIO_PROCESS, 0, 19)
	var b strings.Builder
	for _, l := range strings.Split(readText(filepath.Join(dir, "fim", "state")), "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 {
			continue
		}
		if size, err := strconv.ParseInt(f[1], 10, 64); err != nil || size > fimBig {
			continue
		}
		if h, ok := sha256File(f[0]); ok {
			fmt.Fprintf(&b, "%s\t%s\t%s\t%s\n", f[0], f[1], f[2], h)
		}
	}
	tmp := filepath.Join(dir, "rehash.tmp")
	if os.WriteFile(tmp, []byte(b.String()), 0o600) != nil || os.Rename(tmp, filepath.Join(dir, "rehash")) != nil {
		return 1
	}
	return 0
}

// ---------- het onderdeel "ids" van het rapport (als de Go-agent het werk doet) ----------

type idsRun struct {
	ids    map[string]any
	iso    map[string]any
	locks  [][]string
	commit func() // na een aangenomen rapport
}

// collectIdsLinux: alles wat de bash-agent voor het IDS stuurt en doet. nil als het IDS voor deze server uit staat
// (isoleren en accounts vergrendelen staan daar los van).
func collectIdsLinux(c *Config) idsRun {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	conf, _ := readShellConf(bashConfPath())
	r := idsRun{commit: func() {}}
	mode := idsMode(tests)
	var fw fwResult
	var fim *fimRun
	if mode != "" {
		dir := idsDir()
		_ = os.MkdirAll(dir, 0o700)
		ids := map[string]any{"mode": mode, "fv": 2, "files": idsFileHashes(""), "users": idsUsers(readText("/etc/passwd")), "groups": idsGroups(readText("/etc/group")),
			"modules": idsModules(readText("/proc/modules")), "procs": idsSuspectProcs(), "web": idsWebshells(), "conns": []map[string]any{}, "out": [][]any{}}
		if l := idsBackground("suid", time.Hour); l != nil {
			ids["suid"] = l
		}
		if idsPkgTool() != "" {
			if l := idsBackground("pkgdiff", 24*time.Hour); l != nil {
				ids["pkgdiff"] = l
			}
		}
		ids["pkg"], ids["pkg_busy"] = idsPkgActivity()
		if haveCmd("ss") {
			listen, _ := stdoutOf(10*time.Second, "ss", "-Htlnp")
			est, _ := stdoutOf(10*time.Second, "ss", "-tnpH", "state", "established")
			ids["conns"], ids["out"] = idsConns(est, idsListenPorts(listen))
		}
		// de lijst met diensten elke 10 minuten, hardening elk uur: alleen in het rapport als ze opnieuw gemeten zijn
		if st, err := os.Stat(filepath.Join(dir, "units.at")); err != nil || time.Since(st.ModTime()) >= 10*time.Minute {
			if u := idsEnabledUnits(); u != nil {
				ids["units"] = u
				_ = os.WriteFile(filepath.Join(dir, "units.at"), nil, 0o600)
			}
		}
		if st, err := os.Stat(filepath.Join(dir, "hard.json")); err != nil || time.Since(st.ModTime()) >= time.Hour {
			if h := idsHardCached(); len(h) > 0 {
				ids["hard"] = h
			}
		}
		ids["killed"] = killWanted(tests)
		ids["fimc"] = idsFimCandidates()
		if fc := fimParse(tests); fc != nil {
			f := fimDelta(fc, filepath.Join(dir, "fim"))
			fim = &f
			ids["fim"] = f.json
			if _, err := os.Stat(filepath.Join(dir, "fim", "state")); err == nil {
				idsBackground("rehash", 24*time.Hour)
			}
		} else {
			_ = os.RemoveAll(filepath.Join(dir, "fim")) // niets (meer) te bewaken
		}
		r.ids = ids
	}
	fw = fwApply(tests, c, conf)
	if r.ids != nil {
		r.ids["decoy"] = fw.decoy
		if fw.decoyErr != "" {
			r.ids["decoy_err"] = fw.decoyErr
		}
		r.ids["fw"] = fw.fw
	}
	if isRoot() {
		r.iso = isoApply(tests, c)
		r.locks = userLocks(tests)
	}
	r.commit = func() {
		decoySent(fw)
		if fim != nil {
			fim.commit()
		}
	}
	return r
}

// idsDryRun: voor de proef: wat de Go-agent zou zetten (firewall en isoleren). Er wordt niets uitgevoerd.
func idsDryRun(obs map[string]any, c *Config) {
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	conf, _ := readShellConf(bashConfPath())
	obs["fw"] = fwDryRun(tests, c, conf)
	if iso := isoDryRun(tests, c); iso != nil {
		obs["iso"] = iso
	}
}
