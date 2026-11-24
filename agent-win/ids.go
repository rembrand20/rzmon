package main

import (
	"net"
	"net/netip"
	"regexp"
	"sort"
	"strings"
)

// Fase 3: IDS en EDR op Windows. De agent leest de belangrijke beveiligingsgebeurtenissen uit de Windows-logboeken en
// stuurt alleen wat ertoe doet (de beoordeling en de incidenten doet RzSense, net als bij Linux). Blokkeren en isoleren
// gaan via de Windows Firewall, in eigen regelgroepen ("RzSense" en "RzSense-isolatie").

// idsChannels: welke gebeurtenissen uit welk logboek
var idsChannels = []struct {
	Channel string
	Filter  string
}{
	{"System", "EventID=7045 or EventID=104"},
	{"Security", "EventID=1102 or EventID=4720 or EventID=4722 or EventID=4728 or EventID=4732 or EventID=4756 or EventID=4698 or EventID=4688"},
	{"Microsoft-Windows-Windows Defender/Operational", "EventID=1116 or EventID=1117 or EventID=5001"},
	{"Microsoft-Windows-PowerShell/Operational", "EventID=4104"},
	{"Microsoft-Windows-Sysmon/Operational", "EventID=1"},
}

// Programma's die als server draaien (web, database) en daarom zelden een shell starten; doen ze het wel, dan is dat
// vaak een webshell of een aanval op de database
var serverProcs = map[string]bool{"w3wp.exe": true, "sqlservr.exe": true, "httpd.exe": true, "nginx.exe": true, "php-cgi.exe": true,
	"tomcat.exe": true, "tomcat9.exe": true, "tomcat10.exe": true, "java.exe": false, "node.exe": false, "umworkerprocess.exe": true, "exchange.exe": true}
var shellProcs = map[string]bool{"cmd.exe": true, "powershell.exe": true, "pwsh.exe": true, "whoami.exe": true, "net.exe": true, "net1.exe": true,
	"certutil.exe": true, "bitsadmin.exe": true, "mshta.exe": true, "rundll32.exe": true, "regsvr32.exe": true, "wscript.exe": true, "cscript.exe": true,
	"nltest.exe": true, "systeminfo.exe": true, "tasklist.exe": true, "ipconfig.exe": true, "curl.exe": true, "wmic.exe": true}

// Verdachte stukjes in een PowerShell-script (4104): versleutelde of gedownloade code, bekende aanvalsmodules
var psBad = regexp.MustCompile(`(?i)(frombase64string|-enc(odedcommand)?\s|downloadstring|downloadfile|net\.webclient|invoke-webrequest.+\|\s*iex|iex\s*\(|invoke-expression|invoke-mimikatz|invoke-shellcode|sekurlsa|amsiutils|amsiinitfailed|reflection\.assembly\]::load|virtualalloc|bypass.+-nop|-w(indowstyle)?\s+hidden)`)

func baseName(p string) string {
	p = strings.ToLower(strings.TrimSpace(p))
	if i := strings.LastIndexAny(p, `\/`); i >= 0 {
		p = p[i+1:]
	}
	return p
}

// idsEvent: één gebeurtenis zoals RzSense hem verwacht (k = soort), of nil als hij niet verdacht is
func idsEvent(e winEvent) map[string]any {
	d := e.Data
	ev := map[string]any{"id": e.ID, "t": e.Time.Unix(), "ch": e.Channel}
	short := func(s string, n int) string {
		s = oneLine(s)
		if len(s) > n {
			s = s[:n]
		}
		return s
	}
	switch {
	case e.Channel == "System" && e.ID == 7045:
		ev["k"], ev["name"], ev["path"], ev["user"] = "service", d["ServiceName"], short(d["ImagePath"], 300), d["AccountName"]
	case e.ID == 104 || (e.Channel == "Security" && e.ID == 1102):
		ev["k"], ev["user"] = "logclear", d["SubjectUserName"]
	case e.Channel == "Security" && (e.ID == 4720 || e.ID == 4722):
		ev["k"], ev["name"], ev["user"] = "user", d["TargetUserName"], d["SubjectUserName"]
	case e.Channel == "Security" && (e.ID == 4728 || e.ID == 4732 || e.ID == 4756):
		member := d["MemberName"]
		if member == "" || member == "-" {
			member = d["MemberSid"]
		}
		ev["k"], ev["name"], ev["group"], ev["user"] = "group", member, d["TargetUserName"], d["SubjectUserName"]
	case e.Channel == "Security" && e.ID == 4698:
		ev["k"], ev["name"], ev["user"] = "task", d["TaskName"], d["SubjectUserName"]
	case strings.HasSuffix(e.Channel, "Defender/Operational") && (e.ID == 1116 || e.ID == 1117):
		ev["k"], ev["name"], ev["path"], ev["act"] = "malware", d["Threat Name"], short(d["Path"], 300), d["Action Name"]
	case strings.HasSuffix(e.Channel, "Defender/Operational") && e.ID == 5001:
		ev["k"] = "av_off"
	case e.ID == 4104:
		script := d["ScriptBlockText"]
		m := psBad.FindString(script)
		if m == "" {
			return nil
		}
		ev["k"], ev["name"], ev["cmd"], ev["path"] = "ps", m, short(script, 400), d["Path"]
	case (strings.HasSuffix(e.Channel, "Sysmon/Operational") && e.ID == 1) || (e.Channel == "Security" && e.ID == 4688):
		image, parent, cmd, user := d["Image"], d["ParentImage"], d["CommandLine"], d["User"]
		if e.ID == 4688 {
			image, parent, cmd, user = d["NewProcessName"], d["ParentProcessName"], d["CommandLine"], d["SubjectUserName"]
		}
		if !serverProcs[baseName(parent)] || !shellProcs[baseName(image)] {
			return nil
		}
		ev["k"], ev["name"], ev["parent"], ev["cmd"], ev["user"] = "proc", image, parent, short(cmd, 400), user
	default:
		return nil
	}
	return ev
}

// ---------------------------------------------------------------- blokkeren en isoleren

type blockWant struct {
	IP    string
	Until int64
}

type isolateWant struct {
	Until int64
	Allow []string
}

// complementV4: alle IPv4-adressen behalve de toegestane netwerken, als reeksen ("a-b") voor een blokkeerregel.
// Blokkeerregels gaan in de Windows Firewall altijd vóór toestaan-regels: zo blijft er echt niets anders open.
func complementV4(allow []string) []string {
	type rng struct{ lo, hi uint32 }
	var rs []rng
	for _, a := range allow {
		a = strings.TrimSpace(a)
		if a == "" {
			continue
		}
		if !strings.Contains(a, "/") {
			if ip := net.ParseIP(a); ip != nil && ip.To4() != nil {
				a += "/32"
			}
		}
		p, err := netip.ParsePrefix(a)
		if err != nil || !p.Addr().Is4() {
			continue
		}
		p = p.Masked()
		b := p.Addr().As4()
		lo := uint32(b[0])<<24 | uint32(b[1])<<16 | uint32(b[2])<<8 | uint32(b[3])
		size := uint64(1) << (32 - p.Bits())
		rs = append(rs, rng{lo, uint32(uint64(lo) + size - 1)})
	}
	rs = append(rs, rng{0x7f000000, 0x7fffffff}) // loopback blijft altijd open
	sort.Slice(rs, func(i, j int) bool { return rs[i].lo < rs[j].lo })
	ip := func(v uint32) string {
		return netip.AddrFrom4([4]byte{byte(v >> 24), byte(v >> 16), byte(v >> 8), byte(v)}).String()
	}
	var out []string
	next := uint64(0)
	for _, r := range rs {
		if uint64(r.lo) > next {
			out = append(out, ip(uint32(next))+"-"+ip(r.lo-1))
		}
		if uint64(r.hi)+1 > next {
			next = uint64(r.hi) + 1
		}
	}
	if next <= 0xffffffff {
		out = append(out, ip(uint32(next))+"-255.255.255.255")
	}
	return out
}

// hostIPs: de IP-adressen van RzSense (gewone adres en reserve-adres), die bij isoleren altijd bereikbaar blijven
func hostIPs(urls ...string) []string {
	var out []string
	for _, u := range urls {
		u = strings.TrimPrefix(strings.TrimPrefix(u, "https://"), "http://")
		if i := strings.IndexAny(u, "/"); i >= 0 {
			u = u[:i]
		}
		h := u
		if hh, _, err := net.SplitHostPort(u); err == nil {
			h = hh
		}
		h = strings.Trim(h, "[]")
		if h == "" {
			continue
		}
		if ip := net.ParseIP(h); ip != nil {
			out = append(out, ip.String())
			continue
		}
		if ips, err := net.LookupIP(h); err == nil {
			for _, ip := range ips {
				out = append(out, ip.String())
			}
		}
	}
	return out
}
