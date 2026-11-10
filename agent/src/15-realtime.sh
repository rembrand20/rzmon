# ---------------------------------------------------------------- Realtime-watcher (--watch)
# Draait als aparte dienst (rzmon-watch). Doet zelf geen analyse: hij let alleen op en start bij een
# gebeurtenis meteen een gewone agent-run, die alles verwerkt (logins, brute force, IDS, services, bestanden)
# en die RzSense direct beoordeelt. Alleen als RzSense realtime voor deze server aanzet (regel "0 <tab> realtime <tab> 1")
# en REALTIME niet 0 is; anders wacht hij en kijkt elke minuut opnieuw.
#  - systemlog (journalctl -f): SSH-logins en mislukte pogingen, sudo, su, console, en meldingen van systemd
#    over de services die RzSense bewaakt (gestopt, gecrasht, herstart);
#  - bestanden (inotifywait, uit inotify-tools): de 🔴-paden van "Bestanden bewaken".
# Hooguit één extra run per 10 seconden, ook als er veel gebeurt (bv. tijdens een brute-force-aanval).
if [ "${1-}" = "--watch" ]; then
    w_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    mkdir -p "$w_dir" 2>/dev/null
    w_cache="$w_dir/tests.cache"
    w_self=$(readlink -f "$0")
    w_status() { printf '%s %s %s %s %s\n' "$1" "$(date +%s)" "$2" "$3" "${4:--}" >"$w_dir/watch.state" 2>/dev/null; }
    w_pids=()
    # Elke volger (een pijplijn) in een eigen procesgroep, zodat stoppen ook tail, awk en podman events meeneemt
    set -m
    w_stop() {
        local p
        for p in $(jobs -p); do kill -- -"$p" 2>/dev/null || kill "$p" 2>/dev/null; done
        w_pids=()
    }
    trap 'w_stop; w_status off - -; exit 0' TERM INT
    while true; do
        rt_on=0; edr_on=0
        [ "$REALTIME" != 0 ] && grep -q $'^0\trealtime\t1' "$w_cache" 2>/dev/null && rt_on=1
        # Endpoint-bewaking (agent 1.63.0): als RzSense het voor deze server aanzet, als root, niet op Home Assistant OS
        [ "$EDR" != 0 ] && [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && grep -q $'^0\tedr\t1' "$w_cache" 2>/dev/null && edr_on=1
        if [ "$rt_on" = 0 ] && [ "$edr_on" = 0 ]; then
            w_status off - -
            sleep 60
            continue
        fi
        w_sig=$(grep -E $'^(0\t(realtime|fim|edr|edrself)|[0-9]+\tsvc)\t' "$w_cache" 2>/dev/null | cksum)
        # Wat van RzSense zelf is (agent 1.65.0): de map en gebruiker van RzSense (alleen op zijn eigen server van belang)
        # en de adressen van RzSense; dat wordt gelogd als "toegestaan", nooit als aanval
        IFS=$'\t' read -r EDR_ROOT EDR_USER EDR_MARK < <(awk -F'\t' '$1 == "0" && $2 == "edrself" { print $3 "\t" $4 "\t" $5; exit }' "$w_cache")
        [[ "${EDR_MARK-}" =~ ^[0-9a-f]{16,64}$ ]] || EDR_MARK=""   # agent 1.67.0: geheim kenmerk in de opdrachten van RzSense
        [[ "${EDR_ROOT-}" == /?* ]] || EDR_ROOT=""
        EDR_RZIPS=""
        for u in "$MONITOR_URL" "${MONITOR_URL_FALLBACK-}"; do
            [ -n "$u" ] || continue
            h=${u#*://}; h=${h%%/*}; h=${h%:*}; h=${h#[}; h=${h%]}
            EDR_RZIPS+=" $h $(getent ahosts "$h" 2>/dev/null | awk '{ print $1 }' | sort -u | tr '\n' ' ')"
        done
        export EDR_ROOT EDR_USER EDR_RZIPS EDR_MARK
        units=$(awk -F'\t' '$2 == "svc" && $3 ~ /^[A-Za-z0-9@._:-]+$/ { gsub(/\./, "\\.", $3); printf "%s%s", (n++ ? "|" : ""), $3 }' "$w_cache")
        pat='(sshd|sshd-session)\[[0-9]+\]: (Accepted|Failed|Invalid user|Connection closed by authenticating)|sudo(\[[0-9]+\])?: |[ :]su(\[[0-9]+\])?: |login\[[0-9]+\]: '
        [ -n "$units" ] && pat+="|systemd\\[1\\]: .*($units)(\\.service)?[: ]"
        fifo="$w_dir/watch.fifo"
        rm -f "$fifo"; mkfifo -m 600 "$fifo"
        exec 7<>"$fifo"
        j=-; i=-; e=-
        if [ "$rt_on" = 0 ]; then
            :   # alleen endpoint-bewaking
        elif [ -d /run/systemd/journal ] && command -v journalctl >/dev/null 2>&1; then
            journalctl -f -n 0 -q -o short 2>/dev/null | grep --line-buffered -E "$pat" >&7 &
            w_pids+=($!); j=ok
        else
            # Zonder journald: het eerste logbestand uit AUTH_LOGS dat bestaat (zoals de agent zelf)
            for f in $AUTH_LOGS; do
                [ -r "$f" ] || continue
                tail -n 0 -F "$f" 2>/dev/null | grep --line-buffered -E "$pat" >&7 &
                w_pids+=($!); j=ok
                break
            done
        fi
        # 🔴-paden uit "Bestanden bewaken" (bij jokertekens: de map ervoor), alleen die bestaan
        roots=()
        fim_b64=$(awk -F'\t' '$1 == "0" && $2 == "fim" { print $3; exit }' "$w_cache")
        if [ -n "$fim_b64" ]; then
            while IFS=$'\t' read -r k sev root; do
                [ "$k" = p ] && [ "$sev" = 2 ] || continue
                base=${root%%[*?[]*}; [ "$base" != "$root" ] && base=${base%/*}
                [ -e "${base:-/}" ] && roots+=("${base:-/}")
            done < <(printf '%s' "$fim_b64" | base64 -d 2>/dev/null)
        fi
        if [ ${#roots[@]} -gt 0 ] && [ "$rt_on" = 1 ]; then
            if command -v inotifywait >/dev/null 2>&1; then
                inotifywait -m -r -q -e create,modify,attrib,moved_to,delete --format '%w%f' "${roots[@]}" >&7 2>/dev/null &
                w_pids+=($!); i=ok
            else
                i=missing   # inotify-tools ontbreekt: bestanden alleen via de gewone runs
            fi
        fi
        # Endpoint-sensoren: processen en bewaakte paden (auditd), netwerk, bestanden, containers
        gap=10
        if [ "$edr_on" = 1 ]; then
            e=ok; gap=5
            if [ -r /var/log/audit/audit.log ] && command -v auditctl >/dev/null 2>&1; then
                tail -n 0 -F /var/log/audit/audit.log 2>/dev/null | edr_audit_awk | while IFS= read -r l; do edr_emit "$l"; done &
                w_pids+=($!)
            else
                e=noaudit
            fi
            edr_net_loop & w_pids+=($!)
            # Bestanden en containers: niet in w_pids (zonder mappen of containers stoppen ze meteen, en dat is goed)
            edr_file_loop &
            edr_ctr_loop &
        fi
        w_status active "$j" "$i" "$e"
        last_run=0; pending=0; next_check=$(( $(date +%s) + 60 ))
        while true; do
            IFS= read -r -t 5 _ <&7 && pending=1
            now=$(date +%s)
            if [ "$pending" = 1 ] && [ $(( now - last_run )) -ge "$gap" ]; then
                RZ_FORCE=1 "$w_self" >/dev/null 2>&1
                last_run=$(date +%s); pending=0
            fi
            if [ "$now" -ge "$next_check" ]; then
                next_check=$(( now + 60 ))
                # Instellingen in RzSense veranderd, realtime uitgezet, of een volger gestopt: opnieuw opbouwen
                [ "$(grep -E $'^(0\t(realtime|fim|edr|edrself)|[0-9]+\tsvc)\t' "$w_cache" 2>/dev/null | cksum)" = "$w_sig" ] || break
                w_dead=0
                for p in "${w_pids[@]}"; do kill -0 "$p" 2>/dev/null || w_dead=1; done
                [ "$w_dead" = 1 ] && break
            fi
        done
        w_stop
        exec 7<&-
        rm -f "$fifo"
    done
fi

# Agent 1.87.0: tabs als \t, andere stuurtekens (bv. kleurcodes uit een logregel) weg: anders is de JSON ongeldig
json_escape() {
    local s
    s=$(printf '%s' "$1" | tr -d '\000-\010\013\014\016-\037\177')
    s=${s//\\/\\\\}; s=${s//\"/\\\"}; s=${s//$'\t'/\\t}; s=${s//$'\n'/\\n}; s=${s//$'\r'/\\r}
    printf '%s' "$s"
}
# Willekeurige tekst (stdin, bv. uitvoer van een commando) als JSON-tekst: geldige UTF-8, tabs en
# regeleindes als \t en \n, andere stuurtekens weg
json_text() {
    { if command -v iconv >/dev/null 2>&1; then iconv -f UTF-8 -t UTF-8 -c 2>/dev/null; else LC_ALL=C tr -cd '\11\12\15\40-\176'; fi; } |
        sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/\\t/g' | LC_ALL=C tr -d '\000-\010\013-\037' | awk 'NR > 1 { printf "\\n" } { printf "%s", $0 }'
}

