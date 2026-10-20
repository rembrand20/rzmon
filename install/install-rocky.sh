#!/usr/bin/env bash
# =============================================================================
#  RzMon – installatiescript voor Rocky Linux 10 (ook RHEL 10 / AlmaLinux 10)
#
#  Installeert en configureert in één keer:
#    httpd (+ mod_ssl), PHP-FPM 8.3 + extensies, MariaDB, cron, firewalld-regels,
#    SELinux-instellingen, de RzMon-code, database + gebruiker, config.php,
#    tabellen, Apache-vhost en de cronjob. Daarna maak je in de browser alleen
#    nog de beheerder aan.
#
#  Gebruik:
#    curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh | sudo bash
#  of, vanuit een gedownloade kopie:
#    sudo bash install/install-rocky.sh [opties]
#
#  Opnieuw uitvoeren is veilig: bestaande installaties worden bijgewerkt
#  (git pull + tabellen bijwerken); config.php en de database blijven staan.
# =============================================================================
set -Eeuo pipefail

# ----------------------------------------------------------------- standaarden
REPO_URL="https://github.com/rembrand20/rzmon.git"
BRANCH="main"
INSTALL_DIR="/var/www/rzmon"
DB_NAME="rzmon"
DB_USER="rzmon"
DB_PASS=""                 # leeg = willekeurig wachtwoord genereren
SERVER_NAME=""             # leeg = hostname
TIMEZONE="Europe/Amsterdam"
WITH_HTTPS=1               # self-signed HTTPS op poort 443 naast HTTP
DO_FIREWALL=1
DO_UPDATE=0                # dnf update vooraf
WRITE_CONFIG=1             # 0 = databasegegevens zelf in de browser invullen
ASSUME_YES=0
MODE="install"             # install | lock | unlock
REPO_SET=0

WEB_USER="apache"
LOG_FILE="/var/log/rzmon-install.log"
SUMMARY_FILE="/root/rzmon-install.txt"

# ----------------------------------------------------------------- uitvoer
if [ -t 1 ]; then
    C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_HEAD=$'\e[1;36m'; C_DIM=$'\e[2m'; C_OFF=$'\e[0m'
else
    C_OK=""; C_WARN=""; C_ERR=""; C_HEAD=""; C_DIM=""; C_OFF=""
fi
step() { printf '\n%s==> %s%s\n' "$C_HEAD" "$*" "$C_OFF"; }
ok()   { printf '    %s✔%s %s\n' "$C_OK" "$C_OFF" "$*"; }
info() { printf '    %s%s%s\n' "$C_DIM" "$*" "$C_OFF"; }
warn() { printf '    %s!%s %s\n' "$C_WARN" "$C_OFF" "$*"; }
die()  { printf '\n%s✘ %s%s\n' "$C_ERR" "$*" "$C_OFF" >&2; exit 1; }
trap 'die "Fout op regel $LINENO (zie $LOG_FILE). Het script kan veilig opnieuw worden uitgevoerd."' ERR

usage() {
    cat <<EOF
RzMon installatiescript voor Rocky Linux 10 / RHEL 10 / AlmaLinux 10

Gebruik: sudo bash install-rocky.sh [opties]

Opties:
  --dir PAD            Installatiemap                    (standaard: $INSTALL_DIR)
  --server-name NAAM   Hostnaam voor Apache              (standaard: hostname)
  --db-name NAAM       Databasenaam                      (standaard: $DB_NAME)
  --db-user NAAM       Databasegebruiker                 (standaard: $DB_USER)
  --db-pass WACHTWOORD Databasewachtwoord                (standaard: willekeurig)
  --timezone TZ        Tijdzone                          (standaard: $TIMEZONE)
  --branch NAAM        Git-branch                        (standaard: $BRANCH)
  --repo URL|MAP       Git-repository, of een lokale clone (bv. van een privé-repository)
                                                         (standaard: $REPO_URL)
  --no-https           Geen self-signed HTTPS op poort 443
  --no-firewall        firewalld niet aanpassen
  --no-config          Geen config.php schrijven: database zelf in de browser invullen
  --update             Eerst het systeem bijwerken (dnf update)
  -y, --yes            Niet om bevestiging vragen
  --lock               Na de browser-setup: schrijfrechten van de webserver weer intrekken
  --unlock             Schrijfrechten tijdelijk teruggeven (om via Setup config.php te wijzigen)
  -h, --help           Deze hulp
EOF
}

# ----------------------------------------------------------------- opties
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) INSTALL_DIR="${2:?}"; shift ;;
        --server-name) SERVER_NAME="${2:?}"; shift ;;
        --db-name) DB_NAME="${2:?}"; shift ;;
        --db-user) DB_USER="${2:?}"; shift ;;
        --db-pass) DB_PASS="${2:?}"; shift ;;
        --timezone) TIMEZONE="${2:?}"; shift ;;
        --branch) BRANCH="${2:?}"; shift ;;
        --repo) REPO_URL="${2:?}"; REPO_SET=1; shift ;;
        --no-https) WITH_HTTPS=0 ;;
        --no-firewall) DO_FIREWALL=0 ;;
        --no-config) WRITE_CONFIG=0 ;;
        --update) DO_UPDATE=1 ;;
        -y|--yes) ASSUME_YES=1 ;;
        --lock) MODE="lock" ;;
        --unlock) MODE="unlock" ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Onbekende optie: $1" ;;
    esac
    shift
done
INSTALL_DIR="${INSTALL_DIR%/}"

# ----------------------------------------------------------------- hulpfuncties
# Een lokale map als bron (bv. ~/rzmon, een eigen clone van een privé-repository)
# is van een andere gebruiker dan root; git weigert die dan ("dubious ownership").
# "git -c safe.directory=…" werkt niet voor lokale bronnen, een tijdelijk globaal
# config-bestand wel. Alleen voor deze git-aanroepen; ~/.gitconfig blijft ongemoeid.
GIT_SAFE_CFG=""
if [ -d "$REPO_URL" ]; then
    REPO_URL=$(cd "$REPO_URL" && pwd)
    GIT_SAFE_CFG=$(mktemp)
    printf '[safe]\n\tdirectory = %s\n\tdirectory = %s/.git\n' "$REPO_URL" "$REPO_URL" > "$GIT_SAFE_CFG"
    trap 'rm -f "$GIT_SAFE_CFG"' EXIT
fi
gitc() {
    if [ -n "$GIT_SAFE_CFG" ]; then
        GIT_CONFIG_GLOBAL="$GIT_SAFE_CFG" git "$@"
    else
        git "$@"
    fi
}

selinux_on() { command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" != "Disabled" ]; }

as_web() { runuser -u "$WEB_USER" -- "$@"; }

primary_ip() {
    ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit }}'
}

random_password() {
    local p
    p=$(openssl rand -base64 48 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 24) || true
    [ ${#p} -ge 20 ] || p=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24) || true
    printf '%s' "$p"
}

sql_escape() { printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g"; }

# Schrijfrechten voor de setup-pagina (map + SELinux-label)
unlock_dir() {
    chown "$WEB_USER" "$INSTALL_DIR"
    if selinux_on; then
        chcon -t httpd_sys_rw_content_t "$INSTALL_DIR"
        [ -f "$INSTALL_DIR/config.php" ] && chcon -t httpd_sys_rw_content_t "$INSTALL_DIR/config.php"
    fi
    return 0
}

lock_dir() {
    chown root:"$WEB_USER" "$INSTALL_DIR"
    chmod 750 "$INSTALL_DIR"
    if [ -f "$INSTALL_DIR/config.php" ]; then
        chown root:"$WEB_USER" "$INSTALL_DIR/config.php"
        chmod 640 "$INSTALL_DIR/config.php"
    fi
    selinux_on && restorecon -R "$INSTALL_DIR"
    return 0
}

# ----------------------------------------------------------------- controles
[ "$(id -u)" -eq 0 ] || die "Voer dit script uit als root: sudo bash $0"
[ -r /etc/os-release ] || die "Kan /etc/os-release niet lezen"
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
    rocky|rhel|almalinux) ;;
    *) die "Dit script is voor Rocky Linux / RHEL / AlmaLinux 10 (gevonden: ${PRETTY_NAME:-onbekend})" ;;
esac
case "${VERSION_ID:-}" in
    10*) ;;
    *) die "Dit script is voor versie 10 (gevonden: ${PRETTY_NAME:-onbekend})" ;;
esac
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "--db-name: alleen letters, cijfers en _"
[[ "$DB_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "--db-user: alleen letters, cijfers en _"
[ -e "/usr/share/zoneinfo/$TIMEZONE" ] || die "Onbekende tijdzone: $TIMEZONE"

# ----------------------------------------------------------------- --lock / --unlock
if [ "$MODE" = "lock" ]; then
    [ -d "$INSTALL_DIR" ] || die "$INSTALL_DIR bestaat niet"
    lock_dir
    ok "Vergrendeld: de webserver kan niet meer schrijven in $INSTALL_DIR"
    exit 0
fi
if [ "$MODE" = "unlock" ]; then
    [ -d "$INSTALL_DIR" ] || die "$INSTALL_DIR bestaat niet"
    unlock_dir
    ok "Ontgrendeld: Setup kan config.php weer opslaan. Vergeet daarna niet: sudo bash $0 --lock"
    exit 0
fi

# ----------------------------------------------------------------- bevestigen
exec > >(tee -a "$LOG_FILE") 2>&1
printf '%sRzMon installatie op %s%s\n' "$C_HEAD" "${PRETTY_NAME}" "$C_OFF"
info "Logbestand: $LOG_FILE"
cat <<EOF

  Installatiemap : $INSTALL_DIR
  Database       : $DB_NAME (gebruiker $DB_USER)
  HTTPS (443)    : $([ $WITH_HTTPS = 1 ] && echo "ja, self-signed" || echo "nee")
  Firewall       : $([ $DO_FIREWALL = 1 ] && echo "http/https openzetten" || echo "niet aanpassen")
  config.php     : $([ $WRITE_CONFIG = 1 ] && echo "door dit script (database is meteen klaar)" || echo "zelf in de browser")
EOF
if [ "$ASSUME_YES" -ne 1 ] && [ -r /dev/tty ]; then
    printf '\nDoorgaan? [J/n] '
    read -r answer </dev/tty || answer=""
    case "${answer,,}" in n|nee|no) die "Afgebroken" ;; esac
fi

# ----------------------------------------------------------------- 1. pakketten
step "1/9 Pakketten installeren"
if [ "$DO_UPDATE" -eq 1 ]; then
    dnf -y update
    ok "Systeem bijgewerkt"
fi

dnf -y install \
    httpd mod_ssl \
    php php-fpm php-cli php-common php-mysqlnd php-mbstring php-process php-opcache php-zip \
    mariadb-server mariadb \
    iputils iproute cronie git curl openssl tar unzip \
    policycoreutils policycoreutils-python-utils
ok "httpd, PHP-FPM, MariaDB en hulpprogramma's geïnstalleerd"

# Handig maar niet noodzakelijk: niet afbreken als een pakket ontbreekt
for pkg in php-intl php-xml php-gd; do
    dnf -y install "$pkg" >/dev/null 2>&1 && info "extra: $pkg" || warn "Optioneel pakket $pkg niet beschikbaar (geen probleem)"
done

php_version=$(php -r 'echo PHP_VERSION;')
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || die "PHP $php_version is te oud (minimaal 8.1)"
for ext in pdo_mysql curl mbstring; do
    php -m | grep -qix "$ext" || die "PHP-extensie $ext ontbreekt"
done
ok "PHP $php_version met pdo_mysql, curl en mbstring"

# ----------------------------------------------------------------- 2. PHP
step "2/9 PHP instellen"
cat > /etc/php.d/90-rzmon.ini <<EOF
; RzMon – aangemaakt door install-rocky.sh
date.timezone = $TIMEZONE
expose_php = Off
display_errors = Off
log_errors = On
EOF
ok "/etc/php.d/90-rzmon.ini (tijdzone $TIMEZONE)"

# ----------------------------------------------------------------- 3. diensten
step "3/9 Diensten starten"
systemctl enable --now mariadb php-fpm crond >/dev/null
systemctl restart php-fpm
ok "mariadb, php-fpm en crond draaien en starten automatisch"

# ----------------------------------------------------------------- 4. MariaDB
step "4/9 Database"
db_pass_note="(ongewijzigd, staat in config.php)"
if [ "$WRITE_CONFIG" -eq 1 ] && [ -f "$INSTALL_DIR/config.php" ] && [ -z "$DB_PASS" ]; then
    # Bestaande installatie: wachtwoord uit config.php gebruiken
    DB_PASS=$(php -r '$c = require $argv[1]; echo $c["db"]["pass"] ?? "";' "$INSTALL_DIR/config.php")
    info "Bestaande config.php gevonden: huidig databasewachtwoord wordt hergebruikt"
fi
if [ -z "$DB_PASS" ]; then
    DB_PASS=$(random_password)
    db_pass_note="(gegenereerd)"
fi
esc_pass=$(sql_escape "$DB_PASS")

mariadb <<SQL
-- Standaardinstallatie opschonen (zoals mariadb-secure-installation)
DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS ''@'$(hostname)';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db = 'test' OR Db = 'test\\_%';

CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$esc_pass';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$esc_pass';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "Database '$DB_NAME' en gebruiker '$DB_USER'@'localhost' klaar $db_pass_note"

# ----------------------------------------------------------------- 5. code
step "5/9 RzMon ophalen"
old_version=$(cat "$INSTALL_DIR/VERSION" 2>/dev/null || true)
if [ -d "$INSTALL_DIR/.git" ]; then
    # Na de setup kan de map (tijdelijk) van apache zijn; git als root wil een root-map
    chown root:"$WEB_USER" "$INSTALL_DIR"
    [ "$REPO_SET" -eq 1 ] && gitc -C "$INSTALL_DIR" remote set-url origin "$REPO_URL"
    # Dit script zet zelf de bestandsrechten (640/755); git moet die niet als lokale wijziging zien,
    # anders weigert "git pull" met "Your local changes … would be overwritten"
    gitc -C "$INSTALL_DIR" config core.fileMode false
    gitc -C "$INSTALL_DIR" fetch --quiet origin "$BRANCH"
    gitc -C "$INSTALL_DIR" checkout --quiet "$BRANCH"
    gitc -C "$INSTALL_DIR" pull --quiet --ff-only origin "$BRANCH"
    new_version=$(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo "?")
    if [ -n "$old_version" ] && [ "$old_version" != "$new_version" ]; then
        ok "RzMon bijgewerkt van $old_version naar $new_version (zie CHANGELOG.md)"
    else
        ok "RzMon $new_version is de nieuwste versie"
    fi
elif [ -e "$INSTALL_DIR" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
    die "$INSTALL_DIR bestaat al en is geen git-checkout. Kies een andere map met --dir of maak hem leeg."
else
    mkdir -p "$(dirname "$INSTALL_DIR")"
    gitc clone --quiet --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
    ok "RzMon $(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo '?') gekloond uit $REPO_URL"
fi

chown -R root:"$WEB_USER" "$INSTALL_DIR"
find "$INSTALL_DIR" -path "$INSTALL_DIR/.git" -prune -o -type d -exec chmod 750 {} +
find "$INSTALL_DIR" -path "$INSTALL_DIR/.git" -prune -o -type f -exec chmod 640 {} +
chmod 755 "$INSTALL_DIR/agent/rzmon-agent.sh" "$INSTALL_DIR/install/install-rocky.sh" "$INSTALL_DIR/install/install-helper.sh"
ok "Rechten gezet (root:$WEB_USER, mappen 750, bestanden 640)"

# ----------------------------------------------------------------- 6. config + tabellen
step "6/9 Configuratie en tabellen"
ip_addr=$(primary_ip || true)
ip_addr=${ip_addr:-$(hostname -I 2>/dev/null | awk '{print $1}')}
[ -n "$SERVER_NAME" ] || SERVER_NAME=$(hostname -f 2>/dev/null || hostname)

if [ "$WRITE_CONFIG" -eq 1 ]; then
    if [ -f "$INSTALL_DIR/config.php" ]; then
        info "config.php bestaat al en blijft ongewijzigd"
    else
        RZ_DB_NAME="$DB_NAME" RZ_DB_USER="$DB_USER" RZ_DB_PASS="$DB_PASS" RZ_TZ="$TIMEZONE" \
        RZ_BASE="http://${ip_addr:-$SERVER_NAME}" \
        php -r '
            require $argv[1] . "/src/bootstrap.php";
            $cfg = Installer::mergedConfig([
                "db" => ["host" => "localhost", "port" => 3306, "name" => getenv("RZ_DB_NAME"),
                         "user" => getenv("RZ_DB_USER"), "pass" => getenv("RZ_DB_PASS")],
                "timezone" => getenv("RZ_TZ"),
                "ping_bin" => "/bin/ping",
                "base_url" => getenv("RZ_BASE"),
            ]);
            file_put_contents($argv[1] . "/config.php", Installer::renderConfig($cfg));
        ' "$INSTALL_DIR"
        chown root:"$WEB_USER" "$INSTALL_DIR/config.php"
        chmod 640 "$INSTALL_DIR/config.php"
        ok "config.php geschreven"
    fi
    as_web php "$INSTALL_DIR/install/install.php" --upgrade >/dev/null
    ok "Tabellen aangemaakt/bijgewerkt"
else
    info "--no-config: databasegegevens vul je in de browser in"
fi

# ----------------------------------------------------------------- 7. SELinux
step "7/9 SELinux"
if selinux_on; then
    setsebool -P httpd_can_network_connect_db 1
    setsebool -P httpd_can_network_connect 1
    restorecon -R "$INSTALL_DIR"
    ok "SELinux $(getenforce): database- en netwerktoegang voor PHP toegestaan, labels hersteld"
else
    warn "SELinux staat uit; overgeslagen"
fi
if [ "$WRITE_CONFIG" -eq 1 ]; then
    # config.php staat er al: de browser-setup hoeft alleen nog een beheerder aan te maken
    # (gaat via de database), dus de map blijft dicht
    lock_dir
    ok "Installatiemap vergrendeld (instellingen wijzigen via Setup? eerst: --unlock)"
else
    # De setup-pagina moet config.php kunnen aanmaken
    unlock_dir
    ok "Setup mag tijdelijk schrijven in $INSTALL_DIR (vergrendel na de setup met --lock)"
fi

# ----------------------------------------------------------------- 8. Apache
step "8/9 Apache"
[ -f /etc/httpd/conf.d/welcome.conf ] && mv /etc/httpd/conf.d/welcome.conf /etc/httpd/conf.d/welcome.conf.uit

vhost_dir_block() {
    cat <<EOF
    DocumentRoot $INSTALL_DIR/public

    <Directory $INSTALL_DIR/public>
        Require all granted
        AllowOverride None
        Options -Indexes
        DirectoryIndex index.php
        # REST API op /v1.php/status en "Authorization: Bearer" doorgeven aan PHP-FPM
        AcceptPathInfo On
        CGIPassAuth On
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"
EOF
}

{
    echo "# RzMon – aangemaakt door install-rocky.sh"
    echo "<VirtualHost *:80>"
    echo "    ServerName $SERVER_NAME"
    [ -n "$ip_addr" ] && echo "    ServerAlias $ip_addr"
    vhost_dir_block
    echo "    ErrorLog  /var/log/httpd/rzmon_error.log"
    echo "    CustomLog /var/log/httpd/rzmon_access.log combined"
    echo "</VirtualHost>"
    if [ "$WITH_HTTPS" -eq 1 ]; then
        echo
        echo "<VirtualHost *:443>"
        echo "    ServerName $SERVER_NAME"
        [ -n "$ip_addr" ] && echo "    ServerAlias $ip_addr"
        vhost_dir_block
        echo "    SSLEngine on"
        echo "    # Self-signed certificaat van mod_ssl; vervang door Let's Encrypt (zie INSTALL-ROCKY-LINUX-10.md)"
        echo "    SSLCertificateFile    /etc/pki/tls/certs/localhost.crt"
        echo "    SSLCertificateKeyFile /etc/pki/tls/private/localhost.key"
        echo "    ErrorLog  /var/log/httpd/rzmon_ssl_error.log"
        echo "    CustomLog /var/log/httpd/rzmon_ssl_access.log combined"
        echo "</VirtualHost>"
    fi
} > /etc/httpd/conf.d/rzmon.conf

if [ "$WITH_HTTPS" -eq 1 ] && [ ! -f /etc/pki/tls/certs/localhost.crt ]; then
    # Normaal maakt httpd-init.service dit certificaat bij de eerste start
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout /etc/pki/tls/private/localhost.key -out /etc/pki/tls/certs/localhost.crt \
        -subj "/CN=$SERVER_NAME" -addext "subjectAltName=DNS:$SERVER_NAME${ip_addr:+,IP:$ip_addr}" >/dev/null 2>&1
    chmod 600 /etc/pki/tls/private/localhost.key
    info "Self-signed certificaat aangemaakt"
fi

if ss -Htlnp 2>/dev/null | awk '$4 ~ /:80$/' | grep -qv httpd; then
    warn "Poort 80 is al in gebruik door een ander programma (nginx?). Stop dat eerst."
fi
apachectl configtest >/dev/null 2>&1 || { apachectl configtest; die "Apache-configuratie bevat een fout"; }
systemctl enable --now httpd >/dev/null
systemctl reload httpd
ok "Virtuele host /etc/httpd/conf.d/rzmon.conf actief$([ $WITH_HTTPS = 1 ] && echo ' (HTTP + HTTPS)')"

if [ "$DO_FIREWALL" -eq 1 ] && systemctl is-active --quiet firewalld; then
    firewall-cmd --quiet --permanent --add-service=http
    [ "$WITH_HTTPS" -eq 1 ] && firewall-cmd --quiet --permanent --add-service=https
    firewall-cmd --quiet --reload
    ok "firewalld: http$([ $WITH_HTTPS = 1 ] && echo '/https') open"
elif [ "$DO_FIREWALL" -eq 1 ]; then
    info "firewalld draait niet; overgeslagen"
fi

# ----------------------------------------------------------------- 9. cron
step "9/9 Cron"
printf '# RzMon – checks elke minuut\n* * * * * %s /usr/bin/php %s/cron/run.php >/dev/null 2>&1\n' "$WEB_USER" "$INSTALL_DIR" > /etc/cron.d/rzmon
chmod 644 /etc/cron.d/rzmon
ok "/etc/cron.d/rzmon (als gebruiker $WEB_USER)"
if [ "$WRITE_CONFIG" -eq 1 ]; then
    as_web php "$INSTALL_DIR/cron/run.php" >/dev/null && ok "Eerste testrun van de checks geslaagd"
fi
# Hulpdienst: laat "Databasewachtwoord vernieuwen" in RzMon werken terwijl de map vergrendeld is
if bash "$INSTALL_DIR/install/install-helper.sh" --group "$WEB_USER" >/dev/null; then
    ok "Hulpdienst rzmon-helper (databasewachtwoord vernieuwen vanuit RzMon)"
else
    warn "Hulpdienst niet geïnstalleerd; databasewachtwoord vernieuwen kan via: sudo php $INSTALL_DIR/install/install.php --rotate-db-password"
fi

# ----------------------------------------------------------------- controle
http_code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $SERVER_NAME" http://127.0.0.1/ || true)
conf_code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $SERVER_NAME" http://127.0.0.1/config.php || true)
[ "$conf_code" = "404" ] && ok "config.php is niet via het web bereikbaar (404)" || warn "config.php gaf HTTP $conf_code (verwacht 404)"
case "$http_code" in
    200|302) ok "RzMon antwoordt (HTTP $http_code)" ;;
    *) warn "RzMon gaf HTTP $http_code; kijk in /var/log/httpd/rzmon_error.log" ;;
esac

# ----------------------------------------------------------------- samenvatting
url="http://${ip_addr:-$SERVER_NAME}/"
{
    echo "RzMon installatie – $(date '+%Y-%m-%d %H:%M')"
    echo "URL            : $url"
    [ "$WITH_HTTPS" -eq 1 ] && echo "HTTPS          : https://${ip_addr:-$SERVER_NAME}/ (self-signed)"
    echo "Installatiemap : $INSTALL_DIR"
    echo "Database       : $DB_NAME"
    echo "DB-gebruiker   : $DB_USER"
    echo "DB-wachtwoord  : $DB_PASS"
    echo "Logbestand     : $LOG_FILE"
} > "$SUMMARY_FILE"
chmod 600 "$SUMMARY_FILE"

cat <<EOF

${C_OK}════════════════════════════════════════════════════════════════════${C_OFF}
${C_OK}  RzMon $(cat "$INSTALL_DIR/VERSION" 2>/dev/null) is geïnstalleerd 🎉${C_OFF}
${C_OK}════════════════════════════════════════════════════════════════════${C_OFF}

  1. Open vanaf een computer op je lokale netwerk:

       ${C_HEAD}$url${C_OFF}

$( if [ "$WRITE_CONFIG" -eq 1 ]; then
    echo "     De database is al gekoppeld. Maak in stap 4 van de setup je"
    echo "     beheerder aan; daarna kom je op het dashboard."
else
    echo "     Vul bij 'Database' in: host localhost, database $DB_NAME,"
    echo "     gebruiker $DB_USER, wachtwoord: zie $SUMMARY_FILE"
fi )

$( if [ "$WRITE_CONFIG" -eq 1 ]; then
    echo "  2. Wil je later in Setup de database- of PHP-instellingen wijzigen?"
    echo "     Ontgrendel dan eerst, en vergrendel daarna weer:"
    echo
    echo "       ${C_HEAD}sudo bash $INSTALL_DIR/install/install-rocky.sh --unlock${C_OFF}"
    echo "       ${C_HEAD}sudo bash $INSTALL_DIR/install/install-rocky.sh --lock${C_OFF}"
else
    echo "  2. Na de setup de schrijfrechten weer intrekken:"
    echo
    echo "       ${C_HEAD}sudo bash $INSTALL_DIR/install/install-rocky.sh --lock${C_OFF}"
fi )

  3. Daarna: Pushover koppelen, servers en checks toevoegen
     → $INSTALL_DIR/HANDLEIDING.md (vanaf hoofdstuk 6)

  Databasegegevens staan in ${SUMMARY_FILE} (alleen leesbaar voor root).
  Bijwerken naar een nieuwe versie: voer dit script gewoon opnieuw uit.

EOF
