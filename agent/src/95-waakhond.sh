# ---------------------------------------------------------------- 🐕 Waakhond (agent 1.62.0)
# In RzSense per agent aan te zetten (Agent Manager). Lukt het rapport een tijd niet (standaard 5 minuten), dan stuurt de
# agent zelf een pushbericht (Pushover en/of ntfy), en een herstelbericht als RzSense terug is. Bereikt de agent ook Pushover niet, dan ligt
# het aan zijn eigen netwerk: dan geen bericht, wel een notitie voor RzSense. Draait RzSense wel, maar staan de checks stil
# (cron), dan meldt hij dat ook. De Pushover-gegevens komen versleuteld van RzSense (cache, alleen voor root).
wd_line=$(awk -F'\t' '$1 == "0" && $2 == "watchdog" { print; exit }' "${t_cache:-/dev/null}" 2>/dev/null)
if [ -n "$wd_line" ] && [ "$WATCHDOG" != 0 ] && [ "$TEST" != 1 ]; then
    IFS=$'\t' read -r _ _ wd_min wd_user wd_token wd_prio wd_nsrv wd_ntop wd_ntok _ <<<"$wd_line"
    [[ "$wd_min" =~ ^[0-9]+$ ]] || wd_min=5
    [[ "$wd_prio" =~ ^-?[0-2]$ ]] || wd_prio=1
    # ntfy (agent 1.72.0): naast of in plaats van Pushover; JSON naar de server, prioriteit -2..2 wordt 1..5
    [[ "${wd_nsrv:--}" =~ ^https?://[^[:space:]]+$ ]] || wd_nsrv=-
    if [ "$wd_nsrv" != - ] && ! [[ "${wd_ntop:--}" =~ ^[A-Za-z0-9_-]{1,64}$ ]]; then
        echo "Waakhond: ntfy-onderwerp \"${wd_ntop:0:70}\" is ongeldig (alleen letters, cijfers, - en _): ntfy staat uit" >&2   # agent 1.87.0
        wd_nsrv=-
    fi
    wd_push() {  # wd_push <titel> <bericht> <prioriteit>: 0 = verstuurd (via minstens één dienst)
        local ok=1 extra=() auth=()
        if [ "$wd_user" != - ] && [ "$wd_token" != - ] && [ -n "$wd_user" ]; then
            [ "$3" = 2 ] && extra=(--form-string retry=120 --form-string expire=3600)
            # Agent 1.87.0: het Pushover-token in een tijdelijk curl-configbestand (alleen root), niet op de opdrachtregel
            local cf
            cf=$(umask 077 && mktemp "$t_dir/wd.XXXXXX" 2>/dev/null) || cf=""
            if [ -n "$cf" ] && printf 'form-string = "token=%s"\nform-string = "user=%s"\n' "${wd_token//\"/}" "${wd_user//\"/}" >"$cf"; then
                curl -fsS -m 15 -o /dev/null -K "$cf" --form-string "title=$1" \
                    --form-string "message=$2" --form-string "priority=$3" "${extra[@]}" "$PUSHOVER_API" 2>/dev/null && ok=0
            fi
            [ -n "$cf" ] && rm -f "$cf"
        fi
        if [ "$wd_nsrv" != - ]; then
            local nf=""
            if [ -n "$wd_ntok" ] && [ "$wd_ntok" != - ]; then
                nf=$(umask 077 && mktemp "$t_dir/wdn.XXXXXX" 2>/dev/null) || nf=""
                case $wd_ntok in
                    *:*) [ -n "$nf" ] && printf 'user = "%s"\n' "${wd_ntok//\"/}" >"$nf" ;;
                    *) [ -n "$nf" ] && printf 'header = "Authorization: Bearer %s"\n' "${wd_ntok//\"/}" >"$nf" ;;
                esac
                [ -n "$nf" ] && auth=(-K "$nf")
            fi
            printf '{"topic":"%s","title":"%s","message":"%s","priority":%s}' "$wd_ntop" "$(json_escape "$1" | tr '\n\t\r' '   ')" "$(json_escape "$2" | tr '\n\t\r' '   ')" "$(( $3 + 3 ))" |
                curl -fsS -m 15 -o /dev/null -H 'Content-Type: application/json' "${auth[@]}" --data-binary @- "${wd_nsrv%/}" 2>/dev/null && ok=0
            [ -n "$nf" ] && rm -f "$nf"
        fi
        return "$ok"
    }
    wd_down=0; wd_sent=0; wd_self=0; wd_cron=0
    # shellcheck disable=SC1090
    [ -r "$wd_file" ] && . <(grep -E '^wd_(down|sent|self|cron)=[0-9]+$' "$wd_file")
    wd_now=$(date +%s)
    if [ "$rc" != 0 ]; then
        [ "$wd_down" -gt 0 ] || wd_down=$wd_now
        # Agent 1.82.0: tijdens een aangekondigde update van RzSense niet alarmeren, tot de tijd die RzSense meegaf voorbij is
        wd_title="⚠️ RzSense onbereikbaar"
        wd_msg="RzSense is vanaf $hostname niet bereikbaar sinds $(date -d "@$wd_down" +%H:%M 2>/dev/null || date +%H:%M)${st_err:+ ($st_err)}. Gemeld door de waakhond op $hostname."
        if [ -n "${hold_id-}" ]; then
            wd_title="⚠️ RzSense onbereikbaar tijdens update"
            wd_msg="De update van RzSense begon om $(date -d "@${hold_since:-$wd_down}" +%H:%M 2>/dev/null || echo ?) en RzSense is nog niet terug$( [ -s "${q_file-}" ] && echo "; $(wc -l <"$q_file") metingen in de wachtrij"). Gemeld door de waakhond op $hostname."
        fi
        if [ "$wd_sent" = 0 ] && [ $(( wd_now - wd_down )) -ge $(( wd_min * 60 )) ] && { [ -z "${hold_id-}" ] || [ "$wd_now" -ge "${hold_until:-0}" ]; }; then
            if wd_push "$wd_title" "$wd_msg" "$wd_prio"; then
                wd_sent=1; wd_self=0
            else
                wd_self=1   # Pushover/ntfy ook niet bereikbaar (of geen gegevens): eerder het netwerk van deze server
            fi
        fi
    else
        if [ "$wd_down" -gt 0 ]; then
            [ "$wd_sent" = 1 ] && wd_push "✅ RzSense weer bereikbaar" "RzSense is vanaf $hostname weer bereikbaar ($(( (wd_now - wd_down + 59) / 60 )) min weg)." -1
            # Voor RzSense (ℹ️ in het volgende rapport): alleen als het langer duurde dan de ingestelde tijd
            [ $(( wd_now - wd_down )) -ge $(( wd_min * 60 )) ] && [ "${hold_released:-0}" = 0 ] && printf '{"down":%s,"up":%s,"sent":%s,"self":%s}\n' "$wd_down" "$wd_now" "$wd_sent" "$wd_self" >"$wd_file.gap"
            wd_down=0; wd_sent=0; wd_self=0
        fi
        # RzSense antwoordt, maar staan de checks stil (cron)?
        wd_age=$(sed -n 's/.*"cron_age":[[:space:]]*\([0-9]\{1,9\}\).*/\1/p' <<<"$resp" | head -n 1)
        if [[ "$wd_age" =~ ^[0-9]+$ ]]; then
            if [ "$wd_age" -ge $(( wd_min * 60 )) ] && [ "$wd_cron" = 0 ]; then
                wd_push "⚠️ RzSense: checks staan stil" "RzSense antwoordt, maar de checks hebben al $(( wd_age / 60 )) min niet gedraaid (cron). Gemeld door de waakhond op $hostname." "$wd_prio" && wd_cron=1
            elif [ "$wd_age" -lt 300 ] && [ "$wd_cron" = 1 ]; then
                wd_push "✅ RzSense: checks draaien weer" "De checks in RzSense draaien weer (gezien vanaf $hostname)." -1
                wd_cron=0
            fi
        fi
    fi
    (umask 077; printf 'wd_down=%s\nwd_sent=%s\nwd_self=%s\nwd_cron=%s\n' "$wd_down" "$wd_sent" "$wd_self" "$wd_cron" >"$wd_file") 2>/dev/null
fi
exit "$rc"
