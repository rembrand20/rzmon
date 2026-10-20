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
| **SSL-certificaat** | Hoe lang is het certificaat nog geldig? Werkt op elke TLS-poort | Dagen: warning **onder** 21, critical **onder** 7. Verlopen of ongeldig = CRITICAL | Alle servers |
| **Diskruimte** | Percentage gebruikt per mount, plus een voorspelling wanneer hij vol is | % gebruikt. Vol binnen 7 dagen (instelbaar) = WARNING | Lokaal + agent |
| **Service** | Is een systemd-service `active`? Op agent-servers ook: luistert hij op zijn poorten, antwoordt hij, herstarts en log-fouten (§8.7) | Niet actief of antwoordt niet = CRITICAL; ≥ 3 herstarts/uur of fouten in het log = WARNING | Lokaal + agent |
| **CPU** | CPU-gebruik over 2 seconden + load average | % gebruik | Lokaal + agent |
| **RAM** | Geheugengebruik (cache telt niet mee) + swap | % gebruikt | Lokaal + agent |
| **Home Assistant** | Core, Supervisor, elke add-on en beschikbare updates | Niet actief = CRITICAL, update beschikbaar = WARNING | HA-add-on |
| **Podman (algemeen)** | Draait podman; gestopte (foutcode), ongezonde of gepauzeerde containers; pods | Probleem = WARNING | Agent met podman |
| **Container (Podman)** | Eén container: status (incl. healthcheck en herstarts), CPU, RAM of disk | Gestopt/ongezond = CRITICAL; CPU/RAM in %, disk in MB | Agent met podman |

### Drie soorten servers
- **Lokaal**: de RzMon-server zelf. Alles wordt direct uitgelezen.
- **Remote met agent**: een Linux-server of Home Assistant die zelf gegevens doorstuurt. Stopt de agent met rapporteren, dan worden de checks na 5 minuten CRITICAL (instelbaar).
- **Remote (alleen netwerk)**: routers, NAS, switches, printers en websites. Daarvoor zijn ping, TCP en HTTP beschikbaar.

### Meldingen via Pushover, e-mail en Home Assistant
- **Drie kanalen:** Pushover op je telefoon, **e-mail** (altijd, of alleen als reserve als Pushover faalt), en een **webhook naar Home Assistant** om automatiseringen te starten (lamp rood, TTS, notificatie via de HA-app).
- **MQTT discovery:** al je servers en checks verschijnen als apparaten en sensoren in Home Assistant.
- **Melding pas na N fouten** achter elkaar (standaard 2), zodat je geen valse alarmen krijgt.
- **Nieuwe melding bij escalatie**, bijvoorbeeld van WARNING naar CRITICAL.
- **Herhaalmelding** elke X minuten zolang het probleem blijft (standaard 60, 0 = uit).
- **Herstelmelding** ✅ als het weer goed gaat, met lage prioriteit dus zonder geluid.
- **Prioriteit en geluid instelbaar**, apart voor critical en warning, inclusief *Emergency* (blijft herhalen tot je hem bevestigt).
- **Testknoppen** voor elk kanaal, en een **meldingenlog** dat per melding toont via welk kanaal hij is verstuurd (📲 / 📧 / 🏠).

### Storingen bevestigen
- **✔ Bevestigen** per check, of alle storingen van een server tegelijk, met een optionele opmerking. Daarna krijg je voor die storing **geen meldingen meer**: geen herhalingen, ook niet bij erger worden.
- **Vanaf je telefoon:** elke melding bevat een link **"✔ Bevestigen"** (Pushover, en via de webhook ook in Home Assistant). Die werkt zonder inloggen, maar alleen voor die ene storing.
- **Emergency-meldingen:** bevestig je in de Pushover-app, dan neemt RzMon dat over. Bevestig je in RzMon, dan stopt Pushover met herhalen.
- **Vervalt vanzelf:** zodra de check weer OK is, vervalt de bevestiging. Een nieuwe storing geeft gewoon weer een melding.

### Onderhoud en afhankelijkheden
- **🔧 Onderhoudsmodus:** voor alles, per server of per check. Kies een duur (15 min tot een week) of een eindtijd, met een optionele reden. Checks blijven draaien, maar er gaan geen meldingen uit. Is er na afloop nog iets mis, dan krijg je alsnog een melding.
- **🔗 Afhankelijkheden:** geef aan dat een server bereikbaar is via een andere, zoals een router, switch, VPN of hypervisor. Valt die uit, dan krijg je **één** melding ("…meldingen van 5 afhankelijke servers worden onderdrukt") in plaats van een stortvloed. Komt hij terug en ligt een server erachter nog plat, dan krijg je die melding alsnog.

### Dashboard (HTML5)
- **Overzicht:** tellers voor OK, Warning, Critical, Onbekend en Onderhoud, en per server een **tegel met de algehele status** ("Alles OK · 6 checks" of "1 kritiek · 1 waarschuwing"), de namen van de checks met een probleem en mini-meters voor CPU, RAM en disk. Servers met problemen staan bovenaan.
- **Details:** klik op een tegel en hij klapt uit met alle checks per stuk, met balkjes en drempels en de knoppen (bevestigen, onderhoud, check toevoegen, poortscan, bewerken). Welke tegels open staan, wordt onthouden; **⊞ Alles openen** klapt alles in één keer open.
- **Zoeken:** zoekveld (zoek je op een checknaam, dan klapt de server vanzelf open) en een filter **Alleen problemen**.
- **Minigrafiek per check** van de laatste 24 uur; klik erop voor de grote **grafiek** over 6 uur tot 30 dagen, met beschikbaarheid, minimum, gemiddelde, maximum en bij disks de trend.
- **Beschikbaarheid per server** over 24 uur, 7 dagen en 30 dagen.
- **Bijwerken:** de pagina ververst zichzelf elke 20 seconden. Met **↻ Alles nu checken** draai je direct alle checks.
- **Weergave:** donker en licht thema, werkt ook op je telefoon.
- **Twee lay-outs** (*Instellingen → Weergave*, per apparaat):
  - **Blox:** een tegel per server (standaard).
  - **L1nes:** alle servers onder elkaar, elk als balk over de volle breedte. Je kiest **Small** of **Large**:
    - **Small:** één regel met naam, adres, status en tellers.
    - **Large:** kolommen voor status, probleem-checks, CPU, RAM, disk, bereikbaarheid (7 dagen) en de laatste check.
  - Klik op een balk om hem uit te klappen; alles werkt daarna hetzelfde als bij Blox.

### Poortscan
- **Scannen** naar open TCP-poorten: bekende poorten, poort 1-1024, of een eigen lijst zoals `22,80,8000-8100` (maximaal 4096 poorten).
- **Snel:** 1024 poorten zijn binnen een seconde gescand, omdat de scan parallel loopt.
- **＋ Check** maakt van een open poort direct een bewaakte TCP-check.
- **Agent-servers:** daar zie je ook welke poorten luisteren, met procesnaam. Dat werkt ook voor poorten achter een firewall.

### Toegang en beveiliging
- **Login:** inloggen met gebruikersnaam en wachtwoord (bcrypt). Pogingen worden afgeremd tegen brute force.
- **Meerdere gebruikers**, elk met of zonder **tweestapsverificatie** (TOTP, met elke authenticator-app). Beheer en resetten via de instellingenpagina.
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
  "url": "http://192.168.1.10",
  "ack_url": "http://192.168.1.10/ack.php?c=5&t=…"   // bevestig-link (null bij herstel/test)
}
```

> `local_only: true` betekent dat HA de webhook alleen accepteert vanaf je eigen netwerk. Draait RzMon ergens anders, bijvoorbeeld in de cloud, zet dit dan op `false` en gebruik een HTTPS-URL.

### 6.4 E-mail
Ga naar *Instellingen → E-mail*, zet **E-mailmeldingen aan** en vul in:

| Instelling | Uitleg |
|---|---|
| Versturen | **Altijd** (naast Pushover), of **Alleen als Pushover uit staat of mislukt**: e-mail als reservekanaal |
| SMTP-server, poort, beveiliging | Bijvoorbeeld `smtp.gmail.com`, `587`, STARTTLS. Of poort `465` met SSL/TLS. Poort `25` zonder versleuteling alleen voor een mailserver in je eigen netwerk |
| Gebruikersnaam en wachtwoord | Je mailaccount. Leeg laten als de server geen login vraagt |
| Afzender | Leeg = je gebruikersnaam (als dat een e-mailadres is) |
| Ontvanger(s) | Eén of meer adressen, met een komma ertussen |

Klik op **📧 Testmail sturen**. Dat gebruikt wat je hebt ingevuld, ook als het nog niet is opgeslagen. Klik daarna op **Instellingen opslaan**.

Aandachtspunten:
- **App-wachtwoord:** Gmail, Outlook/Hotmail en iCloud accepteren je gewone wachtwoord niet. Maak in je accountinstellingen een *app-wachtwoord* aan (daarvoor moet 2FA bij die dienst aan staan).
- **Inhoud:** de mail bevat de melding, de tijd, de **bevestig-link** en een link naar RzMon (als de *Basis-URL* in Setup is ingevuld).
- **Foutmelding bij de test:** die zegt wat er mis is, bijvoorbeeld "gebruikersnaam/wachtwoord onjuist" of "klopt de beveiliging? 465 = SSL/TLS, 587 = STARTTLS".

### 6.5 MQTT voor Home Assistant
Met MQTT verschijnt RzMon vanzelf in Home Assistant, zonder YAML.

**1. MQTT in Home Assistant.** Heb je nog geen MQTT:
- installeer de add-on **Mosquitto broker** (*Instellingen → Add-ons → Add-on winkel*) en start hem;
- voeg de integratie **MQTT** toe (*Instellingen → Apparaten en diensten*); meestal stelt HA die zelf voor;
- maak een gebruiker voor RzMon, bijvoorbeeld een HA-gebruiker `rzmon` (*Instellingen → Personen → Gebruikers*). Mosquitto accepteert HA-gebruikers als MQTT-login.

**2. In RzMon:** ga naar *Instellingen → MQTT*:
- zet **MQTT aan**;
- vul de MQTT-server in (het adres van Home Assistant), poort `1883`, en de gebruikersnaam en het wachtwoord;
- klik op **📡 Verbinding testen & publiceren** en daarna op **Instellingen opslaan**.

Onder *Apparaten en diensten → MQTT* staan nu:

| Apparaat | Entiteiten |
|---|---|
| **RzMon** | *Probleem* (aan als er ergens een warning of critical is), *Kritiek* en *Waarschuwingen* (aantallen), *Laatste controle* |
| **Elke server** | *Probleem* en *Status* (OK, WARNING, CRITICAL, UNKNOWN of ONDERHOUD) |
| **Elke check** | Een status-sensor, en bij checks met een meetwaarde ook een sensor met die waarde (ms, %, dagen). Die kun je in grafieken en automatiseringen gebruiken |

Goed om te weten:
- **Bijwerken:** RzMon publiceert elke minuut, bij elke cron-run.
- **Niet beschikbaar:** komt er 10 minuten niets binnen, bijvoorbeeld omdat cron of RzMon gestopt is, dan worden de sensoren *niet beschikbaar*. Daar kun je in HA een melding op maken: zo merk je het ook als RzMon zelf uitvalt.
- **Verwijderen:** verwijder je een check of server in RzMon, dan verdwijnt hij ook uit HA.
- **TLS:** kies *Versleuteling: TLS* en poort `8883`. Gebruik je een self-signed certificaat, zet dan onder *Geavanceerd* de certificaatcontrole uit.
- **Prefix:** *Geavanceerd* bevat ook de discovery-prefix (standaard `homeassistant`) en het basis-topic (standaard `rzmon`). Die hoef je normaal niet te veranderen.

Voorbeeld van een automatisering:
```yaml
triggers:
  - trigger: state
    entity_id: binary_sensor.rzmon_probleem
    to: "on"
actions:
  - action: notify.mobile_app_jouw_telefoon
    data:
      message: "RzMon: {{ state_attr('binary_sensor.rzmon_probleem', 'critical') }} kritieke storing(en)"
```
De precieze entiteitnamen zie je in HA bij het apparaat *RzMon*.

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
| SSL-certificaat | Host met eventueel een poort: `site.nl`, `nas.lan:5001`, `mqtt.lan:8883`. Leeg = adres van de server op poort 443. Bij een agent-server kies je ook uit de certificaten die de agent meldt (§8.5) | Warning **onder** 21 dagen, critical **onder** 7 dagen |
| Diskruimte | Mount, bv. `/` of `/data`. Bij agent-servers kies je uit een lijst | 80 / 90 %. Plus WARNING als hij binnen 7 dagen vol raakt |
| Service | systemd-naam, bv. `nginx`. Bij een HA-agent: `homeassistant`, `supervisor`, `updates` of `addon:<slug>` | – |
| CPU | – | 85 / 95 % |
| RAM | – | 85 / 95 % |
| Podman (algemeen) | – (alleen agent-servers met podman) | – |
| Container (Podman) | Containernaam, uit de lijst. Kies bij *Meten*: Status, CPU, RAM of Disk | CPU 80 / 95 %, RAM 85 / 95 %, disk 5000 / 10000 MB |

- **Interval:** minimaal 30 seconden. Omdat cron elke minuut draait, is **1 minuut** in de praktijk het kortste interval.
- **Na opslaan** wordt de check direct uitgevoerd.
- **Knoppen per check:** ↻ = nu checken, 📈 = grafiek, ✎ = bewerken of verwijderen.
- **Certificaat controleren** (bij HTTP(S) en SSL-certificaat): zet dit uit voor apparaten met een self-signed certificaat, zoals Proxmox of een NAS. De vervaldatum wordt dan nog steeds bewaakt.

**Grafieken en beschikbaarheid**
- Onder elke check staat een **minigrafiek** van de laatste 24 uur: de lijn is de meetwaarde, en de gekleurde streepjes eronder tonen de status. Klik erop (of op 📈) voor de grote grafiek, met minimum, gemiddelde en maximum.
- Een opengeklapte server toont **Bereikbaar: 24 u · 7 d · 30 d**. Dat is het percentage metingen waarin de ping lukte; heeft de server geen ping-check, dan tellen de TCP- en HTTP-checks. Ingeklapt staat het percentage van 7 dagen achter ⏱.
- **Diskvoorspelling:** RzMon trekt een lijn door de diskmetingen van de afgelopen 7 dagen. Groeit een disk, dan staat in de check bijvoorbeeld *vol over ~12 dagen (+0,8%/dag)*. Raakt hij binnen 7 dagen vol, dan wordt de check WARNING, ook als de drempel nog niet bereikt is. Het aantal dagen stel je in onder *Instellingen → Alarmgedrag* (0 = uit). Er is minimaal een dag aan metingen nodig.

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

### 7.5 Storing bevestigen ✔
Weet je van een storing en ben je ermee bezig? Bevestig hem, dan word je er niet meer over gestoord.

| Waar | Hoe |
|---|---|
| **Dashboard, één check** | De groene **✔** bij de check |
| **Dashboard, hele server** | De groene **✔** op de serverkaart: alle onbevestigde storingen van die server |
| **Telefoon** | Tik in de Pushover-melding op **✔ Bevestigen** → *Bevestigen* |
| **Pushover-app** (alleen Emergency, prioriteit 2) | Tik op *Acknowledge*; RzMon neemt dat binnen een minuut over |

**Wat er gebeurt:**
- **Geen meldingen meer** voor deze storing: geen herhalingen, en ook niet als hij van WARNING naar CRITICAL gaat.
- **Emergency:** een Emergency-melding stopt meteen met herhalen.
- **Op het dashboard** staat bij de check **✔ bevestigd**. Hou de muis erop voor wie, wanneer en de opmerking; klik erop om de bevestiging **in te trekken**, dan hervatten de meldingen.
- **Titelbalk:** het getal tussen haakjes telt alleen onbevestigde CRITICAL-storingen.
- **Herstel:** zodra de check weer **OK** is, vervalt de bevestiging automatisch. Een herstelmelding krijg je dan alleen als *Instellingen → Alarmgedrag → Ook herstel melden na een bevestigde storing* aan staat (standaard uit).
- **Meldingenlog:** elke bevestiging komt in het log, met ✔.

> **De bevestig-link in Pushover** gebruikt de *Basis-URL* uit *Setup → Applicatie & PHP*, bijvoorbeeld `http://192.168.1.10`. Is die leeg, dan komt er geen link in de melding. Bereikbaar van buitenaf? Gebruik dan je externe adres (HTTPS).
> De link werkt zonder inloggen, maar alleen voor die ene storing. Na herstel of bij een nieuwe storing is een oude link ongeldig. Bevestigen gebeurt pas na een klik op de knop, zodat een linkvoorbeeld in een chat-app niet per ongeluk bevestigt.

### 7.6 Afhankelijkheden 🔗
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

Voor diskruimte, CPU, RAM, services en luisterende poorten van **andere** Linux-servers: Rocky, RHEL, AlmaLinux, Fedora, Debian, Ubuntu, Raspberry Pi OS en Proxmox VE.

### 8.1 Installeren met één commando
1. Voeg in RzMon een server toe met type **Remote met agent**.
2. Klap de server open en klik op **Agent installeren** → tabblad **🐧 Linux-server**. Klik op **Kopieer** bij het commando; daarin staan het adres van RzMon en het token van deze server al ingevuld:
   ```bash
   curl -fsSL 'http://192.168.1.10/api.php?action=agent_install' | sudo bash -s -- --url 'http://192.168.1.10' --token '<token>'
   ```
3. Plak het op de server die je wilt bewaken. Het script:
   - installeert wat ontbreekt (`curl`, `ss`);
   - haalt de agent op **van je eigen RzMon**, dus het werkt ook als de GitHub-repository privé is;
   - **stelt de services voor** die op die server aan staan (bv. `sshd nginx mariadb docker`). Druk op Enter om ze over te nemen, of typ je eigen lijst;
   - zet de instellingen in `/etc/default/rzmon-agent` (alleen leesbaar voor root);
   - laat de agent elke minuut rapporteren via een **systemd-timer**, of via cron als er geen systemd is;
   - stuurt een **testrapport** en controleert of RzMon het accepteert.
4. Klap de server in RzMon open en voeg checks toe met **＋ Check**. Mounts en services kies je uit de lijst met wat de agent gemeld heeft.

| Optie | Uitleg |
|---|---|
| `--services "sshd nginx"` | Zelf de te bewaken services kiezen (anders: voorstel) |
| `--interval 120` | Rapporteren elke … seconden (30-3600, standaard 60) |
| `--insecure` | Self-signed HTTPS-certificaat van RzMon accepteren (gebruik dan ook `curl -k`) |
| `--cron` | Cron gebruiken in plaats van een systemd-timer |
| `-y` | Niets vragen (handig voor Ansible e.d.) |

### 8.2 Beheren
Het installatiescript blijft op de server staan als `rzmon-agent-setup`:

| Wat | Commando |
|---|---|
| **Verbinding testen** | `sudo rzmon-agent-setup --test` (zie 8.4) |
| Status bekijken | `systemctl status rzmon-agent.timer` en `journalctl -u rzmon-agent -n 20` |
| Services wijzigen | `sudo rzmon-agent-setup --services "sshd nginx mariadb"` |
| Certificaten wijzigen | `sudo rzmon-agent-setup --certs "/etc/letsencrypt/live/*/cert.pem localhost:8443"` (zie 8.5) |
| Podman uitzetten | Zet `PODMAN=0` in `/etc/default/rzmon-agent` (zie 8.6) |
| Agent bijwerken | `sudo rzmon-agent-setup --update` (haalt de nieuwste versie van RzMon) |
| Nieuw token | `sudo rzmon-agent-setup --token <nieuw token>` |
| Verwijderen | `sudo rzmon-agent-setup --uninstall` |

Opnieuw uitvoeren is altijd veilig: wat je niet meegeeft, blijft zoals het was.

### 8.3 Goed om te weten
- **Gegevens:** CPU, RAM, alle echte mounts, de gekozen services, de luisterende TCP-poorten, de vervaldatum van de gekozen certificaten en, als podman er staat, de containers (8.6).
- **Netwerk:** de agent stuurt zelf naar RzMon. Op de bewaakte server hoeft dus geen poort open; die server moet RzMon wel kunnen bereiken op de *Basis-URL* uit Setup.
- **Root:** de agent draait als root om procesnamen bij poorten te zien. De systemd-service is afgeschermd (`ProtectSystem`, `NoNewPrivileges`); de agent leest alleen en schrijft niets.
- **Token gelekt?** Klik op *Agent installeren* → **↻ Nieuw token** en voer op de server `sudo rzmon-agent-setup --token <nieuw token>` uit.
- **Foutmeldingen** tijdens het installeren:
  - `403` → het token klopt niet, of de server staat niet op type *Remote met agent*;
  - "kan niet downloaden" → het adres klopt niet, of RzMon is niet bereikbaar vanaf die server;
  - certificaat → `--insecure`.

### 8.4 Komt er niets binnen? Verbindingstest 🩺
**In RzMon:** klap de server open → **Agent installeren**. Bovenaan staat een live-status, die elke 5 seconden wordt bijgewerkt:
- ✔ **Laatste rapport 12 s geleden vanaf 192.168.1.20**: de agent werkt.
- ⏳ **Nog geen rapport ontvangen**: er komt niets binnen.
- **Geweigerde pogingen**, met IP-adres en reden: bijvoorbeeld *onbekend token* of *server staat niet op type 'Remote met agent'*.

**Op de bewaakte server:**
```bash
sudo rzmon-agent-setup --test
```
De test loopt stap voor stap na waar het misgaat, en stopt bij de eerste echte fout met een concrete oplossing:

| Stap | Wat wordt gecontroleerd | Typische oplossing bij een fout |
|---|---|---|
| Planning | Draait de systemd-timer, of draait cron? Hoe liep de laatste run? | `sudo systemctl enable --now rzmon-agent.timer`; cron installeren |
| Instellingen | Zijn adres en token ingevuld? | Test met `sudo` draaien; opnieuw installeren |
| Adres | Wijst het naar deze server zelf (`localhost`)? | Het IP van de RzMon-server gebruiken |
| DNS | Kan de naam van RzMon worden opgezocht? | IP-adres gebruiken, of `/etc/hosts` |
| Netwerk | Is de poort van RzMon bereikbaar? | Firewall op RzMon: `firewall-cmd --add-service=http`; VLAN/router |
| RzMon | Draait daar echt RzMon? Of een doorverwijzing, of een 404? | Juiste URL (http/https, zonder `/public`) |
| Token | Kent RzMon dit token, en staat de server op type *agent*? | Commando opnieuw kopiëren uit RzMon |
| Gegevens | CPU, disks, services en poorten verzamelen | Bv. `iproute` installeren voor poorten |
| Rapport | Proefrapport versturen | — |

Zonder het installatiescript kan het ook: `sudo /usr/local/bin/rzmon-agent.sh --test`.
Met een oudere agent-versie: eerst bijwerken met `sudo rzmon-agent-setup --update`, of het installatiecommando opnieuw uitvoeren.

<details>
<summary>Handmatig installeren (zonder het script)</summary>

```bash
curl -fsSL 'http://192.168.1.10/api.php?action=agent_script' -o rzmon-agent.sh
sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
printf 'MONITOR_URL="%s"\nTOKEN="%s"\nSERVICES="%s"\n' 'http://192.168.1.10' '<token>' 'sshd cron nginx' | sudo tee /etc/default/rzmon-agent >/dev/null
sudo chmod 600 /etc/default/rzmon-agent
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon-agent
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'   # moet {"ok":true} geven
```
</details>


### 8.5 Certificaten bewaken 🔒
Het verloop van certificaten kun je op twee manieren bewaken. Je kunt ze ook combineren.

| | **Zonder agent** (RzMon meet zelf) | **Via de agent** |
|---|---|---|
| Wat | Elke TLS-poort die RzMon kan bereiken: servers in je netwerk én op internet | Certificaat**bestanden** op de server, en `host:poort` gezien **vanaf die server** |
| Handig voor | Websites, NAS, Proxmox, MQTT, mailservers… | Diensten die alleen lokaal luisteren of achter een firewall zitten, Let's Encrypt-certificaten (ook als de dienst plat ligt), en servers die alleen die server kan bereiken |
| Instellen | Check *SSL-certificaat* met `host` of `host:poort` (of in de poortscan op **＋ Cert**) | `CERTS` op de server, daarna een check *SSL-certificaat* en het certificaat kiezen uit de lijst |

**Wat de agent meldt:** de regel `CERTS` in `/etc/default/rzmon-agent`, met spaties tussen de waarden:
```bash
CERTS="/etc/letsencrypt/live/*/cert.pem /etc/pki/tls/certs/mijn.crt localhost:8443 mail.example.com:993 example.com"
```
- **Bestanden:** wildcards mogen. Een wildcard zonder treffers is geen fout, dus de standaard werkt ook op servers zonder Let's Encrypt.
- **host:poort:** zonder poort wordt het 443. De agent maakt een TLS-verbinding vanaf die server; dat werkt ook naar servers op internet.
- **Standaard:** staat `CERTS` er niet in, dan meldt de agent alle Let's Encrypt-certificaten (`/etc/letsencrypt/live/*/cert.pem`). Met `CERTS=""` meldt hij er geen.
- **Installatiescript:** stelt bij het installeren zelf voor wat het vindt: Let's Encrypt en lokale poorten zoals 443, 8443, 8006 of 9443.
- **Wijzigen:** `sudo rzmon-agent-setup --certs "…"`, of het bestand aanpassen met `sudo nano /etc/default/rzmon-agent`.
- **Hoe vaak:** elk uur. RzMon rekent zelf het aantal dagen uit, dus vaker is niet nodig.
- **Benodigd:** `openssl`; het installatiescript installeert dat zo nodig.

**In RzMon:** klik bij de server op **＋ Check** en kies *SSL-certificaat*. In het veld *Host[:poort]* verschijnt een lijst met de certificaten die de agent meldt. Een check daarop krijgt "· via agent" in het bericht.
- *Certificaat controleren* aan: een certificaat dat de agent niet vertrouwt, zoals een self-signed certificaat, wordt CRITICAL.
- Bij bestanden wordt alleen de vervaldatum bekeken.

**Controleren:** `sudo rzmon-agent-setup --test` toont per certificaat bijvoorbeeld `localhost:8443: nas.lan, nog 63 dagen geldig`, of wat er mis is, zoals "poort dicht" of "bestand niet gevonden".


### 8.6 Podman-containers 📦
Staat **podman** op de server, dan meldt de agent vanzelf alle containers. Er hoeft niets ingesteld te worden. Zonder podman stuurt de agent hier niets over.

**Wat de agent meldt** (elke minuut):

| | |
|---|---|
| **Algemeen** | Podman-versie, aantal containers en pods, ruimte van images, containers en volumes, en hoeveel je kunt opruimen (`podman system prune`) |
| **Per container** | Status (draait, gestopt + exitcode, gepauzeerd), healthcheck (gezond/ongezond), aantal automatische herstarts, starttijd, image en pod |
| | **CPU** (actueel, gemeten over 2 seconden; 100% = één core, net als `podman stats`), **geheugen** en de limiet, netwerk- en disk-I/O, aantal processen |
| | **Disk**: de schrijflaag van de container plus de grootte van zijn volumes. Volumes worden elk uur opnieuw gemeten |
| **Per pod** | Status (Running, Degraded…) en aantal containers |

**Rootless containers:** naast de containers van root zoekt de agent gebruikers met eigen containers (in `~/.local/share/containers`). Die staan in RzMon als `gebruiker/naam`.
- Andere keuze: zet `PODMAN_USERS="jan piet"` in `/etc/default/rzmon-agent` voor een vaste lijst, of `PODMAN_USERS=""` voor alleen root. `PODMAN=0` zet podman helemaal uit.
- **CPU en RAM van rootless containers** vereisen cgroups v2; dat is standaard op Rocky/RHEL 9 en 10, Debian 11+ en Ubuntu 22.04+.
- **Lingering:** zet voor die gebruiker *lingering* aan (`sudo loginctl enable-linger jan`), anders stoppen de containers als de gebruiker uitlogt. Dat is sowieso aan te raden.
- **systemd-service:** het installatiescript zet op servers met podman `NoNewPrivileges` en `ProtectHome` uit, omdat rootless podman die nodig heeft. Na het bijwerken van een bestaande agent: `sudo rzmon-agent-setup --update`.

**In RzMon:** klap de server open en klik op **📦 Containers**. Je ziet alle containers met status, CPU, RAM en disk.
- Per container voeg je met één klik een check toe: **＋ Status**, **＋ CPU**, **＋ RAM** of **＋ Disk**.
- Bovenaan staan **＋ Podman-check** (algehele status) en **＋ Status-check voor alle containers**.
- Je kunt het ook via **＋ Check** doen: kies het type *Container (Podman)*, de container uit de lijst, en bij *Meten* wat je wilt bewaken.

| Check | OK | WARNING | CRITICAL |
|---|---|---|---|
| Podman (algemeen) | Alles in orde | Container gestopt met foutcode, ongezond of gepauzeerd; pod niet in orde | Podman reageert niet |
| Container – Status | Draait | Gepauzeerd, of in het afgelopen kwartier automatisch herstart (crash) | Gestopt, ongezond (healthcheck faalt), of bestaat niet meer |
| Container – CPU / RAM | Onder de drempel | Boven de warning-drempel | Boven de critical-drempel. Draait de container niet, dan is deze check ONBEKEND (de status-check meldt het stoppen) |
| Container – Disk | Onder de drempel (MB) | Boven de warning-drempel | Boven de critical-drempel |

Goed om te weten:
- **Bedoeld gestopt:** een container die met exitcode 0 is gestopt (een eenmalige taak), telt bij *Podman (algemeen)* niet als probleem. Een status-check op zo'n container wordt wel CRITICAL; zet daar dus geen status-check op.
- **Handmatige herstart:** `podman restart` telt niet als crash. Alleen herstarts door de restart-policy (`--restart always` of `on-failure`) geven een WARNING.
- **Grafieken en MQTT:** CPU, RAM en disk van containers krijgen ook grafieken en verschijnen als sensoren in Home Assistant, met eenheid % en MB.


### 8.7 Werkt de service echt? Diepere controle 🔎
Op agent-servers kijkt een **service-check** niet alleen of de service "active" is, maar ook of hij echt werkt. Dat staat standaard aan. Een bericht ziet er bijvoorbeeld zo uit:
> nginx draait · luistert op 80, 443 · HTTP 200 in 12 ms · geen fouten in log · draait sinds 20-10 08:15

**Wat de agent controleert:**

| Laag | Wat | Status bij een probleem |
|---|---|---|
| Proces | Draait het hoofdproces echt (`running`), niet alleen "active"? | WARNING |
| Poorten | Op welke poorten luistert díe service, en nemen ze verbindingen aan? Getest op de server zelf | CRITICAL |
| Antwoordt hij? | Een test die bij de dienst past (zie hieronder) | CRITICAL (of WARNING bij bv. HTTP 5xx of tijd niet gesynchroniseerd) |
| Herstarts | Automatische herstarts in het afgelopen uur | WARNING vanaf 3 (crash-lus) |
| Log | Foutregels (`journalctl -p err`) van het laatste kwartier | WARNING, met de laatste foutregel |

**Tests per dienst** (op naam van de service):

| Service | Test |
|---|---|
| nginx, httpd, apache2, caddy, lighttpd, traefik, haproxy | HTTP-verzoek naar de eigen poort; elk antwoord is goed, 5xx = WARNING |
| mariadb, mysql | `mariadb-admin ping` (werkt ook zonder wachtwoord) |
| postgresql | `pg_isready` |
| redis, valkey | `PING` → `PONG` |
| ssh, sshd | SSH-begroeting |
| postfix, exim, sendmail | SMTP-begroeting (220) |
| dovecot | IMAP-begroeting |
| named, bind9, unbound, pihole-FTL, dnsmasq | DNS-vraag krijgt antwoord (`dig`) |
| chronyd, systemd-timesyncd | Tijd is gesynchroniseerd |
| Overige services | Alleen poorten, herstarts en log |

**Eigen poort(en) of socket:** vindt de agent de poort van een service niet zelf, bijvoorbeeld bij een container, een service met een andere procesnaam, of php-fpm via een socket? Bewerk dan de service-check en vul *Eigen poort(en) of socket* in:
- `8080`, of `127.0.0.1:9000`, of `[::1]:8080`: de poort wordt getest (zonder adres: `127.0.0.1`);
- `/run/php-fpm/www.sock`: de agent controleert of de socket bestaat en verbindingen aanneemt;
- meerdere tegelijk mag, gescheiden door een komma (maximaal 10).

Wat er met jouw poorten gebeurt:
- ze worden **altijd** getest, naast de poorten die de agent zelf vindt;
- de **test van de dienst** gebruikt jouw poort. Bijvoorbeeld nginx op `8081` → HTTP-test op 8081, of SSH op `2222` → SSH-begroeting op 2222;
- in het bericht staat "luistert op 80, 8081 (waarvan zelf opgegeven: 8081)";
- werkt een opgegeven poort of socket niet, dan is de check **CRITICAL**.

Nieuwe of gewijzigde poorten worden, net als extra tests, binnen 10 minuten door de agent opgepikt.

**Extra test per check:** bewerk de service-check en kies bij *Extra test*:
- **URL moet antwoorden**, bijvoorbeeld `http://localhost:8080/health`, eventueel met een tekst die in het antwoord moet staan (zoals `OK`). HTTP 400 of hoger telt als fout.
- **Poort moet antwoorden**: `8080` of `host:8080`.

De agent voert de test uit op de server zelf, dus `localhost` werkt. Nieuwe of gewijzigde tests worden binnen 10 minuten opgepikt.

**Eigen test-script** (voor alles wat hierboven niet past): maak een uitvoerbaar script `/etc/rzmon-agent/tests/<servicenaam>`:
```bash
sudo mkdir -p /etc/rzmon-agent/tests
sudo tee /etc/rzmon-agent/tests/myapp >/dev/null <<'EOF'
#!/bin/sh
# Eerste regel uitvoer = bericht. Exitcode: 0 = OK, 1 = WARNING, 2 = CRITICAL
n=$(ls /var/spool/myapp | wc -l)
[ "$n" -gt 1000 ] && { echo "wachtrij vol: $n"; exit 2; }
echo "wachtrij: $n"
EOF
sudo chmod 755 /etc/rzmon-agent/tests/myapp
```
- Bestaande Nagios-plugins werken ook; die gebruiken dezelfde exitcodes.
- **Veiligheid:** het script moet van root zijn en mag niet door anderen te wijzigen zijn, anders voert de agent het niet uit. Het mag maximaal 20 seconden duren.
- Via RzMon kunnen nooit commando's op je servers worden uitgevoerd; alleen URL- en poorttests.

**Goed om te weten:**
- **Uitzetten:** per check zet je *Diepere controle* uit, dan wordt alleen "active" gecontroleerd. Voor de hele server: `SERVICE_DEEP=0` in `/etc/default/rzmon-agent`.
- **Eenmalige services** (`Type=oneshot`, zoals een firewall-script) die "exited" zijn, gelden als goed.
- **Op de RzMon-server zelf** (type *Lokaal*) wordt alleen de status gecontroleerd. Wil je daar ook de diepere controle, installeer dan de agent en maak een server van type *Remote met agent*.
- Controleren op de server: `sudo rzmon-agent-setup --test` toont per service de poorten en het resultaat van de test.

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
   certs:                        # vervaldatum bewaken (optioneel)
     - /ssl/fullchain.pem        # Let's Encrypt- of DuckDNS-add-on
     - mijndomein.duckdns.org:443
   ```
   Klik op **Opslaan** en dan op **Starten**. Controleer op het tabblad *Info* dat *Starten bij opstarten* aan staat.
5. **Logboek:** daar moet `Verbonden met RzMon ✔` staan.
6. **RzMon:** voeg checks toe met **＋**. Aanbevolen:
   - Service `homeassistant`, `supervisor` en `updates`
   - Service `addon:...` voor elke add-on die altijd moet draaien, zoals Mosquitto, Zigbee2MQTT of Z-Wave JS
   - Diskruimte `/data` (80/90%), CPU, RAM
   - SSL-certificaat: kies `/ssl/fullchain.pem` uit de lijst (zie §8.5)
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

### Gebruikers en tweestapsverificatie 🔐
Onder *Instellingen → Gebruikers* beheer je wie er mag inloggen. Iedere gebruiker kan alles beheren.

- **Gebruiker toevoegen:** vul een naam en wachtwoord in. Wil je meteen 2FA, zet dan *Direct tweestapsverificatie instellen* aan. Zonder 2FA is alleen het wachtwoord nodig.
- **2FA instellen:** klik bij de gebruiker op **🔐 2FA instellen** en scan de QR-code met een authenticator-app, zoals Google of Microsoft Authenticator, Authy, Bitwarden of 1Password. Lukt scannen niet, typ dan de sleutel eronder over. Vul de code in die de app toont: pas daarna staat 2FA aan, dus je kunt jezelf niet per ongeluk buitensluiten.
- **Inloggen met 2FA:** na het wachtwoord vraagt RzMon om de 6-cijferige code. Een code werkt maar één keer. Na 5 foute codes, of na 5 minuten, begin je opnieuw met je wachtwoord.
- **2FA resetten:** bijvoorbeeld bij een nieuwe of verloren telefoon. Klik op **2FA resetten**: die gebruiker logt dan weer in met alleen het wachtwoord, tot 2FA opnieuw is ingesteld.
- **🔑** stelt een nieuw wachtwoord in voor een gebruiker. **🗑** verwijdert een gebruiker; jezelf en de laatste gebruiker kun je niet verwijderen.
- **Op het lokale netwerk** blijft inloggen zonder wachtwoord werken als je dat aan hebt staan; 2FA geldt voor inloggen van buitenaf.

**Buitengesloten?** Op de server zet dit 2FA uit voor een gebruiker:
```bash
sudo -u www-data php /var/www/rzmon/install/install.php --reset-2fa admin     # Rocky: sudo -u apache …
```

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
| `GET /v1.php/hosts` | Alle servers met checks, inclusief `uptime` (% bereikbaar over `h24`, `d7` en `d30`) |
| `GET /v1.php/hosts/{id}` | Eén server |
| `GET /v1.php/checks` | Alle checks. Filters: `?status=2`, `?type=disk`, `?host_id=1` |
| `GET /v1.php/checks/{id}` | Eén check, met waarde, eenheid, drempels en bericht |
| `GET /v1.php/checks/{id}/history?hours=24` | Meetwaarden (maximaal 720 uur) |
| `GET /v1.php/alerts?limit=50` | Laatste meldingen (maximaal 500) |

- **Status:** `0` OK, `1` WARNING, `2` CRITICAL, `3` UNKNOWN. Checks in onderhoud tellen niet mee voor de totaalstatus; staat alles in onderhoud, dan is de status `0`.
- **Extra velden:** `in_maintenance` en `maintenance_until` bij servers en checks, `parent_id` bij servers, `suppressed_by` bij checks (id van de onbereikbare server erboven), `acknowledged`, `acknowledged_at`, `acknowledged_by`, `ack_comment` en `problem_since` bij checks, `counts.acknowledged` (bevestigde storingen), `counts.maintenance` en `maintenance` (globaal onderhoud) in `/status`.
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

### Versienummer
RzMon heeft een versienummer (bijvoorbeeld **v1.6.0**). Je ziet het:
- naast het logo in de topbalk; klik erop voor de lijst met wijzigingen in [`CHANGELOG.md`](CHANGELOG.md);
- op de login- en setup-pagina;
- in de API (`GET /api/v1/status` → `version`, en `api.php?action=ping`).

De nummering is *major.minor.patch*:
- **patch** (1.6.0 → 1.6.1): alleen reparaties;
- **minor** (1.6 → 1.7): nieuwe functies, volledig compatibel;
- **major** (1 → 2): er verandert iets waar je zelf iets voor moet doen. Dat staat dan in de changelog.

De agents hebben hetzelfde versienummer. Elke agent geeft zijn versie door, en je ziet die op de servertegel (bijvoorbeeld `agent 1 min geleden · v1.6.0`). Is een agent ouder dan RzMon, dan staat er **⬆** achter. In het venster *Agent installeren* staat dan het commando om hem bij te werken. Met `rzmon-agent.sh --version` zie je de versie op de server zelf.

### Bijwerken met het installatiescript (Rocky Linux)
```bash
git -C ~/rzmon pull && sudo bash ~/rzmon/install/install-rocky.sh --repo ~/rzmon
```
Gebruik je de curl-methode (openbare repository), voer dan hetzelfde curl-commando uit als bij de installatie. Zie ook [INSTALL-ROCKY-LINUX-10.md](INSTALL-ROCKY-LINUX-10.md).
Het script haalt de nieuwste versie op en meldt bijvoorbeeld `RzMon bijgewerkt van 1.5.0 naar 1.6.0`.

### Handmatig bijwerken
1. **Maak eerst een back-up** (hoofdstuk 13).
2. **Zet de nieuwe bestanden eroverheen** (`git pull` in de RzMon-map, of uitpakken). Laat `config.php` staan:
   ```bash
   cd /tmp && unzip -o rzmon.zip
   sudo rsync -a --exclude config.php /tmp/rzmon/ /var/www/rzmon/
   sudo chown -R root:www-data /var/www/rzmon
   ```
3. **De database wordt automatisch bijgewerkt.** Dat gebeurt bij de eerste cron-run of de eerste keer dat je het dashboard opent na een nieuwe versie. Lukt dat niet, bijvoorbeeld door ontbrekende rechten, dan zie je een rode balk op het dashboard. Handmatig kan het ook:
   ```bash
   sudo -u www-data php /var/www/rzmon/install/install.php --upgrade
   ```
   Of klik op **Setup → ↻ Tabellen bijwerken**.
4. **Werk de agents bij:**
   - Linux-servers: `sudo rzmon-agent-setup --update`.
   - Home Assistant: ga naar de add-on winkel → ⋮ → *Controleren op updates* → *Bijwerken*. HA ziet de update omdat het versienummer van de add-on mee verhoogd wordt.
5. **Ververs de browser** met Ctrl+F5.

### Voor ontwikkelaars: versie verhogen
Elke wijziging aan RzMon krijgt een nieuw versienummer:
```bash
tools/bump-version.sh patch      # of minor / major / 1.7.0
# vul daarna de nieuwe sectie bovenaan CHANGELOG.md in
tools/check-version.sh           # controleert of alles gelijk loopt
```
`bump-version.sh` werkt deze plekken in één keer bij:
- het bestand `VERSION`;
- de agent;
- de kopie van de agent in de HA-add-on;
- de add-on-versie.

Op GitHub controleert een Action (`Versiecontrole`) bij elke push of de versies gelijk zijn en de changelog ingevuld is.

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
| **Telefoon met 2FA kwijt** | `sudo -u www-data php /var/www/rzmon/install/install.php --reset-2fa admin` zet 2FA uit; stel het daarna opnieuw in |
| **2FA-code steeds "onjuist"** | Staat de klok van je telefoon én van de server goed? Codes wisselen elke 30 seconden. Controleer de server met `timedatectl` |
| **Testmail mislukt** | Lees de foutmelding: bij "gebruikersnaam/wachtwoord onjuist" heb je bij Gmail/Outlook een app-wachtwoord nodig. Bij een time-out: 465 = SSL/TLS, 587 = STARTTLS. Op Rocky Linux moet SELinux uitgaande verbindingen toestaan (`setsebool -P httpd_can_network_connect 1`, doet het installatiescript al) |
| **MQTT: "geen toegang"** | Gebruikersnaam/wachtwoord van de broker klopt niet. Bij de Mosquitto-add-on: gebruik een HA-gebruiker of zet een login in de add-on-configuratie |
| **MQTT: geen apparaten in HA** | Is de MQTT-integratie in HA toegevoegd? Klopt de discovery-prefix (`homeassistant`)? Klik op *Verbinding testen & publiceren*. Met `mosquitto_sub -h <broker> -u … -P … -t 'rzmon/#' -v` zie je wat RzMon stuurt |
| **SSL-check: "niet vertrouwd of naam klopt niet"** | Self-signed certificaat (bv. Proxmox of een NAS): zet *Certificaat controleren* uit bij de check. De vervaldatum wordt dan nog steeds bewaakt |
| **Dashboard stuurt door naar Setup** | De database is onbereikbaar. Controleer `sudo systemctl status mariadb` en de gegevens bij Setup → Database |
| **"Cron draait" blijft oranje** | Controleer `/etc/cron.d/rzmon`: het bestand moet van root zijn en mag geen punt in de naam hebben. Test handmatig met `sudo -u www-data php cron/run.php` |
| **Ping: "not found"** | `sudo apt install iputils-ping`, en zet het juiste pad bij Setup (`command -v ping`) |
| **Ping: "Operation not permitted"** | `echo 'net.ipv4.ping_group_range = 0 2147483647' \| sudo tee /etc/sysctl.d/99-ping.conf && sudo sysctl --system` |
| **Service-check: "System has not been booted with systemd"** | RzMon draait in een container zonder systemd. Gebruik dan TCP-checks, of een agent op de host |
| **HTTP-check: SSL-fout** | Het certificaat is verlopen of self-signed, of het systeem mist CA-certificaten (`sudo apt install ca-certificates`) |
| **Pushover-test mislukt** | Controleer de user key en het token. De server moet uitgaand `https://api.pushover.net` kunnen bereiken: test met `curl -I https://api.pushover.net` |
| **Agent rapporteert niet / "Nog geen rapport ontvangen"** | Op de server: `sudo rzmon-agent-setup --test` (§8.4). In RzMon staan bij *Agent installeren* de geweigerde pogingen met reden |
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
