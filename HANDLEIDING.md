# 📡 RzMon – Handleiding

RzMon is een zelf-gehoste monitoringtool voor je servers, netwerkapparaten en Home Assistant. Hij controleert elke minuut of alles draait en stuurt een **Pushover**-bericht naar je telefoon zodra een drempel wordt overschreden.

Je kunt hem draaien op elke Linux-machine met PHP 8 en MariaDB: een VM, een Raspberry Pi of een NAS met Docker/LXC.

---

## Inhoud

1. [Wat is RzMon?](#1-wat-is-rzmon)
2. [Alle features](#2-alle-features)
3. [Hoe werkt het?](#3-hoe-werkt-het)
4. [Benodigdheden](#4-benodigdheden)
5. [Installatie stap voor stap](#5-installatie-stap-voor-stap)
6. [Eerste configuratie](#6-eerste-configuratie)
7. [Servers en checks toevoegen](#7-servers-en-checks-toevoegen)
8. [Agent op Linux-servers](#8-agent-op-linux-servers)
9. [Agent op Home Assistant OS](#9-agent-op-home-assistant-os)
10. [Toegang: inloggen zonder wachtwoord op je LAN](#10-toegang-inloggen-zonder-wachtwoord-op-je-lan)
11. [REST API en Home Assistant-integratie](#11-rest-api-en-home-assistant-integratie)
12. [Upgraden](#12-upgraden)
13. [Back-up en herstel](#13-back-up-en-herstel)
14. [Beveiligingschecklist](#14-beveiligingschecklist)
15. [Problemen oplossen](#15-problemen-oplossen)
16. [Naslag](#16-naslag)

---

## 1. Wat is RzMon?

RzMon bestaat uit vier onderdelen:

| Onderdeel | Wat het doet | Waar |
|---|---|---|
| **Webapplicatie** | Dashboard, instellingen, setup en de interne API | `public/`, `src/` |
| **Cron-runner** | Voert elke minuut de checks uit en verstuurt meldingen | `cron/run.php` |
| **Linux-agent** | Bash-script dat CPU, RAM, disks, services en poorten van een remote server doorstuurt | `agent/rzmon-agent.sh` |
| **Home Assistant-add-on** | Dezelfde agent, verpakt als add-on voor Home Assistant OS / Supervised | `ha-addon/rzmon_agent/` |

---

## 2. Alle features

### Monitoring
| Check | Controleert | Drempel (warning / critical) | Werkt op |
|---|---|---|---|
| **Ping** | Reageert de server? 3 pings | Gemiddelde ms. Pakketverlies = minstens WARNING, 100% verlies = CRITICAL | Alle servers |
| **TCP poort** | Staat een dienst open, zoals SSH, MariaDB of MQTT? | Verbindingstijd in ms. Dicht = CRITICAL | Alle servers |
| **HTTP(S)** | Website of API bereikbaar, statuscode < 400 | Responstijd in ms. Statuscode ≥ 400 = CRITICAL. SSL-certificaat < 14 dagen geldig = WARNING, < 3 dagen = CRITICAL | Alle servers |
| **Diskruimte** | Percentage gebruikt per mount | % gebruikt | Lokaal + agent |
| **Service** | Is een systemd-service `active`? | Niet actief = CRITICAL | Lokaal + agent |
| **CPU** | CPU-gebruik over 2 seconden + load average | % gebruik | Lokaal + agent |
| **RAM** | Geheugengebruik (cache telt niet mee) + swap | % gebruikt | Lokaal + agent |
| **Home Assistant** | Core, Supervisor, elke add-on en beschikbare updates | Niet actief = CRITICAL, update beschikbaar = WARNING | HA-add-on |

### Drie soorten servers
- **Lokaal**: de RzMon-server zelf. Alles wordt direct uitgelezen.
- **Remote met agent**: een Linux-server of Home Assistant die zelf gegevens doorstuurt. Stopt de agent met rapporteren, dan worden de checks na 5 minuten CRITICAL (instelbaar).
- **Remote (alleen netwerk)**: routers, NAS, switches, printers en websites. Daarvoor zijn ping, TCP en HTTP beschikbaar.

### Meldingen via Pushover en Home Assistant
- **Twee kanalen:** Pushover op je telefoon, en een **webhook naar Home Assistant** om automatiseringen te starten (lamp rood, TTS, notificatie via de HA-app).
- **Melding pas na N fouten** achter elkaar (standaard 2), zodat je geen valse alarmen krijgt.
- **Nieuwe melding bij escalatie**, bijvoorbeeld van WARNING naar CRITICAL.
- **Herhaalmelding** elke X minuten zolang het probleem blijft (standaard 60, 0 = uit).
- **Herstelmelding** ✅ als het weer goed gaat, met lage prioriteit dus zonder geluid.
- **Prioriteit en geluid instelbaar**, apart voor critical en warning, inclusief *Emergency* (blijft herhalen tot je hem bevestigt).
- **Testknoppen** voor beide kanalen, en een **meldingenlog** dat per melding toont via welk kanaal hij is verstuurd (📲 / 🏠).

### Onderhoud en afhankelijkheden
- **🔧 Onderhoudsmodus:** voor alles, per server of per check. Kies een duur (15 min tot een week) of een eindtijd, met een optionele reden. Checks blijven draaien, maar er gaan geen meldingen uit. Is er na afloop nog iets mis, dan krijg je alsnog een melding.
- **🔗 Afhankelijkheden:** geef aan dat een server bereikbaar is via een andere, zoals een router, switch, VPN of hypervisor. Valt die uit, dan krijg je **één** melding ("…meldingen van 5 afhankelijke servers worden onderdrukt") in plaats van een stortvloed. Komt hij terug en ligt een server erachter nog plat, dan krijg je die melding alsnog.

### Dashboard (HTML5)
- **Overzicht:** tellers voor OK, Warning, Critical en Onbekend, en een kaart per server met de status van elke check.
- **Visueel:** balkjes voor disk, CPU en RAM met de drempels als streepjes. Servers met problemen staan bovenaan.
- **Zoeken:** zoekveld en een filter **Alleen problemen**.
- **Grafiek per check:** over 6 uur, 24 uur, 7 dagen of 30 dagen, met beschikbaarheidspercentage.
- **Bijwerken:** de pagina ververst zichzelf elke 20 seconden. Met **↻ Alles nu checken** draai je direct alle checks.
- **Weergave:** donker en licht thema, werkt ook op je telefoon.

### Poortscan
- **Scannen** naar open TCP-poorten: bekende poorten, poort 1-1024, of een eigen lijst zoals `22,80,8000-8100` (maximaal 4096 poorten).
- **Snel:** 1024 poorten zijn binnen een seconde gescand, omdat de scan parallel loopt.
- **＋ Check** maakt van een open poort direct een bewaakte TCP-check.
- **Agent-servers:** daar zie je ook welke poorten luisteren, met procesnaam. Dat werkt ook voor poorten achter een firewall.

### Toegang en beveiliging
- **Login:** inloggen met gebruikersnaam en wachtwoord (bcrypt). Pogingen worden afgeremd tegen brute force.
- **Geen login op je lokale netwerk** (optioneel). Van buitenaf blijft de login nodig. Ga je met je telefoon van wifi naar 4G, dan word je automatisch uitgelogd.
- **Reverse proxy:** ondersteuning voor Nginx Proxy Manager, Traefik en Cloudflare Tunnel, waarbij het echte bezoekers-IP alleen wordt vertrouwd van proxy's die je zelf instelt.
- **Overige beveiliging:** CSRF-bescherming, HttpOnly/SameSite-cookies, een eigen token per agent en API-sleutels die alleen als hash worden opgeslagen.

### Setup-pagina
- **Systeemcheck:** PHP, extensies, ping, systemctl, schrijfrechten, database en cron.
- **Database instellen:** verbinding testen, en optioneel de database en gebruiker laten aanmaken.
- **Tabellen** installeren en bijwerken.
- **Instellingen:** tijdzone, basis-URL, pad naar ping en PHP-instellingen (`memory_limit`, `max_execution_time`, `display_errors`).
- **Eerste beheerder** aanmaken en een kant-en-klare cron-regel kopiëren.

### REST API
- **Alleen lezen** (JSON), met API-sleutels die je in de webinterface beheert.
- **Endpoints** voor de status, servers, checks, historie en meldingen.
- **Home Assistant:** voorbeeldconfiguratie voor sensoren in Home Assistant (en de webhook voor meldingen).

---

## 3. Hoe werkt het?

```
                    ┌──────────────────────────── RzMon-server ───────────────────────────┐
                    │                                                                      │
  Browser ─────────▶│  Webserver (Apache/Nginx) ──▶ PHP ──▶ MariaDB                         │
  Home Assistant ──▶│      /index.php  /setup.php  /api.php  /v1.php (REST API)            │
                    │                                   ▲                                  │
                    │  cron (elke minuut) ──▶ cron/run.php ──┬──▶ ping / TCP / HTTP ──────────┼──▶ servers, routers, sites
                    │                                        └──▶ Pushover API ─────────────┼──▶ 📱 jouw telefoon
                    └───────────────────────────────────▲──────────────────────────────────┘
                                                        │ HTTPS POST elke minuut (token)
                         ┌──────────────────────────────┴───────────────┐
                         │ Linux-server met rzmon-agent.sh (cron)        │
                         │ Home Assistant met add-on "RzMon Agent"       │
                         └──────────────────────────────────────────────┘
```

- **Netwerkchecks** (ping, TCP, HTTP) voert de RzMon-server zelf uit.
- **Agents** sturen hun gegevens zelf naar RzMon. Je hoeft dus geen poort te openen op de remote server; alleen RzMon moet bereikbaar zijn vanaf die server.
- **Statussen:** elke check heeft er een: `0` OK, `1` WARNING, `2` CRITICAL of `3` ONBEKEND. De kleur van een server is die van zijn slechtste check.

---

## 4. Benodigdheden

| | Minimaal | Aanbevolen |
|---|---|---|
| Besturingssysteem | Linux met systemd | Debian 12/13, Ubuntu 22.04/24.04 of [Rocky Linux 10](INSTALL-ROCKY-LINUX-10.md) |
| PHP | 8.1 | 8.2 of nieuwer |
| PHP-extensies | `pdo_mysql`, `curl`, `mbstring` | idem |
| Database | MariaDB 10.3 of MySQL 8 | MariaDB 10.11+ |
| Webserver | Apache 2.4 of Nginx + PHP-FPM | idem |
| Overig | `ping` (iputils-ping), `cron` | |
| Hardware | 1 vCPU, 512 MB RAM | Raspberry Pi 4 of een kleine VM is ruim voldoende |

PHP-functies `exec()` en `shell_exec()` moeten toegestaan zijn; die zijn nodig voor ping en service-checks. Op Debian en Ubuntu is dat standaard zo.

---

## 5. Installatie stap voor stap

> 🐧 Dit hoofdstuk is voor **Debian en Ubuntu**. Gebruik je **Rocky Linux 10**, RHEL 10 of AlmaLinux 10? Volg dan [INSTALL-ROCKY-LINUX-10.md](INSTALL-ROCKY-LINUX-10.md), of gebruik het installatiescript `install/install-rocky.sh`, dat alles in één keer doet. Daarin staan ook SELinux en firewalld. Ga daarna verder met hoofdstuk 6 hieronder.

> In de voorbeelden staat RzMon in `/var/www/rzmon` en gebruikt de webserver de gebruiker `www-data` (Debian/Ubuntu). Pas dat aan als jouw situatie anders is.

### 5.1 Pakketten installeren

**Met Apache** (makkelijkst):
```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php mariadb-server \
     php php-mysql php-curl php-mbstring iputils-ping unzip
```

**Of met Nginx + PHP-FPM**:
```bash
sudo apt update
sudo apt install nginx php-fpm mariadb-server \
     php-mysql php-curl php-mbstring iputils-ping unzip
```

Controleer de PHP-versie (moet 8.1 of hoger zijn):
```bash
php -v
```

### 5.2 Bestanden plaatsen

```bash
cd /tmp
unzip rzmon.zip                       # de download
sudo mv rzmon /var/www/rzmon
sudo chown -R root:www-data /var/www/rzmon
sudo find /var/www/rzmon -type d -exec chmod 750 {} \;
sudo find /var/www/rzmon -type f -exec chmod 640 {} \;
sudo chmod 755 /var/www/rzmon/agent/rzmon-agent.sh

# Tijdelijk: laat de setup-pagina config.php aanmaken
sudo chown www-data /var/www/rzmon
```

### 5.3 Database aanmaken

Op Debian en Ubuntu logt de MariaDB-root in via `unix_socket`, dus alleen vanaf de terminal met `sudo`. Maak daarom de database en gebruiker het makkelijkst zo aan:

```bash
sudo mysql <<'SQL'
CREATE DATABASE rzmon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rzmon'@'localhost' IDENTIFIED BY 'KIES_EEN_STERK_WACHTWOORD';
GRANT ALL PRIVILEGES ON rzmon.* TO 'rzmon'@'localhost';
FLUSH PRIVILEGES;
SQL
```

> 💡 Heb je een MariaDB-beheerdersaccount **met** wachtwoord? Dan kan de setup-pagina de database en gebruiker ook voor je aanmaken: zet daar *Database en gebruiker voor mij aanmaken* aan.

Gebruik in de setup als host `localhost` of `127.0.0.1`. Allebei werken.

### 5.4 Webserver instellen

> ⚠️ **Belangrijk:** de document root moet de map **`public/`** zijn. Dan zijn `config.php` (met je databasewachtwoord) en de broncode nooit via het web op te vragen.

#### Apache
Maak `/etc/apache2/sites-available/rzmon.conf`:
```apache
<VirtualHost *:80>
    ServerName rzmon.lan
    DocumentRoot /var/www/rzmon/public

    <Directory /var/www/rzmon/public>
        Require all granted
        # Nodig met PHP-FPM: geeft "Authorization: Bearer …" (REST API) door aan PHP
        CGIPassAuth On
        AllowOverride None
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/rzmon_error.log
    CustomLog ${APACHE_LOG_DIR}/rzmon_access.log combined
</VirtualHost>
```
```bash
sudo a2ensite rzmon
sudo a2dissite 000-default      # optioneel: standaardsite uit
sudo systemctl reload apache2
```

#### Nginx
Maak `/etc/nginx/sites-available/rzmon`. Controleer de PHP-versie in de socketnaam met `ls /run/php/`:
```nginx
server {
    listen 80;
    server_name rzmon.lan;
    root /var/www/rzmon/public;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    # (/|$) is nodig zodat de REST API op /v1.php/status werkt
    location ~ \.php(/|$) {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/rzmon /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

#### HTTPS (aanbevolen als RzMon van buitenaf bereikbaar is)
```bash
sudo apt install certbot python3-certbot-apache   # of python3-certbot-nginx
sudo certbot --apache -d rzmon.jouwdomein.nl      # of --nginx
```
Voor alleen thuis gebruik is HTTP op je LAN prima. Wil je er van buitenaf bij, gebruik dan HTTPS of een VPN zoals WireGuard of Tailscale.

### 5.5 Setup-wizard doorlopen

Open vanaf een computer **op je lokale netwerk** `http://<ip-van-de-server>/`. Je komt automatisch op de setup-pagina.

> 🔒 Zolang de installatie niet klaar is, werkt de setup alleen vanaf een privé-adres (192.168.x.x, 10.x.x.x, 172.16-31.x.x of localhost).

1. **Systeemcheck.** Alles hoort groen te zijn. Oranje is een waarschuwing. "Cron draait" wordt pas groen na stap 5.6.
2. **Database.** Vul in:
   - Host `127.0.0.1`, poort `3306`
   - Database `rzmon`, gebruiker `rzmon`, en het wachtwoord uit stap 5.3

   Klik op **🔌 Verbinding testen** en daarna op **Opslaan & tabellen installeren**.
3. **Applicatie & PHP.**
   - **Tijdzone:** `Europe/Amsterdam`.
   - **Basis-URL:** bijvoorbeeld `http://192.168.1.10`. Die wordt gebruikt in de installatie-instructies voor agents.
   - **Pad naar ping:** meestal `/bin/ping` of `/usr/bin/ping`. Controleer met `command -v ping`.
   - **memory_limit en max_execution_time:** leeg laten, tenzij je een reden hebt.
   - **display_errors:** alleen aanzetten om fouten te zoeken.
4. **Beheerder aanmaken.** Kies een gebruikersnaam en een wachtwoord van minstens 8 tekens. Daarna ben je direct ingelogd en sta je op het dashboard.
5. **Cron.** Kopieer de regel van deze stap; zie 5.6.

Zet de schrijfrechten daarna weer terug:
```bash
sudo chown root /var/www/rzmon
```
Wil je later via *Setup* iets aan de database- of PHP-instellingen veranderen, dan toont de setup de nieuwe inhoud van `config.php`, die je zelf plaatst. Of je geeft tijdelijk weer schrijfrechten.

> **Kan de setup `config.php` niet schrijven?** Dan zie je de inhoud in beeld. Maak het bestand zelf aan met `sudo nano /var/www/rzmon/config.php`, plak de inhoud, sla op en ververs de pagina.

### 5.6 Cron instellen

De checks draaien elke minuut. Gebruik de regel van de setup-pagina, of:
```bash
echo '* * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
```
Testen:
```bash
sudo -u www-data php /var/www/rzmon/cron/run.php
# → 2026-10-19T16:08:36+02:00 4 check(s) uitgevoerd
```

### 5.7 Controleren
- Op het **dashboard** staat je RzMon-server al met vier checks: Disk /, MariaDB, CPU en RAM.
- Bij **Setup → Systeemcheck** staat "Cron draait" na een minuut op groen.

### Alternatief: installatie volledig via de terminal
```bash
cd /var/www/rzmon
sudo cp config.sample.php config.php
sudo nano config.php                       # databasegegevens, tijdzone, base_url
sudo chown root:www-data config.php && sudo chmod 640 config.php
sudo -u www-data php install/install.php admin   # tabellen + beheerder (vraagt wachtwoord)
echo '* * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
```

---

## 6. Eerste configuratie

### 6.1 Pushover koppelen
1. Installeer de **Pushover**-app (iOS of Android). De app is na de proefperiode een eenmalige aankoop.
2. Log in op [pushover.net](https://pushover.net). Rechtsboven staat je **User Key**.
3. Klik op **Create an Application/API Token**, geef het de naam "RzMon" en kopieer het **API Token**.
4. In RzMon: **Instellingen → Pushover**:
   - zet *Pushover meldingen aan* aan;
   - vul de user key en het API-token in;
   - klik op **📲 Testbericht sturen** en controleer je telefoon;
   - klik op **Instellingen opslaan**.

| Instelling | Standaard | Uitleg |
|---|---|---|
| Prioriteit critical | Hoog (1) | Hoog = geluid, ook tijdens stille uren. Emergency (2) blijft elke minuut herhalen, maximaal een uur lang, tot je hem bevestigt |
| Prioriteit warning | Normaal (0) | |
| Geluid | (standaard) | Bijvoorbeeld `siren`, `alien`, `pushover` of `none` |

### 6.2 Alarmgedrag
| Instelling | Standaard | Uitleg |
|---|---|---|
| Alarm na aantal opeenvolgende fouten | 2 | Hoe vaak achter elkaar een check moet falen voor er een melding komt. Zet hem op 3 of hoger als je last hebt van korte CPU-pieken |
| Herhaal melding elke … minuten | 60 | Zolang het probleem blijft. 0 = nooit herhalen |
| Ook melden bij WARNING | aan | Uit = alleen meldingen bij CRITICAL |
| Melden bij herstel | aan | ✅-bericht met lage prioriteit |
| Agent offline na … minuten | 5 | Na zoveel minuten zonder rapport worden de agent-checks CRITICAL |
| Historie bewaren (dagen) | 30 | Meetwaarden. Meldingen worden 3× zo lang bewaard |

---

### 6.3 Home Assistant-webhook
RzMon kan elke melding ook naar Home Assistant sturen. Daar start je er een automatisering mee: een lamp rood kleuren, een TTS-melding, of een notificatie via de HA-app als extra kanaal naast Pushover.

**1. Automatisering in Home Assistant.** Ga naar *Instellingen → Automatiseringen → Nieuwe automatisering → ⋮ → Bewerken in YAML* en plak:
```yaml
alias: RzMon meldingen
description: Meldingen van RzMon via webhook
triggers:
  - trigger: webhook
    webhook_id: rzmon-kies-hier-een-lang-geheim-id   # werkt als wachtwoord
    allowed_methods: [POST]
    local_only: true
actions:
  # Altijd: notificatie via de Home Assistant-app
  - action: notify.mobile_app_jouw_telefoon
    data:
      title: "{{ trigger.json.title }}"
      message: "{{ trigger.json.message }}"
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
Vervang `notify.mobile_app_jouw_telefoon` en `light.bureau` door je eigen entiteiten. Het `webhook_id` werkt als wachtwoord: maak het lang en onvoorspelbaar.

**2. In RzMon:** ga naar *Instellingen → Home Assistant-webhook*:
- zet *Webhook aan*;
- vul als URL in: `http://<home-assistant>:8123/api/webhook/rzmon-kies-hier-een-lang-geheim-id`;
- klik op **🏠 Testbericht naar Home Assistant**;
- klik op **Instellingen opslaan**.

| Instelling | Uitleg |
|---|---|
| Versturen bij | *Alle meldingen*, of *Alleen critical en herstel* |
| SSL-certificaat controleren | Uitzetten als HA via HTTPS met een self-signed certificaat draait |

**Inhoud van het bericht** (`trigger.json` in Home Assistant):
```jsonc
{
  "source": "rzmon",
  "event": "problem",              // problem | reminder | recovery | test
  "status": 2, "status_text": "CRITICAL",
  "previous_status": 0, "previous_status_text": "OK",
  "host": "NAS", "host_id": 2,
  "check": "SMB", "check_id": 5, "type": "tcp", "target": "445",
  "value": null, "unit": "ms",
  "title": "🔴 CRITICAL: NAS – SMB",
  "message": "Poort 445 onbereikbaar: Connection refused",
  "time": "2026-10-19T18:02:05+02:00",
  "url": "http://192.168.1.10"
}
```

> `local_only: true` betekent dat HA de webhook alleen accepteert vanaf je eigen netwerk. Draait RzMon ergens anders, bijvoorbeeld in de cloud, zet dit dan op `false` en gebruik een HTTPS-URL.

---

## 7. Servers en checks toevoegen

### 7.1 Server toevoegen
Klik op **＋ Server** en vul in:
- **Naam**, bijvoorbeeld "NAS" of "Webserver".
- **Hostname of IP-adres**.
- **Type**: Lokaal, Remote met agent of Remote (alleen netwerk).

Laat het vinkje *Direct een ping-check toevoegen* aan staan.

### 7.2 Check toevoegen
Klik op een serverkaart op **＋**. Per type:

| Type | Doel / veld | Voorbeeld drempels |
|---|---|---|
| Ping | – | Warning 100 ms, critical 500 ms |
| TCP poort | Poortnummer, bv. `22` | 500 / 2000 ms |
| HTTP(S) | Volledige URL (`https://site.nl/`) of een pad (`/status`, dan wordt het adres van de server gebruikt) | 1000 / 3000 ms |
| Diskruimte | Mount, bv. `/` of `/data`. Bij agent-servers kies je uit een lijst | 80 / 90 % |
| Service | systemd-naam, bv. `nginx`. Bij een HA-agent: `homeassistant`, `supervisor`, `updates` of `addon:<slug>` | – |
| CPU | – | 85 / 95 % |
| RAM | – | 85 / 95 % |

- **Interval:** minimaal 30 seconden. Omdat cron elke minuut draait, is **1 minuut** in de praktijk het kortste interval.
- **Na opslaan** wordt de check direct uitgevoerd.
- **Knoppen per check:** ↻ = nu checken, 📈 = grafiek, ✎ = bewerken of verwijderen.

### 7.3 Poortscan
Klik op een serverkaart op **🔍**:
1. Kies een bereik en klik op **Scannen**.
2. Klik bij elke dienst die je wilt bewaken op **＋ Check**.
3. Bij agent-servers staat eronder ook de lijst met luisterende poorten volgens de agent.

> Scan alleen apparaten van jezelf. Op bedrijfs- of hostingnetwerken kan een poortscan als aanval worden gezien.

### 7.4 Onderhoud 🔧
Ga je iets updaten of herstarten? Zet dan onderhoud aan, zodat je geen meldingen krijgt voor iets wat je zelf doet.

| Waar | Knop |
|---|---|
| **Alles** | **🔧 Onderhoud** naast *Alles nu checken* |
| **Eén server** | 🔧 op de serverkaart |
| **Eén check** | 🔧 bij de check |

Kies een duur (15 min, 30 min, 1 uur … 1 week), of een vast eindtijdstip, en vul eventueel een reden in.

- **Tijdens het onderhoud** blijven checks draaien en zie je de status gewoon. Er gaan geen Pushover- of webhookmeldingen uit.
- **Herkenbaar:** servers en checks in onderhoud zijn paars gestreept, met een label *🔧 tot 14:30*. Bij globaal onderhoud staat bovenaan een balk.
- **Na afloop** stopt het onderhoud vanzelf. Is er dan nog iets mis, dan komt de melding bij de volgende check, en is het weer goed, dan krijg je een herstelmelding. Zo mis je nooit iets dat na je onderhoud kapot is gebleven.
- **Eerder stoppen:** klik op 🔧 → **Onderhoud beëindigen**, of op **Beëindigen** in de balk.
- **REST API:** checks in onderhoud tellen niet mee voor de totaalstatus. De sensor *RzMon alles OK* springt dus niet op rood door gepland onderhoud. `counts.maintenance` telt ze apart.

### 7.5 Afhankelijkheden 🔗
Valt je router uit, dan zijn alle servers erachter ook onbereikbaar. Zonder afhankelijkheden krijg je dan tientallen meldingen. Zo stel je het in:

1. Zorg dat de router (of switch, VPN-server, hypervisor) een **ping-check** heeft.
2. Bewerk elke server erachter (✎) en kies bij **Afhankelijk van** de router.

Je kunt meerdere lagen maken, bijvoorbeeld *Internet → Router → Switch → NAS*. Kringen, waarbij A van B afhangt en B van A, worden geweigerd.

**Wat er gebeurt:**
| Situatie | Melding |
|---|---|
| Router valt uit | 🔴 *Router – Ping*, met *"Meldingen van 5 afhankelijke server(s) worden onderdrukt."* |
| Servers erachter worden onbereikbaar | Geen melding; de checks tonen **🔕 onderdrukt** |
| Router komt terug, NAS is ook weer OK | ✅ Herstel voor de router |
| Router komt terug, NAS blijft plat | ✅ Herstel voor de router **en** 🔴 voor de NAS |

**Wanneer telt een server als onbereikbaar?**
- **Met ping-checks:** als al zijn ping-checks CRITICAL zijn.
- **Zonder ping:** als al zijn TCP- en HTTP-checks CRITICAL zijn.
- **Zonder netwerkchecks** telt een server nooit als onbereikbaar.

Bovenliggende servers worden in elke ronde als eerste gecontroleerd, zodat de onderdrukking meteen klopt.

---

## 8. Agent op Linux-servers

Voor diskruimte, CPU, RAM, services en luisterende poorten van **andere** Linux-servers.

1. Voeg in RzMon een server toe met type **Remote met agent**.
2. Klik op de kaart op **Agent installeren**, tabblad **🐧 Linux-server**. Daar staan de commando's met jouw token al ingevuld.
3. Kopieer `agent/rzmon-agent.sh` naar de server, bijvoorbeeld met `scp agent/rzmon-agent.sh gebruiker@server:/tmp/`, en voer daar uit:

```bash
sudo install -m 755 /tmp/rzmon-agent.sh /usr/local/bin/rzmon-agent.sh

sudo tee /etc/default/rzmon-agent >/dev/null <<'EOF'
MONITOR_URL="http://192.168.1.10"
TOKEN="<token uit RzMon>"
SERVICES="ssh cron nginx mariadb docker"
# INSECURE=1   # alleen bij een self-signed certificaat op RzMon
EOF
sudo chmod 600 /etc/default/rzmon-agent

echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon-agent

# Test — moet {"ok":true} geven:
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'
```

4. Voeg in RzMon checks toe. Mounts en services kies je uit de lijst met wat de agent gemeld heeft.

- **Vereisten:** `bash`, `curl`, `df`, `systemctl` en `ss`. Die staan standaard op Debian, Ubuntu, Raspberry Pi OS en Proxmox.
- **Gegevens:** CPU, RAM, alle echte mounts, de services in `SERVICES` en de luisterende TCP-poorten.
- **Root:** de agent draait als root om procesnamen bij poorten te zien. Als gewone gebruiker werkt alles ook, maar dan zonder procesnamen.
- **Token gelekt?** Klik op *Agent installeren* → **↻ Nieuw token** en zet het nieuwe token in `/etc/default/rzmon-agent`.

---

## 9. Agent op Home Assistant OS

Op Home Assistant OS kun je geen scripts of cron op het systeem zelf zetten. De agent draait daarom als **add-on**.

### Wat wordt er bewaakt?
| Check in RzMon | Betekenis |
|---|---|
| CPU / RAM | Van de Home Assistant-host |
| Diskruimte `/data` | Data-partitie: config, database, back-ups en media |
| Service `homeassistant` | Draait Home Assistant Core en reageert de API? |
| Service `supervisor` | Is de Supervisor *healthy*? |
| Service `addon:<slug>` | Draait een add-on? Bijvoorbeeld `addon:core_mosquitto`, `addon:45df7312_zigbee2mqtt` of `addon:a0d7b954_nodered` |
| Service `updates` | WARNING met de lijst van beschikbare updates (Core, Supervisor, OS, add-ons) |
| Poortscan | Luisterende poorten van de host |

### Installeren
1. **RzMon:** **＋ Server** → naam "Home Assistant", adres = IP van HA, type **Remote met agent**. Kopieer het token via *Agent installeren*, tabblad **🏠 Home Assistant OS**.
2. **Add-on toevoegen aan Home Assistant.** Kies één van deze manieren:
   - **Via GitHub (aanbevolen):** *Instellingen → Add-ons → Add-on winkel* → ⋮ → **Repositories** → voeg `https://github.com/rembrand20/rzmon` toe. De repository moet openbaar zijn. Updates van de add-on komen dan vanzelf binnen.
   - **Samba share**-add-on: open `\\homeassistant.local\addons` (Windows) of `smb://homeassistant.local/addons` (Mac) en kopieer daar de map **`rzmon_agent`** uit `ha-addon/` naartoe.
   - **Advanced SSH & Web Terminal**-add-on: `scp -r ha-addon/rzmon_agent root@homeassistant.local:/addons/`
3. **Home Assistant:** *Instellingen → Add-ons → Add-on winkel* → rechtsboven ⋮ → **Controleren op updates**. **RzMon Agent** staat nu onder *RzMon add-ons* (via GitHub) of onder *Lokale add-ons* (via Samba/SSH). Klik op **Installeren**; het bouwen duurt een minuut.
4. **Tabblad Configuratie:**
   ```yaml
   monitor_url: http://192.168.1.10
   token: <token uit RzMon>
   interval: 60
   verify_ssl: true
   ```
   Klik op **Opslaan** en dan op **Starten**. Controleer op het tabblad *Info* dat *Starten bij opstarten* aan staat.
5. **Logboek:** daar moet `Verbonden met RzMon ✔` staan.
6. **RzMon:** voeg checks toe met **＋**. Aanbevolen:
   - Service `homeassistant`, `supervisor` en `updates`
   - Service `addon:...` voor elke add-on die altijd moet draaien, zoals Mosquitto, Zigbee2MQTT of Z-Wave JS
   - Diskruimte `/data` (80/90%), CPU, RAM
   - Bij de server ook een **ping-check** en een **TCP-check op poort 8123**. Die werken los van de agent.

### Goed om te weten
- **Waarom een aparte monitor:** valt Home Assistant helemaal uit, dan stopt de agent ook. RzMon merkt dat binnen een paar minuten en stuurt een Pushover-bericht. Dat werkt dus ook als HA zelf geen meldingen meer kan sturen.
- **Rechten:** de add-on kan alleen lezen. Hij gebruikt de Supervisor-rol `default` en het host-netwerk, en kan niets veranderen aan je installatie.
- **Ondersteunde hardware:** `aarch64` (Raspberry Pi 4/5, Green, Yellow), `amd64` (NUC, mini-pc, VM) en `armv7`.
- **Foutmeldingen in het logboek:**
  - `403` → het token klopt niet, of de server staat in RzMon niet op type *Remote met agent*.
  - `Could not resolve` / `Failed to connect` → `monitor_url` klopt niet, of RzMon is niet bereikbaar.
  - `SSL` / `certificate` → zet `verify_ssl` uit bij een self-signed certificaat.
- **Nieuwe versie van de add-on uitbrengen:** verhoog `version` in `ha-addon/rzmon_agent/config.yaml` en push naar GitHub. Home Assistant toont dan een update.

---

## 10. Toegang: inloggen zonder wachtwoord op je LAN

**Instellingen → Toegang**

1. Controleer bovenaan het blok **Jouw IP-adres**. Daar staat of je huidige IP binnen je lokale netwerken valt.
2. Pas de lijst met **lokale netwerken** aan. Standaard staan alle privé-ranges erin. Strakker is je eigen subnet, bijvoorbeeld:
   ```
   192.168.1.0/24
   ```
3. Zet **Geen login nodig op het lokale netwerk** aan en klik op **Toegang opslaan**.

**Hoe het werkt:**
- **Op je LAN** ben je automatisch ingelogd als de eerste beheerder. Rechtsboven staat dan **🏠 Lokaal netwerk** en er is geen uitlogknop.
- **Van buitenaf** krijg je gewoon het loginscherm.
- **Wissel je van netwerk** met een open sessie, bijvoorbeeld van wifi naar 4G, dan word je bij het volgende verzoek uitgelogd.
- **REST API:** die vraagt altijd een API-sleutel, ook op het LAN.

### Achter een reverse proxy
Gebruik je **Nginx Proxy Manager**, **Traefik**, **Caddy**, **Cloudflare Tunnel** of de HA-add-on *NGINX SSL proxy*? Dan komt elk verzoek binnen met het IP van de proxy.

- Vul het IP van de proxy in bij **Reverse proxy IP's**. RzMon gebruikt dan het echte bezoekers-IP uit `X-Forwarded-For`.
- Zet het proxy-IP **nooit** bij *Lokale netwerken*. Anders komt iedereen die via de proxy binnenkomt, dus ook van internet, zonder wachtwoord binnen.
- **Testen:** open RzMon via 4G op je telefoon. Je moet een loginscherm krijgen.

> ⚠️ Sommige routers geven bij port-forwarding álle bezoekers het IP van de router. Test daarom altijd via 4G nadat je deze optie aanzet.

---

## 11. REST API en Home Assistant-integratie

### API-sleutel maken
**Instellingen → API-sleutels**: vul een naam in, bijvoorbeeld "Home Assistant", en klik op **＋ Sleutel maken**.

- De sleutel (`rzm_…`) wordt **één keer** getoond; kopieer hem meteen.
- Per sleutel zie je wanneer hij voor het laatst gebruikt is. Met 🗑 trek je een sleutel in.

### Gebruik
```bash
curl -H "Authorization: Bearer rzm_xxxxxxxx" http://192.168.1.10/v1.php/status
```
In plaats van de `Authorization`-header kun je ook `X-API-Key: rzm_…` gebruiken, of `?key=rzm_…` in de URL. Die laatste is minder veilig, omdat hij in logbestanden belandt.

| Endpoint | Resultaat |
|---|---|
| `GET /v1.php/status` | Totaalstatus, tellers, lijst met problemen, status per server |
| `GET /v1.php/hosts` | Alle servers met checks |
| `GET /v1.php/hosts/{id}` | Eén server |
| `GET /v1.php/checks` | Alle checks. Filters: `?status=2`, `?type=disk`, `?host_id=1` |
| `GET /v1.php/checks/{id}` | Eén check, met waarde, eenheid, drempels en bericht |
| `GET /v1.php/checks/{id}/history?hours=24` | Meetwaarden (maximaal 720 uur) |
| `GET /v1.php/alerts?limit=50` | Laatste meldingen (maximaal 500) |

- **Status:** `0` OK, `1` WARNING, `2` CRITICAL, `3` UNKNOWN. Checks in onderhoud tellen niet mee voor de totaalstatus; staat alles in onderhoud, dan is de status `0`.
- **Extra velden:** `in_maintenance` en `maintenance_until` bij servers en checks, `parent_id` bij servers, `suppressed_by` bij checks (id van de onbereikbare server erboven), `counts.maintenance` en `maintenance` (globaal onderhoud) in `/status`.
- **Tijden** in ISO 8601.
- **Werkt `/v1.php/status` niet** (bijvoorbeeld Nginx zonder PATH_INFO)? Gebruik dan `/v1.php?r=status`.
- **Check-id** vind je via `/v1.php/checks`.

### Home Assistant: sensoren
In `secrets.yaml`:
```yaml
rzmon_auth: "Bearer rzm_xxxxxxxx"
```
In `configuration.yaml`:
```yaml
rest:
  - resource: http://192.168.1.10/v1.php/status
    headers:
      Authorization: !secret rzmon_auth
    scan_interval: 60
    sensor:
      - name: "RzMon status"
        value_template: "{{ value_json.status_text }}"
        json_attributes: [counts, problems]
      - name: "RzMon problemen"
        value_template: "{{ value_json.counts.warning + value_json.counts.critical }}"
      - name: "RzMon in onderhoud"
        value_template: "{{ value_json.counts.maintenance }}"
    binary_sensor:
      - name: "RzMon alles OK"
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
Herstart Home Assistant. Ideeën:
- een lamp die rood wordt als `binary_sensor.rzmon_alles_ok` uit gaat;
- een dashboardkaart met de attribuut-lijst `problems`.

---

## 12. Upgraden

1. **Maak eerst een back-up** (hoofdstuk 13).
2. **Zet de nieuwe bestanden eroverheen.** Laat `config.php` staan:
   ```bash
   cd /tmp && unzip -o rzmon.zip
   sudo rsync -a --exclude config.php /tmp/rzmon/ /var/www/rzmon/
   sudo chown -R root:www-data /var/www/rzmon
   ```
3. **Werk de database bij.** Klik op **Setup → ↻ Tabellen bijwerken**, of:
   ```bash
   sudo -u www-data php /var/www/rzmon/install/install.php --upgrade
   ```
4. **Vervang de agents:**
   - Linux-servers: `/usr/local/bin/rzmon-agent.sh`.
   - Home Assistant: kopieer de map `rzmon_agent` opnieuw naar `addons`, ga naar de add-on winkel → ⋮ → *Controleren op updates* → *Bijwerken*.
5. **Ververs de browser** met Ctrl+F5.

> Kom je van de versie die nog *ServerMonitor* heette? Laat je `config.php` met de oude databasenaam staan. De agent heet nu `rzmon-agent.sh`; de oude `sm-agent.sh` blijft werken, maar vervang hem bij gelegenheid.

---

## 13. Back-up en herstel

Belangrijk zijn alleen de **database** en **`config.php`**.

```bash
# Back-up (bijvoorbeeld dagelijks via cron)
sudo mysqldump --single-transaction rzmon | gzip > /root/rzmon-$(date +%F).sql.gz
sudo cp /var/www/rzmon/config.php /root/rzmon-config.php

# Herstel
gunzip < /root/rzmon-2026-10-19.sql.gz | sudo mysql rzmon
sudo cp /root/rzmon-config.php /var/www/rzmon/config.php
```

---

## 14. Beveiligingschecklist

- [ ] De document root is `public/`. Controleer dat `http://<server>/config.php` een **404** geeft.
- [ ] De schrijfrechten op `/var/www/rzmon` zijn na de setup teruggezet (`sudo chown root /var/www/rzmon`).
- [ ] Je beheerder heeft een sterk wachtwoord.
- [ ] Bereikbaar van buitenaf? Dan alleen via **HTTPS** of een VPN.
- [ ] "Geen login op LAN" aan? Dan getest via 4G dat je een loginscherm krijgt.
- [ ] Ongebruikte API-sleutels zijn ingetrokken.
- [ ] De MariaDB-gebruiker `rzmon` mag alleen inloggen vanaf `localhost`.
- [ ] `display_errors` staat uit.

---

## 15. Problemen oplossen

| Probleem | Oplossing |
|---|---|
| **"Setup is alleen bereikbaar vanaf het lokale netwerk"** | Open de pagina vanaf een computer op je LAN, of via een SSH-tunnel: `ssh -L 8080:localhost:80 server` en dan `http://localhost:8080` |
| **Witte pagina / fout 500** | Bekijk `/var/log/apache2/rzmon_error.log` of `/var/log/nginx/error.log`. Of zet tijdelijk `display_errors` aan via Setup |
| **Wachtwoord vergeten** | `sudo -u www-data php /var/www/rzmon/install/install.php admin` zet een nieuw wachtwoord voor `admin` |
| **Dashboard stuurt door naar Setup** | De database is onbereikbaar. Controleer `sudo systemctl status mariadb` en de gegevens bij Setup → Database |
| **"Cron draait" blijft oranje** | Controleer `/etc/cron.d/rzmon`: het bestand moet van root zijn en mag geen punt in de naam hebben. Test handmatig met `sudo -u www-data php cron/run.php` |
| **Ping: "not found"** | `sudo apt install iputils-ping`, en zet het juiste pad bij Setup (`command -v ping`) |
| **Ping: "Operation not permitted"** | `echo 'net.ipv4.ping_group_range = 0 2147483647' \| sudo tee /etc/sysctl.d/99-ping.conf && sudo sysctl --system` |
| **Service-check: "System has not been booted with systemd"** | RzMon draait in een container zonder systemd. Gebruik dan TCP-checks, of een agent op de host |
| **HTTP-check: SSL-fout** | Het certificaat is verlopen of self-signed, of het systeem mist CA-certificaten (`sudo apt install ca-certificates`) |
| **Pushover-test mislukt** | Controleer de user key en het token. De server moet uitgaand `https://api.pushover.net` kunnen bereiken: test met `curl -I https://api.pushover.net` |
| **Agent: `403`** | Het token klopt niet, of de server staat niet op type *Remote met agent* |
| **Agent-checks: "Agent stil sinds …"** | Draait de cron op de agent-server? Kan die server RzMon bereiken? Test het commando uit hoofdstuk 8 |
| **Agent: "meldt service X niet"** | Voeg de service toe aan `SERVICES` in `/etc/default/rzmon-agent` |
| **HA-add-on niet zichtbaar in de winkel** | Staat de map als `/addons/rzmon_agent/config.yaml`? Doe ⋮ → *Controleren op updates* en ververs de browser |
| **REST API geeft 401 met een geldige sleutel (Apache + PHP-FPM)** | Zet `CGIPassAuth On` in de vhost (hoofdstuk 5.4), of gebruik de header `X-API-Key: rzm_…` |
| **`/v1.php/status` geeft 404 (Nginx)** | Gebruik `location ~ \.php(/|$)` (hoofdstuk 5.4), of `/v1.php?r=status` |
| **Poortscan vindt niets** | Een firewall blokkeert de scan. Bij een agent-server zie je de luisterende poorten wel in de tweede lijst |

---

## 16. Naslag

### Mappenstructuur
```
rzmon/
├── HANDLEIDING.md            ← dit document
├── README.md                 korte technische beschrijving
├── INSTALL-ROCKY-LINUX-10.md installatie op Rocky Linux / RHEL / AlmaLinux 10
├── config.sample.php         voorbeeldconfig (config.php maakt de setup aan)
├── public/                   ← document root van de webserver
│   ├── index.php             dashboard + login
│   ├── setup.php             setup-pagina
│   ├── api.php               interne API (webinterface + agents)
│   ├── v1.php                REST API (alleen lezen, API-sleutels)
│   └── assets/               app.js, setup.js, app.css
├── src/                      PHP-logica (checks, monitor, pushover, auth, poortscan, installer)
├── cron/run.php              elke minuut via cron
├── install/                  schema.sql, install.php (CLI), install-rocky.sh (installatiescript Rocky 10)
├── agent/rzmon-agent.sh      Linux-agent
├── repository.yaml           maakt van de GitHub-repo een HA add-on repository
└── ha-addon/
    └── rzmon_agent/          Home Assistant add-on: config.yaml, Dockerfile, run.sh, DOCS.md, vertalingen
```

### config.php
| Sleutel | Uitleg |
|---|---|
| `db.host` / `db.port` / `db.name` / `db.user` / `db.pass` | Databaseverbinding |
| `timezone` | Bijvoorbeeld `Europe/Amsterdam` |
| `ping_bin` | Pad naar ping |
| `base_url` | Adres van RzMon, voor de agent-instructies |
| `php.memory_limit` / `php.max_execution_time` / `php.display_errors` | PHP-waarden die RzMon zelf toepast. Leeg = waarde uit php.ini |

### Commando's
| Commando | Doel |
|---|---|
| `php cron/run.php` | Checks uitvoeren die aan de beurt zijn |
| `php cron/run.php --force` | Alle checks direct uitvoeren |
| `php install/install.php <naam>` | Tabellen installeren + gebruiker aanmaken of wachtwoord resetten |
| `php install/install.php --upgrade` | Alleen de tabellen bijwerken |

Voer ze uit als de webserver-gebruiker: `sudo -u www-data php …` vanuit `/var/www/rzmon`.
