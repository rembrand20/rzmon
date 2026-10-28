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
| **SSL-certificaat** | Hoeveel dagen een certificaat nog geldig is: elke TLS-poort in je netwerk of op internet, of via de agent certificaatbestanden (die hij zelf zoekt, om uit te kiezen) en `localhost:poort`; bij een keten telt het certificaat dat het eerst verloopt | Alle servers |
| **Proces** | Draait een proces (op naam of patroon in de opdrachtregel, eventueel van één gebruiker), met aantal, CPU en RAM; een herstart geeft een ℹ️ info-melding. Kiezen uit wat er draait | Lokaal + agent |
| **Diskruimte** | % gebruikt per mount, plus een **voorspelling** wanneer de disk vol is | Lokaal, agent |
| **CPU** | Gebruik over 2 seconden en load average | Lokaal, agent |
| **RAM** | Geheugengebruik (cache telt niet mee) en swap | Lokaal, agent |
| **Service** | Is een systemd-service actief? Via de agent ook de **diepere controle**: luistert hij op zijn poorten, antwoordt hij (HTTP, database, SSH, mail, DNS…), herstarts, fouten in het log, een extra URL- of poorttest of een eigen testscript (§8.7) | Lokaal, agent |
| **Logbestand** | Treffers van een zoekpatroon in een logbestand, het systemlog of kernelmeldingen. Met voorinstellingen voor mislukte logins (SSH, sudo), disk- en hardwarefouten, SMART, geheugen vol (OOM) en crashes (§8.8) | Agent |
| **Podman** | Overzicht: gestopte, ongezonde of gepauzeerde containers en pods | Agent met Podman |
| **Container** | Eén check per container: status (healthcheck, herstarts), plus naar keuze CPU, RAM en disk, elk met eigen drempels; ook rootless containers (§8.6) | Agent met Podman |
| **Home Assistant** | Laatste automatische back-up, integraties die niet geladen zijn, systeemstatus van de Supervisor | HA-add-on |
| **📡 SNMP** | RzMon leest het apparaat zelf uit (v2c of v3): uptime en herstart, CPU, geheugen, load, temperatuur, interfaces (up/down, verkeer, bezetting), opslag, UPS (status, lading, looptijd, belasting, accutemperatuur), Synology (systeem, volumes, schijven), printers (toner, inkt) en UniFi access points (isolated, firmware met ℹ️-melding, clients per band en per SSID, kanaalbezetting, SSID uitgezonden, verkeer en kwaliteit per SSID). Met **📡 SNMP-checks** (het apparaat doorzoeken en aanvinken) en **eigen OID's** als getal, teller of tekst (§7.7) | Alle servers met SNMP aan |
| **🖥️ Commando** (met 21 voorinstellingen voor TrueNAS, Proxmox en Linux, en *＋ Aanbevolen checks*) | De agent voert een commando uit; RzMon vergelijkt de uitvoer met het verwachte antwoord (*gelijk aan*, *bevat*, *regex* of *exitcode 0*), met time-out. Bijvoorbeeld `zpool status -x` → `all pools are healthy` (§8.10) | Agent |
| **Heartbeat** (passieve check) | Een script, back-up of cronjob meldt zich zelf: met `rzmon-hb NAAM ok` (versleuteld via de agent) of een geheime URL (alleen LAN, per check ook van buiten). Schema *elke …*, *dagelijks om*, *wekelijks* of cron, met speling: te laat = WARNING, 2× de speling = CRITICAL. *fail* = CRITICAL, *start* meet de duur (te lang = *hangt het?*), een meegestuurde waarde met eigen drempels. Voorbeelden voor Proxmox, rsync, restic, borg, Synology, MariaDB, certbot en Home Assistant (§7.8) | Alle servers |

Elke check heeft eigen drempels voor warning en critical, een eigen interval en een minigrafiek van de laatste 24 uur.

**📋 Sjablonen** (1.94.0): sets checks die je zelf maakt (of maakt van een bestaande server) en aan servers koppelt, met `{adres}` en `{naam}` per server. Pas je het sjabloon aan, dan gaan alle gekoppelde servers mee, behalve checks die je op een server zelf hebt aangepast (terug te zetten). Startsjablonen: *Linux-server*, *Website*, *Netwerkapparaat*.

**💡 Nog niet bewaakt** (1.95.0): wat de agent ziet maar nog geen check heeft (disks, services, containers, certificaten, open poorten) staat bij de server als *💡 N nieuw gevonden*: met één klik toevoegen of negeren. **🧪 Nu proberen** in het checkformulier toont het resultaat vóór je opslaat.

**🧹 Opschoonadvies** (1.96.0): checks die al dagen onbekend zijn, ruismakers, flappers, al lang in warning, net onder de drempel of al lang uit, elk met een voorstel en een knop (melden na 3, uitzetten, verwijderen, niet meer tonen).

**☑️ Alle checks** (1.94.0): één lijst met zoeken en filters; vink checks aan en pas ze tegelijk aan (interval, drempels, melden, onderhoud, aan/uit), kopieer ze naar andere servers of verwijder ze.

**⇄ Omgekeerde status** (ping, poort, HTTP, service, container, Podman, commando, Home Assistant): kies zelf de status als de check slaagt (OK, warning of critical) en als hij mislukt. Voor iets dat juist níet mag draaien of bereikbaar mag zijn, zoals telnet of een oude server. Telt niet mee voor de bereikbaarheid van een server.

## 2. Soorten servers
- **Lokaal**: de RzMon-server zelf; alles wordt direct uitgelezen.
- **Remote met agent**: een Linux-server, TrueNAS SCALE of Home Assistant die zelf gegevens doorstuurt. Stopt de agent, dan worden de checks na 5 minuten (instelbaar) critical.
- **Remote (alleen netwerk)**: routers, NAS, switches, printers en websites, met ping, TCP, HTTP(S) en certificaten.
- **Poortscan**: zoek open TCP-poorten (bekende poorten, 1–1024 of een eigen lijst) en maak er met één klik een check van. Bij agent-servers zie je ook welke poorten lokaal luisteren, met procesnaam.

## 3. Dashboard
- **Nederlands of Engels** 🌐: in het accountmenu rechtsboven (NL of EN), per gebruiker onthouden. De hele interface is vertaald. Meldingen (Pushover, e-mail, Home Assistant, MQTT), checkberichten en de REST API volgen een eigen instelling: *Taal van meldingen en checkberichten*.
- **⚲ Filters op elke pagina** (Dashboard, Meldingen, IDS, Toegang, Blokkades, Kwetsbaarheden, Datalekken): per onderdeel meerdere waarden met aantallen, een periode, actieve filters als chips, per gebruiker bewaard, **opgeslagen filters met een naam**, en de filters in het adres om te delen.
- **Bij openen alles dicht** (of *slim openen*, per gebruiker): wat je openklapt blijft open tot je herlaadt; zoeken of filteren klapt de treffers open; *Alles openen / sluiten* op elke pagina. Op *Kwetsbaarheden* en *Datalekken* (1.93.0) onthoudt RzMon per account welke blokken je open had; de rest is dicht.
- **Vandaag altijd open** op Meldingen, IDS-incidenten en Logins (gaat weer open bij iets nieuws), met een lege balk *Vandaag · geen meldingen 👌* als het rustig is.
- **Tellers onder de header** (OK, warning, critical, onbekend, onderhoud), klikbaar als filter, blijven staan bij scrollen. Ook de pagina's **Meldingen**, **IDS** en **Kwetsbaarheden** hebben die opbouw: tellers, kerncijfers, werkbalk met chips, compacte rijen, en herhalingen of gelijke incidenten gebundeld (*5×*); *niet verzonden* als rood icoon met de reden.
- **Kerncijfers als tegels**: de algehele status (🟢 *Alles in orde*, 🟠 *Aandacht nodig*, 🔴 *Storing*) met een **score van 0–100%** als ring, servers bereikbaar, checks OK, open problemen met het **langst openstaande**, en de beschikbaarheid over 24 uur met een lijntje; 🌙 *stil tot …*, flappende checks en onderhoud.
- **⚠️ Nu aandacht nodig**: alle open problemen in één lijst, critical eerst, met ✔ bevestigen en 🔧 onderhoud per regel.
- **Werkbalk** met zoeken, filterchips (problemen, onderhoud, en per **soort apparaat**: servers, netwerk, opslag, stroom, smart home), sorteren en een menu ⋯; servers met problemen als kaart met **mini-grafiekjes van CPU, RAM en disk over 24 uur**, servers waar alles OK is compact eronder.
- **Twee lay-outs**, per apparaat te kiezen (*Mijn account → Weergave*), voor het dashboard en de meldingen:
  - **Blox**: tegels, in **Small** (compact, meer servers in beeld) of **Large**;
  - **L1nes**: rijen, in **Small** of **Large**.
- **Tabbladen in één balk** (Dashboard, Meldingen, IDS, Kwetsbaarheden) met eigen lijn-iconen en een vlak dat naar het actieve tabblad schuift; de bovenbalk blijft staan als je scrolt. Op de telefoon een **tabbalk onderaan het scherm**, zoals in een app.
- **Tellers op de tabbladen** Dashboard (nieuwe problemen, in de kleur van het ergste) en Meldingen (nieuwe meldingen), sinds je laatste bezoek; per gebruiker onthouden en ook in de titel van het browsertabblad.
- **Per server:** de algehele status, de checks met problemen en mini-meters voor CPU, RAM en disk. Uitklappen toont alle checks in **groepen** (📊 Systeem met CPU, RAM en disks, 🌐 Bereikbaarheid, ⚙️ Services, 📦 Containers, 🖥️ Commando's, 📜 Logbestanden, 🔒 Certificaten, 🏠 Home Assistant), elke groep in en uit te klappen. Problemen staan bovenaan in de groep **⚠️ Problemen**, per status (critical, warning, onbekend, bevestigd); een nieuw probleem klapt die groep vanzelf open.
- **Popup per check** (klik op een check): status en bericht, instellingen, beschikbaarheid en de **laatste 5 statuswijzigingen** met het bericht dat ze veroorzaakte en of en via welke kanalen er gemeld is (of waarom niet).
- **Grafieken** van 6 uur tot 30 dagen, met minimum, gemiddelde en maximum, en bij disks de trend.
- **Beschikbaarheid** per server over 24 uur, 7 dagen en 30 dagen.
- **Zoeken** en filterchips (*Problemen*, *Onderhoud*, per soort apparaat).
- **🖥️ Servers en 🌐 netwerk apart** (soort apparaat): vanzelf herkend via de agent en SNMP (access point, switch, router, firewall, NAS, UPS, printer, camera) of zelf gekozen; **📷 camera's** ook aan de naam, met aanbevolen checks (ping, RTSP, webpagina); bij *Alle* een blok per groep, met één groep gekozen volgen ook de tellers en tegels.
- **Overig:** licht en donker thema, werkt op je telefoon, ververst elke 20 seconden, en **↻ Alles nu checken**.
- **Meldingenoverzicht per dag:** elke verstuurde melding met de kanalen waarlangs hij ging, gegroepeerd per dag met tellers. Vandaag staat open. De bewaartermijn stel je zelf in. **Sorteren** op tijd, status, server, check, bericht of kanalen (klik op de kolomkop; in Blox een keuzelijst), en klik op een melding voor de popup van de check.
- **📊 Analyse** (vanaf 1.106.0, alleen voor beheerders en gebruikers): een eigen tabblad over een periode die je kiest (24 uur, 7, 30, 90 of 365 dagen, of eigen datums), steeds vergeleken met de periode ervoor. Kerncijfers (beschikbaarheid, storingen, hersteltijd, meldingen, IDS, score), **🎯 Wat nu aanpakken** (disks die vollopen, guests zonder back-up, flappende checks, riskante poorten, kwetsbaarheden met een oplossing …), betrouwbaarheid (per server, storingen per weekdag en uur, lastigste checks, responstijden), capaciteit, beveiliging, netwerk (ook UniFi en AdGuard), back-ups en meldingen/ruis. Dagtotalen blijven een jaar bewaard. Een **maandrapport per e-mail** zet je zelf aan of uit.
- **⚡ Live bijwerken** (vanaf 1.107.0): wijzigingen binnen een paar seconden op je scherm; vanaf 1.109.0 **echt live**: alleen wat veranderde wordt bijgewerkt (opengeklapte onderdelen en vensters blijven open), waarden lichten op en tellen mee, een meldinkje bij een nieuw probleem, en het venster van een check werkt live bij. **⌨️ Zoeken en doen (Ctrl+K)**: servers, checks, IP's, tabbladen en instellingen, en met een werkwoord (*onderhoud*, *nu*, *bevestig*, *grafiek*, *blokkeer*) meteen de actie.
- **🟢 Statuspagina** (vanaf 1.107.0): voor huisgenoten of klanten via een geheime link, met de onderdelen die je kiest en 90 dagen per dag.

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
- **ℹ️ Info-meldingen** (standaard uit): gebeurtenissen die geen storing zijn: een server is herstart (check **Uptime**, HA OS herstart, SNMP-uptime), een SSL-certificaat is vernieuwd, een agent of RzMon is bijgewerkt. Algemeen en per check in te stellen, stil via Pushover, niet tijdens stille uren of onderhoud; op de pagina Meldingen (kanaal *📋 Meldingenpagina*, standaard aan), met een filter *Alles · Zonder info · ℹ️ Info*.
- **Info-checks:** met de schakelaar *ℹ️ Alleen info-meldingen* geeft een check alleen info-meldingen bij een statuswissel en telt hij nooit als storing (blauwe stip, server blijft groen).
- **🌙 Stille uren:**
  - vensters (van–tot, per dag van de week, ook over middernacht) waarin geen meldingen uitgaan;
  - algemeen, per check eigen vensters, of *nooit stil*;
  - na afloop **één bericht** met alles wat dan nog open is.
- **Herstelmelding** zodra het weer goed gaat. Een **nieuwe melding bij escalatie** (warning → critical).
- **Automatisch onderhoud** na herhaalde meldingen voor dezelfde storing, tot hij is opgelost.
- **Testknoppen** per kanaal.
- **🧠 Slimme drempels** (vanaf 1.107.0): RzMon leert per check wat normaal is per uur van de week en zet daar de drempels op (handmatig, slim, of slim met vangnet); elke nacht bijgesteld (hooguit 10%), in het wijzigingslogboek terug te draaien, met een ℹ️ bij een sluipende stijging.
- **🔗 Bundelen over servers heen** (vanaf 1.107.0): meerdere servers tegelijk mis = één bericht met de waarschijnlijke oorzaak.
- **🩹 Zelf herstellen** (vanaf 1.107.0, agent 1.62.0): een service of container herstarten, of een eigen herstelcommando van de server; melden pas als het niet helpt.
- **🐕 Waakhond** (vanaf 1.107.0, agent 1.62.0): een agent meldt via Pushover als RzMon onbereikbaar is of de checks stilstaan.
- **⏫ Escaleren** (vanaf 1.108.0, standaard uit): een storing die niemand bevestigt, na een instelbare tijd nog een keer melden, een stap hoger (warning → critical, critical → nood); niet tijdens de stille uren.
- **💡 Slimme voorstellen** (vanaf 1.108.0): elke nacht voorstellen voor *melden na N*, vaste onderhoudsvensters en afhankelijkheden, uit de geschiedenis; jij klikt op Toepassen of Negeren.
- **⏱️ Slim interval** (vanaf 1.108.0, standaard uit): bij een probleem elke 20 seconden checken, als alles een uur goed is trager.
- **📈 Afwijkingen zonder drempels, 💾 disk groeit hard, 🕵️ oorzaak erbij, 📥 heartbeat-ritme** (vanaf 1.108.0): een ℹ️ bij een ongewone waarde of een snel vollopende disk, in de melding wat er vlak ervoor gebeurde, en een warning als een heartbeat later is dan normaal of een vreemde waarde meestuurt.

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
- **🗓️ Vaste onderhoudsvensters** (vanaf 1.108.0): elke dag of op een weekdag een vaste tijd zonder meldingen, voor een server of één check.

## 6. Home Assistant
- **MQTT discovery:** al je servers en checks verschijnen in Home Assistant als apparaten en sensoren, met de status en de meetwaarden. Er is ook een sensor **Algehele status** en een sensor **Gezondheid** (%). Stopt RzMon, dan worden de sensoren *niet beschikbaar*.
- **Webhook:** elke melding gaat als JSON naar een HA-automatisering, met een bevestig-link.
- **REST-sensoren** via de API (§11).
- **Add-on** voor Home Assistant OS, zie hoofdstuk 12.

## 7. REST API
- JSON, met API-sleutels die je in de webinterface beheert.
- **Rechten per sleutel:** 📖 status lezen, 🛡️ IDS lezen, 🐞 kwetsbaarheden lezen, ⛔ IDS blokkeren (met bevestiging) en ✔ checks bedienen; daarnaast optioneel **alleen vanaf** bepaalde IP's of netwerken, en een **geldigheid** van 6, 12 of 24 maanden (verlengen met ✎).
- **IDS:** incidenten, blokkades en logins uitlezen, en IP's blokkeren of deblokkeren, bijvoorbeeld vanuit fail2ban. Je LAN, RzMon zelf en de whitelist weigert de API altijd; in de leerstand wordt een blokkade alleen vastgelegd.
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
- **Instellingen** (alleen beheerders): een zijbalk met groepen, één onderdeel tegelijk, alles **meteen opgeslagen** (✔ naast het veld). Begint bij **✅ Hoe staat het ervoor?**: een score en een lijst aandachtspunten (meldkanaal, back-up, 2FA van beheerders, inlogbeveiliging, RzMon-versie, verouderde en onversleutelde agents, HTTPS, kwetsbaarheden, IDS); klik erop en je staat bij de instelling, met een oranje of rood bolletje in de zijbalk. **🔍 Zoeken** door alle instellingen (Enter springt naar het veld en laat het oplichten), lange uitleg ingekort met *ⓘ meer uitleg* of een ⓘ met tooltip, en zelden gebruikte opties onder **▸ Geavanceerd**.
- **Setup in de beheerportal** (1.92.0, alleen beheerders, *Instellingen → Systeem*): **Systeemcheck** (met de cron-regel), **Database en opslag** (verbinding, tabellen, wachtwoord, bewaartermijnen) en **Server en PHP** (tijdzone, basis-URL, ping, PHP-waarden). De losse setup-pagina is er alleen nog voor de installatie (database en eerste beheerder).
- **⬆ RzMon bijwerken vanuit de webinterface:**
  - een dagelijkse controle op GitHub, en **wat er nieuw is**;
  - de knop **Nu bijwerken** (of het label ⬆ bovenin): een venster met de vraag, eventueel eerst een back-up, en daarna een **voortgangsbalk** met waar de installatie mee bezig is;
  - of **automatisch** op een tijdstip naar keuze.
- **Database** wordt vanzelf bijgewerkt bij een nieuwe versie.
- **📜 Wijzigingslogboek** (1.97.0): wie veranderde welke instelling, server, check of welk sjabloon, met oud en nieuw. *↩ Terugzetten* per wijziging, ook voor een verwijderde check of server. Bewaartermijn instelbaar (30 dagen tot 3 jaar); geheimen nooit leesbaar.
- **📦 Exporteren en importeren** (1.97.0): servers, checks, sjablonen en eventueel instellingen als JSON, zonder geheimen. Importeren voegt alleen toe, met eerst een voorbeeld.
- **🧪 Meldketen testen** (1.97.0): één testmelding door alle kanalen, met per kanaal ✓ of ✕.
- **Gezondheid van RzMon** (1.96.0, *Systeemcheck*): de grootte en groei van de database (met wanneer de disk vol is), de duur van een cron-ronde, de traagste checks en PHP-fouten van de laatste 24 uur.
- **🔐 Verbindingscontrole per agent** (1.100.0): eigen sleutel, versleuteld, https, certificaat gecontroleerd, IP-slot, versie, sleutel vernieuwd, geen oud token, instellingen op de server opgeruimd, agent-script ongewijzigd (SHA-256), reservekopie. Te zien in de Agent Manager, de agenttabel en *Hoe staat het ervoor?*. ⚠️/✅-melding als een agent onveilig wordt. **🧹 Opruimen** per agent of allemaal: tokens en de oude algemene sleutel wissen, en de agents schoon opnieuw installeren met een reservekopie. *Alleen agents met een eigen sleutel* (koppelen met een koppelcode blijft kunnen). Op de server: `rzmon-agent-setup --status`, met verbinding, TLS, versleuteling, contacten, fouten en wat er verstuurd wordt.
- **📡 Contact met de agent** (1.99.0): per agent het laatste en het volgende contact, live aftellend, in de agenttabel, de Agent Manager en op de serverkaart; 🟠 te laat, 🔴 offline, ⚡ realtime; het ritme dat de agent werkelijk aanhoudt (⚠️ als hij minder vaak komt dan ingesteld); sorteren op langst geen contact.
- **📡 Verbindingen en taken** (*Instellingen → Overzicht*): wat er bij RzMon binnenkomt (agents met hun ritme en realtime, browsers, API-sleutels, heartbeats) en hoe RzMon's eigen achtergrondtaken lopen (cron, updates zoeken, blocklists, GeoIP, datalekken, kwetsbaarheden, certificaat, MQTT, back-up): laatst gelukt, volgende keer en eventuele fout. Lukt een taak te lang niet, dan volgt een ⚠️-melding via je gewone kanalen (en een ✅ als het weer lukt).
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
- **Reverse proxy** (SWAG, Nginx Proxy Manager, Traefik, Cloudflare Tunnel): het echte IP en https worden alleen vertrouwd van proxy's die je zelf opgeeft; proxy-kopteksten van een onbekend adres = bezoeker van buiten, met een ⚠️-melding.
- **🔐 Certificaat voor de agents**: in een venster het adres van de agents, het eigen certificaat van RzMon (namen, geldigheid, vingerafdruk), de vastgezette vingerafdruk en per agent of hij hem kent; **downloaden** (.crt, met je wachtwoord) en **vernieuwen** (nieuwe sleutel, 2 jaar, automatische en eigen namen, via de hulpdienst als root, met reservekopie en terugzetten bij een fout); agents gaan vanzelf mee; dagelijkse controle (ℹ️ 14 dagen vooraf, ⚠️ verlopen).
- **🔓 Datalekken (Have I Been Pwned)**, met een hoofdschakelaar (standaard uit):
  - **wachtwoorden** (gratis, k-anonimiteit: alleen 5 tekens van de hash gaan naar HIBP): waarschuwing bij het instellen, **verplicht een nieuw kiezen** na inloggen met een gelekt wachtwoord (dan wordt een gelekt wachtwoord geweigerd); drempel instelbaar;
  - **e-mailadressen** (API-sleutel): van gebruikers, een eigen lijst en een geverifieerd **domein**, dagelijks en gespreid; ⚠️ bij een nieuw lek met wachtwoorden, anders ℹ️; eerste controle als één samenvatting;
  - tabblad **Datalekken** bij Kwetsbaarheden (tellers, tegels, filters, per adres de lekken met gegevenssoorten, ✔ afgehandeld); MQTT-sensoren; *Hoe staat het ervoor?*;
  - **per account** aan of uit (nieuwe accounts aan).
- **🏠 Alleen lokaal inloggen** per account (bv. een noodaccount zonder 2FA): van buiten geweigerd met een ℹ️-melding (eigen rij in Systeeminfo), ook onthouden apparaten en open sessies; er blijft altijd een beheerder die van buiten mag.
- **📱 Ingelogde apparaten**: elke sessie (wachtwoord, 2FA, onthouden apparaat, LAN) met apparaat, IP (🏠/🌍), hoe, sinds en laatst actief. Gebruikers zien en loggen hun eigen sessies uit; beheerders zien alle sessies van alle gebruikers, met *Uitloggen*, *Alles van deze gebruiker uitloggen* en *Iedereen uitloggen behalve mij*. Uitloggen werkt meteen.
- **🔒 Beheer via https:**
  - een **beheeradres** (bv. via SWAG met Let's Encrypt) en **Beheer alleen via https**: http gaat naar het beheeradres, met **HSTS** (eerst 1 dag, na 7 dagen zonder problemen 1 jaar); agents en de REST API blijven via http werken;
  - de **certificaatkaart**: uitgever, namen, geldigheid, vingerafdruk, vertrouwd, en of de lokale **DNS-rewrite** werkt; dagelijks gecontroleerd, ℹ️ 14 dagen voor het verloopt, ⚠️ als het verlopen of ongeldig is;
  - de **nooddeur** (https op het IP-adres van RzMon, alleen van binnen) en het noodcommando `install.php --https-off`;
  - een **rode balk** op de inlogpagina via http, en **inloggen via http weigeren**;
  - **2FA verplicht van buiten** je interne netwerken (onthouden apparaten alleen als ze met 2FA zijn onthouden).
- **Bescherming:** wachtwoorden met bcrypt, afremmen van inlogpogingen, CSRF-bescherming, veilige cookies, een eigen token per agent, en API-sleutels alleen als hash.
- **🔐 Beveiligde verbinding** (agent 1.50.0+): een **eigen sleutel per agent**, geen token meer over de lijn, elk verzoek versleuteld of ondertekend; nieuwe agents koppelen met een **koppelcode** (eenmalig, 15 minuten), bestaande stappen vanzelf over; sleutels vanzelf vernieuwd (elke 30 dagen); het **certificaat van RzMon vastgezet** bij een eigen certificaat; agents alleen vanaf hun **bekende IP-adres** (nieuw adres = geweigerd tot je het toestaat); vastzetten en het IP-slot ook **per agent** aan of uit; het installatiecommando controleert script en agent met SHA-256.
- **🌍 Agents via https** (agent 1.51.0): het adres van RzMon **vanuit RzMon wijzigen** (per agent of alle tegelijk; de agent test het eerst met zijn eigen sleutel), een **reserve-adres** als de proxy niet bereikbaar is (⚠️/✅), een kolom **Verbinding** (https via proxy, https, http, reserve) en **Agents alleen via https** (algemeen en per agent).
- **🔒 Lokaal via https zonder proxy** (agent 1.53.0): rechtstreeks naar `https://<IP van RzMon>` met het eigen certificaat, **vastgezet** door de agent; de controle geldt **per adres**, zodat een rechtstreeks adres en het proxy-adres (DNS-rewrite) samen gewoon adres en reserve-adres kunnen zijn. Adresvenster met een keuzelijst (IP-adressen, namen, beheeradres of zelf invullen, met **Testen**), http of https naar keuze, reserve-adres uit te zetten. **Certificaat vernieuwen in twee stappen**: eerst klaarzetten, de agents krijgen de nieuwe vingerafdruk, dan pas wisselen (uiterlijk na 7 dagen, of *Nu wisselen*). Reserve-adres: één samengevatte ⚠️-melding na 5 minuten.
- **Hoe staat het ervoor?** wijst op een proxy die via http doorstuurt (met de SWAG-regels om te kopiëren), inloggen via http (knop *Weigeren*), split DNS als tip, en stelt *Agents alleen via https* voor (knop *Aanzetten*).
- **🔐 Versleutelde agents** (agent 1.49.0+): rapport, voortgang en instellingen versleuteld (AES-256 + HMAC, tegen opnieuw afspelen), het antwoord ook; één sleutel met **🎲 genereren** (de vorige nog 24 uur geldig), *uit* / *aan* / *verplicht*, ook **per agent**; 🔒 per server.
- **ℹ️ Systeeminfo per melding:** voor elke melding van RzMon zelf (updates, agents, logins, blokkades, kwetsbaarheden, ⚠️ niet-geautoriseerd) Pushover (stil of met geluid), e-mail, Home Assistant en de meldingenpagina apart aan te vinken, met een 🧪 testknop.
- **🔐 Inlogbeveiliging:** na een in te stellen aantal mislukte pogingen (ook 2FA) binnen een in te stellen tijd het **account** en/of het **IP-adres** (niet van je LAN) blokkeren, voor een in te stellen tijd of tot een beheerder deblokkeert; vanaf je LAN kun je een geblokkeerd account nog gebruiken; ⚠️ + Pushover.
- **⛔ Blokkades** op IP-adres of netwerk voor heel RzMon (webpagina, login, API, agents), ook met één klik vanuit Meldingen; jezelf blokkeren kan niet.
- **🖥️ Proxmox VE** (1.105.0, agent 1.61.0): de agent leest Proxmox zelf uit (guests, opslag, ZFS, cluster, back-ups per guest en de back-uptaken). Checks voor het overzicht van de node, één guest, back-ups (2/7 dagen, per guest aan te passen, ook *in geen back-uptaak*), opslag en ZFS; *＋ Aanbevolen checks*, een overzicht per node met ＋ Check per guest, en losse nodes samen als **cluster**. Eigen tabblad in de Agent Manager.
- **📡 UniFi, AdGuard Home en weren** (1.104.0): namen, wifi-netwerk en access point uit UniFi (ook Network 10; lokaal account of API-sleutel), namen uit AdGuard Home en per apparaat **met welke domeinen het praat**. **⛔ Weren** van een apparaat: van het wifi (UniFi), geen DNS (alle AdGuard-instanties) en geen toegang tot RzMon en je servers, voor 1 dag, 7 dagen of tot je het weer toelaat; RzMon, je router, de controller en je servers kun je niet weren.
- **🔎 Netwerkscan** (1.103.0, standaard uit): alle apparaten op je lokale netwerken op het tabblad **Netwerk**: IP, MAC, fabrikant, naam (DNS, NetBIOS, mDNS, UPnP), open poorten en de soort apparaat. Elk uur een snelle ronde, een paar keer per dag dieper. Riskante poorten (Telnet, FTP, VNC …) in rood met advies, een ℹ️-melding bij een nieuw apparaat, en met één klik toevoegen aan RzMon, bekend markeren of negeren.
- **⏳ Wachtende apparaten** (1.102.0): een browser van buiten die geweigerd wordt (404, alleen toegestane apparaten) komt op een lijst in de beheerportal, met een melding. Toestaan voor één gebruiker (de bezoeker merkt niets tot je het toestaat; daarna het inlogscherm), negeren of het IP blokkeren. Alleen browsers uit je normale landen, geen scanners of bots.
- **🔎 Incidentvenster met advies** (1.101.0): klik op een IDS-incident voor uitleg, hoe erg het is, *Was jij dit?* (ja = afgehandeld, nee = noodstappen), stappen en commando's om te kopiëren, en alles over het IP (DNS-naam, eigenaar via RDAP, abuse-adres, hosting/VPN, bekende aanvaller, tijdlijn; met korte time-outs en 7 dagen bewaard), plus alle knoppen. De hardening-punten klappen open met uitleg, controleren en oplossen.
- **🔁 Versie kiezen bij bijwerken** (1.90.0): elke versie uit de changelog, ook terugzetten naar een oudere (met wachtwoord); automatisch bijwerken pauzeert zolang je niet op de nieuwste zit.
- **📜 Logs van webdiensten** (1.89.0, agent 1.58.0): SWAG (aanvalspaden, scanners, veel 404/401/403, zonder hostnaam), Home Assistant en Vaultwarden (mislukte logins). Logs worden zelf gevonden (of zelf in te vullen), elk scenario en elke bron aan/uit, blokkeren op al je servers, je eigen netwerk nooit. Blokkades werken nu ook vóór Docker.
- **🧰 Hardening, isoleren en tijdlijn** (1.88.0, agent 1.57.0): hardening-score per server met advies per punt (incident als iets slechter wordt), een server met één klik **isoleren** (vervalt vanzelf, ook zonder RzMon), een gebruiker op een server vergrendelen, en een **tijdlijn per IP** over al je servers.
- **🕸️ Webshell, persistentie en uitgaand** (1.87.0, agent 1.56.0): melding als een webserver een shell of `curl` start (optioneel het proces stoppen), bij een nieuwe automatisch startende dienst, en bij verbindingen naar bekende aanvallers (critical, optioneel uitgaand blokkeren, ook voor containers) of vreemde landen (warning, aan te leren). Vrijgeven met één klik.
- **🕶️ Onzichtbaar van buiten** (1.86.0): van buiten niet herkenbaar als RzMon (gewone 404, geen versie, agent-script alleen met downloadsleutel). Optioneel de webinterface alleen op **toegestane apparaten** per gebruiker, alleen vanuit je normale landen, met een **noodlink** als je sleutel kwijt is.
- **🗝️ Passkeys** (1.86.0): inloggen met Face ID, vingerafdruk of beveiligingssleutel, phishing-bestendig; wachtwoord met 2FA als reserve.
- **⛔ Blokkades en whitelist** (1.83.0; vanaf 1.92.0 in het tabblad *IDS → Blokkades*): één overzicht van alles wat geblokkeerd is: IP's in RzMon, vergrendelde accounts en IDS-blokkades per server. Er zijn tabbladen *Nu geblokkeerd* en *Eerder*, met de reden, het land, filters en zoeken.
  - Per regel kun je **🔓 deblokkeren** of **✅ op de whitelist** zetten. Dat geldt 1/7/30 dagen of altijd, je bevestigt met je wachtwoord en het komt in het auditlog.
  - De whitelist kent drie soorten: een IP of netwerk (RzMon en het IDS op alle servers), een account (nooit vergrendelen, het IP van de aanvaller wel) en een naam die geen honeypot-naam is.
- **Beveiligingsmeldingen:** ⚠️ + Pushover bij een niet-geautoriseerde verbinding (fout token, verkeerde sleutel, verkeerde API-sleutel, 3 mislukte logins); ℹ️ bij elke login in RzMon en als een agent (opnieuw) verbindt.
- **Gevoelige acties** (back-up downloaden of terugzetten, databasewachtwoord, commando's toestaan) vragen om je wachtwoord.
- **Logboek** van gevoelige wijzigingen.
- **🛡️ IDS (intrusion detection)**, pagina *IDS*:
  - standaard **uit**; met een schakelaar **leren** (alleen melden) of **blokkeren** (met je wachtwoord);
  - een **basismeting** per server (standaard 24 uur), daarna alleen afwijkingen; *Dit is normaal* en *basismeting opnieuw*;
  - meldt gewijzigde gevoelige bestanden, nieuwe gebruikers (en een tweede root), poorten, SUID-programma's en kernelmodules, verdachte processen, reverse shells, brute force, inlog na brute force, root-login, logins vanaf nieuwe IP's en **elke SSH-login van buiten** (met melding); logins van binnen als ℹ️ systeeminfo, en login-incidenten van binnen sluiten na 24 uur vanzelf; **🍯 honeypot** (honeypot-gebruikers en een honeypot-poort: één poging = blokkeren; vanaf je eigen netwerk critical), **groepswijzigingen** (wheel, sudo, docker …), PAM en andere achterdeuren, **automatische blokkades op alle servers** en een **📬 wekelijks overzicht** per e-mail; **☠️ bekende aanvallers** (Spamhaus DROP en FireHOL level 1, vooraf geblokkeerd), **🌍 land per IP** (DB-IP Lite, lokaal; een login uit een ander land is critical) en **open poorten met een actief misbruikte kwetsbaarheid** (KEV);
  - **critical** ook naar Pushover (ook tijdens stille uren), **warning** alleen op de pagina IDS;
  - **blokkeren** op de aangevallen server: automatisch bij brute force (24 uur; bij herhaling binnen 30 dagen 7 dagen; permanent met de hand) of met één klik, en **deblokkeren**; je LAN, RzMon en een eigen lijst nooit automatisch;
  - **📁 bestanden bewaken** (FIM): eigen paden (algemeen en per server, met jokertekens zoals `/var/www/**/*.php`), met hash, grootte, rechten en eigenaar; nieuw, gewijzigd, verwijderd of SUID erbij; elke minuut snel, dagelijks volledig; veel wijzigingen tegelijk als één incident; *＋ Aanbevolen*, *goedkeuren* en *niet meer bewaken*;
  - TrueNAS SCALE alleen detecteren;
  - **🛰️ endpoint-bewaking** (1.110.0, agent 1.63.0; algemeen en per server aan/uit): processen, gevoelige paden en kernelmodules (auditd), verbindingen en luisterpoorten, nieuwe bestanden in tijdelijke mappen en webmappen (inotify) en containers, op het moment zelf; getoetst aan **eigen regels met uitleg** en **121 Sigma-regels van SigmaHQ**; RzMon **stopt het proces**, **blokkeert het IP** of **isoleert de server** (elk los aan/uit, alleen in de modus Blokkeren); ontbrekende onderdelen (auditd, inotify-tools) staan onder de servernaam, met **installeren via de agent**; tabbladen **🛰️ Live** en **🔏 Regels**;
  - **🧬 afwijkingen** (1.111.0): RzMon leert per server 14 dagen (instelbaar) wat normaal is en meldt daarna wat die server nog nooit deed (een dienst die iets nieuws start, een nieuw programma dat luistert of naar buiten belt, een nieuwe gebruiker, een nieuw land, ongewoon veel processen, actief op een ongewoon tijdstip …), met een score; vanaf een drempel een incident; *Leren afronden* en *Opnieuw leren* per server;
  - **🔗 ketens en combinaties** (1.111.0): webaanval → code op de webserver, inloggen → rechten of achterdeur, binnenhalen → contact naar buiten, meerdere aanvalsstappen, veel afwijkingen tegelijk en een login vanaf een gehackte eigen server; critical met alle stappen in volgorde, en in de modus Blokkeren de aanvaller blokkeren of de server isoleren;
  - **🌐 dreigingsinformatie** (1.112.0): AbuseIPDB (eigen API-sleutel in de beheerportal), GreyNoise, Shodan InternetDB, CIRCL hashlookup, abuse.ch (Feodo Tracker, URLhaus, en met een sleutel MalwareBazaar en ThreatFox) en Tor; een oordeel per IP en bestand bij het incident en het IP-adres; bekende aanvallers blokkeren, een login van een bekende aanvaller critical, onschuldige scanners vanzelf afhandelen, verbindingen met botnet-servers meteen critical, bekende malware, aanvallen op een lek dat de server echt heeft; geblokkeerde aanvallers melden bij AbuseIPDB (optioneel);
  - **🧭 netwerk** (1.113.0): **DNS-controle via AdGuard** voor elk apparaat (malware- en botnetdomeinen van URLhaus/ThreatFox, Safe Browsing, antwoorden naar bekende aanvallers, DNS-tunnels en verzonnen domeinen), **Suricata**-alerts via de agent, en **YARA**-controle van nieuwe bestanden met regels van RzMon (webshells, miners, botnets, rootkits);
  - **✔ RzMon zelf toegestaan** (1.114.0): wat de agent, RzMon (web, API, cron) en het verkeer met RzMon doen, wordt nooit tegengehouden of gemeld, maar wel gelogd als groene regel in Live (samengevat per rapport, apart als een regel geraakt zou zijn);
  - **🧭 MITRE ATT&CK-overzicht** (1.111.0): alle meldingen per tactiek en techniek over 7, 30 of 90 dagen, met de dekking van de regels;
  - **payload-inspectie** van webverzoeken via SWAG: SQL-injectie, XSS, command injection, path traversal, Log4Shell, SSRF, SSTI en meer, bekende exploits met hun CVE en scanners; het verdachte stuk gedecodeerd en gemarkeerd in het incident (**Inspectie**, met MITRE ATT&CK);
  - drie tabbladen: **Incidenten**, **🔑 Toegang** (logins, sudo en su per dag, met filters, sorteren, 🙈 negeren en ⛔ blokkeren; los aan/uit te zetten) en **Blokkades**, plus een knop **⛔ IP blokkeren** voor een IP-adres naar keuze.
- **🐞 Kwetsbaarheden**, pagina *Kwetsbaarheden* (standaard **uit**):
  - de agents (1.47.0+) sturen hun pakketlijst mee (dpkg of rpm), RzMon leest die van zichzelf; RzMon zoekt de CVE's op bij **OSV.dev** met de adviezen van de distributie (geen vals alarm door backports; alleen pakketnaam, versie en distributie gaan naar OSV);
  - per server: pakket, geïnstalleerde versie, **opgelost in**, CVE's per ernst, **🌐 bereikbaar** (luistert op het netwerk), **⚠️ actief misbruikt** (CISA KEV), **↻ herstart nodig**; tellers, zoeken en filters, en de hele softwarelijst;
  - klik op een CVE: omschrijving, CVSS met uitleg, EPSS, adviezen en links, en per server het commando om bij te werken;
  - **bijwerken via de agent** per pakket of *alle beveiligingsupdates* (beheerders, met wachtwoord, alleen als commando's als root aan staan; anders het commando om zelf uit te voeren), met een **voortgangsbalk** (stap, percentage, pakket en uitvoer; agent 1.48.0), daarna meteen een nieuwe scan en een ℹ️ info-melding;
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
- **✔ Eigen processen** (1.65.0): wat de agent start (systemd-groep `rzmon-*.service`) en RzMon op zijn eigen server worden herkend en als "toegestaan" gemeld; de agent stopt nooit een eigen proces.
- **🧭 Suricata en YARA** (1.64.0): nieuwe alerts uit Suricata's `eve.json` gaan mee met het rapport (`SURICATA=0` zet het uit); met `yara` controleert de agent nieuwe bestanden met de YARA-regels die RzMon versleuteld stuurt.
- **🛰️ Endpoint-bewaking** (1.63.0): processen en gevoelige paden via eigen `auditd`-regels, verbindingen, nieuwe bestanden (inotify) en containers, in de realtime-watcher; een eigen wachtrij die pas leeg gaat als het rapport aankwam; een proces stoppen alleen op versleuteld verzoek van RzMon, na controle van pid en starttijd; onderdelen installeren op verzoek; `EDR=0` zet het op een server uit.
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
