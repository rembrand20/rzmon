# Home Assistant

Wat staat hier: alles over RzSense en Home Assistant. Je leest hoe je Home Assistant OS laat bewaken met de add-on
*RzSense Agent*, hoe je de status van RzSense als sensoren in Home Assistant krijgt, en hoe je de netwerkkaart op je
dashboard zet. Meldingen naar Home Assistant (webhook, MQTT en de kritieke melding in de app) staan in
[Meldingen](03-meldingen.md).

## Wat er kan

| Wat | Richting | Waar |
| --- | --- | --- |
| Add-on *RzSense Agent*: Home Assistant OS bewaken | Home Assistant → RzSense | Dit hoofdstuk |
| Sensoren met de status van RzSense (REST) | RzSense → Home Assistant | Dit hoofdstuk |
| Netwerkkaart op je dashboard | RzSense → Home Assistant | Dit hoofdstuk |
| Knoppen, bv. onderhoud aanzetten (`rest_command`) | Home Assistant → RzSense | Dit hoofdstuk |
| Webhook: automatiseringen starten bij een melding | RzSense → Home Assistant | [Meldingen](03-meldingen.md) |
| MQTT: servers en checks als apparaten en sensoren | RzSense → Home Assistant | [Meldingen](03-meldingen.md) |
| Kritieke melding in de Home Assistant-app (beveiligingskanaal) | RzSense → Home Assistant | [Meldingen](03-meldingen.md) |

## Home Assistant OS bewaken met de add-on

Op Home Assistant OS kun je geen scripts of cron op het systeem zelf zetten. De agent draait daarom als **add-on**. Hij
meldt CPU, geheugen, de data-partitie, Core, Supervisor, add-ons en plugins, updates, back-ups, onverwachte herstarts, de
SD-kaart, USB-sticks, logs en certificaten aan RzSense.

**Waarom een aparte bewaking:** valt Home Assistant helemaal uit, dan stopt ook de add-on met rapporteren. RzSense merkt
dat binnen een paar minuten en stuurt je een melding. Die loopt dus niet via Home Assistant zelf, en komt ook als Home
Assistant geen meldingen meer kan sturen.

### Installeren

1. **In RzSense:** klik op **＋ Server**. Naam bv. *Home Assistant*, adres het IP-adres van Home Assistant, type
   **Remote met agent**.
2. Open de *Agent Manager* van die server, tabblad **🏠 Home Assistant OS**, en klik op **🔗 Koppelcode maken**. De code is
   eenmalig en 15 minuten geldig.
3. **De add-on toevoegen aan Home Assistant.** Kies één manier:
   - **Via GitHub (aanbevolen):** *Instellingen → Add-ons → Add-on winkel* → ⋮ → **Repositories**, en voeg
     `https://github.com/rembrand20/rzmon` toe. Updates van de add-on komen dan vanzelf binnen.
   - **Met de Samba share-add-on:** kopieer de map `rzmon_agent` (uit `ha-addon/`) naar de share `addons`
     (`\\homeassistant.local\addons` op Windows, `smb://homeassistant.local/addons` op een Mac).
   - **Met de SSH-add-on:** `scp -r ha-addon/rzmon_agent root@homeassistant.local:/addons/`
4. In Home Assistant: *Instellingen → Add-ons → Add-on winkel* → ⋮ → **Controleren op updates**. **RzSense Agent** staat nu
   onder *RzSense add-ons* (via GitHub) of *Lokale add-ons* (via Samba of SSH). Klik op **Installeren**; het bouwen duurt
   ongeveer een minuut.
5. Vul op het tabblad **Configuratie** in:
   ```yaml
   monitor_url: http://192.168.1.10
   pair_code: K7QF-M2XA-9RTD-HC4P-WE8N
   verify_ssl: true
   certs:                        # vervaldatum bewaken (optioneel)
     - /ssl/fullchain.pem        # van de Let's Encrypt- of DuckDNS-add-on
     - mijndomein.duckdns.org:443
   ```
6. Klik op **Opslaan** en dan op **Starten**. Controleer op het tabblad *Info* dat *Starten bij opstarten* aan staat.
7. Kijk in het **Logboek** van de add-on. Daar moet `Verbonden met RzSense ✔` staan. Met de koppelcode haalt de add-on zijn
   eigen sleutel op; daarna gaat alles versleuteld en ondertekend.
8. **In RzSense:** klik in de *Agent Manager* op **＋ Aanbevolen checks**. Dat stelt de checks hieronder voor, plus je
   USB-sticks en de belangrijkste containers en logs.

Zet bij de server ook een **ping-check** en een **TCP-check op poort 8123**. Die werken los van de add-on.

### Opties van de add-on

| Optie | Betekenis |
| --- | --- |
| `monitor_url` | Adres van RzSense, zonder `/api.php` |
| `monitor_url_fallback` | Reserve-adres als `monitor_url` niet bereikbaar is, bv. `https://rzsense.voorbeeld.nl` met als reserve `http://192.168.1.10` |
| `pair_code` | Koppelcode uit de Agent Manager |
| `pin` | Alleen bij een eigen (self-signed) certificaat van RzSense: de vingerafdruk, die staat bij de koppelcode |
| `verify_ssl` | Zet uit bij een self-signed certificaat zonder `pin` |
| `certs` | Certificaten waarvan RzSense de vervaldatum bewaakt: bestanden in `/ssl` en/of `host:poort`. Kies ze in RzSense bij een check *SSL-certificaat* |
| `stats_interval` | Hoe vaak (in minuten) de add-on CPU, geheugen en netwerk van Core, Supervisor en elke add-on ophaalt. Standaard 5; 1 = elke minuut, 0 = nooit. Elke vraag kost de Supervisor ongeveer 2 seconden en een regel in zijn log |
| `token` | Oude manier van koppelen; niet nodig met een koppelcode |
| `interval` | Niet meer nodig: hoe vaak de add-on rapporteert, kies je in RzSense |

**Hoe vaak:** stel je in RzSense in, bij *Agent Manager → Rapporteren elke …* (standaard 60 seconden). Met **Realtime**
(ook in de Agent Manager) kijkt de add-on elke 5 seconden of er iets verandert aan de add-ons, Core of de opstarttijd, en
rapporteert dan meteen. Of een add-on draait, controleert hij altijd elke minuut.

### Wat er bewaakt wordt

| Check in RzSense | Betekenis |
| --- | --- |
| CPU / RAM | Van de Home Assistant-host, met load en swap |
| Diskruimte `/data` | De data-partitie: configuratie, database, back-ups en media |
| Service `homeassistant` | Draait Home Assistant Core en reageert de API? |
| Service `supervisor` | Is de Supervisor *healthy*? |
| Service `addon:<slug>` | Draait een add-on? Bv. `addon:core_mosquitto`, `addon:45df7312_zigbee2mqtt` of `addon:a0d7b954_nodered` |
| Service `plugin:<naam>` | Draait een plugin van de Supervisor (`dns`, `audio`, `cli`, `multicast`, `observer`)? |
| Service `updates` | Warning met de lijst van beschikbare updates (Core, Supervisor, OS, add-ons en plugins) |
| Poortscan | Luisterende poorten van de host (zonder procesnamen) |
| SSL-certificaat | Vervaldatum van de certificaten uit de optie `certs` |
| Home Assistant → **Laatste back-up** | Dagen sinds de laatste geslaagde automatische back-up (standaard warning 2, critical 7). Ook warning als de laatste poging mislukt is. Nodig: Home Assistant 2025.2 of nieuwer met automatische back-ups aan |
| Home Assistant → **Integraties** | Aantal integraties dat niet geladen is, met hun namen. Uitgeschakelde integraties tellen niet mee |
| Home Assistant → **Systeemstatus** | Reparaties van de Supervisor: *niet gezond* = critical; *niet ondersteund* of een melding (bv. weinig vrije ruimte) = warning |
| Home Assistant → **Onverwachte herstart (HA OS)** | Critical met een melding als Home Assistant OS opnieuw is opgestart zonder OS-update (stroomuitval, crash of een handmatige herstart). Blijft een uur critical. Een herstart door een OS-update staat er alleen bij (bv. *16.2 → 16.3*) |
| Home Assistant → **Levensduur SD-kaart / eMMC** | Hoeveel procent van de opslag versleten is (standaard warning 75 %, critical 90 %). Alleen als je hardware dat meldt, anders onbekend |
| Home Assistant → **OS-update mislukt** | Critical als een OS-update niet wilde opstarten en Home Assistant OS is teruggevallen op de vorige versie |
| Home Assistant → **USB-apparaat aanwezig** | Kies een apparaat (Zigbee- of Z-Wave-stick, Bluetooth). Is het weg, dan critical |
| Containers 📦 | Core, Supervisor, de plugins en elke add-on, met CPU en RAM. Gestopt met een fout = critical, net herstart door de watchdog = warning; CPU en RAM naar keuze met drempels |
| Logbestand (zoeken) | Bronnen `ha:host` (systeemlog van HA OS), `ha:core`, `ha:supervisor` en `ha:addon:<slug>`, met voorinstellingen voor te lage voeding (Raspberry Pi), diskfouten en fouten in Core of de Supervisor |

De Home Assistant-checks voeg je toe met **＋ Check → Home Assistant**, en dan bij *Bewaken* wat je wilt. Services kies je
bij **＋ Check → Service** uit een lijst. Een overzicht van de containers krijg je met de knop **📦 Containers** bij de
server.

De add-on haalt back-up, integraties en systeemstatus elke 5 minuten op, de rest bij elk rapport. Geeft je Home Assistant
iets niet vrij, dan staat de check op onbekend met de reden erbij.

### Goed om te weten

- **Alleen lezen:** de add-on verandert niets aan je installatie. Hij heeft de Supervisor-rol **manager** nodig om de
  statistieken per container, de logs en de USB-apparaten te lezen; Home Assistant toont dat bij de add-on. Het
  host-netwerk heeft hij nodig voor de lijst met poorten, en `/ssl` leest hij alleen.
- **Herstarts van containers:** de Supervisor houdt geen herstarts bij. De add-on ziet een herstart aan de *watchdog* in
  het log van de Supervisor, en aan een container die tussen twee rapporten opnieuw is gestart. Een add-on die je zelf
  (her)start, telt niet als automatische herstart.
- **Een add-on die je bewust uit hebt staan** geeft alleen een melding als je er een check voor maakt.
- **Ondersteunde hardware:** `aarch64` (Raspberry Pi 4/5, Home Assistant Green en Yellow), `amd64` (NUC, mini-pc, VM) en
  `armv7`.
- **Bijwerken** gaat via de add-on winkel van Home Assistant, niet via de knop in RzSense.
- **Niet in de add-on:** commando-checks, logins en sudo, bestandsbewaking, endpoint-bewaking en ransomware-bescherming.
  Die werken alleen met de [Linux-agent](05-linux-agent.md).
- **Heartbeat:** in de add-on is er geen `rzmon-hb`. Laat een automatisering zich melden bij de geheime URL van een
  heartbeat-check met een `rest_command`; zie [Servers en checks](04-servers-en-checks.md).
- Deze checks zijn getest met een nagebootste Supervisor. Zie je iets vreemds, bijvoorbeeld een herstart die niet klopt,
  laat het dan weten.

### Problemen

| In het logboek van de add-on | Oplossing |
| --- | --- |
| `403` | De add-on wordt geweigerd. Kijk bij *Meldingen* in RzSense waarom (sleutel, nieuw IP-adres). Staat de server in RzSense op type *Remote met agent*? Maak anders een nieuwe koppelcode |
| `Could not resolve` of `Failed to connect` | `monitor_url` klopt niet, of RzSense is niet bereikbaar vanaf Home Assistant |
| `SSL` of `certificate` | RzSense heeft een eigen certificaat: vul `pin` in, of zet `verify_ssl` uit |

## De status van RzSense in Home Assistant

Met de REST API van RzSense haal je de status op in Home Assistant, als sensoren. Hoe de API werkt en welke rechten een
sleutel kan hebben, lees je in [Toegang en beheer](11-toegang-en-beheer.md).

### Sensoren

1. Maak in RzSense een API-sleutel bij *Instellingen → Systeem → REST API*, met alleen
   **Status lezen**. Kopieer de sleutel (`rzm_…`) meteen; je ziet hem maar één keer.
2. Zet in Home Assistant in `secrets.yaml`:
   ```yaml
   rzmon_auth: "Bearer rzm_xxxxxxxx"
   ```
3. Zet in `configuration.yaml`:
   ```yaml
   rest:
     - resource: http://192.168.1.10/v1.php/status
       headers:
         Authorization: !secret rzmon_auth
       scan_interval: 60
       sensor:
         - name: "RzSense status"
           value_template: "{{ value_json.status_text }}"
           json_attributes: [counts, problems]
         - name: "RzSense problemen"
           value_template: "{{ value_json.counts.warning + value_json.counts.critical }}"
         - name: "RzSense in onderhoud"
           value_template: "{{ value_json.counts.maintenance }}"
       binary_sensor:
         - name: "RzSense alles OK"
           value_template: "{{ value_json.status == 0 }}"

     - resource: http://192.168.1.10/v1.php/checks/3      # bv. CPU van je webserver
       headers:
         Authorization: !secret rzmon_auth
       scan_interval: 60
       sensor:
         - name: "Webserver CPU"
           value_template: "{{ value_json.value }}"
           unit_of_measurement: "%"
           state_class: measurement
   ```
   Het nummer van een check vind je via `/v1.php/checks`.
4. Herstart Home Assistant.

Ideeën: een lamp die rood wordt als `binary_sensor.rzmon_alles_ok` uit gaat, of een dashboardkaart met de lijst
`problems`.

### Knoppen: RzSense bedienen vanuit Home Assistant

Met een sleutel met het recht **Checks bedienen** kan Home Assistant bijvoorbeeld onderhoud aanzetten. Zet in
`configuration.yaml`:

```yaml
rest_command:
  rzmon_onderhoud:
    url: "http://192.168.1.10/v1.php/hosts/{{ host_id }}/maintenance"
    method: POST
    headers:
      Authorization: !secret rzmon_bedienen
    content_type: application/json
    payload: '{"minutes": {{ minutes }}, "reason": "Home Assistant"}'
```

Met `minutes: 0` beëindig je het onderhoud. Gebruik voor bedienen een aparte sleutel, met *Alleen vanaf* het adres van
Home Assistant.

## De netwerkkaart op je dashboard

Een eigen kaart voor je Home Assistant-dashboard met **je netwerk in het kort**:

- bovenaan **één oordeel**: *Alles in orde*, *Let op* of *Storing*, met de redenen eronder (bv. *4 nieuwe apparaten*);
- **Internet**: download en upload van de laatste meting, de vertraging en hoeveel procent van je abonnement je haalt;
- **Deze maand**: het verbruik van je internetlijn, je limiet en wat er aan het eind van de maand verwacht wordt;
- **Apparaten**: hoeveel er online zijn (het laatste uur gezien) en hoeveel nieuw;
- **IDS**: open incidenten, hoeveel kritiek en hoeveel IP-adressen vandaag geblokkeerd zijn (alleen met het recht
  *IDS lezen*);
- **Netwerkpaden** (in RzSense: *Netwerk → Paden*): elk pad met een bolletje, de vertraging en het verlies;
- **Top verkeer vandaag**: de drie apparaten met het meeste verkeer;
- **Servers** (schakelaar *Servers*): elke server met een bolletje, CPU, RAM en disk als balkje (oranje vanaf 75 %, rood
  vanaf 90 %) en wat er speelt, bv. *2 problemen*, *agent stil 12 min* of *onderhoud*. De ergste staan bovenaan; met
  *Max. servers* (standaard 8) kies je hoeveel. De servers tellen dan ook mee in het oordeel bovenaan.

Tik op een tegel of regel en RzSense opent op die plek. De kaart volgt het thema (licht of donker) en de taal van Home
Assistant, en ververst elke minuut (en meteen als je het dashboard weer opent).

### Zo zet je hem erin

1. Maak in RzSense een API-sleutel bij *Instellingen → Systeem → REST API*, met alleen
   **Status lezen** (en **IDS lezen** voor de IDS-tegel). Het venster met de nieuwe sleutel toont meteen de YAML voor de
   kaart, en onder de API-sleutels staat de juiste URL van de kaart.
2. In Home Assistant: *Instellingen → Dashboards → ⋮ → Bronnen → Bron toevoegen*. URL: `https://<rzsense>/ha-card.php`,
   soort **JavaScript-module**.
3. Ververs de pagina, bewerk een dashboard en voeg de kaart **RzSense netwerk** toe. Vul de URL van RzSense en de sleutel
   in; de rest kies je met schakelaars. Of in YAML:
   ```yaml
   type: custom:rzmon-network-card
   url: https://rzsense.thuis.lan
   api_key: rzm_xxxxxxxx
   refresh: 60            # seconden (15–3600)
   title: Netwerk         # optioneel
   show_ids: true         # ook: show_internet, show_month, show_devices, show_paths, show_top, show_problems, show_servers
   max_servers: 8         # de rest als "+3 meer"
   ```

### Goed om te weten

- De **browser of de Home Assistant-app zelf** haalt de gegevens bij RzSense op, niet de Home Assistant-server. RzSense
  moet dus bereikbaar zijn vanaf je telefoon en computer.
- Gebruikt Home Assistant **https**, dan moet RzSense ook via https bereikbaar zijn. Anders blokkeert de browser het als
  *mixed content*.
- Zet je *Alleen vanaf* bij de sleutel, neem dan de adressen (of het netwerk) van je telefoon en computer op.
- Iedereen die het dashboard mag bewerken, kan de sleutel zien. Geef deze sleutel daarom **alleen leesrechten**.
- De kaart gebruikt `GET /v1.php/network` (met `?servers=1` ook de servers). Dat kun je ook zelf gebruiken, bijvoorbeeld in
  een `rest`-sensor.
