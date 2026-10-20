# RzMon installeren op Rocky Linux 10

Deze handleiding installeert RzMon op **Rocky Linux 10**. Ze werkt ook voor RHEL 10 en AlmaLinux 10. Voor alles na de installatie, zoals Pushover, checks, agents en de API, volg je daarna de gewone [HANDLEIDING.md](HANDLEIDING.md) vanaf hoofdstuk 6.

> Gebruik je Debian of Ubuntu? Dan heb je deze pagina niet nodig; zie [HANDLEIDING.md](HANDLEIDING.md), hoofdstuk 5.

## ⚡ Snelle installatie met het script

Het script [`install/install-rocky.sh`](install/install-rocky.sh) doet alle stappen van deze handleiding in één keer:
- **Software:** httpd met mod_ssl, PHP-FPM met de nodige extensies, MariaDB en cron.
- **Beveiliging:** firewalld en SELinux.
- **Database:** aangemaakt met een willekeurig wachtwoord.
- **RzMon zelf:** `config.php`, de tabellen, een Apache-vhost op HTTP én HTTPS (self-signed) en de cronjob.

Daarna maak je in de browser alleen nog je beheerder aan.

```bash
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh
less install-rocky.sh          # altijd eerst even bekijken wat een script doet
sudo bash install-rocky.sh
```

> **Krijg je `curl: (22) … error: 404`?** Dan is de GitHub-repository **privé**: GitHub geeft dan aan iedereen die niet ingelogd is een 404. Kies één van deze twee oplossingen:
>
> **A. Repository openbaar maken (makkelijkst).** Op GitHub: *Settings → General → Danger Zone → Change visibility → Public*. Er staan geen wachtwoorden of tokens in de code; `config.php` gaat nooit naar GitHub. Daarna werkt de curl-regel hierboven, en kun je de Home Assistant-add-on direct als repository toevoegen.
>
> **B. Privé houden.** Clone de repository dan zelf, met een *Personal Access Token*:
> 1. Maak op GitHub een token aan: *Settings → Developer settings → Fine-grained tokens → Generate new token*. Kies *Only select repositories* → `rzmon`, met bij *Permissions* **Contents: Read-only**.
> 2. Op de server:
>    ```bash
>    sudo dnf -y install git
>    git clone https://github.com/rembrand20/rzmon.git ~/rzmon     # gebruikersnaam + het token als wachtwoord
>    sudo bash ~/rzmon/install/install-rocky.sh --repo ~/rzmon
>    ```
> 3. Bijwerken gaat daarna met:
>    ```bash
>    git -C ~/rzmon pull && sudo bash ~/rzmon/install/install-rocky.sh --repo ~/rzmon
>    ```
> Het token wordt niet op de server opgeslagen; git vraagt er bij `git pull` opnieuw om.

Open aan het eind de getoonde URL, bijvoorbeeld `http://192.168.1.10/`, en maak bij stap 4 van de setup je beheerder aan. Het databasewachtwoord staat in `/root/rzmon-install.txt`, dat alleen root kan lezen. Het script installeert ook de *hulpdienst* (`rzmon-helper.path`), waarmee je het databasewachtwoord later met één knop kunt vernieuwen (*Instellingen → Database*). De hulpdienst maakt ook de map voor back-ups aan (`/var/lib/rzmon/backups`, zie *Instellingen → 💾 Back-up*). Bij een oudere installatie zet je die er één keer bij met `sudo dnf -y install php-zip && sudo bash /var/www/rzmon/install/install-helper.sh`.

| Optie | Uitleg |
|---|---|
| `--server-name rzmon.lan` | Hostnaam voor Apache (standaard: de hostname van de server) |
| `--dir /var/www/rzmon` | Installatiemap |
| `--db-name`, `--db-user`, `--db-pass` | Databasegegevens (standaard `rzmon`/`rzmon`/willekeurig) |
| `--timezone Europe/Amsterdam` | Tijdzone voor PHP en RzMon |
| `--no-https` | Geen self-signed HTTPS op poort 443 |
| `--no-firewall` | firewalld niet aanpassen |
| `--no-config` | Databasegegevens zelf in de browser invullen |
| `--repo ~/rzmon` | Installeren vanaf een lokale clone, bv. van een privé-repository |
| `--update` | Eerst `dnf update` |
| `-y` | Niet om bevestiging vragen |
| `--unlock` / `--lock` | Setup tijdelijk wel of niet laten schrijven in `config.php` |

- **Opnieuw uitvoeren is veilig:** het script werkt dan bij met `git pull` en bijgewerkte tabellen. `config.php`, de database en je wachtwoord blijven staan.
- **Wil je later via *Setup* de database- of PHP-instellingen wijzigen?** Voer dan eerst `sudo bash /var/www/rzmon/install/install-rocky.sh --unlock` uit, en daarna weer `--lock`.
- **Logboek:** `/var/log/rzmon-install.log`.

Liever alles zelf doen, of wil je weten wat het script precies doet? Volg dan de stappen hieronder.

---

---

## Inhoud
1. [Verschillen met Debian/Ubuntu](#1-verschillen-met-debianubuntu)
2. [Voordat je begint](#2-voordat-je-begint)
3. [Pakketten installeren](#3-pakketten-installeren)
4. [Diensten starten en firewall openen](#4-diensten-starten-en-firewall-openen)
5. [MariaDB instellen](#5-mariadb-instellen)
6. [RzMon downloaden](#6-rzmon-downloaden)
7. [SELinux instellen](#7-selinux-instellen)
8. [Apache instellen](#8-apache-instellen)
9. [Setup-wizard doorlopen](#9-setup-wizard-doorlopen)
10. [Cron instellen](#10-cron-instellen)
11. [Afronden en controleren](#11-afronden-en-controleren)
12. [Optioneel: HTTPS](#12-optioneel-https)
13. [Optioneel: Nginx in plaats van Apache](#13-optioneel-nginx-in-plaats-van-apache)
14. [Agent op Rocky Linux-servers](#14-agent-op-rocky-linux-servers)
15. [Upgraden](#15-upgraden)
16. [Problemen oplossen (Rocky-specifiek)](#16-problemen-oplossen-rocky-specifiek)

---

## 1. Verschillen met Debian/Ubuntu

| | Debian/Ubuntu | Rocky Linux 10 |
|---|---|---|
| Pakketbeheer | `apt` | `dnf` |
| Webserver | `apache2` | `httpd` |
| Webserver-gebruiker | `www-data` | **`apache`** |
| PHP in Apache | mod_php | **PHP-FPM** (standaard, via `/run/php-fpm/www.sock`) |
| Apache-config | `/etc/apache2/sites-available/` | `/etc/httpd/conf.d/` |
| Firewall | meestal uit | **firewalld staat aan** |
| Beveiliging | AppArmor (niet relevant) | **SELinux staat aan (enforcing)** |
| Cron | standaard geïnstalleerd | pakket `cronie`, dienst `crond` |
| php.ini | `/etc/php/8.x/…/php.ini` | `/etc/php.ini` + `/etc/php-fpm.d/www.conf` |

**SELinux is het belangrijkste verschil.** Zet het **niet** uit. In stap 7 staan de paar instellingen die RzMon nodig heeft.

---

## 2. Voordat je begint

- **Rocky Linux 10**, een *Minimal* of *Server*-installatie, met een gebruiker die `sudo` mag.
- **Processor:** Rocky Linux 10 vereist op Intel/AMD een CPU met **x86-64-v3** (ongeveer Intel Haswell 2013+ / AMD Excavator 2015+). In **Proxmox** zet je het CPU-type van de VM op **`host`** of **`x86-64-v3`**. Met het standaardtype `kvm64` start Rocky 10 niet.
- **Arm-hardware** wordt ook ondersteund (`aarch64`).
- **Vast IP-adres** voor de server, zodat agents en je browser hem altijd vinden. Stel het in met `nmtui`.
- **Hardware:** 1 vCPU en 1 GB RAM is ruim voldoende.

Werk het systeem eerst bij:
```bash
sudo dnf -y update
sudo reboot        # alleen nodig als er een nieuwe kernel is geïnstalleerd
```

---

## 3. Pakketten installeren

Rocky Linux 10 levert standaard **PHP 8.3** en **MariaDB 10.11**. Beide voldoen ruim.

```bash
sudo dnf -y install \
    httpd php php-fpm php-cli php-mysqlnd php-mbstring php-process php-zip \
    mariadb-server \
    iputils iproute cronie git unzip \
    policycoreutils-python-utils
```

| Pakket | Waarvoor |
|---|---|
| `httpd`, `php`, `php-fpm` | Webserver + PHP (via PHP-FPM) |
| `php-mysqlnd` | `pdo_mysql`: verbinding met MariaDB |
| `php-mbstring` | Tekstfuncties |
| `php-process` | `posix`-functies (gebruikt door de systeemcheck) |
| `php-zip` | Back-ups als (versleutelde) zip |
| `mariadb-server` | Database |
| `iputils`, `iproute` | `ping` en `ss` |
| `cronie` | Cron, om elke minuut de checks te draaien |
| `git` | RzMon ophalen van GitHub |
| `policycoreutils-python-utils` | `semanage`, voor de SELinux-instellingen |

Controleer PHP:
```bash
php -v                                        # PHP 8.3.x
php -m | grep -Ei '^(pdo_mysql|curl|mbstring)$'
```
Je moet `curl`, `mbstring` en `pdo_mysql` zien. Op Rocky zit `curl` standaard in PHP (pakket `php-common`).

---

## 4. Diensten starten en firewall openen

```bash
sudo systemctl enable --now mariadb php-fpm httpd crond

sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https     # als je later HTTPS gebruikt
sudo firewall-cmd --reload
```

Controleren:
```bash
systemctl is-active mariadb php-fpm httpd crond     # 4× "active"
sudo firewall-cmd --list-services                   # bevat http (en https)
```

---

## 5. MariaDB instellen

Optioneel maar aanbevolen: beveilig de standaardinstallatie. Daarbij worden de anonieme gebruikers en de testdatabase verwijderd.
```bash
sudo mariadb-secure-installation
```
Beantwoord de vragen zo:
- Huidig root-wachtwoord: **Enter**.
- *Switch to unix_socket authentication*: **n**. Dat staat al goed.
- *Change the root password*: **n**.
- Bij de rest: **Y**.

Maak de database en gebruiker voor RzMon aan:
```bash
sudo mariadb <<'SQL'
CREATE DATABASE rzmon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rzmon'@'localhost' IDENTIFIED BY 'KIES_EEN_STERK_WACHTWOORD';
GRANT ALL PRIVILEGES ON rzmon.* TO 'rzmon'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Testen:
```bash
mariadb -u rzmon -p rzmon -e 'SELECT VERSION();'
```

---

## 6. RzMon downloaden

Clone RzMon direct in `/var/www`. Bestanden die daar worden aangemaakt krijgen automatisch het juiste SELinux-label:

```bash
sudo git clone https://github.com/rembrand20/rzmon.git /var/www/rzmon

sudo chown -R root:apache /var/www/rzmon
sudo find /var/www/rzmon -type d -exec chmod 750 {} \;
sudo find /var/www/rzmon -type f -exec chmod 640 {} \;
sudo chmod 755 /var/www/rzmon/agent/rzmon-agent.sh

# Tijdelijk: laat de setup-pagina config.php aanmaken (wordt in stap 11 teruggezet)
sudo chown apache /var/www/rzmon
```

> ⚠️ Pak je liever de **zip** uit? Doe dat dan niet in `/tmp` om hem daarna te **verplaatsen** (`mv`), want dan neemt hij een verkeerd SELinux-label mee. Pak hem direct uit in `/var/www`, of herstel na het verplaatsen de labels met `sudo restorecon -Rv /var/www/rzmon`.

---

## 7. SELinux instellen

Controleer dat SELinux aan staat:
```bash
getenforce        # → Enforcing
```

RzMon heeft drie dingen nodig:

```bash
# 1. PHP mag verbinden met de database
sudo setsebool -P httpd_can_network_connect_db 1

# 2. PHP mag uitgaande verbindingen maken: Pushover, HTTP/TCP-checks,
#    de poortscan en de knop "Nu checken" in de webinterface
sudo setsebool -P httpd_can_network_connect 1

# 3. Tijdelijk: de setup mag config.php in de RzMon-map schrijven
sudo chcon -t httpd_sys_rw_content_t /var/www/rzmon
```

Controleren:
```bash
getsebool httpd_can_network_connect_db httpd_can_network_connect   # beide "on"
ls -Zd /var/www/rzmon                                              # httpd_sys_rw_content_t
```

> Het schrijflabel uit stap 3 is tijdelijk. Na de setup zet je het in stap 11 terug met `restorecon`.

**Cron heeft hier geen last van.** De checks die elke minuut via cron draaien vallen niet onder de webserver-regels van SELinux. Ping-, service- en CPU-checks werken daar dus altijd. Alleen als je in de webinterface op **↻ Nu checken** klikt, voert PHP-FPM de check uit onder SELinux. Werkt een ping- of service-check via die knop niet, terwijl hij via cron wel goed gaat? Zie [hoofdstuk 16](#16-problemen-oplossen-rocky-specifiek).

---

## 8. Apache instellen

Op Rocky stuurt Apache `.php`-bestanden standaard al door naar PHP-FPM, via `/etc/httpd/conf.d/php.conf`. Je hoeft alleen een virtuele host aan te maken.

Maak `/etc/httpd/conf.d/rzmon.conf`:
```bash
sudo tee /etc/httpd/conf.d/rzmon.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName rzmon.lan
    DocumentRoot /var/www/rzmon/public

    <Directory /var/www/rzmon/public>
        Require all granted
        AllowOverride None
        Options -Indexes
        # Nodig voor de REST API op /v1.php/status
        AcceptPathInfo On
        # Geeft de header "Authorization: Bearer …" (API-sleutel) door aan PHP-FPM
        CGIPassAuth On
        DirectoryIndex index.php
    </Directory>

    ErrorLog  /var/log/httpd/rzmon_error.log
    CustomLog /var/log/httpd/rzmon_access.log combined
</VirtualHost>
EOF
```

Optioneel kun je de Rocky-welkomstpagina uitzetten. Vervolgens test en herlaad je de configuratie:
```bash
sudo mv /etc/httpd/conf.d/welcome.conf /etc/httpd/conf.d/welcome.conf.uit   # optioneel
sudo apachectl configtest      # → Syntax OK
sudo systemctl reload httpd
```

> **Gebruik `ServerName`** met de naam waarop je RzMon opent, of laat de regel staan. Omdat dit de eerste virtuele host op poort 80 is, werkt hij ook als je de server via zijn IP-adres opent.

---

## 9. Setup-wizard doorlopen

Open vanaf een computer **op je lokale netwerk** `http://<ip-van-de-server>/`. Je komt automatisch op de setup-pagina.

1. **Systeemcheck.** Alles hoort groen te zijn. "Cron draait" wordt pas groen na stap 10. Wat je hier ziet:
   - *Draait als gebruiker* (open *PHP-omgeving*) = **`apache`**.
   - *Ping beschikbaar* = `/bin/ping`. Op Rocky is dat dezelfde als `/usr/bin/ping`.
2. **Database:**
   - Host `localhost` of `127.0.0.1`, poort `3306`.
   - Database `rzmon`, gebruiker `rzmon`, en het wachtwoord uit stap 5.

   Klik op **🔌 Verbinding testen** en daarna op **Opslaan & tabellen installeren**.

   Krijg je *Permission denied*, dan staat `httpd_can_network_connect_db` uit (stap 7).
3. **Applicatie & PHP.**
   - **Tijdzone:** `Europe/Amsterdam`.
   - **Basis-URL:** bijvoorbeeld `http://192.168.1.10`.
   - **Pad naar ping:** laat dit op `/bin/ping` staan.
4. **Beheerder aanmaken.** Daarna sta je direct op het dashboard.
5. **Cron.** De setup toont de cron-regel al met gebruiker `apache`; zie stap 10.

---

## 10. Cron instellen

```bash
echo '* * * * * apache /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon
sudo chmod 644 /etc/cron.d/rzmon
```

Testen:
```bash
sudo -u apache /usr/bin/php /var/www/rzmon/cron/run.php
# → 2026-10-19T16:08:36+02:00 4 check(s) uitgevoerd
```

> De gebruiker `apache` heeft geen login-shell (`/sbin/nologin`). Dat is geen probleem: cron en `sudo -u apache` werken gewoon.

---

## 11. Afronden en controleren

**Zet de schrijfrechten en het SELinux-label terug.** De setup heeft `config.php` aangemaakt en hoeft niet meer te schrijven:
```bash
sudo chown root /var/www/rzmon
sudo chown root:apache /var/www/rzmon/config.php
sudo chmod 640 /var/www/rzmon/config.php
sudo restorecon -Rv /var/www/rzmon
```

**Controleer:**
```bash
ls -Z /var/www/rzmon/config.php                           # httpd_sys_content_t
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/config.php   # 404
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/             # 200 of 302
```
- Op het **dashboard** staat je server met vier checks: Disk /, MariaDB, CPU en RAM.
- **Setup → Systeemcheck:** "Cron draait" staat na een minuut op groen.

> Wil je later via *Setup* de database- of PHP-instellingen veranderen? Herhaal dan `sudo chown apache /var/www/rzmon` en `sudo chcon -t httpd_sys_rw_content_t /var/www/rzmon`, sla op in de setup, en draai daarna de commando's uit dit hoofdstuk opnieuw. Of plak de inhoud die de setup toont zelf in `config.php`.

---

## 12. Optioneel: HTTPS

**Met Let's Encrypt.** Dit vereist een domeinnaam die naar je server wijst en een open poort 80. Certbot komt uit EPEL:
```bash
sudo dnf -y install epel-release
sudo dnf -y install certbot python3-certbot-apache mod_ssl
sudo certbot --apache -d rzmon.jouwdomein.nl
```
Certbot vernieuwt het certificaat automatisch; controleer dat met `systemctl list-timers | grep certbot`.

**Alleen op je LAN, met een eigen certificaat:**
```bash
sudo dnf -y install mod_ssl      # maakt een self-signed certificaat aan
sudo systemctl restart httpd
```
Voeg in `rzmon.conf` dan een `<VirtualHost *:443>`-blok toe met dezelfde inhoud plus `SSLEngine on`. Gebruik bij agents met een self-signed certificaat `INSECURE=1`, of zet `verify_ssl: false` in de Home Assistant-add-on.

---

## 13. Optioneel: Nginx in plaats van Apache

Kies Apache **óf** Nginx, niet allebei op poort 80.

```bash
sudo systemctl disable --now httpd
sudo dnf -y install nginx
```

Nginx serveert de CSS- en JavaScript-bestanden zelf. Daarom moet de gebruiker `nginx` de bestanden van RzMon kunnen lezen; die zijn van groep `apache`:
```bash
sudo usermod -aG apache nginx
```

PHP-FPM draait op Rocky als gebruiker `apache`. Nginx mag de socket al gebruiken, dankzij `listen.acl_users = apache,nginx` in `/etc/php-fpm.d/www.conf`. Controleer dat:
```bash
grep -E '^listen(\.acl_users)?\s*=' /etc/php-fpm.d/www.conf
# listen = /run/php-fpm/www.sock
# listen.acl_users = apache,nginx
```

Maak `/etc/nginx/conf.d/rzmon.conf`:
```nginx
server {
    listen 80 default_server;
    server_name rzmon.lan _;
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

Haal in `/etc/nginx/nginx.conf` het standaard `server { listen 80 default_server; … }`-blok weg, of de `default_server` daarin. Daarna:
```bash
sudo nginx -t && sudo systemctl enable --now nginx
```
SELinux, cron en de setup werken hetzelfde als bij Apache. De gebruiker blijft `apache`, want die van PHP-FPM telt.

---

## 14. Agent op Rocky Linux-servers

Wil je **andere** Rocky-servers bewaken met de agent (CPU, RAM, disks, services, poorten)? Kopieer in RzMon het commando bij *Agent installeren* en plak het op die server:

```bash
curl -fsSL 'http://192.168.1.10/api.php?action=agent_install' | sudo bash -s -- --url 'http://192.168.1.10' --token '<token>'
```

Het script installeert zelf wat ontbreekt (`iproute`), stelt de draaiende services voor en zet een systemd-timer aan. Zie [HANDLEIDING.md, hoofdstuk 8](HANDLEIDING.md#8-agent-op-linux-servers).

- **SELinux:** geen aanpassingen nodig. De agent draait als systemd-service en maakt alleen een uitgaande verbinding naar RzMon.
- **Firewall:** geen poort nodig op de agent-server, want de agent stuurt zelf naar RzMon.
- **Interessante services** om in `SERVICES` te zetten, bijvoorbeeld:
  ```
  SERVICES="sshd crond firewalld chronyd httpd mariadb php-fpm"
  ```
  Let op: op Rocky heet de SSH-dienst **`sshd`**, niet `ssh` zoals op Debian.

---

## 15. Upgraden

```bash
cd /var/www/rzmon
sudo git pull
sudo chown -R root:apache /var/www/rzmon
sudo restorecon -Rv /var/www/rzmon
sudo -u apache php install/install.php --upgrade
```
De laatste stap is eigenlijk niet meer nodig: sinds versie 1.6.0 werkt RzMon de database automatisch bij bij de eerste cron-run of het eerste bezoek aan het dashboard. Je kunt ook na de `git pull` op **Setup → ↻ Tabellen bijwerken** klikken. De versie staat in de topbalk, en wat er nieuw is lees je in `CHANGELOG.md`. Agents werk je bij met de knop onder *Instellingen → ⬆ Agents bijwerken* (agents van vóór 1.19.0 eenmalig met `sudo rzmon-agent-setup --update`).

`config.php` staat niet in Git (`.gitignore`), dus `git pull` laat hem met rust.

Vanaf 1.18.0 (back-ups) is ook `php-zip` nodig, plus de back-upmap: `sudo dnf -y install php-zip && sudo systemctl restart php-fpm && sudo bash /var/www/rzmon/install/install-helper.sh`. Opnieuw `install-rocky.sh` draaien doet dit vanzelf.

Pakketupdates van Rocky zelf gaan met `sudo dnf -y update`. Na een PHP-update eventueel nog:
```bash
sudo systemctl restart php-fpm
```

---

## 16. Problemen oplossen (Rocky-specifiek)

Voor algemene problemen, zoals Pushover, agents of de API: zie [HANDLEIDING.md, hoofdstuk 15](HANDLEIDING.md#15-problemen-oplossen).

### Eerst: zit SELinux in de weg?
```bash
sudo ausearch -m avc -ts recent | tail -20
```
Zie je regels met `denied` en `comm="php-fpm"` of `httpd`? Dan blokkeert SELinux iets. Zet SELinux daarvoor **niet** uit. Zoek het probleem in de tabel hieronder.

| Probleem | Oorzaak | Oplossing |
|---|---|---|
| **403 Forbidden** op de hele site | Verkeerd SELinux-label, meestal na `mv` vanuit `/tmp` of je homemap | `sudo restorecon -Rv /var/www/rzmon` |
| **Setup: "Database: … Permission denied" (2002)** | Boolean staat uit | `sudo setsebool -P httpd_can_network_connect_db 1` |
| **Setup kan `config.php` niet opslaan** | Map niet schrijfbaar of verkeerd label | `sudo chown apache /var/www/rzmon && sudo chcon -t httpd_sys_rw_content_t /var/www/rzmon` (na afloop terugzetten, stap 11) |
| **Pushover-test faalt in de webinterface** maar werkt via de terminal | PHP mag niet naar buiten | `sudo setsebool -P httpd_can_network_connect 1` |
| **Poortscan vindt niets / HTTP-check "Geen antwoord"** vanuit de webinterface | Idem | Idem |
| **"↻ Nu checken" bij ping of service geeft een fout**, maar via cron gaat het goed | SELinux laat PHP-FPM geen `ping` of `systemctl` starten | Laat het zo; de cron-checks zijn leidend. Of sta het toe met een eigen beleidsmodule, zie hieronder |
| **Pagina laadt niet vanaf een andere pc** | Firewall | `sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload` |
| **Pagina zonder opmaak / 403 op `assets/` (Nginx)** | Gebruiker `nginx` mag de bestanden niet lezen | `sudo usermod -aG apache nginx && sudo systemctl restart nginx` |
| **502 Bad Gateway (Nginx)** | Nginx mag niet bij de PHP-FPM-socket | Controleer `listen.acl_users` in `/etc/php-fpm.d/www.conf` en `sudo systemctl restart php-fpm` |
| **REST API geeft 401 met een geldige sleutel** | Apache geeft `Authorization` niet door aan PHP-FPM | `CGIPassAuth On` in `rzmon.conf` (stap 8). Of gebruik de header `X-API-Key: rzm_…` |
| **`/v1.php/status` geeft 404** | PATH_INFO staat uit | `AcceptPathInfo On` in `rzmon.conf` (stap 8), of gebruik `/v1.php?r=status` |
| **"Cron draait" blijft oranje** | `crond` draait niet, of het cron-bestand heeft verkeerde rechten | `sudo systemctl enable --now crond`, en `/etc/cron.d/rzmon` moet `root:root` met `644` zijn |
| **"Fatal glibc error: CPU does not support x86-64-v3"** bij het starten | De CPU (of het VM-CPU-type) is te oud voor Rocky 10 | Proxmox: CPU-type `host` of `x86-64-v3`. Op oude hardware: gebruik Rocky Linux 9 |
| **Service-check `ssh` is CRITICAL** | Op Rocky heet de dienst `sshd` | Pas de check aan naar `sshd` |

### Ping en services ook via "Nu checken" toestaan (optioneel)
Doe dit alleen als je die knop echt voor ping- en service-checks wilt gebruiken. RzMon werkt prima zonder.

```bash
# 1. Klik in RzMon een paar keer op ↻ bij een ping- en een service-check (die mislukken)
# 2. Maak van de geweigerde acties een eigen beleidsmodule en laad die:
sudo ausearch -m avc -ts recent -c php-fpm | audit2allow -M rzmon-local
cat rzmon-local.te            # bekijk wat er wordt toegestaan
sudo semodule -i rzmon-local.pp
```
Herhaal dit als er nog een nieuwe weigering bijkomt. Verwijderen gaat met `sudo semodule -r rzmon-local`.

### Logbestanden
| Wat | Waar |
|---|---|
| Apache | `/var/log/httpd/rzmon_error.log` |
| PHP-FPM | `/var/log/php-fpm/www-error.log` |
| Nginx | `/var/log/nginx/error.log` |
| SELinux | `sudo ausearch -m avc -ts recent` |
| Cron | `sudo journalctl -u crond --since "10 min ago"` |
| MariaDB | `sudo journalctl -u mariadb` |
