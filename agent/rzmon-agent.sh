#!/usr/bin/env bash
# RzMon agent – rapporteert CPU, RAM, disk usage, luisterende poorten, service-status,
# vervaldatums van SSL-certificaten (bestanden en host:poort, ook op internet) en, als
# podman aanwezig is, de status en het verbruik van elke container en pod aan de monitor.
#
# Linux-server:
#   sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
#   Zet MONITOR_URL, TOKEN, SERVICES en CERTS in /etc/default/rzmon-agent (zie "Agent Manager" in de webinterface)
#   echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon-agent
#   Vereist: bash, curl, df, systemctl, ss (iproute2); voor certificaten: openssl
#
# Home Assistant OS / Supervised:
#   Draait als add-on (map ha-addon/rzmon_agent). Zodra SUPERVISOR_TOKEN bestaat schakelt de agent
#   over op HA-modus: disk via de Supervisor, en "services" zijn Home Assistant Core, de Supervisor,
#   elke add-on (addon:<slug>) en beschikbare updates. Vereist daarnaast: jq.
#
# TrueNAS SCALE: de agent staat op een pool (bv. /mnt/tank/rzmon-agent) en draait via een cronjob van
#   TrueNAS zelf; install-agent.sh regelt dat (zie HANDLEIDING.md §8.9).
#
# Verbindingstest:  sudo rzmon-agent.sh --test

# Versie: wordt bijgewerkt door tools/bump-version.sh (gelijk aan VERSION van RzMon)
AGENT_VERSION="1.63.0"

TEST=0
[ "${1-}" = "--test" ] && TEST=1
STATUS=0; [ "${1-}" = "--status" ] && STATUS=1
if [ "${1-}" = "--version" ]; then
    echo "rzmon-agent $AGENT_VERSION"
    exit 0
fi
# Voor het IDS (bestanden bewaken), op de achtergrond: alle bestanden uit de lijst opnieuw hashen.
# Uitvoer: pad <tab> grootte <tab> tijd <tab> sha256; grootte en tijd zoals in de lijst, zodat een
# uitkomst die inmiddels verouderd is (bestand sindsdien gewijzigd) herkend wordt.
if [ "${1-}" = "--fim-rehash" ] && [ -r "${2-}" ]; then
    while IFS=$'\t' read -r p s t _; do
        [ "$s" -le 104857600 ] 2>/dev/null || continue
        h=$(sha256sum -- "$p" 2>/dev/null) || continue
        printf '%s\t%s\t%s\t%s\n' "$p" "$s" "$t" "${h%% *}"
    done <"$2"
    exit 0
fi

# Met de hand gestart (zonder omgeving van systemd/cron)? Dan zelf de instellingen inlezen.
if [ -z "${MONITOR_URL-}" ] && [ -z "${SUPERVISOR_TOKEN-}" ] && [ -r /etc/default/rzmon-agent ]; then
    set -a
    # shellcheck disable=SC1091
    . /etc/default/rzmon-agent
    set +a
fi

MONITOR_URL="${MONITOR_URL:-http://rzmon.example.lan}"
TOKEN="${TOKEN:-VUL_HIER_HET_TOKEN_IN}"
# Welke services bewaakt worden, stel je in RzMon in (service-check toevoegen). SERVICES is verouderd,
# maar werkt nog: services in deze spatie-gescheiden lijst worden óók gemeld (niet gebruikt in HA-modus).
SERVICES="${SERVICES-}"
# INSECURE=1 accepteert een self-signed certificaat van RzMon
INSECURE="${INSECURE:-0}"
# Certificaten om te bewaken (spatie-gescheiden): bestanden (wildcards mogen) en/of host:poort,
# lokaal of op internet, bv. "/etc/letsencrypt/live/*/cert.pem localhost:8443 example.com:443".
# Niet ingesteld = alle Let's Encrypt-certificaten op deze server; leeg ("") = geen.
CERTS="${CERTS-/etc/letsencrypt/live/*/cert.pem}"
# Certificaten veranderen zelden: elk uur opnieuw uitlezen is genoeg (seconden)
CERT_INTERVAL="${CERT_INTERVAL:-3600}"
# Certificaten zoeken (1.59.0): in deze mappen naar .crt/.pem/.cer, één keer per dag (of meteen als RzMon erom vraagt).
# In RzMon kies je welke bewaakt worden; extra mappen stel je daar in (Instellingen → Agents). Leeg ("") = niet zoeken.
CERT_DIRS="${CERT_DIRS-/etc/ssl /etc/pki /etc/letsencrypt /etc/nginx /etc/apache2 /etc/httpd /etc/haproxy /etc/lighttpd /etc/caddy /etc/traefik /etc/postfix /etc/dovecot /etc/mosquitto /etc/cockpit /etc/pve /etc/ipa /opt /srv /usr/local/etc /var/lib/docker/volumes /config /ssl}"
# Diepere controle van services: draait het proces, luistert het op zijn poorten, antwoordt het
# (HTTP, database, SSH, mail, DNS…), herstarts en fouten in het log. SERVICE_DEEP=0 zet het uit.
SERVICE_DEEP="${SERVICE_DEEP:-1}"
# Eigen tests: een uitvoerbaar script /etc/rzmon-agent/tests/<service> (van root, niet door anderen
# te wijzigen). Exitcode 0 = OK, 1 = WARNING, 2 = CRITICAL; de eerste regel uitvoer is het bericht.
SERVICE_TEST_DIR="${SERVICE_TEST_DIR:-/etc/rzmon-agent/tests}"
# Logbestanden doorzoeken (zoekopdrachten stel je in RzMon in): alleen bestanden in deze mappen
# mogen gelezen worden, zodat via RzMon niet zomaar elk bestand uitgelezen kan worden.
LOG_DIRS="${LOG_DIRS:-/var/log}"
# Podman: wordt alleen gemeld als podman op deze server staat. PODMAN=0 zet het uit.
PODMAN="${PODMAN:-1}"
# Gebruikers met rootless containers: "auto" = zelf zoeken (gebruikers met ~/.local/share/containers),
# of een lijst namen; "" = alleen de containers van root
PODMAN_USERS="${PODMAN_USERS-auto}"
# Bijwerken vanuit RzMon (knop "Agent bijwerken" of automatisch). REMOTE_UPDATE=0: alleen met de hand
# (sudo rzmon-agent-setup --update). De nieuwe versie komt altijd van je eigen RzMon en wordt eerst
# gecontroleerd (SHA-256, geldig script, juiste versie) voordat hij geïnstalleerd wordt.
REMOTE_UPDATE="${REMOTE_UPDATE:-1}"
# Installatiescript voor bijwerken vanuit RzMon (op TrueNAS staat het op de pool, naast de agent)
AGENT_SETUP="${AGENT_SETUP:-/usr/local/sbin/rzmon-agent-setup}"
# Commando-checks (＋ Check → Commando in RzMon): RzMon bepaalt of ze mogen en als welke gebruiker
# (Instellingen → Agents). Hier op de server kun je dat inperken: COMMANDS=0 = nooit uitvoeren,
# COMMANDS=nobody = nooit als root. Leeg = wat RzMon zegt.
COMMANDS="${COMMANDS-}"
# Zelf herstellen (agent 1.62.0, per check in RzMon aan te zetten): een service of container die niet draait, herstart
# de agent zelf; een eigen herstelcommando staat alleen hier, in HEAL_DIR (uitvoerbaar, van root, niet door anderen te
# wijzigen). HEAL=0 = op deze server nooit zelf herstellen.
HEAL="${HEAL:-1}"
HEAL_DIR="${HEAL_DIR:-/etc/rzmon-agent/heal.d}"
# Waakhond (agent 1.62.0, per agent in RzMon aan te zetten): Pushover als RzMon onbereikbaar is of de checks stilstaan.
# WATCHDOG=0 = op deze server nooit.
WATCHDOG="${WATCHDOG:-1}"
PUSHOVER_API="${PUSHOVER_API:-https://api.pushover.net/1/messages.json}"
# Logins (SSH en console), sudo en su melden op de pagina Meldingen, als dat in RzMon aan staat
# (Instellingen → Alarmgedrag). AUTH_EVENTS=0 zet het op deze server altijd uit.
AUTH_EVENTS="${AUTH_EVENTS-}"
# IDS (pagina 🛡️ IDS in RzMon): IDS_BLOCK=0 = op deze server nooit blokkeren, ook als RzMon op
# "blokkeren" staat (detecteren gaat door).
IDS_BLOCK="${IDS_BLOCK-}"
# Zonder journal (of met AUTH_SOURCE=file): deze logbestanden, de eerste die bestaat
AUTH_LOGS="${AUTH_LOGS:-/var/log/secure /var/log/auth.log}"
SUPERVISOR_URL="${SUPERVISOR_URL:-http://supervisor}"
# Realtime-watcher (dienst rzmon-watch, aan/uit per server in RzMon): REALTIME=0 zet hem op deze server altijd uit.
REALTIME="${REALTIME-}"

HA_MODE=0
[ -n "${SUPERVISOR_TOKEN:-}" ] && HA_MODE=1

# ---------------------------------------------------------------- Endpoint-bewaking (agent 1.63.0)
# Aan per server in RzMon (regel "0 <tab> edr <tab> 1"); EDR=0 zet het op deze server altijd uit. De realtime-watcher
# start dan sensoren die elke gebeurtenis meteen in een wachtrij zetten (edr.spool) en een run starten. RzMon toetst
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
    awk -v dropf="$edr_dir/edr.drop" '
    BEGIN {
        hx = "0123456789ABCDEF"; for (i = 0; i < 16; i++) hv[substr(hx, i + 1, 1)] = i
        while ((getline l < "/etc/passwd") > 0) { n = split(l, pw, ":"); if (n >= 3) un[pw[3]] = pw[1] }
        close("/etc/passwd")
        cur = ""; minute = 0; sent = 0; drop = 0; reset = 0
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
            if (pc ~ /^rzmon-agent/ || comm ~ /^rzmon-agent/) { cur = ""; return }
            cmd = ""
            for (i = 0; i < argc && i < 16; i++) cmd = cmd (i ? " " : "") av[i]
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
            print "{\"k\":\"a\",\"t\":" ts ",\"key\":\"" esc(ekey) "\",\"pid\":" num(pid) ",\"uid\":" num(uid) ",\"auid\":" num(auid) ",\"u\":\"" esc(u) \
                "\",\"exe\":\"" esc(exe) "\",\"comm\":\"" esc(comm) "\",\"name\":\"" esc(nm) "\"}"
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

# SWAG/nginx-log (stdin) → verdachte verzoeken als JSON (zonder haakjes), hooguit 100:
# [ip, tijd, methode, pad+query, status, bytes, user-agent, verwijzer]; RzMon beoordeelt ze precies
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
        printf "%s[\"%s\",\"%s\",\"%s\",\"%s\",%d,%d,\"%s\",\"%s\"]", (n > 1 ? "," : ""), ip, esc(tm), esc(substr(m, 1, 20)), esc(substr(uri, 1, 512)),
            st, by, esc(substr(ua, 1, 200)), esc(substr(ref, 1, 200))
    }'
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
    local lp out pid comm rip rp lport st exe u now
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
            edr_emit "{\"k\":\"n\",\"t\":$now,\"dir\":\"out\",\"pid\":${pid:-0}${st:+,\"st\":$st},\"comm\":\"$(edr_j "$comm")\",\"exe\":\"$(edr_j "$exe")\",\"u\":\"$(edr_j "$u")\",\"rip\":\"$(edr_j "$rip")\",\"rp\":${rp:-0},\"lp\":${lport:-0}}"
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
            edr_emit "{\"k\":\"n\",\"t\":$now,\"dir\":\"listen\",\"pid\":${pid:-0}${st:+,\"st\":$st},\"comm\":\"$(edr_j "$comm")\",\"exe\":\"$(edr_j "$exe")\",\"u\":\"$(edr_j "$u")\",\"lp\":${lport:-0}}"
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
    local p=$1 sz h elf=0 x=0 susp="" sha
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
            grep -q -E -i '(eval|assert|system|passthru|shell_exec|proc_open|popen)[[:space:]]*\(.*\$_(POST|GET|REQUEST|COOKIE)|\$_(POST|GET|REQUEST|COOKIE).*(eval|assert|system|passthru|shell_exec)[[:space:]]*\(|eval[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|str_rot13|gzuncompress)' "$p" 2>/dev/null || return 0
            susp=$(grep -o -E -i '(eval|assert|system|passthru|shell_exec|proc_open|popen|base64_decode|gzinflate|str_rot13)' "$p" 2>/dev/null | tr 'A-Z' 'a-z' | sort -u | head -n 4 | tr '\n' ',')
            susp=${susp%,} ;;
    esac
    sha=$(sha256sum "$p" 2>/dev/null | cut -c1-64)
    edr_emit "{\"k\":\"f\",\"t\":$(date +%s),\"path\":\"$(edr_j "$p")\",\"size\":$sz,\"elf\":$elf,\"x\":$x,\"sha\":\"$sha\"${susp:+,\"susp\":\"$(edr_j "$susp")\"}}"
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

# Testen zonder RzMon (tests/agent-edr.sh): rzmon-agent.sh --edr-parse audit|swag < bestand
if [ "${1-}" = "--edr-parse" ]; then
    case "${2-}" in
        audit) edr_audit_awk ;;
        swag) edr_swag_req; echo ;;
        *) echo "Gebruik: $0 --edr-parse audit|swag < bestand" >&2; exit 2 ;;
    esac
    exit 0
fi

# ---------------------------------------------------------------- Realtime-watcher (--watch)
# Draait als aparte dienst (rzmon-watch). Doet zelf geen analyse: hij let alleen op en start bij een
# gebeurtenis meteen een gewone agent-run, die alles verwerkt (logins, brute force, IDS, services, bestanden)
# en die RzMon direct beoordeelt. Alleen als RzMon realtime voor deze server aanzet (regel "0 <tab> realtime <tab> 1")
# en REALTIME niet 0 is; anders wacht hij en kijkt elke minuut opnieuw.
#  - systemlog (journalctl -f): SSH-logins en mislukte pogingen, sudo, su, console, en meldingen van systemd
#    over de services die RzMon bewaakt (gestopt, gecrasht, herstart);
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
        # Endpoint-bewaking (agent 1.63.0): als RzMon het voor deze server aanzet, als root, niet op Home Assistant OS
        [ "$EDR" != 0 ] && [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && grep -q $'^0\tedr\t1' "$w_cache" 2>/dev/null && edr_on=1
        if [ "$rt_on" = 0 ] && [ "$edr_on" = 0 ]; then
            w_status off - -
            sleep 60
            continue
        fi
        w_sig=$(grep -E $'^(0\t(realtime|fim|edr)|[0-9]+\tsvc)\t' "$w_cache" 2>/dev/null | cksum)
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
                # Instellingen in RzMon veranderd, realtime uitgezet, of een volger gestopt: opnieuw opbouwen
                [ "$(grep -E $'^(0\t(realtime|fim|edr)|[0-9]+\tsvc)\t' "$w_cache" 2>/dev/null | cksum)" = "$w_sig" ] || break
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

json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
# Willekeurige tekst (stdin, bv. uitvoer van een commando) als JSON-tekst: geldige UTF-8, tabs en
# regeleindes als \t en \n, andere stuurtekens weg
json_text() {
    { if command -v iconv >/dev/null 2>&1; then iconv -f UTF-8 -t UTF-8 -c 2>/dev/null; else LC_ALL=C tr -cd '\11\12\15\40-\176'; fi; } |
        sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/\\t/g' | LC_ALL=C tr -d '\000-\010\013-\037' | awk 'NR > 1 { printf "\\n" } { printf "%s", $0 }'
}

# ---------------------------------------------------------------- Heartbeat (rzmon-hb, agent 1.52.0)
# Een script of cronjob meldt zich bij RzMon: rzmon-hb NAAM [ok|start|fail] [bericht] [--value N].
# Er wordt alleen een regel in de wachtrij gezet (HB_DIR, iedereen mag erin schrijven maar niet lezen);
# de agent stuurt hem versleuteld mee met het volgende rapport. Zonder verbinding blijven de regels bewaard.
hb_dir="${HB_DIR:-${AGENT_DIR:+$AGENT_DIR/hb}}"
hb_dir="${hb_dir:-/var/spool/rzmon-agent}"
if [ "$(basename -- "$0")" = rzmon-hb ] || [ "${1-}" = --hb ]; then
    [ "${1-}" = --hb ] && shift
    hb_name=${1-}; hb_kind=${2:-ok}; hb_msg=""; hb_val=""
    [ $# -ge 2 ] && shift 2 || shift $#
    while [ $# -gt 0 ]; do
        case $1 in
            --value) hb_val=${2-}; shift 2 2>/dev/null || shift ;;
            *) hb_msg="${hb_msg:+$hb_msg }$1"; shift ;;
        esac
    done
    case $hb_kind in success|done) hb_kind=ok ;; failure|failed|error) hb_kind=fail ;; begin) hb_kind=start ;; esac
    if ! [[ "$hb_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || [[ " start ok fail " != *" $hb_kind "* ]]; then
        echo "Gebruik: rzmon-hb NAAM [ok|start|fail] [bericht] [--value GETAL]" >&2
        echo "  NAAM = de naam van de heartbeat-check in RzMon (letters, cijfers, punt, - of _), bv. backup-db" >&2
        exit 2
    fi
    if [ -n "$hb_val" ] && ! [[ "$hb_val" =~ ^-?[0-9]+([.][0-9]+)?$ ]]; then
        echo "rzmon-hb: --value moet een getal zijn (bv. 42 of 3.5); genegeerd" >&2; hb_val=""
    fi
    [ -d "$hb_dir" ] || { mkdir -p "$hb_dir" && chmod 1733 "$hb_dir"; } 2>/dev/null
    hb_f=$(mktemp "$hb_dir/hb.XXXXXXXX" 2>/dev/null) || { echo "rzmon-hb: kan niet schrijven in $hb_dir (is de agent geïnstalleerd?)" >&2; exit 1; }
    printf '{"n":"%s","s":"%s","t":%s%s,"m":"%s"}\n' "$hb_name" "$hb_kind" "$(date +%s)" "${hb_val:+,\"v\":$hb_val}" \
        "$(printf '%s' "$hb_msg" | head -c 300 | json_text)" >"$hb_f"
    chmod 644 "$hb_f" 2>/dev/null
    exit 0
fi

t_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
[ "$HA_MODE" = 1 ] && t_dir="${CACHE_DIRECTORY:-/data/rzmon-cache}"   # add-on: /data blijft bewaard
mkdir -p "$t_dir" 2>/dev/null && [ -w "$t_dir" ] || t_dir="${TMPDIR:-/tmp}"
t_cache="$t_dir/tests.cache"

# ---------------------------------------------------------------- Versleuteling (RzMon 1.57.0+)
# Met een sleutel gaan rapporten versleuteld naar RzMon (AES-256-CBC + HMAC-SHA256, met openssl) en komt het
# antwoord versleuteld terug; zie src/AgentCrypto.php in RzMon. De sleutel: ENC_KEY in /etc/default/rzmon-agent,
# of de sleutel die RzMon zelf meestuurt (enc.key, alleen leesbaar voor root; die gaat voor, zo werkt een nieuwe
# sleutel vanzelf). Staat versleutelen in RzMon uit voor deze server, dan zet RzMon enc.off neer.
#
# Beveiligde verbinding (agent 1.50.0+, RzMon 1.64.0+): een eigen sleutel voor deze server (agent.key). Die staat
# in een vaste map, niet in de cache: in de HA-add-on /data/rzmon-keys, op TrueNAS in de map van de agent, anders
# /var/lib/rzmon-agent (alleen root). Met een eigen sleutel stuurt de agent geen token meer mee, alleen het kenmerk
# van zijn sleutel, en is elk verzoek versleuteld of ondertekend. De sleutel komt bij het koppelen (koppelcode:
# PAIR_CODE) of, als de agent al met de algemene sleutel werkte, in een versleuteld antwoord van RzMon.
enc_file="$t_dir/enc.key"
if [ "$HA_MODE" = 1 ]; then k_dir=/data/rzmon-keys
elif [ -n "${AGENT_DIR-}" ]; then k_dir="$AGENT_DIR/keys"
else k_dir=/var/lib/rzmon-agent; fi
{ mkdir -p "$k_dir" && chmod 700 "$k_dir"; } 2>/dev/null
[ -w "$k_dir" ] || k_dir=$t_dir
own_file="$k_dir/agent.key"
own_key=""
enc_key=""
if command -v openssl >/dev/null 2>&1; then
    [ -r "$own_file" ] && own_key=$(tr -dc '0-9a-f' <"$own_file" | head -c 64)
    [ "${#own_key}" = 64 ] || own_key=""
    if [ ! -e "$t_dir/enc.off" ]; then
        enc_key=$own_key
        [ -z "$enc_key" ] && [ -r "$enc_file" ] && enc_key=$(tr -dc '0-9a-f' <"$enc_file" | head -c 64)
        [ "${#enc_key}" = 64 ] || enc_key=""
        [ -z "$enc_key" ] && [[ "${ENC_KEY-}" =~ ^[0-9a-f]{64}$ ]] && enc_key=$ENC_KEY
    fi
fi
enc_sub() {  # enc_sub <sleutel> <label>: afgeleide sleutel (HMAC-SHA256, hex)
    printf '%s' "$2" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$1" 2>/dev/null | sed 's/^.*= *//'
}
seal() {  # seal <invoer> <uitvoer>: versleutelen met enc_key; de nonce staat daarna in seal_n
    local ke km iv t d m
    ke=$(enc_sub "$enc_key" enc) && km=$(enc_sub "$enc_key" mac) && iv=$(openssl rand -hex 16) && seal_n=$(openssl rand -hex 16) || return 1
    t=$(date +%s)
    d=$(openssl enc -aes-256-cbc -K "$ke" -iv "$iv" -in "$1" 2>/dev/null | base64 -w0) && [ -n "$d" ] || return 1
    m=$(printf 'rzenc1|%s|%s|%s|%s' "$t" "$seal_n" "$iv" "$d" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$km" 2>/dev/null | sed 's/^.*= *//')
    [ "${#m}" = 64 ] || return 1
    printf '{"rzenc":1,"t":%s,"n":"%s","iv":"%s","d":"%s","m":"%s"}' "$t" "$seal_n" "$iv" "$d" "$m" >"$2"
}
unseal() {  # unseal <antwoord> <nonce van het verzoek>: handtekening controleren, dan uitpakken; 1 = niet te vertrouwen
    local t rn iv d m ke km
    t=$(sed -n 's/^{"rzenc":1,"t":\([0-9]*\),.*/\1/p' <<<"$1")
    rn=$(sed -n 's/.*"rn":"\([0-9a-f]*\)".*/\1/p' <<<"$1")
    iv=$(sed -n 's/.*"iv":"\([0-9a-f]*\)".*/\1/p' <<<"$1")
    d=$(sed -n 's/.*"d":"\([A-Za-z0-9+/=]*\)".*/\1/p' <<<"$1")
    m=$(sed -n 's/.*"m":"\([0-9a-f]*\)".*/\1/p' <<<"$1")
    [ -n "$t" ] && [ -n "$d" ] && [ "${#m}" = 64 ] && [ -n "$2" ] && [ "$rn" = "$2" ] || return 1
    km=$(enc_sub "$enc_key" mac) && ke=$(enc_sub "$enc_key" enc) || return 1
    [ "$(printf 'rzenc1r|%s|%s|%s|%s' "$t" "$rn" "$iv" "$d" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$km" 2>/dev/null | sed 's/^.*= *//')" = "$m" ] || return 1
    printf '%s' "$d" | base64 -d 2>/dev/null | openssl enc -d -aes-256-cbc -K "$ke" -iv "$iv" 2>/dev/null
}
# Wie we zijn: met de eigen sleutel alleen het kenmerk (X-Agent-Key), anders het token
set_auth() {
    kid=""
    if [ -n "$own_key" ] && [ "$enc_key" = "$own_key" ]; then
        kid=$(enc_sub "$own_key" id | cut -c1-16)
        auth=(-H "X-Agent-Key: $kid")
    else
        auth=(-H "X-Agent-Token: $TOKEN")
    fi
}
set_auth
# GET-verzoek (actie $1): met de eigen sleutel ondertekend (X-Agent-Auth), anders het token en eventueel het kenmerk
# van de algemene sleutel. Het antwoord komt dan versleuteld terug, gebonden aan get_n.
get_auth() {
    get_n=""
    if [ -n "$kid" ]; then
        local t s
        t=$(date +%s); get_n=$(openssl rand -hex 16)
        s=$(printf 'rzget1|%s|%s|%s' "$1" "$t" "$get_n" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$(enc_sub "$own_key" mac)" 2>/dev/null | sed 's/^.*= *//')
        ga=(-H "X-Agent-Auth: $kid:$t:$get_n:$s")
    else
        ga=(-H "X-Agent-Token: $TOKEN")
        if [ -n "$enc_key" ]; then get_n=$(openssl rand -hex 16) && ga+=(-H "X-Agent-Enc: $(enc_sub "$enc_key" id | cut -c1-16):$get_n"); fi
    fi
}

# Adres van RzMon (agent 1.51.0, RzMon 1.72.0). RzMon kan de agent een ander adres geven (bv. https via de reverse
# proxy); de agent test het eerst en bewaart het dan in $k_dir/url (regel 1: het adres, 2: het adres uit de instellingen
# waar het voor in de plaats komt, 3: het reserve-adres). Pas je MONITOR_URL zelf aan, dan geldt weer dat. Het
# reserve-adres (MONITOR_URL_FALLBACK, of uit $k_dir/url) wordt gebruikt als het gewone adres niet bereikbaar is
# (geen verbinding, certificaat, of de proxy geeft 502-504); RzMon toont dat met een ⚠️.
conf_url=${MONITOR_URL%/}
MONITOR_URL_FALLBACK="${MONITOR_URL_FALLBACK-}"
url_file="$k_dir/url"
url_set_by_rzmon=0
if [ -r "$url_file" ]; then
    u_set=""; u_from=""; u_fb=""
    { IFS= read -r u_set; IFS= read -r u_from; IFS= read -r u_fb; } <"$url_file"
    if [ -n "$u_set" ] && [ "$u_from" = "$conf_url" ]; then
        MONITOR_URL=$u_set
        MONITOR_URL_FALLBACK=$u_fb
        url_set_by_rzmon=1
    else
        rm -f "$url_file"   # MONITOR_URL is met de hand gewijzigd: dat gaat voor
    fi
fi
MONITOR_URL=${MONITOR_URL%/}
MONITOR_URL_FALLBACK=${MONITOR_URL_FALLBACK%/}
[ "$MONITOR_URL_FALLBACK" = "$MONITOR_URL" ] && MONITOR_URL_FALLBACK=""
url_result=$(head -c 500 "$k_dir/url.result" 2>/dev/null | tr -d '\n')

# Controle per adres (agent 1.53.0, RzMon 1.78.0): in $k_dir/url.pins staat per adres hoe het certificaat gecontroleerd
# wordt: "-" = gewoon (geldig certificaat, of http), "sha256//…" = alleen het eigen certificaat van RzMon (vastzetten;
# met een klaargezet nieuw certificaat twee vingerafdrukken, "sha256//A;sha256//B"). RzMon geeft dit door in elk
# versleuteld antwoord. Geen regel voor een adres: zoals vroeger (de vingerafdruk uit $k_dir/pin, of INSECURE).
spec_re='^(-|sha256//[A-Za-z0-9+/=]{40,64}(;sha256//[A-Za-z0-9+/=]{40,64})?)$'
url_spec() { [ -n "$1" ] && [ -r "$k_dir/url.pins" ] && awk -v u="$1" '$1 == u { print $2; exit }' "$k_dir/url.pins"; }
url_pins_put() {  # url_pins_put <adres> <controle> […]: deze adressen bijwerken, de andere bewaren (hooguit 6)
    local f="$k_dir/url.pins" keep="" add="" a
    local -a urls=()
    while [ $# -ge 2 ]; do
        if [[ "$1" =~ ^https?://[^[:space:]\"]+$ ]] && [[ "$2" =~ $spec_re ]]; then urls+=("$1"); add+="$1 $2"$'\n'; fi
        shift 2
    done
    [ ${#urls[@]} -gt 0 ] || return 0
    if [ -r "$f" ]; then
        while read -r a _; do
            [[ " ${urls[*]} " == *" $a "* ]] || keep+="$(grep -m1 -F "$a " "$f")"$'\n'
        done <"$f"
    fi
    (umask 077; printf '%s%s' "$keep" "$add" | grep -v '^$' | tail -n 6 >"$f.tmp" && mv -f "$f.tmp" "$f")
}
# curl-opties voor een adres volgens zijn controle (leeg = geen aanwijzing)
spec_tls() {  # spec_tls <adres> <controle>: zet s_tls
    s_tls=()
    [[ "$1" == https://* ]] || return 0
    if [[ "$2" == sha256//* ]] && [ "$curl_pins" = 1 ]; then s_tls=(-k --pinnedpubkey "$2")
    elif [ "$INSECURE" = 1 ]; then s_tls=(-k); fi
}

# Overstappen naar een nieuw adres (opdracht uit een versleuteld antwoord van RzMon): eerst testen met een ondertekend
# verzoek; alleen als het antwoord met onze eigen sleutel klopt, gaat de agent dat adres gebruiken (vanaf de volgende
# keer). De uitkomst gaat met het volgende rapport naar RzMon.
url_switch() {  # url_switch <nieuw adres> <reserve-adres> [controle nieuw] [controle reserve] (agent 1.53.0)
    local new=${1%/} fb=${2%/} n_spec=${3-} f_spec=${4-} t_tls=() out code rip body why
    [ -e "$k_dir/url.result" ] && return 0   # vorige uitkomst nog niet doorgegeven
    [[ "$new" =~ ^https?://[^[:space:]\"]+$ ]] || return 0
    [[ -z "$fb" || "$fb" =~ ^https?://[^[:space:]\"]+$ ]] || fb=""
    [[ "$n_spec" =~ $spec_re ]] || n_spec=""
    [[ "$f_spec" =~ $spec_re ]] || f_spec=""
    if [ -z "$kid" ]; then
        printf 'error: deze agent heeft nog geen eigen sleutel (eerst koppelen met een koppelcode)\n' >"$k_dir/url.result"
        return 0
    fi
    if [[ "$new" == https://* && "$n_spec" == sha256//* && "$curl_pins" != 1 ]]; then
        printf 'error: curl %s op deze server kan het certificaat van RzMon niet vastzetten (7.44 of nieuwer nodig)\n' "$c_ver" >"$k_dir/url.result"
        return 0
    fi
    spec_tls "$new" "$n_spec"; t_tls=("${s_tls[@]}")
    get_auth agent_check
    # Ook het IP waarmee curl verbond: RzMon ziet daaraan of de naam hier naar de proxy wijst of via internet gaat
    out=$(curl -sS -m 15 "${t_tls[@]}" "${ga[@]}" -w '\n%{http_code} %{remote_ip}' "$new/api.php?action=agent_check" 2>&1)
    code=${out##*$'\n'}; out=${out%$'\n'*}
    rip=${code#* }; code=${code%% *}
    [[ "$rip" =~ ^[0-9a-fA-F.:]+$ ]] || rip=
    if [ "$code" = 200 ] && [ -n "$get_n" ] && body=$(unseal "$out" "$get_n") && [[ "$body" == *'"ok":true'* ]]; then
        if (umask 077; printf '%s\n%s\n%s\n' "$new" "$conf_url" "$fb" >"$url_file.tmp" && mv -f "$url_file.tmp" "$url_file"); then
            url_pins_put "$new" "$n_spec" "$fb" "$f_spec"
            printf 'ok %s%s\n' "$new" "${rip:+ [ip $rip]}" >"$k_dir/url.result"
            echo "Adres van RzMon gewijzigd naar $new${fb:+ (reserve: $fb)}" >&2
        else
            printf 'error: kan %s niet schrijven\n' "$url_file" >"$k_dir/url.result"
        fi
    else
        why=$(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' <<<"$out" | head -c 150)
        [ -z "$why" ] && why=$(head -n 1 <<<"$out" | head -c 150)
        [ "$code" = 200 ] && why="het antwoord klopt niet met de sleutel van deze agent"
        printf 'error: %s werkt niet vanaf deze server (HTTP %s%s)%s\n' "$new" "${code:-000}" "${why:+: $why}" "${rip:+ [ip $rip]}" >"$k_dir/url.result"
        echo "Nieuw adres $new werkt niet; het huidige adres blijft" >&2
    fi
}

# Certificaat van RzMon vastzetten: bij een eigen (self-signed) certificaat accepteert de agent alleen dat ene
# certificaat (curl --pinnedpubkey). De vingerafdruk komt uit het installatiecommando (PIN) en daarna uit de
# versleutelde antwoorden van RzMon ("" = niet vastzetten). Zonder vingerafdruk: INSECURE=1 als vroeger.
curl_pins=0
c_ver=$(curl -V 2>/dev/null | awk 'NR == 1 { print $2 }'); IFS=. read -r c_v1 c_v2 _ <<<"$c_ver"
{ [ "${c_v1:-0}" -gt 7 ] || { [ "${c_v1:-0}" = 7 ] && [ "${c_v2:-0}" -ge 44 ]; }; } 2>/dev/null && curl_pins=1   # sha256-pins: curl 7.44+
set_tls() {
    pin=""
    u_spec=$(url_spec "$MONITOR_URL")
    if [ -n "$u_spec" ]; then   # agent 1.53.0: RzMon gaf voor dit adres door hoe het gecontroleerd wordt
        spec_tls "$MONITOR_URL" "$u_spec"; tls=("${s_tls[@]}")
        [[ "$u_spec" == sha256//* ]] && pin=${u_spec#sha256//} && pin=${pin%%;*}
        return 0
    fi
    [ -r "$k_dir/pin" ] && pin=$(tr -dc 'A-Za-z0-9+/=' <"$k_dir/pin" | head -c 64)
    [ -z "$pin" ] && [ ! -e "$k_dir/pin.off" ] && [[ "${PIN-}" =~ ^[A-Za-z0-9+/=]{40,64}$ ]] && pin=$PIN
    tls=()
    if [[ "$MONITOR_URL" == https://* ]] && [ -n "$pin" ] && [ "$curl_pins" = 1 ]; then tls=(-k --pinnedpubkey "sha256//$pin")
    elif [ "$INSECURE" = 1 ]; then tls=(-k); fi
}
set_tls
store_pin() {  # store_pin <versleuteld antwoord>: vingerafdruk van RzMon bewaren, of ("") niet meer vastzetten
    [[ "$1" =~ \"pin\":\"([A-Za-z0-9+/=\\]{0,90})\" ]] || return 0
    local p=${BASH_REMATCH[1]//\\/}
    if [ -n "$p" ]; then
        [ "$(cat "$k_dir/pin" 2>/dev/null)" = "$p" ] || (umask 077; printf '%s\n' "$p" >"$k_dir/pin")
        rm -f "$k_dir/pin.off"
    else
        rm -f "$k_dir/pin"; : >"$k_dir/pin.off"
    fi
}
store_key() {  # store_key <versleuteld antwoord>: eigen sleutel van RzMon bewaren (eerste keer, of vernieuwd)
    [[ "$1" =~ \"agent_key\":\"([0-9a-f]{64})\" ]] || return 0
    [ "${BASH_REMATCH[1]}" = "$own_key" ] && return 0
    (umask 077; printf '%s\n' "${BASH_REMATCH[1]}" >"$own_file.tmp" && mv -f "$own_file.tmp" "$own_file") || return 1
    own_key=${BASH_REMATCH[1]}
}

# Koppelen met een koppelcode (eenmalig; RzMon → Agent Manager → 🔗 Koppelcode). Het verzoek is versleuteld met een
# sleutel uit de code; RzMon antwoordt (ook versleuteld) met de eigen sleutel van deze server. Een code die al
# gebruikt is (pair.used) wordt overgeslagen.
pair_code=$(printf '%s' "${PAIR_CODE-}" | tr -dc 'A-Za-z0-9' | tr '[:lower:]' '[:upper:]')
pair_agent() {
    local pk pf raw code body p_key
    pk=$(printf 'rzpair1|%s' "$pair_code" | sha256sum | cut -d' ' -f1)
    pf=$(mktemp "${TMPDIR:-/tmp}/rzmon-pair.XXXXXX") || return 1
    printf '{"agent_version":"%s","hostname":"%s"}' "$AGENT_VERSION" "$(json_escape "$(hostname)")" >"$pf"
    enc_key=$pk
    if ! seal "$pf" "$pf.enc"; then rm -f "$pf" "$pf.enc"; echo "Koppelen mislukt: versleutelen met openssl lukt niet" >&2; return 1; fi
    raw=$(curl -sS "${tls[@]}" -m 20 -X POST -H "Content-Type: application/json" -H "X-Agent-Pair: $(enc_sub "$pk" id | cut -c1-16)" \
        --data-binary "@$pf.enc" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent_pair" 2>&1)
    rm -f "$pf" "$pf.enc"
    code=${raw##*$'\n'}; raw=${raw%$'\n'*}
    if [ "$code" != 200 ] || ! body=$(unseal "$raw" "$seal_n"); then
        echo "Koppelen mislukt (HTTP $code): $(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' <<<"$raw" | head -c 200)" >&2
        [ "$code" = 200 ] && echo "  Het antwoord van RzMon klopt niet (handtekening); niet gekoppeld." >&2
        return 1
    fi
    p_key=$(sed -n 's/.*"agent_key":"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$body")
    [ -n "$p_key" ] || { echo "Koppelen mislukt: geen sleutel in het antwoord" >&2; return 1; }
    own_key=""; store_key "$body" || { echo "Koppelen mislukt: kan $own_file niet schrijven" >&2; return 1; }
    printf '%s' "$pair_code" | sha256sum | cut -c1-16 >"$k_dir/pair.used"
    rm -f "$t_dir/enc.off" "$t_dir/enc.fail"
    store_pin "$body"
    echo "🔑 Gekoppeld met RzMon (server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$body")): eigen sleutel ontvangen" >&2
}
if [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1 \
    && [ "$(cat "$k_dir/pair.used" 2>/dev/null)" != "$(printf '%s' "$pair_code" | sha256sum | cut -c1-16)" ]; then
    prev_key=$enc_key
    if pair_agent; then enc_key=$own_key
    else
        enc_key=$prev_key
        # Zonder sleutel en zonder token valt er niets te versturen
        [ -z "$enc_key" ] && ! [[ "$TOKEN" =~ ^[A-Za-z0-9]{16,128}$ ]] && [ "$TEST" != 1 ] && exit 1
    fi
    set_auth; set_tls
fi

# ---------------------------------------------------------------- Verbindingstest (--test)
pass() { printf '  \e[32m✔\e[0m %s\n' "$*"; }
fail() { printf '  \e[31m✘\e[0m %s\n' "$*"; TEST_FAILED=1; }
hint() { printf '      → %s\n' "$*"; }
note() { printf '  \e[33m⚠\e[0m %s\n' "$*"; }
# Harde fout: volgende stappen hebben geen zin
stop() { echo; echo "  Los dit eerst op en test opnieuw; de volgende stappen zijn overgeslagen."; exit 1; }
TEST_FAILED=0

if [ "$TEST" = 1 ]; then
    base=${MONITOR_URL%/}
    echo "RzMon agent $AGENT_VERSION – verbindingstest"
    echo "  RzMon-adres : $base$([ "$url_set_by_rzmon" = 1 ] && echo " (ingesteld vanuit RzMon; in de instellingen staat $conf_url)")"
    [ -n "$MONITOR_URL_FALLBACK" ] && echo "  Reserve     : $MONITOR_URL_FALLBACK (als het adres hierboven niet bereikbaar is)"
    for t_u in "$base" "$MONITOR_URL_FALLBACK"; do
        case "$(url_spec "$t_u")" in
            sha256//*) echo "  Controle    : $t_u → 📌 alleen het eigen certificaat van RzMon (vastgezet)" ;;
            -) [[ "$t_u" == https://* ]] && echo "  Controle    : $t_u → 🔒 geldig certificaat (gewone controle)" ;;
        esac
    done
    if [ -n "$kid" ]; then echo "  Sleutel     : 🔑 eigen sleutel (kenmerk $kid)"; else echo "  Token       : ${TOKEN:0:6}…${TOKEN: -4}"; fi
    echo

    # 1. Instellingen
    if [ "$base" = "http://rzmon.example.lan" ] || { [ "$TOKEN" = "VUL_HIER_HET_TOKEN_IN" ] && [ -z "$kid" ]; }; then
        fail "Geen instellingen gevonden (adres/token zijn nog de voorbeeldwaarden)"
        hint "Staan ze in /etc/default/rzmon-agent? Draai de test met sudo (het bestand is alleen leesbaar voor root)."
        exit 1
    fi
    pass "Instellingen gevonden"

    # 2. Adres ontleden
    if [[ ! "$base" =~ ^(https?)://([^/:]+)(:([0-9]+))?(/.*)?$ ]]; then
        fail "Ongeldig adres: $base"; exit 1
    fi
    proto=${BASH_REMATCH[1]}; host=${BASH_REMATCH[2]}; port=${BASH_REMATCH[4]}
    [ -n "$port" ] || { [ "$proto" = https ] && port=443 || port=80; }
    case "$host" in
        localhost|127.*|::1) note "Het adres wijst naar deze server zelf ($host): alleen goed als RzMon óp deze server draait"
            hint "Anders: gebruik het IP-adres of de naam van de RzMon-server, bv. http://192.168.1.10" ;;
    esac

    # 3. DNS
    if [[ "$host" =~ ^[0-9.]+$ ]] || getent hosts "$host" >/dev/null 2>&1; then
        pass "Naam opzoeken: $host → $(getent hosts "$host" 2>/dev/null | awk '{print $1; exit}' || echo "$host")"
    else
        fail "De naam '$host' kan niet worden opgezocht (DNS)"
        hint "Gebruik het IP-adres van RzMon, of zet de naam in /etc/hosts"
        stop
    fi

    # 4. Netwerk: kan deze server de poort bereiken?
    if timeout 5 bash -c "exec 3<>/dev/tcp/$host/$port" 2>/dev/null; then
        pass "Verbinding met $host poort $port"
    else
        fail "Geen verbinding met $host poort $port"
        hint "Draait de webserver op RzMon? (systemctl status httpd/apache2/nginx)"
        hint "Firewall op de RzMon-server? Rocky: sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload"
        hint "Zitten deze server en RzMon in hetzelfde netwerk / mag het verkeer ertussen (VLAN, router)?"
        stop
    fi
    if [ -n "${http_proxy:-}${HTTP_PROXY:-}${https_proxy:-}${HTTPS_PROXY:-}" ]; then
        hint "Let op: er is een proxy ingesteld (http_proxy/https_proxy). Zet RzMon zo nodig in no_proxy."
    fi

    curl_t=(-sS -m 15 -o /tmp/rzmon-test.$$ -w '%{http_code} %{redirect_url}' "${tls[@]}")

    # 5. Draait daar RzMon?
    read -r code redirect < <(curl "${curl_t[@]}" "$base/api.php?action=ping" 2>/tmp/rzmon-test-err.$$ || true)
    body=$(cat /tmp/rzmon-test.$$ 2>/dev/null); err=$(cat /tmp/rzmon-test-err.$$ 2>/dev/null)
    old_rzmon=0
    if [ "$code" = 200 ] && [[ "$body" == *'"app":"RzMon"'* ]]; then
        rz_version=$(sed -n 's/.*"version":"\([^"]*\)".*/\1/p' <<<"$body")
        # Nieuwste agent (eigen nummer sinds RzMon 1.46.0); oudere RzMon: gelijk aan de versie van RzMon
        rz_agent=$(sed -n 's/.*"agent_version":"\([^"]*\)".*/\1/p' <<<"$body")
        pass "RzMon ${rz_version:+$rz_version }gevonden op $base"
        if [ -n "${rz_agent:-$rz_version}" ] && [ "${rz_agent:-$rz_version}" != "$AGENT_VERSION" ]; then
            hint "Agent is versie $AGENT_VERSION, de nieuwste is ${rz_agent:-$rz_version}: bijwerken met  sudo rzmon-agent-setup --update"
        fi
    elif [ "$code" = 401 ] && [[ "$body" == *'"error"'* ]]; then
        pass "RzMon gevonden op $base (oudere versie: werk RzMon bij voor een volledige test)"
        old_rzmon=1
    elif [[ "$code" =~ ^30[1278]$ ]]; then
        fail "RzMon stuurt door naar: $redirect"
        hint "Gebruik dat adres als MONITOR_URL (bv. https:// i.p.v. http://): sudo rzmon-agent-setup --url ${redirect%/api.php*}"
    elif [ "$code" = 404 ]; then
        fail "Er draait wel een webserver op $base, maar niet RzMon (404)"
        hint "Klopt het pad? RzMon staat op de hoofd-URL van de vhost, bv. http://192.168.1.10/ — niet /rzmon/public/"
    elif [[ "$err" == *SSL* || "$err" == *certificate* ]]; then
        fail "Certificaatprobleem: ${err#curl: }"
        hint "Self-signed certificaat? Zet INSECURE=\"1\" in /etc/default/rzmon-agent (of: sudo rzmon-agent-setup --insecure)"
    elif [ "$code" = 000 ]; then
        fail "Geen antwoord van $base: ${err#curl: }"
    else
        fail "Onverwacht antwoord van $base (HTTP $code)"
        [ -n "$body" ] && hint "${body:0:200}"
    fi
    if [ "$TEST_FAILED" = 1 ]; then rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$; stop; fi

    # 6. Token, of de eigen sleutel (dan is het antwoord versleuteld: zo weet de agent dat het echt RzMon is)
    if [ -n "$pin" ] && [ "${#tls[@]}" -gt 1 ]; then pass "Certificaat van RzMon vastgezet (📌 sha256//${pin:0:12}…)"
    elif [ -n "$pin" ]; then hint "Vingerafdruk van RzMon bekend, maar deze curl ($c_ver) kan geen certificaat vastzetten (7.44 of nieuwer nodig)"; fi
    curl_k=(-sS -m 15 "${tls[@]}")
    get_auth agent_check
    tk=$(curl "${curl_k[@]}" "${ga[@]}" -w '\n%{http_code}' "$base/api.php?action=agent_check" 2>/dev/null || true)
    tk_code=${tk##*$'\n'}; tk_body=${tk%$'\n'*}
    if [ "$tk_code" = 200 ] && [ -n "$kid" ]; then
        if tk_body=$(unseal "$tk_body" "$get_n"); then
            pass "🔑 Eigen sleutel geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzMon ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
        else
            fail "Het antwoord van RzMon is niet met de sleutel van deze server ondertekend: praat de agent wel met RzMon?"
        fi
    elif [ "$tk_code" = 200 ]; then
        pass "Token geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzMon ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
    elif [ "$tk_code" = 403 ]; then
        fail "$([ -n "$kid" ] && echo "Sleutel" || echo "Token") geweigerd: $(sed -n 's/.*"reason":"\([^"]*\)".*/\1/p' <<<"$tk_body")"
        hint "Maak in RzMon een koppelcode (server → Agent Manager → 🔗 Koppelcode) en voer het commando uit"
        rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
        stop
    elif [ "$old_rzmon" = 1 ]; then
        hint "Tokencontrole overgeslagen (oudere RzMon-versie); het proefrapport hieronder test het token wel"
    else
        fail "Tokencontrole gaf HTTP $tk_code: ${tk_body:0:150}"
    fi
    rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
fi

# ---------------------------------------------------------------- Status en instellingen (1.60.0)
# Elk contact met RzMon wordt bijgehouden in $st_dir (laatste rapport, de laatste 30 contacten, fouten en de
# beveiligingscontrole van RzMon), voor "rzmon-agent-setup --status". Daarnaast meldt de agent welke regels in
# zijn instellingen overbodig zijn (alleen de namen, nooit de waarden), zodat RzMon kan laten opruimen.
st_dir="$t_dir/status"
conf_file=""
if [ "$HA_MODE" != 1 ]; then
    if [ -n "${AGENT_DIR-}" ] && [ -f "$AGENT_DIR/agent.conf" ]; then conf_file="$AGENT_DIR/agent.conf"
    elif [ -f /etc/default/rzmon-agent ]; then conf_file=/etc/default/rzmon-agent; fi
fi
if [ -n "${AGENT_DIR-}" ]; then bk_dir="$AGENT_DIR/backup"; else bk_dir=/var/lib/rzmon-agent/backup; fi
now_ms() { local n; n=$(date +%s%3N 2>/dev/null); [[ "$n" =~ ^[0-9]{13}$ ]] && echo "$n" || echo $(( $(date +%s) * 1000 )); }
conf_has() { [ -n "$conf_file" ] && grep -q "^$1=" "$conf_file" 2>/dev/null; }
conf_val() { local v; v=$(grep "^$1=" "$conf_file" 2>/dev/null | tail -1); v=${v#*=}; v=${v#\"}; v=${v%\"}; printf '%s' "$v"; }
# Overbodige regels, met dezelfde regels als rzmon-agent-setup --cleanup
conf_obsolete() {
    local n v
    [ -n "$conf_file" ] && [ -r "$conf_file" ] || return 0
    for n in TOKEN ENC_KEY PAIR_CODE; do   # met een eigen sleutel niet meer nodig
        conf_has "$n" || continue
        v=$(conf_val "$n"); { [ -z "$v" ] || [ -n "$own_key" ]; } && echo "$n"
    done
    conf_has INTERVAL && echo INTERVAL   # de tik komt uit RzMon
    if conf_has PIN; then v=$(conf_val PIN); { [ -z "$v" ] || [ -s "$k_dir/pin" ] || [ -s "$k_dir/url.pins" ]; } && echo PIN; fi
    if conf_has INSECURE; then
        v=$(conf_val INSECURE); { [ "$v" != 1 ] || [[ "$MONITOR_URL" != https://* ]] || [ -n "$pin" ] || [ -n "$(url_spec "$MONITOR_URL")" ]; } && echo INSECURE
    fi
    conf_has SERVICES && echo SERVICES   # wordt een service-check in RzMon
    conf_has MONITOR_URL_FALLBACK && [ -z "$(conf_val MONITOR_URL_FALLBACK)" ] && echo MONITOR_URL_FALLBACK
    [ -n "$own_key" ] && [ -e "$enc_file" ] && echo enc.key   # bestand met de oude algemene sleutel
    return 0
}
cfg_sched() {
    if [ "$HA_MODE" = 1 ]; then echo ha
    elif [ -n "${AGENT_DIR-}" ]; then echo truenas
    elif [ -n "${INVOCATION_ID-}" ]; then echo systemd
    elif [ -f /etc/cron.d/rzmon-agent ]; then echo cron
    else echo manual; fi
}
cfg_json() {
    local ob sv sha perm bk=0 bl="" ins=0
    ob=$(conf_obsolete | awk 'NF { printf "%s\"%s\"", (n++ ? "," : ""), $0 }')
    sv=$(tr ' ' '\n' <<<"${SERVICES-}" | awk 'NF && /^[A-Za-z0-9@._:-]+$/ { printf "%s\"%s\"", (n++ ? "," : ""), $0 }')
    sha=$(sha256sum "${BASH_SOURCE[0]}" 2>/dev/null | cut -d' ' -f1)
    perm=$( [ -n "$conf_file" ] && stat -c '%a' "$conf_file" 2>/dev/null)
    if [ -d "$bk_dir" ]; then
        bk=$(find "$bk_dir" -maxdepth 1 -name 'agent-*.sh' 2>/dev/null | wc -l)
        bl=$(ls -1t "$bk_dir"/agent-*.sh 2>/dev/null | head -1); bl=${bl##*/}
    fi
    [ "${INSECURE-0}" = 1 ] && [[ "$MONITOR_URL" == https://* ]] && [ -z "$pin" ] && [ -z "$(url_spec "$MONITOR_URL")" ] && ins=1
    printf '{"conf":"%s","perm":"%s","obsolete":[%s],"services":[%s],"insecure":%d,"sha":"%s","backups":%d,"backup_last":"%s","sched":"%s"}' \
        "$(json_escape "$conf_file")" "$perm" "$ob" "$sv" "$ins" "$sha" "$bk" "$(json_escape "$bl")" "$(cfg_sched)"
}

# ---------------------------------------------------------------- --status (1.60.0)
if [ "${STATUS-0}" = 1 ]; then
    sl() { printf '  %-18s %s\n' "$1" "$2"; }
    if [ -t 1 ]; then b1=$'\e[1m'; b0=$'\e[0m'; else b1=""; b0=""; fi   # vet alleen op een terminal
    hd() { printf '\n%s── %s%s\n' "$b1" "$1" "$b0"; }
    dt() { date -d "@$1" '+%d-%m-%Y %H:%M:%S' 2>/dev/null || echo "$1"; }
    ago_s() { local s=$(( $(date +%s) - $1 )); if [ "$s" -lt 120 ]; then echo "$s s geleden"; elif [ "$s" -lt 7200 ]; then echo "$(( s / 60 )) min geleden"; elif [ "$s" -lt 172800 ]; then echo "$(( s / 3600 )) uur geleden"; else echo "$(( s / 86400 )) dagen geleden"; fi; }
    kb() { awk -v b="$1" 'BEGIN { if (b < 1024) printf "%d bytes", b; else printf "%.1f KB", b / 1024 }'; }
    st_get() { sed -n "s/^$1=//p" "$st_dir/last" 2>/dev/null | head -1; }
    printf '%sRzMon agent %s%s – status van %s op %s\n' "$b1" "$AGENT_VERSION" "$b0" "$(hostname)" "$(date '+%d-%m-%Y %H:%M:%S')"

    hd "Agent"
    sl "Versie:" "$AGENT_VERSION"
    sl "Script:" "${BASH_SOURCE[0]} (sha256 $(sha256sum "${BASH_SOURCE[0]}" 2>/dev/null | cut -c1-16)…)"
    case "$(cfg_sched)" in
        systemd|manual)
            if command -v systemctl >/dev/null 2>&1 && [ "$(systemctl is-active rzmon-agent.timer 2>/dev/null)" = active ]; then
                nx=$(systemctl show rzmon-agent.timer -p NextElapseUSecRealtime --value 2>/dev/null)
                sl "Gestart door:" "systemd-timer (actief${nx:+, volgende start: $nx})"
            elif [ -f /etc/cron.d/rzmon-agent ]; then sl "Gestart door:" "cron (/etc/cron.d/rzmon-agent)"
            else sl "Gestart door:" "⚠️ geen actieve timer of cronjob gevonden"; fi
            if command -v systemctl >/dev/null 2>&1; then
                sl "Realtime-watcher:" "$(systemctl is-active rzmon-watch.service 2>/dev/null || echo uit)"
            fi ;;
        cron) sl "Gestart door:" "cron (/etc/cron.d/rzmon-agent)" ;;
        truenas) sl "Gestart door:" "TrueNAS-cronjob (System → Advanced → Cron Jobs)" ;;
        ha) sl "Gestart door:" "Home Assistant-add-on" ;;
    esac
    if [ -n "$conf_file" ]; then
        ob=$(conf_obsolete | tr '\n' ' '); ob=${ob% }
        sl "Instellingen:" "$conf_file (rechten $(stat -c '%a %U' "$conf_file" 2>/dev/null))"
        if [ -n "$ob" ]; then sl "" "⚠️ overbodig: $ob → opruimen: rzmon-agent-setup --cleanup (of 🧹 in de Agent Manager)"
        else sl "" "✔ opgeruimd: geen overbodige regels"; fi
    fi
    if [ -d "$bk_dir" ] && ls "$bk_dir"/agent-*.sh >/dev/null 2>&1; then
        bl=$(ls -1t "$bk_dir"/agent-*.sh | head -1)
        sl "Reservekopie:" "$(find "$bk_dir" -maxdepth 1 -name 'agent-*.sh' | wc -l) × in $bk_dir (laatste: ${bl##*/}, $(dt "$(stat -c %Y "$bl")"))"
    fi

    hd "Verbinding met RzMon"
    sl "Adres:" "$MONITOR_URL"
    [ -n "$MONITOR_URL_FALLBACK" ] && sl "Reserve-adres:" "$MONITOR_URL_FALLBACK (als het gewone adres niet bereikbaar is)"
    if [[ "$MONITOR_URL" != https://* ]]; then sl "Certificaat:" "⚠️ via http: de inhoud is versleuteld, maar niet verborgen dat er verkeer is (zet RzMon op https)"
    elif [ -n "$pin" ]; then sl "Certificaat:" "🔒 vastgezet (sha256//${pin:0:12}…): alleen dit certificaat wordt geaccepteerd"
    elif [ "${INSECURE-0}" = 1 ]; then sl "Certificaat:" "⚠️ niet gecontroleerd (INSECURE=1, geen vingerafdruk)"
    else sl "Certificaat:" "✔ gewone controle (vertrouwd certificaat)"; fi
    pr=$(LC_ALL=C curl -sS "${tls[@]}" -m 10 -o /dev/null -w '%{http_code} %{time_namelookup} %{time_connect} %{time_appconnect} %{time_total} %{local_ip} %{remote_ip}' \
        "${MONITOR_URL%/}/api.php?action=ping" 2>&1)
    read -r p_code p_dns p_con p_tls p_tot p_lip p_rip <<<"$pr"
    if [ "$p_code" = 200 ]; then
        sl "Nu bereikbaar:" "✔ in $(awk -v t="$p_tot" 'BEGIN { printf "%d", t * 1000 }') ms (DNS $(awk -v t="$p_dns" 'BEGIN { printf "%d", t * 1000 }') ms, verbinden $(awk -v t="$p_con" 'BEGIN { printf "%d", t * 1000 }') ms$( [[ "$MONITOR_URL" == https://* ]] && printf ', TLS %d ms' "$(awk -v t="$p_tls" 'BEGIN { printf "%d", t * 1000 }')")), van $p_lip naar $p_rip"
    else
        pr=${pr#curl: }; sl "Nu bereikbaar:" "✘ $(head -1 <<<"$pr" | cut -c1-160)"
    fi
    if [[ "$MONITOR_URL" == https://* ]] && command -v openssl >/dev/null 2>&1; then
        hp=${MONITOR_URL#https://}; hp=${hp%%/*}; h_h=${hp%:*}; h_p=443; [[ "$hp" == *:* ]] && h_p=${hp##*:}
        so=$(LC_ALL=C timeout 10 openssl s_client -connect "$h_h:$h_p" -servername "$h_h" </dev/null 2>/dev/null)
        # OpenSSL 3: "New, TLSv1.3, Cipher is …"; ouder: "Protocol : …" en "Cipher : …"
        pv=$(sed -n 's/^New, \(TLSv[0-9.]*\), Cipher is \(.*\)$/\1, \2/p' <<<"$so" | head -1)
        [ -n "$pv" ] || pv="$(sed -n 's/^ *Protocol *: *//p' <<<"$so" | head -1), $(sed -n 's/^ *Cipher *: *//p' <<<"$so" | head -1)"
        [ "$pv" != ", " ] && sl "TLS:" "$pv"
        if crt=$(openssl x509 2>/dev/null <<<"$so"); then
            c_cn=$(openssl x509 -noout -subject -nameopt RFC2253 <<<"$crt" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
            c_na=$(date -d "$(openssl x509 -noout -enddate <<<"$crt" | cut -d= -f2)" '+%d-%m-%Y' 2>/dev/null)
            c_pin=$(openssl x509 -pubkey -noout <<<"$crt" | openssl pkey -pubin -outform der 2>/dev/null | openssl dgst -sha256 -binary | base64)
            pm=""; [ -n "$pin" ] && { [ "$c_pin" = "$pin" ] && pm=" · vingerafdruk klopt ✔" || pm=" · ⚠️ vingerafdruk wijkt af (RzMon stuurt de nieuwe bij het volgende rapport)"; }
            sl "Certificaat RzMon:" "${c_cn:-?}, geldig tot ${c_na:-?}$pm"
        fi
    fi

    hd "Versleuteling"
    if [ -n "$own_key" ]; then
        sl "Sleutel:" "🔑 eigen sleutel van deze server (kenmerk $(enc_sub "$own_key" id | cut -c1-16)), sinds $(dt "$(stat -c %Y "$own_file")")"
        sl "" "RzMon vernieuwt hem vanzelf; er gaat geen token of sleutel over de lijn"
    elif [ -n "$enc_key" ]; then sl "Sleutel:" "⚠️ alleen de oude algemene sleutel: koppel opnieuw met een koppelcode (rzmon-agent-setup --pair …)"
    else sl "Sleutel:" "⚠️ geen sleutel: rapporten gaan onversleuteld met een token. Koppel met: rzmon-agent-setup --pair …"; fi
    [ -n "$enc_key" ] && sl "Methode:" "AES-256-CBC + HMAC-SHA256; elk bericht uniek (nonce) en 5 minuten geldig; antwoorden van RzMon ondertekend"
    [ -n "$own_key" ] && [ -e "$enc_file" ] && sl "Oude sleutel:" "⚠️ het bestand met de algemene sleutel staat er nog ($enc_file) → --cleanup"

    hd "Contact"
    if [ -r "$st_dir/last" ]; then
        l_t=$(st_get time); l_rc=$(st_get rc)
        if [ "$l_rc" = 0 ]; then
            sl "Laatste rapport:" "✔ $(dt "$l_t") ($(ago_s "$l_t")), HTTP $(st_get http), $(st_get ms) ms (versturen $(st_get send_ms) ms)"
        else
            sl "Laatste rapport:" "✘ $(dt "$l_t") ($(ago_s "$l_t")): $(st_get error)"
        fi
        sl "Verstuurd:" "$(kb "$(st_get bytes)") ($( [ "$(st_get enc)" = 1 ] && echo "🔒 versleuteld $(kb "$(st_get sent)")" || echo "⚠️ onversleuteld")), via $( [ "$(st_get via)" = fallback ] && echo "⚠️ het reserve-adres" || echo "het gewone adres")"
        rv=$(st_get received | tr -d '"' | tr ',' '\n' | awk -F: '{ n = $1; sub(/^disks$/, "disks", n); sub(/^ports$/, "poorten", n); sub(/^certs$/, "certificaten", n)
            printf "%s%s %s", (NR > 1 ? ", " : ""), $2, n }')
        [ -n "$rv" ] && sl "RzMon ontving:" "$rv"
        pt=$(st_get parts | sed 's/ / · /g; s/=/: /g'); [ -n "$pt" ] && sl "Ook meegestuurd:" "$pt"
        ok_t=$(awk -F'\t' '$2 == 0 { t = $1 } END { print t }' "$st_dir/history" 2>/dev/null)
        [ -n "$ok_t" ] && [ "$l_rc" != 0 ] && sl "Laatst gelukt:" "$(dt "$ok_t") ($(ago_s "$ok_t"))"
    else
        sl "Laatste rapport:" "nog niet bijgehouden (komt na het volgende rapport)"
    fi
    tk=$(awk -F'\t' '$1 == "0" && $2 == "tick" { print $3; exit }' "$t_cache" 2>/dev/null)
    if [ -n "$tk" ]; then
        sl "Ritme:" "elke $tk s (ingesteld in RzMon)$( [ -n "${l_t-}" ] && echo "; volgende rapport rond $(date -d "@$(( l_t + tk ))" '+%H:%M:%S' 2>/dev/null)")"
    fi
    if [ -s "$st_dir/history" ]; then
        echo "  Laatste contacten:"
        tail -n 10 "$st_dir/history" | tac 2>/dev/null | while IFS=$'\t' read -r h_t h_rc h_c h_ms h_b h_e h_v; do
            printf '    %s  %s  %6s ms  %9s  %s%s\n' "$(dt "$h_t")" "$( [ "$h_rc" = 0 ] && echo "✔ $h_c" || echo "✘ ${h_c:-curl $h_rc}")" "$h_ms" "$(kb "$h_b")" \
                "$( [ "$h_e" = 1 ] && echo 🔒 || echo ⚠️)" "$( [ "$h_v" = fallback ] && echo ' via reserve-adres')"
        done
    fi
    if [ -s "$st_dir/errors" ]; then
        n_e=$(awk -F'\t' -v d="$(( $(date +%s) - 86400 ))" '$1 >= d' "$st_dir/errors" | wc -l)
        if [ "$n_e" -gt 0 ]; then
            sl "Fouten (24 uur):" "$n_e×, laatste: $(tail -1 "$st_dir/errors" | cut -f2) ($(dt "$(tail -1 "$st_dir/errors" | cut -f1)"))"
        fi
    fi
    if [ -r "$t_cache" ]; then
        n_svc=$(grep -c $'^[0-9]*\tsvc\t' "$t_cache"); n_crt=$(grep -c $'^0\tcert\t' "$t_cache"); n_pr=$(grep -c $'^[0-9]*\tproc\t' "$t_cache")
        n_log=$(grep -c $'^[0-9]*\tlog\t' "$t_cache"); n_cmd=$(grep -c $'^[0-9]*\tcmd\t' "$t_cache")
        idsm=$(awk -F'\t' '$1 == "0" && $2 == "ids" { print $3; exit }' "$t_cache")
        case "$idsm" in block) idsm="aan, met blokkeren" ;; "") idsm=uit ;; *) idsm="aan ($idsm)" ;; esac
        sl "Bewaakt (RzMon):" "$n_svc services, $n_crt certificaten, $n_pr processen, $n_log logzoekopdrachten, $n_cmd commando's; IDS: $idsm"
        if command -v pvesh >/dev/null 2>&1 && [ "${PVE:-1}" != 0 ]; then
            if [ -s "$t_dir/pve/live" ]; then
                p_vm=$(grep -o '"vmid":[0-9]*' <(sed -n 's/.*"qemu":\(\[[^]]*\]\).*/\1/p' "$t_dir/pve/live") | wc -l)
                p_ct=$(grep -o '"vmid":[0-9]*' <(sed -n 's/.*"lxc":\(\[[^]]*\]\).*/\1/p' "$t_dir/pve/live") | wc -l)
                p_run=$(grep -o '"status":"running"' "$t_dir/pve/live" | wc -l)
                sl "Proxmox VE:" "$p_vm VM's en $p_ct containers ($p_run draaien), bijgewerkt $(ago_s "$(stat -c %Y "$t_dir/pve/live")")"
            else
                sl "Proxmox VE:" "nog niet uitgelezen (komt bij het volgende rapport)"
            fi
        fi
        sl "" "instellingen van RzMon opgehaald $(ago_s "$(stat -c %Y "$t_cache")")"
    fi

    hd "Beveiligingscontrole door RzMon"
    if [ -s "$st_dir/sec" ]; then sed 's/^/  /' "$st_dir/sec"
    else echo "  (komt mee met het volgende rapport; RzMon 1.100.0 of nieuwer)"; fi
    echo
    exit 0
fi

# Supervisor API (alleen HA-modus)
sv() { curl -fsS -m 10 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SUPERVISOR_URL$1" 2>/dev/null; }

# ---------------------------------------------------------------- Instellingen uit RzMon en de tik
# Wat in RzMon is ingesteld: welke services bewaakt worden (service-checks), extra tests (URL/poort),
# de eigen poorten of sockets van een service, de logzoekopdrachten, de intervallen per check en de tik.
# De lijst wordt elke 10 minuten bij RzMon opgehaald, en meteen als RzMon meldt dat er iets veranderd is
# (tests_rev in het antwoord). Commando's van RzMon worden alleen uitgevoerd bij commando-checks, als RzMon
# ze voor deze server toestaat en COMMANDS (hierboven) dat niet blokkeert; zie "Commando-checks" verderop.
if [ "$TEST" = 1 ] || [ ! -r "$t_cache" ] || [ -e "$t_dir/tests.stale" ] || [ $(( $(date +%s) - $(stat -c %Y "$t_cache") )) -ge 600 ]; then
    get_auth agent_tests
    t_k=("${tls[@]}" "${ga[@]}"); t_n=$get_n
    # Alleen een complete lijst bewaren (met de vaste regel "0 <tab> tick"). Geeft RzMon iets anders terug,
    # bijvoorbeeld tijdens een update, dan blijft de vorige lijst staan: anders zou de agent denken dat het
    # IDS uit staat en de blokkades opheffen.
    # Met een sleutel moet de lijst versleuteld terugkomen; anders is hij niet te vertrouwen
    if t_list=$(curl -fsS "${t_k[@]}" -m 10 "${MONITOR_URL%/}/api.php?action=agent_tests" 2>/dev/null) \
        && { if [[ "$t_list" == '{"rzenc":'* ]]; then t_list=$(unseal "$t_list" "$t_n"); else [ -z "$t_n" ]; fi; } \
        && grep -q $'^0\ttick\t[0-9]' <<<"$t_list"; then
        (umask 077; printf '%s\n' "$t_list" >"$t_cache") 2>/dev/null && rm -f "$t_dir/tests.stale"
    fi
fi
# Tik: hoe vaak er een rapport uitgaat (regel "0 <tab> tick <tab> seconden", per server in RzMon in te stellen;
# anders INTERVAL). De timer start de agent vaker (elke 30 s); is de tik nog niet om, dan meteen stoppen.
tick=$(awk -F'\t' '$1 == "0" && $2 == "tick" { print $3; exit }' "$t_cache" 2>/dev/null)
[[ "$tick" =~ ^[0-9]+$ ]] && [ "$tick" -ge 30 ] || tick=${INTERVAL:-60}
[[ "$tick" =~ ^[0-9]+$ ]] || tick=60
if [ "$TEST" != 1 ] && [ "${RZ_FORCE-}" != 1 ] && [ -f "$t_dir/last.run" ] && [ $(( $(date +%s) - $(stat -c %Y "$t_dir/last.run") )) -lt $(( tick - 10 )) ]; then
    exit 0
fi
# Nooit twee runs tegelijk (timer en realtime-watcher): even wachten op een run die al bezig is
if [ "$TEST" != 1 ] && command -v flock >/dev/null 2>&1; then
    exec 9>"$t_dir/run.lock"
    # BusyBox-flock (Alpine, de Home Assistant-add-on) kent -w niet: dan zonder wachten proberen.
    # Lukt het allebei niet, dan loopt er al een run en slaan we deze keer over.
    if ! flock -w 25 9 2>/dev/null && ! flock -n 9 2>/dev/null; then
        exit 0
    fi
fi
touch "$t_dir/last.run" 2>/dev/null
run_t0=$(now_ms)

# ---------------------------------------------------------------- Disk
disks=""
hostname=$(hostname)
if [ "$HA_MODE" = 1 ]; then
    # In de add-on-container is df onbruikbaar (bind mounts); de Supervisor kent de data-partitie (GB)
    host_info=$(sv /host/info)
    if [ -n "$host_info" ]; then
        hostname=$(jq -r '.data.hostname // empty' <<<"$host_info")
        disks=$(jq -r '.data | select(.disk_total != null and .disk_total > 0)
            | "{\"mount\":\"/data\",\"total\":\(.disk_total * 1073741824 | floor),\"avail\":\(.disk_free * 1073741824 | floor)},"' <<<"$host_info")
    fi
else
    while read -r _fs total _used avail _pct mount; do
        [ -z "$mount" ] && continue
        disks+="{\"mount\":\"$(json_escape "$mount")\",\"total\":$total,\"avail\":$avail},"
    done < <(df -P -B1 -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs 2>/dev/null | tail -n +2)
fi

# ---------------------------------------------------------------- Podman: voorbereiding
# Contexten: "root" (of de huidige gebruiker) plus gebruikers met rootless containers
podman_ctx=()
if [ "$PODMAN" != 0 ] && [ "$HA_MODE" = 0 ] && command -v podman >/dev/null 2>&1; then
    me=$(id -un)
    podman_ctx=("$( [ "$(id -u)" = 0 ] && echo root || echo "$me")")
    if [ "$(id -u)" = 0 ] && [ -n "$PODMAN_USERS" ]; then
        if [ "$PODMAN_USERS" = auto ]; then
            while IFS=: read -r u _ uid _ _ home _; do
                # Alleen gebruikers die echt containers hebben (een lege podman-map telt niet):
                # elke container heeft een map <driver>-containers/<id>/userdata (overlay, vfs, btrfs…)
                [ "$uid" -ge 1000 ] && [ "$uid" -lt 65534 ] \
                    && compgen -G "$home/.local/share/containers/storage/*-containers/*/userdata" >/dev/null && podman_ctx+=("$u")
            done < <(getent passwd 2>/dev/null)
        else
            for u in $PODMAN_USERS; do id -u "$u" >/dev/null 2>&1 && podman_ctx+=("$u"); done
        fi
    fi
fi
pm() {  # pm <context> <podman-argumenten…>: podman als root, of als die gebruiker (rootless)
    local ctx=$1 uid
    shift
    if [ "$ctx" = root ] || [ "$ctx" = "$(id -un)" ]; then
        timeout 60 podman "$@"
    else
        uid=$(id -u "$ctx")
        (cd / && if [ -d "/run/user/$uid" ]; then export XDG_RUNTIME_DIR="/run/user/$uid"; else unset XDG_RUNTIME_DIR; fi
         timeout 60 runuser -u "$ctx" -- podman "$@")
    fi
}
# Ruwe tellers per draaiende container; twee metingen → actueel CPU-gebruik (zoals podman stats).
# Netwerk via {{.NetIO}} ("27.2MB / 25.1MB"): podman 5 heeft NetInput/NetOutput weggehaald, en een
# onbekend veld laat podman na de eerste container stoppen (dan kregen alleen die cijfers).
podman_stats() {
    local ctx
    for ctx in "${podman_ctx[@]}"; do
        pm "$ctx" stats --no-stream --no-trunc --format '{{.ContainerStats.ContainerID}}|{{.ContainerStats.CPUNano}}|{{.ContainerStats.SystemNano}}|{{.ContainerStats.MemUsage}}|{{.ContainerStats.MemLimit}}|{{.NetIO}}|{{.ContainerStats.BlockInput}}|{{.ContainerStats.BlockOutput}}|{{.ContainerStats.PIDs}}' 2>/dev/null \
            | sed "s/^/S|$ctx|/"
    done
}
pod_stats_a=""
[ ${#podman_ctx[@]} -gt 0 ] && pod_stats_a=$(podman_stats)

# ---------------------------------------------------------------- CPU
# CPU-gebruik over 2 seconden uit /proc/stat (idle = idle + iowait)
read -r _ u1 n1 s1 i1 w1 q1 sq1 st1 _ < /proc/stat
sleep 2
read -r _ u2 n2 s2 i2 w2 q2 sq2 st2 _ < /proc/stat
t1=$((u1+n1+s1+i1+w1+q1+sq1+${st1:-0})); t2=$((u2+n2+s2+i2+w2+q2+sq2+${st2:-0}))
idle=$(( (i2+w2) - (i1+w1) )); total=$(( t2 - t1 ))
cpu_pct=$(awk -v i="$idle" -v t="$total" 'BEGIN { printf "%.1f", (t > 0 ? (1 - i / t) * 100 : 0) }')
read -r l1 l5 l15 _ < /proc/loadavg
cores=$(grep -c '^cpu[0-9]' /proc/stat)

# ---------------------------------------------------------------- RAM
# Geheugen uit /proc/meminfo (kB -> bytes)
mem=$(awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ { v[$1] = $2 * 1024 }
    END { printf "{\"total\":%.0f,\"avail\":%.0f,\"swap_total\":%.0f,\"swap_free\":%.0f}",
          v["MemTotal:"], v["MemAvailable:"], v["SwapTotal:"], v["SwapFree:"] }' /proc/meminfo)

# ---------------------------------------------------------------- Poorten
# Luisterende TCP-poorten (procesnamen alleen zichtbaar als root, niet in de HA-add-on)
ports=""
if command -v ss >/dev/null 2>&1; then
    ports=$(ss -Htlnp 2>/dev/null | awk '{
        local = $4; port = local; sub(/.*:/, "", port); addr = local; sub(/:[^:]*$/, "", addr)
        gsub(/[\[\]"]/, "", addr); proc = ""
        if (match($0, /users:\(\("[^"]+"/)) { proc = substr($0, RSTART + 9, RLENGTH - 10) }
        key = port "|" addr
        if (port ~ /^[0-9]+$/ && !(key in seen)) {
            seen[key] = 1
            printf "{\"port\":%s,\"addr\":\"%s\",\"proc\":\"%s\"},", port, addr, proc
        }
    }')
fi

# Wat in RzMon is ingesteld (zie "Instellingen uit RzMon" bovenaan): hier alleen de regels voor services.
declare -A manual_ports=()
declare -A ival_by_id=()   # interval per check (s), voor zware onderdelen: diepere controle, extra tests, logs
rz_services=""
[ -r "${t_cache-}" ] && while IFS=$'\t' read -r t_id t_type t_target t_expect; do
    [ "$t_type" = ival ] && [[ "$t_id" =~ ^[0-9]+$ ]] && [[ "$t_target" =~ ^[0-9]+$ ]] && ival_by_id[$t_id]=$t_target
    [ "$HA_MODE" = 0 ] || continue
    [ "$t_type" = ports ] && manual_ports["$t_target"]+=" $t_expect"
    [ "$t_type" = svc ] && [[ "$t_target" =~ ^[A-Za-z0-9@._:-]+$ ]] && rz_services+=" $t_target"
done <"$t_cache"
# Interval per service (via de check-id van de service-check; bij meer checks op dezelfde service: de kortste)
declare -A ival_by_svc=()
[ -r "${t_cache-}" ] && while IFS=$'\t' read -r t_id t_type t_target _; do
    [ "$t_type" = svc ] && [ -n "${ival_by_id[$t_id]-}" ] || continue
    [ -z "${ival_by_svc[$t_target]-}" ] || [ "${ival_by_id[$t_id]}" -lt "${ival_by_svc[$t_target]}" ] && ival_by_svc[$t_target]=${ival_by_id[$t_id]}
done <"$t_cache"
due_cached() {  # due_cached <bestand> <interval>: 0 = vorige uitkomst is nog vers genoeg (niet opnieuw doen)
    [ -n "$2" ] && [ -s "$1" ] && [ $(( $(date +%s) - $(stat -c %Y "$1") )) -lt $(( $2 - 5 )) ]
}
# Te bewaken services: die met een service-check in RzMon, plus (verouderd) SERVICES uit de instellingen
svc_all=$(printf '%s\n' $SERVICES $rz_services | awk 'NF && !seen[$0]++' | tr '\n' ' ')

# ---------------------------------------------------------------- Zelf herstellen (agent 1.62.0)
# Per check in RzMon aan te zetten (✎ → 🩹 Zelf herstellen). Een service of container die niet draait, herstart de
# agent hier, vóór het rapport: lukt dat, dan blijft de check in RzMon OK en komt er een ℹ️-melding; lukt het niet, dan
# gaat de gewone melding uit. Een eigen herstelcommando (HEAL_DIR) start de agent alleen op verzoek van RzMon, in een
# versleuteld antwoord, als die check misgaat (zie "Versturen"). Hooguit één poging per 10 minuten en 3 per dag per check.
heal_dir="$t_dir/heal"
heal_ok() {  # heal_ok <check-id>: mag er nu een poging?
    local f="$heal_dir/$1.tries" now n last
    now=$(date +%s)
    [ -r "$f" ] || return 0
    n=$(awk -v t=$(( now - 86400 )) '$1 > t' "$f" | wc -l)
    last=$(tail -n 1 "$f")
    [ "$n" -lt 3 ] && [ $(( now - ${last:-0} )) -ge 600 ]
}
heal_log() {  # heal_log <check-id> <ok: 0/1> <bericht>: poging onthouden, en de uitkomst gaat mee met het volgende rapport
    mkdir -p "$heal_dir" 2>/dev/null || return 0
    date +%s >>"$heal_dir/$1.tries"
    tail -n 5 "$heal_dir/$1.tries" >"$heal_dir/$1.tmp" 2>/dev/null && mv -f "$heal_dir/$1.tmp" "$heal_dir/$1.tries"
    printf '{"id":%s,"ok":%s,"t":%s,"m":"%s"}\n' "$1" "$2" "$(date +%s)" "$(json_escape "$(printf '%s' "$3" | tr '\n\t' '  ' | cut -c1-200)")" >>"$heal_dir/done"
}
heal_cmd_ok() {  # heal_cmd_ok <pad>: alleen uitvoerbaar, van root en niet door anderen te wijzigen
    [ -f "$1" ] && [ ! -L "$1" ] && [ -x "$1" ] && [ "$(stat -c '%u' "$1")" = 0 ] && ! find "$1" -perm /022 | grep -q .
}
if [ "$HA_MODE" = 0 ] && [ "$HEAL" != 0 ] && [ "$TEST" != 1 ] && [ "$(id -u)" = 0 ] && [ -r "${t_cache-}" ]; then
    while IFS=$'\t' read -r h_id h_type h_kind h_target; do
        [ "$h_type" = heal ] && [[ "$h_id" =~ ^[0-9]+$ ]] || continue
        if [ "$h_kind" = svc ] && [[ "$h_target" =~ ^[A-Za-z0-9@._:-]+$ ]] && command -v systemctl >/dev/null 2>&1; then
            h_st=$(systemctl is-active "$h_target" 2>/dev/null)
            [ "$h_st" = failed ] || [ "$h_st" = inactive ] || continue
            heal_ok "$h_id" || continue
            timeout 60 systemctl restart "$h_target" >/dev/null 2>&1 && sleep 5
            h_st2=$(systemctl is-active "$h_target" 2>/dev/null)
            if [ "$h_st2" = active ]; then heal_log "$h_id" 1 "service $h_target was $h_st, herstart gelukt"
            else heal_log "$h_id" 0 "service $h_target was $h_st, herstart mislukt (nu ${h_st2:-onbekend})"; fi
        elif [ "$h_kind" = ctr ] && [[ "$h_target" =~ ^([a-z_][a-z0-9_.-]*)/([A-Za-z0-9][A-Za-z0-9_.-]*)$ ]] && [ ${#podman_ctx[@]} -gt 0 ]; then
            h_ctx=${BASH_REMATCH[1]}; h_name=${BASH_REMATCH[2]}
            [ "$h_ctx" = root ] || id -u "$h_ctx" >/dev/null 2>&1 || continue
            h_st=$(pm "$h_ctx" inspect --format '{{.State.Status}}' "$h_name" 2>/dev/null | head -n 1)
            case "$h_st" in exited|stopped|created|dead) ;; *) continue ;; esac
            heal_ok "$h_id" || continue
            pm "$h_ctx" start "$h_name" >/dev/null 2>&1 && sleep 5
            h_st2=$(pm "$h_ctx" inspect --format '{{.State.Status}}' "$h_name" 2>/dev/null | head -n 1)
            if [ "$h_st2" = running ]; then heal_log "$h_id" 1 "container $h_name was $h_st, gestart"
            else heal_log "$h_id" 0 "container $h_name was $h_st, starten mislukt (nu ${h_st2:-onbekend})"; fi
        fi
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Services
services=""
if [ "$HA_MODE" = 1 ]; then
    core_state=failed
    sv /core/api/ >/dev/null && core_state=active
    services+="\"homeassistant\":\"$core_state\","

    sup_info=$(sv /supervisor/info)
    [ -z "$sup_info" ] && sup_info='{}'
    sup_state=$(jq -r 'if .data.healthy == false then "unhealthy" else "active" end' <<<"$sup_info" 2>/dev/null)
    services+="\"supervisor\":\"${sup_state:-unknown}\","

    addons=$(sv /addons)
    if [ -n "$addons" ]; then
        services+=$(jq -r '.data.addons[]
            | "\(("addon:" + .slug) | tojson):\((if .state == "started" then "active" else (.state // "unknown") end) | tojson),"' \
            <<<"$addons" | tr -d '\n')
    fi

    # Plugins van de Supervisor (DNS, Audio, CLI, Multicast, Observer): draaien ze (statistieken beschikbaar)
    # en is er een update. De statistieken worden hieronder ook voor de containers gebruikt.
    declare -A pl_stats=()
    plugin_upd=""
    for p in dns audio cli multicast observer; do
        p_info=$(sv "/$p/info") || continue
        pl_stats[$p]=$(sv "/$p/stats" | jq -c '.data // empty' 2>/dev/null)
        services+="\"plugin:$p\":\"$([ -n "${pl_stats[$p]}" ] && echo active || echo failed)\","
        jq -e '.data.update_available == true' <<<"$p_info" >/dev/null 2>&1 && plugin_upd+=" Plugin-$p"
    done

    # Beschikbare updates (Core, Supervisor, OS, plugins en add-ons) → WARNING in RzMon
    updates=$( {
        sv /core/info | jq -r 'select(.data.update_available) | "Core \(.data.version_latest)"'
        jq -r 'select(.data.update_available) | "Supervisor"' <<<"$sup_info"
        sv /os/info | jq -r 'select(.data.update_available) | "OS \(.data.version_latest)"'
        [ -n "$addons" ] && jq -r '.data.addons[] | select(.update_available) | .name' <<<"$addons"
        for p in $plugin_upd; do echo "$p"; done
    } 2>/dev/null | paste -sd, - | sed 's/,/, /g')
    if [ -n "$updates" ]; then
        services+="\"updates\":\"$(json_escape "update-available: $updates")\","
    else
        services+="\"updates\":\"active\","
    fi
else
    for s in $svc_all; do
        state=$(systemctl is-active "$s" 2>/dev/null)
        services+="\"$(json_escape "$s")\":\"$(json_escape "${state:-unknown}")\","
    done
fi

# Overzicht van de services op deze server (aan bij het opstarten, of draaiend), om in RzMon uit te kiezen.
# Formaat: [naam, status, enabled]
svc_list=""
if [ "$HA_MODE" = 0 ] && command -v systemctl >/dev/null 2>&1; then
    svc_list=$({ systemctl list-unit-files --type=service --no-legend --no-pager 2>/dev/null | awk '{ print "F", $1, $2 }'
                 systemctl list-units --type=service --all --no-legend --no-pager --plain 2>/dev/null | awk '{ print "U", $1, $3 }'; } |
        awk '$2 ~ /\.service$/ && $2 !~ /@\.service$/ { n = $2; sub(/\.service$/, "", n)
                 if (n !~ /^[A-Za-z0-9@._:-]+$/) next   # bv. systemd-fsck@dev-disk-by\x2duuid…: backslash breekt de JSON
                 if ($1 == "F") en[n] = $3; else st[n] = $3; names[n] = 1 }
             END { for (n in names) if (en[n] == "enabled" || st[n] == "active" || (st[n] == "failed" && en[n] != "disabled"))
                       printf "[\"%s\",\"%s\",%d],", n, (n in st ? st[n] : "inactive"), en[n] == "enabled" }' 2>/dev/null)
fi
# Home Assistant: Core, Supervisor, add-ons en updates als lijst om uit te kiezen
if [ "$HA_MODE" = 1 ] && [ -n "$services" ]; then
    svc_list=$(jq -r 'to_entries[] | "[\(.key | tojson),\((if .value == "active" then "active" elif (.value | test("update-available")) then "update" else .value end) | tojson),1],"' \
        <<<"{${services%,}}" 2>/dev/null | tr -d '\n')
fi

# ---------------------------------------------------------------- Home Assistant: back-up, integraties, systeemstatus
# Elke 5 minuten opnieuw opgehaald (de lijst met entiteiten kan groot zijn). Alleen lezen.
ha_json=""
if [ "$HA_MODE" = 1 ]; then
    ha_cache="${TMPDIR:-/tmp}/rzmon-ha.json"
    if [ "$TEST" != 1 ] && [ -s "$ha_cache" ] && [ $(( $(date +%s) - $(stat -c %Y "$ha_cache") )) -lt 300 ]; then
        ha_json=$(cat "$ha_cache")
    else
        ha_get() {  # ha_get <pad> → body; exitcode 0 alleen bij HTTP 200
            local out
            out=$(curl -sS -m 20 -H "Authorization: Bearer $SUPERVISOR_TOKEN" -w '\n%{http_code}' "$SUPERVISOR_URL$1" 2>&1) || { echo "${out##*$'\n'}"; return 1; }
            [ "${out##*$'\n'}" = 200 ] || { echo "HTTP ${out##*$'\n'}"; return 1; }
            printf '%s' "${out%$'\n'*}"
        }
        now_s=$(date +%s)
        # 1. Back-up: de sensoren van de backup-integratie (HA 2025.2+). Tijdstempels in het verleden zijn de
        #    laatste geslaagde en de laatste poging (naam met "succes"/"erfolg" = geslaagd), in de toekomst de volgende.
        if states=$(ha_get /core/api/states); then
            ha_backup=$(jq -c --argjson now "$now_s" '
                def ts: sub("\\.[0-9]+"; "") | sub("[+]00:00$"; "Z") | (try fromdateiso8601 catch null);
                [.[] | select(.entity_id | startswith("sensor.backup_"))] as $b
                | [$b[] | select(.attributes.device_class == "timestamp") | {name: (.attributes.friendly_name // .entity_id), t: (.state | ts)}
                   | select(.t != null)] as $ts
                | [$ts[] | select(.t <= $now)] as $past
                | ([$past[] | select(.name | test("succes|erfolg|réussi"; "i"))] | max_by(.t) // null) as $ok
                | {found: ($b | length > 0),
                   last_ok: (if $ok then $ok.t elif ($past | length) == 1 then $past[0].t else ($past | min_by(.t) | .t?) end),
                   last_try: ($past | max_by(.t) | .t?),
                   next: ([$ts[] | select(.t > $now)] | min_by(.t) | .t?)}' <<<"$states" 2>/dev/null)
        else
            ha_backup="{\"error\":\"$(json_escape "Home Assistant-API: $states")\"}"
        fi
        # 2. Integraties die niet geladen zijn (uitgeschakelde tellen niet mee)
        if entries=$(ha_get /core/api/config/config_entries/entry); then
            ha_integr=$(jq -c '[.[] | select(.disabled_by == null)] as $all
                | {total: ($all | length), failed: [$all[] | select(.state == "setup_error" or .state == "setup_retry" or .state == "failed_unload")
                   | {domain, title, state, reason: (.reason // null)}]}' <<<"$entries" 2>/dev/null)
        else
            ha_integr="{\"error\":\"$(json_escape "integratielijst niet beschikbaar voor de add-on ($entries)")\"}"
        fi
        # 4. Systeemstatus van de Supervisor: unhealthy, unsupported en openstaande meldingen
        if resol=$(ha_get /resolution/info); then
            ha_repair=$(jq -c '.data | {unhealthy: (.unhealthy // []), unsupported: (.unsupported // []),
                issues: [(.issues // [])[] | {type, context, reference}]}' <<<"$resol" 2>/dev/null)
        else
            ha_repair="{\"error\":\"$(json_escape "Supervisor: $resol")\"}"
        fi
        ha_json="{\"backup\":${ha_backup:-null},\"integrations\":${ha_integr:-null},\"repairs\":${ha_repair:-null}}"
        (umask 077; printf '%s' "$ha_json" >"$ha_cache") 2>/dev/null || true
    fi
fi

# Nieuwe logregels van de Supervisor sinds de vorige keer (/host/logs, /core/logs, /supervisor/logs, /addons/<slug>/logs).
# Hooguit de laatste 500; de eerste keer alleen vastleggen waar we zijn. Kleurcodes eruit.
ha_log_new() {  # ha_log_new <api-pad> <bestand met de laatst geziene regel>
    local out last
    out=$(curl -fsS -m 15 -H "Authorization: Bearer $SUPERVISOR_TOKEN" -H 'Accept: text/plain' -H 'Range: entries=:-499:' \
        "$SUPERVISOR_URL$1" 2>/dev/null | tail -n 500 | sed 's/\x1b\[[0-9;]*m//g') || return 1
    [ -n "$out" ] || return 0
    last=$(cat "$2" 2>/dev/null)
    tail -n 1 <<<"$out" >"$2"
    [ -n "$last" ] || return 0
    awk -v last="$last" '{ l[NR] = $0 } $0 == last { p = NR } END { for (i = p + 1; i <= NR; i++) print l[i] }' <<<"$out"
}

# ---------------------------------------------------------------- Home Assistant: OS en containers
# OS: herstart (boot-tijd), levensduur van de SD-kaart/eMMC, de A/B-bootslots (mislukte OS-update) en USB-apparaten.
# Containers: Core, Supervisor, plugins en add-ons met CPU en RAM (statistieken van de Supervisor), gemeld zoals
# Podman-containers, zodat dezelfde checks en weergave werken. Herstarts: overgangen tussen twee rapporten en de
# watchdog in het Supervisor-log. Alles alleen lezen; statistieken en logs vragen de rol "manager" van de add-on.
usb_json=""
podman_ha=""
if [ "$HA_MODE" = 1 ]; then
    ha_dir="$t_dir/ha"
    mkdir -p "$ha_dir" 2>/dev/null
    hi=${host_info:-}; [ -n "$hi" ] || hi='{}'
    os_info=$(sv /os/info); [ -n "$os_info" ] || os_info='null'
    # Boot-tijd (µs, ms of s sinds 1970) → seconden; verandert hij, dan is HA OS herstart.
    # Met een andere OS-versie erna was het een OS-update (gepland), anders onverwacht.
    boot=$(jq -r '(.data.boot_timestamp // 0) | tonumber | if . > 1e14 then . / 1e6 elif . > 1e11 then . / 1e3 else . end | floor' <<<"$hi" 2>/dev/null)
    os_ver=$(jq -r '.data.version // ""' <<<"$os_info" 2>/dev/null)
    p_boot=""; p_ver=""
    [ -f "$ha_dir/os.state" ] && read -r p_boot p_ver _ <"$ha_dir/os.state"
    if [[ "$boot" =~ ^[0-9]+$ ]] && [ "$boot" -gt 0 ]; then
        if [[ "$p_boot" =~ ^[0-9]+$ ]] && [ $(( boot - p_boot )) -gt 60 ]; then
            planned=false; [ -n "$os_ver" ] && [ -n "$p_ver" ] && [ "$os_ver" != "$p_ver" ] && planned=true
            printf '{"t":%d,"prev":%d,"seen":%d,"planned":%s,"from":"%s","to":"%s"}' "$boot" "$p_boot" "$(date +%s)" "$planned" \
                "$(json_escape "$p_ver")" "$(json_escape "$os_ver")" >"$ha_dir/reboot.json"
        fi
        echo "$boot ${os_ver:--}" >"$ha_dir/os.state"
    fi
    last_reboot=$(cat "$ha_dir/reboot.json" 2>/dev/null); [ -n "$last_reboot" ] || last_reboot=null
    ha_os=$(jq -c --argjson boot "${boot:-0}" --argjson last "$last_reboot" '{boot: $boot, version: (.os.data.version? // null),
            slot: (.os.data.boot? // null), slots: (.os.data.boot_slots? // null), board: (.os.data.board? // null),
            disk_life: (.host.data.disk_life_time? // null), last_reboot: $last}' <<<"{\"os\":$os_info,\"host\":$hi}" 2>/dev/null)
    [ -n "$ha_json" ] && ha_json="${ha_json:0:${#ha_json}-1},\"os\":${ha_os:-null}}"

    # USB-apparaten (Zigbee/Z-Wave-sticks, Bluetooth): naam en vast pad (/dev/serial/by-id/…)
    usb_json=$(sv /hardware/info | jq -c '[.data.devices[]? | select(((.by_id // "") | test("/by-id/")) or .subsystem == "bluetooth")
        | {id: ((.by_id // .dev_path // .name) | split("/") | last), path: (.by_id // .dev_path // ""),
           name: (.attributes.ID_MODEL_FROM_DATABASE // .attributes.ID_MODEL // .name // ""),
           vendor: (.attributes.ID_VENDOR_FROM_DATABASE // .attributes.ID_VENDOR // "")}] | unique_by(.id)' 2>/dev/null)

    # Containers: naam <tab> weergavenaam <tab> status <tab> statistieken (JSON)
    ct_raw=$(
        s=$(sv /core/stats | jq -c '.data // empty' 2>/dev/null)
        printf '%s\t%s\t%s\t%s\n' homeassistant "Home Assistant Core" "$([ "$core_state" = active ] && echo running || echo stopped)" "${s:-null}"
        s=$(sv /supervisor/stats | jq -c '.data // empty' 2>/dev/null)
        printf '%s\t%s\t%s\t%s\n' hassio_supervisor Supervisor running "${s:-null}"
        for p in dns audio cli multicast observer; do
            [ -n "${pl_stats[$p]-}" ] && printf '%s\t%s\t%s\t%s\n' "hassio_$p" "Plugin $p" running "${pl_stats[$p]}"
        done
        [ -n "$addons" ] && jq -r '.data.addons[] | [.slug, .name, .state] | @tsv' <<<"$addons" 2>/dev/null |
            while IFS=$'\t' read -r slug name state; do
                s=""; [ "$state" = started ] && s=$(sv "/addons/$slug/stats" | jq -c '.data // empty' 2>/dev/null)
                printf '%s\t%s\t%s\t%s\n' "addon_$slug" "$name" "$state" "${s:-null}"
            done
    )
    [ -f "$ha_dir/ct.state" ] || : >"$ha_dir/ct.state"
    # Herstarts: de watchdog van de Supervisor meldt "Watchdog found addon X is failed, restarting…"
    wd=$(ha_log_new /supervisor/logs "$ha_dir/sup.mark" | grep -i 'watchdog' | grep -iE 'restart|fail|not running|problem' | tr '[:upper:]' '[:lower:]')
    ct_tsv=$(awk -F'\t' -v OFS='\t' -v now="$(date +%s)" -v wd="$wd" -v state="$ha_dir/ct.state" '
        FILENAME == ARGV[1] { ps[$1] = $2; pt[$1] = $3; pr[$1] = $4; next }
        { run = ($3 == "running" || $3 == "started"); started = pt[$1] + 0; restarts = pr[$1] + 0
          if (run && ps[$1] != "running") started = now                          # (weer) gestart sinds de vorige keer
          # watchdog: per regel; Core = "home assistant" in een regel zonder "addon", anders de naam van de add-on
          n = split(wd, wl, "\n"); hit = 0
          for (i = 1; i <= n; i++) {
              if ($1 == "homeassistant") { if (index(wl[i], "home assistant") && !index(wl[i], "addon")) hit = 1 }
              else if ($1 ~ /^addon_/ && index(wl[i], tolower($2))) hit = 1
          }
          if (hit) { restarts++; started = now }
          print $1, (run ? "running" : $3), started, restarts > state
          print $1, $2, (run ? "running" : $3), started, restarts, $4 }' "$ha_dir/ct.state" <(printf '%s\n' "$ct_raw" | grep .) 2>/dev/null)
    [ -f "$ha_dir/ct.state" ] || : >"$ha_dir/ct.state"
    ct_json=$(jq -R -s -c '[split("\n")[] | select(length > 0) | split("\t") | (.[5] | fromjson? // null) as $s
        | {name: .[0], image: .[1], state: (if .[2] == "running" then "running" else "exited" end),
           exit_code: (if .[2] == "error" then 1 else 0 end), started: (.[3] | tonumber), restarts: (.[4] | tonumber)}
        + (if $s then {cpu: $s.cpu_percent, mem: $s.memory_usage, mem_limit: $s.memory_limit, net_in: $s.network_rx,
                       net_out: $s.network_tx, blk_in: $s.blk_read, blk_out: $s.blk_write} else {} end)]' <<<"$ct_tsv" 2>/dev/null)
    [ -n "$ct_json" ] && [ "$ct_json" != "[]" ] && podman_ha="{\"version\":\"Home Assistant\",\"containers\":$ct_json}"
fi

# ---------------------------------------------------------------- Services: diepere controle
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
        c_t0=$(date +%s%N)
        (cd / && env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/ LANG=C.UTF-8 \
            "${c_run[@]}" timeout -k 2 "$c_timeout" /bin/bash -c "$c_cmd") </dev/null >"$c_dir/$c_id.out" 2>"$c_dir/$c_id.err"
        c_rc=$?
        c_ms=$(( ($(date +%s%N) - c_t0) / 1000000 ))
        printf '{"rc":%d,"out":"%s","err":"%s","ms":%d,"as":"%s","at":%d}' "$c_rc" \
            "$(head -c 4096 "$c_dir/$c_id.out" | json_text)" "$(head -c 500 "$c_dir/$c_id.err" | json_text)" \
            "$c_ms" "$c_runas" "$(date +%s)" >"$c_state"
        rm -f "$c_dir/$c_id.out" "$c_dir/$c_id.err"
        cmd_results+="\"$c_id\":$(cat "$c_state"),"
    done <"$t_cache"
fi

# ---------------------------------------------------------------- Logbestanden doorzoeken
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

# ---------------------------------------------------------------- Logins, sudo en su
# Als RzMon erom vraagt (regel "0 <tab> auth <tab> 1"): de nieuwe regels van het auth-log sinds de vorige
# keer (journal, anders /var/log/secure of /var/log/auth.log). Geslaagde logins (SSH, console), sudo en su
# gaan als losse gebeurtenissen naar RzMon; mislukte pogingen worden per soort/IP/gebruiker opgeteld.
# Wachtwoorden in sudo-commando's (bv. mysql -pGeheim, password=…) worden hier al gemaskeerd.
auth_json=""
mask_secrets() {
    sed -E 's/((password|passwd|pass|pwd|token|secret|apikey|api_key)[=:])[^ ]+/\1••••/Ig;
            s/(--password[= ])[^ ]+/\1••••/g; s/((mysql|mariadb|mysqldump|mysqladmin)( [^|;&]*)? -p)[^ ]+/\1••••/g'
}
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

    a_events=""; n_events=0
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
    re_ssh_ok='^Accepted ([^ ]+) for ([^ ]+) from ([^ ]+) port'
    re_ssh_fail='^Failed ([^ ]+) for ([^ ]+) from ([^ ]+) port'
    re_ssh_invalid='^Invalid user ([^ ]*) from ([^ ]+)'
    re_login_ok='^pam_unix\(login:session\): session opened for user ([^ (]+)'
    re_login_fail='^pam_unix\(login:auth\): authentication failure;.* tty=([^ ]*).* user=([^ ]+)'
    re_sudo='^ *([^ ]+) : (.*)TTY=([^ ]+) ; PWD=(.*) ; USER=([^ ]+) ; (.* ; )?COMMAND=(.*)$'
    re_su_ok='^pam_unix\(su(-l)?:session\): session opened for user ([^ (]+)(\(uid=[0-9]+\))? by ([^ (]*)'
    re_su_fail='^pam_unix\(su(-l)?:auth\): authentication failure;.* logname=([^ ]*) .* tty=([^ ]*) .* user=([^ ]+)'
    while IFS=$'\t' read -r a_t a_id a_msg; do
        [ -n "$a_msg" ] || continue
        case "$a_id" in
            sshd|sshd-session)
                if [[ "$a_msg" =~ $re_ssh_ok ]]; then
                    a_event "\"t\":$a_t,\"k\":\"ssh\",\"u\":\"$(json_escape "${BASH_REMATCH[2]}")\",\"ip\":\"$(json_escape "${BASH_REMATCH[3]}")\",\"m\":\"$(json_escape "${BASH_REMATCH[1]}")\""
                elif [[ "$a_msg" =~ $re_ssh_fail ]] && [ "${BASH_REMATCH[2]}" != invalid ]; then
                    a_fail "ssh|${BASH_REMATCH[3]}" "$a_t" "${BASH_REMATCH[2]}" "\"ip\":\"$(json_escape "${BASH_REMATCH[3]}")\""
                elif [[ "$a_msg" =~ $re_ssh_invalid ]]; then
                    a_fail "ssh|${BASH_REMATCH[2]}" "$a_t" "${BASH_REMATCH[1]:-?}" "\"ip\":\"$(json_escape "${BASH_REMATCH[2]}")\""
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
    done <<<"$a_lines"
    a_fails=""
    for key in "${!f_n[@]}"; do
        users=""; for u in ${f_users[$key]:-}; do users+="\"$(json_escape "$u")\","; done
        a_fails+="{\"k\":\"${key%%|*}\",\"n\":${f_n[$key]},\"t\":${f_t[$key]},\"users\":[${users%,}]${f_extra[$key]:+,${f_extra[$key]}}},"
    done
    auth_json="\"auth\":{\"events\":[${a_events%,}],\"fails\":[${a_fails%,}]},"
fi

# ---------------------------------------------------------------- IDS (intrusion detection)
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
        setsid bash "$out.job" </dev/null >/dev/null 2>&1 &
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
        cmd=$(tr '\0' ' ' <"$p/cmdline" 2>/dev/null | head -c 300 | json_text)
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
                if kill -KILL "$k_pid" 2>/dev/null; then k_res=ok; else k_res=fout; fi
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
[ -n "$ids_mode" ] && [ -r "${t_cache-}" ] && decoy_port=$(awk -F'\t' '$1 == "0" && $2 == "decoy" && $3 ~ /^[0-9]+$/ && $3 >= 1 && $3 <= 65535 { print $3; exit }' "$t_cache")
if [ -n "$decoy_port" ]; then
    if command -v midclt >/dev/null 2>&1 || ! command -v nft >/dev/null 2>&1; then decoy_port=""
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
    if command -v midclt >/dev/null 2>&1; then
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
    read -r e_ws _ _ _ e_we <"$edr_dir/watch.state" 2>/dev/null
    [ "$e_ws" = active ] && [ "$e_we" != - ] && e_watch=on
    e_drop=$(cat "$edr_dir/edr.drop" 2>/dev/null); [[ "$e_drop" =~ ^[0-9]+$ ]] || e_drop=0
    # Wachtrij: wat er nog lag (niet aangekomen) plus de nieuwe gebeurtenissen
    e_send="$edr_dir/edr.sending"
    if [ -s "$edr_spool" ]; then
        mv -f "$edr_spool" "$edr_spool.tmp" 2>/dev/null && { cat "$edr_spool.tmp" >>"$e_send"; rm -f "$edr_spool.tmp"; }
    fi
    e_ev=""
    if [ -s "$e_send" ]; then
        e_ev=$(head -n 500 "$e_send" | grep -E '^\{.*\}$' | tr '\n' ',' | sed 's/,$//')
    fi
    edr_json="{\"ev\":[$e_ev],\"info\":{\"audit\":\"$e_audit\",\"file\":\"$e_file\",\"ctr\":\"$e_ctr\",\"fam\":\"$e_fam\",\"epel\":$e_epel,\"watch\":\"$e_watch\",\"systemd\":$e_sys,\"drop\":$e_drop,\"queue\":$(wc -l <"$e_send" 2>/dev/null || echo 0)}}"
elif [ "$edr_want" = 0 ] && [ "$(id -u)" = 0 ] && [ "$HA_MODE" = 0 ]; then
    # Uit (in RzMon of met EDR=0): regels weg en de wachtrij leeg
    edr_rules off >/dev/null 2>&1
    rm -f "$edr_spool" "$edr_dir/edr.sending" 2>/dev/null
fi

# ---------------------------------------------------------------- Isoleren en gebruiker uitzetten (agent 1.57.0)
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

# ---------------------------------------------------------------- Podman: containers, pods en opslag
# Per context: versie, containers (status, health, herstarts, grootte, volumes), pods en opslag.
# Volumegroottes (du) worden een uur bewaard: die kunnen groot zijn en veranderen langzaam.
podman_json=""
if [ ${#podman_ctx[@]} -gt 0 ]; then
    pod_stats_b=$(podman_stats)
    vol_cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    mkdir -p "$vol_cache_dir" 2>/dev/null && [ -w "$vol_cache_dir" ] || vol_cache_dir="${TMPDIR:-/tmp}"
    vol_cache="$vol_cache_dir/volumes.cache"
    now_s=$(date +%s)
    podman_raw=$(
        printf '%s\n' "$pod_stats_a" | sed 's/^S|/A|/'
        printf '%s\n' "$pod_stats_b" | sed 's/^S|/B|/'
        for ctx in "${podman_ctx[@]}"; do
            if ! ver=$(pm "$ctx" version --format '{{.Client.Version}}' 2>/dev/null); then
                # Podman zet waarschuwingen vóór de echte fout; die echte fout is wat telt
                out=$(pm "$ctx" version 2>&1); rc=$?
                err=$(grep -m1 -iE 'error|denied|not permitted|read-only' <<<"$out" | sed 's/^time="[^"]*" level=[a-z]* msg="//; s/"$//')
                home=$(getent passwd "$ctx" | cut -d: -f6)
                if [ "$ctx" != root ] && [ -n "${INVOCATION_ID-}" ] && [ -n "$home" ] && ! [ -w "$home" ]; then
                    # systemd-service met ProtectHome=read-only (agent geïnstalleerd vóór podman)
                    err="home-map is alleen-lezen voor de agent; los op met: sudo rzmon-agent-setup --update${err:+ ($err)}"
                elif [ "$rc" = 124 ]; then
                    err="podman gaf binnen 60 s geen antwoord"
                elif [ -z "$err" ]; then
                    err=$(grep -v '^[[:space:]]*$' <<<"$out" | tail -1 | sed 's/^time="[^"]*" level=[a-z]* msg="//; s/"$//')
                    err="${err:-podman stopte met code $rc}"
                fi
                printf 'E|%s|%s\n' "$ctx" "$(tr '|' ' ' <<<"$err" | cut -c1-200)"
                continue
            fi
            printf 'V|%s|%s\n' "$ctx" "$ver"
            pm "$ctx" ps -a --pod --size --no-trunc --format '{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.ExitCode}}|{{.Restarts}}|{{.PodName}}|{{.IsInfra}}|{{.StartedAt}}|{{.Size}}' 2>/dev/null \
                | sed "s/^/C|$ctx|/"
            pm "$ctx" pod ps --format '{{.Name}}|{{.Status}}|{{.NumberOfContainers}}' 2>/dev/null | sed "s/^/P|$ctx|/"
            pm "$ctx" system df --format '{{.Type}}|{{.RawSize}}|{{.RawReclaimable}}|{{.Total}}|{{.Active}}' 2>/dev/null | sed "s/^/D|$ctx|/"
            ids=$(pm "$ctx" ps -aq --no-trunc 2>/dev/null)
            if [ -n "$ids" ]; then
                # shellcheck disable=SC2086
                pm "$ctx" inspect --format '{{.ID}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}' $ids 2>/dev/null \
                    | sed "s/^/M|$ctx|/"
            fi
        done
    )
    # Volumegroottes: uit de cache als die minder dan een uur oud is
    vols=$(grep '^M|' <<<"$podman_raw" | cut -d'|' -f4 | tr ';' '\n' | grep -v '^$' | sort -u)
    if [ -n "$vols" ]; then
        if [ -r "$vol_cache" ] && [ $(( now_s - $(head -1 "$vol_cache") )) -lt 3600 ]; then
            vol_sizes=$(tail -n +2 "$vol_cache")
        else
            vol_sizes=$(while IFS= read -r v; do
                printf 'Z|%s|%s\n' "$v" "$(du -sb "$v" 2>/dev/null | cut -f1)"
            done <<<"$vols")
            (umask 077; { echo "$now_s"; echo "$vol_sizes"; } >"$vol_cache") 2>/dev/null || true
        fi
        podman_raw+=$'\n'"$vol_sizes"
    fi
    podman_json=$(awk -F'|' -v now="$now_s" '
        function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); return s }
        function num(v) { return (v ~ /^[0-9]+(\.[0-9]+)?$/) ? v : 0 }
        # "11.8kB (virtual 8.12MB)" → bytes (podman gebruikt SI: kB = 1000)
        function bytes(s,   n, u) {
            if (!match(s, /^[0-9.]+[a-zA-Z]*/)) return 0
            s = substr(s, 1, RLENGTH); n = s + 0; u = s; sub(/^[0-9.]+/, "", u); u = tolower(u)
            return n * (u == "kb" ? 1e3 : u == "mb" ? 1e6 : u == "gb" ? 1e9 : u == "tb" ? 1e12 : 1)
        }
        $1 == "A" { a_cpu[$3] = $4; a_sys[$3] = $5 }
        $1 == "B" { b_cpu[$3] = $4; b_sys[$3] = $5; mem[$3] = $6; lim[$3] = $7; bi[$3] = $9; bo[$3] = $10; pids[$3] = $11
                    split($8, nio, " / "); ni[$3] = sprintf("%.0f", bytes(nio[1])); no[$3] = sprintf("%.0f", bytes(nio[2])) }
        $1 == "V" { if (version == "") version = $3; ctxs = ctxs (ctxs ? "," : "") "\"" esc($2) "\"" }
        $1 == "E" { errs = errs (errs ? "; " : "") $2 ": " $3 }
        $1 == "C" { n++; c_ctx[n] = $2; c_id[n] = $3; c_line[n] = $0 }
        $1 == "P" { pods = pods sprintf("{\"name\":\"%s\",\"user\":\"%s\",\"status\":\"%s\",\"containers\":%d},", esc($3), esc($2), esc($4), num($5)) }
        $1 == "D" { t = tolower($3); df_size[t] += num($4); reclaim += num($5); if (t == "images") images += num($6) }
        $1 == "M" { cvol[$3] = $4 }
        $1 == "Z" { vsize[$2] = num($3) }
        END {
            out = ""
            for (i = 1; i <= n; i++) {
                split(c_line[i], f, "|")
                if (f[11] == "true") continue            # infra-container van een pod
                id = f[3]; health = ""
                if (f[7] ~ /\(healthy\)/) health = "healthy"; else if (f[7] ~ /\(unhealthy\)/) health = "unhealthy"; else if (f[7] ~ /\(starting\)/) health = "starting"
                cpu = "null"
                if ((id in a_cpu) && (id in b_cpu) && b_sys[id] > a_sys[id])
                    cpu = sprintf("%.2f", (b_cpu[id] - a_cpu[id]) / (b_sys[id] - a_sys[id]) * 100)
                vb = 0; nv = split(cvol[id], vl, ";")
                for (j = 1; j <= nv; j++) if (vl[j] != "") vb += vsize[vl[j]]
                out = out sprintf("{\"name\":\"%s\",\"user\":\"%s\",\"pod\":\"%s\",\"image\":\"%s\",\"state\":\"%s\",\"status\":\"%s\",\"health\":\"%s\",\"exit_code\":%d,\"restarts\":%d,\"started\":%.0f,\"cpu\":%s,\"mem\":%s,\"mem_limit\":%s,\"net_in\":%s,\"net_out\":%s,\"blk_in\":%s,\"blk_out\":%s,\"pids\":%s,\"size_rw\":%.0f,\"volumes\":%.0f},",
                    esc(f[4]), esc(f[2]), esc(f[10]), esc(f[5]), esc(f[6]), esc(f[7]), health, num(f[8]), num(f[9]), num(f[12]), cpu,
                    (id in mem) ? num(mem[id]) : "null", (id in lim) ? num(lim[id]) : "null", (id in ni) ? num(ni[id]) : "null",
                    (id in no) ? num(no[id]) : "null", (id in bi) ? num(bi[id]) : "null", (id in bo) ? num(bo[id]) : "null",
                    (id in pids) ? num(pids[id]) : "null", bytes(f[13]), vb)
            }
            sub(/,$/, "", out); sub(/,$/, "", pods)
            printf "{\"version\":\"%s\",\"error\":\"%s\",\"users\":[%s],\"images\":%d,\"images_size\":%.0f,\"containers_size\":%.0f,\"volumes_size\":%.0f,\"reclaimable\":%.0f,\"pods\":[%s],\"containers\":[%s]}",
                esc(version), esc(errs), ctxs, images, df_size["images"], df_size["containers"], df_size["local volumes"], reclaim, pods, out
        }' <<<"$podman_raw")
fi

# ---------------------------------------------------------------- Proxmox VE (agent 1.61.0)
# Alleen op een Proxmox-host, als root: guests (VM's en containers), opslag, ZFS-pools, de cluster, back-ups per guest en de
# back-uptaken. pvesh geeft JSON; dat gaat ongewijzigd mee (RzMon leest het), dus jq is niet nodig.
# Guests en opslag elke 2 minuten, back-ups en taken elk kwartier. PVE=0 in de instellingen zet het uit.
pve_json=""
if [ "$HA_MODE" = 0 ] && [ "${PVE:-1}" != 0 ] && command -v pvesh >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
    pve_dir="$t_dir/pve"
    mkdir -p "$pve_dir" 2>/dev/null
    pve_node=$(hostname -s)   # Proxmox noemt de node naar de korte hostnaam
    pj() {  # pj <tijd> <pad> [opties…]: JSON van pvesh, of null
        local to=$1 o; shift
        o=$(timeout "$to" pvesh get "$@" --output-format json 2>/dev/null)
        case "$o" in '['*|'{'*) printf '%s' "$o" ;; *) printf 'null' ;; esac
    }
    if ! due_cached "$pve_dir/live" 120; then
        # Uit de configuratiebestanden: automatisch starten, guest agent aan, sjabloon (alleen het deel vóór [snapshots])
        pve_conf=$(for f in /etc/pve/qemu-server/*.conf /etc/pve/lxc/*.conf; do
            [ -f "$f" ] || continue
            awk -v id="$(basename "$f" .conf)" -v t="$([[ "$f" == */lxc/* ]] && echo lxc || echo qemu)" '
                /^\[/ { exit } /^onboot: *1/ { ob = 1 } /^agent: *(1|enabled=1)/ { ag = 1 } /^template: *1/ { tp = 1 }
                END { if (id ~ /^[0-9]+$/) printf "[\"%s\",%s,%d,%d,%d],", t, id, ob, ag, tp }' "$f"
        done)
        # ZFS-pools: gezondheid, vulling en de laatste scrub
        pve_zfs=""
        if command -v zpool >/dev/null 2>&1; then
            while IFS=$'\t' read -r z_name z_health z_size z_alloc z_cap; do
                [ -n "$z_name" ] || continue
                z_scan=$(timeout 10 zpool status "$z_name" 2>/dev/null | sed -n 's/^ *scan: *//p' | head -1)
                pve_zfs+="{\"name\":\"$(json_escape "$z_name")\",\"health\":\"$(json_escape "$z_health")\",\"size\":${z_size:-0},\"alloc\":${z_alloc:-0},\"cap\":${z_cap%\%},\"scan\":\"$(json_escape "$z_scan")\"},"
            done < <(timeout 10 zpool list -Hp -o name,health,size,alloc,capacity 2>/dev/null)
        fi
        printf '"node":"%s","version":"%s","qemu":%s,"lxc":%s,"storage":%s,"cluster":%s,"conf":[%s],"zfs":[%s],' \
            "$(json_escape "$pve_node")" "$(json_escape "$(pveversion 2>/dev/null | head -1)")" \
            "$(pj 20 "/nodes/$pve_node/qemu")" "$(pj 20 "/nodes/$pve_node/lxc")" "$(pj 30 "/nodes/$pve_node/storage")" \
            "$(pj 15 /cluster/status)" "${pve_conf%,}" "${pve_zfs%,}" >"$pve_dir/live.tmp" && mv -f "$pve_dir/live.tmp" "$pve_dir/live"
    fi
    if ! due_cached "$pve_dir/slow" 900; then
        # Back-ups per guest: de nieuwste per VMID uit elke opslag met back-ups (vzdump-bestanden en Proxmox Backup Server)
        pve_bk=$(for st in $(timeout 20 pvesm status --content backup 2>/dev/null | awk 'NR > 1 && $3 == "active" { print $1 }'); do
            [[ "$st" =~ ^[A-Za-z0-9._-]+$ ]] || continue
            timeout 60 pvesm list "$st" --content backup 2>/dev/null | awk -v st="$st" 'NR > 1 { print st "\t" $1 "\t" $NF }'
        done | awk -F'\t' '
            {
                id = $3; d = ""
                # (geen {4} in de patronen: mawk op Debian kent dat niet altijd)
                if (match($2, /[0-9][0-9][0-9][0-9]_[0-9][0-9]_[0-9][0-9]-[0-9][0-9]_[0-9][0-9]_[0-9][0-9]/)) { d = substr($2, RSTART, RLENGTH); gsub(/_/, "-", d); d = substr(d, 1, 10) " " substr(d, 12, 2) ":" substr(d, 15, 2) ":" substr(d, 18, 2) }
                else if (match($2, /[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z/)) { d = substr($2, RSTART, 10) " " substr($2, RSTART + 11, 8) "Z" }
                if (id !~ /^[0-9]+$/ || d == "") next
                n[id]++; if (d > last[id]) { last[id] = d; where[id] = $1 }
            }
            END { for (id in n) printf "\"%s\":[\"%s\",%d,\"%s\"],", id, last[id], n[id], where[id] }')
        printf '"backups":{%s},"jobs":%s,"tasks":%s,' "${pve_bk%,}" "$(pj 15 /cluster/backup)" \
            "$(pj 20 "/nodes/$pve_node/tasks" --typefilter vzdump --limit 30)" >"$pve_dir/slow.tmp" && mv -f "$pve_dir/slow.tmp" "$pve_dir/slow"
        # Guest agent: antwoordt hij (alleen draaiende VM's met de agent aan)?
        pve_ga=""
        for f in /etc/pve/qemu-server/*.conf; do
            [ -f "$f" ] || continue
            id=$(basename "$f" .conf)
            awk '/^\[/ { exit } /^agent: *(1|enabled=1)/ { f = 1 } END { exit !f }' "$f" || continue
            [ -e "/var/run/qemu-server/$id.pid" ] || continue
            if timeout 4 qm agent "$id" ping >/dev/null 2>&1; then pve_ga+="\"$id\":1,"; else pve_ga+="\"$id\":0,"; fi
        done
        printf '"gagent":{%s},' "${pve_ga%,}" >>"$pve_dir/slow"
    fi
    pve_json="{$(cat "$pve_dir/live" "$pve_dir/slow" 2>/dev/null)\"at\":$(date +%s)}"
fi

# ---------------------------------------------------------------- Certificaten
# Per bron: onderwerp (CN), uitgever, vervaldatum (epoch) en of het certificaat vertrouwd wordt.
# RzMon rekent zelf het aantal dagen uit, dus een uur oude gegevens zijn geen probleem.
cert_line() {  # bron cn uitgever not_after verify fout [aantal in de keten, eerste vervaldatum in de keten, cn daarvan]
    printf '{"source":"%s","cn":"%s","issuer":"%s","not_after":%s,"verify":%s,"error":"%s","chain_n":%s,"chain_min":%s,"chain_cn":"%s"},' \
        "$(json_escape "$1")" "$(json_escape "$2")" "$(json_escape "$3")" "${4:-null}" \
        "$( [ -n "$5" ] && printf '"%s"' "$(json_escape "$5")" || echo null)" "$(json_escape "$6")" "${7:-0}" "${8:-null}" "$(json_escape "${9-}")"
}
# Alle certificaten in een PEM-tekst (stdin; fullchain of de keten van een server, 1.59.0): chain_n, en welk
# certificaat het eerst verloopt (chain_min, chain_cn). Zo valt ook een verlopen tussencertificaat op.
chain_info() {
    local tmp blk na
    chain_n=0; chain_min=""; chain_cn=""
    tmp=$(mktemp -d 2>/dev/null) || { cat >/dev/null; return 0; }
    awk -v d="$tmp" '/-----BEGIN CERTIFICATE-----/ { n++; f = d "/c" n } f { print > f } /-----END CERTIFICATE-----/ { if (f) close(f); f = ""; if (n >= 30) exit }'
    for blk in "$tmp"/c*; do
        [ -f "$blk" ] || continue
        na=$(openssl x509 -noout -enddate <"$blk" 2>/dev/null | sed -n 's/^notAfter=//p')
        na=$(date -d "$na" +%s 2>/dev/null) || continue
        chain_n=$((chain_n + 1))
        if [ -z "$chain_min" ] || [ "$na" -lt "$chain_min" ]; then
            chain_min=$na
            chain_cn=$(openssl x509 -noout -subject -nameopt RFC2253 <"$blk" 2>/dev/null | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
        fi
    done
    rm -rf "$tmp"
}
# Leest een PEM-certificaat van stdin en zet cn, issuer en not_after
parse_cert() {
    local info
    info=$(openssl x509 -noout -enddate -subject -issuer -nameopt RFC2253 2>/dev/null) || return 1
    not_after=$(date -d "$(sed -n 's/^notAfter=//p' <<<"$info")" +%s 2>/dev/null) || return 1
    cn=$(sed -n 's/^subject=//p' <<<"$info" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
    issuer=$(sed -n 's/^issuer=//p' <<<"$info" | grep -o 'O=[^,]*' | head -1 | cut -c3-)
    [ -n "$issuer" ] || issuer=$(sed -n 's/^issuer=//p' <<<"$info" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
}
collect_certs() {
    local entry f host port out code rc
    for entry in $cert_list; do
        if [[ "$entry" == /* ]]; then
            # Bestand(en); een wildcard zonder treffers is geen fout (bv. geen Let's Encrypt)
            if [[ "$entry" == *[*?[]* ]]; then
                compgen -G "$entry" >/dev/null || continue
            fi
            for f in $entry; do
                if [ ! -r "$f" ]; then
                    cert_line "$f" "" "" "" "" "bestand niet gevonden of niet leesbaar"
                elif parse_cert <"$f"; then
                    chain_info <"$f"
                    cert_line "$f" "$cn" "$issuer" "$not_after" "" "" "$chain_n" "$chain_min" "$chain_cn"
                else
                    cert_line "$f" "" "" "" "" "geen geldig certificaat (PEM) in dit bestand"
                fi
            done
        else
            # host:poort (zonder poort: 443); [IPv6]:poort mag ook
            entry=${entry#*://}; entry=${entry%%/*}
            if [[ "$entry" == \[*\]:* ]]; then host=${entry%]:*}; host=${host#[}; port=${entry##*]:}
            elif [[ "$entry" == *:* && "$entry" != *:*:* ]]; then host=${entry%:*}; port=${entry##*:}
            else host=${entry#[}; host=${host%]}; port=443; fi
            local src="$host:$port"
            [[ "$host" == *:* ]] && src="[$host]:$port"
            out=$(timeout 15 openssl s_client -connect "$src" -servername "$host" -verify_hostname "$host" -showcerts </dev/null 2>&1)
            rc=$?
            if parse_cert <<<"$out"; then
                code=$(sed -n 's/^ *Verify return code: \([0-9]*\) (\(.*\))/\1 \2/p' <<<"$out" | tail -1)
                [ "${code%% *}" = 0 ] && code=ok || code=${code#* }
                chain_info <<<"$out"
                cert_line "$src" "$cn" "$issuer" "$not_after" "${code:-onbekend}" "" "$chain_n" "$chain_min" "$chain_cn"
            else
                case "$out" in
                    *"Connection refused"*) out="poort dicht (verbinding geweigerd)" ;;
                    *"not known"*|*"No address"*|*"nodename nor servname"*) out="naam $host onbekend (DNS)" ;;
                    *"No route"*|*"unreachable"*) out="server onbereikbaar" ;;
                    *"wrong version number"*|*"packet length too long"*) out="op deze poort draait geen TLS" ;;
                    *) [ "$rc" = 124 ] && out="geen antwoord binnen 15 seconden" || out="${out%%$'\n'*}"; out=${out:0:120} ;;
                esac
                cert_line "$src" "" "" "" "" "geen TLS-verbinding: $out"
            fi
        fi
    done
}
certs=""
# Te controleren: CERTS van deze server, plus wat je in RzMon gekozen hebt (regels "0 <tab> cert <tab> bron", 1.59.0)
cert_list="$CERTS"
[ -r "${t_cache-}" ] && cert_list="$cert_list $(awk -F'\t' '$1 == "0" && $2 == "cert" && $3 !~ /[[:space:]]/ { print $3 }' "$t_cache" | head -200 | tr '\n' ' ')"
cert_list=$(tr -s ' ' <<<"$cert_list"); cert_list=${cert_list# }; cert_list=${cert_list% }
if [ -n "$cert_list" ]; then
    if ! command -v openssl >/dev/null 2>&1; then
        certs=$(cert_line "openssl" "" "" "" "" "openssl ontbreekt op deze server (installeer het pakket openssl)")
    else
        # Vaste cachemap (systemd: CacheDirectory); /tmp is bij systemd per run leeg (PrivateTmp)
        cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
        mkdir -p "$cache_dir" 2>/dev/null && [ -w "$cache_dir" ] || cache_dir="${TMPDIR:-/tmp}"
        cache="$cache_dir/certs.cache"
        key=$(printf '%s' "$cert_list" | cksum | cut -d' ' -f1)
        # Cache: regel 1 = sleutel van CERTS, regel 2 = tijdstip, regel 3 = resultaat
        if [ "$TEST" = 0 ] && [ -r "$cache" ] && [ "$(sed -n 1p "$cache")" = "$key" ] \
            && [ $(( $(date +%s) - $(sed -n 2p "$cache") )) -lt "$CERT_INTERVAL" ]; then
            certs=$(sed -n 3p "$cache")
        else
            certs=$(collect_certs)
            (umask 077; printf '%s\n%s\n%s\n' "$key" "$(date +%s)" "$certs" >"$cache") 2>/dev/null || true
        fi
    fi
fi

# ---------------------------------------------------------------- Certificaten zoeken (1.59.0)
# Bestanden met een certificaat in de mappen van CERT_DIRS (plus de mappen die je in RzMon opgeeft), om in RzMon
# uit te kiezen. Eén keer per dag, of meteen als RzMon erom vraagt (regel "0 <tab> certscan <tab> teller").
# Overgeslagen: de CA-lijsten van het systeem en van programmeertalen (Python, Ruby), testmappen, CA-bundels (meer dan
# 20 certificaten) en sleutels zonder certificaat.
cert_found=""
if [ "$TEST" != 1 ] && command -v openssl >/dev/null 2>&1; then
    cs_dirs="$CERT_DIRS"
    [ -r "${t_cache-}" ] && cs_dirs="$cs_dirs $(awk -F'\t' '$1 == "0" && $2 == "certdirs" { print $3; exit }' "$t_cache")"
    cs_req=$( [ -r "${t_cache-}" ] && awk -F'\t' '$1 == "0" && $2 == "certscan" { print $3; exit }' "$t_cache")
    cs_cache="${cache_dir:-${CACHE_DIRECTORY:-/var/cache/rzmon-agent}}/certfound.cache"
    mkdir -p "${cs_cache%/*}" 2>/dev/null
    cs_key=$(printf '%s|%s' "$cs_dirs" "$cs_req" | cksum | cut -d' ' -f1)
    if [ -r "$cs_cache" ] && [ "$(sed -n 1p "$cs_cache")" = "$cs_key" ] && [ $(( $(date +%s) - $(sed -n 2p "$cs_cache") )) -lt 86400 ]; then
        cert_found=$(sed -n 3p "$cs_cache")
    elif [ -n "${cs_dirs// /}" ]; then
        cs_ex=()
        for d in $cs_dirs; do [[ "$d" == /* && "$d" != *..* && -d "$d" ]] && cs_ex+=("$d"); done
        if [ "${#cs_ex[@]}" -gt 0 ]; then
            cs_n=0
            while IFS= read -r -d '' f; do
                [ -f "$f" ] && [ -r "$f" ] || continue
                grep -q -- '-----BEGIN CERTIFICATE-----' "$f" 2>/dev/null || continue
                [ "$(grep -c -- '-----BEGIN CERTIFICATE-----' "$f")" -le 20 ] || continue   # CA-bundel
                parse_cert <"$f" || continue
                chain_info <"$f"
                cert_found+=$(printf '{"path":"%s","cn":"%s","issuer":"%s","not_after":%s,"n":%s,"chain_min":%s},' "$(json_escape "$f")" "$(json_escape "$cn")" \
                    "$(json_escape "$issuer")" "$not_after" "$chain_n" "${chain_min:-null}")
                cs_n=$((cs_n + 1)); [ "$cs_n" -ge 300 ] && break
            done < <(timeout 120 find "${cs_ex[@]}" -xdev -maxdepth 8 \( -path /etc/ssl/certs -o -path /etc/pki/ca-trust -o -path /etc/letsencrypt/archive \
                -o -path '*/ca-certificates' -o -path /etc/pki/tls/certs/ca-bundle.crt -o -name node_modules -o -name .git -o -name site-packages \
                -o -name dist-packages -o -name ssl_certs -o -name certifi -o -iname 'cacert.pem' -o -iname '*ca-bundle*' -o -name test -o -name tests \) -prune \
                -o \( -type f -o -type l \) \( -iname '*.crt' -o -iname '*.pem' -o -iname '*.cer' -o -iname '*.cert' \) -size -200k -print0 2>/dev/null)
        fi
        (umask 077; printf '%s\n%s\n%s\n' "$cs_key" "$(date +%s)" "$cert_found" >"$cs_cache") 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------- Processen (1.59.0)
# Proces-checks uit RzMon: "id <tab> proc <tab> name|pattern <tab> naam of patroon (base64) <tab> gebruiker".
# Per check: aantal, PID's, CPU (%), geheugen (kB) en wanneer het oudste proces startte (om een herstart te zien).
# Plus een lijst van wat er draait (zonder kernelprocessen), om in RzMon uit te kiezen.
procs_json=""; proc_list=""
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ]; then
    # LC_ALL=C (1.59.2): punt als decimaalteken en alleen ASCII (andere tekens als ?), zodat de JSON altijd klopt
    ps_raw=$(LC_ALL=C ps -eo pid=,ppid=,user:32=,etimes=,pcpu=,rss=,comm=,args= 2>/dev/null)
    if [ -n "$ps_raw" ]; then
        now_ts=$(date +%s)
        proc_list=$(LC_ALL=C awk -v self=$$ '
            function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); gsub(/\t/, " ", s); return s }
            $2 == 2 || $1 == 2 || $1 == self || $2 == self { next }
            { a = ""; for (i = 8; i <= NF; i++) a = a (i > 8 ? " " : "") $i
              if (a ~ /^\[/) next
              n[$7]++; if (!($7 in ex)) { ex[$7] = substr(a, 1, 120); us[$7] = $3 } }
            END { c = 0; for (k in n) { if (c++ >= 200) break; printf "[\"%s\",%d,\"%s\",\"%s\"],", esc(k), n[k], esc(us[k]), esc(ex[k]) } }' <<<"$ps_raw")
        if [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tproc\t' "$t_cache"; then
            while IFS=$'\t' read -r p_id p_type p_mode p_val p_user; do
                [ "$p_type" = proc ] && [[ "$p_id" =~ ^[0-9]+$ ]] || continue
                p_val=$(printf '%s' "$p_val" | base64 -d 2>/dev/null) || continue
                [ -n "$p_val" ] || continue
                # Het patroon via de omgeving: awk -v zou de backslashes in een regex opeten
                procs_json+=$(P_VAL="$p_val" LC_ALL=C awk -v id="$p_id" -v mode="$p_mode" -v usr="$p_user" -v self=$$ -v now="$now_ts" '
                    BEGIN { val = ENVIRON["P_VAL"] }
                    $1 == self || $2 == self || $2 == 2 { next }
                    { a = ""; for (i = 8; i <= NF; i++) a = a (i > 8 ? " " : "") $i
                      if (a ~ /^\[/) next
                      if (usr != "" && $3 != usr) next
                      if (mode == "pattern") { if (a !~ val) next }
                      else { b = $8; sub(/.*\//, "", b); sub(/:$/, "", b); if ($7 != val && b != val && substr(val, 1, 15) != $7) next }
                      n++; pids = pids (pids == "" ? "" : " ") $1; cpu += $5; rss += $6; if ($4 > age) age = $4 }
                    END { printf "\"%s\":{\"n\":%d,\"pids\":\"%s\",\"cpu\":%.1f,\"rss\":%d,\"start\":%s},", id, n, substr(pids, 1, 200), cpu, rss, n ? now - age : "null" }' <<<"$ps_raw")
            done <"$t_cache"
        fi
    fi
fi

# ---------------------------------------------------------------- Software (Kwetsbaarheden in RzMon)
# Geïnstalleerde pakketten met de distributie: RzMon zoekt er de bekende kwetsbaarheden (CVE's) bij.
# De hele lijst één keer per dag, of eerder als de pakketdatabase verandert; anders alleen het controlegetal.
pkgs_json=""; pk_hash=""; pk_mgr=""
pk_dir="$t_dir/pkgs"
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ] && mkdir -p "$pk_dir" 2>/dev/null && [ -w "$pk_dir" ]; then
    pk_db=""
    if command -v dpkg-query >/dev/null 2>&1 && [ -f /var/lib/dpkg/status ]; then
        pk_mgr=dpkg; pk_db=/var/lib/dpkg/status
    elif command -v rpm >/dev/null 2>&1; then
        pk_mgr=rpm
        for f in /var/lib/rpm/rpmdb.sqlite /var/lib/rpm/Packages /usr/lib/sysimage/rpm/rpmdb.sqlite; do [ -f "$f" ] && pk_db=$f && break; done
    fi
    pk_list="$pk_dir/list.json"
    if [ -n "$pk_mgr" ] && { [ ! -s "$pk_list" ] || [ -f "$pk_dir/resend" ] || { [ -n "$pk_db" ] && [ "$pk_db" -nt "$pk_list" ]; } \
            || [ $(( $(date +%s) - $(stat -c %Y "$pk_list" 2>/dev/null || echo 0) )) -ge 86400 ]; }; then
        rm -f "$pk_dir/resend"
        # [naam, versie, architectuur, bronpakket, bronversie]
        if [ "$pk_mgr" = dpkg ]; then
            pk_rows=$(dpkg-query -W -f='${db:Status-Abbrev}\t${Package}\t${Version}\t${Architecture}\t${source:Package}\t${source:Version}\n' 2>/dev/null \
                | awk -F'\t' '$1 ~ /^ii/ { printf "[\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"],", $2, $3, $4, $5, $6 }')
        else
            pk_rows=$(rpm -qa --qf '%{NAME}\t%{EPOCHNUM}:%{VERSION}-%{RELEASE}\t%{ARCH}\t%{SOURCERPM}\n' 2>/dev/null \
                | awk -F'\t' '$1 != "gpg-pubkey" { v = $2; sub(/^0:/, "", v); s = $4; sub(/-[^-]+-[^-]+\.src\.rpm$/, "", s)
                    printf "[\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"],", $1, v, $3, s, v }')
        fi
        pk_os=$( (. /etc/os-release 2>/dev/null; printf '{"id":"%s","version":"%s","like":"%s","name":"%s"}' \
            "$(json_escape "${ID-}")" "$(json_escape "${VERSION_ID-}")" "$(json_escape "${ID_LIKE-}")" "$(json_escape "${PRETTY_NAME-}")") )
        # Bereikbaar via het netwerk: pakketten van programma's die op een poort luisteren (niet alleen op localhost)
        pk_exp=""
        if command -v ss >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
            declare -A pk_owner=()
            while read -r proto local pids; do
                for pid in $pids; do
                    exe=$(readlink -f "/proc/$pid/exe" 2>/dev/null) || continue
                    [ -n "$exe" ] || continue
                    if [ -z "${pk_owner[$exe]+x}" ]; then
                        if [ "$pk_mgr" = dpkg ]; then
                            o=$(dpkg -S "$exe" 2>/dev/null | head -1 | cut -d: -f1)
                            [ -z "$o" ] && o=$(dpkg -S "${exe#/usr}" 2>/dev/null | head -1 | cut -d: -f1)   # /usr-merge
                        else
                            o=$(rpm -qf --qf '%{NAME}\n' "$exe" 2>/dev/null | head -1)
                            [[ "$o" == *" "* ]] && o=""
                        fi
                        pk_owner[$exe]=$o
                    fi
                    [ -n "${pk_owner[$exe]}" ] && pk_exp+="[\"${pk_owner[$exe]}\",${local##*:},\"$proto\"],"
                done
            done < <(ss -Htulnp 2>/dev/null | awk '{
                    local = $5; if (local !~ /:[0-9]+$/) next
                    addr = local; sub(/:[0-9]+$/, "", addr); gsub(/[\[\]]/, "", addr); sub(/%.*/, "", addr)
                    if (addr ~ /^127\./ || addr == "::1" || addr == "localhost") next
                    pids = ""; s = $0
                    while (match(s, /pid=[0-9]+/)) { pids = pids " " substr(s, RSTART + 4, RLENGTH - 4); s = substr(s, RSTART + RLENGTH) }
                    if (pids != "") print $1, local, pids }' | sort -u)
        fi
        # Herstart nodig: nieuwere kernel geïnstalleerd dan er draait; services met oude bibliotheken (als de hulpmiddelen er zijn)
        pk_kern=$(find /boot -maxdepth 1 -name 'vmlinuz-*' -printf '%f\n' 2>/dev/null | sed 's/^vmlinuz-//' | grep -v rescue | sort -V | tail -1)
        pk_rs=""
        if command -v needs-restarting >/dev/null 2>&1; then
            pk_rs=$(timeout 60 needs-restarting -s 2>/dev/null | grep -E '^[A-Za-z0-9@._:-]+$' | head -50)
        elif command -v needrestart >/dev/null 2>&1; then
            pk_rs=$(timeout 60 needrestart -b -r l 2>/dev/null | sed -n 's/^NEEDRESTART-SVC: *//p' | grep -E '^[A-Za-z0-9@._:-]+$' | head -50)
        fi
        pk_rs_json=$(printf '%s\n' $pk_rs | awk 'NF { printf "\"%s\",", $0 }')
        printf '{"mgr":"%s","os":%s,"kernel":{"running":"%s","latest":"%s"},"restart":[%s],"exposed":[%s],"list":[%s]}' \
            "$pk_mgr" "${pk_os:-{\}}" "$(uname -r)" "$pk_kern" "${pk_rs_json%,}" "${pk_exp%,}" "${pk_rows%,}" >"$pk_list.tmp" && mv -f "$pk_list.tmp" "$pk_list"
    fi
    if [ -s "$pk_list" ]; then
        pk_hash=$(sha1sum "$pk_list" | cut -c1-16)
        # Alles meesturen als RzMon deze stand nog niet heeft; anders alleen het controlegetal
        if [ "$(cat "$pk_dir/sent" 2>/dev/null)" != "$pk_hash" ]; then
            pkgs_json="{\"hash\":\"$pk_hash\",\"data\":$(cat "$pk_list")}"
        else
            pkgs_json="{\"hash\":\"$pk_hash\"}"
        fi
    fi
fi
# Uitkomst van "pakketten bijwerken" (opdracht uit RzMon) terugmelden: id, ok/error en het einde van de uitvoer (base64)
pkg_result=""
if [ -r "$pk_dir/upgrade.result" ]; then
    IFS=$'\t' read -r pr_id pr_st pr_log <"$pk_dir/upgrade.result"
    [[ "$pr_id" =~ ^[0-9a-f]{8,32}$ ]] && [[ "$pr_log" =~ ^[A-Za-z0-9+/=]*$ ]] \
        && pkg_result="{\"id\":\"$pr_id\",\"ok\":$([ "$pr_st" = ok ] && echo true || echo false),\"log_b64\":\"$pr_log\"}"
fi

pkg_upgrade() {  # pkg_upgrade <antwoord van RzMon>: alleen dnf/yum/apt "upgrade" van de meegestuurde pakketnamen, als root
    # Agent 1.63.0: of "install" (mode), alleen voor de onderdelen van de endpoint-bewaking (vaste lijst hieronder)
    local id names n=() out="$pk_dir/upgrade.result" why="" mode=upgrade
    id=$(sed -n 's/.*"pkg_upgrade":[[:space:]]*{[^}]*"id":[[:space:]]*"\([0-9a-f]\{8,32\}\)".*/\1/p' <<<"$1")
    names=$(sed -n 's/.*"pkg_upgrade":[[:space:]]*{[^}]*"pkgs":[[:space:]]*\[\([^]]*\)\].*/\1/p' <<<"$1" | tr -d '"' | tr ',' ' ')
    [[ "$1" =~ \"pkg_upgrade\":[[:space:]]*\{[^}]*\"mode\":[[:space:]]*\"install\" ]] && mode=install
    [ -n "$id" ] && [ -d "$pk_dir" ] || return 0
    for p in $names; do
        [[ "$p" =~ ^[A-Za-z0-9][A-Za-z0-9.+_:~-]{0,99}$ ]] || continue
        if [ "$mode" = install ]; then case "$p" in audit|auditd|inotify-tools|epel-release|yara) ;; *) continue ;; esac; fi
        n+=("$p")
    done
    case "$COMMANDS" in 0|off|no|nee|nobody) why="geblokkeerd op de server (COMMANDS=$COMMANDS in /etc/default/rzmon-agent)" ;; esac
    [ "$(id -u)" = 0 ] || why="de agent draait niet als root"
    [ "${#n[@]}" -gt 0 ] || why="geen geldige pakketnamen ontvangen"
    if [ -n "$why" ]; then
        printf '%s\terror\t%s\n' "$id" "$(printf '%s' "$why" | base64 -w0)" >"$out"
        return 0
    fi
    # Loopt er al een (minder dan 30 minuten geleden gestart)? Dan niet nog een.
    if [ -f "$pk_dir/upgrade.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$pk_dir/upgrade.lock") )) -lt 1800 ]; then return 0; fi
    : >"$pk_dir/upgrade.lock"
    # Adres en token voor de voortgang: in een bestand (alleen root), niet als argument (zichtbaar in ps)
    # Regel 2: wie we zijn (header met het sleutelkenmerk of het token), regel 3: -k, of de vingerafdruk van RzMon
    local tls_spec=0
    [ "${tls[0]-}" = -k ] && tls_spec=1
    [ "${tls[1]-}" = --pinnedpubkey ] && tls_spec=${tls[2]}
    (umask 077; printf '%s\n%s\n%s\n%s\n' "$MONITOR_URL" "${auth[1]}" "$tls_spec" "$enc_key" >"$pk_dir/upgrade.conf")
    # Het script meldt zijn voortgang elke paar seconden zelf aan RzMon (stap, procent, pakket, laatste uitvoer);
    # de uitkomst gaat daarnaast via upgrade.result mee met het volgende rapport (als de voortgang niet aankwam).
    # shellcheck disable=SC2016  # het script krijgt zijn waarden als argumenten mee, niet via de tekst
    local script='id=$1; mgr=${2%%:*}; mode=${2#*:}; dir=$3; shift 3
        [ "$mode" = "$2" ] && mode=upgrade
        url=""; tok=""; ins=0; enc_key=""; last=0; stage=update; pct=2; detail=""; count=""
        if [ -r "$dir/upgrade.conf" ]; then { read -r url; read -r tok; read -r ins; read -r enc_key; } <"$dir/upgrade.conf"; rm -f "$dir/upgrade.conf"; fi
        log="$dir/upgrade.log"; : >"$log"
        post() {   # post [extra JSON-velden]: voortgang naar RzMon (hooguit 4 seconden wachten)
            [ -n "$url" ] || return 0
            local k=(-fsS -m 4 -o /dev/null -X POST -H "$tok" -H "Content-Type: application/json")
            [ "$ins" = 1 ] && k+=(-k)
            [[ "$ins" == sha256//* ]] && k+=(-k --pinnedpubkey "$ins")
            printf "%s" "{\"id\":\"$id\",\"stage\":\"$stage\",\"pct\":$pct,\"detail\":\"$(printf "%s" "$detail" | tr -d "\"\\\\\\000-\\037" | cut -c1-120)\",\"count\":\"$count\",\"log_b64\":\"$(tail -c "${2:-800}" "$log" | base64 -w0)\"$1}" >"$dir/progress.json"
            local f="$dir/progress.json"
            if [ -n "$enc_key" ] && seal "$dir/progress.json" "$dir/progress.enc"; then f="$dir/progress.enc"; fi   # versleuteld, net als het rapport
            curl "${k[@]}" --data-binary "@$f" "${url%/}/api.php?action=agent_pkg_progress" >/dev/null 2>&1
            last=$(date +%s)
        }
        tick() { [ "$1" != "$stage" ] && { stage=$1; post; return; }; [ $(( $(date +%s) - last )) -ge 2 ] && post; }
        export LC_ALL=C
        post
        if [ "$mgr" = rpm ]; then
            cmd=(dnf -y upgrade "$@"); command -v dnf >/dev/null 2>&1 || cmd=(yum -y update "$@")
            if [ "$mode" = install ]; then
                # EPEL eerst apart (inotify-tools en yara staan daarin), dan de rest
                if [ "$1" = epel-release ]; then dnf -y install epel-release >>"$log" 2>&1 || yum -y install epel-release >>"$log" 2>&1; shift; fi
                cmd=(dnf -y install "$@"); command -v dnf >/dev/null 2>&1 || cmd=(yum -y install "$@")
            fi
            "${cmd[@]}" 2>&1 | while IFS= read -r l; do
                printf "%s\n" "$l" >>"$log"
                if [[ "$l" =~ ^\(([0-9]+)/([0-9]+)\):\ ([^ ]+) ]]; then          # (3/7): openssl-libs-3.2.2.rpm   downloaden
                    pct=$((15 + 30 * BASH_REMATCH[1] / BASH_REMATCH[2])); detail=${BASH_REMATCH[3]}; count="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"; tick download
                elif [[ "$l" =~ ^\ +([A-Z][a-z]+( scriptlet)?)\ *:\ ([^ ]+).*\ ([0-9]+)/([0-9]+)$ ]]; then   #   Upgrading : openssl 3/14
                    detail=${BASH_REMATCH[3]}; count="${BASH_REMATCH[4]}/${BASH_REMATCH[5]}"
                    if [ "${BASH_REMATCH[1]}" = Verifying ]; then pct=$((90 + 8 * BASH_REMATCH[4] / BASH_REMATCH[5])); tick verify
                    else pct=$((45 + 45 * BASH_REMATCH[4] / BASH_REMATCH[5])); tick install; fi
                elif [[ "$l" =~ ^\[\ *([0-9]+)/([0-9]+)\]\ (.*)$ ]]; then          # dnf5: [ 3/14] Upgrading openssl
                    pct=$((45 + 50 * BASH_REMATCH[1] / BASH_REMATCH[2])); detail=${BASH_REMATCH[3]}; count="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"; tick install
                fi
            done
            rc=${PIPESTATUS[0]}
        else
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -q >>"$log" 2>&1; rc=$?
            if [ "$rc" = 0 ]; then
                stage=download; pct=15; post
                # APT::Status-Fd: apt meldt zelf het percentage (dlstatus = downloaden, pmstatus = installeren per pakket)
                up=--only-upgrade; [ "$mode" = install ] && up=--no-install-recommends
                apt-get install -y -q "$up" -o Dpkg::Options::=--force-confold -o APT::Status-Fd=3 "$@" 3>&1 >>"$log" 2>&1 \
                    | while IFS=: read -r kind p1 p2 _; do
                        p2=${p2%%.*}; [[ "$p2" =~ ^[0-9]+$ ]] || continue
                        case "$kind" in
                            dlstatus) pct=$((15 + 30 * p2 / 100)); detail=""; count=""; tick download ;;
                            pmstatus) pct=$((45 + 53 * p2 / 100)); detail=$p1; count=""; tick install ;;
                        esac
                    done
                rc=${PIPESTATUS[0]}
            fi
        fi
        # auditd na het installeren meteen starten (de agent zet de regels bij de volgende run)
        if [ "$mode" = install ] && [ "$rc" = 0 ] && command -v auditctl >/dev/null 2>&1; then systemctl enable --now auditd >>"$log" 2>&1 || service auditd start >>"$log" 2>&1; fi
        printf "%s\t%s\t%s\n" "$id" "$([ "$rc" = 0 ] && echo ok || echo error)" "$(tail -c 1500 "$log" | base64 -w0)" >"$dir/upgrade.result.tmp" \
            && mv -f "$dir/upgrade.result.tmp" "$dir/upgrade.result"
        stage=done; pct=100; detail=""; count=""
        post ",\"done\":true,\"ok\":$([ "$rc" = 0 ] && echo true || echo false)" 1500
        rm -f "$dir/upgrade.lock" "$dir/progress.json" "$dir/progress.enc"'
    script="$(declare -f enc_sub seal)
$script"   # versleutelen kan het script dan zelf
    # Als bestand starten, niet met bash -c: systemd-run vult ${…} en %-codes op de opdrachtregel zelf in, waardoor
    # bv. "${cmd[@]}" leeg werd (1.48.0–1.49.0: "_: line 34: : command not found")
    (umask 077; printf '%s\n' "$script" >"$pk_dir/upgrade.sh") \
        || { printf '%s\terror\t%s\n' "$id" "$(printf 'kan upgrade.sh niet schrijven' | base64 -w0)" >"$out"; rm -f "$pk_dir/upgrade.lock"; return 0; }
    if [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --collect --unit="rzmon-pkg-upgrade-$(date +%s)" /bin/bash "$pk_dir/upgrade.sh" "$id" "$pk_mgr:$mode" "$pk_dir" "${n[@]}" >/dev/null 2>&1 \
            || { printf '%s\terror\t%s\n' "$id" "$(printf 'systemd-run mislukt' | base64 -w0)" >"$out"; rm -f "$pk_dir/upgrade.lock"; }
    else
        setsid /bin/bash "$pk_dir/upgrade.sh" "$id" "$pk_mgr:$mode" "$pk_dir" "${n[@]}" </dev/null >/dev/null 2>&1 &
    fi
}

# ---------------------------------------------------------------- Bijwerken op afstand
# Kan deze agent zichzelf bijwerken, en hoe? Gestart door systemd (afgeschermd: /usr is alleen-lezen)
# → via systemd-run als aparte taak; via cron of met de hand → direct.
upd_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
upd_setup="$AGENT_SETUP"
if [ "$TEST" = 1 ]; then
    upd_via="test"   # verbindingstest: RzMon stuurt dan geen update-opdracht
elif [ "$HA_MODE" = 1 ]; then
    upd_via=ha
elif [ "$REMOTE_UPDATE" != 1 ]; then
    upd_via=off
elif [ "$(id -u)" != 0 ] || [ ! -f "$upd_setup" ] || ! command -v sha256sum >/dev/null 2>&1; then
    upd_via=none
elif [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
    upd_via=systemd
else
    upd_via=direct
fi
# Uitkomst van de vorige update terugmelden (één regel: "ok <oud> <nieuw>" of "error <reden>")
upd_result=""
if [ -r "$upd_dir/update.result" ]; then
    upd_result=$(head -c 300 "$upd_dir/update.result" | head -1)
fi

remote_update() {  # remote_update <antwoord van RzMon>
    local want sha_a sha_s k
    want=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"version":[[:space:]]*"\([0-9.]*\)".*/\1/p' <<<"$1")
    sha_a=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"agent_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    sha_s=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"setup_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    case "$upd_via" in systemd|direct) ;; *) return 0 ;; esac
    [ -n "$want" ] && [ -n "$sha_a" ] && [ -n "$sha_s" ] || return 0
    mkdir -p "$upd_dir" 2>/dev/null && [ -w "$upd_dir" ] || return 0
    # Loopt er al een update (minder dan 10 minuten geleden gestart)? Dan niet nog een.
    if [ -f "$upd_dir/update.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$upd_dir/update.lock" 2>/dev/null || echo 0) )) -lt 600 ]; then
        return 0
    fi
    : >"$upd_dir/update.lock"
    upd_fail() {
        printf 'error %s\n' "$1" >"$upd_dir/update.result"
        rm -f "$upd_dir/update.lock" "$upd_dir/agent.new" "$upd_dir/setup.new"
    }
    k=(-fsS -m 60 "${tls[@]}")
    if ! curl "${k[@]}" -o "$upd_dir/agent.new" "${MONITOR_URL%/}/api.php?action=agent_script" 2>/dev/null \
        || ! curl "${k[@]}" -o "$upd_dir/setup.new" "${MONITOR_URL%/}/api.php?action=agent_install" 2>/dev/null; then
        upd_fail "downloaden van de nieuwe versie bij RzMon mislukt"
        return 0
    fi
    if [ "$(sha256sum "$upd_dir/agent.new" | cut -d' ' -f1)" != "$sha_a" ] || [ "$(sha256sum "$upd_dir/setup.new" | cut -d' ' -f1)" != "$sha_s" ]; then
        upd_fail "controlegetal (SHA-256) klopt niet: download onvolledig of onderweg gewijzigd"
        return 0
    fi
    if ! bash -n "$upd_dir/agent.new" 2>/dev/null || ! bash -n "$upd_dir/setup.new" 2>/dev/null \
        || ! grep -q "^AGENT_VERSION=\"$want\"" "$upd_dir/agent.new" || ! grep -q 'RzMon agent' "$upd_dir/setup.new"; then
        upd_fail "de download is geen geldige RzMon-agent $want"
        return 0
    fi
    chmod 700 "$upd_dir/setup.new"
    # Het installatiescript doet de rest (zoals bij sudo rzmon-agent-setup --update), met de gecontroleerde bestanden
    local args=(/bin/bash "$upd_dir/setup.new" --update --yes --file "$upd_dir/agent.new" --result "$upd_dir/update.result")
    if [ "$upd_via" = systemd ]; then
        systemd-run --quiet --collect --unit="rzmon-agent-update-$(date +%s)" "${args[@]}" >/dev/null 2>&1 \
            || upd_fail "systemd-run mislukt; werk bij met: sudo rzmon-agent-setup --update"
    else
        setsid "${args[@]}" </dev/null >/dev/null 2>&1 &
    fi
}

# ---------------------------------------------------------------- Benodigdheden voor realtime (elk uur)
# Is inotify-tools er (bestanden in realtime), en op Rocky/Alma/RHEL: staat EPEL erop? RzMon toont het in de Agent Manager.
tools_json=""
if [ "$HA_MODE" = 0 ] && [ -n "${INVOCATION_ID-}" ]; then   # alleen met systemd (realtime werkt niet via cron)
    tf="$t_dir/tools.json"
    if [ ! -s "$tf" ] || [ $(( $(date +%s) - $(stat -c %Y "$tf") )) -ge 3600 ]; then
        ino=0; command -v inotifywait >/dev/null 2>&1 && ino=1
        epel=null; fam=other
        if command -v rpm >/dev/null 2>&1 && { command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; }; then
            fam=rhel; epel=0
            { rpm -q epel-release >/dev/null 2>&1 || rpm -qa 2>/dev/null | grep -q '^oracle-epel-release'; } && epel=1
        fi
        no_epel=0; [ "${EPEL-}" = 0 ] && no_epel=1
        printf '{"inotify":%d,"epel":%s,"family":"%s","no_epel":%d}' "$ino" "$epel" "$fam" "$no_epel" >"$tf" 2>/dev/null
    fi
    tools_json=$(cat "$tf" 2>/dev/null)
fi

# ---------------------------------------------------------------- Realtime-watcher: status
# state (active/off) <spatie> sinds <spatie> systemlog (ok/-) <spatie> bestanden (ok/missing/-), van de watcher zelf
rt_json=""
rt_line=""
[ -r "$t_dir/watch.state" ] && rt_line=$(head -n 1 "$t_dir/watch.state")
[ -n "${RT_HA-}" ] && rt_line=$RT_HA   # Home Assistant-add-on: de lus in run.sh geeft de status door
if [ -n "$rt_line" ]; then
    read -r rt_s rt_t rt_j rt_i _ <<<"$rt_line"
    [[ "$rt_t" =~ ^[0-9]+$ ]] && rt_json="{\"state\":\"$(json_escape "$rt_s")\",\"since\":$rt_t,\"journal\":\"$(json_escape "$rt_j")\",\"files\":\"$(json_escape "$rt_i")\"}"
fi

# ---------------------------------------------------------------- Heartbeats meesturen (rzmon-hb)
# Alleen regels in precies de vorm die rzmon-hb schrijft (iedereen mag in de map schrijven); oudste eerst, hooguit 100
hb_json=""; hb_files=()
if [ -d "$hb_dir" ]; then
    hb_re='^\{"n":"[A-Za-z0-9][A-Za-z0-9._-]{0,63}","s":"(start|ok|fail)","t":[0-9]{1,12}(,"v":-?[0-9]+(\.[0-9]+)?)?,"m":"([^"\\]|\\.)*"\}$'
    while IFS= read -r f; do
        [ -f "$f" ] && [ ! -L "$f" ] || continue
        line=$(head -c 1000 "$f" 2>/dev/null | head -n 1)
        if [[ ${#line} -le 900 && "$line" =~ $hb_re ]]; then
            hb_json+="${hb_json:+,}$line"
            hb_files+=("$f")
        else
            rm -f "$f"   # geen geldige regel: weg
        fi
        [ ${#hb_files[@]} -ge 100 ] && break
    done < <(ls -1tr "$hb_dir"/hb.* 2>/dev/null)
    # Map voor rzmon-hb: iedereen mag erin schrijven, maar niemand mag lezen of andermans regels weghalen
    [ "$(id -u)" = 0 ] && chmod 1733 "$hb_dir" 2>/dev/null
elif [ "$(id -u)" = 0 ] && [ "$HA_MODE" != 1 ]; then
    { mkdir -p "$hb_dir" && chmod 1733 "$hb_dir"; } 2>/dev/null
fi

# ---------------------------------------------------------------- Zelf herstellen en waakhond: meesturen
# Uitkomsten van herstelpogingen (weg na een geslaagd rapport), de herstelcommando's die hier staan, en een periode
# waarin de waakhond RzMon niet bereikte
heal_json=""; heal_cmds=""
if [ -s "$heal_dir/done" ]; then
    mv -f "$heal_dir/done" "$heal_dir/done.sending" 2>/dev/null
    heal_json=$(grep -E '^\{"id":[0-9]+,"ok":[01],"t":[0-9]+,"m":"([^"\\]|\\.)*"\}$' "$heal_dir/done.sending" 2>/dev/null | tail -n 50 | paste -sd, -)
fi
if [ "$HA_MODE" = 0 ] && [ -d "$HEAL_DIR" ]; then
    for hc_f in "$HEAL_DIR"/*; do
        hc_n=${hc_f##*/}
        [[ "$hc_n" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] && heal_cmd_ok "$hc_f" && heal_cmds+="${heal_cmds:+,}\"$hc_n\""
    done
fi
wd_file="$t_dir/watchdog"
wd_gap=""
[ -s "$wd_file.gap" ] && wd_gap=$(grep -E '^\{"down":[0-9]+,"up":[0-9]+,"sent":[01],"self":[01]\}$' "$wd_file.gap" | head -n 1)

# ---------------------------------------------------------------- Versturen
# Opstarttijd (epoch) voor de check Herstart in RzMon
read -r boot_up _ < /proc/uptime 2>/dev/null || boot_up=0
boot_ts=0; [ "${boot_up%.*}" -gt 0 ] 2>/dev/null && boot_ts=$(( $(date +%s) - ${boot_up%.*} ))
# Wat er naast de vaste onderdelen meegaat, voor --status (1.60.0)
parts="processen=$(grep -o '\["' <<<"$proc_list" | wc -l) certificaatbestanden=$(grep -o '"path"' <<<"$cert_found" | wc -l) softwarelijst=$( [ -n "$pkgs_json" ] && echo ja || echo ongewijzigd) heartbeats=${#hb_files[@]} ids=$( [ -n "$ids_json" ] && echo ja || echo nee)"
payload="{\"agent_version\":\"$AGENT_VERSION\",\"hostname\":\"$(json_escape "$hostname")\",\"boot\":$boot_ts,\"cpu\":{\"pct\":$cpu_pct,\"load\":[$l1,$l5,$l15],\"cores\":$cores},\"mem\":$mem,\"disks\":[${disks%,}],\"ports\":[${ports%,}],\"services\":{${services%,}},\"certs\":[${certs%,}]${podman_json:+,\"podman\":$podman_json}${rt_json:+,\"realtime\":$rt_json}${tools_json:+,\"tools\":$tools_json}${podman_ha:+,\"podman\":$podman_ha}${usb_json:+,\"usb\":$usb_json}${pve_json:+,\"pve\":$pve_json},\"svc\":{${svc_detail%,}},\"svc_tests\":{${svc_tests%,}},\"logs\":{${log_results%,}},\"cmd\":{${cmd_results%,}},${auth_json}${iso_json}${weblog_json}${ids_json}\"svc_list\":[${svc_list%,}],\"cert_found\":[${cert_found%,}],\"procs\":{${procs_json%,}},\"proc_list\":[${proc_list%,}],\"cfg\":$(cfg_json),${ha_json:+\"ha\":$ha_json,}\"update\":{\"via\":\"$upd_via\"},\"url\":\"$(json_escape "$MONITOR_URL")\",\"url_fallback\":\"$(json_escape "$MONITOR_URL_FALLBACK")\",\"url_pin\":\"$(url_spec "$MONITOR_URL")\",\"url_fb_pin\":\"$(url_spec "$MONITOR_URL_FALLBACK")\"${url_result:+,\"url_result\":\"$(json_escape "$url_result")\"}${upd_result:+,\"update_result\":\"$(json_escape "$upd_result")\"}${pkgs_json:+,\"packages\":$pkgs_json}${pkg_result:+,\"pkg_result\":$pkg_result}${hb_json:+,\"hb\":[$hb_json]}${heal_json:+,\"heal\":[$heal_json]}${heal_cmds:+,\"heal_cmds\":[$heal_cmds]}${wd_gap:+,\"watchdog\":$wd_gap}${edr_json:+,\"edr\":$edr_json}}"

curl_opts=(-fsS -m 20 -X POST "${tls[@]}")
# Rapport via een tijdelijk bestand versturen, niet als argument: op sommige systemen (TrueNAS SCALE)
# wordt curl met een lang argument stil afgebroken en komt er niets aan.
payload_file=$(mktemp "${TMPDIR:-/tmp}/rzmon-report.XXXXXX") || { echo "Kan geen tijdelijk bestand maken" >&2; exit 1; }
trap 'rm -f "$payload_file" "$payload_file.enc" "$payload_file.u"' EXIT
printf '%s' "$payload" >"$payload_file"
# Alleen geldige UTF-8 versturen (1.59.1): een afgeknipt teken (mawk knipt op bytes) maakte het hele rapport onleesbaar
if command -v iconv >/dev/null 2>&1; then
    iconv -f UTF-8 -t UTF-8 -c <"$payload_file" >"$payload_file.u" 2>/dev/null
    [ -s "$payload_file.u" ] && mv -f "$payload_file.u" "$payload_file"
    rm -f "$payload_file.u"
fi
send_file=$payload_file; seal_n=""
if [ -n "$enc_key" ]; then
    if seal "$payload_file" "$payload_file.enc"; then send_file="$payload_file.enc"
    else echo "Versleutelen mislukt (openssl); rapport gaat onversleuteld" >&2; seal_n=""; fi
fi
# Antwoord van RzMon: versleuteld (dan eerst controleren) of gewoon. Klopt de handtekening niet: niets mee doen.
open_resp() {
    if [[ "$1" == '{"rzenc":'* ]]; then
        unseal "$1" "$seal_n" || { echo "Antwoord van RzMon niet te vertrouwen (handtekening klopt niet); genegeerd" >&2; return 1; }
    elif [ -n "$seal_n" ]; then
        # Versleuteld verstuurd, maar onversleuteld antwoord: dat komt niet van RzMon (die antwoordt dan altijd versleuteld)
        echo "Antwoord van RzMon niet te vertrouwen (niet versleuteld); genegeerd" >&2; return 1
    else
        printf '%s' "$1"
    fi
}

if [ "$TEST" = 1 ]; then
    # 7. Verzamelde gegevens tonen
    n_disks=$(grep -o '"mount"' <<<"$disks" | wc -l)
    n_ports=$(grep -o '"port"' <<<"$ports" | wc -l)
    pass "Gegevens verzameld: CPU ${cpu_pct}% (${cores} cores), $n_disks disk(s), $n_ports luisterende poort(en)"
    [ -n "$services" ] && hint "Services: $(sed 's/"//g; s/,$//; s/,/, /g' <<<"$services")"
    command -v ss >/dev/null 2>&1 || hint "ss ontbreekt: poorten worden niet gemeld (Rocky: dnf install iproute)"
    if [ -n "$svc_detail" ]; then
        pass "Diepere controle van services:"
        # Per service: poorten en het resultaat van de test (✘ = probleem)
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${svc_detail%,}" | sed -nE \
            's/^"([^"]+)":\{"sub":"running".*"ports":\[([0-9,]*)\],"ports_fail":\[([0-9,]*)\].*"sockets":\[([^]]*)\],"sockets_fail":\[([^]]*)\].*"probe_rc":([0-9]|null),"probe_msg":"([^"]*)".*"log_errors":([0-9]+|null).*/\1|\2|\3|\4|\5|\6|\7|\8/p' |
            while IFS='|' read -r n p pf so sf rc m le; do
                printf '%s %s: poorten %s%s%s%s%s\n' "$( [ -n "$pf$sf" ] || [ "$rc" = 2 ] && echo ✘ || echo ✔)" "$n" "${p:-geen}" \
                    "${pf:+ (dicht: $pf)}" "$( [ -n "$sf" ] && echo " · socket: ${sf//\"/}" || { [ -n "$so" ] && echo " · socket ${so//\"/} ok"; })" \
                    "${m:+ · $m}" "$( [ "$le" != null ] && [ "$le" != 0 ] && echo " · $le fout(en) in log")"
            done)
    fi
    if [ -n "$cmd_results" ]; then
        pass "Commando-checks:"
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${cmd_results%,}" | sed -nE \
            -e 's/^"([0-9]+)":\{"blocked":"([^"]*)".*/check \1: geblokkeerd (\2)/p' \
            -e 's/^"([0-9]+)":\{"error":"([^"]*)".*/check \1: \2/p' \
            -e 's/^"([0-9]+)":\{"rc":(-?[0-9]+),"out":"(([^"\\]|\\.){0,60}).*"ms":([0-9]+),"as":"([a-z]+)".*/check \1: exitcode \2 in \5 ms als \6: \3/p')
    elif grep -q $'^[0-9]*\tcmd\t' "${t_cache:-/dev/null}" 2>/dev/null; then
        hint "Commando-checks: nog niets uitgevoerd"
    fi
    if [ -n "$log_results" ]; then
        pass "Logbestanden doorzocht:"
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${log_results%,}" |
            sed -nE 's/^"([0-9]+)":\{"new":([0-9]+).*"error":"([^"]*)"\}?$/\1|\2|\3/p' |
            while IFS='|' read -r i n e; do
                if [ -n "$e" ]; then echo "✘ zoekopdracht $i: $e"; else echo "zoekopdracht $i: $n nieuwe treffer(s)"; fi
            done)
    fi
    if [ -n "$podman_json" ]; then
        n_run=$(grep -o '"state":"running"' <<<"$podman_json" | wc -l)
        n_all=$(grep -o '"state":"' <<<"$podman_json" | wc -l)
        pass "Podman $(sed -n 's/^{"version":"\([^"]*\)".*/\1/p' <<<"$podman_json"): $n_run van $n_all container(s) draaien (${podman_ctx[*]})"
        p_err=$(sed -n 's/^{"version":"[^"]*","error":"\([^"]*\)".*/\1/p' <<<"$podman_json")
        [ -n "$p_err" ] && note "Podman-fout: $p_err"
    elif command -v podman >/dev/null 2>&1; then
        hint "Podman staat erop, maar wordt niet gemeld (PODMAN=0 of HA-modus)"
    fi
    if [ -n "$certs" ]; then
        n_certs=$(grep -o '"source"' <<<"$certs" | wc -l)
        pass "Certificaten: $n_certs uitgelezen"
        while IFS= read -r line; do
            hint "$line"
        done < <(sed 's/},{/}\n{/g' <<<"${certs%,}" | sed -E 's/^\{"source":"([^"]*)","cn":"([^"]*)",.*"not_after":([0-9]+|null).*"error":"([^"]*)"\}?,?$/\1|\2|\3|\4/' |
            while IFS='|' read -r src cn na err; do
                if [ -n "$err" ]; then echo "✘ $src: $err"
                else echo "$src: ${cn:-?}, nog $(( (na - $(date +%s)) / 86400 )) dagen geldig"; fi
            done)
    elif [ -n "$CERTS" ]; then
        hint "Certificaten: geen gevonden voor CERTS=\"$CERTS\""
    fi

    # 8. Echt rapport versturen
    resp=$(curl "${curl_k[@]}" -m 20 -X POST -H "Content-Type: application/json" "${auth[@]}" \
        --data-binary "@$send_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent" 2>&1)
    r_rc=$?
    r_code=${resp##*$'\n'}; r_body=${resp%$'\n'*}
    if [ "$r_code" = 200 ] && [[ "$r_body" == '{"rzenc":'* ]]; then
        r_body=$(open_resp "$r_body") && pass "Versleuteld verstuurd en het antwoord van RzMon klopt (🔒)"
    elif [ -n "$enc_key" ]; then hint "Sleutel aanwezig, maar het antwoord kwam onversleuteld terug"
    else hint "Niet versleuteld (geen sleutel): koppel de agent met een koppelcode (RzMon → Agent Manager → 🔗 Koppelcode)"; fi
    if [ -n "$kid" ]; then pass "🔑 Eigen sleutel van deze server (geen token meer nodig)"
    elif [ -n "$enc_key" ]; then hint "Nog de algemene sleutel: RzMon stuurt bij het volgende rapport een eigen sleutel mee"; fi
    if [ -z "$resp" ]; then
        fail "Rapport niet verstuurd: curl stopte zonder uitvoer (exitcode $r_rc, rapport $(wc -c <"$payload_file") bytes)"
    elif [ "$r_code" = 200 ] && [[ "$r_body" == *'"ok":true'* ]]; then
        pass "Rapport ontvangen door RzMon: $r_body"
        echo
        echo "  Alles werkt. Zie je in RzMon toch niets? Klap de server open → 'Agent Manager':"
        echo "  daar staat wanneer het laatste rapport binnenkwam en vanaf welk IP."
    else
        fail "Rapport niet geaccepteerd (HTTP $r_code): ${r_body:0:200}"
    fi
    exit "$TEST_FAILED"
fi

s_t0=$(now_ms); fb_used=0
resp=$(curl "${curl_opts[@]}" \
    -H "Content-Type: application/json" \
    "${auth[@]}" \
    --data-binary "@$send_file" \
    -w '\n%{http_code}' \
    "${MONITOR_URL%/}/api.php?action=agent")
rc=$?
h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
# Certificaat van RzMon past niet meer bij de vingerafdruk (curl 90), bv. een nieuw certificaat: één keer opnieuw
# zonder vastzetten, maar alleen versleuteld. Het antwoord moet dan met onze sleutel ondertekend zijn (dat kan
# alleen RzMon), en daarin staat de nieuwe vingerafdruk. Een ander certificaat met een vals antwoord: geweigerd.
if [ "$rc" = 90 ] && [ -n "$seal_n" ]; then
    echo "Certificaat van RzMon is veranderd; controleren met de sleutel" >&2
    p_opts=(-fsS -m 20 -X POST -k -H "X-Agent-Pin-Fail: 1")   # RzMon bepaalt de vingerafdruk dan meteen opnieuw
    resp=$(curl "${p_opts[@]}" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$send_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent")
    rc=$?
    h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
fi
# Gewone adres niet bereikbaar (geen verbinding, time-out, certificaat, of de proxy geeft 502-504)? Dan via het
# reserve-adres (agent 1.51.0), opnieuw versleuteld met een nieuwe nonce. RzMon ziet dat (X-Agent-Fallback) en meldt ⚠️.
if [ -n "$MONITOR_URL_FALLBACK" ] && { [[ " 6 7 28 35 52 56 60 " == *" $rc "* ]] || { [ "$rc" = 22 ] && [[ " 502 503 504 " == *" $h_code "* ]]; }; }; then
    echo "RzMon niet bereikbaar via $MONITOR_URL (curl $rc${h_code:+, HTTP $h_code}); via het reserve-adres $MONITOR_URL_FALLBACK" >&2
    if [ -n "$seal_n" ]; then seal "$payload_file" "$payload_file.enc" || seal_n=""; fi
    spec_tls "$MONITOR_URL_FALLBACK" "$(url_spec "$MONITOR_URL_FALLBACK")"
    fb_opts=(-fsS -m 20 -X POST "${s_tls[@]}"); fb_used=1
    resp=$(curl "${fb_opts[@]}" -H "X-Agent-Fallback: 1" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$send_file" \
        -w '\n%{http_code}' "$MONITOR_URL_FALLBACK/api.php?action=agent")
    rc=$?
    h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
fi
# Geweigerd met de algemene sleutel van RzMon (bv. ouder dan de wisseltijd)? Na 3 keer die sleutel weggooien:
# dan gaat het volgende rapport onversleuteld (of met ENC_KEY). De eigen sleutel gooit de agent nooit weg; staat
# versleutelen uit (enc.off) en weigert RzMon het token, dan staat het weer aan: de volgende keer met de sleutel.
if [ "$rc" = 22 ] && [ -n "$seal_n" ] && [ -z "$kid" ] && [ -r "$enc_file" ]; then
    n_fail=$(( $(cat "$t_dir/enc.fail" 2>/dev/null || echo 0) + 1 ))
    if [ "$n_fail" -ge 3 ]; then rm -f "$enc_file" "$t_dir/enc.fail"; else echo "$n_fail" >"$t_dir/enc.fail"; fi
elif [ "$rc" = 22 ] && [ -z "$kid" ] && [ -n "$own_key" ] && [ -e "$t_dir/enc.off" ]; then
    rm -f "$t_dir/enc.off"
elif [ "$rc" = 22 ] && [ -n "$kid" ]; then
    echo "RzMon weigert dit rapport. De reden staat in RzMon bij Meldingen (bv. nieuw IP-adres, of de sleutel is vernieuwd: maak dan een koppelcode in de Agent Manager)." >&2
elif [ "$rc" = 0 ]; then
    rm -f "$t_dir/enc.fail"
fi
if [ "$rc" = 0 ]; then resp=$(open_resp "$resp") || rc=1; fi
# Dit contact bijhouden voor --status (1.60.0): laatste rapport, de laatste 30 contacten, fouten, controle van RzMon
if mkdir -p "$st_dir" 2>/dev/null && [ -w "$st_dir" ]; then
    st_now=$(date +%s); st_ms=$(( $(now_ms) - run_t0 )); st_sms=$(( $(now_ms) - s_t0 ))
    st_err=""
    if [ "$rc" != 0 ]; then
        case "$rc" in
            22) st_err="RzMon weigerde het rapport (HTTP ${h_code:-?}); de reden staat in RzMon (Agent Manager)" ;;
            6) st_err="naam van RzMon niet gevonden (DNS)" ;; 7) st_err="geen verbinding (poort dicht of RzMon uit)" ;;
            28) st_err="geen antwoord binnen 20 seconden" ;; 35|60) st_err="TLS/certificaat klopt niet" ;; 90) st_err="certificaat past niet bij de vingerafdruk" ;;
            1) st_err="antwoord van RzMon niet te vertrouwen (handtekening)" ;; *) st_err="curl $rc${h_code:+, HTTP $h_code}" ;;
        esac
    fi
    st_rcv=$(sed -n 's/.*"received":{\([^}]*\)}.*/\1/p' <<<"$resp" | head -c 300)
    (umask 077; printf 'time=%s\nrc=%s\nhttp=%s\nms=%s\nsend_ms=%s\nbytes=%s\nsent=%s\nenc=%s\nvia=%s\nerror=%s\nreceived=%s\nparts=%s\n' \
        "$st_now" "$rc" "${h_code:-}" "$st_ms" "$st_sms" "$(wc -c <"$payload_file")" "$(wc -c <"$send_file")" "$( [ -n "$seal_n" ] && echo 1 || echo 0)" \
        "$( [ "$fb_used" = 1 ] && echo fallback || echo primary)" "$st_err" "$st_rcv" "$parts" >"$st_dir/last") 2>/dev/null
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$st_now" "$rc" "${h_code:-}" "$st_ms" "$(wc -c <"$send_file")" "$( [ -n "$seal_n" ] && echo 1 || echo 0)" \
        "$( [ "$fb_used" = 1 ] && echo fallback || echo primary)" >>"$st_dir/history" 2>/dev/null
    [ "$(wc -l <"$st_dir/history" 2>/dev/null)" -gt 30 ] && { tail -n 30 "$st_dir/history" >"$st_dir/history.tmp" && mv -f "$st_dir/history.tmp" "$st_dir/history"; }
    if [ -n "$st_err" ]; then
        printf '%s\t%s\n' "$st_now" "$st_err" >>"$st_dir/errors" 2>/dev/null
        [ "$(wc -l <"$st_dir/errors" 2>/dev/null)" -gt 20 ] && { tail -n 20 "$st_dir/errors" >"$st_dir/errors.tmp" && mv -f "$st_dir/errors.tmp" "$st_dir/errors"; }
    fi
    # De beveiligingscontrole van RzMon (regels tekst, RzMon 1.100.0+)
    if [ "$rc" = 0 ] && [[ "$resp" =~ \"sec\":\[([^]]*)\] ]]; then
        grep -o '"[^"]*"' <<<"${BASH_REMATCH[1]}" | sed 's/^"//; s/"$//; s#\\/#/#g' >"$st_dir/sec" 2>/dev/null
    fi
fi
# Uitvoer (komt in het logboek): zonder sleutels
sed -E 's/"(agent_key|enc_key)":"[0-9a-f]{64}"/"\1":"…"/g' <<<"$resp"
if [ "$rc" -eq 0 ]; then
    # Versleuteling: eigen sleutel en vingerafdruk van RzMon bewaren (alleen uit een versleuteld antwoord), de
    # algemene sleutel (agent 1.49), of stoppen als het voor deze server uit staat
    if [ -n "$seal_n" ]; then
        store_key "$resp" && rm -f "$t_dir/enc.off"
        store_pin "$resp"
        # Per adres hoe het gecontroleerd wordt (RzMon 1.78.0): alleen voor het gewone en het reserve-adres
        if [[ "$resp" =~ \"url_pins\":\{([^}]*)\} ]]; then
            p_rest=${BASH_REMATCH[1]}; p_args=()
            while [[ "$p_rest" =~ \"([^\"]+)\":\"([^\"]*)\" ]]; do
                p_u=${BASH_REMATCH[1]//\\/}; p_s=${BASH_REMATCH[2]//\\/}
                p_rest=${p_rest#*"${BASH_REMATCH[0]}"}
                [ "$p_u" = "$MONITOR_URL" ] || [ "$p_u" = "$MONITOR_URL_FALLBACK" ] || continue
                [ "$(url_spec "$p_u")" = "$p_s" ] || p_args+=("$p_u" "$p_s")
            done
            [ ${#p_args[@]} -gt 0 ] && url_pins_put "${p_args[@]}"
        fi
    fi
    if [[ "$resp" =~ \"enc_key\":\"([0-9a-f]{64})\" ]] && [ -n "$seal_n" ]; then
        (umask 077; printf '%s\n' "${BASH_REMATCH[1]}" >"$enc_file.tmp" && mv -f "$enc_file.tmp" "$enc_file") && rm -f "$t_dir/enc.off"
    elif [[ "$resp" =~ \"enc\":\"off\" ]]; then
        rm -f "$enc_file"; : >"$t_dir/enc.off"
    fi
    # Lokaas-poort: de gemelde IP's zijn binnen, uit de set halen (een nieuwe verbinding zet ze er weer in)
    [ -n "$dc_sent4" ] && nft delete element inet rzmon dc4 "{ $dc_sent4 }" 2>/dev/null
    [ -n "$dc_sent6" ] && nft delete element inet rzmon dc6 "{ $dc_sent6 }" 2>/dev/null
    # Heartbeats zijn binnen; namen zonder check in RzMon in het logboek zetten
    [ ${#hb_files[@]} -gt 0 ] && rm -f "${hb_files[@]}"
    if [[ "$resp" =~ \"hb_unknown\":\[([^]]*)\] ]]; then
        echo "rzmon-hb: RzMon heeft geen heartbeat-check met de naam ${BASH_REMATCH[1]//\"/} op deze server (＋ Check → Heartbeat)" >&2
    fi
    # Uitkomst van een overstap is doorgegeven; vraagt RzMon om naar een ander adres over te stappen (alleen versleuteld)?
    [ -n "$url_result" ] && rm -f "$k_dir/url.result"
    if [ -n "$seal_n" ] && [[ "$resp" =~ \"url_set\":\{\"url\":\"([^\"]+)\",\"fallback\":\"([^\"]*)\"(,\"pin\":\"([^\"]*)\",\"fb_pin\":\"([^\"]*)\")?\} ]]; then
        url_switch "$(sed 's#\\/#/#g' <<<"${BASH_REMATCH[1]}")" "$(sed 's#\\/#/#g' <<<"${BASH_REMATCH[2]}")" "${BASH_REMATCH[4]//\\/}" "${BASH_REMATCH[5]//\\/}"
    fi
    # Uitkomst is doorgegeven; vraagt RzMon om bij te werken?
    [ -n "$upd_result" ] && rm -f "$upd_dir/update.result"
    # IDS, bestanden bewaken: RzMon heeft de wijzigingen; dit is nu de stand om mee te vergelijken
    if [ -n "$fim_commit" ]; then
        mv -f "$fim_dir/state.new" "$fim_dir/state" && mv -f "$fim_dir/meta.new" "$fim_dir/meta" && mv -f "$fim_dir/roots.new" "$fim_dir/roots"
        [ "$fim_rh" != /dev/null ] && touch "$i_dir/rehash.used"
    fi
    # Instellingen in RzMon gewijzigd (bv. een service-check toegevoegd)? Lijst bij de volgende run opnieuw ophalen.
    rev=$(sed -n 's/.*"tests_rev":[[:space:]]*"\([0-9a-f]\{8,64\}\)".*/\1/p' <<<"$resp")
    if [ -n "$rev" ] && [ -n "${t_cache-}" ] && [ -r "$t_cache" ] && [ "$(sha1sum "$t_cache" | cut -c1-12)" != "$rev" ]; then
        : >"$t_dir/tests.stale" 2>/dev/null || rm -f "$t_cache"   # volgende run opnieuw ophalen; de oude lijst blijft tot dan
    fi
    [[ "$resp" =~ \"update\":[[:space:]]*\{ ]] && remote_update "$resp"
    # Softwarelijst: RzMon heeft deze stand nu (of vraagt om alles opnieuw); uitkomst van het bijwerken is doorgegeven
    if [ -n "$pk_hash" ]; then
        if [[ "$resp" =~ \"packages_resend\":[[:space:]]*true ]]; then : >"$pk_dir/resend"; rm -f "$pk_dir/sent"
        elif [[ "$resp" =~ \"packages_ok\":[[:space:]]*true ]]; then printf '%s' "$pk_hash" >"$pk_dir/sent"; fi
    fi
    [ -n "$pkg_result" ] && rm -f "$pk_dir/upgrade.result"
    # Endpoint-bewaking: de meegestuurde gebeurtenissen zijn binnen; vraagt RzMon om processen meteen te stoppen?
    # (alleen versleuteld, en alleen als de starttijd klopt: nooit een ander proces dat dat nummer later kreeg)
    if [ -n "$edr_json" ] && [ -s "$edr_dir/edr.sending" ]; then
        tail -n +501 "$edr_dir/edr.sending" >"$edr_dir/edr.rest" 2>/dev/null && mv -f "$edr_dir/edr.rest" "$edr_dir/edr.sending"
        [ -s "$edr_dir/edr.sending" ] || rm -f "$edr_dir/edr.sending"
        rm -f "$edr_dir/edr.drop"
    fi
    if [ -n "$seal_n" ] && [ "$IDS_BLOCK" != 0 ] && [ "$(id -u)" = 0 ] && [[ "$resp" =~ \"edr_kill\":\[(\[[0-9]+,[0-9]+\],?)+\] ]]; then
        mkdir -p "$t_dir/ids" 2>/dev/null
        while IFS=, read -r k_pid k_start; do
            [[ "$k_pid" =~ ^[0-9]+$ ]] && [[ "$k_start" =~ ^[0-9]+$ ]] && [ "$k_pid" -gt 1 ] || continue
            [ -f "$t_dir/ids/killed.$k_pid.$k_start" ] && continue
            k_res=weg
            if [ "$(awk '{ print $22 }' "/proc/$k_pid/stat" 2>/dev/null)" = "$k_start" ]; then
                if kill -KILL "$k_pid" 2>/dev/null; then k_res=ok; else k_res=fout; fi
            fi
            : >"$t_dir/ids/killed.$k_pid.$k_start"
            printf '[%s,%s,"%s"],' "$k_pid" "$k_start" "$k_res" >>"$t_dir/ids/killed.pending"
        done < <(grep -o '\[[0-9]*,[0-9]*\]' <<<"${BASH_REMATCH[0]#*:}" | tr -d '[]')
    fi
    [[ "$resp" =~ \"pkg_upgrade\":[[:space:]]*\{ ]] && [ -n "$pk_mgr" ] && pkg_upgrade "$resp"
    # Zelf herstellen: de uitkomsten zijn binnen; vraagt RzMon om een herstelcommando (alleen versleuteld)?
    rm -f "$heal_dir/done.sending" "$t_dir/watchdog.gap"
    if [ -n "$seal_n" ] && [ "$HEAL" != 0 ] && [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [[ "$resp" =~ \"heal_run\":\[(\[[0-9]+,\"[A-Za-z0-9._-]+\"\],?)+\] ]]; then
        while IFS=, read -r hr_id hr_name; do
            hr_id=${hr_id//[^0-9]/}; hr_name=${hr_name//\"/}; hr_name=${hr_name%]}
            [[ "$hr_id" =~ ^[0-9]+$ ]] && [[ "$hr_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || continue
            heal_ok "$hr_id" || continue
            hr_f="$HEAL_DIR/$hr_name"
            if ! heal_cmd_ok "$hr_f"; then
                heal_log "$hr_id" 0 "herstelcommando $hr_f niet gebruikt: bestaat niet, of is niet uitvoerbaar, niet van root of door anderen te wijzigen"
                continue
            fi
            hr_out=$(timeout 120 "$hr_f" 2>&1); hr_rc=$?
            hr_msg=$(grep -v '^[[:space:]]*$' <<<"$hr_out" | head -n 1 | cut -c1-150)
            if [ "$hr_rc" = 0 ]; then heal_log "$hr_id" 1 "herstelcommando $hr_name uitgevoerd${hr_msg:+: $hr_msg}"
            elif [ "$hr_rc" = 124 ]; then heal_log "$hr_id" 0 "herstelcommando $hr_name duurde langer dan 120 seconden"
            else heal_log "$hr_id" 0 "herstelcommando $hr_name mislukt (code $hr_rc)${hr_msg:+: $hr_msg}"; fi
        done < <(grep -o '\[[0-9]*,"[A-Za-z0-9._-]*"\]' <<<"${BASH_REMATCH[0]#*:}" | tr -d '[]')
    fi
elif [ -s "$heal_dir/done.sending" ]; then
    cat "$heal_dir/done.sending" >>"$heal_dir/done" 2>/dev/null && rm -f "$heal_dir/done.sending"   # volgende keer opnieuw
fi

# ---------------------------------------------------------------- 🐕 Waakhond (agent 1.62.0)
# In RzMon per agent aan te zetten (Agent Manager). Lukt het rapport een tijd niet (standaard 5 minuten), dan stuurt de
# agent zelf een Pushover-bericht, en een herstelbericht als RzMon terug is. Bereikt de agent ook Pushover niet, dan ligt
# het aan zijn eigen netwerk: dan geen bericht, wel een notitie voor RzMon. Draait RzMon wel, maar staan de checks stil
# (cron), dan meldt hij dat ook. De Pushover-gegevens komen versleuteld van RzMon (cache, alleen voor root).
wd_line=$(awk -F'\t' '$1 == "0" && $2 == "watchdog" { print; exit }' "${t_cache:-/dev/null}" 2>/dev/null)
if [ -n "$wd_line" ] && [ "$WATCHDOG" != 0 ] && [ "$TEST" != 1 ]; then
    IFS=$'\t' read -r _ _ wd_min wd_user wd_token wd_prio <<<"$wd_line"
    [[ "$wd_min" =~ ^[0-9]+$ ]] || wd_min=5
    [[ "$wd_prio" =~ ^-?[0-2]$ ]] || wd_prio=1
    wd_push() {  # wd_push <titel> <bericht> <prioriteit>: 0 = verstuurd
        [ "$wd_user" != - ] && [ "$wd_token" != - ] && [ -n "$wd_user" ] || return 1
        local extra=()
        [ "$3" = 2 ] && extra=(--form-string retry=120 --form-string expire=3600)
        curl -fsS -m 15 -o /dev/null --form-string "token=$wd_token" --form-string "user=$wd_user" --form-string "title=$1" \
            --form-string "message=$2" --form-string "priority=$3" "${extra[@]}" "$PUSHOVER_API" 2>/dev/null
    }
    wd_down=0; wd_sent=0; wd_self=0; wd_cron=0
    # shellcheck disable=SC1090
    [ -r "$wd_file" ] && . <(grep -E '^wd_(down|sent|self|cron)=[0-9]+$' "$wd_file")
    wd_now=$(date +%s)
    if [ "$rc" != 0 ]; then
        [ "$wd_down" -gt 0 ] || wd_down=$wd_now
        if [ "$wd_sent" = 0 ] && [ $(( wd_now - wd_down )) -ge $(( wd_min * 60 )) ]; then
            if wd_push "⚠️ RzMon onbereikbaar" "RzMon is vanaf $hostname niet bereikbaar sinds $(date -d "@$wd_down" +%H:%M 2>/dev/null || date +%H:%M)${st_err:+ ($st_err)}. Gemeld door de waakhond op $hostname." "$wd_prio"; then
                wd_sent=1; wd_self=0
            else
                wd_self=1   # Pushover ook niet bereikbaar (of geen gegevens): eerder het netwerk van deze server
            fi
        fi
    else
        if [ "$wd_down" -gt 0 ]; then
            [ "$wd_sent" = 1 ] && wd_push "✅ RzMon weer bereikbaar" "RzMon is vanaf $hostname weer bereikbaar ($(( (wd_now - wd_down + 59) / 60 )) min weg)." -1
            # Voor RzMon (ℹ️ in het volgende rapport): alleen als het langer duurde dan de ingestelde tijd
            [ $(( wd_now - wd_down )) -ge $(( wd_min * 60 )) ] && printf '{"down":%s,"up":%s,"sent":%s,"self":%s}\n' "$wd_down" "$wd_now" "$wd_sent" "$wd_self" >"$wd_file.gap"
            wd_down=0; wd_sent=0; wd_self=0
        fi
        # RzMon antwoordt, maar staan de checks stil (cron)?
        wd_age=$(sed -n 's/.*"cron_age":[[:space:]]*\([0-9]\{1,9\}\).*/\1/p' <<<"$resp" | head -n 1)
        if [[ "$wd_age" =~ ^[0-9]+$ ]]; then
            if [ "$wd_age" -ge $(( wd_min * 60 )) ] && [ "$wd_cron" = 0 ]; then
                wd_push "⚠️ RzMon: checks staan stil" "RzMon antwoordt, maar de checks hebben al $(( wd_age / 60 )) min niet gedraaid (cron). Gemeld door de waakhond op $hostname." "$wd_prio" && wd_cron=1
            elif [ "$wd_age" -lt 300 ] && [ "$wd_cron" = 1 ]; then
                wd_push "✅ RzMon: checks draaien weer" "De checks in RzMon draaien weer (gezien vanaf $hostname)." -1
                wd_cron=0
            fi
        fi
    fi
    (umask 077; printf 'wd_down=%s\nwd_sent=%s\nwd_self=%s\nwd_cron=%s\n' "$wd_down" "$wd_sent" "$wd_self" "$wd_cron" >"$wd_file") 2>/dev/null
fi
exit "$rc"
