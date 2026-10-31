#!/usr/bin/env bash
# =============================================================================
#  RzMon agent – installatiescript voor Linux-servers
#  (Rocky/RHEL/AlmaLinux/Fedora, Debian/Ubuntu/Raspberry Pi OS, Proxmox VE, TrueNAS SCALE)
#
#  Het makkelijkst: kopieer het commando uit RzMon (server → "Agent Manager"):
#    In RzMon: server → Agent Manager → 🔗 Koppelcode geeft het volledige commando (met controle van dit script):
#    curl -fsSL http://RZMON/api.php?action=agent_install -o rzmon-install.sh && sudo bash rzmon-install.sh --url http://RZMON --pair CODE
#
#  Wat het doet:
#    - ontbrekende hulpprogramma's installeren (curl, ss)
#    - de agent ophalen van je eigen RzMon-server (/usr/local/bin/rzmon-agent.sh)
#    - instellingen in /etc/default/rzmon-agent (alleen leesbaar voor root)
#    - elke minuut rapporteren via een systemd-timer (of cron als er geen systemd is)
#    - een testrapport sturen en controleren of RzMon het accepteert
#
#  TrueNAS SCALE (automatisch herkend): /usr en /etc horen bij TrueNAS zelf, dus alles komt op een pool
#  (standaard /mnt/<eerste pool>/rzmon-agent, of --pool NAAM / --dir PAD) en de agent draait via een
#  cronjob van TrueNAS (System → Advanced → Cron Jobs), die ook na een TrueNAS-update blijft staan.
#
#  Opnieuw uitvoeren is veilig: een bestaand instellingenbestand blijft precies zoals het is. Alleen wat je
#  zelf meegeeft (bv. --url of --token) wordt aangepast, en dan alleen die ene regel.
# =============================================================================
set -Eeuo pipefail

BIN="/usr/local/bin/rzmon-agent.sh"
CONF="/etc/default/rzmon-agent"
UNIT="/etc/systemd/system/rzmon-agent.service"
TIMER="/etc/systemd/system/rzmon-agent.timer"
WATCH="/etc/systemd/system/rzmon-watch.service"   # realtime-watcher (alleen actief als RzMon het aanzet)
CRON="/etc/cron.d/rzmon-agent"
SETUP="/usr/local/sbin/rzmon-agent-setup"   # dit script, voor later --update/--uninstall

URL=""
FALLBACK=""          # reserve-adres van RzMon (agent 1.51.0+): als URL niet bereikbaar is
FALLBACK_SET=0
TOKEN=""
ENC_KEY=""          # sleutel voor versleutelde rapporten (Instellingen → 🔐 Versleuteling)
PAIR=""             # koppelcode (RzMon → Agent Manager → 🔗 Koppelcode): de agent haalt zijn eigen sleutel op
PIN=""              # vingerafdruk van het eigen (self-signed) certificaat van RzMon: alleen dat certificaat accepteren
# Downloadsleutel: RzMon vult hem in als het dit script uitlevert (RzMon 1.86.0). Is RzMon van buiten "niet herkenbaar",
# dan levert het de agent alleen met deze sleutel (of aan een bekende agent).
DL_KEY=''
AGENT_SHA=""        # SHA-256 van de agent zoals RzMon hem levert: de download wordt daarmee gecontroleerd
SERVICES=""
CERTS=""
CERTS_SET=0           # --certs meegegeven (ook leeg = geen certificaten)
INSECURE=""
INTERVAL=""
SCHED="auto"          # auto | systemd | cron
EPEL="${EPEL-}"       # 0 = nooit EPEL installeren (--no-epel of EPEL=0 in de instellingen)
ASSUME_YES=0
MODE="install"        # install | update | status | cleanup | test | uninstall | version
SOURCE_FILE=""        # lokale rzmon-agent.sh i.p.v. downloaden
RESULT_FILE=""        # bijwerken vanuit RzMon: uitkomst hierin voor de agent ("ok …" of "error …")
TRUENAS=0             # 1 = TrueNAS SCALE (automatisch herkend, of --truenas)
POOL=""               # TrueNAS: pool voor de agent (standaard de eerste, niet boot-pool)
TN_DIR=""             # TrueNAS: map op de pool (standaard /mnt/<pool>/rzmon-agent)

if [ -t 1 ]; then
    C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_HEAD=$'\e[1;36m'; C_DIM=$'\e[2m'; C_OFF=$'\e[0m'
else
    C_OK=""; C_WARN=""; C_ERR=""; C_HEAD=""; C_DIM=""; C_OFF=""
fi
step() { printf '\n%s==> %s%s\n' "$C_HEAD" "$*" "$C_OFF"; }
ok()   { printf '    %s✔%s %s\n' "$C_OK" "$C_OFF" "$*"; }
info() { printf '    %s%s%s\n' "$C_DIM" "$*" "$C_OFF"; }
warn() { printf '    %s!%s %s\n' "$C_WARN" "$C_OFF" "$*"; }
die()  {
    printf '\n%s✘ %s%s\n' "$C_ERR" "$*" "$C_OFF" >&2
    [ -n "$RESULT_FILE" ] && printf 'error %s\n' "$*" >"$RESULT_FILE" 2>/dev/null
    exit 1
}
# Met het commando dat misging: bij bijwerken vanuit RzMon is de uitvoer niet te zien, alleen deze regel
trap 'die "Fout op regel $LINENO ($BASH_COMMAND). Het script kan veilig opnieuw worden uitgevoerd."' ERR

usage() {
    cat <<EOF
RzMon agent installeren

Gebruik: sudo bash install-agent.sh --url URL --pair KOPPELCODE [opties]

  --url URL            Adres van RzMon, bv. http://192.168.1.10
  --fallback URL       Reserve-adres als --url niet bereikbaar is (bv. https via de proxy, met het LAN-adres als reserve)
  --pair CODE          Koppelcode van deze server (RzMon → server → Agent Manager → 🔗 Koppelcode;
                       eenmalig, 15 minuten geldig). De agent krijgt dan zijn eigen sleutel.
  --pin VINGERAFDRUK   Alleen dit certificaat van RzMon accepteren (bij een eigen, self-signed certificaat)
  --agent-sha SHA256   De gedownloade agent hiermee controleren
  --token TOKEN        (oude manier) Agent-token van deze server, in plaats van een koppelcode
  --key SLEUTEL        Sleutel voor versleutelde rapporten (RzMon → Instellingen → 🔐 Versleuteling)
  --services "a b c"   (verouderd) Extra systemd-services; services kies je nu in RzMon
  --certs "a b c"      Certificaten: bestanden (wildcards mogen) en/of host:poort, ook op
                       internet, bv. "/etc/letsencrypt/live/*/cert.pem localhost:8443 site.nl"
                       (standaard: automatisch voorstel; "" = geen)
  --interval SEC       Tik als RzMon er geen doorgeeft (30-3600, standaard 60); normaal in RzMon instellen
  --insecure           Self-signed HTTPS-certificaat van RzMon accepteren
  --cron               Cron gebruiken in plaats van een systemd-timer
  --no-epel            Rocky/Alma/RHEL: EPEL niet installeren (dan geen bestanden in realtime)
  --truenas            TrueNAS SCALE (wordt normaal vanzelf herkend)
  --pool NAAM          TrueNAS: de agent op deze pool zetten (standaard de eerste pool)
  --dir PAD            TrueNAS: precies deze map gebruiken, bv. /mnt/tank/rzmon-agent
  --file PAD           Lokale rzmon-agent.sh gebruiken i.p.v. downloaden
  --result PAD         (intern) uitkomst van een update vanuit RzMon in dit bestand zetten
  -y, --yes            Niets vragen
  --test               Verbindingstest: planning, netwerk, token en een proefrapport
  --update             De agent bijwerken: eerst een reservekopie, daarna de nieuwe agent en opgeruimde
                       instellingen (wat je zelf instelde blijft; alleen wat niet meer nodig is gaat weg)
  --status             Alles over deze agent: verbinding, versleuteling, laatste contacten, wat er
                       meegestuurd wordt en de beveiligingscontrole van RzMon
  --cleanup            Alleen de instellingen opruimen (met een reservekopie)
  --uninstall          Agent verwijderen
  --version            Geïnstalleerde agentversie tonen
  -h, --help           Deze hulp
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="${2:?}"; shift ;;
        --fallback) FALLBACK="${2-}"; FALLBACK_SET=1; shift ;;
        --token) TOKEN="${2:?}"; shift ;;
        --key) ENC_KEY="${2:?}"; shift ;;
        --pair) PAIR="${2:?}"; shift ;;
        --pin) PIN="${2:?}"; shift ;;
        --agent-sha) AGENT_SHA="${2:?}"; shift ;;
        --services) SERVICES="${2-}"; shift ;;
        --certs) CERTS="${2-}"; CERTS_SET=1; shift ;;
        --interval) INTERVAL="${2:?}"; shift ;;
        --insecure) INSECURE=1 ;;
        --cron) SCHED="cron" ;;
        --no-epel) EPEL=0 ;;
        --truenas) TRUENAS=1 ;;
        --pool) POOL="${2:?}"; shift ;;
        --dir) TN_DIR="${2:?}"; shift ;;
        --file) SOURCE_FILE="${2:?}"; shift ;;
        --result) RESULT_FILE="${2:?}"; shift ;;
        -y|--yes) ASSUME_YES=1 ;;
        --update) MODE="update" ;;
        --status) MODE="status" ;;
        --cleanup) MODE="cleanup" ;;
        --test) MODE="test" ;;
        --uninstall) MODE="uninstall" ;;
        --version) MODE="version" ;;
        -h|--help) usage; exit 0 ;;
        *) usage; die "Onbekende optie: $1" ;;
    esac
    shift
done

# Wat je nu zelf meegeeft: bij een bestaande installatie worden alleen díe regels in de instellingen aangepast
ARG_URL=$URL ARG_TOKEN=$TOKEN ARG_KEY=$ENC_KEY ARG_PAIR=$PAIR ARG_PIN=$PIN ARG_SERVICES=$SERVICES ARG_INSECURE=$INSECURE ARG_INTERVAL=$INTERVAL ARG_CERTS=$CERTS_SET
ARG_NO_EPEL=0; [ "$EPEL" = 0 ] && ARG_NO_EPEL=1

if [ "$MODE" = "version" ]; then
    if [ -f "$BIN" ]; then bash "$BIN" --version 2>/dev/null || echo "rzmon-agent (oude versie, zonder versienummer)"; else echo "Agent niet geïnstalleerd"; fi
    exit 0
fi

[ "$(id -u)" -eq 0 ] || die "Voer dit uit als root (met sudo)."

has_systemd() { [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; }

# ----------------------------------------------------------------- TrueNAS SCALE
# Herkennen aan midclt (de TrueNAS-API); dan alles in één map op een pool
command -v midclt >/dev/null 2>&1 && [ -d /mnt ] && TRUENAS=1
if [ "$TRUENAS" = 1 ]; then
    # Welke map: --dir, anders AGENT_DIR (bijwerken vanuit RzMon), anders de map van dit script
    # (als daar al een agent staat), anders --pool of de eerste pool
    [ -n "$TN_DIR" ] || TN_DIR=${AGENT_DIR-}
    if [ -z "$TN_DIR" ]; then
        here=$(cd "$(dirname "${BASH_SOURCE[0]:-/nonexistent/x}")" 2>/dev/null && pwd || true)
        [ -n "$here" ] && [ -f "$here/agent.conf" ] && TN_DIR=$here
    fi
    if [ -z "$TN_DIR" ]; then
        [ -n "$POOL" ] || POOL=$(zpool list -H -o name 2>/dev/null | grep -vx 'boot-pool' | head -1 || true)
        [ -n "$POOL" ] || die "Geen pool gevonden. Geef hem op met --pool NAAM"
        [ -d "/mnt/$POOL" ] || die "Pool $POOL is niet gekoppeld onder /mnt/$POOL"
        TN_DIR="/mnt/$POOL/rzmon-agent"
    fi
    TN_DIR=${TN_DIR%/}
    [[ "$TN_DIR" =~ ^/mnt/[A-Za-z0-9._/-]+$ ]] || die "Ongeldige map: $TN_DIR (moet op een pool staan, onder /mnt/)"
    BIN="$TN_DIR/rzmon-agent.sh"
    CONF="$TN_DIR/agent.conf"
    SETUP="$TN_DIR/rzmon-agent-setup"
fi
TN_CRON_DESC="RzMon agent"
# Commando voor de cronjob van TrueNAS (instellingen inlezen, dan de agent)
tn_command() { printf "bash -c 'set -a; . %s; bash %s'" "$CONF" "$BIN"; }
# Id van onze cronjob in TrueNAS, of leeg
tn_cron_id() {
    midclt call cronjob.query "[[\"description\",\"=\",\"$TN_CRON_DESC\"]]" 2>/dev/null \
        | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d[0]["id"] if d else "")' 2>/dev/null || true
}
# Cronjob aanmaken of bijwerken: als root, elke minuut (of elke N minuten), uitvoer verborgen (anders elke minuut een mail)
tn_cron_set() {
    local minute=$1 id payload
    payload=$(TN_CMD="$(tn_command)" TN_MIN="$minute" TN_DESC="$TN_CRON_DESC" python3 -c '
import json, os
print(json.dumps({"description": os.environ["TN_DESC"], "command": os.environ["TN_CMD"], "user": "root", "enabled": True,
                  "stdout": True, "stderr": True,
                  "schedule": {"minute": os.environ["TN_MIN"], "hour": "*", "dom": "*", "month": "*", "dow": "*"}}))') || return 1
    id=$(tn_cron_id)
    if [ -n "$id" ]; then
        midclt call cronjob.update "$id" "$payload" >/dev/null
    else
        midclt call cronjob.create "$payload" >/dev/null
    fi
}
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
    if [ "$TRUENAS" = 1 ]; then
        id=$(tn_cron_id)
        [ -n "$id" ] && midclt call cronjob.delete "$id" >/dev/null && ok "Cronjob \"$TN_CRON_DESC\" verwijderd"
    elif has_systemd; then
        systemctl disable --now rzmon-agent.timer rzmon-watch.service >/dev/null 2>&1 || true
        rm -f "$UNIT" "$TIMER" "$WATCH"
        systemctl daemon-reload
    fi
    rm -f "$CRON" "$BIN" "$SETUP" /usr/local/bin/rzmon-hb
    rm -rf /var/spool/rzmon-agent
    rm -rf /var/cache/rzmon-agent /var/lib/rzmon-agent   # ook de eigen sleutel: opnieuw installeren = opnieuw koppelen
    if [ -f "$CONF" ]; then
        if interactive && [ "$(ask 'Ook de instellingen (URL en token) verwijderen? j/n' 'j')" != "n" ]; then
            rm -f "$CONF"
        elif ! interactive; then
            rm -f "$CONF"
        fi
    fi
    [ "$TRUENAS" = 1 ] && { rm -rf "$TN_DIR/keys"; rmdir "$TN_DIR" 2>/dev/null; } || true
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

# ----------------------------------------------------------------- reservekopie en opruimen (1.60.0)
# Vóór elke update en bij --cleanup: een kopie van de huidige agent en instellingen (de laatste 5 van elk). Daarna
# gaan de regels die niet meer nodig zijn uit de instellingen; wat je zelf instelde blijft precies zoals het was.
if [ "$TRUENAS" = 1 ]; then KDIR="$TN_DIR/keys"; BKDIR="$TN_DIR/backup"; TCACHE=""
else KDIR=/var/lib/rzmon-agent; BKDIR=/var/lib/rzmon-agent/backup; TCACHE=/var/cache/rzmon-agent; fi
BK_CONF=""
backup_now() {
    { mkdir -p "$BKDIR" && chmod 700 "$BKDIR"; } 2>/dev/null || { warn "Geen reservekopie: kan $BKDIR niet aanmaken"; return 0; }
    local ts v
    ts=$(date +%Y%m%d-%H%M%S)
    if [ -f "$BIN" ]; then
        v=$(bash "$BIN" --version 2>/dev/null | awk '{print $2}')
        cp -p "$BIN" "$BKDIR/agent-${v:-oud}-$ts.sh" && chmod 600 "$BKDIR/agent-${v:-oud}-$ts.sh"
    fi
    if [ -f "$CONF" ]; then
        BK_CONF="$BKDIR/conf-$ts"
        cp -p "$CONF" "$BK_CONF" && chmod 600 "$BK_CONF"
    fi
    ls -1t "$BKDIR"/agent-*.sh 2>/dev/null | tail -n +6 | xargs -r rm -f
    ls -1t "$BKDIR"/conf-* 2>/dev/null | tail -n +6 | xargs -r rm -f
    ok "Reservekopie van de huidige agent ${v:+($v) }en instellingen in $BKDIR"
}
# Uitleg boven elke regel in het opgeruimde bestand
conf_comment() {
    case "$1" in
        MONITOR_URL) echo "# Adres van RzMon" ;;
        MONITOR_URL_FALLBACK) echo "# Reserve-adres: als MONITOR_URL niet bereikbaar is (RzMon meldt dat met ⚠️)" ;;
        TOKEN) echo "# Token (oude manier): nodig zolang deze server nog geen eigen sleutel heeft" ;;
        PAIR_CODE) echo "# Koppelcode: hiermee haalt de agent zijn eigen sleutel op" ;;
        ENC_KEY) echo "# Algemene sleutel (oude manier): nodig zolang deze server nog geen eigen sleutel heeft" ;;
        PIN) echo "# Vingerafdruk van het certificaat van RzMon" ;;
        INSECURE) echo "# ⚠️ Certificaat van RzMon niet controleren (beter: een vingerafdruk via een koppelcode)" ;;
        SERVICES) echo "# (verouderd) Services; RzMon maakt er service-checks van, daarna kan deze regel weg" ;;
        CERTS) echo "# Certificaten: bestanden (wildcards mogen) en/of host:poort" ;;
        CERT_DIRS) echo "# Mappen waarin de agent naar certificaatbestanden zoekt" ;;
        COMMANDS) echo "# Commando-checks op deze server: leeg = zoals RzMon zegt, 0 = nooit, nobody = nooit als root" ;;
        REMOTE_UPDATE) echo "# 0 = niet bijwerken vanuit RzMon" ;;
        EPEL) echo "# Rocky/Alma/RHEL: EPEL niet installeren (geen bestanden in realtime)" ;;
        AGENT_DIR|AGENT_SETUP) echo "# TrueNAS: map van de agent en het installatiescript" ;;
    esac
}
CLEAN_GONE=""
cleanup_conf() {
    [ -f "$CONF" ] || return 0
    local own=0 pinf=0 line n v s svc_ok cur_url tmpc keep=() gone=()
    [ -s "$KDIR/agent.key" ] && own=1
    { [ -s "$KDIR/pin" ] || [ -s "$KDIR/url.pins" ]; } && pinf=1
    cur_url=$(sed -n 's/^MONITOR_URL="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CONF" | tail -1)
    while IFS= read -r line || [ -n "$line" ]; do
        [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue   # uitleg en lege regels: het nieuwe bestand krijgt eigen uitleg
        if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
            n=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}; v=${v#\"}; v=${v%\"}
            case "$n" in
                TOKEN|ENC_KEY|PAIR_CODE) if [ -z "$v" ] || [ "$own" = 1 ]; then gone+=("$n"); continue; fi ;;
                INTERVAL) gone+=("$n"); continue ;;   # de tik komt uit RzMon
                PIN) if [ -z "$v" ] || [ "$pinf" = 1 ]; then gone+=("$n"); continue; fi ;;
                INSECURE) if [ "$v" != 1 ] || [[ "$cur_url" != https://* ]] || [ "$pinf" = 1 ]; then gone+=("$n"); continue; fi ;;
                MONITOR_URL_FALLBACK) if [ -z "$v" ]; then gone+=("$n"); continue; fi ;;
                SERVICES)
                    # Pas weg als RzMon voor elke service een check heeft (dan staat hij in de lijst van RzMon)
                    svc_ok=1
                    for s in $v; do grep -q "^[0-9]*"$'\t'"svc"$'\t'"$s\$" "$TCACHE/tests.cache" 2>/dev/null || svc_ok=0; done
                    if [ -z "$v" ] || [ "$svc_ok" = 1 ]; then gone+=("$n"); continue; fi ;;
            esac
            [ "$n" = MONITOR_URL ] && { keep=("$(conf_comment "$n")" "$line" "${keep[@]}"); continue; }
            s=$(conf_comment "$n"); [ -n "$s" ] && keep+=("$s")
        fi
        keep+=("$line")
    done <"$CONF"
    if [ "$own" = 1 ] && [ -n "$TCACHE" ] && [ -e "$TCACHE/enc.key" ]; then
        rm -f "$TCACHE/enc.key" "$TCACHE/enc.fail"; gone+=(enc.key)
    fi
    if [ ${#gone[@]} -eq 0 ]; then ok "Instellingen: niets overbodig"; return 0; fi
    CLEAN_GONE="${gone[*]}"
    tmpc=$(mktemp "$CONF.XXXXXX")
    {
        echo "# RzMon agent – opgeruimd door rzmon-agent-setup op $(date '+%d-%m-%Y %H:%M')${BK_CONF:+ (vorige versie: $BK_CONF)}"
        echo "# De tik, services, sleutels en de vingerafdruk regelt RzMon zelf; die staan hier niet meer."
        printf '%s\n' "${keep[@]}"
    } >"$tmpc"
    chmod 600 "$tmpc"; mv -f "$tmpc" "$CONF"
    ok "Instellingen opgeruimd; weggehaald: ${gone[*]}"
}

if [ "$MODE" = "status" ]; then
    [ -f "$CONF" ] || die "Nog geen agent geïnstalleerd ($CONF ontbreekt)."
    # shellcheck disable=SC1090
    (set -a; . "$CONF"; bash "$BIN" --status)
    exit $?
fi
if [ "$MODE" = "cleanup" ]; then
    [ -f "$CONF" ] || die "Nog geen agent geïnstalleerd ($CONF ontbreekt)."
    step "Instellingen opruimen"
    backup_now
    cleanup_conf
    [ -n "$CLEAN_GONE" ] && info "Terugzetten kan met: cp $BK_CONF $CONF"
    exit 0
fi

# ----------------------------------------------------------------- verbindingstest
if [ "$MODE" = "test" ]; then
    [ -f "$CONF" ] || die "Nog geen agent geïnstalleerd ($CONF ontbreekt)."
    [ -f "$BIN" ] || die "$BIN ontbreekt. Installeer opnieuw (commando uit RzMon → Agent Manager)."
    printf '%sRzMon agent – planning%s\n' "$C_HEAD" "$C_OFF"
    if [ "$TRUENAS" = 1 ]; then
        if [ -n "$(tn_cron_id)" ]; then
            ok "TrueNAS-cronjob \"$TN_CRON_DESC\" aanwezig ($TN_DIR)"
        else
            warn "Geen TrueNAS-cronjob \"$TN_CRON_DESC\" gevonden: installeer opnieuw (commando uit RzMon → Agent Manager → TrueNAS)"
        fi
    elif has_systemd && [ -f "$TIMER" ]; then
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
    (set -a; . "$CONF"; bash "$BIN" --test)
    exit $?
fi

if [ "$MODE" = "update" ]; then
    [ -f "$CONF" ] || die "Nog geen agent geïnstalleerd ($CONF ontbreekt). Voer eerst een installatie uit."
fi

printf '%sRzMon agent – %s%s\n' "$C_HEAD" "$( [ "$TRUENAS" = 1 ] && echo "TrueNAS SCALE ($TN_DIR)" || { . /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-Linux}"; })" "$C_OFF"

# ----------------------------------------------------------------- URL en token
if [ -z "$URL" ]; then
    interactive || die "Geef het adres van RzMon mee: --url http://…"
    URL=$(ask "Adres van RzMon (bv. http://192.168.1.10)" "")
fi
# Een token of koppelcode is alleen nodig voor een nieuwe koppeling: niet bij bijwerken, en niet als deze server al een
# eigen sleutel heeft (1.60.0; daarvoor stopte --update bij een agent die met een koppelcode was gekoppeld)
if [ -z "$TOKEN" ] && [ -z "$PAIR" ] && [ "$MODE" != "update" ] && [ ! -s "$KDIR/agent.key" ]; then
    interactive || die "Geef de koppelcode mee: --pair … (RzMon → server → Agent Manager → 🔗 Koppelcode)"
    PAIR=$(ask "Koppelcode (RzMon → server → Agent Manager → 🔗 Koppelcode)" "")
fi
URL=${URL%/}
URL=${URL%/api.php*}
[[ "$URL" =~ ^https?://[^[:space:]\"\'\\\$\`]+$ ]] || die "Ongeldig adres: $URL (moet met http:// of https:// beginnen)"
[ -z "$TOKEN" ] || [[ "$TOKEN" =~ ^[A-Za-z0-9]{16,128}$ ]] || die "Ongeldig token (alleen letters en cijfers, zoals RzMon het toont)"
[ -z "$PAIR" ] || [[ "$PAIR" =~ ^[A-Za-z0-9-]{20,40}$ ]] || die "Ongeldige koppelcode (bv. K7QF-M2XA-9RTD-HC4P-WE8N, zoals RzMon hem toont)"
[ -z "$PIN" ] || [[ "$PIN" =~ ^[A-Za-z0-9+/]{43}=$ ]] || die "Ongeldige vingerafdruk (--pin), kopieer het commando opnieuw uit RzMon"
[ -z "$AGENT_SHA" ] || [[ "$AGENT_SHA" =~ ^[0-9a-f]{64}$ ]] || die "Ongeldige --agent-sha"
[ -z "$ENC_KEY" ] || [[ "$ENC_KEY" =~ ^[0-9a-f]{64}$ ]] || die "Ongeldige sleutel (64 tekens 0-9 en a-f, zoals RzMon hem toont)"
[[ "$INTERVAL" =~ ^[0-9]+$ ]] && [ "$INTERVAL" -ge 30 ] && [ "$INTERVAL" -le 3600 ] || die "--interval moet tussen 30 en 3600 liggen"

CURL_OPTS=(-fsS --max-time 20)
# Agent 1.66.1: geen --pin meegegeven? Dan de controle die de agent zelf al gebruikt (RzMon gaf die door, per adres in
# url.pins, of de vingerafdruk in pin). Sinds --cleanup PIN uit de instellingen haalt, faalde --update anders met
# "Certificaatprobleem" bij een eigen (self-signed) certificaat van RzMon.
PIN_SPEC=""
if [ -z "$PIN" ] && [[ "$URL" == https://* ]]; then
    PIN_SPEC=$(awk -v u="$URL" '$1 == u { print $2; exit }' "$KDIR/url.pins" 2>/dev/null)
    if [ -z "$PIN_SPEC" ] && [ -s "$KDIR/pin" ] && [ ! -e "$KDIR/pin.off" ]; then
        PIN_SPEC="sha256//$(tr -dc 'A-Za-z0-9+/=' <"$KDIR/pin" | head -c 64)"
    fi
    [[ "$PIN_SPEC" =~ ^sha256//[A-Za-z0-9+/=]{40,64}(\;sha256//[A-Za-z0-9+/=]{40,64})?$ ]] || PIN_SPEC=""
fi
if [ -n "$PIN" ] && [[ "$URL" == https://* ]]; then
    CURL_OPTS+=(-k --pinnedpubkey "sha256//$PIN")   # alleen het certificaat met deze vingerafdruk
elif [ -n "$PIN_SPEC" ]; then
    CURL_OPTS+=(-k --pinnedpubkey "$PIN_SPEC")      # het vastgezette certificaat van RzMon (van de agent)
elif [ "$INSECURE" = 1 ]; then
    CURL_OPTS+=(-k)
fi

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
if [ "$TRUENAS" = 0 ] && { [ "$SCHED" = "cron" ] || ! has_systemd; }; then
    # De map /etc/cron.d kan bestaan zonder cron-dienst: kijk naar het programma zelf
    if ! command -v crond >/dev/null 2>&1 && ! command -v cron >/dev/null 2>&1; then
        case "$pm" in dnf|yum|zypper) need+=(cronie) ;; apt-get) need+=(cron) ;; esac
    fi
fi
if [ "$TRUENAS" = 1 ]; then
    need=()   # TrueNAS: pakketten installeren kan niet (en is niet nodig: curl, ss en openssl zijn er)
    # Bestaat de map al (bijwerken), dan niets aanmaken. Rechten 700 lukt niet op elke dataset (ACL's): geen reden om te stoppen.
    if [ ! -d "$TN_DIR" ]; then
        mk_err=$(mkdir -p "$TN_DIR" 2>&1) || die "Kan de map $TN_DIR niet aanmaken: ${mk_err#mkdir: } (staat de pool op alleen-lezen, of is hij versleuteld en op slot?)"
    fi
    chmod 700 "$TN_DIR" 2>/dev/null || warn "Rechten van $TN_DIR niet op 700 te zetten (ACL van de dataset?); de agent werkt wel"
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
# inotify-tools voor de realtime-watcher (bestanden). Op Rocky, Alma, RHEL en Oracle Linux staat het in EPEL:
# dat wordt dan eerst geïnstalleerd (niet met --no-epel of EPEL=0). Mag mislukken (bv. zonder internet):
# dan werkt realtime voor logins en services wel, bestanden alleen bij de gewone runs, en meldt RzMon het.
[ -f "$CONF" ] && grep -qE '^EPEL="?0"?$' "$CONF" && EPEL=0
# (grep zonder -q: met pipefail zou een vroeg stoppende grep de pijplijn als mislukt laten gelden)
epel_present() { rpm -q epel-release >/dev/null 2>&1 || rpm -qa 2>/dev/null | grep '^oracle-epel-release' >/dev/null || "$pm" repolist enabled 2>/dev/null | grep -i epel >/dev/null; }
if [ "$TRUENAS" = 0 ] && has_systemd && ! command -v inotifywait >/dev/null 2>&1 && [ -n "$pm" ]; then
    case "$pm" in
        apt-get) DEBIAN_FRONTEND=noninteractive apt-get install -y -qq inotify-tools >/dev/null 2>&1 || true ;;
        apk) apk add --no-cache inotify-tools >/dev/null 2>&1 || true ;;
        dnf|yum)
            info "inotify-tools ontbreekt; staat in EPEL (Extra Packages for Enterprise Linux)"
            if epel_present; then
                :
            elif [ "$EPEL" = 0 ]; then
                warn "EPEL wordt niet geïnstalleerd (--no-epel / EPEL=0): bestanden alleen bij de gewone runs"
            else
                os_id=$(. /etc/os-release 2>/dev/null; echo "${ID:-}")
                os_ver=$(. /etc/os-release 2>/dev/null; echo "${VERSION_ID:-}"); os_ver=${os_ver%%.*}
                case "$os_id" in
                    rhel) epel_pkg="https://dl.fedoraproject.org/pub/epel/epel-release-latest-${os_ver}.noarch.rpm" ;;   # officieel, van het Fedora-project
                    ol) epel_pkg="oracle-epel-release-el${os_ver}" ;;
                    *) epel_pkg="epel-release" ;;   # Rocky, Alma, CentOS Stream: in de standaardrepository "extras"
                esac
                info "EPEL installeren: $pm install $epel_pkg"
                if "$pm" -y -q install "$epel_pkg" >/dev/null 2>&1; then
                    ok "EPEL geïnstalleerd"
                else
                    warn "EPEL installeren mislukt (geen internet?). Later: sudo $pm install $epel_pkg && sudo $pm install inotify-tools, of ⬆ Opnieuw installeren in RzMon"
                fi
            fi
            epel_present && { "$pm" -y -q install inotify-tools >/dev/null 2>&1 || true; } ;;
        *) "$pm" -y -q install inotify-tools >/dev/null 2>&1 || true ;;
    esac
    command -v inotifywait >/dev/null 2>&1 && ok "inotify-tools geïnstalleerd (bestanden in realtime)" \
        || warn "inotify-tools niet beschikbaar: bestanden worden alleen bij de gewone runs gecontroleerd (RzMon meldt dit in de Agent Manager)"
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
elif ! curl "${CURL_OPTS[@]}" "$URL/api.php?action=agent_script${DL_KEY:+&k=$DL_KEY}" -o "$tmp" 2>/tmp/rzmon-agent-dl.err; then
    err=$(cat /tmp/rzmon-agent-dl.err); rm -f /tmp/rzmon-agent-dl.err
    case "$err" in
        *pinned*|*"public key"*) die "Het certificaat van $URL past niet bij de vingerafdruk die de agent kent. Is het certificaat van RzMon vernieuwd? Kopieer dan het installatiecommando opnieuw uit RzMon (met --pin)." ;;
        *SSL*|*certificate*) die "Certificaatprobleem met $URL. Gebruikt RzMon een eigen (self-signed) certificaat? Kopieer het installatiecommando uit RzMon (met --pin), of voeg --insecure toe." ;;
        *) die "Kan de agent niet downloaden van $URL ($err). Klopt het adres en is RzMon vanaf deze server bereikbaar?" ;;
    esac
fi
rm -f /tmp/rzmon-agent-dl.err
head -1 "$tmp" | grep -q '^#!/usr/bin/env bash' && grep -q 'RzMon agent' "$tmp" \
    || die "Het gedownloade bestand is geen RzMon-agent. Klopt het adres ($URL)?"
if [ -n "$AGENT_SHA" ]; then
    [ "$(sha256sum "$tmp" | cut -d' ' -f1)" = "$AGENT_SHA" ] \
        || die "Controlegetal (SHA-256) van de agent klopt niet: onderweg gewijzigd, of RzMon is intussen bijgewerkt. Kopieer het commando opnieuw uit RzMon."
    ok "Controlegetal van de agent klopt"
fi
old_version=$([ -f "$BIN" ] && bash "$BIN" --version 2>/dev/null | awk '{print $2}' || true)
new_version=$(sed -n 's/^AGENT_VERSION="\([^"]*\)".*/\1/p' "$tmp")
# Reservekopie van wat er nu staat (bijwerken of opnieuw installeren), daarna de nieuwe agent
if [ -f "$BIN" ] || [ -f "$CONF" ]; then backup_now; fi
install -m 755 -o root -g root "$tmp" "$BIN"
# rzmon-hb: scripts en cronjobs melden zich bij RzMon (heartbeat, agent 1.52.0). Op TrueNAS (alleen-lezen systeem):
# "$TN_DIR/rzmon-agent.sh --hb NAAM", met de wachtrij in de map van de agent.
if [ "$TRUENAS" != 1 ]; then
    ln -sf "$BIN" /usr/local/bin/rzmon-hb 2>/dev/null || warn "Kon /usr/local/bin/rzmon-hb niet maken; gebruik $BIN --hb"
    { mkdir -p /var/spool/rzmon-agent && chmod 1733 /var/spool/rzmon-agent; } 2>/dev/null || true
fi
command -v restorecon >/dev/null 2>&1 && restorecon "$BIN" 2>/dev/null || true
if [ -n "$old_version" ] && [ "$old_version" != "$new_version" ]; then
    ok "Agent bijgewerkt van $old_version naar ${new_version:-?}"
else
    ok "Agent ${new_version:-?} → $BIN"
fi

# ----------------------------------------------------------------- 3. services
if [ "$MODE" = "install" ]; then
    step "3/5 Wat bewaken"
    # Services kies je in RzMon (＋ Check → Service): de agent stuurt een lijst van de services op deze
    # server mee en bewaakt vanzelf wat je daar kiest. --services werkt nog (verouderd).
    [[ "$SERVICES" =~ ^[A-Za-z0-9@._:\ -]*$ ]] || die "Ongeldige servicenaam in: $SERVICES"
    if [ -n "$SERVICES" ]; then
        ok "Services (uit --services of de oude instellingen): $SERVICES"
        info "Tip: services kies je voortaan in RzMon; deze lijst is niet meer nodig."
    else
        ok "Services kies je straks in RzMon (＋ Check → Service), uit een lijst van deze server"
    fi

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
# Eén regel in de bestaande instellingen zetten; de rest van het bestand blijft precies gelijk
conf_set() {  # conf_set NAAM WAARDE
    local tmp
    if grep -q "^$1=" "$CONF"; then
        tmp=$(mktemp "$CONF.XXXXXX")
        awk -v k="$1=" -v line="$1=\"$2\"" 'index($0, k) == 1 { print line; next } { print }' "$CONF" >"$tmp"
        chmod 600 "$tmp"
        mv -f "$tmp" "$CONF"
    else
        printf '%s="%s"\n' "$1" "$2" >>"$CONF"
    fi
    ok "$CONF: $1 aangepast"
    conf_changed=1
}
if [ "$MODE" = "install" ] && [ -f "$CONF" ]; then
    # Bestaande installatie: het bestand niet opnieuw schrijven, alleen wat je nu meegeeft en anders is
    conf_changed=0
    if [ -n "$ARG_URL" ] && [ "$URL" != "${OLD_URL-}" ]; then conf_set MONITOR_URL "$URL"; fi
    if [ -n "$ARG_TOKEN" ] && [ "$TOKEN" != "${OLD_TOKEN-}" ]; then conf_set TOKEN "$TOKEN"; fi
    if [ -n "$ARG_KEY" ] && ! grep -qx "ENC_KEY=\"$ENC_KEY\"" "$CONF"; then conf_set ENC_KEY "$ENC_KEY"; fi
    if [ -n "$ARG_PAIR" ] && ! grep -qx "PAIR_CODE=\"$PAIR\"" "$CONF"; then conf_set PAIR_CODE "$PAIR"; fi
    if [ -n "$ARG_PIN" ] && ! grep -qx "PIN=\"$PIN\"" "$CONF"; then conf_set PIN "$PIN"; fi
    if [ -n "$ARG_SERVICES" ] && [ "$SERVICES" != "${OLD_SERVICES-}" ]; then conf_set SERVICES "$SERVICES"; fi
    if [ "$ARG_CERTS" = 1 ] && [ "$CERTS" != "${OLD_CERTS-}" ]; then conf_set CERTS "$CERTS"; fi
    if [ -n "$ARG_INSECURE" ] && [ "$INSECURE" != "${OLD_INSECURE-}" ]; then conf_set INSECURE "$INSECURE"; fi
    if [ -n "$ARG_INTERVAL" ] && [ "$INTERVAL" != "${OLD_INTERVAL-}" ]; then conf_set INTERVAL "$INTERVAL"; fi
    if [ "$ARG_NO_EPEL" = 1 ] && ! grep -qE '^EPEL="?0"?$' "$CONF"; then conf_set EPEL 0; fi
    if [ "$FALLBACK_SET" = 1 ] && ! grep -qx "MONITOR_URL_FALLBACK=\"${FALLBACK%/}\"" "$CONF"; then conf_set MONITOR_URL_FALLBACK "${FALLBACK%/}"; fi
    [ "$conf_changed" = 1 ] || ok "$CONF blijft ongewijzigd"
elif [ "$MODE" = "install" ]; then
    NEW_CONF=1
    umask 077
    cat > "$CONF" <<EOF
# RzMon agent – aangemaakt door install-agent.sh op $(date '+%Y-%m-%d %H:%M')
MONITOR_URL="$URL"
# Reserve-adres: gebruikt als MONITOR_URL niet bereikbaar is (bv. de reverse proxy ligt eruit); RzMon meldt dat met ⚠️
MONITOR_URL_FALLBACK="${FALLBACK%/}"
TOKEN="$TOKEN"
# Koppelcode (eenmalig): daarmee haalt de agent zijn eigen sleutel op. Daarna is hij niet meer nodig.
PAIR_CODE="$PAIR"
# Vingerafdruk van het certificaat van RzMon (eigen certificaat); RzMon stuurt een nieuwe vanzelf mee
PIN="$PIN"
# (oude manier) Algemene sleutel voor versleutelde rapporten; met een koppelcode niet nodig
ENC_KEY="$ENC_KEY"
# Services stel je in RzMon in (＋ Check → Service). SERVICES is verouderd: wat hier staat wordt ook gemeld.
SERVICES="$SERVICES"
# Certificaten: bestanden (wildcards mogen) en/of host:poort, ook op internet
CERTS="$CERTS"
INSECURE="$INSECURE"
# Tik (seconden) als RzMon er geen doorgeeft; normaal stel je hem in RzMon in (Agent Manager)
INTERVAL="$INTERVAL"
EOF
    if [ "$EPEL" = 0 ]; then printf '# Rocky/Alma/RHEL: EPEL niet installeren (geen bestanden in realtime)\nEPEL=0\n' >>"$CONF"; fi
    if [ "$TRUENAS" = 1 ]; then
        # Voor bijwerken vanuit RzMon: waar de agent en dit script staan
        printf '# TrueNAS: map van de agent en het installatiescript (bijwerken vanuit RzMon)\nAGENT_DIR="%s"\nAGENT_SETUP="%s"\n' "$TN_DIR" "$SETUP" >>"$CONF"
    fi
    umask 022
    chmod 600 "$CONF"
    ok "$CONF (alleen leesbaar voor root)"
fi

# Bijwerken of opnieuw installeren: overbodige regels uit de instellingen (1.60.0)
if [ -f "$CONF" ] && { [ "$MODE" = "update" ] || [ -n "$BK_CONF" ]; }; then cleanup_conf; fi

use_systemd=0
if [ "$TRUENAS" = 0 ] && [ "$SCHED" != "cron" ] && has_systemd; then
    use_systemd=1
fi
# Rootless podman (containers van gewone gebruikers) moet newuidmap kunnen gebruiken en lock-
# bestanden in de home-map schrijven; zonder podman blijft de service strenger afgeschermd.
hardening="NoNewPrivileges=yes
ProtectHome=read-only"
if command -v podman >/dev/null 2>&1; then
    hardening="# Podman aanwezig: nodig om ook rootless containers van gebruikers uit te lezen
NoNewPrivileges=no
ProtectHome=no"
fi

if [ "$TRUENAS" = 1 ]; then
    # Elke minuut starten; de agent rapporteert volgens de tik uit RzMon (minimaal elke minuut via cron)
    if tn_cron_set "*"; then
        ok "TrueNAS-cronjob \"$TN_CRON_DESC\" (elke minuut, uitvoer verborgen); zie System → Advanced → Cron Jobs"
    else
        warn "De cronjob kon niet via de TrueNAS-API worden aangemaakt. Maak hem zelf aan onder"
        warn "System → Advanced → Cron Jobs → Add, als root, elke minuut, met Hide Standard Output/Error aan:"
        warn "  $(tn_command)"
    fi
elif [ "$use_systemd" -eq 1 ]; then
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
$hardening
PrivateTmp=yes
CacheDirectory=rzmon-agent
ProtectSystem=full
EOF
    cat > "$TIMER" <<EOF
[Unit]
Description=RzMon agent (start elke 30 s; rapporteert volgens de tik uit RzMon)

[Timer]
OnBootSec=30s
OnUnitActiveSec=30s
AccuracySec=5s
Unit=rzmon-agent.service

[Install]
WantedBy=timers.target
EOF
    # Realtime-watcher: draait altijd, maar doet pas iets als realtime voor deze server in RzMon aan staat
    cat > "$WATCH" <<EOF
[Unit]
Description=RzMon realtime-watcher (alleen actief als het in RzMon aan staat)
Wants=network-online.target
After=network-online.target

[Service]
Type=simple
EnvironmentFile=$CONF
ExecStart=$BIN --watch
Restart=always
RestartSec=10
Nice=10
$hardening
# Geen eigen /tmp: de endpoint-bewaking (agent 1.63.0) moet nieuwe programma's in het echte /tmp en /var/tmp zien
PrivateTmp=no
CacheDirectory=rzmon-agent
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    # Agent 1.68.0: de timer start pas na het testrapport (stap 5); anders draaien er twee runs tegelijk en koppelt
    # de timer al met de eenmalige code terwijl het testrapport dat ook probeert
    systemctl enable rzmon-agent.timer >/dev/null 2>&1
    systemctl stop rzmon-agent.timer >/dev/null 2>&1 || true
    START_TIMER=1
    systemctl enable rzmon-watch.service >/dev/null 2>&1 || true
    systemctl restart rzmon-watch.service >/dev/null 2>&1 || true   # nieuwe versie van de agent meteen gebruiken
    rm -f "$CRON"   # oude cron-installatie opruimen
    ok "systemd-timer klaar (gaat aan na het testrapport; start elke 30 s; rapporteert volgens de tik in RzMon). Logboek: journalctl -u rzmon-agent"
    ok "realtime-watcher rzmon-watch klaar (aan/uit per server in RzMon → Agent Manager)"
else
    sched="* * * * *"   # elke minuut; de agent rapporteert volgens de tik uit RzMon
    printf '# RzMon agent\n%s root set -a; . %s; %s >/dev/null 2>&1\n' "$sched" "$CONF" "$BIN" > "$CRON"
    chmod 644 "$CRON"
    for svc in crond cron; do
        if has_systemd && systemctl list-unit-files "$svc.service" >/dev/null 2>&1; then
            systemctl enable --now "$svc" >/dev/null 2>&1 && break
        fi
    done
    ok "cron: $CRON ($sched)"
fi

# Dit installatiescript bewaren voor later (bij "curl | bash" staat het nergens op schijf).
# Vanuit RzMon bijgewerkt: dit script is al door de agent gecontroleerd, dus dat bewaren.
# Vanaf een bestand gestart (het commando uit RzMon controleert dat bestand eerst): dat bewaren.
if [ -f "${BASH_SOURCE[0]:-}" ] && { [ -n "$RESULT_FILE" ] || [ -n "$ARG_PAIR" ]; } && [ "$(realpath "${BASH_SOURCE[0]}")" != "$SETUP" ]; then
    install -m 755 -o root -g root "${BASH_SOURCE[0]}" "$SETUP"
elif curl "${CURL_OPTS[@]}" "$URL/api.php?action=agent_install${DL_KEY:+&k=$DL_KEY}" -o "$tmp.setup" 2>/dev/null && grep -q 'RzMon agent' "$tmp.setup"; then
    install -m 755 -o root -g root "$tmp.setup" "$SETUP"
elif [ -f "${BASH_SOURCE[0]:-}" ] && [ "$(realpath "${BASH_SOURCE[0]}")" != "$SETUP" ]; then
    install -m 755 -o root -g root "${BASH_SOURCE[0]}" "$SETUP"
fi
rm -f "$tmp.setup"

# ----------------------------------------------------------------- 5. testrapport
step "5/5 Testrapport naar RzMon"
start_timer() { [ "${START_TIMER-}" = 1 ] && systemctl start rzmon-agent.timer >/dev/null 2>&1; return 0; }
# shellcheck disable=SC1090
if out=$(set -a; . "$CONF"; RZ_FORCE=1 bash "$BIN" 2>&1); then   # ook als de tik nog niet om is
    ok "RzMon antwoordt: $out"
    # Agent 1.68.0: nieuwe installatie: lege regels en de gebruikte koppelcode meteen weg (anders "overbodig" in RzMon)
    [ "${NEW_CONF-}" = 1 ] && cleanup_conf
    start_timer
else
    warn "Rapport mislukt: $out"
    echo
    # Meteen de volledige diagnose tonen
    # shellcheck disable=SC1090
    (set -a; . "$CONF"; bash "$BIN" --test) || true
    start_timer   # toch aan: lost het probleem zich op (bv. RzMon even weg), dan rapporteert de agent vanzelf
    die "De agent is geïnstalleerd, maar RzMon accepteert nog geen rapport. Los het bovenstaande op en test met: sudo rzmon-agent-setup --test"
fi

if [ -n "$RESULT_FILE" ]; then
    # Bijgewerkt vanuit RzMon: uitkomst voor de agent (die meldt hem bij het volgende rapport) en opruimen
    printf 'ok %s %s\n' "${old_version:-}" "${new_version:-?}" >"$RESULT_FILE"
    rm -f "$(dirname "$RESULT_FILE")/update.lock" "$SOURCE_FILE" "$(dirname "$RESULT_FILE")/setup.new"
    exit 0
fi

if [ "$TRUENAS" = 1 ]; then
    cat <<EOF

${C_OK}✔ De agent staat in $TN_DIR en rapporteert elke minuut aan ${URL}.${C_OFF}

  Volgende stap in RzMon: klik bij deze server op ＋ en voeg checks toe
  (CPU, RAM, Diskruimte: kies bv. /mnt/$(echo "$TN_DIR" | cut -d/ -f3), Service, Commando).

  Planning        : TrueNAS → System → Advanced → Cron Jobs → "$TN_CRON_DESC"
  Verbinding testen: bash $SETUP --test
  Agent bijwerken : knop in RzMon (Agent Manager → Bijwerken), of bash $SETUP --update
  Verwijderen     : bash $SETUP --uninstall

EOF
    exit 0
fi

cat <<EOF

${C_OK}✔ De agent is geïnstalleerd en rapporteert aan ${URL}.${C_OFF}
  Hoe vaak: in RzMon → Agent Manager (standaard elke 60 s).

  Volgende stap in RzMon: klik bij deze server op ＋ en voeg checks toe:
  CPU, RAM, Diskruimte (kies een mount), Service (kies uit de lijst van deze server;
  de agent bewaakt hem dan vanzelf) en SSL-certificaat (kies uit: ${CERTS:-–}).

  Status bekijken : $( [ "$use_systemd" -eq 1 ] && echo "systemctl status rzmon-agent.timer; journalctl -u rzmon-agent -n 20" || echo "grep rzmon /var/log/cron* 2>/dev/null" )
  Services         : kies je in RzMon (＋ Check → Service)
  Certificaten     : sudo rzmon-agent-setup --certs "/pad/cert.pem site.nl:443 …"
  Verbinding testen: sudo rzmon-agent-setup --test
  Agent bijwerken : knop in RzMon (Agent Manager → Bijwerken), of sudo rzmon-agent-setup --update
  Verwijderen     : sudo rzmon-agent-setup --uninstall

EOF
