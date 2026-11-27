//go:build linux

package main

import (
	"encoding/binary"
	"fmt"
	"net"
	"os"
	"os/exec"
	"sort"
	"strings"
	"testing"
)

// diagMsg: een inet_diag_msg zoals de kernel hem geeft, voor de test
func diagMsg(family uint8, port uint16, ip net.IP, ifIndex, inode uint32, v6only *bool) []byte {
	d := make([]byte, diagMsgLen)
	d[0] = family
	binary.BigEndian.PutUint16(d[4:], port)
	copy(d[8:], ip)
	binary.LittleEndian.PutUint32(d[40:], ifIndex)
	binary.LittleEndian.PutUint32(d[68:], inode)
	if v6only != nil {
		a := []byte{5, 0, diagAttrV6Only, 0, 0, 0, 0, 0}
		if *v6only {
			a[4] = 1
		}
		d = append(d, a...)
	}
	return d
}

func TestParseDiagLikeSs(t *testing.T) {
	yes, no := true, false
	cases := []struct {
		msg  []byte
		port int
		addr string
	}{
		{diagMsg(2, 22, net.IPv4(0, 0, 0, 0).To4(), 0, 111, nil), 22, "0.0.0.0"},
		{diagMsg(2, 5432, net.IPv4(127, 0, 0, 1).To4(), 0, 112, nil), 5432, "127.0.0.1"},
		{diagMsg(10, 22, net.IPv6unspecified, 0, 113, &yes), 22, "::"},   // sshd: eigen IPv6-socket
		{diagMsg(10, 8080, net.IPv6unspecified, 0, 114, &no), 8080, "*"}, // IPv4 en IPv6 samen
		{diagMsg(10, 631, net.IPv6loopback, 0, 115, &yes), 631, "::1"},
		{diagMsg(10, 80, net.ParseIP("::ffff:10.0.0.5"), 0, 116, &no), 80, "::ffff:10.0.0.5"},
	}
	for _, c := range cases {
		s, ok := parseDiagMsg(c.msg)
		if !ok || s.port != c.port || s.addr != c.addr {
			t.Fatalf("poort %d: kreeg %d %q, verwacht %q", c.port, s.port, s.addr, c.addr)
		}
	}
	if s, _ := parseDiagMsg(diagMsg(2, 53, net.IPv4(127, 0, 0, 53).To4(), 1, 117, nil)); !strings.HasPrefix(s.addr, "127.0.0.53%") || s.inode != 117 {
		t.Fatalf("socket aan een interface: %q, inode %d", s.addr, s.inode)
	}
	if _, ok := parseDiagMsg([]byte{1, 2, 3}); ok {
		t.Fatal("een te kort bericht moet geweigerd worden")
	}
}

func TestProcListenFallback(t *testing.T) {
	tcp4 := "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n" +
		"   0: 00000000:0016 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 12345 1 0 100 0 0 10 0\n" +
		"   1: 0100007F:1538 00000000:0000 0A 00000000:00000000 00:00000000 00000000   999        0 12346 1 0 100 0 0 10 0\n" +
		"   2: 0100007F:A1B2 0100007F:1538 01 00000000:00000000 00:00000000 00000000     0        0 12347 1 0 100 0 0 10 0\n"
	tcp6 := "  sl  local_address                         remote_address                        st ...\n" +
		"   0: 00000000000000000000000000000000:0016 00000000000000000000000000000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 22345 1 0 100 0 0 10 0\n"
	got := procListen(tcp4, tcp6)
	if len(got) != 3 || got[0].port != 22 || got[0].addr != "0.0.0.0" || got[0].inode != 12345 || got[1].port != 5432 || got[1].addr != "127.0.0.1" || got[2].addr != "::" {
		t.Fatalf("terugval via /proc: %+v", got)
	}
}

func TestProcComm(t *testing.T) {
	if procComm("104 (claude) S 1 104") != "claude" || procComm("7 (a (b) c) S 1") != "a (b) c" || procComm("kapot") != "" {
		t.Fatal("procesnaam uit /proc/<pid>/stat")
	}
}

func TestWantedServices(t *testing.T) {
	cache := "12\tsvc\tnginx\t\n13\tsvc\tsshd\t\n14\tports\tnginx\t80 443\n15\tsvc\tfout naam\t\n16\tsvc\tsshd\t\n17\tival\t60\t\n"
	got := wantedServices(map[string]string{"SERVICES": "cron  nginx $(rm)"}, cache)
	if strings.Join(got, " ") != "cron nginx sshd" {
		t.Fatalf("diensten: %v", got)
	}
	if len(wantedServices(map[string]string{}, "")) != 0 {
		t.Fatal("zonder lijst geen diensten")
	}
}

// Op een machine met ss: dezelfde poorten en adressen als "ss -Htln" (zoals de bash-agent ze leest)
func TestPortsEqualSs(t *testing.T) {
	if _, err := exec.LookPath("ss"); err != nil {
		t.Skip("ss niet beschikbaar")
	}
	// Tussen de twee momentopnamen kan een poort open of dicht gaan: tot drie keer proberen
	var diff string
	for try := 0; try < 3; try++ {
		if diff = portsVsSs(t); diff == "" {
			return
		}
	}
	t.Fatal(diff)
}

func portsVsSs(t *testing.T) string {
	out, err := exec.Command("ss", "-Htln").Output()
	if err != nil {
		t.Skip("ss niet beschikbaar")
	}
	want := map[string]bool{}
	for _, l := range strings.Split(string(out), "\n") {
		f := strings.Fields(l)
		if len(f) < 4 {
			continue
		}
		i := strings.LastIndexByte(f[3], ':')
		if i < 0 {
			continue
		}
		want[f[3][i+1:]+"|"+strings.NewReplacer("[", "", "]", "").Replace(f[3][:i])] = true
	}
	got := map[string]bool{}
	for _, p := range collectPortsLinux() {
		got[fmt.Sprintf("%d|%s", p["port"], p["addr"])] = true
	}
	keys := func(m map[string]bool) string {
		k := make([]string, 0, len(m))
		for x := range m {
			k = append(k, x)
		}
		sort.Strings(k)
		return strings.Join(k, " ")
	}
	if keys(want) != keys(got) {
		return fmt.Sprintf("poorten verschillen van ss:\n  ss: %s\n  go: %s", keys(want), keys(got))
	}
	return ""
}

// Een socket die twee processen open hebben (zoals bij een dienst die systemd via een socket start): ss noemt het proces
// met het hoogste pid als eerste, en dat neemt de bash-agent. De Go-agent hoort dezelfde te kiezen.
func TestSharedSocketOwner(t *testing.T) {
	ln, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Skip("kan niet luisteren")
	}
	defer ln.Close()
	f, err := ln.(*net.TCPListener).File()
	if err != nil {
		t.Skip(err)
	}
	defer f.Close()
	child := exec.Command("sleep", "20")
	child.ExtraFiles = []*os.File{f}
	if err := child.Start(); err != nil {
		t.Skip(err)
	}
	defer func() { _ = child.Process.Kill(); _ = child.Wait() }()
	port := ln.Addr().(*net.TCPAddr).Port
	for _, p := range collectPortsLinux() {
		if p["port"] == port {
			if p["proc"] != "sleep" {
				t.Fatalf("poort %d: de Go-agent noemt %q; ss noemt het proces met het hoogste pid (sleep, pid %d)", port, p["proc"], child.Process.Pid)
			}
			return
		}
	}
	t.Fatalf("poort %d niet gevonden", port)
}
