#!/usr/bin/env bash
# RzSense agent relay (rzmon-agent-relay): installeren, bijwerken, status en verwijderen. RzSense 1.283.0, agent 1.110.0.
#
# Een losse relay op een Linux-server met systemd, zonder container: agents in een netwerk zonder verbinding met RzSense
# rapporteren via deze relay. Precies dezelfde onderdelen als de container (RzSense Relay): de agent in relaymodus, het
# doorstuurprogramma relay.py en het startscript. Los van een gewone rzmon-agent op dezelfde server (eigen gebruiker,
# eigen mappen, eigen sleutel).
#
#   Installeren (het commando staat in RzSense, met koppelcode):
#     sudo bash rzmon-relay-install.sh --url https://rzsense.voorbeeld.nl --pair XXXX-XXXX-XXXX-XXXX-XXXX [--pin …] [--port 8444]
#   RzSense haalt op (1.292.0, bijvoorbeeld in een DMZ zonder verbinding met RzSense): uit de bundel uit RzSense,
#     sudo bash install-relay.sh --src . --pull-key SLEUTEL --pair XXXX-XXXX-XXXX-XXXX-XXXX [--port 8444] [--pull-port 8445]
#   Daarna:  sudo rzmon-agent-relay-setup --status | --update | --uninstall
#
# - draait als gebruiker rzrelay (niet root), afgeschermd door systemd; alleen CAP_NET_RAW (ping)
# - bijwerken: met de knop in RzSense of automatisch; de relay zet alleen een verzoek neer, deze setup (als root, via
#   een systemd path-unit) haalt de nieuwe versie bij RzSense en installeert alleen als de handtekening van de
#   ontwikkelaar klopt
# - instellingen: /etc/default/rzmon-agent-relay (MONITOR_URL, MONITOR_URL_FALLBACK, PAIR_CODE, PIN, RELAY_PORT)
set -euo pipefail

# Downloadsleutel: RzSense vult hem in als het dit script uitlevert (van buiten levert RzSense alleen daarmee)
DL_KEY=''
APP=/opt/rzmon-agent-relay
DATA=/var/lib/rzmon-agent-relay
CONF=/etc/default/rzmon-agent-relay
SETUP=/usr/local/sbin/rzmon-agent-relay-setup
UNIT=rzmon-agent-relay
RUSER=rzrelay
SD=/etc/systemd/system

URL="" FALLBACK="" FALLBACK_SET=0 PAIR="" PIN="" PORT="" AGENT_SHA="" SRC="" MODE=install ASSUME_YES=0 FROM_AGENT=0 PULL_KEY="" PULL_PORT=""

C_OK=$'\e[32m' C_WARN=$'\e[33m' C_ERR=$'\e[31m' C_HEAD=$'\e[1m' C_OFF=$'\e[0m'
[ -t 1 ] || { C_OK="" C_WARN="" C_ERR="" C_HEAD="" C_OFF=""; }
step() { printf '\n%s==> %s%s\n' "$C_HEAD" "$*" "$C_OFF"; }
ok() { printf '    %s✔%s %s\n' "$C_OK" "$C_OFF" "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '    %s!%s %s\n' "$C_WARN" "$C_OFF" "$*"; }
RESULT=""
die() {
    printf '\n%s✘ %s%s\n' "$C_ERR" "$*" "$C_OFF" >&2
    [ -n "$RESULT" ] && { printf 'error %s\n' "$*" >"$RESULT" 2>/dev/null; chown "$RUSER:$RUSER" "$RESULT" 2>/dev/null; } || true
    exit 1
}

usage() {
    cat <<'EOF'
RzSense agent relay (rzmon-agent-relay)

Installeren:
  rzmon-relay-install.sh --url URL --pair KOPPELCODE [--pin VINGERAFDRUK] [--port 8444] [--fallback URL] [--agent-sha SHA256]
  --src MAP     installeren uit een lokale map (rzmon-agent.sh, relay.py, entrypoint.sh); zonder controle van de handtekening
  --pull-key SLEUTEL  RzSense haalt op (DMZ): de relay belt nooit zelf naar RzSense; --url is dan niet nodig (wel --src)
  --pull-port POORT   poort waarop RzSense ophaalt (standaard 8445)
Daarna:
  rzmon-agent-relay-setup --status      hoe het ervoor staat
  rzmon-agent-relay-setup --update      bijwerken vanaf RzSense (alleen met een geldige handtekening)
  rzmon-agent-relay-setup --uninstall   verwijderen (met -y ook de sleutels en instellingen)
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="${2:?}"; shift ;;
        --fallback) FALLBACK="${2-}"; FALLBACK_SET=1; shift ;;
        --pair) PAIR="${2:?}"; shift ;;
        --pin) PIN="${2:?}"; shift ;;
        --port) PORT="${2:?}"; shift ;;
        --agent-sha) AGENT_SHA="${2:?}"; shift ;;
        --src) SRC="${2:?}"; shift ;;
        --pull-key) PULL_KEY="${2:?}"; shift ;;
        --pull-port) PULL_PORT="${2:?}"; shift ;;
        --update) MODE=update ;;
        --from-agent) MODE=update; FROM_AGENT=1 ;;
        --status) MODE=status ;;
        --uninstall) MODE=uninstall ;;
        -y|--yes) ASSUME_YES=1 ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Onbekende optie: $1" ;;
    esac
    shift
done

[ "$(id -u)" -eq 0 ] || die "Voer dit uit als root (met sudo)."
[ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1 || die "Deze relay heeft systemd nodig. Zonder systemd: gebruik de container (Podman of Docker)."

conf_get() { [ -r "$CONF" ] && sed -n "s/^$1=[\"']\{0,1\}\([^\"']*\)[\"']\{0,1\}\$/\1/p" "$CONF" | tail -1 || true; }

# ----------------------------------------------------------------- status
if [ "$MODE" = status ]; then
    step "RzSense agent relay"
    [ -f "$APP/rzmon-agent.sh" ] || { info "Niet geïnstalleerd."; exit 0; }
    info "Versie:    $(cat "$APP/VERSION" 2>/dev/null || echo '?')"
    info "Dienst:    $(systemctl is-active "$UNIT.service" 2>/dev/null || true) ($UNIT.service)"
    info "RzSense:   $(conf_get MONITOR_URL)"
    info "Poort:     $(conf_get RELAY_PORT)"
    [ -n "$(conf_get RELAY_PULL_KEY)" ] && info "Ophalen:   RzSense haalt op via poort $(conf_get RELAY_PULL_PORT || true) (deze relay belt niet zelf naar RzSense)"
    info "Pin:       $(cat "$DATA/relay/pin" 2>/dev/null || echo '–')"
    info "Netwerken: $(cat "$DATA/relay/allow" 2>/dev/null || echo 'nog geen (de relay laat niemand door)')"
    if [ -s "$DATA/relay/stats.json" ]; then info "Verkeer:   $(cat "$DATA/relay/stats.json")"; fi
    info "Laatste run van de agent:"
    tail -n 3 "$DATA/agent-last.log" 2>/dev/null | sed 's/"\(agent_key\|enc_key\)":"[0-9a-f]\{64\}"/"\1":"…"/g; s/^/      /' || true
    exit 0
fi

# ----------------------------------------------------------------- verwijderen
if [ "$MODE" = uninstall ]; then
    step "RzSense agent relay verwijderen"
    systemctl disable --now "$UNIT.service" "$UNIT-update.path" >/dev/null 2>&1 || true
    rm -f "$SD/$UNIT.service" "$SD/$UNIT-update.path" "$SD/$UNIT-update.service"
    systemctl daemon-reload
    p=$(conf_get RELAY_PORT); p=${p:-8444}
    pp=""; [ -n "$(conf_get RELAY_PULL_KEY)" ] && pp=$(conf_get RELAY_PULL_PORT) && pp=${pp:-8445}
    for p in $p $pp; do
        if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
            firewall-cmd -q --permanent --remove-port="$p/tcp" 2>/dev/null && firewall-cmd -q --reload && ok "Firewall: poort $p dicht"
        elif command -v ufw >/dev/null 2>&1; then
            ufw delete allow "$p/tcp" >/dev/null 2>&1 && ok "Firewall: poort $p dicht" || true
        fi
    done
    rm -rf "$APP"
    ok "Programma en dienst weg"
    if [ "$ASSUME_YES" = 1 ]; then
        rm -rf "$DATA" "$CONF"
        userdel "$RUSER" >/dev/null 2>&1 || true
        ok "Sleutels, certificaat en instellingen weg ($DATA, $CONF)"
    else
        info "Sleutels, certificaat en instellingen blijven staan ($DATA, $CONF). Ook weg: $SETUP --uninstall -y"
    fi
    [ "$ASSUME_YES" = 1 ] && rm -f "$SETUP"
    exit 0
fi

# ----------------------------------------------------------------- installeren of bijwerken
command -v curl >/dev/null 2>&1 || die "curl ontbreekt (dnf install curl / apt install curl)"
command -v openssl >/dev/null 2>&1 || die "openssl ontbreekt (dnf install openssl / apt install openssl)"
command -v python3 >/dev/null 2>&1 || die "python3 ontbreekt (dnf install python3 / apt install python3)"
python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 7) else 1)' \
    || die "python3 is te oud ($(python3 -V 2>&1)); 3.7 of nieuwer nodig (Rocky 8: dnf install python39, en zet die als python3)"

if [ "$MODE" = update ]; then
    [ -f "$APP/rzmon-agent.sh" ] || die "Niet geïnstalleerd: installeer eerst met het commando uit RzSense."
    if [ "$FROM_AGENT" = 1 ]; then
        RESULT="$DATA/cache/update.result"
        REQ="$DATA/update.req"
        [ -r "$REQ" ] || exit 0
        read -r WANT WANT_SHA WANT_SETUP <"$REQ" || true
        rm -f "$REQ"
        [[ "${WANT-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && [[ "${WANT_SHA-}" =~ ^[0-9a-f]{64}$ ]] && [[ "${WANT_SETUP-}" =~ ^[0-9a-f]{64}$ ]] \
            || die "ongeldig verzoek om bij te werken"
        AGENT_SHA=$WANT_SHA
    fi
    URL=$(conf_get MONITOR_URL)
    # Heeft RzSense het adres van de relay gewijzigd (adres wisselen)? Dan dat adres, met de controle die de agent kent
    if [ -r "$DATA/agent/keys/url" ]; then
        { IFS= read -r u_set; IFS= read -r u_from; } <"$DATA/agent/keys/url" || true
        [ -n "${u_set-}" ] && [ "${u_from-}" = "${URL%/}" ] && URL=$u_set
    fi
    PIN=$(conf_get PIN)
    spec=$(awk -v u="${URL%/}" '$1 == u { print $2; exit }' "$DATA/agent/keys/url.pins" 2>/dev/null || true)
    if [[ "$spec" == sha256//* ]]; then PIN=${spec#sha256//}; PIN=${PIN%%;*}
    elif [ "$spec" = "-" ]; then PIN=""
    elif [ -r "$DATA/agent/keys/pin" ]; then PIN=$(tr -dc 'A-Za-z0-9+/=' <"$DATA/agent/keys/pin" | head -c 64); fi
fi
URL=${URL%/}
# 1.292.0: RzSense haalt op. De relay kan RzSense dan niet bereiken: installeren en bijwerken uit de bundel (--src)
[ -z "$PULL_KEY" ] || [[ "$PULL_KEY" =~ ^[0-9a-f]{64}$ ]] || die "Ongeldige sleutel (--pull-key): kopieer hem uit RzSense (64 tekens, 0-9 en a-f)"
[ -z "$PULL_PORT" ] || { [[ "$PULL_PORT" =~ ^[0-9]{1,5}$ ]] && [ "$PULL_PORT" -ge 1 ] && [ "$PULL_PORT" -le 65535 ]; } || die "Ongeldige poort (--pull-port)"
if [ -z "$SRC" ] && { [ -n "$PULL_KEY" ] || [ -n "$(conf_get RELAY_PULL_KEY)" ]; }; then
    die "RzSense haalt op bij deze relay, dus de relay kan RzSense niet bereiken. Download in RzSense de bundel (Netwerken en relays → 📦 Installeren), zet hem op deze machine en installeer met: sudo bash install-relay.sh --src . (--update werkt zo ook)"
fi
if [ -z "$SRC" ]; then
    [[ "$URL" =~ ^https?://[^[:space:]\"\']+$ ]] || die "Geef het adres van RzSense op met --url (bijvoorbeeld https://rzsense.voorbeeld.nl)"
fi
[ -z "$PIN" ] || [[ "$PIN" =~ ^[A-Za-z0-9+/]{43}=$ ]] || die "Ongeldige vingerafdruk (--pin)"
[ -z "$PAIR" ] || [[ "$PAIR" =~ ^[A-Za-z0-9-]{10,40}$ ]] || die "Ongeldige koppelcode (--pair)"
[ -z "$PORT" ] || { [[ "$PORT" =~ ^[0-9]{4,5}$ ]] && [ "$PORT" -ge 1024 ] && [ "$PORT" -le 65535 ]; } || die "Poort moet tussen 1024 en 65535 liggen"
[ -z "$FALLBACK" ] || [[ "$FALLBACK" =~ ^https?://[^[:space:]\"\']+$ ]] || die "Ongeldig reserve-adres (--fallback)"

CURL=(curl -fsS -m 60 --proto '=https,http')
[[ "$URL" == https://* ]] && [ -n "$PIN" ] && CURL+=(-k --pinnedpubkey "sha256//$PIN")
q_dl=${DL_KEY:+&k=$DL_KEY}

# Handtekening van de ontwikkelaar (ed25519), dezelfde sleutel als voor de agent
AGENT_SIGN_PUB="MCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM="
sig_check() {  # sig_check <bestand> <handtekening (base64)>: 0 = klopt, 1 = klopt niet, 2 = niet te controleren (OpenSSL ouder dan 3)
    local d rc
    openssl pkeyutl -help 2>&1 | grep -q -- '-rawin' || return 2
    d=$(mktemp -d) || return 2
    printf -- '-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n' "$AGENT_SIGN_PUB" >"$d/pub.pem"
    if ! base64 -d <"$2" >"$d/sig" 2>/dev/null || [ "$(wc -c <"$d/sig")" != 64 ]; then rm -rf "$d"; return 1; fi
    openssl pkeyutl -verify -pubin -inkey "$d/pub.pem" -rawin -in "$1" -sigfile "$d/sig" >/dev/null 2>&1; rc=$?
    rm -rf "$d"
    [ "$rc" = 0 ]
}
conf_on() { [ "$(conf_get "$1")" = 1 ]; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# ----------------------------------------------------------------- 1. ophalen en controleren
step "1/4 Ophalen en controleren"
if [ -n "$SRC" ]; then
    for f in rzmon-agent.sh relay.py entrypoint.sh; do
        [ -f "$SRC/$f" ] || [ -f "$SRC/agent/$f" ] || [ -f "$SRC/relay/$f" ] || die "$f niet gevonden in $SRC"
        for c in "$SRC/$f" "$SRC/agent/$f" "$SRC/relay/$f"; do [ -f "$c" ] && { cp "$c" "$tmp/$f"; break; }; done
    done
    warn "Uit een lokale map ($SRC): de handtekening wordt niet gecontroleerd"
else
    get() {  # get <action-query> <bestand>
        "${CURL[@]}" "$URL/api.php?action=$1$q_dl" -o "$tmp/$2" 2>"$tmp/err" \
            || die "Ophalen bij $URL mislukt ($(head -c 200 "$tmp/err")). Klopt het adres, en is RzSense vanaf deze server bereikbaar?"
    }
    get agent_script rzmon-agent.sh
    get "agent_relay_file&f=relay" relay.py
    get "agent_relay_file&f=run" entrypoint.sh
    get "agent_relay_file&f=install" setup.sh
    head -1 "$tmp/rzmon-agent.sh" | grep -q '^#!/usr/bin/env bash' && grep -q 'RzSense agent' "$tmp/rzmon-agent.sh" || die "Het gedownloade bestand is geen RzSense-agent"
    if [ -n "$AGENT_SHA" ]; then
        [ "$(sha256sum "$tmp/rzmon-agent.sh" | cut -d' ' -f1)" = "$AGENT_SHA" ] || die "Controlegetal (SHA-256) van de agent klopt niet: onderweg gewijzigd, of RzSense is intussen bijgewerkt"
        ok "Controlegetal van de agent klopt"
    fi
    if [ "$FROM_AGENT" = 1 ]; then
        [ "$(sha256sum "$tmp/setup.sh" | cut -d' ' -f1)" = "$WANT_SETUP" ] || die "Controlegetal (SHA-256) van het installatiescript klopt niet"
        grep -q "^AGENT_VERSION=\"$WANT\"" "$tmp/rzmon-agent.sh" || die "RzSense levert niet de gevraagde versie $WANT"
    fi
    # Handtekeningen: agent, doorstuurprogramma, startscript en dit script (DL_KEY terug naar leeg voor de controle).
    # UPDATE_UNSIGNED=1 in de instellingen: bij bijwerken niet controleren (zoals bij de gewone agent; niet aangeraden).
    sed "s/^DL_KEY='[A-Za-z0-9]*'\$/DL_KEY=''/" "$tmp/setup.sh" >"$tmp/setup.chk"
    bad=() old=0 missing=()
    sig_pairs=("agent_script_sig:rzmon-agent.sh" "agent_relay_sig&f=relay:relay.py" "agent_relay_sig&f=run:entrypoint.sh" "agent_relay_sig&f=install:setup.chk")
    if [ "$MODE" = update ] && conf_on UPDATE_UNSIGNED; then
        warn "UPDATE_UNSIGNED=1: de handtekening wordt niet gecontroleerd"
        sig_pairs=()
    fi
    for pair in "${sig_pairs[@]}"; do
        a=${pair%%:*} f=${pair#*:}
        if "${CURL[@]}" "$URL/api.php?action=$a$q_dl" -o "$tmp/$f.sig" 2>/dev/null && [ -s "$tmp/$f.sig" ]; then
            sig_check "$tmp/$f" "$tmp/$f.sig" && rc=0 || rc=$?
            [ "$rc" = 1 ] && bad+=("$f")
            [ "$rc" = 2 ] && old=1
        else
            missing+=("$f")
        fi
    done
    [ ${#bad[@]} -eq 0 ] || die "De handtekening klopt niet voor: ${bad[*]}. Deze versie komt niet van de ontwikkelaar; niet geïnstalleerd."
    if [ ${#missing[@]} -gt 0 ]; then
        if [ "$MODE" = update ] && ! conf_on UPDATE_UNSIGNED; then
            die "Geen handtekening bij RzSense voor: ${missing[*]}. Niet bijgewerkt (zet UPDATE_UNSIGNED=1 in $CONF om toch bij te werken; niet aangeraden)."
        fi
        warn "Nog geen handtekening bij RzSense voor: ${missing[*]} (alleen het controlegetal is gecontroleerd)"
    elif [ "$old" = 1 ]; then
        if [ "$MODE" = update ] && ! conf_on UPDATE_OLD_OPENSSL && ! conf_on UPDATE_UNSIGNED; then
            die "Handtekening niet te controleren: OpenSSL op deze server is ouder dan 3.0. Zet UPDATE_OLD_OPENSSL=1 in $CONF om toch bij te werken."
        fi
        warn "Handtekening niet te controleren: OpenSSL ouder dan 3.0"
    elif [ ${#sig_pairs[@]} -gt 0 ]; then
        ok "Handtekeningen kloppen (ondertekend door de ontwikkelaar)"
    fi
fi
bash -n "$tmp/rzmon-agent.sh" && bash -n "$tmp/entrypoint.sh" || die "De download is geen geldig script"
python3 -m py_compile "$tmp/relay.py" 2>/dev/null || die "relay.py is geen geldig Python-programma"
new_version=$(sed -n 's/^AGENT_VERSION="\([^"]*\)".*/\1/p' "$tmp/rzmon-agent.sh")
old_version=$(cat "$APP/VERSION" 2>/dev/null || true)
ok "Versie ${new_version:-?}"

# ----------------------------------------------------------------- 2. gebruiker, mappen en bestanden
step "2/4 Installeren"
if ! id "$RUSER" >/dev/null 2>&1; then
    useradd --system --home-dir "$DATA" --no-create-home --shell /usr/sbin/nologin "$RUSER" 2>/dev/null \
        || useradd --system --home-dir "$DATA" --shell /usr/sbin/nologin "$RUSER"
    ok "Gebruiker $RUSER aangemaakt (geen root, kan niet inloggen)"
fi
install -d -m 755 -o root -g root "$APP"
install -d -m 700 -o "$RUSER" -g "$RUSER" "$DATA"
install -m 755 -o root -g root "$tmp/rzmon-agent.sh" "$APP/rzmon-agent.sh"
install -m 644 -o root -g root "$tmp/relay.py" "$APP/relay.py"
install -m 755 -o root -g root "$tmp/entrypoint.sh" "$APP/entrypoint.sh"
printf '%s\n' "$new_version" >"$APP/VERSION"
if [ -f "$tmp/setup.sh" ]; then install -m 700 -o root -g root "$tmp/setup.sh" "$SETUP"
elif [ "$(readlink -f "$0")" != "$SETUP" ]; then install -m 700 -o root -g root "$0" "$SETUP"; fi
command -v restorecon >/dev/null 2>&1 && restorecon -R "$APP" "$SETUP" 2>/dev/null || true
ok "Programma in $APP, beheer met $SETUP"

# Instellingen: een nieuw bestand alleen bij een nieuwe installatie; daarna alleen wat je zelf meegeeft
conf_set() {  # conf_set NAAM WAARDE
    local t
    if grep -q "^$1=" "$CONF"; then
        t=$(mktemp "$CONF.XXXXXX")
        awk -v k="$1=" -v line="$1=\"$2\"" 'index($0, k) == 1 { print line; next } { print }' "$CONF" >"$t"
        mv -f "$t" "$CONF"
    else
        printf '%s="%s"\n' "$1" "$2" >>"$CONF"
    fi
}
if [ ! -f "$CONF" ]; then
    [ -n "$SRC" ] && [ -z "$URL" ] && [ -z "$PULL_KEY" ] && die "Geef het adres van RzSense op met --url (of --pull-key als RzSense ophaalt)"
    umask 027
    cat >"$CONF" <<EOF
# RzSense agent relay ($UNIT). Wijzig met de hand en herstart: systemctl restart $UNIT
MONITOR_URL="$URL"
MONITOR_URL_FALLBACK="${FALLBACK%/}"
PAIR_CODE="$PAIR"
PIN="$PIN"
RELAY_PORT="${PORT:-8444}"
# 1.292.0: RzSense haalt op (leeg = de relay stuurt door naar MONITOR_URL)
RELAY_PULL_KEY="$PULL_KEY"
RELAY_PULL_PORT="${PULL_PORT:-8445}"
EOF
    umask 022
    ok "Instellingen in $CONF"
elif [ "$MODE" = install ]; then
    [ -n "$URL" ] && [ "$URL" != "$(conf_get MONITOR_URL)" ] && conf_set MONITOR_URL "$URL"
    [ -n "$PAIR" ] && conf_set PAIR_CODE "$PAIR"
    [ -n "$PIN" ] && conf_set PIN "$PIN"
    [ -n "$PORT" ] && conf_set RELAY_PORT "$PORT"
    [ "$FALLBACK_SET" = 1 ] && conf_set MONITOR_URL_FALLBACK "${FALLBACK%/}"
    [ -n "$PULL_KEY" ] && conf_set RELAY_PULL_KEY "$PULL_KEY"
    [ -n "$PULL_PORT" ] && conf_set RELAY_PULL_PORT "$PULL_PORT"
    ok "Instellingen bijgewerkt (alleen wat je meegaf)"
fi
chown root:"$RUSER" "$CONF"; chmod 640 "$CONF"
PORT=$(conf_get RELAY_PORT); PORT=${PORT:-8444}
PULL_ON=""; [ -n "$(conf_get RELAY_PULL_KEY)" ] && PULL_ON=1
PULL_PORT=$(conf_get RELAY_PULL_PORT); PULL_PORT=${PULL_PORT:-8445}

# ----------------------------------------------------------------- 3. systemd en firewall
step "3/4 Dienst en firewall"
cat >"$SD/$UNIT.service" <<EOF
[Unit]
Description=RzSense agent relay (stuurt agents in dit netwerk door naar RzSense)
After=network-online.target
Wants=network-online.target

[Service]
User=$RUSER
Group=$RUSER
EnvironmentFile=$CONF
Environment=RELAY_APP=$APP RELAY_DATA=$DATA RELAY_KIND=agent RELAY_UPDATE_REQ=$DATA/update.req
ExecStart=$APP/entrypoint.sh
Restart=always
RestartSec=10
AmbientCapabilities=CAP_NET_RAW
CapabilityBoundingSet=CAP_NET_RAW
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ReadWritePaths=$DATA
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
RestrictNamespaces=yes
UMask=0077

[Install]
WantedBy=multi-user.target
EOF
# Bijwerken: de relay (geen root) zet alleen een verzoek neer; dit script (root) controleert en installeert
cat >"$SD/$UNIT-update.path" <<EOF
[Unit]
Description=RzSense agent relay: verzoek om bij te werken

[Path]
PathExists=$DATA/update.req

[Install]
WantedBy=multi-user.target
EOF
cat >"$SD/$UNIT-update.service" <<EOF
[Unit]
Description=RzSense agent relay bijwerken (alleen met een geldige handtekening)

[Service]
Type=oneshot
ExecStart=$SETUP --from-agent --yes
EOF
systemctl daemon-reload
systemctl enable "$UNIT.service" "$UNIT-update.path" >/dev/null 2>&1
systemctl restart "$UNIT-update.path"
ok "Dienst $UNIT.service (als $RUSER) en bijwerken via $UNIT-update.path"
if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    firewall-cmd -q --permanent --add-port="$PORT/tcp" && firewall-cmd -q --reload && ok "Firewall (firewalld): poort $PORT open; de relay laat zelf alleen de netwerken uit RzSense door"
    [ -n "$PULL_ON" ] && firewall-cmd -q --permanent --add-port="$PULL_PORT/tcp" && firewall-cmd -q --reload && ok "Firewall (firewalld): poort $PULL_PORT open voor het ophalen (alleen met de sleutel van RzSense)"
elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q 'Status: active'; then
    ufw allow "$PORT/tcp" >/dev/null && ok "Firewall (ufw): poort $PORT open; de relay laat zelf alleen de netwerken uit RzSense door"
    [ -n "$PULL_ON" ] && ufw allow "$PULL_PORT/tcp" >/dev/null && ok "Firewall (ufw): poort $PULL_PORT open voor het ophalen (alleen met de sleutel van RzSense)"
else
    info "Geen firewalld of ufw gevonden: zorg zelf dat poort $PORT bereikbaar is voor de agents in dit netwerk${PULL_ON:+, en poort $PULL_PORT voor RzSense}"
fi
[ -n "$PULL_ON" ] && info "Tip: laat poort $PULL_PORT in je firewall alleen toe vanaf het adres van RzSense"

# ----------------------------------------------------------------- 4. starten
step "4/4 Starten"
systemctl restart "$UNIT.service"
if [ "$MODE" = update ]; then
    if [ -n "$RESULT" ]; then
        install -d -m 700 -o "$RUSER" -g "$RUSER" "$DATA/cache"
        printf 'ok %s %s\n' "$old_version" "$new_version" >"$RESULT"; chown "$RUSER:$RUSER" "$RESULT"
    fi
    ok "Bijgewerkt${old_version:+ van $old_version} naar $new_version"
    exit 0
fi
if [ -n "$PULL_ON" ]; then
    ok "Gestart. De relay wacht nu op RzSense: zodra RzSense ophaalt (elke 15 seconden) koppelt hij zich en staat hij in RzSense op online."
    info "Vingerafdruk van het certificaat van deze relay: $(cat "$DATA/relay/pin" 2>/dev/null || echo '?')"
    info "Ophaaladres voor RzSense: https://$(hostname -I 2>/dev/null | awk '{print $1}'):$PULL_PORT · agents in dit netwerk: https://$(hostname -I 2>/dev/null | awk '{print $1}'):$PORT"
    exit 0
fi
for _ in $(seq 1 45); do
    [ -f "$DATA/.ok" ] && break
    sleep 2
done
if [ -f "$DATA/.ok" ]; then
    ok "Gekoppeld: de relay meldt zich bij RzSense"
else
    warn "Nog niet gemeld bij RzSense. Kijk met: journalctl -u $UNIT -n 30  (of $SETUP --status)"
fi
info "Vingerafdruk van het certificaat van deze relay: $(cat "$DATA/relay/pin" 2>/dev/null || echo '?')"
info "Zet in RzSense het adres van de relay, bijvoorbeeld https://$(hostname -I 2>/dev/null | awk '{print $1}'):$PORT"
info "en maak een netwerk dat via deze relay gaat (Beheer → Agents en koppelingen → Netwerken en relays)."
