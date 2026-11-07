# ---------------------------------------------------------------- Logs vinden (agent 1.84.0, RzMon 1.213.0)
lap log_scan
# Welke logs zijn er op deze server, om in RzMon (Agent Manager › Logs) uit te kiezen: bestanden en mappen binnen
# LOG_DIRS, containers (Docker/Podman), systemd-services die de afgelopen 24 uur iets in de journal schreven, en op
# Home Assistant de Supervisor-logs en add-ons. Alleen namen, groottes en tijden, nooit de inhoud.
# Eén keer per dag, of meteen als RzMon erom vraagt (regel "0 <tab> logscan <tab> teller"). Het resultaat gaat alleen mee
# in het rapport als het net gemaakt is (anders niets: RzMon bewaart de laatste lijst).
# Overgeslagen: gedraaide bestanden (.1, .gz, -20250101), binaire bestanden (wtmp, btmp, lastlog) en de map journal.
logscan_json=""
if [ "$TEST" != 1 ] && [ "${LOG_SHIP:-1}" != 0 ]; then
    lv_req=$( [ -r "${t_cache-}" ] && awk -F'\t' '$1 == "0" && $2 == "logscan" { print $3; exit }' "$t_cache")
    lv_cache="$t_dir/logscan.cache"
    lv_key=$(printf '%s|%s' "$LOG_DIRS" "$lv_req" | cksum | cut -d' ' -f1)
    lv_now=$(date +%s)
    if ! { [ -r "$lv_cache" ] && [ "$(sed -n 1p "$lv_cache")" = "$lv_key" ] && [ $((lv_now - $(sed -n 2p "$lv_cache"))) -lt 86400 ]; }; then
        lv_files=""; lv_n=0; lv_noread=""
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
        logscan_json="{\"at\":$lv_now,\"req\":${lv_req:-0},\"dirs\":\"$(json_escape "$LOG_DIRS")\",\"files\":[${lv_files%,}],\"noread\":[${lv_noread%,}],\"docker\":[${lv_dk%,}],\"units\":[${lv_un%,}],\"ha\":[${lv_ha%,}]}"
        (umask 077; printf '%s\n%s\n' "$lv_key" "$lv_now" >"$lv_cache") 2>/dev/null || true
    fi
fi
