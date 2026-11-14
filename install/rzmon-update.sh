#!/usr/bin/env bash
# RzSense bijwerken vanuit de webinterface (Instellingen → ⬆ RzSense bijwerken).
#
# Gestart door de hulpdienst (systemd: rzmon-update.path → rzmon-update.service) als de webpagina
# /var/lib/rzmon/update.request neerzet. install-helper.sh zet een kopie van dit script in
# /usr/local/sbin/rzmon-update; die kopie kan de webserver niet wijzigen.
#
# Wat het doet, altijd hetzelfde (van de webpagina komt alleen "wel of geen back-up" en eventueel een versie, 1.90.0):
#   1. eventueel een back-up (php install/install.php --backup)
#   2. de nieuwste versie ophalen (git pull), of de gekozen versie (de commit waarin VERSION dat nummer kreeg),
#      en install/install-rocky.sh --yes uitvoeren,
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
# Gekozen versie (alleen cijfers en punten; anders de nieuwste)
want=$(sed -n 's/.*"version":[[:space:]]*"\([0-9]\{1,4\}\.[0-9]\{1,4\}\.[0-9]\{1,4\}\)".*/\1/p' "$REQ" 2>/dev/null | head -n 1)
rm -f "$REQ"

GROUP=$(stat -c %G "$HELPER_DIR")
from=$(cat "$DIR/VERSION" 2>/dev/null || echo "?")
started=$(date +%s)
: >"$LOG"
chgrp "$GROUP" "$LOG"
chmod 0640 "$LOG"

json_str() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037'; }
status() {   # status <state> <stap> <tekst> [procent] [onderdeel, bv. "3/9 Diensten starten"]
    printf '{"state":"%s","step":"%s","message":"%s","pct":%s,"detail":"%s","from":"%s","to":"%s","started":%s,"at":%s,"hb":10}\n' \
        "$1" "$2" "$(json_str "$3")" "${4:-null}" "$(json_str "${5:-}")" "$(json_str "$from")" "$(json_str "${to:-}")" "$started" "$(date +%s)" >"$STATUS.tmp"
    chgrp "$GROUP" "$STATUS.tmp"
    chmod 0640 "$STATUS.tmp"
    mv -f "$STATUS.tmp" "$STATUS"
    printf '[%s] %s\n' "$(date '+%H:%M:%S')" "$3" >>"$LOG"
}
fail() { status error "${step:-}" "$1"; finished=1; exit 1; }
# 1.221.0: duur per stap (voor het logboek en de melding na afloop)
t_last=$(date +%s); durs=""
lap() { local now; now=$(date +%s); durs="${durs:+$durs · }$1 $((now - t_last)) s"; t_last=$now; }

# Teken van leven: elke 10 seconden het statusbestand aanraken, zodat RzSense ziet dat de update nog loopt.
# Stopt het script onverwacht (zonder ok of fout), dan zegt het dat zelf.
( while sleep 10; do touch -c "$STATUS" 2>/dev/null; done ) &
hb_pid=$!
finished=0
trap 'kill "$hb_pid" 2>/dev/null; [ "$finished" = 1 ] || status error "${step:-}" "Bijwerken onverwacht gestopt (zie het logboek)"' EXIT

# Veiligheid: dit script draait als root. Kan de webserver iets in de map veranderen (ontgrendeld met
# --unlock, of bestanden van een andere eigenaar), dan zou hij zo code als root kunnen laten uitvoeren.
step=check
status running check "Controleren" 3
[ -d "$DIR/.git" ] || fail "$DIR is geen git-checkout: bijwerken kan alleen bij een installatie met install-rocky.sh"
[ "$(stat -c %U "$DIR")" = root ] || fail "De map $DIR is ontgrendeld (--unlock). Vergrendel hem eerst: sudo bash $DIR/install/install-rocky.sh --lock"
# (ook config.php: die leest de back-up als root in)
odd=$(find "$DIR" \( ! -user root -o \( ! -type l -perm /0022 \) \) -print -quit 2>/dev/null)
[ -z "$odd" ] || fail "Niet veilig om als root bij te werken: $odd is niet van root of is schrijfbaar voor anderen. Zet de rechten goed met: sudo bash $DIR/install/install-rocky.sh --lock"

lap Controleren

# 1.221.0: eerst ophalen (de bestanden veranderen dan nog niet), dan de back-up (snel, tenzij de database-opbouw
# verandert), en pas daarna overschakelen. Zo is de back-up klein als het kan en volledig als het moet.
step=download
branch=$(git -C "$DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)
git -C "$DIR" config core.fileMode false
if [ -n "$want" ]; then
    status running download "Versie $want ophalen" 10
    # Hele geschiedenis nodig om een oudere versie te vinden (een ondiepe kopie eerst aanvullen)
    if [ "$(git -C "$DIR" rev-parse --is-shallow-repository 2>/dev/null)" = true ]; then
        git -C "$DIR" fetch --quiet --unshallow origin "$branch" >>"$LOG" 2>&1 || fail "Ophalen van GitHub mislukt (zie het logboek); er is niets veranderd"
    else
        git -C "$DIR" fetch --quiet origin "$branch" >>"$LOG" 2>&1 || fail "Ophalen van GitHub mislukt (zie het logboek); er is niets veranderd"
    fi
    # De commit waarin VERSION dit nummer kreeg (nieuwste eerst)
    commit=""
    while read -r c; do
        [ "$(git -C "$DIR" show "$c:VERSION" 2>/dev/null)" = "$want" ] && { commit=$c; break; }
    done < <(git -C "$DIR" log --format=%H "origin/$branch" -- VERSION 2>/dev/null)
    [ -n "$commit" ] || fail "Versie $want niet gevonden op GitHub; er is niets veranderd"
    # 1.263.2: de handtekening van de agent komt in een aparte commit kort daarna ("Agent ondertekend", alleen *.sig).
    # Die erbij nemen, anders hoort de handtekening bij de vorige agent en weigeren de agents de update.
    while read -r c; do
        chg=$(git -C "$DIR" diff-tree --no-commit-id --name-only -r "$c" 2>/dev/null)
        [ -n "$chg" ] && ! grep -qv '\.sig$' <<<"$chg" || break
        commit=$c
    done < <(git -C "$DIR" rev-list --reverse --ancestry-path "$commit..origin/$branch" 2>/dev/null)
else
    status running download "Nieuwste versie ophalen" 10
    git -C "$DIR" fetch --quiet origin "$branch" >>"$LOG" 2>&1 || fail "Ophalen van GitHub mislukt (zie het logboek); er is niets veranderd"
    commit=$(git -C "$DIR" rev-parse "origin/$branch" 2>/dev/null)
    [ -n "$commit" ] || fail "Ophalen van GitHub mislukt (zie het logboek); er is niets veranderd"
fi
lap Ophalen

if [ "$backup" = 1 ]; then
    step=backup
    bk_args="--snel"
    if git -C "$DIR" diff --quiet HEAD "$commit" -- install/schema.sql src/Installer.php 2>/dev/null; then
        status running backup "Snelle back-up maken (instellingen; geschiedenis en logs blijven staan)" 20
    else
        status running backup "Volledige back-up maken (de database-opbouw verandert)" 20
        bk_args=""
    fi
    # shellcheck disable=SC2086
    if ! php "$DIR/install/install.php" --backup $bk_args >>"$LOG" 2>&1; then
        fail "Back-up mislukt; er is niets bijgewerkt (zie het logboek)"
    fi
    find "$HELPER_DIR/backups" -maxdepth 1 -name 'rzmon-backup-*.zip' -user root -exec chgrp "$GROUP" {} + -exec chmod 0640 {} + 2>/dev/null
    lap Back-up
fi

step=download
if [ -n "$want" ]; then
    git -C "$DIR" reset --quiet --hard "$commit" >>"$LOG" 2>&1 || fail "Overschakelen naar $want mislukt (zie het logboek)"
else
    # Na een eerdere keuze voor een oudere versie gaat dit gewoon weer vooruit (fast-forward)
    git -C "$DIR" merge --quiet --ff-only "$commit" >>"$LOG" 2>&1 || fail "Bijwerken van de bestanden mislukt (zie het logboek); er is niets veranderd"
fi
to=$(cat "$DIR/VERSION" 2>/dev/null || echo "?")
# 1.221.1: meteen de database bijwerken, zodat agents die nu een rapport sturen niet tegen ontbrekende kolommen lopen
php "$DIR/install/install.php" --upgrade >>"$LOG" 2>&1 || true

step=install
status running install "Installeren: rechten, database en hulpdienst ($from → $to)" 40
# Het installatiescript is opnieuw uitvoeren-veilig; --yes: geen vragen; --quick (1.221.0): alleen wat nodig is. Zijn stappen ("==> 3/9 Diensten starten")
# gaan als voortgang naar de webpagina: 40% tot 98% over de stappen verdeeld.
quick=""
grep -q -- '--quick)' "$DIR/install/install-rocky.sh" 2>/dev/null && quick="--quick"   # oudere versies kennen --quick niet
# shellcheck disable=SC2086
bash "$DIR/install/install-rocky.sh" --yes $quick --dir "$DIR" 2>&1 | while IFS= read -r line; do
    printf '%s\n' "$line" >>"$LOG"
    if [[ "$line" =~ ==\>\ ([0-9]+)/([0-9]+)\ (.*)$ ]] && [ "${BASH_REMATCH[2]}" -gt 0 ]; then
        status running install "Installeren ($from → $to)" $((40 + 58 * (BASH_REMATCH[1] - 1) / BASH_REMATCH[2])) \
            "${BASH_REMATCH[1]}/${BASH_REMATCH[2]} ${BASH_REMATCH[3]}"
    fi
done
if [ "${PIPESTATUS[0]}" -ne 0 ]; then
    fail "Installatiescript gaf een fout (zie het logboek). De nieuwe code staat er, maar controleer RzSense; de back-up staat klaar"
fi

lap Installeren
step=klaar
total=$(( $(date +%s) - started ))
printf '[%s] Duur: %s · totaal %s s\n' "$(date '+%H:%M:%S')" "$durs" "$total" >>"$LOG"
if [ -n "$want" ] && [ "$(printf '%s\n%s\n' "$to" "$from" | sort -V | head -n 1)" = "$to" ] && [ "$to" != "$from" ]; then
    status ok klaar "RzSense teruggezet van $from naar $to in $total s" 100 "$durs"
else
    status ok klaar "RzSense bijgewerkt van $from naar $to in $total s" 100 "$durs"
fi
finished=1
exit 0
