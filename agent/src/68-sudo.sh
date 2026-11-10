# ---------------------------------------------------------------- Tijdelijk sudo (agent 1.93.0)
lap sudo
# Vraagt een beheerder in RzSense tijdelijk sudo voor een gebruiker (regel "0 <tab> sudo <tab> id <tab> gebruiker <tab>
# tot (unix-tijd)"), dan zet de agent een eigen bestand /etc/sudoers.d/rzsense-jit-<gebruiker> neer:
#  - eerst als verborgen tijdelijk bestand (sudo leest bestanden met een punt in de naam niet), gecontroleerd met
#    visudo, pas dan op zijn plek; daarna de hele sudo-configuratie nog eens met visudo (klopt die niet, dan meteen weg);
#  - een systemd-timer haalt het op tijd weg, ook als de agent er niet meer is;
#  - de agent haalt het zelf weg als de tijd voorbij is of de regel er niet meer is (ingetrokken).
# Alleen de eigen rzsense-jit-bestanden worden ooit aangeraakt; /etc/sudoers en andere bestanden nooit. Nooit voor root.
# Uitzetten op de server: SUDO_JIT=0 in /etc/default/rzmon-agent.
sudo_json=""
sd_dir=/etc/sudoers.d
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ "${SUDO_JIT:-1}" != 0 ] && [ -d "$sd_dir" ] && command -v visudo >/dev/null 2>&1; then
    sd_now=$(date +%s); sd_keep=" "
    sd_failed=" $(tr '\n' ' ' 2>/dev/null <"$t_dir/sudo.failed")"   # mislukte aanvragen niet elke ronde opnieuw (en niet elke minuut in het logboek)
    sd_fail() { printf '%s\n' "$1" >>"$t_dir/sudo.failed"; tail -n 50 "$t_dir/sudo.failed" >"$t_dir/sudo.failed.tmp" 2>/dev/null && mv -f "$t_dir/sudo.failed.tmp" "$t_dir/sudo.failed"; }
    while IFS=$'\t' read -r _ _ sd_id sd_user sd_until _; do
        [[ "$sd_id" =~ ^[0-9]{1,10}$ ]] && [[ "$sd_user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [[ "$sd_until" =~ ^[0-9]{9,11}$ ]] || continue
        [ "$sd_user" != root ] && [ "$sd_until" -gt "$sd_now" ] || continue
        [[ "$sd_failed" == *" $sd_id "* ]] && continue
        sd_keep+="$sd_user "
        sd_f="$sd_dir/rzsense-jit-$sd_user"
        if [ -f "$sd_f" ] && grep -qx "# rzsense-jit id=$sd_id until=$sd_until" "$sd_f"; then
            sudo_json+="{\"id\":$sd_id,\"st\":\"active\"},"; continue
        fi
        if ! id -u "$sd_user" >/dev/null 2>&1; then
            act_log sudo 0 "Tijdelijk sudo mislukt" "$sd_user: gebruiker bestaat niet op deze server"
            sudo_json+="{\"id\":$sd_id,\"st\":\"error\",\"d\":\"gebruiker bestaat niet\"},"; sd_fail "$sd_id"; continue
        fi
        sd_tmp=$(mktemp "$sd_dir/.rzsense-jit.XXXXXX" 2>/dev/null) || continue
        sd_when=$(date -d "@$sd_until" '+%Y-%m-%d %H:%M' 2>/dev/null || echo "$sd_until")
        printf '# rzsense-jit id=%s until=%s\n# Tijdelijk sudo via RzSense tot %s; wordt vanzelf weggehaald.\n%s ALL=(ALL:ALL) ALL\n' \
            "$sd_id" "$sd_until" "$sd_when" "$sd_user" >"$sd_tmp"
        chmod 0440 "$sd_tmp"
        if visudo -cqf "$sd_tmp" >/dev/null 2>&1 && mv -f "$sd_tmp" "$sd_f"; then
            if ! visudo -cq >/dev/null 2>&1; then
                rm -f -- "$sd_f"
                act_log sudo 0 "Tijdelijk sudo mislukt" "$sd_user: de sudo-configuratie klopte daarna niet; meteen weggehaald"
                sudo_json+="{\"id\":$sd_id,\"st\":\"error\",\"d\":\"visudo keurt de configuratie af\"},"; sd_fail "$sd_id"; continue
            fi
            # Vangnet: een timer die het bestand op tijd weghaalt, ook als de agent stopt
            command -v systemd-run >/dev/null 2>&1 && systemd-run --quiet --collect --unit="rzsense-jit-$sd_user-$sd_id" \
                --on-active="$(( sd_until - sd_now + 30 ))" /bin/rm -f -- "$sd_f" >/dev/null 2>&1
            act_log sudo 1 "Tijdelijk sudo gegeven" "$sd_user tot $sd_when"
            sudo_json+="{\"id\":$sd_id,\"st\":\"active\"},"
        else
            rm -f -- "$sd_tmp"
            act_log sudo 0 "Tijdelijk sudo mislukt" "$sd_user: visudo keurt het bestand niet goed"
            sudo_json+="{\"id\":$sd_id,\"st\":\"error\",\"d\":\"visudo keurt het niet goed\"},"; sd_fail "$sd_id"
        fi
    done < <(awk -F'\t' '$1 == "0" && $2 == "sudo"' "${t_cache:-/dev/null}" 2>/dev/null | head -n 20)
    # Weghalen: tijd voorbij, ingetrokken of niet meer gevraagd (alleen onze eigen bestanden)
    for sd_f in "$sd_dir"/rzsense-jit-*; do
        [ -f "$sd_f" ] || continue
        sd_user=${sd_f##*/rzsense-jit-}
        sd_until=$(sed -n 's/^# rzsense-jit id=[0-9]* until=\([0-9]*\)$/\1/p' "$sd_f" | head -n 1)
        sd_id=$(sed -n 's/^# rzsense-jit id=\([0-9]*\) until=[0-9]*$/\1/p' "$sd_f" | head -n 1)
        if [[ "$sd_keep" != *" $sd_user "* ]] || [ "${sd_until:-0}" -le "$sd_now" ]; then
            rm -f -- "$sd_f"
            act_log sudo 1 "Tijdelijk sudo weggehaald" "$sd_user$([ "${sd_until:-0}" -le "$sd_now" ] && echo ' (tijd voorbij)' || echo ' (ingetrokken)')"
            sudo_json+="{\"id\":${sd_id:-0},\"st\":\"removed\"},"
        fi
    done
    rm -f "$sd_dir"/.rzsense-jit.* 2>/dev/null
fi
