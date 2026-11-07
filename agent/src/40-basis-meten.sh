# ---------------------------------------------------------------- Instellingen uit RzSense en de tik
# Wat in RzSense is ingesteld: welke services bewaakt worden (service-checks), extra tests (URL/poort),
# de eigen poorten of sockets van een service, de logzoekopdrachten, de intervallen per check en de tik.
# De lijst wordt elke 10 minuten bij RzSense opgehaald, en meteen als RzSense meldt dat er iets veranderd is
# (tests_rev in het antwoord). Commando's van RzSense worden alleen uitgevoerd bij commando-checks, als RzSense
# ze voor deze server toestaat en COMMANDS (hierboven) dat niet blokkeert; zie "Commando-checks" verderop.
if [ "$TEST" = 1 ] || [ ! -r "$t_cache" ] || [ -e "$t_dir/tests.stale" ] || [ $(( $(date +%s) - $(stat -c %Y "$t_cache") )) -ge 600 ]; then
    get_auth agent_tests
    t_k=("${tls[@]}" "${ga[@]}"); t_n=$get_n
    # Alleen een complete lijst bewaren (met de vaste regel "0 <tab> tick"). Geeft RzSense iets anders terug,
    # bijvoorbeeld tijdens een update, dan blijft de vorige lijst staan: anders zou de agent denken dat het
    # IDS uit staat en de blokkades opheffen.
    # Met een sleutel moet de lijst versleuteld terugkomen; anders is hij niet te vertrouwen
    if t_list=$(curl -fsS "${t_k[@]}" -m 10 "${MONITOR_URL%/}/api.php?action=agent_tests" 2>/dev/null) \
        && { if [[ "$t_list" == '{"rzenc":'* ]]; then t_list=$(unseal "$t_list" "$t_n"); else [ -z "$t_n" ]; fi; } \
        && grep -q $'^0\ttick\t[0-9]' <<<"$t_list"; then
        (umask 077; printf '%s\n' "$t_list" >"$t_cache") 2>/dev/null && rm -f "$t_dir/tests.stale"
    fi
fi
# Tik: hoe vaak er een rapport uitgaat (regel "0 <tab> tick <tab> seconden", per server in RzSense in te stellen;
# anders INTERVAL). De timer start de agent vaker (elke 30 s); is de tik nog niet om, dan meteen stoppen.
tick=$(awk -F'\t' '$1 == "0" && $2 == "tick" { print $3; exit }' "$t_cache" 2>/dev/null)
[[ "$tick" =~ ^[0-9]+$ ]] && [ "$tick" -ge 30 ] || tick=${INTERVAL:-60}
[[ "$tick" =~ ^[0-9]+$ ]] || tick=60
if [ "$TEST" != 1 ] && [ "${RZ_FORCE-}" != 1 ] && [ -f "$t_dir/last.run" ] && [ $(( $(date +%s) - $(stat -c %Y "$t_dir/last.run") )) -lt $(( tick - 10 )) ]; then
    exit 0
fi
# Nooit twee runs tegelijk (timer en realtime-watcher): even wachten op een run die al bezig is
# Agent 1.68.0: de test wacht ook even (anders zitten twee runs aan dezelfde tijdelijke bestanden), maar gaat altijd door
if [ "$TEST" = 1 ] && command -v flock >/dev/null 2>&1; then
    exec 9>"$t_dir/run.lock"
    flock -w 25 9 2>/dev/null || true
elif [ "$TEST" != 1 ] && command -v flock >/dev/null 2>&1; then
    exec 9>"$t_dir/run.lock"
    # BusyBox-flock (Alpine, de Home Assistant-add-on) kent -w niet: dan zonder wachten proberen.
    # Lukt het allebei niet, dan loopt er al een run en slaan we deze keer over.
    if ! flock -w 25 9 2>/dev/null && ! flock -n 9 2>/dev/null; then
        exit 0
    fi
fi
touch "$t_dir/last.run" 2>/dev/null
run_t0=$(now_ms)
# Agent 1.69.0: duurt deze run langer dan de tik (een trage check, een druk systeem), dan elke tik een kleine hartslag
# "ik ben bezig" naar RzSense. Zo is de agent niet "stil" terwijl hij gewoon werkt; zijn gegevens blijven tot het rapport komt.
beat_pid=""
if [ "$TEST" != 1 ] && [ "${RZ_NO_BEAT-}" != 1 ]; then
    (
        b_since=$(date +%s)
        sleep "$tick"
        while kill -0 "$$" 2>/dev/null; do
            b_file=$(mktemp "${TMPDIR:-/tmp}/rzmon-beat.XXXXXX") || exit 0
            printf '{"beat":1,"agent_version":"%s","hostname":"%s","busy_since":%s}' "$AGENT_VERSION" "$(json_escape "$(hostname)")" "$b_since" >"$b_file"
            b_send=$b_file
            if [ -n "$enc_key" ] && seal "$b_file" "$b_file.enc"; then b_send="$b_file.enc"; fi
            curl -fsS -m 10 -X POST "${tls[@]}" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$b_send" \
                "${MONITOR_URL%/}/api.php?action=agent" >/dev/null 2>&1
            rm -f "$b_file" "$b_file.enc"
            sleep "$tick"
        done
    ) 9>&- </dev/null >/dev/null 2>&1 &   # 1.73.0: zonder de run-vergrendeling (fd 9), anders houdt zijn sleep die vast
    beat_pid=$!
fi
# Agent 1.69.0: hoe lang elk onderdeel duurde (gaat mee naar RzSense: Agent Manager toont wat traag is)
lap_t=$run_t0; lap_name=""; laps=""
lap() { local t; t=$(now_ms); [ -n "$lap_name" ] && laps+="\"$lap_name\":$(( t - lap_t )),"; lap_name=$1; lap_t=$t; }
# Agent 1.67.0: PID van deze run, zodat de endpoint-bewaking wat de agent start als "van de agent" herkent (de laatste 8)
[ "$TEST" != 1 ] && { tail -n 7 "$t_dir/own.pids" 2>/dev/null; echo "$$"; } >"$t_dir/own.pids.tmp" 2>/dev/null && mv -f "$t_dir/own.pids.tmp" "$t_dir/own.pids" 2>/dev/null

# ---------------------------------------------------------------- Disk
lap disk
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
lap containers_prep
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
# Agent 1.69.0: Docker als extra context "docker" (alleen als de Docker-daemon draait)
if [ "$DOCKER" != 0 ] && [ "$HA_MODE" = 0 ] && command -v docker >/dev/null 2>&1 && timeout 5 docker info >/dev/null 2>&1; then
    podman_ctx+=(docker)
fi
pm() {  # pm <context> <podman-argumenten…>: podman als root, of als die gebruiker (rootless); context docker = docker
    local ctx=$1 uid
    shift
    if [ "$ctx" = docker ]; then
        timeout 60 docker "$@"
    elif [ "$ctx" = root ] || [ "$ctx" = "$(id -un)" ]; then
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
# Docker meet CPU zelf (in %): die waarde gaat als twee "metingen" (0 en pct·10⁶ op 10⁸) door dezelfde berekening.
# Geheugen staat bij Docker in MiB/GiB, netwerk en disk in kB/MB: hier alles naar bytes.
docker_bytes='function db(s,  n, u) { if (!match(s, /^[0-9.]+[a-zA-Z]*/)) return 0; s = substr(s, 1, RLENGTH); n = s + 0; u = s; sub(/^[0-9.]+/, "", u); u = tolower(u)
    return n * (u == "kib" ? 1024 : u == "mib" ? 1048576 : u == "gib" ? 1073741824 : u == "tib" ? 1099511627776 : u == "kb" ? 1e3 : u == "mb" ? 1e6 : u == "gb" ? 1e9 : u == "tb" ? 1e12 : 1) }'
podman_stats() {  # podman_stats [a|b]
    local ctx
    for ctx in "${podman_ctx[@]}"; do
        if [ "$ctx" = docker ]; then
            if [ "${1:-b}" = a ]; then
                pm docker ps -q --no-trunc 2>/dev/null | sed 's/^/S|docker|/; s/$/|0|0|0|0|0B \/ 0B|0|0|0/'
            else
                pm docker stats --no-stream --no-trunc --format '{{.ID}}|{{.CPUPerc}}|{{.MemUsage}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}' 2>/dev/null |
                    awk -F'|' "$docker_bytes"'{ c = $2; sub(/%/, "", c); split($3, m, " / "); split($5, b, " / ")
                        printf "S|docker|%s|%.0f|100000000|%.0f|%.0f|%s|%.0f|%.0f|%s\n", $1, c * 1e6, db(m[1]), db(m[2]), $4, db(b[1]), db(b[2]), $6 }'
            fi
            continue
        fi
        pm "$ctx" stats --no-stream --no-trunc --format '{{.ContainerStats.ContainerID}}|{{.ContainerStats.CPUNano}}|{{.ContainerStats.SystemNano}}|{{.ContainerStats.MemUsage}}|{{.ContainerStats.MemLimit}}|{{.NetIO}}|{{.ContainerStats.BlockInput}}|{{.ContainerStats.BlockOutput}}|{{.ContainerStats.PIDs}}' 2>/dev/null \
            | sed "s/^/S|$ctx|/"
    done
}
pod_stats_a=""
[ ${#podman_ctx[@]} -gt 0 ] && pod_stats_a=$(podman_stats a)

# ---------------------------------------------------------------- CPU
lap cpu
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
lap ram
# Geheugen uit /proc/meminfo (kB -> bytes)
mem=$(awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ { v[$1] = $2 * 1024 }
    END { printf "{\"total\":%.0f,\"avail\":%.0f,\"swap_total\":%.0f,\"swap_free\":%.0f}",
          v["MemTotal:"], v["MemAvailable:"], v["SwapTotal:"], v["SwapFree:"] }' /proc/meminfo)

# ---------------------------------------------------------------- Poorten
lap ports
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

# Wat in RzSense is ingesteld (zie "Instellingen uit RzSense" bovenaan): hier alleen de regels voor services.
declare -A manual_ports=()
declare -A ival_by_id=()   # interval per check (s), voor zware onderdelen: diepere controle, extra tests, logs
rz_services=""
[ -r "${t_cache-}" ] && while IFS=$'\t' read -r t_id t_type t_target t_expect; do
    [ "$t_type" = ival ] && [[ "$t_id" =~ ^[0-9]+$ ]] && [[ "$t_target" =~ ^[0-9]+$ ]] && ival_by_id[$t_id]=$t_target
    [ "$HA_MODE" = 0 ] || continue
    [ "$t_type" = ports ] && manual_ports["$t_target"]+=" $t_expect"
    [ "$t_type" = svc ] && [[ "$t_target" =~ ^[A-Za-z0-9@._:-]+$ ]] && rz_services+=" $t_target"
done <"$t_cache"
# Interval per service (via de check-id van de service-check; bij meer checks op dezelfde service: de kortste)
declare -A ival_by_svc=()
[ -r "${t_cache-}" ] && while IFS=$'\t' read -r t_id t_type t_target _; do
    [ "$t_type" = svc ] && [ -n "${ival_by_id[$t_id]-}" ] || continue
    [ -z "${ival_by_svc[$t_target]-}" ] || [ "${ival_by_id[$t_id]}" -lt "${ival_by_svc[$t_target]}" ] && ival_by_svc[$t_target]=${ival_by_id[$t_id]}
done <"$t_cache"
due_cached() {  # due_cached <bestand> <interval>: 0 = vorige uitkomst is nog vers genoeg (niet opnieuw doen)
    [ -n "$2" ] && [ -s "$1" ] && [ $(( $(date +%s) - $(stat -c %Y "$1") )) -lt $(( $2 - 5 )) ]
}
# Te bewaken services: die met een service-check in RzSense, plus (verouderd) SERVICES uit de instellingen
svc_all=$(printf '%s\n' $SERVICES $rz_services | awk 'NF && !seen[$0]++' | tr '\n' ' ')

# ---------------------------------------------------------------- Zelf herstellen (agent 1.62.0)
lap heal
# Per check in RzSense aan te zetten (✎ → 🩹 Zelf herstellen). Een service of container die niet draait, herstart de
# agent hier, vóór het rapport: lukt dat, dan blijft de check in RzSense OK en komt er een ℹ️-melding; lukt het niet, dan
# gaat de gewone melding uit. Een eigen herstelcommando (HEAL_DIR) start de agent alleen op verzoek van RzSense, in een
# versleuteld antwoord, als die check misgaat (zie "Versturen"). Hooguit één poging per 10 minuten en 3 per dag per check.
heal_dir="$t_dir/heal"
heal_ok() {  # heal_ok <check-id>: mag er nu een poging?
    local f="$heal_dir/$1.tries" now n last
    now=$(date +%s)
    [ -r "$f" ] || return 0
    n=$(awk -v t=$(( now - 86400 )) '$1 > t' "$f" | wc -l)
    last=$(tail -n 1 "$f")
    [ "$n" -lt 3 ] && [ $(( now - ${last:-0} )) -ge 600 ]
}
heal_log() {  # heal_log <check-id> <ok: 0/1> <bericht>: poging onthouden, en de uitkomst gaat mee met het volgende rapport
    mkdir -p "$heal_dir" 2>/dev/null || return 0
    date +%s >>"$heal_dir/$1.tries"
    tail -n 5 "$heal_dir/$1.tries" >"$heal_dir/$1.tmp" 2>/dev/null && mv -f "$heal_dir/$1.tmp" "$heal_dir/$1.tries"
    printf '{"id":%s,"ok":%s,"t":%s,"m":"%s"}\n' "$1" "$2" "$(date +%s)" "$(json_escape "$(printf '%s' "$3" | tr '\n\t' '  ' | cut -c1-200)")" >>"$heal_dir/done"
}
heal_cmd_ok() {  # heal_cmd_ok <pad>: alleen uitvoerbaar, van root en niet door anderen te wijzigen
    [ -f "$1" ] && [ ! -L "$1" ] && [ -x "$1" ] && [ "$(stat -c '%u' "$1")" = 0 ] && ! find "$1" -perm /022 | grep -q .
}
if [ "$HA_MODE" = 0 ] && [ "$HEAL" != 0 ] && [ "$TEST" != 1 ] && [ "$(id -u)" = 0 ] && [ -r "${t_cache-}" ]; then
    while IFS=$'\t' read -r h_id h_type h_kind h_target; do
        [ "$h_type" = heal ] && [[ "$h_id" =~ ^[0-9]+$ ]] || continue
        if [ "$h_kind" = svc ] && [[ "$h_target" =~ ^[A-Za-z0-9@._:-]+$ ]] && command -v systemctl >/dev/null 2>&1; then
            h_st=$(systemctl is-active "$h_target" 2>/dev/null)
            [ "$h_st" = failed ] || [ "$h_st" = inactive ] || continue
            heal_ok "$h_id" || continue
            timeout 60 systemctl restart "$h_target" >/dev/null 2>&1 && sleep 5
            h_st2=$(systemctl is-active "$h_target" 2>/dev/null)
            if [ "$h_st2" = active ]; then heal_log "$h_id" 1 "service $h_target was $h_st, herstart gelukt"
            else heal_log "$h_id" 0 "service $h_target was $h_st, herstart mislukt (nu ${h_st2:-onbekend})"; fi
        elif [ "$h_kind" = ctr ] && [[ "$h_target" =~ ^([a-z_][a-z0-9_.-]*)/([A-Za-z0-9][A-Za-z0-9_.-]*)$ ]] && [ ${#podman_ctx[@]} -gt 0 ]; then
            h_ctx=${BASH_REMATCH[1]}; h_name=${BASH_REMATCH[2]}
            [ "$h_ctx" = root ] || id -u "$h_ctx" >/dev/null 2>&1 || continue
            h_st=$(pm "$h_ctx" inspect --format '{{.State.Status}}' "$h_name" 2>/dev/null | head -n 1)
            case "$h_st" in exited|stopped|created|dead) ;; *) continue ;; esac
            heal_ok "$h_id" || continue
            pm "$h_ctx" start "$h_name" >/dev/null 2>&1 && sleep 5
            h_st2=$(pm "$h_ctx" inspect --format '{{.State.Status}}' "$h_name" 2>/dev/null | head -n 1)
            if [ "$h_st2" = running ]; then heal_log "$h_id" 1 "container $h_name was $h_st, gestart"
            else heal_log "$h_id" 0 "container $h_name was $h_st, starten mislukt (nu ${h_st2:-onbekend})"; fi
        fi
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Services
lap services
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

    # Plugins van de Supervisor (DNS, Audio, CLI, Multicast, Observer): draaien ze (statistieken beschikbaar)
    # en is er een update. De statistieken worden hieronder ook voor de containers gebruikt.
    declare -A pl_stats=()
    plugin_upd=""
    for p in dns audio cli multicast observer; do
        p_info=$(sv "/$p/info") || continue
        pl_stats[$p]=$(sv "/$p/stats" | jq -c '.data // empty' 2>/dev/null)
        services+="\"plugin:$p\":\"$([ -n "${pl_stats[$p]}" ] && echo active || echo failed)\","
        jq -e '.data.update_available == true' <<<"$p_info" >/dev/null 2>&1 && plugin_upd+=" Plugin-$p"
    done

    # Beschikbare updates (Core, Supervisor, OS, plugins en add-ons) → WARNING in RzSense
    updates=$( {
        sv /core/info | jq -r 'select(.data.update_available) | "Core \(.data.version_latest)"'
        jq -r 'select(.data.update_available) | "Supervisor"' <<<"$sup_info"
        sv /os/info | jq -r 'select(.data.update_available) | "OS \(.data.version_latest)"'
        [ -n "$addons" ] && jq -r '.data.addons[] | select(.update_available) | .name' <<<"$addons"
        for p in $plugin_upd; do echo "$p"; done
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

# Overzicht van de services op deze server (aan bij het opstarten, of draaiend), om in RzSense uit te kiezen.
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

