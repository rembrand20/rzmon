# RzSense-agent voor Windows

De agent voor Windows Server 2019, 2022 en 2025 (64-bit), in Go. Hij draait als Windows-service *RzSense Agent*
(`RzSenseAgent`, LocalSystem) en stuurt hetzelfde rapport als de Linux-agent (`agent/`), versleuteld en ondertekend
op precies dezelfde manier. Gebruik en installeren: zie `HANDLEIDING.md`, hoofdstuk 8.16.

| Bestand | Wat |
| --- | --- |
| `crypto.go` | versleuteling, koppelcode en ondertekende verzoeken (gelijk aan `src/AgentCrypto.php`; `crypto_test.go` controleert dat met waarden uit PHP) |
| `client.go` | verbinding met RzSense, vastgezet certificaat (PIN), reserve-adres |
| `agent.go` | koppelen, testlijst, het rapport, het antwoord verwerken; `Version` |
| `update.go` | bijwerken: alleen een `.exe` met een geldige ed25519-handtekening |
| `config.go` | `C:\ProgramData\RzSense\agent.conf` (sleutel met DPAPI) |
| `collect_windows.go` | metingen (CPU, geheugen, schijven, services, processen, poorten, Windows-versie) |
| `service_windows.go` | de service, installeren en verwijderen, DPAPI, rechten, logboek |
| `platform_other.go` | alleen om de algemene code ook op Linux te testen |

Ontwikkelen (op Linux of Windows):

```
cd agent-win
gofmt -l . && go vet ./... && GOOS=windows go vet ./... && go test ./...
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -trimpath -ldflags "-s -w -buildid=" -o rzsense-agent.exe .
BASE=http://127.0.0.1:8099 bash ../tests/agent-win-e2e.sh   # tegen een draaiende RzSense
```

De `.exe` in `dist/` (met `.sig`) maakt GitHub Actions (`.github/workflows/build-agent-win.yml`); de build is
reproduceerbaar (`-trimpath`, zonder build-id), dus iedereen kan nagaan dat de `.exe` uit deze code komt.
