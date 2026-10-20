# 📡 RzMon

Lichtgewicht server-monitoring in PHP 8 + MariaDB met een moderne HTML5-interface, meldingen via Pushover, e-mail en Home Assistant, en MQTT discovery voor Home Assistant.

📖 **Uitgebreide installatiehandleiding en uitleg van alle features: [HANDLEIDING.md](HANDLEIDING.md)**
🐧 **Rocky Linux 10 / RHEL 10 / AlmaLinux 10 (met SELinux en firewalld): [INSTALL-ROCKY-LINUX-10.md](INSTALL-ROCKY-LINUX-10.md)**, of in één keer met het installatiescript:
```bash
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh && sudo bash install-rocky.sh
```

**Checks**

| Type | Wat | Drempel |
|---|---|---|
| Ping | Reageert de server? (3 pings) | gemiddelde ms — 100% verlies = CRITICAL |
| TCP poort | Draait een dienst op poort X (SSH, MariaDB, SMTP…) | connect-tijd ms |
| HTTP(S) | Website/API bereikbaar, status < 400, SSL-cert geldig | responstijd ms |
| SSL-certificaat | Dagen dat een certificaat nog geldig is: elke TLS-poort in je netwerk of op internet, of via de agent (certificaatbestanden en lokale poorten, `CERTS`) | dagen — warning **onder** 21, critical **onder** 7 |
| Diskruimte | % gebruikt per mount + voorspelling "vol over ~N dagen" | % gebruikt; vol binnen 7 dagen = WARNING |
| Service | systemd-service `active`? Via de agent ook: poorten, antwoordt hij (HTTP, database, SSH, mail, DNS…), herstarts, log-fouten, extra URL/poort-test of eigen script | niet actief / antwoordt niet = CRITICAL |
| CPU | CPU-gebruik over 2 sec + load average | % gebruik |
| RAM | Geheugengebruik (MemAvailable) + swap | % gebruikt |
| Podman (algemeen) | Via de agent: gestopte, ongezonde of gepauzeerde containers, pods | probleem = WARNING |
| Logbestand (zoeken) | Via de agent: treffers in een logbestand, het systemlog of kernelmeldingen, met voorinstellingen voor mislukte logins, disk- en hardwarefouten | aantal treffers in 15 min |
| Container (Podman) | Via de agent, per container: status (healthcheck, herstarts), CPU, RAM of disk | %, MB |

**Host-types**
- **Lokaal** – de monitor-server zelf: disk en services worden direct uitgelezen.
- **Agent** – remote Linux-server met `agent/rzmon-agent.sh`, geïnstalleerd met één commando (`agent/install-agent.sh`, wordt door RzMon zelf aangeboden), (bash + curl) die elke minuut disk & services pusht, of **Home Assistant OS** met de add-on in `ha-addon/`. Stopt de agent met rapporteren → CRITICAL.
- **Remote** – alleen netwerkchecks (ping/poort/http), bv. routers, NAS, externe sites.

**Poortscan**: scan vanuit RzMon welke TCP-poorten op een server open staan (bekende poorten, 1-1024 of een eigen lijst/range) en voeg ze met één klik toe als check. Bij agent-servers zie je ook welke poorten er lokaal luisteren, inclusief procesnaam.

**Onderhoud & afhankelijkheden**: 🔧 meldingen pauzeren voor alles, een server of een check (bv. tijdens updates). 🔗 Servers achter een router/switch/VPN geven geen eigen melding als die onbereikbaar is: één melding in plaats van tientallen.

**Dashboard**: kies onder *Instellingen → Weergave* tussen **Blox** (tegels) en **L1nes** (servers onder elkaar als tabel, in Small of Large); de keuze geldt per apparaat.

**Storingen bevestigen**: ✔ bevestig een storing in het dashboard, via de link in de Pushover-melding, of in de Pushover-app (Emergency). Daarna krijg je er geen meldingen meer over; na herstel vervalt de bevestiging vanzelf.

**Home Assistant-webhook**: elke melding ook als JSON naar een HA-webhook, om automatiseringen te starten (zie HANDLEIDING.md §6.3).

**Alarmering**: na *N* opeenvolgende fouten een Pushover-bericht, bij escalatie (WARN → CRIT) opnieuw, optioneel elke *X* minuten herhalen en een melding bij herstel. Alles instelbaar in de webinterface.

---

## Installatie (Debian / Ubuntu)

> Rocky Linux 10 / RHEL / AlmaLinux: zie [INSTALL-ROCKY-LINUX-10.md](INSTALL-ROCKY-LINUX-10.md).

```bash
sudo apt install apache2 mariadb-server php php-mysql php-curl php-mbstring iputils-ping
sudo cp -r rzmon /var/www/
sudo chown -R root:www-data /var/www/rzmon
sudo chown www-data /var/www/rzmon     # setup mag config.php aanmaken

# Database + gebruiker (root gebruikt op Debian unix_socket, dus via de terminal):
sudo mysql -e "CREATE DATABASE rzmon CHARACTER SET utf8mb4;
  CREATE USER 'rzmon'@'localhost' IDENTIFIED BY 'EEN_STERK_WACHTWOORD';
  GRANT ALL ON rzmon.* TO 'rzmon'@'localhost';"
```

Stel de webserver in (hieronder) en open **`http://<server>/`** vanaf je lokale netwerk. Je komt automatisch op de **setup-pagina**:

1. **Systeemcheck** – PHP-versie, extensies, ping, systemctl, schrijfrechten, database, cron.
2. **Database** – host/poort/naam/gebruiker/wachtwoord, *Verbinding testen*, *Opslaan & tabellen installeren*. Optioneel maakt RzMon de database en gebruiker zelf aan met een MariaDB-beheerdersaccount (werkt niet met `unix_socket`-root; gebruik dan het commando hierboven).
3. **Applicatie & PHP** – tijdzone, basis-URL, pad naar ping, `memory_limit`, `max_execution_time`, `display_errors`.
4. **Beheerder** – eerste gebruikersnaam en wachtwoord.
5. **Cron** – kant-en-klare regel om te kopiëren.

Daarna is de setup altijd bereikbaar via de tab **Setup** (alleen ingelogd). Zolang de installatie niet af is, of als de database onbereikbaar is, werkt de setup **alleen vanaf een privé-netwerk** (192.168.x, 10.x, 172.16-31.x, localhost).

> Mag de webserver `config.php` niet schrijven? Dan toont de setup de inhoud, die je zelf in `/var/www/rzmon/config.php` plaatst. Daarna kun je de schrijfrechten weer intrekken: `sudo chown root /var/www/rzmon`.

Liever via de terminal? Dat kan nog steeds:
```bash
sudo cp config.sample.php config.php && sudo nano config.php
sudo -u www-data php install/install.php admin
echo '* * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
```

### Webserver
De **document root moet `public/`** zijn (dan zijn config en broncode niet bereikbaar).

Apache (`/etc/apache2/sites-available/rzmon.conf`):
```apache
<VirtualHost *:80>
    ServerName rzmon.example.lan
    DocumentRoot /var/www/rzmon/public
    <Directory /var/www/rzmon/public>
        Require all granted
        # Nodig met PHP-FPM: geeft "Authorization: Bearer …" (REST API) door aan PHP
        CGIPassAuth On
    </Directory>
</VirtualHost>
```
```bash
sudo a2ensite rzmon && sudo systemctl reload apache2
```

Nginx + PHP-FPM:
```nginx
server {
    listen 80;
    server_name rzmon.example.lan;
    root /var/www/rzmon/public;
    index index.php;
    # (/|$) zorgt dat ook /v1.php/status (API) werkt
    location ~ \.php(/|$) { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
}
```

> 🔒 Tip: zet er HTTPS voor (certbot) of maak hem alleen bereikbaar via je LAN/VPN.

### Pushover
1. Installeer de Pushover app en kopieer je **User Key** van https://pushover.net
2. Maak een applicatie aan op https://pushover.net/apps/build → **API Token**
3. Webinterface → *Instellingen* → invullen → *Testbericht sturen* → *Opslaan*

### E-mail
*Instellingen → E-mail*: SMTP-server, poort, beveiliging (STARTTLS / SSL/TLS / geen), login, afzender en ontvangers. Kies **altijd** of **alleen als Pushover faalt**. Gmail/Outlook/iCloud: gebruik een app-wachtwoord.

### MQTT (Home Assistant)
*Instellingen → MQTT*: server, poort, TLS, gebruikersnaam en wachtwoord van je broker (bv. de Mosquitto-add-on). Elke server verschijnt in Home Assistant als apparaat met sensoren voor status en meetwaarden; RzMon publiceert elke minuut.

### Gebruikers, rollen en tweestapsverificatie
*Instellingen → Gebruikers*: rollen **Beheerder** (alles), **Gebruiker** (storingen afhandelen) en **Alleen lezen** (kijken). Op het lokale netwerk kom je zonder wachtwoord binnen als alleen lezen, tenzij een beheerder onder *Toegang* "als beheerder inloggen" aanzet. Met *Onthoud mij op dit apparaat* blijf je 30 dagen ingelogd (beheer per apparaat via 📱). Accounts aanmaken met of zonder 2FA (TOTP, QR-code voor elke authenticator-app), 2FA resetten, wachtwoord instellen, verwijderen. Telefoon kwijt: `sudo -u www-data php install/install.php --reset-2fa <naam>`.

### Inloggen zonder wachtwoord op je lokale netwerk
*Instellingen → Toegang* → **Geen login nodig op het lokale netwerk**.

- Vul je lokale netwerken in (standaard alle privé-ranges; beperken tot bv. `192.168.1.0/24` kan ook). De kaart toont je huidige IP en of dat als lokaal wordt herkend.
- Van buitenaf blijft gewoon de login nodig. Ga je met een open sessie van wifi naar 4G, dan word je automatisch uitgelogd.
- Bij een automatische login staat rechtsboven **🏠 Lokaal netwerk**.
- **Reverse proxy** (Nginx Proxy Manager, Traefik, Cloudflare tunnel, HA-addon e.d.): vul het IP van de proxy in bij *Reverse proxy IP's*. Het echte bezoekers-IP wordt dan uit `X-Forwarded-For` gehaald. Zet het proxy-IP **nooit** bij de lokale netwerken, anders komt iedereen via de proxy zonder wachtwoord binnen.
- De REST API vraagt altijd om een API-sleutel, ook op het LAN.

### Poortscan
Klik bij een server op 🔍. Kies *Bekende poorten*, *1-1024* of een eigen lijst zoals `22,80,443,8000-8100` (max. 4096 poorten). Open poorten krijgen een knop **＋ Check**: die maakt meteen een TCP-check aan, zodat je een Pushover-melding krijgt als de dienst wegvalt.
Bij agent-servers zie je ook de **luisterende poorten volgens de agent** (via `ss`, met procesnaam als de agent als root draait), dus ook poorten achter een firewall of op `127.0.0.1`.

> Scan alleen je eigen servers. Op sommige netwerken (hosting, bedrijfsnetwerk) kan een poortscan als aanval worden gezien.

### Agent op remote servers
Voeg in de webinterface een server toe met type **Remote met agent** en klik *Agent installeren* — je krijgt de kant-en-klare commando's met het token. Kort gezegd:

```bash
sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
sudo tee /etc/default/rzmon-agent <<'EOF'
MONITOR_URL="http://rzmon.example.lan"
TOKEN="<token uit de webinterface>"
SERVICES="ssh cron nginx mariadb"
EOF
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon-agent
```

### Home Assistant OS / Supervised (add-on)
Op Home Assistant OS kun je geen cron-scripts op de host zetten. Daarom zit de agent er ook bij als **add-on**: `ha-addon/rzmon_agent`.

| Check in RzMon | Wat |
|---|---|
| CPU / RAM | Van de HA-host |
| Diskruimte `/data` | De data-partitie (config, database, back-ups, media) |
| Service `homeassistant` | Draait Core en reageert de API? |
| Service `supervisor` | Is de Supervisor *healthy*? |
| Service `addon:<slug>` | Draait een add-on? bv. `addon:core_mosquitto` |
| Service `updates` | **WARNING** bij een update voor Core, Supervisor, OS of een add-on |
| Poortscan | Luisterende poorten van de host |

Installeren:
1. Voeg in RzMon een server toe met type **Remote met agent** (adres = IP van Home Assistant) en kopieer het token.
2. Kopieer de map `ha-addon/rzmon_agent` naar de share **`addons`** (met de Samba share-add-on) of naar `/addons/` (met de SSH-add-on).
3. *Instellingen → Add-ons → Add-on winkel* → ⋮ → **Controleren op updates** → **RzMon Agent** (onder *Lokale add-ons*) → Installeren.
4. Op het tabblad Configuratie vul je `monitor_url` en `token` in → Starten. Het logboek toont `Verbonden met RzMon ✔`.

**Makkelijker:** voeg deze GitHub-repository toe als add-on-repository: *Add-on winkel* → ⋮ → *Repositories* → `https://github.com/rembrand20/rzmon`. De repository moet daarvoor openbaar zijn. Updates van de agent komen dan via de add-on winkel binnen.
De add-on heeft alleen leesrechten: Supervisor-rol `default`, plus host-netwerk voor de poortlijst. Meer uitleg staat in `ha-addon/rzmon_agent/DOCS.md`; die zie je in HA ook op het tabblad *Documentatie*.

> `ha-addon/rzmon_agent/rzmon-agent.sh` is een kopie van `agent/rzmon-agent.sh`. Een add-on moet al zijn bestanden in de eigen map hebben. Pas je de agent aan? Kopieer hem dan opnieuw en verhoog `version` in `config.yaml`, zodat HA de update ziet.

## Versie en upgraden
De huidige versie staat in [`VERSION`](VERSION) en in de topbalk van RzMon. Wat er per versie veranderd is, lees je in [`CHANGELOG.md`](CHANGELOG.md).

Upgraden gaat zo:
1. Zet de nieuwe bestanden eroverheen (`git pull`, of opnieuw `install-rocky.sh`), behalve `config.php`.
2. De **database wordt automatisch bijgewerkt** bij de eerste cron-run of het eerste bezoek aan het dashboard. Handmatig kan ook: `sudo -u www-data php install/install.php --upgrade`, of *Setup → Tabellen bijwerken*.
3. Werk de agents bij met `sudo rzmon-agent-setup --update`. Een verouderde agent herken je aan **⬆** op de servertegel.
4. Vanaf 1.17.0: wil je het databasewachtwoord met de knop in *Instellingen → Database* kunnen vernieuwen, installeer dan één keer de hulpdienst met `sudo bash /var/www/rzmon/install/install-helper.sh` (opnieuw `install-rocky.sh` draaien doet dat ook).

Ontwikkelen: verhoog de versie met `tools/bump-version.sh patch|minor|major` en controleer met `tools/check-version.sh`.

> Kom je van de versie die nog *ServerMonitor* heette? Laat je bestaande `config.php` (met de oude databasenaam) gewoon staan. De agent heet nu `rzmon-agent.sh`; de oude `sm-agent.sh` blijft werken, maar vervang hem bij gelegenheid.

## REST API

Read-only JSON API om statussen en meetwaarden uit te lezen, bv. voor Home Assistant, Grafana of eigen scripts.

1. Webinterface → *Instellingen* → *API-sleutels* → naam invullen → **Sleutel maken** (wordt maar één keer getoond).
2. Stuur de sleutel mee als header `Authorization: Bearer rzm_…` (of `X-API-Key: rzm_…`).

| Endpoint | Beschrijving |
|---|---|
| `GET /v1.php/status` | Totaalstatus, tellers, lijst met problemen en status per server |
| `GET /v1.php/hosts` | Alle servers met hun checks |
| `GET /v1.php/hosts/{id}` | Eén server |
| `GET /v1.php/checks` | Alle checks — filters: `?status=2`, `?type=disk`, `?host_id=1` |
| `GET /v1.php/checks/{id}` | Eén check |
| `GET /v1.php/checks/{id}/history?hours=24` | Meetwaarden (max 720 uur) |
| `GET /v1.php/alerts?limit=50` | Laatste meldingen |

Status-codes: `0` OK · `1` WARNING · `2` CRITICAL · `3` UNKNOWN. Tijden in ISO 8601.
Werkt `/v1.php/status` niet op je webserver (PATH_INFO uit)? Gebruik dan `/v1.php?r=status`.

```bash
curl -H "Authorization: Bearer rzm_xxx" https://rzmon.example.lan/v1.php/status
```

Voorbeeld van een check:
```json
{ "id": 3, "host": "web01", "name": "CPU", "type": "cpu", "status": 0, "status_text": "OK",
  "value": 12.4, "unit": "%", "message": "CPU 12.4% · load 0.31 0.25 0.20 (4 cores)",
  "warn_threshold": 85, "crit_threshold": 95, "last_checked": "2026-10-19T15:56:59+02:00" }
```

### Home Assistant
`configuration.yaml` (sleutel in `secrets.yaml` als `rzmon_auth: "Bearer rzm_xxx"`):

```yaml
rest:
  - resource: https://rzmon.example.lan/v1.php/status
    headers:
      Authorization: !secret rzmon_auth
    scan_interval: 60
    sensor:
      - name: "RzMon status"
        value_template: "{{ value_json.status_text }}"
        json_attributes: [counts, problems]
      - name: "RzMon problemen"
        value_template: "{{ value_json.counts.warning + value_json.counts.critical }}"
    binary_sensor:
      - name: "RzMon alles OK"
        value_template: "{{ value_json.status == 0 }}"

  # Losse meetwaarde, bv. CPU van check 3 (id staat in de URL van /checks)
  - resource: https://rzmon.example.lan/v1.php/checks/3
    headers:
      Authorization: !secret rzmon_auth
    scan_interval: 60
    sensor:
      - name: "Web01 CPU"
        value_template: "{{ value_json.value }}"
        unit_of_measurement: "%"
        state_class: measurement
```

## Handig
- Handmatig alle checks draaien: `php cron/run.php --force`
- Historie wordt automatisch opgeschoond (instelbaar, standaard 30 dagen)
- Mappenstructuur: `public/` (web), `src/` (logica), `cron/`, `agent/`, `install/`
