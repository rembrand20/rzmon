# Netwerk

RzSense kan zelf uitzoeken wat er op je lokale netwerk zit: telefoons, laptops, camera's, printers, slimme stekkers, je NAS. Dit hoofdstuk legt uit hoe je de netwerkscan aanzet, hoe je de pagina **Netwerk** leest (overzicht met kaart, apparaten, ontdekken), hoe je UniFi en AdGuard Home koppelt, hoe je een apparaat weert, en hoe RzSense het verkeer per apparaat en van je internetlijn bijhoudt. De netwerkkaart in Home Assistant staat in [Home Assistant](07-home-assistant.md); servers in een ander netwerk via een relay laten rapporteren staat in [Linux-agent](05-linux-agent.md#netwerken-en-relays).

## De netwerkscan

De netwerkscan zoekt welke apparaten er op je lokale netwerken zitten, met hun IP-adres, MAC-adres, fabrikant, naam, open poorten en een gok wat voor apparaat het is.

### Aanzetten

1. Zorg dat je lokale netwerken zijn ingevuld bij *Instellingen → Toegang tot RzSense → Inloggen → Lokale netwerken*, bijvoorbeeld `192.168.2.0/24`.
2. Ga naar *Instellingen → Netwerk → Netwerkscan* en zet **Netwerkscan aan**.
3. Kies hoe vaak RzSense scant:
   - **Snelle ronde** (ping en ARP): elke 15 of 30 minuten, of elke 1, 2 of 6 uur. Standaard elk uur.
   - **Diepere scan** (poorten, namen, merk): 1, 2, 4, 6 of 12 keer per dag. Standaard 4 keer.

Daarna staat er een tabblad **Netwerk** in de bovenbalk.

### Wat RzSense scant

- **Welke netwerken**: de netwerken bij *Lokale netwerken*. Losse adressen (zoals `127.0.0.1`), IPv6, netwerken die geen privé-adressen zijn en netwerken groter dan /22 (1024 adressen) slaat RzSense over. Op de pagina zie je wat wel en niet gescand wordt. Internet wordt nooit gescand.
- **Snelle ronde**: een ping naar elk adres, daarna de ARP-tabel van de RzSense-server. Zo vindt RzSense ook apparaten die niet op ping antwoorden. Nieuwe apparaten krijgen hun DNS-naam via je DNS-server (bijvoorbeeld AdGuard Home).
- **Diepere scan**: open poorten (een vaste lijst van ruim 40 bekende poorten), de NetBIOS-naam (Windows, NAS), UPnP (merk en model van tv's, mediaspelers, routers), mDNS/Bonjour en de titel van de webpagina. Daaruit maakt RzSense een gok wat voor apparaat het is.
- **Fabrikant**: uit het MAC-adres, met de lijst van Wireshark (maandelijks bijgewerkt; uitgaand HTTPS naar `www.wireshark.org`).

Sneller en beter wordt het met `fping` en `avahi-browse` op de RzSense-server. Nodig zijn ze niet.

```bash
# Rocky, AlmaLinux, RHEL
sudo dnf install fping avahi-tools
# Debian, Ubuntu
sudo apt install fping avahi-utils
```

### Goed om te weten

- Een UniFi IPS of een strenge firewall kan de scan van RzSense als poortscan zien. Sta in dat geval het IP-adres van de RzSense-server toe.
- In andere VLAN's ziet RzSense alleen IP-adressen, geen MAC-adres of fabrikant: ARP gaat niet over een router heen.
- Apparaten die 90 dagen niet gezien zijn, verdwijnen. Bekende apparaten met een eigen naam blijven staan.

## De pagina Netwerk

De pagina Netwerk heeft vijf tabbladen: **🗺️ Overzicht**, **📋 Apparaten**, **🔍 Ontdekken**, **⚡ Netwerksnelheid** (zie [Netwerksnelheid](#netwerksnelheid)) en **🛣️ Paden** (de pad-checks, zie [Linux-agent](05-linux-agent.md)). RzSense onthoudt (per browser) welk tabblad je laatst open had. Het tabblad Netwerk krijgt een oranje teller voor apparaten die je nog niet beoordeeld hebt; dat aantal staat ook in *Hoe staat het ervoor?*.

### Overzicht

- **Wat zit er op je netwerk**: een teller per soort apparaat, met hoeveel er de laatste 24 uur online waren. Klik op een soort: op de kaart licht die soort op.
- **Per netwerk**: per gescand netwerk een balk met hoeveel apparaten online zijn. Apparaten buiten die netwerken staan onder *Andere netwerken*.
- **Verkeer vandaag** en **Internetlijn deze maand**: zie [Verkeer](#verkeer-per-apparaat).
- **De kaart**: bovenaan het internet en je **router** (de standaardgateway van de RzSense-server). Daaronder je **switches** en **access points**, elk met de apparaten die eraan hangen en hoeveel er online zijn; bij een access point ook de wifi-netwerken.

Op de kaart is elk apparaat een bolletje:

| Teken | Betekenis |
|---|---|
| groen | online |
| grijs | 24 uur niet gezien |
| blauwe stip | nieuw, nog niet beoordeeld |
| rode stip | riskante poort open |
| groene ring | staat in RzSense |
| blauwe ring | meer dan 1 GB verkeer in 24 uur |

Ga op een bolletje staan voor naam, IP-adres en fabrikant; klik voor alle details.

Aan welk access point of welke switch een apparaat hangt, komt uit **UniFi**. Bedrade apparaten waarvan de switch niet bekend is, staan onder *Bedraad*; wat UniFi niet kent, onder *Verbinding onbekend*. Zonder UniFi staat alles in één blok onder je router.

### Apparaten

- **Tellers**: 🆕 nieuw (nog niet beoordeeld), ⚠️ riskante poort, ➕ niet in RzSense en ✔ in RzSense. Klik erop om te filteren.
- **Kerncijfers**: apparaten (gezien in de laatste 24 uur / totaal), nieuw, niet in RzSense, riskante poorten en welke netwerken gescand worden.
- **Zoeken** op naam, IP-adres, MAC-adres of fabrikant, en **groeperen** per soort, per fabrikant of per verbinding (wifi-netwerk of bedraad; dat komt uit UniFi).
- **Per apparaat**: icoon en soort, naam, IP-adres, MAC-adres en fabrikant, open poorten (riskante in rood), het verkeer van 24 uur, wanneer gezien en sinds wanneer. **🎭 privé** betekent een willekeurig MAC-adres: telefoons en laptops gebruiken per wifi-netwerk zo'n adres, en soms wisselt het.
- **Knoppen** per apparaat: **＋ RzSense** (opent *Server toevoegen* met naam, adres en soort al ingevuld), **✔ Bekend** en **🙈 Negeren**.
- **✔ Alle nieuwe bekend** keurt in één keer alles goed; handig na de eerste scan.
- **🔍 Nu scannen** doet meteen een snelle en een diepere ronde.

Klik op een apparaat voor alle details: DNS-naam, NetBIOS, mDNS, UPnP, webpagina, eerdere IP-adressen, het verkeer en de domeinen waarmee het praat. Daar geef je het ook een **eigen naam**, een **soort**, een **notitie** of een **status**. **Vergeten** haalt het apparaat weg; is het er nog, dan komt het bij de volgende scan terug als nieuw.

### Riskante poorten

Deze open poorten vragen aandacht. In het venster van het apparaat staat waarom, en wat je eraan doet.

| Poort | Waarom |
|---|---|
| FTP (21) | wachtwoorden gaan onversleuteld over het netwerk |
| Telnet (23) | onversleuteld en vaak met een standaardwachtwoord; geliefd bij botnets |
| Docker-API zonder TLS (2375) | wie erbij kan, is root op die machine |
| RDP (3389) | alleen openzetten als het nodig is, nooit naar internet |
| VNC (5900) | vaak zonder of met een zwak wachtwoord |
| Redis (6379), Elasticsearch (9200), Memcached (11211), MongoDB (27017) | standaard of soms zonder wachtwoord |

### Ontdekken

Ontdekken laat zien wat er verandert op je netwerk.

**🆕 Nieuw en onbekend**: alles wat je nog niet als bekend of genegeerd markeerde, nieuwste bovenaan, met een staafje per dag voor de laatste 30 dagen. Met **✔ Bekend** of **🙈 Negeren** ben je klaar.

**🔄 Veranderingen** van de laatste 30 dagen (klik op een soort om te filteren):

| Verandering | Wat het betekent |
|---|---|
| 🆕 Nieuw apparaat | voor het eerst gezien (niet bij de allereerste scan, want dan is alles nieuw) |
| 🔁 Ander IP-adres | hetzelfde MAC-adres kreeg een ander IP-adres |
| 🚪 Nieuwe poort open | de diepere scan vond een poort die eerder dicht was; ⚠️ bij een riskante poort |
| 🔒 Poort dicht | een poort die open was, is dicht |
| 🌐 Praat met nieuwe domeinen | alleen met AdGuard Home: RzSense leert eerst 7 dagen met welke hoofddomeinen een apparaat praat (`cdn.voorbeeld.nl` telt als `voorbeeld.nl`) en meldt daarna elk nieuw hoofddomein. Lokale namen (`.local`, `.lan`, `.home`) tellen niet mee |
| 💤 Al dagen weg | 3 dagen niet gezien. Alleen voor apparaten met een vast MAC-adres die je niet negeert |
| 👋 Weer terug | een apparaat dat weg was, is er weer |
| 📈 Ongewoon veel verkeer | zie [Ongewoon verkeer](#ongewoon-verkeer) |
| 🌙 Verkeer 's nachts | zie [Ongewoon verkeer](#ongewoon-verkeer) |

**💡 Voorstellen**: apparaten die je waarschijnlijk wilt bewaken (router, switch of access point, server, NAS, printer, camera, smart home, IoT en media), die nog niet in RzSense staan, die je niet negeert en die de laatste 7 dagen gezien zijn. Telefoons en laptops komen hier alleen als ze een dienst aanbieden.

- Per apparaat staan de checks die passen bij de open poorten: **Ping**, **Web** (https gaat voor http; het certificaat wordt niet gecontroleerd, want apparaten hebben bijna altijd een eigen certificaat), beheerpagina's (5000/5001, Proxmox 8006), **Home Assistant** (8123), **Plex**, en poortchecks voor SSH, DNS, SMB, AFP, RTSP (camerabeeld), IPP en 9100 (printen), MQTT, MariaDB en PostgreSQL. De eerste drie staan aangevinkt.
- **＋ In RzSense met deze checks** (beheerders) zet het apparaat met één klik in RzSense: als server zonder agent, met het IP-adres, de juiste soort en de gekozen checks. Het apparaat wordt dan *bekend*. **✎ Zelf instellen** opent het gewone formulier.
- Bij een router, switch, NAS of printer staat een tip om daarna **SNMP** aan te zetten. Zie [Servers en checks](04-servers-en-checks.md).

**🕒 Aanwezigheid**: per apparaat een blokje per uur over de laatste 7 dagen (groen = gezien in een scan van dat uur), het percentage van de tijd dat het online was, en 🌙 als het ook 's nachts (0–6 uur) online was. Hoe donkerder het blokje, hoe meer verkeer in dat uur. Filter op soort, bijvoorbeeld alleen telefoons. Handig om te zien wie er thuis is, of wat er 's nachts aan staat. Met een snelle ronde per uur zie je per uur of een apparaat er was.

Veranderingen worden 90 dagen bewaard, de aanwezigheid 30 dagen.

### Meldingen

- Een nieuw apparaat geeft de melding *Nieuw apparaat op je netwerk*, standaard alleen op de meldingenpagina. Komen er meer dan 5 tegelijk, dan krijg je één bericht. De **eerste scan is de basismeting** en geeft geen meldingen.
- Wil je het via Pushover, e-mail of een ander kanaal, zet het dan aan bij *Instellingen → Meldingen en rapporten → Systeemmeldingen → Nieuw apparaat op het netwerk*. Zie [Meldingen](03-meldingen.md).

## UniFi en AdGuard Home koppelen

Met UniFi en AdGuard Home weet RzSense veel meer over je apparaten: de namen die je zelf gaf, aan welk access point of welke switch ze hangen, het verkeer, en met welke domeinen ze praten. Beide koppelingen zijn optioneel.

### UniFi

Werkt met de UniFi Network-app op een UniFi OS-console (zoals een UDM, UDR, Cloud Gateway of Cloud Key) of zelf gehost.

1. Maak in UniFi een **lokaal account** voor RzSense: *Admins & Users → Add admin → Restrict to local access only*, met beheerrechten voor Network en zonder 2FA.
2. Ga in RzSense naar *Instellingen → Netwerk → UniFi*.
3. Vul het **adres van de controller** in. Bij een UniFi OS-console is dat het IP-adres met https, zonder poort (bijvoorbeeld `https://192.168.2.1`). Bij een zelf gehoste controller meestal met poort 8443 (bijvoorbeeld `https://unifi:8443`).
4. Vul de **site** in (meestal `default`) en de gebruikersnaam en het wachtwoord van het lokale account.
5. Laat **Certificaat controleren** uit, tenzij je de console een geldig certificaat gaf.
6. Klik **🧪 Verbinding testen**: je ziet of het werkt, hoeveel clients UniFi kent en of weren kan.

Alleen lezen kan ook met een **API-sleutel** (*Settings → Control Plane → Integrations*, Network 9 of nieuwer). Dan kan RzSense niet weren en geen verkeer per apparaat tellen.

RzSense haalt elke 5 minuten de clientlijst op: de **naam** die je in UniFi gaf, het **wifi-netwerk** (SSID), het **access point** of de switchpoort, en of het apparaat bedraad is. Een telefoon die slaapt en niet op ping antwoordt, staat er via UniFi toch in.

### AdGuard Home

1. Ga naar *Instellingen → Netwerk → AdGuard Home*.
2. Vul **alle** adressen in, één per regel, met de poort van de webinterface (vaak 3000 of 80). Heb je meerdere instanties (bijvoorbeeld met adguardhome-sync), vul ze dan allemaal in.
3. Vul de gebruikersnaam en het wachtwoord van de webinterface in. Bij meerdere instanties moeten die overal hetzelfde zijn. Tip: maak in AdGuard Home een eigen gebruiker voor RzSense, dan zie je in de log wat RzSense deed.
4. Klik **🧪 Verbinding testen**.

RzSense gebruikt dan:

- de **namen** van je clients (eigen clients en wat AdGuard zelf vond);
- **🌐 met welke domeinen een apparaat praat**: klap het open in het venster van een apparaat. Je ziet de meest opgevraagde domeinen (uit de query-log van alle instanties samen), hoe vaak, en welke geblokkeerd werden (⛔). Handig om te zien of een slim apparaat "naar huis belt".

## Een apparaat weren

Vertrouw je een apparaat niet, dan kun je het als beheerder van je netwerk weren.

1. Open het venster van het apparaat en klik **⛔ Weren**.
2. Kies waarmee (een of meer):
   - **📶 UniFi**: het apparaat komt niet meer op je **wifi** (blokkeren op MAC-adres). Een bedraad apparaat haal je zo niet van het netwerk; zet daarvoor de poort op de switch uit.
   - **🌐 AdGuard**: het IP-adres komt op de lijst *Niet toegestane clients* van alle AdGuard-instanties. Geen DNS meer, dus geen internet via namen.
   - **🛡️ RzSense en je servers**: geen toegang meer tot RzSense, en een blokkade op al je servers met agent (als het IDS op *blokkeren* staat; anders alleen vastgelegd).
3. Kies hoe lang: **1 dag**, **7 dagen** of **Tot ik het weer toelaat**, en eventueel een reden.
4. Bevestig met je wachtwoord.

**✅ Weer toelaten** draait alles terug. Is de tijd om, dan doet RzSense dat vanzelf. Elk weren en toelaten komt in het logboek en geeft een melding (*Systeemmeldingen → IP geblokkeerd* en *Blokkade opgeheven*).

**Nooit te weren**: het apparaat waarmee je nu werkt, de RzSense-server, je router, de UniFi-controller en de AdGuard-servers, servers die in RzSense staan, en adressen op de whitelist.

**Let op**: een telefoon met een **privé-adres** kan een nieuw MAC-adres nemen en zo weer op het wifi komen. Een apparaat met een eigen DNS-server of een vast IP-adres komt langs AdGuard.

## Verkeer per apparaat

RzSense kan bijhouden hoeveel elk apparaat downloadt en uploadt: per uur (30 dagen bewaard) en per dag (ruim een jaar).

### Aanzetten

Zet bij *Instellingen → Netwerk → Netwerkscan* **Verkeer per apparaat bijhouden** aan (standaard aan). De netwerkscan moet aan staan, en RzSense heeft minstens één bron nodig:

| Bron | Wat nodig is | Hoe het werkt |
|---|---|---|
| **UniFi** (beste) | UniFi gekoppeld met een lokaal account (niet alleen een API-sleutel) | elke 5 minuten de bytetellers van alle verbonden clients |
| **SNMP op een switch** | SNMP aan bij de switch in RzSense, met als soort *switch*, *router*, *access point* of *firewall* | elke 5 minuten de MAC-adressen per poort en de tellers van de poorten. Zit er op een poort precies één apparaat, dan is dat het verkeer van dat apparaat. Poorten met meer apparaten (uplinks, een switch erachter) tellen niet mee |
| **De agent op een server** | de Linux-agent op die server | de tellers van de echte netwerkkaarten (plus bond en wifi; geen virtuele kaarten zoals docker0, veth of bridges) |

Het apparaat moet in de netwerkscan staan (met MAC-adres). UniFi gaat voor, dan SNMP, dan de agent. Een bron met minder voorrang telt pas mee als de betere bron het apparaat al 15 minuten niet meer zag. Onder de grafieken staat welke bron het verkeer leverde.

**Download en upload** zijn gezien vanaf het apparaat: download is wat het ontvangt, upload wat het verstuurt. Telt jouw controller het andersom (bijvoorbeeld een camera die ineens veel *download*), zet dan **Download en upload omdraaien** aan.

### Waar je het ziet

- *Netwerk → Overzicht → Verkeer vandaag*: het totaal van vandaag, vergeleken met gisteren tot hetzelfde uur, en een top 10 van de laatste 24 uur. Op de kaart staat onder elk apparaat het verkeer van 24 uur.
- *Netwerk → Apparaten*: de kolom *Verkeer 24 u* met een staafje per uur. Klik op de kolomkop om te sorteren.
- *Het venster van een apparaat*: een grafiek per uur (7 dagen, blauw = download, oranje = upload), staafjes per dag (30 dagen), de snelheid van nu, wat normaal is (de mediaan van de laatste 14 dagen, na 7 dagen) en ⚠️ als het vandaag 5× zoveel is.
- *Netwerk → Ontdekken → Aanwezigheid*: hoe donkerder het blokje, hoe meer verkeer in dat uur.

### Diensten (UniFi DPI)

In het venster van een apparaat klap je **Diensten (UniFi DPI)** open voor de verdeling van het verkeer over soorten diensten (streaming, web, updates en beveiliging, sociale media, VPN en tunnels, games …), sinds het apparaat verbonden is. Daarvoor moet in UniFi *Traffic Identification* aan staan. UniFi geeft geen namen van apps door; RzSense laat de soort zien.

### Ongewoon verkeer

Elke nacht kijkt RzSense naar de dag ervoor, zodra er minstens 7 dagen gegevens zijn:

- **📈 Ongewoon veel verkeer**: op een dag minstens 5× zoveel upload of download als normaal, en minstens 300 MB;
- **🌙 Verkeer 's nachts**: minstens 50 MB tussen 0 en 6 uur, terwijl het apparaat 's nachts normaal (bijna) stil is.

Ze staan in *Ontdekken → Veranderingen*, en komen ook als melding (*Instellingen → Meldingen en rapporten → Systeemmeldingen → Ongewoon netwerkverkeer*, standaard alleen op de meldingenpagina). Zet daar Pushover of e-mail aan als je ze meteen wilt weten.

### Goed om te weten

- RzSense telt wat UniFi ziet. Verkeer tussen twee apparaten op dezelfde switch ziet UniFi maar voor een deel; het verkeer naar internet klopt het best.
- Verbindt een apparaat opnieuw, dan begint zijn teller bij nul; RzSense telt gewoon verder. Een onmogelijk grote sprong (meer dan 10 Gbit/s) wordt genegeerd.

## Je internetlijn

### Internetlijn deze maand

Op *Netwerk → Overzicht* staat naast *Verkeer vandaag* het verbruik van je internetlijn deze maand: download en upload, een staafje per dag, en wat je aan het eind van de maand waarschijnlijk gebruikt hebt. RzSense haalt dat elk uur op uit het dagrapport van je UniFi-gateway. Zonder UniFi (of als dat niet lukt) is het de som van alle apparaten die RzSense telt; dat staat er dan onder.

### Datalimiet

Heb je een abonnement met een limiet, vul die dan in bij *Instellingen → Netwerk → Netwerkscan → Datalimiet van je internetabonnement* (GB per maand, download en upload samen). Er komt een balk bij, met een streepje waar je naar verwachting uitkomt. Bij **80%** en bij **100%** krijg je één keer per maand een melding (*Systeemmeldingen → Datalimiet internet bijna of helemaal op*).

## Verkeer als check en in een rapport

### De check Verkeer (UniFi)

Wil je een melding als één apparaat te veel verkeer doet, voeg dan een check toe:

1. Ga bij een server naar **＋ Check** en kies *Verkeer (UniFi)*.
2. Vul het MAC- of IP-adres van het apparaat in. Leeg is het adres van de server zelf (handig als het apparaat zelf in RzSense staat).
3. Kies wat je meet en stel de drempels in.

| Meten | Eenheid | Voorbeeld |
|---|---|---|
| Snelheid nu (download + upload) | Mbit/s | warning boven 50, critical boven 200 |
| Upload nu | Mbit/s | een camera die meer dan 10 Mbit/s verstuurt |
| Vandaag (download + upload) | GB | een tv die meer dan 20 GB per dag doet |
| Upload vandaag | GB | een slim apparaat dat meer dan 2 GB per dag verstuurt |

*Status bevestigen* en *Melden na N* werken zoals bij elke check (zie [Servers en checks](04-servers-en-checks.md)). Staat het apparaat niet in de netwerkscan, of is het niet verbonden, dan is de check onbekend.

### Rapport Netwerkverkeer

Bij *Instellingen → Meldingen en rapporten → Rapporten* zet je het rapport **Netwerkverkeer** aan; je kunt het ook aanvinken in het Maandoverzicht. Het toont het verkeer van je internetlijn en de top 10 apparaten over de periode, met de verandering ten opzichte van de periode ervoor (+100% of meer is gemarkeerd). Zie [Meldingen](03-meldingen.md).

## Netwerksnelheid

Op *Netwerk → ⚡ Netwerksnelheid* zie je in één keten hoe snel je netwerk en je internetlijn zijn: van RzSense (of een
andere bron) tot het doel, in de stijl van een traceroute. Bovenaan kies je de **route**, en of je het verloop van de
laatste **7 of 30 dagen** wilt zien.

### Wat je ziet

- **Vier tegels:** de download en upload van je internetlijn (in procent van je abonnement), de ping naar het doel
  (met jitter en verlies) en de gemeten doorvoer in je eigen netwerk (iperf3).
- **De keten:** elk knooppunt (de bron, een switch, de router, de provider, het doel) met de lijn naar het volgende.
  - **Boven de lijn:** de vertraging tot dat punt.
  - **Onder de lijn:** de linksnelheid (bijvoorbeeld *1 Gbit*), wat er echt gemeten is, fouten op de poort, waar het
    getal vandaan komt (*agent*, *SNMP*, *UniFi*, *snelheidstest*) en een lijntje met het verloop.
  - **Kleur:** groen is goed. Oranje is de smalste schakel, of een poort die op een lagere snelheid draait dan eerder
    (vaak een slechte kabel). Een grijze stippellijn betekent dat de snelheid van die lijn niet bekend is.
- **Smalste schakel:** één zin met de traagste schakel van de route. Is dat je internetlijn en haal je wat je abonnement
  belooft, dan staat erbij dat het niet aan je eigen netwerk ligt.
- **Tips:** wat RzSense nog mist, bijvoorbeeld mtr op de RzSense-server of SNMP op de router.

### Waar de snelheden vandaan komen

| Wat | Bron |
|---|---|
| Netwerkkaart van de bron, vertraging per hop | De agent (mtr of traceroute), of RzSense zelf als de bron RzSense is |
| Switchpoorten en de WAN-poort van de router | **SNMP** (het apparaat staat in RzSense met SNMP aan) of **UniFi** |
| Doorvoer in je netwerk | **iperf3**: de agent op het doel start kort een ontvanger |
| Internetlijn | De snelheidstest van de check **Internet** (zie [Internet](04-servers-en-checks.md#internet)) |

Met SNMP zoekt RzSense in de MAC-tabel van je switches op welke poort de bron zit. Zo komt een switch in de keten, ook
al zie je hem niet in een traceroute. De WAN-poort van de router herkent RzSense aan de naam (*wan*, *ppp*, *dsl* …);
lukt dat niet, dan kies je hem bij *Routes beheren*. De snelheid van de internetlijn vergelijkt RzSense met je
abonnement (*Instellingen → Netwerk → Internetcheck*).

### Routes

Standaard is er één route: **RzSense → Internet**. Beheerders maken meer routes met **⚙️ Routes beheren**:

1. Kies **Van**: RzSense of een server met de agent.
2. Kies **Naar**: internet, een server in RzSense of een IP-adres of naam.
3. Bij een server kun je **Doorvoer meten** aanzetten (elk uur, elke 6 uur of elke dag). Zet bij die server *Mag
   doorvoertest ontvangen* aan; ontbreekt iperf3, dan installeert de agent het zelf (zie de pad-check bij
   [Linux-agent](05-linux-agent.md)).
4. Geef de route eventueel een naam en klik **Opslaan**.

Vanaf een server met de agent (ook als de agent op de RzSense-server zelf draait) maakt RzSense voor de route een
**pad-check** met de naam *Netwerksnelheid → …*. Die check is alleen informatief: hij meldt zelf niets. Meet RzSense zelf
(zonder agent op de RzSense-server), dan gebruikt het mtr of traceroute als die op de server staan; anders zie je alleen
de router en het doel. Voor een doorvoertest vanaf RzSense moet iperf3 op de RzSense-server staan.

**▶ Nu meten** (alleen beheerders) meet het pad meteen, vraagt een doorvoertest aan en doet bij een route naar internet
een snelheidstest. Die gebruikt je lijn even helemaal (± 15 seconden).

### Wifi per access point

Onder de keten staat de wifi, per access point:

- **Zonder UniFi** meldt elke laptop of pc met de agent (Linux of Windows) zijn eigen wifi: het wifi-netwerk, de band,
  het kanaal, de verbindingssnelheid en het signaal. RzSense groepeert ze per access point. Op Linux is daarvoor `iw`
  nodig. Heeft een apparaat een wifi-kaart maar geen `iw`, dan laat RzSense de agent het zelf installeren (via apt of
  dnf, zoals iperf3), als *commando's als root* aan staan voor dat apparaat. Lukt dat niet (bijvoorbeeld op TrueNAS of
  Home Assistant), dan zie je dat onder de wifi, met hoe je het zelf installeert (`sudo apt install iw` of
  `sudo dnf install iw`).
- **Met UniFi** zie je alle access points, met hoe ze aan het netwerk hangen (kabel of mesh, met de snelheid), en per
  apparaat de snelheid en het signaal. De zwakste staan bovenaan.

Een signaal zwakker dan −72 dBm krijgt *zwak signaal*, zwakker dan −80 dBm *slecht signaal*. Heeft het apparaat een
route met een doorvoertest, dan staat de gemeten doorvoer erbij.

### Melding

Een melding is niet nodig om het overzicht te gebruiken, maar je kunt hem per route aanzetten bij *Routes beheren →
Melding*. Je krijgt dan één melding als een link terugvalt, het doel onbereikbaar is of de smalste schakel onder de
grens zakt die je invulde, en één melding als het weer in orde is. De melding gaat via je gewone kanalen; welke dat
zijn, stel je in bij *Instellingen → Meldingen en rapporten → Systeemmeldingen → Netwerksnelheid*.

### Op het dashboard

Op het dashboard staat de tegel **Netwerksnelheid**: download en upload van je internetlijn en de ping, of hoeveel
aandachtspunten er zijn. Klik erop om naar de pagina te gaan.

## Zie ook

- [Home Assistant](07-home-assistant.md): de netwerkkaart als kaart op je Home Assistant-dashboard.
- [Linux-agent](05-linux-agent.md#netwerken-en-relays): servers in een ander netwerk laten rapporteren via een relay, en de pad-check (Netwerk → Paden).
- [Servers en checks](04-servers-en-checks.md): SNMP op switches, routers en access points.
- [Beveiliging](08-beveiliging.md): de DNS-blokkadelijst voor AdGuard Home en het IDS.
