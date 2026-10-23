# Changelog

Alle wijzigingen aan RzMon, per versie. Nieuwste bovenaan.
Versienummers volgen [Semantic Versioning](https://semver.org/lang/nl/):
`MAJOR.MINOR.PATCH`. MINOR gaat omhoog bij nieuwe functies, PATCH bij reparaties,
en MAJOR bij wijzigingen waarvoor je zelf iets moet aanpassen.

Het versienummer staat in [`VERSION`](VERSION). Verhogen gaat met `tools/bump-version.sh` (zie [CLAUDE.md](CLAUDE.md)).

## 1.57.3 – 2026-10-23
### Opgelost
- **Opengeklapte onderdelen gingen steeds weer dicht.** De pagina's Meldingen, IDS en Kwetsbaarheden worden elke 20 seconden ververst; daarbij klapte alles weer in, zoals *Uitvoer* (het logboek) bij een server, een andere dag bij Meldingen of een uitgeklapt bericht. Nu blijft open wat je openzette, en blijft de pagina op dezelfde plek staan.
- Nieuwe agent 1.49.1: pakketten bijwerken via de agent mislukte op servers met systemd met *_: line 34: : command not found*. Werk de agents bij (*Instellingen → ⬆ Agents bijwerken*) en probeer het opnieuw.

## 1.57.2 – 2026-10-23
### Opgelost
- **Kwetsbaarheden: na het invoeren van je wachtwoord verscheen soms geen voortgang.** Het venster ging eerst dicht en het voortgangsvenster opende pas daarna; lukte die eerste vraag om de stand niet (even geen verbinding, telefoon), dan bleef het leeg. Nu blijft het venster open en wisselt het meteen naar de voortgang (*Wachten op de agent*, met de pakketten); de stand wordt daarna steeds opnieuw opgevraagd, ook na een mislukte vraag.

## 1.57.1 – 2026-10-23
### Opgelost
- Op de pagina Meldingen stond bij een melding die nergens heen gestuurd is (kanaal of meldregel uit) het icoontje ✉️✕. Dat leek op een mislukte e-mail, ook als e-mail uit staat. Dat is nu 🔕 (*niet verstuurd, alleen op deze pagina*). RzMon probeerde nooit een mail te sturen als e-mail uit staat.

## 1.57.0 – 2026-10-23
### Nieuw
- **🔐 Versleutelde agents** (*Instellingen → Toegang → Versleuteling van de agents*). Met **🎲 Sleutel genereren** maak je één sleutel; agents vanaf **1.49.0** krijgen hem bij hun volgende rapport en versturen daarna alles versleuteld (AES-256 met een handtekening): het rapport, de voortgang van pakketten bijwerken en het ophalen van hun instellingen. Het antwoord van RzMon komt ook versleuteld terug. Oude of opnieuw afgespeelde berichten en een verkeerde sleutel worden geweigerd. Kies *Uit*, *Aan* (standaard; oude agents werken nog) of *Verplicht*, en per server een eigen keuze in de Agent Manager. Een nieuwe sleutel: de vorige blijft nog 24 uur geldig. Het installatiecommando in de Agent Manager geeft de sleutel mee (`--key`). **Nieuwe agent 1.49.0** nodig om te versleutelen.
- **⛔ Blokkades** op IP-adres of netwerk (`203.0.113.0/24`, ook IPv6) voor **heel RzMon**: webpagina, inloggen, REST API en agents. Op de kaart *Blokkades*, of met één klik (*⛔ blokkeren*) bij een melding. Jezelf en de RzMon-server kun je niet blokkeren; buitengesloten: `sudo php install/install.php --unblock-all`.
- **⚠️ Niet-geautoriseerde verbinding** in *Meldingen* en **altijd naar Pushover** (ook tijdens stille uren): een agent met een fout token of een verkeerde sleutel, een verkeerde of verlopen API-sleutel, of 3 mislukte logins binnen 10 minuten. Eén melding per IP per uur, daarna een teller.
- **ℹ️ Logins in RzMon** (wie, vanaf welk IP, welk apparaat, met of zonder 2FA) en mislukte pogingen, en **ℹ️ Agent verbonden** (eerste keer, weer verbonden, ander IP). Altijd op de pagina Meldingen; de andere kanalen volgen de rij *Info*.
- Nieuwe agent 1.49.0.

## 1.56.1 – 2026-10-23
### Opgelost
- **Het update-venster kon blijven hangen op *Installeren*.** Tijdens het installeren worden PHP en Apache herstart; een vraag om de voortgang die op dat moment liep, kon dan eeuwig blijven wachten, waarna het venster niets meer ververste. Nu wacht elke vraag hooguit 10 seconden, en komt er 20 seconden niets binnen (of kom je terug in de app op je telefoon), dan vraagt het venster opnieuw.
- **Een afgebroken update bleef altijd "bezig".** Het update-script geeft nu elke 10 seconden een teken van leven, en stopt het onverwacht, dan meldt het dat zelf. Blijft het teken van leven uit (90 seconden, of 20 minuten bij een update die nog met het oude script loopt), dan zegt het venster dat de update niet meer reageert, met het logboek en wat je op de server kunt controleren. Daarna kun je opnieuw bijwerken.
- Het teken van leven werkt vanaf de update ná deze, omdat het update-script zelf tijdens deze update wordt vernieuwd. De rest werkt meteen.

## 1.56.0 – 2026-10-23
### Nieuw
- **Voortgang bij bijwerken op de pagina 🐞 Kwetsbaarheden.** Na *⬆ Bijwerken* blijft het venster open met een voortgangsbalk, de stappen (*Wachten op de agent → Pakketlijst ophalen → Downloaden → Installeren → Klaar*), waar de server nu mee bezig is (bv. *Installeren: openssl*) en de laatste regels van apt of dnf. Met *Op de achtergrond* sluit je het; bij de server staat dan *⏳ Bezig met bijwerken 45%* om het weer te openen. **Agent 1.48.0 nodig** voor het percentage; met een oudere agent zie je een bewegende balk.
- Na het bijwerken scant RzMon de server meteen opnieuw zodra de nieuwe softwarelijst binnen is; het venster laat zien wanneer dat klaar is.
- ℹ️ Info-melding als pakketten bijwerken klaar is of mislukt (volgt de rij *Info* bij 🔔 Meldingen).
- Nieuwe agent 1.48.0.

### Opgelost
- *Instellingen → 🐞 Kwetsbaarheden* staat in L1nes nu in de groep *📈 Monitoring*, onder 🛡️ IDS (stond bovenaan), met een samenvatting achter de titel.

## 1.55.0 – 2026-10-23
### Nieuw
- **Tellers op de tabbladen Dashboard en Meldingen**, net als bij 🛡️ IDS. *Dashboard*: het aantal nieuwe problemen sinds je laatste bezoek (checks die warning, critical of onbekend werden en dat nog zijn), in de kleur van het ergste. *Meldingen*: het aantal nieuwe meldingen sinds je die pagina voor het laatst opende (zonder info en bevestigingen). Open je het tabblad, dan gaat de teller naar 0. Dit wordt per gebruiker onthouden, dus ook op je telefoon. Het totaal staat ook in de titel van het browsertabblad, bv. *(3) RzMon*.
- **Blox in Small en Large**, net als L1nes (*Instellingen → Weergave*). *Small*: compacte tegels met naam, status en de checks met problemen, zodat er meer servers in beeld passen; meldingen op één regel. *Large*: zoals altijd. Blox en L1nes onthouden elk hun eigen keuze; Blox blijft standaard op Large.
- **Bijwerken in een venster met voortgangsbalk.** *Nu bijwerken* of het label **⬆** bovenin opent een venster met de vraag of je wilt bijwerken (met wat er nieuw is en *Eerst een back-up maken*). Daarna zie je een voortgangsbalk met percentage, de stappen, waar de installatie nu mee bezig is en de laatste regels van het logboek. Herstart RzMon even, dan wacht het venster; na herladen gaat het vanzelf weer open. Klaar: de pagina laadt de nieuwe versie.
- De hulpdienst geeft bij *Installeren* nu ook het onderdeel door (bv. *Database (4/9)*). Dat zie je vanaf de volgende update, omdat de hulpdienst zelf tijdens deze update wordt vernieuwd.

## 1.54.2 – 2026-10-23
### Opgelost
- **Kwetsbaarheden: scannen mislukte bij servers met veel kwetsbaarheden** (meer dan 500, bijvoorbeeld Debian of Proxmox) met *SQLSTATE[HY093]: Invalid parameter number*. Dat kwam door het opslaan in de database, niet door OSV.dev; toch stond er *OSV.dev niet bereikbaar*. Beide zijn opgelost: andere fouten heten nu *Scannen mislukt*.
- Een server waarvan de scan mislukte, wordt nu elk uur opnieuw geprobeerd in plaats van pas bij de dagelijkse scan. Na de update komt het dus vanzelf goed; met *🔍 Nu scannen* meteen.
- Op de telefoon stond het pijltje ▸ bij een server op een eigen regel.

## 1.54.1 – 2026-10-23
### Opgelost
- De schakelaar **Kwetsbaarheden aan** (*Instellingen → 🐞 Kwetsbaarheden*) deed niets zolang je niet ook op *💾 Instellingen opslaan* klikte. Nu werkt hij direct, net als de schakelaar van het IDS: je ziet *Kwetsbaarheden aangezet*, en na een tel staat het tabblad 🐞 Kwetsbaarheden in de bovenbalk (of is het weg bij uitzetten).

## 1.54.0 – 2026-10-23
### Nieuw
- **🐞 Kwetsbaarheden**: een nieuwe pagina met de software per server en de bekende kwetsbaarheden (CVE's) daarbij. Standaard **uit**; aanzetten bij *Instellingen → 🐞 Kwetsbaarheden*. Zichtbaar voor beheerders en gebruikers.
  - RzMon zoekt de kwetsbaarheden op bij OSV.dev, met de adviezen van de distributie zelf (Debian, Ubuntu, Rocky Linux, AlmaLinux en Alpine; RHEL en CentOS via AlmaLinux). Alleen pakketnamen, versies en de distributie gaan naar OSV.dev.
  - Per server: pakket, geïnstalleerde versie, *opgelost in*, de CVE's per ernst, **🌐 bereikbaar**, **⚠️ actief misbruikt** (CISA) en **↻ herstart nodig**. Met tellers, zoeken, filters en de hele softwarelijst.
  - Klik op een CVE voor de omschrijving, de CVSS-score met uitleg, de kans op misbruik (EPSS), de adviezen, en per server het commando om bij te werken.
  - **Bijwerken via de agent** per pakket of alle beveiligingsupdates tegelijk (beheerders, met je wachtwoord, als commando's als root aan staan voor die server). Anders krijg je het commando om zelf uit te voeren.
  - **Risico accepteren** met een reden, ℹ️ info bij een nieuwe critical of actief misbruikte kwetsbaarheid, de REST API (`/v1.php/vulnerabilities`, nieuw recht *Kwetsbaarheden lezen*) en MQTT-sensoren per server.
  - Nodig: uitgaand HTTPS naar `api.osv.dev`, `www.cisa.gov`, `api.first.org` en `services.nvd.nist.gov`.
- Nieuwe agent 1.47.0: stuurt de softwarelijst mee en kan pakketten bijwerken. Agent 1.47.0 nodig voor *Kwetsbaarheden* op agent-servers.

## 1.53.1 – 2026-10-22
### Opgelost
- Checkformulier: met **ℹ️ Alleen info-meldingen** aan zie je bij *🔔 Meldingen* alleen nog de rij **ℹ️ Info** (*Eigen info-kanalen voor deze check*); warning, critical, onbekend, hersteld en anti-flapping zijn dan verborgen. Zet je het uit, dan komt alles terug, met de instellingen die je eerder had.

## 1.53.0 – 2026-10-22
### Nieuw
- **Info-checks:** in het checkformulier staat de schakelaar **ℹ️ Alleen info-meldingen**. Zo'n check telt nooit als storing. Bij een statuswissel komt er één info-melding (*"nu WARNING: …"*, *"weer OK: …"*) in plaats van warning, critical of hersteld; *melden na … mislukte checks* geldt ook hier. Op het dashboard heeft hij een blauwe stip met de echte status (*ℹ️ CRITICAL*); de server blijft groen en de check telt niet mee in de gezondheid, de tellers en *⚠️ Problemen*. MQTT en de REST API geven `"info": true`.
- **📋 Meldingenpagina** als kanaal in de rij **ℹ️ Info** (algemeen en per check): of info-meldingen op de pagina Meldingen komen. Staat standaard aan, dus er verandert niets.
- Info staat na de update nog steeds uit: zet de rij Info op *Direct* om info-meldingen te ontvangen.

## 1.52.0 – 2026-10-22
### Nieuw
- **ℹ️ Info-meldingen** voor gebeurtenissen die geen storing zijn. In *Instellingen → 🔔 Meldingen* staat een nieuwe rij **ℹ️ Info** (nooit of direct, met de kanalen Pushover, e-mail en Home Assistant). Per check anders in te stellen (✎ → 🔔 Meldingen → *Eigen meldregels*). Na de update staat info **uit**, dus er verandert niets tot je het zelf aanzet.
  - **Server herstart:** nieuwe check **🔄 Herstart** (＋ Check, groep Systeem) voor de RzMon-server en agent-servers; ook de checks *HA OS herstart* (geplande herstart) en SNMP *Uptime* geven info bij een herstart.
  - **SSL-certificaat vernieuwd:** bij een certificaat-check.
  - **Agent bijgewerkt** (of mislukt): per server uit te zetten in de Agent Manager (*Info bij agent-updates*).
  - **RzMon bijgewerkt.**
  - Info gaat direct en via Pushover stil (prioriteit -1). Tijdens stille uren en onderhoud wordt hij niet verstuurd en komt hij niet in de samenvatting achteraf.
  - Op de pagina **Meldingen** staat elke info-melding altijd, in het blauw met ℹ️, met een filter *Alles · Zonder info · ℹ️ Info*. Home Assistant krijgt `"event": "info"`.
- Nieuwe agent 1.46.0: stuurt zijn opstarttijd mee (nodig voor de check *Herstart* op agent-servers). Werk je agents bij met de knop in *Instellingen → ⬆ Agents bijwerken*.

### Opgelost
- **Anti-flapping hield geen rekening met "melden na … mislukte checks achter elkaar".** Een check die vaak wisselde, gaf meteen een melding *FLAPPEND* en per status een melding, ook als hij maar één keer mislukte. Nu flapt hij stil tot het ingestelde aantal gehaald is. Daardoor leek het of bestaande checks de instelling oversloegen en nieuwe niet.

## 1.51.1 – 2026-10-22
### Opgelost
- Teksten die de agent zelf meestuurt, staan nu ook in het Engels als *Taal van meldingen en checkberichten* op English staat. Het gaat om de diepere servicecontrole (bijvoorbeeld *database antwoordt (ping)*), sockets, de extra test en de eigen test, en om fouten bij log-, commando-, Podman-, certificaat- en Home Assistant-checks en bij het bijwerken van een agent. RzMon vertaalt ze zelf: je hoeft de agent niet bij te werken.

## 1.51.0 – 2026-10-22
### Nieuw
- **⚠️ Problemen bovenaan per server.** Checks in warning, critical of onbekend staan op het dashboard bovenaan de server in de groep *⚠️ Problemen*, met een kopje per status (🔴 Critical, 🟠 Warning, ⚪ Onbekend) en onderaan *✔ Bevestigd*. Ze staan niet ook nog in hun gewone groep; zodra een check weer OK is, gaat hij daar vanzelf naar terug. Werkt in Blox en L1nes, ook bij servers met weinig checks.
- Checks in onderhoud blijven in hun gewone groep.
- De groep *Problemen* is in te klappen; komt er een nieuw probleem bij, dan gaat hij vanzelf weer open.

## 1.50.0 – 2026-10-22
### Nieuw
- **Engelse interface (deel 2): nu helemaal vertaald.** Ook de instellingen, IDS, alle vensters (check, server, Agent Manager, SNMP, poortscan, back-up …), foutmeldingen, de setup-pagina en de bevestig-pagina uit Pushover.
- **Taal van meldingen en checkberichten** (*Instellingen → 🔔 Meldingen*): Nederlands of English. Geldt voor Pushover, e-mail, Home Assistant en MQTT, de berichten van checks, IDS- en toegangsregels en de antwoorden van de REST API. Na de update staat dit op Nederlands, dus er verandert niets tot je het zelf aanpast.
- Mails voor *wachtwoord vergeten* gaan in de taal van het account.
- Op het lokale netwerk (zonder wachtwoord) typ je ter bevestiging het woord in je eigen taal, bijvoorbeeld *BLOCK* of *BLOKKEREN*; beide worden geaccepteerd.
- Wat al bewaard is (oude meldingen, het logboek), blijft in de taal van toen.

### Opgelost
- De inlogcode (tweestapsverificatie) en de link voor een nieuw wachtwoord herkenden "opnieuw inloggen" en "link verlopen" aan de tekst van de foutmelding; dat werkt nu ook in het Engels.

## 1.49.0 – 2026-10-22
### Nieuw
- **Engelse interface (deel 1).** Bovenaan *Instellingen* staat een schuifje **EN ◯ NL**. De keuze hoort bij je account en gaat mee naar elk apparaat. Bestaande accounts blijven Nederlands; nieuwe accounts en de inlogpagina volgen de taal van de browser.
- Vertaald: inloggen en wachtwoord vergeten, de bovenbalk, het dashboard (gezondheidsbalk, servers, checks) en het tabblad *Meldingen*. Datums en getallen volgen de taal. De rest (instellingen, IDS, vensters, checkberichten en meldingen) volgt in een volgende versie.
- **Setup** staat niet meer in de bovenbalk, maar als knop **⚙️ Setup** op de pagina *Instellingen*, en alleen voor beheerders.

### Opgelost
- Opende je RzMon direct op *Instellingen* (bijvoorbeeld na herladen), dan gaf de pagina een fout en bleven de API-sleutels leeg.
- "1 dagen geleden" is nu "1 dag geleden".


## 1.48.0 – 2026-10-22
### Nieuw
- **Melden na N mislukte checks.** Onder *Instellingen → 🔔 Meldingen* stel je in hoe vaak een check achter elkaar mag mislukken voordat er een melding uitgaat. Per check kun je een eigen aantal kiezen (✎ → 🔔 Meldingen); leeg = de algemene instelling.
- Het telt per status (warning en critical apart, onbekend telt mee) en werkt samen met *direct* / *na X minuten*: beide moeten kloppen. De herstelmelding verandert niet.
- Op het dashboard staat **⏳ 2/3** bij een check die nog niet gemeld is.
- Na de update staat het aantal op 1, dus alles werkt zoals voorheen tot je het zelf aanpast.
- De REST API geeft per check `status_streak` en `notify_fails`.


## 1.47.0 – 2026-10-22
### Nieuw
- **📡 SNMP.** Per server zet je bij ✎ Bewerken SNMP aan (v2c met community, of v3 met gebruiker, SHA/SHA-256 en AES). RzMon leest het apparaat dan zelf uit, ook zonder agent: switches, UniFi, TP-Link, MikroTik, Synology, een UPS, printers of Proxmox.
- **🔍 Test SNMP** in het serverformulier toont meteen de naam, de fabrikant en de uptime, of wat er mis is.
- **📡 SNMP-checks:** RzMon doorzoekt het apparaat en toont wat er te bewaken valt, om aan te vinken: uptime (herstart), CPU, geheugen, load, temperatuur, elke interface (up/down, verkeer, bezetting), elke schijf of elk volume, UPS (status, lading, looptijd, belasting, accutemperatuur; UPS-MIB en APC), Synology (systeem en ventilatoren, volumes degraded/crashed, schijfgezondheid), printervoorraden en UniFi-clients.
- **Eigen OID:** elke numerieke OID als getal met drempels (met omrekening en "lager is slechter"), als teller (waarde per seconde) of als tekst om te vergelijken.
- Community en wachtwoorden gaan nooit terug naar de browser.
- `install-rocky.sh` installeert `php-snmp` en `net-snmp-utils` (voor `snmpwalk`). Bij een update gebeurt dat ook; met de hand: `sudo dnf -y install php-snmp net-snmp-utils && sudo systemctl restart php-fpm`.


## 1.46.1 – 2026-10-22
### Opgelost
- Nieuwe agent **1.45.2**: de Home Assistant-add-on (en een agent op Alpine Linux) stuurde sinds agent 1.43.0 geen rapporten meer, waardoor Home Assistant in RzMon op *onbereikbaar (agent stil)* stond. Werk de add-on bij in de add-on winkel; Linux-servers werk je bij met ⬆ zoals altijd.

## 1.46.0 – 2026-10-22
### Nieuw
- **Eigen versienummer voor de agent.** RzMon en de agent (met de Home Assistant-add-on) worden apart genummerd. Verandert er alleen iets in RzMon, dan zijn je agents niet meer "verouderd" en hoef je ze niet bij te werken. De agent staat nu op 1.45.1, met een eigen [changelog](agent/CHANGELOG.md). In de *Agent Manager* staat "Agent-versie 1.45.1 ✔ nieuwste" in plaats van een vergelijking met RzMon.
- **Een update verandert je instellingen niet.** Het bijwerken van de database voegt alleen nieuwe instellingen toe. Een bestaande instelling, zoals IDS aan en de modus, wordt nooit meer veranderd; zou een update dat toch doen, dan zet RzMon de oude waarde direct terug. `config.php` bleef al staan.

### Opgelost
- Bij een herinstallatie met het installatiecommando werd `/etc/default/rzmon-agent` opnieuw geschreven (andere volgorde, commentaar weg). Nu blijft het bestand zoals het is; alleen wat je zelf meegeeft, zoals `--token`, wordt aangepast (agent 1.45.1).
- Gaf RzMon tijdens een update even een onvolledig antwoord, dan kon de agent denken dat het IDS uit stond en de blokkades op de server opheffen. De agent bewaart nu alleen een volledige lijst en houdt anders de vorige (agent 1.45.1).

## 1.45.0 – 2026-10-22
### Nieuw
- **Rechten per API-sleutel:** bij het maken kies je wat de sleutel mag: 📖 status lezen, 🛡️ IDS lezen, ⛔ IDS blokkeren en ✔ checks bedienen. Een sleutel met ⛔ vraagt om bevestiging met je wachtwoord.
- **Alleen vanaf:** een sleutel kan beperkt worden tot bepaalde IP-adressen of netwerken.
- **Geldigheid:** een nieuwe sleutel is 6, 12 of 24 maanden geldig (standaard 12). Met ✎ verleng je hem, en pas je rechten en adressen aan.
- **IDS in de API:** `GET /ids`, `/ids/incidents`, `/ids/blocks` en `/ids/access`, en `POST /ids/block` en `/ids/unblock`. Zo kan bijvoorbeeld fail2ban zijn bans aan RzMon doorgeven (voorbeeld in de handleiding, §11). Je LAN, RzMon zelf en *Nooit blokkeren* weigert de API altijd; staat het IDS op *leren*, dan wordt de blokkade alleen vastgelegd.
- **Checks bedienen via de API:** storing bevestigen of de bevestiging intrekken, onderhoud voor een check of een hele server, en een check nu uitvoeren.
- Maken, aanpassen en intrekken van sleutels, en alles wat een sleutel verandert, komt in het logboek.
- Bestaande sleutels houden alleen *Status lezen* en hebben geen vervaldatum; met ✎ geef je ze andere rechten.

## 1.44.0 – 2026-10-22
### Nieuw
- **EPEL automatisch:** heeft de realtime-watcher `inotify-tools` nodig en ontbreekt het, dan kijkt de agent-setup op Rocky, Alma, RHEL en Oracle Linux of EPEL er is. Zo niet, dan installeert hij het eerst (Rocky/Alma: `epel-release`; RHEL: het officiële pakket van het Fedora-project; Oracle: `oracle-epel-release`). Dat gebeurt bij elke installatie en elke update. CRB wordt niet aangezet.
- Lukt het niet (geen internet), dan gaat de installatie gewoon door.
- **Melding in RzMon:** ontbreekt EPEL of `inotify-tools`, dan staat er in de *Agent Manager* een waarschuwing met de oplossing (⬆ Opnieuw installeren, of het commando voor op de server). Op de IDS-pagina staat bij de server *EPEL ontbreekt*.
- Geen EPEL willen: `--no-epel` bij de setup (wordt bewaard als `EPEL=0`).

## 1.43.0 – 2026-10-22
### Nieuw
- **⚡ Realtime-watcher**, per server aan te zetten in de *Agent Manager* (standaard uit). Logins, mislukte pogingen, sudo, su, services die stoppen of crashen, en nieuwe of gewijzigde bestanden onder een 🔴-pad van *Bestanden bewaken* staan binnen een paar seconden in RzMon, in plaats van bij de volgende tik.
- De nieuwe dienst `rzmon-watch` volgt het systemlog (of het logbestand zonder journald) en, met inotify-tools, de bestanden. Bij een gebeurtenis start hij meteen een gewone agent-run (hooguit één per 10 s), zodat alle bestaande logica (IDS, brute force, meldingen) gewoon werkt.
- De status staat in de Agent Manager (*⚡ Realtime actief · systemlog ✔ · bestanden ✔*) en op de IDS-pagina.
- De setup maakt de dienst aan en installeert inotify-tools. Op Rocky/RHEL staat dat in EPEL; lukt het niet, dan werkt realtime voor logins en services nog steeds.
- `REALTIME=0` zet het op een server altijd uit. Niet via cron of op TrueNAS.
- **Home Assistant-add-on:** met realtime aan kijkt de add-on elke 5 s of er iets verandert aan de add-ons, Core of de boot-tijd, en rapporteert dan meteen.
- Twee agent-runs lopen nooit meer tegelijk (de tweede wacht even).
- Agent 1.43.0 nodig (bijwerken met ⬆; de dienst wordt bij het bijwerken aangemaakt).

## 1.42.0 – 2026-10-22
### Nieuw
Home Assistant: wat er op OS-niveau en met de containers gebeurt (add-on 1.42.0).
- **Onverwachte herstart van HA OS:** CRITICAL met een melding (een uur lang), bij stroomuitval, een crash of een handmatige herstart. Een herstart door een OS-update staat er alleen bij.
- **Levensduur van de SD-kaart of eMMC:** slijtage in % met drempels (75/90 %), als de hardware dat meldt.
- **Mislukte OS-update:** CRITICAL als HA OS is teruggevallen op de vorige versie (A/B-bootslots).
- **USB-apparaten:** kies je Zigbee- of Z-Wave-stick (of Bluetooth-adapter) uit een lijst; weg = CRITICAL.
- **Containers:** Core, Supervisor, de plugins en elke add-on met CPU en RAM, in hetzelfde overzicht (📦 Containers) en met dezelfde checks als Podman. Gecrasht = CRITICAL, herstart door de watchdog = WARNING.
- **Plugins** (DNS, Audio, CLI, Multicast, Observer) als service `plugin:<naam>`; hun updates staan bij `updates`.
- **Logbestanden** van HA OS, Core, de Supervisor en add-ons doorzoeken (`ha:host`, `ha:core`, `ha:supervisor`, `ha:addon:<slug>`), met voorinstellingen voor te lage voeding, diskfouten en fouten.
- **＋ Aanbevolen checks** voor Home Assistant in de Agent Manager.
- De add-on krijgt de Supervisor-rol **manager** (alleen om te lezen). Home Assistant vraagt daar bij het bijwerken naar.
- Getest met een nagebootste Supervisor, nog niet op een echte Home Assistant.

### Opgelost
- Containeroverzicht: geen "NaN%" meer bij een gestopte container.

## 1.41.0 – 2026-10-22
### Nieuw
- **⏱ Hoe vaak een agent rapporteert, stel je nu in RzMon in:** 30 s, 60 s, 2 min of 5 min. De standaard (60 s) staat onder *Instellingen → ⬆ Agents bijwerken*; per server kies je het in de *Agent Manager*. De agent neemt het binnen een minuut over.
- De systemd-timer start de agent voortaan elke 30 s; is de tik nog niet om, dan stopt hij meteen. Bij cron en op TrueNAS is één minuut het minimum. De Home Assistant-add-on volgt de tik ook (de optie `interval` is alleen nog voor oudere RzMon-versies).
- **Interval per check ook in de agent:** de diepere controle van een service, de extra test (URL/poort) en logbestanden doorzoeken gebeuren alleen als de check aan de beurt is. Tussendoor gaat het vorige resultaat mee; bij logbestanden gaat er niets verloren.
- **Meteen beoordeeld:** agent-checks worden beoordeeld zodra het rapport binnen is, niet pas bij de volgende cron-minuut. Met een tik van 30 s komt een melding dus sneller.
- *Agent offline* volgt de tik: een agent geldt pas als stil na minstens 3 gemiste tikken.
- Agent 1.41.0 nodig voor de tik van 30 s (bijwerken met ⬆ Bijwerken); oudere agents blijven gewoon werken.

## 1.40.0 – 2026-10-21
### Nieuw
- **📁 Bestanden bewaken (IDS).** Onder *Instellingen → 🛡️ IDS* geef je paden op met een ernst (🟠 warning of 🔴 critical), bv. `/usr/local/bin`, `/etc/nginx` of `/var/www/**/*.php`. Per server voeg je er paden aan toe via *✎ Bewerken*.
- De agent houdt per bestand de SHA-256-hash, grootte, rechten en eigenaar bij (nooit de inhoud). Hij meldt nieuwe, gewijzigde en verwijderde bestanden, en gewijzigde rechten. Komt er SUID of SGID bij, dan is het altijd 🔴.
- Elke minuut een snelle controle; een volledige hash alleen bij een verschil, en dagelijks van alles op de achtergrond.
- Meer dan 10 wijzigingen onder één pad tegelijk (bv. een website-update) worden één incident met de lijst.
- Op de pagina IDS: **✔ Wijziging goedkeuren** / **✔ Alles goedkeuren** en **🚫 Niet meer bewaken**. Bij de server staat hoeveel bestanden er bewaakt worden.
- **＋ Aanbevolen**: RzMon stelt paden voor die de agent op je servers heeft gevonden.
- Uitsluiten van bijvoorbeeld `*.log` en `cache/`. Grenzen: 20 paden (algemeen en per server), 10.000 bestanden per server; bestanden boven 100 MB alleen op grootte en tijd.
- Agent 1.40.0 nodig; werkt op Linux en TrueNAS SCALE, niet in de Home Assistant-add-on.

### Opgelost
- Handleiding en overzicht: blokkeren bij herhaling is **7 dagen** (als het IP de afgelopen 30 dagen al eens geblokkeerd was), niet permanent. Permanent blokkeren gaat met de hand.

## 1.39.0 – 2026-10-21
### Nieuw
- **Checks onder een server in groepen**, in Blox en L1nes: eerst **📊 Systeem** (CPU, RAM, disks), dan **🌐 Bereikbaarheid**, **⚙️ Services**, **📦 Containers**, **🖥️ Commando's**, **📜 Logbestanden**, **🔒 Certificaten** en **🏠 Home Assistant**.
- Elk groepskopje toont het aantal checks en wat aandacht vraagt (🔴 🟠 ⚪, of ✅). Klik erop om de groep in of uit te klappen; dat wordt per server en per apparaat onthouden.
- Binnen een groep staan problemen bovenaan, daarna op naam. Bij Systeem eerst CPU, dan RAM, dan de disks op volgorde van mount.
- Servers met minder dan 5 checks krijgen geen kopjes, wel dezelfde volgorde.

## 1.38.0 – 2026-10-21
### Nieuw
- **Logins, sudo en su verhuizen naar de pagina 🛡️ IDS**, tabblad **🔑 Toegang**. De pagina Meldingen toont alleen nog storingen. Bestaande regels verhuizen bij de update automatisch mee.
- De pagina IDS heeft nu drie tabbladen: **⚠️ Incidenten**, **🔑 Toegang** en **⛔ Blokkades**, met tellers. Het laatste tabblad wordt per apparaat onthouden.
- **Toegang:** per dag, met filters (*SSH, Console, sudo, su, ❌ Mislukt*), een keuzelijst per server en sorteren op elke kolom. Per regel **🙈 Negeer** (gebruiker en/of IP-adres voortaan niet meer tonen) en **⛔ Blokkeer** (IP-adres).
- Knop **⛔ IP blokkeren** bovenaan de pagina IDS, om zelf een IP-adres te blokkeren.
- *Logins en sudo* staat in de instellingen nu onder **🛡️ IDS → 🔑 Toegang** (was *Alarmgedrag*), samen met de lijst *Negeren*. Het werkt ook als het IDS uit staat.
- De agent hoeft niet bijgewerkt te worden.

## 1.37.0 – 2026-10-21
### Nieuw
- **Popup per check:** klik op een check op het dashboard (Blox en L1nes) of op een melding. Je ziet de status en het bericht, de instellingen, de beschikbaarheid over 24 uur en 7 dagen, en de **laatste 5 statuswijzigingen**: van/naar, hoe lang, het bericht dat de wijziging veroorzaakte, en of er gemeld is (wanneer, via welke kanalen) of waarom niet. Met knoppen voor bevestigen, onderhoud, nu checken, grafiek en bewerken.
- Statuswijzigingen worden vanaf nu bewaard, ook als er geen melding uitging, net zo lang als de meldingen.
- **Sorteren op de pagina Meldingen:** klik in L1nes op een kolomkop (Tijd, Status, Server, Check, Bericht, Kanalen): oplopend ▲, aflopend ▼, en de derde klik zet *nieuwste eerst* terug. In Blox een keuzelijst *Sorteren op*. Er wordt binnen elke dag gesorteerd; de keuze wordt per apparaat onthouden.

### Opgelost
- Knoppen onderin een venster lopen op een smal scherm door naar een tweede regel in plaats van buiten beeld te vallen.

## 1.36.0 – 2026-10-21
### Nieuw
- **⇄ Omgekeerde status per check.** In het checkformulier kies je *Status als de check slaagt* (OK, warning of critical) en, bij omgekeerd, *Status als de check mislukt* (standaard OK). Zo krijg je een melding als iets reageert dat juist níet mag draaien of bereikbaar mag zijn, bijvoorbeeld SSH, telnet of een oude server.
- Kan bij ping, TCP-poort, HTTP(S), service, container, Podman, commando en Home Assistant. Een waarschuwing telt als "slaagt"; onbekend blijft onbekend.
- Op het dashboard staat een **⇄** achter de naam van een omgekeerde check, en de melding begint met *⇄ Reageert (dat mag niet)* of *⇄ Reageert niet, zoals bedoeld*.
- Omgekeerde ping-, poort- en HTTP-checks tellen niet mee voor *Bereikbaar*, de gezondheidsbalk en afhankelijke servers.
- Bestaande checks blijven gewoon normaal. De agent hoeft niet bijgewerkt te worden.

## 1.35.1 – 2026-10-21
### Opgelost
- Pagina IDS: bij een Home Assistant-add-on stond *nog geen IDS-rapport*, terwijl er daar nooit een komt. Nu staat er *niet beschikbaar in de Home Assistant-add-on*, en die server staat niet meer in de keuzelijst bij *IP blokkeren*.

## 1.35.0 – 2026-10-21
### Nieuw
- **🛡️ IDS (inbraakdetectie)**, een nieuwe pagina. De agents letten op:
  - nieuwe of gewijzigde gevoelige bestanden (sudoers, `authorized_keys`, passwd/shadow, crontabs, sshd-config, systemd-services …);
  - nieuwe gebruikers, een tweede root-account, nieuwe luisterende poorten, SUID-programma's en kernelmodules, en gewijzigde programmabestanden van pakketten;
  - programma's die vanuit `/tmp` of zonder bestand draaien, mogelijke reverse shells en verbindingen naar bekende malware-poorten;
  - brute force op SSH, een geslaagde inlog na brute force, root-logins en logins vanaf een nieuw IP-adres.
- **Instellingen → 🛡️ IDS**: aan/uit (standaard **uit**) en **leren / blokkeren**. Leren meldt alleen; blokkeren blokkeert ook echt. Overschakelen naar blokkeren vraagt je wachtwoord en komt in het logboek.
- Per server eerst een **basismeting** (standaard 24 uur); daarna alleen afwijkingen. Met **Dit is normaal** komt een melding niet meer terug.
- **Critical** gaat ook naar Pushover, ook tijdens stille uren; een rood bolletje op het tabblad IDS telt de open critical incidenten.
- **Blokkeren** op de aangevallen server, in een eigen nftables-tabel van de agent: automatisch bij brute force (24 uur, bij herhaling binnen 7 dagen permanent) of met **⛔ Blokkeer IP**. **🔓 Deblokkeren** op de pagina IDS. Je LAN, RzMon zelf en een eigen lijst worden nooit automatisch geblokkeerd.
- Op een server blokkeren altijd uitzetten met `IDS_BLOCK=0`. TrueNAS SCALE: alleen detecteren.
- Agent 1.35.0 of nieuwer nodig.

## 1.34.0 – 2026-10-21
### Nieuw
- **Voorinstellingen voor commando-checks**: 21 kant-en-klare checks in drie groepen. De groep die bij de server past, staat bovenaan.
  - **TrueNAS/ZFS:** pools gezond, pools onder 80% vol, scrub zonder fouten, TrueNAS-meldingen, apps.
  - **Proxmox:** quorum, opslag onder 85%, laatste back-uptaak, Ceph, updates.
  - **Linux:** mislukte services, herstart nodig, (beveiligings)updates, NTP, inodes, temperatuur, RAID, SMART, Docker.
- Bij elke voorinstelling staat of hij **root** nodig heeft; het formulier waarschuwt als commando's op die server alleen als nobody mogen.
- **＋ Aanbevolen checks** in de Agent Manager: RzMon herkent TrueNAS, Proxmox of Linux (en Debian- of RHEL-familie) en stelt een set checks voor met vinkjes. Bestaande checks worden overgeslagen.
- De voorinstellingen voor TrueNAS en Proxmox zijn nog niet op een echt systeem getest.

## 1.33.0 – 2026-10-21
### Nieuw
- **🔑 Logins en sudo op de pagina Meldingen** (via de agent): SSH- en console-logins, `sudo` (met het commando, als wie en in welke map) en `su`. Ook mislukte pogingen (fout wachtwoord, *niet in sudoers*, mislukte SSH-logins); die worden per server en IP-adres per uur opgeteld. Per regel staat wie, wat en op welke server.
- **Filterknoppen** op de pagina Meldingen: *Alles · ⚠️ Storingen · 🔑 Toegang*, en tellers 🔑 en ❌ in de dagkop.
- **Instellingen → Alarmgedrag:** aan/uit (standaard aan) en een lijst met gebruikers en IP-adressen om te negeren. Op een server zelf: `AUTH_EVENTS=0`.
- Wachtwoorden in sudo-commando's (bv. `mysql -p…`, `password=…`) worden al op de server gemaskeerd. Er gaat geen Pushover-, mail- of HA-bericht uit.

## 1.32.0 – 2026-10-21
### Nieuw
- **Meldingen per dag:** de pagina *Meldingen* groepeert alles per dag ("Vandaag", "Gisteren", "maandag 19 oktober"…), met tellers per dag (🔴 critical, 🟠 warning, ⚪ onbekend, ✅ hersteld, ✔ bevestigd). Vandaag staat open, de rest is dicht; **⊞ Alles openen** klapt alles in één keer open. Werkt in Blox en L1nes.
- De pagina toont nu **alle** meldingen van de bewaartermijn, in plaats van alleen de laatste 50.
- **Instelling *Meldingen bewaren (dagen)*** (*Instellingen → Alarmgedrag*). Zolang je die niet aanpast, blijft het zoals het was: 3× de historie (standaard 90 dagen).

## 1.31.1 – 2026-10-21
### Nieuw
- **[FEATURES.md](FEATURES.md)**: een overzicht van alle features van RzMon, de agent, TrueNAS en de Home Assistant-add-on, met verwijzingen naar de handleiding. Gelinkt vanuit de README.

## 1.31.0 – 2026-10-21
### Nieuw
- **Commando-check** (*＋ Check → Commando*): de agent voert een commando uit en RzMon vergelijkt de uitvoer met het antwoord dat je verwacht (*is gelijk aan*, *bevat*, *regex* of *exitcode 0*). Klopt het niet, dan critical of warning (naar keuze); met time-out. Op het dashboard zie je wat er terugkwam. Bijvoorbeeld `zpool status -x` → `all pools are healthy`.
- **Veilig aanzetten:** standaard voeren agents geen commando's uit. Aanzetten onder *Instellingen → ⬆ Agents bijwerken* (als `nobody` of als root), en per server anders in de Agent Manager. Ruimer maken vraagt om je wachtwoord; wijzigingen staan in een **logboek**. Op de server zelf blokkeert `COMMANDS=0` (of `COMMANDS=nobody`) het altijd.
- **TrueNAS SCALE met één commando:** nieuw tabblad *🗄️ TrueNAS SCALE* in de Agent Manager. De installer zet de agent op je pool, maakt de cronjob via de TrueNAS-API aan (uitvoer verborgen) en stuurt een testrapport. Een handmatige installatie wordt overgenomen. **Bijwerken met de knop** werkt nu ook op TrueNAS.

### Opgelost
- Opnieuw installeren van de agent bewaart nu eigen regels in de instellingen (bv. `COMMANDS`, `LOG_DIRS`, `REMOTE_UPDATE`).

## 1.30.0 – 2026-10-21
### Nieuw
- **De lay-out (Blox/L1nes, Small/Large) geldt nu ook voor Instellingen en Meldingen.**
  - **Instellingen in L1nes:** elk onderdeel als rij over de volle breedte, in groepen (*Meldingen*, *Monitoring*, *Systeem*, *Toegang*), met achter de titel een korte samenvatting van hoe het staat. Klik om te openen; open rijen worden onthouden. Small = standaard dicht, Large = standaard open, plus **⊞ Alles openen**.
  - De knop **💾 Instellingen opslaan** blijft in L1nes onderin in beeld, met *● niet opgeslagen wijzigingen*.
  - **Meldingen in L1nes:** een tabel met één regel per melding (tijd, status, server, check, bericht, kanalen). Small kort het bericht in (klik voor alles), Large toont het helemaal.
  - In Blox blijft alles zoals het was.

## 1.29.0 – 2026-10-21
### Nieuw
- **RzMon bijwerken vanuit de webinterface** (*Instellingen → ⬆ RzMon bijwerken*):
  - RzMon kijkt één keer per dag op GitHub of er een nieuwe versie is (of direct met **🔍 Nu controleren**), en laat zien **wat er nieuw is**;
  - bovenin staat voor beheerders een label **⬆ 1.x.y** als er een update is;
  - **⬆ Nu bijwerken**: de hulpdienst op de server maakt eventueel eerst een back-up, haalt de nieuwe versie op en voert het installatiescript uit, net als het commando met `install-rocky.sh`. Je ziet de voortgang stap voor stap; daarna laadt de pagina de nieuwe versie;
  - schakelaar **Eerst een back-up maken** (standaard aan), ook voor automatisch bijwerken;
  - **Automatisch bijwerken**: elke dag op een tijdstip naar keuze (standaard 04:00), als er een nieuwe versie is.
- Werkt bij installaties met `install-rocky.sh` (Rocky/RHEL/AlmaLinux 10). **Eenmalig**: voer na deze update het installatiecommando nog één keer uit; dat zet de hulpdienst voor bijwerken klaar.
- Veilig: de webpagina zet alleen een verzoek klaar. De hulpdienst voert altijd dezelfde vaste stappen uit en weigert als de RzMon-map ontgrendeld is of bestanden niet van root zijn.

## 1.28.0 – 2026-10-21
### Nieuw
- **🌙 Stille uren** (*Instellingen → 🔔 Meldingen*): periodes waarin geen meldingen uitgaan, ook geen critical.
  - Eén of meer vensters met een tijd *van* en *tot* en de dagen, bijvoorbeeld door de week 23:00–07:00 en in het weekend 00:00–10:00. Over middernacht mag.
  - Na afloop komt er **één bericht** met alle problemen die dan nog open zijn, van alle servers samen (*"🌅 Na de stille uren: 2 problemen open"*). Wat 's nachts vanzelf herstelde, wordt niet gemeld.
  - **Per check**: algemeen, eigen vensters, of *nooit stil* (altijd melden). Zichtbaar als 🌙 of 🌙✕ op het dashboard.
  - In de gezondheidsbalk staat **🌙 Stil tot …** zolang de stille uren lopen; ook in MQTT en de REST API (`health.quiet_until`).

### Opgelost
- Gezondheidsbalk: "probleemen" is nu "problemen", en onbereikbare servers lezen beter (*onbereikbaar: WebServer01 (agent stil)*).

## 1.27.1 – 2026-10-20
### Opgelost
- **Agent op TrueNAS SCALE stuurde geen rapporten.** De agent gaf het rapport als lange tekst aan `curl` mee; op TrueNAS werd `curl` dan stil afgebroken. Het rapport gaat nu via een tijdelijk bestand, op alle systemen.
- Mislukt het versturen zonder antwoord, dan zegt de verbindingstest dat nu duidelijk, met de exitcode en de grootte van het rapport, in plaats van *"HTTP )"*.

### Nieuw
- Handleiding §8.9: de agent op **TrueNAS** installeren.

## 1.27.0 – 2026-10-20
### Nieuw
- **Gezondheidsbalk** bovenaan het dashboard, in plaats van de losse tellers:
  - de algehele status: **🟢 Alles in orde**, **🟠 Aandacht nodig** (alleen warnings, of problemen die bevestigd zijn of in onderhoud staan) of **🔴 Storing** (een onbevestigde critical of een onbereikbare server);
  - een **score van 0–100%**: een critical telt zwaarder dan een warning, een onbereikbare server het zwaarst;
  - **klikbare tellers** (● OK ● W ● C ● ? 🔧): klik en je ziet alleen die checks. Klik op de status zelf voor *Alleen problemen*;
  - een inforegel met servers bereikbaar, checks OK, open problemen en het **langst openstaande probleem**, de beschikbaarheid over 24 uur, en flappende checks en onderhoud. Klik op een servernaam en RzMon springt ernaartoe.
- **Home Assistant (MQTT):** nieuwe sensoren *Algehele status* en *Gezondheid* (%) op het RzMon-apparaat.
- **REST API:** `/v1.php/status` geeft nu ook `health` met de toestand, de score en de tellers.

## 1.26.0 – 2026-10-20
### Nieuw
- **Anti-flapping** (*Instellingen → 🔔 Meldingen*, standaard aan). Wisselt een check **4× binnen 30 minuten** van status, dan:
  - komt er één melding *"🔀 FLAPPEND: … staat te flappen"*;
  - daarna per status (warning, critical, onbekend, hersteld) **hooguit één melding**; verdere wisselingen en herinneringen blijven stil;
  - is de status **15 minuten** niet veranderd, dan volgt één melding *"STABIEL"* met de status van dat moment, en werkt alles weer normaal.
- De drie getallen zijn algemeen in te stellen en per check te overschrijven (eigen meldregels).
- Op het dashboard staat **🔀 flapt** bij een check die flapt.

## 1.25.0 – 2026-10-20
### Nieuw
- **Meer controle over meldingen** (*Instellingen → 🔔 Meldingen*). Per status (warning, critical, onbekend, hersteld) stel je in:
  - **wanneer:** nooit, direct, of pas na X minuten onafgebroken in die status. Hersteld: pas als het X minuten goed blijft; gaat het in die tijd weer mis, dan blijft het één storing;
  - **herhalen:** elke Y minuten;
  - **kanalen:** Pushover, e-mail en/of Home Assistant.
- **Schakelaar "Melden bij ONBEKEND".**
- **Per check te overschrijven:** in het checkformulier onder *🔔 Meldingen*. Checks met eigen regels hebben een 🔔 op het dashboard.
- **Bundelen per server:** gaan op één server binnen 120 seconden (instelbaar, 0 = uit) meerdere checks mis of herstellen ze, dan komt er één bericht met alles erin. Ook herinneringen worden gebundeld. Home Assistant krijgt wel een bericht per check.

### Gewijzigd
- "Alarm na N fouten", "Ook melden bij WARNING", "Herhaal melding" en "Melden bij herstel" zijn vervangen door de tabel. Je oude instellingen zijn omgezet, zodat er niets verandert.
- De webhook-keuze "alleen critical en herstel" is nu de kolom *Home Assistant* in de tabel.

## 1.24.0 – 2026-10-20
### Nieuw
- **Home Assistant-checks** via de add-on (**＋ Check → Home Assistant**):
  - **Laatste back-up:** dagen sinds de laatste geslaagde automatische back-up (standaard warning 2, critical 7). Ook WARNING als de laatste poging mislukt is. Het bericht noemt ook de volgende geplande back-up. Gebruikt de back-upsensoren van HA 2025.2+; er zijn geen extra rechten nodig.
  - **Integraties:** het aantal integraties dat niet geladen is (fout bij instellen, opnieuw proberen, uitladen mislukt), met de namen in het bericht.
  - **Systeemstatus:** de reparaties van de Supervisor. *Niet gezond* = CRITICAL; *niet ondersteund* of een melding (bv. weinig vrije ruimte) = WARNING.
- **Servicekiezer voor Home Assistant:** bij *＋ Check → Service* kies je Core, Supervisor, add-ons en Updates uit een lijst, met hun status.
- Werk de **add-on** bij via de add-on winkel.

## 1.23.0 – 2026-10-20
### Nieuw
- **Eén check per container.** De status (draait, healthcheck, herstarts) wordt altijd bewaakt; vink daarnaast CPU, RAM en/of disk aan, elk met eigen drempels.
  - Op het dashboard staat per container **één rij**, bijvoorbeeld *adguardhome draait (gezond) · CPU 2% · RAM 1,6 GB (43,8%) · disk 25,6 kB*.
  - Gaat iets over een drempel, dan staat dat vooraan, bijvoorbeeld *⚠ RAM 87% (warning vanaf 85%)*.
  - Eén melding per container, met de slechtste status van de onderdelen.
- **Containers-venster (📦):** per container één knop: **＋ Bewaken**, of **✓ status, CPU, RAM ✎** om aan te passen. "＋ Alle containers bewaken" voegt voor elke container de status toe.
- **Grafiek (📈):** bij een container staat per bewaakte meting een eigen grafiek onder elkaar, elk met zijn drempels.

### Gewijzigd
- Bestaande losse container-checks (status, CPU, RAM en disk apart) worden bij de update **automatisch per container samengevoegd**. De drempels, de geschiedenis van alle metingen, onderhoud en meldingen gaan mee. Een eigen naam blijft behouden.

## 1.22.0 – 2026-10-20
### Nieuw
- **Wachtwoord vergeten** op het loginscherm.
  - Vul je gebruikersnaam of e-mailadres in. Je krijgt een mail met een link die **30 minuten** geldig is en **één keer** werkt.
  - Heeft het account tweestapsverificatie, dan is bij het herstellen ook de code uit de app nodig.
  - Daarna worden alle onthouden apparaten en open sessies van dat account uitgelogd, en volgt een bevestigingsmail.
  - De melding is altijd hetzelfde, zodat niemand kan uitzoeken welke accounts er bestaan.
  - Hooguit 3 links per account en 10 aanvragen per IP-adres per uur. In de database staat alleen een hash van de link.
- **E-mailadres per gebruiker** (*Instellingen → Gebruikers*: ✉, of bij het aanmaken). Iedereen kan zijn eigen adres instellen.
- **Logboek** van herstelaanvragen voor beheerders (*Gebruikers → Logboek bekijken*). Bij *Gebruikers* staat ook of "wachtwoord vergeten" werkt (SMTP en Basis-URL).
- Nieuw commando: `install.php --reset-password <gebruiker>`.

### Gewijzigd
- Wijzig je je wachtwoord, of zet een beheerder een nieuw wachtwoord, dan worden de **andere open sessies** van dat account uitgelogd.

## 1.21.2 – 2026-10-20
### Gewijzigd
- De knop **Agent installeren** heet nu **Agent Manager**: daar installeer, test en werk je de agent bij. Ook de teksten in de agent en de handleiding zijn aangepast.

## 1.21.1 – 2026-10-20
### Opgelost
- **Podman 5:** bij de meeste containers stonden CPU en RAM op "–"; alleen de eerste container had cijfers.
  - Oorzaak: podman 5 heeft de velden `NetInput` en `NetOutput` weggehaald, en podman stopte daarna met de rest.
  - De agent gebruikt nu `NetIO`, dat in podman 4 én 5 bestaat.
  - Werk de agents bij met **⬆ Bijwerken**.

## 1.21.0 – 2026-10-20
### Nieuw
- **L1nes: ook de checks als rijen.** Klap je een server uit, dan staan zijn checks als rijen eronder, net als de servers. Ze volgen de keuze Small/Large:
  - **Small:** één regel per check met naam, type, waarde (balk met warning- en critical-streepjes voor percentages), bericht en tijd.
  - **Large:** kolomkoppen en de minigrafiek van 24 uur per check.
- De checks staan op volgorde van **problemen bovenaan**: kritiek, waarschuwing, onbekend, OK, onderhoud, uit.
- De knoppen per check verschijnen pas als je met de muis over de rij gaat. Op een telefoon of tablet staan ze er altijd.
- Blox is niet veranderd.

## 1.20.4 – 2026-10-20
### Opgelost
- Agent-servers toonden alle checks van de agent als ONBEKEND ("Agent meldt geen CPU-gegevens", "geen mount /"), terwijl de agent wel rapporteerde.
  - Oorzaak: de servicelijst uit 1.20.0 nam ook systemd-namen met een backslash mee, zoals `systemd-fsck@dev-disk-by\x2duuid-…`. Die maakten het rapport onleesbaar.
  - De agent slaat zulke namen nu over.
  - RzMon herstelt rapporten van agents 1.20.0–1.20.3 zelf. Bijwerken kan dus gewoon met de knop.
- Een onleesbaar rapport wist niet langer alle gegevens van een server. RzMon weigert het en zet de reden in het logboek van *Agent installeren*.

## 1.20.3 – 2026-10-20
### Opgelost
- Podman: een gebruiker zonder containers gaf toch een fout, bijvoorbeeld "rehman: podman reageert niet". Dat gebeurde als die gebruiker podman ooit had gestart. De agent kijkt nu alleen naar gebruikers die echt containers hebben.

## 1.20.2 – 2026-10-20
### Opgelost
- Containers-venster: de knoppen onder *Bewaken* vielen rechts buiten beeld. Ze staan nu netjes 2×2 in de cel.
- Podman-fout van een gebruiker (rootless) toont nu de echte oorzaak in plaats van alleen "podman reageert niet".
  - Is podman ná de agent geïnstalleerd, dan is de home-map alleen-lezen voor de agent. De melding zegt dan: los op met `sudo rzmon-agent-setup --update`.
  - Duurt het te lang, dan staat er "geen antwoord binnen 60 s".

## 1.20.1 – 2026-10-20
### Opgelost
- Bijwerken met `install-rocky.sh` stopte bij stap 5 met "Your local changes … would be overwritten by merge". Het script zet zelf de bestandsrechten, en git zag dat als een wijziging. Git negeert bestandsrechten nu in de RzMon-map.

## 1.20.0 – 2026-10-20
### Nieuw
- **Services kies je in RzMon.** Bij *＋ Check → Service* op een agent-server staat een lijst van de services op die server (die aan staan bij het opstarten, of draaien), met hun status. Typ om te zoeken en klik om te kiezen.
- De agent **bewaakt vanzelf** elke service waarvoor een check bestaat, inclusief de diepere controle. Een nieuwe check is binnen ongeveer 2 minuten actief: RzMon geeft bij elk rapport een kenmerk van de instellingen mee, en bij een wijziging haalt de agent ze meteen opnieuw op.
- Het installatiescript vraagt niet meer naar services.
- De agent vraagt alleen de status van services op. RzMon kan op de server niets starten of stoppen.

### Gewijzigd
- `SERVICES` in `/etc/default/rzmon-agent` en `--services` zijn **verouderd**. Een bestaande lijst blijft werken: die services worden óók gemeld.
- De melding "Agent meldt service X niet" legt nu uit wat je moet doen: de agent bijwerken, of even wachten.

### Let op
- Werk je agents bij met **⬆ Bijwerken** (of één keer `sudo rzmon-agent-setup --update` voor agents van vóór 1.19.0). Pas daarna verschijnt de lijst met services.

## 1.19.0 – 2026-10-20
### Nieuw
- **Agents bijwerken vanuit RzMon.** De eerste installatie gaat nog steeds met het commando op de server; daarna kan het zo:
  - per server met **⬆ Bijwerken** in het venster *Agent installeren*;
  - allemaal tegelijk onder *Instellingen → ⬆ Agents bijwerken*;
  - of **automatisch**, met een schakelaar op dezelfde kaart (standaard uit, hooguit één poging per 6 uur).
- Hoe het werkt: de agent krijgt de opdracht bij zijn volgende rapport en haalt de nieuwe versie op bij je eigen RzMon. Hij controleert eerst:
  - het SHA-256-controlegetal;
  - of het een geldig script is;
  - of het de juiste versie is.
  Pas dan installeert hij. Zijn instellingen blijven staan. Onder systemd loopt de update als aparte taak (`systemd-run`).
- RzMon toont per server de uitkomst: "bijgewerkt van … naar …", of waarom het niet lukte. Bijvoorbeeld een oude agent, `REMOTE_UPDATE=0`, of een Home Assistant-add-on (die werk je bij via de add-on winkel).
- `REMOTE_UPDATE=0` in `/etc/default/rzmon-agent` sluit een server uit; die werk je dan alleen met de hand bij.

### Let op
- Agents van vóór 1.19.0 kennen de knop nog niet: werk ze **één keer** bij met `sudo rzmon-agent-setup --update`.

## 1.18.0 – 2026-10-20
### Nieuw
- **Back-up en herstel** onder *Instellingen → 💾 Back-up* (alleen beheerders).
  - Automatisch elke dag, week of maand, op een tijd die je kiest (standaard 03:15). Je stelt in hoeveel automatische back-ups bewaard blijven (standaard 7).
  - Eén zip met de database, `config.php` en `info.txt`. Met een zip-wachtwoord worden database en config versleuteld met **AES-256**.
  - Schakelaar *Meethistorie meenemen*: uit maakt de zip veel kleiner.
  - **Nu back-up maken**, **downloaden** (na bevestigen met je wachtwoord, daarna 10 minuten vrij), **uploaden** (in stukjes, tot 2 GB), **terugzetten** en verwijderen.
  - Terugzetten maakt eerst een veiligheidsback-up en is alles of niets: mislukt het halverwege, dan wordt de oude toestand teruggezet. Gewijzigde of afgebroken back-ups worden geweigerd.
  - Een mislukte automatische back-up geeft een melding via Pushover, e-mail en Home Assistant. RzMon probeert het elk uur opnieuw en meldt het als het weer lukt.
- In de terminal: `install.php --backup`, `--list-backups` en `--restore <zip>`. Met `--with-config` (of op een nieuwe server zonder `config.php`) worden ook `config.php`, de database en de databasegebruiker teruggezet.
- `install-helper.sh` maakt de back-upmap `/var/lib/rzmon/backups` aan, ook zonder systemd. `install-rocky.sh` installeert nu ook `php-zip`.

### Opgelost
- De instellingenpagina bleef leeg als je RzMon direct op `#settings` opende (bijvoorbeeld na herladen).

## 1.17.0 – 2026-10-20
### Nieuw
- **Databasewachtwoord vernieuwen:** knop onder *Instellingen → Database* (alleen beheerders).
  - RzMon maakt een nieuw wachtwoord van 32 tekens, zet het in MariaDB én in `config.php`, en test meteen of alles nog werkt. Lukt dat niet, dan wordt alles teruggezet.
  - Het nieuwe wachtwoord wordt **één keer** getoond, met een kopieerknop. `/root/rzmon-install.txt` wordt ook bijgewerkt.
  - Bevestigen met je eigen wachtwoord. Ben je op het LAN automatisch beheerder, dan typ je `VERNIEUWEN`.
- **Hulpdienst** (`install/install-helper.sh`): omdat de RzMon-map vergrendeld is, doet een kleine systemd-dienst het wisselen als root. Hij doet niets anders. `install-rocky.sh` installeert hem automatisch.
  - Is de hulpdienst er (nog) niet, dan toont de knop een venster met de commando's om te kopiëren: de hulpdienst installeren, of het wachtwoord meteen in de terminal vernieuwen.
- Nieuw commando: `sudo php install/install.php --rotate-db-password`.

### Opgelost
- Een gewijzigde `config.php` wordt nu meteen gelezen, ook als PHP's opcache bestanden niet opnieuw controleert.

## 1.16.0 – 2026-10-20
### Nieuw
- **Logbestanden doorzoeken via de agent:** nieuw checktype *Logbestand (zoeken)*. Je stelt in RzMon in welke bron en waarnaar gezocht wordt.
  - Bronnen: een logbestand, het systemlog (`journal`), het log van één service (`journal:sshd`) of kernelmeldingen (`kernel`).
  - RzMon telt de treffers in de laatste periode (standaard **15 minuten**, in te stellen onder *Instellingen → Alarmgedrag*), met drempels voor warning en critical.
  - Het bericht toont de **IP-adressen** die het vaakst voorkomen en de laatste regel. In de grafiek (📈) staan de **laatste 5 gevonden regels**.
- **Voorinstellingen:**
  - beveiliging: mislukte SSH-logins, mislukte sudo/su, firewall-blokkades, Fail2ban-bans, mislukte mail-logins;
  - hardware en systeem: diskfouten, hardwarefouten (CPU/geheugen/PCIe/temperatuur), SMART, geheugen vol (OOM), gecrashte programma's, mislukte services;
  - webserver en database: webserver 5xx, 404-scans en foutlog, databasefouten;
  - of zelf instellen.
- De agent leest alleen de **nieuwe regels**, onthoudt waar hij gebleven was, kan omgaan met logrotatie en telt half geschreven regels pas als ze af zijn.
- **Veilig:** de agent leest alleen logbestanden binnen `LOG_DIRS` (standaard `/var/log`), ook niet via een link naar een bestand daarbuiten. Hij stuurt alleen gevonden regels (maximaal 5, ingekort), nooit hele bestanden.

## 1.15.0 – 2026-10-20
### Nieuw
- **Onthoud mij op dit apparaat:** met de schakelaar op het inlogscherm blijf je op dat apparaat ingelogd, ook na het sluiten van de browser, zonder wachtwoord en zonder 2FA.
  - Het apparaat wordt vergeten na **30 dagen** niet gebruiken; elk gebruik telt opnieuw.
  - De sleutel is gekoppeld aan de browser op dat apparaat. In de database staat alleen een hash.
  - De sleutel wordt bij elk gebruik vervangen; een oude, mogelijk gestolen sleutel maakt het apparaat ongeldig.
- **Onthouden apparaten** (📱 bij elke gebruiker): je ziet per apparaat de naam (bv. "Safari op iPhone"), wanneer het laatst gebruikt is en vanaf welk IP. Je kunt per apparaat of alles in één keer **vergeten**, en een vergeten apparaat wordt ook direct uitgelogd. Beheerders kunnen dit voor iedereen.
- Onthouden apparaten worden ook vergeten bij uitloggen, bij een wachtwoordwijziging (behalve het apparaat waarop je het wijzigt) en als een beheerder je wachtwoord reset.
- Op het lokale netwerk gaat een onthouden apparaat vóór "alleen lezen": je komt binnen met je eigen account.
### Opgelost
- Een toelichting naast een schakelaar werd soms als tweede schakelaar getoond (bv. bij *Certificaat controleren*).

## 1.14.0 – 2026-10-20
### Nieuw
- **Drie rollen per account** (*Instellingen → Gebruikers*):
  - 🔑 **Beheerder**: alles;
  - 👤 **Gebruiker**: storingen afhandelen (bevestigen, nu checken, onderhoud, poortscan), maar niets inrichten;
  - 👁 **Alleen lezen**: kijken.
  De rol kies je bij het aanmaken, en wijzigen kan met de keuzelijst bij elke gebruiker. Knoppen die niet mogen, verdwijnen, en de server controleert de rechten ook zelf (API, Setup). Agent-tokens zien alleen beheerders.
- **Inloggen op het lokale netwerk:** zonder wachtwoord kom je binnen als **alleen lezen**. Met **Inloggen** rechtsboven krijg je de rechten van je eigen account. Onder *Toegang* zet een beheerder desgewenst **"Op het lokale netwerk als beheerder inloggen"** aan. Van buitenaf is altijd een gebruikersnaam en wachtwoord nodig.
- Beveiligingen: er blijft altijd minimaal één beheerder, en je kunt je eigen rol niet wijzigen.
### Let op
- Bestaande accounts worden **beheerder**. Stond *Geen login nodig op het lokale netwerk* aan, dan kom je daar na de update binnen als **alleen lezen**, tot je onder *Toegang* "als beheerder inloggen" aanzet of rechtsboven inlogt.

## 1.13.0 – 2026-10-20
### Nieuw
- **Automatisch onderhoud na herhaalde meldingen:** blijft een check fout en zijn er 3 meldingen verstuurd, dan gaat **die check** automatisch in onderhoud. Je krijgt daar één melding over (🔧 ONDERHOUD), daarna is het stil.
  - De andere checks van de server blijven gewoon melden.
  - Het onderhoud stopt vanzelf zodra de check weer OK is, met een herstelmelding ("Automatisch onderhoud beëindigd"). Handmatig beëindigen kan ook (🔧).
  - Aan/uit en het aantal meldingen stel je in onder *Instellingen → Alarmgedrag*; standaard aan, na 3.
  - Alleen meldingen die echt zijn aangekomen tellen mee.
- Dashboard: "🔧 auto, tot opgelost" bij zo'n check. REST API: `maintenance_auto`. Webhook: event `maintenance`.

## 1.12.0 – 2026-10-20
### Nieuw
- **Eigen poorten en sockets per service:** bij een service-check (diepere controle) kun je zelf de poort(en) of socket(s) van de service opgeven, bijvoorbeeld `8080`, `127.0.0.1:9000` of `/run/php-fpm/www.sock`. Handig als de agent ze zelf niet vindt (container, socket, andere procesnaam).
  - Jouw poorten worden altijd getest, naast wat de agent zelf vindt, en de test van de dienst (HTTP, SSH, Redis…) gebruikt jouw poort.
  - Bij een socket kijkt de agent of hij bestaat en verbindingen aanneemt.
  - Werkt niet → CRITICAL.
- `rzmon-agent-setup --test` laat ook de sockets zien.

## 1.11.0 – 2026-10-20
### Nieuw
- **Diepere controle van services via de agent:** een service-check kijkt nu niet alleen of de service "active" is, maar ook of hij echt werkt:
  - draait het hoofdproces echt (niet alleen "active");
  - **luistert hij op zijn poorten** en nemen die verbindingen aan (getest op de server zelf);
  - **antwoordt hij**: een test die bij de dienst past, voor web (nginx, httpd, apache, caddy…: HTTP-antwoord), MariaDB/MySQL (ping), PostgreSQL (`pg_isready`), Redis (`PING`), SSH (begroeting), Postfix/Exim (SMTP 220), Dovecot (IMAP), DNS (named, unbound, Pi-hole, dnsmasq…: DNS-vraag) en chrony/timesyncd (tijd gesynchroniseerd);
  - **herstarts**: vanaf 3 automatische herstarts per uur een WARNING (crash-lus);
  - **fouten in het log** van het laatste kwartier: WARNING, met de laatste foutregel.
  Je bestaande service-checks doen dit vanzelf (nadat de agent is bijgewerkt). Per check kun je het uitzetten met *Diepere controle*.
- **Extra test per service-check:** "deze URL moet antwoorden (en deze tekst bevatten)" of "deze poort moet antwoorden". Je stelt die in bij de check in RzMon; de agent voert hem uit op de server zelf, dus `localhost` werkt ook.
- **Eigen test-scripts** op de server: `/etc/rzmon-agent/tests/<service>`, met exitcode 0 = OK, 1 = WARNING, 2 = CRITICAL (zoals Nagios-plugins). Alleen scripts van root die niet door anderen te wijzigen zijn, worden uitgevoerd. RzMon zelf kan nooit commando's op je servers laten uitvoeren.
- `rzmon-agent-setup --test` toont per service de poorten en het resultaat van de test.

## 1.10.0 – 2026-10-20
### Nieuw
- **Twee lay-outs voor het dashboard**, te kiezen onder *Instellingen → Weergave*:
  - **Blox**: de bekende tegels per server (standaard);
  - **L1nes**: elke server als balk over de volle breedte, onder elkaar als een tabel. Kies **Small** (één compacte regel: naam, adres, status en tellers) of **Large** (kolommen met status en probleem-checks, CPU, RAM, disk, bereikbaarheid en laatste check).
- Klikken, uitklappen, zoeken, *Alleen problemen* en *Alles openen* werken in beide lay-outs hetzelfde.
- De keuze geldt per apparaat en blijft bewaard, dus je kunt op je telefoon iets anders kiezen dan op je pc. Op een smal scherm vallen kolommen weg, zodat je niet opzij hoeft te scrollen.

## 1.9.0 – 2026-10-20
### Nieuw
- **Podman-bewaking via de agent:** staat podman op een server, dan meldt de agent vanzelf de algehele status, en per container:
  - status, health en herstarts;
  - CPU en geheugen (ook de limiet);
  - netwerk, disk-I/O en aantal processen;
  - diskgebruik (schrijflaag + volumes).
  Pods en de opslag van images en volumes worden ook gemeld. Containers van root en, automatisch, rootless containers van gewone gebruikers (`gebruiker/naam`). Zonder podman verandert er niets. Uitzetten kan met `PODMAN=0`.
- **Nieuwe checktypes:**
  - *Podman (algemeen)*: WARNING bij gestopte containers met een foutcode, ongezonde of gepauzeerde containers, en pods die niet in orde zijn.
  - *Container (Podman)*: meet de **status** (CRITICAL bij gestopt of ongezond; WARNING bij gepauzeerd of na een automatische herstart in het afgelopen kwartier), **CPU**, **RAM** of **disk**, elk met eigen drempels.
- **📦 Containers** bij een server: een overzicht van alle containers met status, CPU, RAM en disk. Met één klik voeg je per container een check toe (Status, CPU, RAM of Disk), of status-checks voor alle containers tegelijk.
- MQTT, webhook en REST API gebruiken de juiste eenheid per container-meting (%, MB).
### Gewijzigd
- Op servers met podman zet `rzmon-agent-setup` in de systemd-service `NoNewPrivileges` en `ProtectHome` uit. Dat is nodig om rootless containers van gebruikers uit te lezen. Wordt toegepast bij `--update`.

## 1.8.0 – 2026-10-20
### Nieuw
- **Certificaten via de agent:** de agent leest vervaldatums uit en stuurt ze naar RzMon. Dat kan op twee manieren:
  - van **certificaatbestanden**, zoals standaard alle Let's Encrypt-certificaten in `/etc/letsencrypt/live/*/cert.pem`;
  - van **host:poort**, ook voor diensten die alleen lokaal luisteren en voor servers op internet, gezien vanaf die server.
  Instellen gaat met `CERTS` in `/etc/default/rzmon-agent`, of met `sudo rzmon-agent-setup --certs "…"`. Bij het installeren stelt het script zelf voor wat het vindt. Certificaten worden elk uur opnieuw uitgelezen.
- **Kiezen in RzMon:** bij een check *SSL-certificaat* op een agent-server kies je uit de certificaten die de agent meldt. Zo'n check krijgt dezelfde dagen-drempels en meldingen, met "via agent" in het bericht.
- **Home Assistant-add-on:** nieuwe optie `certs`, standaard `/ssl/fullchain.pem` (van de Let's Encrypt- of DuckDNS-add-on). Adressen zoals `mijndomein.duckdns.org:443` mogen ook.
- **Poortscan:** bij poorten waarop meestal TLS draait (443, 8443, 8006, 993…) staat **＋ Cert**, dat met één klik een certificaatcheck toevoegt. Bij adressen in je eigen netwerk staat de certificaatcontrole dan uit, i.v.m. self-signed certificaten.
- `rzmon-agent-setup --test` laat per certificaat zien hoeveel dagen het nog geldig is, of wat er mis is.

## 1.7.0 – 2026-10-20
### Nieuw
- **Gebruikers en tweestapsverificatie (2FA):** onder *Instellingen → Gebruikers* maak je accounts aan, met of zonder 2FA. Je kunt 2FA instellen met een QR-code voor elke authenticator-app, 2FA resetten, wachtwoorden opnieuw instellen en gebruikers verwijderen. Bij inloggen met 2FA volgt na het wachtwoord een 6-cijferige code. Een code werkt maar één keer, en na 5 foute codes begin je opnieuw. Telefoon kwijt? `php install/install.php --reset-2fa <naam>` zet 2FA uit.
- **E-mailmeldingen:** SMTP met STARTTLS, SSL/TLS of zonder versleuteling, en met inloggen. Je kiest *altijd* (naast Pushover) of *alleen als Pushover uit staat of mislukt*. De mail bevat ook de bevestig-link. Er is een testknop, en in het meldingenlog staat 📧.
- **MQTT discovery voor Home Assistant:** elke server verschijnt in Home Assistant als apparaat. Je krijgt sensoren voor de status en de meetwaarde van elke check, een probleem-sensor per server, en een overzicht van RzMon zelf. Server, poort, TLS, gebruikersnaam en wachtwoord stel je in onder Instellingen. Verwijderde checks verdwijnen ook uit HA. Stopt RzMon, dan worden de sensoren na 10 minuten *niet beschikbaar*.
- **Checktype SSL-certificaat:** laat zien hoeveel dagen een certificaat nog geldig is, met eigen drempels (standaard warning onder 21 dagen, critical onder 7). Werkt op elke TLS-poort. Per check kun je kiezen of het certificaat gecontroleerd wordt; uitzetten is handig bij self-signed certificaten, zoals van Proxmox of een NAS. Die keuze geldt nu ook voor HTTP(S)-checks.
- **Beschikbaarheid per server** over 24 uur, 7 dagen en 30 dagen, op de tegel en in de REST API (`uptime`).
- **Minigrafieken** van de laatste 24 uur bij elke check. Klik erop voor de grote grafiek, die nu ook minimum, gemiddelde en maximum toont.
- **Diskvoorspelling:** RzMon berekent hoe snel een disk volloopt ("vol over ~12 dagen") en geeft een WARNING als dat binnen 7 dagen is. Dat aantal dagen stel je in.
### Opgelost
- De agent-log wordt nu elk uur opgeruimd, en niet alleen als er een agent geweigerd wordt.
- Opruimen van oude meetgegevens gebeurt nu ook als de cron-run van precies :00 is overgeslagen.

## 1.6.0 – 2026-10-19
### Nieuw
- **Versienummers:** `VERSION` en deze changelog. De versie staat in de menubalk, op de login- en setup-pagina, in de REST API (`/v1.php/status`, `api.php?action=ping`) en in de agent.
- **Agentversie per server:** RzMon laat zien welke agentversie elke server draait, en markeert verouderde agents met ⬆, met het commando om bij te werken.
- **Database bijwerken gaat automatisch:** na een update werkt RzMon de tabellen zelf bij, bij het openen van het dashboard of bij de eerstvolgende cron-run. *Setup → Tabellen bijwerken* is niet meer nodig.
- `rzmon-agent.sh --version` en `rzmon-agent-setup --version`.
- `install-rocky.sh` meldt bij een update van welke naar welke versie er is bijgewerkt.
- `tools/bump-version.sh` en `tools/check-version.sh`, plus een GitHub-controle die alle versienummers gelijk houdt.

## 1.5.0 – 2026-10-19
### Nieuw
- **Verbindingstest voor de agent:** `sudo rzmon-agent-setup --test` controleert stap voor stap de planning, het adres, DNS, het netwerk, of daar RzMon draait, het token en een proefrapport.
- **Live agentstatus** in *Agent installeren*: laatste rapport en IP, en geweigerde pogingen met reden.
### Opgelost
- Met de hand gestart las de agent zijn instellingen niet in.
- Het installatiescript sloeg cron over als `/etc/cron.d` al bestond zonder cron-dienst.

## 1.4.0 – 2026-10-19
### Nieuw
- **Agent installeren met één commando** (`agent/install-agent.sh`). RzMon biedt het script zelf aan. Het gebruikt een systemd-timer of cron, stelt services voor en heeft `--update` en `--uninstall`.
- **Dashboard met server-tegels:** algehele status per server; klik klapt uit naar de checks.

## 1.3.0 – 2026-10-19
### Nieuw
- **Storingen bevestigen:** in het dashboard, via een link in Pushover, of in de Pushover-app (Emergency). Daarna komen er geen meldingen meer tot herstel.

## 1.2.1 – 2026-10-19
### Opgelost
- `install-rocky.sh --repo ~/rzmon`: installeren vanaf een lokale clone, bijvoorbeeld van een privé-repository.

## 1.2.0 – 2026-10-19
### Nieuw
- **Onderhoudsmodus:** voor alles, per server of per check.
- **Afhankelijkheden:** één melding als een router of switch onbereikbaar is, in plaats van één per server erachter.
- **Home Assistant-webhook** als extra meldkanaal.

## 1.1.0 – 2026-10-19
### Nieuw
- **Rocky Linux 10:** installatiehandleiding en `install/install-rocky.sh`.
### Opgelost
- REST API achter Apache met PHP-FPM gaf altijd 401 (`CGIPassAuth`).
- REST API achter Nginx gaf bij `/v1.php/checks/3` de status terug.
- Na de database-setup zonder beheerder kwam je op een loginscherm zonder uitweg.
- Cron kon blijven crashen door een lockbestand van root.

## 1.0.0 – 2026-10-19
Eerste versie:
- **Checks:** ping, TCP, HTTP(S) met SSL-controle, diskruimte, services, CPU en RAM.
- **Meldingen** via Pushover.
- **Interface:** dashboard, grafieken, meldingenlog, setup-pagina en een REST API met API-sleutels.
- **Toegang en extra's:** login zonder wachtwoord op je LAN, poortscan.
- **Agents:** Linux-agent en Home Assistant-add-on.
