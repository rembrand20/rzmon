#!/usr/bin/env bash
# RzSense: proef met de Go-agent (het commando "rzsense") náást de bash-agent.
#
#   Installeren:  curl -fsSL '<adres van RzSense>/api.php?action=agent_go_install' | sudo bash
#   Weghalen:     curl -fsSL '<adres van RzSense>/api.php?action=agent_go_install' | sudo bash -s -- --remove
#
# Wat dit doet: het bestand /usr/local/bin/rzsense neerzetten (alleen als de handtekening van de ontwikkelaar klopt) en
# een timer maken die elke minuut "rzsense shadow" draait. Die proefronde meet hetzelfde als de bash-agent en stuurt het
# naar RzSense om te vergelijken. De bash-agent, zijn instellingen en zijn sleutel blijven zoals ze zijn: de Go-agent
# leest alleen mee. Weghalen kan altijd; daarna is de server precies zoals hij was.
set -u

DL_KEY=''
CONF=/etc/default/rzmon-agent
BIN=/usr/local/bin/rzsense
LINK=/usr/bin/rzsense   # sudo zoekt op RHEL, Rocky en Alma niet in /usr/local/bin
DATA=/var/lib/rzsense
UNIT=/etc/systemd/system/rzsense-shadow
FALLBACK=/etc/systemd/system/rzsense-fallback
FALLBACK_BIN=/usr/local/sbin/rzsense-fallback
CRON=/etc/cron.d/rzsense-shadow
# De publieke sleutel van de ontwikkelaar (ed25519), dezelfde als van de bash-agent en de Windows-agent
AGENT_SIGN_PUB="MCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM="

MODE=install; OLD_SSL=0
for a in "$@"; do
    case "$a" in
        --remove) MODE=remove ;;
        --old-openssl) OLD_SSL=1 ;;
        *) echo "Onbekende optie: $a (mogelijk: --remove, --old-openssl)" >&2; exit 2 ;;
    esac
done

ok() { printf '✔ %s\n' "$1"; }
info() { printf '  %s\n' "$1"; }
die() { printf '✘ %s\n' "$1" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "Start dit als root (sudo)."

has_systemd() { command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; }

remove_all() {
    if has_systemd; then
        # Deed de Go-agent het werk? Dan eerst de bash-agent weer aan, zodat de server nooit zonder agent zit
        if [ "$(cat "$DATA/mode" 2>/dev/null)" = active ]; then
            systemctl enable --now rzmon-agent.timer >/dev/null 2>&1 && ok "De bash-agent doet het werk weer (rzmon-agent.timer)"
            systemctl enable --now rzmon-agent-guard.timer >/dev/null 2>&1
        fi
        systemctl disable --now rzsense-shadow.timer rzsense-fallback.timer >/dev/null 2>&1
        rm -f "$UNIT.service" "$UNIT.timer" "$FALLBACK.service" "$FALLBACK.timer" "$FALLBACK_BIN"
        systemctl daemon-reload >/dev/null 2>&1
    fi
    if [ -L "$LINK" ] && [ "$(readlink "$LINK")" = "$BIN" ]; then rm -f "$LINK"; fi
    rm -f "$CRON" "$BIN"
    [ -d "$DATA" ] && rm -rf "$DATA"
}

if [ "$MODE" = remove ]; then
    remove_all
    ok "De Go-agent (rzsense) is weggehaald. De bash-agent is niet aangeraakt."
    exit 0
fi

[ -r "$CONF" ] || die "De bash-agent is hier niet geïnstalleerd ($CONF ontbreekt). Deze proef draait naast de bash-agent."
URL=$(sed -n 's/^MONITOR_URL="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CONF" | tail -1)
URL=${URL%/}
url_re='^https?://[][A-Za-z0-9.:-]+(/[A-Za-z0-9._~/-]*)?$'
[[ "$URL" =~ $url_re ]] || die "MONITOR_URL in $CONF ontbreekt of klopt niet."
command -v curl >/dev/null 2>&1 || die "curl ontbreekt."

case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    armv7l|armv8l) ARCH=armv7 ;;
    *) die "Voor deze processor ($(uname -m)) is er geen Go-agent. Deze server blijft op de bash-agent; daar is niets mis mee." ;;
esac

# Zelfde controle van het certificaat als de bash-agent: een vastgezette vingerafdruk, of gewoon
tls=()
KDIR=/var/lib/rzmon-agent
AGENT_DIR=$(sed -n 's/^AGENT_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CONF" | tail -1)
[ -n "$AGENT_DIR" ] && [ -d "$AGENT_DIR/keys" ] && KDIR="$AGENT_DIR/keys"
spec=""
[ -r "$KDIR/url.pins" ] && spec=$(awk -v u="$URL" '$1 == u { print $2; exit }' "$KDIR/url.pins")
[[ "$spec" == sha256//* ]] && tls=(-k --pinnedpubkey "$spec")
[ -s "$KDIR/agent.key" ] || die "Deze server heeft nog geen eigen sleutel. Koppel de bash-agent eerst met een koppelcode (RzSense → Agent Manager → 🔗 Koppelcode)."

sig_check() {  # sig_check <bestand> <handtekening (base64)>: 0 = klopt, 1 = klopt niet, 2 = niet te controleren (OpenSSL ouder dan 3)
    local d rc
    command -v openssl >/dev/null 2>&1 && openssl pkeyutl -help 2>&1 | grep -q -- '-rawin' || return 2
    d=$(mktemp -d "${TMPDIR:-/tmp}/rzsense-sig.XXXXXX") || return 2
    printf -- '-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n' "$AGENT_SIGN_PUB" >"$d/pub.pem"
    if ! base64 -d <"$2" >"$d/sig" 2>/dev/null || [ "$(wc -c <"$d/sig")" != 64 ]; then rm -rf "$d"; return 1; fi
    openssl pkeyutl -verify -pubin -inkey "$d/pub.pem" -rawin -in "$1" -sigfile "$d/sig" >/dev/null 2>&1; rc=$?
    rm -rf "$d"
    [ "$rc" = 0 ] && return 0 || return 1
}

work=$(mktemp -d /usr/local/bin/.rzsense-new.XXXXXX) || die "Kan niet schrijven in /usr/local/bin."
trap 'rm -rf "$work"' EXIT
kq=""; [ -n "$DL_KEY" ] && kq="&k=$DL_KEY"
curl -fsSL ${tls[@]+"${tls[@]}"} --connect-timeout 10 --max-time 120 "$URL/api.php?action=agent_go_bin&arch=$ARCH$kq" -o "$work/rzsense" \
    || die "Het bestand voor $ARCH is niet op te halen bij $URL. Heeft RzSense het al? (RzSense → Agent Manager → Go-agent)"
curl -fsSL ${tls[@]+"${tls[@]}"} --connect-timeout 10 --max-time 30 "$URL/api.php?action=agent_go_sig&arch=$ARCH$kq" -o "$work/rzsense.sig" \
    || die "De handtekening is niet op te halen bij $URL."
sig_check "$work/rzsense" "$work/rzsense.sig"
case $? in
    0) ok "Handtekening van de ontwikkelaar klopt" ;;
    2) if [ "$OLD_SSL" = 1 ]; then
           info "OpenSSL op deze server is ouder dan 3.0 en kan de handtekening niet controleren; je koos --old-openssl, dus toch door."
       else
           die "OpenSSL op deze server is ouder dan 3.0 en kan de handtekening niet controleren. Wil je toch door, voeg dan --old-openssl toe (bash -s -- --old-openssl)."
       fi ;;
    *) die "De handtekening klopt niet: het bestand is niet van de ontwikkelaar. Er is niets geïnstalleerd." ;;
esac
chmod 755 "$work/rzsense"
ver=$("$work/rzsense" version 2>/dev/null) || die "Het bestand kan hier niet draaien (staat /usr/local/bin op noexec?). Deze server blijft op de bash-agent."
mv -f "$work/rzsense" "$BIN" || die "Kan $BIN niet neerzetten."
(umask 077; mkdir -p "$DATA")
ok "$ver geïnstalleerd als $BIN"
# een verwijzing in /usr/bin, zodat ook "sudo rzsense …" werkt (alleen als daar niets anders staat)
if [ ! -e "$LINK" ] && [ ! -L "$LINK" ]; then ln -s "$BIN" "$LINK" 2>/dev/null || true; fi

if has_systemd; then
    rm -f "$CRON"
    cat >"$UNIT.service" <<EOF
[Unit]
Description=RzSense Go-agent: proefronde naast de bash-agent
After=network-online.target

[Service]
Type=oneshot
ExecStart=$BIN shadow --quiet
Nice=10
TimeoutStartSec=90
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
ReadWritePaths=$DATA /usr/local/bin $KDIR -/var/cache/rzmon-agent
EOF
    # Het bewakertje (een shellscript, los van de Go-agent): doet de Go-agent het werk en kwam er 10 minuten geen rapport
    # aan, dan gaat de bash-agent weer aan. Zo zit de server nooit zonder agent, ook als het Go-bestand stuk is.
    cat >"$FALLBACK_BIN" <<'EOS'
#!/bin/sh
# RzSense: terugval van de Go-agent op de bash-agent (zie install-rzsense.sh)
D=${RZSENSE_DATA:-/var/lib/rzsense}
[ "$(cat "$D/mode" 2>/dev/null)" = active ] || exit 0
last=$(cat "$D/last_ok" 2>/dev/null | tr -dc '0-9')
now=$(date +%s)
[ -n "$last" ] && [ $((now - last)) -lt 600 ] && exit 0
systemctl enable --now rzmon-agent.timer >/dev/null 2>&1
systemctl enable --now rzmon-agent-guard.timer >/dev/null 2>&1
echo shadow >"$D/mode"
echo "$now" >"$D/takeover.hold"
echo "$(date '+%Y-%m-%d %H:%M:%S') terug naar de bash-agent: 10 minuten geen rapport van de Go-agent (bewakertje)" >"$D/takeover.last"
EOS
    chmod 755 "$FALLBACK_BIN"
    cat >"$FALLBACK.service" <<EOF
[Unit]
Description=RzSense Go-agent: terugval op de bash-agent

[Service]
Type=oneshot
ExecStart=$FALLBACK_BIN
EOF
    cat >"$FALLBACK.timer" <<EOF
[Unit]
Description=RzSense Go-agent: elke 2 minuten kijken of de Go-agent nog rapporteert

[Timer]
OnBootSec=3min
OnUnitActiveSec=2min

[Install]
WantedBy=timers.target
EOF
    cat >"$UNIT.timer" <<EOF
[Unit]
Description=RzSense Go-agent: elke minuut een proefronde

[Timer]
OnCalendar=minutely
RandomizedDelaySec=20
Persistent=false

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload >/dev/null 2>&1
    systemctl enable --now rzsense-shadow.timer >/dev/null 2>&1 || die "De timer rzsense-shadow.timer start niet."
    systemctl enable --now rzsense-fallback.timer >/dev/null 2>&1 || info "Het bewakertje rzsense-fallback.timer start niet; overnemen kan dan niet."
    ok "Timer rzsense-shadow.timer loopt (elke minuut een proefronde)"
else
    printf '* * * * * root %s shadow --quiet >/dev/null 2>&1\n' "$BIN" >"$CRON"
    chmod 644 "$CRON"
    ok "Cronjob $CRON gemaakt (elke minuut een proefronde)"
fi

echo
"$BIN" shadow || info "De eerste proefronde lukte niet; draai: sudo rzsense doctor"
echo
info "Bekijk de uitkomst in RzSense: Agent Manager van deze server → Go-agent (proef)."
info "Op deze server: rzsense status · rzsense test · rzsense doctor"
if [ "$(cat "$DATA/mode" 2>/dev/null)" = active ]; then
    info "De Go-agent doet het werk op deze server. Terug naar de bash-agent: rzsense back (of de knop in RzSense)."
else
    info "De bash-agent doet nog steeds het werk. Weghalen: hetzelfde commando met --remove erachter."
fi
