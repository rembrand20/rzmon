# 1.313.0: op een echte Windows-machine (GitHub Actions, windows-latest): meet de Windows-agent wat RzSense verwacht?
# Draait "rzsense-agent.exe collect" (zonder iets te versturen) en controleert het rapport.
# 1.314.0 (fase 2): ook logboeken (een eigen testgebeurtenis in Application), logins, certificaten en de lijst met logboeken.
$ErrorActionPreference = 'Stop'
$exe = Join-Path $PSScriptRoot '..\agent-win\rzsense-agent.exe'
$env:RZSENSE_DATA = Join-Path $env:RUNNER_TEMP 'rzsense-data'
New-Item -ItemType Directory -Force -Path $env:RZSENSE_DATA | Out-Null
$fail = @()
function Expect($ok, $what) { if ($ok) { Write-Host "  ✔ $what" } else { Write-Host "  ✘ $what"; $script:fail += $what } }

# 1.315.0: een IIS-logmap, zodat de agent hem moet vinden
New-Item -ItemType Directory -Force -Path 'C:\inetpub\logs\LogFiles\W3SVC9' | Out-Null
Set-Content -Path 'C:\inetpub\logs\LogFiles\W3SVC9\u_ex261124.log' -Value '#Fields: date time cs-uri-stem','2026-11-24 10:00:00 /index.html'
# Eerste ronde zet de bladwijzers; dan een eigen gebeurtenis schrijven; de tweede ronde moet hem vinden
& $exe collect | Out-Null
if (-not [System.Diagnostics.EventLog]::SourceExists('RzSenseTest')) { New-EventLog -LogName Application -Source RzSenseTest }
Write-EventLog -LogName Application -Source RzSenseTest -EventId 4242 -EntryType Error -Message 'RzSenseTest: proefgebeurtenis van de test'
Start-Sleep -Seconds 2
# De lijsten met logboeken en certificaten gaan eens per dag mee: voor de tweede ronde opnieuw laten zoeken
Remove-Item -ErrorAction SilentlyContinue (Join-Path $env:RZSENSE_DATA 'logscan.req'), (Join-Path $env:RZSENSE_DATA 'certscan.req')
$json = & $exe collect | Out-String
$r = $json | ConvertFrom-Json

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
# Fase 2
$lc = $r.logs.'5'
Expect ($lc.new -ge 1 -and (@($lc.lines) -join ' ') -match 'ERROR RzSenseTest\[4242\]: RzSenseTest: proefgebeurtenis') "log-check vindt de testgebeurtenis ($($lc.new)): $(@($lc.lines)[-1])"
Expect ($null -eq $lc.error) 'log-check zonder fout'
$scan = @($r.log_scan.winevt | ForEach-Object { $_[0] })
Expect ($scan -contains 'System' -and $scan -contains 'Application' -and $scan -contains 'Security') "logboeken gevonden: $($scan -join ', ')"
Expect ($null -ne $r.auth -and $null -ne $r.auth.events) "logins gelezen uit Beveiliging ($(@($r.auth.events).Count) gelukt, $(@($r.auth.fails).Count) mislukt)"
$wf = @($r.log_scan.winfiles | ForEach-Object { $_[0] })
Expect ($wf -contains 'C:\inetpub\logs\LogFiles\W3SVC9\*.log') "logbestanden gevonden: $($wf -join ', ')"
$ids = $r.ids
Expect ($null -eq $ids -or $null -ne $ids.win) 'IDS-gebeurtenissen gelezen (of IDS uit)'
Expect ($null -ne $r.cert_found) "certificaatopslag gelezen ($(@($r.cert_found).Count) certificaten)"

if ($fail.Count) { Write-Host $json; Write-Error "Windows-agent: $($fail.Count) controle(s) mislukt" }
Write-Host '✔ De Windows-agent meet alles wat RzSense verwacht'
