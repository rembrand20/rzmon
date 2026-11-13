# ---------------------------------------------------------------- Logs vinden (agent 1.84.0, RzSense 1.213.0)
lap log_scan
# Welke logs zijn er op deze server, om in RzSense (Agent Manager › Logs) uit te kiezen: bestanden en mappen binnen
# LOG_DIRS, containers (Docker/Podman), systemd-services die de afgelopen 24 uur iets in de journal schreven, en op
# Home Assistant de Supervisor-logs en add-ons. Alleen namen, groottes en tijden, nooit de inhoud.
# Eén keer per dag, of meteen als RzSense erom vraagt (regel "0 <tab> logscan <tab> teller"). Het resultaat gaat alleen mee
# in het rapport als het net gemaakt is (anders niets: RzSense bewaart de laatste lijst).
# Overgeslagen: gedraaide bestanden (.1, .gz, -20250101), binaire bestanden (wtmp, btmp, lastlog) en de map journal.
logscan_json=""
if [ "$TEST" != 1 ] && [ "${LOG_SHIP:-1}" != 0 ]; then
    lv_req=$( [ -r "${t_cache-}" ] && awk -F'\t' '$1 == "0" && $2 == "logscan" { print $3; exit }' "$t_cache")
    lv_cache="$t_dir/logscan.cache"
    lv_key=$(printf '%s|%s|%s|%s' "$LOG_DIRS" "$lv_req" "$LOG_DIRS_MAX" "${lr_want-}" | cksum | cut -d' ' -f1)
    lv_now=$(date +%s)
    if ! { [ -r "$lv_cache" ] && [ "$(sed -n 1p "$lv_cache")" = "$lv_key" ] && [ $((lv_now - $(sed -n 2p "$lv_cache"))) -lt 86400 ]; }; then
        lv_files=""; lv_n=0; lv_noread=""; declare -A lv_seen=()
        for lv_d in $LOG_DIRS; do
            [[ "$lv_d" == /* && "$lv_d" != *..* && -d "$lv_d" ]] || continue
            # Mappen zonder leesrechten (tot 2 diep): wel noemen, zodat je weet waarom ze ontbreken
            lv_c=0
            while IFS= read -r -d '' x; do
                lv_c=$((lv_c + 1)); [ "$lv_c" -gt 200 ] && break
                { [ -r "$x" ] && [ -x "$x" ]; } || lv_noread+="\"$(json_escape "$x")\","
            done < <(find "$lv_d" -xdev -maxdepth 2 -mindepth 1 -type d ! -name journal -print0 2>/dev/null)
            while IFS= read -r -d '' f; do
                case "${f##*/}" in
                    *.gz|*.xz|*.bz2|*.zst|*.zip|*.old|*.[0-9]|*.[0-9].log|*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]|*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].log) continue ;;
                    wtmp*|btmp*|lastlog|faillog|*.journal|*.journal~|*.pid|*.lock|*.sock|*.db|*.sqlite*) continue ;;
                esac
                [ -r "$f" ] || continue
                log_allowed "$f" || continue   # agent 1.96.0: in mappen uit RzSense alleen logs, geen geheimen
                [ -n "${lv_seen[$f]-}" ] && continue; lv_seen[$f]=1   # map in een andere map: niet dubbel
                read -r lv_sz lv_mt < <(stat -c '%s %Y' -- "$f" 2>/dev/null) || continue
                # Binair? (NUL-tekens in het begin)
                if [ "${lv_sz:-0}" -gt 0 ]; then
                    lv_a=$(head -c 2048 -- "$f" 2>/dev/null | wc -c); lv_b=$(head -c 2048 -- "$f" 2>/dev/null | tr -d '\000' | wc -c)
                    [ "$lv_a" = "$lv_b" ] || continue
                fi
                lv_files+="[\"$(json_escape "$f")\",${lv_sz:-0},${lv_mt:-0}],"
                lv_n=$((lv_n + 1)); [ "$lv_n" -ge 500 ] && break 2
            done < <(find "$lv_d" -xdev -maxdepth 3 \( -name journal -o -name private \) -prune -o -type f -print0 2>/dev/null)
        done
        # Containers (ook gestopte)
        lv_dk=""
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            while IFS=$'\t' read -r n i s; do
                [[ "$n" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] || continue
                lv_dk+="[\"$n\",\"$(json_escape "${i:0:120}")\",\"$(json_escape "${s:0:40}")\",\"$c\"],"
            done < <(timeout 15 "$c" ps -a --format '{{.Names}}\t{{.Image}}\t{{.State}}' 2>/dev/null | head -n 150)
            [ -n "$lv_dk" ] && break
        done
        # Services die de afgelopen 24 uur iets logden (met het aantal regels)
        lv_un=""
        if [ "$HA_MODE" != 1 ] && command -v journalctl >/dev/null 2>&1; then
            lv_un=$(timeout 25 journalctl -q --no-pager --since "-24h" -o export --output-fields=_SYSTEMD_UNIT 2>/dev/null | head -n 400000 |
                awk -F= '$1 == "_SYSTEMD_UNIT" && $2 ~ /^[A-Za-z0-9@._:-]+$/ { n[$2]++ }
                    END { c = 0; for (u in n) { if (c++ >= 150) break; printf "[\"%s\",%d],", u, n[u] } }')
        fi
        # Home Assistant: de Supervisor-logs en de add-ons
        lv_ha=""
        if [ "$HA_MODE" = 1 ] && [ -n "${SUPERVISOR_TOKEN-}" ] && command -v jq >/dev/null 2>&1; then
            lv_ha='["host","Host","started"],["core","Home Assistant Core","started"],["supervisor","Supervisor","started"],'
            lv_ha+=$(sv /addons | jq -r '.data.addons[]? | select(.slug | test("^[a-z0-9_]{1,64}$")) | "[\"addon:\(.slug)\",\(.name | @json),\(.state | @json)],"' 2>/dev/null | head -n 100 | tr -d '\n')
        fi
        logscan_json="{\"at\":$lv_now,\"req\":${lv_req:-0},\"dirs\":\"$(json_escape "$LOG_DIRS")\",\"files\":[${lv_files%,}],\"noread\":[${lv_noread%,}],\"docker\":[${lv_dk%,}],\"units\":[${lv_un%,}],\"ha\":[${lv_ha%,}],\"local\":\"$(json_escape "$LOG_DIRS_LOCAL")\",\"max\":\"$(json_escape "$LOG_DIRS_MAX")\",\"refused\":[${log_dirs_refused%,}],\"ok\":\"$(json_escape "${log_dirs_ok# }")\"}"
        (umask 077; printf '%s\n%s\n' "$lv_key" "$lv_now" >"$lv_cache") 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------- Map doorbladeren (agent 1.96.0)
# RzSense vraagt de inhoud van één map (regel "0 <tab> logbrowse <tab> teller <tab> pad"), om een logmap te kiezen.
# Alleen binnen LOG_DIRS of LOG_DIRS_MAX, alleen namen, groottes en tijden (nooit de inhoud), geen verborgen namen.
# Per bestand: of de agent het als log zou lezen. Per submap: hoeveel logs erin staan (tot 2 diep).
logbrowse_json=""
if [ "$TEST" != 1 ] && [ "${LOG_SHIP:-1}" != 0 ] && [ -r "${t_cache-}" ]; then
    IFS=$'\t' read -r lb_req lb_path < <(awk -F'\t' '$1 == "0" && $2 == "logbrowse" { print $3 "\t" $4; exit }' "$t_cache")
    lb_done="$t_dir/logbrowse.done"
    if [[ "${lb_req-}" =~ ^[0-9]+$ ]] && [ "$lb_req" != "$(cat "$lb_done" 2>/dev/null)" ]; then
        lb_err=""; lb_dirs=""; lb_files=""; lb_real=""
        if [[ "${lb_path-}" != /* || "$lb_path" == *..* ]]; then lb_err="geen volledig pad"
        elif ! lb_real=$(readlink -e -- "$lb_path" 2>/dev/null) || [ ! -d "$lb_real" ]; then lb_err="bestaat niet"
        elif ! { in_dirs "$lb_real" "$LOG_DIRS_LOCAL" || in_dirs "$lb_real" "$LOG_DIRS_MAX"; }; then lb_err="buiten LOG_DIRS en LOG_DIRS_MAX"
        elif log_secret "$lb_real/"; then lb_err="map met geheimen"
        elif [ ! -r "$lb_real" ] || [ ! -x "$lb_real" ]; then lb_err="geen leesrechten"
        else
            lb_n=0
            while IFS= read -r -d '' x; do
                lb_n=$((lb_n + 1)); [ "$lb_n" -gt 400 ] && break
                b="${x##*/}"; [[ "$b" == .* ]] && continue
                if [ -d "$x" ]; then
                    lb_c=0; lb_ok=1
                    if log_secret "$x/" || [ ! -r "$x" ] || [ ! -x "$x" ]; then lb_ok=0
                    else
                        lb_m=0
                        while IFS= read -r -d '' y; do
                            lb_m=$((lb_m + 1)); [ "$lb_m" -gt 500 ] && break
                            log_like "$y" && ! log_secret "$y" && lb_c=$((lb_c + 1))
                        done < <(timeout 5 find "$x" -xdev -maxdepth 2 -type f -print0 2>/dev/null)
                    fi
                    lb_dirs+="[\"$(json_escape "$b")\",$lb_c,$lb_ok],"
                elif [ -f "$x" ]; then
                    read -r lb_sz lb_mt < <(stat -c '%s %Y' -- "$x" 2>/dev/null) || continue
                    # 1 = de agent leest het, 0 = geen log of een geheim, 2 = geen leesrechten
                    if in_dirs "$lb_real" "$LOG_DIRS_LOCAL"; then lb_ok=1
                    elif log_like "$x" && ! log_secret "$x"; then lb_ok=1; else lb_ok=0; fi
                    [ "$lb_ok" = 1 ] && [ ! -r "$x" ] && lb_ok=2
                    lb_files+="[\"$(json_escape "$b")\",${lb_sz:-0},${lb_mt:-0},$lb_ok],"
                fi
            done < <(find "$lb_real" -xdev -maxdepth 1 -mindepth 1 -print0 2>/dev/null)
        fi
        logbrowse_json="{\"req\":$lb_req,\"path\":\"$(json_escape "$lb_path")\",\"real\":\"$(json_escape "$lb_real")\",\"err\":\"$(json_escape "$lb_err")\",\"dirs\":[${lb_dirs%,}],\"files\":[${lb_files%,}]}"
        (umask 077; echo "$lb_req" >"$lb_done") 2>/dev/null || true
    fi
fi
