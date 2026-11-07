# ---------------------------------------------------------------- Diagnose voor AI-advies (agent 1.76.0)
# Op verzoek van RzSense (regel "0 <tab> diag <tab> id <tab> soort <tab> doel" in de lijst) bij een storing: een VASTE set
# commando's die alleen lezen, zodat het AI-advies weet wat er echt aan de hand is. RzSense stuurt alleen de soort en het
# doel (een servicenaam, container, mount of poort), nooit een commando; het doel wordt hier opnieuw streng gecontroleerd.
# In RzSense staat dit apart aan (Instellingen → AI-advies, met je wachtwoord); COMMANDS=0 hierboven blokkeert het altijd.
# Elke aanvraag één keer. Per onderdeel hooguit 20 seconden en 6 kB uitvoer; wachtwoorden filtert RzSense er nog uit.
diag_json=""
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ] && [ "$COMMANDS" != 0 ] && [ -r "${t_cache-}" ]; then
    d_line=$(awk -F'\t' '$1 == "0" && $2 == "diag" { print; exit }' "$t_cache")
    if [ -n "$d_line" ]; then
        IFS=$'\t' read -r _ _ d_id d_kind d_target <<<"$d_line"
        d_done="$t_dir/diag.done"
        if [[ "$d_id" =~ ^[0-9]{1,18}$ ]] && [ "$(cat "$d_done" 2>/dev/null)" != "$d_id" ]; then
            d_parts=""
            d_run() {  # d_run <naam> <commando…>: uitvoer (stdout en stderr) als één onderdeel
                local out
                out=$(timeout 20 "${@:2}" 2>&1 | head -c 6000 | json_text)
                d_parts+="{\"k\":\"$1\",\"out\":\"$out\"},"
            }
            d_run uptime uptime
            command -v journalctl >/dev/null 2>&1 && d_run errors journalctl -p err -n 30 --no-pager --since -3h -o short-iso
            [ "$(id -u)" = 0 ] && d_run dmesg sh -c 'dmesg -T 2>/dev/null | tail -n 25'
            case "$d_kind" in
                svc)
                    if [[ "$d_target" =~ ^[A-Za-z0-9@._:-]{1,100}$ ]] && command -v systemctl >/dev/null 2>&1; then
                        d_run status systemctl status "$d_target" --no-pager -l -n 0
                        d_run journal journalctl -u "$d_target" -n 60 --no-pager -o short-iso
                    fi ;;
                ctr)
                    if [[ "$d_target" =~ ^([a-z_][a-z0-9_.-]*)/([A-Za-z0-9][A-Za-z0-9_.-]*)$ ]]; then
                        d_ctx=${BASH_REMATCH[1]}; d_name=${BASH_REMATCH[2]}
                        if [ "$d_ctx" = root ] || id -u "$d_ctx" >/dev/null 2>&1; then
                            d_run state pm "$d_ctx" inspect --format '{{.State.Status}} exit={{.State.ExitCode}} restarts={{.RestartCount}} started={{.State.StartedAt}} error={{.State.Error}}' "$d_name"
                            d_run logs pm "$d_ctx" logs --tail 60 "$d_name"
                        fi
                    fi ;;
                disk)
                    if [[ "$d_target" =~ ^/[A-Za-z0-9._/@+-]{0,200}$ && "$d_target" != *..* ]]; then
                        d_run df df -h "$d_target"
                        d_run inodes df -i "$d_target"
                        d_run du sh -c 'du -xh --max-depth=2 -- "$1" 2>/dev/null | sort -h | tail -n 15' _ "$d_target"
                    fi ;;
                proc)
                    d_run top sh -c 'ps -eo pid,user,%cpu,%mem,etime,comm --sort=-%cpu | head -n 16'
                    d_run mem free -m
                    command -v journalctl >/dev/null 2>&1 && d_run oom sh -c 'journalctl -k --since -6h --no-pager 2>/dev/null | grep -i -E "out of memory|oom-kill|killed process" | tail -n 10' ;;
                port)
                    if [[ "$d_target" =~ ^[0-9]{1,5}$ ]]; then
                        d_run listen sh -c 'ss -ltnp 2>/dev/null | grep -E ":$1([^0-9]|$)" || echo "niets luistert op poort $1"' _ "$d_target"
                    fi ;;
            esac
            diag_json="{\"id\":$d_id,\"items\":[${d_parts%,}]}"
            printf '%s' "$d_id" >"$d_done" 2>/dev/null
        fi
    fi
fi
