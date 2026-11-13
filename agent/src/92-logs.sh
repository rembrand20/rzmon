# ---------------------------------------------------------------- Logs verzamelen en versturen (agent 1.83.0, RzSense 1.210.0)
# Bronnen uit RzSense (tests.cache): id <tab> logsrc <tab> soort <tab> bron <tab> niveau (0 alles, 1 info en erger, 2 warning en
# erger, 3 alleen errors). Verder: 0 <tab> logship <tab> seconden tussen pakketjes, en 0 <tab> logpause <tab> tot (epoch):
# de daglimiet is bereikt, tot dan niets lezen (daarna verder waar we waren).
# Elke run gaan de nieuwe regels van elke bron achteraan in een spoolbestand. Per bestand onthoudt de agent waar hij was,
# ook na logrotatie; een nieuwe bron begint aan het einde (oude regels worden niet ingelezen). Is het tijd (of is het
# spoolbestand groter dan 8 MB), dan wordt het één pakketje (gzip) dat versleuteld naar RzSense gaat, los van het rapport.
# Met de tijdzone van de server erbij (voor regels zonder zone). Pas als RzSense het pakketje bevestigt, gaat het weg. Geen verbinding: de pakketjes blijven (samen hooguit 50 MB; daarboven
# gaan de oudste weg, en het volgende pakketje zegt hoeveel). Bestanden alleen binnen LOG_DIRS. Uitzetten: LOG_SHIP=0.
ls_dir="$k_dir/logship"
if [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tlogsrc\t' "$t_cache" && [ "${LOG_SHIP:-1}" != 0 ]; then
    (umask 077; mkdir -p "$ls_dir/state") 2>/dev/null
    ls_now=$(date +%s)
    ls_every=$(awk -F'\t' '$2 == "logship" { print $3; exit }' "$t_cache"); [[ "$ls_every" =~ ^[0-9]+$ ]] || ls_every=300
    ls_pause=$(awk -F'\t' '$2 == "logpause" { print $3; exit }' "$t_cache"); [[ "$ls_pause" =~ ^[0-9]+$ ]] || ls_pause=0
    ls_cur="$ls_dir/cur"; ls_tmp="$ls_dir/tmp"; ls_status=""
    ls_filter() {  # ls_filter <niveau>: alleen de regels van dat niveau en erger doorlaten (op de tekst)
        case "$1" in
            1) grep -viE '(^|[^[:alnum:]])(debug|trace)([^[:alnum:]]|$)' ;;
            2) grep -iE 'warn|err|crit|fatal|alert|emerg|panic|fail|denied|refused' ;;
            3) grep -iE 'err|crit|fatal|alert|emerg|panic|fail' ;;
            *) cat ;;
        esac
        return 0
    }
    ls_add() {  # ls_add <id>: regels uit $ls_tmp achteraan in het spoolbestand (zonder stuurtekens, hooguit 8000 tekens)
        [ -s "$ls_tmp" ] || return 0
        (umask 077; tr -d '\000-\010\013\014\016-\037' <"$ls_tmp" |
            awk -v id="$1" -v t="$ls_now" '{ sub(/\r$/, ""); if ($0 != "") print "L\t" id "\t" t "\t" substr($0, 1, 8000) }' >>"$ls_cur")
    }
    ls_since() {  # ls_since <statusbestand>: vanaf wanneer lezen (de eerste keer: nu, dus geen oude regels)
        local p; p=$(cat "$1" 2>/dev/null)
        [[ "$p" =~ ^[0-9]+$ ]] && [ "$p" -le "$ls_now" ] && [ "$p" -gt $((ls_now - 86400)) ] && { echo "$p"; return; }
        echo "$ls_now"
    }
    while IFS=$'\t' read -r s_id s_type s_kind s_spec s_lvl; do
        [ "$s_type" = logsrc ] && [[ "$s_id" =~ ^[0-9]+$ ]] || continue
        [ "$ls_now" -lt "$ls_pause" ] && continue
        [[ "$s_lvl" =~ ^[0-3]$ ]] || s_lvl=0
        s_err=""; s_files=""; : >"$ls_tmp"
        st="$ls_dir/state/$s_id"
        case "$s_kind" in
            file)
                s_files=0
                while IFS= read -r f; do
                    [ -f "$f" ] && [ -r "$f" ] || continue
                    case "$f" in *.gz|*.xz|*.bz2|*.zst) continue ;; esac
                    if ! log_allowed "$f"; then
                        s_err=$(log_refused "$f")
                        continue
                    fi
                    s_files=$((s_files + 1))
                    f_st="$st.$(printf '%s' "$f" | md5sum | cut -c1-16)"
                    prev=$(cat "$f_st" 2>/dev/null)
                    inode=$(stat -c %i "$f"); size=$(stat -c %s "$f")
                    p_inode=${prev%%|*}; p_pos=${prev##*|}
                    if [ -z "$prev" ]; then pos=$size   # nieuw: bij het einde beginnen
                    elif [ "$p_inode" != "$inode" ] || ! [[ "$p_pos" =~ ^[0-9]+$ ]] || [ "$p_pos" -gt "$size" ]; then pos=0   # geroteerd
                    else pos=$p_pos; fi
                    # Hooguit 2 MB per bestand per keer, en alleen volledige regels (een half geschreven regel komt de volgende keer)
                    tail -c +"$((pos + 1))" "$f" 2>/dev/null | head -c 2000000 >"$ls_tmp.c"
                    nbytes=$(stat -c %s "$ls_tmp.c")
                    if [ "$nbytes" -gt 0 ] && [ "$(tail -c 1 "$ls_tmp.c" | od -An -tx1 | tr -d ' ')" != 0a ]; then
                        nbytes=$((nbytes - $(tail -n 1 "$ls_tmp.c" | wc -c)))
                    fi
                    [ "$nbytes" -gt 0 ] && head -c "$nbytes" "$ls_tmp.c" | ls_filter "$s_lvl" >>"$ls_tmp"
                    echo "$inode|$((pos + nbytes))" >"$f_st"
                done < <(compgen -G "$s_spec" | head -n 20)
                rm -f "$ls_tmp.c"
                [ "$s_files" = 0 ] && [ -z "$s_err" ] && s_err="geen leesbaar bestand gevonden voor $s_spec"
                ;;
            journal|unit)
                if ! command -v journalctl >/dev/null 2>&1; then
                    s_err="journalctl ontbreekt op deze server"
                else
                    since=$(ls_since "$st")
                    j_opt=(-q --no-pager -o short-iso)
                    [ "$s_kind" = unit ] && j_opt+=(-u "$s_spec")
                    case "$s_lvl" in 1) j_opt+=(-p info) ;; 2) j_opt+=(-p warning) ;; 3) j_opt+=(-p err) ;; esac
                    [ "$since" -lt "$ls_now" ] && journalctl "${j_opt[@]}" --since "@$since" --until "@$((ls_now - 1))" 2>/dev/null | head -n 50000 >"$ls_tmp"
                    echo "$ls_now" >"$st"
                fi
                ;;
            docker)
                dk=""; command -v docker >/dev/null 2>&1 && dk=docker; [ -z "$dk" ] && command -v podman >/dev/null 2>&1 && dk=podman
                if [ -z "$dk" ]; then
                    s_err="docker en podman ontbreken op deze server"
                elif ! [[ "$s_spec" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]]; then
                    s_err="ongeldige containernaam"
                else
                    since=$(ls_since "$st")
                    if [ "$since" -lt "$ls_now" ]; then
                        "$dk" logs --timestamps --since "$since" --until "$ls_now" "$s_spec" >"$ls_tmp.c" 2>&1; d_rc=$?
                        if [ "$d_rc" != 0 ]; then s_err="$dk logs $s_spec: $(head -n 1 "$ls_tmp.c" | cut -c1-200)"
                        else head -n 50000 "$ls_tmp.c" | ls_filter "$s_lvl" >"$ls_tmp"; fi
                        rm -f "$ls_tmp.c"
                    fi
                    [ -z "$s_err" ] && echo "$ls_now" >"$st"
                fi
                ;;
            ha)
                case "$s_spec" in
                    host) l_api=/host/logs ;; core) l_api=/core/logs ;; supervisor) l_api=/supervisor/logs ;;
                    addon:*) l_api="/addons/${s_spec#addon:}/logs" ;; *) l_api="" ;;
                esac
                if [ "$HA_MODE" != 1 ]; then s_err="Home Assistant-logs kunnen alleen in de Home Assistant-add-on"
                elif [ -z "$l_api" ] || [[ "$l_api" == *[!A-Za-z0-9/_-]* ]]; then s_err="onbekende bron: $s_spec"
                elif ha_log_new "$l_api" "$st.mark" >"$ls_tmp.c"; then ls_filter "$s_lvl" <"$ls_tmp.c" >"$ls_tmp"
                else s_err="log van $s_spec niet beschikbaar (heeft de add-on de rol manager?)"; fi
                rm -f "$ls_tmp.c"
                ;;
            *) s_err="onbekende soort bron: $s_kind" ;;
        esac
        ls_add "$s_id"
        ls_status+="S"$'\t'"$s_id"$'\t'"$( [ -n "$s_err" ] && echo err || echo ok)"$'\t'"$s_files"$'\t'"${s_err:0:280}"$'\n'
    done <"$t_cache"
    rm -f "$ls_tmp"
    [ "$ls_now" -lt "$ls_pause" ] || (umask 077; printf '%s' "$ls_status" >"$ls_dir/status")

    # Pakketje maken: als het tijd is (en er iets nieuws is), of als het spoolbestand al groot is
    ls_last=$(cat "$ls_dir/last" 2>/dev/null); [[ "$ls_last" =~ ^[0-9]+$ ]] || ls_last=0
    if { [ -s "$ls_cur" ] || ! cmp -s "$ls_dir/status" "$ls_dir/status.sent" 2>/dev/null; } &&
        { [ $((ls_now - ls_last)) -ge "$ls_every" ] || [ "$(wc -c <"$ls_cur" 2>/dev/null || echo 0)" -gt 8000000 ]; }; then
        b_id="b${ls_now}$(od -An -N3 -tx1 /dev/urandom | tr -d ' \n')"
        if (umask 077; { printf 'rzlogs1\t%s\t%s\t%s\t%s\n' "$b_id" "$ls_now" "$(cat "$ls_dir/dropped" 2>/dev/null || echo 0)" "$(date +%z)"
            cat "$ls_dir/status" 2>/dev/null; cat "$ls_cur" 2>/dev/null; } | gzip -c -6 >"$ls_dir/$b_id.gz"); then
            rm -f "$ls_cur" "$ls_dir/dropped"
            cp -f "$ls_dir/status" "$ls_dir/status.sent" 2>/dev/null
            echo "$ls_now" >"$ls_dir/last"
        fi
    fi
    # Wachtrij hooguit 50 MB: daarboven de oudste pakketjes weg (en tellen)
    ls_b=("$ls_dir"/b*.gz)
    if [ -e "${ls_b[0]}" ]; then
        ls_total=$(cat "${ls_b[@]}" | wc -c)
        while [ "$ls_total" -gt 52428800 ] && [ ${#ls_b[@]} -gt 1 ]; do
            ls_sz=$(stat -c %s "${ls_b[0]}")
            rm -f "${ls_b[0]}"
            echo $(( $(cat "$ls_dir/dropped" 2>/dev/null || echo 0) + ls_sz )) >"$ls_dir/dropped"
            ls_total=$((ls_total - ls_sz)); ls_b=("${ls_b[@]:1}")
        done
    fi
    # Versturen: alleen versleuteld (zonder sleutel blijven de pakketjes liggen), hooguit 5 per run, oudste eerst
    if [ -e "${ls_b[0]}" ] && [ -n "$enc_key" ]; then
        ls_n=0
        for b in "${ls_b[@]}"; do
            [ -e "$b" ] || continue
            ls_n=$((ls_n + 1)); [ "$ls_n" -gt 5 ] && break
            seal "$b" "$b.enc" || break
            l_resp=$(curl -fsS -m 60 -X POST "${tls[@]}" -H "Content-Type: application/octet-stream" "${auth[@]}" --data-binary "@$b.enc" \
                "${MONITOR_URL%/}/api.php?action=agent_logs" 2>/dev/null); l_rc=$?
            rm -f "$b.enc"
            [ "$l_rc" = 0 ] || break
            l_body=$(unseal "$l_resp" "$seal_n") || break
            b_name=$(basename "$b" .gz)
            if [[ "$l_body" == *"\"batch\":\"$b_name\""* ]] && [[ "$l_body" == *'"ok":true'* ]]; then rm -f "$b"; else break; fi
        done
    elif [ -e "${ls_b[0]}" ]; then
        echo "Logs: geen sleutel om te versleutelen; de pakketjes blijven liggen (koppel de agent met een koppelcode)" >&2
    fi
elif [ -d "$ls_dir" ]; then
    rm -rf -- "${k_dir:?}/logship"   # geen bronnen meer (of uitgezet in RzSense): de wachtrij en de standen opruimen
fi
