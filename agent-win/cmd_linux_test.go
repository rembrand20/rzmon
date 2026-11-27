//go:build linux

package main

import (
	"encoding/base64"
	"strings"
	"testing"
)

func TestParseCmdChecks(t *testing.T) {
	b := func(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
	got := parseCmdChecks("0\ttick\t60\n7\tcmd\t" + b("uptime") + "\t5\troot\t300\n8\tcmd\t" + b("id") + "\t999\tiemand\tx\n9\tcmd\t!!!\t10\tnobody\t60\nx\tcmd\t" + b("id") + "\t1\troot\t60\n")
	if len(got) != 3 {
		t.Fatalf("aantal: %d (een regel zonder nummer telt niet)", len(got))
	}
	if got[0] != (cmdCheck{id: "7", cmd: "uptime", timeout: 5, runas: "root", every: 300}) {
		t.Fatalf("eerste: %+v", got[0])
	}
	if got[1].timeout != 10 || got[1].runas != "nobody" || got[1].every != 60 {
		t.Fatalf("een rare time-out, gebruiker of interval hoort naar de veilige standaard te gaan: %+v", got[1])
	}
	if got[2].cmd != "" {
		t.Fatal("ongeldige base64 hoort geen commando op te leveren")
	}
}

func TestCmdArgvAndText(t *testing.T) {
	argv, why := cmdArgv(cmdCheck{id: "1", cmd: "echo hoi; rm -rf /tmp/x", timeout: 7, runas: "root"})
	if why != "" || argv[0] != "env" || argv[1] != "-i" || argv[len(argv)-1] != "echo hoi; rm -rf /tmp/x" || argv[len(argv)-2] != "-c" || argv[len(argv)-3] != "/bin/bash" {
		t.Fatalf("omhulsel: %v %q", argv, why)
	}
	if j := strings.Join(argv, " "); !strings.Contains(j, "timeout -k 2 7 /bin/bash -c") || !strings.Contains(j, "HOME=/") {
		t.Fatalf("time-out of kale omgeving ontbreekt: %s", j)
	}
	if got := jsonText([]byte("a\tb\r\n\x01c\n\n"), 100); got != "a\tb\nc\n" {
		t.Fatalf("tekst: %q", got)
	}
	if got := jsonText([]byte(strings.Repeat("x", 5000)), 4096); len(got) != 4096 {
		t.Fatalf("lengte: %d", len(got))
	}
}
