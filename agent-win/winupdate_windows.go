//go:build windows

package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"os"
	"os/exec"
	"strings"
	"sync"
	"time"
)

// Windows Update: hoeveel updates er klaarstaan (en hoeveel kritiek of belangrijk), via de Windows Update-API (COM).
// Zoeken kan minuten duren: op de achtergrond, hooguit eens per 6 uur; het rapport stuurt de laatste uitkomst mee.
// Fase 4: updates installeren als RzSense erom vraagt (Updates in RzSense: met de hand, in het onderhoudsvenster of
// elke dag). Vaste, ingebouwde scripts: de agent voert nooit opdrachten van de server uit, en herstart nooit zelf.

const wuScript = `$ErrorActionPreference='Stop';$s=New-Object -ComObject Microsoft.Update.Session;$q=$s.CreateUpdateSearcher();` +
	`$r=$q.Search("IsInstalled=0 and IsHidden=0 and Type='Software'");` +
	`$a=@($r.Updates|Select-Object -First 300|ForEach-Object{$kb=@($_.KBArticleIDs)|Select-Object -First 1;$cat=@($_.Categories|ForEach-Object{$_.Name});` +
	`[pscustomobject]@{kb=$(if($kb){"KB$kb"}else{'upd-'+$_.Identity.UpdateID.Substring(0,8)});t=$_.Title;s=[string]$_.MsrcSeverity;` +
	`sec=(([string]$_.MsrcSeverity -ne '') -or ($cat -contains 'Security Updates') -or ($cat -contains 'Critical Updates'));cat=($cat|Select-Object -First 1);cve=@($_.CveIDs)}});` +
	`$l=@($a|ForEach-Object{[pscustomobject]@{t=$_.t;s=$_.s;kb=$_.kb}});$rb=$false;try{$rb=(New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired}catch{};` +
	`[pscustomobject]@{n=$l.Count;crit=@($l|Where-Object{$_.s -eq 'Critical'}).Count;imp=@($l|Where-Object{$_.s -eq 'Important'}).Count;rb=$rb;` +
	`list=@($l|Select-Object -First 20);all=$a}|ConvertTo-Json -Compress -Depth 4`

// wuInstallScript: de klaarstaande updates downloaden en installeren; RZ_WU_MODE = security (alleen beveiliging en
// kritiek) of all. Licenties worden geaccepteerd (anders installeert Windows Update niets); geen herstart.
const wuInstallScript = `$ErrorActionPreference='Stop';$s=New-Object -ComObject Microsoft.Update.Session;$s.ClientApplicationID='RzSense';$q=$s.CreateUpdateSearcher();` +
	`$r=$q.Search("IsInstalled=0 and IsHidden=0 and Type='Software'");$c=New-Object -ComObject Microsoft.Update.UpdateColl;` +
	`foreach($u in $r.Updates){$cat=@($u.Categories|ForEach-Object{$_.Name});$sec=([string]$u.MsrcSeverity -ne '') -or ($cat -contains 'Security Updates') -or ($cat -contains 'Critical Updates');` +
	`if($env:RZ_WU_MODE -eq 'all' -or $sec){if(-not $u.EulaAccepted){$u.AcceptEula()};[void]$c.Add($u)}};` +
	`if($c.Count -eq 0){[pscustomobject]@{ok=$true;n=0;rb=$false;log='Geen updates om te installeren'}|ConvertTo-Json -Compress;exit};` +
	`$d=$s.CreateUpdateDownloader();$d.Updates=$c;[void]$d.Download();$i=$s.CreateUpdateInstaller();$i.Updates=$c;$res=$i.Install();` +
	`$w=@('niet begonnen','bezig','gelukt','gelukt met fouten','mislukt','afgebroken');` +
	`$lines=for($k=0;$k -lt $c.Count;$k++){$c.Item($k).Title+': '+$w[$res.GetUpdateResult($k).ResultCode]};` +
	`[pscustomobject]@{ok=($res.ResultCode -eq 2 -or $res.ResultCode -eq 3);n=$c.Count;rb=$res.RebootRequired;log=($lines -join "` + "`" + `n")}|ConvertTo-Json -Compress`

var wuMu sync.Mutex
var wuBusy, wuInstalling bool

func windowsUpdate() map[string]any {
	var cur map[string]any
	_ = json.Unmarshal([]byte(readState("wu.json")), &cur)
	at, _ := cur["at"].(float64)
	if time.Now().Unix()-int64(at) > 6*3600 {
		wuMu.Lock()
		if !wuBusy && !wuInstalling {
			wuBusy = true
			go func() {
				defer func() { wuMu.Lock(); wuBusy = false; wuMu.Unlock() }()
				res := map[string]any{"at": time.Now().Unix()}
				ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
				defer cancel()
				out, err := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", wuScript).Output()
				var r map[string]any
				if err != nil || json.Unmarshal([]byte(strings.TrimSpace(string(out))), &r) != nil {
					msg := "Windows Update niet te bevragen"
					if err != nil {
						msg += ": " + oneLine(err.Error())
					}
					res["error"] = msg
				} else {
					for k, v := range r {
						res[k] = v
					}
				}
				b, _ := json.Marshal(res)
				writeState("wu.json", string(b))
			}()
		}
		wuMu.Unlock()
	}
	return cur
}

// installWindowsUpdates: op de achtergrond installeren (kan een uur duren). De uitkomst gaat meteen naar RzSense; lukt
// dat niet, dan met het volgende rapport (pkg_result).
func installWindowsUpdates(c *Config, id, mode string) {
	if !jobIDRe.MatchString(id) || (mode != "security" && mode != "all") {
		return
	}
	wuMu.Lock()
	if wuInstalling || readState("wu.job") == id {
		wuMu.Unlock()
		return // loopt al, of al gedaan
	}
	wuInstalling = true
	wuMu.Unlock()
	writeState("wu.job", id)
	logf("Windows-updates installeren (%s), gevraagd door RzSense", mode)
	go func() {
		defer func() { wuMu.Lock(); wuInstalling = false; wuMu.Unlock() }()
		progress := func(m map[string]any) bool {
			b, _ := json.Marshal(m)
			_, code, err := sealedCall(c, "agent_pkg_progress", b, map[string]string{"Content-Type": "application/json"}, 30*time.Second)
			return err == nil && code == 200
		}
		progress(map[string]any{"id": id, "stage": "download", "pct": 5, "detail": "Windows Update"})
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Hour)
		defer cancel()
		cmd := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", wuInstallScript)
		cmd.Env = append(os.Environ(), "RZ_WU_MODE="+mode)
		out, err := cmd.Output()
		res := wuInstallResult(id, strings.TrimSpace(string(out)), err)
		dropState("wu.json") // meteen opnieuw zoeken: de lijst is veranderd
		if !progress(res) {
			b, _ := json.Marshal(res)
			writeState("wu.result", string(b))
		}
		logf("Windows-updates installeren klaar: ok=%v", res["ok"])
	}()
}

func wuInstallResult(id, out string, err error) map[string]any {
	res := map[string]any{"id": id, "done": 1, "ok": false}
	var r map[string]any
	if err != nil || json.Unmarshal([]byte(out), &r) != nil {
		msg := "Windows Update: installeren mislukt"
		if err != nil {
			msg += ": " + oneLine(err.Error())
		}
		res["log_b64"] = base64.StdEncoding.EncodeToString([]byte(msg))
		return res
	}
	log, _ := r["log"].(string)
	if rb, _ := r["rb"].(bool); rb {
		log += "\nHerstart nodig om de updates af te maken (de agent herstart nooit zelf)."
	}
	res["ok"], _ = r["ok"].(bool)
	res["log_b64"] = base64.StdEncoding.EncodeToString([]byte(log))
	return res
}
