# De interface

Wat staat hier: wat RzSense is, en een rondleiding door de schermen. Je leest hoe het menu is ingedeeld, wat er op het
dashboard en op Monitoring staat, hoe je met incidenten, analyse, trends en AI-advies werkt, en hoe je RzSense naar je
eigen smaak instelt: lay-out, thema, licht/donker, beweging en taal. Hoe je meldingen, checks, de agents en de beveiliging
instelt, staat in de andere hoofdstukken; daar verwijzen we naar.

## Wat is RzSense?

RzSense (voluit *Rz//Sense AI*) bewaakt je servers, netwerkapparaten en Home Assistant, en let op aanvallen. Elke minuut
draaien de checks. Gaat er iets mis, dan krijg je een melding op je telefoon, per e-mail of in Home Assistant. Je draait
het zelf, op een Linux-machine met PHP 8 en MariaDB: een VM, een Raspberry Pi of een NAS met containers.

RzSense bestaat uit deze onderdelen:

| Onderdeel | Wat het doet |
|---|---|
| **Webapplicatie** | De interface die je in je browser opent: dashboard, monitoring, beveiliging en instellingen |
| **Cron-runner** | Voert elke minuut de checks uit en verstuurt de meldingen |
| **Linux-agent** | Een klein script op je Linux-servers dat CPU, RAM, disks, services, poorten en beveiliging doorgeeft. Zie [Linux-agent](05-linux-agent.md) |
| **Windows-agent** | Hetzelfde voor Windows. Zie [Windows-agent](06-windows-agent.md) |
| **Home Assistant-add-on** | De Linux-agent als add-on voor Home Assistant OS. Zie [Home Assistant](07-home-assistant.md) |

De technische naam is nog steeds `rzmon`: de map, de database, de service `rzmon-agent` en het bestand
`/etc/default/rzmon-agent` heten zo.

## De indeling van het menu

RzSense heeft twee menu's: het **hoofdmenu** voor dagelijks werk, en de **beheerportal** *Instellingen* om dingen in te
stellen.

### Het hoofdmenu

Op een breed scherm staat het hoofdmenu links in de zijbalk. Op een smaller scherm staan de pagina's bovenaan, op een
telefoon in een balk onderaan het scherm (met *Meer* voor de rest).

| Groep | Pagina's |
|---|---|
| **Overzicht** | **Dashboard** (de startpagina) · **Meldingen** |
| **Monitoring** | **Monitoring** (al je servers en checks) · **Servers** (de Agent Manager, alleen beheerders) |
| **Beveiliging** | **Incidenten** · **IDS** · **Kwetsbaarheden** · **Updates** · **Reverse proxy** (alleen beheerders) |
| **Netwerk** | **Netwerk** (netwerkscan, apparaten en de netwerkkaart) |
| **Analyse** | **Analyse** · **Logs** · **Logboek** · **Compliance** (NIS2, alleen beheerders) |

Wat je ziet hangt af van je rol en van wat aan staat. Met de rol *alleen lezen* zie je onder meer geen Incidenten,
Analyse, Kwetsbaarheden en Netwerk. Zie [Toegang en beheer](11-toegang-en-beheer.md) voor de rollen.

**Tabbladen in de zijbalk.** Veel pagina's hebben tabbladen (bijvoorbeeld *IDS › Live, Blokkades, Aanvalskaart*). Op
een breed scherm staan die als subpagina's in de zijbalk, onder de pagina zelf, met hun tellers. Alleen de pagina waar je
bent klapt open; met ▸ klap je een andere open. Op een telefoon staan de tabbladen bovenaan de pagina.

**Tellers.** Op een menu-item staat een bolletje met een getal als er iets nieuws is. Bij *Monitoring* is dat het aantal
nieuwe problemen sinds je de pagina voor het laatst zag, bij *Meldingen* het aantal nieuwe meldingen. Open je de pagina,
dan gaat de teller naar 0. RzSense onthoudt dat per gebruiker, dus ook op je andere apparaten. Het totaal staat ook in
de titel van het browsertabblad, bijvoorbeeld *(3) RzSense*.

**Statuslampje.** Onder het logo in de zijbalk zie je met één lampje hoe het ervoor staat. Klik erop en je gaat naar
Monitoring.

### De zijbalk aanpassen

- **Inklappen:** met *Inklappen* onderaan wordt de zijbalk smal: alleen iconen, met de tellers erbij. Wijs een icoon aan
  voor de naam en de subpagina's. *Uitklappen* maakt hem weer breed.
- **Breedte:** sleep de rechterrand naar links of rechts (180 tot 380 pixels). Sleep je smaller dan 150 pixels, dan
  klapt hij in tot iconen. Dubbelklik op de rand zet de gewone breedte terug. Met het toetsenbord: zet de focus op de
  rand en gebruik ← en →.
- **Smal venster:** in een venster van ongeveer 1100 tot 1280 pixels breed (een laptop, een half scherm) staat de
  zijbalk vanzelf op iconen. Wordt het venster breder, dan krijg je je eigen keuze terug.

RzSense onthoudt de stand en de breedte voor je account.

### De beheerportal Instellingen

Klik onderaan de zijbalk op **Instellingen** (of kies het in het accountmenu). De zijbalk wordt dan het menu van de
instellingen, met bovenaan **← Terug naar RzSense**. Op een telefoon is het menu een keuzelijst bovenaan.

| Groep | Onderdelen |
|---|---|
| **Overzicht** | Status en functies · Achtergrondtaken |
| **Monitoring** | Alle checks · Sjablonen · Slimme drempels en advies · Internetcheck · Logs verzamelen |
| **Meldingen en rapporten** | Kanalen · Wanneer melden · IDS-pushmeldingen · Beveiligingskanaal · Systeemmeldingen · Rapporten |
| **Toegang tot RzSense** | Inloggen · Beheer van buiten · Gebruikers |
| **Bescherming** | IDS algemeen · Beschermingen · Endpoint-bewaking (EDR) · Dreigingsinformatie · DNS, Suricata en YARA · Ransomware · Programma's · Jagen op sporen · Netwerk en weblogs · IDS per server · Reverse proxy en WAF · Van buitenaf · Kwetsbaarheden en datalekken · Updatebeleid · E-mailbeveiliging · NIS2 · Netwerkscan |
| **Agents en koppelingen** | Standaard voor alle agents · Netwerken en relays · Tijdelijk sudo · Commando-checks · UniFi · AdGuard Home · SNMP · Home Assistant (MQTT) |
| **Systeem** | Weergave · Bijwerken · Back-up · Reserve-RzSense · Database en server · Prestaties en fouten · AI-advies · REST API · Statuspagina · Exporteren en importeren |

Beheerders zien alles. Gewone gebruikers zien alleen *Status en functies*, *Rapporten* en een deel van *Slimme drempels
en advies*, om te bekijken.

**Zo werkt de beheerportal:**

- **Status** (het eerste onderdeel) geeft een score van 0 tot 100% en een lijst: ✔ groen is in orde, **!** oranje is
  een aandachtspunt, ✘ rood moet je regelen, grijs is optioneel. RzSense kijkt onder meer naar een meldkanaal,
  automatische back-up, tweestapsverificatie voor beheerders, inlogbeveiliging, een nieuwe versie, oude of onversleutelde
  agents, HTTPS, en of kwetsbaarheden en IDS aan staan. Klik op een punt en je staat bij de instelling.
- **Bolletjes:** een onderdeel met een aandachtspunt krijgt een oranje of rood bolletje in het menu. Open je het, dan
  staat bovenaan een balk **Aandacht nodig** met elk punt en, waar het kan, de knop om het op te lossen. Ook de kaart waar
  het over gaat heeft een bolletje bij de titel. Is het opgelost, dan verdwijnen ze vanzelf.
- **Zoek een instelling…** bovenaan het menu zoekt in de namen van de onderdelen en in alle velden, schakelaars en
  koppen. Met **Enter** spring je naar het veld; het licht even op en een ingeklapt *Geavanceerd* gaat open. **Esc** wist
  de zoekterm.
- **Alles wordt meteen opgeslagen:** schakelaars en keuzelijsten direct, tekstvelden zodra je ze verlaat of op Enter
  drukt. Naast het veld staat dan kort **✔ opgeslagen**. Klopt een waarde niet, dan staat er **✘ niet opgeslagen** met
  de reden. Gevoelige dingen (commando's als root, IP-adressen blokkeren, een nieuwe sleutel) vragen om je wachtwoord.
- **Uitleg:** onder elke titel staat één regel uitleg met **Meer info** voor de volledige uitleg. Achter velden en
  schakelaars staat een **?**: wijs hem aan voor een korte uitleg, klik of tik voor alles (eenheid, bereik, aanbevolen
  waarde).
- **Geavanceerd:** zelden gebruikte opties staan ingeklapt onder **▸ Geavanceerd**.
- **Elk onderdeel heeft een eigen adres**, bijvoorbeeld `#settings/backup`. RzSense onthoudt waar je was. Oude links
  sturen vanzelf door naar de nieuwe plek.

Bij *Meldingen en rapporten* heeft elke kaart een rustige opbouw: een klein label met het onderwerp (bijvoorbeeld *PUSH ·
TELEFOON*), de titel met de hoofdschakelaar rechts, de velden naast elkaar en onderaan *Geavanceerd* en de actieknop. Bij
*Kanalen* staat bovenaan een overzicht van alle kanalen met aan/uit.

## Het dashboard

Het **Dashboard** is de pagina waarop RzSense opent: monitoring, beveiliging, kwetsbaarheden en netwerk op één plek,
onder de titel *Zo staat het ervoor*. Welk dashboard je ziet, hangt af van je lay-out: met *Default* het rustige
dashboard, met *L1nes* of *Compact* het klassieke dashboard, met *CC* het Command center (zie [Lay-out](#lay-out)).

### Het rustige dashboard (Default)

Met de lay-out *Default* is het dashboard kaal en rustig:

1. **Eén zin** bovenaan zegt hoe het ervoor staat, bijvoorbeeld *Bijna alles draait. 3 dingen vragen aandacht.* of
   *Alles draait. Er is niets te doen.* Daaronder de datum.
2. **Vier cijfers in één strook**: *Servers online*, *Open problemen*, *Geblokkeerd vandaag* (door de IDS) en
   *Beschikbaarheid* over de gekozen periode. Klik op een cijfer voor de pagina erachter.
3. **Aandacht nodig**: wat je nu moet doen, het ergste eerst. Klik op een regel om hem te openen.
4. **Servers**: elke server als een rij met zijn status, de soort apparaat, CPU en geheugen. Wat aandacht vraagt staat
   bovenaan. Klik op een server om hem op *Monitoring* te zien.

Het dashboard wordt live bijgewerkt zonder dat de pagina opnieuw opbouwt: alleen wat verandert, verandert.

### Het klassieke dashboard

Van boven naar beneden:

1. **Statuszin met de totaalscore**, bijvoorbeeld *3 dingen om nu te doen (1 dringend) · 2 open incidenten · 1 server
   vraagt aandacht*, of *Alles in orde: 12 servers en 140 checks zonder problemen*.
2. **Tegels**: *Hosts* (hoeveel bereikbaar), *Meldingen* (open problemen), *Kritieke CVE* (met hoeveel daarvan actief
   misbruikt worden, KEV) en *Agents* (hoeveel rapporteren, hoeveel stil of op een oudere versie). Klik op een tegel en je
   staat op die pagina.
3. **Nu doen**: de open incidenten en de adviezen uit Analyse in één lijst, op urgentie. Per regel de reden (bijvoorbeeld
   *al 3 uur · bereikbaar van buiten*), de status en de eigenaar. **Openen** brengt je naar het incident of de juiste
   pagina.
4. **Servers die aandacht vragen**: servers met een probleem of met een risico van 40 of meer, met CPU, RAM, disk en de
   redenen van het risico.
5. Daarna: resourcegebruik over 24 uur, recente IDS-incidenten, de netwerkkaart (internet → router → switches en access
   points → apparaten per soort), de overige servers, grafieken van beschikbaarheid, IDS en kwetsbaarheden, en de laatste
   gebeurtenissen.

Rechtsboven kies je de periode (7, 30 of 90 dagen). Het lampje **● Live** laat zien dat de pagina live meeloopt; het
wordt grijs als de verbinding even wegvalt.

**Onbekend is nooit groen.** Een server zonder gegevens (of helemaal in onderhoud) krijgt een grijs ringetje, op de
netwerkkaart met een gestippelde rand.

### Urgentie en risico

Twee getallen komen overal terug, steeds op dezelfde manier berekend. Wijs ze aan voor de uitleg.

**Urgentie (0–100)**, bij *Nu doen* en bij Incidenten: ernst (critical 50, warning 25, tip 5) + hoe lang al (tot 15) +
bereikbaar van buiten (+10) + servers die ervan afhangen (+3 per server, tot 10) + beveiliging (IDS of een actief
misbruikte kwetsbaarheid, +15).

**Risico per server (0–100)**, op het dashboard, in de [REST API](11-toegang-en-beheer.md) en als sensor in Home
Assistant:

| Onderdeel | Punten |
|---|---|
| Open incidenten | 15 per critical, 5 per warning (tot 35) |
| Kwetsbaarheden | de hoogste risicoscore × 0,3 als het pakket op het netwerk luistert, anders × 0,15 (tot 30) |
| Hardening | (100 − hardening-score) × 0,15 (tot 15) |
| Agent | stil 10, onversleuteld 5 |
| Afwijkingen (IDS, 7 dagen) | 2 per afwijking (tot 10) |

Vanaf 40 is het oranje, vanaf 70 rood.

### Aanvalspad en impact

Klik op het **risicogetal** van een server op het dashboard, of op **🎯 Aanvalspad** in een incident. Je ziet:

- het pad: van buiten of het netwerk → open poorten → de server → servers die ervan afhangen;
- de open poorten die niet alleen op localhost luisteren, met het programma, het pakket en de ergste kwetsbaarheid
  daarin (met de versie die hem oplost);
- de open incidenten van die server;
- de servers die ervan afhangen: valt deze server om of wordt hij overgenomen, dan raakt dat ook die servers.

Het is een schatting: RzSense kent je firewall niet. Een poort die op alle adressen luistert, kan van buiten toch dicht
zijn.

### Het Command center

Met de lay-out **CC** is het dashboard het **Command center**: alles in één oogopslag, in een futuristische stijl die
meekleurt met licht of donker. De kaarten, in de standaardvolgorde:

1. **Kop**: een begroeting en *sinds je laatste bezoek* (nieuwe kritieke meldingen, meldingen, blokkades en SSH-logins),
   de periode (*Vandaag*, 7, 30 of 90 dagen), *LIVE*, **✎ Indeling** en **▣ TV-modus**.
2. **Overzicht**: de totaalscore als ring met wat je eerst moet doen, en tegels voor Monitoring, Meldingen, IDS,
   Kwetsbaarheden en Agents.
3. **Beveiliging in één oogopslag**: SSH-logins (en hoeveel van een nieuw IP-adres), mislukte SSH-logins,
   SSH-blokkades, IDS-blokkades, hoeveel er is tegengehouden, sudo en su, webaanvallen en verdacht uitgaand verkeer.
   Steeds met het verschil met de periode ervoor (↑ rood = slechter, groen = beter). Klik op een getal voor de lijst.
4. **De toppers**: de top SSH-blokkade en top IDS-blokkade (met *Overal blokkeren*), de grootste aanvaller, de meest
   aangevallen server en het top land.
5. **Nu doen**, **Live aanvallen** (de laatste 15 minuten) en **Wie logt er in** (SSH, console, sudo en su: wie, waar,
   hoe laat, met sleutel of wachtwoord).
6. **Monitoring**: actieve checks, hoe vaak ze draaiden, hoe vaak het fout ging, het percentage gelukt en de meldingen,
   met een grafiek en de checks die het vaakst fout gingen of de meeste meldingen gaven.
7. **Servers** als tegels met drie ringen (CPU, RAM, de volste disk); de rand heeft de kleur van de status.
8. **IDS-gebeurtenissen**, **Beschikbaarheid**, **Netwerk**, **Resourcegebruik · 24 uur**, **Kwetsbaarheden (CVE)** en
   **Laatste gebeurtenissen**.

**Indeling aanpassen:** klik op **✎ Indeling**. Sleep een kaart naar een andere plek of gebruik ↑ ↓. Met **Verbergen**
haal je een kaart weg; in de indeling blijft hij vaag staan, zodat je hem met **Tonen** terughaalt.
**Standaardindeling** zet alles terug. De indeling geldt voor je account.

**TV-modus:** klik op **▣ TV-modus** voor een scherm aan de muur of een tablet: schermvullend, zonder menu, met grotere
letters. Uitzetten met **✕ TV-modus uit** rechtsonder of met **Esc**. Dit wordt per apparaat onthouden: de tablet aan de
muur blijft in TV-modus, je laptop niet.

### Kaarten indelen op andere pagina's

In de lay-out CC kun je ook op andere pagina's de kaarten indelen: Monitoring, Meldingen, IDS, Incidenten,
Kwetsbaarheden, Netwerk, Analyse, de Instellingen, Mijn account en de Agent Manager. Rechtsboven staan **● Live**,
**✎ Indeling** en **▣ TV-modus**. Met *Indeling*:

- sleep je een kaart aan ⋮⋮ naar een andere plek;
- kies je de breedte: ¼, ⅓, ½, ¾ of de hele breedte;
- verberg je met 👁 wat je niet nodig hebt;
- zet **↺ Standaard** alles terug.

Dit wordt per pagina onthouden, voor je account. In de Instellingen heeft elk onderdeel een eigen indeling. Op een
telefoon staan de kaarten onder elkaar, in jouw volgorde.

## Het kruimelpad en het detailpaneel

**Kruimelpad.** Bovenaan elke pagina staat waar je bent, bijvoorbeeld *🏠 › Monitoring › Netwerkpaden* of *🏠 ›
Instellingen › Monitoring › Slimme drempels en advies*. Elk stuk is een link. Staan er filters aan, dan staan die
erachter als knopje (*CRITICAL ✕* of *2 filters ✕*); een klik haalt het filter weg. Duurt het laden van een pagina even,
dan zie je in het kruimelpad een teller (*IDS laden · 0,8 s*).

**Detailpaneel.** Klik je op een item (een server, check, incident, IDS-treffer, apparaat, logregel, container, advies,
melding, regel van het Logboek …), dan openen de details in een **paneel rechts**. Knoppen in een rij (✔ Bevestig, 🔧,
＋) doen meteen wat ze zeggen, zonder paneel.

- In het paneel van een server opent een check in hetzelfde paneel, met bovenin een pad terug.
- **← →** (de knoppen bovenin of de pijltjestoetsen) gaan naar het vorige of volgende item op de pagina.
- **Sluiten:** Esc, ✕ of naast het paneel klikken.
- **Delen:** het adres krijgt `?open=…` (bijvoorbeeld `#monitoring?open=host:3`). Wie die link opent, ziet hetzelfde
  paneel.
- Op een scherm van 1920 pixels of breder staat het paneel naast de lijst, zodat je kunt doorklikken. Op een telefoon
  vult het paneel het scherm.

## De pagina Monitoring

**Monitoring** toont al je servers en checks. Bovenaan staan tabbladen: **Checks** (de gewone pagina), **Containers**
(zie [Linux-agent](05-linux-agent.md)), **Netwerkpaden** (zie [Servers en checks](04-servers-en-checks.md)) en
**Beschikbaarheid** (zie hieronder). Op een telefoon is er ook **mDS**: een compact overzicht van al je servers en
apparaten per soort.

Op het tabblad *Checks*, van boven naar beneden:

- **Tellers** (● OK · ● Warning · ● Critical · ● Onbekend · ● Onderhoud). Ze blijven staan als je scrolt. Klik op een
  teller en je ziet alleen de checks met die status; nog een keer klikken heft het filter op.
- **CPU · RAM · Disk** in dezelfde balk: per soort de **hoogste** waarde van al je servers (bewust niet het gemiddelde,
  dat verbergt die ene server op 95%). Wijs aan voor de top 3, klik om de servers op die waarde te sorteren.
- **De RzSense-kern**: CPU, RAM, disk en cron van de server waarop RzSense zelf draait, met één oordeel. Klik erop voor
  de details.
- **Kerncijfers** als tegels:
  - **De toestand met de score**: 🟢 *Alles in orde*, 🟠 *Aandacht nodig* (alleen warnings of onbekend, of bevestigde
    problemen) of 🔴 *Storing* (een onbevestigde critical of een onbereikbare server). De score (0–100%) is het
    gemiddelde over je servers; per probleem gaat er iets af (critical 25, warning 10, onbekend 5, bevestigd de helft).
    Servers die helemaal in onderhoud staan tellen niet mee.
  - **Servers bereikbaar**, **Checks OK**, **Open problemen** (met het langst openstaande) en **Beschikbaar 24 u**, met
    een lijntje en ▲/▼ ten opzichte van eerder.
- **⚠️ Nu aandacht nodig**: alle open problemen in één lijst, critical eerst, de oudste bovenaan. Per regel de server, de
  check, het bericht, hoe lang al, **✔ Bevestig** en **🔧** (onderhoud). Is een server onbereikbaar of zijn agent stil,
  of heeft hij 4 of meer problemen, dan staan die in één regel (*WebServer01 · agent stil · 10 checks*). Bevestigde
  problemen staan er niet in, wel hoeveel het er zijn.
- **Werkbalk**: zoeken (zoek je op een checknaam, dan klapt de server vanzelf open), **filterchips** per soort apparaat
  (alleen soorten die je hebt, met een rood bolletje bij een open probleem), **sorteren** (*Ergste eerst*, *Op naam*,
  *Hoogste CPU*, *Hoogste RAM*, *Volste disk*), het menu **⋯** met *🔧 Onderhoud* (alle meldingen pauzeren), *↻ Alles nu
  checken* en *⊞ Alles openen*, en **＋ Server**.
- **De servers**, per soort apparaat in drie groepen: *Met open problemen*, **✔ Bevestigd** (alles al bevestigd: je
  weet ervan) en *Alles in orde*.

De filterchip geldt alleen zolang je op de pagina bent. Kom je terug, dan staat hij weer op *Alle*, zodat je nooit een
storing mist. De sortering wordt per gebruiker onthouden.

### Hoe een server eruitziet

Hoe de servers getoond worden, hangt af van je [lay-out](#lay-out):

- **Default**: servers onder elkaar, als rustige rijen in één kader, zonder gekleurde randen. *Large* heeft kolommen voor
  de belangrijkste checks en de status van 24 uur; *Small* is één regel per server.
- **L1nes**: servers onder elkaar, als rijen. *Large* heeft kolommen voor de belangrijkste checks en de status van 24
  uur; *Small* is één regel per server.
- **Compact**: per server één regel met een blokje per check in de kleur van de status. Handig bij veel checks of op een
  TV-scherm. Wijs een blokje aan voor de naam en de waarde.
- **CC**: per groep een raster van tegels, wie aandacht vraagt eerst. Elke tegel heeft een ring (checks in orde van het
  totaal) en de laatste 24 uur. Klik op een tegel en hij klapt open over de volle breedte, met meters, de knoppen,
  *Vraagt aandacht* en *In orde*.

De **drie belangrijkste checks** kiest RzSense zelf: eerst wat stuk is, dan checks met een meetwaarde (internet, CPU,
RAM, de volste disk, responstijd, ping …). Zo heeft ook een router of NAS zonder CPU-check zijn eigen belangrijkste
checks. Onderaan een server staat de **laatste 24 uur** als balkje: een blokje per uur, groen, oranje of rood.

**Checks in groepen.** Klap je een server open en heeft hij 5 of meer checks, dan staan ze onder kopjes: *Systeem*
(CPU, RAM, disks), *Bereikbaarheid* (ping, poort, HTTP), *Services*, *Containers*, *Commando's*, *Logbestanden*,
*Certificaten* en *Home Assistant*. Checks met een probleem staan bovenaan in de groep **⚠️ Problemen**, per status, met
onderaan *✔ Bevestigd*. Klik op een kopje om de groep in of uit te klappen.

**Per check** zie je een minigrafiek van 24 uur. Klik erop voor de grote grafiek over 6 uur tot 30 dagen, met
beschikbaarheid, minimum, gemiddelde, maximum en bij disks de trend. De knoppen per check (✔ 🔧 ↻ 📈 ✎) verschijnen als je
met de muis over de rij gaat; op een telefoon staan ze er altijd.

**Rode gloed.** Een server met een onbevestigde critical pulseert zacht. Uitzetten kan bij *Mijn account → Weergave*.

### Soort apparaat

Elke server heeft een **soort apparaat**: 🖥️ server, 🌐 router, 🔀 switch, 📶 access point, 🧱 firewall, 🗄️ NAS, 🔋 UPS,
📷 camera, 🏠 smart home, 🖨️ printer of 📦 overig. Op Monitoring vallen ze in groepen: **Servers · Netwerk** (router,
switch, access point, firewall) **· Opslag · Stroom · Camera's · Smart home · Overig**.

- **Vanzelf herkend:** een server met de agent is een *server*, de Home Assistant-add-on *smart home*. Met SNMP kijkt
  RzSense naar het apparaat zelf (bijvoorbeeld UniFi = access point, Synology of QNAP = NAS, APC = UPS). Een naam met
  *cam*, *camera*, *IPCam*, *CCTV* of *deurbel* is vanzelf een camera. Zonder agent of SNMP is het *overig*.
- **Zelf kiezen:** *✎ Server bewerken → Soort apparaat*. Je eigen keuze gaat altijd voor.
- Staat je router niet op de netwerkkaart van het dashboard? Geef hem dan de soort *Router* (of *Switch*, *Access
  point*).
- Kies je één groep met de filterchip, dan tellen ook de tellers, de tegels en *Nu aandacht nodig* alleen die groep.

Bij een camera staat de knop **＋ Aanbevolen checks** (ping, RTSP op poort 554 en eventueel de webpagina). Meer over
checks in [Servers en checks](04-servers-en-checks.md).

## Beschikbaarheid per groep

Op **Monitoring → Beschikbaarheid** zie je hoe betrouwbaar je apparaten zijn, per groep.

1. Kies bij **Indelen** *🗂️ Soort apparaat* of *🏷️ Eigen groep*. Een eigen groep geef je een server bij *✎ Server
   bewerken → Groep* (vrije tekst, bijvoorbeeld *Thuis*, *Kantoor* of *Klant A*).
2. Kies de **periode**: 24 uur, 7, 30 of 90 dagen.
3. Kies je **🎯 Doel** (SLA): van 95% tot 99,99%, standaard 99,9%.

RzSense onthoudt je keuzes voor je account. Je ziet:

- **Kerncijfers**: online nu, beschikbaar over de periode, het aantal storingen, de opgetelde downtime en bij hoeveel
  groepen het doel gehaald is.
- **Per groep een kaart** met het percentage, een bolletje per apparaat, of het doel gehaald is en hoeveel tijd je nog
  "over" hebt, het verloop en de langste storing. Met **▸ per server** klapt de kaart open tot een tabel per server.
- **📉 Minst betrouwbaar** (de 5 laagste) en **⏱️ Laatste storingen** (begin, einde en duur).

**Hoe het geteld wordt:** *beschikbaar* is het deel van de metingen van ping (zonder ping: TCP en HTTP) dat niet
critical was. Een *storing* is de tijd dat al die checks tegelijk critical waren. Het percentage van een groep is het
gemiddelde van de apparaten.

## Incidenten

**Incidenten** zet in één lijst wat er echt mis is, uit alle onderdelen, zodat je het kunt afhandelen. Beheerders en
gebruikers zien deze pagina. Met de chips *Alles*, *Storingen*, *Aanvallen* en *Kwetsbaarheden* kies je de soort.

| Bron | Wanneer een incident |
|---|---|
| Monitoring | een check is minstens 5 minuten critical (niet tijdens onderhoud). Eén incident per storing |
| IDS | een critical IDS-incident (zie [Beveiliging](08-beveiliging.md)) |
| Kwetsbaarheden | een actief misbruikte kwetsbaarheid (CISA KEV) op een server, die niet geaccepteerd is |

**Zo werk je ermee:**

1. Klik op een incident voor de details, de urgentie met de redenen, het aanvalspad en de tijdlijn.
2. Kies een **eigenaar**: het incident gaat dan naar *in behandeling*.
3. Schrijf een **notitie** over wat je deed of vond. Elke wijziging komt in de tijdlijn, met wie en wanneer.
4. Met **Naar Monitoring / IDS / Kwetsbaarheden** ga je naar de bron.

**Goed om te weten:**

- De status gaat van *open* naar *in behandeling* naar *opgelost*. Is de check weer OK, het IDS-incident afgehandeld of
  de kwetsbaarheid weg, dan gaat het incident vanzelf naar *opgelost*. Zelf sluiten kan ook; een IDS-incident is dan ook
  in IDS afgehandeld.
- IDS-meldingen op dezelfde server die bij elkaar horen (hetzelfde IP-adres binnen 6 uur, of een aanvalsketen) worden
  één incident met stappen, bijvoorbeeld *NAS: Brute force SSH → SSH-login van buiten → Verdacht proces*. Zonder
  hetzelfde IP en zonder keten blijven ze apart.
- Bovenaan zie je hoeveel er open en in behandeling zijn, hoeveel er de laatste 30 dagen zijn opgelost en hoe lang dat
  gemiddeld duurde. De lijst staat op urgentie.
- Op een scherm van 1920 pixels of breder opent een incident naast de lijst.
- Opgeloste incidenten blijven ruim een jaar bewaard.

## Analyse en advies

Op **Analyse** zie je hoe het over langere tijd gaat, en wat je kunt veranderen om je netwerk veiliger te maken.
Beheerders en gebruikers zien deze pagina.

Bovenaan kies je de **periode**: 24 uur, 7, 30 of 90 dagen, 1 jaar, of eigen datums (*Van* / *Tot en met* → **Toon**).
Elk cijfer wordt vergeleken met de periode ervoor van dezelfde lengte (▲/▼).

### De veiligheidsscore

De **veiligheidsscore** (0–100) staat bovenaan, met het verloop en een score per onderdeel: *Toegang en SSH*,
*Blootstelling van buiten*, *Kwetsbaarheden en updates*, *Detectie en reactie*, *Netwerk en apparaten* en *Herstel en
monitoring*. Elk onderdeel begint op 100; een open advies kost 18 (hoog), 8 (middel) of 2 (laag) punten. De totaalscore
is het gemiddelde. Klik op een onderdeel voor zijn adviezen.

### Adviezen

RzSense combineert de gegevens van de agents, de CVE-scan, het IDS, de netwerkscan, de checks en je instellingen. Een
advies verschijnt alleen als er bewijs voor is in je eigen gegevens. De belangrijkste staan bovenaan. Elk advies heeft:

- **risico** (hoog, middel, laag), **moeite** (*1 klik*, *5 minuten* …) en **winst** in punten;
- **Waarom (uit je gegevens)**: het bewijs, bijvoorbeeld *sshd luistert op poort 22 · CVE-2024-6387 · poort 22 is 617
  keer van buiten aangevallen · 3 servers hangen af van deze server*;
- **Wat te doen**, met de commando's voor die server en een knop **Kopiëren**;
- voor beheerders soms een knop om het meteen te regelen (bijvoorbeeld *bijwerken via de agent* of *blokkeren*), altijd
  met een bevestiging;
- **Ik heb het gedaan**, **Later (7 dagen)** of **Niet van toepassing** (telt dan niet meer mee). *Gedaan* blijft staan
  tot RzSense zelf ziet dat het is opgelost; dan staat het onder *Opgelost in de laatste 30 dagen*.

Wat RzSense herkent (elke 15 minuten opnieuw berekend): kwetsbare diensten die van buiten bereikbaar zijn, belangrijke
kwetsbaarheden, updates die achterlopen of een herstart nodig hebben, besturingssystemen zonder ondersteuning,
hardening, diensten die van buiten worden aangevallen, verdachte logins, de stand van het IDS, nieuwe open poorten,
apparaten die naar bekende aanvallers bellen, riskante netwerkapparaten, verouderde software op apparaten zonder agent,
dingen die niet bewaakt worden, stille of oude agents, beheerders zonder tweestapsverificatie, back-ups en disks die
vollopen, en flink meer aanvallen dan de week ervoor.

Een advies over een kwetsbaarheid die wereldwijd bekend en kritiek is, krijg je ook als melding. Zie
[Meldingen](03-meldingen.md).

### De tabbladen

Onder de adviezen staan tabbladen:

| Tabblad | Wat je ziet |
|---|---|
| **Trends** | wat langzaam verandert over 90 dagen (zie hieronder) |
| **Aanvallen** | wanneer er wordt aangevallen (per weekdag en uur), welke gebruikersnamen ze proberen, of de blokkades werken, de poorten onder vuur, landen en IP-adressen |
| **Blootstelling** | per server de open poorten, de ergste kwetsbaarheid, de aanvallen, de hardening-score, het risico en het aantal adviezen |
| **Betrouwbaarheid** | kerncijfers, beschikbaarheid per dag en per server, wanneer het misgaat (per weekdag en uur), de lastigste checks, responstijden en herstarts |
| **Capaciteit** | wanneer disks vol zijn, CPU en RAM per server, de grootste Proxmox-guests |
| **Netwerk** | soorten apparaten, fabrikanten, nieuwe apparaten, wifi-clients (UniFi) en DNS (AdGuard Home) |
| **Back-ups** | de laatste back-up per Proxmox-guest, de back-uptaken, je heartbeats en de back-up van RzSense zelf |
| **Meldingen en ruis** | meldingen per dag en per kanaal, de checks met de meeste meldingen, checks die steeds vanzelf herstellen, en de doelen (zie hieronder) |

Beweeg over een grafiek voor de precieze waarde. RzSense bewaart de dagtotalen een jaar, ook als de losse meetwaarden
al zijn opgeruimd.

### Doelen en "na de actie"

Op het tabblad *Meldingen en ruis* staat hoe goed RzSense en jij reageren:

| Blok | Wat | Doel |
|---|---|---|
| **Melding binnen 5 minuten** | van critical tot de eerste melding (mediaan en 90%) | 95% |
| **Agents rapporteren** | het deel van de tijd dat alle agents rapporteerden | 99,5% |
| **Zelf herstellen** | hoe vaak een herstelactie lukte (30 dagen) | 80% |
| **Automatische blokkades** | hoeveel er vroegtijdig zijn opgeheven: waarschijnlijk onterecht | hooguit 5% |
| **Slimme drempels met méér ruis** | checks die na het aanzetten van slimme drempels méér warnings gaven | geen |

Je meldregels tellen mee: met *melden na 10 minuten* haal je de 5 minuten nooit (dat is dan je eigen keuze).

### Rapport als PDF

Op Analyse staat de knop **📄 Rapport (PDF)**. Die opent een afdrukbare pagina over de gekozen periode, met meteen het
afdrukvenster. Kies daar **Opslaan als PDF**.

- **Eerste pagina, In één oogopslag:** de score, de tijd tot melding (MTTD) en tot opgelost (MTTR), de doelen met ✔ of
  ✘, en de 5 grootste risico's.
- **Daarna:** de samenvatting (beschikbaarheid, storingen, hersteltijd, meldingen, IDS), de incidenten, de
  beschikbaarheid per server en de lastigste checks, beveiliging, de kwetsbaarheden per server en wat je nu kunt
  aanpakken.

Opent er niets, sta dan pop-ups toe voor RzSense. Rapporten die RzSense zelf per e-mail of push verstuurt, stel je in met
de knop **📬 Rapporten** (zie [Meldingen](03-meldingen.md)).

## Trends

Op **Analyse → Trends** zie je wat over de laatste 90 dagen langzaam verandert: dingen die je dag tot dag niet opvallen,
maar over een paar maanden misgaan.

| Groep | Wat |
|---|---|
| Hardware veroudert | UPS-looptijd bij dezelfde belasting, UPS-lading, SSD-slijtage, vervangen sectoren, schijftemperatuur |
| Langzaam trager | responstijd van HTTP-, TCP-, ping- en DNS-checks, internetsnelheid en -vertraging |
| Capaciteit | disks, geheugen, de database en de logs van RzSense |
| Betrouwbaarheid | storingen en wisselingen per week, duur van een controleronde |
| Beveiliging en onderhoud | aanvallen en mislukte logins per week, openstaande kwetsbaarheden, hardening-score |

**Hoe RzSense rekent:** door de dagwaarden gaat een robuuste lijn, zodat één rare dag of een rustig weekend hem niet
scheeftrekt. Iets is pas een trend als de verandering groot genoeg is (bijvoorbeeld 10% trager per maand) én de meeste
dagen dezelfde kant op gaan. Anders staat het onder *stabiel*.

**Per kaartje:** de waarde nu en 90 dagen geleden, de verandering per maand (rood = de verkeerde kant op), de lijn met
de verwachting (gestippeld), wat je eraan kunt doen, en ⏳ wanneer de grens geraakt wordt. Bovenaan **Wat beweegt er**:
de drie belangrijkste.

**Goed om te weten:**

- Raakt een trend binnen 30 dagen een grens, dan krijg je een melding (*Instellingen → Meldingen en rapporten →
  Systeemmeldingen → Trend*), hooguit één keer per 14 dagen per trend.
- Sommige waarden (UPS-looptijd, SSD-slijtage, grootte van de database) bewaart RzSense pas sinds kort elke dag. Daarvan
  zie je na 14 dagen een trend.

## AI-advies

Een AI legt een storing of incident uit en stelt een oplossing voor. Het staat standaard uit.

### Zo zet je het aan

1. Ga naar *Instellingen → Systeem → AI-advies* en zet **AI-advies aan**.
2. Kies de **aanbieder**:
   - **Ollama**: lokaal of op een eigen server. Gratis, en je gegevens blijven thuis. Vul het adres in.
   - **Claude**, **ChatGPT**, **Gemini** of **Grok**: in de cloud, met een API-sleutel. De sleutel gaat nooit naar de
     browser.
3. Klik op **⟳ Modellen ophalen** en kies een model.
4. Klik op **🧪 Testen** om de verbinding te proberen.
5. Kies bij **Wanneer**: *🖐️ Op verzoek* (alleen als je op 🤖 klikt) of *⚡ Automatisch* (één advies per storing zodra die
   critical wordt; bij 3 of meer problemen op een server één advies voor die server).

### Zo gebruik je het

De knop 🤖 staat bij een check met een probleem, bij een gebundelde storing in *Nu aandacht nodig*, en bij elk open
incident op IDS en Incidenten. Zie je geen knoppen? Dan staat AI-advies uit, of is er nu geen probleem.

Het antwoord heeft vier kopjes: *wat is er aan de hand*, *de oorzaak*, *de oplossing in stappen* (met commando's om te
kopiëren) en *controleren*. Met **🔄 Opnieuw vragen** vraag je een nieuw advies. Beheerders en gebruikers mogen een
advies vragen; alleen-lezen-accounts lezen een advies dat er al is.

**Wat er meegaat:** de check en de server, de statuswissels en metingen, andere problemen op de server, wijzigingen in
RzSense van de laatste 7 dagen en pogingen van zelf herstellen. Wachtwoorden en tokens gaan er altijd uit. Staat AI-advies
uit, dan gaat er niets de deur uit.

### Opties

- **Daglimiet** (standaard 50 vragen per dag) en **Maskeren**: servernamen, IP- en e-mailadressen gaan als labels naar
  de cloud (`server-1`, `ip-1`); in het antwoord staan weer de echte namen.
- **Diep zoeken via de agent** (aanzetten vraagt je wachtwoord): bij een critical voert de agent een vaste lijst
  commando's uit die alleen lezen (status en log van een service of container, `df`, de zwaarste processen, wie op een
  poort luistert, de laatste fouten in het journal). Dat duurt meestal 1 à 2 minuten. De AI kiest nooit zelf commando's.
  `COMMANDS=0` in `/etc/default/rzmon-agent` blokkeert dit op die server altijd.
- **📝 Alleen tekst ↔ ▶ Uitvoeren** (standaard alleen tekst; aanzetten vraagt je wachtwoord): een advies mag een
  bestaande herstelactie voorstellen (herstarten, of een script uit `/etc/rzmon-agent/heal.d`). Een beheerder voert die
  uit met ▶ en een bevestiging; het komt in het logboek. Een service wordt alleen herstart als hij echt stil ligt.

### Verbruik en tegoed

De aanbieders laten met een gewone API-sleutel niet zien hoeveel tegoed je nog hebt. RzSense telt daarom zelf mee; de
bedragen zijn een **schatting**.

- Onder elk advies staan de tokens en de geschatte kosten (bij Ollama: *gratis* en de snelheid).
- Vul in de tabel per model de prijs in euro per 1 miljoen tokens in (in en uit apart).
- **Tegoed bijhouden:** vul per aanbieder in hoeveel je hebt opgewaardeerd en vanaf welke dag. Bij 20% over krijg je een
  melding.
- **Maandbudget (€):** is het op, dan stopt AI-advies via de cloud tot de volgende maand (of gaat het naar Ollama). Bij
  80% krijg je een melding. 0 = geen budget.
- Meldt een aanbieder dat het tegoed op is, dan staat dat rood bovenaan, met een knop **Opwaarderen ↗**.

### Extra's

Elke extra heeft een eigen schakelaar onder **Extra's**:

| Extra | Wat het doet | Standaard |
|---|---|---|
| **🧠 Geheugen** | onder een advies staan 👍, 👎 en *✔ Dit loste het op*. Valt dezelfde check later weer om, dan krijgt de AI die feedback mee (tot 180 dagen) | aan |
| **♻️ Niet twee keer betalen** | dezelfde storing binnen de ingestelde tijd krijgt het bestaande advies | aan, 6 uur |
| **🏠 Terugvallen op Ollama** | is de cloud onbereikbaar, het tegoed op of de limiet bereikt, dan gaat de vraag naar Ollama. Een geweigerde API-sleutel valt niet terug | aan |
| **🔔 Samenvatting in de melding** | bij een automatisch advies een korte melding met de waarschijnlijke oorzaak | aan |
| **📅 Weekoverzicht** | één keer per week in gewone taal wat er speelde. Dag, tijd en kanalen stel je in bij *Meldingen en rapporten → Rapporten* | aan |
| **🏷️ Oordeel bij IDS-incidenten** | nieuwe IDS-incidenten krijgen een label *lijkt onschuldig*, *verdacht* of *lijkt echt*. Alleen een label: er wordt nooit iets afgehandeld of geblokkeerd. Jouw eigen oordeel gaat altijd voor. Hooguit een ingesteld aantal AI-vragen per dag (standaard 20) | uit |
| **📝 Verslag na een incident** | bij een opgelost incident de knop *📝 AI-verslag*: wat er gebeurde, hoe erg, wat hielp en hoe je het voorkomt | aan |

De meldingen over AI-advies (samenvatting, budget, tegoed) stel je in bij *Instellingen → Meldingen en rapporten →
Systeemmeldingen*.

### Goed om te weten

- **Ollama op een andere machine:** laat het daar luisteren op het netwerk (`OLLAMA_HOST=0.0.0.0`) en zet poort 11434 in
  de firewall alleen open voor de server van RzSense. Ollama heeft zelf geen wachtwoord.

## Live bijwerken

RzSense werkt het scherm vanzelf bij. Het kijkt elke paar seconden of er iets veranderde (een storing, een melding,
iets bevestigd, een nieuwe checkronde) en past dan **alleen aan wat veranderde**. Wat je openklapte, wat je typt en je
scrollpositie blijven staan.

- Een nieuwe waarde licht even op en telt naar de nieuwe waarde (bijvoorbeeld CPU 23% → 41%).
- Een **nieuw probleem** waarvoor echt een melding uitging, geeft een klein meldinkje rechtsonder (*🔴 NAS – SMB:
  CRITICAL*). Klik erop en de check opent.
- Het venster van een check werkt ook live bij zolang het open is.
- Het groene lampje bij *Laatste check* laat zien dat het werkt; grijs = even geen verbinding.
- Instellingen en Analyse worden niet live bijgewerkt: daar ben je aan het invullen of kijk je naar een periode.

### Aanvallen live

Boven **Dashboard**, **IDS** en **Netwerk** staat een band met wat de IDS de laatste 15 minuten zag. Elke stip is een
echte gebeurtenis:

- **Rood, van buiten:** een stip vliegt van *Internet* naar het IDS-schild. Is het IP-adres geblokkeerd, dan spat hij
  daar uiteen (*tegengehouden*); anders loopt hij door naar de server (*gezien*).
- **Rood, naar buiten:** een server maakt zelf een verdachte verbinding.
- **Oranje, binnen het netwerk:** van een eigen server of het LAN naar een andere server.

Rechts staan de laatste vier; klik erop voor de details. Zag de IDS de laatste 7 dagen niets, dan blijft de band weg.
Verbergen kan met ✕; terugzetten bij *Mijn account → Weergave → Aanvallen live*.

### Voortgang

Overal waar RzSense iets voor je doet (opslaan, testen, scannen, bijwerken) zie je dat het loopt:

- **De knop zelf** krijgt na een halve seconde een bewegend balkje en de tijd (*4 s*). Weet RzSense hoe lang het meestal
  duurt, dan staat dat erbij (*4 s / ±12 s*). Je kunt niet per ongeluk twee keer klikken.
- **Lange taken** (netwerkscan, poortscan): de knop wordt **⏹ Stoppen** en is zelf de voortgangsbalk. Eronder staan de
  stappen. Wat al klaar is, blijft bewaard als je stopt. De netwerkscan loopt door als je de pagina verlaat.
- **Reeksen** (meerdere checks tegelijk toevoegen) tellen mee: *7 van 25*.
- **Wat de agent doet** (bijwerken bij Kwetsbaarheden en Updates) toont overal dezelfde stappen: *aangevraagd →
  opgepakt → bezig → klaar*.
- **Een pagina laden:** duurt het langer dan 0,4 seconde, dan zie je een dun balkje bovenaan en een teller in het
  kruimelpad. Na 8 seconden staat er *Dit duurt langer dan normaal* met **↻ Opnieuw**.

## Zoeken en doen (Ctrl+K)

Druk op **Ctrl+K** (op een Mac **⌘K**), of klik op het vergrootglas. Typ een deel van een server, check, IP-adres,
pagina of instelling en druk op Enter.

Met een werkwoord erbij krijg je meteen de actie:

| Woord | Actie |
|---|---|
| *nu* of *check* | de check nu uitvoeren |
| *onderhoud* | onderhoud instellen |
| *bevestig* | een storing bevestigen |
| *grafiek* | de grafiek openen |
| *bewerk* | bewerken |
| *agent* | de Agent Manager openen |
| *blokkeer* | een IP-adres blokkeren (beheerders) |

Bijvoorbeeld `rl02 disk onderhoud` of `nas nu`. Typ je een IP-adres, dan krijg je de tijdlijn van dat adres (IDS,
logins en blokkades). Je ziet alleen wat je met je rol mag. Met ↑ ↓ kies je, Esc sluit.

## Filters

**⚲ Filters** staat in de werkbalk van Monitoring, Meldingen, IDS (Incidenten, Toegang, Blokkades), Kwetsbaarheden en
Datalekken.

1. Klik op **⚲ Filters**. Per onderdeel zie je een keuzelijst, met hoe vaak elke waarde voorkomt.
2. Vink één of meer waarden aan (server NAS én Pi). Verschillende onderdelen werken samen (server NAS **en** status
   Critical).
3. Actieve filters staan als chips onder de werkbalk (`Server: NAS ✕`). *Alles wissen* haalt ze allemaal weg.

| Pagina | Filteren op |
|---|---|
| Monitoring | server · soort apparaat · bewaking (agent, remote …) · groep · status · soort check · check · bevestigd · agentversie |
| Meldingen | periode · server · soort apparaat · check · status · soort · kanaal · verzonden · IP-adres |
| IDS → Incidenten | periode · server · categorie · soort incident · ernst · status · IP-adres |
| IDS → Toegang | periode · server · soort (SSH, console, sudo, su) · resultaat · gebruiker · IP-adres |
| IDS → Blokkades | periode · server · IP · reden · door (automatisch of met de hand) · nog actief |
| Kwetsbaarheden | server · pakket · ernst · CVE · met oplossing · bereikbaar · actief misbruikt · geaccepteerd · herstart nodig |
| Datalekken | adres · bron · lek · soort gegevens · jaar · status |

**Goed om te weten:**

- De periode (Meldingen, IDS) is *vandaag*, *24 uur*, *7 dagen*, *30 dagen* of *van – tot*.
- Filters worden per gebruiker bewaard, ook op een ander apparaat.
- Met **💾 Opslaan als…** bewaar je een filter met een naam (bijvoorbeeld *NAS critical*). Met ⭐ in het paneel haal je
  het terug.
- De filters staan ook in het adres, bijvoorbeeld `#alerts?server=NAS,Pi&status=crit&period=7d`. Zo kun je een
  bladwijzer maken of een link delen.

**Open of dicht bij openen.** Bij *Mijn account → Weergave → Bij openen* kies je:

- **Alles dicht** (standaard): servers, dagen en gebundelde regels staan dicht, behalve *vandaag*. Je ziet de koppen met
  hun tellers. Met zoeken of een filter gaan de blokken met treffers vanzelf open.
- **Slim openen**: vandaag en ernstige dingen open; servers die je openklapte worden onthouden.

Op Kwetsbaarheden en Datalekken onthoudt RzSense wat jij open- of dichtklapte, voor je account. Overal staat in het menu
**⋯** *Alles openen / sluiten*.

## Accountmenu en Mijn account

### Het accountmenu

Klik op je naam (onderaan de zijbalk, of rechtsboven op een smaller scherm). Je ziet:

- je naam, je rol en vanaf welk IP-adres je bent ingelogd, met de versie van RzSense en een link *wat is er nieuw?*;
- **👤 Mijn account** en **⚙️ Instellingen**;
- **🧭 Aan de slag** (alleen beheerders): de wizard die je op weg helpt, zie [Installatie](01-installatie.md#aan-de-slag);
- **💡 Rondleiding**: een korte rondleiding langs het Dashboard, Monitoring, Meldingen en dit menu. Een gewone gebruiker
  krijgt hem vanzelf de eerste keer dat hij inlogt;
- snelle keuzes voor **Lay-out**, **Thema**, **Licht/donker** en **Taal**;
- **⏻ Uitloggen**.

Ben je zonder wachtwoord binnen via je lokale netwerk, dan staat er **🏠 Lokaal netwerk** met **Inloggen met je
account**; *Mijn account* is er dan niet. Zie [Toegang en beheer](11-toegang-en-beheer.md).

### Mijn account

**Mijn account** heeft deze onderdelen (op een breed scherm in de zijbalk; klik en je springt ernaartoe):

| Onderdeel | Wat je er doet |
|---|---|
| **Gegevens** | je gebruikersnaam en rol (die wijzigt alleen een beheerder), je e-mailadres en de taal |
| **Wachtwoord wijzigen** | een nieuw wachtwoord (minstens 8 tekens) |
| **Tweestapsverificatie** | 2FA voor je eigen account aan- of uitzetten |
| **Van buiten en passkeys** | inloggen van buiten je netwerk, en met een passkey |
| **Meldingen op dit apparaat** | Web Push op deze browser (zie [Meldingen](03-meldingen.md)) |
| **Ingelogde apparaten** | waar je account is ingelogd, en die apparaten uitloggen |
| **Mijn laatste logins** | je laatste inlogpogingen, gelukt en mislukt |
| **Weergave** | hoe RzSense er voor jou uitziet (zie hieronder) |

## Weergave en thema's

Bij *Mijn account → Weergave* stel je in hoe RzSense eruitziet. Wijzigingen zie je meteen. De meeste keuzes gelden voor
je **account**, dus op al je apparaten. Wat per scherm logisch is (paginabreedte, de tijden van het dagritme, de
TV-modus) geldt **per apparaat**. Een beheerder kiest de standaard voor nieuwe accounts bij *Instellingen → Systeem →
Weergave*.

### Lay-out

De lay-out is hoe de pagina's zijn opgebouwd. Kies hem hier of in het accountmenu.

| Lay-out | Dashboard | Monitoring |
|---|---|---|
| ▢ **Default** | rustig: één zin, vier cijfers, *Aandacht nodig* en de servers | servers als rustige rijen |
| ☰ **L1nes** | klassiek | servers onder elkaar, als een tabel |
| ▪ **Compact** | klassiek | een blokje per check; voor veel checks of een TV-scherm |
| ◈ **CC** | Command center | tegels in CC-stijl, met versleepbare kaarten op elke pagina |

Bij **Grootte** kies je *Small* (compact, meer in beeld) of *Large* (meer informatie). Dat geldt per lay-out, apart voor
je telefoon en je computer.

**Default** is de rustigste lay-out: weinig kleur, dunne randen, geen schaduw, en in de zijbalk een licht vlakje achter
het onderdeel waar je bent. Elk thema werkt ermee; Liquid Glass, @S en Neo houden hun eigen stijl. Default heette
vroeger *Blox*: wie Blox had, heeft nu vanzelf Default.

### Thema en licht/donker

Het **thema** is hoe het eruitziet. Elk thema werkt met elke lay-out.

| Thema | Hoe het eruitziet |
|---|---|
| **Standaard** | de gewone stijl van RzSense |
| **@S** | rustig en licht, in de stijl van Apple: de systeemletter, witte kaarten zonder randen met een zachte schaduw, één blauwe accentkleur |
| **Liquid Glass** | kaarten, menu's en vensters als doorschijnend glas, met licht dat er traag achter beweegt. Met *Levendige kleuren* (bij Weergave) wordt het kleurrijker |
| **Neo** | de Command-center-stijl: diep blauw, cyaan accent en gloeiende randen |

Bij **Licht/donker** kies je:

- **Auto**: volgt je computer of telefoon;
- **Licht** of **Donker**;
- **Schemer**: licht, maar zacht en warm voor je ogen als het om je heen donker is. Geen fel wit maar warm grijs-beige,
  minder blauw licht en rustigere statuskleuren;
- **Dagritme**: overdag licht, 's avonds Schemer, 's nachts donker. De tijden stel je eronder in, per apparaat
  (standaard licht vanaf 07:00, Schemer vanaf 19:00, donker vanaf 23:00). Laat je *Donker vanaf* leeg, dan blijft het 's
  nachts Schemer. RzSense wisselt vanzelf, ook als de pagina openstaat.

*Auto* weet niet hoe laat het donker wordt; wil je Schemer, kies dan *Dagritme*.

### Paginabreedte

Hoe breed RzSense wordt op een groot scherm (per apparaat):

- **Begrensd** (standaard): hooguit 1400 pixels, in het midden.
- **Breed**: hooguit 1920 pixels. Goed voor een scherm van 24 tot 27 inch.
- **Volledig**: de hele breedte, ook op een ultrawide.

Met meer ruimte komen er meer servers per rij, kaarten naast elkaar en meer kolommen in beeld. Tekst en formulieren
houden een leesbare breedte. Op een telefoon maakt het niets uit.

### Beweging

Met de schuif **Beweging** kies je hoeveel er beweegt in heel RzSense:

- **Vol** (standaard): getallen lopen naar hun nieuwe waarde, een statuswissel pulseert één keer, knoppen laten zien dat
  ze bezig en klaar zijn, grafieken tekenen zich, nieuwe meldingen schuiven zacht binnen, en de Attack Flow en het
  Command center bewegen helemaal.
- **Rustig**: hetzelfde, maar trager en zonder flitsen.
- **Stil**: niets beweegt. Handig voor een scherm dat de hele dag aanstaat.

Staat *minder beweging* aan op je computer of telefoon, dan is het altijd stil.

### Overige keuzes

- **Rode gloed bij critical**: een server met een critical check pulseert zacht.
- **Laadanimatie**: bij het openen lichten je servers een voor een op rond RzSense, en overal waar je moet wachten loopt
  een hartslag. Het houdt het laden niet op.
- **Aanvallen live**: de band boven Dashboard, IDS en Netwerk.
- **Moderne weergave van IDS**: de IDS-pagina met score, *Nu aandacht nodig*, serverkaarten en grafiek. Uit = de
  compacte weergave.

## Taal

RzSense is er in het **Nederlands** en het **Engels**. Kies de taal in het accountmenu (**🌐 Taal**: NL of EN) of bij
*Mijn account → Gegevens*. De pagina laadt dan opnieuw. Je keuze geldt voor je account. Zonder keuze volgt RzSense de
taal van je browser.

De taal van de **meldingen** en de berichten van checks (Pushover, e-mail, Home Assistant) is voor iedereen hetzelfde.
Die stel je in bij *Instellingen → Meldingen en rapporten → Wanneer melden*.

## Logo, icoon en op je telefoon

- Het logo staat in de zijbalk en op het inlogscherm. Het browsertabblad toont het icoon (een oranje schild met de z).
- **Op je telefoon vastzetten:** iPhone: Safari → *Deel* → *Zet op beginscherm*. Android: Chrome → ⋮ → *Toevoegen aan
  startscherm*. RzSense opent dan als losse app met zijn eigen icoon.
- **Pushover:** zet [`brand/pushover-icon-72.png`](../brand/pushover-icon-72.png) als icoon van je applicatie op
  pushover.net (zie [Meldingen](03-meldingen.md)).
- **Op een telefoon** worden brede tabellen kaartjes (elke rij een kaartje met de kolomnamen ervoor), staan tegels twee
  per rij en blijven de knoppen onderin een venster in beeld.
