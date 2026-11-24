# Installatie

Hier lees je hoe je RzSense installeert, de eerste keer instelt, bijwerkt en weer verwijdert. Je kiest uit drie
manieren: het installatiescript voor Rocky Linux, RHEL en AlmaLinux 10, een container met Podman of Docker, of alles
met de hand (ook op Debian en Ubuntu). Achteraan staan HTTPS, bijwerken en de oplossingen voor bekende problemen.
Is RzSense eenmaal geïnstalleerd, ga dan verder met [De interface](02-de-interface.md) en
[Meldingen](03-meldingen.md).

## Welke manier past bij jou?

| Jouw situatie | Kies |
|---|---|
| Een (nieuwe) server of VM met Rocky Linux, RHEL of AlmaLinux 10 | [Het installatiescript](#installeren-met-het-script). Aanbevolen: één commando, en daarna kun je RzSense bijwerken met één knop. |
| Je draait al alles in containers, of je wilt RzSense achter een reverse proxy zoals SWAG | [Een container](#installeren-in-een-container) met Podman (of Docker). |
| Je wilt precies weten wat er gebeurt, of je gebruikt Rocky/RHEL/Alma zonder script | [Met de hand op Rocky Linux](#met-de-hand-op-rocky-linux-rhel-of-almalinux). |
| Je server draait Debian of Ubuntu | [Met de hand op Debian of Ubuntu](#met-de-hand-op-debian-of-ubuntu). Bijwerken gaat daar met de hand. |

## Wat je nodig hebt

| | Minimaal | Aanbevolen |
|---|---|---|
| Besturingssysteem | Linux met systemd | Rocky Linux, RHEL of AlmaLinux 10; Debian 12/13; Ubuntu 22.04/24.04 |
| PHP | 8.1 | 8.2 of nieuwer (Rocky 10 levert 8.3) |
| PHP-extensies | `pdo_mysql`, `curl`, `mbstring` | ook `zip` (back-ups), `sodium` (handtekening van de agent controleren), `zlib`, en eventueel `snmp` |
| Database | MariaDB 10.3 of MySQL 8 | MariaDB 10.11 of nieuwer |
| Webserver | Apache 2.4 of Nginx, met PHP-FPM of mod_php | Apache (het script gebruikt Apache) |
| Overig | `ping` (iputils), `cron` | `git`, om makkelijk bij te werken |
| Hardware | 1 vCPU, 512 MB RAM | 1 vCPU en 1 GB RAM; een kleine VM of Raspberry Pi 4 is ruim genoeg |

Goed om te weten:

- **Vast IP-adres.** Geef de server een vast adres, zodat agents en je browser hem altijd vinden. Op Rocky stel je dat
  in met `nmtui`.
- **Netwerk.** Agents sturen hun gegevens zelf naar RzSense. Op de bewaakte servers hoef je dus geen poort te openen;
  alleen RzSense moet bereikbaar zijn op poort 80 of 443. RzSense zelf heeft internet nodig voor meldingen
  (zoals Pushover), het zoeken naar nieuwe versies op GitHub en de dagelijkse lijsten (blocklists, kwetsbaarheden).
- **`exec()` en `shell_exec()`** moeten in PHP toegestaan zijn. Ze zijn nodig voor ping- en service-checks. Op de
  genoemde systemen is dat standaard zo.
- **Processor bij Rocky Linux 10.** Op Intel en AMD vereist Rocky 10 een processor met **x86-64-v3** (ongeveer Intel
  vanaf 2013 en AMD vanaf 2015). Zet in Proxmox het CPU-type van de VM op `host` of `x86-64-v3`; met het standaardtype
  `kvm64` start Rocky 10 niet. Arm (`aarch64`) wordt ook ondersteund.

## Installeren met het script

Voor **Rocky Linux 10, RHEL 10 en AlmaLinux 10** is er een script dat alles in één keer doet. Daarna maak je in de
browser alleen nog je beheerder aan. Dit is de aanbevolen manier: alleen zo werkt later bijwerken met één knop vanuit
RzSense.

### Zo installeer je

Log in op de server met een gebruiker die `sudo` mag, en voer uit:

```bash
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh
less install-rocky.sh          # altijd eerst even bekijken wat een script doet
sudo bash install-rocky.sh
```

Het script laat zien wat het gaat doen en vraagt of je door wilt gaan. Aan het eind toont het een adres, bijvoorbeeld
`http://192.168.1.10/`. Open dat vanaf een computer op je eigen netwerk. De database is dan al gekoppeld: maak in de
setup je beheerder aan (zie [De setup-wizard](#de-setup-wizard)) en je komt op het dashboard.

### Wat het script doet

Het script werkt in negen stappen:

1. **Pakketten:** Apache met mod_ssl, PHP-FPM met de nodige extensies, MariaDB, cron, git en hulpprogramma's. Voor
   `php-sodium` zet het zo nodig **EPEL** aan (met CRB, waar EPEL op leunt). Extra pakketten zoals `php-snmp`,
   `php-intl` en `net-snmp-utils` installeert het als ze er zijn; ontbreekt er een, dan gaat het gewoon door.
2. **PHP:** tijdzone en veilige instellingen in `/etc/php.d/90-rzmon.ini`.
3. **Diensten:** MariaDB, PHP-FPM en cron starten, ook na een herstart.
4. **Database:** ruimt de standaardinstallatie op (anonieme gebruikers, testdatabase) en maakt de database `rzmon`
   en de gebruiker `rzmon` aan, met een willekeurig wachtwoord.
5. **RzSense ophalen** met git naar `/var/www/rzmon`, met de juiste eigenaar en rechten.
6. **Configuratie en tabellen:** schrijft `config.php` en maakt de tabellen aan.
7. **SELinux:** zet de twee nodige instellingen aan en herstelt de labels. Daarna wordt de installatiemap vergrendeld:
   de webserver kan er niet meer in schrijven.
8. **Apache:** een virtuele host op HTTP en HTTPS (met een self-signed certificaat), en firewalld opent http en https.
9. **Cron:** de checks draaien elke minuut. Ook installeert het de **hulpdienst**, zie hieronder.

Na afloop controleert het script of RzSense antwoordt en of `config.php` niet via het web op te vragen is.

- **Wachtwoorden:** het databasewachtwoord staat in `/root/rzmon-install.txt`, dat alleen root kan lezen.
- **Logboek:** alles wat het script doet staat in `/var/log/rzmon-install.log`.
- **Opnieuw uitvoeren is veilig.** Bij een bestaande installatie haalt het script de nieuwste versie op en werkt het
  de tabellen bij. `config.php`, de database en je wachtwoorden blijven staan.

### De hulpdienst

De hulpdienst is een kleine systemd-dienst die als root een paar vaste taken uitvoert als RzSense daarom vraagt,
terwijl de installatiemap vergrendeld blijft. Hij doet nooit iets anders dan die vaste taken:

- RzSense bijwerken met de knop in *Instellingen → Systeem → Bijwerken*;
- een nieuw databasewachtwoord instellen (*Instellingen → Systeem → Database en server*);
- het certificaat vernieuwen en instellingen in `config.php` opslaan;
- de map voor back-ups aanmaken: `/var/lib/rzmon/backups`.

Ontbreekt hij, bijvoorbeeld na een handmatige installatie, dan zet je hem erbij met:

```bash
sudo bash /var/www/rzmon/install/install-helper.sh
```

### Opties

Je geeft opties mee na de naam van het script, bijvoorbeeld `sudo bash install-rocky.sh --server-name rzsense.lan -y`.
Alle opties zie je met `--help`.

| Optie | Wat het doet |
|---|---|
| `--server-name NAAM` | Hostnaam voor Apache (standaard de hostname van de server) |
| `--dir PAD` | Installatiemap (standaard `/var/www/rzmon`) |
| `--db-name`, `--db-user`, `--db-pass` | Databasegegevens (standaard `rzmon`, `rzmon` en een willekeurig wachtwoord) |
| `--timezone TZ` | Tijdzone voor PHP en RzSense (standaard `Europe/Amsterdam`) |
| `--repo URL of MAP` | Een andere git-repository, of een lokale clone (bijvoorbeeld van een privé-kopie) |
| `--branch NAAM` | Een andere git-branch (standaard `main`) |
| `--no-https` | Geen self-signed HTTPS op poort 443 |
| `--no-firewall` | firewalld niet aanpassen |
| `--no-config` | Geen `config.php` schrijven: je vult de database zelf in de browser in |
| `--no-epel` | EPEL niet aanzetten. Dan geen `php-sodium`, en kan RzSense de handtekening van de agent niet controleren |
| `--update` | Eerst het hele systeem bijwerken met `dnf update` |
| `-y`, `--yes` | Niet om bevestiging vragen |
| `--unlock` | De installatiemap tijdelijk weer schrijfbaar maken voor de setup |
| `--lock` | De installatiemap weer vergrendelen |
| `-h`, `--help` | De hulp tonen |

**Instellingen via Setup wijzigen.** Wil je later in de setup de database- of PHP-instellingen veranderen, ontgrendel
de map dan eerst en vergrendel hem daarna weer:

```bash
sudo bash /var/www/rzmon/install/install-rocky.sh --unlock
# … instellingen opslaan in de setup …
sudo bash /var/www/rzmon/install/install-rocky.sh --lock
```

Zolang de map ontgrendeld is, weigert de hulpdienst RzSense bij te werken.

**Krijg je bij `curl` een fout 404?** Dan kan het script niet worden opgehaald, bijvoorbeeld omdat je een privé-kopie
van de repository gebruikt. Clone die dan zelf (met een GitHub-token als wachtwoord) en installeer vanaf je clone:

```bash
sudo dnf -y install git
git clone https://github.com/<jouw-account>/rzmon.git ~/rzmon
sudo bash ~/rzmon/install/install-rocky.sh --repo ~/rzmon
```

## Installeren in een container

RzSense draait ook als container, naast de gewone installatie. Het zijn er twee:

| Container | Image | Wat |
|---|---|---|
| `rzsense` | `ghcr.io/rembrand20/rzsense` | RzSense zelf: Nginx, PHP-FPM en de planner die elke minuut de checks start |
| `rzsense-db` | `docker.io/library/mariadb:11.4` | De database; die werk je los bij |

- **Netwerk:** de containers gebruiken het netwerk van de host. Daardoor werken ping, SNMP, mtr, ARP-ontdekking en
  LLDP zoals bij een gewone installatie, en bereiken de agents RzSense op het adres van de server. MariaDB luistert
  alleen op `127.0.0.1`.
- **Poorten:** **8080** (http) voor je reverse proxy, en **8443** voor de *nooddeur*: https met een eigen certificaat,
  alleen bereikbaar vanaf je eigen netwerk. Beide stel je in met `RZMON_WEB_PORT` en `RZMON_DOOR_PORT`.
- **Gegevens:** in de volumes `rzsense-data` (`config.php`, back-ups, certificaat en sleutels) en `rzsense-db`
  (de database).
- **Veilig:** RzSense draait als `www-data`, niet als root. Het bestandssysteem is alleen-lezen, behalve het
  datavolume en `/tmp`. Het enige extra recht is `NET_RAW`, voor ping. Er wordt geen Podman-socket aangekoppeld.

### Zo installeer je (Podman met quadlets)

Quadlets zijn kleine bestanden waarmee systemd de containers start, ook na een herstart. Op Rocky Linux is dit de
aanbevolen manier.

1. Installeer Podman en zet het bestand met instellingen klaar:
   ```bash
   sudo dnf -y install podman
   sudo mkdir -p /etc/rzsense && sudo chmod 700 /etc/rzsense
   curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/container/rzsense.env.example | sudo tee /etc/rzsense/rzsense.env >/dev/null
   sudo chmod 600 /etc/rzsense/rzsense.env
   ```
2. Vul de wachtwoorden in met `sudo nano /etc/rzsense/rzsense.env`. Maak lange, willekeurige wachtwoorden met
   `openssl rand -base64 24`. Let op: `RZMON_DB_PASS` moet gelijk zijn aan `MARIADB_PASSWORD`.
3. Haal de quadlets op en start de containers:
   ```bash
   for f in rzsense-db.container rzsense.container; do
     curl -fsSL "https://raw.githubusercontent.com/rembrand20/rzmon/main/container/quadlet/$f" | sudo tee "/etc/containers/systemd/$f" >/dev/null
   done
   sudo systemctl daemon-reload
   sudo systemctl start rzsense-db rzsense
   sudo podman logs -f rzsense        # wacht op "klaar: web op poort 8080 …"
   ```
4. Open in de firewall alleen wat nodig is: 8080 voor je reverse proxy, en 8443 als je de nooddeur wilt gebruiken.
   Bijvoorbeeld met het adres van je SWAG-server:
   ```bash
   sudo firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=<IP van SWAG> port port=8080 protocol=tcp accept'
   sudo firewall-cmd --permanent --add-port=8443/tcp && sudo firewall-cmd --reload
   ```

**De eerste beheerder** maak je niet in de browser: die staat in `rzsense.env` (`RZMON_ADMIN_USER` en
`RZMON_ADMIN_PASS`). Laat je het wachtwoord leeg, dan maakt RzSense er zelf een en zet het één keer in de log
(`sudo podman logs rzsense`). Verander het na het inloggen.

**Achter SWAG of een andere reverse proxy:** stuur je site door naar `http://<server>:8080`. Zet daarna in RzSense het
beheeradres, bij *Instellingen → Toegang tot RzSense → Beheer van buiten*. Zie
[Toegang en beheer](11-toegang-en-beheer.md).

### Met compose of Docker

Liever compose? Zet `container/compose.yaml` en `container/rzsense.env.example` (als `.env`) in dezelfde map, vul de
wachtwoorden in en start:

```bash
podman compose up -d        # of: docker compose up -d
```

Zelf de image bouwen kan ook, vanuit een clone van de repository:

```bash
podman build -f container/Containerfile -t localhost/rzsense .
```

### Wat in een container anders is

- **Bijwerken** gaat met een nieuwe image, niet met de knop of git. Zie [Bijwerken](#een-container-bijwerken).
- **Geen hulpdienst nodig:** `config.php` staat in het datavolume en RzSense mag het zelf aanpassen.
- **CPU, RAM en schijf** van de server ziet de container nauwelijks; hij ziet vooral zichzelf. Installeer de
  [Linux-agent](05-linux-agent.md) op de host voor de echte cijfers.
- **Het certificaat van de nooddeur** wordt bij het starten aangemaakt (2 jaar geldig) en vernieuwd als het binnen
  30 dagen verloopt.
- **Zonder host-netwerk** (bijvoorbeeld met `-p 8080:8080`) werken UniFi, ping en SNMP nog, maar ARP-ontdekking en
  LLDP niet.

### Overstappen van een gewone installatie

1. Maak in je huidige RzSense een back-up: *Instellingen → Systeem → Back-up* → **Nu back-up maken** →
   **⬇ Downloaden**. Onthoud het wachtwoord van de zip.
2. Start de containers zoals hierboven, met een nieuw databasewachtwoord, en log in.
3. Ga in de container naar *Instellingen → Systeem → Back-up* → **⬆ Zip uploaden** → **↺ Terugzetten**. De
   `config.php` uit de zip wordt niet overgenomen: de container houdt zijn eigen database-instellingen.
4. Werkt alles, zet dan de oude installatie uit. Verandert het adres van RzSense, geef de agents dan het nieuwe adres
   (zie [Linux-agent](05-linux-agent.md)).

Een container op een andere machine is ook een goede **reserve-RzSense** die een kopie van je hoofdserver bijhoudt.
Zie [Toegang en beheer](11-toegang-en-beheer.md).

## Met de hand op Rocky Linux, RHEL of AlmaLinux

Deze stappen doen hetzelfde als het script. Volg ze als je alles zelf wilt doen of wilt begrijpen wat er gebeurt.

### Verschillen met Debian en Ubuntu

| | Debian/Ubuntu | Rocky/RHEL/Alma |
|---|---|---|
| Pakketbeheer | `apt` | `dnf` |
| Webserver | `apache2` | `httpd` |
| Gebruiker van de webserver | `www-data` | **`apache`** |
| PHP in Apache | mod_php | **PHP-FPM** (`/run/php-fpm/www.sock`) |
| Apache-configuratie | `/etc/apache2/sites-available/` | `/etc/httpd/conf.d/` |
| Firewall | meestal uit | **firewalld staat aan** |
| Beveiliging | AppArmor | **SELinux staat aan** (enforcing) |
| Cron | standaard aanwezig | pakket `cronie`, dienst `crond` |

**SELinux is het belangrijkste verschil.** Zet het niet uit; RzSense heeft maar een paar instellingen nodig.

### Pakketten installeren

Werk eerst het systeem bij (herstart als er een nieuwe kernel is) en installeer dan de pakketten:

```bash
sudo dnf -y update
sudo dnf -y install \
    httpd mod_ssl php php-fpm php-cli php-mysqlnd php-mbstring php-process php-zip \
    mariadb-server iputils iproute cronie git unzip \
    policycoreutils-python-utils
```

Aanbevolen extra's. `php-sodium` staat in EPEL; zonder kan RzSense de handtekening van de agent niet controleren.
`php-snmp` heb je nodig om apparaten via SNMP uit te lezen:

```bash
sudo dnf -y install epel-release && sudo crb enable
sudo dnf -y install php-sodium php-snmp net-snmp-utils
```

| Pakket | Waarvoor |
|---|---|
| `php-mysqlnd` | De verbinding met MariaDB (`pdo_mysql`) |
| `php-mbstring` | Tekstfuncties |
| `php-process` | `posix`-functies, voor de systeemcheck |
| `php-zip` | Back-ups als (versleutelde) zip |
| `php-sodium` | De handtekening van de agent controleren |
| `php-snmp`, `net-snmp-utils` | Switches, NAS, UPS en printers uitlezen via SNMP |
| `cronie` | Cron, om elke minuut de checks te draaien |
| `policycoreutils-python-utils` | `semanage`, voor SELinux |

Controleer PHP: `php -v` moet 8.3 tonen, en `php -m` moet `curl`, `mbstring` en `pdo_mysql` bevatten.

### Diensten starten en firewall openen

```bash
sudo systemctl enable --now mariadb php-fpm httpd crond
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

### De database aanmaken

Beveilig eerst MariaDB. Antwoord bij het root-wachtwoord met **Enter**, bij *Switch to unix_socket authentication* en
*Change the root password* met **n**, en bij de rest met **Y**:

```bash
sudo mariadb-secure-installation
```

Maak dan de database en de gebruiker aan:

```bash
sudo mariadb <<'SQL'
CREATE DATABASE rzmon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rzmon'@'localhost' IDENTIFIED BY 'KIES_EEN_STERK_WACHTWOORD';
GRANT ALL PRIVILEGES ON rzmon.* TO 'rzmon'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### RzSense ophalen

Clone RzSense direct in `/var/www`; dan krijgen de bestanden vanzelf het juiste SELinux-label:

```bash
sudo git clone https://github.com/rembrand20/rzmon.git /var/www/rzmon
sudo chown -R root:apache /var/www/rzmon
sudo find /var/www/rzmon -type d -exec chmod 750 {} \;
sudo find /var/www/rzmon -type f -exec chmod 640 {} \;
sudo chmod 755 /var/www/rzmon/agent/rzmon-agent.sh

# Tijdelijk: de setup mag config.php aanmaken (dit zet je straks terug)
sudo chown apache /var/www/rzmon
```

Pak je liever een zip uit? Doe dat dan direct in `/var/www`. Pak je hem uit in `/tmp` en verplaats je hem met `mv`, dan
neemt hij een verkeerd SELinux-label mee. Herstel dat met `sudo restorecon -Rv /var/www/rzmon`.

### SELinux instellen

```bash
# PHP mag verbinden met de database
sudo setsebool -P httpd_can_network_connect_db 1
# PHP mag naar buiten: Pushover, HTTP- en TCP-checks, de poortscan en "Nu checken"
sudo setsebool -P httpd_can_network_connect 1
# Tijdelijk: de setup mag config.php schrijven
sudo chcon -t httpd_sys_rw_content_t /var/www/rzmon
```

Controleer met `getenforce` (*Enforcing*) en `getsebool httpd_can_network_connect_db httpd_can_network_connect`
(beide *on*).

Cron heeft geen last van SELinux: de checks die elke minuut draaien, werken altijd. Alleen de knop **↻ Nu checken**
in de webinterface draait onder de regels van de webserver. Zie [Problemen oplossen](#problemen-oplossen) als die knop
bij ping- of service-checks een fout geeft.

### Apache instellen

Apache stuurt `.php` op Rocky al door naar PHP-FPM. Je maakt alleen een virtuele host:

```bash
sudo tee /etc/httpd/conf.d/rzmon.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName rzsense.lan
    DocumentRoot /var/www/rzmon/public

    <Directory /var/www/rzmon/public>
        Require all granted
        AllowOverride None
        Options -Indexes
        DirectoryIndex index.php
        # Nodig voor de REST API op /v1.php/status
        AcceptPathInfo On
        # Geeft "Authorization: Bearer …" (API-sleutel) door aan PHP-FPM
        CGIPassAuth On
    </Directory>

    ErrorLog  /var/log/httpd/rzmon_error.log
    CustomLog /var/log/httpd/rzmon_access.log combined
</VirtualHost>
EOF
sudo mv /etc/httpd/conf.d/welcome.conf /etc/httpd/conf.d/welcome.conf.uit   # optioneel: welkomstpagina uit
sudo apachectl configtest && sudo systemctl reload httpd
```

De document root moet de map `public/` zijn. Dan zijn `config.php` (met je databasewachtwoord) en de broncode nooit
via het web op te vragen. Omdat dit de eerste virtuele host op poort 80 is, werkt hij ook via het IP-adres.

### Setup, cron en afronden

1. Doorloop de [setup-wizard](#de-setup-wizard). Op Rocky staat bij *Draait als gebruiker* **`apache`**, en het pad
   naar ping is `/bin/ping`.
2. Zet cron aan:
   ```bash
   echo '* * * * * apache /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
   sudo chmod 644 /etc/cron.d/rzmon
   sudo -u apache /usr/bin/php /var/www/rzmon/cron/run.php     # test: "… 4 check(s) uitgevoerd"
   ```
   De gebruiker `apache` heeft geen login-shell. Dat is geen probleem: cron en `sudo -u apache` werken gewoon.
3. Vergrendel de map weer en zet de labels terug:
   ```bash
   sudo chown root:apache /var/www/rzmon
   sudo chown root:apache /var/www/rzmon/config.php
   sudo chmod 640 /var/www/rzmon/config.php
   sudo restorecon -Rv /var/www/rzmon
   ```
4. Installeer de hulpdienst, voor back-ups, een nieuw databasewachtwoord en bijwerken met de knop:
   ```bash
   sudo bash /var/www/rzmon/install/install-helper.sh --group apache
   ```
5. Controleer: `curl -s -o /dev/null -w '%{http_code}\n' http://localhost/config.php` moet **404** geven.

## Met de hand op Debian of Ubuntu

In de voorbeelden staat RzSense in `/var/www/rzmon` en is `www-data` de gebruiker van de webserver.

### Pakketten installeren

Met Apache (het makkelijkst):

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php mariadb-server \
     php php-mysql php-curl php-mbstring php-zip iputils-ping git unzip
```

Of met Nginx en PHP-FPM:

```bash
sudo apt update
sudo apt install nginx php-fpm mariadb-server \
     php-mysql php-curl php-mbstring php-zip iputils-ping git unzip
```

Wil je apparaten via SNMP uitlezen, installeer dan ook `php-snmp`. Controleer met `php -v` dat PHP 8.1 of nieuwer is.

### Bestanden plaatsen

```bash
sudo git clone https://github.com/rembrand20/rzmon.git /var/www/rzmon
sudo chown -R root:www-data /var/www/rzmon
sudo find /var/www/rzmon -type d -exec chmod 750 {} \;
sudo find /var/www/rzmon -type f -exec chmod 640 {} \;
sudo chmod 755 /var/www/rzmon/agent/rzmon-agent.sh

# Tijdelijk: de setup mag config.php aanmaken
sudo chown www-data /var/www/rzmon
```

### De database aanmaken

Op Debian en Ubuntu logt de root van MariaDB in via `unix_socket`, dus alleen vanaf de terminal met `sudo`. Maak de
database daarom zo aan:

```bash
sudo mysql <<'SQL'
CREATE DATABASE rzmon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rzmon'@'localhost' IDENTIFIED BY 'KIES_EEN_STERK_WACHTWOORD';
GRANT ALL PRIVILEGES ON rzmon.* TO 'rzmon'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Heb je een MariaDB-beheerder **met** wachtwoord? Dan kan de setup de database ook voor je aanmaken.

### De webserver instellen

De document root moet de map **`public/`** zijn. Dan zijn `config.php` en de broncode nooit via het web op te vragen.

**Apache.** Maak `/etc/apache2/sites-available/rzmon.conf`:

```apache
<VirtualHost *:80>
    ServerName rzsense.lan
    DocumentRoot /var/www/rzmon/public

    <Directory /var/www/rzmon/public>
        Require all granted
        AllowOverride None
        # Nodig met PHP-FPM: geeft "Authorization: Bearer …" (REST API) door aan PHP
        CGIPassAuth On
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

**Nginx.** Maak `/etc/nginx/sites-available/rzmon`. Controleer de PHP-versie in de naam van de socket met
`ls /run/php/`:

```nginx
server {
    listen 80;
    server_name rzsense.lan;
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

### Setup, cron en afronden

1. Doorloop de [setup-wizard](#de-setup-wizard).
2. Zet cron aan:
   ```bash
   echo '* * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
   sudo -u www-data php /var/www/rzmon/cron/run.php     # test: "… 4 check(s) uitgevoerd"
   ```
3. Vergrendel de map weer: `sudo chown root /var/www/rzmon`.
4. Installeer de hulpdienst, voor back-ups en een nieuw databasewachtwoord met één knop:
   ```bash
   sudo bash /var/www/rzmon/install/install-helper.sh
   ```

### Alles via de terminal

Liever helemaal geen browser-setup? Dat kan ook:

```bash
cd /var/www/rzmon
sudo cp config.sample.php config.php
sudo nano config.php                              # database, tijdzone en base_url
sudo chown root:www-data config.php && sudo chmod 640 config.php
sudo -u www-data php install/install.php admin    # tabellen en beheerder "admin" (vraagt een wachtwoord)
echo '* * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
```

## De setup-wizard

Na een installatie met de hand (of met het script) open je vanaf een computer **op je eigen netwerk**
`http://<ip-van-de-server>/`. Je komt vanzelf op de setup. Zolang de installatie niet klaar is, werkt de setup alleen
vanaf een privé-adres (192.168.x.x, 10.x.x.x, 172.16–31.x.x of de server zelf). Heeft het script de database al
gekoppeld, dan ga je meteen naar stap 4.

1. **Systeemcheck.** Alles hoort groen te zijn; oranje is een waarschuwing. *Cron draait* wordt pas groen een minuut
   nadat je cron hebt ingesteld. Onder *PHP-omgeving* zie je onder andere als welke gebruiker PHP draait.
2. **Database (MariaDB).** Vul in: host `localhost` of `127.0.0.1`, poort `3306`, database `rzmon`, gebruiker `rzmon`
   en het wachtwoord. Klik op **🔌 Verbinding testen** en daarna op **Opslaan & tabellen installeren**. Heb je nog geen
   database, zet dan *Database en gebruiker voor mij aanmaken* aan en geef een MariaDB-beheerder met wachtwoord op.
   Dat wachtwoord wordt niet bewaard.
3. **Applicatie & PHP.**
   - **Tijdzone**, bijvoorbeeld `Europe/Amsterdam`.
   - **Basis-URL**, bijvoorbeeld `http://192.168.1.10`. Die komt in de installatiecommando's voor agents.
   - **Pad naar ping**: meestal `/bin/ping` of `/usr/bin/ping` (controleer met `command -v ping`).
   - **memory_limit** en **max_execution_time**: leeg laten, tenzij je een reden hebt.
   - **display_errors**: alleen aanzetten om fouten te zoeken.
4. **Beheerder aanmaken.** Kies een gebruikersnaam en een wachtwoord van minstens 8 tekens. Daarna ben je ingelogd en
   sta je op het dashboard.
5. **Cron.** Hier staat de regel voor `/etc/cron.d/rzmon`, klaar om te kopiëren.

**Kan de setup `config.php` niet schrijven?** Dan zie je de inhoud in beeld. Maak het bestand zelf aan met
`sudo nano /var/www/rzmon/config.php`, plak de inhoud, sla op en ververs de pagina.

**Klaar?** Op het dashboard staat je RzSense-server al, met vier checks: Disk /, MariaDB, CPU en RAM. De systeemcheck
vind je later terug bij *Instellingen → Systeem → Database en server*. Ga verder met [De interface](02-de-interface.md),
[Meldingen](03-meldingen.md) en [Servers en checks](04-servers-en-checks.md). Andere Linux-servers bewaak je met de
[Linux-agent](05-linux-agent.md).

## HTTPS en Nginx

Op je eigen netwerk is HTTP prima. Wil je RzSense van buitenaf gebruiken, doe dat dan via HTTPS of een VPN zoals
WireGuard of Tailscale. RzSense kan ook zelf veilig beheer van buiten regelen, met een eigen certificaat en alleen
toegestane apparaten; zie [Toegang en beheer](11-toegang-en-beheer.md).

### Let's Encrypt

Hiervoor heb je een domeinnaam nodig die naar je server wijst, en een open poort 80.

```bash
# Rocky/RHEL/Alma (certbot staat in EPEL)
sudo dnf -y install epel-release
sudo dnf -y install certbot python3-certbot-apache mod_ssl
sudo certbot --apache -d rzsense.jouwdomein.nl

# Debian/Ubuntu
sudo apt install certbot python3-certbot-apache      # of python3-certbot-nginx
sudo certbot --apache -d rzsense.jouwdomein.nl        # of --nginx
```

Certbot vernieuwt het certificaat vanzelf; controleer dat met `systemctl list-timers | grep certbot`.

Let op bij een installatie met het script: `install-rocky.sh` schrijft `/etc/httpd/conf.d/rzmon.conf` bij elke run
opnieuw, ook bij bijwerken met de knop. Wat je zelf (of certbot) in dat bestand verandert, verdwijnt dan.

### Eigen certificaat op je netwerk

Het script zet al een self-signed certificaat klaar op poort 443. Met de hand op Rocky installeer je `mod_ssl` (dat
maakt een self-signed certificaat aan) en voeg je in `rzmon.conf` een `<VirtualHost *:443>`-blok toe met dezelfde
inhoud plus `SSLEngine on`.

Agents vertrouwen een self-signed certificaat niet zomaar. Zet dan `INSECURE=1` in `/etc/default/rzmon-agent`, of
`verify_ssl: false` in de Home Assistant-add-on. Beter is het certificaat vastzetten; zie
[Linux-agent](05-linux-agent.md).

### Nginx in plaats van Apache op Rocky

Kies Apache óf Nginx, niet allebei op poort 80.

```bash
sudo systemctl disable --now httpd
sudo dnf -y install nginx
sudo usermod -aG apache nginx      # Nginx moet de bestanden (groep apache) kunnen lezen
```

PHP-FPM draait als `apache`, en Nginx mag de socket al gebruiken dankzij `listen.acl_users = apache,nginx` in
`/etc/php-fpm.d/www.conf`. Maak `/etc/nginx/conf.d/rzmon.conf`:

```nginx
server {
    listen 80 default_server;
    server_name rzsense.lan _;
    root /var/www/rzmon/public;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    # (/|$) zodat ook /v1.php/status (REST API) werkt
    location ~ ^(.+\.php)(/.*)?$ {
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        set $path_info $fastcgi_path_info;     # bewaren: try_files wist hem
        try_files $fastcgi_script_name =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $path_info;
        fastcgi_pass unix:/run/php-fpm/www.sock;
    }

    location ~ /\. { deny all; }
}
```

Haal in `/etc/nginx/nginx.conf` het standaardblok `server { listen 80 default_server; … }` weg (of de
`default_server` daarin) en start Nginx met `sudo nginx -t && sudo systemctl enable --now nginx`. SELinux, cron en de
setup werken hetzelfde als bij Apache.

Het installatiescript en de knop voor bijwerken gaan uit van Apache. Gebruik je Nginx, werk dan met de hand bij.

## Bijwerken

RzSense heeft een versienummer: *major.minor.patch*. Een **patch** bevat alleen reparaties, een **minor** nieuwe
functies, en bij een **major** moet je zelf iets doen (dat staat dan in de changelog). Je versie staat naast het logo
in de bovenbalk; klik erop voor wat er nieuw is. De agents en de Home Assistant-add-on hebben een eigen versienummer.

**Je instellingen blijven staan.** Een update laat `config.php` en alle instellingen in de database (zoals meldingen,
IDS en back-up) precies zoals ze waren. Nieuwe instellingen komen erbij met hun standaardwaarde. De database zelf
wordt vanzelf bijgewerkt bij de eerste cron-ronde of het eerste bezoek aan het dashboard na een nieuwe versie.

### Vanuit RzSense

Dit werkt bij een installatie met het script (Rocky, RHEL of AlmaLinux met git en de hulpdienst). RzSense kijkt één
keer per dag op GitHub of er een nieuwe versie is. Is die er, dan zie je bovenin een groen label **⬆ 1.x.y**.

1. Ga naar *Instellingen → Systeem → Bijwerken*, of klik op het groene label. Met **🔍 Nu controleren** kijk je meteen.
2. Lees *Wat is er nieuw*, laat **Eerst een back-up maken** aan staan (aanbevolen) en klik op
   **⬆ Nu bijwerken naar 1.x.y**.
3. Een venster toont de voortgang: *Controleren*, *Nieuwste versie ophalen*, *Back-up maken*, *Installeren* en
   *Klaar*. Tijdens het herstarten is RzSense even niet bereikbaar; het venster wacht. Met **Op de achtergrond** sluit
   je het venster; het label bovenin toont dan *⏳ Bijwerken…*.
4. Klaar: de pagina laadt vanzelf de nieuwe versie. Gaat er iets mis, dan zie je welke stap misging, het logboek en
   **↻ Opnieuw proberen**. Mislukt het ophalen, dan is er niets veranderd.

Goed om te weten:

- **De back-up** is meestal een snelle: instellingen, servers, checks, gebruikers en configuratie, zonder de grote
  geschiedenis en logs. Verandert de nieuwe versie de opbouw van de database, dan wordt het een volledige back-up. Je
  vindt hem bij *Instellingen → Systeem → Back-up* (*voor update*; de laatste 5 blijven bewaard).
- **Automatisch bijwerken:** zet de schakelaar **Automatisch bijwerken** aan en kies een tijdstip (standaard 04:00).
  Is er een nieuwe versie, dan werkt RzSense zichzelf die dag in het uur na dat tijdstip bij, met eerst een back-up als
  die schakelaar aan staat.
- **🔁 Andere versie…** Hiermee kies je een andere versie uit de lijst, ook een oudere om terug te gaan. Terugzetten
  bevestig je met je wachtwoord. Je gegevens en de database blijven staan; wat er sindsdien bij kwam werkt dan niet
  meer. Zolang je bewust een oudere versie gebruikt, pauzeert automatisch bijwerken. Later weer naar de nieuwste gaat
  met **Nu bijwerken**.
- **Veiligheid:** de webpagina zet alleen een verzoek klaar. De hulpdienst voert als root altijd dezelfde stappen uit
  (git ophalen en `install-rocky.sh` draaien), en weigert als de map ontgrendeld is of als een bestand niet van root is.
  Het logboek staat in `/var/lib/rzmon/update.log`.
- **Werkt de knop niet?** Dan zegt de kaart waarom, bijvoorbeeld *De hulpdienst voor bijwerken is nog niet
  geïnstalleerd*. Voer dan het installatiescript nog één keer uit; daarna werkt de knop.

### Met het script

Voer het installatiescript gewoon opnieuw uit, op dezelfde manier als bij de installatie:

```bash
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh
sudo bash install-rocky.sh
```

Of vanaf je eigen clone: `git -C ~/rzmon pull && sudo bash ~/rzmon/install/install-rocky.sh --repo ~/rzmon`. Het
script meldt bijvoorbeeld *RzSense bijgewerkt van 1.319.0 naar 1.320.0*.

### Met de hand

1. **Maak eerst een back-up** (*Instellingen → Systeem → Back-up*, zie [Toegang en beheer](11-toegang-en-beheer.md)).
2. **Haal de nieuwe versie op** en zet de rechten goed (op Rocky: groep `apache` en daarna `restorecon`):
   ```bash
   cd /var/www/rzmon
   sudo git pull
   sudo chown -R root:www-data /var/www/rzmon
   ```
   `config.php` staat niet in git; `git pull` laat hem met rust. Gebruik je een zip, pak die dan uit en kopieer hem
   eroverheen zonder `config.php`:
   `sudo rsync -a --exclude config.php /tmp/rzmon/ /var/www/rzmon/`.
3. **De database** wordt vanzelf bijgewerkt. Lukt dat niet, dan zie je een rode balk op het dashboard. Met de hand kan
   het met `sudo -u www-data php /var/www/rzmon/install/install.php --upgrade` (op Rocky `-u apache`), of met
   **↻ Tabellen bijwerken** op de setup-pagina.
4. **Ververs de browser** met Ctrl+F5.

### Een container bijwerken

RzSense kijkt elke dag of er een nieuwe versie is en meldt dat in de app, bij *Instellingen → Systeem → Bijwerken*.
Daar staan ook de commando's. Bijwerken gaat nooit vanzelf:

```bash
sudo podman pull ghcr.io/rembrand20/rzsense:latest && sudo systemctl restart rzsense
```

Bij het starten van een nieuwe versie maakt RzSense eerst een snelle back-up in het datavolume en werkt daarna de
database bij. MariaDB werk je los bij:
`sudo podman pull docker.io/library/mariadb:11.4 && sudo systemctl restart rzsense-db`. Wil je op een bepaalde versie
blijven, zet dan in `rzsense.container` bijvoorbeeld `Image=ghcr.io/rembrand20/rzsense:1.320.0`.

### Agents en het systeem bijwerken

- **Agents** werk je apart bij: op de pagina *Servers* met **⬆ Alle verouderde agents bijwerken**, per server in de
  Agent Manager, of automatisch. Een verouderde agent herken je aan **⬆** op de servertegel. Zie
  [Linux-agent](05-linux-agent.md). Een update van de agent laat `/etc/default/rzmon-agent` staan.
- **Home Assistant-add-on:** in Home Assistant bij de add-ons, ⋮ → *Controleren op updates* → *Bijwerken*.
- **Het besturingssysteem** werk je zelf bij (`sudo dnf -y update` of `sudo apt upgrade`). Na een PHP-update eventueel
  `sudo systemctl restart php-fpm`.

## Verwijderen

Er is geen script dat RzSense in één keer verwijdert. Maak eerst een back-up als je de gegevens wilt bewaren, en haal
de agents van je servers met `sudo rzmon-agent-setup --uninstall` (zie [Linux-agent](05-linux-agent.md)).

**Gewone installatie** (op Debian: `apache2` en `/etc/apache2/sites-available/rzmon.conf` in plaats van `httpd`):

```bash
sudo bash /var/www/rzmon/install/install-helper.sh --uninstall   # hulpdienst; back-ups blijven staan
sudo rm -f /etc/cron.d/rzmon /etc/httpd/conf.d/rzmon.conf /etc/php.d/90-rzmon.ini
sudo systemctl reload httpd
sudo mariadb -e "DROP DATABASE rzmon; DROP USER 'rzmon'@'localhost';"
sudo rm -rf /var/www/rzmon
sudo rm -rf /var/lib/rzmon /root/rzmon-install.txt               # back-ups en het wachtwoordbestand
```

**Container:**

```bash
sudo systemctl stop rzsense rzsense-db
sudo rm /etc/containers/systemd/rzsense.container /etc/containers/systemd/rzsense-db.container
sudo systemctl daemon-reload
sudo podman volume rm rzsense-data rzsense-db                    # alle gegevens, ook de back-ups
sudo rm -rf /etc/rzsense
```

## Problemen oplossen

### Zit SELinux in de weg?

Op Rocky, RHEL en AlmaLinux is SELinux de meest voorkomende oorzaak. Kijk of het iets blokkeert:

```bash
sudo ausearch -m avc -ts recent | tail -20
```

Zie je regels met `denied` en `comm="php-fpm"` of `httpd`, zoek de oplossing dan in de tabel hieronder. Zet SELinux
niet uit.

### Bekende problemen

| Probleem | Oorzaak | Oplossing |
|---|---|---|
| **403 Forbidden** op de hele site | Verkeerd SELinux-label, meestal na `mv` vanuit `/tmp` of je homemap | `sudo restorecon -Rv /var/www/rzmon` |
| Setup: *Database … Permission denied* | PHP mag niet met de database verbinden | `sudo setsebool -P httpd_can_network_connect_db 1` |
| Setup kan `config.php` niet opslaan | Map niet schrijfbaar of verkeerd label | Script-installatie: `--unlock` (daarna `--lock`). Met de hand: `sudo chown apache /var/www/rzmon && sudo chcon -t httpd_sys_rw_content_t /var/www/rzmon`, daarna terugzetten. Of plak de getoonde inhoud zelf in `config.php`. |
| Pushover-test, HTTP-check of poortscan faalt vanuit de webinterface | PHP mag niet naar buiten | `sudo setsebool -P httpd_can_network_connect 1` |
| **↻ Nu checken** bij ping of service geeft een fout, maar via cron gaat het goed | SELinux laat PHP-FPM geen `ping` of `systemctl` starten | Laat het zo, de cron-checks tellen. Of sta het toe, zie hieronder. |
| Pagina laadt niet vanaf een andere computer | Firewall | `sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload` |
| *Cron draait* blijft oranje | `crond` draait niet, of het cron-bestand heeft verkeerde rechten | `sudo systemctl enable --now crond`; `/etc/cron.d/rzmon` moet van `root:root` zijn met rechten `644` |
| Setup: *alleen bereikbaar vanaf het lokale netwerk* | De installatie is nog niet klaar en je komt van buiten | Open de setup vanaf je eigen netwerk |
| REST API geeft 401 met een geldige sleutel | Apache geeft `Authorization` niet door aan PHP-FPM | `CGIPassAuth On` in de virtuele host, of gebruik de header `X-API-Key: rzm_…` |
| `/v1.php/status` geeft 404 | PATH_INFO staat uit | `AcceptPathInfo On` in de virtuele host, of gebruik `/v1.php?r=status` |
| Pagina zonder opmaak, 403 op `assets/` (Nginx op Rocky) | Gebruiker `nginx` mag de bestanden niet lezen | `sudo usermod -aG apache nginx && sudo systemctl restart nginx` |
| 502 Bad Gateway (Nginx) | Nginx mag niet bij de socket van PHP-FPM | Controleer `listen.acl_users` in `/etc/php-fpm.d/www.conf`, dan `sudo systemctl restart php-fpm` |
| Bijwerken met de knop weigert: map ontgrendeld of bestand niet van root | De map is nog open na `--unlock`, of iemand heeft rechten veranderd | `sudo bash /var/www/rzmon/install/install-rocky.sh --lock` |
| *Fatal glibc error: CPU does not support x86-64-v3* | Processor of VM-type te oud voor Rocky 10 | Proxmox: CPU-type `host` of `x86-64-v3`. Op oude hardware: Rocky Linux 9 |
| Service-check `ssh` is CRITICAL op Rocky | Daar heet de dienst `sshd` | Pas de check aan naar `sshd` |

### Ping en services ook via "Nu checken" toestaan

Alleen nodig als je die knop echt voor ping- en service-checks wilt gebruiken; RzSense werkt prima zonder.

```bash
# 1. Klik in RzSense een paar keer op ↻ bij een ping- en een service-check (die mislukken)
# 2. Maak van de geweigerde acties een eigen beleidsmodule en laad die:
sudo ausearch -m avc -ts recent -c php-fpm | audit2allow -M rzmon-local
cat rzmon-local.te            # bekijk wat er wordt toegestaan
sudo semodule -i rzmon-local.pp
```

Komt er later een nieuwe weigering bij, herhaal het dan. Verwijderen gaat met `sudo semodule -r rzmon-local`.

### Logbestanden

| Wat | Waar |
|---|---|
| Installatiescript | `/var/log/rzmon-install.log` |
| Bijwerken vanuit RzSense | `/var/lib/rzmon/update.log` |
| Apache (Rocky) | `/var/log/httpd/rzmon_error.log` (HTTPS: `rzmon_ssl_error.log`) |
| Apache (Debian/Ubuntu) | `/var/log/apache2/rzmon_error.log` |
| PHP-FPM (Rocky) | `/var/log/php-fpm/www-error.log` |
| Nginx | `/var/log/nginx/error.log` |
| SELinux | `sudo ausearch -m avc -ts recent` |
| Cron | `sudo journalctl -u crond --since "10 min ago"` (Debian: `-u cron`) |
| MariaDB | `sudo journalctl -u mariadb` |
| Container | `sudo podman logs rzsense` en `sudo podman logs rzsense-db` |

Fouten van PHP en de snelheid van RzSense zie je ook in RzSense zelf, bij *Instellingen → Systeem → Prestaties en
fouten*.
