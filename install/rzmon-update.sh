#!/usr/bin/env bash
# RzMon bijwerken vanuit de webinterface (Instellingen → ⬆ RzMon bijwerken).
#
# Gestart door de hulpdienst (systemd: rzmon-update.path → rzmon-update.service) als de webpagina
# /var/lib/rzmon/update.request neerzet. install-helper.sh zet een kopie van dit script in
# /usr/local/sbin/rzmon-update; die kopie kan de webserver niet wijzigen.
#
# Wat het doet, altijd hetzelfde (van de webpagina komt alleen "wel of geen back-up"):
#   1. eventueel een back-up (php install/install.php --backup)
#   2. de nieuwste versie ophalen (git pull) en install/install-rocky.sh --yes uitvoeren,
#      precies zoals het handmatige updatecommando: rechten, SELinux, hulpdienst, tabellen
# Voortgang en uitkomst staan in /var/lib/rzmon/update.status (JSON) en update.log.
#
#   rzmon-update /var/www/rzmon
set -uo pipefail

DIR="${1:?Gebruik: rzmon-update <installatiemap>}"
DIR="${DIR%/}"
HELPER_DIR=/var/lib/rzmon
REQ="$HELPER_DIR/update.request"
STATUS="$HELPER_DIR/update.status"
LOG="$HELPER_DIR/update.log"

[ "$(id -u)" -eq 0 ] || { echo "Alleen als root" >&2; exit 1; }
[ -f "$REQ" ] || exit 0
backup=0
grep -q '"backup":[[:space:]]*true' "$REQ" 2>/dev/null && backup=1
rm -f "$REQ"

GROUP=$(stat -c %G "$HELPER_DIR")
from=$(cat "$DIR/VERSION" 2>/dev/null || echo "?")
started=$(date +%s)
: >"$LOG"
chgrp "$GROUP" "$LOG"
chmod 0640 "$LOG"

json_str() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037'; }
status() {   # status <state> <stap> <tekst>
    printf '{"state":"%s","step":"%s","message":"%s","from":"%s","to":"%s","started":%s,"at":%s}\n' \
        "$1" "$2" "$(json_str "$3")" "$(json_str "$from")" "$(json_str "${to:-}")" "$started" "$(date +%s)" >"$STATUS.tmp"
    chgrp "$GROUP" "$STATUS.tmp"
    chmod 0640 "$STATUS.tmp"
    mv -f "$STATUS.tmp" "$STATUS"
    printf '[%s] %s\n' "$(date '+%H:%M:%S')" "$3" >>"$LOG"
}
fail() { status error "${step:-}" "$1"; exit 1; }

# Veiligheid: dit script draait als root. Kan de webserver iets in de map veranderen (ontgrendeld met
# --unlock, of bestanden van een andere eigenaar), dan zou hij zo code als root kunnen laten uitvoeren.
step=check
status running check "Controleren"
[ -d "$DIR/.git" ] || fail "$DIR is geen git-checkout: bijwerken kan alleen bij een installatie met install-rocky.sh"
[ "$(stat -c %U "$DIR")" = root ] || fail "De map $DIR is ontgrendeld (--unlock). Vergrendel hem eerst: sudo bash $DIR/install/install-rocky.sh --lock"
# (ook config.php: die leest de back-up als root in)
odd=$(find "$DIR" \( ! -user root -o \( ! -type l -perm /0022 \) \) -print -quit 2>/dev/null)
[ -z "$odd" ] || fail "Niet veilig om als root bij te werken: $odd is niet van root of is schrijfbaar voor anderen. Zet de rechten goed met: sudo bash $DIR/install/install-rocky.sh --lock"

if [ "$backup" = 1 ]; then
    step=backup
    status running backup "Back-up maken"
    if ! php "$DIR/install/install.php" --backup >>"$LOG" 2>&1; then
        fail "Back-up mislukt; er is niets bijgewerkt (zie het logboek)"
    fi
    find "$HELPER_DIR/backups" -maxdepth 1 -name 'rzmon-backup-*.zip' -user root -exec chgrp "$GROUP" {} + -exec chmod 0640 {} + 2>/dev/null
fi

step=download
status running download "Nieuwste versie ophalen"
branch=$(git -C "$DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)
git -C "$DIR" config core.fileMode false
if ! git -C "$DIR" fetch --quiet origin "$branch" >>"$LOG" 2>&1 || ! git -C "$DIR" pull --quiet --ff-only origin "$branch" >>"$LOG" 2>&1; then
    fail "Ophalen van GitHub mislukt (zie het logboek); er is niets veranderd"
fi
to=$(cat "$DIR/VERSION" 2>/dev/null || echo "?")

step=install
status running install "Installeren: rechten, database en hulpdienst ($from → $to)"
# Het installatiescript is opnieuw uitvoeren-veilig; --yes: geen vragen
if ! bash "$DIR/install/install-rocky.sh" --yes --dir "$DIR" >>"$LOG" 2>&1; then
    fail "Installatiescript gaf een fout (zie het logboek). De nieuwe code staat er, maar controleer RzMon; de back-up staat klaar"
fi

step=klaar
status ok klaar "RzMon bijgewerkt van $from naar $to"
exit 0
