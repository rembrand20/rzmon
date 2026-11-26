# Meldingen

Dit hoofdstuk gaat over meldingen: hoe RzSense je laat weten dat er iets mis is, en wanneer. Je koppelt eerst een of meer
kanalen (Pushover, ntfy, Web Push, e-mail, een chatkanaal, Home Assistant). Daarna bepaal je wanneer een probleem een
melding wordt, wanneer het stil moet zijn, en wat er met beveiligingsmeldingen, systeemmeldingen en rapporten gebeurt.
Alles staat bij *Instellingen → Meldingen en rapporten*.

## Hoe een melding ontstaat

Een melding doorloopt een paar stappen. Elke stap filtert ruis weg:

1. **De check meet** een probleem (warning, critical of onbekend).
2. **Status bevestigen:** de status wisselt pas na een aantal mislukte controles achter elkaar.
3. **Melden na … mislukte checks:** daarna moet de status nog zoveel controles zo blijven.
4. **De meldregel** van die status: nooit, direct, of pas na X minuten.
5. **Stille uren, onderhoud, bevestigd?** Dan gaat er niets naar buiten.
6. **De kanalen** die je bij die status aanvinkte, krijgen de melding.

Bovenaan het venster van een check zie je waar hij staat, bijvoorbeeld
*Meting CRITICAL › Bevestigen ●○○ 1/3 › Melden na N 0/1 › Meldregel direct · ⏳ melding over ±40 s*, of *🔔 gemeld om 14:32*.

Met **📖 Hoe werken meldingen?** (bij *Wanneer melden*) zie je wat er met jouw instellingen per status waarheen gaat,
ook wat je nog niet bewaard hebt. Daaronder staat wanneer elke soort melding verstuurd wordt en wat stille uren doen.

## Taal van meldingen

RzSense schrijft meldingen in het Nederlands of het Engels. Dat is één keuze voor iedereen, los van de taal van je
interface.

1. Ga naar *Instellingen → Meldingen en rapporten → Wanneer melden*.
2. Kies bij **Taal van meldingen en checkberichten** *Nederlands* of *English*.

**Goed om te weten:**
- De keuze geldt voor de berichten via alle kanalen, de berichten van checks (zoals *Poort 443 onbereikbaar*), de
  IDS-regels en de antwoorden van de REST API.
- Wat al bewaard is, blijft in de oude taal: oude meldingen, het logboek, en checkberichten tot de volgende controle.
- De vaste teksten van de agent vertaalt RzSense zelf. Alleen de uitvoer van je eigen commando's en wat een server zelf
  zegt (bijvoorbeeld een SSH-banner) blijven zoals ze zijn.
- Mails voor *wachtwoord vergeten* gaan in de taal van het account.
- De taal van de interface zelf kies je bovenaan *Instellingen* (zie [De interface](02-de-interface.md)).

## Kanalen

Alle kanalen staan bij *Instellingen → Meldingen en rapporten → Kanalen*. Wat je invult, wordt meteen opgeslagen
(✔ opgeslagen naast het veld). Elk kanaal heeft een testknop.

| Kanaal | Geschikt voor | Nodig |
|---|---|---|
| Pushover | Betrouwbare push met geluid en noodmeldingen | Pushover-app (eenmalige aankoop) |
| ntfy | Gratis push, ook met een eigen server | ntfy-app |
| Web Push | Push zonder extra app | RzSense via https met een echt certificaat |
| E-mail | Altijd, of als reserve | Een mailserver (SMTP) |
| Discord, Slack, Teams | Meldingen in een teamkanaal | Een webhook in de chatdienst |
| Home Assistant-webhook | Automatiseringen starten (lamp, TTS, HA-app) | Home Assistant |
| MQTT | Sensoren in Home Assistant | Een MQTT-broker |

In de meldregels heten Pushover en ntfy samen **📲 Pushover / ntfy**: de melding gaat naar wat aan staat. Lukt één
van de twee, dan telt de melding als verstuurd; lukt de ander niet, dan staat dat bij de melding.

Alle links in meldingen (bevestigen, *Open RzSense*) gebruiken de **Externe URL** van RzSense, of anders het beheeradres
of de basis-URL. Die stel je in bij *Instellingen → Systeem → Database en server → Server*.

### Pushover koppelen

Pushover is een app voor pushmeldingen op iOS en Android. Hij kan een melding laten herhalen tot je reageert.

1. Installeer de **Pushover**-app. Na de proefperiode is het een eenmalige aankoop.
2. Log in op [pushover.net](https://pushover.net). Rechtsboven staat je **User Key**.
3. Klik op **Create an Application/API Token**, noem het "RzSense" en kopieer het **API Token**. Kies bij *Icon* het
   bestand [`brand/pushover-icon-72.png`](../brand/pushover-icon-72.png), dan staat het logo van RzSense bij elke
   melding.
4. Ga in RzSense naar *Kanalen → Pushover*, zet **Pushover meldingen aan** aan en vul de user key en het API-token in.
5. Klik op **📲 Testbericht sturen** en kijk op je telefoon.

Onder *Geavanceerd*:

| Instelling | Standaard | Uitleg |
|---|---|---|
| Prioriteit critical | Hoog (1) | *Hoog* maakt geluid, ook tijdens de stille uren van de Pushover-app. *Emergency (2)* herhaalt elke minuut, hooguit een uur, tot je hem in de app bevestigt |
| Prioriteit warning | Normaal (0) | *Laag*, *Normaal* of *Hoog* |
| Geluid | (standaard) | Bijvoorbeeld `siren`, `alien`, `pushover` of `none` |

Info-meldingen gaan altijd stil (prioriteit -1: geen geluid of trilling).

### ntfy koppelen

[ntfy](https://ntfy.sh) is een gratis alternatief voor Pushover. Je kunt het naast Pushover gebruiken of in plaats
ervan.

1. Installeer de **ntfy**-app.
2. Bedenk een **onderwerp** (topic): een lange naam die moeilijk te raden is, bijvoorbeeld `rzmon-k7f2q9x`. Alleen
   letters, cijfers, `-` en `_`.
3. Abonneer je in de app op dat onderwerp. Gebruik je een eigen ntfy-server, vul dan ook dat adres in.
4. Ga in RzSense naar *Kanalen → ntfy* en zet **ntfy meldingen aan** aan.
5. Vul in:
   - **Server**: `https://ntfy.sh` of het adres van je eigen server;
   - **Onderwerp (topic)**: de naam uit stap 2;
   - **Toegangstoken of gebruiker:wachtwoord**: alleen als je server inloggen vraagt (een token begint met `tk_`).
6. Klik op **📲 Testbericht sturen**.

**Goed om te weten:**
- Op ntfy.sh kan iedereen die de naam van het onderwerp kent meelezen. Kies dus een lange naam, of gebruik een eigen
  server met een token.
- De prioriteit volgt Pushover: stil = 2, normaal = 3, hoog = 4, emergency = 5.
- Onder een storing staat een knop **✔ Bevestigen**. Tikken op het bericht opent RzSense.

### Web Push: meldingen zonder extra app

Met Web Push komen meldingen rechtstreeks op je telefoon, tablet of computer, via de browser. Je hebt geen Pushover of
ntfy nodig.

**Nodig:** RzSense via **https met een echt certificaat**, bijvoorbeeld via SWAG. Via `http://` of met een eigen
(self-signed) certificaat staat een browser Web Push niet toe.

**Een apparaat aanmelden** (elke gebruiker doet dit zelf, per apparaat):
- **Android, Windows, Mac, Linux:** open RzSense in Chrome, Edge of Firefox en ga naar
  *👤 Mijn account → Meldingen op dit apparaat*. Klik op **🔔 Meldingen op dit apparaat aanzetten** en kies *Toestaan*.
- **iPhone en iPad** (iOS/iPadOS 16.4 of nieuwer): open RzSense in Safari en kies *Deel (□↑) → Zet op beginscherm*.
  Open RzSense daarna **vanaf het beginscherm** en meld je aan zoals hierboven. In Safari zelf kan het niet; dat is een
  regel van Apple.
- Klik daarna op **🧪 Test** bij je apparaat.

**Per apparaat kiezen** (kolom *Krijgt*): **Alles**, **Alleen critical** (en het herstel daarvan) of
**Alleen IDS-aanvallen**. Elke gebruiker kiest dat voor zijn eigen apparaten, een beheerder voor elk apparaat.

**Beheer:** bij *Kanalen → Web Push* staan de hoofdschakelaar **Web Push aan** en alle apparaten van alle gebruikers,
met **🧪 Test** en **✕ Verwijderen**. Daar staat ook de reserve:
**Komt Web Push niet aan (of staat het uit), dan via Pushover of ntfy** (standaard aan). Is Web Push aangevinkt maar
uit, is er geen apparaat aangemeld, of komt het niet aan, dan gaat de melding via Pushover of ntfy, met de reden erbij.
Kreeg Pushover / ntfy de melding al, dan niet nog een keer. Kiest geen enkel apparaat de melding (bijvoorbeeld allemaal
*Alleen critical*), dan is dat geen fout en springt de reserve niet in.

**Hoe het binnenkomt:** bij een storing staat er een knop **✔ Bevestigen** onder de melding (Android en computers; op
een iPhone tik je op de melding en bevestig je op de pagina die opent). Prioriteit *stil* geeft een melding zonder
geluid; *Emergency* geeft een melding die blijft staan tot je hem wegtikt.

**Veilig:**
- Elk bericht is **versleuteld van RzSense tot je apparaat**. Google, Apple, Mozilla of Microsoft bezorgen het, maar
  kunnen het niet lezen.
- RzSense ondertekent elk bericht met een eigen sleutel. De geheime helft blijft in RzSense.
- RzSense stuurt alleen naar de pushdiensten van Google, Apple, Mozilla en Microsoft, via https met een gecontroleerd
  certificaat. Er gaat geen e-mailadres of domein van jou mee.

**Goed om te weten:**
- Meldt een apparaat zich af of verwijder je de app, dan haalt RzSense het vanzelf van de lijst.
- Herstel je een back-up op een nieuwe server, of krijgt RzSense een nieuwe sleutel? Meld je apparaten dan opnieuw aan.
  De lijst toont *HTTP 403* bij zo'n apparaat.
- Gebruik je *Van buiten alleen op toegestane apparaten*? Zet je telefoon daar dan ook op, anders werkt de
  bevestig-knop onderweg niet (zie [Toegang en beheer](11-toegang-en-beheer.md)).

### E-mail

1. Ga naar *Kanalen → E-mail* en zet **E-mailmeldingen aan** aan.
2. Vul in:

| Instelling | Uitleg |
|---|---|
| Versturen | **Altijd (naast Pushover)**, of **Alleen als Pushover uit staat of mislukt**: e-mail als reserve als geen enkele pushdienst (Pushover, ntfy of Web Push) de melding kon bezorgen |
| SMTP-server, poort, beveiliging | Bijvoorbeeld `smtp.gmail.com`, `587`, STARTTLS. Of poort `465` met SSL/TLS. Poort `25` zonder versleuteling alleen voor een mailserver in je eigen netwerk |
| Gebruikersnaam en wachtwoord | Je mailaccount. Leeg als de server geen login vraagt |
| Afzender | Leeg = je gebruikersnaam (als dat een e-mailadres is) |
| Ontvanger(s) | Eén of meer adressen, met een komma ertussen |
| SSL-certificaat controleren | Onder *Geavanceerd*; alleen uitzetten bij een self-signed certificaat |

3. Klik op **📧 Testmail sturen**. De test gebruikt wat je hebt ingevuld, ook als het nog niet opgeslagen is.

**Goed om te weten:**
- **App-wachtwoord:** Gmail, Outlook/Hotmail en iCloud accepteren je gewone wachtwoord niet. Maak in je
  accountinstellingen een *app-wachtwoord* aan (daarvoor moet tweestapsverificatie bij die dienst aan staan).
- De mail bevat de melding, de tijd, de **bevestig-link** en een link naar RzSense.
- Lukt de test niet, dan zegt de foutmelding wat er mis is, bijvoorbeeld "gebruikersnaam/wachtwoord onjuist" of
  "klopt de beveiliging? 465 = SSL/TLS, 587 = STARTTLS".

### Discord, Slack en Microsoft Teams

Meldingen kunnen ook als bericht in een chatkanaal van je team komen. Elk bericht heeft een kleur per status, de server,
de tijd en een knop: **Bevestigen** bij een storing, anders **Open RzSense**.

1. Maak in de chatdienst een **webhook**:
   - **Discord:** kanaal → *Instellingen* → *Integraties* → *Webhooks* → *Nieuwe webhook* → *Webhook-URL kopiëren*;
   - **Slack:** maak een app met *Incoming Webhooks* en kies het kanaal; je krijgt een adres op `hooks.slack.com`;
   - **Microsoft Teams:** maak in het kanaal de workflow *Post to a channel when a webhook request is received* en
     kopieer het adres. De oude *Office 365-connectors* werken ook nog.
2. Ga in RzSense naar *Kanalen → Discord, Slack en Microsoft Teams*, zet de dienst **Aan** en plak de URL.
3. Kies per dienst:
   - **Welke problemen:** *Warning en critical* of *Alleen critical*;
   - **Ook herstel (weer OK)** (standaard aan);
   - **Ook info en systeemmeldingen** en **Ook IDS-meldingen** (standaard uit).
4. Klik op **💬 Testbericht**.

**Wanneer?** Een chatkanaal krijgt een melding als **Pushover / ntfy** of **Web Push** voor die melding aangevinkt is.
Stille uren, melden na N keer, flappen, escaleren en bundelen gelden dus ook hier, met het filter uit stap 3 erbovenop.
Op de pagina Meldingen staat 💬 bij elke melding die naar een chatkanaal ging (rood als dat mislukte).

**Veilig:** de webhook-URL is een geheim: wie hem kent, kan in je kanaal schrijven. Hij blijft daarom in RzSense en gaat
nooit naar de browser. RzSense stuurt alleen naar de echte adressen van de dienst (`discord.com`, `hooks.slack.com`,
`*.logic.azure.com`, `*.powerplatform.com`, `*.webhook.office.com`), via https met een gecontroleerd certificaat, en
volgt geen doorverwijzingen. In Discord werken `@everyone` en `@rollen` uit de tekst van een melding nooit.

### Home Assistant-webhook

RzSense kan elke melding naar Home Assistant sturen. Daar start je er een automatisering mee: een lamp rood kleuren, een
gesproken melding (TTS), of een notificatie via de HA-app.

**1. Automatisering in Home Assistant.** Ga naar *Instellingen → Automatiseringen → Nieuwe automatisering → ⋮ →
Bewerken in YAML* en plak:

```yaml
alias: RzSense meldingen
description: Meldingen van RzSense via webhook
triggers:
  - trigger: webhook
    webhook_id: rzmon-kies-hier-een-lang-geheim-id   # werkt als wachtwoord
    allowed_methods: [POST]
    local_only: true
actions:
  # Altijd: notificatie via de Home Assistant-app (met knop "Bevestigen" bij een storing)
  - action: notify.mobile_app_jouw_telefoon
    data:
      title: "{{ trigger.json.title }}"
      message: "{{ trigger.json.message }}"
      data:
        actions: >
          {{ [{'action': 'URI', 'title': '✔ Bevestigen', 'uri': trigger.json.ack_url}]
             if trigger.json.ack_url else [] }}
  # Alleen bij CRITICAL: lamp rood laten knipperen
  - if:
      - condition: template
        value_template: "{{ trigger.json.status == 2 and trigger.json.event == 'problem' }}"
    then:
      - action: light.turn_on
        target:
          entity_id: light.bureau
        data:
          color_name: red
          flash: short
mode: queued
```

Vervang `notify.mobile_app_jouw_telefoon` en `light.bureau` door je eigen entiteiten. Het `webhook_id` werkt als
wachtwoord: maak het lang en onvoorspelbaar.

**2. In RzSense:**
1. Ga naar *Kanalen → Home Assistant-webhook* en zet **Webhook aan** aan.
2. Vul als **Webhook-URL** in: `http://<home-assistant>:8123/api/webhook/rzmon-kies-hier-een-lang-geheim-id`.
3. Zet **SSL-certificaat controleren** uit als Home Assistant via https met een self-signed certificaat draait.
4. Klik op **🏠 Testbericht naar Home Assistant**.

Welke meldingen naar Home Assistant gaan, kies je in de meldregels (kolom *Home Assistant*, zie
[Wanneer melden](#wanneer-melden)).

**Inhoud van het bericht** (`trigger.json` in Home Assistant):

```jsonc
{
  "source": "rzmon",
  "event": "problem",              // problem | reminder | recovery | quiet (nog open na de stille uren) | maintenance | info | test
  "status": 2, "status_text": "CRITICAL",
  "previous_status": 0, "previous_status_text": "OK",
  "host": "NAS", "host_id": 2,
  "check": "SMB", "check_id": 5, "type": "tcp", "target": "445",
  "value": null, "unit": "ms",
  "title": "🔴 CRITICAL: NAS – SMB",
  "message": "Poort 445 onbereikbaar: Connection refused",
  "time": "2026-10-19T18:02:05+02:00",
  "url": "http://192.168.1.10",
  "ack_url": "http://192.168.1.10/ack.php?c=5&t=…"   // bevestig-link (null bij herstel en test)
}
```

**Goed om te weten:**
- `local_only: true` betekent dat Home Assistant de webhook alleen vanaf je eigen netwerk accepteert. Draait RzSense
  ergens anders, bijvoorbeeld in de cloud, zet het dan op `false` en gebruik een https-adres.
- Home Assistant krijgt altijd een bericht **per check**, ook als RzSense de andere kanalen bundelt. Zo blijven je
  automatiseringen werken.

### MQTT

Met MQTT verschijnt RzSense vanzelf in Home Assistant, zonder YAML: RzSense zelf, elke server en elke check worden
apparaten en sensoren. Dat is geen melding in de gewone zin, maar je kunt er in Home Assistant wel automatiseringen op
maken.

1. Zorg voor een MQTT-broker, bijvoorbeeld de add-on **Mosquitto broker**, en de integratie **MQTT** in Home Assistant.
2. Ga in RzSense naar *Instellingen → Agents en koppelingen → Home Assistant (MQTT)* en zet **MQTT aan** aan.
3. Vul de **MQTT-server**, de **Poort** (`1883`, of `8883` met **Versleuteling** TLS), de gebruikersnaam en het
   wachtwoord in.
4. Klik op **📡 Verbinding testen & publiceren**.

RzSense publiceert elke minuut. Komt er 10 minuten niets binnen, dan worden de sensoren *niet beschikbaar*: zo merk je
het ook als RzSense zelf uitvalt. MQTT blijft gewoon werken tijdens stille uren en voor checks die *altijd stil* zijn.

Welke sensoren er komen, voorbeelden van automatiseringen, TLS en de prefix staan in
[Home Assistant](07-home-assistant.md).

## Wanneer melden

Bij *Instellingen → Meldingen en rapporten → Wanneer melden* bepaal je wanneer een probleem een melding wordt en
waarheen hij gaat. De algemene instellingen gelden voor alle checks; per check kun je ze aanpassen.

### Per status: melden, herhalen en kanalen

In de tabel kies je per status:

| Status | Melden | Herhalen | Kanalen |
|---|---|---|---|
| 🟠 Warning | Nooit, Direct, of Na … minuten | Elke … min (0 = nooit) | 📲 Pushover / ntfy · 📱 Web Push · E-mail · Home Assistant |
| 🔴 Critical | idem | idem | idem |
| ⚪ Onbekend | idem | idem | idem |
| ✅ Hersteld | Nooit, Direct, of Na … min OK | – | idem |
| ℹ️ Info (checks) | Nooit of Direct (standaard **Nooit**) | – | idem, plus 📋 Meldingenpagina |

- **Na … minuten:** pas melden als de check zo lang **onafgebroken** in die status is. Herstelt hij eerder, dan komt er
  niets. Zo filter je korte pieken weg. Elke status heeft een eigen klok: wordt een warning critical, en staat critical
  op *Direct*, dan komt de melding meteen.
- **Na … min OK** (bij *Hersteld*): de herstelmelding komt pas als het zo lang goed blijft. Gaat het in die tijd weer
  mis, dan blijft het dezelfde storing: geen nieuwe probleemmelding en geen herstelmelding.
- **Herhalen:** elke X minuten een herinnering zolang het probleem blijft.
- **Kanalen:** vink per status aan waar de melding heen gaat. Een kanaal dat uit staat, krijgt *(uit)* in de kop. Staat
  Web Push aangevinkt maar uit, dan zie je onder de tabel een waarschuwing.
- **Melden bij ONBEKEND:** met deze schakelaar zet je meldingen voor de status onbekend in één keer aan of uit.
- **Ook herstel melden na een bevestigde storing:** standaard uit.

Voorbeeld: warning alleen via *Web Push*; critical en hersteld via *Web Push* én *Pushover / ntfy*. Dan komt alles via
Web Push, en een storing daarnaast via Pushover, met de sirene als je dat bij Pushover zo instelt.

**Per check anders:** open de check (✎) en ga naar **🔔 Meldingen**. Zet **Eigen meldregels voor deze check** aan en pas
de tabel aan; de algemene instellingen zijn het startpunt. Checks met eigen regels hebben een 🔔 op het dashboard.
Bijvoorbeeld een back-upcheck die pas na een dag meldt, of een ping die critical meteen meldt en herstel na 2 minuten.

### Melden na … mislukte checks

**Melden na … mislukte checks achter elkaar** (standaard 1): een melding gaat pas uit als de check zoveel keer
**achter elkaar** dezelfde status gaf. Bij 3 en een interval van 60 seconden is dat na ongeveer 2 minuten.

- De telling loopt **per status**: wordt een warning critical, dan begint hij opnieuw. *Onbekend* telt ook als mislukt.
  Eén goede check zet de teller op 0.
- Het aantal **én** de meldregel moeten allebei kloppen. Wil je alleen op het aantal letten, zet de regel op *Direct*.
- Per check anders: ✎ → 🔔 Meldingen → *Melden na … mislukte checks achter elkaar* (leeg = de algemene instelling).
  Bijvoorbeeld 5 voor een onrustige wifi-ping, 1 voor een UPS-status.
- Op het dashboard staat **⏳ 2/3** bij een check die nog niet gemeld is: de tweede mislukte check van de drie.
- De herstelmelding telt niet mee; die volgt de regel bij *Hersteld*.

RzSense kan deze waarde ook zelf voorstellen en instellen (*Melden na N zelf aangepast*, zie
[Servers en checks](04-servers-en-checks.md)).

### Status bevestigen

Een check wisselt pas van status na een aantal controles **achter elkaar**. Eén time-out of een kort piekje maakt een
check zo niet meteen rood. Dit staat in het blok **⏳ Status bevestigen** bij *Wanneer melden*.

| Instelling | Uitleg |
|---|---|
| Warning of critical pas na … mislukte controles | Bijvoorbeeld 3. Warning, critical en onbekend tellen samen. Is de check tussendoor één keer goed, dan begint de telling opnieuw. Leeg = meteen |
| Weer OK pas na … goede controles | Bijvoorbeeld 2, voor een check die steeds even terugvalt. Leeg = meteen (dan komt de herstelmelding het snelst) |
| Sneller bevestigen (standaard aan) | Twijfelt een ping, TCP, HTTP of SNMP nog, dan controleert RzSense hem elke 20 seconden opnieuw. Met 3 is een echte storing dan na ±40 seconden bevestigd; een hapering korter dan dat blijft stil |
| Per soort check | Een eigen waarde per soort (✘ = mislukt, ✔ = goed). Leeg = de algemene waarde |

- **Per check:** ✎ → *Status bevestigen* (schakelaar aan, dan eigen aantallen). Een check met een eigen waarde gaat
  voor de soort.
- **Wat je ziet:** zolang een nieuwe status niet bevestigd is, blijft de check zoals hij was, met het label
  **⏳ CRITICAL 2/3**. Grafieken en de geschiedenis tonen gewoon elke meting.
- **Samen met melden na N:** *Melden na … mislukte checks* telt pas vanaf het moment dat de status bevestigd is. Met 3
  bij bevestigen en 2 bij melden komt de melding na 4 mislukte controles. Wil je alleen bevestigen, zet melden op 1.
- Een nieuwe check krijgt bij de eerste controle meteen zijn status.

**✨ Advies toepassen** stelt in één keer in: critical direct, warning na 10 minuten, onbekend na 15 minuten, herstel
direct, melden na 1, en bevestigen = 1 voor langzame checks (disk, SSL, domein, heartbeat, pad, SMART en uptime). Je ziet
eerst wat er verandert. Herhalen en kanalen blijven zoals ze zijn; terugdraaien kan in het logboek.

### Bundelen

- **Meldingen per server bundelen binnen … seconden** (standaard 120): gaan op één server binnen die tijd meerdere
  checks mis of herstellen ze, dan krijg je **één bericht**, bijvoorbeeld *"🔴 CRITICAL: WebServer01 – 3 meldingen"*.
  Ook herinneringen worden gebundeld. Het bericht krijgt de ergste status en gaat naar alle kanalen van de checks erin.
  De eerste melding wacht daardoor hooguit zo lang. 0 = niet bundelen.
- **Ook over servers heen bundelen** (standaard aan): gaan binnen die tijd meerdere servers mis, dan komt er één bericht
  met de **waarschijnlijke oorzaak** als RzSense die vindt:
  - ze hangen volgens je afhankelijkheden achter dezelfde server (*"kijk daar eerst"*);
  - ze hangen volgens UniFi aan hetzelfde access point of dezelfde switch;
  - alle agents zijn tegelijk stil: dan ligt het eerder aan RzSense zelf of aan zijn netwerk;
  - ze zitten in hetzelfde Proxmox-cluster.

De losse checks blijven zichtbaar in RzSense, en Home Assistant krijgt nog steeds een bericht per check.

### Anti-flapping

Een check die steeds van status wisselt (OK → CRITICAL → OK → CRITICAL) geeft geen stroom berichten. Anti-flapping staat
standaard aan; de getallen pas je aan onder de tabel, en per check in de eigen meldregels.

- Een check **flapt** bij **4 statuswisselingen binnen 30 minuten**. Je krijgt dan één melding:
  *"🔀 FLAPPEND: … staat te flappen (5× gewisseld in 30 min)"*.
- Zolang hij flapt, komt er **per status hooguit één melding**: de eerste warning, critical, onbekend en het eerste
  herstel. Verdere wisselingen en herinneringen worden niet gemeld.
- Is de status **15 minuten** niet veranderd, dan krijg je één melding *"STABIEL: …"* en werkt alles weer normaal.
- Ook hier geldt *melden na … mislukte checks*: haalt de check dat aantal niet, dan flapt hij **stil**.
- Bij de check staat **🔀 flapt** zolang hij flapt, en daarna nog 24 uur een vaag **🔀 flapte (5×)**. Zo zie je achteraf
  welke check onrustig was.

### Escaleren

Bevestigt niemand een storing, dan kan RzSense hem na een tijd **nog een keer melden, een stap hoger**. Dit staat
standaard **uit**.

| Instelling | Standaard | Uitleg |
|---|---|---|
| Escaleren | uit | Aan of uit voor alles |
| Warning: na … min | 30 | Een warning die zo lang open en onbevestigd is, gaat nog een keer uit **als critical**. 0 = nooit |
| Critical: na … min | 15 | Een critical die zo lang open en onbevestigd is, gaat nog een keer uit met de prioriteit hieronder. 0 = nooit |
| Critical wordt | nood | *hoge prioriteit*, of *nood*: Pushover herhaalt dan tot je bevestigt |
| Niet escaleren tijdens de stille uren | aan | 's Nachts geen noodmelding; na de stille uren gaat hij alsnog uit als de storing er dan nog is |

Het bericht heet bijvoorbeeld *"⏫ ESCALATIE: CRITICAL – WebServer01 – MariaDB"*, met *"Al 32 min critical en niet
bevestigd"*. Het gebeurt één keer per storing. **Bevestigen** stopt het. Een check in onderhoud escaleert niet.

### Info-meldingen van checks

De rij **ℹ️ Info (checks)** is voor gebeurtenissen die **geen storing** zijn. Hij staat standaard op *Nooit*.

- **Server herstart:** met de check *Uptime*, de check *HA OS herstart* van Home Assistant (alleen een geplande
  herstart; een onverwachte is critical) en de SNMP-check *Uptime*.
- **SSL-certificaat vernieuwd:** als er ineens meer dagen over zijn dan bij de vorige controle.
- **Info-checks:** een check met de schakelaar *Alleen info-meldingen* telt nooit als storing. Bij een statuswissel komt
  er één info-melding, bijvoorbeeld *"ℹ️ INFO: NAS – Back-up: nu WARNING: …"* (zie
  [Servers en checks](04-servers-en-checks.md)).

Zet de rij op *Direct* en vink de kanalen aan. Info gaat direct (niet gebundeld) en via push **stil**. Tijdens stille
uren en onderhoud gaat info niet naar buiten. De kolom **📋 Meldingenpagina** (standaard aan) bepaalt of info op de
pagina Meldingen komt; staat hij uit, dan wordt info nergens bewaard. Echte storingen staan altijd op de pagina
Meldingen.

Meldingen over RzSense zelf (RzSense of een agent bijgewerkt, logins …) stel je in bij
[Systeemmeldingen](#systeemmeldingen).

### Alarmgedrag

In het blok **Alarmgedrag** stel je waarschuwingen vooraf in. Dit zijn ℹ️-meldingen: geen storing, wel iets om op tijd
naar te kijken.

| Instelling | Standaard | Uitleg |
|---|---|---|
| Waarschuwen als een disk binnen … dagen vol raakt | 7 | 0 = uit |
| Melden als een trend binnen … dagen een grens bereikt | 30 | Uit *Analyse → Trends*; 0 = uit |
| UPS: kortste looptijd die nog goed is (minuten) | 10 | Voor de trend van de accu |

Onder *Geavanceerd*:
- **Logbestanden: treffers tellen over de laatste … minuten** (5–1440), voor logchecks.
- **Automatisch onderhoud na herhaalde meldingen** (standaard aan, na **3** meldingen): na zoveel verstuurde meldingen
  voor dezelfde storing gaat alleen die check in onderhoud, tot hij weer OK is. Je krijgt daar één bericht over. De
  andere checks van de server melden gewoon door. Beëindigen kan met 🔧 bij de check.

Hoe lang meldingen bewaard worden, stel je in bij *Instellingen → Systeem → Database en server* (*Meldingen bewaren
(dagen)*).

## Een storing bevestigen

Met bevestigen zeg je: ik weet ervan. Daarna krijg je voor die storing **geen meldingen meer**: geen herinneringen, geen
escalatie, ook niet als hij erger wordt.

**Zo doe je het:**
- In RzSense: **✔ Bevestigen** bij een check, of alle storingen van een server tegelijk, met een optionele opmerking.
  Ook in het zijpaneel van een melding op de pagina Meldingen.
- **Vanaf je telefoon:** elke melding bevat een knop of link **✔ Bevestigen** (Pushover, ntfy, Web Push, e-mail,
  Discord/Slack/Teams en via de webhook in Home Assistant). Die werkt zonder inloggen, maar alleen voor die ene storing.
- **Emergency-meldingen:** bevestig je in de Pushover-app, dan neemt RzSense dat over. Bevestig je in RzSense, dan stopt
  Pushover met herhalen.

**Goed om te weten:**
- Zodra de check weer OK is, vervalt de bevestiging. Een nieuwe storing geeft gewoon weer een melding.
- Een herstelmelding na een bevestigde storing komt alleen als *Ook herstel melden na een bevestigde storing* aan staat.
- Op de pagina Meldingen kun je een open storing ook als **gezien** markeren. Dat stopt alleen de animatie in de lijst,
  niet de meldingen. Zie [De interface](02-de-interface.md).

## Stille uren

In stille uren gaan er **geen meldingen** naar buiten, bijvoorbeeld 's nachts of in het weekend. Je stelt ze in bij
*Instellingen → Meldingen en rapporten → Wanneer melden → Stille uren*.

### Wat er gebeurt tijdens stille uren

- Er gaat niets naar Pushover, ntfy, Web Push, e-mail, Home Assistant of een chatkanaal, ook geen critical (tenzij het
  profiel *Critical komt wel door* heeft).
- Checks draaien gewoon door. Het dashboard, MQTT en de API laten alles zien. In de gezondheidsbalk staat
  **🌙 Stil tot 07:00**.
- **Na afloop** krijg je **één bericht** met alles wat dan nog mis is, van alle servers samen, bijvoorbeeld
  *"🌅 Na de stille uren: 2 problemen open"*. Kreeg je vóór de stille uren een melding over iets dat 's nachts herstelde,
  dan staat het herstel er ook in. Wat 's nachts misging en vanzelf weer goed kwam, wordt niet gemeld. Bevestigde checks
  en checks in onderhoud tellen niet mee.
- Home Assistant krijgt na afloop per check een bericht met `"event": "quiet"` (nog open) of `"recovery"`.

Een paar soorten gaan **wel** door stille uren heen: een niet-geautoriseerde verbinding, het isoleren van een server,
critical IDS-incidenten, een nieuwe open poort van buiten, en het [beveiligingskanaal](#beveiligingskanaal).

### Een profiel maken

1. Klik op **＋ Nieuw profiel**.
2. Geef het een **Naam** en een **Icoon**, bijvoorbeeld 🌙 *Nacht*, 🏢 *Kantoortijden* of 🏖️ *Vakantie*.
3. Vul een of meer **vensters** in: *van* en *tot*, en de dagen waarop het venster begint. Met **＋ Venster** voeg je er
   een toe.
   - Over middernacht mag: *23:00 tot 07:00 op ma–vr* loopt van maandagavond tot dinsdagochtend.
   - Van gelijk aan tot betekent 24 uur.
4. Kies eventueel:
   - **Critical komt wel door**: warning en onbekend blijven stil, maar een critical gaat gewoon naar buiten;
   - **Alleen in een periode** (bijvoorbeeld je vakantie), met *Van* en *Tot en met*. Met **De hele dag (geen tijden)**
     is het de hele periode stil; anders alleen binnen de vensters. Buiten de periode doet het profiel niets.
5. Kies bij **★ Algemeen** welk profiel overal geldt, of *geen stille uren*.

Bij elk profiel zie je hoe vaak het gebruikt wordt en of het nu actief is. Met ✎ pas je het aan.

### Koppelen en overschrijven

Een fijnere keuze gaat voor een grovere. Van sterk naar zwak:

1. **🌙 Nu stil** bij de check of bij de server;
2. de **check** (✎ → 🌙 Stille uren: *volgt de server*, een profiel, *nooit stil* of *altijd stil*);
3. de **server** (✎ Bewerken → Stille uren: *volgt groep of algemeen*, een profiel, *nooit stil* of *altijd stil*);
4. de **eigen groep** van de server (tabel *Groepen* op de pagina Stille uren);
5. de **soort apparaat** (bijvoorbeeld camera's of netwerk), in dezelfde tabel;
6. **★ Algemeen**.

*Nooit stil* betekent: altijd melden, bijvoorbeeld voor je router. Bij elke check zie je welk profiel geldt en waar het
vandaan komt (in de popup *via server*, *groep*, *soort apparaat* …). Voor veel checks tegelijk: *meerdere checks
bewerken* → 🔔 Melden → Stille uren.

### Altijd stil

**🔕 Altijd stil** betekent: nooit een melding voor deze check, server, groep of soort apparaat, ook niet bij critical.
Handig voor een printer, een testserver of een apparaat dat vaak uit staat.

- Er gaat niets naar buiten, en er komt achteraf ook geen samenvatting. Zet je hem weer aan, dan meldt RzSense pas bij
  de volgende verandering.
- De check draait gewoon door: de status, de pagina Meldingen, het dashboard, Incidenten en MQTT werken zoals altijd.
- Bij de check en de server staat **🔕 stil**, zodat je het niet vergeet. Op de pagina Stille uren staat een lijst
  *Altijd stil*.
- Zet je een server op 🔕, dan kun je één belangrijke check op *nooit stil* zetten.

### Nu stil

Met de knop **🌙 Nu stil** bij een server of check zet je de meldingen meteen stil: 1 uur, 8 uur, tot morgen 07:00, of
tot een tijdstip naar keuze. Dat gaat voor alles, ook voor critical. Een server die nu stil is, neemt al zijn checks
mee. Met **Opheffen** ben je er eerder vanaf. Op de pagina Stille uren staat wat nu stil is.

## Onderhoud

In **🔧 onderhoud** draaien checks gewoon door, maar gaan er geen meldingen uit. Je zet het voor alles, per server of per
check, met een duur (15 minuten tot een week) of een eindtijd en een optionele reden. Is er na afloop nog iets mis, dan
krijg je alsnog een melding. Info-meldingen gaan tijdens onderhoud niet naar buiten.

Hoe je onderhoud plant en hoe afhankelijkheden tussen servers meldingen onderdrukken, staat in
[Servers en checks](04-servers-en-checks.md).

## IDS-pushmeldingen en de dagsamenvatting

Het IDS (inbraakdetectie) ziet veel aanvallen van buiten die al vanzelf tegengehouden worden. Je wilt niet dat elke
daarvan je telefoon laat trillen. Bij *Instellingen → Meldingen en rapporten → IDS-pushmeldingen* kies je wanneer dat
wel gebeurt.

**Pushmelding bij een aanval:**

| Keuze | Wat je krijgt |
|---|---|
| **Alleen als ik iets moet doen** (aanbevolen, standaard) | Geen push voor een aanval van buiten die al automatisch geblokkeerd of tegengehouden is. Wel meteen een push als hij **niet** geblokkeerd kon worden, als de app een gewoon antwoord gaf (misschien gelukt), als het lek past bij software die je echt draait, en altijd bij iets op een server zelf (webshell, verdacht proces, bestand, login, malware). Dezelfde aanvaller binnen 10 minuten: één melding |
| **Alles pushen** | Elk critical incident |
| **Alleen de dagelijkse samenvatting** | Geen losse meldingen voor aanvallen van buiten. Iets op een server zelf komt wel meteen |

- **Via:** 📲 **Pushover / ntfy** en/of 📱 **Web Push** (allebei standaard aan). Dat geldt voor aanvallen en de
  samenvatting. Een chatkanaal krijgt IDS-meldingen alleen als daar *Ook IDS-meldingen* aan staat.
- IDS-pushmeldingen gaan alleen via push, niet via e-mail of de Home Assistant-webhook. Ze gaan ook door stille uren
  heen.
- Een warning-incident geeft geen push; het staat alleen op de pagina IDS.

**Dagelijkse samenvatting (stil, zonder geluid)** (standaard aan, om 20:00): één keer per dag een stille push met de
aanvallen van de afgelopen 24 uur, hoeveel daarvan tegengehouden zijn, de meest geziene aanvallen en hoeveel andere
meldingen op je servers nog open staan. Was er niets, dan krijg je ook niets. Het tijdstip kies je zelf.

Wat het IDS doet en hoe je het instelt, staat in [Beveiliging](08-beveiliging.md).

## Beveiligingskanaal

Het beveiligingskanaal is een apart, hard alarm voor wat **nú** je aandacht nodig heeft: een inbraak, malware, of een
isolatie die op jouw keuze wacht. Het gaat door *Niet storen* en stille uren heen, en blijft komen tot iemand bevestigt.
Het staat standaard **uit**.

### Aanzetten

1. Ga naar *Instellingen → Meldingen en rapporten → Beveiligingskanaal* en zet **Beveiligingskanaal aan** aan.
2. Kies bij **Welke incidenten** *Alleen als je iets moet doen (aanbevolen)* of *Elke critical*.
3. Vink aan welke soorten erdoor gaan:

| Soort | Bijvoorbeeld | Standaard |
|---|---|---|
| Isolatie wacht op jou | Aanvalsketen gevonden; zonder keuze isoleert RzSense na de wachttijd | aan |
| Ransomware of malware op een server | Bestanden worden massaal versleuteld, of een virusscanner vond iets | aan |
| Webshell of verdacht proces | Webserver of database start een shell, reverse shell, aanvalsketen | aan |
| Login of nieuwe beheerder van buiten | Inbraak, root-login, nieuwe gebruiker of beheerder, logboek gewist | aan |
| Aanval niet tegengehouden | Beschermd IP, IDS op leren, of blokkeren mislukt | aan |
| Lek dat je echt draait wordt aangevallen | Een CVE uit Kwetsbaarheden in een aanval op jouw server | uit |

4. Kies bij **Hoe het binnenkomt** de kanalen (zie hieronder).
5. Klik op **Opslaan** en daarna op **Proefmelding sturen**. Bevestig de proefmelding bovenaan de pagina IDS.

Onder de soorten staat hoe vaak het kanaal de afgelopen 30 dagen afgegaan zou zijn met jouw keuze. Een alarm dat vaak
afgaat, ga je negeren: zet dan minder soorten aan.

### Hoe het binnenkomt

| Kanaal | Hoe |
|---|---|
| Pushover / ntfy | Pushover als **noodmelding**: herhaalt elke minuut tot je bevestigt, hooguit een uur. ntfy op urgent |
| Web Push | Blijft op het scherm staan tot je hem wegtikt; elke 5 minuten opnieuw tot iemand bevestigt |
| E-mail | Eén keer, als vangnet |
| Home Assistant-app | Een **kritieke melding**: gaat op de iPhone door *Niet storen* en het stille knopje heen, op vol volume (Android: alarmkanaal). Elke 5 minuten opnieuw tot iemand bevestigt |

De kanalen zelf moeten aan staan bij *Kanalen*; anders staat er *staat uit bij Kanalen* bij. Een chatkanaal
(Discord, Slack, Teams) krijgt de eerste melding ook, als daar *Ook IDS-meldingen* aan staat.

**De Home Assistant-app instellen:**
1. Zet **Home Assistant-app** aan.
2. Vul het **Adres van Home Assistant** in, bijvoorbeeld `https://homeassistant.local:8123`.
3. Vul bij **Telefoon (notify-actie)** de naam van je telefoon in, bijvoorbeeld `mobile_app_iphone_van_jan`. Die vind je
   in Home Assistant bij *Ontwikkelaarshulpmiddelen → Acties* (`notify.mobile_app_…`).
4. Maak in Home Assistant een **langlevend toegangstoken** (je profiel → *Beveiliging* → *Langlevende toegangstokens*)
   en plak het in **Langlevend toegangstoken**.

Het token blijft op de server van RzSense en gaat nooit naar de browser. Dit staat los van de
[Home Assistant-webhook](#home-assistant-webhook).

### Bevestigen

- Bovenaan de pagina **IDS** staat een balk met elke beveiligingsmelding die nog niemand bevestigde. Klik op
  **Ik kijk ernaar**: dan stopt het herhalen overal.
- Bevestigen in de Pushover-app werkt ook.
- De knop in de melding opent alleen RzSense. Isoleren of iets anders doen gaat altijd in RzSense zelf.
- Is het incident afgehandeld of de isolatie beslist, dan stopt het vanzelf. Na een uur stopt het herhalen ook; het
  incident blijft dan open bij IDS.

### Goed om te weten

- Alleen critical incidenten gaan via dit kanaal. Afgehandelde aanvallen van buiten komen er nooit door.
- Wat niet via het beveiligingskanaal gaat, blijft een gewone [IDS-pushmelding](#ids-pushmeldingen-en-de-dagsamenvatting).
- Met **'s Nachts (23:00–07:00) alleen het ergste** gaan 's nachts alleen *Isolatie wacht op jou* en *Ransomware of
  malware* via het kanaal. De rest komt dan als gewone melding.

## Systeemmeldingen

Systeemmeldingen gaan over RzSense zelf, niet over een check: updates, logins, blokkades, certificaten, agents. Bij
*Instellingen → Meldingen en rapporten → Systeemmeldingen* (kaart **ℹ️ Systeeminfo**) kies je per soort waarheen hij
gaat.

**Per soort** vink je aan: **📲 Pushover / ntfy**, **📱 Web Push**, **📧 E-mail**, **🏠 Home Assistant** en
**📄 Meldingenpagina**. Bij **Push-geluid** kies je **🔕 stil** of **🔔 normaal**. Met **🧪** stuur je een voorbeeld naar
wat je aangevinkt hebt, ook tijdens stille uren. Een kanaal dat helemaal uit staat, krijgt *(uit)* in de kop.

| Groep | Meldingen |
|---|---|
| RzSense | RzSense bijgewerkt · Achtergrondtaak lukt niet (en weer gelukt) |
| Agents | Agent bijgewerkt · Agent bijwerken mislukt · Agent verbonden · Agent verbindt niet veilig (en weer veilig) |
| Inloggen | Ingelogd · Mislukte login · Login op een server, van binnen (SSH of console) |
| Blokkades | Account geblokkeerd · Account gedeblokkeerd · IP geblokkeerd · Blokkade opgeheven |
| Kwetsbaarheden | Nieuwe kwetsbaarheden · Een kwetsbaarheid op je servers krijgt veel aandacht · Pakketten bijgewerkt of mislukt · Kritiek advies |
| Beveiliging | Certificaat verloopt binnenkort · Datalek · Inlogpoging van buiten op een lokaal account · Niet-geautoriseerde verbinding |
| Onzichtbaar van buiten | Geweigerd apparaat van buiten · Apparaat toegestaan of ingetrokken · Passkey aangemaakt of verwijderd · Noodlink aangevraagd of gebruikt |
| IDS | Server geïsoleerd of opgeheven |
| Netwerk | Nieuw apparaat op het netwerk · Ongewoon netwerkverkeer · Datalimiet internet bijna of helemaal op · Pad naar een doel gewijzigd · Netwerksnelheid (als de route dat mag) |
| Checks | Normaalwaarde stijgt sluipend · Drempels zelf aangepast · Melden na N zelf aangepast · Zelf hersteld · Waakhond · Trend · End-of-life · NIS2 |
| Logs | Daglimiet van een server bereikt · Maximum bereikt · Vaste zoekactie te traag, uitgezet |
| AI-advies | Samenvatting van een automatisch advies · Weekoverzicht · Tegoed of budget bijna op of op |

**Goed om te weten:**
- **Stille uren:** er gaat niets naar buiten, maar de melding komt wel op de meldingenpagina (als die aangevinkt is).
- **Uitzonderingen:** ⚠️ *Niet-geautoriseerde verbinding* gaat ook tijdens stille uren, en staat altijd op de
  meldingenpagina (daar telt RzSense de pogingen per IP). *Server geïsoleerd of opgeheven* gaat altijd via alle kanalen,
  ook tijdens stille uren.
- **Agent-updates per server:** in de **Agent Manager** van een server kun je *Info bij agent-updates* uitzetten. Dan
  komen updates van die server alleen op de meldingenpagina.
- **Waakhond:** valt RzSense zelf uit, dan kan een agent je waarschuwen via Pushover of ntfy. Dat zet je per server aan
  in de Agent Manager; zie [Linux-agent](05-linux-agent.md). Als RzSense terug is, krijg je de systeemmelding *Waakhond*.
- Meer over de onderwerpen zelf staat in [Beveiliging](08-beveiliging.md), [Netwerk](10-netwerk.md),
  [Kwetsbaarheden en updates](09-kwetsbaarheden-en-updates.md) en [Toegang en beheer](11-toegang-en-beheer.md).

## Rapporten en overzichten

Alle overzichten die RzSense per e-mail of push verstuurt, staan bij
*Instellingen → Meldingen en rapporten → Rapporten*. Op *Analyse* staat daarvoor ook de knop **📬 Rapporten**.

| Rapport | Wat erin staat |
|---|---|
| 📦 Maandoverzicht (alles in één) | De onderdelen die je aanvinkt, in één bericht |
| 🛡️ IDS-weekoverzicht | Geblokkeerde aanvallen, mislukte logins, de IP-adressen met de meeste pogingen, de honeypot, SSH-logins van buiten en open incidenten |
| 🤖 AI-weekoverzicht | In gewone taal wat er speelde, wat terugkwam en waar je eens naar moet kijken (AI-advies moet aan staan) |
| 📈 Maandrapport | Beschikbaarheid, storingen, meldingen, beveiliging, kwetsbaarheden en wat nu aanpakken |
| 🐞 Kwetsbaarheden | Open per ernst, actief misbruikt, *Eén update, veel opgelost* en CVE's met veel aandacht |
| 📶 Beschikbaarheid | Per server hoe vaak hij bereikbaar was, de storingen en de langste |
| 💾 Back-ups | Hoeveel er gemaakt zijn, de ruimte, de laatste en problemen |
| 🔓 Datalekken | Nieuwe lekken op je adressen en wat nog niet afgehandeld is |
| 📊 Netwerkverkeer | Het verkeer van je internetlijn en de top 10 apparaten, met de verandering ten opzichte van de periode ervoor |
| 🇪🇺 NIS2-overzicht | De NIS2-maatregelen met wat RzSense ziet en je notities, en de meldtermijnen. Standaard elk kwartaal |

Een rapport dat een functie nodig heeft die uit staat (IDS, AI-advies, Kwetsbaarheden, Datalekken, NIS2), krijgt een
waarschuwing in zijn kaart.

### Een rapport instellen

Per rapport kies je:
- **aan of uit**;
- **Hoe vaak**: *elke week* (een dag van de week), *elke maand* (dag 1 tot en met 28) of *elk kwartaal* (in januari,
  april, juli en oktober op de gekozen dag, over het vorige kwartaal). De weekoverzichten zijn altijd wekelijks;
- **Om**: de tijd;
- de kanalen: **📧 E-mail (HTML)**, **📱 Web Push** en/of **📲 Pushover / ntfy** (een korte samenvatting);
- **Ontvangers van dit rapport** (alleen beheerders): eigen e-mailadressen, met een komma ertussen, hooguit 20. Leeg =
  de algemene ontvangers bij *Kanalen → E-mail*. Met **Ook naar de algemene ontvangers** gaat hij naar allebei. Zo
  stuur je bijvoorbeeld het NIS2-overzicht naar je directie, zonder dat die je storingsmeldingen krijgt. Gebruikers zien
  de adressen niet, alleen dát een rapport eigen ontvangers heeft.

Bij het **Maandoverzicht** vink je de **Onderdelen** aan die erin komen.

### Knoppen

- **📤 Nu versturen** (beheerders): verstuurt het rapport meteen, naar dezelfde ontvangers als het automatische.
- **👁 Voorbeeld**: opent het rapport in een nieuw tabblad.
- **✉️ Verstuur naar mij** (beheerders en gebruikers): alleen naar het e-mailadres bij *Mijn account*. Hooguit 10 keer
  per uur per account.

Elke verzending (wie, welk rapport, naar welke adressen) komt in het logboek.

**Goed om te weten:**
- Een rapport gaat over de afgelopen week, de vorige maand of het vorige kwartaal. Het wordt één keer per periode
  verstuurd, op of na de gekozen tijd.
- E-mail komt als HTML, met een platte versie erbij. Push is een korte, stille samenvatting.
- Zonder ingestelde e-mail gaan rapporten alleen als push.
- Het AI-weekoverzicht maakt de AI op de gekozen dag en tijd; zodra het klaar is, wordt het verstuurd.

## De meldketen testen

Elk kanaal heeft zijn eigen testknop. Wil je in één keer weten of alles werkt, gebruik dan de meldketentest.

1. Ga naar *Instellingen → Meldingen en rapporten → Kanalen*, kaart **🧪 Meldketen testen** (alleen beheerders).
2. Klik op **🧪 Nu testen**.

RzSense stuurt één testmelding door alle kanalen die aan staan, met de opgeslagen instellingen: Pushover, ntfy, Web
Push, e-mail, de Home Assistant-webhook, Discord/Slack/Teams, MQTT en de meldingenpagina. Per kanaal zie je ✓ verstuurd,
✕ met de fout, of – *staat uit*.

**Komt een melding niet aan?**
- Kijk op de pagina **Meldingen**: bij elke melding staat per kanaal ✓ of een rood ✕. Wijs het ✕ aan voor de reden.
  De tegel *Niet verzonden* toont de meest voorkomende reden van de laatste 24 uur.
- Staat er 🔕 of *[niet verzonden: …]*, dan stond het kanaal of de meldregel uit, of was het stil (stille uren,
  onderhoud, *altijd stil*, bevestigd).
- Open **📖 Hoe werken meldingen?** bij *Wanneer melden* om te zien wat je instellingen per status doen.
- Het beveiligingskanaal test je apart met **Proefmelding sturen**.
