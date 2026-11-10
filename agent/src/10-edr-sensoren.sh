# ---------------------------------------------------------------- Endpoint-bewaking (agent 1.63.0)
# Aan per server in RzSense (regel "0 <tab> edr <tab> 1"); EDR=0 zet het op deze server altijd uit. De realtime-watcher
# start dan sensoren die elke gebeurtenis meteen in een wachtrij zetten (edr.spool) en een run starten. RzSense toetst
# ze aan zijn regels (eigen regels en Sigma) en kan het proces laten stoppen, het IP blokkeren of de server isoleren.
#  - processen (auditd, execve), bewaakte paden (accounts, sudo, cron, systemd, ssh) en kernelmodules;
#  - netwerk (ss, elke 5 s): nieuwe uitgaande verbindingen en nieuwe luisterpoorten;
#  - bestanden (inotify): nieuwe programma's in /tmp, /var/tmp en /dev/shm, en webshells in webmappen;
#  - containers (podman/docker events): exec, en starten met --privileged.
# Processen van de agent zelf tellen niet mee; dezelfde gebeurtenis (zelfde programma, ouder, gebruiker en opdracht)
# hooguit eens per 10 minuten, tenzij hij er verdacht uitziet; hooguit 200 processen per minuut.
EDR="${EDR-}"
edr_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
edr_spool="$edr_dir/edr.spool"

edr_j() {  # edr_j <tekst>: veilig binnen "…" in JSON
    local s=${1//\\/\\\\}
    s=${s//\"/\\\"}
    printf '%s' "${s//[$'\001'-$'\037']/ }"
}

edr_emit() {  # edr_emit <json>: in de wachtrij (hooguit 2 MB) en de watcher wakker maken
    if [ -f "$edr_spool" ] && [ "$(stat -c %s "$edr_spool" 2>/dev/null || echo 0)" -gt 2097152 ]; then
        echo $(( $(cat "$edr_dir/edr.drop" 2>/dev/null || echo 0) + 1 )) >"$edr_dir/edr.drop" 2>/dev/null
        return 0
    fi
    printf '%s\n' "$1" >>"$edr_spool" 2>/dev/null
    printf 'e\n' >&7 2>/dev/null
    return 0
}

# auditd-log (stdin) → één JSON-regel per gebeurtenis met een sleutel rzmon_* (stdout)
edr_audit_awk() {
    awk -v dropf="$edr_dir/edr.drop" -v rzroot="${EDR_ROOT-}" -v rzuser="${EDR_USER-}" -v rzmark="${EDR_MARK-}" -v pidf="$edr_dir/own.pids" '
    BEGIN {
        hx = "0123456789ABCDEF"; for (i = 0; i < 16; i++) hv[substr(hx, i + 1, 1)] = i
        while ((getline l < "/etc/passwd") > 0) { n = split(l, pw, ":"); if (n >= 3) un[pw[3]] = pw[1] }
        close("/etc/passwd")
        cur = ""; minute = 0; sent = 0; drop = 0; reset = 0; ownn = 0
    }
    # Van RzSense zelf (agent 1.65.0): de agent en wat hij start (systemd-groep rzmon-*.service, of als root vanuit
    # rzmon-agent), en RzSense op zijn eigen server (PHP in de RzSense-map, als de gebruiker van RzSense of root). Kinderen
    # erven het. RzSense logt dat als "toegestaan" en grijpt er nooit op in.
    # Agent 1.67.0: ook een kind van een run van de agent (zijn PID staat in own.pids; de run kan al klaar zijn als de
    # gebeurtenis binnenkomt, dan is /proc weg), het geheime kenmerk van RzSense in de opdracht (alles wat RzSense start), en de
    # cron van RzSense (crond → sh -c php …/cron/run.php)
    function agentpid(p,   l, hit) {
        hit = 0
        if (p == 0) return 0
        while ((getline l < pidf) > 0) if (l == p) hit = 1
        close(pidf)
        return hit
    }
    function gpid(p,   s, i) {   # ouder van de ouder (uit /proc/<p>/stat, na de naam tussen haakjes)
        s = rd("/proc/" p "/stat"); i = index(s, ") ")
        if (!p || !i) return 0
        split(substr(s, i + 2), sf2, " "); return num(sf2[2])
    }
    function owner(pc, u, cmd,   cg) {
        if (num(ppid) in own) return own[num(ppid)]
        cg = rd("/proc/" num(ppid) "/cgroup") rd("/proc/" num(pid) "/cgroup")
        if (cg ~ /\/rzmon-[^\/]*\.service/) return "agent"
        if (uid == "0" && (comm ~ /^rzmon-agent/ || pc ~ /^rzmon-agent/)) return "agent"
        if (uid == "0" && (agentpid(num(ppid)) || agentpid(gpid(num(ppid))))) return "agent"
        if (rzmark != "" && (uid == "0" || u == rzuser) && index(cmd, "rzmon:" rzmark)) return "rzmon"
        if (rzroot != "" && (uid == "0" || u == rzuser) && index(cmd, rzroot "/cron/run.php")) return "rzmon"
        if (rzroot != "" && exe ~ /\/php[0-9.]*$|php-fpm/ && (uid == "0" || u == rzuser) && (index(cwd, rzroot) == 1 || index(cmd, rzroot "/cron/") || index(cmd, rzroot "/install/"))) return "rzmon"
        if (rzuser != "" && u == rzuser && pc ~ /^php/ && index(cwd, rzroot) == 1) return "rzmon"
        return ""
    }
    function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); gsub(/[\001-\037\177]/, " ", s); return s }
    function fv(l, k,   p, r, q) {
        p = index(l, " " k "=")
        if (!p) return ""
        r = substr(l, p + length(k) + 2)
        if (substr(r, 1, 1) == "\"") { r = substr(r, 2); q = index(r, "\""); return q ? substr(r, 1, q - 1) : r }
        q = index(r, " "); return q ? substr(r, 1, q - 1) : r
    }
    function quoted(l, k,   p) { p = index(l, " " k "="); return p && substr(l, p + length(k) + 2, 1) == "\"" }
    function unhex(s,   o, i, c) {
        s = toupper(s); o = ""
        for (i = 1; i < length(s); i += 2) {
            c = hv[substr(s, i, 1)] * 16 + hv[substr(s, i + 1, 1)]
            o = o (c >= 32 && c < 127 ? sprintf("%c", c) : (c == 0 ? " " : "?"))
        }
        return o
    }
    function arg(l, k,   v) {
        v = fv(l, k)
        if (quoted(l, k)) return v
        if (v == "(null)") return ""
        if (v ~ /^[0-9A-Fa-f]+$/ && length(v) % 2 == 0) return unhex(v)
        return v
    }
    function rd(f,   x) { x = ""; if ((getline x < f) <= 0) x = ""; close(f); return x }
    function num(v) { return v ~ /^[0-9]+$/ ? v : 0 }
    function flush(   ts, pc, u, sus, k, s, sf, st, cmd, i, out, nm) {
        if (cur == "" || ekey == "") { cur = ""; return }
        ts = cur; sub(/\..*/, "", ts); ts = ts + 0
        if (ts - reset > 600) { split("", seen); reset = ts }
        u = (uid in un) ? un[uid] : uid
        if (ekey == "rzmon_exec") {
            pc = rd("/proc/" num(ppid) "/comm")
            cmd = ""
            for (i = 0; i < argc && i < 16; i++) cmd = cmd (i ? " " : "") av[i]
            ow = owner(pc, u, cmd)
            if (ow != "") {
                if (++ownn > 5000) { split("", own); ownn = 0 }
                own[num(pid)] = ow
                # Eigen processen: per programma en ouder hooguit eens per 10 minuten, en niet binnen de grens van 200
                k = "own|" exe "|" pc; gsub(/[0-9]+/, "#", k)
                if (k in seen) { cur = ""; return }
                seen[k] = 1
                print "{\"k\":\"x\",\"t\":" ts ",\"pid\":" num(pid) ",\"ppid\":" num(ppid) ",\"uid\":" num(uid) ",\"u\":\"" esc(u) "\",\"exe\":\"" esc(exe) \
                    "\",\"comm\":\"" esc(comm) "\",\"pc\":\"" esc(pc) "\",\"cwd\":\"" esc(cwd) "\",\"cmd\":\"" esc(substr(cmd, 1, 300)) "\",\"own\":\"" ow "\"}"
                fflush(); cur = ""; return
            }
            sus = (pc ~ /^(nginx|apache2|httpd|lighttpd|caddy|php|uwsgi|gunicorn)/ || exe ~ /^\/(tmp|var\/tmp|dev\/shm|run\/shm)\// || exe ~ /\/\.[^\/]*$/ \
                || u ~ /^(www-data|apache|nginx|http|wwwrun|lighttpd)$/ \
                || tolower(cmd) ~ /(\/dev\/(tcp|udp)\/|\| *(sudo +)?(ba|z|da)?sh( |$)|base64 +(-d|--decode)|(^| )(nc|ncat|netcat|socat|telnet|xmrig|insmod|chattr|mkfifo|curl|wget)( |$)|stratum\+|authorized_keys|history +-c|histfile|ld\.so\.preload|python[0-9.]* +-c|perl +-e|php +-r|ruby +-e)/)
            k = exe "|" pc "|" uid "|" substr(cmd, 1, 200); gsub(/[0-9]+/, "#", k)
            if (!sus && (k in seen)) { cur = ""; return }
            seen[k] = 1
            if (int(ts / 60) != minute) { minute = int(ts / 60); sent = 0 }
            if (++sent > 200) { drop++; print drop > dropf; close(dropf); cur = ""; return }
            st = ""; s = rd("/proc/" num(pid) "/stat"); if (s != "") { split(s, sf, " "); st = sf[22] }
            out = "{\"k\":\"x\",\"t\":" ts ",\"pid\":" num(pid) ",\"ppid\":" num(ppid) (st ~ /^[0-9]+$/ ? ",\"st\":" st : "") ",\"uid\":" num(uid) ",\"euid\":" num(euid) \
                ",\"auid\":" num(auid) ",\"u\":\"" esc(u) "\",\"exe\":\"" esc(exe) "\",\"comm\":\"" esc(comm) "\",\"pc\":\"" esc(pc) "\",\"cwd\":\"" esc(cwd) "\",\"argv\":["
            for (i = 0; i < argc && i < 16; i++) out = out (i ? "," : "") "\"" esc(substr(av[i], 1, 200)) "\""
            print out "],\"cmd\":\"" esc(substr(cmd, 1, 1000)) "\",\"sc\":\"execve\"}"
        } else {
            nm = pname
            if (nm != "" && substr(nm, 1, 1) != "/" && cwd != "") nm = cwd "/" nm
            k = ekey "|" nm "|" exe
            if (k in seen) { cur = ""; return }
            seen[k] = 1
            pc = rd("/proc/" num(ppid) "/comm"); cmd = ""
            ow = (num(pid) in own) ? own[num(pid)] : owner(pc, u, cmd)
            print "{\"k\":\"a\",\"t\":" ts ",\"key\":\"" esc(ekey) "\",\"pid\":" num(pid) ",\"uid\":" num(uid) ",\"auid\":" num(auid) ",\"u\":\"" esc(u) \
                "\",\"exe\":\"" esc(exe) "\",\"comm\":\"" esc(comm) "\",\"name\":\"" esc(nm) "\"" (ow != "" ? ",\"own\":\"" ow "\"" : "") "}"
        }
        fflush()
        cur = ""
    }
    {
        line = $0
        g = index(line, "\035"); if (g) line = substr(line, 1, g - 1)   # ENRICHED-formaat: alleen het ruwe deel
        line = " " line
        m = index(line, "msg=audit(")
        if (!m) next
        id = substr(line, m + 10); id = substr(id, 1, index(id, ")") - 1)
        if (id != cur) { flush(); cur = id; ekey = ""; argc = 0; split("", av); pname = ""; cwd = "" }
        t = fv(line, "type")
        if (t == "SYSCALL") {
            ekey = fv(line, "key"); if (ekey !~ /^rzmon_/) ekey = ""
            pid = fv(line, "pid"); ppid = fv(line, "ppid"); uid = fv(line, "uid"); euid = fv(line, "euid"); auid = fv(line, "auid")
            comm = arg(line, "comm"); exe = arg(line, "exe")
        } else if (t == "EXECVE") {
            argc = fv(line, "argc") + 0
            for (i = 0; i < argc && i < 16; i++) av[i] = arg(line, "a" i)
        } else if (t == "CWD") {
            cwd = arg(line, "cwd")
        } else if (t == "PATH") {
            if (fv(line, "nametype") != "PARENT") pname = arg(line, "name")
        } else if (t == "EOE") {
            flush()
        }
    }
    END { flush() }'
}

# nginx-log van de reverse proxy (stdin) → verdachte verzoeken als JSON (zonder haakjes), hooguit 100:
# [ip, tijd, methode, pad+query, status, bytes, user-agent, verwijzer, site, dienst]; RzSense beoordeelt ze precies
edr_swag_req() {
    awk -F'"' '
    function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); gsub(/[\001-\037\177]/, " ", s); return s }
    {
        split($1, a, " "); ip = a[1]
        if (ip !~ /^[0-9a-fA-F:.]+$/) next
        tm = $1; sub(/^[^[]*\[/, "", tm); sub(/\].*$/, "", tm)
        req = $2; m = req; sub(/ .*/, "", m); uri = req; sub(/^[^ ]* /, "", uri); sub(/ HTTP\/[0-9.]+$/, "", uri)
        split($3, b, " "); st = b[1] + 0; by = b[2] + 0
        ref = $4; ua = $6
        l = tolower(req " " ua)
        if (l !~ /(%27|%22|%3c|%3e|%24%7b|%2e%2e|\.\.\/|\$\{|\$\(|`|;|\||<|>|'"'"'|\\x22|union|select|sleep\(|\/etc\/|cmd=|exec|eval|system\(|base64|php:\/\/|jndi|\.env|\.git\/|wp-login|xmlrpc|phpunit|cgi-bin|boaform|hnap1|actuator|\/shell\?|\(\) *\{|sqlmap|nikto|nuclei|masscan|zgrab|wpscan)/ \
            && m ~ /^(GET|POST|HEAD|OPTIONS|-)$/) next
        if (++n > 100) exit
        # Agent 1.86.0: met de site en de dienst erachter als de log die heeft (rzsense.log, Nginx Proxy Manager)
        printf "%s[\"%s\",\"%s\",\"%s\",\"%s\",%d,%d,\"%s\",\"%s\",\"%s\",\"%s\"]", (n > 1 ? "," : ""), ip, esc(tm), esc(substr(m, 1, 20)), esc(substr(uri, 1, 512)),
            st, by, esc(substr(ua, 1, 200)), esc(substr(ref, 1, 200)), esc(substr($8, 1, 120)), esc(substr($10, 1, 80))
    }'
}

edr_npm_norm() {  # Nginx Proxy Manager (stdin) → combined met "site" "dienst" erachter (agent 1.86.0)
    # [tijd zone] cache upstream-status status - METHODE schema site "pad" [Client IP] [Length n] [Gzip x] [Sent-to dienst] "agent" "verwijzer"
    awk -F'"' 'NF >= 6 { split($1, a, " "); tm = a[1] " " a[2]; gsub(/[][]/, "", tm)
        ip = $3; sub(/.*\[Client /, "", ip); sub(/\].*/, "", ip)
        by = $3; if (by ~ /\[Length /) { sub(/.*\[Length /, "", by); sub(/\].*/, "", by) } else by = 0
        up = $3; if (up ~ /\[Sent-to / && a[4] != "-") { sub(/.*\[Sent-to /, "", up); sub(/\].*/, "", up) } else up = "-"
        printf "%s - - [%s] \"%s %s HTTP/1.1\" %s %d \"%s\" \"%s\" \"%s\" \"%s\"\n", ip, tm, a[7], $2, a[5], by + 0, $6, $4, a[9], up }'
}

# Van RzSense zelf? (agent 1.65.0) edr_own <pid> <programma> <gebruiker> [<ip>]: agent, rzmon, net (verkeer met RzSense) of leeg
edr_own() {
    local cg=""
    [[ "$1" =~ ^[0-9]+$ ]] && [ "$1" -gt 0 ] && cg=$(cat "/proc/$1/cgroup" 2>/dev/null)
    if [[ "$cg" =~ /rzmon-[^/]*\.service ]]; then echo agent; return 0; fi
    if [ -n "${EDR_ROOT-}" ] && [[ "$1" =~ ^[0-9]+$ ]] && { [ "$3" = root ] || [ "$3" = "${EDR_USER-}" ]; } && [[ "$2" == php* ]] \
        && { [[ "$(readlink "/proc/$1/cwd" 2>/dev/null)" == "$EDR_ROOT"* ]] || tr '\0' ' ' 2>/dev/null <"/proc/$1/cmdline" | grep -qF -- "$EDR_ROOT/cron/"; }; then
        echo rzmon; return 0
    fi
    if [ -n "${4-}" ] && [[ " ${EDR_RZIPS-} " == *" $4 "* ]]; then echo net; fi
    return 0
}

edr_proc() {  # edr_proc <pid>: "starttijd<tab>programma<tab>gebruiker" (leeg als het proces al weg is)
    local p=$1 st exe u
    [[ "$p" =~ ^[0-9]+$ ]] && [ -r "/proc/$p/stat" ] || return 0
    st=$(awk '{ print $22 }' "/proc/$p/stat" 2>/dev/null)
    exe=$(readlink "/proc/$p/exe" 2>/dev/null)
    u=$(stat -c %U "/proc/$p" 2>/dev/null)
    printf '%s\t%s\t%s' "$st" "$exe" "$u"
}

# Netwerk: elke 5 s nieuwe uitgaande verbindingen (zelfde programma, adres en poort hooguit eens per uur) en nieuwe
# luisterpoorten. De eerste keer alleen vastleggen.
edr_net_once() {  # edr_net_once <1 = eerste keer>
    local lp out pid comm rip rp lport st exe u now ow
    now=$(date +%s)
    lp=$(ss -ltnH 2>/dev/null | awk '{ n = split($4, a, ":"); print a[n] }' | sort -u | tr '\n' ' ')
    out=$(ss -tnpH state established 2>/dev/null | awk -v lp=" $lp" -v sf="$edr_dir/net.state" -v now="$now" '
        BEGIN { FS = "[ \t]+"; while ((getline l < sf) > 0) { split(l, a, "\t"); if (now - a[2] < 3600) seen[a[1]] = a[2] }; close(sf) }
        {
            nl = split($3, L, ":"); lport = L[nl]
            if (index(lp " ", " " lport " ")) next
            np = split($4, P, ":"); rport = P[np]; rip = substr($4, 1, length($4) - length(rport) - 1)
            gsub(/^\[|\]$/, "", rip); sub(/^::ffff:/, "", rip)
            if (rip ~ /^127\./ || rip == "::1" || rip == "") next
            comm = ""; pid = 0
            if (match($0, /users:\(\("[^"]*",pid=[0-9]+/)) { u = substr($0, RSTART + 9, RLENGTH - 9); comm = u; sub(/".*/, "", comm); pid = u; sub(/.*pid=/, "", pid) }
            k = comm "|" rip "|" rport
            if (k in seen) next
            seen[k] = now
            print pid "\t" comm "\t" rip "\t" rport "\t" lport
        }
        END { for (k in seen) print k "\t" seen[k] > sf }')
    if [ "$1" != 1 ] && [ -n "$out" ]; then
        while IFS=$'\t' read -r pid comm rip rp lport; do
            st=""; exe=""; u=""; pid=${pid//[^0-9]/}; rp=${rp//[^0-9]/}; lport=${lport//[^0-9]/}
            IFS=$'\t' read -r st exe u < <(edr_proc "$pid")
            ow=$(edr_own "${pid:-0}" "$comm" "$u" "$rip")
            edr_emit "{\"k\":\"n\",\"t\":$now,\"dir\":\"out\",\"pid\":${pid:-0}${st:+,\"st\":$st},\"comm\":\"$(edr_j "$comm")\",\"exe\":\"$(edr_j "$exe")\",\"u\":\"$(edr_j "$u")\",\"rip\":\"$(edr_j "$rip")\",\"rp\":${rp:-0},\"lp\":${lport:-0}${ow:+,\"own\":\"$ow\"}}"
        done <<<"$out"
    fi
    # Luisterpoorten: programma + poort
    out=$(ss -ltnpH 2>/dev/null | awk -v sf="$edr_dir/listen.state" -v first="$1" '
        BEGIN { while ((getline l < sf) > 0) seen[l] = 1; close(sf) }
        { n = split($4, a, ":"); port = a[n]; comm = ""; pid = 0
          if (match($0, /users:\(\("[^"]*",pid=[0-9]+/)) { u = substr($0, RSTART + 9, RLENGTH - 9); comm = u; sub(/".*/, "", comm); pid = u; sub(/.*pid=/, "", pid) }
          k = comm "|" port; now[k] = 1
          if (!(k in seen) && first != 1) print pid "\t" comm "\t" port }
        END { for (k in now) print k > sf }')
    if [ -n "$out" ]; then
        while IFS=$'\t' read -r pid comm lport; do
            st=""; exe=""; u=""; pid=${pid//[^0-9]/}; lport=${lport//[^0-9]/}
            IFS=$'\t' read -r st exe u < <(edr_proc "$pid")
            ow=$(edr_own "${pid:-0}" "$comm" "$u")
            edr_emit "{\"k\":\"n\",\"t\":$now,\"dir\":\"listen\",\"pid\":${pid:-0}${st:+,\"st\":$st},\"comm\":\"$(edr_j "$comm")\",\"exe\":\"$(edr_j "$exe")\",\"u\":\"$(edr_j "$u")\",\"lp\":${lport:-0}${ow:+,\"own\":\"$ow\"}}"
        done <<<"$out"
    fi
}
edr_net_loop() {
    command -v ss >/dev/null 2>&1 || return 0
    local first=1
    while true; do
        edr_net_once "$first"; first=0
        sleep 5
    done
}

# Bestanden: nieuwe programma's (ELF) en scripts met de uitvoerbit in /tmp, /var/tmp en /dev/shm; nieuwe scripts met
# webshell-code in de webmappen (alleen mappen met hooguit 5000 submappen: inotify heeft een grens)
edr_file() {
    local p=$1 sz h elf=0 x=0 susp="" sha yr=""
    [ -f "$p" ] && [ ! -L "$p" ] || return 0
    sz=$(stat -c %s "$p" 2>/dev/null) || return 0
    [ "$sz" -gt 0 ] && [ "$sz" -le 20971520 ] || return 0
    case "$p" in
        /tmp/*|/var/tmp/*|/dev/shm/*)
            case "$p" in *.so|*.so.*|*.o) return 0 ;; esac
            h=$(od -An -tx1 -N18 "$p" 2>/dev/null | tr -d ' \n')
            if [ "${h:0:8}" = 7f454c46 ]; then case "${h:32:2}" in 02|03) elf=1 ;; esac; fi
            [ -x "$p" ] && x=1
            if [ "$elf" != 1 ]; then
                [ "$x" = 1 ] || return 0
                case "$p" in *.sh|*.py|*.pl|*.php|*.elf|*.bin) ;; *) return 0 ;; esac
            fi ;;
        *)
            case "$p" in *.php|*.phtml|*.php[3-8]|*.phar|*.jsp|*.jspx|*.asp|*.aspx|*.cgi) ;; *) return 0 ;; esac
            # Met YARA (agent 1.64.0) elk nieuw script in een webmap; anders alleen als het op webshell-code lijkt
            yr=$(edr_yara "$p")
            [ -n "$yr" ] || grep -q -E -i '(eval|assert|system|passthru|shell_exec|proc_open|popen)[[:space:]]*\(.*\$_(POST|GET|REQUEST|COOKIE)|\$_(POST|GET|REQUEST|COOKIE).*(eval|assert|system|passthru|shell_exec)[[:space:]]*\(|eval[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|str_rot13|gzuncompress)' "$p" 2>/dev/null || return 0
            susp=$(grep -o -E -i '(eval|assert|system|passthru|shell_exec|proc_open|popen|base64_decode|gzinflate|str_rot13)' "$p" 2>/dev/null | tr 'A-Z' 'a-z' | sort -u | head -n 4 | tr '\n' ',')
            susp=${susp%,} ;;
    esac
    sha=$(sha256sum "$p" 2>/dev/null | cut -c1-64)
    [ -n "$yr" ] || yr=$(edr_yara "$p")
    edr_emit "{\"k\":\"f\",\"t\":$(date +%s),\"path\":\"$(edr_j "$p")\",\"size\":$sz,\"elf\":$elf,\"x\":$x,\"sha\":\"$sha\"${susp:+,\"susp\":\"$(edr_j "$susp")\"}${yr:+,\"yara\":\"$(edr_j "$yr")\"}}"
}
# YARA (agent 1.64.0): de regels komen van RzSense (versleuteld, rzmon.yar); namen van de regels die raak zijn, met komma's
edr_yara() {
    [ -s "$edr_dir/rzmon.yar" ] && command -v yara >/dev/null 2>&1 || return 0
    timeout 10 yara -w "$edr_dir/rzmon.yar" "$1" 2>/dev/null | awk '$1 ~ /^[A-Za-z0-9_]+$/ { print $1 }' | head -n 8 | tr '\n' ',' | sed 's/,$//'
}
edr_file_loop() {
    command -v inotifywait >/dev/null 2>&1 || return 0
    local roots=() d last="" lt=0
    for d in /tmp /var/tmp /dev/shm /run/shm /var/www /srv/www /srv/http /usr/share/nginx/html; do
        [ -d "$d" ] && [ ! -L "$d" ] || continue
        [ "$(find "$d" -xdev -type d 2>/dev/null | head -n 5001 | wc -l)" -le 5000 ] && roots+=("$d")
    done
    [ ${#roots[@]} -gt 0 ] || return 0
    inotifywait -m -r -q -e close_write,moved_to --format '%w%f' "${roots[@]}" 2>/dev/null | while IFS= read -r p; do
        # Hetzelfde bestand niet vaker dan eens per 10 s bekijken
        [ "$p" = "$last" ] && [ $(( $(date +%s) - lt )) -lt 10 ] && continue
        last=$p; lt=$(date +%s)
        edr_file "$p"
    done
}

# Containers: exec, en starten (met --privileged gemarkeerd)
edr_ctr_loop() {
    local rt="" l name img act cmd priv
    if [ "${PODMAN-}" != 0 ] && command -v podman >/dev/null 2>&1 && podman ps -q >/dev/null 2>&1; then rt=podman
    elif command -v docker >/dev/null 2>&1 && docker ps -q >/dev/null 2>&1; then rt=docker; fi
    [ -n "$rt" ] || return 0
    if [ "$rt" = podman ]; then
        podman events --format json --filter event=exec --filter event=start 2>/dev/null
    else
        docker events --format '{{json .}}' --filter event=exec_start --filter event=start 2>/dev/null
    fi | while IFS= read -r l; do
        if [ "$rt" = podman ]; then
            name=$(sed -n 's/.*"Name":"\([^"]*\)".*/\1/p' <<<"$l"); img=$(sed -n 's/.*"Image":"\([^"]*\)".*/\1/p' <<<"$l")
            act=$(sed -n 's/.*"Status":"\([^"]*\)".*/\1/p' <<<"$l"); cmd=""
        else
            name=$(sed -n 's/.*"name":"\([^"]*\)".*/\1/p' <<<"$l"); img=$(sed -n 's/.*"image":"\([^"]*\)".*/\1/p' <<<"$l")
            act=$(sed -n 's/.*"Action":"\([^"]*\)".*/\1/p' <<<"$l"); cmd=${act#exec_start: }; [ "$cmd" = "$act" ] && cmd=""; act=${act%%:*}
            [ "$act" = exec_start ] && act="exec"
        fi
        [ -n "$name" ] || continue
        priv=0
        [ "$act" = start ] && [ "$("$rt" inspect --format '{{.HostConfig.Privileged}}' "$name" 2>/dev/null)" = true ] && priv=1
        edr_emit "{\"k\":\"c\",\"t\":$(date +%s),\"rt\":\"$rt\",\"act\":\"$(edr_j "$act")\",\"name\":\"$(edr_j "$name")\",\"img\":\"$(edr_j "$img")\",\"priv\":$priv,\"cmd\":\"$(edr_j "${cmd:0:300}")\"}"
    done
}

# Suricata (agent 1.64.0): alerts uit eve.json (stdin) → [tijd,bron,poort,doel,poort,protocol,sid,"melding","categorie",ernst,"actie"],…
edr_eve_awk() {
    awk '
    function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); gsub(/[\001-\037\177]/, " ", s); return s }
    function js(l, k,   p, r, q) {   # tekstwaarde van "k":"…" (met \" erin)
        p = index(l, "\"" k "\":\""); if (!p) return ""
        r = substr(l, p + length(k) + 4); q = match(r, /([^\\]|^)"/); if (!q) return ""
        r = substr(r, 1, RSTART + RLENGTH - 2); gsub(/\\"/, "\"", r); gsub(/\\\\/, "\\", r); return r
    }
    function jn(l, k,   p, r) { p = index(l, "\"" k "\":"); if (!p) return 0; r = substr(l, p + length(k) + 3); return r + 0 }
    index($0, "\"event_type\":\"alert\"") {
        if (n >= 200) next
        printf "%s[\"%s\",\"%s\",%d,\"%s\",%d,\"%s\",%d,\"%s\",\"%s\",%d,\"%s\"]", (n++ ? "," : ""), esc(substr(js($0, "timestamp"), 1, 32)), esc(js($0, "src_ip")), jn($0, "src_port"),
            esc(js($0, "dest_ip")), jn($0, "dest_port"), esc(js($0, "proto")), jn($0, "signature_id"), esc(substr(js($0, "signature"), 1, 200)),
            esc(substr(js($0, "category"), 1, 80)), jn($0, "severity"), esc(js($0, "action"))
    }'
}

# Testen zonder RzSense (tests/agent-edr.sh): rzmon-agent.sh --edr-parse audit|swag|npm|eve < bestand
if [ "${1-}" = "--edr-parse" ]; then
    case "${2-}" in
        audit) edr_audit_awk ;;
        swag) edr_swag_req; echo ;;
        npm) edr_npm_norm ;;
        eve) edr_eve_awk; echo ;;
        *) echo "Gebruik: $0 --edr-parse audit|swag|npm|eve < bestand" >&2; exit 2 ;;
    esac
    exit 0
fi

