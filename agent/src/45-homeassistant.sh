# ---------------------------------------------------------------- Home Assistant: back-up, integraties, systeemstatus
lap ha
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

# Nieuwe logregels van de Supervisor sinds de vorige keer (/host/logs, /core/logs, /supervisor/logs, /addons/<slug>/logs).
# Hooguit de laatste 500; de eerste keer alleen vastleggen waar we zijn. Kleurcodes eruit.
ha_log_new() {  # ha_log_new <api-pad> <bestand met de laatst geziene regel>
    local out last
    out=$(curl -fsS -m 15 -H "Authorization: Bearer $SUPERVISOR_TOKEN" -H 'Accept: text/plain' -H 'Range: entries=:-499:' \
        "$SUPERVISOR_URL$1" 2>/dev/null | tail -n 500 | sed 's/\x1b\[[0-9;]*m//g') || return 1
    [ -n "$out" ] || return 0
    last=$(cat "$2" 2>/dev/null)
    tail -n 1 <<<"$out" >"$2"
    [ -n "$last" ] || return 0
    awk -v last="$last" '{ l[NR] = $0 } $0 == last { p = NR } END { for (i = p + 1; i <= NR; i++) print l[i] }' <<<"$out"
}

# ---------------------------------------------------------------- Home Assistant: OS en containers
lap ha_os
# OS: herstart (boot-tijd), levensduur van de SD-kaart/eMMC, de A/B-bootslots (mislukte OS-update) en USB-apparaten.
# Containers: Core, Supervisor, plugins en add-ons met CPU en RAM (statistieken van de Supervisor), gemeld zoals
# Podman-containers, zodat dezelfde checks en weergave werken. Herstarts: overgangen tussen twee rapporten en de
# watchdog in het Supervisor-log. Alles alleen lezen; statistieken en logs vragen de rol "manager" van de add-on.
usb_json=""
podman_ha=""
if [ "$HA_MODE" = 1 ]; then
    ha_dir="$t_dir/ha"
    mkdir -p "$ha_dir" 2>/dev/null
    hi=${host_info:-}; [ -n "$hi" ] || hi='{}'
    os_info=$(sv /os/info); [ -n "$os_info" ] || os_info='null'
    # Boot-tijd (µs, ms of s sinds 1970) → seconden; verandert hij, dan is HA OS herstart.
    # Met een andere OS-versie erna was het een OS-update (gepland), anders onverwacht.
    boot=$(jq -r '(.data.boot_timestamp // 0) | tonumber | if . > 1e14 then . / 1e6 elif . > 1e11 then . / 1e3 else . end | floor' <<<"$hi" 2>/dev/null)
    os_ver=$(jq -r '.data.version // ""' <<<"$os_info" 2>/dev/null)
    p_boot=""; p_ver=""
    [ -f "$ha_dir/os.state" ] && read -r p_boot p_ver _ <"$ha_dir/os.state"
    if [[ "$boot" =~ ^[0-9]+$ ]] && [ "$boot" -gt 0 ]; then
        if [[ "$p_boot" =~ ^[0-9]+$ ]] && [ $(( boot - p_boot )) -gt 60 ]; then
            planned=false; [ -n "$os_ver" ] && [ -n "$p_ver" ] && [ "$os_ver" != "$p_ver" ] && planned=true
            printf '{"t":%d,"prev":%d,"seen":%d,"planned":%s,"from":"%s","to":"%s"}' "$boot" "$p_boot" "$(date +%s)" "$planned" \
                "$(json_escape "$p_ver")" "$(json_escape "$os_ver")" >"$ha_dir/reboot.json"
        fi
        echo "$boot ${os_ver:--}" >"$ha_dir/os.state"
    fi
    last_reboot=$(cat "$ha_dir/reboot.json" 2>/dev/null); [ -n "$last_reboot" ] || last_reboot=null
    ha_os=$(jq -c --argjson boot "${boot:-0}" --argjson last "$last_reboot" '{boot: $boot, version: (.os.data.version? // null),
            slot: (.os.data.boot? // null), slots: (.os.data.boot_slots? // null), board: (.os.data.board? // null),
            disk_life: (.host.data.disk_life_time? // null), last_reboot: $last}' <<<"{\"os\":$os_info,\"host\":$hi}" 2>/dev/null)
    [ -n "$ha_json" ] && ha_json="${ha_json:0:${#ha_json}-1},\"os\":${ha_os:-null}}"

    # USB-apparaten (Zigbee/Z-Wave-sticks, Bluetooth): naam en vast pad (/dev/serial/by-id/…)
    usb_json=$(sv /hardware/info | jq -c '[.data.devices[]? | select(((.by_id // "") | test("/by-id/")) or .subsystem == "bluetooth")
        | {id: ((.by_id // .dev_path // .name) | split("/") | last), path: (.by_id // .dev_path // ""),
           name: (.attributes.ID_MODEL_FROM_DATABASE // .attributes.ID_MODEL // .name // ""),
           vendor: (.attributes.ID_VENDOR_FROM_DATABASE // .attributes.ID_VENDOR // "")}] | unique_by(.id)' 2>/dev/null)

    # Containers: naam <tab> weergavenaam <tab> status <tab> statistieken (JSON)
    ct_raw=$(
        s=$(sv /core/stats | jq -c '.data // empty' 2>/dev/null)
        printf '%s\t%s\t%s\t%s\n' homeassistant "Home Assistant Core" "$([ "$core_state" = active ] && echo running || echo stopped)" "${s:-null}"
        s=$(sv /supervisor/stats | jq -c '.data // empty' 2>/dev/null)
        printf '%s\t%s\t%s\t%s\n' hassio_supervisor Supervisor running "${s:-null}"
        for p in dns audio cli multicast observer; do
            [ -n "${pl_stats[$p]-}" ] && printf '%s\t%s\t%s\t%s\n' "hassio_$p" "Plugin $p" running "${pl_stats[$p]}"
        done
        [ -n "$addons" ] && jq -r '.data.addons[] | [.slug, .name, .state] | @tsv' <<<"$addons" 2>/dev/null |
            while IFS=$'\t' read -r slug name state; do
                s=""; [ "$state" = started ] && s=$(sv "/addons/$slug/stats" | jq -c '.data // empty' 2>/dev/null)
                printf '%s\t%s\t%s\t%s\n' "addon_$slug" "$name" "$state" "${s:-null}"
            done
    )
    [ -f "$ha_dir/ct.state" ] || : >"$ha_dir/ct.state"
    # Herstarts: de watchdog van de Supervisor meldt "Watchdog found addon X is failed, restarting…"
    wd=$(ha_log_new /supervisor/logs "$ha_dir/sup.mark" | grep -i 'watchdog' | grep -iE 'restart|fail|not running|problem' | tr '[:upper:]' '[:lower:]')
    ct_tsv=$(awk -F'\t' -v OFS='\t' -v now="$(date +%s)" -v wd="$wd" -v state="$ha_dir/ct.state" '
        FILENAME == ARGV[1] { ps[$1] = $2; pt[$1] = $3; pr[$1] = $4; next }
        { run = ($3 == "running" || $3 == "started"); started = pt[$1] + 0; restarts = pr[$1] + 0
          if (run && ps[$1] != "running") started = now                          # (weer) gestart sinds de vorige keer
          # watchdog: per regel; Core = "home assistant" in een regel zonder "addon", anders de naam van de add-on
          n = split(wd, wl, "\n"); hit = 0
          for (i = 1; i <= n; i++) {
              if ($1 == "homeassistant") { if (index(wl[i], "home assistant") && !index(wl[i], "addon")) hit = 1 }
              else if ($1 ~ /^addon_/ && index(wl[i], tolower($2))) hit = 1
          }
          if (hit) { restarts++; started = now }
          print $1, (run ? "running" : $3), started, restarts > state
          print $1, $2, (run ? "running" : $3), started, restarts, $4 }' "$ha_dir/ct.state" <(printf '%s\n' "$ct_raw" | grep .) 2>/dev/null)
    [ -f "$ha_dir/ct.state" ] || : >"$ha_dir/ct.state"
    ct_json=$(jq -R -s -c '[split("\n")[] | select(length > 0) | split("\t") | (.[5] | fromjson? // null) as $s
        | {name: .[0], image: .[1], state: (if .[2] == "running" then "running" else "exited" end),
           exit_code: (if .[2] == "error" then 1 else 0 end), started: (.[3] | tonumber), restarts: (.[4] | tonumber)}
        + (if $s then {cpu: $s.cpu_percent, mem: $s.memory_usage, mem_limit: $s.memory_limit, net_in: $s.network_rx,
                       net_out: $s.network_tx, blk_in: $s.blk_read, blk_out: $s.blk_write} else {} end)]' <<<"$ct_tsv" 2>/dev/null)
    [ -n "$ct_json" ] && [ "$ct_json" != "[]" ] && podman_ha="{\"version\":\"Home Assistant\",\"containers\":$ct_json}"
fi

