# Changelog

Alle wijzigingen aan RzMon, per versie. Nieuwste bovenaan.
Versienummers volgen [Semantic Versioning](https://semver.org/lang/nl/):
`MAJOR.MINOR.PATCH`. MINOR gaat omhoog bij nieuwe functies, PATCH bij reparaties,
en MAJOR bij wijzigingen waarvoor je zelf iets moet aanpassen.

Het versienummer staat in [`VERSION`](VERSION). Verhogen gaat met `tools/bump-version.sh` (zie [CLAUDE.md](CLAUDE.md)).

## 1.131.1 – 2026-10-29
### Opgelost
- **Agents-overzicht loopt live mee**: de kaarten (versie, verbinding, contact) werden maar één keer geladen. Na een update bleef bijvoorbeeld *v1.66.0 ⬆* staan terwijl de Agent Manager al 1.66.1 liet zien. Ze worden nu hooguit elke 30 seconden bijgewerkt.
- **"https via proxy" alleen als het echt via de proxy gaat**: draait je reverse proxy (bijvoorbeeld SWAG) op dezelfde server als een agent, dan kwam die agent rechtstreeks binnen vanaf het IP van de proxy en noemde RzMon dat "via proxy". Nu telt alleen een verzoek dat de proxy echt heeft doorgestuurd (met `X-Forwarded-…`); anders staat er gewoon *https*.

## 1.131.0 – 2026-10-29
### Nieuw
- **Geheugen van de database** bij *Instellingen → Overzicht → Status*: past de database niet in het geheugen van MariaDB (standaard maar 128 MB), dan staat er een ⚠️ met de regel voor `/etc/my.cnf.d/rzmon.cnf` (ongeveer 40% van het geheugen van de server). RzMon past de instellingen van MariaDB niet zelf aan.

### Opgelost
- **Agentrapporten veel sneller**: de endpoint-bewaking (EDR) schreef elke gebeurtenis twee keer apart weg, en elke keer wachtte de database tot de schijf het bevestigde. Bij honderden gebeurtenissen per rapport duurde een rapport zo gemiddeld 7 seconden en soms meer dan een minuut. Nu gaat alles van één rapport in één keer.
- **Checks sneller**: wat er na een meting wordt opgeslagen (resultaat, status, afwijking) gaat ook in één keer. De **diskvoorspelling** rekende bij elke beoordeling een week aan metingen door (ongeveer 10.000 per disk); de trend wordt nu hooguit eens per half uur opnieuw berekend. Hoeveel dagen het nog duurt tot de disk vol is, volgt wel elke meting.

## 1.130.1 – 2026-10-29
### Opgelost
- *✗ Geen antwoord van de update na 10 minuten* bleef in de Agent Manager staan, ook als de agent daarna met de hand was bijgewerkt en al de nieuwste versie draaide. Zodra de agent op de nieuwste versie rapporteert, staat er nu *✔ bijgewerkt naar …*.

## 1.130.0 – 2026-10-29
### Nieuw
- **Installatiecommando met https als agents alleen via https mogen**: staat *Agents alleen via https* aan (voor alle servers of voor deze server) en begint de Basis-URL met `http://`, dan gebruikt het commando in de Agent Manager (ook TrueNAS en de Home Assistant-add-on) hetzelfde adres met `https://`. De agent komt zo meteen op https te staan; met de hand aanpassen hoeft niet meer. Onder het commando staat waarom (🔒). Staat het voor één server uit, dan blijft het commando voor die server http.

### Opgelost
- **Vingerafdruk ook als je RzMon via http opent**: RzMon bepaalde de vingerafdruk van zijn eigen certificaat alleen als je browser via https verbonden was. Via http kwam er geen `--pin` in het commando, en een agent op https liep dan vast op het eigen (self-signed) certificaat. RzMon kijkt nu ook zelf op poort 443 en zet de vingerafdruk in het commando.

## 1.129.0 – 2026-10-29
### Nieuw
- **Nieuwe agent 1.66.1** (zie de [changelog van de agent](agent/CHANGELOG.md)): `rzmon-agent-setup --update` werkt weer met het eigen (self-signed) certificaat van RzMon (ook na `--cleanup`), de agent wacht 50 seconden op RzMon in plaats van 20, en stuurt de softwarelijst niet meer elke minuut opnieuw als er geen antwoord kwam.
- **Hoe lang een agentrapport duurt, per onderdeel**: bij *Instellingen → Overzicht → Gezondheid → Snelheid* staat nu *Onderdelen van een agentrapport* (ontvangen, meetwaarden, logins, IDS, weblogs, EDR, ketens, checks, software). Duurt een onderdeel ergens langer dan 5 seconden, dan staat het blok open. Zo zie je waarom een agent *geen antwoord binnen … seconden* meldt.

## 1.128.0 – 2026-10-29
### Nieuw
- **Externe URL** bij *Instellingen → Database en server → Server en PHP*: het adres waarop RzMon van buiten bereikbaar is (bijv. `https://status.voorbeeld.nl`). Leeg = niet gebruikt; je bestaande Basis-URL blijft zoals hij is.
- **Geheime link van de statuspagina: Lokaal of Extern.** Met de schakelaar *🏠 Lokaal | 🌐 Extern* naast *Geheime link* kies je welk adres in de link staat: de Basis-URL (binnen) of de externe URL (buiten). De sleutel blijft dezelfde, dus beide links werken tegelijk. Zonder externe URL staat *Extern* grijs, met een link naar Server en PHP.

### Opgelost
- Met *Alleen https* aan werd de statuspagina op een ander adres dan het beheeradres doorgestuurd naar het beheeradres. Op de externe URL opent hij nu gewoon; de rest van RzMon blijft alleen via het beheeradres bereikbaar.

## 1.127.1 – 2026-10-29
### Opgelost
- **Geen melding meer bij een kernel-update**: *Nieuw programma in een tijdelijke map* ging af als `dracut` een nieuwe initramfs bouwde (bijv. `/var/tmp/dracut.CbpkW9/initramfs/usr/bin/ps`). De werkmap van dracut (`/tmp` of `/var/tmp`, `dracut.` + 6 tekens) wordt nu overgeslagen. Een programma in een andere map, of met een naam die er alleen op lijkt, geeft nog steeds een melding, en YARA en de regel voor verborgen mappen kijken ook in de werkmap van dracut.

## 1.127.0 – 2026-10-29
### Nieuw
- **Bevestigen met je wachtwoord in een eigen venster** bij installeren (EDR-onderdelen), een server isoleren en een gebruiker vergrendelen: een strak venster met titel, uitleg, een wachtwoordveld met 👁 om het even te tonen, en *Annuleren* / *Bevestigen*. Gevaarlijke acties hebben een rode knop.

### Opgelost
- Bij deze bevestigingen stond je wachtwoord **leesbaar** in de pop-up van de browser. Het veld is nu verborgen (puntjes) en de browser kan het als wachtwoord herkennen. Op je LAN zonder account typ je zoals voorheen het woord (bijv. INSTALLEREN).

## 1.126.0 – 2026-10-29
### Nieuw
- **Vensters iets breder**: gewone vensters 680 px (was 560), brede vensters 980 px (was 900). Op de telefoon blijven ze schermbreed.
- **Toelichting achter de titel van een veld** in de hele interface: *Critical vanaf (ms)*, *Basismeting (uren) eerst alleen vastleggen wat normaal is*, in plaats van op een eigen regel tussen titel en veld. Eenheden staan tussen haakjes. Lange toelichtingen blijven een ⓘ.

## 1.125.0 – 2026-10-29
### Nieuw
- **Eigen pakketnamen bij installeren** (**agent 1.66.0 nodig**): in de Agent Manager staat bij een ontbrekend onderdeel van de endpoint-bewaking, onder *Commando*, een veld met de pakketten. Pas ze aan (bv. `audit audispd-plugins`) en klik op **Met deze pakketten installeren**. Het voorbeeld van wat de agent uitvoert loopt mee terwijl je typt. Alleen gewone pakketnamen (hooguit 10). Je bevestigt met je wachtwoord en het komt in het logboek.
- Nieuwe agent 1.66.0.

## 1.124.0 – 2026-10-29
### Nieuw
- **Commando bij installeren** (Agent Manager → *Endpoint-bewaking*): onder elk ontbrekend onderdeel staat een klein uitklapmenu **Commando**. Daarin zie je precies wat de agent uitvoert (als root, bv. `dnf -y install audit` en `systemctl enable --now auditd`) en het commando om het zelf op de server te doen, met een kopieerknop.

## 1.123.0 – 2026-10-29
### Nieuw
- **De melding *ontbreekt: auditd · installeren?* per server uitzetten**: klik erop en kies **Niet meer tonen** (of **Installeren…**). Dat geldt per server en per onderdeel: mist er later iets anders, dan zie je dat wel. **Weer tonen** staat in de Agent Manager, bij *Functies*.

## 1.122.0 – 2026-10-29
### Nieuw
- **Niets meer dubbel** (deel 3 van 3):
  - **Status**: de 5 losse punten over agents zijn één regel **Agents**, en de punten over https één regel **Beheer via https**. Eronder staan alleen wat aandacht nodig heeft en de knoppen (bv. *Alles opruimen*).
  - **IDS-onderdelen aan of uit alleen nog op *IDS → Bescherming***. De drempels van een onderdeel stel je in met **⚙️** op de kaart, bijvoorbeeld brute force, honeypot, afwijkingen, ketens, blokkeerscore, webdiensten, uitgaand verkeer en landen.
  - Op de IDS-pagina's bij *Instellingen* staan de algemene instellingen, met een verwijzing naar Bescherming. De dubbele schakelaars en lege kopjes zijn weg. Er verandert niets aan wat aan of uit staat.

## 1.121.0 – 2026-10-29
### Nieuw
- **Agents in de stijl van het dashboard** (deel 2 van 3), bij *Instellingen → Agents → Overzicht*:
  - tegels voor online, nieuwste versie, eigen sleutel en versleuteld, en verbinding in orde;
  - **⚠️ Nu aandacht nodig**: per agent wat niet goed is, met **Bijwerken**, **Agent Manager** en **Alles opruimen**;
  - een **kaart per agent** met status, versie, laatste contact en badges (sleutel, versleuteld, https, ritme, realtime, IP-adres).
- **Agents → Standaardinstellingen**: wat voor alle agents geldt (bijwerken, ritme, offline, certificaatmappen, versleuteling en beveiligde verbinding) op één pagina.
- **Agent Manager als pagina** over bijna het hele scherm: status bovenaan, **Functies** en **Verbinding** naast elkaar, **Installeren of opnieuw koppelen** ingeklapt. Geen tabbladen en niets dat zijwaarts schuift, ook niet op de telefoon.

### Opgelost
- De instellingen per agent (versleuteling, certificaat vastzetten, IP-slot, alleen https) stonden zowel in de agenttabel als in de Agent Manager; nu alleen in de Agent Manager.
- De brede agenttabel (12 kolommen) schoof op kleinere schermen zijwaarts.

## 1.120.0 – 2026-10-29
### Nieuw
- **Beheerportal in een nieuw jasje** (deel 1 van 3): overzichtelijker en zakelijker.
  - **Nieuwe indeling** in 8 groepen met **lijn-iconen** (zoals de bovenbalk): Overzicht, Monitoring, Meldingen, **IDS** (nu een eigen groep), Beveiliging (met Toegang erin), Agents, Koppelingen en Systeem.
  - **Samengevoegd** wat bij elkaar hoort:
    - **Advies** met tabbladen voor *Slimme drempels*, *Voorstellen* en *Opschonen*;
    - **Wanneer melden** (meldregels, stille uren en alarmgedrag);
    - **Database en server**;
    - **Gezondheid** (de systeemcheck en de snelheid, nu onder Overzicht).
  - **Eén titel per pagina** met één korte zin eronder; de kaarttitel die hetzelfde zei is weg.
  - **Lange uitleg in een oranje ⓘ-blok** (de kleur van *AI* in het logo), ingeklapt tot één regel; klik voor de hele tekst. Blokken die direct na elkaar staan worden één blok.
  - **Schakelaars als rijen**: de tekst links, de schakelaar rechts.
  - **Titels zonder emoji**; kopjes binnen een pagina klein en rustig.
- Oude links (zoals `#settings/smart` of `#settings/server`) gaan vanzelf naar de nieuwe plek; teksten in RzMon verwijzen naar de nieuwe namen.

### Opgelost
- Op de telefoon schoven sommige instellingenpagina's zijwaarts (door een verborgen uitlegballon).

## 1.119.0 – 2026-10-29
### Nieuw
- **🚀 Snelheid** bij *Instellingen → Systeem → Systeemcheck*. Je ziet:
  - of OPcache aan staat;
  - of je webserver comprimeert en de bestanden laat bewaren in de browser (zo niet: een stukje config voor Apache en nginx);
  - de traagste verzoeken en agent-rapporten, en hoe lang elk onderdeel van een cron-ronde duurt (laatste 24 uur);
  - de tabellen met hun ongebruikte ruimte.
- Knop **🧹 Database optimaliseren**.
- Schakelaar **Snelheid meten** (standaard aan).
- **Meldingen** laadt standaard de laatste 7 dagen en de **IDS** de laatste 14 (open incidenten altijd allemaal). Ouder laden kan met de knop onderaan; zoeken haalt vanzelf alles op.
- Nieuwe installaties (`install-rocky.sh`): Apache comprimeert en de browser bewaart de bestanden van de interface 30 dagen.

### Opgelost
- **RzMon werd steeds trager**, ook bij weinig servers:
  - Elk verzoek las alle code van RzMon in (1,5 MB). Nu alleen wat nodig is: zonder OPcache ~35 ms → ~1 ms per verzoek.
  - Live bijwerken haalde elke paar seconden alles opnieuw op zodra de IDS iets nieuws zag, ook de meldingen en de IDS-pagina. Nu wordt alleen herladen wat veranderde.
  - Een trage pagina hield de sessie vast, zodat alle andere verzoeken van je browser moesten wachten. Leesverzoeken geven de sessie nu meteen vrij.
  - De beschikbaarheid (7 en 30 dagen) werd elke 2 minuten uit alle metingen berekend. Nu komt die uit de dagtotalen; in de test ging dat van ~420 ms naar ~13 ms. *7 dagen* telt nu per hele dag.

## 1.118.0 – 2026-10-28
### Nieuw
- **Bolletjes bij incidenten** (*IDS → Incidenten*): een bundel heeft een **blauw bolletje** (*7×*), een incident dat vaker gezien is een **paars bolletje** (*3×*) in plaats van de grijze *(3×)*. Met de muis erover zie je meer: bij een bundel de server, hoeveel open en van hoe laat tot hoe laat; bij een herhaling wanneer het eerst en laatst gezien is.
- Klik op het blauwe bolletje om de bundel open te klappen (zoals voorheen), en op de titel voor het incidentvenster.

### Opgelost
- De nummers onderin het incidentvenster lopen nu alleen door de incidenten van de regel waarop je klikte. Eerder kwamen er ook incidenten van dezelfde soort uit andere regels van die dag bij (bijvoorbeeld al afgehandelde).
- De knop **▶ Doorlopen** is weg: klik op de titel.

## 1.117.0 – 2026-10-28
### Nieuw
- **De pagina IDS in de stijl van het dashboard** (boven alle tabbladen):
  - een **score-tegel met ring**: de gemiddelde hardening-score, met 🔴 *Actie nodig*, 🟠 *Aandacht* of 🟢 *Rustig*; de modus (Leren/Blokkeren) staat nu als pil in de tellerbalk;
  - **⚠️ Nu aandacht nodig**: open critical-incidenten en aanvalsketens met **Afhandelen** en **⛔ IP blokkeren**; maximaal 5, de rest uitklappen. Niets dringends? Dan één regel;
  - **serverkaarten** voor servers met open incidenten: aantallen, hardening, blokkades en een staafje per dag over 7 dagen; de andere servers compact onder *✔ Rustig*;
  - **📊 Aanvallen per dag**: 14 dagen gestapeld per soort, uitklapbaar (standaard dicht);
  - de werkbalk van *Incidenten* op één regel.
- Schakelaar **Moderne weergave** bij *Instellingen → Beveiliging → IDS: detectie* (standaard aan); uit = de compacte weergave van voorheen.

## 1.116.0 – 2026-10-28
### Nieuw
- **Gebundelde incidenten doorlopen** (*IDS → Incidenten*): onderin het incidentvenster staan nummers (‹ 1 2 3 … 8 ›) voor alle incidenten van dezelfde soort, server en dag, met per nummer • open of ✔ afgehandeld en *3 van 8 · ✔ 2 afgehandeld · 6 open*. Bladeren kan ook met ← →.
- Na **Afhandelen**, **Dit is normaal** of **Heropenen** gaat het venster vanzelf door naar het volgende open incident van de bundel; is alles klaar, dan sluit het.
- **▶ Doorlopen** in de lijst opent een bundel meteen bij het eerste incident.
- Herhalingen binnen één incident: kies met **Gebeurtenis 1 2 3 …** welke gebeurtenis je ziet (nieuwste voorop).
- Bij een afgehandeld incident staat ook wanneer het werd afgehandeld.

## 1.115.0 – 2026-10-28
### Nieuw
- **🛡️ IDS → Bescherming**: alles wat het IDS doet, als kaarten per groep. Per kaart wat het ziet (met de drempel), de ernst, de actie (alleen melden, IP blokkeren, proces stoppen, server isoleren, vanzelf afhandelen), een schakelaar, hoe vaak het de laatste 7 dagen raak was (met *Toon incidenten*) en een aandachtspunt als het ergens niet werkt. Bovenaan de modus Leren of Blokkeren.
- **Waarom / Trigger / Actie bij elk incident**: waarom het een melding is (de regel en de drempel), wat het deed afgaan, en wat RzMon deed met de stand van nu (geblokkeerd tot, gestopt ✔, geïsoleerd, gedeblokkeerd door, verlopen). In de modus Leren staat erbij wat RzMon in Blokkeren zou doen. Ook in Pushover, en als label achter de titel in de lijst. Oude incidenten krijgen een eenvoudige versie.

## 1.114.0 – 2026-10-28
### Nieuw
- **✔ RzMon zelf is altijd toegestaan** (**agent 1.65.0 nodig**): wat de agent doet (en alles wat hij start), wat RzMon op zijn eigen server doet (webinterface, API en cron) en het verkeer met RzMon (rapporten, checks, netwerkscan) houdt het IDS nooit meer tegen: geen proces stoppen, niet blokkeren of isoleren, geen incident, en het telt niet mee voor afwijkingen, ketens of dreigingsinformatie.
- **Wel gelogd, in het groen** op *🛡️ IDS → 🛰️ Live*: per rapport één samengevatte regel (*✔ toegestaan · agent: 38 proces(sen)*, klik voor de lijst), en apart als een regel geraakt zou zijn (*zou … raken, maar is RzMon zelf*) of bij iets bijzonders (installeren, herstellen, bijwerken). Met de schakelaar *Toegestaan tonen*.
- Suricata, de DNS-controle en de weblogs herkennen de adressen van RzMon: een netwerkscan van RzMon wordt een groene regel in plaats van een incident.
- Nieuwe agent 1.65.0.

### Opgelost
- Een programma van een gewone gebruiker dat zich "rzmon-agent" noemde, werd door de endpoint-bewaking overgeslagen. Nu telt alleen de echte agent als RzMon.
- RzMon kon zijn eigen opzoeking van een DNS-naam (PHP die een shell start) aanzien voor een webshell.

## 1.113.0 – 2026-10-28
### Nieuw
- **🧭 DNS-controle via AdGuard** (IDS 2.0, deel 4): RzMon leest elke minuut de query-log van AdGuard Home en meldt per apparaat (ook zonder agent: tv, camera's, IoT) domeinen van malware en botnets (URLhaus, ThreatFox), wat AdGuard Safe Browsing tegenhield, antwoorden die naar een bekende aanvaller wijzen, mogelijke DNS-tunnels en verzonnen domeinen. Is het apparaat een server, dan telt het mee voor de ketens.
- **Suricata** (**agent 1.64.0 nodig**): de agent stuurt nieuwe alerts uit `eve.json` mee; ernst 1 is critical (en de aanvaller van buiten wordt geblokkeerd), ernst 2 warning, ernst 3 een signaal. Met de stap van een aanval (MITRE) erbij.
- **YARA** (**agent 1.64.0 nodig**): RzMon stuurt zijn eigen YARA-regels versleuteld naar de agents. Staat `yara` op een server, dan wordt elk nieuw bestand in tijdelijke mappen en elk nieuw script in webmappen gecontroleerd op webshells, cryptominers, reverse shells, IoT-botnets, Kinsing en rootkits. YARA installeren kan via de Agent Manager (optioneel).
- Instellingen in *IDS → Dreigingsinformatie → 🧭 Netwerk*.
- Nieuwe agent 1.64.0.

## 1.112.0 – 2026-10-28
### Nieuw
- **🌐 Dreigingsinformatie** (IDS 2.0, deel 3, *Instellingen → IDS → Dreigingsinformatie*): RzMon zoekt de IP-adressen en bestanden uit je incidenten op bij **AbuseIPDB** (met een veld voor je eigen API-sleutel en een knop *Sleutel testen*), **GreyNoise**, **Shodan InternetDB**, **CIRCL hashlookup**, **abuse.ch** (Feodo Tracker, URLhaus, en met een gratis Auth-Key ook MalwareBazaar en ThreatFox) en de lijst met **Tor-uitgangen**. Op de achtergrond, met een cache en een limiet per dag; er gaat alleen het IP-adres of de hash naar buiten.
- **Oordeel** per IP (bekende aanvaller, verdacht, scanner, onschuldig, Tor) bij het incident, en alle details bij het IP-adres in het incidentvenster, met links.
- **RzMon handelt ernaar**: een bekende aanvaller blokkeren (modus Blokkeren), een login vanaf een bekende aanvaller critical maken, een webaanval van een onschuldige scanner vanzelf afhandelen, een verbinding met een botnet-server meteen critical (en het proces stoppen), bekende malware critical. Elk los aan of uit.
- **Aanval op een lek dat de server echt heeft**: een webaanval met een CVE die volgens *🐞 Kwetsbaarheden* op die server openstaat, is critical, met het pakket en de versie die het oplost.
- **Melden bij AbuseIPDB** (standaard uit): wie RzMon automatisch blokkeert, hooguit één keer per dag per IP.
- abuse.ch Feodo Tracker (botnet-servers) hoort nu bij de bekende aanvallers die de agents vooraf blokkeren; Tor-uitgangen kun je daar ook bij zetten.

## 1.111.0 – 2026-10-28
### Nieuw
- **🧬 Afwijkingen** (IDS 2.0, deel 2): RzMon leert per server 14 dagen wat normaal is (welke programma's, wie ze start, gebruikers, verbindingen, luisterpoorten, landen, container-images en hoeveel er per uur gebeurt) en meldt daarna wat die server nog nooit deed. Elke afwijking krijgt een score; vanaf 40 wordt het een incident (warning), lager staat het alleen op de pagina. Per server *Leren afronden* of *Opnieuw leren*.
- **🔗 Ketens en combinaties**: RzMon kijkt na elk rapport of de meldingen van het laatste uur samen een aanval vormen: *webaanval gevolgd door code op de webserver*, *inloggen gevolgd door rechten of een achterdeur*, *programma binnengehaald en daarna contact naar buiten*, *meerdere stappen van een aanval*, *veel afwijkingen tegelijk* en *mogelijk verder het netwerk in* (een login vanaf een eigen server waar net een aanval was). Een keten is critical (Pushover), met alle stappen in volgorde. In de modus Blokkeren blokkeert RzMon de aanvaller en isoleert hij de server bij een webshell of malware.
- **🛡️ IDS → 🧬 Afwijkingen**: de leerstatus per server, de ketens en de afwijkingen op één pagina.
- **🛡️ IDS → 🧭 MITRE**: alles van de laatste 7, 30 of 90 dagen in de matrix van MITRE ATT&CK (versie 20), per stap van een aanval; klik op een techniek voor de meldingen. Ook te zien: welke technieken de regels herkennen.
- Instellingen in *IDS → Endpoint-bewaking → 🧬 Afwijkingen en ketens*.
- Payload-inspectie herkent nu ook WordPress File Manager (CVE-2020-25213).

### Opgelost
- *Ja, dit was ik* bij een endpoint-incident: RzMon stopte het proces daarna toch nog (alleen de melding bleef weg).

## 1.110.0 – 2026-10-28
### Nieuw
- **🛰️ Endpoint-bewaking** (IDS 2.0, deel 1; **agent 1.63.0 nodig**): de agent kijkt op het moment zelf mee naar nieuwe processen, gevoelige paden (passwd, sudoers, cron, systemd, SSH-sleutels, kernelmodules), verbindingen en luisterpoorten, nieuwe bestanden in `/tmp`, `/dev/shm` en de webmappen, en containers. RzMon toetst alles aan **eigen regels met uitleg** (webshell, reverse shell, `curl | sh`, cryptominer, programma's uit `/dev/shm` …) en aan **121 Linux-regels van SigmaHQ**. Een treffer wordt een IDS-incident met het bewijs erbij.
- **Zelf ingrijpen** (in de modus ⛔ Blokkeren, elk los aan/uit): **proces stoppen**, **IP blokkeren** of de **server isoleren** (24 uur). Het staat altijd in het incident, en een critical gaat naar Pushover. In de modus Leren wordt het alleen gemeld.
- **Per server aan/uit** in de Agent Manager (standaard aan), algemeen in *Instellingen → IDS → Endpoint-bewaking*.
- **Ontbrekende onderdelen onder de servernaam**: mist een server `auditd` of `inotify-tools`, dan zie je dat op het Dashboard en in de Agent Manager, met **installeren via de agent** (met bevestiging en je wachtwoord) of het commando om het zelf te doen.
- **Payload-inspectie van webverzoeken** (SWAG): RzMon decodeert verdachte verzoeken en herkent SQL-injectie, XSS, command injection, path traversal, Log4Shell, SSRF, SSTI en meer, bekende exploits met hun **CVE** en scanners zoals sqlmap. Eén incident per IP, met het verdachte stuk gemarkeerd; in de modus Blokkeren wordt het IP geblokkeerd.
- **Inspectie in het incidentvenster**: het programma, de opdracht, de ouder, de gebruiker, de verbinding of het bestand, de verzoeken, MITRE ATT&CK-technieken (klikbaar) en wat RzMon deed.
- **🛡️ IDS → 🛰️ Live**: alles wat de agents de laatste 3 dagen zagen, live bijgewerkt, met *Alleen treffers* en een filter per server. **🔏 Regels**: alle regels met uitleg en treffers, per regel aan of uit.
- Nieuwe agent 1.63.0.

## 1.109.0 – 2026-10-28
### Nieuw
- **⚡ Echt live**: RzMon bouwt het scherm niet meer elke keer opnieuw op, maar past alleen aan wat veranderde. Wat je openklapte (een server, een dag bij Meldingen, *Verbindingscontrole* in de Agent Manager), een menu, wat je typt en je scrollpositie blijven staan. Op Dashboard, Meldingen, IDS, Kwetsbaarheden en Netwerk.
- **Nieuwe meetwaarden meteen zichtbaar**: een waarde die verandert, licht even op en telt naar de nieuwe waarde; een check die van status wisselt, kleurt even mee; *"Laatste check … geleden"* telt door.
- **Meldinkje bij een nieuw probleem** rechtsonder (als er echt een melding uitging); klik erop en de check opent.
- **Het venster van een check werkt live bij** zolang het open is.

### Opgelost
- De Agent Manager klapte *Verbindingscontrole* na een paar seconden weer dicht.

## 1.108.2 – 2026-10-28
### Opgelost
- **Slim interval en melden na N**: met slim interval checkt RzMon bij een probleem elke 20 seconden, waardoor *melden na N* veel sneller bereikt werd (melden na 6 = 2 minuten in plaats van 6). Nu telt het ook in tijd: een check op *melden na 3* met een interval van 1 minuut meldt pas na 2 minuten, net als zonder slim interval. Een korte hapering blijft dus stil. De tabel bij *Slimme drempels* telt op dezelfde manier.

## 1.108.1 – 2026-10-28
### Opgelost
- **Slimme drempels, voorstel voor bestaande checks**: een dag met een warning telt nu net als een echte melding, pas als er zoveel metingen achter elkaar te hoog waren als de check vraagt (*melden na N*). Eerder telde één losse piek al, waardoor bv. een camera "8 van 8 dagen" kreeg. De cijfers worden vannacht opnieuw berekend.

### Nieuw
- **Duidelijkere uitleg** bij dat voorstel: kolommen *Dagen met warning – nu* en *– met slim*, een kolom **Waarom** in gewone woorden (bv. *✔ 6 dag(en) minder met een warning* of *geen winst*), en grijs voor checks waar slim niets oplevert.
- **Grillige checks**: piekt een check bijna elke dag even (zoals de webinterface van een camera), dan staat dat erbij, met in de popup van de check een tip: *melden na 3*, of een ping-check voor de bereikbaarheid.

## 1.108.0 – 2026-10-27
### Nieuw
- **Nieuw logo**: RzMon met een klein oranje **AI** erachter, in de bovenbalk, op het inlogscherm en in `brand/`.
- **💡 Slimme voorstellen** (*Instellingen → Checks → Slimme voorstellen*): elke nacht bekijkt RzMon de geschiedenis en stelt voor wat beter kan. Er verandert niets tot jij op **Toepassen** klikt; een genegeerd voorstel komt niet terug. Alles staat in het wijzigingslogboek, ook in 📊 Analyse.
  - **Melden na N**: een check die vaak kort hapert en vanzelf herstelt, krijgt een voorstel voor *melden na 2 tot 6 mislukte checks*; een check die lang wacht terwijl storingen altijd lang duren, een voorstel om meteen te melden.
  - **Vast onderhoudsvenster**: gaat iets steeds op hetzelfde moment kort mis (de back-up om 2:00, de herstart op zondag), dan een voorstel voor een vast venster, voor de server of één check.
  - **Afhankelijkheden**: volgens UniFi, of omdat een server steeds tegelijk uitvalt met een router of switch.
- **🗓️ Vaste onderhoudsvensters**: elke dag of op één weekdag een vaste tijd zonder meldingen, voor een server of één check. Zelf toe te voegen op dezelfde pagina.
- **⏫ Escaleren** (standaard uit, *Meldregels en stille uren*): een storing die niemand bevestigt, na een tijd nog een keer melden, een stap hoger (warning na 30 min als critical, critical na 15 min als noodmelding). Instelbaar, en niet tijdens de stille uren.
- **⏱️ Slim interval** (standaard uit): bij een probleem checkt RzMon een ping-, TCP-, HTTP- of SNMP-check elke 20 seconden; is alles een uur goed, dan mag het interval tot 2× zo lang. Beide instelbaar.
- **📈 Afwijkingen zonder drempels**: bij checks met een getal maar zonder drempels (verkeer, clients, processen, logregels, commando's) leert RzMon wat normaal is per uur, en meldt een flinke afwijking als ℹ️ (standaard alleen op de meldingenpagina).
- **💾 Disk groeit hard**: een ℹ️ als een disk in een uur 5 procentpunt (instelbaar) of meer groeit, ook ver onder de drempel.
- **🕵️ Oorzaak erbij**: in de eerste melding van een storing staat wat er vlak ervoor op die server gebeurde (update, herstart, wijziging in RzMon).
- **📥 Heartbeats leren hun ritme**: een warning als een heartbeat later is dan hij normaal binnenkomt, of als de meegestuurde waarde veel kleiner of groter is dan normaal.
- Alles apart aan en uit te zetten; een update verandert geen bestaande instellingen.

## 1.107.0 – 2026-10-27
### Nieuw
- **🧠 Slimme drempels**: RzMon leert per check wat normaal is, per uur van de week (maandag 3:00 is anders dan dinsdag 14:00), en zet daar de drempels op. Per check te kiezen: ✋ *Handmatig*, 🧠 *Slim* of 🧠🛡️ *Slim met vangnet* (vanaf een harde grens altijd critical).
  - Werkt bij ping, TCP, HTTP, CPU, RAM en SNMP (CPU, geheugen, interface, load, temperatuur, UPS, UniFi). Disks houden vaste drempels met de voorspelling.
  - Na 7 dagen gegevens; daarna elke nacht bijgesteld, hooguit 10% per nacht. Elke aanpassing staat in het wijzigingslogboek, terug te draaien.
  - Stijgt de normaalwaarde sluipend (een lek?), dan een ℹ️-melding, en dan schuiven de drempels niet mee omhoog.
  - In de popup van een check: wat nu normaal is, een weekgrafiekje en hoeveel dagen met een warning het met vaste en met slimme drempels was; in de grafiek de normale band.
  - **Nieuwe checks** krijgen de standaard uit *Instellingen → Checks → Slimme drempels* (standaard slim met vangnet). **Bestaande checks blijven handmatig**: daar staat een voorstel om ze om te zetten, ook in 📊 Analyse.
  - Gevoeligheid (rustig, normaal, scherp) voor alles en per check; ook voor meerdere checks tegelijk.
- **🔗 Storingen bundelen over servers heen**: gaan er binnen het bundelvenster meerdere servers mis, dan één bericht met de waarschijnlijke oorzaak (dezelfde server erboven, hetzelfde access point of dezelfde switch via UniFi, alle agents tegelijk stil, hetzelfde Proxmox-cluster). Uit te zetten bij *Alarmgedrag*.
- **🩹 Zelf herstellen** (agent 1.62.0 nodig): per check een service of container laten herstarten, of een eigen herstelcommando dat alleen óp de server staat. RzMon meldt pas als het niet helpt.
- **🐕 Waakhond** (agent 1.62.0 nodig): per agent aan te zetten in de Agent Manager. De agent stuurt zelf een Pushover-bericht als RzMon onbereikbaar is of de checks stilstaan.
- **⚡ Live bijwerken**: wijzigingen (storingen, meldingen, bevestigen, onderhoud) staan binnen een paar seconden op je scherm, zonder de pagina telkens helemaal te laden. Een groen bolletje bij *Laatste check* laat zien dat het werkt.
- **⌨️ Zoeken en doen (Ctrl+K)**: servers, checks, tabbladen, instellingen en IP-adressen, en met een woord als *onderhoud*, *nu*, *bevestig*, *grafiek*, *bewerk* of *blokkeer* meteen de actie. Op de telefoon met de 🔍-knop.
- **🟢 Statuspagina**: een eenvoudige pagina voor huisgenoten of klanten via een geheime link (ook van buiten), met de onderdelen die je kiest, een balk per dag over 90 dagen en een eigen mededeling. *Instellingen → Koppelingen → Statuspagina*.
- **🧪 Automatische tests** in GitHub: de agent op Debian, Ubuntu, Rocky, Fedora en Alpine (elk met zijn eigen awk), PHP-tests met een echte database en een rondje in de browser.
- *Nieuwe agent 1.62.0.*

### Opgelost
- **Installeren op MariaDB 11.7 of nieuwer** mislukte (*SQL syntax … near 'VARCHAR(200) NULL'*): de kolom `vector` is daar een gereserveerd woord. Gevonden door de nieuwe automatische tests.

## 1.106.2 – 2026-10-27
### Opgelost
- **📊 Analyse: de grafiekjes in de kerncijfers** staken onder de tegel uit en stonden naar rechts verschoven. Ze staan nu netjes onderaan in de tegel, op één lijn.
- Een grafiek met dagen zonder gegevens (bijvoorbeeld vóór de eerste meting) tekende een schuin vlak vanaf links; die dagen blijven nu leeg.

## 1.106.1 – 2026-10-27
### Opgelost
- **Nieuwe agent 1.61.1**: een proces met een backslash in zijn opdrachtregel maakte het rapport deels onleesbaar (*proc_list · Syntax error*), vooral op Debian en Proxmox. Werk de agent bij via de Agent Manager.

## 1.106.0 – 2026-10-27
### Nieuw
- **📊 Analyse**: een nieuw tabblad dat laat zien hoe het over langere tijd gaat, alleen voor beheerders en gebruikers (niet voor *alleen lezen*). Kies een periode (24 uur tot 365 dagen, of eigen datums); elk cijfer wordt vergeleken met de periode ervoor.
  - **Kerncijfers**: beschikbaarheid, storingen, gemiddelde hersteltijd, meldingen, IDS-incidenten en een score.
  - **🎯 Wat nu aanpakken**: concrete punten, de belangrijkste eerst (disks die vollopen, guests zonder back-up, flappende checks, riskante poorten, kwetsbaarheden met een update …), met een klik naar de plek waar je het oplost.
  - **Betrouwbaarheid, capaciteit, beveiliging, netwerk** (ook UniFi en AdGuard Home), **back-ups** en **meldingen en ruis**, met grafieken.
- RzMon houdt elk uur **dagtotalen** bij en bewaart die een jaar, ook als de losse meetwaarden al zijn opgeruimd. De eerste keer rekent RzMon terug wat er nog is.
- **Maandrapport per e-mail** op de 1e van de maand, zelf aan en uit te zetten (standaard uit), met **Nu versturen**.

## 1.105.2 – 2026-10-27
### Opgelost
- **SNMP: "Failure in sendto (Operation not permitted)"** krijgt nu uitleg: een firewallregel op de RzMon-server zelf houdt het pakket tegen, nog vóór het netwerk. Is er een server geïsoleerd, dan staat erbij welke (misschien de RzMon-server zelf), anders hoe je de regels bekijkt (`sudo nft list ruleset`).
- **De RzMon-server zelf isoleren kan niet meer**: dat legt alle checks stil (ping, SNMP en poorten naar netwerken buiten je *Lokale netwerken*).

## 1.105.1 – 2026-10-27
### Opgelost
- **UniFi: "gebruikersnaam of wachtwoord klopt niet" bij een zelf gehoste Network-app**, terwijl ze wel klopten. RzMon probeerde eerst het inlogadres van UniFi OS; een zelf gehoste controller antwoordt daar met *LoginRequired*, en RzMon stopte dan al. Nu probeert RzMon beide, en gebruikt bij de zelf gehoste controller ook de juiste CSRF-sleutel (nodig om te weren).
- Lukt inloggen niet, dan staat het antwoord van de controller erbij, en een account dat om een tweede stap (2FA of e-mailcode) vraagt, krijgt een eigen melding.

## 1.105.0 – 2026-10-27
### Nieuw
- **🖥️ Proxmox VE** (agent 1.61.0 nodig; *Nieuwe agent 1.61.0*). De agent op een Proxmox-host leest Proxmox zelf uit, en RzMon heeft er een nieuw checktype voor:
  - **Overzicht van de node**: een guest die automatisch moet starten maar uit staat, opslag die niet beschikbaar is, een ongezonde ZFS-pool, geen quorum (CRITICAL); een gepauzeerde of vergrendelde guest of een mislukte back-uptaak (WARNING).
  - **Eén guest**: draait hij, RAM-gebruik, en of de guest agent antwoordt.
  - **Back-ups**: de nieuwste back-up per guest (standaard 2 dagen warning, 7 dagen critical, per guest aan te passen), nooit een back-up, en guests die in **geen enkele back-uptaak** zitten.
  - **Opslag** en **ZFS-pools**: vulling, beschikbaarheid, gezondheid en de laatste scrub.
  - **🖥️ Proxmox** bij de server: alle guests met status, gebruik en laatste back-up, ＋ Check per guest, opslag, ZFS en de back-uptaken.
  - **Losse nodes als cluster**: geef ze dezelfde clusternaam en het overzicht toont ze samen.
  - **＋ Aanbevolen checks** herkent Proxmox, en de Agent Manager heeft een tabblad **🖥️ Proxmox VE**.

## 1.104.0 – 2026-10-27
### Nieuw
- **📡 UniFi en AdGuard Home voor de netwerkscan** (*Instellingen → Koppelingen → UniFi en AdGuard Home*, met **🧪 Verbinding testen**).
  - **UniFi** (Network-app, ook 9 en 10; UniFi OS of zelf gehost): naam, wifi-netwerk, access point en bedraad of niet, ook van apparaten die niet op ping antwoorden. Met een lokaal account, of alleen lezen met een API-sleutel.
  - **AdGuard Home** (meerdere instanties): namen van clients, en in het venster van een apparaat **met welke domeinen het praat** (query-log, met wat er geblokkeerd werd).
- **⛔ Een apparaat weren** (tabblad Netwerk, beheerders, met je wachtwoord): van het wifi (UniFi), geen DNS meer (alle AdGuard-instanties) en geen toegang tot RzMon en je servers; 1 dag, 7 dagen of tot je het weer toelaat. **✅ Weer toelaten** draait alles terug, en na de gekozen tijd gebeurt dat vanzelf. RzMon zelf, je router, de controller, de AdGuard-servers, servers in RzMon, de whitelist en je eigen apparaat kun je niet weren. Nieuw filter *⛔ Geweerd*.
- De RzMon-server staat in de lijst als *RzMon-server (deze server)*.

### Opgelost
- Een willekeurig (privé) MAC-adres krijgt geen fabrikant meer: zo'n adres hoort bij geen enkele fabrikant.

## 1.103.0 – 2026-10-27
### Nieuw
- **🔎 Netwerkscan en het tabblad Netwerk.** RzMon zoekt welke apparaten er op je lokale netwerken zitten (*Toegang → Lokale netwerken*, alleen privé-netwerken tot /22). Aanzetten bij *Instellingen → Beveiliging → Netwerkscan* (standaard uit).
  - Elk uur een snelle ronde (ping en ARP-tabel), standaard 4× per dag een diepere scan: open poorten, NetBIOS-naam, UPnP (merk en model), mDNS (met `avahi-browse`) en de titel van de webpagina. De fabrikant komt uit het MAC-adres (lijst van Wireshark, maandelijks bijgewerkt).
  - Per apparaat: soort (gok, zelf aan te passen), naam, IP, MAC, fabrikant, open poorten en wanneer gezien; willekeurige (privé) MAC-adressen worden herkend.
  - **＋ RzMon** opent *Server toevoegen* met naam, adres en soort al ingevuld; verder **bekend**, **negeren**, een eigen naam en een notitie.
  - **Riskante poorten** (Telnet, FTP, VNC, RDP, Docker zonder TLS, Redis …) in rood, met uitleg.
  - ℹ️-melding **Nieuw apparaat op je netwerk** (standaard alleen op de meldingenpagina, in te stellen bij *Systeeminfo*); de eerste scan is de basismeting.
  - Een teller op het tabblad, een punt bij *Hoe staat het ervoor?*, en de scan bij *Verbindingen en taken*.

## 1.102.0 – 2026-10-27
### Nieuw
- **⏳ Wachtende apparaten** (*Instellingen → Van buiten → Onzichtbaar van buiten*). Staat de webinterface van buiten alleen open voor toegestane apparaten, dan komt een browser die geweigerd wordt nu op een lijst in de beheerportal. Daar kun je hem **toestaan** voor één gebruiker, **negeren** of het IP **blokkeren**.
  - De bezoeker krijgt dezelfde 404 als altijd, met een wachtende sleutel in een cookie. Sta je hem toe, dan krijgt hij bij zijn volgende bezoek het inlogscherm (alleen voor die gebruiker, met 2FA). Een ander apparaat vanaf hetzelfde IP blijft een 404 krijgen.
  - Alleen browsers uit je normale landen; scanners en bots komen er niet op. Hooguit 3 nieuwe per IP per uur; een wachtend apparaat vervalt na 7 dagen.
  - Een ℹ️-melding *Geweigerd apparaat van buiten* (standaard alleen op de meldingenpagina), een punt bij *Hoe staat het ervoor?* en een bolletje in de zijbalk.
  - Aan of uit met *Geweigerde apparaten bijhouden* (standaard aan).

## 1.101.1 – 2026-10-27
### Opgelost
- **Onterechte critical-meldingen voor services als `ssh`, `cron`, `nginx` of `podman`** na 1.100.0. RzMon maakte van de oude regel `SERVICES` in `/etc/default/rzmon-agent` service-checks, ook voor namen die op die server niet bestaan (op Rocky heten ze `sshd` en `crond`) of niet als vaste service draaien (`podman`). Vóór 1.100.0 gaven die nooit een melding.
  - Een service uit `SERVICES` wordt nu alleen een check als hij op de server draait.
  - De checks die al zo gemaakt waren en niet draaien, haalt de update één keer weg. Ze staan in het **wijzigingslogboek** (*draaide niet; uit SERVICES*) en zijn daar terug te zetten. Checks die je zelf maakte, blijven ongemoeid.

## 1.101.0 – 2026-10-27
### Nieuw
- **Klik op een IDS-incident** voor een venster met:
  - **💡 Wat is dit** en **⚖️ Hoe erg is het**, in gewone taal en ingevuld met de gegevens van het incident (gebruiker, IP-adres, pad, poort, proces);
  - **❓ Was jij dit?**: *Ja, dit was ik / bekend* handelt het af en meldt het voortaan niet meer; *Nee* toont meteen de noodstappen;
  - **🛠️ Wat te doen**, met commando's om te kopiëren (waarden uit het incident staan altijd tussen aanhalingstekens, zodat kopiëren nooit iets anders uitvoert);
  - **🛡️ Voorkomen** en **🚨 Noodstappen**;
  - alle acties: afhandelen, dit is normaal, IP blokkeren, op de whitelist, tijdlijn van het IP, gebruiker vergrendelen, server isoleren, beveiliging van de server en de Agent Manager.
- **🌐 IP-gegevens in het venster**:
  - omgekeerde DNS, en of die naam ook terugwijst naar het IP (anders is de naam niet te vertrouwen);
  - eigenaar en netwerk via RDAP (de opvolger van whois), met het adres om misbruik te melden;
  - land, en of het een hosting- of cloudbedrijf is (vandaar komen vooral scanners);
  - of het op een lijst van aanvallers staat;
  - een eigen server in RzMon, whitelist of blokkades, en hoe vaak het eerder gezien is.

  Opzoeken gebeurt los van het venster (dat opent meteen), met korte time-outs (DNS 2 s, register 5 s) en wordt 7 dagen bewaard. Een mislukte opzoeking wordt na een uur opnieuw geprobeerd.
- **Hardening-score**: klik op de score (zoals nu), en elk punt klapt open met wat het is, waarom het belangrijk is, hoe je het controleert, hoe je het oplost (commando's per systeem) en waar je op moet letten. Punten die niet goed zijn, staan meteen open.

## 1.100.0 – 2026-10-27
**Nieuwe agent 1.60.0** (agent 1.60.0 nodig voor `--status`, het melden van overbodige instellingen en de controle of het script ongewijzigd is).

### Nieuw
- **🔐 Verbindingscontrole per agent**, in de Agent Manager, de agenttabel (kolom 🔐) en *Hoe staat het ervoor?*. RzMon controleert:
  - eigen sleutel;
  - versleuteld;
  - via https;
  - certificaat gecontroleerd;
  - IP-slot;
  - versie;
  - sleutel vernieuwd;
  - geen oud token meer;
  - instellingen op de server opgeruimd;
  - agent-script ongewijzigd (dezelfde SHA-256 als de agent van RzMon);
  - reservekopie.

  De uitkomst gaat ook terug naar de server, voor `rzmon-agent-setup --status`.
- **🧹 Opruimen**:
  - **per agent** in de Agent Manager: het oude token wissen, en de agent installeert zichzelf opnieuw met een reservekopie en opgeruimde instellingen;
  - **alle agents tegelijk**: *Instellingen → Agents → Beveiligde verbinding → Alle agents opruimen*, of de knop in *Hoe staat het ervoor?*. Hebben alle agents een eigen sleutel, dan wordt ook de oude algemene sleutel gewist en gaat *alleen agents met een eigen sleutel* aan.
- **Alleen agents met een eigen sleutel**: nieuwe instelling. Een agent die zich met alleen een token meldt, wordt geweigerd; koppelen met een koppelcode blijft altijd kunnen. Aanzetten kan alleen als alle agents een eigen sleutel hebben.
- **Tokens weg**: zodra een agent een eigen sleutel heeft (koppelen of eerste versleutelde rapport) wist RzMon zijn token; een token over de lijn kan dan niet meer.
- **SERVICES wordt een service-check**: services uit de verouderde regel `SERVICES` op de server worden service-checks in RzMon (in het wijzigingslogboek); daarna haalt het opruimen de regel weg.
- **⚠️ Melding als een agent niet veilig verbindt**: zonder eigen sleutel, onversleuteld, het script gewijzigd, of het certificaat van RzMon niet gecontroleerd. ✅ als het weer goed is. Kanalen: *Instellingen → ℹ️ Systeeminfo → Agent verbindt niet veilig*.
- **🌐 Naar https** in de Agent Manager voor een agent die nog via http verbindt (de agent test het nieuwe adres eerst).

### Opgelost
- Bijwerken vanuit RzMon (en `rzmon-agent-setup --update`) mislukte bij agents die met een koppelcode waren gekoppeld (agent 1.60.0).


## 1.99.2 – 2026-10-27
**Nieuwe agent 1.59.2.**

### Opgelost
- **Agent bleef stil met "RzMon weigert dit rapport" (HTTP 400)**: is een rapport onleesbaar, dan leest RzMon het nu per onderdeel (disks, services, processen, certificaten …) en laat alleen het kapotte onderdeel weg. De agent meldt zich dus gewoon, ook met een oudere agent. In de *Agent Manager* staat dan ⚠️ welk onderdeel het was, met het stukje tekst waar het misging.
- Agent 1.59.2: de proceslijst is altijd geldige JSON, ook met een landinstelling met decimale komma.

## 1.99.1 – 2026-10-27
**Nieuwe agent 1.59.1.**

### Opgelost
- **Agent 1.59.0 meldde zich niet meer** (vooral op Proxmox/Debian): stond er in een opdrachtregel van een proces een teken als é op de verkeerde plek, dan was het rapport ongeldige UTF-8 en weigerde RzMon het helemaal. RzMon leest zo'n rapport nu gewoon (alleen dat ene teken wordt vervangen), dus een agent 1.59.0 werkt meteen weer; agent 1.59.1 verstuurt alleen nog geldige UTF-8.
- **"Agent stil sinds …"** toont de tijd nu als `dd-mm-jjjj uu:mm:ss`.

## 1.99.0 – 2026-10-27
### Nieuw
- **Contact met de agent**: in de agenttabel (*Instellingen → Agents*, kolom **Contact**), in de *Agent Manager* en op de serverkaart zie je wanneer de agent zich het laatst meldde (tijd en "… geleden") en wanneer het volgende contact komt ("over 37 s"), live aftellend. Komt hij niet op tijd: 🟠 "… te laat"; na de offline-tijd 🔴 "offline sinds …". Met realtime aan: "of eerder bij een gebeurtenis ⚡".
- **Echte ritme**: RzMon onthoudt de laatste 20 contacten per agent en toont het ritme dat de agent werkelijk aanhoudt. Komt hij duidelijk minder vaak dan ingesteld (bv. elke minuut via cron terwijl er 30 s staat), dan staat er ⚠️ "werkelijk elke … (ingesteld …)". Werkt met elke agentversie.
- **Sorteren op contact**: klik op de kolomkop *Contact ⇅* om de agents die het langst niets van zich lieten horen bovenaan te zetten (je browser onthoudt de keuze). 

## 1.98.0 – 2026-10-27
**Nieuwe agent 1.59.0** (agent 1.59.0 nodig voor het zoeken naar certificaatbestanden, certificaten op de server zelf en proces-checks op agent-servers).

### Nieuw
- **Certificaatbestanden kiezen**: bij een SSL-check op een agent-server staat een lijst met de certificaatbestanden die de agent op de server vond, met naam (CN), uitgever en vervaldatum. Klik er een aan om hem te bewaken. Met **🔄 Opnieuw zoeken** zoekt de agent meteen opnieuw (anders eens per dag). Extra mappen: Instellingen → Agents → *Ook zoeken naar certificaten in*.
- **Zelf invullen**: een pad (`/etc/ssl/mijn.crt`) of `localhost:443` werkt ook. Een bestand en `localhost` leest de agent op de server zelf; bij *Certificaat lezen* kies je anders zelf: automatisch, vanaf RzMon of op de server zelf.
- **Hele keten**: verloopt een tussencertificaat eerder dan het certificaat zelf, dan telt die datum ("let op: … in de keten verloopt eerder").
- **Nieuwe certificaatcheck telt alleen de vervaldatum**: *Certificaat controleren* staat bij een nieuwe SSL-check standaard uit, zodat self-signed en eigen CA's ook gewoon werken. Bestaande checks blijven zoals ze zijn.
- **Proces-check**: nieuw checktype *Proces*, net als een service. Op naam of op een patroon in de opdrachtregel (regex), eventueel alleen van één gebruiker. Draait het niet: CRITICAL. Optioneel een minimum en maximum aantal (WARNING) en drempels voor CPU (%) en RAM (MB). Is het proces herstart, dan komt er een ℹ️ info-melding. Op agent-servers kies je hem uit de lijst van wat er draait; op de server van RzMon zelf werkt hij zonder agent.
- **Voorstellen** (🔎 Wat wordt nog niet bewaakt): ook gevonden certificaatbestanden en TLS-poorten op de server (443, 8443, 993, 8883 …), als certificaatcheck via de agent. 

## 1.97.0 – 2026-10-26
### Nieuw
- **📜 Wijzigingslogboek** (*Instellingen → Systeem*): wie veranderde wat en wanneer, met de oude en de nieuwe waarde. Het gaat om instellingen, servers, checks (ook bij meerdere tegelijk) en sjablonen.
  - **↩ Terugzetten** per wijziging. Een verwijderde check of server komt terug met hetzelfde id (zonder meethistorie).
  - Wachtwoorden en sleutels staan er nooit leesbaar in.
  - Bewaren: 30 dagen, 90 dagen (standaard), 1 jaar of 3 jaar.
- **📦 Exporteren en importeren** (*Instellingen → Systeem*): servers, checks, sjablonen en eventueel instellingen als één JSON-bestand, zonder geheimen. Importeren voegt alleen toe (bestaande servers, sjablonen en checks blijven), met eerst een voorbeeld en je wachtwoord.
- **🧪 Meldketen testen** (*Meldingen → Kanalen*): één testmelding door alle kanalen die aan staan, met per kanaal ✓ of ✕ en de fout.


## 1.96.0 – 2026-10-26
### Nieuw
- **🧹 Opschoonadvies** (*Instellingen → 🧩 Checks*): checks die al dagen onbekend zijn, ruismakers (de meeste meldingen), checks die steeds wisselen, al lang in warning staan, net onder de drempel zitten of al lang uit staan. Elk met een voorstel en een knop: *melden na 3*, uitzetten, verwijderen, of *niet meer tonen*.
- **Gezondheid van RzMon** in *Systeemcheck*:
  - de grootte van de database, met de grootste tabellen, de groei per dag en wanneer de disk vol is;
  - hoe lang een cron-ronde duurt, en of hij uitloopt;
  - de traagste checks;
  - PHP-fouten van de laatste 24 uur. RzMon houdt die nu zelf bij, samengevat per soort, 7 dagen.


## 1.95.0 – 2026-10-26
### Nieuw
- **💡 Nog niet bewaakt:** wat de agent ziet maar nog geen check heeft, staat bij de server als *💡 N nieuw gevonden*. Het gaat om disks, services die draaien en automatisch starten, containers, certificaten en poorten die van buiten bereikbaar zijn. Vink aan en voeg toe, of negeer het, dan komt het niet meer terug.
- **🧪 Nu proberen** in het checkformulier: zie meteen wat de check zou zeggen (status, waarde, bericht) voordat je opslaat. Er wordt niets bewaard of gemeld.


## 1.94.0 – 2026-10-26
### Nieuw
- **📋 Sjablonen** (*Instellingen → 🧩 Checks → Sjablonen*):
  - Een sjabloon is een set checks die je zelf maakt, met hetzelfde formulier als bij een server, of maakt van een bestaande server.
  - Koppelen bij het sjabloon of bij *✎ Server bewerken*. Een server kan meerdere sjablonen hebben, en een bestaande check van dezelfde soort wordt overgenomen.
  - `{adres}` en `{naam}` worden per server ingevuld.
  - Pas je het sjabloon aan, dan gaan alle gekoppelde servers mee, behalve checks die je op een server zelf hebt aangepast. Die zet je terug met *↩ Terug naar het sjabloon*.
  - Drie startsjablonen om aan te passen: *Linux-server (agent)*, *Website* en *Netwerkapparaat*.
- **☑️ Alle checks** (*Instellingen → 🧩 Checks → Alle checks*): alle checks in één lijst, met zoeken en filters per server en type.
  - Vink er meerdere aan en pas ze tegelijk aan: interval, drempels (bij hetzelfde type), melden, onderhoud, aan of uit.
  - Kopieer ze naar andere servers, of verwijder ze (met je wachtwoord).


## 1.93.0 – 2026-10-26
### Nieuw
- **Kwetsbaarheden en Datalekken: altijd dicht, en RzMon onthoudt wat jij open had.** Per account (ook op je telefoon), ook met *Alles openen / sluiten*. *Slim openen* telt op deze pagina's niet meer mee. Met zoeken of een filter gaan de blokken met treffers tijdelijk open; daarna staat alles weer zoals jij het had.


## 1.92.2 – 2026-10-26
### Opgelost
- **Elke minuut een nieuwe sessie "LAN, zonder wachtwoord"** bij *Ingelogde apparaten* (met "Inloggen zonder wachtwoord op het LAN" aan). Een controle die RzMon opvraagt zonder cookies te bewaren (de diepere controle van httpd/nginx door de agent op de RzMon-server, of een HTTP-check op RzMon zelf) gaf elke keer een nieuwe sessie. Een LAN-sessie komt nu pas in de lijst als dezelfde browser terugkomt; de oude losse sessies ruimt RzMon vanzelf op.

## 1.92.1 – 2026-10-26
### Opgelost
- **Bijwerken stopte soms met "PHP-extensie mbstring ontbreekt"** terwijl mbstring er gewoon is. De controle in het installatiescript (`php -m | grep -q`) kon door de manier van uitlezen af en toe mislukken; het script vraagt het nu rechtstreeks aan PHP. Bleef je update daardoor op 1.92.0 met een foutmelding staan? Werk dan bij naar deze versie: het installatiescript loopt dan alsnog helemaal door.

## 1.92.0 – 2026-10-26
### Nieuw
- **Instellingen opnieuw ingedeeld**: alles staat één keer, op de plek waar het hoort. Je instellingen veranderen niet en oude links blijven werken.
  - **✅ Overzicht**: Hoe staat het ervoor? · Verbindingen en taken.
  - **🔔 Meldingen**: Kanalen · Meldregels en stille uren · Alarmgedrag · Systeeminfo.
  - **🛡️ Beveiliging**: de IDS in drie pagina's (*detectie*, *aanvallers en verkeer*, *servers*) · Kwetsbaarheden · Datalekken.
  - **🔐 Toegang**: Inloggen · **Van buiten** (https, onzichtbaar van buiten, apparaten en passkeys op één pagina) · Gebruikers.
  - **🖥️ Agents**: één tabel met alle agents (versie, ritme, realtime, sleutel, IP, verbinding, laatste rapport en ⚙️ Agent Manager) · Beveiligde verbinding · Commando-checks.
  - **🔗 Koppelingen**: MQTT · REST API en API-sleutels.
  - **🛠️ Systeem**: RzMon bijwerken · Back-up · Database en opslag · Server en PHP · Systeemcheck.
- **De setup zit nu in de beheerportal**: Systeemcheck (met de cron-regel), Database en opslag (verbinding testen en opslaan met je wachtwoord, tabellen bijwerken, databasewachtwoord, bewaartermijnen) en Server en PHP (tijdzone, basis-URL, ping, PHP-waarden). `setup.php` is er alleen nog voor de installatie en stuurt je daarna door.
- **Blokkades en whitelist** staan in het tabblad **IDS → ⛔ Blokkades** (niet meer dubbel in de instellingen).
- **Verbindingen en taken**: *Binnenkomend* is nu één regel per soort, met een link naar de volledige lijst.
- *Agent offline na …* staat bij Agents; de bewaartermijnen staan bij Database en opslag.


## 1.91.0 – 2026-10-26
### Nieuw
- **📡 Verbindingen en taken** (*Instellingen → Systeem*):
  - **Binnenkomend:** agents (ritme, realtime, laatste rapport), browsers van de laatste 24 uur, API-sleutels en heartbeats.
  - **Achtergrondtaken:** cron, nieuwe versie zoeken, blocklists, GeoIP, datalekken, kwetsbaarheden, certificaat, MQTT en back-up, met wanneer ze laatst gelukt zijn, de volgende keer en een eventuele fout.
- **⚠️ Achtergrondtaak lukt niet:** lukt een taak te lang niet, dan één melding via je gewone kanalen en een ✅ als het weer lukt. Kanalen en aan/uit bij *Meldingen → ℹ️ Systeeminfo*. Ook als aandachtspunt in *Hoe staat het ervoor?*.
- Het interval van een check is minimaal **1 minuut**: de checks draaien één keer per minuut, dus 30 seconden deed in de praktijk hetzelfde. Een bestaande check met 30 s blijft zoals hij is.
- Handleiding: het schema bij *Hoe werkt het?* bijgewerkt, met een tabel *Wat gebeurt wanneer?*.

## 1.90.1 – 2026-10-26
### Nieuw
- **Interval van een check in minuten en uren** in plaats van seconden. Het formulier heeft een keuzelijst van 30 seconden tot 24 uur. Een bestaande check met een andere waarde (bv. 90 s) houdt die, als *1,5 minuten (huidig)*. Ook het info-venster van een check toont het zo.

## 1.90.0 – 2026-10-26
### Nieuw
- **RzMon bijwerken: een versie kiezen** (*🔁 Andere versie…*). Je kiest uit alle versies van de changelog, ook oudere (terugzetten, met je wachtwoord). Niets kiezen is de nieuwste.
  - Bij een andere versie dan de nieuwste pauzeert automatisch bijwerken, tot je weer naar de nieuwste gaat.
  - Werkt vanaf de update naar deze versie: de hulpdienst wordt daarbij bijgewerkt.
- Checktypes zonder icoontjes; *Herstart* heet nu **Uptime**.

## 1.89.0 – 2026-10-26
### Nieuw
- **📜 Logs van webdiensten** (IDS, **agent 1.58.0 nodig**), aan/uit per bron:
  - **SWAG**: bekend aanvalspad (`/.env`, `/.git/`, `wp-login.php` … met een foutcode) en scanners meteen; veel 404, veel 401/403 en verzoeken zonder hostnaam (444) boven een grens.
  - **Home Assistant** en **Vaultwarden**: mislukte logins boven een grens.
  - De agent zoekt de logs zelf (container, gangbare mappen, Supervisor). Je kunt elk pad zelf invullen; per server zie je wat gevonden is.
  - Elk scenario apart aan of uit, met aanpasbare aantallen en tijd.
  - In de modus blokkeren optioneel blokkeren op al je servers (standaard uit). Je eigen netwerk en de whitelist nooit; deblokkeren bij *Blokkades en whitelist*.
  - Een ⚠️ als Home Assistant achter SWAG alleen interne adressen ziet (`use_x_forwarded_for`).
- Nieuwe agent 1.58.0.

### Opgelost
- **Blokkades golden niet voor Docker-containers.** Verkeer naar een container (bv. SWAG) ging langs de blokkade heen. Agent 1.58.0 blokkeert vóór Docker (prerouting).

## 1.88.0 – 2026-10-26
### Nieuw
- **🧰 Hardening-score** per server (IDS, **agent 1.57.0 nodig**). Punten: SSH (wachtwoord, root, lege wachtwoorden), firewall, automatische updates, open databases, Docker-socket, accounts zonder wachtwoord, sudo zonder wachtwoord, `/tmp` noexec en SELinux.
  - Per punt advies en *negeren*.
  - Een incident als een punt slechter wordt.
- **🧱 Server isoleren**: alleen RzMon, je LAN en jouw IP kunnen er nog bij; in, uit en containers dicht. 1 uur tot 3 dagen, met wachtwoord. Vervalt vanzelf, ook als RzMon onbereikbaar is. Met een ℹ️-melding.
- **🔒 Gebruiker vergrendelen** op een server (en zijn processen stoppen), en weer vrijgeven.
- **🔎 Tijdlijn per IP**: klik op een IP-adres (Meldingen, IDS, Blokkades) voor alles wat RzMon ervan weet, over al je servers, met *Op de whitelist*.
- Nieuwe agent 1.57.0.

## 1.87.0 – 2026-10-26
### Nieuw
- **🕸️ Webshell** (IDS, **agent 1.56.0 nodig**): een webserver (nginx, apache, php-fpm …) die een shell of `curl`/`wget`/`python` start, is critical. In de modus blokkeren kan de agent dat proces meteen stoppen (schakelaar, standaard uit). Met **✅ Vrijgeven** wordt het voortaan niet meer gemeld of gestopt.
- **Nieuwe automatisch startende dienst of timer** (systemd): warning, tijdens pakketupdates stil.
- **🌐 Uitgaande verbindingen**:
  - Naar een **bekende aanvaller**: critical.
  - Naar een land buiten je normale landen: warning, per programma; tijdens de basismeting aangeleerd.
  - In de modus blokkeren optioneel uitgaand geblokkeerd, ook vanuit containers.
  - **✅ Vrijgeven** zet het IP op de whitelist.
- Alles aan of uit bij *Instellingen → 🛡️ IDS*. Melden staat standaard aan, stoppen en blokkeren uit.
- Filters op de IDS-pagina: *🌐 Uitgaand* en *🕸️ Webshell en diensten*.
- Nieuwe agent 1.56.0.

## 1.86.0 – 2026-10-26
### Nieuw
- **🕶️ Onzichtbaar van buiten** (*Instellingen → 🔐 Beveiliging → Onzichtbaar van buiten*), met schakelaars:
  - **Niet herkenbaar** (standaard aan): van buiten geen naam of versie meer (ping), het agent-script alleen voor bekende agents of met de downloadsleutel uit de installatiecommando's, en geweigerd of onbekend (agent, REST API, heartbeat, ack-link, geblokkeerd IP) krijgt een gewone **404** zoals nginx die geeft. De reden staat in Meldingen. Ook staat het versienummer niet meer op het inlogscherm.
  - **Webinterface alleen op toegestane apparaten** (standaard uit): van buiten zie je zonder toegestaan apparaat niet eens een inlogscherm, alleen een 404. Agents, heartbeats, ack-links en de REST API werken gewoon.
  - **Alleen vanuit je normale landen** (de landen bij IDS → Bekende aanvallers en land).
  - **Noodlink**: sleutel kwijt onderweg? Een vast adres (`/?nood=jouwnaam`) stuurt een link naar je e-mail (en Pushover voor een beheerder), één keer te gebruiken en 15 minuten geldig.
- **📱 Toegestane apparaten**, per gebruiker. Je zet een apparaat thuis op de lijst bij *Mijn account → Van buiten en passkeys* (met je wachtwoord). Het krijgt een eigen sleutel (cookie, 1 jaar) en geldt alleen voor jouw account. Beheerders zien, hernoemen en trekken alles in.
- **🗝️ Passkeys**: inloggen met Face ID, vingerafdruk of een beveiligingssleutel, in plaats van wachtwoord en 2FA (die blijven de reserve). Een passkey werkt alleen voor het echte domein, dus een nep-site kan er niets mee. Alleen via https met een domeinnaam. Per account en per apparaat te verwijderen.
- Systeeminfo-meldingen (zelfde kanalen als de rest): *📱 apparaat toegestaan of ingetrokken*, *🔑 passkey aangemaakt of verwijderd*, *🚪 noodlink aangevraagd of gebruikt* (standaard ook naar Pushover).
- Nieuwe agent 1.55.1 (installatiescript met downloadsleutel).

## 1.85.1 – 2026-10-26
### Opgelost
- **Na een nacht toch uitgelogd, ondanks *Onthoud mij*.** De sleutel van een onthouden apparaat werd bij elke automatische login vervangen. Miste de telefoon het antwoord met de nieuwe sleutel (browser 's nachts in slaap, even geen verbinding), dan zag RzMon later de oude sleutel aan voor een gestolen cookie en vergat het apparaat.
  - De sleutel wordt nu hooguit één keer per dag vervangen.
  - De vorige blijft geldig tot de nieuwe echt is aangekomen.
  - Een echt hergebruikte oude sleutel geeft nu een ⚠️-melding.

### Nieuw
- Het inlogscherm zegt waarom een onthouden apparaat niet vanzelf binnenkwam. Bijvoorbeeld: onthouden zonder 2FA terwijl je buiten je netwerk bent, langer dan 30 dagen niet gebruikt, of vergeten.

## 1.85.0 – 2026-10-25
### Nieuw
- **⏱️ Uitloggen na … zonder activiteit** (*Instellingen → Toegang en inlogbeveiliging*): 30 minuten, **2 uur** (standaard), 8 uur of 1 dag. Tot nu toe was dat vast 24 minuten (de standaard van PHP), waardoor je telefoon na een tijdje in je zak uitgelogd was.
- Sessies staan nu in de database. De opruimtaak van het systeem gooit ze dus niet meer na 24 minuten weg.
- Na deze update moet je één keer opnieuw inloggen. Met *Onthoud mij* gaat dat vanzelf.

## 1.84.2 – 2026-10-25
### Opgelost
- **"Ongeldig CSRF token" na een tijdje niets doen** (bv. de telefoon in je zak). De sessie was dan verlopen en via *Onthoud mij* vanzelf vernieuwd, maar de pagina hield het oude token. Opslaan of bevestigen gaf daardoor die fout. De pagina neemt het nieuwe token nu automatisch over en probeert het één keer opnieuw.

## 1.84.1 – 2026-10-25
### Nieuw
- **Lokaas heet nu 🍯 Honeypot**, in het Nederlands en het Engels: honeypot-gebruikers, honeypot-poort, *Geen honeypot-naam* op de whitelist. Je instellingen blijven gewoon staan.

### Opgelost
- **De reden van een blokkade staat in jouw taal.** RzMon bewaart de reden in het Nederlands, dus in het Engels zag je bijvoorbeeld *Lokaas-gebruiker: admin*. Nu zie je *Honeypot user: admin*, ook bij blokkades van voor deze update. Dat geldt voor IDS → Blokkades en voor *Blokkades en whitelist*.

## 1.84.0 – 2026-10-25
### Nieuw
- **Meldingen: chip *Zonder info*.** Toont alles behalve de ℹ️-systeeminfo (ingelogd, agent verbonden, bijgewerkt …). Storingen, herstel, beveiliging en bevestigingen blijven staan. De tegels bovenaan tellen de info dan ook niet mee.
- De gekozen chip op de pagina Meldingen wordt nu **per gebruiker** onthouden (eerder alleen in die browser).

## 1.83.0 – 2026-10-25
### Nieuw
- **⛔ Blokkades en whitelist** (*Instellingen → 🔐 Beveiliging*). Eén overzicht van alles wat geblokkeerd is: IP-adressen in RzMon, vergrendelde accounts en de IDS-blokkades per server (🌐 als een automatische blokkade op al je servers geldt).
  - Per regel zie je het land, de reden (bij lokaas ook de naam), sinds, tot en door wie.
  - Er zijn twee tabbladen: *Nu geblokkeerd* en *Eerder*. *Eerder* toont opgeheven en verlopen blokkades, zo lang als de meldingen bewaard worden.
  - Je kunt filteren op soort en zoeken.
- **🔓 Deblokkeren** per regel, meteen.
- **✅ Op de whitelist**: je kiest 1, 7 of 30 dagen of altijd, bevestigt met je wachtwoord, en het komt in het auditlog. De blokkade wordt meteen opgeheven. Er zijn drie soorten:
  - **IP-adres of netwerk**: nooit blokkeren, in RzMon én door het IDS op al je servers (ook niet via de lijsten met bekende aanvallers).
  - **Account**: nooit vergrendelen na mislukte logins; het IP van de aanvaller wordt wel geblokkeerd.
  - **Naam is geen lokaas**: valt af van de lokaas-namen, op alle servers.
- Links naar het overzicht bij *IDS → Blokkades* en bij een vergrendeld account onder *Gebruikers*.
- De lijst *Nooit blokkeren* van het IDS wordt bij de update overgenomen in de whitelist. Daar beheer je hem voortaan.

## 1.82.0 – 2026-10-25
### Nieuw
- **☠️ IDS: bekende aanvallers.** Dagelijks Spamhaus DROP (IPv4 en IPv6) en FireHOL level 1 ophalen. In de modus blokkeren zetten de agents ze vooraf in hun firewall (**agent 1.55.0 nodig**). In de modus leren krijgen logins en incidenten van zo'n adres het label ☠️. Je eigen netwerk staat er nooit in.
- **🌍 Land per IP-adres** (DB-IP Lite, maandelijks, op de RzMon-server zelf): een vlag bij logins, mislukte pogingen, incidenten, blokkades en in het weekoverzicht. Een **SSH-login van buiten vanuit een land dat niet bij *Normale landen* staat** (standaard NL) is critical; vanaf een bekende aanvaller ook.
- **🔓 Open poort met een actief misbruikte kwetsbaarheid** (KEV): een critical-incident in de IDS, met de update die het oplost.
- *Instellingen → 🛡️ IDS → Bekende aanvallers en land*, met de stand en *Nu bijwerken*.
- Nieuwe agent 1.55.0.

## 1.81.0 – 2026-10-25
### Nieuw
- **🪤 IDS-lokaas** (agent 1.54.0 nodig):
  - **Lokaas-gebruikers** (standaard `admin test guest user oracle ubuntu pi ftp support postgres`, aan te vullen): één mislukte SSH-login met zo'n naam is genoeg om het IP meteen te blokkeren. Namen die op een server echt bestaan, gelden daar niet.
  - **Lokaas-poort** (standaard TCP 2323): elke verbinding is een scanner. Er luistert niets; de agent zet een regel in zijn eigen firewalltabel. Ook in de modus leren (dan alleen melden).
  - Van buiten: incident en meteen blokkeren. Vanaf je eigen netwerk: 🔴 critical met Pushover (waarschijnlijk een besmet apparaat).
- **Automatische blokkades op alle servers** (standaard aan): een aanvaller die op één server automatisch geblokkeerd wordt, is meteen op al je servers geblokkeerd.
- **Meer achterdeuren bewaakt** (agent 1.54.0): `/etc/pam.d/*` (critical), `/etc/hosts`, `/etc/resolv.conf`, `/etc/environment`, `at`-jobs, `.bashrc`/`.profile` van root en systemd-gebruikersunits. Bij het eerste rapport stil vastgelegd.
- **Groepen** (agent 1.54.0): wordt iemand lid van `wheel`, `sudo`, `docker`, `root`, `adm` of `lxd`, dan is dat een 🔴 critical-incident (*rehman toegevoegd aan de groep docker*).
- **📬 Wekelijks IDS-overzicht**: standaard maandag 08:00 per e-mail (of Pushover). Geblokkeerde aanvallen, mislukte logins en top-IP's, lokaas, SSH-logins van buiten en open incidenten. Met *Nu versturen*.
- Nieuwe agent 1.54.0.

## 1.80.2 – 2026-10-25
### Opgelost
- **Menu ⋯ viel van de pagina af** als de werkbalk op een smaller scherm doorloopt naar een tweede regel (de knop staat dan helemaal links). Het menu klapt nu naar rechts uit als er links geen ruimte is; dat geldt voor de ⋯-menu's op elke pagina.

## 1.80.1 – 2026-10-25
### Opgelost
- **Hoe staat het ervoor? → Tweestapsverificatie** telt beheerders die **alleen lokaal** mogen inloggen (🏠, bv. een noodaccount zonder 2FA) niet meer als aandachtspunt: van buiten kan niemand met zo'n account inloggen. Ze staan er nog wel bij als *alleen lokaal, zonder 2FA*.

## 1.80.0 – 2026-10-25
### Nieuw
- **IDS: geslaagde logins op je servers.**
  - **SSH-login van buiten** je netwerk: een nieuw incident 🔑 *SSH-login van buiten* (warning) met een ⚠️-melding. Hij blijft open tot je *Gezien* of *Dit is normaal* kiest. Dezelfde gebruiker vanaf hetzelfde IP binnen 24 uur telt op.
  - **SSH- en console-logins van binnen**: een ℹ️ systeeminfo-bericht, per kanaal aan of uit bij *Meldingen → Systeeminfo → Login op een server* (standaard alleen op de meldingenpagina).
  - Geen dubbele meldingen: geeft een login al *Root-login*, *nieuw IP* of *Inlog na brute force*, dan komt er niets bij. De lijst *Negeren* geldt ook.
  - Login-incidenten vanaf je eigen netwerk (*nieuw IP*, *Root-login*) sluiten na 24 uur vanzelf; die van buiten blijven open.

## 1.79.1 – 2026-10-25
### Opgelost
- **Beveiliging → Nooddeur stond rood** (*niet vertrouwd of de naam klopt niet*) terwijl alles in orde was. Op de nooddeur staat het eigen certificaat van RzMon, en dat is niet door een bekende uitgever ondertekend; dat is normaal. Het blok is nu groen met 🔏 *self-signed*, zoals in het certificaatvenster, en alleen oranje of rood als het certificaat bijna verloopt, verlopen is of niet bereikbaar. Dat gold ook voor het standaardcertificaat dat Rocky Linux bij Apache aanmaakt (uitgever *Unspecified*).

## 1.79.0 – 2026-10-25
### Nieuw
- **📱 Ingelogde apparaten**: RzMon houdt nu elke ingelogde sessie bij, niet alleen *Onthoud mij*: ook inloggen met wachtwoord of 2FA en automatisch via je LAN. Per sessie het apparaat, het IP-adres (🏠 binnen of 🌍 buiten je netwerk), hoe er is ingelogd, sinds wanneer en laatst actief.
  - **Mijn account → Ingelogde apparaten**: je eigen sessies, met **Uitloggen** per apparaat en **Alle andere apparaten uitloggen** (dit apparaat blijft).
  - **Instellingen → Gebruikers → Ingelogde apparaten** (beheerders): alle sessies van alle gebruikers, te filteren per gebruiker, met **Uitloggen**, **Alles van deze gebruiker uitloggen** en de noodknop **Iedereen uitloggen behalve mij** (met je wachtwoord).
  - Uitloggen werkt bij de volgende klik op dat apparaat; een onthouden apparaat wordt daarbij ook vergeten. Uitloggen van andermans sessies komt in het logboek.
  - Een wachtwoordwijziging logt ook alle andere sessies van dat account uit.

## 1.78.0 – 2026-10-25
### Nieuw
- **🔒 Agents lokaal via https, zonder proxy** (agent 1.53.0 nodig): agents in je netwerk kunnen rechtstreeks naar `https://<IP van RzMon>` met het eigen certificaat van RzMon, dat ze **vastzetten**. RzMon kijkt per adres welk certificaat er staat en geeft de agent per adres door hoe hij het controleert (📌 vastzetten of 🔒 gewone controle). Zo werken een rechtstreeks adres en je proxy-adres (DNS-rewrite) samen als gewoon adres en reserve-adres.
- **Nieuw adresvenster** (*🌐 Alle agents naar een ander adres* en per agent in de Agent Manager): kies uit de IP-adressen, namen (DNS, hostnaam, certificaat) en het beheeradres van RzMon, of vul zelf een IP-adres of naam in en test het. Je ziet per adres wat er op staat. http of https naar keuze, en het reserve-adres kun je uitzetten of los aanpassen.
- **Certificaat vernieuwen in twee stappen**: zetten agents het eigen certificaat vast, dan zet RzMon het nieuwe eerst klaar. De agents krijgen de nieuwe vingerafdruk erbij, en pas als ze die allemaal hebben (uiterlijk na 7 dagen) wisselt RzMon. Met **Nu wisselen** en **Annuleren** in het certificaatvenster; in de terminal `--renew-cert --activate` en `--now`.
- **Reserve-adres: één melding**: pas na 5 minuten, en één samengevatte melding voor alle agents (*⚠️ 12 agents via hun reserve-adres*), en één ✅ als ze terug zijn. Een korte onderbreking van je proxy geeft geen meldingen meer.
- **Hoe staat het ervoor?**: nieuwe punten *Proxy → RzMon via http* (met de regels voor SWAG om te kopiëren), *Inloggen via http* (knop **Weigeren**), *Tip: overal hetzelfde adres* (DNS-rewrite) en *Agents alleen via https* (knop **Aanzetten** zodra alle agents via https komen).
- **Vandaag staat altijd open** op Meldingen, IDS-incidenten en Logins; de andere dagen zijn dicht. Klap je vandaag dicht en komt er iets nieuws bij, dan gaat hij weer open. Is er vandaag nog niets, dan staat er een lege balk *Vandaag · geen meldingen 👌*.
- Nieuwe agent 1.53.0.

## 1.77.0 – 2026-10-25
### Nieuw
- **📥 Heartbeat-checks** (passieve checks): een script, cronjob of apparaat meldt zich zelf bij RzMon, bv. na een back-up. Blijft de melding uit, dan hoor je het.
  - **Schema**: *elke … minuten/uren/dagen*, *elke dag om …*, *elke week op …* of een eigen **cron-schema** (bv. `30 2 * * 1-5`), met een **speling**. Te laat = WARNING, na twee keer de speling CRITICAL.
  - **Start, ok en fail**: *fail* is altijd CRITICAL (met de reden erbij). Met *start* meet RzMon hoe lang het duurt; loopt het veel langer dan normaal (vanzelf 2× het gemiddelde, of een eigen maximum), dan krijg je *hangt het?*.
  - **Waarde meesturen** (bv. de grootte van de back-up in GB) met eigen drempels.
  - **Twee manieren om te melden**: op een server met de agent het commando `rzmon-hb backup-db ok` (versleuteld via de agent, **agent 1.52.0 nodig**), of overal elders een **geheime URL** (`curl …/hb.php/<code>`). De URL werkt standaard alleen vanaf je lokale netwerk; per check zet je *ook van buiten* aan. Ook voor Home Assistant (`rest_command`), Synology, Proxmox en andere apparaten zonder agent.
  - In het checkformulier staan de kant-en-klare commando's en de URL om te kopiëren. Voorbeelden voor Proxmox Backup, rsync/restic/borg, Synology Hyper Backup, een MariaDB-dump, certbot en Home Assistant staan in de handleiding.
- Nieuwe agent 1.52.0.

## 1.76.2 – 2026-10-25
### Opgelost
- **MQTT, sensor *Algehele status***: in het Engels weigerde Home Assistant de waarde (*Ignoring invalid option received on topic 'rzmon/health', got 'All good'*), omdat de lijst met toegestane waarden altijd Nederlands was. Die lijst volgt nu de taal van de meldingen, net als de waarde zelf; de sensor krijgt weer een waarde en de foutmelding verdwijnt uit het log van Home Assistant. Tip voor automatiseringen: het attribuut `state` (`ok`, `attention`, `outage`) hangt niet van de taal af.

## 1.76.1 – 2026-10-24
### Opgelost
- Geen dubbel *Alles in orde* meer op het dashboard: zonder open problemen verdwijnt de balk onder de tegels. Zijn er bevestigde problemen, dan staat er een klein regeltje *✔ Geen open problemen · 2 bevestigd* met **bekijken** (toont de problemen).

## 1.76.0 – 2026-10-24
### Nieuw
- **📷 Camera** als soort apparaat, met een eigen groep **📷 Camera's** op het dashboard (chip, blok bij *Alle*, en tellers en tegels als je die groep kiest).
  - **Vanzelf herkend** via SNMP (Axis, Hikvision, Dahua, Hanwha, of *camera*/*NVR* in de beschrijving) en aan de **naam** (*cam*, *camera*, *IPCam*, *CCTV*, *deurbel*); zelf te wijzigen bij *✎ Bewerken → Soort apparaat*.
  - **＋ Aanbevolen checks** bij een camera: ping, RTSP (poort 554) voor de videostroom, en de webpagina (poort 80/443, standaard uit).

## 1.75.0 – 2026-10-24
### Nieuw
- **Soort apparaat**: elke server is nu een 🖥️ server, 🌐 router, 🔀 switch, 📶 access point, 🧱 firewall, 🗄️ NAS, 🔋 UPS, 🏠 smart home, 🖨️ printer of 📦 overig.
  - **Vanzelf herkend**: agent = server, HA-add-on = smart home; via SNMP aan het apparaat zelf (UniFi, Synology, QNAP, TrueNAS, UPS-MIB, APC, printers, MikroTik, Cisco, Netgear, TP-Link, pfSense, OPNsense, Fortinet, Linux, Proxmox …). Zelf te kiezen bij *✎ Bewerken → Soort apparaat*.
- **Dashboard per groep**: de chips bovenaan zijn nu *Alle · Problemen · Onderhoud · 🖥️ Servers · 🌐 Netwerk · 🗄️ Opslag · 🔋 Stroom · 🏠 Smart home · 📦 Overig*, met een rood bolletje bij open problemen. De keuze wordt per gebruiker onthouden.
  - Bij *Alle* staat er een **blok per groep** (met problemen en alles in orde).
  - Met één groep gekozen tellen ook de **tellers, tegels en *Nu aandacht nodig*** alleen die groep.
  - Op de kaarten staat het icoon van de soort apparaat.
- *⚲ Filters*: nieuw onderdeel **Soort apparaat** (Dashboard, Meldingen, IDS); het oude *Soort server* heet nu **Bewaking** (agent, remote, Home Assistant, lokaal). De chips *Agent / Home Assistant / Remote / Lokaal* zijn daarin opgegaan.

## 1.74.0 – 2026-10-24
### Nieuw
- **UniFi access points via SNMP** (UBNT-UniFi-MIB): bij *📡 SNMP-checks* staat bij een UniFi-AP nu de groep **UniFi** met:
  - **UniFi AP**: CRITICAL als het AP *isolated* is (geen bekabelde verbinding meer); toont model, firmware en uplink, en geeft een **ℹ️-melding bij nieuwe firmware**;
  - **clients per band** (2,4 / 5 / 6 GHz) en **per SSID**, elk uitgesplitst (zonder drempels);
  - **kanaalbezetting per band** (⚠️ 60 %, 🔴 80 %), met het eigen verkeer en het kanaal;
  - **SSID uitgezonden**: nergens = CRITICAL, op een band uit = WARNING;
  - **verkeer per SSID** (download en upload) en **kwaliteit per SSID** (herhaald verzonden pakketten met CCQ, ⚠️ 15 %, 🔴 30 %); standaard niet aangevinkt.
- Een bestaande check *UniFi clients* blijft werken (het totaal), en kan nu ook per band (`band:5g`) of per SSID (`ssid:naam`).

## 1.73.1 – 2026-10-24
### Opgelost
- Het **icoon op het beginscherm** van je telefoon (en de iconen in het manifest) gaf "Not Found" bij een installatie met Apache: Apache gebruikt het adres `/icons/` zelf. De iconen staan nu in `assets/icons/`, en het icoon voor iPhone ook in de hoofdmap (`/apple-touch-icon.png`). Voeg RzMon daarna opnieuw toe aan je beginscherm.

## 1.73.0 – 2026-10-24
### Nieuw
- **Eigen logo en icoon**: het woordmerk *RzMon* met een oranje z, en als icoon een oranje schild met de z op een donkere tegel.
  - In de bovenbalk, op het inlogscherm, bij de setup en op de bevestigpagina (in plaats van 📡); in het donkere thema worden de letters wit.
  - Als icoon in het **tabblad** van de browser, en op het **beginscherm** van je telefoon als je RzMon daar vastzet (iPhone en Android).
  - Voor **Pushover**: `brand/pushover-icon-72.png`, in te stellen als icoon van je applicatie op pushover.net.
  - De **HA-add-on** heeft hetzelfde icoon en logo in de add-on winkel, en het logo staat bovenaan de README.

## 1.72.1 – 2026-10-24
### Opgelost
- **Overstap via internet herkennen** (agent 1.51.1 nodig): mislukt een overstap naar een ander adres omdat de agent via je **publieke IP** binnenkomt (de naam wijst op die server niet naar je reverse proxy, bv. omdat de DNS-rewrite in AdGuard daar niet werkt), dan staat dat er nu bij: *⚠️ via internet (…): wijst de naam hier naar je proxy?*
- **✔ Toestaan** waarschuwt als een agent die tot nu toe uit je eigen netwerk kwam, ineens van een publiek adres komt; meestal moet je dan de DNS op die server oplossen in plaats van het adres toe te staan.
- Een **✘ van een mislukte overstap** verdwijnt nu vanzelf (*✔ Gebruikt nu …*) zodra de agent toch via dat adres rapporteert, bv. omdat je het op de server zelf hebt ingesteld.
- Nieuwe agent 1.51.1.

## 1.72.0 – 2026-10-24
### Nieuw
- **🌍 Agents via https** (agent 1.51.0 nodig), in *Instellingen → Agents → Beveiligde verbinding*:
  - **Adres van RzMon wijzigen vanuit RzMon**: **🌐 Alle agents naar een ander adres…**, of per agent (🌐 in de tabel, of *Agent Manager → Adres van RzMon*). De agent krijgt het adres versleuteld, **test het eerst** (verbinding, certificaat en zijn eigen sleutel) en stapt pas dan over; anders blijft het oude adres, met de reden.
  - **Reserve-adres**: is het gewone adres (bv. via de reverse proxy) niet bereikbaar, dan rapporteert de agent via het reserve-adres (bv. het LAN-adres). Eén ⚠️-melding *Agent via reserve-adres*, en ✅ zodra het weer via het gewone adres gaat.
  - Kolom **Verbinding**: https via proxy, https, http of via het reserve-adres, met de adressen en de uitkomst van de laatste overstap. Ook in de Agent Manager.
  - **🔒 Agents alleen via https** (algemeen, en per agent *aan/uit*): een agent via gewone http wordt geweigerd; via het reserve-adres mag het, met een ⚠️. Aanzetten kan pas als geen agent meer via http komt.
  - *Hoe staat het ervoor?* toont *Agents via https*.
- Nieuwe agent 1.51.0 (adres vanuit RzMon, reserve-adres, `--fallback` bij het installeren, *Reserve-adres* in de HA-add-on).

## 1.71.0 – 2026-10-24
### Nieuw
- **⚲ Filters op elke pagina**: Dashboard, Meldingen, IDS (Incidenten, Toegang, Blokkades), Kwetsbaarheden en Datalekken.
  - Een paneel met een keuzelijst **per onderdeel** (server, status, soort, check, kanaal, IP-adres, ernst, pakket, CVE, lek, soort gegevens …), met hoe vaak elke waarde voorkomt en een zoekveld bij lange lijsten. **Meerdere waarden** per onderdeel; onderdelen werken samen.
  - **Periode** bij Meldingen en IDS: vandaag, 24 uur, 7 dagen, 30 dagen of van – tot.
  - Actieve filters als **chips** onder de werkbalk (✕ haalt er één weg, *Alles wissen* allemaal).
  - **Per gebruiker bewaard**, ook na herladen; **💾 Opslaan als…** met een naam, met ⭐ terug te halen.
  - De filters staan in het **adres** (`#alerts?server=NAS,Pi&status=crit`, `#ids/acc?result=fail`, `#vulns/leak?status=open`), om als bladwijzer of link te delen.
  - De losse keuzelijst *Alle servers* bij Meldingen en Toegang is opgegaan in *Filters → Server*.
- **Bij openen alles dicht**: servers, dagen, gebundelde regels en adressen staan dicht; wat je openklapt, blijft open tot je herlaadt. Zoeken of filteren klapt de treffers vanzelf open. In *Mijn account → Weergave* kies je **Alles dicht** (standaard) of **Slim openen** (zoals het was), per gebruiker. Ook bij Kwetsbaarheden en Datalekken staat nu *Alles openen / sluiten* in het menu ⋯.

## 1.70.0 – 2026-10-24
### Nieuw
- **🔐 Certificaat voor de agents** (*Instellingen → Agents → Beveiligde verbinding → 🔐 Certificaat bekijken*):
  - in een venster: het adres van de agents (http, het eigen certificaat van RzMon, of dat van je reverse proxy), het eigen certificaat van RzMon op poort 443 (uitgever, namen, geldigheid, vingerafdruk), de vingerafdruk die de agents vastzetten, en **per agent** of hij het huidige certificaat al kent;
  - **⬇ downloaden** als `.crt` (met je wachtwoord), om in je browser of besturingssysteem te vertrouwen;
  - **🔄 vernieuwen** (met je wachtwoord): een nieuwe sleutel, **2 jaar** geldig, met automatisch de hostnaam, alle IP-adressen, het beheeradres en de Basis-URL, plus **eigen extra namen**. Via de nieuwe hulpdienst `rzmon-cert.path` (als root), met een reservekopie en terugzetten als de webserver het niet accepteert. Agents met een vastgezet certificaat gaan vanzelf mee. In de terminal: `sudo php install/install.php --renew-cert`;
  - **dagelijkse controle** van dit certificaat: ℹ️ 14 dagen voordat het verloopt, ⚠️ als het verlopen is (dit gebeurt nu ook zonder beheeradres).
- `install-helper.sh` installeert de hulpdienst voor *Certificaat vernieuwen*; bij een update met de knop *RzMon bijwerken* gebeurt dat vanzelf.

## 1.69.0 – 2026-10-24
### Nieuw
- **🔓 Datalekken via Have I Been Pwned** (*Instellingen → Beveiliging → Datalekken*), met een hoofdschakelaar. Na de update staat hij **uit**.
  - **Wachtwoorden** (gratis): het wachtwoord gaat nooit de deur uit, alleen de eerste 5 tekens van de hash (met opvulling). Bij het instellen van een gelekt wachtwoord een **waarschuwing**; wie **inlogt met een gelekt wachtwoord, moet eerst een nieuw kiezen** (daar wordt een gelekt wachtwoord geweigerd). Drempel instelbaar (standaard: elke treffer). Is HIBP niet bereikbaar, dan gaat alles gewoon door.
  - **E-mailadressen** (API-sleutel, met 🧪 testknop): de adressen van gebruikers, een eigen lijst en een bij HIBP geverifieerd **domein**, dagelijks en gespreid binnen je abonnement. **⚠️ warning** bij een nieuw lek met wachtwoorden; **ℹ️ info** bij een nieuw lek zonder wachtwoorden en bij de eerste controle van een adres (één samenvatting).
  - Tabblad **Datalekken** bij *Kwetsbaarheden* (beheerders): tellers, tegels, zoeken en filters, per adres de lekken met datum, gegevenssoorten en uitleg; **✔ Afgehandeld** (blijft grijs zichtbaar) en **🔍 Nu controleren**.
  - MQTT: `sensor.rzmon_datalekken` en `binary_sensor.rzmon_gelekt_wachtwoord`. *Hoe staat het ervoor?*: *Gelekte wachtwoorden* en *Gelekte e-mailadressen*.
- **Per account bij Gebruikers:**
  - **🔓/🔕 datalek-controle**: nieuwe accounts aan, bestaande accounts uit;
  - **🏠 alleen lokaal**: alleen inloggen vanaf je interne netwerken, bijvoorbeeld voor een noodaccount zonder 2FA. Van buiten geweigerd (ook onthouden apparaten en open sessies), met een ℹ️-melding in een eigen rij bij *Systeeminfo* (*🏠 Inlogpoging van buiten op een lokaal account*), zodat je daar Pushover voor kunt aanzetten. Er blijft altijd minstens één beheerder die ook van buiten mag inloggen.

## 1.68.0 – 2026-10-23
### Nieuw
- **🔒 Beheer via https** (*Instellingen → Beveiliging → Beheer via https*): de webinterface altijd via https, zodat niemand op het netwerk je wachtwoord, je sessie of de pagina zelf (bv. een installatiecommando) kan zien of veranderen.
  - **Beheeradres** via je reverse proxy (bv. `https://rzmon.jouwdomein.nl` via SWAG met Let's Encrypt), met een stappenplan en een kant-en-klaar voorbeeld voor SWAG en de DNS-rewrite in AdGuard Home.
  - **Certificaatkaart**: uitgever, namen, geldigheid, dagen nog geldig, vingerafdruk en of het vertrouwd is, voor het beheeradres en de nooddeur; plus of de naam intern wijst (DNS-rewrite). **Elke dag** gecontroleerd: **ℹ️ info 14 dagen** voor het verloopt (*Systeeminfo → 🔐 Certificaat verloopt binnenkort*), **⚠️** als het verlopen of ongeldig is, ✅ als het weer goed is.
  - **Beheer alleen via https**: http gaat naar het beheeradres, met **HSTS** (de eerste 7 dagen 1 dag, daarna zonder problemen vanzelf 1 jaar). Aanzetten kan alleen als je al via het beheeradres binnen bent en het certificaat klopt. Agents, hun downloads en de REST API blijven via http werken.
  - **Nooddeur**: `https://<IP van RzMon>/` (self-signed) werkt vanaf je interne netwerken altijd, ook met een verlopen certificaat. Buitengesloten: `php install/install.php --https-off`.
  - **Rode balk** op de inlogpagina via http, en **Inloggen via http weigeren**.
  - **2FA verplicht van buiten je interne netwerken**: zonder 2FA van buiten geweigerd; een onthouden apparaat geldt van buiten alleen als het met 2FA is onthouden; *wachtwoord vergeten* van buiten alleen met 2FA. API-sleutels blijven werken.
  - Uitzetten vraagt je wachtwoord. *Hoe staat het ervoor?* toont *Beheer via https*, *Certificaat*, *Lokale DNS-rewrite*, *Reverse proxy* en *2FA van buiten*.
- **Reverse proxy veiliger**: RzMon herkent https via `X-Forwarded-Proto` (en de naam via `X-Forwarded-Host`), maar alleen van een adres bij *Reverse proxy IP's*; de sessiecookie krijgt dan `Secure`. Komen er proxy-kopteksten van een **onbekend adres**, dan gelden die bezoekers als **extern** (nooit inloggen zonder wachtwoord, 2FA verplicht als dat aan staat) en krijg je één keer per dag een ⚠️-melding.

## 1.67.0 – 2026-10-23
### Nieuw
- **Meldingen, IDS en Kwetsbaarheden in het nieuwe jasje**, net als het dashboard: tellers onder de header (blijven staan bij scrollen, klikbaar als filter), kerncijfers als tegels, een werkbalk met zoeken en filterchips, en compacte rijen.
  - **Meldingen**: tellers van vandaag (Critical · Warning · Onbekend · Hersteld · Info · Beveiliging), tegels (vandaag met een lijntje per uur en de vergelijking met gisteren, nu open, **niet verzonden 24 u** met de reden, drukste server, laatste melding), zoeken en een filter per server. *Niet verzonden* is een **rood ✕ op het kanaal-icoon** met de reden als tooltip, niet meer in de tekst. **Herhalingen gebundeld** (*Ingelogd: admin · 5×*, uit te klappen).
  - **IDS**: tellers (open incidenten, geblokkeerd, logins en mislukte logins 24 u), tegels (modus, servers bewaakt, open incidenten, nu geblokkeerd, mislukte logins met een lijntje), servers als compacte regels, tabbladen in de stijl van de bovenbalk, chips per soort (*Poorten · Bestanden · Processen · Logins*), **gelijke incidenten gebundeld** met *✔ Afhandelen (3)* in één keer. **Toegang** in de stijl van Meldingen.
  - **Kwetsbaarheden**: tellers per ernst en actief misbruikt, **één melding bovenaan** bij dezelfde scanfout op meerdere servers, tegels (servers gescand, kwetsbare pakketten, met oplossing, actief misbruikt, volgende scan), chips met aantallen, en kaarten met een balkje per ernst.
  - Overal ook op de telefoon (tellers als chips, tegels opzij te schuiven) en in licht en donker.

## 1.66.0 – 2026-10-23
### Nieuw
- **Vernieuwd dashboard**, overzichtelijker en moderner:
  - **Tellers direct onder de header** (OK, Warning, Critical, Onbekend, Onderhoud) met grote cijfers in hun kleur; ze blijven staan als je scrolt en filteren nog steeds.
  - **Kerncijfers als tegels**: de toestand met de score als ring, servers bereikbaar, checks OK, open problemen (met het langst openstaande) en de beschikbaarheid over 24 uur met een lijntje. Op de telefoon schuif je ze opzij.
  - **⚠️ Nu aandacht nodig**: alle open problemen in één lijst, critical eerst, met ✔ *Bevestig* en 🔧 per regel; 6 zichtbaar (op de telefoon 3), de rest uit te klappen.
  - **Werkbalk**: zoeken, filterchips met aantallen (*Alle · Problemen · Onderhoud · Agent · Home Assistant · Remote · Lokaal*), sorteren (*Ergste eerst* of *Op naam*), een menu ⋯ met *Onderhoud*, *Alles nu checken* en *Alles openen*, en *＋ Server*. Het schakelaartje *Alleen problemen* is de chip *Problemen* geworden.
  - **Servers met problemen** als kaart, met een icoon voor het soort server en **mini-grafiekjes van CPU, RAM en disk over 24 uur**; **servers waar alles OK is compact** eronder (*✔ Alles in orde*).
  - Blox en L1nes (Small en Large) blijven, te kiezen onder *Mijn account → Weergave*.
### Opgelost
- De teller op het tabblad Meldingen viel over het belletje heen.

## 1.65.2 – 2026-10-23
### Opgelost
- **TrueNAS: agent bijwerken mislukte met "Fout op regel 351"**. Het installatiescript maakte de map van de agent op de pool ook bij een update opnieuw aan (`mkdir`) en zette de rechten op 700. Op sommige datasets lukt dat niet. Een map die al bestaat wordt nu niet meer aangemaakt, en lukken de rechten niet (ACL's), dan is dat alleen een waarschuwing. Kan de map echt niet gemaakt worden, dan staat de reden in de melding.
- Een fout in het installatiescript noemt nu ook het **commando** dat misging (bij bijwerken vanuit RzMon was alleen het regelnummer te zien).

## 1.65.1 – 2026-10-23
### Opgelost
- **E-mail aanzetten gaf "Vul de SMTP-server en een ontvanger in, of zet e-mail uit"**, ook als die al ingevuld waren, en daarna bleef alles in die kaart op *✘ niet opgeslagen* staan. Sinds het meteen opslaan (1.61.0) gaat alleen het gewijzigde veld naar de server, en die controleerde alleen dat ene verzoek. Nu kijkt hij voor de rest naar de opgeslagen waarden. Hetzelfde gold voor de Home Assistant-webhook en MQTT.

## 1.65.0 – 2026-10-23
### Nieuw
- **📌 Certificaat vastzetten** en **🌐 alleen vanaf het bekende IP-adres** zijn nu ook **per agent** in te stellen: *algemene instelling*, *aan* of *uit*. In de Agent Manager en in de tabel bij *Instellingen → Agents → Beveiligde verbinding* (kolom 📌 · 🌐). Uitzetten waar het nu geldt vraagt om je wachtwoord.

## 1.64.0 – 2026-10-23
### Nieuw
- **🔐 Beveiligde verbinding met de agents** (Instellingen → Agents → *Beveiligde verbinding*; **Nieuwe agent 1.50.0**):
  - **Eigen sleutel per agent**: geen token meer over de lijn, elk verzoek versleuteld of ondertekend. Wordt één server gehackt, dan geldt dat alleen voor die server. Agents die al versleutelden, stappen vanzelf over; een verzoek met alleen het token wordt dan geweigerd (⚠️).
  - **Koppelcode** (Agent Manager → 🔗 *Koppelcode maken*): eenmalig en 15 minuten geldig. Het installatiecommando controleert eerst het installatiescript en de agent (SHA-256). Het venster meldt *✔ Gekoppeld*. Ook voor de Home Assistant-add-on (`pair_code`).
  - **Sleutels vernieuwen**: vanzelf elke 30 dagen (of 7, 90, alleen met de hand), zonder onderbreking; 🔄 per server.
  - **📌 Certificaat van RzMon vastzetten** bij een eigen (self-signed) certificaat: de agents accepteren alleen dat certificaat; bij een nieuw certificaat controleren ze het met hun sleutel. Aan en uit te zetten.
  - **🌐 Alleen vanaf het bekende IP-adres**: een agent van een ander adres wordt geweigerd, met een ⚠️-melding en Pushover; met **✔ Toestaan** laat je hem weer toe. Aan en uit te zetten.
  - Uitzetten van vastzetten of het IP-slot vraagt om je wachtwoord.
  - *Hoe staat het ervoor?* toont agents zonder eigen sleutel en agents die op toestemming wachten.
- Agents werken zichzelf **automatisch** alleen nog bij via een versleutelde verbinding; onversleuteld alleen met de knop.
- Een sleutel gaat nooit meer leesbaar over de lijn: agents zonder sleutel koppel je met een koppelcode (de algemene sleutel voor agents 1.49.x geef je zo nodig mee met `--key`).

## 1.63.0 – 2026-10-23
### Nieuw
- **Nieuwe tabbladen**: Dashboard, Meldingen, IDS en Kwetsbaarheden in één afgeronde balk midden in de bovenbalk, met eigen lijn-iconen en een vlak dat naar het actieve tabblad schuift. De bovenbalk blijft staan als je scrolt.
- **Op de telefoon een tabbalk onderaan het scherm**, zoals in de Home Assistant-app: icoon met kort label, niets valt meer weg.
- De tellers zijn bolletjes op het icoon, in de kleur van het ergste probleem, en pulseren kort als het aantal omhooggaat.

## 1.62.0 – 2026-10-23
### Nieuw
- **✅ Hoe staat het ervoor?** (stap 3 van de vernieuwing van de instellingen): het eerste onderdeel van Instellingen, met een score en een lijst aandachtspunten: meldkanaal, automatische back-up, 2FA voor beheerders, inlogbeveiliging, nieuwe RzMon-versie, verouderde of onversleutelde agents, HTTPS, kwetsbaarheden en IDS. Klik op een punt en je staat bij de instelling. Een groep met een aandachtspunt krijgt een oranje of rood bolletje in de zijbalk.
- **🔍 Zoek een instelling**: zoekt in de onderdelen en in alle velden; met Enter spring je naar het veld, dat even oplicht.
- Lange uitleg is ingekort (*ⓘ meer uitleg*), lange toelichtingen bij een veld zijn een **ⓘ** met tooltip.
- Zelden gebruikte opties staan ingeklapt onder **▸ Geavanceerd** (Pushover, Alarmgedrag, E-mail, MQTT, Toegang).

## 1.61.0 – 2026-10-23
### Nieuw
- **Instellingen worden meteen opgeslagen** (stap 2 van de vernieuwing): schakelaars en keuzelijsten direct, tekstvelden zodra je ze verlaat of op Enter drukt. Naast het veld staat kort **✔ opgeslagen**, bij de titel *✔ alles opgeslagen*; klopt een waarde niet, dan **✘ niet opgeslagen** met de reden. Alleen wat je veranderde gaat naar de server. Ook bij *Toegang en inlogbeveiliging*, de stille uren en je e-mailadres op *Mijn account*.
- De knoppen *💾 Instellingen opslaan* en *Toegang opslaan* zijn weg. Gevoelige acties vragen nog steeds om je wachtwoord.

## 1.60.0 – 2026-10-23
### Nieuw
- **Accountmenu rechtsboven** (op de plek van de uitlogknop): *👤 Mijn account*, *⚙️ Instellingen* (beheerders), het thema (auto, licht, donker), de taal (NL of EN) en *Uitloggen*. Via het lokale netwerk zonder account: *🏠 Lokaal netwerk* met *Inloggen met je account*.
- **Mijn account** (alleen als je met een account bent ingelogd): e-mailadres, taal, wachtwoord wijzigen, **tweestapsverificatie van je eigen account**, onthouden apparaten, **je laatste 10 logins** (ook mislukte) en de weergave op dit apparaat (Blox of L1nes, Small of Large). Gebruikers en *alleen lezen* zien alleen deze pagina.
- **Instellingen met een zijbalk** (stap 1 van de vernieuwing): onderdelen in de groepen Meldingen, Monitoring, Beveiliging, Agents en Systeem, één tegelijk in beeld, met een eigen adres (bv. `#settings/backup`). Op de telefoon een keuzelijst. *Setup* staat onder Systeem. Het tabblad *Instellingen* in de bovenbalk is weg; Instellingen zit in het accountmenu.
- De instellingen volgen niet meer Blox/L1nes; die keuze geldt nog voor het dashboard en de meldingen.

## 1.59.0 – 2026-10-23
### Nieuw
- **ℹ️ Systeeminfo per melding** (*Instellingen → Meldingen → ℹ️ Systeeminfo*): voor elke melding van RzMon zelf kies je **Pushover**, **e-mail**, **Home Assistant** en de **meldingenpagina**, en of Pushover **stil** of **met geluid** komt. Dat zijn: RzMon bijgewerkt; agent bijgewerkt, agent bijwerken mislukt, agent verbonden; ingelogd, mislukte login; account geblokkeerd en gedeblokkeerd, IP geblokkeerd en blokkade opgeheven; nieuwe kwetsbaarheden, pakketten bijgewerkt of mislukt; en ⚠️ niet-geautoriseerde verbinding. Met 🧪 stuur je per melding een voorbeeld.
- Tijdens stille uren gaat systeeminfo niet naar buiten, maar wel op de meldingenpagina. ⚠️ *Niet-geautoriseerde verbinding* gaat ook tijdens stille uren, en kan nu ook naar e-mail of Home Assistant.
- Na de update werkt alles zoals voorheen: de keuzes zijn overgenomen uit de rij Info. Die rij heet nu **Info (checks)** en geldt alleen nog voor checks.

## 1.58.2 – 2026-10-23
### Nieuw
- Ook het **opheffen** van een blokkade staat als ℹ️-melding in *Meldingen*: *🔓 Account gedeblokkeerd* en *✅ Blokkade opgeheven*, met wie het deed (ook via de terminal met `--unlock` of `--unblock-all`) of dat de ingestelde tijd voorbij was. Verlopen blokkades worden nu binnen een minuut opgeruimd (en verdwijnen uit de lijst ⛔ *Blokkades*).

## 1.58.1 – 2026-10-23
### Nieuw
- Elke blokkade staat nu ook als **ℹ️-melding** in *Meldingen*: *🔒 Account geblokkeerd* (inlogbeveiliging) en *⛔ IP geblokkeerd* (door de inlogbeveiliging of met de hand), met de reden, door wie en tot wanneer. De ⚠️-melding met Pushover blijft zoals hij was.

## 1.58.0 – 2026-10-23
### Nieuw
- **🔐 Inlogbeveiliging** (*Instellingen → Toegang*, standaard aan): na **3** mislukte pogingen (ook een verkeerde 2FA-code) binnen **10** minuten wordt het **account** geblokkeerd en het **IP-adres**, als dat niet uit je lokale netwerken komt. Aantal, tijd en wat er geblokkeerd wordt stel je zelf in.
  - Pogingen tellen apart per account (vanaf elk IP) en per IP-adres.
  - Een geblokkeerd account kan vanaf je **lokale netwerk** nog inloggen, zodat niemand van buiten je kan buitensluiten. Van buiten zie je de melding *account geblokkeerd* alleen bij het juiste wachtwoord.
  - De blokkade gaat na **60 minuten** vanzelf weg (in te stellen; 0 = tot een beheerder deblokkeert). Deblokkeren: bij *Gebruikers* (🔓 Deblokkeren) en bij ⛔ *Blokkades*, of op de server met `sudo php install/install.php --unlock <gebruiker>`.
  - ⚠️-melding met Pushover bij elke blokkade.
- ⛔ *Blokkades* toont automatische blokkades (*auto*) en tot wanneer ze gelden.

## 1.57.4 – 2026-10-23
### Opgelost
- **Het venster van pakketten bijwerken (Kwetsbaarheden) werd na een paar seconden vervangen door dat van de RzMon-update.** Beide vensters hebben dezelfde opmaak, en de controle van de RzMon-update (die het venster bij een hangende verbinding opnieuw opvraagt) zag het venster van Kwetsbaarheden voor het zijne aan. Nu kijkt die alleen naar het eigen venster.

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
