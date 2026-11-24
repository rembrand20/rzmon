# Changelog Windows-agent

Wijzigingen aan de RzSense-agent voor Windows (`rzsense-agent.exe`), per versie. Nieuwste bovenaan.
De Windows-agent heeft een eigen versienummer (`agent-win/VERSION`), los van RzSense en van de Linux-agent.
Bijwerken: `tools/bump-version.sh agentwin patch|minor|major` (zie [CLAUDE.md](../CLAUDE.md)).

## 0.1.0 – 2026-11-24
- **Eerste versie (fase 1: monitoring)** voor Windows Server 2019, 2022 en 2025 (64-bit), als Windows-service *RzSense Agent* (LocalSystem).
- Installeren en koppelen in één opdracht: `rzsense-agent.exe install --url … --pair CODE`. Een eigen certificaat van RzSense wordt vastgezet (`--pin`), net als bij de Linux-agent.
- Versleuteld en ondertekend, precies zoals de Linux-agent (AES-256 en HMAC-SHA256); de eigen sleutel staat versleuteld met DPAPI.
- Meet elke minuut: CPU, geheugen en wisselbestand, vaste schijven (C:, D: …), opstarttijd, wachtende herstart, luisterende poorten met het proces, de status van bewaakte Windows-services, en proces-checks. De lijst met services en processen om uit te kiezen gaat mee.
- Bijwerken vanuit RzSense: alleen een .exe met een geldige ed25519-handtekening; Windows start de service daarna opnieuw.
- Reserve-adres (`--fallback`) als het gewone adres niet bereikbaar is.
