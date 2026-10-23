# ✨ RzMon – overzicht van alle features

Dit is een overzicht van wat RzMon en de agent kunnen, in het kort. Hoe je alles instelt en gebruikt, staat in de [HANDLEIDING.md](HANDLEIDING.md) (de §-nummers hieronder verwijzen daarnaar). Wat er per versie veranderd is, lees je in de [CHANGELOG.md](CHANGELOG.md).

**Inhoud**
1. [Checks](#1-checks)
2. [Soorten servers](#2-soorten-servers)
3. [Dashboard](#3-dashboard)
4. [Meldingen](#4-meldingen)
5. [Storingen, onderhoud en afhankelijkheden](#5-storingen-onderhoud-en-afhankelijkheden)
6. [Home Assistant](#6-home-assistant)
7. [REST API](#7-rest-api)
8. [Beheer: installeren, bijwerken en back-up](#8-beheer-installeren-bijwerken-en-back-up)
9. [Toegang en beveiliging](#9-toegang-en-beveiliging)
10. [De agent (Linux)](#10-de-agent-linux)
11. [De agent op TrueNAS SCALE](#11-de-agent-op-truenas-scale)
12. [De Home Assistant-add-on](#12-de-home-assistant-add-on)

---

## 1. Checks

| Check | Wat het controleert | Werkt op |
|---|---|---|
| **Ping** | Reageert de server (3 pings); gemiddelde responstijd, pakketverlies | Alle servers |
| **TCP-poort** | Staat een dienst open (SSH, MariaDB, MQTT…), met verbindingstijd | Alle servers |
| **HTTP(S)** | Website of API bereikbaar, statuscode, responstijd; waarschuwt ook als het certificaat bijna verloopt | Alle servers |
| **SSL-certificaat** | Hoeveel dagen een certificaat nog geldig is: elke TLS-poort in je netwerk of op internet, of certificaatbestanden via de agent | Alle servers |
| **Diskruimte** | % gebruikt per mount, plus een **voorspelling** wanneer de disk vol is | Lokaal, agent |
| **CPU** | Gebruik over 2 seconden en load average | Lokaal, agent |
| **RAM** | Geheugengebruik (cache telt niet mee) en swap | Lokaal, agent |
| **Service** | Is een systemd-service actief? Via de agent ook de **diepere controle**: luistert hij op zijn poorten, antwoordt hij (HTTP, database, SSH, mail, DNS…), herstarts, fouten in het log, een extra URL- of poorttest of een eigen testscript (§8.7) | Lokaal, agent |
| **Logbestand** | Treffers van een zoekpatroon in een logbestand, het systemlog of kernelmeldingen. Met voorinstellingen voor mislukte logins (SSH, sudo), disk- en hardwarefouten, SMART, geheugen vol (OOM) en crashes (§8.8) | Agent |
| **Podman** | Overzicht: gestopte, ongezonde of gepauzeerde containers en pods | Agent met Podman |
| **Container** | Eén check per container: status (healthcheck, herstarts), plus naar keuze CPU, RAM en disk, elk met eigen drempels; ook rootless containers (§8.6) | Agent met Podman |
| **Home Assistant** | Laatste automatische back-up, integraties die niet geladen zijn, systeemstatus van de Supervisor | HA-add-on |
| **📡 SNMP** | RzMon leest het apparaat zelf uit (v2c of v3): uptime en herstart, CPU, geheugen, load, temperatuur, interfaces (up/down, verkeer, bezetting), opslag, UPS (status, lading, looptijd, belasting, accutemperatuur), Synology (systeem, volumes, schijven), printers (toner, inkt) en UniFi-clients. Met **📡 SNMP-checks** (het apparaat doorzoeken en aanvinken) en **eigen OID's** als getal, teller of tekst (§7.7) | Alle servers met SNMP aan |
| **🖥️ Commando** (met 21 voorinstellingen voor TrueNAS, Proxmox en Linux, en *＋ Aanbevolen checks*) | De agent voert een commando uit; RzMon vergelijkt de uitvoer met het verwachte antwoord (*gelijk aan*, *bevat*, *regex* of *exitcode 0*), met time-out. Bijvoorbeeld `zpool status -x` → `all pools are healthy` (§8.10) | Agent |

Elke check heeft eigen drempels voor warning en critical, een eigen interval en een minigrafiek van de laatste 24 uur.

**⇄ Omgekeerde status** (ping, poort, HTTP, service, container, Podman, commando, Home Assistant): kies zelf de status als de check slaagt (OK, warning of critical) en als hij mislukt. Voor iets dat juist níet mag draaien of bereikbaar mag zijn, zoals telnet of een oude server. Telt niet mee voor de bereikbaarheid van een server.

## 2. Soorten servers
- **Lokaal**: de RzMon-server zelf; alles wordt direct uitgelezen.
- **Remote met agent**: een Linux-server, TrueNAS SCALE of Home Assistant die zelf gegevens doorstuurt. Stopt de agent, dan worden de checks na 5 minuten (instelbaar) critical.
- **Remote (alleen netwerk)**: routers, NAS, switches, printers en websites, met ping, TCP, HTTP(S) en certificaten.
- **Poortscan**: zoek open TCP-poorten (bekende poorten, 1–1024 of een eigen lijst) en maak er met één klik een check van. Bij agent-servers zie je ook welke poorten lokaal luisteren, met procesnaam.

## 3. Dashboard
- **Nederlands of Engels** 🌐: schuifje bovenaan *Instellingen* (EN links, NL rechts), per gebruiker onthouden. De hele interface is vertaald. Meldingen (Pushover, e-mail, Home Assistant, MQTT), checkberichten en de REST API volgen een eigen instelling: *Taal van meldingen en checkberichten*.
- **Gezondheidsbalk** bovenaan:
  - de algehele status: 🟢 *Alles in orde*, 🟠 *Aandacht nodig* of 🔴 *Storing*;
  - een **score van 0–100%**;
  - **klikbare tellers** (OK, warning, critical, onbekend, onderhoud) om te filteren;
  - een inforegel met servers bereikbaar, checks OK, het **langst openstaande probleem**, de beschikbaarheid over 24 uur, flappende checks en 🌙 *stil tot …*.
- **Twee lay-outs**, per apparaat te kiezen, voor het dashboard, de meldingen en de instellingen:
  - **Blox**: tegels;
  - **L1nes**: rijen, in **Small** of **Large**.
- **Per server:** de algehele status, de checks met problemen en mini-meters voor CPU, RAM en disk. Uitklappen toont alle checks in **groepen** (📊 Systeem met CPU, RAM en disks, 🌐 Bereikbaarheid, ⚙️ Services, 📦 Containers, 🖥️ Commando's, 📜 Logbestanden, 🔒 Certificaten, 🏠 Home Assistant), elke groep in en uit te klappen. Problemen staan bovenaan in de groep **⚠️ Problemen**, per status (critical, warning, onbekend, bevestigd); een nieuw probleem klapt die groep vanzelf open.
- **Popup per check** (klik op een check): status en bericht, instellingen, beschikbaarheid en de **laatste 5 statuswijzigingen** met het bericht dat ze veroorzaakte en of en via welke kanalen er gemeld is (of waarom niet).
- **Grafieken** van 6 uur tot 30 dagen, met minimum, gemiddelde en maximum, en bij disks de trend.
- **Beschikbaarheid** per server over 24 uur, 7 dagen en 30 dagen.
- **Zoeken** en het filter *Alleen problemen*.
- **Overig:** licht en donker thema, werkt op je telefoon, ververst elke 20 seconden, en **↻ Alles nu checken**.
- **Meldingenoverzicht per dag:** elke verstuurde melding met de kanalen waarlangs hij ging, gegroepeerd per dag met tellers. Vandaag staat open. De bewaartermijn stel je zelf in. **Sorteren** op tijd, status, server, check, bericht of kanalen (klik op de kolomkop; in Blox een keuzelijst), en klik op een melding voor de popup van de check.

## 4. Meldingen
- **Kanalen:**
  - **Pushover**, met prioriteit en geluid per status, inclusief *Emergency*;
  - **e-mail**: altijd, of als reserve wanneer Pushover faalt;
  - een **Home Assistant-webhook**.
- **Per status** (warning, critical, onbekend, hersteld) stel je in:
  - **wanneer**: nooit, direct of pas na *X* minuten onafgebroken;
  - **herhalen**: elke *Y* minuten;
  - **welke kanalen**.

  Per check kun je dit **overschrijven**.
- **Bundelen per server:** meerdere meldingen binnen 120 seconden (instelbaar) worden één bericht.
- **Melden na N mislukte checks:** een melding pas als een check zoveel keer achter elkaar dezelfde status gaf (per status, onbekend telt mee). Algemeen in te stellen en per check anders; op het dashboard staat **⏳ 2/3** zolang hij nog niet gemeld is.
- **Anti-flapping:** een check die steeds van status wisselt, geeft één melding *"staat te flappen"*. Daarna komt er per status hooguit één melding, tot hij stabiel is. Ook hier geldt *melden na N mislukte checks*: zolang dat aantal niet gehaald wordt, flapt hij stil. De getallen stel je zelf in, algemeen en per check.
- **ℹ️ Info-meldingen** (standaard uit): gebeurtenissen die geen storing zijn: een server is herstart (nieuwe check **🔄 Herstart**, HA OS herstart, SNMP-uptime), een SSL-certificaat is vernieuwd, een agent of RzMon is bijgewerkt. Algemeen en per check in te stellen, stil via Pushover, niet tijdens stille uren of onderhoud; op de pagina Meldingen (kanaal *📋 Meldingenpagina*, standaard aan), met een filter *Alles · Zonder info · ℹ️ Info*.
- **Info-checks:** met de schakelaar *ℹ️ Alleen info-meldingen* geeft een check alleen info-meldingen bij een statuswissel en telt hij nooit als storing (blauwe stip, server blijft groen).
- **🌙 Stille uren:**
  - vensters (van–tot, per dag van de week, ook over middernacht) waarin geen meldingen uitgaan;
  - algemeen, per check eigen vensters, of *nooit stil*;
  - na afloop **één bericht** met alles wat dan nog open is.
- **Herstelmelding** zodra het weer goed gaat. Een **nieuwe melding bij escalatie** (warning → critical).
- **Automatisch onderhoud** na herhaalde meldingen voor dezelfde storing, tot hij is opgelost.
- **Testknoppen** per kanaal.

## 5. Storingen, onderhoud en afhankelijkheden
- **✔ Bevestigen:**
  - per check of per server, met een opmerking;
  - ook via de link in de melding, of in de Pushover-app (*Emergency*);
  - daarna komen er geen meldingen meer voor die storing;
  - na herstel vervalt de bevestiging vanzelf.
- **🔧 Onderhoud:**
  - voor alles, per server of per check;
  - met een duur of een eindtijd, en een reden;
  - checks blijven draaien; wat na afloop nog mis is, wordt alsnog gemeld.
- **🔗 Afhankelijkheden:** valt een router, switch, VPN of hypervisor uit, dan krijg je één melding in plaats van een melding per server erachter.

## 6. Home Assistant
- **MQTT discovery:** al je servers en checks verschijnen in Home Assistant als apparaten en sensoren, met de status en de meetwaarden. Er is ook een sensor **Algehele status** en een sensor **Gezondheid** (%). Stopt RzMon, dan worden de sensoren *niet beschikbaar*.
- **Webhook:** elke melding gaat als JSON naar een HA-automatisering, met een bevestig-link.
- **REST-sensoren** via de API (§11).
- **Add-on** voor Home Assistant OS, zie hoofdstuk 12.

## 7. REST API
- JSON, met API-sleutels die je in de webinterface beheert.
- **Rechten per sleutel:** 📖 status lezen, 🛡️ IDS lezen, 🐞 kwetsbaarheden lezen, ⛔ IDS blokkeren (met bevestiging) en ✔ checks bedienen; daarnaast optioneel **alleen vanaf** bepaalde IP's of netwerken, en een **geldigheid** van 6, 12 of 24 maanden (verlengen met ✎).
- **IDS:** incidenten, blokkades en logins uitlezen, en IP's blokkeren of deblokkeren, bijvoorbeeld vanuit fail2ban. Je LAN, RzMon zelf en *Nooit blokkeren* weigert de API altijd; in de leerstand wordt een blokkade alleen vastgelegd.
- **Checks bedienen:** bevestigen, onderhoud (check of server) en nu checken.
- Alles wat een sleutel verandert komt in het logboek.
- **Endpoints:**
  - `/status`, met de algehele gezondheid (`health`);
  - `/hosts`, met de beschikbaarheid;
  - `/checks`;
  - `/checks/{id}/history`;
  - `/alerts`.

## 8. Beheer: installeren, bijwerken en back-up
- **Installatie in één commando** op Rocky Linux / RHEL / AlmaLinux 10 (`install-rocky.sh`), inclusief SELinux, firewalld, Apache, PHP-FPM, MariaDB en cron. Debian/Ubuntu kan met de hand of via de setup-pagina.
- **Setup-pagina** (alleen beheerders, via ⚙️ Setup op *Instellingen*): een systeemcheck, de database instellen, tabellen installeren, de eerste beheerder aanmaken, en een PHP-instellingen-editor.
- **⬆ RzMon bijwerken vanuit de webinterface:**
  - een dagelijkse controle op GitHub, en **wat er nieuw is**;
  - de knop **Nu bijwerken**, met voortgang en eventueel eerst een back-up;
  - of **automatisch** op een tijdstip naar keuze.
- **Database** wordt vanzelf bijgewerkt bij een nieuwe versie.
- **💾 Back-up en herstel:**
  - automatisch elke dag, week of maand, of met de hand;
  - een zip met de database en `config.php`, versleuteld met AES-256;
  - downloaden, uploaden en terugzetten in de webinterface, met een veiligheidsback-up vooraf en automatisch terugdraaien als het misgaat;
  - ook via de terminal (`install.php --backup` / `--restore`).
- **Databasewachtwoord vernieuwen** met één knop, via de hulpdienst.
- **Agents bijwerken** met één knop (per server of allemaal), of automatisch.

## 9. Toegang en beveiliging
- **Gebruikers** met rollen (*beheerder*, *gebruiker*, *alleen lezen*) en **tweestapsverificatie** (TOTP).
- **Wachtwoord vergeten:**
  - een herstellink per e-mail, 30 minuten geldig en één keer te gebruiken;
  - bij tweestapsverificatie ook de code nodig;
  - met een logboek.
- **Lokaal netwerk:** optioneel inloggen zonder wachtwoord op je LAN (*alleen lezen* of beheerder); van buitenaf blijft de login nodig.
- **Reverse proxy** (Nginx Proxy Manager, Traefik, Cloudflare Tunnel): het echte IP wordt alleen vertrouwd van proxy's die je zelf opgeeft.
- **Bescherming:** wachtwoorden met bcrypt, afremmen van inlogpogingen, CSRF-bescherming, veilige cookies, een eigen token per agent, en API-sleutels alleen als hash.
- **Gevoelige acties** (back-up downloaden of terugzetten, databasewachtwoord, commando's toestaan) vragen om je wachtwoord.
- **Logboek** van gevoelige wijzigingen.
- **🛡️ IDS (intrusion detection)**, pagina *IDS*:
  - standaard **uit**; met een schakelaar **leren** (alleen melden) of **blokkeren** (met je wachtwoord);
  - een **basismeting** per server (standaard 24 uur), daarna alleen afwijkingen; *Dit is normaal* en *basismeting opnieuw*;
  - meldt gewijzigde gevoelige bestanden, nieuwe gebruikers (en een tweede root), poorten, SUID-programma's en kernelmodules, verdachte processen, reverse shells, brute force, inlog na brute force, root-login en logins vanaf nieuwe IP's;
  - **critical** ook naar Pushover (ook tijdens stille uren), **warning** alleen op de pagina IDS;
  - **blokkeren** op de aangevallen server: automatisch bij brute force (24 uur; bij herhaling binnen 30 dagen 7 dagen; permanent met de hand) of met één klik, en **deblokkeren**; je LAN, RzMon en een eigen lijst nooit automatisch;
  - **📁 bestanden bewaken** (FIM): eigen paden (algemeen en per server, met jokertekens zoals `/var/www/**/*.php`), met hash, grootte, rechten en eigenaar; nieuw, gewijzigd, verwijderd of SUID erbij; elke minuut snel, dagelijks volledig; veel wijzigingen tegelijk als één incident; *＋ Aanbevolen*, *goedkeuren* en *niet meer bewaken*;
  - TrueNAS SCALE alleen detecteren;
  - drie tabbladen: **Incidenten**, **🔑 Toegang** (logins, sudo en su per dag, met filters, sorteren, 🙈 negeren en ⛔ blokkeren; los aan/uit te zetten) en **Blokkades**, plus een knop **⛔ IP blokkeren** voor een IP-adres naar keuze.
- **🐞 Kwetsbaarheden**, pagina *Kwetsbaarheden* (standaard **uit**):
  - de agents (1.47.0+) sturen hun pakketlijst mee (dpkg of rpm), RzMon leest die van zichzelf; RzMon zoekt de CVE's op bij **OSV.dev** met de adviezen van de distributie (geen vals alarm door backports; alleen pakketnaam, versie en distributie gaan naar OSV);
  - per server: pakket, geïnstalleerde versie, **opgelost in**, CVE's per ernst, **🌐 bereikbaar** (luistert op het netwerk), **⚠️ actief misbruikt** (CISA KEV), **↻ herstart nodig**; tellers, zoeken en filters, en de hele softwarelijst;
  - klik op een CVE: omschrijving, CVSS met uitleg, EPSS, adviezen en links, en per server het commando om bij te werken;
  - **bijwerken via de agent** per pakket of *alle beveiligingsupdates* (beheerders, met wachtwoord, alleen als commando's als root aan staan; anders het commando om zelf uit te voeren);
  - **risico accepteren** met een reden; ℹ️ info bij een nieuwe critical of actief misbruikte CVE; REST API (`/vulnerabilities`) en MQTT-sensoren;
  - ondersteund: Debian, Ubuntu, Rocky Linux, AlmaLinux (en RHEL/CentOS), Alpine.
- **Hulpdienst als root** voert alleen vaste taken uit. Hij weigert als de RzMon-map niet veilig is (ontgrendeld, of bestanden die niet van root zijn).

## 10. De agent (Linux)
Een bash-script (`rzmon-agent.sh`) dat elke minuut een rapport naar RzMon stuurt. Het werkt op Rocky/RHEL/AlmaLinux/Fedora, Debian/Ubuntu, Raspberry Pi OS en Proxmox VE.

**Installeren en beheren**
- **Installatie met één commando** uit de *Agent Manager*:
  - ontbrekende hulpprogramma's installeren;
  - een systemd-timer met afscherming (of cron);
  - een testrapport sturen.
- **`rzmon-agent-setup`:**
  - `--test`: een verbindingstest die stap voor stap zegt wat er mis is;
  - `--update`;
  - `--uninstall`;
  - `--certs`.
- **Bijwerken vanuit RzMon** (knop of automatisch): de agent haalt de nieuwe versie bij je eigen RzMon. Hij controleert het SHA-256-controlegetal, of het script geldig is en of het de juiste versie is, en meldt de uitkomst terug. `REMOTE_UPDATE=0` zet dit uit.
- **Instellingen** in `/etc/default/rzmon-agent`, alleen leesbaar voor root. Eigen regels blijven staan bij opnieuw installeren.
- **⏱ Tik vanuit RzMon:** hoe vaak de agent rapporteert (30 s, 60 s, 2 min, 5 min), standaard in de instellingen en per server in de Agent Manager. Zware onderdelen (diepere controle, extra tests, logbestanden, commando's) volgen het interval van hun check. RzMon beoordeelt agent-checks meteen als het rapport binnenkomt.
- **⚡ Realtime-watcher** (per server aan/uit, standaard uit): de dienst `rzmon-watch` volgt het systemlog (logins, sudo, su, services die stoppen of crashen) en met inotify de 🔴-paden van *Bestanden bewaken*, en start bij een gebeurtenis meteen een rapport. Binnen enkele seconden in RzMon. `REALTIME=0` zet het op een server uit; niet op TrueNAS of via cron. In de Home Assistant-add-on: elke 5 s kijken of add-ons, Core of de boot-tijd veranderen.

**Wat de agent meldt**
- **Systeem:** CPU (gebruik, load, cores), geheugen en swap, disks per mount, luisterende poorten met procesnaam.
- **Services:**
  - de status van de services die je in RzMon kiest; de agent stuurt een lijst van alle services op de server mee voor de **servicekiezer**;
  - de **diepere controle**: poorten, een protocoltest (HTTP, database, SSH, mail, DNS…), herstarts en logfouten, plus een extra URL- of poorttest;
  - een eigen testscript in `/etc/rzmon-agent/tests/`.
- **Certificaten:** certificaatbestanden (ook met wildcards, zoals Let's Encrypt) en `host:poort`, lokaal of op internet. Eén keer per uur uitgelezen.
- **Logbestanden:**
  - alleen de nieuwe regels sinds de vorige keer, met de laatste 5 treffers en de meest voorkomende IP-adressen;
  - het systemlog (journal), per service, en kernelmeldingen;
  - bestanden alleen binnen `LOG_DIRS` (standaard `/var/log`).
- **Podman:**
  - containers van root en van gewone gebruikers (rootless): status, healthcheck, herstarts, CPU, RAM, netwerk en disk (schrijflaag + volumes);
  - pods en opslag.
- **🖥️ Commando-checks:**
  - **standaard uit**; aanzetten in RzMon (algemeen of per server) als `nobody` of als root, met je wachtwoord en een logboek;
  - de agent voert ze uit in een kale omgeving, met time-out en hooguit 4 KB uitvoer;
  - op de server zelf perkt `COMMANDS=0` of `COMMANDS=nobody` het altijd in.
- **🔑 Logins en sudo:** SSH- en console-logins, `sudo` en `su` (wie, wat, als wie, waar vandaan), ook mislukte pogingen, opgeteld per IP per uur. Op de pagina *IDS → Toegang*, met filters per soort en server, sorteren, 🙈 negeren en ⛔ blokkeren; wachtwoorden in commando's gemaskeerd; gebruikers/IP's te negeren; `AUTH_EVENTS=0` zet het op een server uit.
- **🛡️ IDS** (standaard uit): controlegetallen van gevoelige bestanden (sudoers, authorized_keys, passwd, crontabs, sshd-config …), gebruikers, SUID-programma's, kernelmodules, gewijzigde pakketbestanden (`rpm -Va`/`debsums`), processen uit `/tmp` of zonder bestand, reverse shells en verbindingen naar malware-poorten. Bij *blokkeren* een eigen nftables-tabel `inet rzmon` met de geblokkeerde IP's; `IDS_BLOCK=0` zet blokkeren op een server uit. Bestanden bewaken: alleen wijzigingen gaan naar RzMon, de stand wordt pas bewaard als het rapport is aangekomen.
- **Tegen kapotte rapporten:** servicenamen worden gefilterd, en de uitvoer wordt veilig als JSON verstuurd (via een tijdelijk bestand).

## 11. De agent op TrueNAS SCALE
- **Eén commando** (tabblad *🗄️ TrueNAS SCALE* in de Agent Manager):
  - de agent en de instellingen komen op je **pool** (`/mnt/<pool>/rzmon-agent`), omdat `/usr` en `/etc` bij TrueNAS zelf horen;
  - een **cronjob van TrueNAS** wordt via de API aangemaakt (uitvoer verborgen); die blijft staan na een TrueNAS-update;
  - een bestaande handmatige installatie wordt overgenomen.
- **Bijwerken met de knop** in RzMon, net als op andere servers.
- **🛡️ IDS:** alleen detecteren, niet blokkeren.
- **Tip:** bewaak je pools met een commando-check `zpool status -x`.
- **TrueNAS CORE** (FreeBSD) voeg je toe als *Remote* met netwerkchecks.

## 12. De Home Assistant-add-on
- **Installeren** via de add-on winkel (repository `https://github.com/rembrand20/rzmon`).
- **Wat de add-on meldt:**
  - CPU, RAM en de data-partitie (via de Supervisor);
  - als services: Home Assistant Core, de Supervisor, elke add-on (`addon:<slug>`) en beschikbare updates;
  - de **laatste automatische back-up** (HA 2025.2+);
  - **integraties** die niet geladen zijn;
  - de **systeemstatus** van de Supervisor;
  - **onverwachte herstart** van HA OS (critical met melding), **levensduur van de SD-kaart/eMMC**, een **mislukte OS-update** (A/B-terugval) en **USB-apparaten** (Zigbee-/Z-Wave-stick weg = critical);
  - **containers**: Core, Supervisor, plugins en add-ons met CPU en RAM, gecrasht of herstart door de watchdog, in hetzelfde overzicht en met dezelfde checks als Podman;
  - **logbestanden** van HA OS, Core, de Supervisor en add-ons doorzoeken (bv. te lage voeding op een Raspberry Pi);
  - *＋ Aanbevolen checks* voor Home Assistant.
- **Alleen lezen**, met de Supervisor-rol `manager` (statistieken, logs, hardware). Commando-checks zijn in de add-on niet actief.
- **Bijwerken** via de add-on winkel. De add-on heeft hetzelfde versienummer als de agent (los van RzMon), dus er is alleen een update als de agent veranderd is.
