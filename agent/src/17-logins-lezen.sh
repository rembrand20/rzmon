# ---------------------------------------------------------------- Logins lezen (agent 1.74.0)
# Regels "epoch <tab> programma <tab> bericht" (stdin, uit journal of auth-log) → auth_json voor RzMon:
#  - events: geslaagde logins (SSH met bronpoort, console), sudo, su, en het einde van een SSH-sessie (ssh_end)
#  - fails:  mislukte pogingen opgeteld per soort/IP/gebruiker (zoals altijd: brute force, Toegang)
#  - tries:  1.74.0: de losse mislukte SSH-pogingen (tijd, IP, gebruiker, bronpoort; hooguit 200 per rapport),
#            voor de aanvalskaart en de tijdlijn in RzMon
# Wachtwoorden in sudo-commando's (bv. mysql -pGeheim, password=…) worden hier al gemaskeerd.
# Testen zonder RzMon (tests/agent-edr.sh): rzmon-agent.sh --auth-parse < bestand
mask_secrets() {
    sed -E 's/((password|passwd|pass|pwd|token|secret|apikey|api_key)[=:])[^ ]+/\1••••/Ig;
            s/(--password[= ])[^ ]+/\1••••/g; s/((mysql|mariadb|mysqldump|mysqladmin)( [^|;&]*)? -p)[^ ]+/\1••••/g'
}
auth_parse() {
    local a_t a_id a_msg key users u s_user s_why s_tty s_pwd s_as s_cmd
    a_events=""; n_events=0; a_tries=""; n_tries=0
    declare -A f_n=() f_users=() f_t=() f_extra=()
    a_fail() {  # a_fail <sleutel> <tijd> <gebruiker> <extra json-velden>
        f_n[$1]=$(( ${f_n[$1]:-0} + 1 )); f_t[$1]=$2; f_extra[$1]=$4
        local list=" ${f_users[$1]:-} "
        [ -n "$3" ] && [[ "$list" != *" $3 "* ]] && [ "$(wc -w <<<"${f_users[$1]:-}")" -lt 8 ] && f_users[$1]+=" $3"
    }
    a_event() {  # a_event <json-object zonder accolades>
        [ "$n_events" -lt 300 ] || return 0
        a_events+="{$1},"; n_events=$((n_events + 1))
    }
    a_try() {  # a_try <tijd> <ip> <gebruiker> <bronpoort>: één mislukte SSH-poging
        [ "$n_tries" -lt 200 ] || return 0
        a_tries+="{\"t\":$1,\"ip\":\"$(json_escape "$2")\",\"u\":\"$(json_escape "$3")\"${4:+,\"p\":$4}},"; n_tries=$((n_tries + 1))
    }
    local re_ssh_ok='^Accepted ([^ ]+) for ([^ ]+) from ([^ ]+) port ([0-9]+)'
    local re_ssh_fail='^Failed ([^ ]+) for (invalid user )?([^ ]*) from ([^ ]+) port ([0-9]+)'
    local re_ssh_invalid='^Invalid user ([^ ]*) from ([^ ]+)( port ([0-9]+))?'
    local re_ssh_end='^Disconnected from user ([^ ]+) ([^ ]+) port ([0-9]+)'
    local re_login_ok='^pam_unix\(login:session\): session opened for user ([^ (]+)'
    local re_login_fail='^pam_unix\(login:auth\): authentication failure;.* tty=([^ ]*).* user=([^ ]+)'
    local re_sudo='^ *([^ ]+) : (.*)TTY=([^ ]+) ; PWD=(.*) ; USER=([^ ]+) ; (.* ; )?COMMAND=(.*)$'
    local re_su_ok='^pam_unix\(su(-l)?:session\): session opened for user ([^ (]+)(\(uid=[0-9]+\))? by ([^ (]*)'
    local re_su_fail='^pam_unix\(su(-l)?:auth\): authentication failure;.* logname=([^ ]*) .* tty=([^ ]*) .* user=([^ ]+)'
    while IFS=$'\t' read -r a_t a_id a_msg; do
        [ -n "$a_msg" ] && [[ "$a_t" =~ ^[0-9]+$ ]] || continue
        case "$a_id" in
            sshd|sshd-session)
                if [[ "$a_msg" =~ $re_ssh_ok ]]; then
                    a_event "\"t\":$a_t,\"k\":\"ssh\",\"u\":\"$(json_escape "${BASH_REMATCH[2]}")\",\"ip\":\"$(json_escape "${BASH_REMATCH[3]}")\",\"m\":\"$(json_escape "${BASH_REMATCH[1]}")\",\"p\":${BASH_REMATCH[4]}"
                elif [[ "$a_msg" =~ $re_ssh_fail ]]; then
                    # "Failed … for invalid user x": die poging telt al bij de regel "Invalid user x from …"
                    if [ -z "${BASH_REMATCH[2]}" ]; then
                        a_fail "ssh|${BASH_REMATCH[4]}" "$a_t" "${BASH_REMATCH[3]}" "\"ip\":\"$(json_escape "${BASH_REMATCH[4]}")\""
                        a_try "$a_t" "${BASH_REMATCH[4]}" "${BASH_REMATCH[3]}" "${BASH_REMATCH[5]}"
                    fi
                elif [[ "$a_msg" =~ $re_ssh_invalid ]]; then
                    a_fail "ssh|${BASH_REMATCH[2]}" "$a_t" "${BASH_REMATCH[1]:-?}" "\"ip\":\"$(json_escape "${BASH_REMATCH[2]}")\""
                    a_try "$a_t" "${BASH_REMATCH[2]}" "${BASH_REMATCH[1]:-?}" "${BASH_REMATCH[4]}"
                elif [[ "$a_msg" =~ $re_ssh_end ]]; then
                    a_event "\"t\":$a_t,\"k\":\"ssh_end\",\"u\":\"$(json_escape "${BASH_REMATCH[1]}")\",\"ip\":\"$(json_escape "${BASH_REMATCH[2]}")\",\"p\":${BASH_REMATCH[3]}"
                fi ;;
            login)
                if [[ "$a_msg" =~ $re_login_ok ]]; then
                    a_event "\"t\":$a_t,\"k\":\"console\",\"u\":\"$(json_escape "${BASH_REMATCH[1]}")\""
                elif [[ "$a_msg" =~ $re_login_fail ]]; then
                    a_fail "console|${BASH_REMATCH[1]}" "$a_t" "${BASH_REMATCH[2]}" "\"tty\":\"$(json_escape "${BASH_REMATCH[1]}")\""
                fi ;;
            sudo)
                if [[ "$a_msg" =~ $re_sudo ]]; then
                    s_user=${BASH_REMATCH[1]}; s_why=${BASH_REMATCH[2]% ; }; s_tty=${BASH_REMATCH[3]}; s_pwd=${BASH_REMATCH[4]}
                    s_as=${BASH_REMATCH[5]}; s_cmd=$(printf '%s' "${BASH_REMATCH[7]:0:500}" | mask_secrets | json_text)
                    if [ -z "$s_why" ]; then
                        a_event "\"t\":$a_t,\"k\":\"sudo\",\"u\":\"$(json_escape "$s_user")\",\"as\":\"$(json_escape "$s_as")\",\"cmd\":\"$s_cmd\",\"tty\":\"$(json_escape "$s_tty")\",\"pwd\":\"$(json_escape "${s_pwd:0:200}")\""
                    else
                        a_fail "sudo|$s_user|$s_as|${s_cmd:0:200}" "$a_t" "$s_user" "\"as\":\"$(json_escape "$s_as")\",\"cmd\":\"$s_cmd\",\"why\":\"$(json_escape "${s_why:0:150}")\""
                    fi
                fi ;;
            su|su-l)
                # Alleen van een echte gebruiker: su vanuit diensten (bv. logrotate) heeft geen naam achter "by"
                if [[ "$a_msg" =~ $re_su_ok ]] && [ -n "${BASH_REMATCH[4]}" ]; then
                    a_event "\"t\":$a_t,\"k\":\"su\",\"u\":\"$(json_escape "${BASH_REMATCH[4]}")\",\"as\":\"$(json_escape "${BASH_REMATCH[2]}")\""
                elif [[ "$a_msg" =~ $re_su_fail ]]; then
                    a_fail "su|${BASH_REMATCH[2]}|${BASH_REMATCH[4]}" "$a_t" "${BASH_REMATCH[2]:-?}" "\"as\":\"$(json_escape "${BASH_REMATCH[4]}")\",\"tty\":\"$(json_escape "${BASH_REMATCH[3]}")\""
                fi ;;
        esac
    done
    local a_fails=""
    for key in "${!f_n[@]}"; do
        users=""; for u in ${f_users[$key]:-}; do users+="\"$(json_escape "$u")\","; done
        a_fails+="{\"k\":\"${key%%|*}\",\"n\":${f_n[$key]},\"t\":${f_t[$key]},\"users\":[${users%,}]${f_extra[$key]:+,${f_extra[$key]}}},"
    done
    auth_json="\"auth\":{\"events\":[${a_events%,}],\"fails\":[${a_fails%,}],\"tries\":[${a_tries%,}]},"
}
if [ "${1-}" = "--auth-parse" ]; then
    auth_parse
    printf '{%s}\n' "${auth_json%,}"
    exit 0
fi

