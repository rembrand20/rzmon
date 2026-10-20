#!/usr/bin/env bash
# =============================================================================
#  RzMon agent – installatiescript voor Linux-servers
#  (Rocky/RHEL/AlmaLinux/Fedora, Debian/Ubuntu/Raspberry Pi OS, Proxmox VE)
#
#  Het makkelijkst: kopieer het commando uit RzMon (server → "Agent installeren"):
#    curl -fsSL http://RZMON/api.php?action=agent_install | sudo bash -s -- --url http://RZMON --token TOKEN
#
#  Wat het doet:
#    - ontbrekende hulpprogramma's installeren (curl, ss)
#    - de agent ophalen van je eigen RzMon-server (/usr/local/bin/rzmon-agent.sh)
#    - instellingen in /etc/default/rzmon-agent (alleen leesbaar voor root)
#    - elke minuut rapporteren via een systemd-timer (of cron als er geen systemd is)
#    - een testrapport sturen en controleren of RzMon het accepteert
#
#  Opnieuw uitvoeren is veilig: bestaande instellingen blijven staan tenzij je ze meegeeft.
# =============================================================================
set -Eeuo pipefail

BIN="/usr/local/bin/rzmon-agent.sh"
CONF="/etc/default/rzmon-agent"
UNIT="/etc/systemd/system/rzmon-agent.service"
TIMER="/etc/systemd/system/rzmon-agent.timer"
CRON="/etc/cron.d/rzmon-agent"
SETUP="/usr/local/sbin/rzmon-agent-setup"   # dit script, voor later --update/--uninstall

URL=""
TOKEN=""
SERVICES=""
CERTS=""
CERTS_SET=0           # --certs meegegeven (ook leeg = geen certificaten)
INSECURE=""
INTERVAL=""
SCHED="auto"          # auto | systemd | cron
ASSUME_YES=0
MODE="install"        # install | update | uninstall
SOURCE_FILE=""        # lokale rzmon-agent.sh i.p.v. downloaden

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
trap 'die "Fout op regel $LINENO. Het script kan veilig opnieuw worden uitgevoerd."' ERR

usage() {
    cat <<EOF
RzMon agent installeren

Gebruik: sudo bash install-agent.sh --url URL --token TOKEN [opties]

  --url URL            Adres van RzMon, bv. http://192.168.1.10
  --token TOKEN        Agent-token van deze server (RzMon → server → "Agent installeren")
  --services "a b c"   Te bewaken systemd-services (standaard: automatisch voorstel)
  --certs "a b c"      Certificaten: bestanden (wildcards mogen) en/of host:poort, ook op
                       internet, bv. "/etc/letsencrypt/live/*/cert.pem localhost:8443 site.nl"
                       (standaard: automatisch voorstel; "" = geen)
  --interval SEC       Rapporteren elke … seconden (30-3600, standaard 60)
  --insecure           Self-signed HTTPS-certificaat van RzMon accepteren
  --cron               Cron gebruiken in plaats van een systemd-timer
  --file PAD           Lokale rzmon-agent.sh gebruiken i.p.v. downloaden
  -y, --yes            Niets vragen
  --test               Verbindingstest: planning, netwerk, token en een proefrapport
  --update             Alleen de agent zelf bijwerken (instellingen blijven)
  --uninstall          Agent verwijderen
  --version            Geïnstalleerde agentversie tonen
  -h, --help           Deze hulp
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="${2:?}"; shift ;;
        --token) TOKEN="${2:?}"; shift ;;
        --services) SERVICES="${2-}"; shift ;;
        --certs) CERTS="${2-}"; CERTS_SET=1; shift ;;
        --interval) INTERVAL="${2:?}"; shift ;;
        --insecure) INSECURE=1 ;;
        --cron) SCHED="cron" ;;
        --file) SOURCE_FILE="${2:?}"; shift ;;
        -y|--yes) ASSUME_YES=1 ;;
        --update) MODE="update" ;;
        --test) MODE="test" ;;
        --uninstall) MODE="uninstall" ;;
        --version) MODE="version" ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Onbekende optie: $1" ;;
    esac
    shift
done

if [ "$MODE" = "version" ]; then
    if [ -x "$BIN" ]; then "$BIN" --version 2>/dev/null || echo "rzmon-agent (oude versie, zonder versienummer)"; else echo "Agent niet geïnstalleerd"; fi
    exit 0
fi

[ "$(id -u)" -eq 0 ] || die "Voer dit uit als root (met sudo)."

has_systemd() { [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; }
# Echt een terminal? (/dev/tty kan bestaan zonder te openen te zijn, bv. via Ansible of cron)
interactive() { [ "$ASSUME_YES" -ne 1 ] && { : </dev/tty; } 2>/dev/null; }
ask() {  # ask "vraag" "standaard" → antwoord op stdout
    local answer
    printf '%s' "$1" >/dev/tty
    [ -n "${2-}" ] && printf ' [%s]' "$2" >/dev/tty
    printf ': ' >/dev/tty
    IFS= read -r answer </dev/tty || answer=""
    printf '%s' "${answer:-${2-}}"
}

# ----------------------------------------------------------------- verwijderen
if [ "$MODE" = "uninstall" ]; then
    step "RzMon agent verwijderen"
    if has_systemd; then
        systemctl disable --now rzmon-agent.timer >/dev/null 2>&1 || true
        rm -f "$UNIT" "$TIMER"
        systemctl daemon-reload
    fi
    rm -f "$CRON" "$BIN" "$SETUP"
    rm -rf /var/cache/rzmon-agent
    if [ -f "$CONF" ]; then
        if interactive && [ "$(ask 'Ook de instellingen (URL en token) verwijderen? j/n' 'j')" != "n" ]; then
            rm -f "$CONF"
        elif ! interactive; then
            rm -f "$CONF"
        fi
    fi
    ok "Agent verwijderd. Verwijder de server eventueel ook in RzMon."
    exit 0
fi

# ----------------------------------------------------------------- bestaande instellingen
if [ -f "$CONF" ]; then
    # In een subshell inlezen: alleen deze variabelen overnemen
    # shellcheck disable=SC1090
    eval "$(set -a; . "$CONF" >/dev/null 2>&1
            printf 'OLD_URL=%q OLD_TOKEN=%q OLD_SERVICES=%q OLD_INSECURE=%q OLD_INTERVAL=%q OLD_CERTS=%q OLD_CERTS_SET=%q\n' \
                "${MONITOR_URL-}" "${TOKEN-}" "${SERVICES-}" "${INSECURE-}" "${INTERVAL-}" "${CERTS-}" "${CERTS+1}")"
    URL=${URL:-$OLD_URL}
    INTERVAL=${INTERVAL:-$OLD_INTERVAL}
    TOKEN=${TOKEN:-$OLD_TOKEN}
    [ -n "$SERVICES" ] || SERVICES=$OLD_SERVICES
    if [ "$CERTS_SET" = 0 ] && [ "$OLD_CERTS_SET" = 1 ]; then
        CERTS=$OLD_CERTS
        CERTS_SET=1
    fi
    INSECURE=${INSECURE:-$OLD_INSECURE}
fi
INSECURE=${INSECURE:-0}
INTERVAL=${INTERVAL:-60}

# ----------------------------------------------------------------- verbindingstest
if [ "$MODE" = "test" ]; then
    [ -f "$CONF" ] || die "Nog geen agent geïnstalleerd ($CONF ontbreekt)."
    [ -x "$BIN" ] || die "$BIN ontbreekt. Installeer opnieuw (commando uit RzMon → Agent installeren)."
    printf '%sRzMon agent – planning%s\n' "$C_HEAD" "$C_OFF"
    if has_systemd && [ -f "$TIMER" ]; then
        if [ "$(systemctl is-active rzmon-agent.timer 2>/dev/null)" = "active" ]; then
            ok "systemd-timer actief"
        else
            warn "systemd-timer staat niet aan → sudo systemctl enable --now rzmon-agent.timer"
        fi
        last=$(systemctl show rzmon-agent.service -p ExecMainExitTimestamp --value 2>/dev/null)
        status=$(systemctl show rzmon-agent.service -p ExecMainStatus --value 2>/dev/null)
        if [ -z "$last" ]; then
            warn "Agent is via de timer nog niet uitgevoerd"
        elif [ "${status:-0}" = 0 ]; then
            ok "Laatste run: $last (gelukt)"
        else
            warn "Laatste run: $last mislukt (exitcode $status). Laatste logregels:"
            journalctl -u rzmon-agent -n 5 --no-pager -o cat 2>/dev/null | sed 's/^/        /'
        fi
    elif [ -f "$CRON" ]; then
        if pgrep -x crond >/dev/null 2>&1 || pgrep -x cron >/dev/null 2>&1; then
            ok "cron draait ($CRON)"
        else
            warn "cron draait niet: de agent wordt nooit gestart!"
            info "Rocky/RHEL: sudo dnf -y install cronie && sudo systemctl enable --now crond"
            info "Debian/Ubuntu: sudo apt install cron && sudo systemctl enable --now cron"
        fi
    else
        warn "Geen planning gevonden (geen timer, geen cron). Installeer opnieuw."
    fi
    if ! grep -q -- '--test' "$BIN"; then
        warn "Deze agent-versie kent nog geen verbindingstest: eerst bijwerken met  sudo rzmon-agent-setup --update"
        exit 1
    fi
    echo
    # shellcheck disable=SC1090
    (set -a; . "$CONF"; "$BIN" --test)
    exit $?
fi

if [ "$MODE" = "update" ]; then
    [ -f "$CONF" ] || die "Nog geen agent geïnstalleerd ($CONF ontbreekt). Voer eerst een installatie uit."
fi

printf '%sRzMon agent – %s%s\n' "$C_HEAD" "$(. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-Linux}")" "$C_OFF"

# ----------------------------------------------------------------- URL en token
if [ -z "$URL" ]; then
    interactive || die "Geef het adres van RzMon mee: --url http://…"
    URL=$(ask "Adres van RzMon (bv. http://192.168.1.10)" "")
fi
if [ -z "$TOKEN" ]; then
    interactive || die "Geef het agent-token mee: --token … (RzMon → server → Agent installeren)"
    TOKEN=$(ask "Agent-token (RzMon → server → Agent installeren)" "")
fi
URL=${URL%/}
URL=${URL%/api.php*}
[[ "$URL" =~ ^https?://[^[:space:]\"\'\\\$\`]+$ ]] || die "Ongeldig adres: $URL (moet met http:// of https:// beginnen)"
[[ "$TOKEN" =~ ^[A-Za-z0-9]{16,128}$ ]] || die "Ongeldig token (alleen letters en cijfers, zoals RzMon het toont)"
[[ "$INTERVAL" =~ ^[0-9]+$ ]] && [ "$INTERVAL" -ge 30 ] && [ "$INTERVAL" -le 3600 ] || die "--interval moet tussen 30 en 3600 liggen"

CURL_OPTS=(-fsS --max-time 20)
[ "$INSECURE" = 1 ] && CURL_OPTS+=(-k)

# ----------------------------------------------------------------- 1. hulpprogramma's
step "1/5 Benodigde programma's"
pm=""
for p in dnf yum apt-get zypper apk; do
    command -v "$p" >/dev/null 2>&1 && { pm=$p; break; }
done
need=()
command -v curl >/dev/null 2>&1 || need+=(curl)
command -v openssl >/dev/null 2>&1 || need+=(openssl)   # vervaldatum van certificaten
if ! command -v ss >/dev/null 2>&1; then
    case "$pm" in dnf|yum) need+=(iproute) ;; *) need+=(iproute2) ;; esac
fi
if [ "$SCHED" = "cron" ] || ! has_systemd; then
    # De map /etc/cron.d kan bestaan zonder cron-dienst: kijk naar het programma zelf
    if ! command -v crond >/dev/null 2>&1 && ! command -v cron >/dev/null 2>&1; then
        case "$pm" in dnf|yum|zypper) need+=(cronie) ;; apt-get) need+=(cron) ;; esac
    fi
fi
if [ ${#need[@]} -gt 0 ]; then
    [ -n "$pm" ] || die "Installeer eerst: ${need[*]}"
    info "Installeren: ${need[*]}"
    case "$pm" in
        apt-get) DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${need[@]}" >/dev/null ;;
        apk) apk add --no-cache "${need[@]}" >/dev/null ;;
        *) "$pm" -y -q install "${need[@]}" >/dev/null ;;
    esac
fi
command -v curl >/dev/null 2>&1 || die "curl ontbreekt"
ok "curl$(command -v ss >/dev/null 2>&1 && echo ', ss')$(command -v openssl >/dev/null 2>&1 && echo ', openssl') aanwezig"
command -v openssl >/dev/null 2>&1 || warn "openssl ontbreekt: certificaten worden niet gemeld"
command -v ss >/dev/null 2>&1 || warn "ss ontbreekt: luisterende poorten worden niet gemeld"

# ----------------------------------------------------------------- 2. agent ophalen
step "2/5 Agent ophalen"
tmp=$(mktemp)
trap 'rm -f "$tmp" "$tmp.setup"' EXIT
if [ -n "$SOURCE_FILE" ]; then
    cp "$SOURCE_FILE" "$tmp"
    info "Lokaal bestand: $SOURCE_FILE"
elif ! curl "${CURL_OPTS[@]}" "$URL/api.php?action=agent_script" -o "$tmp" 2>/tmp/rzmon-agent-dl.err; then
    err=$(cat /tmp/rzmon-agent-dl.err); rm -f /tmp/rzmon-agent-dl.err
    case "$err" in
        *SSL*|*certificate*) die "Certificaatprobleem met $URL. Gebruikt RzMon een self-signed certificaat? Voeg --insecure toe." ;;
        *) die "Kan de agent niet downloaden van $URL ($err). Klopt het adres en is RzMon vanaf deze server bereikbaar?" ;;
    esac
fi
rm -f /tmp/rzmon-agent-dl.err
head -1 "$tmp" | grep -q '^#!/usr/bin/env bash' && grep -q 'RzMon agent' "$tmp" \
    || die "Het gedownloade bestand is geen RzMon-agent. Klopt het adres ($URL)?"
old_version=$([ -x "$BIN" ] && "$BIN" --version 2>/dev/null | awk '{print $2}' || true)
new_version=$(sed -n 's/^AGENT_VERSION="\([^"]*\)".*/\1/p' "$tmp")
install -m 755 -o root -g root "$tmp" "$BIN"
command -v restorecon >/dev/null 2>&1 && restorecon "$BIN" 2>/dev/null || true
if [ -n "$old_version" ] && [ "$old_version" != "$new_version" ]; then
    ok "Agent bijgewerkt van $old_version naar ${new_version:-?}"
else
    ok "Agent ${new_version:-?} → $BIN"
fi

# ----------------------------------------------------------------- 3. services
if [ "$MODE" = "install" ]; then
    step "3/5 Services om te bewaken"
    if [ -z "$SERVICES" ] && has_systemd; then
        # Voorstel: bekende services die op deze server aan staan
        candidates="sshd ssh cron crond nginx httpd apache2 mariadb mysql postgresql redis redis-server docker containerd
                    php-fpm php8.3-fpm php8.2-fpm firewalld chronyd systemd-timesyncd NetworkManager smbd nmbd nfs-server
                    named bind9 unbound pihole-FTL dnsmasq haproxy caddy traefik mosquitto influxdb grafana-server
                    prometheus node_exporter zabbix-agent2 fail2ban postfix dovecot pveproxy pvedaemon pve-cluster corosync
                    libvirtd qemu-guest-agent wg-quick@wg0 openvpn-server@server tailscaled cloudflared"
        found=()
        for s in $candidates; do
            if [ "$(systemctl is-enabled "$s" 2>/dev/null)" = "enabled" ]; then
                found+=("$s")
            fi
        done
        SERVICES="${found[*]}"
        info "Gevonden: ${SERVICES:-(geen bekende services)}"
    fi
    if interactive; then
        SERVICES=$(ask "Te bewaken services (spatie-gescheiden, Enter = akkoord)" "$SERVICES")
    fi
    [[ "$SERVICES" =~ ^[A-Za-z0-9@._:\ -]*$ ]] || die "Ongeldige servicenaam in: $SERVICES"
    ok "${SERVICES:-geen services} "

    info ""
    info "Certificaten om te bewaken (vervaldatum):"
    if [ "$CERTS_SET" = 0 ]; then
        # Voorstel: Let's Encrypt-certificaten en lokale poorten waarop meestal TLS draait
        proposal=()
        compgen -G "/etc/letsencrypt/live/*/cert.pem" >/dev/null && proposal+=("/etc/letsencrypt/live/*/cert.pem")
        tls_ports=" 443 465 636 853 993 995 2083 5001 5986 6443 8006 8443 8883 8920 9443 10443 "
        if command -v ss >/dev/null 2>&1; then
            for port in $(ss -Htln 2>/dev/null | awk '{ p = $4; sub(/.*:/, "", p); print p }' | sort -un); do
                [[ "$tls_ports" == *" $port "* ]] && proposal+=("localhost:$port")
            done
        fi
        CERTS="${proposal[*]}"
        info "Gevonden: ${CERTS:-(geen)}"
    fi
    if interactive; then
        info "Bestanden (wildcards mogen) en/of host:poort, ook servers op internet (bv. example.com:443)."
        CERTS=$(ask "Certificaten (spatie-gescheiden, Enter = akkoord, - = geen)" "$CERTS")
        [ "$CERTS" = "-" ] && CERTS=""
    fi
    [[ "$CERTS" =~ ^[][A-Za-z0-9@._:/*?\ -]*$ ]] || die "Ongeldig teken in --certs: $CERTS"
    ok "${CERTS:-geen certificaten}"
fi

# ----------------------------------------------------------------- 4. instellingen + planning
step "4/5 Instellen"
if [ "$MODE" = "install" ]; then
    umask 077
    cat > "$CONF" <<EOF
# RzMon agent – aangemaakt door install-agent.sh op $(date '+%Y-%m-%d %H:%M')
MONITOR_URL="$URL"
TOKEN="$TOKEN"
SERVICES="$SERVICES"
# Certificaten: bestanden (wildcards mogen) en/of host:poort, ook op internet
CERTS="$CERTS"
INSECURE="$INSECURE"
INTERVAL="$INTERVAL"
EOF
    umask 022
    chmod 600 "$CONF"
    ok "$CONF (alleen leesbaar voor root)"
fi

use_systemd=0
if [ "$SCHED" != "cron" ] && has_systemd; then
    use_systemd=1
fi

if [ "$use_systemd" -eq 1 ]; then
    cat > "$UNIT" <<EOF
[Unit]
Description=RzMon agent: rapport naar RzMon
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
EnvironmentFile=$CONF
ExecStart=$BIN
Nice=10
NoNewPrivileges=yes
PrivateTmp=yes
CacheDirectory=rzmon-agent
ProtectSystem=full
ProtectHome=read-only
EOF
    cat > "$TIMER" <<EOF
[Unit]
Description=RzMon agent elke ${INTERVAL}s

[Timer]
OnBootSec=30s
OnUnitActiveSec=${INTERVAL}s
AccuracySec=5s
Unit=rzmon-agent.service

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload
    systemctl enable --now rzmon-agent.timer >/dev/null 2>&1
    rm -f "$CRON"   # oude cron-installatie opruimen
    ok "systemd-timer actief (elke ${INTERVAL}s). Logboek: journalctl -u rzmon-agent"
else
    minutes=$(( (INTERVAL + 59) / 60 ))
    sched="* * * * *"; [ "$minutes" -gt 1 ] && sched="*/$minutes * * * *"
    printf '# RzMon agent\n%s root set -a; . %s; %s >/dev/null 2>&1\n' "$sched" "$CONF" "$BIN" > "$CRON"
    chmod 644 "$CRON"
    for svc in crond cron; do
        if has_systemd && systemctl list-unit-files "$svc.service" >/dev/null 2>&1; then
            systemctl enable --now "$svc" >/dev/null 2>&1 && break
        fi
    done
    ok "cron: $CRON ($sched)"
fi

# Dit installatiescript bewaren voor later (bij "curl | bash" staat het nergens op schijf)
if curl "${CURL_OPTS[@]}" "$URL/api.php?action=agent_install" -o "$tmp.setup" 2>/dev/null && grep -q 'RzMon agent' "$tmp.setup"; then
    install -m 755 -o root -g root "$tmp.setup" "$SETUP"
elif [ -f "${BASH_SOURCE[0]:-}" ] && [ "$(realpath "${BASH_SOURCE[0]}")" != "$SETUP" ]; then
    install -m 755 -o root -g root "${BASH_SOURCE[0]}" "$SETUP"
fi
rm -f "$tmp.setup"

# ----------------------------------------------------------------- 5. testrapport
step "5/5 Testrapport naar RzMon"
# shellcheck disable=SC1090
if out=$(set -a; . "$CONF"; "$BIN" 2>&1); then
    ok "RzMon antwoordt: $out"
else
    warn "Rapport mislukt: $out"
    echo
    # Meteen de volledige diagnose tonen
    # shellcheck disable=SC1090
    (set -a; . "$CONF"; "$BIN" --test) || true
    die "De agent is geïnstalleerd, maar RzMon accepteert nog geen rapport. Los het bovenstaande op en test met: sudo rzmon-agent-setup --test"
fi

cat <<EOF

${C_OK}✔ De agent is geïnstalleerd en rapporteert elke ${INTERVAL}s aan ${URL}.${C_OFF}

  Volgende stap in RzMon: klik bij deze server op ＋ en voeg checks toe:
  CPU, RAM, Diskruimte (kies een mount), Service (kies uit: ${SERVICES:-–})
  en SSL-certificaat (kies uit: ${CERTS:-–}).

  Status bekijken : $( [ "$use_systemd" -eq 1 ] && echo "systemctl status rzmon-agent.timer; journalctl -u rzmon-agent -n 20" || echo "grep rzmon /var/log/cron* 2>/dev/null" )
  Services wijzigen: sudo rzmon-agent-setup --services "sshd nginx …"   (of: sudo nano $CONF)
  Certificaten     : sudo rzmon-agent-setup --certs "/pad/cert.pem site.nl:443 …"
  Verbinding testen: sudo rzmon-agent-setup --test
  Agent bijwerken : sudo rzmon-agent-setup --update
  Verwijderen     : sudo rzmon-agent-setup --uninstall

EOF
