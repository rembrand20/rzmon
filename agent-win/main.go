// RzSense-agent voor Windows (Windows Server 2019, 2022 en 2025, 64-bit).
//
//	rzsense-agent.exe install --url https://rzsense.voorbeeld --pair K7QF-M2XA-9RTD-HC4P-WE8N [--pin sha256//…] [--fallback URL]
//	rzsense-agent.exe uninstall [--purge]   service verwijderen (--purge: ook de instellingen en de sleutel)
//	rzsense-agent.exe once                  één ronde in dit venster, met wat er verstuurd wordt
//	rzsense-agent.exe status                instellingen en de laatste meldingen
//	rzsense-agent.exe collect               laat zien wat de agent meet (zonder te versturen)
//	rzsense-agent.exe sysmon <Sysmon64.exe> Sysmon installeren met de configuratie van RzSense
//	rzsense-agent.exe audit                 procescontrole (4688) en PowerShell-scripts (4104) laten loggen
//	rzsense-agent.exe version
//
// Als service (RzSenseAgent, LocalSystem) start Windows hem met "run".
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

var urlRe = regexp.MustCompile(`^https?://[A-Za-z0-9.\-\[\]:]+(/[A-Za-z0-9._~/-]*)?$`)

func main() {
	if cliLinux(os.Args) { // 0.8.0: op Linux het commando rzsense
		return
	}
	if isService() {
		if err := runService(); err != nil {
			logf("Service: %v", err)
			os.Exit(1)
		}
		return
	}
	console = true
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	switch os.Args[1] {
	case "run":
		if err := runService(); err != nil {
			fmt.Fprintln(os.Stderr, "Dit start Windows zelf als service; gebruik 'once' om één ronde te testen:", err)
			os.Exit(1)
		}
	case "install":
		os.Exit(cmdInstall(os.Args[2:]))
	case "uninstall":
		fs := flag.NewFlagSet("uninstall", flag.ExitOnError)
		purge := fs.Bool("purge", false, "ook de instellingen en de sleutel verwijderen")
		_ = fs.Parse(os.Args[2:])
		if err := uninstall(*purge); err != nil {
			fmt.Fprintln(os.Stderr, "Verwijderen mislukt:", err)
			os.Exit(1)
		}
		fmt.Println("✔ RzSense-agent verwijderd")
	case "once":
		c, err := loadConfig()
		if err != nil || c.Key == "" {
			fmt.Fprintln(os.Stderr, "Niet gekoppeld. Eerst: rzsense-agent.exe install --url … --pair …")
			os.Exit(1)
		}
		if len(os.Args) > 2 && os.Args[2] == "--show" {
			t := loadTests(c, true)
			b, _ := json.MarshalIndent(buildReport(c, t, map[string]int{}), "", "  ")
			fmt.Println(string(b))
		}
		if _, err := round(c, true); err != nil {
			fmt.Fprintln(os.Stderr, "✘", err)
			os.Exit(1)
		}
		fmt.Println("✔ Rapport verstuurd en het antwoord van RzSense klopt (🔒)")
	case "collect":
		// Wat de agent op deze computer meet, zonder iets te versturen (ook voor de test in GitHub Actions)
		t := tests{Tick: 60, Svc: map[string]string{"1": "EventLog", "2": "Dhcp", "3": "BestaatNiet"}, Procs: []procWant{{ID: "4", Value: "svchost.exe"}},
			Auth: true, Logs: []logCheck{{ID: "5", Source: "Application", Pattern: "RzSenseTest"}}, LogScan: 1, CertScan: 1}
		b, _ := json.MarshalIndent(buildReport(&Config{RemoteUpdate: true}, t, map[string]int{}), "", "  ")
		fmt.Println(string(b))
	case "sysmon":
		// Sysmon installeren met de ingebouwde configuratie (het bestand download je zelf van Microsoft Sysinternals)
		if len(os.Args) < 3 {
			fmt.Fprintln(os.Stderr, "Gebruik: rzsense-agent.exe sysmon C:\\pad\\naar\\Sysmon64.exe")
			os.Exit(2)
		}
		if err := installSysmon(os.Args[2]); err != nil {
			fmt.Fprintln(os.Stderr, "✘ Sysmon installeren mislukt:", err)
			os.Exit(1)
		}
		fmt.Println("✔ Sysmon draait met de configuratie van RzSense; de agent leest zijn gebeurtenissen voortaan mee")
	case "audit":
		// Procescontrole (4688, met opdrachtregel) en PowerShell-scripts (4104) laten loggen
		if err := enableAudit(); err != nil {
			fmt.Fprintln(os.Stderr, "✘ Aanzetten mislukt:", err)
			os.Exit(1)
		}
		fmt.Println("✔ Windows logt nu nieuwe processen (4688, met opdrachtregel) en PowerShell-scripts (4104)")
	case "status":
		c, err := loadConfig()
		fmt.Printf("RzSense-agent %s\nInstellingen: %s\n", Version, configPath())
		if err != nil {
			fmt.Println("  (nog niet ingesteld)")
			return
		}
		fmt.Printf("  Adres:        %s\n  Reserve:      %s\n  Vastgezet:    %v\n  Gekoppeld:    %v\n  Bijwerken:    %v\n",
			c.URL, dash(c.URLFallback), c.Pin != "", c.Key != "", c.RemoteUpdate)
		if b, err := os.ReadFile(filepath.Join(dataDir(), "agent.log")); err == nil {
			lines := strings.Split(strings.TrimSpace(string(b)), "\n")
			if len(lines) > 10 {
				lines = lines[len(lines)-10:]
			}
			fmt.Println("Laatste meldingen:")
			for _, l := range lines {
				fmt.Println("  " + strings.TrimSpace(l))
			}
		}
	case "version", "--version", "-v":
		fmt.Println(Version)
	default:
		usage()
		os.Exit(2)
	}
}

func dash(s string) string {
	if s == "" {
		return "-"
	}
	return s
}

func usage() {
	fmt.Println(`RzSense-agent voor Windows ` + Version + `

  rzsense-agent.exe install --url https://rzsense.voorbeeld --pair CODE [--pin sha256//…] [--fallback URL]
  rzsense-agent.exe install --url https://rzsense.voorbeeld --enroll CODE [--pin sha256//…]   (uitrolcode, veel servers)
  rzsense-agent.exe uninstall [--purge]
  rzsense-agent.exe once [--show]
  rzsense-agent.exe status
  rzsense-agent.exe collect
  rzsense-agent.exe sysmon C:\pad\Sysmon64.exe
  rzsense-agent.exe audit
  rzsense-agent.exe version

Starten als beheerder. De koppelcode maak je in RzSense: Agent Manager → 🔗 Koppelcode.`)
}

func cmdInstall(args []string) int {
	fs := flag.NewFlagSet("install", flag.ContinueOnError)
	url := fs.String("url", "", "adres van RzSense, bv. https://rzsense.voorbeeld")
	code := fs.String("pair", "", "koppelcode uit RzSense (Agent Manager → 🔗 Koppelcode)")
	enroll := fs.String("enroll", "", "uitrolcode uit RzSense, voor veel servers (Agent Manager → Windows → Uitrolcode en MSI)")
	pin := fs.String("pin", "", "vingerafdruk van het certificaat van RzSense (sha256//…), bij een eigen certificaat")
	fb := fs.String("fallback", "", "reserve-adres van RzSense (optioneel)")
	noUpd := fs.Bool("no-remote-update", false, "niet bijwerken vanuit RzSense")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	c, _ := loadConfig()
	if *url != "" {
		c.URL = strings.TrimRight(*url, "/")
	}
	if *fb != "" {
		c.URLFallback = strings.TrimRight(*fb, "/")
	}
	if *pin != "" {
		c.Pin = "sha256//" + strings.TrimPrefix(*pin, "sha256//")
	}
	if *noUpd {
		c.RemoteUpdate = false
	}
	if !urlRe.MatchString(c.URL) || (c.URLFallback != "" && !urlRe.MatchString(c.URLFallback)) {
		fmt.Fprintln(os.Stderr, "Geef het adres van RzSense op: --url https://…")
		return 2
	}
	if *code == "" && *enroll != "" {
		if c.Key == "" {
			*code = "enroll:" + *enroll
		} else {
			fmt.Println("Al gekoppeld met RzSense: de uitrolcode is niet nodig (alleen bijwerken).")
		}
	}
	if *code == "" && c.Key == "" {
		fmt.Fprintln(os.Stderr, "Geef een koppelcode op: --pair CODE (RzSense → Agent Manager → 🔗 Koppelcode), of een uitrolcode: --enroll CODE")
		return 2
	}
	if err := install(c, *code); err != nil {
		fmt.Fprintln(os.Stderr, "✘ Installeren mislukt:", err)
		return 1
	}
	fmt.Println("✔ RzSense-agent geïnstalleerd en gestart (service RzSenseAgent). Binnen een minuut staat deze server in RzSense.")
	return 0
}
