# Changelog Windows-agent

Wijzigingen aan de RzSense-agent voor Windows (`rzsense-agent.exe`), per versie. Nieuwste bovenaan.
De Windows-agent heeft een eigen versienummer (`agent-win/VERSION`), los van RzSense en van de Linux-agent.
Bijwerken: `tools/bump-version.sh agentwin patch|minor|major` (zie [CLAUDE.md](../CLAUDE.md)).

## 0.4.0 – 2026-11-24
- **Fase 4: Windows-updates installeren** als RzSense erom vraagt (Updates: met de hand, in het onderhoudsvenster of elke dag): alleen beveiligings- en kritieke updates, of alles. Op de achtergrond via de Windows Update-API, met een vast, ingebouwd script; licenties worden geaccepteerd; nooit een herstart. De uitkomst gaat meteen naar RzSense (lukt dat niet, dan met het volgende rapport).
- De lijst met klaarstaande updates gaat nu ook naar **Updates** (KB-nummer, titel, beveiliging, soort), en per beveiligingsupdate de **CVE's** naar **Kwetsbaarheden**.
- Na installeren zoekt de agent meteen opnieuw naar updates.

## 0.3.0 – 2026-11-24
- **Fase 3: IDS en EDR.** Leest de belangrijke beveiligingsgebeurtenissen (System, Security, Microsoft Defender, PowerShell en Sysmon) en stuurt alleen wat ertoe doet: nieuwe services en geplande taken, nieuwe gebruikers en groepsleden, gewiste logboeken, malware, Defender uitgezet, verdachte PowerShell-scripts en een webserver of database die een shell start.
- **Microsoft Defender**: status van de realtime-bescherming en leeftijd van de definities (eens per 15 minuten).
- **Blokkeren** (modus *blokkeren* van het IDS) en **isoleren** via de Windows Firewall, in de regelgroepen *RzSense* en *RzSense-isolatie*. Bij isoleren blijven RzSense, loopback en de toegestane netwerken bereikbaar; kan de agent het adres van RzSense niet vinden, dan isoleert hij niet.
- **IIS-logs**: per IP een samenvatting (aantal, 404, 401/403, scanner, paden met een foutcode, nooit de querystring) voor de webaanvallen.
- **Logbestanden op Windows** (IIS, Windows Firewall, SMTP, DNS) naar de pagina Logs, per bestand een eigen plek.
- Nieuwe opdrachten: `sysmon <pad naar Sysmon64.exe>` (installeert Sysmon met een ingebouwde, rustige instelling; de agent downloadt Sysmon nooit zelf) en `audit` (procesaudit met opdrachtregel en PowerShell-scriptlogging).

## 0.2.0 – 2026-11-24
- **Fase 2: logs en logins.** Windows-logboeken (System, Application, Security, Sysmon, Defender, PowerShell …) naar de pagina **Logs**, met de tekst zoals Logboeken hem toont. Per logboek een bladwijzer: niets dubbel, en lukt versturen niet, dan de volgende keer vanaf dezelfde plek.
- **Log-checks** op een Windows-logboek (doel bv. `System`, zoekpatroon bv. `onverwacht beëindigd`).
- **Logins** uit het Beveiliging-logboek: gelukte console- en RDP-logins, en mislukte logins (ook via het netwerk, bv. SMB), per IP-adres opgeteld.
- **Certificaten**: via `host:poort`, en uit de certificaatopslag van de computer (Persoonlijk, Webhosting, Extern bureaublad), om bij een SSL-check uit te kiezen.
- **Windows Update**: hoeveel updates er klaarstaan (en hoeveel kritiek of belangrijk), op de achtergrond eens per 6 uur.

## 0.1.0 – 2026-11-24
- **Eerste versie (fase 1: monitoring)** voor Windows Server 2019, 2022 en 2025 (64-bit), als Windows-service *RzSense Agent* (LocalSystem).
- Installeren en koppelen in één opdracht: `rzsense-agent.exe install --url … --pair CODE`. Een eigen certificaat van RzSense wordt vastgezet (`--pin`), net als bij de Linux-agent.
- Versleuteld en ondertekend, precies zoals de Linux-agent (AES-256 en HMAC-SHA256); de eigen sleutel staat versleuteld met DPAPI.
- Meet elke minuut: CPU, geheugen en wisselbestand, vaste schijven (C:, D: …), opstarttijd, wachtende herstart, luisterende poorten met het proces, de status van bewaakte Windows-services, en proces-checks. De lijst met services en processen om uit te kiezen gaat mee.
- Bijwerken vanuit RzSense: alleen een .exe met een geldige ed25519-handtekening; Windows start de service daarna opnieuw.
- Reserve-adres (`--fallback`) als het gewone adres niet bereikbaar is.
