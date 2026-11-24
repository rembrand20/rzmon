//go:build windows

package main

import (
	"context"
	"encoding/json"
	"os/exec"
	"strings"
	"sync"
	"time"
)

// Windows Update: hoeveel updates er klaarstaan (en hoeveel kritiek of belangrijk), via de Windows Update-API (COM).
// Zoeken kan minuten duren: op de achtergrond, hooguit eens per 6 uur; het rapport stuurt de laatste uitkomst mee.
// Een vast, ingebouwd script: de agent voert nooit opdrachten van de server uit.

const wuScript = `$ErrorActionPreference='Stop';$s=New-Object -ComObject Microsoft.Update.Session;$q=$s.CreateUpdateSearcher();` +
	`$r=$q.Search("IsInstalled=0 and IsHidden=0 and Type='Software'");` +
	`$l=@($r.Updates|ForEach-Object{[pscustomobject]@{t=$_.Title;s=[string]$_.MsrcSeverity;kb=(@($_.KBArticleIDs) -join ',')}});` +
	`[pscustomobject]@{n=$l.Count;crit=@($l|Where-Object{$_.s -eq 'Critical'}).Count;imp=@($l|Where-Object{$_.s -eq 'Important'}).Count;list=@($l|Select-Object -First 20)}|ConvertTo-Json -Compress -Depth 3`

var wuMu sync.Mutex
var wuBusy bool

func windowsUpdate() map[string]any {
	var cur map[string]any
	_ = json.Unmarshal([]byte(readState("wu.json")), &cur)
	at, _ := cur["at"].(float64)
	if time.Now().Unix()-int64(at) > 6*3600 {
		wuMu.Lock()
		if !wuBusy {
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
