# Toegang en beheer

Dit hoofdstuk gaat over wie er in RzSense mag, en hoe je RzSense zelf veilig en in de lucht houdt. Je leest hoe inloggen
werkt (ook zonder wachtwoord op je eigen netwerk), hoe je apparaten, gebruikers, rollen en 2FA beheert, hoe RzSense van
buiten onzichtbaar wordt en hoe je het alleen via https beheert. Daarna: de beveiligde verbinding met de agents,
blokkades, back-up en herstel, een reserve-RzSense, de REST API, de statuspagina en een beveiligingschecklist.

## Inloggen zonder wachtwoord op je LAN

Thuis wil je niet steeds een wachtwoord typen. RzSense kan je op je eigen netwerk zonder wachtwoord binnenlaten. Van
buiten blijft de login altijd nodig.

**Zo zet je het aan** (*Instellingen → Toegang tot RzSense → Inloggen*):

1. Kijk bovenaan bij **Jouw IP-adres** of je huidige IP-adres binnen je lokale netwerken valt.
2. Pas de lijst **Lokale netwerken** aan. Standaard staan alle privé-ranges erin. Strakker is alleen je eigen subnet:
   ```
   192.168.1.0/24
   ```
3. Zet **Geen login nodig op het lokale netwerk** aan. Dat wordt meteen opgeslagen.

**Welke rechten heb je dan?**

- Standaard kom je op je LAN binnen als **Alleen lezen**: je ziet alles, maar kunt niets veranderen. Rechtsboven staat
  **Lokaal netwerk**.
- Met **Inloggen** rechtsboven log je in met je eigen account en krijg je de rechten van dat account. *Verder zonder
  inloggen* brengt je terug naar alleen lezen.
- Zet een beheerder **Op het lokale netwerk als beheerder inloggen** aan, dan ben je op je LAN zonder wachtwoord meteen
  beheerder.
- Heb je op dit apparaat *Onthoud mij* aan, dan kom je binnen met je eigen account.

**Goed om te weten**

- Van buiten krijg je gewoon het inlogscherm, met 2FA als die aan staat.
- Ga je met een open sessie van wifi naar 4G, dan word je bij het volgende verzoek uitgelogd.
- De REST API vraagt altijd een API-sleutel, ook op je LAN.
- Test na het aanzetten via 4G op je telefoon dat je een inlogscherm krijgt.

## Onthoud mij en uitloggen

### Onthoud mij op dit apparaat

Zet bij het inloggen **Onthoud mij op dit apparaat** aan. Dan blijf je op dat apparaat ingelogd, ook na het sluiten van
de browser of een herstart, zonder wachtwoord of 2FA-code.

- Gebruik je het apparaat **30 dagen** niet, dan wordt het vergeten. Elk gebruik begint de 30 dagen opnieuw.
- Het apparaat krijgt een geheime sleutel in een beveiligde cookie. Wis je de cookies of gebruik je een privévenster, dan
  log je opnieuw in.
- RzSense bewaart alleen een hash van die sleutel en vervangt hem hooguit één keer per dag. Duikt er daarna nog een oude
  sleutel op (bijvoorbeeld een gekopieerde cookie), dan wordt het apparaat vergeten en krijg je een melding.
- Van buiten je netwerk werkt *Onthoud mij* alleen als het apparaat **met 2FA** is onthouden. Tip: zet het op je
  telefoon aan, met 2FA.
- Je houdt de rechten van je account. Wijzigt een beheerder je rol, dan geldt die meteen.
- Een apparaat wordt vanzelf vergeten als je erop uitlogt, als je je wachtwoord wijzigt (alle andere apparaten) en als
  een beheerder je wachtwoord reset.
- Kom je toch op het inlogscherm, dan staat erboven waarom (bijvoorbeeld: buiten je netwerk zonder 2FA, te lang niet
  gebruikt, of vergeten).

### Uitloggen na een tijd zonder activiteit

*Instellingen → Toegang tot RzSense → Inloggen → Uitloggen*: kies **30 minuten, 2 uur** (standaard), **8 uur** of **1
dag**.

- Zolang RzSense open en zichtbaar is, blijf je ingelogd.
- Ligt je telefoon in je zak of staat het tabblad op de achtergrond, dan telt de tijd.
- Met *Onthoud mij* log je daarna vanzelf weer in.

### Ingelogde apparaten

RzSense houdt elke ingelogde sessie bij. Per sessie zie je het apparaat (bijvoorbeeld *Safari op iPhone*), het IP-adres
(binnen of buiten je netwerk), hoe er is ingelogd (wachtwoord, wachtwoord + 2FA, onthouden apparaat of LAN zonder
wachtwoord), sinds wanneer en wanneer het laatst actief was. Onthouden apparaten zonder open sessie staan er ook bij,
als *onthouden, nu niet actief*.

- **Je eigen sessies:** *Mijn account → Ingelogde apparaten*. Per apparaat **Uitloggen** (of **Vergeten**), of **Alle
  andere apparaten uitloggen**. Je huidige sessie sluit je met *Uitloggen* in het accountmenu.
- **Alle sessies** (beheerders): *Instellingen → Toegang tot RzSense → Gebruikers → Ingelogde apparaten*. Filter per
  gebruiker, log één sessie of alles van een gebruiker uit, of gebruik de noodknop **Iedereen uitloggen behalve mij**
  (met je wachtwoord).

Uitloggen werkt meteen: bij de volgende klik, of uiterlijk binnen 20 seconden. Een onthouden apparaat wordt daarbij ook
vergeten, anders zou het meteen weer inloggen. Een LAN-sessie logt opnieuw in zolang *Geen login nodig op het lokale
netwerk* aan staat. Handig bij een verloren telefoon. Uitloggen van andermans sessies komt in het logboek.

## Gebruikers, rollen en 2FA

Onder *Instellingen → Toegang tot RzSense → Gebruikers* beheer je wie er mag inloggen, en met welke rol.

| Mag… | Beheerder | Gebruiker | Alleen lezen |
|---|:-:|:-:|:-:|
| Dashboard, checks, grafieken, meldingen en containers bekijken | ja | ja | ja |
| Eigen wachtwoord en eigen 2FA | ja | ja | ja |
| Storing bevestigen, nu checken, onderhoud, poortscan | ja | ja | nee |
| Rapport naar je eigen e-mailadres sturen | ja | ja | nee |
| Beheerportal bekijken: Status en functies, Advies, Rapporten, Logboek | ja | alleen bekijken | nee |
| Ontvangers per rapport instellen | ja | nee | nee |
| Servers en checks inrichten, agents, instellingen, gebruikers, API-sleutels | ja | nee | nee |

- Je kiest de rol bij het aanmaken; wijzigen gaat met de keuzelijst bij de gebruiker.
- Je eigen rol kun je niet wijzigen, en er blijft altijd minstens één beheerder.
- Knoppen die niet mogen, verdwijnen. De server controleert de rechten ook zelf, dus ook via de API kan niemand meer
  dan zijn rol toestaat.

### Gebruikers en 2FA beheren

- **Gebruiker toevoegen:** vul een naam en wachtwoord in. Zet *Direct tweestapsverificatie instellen* aan als je meteen
  2FA wilt.
- **2FA instellen:** klik bij de gebruiker op **2FA instellen** en scan de QR-code met een authenticator-app (Google of
  Microsoft Authenticator, Authy, Bitwarden, 1Password …). Lukt scannen niet, typ dan de sleutel eronder over. 2FA staat
  pas aan als je een juiste code invult, dus je sluit jezelf niet per ongeluk buiten.
- **Inloggen met 2FA:** na het wachtwoord vraagt RzSense de 6-cijferige code. Een code werkt één keer. Na 5 foute codes
  of 5 minuten begin je opnieuw met je wachtwoord.
- **2FA resetten** (nieuwe of verloren telefoon): de gebruiker logt dan weer in met alleen het wachtwoord, tot 2FA
  opnieuw is ingesteld.
- **🔑** stelt een nieuw wachtwoord in, **🗑** verwijdert een gebruiker. Jezelf en de laatste gebruiker kun je niet
  verwijderen.

Wachtwoorden worden opgeslagen met Argon2id (of bcrypt als PHP geen Argon2id heeft).

### Bevestigen bij acties

Bij ingrijpende acties (herstellen, installeren, isoleren, blokkeren, bijwerken, terugzetten, verwijderen) vraagt
RzSense om je wachtwoord. Op *Instellingen → Toegang tot RzSense → Gebruikers → Bevestigen bij acties* zet je per rol
aan dat een bevestigingsknop genoeg is: die houd je **even ingedrukt** (muis, vinger, of spatie/Enter). Laat je eerder
los, dan gebeurt er niets.

- Standaard aan voor beheerders, uit voor gebruikers. *Alleen lezen* heeft geen knoppen voor acties.
- De knop werkt alleen als je je wachtwoord de laatste **60 minuten** hebt ingevuld (bij het inloggen, ook met een
  passkey, of bij een eerdere bevestiging). Het aantal minuten stel je zelf in (1–1440).
- **Altijd met je wachtwoord:** passkeys, sleutels, back-ups en certificaten downloaden, het databasewachtwoord en
  -gegevens, en iedereen uitloggen.
- Op je LAN zonder inloggen typ je in plaats van een wachtwoord het woord dat RzSense noemt (bijvoorbeeld `HERSTELLEN`).

## Wachtwoord vergeten

Op het inlogscherm staat **Wachtwoord vergeten?**.

1. Vul je gebruikersnaam of e-mailadres in. Je krijgt altijd dezelfde melding, of het account nu bestaat of niet.
2. Heeft het account een e-mailadres, dan krijg je een mail met een link. Die werkt **één keer** en **30 minuten**.
3. Kies via de link een nieuw wachtwoord. Heeft het account 2FA, dan is ook de code uit de app nodig: alleen toegang tot
   iemands mailbox is dus niet genoeg.
4. Daarna worden alle andere sessies en onthouden apparaten van dat account uitgelogd, en krijg je een bevestigingsmail.

**Wat ervoor nodig is**

- een e-mailadres bij het account (beheerders vullen het in bij *Gebruikers*; iedereen kan zijn eigen adres instellen);
- een SMTP-server (*Instellingen → Meldingen en rapporten → Kanalen*, e-mail; e-mailmeldingen hoeven niet aan te staan);
- de **Basis-URL**, want daarmee wordt de link gemaakt.

Ontbreekt er iets, dan staat dat in rood bij *Gebruikers*, en toont het inlogscherm *Vraag een beheerder*.

Per account gaan hooguit 3 links per uur weg, en per IP-adres hooguit 10 aanvragen per uur. Elke aanvraag staat in het
logboek (*Gebruikers → Logboek bekijken*).

**Buitengesloten?** Op de server (Debian/Ubuntu: `www-data` in plaats van `apache`):

```bash
sudo -u apache php /var/www/rzmon/install/install.php --reset-password admin   # nieuw wachtwoord
sudo -u apache php /var/www/rzmon/install/install.php --reset-2fa admin        # 2FA uitzetten (telefoon kwijt)
```

Codes blijven *onjuist*? Controleer of de klok van je telefoon en van de server goed staat (`timedatectl`).

## Achter een reverse proxy

Gebruik je SWAG, Nginx Proxy Manager, Traefik, Caddy, Cloudflare Tunnel of de Home Assistant-add-on *NGINX SSL proxy*,
dan komt elk verzoek binnen met het IP-adres van de proxy. Vertel RzSense welke proxy hij mag vertrouwen.

**Zo doe je het** (*Instellingen → Toegang tot RzSense → Inloggen → Geavanceerd: reverse proxy*):

1. Vul het IP-adres van de proxy in bij **Reverse proxy IP's**.
2. RzSense haalt dan het echte bezoekers-IP uit `X-Forwarded-For`, en ziet via `X-Forwarded-Proto` dat je via https
   kwam.
3. Test via 4G op je telefoon: je moet een inlogscherm krijgen.

**Goed om te weten**

- Zet het proxy-IP **nooit** bij *Lokale netwerken*. Anders komt iedereen die via de proxy binnenkomt, dus ook van
  internet, zonder wachtwoord binnen.
- Sommige routers geven bij port-forwarding alle bezoekers het IP-adres van de router. Test daarom altijd via 4G.
- Komen er proxy-kopteksten van een adres dat niet op de lijst staat, dan behandelt RzSense die bezoekers als **extern**:
  nooit zonder wachtwoord, 2FA verplicht als dat aan staat, en het proxy-adres wordt niet automatisch geblokkeerd. Je
  krijgt één keer per dag een melding, en *Status* toont het tot je het adres toevoegt.

## Beheer via https

Open je RzSense via `http://`, dan kan iemand op je netwerk je wachtwoord en je sessie zien, of de pagina onderweg
veranderen (bijvoorbeeld een installatiecommando). Met een **beheeradres** via je reverse proxy gaat de webinterface
altijd via https. Agents en de REST API merken er niets van: hun berichten zijn al versleuteld of hebben een API-sleutel.

Je vindt alles op *Instellingen → Toegang tot RzSense → Beheer van buiten*. Bovenaan zie je hoe je nu verbonden bent:
http of https, via je vertrouwde proxy of niet, van binnen of van buiten.

### Opzetten met SWAG en een DNS-rewrite

1. **SWAG:** maak `nginx/proxy-confs/rzmon.subdomain.conf` (het voorbeeld staat op de kaart onder *Zo zet je het op*) en
   herstart SWAG. SWAG vraagt het Let's Encrypt-certificaat zelf aan en vernieuwt het.
2. **Vertrouwde proxy:** zet het LAN-IP van SWAG bij *Reverse proxy IP's* (zie hierboven).
3. **DNS-rewrite** in AdGuard Home (*Filters → DNS-herschrijvingen*): `rzmon.jouwdomein.nl` → het LAN-IP van SWAG. Dan
   gebruik je thuis en onderweg hetzelfde adres met hetzelfde geldige certificaat. Gebruikt de RzSense-server zelf ook
   AdGuard als DNS, dan kan RzSense het certificaat controleren (anders: een regel in `/etc/hosts` van de RzSense-server).
4. Open RzSense via `https://rzmon.jouwdomein.nl`, vul dat in als **Beheeradres** en klik op **Nu controleren**.

Draait je proxy op een andere machine en stuurt hij het verkeer via http door, dan gaat het laatste stuk onversleuteld
over je netwerk. *Status* waarschuwt daarvoor en toont de regels voor SWAG om te kopiëren:

```nginx
set $upstream_app 192.168.1.10;
set $upstream_port 443;
set $upstream_proto https;
proxy_pass $upstream_proto://$upstream_app:$upstream_port;
```

### Het certificaat

De kaart toont van het beheeradres en van de nooddeur de uitgever, namen, geldigheid, vingerafdruk en of het vertrouwd
is. Je ziet ook of de naam vanaf de RzSense-server intern wijst (de DNS-rewrite werkt) of naar je publieke IP.

RzSense controleert het certificaat elke dag:

- een **info**-melding **14 dagen** voordat het verloopt. SWAG vernieuwt normaal rond 30 dagen van tevoren, dus deze
  melding betekent: vernieuwen lukt niet. Kijk in de log van SWAG;
- een **warning** als het verlopen of ongeldig is (verkeerde naam, niet vertrouwd, geen verbinding), en een melding als
  het weer in orde is.

### Afdwingen

| Schakelaar | Wat het doet |
|---|---|
| **Beheer alleen via https** | Elke pagina via http gaat naar het beheeradres (ook `http://LAN-IP` en links uit meldingen), met HSTS: de browser probeert http daarna niet eens meer. De eerste 7 dagen geldt HSTS 1 dag, daarna vanzelf 1 jaar (zonder `includeSubDomains`). Aanzetten kan alleen terwijl je via het beheeradres binnen bent en het certificaat in orde is. Uitzetten (met je wachtwoord) laat de browser HSTS weer vergeten. |
| **Inloggen via http weigeren** | Via http weigert RzSense het wachtwoord, de 2FA-code en een nieuw wachtwoord. De inlogpagina toont dan een rode balk met de link naar https. Aanzetten kan alleen als je zelf via https binnen bent. |
| **2FA verplicht van buiten** | Van buiten je lokale netwerken is altijd een 2FA-code nodig. Een account zonder 2FA wordt van buiten geweigerd. Een onthouden apparaat werkt van buiten alleen als het met 2FA is onthouden. *Wachtwoord vergeten* kan van buiten alleen met 2FA, en je eigen 2FA uitzetten kan van buiten niet. Op de kaart staat wie nog geen 2FA heeft. |

Agents (`api.php`) en de REST API (`v1.php`) blijven via http werken.

### De nooddeur

`https://<IP-adres van RzSense>/` gebruikt het eigen certificaat van RzSense (het installatiescript zet dat op poort
443). Kies in je browser *toch doorgaan*. De nooddeur werkt alleen vanaf je lokale netwerken; van buiten word je naar het
beheeradres gestuurd. HSTS geldt niet voor een IP-adres, dus ook met een verlopen certificaat op het beheeradres kom je
zo altijd binnen.

**Buitengesloten?** Op de server:

```bash
sudo -u apache php /var/www/rzmon/install/install.php --https-off   # Debian/Ubuntu: www-data
```

Dat zet *Beheer alleen via https* en *Inloggen via http weigeren* uit.

## Onzichtbaar van buiten

Van buiten je eigen netwerk (alles wat niet bij *Lokale netwerken* staat) hoeft een vreemde niet te zien dat hier
RzSense draait. Thuis verandert er niets. Je vindt het op *Instellingen → Toegang tot RzSense → Beheer van buiten →
Onzichtbaar van buiten*.

| Schakelaar | Standaard | Wat het doet |
|---|---|---|
| **Niet herkenbaar** van buiten | aan | Geen naam of versie (ook niet op het inlogscherm). Het agent-script en installatiescript krijg je van buiten alleen met de downloadsleutel (die staat vanzelf in de installatiecommando's) of als bekende agent. Wie geweigerd wordt, krijgt een gewone **404**: een agent met een fout token, de REST API zonder geldige sleutel, een onbekende heartbeat- of ack-link, een geblokkeerd IP. De reden zie je in *Meldingen*. |
| Webinterface van buiten alleen op **toegestane apparaten** | uit | Zonder toegestaan apparaat krijg je van buiten ook geen inlogscherm, alleen een 404. Agents, heartbeats, ack-links en de REST API blijven werken. **Zet eerst je eigen apparaten op de lijst.** |
| Alleen vanuit je **normale landen** | aan | Ook een toegestaan apparaat komt alleen binnen vanuit de landen die je bij het IDS als normaal instelde (*Instellingen → Bescherming → Netwerk en weblogs*). Zonder landgegevens geldt dit niet. |
| **Noodlink** | aan | Een link naar je e-mail als je apparaatsleutel kwijt is (zie hieronder). |
| **Passkeys** | aan | Inloggen met Face ID, vingerafdruk of beveiligingssleutel. |
| **Geweigerde apparaten** bijhouden | aan | Apparaten die van buiten geweigerd worden, kun je op afstand toestaan (zie hieronder). |

Uitzetten van *Niet herkenbaar* en *toegestane apparaten* vraagt een bevestiging.

### Een apparaat toestaan

1. Ga thuis, op het apparaat zelf, naar *Mijn account → Van buiten en passkeys*.
2. Klik **Dit apparaat ook van buiten toestaan** en bevestig met je wachtwoord.

Het apparaat krijgt een eigen sleutel als cookie (1 jaar) die **alleen voor jouw account** geldt: met de telefoon van een
collega kun je van buiten niet als jezelf inloggen. Wis je de browsergegevens, dan doe je het opnieuw. Beheerders zien op
de kaart alle toegestane apparaten en passkeys van iedereen (laatst gezien, IP, land, en hoe er laatst is ingelogd:
passkey, 2FA of alleen wachtwoord) en kunnen ze hernoemen en intrekken. Zie je bij een apparaat van buiten *alleen
wachtwoord*, zet dan 2FA aan of maak een passkey.

### Wachtende apparaten

Opent een browser uit je normale landen RzSense van buiten zonder toestemming, dan krijgt hij de gewone 404, maar komt
hij ook op de lijst **Wachtende apparaten** (met soort apparaat, IP-adres, land, hoe vaak en wanneer). Je krijgt een
info-melding *Geweigerd apparaat van buiten*.

Per apparaat kies je:

- **Toestaan:** kies de gebruiker en een naam, bekijk de gegevens van het IP-adres en bevestig met je wachtwoord. Bij het
  volgende bezoek krijgt dat apparaat het inlogscherm, alleen voor die gebruiker en met 2FA als die aan staat.
- **Negeren:** niet meer tonen, geen meldingen meer.
- **IP blokkeren.**

Scanners, bots en `curl` komen niet op de lijst. Per IP komen er hooguit 3 nieuwe per uur bij, hooguit 100 wachten er
tegelijk, en een wachtend apparaat vervalt na 7 dagen.

### Passkeys

Maak een passkey bij *Mijn account → Passkey aanmaken* (met je wachtwoord). Daarna log je in met **Inloggen met een
passkey**: Face ID, vingerafdruk of pincode, zonder wachtwoord en 2FA-code. Wachtwoord met 2FA blijft de reserve.

- De geheime sleutel blijft op je apparaat; RzSense bewaart alleen de openbare sleutel.
- Een passkey werkt alleen op het echte domein. Op een nep-site biedt je telefoon hem niet eens aan.
- Werkt alleen via **https met een domeinnaam**, niet via een IP-adres.
- Een passkey die je maakt op een toegestaan apparaat hoort bij dat apparaat: trek je het apparaat in, dan verdwijnt de
  passkey ook.

### Noodlink

Sleutel kwijt of een nieuwe telefoon, terwijl je onderweg bent:

1. Open `https://jouw-adres/?nood=jouwgebruikersnaam` (het adres staat ook bij *Mijn account*; bewaar het).
2. Je ziet een 404, maar er gaat een link naar je e-mailadres (en naar Pushover als je beheerder bent).
3. De link werkt **één keer** en **15 minuten**. Log in (met 2FA) en sta het apparaat opnieuw toe.

Hooguit 3 aanvragen per uur. Elke aanvraag en elk gebruik geeft een melding, dus je ziet het als iemand anders het
probeert.

### Goed om te weten

De vaste bestanden van de webinterface (`/assets/…`) levert de webserver zelf. Wil je ook die van buiten afschermen,
zet dan in SWAG een `location /assets/` die alleen werkt met de cookie `rzmon_device`.

## Inlogbeveiliging en blokkades

### Blokkeren na mislukte logins

*Instellingen → Toegang tot RzSense → Inloggen → Inlogbeveiliging* (standaard aan):

- Na **3** mislukte pogingen binnen **10** minuten wordt het **account** en/of het **IP-adres** geblokkeerd, elk met
  een eigen schakelaar. Een verkeerde 2FA-code telt ook.
- Pogingen tellen apart: 3 fouten op één account (vanaf welk IP dan ook) blokkeren dat account; 3 fouten vanaf één IP
  (op welke accounts dan ook) blokkeren dat IP. Een onbekende gebruikersnaam blokkeert alleen het IP.
- Een IP-adres uit je lokale netwerken wordt nooit geblokkeerd.
- Een geblokkeerd account kan vanaf je **lokale netwerk** gewoon inloggen. Zo kan niemand van buiten je buitensluiten
  door je gebruikersnaam fout te raden. Van buiten ziet alleen wie het juiste wachtwoord typt dat het account geblokkeerd
  is; bij een fout wachtwoord blijft het *Onjuiste gebruikersnaam of wachtwoord*.
- **Blokkade opheffen na 60 minuten**; **0** = tot een beheerder deblokkeert.
- Bij een blokkade krijg je een warning via Pushover, en zowel blokkeren als opheffen komen in *Meldingen*.

### De blokkadelijst

Op *IDS → Blokkades* staat alles wat geblokkeerd is in één lijst: IP-adressen in RzSense, vergrendelde accounts en de
IDS-blokkades per server. Per regel zie je wat, waar, het land, de reden, sinds en tot wanneer en door wie. Het tabblad
*Eerder* toont wat opgeheven of verlopen is.

- Een IP-adres of netwerk dat je hier blokkeert (ook IPv6, bijvoorbeeld `203.0.113.0/24`) komt nergens meer in RzSense:
  niet de webpagina, niet het inloggen, niet de REST API en geen agents. Je kunt ook blokkeren met ⛔ bij een melding.
- Jezelf (je huidige IP) en de RzSense-server kun je niet blokkeren.
- Dit is iets anders dan een IDS-blokkade: die gebeurt op de aangevallen server zelf. Zie [Beveiliging](08-beveiliging.md).
- **Deblokkeren** werkt meteen. Een vergrendeld account deblokkeer je ook bij *Gebruikers*.

### De whitelist

Onder de blokkades staat de **whitelist**: wat nooit geblokkeerd mag worden. Toevoegen heft een blokkade meteen op. Je
bevestigt met je wachtwoord en kiest hoe lang het geldt: **1 dag, 7 dagen, 30 dagen of altijd**.

- **IP-adres of netwerk** (hooguit een /16, bij IPv6 een /32): wordt nergens geblokkeerd, niet in RzSense en niet door
  het IDS op welke server dan ook. Meldingen krijg je nog wel.
- **Account:** wordt nooit vergrendeld na mislukte logins. Het IP-adres van wie het probeert wordt wél geblokkeerd.
- **Geen honeypot-naam:** de naam telt niet meer als honeypot-naam, bijvoorbeeld als *admin* bij jou een echte gebruiker
  is.

Voorbeeld: een collega in een hotel tikt drie keer zijn wachtwoord fout. Zijn account en het hotel-IP zijn geblokkeerd.
Je klikt bij het account op **Deblokkeren** en bij het IP op **Whitelist → 1 dag**.

**Buitengesloten?** Op de server:

```bash
sudo php /var/www/rzmon/install/install.php --unlock <gebruiker>   # account deblokkeren
sudo php /var/www/rzmon/install/install.php --unblock-all          # alle IP-blokkades opheffen
```

### Beveiligingsmeldingen

- **Niet-geautoriseerde verbinding** (warning, altijd naar Pushover, ook tijdens stille uren): een agent met een fout
  token, sleutel of handtekening, een oud of herhaald bericht, een verkeerde, verlopen of niet toegestane API-sleutel, en
  te veel mislukte logins. Per IP en soort één melding per uur, daarna loopt een teller op. Met **⛔ blokkeren** zet je
  het IP meteen op de blokkadelijst.
- **Info:** elke geslaagde login (wie, IP, apparaat, hoe), mislukte pogingen, en agents die (opnieuw) verbinden.

Waar elke melding heen gaat, kies je bij *Instellingen → Meldingen en rapporten → Systeemmeldingen*. Zie
[Meldingen](03-meldingen.md).

## Beveiligde verbinding met de agents

Elke agent heeft een **eigen sleutel**. Daarmee versleutelt en ondertekent hij alles (AES-256 met HMAC-SHA256), en RzSense
antwoordt ook versleuteld. Er gaat geen token en nooit een leesbare sleutel over de lijn. Wordt één server gehackt, dan
geldt dat alleen voor die server: maak een nieuwe koppelcode en de oude sleutel vervalt meteen.

De instellingen staan op *Instellingen → Agents en koppelingen → Standaard voor alle agents*, kaart **Beveiligde
verbinding met de agents** (alleen beheerders). Per server stel je het anders in bij de Agent Manager (pagina
*Servers*).

| Instelling | Uitleg |
|---|---|
| **Sleutels van de agents vernieuwen** | Standaard elke 30 dagen (of 7, 90, alleen met de hand). Gaat vanzelf en zonder onderbreking; de vorige sleutel blijft nog 24 uur geldig. |
| **Certificaat van RzSense vastzetten** (standaard aan) | Draait RzSense met een eigen (self-signed) certificaat, dan accepteert de agent alleen dát certificaat. Bij een nieuw certificaat controleert de agent het antwoord met zijn sleutel en neemt hij dan pas de nieuwe vingerafdruk over. Niet nodig met een geldig certificaat of via http. Agents hebben curl 7.44 of nieuwer nodig. |
| **Agents alleen vanaf hun bekende IP-adres** (standaard aan) | RzSense onthoudt het eerste IP-adres van elke agent. Komt hij van een ander adres, dan wordt hij geweigerd met een melding, en verschijnt er een knop **Toestaan** (tot 5 adressen per server, bijvoorbeeld IPv4 en IPv6). Met **↺** vergeet je de adressen. |
| **Agents alleen via https** | Een agent via gewone http wordt geweigerd; via het reserve-adres mag http wel, met een waarschuwing. Aanzetten kan pas als geen agent meer via http komt. |
| **Alleen agents met een eigen sleutel** | Een agent met alleen een token wordt geweigerd. Koppelen met een koppelcode kan altijd. |

Uitzetten van vastzetten of het IP-slot vraagt om je wachtwoord.

- **Koppelen:** in de Agent Manager → **Koppelcode maken**. De code werkt één keer en 15 minuten. Zie
  [Linux-agent](05-linux-agent.md).
- **Alle agents opruimen…** wist oude tokens en de algemene sleutel, en laat de agents opnieuw installeren met een
  reservekopie.
- De **Algemene sleutel** (onder *Geavanceerd*) is alleen nog voor heel oude agents (1.49.x). Heb je die niet meer, ruim
  hem dan op.

### Agents via https en een reserve-adres

Agents kunnen RzSense via https bereiken: via je reverse proxy met een geldig certificaat, of rechtstreeks via
`https://<IP van RzSense>` met het vastgezette eigen certificaat (zonder proxy en zonder iets in de trust store van de
server).

1. Klik op **Alle agents naar een ander adres…** (of per agent bij *Adres van RzSense → Wijzigen* in de Agent Manager).
2. Kies het **Adres** en eventueel een **Reserve-adres** uit de lijst (IP-adressen, DNS-namen, je beheeradres), of vul
   zelf iets in en klik **Testen**. Onder elk adres zie je wat er staat: het eigen certificaat van RzSense, een geldig
   certificaat, http, een onbekend certificaat (dan weigert RzSense) of *niet bereikbaar*.
3. De agent krijgt het adres versleuteld en **test het eerst**. Lukt het niet, dan blijft het oude adres en zie je de
   reden. In de kolom **Verbinding** staat daarna *Overgestapt naar …*.

**Advies:** rechtstreeks `https://<IP van RzSense>` als gewoon adres, en je beheeradres (via de DNS-rewrite naar je
proxy) als reserve. Dan hangen de agents niet af van je proxy en AdGuard. Agents buiten je netwerk (zoals een VPS) laat
je op het externe adres. Komen alle agents via https binnen, dan stelt *Status* voor om **Agents alleen via https** aan
te zetten.

Is het gewone adres niet bereikbaar (geen verbinding, time-out, certificaatfout, 502/503/504), dan stuurt de agent zijn
rapport via het reserve-adres. RzSense meldt dat pas na 5 minuten, in één melding voor alle agents samen. Een korte
onderbreking geeft dus geen meldingen.

Mislukt de overstap met *nieuw IP-adres* (je publieke IP)? Dan wijst de naam op die server niet naar je proxy en gaat
het verkeer via je router naar buiten en weer binnen. Los het op in de DNS van die server, niet met *Toestaan*:

- **AdGuard Home:** staat de server bij *Clientinstellingen* zonder globale instelling, zet dan *Domeinen blokkeren
  d.m.v. filters en host-bestanden* aan, en wis de DNS-cache.
- **Linux:** `getent hosts <naam>` moet het LAN-IP van de proxy geven; kijk anders in `/etc/resolv.conf` en `/etc/hosts`.
- **Home Assistant:** `nslookup <naam>` in de Terminal-add-on; eventueel `ha dns options --fallback=false` en
  `ha dns restart`.

Klik daarna op **↺** bij de server en doe de overstap opnieuw.

### Het certificaat voor de agents

Met **Certificaat bekijken** op dezelfde kaart zie je welk certificaat de agents gebruiken, de vingerafdruk die ze
vastzetten, en per agent of hij het huidige certificaat al kent.

- **Downloaden (.crt)** (met je wachtwoord): zet het in je browser of systeem als vertrouwd. Dan geeft ook de nooddeur
  geen waarschuwing meer.
- **Vernieuwen** (met je wachtwoord): een nieuw certificaat met een nieuwe sleutel, 2 jaar geldig, met de hostnaam, alle
  IP-adressen, het beheeradres en de Basis-URL (extra namen vul je zelf aan). Het oude blijft als reservekopie staan; gaat
  er iets mis, dan wordt het teruggezet.
- Zijn er agents die het certificaat vastzetten, dan gaat vernieuwen **in twee stappen**: RzSense zet het nieuwe eerst
  klaar en geeft de agents de nieuwe vingerafdruk. Zodra ze die allemaal hebben (uiterlijk na 7 dagen) gaat RzSense het
  nieuwe gebruiken. Met **Nu wisselen** of *Meteen wisselen* sla je het wachten over. Geen enkele agent weigert zo het
  certificaat, en je hoeft niets opnieuw te koppelen.

RzSense controleert dit certificaat ook elke dag (info 14 dagen voor het verloopt, warning als het verlopen is).

Het vernieuwen doet de hulpdienst als root. Zonder hulpdienst, in de terminal:

```bash
sudo php /var/www/rzmon/install/install.php --renew-cert             # klaarzetten (met vastgezette agents)
sudo php /var/www/rzmon/install/install.php --renew-cert --activate  # het klaargezette gaan gebruiken
sudo php /var/www/rzmon/install/install.php --renew-cert --now       # meteen een nieuw certificaat
```

Dit werkt met de virtuele host van het installatiescript (`/etc/httpd/conf.d/rzmon.conf`). Bij een andere opstelling
zet je de paden in `config.php`: `'ssl_cert' => '/pad/naar/cert.crt', 'ssl_key' => '/pad/naar/cert.key'`.

## Back-up en herstel

Belangrijk zijn alleen de **database** en **`config.php`**. RzSense maakt daar zelf back-ups van op *Instellingen →
Systeem → Back-up* (alleen beheerders).

### Instellen

| Instelling | Uitleg |
|---|---|
| **Automatisch** | Uit (standaard), elke dag, elke week (op een dag die je kiest) of elke maand (de 1e). |
| **Om** | Standaard 03:15. Stond de server toen uit, dan volgt de back-up zodra cron weer draait. |
| **Bewaren** | Zoveel automatische back-ups blijven staan, standaard 7. |
| **Handmatige bewaren** | Zoveel back-ups die je met *Nu back-up maken* maakte blijven staan; **0 = alles bewaren** (standaard). |
| **Meethistorie meenemen** | Met de meetresultaten achter de grafieken (standaard aan). Uit geeft een veel kleinere zip. |
| **Zip-wachtwoord** | Versleutelt `database.sql` en `config.php` met AES-256 (minstens 8 tekens). Zonder wachtwoord krijg je een waarschuwing. |

> Bewaar het zip-wachtwoord goed, bijvoorbeeld in je wachtwoordmanager. Zonder is de back-up onbruikbaar. Een
> versleutelde zip open je met 7-Zip; de ingebouwde zip van Windows en macOS kan geen AES.

Mislukt een automatische back-up (schijf vol, map niet schrijfbaar …), dan krijg je één melding. RzSense probeert het
elk uur opnieuw en meldt het als het weer lukt.

### Wat zit er in de zip?

- `database.sql`: alle tabellen (servers, checks, instellingen, gebruikers, API-sleutels, meldingen en eventueel de
  meethistorie);
- `config.php`: met het databasewachtwoord;
- `info.txt`: versie, datum, server en het aantal rijen per tabel (niet versleuteld, geen geheimen).

RzSense heeft daarvoor alleen de PHP-extensie **zip** nodig (`php-zip`), geen `mysqldump`.

### De back-upmap

Standaard staan de back-ups in `/var/lib/rzmon/backups`, buiten de webmap. Bovenaan de kaart zie je de map, wat voor
schijf het is (netwerk, eigen schijf of systeemschijf) en hoeveel er vrij is. Met **Wijzigen** kies je een andere map:

1. Vul de map in en klik **Testen**: je ziet de schijf, de vrije ruimte en of RzSense er kan schrijven. Systeemmappen en
   mappen direct onder `/` worden geweigerd.
2. **Opslaan** gaat via de hulpdienst: die maakt de map, zet de rechten en SELinux goed en past `config.php` aan. Met
   **Bestaande back-ups meeverhuizen** gaan je huidige back-ups mee.

Een **NFS- of SMB-share** koppel je eerst zelf op de server, bijvoorbeeld in `/etc/fstab`:

```
nas.thuis:/mnt/tank/backup  /mnt/nas  nfs4  defaults,_netdev,nofail  0 0
```

Kies daarna een map in de share, zoals `/mnt/nas/rzsense`. RzSense controleert vóór elke back-up of de share gekoppeld
is. Is de NAS weg, dan maakt hij geen back-up (en schrijft hij dus niet ongemerkt naar de systeemschijf) en krijg je een
melding. Met **root_squash** (gebruikelijk op TrueNAS en Synology) maak je de map op de NAS zelf aan en geef je de
webservergebruiker (`apache`) schrijfrechten.

Zonder hulpdienst kan het ook met de hand in `config.php`: `'backup_dir' => '/mnt/nas/rzsense'` (en bij een
netwerkschijf `'backup_mount' => '/mnt/nas'`).

### Opruimen en ruimte

- RzSense ruimt op vóór elke back-up en elke dag.
- Van de veiligheidsback-ups (vóór herstel) blijven de laatste 3, van de back-ups vóór een update de laatste 5.
  Geüploade en handmatige back-ups worden nooit vanzelf weggehaald (behalve via *Handmatige bewaren*).
- Is er te weinig ruimte, dan haalt RzSense eerst de oudste automatische, update- en herstelback-ups weg. Is er dan nog
  te weinig, dan stopt de back-up met een duidelijke melding.

> Bewaar af en toe een kopie buiten de server: download een zip en zet hem op je NAS of in de cloud. Een back-up op
> dezelfde schijf helpt niet als die schijf stukgaat.

### Knoppen

- **Nu back-up maken:** meteen een back-up, met de opgeslagen instellingen.
- **Downloaden:** de zip bevat `config.php`, dus je bevestigt eerst met je wachtwoord (op je LAN zonder inloggen typ je
  `DOWNLOADEN`). Daarna kun je 10 minuten downloaden zonder opnieuw te bevestigen.
- **Zip uploaden:** een zip van je computer terugzetten, ook van een andere server. Tot 2 GB; de upload-limiet van PHP
  maakt niet uit.
- **↺ Terugzetten** en **🗑 Verwijderen**.

### Terugzetten

1. Klik op **↺** bij de back-up en bevestig met je wachtwoord (op je LAN zonder inloggen: `HERSTELLEN`). Heeft de zip een
   ander wachtwoord dan het ingestelde, vul dat dan in.
2. RzSense maakt eerst een **veiligheidsback-up** van de huidige toestand (*voor herstel* in de lijst).
3. Daarna wordt de database teruggezet. Mislukt dat halverwege, dan zet RzSense de veiligheidsback-up terug: alles of
   niets.

**Goed om te weten**

- Het back-upschema en het zip-wachtwoord blijven zoals ze nu zijn.
- Gebruikers en wachtwoorden worden die uit de back-up; misschien moet je opnieuw inloggen.
- Een back-up van een oudere versie kan gewoon; de tabellen worden daarna bijgewerkt.
- Een gewijzigde of afgebroken `database.sql` wordt geweigerd.
- `config.php` wordt via de webpagina niet teruggezet; op dezelfde server is dat ook niet nodig.

### In de terminal en naar een nieuwe server

```bash
sudo php /var/www/rzmon/install/install.php --backup                 # back-up maken (--zonder-historie kan ook)
sudo php /var/www/rzmon/install/install.php --list-backups           # lijst
sudo php /var/www/rzmon/install/install.php --restore /pad/naar/rzmon-backup-….zip
```

**Naar een nieuwe server** (bijvoorbeeld na een crash):

1. Installeer RzSense (zie [Installatie](01-installatie.md)), of zet alleen de bestanden en MariaDB klaar.
2. Zet de zip op de server en voer uit:
   ```bash
   sudo php /var/www/rzmon/install/install.php --restore /root/rzmon-backup-….zip --with-config
   ```
   Je wordt om het zip-wachtwoord gevraagd (of geef `--password=…` mee).
3. RzSense zet `config.php` terug, maakt de database en de databasegebruiker aan met het wachtwoord uit de back-up, en
   zet de database terug. Staat er nog geen `config.php` op de server, dan gebeurt dit ook zonder `--with-config`.

Uit veiligheid wordt een `config.php` met programmacode erin niet teruggezet, alleen gewone instellingen.

## Reserve-RzSense

Een reserve is een tweede RzSense, het liefst op een andere machine (bijvoorbeeld als container), die een kopie bijhoudt
van je hoofdserver. Valt de hoofdserver weg, dan neemt de reserve het werk vanzelf over, en geeft het daarna weer terug.

### Koppelen

1. Installeer RzSense een tweede keer, bijvoorbeeld als container (zie [Installatie](01-installatie.md)), en log in.
2. Op de **hoofdserver**: *Instellingen → Systeem → Reserve-RzSense* → **Koppelcode maken** (met je wachtwoord). De code
   werkt één keer en 1 uur.
3. Op de **reserve**, op dezelfde pagina: vul het adres van de hoofdserver in (bijvoorbeeld je beheeradres) en de code,
   en klik **Koppelen als reserve**.
4. Binnen een paar minuten komt de eerste volledige kopie binnen. Daarna log je op de reserve in met je account van de
   hoofdserver.

### Hoe het bijhouden werkt

- De reserve vraagt elke minuut aan de hoofdserver hoe het gaat. De hoofdserver hoeft de reserve dus niet te kunnen
  bereiken.
- **Elke 15 minuten** komt er een kleine kopie: servers, checks, agentsleutels, instellingen (ook de meldkanalen) en
  gebruikers. **Elke nacht om 3 uur** een volledige kopie, met de geschiedenis.
- De reserve haalt de kopieën rustig op en controleert elke kopie voordat hij hem terugzet.
- Van de reserve zelf blijven: sessies, onthouden apparaten en passkeys, en de instellingen voor zijn eigen adres,
  proxy, https, toegang van buiten, back-ups en updates.
- Een reserve in stand-by doet verder niets: geen checks, meldingen of rapporten. Bovenaan staat *Dit is de reserve van
  …*. Wat je op de reserve verandert, wordt bij de volgende kopie overschreven.
- Heeft de reserve een oudere versie dan de hoofdserver, dan zet hij niets terug tot hij is bijgewerkt.

### Overnemen en teruggeven

- **Adres voor de agents:** de hoofdserver geeft zijn agents het adres van de reserve versleuteld mee. Standaard is dat
  het adres dat de reserve zelf meldt; bij *Adres voor de agents* kies je een ander (met een geldig certificaat). Op de
  pagina zie je hoeveel agents de reserve al kennen. Hiervoor is agent 1.111.0 of nieuwer nodig.
- Een agent probeert de reserve alleen als hij de hoofdserver én zijn gewone reserve-adres niet bereikt. Elk rapport
  probeert eerst weer de hoofdserver.
- De reserve **neemt over** als hij de hoofdserver zelf al minstens 5 minuten niet bereikt én er een echt rapport van een
  bekende agent binnenkomt. Zo werken er nooit twee tegelijk. Je krijgt de melding *Reserve-RzSense heeft het werk
  overgenomen* en bovenaan staat een oranje balk.
- Een actieve reserve verandert niets aan de agents: geen nieuwe sleutels, adressen of updates.
- Bereikt de reserve de hoofdserver **twee minuten achter elkaar**, dan gaat hij terug naar stand-by (*Hoofdserver is
  terug*). De agents gaan vanzelf terug.
- De **geschiedenis van die periode** (metingen, statuswisselingen, meldingen, EDR- en SSH-gebeurtenissen) gaat daarna
  vanzelf terug naar de hoofdserver, in delen van één per minuut. Dubbele rijen ontstaan niet. Bij een lange periode kan
  dat een uur of twee duren; op beide pagina's zie je de voortgang. Aan het eind krijg je *Geschiedenis van de reserve is
  terug*.

### Veiligheid

- Na het koppelen delen de twee een sleutel die alleen zij kennen. Elk verzoek is ondertekend en elk antwoord
  versleuteld, bovenop https. Een verzoek telt één keer en is 5 minuten geldig.
- Een verkeerde code of sleutel krijgt een gewone 404.
- De sleutel staat niet in de export van instellingen en niet in het logboek.
- Gebruik je de bescherming van je reverse proxy met *alleen vanaf mijn netwerk*, dan komt de reserve er na het eerste
  contact vanzelf bij. Komt hij van buiten je netwerk, koppel hem dan eerst vanuit je netwerk.
- **Ontkoppelen** kan aan beide kanten. Op de hoofdserver gaan dan ook de kopieën weg.

## De REST API

Met de REST API lezen andere systemen RzSense uit en bedienen ze het: Home Assistant, Grafana, fail2ban of je eigen
scripts. Alles gaat met een API-sleutel, ook op je LAN.

### Een sleutel maken

1. Ga naar *Instellingen → Systeem → REST API*.
2. Vul een naam in (bijvoorbeeld *Home Assistant*) en kies de rechten:

   | Recht | Mag |
   |---|---|
   | **Status lezen** | Servers, checks, meetwaarden en meldingen uitlezen. Standaard aangevinkt. |
   | **IDS lezen** | Incidenten, blokkades en logins (ssh, sudo, su) van het IDS uitlezen. |
   | **Kwetsbaarheden lezen** | De kwetsbaarheden (CVE's) per server uitlezen. |
   | **IDS blokkeren** | IP-adressen blokkeren en deblokkeren op je servers. Vraagt bij het maken om je wachtwoord. |
   | **Checks bedienen** | Storingen bevestigen, onderhoud aan- en uitzetten, een check nu uitvoeren. |

3. Kies eventueel **Alleen vanaf**: IP-adressen of netwerken, gescheiden door spaties (bijvoorbeeld
   `192.168.1.20 10.0.0.0/24`), of je lokale netwerken. Vanaf een ander adres weigert de API de sleutel. Aanbevolen voor
   een sleutel die mag blokkeren.
4. Kies hoe lang hij geldig is: **6, 12** (standaard) of **24 maanden**.
5. Klik **Sleutel maken**. De sleutel (`rzm_…`) wordt **één keer** getoond; kopieer hem meteen. Er staan testcommando's
   bij voor de gekozen rechten.

Per sleutel zie je de rechten, tot wanneer hij geldig is en wanneer hij laatst gebruikt is. Binnen 30 dagen voor het
einde wordt hij oranje. Met **✎** pas je rechten en *Alleen vanaf* aan of verleng je hem (de sleutel blijft dezelfde);
met **🗑** trek je hem in. Maken, aanpassen, intrekken en wat een sleutel doet komen in het logboek, met als gebruiker
`API: <naam>`.

### Gebruik

```bash
curl -H "Authorization: Bearer rzm_xxxxxxxx" https://rzmon.jouwdomein.nl/v1.php/status
```

In plaats van de `Authorization`-header kan ook `X-API-Key: rzm_…`, of `?key=rzm_…` in de URL. Die laatste is minder
veilig: hij belandt in logbestanden.

| Endpoint | Resultaat |
|---|---|
| `GET /v1.php/status` | Totaalstatus, gezondheid (`health`), tellers, problemen en status per server |
| `GET /v1.php/hosts` | Alle servers met checks, met `uptime` (% bereikbaar over 24 uur, 7 en 30 dagen) |
| `GET /v1.php/hosts/{id}` | Eén server |
| `GET /v1.php/checks` | Alle checks. Filters: `?status=2`, `?type=disk`, `?host_id=1` |
| `GET /v1.php/checks/{id}` | Eén check, met waarde, eenheid, drempels en bericht |
| `GET /v1.php/checks/{id}/history?hours=24` | Meetwaarden (hooguit 720 uur) |
| `GET /v1.php/alerts?limit=50` | Laatste meldingen (hooguit 500) |

- **Status:** `0` OK, `1` WARNING, `2` CRITICAL, `3` UNKNOWN. Checks in onderhoud tellen niet mee voor de totaalstatus.
- Checks en servers hebben ook velden voor onderhoud (`in_maintenance`, `maintenance_until`), bevestiging
  (`acknowledged`, `acknowledged_by`, `ack_comment`), `problem_since` en `suppressed_by` (de onbereikbare server
  erboven).
- `health` in `/status` geeft onder meer `state` (`ok`, `attention` of `outage`), een `score` (0–100), de onbereikbare
  servers met de reden, open problemen en de beschikbaarheid over 24 uur.
- Tijden zijn in ISO 8601. Het id van een check vind je via `/v1.php/checks`.
- Heeft de sleutel het recht niet, dan krijg je HTTP 403 met het ontbrekende recht. Een verlopen sleutel geeft 401 met
  de datum. Van buiten geeft een geweigerde sleutel een gewone 404 als *Niet herkenbaar* aan staat.

### IDS en kwetsbaarheden lezen

| Endpoint | Recht | Resultaat |
|---|---|---|
| `GET /v1.php/ids` | IDS lezen | IDS aan/uit, modus, open incidenten per ernst, actieve blokkades, servers |
| `GET /v1.php/ids/incidents` | IDS lezen | Incidenten, nieuwste eerst. Filters: `?status=open` (of `done`), `?severity=2`, `?host_id=1`, `?limit=100` (max 500) |
| `GET /v1.php/ids/blocks` | IDS lezen | Actieve blokkades; `?all=1` ook opgeheven en verlopen |
| `GET /v1.php/ids/access` | IDS lezen | Logins, sudo en su. Filters: `?host_id=1`, `?type=ssh`, `?failed=1`, `?limit=100` |
| `GET /v1.php/vulnerabilities` | Kwetsbaarheden lezen | Per server het aantal critical, high, medium, low en actief misbruikt |
| `GET /v1.php/vulnerabilities/{id}` | Kwetsbaarheden lezen | De kwetsbaarheden van één server; `?all=1` ook geaccepteerde |

### IP's blokkeren

Met het recht *IDS blokkeren* stuur je JSON met `POST`:

```bash
# blokkeren op server web01, 24 uur (hours 0 = permanent, max 8760)
curl -H "Authorization: Bearer rzm_xxx" -H "Content-Type: application/json" \
  -d '{"host":"web01","ip":"203.0.113.7","hours":24,"reason":"fail2ban sshd"}' \
  https://rzmon.jouwdomein.nl/v1.php/ids/block

# opheffen: met het id uit het antwoord, of met server + IP
curl -H "Authorization: Bearer rzm_xxx" -d '{"host":"web01","ip":"203.0.113.7"}' \
  https://rzmon.jouwdomein.nl/v1.php/ids/unblock
```

- De server geef je met `host` (naam, hoofdletters maken niet uit) of `host_id`.
- Het antwoord bevat `id`, `active` en `until`. Staat het IDS op *leren* (of uit), dan wordt de blokkade vastgelegd maar
  is `active` `false`; hij wordt actief zodra het IDS op *blokkeren* staat.
- Is het IP op die server al geblokkeerd, dan krijg je `"already": true` met de bestaande blokkade.
- Je lokale netwerken, het adres van RzSense zelf en de whitelist weigert de API altijd (HTTP 422).

**Voorbeeld: fail2ban meldt zijn bans aan RzSense.** Maak `/etc/fail2ban/action.d/rzmon.conf`:

```ini
[Definition]
actionban   = curl -fsS -m 10 -H "Authorization: Bearer <rzmon_key>" -d '{"host":"<rzmon_host>","ip":"<ip>","hours":24,"reason":"fail2ban <name>"}' <rzmon_url>/v1.php/ids/block
actionunban = curl -fsS -m 10 -H "Authorization: Bearer <rzmon_key>" -d '{"host":"<rzmon_host>","ip":"<ip>"}' <rzmon_url>/v1.php/ids/unblock

[Init]
rzmon_url  = https://rzmon.jouwdomein.nl
rzmon_key  = rzm_xxx
rzmon_host = web01
```

Zet in je jail `action = %(action_)s` en `rzmon` onder elkaar. Gebruik voor fail2ban een eigen sleutel met alleen *IDS
blokkeren* en *Alleen vanaf* het IP-adres van die server.

### Checks bedienen

Met het recht *Checks bedienen*:

| Endpoint | Doet |
|---|---|
| `POST /v1.php/checks/{id}/ack` | Storing bevestigen, met optioneel `{"comment": "…"}` |
| `POST /v1.php/checks/{id}/unack` | Bevestiging intrekken |
| `POST /v1.php/checks/{id}/maintenance` | Onderhoud: `{"minutes": 60, "reason": "…"}` of `{"until": "2026-11-01T08:00"}`; `minutes` 0 = onderhoud beëindigen (max 90 dagen) |
| `POST /v1.php/hosts/{id}/maintenance` | Hetzelfde, voor een hele server |
| `POST /v1.php/checks/{id}/run` | De check nu uitvoeren; geeft de nieuwe status terug |

Sensoren, knoppen en de netwerkkaart in Home Assistant staan in [Home Assistant](07-home-assistant.md).

### Problemen

| Probleem | Oplossing |
|---|---|
| 401 met een geldige sleutel (Apache met PHP-FPM) | Zet `CGIPassAuth On` in de virtuele host, of gebruik de header `X-API-Key: rzm_…`. Zie [Installatie](01-installatie.md). |
| `/v1.php/status` geeft 404 (nginx zonder PATH_INFO) | Gebruik `location ~ \.php(/|$)` in nginx, of `/v1.php?r=status`. |

## Statuspagina

Een eenvoudige pagina voor huisgenoten of klanten, zonder inloggen. Je stelt hem in op *Instellingen → Systeem →
Statuspagina*.

1. Maak **onderdelen** ("Internet", "NAS", "Plex" …), elk met de checks die erbij horen. Het ergste van die checks
   telt; checks in onderhoud tonen *Onderhoud*.
2. Geef de pagina een **titel** en eventueel een **mededeling** ("Zaterdag onderhoud aan de NAS").
3. Kopieer de **geheime link** (`/status.php?k=…`). Met **Lokaal | Extern** kies je of de link de Basis-URL gebruikt of
   de **Externe URL** (in te vullen bij *Instellingen → Systeem → Database en server → Server*). De sleutel is in beide
   gevallen dezelfde.

**Goed om te weten**

- Per onderdeel staat een balk per dag over de laatste 90 dagen. De pagina ververst elke minuut, in licht en donker,
  ook op de telefoon.
- Er staan geen IP-adressen, servernamen of foutteksten op.
- Een verkeerde link geeft een gewone 404. Met **Nieuwe link** werkt de oude meteen niet meer.
- Wil je hem van buiten openen, zet dan `/status.php` door in je reverse proxy. Staat *Beheer alleen via https* aan, dan
  stuurt RzSense de statuspagina op het externe adres niet door naar het beheeradres.

## Databasewachtwoord vernieuwen

Op *Instellingen → Systeem → Database en server → Database* staat **Databasewachtwoord vernieuwen** (alleen
beheerders).

1. Klik op de knop en bevestig met je wachtwoord (op je LAN zonder inloggen typ je `VERNIEUWEN`).
2. RzSense maakt een nieuw wachtwoord van 32 tekens, zet het in MariaDB en in `config.php`, en test de verbinding.
   Mislukt er iets, dan wordt alles teruggezet.
3. Het nieuwe wachtwoord wordt één keer getoond. Het staat ook in `/root/rzmon-install.txt`.

Dashboard, cron, agents en de API werken gewoon door. Maak daarna een nieuwe back-up: oude back-ups bevatten nog het oude
wachtwoord.

### De hulpdienst

De webserver mag `config.php` niet zelf wijzigen (de map is na de setup vergrendeld). Daarom doet een kleine
systemd-dienst dat als root: voor het databasewachtwoord, het opslaan van *Database en server*, de back-upmap en het
certificaat. De webpagina zet alleen een verzoek klaar; de dienst controleert alles opnieuw en voert geen commando's uit
die van de webpagina komen. Bij `config.php` maakt hij eerst een reservekopie (de laatste 10).

Het installatiescript installeert de hulpdienst. Ontbreekt hij, dan:

```bash
sudo bash /var/www/rzmon/install/install-helper.sh            # installeren
sudo bash /var/www/rzmon/install/install-helper.sh --uninstall # verwijderen
```

Zonder hulpdienst kan het databasewachtwoord ook in de terminal:

```bash
sudo php /var/www/rzmon/install/install.php --rotate-db-password
```

Reageert de hulpdienst niet, kijk dan met `systemctl status rzmon-helper.path rzmon-helper.service` en
`journalctl -u rzmon-helper.service`.

## Wat RzSense zelf doet om veilig te zijn

- Wachtwoorden met Argon2id; inlogpogingen worden afgeremd en geblokkeerd.
- Elke pagina krijgt een Content-Security-Policy: alleen de eigen scripts van RzSense mogen draaien. Daarnaast
  *nosniff*, een zuinige *Referrer-Policy* en geen toegang tot camera, microfoon of locatie.
- CSRF-bescherming en sessiecookies met `HttpOnly` en `SameSite` (en `Secure` via https).
- API-sleutels, *Onthoud mij*-sleutels en herstellinks worden alleen als hash bewaard.
- Agent en installatiescript zijn ondertekend; een agent installeert alleen een update met een geldige handtekening. Zie
  `SECURITY.md`.

**Insluiten in Home Assistant** (paneel of webpagina-kaart) werkt. Wil je dat alleen je eigen sites RzSense mogen
insluiten, zet dan in `config.php`: `'frame_ancestors' => "'self' https://ha.voorbeeld.nl",`. Werkt er na een update
iets niet meer en vermoed je de Content-Security-Policy, dan kun je hem tijdelijk uitzetten met `'csp' => false,` in
`config.php`. Meld het dan.

## Beveiligingschecklist

- [ ] De document root is `public/`. `http://<server>/config.php` geeft een **404**.
- [ ] De schrijfrechten op `/var/www/rzmon` zijn na de setup teruggezet (`sudo chown root /var/www/rzmon`).
- [ ] Elke beheerder heeft een sterk wachtwoord en 2FA (of een passkey).
- [ ] Bereikbaar van buiten? Dan alleen via https of een VPN. Met een reverse proxy: *Beheer alleen via https*,
      *Inloggen via http weigeren* en *2FA verplicht van buiten* aan.
- [ ] *Niet herkenbaar* staat aan; overweeg *alleen toegestane apparaten*.
- [ ] *Geen login op het lokale netwerk* aan? Dan via 4G getest dat je een inlogscherm krijgt, en het proxy-IP staat
      niet bij *Lokale netwerken*.
- [ ] De inlogbeveiliging staat aan en je lokale netwerken kloppen (van daaruit kun je altijd nog inloggen).
- [ ] Ongebruikte API-sleutels zijn ingetrokken; sleutels die mogen blokkeren hebben *Alleen vanaf*.
- [ ] Alle agents hebben een eigen sleutel; daarna *Alleen agents met een eigen sleutel* en eventueel *Agents alleen via
      https* aan.
- [ ] *Controle op datalekken* staat aan, en er is een noodaccount op *alleen lokaal*. Zie
      [Beveiliging](08-beveiliging.md).
- [ ] Automatische back-ups met een zip-wachtwoord, en af en toe een kopie buiten de server.
- [ ] De MariaDB-gebruiker `rzmon` mag alleen inloggen vanaf `localhost`.
- [ ] `display_errors` staat uit.
