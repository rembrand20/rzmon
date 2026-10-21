#!/usr/bin/env bash
# RzMon agent – rapporteert CPU, RAM, disk usage, luisterende poorten, service-status,
# vervaldatums van SSL-certificaten (bestanden en host:poort, ook op internet) en, als
# podman aanwezig is, de status en het verbruik van elke container en pod aan de monitor.
#
# Linux-server:
#   sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
#   Zet MONITOR_URL, TOKEN, SERVICES en CERTS in /etc/default/rzmon-agent (zie "Agent Manager" in de webinterface)
#   echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon-agent
#   Vereist: bash, curl, df, systemctl, ss (iproute2); voor certificaten: openssl
#
# Home Assistant OS / Supervised:
#   Draait als add-on (map ha-addon/rzmon_agent). Zodra SUPERVISOR_TOKEN bestaat schakelt de agent
#   over op HA-modus: disk via de Supervisor, en "services" zijn Home Assistant Core, de Supervisor,
#   elke add-on (addon:<slug>) en beschikbare updates. Vereist daarnaast: jq.
#
# TrueNAS SCALE: de agent staat op een pool (bv. /mnt/tank/rzmon-agent) en draait via een cronjob van
#   TrueNAS zelf; install-agent.sh regelt dat (zie HANDLEIDING.md §8.9).
#
# Verbindingstest:  sudo rzmon-agent.sh --test

# Versie: wordt bijgewerkt door tools/bump-version.sh (gelijk aan VERSION van RzMon)
AGENT_VERSION="1.40.0"

TEST=0
[ "${1-}" = "--test" ] && TEST=1
if [ "${1-}" = "--version" ]; then
    echo "rzmon-agent $AGENT_VERSION"
    exit 0
fi
# Voor het IDS (bestanden bewaken), op de achtergrond: alle bestanden uit de lijst opnieuw hashen.
# Uitvoer: pad <tab> grootte <tab> tijd <tab> sha256; grootte en tijd zoals in de lijst, zodat een
# uitkomst die inmiddels verouderd is (bestand sindsdien gewijzigd) herkend wordt.
if [ "${1-}" = "--fim-rehash" ] && [ -r "${2-}" ]; then
    while IFS=$'\t' read -r p s t _; do
        [ "$s" -le 104857600 ] 2>/dev/null || continue
        h=$(sha256sum -- "$p" 2>/dev/null) || continue
        printf '%s\t%s\t%s\t%s\n' "$p" "$s" "$t" "${h%% *}"
    done <"$2"
    exit 0
fi

# Met de hand gestart (zonder omgeving van systemd/cron)? Dan zelf de instellingen inlezen.
if [ -z "${MONITOR_URL-}" ] && [ -z "${SUPERVISOR_TOKEN-}" ] && [ -r /etc/default/rzmon-agent ]; then
    set -a
    # shellcheck disable=SC1091
    . /etc/default/rzmon-agent
    set +a
fi

MONITOR_URL="${MONITOR_URL:-http://rzmon.example.lan}"
TOKEN="${TOKEN:-VUL_HIER_HET_TOKEN_IN}"
# Welke services bewaakt worden, stel je in RzMon in (service-check toevoegen). SERVICES is verouderd,
# maar werkt nog: services in deze spatie-gescheiden lijst worden óók gemeld (niet gebruikt in HA-modus).
SERVICES="${SERVICES-}"
# INSECURE=1 accepteert een self-signed certificaat van RzMon
INSECURE="${INSECURE:-0}"
# Certificaten om te bewaken (spatie-gescheiden): bestanden (wildcards mogen) en/of host:poort,
# lokaal of op internet, bv. "/etc/letsencrypt/live/*/cert.pem localhost:8443 example.com:443".
# Niet ingesteld = alle Let's Encrypt-certificaten op deze server; leeg ("") = geen.
CERTS="${CERTS-/etc/letsencrypt/live/*/cert.pem}"
# Certificaten veranderen zelden: elk uur opnieuw uitlezen is genoeg (seconden)
CERT_INTERVAL="${CERT_INTERVAL:-3600}"
# Diepere controle van services: draait het proces, luistert het op zijn poorten, antwoordt het
# (HTTP, database, SSH, mail, DNS…), herstarts en fouten in het log. SERVICE_DEEP=0 zet het uit.
SERVICE_DEEP="${SERVICE_DEEP:-1}"
# Eigen tests: een uitvoerbaar script /etc/rzmon-agent/tests/<service> (van root, niet door anderen
# te wijzigen). Exitcode 0 = OK, 1 = WARNING, 2 = CRITICAL; de eerste regel uitvoer is het bericht.
SERVICE_TEST_DIR="${SERVICE_TEST_DIR:-/etc/rzmon-agent/tests}"
# Logbestanden doorzoeken (zoekopdrachten stel je in RzMon in): alleen bestanden in deze mappen
# mogen gelezen worden, zodat via RzMon niet zomaar elk bestand uitgelezen kan worden.
LOG_DIRS="${LOG_DIRS:-/var/log}"
# Podman: wordt alleen gemeld als podman op deze server staat. PODMAN=0 zet het uit.
PODMAN="${PODMAN:-1}"
# Gebruikers met rootless containers: "auto" = zelf zoeken (gebruikers met ~/.local/share/containers),
# of een lijst namen; "" = alleen de containers van root
PODMAN_USERS="${PODMAN_USERS-auto}"
# Bijwerken vanuit RzMon (knop "Agent bijwerken" of automatisch). REMOTE_UPDATE=0: alleen met de hand
# (sudo rzmon-agent-setup --update). De nieuwe versie komt altijd van je eigen RzMon en wordt eerst
# gecontroleerd (SHA-256, geldig script, juiste versie) voordat hij geïnstalleerd wordt.
REMOTE_UPDATE="${REMOTE_UPDATE:-1}"
# Installatiescript voor bijwerken vanuit RzMon (op TrueNAS staat het op de pool, naast de agent)
AGENT_SETUP="${AGENT_SETUP:-/usr/local/sbin/rzmon-agent-setup}"
# Commando-checks (＋ Check → Commando in RzMon): RzMon bepaalt of ze mogen en als welke gebruiker
# (Instellingen → Agents). Hier op de server kun je dat inperken: COMMANDS=0 = nooit uitvoeren,
# COMMANDS=nobody = nooit als root. Leeg = wat RzMon zegt.
COMMANDS="${COMMANDS-}"
# Logins (SSH en console), sudo en su melden op de pagina Meldingen, als dat in RzMon aan staat
# (Instellingen → Alarmgedrag). AUTH_EVENTS=0 zet het op deze server altijd uit.
AUTH_EVENTS="${AUTH_EVENTS-}"
# IDS (pagina 🛡️ IDS in RzMon): IDS_BLOCK=0 = op deze server nooit blokkeren, ook als RzMon op
# "blokkeren" staat (detecteren gaat door).
IDS_BLOCK="${IDS_BLOCK-}"
# Zonder journal (of met AUTH_SOURCE=file): deze logbestanden, de eerste die bestaat
AUTH_LOGS="${AUTH_LOGS:-/var/log/secure /var/log/auth.log}"
SUPERVISOR_URL="${SUPERVISOR_URL:-http://supervisor}"

HA_MODE=0
[ -n "${SUPERVISOR_TOKEN:-}" ] && HA_MODE=1

json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
# Willekeurige tekst (stdin, bv. uitvoer van een commando) als JSON-tekst: geldige UTF-8, tabs en
# regeleindes als \t en \n, andere stuurtekens weg
json_text() {
    { if command -v iconv >/dev/null 2>&1; then iconv -f UTF-8 -t UTF-8 -c 2>/dev/null; else LC_ALL=C tr -cd '\11\12\15\40-\176'; fi; } |
        sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/\\t/g' | LC_ALL=C tr -d '\000-\010\013-\037' | awk 'NR > 1 { printf "\\n" } { printf "%s", $0 }'
}

# ---------------------------------------------------------------- Verbindingstest (--test)
pass() { printf '  \e[32m✔\e[0m %s\n' "$*"; }
fail() { printf '  \e[31m✘\e[0m %s\n' "$*"; TEST_FAILED=1; }
hint() { printf '      → %s\n' "$*"; }
note() { printf '  \e[33m⚠\e[0m %s\n' "$*"; }
# Harde fout: volgende stappen hebben geen zin
stop() { echo; echo "  Los dit eerst op en test opnieuw; de volgende stappen zijn overgeslagen."; exit 1; }
TEST_FAILED=0

if [ "$TEST" = 1 ]; then
    base=${MONITOR_URL%/}
    echo "RzMon agent $AGENT_VERSION – verbindingstest"
    echo "  RzMon-adres : $base"
    echo "  Token       : ${TOKEN:0:6}…${TOKEN: -4}"
    echo

    # 1. Instellingen
    if [ "$base" = "http://rzmon.example.lan" ] || [ "$TOKEN" = "VUL_HIER_HET_TOKEN_IN" ]; then
        fail "Geen instellingen gevonden (adres/token zijn nog de voorbeeldwaarden)"
        hint "Staan ze in /etc/default/rzmon-agent? Draai de test met sudo (het bestand is alleen leesbaar voor root)."
        exit 1
    fi
    pass "Instellingen gevonden"

    # 2. Adres ontleden
    if [[ ! "$base" =~ ^(https?)://([^/:]+)(:([0-9]+))?(/.*)?$ ]]; then
        fail "Ongeldig adres: $base"; exit 1
    fi
    proto=${BASH_REMATCH[1]}; host=${BASH_REMATCH[2]}; port=${BASH_REMATCH[4]}
    [ -n "$port" ] || { [ "$proto" = https ] && port=443 || port=80; }
    case "$host" in
        localhost|127.*|::1) note "Het adres wijst naar deze server zelf ($host): alleen goed als RzMon óp deze server draait"
            hint "Anders: gebruik het IP-adres of de naam van de RzMon-server, bv. http://192.168.1.10" ;;
    esac

    # 3. DNS
    if [[ "$host" =~ ^[0-9.]+$ ]] || getent hosts "$host" >/dev/null 2>&1; then
        pass "Naam opzoeken: $host → $(getent hosts "$host" 2>/dev/null | awk '{print $1; exit}' || echo "$host")"
    else
        fail "De naam '$host' kan niet worden opgezocht (DNS)"
        hint "Gebruik het IP-adres van RzMon, of zet de naam in /etc/hosts"
        stop
    fi

    # 4. Netwerk: kan deze server de poort bereiken?
    if timeout 5 bash -c "exec 3<>/dev/tcp/$host/$port" 2>/dev/null; then
        pass "Verbinding met $host poort $port"
    else
        fail "Geen verbinding met $host poort $port"
        hint "Draait de webserver op RzMon? (systemctl status httpd/apache2/nginx)"
        hint "Firewall op de RzMon-server? Rocky: sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload"
        hint "Zitten deze server en RzMon in hetzelfde netwerk / mag het verkeer ertussen (VLAN, router)?"
        stop
    fi
    if [ -n "${http_proxy:-}${HTTP_PROXY:-}${https_proxy:-}${HTTPS_PROXY:-}" ]; then
        hint "Let op: er is een proxy ingesteld (http_proxy/https_proxy). Zet RzMon zo nodig in no_proxy."
    fi

    curl_t=(-sS -m 15 -o /tmp/rzmon-test.$$ -w '%{http_code} %{redirect_url}')
    [ "$INSECURE" = 1 ] && curl_t+=(-k)

    # 5. Draait daar RzMon?
    read -r code redirect < <(curl "${curl_t[@]}" "$base/api.php?action=ping" 2>/tmp/rzmon-test-err.$$ || true)
    body=$(cat /tmp/rzmon-test.$$ 2>/dev/null); err=$(cat /tmp/rzmon-test-err.$$ 2>/dev/null)
    old_rzmon=0
    if [ "$code" = 200 ] && [[ "$body" == *'"app":"RzMon"'* ]]; then
        rz_version=$(sed -n 's/.*"version":"\([^"]*\)".*/\1/p' <<<"$body")
        pass "RzMon ${rz_version:+$rz_version }gevonden op $base"
        if [ -n "$rz_version" ] && [ "$rz_version" != "$AGENT_VERSION" ]; then
            hint "Agent is versie $AGENT_VERSION, RzMon $rz_version: bijwerken met  sudo rzmon-agent-setup --update"
        fi
    elif [ "$code" = 401 ] && [[ "$body" == *'"error"'* ]]; then
        pass "RzMon gevonden op $base (oudere versie: werk RzMon bij voor een volledige test)"
        old_rzmon=1
    elif [[ "$code" =~ ^30[1278]$ ]]; then
        fail "RzMon stuurt door naar: $redirect"
        hint "Gebruik dat adres als MONITOR_URL (bv. https:// i.p.v. http://): sudo rzmon-agent-setup --url ${redirect%/api.php*}"
    elif [ "$code" = 404 ]; then
        fail "Er draait wel een webserver op $base, maar niet RzMon (404)"
        hint "Klopt het pad? RzMon staat op de hoofd-URL van de vhost, bv. http://192.168.1.10/ — niet /rzmon/public/"
    elif [[ "$err" == *SSL* || "$err" == *certificate* ]]; then
        fail "Certificaatprobleem: ${err#curl: }"
        hint "Self-signed certificaat? Zet INSECURE=\"1\" in /etc/default/rzmon-agent (of: sudo rzmon-agent-setup --insecure)"
    elif [ "$code" = 000 ]; then
        fail "Geen antwoord van $base: ${err#curl: }"
    else
        fail "Onverwacht antwoord van $base (HTTP $code)"
        [ -n "$body" ] && hint "${body:0:200}"
    fi
    if [ "$TEST_FAILED" = 1 ]; then rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$; stop; fi

    # 6. Token
    curl_k=(-sS -m 15); [ "$INSECURE" = 1 ] && curl_k+=(-k)
    tk=$(curl "${curl_k[@]}" -H "X-Agent-Token: $TOKEN" -w '\n%{http_code}' "$base/api.php?action=agent_check" 2>/dev/null || true)
    tk_code=${tk##*$'\n'}; tk_body=${tk%$'\n'*}
    if [ "$tk_code" = 200 ]; then
        pass "Token geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzMon ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
    elif [ "$tk_code" = 403 ]; then
        fail "Token geweigerd: $(sed -n 's/.*"reason":"\([^"]*\)".*/\1/p' <<<"$tk_body")"
        hint "Kopieer het commando opnieuw uit RzMon (server → Agent Manager) en voer het uit"
        rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
        stop
    elif [ "$old_rzmon" = 1 ]; then
        hint "Tokencontrole overgeslagen (oudere RzMon-versie); het proefrapport hieronder test het token wel"
    else
        fail "Tokencontrole gaf HTTP $tk_code: ${tk_body:0:150}"
    fi
    rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
fi

# Supervisor API (alleen HA-modus)
sv() { curl -fsS -m 10 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SUPERVISOR_URL$1" 2>/dev/null; }

# ---------------------------------------------------------------- Disk
disks=""
hostname=$(hostname)
if [ "$HA_MODE" = 1 ]; then
    # In de add-on-container is df onbruikbaar (bind mounts); de Supervisor kent de data-partitie (GB)
    host_info=$(sv /host/info)
    if [ -n "$host_info" ]; then
        hostname=$(jq -r '.data.hostname // empty' <<<"$host_info")
        disks=$(jq -r '.data | select(.disk_total != null and .disk_total > 0)
            | "{\"mount\":\"/data\",\"total\":\(.disk_total * 1073741824 | floor),\"avail\":\(.disk_free * 1073741824 | floor)},"' <<<"$host_info")
    fi
else
    while read -r _fs total _used avail _pct mount; do
        [ -z "$mount" ] && continue
        disks+="{\"mount\":\"$(json_escape "$mount")\",\"total\":$total,\"avail\":$avail},"
    done < <(df -P -B1 -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs 2>/dev/null | tail -n +2)
fi

# ---------------------------------------------------------------- Podman: voorbereiding
# Contexten: "root" (of de huidige gebruiker) plus gebruikers met rootless containers
podman_ctx=()
if [ "$PODMAN" != 0 ] && [ "$HA_MODE" = 0 ] && command -v podman >/dev/null 2>&1; then
    me=$(id -un)
    podman_ctx=("$( [ "$(id -u)" = 0 ] && echo root || echo "$me")")
    if [ "$(id -u)" = 0 ] && [ -n "$PODMAN_USERS" ]; then
        if [ "$PODMAN_USERS" = auto ]; then
            while IFS=: read -r u _ uid _ _ home _; do
                # Alleen gebruikers die echt containers hebben (een lege podman-map telt niet):
                # elke container heeft een map <driver>-containers/<id>/userdata (overlay, vfs, btrfs…)
                [ "$uid" -ge 1000 ] && [ "$uid" -lt 65534 ] \
                    && compgen -G "$home/.local/share/containers/storage/*-containers/*/userdata" >/dev/null && podman_ctx+=("$u")
            done < <(getent passwd 2>/dev/null)
        else
            for u in $PODMAN_USERS; do id -u "$u" >/dev/null 2>&1 && podman_ctx+=("$u"); done
        fi
    fi
fi
pm() {  # pm <context> <podman-argumenten…>: podman als root, of als die gebruiker (rootless)
    local ctx=$1 uid
    shift
    if [ "$ctx" = root ] || [ "$ctx" = "$(id -un)" ]; then
        timeout 60 podman "$@"
    else
        uid=$(id -u "$ctx")
        (cd / && if [ -d "/run/user/$uid" ]; then export XDG_RUNTIME_DIR="/run/user/$uid"; else unset XDG_RUNTIME_DIR; fi
         timeout 60 runuser -u "$ctx" -- podman "$@")
    fi
}
# Ruwe tellers per draaiende container; twee metingen → actueel CPU-gebruik (zoals podman stats).
# Netwerk via {{.NetIO}} ("27.2MB / 25.1MB"): podman 5 heeft NetInput/NetOutput weggehaald, en een
# onbekend veld laat podman na de eerste container stoppen (dan kregen alleen die cijfers).
podman_stats() {
    local ctx
    for ctx in "${podman_ctx[@]}"; do
        pm "$ctx" stats --no-stream --no-trunc --format '{{.ContainerStats.ContainerID}}|{{.ContainerStats.CPUNano}}|{{.ContainerStats.SystemNano}}|{{.ContainerStats.MemUsage}}|{{.ContainerStats.MemLimit}}|{{.NetIO}}|{{.ContainerStats.BlockInput}}|{{.ContainerStats.BlockOutput}}|{{.ContainerStats.PIDs}}' 2>/dev/null \
            | sed "s/^/S|$ctx|/"
    done
}
pod_stats_a=""
[ ${#podman_ctx[@]} -gt 0 ] && pod_stats_a=$(podman_stats)

# ---------------------------------------------------------------- CPU
# CPU-gebruik over 2 seconden uit /proc/stat (idle = idle + iowait)
read -r _ u1 n1 s1 i1 w1 q1 sq1 st1 _ < /proc/stat
sleep 2
read -r _ u2 n2 s2 i2 w2 q2 sq2 st2 _ < /proc/stat
t1=$((u1+n1+s1+i1+w1+q1+sq1+${st1:-0})); t2=$((u2+n2+s2+i2+w2+q2+sq2+${st2:-0}))
idle=$(( (i2+w2) - (i1+w1) )); total=$(( t2 - t1 ))
cpu_pct=$(awk -v i="$idle" -v t="$total" 'BEGIN { printf "%.1f", (t > 0 ? (1 - i / t) * 100 : 0) }')
read -r l1 l5 l15 _ < /proc/loadavg
cores=$(grep -c '^cpu[0-9]' /proc/stat)

# ---------------------------------------------------------------- RAM
# Geheugen uit /proc/meminfo (kB -> bytes)
mem=$(awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ { v[$1] = $2 * 1024 }
    END { printf "{\"total\":%.0f,\"avail\":%.0f,\"swap_total\":%.0f,\"swap_free\":%.0f}",
          v["MemTotal:"], v["MemAvailable:"], v["SwapTotal:"], v["SwapFree:"] }' /proc/meminfo)

# ---------------------------------------------------------------- Poorten
# Luisterende TCP-poorten (procesnamen alleen zichtbaar als root, niet in de HA-add-on)
ports=""
if command -v ss >/dev/null 2>&1; then
    ports=$(ss -Htlnp 2>/dev/null | awk '{
        local = $4; port = local; sub(/.*:/, "", port); addr = local; sub(/:[^:]*$/, "", addr)
        gsub(/[\[\]"]/, "", addr); proc = ""
        if (match($0, /users:\(\("[^"]+"/)) { proc = substr($0, RSTART + 9, RLENGTH - 10) }
        key = port "|" addr
        if (port ~ /^[0-9]+$/ && !(key in seen)) {
            seen[key] = 1
            printf "{\"port\":%s,\"addr\":\"%s\",\"proc\":\"%s\"},", port, addr, proc
        }
    }')
fi

# Wat in RzMon is ingesteld: welke services bewaakt worden (service-checks), extra tests (URL/poort),
# de eigen poorten of sockets van een service en de logzoekopdrachten. De lijst wordt elke 10 minuten
# bij RzMon opgehaald, en meteen als RzMon meldt dat er iets veranderd is (tests_rev in het antwoord).
# Commando's van RzMon worden alleen uitgevoerd bij commando-checks, als RzMon ze voor deze server toestaat
# en COMMANDS (hierboven) dat niet blokkeert; zie "Commando-checks" verderop.
declare -A manual_ports=()
rz_services=""
if [ "$HA_MODE" = 0 ]; then
    t_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    mkdir -p "$t_dir" 2>/dev/null && [ -w "$t_dir" ] || t_dir="${TMPDIR:-/tmp}"
    t_cache="$t_dir/tests.cache"
    if [ "$TEST" = 1 ] || [ ! -r "$t_cache" ] || [ $(( $(date +%s) - $(stat -c %Y "$t_cache") )) -ge 600 ]; then
        t_k=(); [ "$INSECURE" = 1 ] && t_k=(-k)
        if t_list=$(curl -fsS "${t_k[@]}" -m 10 -H "X-Agent-Token: $TOKEN" "${MONITOR_URL%/}/api.php?action=agent_tests" 2>/dev/null); then
            (umask 077; printf '%s\n' "$t_list" >"$t_cache") 2>/dev/null || true
        fi
    fi
    # Regels "id <tab> ports <tab> service <tab> 8080 127.0.0.1:9000 /run/x.sock" en "id <tab> svc <tab> service"
    [ -r "$t_cache" ] && while IFS=$'\t' read -r _ t_type t_target t_expect; do
        [ "$t_type" = ports ] && manual_ports["$t_target"]+=" $t_expect"
        [ "$t_type" = svc ] && [[ "$t_target" =~ ^[A-Za-z0-9@._:-]+$ ]] && rz_services+=" $t_target"
    done <"$t_cache"
fi
# Te bewaken services: die met een service-check in RzMon, plus (verouderd) SERVICES uit de instellingen
svc_all=$(printf '%s\n' $SERVICES $rz_services | awk 'NF && !seen[$0]++' | tr '\n' ' ')

# ---------------------------------------------------------------- Services
services=""
if [ "$HA_MODE" = 1 ]; then
    core_state=failed
    sv /core/api/ >/dev/null && core_state=active
    services+="\"homeassistant\":\"$core_state\","

    sup_info=$(sv /supervisor/info)
    [ -z "$sup_info" ] && sup_info='{}'
    sup_state=$(jq -r 'if .data.healthy == false then "unhealthy" else "active" end' <<<"$sup_info" 2>/dev/null)
    services+="\"supervisor\":\"${sup_state:-unknown}\","

    addons=$(sv /addons)
    if [ -n "$addons" ]; then
        services+=$(jq -r '.data.addons[]
            | "\(("addon:" + .slug) | tojson):\((if .state == "started" then "active" else (.state // "unknown") end) | tojson),"' \
            <<<"$addons" | tr -d '\n')
    fi

    # Beschikbare updates (Core, Supervisor, OS en add-ons) → WARNING in RzMon
    updates=$( {
        sv /core/info | jq -r 'select(.data.update_available) | "Core \(.data.version_latest)"'
        jq -r 'select(.data.update_available) | "Supervisor"' <<<"$sup_info"
        sv /os/info | jq -r 'select(.data.update_available) | "OS \(.data.version_latest)"'
        [ -n "$addons" ] && jq -r '.data.addons[] | select(.update_available) | .name' <<<"$addons"
    } 2>/dev/null | paste -sd, - | sed 's/,/, /g')
    if [ -n "$updates" ]; then
        services+="\"updates\":\"$(json_escape "update-available: $updates")\","
    else
        services+="\"updates\":\"active\","
    fi
else
    for s in $svc_all; do
        state=$(systemctl is-active "$s" 2>/dev/null)
        services+="\"$(json_escape "$s")\":\"$(json_escape "${state:-unknown}")\","
    done
fi

# Overzicht van de services op deze server (aan bij het opstarten, of draaiend), om in RzMon uit te kiezen.
# Formaat: [naam, status, enabled]
svc_list=""
if [ "$HA_MODE" = 0 ] && command -v systemctl >/dev/null 2>&1; then
    svc_list=$({ systemctl list-unit-files --type=service --no-legend --no-pager 2>/dev/null | awk '{ print "F", $1, $2 }'
                 systemctl list-units --type=service --all --no-legend --no-pager --plain 2>/dev/null | awk '{ print "U", $1, $3 }'; } |
        awk '$2 ~ /\.service$/ && $2 !~ /@\.service$/ { n = $2; sub(/\.service$/, "", n)
                 if (n !~ /^[A-Za-z0-9@._:-]+$/) next   # bv. systemd-fsck@dev-disk-by\x2duuid…: backslash breekt de JSON
                 if ($1 == "F") en[n] = $3; else st[n] = $3; names[n] = 1 }
             END { for (n in names) if (en[n] == "enabled" || st[n] == "active" || (st[n] == "failed" && en[n] != "disabled"))
                       printf "[\"%s\",\"%s\",%d],", n, (n in st ? st[n] : "inactive"), en[n] == "enabled" }' 2>/dev/null)
fi
# Home Assistant: Core, Supervisor, add-ons en updates als lijst om uit te kiezen
if [ "$HA_MODE" = 1 ] && [ -n "$services" ]; then
    svc_list=$(jq -r 'to_entries[] | "[\(.key | tojson),\((if .value == "active" then "active" elif (.value | test("update-available")) then "update" else .value end) | tojson),1],"' \
        <<<"{${services%,}}" 2>/dev/null | tr -d '\n')
fi

# ---------------------------------------------------------------- Home Assistant: back-up, integraties, systeemstatus
# Elke 5 minuten opnieuw opgehaald (de lijst met entiteiten kan groot zijn). Alleen lezen.
ha_json=""
if [ "$HA_MODE" = 1 ]; then
    ha_cache="${TMPDIR:-/tmp}/rzmon-ha.json"
    if [ "$TEST" != 1 ] && [ -s "$ha_cache" ] && [ $(( $(date +%s) - $(stat -c %Y "$ha_cache") )) -lt 300 ]; then
        ha_json=$(cat "$ha_cache")
    else
        ha_get() {  # ha_get <pad> → body; exitcode 0 alleen bij HTTP 200
            local out
            out=$(curl -sS -m 20 -H "Authorization: Bearer $SUPERVISOR_TOKEN" -w '\n%{http_code}' "$SUPERVISOR_URL$1" 2>&1) || { echo "${out##*$'\n'}"; return 1; }
            [ "${out##*$'\n'}" = 200 ] || { echo "HTTP ${out##*$'\n'}"; return 1; }
            printf '%s' "${out%$'\n'*}"
        }
        now_s=$(date +%s)
        # 1. Back-up: de sensoren van de backup-integratie (HA 2025.2+). Tijdstempels in het verleden zijn de
        #    laatste geslaagde en de laatste poging (naam met "succes"/"erfolg" = geslaagd), in de toekomst de volgende.
        if states=$(ha_get /core/api/states); then
            ha_backup=$(jq -c --argjson now "$now_s" '
                def ts: sub("\\.[0-9]+"; "") | sub("[+]00:00$"; "Z") | (try fromdateiso8601 catch null);
                [.[] | select(.entity_id | startswith("sensor.backup_"))] as $b
                | [$b[] | select(.attributes.device_class == "timestamp") | {name: (.attributes.friendly_name // .entity_id), t: (.state | ts)}
                   | select(.t != null)] as $ts
                | [$ts[] | select(.t <= $now)] as $past
                | ([$past[] | select(.name | test("succes|erfolg|réussi"; "i"))] | max_by(.t) // null) as $ok
                | {found: ($b | length > 0),
                   last_ok: (if $ok then $ok.t elif ($past | length) == 1 then $past[0].t else ($past | min_by(.t) | .t?) end),
                   last_try: ($past | max_by(.t) | .t?),
                   next: ([$ts[] | select(.t > $now)] | min_by(.t) | .t?)}' <<<"$states" 2>/dev/null)
        else
            ha_backup="{\"error\":\"$(json_escape "Home Assistant-API: $states")\"}"
        fi
        # 2. Integraties die niet geladen zijn (uitgeschakelde tellen niet mee)
        if entries=$(ha_get /core/api/config/config_entries/entry); then
            ha_integr=$(jq -c '[.[] | select(.disabled_by == null)] as $all
                | {total: ($all | length), failed: [$all[] | select(.state == "setup_error" or .state == "setup_retry" or .state == "failed_unload")
                   | {domain, title, state, reason: (.reason // null)}]}' <<<"$entries" 2>/dev/null)
        else
            ha_integr="{\"error\":\"$(json_escape "integratielijst niet beschikbaar voor de add-on ($entries)")\"}"
        fi
        # 4. Systeemstatus van de Supervisor: unhealthy, unsupported en openstaande meldingen
        if resol=$(ha_get /resolution/info); then
            ha_repair=$(jq -c '.data | {unhealthy: (.unhealthy // []), unsupported: (.unsupported // []),
                issues: [(.issues // [])[] | {type, context, reference}]}' <<<"$resol" 2>/dev/null)
        else
            ha_repair="{\"error\":\"$(json_escape "Supervisor: $resol")\"}"
        fi
        ha_json="{\"backup\":${ha_backup:-null},\"integrations\":${ha_integr:-null},\"repairs\":${ha_repair:-null}}"
        (umask 077; printf '%s' "$ha_json" >"$ha_cache") 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------- Services: diepere controle
# Per service: systemd-details, de poorten van zijn processen (getest op deze server), een test
# die bij de dienst past, fouten in het log van het laatste kwartier en een eventuele eigen test.
svc_detail=""
tcp_open() {  # tcp_open adres poort → 0 als er een verbinding mogelijk is
    # shellcheck disable=SC2016
    timeout 3 bash -c 'exec 3<>"/dev/tcp/$0/$1"' "$1" "$2" 2>/dev/null
}
tcp_banner() {  # tcp_banner adres poort [tekst om te sturen] → eerste regel van het antwoord
    # Eén regel lezen (read -t): veel servers houden de verbinding daarna open
    # shellcheck disable=SC2016
    timeout 5 bash -c 'exec 3<>"/dev/tcp/$0/$1" || exit 1
        [ -n "$2" ] && printf "$2" >&3
        IFS= read -r -t 3 line <&3; printf "%s" "$line"' "$1" "$2" "${3-}" 2>/dev/null | tr -d '\r'
}
probe_service() {  # probe_service naam "poort|adres …" → zet probe, probe_rc (0/1/2) en probe_msg
    local name=${1%.service} list=$2 port addr out t code first
    probe=""; probe_rc=""; probe_msg=""
    first=${list%% *}; port=${first%%|*}; addr=${first#*|}
    case "$name" in
        nginx*|httpd*|apache2*|caddy*|lighttpd*|traefik*|haproxy*)
            probe=http
            for pa in $list; do case "${pa%%|*}" in 80|8080|443|8443) port=${pa%%|*}; addr=${pa#*|}; break ;; esac; done
            [ -n "$port" ] || { probe_rc=2; probe_msg="luistert op geen enkele poort"; return; }
            local proto=http; [[ "$port" == *443 ]] && proto=https
            out=$(curl -sk -o /dev/null -m 5 -w '%{http_code} %{time_total}' "$proto://$addr:$port/" 2>/dev/null)
            code=${out%% *}; t=$(awk -v s="${out#* }" 'BEGIN { printf "%d", s * 1000 }')
            if [ -z "$code" ] || [ "$code" = 000 ]; then probe_rc=2; probe_msg="geen HTTP-antwoord op $proto://$addr:$port/"
            elif [ "$code" -ge 500 ]; then probe_rc=1; probe_msg="HTTP $code in $t ms (serverfout)"
            else probe_rc=0; probe_msg="HTTP $code in $t ms"; fi ;;
        mariadb*|mysql*)
            probe=database
            local admin; admin=$(command -v mariadb-admin || command -v mysqladmin)
            if [ -n "$admin" ]; then
                # "mysqld is alive" ook als de login geweigerd wordt: de server antwoordt dan wel
                if "$admin" --connect-timeout=5 ping >/dev/null 2>&1; then probe_rc=0; probe_msg="database antwoordt (ping)"
                else probe_rc=2; probe_msg="database antwoordt niet op ping"; fi
            elif [ -n "$port" ]; then
                if [ -n "$(tcp_banner "$addr" "$port")" ]; then probe_rc=0; probe_msg="database geeft zijn begroeting"; else probe_rc=2; probe_msg="geen begroeting op poort $port"; fi
            fi ;;
        postgresql*)
            probe=database
            if command -v pg_isready >/dev/null 2>&1; then
                out=$(pg_isready -t 5 2>&1); code=$?
                case $code in 0) probe_rc=0; probe_msg="database neemt verbindingen aan" ;; 1) probe_rc=1; probe_msg="database start op of weigert: $out" ;; *) probe_rc=2; probe_msg="database antwoordt niet" ;; esac
            fi ;;
        redis*|valkey*)
            probe=redis
            [ -n "$port" ] || return
            out=$(tcp_banner "$addr" "$port" 'PING\r\n')
            case "$out" in +PONG*|-NOAUTH*) probe_rc=0; probe_msg="antwoordt op PING" ;; *) probe_rc=2; probe_msg="geen antwoord op PING" ;; esac ;;
        ssh|sshd)
            probe=ssh
            [ -n "$port" ] || return
            out=$(tcp_banner "$addr" "$port")
            if [[ "$out" == SSH-* ]]; then probe_rc=0; probe_msg="${out:0:40}"; else probe_rc=2; probe_msg="geen SSH-begroeting op poort $port"; fi ;;
        postfix*|exim*|sendmail*|opensmtpd*)
            probe=smtp
            for pa in $list; do case "${pa%%|*}" in 25|587) port=${pa%%|*}; addr=${pa#*|}; break ;; esac; done
            [ -n "$port" ] || return
            out=$(tcp_banner "$addr" "$port")
            if [[ "$out" == 220* ]]; then probe_rc=0; probe_msg="mailserver antwoordt (SMTP 220)"; else probe_rc=2; probe_msg="geen SMTP-begroeting op poort $port"; fi ;;
        dovecot*)
            probe=imap
            for pa in $list; do [ "${pa%%|*}" = 143 ] && { port=143; addr=${pa#*|}; break; }; done
            [ "$port" = 143 ] || return
            out=$(tcp_banner "$addr" 143)
            if [[ "$out" == "* OK"* ]]; then probe_rc=0; probe_msg="IMAP antwoordt"; else probe_rc=2; probe_msg="geen IMAP-begroeting"; fi ;;
        named|bind9|unbound|pihole-FTL|dnsmasq|knot*|pdns*|coredns*)
            probe=dns
            for pa in $list; do [ "${pa%%|*}" = 53 ] && { port=53; addr=${pa#*|}; break; }; done
            [ -n "$port" ] && command -v dig >/dev/null 2>&1 || return
            if out=$(dig +time=2 +tries=1 @"$addr" -p "$port" . NS 2>&1) && [[ "$out" == *"status:"* ]]; then
                probe_rc=0; probe_msg="DNS antwoordt ($(grep -o 'status: [A-Z]*' <<<"$out" | head -1))"
            else probe_rc=2; probe_msg="DNS geeft geen antwoord"; fi ;;
        chronyd|chrony)
            probe=ntp
            command -v chronyc >/dev/null 2>&1 || return
            if chronyc -n tracking 2>/dev/null | grep -q 'Leap status *: Normal'; then probe_rc=0; probe_msg="tijd is gesynchroniseerd"
            else probe_rc=1; probe_msg="tijd is niet gesynchroniseerd"; fi ;;
        systemd-timesyncd)
            probe=ntp
            if [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ]; then probe_rc=0; probe_msg="tijd is gesynchroniseerd"
            else probe_rc=1; probe_msg="tijd is niet gesynchroniseerd"; fi ;;
    esac
}
sock_open() {  # sock_open pad → 0 = neemt verbindingen aan, 1 = niet, 2 = geen antwoord binnen 3 s
    [ -S "$1" ] || return 1
    curl -s -m 3 --unix-socket "$1" -o /dev/null http://localhost/ 2>/dev/null
    case $? in 7) return 1 ;; 28) return 2 ;; *) return 0 ;; esac   # 7 = geen verbinding; overige = verbonden
}

if [ "$HA_MODE" = 0 ] && [ "$SERVICE_DEEP" != 0 ] && [ -n "${svc_all// /}" ] && command -v systemctl >/dev/null 2>&1; then
    # Alle luisterende TCP-poorten met de bijbehorende PID's: "poort|adres|pid pid …"
    listen_map=""
    command -v ss >/dev/null 2>&1 && listen_map=$(ss -Htlnp 2>/dev/null | awk '{
        local = $4; port = local; sub(/.*:/, "", port); addr = local; sub(/:[^:]*$/, "", addr); gsub(/[\[\]]/, "", addr); sub(/%.*/, "", addr)
        pids = ""; s = $0
        while (match(s, /pid=[0-9]+/)) { pids = pids " " substr(s, RSTART + 4, RLENGTH - 4); s = substr(s, RSTART + RLENGTH) }
        print port "|" addr "|" pids }')
    read -r up_s _ < /proc/uptime
    for s in $svc_all; do
        info=$(systemctl show -p ActiveState -p SubState -p Type -p MainPID -p NRestarts -p ActiveEnterTimestampMonotonic -p ControlGroup "$s" 2>/dev/null)
        get() { sed -n "s/^$1=//p" <<<"$info"; }
        [ "$(get ActiveState)" = active ] || { svc_detail+="\"$(json_escape "$s")\":{\"sub\":\"$(json_escape "$(get SubState)")\"},"; continue; }
        main=$(get MainPID); cg=$(get ControlGroup); mono=$(get ActiveEnterTimestampMonotonic)
        since=null
        [[ "$mono" =~ ^[0-9]+$ ]] && [ "$mono" -gt 0 ] && since=$(awk -v u="$up_s" -v m="$mono" -v n="$(date +%s)" 'BEGIN { printf "%d", n - (u - m / 1e6) }')
        # PID's van de service: zijn cgroup, of anders het hoofdproces met zijn kinderen
        pids=""
        for base in /sys/fs/cgroup /sys/fs/cgroup/systemd /sys/fs/cgroup/unified; do
            if [ -n "$cg" ] && [ -d "$base$cg" ]; then pids=$(find "$base$cg" -name cgroup.procs -exec cat {} + 2>/dev/null | tr '\n' ' '); break; fi
        done
        [ -z "$pids" ] && [[ "$main" =~ ^[1-9][0-9]*$ ]] && pids="$main $(ps -o pid= --ppid "$main" 2>/dev/null | tr '\n' ' ')"
        # Poorten van die PID's; testen op het adres waarop hij luistert (0.0.0.0/:: → 127.0.0.1)
        svc_ports=""; plist=""; pfail=""
        if [ -n "$pids" ] && [ -n "$listen_map" ]; then
            while IFS='|' read -r port addr lpids; do
                for pid in $lpids; do
                    if [[ " $pids " == *" $pid "* ]]; then
                        [[ " $svc_ports " == *" $port "* ]] && break
                        case "$addr" in ''|'*'|0.0.0.0|::) taddr=127.0.0.1 ;; *) taddr=$addr ;; esac
                        svc_ports+=" $port"; plist+=" $port|$taddr"
                        if ! tcp_open "$taddr" "$port" && ! { [ "$addr" = :: ] && tcp_open ::1 "$port"; }; then pfail+="$port,"; fi
                        break
                    fi
                done
            done <<<"$listen_map"
        fi
        # Zelf opgegeven poorten en sockets (in RzMon): altijd testen, en de dienst-test gebruikt ze
        man_list=""; man_show=""; sockets=""; sfail=""
        for m in ${manual_ports[$s]-}; do
            if [[ "$m" == /* ]]; then
                sockets+="\"$(json_escape "$m")\","
                sock_open "$m"; case $? in
                    0) ;;
                    2) sfail+="\"$(json_escape "$m: reageert niet binnen 3 seconden")\"," ;;
                    *) if [ -S "$m" ]; then sfail+="\"$(json_escape "$m: neemt geen verbindingen aan")\","; else sfail+="\"$(json_escape "$m: bestaat niet")\","; fi ;;
                esac
                continue
            fi
            m=${m#[}; mport=${m##*:}; maddr=127.0.0.1
            [[ "$m" == *:* ]] && { maddr=${m%:*}; maddr=${maddr%]}; }
            [[ "$mport" =~ ^[0-9]+$ ]] || continue
            man_list+=" $mport|$maddr"; man_show+="\"$(json_escape "$m")\","
            [[ " $svc_ports " == *" $mport "* ]] || svc_ports+=" $mport"
            tcp_open "$maddr" "$mport" || { [[ ",$pfail" == *",$mport,"* ]] || pfail+="$mport,"; }
        done
        probe_service "$s" "$( [ -n "$man_list" ] && echo "${man_list# }" || echo "${plist# }")"
        # Fouten in het log van het laatste kwartier
        log_n=null; log_last=""
        if command -v journalctl >/dev/null 2>&1; then
            log=$(journalctl -u "$s" -p err --since "-15 min" -q --no-pager -o cat 2>/dev/null)
            log_n=$(grep -c . <<<"$log"); log_last=$(tail -1 <<<"$log" | cut -c1-160)
        fi
        # Eigen test: alleen van root en niet door anderen te wijzigen (anders genegeerd)
        c_rc=null; c_msg=""
        f="$SERVICE_TEST_DIR/${s%.service}"
        if [ -f "$f" ] && [ -x "$f" ]; then
            if [ "$(stat -c '%u' "$f")" = 0 ] && ! find "$f" -perm /022 | grep -q .; then
                c_out=$(timeout 20 "$f" 2>&1); c_rc=$?
                c_msg=$(head -1 <<<"$c_out" | cut -c1-160)
                [ "$c_rc" = 124 ] && { c_rc=2; c_msg="eigen test duurde langer dan 20 seconden"; }
            else
                c_rc=1; c_msg="eigen test $f genegeerd: moet van root zijn en mag niet door anderen te wijzigen zijn"
            fi
        fi
        ports_json=$(tr ' ' '\n' <<<"${svc_ports# }" | grep . | paste -sd, -)
        svc_detail+="\"$(json_escape "$s")\":{\"sub\":\"$(json_escape "$(get SubState)")\",\"type\":\"$(json_escape "$(get Type)")\",\"pid\":${main:-0},\"restarts\":$(get NRestarts | grep -E '^[0-9]+$' || echo null),\"since\":$since,\"ports\":[${ports_json}],\"ports_fail\":[${pfail%,}],\"manual\":[${man_show%,}],\"sockets\":[${sockets%,}],\"sockets_fail\":[${sfail%,}],\"probe\":\"$probe\",\"probe_rc\":${probe_rc:-null},\"probe_msg\":\"$(json_escape "$probe_msg")\",\"log_errors\":$log_n,\"log_last\":\"$(json_escape "$log_last")\",\"custom_rc\":$c_rc,\"custom_msg\":\"$(json_escape "$c_msg")\"},"
    done
fi

# Extra tests die in RzMon bij een service-check zijn ingesteld (URL of poort), zie hierboven.
svc_tests=""
if [ "$HA_MODE" = 0 ]; then
    [ -r "$t_cache" ] && while IFS=$'\t' read -r t_id t_type t_target t_expect; do
        [[ "$t_id" =~ ^[0-9]+$ ]] || continue
        ok=false; msg=""
        if [ "$t_type" = url ]; then
            body=$(curl -sk -m 10 -w '\n%{http_code} %{time_total}' "$t_target" 2>&1)
            meta=${body##*$'\n'}; body=${body%$'\n'*}; code=${meta%% *}
            t=$(awk -v s="${meta#* }" 'BEGIN { printf "%d", s * 1000 }')
            if [ "$code" = 000 ] || [ -z "$code" ]; then msg="geen antwoord van $t_target"
            elif [ "$code" -ge 400 ]; then msg="HTTP $code van $t_target"
            elif [ -n "$t_expect" ] && [[ "$body" != *"$t_expect"* ]]; then msg="HTTP $code, maar \"$t_expect\" staat niet in het antwoord"
            else ok=true; msg="HTTP $code in $t ms${t_expect:+, bevat \"$t_expect\"}"; fi
        elif [ "$t_type" = port ]; then
            t_host=${t_target%:*}; t_port=${t_target##*:}
            [ "$t_host" = "$t_target" ] && t_host=127.0.0.1
            if tcp_open "$t_host" "$t_port"; then ok=true; msg="poort $t_port op $t_host antwoordt"; else msg="poort $t_port op $t_host antwoordt niet"; fi
        else
            continue
        fi
        svc_tests+="\"$t_id\":{\"ok\":$ok,\"msg\":\"$(json_escape "$msg")\"},"
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Commando-checks
# Regels "id <tab> cmd <tab> commando (base64) <tab> time-out <tab> nobody|root <tab> interval" uit RzMon.
# Uitvoeren in een kale omgeving, met time-out, standaard als nobody; uitvoer en exitcode gaan naar RzMon,
# dat ze vergelijkt met het verwachte antwoord. Volgens het interval van de check: tussendoor het vorige
# resultaat opnieuw meesturen. Alle commando's samen hooguit ~40 s per rapport.
cmd_results=""
if [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tcmd\t' "$t_cache"; then
    c_dir="$t_dir/cmdstate"
    mkdir -p "$c_dir" 2>/dev/null && chmod 700 "$c_dir" 2>/dev/null
    c_budget=$(( $(date +%s) + 40 ))
    while IFS=$'\t' read -r c_id c_type c_b64 c_timeout c_runas c_every; do
        [ "$c_type" = cmd ] && [[ "$c_id" =~ ^[0-9]+$ ]] || continue
        [[ "$c_timeout" =~ ^[0-9]+$ ]] && [ "$c_timeout" -ge 1 ] && [ "$c_timeout" -le 60 ] || c_timeout=10
        [[ "$c_every" =~ ^[0-9]+$ ]] || c_every=60
        [ "$c_runas" = root ] || c_runas=nobody
        case "$COMMANDS" in
            0|off|no|nee)
                cmd_results+="\"$c_id\":{\"blocked\":\"COMMANDS=0 in de instellingen van de agent\"},"
                continue ;;
            nobody) c_runas=nobody ;;
        esac
        c_state="$c_dir/$c_id.json"
        if [ -s "$c_state" ] && [ $(( $(date +%s) - $(stat -c %Y "$c_state") )) -lt $(( c_every - 5 )) ]; then
            cmd_results+="\"$c_id\":$(cat "$c_state"),"
            continue
        fi
        if [ "$(date +%s)" -ge "$c_budget" ]; then
            cmd_results+="\"$c_id\":{\"error\":\"overgeslagen: de andere commando's duurden samen te lang\"},"
            continue
        fi
        if ! c_cmd=$(printf '%s' "$c_b64" | base64 -d 2>/dev/null) || [ -z "$c_cmd" ]; then
            cmd_results+="\"$c_id\":{\"error\":\"ongeldig commando ontvangen\"},"
            continue
        fi
        c_run=()
        if [ "$c_runas" = nobody ] && [ "$(id -u)" = 0 ]; then
            if command -v runuser >/dev/null 2>&1; then c_run=(runuser -u nobody --)
            elif command -v setpriv >/dev/null 2>&1; then c_run=(setpriv --reuid=nobody --regid=nogroup --init-groups --)
            else
                cmd_results+="\"$c_id\":{\"error\":\"kan niet als nobody uitvoeren (runuser ontbreekt)\"},"
                continue
            fi
        fi
        c_t0=$(date +%s%N)
        (cd / && env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/ LANG=C.UTF-8 \
            "${c_run[@]}" timeout -k 2 "$c_timeout" /bin/bash -c "$c_cmd") </dev/null >"$c_dir/$c_id.out" 2>"$c_dir/$c_id.err"
        c_rc=$?
        c_ms=$(( ($(date +%s%N) - c_t0) / 1000000 ))
        printf '{"rc":%d,"out":"%s","err":"%s","ms":%d,"as":"%s","at":%d}' "$c_rc" \
            "$(head -c 4096 "$c_dir/$c_id.out" | json_text)" "$(head -c 500 "$c_dir/$c_id.err" | json_text)" \
            "$c_ms" "$c_runas" "$(date +%s)" >"$c_state"
        rm -f "$c_dir/$c_id.out" "$c_dir/$c_id.err"
        cmd_results+="\"$c_id\":$(cat "$c_state"),"
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Logbestanden doorzoeken
# Per zoekopdracht (ingesteld in RzMon): alleen de nieuwe regels sinds de vorige keer lezen,
# tellen hoeveel er op het patroon passen, en de laatste 5 treffers en de IP-adressen meesturen.
# Bron: een logbestand (binnen LOG_DIRS), "journal", "journal:<service>" of "kernel".
log_results=""
log_allowed() {  # log_allowed pad → 0 als het (echte) pad binnen LOG_DIRS valt
    local real dir
    real=$(readlink -f -- "$1" 2>/dev/null) || return 1
    for dir in $LOG_DIRS; do
        dir=$(readlink -f -- "$dir" 2>/dev/null) || continue
        [[ "$real" == "$dir"/* ]] && return 0
    done
    return 1
}
if [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tlog\t' "$t_cache"; then
    l_dir="$t_dir/logstate"
    mkdir -p "$l_dir" 2>/dev/null
    now_l=$(date +%s)
    while IFS=$'\t' read -r l_id l_type l_src l_pat; do
        [ "$l_type" = log ] && [[ "$l_id" =~ ^[0-9]+$ ]] || continue
        st_file="$l_dir/$l_id"
        prev=$(cat "$st_file" 2>/dev/null)
        new_text=""; l_err=""
        case "$l_src" in
            journal|journal:*|kernel)
                # Journal: van de vorige keer tot nu; de eerste keer alleen de vorige minuut
                since=${prev##*|}; [[ "$since" =~ ^[0-9]+$ ]] || since=$((now_l - 60))
                j_opt=(); [ "$l_src" = kernel ] && j_opt=(-k); [[ "$l_src" == journal:* ]] && j_opt=(-u "${l_src#journal:}")
                if command -v journalctl >/dev/null 2>&1; then
                    new_text=$(journalctl "${j_opt[@]}" --since "@$since" --until "@$((now_l - 1))" -q --no-pager -o short-iso 2>/dev/null | head -n 20000)
                else
                    l_err="journalctl ontbreekt op deze server"
                fi
                echo "journal|$now_l" >"$st_file" ;;
            /*)
                if ! log_allowed "$l_src"; then
                    l_err="$l_src valt buiten de toegestane mappen ($LOG_DIRS); voeg de map toe aan LOG_DIRS in /etc/default/rzmon-agent"
                elif [ ! -r "$l_src" ]; then
                    l_err="$l_src bestaat niet of is niet leesbaar"
                else
                    # Bestand: verder lezen waar we gebleven waren (inode|positie); nieuw of geroteerd bestand
                    # → vanaf het begin; de eerste keer vanaf het einde (oude regels tellen niet mee)
                    inode=$(stat -c %i "$l_src"); size=$(stat -c %s "$l_src")
                    p_inode=${prev%%|*}; p_pos=${prev##*|}
                    if [ -z "$prev" ]; then pos=$size
                    elif [ "$p_inode" != "$inode" ] || ! [[ "$p_pos" =~ ^[0-9]+$ ]] || [ "$p_pos" -gt "$size" ]; then pos=0
                    else pos=$p_pos; fi
                    # Maximaal 5 MB per keer, zodat een enorm logbestand de agent niet ophoudt. Alleen volledige
                    # regels: een half geschreven laatste regel wordt de volgende keer in zijn geheel gelezen.
                    chunk="$l_dir/$l_id.chunk"
                    tail -c +"$((pos + 1))" "$l_src" 2>/dev/null | head -c 5000000 >"$chunk"
                    nbytes=$(stat -c %s "$chunk")
                    if [ "$nbytes" -gt 0 ] && [ "$(tail -c 1 "$chunk" | od -An -tx1 | tr -d ' ')" != 0a ]; then
                        nbytes=$((nbytes - $(tail -n 1 "$chunk" | wc -c)))
                    fi
                    new_text=$(head -c "$nbytes" "$chunk"); rm -f "$chunk"
                    echo "$inode|$((pos + nbytes))" >"$st_file"
                fi ;;
            *) l_err="onbekende bron: $l_src" ;;
        esac
        hits=0; lines_json=""; ips_json=""
        if [ -z "$l_err" ] && [ -n "$new_text" ]; then
            matches=$(grep -aiE -- "$l_pat" <<<"$new_text"); rc=$?
            if [ "$rc" = 2 ]; then
                l_err="ongeldig zoekpatroon: $l_pat"
            elif [ -n "$matches" ]; then
                hits=$(grep -c . <<<"$matches")
                while IFS= read -r line; do
                    lines_json+="\"$(json_escape "${line:0:300}")\","
                done < <(tail -n 5 <<<"$matches" | tr -d '\000-\010\013-\037')
                # IP-adressen in de treffers (bv. waar mislukte logins vandaan komen): top 3
                ips_json=$(grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' <<<"$matches" | sort | uniq -c | sort -rn | head -3 |
                    awk '{ printf "[\"%s\",%d],", $2, $1 }')
            fi
        fi
        log_results+="\"$l_id\":{\"new\":$hits,\"lines\":[${lines_json%,}],\"ips\":[${ips_json%,}],\"error\":\"$(json_escape "$l_err")\"},"
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Logins, sudo en su
# Als RzMon erom vraagt (regel "0 <tab> auth <tab> 1"): de nieuwe regels van het auth-log sinds de vorige
# keer (journal, anders /var/log/secure of /var/log/auth.log). Geslaagde logins (SSH, console), sudo en su
# gaan als losse gebeurtenissen naar RzMon; mislukte pogingen worden per soort/IP/gebruiker opgeteld.
# Wachtwoorden in sudo-commando's (bv. mysql -pGeheim, password=…) worden hier al gemaskeerd.
auth_json=""
mask_secrets() {
    sed -E 's/((password|passwd|pass|pwd|token|secret|apikey|api_key)[=:])[^ ]+/\1••••/Ig;
            s/(--password[= ])[^ ]+/\1••••/g; s/((mysql|mariadb|mysqldump|mysqladmin)( [^|;&]*)? -p)[^ ]+/\1••••/g'
}
if [ "$HA_MODE" = 0 ] && [ "$AUTH_EVENTS" != 0 ] && [ -r "${t_cache-}" ] && grep -q $'^0\tauth\t1' "$t_cache"; then
    a_state="$t_dir/authstate"
    now_a=$(date +%s)
    a_prev=$(cat "$a_state" 2>/dev/null)
    a_lines=""   # regels "epoch <tab> programma <tab> bericht"
    # Journal alleen als journald echt draait; anders (of met AUTH_SOURCE=file) de logbestanden
    if [ "${AUTH_SOURCE:-}" != file ] && [ -d /run/systemd/journal ] && command -v journalctl >/dev/null 2>&1; then
        since=${a_prev#journal|}; [[ "$since" =~ ^[0-9]+$ ]] || since=$((now_a - 60))
        # Facility auth (4) en authpriv (10): sshd, login, sudo, su
        a_lines=$(journalctl SYSLOG_FACILITY=4 SYSLOG_FACILITY=10 --since "@$since" --until "@$((now_a - 1))" -q --no-pager -o short-unix 2>/dev/null |
            head -n 20000 | awk '{ t = $1; sub(/\..*/, "", t); id = $3; sub(/(\[[0-9]+\])?:$/, "", id)
                                   if (match($0, /^[^ ]+ [^ ]+ [^ ]+ /)) print t "\t" id "\t" substr($0, RLENGTH + 1) }')
        echo "journal|$now_a" >"$a_state"
    else
        for a_file in $AUTH_LOGS; do
            [ -r "$a_file" ] || continue
            # Verder lezen waar we gebleven waren (inode|positie); de eerste keer vanaf het einde
            inode=$(stat -c %i "$a_file"); size=$(stat -c %s "$a_file")
            p_inode=$(cut -d'|' -f2 <<<"$a_prev"); p_pos=$(cut -d'|' -f3 <<<"$a_prev")
            if [[ "$a_prev" != file\|* ]]; then pos=$size
            elif [ "$p_inode" != "$inode" ] || ! [[ "$p_pos" =~ ^[0-9]+$ ]] || [ "$p_pos" -gt "$size" ]; then pos=0
            else pos=$p_pos; fi
            # Hooguit 5 MB per keer en alleen volledige regels (een half geschreven regel komt de volgende keer)
            a_tmp="$t_dir/auth.chunk"
            tail -c +"$((pos + 1))" "$a_file" 2>/dev/null | head -c 5000000 >"$a_tmp"
            nbytes=$(stat -c %s "$a_tmp")
            if [ "$nbytes" -gt 0 ] && [ "$(tail -c 1 "$a_tmp" | od -An -tx1 | tr -d ' ')" != 0a ]; then
                nbytes=$((nbytes - $(tail -n 1 "$a_tmp" | wc -c)))
            fi
            a_chunk=$(head -c "$nbytes" "$a_tmp"); rm -f "$a_tmp"
            echo "file|$inode|$((pos + nbytes))" >"$a_state"
            # "Oct 21 10:00:01 host sshd[12]: …" of "2026-10-21T10:00:01+02:00 host sshd[12]: …"; tijd = nu
            a_lines=$(awk -v now="$now_a" '{
                if ($1 ~ /^[0-9]{4}-/) { h = 3 } else { h = 5 }
                id = $h; sub(/(\[[0-9]+\])?:$/, "", id)
                msg = $0; for (i = 1; i <= h; i++) { if (match(msg, /^[^ ]+ +/)) msg = substr(msg, RLENGTH + 1) }
                print now "\t" id "\t" msg }' <<<"$a_chunk")
            break
        done
    fi

    a_events=""; n_events=0
    declare -A f_n=() f_users=() f_t=() f_extra=()
    a_fail() {  # a_fail <sleutel> <tijd> <gebruiker> <extra json-velden>
        f_n[$1]=$(( ${f_n[$1]:-0} + 1 )); f_t[$1]=$2; f_extra[$1]=$4
        local list=" ${f_users[$1]:-} "
        [ -n "$3" ] && [[ "$list" != *" $3 "* ]] && [ "$(wc -w <<<"${f_users[$1]:-}")" -lt 8 ] && f_users[$1]+=" $3"
    }
    a_event() {  # a_event <json-object zonder accolades>
        [ "$n_events" -lt 300 ] || return 0
        a_events+="{$1},"; n_events=$((n_events + 1))
    }
    re_ssh_ok='^Accepted ([^ ]+) for ([^ ]+) from ([^ ]+) port'
    re_ssh_fail='^Failed ([^ ]+) for ([^ ]+) from ([^ ]+) port'
    re_ssh_invalid='^Invalid user ([^ ]*) from ([^ ]+)'
    re_login_ok='^pam_unix\(login:session\): session opened for user ([^ (]+)'
    re_login_fail='^pam_unix\(login:auth\): authentication failure;.* tty=([^ ]*).* user=([^ ]+)'
    re_sudo='^ *([^ ]+) : (.*)TTY=([^ ]+) ; PWD=(.*) ; USER=([^ ]+) ; (.* ; )?COMMAND=(.*)$'
    re_su_ok='^pam_unix\(su(-l)?:session\): session opened for user ([^ (]+)(\(uid=[0-9]+\))? by ([^ (]*)'
    re_su_fail='^pam_unix\(su(-l)?:auth\): authentication failure;.* logname=([^ ]*) .* tty=([^ ]*) .* user=([^ ]+)'
    while IFS=$'\t' read -r a_t a_id a_msg; do
        [ -n "$a_msg" ] || continue
        case "$a_id" in
            sshd|sshd-session)
                if [[ "$a_msg" =~ $re_ssh_ok ]]; then
                    a_event "\"t\":$a_t,\"k\":\"ssh\",\"u\":\"$(json_escape "${BASH_REMATCH[2]}")\",\"ip\":\"$(json_escape "${BASH_REMATCH[3]}")\",\"m\":\"$(json_escape "${BASH_REMATCH[1]}")\""
                elif [[ "$a_msg" =~ $re_ssh_fail ]] && [ "${BASH_REMATCH[2]}" != invalid ]; then
                    a_fail "ssh|${BASH_REMATCH[3]}" "$a_t" "${BASH_REMATCH[2]}" "\"ip\":\"$(json_escape "${BASH_REMATCH[3]}")\""
                elif [[ "$a_msg" =~ $re_ssh_invalid ]]; then
                    a_fail "ssh|${BASH_REMATCH[2]}" "$a_t" "${BASH_REMATCH[1]:-?}" "\"ip\":\"$(json_escape "${BASH_REMATCH[2]}")\""
                fi ;;
            login)
                if [[ "$a_msg" =~ $re_login_ok ]]; then
                    a_event "\"t\":$a_t,\"k\":\"console\",\"u\":\"$(json_escape "${BASH_REMATCH[1]}")\""
                elif [[ "$a_msg" =~ $re_login_fail ]]; then
                    a_fail "console|${BASH_REMATCH[1]}" "$a_t" "${BASH_REMATCH[2]}" "\"tty\":\"$(json_escape "${BASH_REMATCH[1]}")\""
                fi ;;
            sudo)
                if [[ "$a_msg" =~ $re_sudo ]]; then
                    s_user=${BASH_REMATCH[1]}; s_why=${BASH_REMATCH[2]% ; }; s_tty=${BASH_REMATCH[3]}; s_pwd=${BASH_REMATCH[4]}
                    s_as=${BASH_REMATCH[5]}; s_cmd=$(printf '%s' "${BASH_REMATCH[7]:0:500}" | mask_secrets | json_text)
                    if [ -z "$s_why" ]; then
                        a_event "\"t\":$a_t,\"k\":\"sudo\",\"u\":\"$(json_escape "$s_user")\",\"as\":\"$(json_escape "$s_as")\",\"cmd\":\"$s_cmd\",\"tty\":\"$(json_escape "$s_tty")\",\"pwd\":\"$(json_escape "${s_pwd:0:200}")\""
                    else
                        a_fail "sudo|$s_user|$s_as|${s_cmd:0:200}" "$a_t" "$s_user" "\"as\":\"$(json_escape "$s_as")\",\"cmd\":\"$s_cmd\",\"why\":\"$(json_escape "${s_why:0:150}")\""
                    fi
                fi ;;
            su|su-l)
                # Alleen van een echte gebruiker: su vanuit diensten (bv. logrotate) heeft geen naam achter "by"
                if [[ "$a_msg" =~ $re_su_ok ]] && [ -n "${BASH_REMATCH[4]}" ]; then
                    a_event "\"t\":$a_t,\"k\":\"su\",\"u\":\"$(json_escape "${BASH_REMATCH[4]}")\",\"as\":\"$(json_escape "${BASH_REMATCH[2]}")\""
                elif [[ "$a_msg" =~ $re_su_fail ]]; then
                    a_fail "su|${BASH_REMATCH[2]}|${BASH_REMATCH[4]}" "$a_t" "${BASH_REMATCH[2]:-?}" "\"as\":\"$(json_escape "${BASH_REMATCH[4]}")\",\"tty\":\"$(json_escape "${BASH_REMATCH[3]}")\""
                fi ;;
        esac
    done <<<"$a_lines"
    a_fails=""
    for key in "${!f_n[@]}"; do
        users=""; for u in ${f_users[$key]:-}; do users+="\"$(json_escape "$u")\","; done
        a_fails+="{\"k\":\"${key%%|*}\",\"n\":${f_n[$key]},\"t\":${f_t[$key]},\"users\":[${users%,}]${f_extra[$key]:+,${f_extra[$key]}}},"
    done
    auth_json="\"auth\":{\"events\":[${a_events%,}],\"fails\":[${a_fails%,}]},"
fi

# ---------------------------------------------------------------- IDS (intrusion detection)
# Als RzMon erom vraagt (regel "0 <tab> ids <tab> learn|block"): wat RzMon nodig heeft om afwijkingen te zien.
# Vergelijken gebeurt in RzMon (daar staat de basismeting, buiten bereik van een aanvaller op deze server).
#  - gevoelige bestanden (SHA-256), gebruikers, kernelmodules, verdachte processen, verdachte verbindingen
#  - SUID-bestanden (elk uur) en pakketcontrole rpm -Va / debsums (dagelijks), op de achtergrond
#  - blokkeren: bij "block" de IP's uit RzMon in een eigen nftables-tabel "inet rzmon" (los van firewalld);
#    bij "learn" of IDS uit wordt die tabel weer verwijderd
#  - bestanden bewaken: hash, grootte, rechten en eigenaar van de paden uit RzMon (alleen wat veranderd is)
ids_json=""
fim_commit=""
ids_mode=""
[ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && ids_mode=$(awk -F'\t' '$1 == "0" && $2 == "ids" { print $3; exit }' "$t_cache")
ids_bg() {  # ids_bg <naam> <max. leeftijd in s> <commando>: op de achtergrond uitvoeren, uitvoer in $i_dir/<naam>
    local name=$1 age=$2 cmd=$3 out="$i_dir/$1"
    [ -f "$out" ] && [ $(( $(date +%s) - $(stat -c %Y "$out") )) -lt "$age" ] && return 0
    [ -f "$out.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$out.lock") )) -lt 7200 ] && return 0
    : >"$out.lock"
    local job
    job="nice -n 19 ionice -c3 bash -c $(printf '%q' "$cmd") >$(printf '%q' "$out.tmp") 2>/dev/null; mv -f $(printf '%q' "$out.tmp") $(printf '%q' "$out"); rm -f $(printf '%q' "$out.lock")"
    if [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --collect --unit="rzmon-ids-$name-$(date +%s)" /bin/bash -c "$job" >/dev/null 2>&1 || rm -f "$out.lock"
    else
        setsid bash -c "$job" </dev/null >/dev/null 2>&1 &
    fi
}
if [ -n "$ids_mode" ]; then
    i_dir="$t_dir/ids"
    mkdir -p "$i_dir" 2>/dev/null && chmod 700 "$i_dir" 2>/dev/null
    # 1. Gevoelige bestanden: inlog, sudo, SSH, cron, systemd-units, ld.so.preload (rootkits)
    shopt -s nullglob
    i_cand=(/etc/passwd /etc/shadow /etc/group /etc/gshadow /etc/sudoers /etc/sudoers.d/* /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*
        /etc/crontab /etc/cron.d/* /var/spool/cron/* /var/spool/cron/crontabs/* /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys
        /etc/systemd/system/*.service /etc/systemd/system/*.timer /etc/ld.so.preload /etc/rc.local /etc/profile.d/*.sh)
    shopt -u nullglob
    i_files=()
    for f in "${i_cand[@]}"; do [ -f "$f" ] && [ -r "$f" ] && i_files+=("$f"); done
    files_json=""
    if [ ${#i_files[@]} -gt 0 ]; then
        while read -r h f; do
            [[ "$h" =~ ^[0-9a-f]{64}$ ]] && files_json+="\"$(json_escape "$f")\":\"$h\","
        done < <(sha256sum -- "${i_files[@]:0:1000}" 2>/dev/null)
    fi
    # 2. Gebruikers (naam en UID) en 3. kernelmodules
    users_json=$(awk -F: '$1 ~ /^[A-Za-z0-9._-]+$/ { printf "[\"%s\",%d],", $1, $3 }' /etc/passwd 2>/dev/null)
    mods_json=$(awk '$1 ~ /^[A-Za-z0-9_]+$/ { printf "\"%s\",", $1 }' /proc/modules 2>/dev/null)
    # 4. SUID-bestanden (elk uur) en 5. pakketcontrole (dagelijks), op de achtergrond
    # Container-images (docker, podman) overslaan: daar staan veel SUID-bestanden die niets met de server zelf te maken hebben
    ids_bg suid 3600 "find / -xdev \\( -path /proc -o -path /sys -o -path /dev -o -path /run -o -path /var/lib/docker -o -path /var/lib/containers -o -path /var/lib/containerd -o -path '/home/*/.local/share/containers' \\) -prune -o -type f -perm -4000 -print 2>/dev/null | head -n 2000"
    if command -v rpm >/dev/null 2>&1 && { [ -d /var/lib/rpm ] || [ -d /usr/lib/sysimage/rpm ]; }; then
        # Inhoud (5) gewijzigd, geen configuratiebestanden, alleen programma's en bibliotheken
        ids_bg pkgdiff 86400 "rpm -Va --noconfig --nomtime 2>/dev/null | awk '\$1 ~ /^..5/ { print \$NF }' | grep -E '^/(usr/)?(s?bin|lib|lib64)/' | head -n 100"
    elif command -v debsums >/dev/null 2>&1; then
        ids_bg pkgdiff 86400 "debsums -c 2>/dev/null | grep -E '^/(usr/)?(s?bin|lib|lib64)/' | head -n 100"
    fi
    suid_part=""
    [ -f "$i_dir/suid" ] && suid_part=",\"suid\":[$(awk '{ gsub(/\\/, "\\\\"); gsub(/"/, "\\\""); printf "\"%s\",", $0 }' "$i_dir/suid" | sed 's/,$//')]"
    pkg_part=""
    [ -f "$i_dir/pkgdiff" ] && pkg_part=",\"pkgdiff\":[$(awk '{ gsub(/\\/, "\\\\"); gsub(/"/, "\\\""); printf "\"%s\",", $0 }' "$i_dir/pkgdiff" | sed 's/,$//')]"
    # Laatste pakketactiviteit (dnf/apt): bestanden die dan veranderen zijn waarschijnlijk de update
    pkg_t=0
    for f in /var/log/dnf.rpm.log /var/log/dnf5.log /var/log/dpkg.log /var/log/apt/history.log; do
        [ -f "$f" ] && t=$(stat -c %Y "$f") && [ "$t" -gt "$pkg_t" ] && pkg_t=$t
    done
    # 6. Verdachte processen: uit /tmp, /dev/shm of /var/tmp, zonder bestand (memfd), of programma verwijderd
    procs_json=""; n_procs=0
    for p in /proc/[0-9]*; do
        exe=$(readlink "$p/exe" 2>/dev/null) || continue
        flag=0
        case "$exe" in
            /tmp/*|/dev/shm/*|/var/tmp/*) flag=1 ;;
            /memfd:*) case "$exe" in *runc*|*crun*|*containerd*) ;; *) flag=1 ;; esac ;;
            *" (deleted)")
                # Na een update draait een dienst vaak nog met het oude (verwijderde) programma: dat is normaal
                case "${exe% (deleted)}" in /usr/*|/opt/*|/lib*|/bin/*|/sbin/*|/snap/*|/var/lib/*) ;; *) flag=1 ;; esac ;;
        esac
        [ "$flag" = 1 ] || continue
        cmd=$(tr '\0' ' ' <"$p/cmdline" 2>/dev/null | head -c 300 | json_text)
        procs_json+="{\"pid\":${p#/proc/},\"exe\":\"$(json_escape "$exe")\",\"user\":\"$(stat -c %U "$p" 2>/dev/null)\",\"cmd\":\"$cmd\"},"
        n_procs=$((n_procs + 1)); [ "$n_procs" -ge 20 ] && break
    done
    # 7. Verbindingen: een shell (of nc/socat) met een verbinding naar buiten, of uitgaand naar een verdachte poort
    conns_json=""
    if command -v ss >/dev/null 2>&1; then
        listen=" $(ss -Htln 2>/dev/null | awk '{ p = $4; sub(/.*:/, "", p); printf "%s ", p }')"
        while IFS=$'\t' read -r c_procs c_pid c_local c_peer; do
            c_proc=${c_procs%%,*}
            peer_ip=${c_peer%:*}; peer_ip=${peer_ip#[}; peer_ip=${peer_ip%]}; peer_port=${c_peer##*:}; local_port=${c_local##*:}
            case "$peer_ip" in 127.*|::1|::ffff:127.*) continue ;; esac
            kind=""
            # Een shell die de verbinding vasthoudt (ook als een ander proces, zoals sleep, hem deelt)
            for c_name in ${c_procs//,/ }; do
                case "$c_name" in bash|sh|dash|zsh|ksh|nc|ncat|netcat|socat|telnet) kind=shell; c_proc=$c_name ;; esac
            done
            if [ -z "$kind" ] && [[ "$listen" != *" $local_port "* ]]; then
                [[ " 1337 4444 4445 5555 6666 6667 6697 12345 31337 " == *" $peer_port "* ]] && kind=port
            fi
            [ -n "$kind" ] && conns_json+="{\"kind\":\"$kind\",\"proc\":\"$(json_escape "$c_proc")\",\"pid\":${c_pid:-0},\"local\":\"$(json_escape "$c_local")\",\"peer\":\"$(json_escape "$c_peer")\"},"
        done < <(ss -tnpH state established 2>/dev/null | awk '{ names = ""; pid = 0; rest = $0
                while (match(rest, /\("[^"]+",pid=[0-9]+/)) { s = substr(rest, RSTART + 2, RLENGTH - 2); split(s, a, "\",pid=")
                    names = names (names == "" ? "" : ",") a[1]; if (!pid) pid = a[2]; rest = substr(rest, RSTART + RLENGTH) }
                if (names != "") print names "\t" pid "\t" $3 "\t" $4 }' | head -n 200)
    fi
    ids_json="\"ids\":{\"mode\":\"$ids_mode\",\"files\":{${files_json%,}},\"users\":[${users_json%,}],\"modules\":[${mods_json%,}]${suid_part}${pkg_part},\"pkg\":$pkg_t,\"procs\":[${procs_json%,}],\"conns\":[${conns_json%,}],"

    # 8. Bestanden bewaken (regel "0 <tab> fim <tab> base64" met e <tab> epoch, p <tab> ernst <tab> pad, x <tab> uitsluiten).
    #    Elke run een snelle controle (grootte, tijd, rechten, eigenaar); alleen bij een verschil een nieuwe hash.
    #    Eén keer per dag op de achtergrond alles opnieuw hashen, voor wie de tijd van een bestand terugzet.
    #    Alleen wijzigingen gaan mee; de nieuwe stand wordt pas bewaard als RzMon het rapport heeft aangenomen.
    fim_dir="$i_dir/fim"
    fimc=""   # voorstellen voor "＋ Aanbevolen" in RzMon: gangbare mappen die hier bestaan en niet leeg zijn
    for d in /usr/local/bin /usr/local/sbin /opt /etc/nginx /etc/httpd /etc/apache2 /etc/caddy /etc/haproxy /etc/postfix /var/www /srv/www /srv/http; do
        [ -d "$d" ] && [ -n "$(ls -A "$d" 2>/dev/null | head -n 1)" ] && fimc+="\"$d\","
    done
    ids_json+="\"fimc\":[${fimc%,}],"
    fim_b64=$(awk -F'\t' '$1 == "0" && $2 == "fim" { print $3; exit }' "$t_cache")
    if [ -n "$fim_b64" ] && fim_cfg=$(printf '%s' "$fim_b64" | base64 -d 2>/dev/null) && mkdir -p "$fim_dir" 2>/dev/null; then
        chmod 700 "$fim_dir"
        fim_epoch=$(awk -F'\t' '$1 == "e" { print $2; exit }' <<<"$fim_cfg")
        fim_x=()   # uitsluiten: *.log (naam), cache/ (map), /pad/* (pad)
        while IFS=$'\t' read -r k a; do
            [ "$k" = x ] && [ -n "$a" ] || continue
            case "$a" in */) fim_x+=(! -path "*/${a%/}/*") ;; */*) fim_x+=(! -path "$a") ;; *) fim_x+=(! -name "$a") ;; esac
        done <<<"$fim_cfg"
        # Lijst: pad, grootte, tijd, rechten, eigenaar, ernst, bewaakt pad (max. 10.000 bestanden; per pad telt de eerste)
        while IFS=$'\t' read -r k sev root; do
            [ "$k" = p ] || continue
            fmt="%p\t%s\t%T@\t%m\t%u:%g\t$sev\t${root//%/%%}\n"
            base=${root%%[*?[]*}
            if [ "$base" != "$root" ]; then
                # Met jokertekens: zoeken vanaf de map vóór het eerste jokerteken; ** = elke diepte
                base=${base%/*}
                find "${base:-/}" -xdev -type f -path "${root//\*\*\//*}" "${fim_x[@]}" -printf "$fmt" 2>/dev/null
            elif [ -d "$root" ]; then
                find "$root" -xdev -type f "${fim_x[@]}" -printf "$fmt" 2>/dev/null
            elif [ -f "$root" ]; then
                find "$root" -maxdepth 0 -type f -printf "$fmt" 2>/dev/null
            fi
        done <<<"$fim_cfg" | awk -F'\t' 'NF == 7 && !seen[$1]++' | head -n 10000 >"$fim_dir/list"
        # Vorige stand: niet bij de eerste keer of na een nieuwe basismeting in RzMon (andere epoch): dan alles sturen
        fim_full=1; fim_old=/dev/null; fim_oldroots=/dev/null
        if [ -f "$fim_dir/state" ] && [ "$(cat "$fim_dir/meta" 2>/dev/null)" = "$fim_epoch" ]; then
            fim_full=0; fim_old="$fim_dir/state"; fim_oldroots="$fim_dir/roots"
        fi
        fim_rh=/dev/null
        [ -f "$i_dir/rehash" ] && [ "$i_dir/rehash" -nt "$i_dir/rehash.used" ] && fim_rh="$i_dir/rehash"
        # Hash overnemen als grootte en tijd niet veranderd zijn (of uit de dagelijkse controle); anders "?" = opnieuw hashen
        awk -F'\t' -v OFS='\t' -v big=104857600 '
            FILENAME == ARGV[1] { rs[$1] = $2 "|" $3; rh[$1] = $4; next }
            FILENAME == ARGV[2] { os[$1] = $2 "|" $3; oh[$1] = $6; next }
            { h = "?"
              if ($2 + 0 > big) h = "m" int($3)
              else if (($1 in rs) && rs[$1] == $2 "|" $3) h = rh[$1]
              else if (($1 in os) && os[$1] == $2 "|" $3) h = oh[$1]
              print $1, $2, $3, $4, $5, h, $6, $7 }' "$fim_rh" "$fim_old" "$fim_dir/list" >"$fim_dir/new0"
        awk -F'\t' '$6 == "?" { print $1 }' "$fim_dir/new0" | tr '\n' '\0' | nice -n 19 xargs -0 -r sha256sum -- 2>/dev/null >"$fim_dir/hashes"
        awk -F'\t' -v OFS='\t' 'FILENAME == ARGV[1] { nh[substr($0, 67)] = substr($0, 1, 64); next }
            $6 == "?" { if (!($1 in nh)) next; $6 = nh[$1] } { print }' "$fim_dir/hashes" "$fim_dir/new0" >"$fim_dir/state.new"
        # Wat RzMon moet weten: c = nieuw/gewijzigd, b = onder een nieuw bewaakt pad (basismeting), d = weg, u = niet meer bewaakt
        fim_json=$(awk -F'\t' -v full="$fim_full" '
            function j(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); return "\"" s "\"" }
            FILENAME == ARGV[1] { if ($1 == "p") cur[$3] = 1; next }
            FILENAME == ARGV[2] { oldroot[$0] = 1; next }
            FILENAME == ARGV[3] { old[$1] = $2 "|" $4 "|" $5 "|" $6; osev[$1] = $7; oroot[$1] = $8; next }
            { seen[$1] = 1
              e = "[" j($1) "," ($2 + 0) "," j($4) "," j($5) "," j($6) "," ($7 + 0) "," j($8) "]"
              if (full) c = c e ","
              else if (!($8 in oldroot)) b = b e ","
              else if (!(($1 in old) && old[$1] == $2 "|" $4 "|" $5 "|" $6)) c = c e "," }
            END { for (p in old) if (!(p in seen)) { if (oroot[p] in cur) d = d "[" j(p) "," (osev[p] + 0) "," j(oroot[p]) "],"; else u = u j(p) "," }
                  sub(/,$/, "", c); sub(/,$/, "", b); sub(/,$/, "", d); sub(/,$/, "", u)
                  printf "\"full\":%d,\"c\":[%s],\"b\":[%s],\"d\":[%s],\"u\":[%s]", full, c, b, d, u }' \
            <(printf '%s\n' "$fim_cfg") "$fim_oldroots" "$fim_old" "$fim_dir/state.new")
        ids_json+="\"fim\":{\"n\":$(wc -l <"$fim_dir/state.new"),$fim_json},"
        printf '%s\n' "$fim_epoch" >"$fim_dir/meta.new"
        awk -F'\t' '$1 == "p" { print $3 }' <<<"$fim_cfg" >"$fim_dir/roots.new"
        fim_commit=1
        # Dagelijks: alles opnieuw hashen, op de achtergrond
        [ -f "$fim_dir/state" ] && ids_bg rehash 86400 "bash $(printf '%q' "$(readlink -f "$0")") --fim-rehash $(printf '%q' "$fim_dir/state")"
    else
        rm -rf "$fim_dir"   # niets (meer) te bewaken
    fi
fi

# Blokkeren: eigen nftables-tabel "inet rzmon" met de IP's uit RzMon (alleen in de modus blokkeren)
fw_json=""
if [ "$ids_mode" = block ]; then
    if command -v midclt >/dev/null 2>&1; then
        fw_json="\"fw\":{\"state\":\"unsupported\",\"err\":\"TrueNAS: alleen detecteren\"}"
    elif [ "$IDS_BLOCK" = 0 ]; then
        fw_json="\"fw\":{\"state\":\"disabled\",\"err\":\"IDS_BLOCK=0 op deze server\"}"
    elif ! command -v nft >/dev/null 2>&1; then
        fw_json="\"fw\":{\"state\":\"error\",\"err\":\"nft (nftables) ontbreekt\"}"
    else
        # Nooit het adres van RzMon zelf blokkeren
        rz_host=${MONITOR_URL#*://}; rz_host=${rz_host%%/*}; rz_host=${rz_host%:*}; rz_host=${rz_host#[}; rz_host=${rz_host%]}
        rz_ips=" $(getent ahosts "$rz_host" 2>/dev/null | awk '{ print $1 }' | sort -u | tr '\n' ' ') $rz_host "
        want4=""; want6=""; now_f=$(date +%s)
        while IFS=$'\t' read -r z t b_ip b_until; do
            [ "$z" = 0 ] && [ "$t" = block ] || continue
            [[ "$b_until" =~ ^[0-9]+$ ]] && [ "$b_until" != 0 ] && [ "$b_until" -le "$now_f" ] && continue
            [[ "$rz_ips" == *" $b_ip "* ]] && continue
            if [[ "$b_ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then want4+="$b_ip, "
            elif [[ "$b_ip" =~ ^[0-9a-fA-F:]+$ ]] && [[ "$b_ip" == *:* ]]; then want6+="$b_ip, "; fi
        done <"$t_cache"
        sig=$(printf '%s|%s' "$want4" "$want6" | sha1sum | cut -c1-16)
        fw_err=""
        if ! nft list table inet rzmon >/dev/null 2>&1 || [ "$(cat "$i_dir/fw.sig" 2>/dev/null)" != "$sig" ]; then
            # Elementen apart opbouwen: accolades binnen ${…:+…} zouden de expansie te vroeg afsluiten
            el4=""; [ -n "$want4" ] && el4=" elements = { ${want4%, } };"
            el6=""; [ -n "$want6" ] && el6=" elements = { ${want6%, } };"
            fw_apply() {  # fw_apply <"counter;" of ""> : tabel in één keer opnieuw opbouwen
                { echo "table inet rzmon"; echo "delete table inet rzmon"; echo "table inet rzmon {"
                  echo "  set bl4 { type ipv4_addr; $1$el4 }"
                  echo "  set bl6 { type ipv6_addr; $1$el6 }"
                  echo "  chain input { type filter hook input priority -5; policy accept; ip saddr @bl4 drop; ip6 saddr @bl6 drop; }"
                  echo "}"; } | nft -f - 2>"$i_dir/fw.err"
            }
            if fw_apply "counter;" || fw_apply ""; then
                echo "$sig" >"$i_dir/fw.sig"
            else
                fw_err=$(head -c 200 "$i_dir/fw.err" | json_text)
            fi
        fi
        if [ -n "$fw_err" ]; then
            fw_json="\"fw\":{\"state\":\"error\",\"err\":\"$fw_err\"}"
        else
            hits=$(nft list table inet rzmon 2>/dev/null | grep -oE '[0-9a-fA-F:.]+ counter packets [0-9]+' | awk '{ printf "\"%s\":%s,", $1, $4 }')
            fw_json="\"fw\":{\"state\":\"active\",\"n\":$(printf '%s%s' "$want4" "$want6" | grep -o ',' | wc -l),\"hits\":{${hits%,}}}"
        fi
    fi
elif [ "$HA_MODE" = 0 ] && command -v nft >/dev/null 2>&1 && nft list table inet rzmon >/dev/null 2>&1; then
    # Leren of IDS uit: niets meer blokkeren
    nft delete table inet rzmon 2>/dev/null
    rm -f "${i_dir:-$t_dir/ids}/fw.sig"
fi
if [ -n "$ids_json" ]; then
    [ -n "$fw_json" ] || fw_json='"fw":{"state":"off"}'
    ids_json+="$fw_json},"
fi

# ---------------------------------------------------------------- Podman: containers, pods en opslag
# Per context: versie, containers (status, health, herstarts, grootte, volumes), pods en opslag.
# Volumegroottes (du) worden een uur bewaard: die kunnen groot zijn en veranderen langzaam.
podman_json=""
if [ ${#podman_ctx[@]} -gt 0 ]; then
    pod_stats_b=$(podman_stats)
    vol_cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    mkdir -p "$vol_cache_dir" 2>/dev/null && [ -w "$vol_cache_dir" ] || vol_cache_dir="${TMPDIR:-/tmp}"
    vol_cache="$vol_cache_dir/volumes.cache"
    now_s=$(date +%s)
    podman_raw=$(
        printf '%s\n' "$pod_stats_a" | sed 's/^S|/A|/'
        printf '%s\n' "$pod_stats_b" | sed 's/^S|/B|/'
        for ctx in "${podman_ctx[@]}"; do
            if ! ver=$(pm "$ctx" version --format '{{.Client.Version}}' 2>/dev/null); then
                # Podman zet waarschuwingen vóór de echte fout; die echte fout is wat telt
                out=$(pm "$ctx" version 2>&1); rc=$?
                err=$(grep -m1 -iE 'error|denied|not permitted|read-only' <<<"$out" | sed 's/^time="[^"]*" level=[a-z]* msg="//; s/"$//')
                home=$(getent passwd "$ctx" | cut -d: -f6)
                if [ "$ctx" != root ] && [ -n "${INVOCATION_ID-}" ] && [ -n "$home" ] && ! [ -w "$home" ]; then
                    # systemd-service met ProtectHome=read-only (agent geïnstalleerd vóór podman)
                    err="home-map is alleen-lezen voor de agent; los op met: sudo rzmon-agent-setup --update${err:+ ($err)}"
                elif [ "$rc" = 124 ]; then
                    err="podman gaf binnen 60 s geen antwoord"
                elif [ -z "$err" ]; then
                    err=$(grep -v '^[[:space:]]*$' <<<"$out" | tail -1 | sed 's/^time="[^"]*" level=[a-z]* msg="//; s/"$//')
                    err="${err:-podman stopte met code $rc}"
                fi
                printf 'E|%s|%s\n' "$ctx" "$(tr '|' ' ' <<<"$err" | cut -c1-200)"
                continue
            fi
            printf 'V|%s|%s\n' "$ctx" "$ver"
            pm "$ctx" ps -a --pod --size --no-trunc --format '{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.ExitCode}}|{{.Restarts}}|{{.PodName}}|{{.IsInfra}}|{{.StartedAt}}|{{.Size}}' 2>/dev/null \
                | sed "s/^/C|$ctx|/"
            pm "$ctx" pod ps --format '{{.Name}}|{{.Status}}|{{.NumberOfContainers}}' 2>/dev/null | sed "s/^/P|$ctx|/"
            pm "$ctx" system df --format '{{.Type}}|{{.RawSize}}|{{.RawReclaimable}}|{{.Total}}|{{.Active}}' 2>/dev/null | sed "s/^/D|$ctx|/"
            ids=$(pm "$ctx" ps -aq --no-trunc 2>/dev/null)
            if [ -n "$ids" ]; then
                # shellcheck disable=SC2086
                pm "$ctx" inspect --format '{{.ID}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}' $ids 2>/dev/null \
                    | sed "s/^/M|$ctx|/"
            fi
        done
    )
    # Volumegroottes: uit de cache als die minder dan een uur oud is
    vols=$(grep '^M|' <<<"$podman_raw" | cut -d'|' -f4 | tr ';' '\n' | grep -v '^$' | sort -u)
    if [ -n "$vols" ]; then
        if [ -r "$vol_cache" ] && [ $(( now_s - $(head -1 "$vol_cache") )) -lt 3600 ]; then
            vol_sizes=$(tail -n +2 "$vol_cache")
        else
            vol_sizes=$(while IFS= read -r v; do
                printf 'Z|%s|%s\n' "$v" "$(du -sb "$v" 2>/dev/null | cut -f1)"
            done <<<"$vols")
            (umask 077; { echo "$now_s"; echo "$vol_sizes"; } >"$vol_cache") 2>/dev/null || true
        fi
        podman_raw+=$'\n'"$vol_sizes"
    fi
    podman_json=$(awk -F'|' -v now="$now_s" '
        function esc(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); return s }
        function num(v) { return (v ~ /^[0-9]+(\.[0-9]+)?$/) ? v : 0 }
        # "11.8kB (virtual 8.12MB)" → bytes (podman gebruikt SI: kB = 1000)
        function bytes(s,   n, u) {
            if (!match(s, /^[0-9.]+[a-zA-Z]*/)) return 0
            s = substr(s, 1, RLENGTH); n = s + 0; u = s; sub(/^[0-9.]+/, "", u); u = tolower(u)
            return n * (u == "kb" ? 1e3 : u == "mb" ? 1e6 : u == "gb" ? 1e9 : u == "tb" ? 1e12 : 1)
        }
        $1 == "A" { a_cpu[$3] = $4; a_sys[$3] = $5 }
        $1 == "B" { b_cpu[$3] = $4; b_sys[$3] = $5; mem[$3] = $6; lim[$3] = $7; bi[$3] = $9; bo[$3] = $10; pids[$3] = $11
                    split($8, nio, " / "); ni[$3] = sprintf("%.0f", bytes(nio[1])); no[$3] = sprintf("%.0f", bytes(nio[2])) }
        $1 == "V" { if (version == "") version = $3; ctxs = ctxs (ctxs ? "," : "") "\"" esc($2) "\"" }
        $1 == "E" { errs = errs (errs ? "; " : "") $2 ": " $3 }
        $1 == "C" { n++; c_ctx[n] = $2; c_id[n] = $3; c_line[n] = $0 }
        $1 == "P" { pods = pods sprintf("{\"name\":\"%s\",\"user\":\"%s\",\"status\":\"%s\",\"containers\":%d},", esc($3), esc($2), esc($4), num($5)) }
        $1 == "D" { t = tolower($3); df_size[t] += num($4); reclaim += num($5); if (t == "images") images += num($6) }
        $1 == "M" { cvol[$3] = $4 }
        $1 == "Z" { vsize[$2] = num($3) }
        END {
            out = ""
            for (i = 1; i <= n; i++) {
                split(c_line[i], f, "|")
                if (f[11] == "true") continue            # infra-container van een pod
                id = f[3]; health = ""
                if (f[7] ~ /\(healthy\)/) health = "healthy"; else if (f[7] ~ /\(unhealthy\)/) health = "unhealthy"; else if (f[7] ~ /\(starting\)/) health = "starting"
                cpu = "null"
                if ((id in a_cpu) && (id in b_cpu) && b_sys[id] > a_sys[id])
                    cpu = sprintf("%.2f", (b_cpu[id] - a_cpu[id]) / (b_sys[id] - a_sys[id]) * 100)
                vb = 0; nv = split(cvol[id], vl, ";")
                for (j = 1; j <= nv; j++) if (vl[j] != "") vb += vsize[vl[j]]
                out = out sprintf("{\"name\":\"%s\",\"user\":\"%s\",\"pod\":\"%s\",\"image\":\"%s\",\"state\":\"%s\",\"status\":\"%s\",\"health\":\"%s\",\"exit_code\":%d,\"restarts\":%d,\"started\":%.0f,\"cpu\":%s,\"mem\":%s,\"mem_limit\":%s,\"net_in\":%s,\"net_out\":%s,\"blk_in\":%s,\"blk_out\":%s,\"pids\":%s,\"size_rw\":%.0f,\"volumes\":%.0f},",
                    esc(f[4]), esc(f[2]), esc(f[10]), esc(f[5]), esc(f[6]), esc(f[7]), health, num(f[8]), num(f[9]), num(f[12]), cpu,
                    (id in mem) ? num(mem[id]) : "null", (id in lim) ? num(lim[id]) : "null", (id in ni) ? num(ni[id]) : "null",
                    (id in no) ? num(no[id]) : "null", (id in bi) ? num(bi[id]) : "null", (id in bo) ? num(bo[id]) : "null",
                    (id in pids) ? num(pids[id]) : "null", bytes(f[13]), vb)
            }
            sub(/,$/, "", out); sub(/,$/, "", pods)
            printf "{\"version\":\"%s\",\"error\":\"%s\",\"users\":[%s],\"images\":%d,\"images_size\":%.0f,\"containers_size\":%.0f,\"volumes_size\":%.0f,\"reclaimable\":%.0f,\"pods\":[%s],\"containers\":[%s]}",
                esc(version), esc(errs), ctxs, images, df_size["images"], df_size["containers"], df_size["local volumes"], reclaim, pods, out
        }' <<<"$podman_raw")
fi

# ---------------------------------------------------------------- Certificaten
# Per bron: onderwerp (CN), uitgever, vervaldatum (epoch) en of het certificaat vertrouwd wordt.
# RzMon rekent zelf het aantal dagen uit, dus een uur oude gegevens zijn geen probleem.
cert_line() {  # bron cn uitgever not_after verify fout
    printf '{"source":"%s","cn":"%s","issuer":"%s","not_after":%s,"verify":%s,"error":"%s"},' \
        "$(json_escape "$1")" "$(json_escape "$2")" "$(json_escape "$3")" "${4:-null}" \
        "$( [ -n "$5" ] && printf '"%s"' "$(json_escape "$5")" || echo null)" "$(json_escape "$6")"
}
# Leest een PEM-certificaat van stdin en zet cn, issuer en not_after
parse_cert() {
    local info
    info=$(openssl x509 -noout -enddate -subject -issuer -nameopt RFC2253 2>/dev/null) || return 1
    not_after=$(date -d "$(sed -n 's/^notAfter=//p' <<<"$info")" +%s 2>/dev/null) || return 1
    cn=$(sed -n 's/^subject=//p' <<<"$info" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
    issuer=$(sed -n 's/^issuer=//p' <<<"$info" | grep -o 'O=[^,]*' | head -1 | cut -c3-)
    [ -n "$issuer" ] || issuer=$(sed -n 's/^issuer=//p' <<<"$info" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
}
collect_certs() {
    local entry f host port out code rc
    for entry in $CERTS; do
        if [[ "$entry" == /* ]]; then
            # Bestand(en); een wildcard zonder treffers is geen fout (bv. geen Let's Encrypt)
            if [[ "$entry" == *[*?[]* ]]; then
                compgen -G "$entry" >/dev/null || continue
            fi
            for f in $entry; do
                if [ ! -r "$f" ]; then
                    cert_line "$f" "" "" "" "" "bestand niet gevonden of niet leesbaar"
                elif parse_cert <"$f"; then
                    cert_line "$f" "$cn" "$issuer" "$not_after" "" ""
                else
                    cert_line "$f" "" "" "" "" "geen geldig certificaat (PEM) in dit bestand"
                fi
            done
        else
            # host:poort (zonder poort: 443); [IPv6]:poort mag ook
            entry=${entry#*://}; entry=${entry%%/*}
            if [[ "$entry" == \[*\]:* ]]; then host=${entry%]:*}; host=${host#[}; port=${entry##*]:}
            elif [[ "$entry" == *:* && "$entry" != *:*:* ]]; then host=${entry%:*}; port=${entry##*:}
            else host=${entry#[}; host=${host%]}; port=443; fi
            local src="$host:$port"
            [[ "$host" == *:* ]] && src="[$host]:$port"
            out=$(timeout 15 openssl s_client -connect "$src" -servername "$host" -verify_hostname "$host" </dev/null 2>&1)
            rc=$?
            if parse_cert <<<"$out"; then
                code=$(sed -n 's/^ *Verify return code: \([0-9]*\) (\(.*\))/\1 \2/p' <<<"$out" | tail -1)
                [ "${code%% *}" = 0 ] && code=ok || code=${code#* }
                cert_line "$src" "$cn" "$issuer" "$not_after" "${code:-onbekend}" ""
            else
                case "$out" in
                    *"Connection refused"*) out="poort dicht (verbinding geweigerd)" ;;
                    *"not known"*|*"No address"*|*"nodename nor servname"*) out="naam $host onbekend (DNS)" ;;
                    *"No route"*|*"unreachable"*) out="server onbereikbaar" ;;
                    *"wrong version number"*|*"packet length too long"*) out="op deze poort draait geen TLS" ;;
                    *) [ "$rc" = 124 ] && out="geen antwoord binnen 15 seconden" || out="${out%%$'\n'*}"; out=${out:0:120} ;;
                esac
                cert_line "$src" "" "" "" "" "geen TLS-verbinding: $out"
            fi
        fi
    done
}
certs=""
if [ -n "$CERTS" ]; then
    if ! command -v openssl >/dev/null 2>&1; then
        certs=$(cert_line "openssl" "" "" "" "" "openssl ontbreekt op deze server (installeer het pakket openssl)")
    else
        # Vaste cachemap (systemd: CacheDirectory); /tmp is bij systemd per run leeg (PrivateTmp)
        cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
        mkdir -p "$cache_dir" 2>/dev/null && [ -w "$cache_dir" ] || cache_dir="${TMPDIR:-/tmp}"
        cache="$cache_dir/certs.cache"
        key=$(printf '%s' "$CERTS" | cksum | cut -d' ' -f1)
        # Cache: regel 1 = sleutel van CERTS, regel 2 = tijdstip, regel 3 = resultaat
        if [ "$TEST" = 0 ] && [ -r "$cache" ] && [ "$(sed -n 1p "$cache")" = "$key" ] \
            && [ $(( $(date +%s) - $(sed -n 2p "$cache") )) -lt "$CERT_INTERVAL" ]; then
            certs=$(sed -n 3p "$cache")
        else
            certs=$(collect_certs)
            (umask 077; printf '%s\n%s\n%s\n' "$key" "$(date +%s)" "$certs" >"$cache") 2>/dev/null || true
        fi
    fi
fi

# ---------------------------------------------------------------- Bijwerken op afstand
# Kan deze agent zichzelf bijwerken, en hoe? Gestart door systemd (afgeschermd: /usr is alleen-lezen)
# → via systemd-run als aparte taak; via cron of met de hand → direct.
upd_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
upd_setup="$AGENT_SETUP"
if [ "$TEST" = 1 ]; then
    upd_via="test"   # verbindingstest: RzMon stuurt dan geen update-opdracht
elif [ "$HA_MODE" = 1 ]; then
    upd_via=ha
elif [ "$REMOTE_UPDATE" != 1 ]; then
    upd_via=off
elif [ "$(id -u)" != 0 ] || [ ! -f "$upd_setup" ] || ! command -v sha256sum >/dev/null 2>&1; then
    upd_via=none
elif [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
    upd_via=systemd
else
    upd_via=direct
fi
# Uitkomst van de vorige update terugmelden (één regel: "ok <oud> <nieuw>" of "error <reden>")
upd_result=""
if [ -r "$upd_dir/update.result" ]; then
    upd_result=$(head -c 300 "$upd_dir/update.result" | head -1)
fi

remote_update() {  # remote_update <antwoord van RzMon>
    local want sha_a sha_s k
    want=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"version":[[:space:]]*"\([0-9.]*\)".*/\1/p' <<<"$1")
    sha_a=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"agent_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    sha_s=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"setup_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    case "$upd_via" in systemd|direct) ;; *) return 0 ;; esac
    [ -n "$want" ] && [ -n "$sha_a" ] && [ -n "$sha_s" ] || return 0
    mkdir -p "$upd_dir" 2>/dev/null && [ -w "$upd_dir" ] || return 0
    # Loopt er al een update (minder dan 10 minuten geleden gestart)? Dan niet nog een.
    if [ -f "$upd_dir/update.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$upd_dir/update.lock" 2>/dev/null || echo 0) )) -lt 600 ]; then
        return 0
    fi
    : >"$upd_dir/update.lock"
    upd_fail() {
        printf 'error %s\n' "$1" >"$upd_dir/update.result"
        rm -f "$upd_dir/update.lock" "$upd_dir/agent.new" "$upd_dir/setup.new"
    }
    k=(-fsS -m 60)
    [ "$INSECURE" = 1 ] && k+=(-k)
    if ! curl "${k[@]}" -o "$upd_dir/agent.new" "${MONITOR_URL%/}/api.php?action=agent_script" 2>/dev/null \
        || ! curl "${k[@]}" -o "$upd_dir/setup.new" "${MONITOR_URL%/}/api.php?action=agent_install" 2>/dev/null; then
        upd_fail "downloaden van de nieuwe versie bij RzMon mislukt"
        return 0
    fi
    if [ "$(sha256sum "$upd_dir/agent.new" | cut -d' ' -f1)" != "$sha_a" ] || [ "$(sha256sum "$upd_dir/setup.new" | cut -d' ' -f1)" != "$sha_s" ]; then
        upd_fail "controlegetal (SHA-256) klopt niet: download onvolledig of onderweg gewijzigd"
        return 0
    fi
    if ! bash -n "$upd_dir/agent.new" 2>/dev/null || ! bash -n "$upd_dir/setup.new" 2>/dev/null \
        || ! grep -q "^AGENT_VERSION=\"$want\"" "$upd_dir/agent.new" || ! grep -q 'RzMon agent' "$upd_dir/setup.new"; then
        upd_fail "de download is geen geldige RzMon-agent $want"
        return 0
    fi
    chmod 700 "$upd_dir/setup.new"
    # Het installatiescript doet de rest (zoals bij sudo rzmon-agent-setup --update), met de gecontroleerde bestanden
    local args=(/bin/bash "$upd_dir/setup.new" --update --yes --file "$upd_dir/agent.new" --result "$upd_dir/update.result")
    if [ "$upd_via" = systemd ]; then
        systemd-run --quiet --collect --unit="rzmon-agent-update-$(date +%s)" "${args[@]}" >/dev/null 2>&1 \
            || upd_fail "systemd-run mislukt; werk bij met: sudo rzmon-agent-setup --update"
    else
        setsid "${args[@]}" </dev/null >/dev/null 2>&1 &
    fi
}

# ---------------------------------------------------------------- Versturen
payload="{\"agent_version\":\"$AGENT_VERSION\",\"hostname\":\"$(json_escape "$hostname")\",\"cpu\":{\"pct\":$cpu_pct,\"load\":[$l1,$l5,$l15],\"cores\":$cores},\"mem\":$mem,\"disks\":[${disks%,}],\"ports\":[${ports%,}],\"services\":{${services%,}},\"certs\":[${certs%,}]${podman_json:+,\"podman\":$podman_json},\"svc\":{${svc_detail%,}},\"svc_tests\":{${svc_tests%,}},\"logs\":{${log_results%,}},\"cmd\":{${cmd_results%,}},${auth_json}${ids_json}\"svc_list\":[${svc_list%,}],${ha_json:+\"ha\":$ha_json,}\"update\":{\"via\":\"$upd_via\"}${upd_result:+,\"update_result\":\"$(json_escape "$upd_result")\"}}"

curl_opts=(-fsS -m 20 -X POST)
[ "$INSECURE" = 1 ] && curl_opts+=(-k)
# Rapport via een tijdelijk bestand versturen, niet als argument: op sommige systemen (TrueNAS SCALE)
# wordt curl met een lang argument stil afgebroken en komt er niets aan.
payload_file=$(mktemp "${TMPDIR:-/tmp}/rzmon-report.XXXXXX") || { echo "Kan geen tijdelijk bestand maken" >&2; exit 1; }
trap 'rm -f "$payload_file"' EXIT
printf '%s' "$payload" >"$payload_file"

if [ "$TEST" = 1 ]; then
    # 7. Verzamelde gegevens tonen
    n_disks=$(grep -o '"mount"' <<<"$disks" | wc -l)
    n_ports=$(grep -o '"port"' <<<"$ports" | wc -l)
    pass "Gegevens verzameld: CPU ${cpu_pct}% (${cores} cores), $n_disks disk(s), $n_ports luisterende poort(en)"
    [ -n "$services" ] && hint "Services: $(sed 's/"//g; s/,$//; s/,/, /g' <<<"$services")"
    command -v ss >/dev/null 2>&1 || hint "ss ontbreekt: poorten worden niet gemeld (Rocky: dnf install iproute)"
    if [ -n "$svc_detail" ]; then
        pass "Diepere controle van services:"
        # Per service: poorten en het resultaat van de test (✘ = probleem)
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${svc_detail%,}" | sed -nE \
            's/^"([^"]+)":\{"sub":"running".*"ports":\[([0-9,]*)\],"ports_fail":\[([0-9,]*)\].*"sockets":\[([^]]*)\],"sockets_fail":\[([^]]*)\].*"probe_rc":([0-9]|null),"probe_msg":"([^"]*)".*"log_errors":([0-9]+|null).*/\1|\2|\3|\4|\5|\6|\7|\8/p' |
            while IFS='|' read -r n p pf so sf rc m le; do
                printf '%s %s: poorten %s%s%s%s%s\n' "$( [ -n "$pf$sf" ] || [ "$rc" = 2 ] && echo ✘ || echo ✔)" "$n" "${p:-geen}" \
                    "${pf:+ (dicht: $pf)}" "$( [ -n "$sf" ] && echo " · socket: ${sf//\"/}" || { [ -n "$so" ] && echo " · socket ${so//\"/} ok"; })" \
                    "${m:+ · $m}" "$( [ "$le" != null ] && [ "$le" != 0 ] && echo " · $le fout(en) in log")"
            done)
    fi
    if [ -n "$cmd_results" ]; then
        pass "Commando-checks:"
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${cmd_results%,}" | sed -nE \
            -e 's/^"([0-9]+)":\{"blocked":"([^"]*)".*/check \1: geblokkeerd (\2)/p' \
            -e 's/^"([0-9]+)":\{"error":"([^"]*)".*/check \1: \2/p' \
            -e 's/^"([0-9]+)":\{"rc":(-?[0-9]+),"out":"(([^"\\]|\\.){0,60}).*"ms":([0-9]+),"as":"([a-z]+)".*/check \1: exitcode \2 in \5 ms als \6: \3/p')
    elif grep -q $'^[0-9]*\tcmd\t' "${t_cache:-/dev/null}" 2>/dev/null; then
        hint "Commando-checks: nog niets uitgevoerd"
    fi
    if [ -n "$log_results" ]; then
        pass "Logbestanden doorzocht:"
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${log_results%,}" |
            sed -nE 's/^"([0-9]+)":\{"new":([0-9]+).*"error":"([^"]*)"\}?$/\1|\2|\3/p' |
            while IFS='|' read -r i n e; do
                if [ -n "$e" ]; then echo "✘ zoekopdracht $i: $e"; else echo "zoekopdracht $i: $n nieuwe treffer(s)"; fi
            done)
    fi
    if [ -n "$podman_json" ]; then
        n_run=$(grep -o '"state":"running"' <<<"$podman_json" | wc -l)
        n_all=$(grep -o '"state":"' <<<"$podman_json" | wc -l)
        pass "Podman $(sed -n 's/^{"version":"\([^"]*\)".*/\1/p' <<<"$podman_json"): $n_run van $n_all container(s) draaien (${podman_ctx[*]})"
        p_err=$(sed -n 's/^{"version":"[^"]*","error":"\([^"]*\)".*/\1/p' <<<"$podman_json")
        [ -n "$p_err" ] && note "Podman-fout: $p_err"
    elif command -v podman >/dev/null 2>&1; then
        hint "Podman staat erop, maar wordt niet gemeld (PODMAN=0 of HA-modus)"
    fi
    if [ -n "$certs" ]; then
        n_certs=$(grep -o '"source"' <<<"$certs" | wc -l)
        pass "Certificaten: $n_certs uitgelezen"
        while IFS= read -r line; do
            hint "$line"
        done < <(sed 's/},{/}\n{/g' <<<"${certs%,}" | sed -E 's/^\{"source":"([^"]*)","cn":"([^"]*)",.*"not_after":([0-9]+|null).*"error":"([^"]*)"\}?,?$/\1|\2|\3|\4/' |
            while IFS='|' read -r src cn na err; do
                if [ -n "$err" ]; then echo "✘ $src: $err"
                else echo "$src: ${cn:-?}, nog $(( (na - $(date +%s)) / 86400 )) dagen geldig"; fi
            done)
    elif [ -n "$CERTS" ]; then
        hint "Certificaten: geen gevonden voor CERTS=\"$CERTS\""
    fi

    # 8. Echt rapport versturen
    resp=$(curl "${curl_k[@]}" -m 20 -X POST -H "Content-Type: application/json" -H "X-Agent-Token: $TOKEN" \
        --data-binary "@$payload_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent" 2>&1)
    r_rc=$?
    r_code=${resp##*$'\n'}; r_body=${resp%$'\n'*}
    if [ -z "$resp" ]; then
        fail "Rapport niet verstuurd: curl stopte zonder uitvoer (exitcode $r_rc, rapport $(wc -c <"$payload_file") bytes)"
    elif [ "$r_code" = 200 ] && [[ "$r_body" == *'"ok":true'* ]]; then
        pass "Rapport ontvangen door RzMon: $r_body"
        echo
        echo "  Alles werkt. Zie je in RzMon toch niets? Klap de server open → 'Agent Manager':"
        echo "  daar staat wanneer het laatste rapport binnenkwam en vanaf welk IP."
    else
        fail "Rapport niet geaccepteerd (HTTP $r_code): ${r_body:0:200}"
    fi
    exit "$TEST_FAILED"
fi

resp=$(curl "${curl_opts[@]}" \
    -H "Content-Type: application/json" \
    -H "X-Agent-Token: $TOKEN" \
    --data-binary "@$payload_file" \
    "${MONITOR_URL%/}/api.php?action=agent")
rc=$?
printf '%s\n' "$resp"
if [ "$rc" -eq 0 ]; then
    # Uitkomst is doorgegeven; vraagt RzMon om bij te werken?
    [ -n "$upd_result" ] && rm -f "$upd_dir/update.result"
    # IDS, bestanden bewaken: RzMon heeft de wijzigingen; dit is nu de stand om mee te vergelijken
    if [ -n "$fim_commit" ]; then
        mv -f "$fim_dir/state.new" "$fim_dir/state" && mv -f "$fim_dir/meta.new" "$fim_dir/meta" && mv -f "$fim_dir/roots.new" "$fim_dir/roots"
        [ "$fim_rh" != /dev/null ] && touch "$i_dir/rehash.used"
    fi
    # Instellingen in RzMon gewijzigd (bv. een service-check toegevoegd)? Lijst bij de volgende run opnieuw ophalen.
    rev=$(sed -n 's/.*"tests_rev":[[:space:]]*"\([0-9a-f]\{8,64\}\)".*/\1/p' <<<"$resp")
    if [ -n "$rev" ] && [ -n "${t_cache-}" ] && [ -r "$t_cache" ] && [ "$(sha1sum "$t_cache" | cut -c1-12)" != "$rev" ]; then
        rm -f "$t_cache"
    fi
    [[ "$resp" =~ \"update\":[[:space:]]*\{ ]] && remote_update "$resp"
fi
exit "$rc"
