# Changelog Go-agent (Windows en Linux)

Wijzigingen aan de RzSense-agent in Go, per versie. Nieuwste bovenaan. Dezelfde code bouwt `rzsense-agent.exe` voor
Windows en, vanaf 0.8.0, het commando `rzsense` voor Linux (als proef naast de bash-agent).
De Go-agent heeft een eigen versienummer (`agent-win/VERSION`), los van RzSense en van de bash-agent voor Linux.
Bijwerken: `tools/bump-version.sh agentwin patch|minor|major` (zie [CLAUDE.md](../CLAUDE.md)).

## 0.26.0 – 2026-11-27
Uit de tweede proefronde op een echte server (Rocky/RHEL, één kern):
- **Linux: het proces bij een poort klopte niet bij een dienst die systemd via een socket start.** Bij cockpit op poort 9090 noemde de Go-agent `systemd`, de bash-agent `cockpit-tls`. `ss` noemt het proces met het hoogste pid als eerste; de Go-agent kiest nu hetzelfde.
- **Linux: de proef meet niet meer terwijl de bash-agent zijn ronde draait.** Op een server met één kern maten de twee agents elkaars werk mee: het CPU-gebruik leek 100%. De Go-agent wacht nu (hooguit 40 seconden) tot de bash-agent klaar is. Zo wordt ook de CPU-meting van de bash-agent zelf niet hoger door de proef.
- **Windows:** geen wijzigingen in de werking.

## 0.25.0 – 2026-11-27
- **Linux: wifi.** SSID, access point, band, kanaal, kanaalbreedte, signaal en verbindingssnelheid van de eigen wifi-kaart (`iw`), voor *Netwerk → Netwerksnelheid*.
- **Linux: certificaten zoeken.** Bestanden met een certificaat in `CERT_DIRS` en de mappen uit RzSense, om bij een SSL-check uit te kiezen; één keer per dag, of meteen bij *Opnieuw zoeken*. CA-lijsten, bundels, testmappen en sleutels worden overgeslagen.
- **Linux: 🧪 Test** uit de Agent Manager: de Go-agent test zichzelf (planning, instellingen, DNS, het adres van RzSense, de klok, vrije ruimte, fouten in zijn log). Alleen vaste controles die lezen.
- **Linux: meetpunt van buitenaf**: gewone TCP-verbindingen naar de openbare adressen en poorten die RzSense opgeeft, zonder gegevens te versturen; privé-adressen worden geweigerd.
- **Linux: diagnose voor AI-advies**: een vaste set commando's die alleen lezen. RzSense stuurt alleen de soort en het doel, nooit een commando; `COMMANDS=0` blokkeert het.
- **Windows:** geen wijzigingen in de werking.

## 0.24.0 – 2026-11-27
- **Linux: databasechecks** voor MariaDB/MySQL en PostgreSQL, lokaal of in een container: status, verbindingen, lopende query's, replicatie en grootte. Alleen lezen, nooit data, met letterlijk dezelfde query's als de bash-agent. Een wachtwoord komt alleen versleuteld van RzSense en gaat via de omgeving naar de client, nooit op de opdrachtregel.
- **Linux: WireGuard**: per peer de laatste handshake, het verkeer en het endpoint (alleen als root).
- **Linux: netwerkverkeer**: de bytes van de echte netwerkkaarten, voor *Netwerk → Verkeer*.
- In de proef vergelijkt RzSense de WireGuard-peers. Databasechecks voert de Go-agent pas uit als hij het werk doet.
- **Windows:** geen wijzigingen in de werking.

## 0.23.0 – 2026-11-27
- **Linux: Proxmox VE.** Guests (VM's en containers), opslag, ZFS-pools, de cluster, back-ups per guest, de back-uptaken en of de guest agent antwoordt. Guests en opslag elke 2 minuten, back-ups en taken elk kwartier. `PVE=0` zet het uit.
- **Linux: TrueNAS SCALE.** Meldingen, replicatie-, snapshot-, cloudsync- en rsync-taken, apps, schijftemperaturen en of er een update is. `TRUENAS=0` zet het uit. De agent leest TrueNAS alleen uit.
- Alleen lezen, alleen als root, en alleen op een server waar `pvesh` of `midclt` staat. Zelfde inhoud als de bash-agent.
- In de proef leest de Go-agent mee, zodat RzSense de guests, de opslag en de apps kan vergelijken.
- **Windows:** geen wijzigingen in de werking.

## 0.22.0 – 2026-11-27
- **Linux: de waakhond.** Lukt het rapport een tijd niet (standaard 5 minuten), dan stuurt de agent zelf een bericht via Pushover en/of ntfy, één keer, en een herstelbericht als RzSense terug is. Draait RzSense wel maar staan de checks stil, dan meldt hij dat ook. Bereikt hij ook de berichtendienst niet, dan stuurt hij niets en krijgt RzSense achteraf een notitie.
- De agent praat alleen met Pushover en ntfy als de waakhond voor deze server aan staat, met de gegevens die versleuteld van RzSense komen, en controleert het certificaat altijd. `WATCHDOG=0` zet het op de server uit; `PUSHOVER_API` werkt zoals bij de bash-agent.
- **Linux: wachten tijdens een update van RzSense.** Kondigt RzSense een update aan, dan telt een mislukt rapport niet mee voor de terugval op de bash-agent en geeft de waakhond geen alarm tot de afgesproken tijd voorbij is. De agent werkt zichzelf in die tijd ook niet bij.
- De stand van de waakhond en van de update-wachtstand staat in dezelfde bestanden als bij de bash-agent: bij overnemen en teruggaan komt er geen tweede alarm.
- **Windows:** geen wijzigingen in de werking.

## 0.21.0 – 2026-11-27
- **Linux: zelf herstellen.** Een dienst die *failed* of *inactive* is, herstart de agent; een container die gestopt is, start hij. Per check aan te zetten in RzSense, vóór de metingen van die ronde, zodat een gelukte herstart meteen als *draait* meetelt.
- **Eigen herstelcommando's** voert de agent alleen uit op verzoek van RzSense, in een versleuteld antwoord, en alleen een bestand uit `HEAL_DIR` op de server zelf dat uitvoerbaar is, van root en niet door anderen te wijzigen. RzSense stuurt alleen de naam.
- Dezelfde grenzen als de bash-agent: hooguit één poging per 10 minuten en 3 per dag per check; `HEAL=0` zet het op de server uit. De telling staat in dezelfde map als bij de bash-agent, zodat de grens ook bij overnemen en teruggaan geldt.
- Alleen als de Go-agent het werk doet; in de proef herstart hij niets.
- **Windows:** geen wijzigingen in de werking.

## 0.20.0 – 2026-11-27
- **Linux: containers (Podman en Docker).** Per container de status, health, herstarts, CPU, geheugen, netwerk, schijf, grootte en volumes; verder de pods (bij Docker: compose-projecten) en de opslag. Ook rootless containers van andere gebruikers, zoals de bash-agent (`PODMAN_USERS`). Alleen lezen.
- Antwoordt podman of docker niet binnen 8 seconden, of loopt één commando af, dan slaat de agent de containeronderdelen die ronde over; de rest van het rapport gaat door.
- **Linux: heartbeats.** De regels die `rzmon-hb` in de wachtrij zet, gaan mee met het rapport en verdwijnen pas als RzSense ze heeft. `rzmon-hb` zelf blijft werken zoals het was.
- In de proef leest de Go-agent de containers eens per 10 minuten mee, zodat RzSense ze kan vergelijken. Heartbeats raakt hij in de proef niet aan.
- **Windows:** geen wijzigingen in de werking.

## 0.19.0 – 2026-11-27
- **Linux: log-checks.** De nieuwe regels van een logbestand of de journal doorzoeken op een patroon, met het aantal treffers, de laatste regels en de IP-adressen die het vaakst voorkomen.
- **Linux: logs verzamelen.** De nieuwe regels van de gekozen bronnen (bestanden, journal, een systemd-dienst, een container) gaan in pakketjes versleuteld naar RzSense, met een wachtrij als RzSense even niet bereikbaar is.
- **Linux: logs vinden en een map doorbladeren**, om in RzSense een bron te kiezen. Alleen namen, groottes en tijden, nooit de inhoud.
- **Dezelfde regels voor welke bestanden gelezen mogen worden:** alleen binnen `LOG_DIRS` van de server, of in een map uit RzSense die binnen `LOG_DIRS_MAX` valt, en daar alleen bestanden die op een log lijken en geen geheim kunnen zijn. Nooit de hele schijf. Bestanden worden alleen gelezen.
- De Go-agent gebruikt dezelfde bladwijzers en dezelfde wachtrij als de bash-agent: bij het overnemen en teruggaan gaat er geen regel verloren en telt er geen dubbel.
- Alleen als de Go-agent het werk doet; in de proef leest hij geen logs.
- **Windows:** geen wijzigingen in de werking.

## 0.18.0 – 2026-11-27
- **Linux: commando-checks.** De Go-agent voert de commando's van commando-checks uit zoals de bash-agent: in een kale omgeving, met een time-out, standaard als `nobody`. Uitvoer en exitcode gaan naar RzSense.
- De noodrem op de server blijft: `COMMANDS=0` in `/etc/default/rzmon-agent` blokkeert alles, `COMMANDS=nobody` staat nooit root toe.
- De eerste meting wacht de ronde af; daarna meet de agent op de achtergrond en gaat het vorige resultaat mee, zodat een traag commando het rapport niet ophoudt.
- Alleen als de Go-agent het werk doet. In de proef voert hij geen commando's uit (anders zou elk commando twee keer lopen).
- **Windows:** geen wijzigingen in de werking.

## 0.17.0 – 2026-11-27
- **Linux: schijfgezondheid (SMART).** Per schijf het model, of de zelftest goed is, de temperatuur, slijtage en slechte sectoren (smartctl), elk half uur. Een schijf in slaapstand wordt niet wakker gemaakt.
- **Linux: UPS** via NUT (`upsc`): status, lading, resterende tijd, belasting en model.
- **Linux: pad-checks.** Elke hop met verlies en vertraging (mtr, anders traceroute), een ping naar de tussenliggende apparaten, de netwerkkaart met zijn snelheid, of de server virtueel draait en (met lldpd) op welke switch en poort hij zit.
- **Doorvoer (iperf3)**, alleen als de Go-agent het werk doet: meten naar een apparaat, of kort een ontvanger starten die na één meting stopt.
- In de proef meet de Go-agent SMART, UPS en het pad mee (zonder iperf3); RzSense legt ze naast de bash-agent.
- **Windows:** geen wijzigingen in de werking.

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
