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
# Verbindingstest:  sudo rzmon-agent.sh --test

# Versie: wordt bijgewerkt door tools/bump-version.sh (gelijk aan VERSION van RzMon)
AGENT_VERSION="1.21.2"

TEST=0
[ "${1-}" = "--test" ] && TEST=1
if [ "${1-}" = "--version" ]; then
    echo "rzmon-agent $AGENT_VERSION"
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
SUPERVISOR_URL="${SUPERVISOR_URL:-http://supervisor}"

HA_MODE=0
[ -n "${SUPERVISOR_TOKEN:-}" ] && HA_MODE=1

json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

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
# Er worden nooit commando's van RzMon uitgevoerd: alleen de status van services wordt opgevraagd.
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
upd_setup=/usr/local/sbin/rzmon-agent-setup
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
payload="{\"agent_version\":\"$AGENT_VERSION\",\"hostname\":\"$(json_escape "$hostname")\",\"cpu\":{\"pct\":$cpu_pct,\"load\":[$l1,$l5,$l15],\"cores\":$cores},\"mem\":$mem,\"disks\":[${disks%,}],\"ports\":[${ports%,}],\"services\":{${services%,}},\"certs\":[${certs%,}]${podman_json:+,\"podman\":$podman_json},\"svc\":{${svc_detail%,}},\"svc_tests\":{${svc_tests%,}},\"logs\":{${log_results%,}},\"svc_list\":[${svc_list%,}],\"update\":{\"via\":\"$upd_via\"}${upd_result:+,\"update_result\":\"$(json_escape "$upd_result")\"}}"

curl_opts=(-fsS -m 20 -X POST)
[ "$INSECURE" = 1 ] && curl_opts+=(-k)

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
        --data "$payload" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent" 2>&1 || true)
    r_code=${resp##*$'\n'}; r_body=${resp%$'\n'*}
    if [ "$r_code" = 200 ] && [[ "$r_body" == *'"ok":true'* ]]; then
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
    --data "$payload" \
    "${MONITOR_URL%/}/api.php?action=agent")
rc=$?
printf '%s\n' "$resp"
if [ "$rc" -eq 0 ]; then
    # Uitkomst is doorgegeven; vraagt RzMon om bij te werken?
    [ -n "$upd_result" ] && rm -f "$upd_dir/update.result"
    # Instellingen in RzMon gewijzigd (bv. een service-check toegevoegd)? Lijst bij de volgende run opnieuw ophalen.
    rev=$(sed -n 's/.*"tests_rev":[[:space:]]*"\([0-9a-f]\{8,64\}\)".*/\1/p' <<<"$resp")
    if [ -n "$rev" ] && [ -n "${t_cache-}" ] && [ -r "$t_cache" ] && [ "$(sha1sum "$t_cache" | cut -c1-12)" != "$rev" ]; then
        rm -f "$t_cache"
    fi
    [[ "$resp" =~ \"update\":[[:space:]]*\{ ]] && remote_update "$resp"
fi
exit "$rc"
