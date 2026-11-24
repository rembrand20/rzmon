# Kwetsbaarheden en updates

Dit hoofdstuk gaat over de software op je servers: welke bekende lekken (CVE's) erin zitten, welke software geen updates meer krijgt (end-of-life), en hoe je updates laat installeren door de agent. Het werkt voor Linux-servers met de agent, voor Windows-servers met de Windows-agent en (voor updates) voor Home Assistant.

## Kwetsbaarheden

RzSense kijkt welke software er op je servers staat en zoekt daar de bekende kwetsbaarheden (CVE's) bij. Zo zie je waar een lek zit, hoe ernstig het is, of het al misbruikt wordt, en welke update het oplost.

### Aanzetten

1. Ga naar *Instellingen → Bescherming → Kwetsbaarheden en datalekken*.
2. Zet **Kwetsbaarheden aan**.
3. Kies eventueel het tijdstip van de dagelijkse scan (standaard 03:30), vanaf welke ernst je ze wilt zien, en welke servers niet gescand worden.

Daarna staat er een tabblad **Kwetsbaarheden** in de bovenbalk, voor beheerders en gebruikers (niet voor *alleen lezen*).

### Hoe het werkt

- De agent stuurt zijn pakketlijst mee (`dpkg` of `rpm`, met de distributie uit `/etc/os-release`): de hele lijst één keer per dag, of eerder als er pakketten veranderen. Van de RzSense-server zelf leest RzSense de lijst direct.
- RzSense zoekt de kwetsbaarheden op bij [OSV.dev](https://osv.dev). Daar staan de beveiligingsadviezen van de distributies zelf. Een reparatie die de distributie in een "oud" versienummer heeft teruggezet (backport), telt dus niet als kwetsbaar.
- Ondersteund: Debian (ook Raspberry Pi OS), Ubuntu, Rocky Linux, AlmaLinux en Alpine. RHEL en CentOS gebruiken de adviezen van AlmaLinux (zelfde pakketten en versies). Afgeleiden van Debian die zelf niet in OSV staan, zoals TrueNAS SCALE, worden gescand als de Debian-versie eronder.
- Naar OSV.dev gaan alleen **pakketnamen, versies en de distributie**, geen servernamen of IP-adressen.
- Daarnaast haalt RzSense op: of een lek **actief misbruikt** wordt (de lijst *Known Exploited Vulnerabilities* van CISA), de **kans op misbruik** (EPSS van FIRST.org) en zo nodig de **CVSS-score** van NVD.
- RzSense scant elke dag op het ingestelde tijdstip, en meteen als de pakketlijst van een server verandert. Met **🔍 Nu scannen** start je het zelf.

De RzSense-server heeft uitgaand HTTPS nodig naar `api.osv.dev`, `www.cisa.gov`, `api.first.org` en `services.nvd.nist.gov`. Kan dat niet, dan staat bij de server *OSV.dev niet bereikbaar*.

### Windows

Op een Windows-server met de Windows-agent werkt het anders. Windows Update zegt per **ontbrekende beveiligingsupdate** welke CVE's die oplost. Die staan bij *Kwetsbaarheden* met het KB-nummer als pakket én als oplossing, met de ernst van Microsoft (*Critical*, *Important* …) en daarna CVSS, EPSS en "actief misbruikt", net als bij Linux. Is de update geïnstalleerd, dan tellen ze als opgelost.

- De agent vraagt Windows Update op de achtergrond, hooguit eens per 6 uur.
- Software van anderen (bijvoorbeeld 7-Zip of een browser) ziet RzSense op Windows nog niet.
- Installeren gaat via de pagina **Updates** (zie [Updates installeren](#updates-installeren)), niet met de knoppen voor pakketten.

Meer over de Windows-agent staat in [Windows-agent](06-windows-agent.md).

### De pagina Kwetsbaarheden

De pagina heeft bovenaan tabbladen: **🐞 Kwetsbaarheden**, **📦 Software**, **⏳ Verouderd** (end-of-life) en, voor beheerders met de datalekcontrole aan, **🔓 Datalekken** (zie [Beveiliging](08-beveiliging.md)).

Op het tabblad Kwetsbaarheden zie je:

- **Tellers**: Critical, High, Medium, Low, Onbekend en ⚠️ Actief misbruikt. Klik erop om te filteren.
- **Kerncijfers**: servers gescand, kwetsbare pakketten, hoeveel een oplossing hebben, hoeveel actief misbruikt worden, wat er de laatste 30 dagen is opgelost, en wanneer de volgende scan is.
- **🎯 Eén update, veel opgelost**: de updates die het meeste risico wegnemen. Per pakket zie je naar welke versie, hoeveel kwetsbaarheden het oplost, op hoeveel servers, en of er actief misbruikte of veelbesproken bij zijn. Met **⬆ Bijwerken** werk je het meteen bij.
- **Werkbalk**: de filters **Alles · ⚡ Hoog risico · 🔥 Veel aandacht · 🌐 Bereikbaar · Met oplossing · ⚠️ Actief misbruikt · Geaccepteerd** (met aantallen), en **🔍 Nu scannen**.
- **Per server een kaart** met de aantallen per ernst. Daarin elk pakket met kwetsbaarheden, de geïnstalleerde versie, de versie waarin het **opgelost** is, en de CVE's als knopjes. Bij Debian en Ubuntu staat het **bronpakket** (bijvoorbeeld `openssh`), met de pakketten die erbij horen eronder.
- **🌐 bereikbaar**: het programma van dat pakket luistert op het netwerk (niet alleen op localhost). Die los je als eerste op.
- **↻ herstart nodig**: er is een nieuwere kernel geïnstalleerd dan er draait, of services draaien nog met oude bestanden (als `needs-restarting` of `needrestart` op de server staat).
- **📦 Alle software**: de volledige pakketlijst van de server, met zoeken.

Geeft de scan op meerdere servers dezelfde fout, dan staat die één keer bovenaan, met **🔍 Opnieuw scannen**.

### Zoeken

Het zoekvak bovenaan zoekt in servers, CVE's en software tegelijk:

- kies een **server**: je ziet alleen die server, met de kaart open;
- kies een **CVE**, of typ een CVE-nummer en druk op Enter: het venster van die CVE opent, ook als hij op geen enkele server staat;
- kies een **programma**: het tabblad *📦 Software* opent bij dat programma.

### Het venster van een CVE

Klik op een CVE voor:

- de omschrijving, de **CVSS-score** met uitleg in gewone woorden (bijvoorbeeld *via het netwerk · zonder in te loggen*), of hij **actief misbruikt** wordt, de **kans op misbruik** en de publicatiedatum;
- **Zo los je het op**: het commando om te kopiëren, bijvoorbeeld `sudo dnf upgrade openssh` of `sudo apt update && sudo apt install --only-upgrade openssh-server`. De nieuwe versie komt uit de pakketbron van je distributie; een download bij de maker is niet nodig;
- **Meer informatie**: de adviezen van de distributie, OSV en NVD;
- **Waar staat hij?**: al je servers in vijf groepen.

| Groep | Betekenis |
|---|---|
| 🔴 Kwetsbaar | het pakket staat erop in een versie met deze CVE, met de versie waarnaar je bijwerkt, 🌐 als het op het netwerk luistert, en ⬆ Bijwerken |
| ✅ Niet kwetsbaar | het pakket staat erop in een versie zonder deze CVE: al bijgewerkt, of de distributie zegt dat haar versie niet geraakt wordt |
| ⚪ Niet van toepassing | het pakket staat er niet op |
| ❔ Onbekend | geen softwarelijst, nog niet gescand of de scan mislukte. Tel die **niet** als veilig |
| 🟦 Geaccepteerd | iemand heeft het risico bewust geaccepteerd |

### Risicoscore

Elk pakket krijgt een score van 0 tot 100, en de lijst staat op risico (hoogste bovenaan). De ernst alleen zegt weinig; de score telt ook mee of een lek misbruikt wordt en of het bereikbaar is.

| Onderdeel | Punten |
|---|---|
| CVSS-score (of de ernst als die ontbreekt) | tot 50 (CVSS × 5) |
| Actief misbruikt (CISA KEV) | +25 |
| Kans op misbruik (EPSS) | tot 15 |
| Het pakket luistert op het netwerk (🌐) | +10 |

Vanaf **70** is het rood (**⚡ Hoog risico**, ook als filter), vanaf 40 oranje. Een critical CVE in een pakket dat niet op het netwerk luistert en niet misbruikt wordt, kan dus lager staan dan een high die bereikbaar is en actief misbruikt wordt.

### Rugbaarheid: hoeveel aandacht krijgt een lek?

Rugbaarheid is een score van 0 tot 100: hoeveel aandacht krijgt een kwetsbaarheid, en wordt hij al misbruikt? RzSense berekent hem alleen voor de CVE's op je eigen servers, één keer per dag (actief misbruikt of high/critical: twee keer).

| Deel | Bronnen | Punten |
|---|---|---|
| Exploits | actief misbruikt (CISA KEV) 25 · oordeel van CISA (SSVC): actief misbruikt 20, proof-of-concept 10 · Exploit-DB 10 · Nuclei-template 8 · PoC's op GitHub tot 15 | tot 60 |
| Praat | Hacker News laatste 7 dagen tot 20 en totaal tot 8 · Mastodon laatste 7 dagen tot 20 | tot 40 |
| Kans op misbruik | EPSS | tot 10 |

- In het venster van een CVE zie je de meter, de stijging in 7 dagen, het verloop en elke bron (met een link). **↻ Nu bijwerken** haalt alles meteen opnieuw op.
- In de lijst staat een **🔥 met de score** bij een CVE boven de grens; het filter **🔥 Veel aandacht** laat alleen die zien.
- Instellen bij *Instellingen → Bescherming → Kwetsbaarheden en datalekken*:
  - **GitHub-token** (optioneel): zonder token zoekt RzSense 6 CVE's per minuut, met token 20. Maak een *fine-grained token* zonder rechten (alleen publieke repositories).
  - **Mastodon**: zonder token telt RzSense de hashtag (bijvoorbeeld `#CVE20243094`) op de instantie die je kiest (standaard infosec.exchange). Met een token (alleen lezen) telt hij ook berichten zonder hashtag.
  - **Exploit-DB** en **Nuclei**: één keer per dag de lijst, aan of uit.
- Naar buiten gaat alleen het CVE-nummer. Nodig: uitgaand HTTPS naar `api.github.com`, `hn.algolia.com`, `raw.githubusercontent.com`, `gitlab.com` en je Mastodon-instantie.

### Raakt dit jou echt?

Staat AI-advies aan, dan staat de knop **🤖 Raakt dit jou echt?** in het venster van een CVE. De AI krijgt de gegevens van de CVE (CVSS, EPSS, KEV, SSVC, rugbaarheid) en per server het pakket, de versie, de oplossing en of het pakket op het netwerk luistert. Hij antwoordt met een oordeel (**eerst doen**, **kan wachten** of **niet van toepassing**), de reden, wat je per server moet doen, en wat je kunt doen als bijwerken nu niet kan. Het oordeel staat daarna als 🤖 bij de CVE in de lijst.

Het is alleen tekst: RzSense voert niets uit wat de AI voorstelt. Met *Gegevens maskeren* aan gaan servernamen en IP-adressen gemaskeerd naar een cloud-AI. Zie ook [De interface](02-de-interface.md) over AI-advies.

### Software over al je servers

Het tabblad **📦 Software** toont elk programma over al je servers. Debian en Ubuntu tellen per bronpakket (zoals `openssl` voor `libssl3`), Rocky en AlmaLinux per pakket.

- Per programma: het aantal servers, de versies, de open CVE's per ernst, ⚠️ actief misbruikt, 🌐 bereikbaar, ↕ als de versies uiteenlopen, en **veilig vanaf**: de versie waarin alle open CVE's van dat programma opgelost zijn.
- Klik op een programma voor de verdeling per besturingssysteem: per versie de servers en de open CVE's, met ⬆ om een server bij te werken.
- Versies worden **per besturingssysteem** vergeleken. Debian (`3.0.15-1~deb12u1`), Ubuntu (`3.0.2-0ubuntu1.18`) en Rocky (`3.0.7-27.el9`) zetten reparaties elk in hun eigen versienummer, dus een "lager" nummer kan net zo veilig zijn.
- Standaard zie je alleen programma's **met open CVE's** (geaccepteerde tellen niet mee); **Alle software** laat alles zien. Filters: 🌐 Bereikbaar, ⚠️ Actief misbruikt, ↕ Versies lopen uiteen.
- Servers die nog niet gescand zijn of waarvan de scan mislukte, staan bovenaan onder *onbekend* en tellen niet mee.

### Bijwerken via de agent

Als beheerder kun je een kwetsbaar pakket meteen laten bijwerken door de agent (alleen Linux):

- **⬆** bij een pakket, of **⬆ Alle beveiligingsupdates installeren** bij een server;
- **⬆ Alle N kwetsbare servers bijwerken** in het venster van een CVE;
- **⬆ … bijwerken op alle N servers** bij een programma in *📦 Software*;
- **⬆ Alles bijwerken** in de werkbalk: elke server met minstens één open kwetsbaarheid waarvoor een oplossing is, met alle pakketten die een oplossing hebben.

Zo gaat het:

1. Je ziet eerst per server wat er gebeurt, of waarom het via de agent niet kan.
2. Je bevestigt één keer met je wachtwoord (op het LAN zonder wachtwoord: typ **BIJWERKEN**).
3. De agent voert bij zijn volgende rapport `dnf upgrade` of `apt-get install --only-upgrade` uit voor precies die pakketten.
4. Een venster toont de voortgang: *Wachten op de agent → Pakketlijst ophalen → Downloaden → Installeren → Klaar*, met waar de server mee bezig is en de laatste regels van de uitvoer. Met **Op de achtergrond** sluit je het venster; bij de server staat dan *⏳ Bezig met bijwerken*, en een klik daarop opent het weer.
5. Daarna stuurt de agent zijn nieuwe softwarelijst en scant RzSense de server meteen opnieuw. Bij de server staat wat er **echt** opgelost is, bijvoorbeeld *🔎 gecontroleerd: 4 kwetsbaarheden opgelost*, of *nog open in openssh* als er een CVE over is die pas een nieuwere versie oplost.

Goed om te weten:

- Het kan alleen als **commando's als root** aan staan voor die server (*Instellingen → Agents en koppelingen → Commando-checks*, of per server in de Agent Manager) en de agent als root draait. Kan het niet, dan krijg je het commando om zelf uit te voeren.
- Op de server zelf blokkeer je het altijd met een regel in `/etc/default/rzmon-agent`:

  ```
  COMMANDS=0
  ```

  (of `COMMANDS=nobody`).
- Er wordt nooit iets herstart. De pakketnamen worden aan beide kanten gecontroleerd; vrije commando's zijn niet mogelijk.
- Elke server komt apart in het logboek, met de uitkomst en de uitvoer.

### Risico accepteren

Soms los je een lek bewust niet op. In het venster van een CVE accepteer je het risico voor alle servers of voor één pakket op één server.

- Een **reden is verplicht**, en je kiest hoe lang: 30, 90 (standaard) of 180 dagen, 1 jaar of voor altijd.
- Een geaccepteerde kwetsbaarheid telt niet meer mee en geeft geen melding; je ziet hem terug onder het filter *Geaccepteerd*.
- Is de tijd om, dan telt hij vanzelf weer mee, zodat je er opnieuw naar kijkt.
- In het venster staat wie hem accepteerde, waarom en tot wanneer, met de knop **Opheffen**.

### Exporteren

- **⤓ CSV** bij de werkbalk (alle open kwetsbaarheden), bij *📦 Software* en in het venster van een CVE. De bestanden gebruiken puntkomma's en UTF-8, zodat Excel ze meteen goed opent. Een cel die met `=`, `+`, `-` of `@` begint, wordt nooit een formule.
- **⤓ SBOM** bij een server: de softwarelijst met de bekende kwetsbaarheden als **CycloneDX 1.5** (JSON). Elk pakket staat erin als *purl* (bijvoorbeeld `pkg:deb/debian/libssl3@3.0.11-1?arch=amd64&distro=debian-12&upstream=openssl`), met per kwetsbaarheid de ernst, CVSS, KEV, EPSS en de versie die het oplost. Dit bestand lezen andere scanners en Dependency-Track.

### Meldingen

Deze meldingen zet je aan of uit bij *Instellingen → Meldingen en rapporten → Systeemmeldingen*:

| Melding | Wanneer |
|---|---|
| 🐞 Nieuwe kwetsbaarheden | een nieuwe critical of actief misbruikte kwetsbaarheid; één melding per server |
| 🔥 Een kwetsbaarheid op je servers krijgt veel aandacht | een CVE passeert de grens van de rugbaarheid (standaard 60) of stijgt snel (+15 in 7 dagen); per server hooguit eens per 14 dagen |
| 📦 Pakketten bijgewerkt of mislukt | de uitkomst van bijwerken via de agent, met de laatste regel van de uitvoer |

Meer over meldingen staat in [Meldingen](03-meldingen.md).

### Goed om te weten

- Via de REST API lees je de tellers per server (`GET /v1.php/vulnerabilities`) en de kwetsbaarheden van één server (`GET /v1.php/vulnerabilities/{id}`), met het recht *🐞 Kwetsbaarheden lezen*. Zie [Toegang en beheer](11-toegang-en-beheer.md).
- Via MQTT krijgt elke server in Home Assistant de sensoren *Kwetsbaarheden critical*, *high* en *actief misbruikt*. Zie [Home Assistant](07-home-assistant.md).
- **Nog niet** gescand: de Home Assistant-add-on, Podman-containers, software die niet via de pakketbeheerder is geïnstalleerd (zelf gecompileerd, pip, npm …), Fedora en openSUSE, en op Windows software van anderen.

## End-of-life

Een besturingssysteem of programma dat geen updates meer krijgt, krijgt ook geen beveiligingsupdates meer. Dat zie je niet aan een CVE-lijst: er komen gewoon geen nieuwe meldingen meer. Daarom kijkt RzSense ook **tot wanneer** iets ondersteund wordt.

### Zo werkt het

- Je vindt het op *Kwetsbaarheden → ⏳ Verouderd*.
- Het staat standaard aan, ook als Kwetsbaarheden zelf uit staat. Het gebruikt dezelfde softwarelijst van de agent.
- RzSense kijkt naar het besturingssysteem (Rocky, AlmaLinux, RHEL, CentOS, Oracle Linux, Fedora, Debian en Raspberry Pi OS, Ubuntu, Alpine, openSUSE Leap, Amazon Linux, Proxmox VE) en naar bekende software (PHP, MariaDB, MySQL, PostgreSQL, nginx, Node.js, Python, Docker Engine, Redis, OpenSSL).
- De einddatums komen van [endoflife.date](https://endoflife.date), eens per dag. Alleen **productnamen** gaan de deur uit (zoals `debian` of `php`), geen versies, servernamen of IP-adressen. **🔄 Nu opzoeken** haalt het meteen op.
- Bij elke regel staat de nieuwste versie die nog wel ondersteund wordt.

### Het oordeel

| Situatie | Oordeel |
|---|---|
| ⛔ Besturingssysteem buiten ondersteuning | critical |
| ⏳ Binnen 90 dagen buiten ondersteuning | warning |
| Software van de leverancier zelf (zoals `docker-ce`) buiten ondersteuning | warning |
| ℹ️ Software uit de pakketbron van je distributie (zoals PHP op Debian) | ter info: de distributie levert vaak nog jaren beveiligingsupdates, ook als de maker zelf stopt |

### Meldingen en uitzetten

- Je krijgt een ℹ️-melding 90 en 30 dagen vooraf en op de dag zelf, één keer per stap, en een regel in het logboek van de server. De melding zet je aan of uit bij *Instellingen → Meldingen en rapporten → Systeemmeldingen → End-of-life*.
- Uitzetten: *Instellingen → Bescherming → Kwetsbaarheden en datalekken → End-of-life*, of *Instellingen → Overzicht → Status en functies → Functies*.
- Windows-servers vallen hier (nog) niet onder.

## Updates installeren

De meeste aanvallen gebruiken lekken waarvoor al lang een update is. Op de pagina **Updates** (in de bovenbalk) zie je per server welke updates er klaarstaan, en de agent kan ze voor je installeren: met de hand, in een onderhoudsvenster, of elke dag.

### Aanzetten

1. Ga naar *Instellingen → Bescherming → Updatebeleid*.
2. Zet **Updates installeren** aan.
3. Zet voor elke Linux-server **commando's als root** aan (*Instellingen → Agents en koppelingen → Commando-checks*, of per server in de Agent Manager).

Staat het uit, dan zie je op de pagina Updates wel wat er klaarstaat, maar installeert RzSense niets.

### Wat je ziet

Per server: hoeveel **updates** en **beveiligingsupdates** er klaarstaan, of er een **herstart** nodig is (↻), hoe de server bijwerkt, en wanneer het laatst gebeurde. Klik op een server voor de lijst met pakketten.

| Soort | Wat RzSense ziet en installeert |
|---|---|
| 🐧 Linux | pakketten via apt of dnf; de agent kijkt eens per uur |
| ⊞ Windows | updates via Windows Update (KB-nummers); de agent kijkt hooguit eens per 6 uur |
| 🏠 Home Assistant | Core, OS en add-ons met een update; installeren gaat via de Supervisor |
| 🗄️ TrueNAS | alleen zien; bijwerken doe je in TrueNAS zelf |

### Met de hand installeren

Klik bij een server op **🛡 Beveiliging** (alleen beveiligingsupdates) of **⬆ Alles**, en bevestig met je wachtwoord of *BIJWERKEN*. Je ziet dezelfde voortgang als bij [bijwerken via de agent](#bijwerken-via-de-agent).

### Automatisch per server

Per server stel je het in bij *Agent Manager → Updates* (of de knop **Instellen →** op de pagina Updates):

- **Wanneer**:
  - *met de hand* (standaard);
  - *in het onderhoudsvenster*: het vaste onderhoudsvenster van die server. Dat stel je in bij *Instellingen → Monitoring → Slimme drempels en advies → Voorstellen → Vaste onderhoudsvensters*. In die tijd krijg je ook geen meldingen;
  - *elke dag om* een tijd, bijvoorbeeld 03:30.
- **Wat**: *alleen beveiligingsupdates* (standaard, het veiligst) of *alle updates*.

Staat er niets klaar, dan gebeurt er niets (en komt er ook niets in het logboek).

### Meerdere servers tegelijk

1. Vink op de pagina Updates de servers aan (of niets: dan alle servers).
2. Klik **⚙️ Meerdere servers instellen…**.
3. Kies *Elke dag om een tijd, gespreid* (bijvoorbeeld de eerste om 03:00 en daarna elke server 20 minuten later), *In het onderhoudsvenster van elke server* of *Met de hand*, en of het alleen beveiligingsupdates of alles is.
4. Je ziet eerst een **voorbeeld** met per server de tijd. Klik **Opslaan**.

Spreiden zorgt dat je servers nooit allemaal tegelijk bijwerken en herstarten. Linux-servers gaan op naam, Home Assistant als laatste. De RzSense-server zelf en TrueNAS blijven met de hand. Elke wijziging komt in het logboek en is terug te zetten.

### Windows

De Windows-agent installeert via de Windows Update-API, op de achtergrond, met een vast ingebouwd script. Licenties van updates worden geaccepteerd (anders installeert Windows Update niets). *Alleen beveiligingsupdates* neemt de beveiligingsupdates en kritieke updates mee.

- Gebruik je **WSUS** of **Windows Update for Business**, dan ziet en installeert de agent wat die beschikbaar stellen.
- De agent herstart nooit. Vraagt een update om een herstart, dan zie je *↻ herstart nodig*.

Zie ook [Windows-agent](06-windows-agent.md).

### Home Assistant

Core en add-ons worden bijgewerkt via de Supervisor, **altijd met een back-up** vooraf. Het besturingssysteem (OS) niet: dat herstart het hele systeem. Dat doe je in Home Assistant zelf.

### Wat RzSense nooit doet

- **TrueNAS** met apt bijwerken: dat maakt TrueNAS kapot. Gebruik *System → Update* in TrueNAS.
- **Automatisch** de server bijwerken waar RzSense zelf op draait: gaat er iets mis, dan ziet niemand het. Dat kan alleen met de hand.
- **Herstarten**: zie je ↻, kies dan zelf een moment.
- **Een snapshot maken**: wil je terug kunnen, maak dan vooraf zelf een snapshot (bijvoorbeeld in Proxmox) of zorg voor een back-up.

### Goed om te weten

- In het logboek van de server staat wie het vroeg (of dat het automatisch was), de uitkomst en wat er werd overgeslagen.
- Aan of uit zetten kan ook bij *Instellingen → Overzicht → Status en functies → Functies*.
- Is de agent van een server te oud, dan staat bij die server welke versie nodig is. Werk de agent bij en het werkt.
