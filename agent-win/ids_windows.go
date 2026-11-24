//go:build windows

package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"os/exec"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// ---------------------------------------------------------------- gebeurtenissen

// collectIDS: verdachte gebeurtenissen sinds de vorige keer (per logboek een bladwijzer; de eerste keer vanaf nu)
func collectIDS() []map[string]any {
	r := newEvtReader()
	defer r.close()
	var out []map[string]any
	for _, c := range idsChannels {
		key := "evt.ids." + strings.NewReplacer("/", "_", " ", "_").Replace(c.Channel)
		since := uint64(atoiDef(readState(key), 0))
		if _, ok := channelRecords(c.Channel); !ok {
			continue // logboek bestaat hier niet (bv. geen Sysmon)
		}
		evs, mark, err := r.read(c.Channel, c.Filter, since, 2000, false)
		if err != nil {
			continue
		}
		writeState(key, strconv.FormatUint(mark, 10))
		for _, e := range evs {
			if ev := idsEvent(e); ev != nil && len(out) < 200 {
				out = append(out, ev)
			}
		}
	}
	return out
}

// ---------------------------------------------------------------- PowerShell (vaste scripts, met gecontroleerde invoer)

func ps(timeout time.Duration, script string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	out, err := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script).CombinedOutput()
	if err != nil {
		return strings.TrimSpace(string(out)), fmt.Errorf("%v: %s", err, oneLine(string(out)))
	}
	return strings.TrimSpace(string(out)), nil
}

func psList(xs []string) string {
	q := make([]string, len(xs))
	for i, x := range xs {
		q[i] = "'" + strings.ReplaceAll(x, "'", "''") + "'"
	}
	return "@(" + strings.Join(q, ",") + ")"
}

// ---------------------------------------------------------------- blokkeren

// syncBlocks: de geblokkeerde IP-adressen van RzSense in de Windows Firewall zetten (regelgroep "RzSense"), en weghalen
// wat RzSense niet meer blokkeert. Alleen bij een verandering.
func syncBlocks(want []blockWant, never []string) map[string]any {
	keep := map[string]bool{}
	for _, n := range never {
		keep[n] = true
	}
	var ips []string
	for _, b := range want {
		if ip := net.ParseIP(b.IP); ip != nil && !keep[ip.String()] && (b.Until == 0 || b.Until > time.Now().Unix()) {
			ips = append(ips, ip.String())
		}
		if len(ips) >= 1000 {
			break
		}
	}
	sort.Strings(ips)
	sig := strings.Join(ips, ",")
	if sig == readState("fw.blocks") {
		return map[string]any{"state": "ok", "n": len(ips)}
	}
	script := "$ErrorActionPreference='Stop';Get-NetFirewallRule -Group 'RzSense' -ErrorAction SilentlyContinue|Remove-NetFirewallRule;"
	if len(ips) > 0 {
		script += "$ips=" + psList(ips) + ";" +
			"New-NetFirewallRule -DisplayName 'RzSense blokkade (inkomend)' -Group 'RzSense' -Direction Inbound -Action Block -RemoteAddress $ips|Out-Null;" +
			"New-NetFirewallRule -DisplayName 'RzSense blokkade (uitgaand)' -Group 'RzSense' -Direction Outbound -Action Block -RemoteAddress $ips|Out-Null;"
	}
	if _, err := ps(2*time.Minute, script); err != nil {
		return map[string]any{"state": "error", "err": err.Error()}
	}
	writeState("fw.blocks", sig)
	if len(ips) > 0 {
		logf("Windows Firewall: %d IP-adres(sen) geblokkeerd", len(ips))
	}
	return map[string]any{"state": "ok", "n": len(ips)}
}

// ---------------------------------------------------------------- isoleren

// applyIsolation: zolang RzSense het vraagt alleen RzSense, de toegestane netwerken (je LAN) en het IP van de beheerder
// toelaten. Twee blokkeerregels (in en uit) voor de rest van IPv4 en heel IPv6 behalve link-local; die gaan voor elke
// toestaan-regel. Opheffen = die regels weg. Geeft de stand voor RzSense (iso: active, off of error).
func applyIsolation(c *Config, want *isolateWant) map[string]any {
	active := want != nil && want.Until > time.Now().Unix()
	if !active {
		if readState("iso.on") == "" {
			return nil
		}
		if _, err := ps(2*time.Minute, "Get-NetFirewallRule -Group 'RzSense-isolatie' -ErrorAction SilentlyContinue|Remove-NetFirewallRule"); err != nil {
			return map[string]any{"state": "error", "err": err.Error()}
		}
		dropState("iso.on")
		logf("Isolatie opgeheven")
		return map[string]any{"state": "off"}
	}
	allow := append([]string{}, want.Allow...)
	allow = append(allow, hostIPs(c.URL, c.URLFallback)...)
	ranges := complementV4(allow)
	sig := strings.Join(ranges, ",")
	if readState("iso.on") == sig {
		return map[string]any{"state": "active"}
	}
	if len(hostIPs(c.URL)) == 0 {
		return map[string]any{"state": "error", "err": "adres van RzSense niet op te zoeken: niet geïsoleerd (anders zou RzSense de server niet meer bereiken)"}
	}
	v6 := []string{"::-fe7f:ffff:ffff:ffff:ffff:ffff:ffff:ffff", "fec0::-ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff"}
	for _, a := range allow {
		if ip := net.ParseIP(a); ip != nil && ip.To4() == nil {
			v6 = nil // een toegestaan IPv6-adres: IPv6 niet dichtzetten (te fijn om hier uit te rekenen)
		}
	}
	script := "$ErrorActionPreference='Stop';Get-NetFirewallRule -Group 'RzSense-isolatie' -ErrorAction SilentlyContinue|Remove-NetFirewallRule;" +
		"$r=" + psList(append(ranges, v6...)) + ";" +
		"New-NetFirewallRule -DisplayName 'RzSense isolatie (inkomend)' -Group 'RzSense-isolatie' -Direction Inbound -Action Block -RemoteAddress $r|Out-Null;" +
		"New-NetFirewallRule -DisplayName 'RzSense isolatie (uitgaand)' -Group 'RzSense-isolatie' -Direction Outbound -Action Block -RemoteAddress $r|Out-Null;" +
		"Set-NetFirewallProfile -All -Enabled True"
	if _, err := ps(2*time.Minute, script); err != nil {
		return map[string]any{"state": "error", "err": err.Error()}
	}
	writeState("iso.on", sig)
	logf("Geïsoleerd tot %s: alleen RzSense, %s", time.Unix(want.Until, 0).Format("02-01 15:04"), strings.Join(want.Allow, ", "))
	return map[string]any{"state": "active"}
}

// ---------------------------------------------------------------- Defender

var avMu sync.Mutex
var avBusy bool

// defenderStatus: staat Microsoft Defender aan (realtime-bescherming) en hoe oud zijn de definities? Eens per 15 minuten.
func defenderStatus() map[string]any {
	var cur map[string]any
	_ = json.Unmarshal([]byte(readState("av.json")), &cur)
	at, _ := cur["at"].(float64)
	if time.Now().Unix()-int64(at) > 900 {
		avMu.Lock()
		if !avBusy {
			avBusy = true
			go func() {
				defer func() { avMu.Lock(); avBusy = false; avMu.Unlock() }()
				res := map[string]any{"at": time.Now().Unix()}
				out, err := ps(2*time.Minute, "Get-MpComputerStatus|Select-Object AMServiceEnabled,AntivirusEnabled,RealTimeProtectionEnabled,AntivirusSignatureAge,IsTamperProtected|ConvertTo-Json -Compress")
				var r map[string]any
				if err != nil || json.Unmarshal([]byte(out), &r) != nil {
					res["present"] = false // geen Defender (of een andere virusscanner)
				} else {
					res["present"] = true
					res["on"] = r["AntivirusEnabled"] == true && r["AMServiceEnabled"] == true
					res["rt"] = r["RealTimeProtectionEnabled"] == true
					res["sig_age"] = r["AntivirusSignatureAge"]
					res["tamper"] = r["IsTamperProtected"] == true
				}
				b, _ := json.Marshal(res)
				writeState("av.json", string(b))
			}()
		}
		avMu.Unlock()
	}
	return cur
}

// ---------------------------------------------------------------- Sysmon en procescontrole (alleen als de beheerder het vraagt)

// sysmonConfig: een kleine configuratie: processen (1), netwerkverbindingen van shells en scripts (3), met de hash
const sysmonConfig = `<Sysmon schemaversion="4.90">
  <HashAlgorithms>SHA256</HashAlgorithms>
  <EventFiltering>
    <RuleGroup name="" groupRelation="or">
      <ProcessCreate onmatch="exclude"/>
    </RuleGroup>
    <RuleGroup name="" groupRelation="or">
      <NetworkConnect onmatch="include">
        <Image condition="end with">powershell.exe</Image>
        <Image condition="end with">pwsh.exe</Image>
        <Image condition="end with">cmd.exe</Image>
        <Image condition="end with">mshta.exe</Image>
        <Image condition="end with">rundll32.exe</Image>
        <Image condition="end with">regsvr32.exe</Image>
        <Image condition="end with">certutil.exe</Image>
      </NetworkConnect>
    </RuleGroup>
  </EventFiltering>
</Sysmon>
`

// installSysmon: Sysmon installeren met de ingebouwde configuratie; het bestand (Sysmon64.exe van Microsoft
// Sysinternals) download je zelf: de agent haalt niets van internet
func installSysmon(exe string) error {
	if st, err := os.Stat(exe); err != nil || st.IsDir() {
		return fmt.Errorf("%s niet gevonden", exe)
	}
	cfg := dataDir() + `\sysmon.xml`
	if err := os.WriteFile(cfg, []byte(sysmonConfig), 0o600); err != nil {
		return err
	}
	out, err := exec.Command(exe, "-accepteula", "-i", cfg).CombinedOutput()
	if err != nil {
		// Al geïnstalleerd: dan alleen de configuratie bijwerken
		if out2, err2 := exec.Command(exe, "-c", cfg).CombinedOutput(); err2 != nil {
			return fmt.Errorf("%v: %s %s", err, oneLine(string(out)), oneLine(string(out2)))
		}
	}
	return nil
}

// enableAudit: procescontrole aanzetten (4688, met de opdrachtregel) en het loggen van PowerShell-scripts (4104)
func enableAudit() error {
	script := "$ErrorActionPreference='Stop';auditpol /set /subcategory:'{0CCE922B-69AE-11D9-BED3-505054503030}' /success:enable|Out-Null;" +
		"New-Item -Force 'HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System\\Audit'|Out-Null;" +
		"Set-ItemProperty 'HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System\\Audit' ProcessCreationIncludeCmdLine_Enabled 1 -Type DWord;" +
		"New-Item -Force 'HKLM:\\SOFTWARE\\Policies\\Microsoft\\Windows\\PowerShell\\ScriptBlockLogging'|Out-Null;" +
		"Set-ItemProperty 'HKLM:\\SOFTWARE\\Policies\\Microsoft\\Windows\\PowerShell\\ScriptBlockLogging' EnableScriptBlockLogging 1 -Type DWord"
	_, err := ps(time.Minute, script)
	return err
}
