# ---------------------------------------------------------------- Services: diepere controle
lap svc_deep
# Per service: systemd-details, de poorten van zijn processen (getest op deze server), een test
# die bij de dienst past, fouten in het log van het laatste kwartier en een eventuele eigen test.
svc_detail=""
tcp_open() {  # tcp_open adres poort → 0 als er een verbinding mogelijk is
    # shellcheck disable=SC2016
    timeout 3 bash -c 'exec 3<>"/dev/tcp/$0/$1"' "$1" "$2" 2>/dev/null
}
tcp_banner() {  # tcp_banner adres poort [tekst om te sturen] → eerste regel van het antwoord
    # Eén regel lezen (read -t): veel servers houden de verbinding daarna open
    # shellcheck disable=SC2016
    timeout 5 bash -c 'exec 3<>"/dev/tcp/$0/$1" || exit 1
        [ -n "$2" ] && printf "$2" >&3
        IFS= read -r -t 3 line <&3; printf "%s" "$line"' "$1" "$2" "${3-}" 2>/dev/null | tr -d '\r'
}
probe_service() {  # probe_service naam "poort|adres …" → zet probe, probe_rc (0/1/2) en probe_msg
    local name=${1%.service} list=$2 port addr out t code first
    probe=""; probe_rc=""; probe_msg=""
    first=${list%% *}; port=${first%%|*}; addr=${first#*|}
    case "$name" in
        nginx*|httpd*|apache2*|caddy*|lighttpd*|traefik*|haproxy*)
            probe=http
            for pa in $list; do case "${pa%%|*}" in 80|8080|443|8443) port=${pa%%|*}; addr=${pa#*|}; break ;; esac; done
            [ -n "$port" ] || { probe_rc=2; probe_msg="luistert op geen enkele poort"; return; }
            local proto=http; [[ "$port" == *443 ]] && proto=https
            out=$(curl -sk -o /dev/null -m 5 -w '%{http_code} %{time_total}' "$proto://$addr:$port/" 2>/dev/null)
            code=${out%% *}; t=$(awk -v s="${out#* }" 'BEGIN { printf "%d", s * 1000 }')
            if [ -z "$code" ] || [ "$code" = 000 ]; then probe_rc=2; probe_msg="geen HTTP-antwoord op $proto://$addr:$port/"
            elif [ "$code" -ge 500 ]; then probe_rc=1; probe_msg="HTTP $code in $t ms (serverfout)"
            else probe_rc=0; probe_msg="HTTP $code in $t ms"; fi ;;
        mariadb*|mysql*)
            probe=database
            local admin; admin=$(command -v mariadb-admin || command -v mysqladmin)
            if [ -n "$admin" ]; then
                # "mysqld is alive" ook als de login geweigerd wordt: de server antwoordt dan wel
                if "$admin" --connect-timeout=5 ping >/dev/null 2>&1; then probe_rc=0; probe_msg="database antwoordt (ping)"
                else probe_rc=2; probe_msg="database antwoordt niet op ping"; fi
            elif [ -n "$port" ]; then
                if [ -n "$(tcp_banner "$addr" "$port")" ]; then probe_rc=0; probe_msg="database geeft zijn begroeting"; else probe_rc=2; probe_msg="geen begroeting op poort $port"; fi
            fi ;;
        postgresql*)
            probe=database
            if command -v pg_isready >/dev/null 2>&1; then
                out=$(pg_isready -t 5 2>&1); code=$?
                case $code in 0) probe_rc=0; probe_msg="database neemt verbindingen aan" ;; 1) probe_rc=1; probe_msg="database start op of weigert: $out" ;; *) probe_rc=2; probe_msg="database antwoordt niet" ;; esac
            fi ;;
        redis*|valkey*)
            probe=redis
            [ -n "$port" ] || return
            out=$(tcp_banner "$addr" "$port" 'PING\r\n')
            case "$out" in +PONG*|-NOAUTH*) probe_rc=0; probe_msg="antwoordt op PING" ;; *) probe_rc=2; probe_msg="geen antwoord op PING" ;; esac ;;
        ssh|sshd)
            probe=ssh
            [ -n "$port" ] || return
            out=$(tcp_banner "$addr" "$port")
            if [[ "$out" == SSH-* ]]; then probe_rc=0; probe_msg="${out:0:40}"; else probe_rc=2; probe_msg="geen SSH-begroeting op poort $port"; fi ;;
        postfix*|exim*|sendmail*|opensmtpd*)
            probe=smtp
            for pa in $list; do case "${pa%%|*}" in 25|587) port=${pa%%|*}; addr=${pa#*|}; break ;; esac; done
            [ -n "$port" ] || return
            out=$(tcp_banner "$addr" "$port")
            if [[ "$out" == 220* ]]; then probe_rc=0; probe_msg="mailserver antwoordt (SMTP 220)"; else probe_rc=2; probe_msg="geen SMTP-begroeting op poort $port"; fi ;;
        dovecot*)
            probe=imap
            for pa in $list; do [ "${pa%%|*}" = 143 ] && { port=143; addr=${pa#*|}; break; }; done
            [ "$port" = 143 ] || return
            out=$(tcp_banner "$addr" 143)
            if [[ "$out" == "* OK"* ]]; then probe_rc=0; probe_msg="IMAP antwoordt"; else probe_rc=2; probe_msg="geen IMAP-begroeting"; fi ;;
        named|bind9|unbound|pihole-FTL|dnsmasq|knot*|pdns*|coredns*)
            probe=dns
            for pa in $list; do [ "${pa%%|*}" = 53 ] && { port=53; addr=${pa#*|}; break; }; done
            [ -n "$port" ] && command -v dig >/dev/null 2>&1 || return
            if out=$(dig +time=2 +tries=1 @"$addr" -p "$port" . NS 2>&1) && [[ "$out" == *"status:"* ]]; then
                probe_rc=0; probe_msg="DNS antwoordt ($(grep -o 'status: [A-Z]*' <<<"$out" | head -1))"
            else probe_rc=2; probe_msg="DNS geeft geen antwoord"; fi ;;
        chronyd|chrony)
            probe=ntp
            command -v chronyc >/dev/null 2>&1 || return
            if chronyc -n tracking 2>/dev/null | grep -q 'Leap status *: Normal'; then probe_rc=0; probe_msg="tijd is gesynchroniseerd"
            else probe_rc=1; probe_msg="tijd is niet gesynchroniseerd"; fi ;;
        systemd-timesyncd)
            probe=ntp
            if [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ]; then probe_rc=0; probe_msg="tijd is gesynchroniseerd"
            else probe_rc=1; probe_msg="tijd is niet gesynchroniseerd"; fi ;;
    esac
}
sock_open() {  # sock_open pad → 0 = neemt verbindingen aan, 1 = niet, 2 = geen antwoord binnen 3 s
    [ -S "$1" ] || return 1
    curl -s -m 3 --unix-socket "$1" -o /dev/null http://localhost/ 2>/dev/null
    case $? in 7) return 1 ;; 28) return 2 ;; *) return 0 ;; esac   # 7 = geen verbinding; overige = verbonden
}

if [ "$HA_MODE" = 0 ] && [ "$SERVICE_DEEP" != 0 ] && [ -n "${svc_all// /}" ] && command -v systemctl >/dev/null 2>&1; then
    # Alle luisterende TCP-poorten met de bijbehorende PID's: "poort|adres|pid pid …"
    listen_map=""
    command -v ss >/dev/null 2>&1 && listen_map=$(ss -Htlnp 2>/dev/null | awk '{
        local = $4; port = local; sub(/.*:/, "", port); addr = local; sub(/:[^:]*$/, "", addr); gsub(/[\[\]]/, "", addr); sub(/%.*/, "", addr)
        pids = ""; s = $0
        while (match(s, /pid=[0-9]+/)) { pids = pids " " substr(s, RSTART + 4, RLENGTH - 4); s = substr(s, RSTART + RLENGTH) }
        print port "|" addr "|" pids }')
    read -r up_s _ < /proc/uptime
    d_dir="$t_dir/svcdeep"
    mkdir -p "$d_dir" 2>/dev/null
    for s in $svc_all; do
        info=$(systemctl show -p ActiveState -p SubState -p Type -p MainPID -p NRestarts -p ActiveEnterTimestampMonotonic -p ControlGroup "$s" 2>/dev/null)
        get() { sed -n "s/^$1=//p" <<<"$info"; }
        [ "$(get ActiveState)" = active ] || { svc_detail+="\"$(json_escape "$s")\":{\"sub\":\"$(json_escape "$(get SubState)")\"},"; rm -f "$d_dir/$s"; continue; }
        # Draait hij en is de vorige controle nog binnen het interval van de check? Dan die opnieuw meesturen.
        if due_cached "$d_dir/$s" "${ival_by_svc[$s]-}"; then
            svc_detail+=$(cat "$d_dir/$s")
            continue
        fi
        main=$(get MainPID); cg=$(get ControlGroup); mono=$(get ActiveEnterTimestampMonotonic)
        since=null
        [[ "$mono" =~ ^[0-9]+$ ]] && [ "$mono" -gt 0 ] && since=$(awk -v u="$up_s" -v m="$mono" -v n="$(date +%s)" 'BEGIN { printf "%d", n - (u - m / 1e6) }')
        # PID's van de service: zijn cgroup, of anders het hoofdproces met zijn kinderen
        pids=""
        for base in /sys/fs/cgroup /sys/fs/cgroup/systemd /sys/fs/cgroup/unified; do
            if [ -n "$cg" ] && [ -d "$base$cg" ]; then pids=$(find "$base$cg" -name cgroup.procs -exec cat {} + 2>/dev/null | tr '\n' ' '); break; fi
        done
        [ -z "$pids" ] && [[ "$main" =~ ^[1-9][0-9]*$ ]] && pids="$main $(ps -o pid= --ppid "$main" 2>/dev/null | tr '\n' ' ')"
        # Poorten van die PID's; testen op het adres waarop hij luistert (0.0.0.0/:: → 127.0.0.1)
        svc_ports=""; plist=""; pfail=""
        if [ -n "$pids" ] && [ -n "$listen_map" ]; then
            while IFS='|' read -r port addr lpids; do
                for pid in $lpids; do
                    if [[ " $pids " == *" $pid "* ]]; then
                        [[ " $svc_ports " == *" $port "* ]] && break
                        case "$addr" in ''|'*'|0.0.0.0|::) taddr=127.0.0.1 ;; *) taddr=$addr ;; esac
                        svc_ports+=" $port"; plist+=" $port|$taddr"
                        if ! tcp_open "$taddr" "$port" && ! { [ "$addr" = :: ] && tcp_open ::1 "$port"; }; then pfail+="$port,"; fi
                        break
                    fi
                done
            done <<<"$listen_map"
        fi
        # Zelf opgegeven poorten en sockets (in RzMon): altijd testen, en de dienst-test gebruikt ze
        man_list=""; man_show=""; sockets=""; sfail=""
        for m in ${manual_ports[$s]-}; do
            if [[ "$m" == /* ]]; then
                sockets+="\"$(json_escape "$m")\","
                sock_open "$m"; case $? in
                    0) ;;
                    2) sfail+="\"$(json_escape "$m: reageert niet binnen 3 seconden")\"," ;;
                    *) if [ -S "$m" ]; then sfail+="\"$(json_escape "$m: neemt geen verbindingen aan")\","; else sfail+="\"$(json_escape "$m: bestaat niet")\","; fi ;;
                esac
                continue
            fi
            m=${m#[}; mport=${m##*:}; maddr=127.0.0.1
            [[ "$m" == *:* ]] && { maddr=${m%:*}; maddr=${maddr%]}; }
            [[ "$mport" =~ ^[0-9]+$ ]] || continue
            man_list+=" $mport|$maddr"; man_show+="\"$(json_escape "$m")\","
            [[ " $svc_ports " == *" $mport "* ]] || svc_ports+=" $mport"
            tcp_open "$maddr" "$mport" || { [[ ",$pfail" == *",$mport,"* ]] || pfail+="$mport,"; }
        done
        probe_service "$s" "$( [ -n "$man_list" ] && echo "${man_list# }" || echo "${plist# }")"
        # Fouten in het log van het laatste kwartier
        log_n=null; log_last=""
        if command -v journalctl >/dev/null 2>&1; then
            log=$(journalctl -u "$s" -p err --since "-15 min" -q --no-pager -o cat 2>/dev/null)
            log_n=$(grep -c . <<<"$log"); log_last=$(tail -1 <<<"$log" | cut -c1-160)
        fi
        # Eigen test: alleen van root en niet door anderen te wijzigen (anders genegeerd)
        c_rc=null; c_msg=""
        f="$SERVICE_TEST_DIR/${s%.service}"
        if [ -f "$f" ] && [ -x "$f" ]; then
            if [ "$(stat -c '%u' "$f")" = 0 ] && ! find "$f" -perm /022 | grep -q .; then
                c_out=$(timeout 20 "$f" 2>&1); c_rc=$?
                c_msg=$(head -1 <<<"$c_out" | cut -c1-160)
                [ "$c_rc" = 124 ] && { c_rc=2; c_msg="eigen test duurde langer dan 20 seconden"; }
            else
                c_rc=1; c_msg="eigen test $f genegeerd: moet van root zijn en mag niet door anderen te wijzigen zijn"
            fi
        fi
        ports_json=$(tr ' ' '\n' <<<"${svc_ports# }" | grep . | paste -sd, -)
        d_frag="\"$(json_escape "$s")\":{\"sub\":\"$(json_escape "$(get SubState)")\",\"type\":\"$(json_escape "$(get Type)")\",\"pid\":${main:-0},\"restarts\":$(get NRestarts | grep -E '^[0-9]+$' || echo null),\"since\":$since,\"ports\":[${ports_json}],\"ports_fail\":[${pfail%,}],\"manual\":[${man_show%,}],\"sockets\":[${sockets%,}],\"sockets_fail\":[${sfail%,}],\"probe\":\"$probe\",\"probe_rc\":${probe_rc:-null},\"probe_msg\":\"$(json_escape "$probe_msg")\",\"log_errors\":$log_n,\"log_last\":\"$(json_escape "$log_last")\",\"custom_rc\":$c_rc,\"custom_msg\":\"$(json_escape "$c_msg")\"},"
        printf '%s' "$d_frag" >"$d_dir/$s" 2>/dev/null
        svc_detail+=$d_frag
    done
fi

# Extra tests die in RzMon bij een service-check zijn ingesteld (URL of poort), zie hierboven.
svc_tests=""
if [ "$HA_MODE" = 0 ]; then
    x_dir="$t_dir/svctest"
    mkdir -p "$x_dir" 2>/dev/null
    [ -r "$t_cache" ] && while IFS=$'\t' read -r t_id t_type t_target t_expect; do
        [[ "$t_id" =~ ^[0-9]+$ ]] && { [ "$t_type" = url ] || [ "$t_type" = port ]; } || continue
        if due_cached "$x_dir/$t_id" "${ival_by_id[$t_id]-}"; then
            svc_tests+=$(cat "$x_dir/$t_id")
            continue
        fi
        ok=false; msg=""
        if [ "$t_type" = url ]; then
            body=$(curl -sk -m 10 -w '\n%{http_code} %{time_total}' "$t_target" 2>&1)
            meta=${body##*$'\n'}; body=${body%$'\n'*}; code=${meta%% *}
            t=$(awk -v s="${meta#* }" 'BEGIN { printf "%d", s * 1000 }')
            if [ "$code" = 000 ] || [ -z "$code" ]; then msg="geen antwoord van $t_target"
            elif [ "$code" -ge 400 ]; then msg="HTTP $code van $t_target"
            elif [ -n "$t_expect" ] && [[ "$body" != *"$t_expect"* ]]; then msg="HTTP $code, maar \"$t_expect\" staat niet in het antwoord"
            else ok=true; msg="HTTP $code in $t ms${t_expect:+, bevat \"$t_expect\"}"; fi
        elif [ "$t_type" = port ]; then
            t_host=${t_target%:*}; t_port=${t_target##*:}
            [ "$t_host" = "$t_target" ] && t_host=127.0.0.1
            if tcp_open "$t_host" "$t_port"; then ok=true; msg="poort $t_port op $t_host antwoordt"; else msg="poort $t_port op $t_host antwoordt niet"; fi
        else
            continue
        fi
        printf '%s' "\"$t_id\":{\"ok\":$ok,\"msg\":\"$(json_escape "$msg")\"}," >"$x_dir/$t_id" 2>/dev/null
        svc_tests+="\"$t_id\":{\"ok\":$ok,\"msg\":\"$(json_escape "$msg")\"},"
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Commando-checks
lap commands
# Agent 1.69.0: taak los van deze run uitvoeren (systemd-run als de agent als service draait, anders setsid)
bg_launch() {  # bg_launch <script> <naam>
    if [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --collect --unit="$2-$(date +%s)" /bin/bash "$1" >/dev/null 2>&1
    else
        setsid bash "$1" 9>&- </dev/null >/dev/null 2>&1 &
    fi
}
cmd_state() {  # cmd_state <id> <rc> <ms> <als> <tijd>: uitvoer en exitcode als JSON in <id>.json
    printf '{"rc":%d,"out":"%s","err":"%s","ms":%d,"as":"%s","at":%d}' "$2" \
        "$(head -c 4096 "$c_dir/$1.out" 2>/dev/null | json_text)" "$(head -c 500 "$c_dir/$1.err" 2>/dev/null | json_text)" \
        "$3" "$4" "$5" >"$c_dir/$1.json"
    rm -f "$c_dir/$1.out" "$c_dir/$1.err"
}
# Regels "id <tab> cmd <tab> commando (base64) <tab> time-out <tab> nobody|root <tab> interval" uit RzMon.
# Uitvoeren in een kale omgeving, met time-out, standaard als nobody; uitvoer en exitcode gaan naar RzMon,
# dat ze vergelijkt met het verwachte antwoord. Volgens het interval van de check: tussendoor het vorige
# resultaat opnieuw meesturen. Alle commando's samen hooguit ~40 s per rapport.
cmd_results=""
if [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tcmd\t' "$t_cache"; then
    c_dir="$t_dir/cmdstate"
    mkdir -p "$c_dir" 2>/dev/null && chmod 700 "$c_dir" 2>/dev/null
    c_budget=$(( $(date +%s) + 40 ))
    while IFS=$'\t' read -r c_id c_type c_b64 c_timeout c_runas c_every; do
        [ "$c_type" = cmd ] && [[ "$c_id" =~ ^[0-9]+$ ]] || continue
        [[ "$c_timeout" =~ ^[0-9]+$ ]] && [ "$c_timeout" -ge 1 ] && [ "$c_timeout" -le 60 ] || c_timeout=10
        [[ "$c_every" =~ ^[0-9]+$ ]] || c_every=60
        [ "$c_runas" = root ] || c_runas=nobody
        case "$COMMANDS" in
            0|off|no|nee)
                cmd_results+="\"$c_id\":{\"blocked\":\"COMMANDS=0 in de instellingen van de agent\"},"
                continue ;;
            nobody) c_runas=nobody ;;
        esac
        c_state="$c_dir/$c_id.json"
        # Klaar op de achtergrond (agent 1.69.0): dat resultaat wordt nu het laatste
        if [ -s "$c_dir/$c_id.done" ] && read -r d_rc d_ms d_at <"$c_dir/$c_id.done" && [[ "$d_rc$d_ms$d_at" =~ ^-?[0-9]+$ ]]; then
            cmd_state "$c_id" "$d_rc" "$d_ms" "$c_runas" "$d_at"
            rm -f "$c_dir/$c_id.done"
        fi
        if [ -s "$c_state" ] && [ $(( $(date +%s) - $(stat -c %Y "$c_state") )) -lt $(( c_every - 5 )) ]; then
            cmd_results+="\"$c_id\":$(cat "$c_state"),"
            continue
        fi
        if [ "$(date +%s)" -ge "$c_budget" ]; then
            cmd_results+="\"$c_id\":{\"error\":\"overgeslagen: de andere commando's duurden samen te lang\"},"
            continue
        fi
        if ! c_cmd=$(printf '%s' "$c_b64" | base64 -d 2>/dev/null) || [ -z "$c_cmd" ]; then
            cmd_results+="\"$c_id\":{\"error\":\"ongeldig commando ontvangen\"},"
            continue
        fi
        c_run=()
        if [ "$c_runas" = nobody ] && [ "$(id -u)" = 0 ]; then
            if command -v runuser >/dev/null 2>&1; then c_run=(runuser -u nobody --)
            elif command -v setpriv >/dev/null 2>&1; then c_run=(setpriv --reuid=nobody --regid=nogroup --init-groups --)
            else
                cmd_results+="\"$c_id\":{\"error\":\"kan niet als nobody uitvoeren (runuser ontbreekt)\"},"
                continue
            fi
        fi
        # Agent 1.69.0: is er al een vorig resultaat, dan meet de agent opnieuw op de achtergrond en gaat nu het vorige mee.
        # Een trage check houdt zo het rapport niet meer op (en de agent is nooit "stil" door een commando).
        if [ -s "$c_state" ] && [ "$TEST" != 1 ]; then
            if ! { [ -f "$c_dir/$c_id.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$c_dir/$c_id.lock") )) -lt $(( c_timeout + 60 )) ]; }; then
                : >"$c_dir/$c_id.lock"
                (umask 077; {
                    printf 'cd / || exit 1\nt0=$(date +%%s%%N)\n'
                    printf 'env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/ LANG=C.UTF-8 %s timeout -k 2 %d /bin/bash -c %q </dev/null >%q 2>%q\n' \
                        "$( [ ${#c_run[@]} -gt 0 ] && printf '%q ' "${c_run[@]}")" "$c_timeout" "$c_cmd" "$c_dir/$c_id.out" "$c_dir/$c_id.err"
                    printf 'rc=$?\nprintf "%%s %%s %%s\\n" "$rc" "$(( ($(date +%%s%%N) - t0) / 1000000 ))" "$(date +%%s)" >%q && mv -f %q %q\nrm -f %q\n' \
                        "$c_dir/$c_id.done.tmp" "$c_dir/$c_id.done.tmp" "$c_dir/$c_id.done" "$c_dir/$c_id.lock"
                } >"$c_dir/$c_id.job") || rm -f "$c_dir/$c_id.lock"
                bg_launch "$c_dir/$c_id.job" "rzmon-cmd-$c_id" || rm -f "$c_dir/$c_id.lock"
            fi
            cmd_results+="\"$c_id\":$(cat "$c_state"),"
            continue
        fi
        c_t0=$(date +%s%N)
        (cd / && env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/ LANG=C.UTF-8 \
            "${c_run[@]}" timeout -k 2 "$c_timeout" /bin/bash -c "$c_cmd") </dev/null >"$c_dir/$c_id.out" 2>"$c_dir/$c_id.err"
        c_rc=$?
        c_ms=$(( ($(date +%s%N) - c_t0) / 1000000 ))
        cmd_state "$c_id" "$c_rc" "$c_ms" "$c_runas" "$(date +%s)"
        cmd_results+="\"$c_id\":$(cat "$c_state"),"
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Logbestanden doorzoeken
lap logs
# Per zoekopdracht (ingesteld in RzMon): alleen de nieuwe regels sinds de vorige keer lezen,
# tellen hoeveel er op het patroon passen, en de laatste 5 treffers en de IP-adressen meesturen.
# Bron: een logbestand (binnen LOG_DIRS), "journal", "journal:<service>" of "kernel".
log_results=""
log_allowed() {  # log_allowed pad → 0 als het (echte) pad binnen LOG_DIRS valt
    local real dir
    real=$(readlink -f -- "$1" 2>/dev/null) || return 1
    for dir in $LOG_DIRS; do
        dir=$(readlink -f -- "$dir" 2>/dev/null) || continue
        [[ "$real" == "$dir"/* ]] && return 0
    done
    return 1
}
if [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tlog\t' "$t_cache"; then
    l_dir="$t_dir/logstate"
    mkdir -p "$l_dir" 2>/dev/null
    now_l=$(date +%s)
    while IFS=$'\t' read -r l_id l_type l_src l_pat; do
        [ "$l_type" = log ] && [[ "$l_id" =~ ^[0-9]+$ ]] || continue
        st_file="$l_dir/$l_id"
        # Nog niet aan de beurt: "overgeslagen" melden (RzMon houdt de telling vast); de volgende keer
        # worden alle nieuwe regels sinds de vorige keer geteld, dus er gaat niets verloren
        if due_cached "$st_file" "${ival_by_id[$l_id]-}"; then
            log_results+="\"$l_id\":{\"skip\":1},"
            continue
        fi
        prev=$(cat "$st_file" 2>/dev/null)
        new_text=""; l_err=""
        case "$l_src" in
            journal|journal:*|kernel)
                # Journal: van de vorige keer tot nu; de eerste keer alleen de vorige minuut
                since=${prev##*|}; [[ "$since" =~ ^[0-9]+$ ]] || since=$((now_l - 60))
                j_opt=(); [ "$l_src" = kernel ] && j_opt=(-k); [[ "$l_src" == journal:* ]] && j_opt=(-u "${l_src#journal:}")
                if command -v journalctl >/dev/null 2>&1; then
                    new_text=$(journalctl "${j_opt[@]}" --since "@$since" --until "@$((now_l - 1))" -q --no-pager -o short-iso 2>/dev/null | head -n 20000)
                else
                    l_err="journalctl ontbreekt op deze server"
                fi
                echo "journal|$now_l" >"$st_file" ;;
            /*)
                if ! log_allowed "$l_src"; then
                    l_err="$l_src valt buiten de toegestane mappen ($LOG_DIRS); voeg de map toe aan LOG_DIRS in /etc/default/rzmon-agent"
                elif [ ! -r "$l_src" ]; then
                    l_err="$l_src bestaat niet of is niet leesbaar"
                else
                    # Bestand: verder lezen waar we gebleven waren (inode|positie); nieuw of geroteerd bestand
                    # → vanaf het begin; de eerste keer vanaf het einde (oude regels tellen niet mee)
                    inode=$(stat -c %i "$l_src"); size=$(stat -c %s "$l_src")
                    p_inode=${prev%%|*}; p_pos=${prev##*|}
                    if [ -z "$prev" ]; then pos=$size
                    elif [ "$p_inode" != "$inode" ] || ! [[ "$p_pos" =~ ^[0-9]+$ ]] || [ "$p_pos" -gt "$size" ]; then pos=0
                    else pos=$p_pos; fi
                    # Maximaal 5 MB per keer, zodat een enorm logbestand de agent niet ophoudt. Alleen volledige
                    # regels: een half geschreven laatste regel wordt de volgende keer in zijn geheel gelezen.
                    chunk="$l_dir/$l_id.chunk"
                    tail -c +"$((pos + 1))" "$l_src" 2>/dev/null | head -c 5000000 >"$chunk"
                    nbytes=$(stat -c %s "$chunk")
                    if [ "$nbytes" -gt 0 ] && [ "$(tail -c 1 "$chunk" | od -An -tx1 | tr -d ' ')" != 0a ]; then
                        nbytes=$((nbytes - $(tail -n 1 "$chunk" | wc -c)))
                    fi
                    new_text=$(head -c "$nbytes" "$chunk"); rm -f "$chunk"
                    echo "$inode|$((pos + nbytes))" >"$st_file"
                fi ;;
            ha:*)
                # Home Assistant-add-on: logs via de Supervisor
                case "$l_src" in
                    ha:host) l_api=/host/logs ;;
                    ha:core) l_api=/core/logs ;;
                    ha:supervisor) l_api=/supervisor/logs ;;
                    ha:addon:*) l_api="/addons/${l_src#ha:addon:}/logs" ;;
                    *) l_api="" ;;
                esac
                if [ "$HA_MODE" != 1 ]; then
                    l_err="$l_src kan alleen in de Home Assistant-add-on"
                elif [ -z "$l_api" ] || [[ "$l_api" == *[!A-Za-z0-9/_-]* ]]; then
                    l_err="onbekende bron: $l_src"
                elif ! new_text=$(ha_log_new "$l_api" "$st_file.mark"); then
                    l_err="log van $l_src niet beschikbaar (heeft de add-on de rol manager? Werk de add-on bij)"
                fi
                touch "$st_file" ;;
            *) l_err="onbekende bron: $l_src" ;;
        esac
        hits=0; lines_json=""; ips_json=""
        if [ -z "$l_err" ] && [ -n "$new_text" ]; then
            matches=$(grep -aiE -- "$l_pat" <<<"$new_text"); rc=$?
            if [ "$rc" = 2 ]; then
                l_err="ongeldig zoekpatroon: $l_pat"
            elif [ -n "$matches" ]; then
                hits=$(grep -c . <<<"$matches")
                while IFS= read -r line; do
                    lines_json+="\"$(json_escape "${line:0:300}")\","
                done < <(tail -n 5 <<<"$matches" | tr -d '\000-\010\013-\037')
                # IP-adressen in de treffers (bv. waar mislukte logins vandaan komen): top 3
                ips_json=$(grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' <<<"$matches" | sort | uniq -c | sort -rn | head -3 |
                    awk '{ printf "[\"%s\",%d],", $2, $1 }')
            fi
        fi
        log_results+="\"$l_id\":{\"new\":$hits,\"lines\":[${lines_json%,}],\"ips\":[${ips_json%,}],\"error\":\"$(json_escape "$l_err")\"},"
    done <"$t_cache"
fi

