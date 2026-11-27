//go:build linux

package main

// Luisterende TCP-poorten en de status van diensten (0.9.0), met dezelfde uitkomst als de bash-agent:
//   - poorten: wat "ss -Htlnp" laat zien (poort, adres, eerste proces). De gegevens komen rechtstreeks van de kernel
//     (sock_diag), dus zonder het programma ss; lukt dat niet, dan uit /proc/net/tcp.
//   - diensten: "systemctl is-active <naam>" voor de diensten waar RzSense een check op heeft. De lijst komt uit de
//     testlijst die de bash-agent al bewaart (tests.cache) en uit SERVICES in zijn instellingen. Alleen lezen.

import (
	"encoding/binary"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"

	"golang.org/x/sys/unix"
)

type listenSock struct {
	port   int
	addr   string
	inode  uint32
	v6only bool
}

const (
	sockDiagByFamily = 20 // SOCK_DIAG_BY_FAMILY
	tcpListenState   = 10 // TCP_LISTEN
	diagAttrV6Only   = 11 // INET_DIAG_SKV6ONLY
	diagMsgLen       = 72 // sizeof(struct inet_diag_msg)
)

// diagListen: de luisterende TCP-sockets van één familie (AF_INET of AF_INET6), gevraagd aan de kernel
func diagListen(family uint8) ([]listenSock, error) {
	fd, err := unix.Socket(unix.AF_NETLINK, unix.SOCK_DGRAM|unix.SOCK_CLOEXEC, unix.NETLINK_SOCK_DIAG)
	if err != nil {
		return nil, err
	}
	defer unix.Close(fd)
	_ = unix.SetsockoptTimeval(fd, unix.SOL_SOCKET, unix.SO_RCVTIMEO, &unix.Timeval{Sec: 5})
	// nlmsghdr (16) + inet_diag_req_v2 (56)
	req := make([]byte, 16+56)
	binary.LittleEndian.PutUint32(req[0:], uint32(len(req)))
	binary.LittleEndian.PutUint16(req[4:], sockDiagByFamily)
	binary.LittleEndian.PutUint16(req[6:], unix.NLM_F_REQUEST|unix.NLM_F_DUMP)
	binary.LittleEndian.PutUint32(req[8:], 1)
	req[16] = family
	req[17] = unix.IPPROTO_TCP
	binary.LittleEndian.PutUint32(req[20:], 1<<tcpListenState)
	if err := unix.Sendto(fd, req, 0, &unix.SockaddrNetlink{Family: unix.AF_NETLINK}); err != nil {
		return nil, err
	}
	var out []listenSock
	buf := make([]byte, 64<<10)
	for {
		n, _, err := unix.Recvfrom(fd, buf, 0)
		if err != nil {
			return nil, err
		}
		msgs, err := syscall.ParseNetlinkMessage(buf[:n])
		if err != nil {
			return nil, err
		}
		for _, m := range msgs {
			switch m.Header.Type {
			case unix.NLMSG_DONE:
				return out, nil
			case unix.NLMSG_ERROR:
				return nil, fmt.Errorf("sock_diag gaf een fout terug")
			}
			if s, ok := parseDiagMsg(m.Data); ok {
				out = append(out, s)
			}
		}
	}
}

// parseDiagMsg: één inet_diag_msg met zijn attributen → poort, adres (zoals ss het toont), inode en "alleen IPv6"
func parseDiagMsg(d []byte) (listenSock, bool) {
	if len(d) < diagMsgLen {
		return listenSock{}, false
	}
	family := d[0]
	s := listenSock{port: int(binary.BigEndian.Uint16(d[4:6])), inode: binary.LittleEndian.Uint32(d[68:72])}
	ifIndex := binary.LittleEndian.Uint32(d[40:44])
	var ip net.IP
	if family == unix.AF_INET {
		ip = net.IP(d[8:12])
	} else {
		ip = net.IP(d[8:24])
	}
	for a := d[diagMsgLen:]; len(a) >= 4; {
		l, t := int(binary.LittleEndian.Uint16(a[0:2])), binary.LittleEndian.Uint16(a[2:4])
		if l < 4 || l > len(a) {
			break
		}
		if t == diagAttrV6Only && l >= 5 {
			s.v6only = a[4] != 0
		}
		a = a[min((l+3)&^3, len(a)):]
	}
	s.addr = ssAddr(family == unix.AF_INET6, ip, s.v6only, ifIndex)
	return s, s.port > 0
}

// ssAddr: het adres zoals de bash-agent het uit ss haalt: 0.0.0.0, "::" (alleen IPv6), "*" (IPv4 en IPv6 samen),
// en bij een socket die aan een interface hangt het achtervoegsel %naam (bv. 127.0.0.53%lo)
func ssAddr(v6 bool, ip net.IP, v6only bool, ifIndex uint32) string {
	a := ip.String()
	if v6 {
		if ip.IsUnspecified() {
			a = map[bool]string{true: "::", false: "*"}[v6only]
		} else if v4 := ip.To4(); v4 != nil {
			a = "::ffff:" + v4.String()
		}
	}
	if ifIndex != 0 {
		if ifc, err := net.InterfaceByIndex(int(ifIndex)); err == nil {
			a += "%" + ifc.Name
		}
	}
	return a
}

// procListen: terugval zonder sock_diag: /proc/net/tcp en /proc/net/tcp6 (status 0A = luisteren)
func procListen(tcp4, tcp6 string) []listenSock {
	var out []listenSock
	parse := func(text string, v6 bool) {
		for i, l := range strings.Split(text, "\n") {
			f := strings.Fields(l)
			if i == 0 || len(f) < 10 || f[3] != "0A" {
				continue
			}
			host, port, ok := strings.Cut(f[1], ":")
			p, err := strconv.ParseUint(port, 16, 16)
			if !ok || err != nil || p == 0 {
				continue
			}
			raw := make([]byte, len(host)/2)
			for j := range raw {
				b, _ := strconv.ParseUint(host[2*j:2*j+2], 16, 8)
				raw[j] = byte(b)
			}
			// per 4 bytes omgedraaid opgeslagen (de volgorde van de processor)
			for j := 0; j+3 < len(raw); j += 4 {
				raw[j], raw[j+1], raw[j+2], raw[j+3] = raw[j+3], raw[j+2], raw[j+1], raw[j]
			}
			ino, _ := strconv.ParseUint(f[9], 10, 32)
			out = append(out, listenSock{port: int(p), addr: ssAddr(v6, net.IP(raw), true, 0), inode: uint32(ino)})
		}
	}
	parse(tcp4, false)
	parse(tcp6, true)
	return out
}

var sockLinkRe = regexp.MustCompile(`^socket:\[(\d+)\]$`)

// socketOwners: per socket (inode) de naam van het proces met het laagste pid dat hem open heeft, zoals ss -p de eerste
// noemt. Alleen te zien als root.
func socketOwners(want map[uint32]bool) map[uint32]string {
	out := map[uint32]string{}
	if len(want) == 0 {
		return out
	}
	dirs, _ := os.ReadDir("/proc")
	pids := make([]int, 0, len(dirs))
	for _, d := range dirs {
		if n, err := strconv.Atoi(d.Name()); err == nil {
			pids = append(pids, n)
		}
	}
	sort.Ints(pids)
	for _, pid := range pids {
		fds, err := os.ReadDir(fmt.Sprintf("/proc/%d/fd", pid))
		if err != nil {
			continue
		}
		name := ""
		for _, fd := range fds {
			link, err := os.Readlink(fmt.Sprintf("/proc/%d/fd/%s", pid, fd.Name()))
			if err != nil {
				continue
			}
			m := sockLinkRe.FindStringSubmatch(link)
			if m == nil {
				continue
			}
			ino, _ := strconv.ParseUint(m[1], 10, 32)
			if !want[uint32(ino)] {
				continue
			}
			if _, have := out[uint32(ino)]; have {
				continue
			}
			if name == "" {
				name = procComm(readText(fmt.Sprintf("/proc/%d/stat", pid)))
			}
			out[uint32(ino)] = name
		}
		if len(out) == len(want) {
			break
		}
	}
	return out
}

// procComm: de naam tussen haakjes in /proc/<pid>/stat (kan zelf haakjes of spaties bevatten)
func procComm(stat string) string {
	a, b := strings.IndexByte(stat, '('), strings.LastIndexByte(stat, ')')
	if a < 0 || b <= a {
		return ""
	}
	return stat[a+1 : b]
}

// collectPortsLinux: [{port, addr, proc}], elke combinatie van poort en adres één keer, zoals de bash-agent
func collectPortsLinux() []map[string]any {
	var socks []listenSock
	ok := false
	for _, fam := range []uint8{unix.AF_INET, unix.AF_INET6} {
		if s, err := diagListen(fam); err == nil {
			socks = append(socks, s...)
			ok = true
		}
	}
	if !ok {
		socks = procListen(readText("/proc/net/tcp"), readText("/proc/net/tcp6"))
	}
	want := map[uint32]bool{}
	for _, s := range socks {
		if s.inode != 0 {
			want[s.inode] = true
		}
	}
	owners := socketOwners(want)
	seen := map[string]bool{}
	out := []map[string]any{}
	for _, s := range socks {
		key := fmt.Sprintf("%d|%s", s.port, s.addr)
		if seen[key] {
			continue
		}
		seen[key] = true
		out = append(out, map[string]any{"port": s.port, "addr": s.addr, "proc": owners[s.inode]})
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i]["port"].(int) != out[j]["port"].(int) {
			return out[i]["port"].(int) < out[j]["port"].(int)
		}
		return out[i]["addr"].(string) < out[j]["addr"].(string)
	})
	return out
}

// ---------------------------------------------------------------- diensten

var svcNameLinuxRe = regexp.MustCompile(`^[A-Za-z0-9@._:-]+$`)

func bashCachePath() string {
	if p := os.Getenv("RZSENSE_BASH_CACHE"); p != "" {
		return p
	}
	if modeActive() { // 0.13.0: de Go-agent haalt de testlijst dan zelf op
		return dataDir()
	}
	return "/var/cache/rzmon-agent"
}

// wantedServices: de diensten waar RzSense een check op heeft (regels "svc" in tests.cache van de bash-agent), plus
// SERVICES uit zijn instellingen; elke naam één keer, in die volgorde
func wantedServices(conf map[string]string, testsCache string) []string {
	var out []string
	seen := map[string]bool{}
	add := func(n string) {
		if n != "" && svcNameLinuxRe.MatchString(n) && !seen[n] {
			seen[n] = true
			out = append(out, n)
		}
	}
	for _, n := range strings.Fields(conf["SERVICES"]) {
		add(n)
	}
	for _, l := range strings.Split(testsCache, "\n") {
		f := strings.Split(l, "\t")
		if len(f) >= 3 && f[1] == "svc" {
			add(f[2])
		}
	}
	return out
}

// serviceStatesLinux: naam → uitkomst van "systemctl is-active" (leeg wordt "unknown"), zoals de bash-agent
func serviceStatesLinux(names []string) map[string]string {
	out := map[string]string{}
	if _, err := exec.LookPath("systemctl"); err != nil {
		return out
	}
	for _, n := range names {
		b, _ := exec.Command("systemctl", "is-active", n).Output() // een dienst die niet draait geeft een foutcode: dat is geen fout
		st := strings.TrimSpace(string(b))
		if st == "" {
			st = "unknown"
		}
		out[n] = st
	}
	return out
}

func collectServicesLinux() map[string]string {
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	return serviceStatesLinux(wantedServices(conf, readText(filepath.Join(bashCachePath(), "tests.cache"))))
}
