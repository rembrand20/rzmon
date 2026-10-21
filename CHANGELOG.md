# Changelog

Alle wijzigingen aan RzMon, per versie. Nieuwste bovenaan.
Versienummers volgen [Semantic Versioning](https://semver.org/lang/nl/):
`MAJOR.MINOR.PATCH`. MINOR gaat omhoog bij nieuwe functies, PATCH bij reparaties,
en MAJOR bij wijzigingen waarvoor je zelf iets moet aanpassen.

Het versienummer staat in [`VERSION`](VERSION). Verhogen gaat met `tools/bump-version.sh` (zie [CLAUDE.md](CLAUDE.md)).

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
