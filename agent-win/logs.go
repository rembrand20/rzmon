package main

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"fmt"
	"net"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Fase 2: Windows-logboeken naar de pagina Logs (bron "winevt"), log-checks op een logboek, en logins (RDP en console)
// uit het Beveiliging-logboek. Per logboek een bladwijzer (EventRecordID): lukt versturen niet, dan leest de agent de
// volgende keer gewoon vanaf dezelfde plek. Zo is er geen wachtrij op schijf nodig.

type winEvent struct {
	RecordID uint64
	Time     time.Time
	Level    int
	ID       int
	Provider string
	Channel  string
	Message  string
	Data     map[string]string
}

// Logboeken die de agent aanbiedt om te verzamelen (als ze op deze server bestaan)
var knownChannels = []string{
	"System", "Application", "Security", "Setup",
	"Microsoft-Windows-Sysmon/Operational",
	"Microsoft-Windows-Windows Defender/Operational",
	"Microsoft-Windows-PowerShell/Operational", "Windows PowerShell",
	"Microsoft-Windows-TerminalServices-LocalSessionManager/Operational",
	"Microsoft-Windows-TerminalServices-RemoteConnectionManager/Operational",
	"Microsoft-Windows-TaskScheduler/Operational",
	"Microsoft-Windows-WindowsUpdateClient/Operational",
	"Microsoft-Windows-Windows Firewall With Advanced Security/Firewall",
	"Microsoft-Windows-Bits-Client/Operational",
	"Directory Service", "DNS Server", "DFS Replication",
	"Microsoft-Windows-SMBServer/Security",
}

var channelRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 ._/-]{0,199}$`)

func levelWord(l int) string {
	switch l {
	case 1:
		return "CRITICAL"
	case 2:
		return "ERROR"
	case 3:
		return "WARNING"
	case 5:
		return "VERBOSE"
	default:
		return "INFO"
	}
}

// levelFilter: XPath bij het niveau uit RzSense (0 alles, 1 info en erger, 2 warning en erger, 3 alleen errors)
func levelFilter(l int) string {
	switch l {
	case 1:
		return "Level=0 or Level<=4"
	case 2:
		return "Level>=1 and Level<=3"
	case 3:
		return "Level=1 or Level=2"
	}
	return ""
}

func oneLine(s string) string {
	s = strings.Join(strings.Fields(strings.ReplaceAll(s, "\t", " ")), " ")
	if len(s) > 7000 {
		s = s[:7000]
	}
	return s
}

func dataText(d map[string]string) string {
	keys := make([]string, 0, len(d))
	for k := range d {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		if d[k] != "" && d[k] != "-" {
			parts = append(parts, k+"="+d[k])
		}
	}
	return strings.Join(parts, " ")
}

// line: één logregel zoals de pagina Logs hem leest (tijd met zone en het niveau vooraan)
func (e winEvent) line() string {
	msg := e.Message
	if msg == "" {
		msg = dataText(e.Data)
	}
	return fmt.Sprintf("%s %s %s[%d]: %s", e.Time.Local().Format("2006-01-02T15:04:05-07:00"), levelWord(e.Level), e.Provider, e.ID, oneLine(msg))
}

// ---------------------------------------------------------------- logs naar de pagina Logs

type eventReader interface {
	read(channel, filter string, since uint64, max int, withMsg bool) ([]winEvent, uint64, error)
	close()
}

type logSrc struct {
	ID    string
	Kind  string
	Spec  string
	Level int
}

func shipLogs(c *Config, t tests) {
	if len(t.LogSrc) == 0 || time.Now().Unix() < t.LogPause {
		return
	}
	last := int64(atoiDef(readState("logs.last"), 0))
	if time.Now().Unix()-last < int64(t.LogShip) {
		return
	}
	now := time.Now()
	var body strings.Builder
	var status strings.Builder
	marks := map[string]uint64{}
	n := 0
	r := newEventReader()
	defer r.close()
	for _, s := range t.LogSrc {
		if s.Kind != "winevt" || !channelRe.MatchString(s.Spec) {
			fmt.Fprintf(&status, "S\t%s\terr\t\t%s\n", s.ID, "deze bron kan de Windows-agent niet lezen: "+s.Kind)
			continue
		}
		key := "evt.src." + s.ID
		since := uint64(atoiDef(readState(key), 0))
		evs, mark, err := r.read(s.Spec, levelFilter(s.Level), since, 5000, true)
		if err != nil {
			fmt.Fprintf(&status, "S\t%s\terr\t\t%s\n", s.ID, oneLine(err.Error()))
			continue
		}
		for _, e := range evs {
			fmt.Fprintf(&body, "L\t%s\t%d\t%s\n", s.ID, now.Unix(), e.line())
			n++
		}
		marks[key] = mark
		fmt.Fprintf(&status, "S\t%s\tok\t%d\t\n", s.ID, len(evs))
	}
	batch := fmt.Sprintf("b%d%s", now.Unix(), randHex(3))
	pkg := fmt.Sprintf("rzlogs1\t%s\t%d\t0\t%s\n%s%s", batch, now.Unix(), now.Format("-0700"), status.String(), body.String())
	var gz bytes.Buffer
	zw := gzip.NewWriter(&gz)
	zw.Write([]byte(pkg))
	zw.Close()
	out, code, err := sealedCall(c, "agent_logs", gz.Bytes(), map[string]string{"Content-Type": "application/octet-stream"}, 60*time.Second)
	if err != nil || code != 200 || !bytes.Contains(out, []byte(`"ok":true`)) {
		logf("Logs niet verstuurd (%d regels): %v %s", n, err, shortErr(out))
		return // de bladwijzers blijven staan: de volgende keer opnieuw
	}
	for k, v := range marks {
		writeState(k, strconv.FormatUint(v, 10))
	}
	writeState("logs.last", fmt.Sprint(now.Unix()))
}

// logScan: welke logboeken er op deze server zijn (om in de Agent Manager uit te kiezen)
func logScan(req int) map[string]any {
	var list [][]any
	for _, ch := range knownChannels {
		if n, ok := channelInfo(ch); ok {
			list = append(list, []any{ch, n})
		}
	}
	return map[string]any{"at": time.Now().Unix(), "req": req, "files": [][]any{}, "docker": [][]any{}, "units": [][]any{}, "ha": [][]any{}, "winevt": list, "dirs": ""}
}

// ---------------------------------------------------------------- log-checks

type logCheck struct {
	ID      string
	Source  string
	Pattern string
}

var ipRe = regexp.MustCompile(`\b(?:\d{1,3}\.){3}\d{1,3}\b`)

// runLogChecks: per check de nieuwe treffers sinds de vorige keer, de laatste regels en de vaakst genoemde IP-adressen
func runLogChecks(checks []logCheck) map[string]any {
	out := map[string]any{}
	if len(checks) == 0 {
		return out
	}
	r := newEventReader()
	defer r.close()
	for _, lc := range checks {
		res := map[string]any{"new": 0}
		re, err := regexp.Compile("(?i)" + lc.Pattern)
		ch := strings.TrimPrefix(lc.Source, "winevt:")
		switch {
		case err != nil:
			res["error"] = "zoekpatroon ongeldig: " + err.Error()
		case !channelRe.MatchString(ch):
			res["error"] = "de Windows-agent leest logboeken (bv. System of Application), geen bestand: " + lc.Source
		default:
			key := "evt.chk." + lc.ID
			since := uint64(atoiDef(readState(key), 0))
			evs, mark, err := r.read(ch, "", since, 5000, true)
			if err != nil {
				res["error"] = err.Error()
				break
			}
			hits, lines, ips := 0, []string{}, map[string]int{}
			for _, e := range evs {
				l := e.line()
				if !re.MatchString(l) {
					continue
				}
				hits++
				lines = append(lines, l)
				for _, ip := range ipRe.FindAllString(l, 3) {
					if net.ParseIP(ip) != nil {
						ips[ip]++
					}
				}
			}
			if len(lines) > 5 {
				lines = lines[len(lines)-5:]
			}
			res["new"], res["lines"], res["ips"] = hits, lines, topIPs(ips, 3)
			writeState(key, strconv.FormatUint(mark, 10))
		}
		out[lc.ID] = res
	}
	return out
}

func topIPs(m map[string]int, n int) [][]any {
	type kv struct {
		ip string
		c  int
	}
	var l []kv
	for k, v := range m {
		l = append(l, kv{k, v})
	}
	sort.Slice(l, func(i, j int) bool { return l[i].c > l[j].c })
	out := [][]any{}
	for i := 0; i < len(l) && i < n; i++ {
		out = append(out, []any{l[i].ip, l[i].c})
	}
	return out
}

// ---------------------------------------------------------------- logins uit het Beveiliging-logboek

// collectAuth: gelukte logins (4624: console, RDP, met opgeslagen gegevens) en mislukte (4625: ook via het netwerk),
// in het formaat van de Linux-agent: events (k, u, ip, t, m) en fails (k, n, users, ip, t).
func collectAuth() map[string]any {
	r := newEventReader()
	defer r.close()
	since := uint64(atoiDef(readState("evt.auth"), 0))
	evs, mark, err := r.read("Security", "EventID=4624 or EventID=4625", since, 2000, false)
	if err != nil {
		logf("Logins niet gelezen: %v", err)
		return nil
	}
	writeState("evt.auth", strconv.FormatUint(mark, 10))
	events := []map[string]any{}
	type failKey struct{ k, ip string }
	fails := map[failKey]map[string]any{}
	for _, e := range evs {
		user := e.Data["TargetUserName"]
		dom := e.Data["TargetDomainName"]
		ip := e.Data["IpAddress"]
		if ip == "-" || ip == "::1" || ip == "127.0.0.1" {
			ip = ""
		}
		lt := atoiDef(e.Data["LogonType"], 0)
		if user == "" || strings.HasSuffix(user, "$") || skipAccount(user, dom) {
			continue
		}
		who := user
		if dom != "" && dom != "-" && !strings.EqualFold(dom, "WORKGROUP") {
			who = dom + `\` + user
		}
		kind := map[int]string{2: "console", 10: "rdp", 11: "console", 7: "console", 3: "net"}[lt]
		if kind == "" {
			continue
		}
		if e.ID == 4624 {
			if kind == "net" {
				continue // gelukt via het netwerk (bestanden delen, beheer): te veel en te gewoon
			}
			events = append(events, map[string]any{"k": kind, "u": who, "ip": ip, "t": e.Time.Unix(), "m": e.Data["AuthenticationPackageName"]})
			continue
		}
		fk := failKey{kind, ip}
		f := fails[fk]
		if f == nil {
			f = map[string]any{"k": kind, "n": 0, "users": []string{}, "ip": ip, "t": e.Time.Unix()}
			fails[fk] = f
		}
		f["n"] = f["n"].(int) + 1
		if us := f["users"].([]string); len(us) < 8 && !contains(us, who) {
			f["users"] = append(us, who)
		}
		f["t"] = e.Time.Unix()
	}
	if len(events) > 300 {
		events = events[len(events)-300:]
	}
	fl := []map[string]any{}
	for _, f := range fails {
		fl = append(fl, f)
	}
	return map[string]any{"events": events, "fails": fl}
}

func skipAccount(user, dom string) bool {
	u := strings.ToUpper(user)
	if strings.HasPrefix(u, "DWM-") || strings.HasPrefix(u, "UMFD-") {
		return true
	}
	switch u {
	case "SYSTEM", "LOCAL SERVICE", "NETWORK SERVICE", "ANONYMOUS LOGON", "DEFAULTACCOUNT":
		return true
	}
	return strings.EqualFold(dom, "Window Manager") || strings.EqualFold(dom, "Font Driver Host")
}

func contains(l []string, s string) bool {
	for _, x := range l {
		if x == s {
			return true
		}
	}
	return false
}

// toJSONList: om lijsten leeg ([]) in plaats van null te sturen
func toJSONList(v any) json.RawMessage {
	b, _ := json.Marshal(v)
	if string(b) == "null" {
		return json.RawMessage("[]")
	}
	return b
}
