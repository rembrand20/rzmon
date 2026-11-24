# RzSense-agent voor Windows

De agent voor Windows Server 2019, 2022 en 2025 (64-bit), geschreven in Go. Hij draait als Windows-service
*RzSense Agent* (`RzSenseAgent`, LocalSystem) en stuurt hetzelfde rapport als de Linux-agent (`agent/`), versleuteld en
ondertekend op precies dezelfde manier.

Dit is de README voor ontwikkelaars. **Installeren en gebruiken:** zie [Windows-agent](../docs/06-windows-agent.md).

## Bouwen en testen

Werkt op Linux en Windows. Draai dit vóór elke commit:

```
cd agent-win
gofmt -l .
go vet ./... && GOOS=windows go vet ./...
go test ./...
```

Zelf een `.exe` bouwen:

```
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w -buildid=" -o rzsense-agent.exe .
```

Andere tests:
- `BASE=http://127.0.0.1:8099 bash ../tests/agent-win-e2e.sh`: koppelen, aanmelden met een uitrolcode, testlijst,
  rapport en antwoord, tegen een draaiende RzSense (`e2e_test.go`).
- `tests/agent-win-collect.ps1`: draait `rzsense-agent.exe collect` op een echte Windows-machine en controleert wat hij
  meet (in GitHub Actions op `windows-latest`).

## Uitgeven

- **Versienummer:** `tools/bump-version.sh agentwin patch|minor|major` werkt `VERSION` en `Version` in `agent.go` bij.
  Schrijf de wijzigingen in [CHANGELOG.md](CHANGELOG.md). Een nieuwe Windows-agent krijgt ook een patch-versie van
  RzSense (zie [CLAUDE.md](../CLAUDE.md)).
- **De `.exe` en de MSI** in `dist/` (met hun `.sig`) maakt GitHub Actions (*Windows-agent bouwen*,
  `.github/workflows/build-agent-win.yml`). Zet ze nooit zelf in git.
- De build is reproduceerbaar (`-trimpath`, zonder build-id): iedereen kan nagaan dat de `.exe` uit deze code komt.
- De MSI wordt met WiX 4 gebouwd uit `msi/rzsense-agent.wxs`, met dezelfde `.exe`.

## Opbouw van de code

Bestanden met `_windows.go` worden alleen voor Windows gebouwd. `platform_other.go` vult die aan, zodat de algemene code
ook op Linux te testen is.

| Bestand | Wat |
| --- | --- |
| `main.go` | Opdrachten: `install`, `uninstall`, `once`, `status`, `collect`, `sysmon`, `audit`, `version` |
| `agent.go` | Koppelen, testlijst, het rapport, het antwoord verwerken; `Version` |
| `crypto.go` | Versleuteling, koppelcode en ondertekende verzoeken, gelijk aan `src/AgentCrypto.php` (`crypto_test.go` controleert dat met waarden uit PHP) |
| `client.go` | Verbinding met RzSense, vastgezet certificaat (PIN), reserve-adres |
| `urlswitch.go` | Reserve-RzSense en overstappen naar een ander adres |
| `config.go` | `C:\ProgramData\RzSense\agent.conf` (sleutel met DPAPI), dezelfde namen als `/etc/default/rzmon-agent` |
| `update.go` | Bijwerken: alleen een `.exe` met een geldige ed25519-handtekening |
| `service_windows.go` | De service, installeren en verwijderen, DPAPI, rechten, logboek |
| `collect_windows.go` | CPU, geheugen, schijven, services, processen, poorten, Windows-versie |
| `eventlog_windows.go` | Windows-logboeken lezen (Event Log-API), per logboek een bladwijzer |
| `logs.go` | Logboeken naar de pagina Logs, log-checks, logins |
| `winfile.go` | Logbestanden (IIS, Windows Firewall, SMTP, DNS) |
| `certs.go`, `store_windows.go` | Certificaten via `host:poort` en uit de certificaatopslag |
| `ids.go`, `ids_windows.go` | IDS en EDR: beveiligingsgebeurtenissen, Defender, blokkeren en isoleren (Windows Firewall), Sysmon, audit |
| `weblog.go` | Webaanvallen in de IIS-logs, per IP samengevat |
| `winupdate_windows.go` | Windows Update: zoeken en installeren |
| `wupatch.go` | Updates en CVE's in het formaat van *Updates* en *Kwetsbaarheden* |
| `platform_other.go` | Lege versies van de Windows-functies, om op Linux te testen |
| `msi/rzsense-agent.wxs` | De MSI, met de eigenschappen `URL`, `ENROLL` en `PIN` |
