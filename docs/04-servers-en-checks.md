# Servers en checks

Dit hoofdstuk laat zien hoe je servers en apparaten toevoegt en wat je erop kunt bewaken. Je vindt hier alle checktypen
met hun standaarddrempels, de poortscan, onderhoud, afhankelijkheden, SNMP, heartbeats voor back-ups en cronjobs,
sjablonen, slimme drempels, zelf herstellen en de internetcheck. Wat alleen via de agent gaat, zoals containers, logs,
databases en commando-checks, staat in [Linux-agent](05-linux-agent.md).

## Drie soorten servers

Elke server in RzSense heeft een type. Dat bepaalt waar de gegevens vandaan komen.

| Type | Voor | Hoe RzSense meet |
|---|---|---|
| **Lokaal (deze server)** | De server waarop RzSense zelf draait | Leest disk, CPU, RAM, services en processen direct uit |
| **Remote met agent** | Linux-servers, Windows-servers en Home Assistant OS | De agent stuurt elke minuut zijn gegevens. Stopt hij met rapporteren, dan worden zijn checks na 5 minuten CRITICAL (*Agent stil sinds …*) |
| **Remote (alleen netwerk)** | Routers, switches, access points, NAS, printers, websites | Alleen checks die RzSense zelf over het netwerk doet: ping, poort, HTTP, certificaat, DNS, SNMP, MQTT, domein enzovoort |

Op een server van het type *Remote (alleen netwerk)* werken disk, CPU, RAM, service, uptime en proces niet. Die checks
worden dan *onbekend*. De agent installeer je zoals beschreven in [Linux-agent](05-linux-agent.md) of
[Windows-agent](06-windows-agent.md). Voor Home Assistant OS is er een add-on: zie [Home Assistant](07-home-assistant.md).

## Een server toevoegen

1. Open de pagina *Monitoring* en klik rechtsboven op **＋ Server**.
2. Vul in:
   - **Naam**, bijvoorbeeld "NAS" of "Webserver".
   - **Hostname / IP-adres**. Je kunt ook kiezen uit de apparaten die de netwerkscan vond.
   - **Type**: *Lokaal (deze server)*, *Remote met agent* of *Remote (alleen netwerk)*.
   - Eventueel **Soort apparaat** (voor de indeling van het dashboard), een **Groep**, **Stille uren** en
     **Afhankelijk van** (zie [Afhankelijkheden](#afhankelijkheden)).
   - Eventueel **📡 SNMP** (zie [SNMP](#snmp)) en **📋 Sjablonen** (zie [Sjablonen](#sjablonen)).
3. Laat **Direct een ping-check toevoegen** aan staan en klik op **Opslaan**.

Kies je *Remote met agent*, dan toont RzSense meteen het installatiecommando voor de agent.

Klik op een server om hem open te klappen. Daar staan de knoppen voor die server: **✔ Alles bevestigen**, **🔧 Onderhoud**,
**🌙 Nu stil**, **＋ Check**, **🔍 Poorten**, **📡 SNMP-checks**, **✎ Bewerken** en bij agent-servers de
**Agent Manager** en **📜 Logs**.

## Een check toevoegen

1. Klap de server open en klik op **＋ Check**.
2. Kies het **type** en vul het **doel** in (zie de tabel hieronder).
3. Pas zo nodig de **drempels** en het **interval** aan.
4. Klik op **🧪 Nu proberen** om te zien wat de check nu zou zeggen (status, waarde en bericht). Er wordt dan nog niets
   bewaard of gemeld. Op een agent-server gebruikt RzSense het laatste rapport van de agent; iets nieuws (een service, log
   of commando) ziet de agent pas bij zijn volgende rapport.
5. Klik op **Opslaan**. De check wordt meteen uitgevoerd.

Goed om te weten:
- **Interval:** minimaal 30 seconden, maar omdat RzSense elke minuut een ronde doet, is **1 minuut** in de praktijk het
  kortste interval.
- **Knoppen per check:** ↻ nu checken, 📈 grafiek en historie, ✎ bewerken of verwijderen, 🔧 onderhoud, ✔ bevestigen.
- **Certificaat controleren** (bij HTTP(S) en SSL-certificaat): zet dit uit voor apparaten met een self-signed certificaat,
  zoals Proxmox of een NAS. De vervaldatum wordt dan nog steeds bewaakt. Bij een nieuwe SSL-check staat het standaard uit.
- **Veilige adressen:** een HTTP-check vraagt alleen `http://` en `https://` op, en nooit link-local adressen zoals
  `169.254.169.254` (de metadata-dienst van clouds), ook niet na een doorverwijzing. Je LAN, `localhost` en internet werken
  gewoon.
- **Wachtwoorden in een check** (database, MQTT, SNMP) gaan nooit terug naar de browser. Laat je het veld met de puntjes
  staan, dan blijft het oude wachtwoord.

## Alle checktypen

*Bron* zegt wie de meting doet: **RzSense** vraagt het zelf op over het netwerk (werkt bij elk servertype), de **agent**
meet het op de server zelf. Bij *Drempels* staan de standaardwaarden voor warning / critical; je past ze per check aan.

| Type | Wat het controleert | Bron | Drempels (standaard) |
|---|---|---|---|
| **Ping** | Reageert het apparaat? 3 pings, de gemiddelde tijd | RzSense | 100 / 500 ms. Pakketverlies = minstens WARNING, alles kwijt = CRITICAL |
| **TCP poort** | Staat een dienst open (SSH, MariaDB, MQTT …)? Doel: het poortnummer, bv. `22` | RzSense | 500 / 2000 ms verbindingstijd. Dicht = CRITICAL |
| **HTTP(S)** | Website of API bereikbaar, statuscode onder 400. Doel: een volledige URL of een pad (`/status`, dan met het adres van de server). Ook met methode (GET, POST, HEAD, PUT) en body, een tekst die in het antwoord moet staan, of een JSON-veld met een waarde | RzSense | 1000 / 3000 ms. Status 400 of hoger = CRITICAL. Certificaat korter dan 14 dagen geldig = WARNING, korter dan 3 dagen = CRITICAL |
| **SSL-certificaat** | Hoe lang een certificaat nog geldig is, op elke TLS-poort: `site.nl`, `nas.lan:5001`, `mqtt.lan:8883`. Leeg = het adres van de server op poort 443. Bij een keten telt het certificaat dat het eerst verloopt | RzSense; een bestand (`/etc/ssl/mijn.crt`) of `localhost:poort` via de agent | Warning **onder** 21 dagen, critical **onder** 7. Verlopen of ongeldig = CRITICAL |
| **Diskruimte** | Percentage gebruikt per mount (`/`, `/data`), plus een voorspelling wanneer hij vol is | Lokaal of agent | 80 / 90 %. Vol binnen 7 dagen = WARNING |
| **Service** | Is een service actief? Bij agent-servers kies je uit een lijst, met naar keuze een diepere controle (luistert hij, antwoordt hij, herstarts, fouten in het log) | Lokaal of agent | Niet actief of antwoordt niet = CRITICAL |
| **Proces** | Draait een proces? Op naam (zoals in `ps`) of op een patroon in de opdrachtregel, eventueel van één gebruiker; plus aantal, CPU en RAM | Lokaal of agent | Draait niet = CRITICAL. Optioneel min/max aantal, CPU 80 / 95 %, RAM 1024 / 2048 MB. Herstart = ℹ️ info |
| **CPU** | CPU-gebruik over 2 seconden, met de load average in het bericht | Lokaal of agent | 85 / 95 % |
| **RAM** | Geheugengebruik (cache telt niet mee), met swap in het bericht | Lokaal of agent | 85 / 95 % |
| **Uptime** | Ziet een herstart en stuurt dan een ℹ️ info-melding. Op Windows ook WARNING als Windows op een herstart wacht | Lokaal of agent | Geen drempels; de waarde is de uptime in uren |
| **Heartbeat** | Meldt een script, back-up of cronjob zich op tijd? Zie [Heartbeat](#heartbeat-scripts-back-ups-en-cronjobs) | Het script zelf (`rzmon-hb` of een geheime URL) | Te laat = WARNING, na 2× de speling CRITICAL; mislukt = CRITICAL |
| **DNS** | Geeft een naam antwoord (A, AAAA, MX, TXT, CNAME, NS), eventueel via een eigen DNS-server, met de waarde die je verwacht? | RzSense | 200 / 1000 ms. Geen antwoord of een andere waarde = CRITICAL |
| **Tijd (NTP)** | Loopt de klok goed? Leeg = de klok van de server (chrony of systemd-timesyncd); of een NTP-server, bv. je router | Agent, of RzSense bij een NTP-server | 100 / 1000 ms afwijking. Niet gesynchroniseerd = WARNING |
| **Schijfgezondheid (SMART)** | Gezondheidstest, temperatuur, slijtage (SSD/NVMe), vervangen en twijfelachtige sectoren. Schijven in slaapstand worden niet gewekt | Agent, met smartmontools | 55 / 65 °C. Test mislukt of 90 % versleten = CRITICAL; sectoren of 80 % versleten = WARNING |
| **UPS (NUT)** | Op het net of op de batterij, lading, resterende tijd en belasting | Agent, met NUT (`upsc`) | Lading onder 50 / 20 %. Op de batterij = WARNING, bijna leeg = CRITICAL |
| **Database (MariaDB, MySQL, PostgreSQL)** | De database van binnen: verbindingen, query's, cache, replicatie, grootte. Een overzicht of één waarde | Agent (lokaal) of RzSense maakt verbinding | Overzicht: 500 / 2000 ms; niet verbonden = CRITICAL. Zie [Linux-agent](05-linux-agent.md) |
| **Domein: verloopdatum** | Wanneer je domein verloopt, via RDAP, hooguit twee keer per dag. Registries zonder verloopdatum (zoals .nl): is het domein nog geregistreerd en actief? | RzSense | Warning **onder** 30 dagen, critical **onder** 14. Niet gevonden of een status als *pending delete* of *quarantaine* = CRITICAL |
| **Domein: e-mailbeveiliging** | SPF, DKIM, DMARC, MTA-STS en TLS-RPT in DNS. Zie [Beveiliging](08-beveiliging.md) | RzSense | Score 0–100. Geen SPF of DMARC = CRITICAL; DMARC `p=none` of zwakke DKIM = WARNING |
| **MQTT-broker** | Inloggen (eventueel met TLS), een testbericht sturen en zelf weer ontvangen; eventueel een topic controleren, bv. `zigbee2mqtt/bridge/state` met `online` | RzSense | 500 / 2000 ms rondgang. Niet verbonden, geweigerd of bericht komt niet terug = CRITICAL |
| **VPN: Gluetun** | De control server van Gluetun (standaard `http://<server>:8000`): draait de VPN, publiek IP, land | RzSense | Gestopt = CRITICAL; **lek** (het publieke IP is dat van je eigen lijn) = CRITICAL; ander land = WARNING |
| **VPN: WireGuard** | Tijd sinds de laatste handshake per peer; kies een peer, een interface (`wg0`) of alle | Agent (als root) | 300 / 900 seconden. Nog nooit een handshake = CRITICAL |
| **SNMP** | Switches, access points, NAS, UPS, printers en Proxmox. Zie [SNMP](#snmp) | RzSense | Per meting |
| **Internet** | Je internetlijn: ping, responstijd, download en upload. Zie [Internet](#internet) | RzSense | Ping 50 / 150 ms, responstijd 500 / 2000 ms, snelheid in % van je abonnement |
| **Verkeer (UniFi)** | Hoeveel verkeer een apparaat doet, volgens UniFi: de snelheid nu of de hoeveelheid vandaag. Zie [Netwerk](10-netwerk.md) | RzSense (via UniFi) | Snelheid 50 / 200 Mbit/s, per dag 20 / 50 GB |
| **Containers (algemeen)** | Podman en/of Docker: gestopte, ongezonde of gepauzeerde containers en pods | Agent | Probleem = WARNING |
| **Container (Podman/Docker)** | Eén container: status, plus naar keuze CPU, RAM en disk | Agent | CPU 80 / 95 %, RAM 85 / 95 %, disk 5000 / 10000 MB |
| **Logbestand (zoeken)** | Treffers van een zoekpatroon in een logbestand, het systemlog of kernelmeldingen | Agent | Aantal treffers in de laatste 15 minuten |
| **Commando** | De agent voert een commando uit en vergelijkt de uitvoer met wat je verwacht. Standaard uit | Agent | Afwijking = warning of critical, naar keuze |
| **Pad (traceroute)** | Vertraging, jitter en verlies per hop naar een doel, met de switches en doorvoer onderweg | Agent (met mtr of traceroute) | 50 / 150 ms |
| **Proxmox VE** | Guests, back-ups, opslag en ZFS van een Proxmox-host | Agent (als root) | Per meting |
| **TrueNAS** | Meldingen, taken, apps, updates en schijftemperatuur van TrueNAS SCALE | Agent (als root) | Per meting |
| **Home Assistant** | Laatste back-up, integraties, systeemstatus, onverwachte herstart, levensduur van de opslag, USB-apparaten | Home Assistant-add-on | Per meting. Zie [Home Assistant](07-home-assistant.md) |

De agent-onderwerpen (certificaatbestanden, processen, pad-check, databases, containers, diepere service-check, logs,
TrueNAS, Proxmox en commando-checks) staan uitgebreid in [Linux-agent](05-linux-agent.md). Op een Home Assistant-agent
gebruik je bij **Service** `homeassistant`, `supervisor`, `updates` of `addon:<slug>`.

### Database-check: een gebruiker zonder rechten

Laat RzSense zelf verbinding maken met MariaDB of MySQL, maak dan een eigen gebruiker die niets mag:

```sql
CREATE USER 'rzmon_check'@'%' IDENTIFIED BY '…';
```

Alleen `USAGE` is genoeg. Let op: een export van je instellingen (zie
[Exporteren en importeren](#exporteren-en-importeren)) bevat dit wachtwoord niet, je vult het op een nieuwe RzSense opnieuw in.

## Omgekeerde status

Soms wil je juist een melding als iets wél reageert: telnet of FTP mag niet luisteren, een oude server moet uit staan, of
een website mag van buitenaf niet bereikbaar zijn. Daarvoor heeft het checkformulier twee keuzes:

- **Status als de check slaagt:** *OK (normaal)*, *WARNING (omgekeerd)* of *CRITICAL (omgekeerd)*.
- **Status als de check mislukt:** *OK*, *WARNING* of *CRITICAL*. Deze keuze verschijnt pas als de check omgekeerd is.

Voorbeeld: een service-check op `sshd` met *slaagt = CRITICAL* en *mislukt = OK*:

| Wat de check ziet | Normaal | Omgekeerd |
|---|---|---|
| sshd draait | OK | CRITICAL: *⇄ Reageert (dat mag niet)* |
| sshd draait niet | CRITICAL | OK: *⇄ Reageert niet, zoals bedoeld* |
| Geen gegevens (agent stil) | onbekend | onbekend |

Goed om te weten:
- Het kan bij **ping, TCP poort, HTTP(S), DNS, database, service, container, containers (algemeen), commando en
  Home Assistant**. Bij checks met drempels (disk, CPU, RAM, logbestand, certificaat) kan het niet.
- Een **waarschuwing telt als "slaagt"**: een trage website reageert wel.
- Op het dashboard staat **⇄** achter de naam.
- Een omgekeerde ping-, poort- of HTTP-check telt niet mee voor *Bereikbaar*, de gezondheidsbalk en afhankelijke servers.
- Slimme drempels werken niet op een omgekeerde check.

## De popup van een check

Klik op de naam of het bericht van een check voor alles over die check:
- de status in het groot, hoe lang al, en een meter met de grenzen;
- de **grafiek van 24 uur**, met drempels, de geleerde band en statuswissels;
- **beschikbaarheid** (24 uur, 7 en 30 dagen), het aantal storingen, de gemiddelde hersteltijd en de langste storing;
- **details** per soort (container, disk met *vol over*, certificaat, domein);
- **melden**: hoe ver *melden na N* is, wanneer en via welke kanalen er gemeld is, escalatie en bevestiging;
- **samenhang**: andere problemen op dezelfde server en de server waarvan hij afhangt;
- het laatste AI-advies, de instellingen, en de **statuswijzigingen** als tijdlijn. Bij elke wijziging staat of er een
  melding uitging, en zo niet, waarom niet (onderhoud, bevestigd, onderdrukt, te kort, flapt, stille uren of de meldregel
  staat uit).

Je opent de popup ook door op een melding op de pagina *Meldingen* te klikken.

### Grafieken, beschikbaarheid en diskvoorspelling

- Onder elke check staat een **minigrafiek** van 24 uur. Klik erop (of op 📈) voor de grote grafiek met minimum,
  gemiddelde en maximum.
- Een opengeklapte server toont **Bereikbaar: 24 u · 7 d · 30 d**: het percentage metingen waarin de ping lukte. Zonder
  ping-check tellen de TCP- en HTTP-checks.
- **Diskvoorspelling:** RzSense trekt een lijn door de diskmetingen van de afgelopen 7 dagen, bijvoorbeeld *vol over
  ~12 dagen (+0,8%/dag)*. Raakt de disk binnen 7 dagen vol, dan wordt de check WARNING, ook onder de drempel. Het aantal
  dagen stel je in bij *Instellingen → Meldingen en rapporten → Wanneer melden*, kaart *Alarmgedrag* (0 = uit). Er is
  minstens een dag aan metingen nodig.

## Poortscan

Zoek uit welke TCP-poorten op een apparaat open staan en maak er met één klik een check van.

1. Klap de server open en klik op **🔍 Poorten**.
2. Kies een bereik: **bekende poorten** (SSH, web, databases, HA, NAS …), **poort 1-1024** of een eigen lijst zoals
   `22,80,8000-8100` (hooguit 4096 poorten). Klik op **Scannen**.
3. De knop wordt **⏹ Stoppen** en telt mee hoeveel poorten gedaan en open zijn. Stoppen houdt wat al gevonden is.
4. Klik bij elke dienst die je wilt bewaken op **＋ Check**.

De scan loopt parallel: 1024 poorten zijn binnen een seconde klaar. Bij agent-servers staat eronder ook welke poorten op
de server luisteren, met de procesnaam. Dat werkt ook voor poorten achter een firewall.

> Scan alleen apparaten van jezelf. Op bedrijfs- of hostingnetwerken kan een poortscan als aanval worden gezien.

## Onderhoud

Ga je iets updaten of herstarten? Zet dan onderhoud aan, zodat je geen meldingen krijgt voor iets wat je zelf doet.

| Voor | Waar |
|---|---|
| **Alles** | *Monitoring* → menu **⋯** → **🔧 Onderhoud** |
| **Eén server** | **🔧 Onderhoud** bij de opengeklapte server |
| **Eén check** | 🔧 bij de check |

Kies een duur (15 minuten tot 1 week) of een vast eindtijdstip, en vul eventueel een reden in.

- **Tijdens het onderhoud** blijven de checks draaien en zie je de status gewoon, maar er gaan geen meldingen uit.
  Servers en checks in onderhoud zijn paars gestreept, met een label als *🔧 tot 14:30*. Bij onderhoud voor alles staat
  bovenaan een balk.
- **Na afloop** stopt het onderhoud vanzelf. Is er dan nog iets mis, dan krijg je bij de volgende check een melding; is
  alles goed, een herstelmelding. Zo mis je niets dat na je onderhoud kapot is gebleven.
- **Eerder stoppen:** 🔧 → **Onderhoud beëindigen**, of **Beëindigen** in de balk.
- **REST API en Home Assistant:** checks in onderhoud tellen niet mee voor de totaalstatus, dus *RzSense alles OK* springt
  niet op rood door gepland onderhoud.

Voor iets dat elke week op hetzelfde moment gebeurt, gebruik je een [vast onderhoudsvenster](#vaste-onderhoudsvensters).

### Automatisch onderhoud na herhaalde meldingen

Blijft een check fout, dan krijg je eerst gewoon meldingen en herhalingen. Bij de **3e verstuurde melding** voor dezelfde
storing gaat **alleen die check** automatisch in onderhoud:
- je krijgt één melding *🔧 ONDERHOUD: server – check* en daarna niets meer over die check;
- de andere checks van de server melden gewoon door;
- bij de check staat **🔧 auto, tot opgelost**;
- is de check weer OK, dan stopt het onderhoud vanzelf en krijg je een herstelmelding.

Aan- en uitzetten en het aantal meldingen: *Instellingen → Meldingen en rapporten → Wanneer melden*, kaart *Alarmgedrag*
(standaard aan, na 3). Alleen meldingen die echt via een kanaal zijn verstuurd tellen mee. Bevestig je een storing, dan
stoppen de meldingen al eerder. Staan herhalingen uit, dan wordt de drempel van 3 meestal niet gehaald.

## Een storing bevestigen

Weet je van een storing en ben je ermee bezig? Bevestig hem met de groene **✔** bij de check, of **✔ Alles bevestigen** bij
de server. Je krijgt dan geen meldingen meer over deze storing, ook niet als hij van WARNING naar CRITICAL gaat. Zodra de
check weer OK is, vervalt de bevestiging vanzelf. Bevestigen vanaf je telefoon, via Pushover en de bevestig-link, staat in
[Meldingen](03-meldingen.md).

## Afhankelijkheden

Valt je router uit, dan zijn alle servers erachter ook onbereikbaar. Zonder afhankelijkheden krijg je dan tientallen
meldingen; met afhankelijkheden één.

1. Zorg dat de router (of switch, VPN-server, hypervisor) een **ping-check** heeft.
2. Bewerk elke server erachter (**✎ Bewerken**) en kies bij **Afhankelijk van** de router.

Je kunt meerdere lagen maken, bijvoorbeeld *Internet → Router → Switch → NAS*. Een kring (A hangt van B af en B van A)
wordt geweigerd.

| Situatie | Melding |
|---|---|
| Router valt uit | CRITICAL voor *Router – Ping*, met *Meldingen van 5 afhankelijke server(s) worden onderdrukt.* |
| Servers erachter worden onbereikbaar | Geen melding; de checks tonen **🔕 onderdrukt** |
| Router komt terug, NAS ook | Herstelmelding voor de router |
| Router komt terug, NAS blijft plat | Herstelmelding voor de router **en** een melding voor de NAS |

Een server telt als onbereikbaar als al zijn ping-checks CRITICAL zijn. Heeft hij geen ping, dan tellen de TCP- en
HTTP-checks. Zonder netwerkchecks telt een server nooit als onbereikbaar. RzSense controleert de bovenliggende servers in
elke ronde als eerste, zodat de onderdrukking meteen klopt.

RzSense kan ook zelf afhankelijkheden voorstellen: zie [Slimme voorstellen](#slimme-voorstellen).

## SNMP

Op apparaten waar geen agent op kan, zoals switches, access points, een NAS, een UPS of een printer, leest RzSense de
gegevens zelf uit via SNMP. Het kan ook op servers met een agent, zoals Proxmox met `snmpd`.

### Op het apparaat

- Zet SNMP aan (alleen lezen) en geef het IP-adres van RzSense toegang. RzSense vraagt op UDP-poort 161.
- Kies **v2c** met een eigen community (liever niet `public`), of beter **v3** met een gebruiker, *SHA* en *AES*.
- Waar het zit, verschilt per merk: bij UniFi in de Network-app (*Instellingen → Systeem → SNMP*), bij TP-Link Omada of
  JetStream in de systeeminstellingen, bij Synology in *Configuratiescherm → Terminal & SNMP → SNMP*.
- Proxmox of Debian: `apt install snmpd` en in `/etc/snmp/snmpd.conf` bijvoorbeeld:

  ```
  rocommunity <community> <IP van RzSense>
  agentAddress udp:161
  ```

### In RzSense

1. Vul één keer de algemene instelling in: *Instellingen → Agents en koppelingen → SNMP* (versie, poort, community of
   v3-gebruiker). Elk apparaat zonder eigen instelling gebruikt die. **🔍 Alles testen** vraagt alle apparaten tegelijk
   om hun systeeminformatie.
2. Bewerk de server (of maak hem aan) en zet bij **📡 SNMP** de schakelaar aan. Wijkt dit apparaat af, zet dan
   **Eigen instellingen** aan en vul de versie en community of v3-gebruiker in.
3. Klik op **🔍 Test SNMP**. Je ziet de naam, de fabrikant en de uptime, of een duidelijke fout.
4. Sla op. RzSense doorzoekt het apparaat meteen en toont **📡 SNMP-checks**: vink aan wat je wilt bewaken en klik op
   **Toevoegen**. Later open je die lijst met **📡 SNMP-checks** bij de server; wat al bewaakt wordt, staat er grijs bij.

### Wat RzSense herkent

| Groep | Checks | Standaard |
|---|---|---|
| Systeem | Uptime (herstart), CPU, geheugen, load, temperatuur | Korter aan dan 1 uur = WARNING · CPU en geheugen 85 / 95 % |
| Interfaces | Per poort: up/down, verkeer in en uit, bezetting, fouten | Down terwijl aan = CRITICAL · bezetting 80 / 95 % van de linksnelheid |
| Opslag | Per schijf of volume: % gebruikt | 80 / 90 % |
| UPS (UPS-MIB: Eaton, CyberPower, Riello … en APC) | Status, lading, looptijd, belasting, accutemperatuur | Lading onder 50 / 20 % · looptijd onder 15 / 5 min · accu 35 / 45 °C |
| Synology | Systeem, voeding, ventilatoren, temperatuur; per volume de status en vulling; per schijf status, gezondheid en temperatuur | Volume 80 / 90 % · schijf 50 / 60 °C |
| Printer | Toner, inkt, drum, afvalbak | Onder 20 / 5 % |
| UniFi access points | Zie hieronder | |

- CPU en geheugen komen uit HOST-RESOURCES (Proxmox, Linux, MikroTik, UniFi en veel switches), de MIB van TP-Link of
  net-snmp. Bij Linux en Proxmox telt geheugen zonder buffers en cache, en is de uptime die van het systeem.
- Een **accutemperatuur** boven 25 °C kost levensduur: bij een loodaccu halveert die ongeveer per 10 °C warmer. Daarom
  waarschuwt RzSense standaard vanaf 35 °C.
- **Interfaceverkeer** is het verschil tussen twee metingen. Na het toevoegen staat er dus eerst *volgt na de volgende
  meting*.

**UniFi access points**

| Check | Wat | Standaard |
|---|---|---|
| **UniFi AP** | Isolated (geen bekabelde verbinding meer, bv. een mesh-AP zonder uplink), met model, firmware en uplink; ℹ️-melding bij nieuwe firmware | Isolated = CRITICAL · aangevinkt |
| **Clients 2,4 / 5 / 6 GHz** | Verbonden clients per band, uitgesplitst per SSID | Geen drempels · aangevinkt |
| **Clients ‹SSID›** | Verbonden clients per SSID, uitgesplitst per band | Geen drempels · aangevinkt |
| **UniFi clients** | Totaal van het AP | Geen drempels · niet aangevinkt |
| **Kanaalbezetting ‹band›** | Hoe vol het kanaal is, ook door buren en storing | 60 / 80 % · aangevinkt |
| **SSID ‹naam›** | Wordt het SSID uitgezonden? | Nergens = CRITICAL, op een band uit = WARNING · aangevinkt |
| **Verkeer ‹SSID›** | Download en upload van de clients | Geen drempels · niet aangevinkt |
| **Kwaliteit ‹SSID›** | Aandeel opnieuw verzonden pakketten | 15 / 30 % · niet aangevinkt |

De onderdelen voor TP-Link, UniFi, MikroTik, APC en printers zijn gemaakt met de MIB's van de fabrikant, maar nog niet op
elk merk getest. RzSense biedt alleen aan wat het apparaat echt beantwoordt.

### Eigen OID

Wil je iets anders uitlezen, klik dan op **＋ Eigen OID** in de lijst *SNMP-checks*, of kies bij **＋ Check** het type
*SNMP* en dan *Eigen OID*:
- **Getal**, met drempels. *Vermenigvuldigen met* rekent om, bijvoorbeeld `0.1` als het apparaat tienden van graden geeft.
  Zet *Lager is slechter* aan voor zoiets als een batterijpercentage.
- **Teller**: RzSense maakt er een waarde per seconde van.
- **Tekst vergelijken**: *is precies*, *bevat*, *bevat niet* of *past op regex*, met warning of critical als het niet klopt.

Geef de OID numeriek op, bijvoorbeeld `1.3.6.1.4.1.14988.1.1.3.10.0`. Opzoeken kan op de server van RzSense met:

```bash
snmpwalk -v2c -c <community> <apparaat> 1.3.6.1.4.1
```

### Goed om te weten

- Geeft het apparaat geen antwoord, dan worden de SNMP-checks **onbekend**. Of het apparaat bereikbaar is, bepaalt de
  ping; voeg die dus ook toe.
- Een apparaat dat niet antwoordt, houdt de ronde hooguit 3 seconden op.
- Community en wachtwoorden gaan nooit terug naar de browser; in het formulier zie je `••••••••`.
- RzSense heeft de PHP-extensie **php-snmp** nodig. Het installatiescript zet die erop. Ontbreekt hij, dan zegt de check of
  de testknop dat. Op Rocky, RHEL of Alma installeer je hem zo:

  ```bash
  sudo dnf -y install php-snmp net-snmp-utils && sudo systemctl restart php-fpm
  ```
- Traps (meldingen die het apparaat zelf stuurt) worden niet ontvangen. RzSense vraagt de waarden zelf op, standaard elke
  minuut.

## Heartbeat: scripts, back-ups en cronjobs

Bij een heartbeat-check vraagt RzSense zelf niets op: het script of apparaat meldt zich bij RzSense als het klaar is.
Blijft die melding uit, dan krijg je bericht. Ideaal voor back-ups, cronjobs, certificaatvernieuwing en alles wat 's nachts
stil hoort te lukken.

### Aanmaken

Klap de server open → **＋ Check** → type **Heartbeat**:
- **Naam (voor rzmon-hb)**: bv. `backup-db` (letters, cijfers, punt, `-` of `_`; per server uniek).
- **Verwacht**: *elke … minuten/uren/dagen*, *elke dag om …*, *elke week op …* of een eigen cron-schema
  (`minuut uur dag maand weekdag`, bv. `30 2 * * 1-5` = werkdagen om 02:30). Gebruik hetzelfde schema als je cronjob.
- **Speling** (standaard 60 minuten): zo lang mag de melding op zich laten wachten. Daarna WARNING, na twee keer de
  speling CRITICAL.
- **Maximale duur**: stuurt het script ook *start*, dan meet RzSense hoe lang het duurt. Duurt het langer, dan wordt de
  check CRITICAL. Leeg = vanzelf: twee keer het gemiddelde van de laatste keren.
- **Eenheid**: stuur je een waarde mee (bv. de grootte van de back-up), vul dan de eenheid in (`GB`, `bestanden` …); dan
  gelden de drempels. Zonder eenheid is de waarde de duur in minuten.

| Melding | Status |
|---|---|
| `ok` (klaar, gelukt) | OK. Duurde het veel langer dan normaal: WARNING |
| `start` (begonnen) | OK, *⏳ Bezig sinds …*; te lang bezig = CRITICAL |
| `fail` (mislukt) | Altijd CRITICAL, met de reden. Een volgende `ok` maakt hem weer groen |
| Niets binnen de speling | WARNING, na twee keer de speling CRITICAL |

Een heartbeat leert ook zijn ritme. Komt een dagelijkse back-up altijd rond 02:10 binnen en is er om 03:00 nog niets, dan
krijg je al een warning (*meestal rond 02:12*). En is de meegestuurde waarde minder dan de helft of meer dan 3× zo groot
als normaal, ook een warning.

### Melden met rzmon-hb (op een server met de agent)

De melding gaat versleuteld mee met het volgende rapport van de agent (binnen een minuut) en blijft bewaard als RzSense
even onbereikbaar is. Elke gebruiker op de server mag het commando gebruiken.

```bash
rzmon-hb backup-db start                 # begonnen
rzmon-hb backup-db ok                    # gelukt
rzmon-hb backup-db ok "12 tabellen" --value 3.4
rzmon-hb backup-db fail "schijf vol"     # mislukt, met reden
```

In een cronjob gebruik je het volledige pad `/usr/local/bin/rzmon-hb`, want cron kent `/usr/local/bin` vaak niet. Klopt
de naam niet, dan staat in het logboek van de agent *RzSense heeft geen heartbeat-check met de naam …*.

Op **TrueNAS** staat `rzmon-hb` niet in `/usr/local/bin` (dat verdwijnt bij een update). Gebruik daar:

```bash
AGENT_DIR=/mnt/<pool>/rzmon-agent bash /mnt/<pool>/rzmon-agent/rzmon-agent.sh --hb backup-db ok
```

### Melden met de geheime URL (overal elders)

Na het opslaan staat de URL in het checkformulier, met kopieerknoppen. GET en POST werken allebei; bij POST is de body
het bericht.

```bash
curl -fsS -m 10 http://RZSENSE-ADRES/hb.php/<code>                       # ok
curl -fsS -m 10 http://RZSENSE-ADRES/hb.php/<code>/start                 # begonnen
curl -fsS -m 10 http://RZSENSE-ADRES/hb.php/<code>/fail --data "reden"   # mislukt
curl -fsS -m 10 "http://RZSENSE-ADRES/hb.php/<code>?value=42&msg=klaar"  # met waarde en bericht
```

### Voorbeelden

**Rond elk script** (agent):

```bash
rzmon-hb nachtwerk start; /pad/naar/script.sh && rzmon-hb nachtwerk ok || rzmon-hb nachtwerk fail "exit $?"
```

**rsync**, dagelijks om 02:00 (check: *elke dag om 02:00*):

```bash
# /etc/cron.d/rsync-nas
0 2 * * * root /usr/local/bin/rzmon-hb rsync-nas start; rsync -a --delete /data/ backup@nas:/volume1/backup/ && /usr/local/bin/rzmon-hb rsync-nas ok || /usr/local/bin/rzmon-hb rsync-nas fail "rsync exit $?"
```

**restic**, met de toegevoegde hoeveelheid in GB (check met eenheid `GB`):

```bash
#!/bin/bash
set -o pipefail
rzmon-hb restic start
if out=$(restic backup /data --json --quiet | tail -n 1); then
    rzmon-hb restic ok --value "$(jq '.data_added / 1e9 * 100 | round / 100' <<<"$out")"
else
    rzmon-hb restic fail "restic exit $?"
fi
```

**borg** (exitcode 1 is een waarschuwing, 2 een fout):

```bash
rzmon-hb borg start
borg create --stats /backup/borg::'{hostname}-{now}' /etc /home; rc=$?
if [ $rc -le 1 ]; then rzmon-hb borg ok "borg rc $rc"; else rzmon-hb borg fail "borg rc $rc"; fi
```

**MariaDB-dump**, met de grootte in MB (check met eenheid `MB`):

```bash
#!/bin/bash
set -o pipefail
f=/backup/mariadb-$(date +%F).sql.gz
rzmon-hb mariadb-dump start
if mariadb-dump --all-databases --single-transaction --routines | gzip >"$f"; then
    rzmon-hb mariadb-dump ok --value "$(du -m "$f" | cut -f1)"
else
    rzmon-hb mariadb-dump fail "dump mislukt (exit $?)"
fi
```

**Proxmox VE-back-ups** (vzdump, ook naar een Proxmox Backup Server). Installeer de agent op de Proxmox-host en maak een
hookscript:

```bash
#!/bin/bash
# /usr/local/bin/vzdump-rzmon.sh  (chmod +x)
case "$1" in
    job-start)    rm -f /run/vzdump-fout; rzmon-hb vzdump start ;;
    backup-abort) echo "VM $3" >>/run/vzdump-fout ;;
    job-end)      if [ -s /run/vzdump-fout ]; then rzmon-hb vzdump fail "mislukt: $(paste -sd' ' /run/vzdump-fout)"
                  else rzmon-hb vzdump ok; fi ;;
    job-abort)    rzmon-hb vzdump fail "back-uptaak afgebroken" ;;
esac
exit 0
```

Zet in `/etc/vzdump.conf` de regel `script: /usr/local/bin/vzdump-rzmon.sh`. Kies in RzSense hetzelfde schema als de
back-uptaak in Proxmox (*Datacenter → Backup*).

**certbot** (systemd-timer; check: *elke 1 dagen*, speling 720 minuten). Zo werkt het voor elke systemd-service:

```bash
sudo systemctl edit certbot.service
```
```ini
[Service]
ExecStopPost=/bin/sh -c 'if [ "$SERVICE_RESULT" = success ]; then /usr/local/bin/rzmon-hb certbot ok; else /usr/local/bin/rzmon-hb certbot fail "certbot: $EXIT_STATUS"; fi'
```

**Synology Hyper Backup** (geen agent; de geheime URL). Hyper Backup kan zelf geen script starten, dus laat een script na
de back-up het logboek lezen: *Configuratiescherm → Taakplanner → Maken → Geplande taak → Door gebruiker gedefinieerd
script*, gebruiker `root`, een half uur na de back-up:

```bash
TAAK="Mijn back-up"   # naam van de taak in Hyper Backup
URL="http://RZSENSE-ADRES/hb.php/<code>"
REGEL=$(grep -F "[$TAAK]" /var/log/synolog/synobackup.log | tail -n 1)
case "$REGEL" in
    *[Ff]ailed*|*[Pp]artially*|*[Cc]ancel*) curl -fsS -m 10 "$URL/fail" --data "$REGEL" ;;
    *[Ff]inished*|*[Cc]ompleted*|*[Ss]ucceeded*) curl -fsS -m 10 "$URL" --data "$REGEL" ;;
    *) curl -fsS -m 10 "$URL/fail" --data "geen regel gevonden voor $TAAK" ;;
esac
```

De tekst in het logboek verschilt per DSM-versie. Kijk met `tail /var/log/synolog/synobackup.log` hoe een geslaagde
back-up er bij jou uitziet en pas de woorden zo nodig aan.

**Home Assistant** (de add-on heeft geen `rzmon-hb`; gebruik de geheime URL). In `configuration.yaml`:

```yaml
rest_command:
  rzmon_hb_backup:
    url: "http://RZSENSE-ADRES/hb.php/<code>{{ soort }}"
    method: post
    payload: "{{ bericht }}"
```

En een automatisering die de automatische back-up van Home Assistant (2025.5 of nieuwer) doorgeeft:

```yaml
alias: RzSense – back-up Home Assistant
triggers:
  - trigger: state
    entity_id: event.backup_automatic_backup
actions:
  - action: rest_command.rzmon_hb_backup
    data:
      soort: >-
        {{ {'in_progress': '/start', 'failed': '/fail'}.get(trigger.to_state.attributes.event_type, '') }}
      bericht: "{{ trigger.to_state.attributes.event_type }}"
```

Met dezelfde `rest_command` kan elke automatisering zich melden, bijvoorbeeld een dagelijkse *ik leef nog*.

### Goed om te weten

- **De code in de URL is het wachtwoord** (128 bits, willekeurig). Standaard werkt de URL alleen vanaf je lokale netwerk;
  van buiten krijg je `403`. Meld je vanaf een VPS of de cloud, zet dan per check **De URL werkt ook van buiten het lokale
  netwerk** aan en gebruik https. Uitgelekt? Vink **Nieuwe geheime URL maken** aan; de oude werkt meteen niet meer. Een
  onbekende code telt mee voor de blokkades na mislukte pogingen.
- **`rzmon-hb`** schrijft alleen een regel in `/var/spool/rzmon-agent`. Iedereen mag daarin schrijven, niemand mag er
  lezen of andermans regels weghalen. De agent stuurt alleen regels in precies de juiste vorm mee, hooguit 100 per rapport.
- Een nieuwe check is meteen OK en wacht op de eerste melding volgens het schema. Onderhoud en bevestigen werken zoals bij
  andere checks.
- De tijd van de melding is het moment waarop het script hem gaf, ook als de agent hem later doorstuurt.

## Sjablonen

Een sjabloon is een vaste set checks, met drempels, interval en meldregels, die je aan servers koppelt. Je vindt ze bij
*Instellingen → Monitoring → Sjablonen*.

- **Zelf maken:** **＋ Nieuw sjabloon** en dan per check **＋ Check toevoegen** (hetzelfde formulier als bij een server). Of
  **📋 Sjabloon maken van een server…**: kies een server en de checks die mee moeten; die server wordt meteen gekoppeld.
- **Per server invullen:** gebruik `{adres}` en `{naam}` in het doel of de naam, bv. `https://{adres}/` of
  `Certificaat {naam}`.
- **Startsjablonen:** *Linux-server (agent)*, *Website* en *Netwerkapparaat*. Pas ze gerust aan of verwijder ze.
- **Koppelen:** bij het sjabloon (**＋ Server koppelen…**) of bij **✎ Bewerken** van de server, onderdeel **📋 Sjablonen**.
  Een server kan meerdere sjablonen hebben. Heeft hij al een check van dezelfde soort (zelfde type en doel), dan neemt het
  sjabloon die over.
- **Aanpassen:** wijzig je een check in het sjabloon, dan gaat dat meteen naar alle gekoppelde servers. Pas je een
  sjablooncheck op één server zelf aan, dan gaat die check daar niet meer mee; in het checkformulier staat dan
  **↩ Terug naar het sjabloon**.
- **Loskoppelen of verwijderen:** de checks blijven op de servers staan, tenzij je kiest om ze ook te verwijderen (met je
  wachtwoord).

## Alle checks tegelijk bewerken

*Instellingen → Monitoring → Alle checks* toont alle checks van alle servers in één lijst, te doorzoeken op naam, doel of
server en te filteren op server en type.

- **Vink checks aan** en pas ze tegelijk aan: ⏱ interval, 🎚 drempels (alleen bij hetzelfde type), 🔔 melden (na hoeveel
  mislukte checks, terug naar de algemene meldregels, alleen info), 🔧 onderhoud, 🧠 slimme drempels, ▶ aan of ⏸ uit,
  📋 kopiëren naar andere servers, of 🗑 verwijderen (met je wachtwoord).
- **Kopiëren:** staat het adres van de oorspronkelijke server in het doel, dan krijgt de kopie het adres van de nieuwe
  server.
- **Klik op een naam** om die check te bewerken.

## Nog niet bewaakt

Op een agent-server ziet RzSense wat de agent meldt maar nog geen check heeft: disks, services die draaien en automatisch
starten, containers, certificaten en poorten die van buiten de server bereikbaar zijn. Systeemservices zoals `systemd-*`,
`getty`, `dbus` en `cron` tellen niet mee.

1. Bij de server staat dan bijvoorbeeld **💡 3 nieuw gevonden**. Klik erop.
2. Vink aan wat je wilt bewaken en kies **Checks toevoegen**. Ze krijgen gangbare drempels; poorten staan standaard uit.
3. Wil je iets nooit bewaken, kies dan **🙈 Gekozen negeren**. *Weer tonen* zet alles terug.

## Opschonen

*Instellingen → Monitoring → Slimme drempels en advies*, tabblad *Opschonen*, toont checks die aandacht verdienen, elk met
een voorstel:

| Punt | Betekenis | Voorstel |
|---|---|---|
| **❔ Al dagen onbekend** | Bestaat het doel nog? | *⏸ Uitzetten* |
| **📣 Ruismakers** | 10 of meer meldingen in 7 dagen | *Melden na 3*: pas melden na 3 mislukte checks achter elkaar |
| **🔁 Wisselt steeds** | 20 of meer statuswissels in 7 dagen | Bekijk de drempel of *melden na N* |
| **🟠 Al lang in warning** | Langer dan 3 dagen | Verhoog de drempel als het normaal is |
| **🎚 Net onder de drempel** | Gemiddeld binnen 10 % onder de warning-drempel | Verhoog de drempel |
| **⏸ Al lang uit** | Meer dan 30 dagen | *🗑 Verwijderen* |

Klik op een naam om de check te bewerken. Met 🙈 (*dit is normaal*) komt het punt niet meer terug.

## Slimme drempels

Vaste drempels passen niet altijd. Een CPU die elke nacht om 3:00 op 100 % staat door de back-up, geeft elke nacht een
warning. En een website die normaal in 120 ms antwoordt maar nu in 900 ms, merk je met een drempel van 1000 ms niet. Daarom
kan RzSense per check **leren wat normaal is**, per uur van de week, en daar de drempels op zetten.

### Zo stel je het in

Bewerk de check (✎) en kies bij de drempels een modus:

| Modus | Wat het doet |
|---|---|
| ✋ **Handmatig** | De vaste drempels |
| 🧠 **Slim** | RzSense leert wat normaal is en zet de drempels zelf |
| 🧠🛡️ **Slim met vangnet** | Slim, maar vanaf het vangnet (*Altijd critical vanaf*, bv. RAM 98 %) altijd CRITICAL |

De standaard voor nieuwe checks, de gevoeligheid (*rustig*, *normaal* of *scherp*) en *Zelf omzetten* stel je in bij
*Instellingen → Monitoring → Slimme drempels en advies*. Een nieuwe check krijgt standaard *slim met vangnet*.

### Hoe het werkt

- **Werkt bij** ping, TCP, HTTP, CPU, RAM, DNS, NTP, database, internet (ping en responstijd), containers (een check op
  alleen CPU of alleen RAM) en SNMP (CPU, geheugen, interface, load, temperatuur, UPS-belasting en -temperatuur,
  Synology-temperaturen, UniFi-kanaalbezetting en -kwaliteit). **Disks** houden vaste drempels met de voorspelling: leren
  zou een vollopende disk gewoon volgen.
- **Leren** gebeurt uit de meetwaarden van de laatste 28 dagen, met mediaan en percentielen, zodat één uitschieter niets
  verpest. Tot er **7 dagen** gegevens zijn, gelden de vaste drempels (*🧠 leert nog 3/7 dagen*).
- **Elke nacht** vanaf 4:00 rekent RzSense alles opnieuw door. Een drempel verschuift hooguit 10 % per nacht. Een merkbare
  aanpassing staat in het Logboek en is daar terug te draaien.
- **Ondergrenzen:** een warning komt nooit onder een zinnige grens (CPU en RAM 50 %, ping 20 ms, HTTP 300 ms …). Een server
  die normaal op 3 % CPU draait, krijgt dus geen warning bij 15 %.
- **Sluipende stijging:** stijgt de normaalwaarde in twee weken flink (een geheugenlek, groeiende belasting), dan krijg je
  een ℹ️-melding en schuiven de drempels zolang niet mee omhoog.
- **In de melding** staat wat normaal is: *CPU 78% · 🧠 normaal op dit uur 35–60%*.
- **In de popup** van de check zie je de modus, wat nu normaal is, welke drempels nu gelden, een weekgrafiekje, en hoeveel
  dagen er een warning was met vaste en met slimme drempels.

### Bestaande checks

Bestaande checks blijven handmatig, maar RzSense leert wel mee. Op het tabblad *Slimme drempels* staat de tabel
**Voorstel voor bestaande checks**:

| Kolom | Betekenis |
|---|---|
| **Dagen met warning – nu** | Op hoeveel dagen er een warning was met de huidige drempels |
| **– met slim** | Hetzelfde met slimme drempels (groen = minder) |
| **Waarom** | Bv. *✔ 6 dag(en) minder met een warning*, *geen winst*, *geen verschil* of *slim is strenger* |

Een dag telt net als een echte melding: pas als er zoveel metingen achter elkaar te hoog waren als *melden na N* vraagt.
Checks met winst staan al aangevinkt. Staat er **grillig**, dan piekt de check bijna elke dag even, zoals de webinterface
van een IP-camera. Slimme drempels helpen daar weinig; **melden na 3** wel, en voor de bereikbaarheid is een ping-check
betrouwbaarder.

**Zelf omzetten** (standaard aan): na 7 dagen leren zet RzSense een handmatige check zelf op *slim met vangnet* als dat
minstens 2 dagen minder warnings had gegeven. Je krijgt een ℹ️-melding met de reden, en het staat in het Logboek. Zet je het
daar terug, dan blijft die check voorgoed handmatig.

## Slimme voorstellen

Elke nacht bekijkt RzSense de geschiedenis en doet voorstellen. Je vindt ze bij *Instellingen → Monitoring → Slimme
drempels en advies*, tabblad *Voorstellen*, en ook in *Analyse* onder *Wat nu aanpakken*. Er verandert niets tot je op
**Toepassen** klikt; **Negeren** = dat voorstel komt nooit meer terug. Met 🔄 bepaal je ze meteen opnieuw.

| Voorstel | Wanneer |
|---|---|
| 🔔 **Melden na meer mislukte checks** | Een check had in 30 dagen minstens 5 storingen en 70 % daarvan was binnen 5 minuten vanzelf over. RzSense rekent uit na hoeveel mislukte checks (2 tot 6) die haperingen wegvallen |
| 🔔 **Meteen melden** | Een check staat op *melden na 3 of meer*, maar de storingen duren bijna altijd lang: dan wacht je voor niets |
| 🗓️ **Vast onderhoudsvenster** | Iets gaat steeds op hetzelfde moment kort mis en herstelt vanzelf, zoals de back-up om 2:00 of de herstart op zondag |
| 🔗 **Afhankelijkheid** | Volgens UniFi hangt een server aan een switch of access point dat in RzSense staat, of een server valt bijna altijd tegelijk uit met een andere |

Een voorstel voor *melden na N* voert RzSense standaard meteen zelf uit, met een ℹ️-melding en een regel in het Logboek om
terug te gaan. Uitzetten kan met de schakelaar boven de voorstellen. Onderhoudsvensters en afhankelijkheden blijven altijd
voorstellen.

### Vaste onderhoudsvensters

Onder de voorstellen staat de lijst met vaste onderhoudsvensters: elke dag of op één weekdag, een begintijd en een duur,
voor een server of één check, met een reden. Voeg ze zelf toe met **＋ Venster toevoegen** of haal ze weg. Tijdens zo'n
venster gaan er geen meldingen uit; de checks draaien door. Een venster over middernacht werkt ook.

### Meer slimme hulp

Ook op dit tabblad zet je deze onderdelen apart aan of uit:

- **Slim interval** (standaard uit): gaat een ping-, TCP-, HTTP- of SNMP-check mis, dan checkt RzSense hem elke 20 seconden,
  zodat je sneller weet of een storing echt is en wanneer hij voorbij is. Is een check al een uur goed, dan mag zijn
  interval tot 2× zo lang worden. *Melden na N* blijft in tijd gelijk: een check op *melden na 3* met een interval van
  1 minuut meldt pas na 2 minuten. Checks van de agent volgen het rapport van de agent.
- **Afwijkingen zonder drempels:** checks met een getal maar zonder drempels (SNMP-verkeer, clients, eigen OID's, aantal
  processen, logregels) leren ook wat normaal is. Wijkt de waarde 3 metingen achter elkaar flink af, dan krijg je een
  ℹ️-melding, bv. *Ongebruikelijk hoog: 812 Mbit/s · normaal op dit uur 4–60*. Hooguit eens per 6 uur per check.
- **Disk groeit hard:** groeit een disk in een uur 5 procentpunt of meer, dan krijg je een ℹ️-melding, ook ver onder de
  drempel. Hooguit eens per 6 uur per disk.
- **Oorzaak erbij:** bij de eerste melding van een storing kijkt RzSense wat er in de 20 minuten ervoor op die server
  gebeurde (een update, een herstart, bijgewerkte pakketten, zelf herstellen of een wijziging in RzSense) en zet dat onder
  de melding: *🕵️ Kort ervoor: 3 min eerder: Agent bijgewerkt*.

## Zelf herstellen

Op een server met de agent kan RzSense een probleem eerst zelf proberen op te lossen, en pas melden als dat niet lukt.
Bewerk de check (✎) en kies bij **🩹 Zelf herstellen**:

- **Herstarten** (bij een service- of containercheck): draait de service of container niet, dan herstart de agent hem
  zelf, vóór zijn rapport. Lukt dat, dan blijft de check OK en krijg je alleen een ℹ️-melding (*Zelf hersteld*). Lukt het
  niet, dan de gewone melding.
- **Een eigen herstelcommando** (bij elke check op die server): een script dat je op de server zelf zet. Gaat de check mis,
  dan vraagt RzSense de agent het uit te voeren, en meldt pas als het niet helpt of als er na 5 minuten nog geen uitkomst is.
  Exitcode 0 = gelukt; de eerste regel uitvoer komt in de melding.

Een herstelcommando maken:

```bash
sudo mkdir -p /etc/rzmon-agent/heal.d
printf '#!/bin/sh\nsystemctl restart wg-quick@wg0 && echo "WireGuard herstart"\n' | sudo tee /etc/rzmon-agent/heal.d/vpn-herstart >/dev/null
sudo chmod 755 /etc/rzmon-agent/heal.d/vpn-herstart
```

Het script moet uitvoerbaar zijn, van root, en niet door anderen te wijzigen; anders negeert de agent het. Na het volgende
rapport staat *herstelcommando vpn-herstart* in de keuzelijst.

**Goed om te weten**
- RzSense kan alleen kiezen uit wat er al op de server staat, en vraagt het alleen via een versleutelde verbinding. Iemand
  die in RzSense binnenkomt, kan dus geen willekeurige commando's op je servers uitvoeren.
- Per check hooguit één poging per 10 minuten en 3 per dag. Staat een check in onderhoud, dan herstelt de agent niet.
- Met `HEAL=0` in `/etc/default/rzmon-agent` staat zelf herstellen op die server helemaal uit.
- De agent draait met `ProtectSystem=full`: een herstelcommando kan `/usr` en `/etc` niet wijzigen. Een service herstarten
  kan wel.
- Op Home Assistant OS werkt zelf herstellen niet.

## Internet

De internetcheck bewaakt je internetlijn, gemeten vanaf de server van RzSense.

1. Ga naar *Instellingen → Monitoring → Internetcheck*.
2. Klik op **🌍 Internet-check toevoegen**. Er komt een apparaat **Internet** bij met vier checks.
3. Vul je **abonnement** in (download en upload in Mbit/s, standaard 1000/1000) en eventueel de drempels in procent.

| Check | Wat | Hoe vaak | Status |
|---|---|---|---|
| **Ping** | Pingtijd, jitter en pakketverlies naar 1.1.1.1 en 8.8.8.8 (5 keer elk), en je router | Elke minuut | De beste pingtijd: 50 / 150 ms. 20 % verlies of één adres weg = WARNING. Geen van beide = CRITICAL, en de melding zegt of je router nog antwoordt (dan zit het probleem buiten) of niet (dan zit het in je eigen netwerk) |
| **Responstijd** | google.com en cloudflare.com, steeds met een nieuwe verbinding: DNS, verbinden, TLS en het eerste antwoord | Elke 5 minuten | 500 / 2000 ms. Eén site weg = WARNING, allebei = CRITICAL |
| **Download** / **Upload** | De snelheid uit de laatste snelheidstest | Elke 5 minuten (de test zelf op vaste tijden) | In procent van je abonnement: warning onder 70 %, critical onder 40 % |

Ping en responstijd leren mee met de [slimme drempels](#slimme-drempels): 's avonds is je lijn drukker dan 's nachts.

### De snelheidstest

De test gebruikt **speed.cloudflare.com**, zonder extra programma. Eerst de pingtijd naar de testserver, dan download en
upload, elk met 4 verbindingen tegelijk. Een richting stopt na 10 seconden, of eerder na het ingestelde aantal MB
(standaard 250 MB). Een test kost dus hooguit ongeveer 500 MB; bij 4 tests per dag zo'n 2 GB per dag. De uitslag zegt ook
via welke locatie het ging, bijvoorbeeld *via AMS*.

Op dezelfde pagina stel je in:
- **Tijden** van de snelheidstest, standaard `03:30,09:30,13:30,17:30` (niet 's avonds, want de test trekt je lijn een paar
  seconden vol);
- **Hooguit per richting (MB)**.

Je ziet er ook de laatste test, wanneer de volgende is, een lijn van 30 dagen met je abonnement als stippellijn, en je
router. Met **⚡ Nu testen** doe je meteen een test. Lukt de test 2 dagen niet, dan krijg je een melding.

## Wijzigingen terugzetten

Elke wijziging aan servers, checks, sjablonen en instellingen staat op de pagina *Logboek* (onderdeel
*RzSense-instellingen*), met wie, wanneer, de oude en de nieuwe waarde.

- **↩ Terugzetten** op een regel zet een gewijzigde instelling, server of check terug zoals hij was. Een verwijderde check
  of server komt terug, en een agent werkt dan meteen weer, maar de meethistorie komt niet terug. Een nieuw aangemaakte
  check of server wordt weer verwijderd.
- Wachtwoorden, tokens en sleutels staan er nooit leesbaar in en worden niet teruggezet.

Meer over het Logboek staat in [De interface](02-de-interface.md).

## Exporteren en importeren

Bij *Instellingen → Systeem → Exporteren en importeren* zet je servers, checks en sjablonen, en eventueel de instellingen,
in één JSON-bestand. Handig om naar een tweede RzSense te kopiëren, of als leesbare reserve naast de back-up.

- **Zonder geheimen:** wachtwoorden, tokens, sleutels en de SNMP-community gaan niet mee. Agents koppel je op de nieuwe
  RzSense opnieuw.
- **Importeren voegt alleen toe.** Een server of sjabloon met dezelfde naam blijft zoals hij is, en een check die er al is
  (zelfde type, doel en soort) wordt overgeslagen. Je ziet eerst wat er gebeurt; importeren vraagt je wachtwoord.

## Handig bij het invullen

- **Lijstvelden** (IP-adressen, netwerken, e-mailadressen, domeinen) werken overal hetzelfde: je kiest uit wat RzSense al
  weet of typt zelf. Elke waarde staat als een chipje met ✕; een ongeldige waarde wordt rood en wordt niet opgeslagen.
- **Keuzelijsten uit je netwerk:** velden voor één adres, zoals het adres van een server, het doel van een ping- of
  TCP-check en de DNS-server van een DNS-check, bieden de apparaten uit de netwerkscan en je servers aan, met hun naam.
  Zelf typen kan altijd.
- **ⓘ bij een veld:** tik erop voor wat het veld doet, de eenheid en het bereik, wat leeg of 0 betekent, en de aanbevolen
  waarde met de knop **Gebruiken**.
