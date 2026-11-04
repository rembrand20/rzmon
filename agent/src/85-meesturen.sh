# ---------------------------------------------------------------- Benodigdheden voor realtime (elk uur)
lap rt_tools
# Is inotify-tools er (bestanden in realtime), en op Rocky/Alma/RHEL: staat EPEL erop? RzMon toont het in de Agent Manager.
tools_json=""
if [ "$HA_MODE" = 0 ] && [ -n "${INVOCATION_ID-}" ]; then   # alleen met systemd (realtime werkt niet via cron)
    tf="$t_dir/tools.json"
    if [ ! -s "$tf" ] || [ $(( $(date +%s) - $(stat -c %Y "$tf") )) -ge 3600 ]; then
        ino=0; command -v inotifywait >/dev/null 2>&1 && ino=1
        epel=null; fam=other
        if command -v rpm >/dev/null 2>&1 && { command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; }; then
            fam=rhel; epel=0
            { rpm -q epel-release >/dev/null 2>&1 || rpm -qa 2>/dev/null | grep -q '^oracle-epel-release'; } && epel=1
        fi
        no_epel=0; [ "${EPEL-}" = 0 ] && no_epel=1
        printf '{"inotify":%d,"epel":%s,"family":"%s","no_epel":%d}' "$ino" "$epel" "$fam" "$no_epel" >"$tf" 2>/dev/null
    fi
    tools_json=$(cat "$tf" 2>/dev/null)
fi

# ---------------------------------------------------------------- Realtime-watcher: status
lap rt_status
# state (active/off) <spatie> sinds <spatie> systemlog (ok/-) <spatie> bestanden (ok/missing/-), van de watcher zelf
rt_json=""
rt_line=""
[ -r "$t_dir/watch.state" ] && rt_line=$(head -n 1 "$t_dir/watch.state")
[ -n "${RT_HA-}" ] && rt_line=$RT_HA   # Home Assistant-add-on: de lus in run.sh geeft de status door
if [ -n "$rt_line" ]; then
    read -r rt_s rt_t rt_j rt_i _ <<<"$rt_line"
    [[ "$rt_t" =~ ^[0-9]+$ ]] && rt_json="{\"state\":\"$(json_escape "$rt_s")\",\"since\":$rt_t,\"journal\":\"$(json_escape "$rt_j")\",\"files\":\"$(json_escape "$rt_i")\"}"
fi

# ---------------------------------------------------------------- Heartbeats meesturen (rzmon-hb)
lap heartbeats
# Alleen regels in precies de vorm die rzmon-hb schrijft (iedereen mag in de map schrijven); oudste eerst, hooguit 100
hb_json=""; hb_files=()
if [ -d "$hb_dir" ]; then
    hb_re='^\{"n":"[A-Za-z0-9][A-Za-z0-9._-]{0,63}","s":"(start|ok|fail)","t":[0-9]{1,12}(,"v":-?[0-9]+(\.[0-9]+)?)?,"m":"([^"\\]|\\.)*"\}$'
    while IFS= read -r f; do
        [ -f "$f" ] && [ ! -L "$f" ] || continue
        line=$(head -c 1000 "$f" 2>/dev/null | head -n 1)
        if [[ ${#line} -le 900 && "$line" =~ $hb_re ]]; then
            hb_json+="${hb_json:+,}$line"
            hb_files+=("$f")
        else
            rm -f "$f"   # geen geldige regel: weg
        fi
        [ ${#hb_files[@]} -ge 100 ] && break
    done < <(ls -1tr "$hb_dir"/hb.* 2>/dev/null)
    # Map voor rzmon-hb: iedereen mag erin schrijven, maar niemand mag lezen of andermans regels weghalen
    [ "$(id -u)" = 0 ] && chmod 1733 "$hb_dir" 2>/dev/null
elif [ "$(id -u)" = 0 ] && [ "$HA_MODE" != 1 ]; then
    { mkdir -p "$hb_dir" && chmod 1733 "$hb_dir"; } 2>/dev/null
fi

# ---------------------------------------------------------------- Zelf herstellen en waakhond: meesturen
lap heal_report
# Uitkomsten van herstelpogingen (weg na een geslaagd rapport), de herstelcommando's die hier staan, en een periode
# waarin de waakhond RzMon niet bereikte
heal_json=""; heal_cmds=""
if [ -s "$heal_dir/done" ]; then
    mv -f "$heal_dir/done" "$heal_dir/done.sending" 2>/dev/null
    heal_json=$(grep -E '^\{"id":[0-9]+,"ok":[01],"t":[0-9]+,"m":"([^"\\]|\\.)*"\}$' "$heal_dir/done.sending" 2>/dev/null | tail -n 50 | paste -sd, -)
fi
if [ "$HA_MODE" = 0 ] && [ -d "$HEAL_DIR" ]; then
    for hc_f in "$HEAL_DIR"/*; do
        hc_n=${hc_f##*/}
        [[ "$hc_n" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] && heal_cmd_ok "$hc_f" && heal_cmds+="${heal_cmds:+,}\"$hc_n\""
    done
fi
wd_file="$t_dir/watchdog"
wd_gap=""
[ -s "$wd_file.gap" ] && wd_gap=$(grep -E '^\{"down":[0-9]+,"up":[0-9]+,"sent":[01],"self":[01]\}$' "$wd_file.gap" | head -n 1)

# ---------------------------------------------------------------- Klok, schijfgezondheid en UPS (agent 1.70.0)
lap time_smart_ups
# Klok: gesynchroniseerd en hoeveel ms hij afwijkt (chrony of systemd-timesyncd), voor de check Tijd
time_json=""
if [ "$HA_MODE" = 0 ]; then
    if command -v chronyc >/dev/null 2>&1 && t_out=$(timeout 5 chronyc -n tracking 2>/dev/null) && [ -n "$t_out" ]; then
        t_off=$(awk -F': *' '/^System time/ { split($2, a, " "); v = a[1] * 1000; if ($2 ~ /slow/) v = -v; printf "%.3f", v }' <<<"$t_out")
        t_sync=$(awk -F': *' '/^Leap status/ { print ($2 == "Normal") ? "true" : "false" }' <<<"$t_out")
        time_json="{\"src\":\"chrony\",\"sync\":${t_sync:-false},\"offset_ms\":${t_off:-null}}"
    elif command -v timedatectl >/dev/null 2>&1 && t_sync=$(timeout 5 timedatectl show -p NTPSynchronized --value 2>/dev/null) && [ -n "$t_sync" ]; then
        t_off=$(timeout 5 timedatectl timesync-status 2>/dev/null | awk -F': *' '/Offset:/ { v = $2; m = 1; if (v ~ /us/) m = 0.001; else if (v ~ /ms/) m = 1; else if (v ~ /s$/) m = 1000; gsub(/[^0-9.+-]/, "", v); printf "%.3f", v * m; exit }')
        time_json="{\"src\":\"timesyncd\",\"sync\":$( [ "$t_sync" = yes ] && echo true || echo false),\"offset_ms\":${t_off:-null}}"
    fi
fi
# Schijfgezondheid (smartctl, smartmontools): elk half uur, schijven in slaapstand worden niet wakker gemaakt
smart_json=""
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ] && [ "$(id -u)" = 0 ] && command -v smartctl >/dev/null 2>&1; then
    sm_cache="$t_dir/smart.cache"
    if [ -s "$sm_cache" ] && [ $(( $(date +%s) - $(stat -c %Y "$sm_cache") )) -lt 1800 ]; then
        smart_json=$(cat "$sm_cache")
    else
        smart_json=$(timeout 20 smartctl --scan 2>/dev/null | awk '$1 ~ /^\/dev\// { print $1 " " ($2 == "-d" ? $3 : "auto") }' | head -n 24 | while read -r sm_dev sm_type; do
            sm_out=$(timeout 20 smartctl -n standby -H -i -A -d "$sm_type" "$sm_dev" 2>/dev/null); sm_rc=$?
            if grep -qi 'STANDBY\|SLEEP' <<<"$sm_out" && [ $(( sm_rc & 2 )) -ne 0 ]; then
                printf '{"dev":"%s","standby":true},' "$(json_escape "$sm_dev")"; continue
            fi
            awk -v dev="$sm_dev" '
                function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); return s }
                /^(Device Model|Model Number|Product):/ { sub(/^[^:]*: */, ""); model = $0 }
                /overall-health self-assessment test result:|SMART Health Status:/ { passed = ($0 ~ /PASSED|: OK/) ? "true" : "false" }
                /^Temperature:/ { temp = $2 + 0 }
                /^Percentage Used:/ { v = $3; gsub(/%/, "", v); wear = v + 0 }
                /^Media and Data Integrity Errors:/ { gsub(/,/, "", $NF); media = $NF + 0 }
                $2 == "Reallocated_Sector_Ct" { realloc = $NF + 0 }
                $2 == "Current_Pending_Sector" { pending = $NF + 0 }
                ($2 == "Temperature_Celsius" || $2 == "Airflow_Temperature_Cel") && temp == "" { temp = $10 + 0 }
                ($2 == "Percent_Lifetime_Remain" || $2 == "SSD_Life_Left" || $2 == "Wear_Leveling_Count" || $2 == "Media_Wearout_Indicator") && wear == "" { wear = 100 - ($4 + 0) }
                END {
                    if (passed == "" && model == "") { printf "{\"dev\":\"%s\",\"error\":\"niet uit te lezen\"},", esc(dev); exit }
                    printf "{\"dev\":\"%s\",\"model\":\"%s\",\"passed\":%s,\"temp\":%s,\"wear\":%s,\"realloc\":%s,\"pending\":%s,\"media_err\":%s},",
                        esc(dev), esc(model), passed == "" ? "null" : passed, temp == "" ? "null" : temp, wear == "" ? "null" : wear,
                        realloc == "" ? "null" : realloc, pending == "" ? "null" : pending, media == "" ? "null" : media
                }' <<<"$sm_out"
        done)
        smart_json="[${smart_json%,}]"
        (umask 077; printf '%s' "$smart_json" >"$sm_cache") 2>/dev/null
    fi
fi
# UPS via NUT (upsc): status (OL op het net, OB op de batterij, LB bijna leeg), lading, resterende tijd, belasting
ups_json=""
if [ "$HA_MODE" = 0 ] && command -v upsc >/dev/null 2>&1; then
    for u_name in $(timeout 5 upsc -l 2>/dev/null | head -n 8); do
        u_out=$(timeout 5 upsc "$u_name@localhost" 2>/dev/null) || continue
        ups_json+=$(awk -F': ' -v n="$u_name" '
            $1 == "ups.status" { st = $2 } $1 == "battery.charge" { ch = $2 + 0 } $1 == "battery.runtime" { rt = $2 + 0 }
            $1 == "ups.load" { ld = $2 + 0 } $1 == "device.model" || $1 == "ups.model" { m = $2; gsub(/"/, "", m) }
            END { printf "{\"name\":\"%s\",\"status\":\"%s\",\"charge\":%s,\"runtime\":%s,\"load\":%s,\"model\":\"%s\"},", n, st,
                ch == "" ? "null" : ch, rt == "" ? "null" : rt, ld == "" ? "null" : ld, m }' <<<"$u_out")
    done
    [ -n "$ups_json" ] && ups_json="[${ups_json%,}]"
fi
# WireGuard (agent 1.75.0): per peer de laatste handshake (seconden geleden, -1 = nog nooit), verkeer en endpoint.
# Alleen als root (wg show heeft root nodig) en alleen tunnels die deze server zelf heeft (niet die in een container).
wg_json=""
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && command -v wg >/dev/null 2>&1; then
    wg_json=$(timeout 5 wg show all dump 2>/dev/null | awk -F'\t' -v now="$(date +%s)" '
        NF >= 9 && $1 ~ /^[A-Za-z0-9_.-]+$/ {
            k = $2; gsub(/[^A-Za-z0-9]/, "", k); k = substr(k, 1, 8)
            ep = ($4 == "(none)") ? "" : $4; gsub(/[^0-9A-Za-z.:\[\]-]/, "", ep)
            hs = ($6 ~ /^[0-9]+$/ && $6 > 0) ? now - $6 : -1
            printf "{\"id\":\"%s:%s\",\"iface\":\"%s\",\"peer\":\"%s\",\"endpoint\":\"%s\",\"hs\":%.0f,\"rx\":%s,\"tx\":%s},", $1, k, $1, k, ep, hs,
                ($7 ~ /^[0-9]+$/ ? $7 : 0), ($8 ~ /^[0-9]+$/ ? $8 : 0)
            if (++n >= 64) exit
        }')
    wg_json="[${wg_json%,}]"
fi

