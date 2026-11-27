//go:build linux

package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/pem"
	"fmt"
	"math/big"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestParseProcStat(t *testing.T) {
	// naam met spaties en haakjes; velden: status, ppid … utime=14 stime=15 … starttime=22 … rss=24
	stat := "4242 (Web (Content) x) S 17 4242 4242 0 -1 4194560 100 0 0 0 250 50 0 0 20 0 5 0 123456 99999999 2500 18446744073709551615 1 1 0 0 0 0 0 0 0 0 0 0 17 1 0 0 0 0 0"
	comm, ppid, ticks, start, rss, ok := parseProcStat(stat)
	if !ok || comm != "Web (Content) x" || ppid != 17 || ticks != 300 || start != 123456 || rss != 2500 {
		t.Fatalf("stat: %q %d %d %d %d %v", comm, ppid, ticks, start, rss, ok)
	}
	if _, _, _, _, _, ok := parseProcStat("kapot"); ok {
		t.Fatal("onleesbare stat moet geweigerd worden")
	}
}

func TestProcChecksLikeBash(t *testing.T) {
	now := time.Unix(1_800_000_000, 0)
	rows := []procRow{
		{pid: 1, ppid: 0, user: "root", etimes: 5000, pcpu: 0.04, rss: 9000, comm: "systemd", args: "/sbin/init"},
		{pid: 2, ppid: 0, user: "root", etimes: 5000, comm: "kthreadd", args: "[kthreadd]"},
		{pid: 30, ppid: 2, user: "root", etimes: 5000, comm: "kworker/0:1", args: "[kworker/0:1]"},
		{pid: 400, ppid: 1, user: "root", etimes: 900, pcpu: 1.26, rss: 20000, comm: "nginx", args: "nginx: master process /usr/sbin/nginx"},
		{pid: 401, ppid: 400, user: "www-data", etimes: 600, pcpu: 2.04, rss: 30000, comm: "nginx", args: "nginx: worker process"},
		{pid: 500, ppid: 1, user: "cf", etimes: 100, pcpu: 0.5, rss: 40000, comm: "cloudflared", args: "/usr/bin/cloudflared tunnel run --token x"},
		{pid: 600, ppid: 1, user: "root", etimes: 50, pcpu: 0, rss: 5000, comm: "python3", args: "/usr/bin/python3 /opt/app/very-long-service-name.py --port 8080"},
		{pid: 700, ppid: 1, user: "root", etimes: 10, comm: "rzsense", args: "rzsense shadow"},
		{pid: 701, ppid: 700, user: "root", etimes: 1, comm: "systemctl", args: "systemctl is-active nginx"},
		{pid: 800, ppid: 1, user: "root", etimes: 3, comm: "defunct", args: "[defunct]"},
	}
	vis := visibleProcs(rows, 700)
	if len(vis) != 5 {
		t.Fatalf("zichtbaar: %d (kernelprocessen, de agent zelf en zijn kinderen horen weg): %v", len(vis), vis)
	}
	b64 := func(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
	cache := strings.Join([]string{
		"11\tproc\tname\t" + b64("nginx") + "\t",
		"12\tproc\tname\t" + b64("nginx") + "\twww-data",
		"13\tproc\tpattern\t" + b64(`very-long-.*\.py`) + "\t",
		"14\tproc\tname\t" + b64("cloudflared") + "\t",
		"15\tproc\tname\t" + b64("bestaat-niet") + "\t",
		"16\tproc\tname\t" + b64("init") + "\t",
		"x\tproc\tname\t" + b64("nginx") + "\t",
		"17\tsvc\tnginx\t",
		"18\tproc\tname\t!!geen base64!!\t",
	}, "\n")
	checks := procChecksFromCache(cache)
	if len(checks) != 6 {
		t.Fatalf("checks uit de testlijst: %d", len(checks))
	}
	got := procChecks(vis, checks, now)
	g := func(id string) map[string]any { return got[id].(map[string]any) }
	if g("11")["n"] != 2 || g("11")["pids"] != "400 401" || g("11")["cpu"] != 3.3 || g("11")["rss"] != int64(50000) || g("11")["start"] != now.Unix()-900 {
		t.Fatalf("nginx op naam: %v", g("11"))
	}
	if g("12")["n"] != 1 || g("12")["pids"] != "401" {
		t.Fatalf("nginx van één gebruiker: %v", g("12"))
	}
	if g("13")["n"] != 1 || g("13")["pids"] != "600" {
		t.Fatalf("patroon op de opdrachtregel: %v", g("13"))
	}
	if g("14")["n"] != 1 || g("15")["n"] != 0 || g("15")["start"] != nil || g("15")["pids"] != "" {
		t.Fatalf("cloudflared %v, bestaat-niet %v", g("14"), g("15"))
	}
	if g("16")["n"] != 1 || g("16")["pids"] != "1" {
		t.Fatalf("het programma uit de opdrachtregel (/sbin/init) telt ook als naam: %v", g("16"))
	}
	pl := procList(vis)
	if len(pl) != 4 || pl[1][0] != "nginx" || pl[1][1] != 2 || pl[1][2] != "root" || pl[1][3] != "nginx: master process /usr/sbin/nginx" {
		t.Fatalf("lijst om uit te kiezen: %v", pl)
	}
}

// Op een machine met ps: dezelfde processen (pid, ouder, naam, geheugen) als ps zelf geeft
func TestProcTableEqualPs(t *testing.T) {
	if _, err := exec.LookPath("ps"); err != nil {
		t.Skip("ps niet beschikbaar")
	}
	// Processen starten en stoppen tussen de twee momentopnamen: tot drie keer proberen
	var diff string
	for try := 0; try < 3; try++ {
		if diff = procsVsPs(t); diff == "" {
			return
		}
	}
	t.Fatal(diff)
}

func procsVsPs(t *testing.T) string {
	cmd := exec.Command("ps", "-eo", "pid=,ppid=,rss=,comm=")
	cmd.Env = append(os.Environ(), "LC_ALL=C")
	out, err := cmd.Output()
	if err != nil {
		t.Skip("ps niet beschikbaar")
	}
	mine := map[int]procRow{}
	for _, r := range procTable(time.Now()) {
		mine[r.pid] = r
	}
	same, seen := 0, 0
	for _, l := range strings.Split(string(out), "\n") {
		f := strings.Fields(l)
		if len(f) < 4 {
			continue
		}
		pid, _ := strconv.Atoi(f[0])
		r, ok := mine[pid]
		if !ok {
			continue // intussen gestart of gestopt (ps zelf)
		}
		seen++
		ppid, _ := strconv.Atoi(f[1])
		rss, _ := strconv.ParseInt(f[2], 10, 64)
		d := rss - r.rss
		if d < 0 {
			d = -d
		}
		if ppid == r.ppid && strings.Join(f[3:], " ") == r.comm && d <= 4096+rss/5 {
			same++
		} else {
			t.Logf("verschil bij pid %d: ps %v, go %s", pid, f, r)
		}
	}
	if seen < 3 || same < seen-2 {
		return fmt.Sprintf("%d van %d processen gelijk aan ps", same, seen)
	}
	return ""
}

func testCert(t *testing.T, cn, org string, notAfter time.Time, parent *x509.Certificate, parentKey *ecdsa.PrivateKey) ([]byte, *x509.Certificate, *ecdsa.PrivateKey) {
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: cn, Organization: []string{org}}, NotBefore: time.Now().Add(-time.Hour), NotAfter: notAfter,
		DNSNames: []string{cn}, IPAddresses: []net.IP{net.IPv4(127, 0, 0, 1)}, IsCA: parent == nil, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign}
	if org == "" {
		tpl.Subject.Organization = nil
	}
	signer, signKey := tpl, key
	if parent != nil {
		signer, signKey = parent, parentKey
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, signer, &key.PublicKey, signKey)
	if err != nil {
		t.Fatal(err)
	}
	c, _ := x509.ParseCertificate(der)
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), c, key
}

func TestCertsLikeBash(t *testing.T) {
	dir := t.TempDir()
	caPem, ca, caKey := testCert(t, "Proef CA", "Proef BV", time.Now().Add(30*24*time.Hour).Truncate(time.Second), nil, nil)
	leafPem, leaf, leafKey := testCert(t, "nas.voorbeeld", "", time.Now().Add(90*24*time.Hour).Truncate(time.Second), ca, caKey)
	full := filepath.Join(dir, "fullchain.pem")
	os.WriteFile(full, append(append([]byte("# uitleg\n"), leafPem...), caPem...), 0o600)
	os.WriteFile(filepath.Join(dir, "leeg.pem"), []byte("geen certificaat\n"), 0o600)

	c := certFromFile(full)
	if c["cn"] != "nas.voorbeeld" || c["issuer"] != "Proef BV" || c["not_after"] != leaf.NotAfter.Unix() || c["verify"] != nil || c["error"] != "" ||
		c["chain_n"] != 2 || c["chain_min"] != ca.NotAfter.Unix() || c["chain_cn"] != "Proef CA" {
		t.Fatalf("bestand met keten: %v", c)
	}
	if e := certFromFile(filepath.Join(dir, "leeg.pem")); e["error"] != "geen geldig certificaat (PEM) in dit bestand" || e["not_after"] != nil || e["chain_n"] != 0 {
		t.Fatalf("leeg bestand: %v", e)
	}
	if e := certFromFile(filepath.Join(dir, "weg.pem")); e["error"] != "bestand niet gevonden of niet leesbaar" {
		t.Fatalf("ontbrekend bestand: %v", e)
	}
	// uitgever zonder organisatie: dan zijn naam
	selfPem, _, _ := testCert(t, "alleen-cn", "", time.Now().Add(time.Hour), nil, nil)
	os.WriteFile(filepath.Join(dir, "self.pem"), selfPem, 0o600)
	if s := certFromFile(filepath.Join(dir, "self.pem")); s["issuer"] != "alleen-cn" || s["chain_n"] != 1 {
		t.Fatalf("zelf ondertekend: %v", s)
	}
	// jokerteken: de treffers, en zonder treffers niets (geen fout)
	got := collectCertsNow([]string{filepath.Join(dir, "*.pem"), filepath.Join(dir, "niets-*.crt")})
	if len(got) != 3 {
		t.Fatalf("jokerteken: %d regels", len(got))
	}

	for in, want := range map[string][2]string{"nas.voorbeeld": {"nas.voorbeeld", "443"}, "https://nas.voorbeeld:8443/pad": {"nas.voorbeeld", "8443"}, "[2001:db8::1]:993": {"2001:db8::1", "993"}, "2001:db8::1": {"2001:db8::1", "443"}} {
		if h, p := certHostPort(in); h != want[0] || p != want[1] {
			t.Fatalf("%q → %q %q", in, h, p)
		}
	}
	srcs := wantedCerts(map[string]string{"CERTS": "/etc/ssl/a.pem  nas:443"}, "0\tcert\tmail.voorbeeld:993\n5\tcert\tfout\n0\tcert\tmet spatie x\n0\tsvc\tnginx\n")
	if strings.Join(srcs, "|") != "/etc/ssl/a.pem|nas:443|mail.voorbeeld:993" {
		t.Fatalf("bronnen: %v", srcs)
	}

	// een echte TLS-server op deze machine: het certificaat, de meegestuurde keten en "niet vertrouwd"
	pair := tls.Certificate{Certificate: [][]byte{leaf.Raw, ca.Raw}, PrivateKey: leafKey}
	ln, err := tls.Listen("tcp", "127.0.0.1:0", &tls.Config{Certificates: []tls.Certificate{pair}})
	if err != nil {
		t.Skip("kan niet luisteren")
	}
	defer ln.Close()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			go func() { conn.(*tls.Conn).Handshake(); conn.Close() }()
		}
	}()
	h := certFromHost(ln.Addr().String())
	if h["source"] != ln.Addr().String() || h["cn"] != "nas.voorbeeld" || h["chain_n"] != 2 || h["chain_cn"] != "Proef CA" || h["error"] != "" || h["verify"] == "ok" || h["verify"] == nil {
		t.Fatalf("host:poort: %v", h)
	}
	plain, _ := net.Listen("tcp", "127.0.0.1:0")
	addr := plain.Addr().String()
	plain.Close()
	if e := certFromHost(addr); e["error"] != "geen TLS-verbinding: poort dicht (verbinding geweigerd)" || e["verify"] != nil {
		t.Fatalf("dichte poort: %v", e)
	}
}
