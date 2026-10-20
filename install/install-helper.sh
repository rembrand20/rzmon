#!/usr/bin/env bash
# RzMon-hulpdienst installeren (root). Maakt ook de back-upmap /var/lib/rzmon/backups.
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
HAS_SYSTEMD=0
command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ] && HAS_SYSTEMD=1

if [ "$MODE" = uninstall ]; then
    if [ "$HAS_SYSTEMD" -eq 1 ]; then
        systemctl disable --now rzmon-helper.path >/dev/null 2>&1 || true
    fi
    rm -f "$UNIT.path" "$UNIT.service" "$HELPER_DIR/helper.installed" "$HELPER_DIR/rotate.request" "$HELPER_DIR/rotate.result"
    [ "$HAS_SYSTEMD" -eq 1 ] && systemctl daemon-reload
    ok "Hulpdienst verwijderd (back-ups in $HELPER_DIR/backups blijven staan)"
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

# Map voor verzoek en resultaat, en voor de back-ups: root en de webserver, niemand anders
install -d -m 0770 -o root -g "$GROUP" "$HELPER_DIR" "$HELPER_DIR/backups"
# Back-ups die root eerder maakte: ook leesbaar voor de webserver
find "$HELPER_DIR/backups" -maxdepth 1 -name 'rzmon-backup-*.zip' -exec chgrp "$GROUP" {} + -exec chmod 0640 {} +
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
ok "$HELPER_DIR en $HELPER_DIR/backups (root:$GROUP, 0770)"

if [ "$HAS_SYSTEMD" -eq 0 ]; then
    printf '  ! Geen systemd: de hulpdienst voor de knop "Databasewachtwoord vernieuwen" is overgeslagen.\n'
    printf '    Vernieuwen kan wel in de terminal: sudo php %s/install/install.php --rotate-db-password\n' "$DIR"
    exit 0
fi

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
echo "  De knop Instellingen → Database → \"Databasewachtwoord vernieuwen\" werkt nu,"
echo "  en back-ups komen in $HELPER_DIR/backups."
