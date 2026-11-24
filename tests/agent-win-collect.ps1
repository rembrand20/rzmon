# 1.313.0: op een echte Windows-machine (GitHub Actions, windows-latest): meet de Windows-agent wat RzSense verwacht?
# Draait "rzsense-agent.exe collect" (zonder iets te versturen) en controleert het rapport.
$ErrorActionPreference = 'Stop'
$exe = Join-Path $PSScriptRoot '..\agent-win\rzsense-agent.exe'
$json = & $exe collect | Out-String
$r = $json | ConvertFrom-Json
$fail = @()
function Expect($ok, $what) { if ($ok) { Write-Host "  ✔ $what" } else { Write-Host "  ✘ $what"; $script:fail += $what } }

Expect ($r.os -eq 'windows') 'os = windows'
Expect ($r.os_info.name -like 'Windows*') "Windows-versie: $($r.os_info.name) (build $($r.os_info.build))"
Expect ($r.boot -gt 1500000000) 'opstarttijd'
Expect ($r.cpu.cores -ge 1 -and $null -ne $r.cpu.pct) "CPU: $($r.cpu.pct)% van $($r.cpu.cores) kernen"
Expect ($r.mem.total -gt 1GB -and $r.mem.avail -gt 0 -and $r.mem.avail -le $r.mem.total) "geheugen: $([math]::Round($r.mem.total / 1GB, 1)) GB"
$c = $r.disks | Where-Object { $_.mount -eq 'C:' }
Expect ($null -ne $c -and $c.total -gt $c.avail -and $c.avail -gt 0) 'schijf C: met totaal en vrij'
Expect ($r.services.EventLog -eq 'active') "service EventLog draait ($($r.services.EventLog))"
Expect ($r.services.BestaatNiet -eq 'not-found') 'onbekende service = not-found'
Expect (@($r.svc_list).Count -ge 20) "lijst met services ($(@($r.svc_list).Count))"
Expect (@($r.proc_list).Count -ge 10) "lijst met processen ($(@($r.proc_list).Count))"
Expect ($r.procs.'4'.n -ge 1) "proces-check svchost.exe ($($r.procs.'4'.n) keer)"
Expect (@($r.ports | Where-Object { $_.port -eq 135 }).Count -ge 1) 'luisterende poort 135 (RPC) met proces'

if ($fail.Count) { Write-Host $json; Write-Error "Windows-agent: $($fail.Count) controle(s) mislukt" }
Write-Host '✔ De Windows-agent meet alles wat RzSense verwacht'
