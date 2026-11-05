# ---------------------------------------------------------------- Logins, sudo en su
lap logins
# Als RzMon erom vraagt (regel "0 <tab> auth <tab> 1"): de nieuwe regels van het auth-log sinds de vorige
# keer (journal, anders /var/log/secure of /var/log/auth.log). Geslaagde logins (SSH, console), sudo en su
# gaan als losse gebeurtenissen naar RzMon; mislukte pogingen worden per soort/IP/gebruiker opgeteld.
# Wachtwoorden in sudo-commando's (bv. mysql -pGeheim, password=…) worden hier al gemaskeerd.
auth_json=""
if [ "$HA_MODE" = 0 ] && [ "$AUTH_EVENTS" != 0 ] && [ -r "${t_cache-}" ] && grep -q $'^0\tauth\t1' "$t_cache"; then
    a_state="$t_dir/authstate"
    now_a=$(date +%s)
    a_prev=$(cat "$a_state" 2>/dev/null)
    a_lines=""   # regels "epoch <tab> programma <tab> bericht"
    # Journal alleen als journald echt draait; anders (of met AUTH_SOURCE=file) de logbestanden
    if [ "${AUTH_SOURCE:-}" != file ] && [ -d /run/systemd/journal ] && command -v journalctl >/dev/null 2>&1; then
        since=${a_prev#journal|}; [[ "$since" =~ ^[0-9]+$ ]] || since=$((now_a - 60))
        # Facility auth (4) en authpriv (10): sshd, login, sudo, su
        a_lines=$(journalctl SYSLOG_FACILITY=4 SYSLOG_FACILITY=10 --since "@$since" --until "@$((now_a - 1))" -q --no-pager -o short-unix 2>/dev/null |
            head -n 20000 | awk '{ t = $1; sub(/\..*/, "", t); id = $3; sub(/(\[[0-9]+\])?:$/, "", id)
                                   if (match($0, /^[^ ]+ [^ ]+ [^ ]+ /)) print t "\t" id "\t" substr($0, RLENGTH + 1) }')
        echo "journal|$now_a" >"$a_state"
    else
        for a_file in $AUTH_LOGS; do
            [ -r "$a_file" ] || continue
            # Verder lezen waar we gebleven waren (inode|positie); de eerste keer vanaf het einde
            inode=$(stat -c %i "$a_file"); size=$(stat -c %s "$a_file")
            p_inode=$(cut -d'|' -f2 <<<"$a_prev"); p_pos=$(cut -d'|' -f3 <<<"$a_prev")
            if [[ "$a_prev" != file\|* ]]; then pos=$size
            elif [ "$p_inode" != "$inode" ] || ! [[ "$p_pos" =~ ^[0-9]+$ ]] || [ "$p_pos" -gt "$size" ]; then pos=0
            else pos=$p_pos; fi
            # Hooguit 5 MB per keer en alleen volledige regels (een half geschreven regel komt de volgende keer)
            a_tmp="$t_dir/auth.chunk"
            tail -c +"$((pos + 1))" "$a_file" 2>/dev/null | head -c 5000000 >"$a_tmp"
            nbytes=$(stat -c %s "$a_tmp")
            if [ "$nbytes" -gt 0 ] && [ "$(tail -c 1 "$a_tmp" | od -An -tx1 | tr -d ' ')" != 0a ]; then
                nbytes=$((nbytes - $(tail -n 1 "$a_tmp" | wc -c)))
            fi
            a_chunk=$(head -c "$nbytes" "$a_tmp"); rm -f "$a_tmp"
            echo "file|$inode|$((pos + nbytes))" >"$a_state"
            # "Oct 21 10:00:01 host sshd[12]: …" of "2026-10-21T10:00:01+02:00 host sshd[12]: …"; tijd = nu
            a_lines=$(awk -v now="$now_a" '{
                if ($1 ~ /^[0-9]{4}-/) { h = 3 } else { h = 5 }
                id = $h; sub(/(\[[0-9]+\])?:$/, "", id)
                msg = $0; for (i = 1; i <= h; i++) { if (match(msg, /^[^ ]+ +/)) msg = substr(msg, RLENGTH + 1) }
                print now "\t" id "\t" msg }' <<<"$a_chunk")
            break
        done
    fi

    auth_parse <<<"$a_lines"   # agent 1.74.0: in 17-logins-lezen.sh
fi

# ---------------------------------------------------------------- IDS (intrusion detection)
lap ids
# Als RzMon erom vraagt (regel "0 <tab> ids <tab> learn|block"): wat RzMon nodig heeft om afwijkingen te zien.
# Vergelijken gebeurt in RzMon (daar staat de basismeting, buiten bereik van een aanvaller op deze server).
#  - gevoelige bestanden (SHA-256), gebruikers, kernelmodules, verdachte processen, verdachte verbindingen
#  - SUID-bestanden (elk uur) en pakketcontrole rpm -Va / debsums (dagelijks), op de achtergrond
#  - blokkeren: bij "block" de IP's uit RzMon in een eigen nftables-tabel "inet rzmon" (los van firewalld);
#    bij "learn" of IDS uit wordt die tabel weer verwijderd
#  - bestanden bewaken: hash, grootte, rechten en eigenaar van de paden uit RzMon (alleen wat veranderd is)
ids_json=""
fim_commit=""
ids_mode=""
[ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && ids_mode=$(awk -F'\t' '$1 == "0" && $2 == "ids" { print $3; exit }' "$t_cache")
ids_bg() {  # ids_bg <naam> <max. leeftijd in s> <commando>: op de achtergrond uitvoeren, uitvoer in $i_dir/<naam>
    local name=$1 age=$2 cmd=$3 out="$i_dir/$1"
    [ -f "$out" ] && [ $(( $(date +%s) - $(stat -c %Y "$out") )) -lt "$age" ] && return 0
    [ -f "$out.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$out.lock") )) -lt 7200 ] && return 0
    : >"$out.lock"
    local job
    job="nice -n 19 ionice -c3 bash -c $(printf '%q' "$cmd") >$(printf '%q' "$out.tmp") 2>/dev/null; mv -f $(printf '%q' "$out.tmp") $(printf '%q' "$out"); rm -f $(printf '%q' "$out.lock")"
    # In een bestand, niet als tekst op de opdrachtregel: systemd-run vult daar zelf ${…} en %-codes in
    (umask 077; printf '%s\n' "$job" >"$out.job") || { rm -f "$out.lock"; return 0; }
    if [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --collect --unit="rzmon-ids-$name-$(date +%s)" /bin/bash "$out.job" >/dev/null 2>&1 || rm -f "$out.lock"
    else
        setsid bash "$out.job" 9>&- </dev/null >/dev/null 2>&1 &
    fi
}
if [ -n "$ids_mode" ]; then
    i_dir="$t_dir/ids"
    mkdir -p "$i_dir" 2>/dev/null && chmod 700 "$i_dir" 2>/dev/null
    # 1. Gevoelige bestanden: inlog, sudo, SSH, cron, systemd-units, ld.so.preload (rootkits)
    shopt -s nullglob
    # Vanaf agent 1.54.0 ("fv":2) ook achterdeuren: PAM, hosts/resolv.conf/environment, at-jobs, de shell-bestanden
    # van root en systemd-gebruikersunits
    i_cand=(/etc/passwd /etc/shadow /etc/group /etc/gshadow /etc/sudoers /etc/sudoers.d/* /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*
        /etc/crontab /etc/cron.d/* /var/spool/cron/* /var/spool/cron/crontabs/* /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys
        /etc/systemd/system/*.service /etc/systemd/system/*.timer /etc/ld.so.preload /etc/rc.local /etc/profile.d/*.sh
        /etc/pam.d/* /etc/hosts /etc/resolv.conf /etc/environment /var/spool/at/* /var/spool/cron/atjobs/*
        /root/.bashrc /root/.bash_profile /root/.profile /etc/systemd/user/*.service /etc/systemd/user/*.timer
        /root/.config/systemd/user/*.service /root/.config/systemd/user/*.timer /home/*/.config/systemd/user/*.service /home/*/.config/systemd/user/*.timer)
    shopt -u nullglob
    i_files=()
    for f in "${i_cand[@]}"; do [ -f "$f" ] && [ -r "$f" ] && i_files+=("$f"); done
    files_json=""
    if [ ${#i_files[@]} -gt 0 ]; then
        while read -r h f; do
            [[ "$h" =~ ^[0-9a-f]{64}$ ]] && files_json+="\"$(json_escape "$f")\":\"$h\","
        done < <(sha256sum -- "${i_files[@]:0:1000}" 2>/dev/null)
    fi
    # 2. Gebruikers (naam en UID) en 3. kernelmodules
    users_json=$(awk -F: '$1 ~ /^[A-Za-z0-9._-]+$/ { printf "[\"%s\",%d],", $1, $3 }' /etc/passwd 2>/dev/null)
    # Leden van groepen die (bijna) root zijn (agent 1.54.0): wheel, sudo, docker, root, adm, lxd
    groups_json=$(awk -F: '$1 ~ /^(wheel|sudo|docker|root|adm|lxd)$/ { s = ""; n = split($4, m, ",")
        for (i = 1; i <= n; i++) if (m[i] ~ /^[A-Za-z0-9._-]+$/) s = s (s == "" ? "" : ",") "\"" m[i] "\""
        printf "\"%s\":[%s],", $1, s }' /etc/group 2>/dev/null)
    mods_json=$(awk '$1 ~ /^[A-Za-z0-9_]+$/ { printf "\"%s\",", $1 }' /proc/modules 2>/dev/null)
    # 4. SUID-bestanden (elk uur) en 5. pakketcontrole (dagelijks), op de achtergrond
    # Container-images (docker, podman) overslaan: daar staan veel SUID-bestanden die niets met de server zelf te maken hebben
    ids_bg suid 3600 "find / -xdev \\( -path /proc -o -path /sys -o -path /dev -o -path /run -o -path /var/lib/docker -o -path /var/lib/containers -o -path /var/lib/containerd -o -path '/home/*/.local/share/containers' \\) -prune -o -type f -perm -4000 -print 2>/dev/null | head -n 2000"
    if command -v rpm >/dev/null 2>&1 && { [ -d /var/lib/rpm ] || [ -d /usr/lib/sysimage/rpm ]; }; then
        # Inhoud (5) gewijzigd, geen configuratiebestanden, alleen programma's en bibliotheken
        ids_bg pkgdiff 86400 "rpm -Va --noconfig --nomtime 2>/dev/null | awk '\$1 ~ /^..5/ { print \$NF }' | grep -E '^/(usr/)?(s?bin|lib|lib64)/' | head -n 100"
    elif command -v debsums >/dev/null 2>&1; then
        ids_bg pkgdiff 86400 "debsums -c 2>/dev/null | grep -E '^/(usr/)?(s?bin|lib|lib64)/' | head -n 100"
    fi
    suid_part=""
    [ -f "$i_dir/suid" ] && suid_part=",\"suid\":[$(awk '{ gsub(/\\/, "&&"); gsub(/"/, "\\\""); printf "\"%s\",", $0 }' "$i_dir/suid" | sed 's/,$//')]"
    pkg_part=""
    [ -f "$i_dir/pkgdiff" ] && pkg_part=",\"pkgdiff\":[$(awk '{ gsub(/\\/, "&&"); gsub(/"/, "\\\""); printf "\"%s\",", $0 }' "$i_dir/pkgdiff" | sed 's/,$//')]"
    # Laatste pakketactiviteit (dnf/apt): bestanden die dan veranderen zijn waarschijnlijk de update
    pkg_t=0
    for f in /var/log/dnf.rpm.log /var/log/dnf5.log /var/log/dpkg.log /var/log/apt/history.log; do
        [ -f "$f" ] && t=$(stat -c %Y "$f") && [ "$t" -gt "$pkg_t" ] && pkg_t=$t
    done
    # 6. Verdachte processen: uit /tmp, /dev/shm of /var/tmp, zonder bestand (memfd), of programma verwijderd
    procs_json=""; n_procs=0
    for p in /proc/[0-9]*; do
        exe=$(readlink "$p/exe" 2>/dev/null) || continue
        flag=0
        case "$exe" in
            /tmp/*|/dev/shm/*|/var/tmp/*) flag=1 ;;
            /memfd:*) case "$exe" in *runc*|*crun*|*containerd*) ;; *) flag=1 ;; esac ;;
            *" (deleted)")
                # Na een update draait een dienst vaak nog met het oude (verwijderde) programma: dat is normaal
                case "${exe% (deleted)}" in /usr/*|/opt/*|/lib*|/bin/*|/sbin/*|/snap/*|/var/lib/*) ;; *) flag=1 ;; esac ;;
        esac
        [ "$flag" = 1 ] || continue
        cmd=$(tr '\0' ' ' 2>/dev/null <"$p/cmdline" | head -c 300 | json_text)
        procs_json+="{\"pid\":${p#/proc/},\"exe\":\"$(json_escape "$exe")\",\"user\":\"$(stat -c %U "$p" 2>/dev/null)\",\"cmd\":\"$cmd\"},"
        n_procs=$((n_procs + 1)); [ "$n_procs" -ge 20 ] && break
    done
    # 6b. Webshell (agent 1.56.0): een shell of netwerkprogramma dat gestart is door een webserver (nginx, apache,
    #     php-fpm …) of draait als webgebruiker (www-data …). PHP's mail() via sendmail is normaal en telt niet.
    web_json=""
    if command -v ps >/dev/null 2>&1; then
        while IFS=$'\t' read -r w_pid w_user w_tool w_web w_cmd; do
            w_start=$(awk '{ print $22 }' "/proc/$w_pid/stat" 2>/dev/null) || continue
            web_json+="{\"pid\":$w_pid,\"start\":${w_start:-0},\"user\":\"$(json_escape "$w_user")\",\"tool\":\"$(json_escape "$w_tool")\",\"web\":\"$(json_escape "$w_web")\",\"cmd\":\"$(printf '%s' "$w_cmd" | head -c 300 | json_text)\"},"
        done < <(ps -eo pid=,ppid=,user:32=,comm=,args= 2>/dev/null | awk '
            { p = $1; pp[p] = $2; us[p] = $3; cm[p] = $4; a = $0
              sub(/^[[:space:]]*[0-9]+[[:space:]]+[0-9]+[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]*/, "", a); ar[p] = a; o[++n] = p }
            END { for (i = 1; i <= n; i++) { p = o[i]; c = cm[p]
                    if (c !~ /^(sh|bash|dash|zsh|ksh|nc|ncat|netcat|socat|curl|wget|python[0-9.]*|perl|ruby|telnet|busybox)$/) continue
                    if (ar[p] ~ /(sendmail|msmtp|ssmtp|postdrop)/) continue
                    web = ""; q = pp[p]
                    for (d = 0; d < 8 && q > 1 && web == ""; d++) { if (cm[q] ~ /^(nginx|apache2|httpd|php-fpm.*|php-cgi.*|lighttpd|caddy|uwsgi|gunicorn)$/) web = cm[q]; q = pp[q] }
                    if (web == "" && us[p] ~ /^(www-data|apache|nginx|http|wwwrun|lighttpd)$/) web = "user:" us[p]
                    if (web == "") continue
                    printf "%s\t%s\t%s\t%s\t%s\n", p, us[p], c, web, ar[p]; if (++k >= 20) break } }')
    fi
    # 6c. Automatisch startende diensten en timers (agent 1.56.0): elke 10 minuten de lijst (systemctl, ingeschakeld)
    units_part=""
    if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ] \
        && { [ ! -f "$i_dir/units" ] || [ $(( $(date +%s) - $(stat -c %Y "$i_dir/units") )) -ge 600 ]; }; then
        systemctl list-unit-files --type=service,timer --state=enabled --no-legend --no-pager 2>/dev/null | awk '{ print $1 }' \
            | grep -E '^[A-Za-z0-9@._:-]+$' | head -n 500 >"$i_dir/units.tmp" && mv -f "$i_dir/units.tmp" "$i_dir/units" \
            && units_part=",\"units\":[$(awk '{ printf "\"%s\",", $0 }' "$i_dir/units" | sed 's/,$//')]"
    fi
    # 6d. Proces stoppen (agent 1.56.0, alleen in de modus blokkeren): regel "0 <tab> kill <tab> pid <tab> starttijd" van
    #     RzMon (webshell). Alleen als de starttijd klopt, dus nooit een ander proces dat dat nummer later kreeg.
    killed_json=""
    # Wat de agent meteen na het vorige rapport stopte (endpoint-bewaking, agent 1.63.0)
    [ -s "$i_dir/killed.pending" ] && killed_json=$(cat "$i_dir/killed.pending") && rm -f "$i_dir/killed.pending"
    if [ "$ids_mode" = block ]; then
        while IFS=$'\t' read -r z t k_pid k_start; do
            [ "$z" = 0 ] && [ "$t" = kill ] && [[ "$k_pid" =~ ^[0-9]+$ ]] && [[ "$k_start" =~ ^[0-9]+$ ]] || continue
            [ -f "$i_dir/killed.$k_pid.$k_start" ] && continue   # al gedaan; de regel kan nog even in de lijst staan
            k_res=weg
            if [ "$(awk '{ print $22 }' "/proc/$k_pid/stat" 2>/dev/null)" = "$k_start" ]; then
                # Nooit iets van RzMon of de agent zelf (agent 1.65.0)
                if [[ "$(cat "/proc/$k_pid/cgroup" 2>/dev/null)" =~ /rzmon-[^/]*\.service ]]; then k_res=eigen
                elif kill -KILL "$k_pid" 2>/dev/null; then k_res=ok; else k_res=fout; fi
            fi
            : >"$i_dir/killed.$k_pid.$k_start"
            killed_json+="[$k_pid,$k_start,\"$k_res\"],"
        done <"$t_cache"
        find "$i_dir" -maxdepth 1 -name 'killed.*' -mmin +1440 -delete 2>/dev/null
    fi
    # 6e. Hardening (agent 1.57.0): elk uur de instellingen die het verschil maken; RzMon geeft er een score aan
    hard_part=""
    if [ ! -f "$i_dir/hard" ] || [ $(( $(date +%s) - $(stat -c %Y "$i_dir/hard") )) -ge 3600 ]; then
        {
            if command -v sshd >/dev/null 2>&1 && sshd_t=$(sshd -T 2>/dev/null) && [ -n "$sshd_t" ]; then
                printf 'ssh_pw=%s\nssh_root=%s\nssh_empty=%s\n' "$(awk '$1 == "passwordauthentication" { print $2 }' <<<"$sshd_t")" \
                    "$(awk '$1 == "permitrootlogin" { print $2 }' <<<"$sshd_t")" "$(awk '$1 == "permitemptypasswords" { print $2 }' <<<"$sshd_t")"
            fi
            h_fw=none
            if systemctl is-active -q firewalld 2>/dev/null; then h_fw=firewalld
            elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q 'Status: active'; then h_fw=ufw
            elif nft list ruleset 2>/dev/null | grep -qE 'hook input .*policy drop'; then h_fw=nftables
            elif iptables -S INPUT 2>/dev/null | grep -qE '^-P INPUT DROP|-j (DROP|REJECT)'; then h_fw=iptables; fi
            printf 'firewall=%s\n' "$h_fw"
            h_au=none
            for u in dnf-automatic.timer dnf-automatic-install.timer dnf5-automatic.timer unattended-upgrades.service; do
                systemctl is-enabled -q "$u" 2>/dev/null && h_au=$u && break
            done
            [ "$h_au" = unattended-upgrades.service ] && ! grep -qsE 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades && h_au=none
            printf 'autoupdate=%s\n' "$h_au"
            printf 'open_db=%s\n' "$(ss -Htln 2>/dev/null | awk '{ a = $4; p = a; sub(/.*:/, "", p); h = a; sub(/:[0-9]+$/, "", h)
                if (p ~ /^(3306|5432|6379|27017|9200|11211|5984|8086)$/ && h ~ /^(0\.0\.0\.0|\*|\[::\]|::)$/) print p }' | sort -un | paste -sd, -)"
            h_dk=ok
            [ -S /var/run/docker.sock ] && [[ "$(stat -c %A /var/run/docker.sock 2>/dev/null)" == ????????w? ]] && h_dk=world
            ss -Htln 2>/dev/null | awk '{ print $4 }' | grep -qE ':2375$' && h_dk=tcp
            printf 'docker_sock=%s\n' "$h_dk"
            if findmnt -no OPTIONS /tmp 2>/dev/null | grep -q noexec; then printf 'tmp_noexec=yes\n'; else printf 'tmp_noexec=no\n'; fi
            printf 'empty_pw=%s\n' "$(awk -F: '$2 == "" { print $1 }' /etc/shadow 2>/dev/null | paste -sd, -)"
            printf 'sudo_nopw=%s\n' "$(grep -hsE '^[^#]*NOPASSWD' /etc/sudoers /etc/sudoers.d/* 2>/dev/null | awk '{ print $1 }' | sort -u | paste -sd, -)"
            if command -v getenforce >/dev/null 2>&1; then printf 'selinux=%s\n' "$(getenforce 2>/dev/null)"; fi
        } >"$i_dir/hard.tmp" 2>/dev/null && mv -f "$i_dir/hard.tmp" "$i_dir/hard"
        [ -s "$i_dir/hard" ] && hard_part=",\"hard\":{$(awk '/^[a-z_]+=/ { k = $0; sub(/=.*/, "", k); v = substr($0, length(k) + 2); gsub(/[\\"]/, "", v); printf "\"%s\":\"%s\",", k, v }' "$i_dir/hard" | sed 's/,$//')}"
    fi
    # 7. Verbindingen: een shell (of nc/socat) met een verbinding naar buiten, of uitgaand naar een verdachte poort
    conns_json=""; out_json=""; out_seen=""; n_out=0
    if command -v ss >/dev/null 2>&1; then
        listen=" $(ss -Htln 2>/dev/null | awk '{ p = $4; sub(/.*:/, "", p); printf "%s ", p }')"
        while IFS=$'\t' read -r c_procs c_pid c_local c_peer; do
            c_proc=${c_procs%%,*}
            peer_ip=${c_peer%:*}; peer_ip=${peer_ip#[}; peer_ip=${peer_ip%]}; peer_port=${c_peer##*:}; local_port=${c_local##*:}
            case "$peer_ip" in 127.*|::1|::ffff:127.*) continue ;; esac
            kind=""
            # Een shell die de verbinding vasthoudt (ook als een ander proces, zoals sleep, hem deelt)
            for c_name in ${c_procs//,/ }; do
                case "$c_name" in bash|sh|dash|zsh|ksh|nc|ncat|netcat|socat|telnet) kind=shell; c_proc=$c_name ;; esac
            done
            if [ -z "$kind" ] && [[ "$listen" != *" $local_port "* ]]; then
                [[ " 1337 4444 4445 5555 6666 6667 6697 12345 31337 " == *" $peer_port "* ]] && kind=port
            fi
            # Uitgaand (agent 1.56.0): naar welke adressen buiten je netwerk deze server verbindt, per programma
            if [[ "$listen" != *" $local_port "* ]] && [ "$n_out" -lt 100 ]; then
                case "$peer_ip" in
                    10.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*|169.254.*|fe80:*|fc*|fd*|::ffff:10.*|::ffff:192.168.*|::ffff:172.*) ;;
                    *) o_key=" ${c_proc:-?}|$peer_ip|$peer_port "
                       if [[ "$out_seen" != *"$o_key"* ]]; then
                           out_seen+="$o_key"; n_out=$((n_out + 1))
                           out_json+="[\"$(json_escape "${c_proc:-?}")\",\"$(json_escape "$peer_ip")\",${peer_port:-0}],"
                       fi ;;
                esac
            fi
            [ -n "$kind" ] && conns_json+="{\"kind\":\"$kind\",\"proc\":\"$(json_escape "$c_proc")\",\"pid\":${c_pid:-0},\"local\":\"$(json_escape "$c_local")\",\"peer\":\"$(json_escape "$c_peer")\"},"
        done < <(ss -tnpH state established 2>/dev/null | awk '{ names = ""; pid = 0; rest = $0
                while (match(rest, /\("[^"]+",pid=[0-9]+/)) { s = substr(rest, RSTART + 2, RLENGTH - 2); split(s, a, "\",pid=")
                    names = names (names == "" ? "" : ",") a[1]; if (!pid) pid = a[2]; rest = substr(rest, RSTART + RLENGTH) }
                if (names != "") print names "\t" pid "\t" $3 "\t" $4 }' | head -n 200)
    fi
    ids_json="\"ids\":{\"mode\":\"$ids_mode\",\"fv\":2,\"files\":{${files_json%,}},\"users\":[${users_json%,}],\"groups\":{${groups_json%,}},\"modules\":[${mods_json%,}]${suid_part}${pkg_part},\"pkg\":$pkg_t,\"procs\":[${procs_json%,}],\"conns\":[${conns_json%,}],\"web\":[${web_json%,}],\"out\":[${out_json%,}],\"killed\":[${killed_json%,}]${units_part}${hard_part},"

    # 8. Bestanden bewaken (regel "0 <tab> fim <tab> base64" met e <tab> epoch, p <tab> ernst <tab> pad, x <tab> uitsluiten).
    #    Elke run een snelle controle (grootte, tijd, rechten, eigenaar); alleen bij een verschil een nieuwe hash.
    #    Eén keer per dag op de achtergrond alles opnieuw hashen, voor wie de tijd van een bestand terugzet.
    #    Alleen wijzigingen gaan mee; de nieuwe stand wordt pas bewaard als RzMon het rapport heeft aangenomen.
    fim_dir="$i_dir/fim"
    fimc=""   # voorstellen voor "＋ Aanbevolen" in RzMon: gangbare mappen die hier bestaan en niet leeg zijn
    for d in /usr/local/bin /usr/local/sbin /opt /etc/nginx /etc/httpd /etc/apache2 /etc/caddy /etc/haproxy /etc/postfix /var/www /srv/www /srv/http; do
        [ -d "$d" ] && [ -n "$(ls -A "$d" 2>/dev/null | head -n 1)" ] && fimc+="\"$d\","
    done
    ids_json+="\"fimc\":[${fimc%,}],"
    fim_b64=$(awk -F'\t' '$1 == "0" && $2 == "fim" { print $3; exit }' "$t_cache")
    if [ -n "$fim_b64" ] && fim_cfg=$(printf '%s' "$fim_b64" | base64 -d 2>/dev/null) && mkdir -p "$fim_dir" 2>/dev/null; then
        chmod 700 "$fim_dir"
        fim_epoch=$(awk -F'\t' '$1 == "e" { print $2; exit }' <<<"$fim_cfg")
        fim_x=()   # uitsluiten: *.log (naam), cache/ (map), /pad/* (pad)
        while IFS=$'\t' read -r k a; do
            [ "$k" = x ] && [ -n "$a" ] || continue
            case "$a" in */) fim_x+=(! -path "*/${a%/}/*") ;; */*) fim_x+=(! -path "$a") ;; *) fim_x+=(! -name "$a") ;; esac
        done <<<"$fim_cfg"
        # Lijst: pad, grootte, tijd, rechten, eigenaar, ernst, bewaakt pad (max. 10.000 bestanden; per pad telt de eerste)
        while IFS=$'\t' read -r k sev root; do
            [ "$k" = p ] || continue
            fmt="%p\t%s\t%T@\t%m\t%u:%g\t$sev\t${root//%/%%}\n"
            base=${root%%[*?[]*}
            if [ "$base" != "$root" ]; then
                # Met jokertekens: zoeken vanaf de map vóór het eerste jokerteken; ** = elke diepte
                base=${base%/*}
                find "${base:-/}" -xdev -type f -path "${root//\*\*\//*}" "${fim_x[@]}" -printf "$fmt" 2>/dev/null
            elif [ -d "$root" ]; then
                find "$root" -xdev -type f "${fim_x[@]}" -printf "$fmt" 2>/dev/null
            elif [ -f "$root" ]; then
                find "$root" -maxdepth 0 -type f -printf "$fmt" 2>/dev/null
            fi
        done <<<"$fim_cfg" | awk -F'\t' 'NF == 7 && !seen[$1]++' | head -n 10000 >"$fim_dir/list"
        # Vorige stand: niet bij de eerste keer of na een nieuwe basismeting in RzMon (andere epoch): dan alles sturen
        fim_full=1; fim_old=/dev/null; fim_oldroots=/dev/null
        if [ -f "$fim_dir/state" ] && [ "$(cat "$fim_dir/meta" 2>/dev/null)" = "$fim_epoch" ]; then
            fim_full=0; fim_old="$fim_dir/state"; fim_oldroots="$fim_dir/roots"
        fi
        fim_rh=/dev/null
        [ -f "$i_dir/rehash" ] && [ "$i_dir/rehash" -nt "$i_dir/rehash.used" ] && fim_rh="$i_dir/rehash"
        # Hash overnemen als grootte en tijd niet veranderd zijn (of uit de dagelijkse controle); anders "?" = opnieuw hashen
        awk -F'\t' -v OFS='\t' -v big=104857600 '
            FILENAME == ARGV[1] { rs[$1] = $2 "|" $3; rh[$1] = $4; next }
            FILENAME == ARGV[2] { os[$1] = $2 "|" $3; oh[$1] = $6; next }
            { h = "?"
              if ($2 + 0 > big) h = "m" int($3)
              else if (($1 in rs) && rs[$1] == $2 "|" $3) h = rh[$1]
              else if (($1 in os) && os[$1] == $2 "|" $3) h = oh[$1]
              print $1, $2, $3, $4, $5, h, $6, $7 }' "$fim_rh" "$fim_old" "$fim_dir/list" >"$fim_dir/new0"
        awk -F'\t' '$6 == "?" { print $1 }' "$fim_dir/new0" | tr '\n' '\0' | nice -n 19 xargs -0 -r sha256sum -- 2>/dev/null >"$fim_dir/hashes"
        awk -F'\t' -v OFS='\t' 'FILENAME == ARGV[1] { nh[substr($0, 67)] = substr($0, 1, 64); next }
            $6 == "?" { if (!($1 in nh)) next; $6 = nh[$1] } { print }' "$fim_dir/hashes" "$fim_dir/new0" >"$fim_dir/state.new"
        # Wat RzMon moet weten: c = nieuw/gewijzigd, b = onder een nieuw bewaakt pad (basismeting), d = weg, u = niet meer bewaakt
        fim_json=$(awk -F'\t' -v full="$fim_full" '
            function j(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); return "\"" s "\"" }
            FILENAME == ARGV[1] { if ($1 == "p") cur[$3] = 1; next }
            FILENAME == ARGV[2] { oldroot[$0] = 1; next }
            FILENAME == ARGV[3] { old[$1] = $2 "|" $4 "|" $5 "|" $6; osev[$1] = $7; oroot[$1] = $8; next }
            { seen[$1] = 1
              e = "[" j($1) "," ($2 + 0) "," j($4) "," j($5) "," j($6) "," ($7 + 0) "," j($8) "]"
              if (full) c = c e ","
              else if (!($8 in oldroot)) b = b e ","
              else if (!(($1 in old) && old[$1] == $2 "|" $4 "|" $5 "|" $6)) c = c e "," }
            END { for (p in old) if (!(p in seen)) { if (oroot[p] in cur) d = d "[" j(p) "," (osev[p] + 0) "," j(oroot[p]) "],"; else u = u j(p) "," }
                  sub(/,$/, "", c); sub(/,$/, "", b); sub(/,$/, "", d); sub(/,$/, "", u)
                  printf "\"full\":%d,\"c\":[%s],\"b\":[%s],\"d\":[%s],\"u\":[%s]", full, c, b, d, u }' \
            <(printf '%s\n' "$fim_cfg") "$fim_oldroots" "$fim_old" "$fim_dir/state.new")
        ids_json+="\"fim\":{\"n\":$(wc -l <"$fim_dir/state.new"),$fim_json},"
        printf '%s\n' "$fim_epoch" >"$fim_dir/meta.new"
        awk -F'\t' '$1 == "p" { print $3 }' <<<"$fim_cfg" >"$fim_dir/roots.new"
        fim_commit=1
        # Dagelijks: alles opnieuw hashen, op de achtergrond
        [ -f "$fim_dir/state" ] && ids_bg rehash 86400 "bash $(printf '%q' "$(readlink -f "$0")") --fim-rehash $(printf '%q' "$fim_dir/state")"
    else
        rm -rf "$fim_dir"   # niets (meer) te bewaken
    fi
fi

# Blokkeren: eigen nftables-tabel "inet rzmon" met de IP's uit RzMon (alleen in de modus blokkeren).
# Lokaas-poort (agent 1.54.0, regel "0 <tab> decoy <tab> poort"): niemand van jou gebruikt die poort, dus elke verbinding
# is een scanner. Er luistert niets; een regel in dezelfde tabel zet het IP in een set (dc4/dc6, een uur), de agent
# stuurt die IP's mee en haalt ze na een geslaagd rapport uit de set. Ook in de modus leren (dan alleen melden).
fw_json=""; decoy_json=""; decoy_err=""; dc_sent4=""; dc_sent6=""
decoy_port=""
# 1.77.0: TrueNAS beheert zijn eigen netwerk; blokkeren en de honeypot-poort alleen als RzMon het voor deze server toestaat
tn_block=1
if command -v midclt >/dev/null 2>&1; then
    tn_block=0
    grep -q $'^0\ttruenas_fw\t1' "${t_cache:-/dev/null}" 2>/dev/null && tn_block=1
fi
[ -n "$ids_mode" ] && [ -r "${t_cache-}" ] && decoy_port=$(awk -F'\t' '$1 == "0" && $2 == "decoy" && $3 ~ /^[0-9]+$/ && $3 >= 1 && $3 <= 65535 { print $3; exit }' "$t_cache")
if [ -n "$decoy_port" ]; then
    if [ "$tn_block" = 0 ] || ! command -v nft >/dev/null 2>&1; then decoy_port=""
    elif [ -n "$(ss -Htln "sport = :$decoy_port" 2>/dev/null)" ]; then decoy_err="poort $decoy_port is hier in gebruik"; decoy_port=""; fi
fi
decoy_hits() {  # decoy_hits <set>: IP's in de lokaas-set
    nft list set inet rzmon "$1" 2>/dev/null | tr -d '\n' | sed -n 's/.*elements = {\(.*\)}.*/\1/p' | tr ',' '\n' \
        | awk '{ print $1 }' | grep -E '^[0-9a-fA-F:.]+$' | head -n 50
}
if [ -n "$decoy_port" ] && nft list table inet rzmon >/dev/null 2>&1; then
    dc_sent4=$(decoy_hits dc4 | paste -sd, -); dc_sent6=$(decoy_hits dc6 | paste -sd, -)
    for d_ip in ${dc_sent4//,/ } ${dc_sent6//,/ }; do decoy_json+="\"$d_ip\","; done
fi
# Tabel in één keer opnieuw opbouwen: blokkades (bl4/bl6) en de lokaas-poort (dc4/dc6)
fw_apply() {  # fw_apply <"counter;" of "">
    local el4="" el6="" dc="" ek4="" ek6=""
    [ -n "$want4" ] && el4=" elements = { ${want4%, } };"
    [ -n "$want6" ] && el6=" elements = { ${want6%, } };"
    [ -n "$kn4" ] && ek4=" elements = { ${kn4%, } };"
    [ -n "$kn6" ] && ek6=" elements = { ${kn6%, } };"
    # Uitgaand naar bekende aanvallers (agent 1.56.0, regel "0 <tab> outblock <tab> 1"): ook voor containers (forward)
    local ob=""
    [ "$outblock" = 1 ] && ob='  chain output { type filter hook output priority -5; policy accept; ip daddr @kn4 drop; ip6 daddr @kn6 drop; }
  chain out_fw { type filter hook forward priority -5; policy accept; ip daddr @kn4 drop; ip6 daddr @kn6 drop; }'
    [ -n "$decoy_port" ] && dc=" iifname != \"lo\" meta nfproto ipv4 tcp dport $decoy_port add @dc4 { ip saddr } drop; iifname != \"lo\" meta nfproto ipv6 tcp dport $decoy_port add @dc6 { ip6 saddr } drop;"
    { echo "table inet rzmon"; echo "delete table inet rzmon"; echo "table inet rzmon {"
      echo "  set bl4 { type ipv4_addr; $1$el4 }"
      echo "  set bl6 { type ipv6_addr; $1$el6 }"
      echo "  set kn4 { type ipv4_addr; flags interval; auto-merge;$ek4 }"
      echo "  set kn6 { type ipv6_addr; flags interval; auto-merge;$ek6 }"
      echo "  set dc4 { type ipv4_addr; flags dynamic,timeout; timeout 1h; }"
      echo "  set dc6 { type ipv6_addr; flags dynamic,timeout; timeout 1h; }"
      echo "  chain pre { type filter hook prerouting priority -150; policy accept; ip saddr @bl4 drop; ip6 saddr @bl6 drop; ip saddr @kn4 drop; ip6 saddr @kn6 drop; }"
      echo "  chain input { type filter hook input priority -5; policy accept; ip saddr @bl4 drop; ip6 saddr @bl6 drop; ip saddr @kn4 drop; ip6 saddr @kn6 drop;$dc }"
      [ -n "$ob" ] && echo "$ob"
      echo "}"; } | nft -f - 2>"$i_dir/fw.err"
}
fw_build() {  # opnieuw opbouwen als de lijst of de lokaas-poort veranderde; zet fw_err
    fw_err=""
    local sig
    sig=$(printf '%s|%s|%s|%s|%s|%s' "$want4" "$want6" "$decoy_port" "$kn4" "$kn6" "$outblock" | sha1sum | cut -c1-16)
    if ! nft list table inet rzmon >/dev/null 2>&1 || [ "$(cat "$i_dir/fw.sig" 2>/dev/null)" != "$sig" ]; then
        if fw_apply "counter;" || fw_apply ""; then
            echo "$sig" >"$i_dir/fw.sig"
        else
            fw_err=$(head -c 200 "$i_dir/fw.err" | json_text)
        fi
    fi
}
want4=""; want6=""; kn4=""; kn6=""; outblock=0
[ "$ids_mode" = block ] && grep -q $'^0\toutblock\t1' "${t_cache:-/dev/null}" 2>/dev/null && outblock=1
if [ "$ids_mode" = block ]; then
    if [ "$tn_block" = 0 ]; then
        fw_json="\"fw\":{\"state\":\"unsupported\",\"err\":\"TrueNAS: alleen detecteren\"}"
    elif [ "$IDS_BLOCK" = 0 ]; then
        fw_json="\"fw\":{\"state\":\"disabled\",\"err\":\"IDS_BLOCK=0 op deze server\"}"
        [ -n "$decoy_port" ] && fw_build
    elif ! command -v nft >/dev/null 2>&1; then
        fw_json="\"fw\":{\"state\":\"error\",\"err\":\"nft (nftables) ontbreekt\"}"
    else
        # Nooit het adres van RzMon zelf blokkeren
        rz_host=${MONITOR_URL#*://}; rz_host=${rz_host%%/*}; rz_host=${rz_host%:*}; rz_host=${rz_host#[}; rz_host=${rz_host%]}
        rz_ips=" $(getent ahosts "$rz_host" 2>/dev/null | awk '{ print $1 }' | sort -u | tr '\n' ' ') $rz_host "
        now_f=$(date +%s)
        while IFS=$'\t' read -r z t b_ip b_until; do
            # Bekende aanvallers (RzMon 1.82.0): bereiken (CIDR of begin-eind) in een eigen set
            if [ "$z" = 0 ] && [ "$t" = knet ]; then
                if [[ "$b_ip" =~ ^[0-9./-]+$ ]]; then kn4+="$b_ip, "; elif [[ "$b_ip" =~ ^[0-9a-fA-F:./-]+$ ]]; then kn6+="$b_ip, "; fi
                continue
            fi
            [ "$z" = 0 ] && [ "$t" = block ] || continue
            [[ "$b_until" =~ ^[0-9]+$ ]] && [ "$b_until" != 0 ] && [ "$b_until" -le "$now_f" ] && continue
            [[ "$rz_ips" == *" $b_ip "* ]] && continue
            [[ " $want4$want6" == *" $b_ip, "* ]] && continue   # al in de lijst (blokkade van meer servers)
            if [[ "$b_ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then want4+="$b_ip, "
            elif [[ "$b_ip" =~ ^[0-9a-fA-F:]+$ ]] && [[ "$b_ip" == *:* ]]; then want6+="$b_ip, "; fi
        done <"$t_cache"
        fw_build
        if [ -n "$fw_err" ]; then
            fw_json="\"fw\":{\"state\":\"error\",\"err\":\"$fw_err\"}"
        else
            hits=$(nft list table inet rzmon 2>/dev/null | grep -oE '[0-9a-fA-F:.]+ counter packets [0-9]+' | awk '{ printf "\"%s\":%s,", $1, $4 }')
            fw_json="\"fw\":{\"state\":\"active\",\"n\":$(printf '%s%s' "$want4" "$want6" | grep -o ',' | wc -l),\"hits\":{${hits%,}}}"
        fi
    fi
elif [ -n "$decoy_port" ]; then
    fw_build   # leren: alleen de lokaas-poort (er wordt niets geblokkeerd)
    [ -n "$fw_err" ] && decoy_err="$fw_err"
elif [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && grep -q $'^0\ttick\t' "$t_cache" \
    && command -v nft >/dev/null 2>&1 && nft list table inet rzmon >/dev/null 2>&1; then
    # Leren (zonder lokaas-poort) of IDS uit volgens een complete lijst van RzMon: niets meer blokkeren (zonder lijst: niets aanraken)
    nft delete table inet rzmon 2>/dev/null
    rm -f "${i_dir:-$t_dir/ids}/fw.sig"
fi
[ -n "$ids_json" ] && ids_json+="\"decoy\":[${decoy_json%,}],${decoy_err:+\"decoy_err\":\"$(json_escape "$decoy_err")\",}"
if [ -n "$ids_json" ]; then
    [ -n "$fw_json" ] || fw_json='"fw":{"state":"off"}'
    ids_json+="$fw_json},"
fi

# ---------------------------------------------------------------- Logs van webdiensten (agent 1.58.0)
lap weblogs
# Regels "0 <tab> weblog <tab> swag|ha|vw <tab> pad, containernaam of auto" (RzMon 1.89.0). Alleen de nieuwe regels sinds
# de vorige keer; per IP samengevat (aantallen, een paar paden met een foutcode, scanner); RzMon past de scenario's toe.
# SWAG: de access-log van nginx. Home Assistant: "invalid authentication". Vaultwarden: "Username or password is incorrect".
weblog_json=""
if [ -r "${t_cache-}" ] && grep -q $'^0\tweblog\t' "$t_cache"; then
    w_dir="$t_dir/weblog"; mkdir -p "$w_dir" 2>/dev/null
    wl_file_new() {  # wl_file_new <naam> <bestand>: nieuwe regels (inode + positie); de eerste keer alleen vastleggen
        local f=$2 st="$w_dir/$1.pos" ino size pino pos
        ino=$(stat -c %i "$f" 2>/dev/null) && size=$(stat -c %s "$f" 2>/dev/null) || return 1
        read -r pino pos 2>/dev/null <"$st"
        echo "$ino $size" >"$st"
        [ -n "$pino" ] || return 0
        if [ "$pino" != "$ino" ] || [ "${pos:-0}" -gt "$size" ]; then pos=0; fi   # geroteerd of nieuw
        tail -c +$(( ${pos:-0} + 1 )) "$f" 2>/dev/null | head -c $(( size - ${pos:-0} )) | tail -n 20000
    }
    wl_docker() {  # wl_docker <image-patroon>: naam van de eerste draaiende container met dat image (docker of podman)
        local c
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            "$c" ps --format '{{.Names}} {{.Image}}' 2>/dev/null | awk -v p="$1" 'tolower($2) ~ p { print $1; exit }' && return 0
        done
    }
    wl_mount() {  # wl_mount <container> <pad in de container>: map op de host
        local c
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            "$c" inspect -f '{{range .Mounts}}{{.Destination}} {{.Source}}{{"\n"}}{{end}}' "$1" 2>/dev/null | awk -v d="$2" '$1 == d { print $2; exit }' && return 0
        done
    }
    wl_ip_count() {  # per IP tellen: "IP aantal" → {"ip":n,…}
        sort | uniq -c | sort -rn | head -n 200 | awk '$2 ~ /^[0-9a-fA-F:.]+$/ { printf "\"%s\":%d,", $2, $1 }' | sed 's/,$//'
    }
    while IFS=$'\t' read -r z t w_src w_arg; do
        [ "$z" = 0 ] && [ "$t" = weblog ] || continue
        w_path=""; w_err=""; w_body=""; w_req=""
        case "$w_src" in
            swag)
                w_path=$w_arg
                if [ -z "$w_path" ] || [ "$w_path" = auto ]; then
                    # Zelf zoeken (één keer per dag): de container van SWAG, gangbare mappen, dan nginx zelf
                    if [ -f "$w_dir/swag.path" ] && [ $(( $(date +%s) - $(stat -c %Y "$w_dir/swag.path") )) -lt 86400 ]; then w_path=$(cat "$w_dir/swag.path")
                    else
                        w_path=""
                        c=$(wl_docker 'swag|letsencrypt'); [ -n "$c" ] && m=$(wl_mount "$c" /config) && [ -f "$m/log/nginx/access.log" ] && w_path="$m/log/nginx/access.log"
                        if [ -z "$w_path" ]; then
                            for f in /opt/swag/config/log/nginx/access.log /srv/swag/config/log/nginx/access.log /docker/swag/config/log/nginx/access.log \
                                /home/*/swag/config/log/nginx/access.log /home/*/docker/swag/config/log/nginx/access.log /var/log/nginx/access.log; do
                                [ -f "$f" ] && w_path=$f && break
                            done
                        fi
                        printf '%s\n' "$w_path" >"$w_dir/swag.path"
                    fi
                fi
                if [ -n "$w_path" ] && [ -r "$w_path" ]; then
                    # nginx "combined": IP - - [tijd] "GET /pad HTTP/1.1" status bytes "referer" "user-agent"; 444 = zonder (bekende) hostnaam
                    # Agent 1.63.0: ook de verdachte verzoeken zelf, voor de payload-inspectie in RzMon (w_req)
                    w_lines=$(wl_file_new swag "$w_path")
                    w_req=$(printf '%s\n' "$w_lines" | edr_swag_req)
                    w_body=$(printf '%s\n' "$w_lines" | awk -F'"' '
                        function js(s) { gsub(/[\\"]/, "", s); gsub(/[^ -~]/, "?", s); return substr(s, 1, 120) }
                        { split($1, a, " "); ip = a[1]; split($3, b, " "); st = b[1] + 0; split($2, r, " "); p = r[2]; ua = tolower($6)
                          if (ip !~ /^[0-9a-fA-F:.]+$/) next
                          n[ip]++; if (st == 404) c404[ip]++; if (st == 401 || st == 403) c40x[ip]++; if (st == 444) c444[ip]++
                          if (match(ua, /(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|dirbuster|gobuster|ffuf|feroxbuster|l9explore|censysinspect)/)) u[ip] = substr(ua, RSTART, RLENGTH)
                          if (st >= 400 && np[ip] < 8 && !((ip, p) in seen)) { seen[ip, p] = 1; np[ip]++; ps[ip] = ps[ip] (ps[ip] == "" ? "" : ",") "\"" js(p) "\"" } }
                        END { k = 0; for (ip in n) { if (!(ip in c404) && !(ip in c40x) && !(ip in c444) && !(ip in u)) continue
                                if (++k > 300) break
                                printf "\"%s\":[%d,%d,%d,%d,\"%s\",[%s]],", ip, n[ip], c404[ip], c40x[ip], c444[ip], u[ip], ps[ip] } }' | sed 's/,$//')
                elif [ -n "$w_path" ]; then w_err="niet leesbaar: $w_path"
                else w_err="niet gevonden"; fi ;;
            ha)
                if [ "$HA_MODE" = 1 ]; then
                    w_path="core"
                    w_body=$(ha_log_new /core/logs "$w_dir/ha.mark" | grep -i 'invalid authentication from' \
                        | sed -n 's/.*invalid authentication from \([0-9a-fA-F:.]*\).*/\1/p' | wl_ip_count)
                else
                    w_path=$w_arg
                    if [ -z "$w_path" ] || [ "$w_path" = auto ]; then
                        c=$(wl_docker 'home-assistant|homeassistant'); w_path=""
                        [ -n "$c" ] && m=$(wl_mount "$c" /config) && [ -f "$m/home-assistant.log" ] && w_path="$m/home-assistant.log"
                    fi
                    if [ -n "$w_path" ] && [ -r "$w_path" ]; then
                        w_body=$(wl_file_new ha "$w_path" | grep -i 'invalid authentication from' | sed -n 's/.*invalid authentication from \([0-9a-fA-F:.]*\).*/\1/p' | wl_ip_count)
                    else w_err="niet gevonden"; w_path=""; fi
                fi ;;
            vw)
                vw_lines=""
                if [ "$HA_MODE" = 1 ]; then
                    slug=$(sv /addons | jq -r '.data.addons[]? | select(.slug | test("vaultwarden|bitwarden")) | .slug' 2>/dev/null | head -n 1)
                    if [ -n "$slug" ]; then w_path="addon:$slug"; vw_lines=$(ha_log_new "/addons/$slug/logs" "$w_dir/vw.mark"); else w_err="niet gevonden"; fi
                elif [ -n "$w_arg" ] && [ "$w_arg" != auto ] && [ -f "$w_arg" ]; then
                    w_path=$w_arg; vw_lines=$(wl_file_new vw "$w_arg")
                else
                    c=$w_arg; { [ -z "$c" ] || [ "$c" = auto ]; } && c=$(wl_docker 'vaultwarden')
                    if [ -n "$c" ]; then
                        w_path="container:$c"
                        since=$(cat "$w_dir/vw.since" 2>/dev/null); now_v=$(date +%s); echo "$now_v" >"$w_dir/vw.since"
                        if [ -n "$since" ]; then
                            for d in docker podman; do command -v "$d" >/dev/null 2>&1 && vw_lines=$("$d" logs --since "$since" "$c" 2>&1 | tail -n 5000) && break; done
                        fi
                    else w_err="niet gevonden"; fi
                fi
                w_body=$(printf '%s\n' "$vw_lines" | grep -i 'username or password is incorrect' | sed -n 's/.*IP: \([0-9a-fA-F:.]*\).*/\1/p' | wl_ip_count) ;;
            *) continue ;;
        esac
        weblog_json+="\"$w_src\":{\"path\":\"$(json_escape "$w_path")\"${w_err:+,\"err\":\"$(json_escape "$w_err")\"},\"ips\":{$w_body}${w_req:+,\"req\":[$w_req]}},"
    done <"$t_cache"
    [ -n "$weblog_json" ] && weblog_json="\"weblog\":{${weblog_json%,}},"
fi

# ---------------------------------------------------------------- Endpoint-bewaking: regels, wachtrij en stand (agent 1.63.0)
lap edr
# auditd-regels (sleutels rzmon_*) zetten zolang het in RzMon aan staat, en weghalen als het uit gaat. Ze staan alleen
# in de kernel (auditctl), niet in /etc/audit: na een herstart zet de eerstvolgende run ze terug. De wachtrij van de
# watcher gaat mee met het rapport (hooguit 500 per keer; wat niet aankomt, blijft voor de volgende keer).
edr_json=""
edr_keys="rzmon_exec rzmon_id rzmon_persist rzmon_mod"
edr_rules() {  # edr_rules on|off: 0 = gelukt (of niets te doen)
    command -v auditctl >/dev/null 2>&1 || return 1
    local k have a p
    have=$(auditctl -l 2>/dev/null | grep -c 'key=rzmon_')
    if [ "$1" = off ]; then
        [ "$have" -gt 0 ] && for k in $edr_keys; do auditctl -D -k "$k" >/dev/null 2>&1; done
        return 0
    fi
    [ "$have" -ge 3 ] && return 0
    for k in $edr_keys; do auditctl -D -k "$k" >/dev/null 2>&1; done
    for a in b64 b32; do auditctl -a always,exit -F arch=$a -S execve -k rzmon_exec >/dev/null 2>&1; done
    for p in /etc/passwd /etc/shadow /etc/group /etc/sudoers /etc/sudoers.d; do
        [ -e "$p" ] && auditctl -w "$p" -p wa -k rzmon_id >/dev/null 2>&1
    done
    for p in /etc/ld.so.preload /etc/crontab /etc/cron.d /var/spool/cron /etc/systemd/system /root/.ssh; do
        [ -e "$p" ] && auditctl -w "$p" -p wa -k rzmon_persist >/dev/null 2>&1
    done
    auditctl -a always,exit -F arch=b64 -S init_module -S finit_module -k rzmon_mod >/dev/null 2>&1
    [ "$(auditctl -l 2>/dev/null | grep -c 'key=rzmon_')" -gt 0 ]
}
edr_want=0
[ "$EDR" != 0 ] && [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && grep -q $'^0\tedr\t1' "$t_cache" && edr_want=1
if [ "$edr_want" = 1 ] && [ "$TEST" != 1 ]; then
    e_audit=off
    if [ "$(id -u)" != 0 ]; then e_audit=noroot
    elif ! command -v auditctl >/dev/null 2>&1; then e_audit=missing
    elif ! pidof auditd >/dev/null 2>&1; then e_audit=stopped
    elif edr_rules on; then e_audit=ok
    else e_audit=locked; fi
    e_file=missing; command -v inotifywait >/dev/null 2>&1 && e_file=ok
    e_ctr=none
    if [ "${PODMAN-}" != 0 ] && command -v podman >/dev/null 2>&1; then e_ctr=podman; elif command -v docker >/dev/null 2>&1; then e_ctr=docker; fi
    e_fam=other; e_epel=0
    if command -v rpm >/dev/null 2>&1 && { command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; }; then
        e_fam=rhel; rpm -q epel-release >/dev/null 2>&1 && e_epel=1
    elif command -v apt-get >/dev/null 2>&1; then e_fam=deb; fi
    e_watch=off; e_sys=1
    [ -d /run/systemd/system ] || e_sys=0
    read -r e_ws _ _ _ e_we 2>/dev/null <"$edr_dir/watch.state"
    [ "$e_ws" = active ] && [ "$e_we" != - ] && e_watch=on
    e_drop=$(cat "$edr_dir/edr.drop" 2>/dev/null); [[ "$e_drop" =~ ^[0-9]+$ ]] || e_drop=0
    # YARA (agent 1.64.0): geïnstalleerd, en welke regels (RzMon stuurt nieuwe als de hash verschilt)
    e_yara=missing; command -v yara >/dev/null 2>&1 && e_yara=ok
    e_ysha=$(sha256sum "$edr_dir/rzmon.yar" 2>/dev/null | cut -c1-64)
    # Suricata (agent 1.64.0): nieuwe alerts uit eve.json (SURICATA=0 zet het uit; SURICATA_EVE voor een ander pad)
    e_sur=off; e_alerts=""
    if [ "${SURICATA-}" != 0 ]; then
        e_eve="${SURICATA_EVE:-/var/log/suricata/eve.json}"
        if [ -r "$e_eve" ]; then
            e_sur=ok
            e_ino=$(stat -c %i "$e_eve" 2>/dev/null); e_size=$(stat -c %s "$e_eve" 2>/dev/null)
            read -r e_pino e_pos 2>/dev/null <"$edr_dir/eve.pos"
            if [ -n "$e_pino" ]; then
                if [ "$e_pino" != "$e_ino" ] || [ "${e_pos:-0}" -gt "${e_size:-0}" ]; then e_pos=0; fi
                e_alerts=$(tail -c +$(( ${e_pos:-0} + 1 )) "$e_eve" 2>/dev/null | head -c $(( e_size - ${e_pos:-0} )) | tail -n 50000 | edr_eve_awk)
            fi
            echo "$e_ino $e_size" >"$edr_dir/eve.pos.new"
        elif command -v suricata >/dev/null 2>&1; then e_sur=noeve; fi
    fi
    # Wachtrij: wat er nog lag (niet aangekomen) plus de nieuwe gebeurtenissen
    e_send="$edr_dir/edr.sending"
    if [ -s "$edr_spool" ]; then
        mv -f "$edr_spool" "$edr_spool.tmp" 2>/dev/null && { cat "$edr_spool.tmp" >>"$e_send"; rm -f "$edr_spool.tmp"; }
    fi
    e_ev=""
    if [ -s "$e_send" ]; then
        e_ev=$(head -n 500 "$e_send" | grep -E '^\{.*\}$' | tr '\n' ',' | sed 's/,$//')
    fi
    edr_json="{\"ev\":[$e_ev],\"info\":{\"audit\":\"$e_audit\",\"file\":\"$e_file\",\"ctr\":\"$e_ctr\",\"fam\":\"$e_fam\",\"epel\":$e_epel,\"watch\":\"$e_watch\",\"systemd\":$e_sys,\"drop\":$e_drop,\"queue\":$(wc -l 2>/dev/null <"$e_send" || echo 0),\"yara\":\"$e_yara\",\"ysha\":\"$e_ysha\",\"sur\":\"$e_sur\"}${e_alerts:+,\"sur\":[$e_alerts]}}"
elif [ "$edr_want" = 0 ] && [ "$(id -u)" = 0 ] && [ "$HA_MODE" = 0 ]; then
    # Uit (in RzMon of met EDR=0): regels weg en de wachtrij leeg
    edr_rules off >/dev/null 2>&1
    rm -f "$edr_spool" "$edr_dir/edr.sending" 2>/dev/null
fi

# ---------------------------------------------------------------- Isoleren en gebruiker uitzetten (agent 1.57.0)
lap isolate
# "0 <tab> isolate <tab> tot (epoch) <tab> toegestane netwerken": alleen nog verkeer met RzMon, je LAN en het IP van de
# beheerder; al het andere (in, uit en containers) wordt tegengehouden. Eigen tabel "inet rzmon_iso". Vervalt vanzelf
# op de tijd, ook als RzMon onbereikbaar is (zo sluit je jezelf nooit voorgoed buiten).
# "0 <tab> userlock <tab> naam <tab> lock|unlock": account vergrendelen (en de processen ervan stoppen) of weer vrijgeven.
iso_json=""
if [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && [ "$(id -u)" = 0 ]; then
    iso_line=$(awk -F'\t' '$1 == "0" && $2 == "isolate" { print $3 "\t" $4; exit }' "$t_cache")
    iso_until=${iso_line%%$'\t'*}; iso_allow=${iso_line#*$'\t'}
    iso_dir="$t_dir/iso"; mkdir -p "$iso_dir" 2>/dev/null
    if [[ "$iso_until" =~ ^[0-9]+$ ]] && [ "$iso_until" -gt "$(date +%s)" ] && command -v nft >/dev/null 2>&1; then
        # Altijd bereikbaar: RzMon (en het reserve-adres) en de DNS-servers (om RzMon te vinden)
        i_ok=""
        for u in "$MONITOR_URL" "${MONITOR_URL_FALLBACK-}"; do
            [ -n "$u" ] || continue
            h=${u#*://}; h=${h%%/*}; h=${h%:*}; h=${h#[}; h=${h%]}
            i_ok+=" $h $(getent ahosts "$h" 2>/dev/null | awk '{ print $1 }' | sort -u | tr '\n' ' ')"
        done
        i_ok+=" $(awk '$1 == "nameserver" { print $2 }' /etc/resolv.conf 2>/dev/null | tr '\n' ' ')"
        a4=""; a6=""
        for n in $iso_allow $i_ok; do
            if [[ "$n" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$ ]]; then [[ " $a4" == *" $n, "* ]] || a4+="$n, "
            elif [[ "$n" =~ ^[0-9a-fA-F:]+(/[0-9]{1,3})?$ ]] && [[ "$n" == *:* ]]; then [[ " $a6" == *" $n, "* ]] || a6+="$n, "; fi
        done
        i_sig=$(printf '%s|%s' "$a4" "$a6" | sha1sum | cut -c1-16)
        if ! nft list table inet rzmon_iso >/dev/null 2>&1 || [ "$(cat "$iso_dir/sig" 2>/dev/null)" != "$i_sig" ]; then
            e4=""; e6=""
            [ -n "$a4" ] && e4=" elements = { ${a4%, } };"
            [ -n "$a6" ] && e6=" elements = { ${a6%, } };"
            if { echo "table inet rzmon_iso"; echo "delete table inet rzmon_iso"; echo "table inet rzmon_iso {"
                 echo "  set ok4 { type ipv4_addr; flags interval; auto-merge;$e4 }"
                 echo "  set ok6 { type ipv6_addr; flags interval; auto-merge;$e6 }"
                 echo "  chain input { type filter hook input priority -10; policy drop; iif lo accept; ip saddr @ok4 accept; ip6 saddr @ok6 accept; icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-advert } accept; }"
                 echo "  chain output { type filter hook output priority -10; policy drop; oif lo accept; ip daddr @ok4 accept; ip6 daddr @ok6 accept; icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit } accept; }"
                 echo "  chain iso_fw { type filter hook forward priority -10; policy drop; }"
                 echo "}"; } | nft -f - 2>"$iso_dir/err"; then
                echo "$i_sig" >"$iso_dir/sig"
            fi
        fi
        if nft list table inet rzmon_iso >/dev/null 2>&1; then iso_json="\"iso\":{\"state\":\"active\",\"until\":$iso_until},"
        else iso_json="\"iso\":{\"state\":\"error\",\"err\":\"$(head -c 200 "$iso_dir/err" 2>/dev/null | json_text)\"},"; fi
    elif command -v nft >/dev/null 2>&1 && nft list table inet rzmon_iso >/dev/null 2>&1; then
        # Tijd voorbij, of RzMon heeft het opgeheven: alles weer open
        nft delete table inet rzmon_iso 2>/dev/null; rm -f "$iso_dir/sig"
        iso_json="\"iso\":{\"state\":\"off\"},"
    elif [ -f "$iso_dir/sig" ] || [[ "$iso_until" =~ ^[0-9]+$ ]]; then
        rm -f "$iso_dir/sig"; iso_json="\"iso\":{\"state\":\"off\"},"
    fi
    ul_json=""
    while IFS=$'\t' read -r z t u_name u_act; do
        [ "$z" = 0 ] && [ "$t" = userlock ] && [[ "$u_name" =~ ^[a-z_][a-z0-9._-]{0,31}$ ]] && [ "$u_name" != root ] || continue
        [ "$u_act" = lock ] || [ "$u_act" = unlock ] || continue
        [ -f "$iso_dir/ul.$u_name.$u_act" ] && continue   # al gedaan
        if ! getent passwd "$u_name" >/dev/null; then u_res=onbekend
        elif [ "$u_act" = lock ]; then
            if usermod -L -e 1 "$u_name" 2>/dev/null; then pkill -KILL -u "$u_name" 2>/dev/null; u_res=ok; else u_res=fout; fi
        elif usermod -U -e '' "$u_name" 2>/dev/null; then u_res=ok
        else u_res=fout; fi
        rm -f "$iso_dir/ul.$u_name."*; : >"$iso_dir/ul.$u_name.$u_act"
        ul_json+="[\"$u_name\",\"$u_act\",\"$u_res\"],"
    done <"$t_cache"
    [ -n "$ul_json" ] && iso_json+="\"userlock\":[${ul_json%,}],"
fi

