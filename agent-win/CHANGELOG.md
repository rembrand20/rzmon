# Changelog Go-agent (Windows en Linux)

Wijzigingen aan de RzSense-agent in Go, per versie. Nieuwste bovenaan. Dezelfde code bouwt `rzsense-agent.exe` voor
Windows en, vanaf 0.8.0, het commando `rzsense` voor Linux (als proef naast de bash-agent).
De Go-agent heeft een eigen versienummer (`agent-win/VERSION`), los van RzSense en van de bash-agent voor Linux.
Bijwerken: `tools/bump-version.sh agentwin patch|minor|major` (zie [CLAUDE.md](../CLAUDE.md)).

## 0.16.0 – 2026-11-27
- **Linux: de softwarelijst voor Kwetsbaarheden.** De geïnstalleerde pakketten (dpkg of rpm) met de distributie, de draaiende en de nieuwste kernel, diensten die een herstart nodig hebben en de pakketten van programma's die op een poort luisteren. De hele lijst gaat één keer per dag mee of als de pakketdatabase verandert; anders alleen het controlegetal. Zelfde inhoud als de bash-agent.
- **Linux: de klok** (chrony of systemd-timesyncd): gesynchroniseerd en hoeveel ms hij afwijkt, voor de check *Tijd*.
- In de proef vergelijkt RzSense de klok elke ronde, en de softwarelijst kort nadat die opnieuw gemaakt is.
- **Windows:** geen wijzigingen in de werking.

## 0.15.1 – 2026-11-27
- Alleen voor de tests: de controle *draait als root* voor Updates is in de automatische tests na te bootsen. Geen wijziging in de werking.

## 0.15.0 – 2026-11-27
- **Linux: Updates.** De Go-agent kijkt eens per uur welke updates er klaarstaan, welke daarvan beveiligingsupdates zijn en of een herstart nodig is (dnf/yum of apt), met dezelfde uitkomst als de bash-agent.
- **Updates installeren op verzoek van RzSense** (alle updates, alleen beveiliging, of een lijst pakketten), met de voortgang en de uitkomst naar RzSense. Alleen het vaste commando van de pakketbeheerder; pakketnamen worden gecontroleerd. Nooit een herstart, nooit op TrueNAS, en niet met `COMMANDS=0`. Nieuwe pakketten installeren doet de Go-agent niet.
- In de proef kijkt de Go-agent alleen, vlak nadat de bash-agent keek en zonder zelf iets te verversen, zodat RzSense de twee lijsten kan vergelijken. Installeren doet hij alleen als hij het werk doet.
- De pakketbeheerder draait in een eigen tijdelijke systemd-eenheid, buiten de afscherming van de agent zelf.
- **Windows:** geen wijzigingen in de werking.

## 0.14.0 – 2026-11-27
- **Linux: logins, sudo en su.** Doet de Go-agent het werk, dan leest hij de nieuwe regels van het auth-log (journal, anders `/var/log/secure` of `/var/log/auth.log`) en stuurt hij gelukte logins, sudo, su, het einde van een SSH-sessie en de mislukte pogingen naar RzSense, precies zoals de bash-agent. Wachtwoorden in sudo-commando's worden op de server al gemaskeerd.
- De Go-agent houdt *waar was ik gebleven* bij in hetzelfde bestand als de bash-agent. Bij het overnemen en bij het teruggaan gaat er zo geen login verloren en komt er geen dubbel.
- `AUTH_EVENTS=0`, `AUTH_SOURCE=file` en `AUTH_LOGS` in de instellingen van de agent werken zoals bij de bash-agent.
- De timer mag daarvoor in `/var/cache/rzmon-agent` schrijven: voer het installatiecommando één keer opnieuw uit.
- **Windows:** geen wijzigingen in de werking.

## 0.13.0 – 2026-11-27
- **Linux: de Go-agent kan het werk van de bash-agent overnemen**, per server, als je dat in RzSense vraagt. Hij zet de timer van de bash-agent en zijn bewaker uit (niet weg), haalt zelf de testlijst op en stuurt het echte rapport. Pas als RzSense dat rapport aanneemt, geldt de overname; anders gaat alles terug zoals het was.
- **Terugval op de bash-agent, vanzelf:** na 5 mislukte rapporten achter elkaar, als RzSense het terugvraagt, of (door een los bewakertje, `rzsense-fallback.timer`) als er 10 minuten geen rapport is aangekomen, ook als het bestand van de Go-agent zelf stuk is. Na een terugval wacht de Go-agent een uur voor hij het opnieuw probeert.
- Nieuw commando `rzsense back`: met de hand terug naar de bash-agent. `rzsense status` zegt wie het werk doet en waarom er eventueel is teruggevallen.
- De bash-agent houdt zijn instellingen en sleutel. Een nieuwe sleutel of een nieuwe vingerafdruk van het certificaat van RzSense schrijft de Go-agent in de bestanden van de bash-agent, zodat teruggaan altijd werkt.
- Het installatiecommando zet nu ook het bewakertje neer. Voer het op een server waar de proef al loopt één keer opnieuw uit; zonder bewakertje neemt de Go-agent niet over.
- **Windows:** geen wijzigingen in de werking.

## 0.12.0 – 2026-11-27
- **Linux: de Go-agent werkt zichzelf bij.** Heeft RzSense een nieuwere versie, dan haalt de agent die op en vervangt hij zichzelf. Alleen als de handtekening van de ontwikkelaar klopt (de agent controleert dat zelf, zonder openssl), de SHA-256 overeenkomt, de versie echt nieuwer is en het nieuwe bestand op deze server kan draaien. Anders blijft de huidige versie staan en staat de reden in RzSense en in `rzsense status`.
- `REMOTE_UPDATE=0` in de instellingen van de agent zet dit uit, net als bij de bash-agent.
- De timer van de proef mag daarvoor in `/usr/local/bin` schrijven. Een proef die met een oudere versie is geïnstalleerd, heeft die ruimte nog niet: voer het installatiecommando één keer opnieuw uit.
- **Windows:** geen wijzigingen in de werking.

## 0.11.0 – 2026-11-27
- **Linux: details per dienst.** Voor elke dienst met een check: de status van systemd, het hoofdproces, herstarts, sinds wanneer hij draait, de poorten van zijn processen (getest op de server zelf), zelf opgegeven poorten en sockets, een test die bij de dienst past (webserver, database, Redis, SSH, mail, DNS, tijd) en de fouten in het log van het laatste kwartier.
- **Linux: extra tests** uit RzSense (een URL, met of zonder verwachte tekst, of een poort) en de **lijst met diensten** om uit te kiezen.
- Een eigen testscript (`SERVICE_TEST_DIR`) voert de Go-agent in de proef niet uit: dat doet de bash-agent al, en een tweede keer draaien kan iets op de server veranderen.
- **Windows:** geen wijzigingen in de werking.

## 0.10.0 – 2026-11-27
- **Linux: certificaten.** Per bron uit `CERTS` en uit de testlijst van de bash-agent: een PEM-bestand (ook met jokertekens) of `host:poort`. Dezelfde velden als de bash-agent: naam, uitgever (de organisatie, anders de naam), vervaldatum, of het vertrouwd wordt, en de hele keten met het certificaat dat het eerst verloopt. Zonder het programma `openssl`. De uitkomst wordt een uur bewaard (`CERT_INTERVAL`), net als bij de bash-agent.
- **Linux: processen.** Per proces-check het aantal, de PID's, CPU, geheugen en wanneer het oudste proces startte, en de lijst met wat er draait om uit te kiezen. Rechtstreeks uit `/proc`, met dezelfde regels als de bash-agent (geen kernelprocessen, niet de agent zelf; zoeken op naam, op het programma uit de opdrachtregel of op een patroon).
- **Linux:** de timer van de proef mag certificaten onder `/home` en `/root` lezen (alleen lezen), zoals de bash-agent.
- **Windows:** geen wijzigingen in de werking.

## 0.9.0 – 2026-11-27
- **Linux: luisterende poorten.** De Go-agent meldt de luisterende TCP-poorten met adres en proces, met dezelfde uitkomst als `ss -tlnp` in de bash-agent: `0.0.0.0`, `::` (alleen IPv6), `*` (IPv4 en IPv6 samen) en een adres aan een interface (`127.0.0.53%lo`). De gegevens komen rechtstreeks van de kernel; het programma `ss` is niet meer nodig.
- **Linux: diensten.** De status (`systemctl is-active`) van de diensten waar RzSense een check op heeft. De lijst komt uit de testlijst die de bash-agent al bewaart en uit `SERVICES`; de Go-agent leest die alleen.
- **Linux:** `rzsense status` meldt ook goed dat de proef loopt op een server zonder systemd (cronjob).
- **Windows:** geen wijzigingen in de werking.

## 0.8.0 – 2026-11-27
- **Linux: het commando `rzsense`** (proef naast de bash-agent). Dezelfde code als de Windows-agent bouwt nu ook voor Linux (amd64, arm64 en armv7): één bestand zonder afhankelijkheden, ondertekend met dezelfde sleutel.
  - `rzsense status`, `rzsense test` en `rzsense doctor` (loopt alles na en zegt in gewone taal wat er mis is en hoe je het oplost), `rzsense collect` en `rzsense shadow`.
  - **Proefronde (`shadow`):** meet hostnaam, opstarttijd, CPU, geheugen en schijven op dezelfde manier als de bash-agent, en stuurt dat versleuteld naar RzSense (1.339.0) om te vergelijken. De Go-agent leest de instellingen en de sleutel van de bash-agent alleen; hij verandert niets op de server en neemt niets over.
- **Windows:** geen wijzigingen in de werking.

## 0.7.0 – 2026-11-26
- **Wifi:** de agent meldt zijn eigen wifi-verbinding (wifi-netwerk, access point, band, kanaal, Wi-Fi-standaard, signaal en verbindingssnelheid, uit `netsh wlan show interfaces`, ook in een Nederlandse of Duitse Windows). RzSense 1.328.0 toont dat bij *Netwerk → Netwerksnelheid*.

## 0.6.0 – 2026-11-24
- **Reserve-RzSense**: RzSense geeft het adres door; zijn het gewone adres en het reserve-adres allebei niet bereikbaar, dan gaat het rapport (versleuteld met de eigen sleutel) daarheen. Een antwoord van de reserve verandert geen sleutel, adressen of opdrachten.
- **Overstappen naar een ander adres** als RzSense erom vraagt (Agent Manager → Adres van RzSense): eerst een ondertekende proef, alleen bij een kloppend antwoord overstappen; de uitkomst gaat mee met het volgende rapport.
- **Twee vingerafdrukken** per adres (`sha256//A;sha256//B`): het huidige en een klaargezet nieuw certificaat.
- Het rapport zegt per adres hoe het certificaat gecontroleerd wordt (`url_pin`, `url_fb_pin`).

## 0.5.0 – 2026-11-24
- **Aanmelden met een uitrolcode**: `rzsense-agent.exe install --url … --enroll CODE`. RzSense maakt de server dan zelf aan (naam = computernaam). Is de agent al gekoppeld, dan wordt de code niet gebruikt (alleen bijwerken).
- **MSI** (`rzsense-agent.msi`, gebouwd met WiX door GitHub Actions) met de eigenschappen `URL`, `ENROLL` en `PIN`, voor groepsbeleid of een beheerprogramma. De MSI stopt de service bij bijwerken en verwijdert hem bij verwijderen; de uitrolcode komt niet in de logboeken van Windows Installer.

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
