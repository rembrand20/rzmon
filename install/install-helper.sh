#!/usr/bin/env bash
# RzMon-hulpdienst installeren (root).
#
# Laat de knop "Databasewachtwoord vernieuwen" in RzMon werken terwijl de RzMon-map vergrendeld
# is (de webserver mag config.php dan niet wijzigen). De webpagina zet een verzoek in
# /var/lib/rzmon; een systemd-path-unit start dan als root:
#     php install/install.php --rotate-db-password --helper
# De hulpdienst doet niets anders: hij voert geen commando's van de webpagina uit.
#
#   sudo bash install/install-helper.sh              # installeren (webgroep automatisch)
#   sudo bash install/install-helper.sh --group apache
#   sudo bash install/install-helper.sh --uninstall
set -euo pipefail

DIR=$(cd "$(dirname "$0")/.." && pwd)
HELPER_DIR=/var/lib/rzmon
UNIT=/etc/systemd/system/rzmon-helper
GROUP=""
MODE=install

die() { printf '✘ %s\n' "$*" >&2; exit 1; }
ok()  { printf '  ✔ %s\n' "$*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --group) GROUP="${2:?}"; shift ;;
        --uninstall) MODE=uninstall ;;
        -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
        *) die "Onbekende optie: $1" ;;
    esac
    shift
done

[ "$(id -u)" -eq 0 ] || die "Voer dit uit als root (met sudo)."
command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ] || die "systemd is nodig voor de hulpdienst. Gebruik anders: sudo php $DIR/install/install.php --rotate-db-password"

if [ "$MODE" = uninstall ]; then
    systemctl disable --now rzmon-helper.path >/dev/null 2>&1 || true
    rm -f "$UNIT.path" "$UNIT.service" "$HELPER_DIR/helper.installed" "$HELPER_DIR/rotate.request" "$HELPER_DIR/rotate.result"
    systemctl daemon-reload
    ok "Hulpdienst verwijderd"
    exit 0
fi

# Groep van de webserver: die van config.php, anders de bekende namen
if [ -z "$GROUP" ]; then
    GROUP=$(stat -c %G "$DIR/config.php" 2>/dev/null || true)
    if [ -z "$GROUP" ] || [ "$GROUP" = root ]; then
        for g in apache www-data nginx http; do getent group "$g" >/dev/null && { GROUP=$g; break; }; done
    fi
fi
getent group "$GROUP" >/dev/null || die "Webservergroep niet gevonden; geef hem op met --group"
PHP=$(command -v php) || die "php niet gevonden"

# Map voor verzoek en resultaat: root en de webserver, niemand anders
install -d -m 0770 -o root -g "$GROUP" "$HELPER_DIR"
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" != Disabled ]; then
    # SELinux: de webserver mag in deze map schrijven
    if command -v semanage >/dev/null 2>&1; then
        semanage fcontext -a -t httpd_var_lib_t "$HELPER_DIR(/.*)?" 2>/dev/null \
            || semanage fcontext -m -t httpd_var_lib_t "$HELPER_DIR(/.*)?" 2>/dev/null || true
        restorecon -R "$HELPER_DIR"
    else
        chcon -R -t httpd_var_lib_t "$HELPER_DIR"
    fi
fi
ok "$HELPER_DIR (root:$GROUP, 0770)"

cat >"$UNIT.path" <<EOF
[Unit]
Description=RzMon-hulpdienst: wacht op een verzoek uit de webinterface

[Path]
PathExists=$HELPER_DIR/rotate.request

[Install]
WantedBy=multi-user.target
EOF
cat >"$UNIT.service" <<EOF
[Unit]
Description=RzMon-hulpdienst: databasewachtwoord vernieuwen

[Service]
Type=oneshot
ExecStart=$PHP $DIR/install/install.php --rotate-db-password --helper
EOF
systemctl daemon-reload
systemctl enable --now rzmon-helper.path >/dev/null
echo "$DIR" >"$HELPER_DIR/helper.installed"
chmod 0644 "$HELPER_DIR/helper.installed"
ok "systemd: rzmon-helper.path (actief) + rzmon-helper.service"
echo
echo "  De knop Instellingen → Database → \"Databasewachtwoord vernieuwen\" werkt nu."
