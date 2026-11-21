# ---------------------------------------------------------------- Bewaker (--guard, agent 1.118.0)
# Draait als eigen systemd-timer (rzmon-agent-guard, elke 5 minuten), los van de gewone ronde: als de agent zelf vastzit of
# niet meer start, ziet de bewaker dat nog wel. Is het laatste geslaagde rapport ouder dan GUARD_MIN minuten (standaard 15):
#  - een ronde die al meer dan 10 minuten loopt: stoppen (de timer start de volgende);
#  - de timer staat stil: weer starten;
#  - er is al 5 minuten geen ronde meer geweest: er een starten.
# Lopen de rondes gewoon maar komt het rapport niet aan (netwerk, reverse proxy, RzSense weigert), dan helpt herstarten
# niet: dan alleen een notitie. Hooguit 3 ingrepen per uur. Elke ingreep komt in het logboek van RzSense (via het
# volgende rapport). GUARD=0 = op deze server nooit.
if [ "${1-}" = "--guard" ]; then
    [ "${GUARD:-1}" = 0 ] && exit 0
    command -v systemctl >/dev/null 2>&1 || exit 0
    g_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    g_st="$g_dir/status"
    g_min="${GUARD_MIN:-15}"; [[ "$g_min" =~ ^[0-9]{1,4}$ ]] && [ "$g_min" -ge 5 ] || g_min=15
    g_now=$(date +%s)
    g_ok=$(cat "$g_st/ok" 2>/dev/null | tr -dc '0-9'); g_ok=${g_ok:-0}
    [ "$g_ok" -gt 0 ] || exit 0   # nog nooit gerapporteerd (installeren of koppelen): niets te herstellen
    [ $(( g_now - g_ok )) -lt $(( g_min * 60 )) ] && exit 0
    g_last=$(sed -n 's/^time=//p' "$g_st/last" 2>/dev/null | tr -dc '0-9'); g_last=${g_last:-0}
    g_err=$(sed -n 's/^error=//p' "$g_st/last" 2>/dev/null | head -c 200)
    g_log="$g_dir/guard.log"
    g_recent=$(awk -F'\t' -v t=$(( g_now - 3600 )) '$1 > t && $2 == "fix"' "$g_log" 2>/dev/null | wc -l)
    g_note() {  # g_note <fix|note> <actie> <detail>: in guard.log en in het logboek dat met het volgende rapport meegaat
        printf '%s\t%s\t%s\n' "$g_now" "$1" "$2" >>"$g_log" 2>/dev/null
        [ "$(wc -l <"$g_log" 2>/dev/null || echo 0)" -gt 200 ] && { tail -n 200 "$g_log" >"$g_log.tmp" && mv -f "$g_log.tmp" "$g_log"; }
        printf '{"id":"%s","t":%s,"m":"agent","ok":%s,"a":"%s","d":"%s"}\n' "$g_now-$$-guard-$RANDOM" "$g_now" "$( [ "$1" = fix ] && echo 1 || echo 2)" \
            "$(json_escape "Bewaker: $2" | cut -c1-80)" "$(json_escape "$3" | cut -c1-900)" >>"$g_dir/act.log" 2>/dev/null
    }
    g_mins=$(( (g_now - g_ok) / 60 ))
    g_state=$(systemctl show -p ActiveState --value rzmon-agent.service 2>/dev/null)
    g_start=$(systemctl show -p ExecMainStartTimestamp --value rzmon-agent.service 2>/dev/null)
    g_start=$(date -d "$g_start" +%s 2>/dev/null || echo 0)
    if [ "$g_recent" -ge 3 ]; then
        # Al 3 keer ingegrepen dit uur: dan zit het probleem ergens anders; alleen een notitie (één keer per uur)
        awk -F'\t' -v t=$(( g_now - 3600 )) '$1 > t && $2 == "limit"' "$g_log" 2>/dev/null | grep -q . \
            || g_note limit "na 3 ingrepen dit uur gestopt" "geen rapport meer sinds $g_mins min; laatste fout: ${g_err:-onbekend}"
    elif [ "$g_state" = activating ] && [ "$g_start" -gt 0 ] && [ $(( g_now - g_start )) -gt 600 ]; then
        systemctl stop rzmon-agent.service >/dev/null 2>&1
        g_note fix "ronde hing, gestopt" "de ronde liep al $(( (g_now - g_start) / 60 )) min; geen rapport sinds $g_mins min"
    elif [ "$(systemctl is-active rzmon-agent.timer 2>/dev/null)" != active ]; then
        systemctl start rzmon-agent.timer >/dev/null 2>&1
        g_note fix "timer stond stil, weer gestart" "geen rapport sinds $g_mins min"
    elif [ "$g_last" -gt 0 ] && [ $(( g_now - g_last )) -gt 300 ] && [ "$g_state" != activating ]; then
        systemctl start --no-block rzmon-agent.service >/dev/null 2>&1
        g_note fix "geen ronde meer, er een gestart" "laatste ronde $(( (g_now - g_last) / 60 )) min geleden; geen rapport sinds $g_mins min"
    else
        # De rondes lopen, maar het rapport komt niet aan: herstarten helpt niet. Eén notitie per uur, met de reden.
        awk -F'\t' -v t=$(( g_now - 3600 )) '$1 > t && $2 == "note"' "$g_log" 2>/dev/null | grep -q . \
            || g_note note "rondes lopen, maar het rapport komt niet aan" "al $g_mins min; laatste fout: ${g_err:-onbekend}"
    fi
    exit 0
fi
