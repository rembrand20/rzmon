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
| **Logbestand (zoeken)** | Treffers van een zoekpatroon in een logbestand, het systemlog of kernelmeldingen (bv. mislukte logins, hardwarefouten) | Aantal treffers in de laatste 15 min (instelbaar) | Agent |
| **Container (Podman)** | Eén check per container: altijd de status (incl. healthcheck en herstarts), plus naar keuze CPU, RAM en disk | Gestopt/ongezond = CRITICAL; CPU/RAM in %, disk in MB, elk met eigen drempels | Agent met podman |

**⇄ Omgekeerde status:** bij ping, poort, HTTP, service, container, Podman, commando en Home Assistant kies je zelf welke status een check krijgt als hij slaagt (OK, warning of critical) en als hij mislukt. Zo bewaak je iets dat juist níet mag draaien of bereikbaar mag zijn (§7.2).

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
- **Meldingen per dag** (pagina *Meldingen*): elke dag een uitklapblok met tellers (🔴 critical, 🟠 warning, ⚪ onbekend, ✅ hersteld, ✔ bevestigd). *Vandaag* staat open, de andere dagen dicht; **⊞ Alles openen** klapt alles open. Hoeveel dagen bewaard en getoond worden, stel je in onder *Instellingen → Alarmgedrag → Meldingen bewaren (dagen)*. **Sorteren:** in L1nes klik je op een kolomkop (Tijd, Status, Server, Check, Bericht, Kanalen); in Blox kies je *Sorteren op …*. Klik op een melding voor de popup van die check.

### Storingen bevestigen
- **✔ Bevestigen** per check, of alle storingen van een server tegelijk, met een optionele opmerking. Daarna krijg je voor die storing **geen meldingen meer**: geen herhalingen, ook niet bij erger worden.
- **Vanaf je telefoon:** elke melding bevat een link **"✔ Bevestigen"** (Pushover, en via de webhook ook in Home Assistant). Die werkt zonder inloggen, maar alleen voor die ene storing.
- **Emergency-meldingen:** bevestig je in de Pushover-app, dan neemt RzMon dat over. Bevestig je in RzMon, dan stopt Pushover met herhalen.
- **Vervalt vanzelf:** zodra de check weer OK is, vervalt de bevestiging. Een nieuwe storing geeft gewoon weer een melding.

### Onderhoud en afhankelijkheden
- **🔧 Onderhoudsmodus:** voor alles, per server of per check. Kies een duur (15 min tot een week) of een eindtijd, met een optionele reden. Checks blijven draaien, maar er gaan geen meldingen uit. Is er na afloop nog iets mis, dan krijg je alsnog een melding.
- **🔗 Afhankelijkheden:** geef aan dat een server bereikbaar is via een andere, zoals een router, switch, VPN of hypervisor. Valt die uit, dan krijg je **één** melding ("…meldingen van 5 afhankelijke servers worden onderdrukt") in plaats van een stortvloed. Komt hij terug en ligt een server erachter nog plat, dan krijg je die melding alsnog.

### Dashboard (HTML5)
- **Gezondheidsbalk** bovenaan: de algehele status van je netwerk in één oogopslag.
  - **🟢 Alles in orde**: geen open problemen. **🟠 Aandacht nodig**: alleen warnings of onbekend, of problemen die bevestigd zijn of in onderhoud staan. **🔴 Storing**: een onbevestigde critical of een onbereikbare server.
  - **Score 0–100%** met een balk: het gemiddelde over je servers. Per server gaat er per probleem iets af (critical 25, warning 10, onbekend 5; bevestigd de helft); een onbereikbare server telt als 0%. Servers die helemaal in onderhoud staan, tellen niet mee.
  - **Tellers** (● 14 OK ● 1 W ● 1 C ● 0 ? 🔧 2): klik erop en je ziet alleen de checks met die status; nog een keer klikken heft het filter op. Klik op de status zelf voor *Alleen problemen*.
  - **Inforegel:** servers bereikbaar (met de naam van wie onbereikbaar is: geen ping, agent stil of achter een uitgevallen server), checks OK, het aantal open (onbevestigde) problemen en het **langst openstaande** probleem met de duur, de gemiddelde beschikbaarheid over 24 uur, en indien van toepassing 🔀 flappende checks en 🔧 checks in onderhoud. Klik op een servernaam en RzMon springt naar die server en klapt hem open.
- **Overzicht:** per server een **tegel met de algehele status** ("Alles OK · 6 checks" of "1 kritiek · 1 waarschuwing"), de namen van de checks met een probleem en mini-meters voor CPU, RAM en disk. Servers met problemen staan bovenaan.
- **Details:** klik op een tegel en hij klapt uit met alle checks per stuk, met balkjes en drempels en de knoppen (bevestigen, onderhoud, check toevoegen, poortscan, bewerken). Welke tegels open staan, wordt onthouden; **⊞ Alles openen** klapt alles in één keer open.
- **Checks in groepen** (Blox en L1nes): heeft een server 5 of meer checks, dan staan ze onder kopjes, altijd in deze volgorde: **📊 Systeem** (CPU, RAM, disks), **🌐 Bereikbaarheid** (ping, poort, HTTP), **⚙️ Services**, **📦 Containers**, **🖥️ Commando's**, **📜 Logbestanden**, **🔒 Certificaten** en **🏠 Home Assistant**. Lege groepen zie je niet. In het kopje staan het aantal checks en de statussen die aandacht vragen (bv. *🔴 1 🟠 2*, of ✅). Klik op een kopje om de groep in of uit te klappen; dat wordt per server en per apparaat onthouden. Binnen een groep staan de problemen bovenaan (kritiek → waarschuwing → onbekend → OK → onderhoud → uit), daarna op naam; bij Systeem eerst CPU, dan RAM, dan de disks. Met minder dan 5 checks zijn er geen kopjes, maar wel dezelfde volgorde.
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
  - Klik op een balk om hem uit te klappen. De checks staan dan ook als **rijen** onder de server, in dezelfde **groepen** als bij Blox (zie hierboven), met binnen elke groep de problemen bovenaan:
    - **Small:** één regel per check met naam, type, waarde (balk voor percentages), bericht en tijd.
    - **Large:** kolomkoppen, met per check ook de minigrafiek van 24 uur; het bericht mag over meer regels lopen.
    - De knoppen per check (✔ 🔧 ↻ 📈 ✎) verschijnen als je met de muis over de rij gaat; op een telefoon of tablet staan ze er altijd.
  - De lay-out geldt ook voor **Instellingen** en **Meldingen**:
    - **Instellingen in L1nes:** elk onderdeel is een rij over de volle breedte, in groepen (*Meldingen*, *Monitoring*, *Systeem*, *Toegang*). Achter de titel staat een korte samenvatting (bijvoorbeeld *✔ aan · critical: Hoog*), zodat je zonder open te klikken ziet hoe het staat. Klik op een rij om hem te openen; welke rijen open staan, wordt onthouden. **Small:** standaard alles dicht. **Large:** standaard alles open. **⊞ Alles openen** klapt alles in één keer open. De knop **💾 Instellingen opslaan** blijft onderin in beeld, met *● niet opgeslagen wijzigingen* zolang je nog moet opslaan. De kaart *Weergave* staat altijd bovenaan open.
    - **Meldingen in L1nes:** een tabel met één regel per melding: tijd, status, server, check, bericht en kanalen. **Small:** het bericht ingekort (klik erop voor het hele bericht). **Large:** het hele bericht.
    - In **Blox** zien Instellingen en Meldingen eruit zoals altijd.

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
- **🛡️ IDS (intrusion detection):** de agents letten op gewijzigde gevoelige bestanden, nieuwe gebruikers en poorten, verdachte processen en verbindingen, brute force en inbraak na brute force. Op de pagina *IDS* handel je incidenten af en blokkeer of deblokkeer je IP-adressen; critical gaat ook naar Pushover. Standaard uit; eerst *leren* (alleen melden), daarna eventueel *blokkeren*.
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

### 6.2 Meldingen 🔔
In *Instellingen → 🔔 Meldingen* stel je per status in **wanneer** en **waarheen** een melding gaat:

| Status | Melden | Herhalen | Kanalen |
|---|---|---|---|
| 🟠 Warning | Nooit, Direct, of Na X minuten | Elke Y minuten (0 = nooit) | Pushover / E-mail / Home Assistant |
| 🔴 Critical | idem | idem | idem |
| ⚪ Onbekend | idem; plus de schakelaar **Melden bij ONBEKEND** | idem | idem |
| ✅ Hersteld | Nooit, Direct, of Na X minuten OK | – | idem |

- **Na X minuten:** pas melden als de check X minuten **onafgebroken** in die status is. Herstelt hij eerder, dan komt er geen melding. Zo filter je korte pieken weg. Elke status heeft een eigen klok: een warning die critical wordt, meldt meteen als critical "Direct" is.
- **Hersteld na X minuten OK:** de herstelmelding komt pas als het X minuten goed blijft. Gaat het in die tijd weer mis, dan blijft het dezelfde storing: geen nieuwe probleemmelding en geen herstelmelding.
- **Kanalen:** per status aanvinken waar de melding heen gaat. Een kanaal dat uit staat, is gemarkeerd met *(uit)*.
- **Bundelen per server** (standaard 120 seconden): gaan op één server binnen die tijd meerdere checks mis of herstellen ze, dan komt er **één bericht**, bijvoorbeeld *"🔴 CRITICAL: WebServer01 – 3 meldingen"* met alle checks erin. Ook herinneringen worden gebundeld (*"🔁 WebServer01: nog steeds 2 problemen"*). Het bericht krijgt de ergste status, en gaat naar alle kanalen die bij één van de checks horen. De eerste melding wacht daardoor hooguit zo lang; 0 = niet bundelen. Home Assistant krijgt wel een bericht per check.
- **Ook herstel melden na een bevestigde storing:** standaard uit.

**Anti-flapping** (standaard aan): een check die steeds van status wisselt, bijvoorbeeld OK → CRITICAL → OK → CRITICAL, geeft geen stroom berichten.
- Een check **flapt** bij **4 statuswisselingen binnen 30 minuten**. Je krijgt dan één melding: *"🔀 FLAPPEND: … staat te flappen (5× gewisseld in 30 min)"*.
- Zolang hij flapt, komt er **per status hooguit één melding**: de eerste warning, de eerste critical, de eerste onbekend en het eerste herstel. Verdere wisselingen en herinneringen worden niet gemeld. Op het dashboard staat **🔀 flapt** bij de check.
- Is de status **15 minuten** niet veranderd, dan krijg je één melding *"STABIEL: … is 15 min OK/CRITICAL"*, en werkt alles weer normaal.
- De drie getallen pas je aan in het blok *Anti-flapping*, en per check in de eigen meldregels.

**🌙 Stille uren** (standaard uit): periodes waarin er **geen meldingen** uitgaan, bijvoorbeeld 's nachts.
- Zet *Stille uren aan* en vul één of meer **vensters** in: *van* en *tot*, en de **dagen** waarop het venster begint. Over middernacht mag: *23:00 tot 07:00 op ma–vr* loopt van maandagavond tot dinsdagochtend. Van gelijk aan tot betekent 24 uur. Met **＋ Venster** voeg je er één toe, bijvoorbeeld in het weekend *00:00 tot 10:00*.
- Tijdens stille uren gaat er niets naar Pushover, e-mail of Home Assistant, ook geen critical. Checks draaien gewoon door; het dashboard, MQTT en de API laten alles zien. In de gezondheidsbalk staat **🌙 Stil tot 07:00**.
- **Na afloop** krijg je **één bericht** met alles wat dan nog mis is, van alle servers samen, bijvoorbeeld *"🌅 Na de stille uren: 2 problemen open"* met per regel de server, de check en sinds hoe laat. Kreeg je vóór de stille uren een melding over een probleem dat 's nachts herstelde, dan staat het herstel er ook in. Wat 's nachts misging en vanzelf weer goed kwam, wordt niet gemeld. Bevestigde checks en checks in onderhoud tellen niet mee.
- **Per check** kies je in het checkformulier (✎) onder **🌙 Stille uren**: *algemeen*, *eigen vensters* of *nooit stil: altijd melden* (bijvoorbeeld voor je router). Checks met eigen stille uren hebben een 🌙 op het dashboard, checks die nooit stil zijn een 🌙✕.
- Home Assistant krijgt na afloop per check een bericht met `"event": "quiet"` (nog open) of `"recovery"`.

**Per check aanpassen:** in het checkformulier (✎) staat **🔔 Meldingen**. Zet *Eigen meldregels* aan en pas de tabel aan; de algemene instellingen zijn het startpunt. Checks met eigen regels hebben een 🔔 op het dashboard. Bijvoorbeeld: een back-upcheck die pas na een dag meldt, of een ping die critical meteen meldt en herstel na 2 minuten.

Bij de update naar 1.25.0 zijn je oude instellingen omgezet, zodat er niets veranderde. "Alarm na N fouten" werd "na N−1 minuten", "ook melden bij WARNING" uit werd "Nooit", en de webhook-keuze "alleen critical en herstel" werd de kolom Home Assistant.

### 6.2b Alarmgedrag
| Instelling | Standaard | Uitleg |
|---|---|---|
| Agent offline na … minuten | 5 | Na zoveel minuten zonder rapport worden de agent-checks CRITICAL. Minstens 3 tikken van die agent (bij een tik van 5 min dus ruim 15 min) |
| Historie bewaren (dagen) | 30 | Meetwaarden voor de grafieken |
| Meldingen bewaren (dagen) | 3× de historie (90) | Hoe lang verstuurde meldingen en de statuswijzigingen van checks bewaard worden; de pagina *Meldingen* toont ze per dag. Oudere worden elk uur opgeruimd |

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
| Welke meldingen | Stel je in bij *🔔 Meldingen*, kolom Home Assistant |
| SSL-certificaat controleren | Uitzetten als HA via HTTPS met een self-signed certificaat draait |

**Inhoud van het bericht** (`trigger.json` in Home Assistant):
```jsonc
{
  "source": "rzmon",
  "event": "problem",              // problem | reminder | recovery | quiet (nog open na de stille uren) | maintenance | test
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
| **RzMon** | *Algehele status* (Alles in orde, Aandacht nodig of Storing, met alle details als attributen), *Gezondheid* (score in %), *Probleem* (aan als er ergens een warning of critical is), *Kritiek* en *Waarschuwingen* (aantallen), *Laatste controle* |
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
| Container (Podman) | Containernaam, uit de lijst. Status altijd; vink CPU, RAM en/of disk aan, elk met eigen drempels | CPU 80 / 95 %, RAM 85 / 95 %, disk 5000 / 10000 MB |
| Commando | Een commando en het antwoord dat je verwacht (gelijk aan, bevat, regex of exitcode 0); alleen als commando's voor die server aan staan (§8.10) | Afwijking = critical of warning, naar keuze |

- **Interval:** minimaal 30 seconden. Omdat cron elke minuut draait, is **1 minuut** in de praktijk het kortste interval.
- **Na opslaan** wordt de check direct uitgevoerd.
- **Knoppen per check:** ↻ = nu checken, 📈 = grafiek, ✎ = bewerken of verwijderen.
- **Klik op een check** (op de naam of het bericht) voor een popup met meer informatie:
  - de huidige status, sinds wanneer, en het bericht;
  - type, doel, interval, drempels, laatste check en de beschikbaarheid over 24 uur en 7 dagen;
  - of hij eigen meldregels, eigen stille uren, een omgekeerde status, onderhoud of een bevestiging heeft;
  - de **laatste 5 statuswijzigingen**: van welke naar welke status, hoe lang die duurde, het bericht dat de wijziging veroorzaakte, en of er een melding is verstuurd (wanneer en via welke kanalen). Zo niet, dan staat erbij waarom niet: onderhoud, storing bevestigd, onderdrukt, of *te kort, flapt, stille uren of de meldregel staat uit*;
  - knoppen voor bevestigen, onderhoud, nu checken, grafiek en bewerken.

  De statuswijzigingen worden bijgehouden vanaf versie 1.37.0, ook als er geen melding uitging, en net zo lang bewaard als de meldingen. Je opent de popup ook door op een melding op de pagina *Meldingen* te klikken.
- **⇄ Omgekeerde status** (zie hieronder): voor iets dat juist níet mag draaien of bereikbaar mag zijn.
- **Certificaat controleren** (bij HTTP(S) en SSL-certificaat): zet dit uit voor apparaten met een self-signed certificaat, zoals Proxmox of een NAS. De vervaldatum wordt dan nog steeds bewaakt.

**⇄ Omgekeerde status**

Soms wil je juist een melding als iets wél reageert: telnet of FTP mag niet luisteren, een oude server moet uit staan, of een website mag van buitenaf niet bereikbaar zijn. Daarvoor heeft het checkformulier twee keuzes:

- **Status als de check slaagt:** *OK (normaal)*, *WARNING* of *CRITICAL*. Kies je WARNING of CRITICAL, dan is de check omgekeerd.
- **Status als de check mislukt:** *OK* (standaard), *WARNING* of *CRITICAL*. Deze keuze verschijnt pas als de check omgekeerd is.

Voorbeeld: een service-check op `sshd`, ingesteld op *slaagt = CRITICAL* en *mislukt = OK*:

| Wat de check ziet | Normaal | Omgekeerd |
|---|---|---|
| sshd draait | ✅ OK | 🔴 CRITICAL: *⇄ Reageert (dat mag niet): …* |
| sshd draait niet | 🔴 CRITICAL | ✅ OK: *⇄ Reageert niet, zoals bedoeld: …* |
| Geen gegevens (agent stil) | ⚪ onbekend | ⚪ onbekend |

Goed om te weten:
- Kan bij **ping, TCP-poort, HTTP(S), service, container, Podman, commando en Home Assistant**. Bij checks met drempels (disk, CPU, RAM, logbestand, certificaat) kan het niet; daar zou het verwarrend zijn.
- Een **waarschuwing telt als "slaagt"**: een trage website of een service die maar half door de diepere controle komt, reageert wel.
- Op het dashboard staat een **⇄** achter de naam; met de muis erop zie je de instelling.
- Meldingen, bevestigen, onderhoud, stille uren, grafieken en de API gebruiken gewoon de omgekeerde status.
- Een omgekeerde ping-, poort- of HTTP-check telt **niet mee** voor *Bereikbaar*, de gezondheidsbalk (server onbereikbaar) en afhankelijke servers. Die gaan over of een server bereikbaar is, en daar zegt een omgekeerde check niets over.

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

#### Automatisch onderhoud na herhaalde meldingen
Blijft een check fout, dan krijg je eerst gewoon meldingen: de eerste, en daarna herhalingen volgens *Herhalen* in 🔔 Meldingen. Bij de **3e verstuurde melding** voor dezelfde storing gaat **die ene check** automatisch in onderhoud:
- je krijgt één melding *🔧 ONDERHOUD: server – check*, en daarna niets meer over die check;
- de andere checks van de server melden gewoon door;
- bij de check staat **🔧 auto, tot opgelost**;
- is de check weer OK, dan stopt het onderhoud vanzelf en krijg je *✅ HERSTELD … Automatisch onderhoud beëindigd*;
- eerder stoppen kan met 🔧 → **Onderhoud beëindigen**.

Instellen gaat onder *Instellingen → Alarmgedrag*: aan/uit en het aantal meldingen (standaard aan, na 3).
- **Wat telt mee:** alleen meldingen die via minstens één kanaal zijn aangekomen. Onderdrukte meldingen (afhankelijkheden) tellen niet mee.
- **Bevestigd:** bevestig je een storing, dan stoppen de meldingen al, dus dan komt automatisch onderhoud niet aan de orde.
- **Geen herhalingen:** staat *Herhalen* op 0, dan krijg je maar één melding per storing (plus een nieuwe als hij erger wordt), en wordt de drempel van 3 meestal niet gehaald.

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
2. Klap de server open en klik op **Agent Manager** → tabblad **🐧 Linux-server**. Klik op **Kopieer** bij het commando; daarin staan het adres van RzMon en het token van deze server al ingevuld:
   ```bash
   curl -fsSL 'http://192.168.1.10/api.php?action=agent_install' | sudo bash -s -- --url 'http://192.168.1.10' --token '<token>'
   ```
3. Plak het op de server die je wilt bewaken. Het script:
   - installeert wat ontbreekt (`curl`, `ss`);
   - haalt de agent op **van je eigen RzMon**, dus het werkt ook als de GitHub-repository privé is;
   - zet de instellingen in `/etc/default/rzmon-agent` (alleen leesbaar voor root);
   - laat de agent elke minuut rapporteren via een **systemd-timer**, of via cron als er geen systemd is;
   - stuurt een **testrapport** en controleert of RzMon het accepteert.
4. Klap de server in RzMon open en voeg checks toe met **＋ Check**. Mounts en services kies je uit de lijst met wat de agent gemeld heeft (zie 8.2.2).

| Optie | Uitleg |
|---|---|
| `--services "sshd nginx"` | *Verouderd.* Services kies je in RzMon (8.2.2); wat je hier opgeeft wordt ook gemeld |
| `--interval 120` | Tik als RzMon er geen doorgeeft (30-3600, standaard 60); normaal stel je dit in RzMon in |
| `--insecure` | Self-signed HTTPS-certificaat van RzMon accepteren (gebruik dan ook `curl -k`) |
| `--cron` | Cron gebruiken in plaats van een systemd-timer |
| `--no-epel` | Rocky/Alma/RHEL/Oracle: EPEL niet installeren (wordt bewaard als `EPEL=0`); dan geen bestanden in realtime |
| `-y` | Niets vragen (handig voor Ansible e.d.) |

### 8.2 Beheren
Het installatiescript blijft op de server staan als `rzmon-agent-setup`:

| Wat | Commando |
|---|---|
| **Verbinding testen** | `sudo rzmon-agent-setup --test` (zie 8.4) |
| Status bekijken | `systemctl status rzmon-agent.timer` en `journalctl -u rzmon-agent -n 20` |
| Services kiezen | In RzMon: **＋ Check → Service** (zie 8.2.2) |
| Certificaten wijzigen | `sudo rzmon-agent-setup --certs "/etc/letsencrypt/live/*/cert.pem localhost:8443"` (zie 8.5) |
| Podman uitzetten | Zet `PODMAN=0` in `/etc/default/rzmon-agent` (zie 8.6) |
| Agent bijwerken | Knop **⬆ Bijwerken** in RzMon (zie 8.2.1), of `sudo rzmon-agent-setup --update` (haalt de nieuwste versie van RzMon) |
| Nieuw token | `sudo rzmon-agent-setup --token <nieuw token>` |
| Verwijderen | `sudo rzmon-agent-setup --uninstall` |

Opnieuw uitvoeren is altijd veilig. Bij een bestaande installatie blijft `/etc/default/rzmon-agent` precies zoals het is, met je eigen regels en commentaar. Geef je zelf iets mee, bijvoorbeeld `--token` of `--interval`, dan wordt alleen die ene regel aangepast. Bijwerken (`--update` of de knop ⬆) verandert nooit iets aan de instellingen.

#### 8.2.1 Bijwerken vanuit RzMon ⬆
Na de eerste installatie (met het commando) werk je de agent bij met een knop:

- **Per server:** klap de server open → **Agent Manager** → **⬆ Bijwerken naar …**. Daar staat ook hoe het afliep.
- **Allemaal tegelijk:** *Instellingen → ⬆ Agents bijwerken* → **Alle verouderde agents bijwerken**.
- **Automatisch:** zet op dezelfde kaart **Agents automatisch bijwerken** aan. Een verouderde agent werkt zichzelf dan bij zonder knop, met hooguit één poging per 6 uur.

Zo gaat het:
1. RzMon geeft de opdracht mee in het antwoord op het volgende rapport van de agent (binnen een minuut). RzMon hoeft de server dus niet zelf te kunnen bereiken.
2. De agent haalt de nieuwe agent en `rzmon-agent-setup` op **bij je eigen RzMon** en controleert ze:
   - het **SHA-256-controlegetal** dat RzMon meestuurt;
   - of het geldige scripts zijn (`bash -n`);
   - of het echt de gevraagde versie is.
   Klopt er iets niet, dan installeert hij niets en meldt hij de reden.
3. Daarna doet `rzmon-agent-setup --update` de rest, net als met de hand. De instellingen in `/etc/default/rzmon-agent` blijven staan. De systemd-service is afgeschermd (`/usr` alleen-lezen), daarom loopt de update als aparte taak via `systemd-run`. Met cron gebeurt het direct.
4. Bij het volgende rapport zie je in RzMon **✔ bijgewerkt van 1.18.0 naar 1.19.0**, of **✘** met de reden.

Goed om te weten:
- **Eenmalig met de hand:** agents van vóór 1.19.0 kennen de knop nog niet. Werk ze één keer bij met `sudo rzmon-agent-setup --update`; daarna gaat het met de knop. RzMon zegt het als dat nodig is.
- **Uitzonderen:** wil je een server alleen met de hand bijwerken, zet dan `REMOTE_UPDATE=0` in `/etc/default/rzmon-agent`. De knop geeft dan aan dat bijwerken op afstand uit staat.
- **Home Assistant-add-on:** bijwerken gaat via de add-on winkel.
- **Veiligheid:** RzMon kan een agent alleen laten bijwerken met het agentscript van RzMon zelf; andere commando's zijn er niet. Wie beheerder is in RzMon, bepaalt wel welke agent er op je servers draait. Bescherm je beheerdersaccounts dus goed (sterk wachtwoord, 2FA).
- **Mislukt?** Kijk op de server met `journalctl -u "rzmon-agent-update-*"` (systemd) of voer `sudo rzmon-agent-setup --update` uit; dan zie je alle stappen.

#### Hoe vaak rapporteert de agent? ⏱
De agent is **geen daemon**: hij wordt steeds opnieuw gestart, maakt een rapport en stopt weer. Zo kan hij niet vastlopen of geheugen lekken, en is een update meteen actief.

| Systeem | Hoe hij start |
|---|---|
| Linux met systemd | systemd-timer `rzmon-agent.timer`, elke **30 s** |
| Linux zonder systemd, TrueNAS SCALE | cron, elke **minuut** |
| Home Assistant-add-on | een lus in de add-on, elke **30 s** |

Hoe vaak er echt een **rapport** uitgaat, de **tik**, stel je in RzMon in. Is de tik nog niet om, dan stopt de agent meteen weer (dat kost een paar milliseconden).
- **Standaard voor alle agents:** *Instellingen → ⬆ Agents bijwerken → Agents rapporteren standaard elke …*: 30 s, 60 s (standaard), 2 min of 5 min.
- **Per server:** *Agent Manager → ⏱ Rapporteren elke …*. *standaard* volgt de algemene instelling.
- De agent neemt een nieuwe tik binnen een minuut over. Bij cron en op TrueNAS is één minuut het minimum.
- **Agent offline** volgt de tik: een agent geldt pas als stil na minstens 3 gemiste tikken.
- `INTERVAL` in `/etc/default/rzmon-agent` (of `--interval` bij de setup) geldt alleen nog als RzMon geen tik doorgeeft, bijvoorbeeld als RzMon niet bereikbaar is.

**Interval per check:** zware onderdelen voert de agent alleen uit als de check aan de beurt is (het *Interval* in het checkformulier). Tussendoor stuurt hij het vorige resultaat mee:
- de **diepere controle** van een service (poorten, protocoltest, fouten in het log, eigen test) en de **extra test** (URL of poort);
- **logbestanden doorzoeken**: er gaat niets verloren, want de volgende keer worden alle nieuwe regels sinds de vorige keer geteld;
- **commando-checks** (al zo).

Of een service draait, CPU, RAM, disks en poorten worden elke tik gemeten. Een service die stopt, zie je dus altijd bij de volgende tik.

**Meteen beoordeeld:** de agent-checks van een server worden beoordeeld zodra het rapport binnen is, niet pas bij de volgende cron-minuut van RzMon. Met een tik van 30 s komt een melding dus hooguit ongeveer 30 s na het probleem (plus je eigen *pas melden na …*). Ping-, poort-, HTTP- en certificaatchecks doet RzMon zelf, elke minuut.

> **Bestaande installaties:** de timer van 30 s komt mee met de agent-update (⬆ Bijwerken). Tot die tijd rapporteert de agent zoals zijn oude timer hem start (meestal elke 60 s).

#### Realtime-watcher ⚡
Normaal ziet RzMon een gebeurtenis bij de volgende tik. Met **realtime** weet RzMon het binnen een paar seconden:

| Wat | Hoe de watcher het ziet |
|---|---|
| SSH-login of mislukte poging, sudo, su, console | het systemlog live volgen (`journalctl -f`; zonder journald het logbestand uit `AUTH_LOGS`) |
| Een bewaakte service stopt, crasht of wordt herstart | meldingen van systemd in het systemlog |
| Nieuw of gewijzigd bestand onder een **🔴-pad** van *Bestanden bewaken* (IDS) | inotify (`inotifywait`) |

- **Aanzetten:** per server in de *Agent Manager* → **⚡ Realtime: aan**. Standaard staat het uit. De agent neemt het binnen een minuut over.
- **Hoe het werkt:** de dienst `rzmon-watch` analyseert zelf niets. Hij let alleen op, en start bij een gebeurtenis meteen een gewone agent-run. Die verwerkt alles zoals altijd (logins, brute force, IDS, services, bestanden), en RzMon beoordeelt het rapport direct. Hooguit één extra run per 10 seconden, ook tijdens een brute-force-aanval.
- **Status:** in de *Agent Manager* staat *⚡ Realtime actief · systemlog ✔ · bestanden ✔*, en op de IDS-pagina *⚡ realtime* bij de server.
- **Dienst:** `rzmon-watch` wordt bij het installeren of bijwerken van de agent aangemaakt (alleen met systemd). Hij draait altijd, maar doet niets zolang realtime uit staat. Stopt hij, dan start systemd hem opnieuw; en valt hij helemaal weg, dan doet de gewone timer alles nog, alleen trager. Bekijken: `systemctl status rzmon-watch`.
- **inotify-tools** (voor bestanden) installeert de setup automatisch, bij elke installatie en elke update. Op Rocky, Alma, RHEL en Oracle Linux staat het in **EPEL**; de setup kijkt of EPEL er is en installeert het zo nodig eerst:
  - Rocky, Alma: `dnf install epel-release` (uit de standaardrepository *extras*);
  - RHEL: het officiële pakket van het Fedora-project (`epel-release-latest-<versie>.noarch.rpm`);
  - Oracle Linux: `oracle-epel-release-el<versie>`.

  CRB (CodeReady Builder) wordt niet aangezet; dat is voor `inotify-tools` niet nodig. Lukt het niet, bijvoorbeeld zonder internet, dan gaat de installatie gewoon door. Wil je geen EPEL: `--no-epel` bij de setup, of `EPEL=0` in `/etc/default/rzmon-agent`.
- **Melding in RzMon:** ontbreekt EPEL of `inotify-tools`, dan staat er in de *Agent Manager* een waarschuwing met de oplossing: **⬆ Opnieuw installeren** (de agent doet het dan zelf, als de server internet heeft), of het commando voor op de server. Op de IDS-pagina staat bij de server *EPEL ontbreekt*. Zonder inotify-tools werkt realtime voor logins en services gewoon; bestanden worden dan bij de gewone runs gecontroleerd.
- **Op een server altijd uit:** `REALTIME=0` in `/etc/default/rzmon-agent`.
- **Niet beschikbaar** via cron of op TrueNAS SCALE (daar kun je geen blijvende eigen dienst toevoegen).
- **Home Assistant-add-on:** daar kijkt de add-on elke 5 seconden of er iets verandert aan de add-ons, Core of de boot-tijd van HA OS; zo ja, dan volgt meteen een rapport.

#### 8.2.2 Services kiezen in RzMon
Welke services de agent bewaakt, stel je in RzMon in, niet meer op de server:

1. Klap de server open → **＋ Check** → type **Service**.
2. Onder *Servicenaam* staat een lijst van de services op die server (die aan staan bij het opstarten, of draaien), met hun status: 🟢 actief, 🔴 mislukt, ⚪ uit. Typ om te zoeken en klik om te kiezen. Services die al een check hebben, staan grijs.
3. Opslaan. Binnen ongeveer 2 minuten bewaakt de agent de service, inclusief de diepere controle (8.7).

Verwijder je de check, dan houdt de agent ermee op. De agent vraagt alleen de **status** op (`systemctl is-active` en `systemctl show`); RzMon kan op de server niets starten of stoppen.

Staat een service niet in de lijst (bijvoorbeeld omdat hij uit staat), typ dan zelf de naam. Een agent van vóór 1.20.0 stuurt nog geen lijst: werk hem bij met **⬆ Bijwerken** (8.2.1).

> `SERVICES` in `/etc/default/rzmon-agent` is **verouderd**. Een bestaande lijst blijft werken: die services worden óók gemeld. Je kunt hem weghalen zodra je voor die services een check in RzMon hebt.

### 8.3 Goed om te weten
- **Gegevens:** CPU, RAM, alle echte mounts, de gekozen services, de luisterende TCP-poorten, de vervaldatum van de gekozen certificaten en, als podman er staat, de containers (8.6).
- **Netwerk:** de agent stuurt zelf naar RzMon. Op de bewaakte server hoeft dus geen poort open; die server moet RzMon wel kunnen bereiken op de *Basis-URL* uit Setup.
- **Root:** de agent draait als root om procesnamen bij poorten te zien. De systemd-service is afgeschermd (`ProtectSystem`, `NoNewPrivileges`); de agent leest alleen en schrijft niets.
- **Token gelekt?** Klik op *Agent Manager* → **↻ Nieuw token** en voer op de server `sudo rzmon-agent-setup --token <nieuw token>` uit.
- **Foutmeldingen** tijdens het installeren:
  - `403` → het token klopt niet, of de server staat niet op type *Remote met agent*;
  - "kan niet downloaden" → het adres klopt niet, of RzMon is niet bereikbaar vanaf die server;
  - certificaat → `--insecure`.

### 8.4 Komt er niets binnen? Verbindingstest 🩺
**In RzMon:** klap de server open → **Agent Manager**. Bovenaan staat een live-status, die elke 5 seconden wordt bijgewerkt:
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
Met een oudere agent-versie: eerst bijwerken met de knop in RzMon (8.2.1) of `sudo rzmon-agent-setup --update`, of het installatiecommando opnieuw uitvoeren.

<details>
<summary>Handmatig installeren (zonder het script)</summary>

```bash
curl -fsSL 'http://192.168.1.10/api.php?action=agent_script' -o rzmon-agent.sh
sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
printf 'MONITOR_URL="%s"\nTOKEN="%s"\n' 'http://192.168.1.10' '<token>' | sudo tee /etc/default/rzmon-agent >/dev/null
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

**Rootless containers:** naast de containers van root zoekt de agent gebruikers die zelf containers hebben (in `~/.local/share/containers`). Een gebruiker die podman ooit gestart heeft maar geen containers heeft, telt niet mee. De containers staan in RzMon als `gebruiker/naam`.
- Andere keuze: zet `PODMAN_USERS="jan piet"` in `/etc/default/rzmon-agent` voor een vaste lijst, of `PODMAN_USERS=""` voor alleen root. `PODMAN=0` zet podman helemaal uit.
- **CPU en RAM van rootless containers** vereisen cgroups v2; dat is standaard op Rocky/RHEL 9 en 10, Debian 11+ en Ubuntu 22.04+.
- **Lingering:** zet voor die gebruiker *lingering* aan (`sudo loginctl enable-linger jan`), anders stoppen de containers als de gebruiker uitlogt. Dat is sowieso aan te raden.
- **systemd-service:** het installatiescript zet op servers met podman `NoNewPrivileges` en `ProtectHome` uit, omdat rootless podman die nodig heeft. Na het bijwerken van een bestaande agent: `sudo rzmon-agent-setup --update`.

**In RzMon:** klap de server open en klik op **📦 Containers**. Je ziet alle containers met status, CPU, RAM en disk.
- **Eén check per container.** Klik bij een container op **＋ Bewaken**. De status wordt altijd bewaakt; vink daarnaast **CPU**, **RAM** en/of **disk** aan, elk met eigen drempels. Wordt een container al bewaakt, dan staat er bijvoorbeeld **✓ status, CPU, RAM ✎**; klik om aan te passen.
- Bovenaan staan **＋ Podman-check** (algehele status) en **＋ Alle containers bewaken** (alleen de status; resources vink je daarna per container aan).
- Je kunt het ook via **＋ Check** doen: kies het type *Container (Podman)* en de container uit de lijst.
- Op het dashboard staat per container **één rij**, bijvoorbeeld *adguardhome draait (gezond) · CPU 2% · RAM 1,6 GB (43,8%) · disk 25,6 kB*. Gaat er iets over een drempel, dan staat dat vooraan: *⚠ RAM 87% (warning vanaf 85%)*. De status van de rij is de slechtste van de onderdelen, en je krijgt één melding per container.
- **Grafiek (📈):** per bewaakte meting een eigen grafiek onder elkaar, elk met zijn drempels.
- Had je (vóór 1.23.0) losse checks per meting, dan zijn die bij de update per container samengevoegd. De drempels en de geschiedenis zijn meegenomen.

| Check | OK | WARNING | CRITICAL |
|---|---|---|---|
| Podman (algemeen) | Alles in orde | Container gestopt met foutcode, ongezond of gepauzeerd; pod niet in orde | Podman reageert niet |
| Container – status | Draait | Gepauzeerd, of in het afgelopen kwartier automatisch herstart (crash) | Gestopt, ongezond (healthcheck faalt), of bestaat niet meer |
| Container – CPU / RAM / disk (aangevinkt) | Onder de drempels | Boven een warning-drempel | Boven een critical-drempel. CPU en RAM tellen alleen als de container draait |

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


### 8.8 Logbestanden doorzoeken 🔎📜
De agent kan logbestanden doorzoeken op bijvoorbeeld **mislukte logins** of **hardwarefouten**. Je stelt alles in RzMon in: klik bij de server op **＋ Check** en kies *Logbestand (zoeken)*.

1. **Voorinstelling:** kies er een, of *Zelf instellen*. Een voorinstelling vult de bron, het zoekpatroon, de drempels en de naam in; daarna kun je alles nog aanpassen.
2. **Zoek naar:** een zoekpatroon (regex). Hoofdletters maken niet uit, en `|` betekent "of". Voorbeeld: `Failed password|Invalid user`. Gebruik `[0-9]` voor een cijfer; `\d` werkt niet.
3. **Bron:**
   - een logbestand, zoals `/var/log/secure` (Rocky) of `/var/log/auth.log` (Debian/Ubuntu);
   - `journal`: het hele systemlog;
   - `journal:sshd`: het log van één service;
   - `kernel`: kernelmeldingen, waar hardware- en diskfouten staan.
4. **Drempels:** het aantal treffers in de periode. Bijvoorbeeld warning bij 5 en critical bij 20 mislukte logins.

**De periode** is standaard **15 minuten** en stel je in onder *Instellingen → Alarmgedrag* (5 minuten tot 24 uur).

**Voorinstellingen**

| Voorinstelling | Bron | Zoekt naar |
|---|---|---|
| 🔐 Mislukte logins (SSH) | journal | `Failed password`, `Invalid user`, `authentication failure` |
| 🔐 Mislukte sudo / su | journal | `incorrect password attempt`, `FAILED SU` |
| 💾 Diskfouten | kernel | `I/O error`, ATA-fouten, EXT4/XFS/BTRFS-fouten, NVMe-timeouts |
| 🔥 Hardwarefouten | kernel | `Machine check`, `MCE`, `EDAC`, `PCIe Bus Error`, temperatuur |
| 💽 SMART-waarschuwingen | journal:smartd | `Prefailure`, `Currently unreadable`, `failed self-test` |
| 🧠 Geheugen vol | kernel | `Out of memory`, `oom-kill` |
| 💥 Programma's gecrasht | kernel | `segfault`, `general protection`, `core dumped` |
| ⚙️ Mislukte services | journal | `Failed to start`, `failed with result` |
| 🧱 Firewall-blokkades | kernel | `REJECT`, `DROP` (alleen als je firewall blokkades logt) |
| 🚫 Fail2ban-bans | /var/log/fail2ban.log | ` Ban ` |
| 🌐 Webserver 5xx / 404 / foutlog | /var/log/nginx/… | HTTP 5xx, 404 (scans), `[error]` |
| ✉️ Mail: mislukte logins | journal | `SASL … authentication failed` |
| 🗄️ Database: fouten | journal:mariadb | `[ERROR]`, `Access denied` |

Gebruik je httpd (Apache, standaard op Rocky) in plaats van nginx? Kies dan als bron `/var/log/httpd/access_log` of `/var/log/httpd/error_log`.

**Wat je ziet**
> 🟠 WARNING: 12 treffers in de laatste 15 min · meeste vanaf 185.220.101.2 (9×) · laatste: Failed password for root from 185.220.101.2 …

Klik op 📈 voor de grafiek van het aantal treffers en de **laatste 5 gevonden regels**.

**Hoe de agent het doet**
- Hij leest elke minuut alleen de **nieuwe** regels en onthoudt waar hij gebleven was. Bij een nieuwe zoekopdracht tellen oude regels niet mee.
- **Logrotatie** gaat goed: bij een nieuw bestand begint hij gewoon opnieuw. Een regel die nog half geschreven is, telt pas als hij af is.
- Bij het systemlog leest hij precies de periode sinds de vorige keer.
- Nieuwe en gewijzigde zoekopdrachten worden binnen 10 minuten opgepikt.

**Veiligheid**
- De agent leest alleen logbestanden binnen de mappen in `LOG_DIRS` (standaard `/var/log`). Ook een link vanuit `/var/log` naar een bestand daarbuiten wordt geweigerd.
- Andere mappen toestaan doe je **op de server**:
  ```bash
  echo 'LOG_DIRS="/var/log /opt/mijnapp/logs"' | sudo tee -a /etc/default/rzmon-agent
  ```
- Er worden alleen gevonden regels verstuurd (maximaal 5, ingekort), nooit hele logbestanden.

---

### 8.9 TrueNAS
**TrueNAS SCALE** (Linux) draait de agent op een eigen manier: `/usr` en `/etc` horen bij TrueNAS zelf, en wat je daar zet verdwijnt bij een TrueNAS-update. De installer zet de agent daarom op je **pool** en laat hem starten door een **cronjob van TrueNAS** zelf.

1. Voeg in RzMon een server toe met type **Remote met agent**.
2. Open de **Agent Manager** en kies het tabblad **🗄️ TrueNAS SCALE**. Kopieer het commando (adres en token zijn al ingevuld):
   ```bash
   curl -fsSL 'http://RZMON-ADRES/api.php?action=agent_install' | sudo bash -s -- --url 'http://RZMON-ADRES' --token 'TOKEN' --truenas
   ```
3. Voer het uit in TrueNAS onder **System → Shell** (of via SSH). Het script:
   - kiest de eerste pool (niet `boot-pool`) en zet alles in `/mnt/<pool>/rzmon-agent`. Een andere pool kies je met `--pool naam`, een eigen map met `--dir /mnt/…`;
   - schrijft de instellingen (`agent.conf`, alleen leesbaar voor root);
   - maakt via de TrueNAS-API de cronjob **RzMon agent** aan: als root, elke minuut, met *Hide Standard Output/Error* aan. Je ziet hem onder *System → Advanced → Cron Jobs*;
   - stuurt een testrapport naar RzMon.

Had je de agent al met de hand geïnstalleerd (map, `agent.conf` en een cronjob met de naam *RzMon agent*)? Dan worden je instellingen en die cronjob overgenomen.

Goed om te weten:
- **Bijwerken:** met de knop in RzMon (*Agent Manager* of *⬆ Agents bijwerken*), net als op andere servers. Met de hand: `bash /mnt/<pool>/rzmon-agent/rzmon-agent-setup --update`.
- **Testen:** `bash /mnt/<pool>/rzmon-agent/rzmon-agent-setup --test`. **Verwijderen:** `… --uninstall`; dat haalt ook de cronjob weg.
- **Lukt de cronjob via de API niet**, dan zegt het script dat en toont het het commando om hem zelf aan te maken.
- **Disks:** je pools staan onder `/mnt/…`, bijvoorbeeld een diskcheck op `/mnt/tank`.
- **Pools bewaken:** voeg een **Commando**-check toe (§8.10) met `zpool status -x` en verwacht antwoord `all pools are healthy`.
- **Apps:** TrueNAS SCALE 24.10 en nieuwer gebruikt Docker; de agent kent alleen Podman, dus apps worden niet gemeld.
- **TrueNAS CORE** (FreeBSD) kan de agent niet draaien. Voeg hem toe als **Remote** met ping, poorten (445 SMB, 2049 NFS, 22 SSH) en HTTP(S) op de webinterface.

### 8.10 Commando-checks 🖥️
Met een **Commando**-check voert de agent een commando uit op de server, en vergelijkt RzMon de uitvoer met het antwoord dat je verwacht. Voorbeelden:

| Commando | Goed als de uitvoer | Verwacht antwoord |
|---|---|---|
| `zpool status -x` | is gelijk aan | `all pools are healthy` |
| `systemctl is-active nginx` | is gelijk aan | `active` |
| `cat /sys/class/thermal/thermal_zone0/temp` | komt overeen met (regex) | `^[1-6][0-9]{4}$` (onder 70 °C) |
| `! test -f /var/run/reboot-required` | exitcode is 0 | *(niet nodig)*: OK zolang er geen herstart nodig is |
| `curl -s localhost:8080/health` | bevat | `"status":"up"` |

**Eerst aanzetten.** Standaard voert geen enkele agent commando's uit. Zet het aan onder **Instellingen → ⬆ Agents bijwerken → 🖥️ Commando-checks**:
- *Uit* (standaard);
- *Toestaan, als gebruiker nobody*: voor de meeste commando's genoeg;
- *Toestaan, als root*: alleen als het echt nodig is.

Per server kun je dat anders instellen in de **Agent Manager** (*Commando-checks op deze server*). Ruimer maken (uit → nobody → root) vraagt om je wachtwoord, en elke wijziging komt in het **logboek** (ook het aanmaken en wijzigen van commando-checks).

**Op de server zelf** kun je het altijd inperken, wat RzMon ook zegt. Zet daarvoor in de instellingen van de agent (`/etc/default/rzmon-agent`, of `agent.conf` op TrueNAS):
- `COMMANDS=0`: nooit commando's uitvoeren;
- `COMMANDS=nobody`: nooit als root.

Deze regels blijven staan als je de agent opnieuw installeert.

**Voorinstellingen:** in het checkformulier kies je bij *Voorinstelling* een kant-en-klare check; alles wordt ingevuld en je kunt het daarna nog aanpassen. De groep die bij de server past (herkend aan zijn services) staat bovenaan:

| Groep | Voorinstellingen |
|---|---|
| 🗄️ TrueNAS / ZFS | ZFS-pools gezond · pools onder 80% vol · scrub zonder fouten · geen TrueNAS-meldingen (root) · apps (Docker) draaien (root) |
| 🖥️ Proxmox VE | cluster heeft quorum (root) · opslag onder 85% vol (root) · laatste back-uptaak geslaagd (root) · Ceph gezond (root) · geen Proxmox-updates |
| 🐧 Linux | geen mislukte services · geen herstart nodig (Debian/Ubuntu, of Rocky/RHEL met root) · updates (Debian/Ubuntu) · beveiligingsupdates (Rocky/RHEL, root) · klok gesynchroniseerd · inodes onder 90% · CPU-temperatuur · software-RAID heel · SMART (root) · Docker-containers draaien (root) |

Voorinstellingen met *(root)* werken alleen als commando's op die server *als root* mogen; het formulier waarschuwt als dat niet zo is. De voorinstellingen voor TrueNAS en Proxmox zijn nog niet op een echt systeem getest. Klopt er een niet, laat het weten.

**＋ Aanbevolen checks** (knop in de *Agent Manager*): RzMon herkent of de server een TrueNAS, Proxmox of gewone Linux-server is (en Debian- of RHEL-familie), en toont een lijst met vinkjes. De aanbevolen checks staan al aangevinkt; checks die er al zijn worden overgeslagen. Met één klik worden ze allemaal toegevoegd.

**De check toevoegen:** *＋ Check → Commando*. Vul in:
- het **commando**, bijvoorbeeld `zpool status -x`;
- hoe het **vergeleken** wordt:
  - *is gelijk aan*: spaties en regeleindes aan begin en eind tellen niet mee;
  - *bevat*;
  - *komt overeen met (regex)*: zonder scheidingstekens;
  - *exitcode is 0*;
- het **verwachte antwoord**;
- of een afwijking **critical** of **warning** is;
- de **time-out** (1–60 s, standaard 10).

Hoe het werkt:
- De agent voert het commando uit in `bash`, in een kale omgeving, zonder invoer, met een time-out en standaard als `nobody`. Hij stuurt de uitvoer (hooguit 4 KB) en de exitcode naar RzMon. Daar wordt vergeleken, dus een ander verwacht antwoord werkt meteen.
- Het commando draait volgens het **interval** van de check. Alle commando's samen hooguit ongeveer 40 seconden per rapport.
- Op het dashboard zie je wat er terugkwam, bijvoorbeeld *Antwoord: "degraded" – verwacht: is gelijk aan "all pools are healthy"*. Ook een time-out, *commando niet gevonden* en *geblokkeerd op de server* worden duidelijk gemeld.
- In de verbindingstest (`rzmon-agent-setup --test`) staat per commando-check de exitcode, de tijd en als wie hij draaide.
- Niet in de Home Assistant-add-on.
- **Gebruik bij voorkeur HTTPS naar RzMon.** De lijst met commando's gaat van RzMon naar de agent; over gewone HTTP kan iemand op je netwerk die onderweg aanpassen.

### 8.11 Logins en sudo 🔑
De agent meldt wie er op een server inlogt en wie `sudo` of `su` gebruikt. Je ziet het op de pagina **🛡️ IDS**, tabblad **🔑 Toegang** (tot en met 1.37.0 stond het op de pagina Meldingen; bij de update naar 1.38.0 verhuizen de bestaande regels mee). Er gaat geen Pushover-, mail- of Home Assistant-bericht uit.

Op het tabblad **Toegang**:
- **Per dag** een uitklapblok met tellers (🔑 gelukt, ❌ mislukt). *Vandaag* staat open.
- **Filters:** *Alles · SSH · Console · sudo · su · ❌ Mislukt*, en een keuzelijst per server.
- **Sorteren:** klik op een kolomkop (*Tijd, Soort, Server, Wie, Wat / vanaf*): oplopend ▲, aflopend ▼, de derde klik zet de standaard terug (nieuwste eerst). Per apparaat onthouden.
- **🙈 Negeer:** kies de gebruiker(s) en/of het IP-adres van die regel. Die komen op de lijst *Negeren*, en ook de regels die er al staan verdwijnen.
- **⛔ Blokkeer:** bij een regel met een IP-adres; opent het venster *IP blokkeren* met het IP en de server al ingevuld (zie §8.12).

| Gebeurtenis | Voorbeeld |
|---|---|
| SSH-login | 🔑 *SSH · rehman* op RL02, vanaf 192.168.2.10 (sleutel) |
| Console-login | 🔑 *Console · root* op RL03, aan de console |
| sudo | 🔑 *sudo · rehman* op RL02: als root `systemctl restart nginx` · in /home/rehman · pts/0 |
| su | 🔑 *su · rehman → root* op RL02 |
| Mislukte SSH-login | ❌ *37× mislukte SSH-login* op RL02, vanaf 45.1.2.3 · gebruikers: root, admin, test |
| Mislukte sudo of su | ❌ *mislukte sudo · gast*: 3 incorrect password attempts · als root: `cat /etc/shadow` |

Goed om te weten:
- **Aan/uit:** *Instellingen → 🛡️ IDS → Toegang bijhouden* (standaard aan). Dit staat los van *IDS aan*: logins worden ook bijgehouden als het IDS uit staat. Op een server zelf zet je het altijd uit met `AUTH_EVENTS=0` in de instellingen van de agent.
- **Negeren:** in hetzelfde blok vul je gebruikers en/of IP-adressen of netwerken in die niet getoond worden (of klik 🙈 bij een regel), bijvoorbeeld `ansible backup 192.168.2.5 10.0.0.0/8`. Een mislukte poging wordt alleen overgeslagen als *alle* gebruikers erin genegeerd zijn, of het IP-adres.
- **Mislukte pogingen** worden opgeteld: per server, soort en IP-adres (of gebruiker) komt er per uur één regel, die bijgewerkt wordt (*37×*). Zo loopt de pagina niet vol als een server vanaf internet aangevallen wordt.
- **Wachtwoorden** in sudo-commando's worden al op de server gemaskeerd, bijvoorbeeld `mysql -p••••` en `password=••••`.
- **su vanuit diensten** (bijvoorbeeld logrotate) wordt niet gemeld; alleen su door een echte gebruiker.
- **Bron:** het systemlog (journal), of zonder journald `/var/log/secure` (Rocky) of `/var/log/auth.log` (Debian). Een ander bestand kies je met `AUTH_LOGS="/pad/naar/log"`; met `AUTH_SOURCE=file` gebruikt de agent het bestand ook als er een journal is.
- **Bij de eerste keer** begint de agent bij *nu*: oude logins komen er niet in.
- **Bewaren:** net zo lang als de andere meldingen (*Meldingen bewaren (dagen)*).
- **Werkt op** Linux-servers en TrueNAS SCALE, niet in de Home Assistant-add-on. Agent 1.33.0 of nieuwer nodig.

### 8.12 IDS: inbraakdetectie 🛡️
De agent let op tekenen van een inbraak en RzMon vergelijkt dat met wat normaal is op die server. Alles komt op de pagina **🛡️ IDS**. Bij *blokkeren* zet de agent aanvallende IP-adressen ook echt in de firewall van die server.

**Aanzetten** gaat op *Instellingen → 🛡️ IDS*:
- **IDS aan/uit**: standaard **uit**. Uit = de agents verzamelen niets en er wordt niets geblokkeerd.
- **🎓 Leren / ⛔ Blokkeren**:
  - **Leren** (standaard): alles wordt gemeld, er wordt niets geblokkeerd.
  - **Blokkeren**: brute force wordt automatisch geblokkeerd, en de knop **⛔ Blokkeer IP** werkt echt. Overschakelen vraagt je wachtwoord (op je LAN: typ `BLOKKEREN`) en komt in het logboek.

Begin met *leren* en kijk een paar dagen wat er gemeld wordt. Schakel pas over naar *blokkeren* als het klopt, en doe dat de eerste keer terwijl je ook via de console bij de server kunt.

**Wat wordt gemeld**

| Melding | Ernst |
|---|---|
| Nieuw of gewijzigd bestand: `/etc/sudoers(.d)`, `/root/.ssh/authorized_keys`, `/etc/ld.so.preload` | 🔴 critical |
| Nieuw of gewijzigd bestand: `/etc/passwd`, `shadow`, `group`, sshd-config, crontabs en `/etc/cron.d`, `authorized_keys` van gebruikers, eigen systemd-services en -timers, `rc.local`, `/etc/profile.d` | 🟠 warning |
| Tweede root-account (UID 0) | 🔴 critical |
| Nieuwe gebruiker | 🟠 warning |
| Nieuwe luisterende poort | 🟠 warning |
| Nieuw SUID-programma, nieuwe kernelmodule, gewijzigd programmabestand van een pakket (`rpm -Va` / `debsums`, dagelijks) | 🟠 warning |
| Programma dat draait vanuit `/tmp`, `/dev/shm` of `/var/tmp`, of van een verwijderd bestand | 🔴 critical |
| Programma zonder bestand (memfd) | 🟠 warning |
| Mogelijke reverse shell: een shell of `nc`/`socat` met een verbinding naar buiten | 🔴 critical |
| Verbinding naar een bekende malware-poort (1337, 4444, 31337 …) | 🟠 warning |
| Brute force op SSH: standaard 10 mislukte logins binnen 10 minuten vanaf één IP | 🟠 warning |
| **Inlog na brute force**: eerst veel mislukte pogingen, daarna een geslaagde login vanaf hetzelfde IP | 🔴 critical |
| Root-login via SSH, login vanaf een IP dat nog nooit eerder inlogde | 🟠 warning |

- **🔴 Critical** gaat ook naar **Pushover**, ook tijdens stille uren, en komt op de pagina Meldingen. **🟠 Warning** staat alleen op de pagina IDS.
- Een rood bolletje op het tabblad **🛡️ IDS** telt de open critical incidenten.
- De pagina heeft drie tabbladen: **⚠️ Incidenten**, **🔑 Toegang** (logins, sudo en su, zie [8.11](#811-logins-en-sudo-)) en **⛔ Blokkades**. Het laatst gekozen tabblad wordt per apparaat onthouden.
- Dezelfde melding binnen 24 uur wordt niet opnieuw gestuurd; de teller loopt op (*5×*).

**Basismeting**
- Als het IDS voor het eerst een rapport van een server krijgt, legt RzMon eerst vast wat er normaal is: standaard **24 uur** (*Basismeting (uren)*). Wat er in die tijd verandert, wordt stil opgenomen.
- **Dit is normaal** bij een incident: afhandelen, en die melding komt op die server niet meer terug.
- **↺ basismeting opnieuw** (bij de server bovenaan de pagina IDS): na een grote wijziging, bijvoorbeeld een upgrade, alles opnieuw als normaal vastleggen.
- Installeer je een pakket of werk je de server bij, dan zie je soms meldingen over gewijzigde bestanden. Klopt het, kies dan **✔ Afhandelen**.

**Blokkeren**
- De agent gebruikt een **eigen nftables-tabel** (`inet rzmon`), naast firewalld of je eigen regels. Je bestaande firewall wordt niet aangepast. Bekijken op de server: `nft list table inet rzmon`.
- Er wordt alleen geblokkeerd **op de server die aangevallen wordt**.
- **Automatisch** bij brute force: **24 uur** (*Blokkeren (uren)*). Was hetzelfde IP de afgelopen **30 dagen** al eens geblokkeerd, dan **7 dagen** (*Bij herhaling (dagen)*). Permanent blokkeren gaat alleen met de hand.
- **Handmatig** met **⛔ Blokkeer IP** bij een incident of een regel in *Toegang*, of met de knop **⛔ IP blokkeren** bovenaan de pagina IDS (vul zelf een IP-adres in): 24 uur, 7 dagen, 30 dagen of permanent.
- **Nooit automatisch geblokkeerd:** je LAN (privé-adressen), RzMon zelf en de lijst *Nooit blokkeren*. Een LAN-adres kun je wel zelf blokkeren; je eigen IP-adres niet.
- **🔓 Deblokkeren** in het blok *Blokkades*. De agent past het binnen een minuut aan. Eerdere blokkades staan onder *Eerder geblokkeerd*.
- **Terug naar leren** of **IDS uit**: de agent haalt zijn tabel meteen weg, alle blokkades vervallen op de servers. In RzMon blijven ze staan, zodat ze terugkomen als je weer blokkeert.
- **Op een server nooit blokkeren:** zet `IDS_BLOCK=0` in `/etc/default/rzmon-agent`. Die server meldt dan wel, maar blokkeert niet.
- Bovenaan de pagina IDS zie je per server of de firewall werkt: *✔ bewaakt · blokkeren actief*, *alleen melden*, *alleen detecteren* of een foutmelding.
- **Docker en Podman:** verkeer naar containers met gepubliceerde poorten loopt via *forward*, niet via *input*. Een blokkade geldt dus voor de server zelf (SSH en diensten op de host), niet altijd voor containers.

**📁 Bestanden bewaken**

Naast de vaste gevoelige bestanden kun je zelf paden laten bewaken, bijvoorbeeld je eigen scripts, je webroot of configuratie. De agent houdt per bestand de **SHA-256-hash**, de **grootte**, de **rechten** en de **eigenaar** bij. De inhoud wordt nooit verstuurd, dus ook geen wachtwoorden uit configbestanden.

- **Instellen:** *Instellingen → 🛡️ IDS → 📁 Bestanden bewaken*: een pad per regel, met de ernst 🟠 warning of 🔴 critical. Die lijst geldt voor alle servers. Per server voeg je er paden aan toe via *✎ Bewerken* van de server (*📁 Bestanden bewaken (IDS)*); hetzelfde pad daar gaat voor, bijvoorbeeld om de ernst te veranderen.
- **Paden:**
  - een map, bv. `/usr/local/bin`, wordt helemaal doorzocht, ook de submappen;
  - een bestand, bv. `/etc/nginx/nginx.conf`;
  - jokertekens: `*` en `**` (elke diepte), bv. `/var/www/**/*.php`.
  - Niet toegestaan: `/` zelf, `/proc`, `/sys`, `/dev` en `/run`. Het veld wordt dan rood.
- **＋ Aanbevolen:** de agents melden welke gangbare mappen er op de server staan (`/usr/local/bin`, `/opt`, `/etc/nginx`, `/var/www`…). Je vinkt aan wat je wilt bewaken; bij een webroot wordt dat `…/**/*.php` als 🔴, om webshells te vangen.
- **Uitsluiten:** namen (`*.log`), mappen (`cache/`) of paden, gescheiden door spaties. Standaard: `*.log *.tmp *.swp *.pid cache/ sessions/`.
- **Grenzen:** maximaal 20 paden in de algemene lijst (en 20 per server) en 10.000 bestanden per server. Bestanden groter dan 100 MB krijgen geen hash; daarvan worden alleen de grootte en de tijd gecontroleerd.

**Hoe vaak:** elke minuut een snelle controle op grootte, tijd, rechten en eigenaar. Alleen bij een verschil maakt de agent een nieuwe hash. Daarnaast hasht hij één keer per dag op de achtergrond alles opnieuw. Zo vangt hij ook een bestand waarvan iemand de inhoud heeft veranderd en de tijd heeft teruggezet.

**Wat er gemeld wordt:**

| Wijziging | Ernst |
|---|---|
| Nieuw bestand | die van het pad |
| Inhoud gewijzigd (oude en nieuwe hash, grootte) | die van het pad |
| Verwijderd | die van het pad |
| Rechten of eigenaar gewijzigd | die van het pad; 🔴 als er SUID of SGID bij kwam (bv. `0755 → 4755`) |
| Meer dan 10 wijzigingen onder één pad tegelijk | één incident *"23 bestanden gewijzigd in …"* met de lijst |

- **✔ Wijziging goedkeuren** (of **✔ Alles goedkeuren** bij een bundel): de nieuwe stand is de norm. Verandert het bestand daarna weer, dan komt er een nieuw incident.
- **🚫 Niet meer bewaken:** dit bestand voortaan overslaan. Bij een bundel: alle wijzigingen onder dat pad.
- **Basismeting:** de eerste keer, en bij een nieuw toegevoegd pad, wordt alles stil vastgelegd. **↺ basismeting opnieuw** legt ook de bewaakte bestanden opnieuw vast.
- Pakketupdates worden hier niet overgeslagen: in je eigen paden komen ze meestal niet.
- Bovenaan de pagina IDS staat per server hoeveel bestanden er bewaakt worden (*📁 18 bestanden bewaakt*).
- **Betrouwbaar:** de agent onthoudt de nieuwe stand pas als RzMon het rapport heeft ontvangen. Mislukt een rapport, dan komt de wijziging bij het volgende rapport alsnog binnen.
- Werkt op Linux-servers en TrueNAS SCALE met agent **1.40.0** of nieuwer, niet in de Home Assistant-add-on.

**Goed om te weten**
- Werkt op Linux-servers met agent **1.35.0** of nieuwer. Werk de agents bij met de knop in de Agent Manager.
- **TrueNAS SCALE: alleen detecteren.** De firewall hoort daar bij TrueNAS zelf; de agent blokkeert er niets.
- **Niet in de Home Assistant-add-on.** Die draait in een container en ziet de gebruikers, bestanden, processen en firewall van Home Assistant OS zelf niet. Voor Home Assistant: zet `ip_ban_enabled: true` en `login_attempts_threshold` onder `http:` in `configuration.yaml`, en gebruik tweestapsverificatie.
- De SUID-zoektocht draait één keer per uur en de pakketcontrole één keer per dag, op de achtergrond en met lage prioriteit. Container-opslag (`/var/lib/docker`, `/var/lib/containers`) wordt overgeslagen.
- Het IDS gebruikt de logins uit [8.11](#811-logins-en-sudo-) (journal of `/var/log/secure`). Dat werkt ook als *Toegang bijhouden* uit staat, en de lijst *Negeren* telt daarvoor niet mee.
- Incidenten worden net zo lang bewaard als de meldingen (*Meldingen bewaren (dagen)*).
- Een IDS ziet veel, maar niet alles. Houd je servers bijgewerkt, gebruik SSH-sleutels en zet `PermitRootLogin no`.

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
| Home Assistant → **Laatste back-up** | Dagen sinds de laatste geslaagde automatische back-up (standaard warning 2, critical 7); ook WARNING als de laatste poging mislukt is. Nodig: HA 2025.2+ met automatische back-ups (*Instellingen → Systeem → Back-ups*) |
| Home Assistant → **Integraties** | Aantal integraties dat niet geladen is (fout bij instellen, opnieuw proberen, uitladen mislukt), met de namen in het bericht. Uitgeschakelde integraties tellen niet mee |
| Home Assistant → **Systeemstatus** | Reparaties van de Supervisor: *niet gezond* = CRITICAL; *niet ondersteund* of een melding (bv. weinig vrije ruimte) = WARNING |
| Home Assistant → **Onverwachte herstart (HA OS)** | **CRITICAL met een melding** als Home Assistant OS opnieuw is opgestart zonder OS-update (stroomuitval, crash of een handmatige herstart). Blijft een uur CRITICAL, daarna vanzelf OK. Een herstart door een OS-update staat er alleen bij (*16.2 → 16.3*) |
| Home Assistant → **Levensduur SD-kaart / eMMC** | Hoeveel procent van de opslag versleten is (standaard warning 75 %, critical 90 %). Alleen als je hardware dat meldt (eMMC zoals in de Green/Yellow/ODROID, sommige SD-kaarten en SSD's); anders ONBEKEND |
| Home Assistant → **OS-update mislukt** | CRITICAL als een OS-update niet wilde opstarten en HA OS is teruggevallen op de vorige versie (A/B-bootslots). Oudere HA OS-versies melden geen bootslots; een mislukte update staat dan bij *Systeemstatus* |
| Home Assistant → **USB-apparaat aanwezig** | Kies een apparaat (Zigbee- of Z-Wave-stick, Bluetooth) uit de lijst; is het weg, dan CRITICAL |
| **Containers** (📦) | Core, Supervisor, de plugins (DNS, Audio, CLI, Multicast, Observer) en elke add-on, met CPU en RAM. Zelfde check als bij Podman: status (gestopt met een fout = CRITICAL, net herstart door de watchdog = WARNING) en naar keuze CPU en RAM met drempels. Overzicht via de knop **📦 Containers** bij de server |
| Service `plugin:<naam>` | Draait een plugin van de Supervisor (`dns`, `audio`, `cli`, `multicast`, `observer`)? Plugin-updates staan ook bij `updates` |
| **Logbestand** (zoeken) | Bronnen `ha:host` (systeemlog van HA OS), `ha:core`, `ha:supervisor` en `ha:addon:<slug>`, met voorinstellingen voor te lage voeding (Raspberry Pi), diskfouten en fouten in Core of de Supervisor |

De Home Assistant-checks voeg je toe met **＋ Check → Home Assistant** en dan bij *Bewaken* wat je wilt. Services (Core, Supervisor, add-ons, plugins, Updates) kies je bij **＋ Check → Service** uit een lijst. Het snelst: *Agent Manager* → **＋ Aanbevolen checks**; dat stelt de checks hierboven voor, plus je USB-sticks en de belangrijkste containers en logs.

De add-on haalt back-up, integraties en systeemstatus elke 5 minuten op, de rest elke tik. Hij leest alleen. Voor de statistieken per container, de logs en de USB-apparaten heeft hij vanaf 1.42.0 de Supervisor-rol **manager** nodig; Home Assistant toont dat bij de add-on. Geeft je Home Assistant iets niet vrij, dan staat de check op ONBEKEND met de reden erbij.

**Herstarts van containers:** de Supervisor meldt geen herstarttellers. De add-on ziet een herstart aan de *watchdog* in het Supervisor-log (*"Watchdog found addon … is failed, restarting"*) en aan een container die tussen twee rapporten opnieuw is gestart. Een add-on die je zelf start of herstart, telt niet als automatische herstart.

> Nog niet op een echte Home Assistant getest, alleen met een nagebootste Supervisor. Zie je iets vreemds (bijvoorbeeld een herstart die niet klopt), laat het dan weten.

### Installeren
1. **RzMon:** **＋ Server** → naam "Home Assistant", adres = IP van HA, type **Remote met agent**. Kopieer het token via *Agent Manager*, tabblad **🏠 Home Assistant OS**.
2. **Add-on toevoegen aan Home Assistant.** Kies één van deze manieren:
   - **Via GitHub (aanbevolen):** *Instellingen → Add-ons → Add-on winkel* → ⋮ → **Repositories** → voeg `https://github.com/rembrand20/rzmon` toe. De repository moet openbaar zijn. Updates van de add-on komen dan vanzelf binnen.
   - **Samba share**-add-on: open `\\homeassistant.local\addons` (Windows) of `smb://homeassistant.local/addons` (Mac) en kopieer daar de map **`rzmon_agent`** uit `ha-addon/` naartoe.
   - **Advanced SSH & Web Terminal**-add-on: `scp -r ha-addon/rzmon_agent root@homeassistant.local:/addons/`
3. **Home Assistant:** *Instellingen → Add-ons → Add-on winkel* → rechtsboven ⋮ → **Controleren op updates**. **RzMon Agent** staat nu onder *RzMon add-ons* (via GitHub) of onder *Lokale add-ons* (via Samba/SSH). Klik op **Installeren**; het bouwen duurt een minuut.
4. **Tabblad Configuratie:**
   ```yaml
   monitor_url: http://192.168.1.10
   token: <token uit RzMon>
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
- **Rechten:** de add-on gebruikt alleen leesfuncties. Hij heeft de Supervisor-rol `manager` (voor statistieken, logs en hardware) en het host-netwerk, en verandert niets aan je installatie.
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

### Onthoud mij op dit apparaat 📱
Zet bij het inloggen **Onthoud mij op dit apparaat** aan. Dan blijf je op dat apparaat ingelogd, ook na het sluiten van de browser of een herstart, en hoef je geen wachtwoord of 2FA-code meer in te vullen.
- **30 dagen:** gebruik je het apparaat 30 dagen niet, dan wordt het vergeten. Elk gebruik telt opnieuw vanaf nul.
- **Gekoppeld aan dat apparaat:** het apparaat krijgt een geheime sleutel in een beveiligde cookie, die alleen in die browser werkt. Wis je de cookies of gebruik je een privévenster, dan moet je opnieuw inloggen.
- **Veilig:** in de database staat alleen een hash van de sleutel. De sleutel wordt bij elk gebruik vervangen. Duikt een oude sleutel op (bijvoorbeeld een gestolen cookie), dan wordt het apparaat ongeldig.
- **Apparaten beheren:** klik bij je account onder *Instellingen → Gebruikers* op **📱**. Je ziet elk apparaat met naam, laatste gebruik en IP-adres, en kunt het **vergeten**. Een vergeten apparaat wordt ook direct uitgelogd, handig bij een verloren telefoon. Beheerders kunnen dit voor alle gebruikers.
- **Automatisch vergeten** bij uitloggen op dat apparaat, bij een wachtwoordwijziging (alle andere apparaten) en als een beheerder je wachtwoord reset.
- **Rechten:** je houdt de rechten van je account. Wijzigt een beheerder je rol, dan geldt die meteen.

### Welke rechten heb je op het lokale netwerk?
- **Standaard** kom je zonder wachtwoord binnen als **👁 Alleen lezen**: je kunt alles zien, maar niets veranderen. Heb je op dit apparaat *Onthoud mij* aan staan, dan kom je binnen met je eigen account.
- **Inloggen** (knop rechtsboven) logt je in met je eigen account, met de rechten van dat account. *Verder zonder inloggen* brengt je terug naar alleen lezen.
- **"Op het lokale netwerk als beheerder inloggen"** (onder *Toegang*, alleen te wijzigen door een beheerder): staat dit aan, dan kom je op het LAN zonder wachtwoord binnen als **beheerder**. Dat geldt alleen voor het lokale netwerk.
- **Van buitenaf (internet)** is altijd een gebruikersnaam en wachtwoord nodig, plus 2FA als die aan staat. Je krijgt dan de rechten van je account.

### Achter een reverse proxy
Gebruik je **Nginx Proxy Manager**, **Traefik**, **Caddy**, **Cloudflare Tunnel** of de HA-add-on *NGINX SSL proxy*? Dan komt elk verzoek binnen met het IP van de proxy.

- Vul het IP van de proxy in bij **Reverse proxy IP's**. RzMon gebruikt dan het echte bezoekers-IP uit `X-Forwarded-For`.
- Zet het proxy-IP **nooit** bij *Lokale netwerken*. Anders komt iedereen die via de proxy binnenkomt, dus ook van internet, zonder wachtwoord binnen.
- **Testen:** open RzMon via 4G op je telefoon. Je moet een loginscherm krijgen.

> ⚠️ Sommige routers geven bij port-forwarding álle bezoekers het IP van de router. Test daarom altijd via 4G nadat je deze optie aanzet.

### Gebruikers, rollen en tweestapsverificatie 🔐
Onder *Instellingen → Gebruikers* beheer je wie er mag inloggen, en met welke **rol**:

| Mag… | 🔑 Beheerder | 👤 Gebruiker | 👁 Alleen lezen |
|---|:-:|:-:|:-:|
| Dashboard, checks, grafieken, meldingen, containers bekijken | ✅ | ✅ | ✅ |
| Eigen wachtwoord en eigen 2FA | ✅ | ✅ | ✅ |
| Storing bevestigen, nu checken, onderhoud, poortscan | ✅ | ✅ | ❌ |
| Servers en checks inrichten, agents, instellingen, gebruikers, API-sleutels, Setup | ✅ | ❌ | ❌ |

- De rol kies je bij het aanmaken. Wijzigen gaat met de keuzelijst bij de gebruiker.
- Je kunt je eigen rol niet wijzigen, en er blijft altijd minimaal één beheerder.
- Knoppen die niet mogen, verdwijnen. Rechtsboven zie je *👤 Gebruiker* of *👁 Alleen lezen*.
- De server controleert de rechten ook zelf, dus ook via de API kan niemand meer dan zijn rol toestaat.

- **Gebruiker toevoegen:** vul een naam en wachtwoord in. Wil je meteen 2FA, zet dan *Direct tweestapsverificatie instellen* aan. Zonder 2FA is alleen het wachtwoord nodig.
- **2FA instellen:** klik bij de gebruiker op **🔐 2FA instellen** en scan de QR-code met een authenticator-app, zoals Google of Microsoft Authenticator, Authy, Bitwarden of 1Password. Lukt scannen niet, typ dan de sleutel eronder over. Vul de code in die de app toont: pas daarna staat 2FA aan, dus je kunt jezelf niet per ongeluk buitensluiten.
- **Inloggen met 2FA:** na het wachtwoord vraagt RzMon om de 6-cijferige code. Een code werkt maar één keer. Na 5 foute codes, of na 5 minuten, begin je opnieuw met je wachtwoord.
- **2FA resetten:** bijvoorbeeld bij een nieuwe of verloren telefoon. Klik op **2FA resetten**: die gebruiker logt dan weer in met alleen het wachtwoord, tot 2FA opnieuw is ingesteld.
- **🔑** stelt een nieuw wachtwoord in voor een gebruiker. **🗑** verwijdert een gebruiker; jezelf en de laatste gebruiker kun je niet verwijderen.
- **Op het lokale netwerk** blijft inloggen zonder wachtwoord werken als je dat aan hebt staan (als *alleen lezen*, zie hieronder); 2FA geldt voor inloggen met een account.

### Wachtwoord vergeten 🔑
Op het loginscherm staat **Wachtwoord vergeten?**.

1. Vul je **gebruikersnaam of e-mailadres** in. Je krijgt altijd dezelfde melding, of het account nu bestaat of niet; zo kan niemand uitzoeken welke accounts er zijn.
2. Heeft het account een e-mailadres, dan komt er een mail met een link. Die is **30 minuten** geldig en werkt **één keer**.
3. Via de link kies je een nieuw wachtwoord (twee keer). Heeft het account **tweestapsverificatie**, dan is ook de code uit de app nodig: alleen toegang tot iemands mailbox is dus niet genoeg.
4. Daarna:
   - worden alle apparaten met "onthoud mij" en andere open sessies van dat account uitgelogd;
   - krijg je een bevestigingsmail *"je wachtwoord is gewijzigd"*.

Wat ervoor nodig is:
- een **e-mailadres** bij het account: beheerders vullen het in bij *Instellingen → Gebruikers* (✉, of bij het aanmaken); iedereen kan zijn eigen adres instellen;
- een **SMTP-server** (*Instellingen → E-mail*; de schakelaar voor e-mailmeldingen hoeft niet aan);
- de **Basis-URL** (Setup), want daarmee wordt de link in de mail gemaakt.

Ontbreekt er iets, dan staat dat in rood bij *Gebruikers*. Op het loginscherm verschijnt dan *"Vraag een beheerder"*.

Beveiliging: hooguit 3 links per account per uur en 10 aanvragen per IP-adres per uur. RzMon bewaart alleen een hash van de link. Elke aanvraag staat in het **logboek** (*Gebruikers → Logboek bekijken*), met tijd, IP-adres en wat er gebeurde.

**Buitengesloten?** Op de server:
```bash
sudo -u apache php /var/www/rzmon/install/install.php --reset-password admin   # nieuw wachtwoord (Debian: www-data)
sudo -u apache php /var/www/rzmon/install/install.php --reset-2fa admin        # 2FA uitzetten (telefoon kwijt)
```

---

## 11. REST API en Home Assistant-integratie

### API-sleutel maken
**Instellingen → API-sleutels**: vul een naam in, bijvoorbeeld "Home Assistant", kies de rechten en klik op **＋ Sleutel maken**.

| Recht | Mag |
|---|---|
| 📖 **Status lezen** (`read`) | Servers, checks, meetwaarden en meldingen uitlezen. Standaard aangevinkt. |
| 🛡️ **IDS lezen** (`ids_read`) | Incidenten, blokkades en logins (sudo, su) van het IDS uitlezen. |
| ⛔ **IDS blokkeren** (`ids_block`) | IP-adressen blokkeren en deblokkeren op je servers. Vraagt om bevestiging met je wachtwoord (op het LAN zonder login: typ BLOKKEREN). |
| ✔ **Checks bedienen** (`checks`) | Storingen bevestigen, onderhoud aan- en uitzetten, een check nu uitvoeren. |

- **Alleen vanaf** (optioneel): IP-adressen of netwerken, gescheiden door spaties, bijvoorbeeld `192.168.1.20 10.0.0.0/24`. Vanaf een ander adres weigert de API de sleutel (HTTP 403). Leeg = overal vandaan. Aanbevolen voor een sleutel met ⛔.
- **Geldig:** 6, 12 (standaard) of 24 maanden. Daarna weigert de API hem (HTTP 401, met de datum). Binnen 30 dagen voor het einde staat er een oranje melding bij de sleutel.
- De sleutel (`rzm_…`) wordt **één keer** getoond; kopieer hem meteen. Er staan meteen testcommando's bij voor de gekozen rechten.
- Met **✎** pas je de rechten en *Alleen vanaf* aan, en **verleng** je de sleutel met 6, 12 of 24 maanden vanaf nu. De sleutel zelf blijft hetzelfde.
- Per sleutel zie je de rechten, tot wanneer hij geldig is en wanneer hij voor het laatst gebruikt is. Met 🗑 trek je een sleutel in.
- Maken, aanpassen en intrekken komt in het logboek (*Instellingen → Agents bijwerken → Logboek*); wat een sleutel doet ook, met als gebruiker `API: <naam>` en het IP-adres.
- Sleutels van vóór 1.45.0 krijgen alleen *Status lezen* en hebben geen vervaldatum. Geef ze met ✎ andere rechten.

### Gebruik
```bash
curl -H "Authorization: Bearer rzm_xxxxxxxx" http://192.168.1.10/v1.php/status
```
In plaats van de `Authorization`-header kun je ook `X-API-Key: rzm_…` gebruiken, of `?key=rzm_…` in de URL. Die laatste is minder veilig, omdat hij in logbestanden belandt.

| Endpoint | Resultaat |
|---|---|
| `GET /v1.php/status` | Totaalstatus, algehele gezondheid (`health`), tellers, lijst met problemen, status per server |
| `GET /v1.php/hosts` | Alle servers met checks, inclusief `uptime` (% bereikbaar over `h24`, `d7` en `d30`) |
| `GET /v1.php/hosts/{id}` | Eén server |
| `GET /v1.php/checks` | Alle checks. Filters: `?status=2`, `?type=disk`, `?host_id=1` |
| `GET /v1.php/checks/{id}` | Eén check, met waarde, eenheid, drempels en bericht |
| `GET /v1.php/checks/{id}/history?hours=24` | Meetwaarden (maximaal 720 uur) |
| `GET /v1.php/alerts?limit=50` | Laatste meldingen (maximaal 500) |

- **Status:** `0` OK, `1` WARNING, `2` CRITICAL, `3` UNKNOWN. Checks in onderhoud tellen niet mee voor de totaalstatus; staat alles in onderhoud, dan is de status `0`.
- **Extra velden:** `in_maintenance` en `maintenance_until` bij servers en checks, `parent_id` bij servers, `suppressed_by` bij checks (id van de onbereikbare server erboven), `acknowledged`, `acknowledged_at`, `acknowledged_by`, `ack_comment` en `problem_since` bij checks, `counts.acknowledged` (bevestigde storingen), `counts.maintenance` en `maintenance` (globaal onderhoud) in `/status`.
- **`health`** in `/status`: `state` (`ok`, `attention` of `outage`), `label`, `score` (0–100), `counts`, `checks_ok`/`checks_total`, `hosts_up`/`hosts_total`, `down` (onbereikbare servers met `reason`: `ping`, `agent` of `parent`), `open_problems`, `oldest_problem`, `flapping` en `availability_24h`.
- **Tijden** in ISO 8601.
- **Werkt `/v1.php/status` niet** (bijvoorbeeld Nginx zonder PATH_INFO)? Gebruik dan `/v1.php?r=status`.
- **Check-id** vind je via `/v1.php/checks`.
- Heeft de sleutel het recht niet, dan krijg je HTTP 403 met welk recht ontbreekt.

### IDS uitlezen (🛡️ IDS lezen)
| Endpoint | Resultaat |
|---|---|
| `GET /v1.php/ids` | Overzicht: IDS aan/uit, modus (`learn`/`block`), open incidenten per ernst, aantal actieve blokkades, servers met hun status |
| `GET /v1.php/ids/incidents` | Incidenten, nieuwste eerst. Filters: `?status=open` (of `done`), `?severity=2` (of `1`), `?host_id=1`, `?limit=100` (max 500) |
| `GET /v1.php/ids/blocks` | Actieve blokkades. `?all=1`: ook opgeheven en verlopen |
| `GET /v1.php/ids/access` | Logins, sudo en su, zoals op het tabblad *Toegang*. Filters: `?host_id=1`, `?type=ssh`, `?failed=1`, `?limit=100` |

### IP's blokkeren (⛔ IDS blokkeren)
Stuur JSON met `POST`:
```bash
# blokkeren op server web01, 24 uur (hours 0 = permanent, max 8760)
curl -H "Authorization: Bearer rzm_xxx" -H "Content-Type: application/json" \
  -d '{"host":"web01","ip":"203.0.113.7","hours":24,"reason":"fail2ban sshd"}' \
  http://192.168.1.10/v1.php/ids/block

# opheffen: met het id uit het antwoord, of met server + IP
curl -H "Authorization: Bearer rzm_xxx" -d '{"host":"web01","ip":"203.0.113.7"}' \
  http://192.168.1.10/v1.php/ids/unblock
```
- De server geef je met `host` (naam, hoofdletters maken niet uit) of `host_id`.
- Het antwoord bevat `id`, `active` en `until`. Staat het IDS op **leren** (of uit), dan wordt de blokkade wel vastgelegd, maar is `active` `false` en staat er een `note` bij; hij wordt actief zodra je het IDS op *blokkeren* zet.
- Is het IP op die server al geblokkeerd, dan krijg je `"already": true` met de bestaande blokkade (er komt geen tweede).
- **Nooit via de API:** je LAN (*Lokale netwerken*), het adres van RzMon zelf en de lijst *Nooit blokkeren* weigert de API altijd (HTTP 422).
- Een opgeheven blokkade wist ook de mislukte pogingen van dat IP, net als ⛔ op de IDS-pagina.

**Voorbeeld: fail2ban meldt zijn bans aan RzMon.** Maak `/etc/fail2ban/action.d/rzmon.conf`:
```ini
[Definition]
actionban   = curl -fsS -m 10 -H "Authorization: Bearer <rzmon_key>" -d '{"host":"<rzmon_host>","ip":"<ip>","hours":24,"reason":"fail2ban <name>"}' <rzmon_url>/v1.php/ids/block
actionunban = curl -fsS -m 10 -H "Authorization: Bearer <rzmon_key>" -d '{"host":"<rzmon_host>","ip":"<ip>"}' <rzmon_url>/v1.php/ids/unblock

[Init]
rzmon_url  = http://192.168.1.10
rzmon_key  = rzm_xxx
rzmon_host = web01
```
Zet in je jail `action = %(action_)s` en `rzmon` onder elkaar. Gebruik voor fail2ban een eigen sleutel met alleen ⛔ en *Alleen vanaf* het IP van die server.

### Checks bedienen (✔ Checks bedienen)
| Endpoint | Doet |
|---|---|
| `POST /v1.php/checks/{id}/ack` | Storing bevestigen, met optioneel `{"comment": "…"}` |
| `POST /v1.php/checks/{id}/unack` | Bevestiging intrekken |
| `POST /v1.php/checks/{id}/maintenance` | Onderhoud: `{"minutes": 60, "reason": "…"}` of `{"until": "2026-11-01T08:00"}`; `minutes` 0 = onderhoud beëindigen (max 90 dagen) |
| `POST /v1.php/hosts/{id}/maintenance` | Idem, voor een hele server |
| `POST /v1.php/checks/{id}/run` | De check nu uitvoeren; geeft de nieuwe status terug |

Home Assistant, bijvoorbeeld voor een knop op je dashboard (`rest_command` in `configuration.yaml`):
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

**De agent heeft een eigen versienummer** (sinds RzMon 1.46.0 en agent 1.45.1), los van RzMon. Verandert er alleen iets in RzMon, dan blijft de agent op zijn versie en hoef je niets bij te werken. Wat er in de agent verandert, staat in [`agent/CHANGELOG.md`](agent/CHANGELOG.md). De Home Assistant-add-on heeft hetzelfde nummer als de agent.

Elke agent geeft zijn versie door, en je ziet die op de servertegel (bijvoorbeeld `agent 1 min geleden · v1.45.1`). Is er een nieuwere agent, dan staat er **⬆** achter. In het venster *Agent Manager* zie je de versie van de agent en de nieuwste, met de knop om bij te werken. Met `rzmon-agent.sh --version` zie je de versie op de server zelf.

**Bij een update blijven je instellingen staan:**
- RzMon: `config.php` en alle instellingen in de database (bijvoorbeeld IDS aan en de modus, meldingen, back-up) blijven precies zoals ze waren. Het bijwerken van de database voegt alleen nieuwe instellingen toe; een bestaande instelling wordt nooit veranderd.
- Agent: `/etc/default/rzmon-agent` blijft byte voor byte gelijk (zie 8.2).

### Bijwerken vanuit RzMon ⬆ (Rocky Linux, vanaf 1.29.0)
Onder **Instellingen → ⬆ RzMon bijwerken** werk je RzMon bij zonder terminal.
- **Is er een update?** RzMon kijkt één keer per dag op GitHub, of direct met **🔍 Nu controleren**. Is er een nieuwe versie, dan zie je bovenin naast het versienummer een groen label **⬆ 1.x.y**, en op de kaart **wat er nieuw is** (de changelog van alle versies sinds de jouwe).
- **⬆ Nu bijwerken naar 1.x.y:** de hulpdienst op de server voert vaste stappen uit en je ziet de voortgang:
  1. *Controleren:* de RzMon-map moet vergrendeld zijn en alle bestanden van root;
  2. *Back-up maken:* als de schakelaar **Eerst een back-up maken** aan staat (standaard). De back-up staat daarna in de lijst onder *Back-up*;
  3. *Nieuwste versie ophalen* (`git pull`);
  4. *Installeren:* het installatiescript (`install-rocky.sh --yes`), net als wanneer je het commando zelf uitvoert: rechten, SELinux, hulpdienst en tabellen.

  Daarna laadt de pagina vanzelf de nieuwe versie. Gaat er iets mis, dan zie je de foutmelding en het logboek; is het ophalen mislukt, dan is er niets veranderd.
- **Automatisch bijwerken:** zet de schakelaar aan en kies een tijdstip (standaard 04:00). Is er een nieuwe versie, dan werkt RzMon zichzelf die dag in het uur na dat tijdstip bij, met een back-up vooraf als die schakelaar aan staat. De agents werk je apart bij (*⬆ Agents bijwerken*).
- **Eenmalig nodig:** de hulpdienst voor bijwerken. Die zet `install-rocky.sh` klaar; voer na de update naar 1.29.0 het installatiecommando dus nog één keer uit. Controleren: `systemctl status rzmon-update.path`.
- **Veiligheid:** de webpagina zet alleen een verzoek neer (`/var/lib/rzmon/update.request`). De hulpdienst (`/usr/local/sbin/rzmon-update`, als root) voert altijd dezelfde stappen uit, en weigert als de map ontgrendeld is (`--unlock`) of als een bestand niet van root is. Het logboek staat in `/var/lib/rzmon/update.log`.
- Werkt alleen bij een installatie met `install-rocky.sh` (git). Bij een andere installatie zegt de kaart dat, en werk je handmatig bij zoals hieronder.

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
   - Linux-servers: *Instellingen → ⬆ Agents bijwerken* → **Alle verouderde agents bijwerken** (of automatisch, zie 8.2.1). Agents van vóór 1.19.0: één keer `sudo rzmon-agent-setup --update`.
   - Home Assistant: ga naar de add-on winkel → ⋮ → *Controleren op updates* → *Bijwerken*. Er is alleen een update als de agent zelf veranderd is.

   Is de agent niet veranderd, dan hoef je hier niets te doen.
5. **Ververs de browser** met Ctrl+F5.

### Voor ontwikkelaars: versie verhogen
RzMon en de agent hebben elk een eigen nummer. Verhoog alleen wat je veranderd hebt:
```bash
tools/bump-version.sh rzmon minor   # RzMon: VERSION + lege sectie in CHANGELOG.md
tools/bump-version.sh agent patch   # agent: agent/VERSION, AGENT_VERSION, add-on-versie + lege sectie in agent/CHANGELOG.md
# vul de changelog(s) in, daarna:
tools/bump-version.sh sync          # agent en agent/CHANGELOG.md naar de add-on kopiëren
tools/check-version.sh              # controleert beide nummers en de changelogs
```
Patch is voor reparaties, minor voor nieuwe functies, major als de gebruiker zelf iets moet aanpassen. Heeft een nieuwe functie in RzMon ook een nieuwere agent nodig, zet dat dan in de changelog van RzMon ("agent 1.x.y nodig"). Een nieuwe agent krijgt altijd ook een patch-versie van RzMon: RzMon levert de agent aan je servers, en ⬆ *RzMon bijwerken* kijkt alleen naar het nummer van RzMon.

Op GitHub controleert een Action (`Versiecontrole`) bij elke push of de nummers kloppen, de kopieën in de add-on gelijk zijn en de changelogs ingevuld zijn.

> Kom je van de versie die nog *ServerMonitor* heette? Laat je `config.php` met de oude databasenaam staan. De agent heet nu `rzmon-agent.sh`; de oude `sm-agent.sh` blijft werken, maar vervang hem bij gelegenheid.

---

## 13. Back-up en herstel

Belangrijk zijn alleen de **database** en **`config.php`**. RzMon maakt daar zelf back-ups van: onder **Instellingen → 💾 Back-up** (alleen beheerders).

### Instellen

| Instelling | Uitleg |
|---|---|
| **Automatisch** | Uit, elke dag, elke week (op een dag die je kiest) of elke maand (de 1e) |
| **Om** | Tijdstip, standaard 03:15. Stond de server toen uit, dan volgt de back-up zodra cron weer draait. |
| **Bewaren** | Zoveel *automatische* back-ups blijven er staan, standaard 7. De oudste gaat weg als er een nieuwe bijkomt. Handmatige en geüploade back-ups blijven tot je ze zelf verwijdert; van de veiligheidsback-ups (zie hieronder) blijven de laatste 3. |
| **Meethistorie meenemen** | Met de meetresultaten achter de grafieken (standaard aan). Uit maakt de zip veel kleiner; bij terugzetten blijft de huidige historie dan staan. |
| **Zip-wachtwoord** | Versleutelt `database.sql` en `config.php` in de zip met **AES-256** (minstens 8 tekens). Zonder wachtwoord is de zip niet versleuteld; je krijgt daar een waarschuwing over. |

> **Bewaar het zip-wachtwoord goed**, bijvoorbeeld in je wachtwoordmanager. Zonder dat wachtwoord is een back-up onbruikbaar. Een versleutelde zip open je met **7-Zip** of een vergelijkbaar programma; de ingebouwde zip van Windows en macOS kan geen AES.

Mislukt een automatische back-up (disk vol, map niet schrijfbaar…), dan krijg je **één melding** via je meldkanalen (Pushover, e-mail, Home Assistant). RzMon probeert het daarna elk uur opnieuw en meldt het als het weer lukt.

### Wat zit er in de zip?

- `database.sql`: alle tabellen (servers, checks, instellingen, gebruikers, API-sleutels, meldingen en eventueel de meethistorie);
- `config.php`: met het databasewachtwoord;
- `info.txt`: versie, datum, server en het aantal rijen per tabel (niet versleuteld, bevat geen geheimen).

De back-ups staan in `/var/lib/rzmon/backups`, buiten de webmap, alleen leesbaar voor root en de webserver. Een andere map kan met `'backup_dir' => '/pad'` in `config.php`. RzMon maakt de back-ups in PHP zelf: `mysqldump` is niet nodig, alleen de PHP-extensie **zip** (`php-zip`).

> Bewaar af en toe een kopie **buiten de server**: download een zip (⬇) en zet hem op je NAS of in de cloud. Een back-up op dezelfde disk helpt niet als die disk stukgaat.

### Knoppen

- **💾 Nu back-up maken**: meteen een back-up, met de opgeslagen instellingen.
- **⬇ Downloaden**: de zip bevat `config.php`, dus je bevestigt eerst met je eigen wachtwoord (op het LAN zonder inloggen typ je `DOWNLOADEN`). Daarna kun je 10 minuten downloaden zonder opnieuw te bevestigen.
- **⬆ Zip uploaden**: een zip van je computer terugzetten, bijvoorbeeld van een andere server. De upload gaat in stukjes, dus de upload-limiet van PHP maakt niet uit (maximaal 2 GB).
- **↺ Terugzetten** en **🗑 Verwijderen**.

### Terugzetten

1. Klik op **↺** bij de back-up en bevestig met je wachtwoord (op het LAN zonder inloggen: `HERSTELLEN`). Heeft de zip een ander wachtwoord dan het ingestelde, vul dat dan in.
2. RzMon maakt eerst een **veiligheidsback-up** van de huidige toestand (*voor herstel* in de lijst).
3. Daarna wordt de database teruggezet. Mislukt dat halverwege, dan zet RzMon automatisch de veiligheidsback-up terug: het is alles of niets.

Goed om te weten:
- Het back-upschema en het zip-wachtwoord blijven zoals ze nu zijn.
- Gebruikers en wachtwoorden worden die uit de back-up; misschien moet je opnieuw inloggen.
- Een back-up van een oudere RzMon-versie kan gewoon; de tabellen worden daarna bijgewerkt.
- RzMon zet alleen terug wat hij zelf heeft geschreven (tabellen en rijen). Een gewijzigde of afgebroken `database.sql` wordt geweigerd.
- `config.php` wordt via de webpagina niet teruggezet; op dezelfde server is dat ook niet nodig.

### In de terminal en op een nieuwe server

```bash
sudo php /var/www/rzmon/install/install.php --backup                 # back-up maken (--zonder-historie kan ook)
sudo php /var/www/rzmon/install/install.php --list-backups           # lijst
sudo php /var/www/rzmon/install/install.php --restore /pad/naar/rzmon-backup-….zip
```

**Naar een nieuwe server** (bijvoorbeeld na een crash):

1. Installeer RzMon, bijvoorbeeld met `install-rocky.sh`, of zet alleen de bestanden en MariaDB klaar.
2. Zet de zip op de server en voer uit:
   ```bash
   sudo php /var/www/rzmon/install/install.php --restore /root/rzmon-backup-….zip --with-config
   ```
   Je wordt om het zip-wachtwoord gevraagd (of geef `--password=…` mee).
3. RzMon zet `config.php` terug, maakt de database en de databasegebruiker aan met het wachtwoord uit de back-up, en zet de database terug. Zonder `config.php` op de server gebeurt dit automatisch, ook zonder `--with-config`.

Uit veiligheid wordt een `config.php` met programmacode erin niet teruggezet: alleen gewone instellingen.

### Bestaande installatie (van vóór 1.18.0)

De back-upmap wordt aangemaakt door de hulpdienst. Toont de kaart een waarschuwing, voer dan één keer uit:
```bash
sudo dnf -y install php-zip && sudo systemctl restart php-fpm   # Debian/Ubuntu: sudo apt install php-zip
sudo bash /var/www/rzmon/install/install-helper.sh
```
Opnieuw `install-rocky.sh` draaien doet beide ook.

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


### Databasewachtwoord vernieuwen 🔑

Onder **Instellingen → Database** staat de knop **🔑 Databasewachtwoord vernieuwen** (alleen voor beheerders).

1. Klik op de knop en bevestig met **je eigen wachtwoord**. Ben je op het LAN automatisch beheerder (zonder inloggen), dan typ je `VERNIEUWEN`.
2. RzMon maakt een nieuw wachtwoord van 32 tekens en zet het in MariaDB **en** in `config.php`. Daarna test hij de verbinding. Mislukt er iets, dan wordt alles teruggezet en verandert er niets.
3. Het nieuwe wachtwoord wordt **één keer** getoond. Kopieer het als je het ergens wilt bewaren. Het staat ook in `/root/rzmon-install.txt`.

Dashboard, cron, agents en de API blijven gewoon werken: zij praten via RzMon met de database en gebruiken dus vanzelf het nieuwe wachtwoord.

**De hulpdienst.** De webserver mag `config.php` niet wijzigen (de map is na de setup vergrendeld, zie de checklist hierboven). Daarom doet een kleine systemd-dienst het wisselen als root:

- De webpagina zet een verzoek in `/var/lib/rzmon`.
- `rzmon-helper.path` ziet dat en start `install.php --rotate-db-password --helper`.
- De dienst kan niets anders. Hij voert geen commando's uit die van de webpagina komen.

`install-rocky.sh` installeert de hulpdienst automatisch. Heb je een oudere installatie, voer dan één keer uit:

```bash
sudo bash /var/www/rzmon/install/install-helper.sh            # installeren
sudo bash /var/www/rzmon/install/install-helper.sh --uninstall # verwijderen
```

Is de hulpdienst er niet, dan toont de knop een venster met beide commando's om te kopiëren. Zonder knop kan het ook altijd in de terminal:

```bash
sudo php /var/www/rzmon/install/install.php --rotate-db-password
```

> Werkt de knop niet ("De hulpdienst reageert niet")? Kijk dan met `systemctl status rzmon-helper.path rzmon-helper.service` en `journalctl -u rzmon-helper.service`.
> Maak na het vernieuwen ook een nieuwe back-up (zie hoofdstuk 13): oude back-ups bevatten nog het oude wachtwoord in `config.php`. Bij terugzetten op dezelfde server maakt dat niet uit, want `config.php` wordt dan niet teruggezet.

---

## 15. Problemen oplossen

| Probleem | Oplossing |
|---|---|
| **"Setup is alleen bereikbaar vanaf het lokale netwerk"** | Open de pagina vanaf een computer op je LAN, of via een SSH-tunnel: `ssh -L 8080:localhost:80 server` en dan `http://localhost:8080` |
| **Witte pagina / fout 500** | Bekijk `/var/log/apache2/rzmon_error.log` of `/var/log/nginx/error.log`. Of zet tijdelijk `display_errors` aan via Setup |
| **Wachtwoord vergeten** | `sudo -u www-data php /var/www/rzmon/install/install.php admin` zet een nieuw wachtwoord voor `admin` |
| **Telefoon met 2FA kwijt** | `sudo -u www-data php /var/www/rzmon/install/install.php --reset-2fa admin` zet 2FA uit; stel het daarna opnieuw in |
| **Wachtwoord kwijt** | *Wachtwoord vergeten?* op het loginscherm (account met e-mailadres), of op de server: `sudo -u www-data php /var/www/rzmon/install/install.php --reset-password admin` |
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
| **Agent rapporteert niet / "Nog geen rapport ontvangen"** | Op de server: `sudo rzmon-agent-setup --test` (§8.4). In RzMon staan bij *Agent Manager* de geweigerde pogingen met reden |
| **Agent: `403`** | Het token klopt niet, of de server staat niet op type *Remote met agent* |
| **Agent-checks: "Agent stil sinds …"** | Draait de cron op de agent-server? Kan die server RzMon bereiken? Test het commando uit hoofdstuk 8 |
| **Agent: "meldt service X niet"** | Werk de agent bij (⬆, 8.2.1); vanaf 1.20.0 bewaakt hij vanzelf elke service waarvoor een check bestaat. Net toegevoegd? Wacht ~2 minuten |
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
