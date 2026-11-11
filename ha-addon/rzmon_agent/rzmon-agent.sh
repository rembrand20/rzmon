#!/usr/bin/env bash
# (Dit bestand wordt gebouwd uit agent/src/*.sh met tools/build-agent.sh: wijzig de modules daar, niet dit bestand.)
# RzSense agent (kenmerk voor oudere installers: RzMon agent) – rapporteert CPU, RAM, disk usage, luisterende poorten, service-status,
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

# Versie: wordt bijgewerkt door tools/bump-version.sh (gelijk aan VERSION van RzSense)
AGENT_VERSION="1.95.1"

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
# Welke services bewaakt worden, stel je in RzSense in (service-check toevoegen). SERVICES is verouderd,
# maar werkt nog: services in deze spatie-gescheiden lijst worden óók gemeld (niet gebruikt in HA-modus).
SERVICES="${SERVICES-}"
# INSECURE=1 accepteert een self-signed certificaat van RzSense
INSECURE="${INSECURE:-0}"
# Certificaten om te bewaken (spatie-gescheiden): bestanden (wildcards mogen) en/of host:poort,
# lokaal of op internet, bv. "/etc/letsencrypt/live/*/cert.pem localhost:8443 example.com:443".
# Niet ingesteld = alle Let's Encrypt-certificaten op deze server; leeg ("") = geen.
CERTS="${CERTS-/etc/letsencrypt/live/*/cert.pem}"
# Certificaten veranderen zelden: elk uur opnieuw uitlezen is genoeg (seconden)
CERT_INTERVAL="${CERT_INTERVAL:-3600}"
# Certificaten zoeken (1.59.0): in deze mappen naar .crt/.pem/.cer, één keer per dag (of meteen als RzSense erom vraagt).
# In RzSense kies je welke bewaakt worden; extra mappen stel je daar in (Instellingen → Agents). Leeg ("") = niet zoeken.
CERT_DIRS="${CERT_DIRS-/etc/ssl /etc/pki /etc/letsencrypt /etc/nginx /etc/apache2 /etc/httpd /etc/haproxy /etc/lighttpd /etc/caddy /etc/traefik /etc/postfix /etc/dovecot /etc/mosquitto /etc/cockpit /etc/pve /etc/ipa /opt /srv /usr/local/etc /var/lib/docker/volumes /config /ssl}"
# Diepere controle van services: draait het proces, luistert het op zijn poorten, antwoordt het
# (HTTP, database, SSH, mail, DNS…), herstarts en fouten in het log. SERVICE_DEEP=0 zet het uit.
SERVICE_DEEP="${SERVICE_DEEP:-1}"
# Eigen tests: een uitvoerbaar script /etc/rzmon-agent/tests/<service> (van root, niet door anderen
# te wijzigen). Exitcode 0 = OK, 1 = WARNING, 2 = CRITICAL; de eerste regel uitvoer is het bericht.
SERVICE_TEST_DIR="${SERVICE_TEST_DIR:-/etc/rzmon-agent/tests}"
# Logbestanden doorzoeken (zoekopdrachten stel je in RzSense in): alleen bestanden in deze mappen
# mogen gelezen worden, zodat via RzSense niet zomaar elk bestand uitgelezen kan worden.
LOG_DIRS="${LOG_DIRS:-/var/log}"
# Podman: wordt alleen gemeld als podman op deze server staat. PODMAN=0 zet het uit.
PODMAN="${PODMAN:-1}"
# Agent 1.69.0: Docker-containers net als die van Podman (zelfde checks, herstarten, zelf herstellen); DOCKER=0 = niet
DOCKER="${DOCKER:-1}"
# Gebruikers met rootless containers: "auto" = zelf zoeken (gebruikers met ~/.local/share/containers),
# of een lijst namen; "" = alleen de containers van root
PODMAN_USERS="${PODMAN_USERS-auto}"
# Bijwerken vanuit RzSense (knop "Agent bijwerken" of automatisch). REMOTE_UPDATE=0: alleen met de hand
# (sudo rzmon-agent-setup --update). De nieuwe versie komt altijd van je eigen RzSense en wordt eerst
# gecontroleerd (SHA-256, geldig script, juiste versie) voordat hij geïnstalleerd wordt.
REMOTE_UPDATE="${REMOTE_UPDATE:-1}"
# Installatiescript voor bijwerken vanuit RzSense (op TrueNAS staat het op de pool, naast de agent)
AGENT_SETUP="${AGENT_SETUP:-/usr/local/sbin/rzmon-agent-setup}"
# Commando-checks (＋ Check → Commando in RzSense): RzSense bepaalt of ze mogen en als welke gebruiker
# (Instellingen → Agents). Hier op de server kun je dat inperken: COMMANDS=0 = nooit uitvoeren,
# COMMANDS=nobody = nooit als root. Leeg = wat RzSense zegt.
COMMANDS="${COMMANDS-}"
# Zelf herstellen (agent 1.62.0, per check in RzSense aan te zetten): een service of container die niet draait, herstart
# de agent zelf; een eigen herstelcommando staat alleen hier, in HEAL_DIR (uitvoerbaar, van root, niet door anderen te
# wijzigen). HEAL=0 = op deze server nooit zelf herstellen.
HEAL="${HEAL:-1}"
HEAL_DIR="${HEAL_DIR:-/etc/rzmon-agent/heal.d}"
# Waakhond (agent 1.62.0, per agent in RzSense aan te zetten): Pushover als RzSense onbereikbaar is of de checks stilstaan.
# WATCHDOG=0 = op deze server nooit.
WATCHDOG="${WATCHDOG:-1}"
PUSHOVER_API="${PUSHOVER_API:-https://api.pushover.net/1/messages.json}"
# Logins (SSH en console), sudo en su melden op de pagina Meldingen, als dat in RzSense aan staat
# (Instellingen → Alarmgedrag). AUTH_EVENTS=0 zet het op deze server altijd uit.
AUTH_EVENTS="${AUTH_EVENTS-}"
# IDS (pagina 🛡️ IDS in RzSense): IDS_BLOCK=0 = op deze server nooit blokkeren, ook als RzSense op
# "blokkeren" staat (detecteren gaat door).
IDS_BLOCK="${IDS_BLOCK-}"
# Zonder journal (of met AUTH_SOURCE=file): deze logbestanden, de eerste die bestaat
AUTH_LOGS="${AUTH_LOGS:-/var/log/secure /var/log/auth.log}"
SUPERVISOR_URL="${SUPERVISOR_URL:-http://supervisor}"
# Realtime-watcher (dienst rzmon-watch, aan/uit per server in RzSense): REALTIME=0 zet hem op deze server altijd uit.
REALTIME="${REALTIME-}"

HA_MODE=0
[ -n "${SUPERVISOR_TOKEN:-}" ] && HA_MODE=1

# 1.95.0: luisterende poorten één keer per ronde lezen en voor alle onderdelen gebruiken (eerst las de agent `ss`
# tot negen keer per ronde). $1: t = tcp (ss -Htlnp), tu = tcp en udp (ss -Htulnp, met de soort als eerste kolom).
# Bewaard in de cachemap (20 seconden), zodat het ook werkt binnen $(…); zonder cachemap gewoon direct.
ss_listen() {
    local o="-Htlnp" f
    [ "$1" = tu ] && o="-Htulnp"
    if [ -z "${t_dir:-}" ] || [ ! -d "$t_dir" ] || [ ! -w "$t_dir" ]; then ss "$o" 2>/dev/null; return 0; fi
    f="$t_dir/ss_$1"
    if [ ! -s "$f" ] || [ $(( $(date +%s) - $(stat -c %Y "$f" 2>/dev/null || echo 0) )) -ge 20 ]; then
        if ss "$o" > "$f.$$" 2>/dev/null; then mv -f "$f.$$" "$f"; else rm -f "$f.$$"; fi
    fi
    cat "$f" 2>/dev/null
}
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
    function red(s,   o, l) { o = ""   # agent 1.86.1: tokens en wachtwoorden in een adres weglakken (?token=***)
        while ((l = tolower(s)) != "" && match(l, /[?&;](pass|passwd|password|pwd|[a-z_]*token|api_?key|apikey|secret|key|auth|authsig|session|sid|jwt|code|sig|signature|otp|ticket)=/)) {
            o = o substr(s, 1, RSTART + RLENGTH - 1) "***"; s = substr(s, RSTART + RLENGTH)
            if (match(s, /[&;# ]/)) s = substr(s, RSTART); else s = "" }
        return o s }
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
        printf "%s[\"%s\",\"%s\",\"%s\",\"%s\",%d,%d,\"%s\",\"%s\",\"%s\",\"%s\"]", (n > 1 ? "," : ""), ip, esc(tm), esc(substr(m, 1, 20)), esc(substr(red(uri), 1, 512)),
            st, by, esc(substr(ua, 1, 200)), esc(substr(red(ref), 1, 200)), esc(substr($8, 1, 120)), esc(substr($10, 1, 80))
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
    lp=$(ss_listen t | awk '{ n = split($4, a, ":"); print a[n] }' | sort -u | tr '\n' ' ')
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
    out=$(ss_listen t | awk -v sf="$edr_dir/listen.state" -v first="$1" '
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

# ---------------------------------------------------------------- Logins lezen (agent 1.74.0)
# Regels "epoch <tab> programma <tab> bericht" (stdin, uit journal of auth-log) → auth_json voor RzSense:
#  - events: geslaagde logins (SSH met bronpoort, console), sudo, su, en het einde van een SSH-sessie (ssh_end)
#  - fails:  mislukte pogingen opgeteld per soort/IP/gebruiker (zoals altijd: brute force, Toegang)
#  - tries:  1.74.0: de losse mislukte SSH-pogingen (tijd, IP, gebruiker, bronpoort; hooguit 200 per rapport),
#            voor de aanvalskaart en de tijdlijn in RzSense
# Wachtwoorden in sudo-commando's (bv. mysql -pGeheim, password=…) worden hier al gemaskeerd.
# Testen zonder RzSense (tests/agent-edr.sh): rzmon-agent.sh --auth-parse < bestand
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

# ---------------------------------------------------------------- Heartbeat (rzmon-hb, agent 1.52.0)
# Een script of cronjob meldt zich bij RzSense: rzmon-hb NAAM [ok|start|fail] [bericht] [--value N].
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
        echo "  NAAM = de naam van de heartbeat-check in RzSense (letters, cijfers, punt, - of _), bv. backup-db" >&2
        exit 2
    fi
    if [ -n "$hb_val" ] && ! [[ "$hb_val" =~ ^-?[0-9]+([.][0-9]+)?$ ]]; then
        echo "rzmon-hb: --value moet een getal zijn (bv. 42 of 3.5); genegeerd" >&2; hb_val=""
    fi
    [ -d "$hb_dir" ] || { mkdir -p "$hb_dir" && chmod 1733 "$hb_dir"; } 2>/dev/null
    hb_f=$(mktemp "$hb_dir/hb.XXXXXXXX" 2>/dev/null) || { echo "rzmon-hb: kan niet schrijven in $hb_dir (is de agent geïnstalleerd?)" >&2; exit 1; }
    printf '{"n":"%s","s":"%s","t":%s%s,"m":"%s"}\n' "$hb_name" "$hb_kind" "$(date +%s)" "${hb_val:+,\"v\":$hb_val}" \
        "$(printf '%s' "$hb_msg" | head -c 300 | json_text)" >"$hb_f"
    chmod 600 "$hb_f" 2>/dev/null   # agent 1.87.0: alleen de eigenaar (en root, de agent)
    exit 0
fi

t_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
[ "$HA_MODE" = 1 ] && t_dir="${CACHE_DIRECTORY:-/data/rzmon-cache}"   # add-on: /data blijft bewaard
# Agent 1.87.0: niet schrijfbaar (geen root)? Dan een eigen map in je home, of een nieuwe privé-map; nooit los in /tmp,
# waar iemand anders de bestandsnamen kan voorzijn
if ! { mkdir -p "$t_dir" 2>/dev/null && [ -w "$t_dir" ]; }; then
    t_dir="${XDG_CACHE_HOME:-${HOME:-/nonexistent}/.cache}/rzmon-agent"
    { mkdir -p "$t_dir" && chmod 700 "$t_dir"; } 2>/dev/null && [ -w "$t_dir" ] || t_dir=$(mktemp -d "${TMPDIR:-/tmp}/rzmon-agent.XXXXXX") || exit 1
fi
t_cache="$t_dir/tests.cache"

# ---------------------------------------------------------------- Versleuteling (RzSense 1.57.0+)
# Met een sleutel gaan rapporten versleuteld naar RzSense (AES-256-CBC + HMAC-SHA256, met openssl) en komt het
# antwoord versleuteld terug; zie src/AgentCrypto.php in RzSense. De sleutel: ENC_KEY in /etc/default/rzmon-agent,
# of de sleutel die RzSense zelf meestuurt (enc.key, alleen leesbaar voor root; die gaat voor, zo werkt een nieuwe
# sleutel vanzelf). Staat versleutelen in RzSense uit voor deze server, dan zet RzSense enc.off neer.
#
# Beveiligde verbinding (agent 1.50.0+, RzSense 1.64.0+): een eigen sleutel voor deze server (agent.key). Die staat
# in een vaste map, niet in de cache: in de HA-add-on /data/rzmon-keys, op TrueNAS in de map van de agent, anders
# /var/lib/rzmon-agent (alleen root). Met een eigen sleutel stuurt de agent geen token meer mee, alleen het kenmerk
# van zijn sleutel, en is elk verzoek versleuteld of ondertekend. De sleutel komt bij het koppelen (koppelcode:
# PAIR_CODE) of, als de agent al met de algemene sleutel werkte, in een versleuteld antwoord van RzSense.
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
# Agent 1.87.0: HMAC-SHA256 zelf uitrekenen met openssl dgst over stdin, zodat geen sleutel op de opdrachtregel staat
# (die is voor iedereen op de server te zien in ps of /proc/*/cmdline). Uitkomst gelijk aan openssl -mac HMAC.
hmac_hex() {  # hmac_hex <hexsleutel>: HMAC-SHA256 van stdin (hex)
    local k=$1 ip="" op="" i x h
    while [ "${#k}" -lt 128 ]; do k+="00"; done
    for ((i = 0; i < 128; i += 2)); do
        printf -v x '\\x%02x' $(( 16#${k:i:2} ^ 0x36 )); ip+=$x
        printf -v x '\\x%02x' $(( 16#${k:i:2} ^ 0x5c )); op+=$x
    done
    h=$({ printf '%b' "$ip"; cat; } | openssl dgst -sha256 2>/dev/null | sed 's/^.*= *//')
    [ "${#h}" = 64 ] || return 1
    x=""
    for ((i = 0; i < 64; i += 2)); do x+="\\x${h:i:2}"; done
    { printf '%b' "$op"; printf '%b' "$x"; } | openssl dgst -sha256 2>/dev/null | sed 's/^.*= *//'
}
enc_sub() {  # enc_sub <sleutel> <label>: afgeleide sleutel (HMAC-SHA256, hex)
    printf '%s' "$2" | hmac_hex "$1"
}
seal() {  # seal <invoer> <uitvoer>: versleutelen met enc_key; de nonce staat daarna in seal_n
    local ke km iv t d m
    ke=$(enc_sub "$enc_key" enc) && km=$(enc_sub "$enc_key" mac) && iv=$(openssl rand -hex 16) && seal_n=$(openssl rand -hex 16) || return 1
    t=$(date +%s)
    d=$(openssl enc -aes-256-cbc -K "$ke" -iv "$iv" -in "$1" 2>/dev/null | base64 -w0) && [ -n "$d" ] || return 1
    m=$(printf 'rzenc1|%s|%s|%s|%s' "$t" "$seal_n" "$iv" "$d" | hmac_hex "$km")
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
    [ "$(printf 'rzenc1r|%s|%s|%s|%s' "$t" "$rn" "$iv" "$d" | hmac_hex "$km")" = "$m" ] || return 1
    printf '%s' "$d" | base64 -d 2>/dev/null | openssl enc -d -aes-256-cbc -K "$ke" -iv "$iv" 2>/dev/null
}
# Agent 1.87.0: het token niet als argument aan curl (dan staat het in ps), maar in een curl-configbestand dat
# alleen root kan lezen. Lukt dat niet, dan zoals voorheen.
tok_cfg="$k_dir/token.curl"
tok_auth() {  # tok_auth: zet tok_h op de curl-argumenten voor het token
    local t=${TOKEN//\\/\\\\}
    t=${t//\"/\\\"}
    if [ -n "$TOKEN" ] && (umask 077 && printf 'header = "X-Agent-Token: %s"\n' "$t" >"$tok_cfg.new" && mv -f "$tok_cfg.new" "$tok_cfg") 2>/dev/null; then
        tok_h=(-K "$tok_cfg")
    else
        tok_h=(-H "X-Agent-Token: $TOKEN")
    fi
}
# Wie we zijn: met de eigen sleutel alleen het kenmerk (X-Agent-Key), anders het token
set_auth() {
    kid=""
    if [ -n "$own_key" ] && [ "$enc_key" = "$own_key" ]; then
        kid=$(enc_sub "$own_key" id | cut -c1-16)
        auth=(-H "X-Agent-Key: $kid")
        rm -f "$tok_cfg" 2>/dev/null
    else
        tok_auth
        auth=("${tok_h[@]}")
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
        s=$(printf 'rzget1|%s|%s|%s' "$1" "$t" "$get_n" | hmac_hex "$(enc_sub "$own_key" mac)")
        ga=(-H "X-Agent-Auth: $kid:$t:$get_n:$s")
    else
        tok_auth
        ga=("${tok_h[@]}")
        if [ -n "$enc_key" ]; then get_n=$(openssl rand -hex 16) && ga+=(-H "X-Agent-Enc: $(enc_sub "$enc_key" id | cut -c1-16):$get_n"); fi
    fi
}

# Adres van RzSense (agent 1.51.0, RzSense 1.72.0). RzSense kan de agent een ander adres geven (bv. https via de reverse
# proxy); de agent test het eerst en bewaart het dan in $k_dir/url (regel 1: het adres, 2: het adres uit de instellingen
# waar het voor in de plaats komt, 3: het reserve-adres). Pas je MONITOR_URL zelf aan, dan geldt weer dat. Het
# reserve-adres (MONITOR_URL_FALLBACK, of uit $k_dir/url) wordt gebruikt als het gewone adres niet bereikbaar is
# (geen verbinding, certificaat, of de proxy geeft 502-504); RzSense toont dat met een ⚠️.
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

# Controle per adres (agent 1.53.0, RzSense 1.78.0): in $k_dir/url.pins staat per adres hoe het certificaat gecontroleerd
# wordt: "-" = gewoon (geldig certificaat, of http), "sha256//…" = alleen het eigen certificaat van RzSense (vastzetten;
# met een klaargezet nieuw certificaat twee vingerafdrukken, "sha256//A;sha256//B"). RzSense geeft dit door in elk
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

# Overstappen naar een nieuw adres (opdracht uit een versleuteld antwoord van RzSense): eerst testen met een ondertekend
# verzoek; alleen als het antwoord met onze eigen sleutel klopt, gaat de agent dat adres gebruiken (vanaf de volgende
# keer). De uitkomst gaat met het volgende rapport naar RzSense.
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
        printf 'error: curl %s op deze server kan het certificaat van RzSense niet vastzetten (7.44 of nieuwer nodig)\n' "$c_ver" >"$k_dir/url.result"
        return 0
    fi
    spec_tls "$new" "$n_spec"; t_tls=("${s_tls[@]}")
    get_auth agent_check
    # Ook het IP waarmee curl verbond: RzSense ziet daaraan of de naam hier naar de proxy wijst of via internet gaat
    out=$(curl -sS -m 15 "${t_tls[@]}" "${ga[@]}" -w '\n%{http_code} %{remote_ip}' "$new/api.php?action=agent_check" 2>&1)
    code=${out##*$'\n'}; out=${out%$'\n'*}
    rip=${code#* }; code=${code%% *}
    [[ "$rip" =~ ^[0-9a-fA-F.:]+$ ]] || rip=
    if [ "$code" = 200 ] && [ -n "$get_n" ] && body=$(unseal "$out" "$get_n") && [[ "$body" == *'"ok":true'* ]]; then
        if (umask 077; printf '%s\n%s\n%s\n' "$new" "$conf_url" "$fb" >"$url_file.tmp" && mv -f "$url_file.tmp" "$url_file"); then
            url_pins_put "$new" "$n_spec" "$fb" "$f_spec"
            printf 'ok %s%s\n' "$new" "${rip:+ [ip $rip]}" >"$k_dir/url.result"
            echo "Adres van RzSense gewijzigd naar $new${fb:+ (reserve: $fb)}" >&2
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

# Certificaat van RzSense vastzetten: bij een eigen (self-signed) certificaat accepteert de agent alleen dat ene
# certificaat (curl --pinnedpubkey). De vingerafdruk komt uit het installatiecommando (PIN) en daarna uit de
# versleutelde antwoorden van RzSense ("" = niet vastzetten). Zonder vingerafdruk: INSECURE=1 als vroeger.
curl_pins=0
c_ver=$(curl -V 2>/dev/null | awk 'NR == 1 { print $2 }'); IFS=. read -r c_v1 c_v2 _ <<<"$c_ver"
{ [ "${c_v1:-0}" -gt 7 ] || { [ "${c_v1:-0}" = 7 ] && [ "${c_v2:-0}" -ge 44 ]; }; } 2>/dev/null && curl_pins=1   # sha256-pins: curl 7.44+
set_tls() {
    pin=""
    u_spec=$(url_spec "$MONITOR_URL")
    if [ -n "$u_spec" ]; then   # agent 1.53.0: RzSense gaf voor dit adres door hoe het gecontroleerd wordt
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
store_pin() {  # store_pin <versleuteld antwoord>: vingerafdruk van RzSense bewaren, of ("") niet meer vastzetten
    [[ "$1" =~ \"pin\":\"([A-Za-z0-9+/=\\]{0,90})\" ]] || return 0
    local p=${BASH_REMATCH[1]//\\/}
    if [ -n "$p" ]; then
        [ "$(cat "$k_dir/pin" 2>/dev/null)" = "$p" ] || (umask 077; printf '%s\n' "$p" >"$k_dir/pin")
        rm -f "$k_dir/pin.off"
    else
        rm -f "$k_dir/pin"; : >"$k_dir/pin.off"
    fi
}
store_key() {  # store_key <versleuteld antwoord>: eigen sleutel van RzSense bewaren (eerste keer, of vernieuwd)
    [[ "$1" =~ \"agent_key\":\"([0-9a-f]{64})\" ]] || return 0
    [ "${BASH_REMATCH[1]}" = "$own_key" ] && return 0
    (umask 077; printf '%s\n' "${BASH_REMATCH[1]}" >"$own_file.tmp" && mv -f "$own_file.tmp" "$own_file") || return 1
    own_key=${BASH_REMATCH[1]}
}

# Koppelen met een koppelcode (eenmalig; RzSense → Agent Manager → 🔗 Koppelcode). Het verzoek is versleuteld met een
# sleutel uit de code; RzSense antwoordt (ook versleuteld) met de eigen sleutel van deze server. Een code die al
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
        [ "$code" = 200 ] && echo "  Het antwoord van RzSense klopt niet (handtekening); niet gekoppeld." >&2
        return 1
    fi
    p_key=$(sed -n 's/.*"agent_key":"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$body")
    [ -n "$p_key" ] || { echo "Koppelen mislukt: geen sleutel in het antwoord" >&2; return 1; }
    own_key=""; store_key "$body" || { echo "Koppelen mislukt: kan $own_file niet schrijven" >&2; return 1; }
    printf '%s' "$pair_code" | sha256sum | cut -c1-16 >"$k_dir/pair.used"
    rm -f "$t_dir/enc.off" "$t_dir/enc.fail"
    store_pin "$body"
    echo "🔑 Gekoppeld met RzSense (server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$body")): eigen sleutel ontvangen" >&2
}
pair_done() { [ "$(cat "$k_dir/pair.used" 2>/dev/null)" = "$(printf '%s' "$pair_code" | sha256sum | cut -c1-16)" ]; }
if [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1 && ! pair_done; then
    # Agent 1.68.0: één run tegelijk koppelt (de code werkt maar één keer). Bv. de timer die net start en het
    # testrapport van de installatie: de tweede wacht en gebruikt dan de sleutel die de eerste kreeg.
    if command -v flock >/dev/null 2>&1; then
        exec 8>"$k_dir/pair.lock"
        flock -w 30 8 2>/dev/null || flock 8 2>/dev/null || true
    fi
fi
if [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1 && pair_done; then
    # Al gekoppeld (eventueel net door een andere run): de eigen sleutel gebruiken
    if [ -z "$own_key" ] && [ -r "$own_file" ]; then
        own_key=$(tr -dc '0-9a-f' <"$own_file" | head -c 64)
        [ "${#own_key}" = 64 ] || own_key=""
        if [ -n "$own_key" ] && [ ! -e "$t_dir/enc.off" ]; then enc_key=$own_key; set_auth; set_tls; fi
    fi
elif [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1; then
    prev_key=$enc_key
    if pair_agent; then enc_key=$own_key
    else
        enc_key=$prev_key
        # Zonder sleutel en zonder token valt er niets te versturen
        [ -z "$enc_key" ] && ! [[ "$TOKEN" =~ ^[A-Za-z0-9]{16,128}$ ]] && [ "$TEST" != 1 ] && exit 1
    fi
    set_auth; set_tls
fi
{ exec 8>&-; } 2>/dev/null   # 1.73.0: alleen dit stil (was: alle foutmeldingen van de rest van de run naar /dev/null)

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
    echo "RzSense agent $AGENT_VERSION – verbindingstest"
    echo "  RzSense-adres : $base$([ "$url_set_by_rzmon" = 1 ] && echo " (ingesteld vanuit RzSense; in de instellingen staat $conf_url)")"
    [ -n "$MONITOR_URL_FALLBACK" ] && echo "  Reserve     : $MONITOR_URL_FALLBACK (als het adres hierboven niet bereikbaar is)"
    for t_u in "$base" "$MONITOR_URL_FALLBACK"; do
        case "$(url_spec "$t_u")" in
            sha256//*) echo "  Controle    : $t_u → 📌 alleen het eigen certificaat van RzSense (vastgezet)" ;;
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
        localhost|127.*|::1) note "Het adres wijst naar deze server zelf ($host): alleen goed als RzSense óp deze server draait"
            hint "Anders: gebruik het IP-adres of de naam van de RzSense-server, bv. http://192.168.1.10" ;;
    esac

    # 3. DNS
    if [[ "$host" =~ ^[0-9.]+$ ]] || getent hosts "$host" >/dev/null 2>&1; then
        pass "Naam opzoeken: $host → $(getent hosts "$host" 2>/dev/null | awk '{print $1; exit}' || echo "$host")"
    else
        fail "De naam '$host' kan niet worden opgezocht (DNS)"
        hint "Gebruik het IP-adres van RzSense, of zet de naam in /etc/hosts"
        stop
    fi

    # 4. Netwerk: kan deze server de poort bereiken?
    if timeout 5 bash -c "exec 3<>/dev/tcp/$host/$port" 2>/dev/null; then
        pass "Verbinding met $host poort $port"
    else
        fail "Geen verbinding met $host poort $port"
        hint "Draait de webserver op RzSense? (systemctl status httpd/apache2/nginx)"
        hint "Firewall op de RzSense-server? Rocky: sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload"
        hint "Zitten deze server en RzSense in hetzelfde netwerk / mag het verkeer ertussen (VLAN, router)?"
        stop
    fi
    if [ -n "${http_proxy:-}${HTTP_PROXY:-}${https_proxy:-}${HTTPS_PROXY:-}" ]; then
        hint "Let op: er is een proxy ingesteld (http_proxy/https_proxy). Zet RzSense zo nodig in no_proxy."
    fi

    # Agent 1.87.0: eigen tijdelijke map (geen vaste naam in /tmp)
    tt_dir=$(mktemp -d "${TMPDIR:-/tmp}/rzmon-test.XXXXXX") || tt_dir=$t_dir
    curl_t=(-sS -m 15 -o "$tt_dir/body" -w '%{http_code} %{redirect_url}' "${tls[@]}")

    # 5. Draait daar RzSense?
    read -r code redirect < <(curl "${curl_t[@]}" "$base/api.php?action=ping" 2>"$tt_dir/err" || true)
    body=$(cat "$tt_dir/body" 2>/dev/null); err=$(cat "$tt_dir/err" 2>/dev/null)
    old_rzmon=0
    if [ "$code" = 200 ] && [[ "$body" == *'"app":"RzMon"'* ]]; then
        rz_version=$(sed -n 's/.*"version":"\([^"]*\)".*/\1/p' <<<"$body")
        # Nieuwste agent (eigen nummer sinds RzSense 1.46.0); oudere RzSense: gelijk aan de versie van RzSense
        rz_agent=$(sed -n 's/.*"agent_version":"\([^"]*\)".*/\1/p' <<<"$body")
        pass "RzSense ${rz_version:+$rz_version }gevonden op $base"
        if [ -n "${rz_agent:-$rz_version}" ] && [ "${rz_agent:-$rz_version}" != "$AGENT_VERSION" ]; then
            hint "Agent is versie $AGENT_VERSION, de nieuwste is ${rz_agent:-$rz_version}: bijwerken met  sudo rzmon-agent-setup --update"
        fi
    elif [ "$code" = 401 ] && [[ "$body" == *'"error"'* ]]; then
        pass "RzSense gevonden op $base (oudere versie: werk RzSense bij voor een volledige test)"
        old_rzmon=1
    elif [[ "$code" =~ ^30[1278]$ ]]; then
        fail "RzSense stuurt door naar: $redirect"
        hint "Gebruik dat adres als MONITOR_URL (bv. https:// i.p.v. http://): sudo rzmon-agent-setup --url ${redirect%/api.php*}"
    elif [ "$code" = 404 ]; then
        fail "Er draait wel een webserver op $base, maar niet RzSense (404)"
        hint "Klopt het pad? RzSense staat op de hoofd-URL van de vhost, bv. http://192.168.1.10/ — niet /rzmon/public/"
    elif [[ "$err" == *SSL* || "$err" == *certificate* ]]; then
        fail "Certificaatprobleem: ${err#curl: }"
        hint "Self-signed certificaat? Zet INSECURE=\"1\" in /etc/default/rzmon-agent (of: sudo rzmon-agent-setup --insecure)"
    elif [ "$code" = 000 ]; then
        fail "Geen antwoord van $base: ${err#curl: }"
    else
        fail "Onverwacht antwoord van $base (HTTP $code)"
        [ -n "$body" ] && hint "${body:0:200}"
    fi
    if [ "$TEST_FAILED" = 1 ]; then rm -f "$tt_dir/body" "$tt_dir/err"; [ "$tt_dir" != "$t_dir" ] && rmdir "$tt_dir" 2>/dev/null; stop; fi

    # 6. Token, of de eigen sleutel (dan is het antwoord versleuteld: zo weet de agent dat het echt RzSense is)
    if [ -n "$pin" ] && [ "${#tls[@]}" -gt 1 ]; then pass "Certificaat van RzSense vastgezet (📌 sha256//${pin:0:12}…)"
    elif [ -n "$pin" ]; then hint "Vingerafdruk van RzSense bekend, maar deze curl ($c_ver) kan geen certificaat vastzetten (7.44 of nieuwer nodig)"; fi
    curl_k=(-sS -m 15 "${tls[@]}")
    get_auth agent_check
    tk=$(curl "${curl_k[@]}" "${ga[@]}" -w '\n%{http_code}' "$base/api.php?action=agent_check" 2>/dev/null || true)
    tk_code=${tk##*$'\n'}; tk_body=${tk%$'\n'*}
    if [ "$tk_code" = 200 ] && [ -n "$kid" ]; then
        if tk_body=$(unseal "$tk_body" "$get_n"); then
            pass "🔑 Eigen sleutel geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzSense ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
        else
            fail "Het antwoord van RzSense is niet met de sleutel van deze server ondertekend: praat de agent wel met RzSense?"
        fi
    elif [ "$tk_code" = 200 ]; then
        pass "Token geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzSense ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
    elif [ "$tk_code" = 403 ]; then
        fail "$([ -n "$kid" ] && echo "Sleutel" || echo "Token") geweigerd: $(sed -n 's/.*"reason":"\([^"]*\)".*/\1/p' <<<"$tk_body")"
        hint "Maak in RzSense een koppelcode (server → Agent Manager → 🔗 Koppelcode) en voer het commando uit"
        rm -f "$tt_dir/body" "$tt_dir/err"; [ "$tt_dir" != "$t_dir" ] && rmdir "$tt_dir" 2>/dev/null
        stop
    elif [ "$old_rzmon" = 1 ]; then
        hint "Tokencontrole overgeslagen (oudere RzSense-versie); het proefrapport hieronder test het token wel"
    else
        fail "Tokencontrole gaf HTTP $tk_code: ${tk_body:0:150}"
    fi
    rm -f "$tt_dir/body" "$tt_dir/err"; [ "$tt_dir" != "$t_dir" ] && rmdir "$tt_dir" 2>/dev/null
fi

# ---------------------------------------------------------------- Status en instellingen (1.60.0)
# Elk contact met RzSense wordt bijgehouden in $st_dir (laatste rapport, de laatste 30 contacten, fouten en de
# beveiligingscontrole van RzSense), voor "rzmon-agent-setup --status". Daarnaast meldt de agent welke regels in
# zijn instellingen overbodig zijn (alleen de namen, nooit de waarden), zodat RzSense kan laten opruimen.
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
    conf_has INTERVAL && echo INTERVAL   # de tik komt uit RzSense
    if conf_has PIN; then v=$(conf_val PIN); { [ -z "$v" ] || [ -s "$k_dir/pin" ] || [ -s "$k_dir/url.pins" ]; } && echo PIN; fi
    if conf_has INSECURE; then
        v=$(conf_val INSECURE); { [ "$v" != 1 ] || [[ "$MONITOR_URL" != https://* ]] || [ -n "$pin" ] || [ -n "$(url_spec "$MONITOR_URL")" ]; } && echo INSECURE
    fi
    conf_has SERVICES && echo SERVICES   # wordt een service-check in RzSense
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
    printf '%sRzSense agent %s%s – status van %s op %s\n' "$b1" "$AGENT_VERSION" "$b0" "$(hostname)" "$(date '+%d-%m-%Y %H:%M:%S')"

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

    hd "Verbinding met RzSense"
    sl "Adres:" "$MONITOR_URL"
    [ -n "$MONITOR_URL_FALLBACK" ] && sl "Reserve-adres:" "$MONITOR_URL_FALLBACK (als het gewone adres niet bereikbaar is)"
    if [[ "$MONITOR_URL" != https://* ]]; then sl "Certificaat:" "⚠️ via http: de inhoud is versleuteld, maar niet verborgen dat er verkeer is (zet RzSense op https)"
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
            pm=""; [ -n "$pin" ] && { [ "$c_pin" = "$pin" ] && pm=" · vingerafdruk klopt ✔" || pm=" · ⚠️ vingerafdruk wijkt af (RzSense stuurt de nieuwe bij het volgende rapport)"; }
            sl "Certificaat RzSense:" "${c_cn:-?}, geldig tot ${c_na:-?}$pm"
        fi
    fi

    hd "Versleuteling"
    if [ -n "$own_key" ]; then
        sl "Sleutel:" "🔑 eigen sleutel van deze server (kenmerk $(enc_sub "$own_key" id | cut -c1-16)), sinds $(dt "$(stat -c %Y "$own_file")")"
        sl "" "RzSense vernieuwt hem vanzelf; er gaat geen token of sleutel over de lijn"
    elif [ -n "$enc_key" ]; then sl "Sleutel:" "⚠️ alleen de oude algemene sleutel: koppel opnieuw met een koppelcode (rzmon-agent-setup --pair …)"
    else sl "Sleutel:" "⚠️ geen sleutel: rapporten gaan onversleuteld met een token. Koppel met: rzmon-agent-setup --pair …"; fi
    [ -n "$enc_key" ] && sl "Methode:" "AES-256-CBC + HMAC-SHA256; elk bericht uniek (nonce) en 5 minuten geldig; antwoorden van RzSense ondertekend"
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
        [ -n "$rv" ] && sl "RzSense ontving:" "$rv"
        pt=$(st_get parts | sed 's/ / · /g; s/=/: /g'); [ -n "$pt" ] && sl "Ook meegestuurd:" "$pt"
        ok_t=$(awk -F'\t' '$2 == 0 { t = $1 } END { print t }' "$st_dir/history" 2>/dev/null)
        [ -n "$ok_t" ] && [ "$l_rc" != 0 ] && sl "Laatst gelukt:" "$(dt "$ok_t") ($(ago_s "$ok_t"))"
    else
        sl "Laatste rapport:" "nog niet bijgehouden (komt na het volgende rapport)"
    fi
    # Agent 1.82.0: RzSense wordt bijgewerkt (aangekondigd door RzSense zelf)
    if [ -r "$k_dir/rzhold" ] && read -r _ h_u h_s _ <"$k_dir/rzhold" 2>/dev/null && [[ "$h_u" =~ ^[0-9]+$ && "$h_s" =~ ^[0-9]+$ ]]; then
        if [ "$h_s" -gt 0 ]; then sl "RzSense-update:" "wacht op de vrijgave sinds $(dt "$h_s"); alarm pas na $(dt "$h_u")"
        else sl "RzSense-update:" "aangekondigd; lukt een rapport niet, dan wacht de agent tot $(dt "$h_u")"; fi
        [ -s "$k_dir/queue.jsonl" ] && sl "Wachtrij:" "$(wc -l <"$k_dir/queue.jsonl") metingen"
    fi
    tk=$(awk -F'\t' '$1 == "0" && $2 == "tick" { print $3; exit }' "$t_cache" 2>/dev/null)
    if [ -n "$tk" ]; then
        sl "Ritme:" "elke $tk s (ingesteld in RzSense)$( [ -n "${l_t-}" ] && echo "; volgende rapport rond $(date -d "@$(( l_t + tk ))" '+%H:%M:%S' 2>/dev/null)")"
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
        sl "Bewaakt (RzSense):" "$n_svc services, $n_crt certificaten, $n_pr processen, $n_log logzoekopdrachten, $n_cmd commando's; IDS: $idsm"
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
        sl "" "instellingen van RzSense opgehaald $(ago_s "$(stat -c %Y "$t_cache")")"
    fi

    hd "Beveiligingscontrole door RzSense"
    if [ -s "$st_dir/sec" ]; then sed 's/^/  /' "$st_dir/sec"
    else echo "  (komt mee met het volgende rapport; RzSense 1.100.0 of nieuwer)"; fi
    echo
    exit 0
fi

# Supervisor API (alleen HA-modus)
sv() { curl -fsS -m 10 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SUPERVISOR_URL$1" 2>/dev/null; }

# ---------------------------------------------------------------- Instellingen uit RzSense en de tik
# Wat in RzSense is ingesteld: welke services bewaakt worden (service-checks), extra tests (URL/poort),
# de eigen poorten of sockets van een service, de logzoekopdrachten, de intervallen per check en de tik.
# De lijst wordt elke 10 minuten bij RzSense opgehaald, en meteen als RzSense meldt dat er iets veranderd is
# (tests_rev in het antwoord). Commando's van RzSense worden alleen uitgevoerd bij commando-checks, als RzSense
# ze voor deze server toestaat en COMMANDS (hierboven) dat niet blokkeert; zie "Commando-checks" verderop.
if [ "$TEST" = 1 ] || [ ! -r "$t_cache" ] || [ -e "$t_dir/tests.stale" ] || [ $(( $(date +%s) - $(stat -c %Y "$t_cache") )) -ge 600 ]; then
    get_auth agent_tests
    t_k=("${tls[@]}" "${ga[@]}"); t_n=$get_n
    # Alleen een complete lijst bewaren (met de vaste regel "0 <tab> tick"). Geeft RzSense iets anders terug,
    # bijvoorbeeld tijdens een update, dan blijft de vorige lijst staan: anders zou de agent denken dat het
    # IDS uit staat en de blokkades opheffen.
    # Met een sleutel moet de lijst versleuteld terugkomen; anders is hij niet te vertrouwen
    if t_list=$(curl -fsS "${t_k[@]}" -m 10 "${MONITOR_URL%/}/api.php?action=agent_tests" 2>/dev/null) \
        && { if [[ "$t_list" == '{"rzenc":'* ]]; then t_list=$(unseal "$t_list" "$t_n"); else [ -z "$t_n" ]; fi; } \
        && grep -q $'^0\ttick\t[0-9]' <<<"$t_list"; then
        (umask 077; printf '%s\n' "$t_list" >"$t_cache") 2>/dev/null && rm -f "$t_dir/tests.stale"
    fi
fi
# Tik: hoe vaak er een rapport uitgaat (regel "0 <tab> tick <tab> seconden", per server in RzSense in te stellen;
# anders INTERVAL). De timer start de agent vaker (elke 30 s); is de tik nog niet om, dan meteen stoppen.
tick=$(awk -F'\t' '$1 == "0" && $2 == "tick" { print $3; exit }' "$t_cache" 2>/dev/null)
[[ "$tick" =~ ^[0-9]+$ ]] && [ "$tick" -ge 30 ] || tick=${INTERVAL:-60}
[[ "$tick" =~ ^[0-9]+$ ]] || tick=60
if [ "$TEST" != 1 ] && [ "${RZ_FORCE-}" != 1 ] && [ -f "$t_dir/last.run" ] && [ $(( $(date +%s) - $(stat -c %Y "$t_dir/last.run") )) -lt $(( tick - 10 )) ]; then
    exit 0
fi
# Nooit twee runs tegelijk (timer en realtime-watcher): even wachten op een run die al bezig is
# Agent 1.68.0: de test wacht ook even (anders zitten twee runs aan dezelfde tijdelijke bestanden), maar gaat altijd door
if [ "$TEST" = 1 ] && command -v flock >/dev/null 2>&1; then
    exec 9>"$t_dir/run.lock"
    flock -w 25 9 2>/dev/null || true
elif [ "$TEST" != 1 ] && command -v flock >/dev/null 2>&1; then
    exec 9>"$t_dir/run.lock"
    # BusyBox-flock (Alpine, de Home Assistant-add-on) kent -w niet: dan zonder wachten proberen.
    # Lukt het allebei niet, dan loopt er al een run en slaan we deze keer over.
    if ! flock -w 25 9 2>/dev/null && ! flock -n 9 2>/dev/null; then
        exit 0
    fi
fi
touch "$t_dir/last.run" 2>/dev/null
run_t0=$(now_ms)
# Agent 1.69.0: duurt deze run langer dan de tik (een trage check, een druk systeem), dan elke tik een kleine hartslag
# "ik ben bezig" naar RzSense. Zo is de agent niet "stil" terwijl hij gewoon werkt; zijn gegevens blijven tot het rapport komt.
beat_pid=""
if [ "$TEST" != 1 ] && [ "${RZ_NO_BEAT-}" != 1 ]; then
    (
        b_since=$(date +%s)
        sleep "$tick"
        while kill -0 "$$" 2>/dev/null; do
            b_file=$(mktemp "${TMPDIR:-/tmp}/rzmon-beat.XXXXXX") || exit 0
            printf '{"beat":1,"agent_version":"%s","hostname":"%s","busy_since":%s}' "$AGENT_VERSION" "$(json_escape "$(hostname)")" "$b_since" >"$b_file"
            b_send=$b_file
            if [ -n "$enc_key" ] && seal "$b_file" "$b_file.enc"; then b_send="$b_file.enc"; fi
            curl -fsS -m 10 -X POST "${tls[@]}" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$b_send" \
                "${MONITOR_URL%/}/api.php?action=agent" >/dev/null 2>&1
            rm -f "$b_file" "$b_file.enc"
            sleep "$tick"
        done
    ) 9>&- </dev/null >/dev/null 2>&1 &   # 1.73.0: zonder de run-vergrendeling (fd 9), anders houdt zijn sleep die vast
    beat_pid=$!
fi
# Agent 1.69.0: hoe lang elk onderdeel duurde (gaat mee naar RzSense: Agent Manager toont wat traag is)
lap_t=$run_t0; lap_name=""; laps=""
lap() { local t; t=$(now_ms); [ -n "$lap_name" ] && laps+="\"$lap_name\":$(( t - lap_t )),"; lap_name=$1; lap_t=$t; }
# Agent 1.67.0: PID van deze run, zodat de endpoint-bewaking wat de agent start als "van de agent" herkent (de laatste 8)
[ "$TEST" != 1 ] && { tail -n 7 "$t_dir/own.pids" 2>/dev/null; echo "$$"; } >"$t_dir/own.pids.tmp" 2>/dev/null && mv -f "$t_dir/own.pids.tmp" "$t_dir/own.pids" 2>/dev/null

# ---------------------------------------------------------------- Disk
lap disk
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
lap containers_prep
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
# Agent 1.69.0: Docker als extra context "docker" (alleen als de Docker-daemon draait)
if [ "$DOCKER" != 0 ] && [ "$HA_MODE" = 0 ] && command -v docker >/dev/null 2>&1 && timeout 5 docker info >/dev/null 2>&1; then
    podman_ctx+=(docker)
fi
pm() {  # pm <context> <podman-argumenten…>: podman als root, of als die gebruiker (rootless); context docker = docker
    local ctx=$1 uid
    shift
    if [ "$ctx" = docker ]; then
        timeout 60 docker "$@"
    elif [ "$ctx" = root ] || [ "$ctx" = "$(id -un)" ]; then
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
# Docker meet CPU zelf (in %): die waarde gaat als twee "metingen" (0 en pct·10⁶ op 10⁸) door dezelfde berekening.
# Geheugen staat bij Docker in MiB/GiB, netwerk en disk in kB/MB: hier alles naar bytes.
docker_bytes='function db(s,  n, u) { if (!match(s, /^[0-9.]+[a-zA-Z]*/)) return 0; s = substr(s, 1, RLENGTH); n = s + 0; u = s; sub(/^[0-9.]+/, "", u); u = tolower(u)
    return n * (u == "kib" ? 1024 : u == "mib" ? 1048576 : u == "gib" ? 1073741824 : u == "tib" ? 1099511627776 : u == "kb" ? 1e3 : u == "mb" ? 1e6 : u == "gb" ? 1e9 : u == "tb" ? 1e12 : 1) }'
podman_stats() {  # podman_stats [a|b]
    local ctx
    for ctx in "${podman_ctx[@]}"; do
        if [ "$ctx" = docker ]; then
            if [ "${1:-b}" = a ]; then
                pm docker ps -q --no-trunc 2>/dev/null | sed 's/^/S|docker|/; s/$/|0|0|0|0|0B \/ 0B|0|0|0/'
            else
                pm docker stats --no-stream --no-trunc --format '{{.ID}}|{{.CPUPerc}}|{{.MemUsage}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}' 2>/dev/null |
                    awk -F'|' "$docker_bytes"'{ c = $2; sub(/%/, "", c); split($3, m, " / "); split($5, b, " / ")
                        printf "S|docker|%s|%.0f|100000000|%.0f|%.0f|%s|%.0f|%.0f|%s\n", $1, c * 1e6, db(m[1]), db(m[2]), $4, db(b[1]), db(b[2]), $6 }'
            fi
            continue
        fi
        pm "$ctx" stats --no-stream --no-trunc --format '{{.ContainerStats.ContainerID}}|{{.ContainerStats.CPUNano}}|{{.ContainerStats.SystemNano}}|{{.ContainerStats.MemUsage}}|{{.ContainerStats.MemLimit}}|{{.NetIO}}|{{.ContainerStats.BlockInput}}|{{.ContainerStats.BlockOutput}}|{{.ContainerStats.PIDs}}' 2>/dev/null \
            | sed "s/^/S|$ctx|/"
    done
}
pod_stats_a=""
[ ${#podman_ctx[@]} -gt 0 ] && pod_stats_a=$(podman_stats a)

# ---------------------------------------------------------------- CPU
lap cpu
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
lap ram
# Geheugen uit /proc/meminfo (kB -> bytes)
mem=$(awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ { v[$1] = $2 * 1024 }
    END { printf "{\"total\":%.0f,\"avail\":%.0f,\"swap_total\":%.0f,\"swap_free\":%.0f}",
          v["MemTotal:"], v["MemAvailable:"], v["SwapTotal:"], v["SwapFree:"] }' /proc/meminfo)

# ---------------------------------------------------------------- Poorten
lap ports
# Luisterende TCP-poorten (procesnamen alleen zichtbaar als root, niet in de HA-add-on)
ports=""
if command -v ss >/dev/null 2>&1; then
    ports=$(ss_listen t | awk '{
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

# Wat in RzSense is ingesteld (zie "Instellingen uit RzSense" bovenaan): hier alleen de regels voor services.
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
# Te bewaken services: die met een service-check in RzSense, plus (verouderd) SERVICES uit de instellingen
svc_all=$(printf '%s\n' $SERVICES $rz_services | awk 'NF && !seen[$0]++' | tr '\n' ' ')

# ---------------------------------------------------------------- Zelf herstellen (agent 1.62.0)
lap heal
# Per check in RzSense aan te zetten (✎ → 🩹 Zelf herstellen). Een service of container die niet draait, herstart de
# agent hier, vóór het rapport: lukt dat, dan blijft de check in RzSense OK en komt er een ℹ️-melding; lukt het niet, dan
# gaat de gewone melding uit. Een eigen herstelcommando (HEAL_DIR) start de agent alleen op verzoek van RzSense, in een
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
# Agent 1.89.0: logboek van wat de agent zelf op deze server verandert (firewall, EDR-regels, en later ransomware, updates,
# sudo …). Elke regel krijgt een eigen kenmerk; hij gaat mee met het volgende rapport en blijft bewaard tot RzSense hem heeft
# (hooguit 2000 regels; daarboven de oudste weg). RzSense toont hem in de Agent Manager (Logboek).
act_n=0
act_log() {  # act_log <onderdeel> <ok: 1 gelukt, 0 mislukt, 2 info> <actie> [detail]
    act_n=$(( act_n + 1 ))
    printf '{"id":"%s","t":%s,"m":"%s","ok":%s,"a":"%s","d":"%s"}\n' "$(date +%s)-$$-$act_n-$RANDOM" "$(date +%s)" "$(printf '%s' "$1" | tr -dc 'a-z')" \
        "$(printf '%s' "$2" | tr -dc '012' | cut -c1)" "$(json_escape "$(printf '%s' "$3" | tr '\n\t' '  ' | cut -c1-80)")" \
        "$(json_escape "$(printf '%s' "${4-}" | tr '\n\t' '  ' | cut -c1-900)")" >>"$t_dir/act.log" 2>/dev/null
    if [ "$(wc -l <"$t_dir/act.log" 2>/dev/null || echo 0)" -gt 2000 ]; then
        tail -n 2000 "$t_dir/act.log" >"$t_dir/act.log.tmp" 2>/dev/null && mv -f "$t_dir/act.log.tmp" "$t_dir/act.log"
    fi
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
lap services
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

    # Beschikbare updates (Core, Supervisor, OS, plugins en add-ons) → WARNING in RzSense
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

# Overzicht van de services op deze server (aan bij het opstarten, of draaiend), om in RzSense uit te kiezen.
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
lap ha
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
lap ha_os
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
    # Agent 1.88.0: de statistieken (CPU, geheugen, netwerk) van Core, Supervisor en de add-ons niet elke tik, maar eens per
    # STATS_INTERVAL minuten (standaard 5, 0 = nooit), met 4 tegelijk. Elke vraag kost de Supervisor ~2 s (hij vraagt het aan
    # Docker) en een regel in zijn log; met 20 add-ons was de agent zo elke minuut ~40 s bezig. Daartussen de laatste cijfers.
    # Of een add-on draait, komt gewoon elke tik uit de lijst van add-ons.
    st_min=${STATS_INTERVAL:-5}; [[ "$st_min" =~ ^[0-9]+$ ]] || st_min=5
    st_cache="$ha_dir/stats.cache"
    st_due=0
    if [ "$st_min" -gt 0 ]; then
        if [ ! -s "$st_cache" ] || [ $(( $(date +%s) - $(stat -c %Y "$st_cache" 2>/dev/null || echo 0) )) -ge $(( st_min * 60 - 15 )) ]; then st_due=1; fi
    else
        rm -f "$st_cache"
    fi
    if [ "$st_due" = 1 ]; then
        st_tmp=$(mktemp -d "$ha_dir/st.XXXXXX") && {
            st_get() {  # st_get <naam> <pad>: statistieken naar $st_tmp/<naam>
                local x
                x=$(sv "$2" | jq -c '.data // empty' 2>/dev/null)
                [ -n "$x" ] && printf '%s\t%s\n' "$1" "$x" >"$st_tmp/$1"
            }
            st_list=$(printf 'homeassistant /core/stats\nhassio_supervisor /supervisor/stats\n'
                [ -n "$addons" ] && jq -r '.data.addons[] | select(.state == "started") | "addon_\(.slug) /addons/\(.slug)/stats"' <<<"$addons" 2>/dev/null)
            st_pids=()   # alleen op deze vragen wachten (niet op andere achtergrondtaken van de agent)
            while read -r st_n st_p; do
                [ -n "$st_n" ] && [ -n "$st_p" ] || continue
                st_get "$st_n" "$st_p" &
                st_pids+=("$!")
                if [ "${#st_pids[@]}" -ge 4 ]; then wait "${st_pids[@]}"; st_pids=(); fi
            done <<<"$st_list"
            [ "${#st_pids[@]}" -gt 0 ] && wait "${st_pids[@]}"
            cat "$st_tmp"/* 2>/dev/null >"$st_cache.new" && mv -f "$st_cache.new" "$st_cache"
            rm -rf "$st_tmp"
        }
    fi
    declare -A ct_stats=()
    if [ -s "$st_cache" ]; then
        while IFS=$'\t' read -r st_n st_v; do [ -n "$st_n" ] && ct_stats[$st_n]=$st_v; done <"$st_cache"
    fi
    ct_raw=$(
        printf '%s\t%s\t%s\t%s\n' homeassistant "Home Assistant Core" "$([ "$core_state" = active ] && echo running || echo stopped)" "${ct_stats[homeassistant]:-null}"
        printf '%s\t%s\t%s\t%s\n' hassio_supervisor Supervisor running "${ct_stats[hassio_supervisor]:-null}"
        for p in dns audio cli multicast observer; do
            [ -n "${pl_stats[$p]-}" ] && printf '%s\t%s\t%s\t%s\n' "hassio_$p" "Plugin $p" running "${pl_stats[$p]}"
        done
        [ -n "$addons" ] && jq -r '.data.addons[] | [.slug, .name, .state] | @tsv' <<<"$addons" 2>/dev/null |
            while IFS=$'\t' read -r slug name state; do
                s=null; [ "$state" = started ] && s=${ct_stats[addon_$slug]:-null}
                printf '%s\t%s\t%s\t%s\n' "addon_$slug" "$name" "$state" "$s"
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
    command -v ss >/dev/null 2>&1 && listen_map=$(ss_listen t | awk '{
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
        # Zelf opgegeven poorten en sockets (in RzSense): altijd testen, en de dienst-test gebruikt ze
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

# Extra tests die in RzSense bij een service-check zijn ingesteld (URL of poort), zie hierboven.
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
# Regels "id <tab> cmd <tab> commando (base64) <tab> time-out <tab> nobody|root <tab> interval" uit RzSense.
# Uitvoeren in een kale omgeving, met time-out, standaard als nobody; uitvoer en exitcode gaan naar RzSense,
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
# Per zoekopdracht (ingesteld in RzSense): alleen de nieuwe regels sinds de vorige keer lezen,
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
        # Nog niet aan de beurt: "overgeslagen" melden (RzSense houdt de telling vast); de volgende keer
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
lap logins
# Als RzSense erom vraagt (regel "0 <tab> auth <tab> 1"): de nieuwe regels van het auth-log sinds de vorige
# keer (journal, anders /var/log/secure of /var/log/auth.log). Geslaagde logins (SSH, console), sudo en su
# gaan als losse gebeurtenissen naar RzSense; mislukte pogingen worden per soort/IP/gebruiker opgeteld.
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
# Als RzSense erom vraagt (regel "0 <tab> ids <tab> learn|block"): wat RzSense nodig heeft om afwijkingen te zien.
# Vergelijken gebeurt in RzSense (daar staat de basismeting, buiten bereik van een aanvaller op deze server).
#  - gevoelige bestanden (SHA-256), gebruikers, kernelmodules, verdachte processen, verdachte verbindingen
#  - SUID-bestanden (elk uur) en pakketcontrole rpm -Va / debsums (dagelijks), op de achtergrond
#  - blokkeren: bij "block" de IP's uit RzSense in een eigen nftables-tabel "inet rzmon" (los van firewalld);
#    bij "learn" of IDS uit wordt die tabel weer verwijderd
#  - bestanden bewaken: hash, grootte, rechten en eigenaar van de paden uit RzSense (alleen wat veranderd is)
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
    #     RzSense (webshell). Alleen als de starttijd klopt, dus nooit een ander proces dat dat nummer later kreeg.
    killed_json=""
    # Wat de agent meteen na het vorige rapport stopte (endpoint-bewaking, agent 1.63.0)
    [ -s "$i_dir/killed.pending" ] && killed_json=$(cat "$i_dir/killed.pending") && rm -f "$i_dir/killed.pending"
    if [ "$ids_mode" = block ]; then
        while IFS=$'\t' read -r z t k_pid k_start; do
            [ "$z" = 0 ] && [ "$t" = kill ] && [[ "$k_pid" =~ ^[0-9]+$ ]] && [[ "$k_start" =~ ^[0-9]+$ ]] || continue
            [ -f "$i_dir/killed.$k_pid.$k_start" ] && continue   # al gedaan; de regel kan nog even in de lijst staan
            k_res=weg
            if [ "$(awk '{ print $22 }' "/proc/$k_pid/stat" 2>/dev/null)" = "$k_start" ]; then
                # Nooit iets van RzSense of de agent zelf (agent 1.65.0)
                if [[ "$(cat "/proc/$k_pid/cgroup" 2>/dev/null)" =~ /rzmon-[^/]*\.service ]]; then k_res=eigen
                elif kill -KILL "$k_pid" 2>/dev/null; then k_res=ok; else k_res=fout; fi
            fi
            : >"$i_dir/killed.$k_pid.$k_start"
            killed_json+="[$k_pid,$k_start,\"$k_res\"],"
        done <"$t_cache"
        find "$i_dir" -maxdepth 1 -name 'killed.*' -mmin +1440 -delete 2>/dev/null
    fi
    # 6e. Hardening (agent 1.57.0): elk uur de instellingen die het verschil maken; RzSense geeft er een score aan
    hard_part=""
    if [ ! -f "$i_dir/hard" ] || [ $(( $(date +%s) - $(stat -c %Y "$i_dir/hard") )) -ge 3600 ]; then
        {
            if command -v sshd >/dev/null 2>&1 && sshd_t=$(sshd -T 2>/dev/null) && [ -n "$sshd_t" ]; then
                printf 'ssh_pw=%s\nssh_root=%s\nssh_empty=%s\n' "$(awk '$1 == "passwordauthentication" { print $2 }' <<<"$sshd_t")" \
                    "$(awk '$1 == "permitrootlogin" { print $2 }' <<<"$sshd_t")" "$(awk '$1 == "permitemptypasswords" { print $2 }' <<<"$sshd_t")"
                # Agent 1.94.0 (CIS 5.2): pogingen per verbinding, X11, afmelden bij stilte, tijd om in te loggen
                awk '$1 == "maxauthtries" { print "ssh_maxauth=" $2 } $1 == "x11forwarding" { print "ssh_x11=" $2 }
                     $1 == "clientaliveinterval" { print "ssh_idle=" $2 } $1 == "logingracetime" { print "ssh_grace=" $2 }' <<<"$sshd_t"
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
            printf 'open_db=%s\n' "$(ss_listen t | awk '{ a = $4; p = a; sub(/.*:/, "", p); h = a; sub(/:[0-9]+$/, "", h)
                if (p ~ /^(3306|5432|6379|27017|9200|11211|5984|8086)$/ && h ~ /^(0\.0\.0\.0|\*|\[::\]|::)$/) print p }' | sort -un | paste -sd, -)"
            h_dk=ok
            [ -S /var/run/docker.sock ] && [[ "$(stat -c %A /var/run/docker.sock 2>/dev/null)" == ????????w? ]] && h_dk=world
            ss_listen t | awk '{ print $4 }' | grep -qE ':2375$' && h_dk=tcp
            printf 'docker_sock=%s\n' "$h_dk"
            if findmnt -no OPTIONS /tmp 2>/dev/null | grep -q noexec; then printf 'tmp_noexec=yes\n'; else printf 'tmp_noexec=no\n'; fi
            printf 'empty_pw=%s\n' "$(awk -F: '$2 == "" { print $1 }' /etc/shadow 2>/dev/null | paste -sd, -)"
            printf 'sudo_nopw=%s\n' "$(grep -hsE '^[^#]*NOPASSWD' /etc/sudoers /etc/sudoers.d/* 2>/dev/null | awk '{ print $1 }' | sort -u | paste -sd, -)"
            if command -v getenforce >/dev/null 2>&1; then printf 'selinux=%s\n' "$(getenforce 2>/dev/null)"; fi
            # Agent 1.94.0: extra controles uit de CIS Benchmarks (alleen uitlezen; RzSense beoordeelt)
            printf 'shadow_perm=%s\npasswd_perm=%s\ncrontab_perm=%s\n' "$(stat -c '%a %U' /etc/shadow 2>/dev/null)" "$(stat -c '%a %U' /etc/passwd 2>/dev/null)" "$(stat -c '%a %U' /etc/crontab 2>/dev/null)"
            printf 'pass_max_days=%s\numask=%s\n' "$(awk '$1 == "PASS_MAX_DAYS" { print $2 }' /etc/login.defs 2>/dev/null)" "$(awk '$1 == "UMASK" { print $2 }' /etc/login.defs 2>/dev/null)"
            printf 'uid0=%s\n' "$(awk -F: '$3 == 0 && $1 != "root" { print $1 }' /etc/passwd 2>/dev/null | paste -sd, -)"
            for h_k in kernel/randomize_va_space net/ipv4/conf/all/accept_redirects net/ipv4/tcp_syncookies net/ipv4/conf/all/rp_filter kernel/dmesg_restrict kernel/yama/ptrace_scope fs/suid_dumpable; do
                [ -r "/proc/sys/$h_k" ] && printf 'sys_%s=%s\n' "${h_k##*/}" "$(cat "/proc/sys/$h_k" 2>/dev/null)"
            done
            if systemctl is-active -q auditd 2>/dev/null; then printf 'auditd=yes\n'; elif command -v systemctl >/dev/null 2>&1; then printf 'auditd=no\n'; fi
            h_ts=none
            for u in chronyd chrony systemd-timesyncd ntpd ntp ntpsec; do systemctl is-active -q "$u" 2>/dev/null && h_ts=$u && break; done
            printf 'time_sync=%s\n' "$h_ts"
            printf 'ww_files=%s\n' "$(timeout 20 find /etc /usr/bin /usr/sbin /usr/local/bin /usr/local/sbin -xdev -type f -perm -0002 2>/dev/null | head -n 5 | paste -sd, -)"
            printf 'legacy_svc=%s\n' "$(ss_listen tu | awk '{ p = $5; sub(/.*:/, "", p); if (p ~ /^(21|23|69|512|513|514)$/) print p }' | sort -un | paste -sd, -)"
            if grep -qsE '^[^#]*(pam_faillock|pam_tally2)' /etc/pam.d/* 2>/dev/null; then printf 'faillock=yes\n'; else printf 'faillock=no\n'; fi
            printf 'pw_minlen=%s\n' "$(cat /etc/security/pwquality.conf /etc/security/pwquality.conf.d/*.conf 2>/dev/null | awk -F= '$1 ~ /^[ \t]*minlen[ \t]*$/ { v = $2; gsub(/[ \t]/, "", v) } END { print v }')"
            for h_g in /boot/grub2/grub.cfg /boot/grub/grub.cfg; do [ -f "$h_g" ] && { printf 'grub_perm=%s\n' "$(stat -c '%a' "$h_g")"; break; }; done
            if findmnt -no OPTIONS /dev/shm 2>/dev/null | grep -q noexec; then printf 'shm_noexec=yes\n'; elif [ -d /dev/shm ]; then printf 'shm_noexec=no\n'; fi
        } >"$i_dir/hard.tmp" 2>/dev/null && mv -f "$i_dir/hard.tmp" "$i_dir/hard"
        [ -s "$i_dir/hard" ] && hard_part=",\"hard\":{$(awk '/^[a-z_]+=/ { k = $0; sub(/=.*/, "", k); v = substr($0, length(k) + 2); gsub(/[\\"]/, "", v); printf "\"%s\":\"%s\",", k, v }' "$i_dir/hard" | sed 's/,$//')}"
    fi
    # 7. Verbindingen: een shell (of nc/socat) met een verbinding naar buiten, of uitgaand naar een verdachte poort
    conns_json=""; out_json=""; out_seen=""; n_out=0
    if command -v ss >/dev/null 2>&1; then
        listen=" $(ss_listen t | awk '{ p = $4; sub(/.*:/, "", p); printf "%s ", p }')"
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
    #    Alleen wijzigingen gaan mee; de nieuwe stand wordt pas bewaard als RzSense het rapport heeft aangenomen.
    fim_dir="$i_dir/fim"
    fimc=""   # voorstellen voor "＋ Aanbevolen" in RzSense: gangbare mappen die hier bestaan en niet leeg zijn
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
        # Vorige stand: niet bij de eerste keer of na een nieuwe basismeting in RzSense (andere epoch): dan alles sturen
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
        # Wat RzSense moet weten: c = nieuw/gewijzigd, b = onder een nieuw bewaakt pad (basismeting), d = weg, u = niet meer bewaakt
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

# Blokkeren: eigen nftables-tabel "inet rzmon" met de IP's uit RzSense (alleen in de modus blokkeren).
# Lokaas-poort (agent 1.54.0, regel "0 <tab> decoy <tab> poort"): niemand van jou gebruikt die poort, dus elke verbinding
# is een scanner. Er luistert niets; een regel in dezelfde tabel zet het IP in een set (dc4/dc6, een uur), de agent
# stuurt die IP's mee en haalt ze na een geslaagd rapport uit de set. Ook in de modus leren (dan alleen melden).
fw_json=""; decoy_json=""; decoy_err=""; dc_sent4=""; dc_sent6=""
decoy_port=""
# 1.77.0: TrueNAS beheert zijn eigen netwerk; blokkeren en de honeypot-poort alleen als RzSense het voor deze server toestaat
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
            # Agent 1.89.0: in het logboek, met de aantallen (blokkades, bekende aanvallers, lokaas-poort, uitgaand)
            fw_cnt=$(printf '%s' "$want4$want6" | tr -cd ',' | wc -c); kn_cnt=$(printf '%s' "$kn4$kn6" | tr -cd ',' | wc -c)
            act_log firewall 1 "Firewall bijgewerkt" "$fw_cnt geblokkeerde IP's · $kn_cnt netten van bekende aanvallers${decoy_port:+ · lokaas-poort $decoy_port}$([ "$outblock" = 1 ] && echo ' · ook uitgaand')"
        else
            fw_err=$(head -c 200 "$i_dir/fw.err" | json_text)
            [ "$(cat "$i_dir/fw.errsig" 2>/dev/null)" != "$sig" ] && { act_log firewall 0 "Firewall bijwerken mislukt" "$(head -c 200 "$i_dir/fw.err")"; echo "$sig" >"$i_dir/fw.errsig"; }
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
        # Nooit het adres van RzSense zelf blokkeren
        rz_host=${MONITOR_URL#*://}; rz_host=${rz_host%%/*}; rz_host=${rz_host%:*}; rz_host=${rz_host#[}; rz_host=${rz_host%]}
        rz_ips=" $(getent ahosts "$rz_host" 2>/dev/null | awk '{ print $1 }' | sort -u | tr '\n' ' ') $rz_host "
        now_f=$(date +%s)
        while IFS=$'\t' read -r z t b_ip b_until; do
            # Bekende aanvallers (RzSense 1.82.0): bereiken (CIDR of begin-eind) in een eigen set
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
    # Leren (zonder lokaas-poort) of IDS uit volgens een complete lijst van RzSense: niets meer blokkeren (zonder lijst: niets aanraken)
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
# Regels "0 <tab> weblog <tab> swag|ha|vw <tab> pad, containernaam of auto" (RzSense 1.89.0). Alleen de nieuwe regels sinds
# de vorige keer; per IP samengevat (aantallen, een paar paden met een foutcode, scanner); RzSense past de scenario's toe.
# SWAG: de access-log van nginx. Home Assistant: "invalid authentication". Vaultwarden: "Username or password is incorrect".
weblog_json=""
if [ -r "${t_cache-}" ] && grep -q $'^0\tweblog\t' "$t_cache"; then
    w_dir="$t_dir/weblog"; mkdir -p "$w_dir" 2>/dev/null
    wl_file_new() {  # wl_file_new <naam> <bestand>: nieuwe regels (inode + positie); de eerste keer alleen vastleggen
        local f=$2 st="$w_dir/$1.pos" ino size pino pos
        ino=$(stat -c %i "$f" 2>/dev/null) && size=$(stat -c %s "$f" 2>/dev/null) || return 1
        read -r pino pos 2>/dev/null <"$st"
        [ -n "$pino" ] || { echo "$ino $size" >"$st"; return 0; }
        echo "$ino $size" >"$st.new"   # agent 1.87.0: pas na een geslaagd rapport de nieuwe positie (anders gaan regels verloren)
        if [ "$pino" != "$ino" ] || [ "${pos:-0}" -gt "$size" ]; then pos=0; fi   # geroteerd of nieuw
        tail -c +$(( ${pos:-0} + 1 )) "$f" 2>/dev/null | head -c $(( size - ${pos:-0} )) | tail -n 20000
    }
    wl_docker() {  # wl_docker <image-patroon>: naam van de eerste draaiende container met dat image (docker of podman)
        local c
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            local out
            out=$(timeout 15 "$c" ps --format '{{.Names}} {{.Image}}' 2>/dev/null | awk -v p="$1" 'tolower($2) ~ p { print $1; exit }')
            [ -n "$out" ] && { echo "$out"; return 0; }   # agent 1.87.0: niet gevonden bij docker? Dan nog podman
        done
        return 1
    }
    wl_mount() {  # wl_mount <container> <pad in de container>: map op de host
        local c
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            local out
            out=$(timeout 15 "$c" inspect -f '{{range .Mounts}}{{.Destination}} {{.Source}}{{"\n"}}{{end}}' "$1" 2>/dev/null | awk -v d="$2" '$1 == d { print $2; exit }')
            [ -n "$out" ] && { echo "$out"; return 0; }
        done
        return 1
    }
    wl_ctr_ips() {  # "IP=naam IP=naam …" van de draaiende containers: welke dienst zit achter de proxy (agent 1.86.0)
        local c
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            timeout 15 "$c" ps -q 2>/dev/null | head -n 200 | xargs -r timeout 15 "$c" inspect -f '{{.Name}}{{range .NetworkSettings.Networks}} {{.IPAddress}}{{end}}' 2>/dev/null \
                | awk '{ n = $1; sub(/^\//, "", n); for (i = 2; i <= NF; i++) if ($i != "") printf "%s=%s ", $i, n }'
            return 0
        done
    }
    wl_ip_count() {  # per IP tellen: "IP aantal" → {"ip":n,…}
        sort | uniq -c | sort -rn | head -n 200 | awk '$2 ~ /^[0-9a-fA-F:.]+$/ { printf "\"%s\":%d,", $2, $1 }' | sed 's/,$//'
    }
    while IFS=$'\t' read -r z t w_src w_arg; do
        [ "$z" = 0 ] && [ "$t" = weblog ] || continue
        w_path=""; w_err=""; w_body=""; w_req=""; w_kind=""; w_ctr=""; w_full=""
        case "$w_src" in
            swag)
                # Agent 1.86.0: de reverse proxy herkennen (SWAG, Nginx Proxy Manager of een gewone nginx). Met het extra
                # logbestand rzsense.log (SWAG/nginx, zie RzSense) of de logs van Nginx Proxy Manager ook de site en de dienst erachter.
                w_path=$w_arg; w_kind=nginx; w_ctr=""; w_full=0; w_lines=""
                if [ -z "$w_path" ] || [ "$w_path" = auto ]; then
                    # Zelf zoeken (één keer per dag): "soort container map"
                    if [ -f "$w_dir/proxy.find" ] && [ $(( $(date +%s) - $(stat -c %Y "$w_dir/proxy.find") )) -lt 86400 ]; then
                        read -r w_kind w_ctr w_path <"$w_dir/proxy.find"
                    else
                        w_kind=""; w_ctr=""; w_path=""
                        c=$(wl_docker 'swag|letsencrypt')
                        if [ -n "$c" ] && m=$(wl_mount "$c" /config) && [ -d "$m/log/nginx" ]; then w_kind=swag; w_ctr=$c; w_path="$m/log/nginx"; fi
                        if [ -z "$w_path" ]; then
                            c=$(wl_docker 'nginx-proxy-manager|jc21/')
                            if [ -n "$c" ] && m=$(wl_mount "$c" /data) && [ -d "$m/logs" ]; then w_kind=npm; w_ctr=$c; w_path="$m/logs"; fi
                        fi
                        if [ -z "$w_path" ]; then
                            for f in /opt/swag/config/log/nginx /srv/swag/config/log/nginx /docker/swag/config/log/nginx \
                                /home/*/swag/config/log/nginx /home/*/docker/swag/config/log/nginx; do
                                [ -f "$f/access.log" ] && w_kind=swag && w_path=$f && break
                            done
                        fi
                        if [ -z "$w_path" ] && [ -f /var/log/nginx/access.log ]; then w_kind=nginx; w_path=/var/log/nginx; fi
                        printf '%s %s %s\n' "${w_kind:--}" "${w_ctr:--}" "$w_path" >"$w_dir/proxy.find"
                    fi
                    [ "$w_kind" = - ] && w_kind=""
                    [ "$w_ctr" = - ] && w_ctr=""
                    # Een map: de logs van Nginx Proxy Manager, het extra logbestand van RzSense, of anders de gewone access-log
                    if [ -n "$w_path" ] && [ -d "$w_path" ]; then
                        if [ "$w_kind" = npm ]; then w_path="$w_path/proxy-host-*_access.log"
                        elif [ -f "$w_path/rzsense.log" ]; then w_path="$w_path/rzsense.log"
                        else w_path="$w_path/access.log"; fi
                    fi
                elif [[ "$w_path" == *swag* ]]; then w_kind=swag
                elif [[ "$w_path" == *proxy-host-* ]]; then w_kind=npm
                fi
                if [ "$w_kind" = npm ] && [[ "$w_path" == *'*'* ]]; then
                    # Nginx Proxy Manager: een log per site, met de site en de dienst erin
                    if compgen -G "$w_path" >/dev/null; then
                        w_full=1
                        for f in $w_path; do
                            [ -f "$f" ] && [ -r "$f" ] || continue
                            w_lines+=$(wl_file_new "npm-$(basename "$f" .log)" "$f" | edr_npm_norm)$'\n'
                        done
                    else w_err="niet gevonden"; fi
                elif [ -n "$w_path" ] && [ -r "$w_path" ]; then
                    if [[ "$w_path" == */rzsense.log ]]; then w_full=1; w_lines=$(wl_file_new swagx "$w_path")
                    else w_lines=$(wl_file_new swag "$w_path"); fi
                elif [ -n "$w_path" ]; then w_err="niet leesbaar: $w_path"
                else w_err="niet gevonden"; fi
                if [ -z "$w_err" ]; then
                    # nginx "combined": IP - - [tijd] "GET /pad HTTP/1.1" status bytes "referer" "user-agent"; 444 = zonder (bekende) hostnaam
                    # Met de site en de dienst: daarachter nog "site" "dienst" (rzsense.log, of omgezet uit Nginx Proxy Manager)
                    # Agent 1.63.0: ook de verdachte verzoeken zelf, voor de payload-inspectie in RzSense (w_req)
                    w_cmap=""
                    [ "$w_full" = 1 ] && [ -n "$w_lines" ] && w_cmap=$(wl_ctr_ips)
                    w_req=$(printf '%s\n' "$w_lines" | edr_swag_req)
                    w_body=$(printf '%s\n' "$w_lines" | awk -F'"' -v cmap="$w_cmap" '
                        function js(s) { gsub(/[\\"]/, "", s); gsub(/[^ -~]/, "?", s); return substr(s, 1, 120) }
                        function red(s,   o, l) { o = ""   # agent 1.86.1: tokens en wachtwoorden in een adres weglakken (?token=***)
                            while ((l = tolower(s)) != "" && match(l, /[?&;](pass|passwd|password|pwd|[a-z_]*token|api_?key|apikey|secret|key|auth|authsig|session|sid|jwt|code|sig|signature|otp|ticket)=/)) {
                                o = o substr(s, 1, RSTART + RLENGTH - 1) "***"; s = substr(s, RSTART + RLENGTH)
                                if (match(s, /[&;# ]/)) s = substr(s, RSTART); else s = "" }
                            return o s }
                        { split($1, a, " "); ip = a[1]; split($3, b, " "); st = b[1] + 0; split($2, r, " "); p = red(r[2]); ua = tolower($6)
                          if (ip !~ /^[0-9a-fA-F:.]+$/) next
                          n[ip]++; if (st == 404) c404[ip]++; if (st == 401 || st == 403) c40x[ip]++; if (st == 444) c444[ip]++
                          if (match(ua, /(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|dirbuster|gobuster|ffuf|feroxbuster|l9explore|censysinspect)/)) u[ip] = substr(ua, RSTART, RLENGTH)
                          if (st >= 400 && np[ip] < 8 && !((ip, p) in seen)) { seen[ip, p] = 1; np[ip]++; ps[ip] = ps[ip] (ps[ip] == "" ? "" : ",") "\"" js(p) "\"" }
                          # Agent 1.86.0: per site (hooguit 4 per IP) het aantal, hoeveel een gewoon antwoord kregen (2xx/3xx) en de dienst
                          if (NF >= 10) { h = $8; up = $10; if (h == "" || h == "-" || h == "_") h = "-"; if (st == 444 || up == "") up = "-"
                            k = ip SUBSEP h
                            if (!(k in sc)) { if (ns[ip] >= 4) h = ""; else { ns[ip]++; sl[ip, ns[ip]] = h } }
                            if (h != "") { sc[k]++; if (st >= 200 && st < 400) so[k]++; if (up != "-") su[k] = up } } }
                        END { nm = split(cmap, cm, " "); for (i = 1; i <= nm; i++) { e = index(cm[i], "="); if (e) cn[substr(cm[i], 1, e - 1)] = substr(cm[i], e + 1) }
                              k = 0; for (ip in n) { if (!(ip in c404) && !(ip in c40x) && !(ip in c444) && !(ip in u)) continue
                                if (++k > 300) break
                                s = ""; for (i = 1; i <= ns[ip]; i++) { h = sl[ip, i]; x = ip SUBSEP h; up = su[x]; uh = up; sub(/:[0-9]+$/, "", uh)
                                    s = s (s == "" ? "" : ",") "[\"" js(h) "\",\"" js(up) "\",\"" js(cn[uh]) "\"," sc[x] + 0 "," so[x] + 0 "]" }
                                printf "\"%s\":[%d,%d,%d,%d,\"%s\",[%s]%s],", ip, n[ip], c404[ip], c40x[ip], c444[ip], u[ip], ps[ip], (ns[ip] ? ",[" s "]" : "") } }' | sed 's/,$//')
                fi ;;
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
        weblog_json+="\"$w_src\":{\"path\":\"$(json_escape "$w_path")\"${w_err:+,\"err\":\"$(json_escape "$w_err")\"}${w_kind:+,\"kind\":\"$w_kind\"}${w_ctr:+,\"ctr\":\"$(json_escape "$w_ctr")\"}${w_full:+,\"full\":$w_full},\"ips\":{$w_body}${w_req:+,\"req\":[$w_req]}},"
    done <"$t_cache"
    [ -n "$weblog_json" ] && weblog_json="\"weblog\":{${weblog_json%,}},"
fi

# ---------------------------------------------------------------- Endpoint-bewaking: regels, wachtrij en stand (agent 1.63.0)
lap edr
# auditd-regels (sleutels rzmon_*) zetten zolang het in RzSense aan staat, en weghalen als het uit gaat. Ze staan alleen
# in de kernel (auditctl), niet in /etc/audit: na een herstart zet de eerstvolgende run ze terug. De wachtrij van de
# watcher gaat mee met het rapport (hooguit 500 per keer; wat niet aankomt, blijft voor de volgende keer).
edr_json=""
edr_keys="rzmon_exec rzmon_id rzmon_persist rzmon_mod"
edr_rules() {  # edr_rules on|off: 0 = gelukt (of niets te doen)
    command -v auditctl >/dev/null 2>&1 || return 1
    local k have a p
    have=$(auditctl -l 2>/dev/null | grep -c 'key=rzmon_')
    if [ "$1" = off ]; then
        [ "$have" -gt 0 ] && { for k in $edr_keys; do auditctl -D -k "$k" >/dev/null 2>&1; done; act_log edr 1 "EDR-regels uit auditd gehaald"; }
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
    have=$(auditctl -l 2>/dev/null | grep -c 'key=rzmon_')
    if [ "$have" -gt 0 ]; then act_log edr 1 "EDR-regels in auditd gezet" "$have regels (programma's, gebruikers, persistentie, kernelmodules)"
    elif [ ! -f "$t_dir/edr.failnote" ] || [ -n "$(find "$t_dir/edr.failnote" -mmin +60 2>/dev/null)" ]; then
        act_log edr 0 "EDR-regels zetten mislukt" "auditctl nam geen regels aan"; : >"$t_dir/edr.failnote"   # hooguit eens per uur
    fi
    [ "$have" -gt 0 ]
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
    # YARA (agent 1.64.0): geïnstalleerd, en welke regels (RzSense stuurt nieuwe als de hash verschilt)
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
    # Agent 1.87.0: de wachtrij begrenzen (bv. als RzSense een dag weg is): de nieuwste 20.000 bewaren, de rest tellen als weggegooid
    if [ -s "$e_send" ]; then
        e_n=$(wc -l <"$e_send")
        if [ "$e_n" -gt 20000 ]; then
            tail -n 20000 "$e_send" >"$e_send.tmp" && mv -f "$e_send.tmp" "$e_send"
            echo $(( $(cat "$edr_dir/edr.drop" 2>/dev/null || echo 0) + e_n - 20000 )) >"$edr_dir/edr.drop" 2>/dev/null
        fi
    fi
    e_ev=""
    if [ -s "$e_send" ]; then
        e_take=500; [ "${e_n:-0}" -gt 5000 ] && e_take=2000   # lange wachtrij: sneller inhalen
        e_ev=$(head -n "$e_take" "$e_send" | grep -E '^\{.*\}$' | tr '\n' ',' | sed 's/,$//')
    fi
    edr_json="{\"ev\":[$e_ev],\"info\":{\"audit\":\"$e_audit\",\"file\":\"$e_file\",\"ctr\":\"$e_ctr\",\"fam\":\"$e_fam\",\"epel\":$e_epel,\"watch\":\"$e_watch\",\"systemd\":$e_sys,\"drop\":$e_drop,\"queue\":$(wc -l 2>/dev/null <"$e_send" || echo 0),\"yara\":\"$e_yara\",\"ysha\":\"$e_ysha\",\"sur\":\"$e_sur\"}${e_alerts:+,\"sur\":[$e_alerts]}}"
elif [ "$edr_want" = 0 ] && [ "$(id -u)" = 0 ] && [ "$HA_MODE" = 0 ]; then
    # Uit (in RzSense of met EDR=0): regels weg en de wachtrij leeg
    edr_rules off >/dev/null 2>&1
    rm -f "$edr_spool" "$edr_dir/edr.sending" 2>/dev/null
fi

# ---------------------------------------------------------------- Isoleren en gebruiker uitzetten (agent 1.57.0)
lap isolate
# "0 <tab> isolate <tab> tot (epoch) <tab> toegestane netwerken": alleen nog verkeer met RzSense, je LAN en het IP van de
# beheerder; al het andere (in, uit en containers) wordt tegengehouden. Eigen tabel "inet rzmon_iso". Vervalt vanzelf
# op de tijd, ook als RzSense onbereikbaar is (zo sluit je jezelf nooit voorgoed buiten).
# "0 <tab> userlock <tab> naam <tab> lock|unlock": account vergrendelen (en de processen ervan stoppen) of weer vrijgeven.
iso_json=""
if [ "$HA_MODE" = 0 ] && [ -r "${t_cache-}" ] && [ "$(id -u)" = 0 ]; then
    iso_line=$(awk -F'\t' '$1 == "0" && $2 == "isolate" { print $3 "\t" $4; exit }' "$t_cache")
    iso_until=${iso_line%%$'\t'*}; iso_allow=${iso_line#*$'\t'}
    iso_dir="$t_dir/iso"; mkdir -p "$iso_dir" 2>/dev/null
    if [[ "$iso_until" =~ ^[0-9]+$ ]] && [ "$iso_until" -gt "$(date +%s)" ] && command -v nft >/dev/null 2>&1; then
        # Altijd bereikbaar: RzSense (en het reserve-adres) en de DNS-servers (om RzSense te vinden)
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
        # Tijd voorbij, of RzSense heeft het opgeheven: alles weer open
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

# ---------------------------------------------------------------- Ransomware herkennen (agent 1.90.0)
lap ransom
# Aan per server in RzSense (Agent Manager → Ransomware), met de mappen die je kiest (regels "0 <tab> ransompath <tab> pad
# (base64) <tab> drempel"). Elke ronde, per map:
#  1. lokaasbestanden: twee bestanden die niemand hoort aan te raken (vooraan en achteraan in de map, want ransomware gaat
#     vaak op alfabet). Gewijzigd, hernoemd of weg = meteen alarm;
#  2. hoeveel bestanden er sinds de vorige ronde veranderden of hernoemd werden (find -cnewer, hooguit 15 s, lage voorrang);
#  3. kenmerken: bekende ransomware-extensies, losgeldbriefjes, en tekstbestanden die ineens niet meer te comprimeren
#     zijn (versleuteld).
# Bij een sterk signaal, als je dat aanzette: meteen een snapshot (ZFS, of btrfs read-only) en/of de schrijvende processen
# stoppen (bij Samba de sessie van die pc, en dat IP in de blokkade-set van de firewall). Alles komt in het logboek.
# "0 <tab> ransom <tab> snapshot 0|1 <tab> stoppen 0|1 <tab> lokaas 0|1" zet het aan; zonder die regel ruimt de agent
# zijn lokaasbestanden weer op.
ransom_json=""; rs_cand_json=""
rs_dir="$t_dir/ransom"
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ "${RANSOM:-1}" != 0 ]; then
    mkdir -p "$rs_dir" 2>/dev/null && chmod 700 "$rs_dir" 2>/dev/null
    rs_line=""
    [ -r "${t_cache-}" ] && rs_line=$(awk -F'\t' '$1 == "0" && $2 == "ransom" { print; exit }' "$t_cache")
    # Mappen om uit te kiezen (eens per uur): Samba-shares, NFS-exports, de shares van TrueNAS en /home
    if [ ! -s "$rs_dir/cand" ] || [ -n "$(find "$rs_dir/cand" -mmin +60 2>/dev/null)" ]; then
        {
            command -v testparm >/dev/null 2>&1 && timeout 10 testparm -s 2>/dev/null \
                | awk -F'=' 'tolower($1) ~ /^[ \t]*path[ \t]*$/ { v = $2; sub(/^[ \t]+/, "", v); sub(/[ \t]+$/, "", v); print "smb\t" v }'
            [ -r /etc/exports ] && awk '$1 ~ /^\// { print "nfs\t" $1 }' /etc/exports
            if command -v midclt >/dev/null 2>&1; then
                timeout 15 midclt call sharing.smb.query 2>/dev/null | tr ',' '\n' | sed -n 's/.*"path": *"\(\/[^"]*\)".*/smb\t\1/p'
                timeout 15 midclt call sharing.nfs.query 2>/dev/null | tr ',' '\n' | sed -n 's/.*"path": *"\(\/[^"]*\)".*/nfs\t\1/p'
            fi
            [ -d /home ] && printf 'home\t/home\n'
        } 2>/dev/null | awk -F'\t' '$2 ~ /^\// && $2 !~ /\.\./ && !seen[$2]++' | head -n 50 >"$rs_dir/cand.new" && mv -f "$rs_dir/cand.new" "$rs_dir/cand"
    fi
    while IFS=$'\t' read -r rs_k rs_p; do
        [ -d "$rs_p" ] && rs_cand_json+="[\"$rs_k\",\"$(json_escape "$rs_p")\"],"
    done <"$rs_dir/cand"
    rs_cand_json="[${rs_cand_json%,}]"

    rs_ext_re='\.(locked|encrypted|crypted|crypt|enc[0-9]*|lockbit|conti|ryuk|akira|blackcat|alphv|phobos|djvu|cerber|wncry|wnry|wncryt|locky|zepto|odin|petya|sodinokibi|revil|maze|egregor|clop|cl0p|avaddon|babuk|hive|royal|rhysida|medusa|8base|basta|bianlian|ransom|hellokitty|play|lock|pay2key|nemty|makop|mallox|stop)$'
    rs_note_re='(how_to_decrypt|how_to_recover|how_to_restore|readme_to_restore|restore_files|restore-my-files|decrypt_instructions|decrypt-files|recover-files|read_me_now|!!!read|_readme\.txt|readme-warning|ransom_note|files_encrypted|info\.hta|decryption_instructions)'
    rs_text_re='\.(txt|csv|log|md|html?|xml|json|sql|conf|ini|cfg|php|py|js|sh|ya?ml|svg|tex|rtf)$'
    rs_c1='!0-RzSense-lokaas-niet-aanraken.txt'; rs_c2='zz-RzSense-lokaas-niet-aanraken.txt'
    rs_canary_make() {  # rs_canary_make <map> <naam>: lokaasbestand met de eigenaar van de map, en de vingerafdruk
        local f="$1/$2" own
        own=$(stat -c '%u:%g' "$1" 2>/dev/null)
        { printf 'Dit bestand hoort bij RzSense (bescherming tegen ransomware).\nNiet openen, wijzigen, hernoemen of weggooien: dan volgt een alarm.\n'
          printf 'This file belongs to RzSense (ransomware protection). Do not open, change, rename or delete it.\n%s\n' "$(head -c 24 /dev/urandom | base64)"; } >"$f" 2>/dev/null || return 1
        [ -n "$own" ] && chown "$own" "$f" 2>/dev/null
        chmod 664 "$f" 2>/dev/null
        printf '%s\t%s\n' "$f" "$(sha256sum "$f" | cut -d' ' -f1)"
    }
    rs_snapshot() {  # rs_snapshot <map>: snapshot van het ZFS-dataset (of btrfs-subvolume) van de map; geeft de naam
        local p=$1 ds mp best="" bl=0 ts
        ts=$(date +%Y%m%d-%H%M%S)
        if command -v zfs >/dev/null 2>&1; then
            while IFS=$'\t' read -r ds mp; do
                [ "$mp" = "/" ] || [ "${mp:0:1}" != "/" ] && continue
                case "$p/" in "$mp"/*) [ ${#mp} -gt "$bl" ] && { best=$ds; bl=${#mp}; } ;; esac
            done < <(zfs list -H -o name,mountpoint -t filesystem 2>/dev/null)
            if [ -n "$best" ] && zfs snapshot -r "$best@rzsense-ransom-$ts" 2>/dev/null; then echo "$best@rzsense-ransom-$ts"; return 0; fi
        fi
        if [ "$(stat -f -c %T "$p" 2>/dev/null)" = btrfs ] && command -v btrfs >/dev/null 2>&1 && [ "$(stat -c %i "$p" 2>/dev/null)" = 256 ]; then
            mkdir -p "$p/.rzsense-snapshots" 2>/dev/null
            if btrfs subvolume snapshot -r "$p" "$p/.rzsense-snapshots/ransom-$ts" >/dev/null 2>&1; then echo "$p/.rzsense-snapshots/ransom-$ts"; return 0; fi
        fi
        return 1
    }
    rs_stop() {  # rs_stop <map> <bestanden…>: processen die nu in deze bestanden schrijven stoppen; Samba: de sessie en het IP
        local p=$1 pid comm ip out=""; shift
        local pids=""
        if command -v lsof >/dev/null 2>&1; then
            pids=$(timeout 10 lsof -t -w -- "$@" 2>/dev/null | sort -u | head -n 20)
        fi
        if command -v smbstatus >/dev/null 2>&1; then   # sessies met een open bestand in deze map
            pids+=" $(timeout 10 smbstatus -L 2>/dev/null | awk -v p="$p" 'index($0, p) && $1 ~ /^[0-9]+$/ { print $1 }' | sort -u | head -n 20)"
        fi
        for pid in $pids; do
            [[ "$pid" =~ ^[0-9]+$ ]] && [ "$pid" -gt 1 ] && [ "$pid" != "$$" ] && [ "$pid" != "$PPID" ] || continue
            comm=$(cat "/proc/$pid/comm" 2>/dev/null)
            case "$comm" in systemd*|sshd|init|rzmon*|agetty|login|dbus*|"") continue ;; esac
            ip=""
            [ "$comm" = smbd ] && ip=$(timeout 10 smbstatus -p 2>/dev/null | awk -v pid="$pid" '$1 == pid { for (i = 1; i <= NF; i++) if ($i ~ /^(ipv4:)?[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(:[0-9]+)?$/) { sub(/^ipv4:/, "", $i); sub(/:[0-9]+$/, "", $i); print $i; exit } }')
            if kill -KILL "$pid" 2>/dev/null; then
                act_log ransom 1 "Proces gestopt" "pid $pid ($comm)${ip:+ · pc $ip}"
                out+="{\"pid\":$pid,\"comm\":\"$(json_escape "$comm")\",\"ip\":\"$ip\"},"
                if [ -n "$ip" ] && nft list table inet rzmon >/dev/null 2>&1 && nft add element inet rzmon bl4 "{ $ip }" 2>/dev/null; then
                    act_log ransom 1 "IP geblokkeerd" "$ip (Samba-sessie die bestanden versleutelde)"
                fi
            fi
        done
        printf '%s' "${out%,}"
    }

    # -cnewer ziet ook hernoemde bestanden (de statustijd verandert); BusyBox-find kent het niet: dan -newer
    rs_newer=-cnewer
    find "$rs_dir" -maxdepth 0 -cnewer "$rs_dir" >/dev/null 2>&1 || rs_newer=-newer
    rs_keep=" "
    if [ -n "$rs_line" ]; then
        IFS=$'\t' read -r _ _ rs_snap rs_kill rs_canary _ <<<"$rs_line"
        rs_paths=""
        while IFS=$'\t' read -r _ _ rs_b64 rs_n _; do
            rs_p=$(printf '%s' "$rs_b64" | base64 -d 2>/dev/null)
            [[ "$rs_p" == /* && "$rs_p" != *..* && "$rs_p" != / ]] && [ -d "$rs_p" ] && [ ! -L "$rs_p" ] || continue
            case "$rs_p" in /proc*|/sys*|/dev*|/run*) continue ;; esac
            [[ "$rs_n" =~ ^[0-9]+$ ]] || rs_n=300
            rs_k=$(printf '%s' "$rs_p" | sha1sum | cut -c1-12)
            rs_keep+="$rs_k "
            rs_st="$rs_dir/$rs_k"
            # 1. lokaasbestanden
            rs_cstate="uit"; rs_chit=""
            if [ "$rs_canary" = 1 ]; then
                rs_cstate=ok
                if [ ! -s "$rs_st.canary" ]; then
                    { rs_canary_make "$rs_p" "$rs_c1"; rs_canary_make "$rs_p" "$rs_c2"; } >"$rs_st.canary" 2>/dev/null
                    printf '%s\n' "$rs_p" >"$rs_st.path"
                    if [ -s "$rs_st.canary" ]; then act_log ransom 1 "Lokaasbestanden geplaatst" "$rs_p"; else rs_cstate=fout; fi
                else
                    while IFS=$'\t' read -r rs_f rs_sha; do
                        if [ ! -e "$rs_f" ]; then rs_chit+="${rs_f##*/} is weg; "
                        elif [ "$(sha256sum "$rs_f" 2>/dev/null | cut -d' ' -f1)" != "$rs_sha" ]; then rs_chit+="${rs_f##*/} is gewijzigd; "; fi
                    done <"$rs_st.canary"
                    if [ -n "$rs_chit" ]; then
                        rs_cstate=alarm
                        # na 10 minuten opnieuw plaatsen (dan zie je ook een tweede golf)
                        if [ ! -f "$rs_st.chit" ]; then : >"$rs_st.chit"
                        elif [ -n "$(find "$rs_st.chit" -mmin +10 2>/dev/null)" ]; then
                            rm -f "$rs_st.chit" "$rs_st.canary"
                        fi
                    fi
                fi
            fi   # lokaas uit: de lus onderaan ruimt de bestanden op
            # 2. en 3. wat er sinds de vorige ronde veranderde
            rs_n_ch=0; rs_ext=0; rs_note=0; rs_enc=0; rs_slow=0; rs_ex=""
            : >"$rs_st.stamp.new"
            if [ -f "$rs_st.stamp" ]; then
                timeout 15 nice -n 10 find "$rs_p" -xdev -maxdepth 8 "$rs_newer" "$rs_st.stamp" -type f \
                    -not -path '*/.zfs/*' -not -path '*/.rzsense-snapshots/*' -not -name "$rs_c1" -not -name "$rs_c2" -print 2>/dev/null | head -n 20000 >"$rs_st.list"
                [ "${PIPESTATUS[0]}" = 124 ] && rs_slow=1
                rs_n_ch=$(wc -l <"$rs_st.list")
                rs_ext=$(grep -ciE "$rs_ext_re" "$rs_st.list")
                rs_note=$(awk -F/ '{ print tolower($NF) }' "$rs_st.list" | grep -cE "$rs_note_re")
                while IFS= read -r rs_f; do   # tekstbestanden die niet meer te comprimeren zijn
                    rs_sz=$(stat -c %s "$rs_f" 2>/dev/null || echo 0)
                    [ "$rs_sz" -ge 4096 ] || continue
                    rs_gz=$(head -c 65536 "$rs_f" 2>/dev/null | gzip -c | wc -c)
                    rs_rd=$(( rs_sz < 65536 ? rs_sz : 65536 ))
                    [ $(( rs_gz * 100 )) -ge $(( rs_rd * 97 )) ] && rs_enc=$(( rs_enc + 1 ))
                done < <(grep -iE "$rs_text_re" "$rs_st.list" | head -n 15)
                rs_ex=$( { grep -iE "$rs_ext_re" "$rs_st.list"; awk -F/ 'tolower($NF) ~ /'"$rs_note_re"'/' "$rs_st.list"; } | head -n 5 | sed "s#^$rs_p/##" | cut -c1-120 \
                    | while IFS= read -r l; do printf '"%s",' "$(json_escape "$l")"; done)
            fi
            mv -f "$rs_st.stamp.new" "$rs_st.stamp"
            # Sterk signaal: lokaas geraakt, extensies, briefjes, versleutelde tekst, of heel veel tegelijk mét een kenmerk
            rs_hit=0
            if [ -n "$rs_chit" ] || [ "$rs_ext" -ge 3 ] || [ "$rs_note" -ge 2 ] || [ "$rs_enc" -ge 3 ] \
                || { [ "$rs_n_ch" -ge "$rs_n" ] && [ $(( rs_ext + rs_enc + rs_note )) -ge 1 ]; }; then rs_hit=1; fi
            rs_snapname=""; rs_killed=""
            if [ "$rs_hit" = 1 ]; then
                act_log ransom 0 "Mogelijke ransomware" "$rs_p · ${rs_chit}$rs_n_ch gewijzigd · $rs_ext met ransomware-extensie · $rs_note losgeldbriefjes · $rs_enc versleuteld"
                if [ "$rs_snap" = 1 ] && { [ ! -f "$rs_st.snap" ] || [ -n "$(find "$rs_st.snap" -mmin +30 2>/dev/null)" ]; }; then
                    if rs_snapname=$(rs_snapshot "$rs_p"); then
                        : >"$rs_st.snap"; act_log ransom 1 "Snapshot gemaakt" "$rs_snapname"
                    else
                        act_log ransom 0 "Snapshot maken mislukt" "$rs_p: geen ZFS-dataset of btrfs-subvolume gevonden"
                    fi
                fi
                if [ "$rs_kill" = 1 ]; then
                    mapfile -t rs_files < <( { grep -iE "$rs_ext_re" "$rs_st.list"; head -n 30 "$rs_st.list"; } 2>/dev/null | head -n 40)
                    rs_killed=$(rs_stop "$rs_p" "${rs_files[@]}")
                fi
            fi
            rs_paths+="{\"k\":\"$rs_k\",\"p\":\"$(json_escape "$rs_p")\",\"n\":$rs_n_ch,\"ext\":$rs_ext,\"notes\":$rs_note,\"enc\":$rs_enc,\"slow\":$rs_slow,\"canary\":\"$rs_cstate\",\"chit\":\"$(json_escape "$rs_chit")\",\"hit\":$rs_hit,\"snap\":\"$(json_escape "$rs_snapname")\",\"ex\":[${rs_ex%,}],\"killed\":[$rs_killed]},"
            [ "${#rs_paths}" -gt 60000 ] && break
        done < <(awk -F'\t' '$1 == "0" && $2 == "ransompath"' "$t_cache" | head -n 20)
        ransom_json="{\"on\":1,\"paths\":[${rs_paths%,}],\"cand\":$rs_cand_json}"
    else
        ransom_json="{\"on\":0,\"cand\":$rs_cand_json}"
    fi
    # Mappen die niet meer bewaakt worden (of lokaas uit): onze lokaasbestanden weg, alleen als ze nog ongewijzigd zijn
    for rs_cf in "$rs_dir"/*.canary; do
        [ -f "$rs_cf" ] || continue
        rs_k=${rs_cf##*/}; rs_k=${rs_k%.canary}
        [[ "$rs_keep" == *" $rs_k "* ]] && [ "${rs_canary:-0}" = 1 ] && continue
        while IFS=$'\t' read -r rs_f rs_sha; do
            [ -f "$rs_f" ] && [ "$(sha256sum "$rs_f" 2>/dev/null | cut -d' ' -f1)" = "$rs_sha" ] && rm -f -- "$rs_f"
        done <"$rs_cf"
        act_log ransom 1 "Lokaasbestanden weggehaald" "$(cat "$rs_dir/$rs_k.path" 2>/dev/null)"
        rm -f "$rs_cf" "$rs_dir/$rs_k.path" "$rs_dir/$rs_k.chit"
    done
    for rs_sf in "$rs_dir"/*.stamp; do   # stempels van mappen die weg zijn
        [ -f "$rs_sf" ] || continue
        rs_k=${rs_sf##*/}; rs_k=${rs_k%.stamp}
        [[ "$rs_keep" == *" $rs_k "* ]] || rm -f "$rs_sf" "$rs_dir/$rs_k.list" "$rs_dir/$rs_k.snap"
    done
fi
# ---------------------------------------------------------------- Onbekende programma's en jagen op sporen (agent 1.91.0)
# 1.95.0: van welk pakket zijn deze bestanden? $1 = bestand met paden (één per regel). Uitvoer: pad<TAB>pakket.
# In één keer: rpm via xargs, dpkg door alle .list-bestanden één keer te lezen (ook /bin ↔ /usr/bin), apk en pacman per bestand.
# Gebruikt door Programma's (64) en de pakketten die via het netwerk bereikbaar zijn (75).
pkg_owners() {
    local list=$1 f g
    [ -s "$list" ] || return 0
    if command -v rpm >/dev/null 2>&1 && { [ -d /var/lib/rpm ] || [ -d /usr/lib/sysimage/rpm ]; }; then
        tr '\n' '\0' <"$list" | xargs -0 -r timeout 60 rpm -qf --qf '%{NAME}\n' -- 2>/dev/null | paste "$list" - 2>/dev/null \
            | awk -F'\t' '$2 != "" && $2 !~ / / { print $1 "\t" $2 }'
    fi
    if [ -d /var/lib/dpkg/info ]; then
        timeout 60 awk 'NR == FNR { w[$0] = $0; if ($0 ~ /^\/usr\//) w[substr($0, 5)] = $0; else w["/usr" $0] = $0; next }
            ($0 in w) { n = FILENAME; sub(/^.*\//, "", n); sub(/\.list$/, "", n); sub(/:.*/, "", n); print w[$0] "\t" n }' \
            "$list" /var/lib/dpkg/info/*.list 2>/dev/null
    fi
    if command -v apk >/dev/null 2>&1; then
        while IFS= read -r f; do
            g=$(timeout 5 apk info -W -- "$f" 2>/dev/null | awk '/ is owned by / { print $NF; exit }')
            [ -n "$g" ] && printf '%s\t%s\n' "$f" "${g%-r[0-9]*}"
        done <"$list"
    fi
    if command -v pacman >/dev/null 2>&1; then
        while IFS= read -r f; do
            g=$(timeout 5 pacman -Qoq -- "$f" 2>/dev/null | head -n 1)
            [ -n "$g" ] && printf '%s\t%s\n' "$f" "$g"
        done <"$list"
    fi
    return 0
}

lap apps
# 1. Programma's (aan per server in RzSense: regel "0 <tab> apps <tab> stoppen 0|1"): elke ronde de programma's die nu
#    draaien (/proc/*/exe, niet in containers). Een nieuw of veranderd programma krijgt een vingerafdruk (sha256) en het
#    pakket waar het bij hoort (rpm, dpkg, apk, pacman; snap/flatpak/nix apart). Dat gaat één keer naar RzSense, die
#    beslist wat onbekend is (na de leerperiode). Staat de vingerafdruk op de blokkeerlijst van RzSense (regels
#    "0 <tab> appblock <tab> sha256") en staat stoppen aan, dan stopt de agent dat programma (alleen dat proces, op PID).
# 2. Jagen op sporen (regels "0 <tab> iochunt <tab> id", "iocsha <tab> sha256", "iocpath <tab> pad (base64)"): één keer per
#    opdracht zoekt de agent of die paden bestaan en of een draaiend programma of een bestand in de gebruikelijke plekken
#    (/tmp, /var/tmp, /dev/shm, /usr/local, /opt, /root, /home, /srv, /var/www) die vingerafdruk heeft. Hooguit 2 minuten,
#    met lage voorrang. Alles komt in het logboek.
apps_json=""; ioc_json=""
ap_dir="$t_dir/apps"
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ -r "${t_cache-}" ]; then
    mkdir -p "$ap_dir" 2>/dev/null && chmod 700 "$ap_dir" 2>/dev/null
    ap_line=""
    [ "${APPCTL:-1}" != 0 ] && ap_line=$(awk -F'\t' '$1 == "0" && $2 == "apps" { print; exit }' "$t_cache")
    ap_hunt=$(awk -F'\t' '$1 == "0" && $2 == "iochunt" && $3 ~ /^[0-9]+$/ { print $3; exit }' "$t_cache")
    [ -n "$ap_hunt" ] && [ "$ap_hunt" = "$(cat "$ap_dir/ioc.done" 2>/dev/null)" ] && ap_hunt=""
    if [ -n "$ap_line" ] || [ -n "$ap_hunt" ]; then
        # Wat er nu draait (niet in een container: die hebben een eigen mount-namespace)
        ap_mnt=$(readlink /proc/self/ns/mnt 2>/dev/null)
        : >"$ap_dir/cur.new"
        for ap_p in /proc/[0-9]*; do
            ap_exe=$(readlink "$ap_p/exe" 2>/dev/null) || continue
            case "$ap_exe" in ""|/memfd:*|*" (deleted)") continue ;; esac   # die ziet de IDS al (verdachte processen)
            [ -n "$ap_mnt" ] && [ "$(readlink "$ap_p/ns/mnt" 2>/dev/null)" != "$ap_mnt" ] && continue
            printf '%s\t%s\t%s\n' "$ap_exe" "${ap_p#/proc/}" "$(stat -c %U "$ap_p" 2>/dev/null)" >>"$ap_dir/cur.new"
        done
        sort -t$'\t' -k1,1 -u "$ap_dir/cur.new" >"$ap_dir/cur" 2>/dev/null; rm -f "$ap_dir/cur.new"
        # Vingerafdruk en pakket alleen voor wat nieuw of veranderd is (apparaat, inode, grootte, tijd)
        declare -A ap_key=() ap_sha=() ap_pkg=()
        if [ -f "$ap_dir/seen" ]; then
            while IFS=$'\t' read -r ap_f ap_k ap_s ap_g; do ap_key[$ap_f]=$ap_k; ap_sha[$ap_f]=$ap_s; ap_pkg[$ap_f]=$ap_g; done <"$ap_dir/seen"
        fi
        : >"$ap_dir/todo"
        while IFS=$'\t' read -r ap_f _ _; do
            ap_k=$(stat -L -c '%d:%i:%s:%Y' -- "$ap_f" 2>/dev/null) || continue
            [ "${ap_key[$ap_f]-}" = "$ap_k" ] && continue
            ap_key[$ap_f]=$ap_k
            printf '%s\n' "$ap_f" >>"$ap_dir/todo"
        done <"$ap_dir/cur"
        head -n 150 "$ap_dir/todo" >"$ap_dir/todo.1"
        ap_new=""
        if [ -s "$ap_dir/todo.1" ]; then
            # Pakketten in één keer opzoeken (dpkg: alle .list-bestanden in één keer lezen; ook /bin ↔ /usr/bin)
            pkg_owners "$ap_dir/todo.1" >"$ap_dir/pkg"   # 1.95.0: gedeeld met de lijst "bereikbaar via het netwerk"
            declare -A ap_npkg=()
            while IFS=$'\t' read -r ap_f ap_g; do [ -z "${ap_npkg[$ap_f]-}" ] && ap_npkg[$ap_f]=$ap_g; done <"$ap_dir/pkg"
            while IFS= read -r ap_l; do
                ap_s=${ap_l%% *}; ap_f=${ap_l#* }; ap_f=${ap_f# }; ap_f=${ap_f#\*}
                [[ "$ap_s" =~ ^[0-9a-f]{64}$ ]] || continue
                ap_g=${ap_npkg[$ap_f]-}
                case "$ap_f" in /snap/*) ap_g=${ap_g:-snap} ;; /var/lib/flatpak/*) ap_g=${ap_g:-flatpak} ;; /nix/store/*) ap_g=${ap_g:-nix} ;; esac
                ap_changed=0
                [ -n "${ap_sha[$ap_f]-}" ] && [ "${ap_sha[$ap_f]}" != "$ap_s" ] && ap_changed=1
                ap_sha[$ap_f]=$ap_s; ap_pkg[$ap_f]=$ap_g
                IFS=$'\t' read -r _ ap_pid ap_u < <(awk -F'\t' -v f="$ap_f" '$1 == f { print; exit }' "$ap_dir/cur")
                ap_cmd=$(tr '\0' ' ' 2>/dev/null <"/proc/$ap_pid/cmdline" | head -c 300 | json_text)
                ap_new+="{\"p\":\"$(json_escape "$ap_f")\",\"sha\":\"$ap_s\",\"pkg\":\"$(json_escape "$ap_g")\",\"u\":\"$(json_escape "$ap_u")\",\"cmd\":\"$ap_cmd\",\"ch\":$ap_changed},"
            done < <(tr '\n' '\0' <"$ap_dir/todo.1" | nice -n 19 xargs -0 -r timeout 60 sha256sum -- 2>/dev/null)
            # Alleen wat een vingerafdruk kreeg komt in "gezien"; de rest (meer dan 150) volgt de volgende ronde
            for ap_f in "${!ap_sha[@]}"; do
                [ -n "${ap_key[$ap_f]-}" ] && printf '%s\t%s\t%s\t%s\n' "$ap_f" "${ap_key[$ap_f]}" "${ap_sha[$ap_f]}" "${ap_pkg[$ap_f]-}"
            done | sort >"$ap_dir/seen.new" && mv -f "$ap_dir/seen.new" "$ap_dir/seen"
        fi
        rm -f "$ap_dir/todo" "$ap_dir/todo.1" "$ap_dir/pkg"
        if [ -n "$ap_line" ]; then
            IFS=$'\t' read -r _ _ ap_kill _ <<<"$ap_line"
            ap_block=" $(awk -F'\t' '$1 == "0" && $2 == "appblock" && length($3) == 64 && $3 ~ /^[0-9a-f]+$/ { printf "%s ", $3 }' "$t_cache")"
            # Wat er nu draait zonder pakket (vingerafdrukken), en geblokkeerde programma's stoppen
            ap_run=""; ap_blk=""; ap_n=0
            while IFS=$'\t' read -r ap_f ap_pid ap_u; do
                ap_n=$((ap_n + 1))
                ap_s=${ap_sha[$ap_f]-}
                [ -n "$ap_s" ] || continue
                [ -z "${ap_pkg[$ap_f]-}" ] && [ "${#ap_run}" -lt 14000 ] && ap_run+="\"$ap_s\","
                [[ "$ap_block" == *" $ap_s "* ]] || continue
                ap_k=0; ap_pids=""
                for ap_q in /proc/[0-9]*; do
                    [ "$(readlink "$ap_q/exe" 2>/dev/null)" = "$ap_f" ] && ap_pids+="${ap_q#/proc/} "
                done
                for ap_q in $ap_pids; do
                    [ "$ap_kill" = 1 ] && [ "$ap_q" -gt 1 ] && [ "$ap_q" != "$$" ] && [ "$ap_q" != "$PPID" ] || continue
                    case "$(cat "/proc/$ap_q/comm" 2>/dev/null)" in systemd*|sshd|init|rzmon*|agetty|login|dbus*|"") continue ;; esac
                    [ "$(readlink "/proc/$ap_q/exe" 2>/dev/null)" = "$ap_f" ] || continue   # nog steeds hetzelfde programma
                    if kill -KILL "$ap_q" 2>/dev/null; then
                        ap_k=$((ap_k + 1)); act_log appctl 1 "Geblokkeerd programma gestopt" "$ap_f · pid $ap_q · $ap_u · sha256 ${ap_s:0:16}…"
                    else
                        act_log appctl 0 "Geblokkeerd programma stoppen mislukt" "$ap_f · pid $ap_q"
                    fi
                done
                ap_blk+="{\"p\":\"$(json_escape "$ap_f")\",\"sha\":\"$ap_s\",\"u\":\"$(json_escape "$ap_u")\",\"killed\":$ap_k},"
            done <"$ap_dir/cur"
            apps_json="{\"on\":1,\"n\":$ap_n,\"kill\":${ap_kill:-0},\"new\":[${ap_new%,}],\"run\":[${ap_run%,}],\"blk\":[${ap_blk%,}]}"
        fi
        # Jagen op sporen: één keer per opdracht
        if [ -n "$ap_hunt" ]; then
            printf '%s' "$ap_hunt" >"$ap_dir/ioc.done"
            ap_t0=$(date +%s); ap_hits=""; ap_nh=0
            while IFS= read -r ap_b; do
                ap_f=$(printf '%s' "$ap_b" | base64 -d 2>/dev/null) || continue
                [ "${ap_f:0:1}" = / ] || continue
                if [ -e "$ap_f" ]; then ap_hits+="{\"t\":\"path\",\"v\":\"$(json_escape "$ap_f")\",\"p\":\"$(json_escape "$ap_f")\"},"; ap_nh=$((ap_nh + 1)); fi
            done < <(awk -F'\t' '$1 == "0" && $2 == "iocpath" { print $3 }' "$t_cache" | head -n 200)
            awk -F'\t' '$1 == "0" && $2 == "iocsha" && length($3) == 64 && $3 ~ /^[0-9a-f]+$/ { print $3 }' "$t_cache" | head -n 500 | sort -u >"$ap_dir/ioc.sha"
            ap_files=0
            if [ -s "$ap_dir/ioc.sha" ]; then
                {
                    cut -f1 "$ap_dir/cur"
                    ap_ds=()
                    for ap_d in /tmp /var/tmp /dev/shm /usr/local /opt /root /home /srv /var/www; do [ -d "$ap_d" ] && ap_ds+=("$ap_d"); done
                    [ "${#ap_ds[@]}" -gt 0 ] && timeout 60 nice -n 19 find "${ap_ds[@]}" -xdev -maxdepth 6 -type f -size -50M 2>/dev/null
                } 2>/dev/null | awk '!s[$0]++' | head -n 20000 >"$ap_dir/ioc.files"
                ap_files=$(wc -l <"$ap_dir/ioc.files")
                tr '\n' '\0' <"$ap_dir/ioc.files" | timeout 120 nice -n 19 xargs -0 -r sha256sum -- 2>/dev/null \
                    | awk 'NR == FNR { w[$1] = 1; next } ($1 in w) { s = $1; $1 = ""; sub(/^ +\*?/, ""); print s "\t" $0 }' "$ap_dir/ioc.sha" - | head -n 100 >"$ap_dir/ioc.hits"
                while IFS=$'\t' read -r ap_s ap_f; do
                    ap_hits+="{\"t\":\"sha\",\"v\":\"$ap_s\",\"p\":\"$(json_escape "$ap_f")\"},"; ap_nh=$((ap_nh + 1))
                done <"$ap_dir/ioc.hits"
            fi
            rm -f "$ap_dir/ioc.sha" "$ap_dir/ioc.files" "$ap_dir/ioc.hits"
            ap_secs=$(( $(date +%s) - ap_t0 ))
            ioc_json="{\"id\":$ap_hunt,\"files\":$ap_files,\"secs\":$ap_secs,\"hits\":[${ap_hits%,}]}"
            if [ "$ap_nh" -gt 0 ]; then
                act_log ioc 0 "Sporen gevonden" "$ap_nh treffers · $ap_files bestanden doorzocht in $ap_secs s"
            else
                act_log ioc 2 "Gezocht naar sporen" "geen treffers · $ap_files bestanden doorzocht in $ap_secs s"
            fi
        fi
    fi
fi
# ---------------------------------------------------------------- Updates (patchbeheer, agent 1.92.0)
lap patch
# Aan per server in RzSense (regel "0 <tab> patch <tab> 1"):
#  - Linux (als root): eens per uur welke updates er klaarstaan en welke daarvan beveiligingsupdates zijn (dnf
#    check-update [--security], of apt-get -s upgrade; de apt-lijsten worden hooguit eens per 6 uur ververst), en of
#    een herstart nodig is. Installeren gaat via pkg_upgrade (mode security of all, eventueel eerst een snapshot).
#  - Home Assistant (add-on): elke 15 minuten wat er voor Core en de add-ons klaarstaat (Supervisor). Vraagt RzSense
#    erom ("ha_update" in het antwoord), dan werkt de agent Core en/of add-ons bij via de Supervisor, altijd met een
#    back-up vooraf. Het besturingssysteem (OS) niet: dat herstart het hele systeem, dat doe je in Home Assistant zelf.
# Nooit een herstart, nooit op TrueNAS (apt kan TrueNAS kapotmaken).
patch_json=""; ha_upd_json=""
pt_dir="$t_dir/patch"
pt_line=""
[ -r "${t_cache-}" ] && pt_line=$(awk -F'\t' '$1 == "0" && $2 == "patch" && $3 == "1" { print; exit }' "$t_cache")
if [ -n "$pt_line" ] && { [ "$HA_MODE" = 1 ] || [ "$(id -u)" = 0 ]; } && ! command -v midclt >/dev/null 2>&1; then
    mkdir -p "$pt_dir" 2>/dev/null && chmod 700 "$pt_dir" 2>/dev/null
    pt_age=$([ "$HA_MODE" = 1 ] && echo 15 || echo 60)
    pt_fresh=0
    if [ ! -s "$pt_dir/avail.json" ] || [ -n "$(find "$pt_dir/avail.json" -mmin +"$pt_age" 2>/dev/null)" ] \
        || { [ -f "${pk_dir:-/nonexistent}/upgrade.result" ] && [ "${pk_dir}/upgrade.result" -nt "$pt_dir/avail.json" ]; } \
        || [ -f "$pt_dir/ha.result" ]; then
        pt_fresh=1
    fi
    if [ "$pt_fresh" = 1 ] && [ "$HA_MODE" = 0 ]; then
        : >"$pt_dir/list.new"; pt_mgr=""
        if command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
            pt_mgr=rpm; pt_dnf=dnf; command -v dnf >/dev/null 2>&1 || pt_dnf=yum
            # 3 kolommen: naam.arch versie repo; "--security" geeft alleen de beveiligingsupdates
            pt_parse() { awk 'NF == 3 && $1 ~ /\./ && $2 ~ /[0-9]/ { n = $1; sub(/\.[^.]+$/, "", n); print n "\t" $2 }'; }
            timeout 180 nice -n 19 "$pt_dnf" -q check-update 2>/dev/null | pt_parse | sort -u >"$pt_dir/all"
            { timeout 180 nice -n 19 "$pt_dnf" -q check-update --security 2>/dev/null || timeout 180 nice -n 19 "$pt_dnf" -q check-upgrade --security 2>/dev/null; } \
                | pt_parse | cut -f1 | sort -u >"$pt_dir/sec"
            awk -F'\t' 'NR == FNR { s[$1] = 1; next } { print $1 "\t" $2 "\t" (($1 in s) ? 1 : 0) }' "$pt_dir/sec" "$pt_dir/all" >"$pt_dir/list.new"
            pt_rb=0
            if command -v needs-restarting >/dev/null 2>&1; then timeout 60 needs-restarting -r >/dev/null 2>&1; [ $? = 1 ] && pt_rb=1; fi
        elif command -v apt-get >/dev/null 2>&1; then
            pt_mgr=dpkg
            if [ -z "$(find /var/lib/apt/lists -maxdepth 0 -mmin -360 2>/dev/null)" ]; then
                timeout 300 nice -n 19 apt-get update -qq >/dev/null 2>&1
            fi
            # Inst naam [oude versie] (nieuwe versie bron [arch]); bron met "security" = beveiligingsupdate
            timeout 120 nice -n 19 apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null \
                | awk '/^Inst / { v = $0; sub(/^[^(]*\(/, "", v); sub(/ .*/, "", v); print $2 "\t" v "\t" (($0 ~ /[Ss]ecurity/) ? 1 : 0) }' | sort -u >"$pt_dir/list.new"
            pt_rb=0; [ -f /var/run/reboot-required ] && pt_rb=1
        fi
        if [ -n "$pt_mgr" ]; then
            pt_n=$(wc -l <"$pt_dir/list.new"); pt_s=$(awk -F'\t' '$3 == 1' "$pt_dir/list.new" | wc -l)
            pt_list=$(head -n 300 "$pt_dir/list.new" | while IFS=$'\t' read -r a b c; do printf '["%s","%s",%s],' "$(json_escape "$a")" "$(json_escape "$b")" "${c:-0}"; done)
            printf '{"mgr":"%s","avail":%s,"sec":%s,"rb":%s,"at":%s,"list":[%s]}' "$pt_mgr" "$pt_n" "$pt_s" "${pt_rb:-0}" "$(date +%s)" "${pt_list%,}" >"$pt_dir/avail.json"
            patch_json=$(cat "$pt_dir/avail.json")
        fi
        rm -f "$pt_dir/list.new" "$pt_dir/all" "$pt_dir/sec"
    elif [ "$pt_fresh" = 1 ] && [ "$HA_MODE" = 1 ] && [ -n "${SUPERVISOR_TOKEN-}" ] && command -v jq >/dev/null 2>&1; then
        pt_get() { curl -fsS -m 20 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "${SUPERVISOR_URL:-http://supervisor}$1" 2>/dev/null; }
        pt_list=$( {
            pt_get /core/info | jq -c 'select(.data.update_available) | ["core", .data.version_latest, 0, "Home Assistant Core"]'
            pt_get /os/info | jq -c 'select(.data.update_available) | ["os", .data.version_latest, 0, "Home Assistant OS"]'
            pt_get /addons | jq -c '.data.addons[] | select(.update_available) | ["addon:" + .slug, (.version_latest // ""), 0, .name]'
        } 2>/dev/null | paste -sd, -)
        pt_n=$(printf '%s' "$pt_list" | grep -o '\["' | wc -l)
        printf '{"mgr":"ha","avail":%s,"sec":0,"rb":0,"at":%s,"list":[%s]}' "$pt_n" "$(date +%s)" "$pt_list" >"$pt_dir/avail.json"
        patch_json=$(cat "$pt_dir/avail.json")
    fi
    # Uitkomst van het bijwerken van Home Assistant (op de achtergrond gedaan) terugmelden
    if [ -f "$pt_dir/ha.result" ] && [ ! -f "$pt_dir/ha.lock" ]; then
        IFS=$'\t' read -r pt_id pt_ok pt_bad <"$pt_dir/ha.result"
        if [[ "$pt_id" =~ ^[0-9a-f]{8,32}$ ]]; then
            ha_upd_json="{\"id\":\"$pt_id\",\"ok\":\"$(json_escape "$pt_ok")\",\"bad\":\"$(json_escape "$pt_bad")\"}"
        fi
        rm -f "$pt_dir/ha.result"
    fi
fi

ha_update() {  # ha_update <antwoord van RzSense>: Core en/of add-ons bijwerken via de Supervisor, met een back-up vooraf
    local id what w
    id=$(sed -n 's/.*"ha_update":[[:space:]]*{[^}]*"id":[[:space:]]*"\([0-9a-f]\{8,32\}\)".*/\1/p' <<<"$1")
    what=$(sed -n 's/.*"ha_update":[[:space:]]*{[^}]*"what":[[:space:]]*\[\([^]]*\)\].*/\1/p' <<<"$1" | tr -d '"' | tr ',' ' ')
    [ -n "$id" ] && [ -n "$what" ] && [ -n "${SUPERVISOR_TOKEN-}" ] && [ -d "$pt_dir" ] || return 0
    if [ -f "$pt_dir/ha.lock" ] && [ -z "$(find "$pt_dir/ha.lock" -mmin +120 2>/dev/null)" ]; then return 0; fi
    : >"$pt_dir/ha.lock"
    act_log patch 2 "Home Assistant bijwerken gestart" "$what (met back-up vooraf)"
    (
        ok=""; bad=""
        for w in $what; do
            case "$w" in
                core) ep=/core/update ;;
                addon:*) s=${w#addon:}; [[ "$s" =~ ^[a-z0-9_-]{1,64}$ ]] || continue; ep="/store/addons/$s/update" ;;
                *) continue ;;   # OS nooit: dat herstart het hele systeem
            esac
            code=$(curl -sS -m 3600 -o "$pt_dir/ha.out" -w '%{http_code}' -X POST -H "Authorization: Bearer $SUPERVISOR_TOKEN" \
                -H 'Content-Type: application/json' -d '{"backup":true}' "${SUPERVISOR_URL:-http://supervisor}$ep" 2>/dev/null)
            if [ "$code" = 200 ]; then
                ok+="$w "; act_log patch 1 "Bijgewerkt via de Supervisor" "$w (back-up vooraf)"
            else
                bad+="$w ($code) "; act_log patch 0 "Bijwerken via de Supervisor mislukt" "$w: HTTP $code $(head -c 200 "$pt_dir/ha.out" 2>/dev/null | tr -d '\n')"
            fi
        done
        printf '%s\t%s\t%s\n' "$id" "${ok% }" "${bad% }" >"$pt_dir/ha.result"
        rm -f "$pt_dir/ha.lock" "$pt_dir/ha.out" "$pt_dir/avail.json"
    ) </dev/null >/dev/null 2>&1 &
}
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
# ---------------------------------------------------------------- Podman: containers, pods en opslag
lap containers
# Per context: versie, containers (status, health, herstarts, grootte, volumes), pods en opslag.
# Volumegroottes (du) worden een uur bewaard: die kunnen groot zijn en veranderen langzaam.
podman_json=""
# Agent 1.69.0: Docker in dezelfde regels als Podman (V versie, C containers, P compose-projecten als pods, D opslag, M volumes)
docker_lines() {
    local ver ids
    ver=$(pm docker version --format '{{.Server.Version}}' 2>/dev/null) || { printf 'E|docker|%s\n' "docker gaf geen antwoord"; return; }
    printf 'V|docker|docker %s\n' "$ver"
    ids=$(pm docker ps -aq --no-trunc 2>/dev/null)
    [ -n "$ids" ] || return
    # shellcheck disable=SC2086
    declare -A d_ins=()
    while IFS='|' read -r d_id d_exit d_rest d_start d_vols; do
        d_ins[$d_id]="$d_exit|$d_rest|$(date -d "$d_start" +%s 2>/dev/null || echo 0)"
        printf 'M|docker|%s|%s\n' "$d_id" "$d_vols"
    done < <(pm docker inspect --format '{{.Id}}|{{.State.ExitCode}}|{{.RestartCount}}|{{.State.StartedAt}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}' $ids 2>/dev/null)
    while IFS='|' read -r d_id d_name d_img d_state d_status d_proj d_size; do
        IFS='|' read -r d_exit d_rest d_start <<<"${d_ins[$d_id]:-0|0|0}"
        printf 'C|docker|%s|%s|%s|%s|%s|%s|%s|%s|false|%s|%s\n' "$d_id" "$d_name" "$d_img" "$d_state" "$d_status" "$d_exit" "$d_rest" "$d_proj" "$d_start" "$d_size"
    done < <(pm docker ps -a --size --no-trunc --format '{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.Label "com.docker.compose.project"}}|{{.Size}}' 2>/dev/null)
    pm docker ps -a --format '{{.Label "com.docker.compose.project"}}|{{.State}}' 2>/dev/null | awk -F'|' '$1 != "" { n[$1]++; if ($2 == "running") r[$1]++ }
        END { for (p in n) printf "P|docker|%s|%s|%d\n", p, (r[p] == n[p] ? "Running" : r[p] ? "Degraded" : "Exited"), n[p] }'
    pm docker system df --format '{{.Type}}|{{.Size}}|{{.Reclaimable}}|{{.TotalCount}}|{{.Active}}' 2>/dev/null |
        awk -F'|' "$docker_bytes"'{ printf "D|docker|%s|%.0f|%.0f|%s|%s\n", $1, db($2), db($3), $4, $5 }'
}
if [ ${#podman_ctx[@]} -gt 0 ]; then
    pod_stats_b=$(podman_stats b)
    vol_cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    mkdir -p "$vol_cache_dir" 2>/dev/null && [ -w "$vol_cache_dir" ] || vol_cache_dir="${TMPDIR:-/tmp}"
    vol_cache="$vol_cache_dir/volumes.cache"
    now_s=$(date +%s)
    podman_raw=$(
        printf '%s\n' "$pod_stats_a" | sed 's/^S|/A|/'
        printf '%s\n' "$pod_stats_b" | sed 's/^S|/B|/'
        for ctx in "${podman_ctx[@]}"; do
            if [ "$ctx" = docker ]; then
                docker_lines
                continue
            fi
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
lap proxmox
# Alleen op een Proxmox-host, als root: guests (VM's en containers), opslag, ZFS-pools, de cluster, back-ups per guest en de
# back-uptaken. pvesh geeft JSON; dat gaat ongewijzigd mee (RzSense leest het), dus jq is niet nodig.
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

# ---------------------------------------------------------------- TrueNAS SCALE (agent 1.77.0)
# Alleen op TrueNAS, als root: TrueNAS zelf uitlezen met midclt (geen API-sleutel nodig). midclt geeft JSON; dat gaat
# ongewijzigd mee (RzSense leest het), dus jq is niet nodig. Met "select" vragen we alleen de velden die RzSense gebruikt.
# Meldingen, taken en apps elke 5 minuten, schijftemperaturen elk kwartier, of er een update is elke 6 uur.
# TRUENAS=0 in de instellingen zet het uit.
truenas_json=""
if [ "$HA_MODE" = 0 ] && [ "${TRUENAS:-1}" != 0 ] && command -v midclt >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
    tn_dir="$t_dir/truenas"
    mkdir -p "$tn_dir" 2>/dev/null
    tj() {  # tj <tijd> <methode> [argumenten…]: JSON van midclt, of null (hooguit 200 kB)
        local to=$1 o; shift
        o=$(timeout "$to" midclt call "$@" 2>/dev/null | head -c 200000)
        case "$o" in '['*|'{'*) printf '%s' "$o" ;; *) printf 'null' ;; esac
    }
    if ! due_cached "$tn_dir/live" 300; then
        printf '"version":"%s","alerts":%s,"repl":%s,"snap":%s,"cloud":%s,"rsync":%s,"apps":%s,' \
            "$(json_escape "$(head -c 60 /etc/version 2>/dev/null)")" \
            "$(tj 20 alert.list)" \
            "$(tj 20 replication.query '[]' '{"select":["id","name","enabled","state"]}')" \
            "$(tj 20 pool.snapshottask.query '[]' '{"select":["id","dataset","enabled","state"]}')" \
            "$(tj 20 cloudsync.query '[]' '{"select":["id","description","enabled","job"]}')" \
            "$(tj 20 rsynctask.query '[]' '{"select":["id","desc","path","enabled","job"]}')" \
            "$(tj 30 app.query '[]' '{"select":["name","state","upgrade_available","version"]}')" \
            >"$tn_dir/live.tmp" && mv -f "$tn_dir/live.tmp" "$tn_dir/live"
    fi
    if ! due_cached "$tn_dir/temp" 900; then
        printf '"temps":%s,' "$(tj 60 disk.temperatures)" >"$tn_dir/temp.tmp" && mv -f "$tn_dir/temp.tmp" "$tn_dir/temp"
    fi
    if ! due_cached "$tn_dir/upd" 21600; then
        # Nieuwere TrueNAS: update.status; oudere: update.check_available
        tn_u=$(tj 60 update.status)
        [ "$tn_u" = null ] && tn_u=$(tj 60 update.check_available)
        printf '"update":%s,' "$tn_u" >"$tn_dir/upd.tmp" && mv -f "$tn_dir/upd.tmp" "$tn_dir/upd"
    fi
    truenas_json="{$(cat "$tn_dir/live" "$tn_dir/temp" "$tn_dir/upd" 2>/dev/null)\"at\":$(date +%s)}"
fi
# ---------------------------------------------------------------- Pad naar een doel (agent 1.78.0)
# Pad-checks uit RzSense: "id <tab> path <tab> doel <tab> tussenliggende IP's (komma's, of -) <tab> interval".
# Per check: elke hop met verlies en vertraging (mtr, anders traceroute), een ping naar de opgegeven tussenliggende
# apparaten (switches zijn onzichtbaar voor een traceroute), de netwerkkaart van de server (snelheid), of hij virtueel
# draait en, met lldpd, op welke switch en poort hij zit.
# Doorvoer (iperf3): "id <tab> pathtp <tab> ip <tab> poort <tab> sleutel" = nu meten naar dat apparaat (één keer per sleutel);
# "0 <tab> iperfs <tab> poort <tab> tot (epoch)" = hier kort een iperf3-ontvanger starten, die na één meting stopt.
path_json=""; iperfs_json=""
if [ "$TEST" != 1 ] && [ -r "${t_cache-}" ] && grep -q $'\t\(path\|pathtp\|iperfs\)\t' "$t_cache"; then
    lap path
    pa_dir="$t_dir/path"
    mkdir -p "$pa_dir" 2>/dev/null
    pa_ok() { [[ "$1" =~ ^[A-Za-z0-9.:_-]{1,253}$ ]]; }
    pa_root=0; [ "$(id -u)" = 0 ] && pa_root=1
    # Ping: verlies en vertraging (min/gem/max/mdev) als JSON, of null
    pa_ping() {
        local out
        out=$(LC_ALL=C timeout 8 ping -n -c 5 -i 0.2 -W 1 "$1" 2>/dev/null)
        LC_ALL=C awk -v ip="$1" '
            /packet loss/ { for (i = 1; i <= NF; i++) if ($i ~ /%$/) { l = $i; sub(/%/, "", l) } }
            /^(rtt|round-trip)/ { split($4, a, "/"); mn = a[1]; av = a[2]; mx = a[3]; jd = a[4] }
            END { if (l == "") l = 100
                  printf "{\"ip\":\"%s\",\"loss\":%s,\"avg\":%s,\"best\":%s,\"worst\":%s,\"jit\":%s}", ip, l + 0,
                      av == "" ? "null" : av, mn == "" ? "null" : mn, mx == "" ? "null" : mx, jd == "" ? "null" : jd }' <<<"$out"
    }
    # Iperf3-ontvanger op dit apparaat (aangevraagd door RzSense voor de pad-check van een andere server)
    while IFS=$'\t' read -r i_id i_type i_port i_until _; do
        [ "$i_type" = iperfs ] && [[ "$i_port" =~ ^[0-9]{2,5}$ ]] && [[ "$i_until" =~ ^[0-9]+$ ]] || continue
        [ "$i_until" -gt "$(date +%s)" ] || continue
        if ! command -v iperf3 >/dev/null 2>&1; then
            iperfs_json="{\"err\":\"iperf3 ontbreekt\"}"
        elif [ "$(cat "$pa_dir/iperfs" 2>/dev/null)" = "$i_until" ]; then
            iperfs_json="{\"started\":$(stat -c %Y "$pa_dir/iperfs"),\"port\":$i_port}"
        else
            # Na één meting stopt iperf3 zelf (-1); anders na 2 minuten. Buiten de agent-service starten, zodat hij blijft draaien.
            if [ "$pa_root" = 1 ] && command -v systemd-run >/dev/null 2>&1; then
                systemd-run --quiet --collect --unit "rzmon-iperf3-$i_until" timeout 120 iperf3 -s -1 -p "$i_port" >/dev/null 2>&1
            else
                setsid nohup timeout 120 iperf3 -s -1 -p "$i_port" >/dev/null 2>&1 &
            fi
            printf '%s' "$i_until" >"$pa_dir/iperfs"
            iperfs_json="{\"started\":$(date +%s),\"port\":$i_port}"
        fi
        : "$i_id"
        break
    done <"$t_cache"
    # Doorvoer meten (iperf3-client), één keer per aanvraag
    declare -A pa_tp=()
    while IFS=$'\t' read -r t_id t_type t_ip t_port t_key; do
        [ "$t_type" = pathtp ] && [[ "$t_id" =~ ^[0-9]+$ ]] && pa_ok "$t_ip" && [[ "$t_port" =~ ^[0-9]{2,5}$ ]] && [[ "$t_key" =~ ^[0-9A-Za-z]{1,40}$ ]] || continue
        [ "$(cat "$pa_dir/$t_id.tpkey" 2>/dev/null)" = "$t_key" ] && continue
        printf '%s' "$t_key" >"$pa_dir/$t_id.tpkey"
        if ! command -v iperf3 >/dev/null 2>&1; then
            pa_tp[$t_id]="{\"to\":\"$t_ip\",\"err\":\"iperf3 ontbreekt op deze server\",\"key\":\"$t_key\"}"
            continue
        fi
        tp_out=$(LC_ALL=C timeout 15 iperf3 -c "$t_ip" -p "$t_port" -t 3 -f m --connect-timeout 3000 2>&1)
        tp_mbit=$(awk '/receiver/ { for (i = 1; i <= NF; i++) if ($i == "Mbits/sec") v = $(i - 1) } END { print v }' <<<"$tp_out")
        if [ -n "$tp_mbit" ]; then
            pa_tp[$t_id]="{\"to\":\"$t_ip\",\"mbit\":$tp_mbit,\"key\":\"$t_key\"}"
        else
            pa_tp[$t_id]="{\"to\":\"$t_ip\",\"err\":\"$(json_escape "$(grep -m1 -i 'error' <<<"$tp_out" | head -c 160)")\",\"key\":\"$t_key\"}"
        fi
    done <"$t_cache"
    pa_virt=$(systemd-detect-virt 2>/dev/null || true); [ "$pa_virt" = none ] && pa_virt=""
    while IFS=$'\t' read -r p_id p_type p_dst p_via p_iv; do
        [ "$p_type" = path ] && [[ "$p_id" =~ ^[0-9]+$ ]] && pa_ok "$p_dst" || continue
        [[ "$p_iv" =~ ^[0-9]+$ ]] || p_iv=300
        p_file="$pa_dir/$p_id.json"
        if ! due_cached "$p_file" "$p_iv"; then
            # Route naar het doel: netwerkkaart, eigen adres, snelheid (Mbit/s) en LLDP-buur
            p_route=$(ip route get "$p_dst" 2>/dev/null | head -n1)
            p_dev=$(awk '{ for (i = 1; i < NF; i++) if ($i == "dev") print $(i + 1) }' <<<"$p_route" | head -n1)
            p_src=$(awk '{ for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1) }' <<<"$p_route" | head -n1)
            p_gw=$(awk '{ for (i = 1; i < NF; i++) if ($i == "via") print $(i + 1) }' <<<"$p_route" | head -n1)
            p_speed=null
            if [[ "$p_dev" =~ ^[A-Za-z0-9._@-]{1,32}$ ]] && [ -r "/sys/class/net/$p_dev/speed" ]; then
                p_speed=$(cat "/sys/class/net/$p_dev/speed" 2>/dev/null); [[ "$p_speed" =~ ^[0-9]+$ ]] || p_speed=null
            fi
            p_lldp=""
            if [ -n "$p_dev" ] && command -v lldpctl >/dev/null 2>&1; then
                p_lldp=$(timeout 5 lldpctl -f keyvalue "$p_dev" 2>/dev/null | awk -F= -v d="$p_dev" '
                    function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); return substr(s, 1, 80) }
                    $1 == "lldp." d ".chassis.name" { n = $2 } $1 == "lldp." d ".port.descr" { p = $2 } $1 == "lldp." d ".port.ifname" && p == "" { p = $2 }
                    END { if (n != "" || p != "") printf "{\"sys\":\"%s\",\"port\":\"%s\"}", esc(n), esc(p) }')
            fi
            # De hops: mtr (verlies en vertraging per hop), anders traceroute
            p_tool=""; p_hops=""
            if command -v mtr >/dev/null 2>&1; then
                p_tool=mtr
                if [ "$pa_root" = 1 ]; then p_raw=$(LC_ALL=C timeout 30 mtr -r -w -n -c 5 -i 0.2 "$p_dst" 2>/dev/null)
                else p_raw=$(LC_ALL=C timeout 30 mtr -r -w -n -c 5 "$p_dst" 2>/dev/null); fi
                # "  1.|-- 192.168.2.1   0.0%   5   0.4   0.5   0.3   0.8   0.1"
                p_hops=$(LC_ALL=C awk '$1 ~ /^[0-9]+\.\|--$/ { n = $1 + 0; ip = ($2 == "???" ? "" : $2); l = $3; sub(/%/, "", l)
                    printf "{\"n\":%d,\"ip\":\"%s\",\"loss\":%s,\"sent\":%s,\"avg\":%s,\"best\":%s,\"worst\":%s,\"jit\":%s},", n, ip, l + 0, $4 + 0,
                        ip == "" ? "null" : $6 + 0, ip == "" ? "null" : $7 + 0, ip == "" ? "null" : $8 + 0, ip == "" ? "null" : $9 + 0 }' <<<"$p_raw")
            elif command -v traceroute >/dev/null 2>&1; then
                p_tool=traceroute
                p_raw=$(LC_ALL=C timeout 40 traceroute -n -q 3 -w 1 -m 20 "$p_dst" 2>/dev/null)
                # " 1  192.168.2.1  0.512 ms  0.433 ms  0.401 ms" of " 2  * * *"
                p_hops=$(LC_ALL=C awk 'NR > 1 && $1 ~ /^[0-9]+$/ { ip = ""; c = 0; s = 0; mn = ""; mx = ""; st = 0
                    for (i = 2; i <= NF; i++) { if ($i == "*") st++; else if ($i ~ /^[0-9.:a-f]+$/ && $(i + 1) == "ms") { c++; s += $i; if (mn == "" || $i < mn) mn = $i; if ($i > mx) mx = $i }
                        else if (ip == "" && $i ~ /^[0-9a-f.:]+$/ && $(i + 1) != "ms") ip = $i }
                    printf "{\"n\":%d,\"ip\":\"%s\",\"loss\":%d,\"sent\":%d,\"avg\":%s,\"best\":%s,\"worst\":%s,\"jit\":null},", $1, ip, (c + st) ? st * 100 / (c + st) : 100, c + st,
                        c ? sprintf("%.2f", s / c) : "null", c ? mn : "null", c ? mx : "null" }' <<<"$p_raw")
            fi
            p_vj=""
            if [ "$p_via" != "-" ]; then
                for v_ip in ${p_via//,/ }; do
                    pa_ok "$v_ip" && p_vj+="$(pa_ping "$v_ip"),"
                done
            fi
            p_dj=$(pa_ping "$p_dst")
            printf '{"at":%s,"tool":"%s","dst":"%s","dev":"%s","src":"%s","gw":"%s","speed":%s,"virt":"%s","root":%s,"hops":[%s],"via":[%s],"ping":%s%s}' \
                "$(date +%s)" "$p_tool" "$p_dst" "$(json_escape "$p_dev")" "$(json_escape "$p_src")" "$(json_escape "$p_gw")" "$p_speed" "$(json_escape "$pa_virt")" "$pa_root" \
                "${p_hops%,}" "${p_vj%,}" "$p_dj" "${p_lldp:+,\"lldp\":$p_lldp}" >"$p_file.tmp" && mv -f "$p_file.tmp" "$p_file"
            p_fresh=1
        else
            p_fresh=0
        fi
        # Meegaan: een nieuwe meting, of een uitkomst van de doorvoertest
        if [ "$p_fresh" = 1 ] || [ -n "${pa_tp[$p_id]-}" ]; then
            p_body=$(cat "$p_file" 2>/dev/null)
            [ -n "$p_body" ] || p_body='{"none":1}'
            [ -n "${pa_tp[$p_id]-}" ] && p_body="${p_body%\}},\"tp\":${pa_tp[$p_id]}}"
            [ "$p_fresh" = 1 ] || p_body="${p_body%\}},\"old\":1}"
            path_json+="\"$p_id\":$p_body,"
        fi
    done <"$t_cache"
    [ -n "$path_json" ] && path_json="{${path_json%,}}"
fi
# ---------------------------------------------------------------- Certificaten
lap certs
# Per bron: onderwerp (CN), uitgever, vervaldatum (epoch) en of het certificaat vertrouwd wordt.
# RzSense rekent zelf het aantal dagen uit, dus een uur oude gegevens zijn geen probleem.
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
        na=$(openssl x509 -noout -enddate 2>/dev/null <"$blk" | sed -n 's/^notAfter=//p')
        na=$(date -d "$na" +%s 2>/dev/null) || continue
        chain_n=$((chain_n + 1))
        if [ -z "$chain_min" ] || [ "$na" -lt "$chain_min" ]; then
            chain_min=$na
            chain_cn=$(openssl x509 -noout -subject -nameopt RFC2253 2>/dev/null <"$blk" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
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
# Te controleren: CERTS van deze server, plus wat je in RzSense gekozen hebt (regels "0 <tab> cert <tab> bron", 1.59.0)
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
lap cert_search
# Bestanden met een certificaat in de mappen van CERT_DIRS (plus de mappen die je in RzSense opgeeft), om in RzSense
# uit te kiezen. Eén keer per dag, of meteen als RzSense erom vraagt (regel "0 <tab> certscan <tab> teller").
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
lap procs
# Proces-checks uit RzSense: "id <tab> proc <tab> name|pattern <tab> naam of patroon (base64) <tab> gebruiker".
# Per check: aantal, PID's, CPU (%), geheugen (kB) en wanneer het oudste proces startte (om een herstart te zien).
# Plus een lijst van wat er draait (zonder kernelprocessen), om in RzSense uit te kiezen.
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

# ---------------------------------------------------------------- Software (Kwetsbaarheden in RzSense)
lap software
# Geïnstalleerde pakketten met de distributie: RzSense zoekt er de bekende kwetsbaarheden (CVE's) bij.
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
            # 1.95.0: eerst alle programma's verzamelen en dan in één keer opzoeken (pkg_owners, ook gebruikt door Programma's)
            declare -A pk_owner=()
            pk_lines=() ; : >"$pk_dir/exes"
            while read -r proto local pids; do
                for pid in $pids; do
                    exe=$(readlink -f "/proc/$pid/exe" 2>/dev/null) || continue
                    [ -n "$exe" ] || continue
                    pk_lines+=("$exe"$'\t'"$proto"$'\t'"${local##*:}")
                    [ -z "${pk_owner[$exe]+x}" ] && pk_owner[$exe]="" && printf '%s\n' "$exe" >>"$pk_dir/exes"
                done
            done < <(ss_listen tu | awk '{
                    local = $5; if (local !~ /:[0-9]+$/) next
                    addr = local; sub(/:[0-9]+$/, "", addr); gsub(/[\[\]]/, "", addr); sub(/%.*/, "", addr)
                    if (addr ~ /^127\./ || addr == "::1" || addr == "localhost") next
                    pids = ""; s = $0
                    while (match(s, /pid=[0-9]+/)) { pids = pids " " substr(s, RSTART + 4, RLENGTH - 4); s = substr(s, RSTART + RLENGTH) }
                    if (pids != "") print $1, local, pids }' | sort -u)
            while IFS=$'\t' read -r exe o; do [ -z "${pk_owner[$exe]}" ] && pk_owner[$exe]=$o; done < <(pkg_owners "$pk_dir/exes")
            for l in "${pk_lines[@]}"; do
                IFS=$'\t' read -r exe proto port <<<"$l"
                [ -n "${pk_owner[$exe]-}" ] && pk_exp+="[\"${pk_owner[$exe]}\",$port,\"$proto\"],"
            done
            rm -f "$pk_dir/exes"
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
        # Alles meesturen als RzSense deze stand nog niet heeft; anders alleen het controlegetal.
        # Agent 1.66.1: kwam er de vorige keer geen antwoord (bv. RzSense was te traag), dan eerst alleen het controlegetal:
        # vaak heeft RzSense de lijst toen toch opgeslagen. Anders bleef de agent elke minuut de hele lijst sturen.
        if [ "$(cat "$pk_dir/sent" 2>/dev/null)" != "$pk_hash" ] && [ "$(cat "$pk_dir/tried" 2>/dev/null)" = "$pk_hash" ]; then
            pkgs_json="{\"hash\":\"$pk_hash\"}"; rm -f "$pk_dir/tried"
        elif [ "$(cat "$pk_dir/sent" 2>/dev/null)" != "$pk_hash" ]; then
            pkgs_json="{\"hash\":\"$pk_hash\",\"data\":$(cat "$pk_list")}"
            printf '%s' "$pk_hash" >"$pk_dir/tried" 2>/dev/null
        else
            pkgs_json="{\"hash\":\"$pk_hash\"}"
        fi
    fi
fi
# Uitkomst van "pakketten bijwerken" (opdracht uit RzSense) terugmelden: id, ok/error en het einde van de uitvoer (base64)
pkg_result=""
if [ -r "$pk_dir/upgrade.result" ]; then
    IFS=$'\t' read -r pr_id pr_st pr_log <"$pk_dir/upgrade.result"
    [[ "$pr_id" =~ ^[0-9a-f]{8,32}$ ]] && [[ "$pr_log" =~ ^[A-Za-z0-9+/=]*$ ]] \
        && pkg_result="{\"id\":\"$pr_id\",\"ok\":$([ "$pr_st" = ok ] && echo true || echo false),\"log_b64\":\"$pr_log\"}"
fi

pkg_upgrade() {  # pkg_upgrade <antwoord van RzSense>: alleen dnf/yum/apt "upgrade" van de meegestuurde pakketnamen, als root
    # Agent 1.63.0: of "install" (mode), alleen voor de onderdelen van de endpoint-bewaking (vaste lijst hieronder)
    # Agent 1.66.0: met "own" (eigen pakketnamen, door de beheerder bevestigd in RzSense) geldt die lijst niet; de namen
    # zelf moeten wel gewone pakketnamen zijn (geen opties, geen paden), en COMMANDS mag het niet blokkeren
    # Agent 1.92.0: mode "security" (alle beveiligingsupdates) of "all" (alle updates), zonder namen. Nooit op TrueNAS,
    # nooit een herstart. Agent 1.95.1: geen snapshot meer vooraf ("snap" van een oudere RzSense wordt genegeerd).
    local id names n=() out="$pk_dir/upgrade.result" why="" mode=upgrade own=0
    id=$(sed -n 's/.*"pkg_upgrade":[[:space:]]*{[^}]*"id":[[:space:]]*"\([0-9a-f]\{8,32\}\)".*/\1/p' <<<"$1")
    names=$(sed -n 's/.*"pkg_upgrade":[[:space:]]*{[^}]*"pkgs":[[:space:]]*\[\([^]]*\)\].*/\1/p' <<<"$1" | tr -d '"' | tr ',' ' ')
    [[ "$1" =~ \"pkg_upgrade\":[[:space:]]*\{[^}]*\"mode\":[[:space:]]*\"install\" ]] && mode=install
    [[ "$1" =~ \"pkg_upgrade\":[[:space:]]*\{[^}]*\"own\":[[:space:]]*true ]] && own=1
    [[ "$1" =~ \"pkg_upgrade\":[[:space:]]*\{[^}]*\"mode\":[[:space:]]*\"(security|all)\" ]] && mode=${BASH_REMATCH[1]}
    [ -n "$id" ] && [ -d "$pk_dir" ] || return 0
    for p in $names; do
        [[ "$p" =~ ^[A-Za-z0-9][A-Za-z0-9.+_:~-]{0,99}$ ]] || continue
        if [ "$mode" = install ] && [ "$own" = 0 ]; then case "$p" in audit|auditd|inotify-tools|epel-release|yara) ;; *) continue ;; esac; fi
        n+=("$p")
    done
    case "$COMMANDS" in 0|off|no|nee|nobody) why="geblokkeerd op de server (COMMANDS=$COMMANDS in /etc/default/rzmon-agent)" ;; esac
    [ "$(id -u)" = 0 ] || why="de agent draait niet als root"
    [ "${#n[@]}" -gt 0 ] || [ "$mode" = security ] || [ "$mode" = all ] || why="geen geldige pakketnamen ontvangen"
    { command -v midclt >/dev/null 2>&1 || grep -qi truenas /etc/version 2>/dev/null; } && why="TrueNAS: bijwerken via de TrueNAS-interface (System → Update), nooit met apt"
    if [ -n "$why" ]; then
        printf '%s\terror\t%s\n' "$id" "$(printf '%s' "$why" | base64 -w0)" >"$out"
        return 0
    fi
    # Loopt er al een (minder dan 30 minuten geleden gestart)? Dan niet nog een.
    if [ -f "$pk_dir/upgrade.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$pk_dir/upgrade.lock") )) -lt 1800 ]; then return 0; fi
    : >"$pk_dir/upgrade.lock"
    # Adres en token voor de voortgang: in een bestand (alleen root), niet als argument (zichtbaar in ps)
    # Regel 2: wie we zijn (header met het sleutelkenmerk of het token), regel 3: -k, of de vingerafdruk van RzSense
    local tls_spec=0
    [ "${tls[0]-}" = -k ] && tls_spec=1
    [ "${tls[1]-}" = --pinnedpubkey ] && tls_spec=${tls[2]}
    (umask 077; printf '%s\n%s\n%s\n%s\n' "$MONITOR_URL" "${auth[1]}" "$tls_spec" "$enc_key" >"$pk_dir/upgrade.conf")
    # Het script meldt zijn voortgang elke paar seconden zelf aan RzSense (stap, procent, pakket, laatste uitvoer);
    # de uitkomst gaat daarnaast via upgrade.result mee met het volgende rapport (als de voortgang niet aankwam).
    # shellcheck disable=SC2016  # het script krijgt zijn waarden als argumenten mee, niet via de tekst
    local script='id=$1; mgr=${2%%:*}; mode=${2#*:}; dir=$3; shift 3
        [ "$mode" = "$2" ] && mode=upgrade
        url=""; tok=""; ins=0; enc_key=""; last=0; stage=update; pct=2; detail=""; count=""
        if [ -r "$dir/upgrade.conf" ]; then { read -r url; read -r tok; read -r ins; read -r enc_key; } <"$dir/upgrade.conf"; rm -f "$dir/upgrade.conf"; fi
        log="$dir/upgrade.log"; : >"$log"
        post() {   # post [extra JSON-velden]: voortgang naar RzSense (hooguit 4 seconden wachten)
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
            if [ "$mode" = security ]; then cmd=(dnf -y upgrade --security); command -v dnf >/dev/null 2>&1 || cmd=(yum -y update --security)
            elif [ "$mode" = all ]; then cmd=(dnf -y upgrade); command -v dnf >/dev/null 2>&1 || cmd=(yum -y update); fi
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
                if [ "$mode" = security ]; then   # agent 1.92.0: alleen wat uit een security-bron komt
                    # shellcheck disable=SC2046
                    set -- $(apt-get -s upgrade 2>/dev/null | awk "/^Inst / && /[Ss]ecurity/ { print \$2 }" | sort -u)
                    [ $# -gt 0 ] || echo "Geen beveiligingsupdates beschikbaar" >>"$log"
                fi
                acmd=(apt-get install -y -q "$up" -o Dpkg::Options::=--force-confold -o APT::Status-Fd=3 "$@")
                [ "$mode" = all ] && acmd=(apt-get upgrade -y -q -o Dpkg::Options::=--force-confold -o APT::Status-Fd=3)
                [ "$mode" = security ] && [ $# = 0 ] && acmd=(true)
                "${acmd[@]}" 3>&1 >>"$log" 2>&1 \
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
        printf "%s\t%s\t%s\n" "$id" "$([ "$rc" = 0 ] && echo ok || echo error)" "$(tail -c 1400 "$log" | base64 -w0)" >"$dir/upgrade.result.tmp" \
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
        setsid /bin/bash "$pk_dir/upgrade.sh" "$id" "$pk_mgr:$mode" "$pk_dir" "${n[@]}" 9>&- </dev/null >/dev/null 2>&1 &
    fi
}

# ---------------------------------------------------------------- Databases (agent 1.81.0)
# Databasechecks uit RzSense: "id <tab> db <tab> mysql|pgsql <tab> local|container <tab> container (of -) <tab> gebruiker (of -)
# <tab> wachtwoord (base64, of -) <tab> database (of -) <tab> sleutel <tab> interval".
# Per sleutel (één verbinding) één meting, hoe veel checks er ook op die verbinding staan. Alleen lezen: status, verbindingen,
# lopende query's, replicatie en grootte; nooit data. Zonder gebruiker lokaal zonder wachtwoord: MariaDB/MySQL als root via
# de socket, PostgreSQL als gebruiker postgres (peer). In een container: docker/podman exec met de client in die container
# (zonder gebruiker: root met het wachtwoord uit de omgeving van de container, of postgres). Een wachtwoord stuurt RzSense
# alleen over een versleutelde verbinding; het gaat via de omgeving naar de client, nooit op de opdrachtregel.
db_json=""
if [ "$TEST" != 1 ] && [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tdb\t' "$t_cache"; then
    lap db
    db_dir="$t_dir/db"
    mkdir -p "$db_dir" 2>/dev/null && chmod 700 "$db_dir" 2>/dev/null
    db_my_sql="SHOW GLOBAL STATUS;
SELECT 'v:max_connections', @@max_connections;
SELECT 'v:version', VERSION();
SELECT 'v:read_only', @@read_only;
SELECT 'longq', CONCAT(TIME, '|', IFNULL(USER, ''), '|', IFNULL(DB, ''), '|', REPLACE(TO_BASE64(LEFT(INFO, 300)), CHAR(10), '')) FROM information_schema.PROCESSLIST WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID') AND INFO IS NOT NULL AND ID <> CONNECTION_ID() AND TIME >= 5 ORDER BY TIME DESC LIMIT 5;
SHOW SLAVE STATUS\\G
SHOW REPLICA STATUS\\G"
    db_my_size="SELECT CONCAT('size:', table_schema), COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema NOT IN ('information_schema', 'performance_schema', 'sys', 'mysql') GROUP BY table_schema;"
    db_pg_sql="SELECT 'version', current_setting('server_version');
SELECT 'uptime', floor(extract(epoch from now() - pg_postmaster_start_time()))::bigint;
SELECT 'max_conn', current_setting('max_connections');
SELECT 'conn', count(*) FROM pg_stat_activity WHERE backend_type = 'client backend';
SELECT 'active', count(*) FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid();
SELECT 'idle_tx', count(*) FROM pg_stat_activity WHERE state LIKE 'idle in transaction%' AND now() - state_change > interval '5 minutes';
SELECT 'xact', sum(xact_commit + xact_rollback) FROM pg_stat_database;
SELECT 'blks_hit', sum(blks_hit) FROM pg_stat_database;
SELECT 'blks_read', sum(blks_read) FROM pg_stat_database;
SELECT 'deadlocks', sum(deadlocks) FROM pg_stat_database;
SELECT 'temp_files', sum(temp_files) FROM pg_stat_database;
SELECT 'wrap_age', max(age(datfrozenxid)) FROM pg_database;
SELECT 'lock_wait', count(*) FROM pg_locks WHERE NOT granted;
SELECT 'recovery', pg_is_in_recovery()::int;
SELECT 'repl_lag', COALESCE(floor(extract(epoch from now() - pg_last_xact_replay_timestamp()))::bigint, -1) WHERE pg_is_in_recovery();
SELECT 'replicas', count(*) FROM pg_stat_replication;
SELECT 'arch_failed', failed_count FROM pg_stat_archiver;
SELECT 'size:' || datname, pg_database_size(datname) FROM pg_database WHERE datallowconn AND NOT datistemplate;
SELECT 'longq', floor(extract(epoch from now() - query_start))::bigint || '|' || coalesce(usename, '') || '|' || coalesce(datname, '') || '|' || translate(encode(convert_to(left(query, 300), 'UTF8'), 'base64'), E'\\n', '') FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid() AND now() - query_start > interval '5 seconds' ORDER BY query_start LIMIT 5;"
    # Uitvoer (sleutel <tab> waarde, en "  Veld: waarde" van SHOW SLAVE STATUS) als JSON; alleen bekende sleutels
    db_to_json() {  # db_to_json <engine> <ms> <exit> <uitvoerbestand>
        LC_ALL=C awk -v eng="$1" -v ms="$2" -v rc="$3" -v now="$(date +%s)" '
            function esc(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); gsub(/[\001-\037]/, " ", s); return substr(s, 1, 400) }
            BEGIN { my = "^(Uptime|Threads_connected|Threads_running|Questions|Slow_queries|Aborted_connects|Innodb_buffer_pool_reads|Innodb_buffer_pool_read_requests|Innodb_deadlocks|Innodb_row_lock_current_waits|Created_tmp_tables|Created_tmp_disk_tables|Max_used_connections|wsrep_cluster_status|wsrep_cluster_size|wsrep_local_state_comment|wsrep_ready|v:max_connections|v:version|v:read_only)$"
                    pg = "^(version|uptime|max_conn|conn|active|idle_tx|xact|blks_hit|blks_read|deadlocks|temp_files|wrap_age|lock_wait|recovery|repl_lag|replicas|arch_failed)$"
                    rp = "^(Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error|Master_Host|Source_Host)$" }
            /\t/ { k = $0; sub(/\t.*/, "", k); v = $0; sub(/^[^\t]*\t/, "", v)
                   if (k == "longq") { if (++nl <= 5) kv = kv sprintf("\"longq:%d\":\"%s\",", nl, esc(v)); next }
                   if (k ~ /^size:/) { if (++ns <= 60) kv = kv sprintf("\"%s\":\"%s\",", esc(k), esc(v)); next }
                   if (k ~ (eng == "pgsql" ? pg : my)) kv = kv sprintf("\"%s\":\"%s\",", k, esc(v)); next }
            /^ *[A-Za-z_]+: / && eng == "mysql" { k = $0; sub(/^ */, "", k); sub(/:.*/, "", k); v = $0; sub(/^ *[A-Za-z_]+: /, "", v)
                   if (k ~ rp) kv = kv sprintf("\"repl:%s\":\"%s\",", k, esc(v)); next }
            /[A-Za-z]/ && err == "" && !/^\*+/ { err = $0 }
            END { sub(/,$/, "", kv)
                  if (kv == "") printf "{\"ok\":0,\"ms\":%d,\"err\":\"%s\",\"at\":%d}", ms, esc(err == "" ? "geen antwoord (exit " rc ")" : err), now
                  else printf "{\"ok\":1,\"ms\":%d,\"kv\":{%s},\"at\":%d}", ms, kv, now }' "$4"
    }
    # db_run <engine> <waar> <container> <gebruiker> <wachtwoord> <database> <sql>: de query als alleen-lezen client, uitvoer naar stdout
    db_run() {
        local eng=$1 where=$2 ct=$3 usr=$4 pw=$5 dbn=$6 sql=$7 ctx
        if [ "$where" = container ]; then
            if [[ "$ct" == */* ]]; then ctx=${ct%%/*}; ct=${ct#*/}
            elif command -v docker >/dev/null 2>&1 && timeout 5 docker inspect "$ct" >/dev/null 2>&1; then ctx=docker
            else ctx=root; fi
            if [ "$eng" = mysql ]; then
                # shellcheck disable=SC2016  # $… wordt in de container uitgewerkt
                printf '%s\n' "$sql" | MYSQL_PWD="$pw" pm "$ctx" exec -i --env MYSQL_PWD "$ct" sh -c \
                    'c=$(command -v mariadb || command -v mysql) || { echo "geen mariadb/mysql-client in de container"; exit 127; }
                     [ -n "$MYSQL_PWD" ] || MYSQL_PWD=${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}; export MYSQL_PWD
                     exec "$c" -N -B --force --connect-timeout=5 -u "${1:-root}" ${2:+"$2"}' sh "$usr" "$dbn" 2>&1
            else
                if [ -n "$usr" ]; then
                    printf '%s\n' "$sql" | PGPASSWORD="$pw" pm "$ctx" exec -i --env PGPASSWORD "$ct" psql -X -A -t -F $'\t' -q -h 127.0.0.1 -U "$usr" -d "${dbn:-postgres}" 2>&1
                else
                    printf '%s\n' "$sql" | pm "$ctx" exec -i -u postgres "$ct" psql -X -A -t -F $'\t' -q -d "${dbn:-postgres}" 2>&1
                fi
            fi
        elif [ "$eng" = mysql ]; then
            local c; c=$(command -v mariadb || command -v mysql) || { echo "mariadb/mysql-client ontbreekt op de server"; return 127; }
            printf '%s\n' "$sql" | MYSQL_PWD="$pw" timeout 20 "$c" -N -B --force --connect-timeout=5 -u "${usr:-root}" ${dbn:+"$dbn"} 2>&1
        else
            command -v psql >/dev/null 2>&1 || { echo "psql ontbreekt op de server (postgresql-client)"; return 127; }
            if [ -n "$usr" ]; then
                printf '%s\n' "$sql" | PGPASSWORD="$pw" PGCONNECT_TIMEOUT=5 timeout 20 psql -X -A -t -F $'\t' -q -h 127.0.0.1 -U "$usr" -d "${dbn:-postgres}" 2>&1
            elif [ "$(id -u)" = 0 ]; then
                (cd / && printf '%s\n' "$sql" | PGCONNECT_TIMEOUT=5 timeout 20 runuser -u postgres -- psql -X -A -t -F $'\t' -q -d "${dbn:-postgres}" 2>&1)
            else
                echo "zonder gebruiker kan alleen een agent als root PostgreSQL lezen (als gebruiker postgres)"; return 1
            fi
        fi
    }
    db_seen=" "
    while IFS=$'\t' read -r d_id d_type d_eng d_where d_ct d_user d_pass d_name d_key d_ival _; do
        [ "$d_type" = db ] && [[ "$d_id" =~ ^[0-9]+$ ]] && [[ "$d_key" =~ ^[0-9a-f]{12}$ ]] || continue
        case "$db_seen" in *" $d_key "*) continue ;; esac
        db_seen+="$d_key "
        [[ "$d_eng" =~ ^(mysql|pgsql)$ ]] && [[ "$d_where" =~ ^(local|container)$ ]] || continue
        [ "$d_ct" = - ] && d_ct=""; [ "$d_user" = - ] && d_user=""; [ "$d_name" = - ] && d_name=""
        [[ "$d_ct" =~ ^([A-Za-z0-9_.-]{1,32}/)?[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$|^$ ]] && [[ "$d_user" =~ ^[A-Za-z0-9_.@-]{0,80}$ ]] && [[ "$d_name" =~ ^[A-Za-z0-9_$-]{0,64}$ ]] || continue
        [ "$d_where" = container ] && [ -z "$d_ct" ] && continue
        d_pw=""
        [ "$d_pass" != - ] && [ -n "$d_pass" ] && d_pw=$(printf '%s' "$d_pass" | base64 -d 2>/dev/null)
        [[ "$d_ival" =~ ^[0-9]+$ ]] || d_ival=60
        d_out="$db_dir/$d_key.json"
        if ! due_cached "$d_out" "$d_ival"; then
            d_raw="$db_dir/$d_key.raw"
            d_t0=$(now_ms)
            if [ "$d_eng" = mysql ]; then d_sql=$db_my_sql; else d_sql=$db_pg_sql; fi
            db_run "$d_eng" "$d_where" "$d_ct" "$d_user" "$d_pw" "$d_name" "$d_sql" > "$d_raw"
            d_rc=$?
            d_ms=$(( $(now_ms) - d_t0 ))
            # Grootte per database (MariaDB/MySQL) hooguit eens per half uur: information_schema.tables kan traag zijn
            if [ "$d_eng" = mysql ] && grep -q $'^Uptime\t' "$d_raw"; then
                due_cached "$db_dir/$d_key.size" 1800 || db_run mysql "$d_where" "$d_ct" "$d_user" "$d_pw" "$d_name" "$db_my_size" | grep $'^size:' > "$db_dir/$d_key.size"
                cat "$db_dir/$d_key.size" >> "$d_raw" 2>/dev/null
            fi
            db_to_json "$d_eng" "$d_ms" "$d_rc" "$d_raw" > "$d_out.tmp" && mv -f "$d_out.tmp" "$d_out"
            rm -f "$d_raw"
        fi
        [ -s "$d_out" ] && db_json+="\"$d_key\":$(head -c 20000 "$d_out"),"
    done < "$t_cache"
    unset d_pw d_pass
    [ -n "$db_json" ] && db_json="{${db_json%,}}"
fi
# ---------------------------------------------------------------- Logs vinden (agent 1.84.0, RzSense 1.213.0)
lap log_scan
# Welke logs zijn er op deze server, om in RzSense (Agent Manager › Logs) uit te kiezen: bestanden en mappen binnen
# LOG_DIRS, containers (Docker/Podman), systemd-services die de afgelopen 24 uur iets in de journal schreven, en op
# Home Assistant de Supervisor-logs en add-ons. Alleen namen, groottes en tijden, nooit de inhoud.
# Eén keer per dag, of meteen als RzSense erom vraagt (regel "0 <tab> logscan <tab> teller"). Het resultaat gaat alleen mee
# in het rapport als het net gemaakt is (anders niets: RzSense bewaart de laatste lijst).
# Overgeslagen: gedraaide bestanden (.1, .gz, -20250101), binaire bestanden (wtmp, btmp, lastlog) en de map journal.
logscan_json=""
if [ "$TEST" != 1 ] && [ "${LOG_SHIP:-1}" != 0 ]; then
    lv_req=$( [ -r "${t_cache-}" ] && awk -F'\t' '$1 == "0" && $2 == "logscan" { print $3; exit }' "$t_cache")
    lv_cache="$t_dir/logscan.cache"
    lv_key=$(printf '%s|%s' "$LOG_DIRS" "$lv_req" | cksum | cut -d' ' -f1)
    lv_now=$(date +%s)
    if ! { [ -r "$lv_cache" ] && [ "$(sed -n 1p "$lv_cache")" = "$lv_key" ] && [ $((lv_now - $(sed -n 2p "$lv_cache"))) -lt 86400 ]; }; then
        lv_files=""; lv_n=0; lv_noread=""
        for lv_d in $LOG_DIRS; do
            [[ "$lv_d" == /* && "$lv_d" != *..* && -d "$lv_d" ]] || continue
            # Mappen zonder leesrechten (tot 2 diep): wel noemen, zodat je weet waarom ze ontbreken
            lv_c=0
            while IFS= read -r -d '' x; do
                lv_c=$((lv_c + 1)); [ "$lv_c" -gt 200 ] && break
                { [ -r "$x" ] && [ -x "$x" ]; } || lv_noread+="\"$(json_escape "$x")\","
            done < <(find "$lv_d" -xdev -maxdepth 2 -mindepth 1 -type d ! -name journal -print0 2>/dev/null)
            while IFS= read -r -d '' f; do
                case "${f##*/}" in
                    *.gz|*.xz|*.bz2|*.zst|*.zip|*.old|*.[0-9]|*.[0-9].log|*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]|*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].log) continue ;;
                    wtmp*|btmp*|lastlog|faillog|*.journal|*.journal~|*.pid|*.lock|*.sock|*.db|*.sqlite*) continue ;;
                esac
                [ -r "$f" ] || continue
                read -r lv_sz lv_mt < <(stat -c '%s %Y' -- "$f" 2>/dev/null) || continue
                # Binair? (NUL-tekens in het begin)
                if [ "${lv_sz:-0}" -gt 0 ]; then
                    lv_a=$(head -c 2048 -- "$f" 2>/dev/null | wc -c); lv_b=$(head -c 2048 -- "$f" 2>/dev/null | tr -d '\000' | wc -c)
                    [ "$lv_a" = "$lv_b" ] || continue
                fi
                lv_files+="[\"$(json_escape "$f")\",${lv_sz:-0},${lv_mt:-0}],"
                lv_n=$((lv_n + 1)); [ "$lv_n" -ge 500 ] && break 2
            done < <(find "$lv_d" -xdev -maxdepth 3 \( -name journal -o -name private \) -prune -o -type f -print0 2>/dev/null)
        done
        # Containers (ook gestopte)
        lv_dk=""
        for c in docker podman; do
            command -v "$c" >/dev/null 2>&1 || continue
            while IFS=$'\t' read -r n i s; do
                [[ "$n" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] || continue
                lv_dk+="[\"$n\",\"$(json_escape "${i:0:120}")\",\"$(json_escape "${s:0:40}")\",\"$c\"],"
            done < <(timeout 15 "$c" ps -a --format '{{.Names}}\t{{.Image}}\t{{.State}}' 2>/dev/null | head -n 150)
            [ -n "$lv_dk" ] && break
        done
        # Services die de afgelopen 24 uur iets logden (met het aantal regels)
        lv_un=""
        if [ "$HA_MODE" != 1 ] && command -v journalctl >/dev/null 2>&1; then
            lv_un=$(timeout 25 journalctl -q --no-pager --since "-24h" -o export --output-fields=_SYSTEMD_UNIT 2>/dev/null | head -n 400000 |
                awk -F= '$1 == "_SYSTEMD_UNIT" && $2 ~ /^[A-Za-z0-9@._:-]+$/ { n[$2]++ }
                    END { c = 0; for (u in n) { if (c++ >= 150) break; printf "[\"%s\",%d],", u, n[u] } }')
        fi
        # Home Assistant: de Supervisor-logs en de add-ons
        lv_ha=""
        if [ "$HA_MODE" = 1 ] && [ -n "${SUPERVISOR_TOKEN-}" ] && command -v jq >/dev/null 2>&1; then
            lv_ha='["host","Host","started"],["core","Home Assistant Core","started"],["supervisor","Supervisor","started"],'
            lv_ha+=$(sv /addons | jq -r '.data.addons[]? | select(.slug | test("^[a-z0-9_]{1,64}$")) | "[\"addon:\(.slug)\",\(.name | @json),\(.state | @json)],"' 2>/dev/null | head -n 100 | tr -d '\n')
        fi
        logscan_json="{\"at\":$lv_now,\"req\":${lv_req:-0},\"dirs\":\"$(json_escape "$LOG_DIRS")\",\"files\":[${lv_files%,}],\"noread\":[${lv_noread%,}],\"docker\":[${lv_dk%,}],\"units\":[${lv_un%,}],\"ha\":[${lv_ha%,}]}"
        (umask 077; printf '%s\n%s\n' "$lv_key" "$lv_now" >"$lv_cache") 2>/dev/null || true
    fi
fi
# ---------------------------------------------------------------- Bijwerken op afstand
lap update
# Kan deze agent zichzelf bijwerken, en hoe? Gestart door systemd (afgeschermd: /usr is alleen-lezen)
# → via systemd-run als aparte taak; via cron of met de hand → direct.
upd_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
upd_setup="$AGENT_SETUP"
if [ "$TEST" = 1 ]; then
    upd_via="test"   # verbindingstest: RzSense stuurt dan geen update-opdracht
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

# Ondertekende updates (agent 1.71.0): de publieke sleutel van de ontwikkelaar (ed25519). Bij elke nieuwe agent zet GitHub er
# een handtekening bij (agent/*.sig, gemaakt met de geheime sleutel die alleen in GitHub staat). Een update wordt alleen
# geïnstalleerd als die klopt: zo kan zelfs een overgenomen RzSense geen eigen code op de servers zetten.
AGENT_SIGN_PUB="MCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM="
sig_check() {  # sig_check <bestand> <handtekening (base64)>: 0 = klopt, 1 = klopt niet, 2 = niet te controleren (OpenSSL ouder dan 3)
    local d rc
    command -v openssl >/dev/null 2>&1 && openssl pkeyutl -help 2>&1 | grep -q -- '-rawin' || return 2
    d=$(mktemp -d "${TMPDIR:-/tmp}/rzmon-sig.XXXXXX") || return 2
    printf -- '-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n' "$AGENT_SIGN_PUB" >"$d/pub.pem"
    if ! base64 -d <"$2" >"$d/sig" 2>/dev/null || [ "$(wc -c <"$d/sig")" != 64 ]; then rm -rf "$d"; return 1; fi
    openssl pkeyutl -verify -pubin -inkey "$d/pub.pem" -rawin -in "$1" -sigfile "$d/sig" >/dev/null 2>&1; rc=$?
    rm -rf "$d"
    [ "$rc" = 0 ] && return 0 || return 1
}
# UPDATE_UNSIGNED=1 in /etc/default/rzmon-agent: ook zonder (geldige) handtekening bijwerken (niet aangeraden)
UPDATE_UNSIGNED="${UPDATE_UNSIGNED:-0}"
remote_update() {  # remote_update <antwoord van RzSense>
    local want sha_a sha_s k
    want=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"version":[[:space:]]*"\([0-9.]*\)".*/\1/p' <<<"$1")
    sha_a=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"agent_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    sha_s=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"setup_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    case "$upd_via" in systemd|direct) ;; *) return 0 ;; esac
    [ -n "$want" ] && [ -n "$sha_a" ] && [ -n "$sha_s" ] || return 0
    # Agent 1.87.0: nooit terug naar een oudere versie (ook niet als die netjes ondertekend is: die kan een bekende fout hebben)
    if [ "$want" != "$AGENT_VERSION" ] && [ "$(printf '%s\n%s\n' "$want" "$AGENT_VERSION" | sort -V | tail -1)" = "$AGENT_VERSION" ]; then
        printf 'error %s\n' "versie $want is ouder dan $AGENT_VERSION: niet teruggezet" >"$upd_dir/update.result" 2>/dev/null
        return 0
    fi
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
        upd_fail "downloaden van de nieuwe versie bij RzSense mislukt"
        return 0
    fi
    if [ "$(sha256sum "$upd_dir/agent.new" | cut -d' ' -f1)" != "$sha_a" ] || [ "$(sha256sum "$upd_dir/setup.new" | cut -d' ' -f1)" != "$sha_s" ]; then
        upd_fail "controlegetal (SHA-256) klopt niet: download onvolledig of onderweg gewijzigd"
        return 0
    fi
    # Handtekening van de ontwikkelaar (agent 1.71.0). Het installatiescript krijgt bij RzSense een downloadsleutel in de regel
    # DL_KEY=''; die ene regel gaat terug naar leeg voor de controle, verder moet alles precies kloppen.
    if [ "$UPDATE_UNSIGNED" != 1 ]; then
        if ! curl "${k[@]}" -o "$upd_dir/agent.sig" "${MONITOR_URL%/}/api.php?action=agent_script_sig" 2>/dev/null \
            || ! curl "${k[@]}" -o "$upd_dir/setup.sig" "${MONITOR_URL%/}/api.php?action=agent_install_sig" 2>/dev/null; then
            upd_fail "geen handtekening bij RzSense (nog niet ondertekend?): niet bijgewerkt"
            rm -f "$upd_dir/agent.sig" "$upd_dir/setup.sig"
            return 0
        fi
        sed "s/^DL_KEY='[A-Za-z0-9]*'\$/DL_KEY=''/" "$upd_dir/setup.new" >"$upd_dir/setup.chk"
        sig_check "$upd_dir/agent.new" "$upd_dir/agent.sig"; s_a=$?
        sig_check "$upd_dir/setup.chk" "$upd_dir/setup.sig"; s_s=$?
        rm -f "$upd_dir/agent.sig" "$upd_dir/setup.sig" "$upd_dir/setup.chk"
        if [ "$s_a" = 1 ] || [ "$s_s" = 1 ]; then
            upd_fail "handtekening klopt niet: deze versie komt niet van de ontwikkelaar; niet bijgewerkt"
            echo bad >"$upd_dir/update.sig"
            return 0
        fi
        # Agent 1.87.0: OpenSSL ouder dan 3.0 kan de handtekening niet controleren: dan niet bijwerken, tenzij je dat zelf
        # toestaat (UPDATE_OLD_OPENSSL=1); anders kon een overgenomen RzSense op zulke servers toch eigen code zetten
        if { [ "$s_a" = 2 ] || [ "$s_s" = 2 ]; } && [ "${UPDATE_OLD_OPENSSL:-0}" != 1 ]; then
            upd_fail "handtekening niet te controleren (OpenSSL ouder dan 3.0): niet bijgewerkt; zet UPDATE_OLD_OPENSSL=1 in /etc/default/rzmon-agent om toch bij te werken"
            echo old_openssl >"$upd_dir/update.sig"
            return 0
        fi
        if [ "$s_a" = 2 ]; then echo old_openssl >"$upd_dir/update.sig"; else echo ok >"$upd_dir/update.sig"; fi
    else
        echo off >"$upd_dir/update.sig"
    fi
    if ! bash -n "$upd_dir/agent.new" 2>/dev/null || ! bash -n "$upd_dir/setup.new" 2>/dev/null \
        || ! grep -q "^AGENT_VERSION=\"$want\"" "$upd_dir/agent.new" || ! grep -qE 'RzMon agent|RzSense agent' "$upd_dir/setup.new"; then
        upd_fail "de download is geen geldige RzSense-agent $want"
        return 0
    fi
    chmod 700 "$upd_dir/setup.new"
    # Het installatiescript doet de rest (zoals bij sudo rzmon-agent-setup --update), met de gecontroleerde bestanden
    local args=(/bin/bash "$upd_dir/setup.new" --update --yes --file "$upd_dir/agent.new" --result "$upd_dir/update.result")
    if [ "$upd_via" = systemd ]; then
        systemd-run --quiet --collect --unit="rzmon-agent-update-$(date +%s)" "${args[@]}" >/dev/null 2>&1 \
            || upd_fail "systemd-run mislukt; werk bij met: sudo rzmon-agent-setup --update"
    else
        setsid "${args[@]}" 9>&- </dev/null >/dev/null 2>&1 &
    fi
}

# ---------------------------------------------------------------- Benodigdheden voor realtime (elk uur)
lap rt_tools
# Is inotify-tools er (bestanden in realtime), en op Rocky/Alma/RHEL: staat EPEL erop? RzSense toont het in de Agent Manager.
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
lap rt_status
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
lap heartbeats
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
    done < <(find "$hb_dir" -maxdepth 1 -name 'hb.*' -type f -printf '%T@ %p\n' 2>/dev/null | sort -n | head -n 200 | cut -d' ' -f2-)   # agent 1.87.0: zonder glob (veel bestanden)
    # Map voor rzmon-hb: iedereen mag erin schrijven, maar niemand mag lezen of andermans regels weghalen
    [ "$(id -u)" = 0 ] && chmod 1733 "$hb_dir" 2>/dev/null
elif [ "$(id -u)" = 0 ] && [ "$HA_MODE" != 1 ]; then
    { mkdir -p "$hb_dir" && chmod 1733 "$hb_dir"; } 2>/dev/null
fi

# ---------------------------------------------------------------- Zelf herstellen en waakhond: meesturen
lap heal_report
# Uitkomsten van herstelpogingen (weg na een geslaagd rapport), de herstelcommando's die hier staan, en een periode
# waarin de waakhond RzSense niet bereikte
heal_json=""; heal_cmds=""
if [ -s "$heal_dir/done" ]; then
    # Agent 1.87.0: hooguit 50 per rapport, de rest blijft voor het volgende (eerder gingen die verloren)
    grep -E '^\{"id":[0-9]+,"ok":[01],"t":[0-9]+,"m":"([^"\\]|\\.)*"\}$' "$heal_dir/done" 2>/dev/null >"$heal_dir/done.all"; rm -f "$heal_dir/done"
    [ -s "$heal_dir/done.sending" ] && { cat "$heal_dir/done.all" >>"$heal_dir/done.sending"; rm -f "$heal_dir/done.all"; } || mv -f "$heal_dir/done.all" "$heal_dir/done.sending"
    heal_json=$(head -n 50 "$heal_dir/done.sending" | paste -sd, -)
fi
if [ "$HA_MODE" = 0 ] && [ -d "$HEAL_DIR" ]; then
    for hc_f in "$HEAL_DIR"/*; do
        hc_n=${hc_f##*/}
        [[ "$hc_n" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] && heal_cmd_ok "$hc_f" && heal_cmds+="${heal_cmds:+,}\"$hc_n\""
    done
fi
# Agent 1.89.0: logboek (act_log) meesturen: hooguit 100 regels per rapport, de rest blijft voor het volgende
act_json=""
if [ -s "$t_dir/act.log" ] || [ -s "$t_dir/act.sending" ]; then
    if [ -s "$t_dir/act.log" ]; then
        grep -E '^\{"id":"[A-Za-z0-9._-]{4,40}","t":[0-9]+,"m":"[a-z]{0,20}","ok":[012]?,"a":"([^"\\]|\\.)*","d":"([^"\\]|\\.)*"\}$' "$t_dir/act.log" 2>/dev/null >>"$t_dir/act.sending"
        rm -f "$t_dir/act.log"
    fi
    act_json=$(head -n 100 "$t_dir/act.sending" 2>/dev/null | paste -sd, -)
fi
wd_file="$t_dir/watchdog"
wd_gap=""
[ -s "$wd_file.gap" ] && wd_gap=$(grep -E '^\{"down":[0-9]+,"up":[0-9]+,"sent":[01],"self":[01]\}$' "$wd_file.gap" | head -n 1)

# ---------------------------------------------------------------- Klok, schijfgezondheid en UPS (agent 1.70.0)
lap time_smart_ups
# Klok: gesynchroniseerd en hoeveel ms hij afwijkt (chrony of systemd-timesyncd), voor de check Tijd
time_json=""
if [ "$HA_MODE" = 0 ]; then
    if command -v chronyc >/dev/null 2>&1 && t_out=$(timeout 5 chronyc -n tracking 2>/dev/null) && [ -n "$t_out" ]; then
        t_off=$(awk -F': *' '/^System time/ { split($2, a, " "); v = a[1] * 1000; if ($2 ~ /slow/) v = -v; printf "%.3f", v }' <<<"$t_out")
        t_sync=$(awk -F': *' '/^Leap status/ { print ($2 == "Normal") ? "true" : "false" }' <<<"$t_out")
        time_json="{\"src\":\"chrony\",\"sync\":${t_sync:-false},\"offset_ms\":${t_off:-null}}"
    elif command -v timedatectl >/dev/null 2>&1 && t_sync=$(timeout 5 timedatectl show -p NTPSynchronized --value 2>/dev/null) && [ -n "$t_sync" ]; then
        t_off=$(timeout 5 timedatectl timesync-status 2>/dev/null | awk -F': *' '/Offset:/ { v = $2; m = 1; if (v ~ /us/) m = 0.001; else if (v ~ /ms/) m = 1; else if (v ~ /s$/) m = 1000; gsub(/[^0-9.+-]/, "", v); printf "%.3f", v * m; exit }')
        time_json="{\"src\":\"timesyncd\",\"sync\":$( [ "$t_sync" = yes ] && echo true || echo false),\"offset_ms\":${t_off:-null}}"
    fi
fi
# Schijfgezondheid (smartctl, smartmontools): elk half uur, schijven in slaapstand worden niet wakker gemaakt
smart_json=""
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ] && [ "$(id -u)" = 0 ] && command -v smartctl >/dev/null 2>&1; then
    sm_cache="$t_dir/smart.cache"
    if [ -s "$sm_cache" ] && [ $(( $(date +%s) - $(stat -c %Y "$sm_cache") )) -lt 1800 ]; then
        smart_json=$(cat "$sm_cache")
    else
        smart_json=$(timeout 20 smartctl --scan 2>/dev/null | awk '$1 ~ /^\/dev\// { print $1 " " ($2 == "-d" ? $3 : "auto") }' | head -n 24 | while read -r sm_dev sm_type; do
            sm_out=$(timeout 20 smartctl -n standby -H -i -A -d "$sm_type" "$sm_dev" 2>/dev/null); sm_rc=$?
            if grep -qi 'STANDBY\|SLEEP' <<<"$sm_out" && [ $(( sm_rc & 2 )) -ne 0 ]; then
                printf '{"dev":"%s","standby":true},' "$(json_escape "$sm_dev")"; continue
            fi
            awk -v dev="$sm_dev" '
                function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); return s }
                /^(Device Model|Model Number|Product):/ { sub(/^[^:]*: */, ""); model = $0 }
                /overall-health self-assessment test result:|SMART Health Status:/ { passed = ($0 ~ /PASSED|: OK/) ? "true" : "false" }
                /^Temperature:/ { temp = $2 + 0 }
                /^Percentage Used:/ { v = $3; gsub(/%/, "", v); wear = v + 0 }
                /^Media and Data Integrity Errors:/ { gsub(/,/, "", $NF); media = $NF + 0 }
                $2 == "Reallocated_Sector_Ct" { realloc = $NF + 0 }
                $2 == "Current_Pending_Sector" { pending = $NF + 0 }
                ($2 == "Temperature_Celsius" || $2 == "Airflow_Temperature_Cel") && temp == "" { temp = $10 + 0 }
                ($2 == "Percent_Lifetime_Remain" || $2 == "SSD_Life_Left" || $2 == "Wear_Leveling_Count" || $2 == "Media_Wearout_Indicator") && wear == "" { wear = 100 - ($4 + 0) }
                END {
                    if (passed == "" && model == "") { printf "{\"dev\":\"%s\",\"error\":\"niet uit te lezen\"},", esc(dev); exit }
                    printf "{\"dev\":\"%s\",\"model\":\"%s\",\"passed\":%s,\"temp\":%s,\"wear\":%s,\"realloc\":%s,\"pending\":%s,\"media_err\":%s},",
                        esc(dev), esc(model), passed == "" ? "null" : passed, temp == "" ? "null" : temp, wear == "" ? "null" : wear,
                        realloc == "" ? "null" : realloc, pending == "" ? "null" : pending, media == "" ? "null" : media
                }' <<<"$sm_out"
        done)
        smart_json="[${smart_json%,}]"
        (umask 077; printf '%s' "$smart_json" >"$sm_cache") 2>/dev/null
    fi
fi
# UPS via NUT (upsc): status (OL op het net, OB op de batterij, LB bijna leeg), lading, resterende tijd, belasting
ups_json=""
if [ "$HA_MODE" = 0 ] && command -v upsc >/dev/null 2>&1; then
    for u_name in $(timeout 5 upsc -l 2>/dev/null | head -n 8); do
        u_out=$(timeout 5 upsc "$u_name@localhost" 2>/dev/null) || continue
        ups_json+=$(awk -F': ' -v n="$u_name" '
            $1 == "ups.status" { st = $2 } $1 == "battery.charge" { ch = $2 + 0 } $1 == "battery.runtime" { rt = $2 + 0 }
            $1 == "ups.load" { ld = $2 + 0 } $1 == "device.model" || $1 == "ups.model" { m = $2; gsub(/"/, "", m) }
            END { printf "{\"name\":\"%s\",\"status\":\"%s\",\"charge\":%s,\"runtime\":%s,\"load\":%s,\"model\":\"%s\"},", n, st,
                ch == "" ? "null" : ch, rt == "" ? "null" : rt, ld == "" ? "null" : ld, m }' <<<"$u_out")
    done
    [ -n "$ups_json" ] && ups_json="[${ups_json%,}]"
fi
# WireGuard (agent 1.75.0): per peer de laatste handshake (seconden geleden, -1 = nog nooit), verkeer en endpoint.
# Alleen als root (wg show heeft root nodig) en alleen tunnels die deze server zelf heeft (niet die in een container).
wg_json=""
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && command -v wg >/dev/null 2>&1; then
    wg_json=$(timeout 5 wg show all dump 2>/dev/null | awk -F'\t' -v now="$(date +%s)" '
        NF >= 9 && $1 ~ /^[A-Za-z0-9_.-]+$/ {
            k = $2; gsub(/[^A-Za-z0-9]/, "", k); k = substr(k, 1, 8)
            ep = ($4 == "(none)") ? "" : $4; gsub(/[^0-9A-Za-z.:\[\]-]/, "", ep)
            hs = ($6 ~ /^[0-9]+$/ && $6 > 0) ? now - $6 : -1
            printf "{\"id\":\"%s:%s\",\"iface\":\"%s\",\"peer\":\"%s\",\"endpoint\":\"%s\",\"hs\":%.0f,\"rx\":%s,\"tx\":%s},", $1, k, $1, k, ep, hs,
                ($7 ~ /^[0-9]+$/ ? $7 : 0), ($8 ~ /^[0-9]+$/ ? $8 : 0)
            if (++n >= 64) exit
        }')
    wg_json="[${wg_json%,}]"
fi


# Netwerkverkeer (agent 1.80.0): bytes van de echte netwerkkaarten (met een apparaat erachter, plus bond en wifi),
# voor Netwerk → Verkeer als UniFi deze server niet ziet. Een poort van een bond telt via de bond zelf (niet dubbel).
nic_json=""
nic_rx=0
nic_tx=0
nic_ifs=""
for nic_d in /sys/class/net/*; do
    nic_i=${nic_d##*/}
    [ "$nic_i" = lo ] && continue
    [[ "$nic_i" =~ ^[A-Za-z0-9_.-]{1,15}$ ]] || continue
    [ -e "$nic_d/master" ] && continue
    if [ ! -e "$nic_d/device" ]; then
        case "$nic_i" in bond*|wl*) ;; *) continue ;; esac
    fi
    nic_r=$(cat "$nic_d/statistics/rx_bytes" 2>/dev/null)
    nic_t=$(cat "$nic_d/statistics/tx_bytes" 2>/dev/null)
    [[ "$nic_r" =~ ^[0-9]+$ && "$nic_t" =~ ^[0-9]+$ ]] || continue
    nic_rx=$((nic_rx + nic_r))
    nic_tx=$((nic_tx + nic_t))
    nic_ifs+="\"$nic_i\","
done
[ -n "$nic_ifs" ] && nic_json="{\"rx\":$nic_rx,\"tx\":$nic_tx,\"ifs\":[${nic_ifs%,}]}"
# ---------------------------------------------------------------- Diagnose voor AI-advies (agent 1.76.0)
# Op verzoek van RzSense (regel "0 <tab> diag <tab> id <tab> soort <tab> doel" in de lijst) bij een storing: een VASTE set
# commando's die alleen lezen, zodat het AI-advies weet wat er echt aan de hand is. RzSense stuurt alleen de soort en het
# doel (een servicenaam, container, mount of poort), nooit een commando; het doel wordt hier opnieuw streng gecontroleerd.
# In RzSense staat dit apart aan (Instellingen → AI-advies, met je wachtwoord); COMMANDS=0 hierboven blokkeert het altijd.
# Elke aanvraag één keer. Per onderdeel hooguit 20 seconden en 6 kB uitvoer; wachtwoorden filtert RzSense er nog uit.
diag_json=""
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ] && [ "$COMMANDS" != 0 ] && [ -r "${t_cache-}" ]; then
    d_line=$(awk -F'\t' '$1 == "0" && $2 == "diag" { print; exit }' "$t_cache")
    if [ -n "$d_line" ]; then
        IFS=$'\t' read -r _ _ d_id d_kind d_target <<<"$d_line"
        d_done="$t_dir/diag.done"
        if [[ "$d_id" =~ ^[0-9]{1,18}$ ]] && [ "$(cat "$d_done" 2>/dev/null)" != "$d_id" ]; then
            d_parts=""
            d_run() {  # d_run <naam> <commando…>: uitvoer (stdout en stderr) als één onderdeel
                local out
                out=$(timeout 20 "${@:2}" 2>&1 | head -c 6000 | json_text)
                d_parts+="{\"k\":\"$1\",\"out\":\"$out\"},"
            }
            d_run uptime uptime
            command -v journalctl >/dev/null 2>&1 && d_run errors journalctl -p err -n 30 --no-pager --since -3h -o short-iso
            [ "$(id -u)" = 0 ] && d_run dmesg sh -c 'dmesg -T 2>/dev/null | tail -n 25'
            case "$d_kind" in
                svc)
                    if [[ "$d_target" =~ ^[A-Za-z0-9@._:-]{1,100}$ ]] && command -v systemctl >/dev/null 2>&1; then
                        d_run status systemctl status "$d_target" --no-pager -l -n 0
                        d_run journal journalctl -u "$d_target" -n 60 --no-pager -o short-iso
                    fi ;;
                ctr)
                    if [[ "$d_target" =~ ^([a-z_][a-z0-9_.-]*)/([A-Za-z0-9][A-Za-z0-9_.-]*)$ ]]; then
                        d_ctx=${BASH_REMATCH[1]}; d_name=${BASH_REMATCH[2]}
                        if [ "$d_ctx" = root ] || id -u "$d_ctx" >/dev/null 2>&1; then
                            d_run state pm "$d_ctx" inspect --format '{{.State.Status}} exit={{.State.ExitCode}} restarts={{.RestartCount}} started={{.State.StartedAt}} error={{.State.Error}}' "$d_name"
                            d_run logs pm "$d_ctx" logs --tail 60 "$d_name"
                        fi
                    fi ;;
                disk)
                    if [[ "$d_target" =~ ^/[A-Za-z0-9._/@+-]{0,200}$ && "$d_target" != *..* ]]; then
                        d_run df df -h "$d_target"
                        d_run inodes df -i "$d_target"
                        d_run du sh -c 'du -xh --max-depth=2 -- "$1" 2>/dev/null | sort -h | tail -n 15' _ "$d_target"
                    fi ;;
                proc)
                    d_run top sh -c 'ps -eo pid,user,%cpu,%mem,etime,comm --sort=-%cpu | head -n 16'
                    d_run mem free -m
                    command -v journalctl >/dev/null 2>&1 && d_run oom sh -c 'journalctl -k --since -6h --no-pager 2>/dev/null | grep -i -E "out of memory|oom-kill|killed process" | tail -n 10' ;;
                port)
                    if [[ "$d_target" =~ ^[0-9]{1,5}$ ]]; then
                        d_run listen sh -c 'ss -ltnp 2>/dev/null | grep -E ":$1([^0-9]|$)" || echo "niets luistert op poort $1"' _ "$d_target"
                    fi ;;
            esac
            diag_json="{\"id\":$d_id,\"items\":[${d_parts%,}]}"
            printf '%s' "$d_id" >"$d_done" 2>/dev/null
        fi
    fi
fi
# ---------------------------------------------------------------- Zelftest (agent 1.79.0)
# Op verzoek van RzSense (🧪 Test in de Agent Manager: regel "0 <tab> selftest <tab> id") test de agent zichzelf, één keer
# per vraag: planning, instellingenbestand, DNS, het adres en het reserve-adres van RzSense, de klok, vrije ruimte, de
# wachtrij en fouten in zijn log. Alleen vaste controles die lezen; RzSense stuurt geen commando's mee.
selftest_json=""
if [ "$TEST" != 1 ] && [ -r "${t_cache-}" ]; then
    st_line=$(awk -F'\t' '$1 == "0" && $2 == "selftest" { print $3; exit }' "$t_cache")
    if [[ "$st_line" =~ ^[0-9]{1,18}$ ]] && [ "$(cat "$t_dir/selftest.done" 2>/dev/null)" != "$st_line" ]; then
        st_items=""
        st_add() { st_items+="{\"k\":\"$1\",\"ok\":\"$2\",\"d\":\"$(json_escape "$3")\"},"; }
        # Planning: systemd-timer, cron, of de add-on / TrueNAS
        if [ "$HA_MODE" = 1 ]; then
            st_add sched ok "add-on van Home Assistant"
        elif [ -n "${AGENT_DIR-}" ]; then
            st_add sched ok "cronjob van TrueNAS ($AGENT_DIR)"
        elif command -v systemctl >/dev/null 2>&1 && systemctl cat rzmon-agent.timer >/dev/null 2>&1; then
            if systemctl is-active --quiet rzmon-agent.timer; then st_add sched ok "systemd-timer actief"
            else st_add sched bad "systemd-timer staat niet aan: sudo systemctl enable --now rzmon-agent.timer"; fi
        elif [ -f /etc/cron.d/rzmon-agent ]; then
            st_add sched ok "cron (/etc/cron.d/rzmon-agent)"
        else
            st_add sched warn "geen timer of cronjob gevonden: draait de agent alleen met de hand?"
        fi
        # Instellingenbestand: alleen leesbaar voor root
        if [ -n "${conf_file-}" ]; then
            st_perm=$(stat -c %a "$conf_file" 2>/dev/null)
            if [ "$st_perm" = 600 ]; then st_add conf ok "$conf_file (600)"
            else st_add conf warn "$conf_file heeft rechten ${st_perm:-?}: sudo chmod 600 $conf_file"; fi
        fi
        # Naam van RzSense opzoeken en het adres (en reserve-adres) bereiken
        st_host=${MONITOR_URL#*://}; st_host=${st_host%%/*}; st_host=${st_host%:*}; st_host=${st_host#[}; st_host=${st_host%]}
        if [[ "$st_host" =~ ^[0-9.]+$ || "$st_host" == *:* ]]; then
            st_add dns skip "adres is een IP-adres ($st_host)"
        elif command -v getent >/dev/null 2>&1; then
            if st_ip=$(timeout 5 getent hosts "$st_host" | awk '{ print $1; exit }') && [ -n "$st_ip" ]; then st_add dns ok "$st_host → $st_ip"
            else st_add dns bad "$st_host is niet op te zoeken"; fi
        fi
        st_reach() {  # st_reach <k> <adres>
            local code
            spec_tls "$2" "$(url_spec "$2")"
            code=$(curl -sS -o /dev/null -m 10 "${s_tls[@]}" -w '%{http_code} %{time_total}' "${2%/}/api.php?action=ping" 2>/dev/null)
            if [ "${code%% *}" != 000 ] && [ -n "$code" ]; then
                st_add "$1" ok "$2 bereikbaar in $(awk -v t="${code#* }" 'BEGIN { printf "%d", t * 1000 }') ms"
            else
                st_add "$1" "$( [ "$1" = url ] && echo bad || echo warn)" "$2 niet bereikbaar (curl $(curl -sS -o /dev/null -m 10 "${s_tls[@]}" "${2%/}/" 2>&1 | head -c 120))"
            fi
        }
        st_reach url "$MONITOR_URL"
        [ -n "$MONITOR_URL_FALLBACK" ] && st_reach fallback "$MONITOR_URL_FALLBACK"
        # Klok gesynchroniseerd?
        st_src=${time_json#*\"src\":\"}; st_src=${st_src%%\"*}
        if [[ "$time_json" == *'"sync":true'* ]]; then st_add clock ok "gesynchroniseerd ($st_src)"
        elif [ -n "$time_json" ]; then st_add clock warn "niet gesynchroniseerd met een tijdserver"
        else st_add clock skip "geen chrony of systemd-timesyncd"; fi
        # Vrije ruimte waar de agent zijn gegevens bewaart
        st_free=$(df -Pk "$t_dir" 2>/dev/null | awk 'NR == 2 { print int($4 / 1024) }')
        if [ -n "$st_free" ]; then
            if [ "$st_free" -lt 50 ]; then st_add disk bad "nog $st_free MB vrij in $t_dir"
            elif [ "$st_free" -lt 500 ]; then st_add disk warn "nog $st_free MB vrij in $t_dir"
            else st_add disk ok "$st_free MB vrij"; fi
        fi
        # Wachtrij van rapporten die niet aankwamen
        st_q=$(wc -l <"$k_dir/queue.jsonl" 2>/dev/null || echo 0)
        if [ "${st_q:-0}" -gt 0 ]; then st_add queue warn "$st_q metingen wachten nog (gaan met de volgende rapporten mee)"
        else st_add queue ok "leeg"; fi
        # Fouten in het log van de agent (laatste uur)
        if [ "$HA_MODE" = 0 ] && command -v journalctl >/dev/null 2>&1 && [ -z "${AGENT_DIR-}" ]; then
            st_err=$(timeout 10 journalctl -u rzmon-agent.service -p err --since -1h -q --no-pager 2>/dev/null | wc -l)
            st_last=$(timeout 10 journalctl -u rzmon-agent.service -p err --since -1h -q --no-pager -o cat 2>/dev/null | tail -n 1 | head -c 160)
            if [ "${st_err:-0}" -gt 0 ]; then st_add log warn "$st_err fout(en), laatste: $st_last"
            else st_add log ok "geen fouten"; fi
        fi
        # Hulpprogramma's die de agent nodig heeft
        st_miss=""
        for st_t in curl awk openssl sha256sum; do command -v "$st_t" >/dev/null 2>&1 || st_miss+="$st_t "; done
        if [ -n "$st_miss" ]; then st_add tools bad "ontbreekt: $st_miss"; else st_add tools ok "curl, awk, openssl, sha256sum"; fi
        selftest_json="{\"id\":$st_line,\"now\":$(date +%s),\"items\":[${st_items%,}]}"
        printf '%s' "$st_line" >"$t_dir/selftest.done" 2>/dev/null
    fi
fi
# ---------------------------------------------------------------- Versturen
lap ""
[ -n "$beat_pid" ] && kill "$beat_pid" 2>/dev/null   # het echte rapport gaat nu; geen hartslag meer nodig
# Opstarttijd (epoch) voor de check Herstart in RzSense
read -r boot_up _ < /proc/uptime 2>/dev/null || boot_up=0
boot_ts=0; [ "${boot_up%.*}" -gt 0 ] 2>/dev/null && boot_ts=$(( $(date +%s) - ${boot_up%.*} ))
# Wat er naast de vaste onderdelen meegaat, voor --status (1.60.0)
parts="processen=$(grep -o '\["' <<<"$proc_list" | wc -l) certificaatbestanden=$(grep -o '"path"' <<<"$cert_found" | wc -l) softwarelijst=$( [ -n "$pkgs_json" ] && echo ja || echo ongewijzigd) heartbeats=${#hb_files[@]} ids=$( [ -n "$ids_json" ] && echo ja || echo nee)"
# Agent 1.69.0: wat deze agent hier kan (RzSense toont het in de Agent Manager en kiest er checks bij)
caps=""
for c_tool in podman docker zpool smartctl nvme auditctl nft upsc chronyc timedatectl systemctl apt-get dnf apk pveversion midclt mtr traceroute iperf3 lldpctl; do
    command -v "$c_tool" >/dev/null 2>&1 && caps+="\"$c_tool\","
done
# Wachtrij (store-and-forward): metingen van rapporten die niet aankwamen gaan met dit rapport mee (hooguit 120)
q_file="$k_dir/queue.jsonl"; q_sent=0; backlog=""
if [ -s "$q_file" ]; then
    backlog=$(head -n 120 "$q_file" | grep '^{"t":[0-9]' | paste -sd, -)
    q_sent=$(head -n 120 "$q_file" | wc -l)
fi
# Agent 1.82.0: RzSense wordt bijgewerkt (dat zei hij zelf, in een versleuteld antwoord). Dan gaan metingen die niet aankomen
# in de wachtrij zonder alarm, tot de vrijgave (een antwoord zonder "rz_hold") of tot de tijd die RzSense meegaf (hooguit een uur).
hold_file="$k_dir/rzhold"; hold_id=""; hold_until=0; hold_since=0; hold_json=""; hold_released=0
if [ -r "$hold_file" ]; then
    read -r hold_id hold_until hold_since _ <"$hold_file" 2>/dev/null
    if ! [[ "$hold_id" =~ ^[0-9a-f]{8,32}$ && "$hold_until" =~ ^[0-9]+$ && "$hold_since" =~ ^[0-9]+$ ]]; then
        hold_id=""; hold_until=0; hold_since=0; rm -f "$hold_file"
    fi
fi
if [ -n "$hold_id" ] && [ "$hold_since" -gt 0 ]; then
    hold_json="{\"id\":\"$hold_id\",\"since\":$hold_since,\"q\":$( [ -s "$q_file" ] && wc -l <"$q_file" || echo 0)}"
    # Na de update niet allemaal op dezelfde seconde terug: 0-24 s wachten
    [ "$TEST" != 1 ] && [ "${HOLD_JITTER:-1}" != 0 ] && sleep $(( RANDOM % 25 ))
fi
run_ms=$(( $(now_ms) - run_t0 ))
payload="{\"agent_version\":\"$AGENT_VERSION\",\"hostname\":\"$(json_escape "$hostname")\",\"boot\":$boot_ts,\"cpu\":{\"pct\":$cpu_pct,\"load\":[$l1,$l5,$l15],\"cores\":$cores},\"mem\":$mem,\"disks\":[${disks%,}],\"ports\":[${ports%,}],\"services\":{${services%,}},\"certs\":[${certs%,}]${podman_json:+,\"podman\":$podman_json}${rt_json:+,\"realtime\":$rt_json}${tools_json:+,\"tools\":$tools_json}${podman_ha:+,\"podman\":$podman_ha}${usb_json:+,\"usb\":$usb_json}${pve_json:+,\"pve\":$pve_json}${truenas_json:+,\"truenas\":$truenas_json},\"svc\":{${svc_detail%,}},\"svc_tests\":{${svc_tests%,}},\"logs\":{${log_results%,}},\"cmd\":{${cmd_results%,}},${auth_json}${iso_json}${weblog_json}${ids_json}\"svc_list\":[${svc_list%,}],\"cert_found\":[${cert_found%,}],${logscan_json:+\"log_scan\":$logscan_json,}\"procs\":{${procs_json%,}},\"proc_list\":[${proc_list%,}],\"cfg\":$(cfg_json),${ha_json:+\"ha\":$ha_json,}\"update\":{\"via\":\"$upd_via\"},\"url\":\"$(json_escape "$MONITOR_URL")\",\"url_fallback\":\"$(json_escape "$MONITOR_URL_FALLBACK")\",\"url_pin\":\"$(url_spec "$MONITOR_URL")\",\"url_fb_pin\":\"$(url_spec "$MONITOR_URL_FALLBACK")\"${url_result:+,\"url_result\":\"$(json_escape "$url_result")\"}${upd_result:+,\"update_result\":\"$(json_escape "$upd_result")\"}${pkgs_json:+,\"packages\":$pkgs_json}${pkg_result:+,\"pkg_result\":$pkg_result}${hb_json:+,\"hb\":[$hb_json]}${heal_json:+,\"heal\":[$heal_json]}${act_json:+,\"act\":[$act_json]}${ransom_json:+,\"ransom\":$ransom_json}${apps_json:+,\"apps\":$apps_json}${ioc_json:+,\"ioc\":$ioc_json}${patch_json:+,\"patch\":$patch_json}${ha_upd_json:+,\"ha_upd_result\":$ha_upd_json}${sudo_json:+,\"sudo\":[${sudo_json%,}]}${heal_cmds:+,\"heal_cmds\":[$heal_cmds]}${wd_gap:+,\"watchdog\":$wd_gap}${edr_json:+,\"edr\":$edr_json}${time_json:+,\"time\":$time_json}$( [ -r "${CACHE_DIRECTORY:-/var/cache/rzmon-agent}/update.sig" ] && printf ',"update_sig":"%s"' "$(head -c 20 "${CACHE_DIRECTORY:-/var/cache/rzmon-agent}/update.sig" | tr -dc 'a-z_')")${smart_json:+,\"smart\":$smart_json}${ups_json:+,\"ups\":$ups_json}${wg_json:+,\"wg\":$wg_json}${diag_json:+,\"diag\":$diag_json}${path_json:+,\"path\":$path_json}${db_json:+,\"db\":$db_json}${iperfs_json:+,\"iperfs\":$iperfs_json}${selftest_json:+,\"selftest\":$selftest_json}${nic_json:+,\"nic\":$nic_json}${hold_json:+,\"hold\":$hold_json},\"caps\":[${caps%,}],\"timing\":{\"total\":$run_ms,\"parts\":{${laps%,}}}${backlog:+,\"backlog\":[$backlog]}}"

# Agent 1.66.1: 50 seconden wachten op RzSense (was 20): een druk bezette RzSense verwerkt een groot rapport soms trager;
# na 20 seconden gaf de agent het op en kreeg hij het antwoord (opdrachten, instellingen, "softwarelijst ontvangen") nooit
REPORT_WAIT=50
curl_opts=(-fsS -m "$REPORT_WAIT" -X POST "${tls[@]}")
# Rapport via een tijdelijk bestand versturen, niet als argument: op sommige systemen (TrueNAS SCALE)
# wordt curl met een lang argument stil afgebroken en komt er niets aan.
payload_file=$(mktemp "${TMPDIR:-/tmp}/rzmon-report.XXXXXX") || { echo "Kan geen tijdelijk bestand maken" >&2; exit 1; }
trap 'rm -f "$payload_file" "$payload_file.enc" "$payload_file.u" "$payload_file.z"; [ -n "$beat_pid" ] && kill "$beat_pid" 2>/dev/null' EXIT
printf '%s' "$payload" >"$payload_file"
# Alleen geldige UTF-8 versturen (1.59.1): een afgeknipt teken (mawk knipt op bytes) maakte het hele rapport onleesbaar
if command -v iconv >/dev/null 2>&1; then
    iconv -f UTF-8 -t UTF-8 -c <"$payload_file" >"$payload_file.u" 2>/dev/null
    [ -s "$payload_file.u" ] && mv -f "$payload_file.u" "$payload_file"
    rm -f "$payload_file.u"
fi
# Agent 1.73.0: comprimeren (gzip) als RzSense dat kan (dat zei hij in zijn vorige antwoord: "gz":1, RzSense 1.147.0+) en
# het rapport groter is dan 2 kB. Vóór het versleutelen: versleutelde gegevens zijn niet meer te comprimeren, en zo valt
# de gecomprimeerde inhoud ook onder de handtekening. RzSense herkent het aan de eerste bytes. Uitzetten: COMPRESS=0;
# de grens (bytes) is COMPRESS_MIN (de test zet hem laag, want in een kale container is een rapport klein).
body_file=$payload_file; gz_sent=0
if [ -e "$t_dir/gz.ok" ] && [ "${COMPRESS:-1}" != 0 ] && command -v gzip >/dev/null 2>&1 && [ "$(wc -c <"$payload_file")" -gt "${COMPRESS_MIN:-2048}" ]; then
    if gzip -c -6 <"$payload_file" >"$payload_file.z" 2>/dev/null && [ -s "$payload_file.z" ]; then
        body_file="$payload_file.z"; gz_sent=1
    fi
fi
send_file=$body_file; seal_n=""
if [ -n "$enc_key" ]; then
    if seal "$body_file" "$payload_file.enc"; then send_file="$payload_file.enc"
    else echo "Versleutelen mislukt (openssl); rapport gaat onversleuteld" >&2; seal_n=""; fi
fi
# Antwoord van RzSense: versleuteld (dan eerst controleren) of gewoon. Klopt de handtekening niet: niets mee doen.
open_resp() {
    if [[ "$1" == '{"rzenc":'* ]]; then
        unseal "$1" "$seal_n" || { echo "Antwoord van RzSense niet te vertrouwen (handtekening klopt niet); genegeerd" >&2; return 1; }
    elif [ -n "$seal_n" ]; then
        # Versleuteld verstuurd, maar onversleuteld antwoord: dat komt niet van RzSense (die antwoordt dan altijd versleuteld)
        echo "Antwoord van RzSense niet te vertrouwen (niet versleuteld); genegeerd" >&2; return 1
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
        # Per veld uitlezen (agent 1.66.1): sinds de keten-velden staat "error" niet meer achteraan
        done < <(sed 's/},{/}\n{/g' <<<"${certs%,}" | while IFS= read -r c; do
                src=$(sed -n 's/.*"source":"\([^"]*\)".*/\1/p' <<<"$c"); cn=$(sed -n 's/.*"cn":"\([^"]*\)".*/\1/p' <<<"$c")
                na=$(sed -n 's/.*"not_after":\([0-9][0-9]*\).*/\1/p' <<<"$c"); err=$(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' <<<"$c")
                vf=$(sed -n 's/.*"verify":"\([^"]*\)".*/\1/p' <<<"$c")
                if [ -n "$err" ]; then echo "✘ $src: $err"
                elif [ -n "$na" ]; then echo "$src: ${cn:-?}, nog $(( (na - $(date +%s)) / 86400 )) dagen geldig${vf:+ ($vf)}"
                else echo "$src: ${cn:-?}"; fi
            done)
    elif [ -n "$CERTS" ]; then
        hint "Certificaten: geen gevonden voor CERTS=\"$CERTS\""
    fi

    # 8. Echt rapport versturen
    resp=$(curl "${curl_k[@]}" -m 50 -X POST -H "Content-Type: application/json" "${auth[@]}" \
        --data-binary "@$send_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent" 2>&1)
    r_rc=$?
    r_code=${resp##*$'\n'}; r_body=${resp%$'\n'*}
    if [ "$r_code" = 200 ] && [[ "$r_body" == '{"rzenc":'* ]]; then
        r_body=$(open_resp "$r_body") && pass "Versleuteld verstuurd en het antwoord van RzSense klopt (🔒)"
    elif [ -n "$enc_key" ]; then hint "Sleutel aanwezig, maar het antwoord kwam onversleuteld terug"
    else hint "Niet versleuteld (geen sleutel): koppel de agent met een koppelcode (RzSense → Agent Manager → 🔗 Koppelcode)"; fi
    if [ -n "$kid" ]; then pass "🔑 Eigen sleutel van deze server (geen token meer nodig)"
    elif [ -n "$enc_key" ]; then hint "Nog de algemene sleutel: RzSense stuurt bij het volgende rapport een eigen sleutel mee"; fi
    if [ -z "$resp" ]; then
        fail "Rapport niet verstuurd: curl stopte zonder uitvoer (exitcode $r_rc, rapport $(wc -c <"$payload_file") bytes)"
    elif [ "$r_code" = 200 ] && [[ "$r_body" == *'"ok":true'* ]]; then
        pass "Rapport ontvangen door RzSense: $r_body"
        echo
        echo "  Alles werkt. Zie je in RzSense toch niets? Klap de server open → 'Agent Manager':"
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
# Certificaat van RzSense past niet meer bij de vingerafdruk (curl 90), bv. een nieuw certificaat: één keer opnieuw
# zonder vastzetten, maar alleen versleuteld. Het antwoord moet dan met onze sleutel ondertekend zijn (dat kan
# alleen RzSense), en daarin staat de nieuwe vingerafdruk. Een ander certificaat met een vals antwoord: geweigerd.
if [ "$rc" = 90 ] && [ -n "$seal_n" ] && [ -n "$kid" ]; then   # agent 1.87.0: alleen met de eigen sleutel (geen token naar een onbekende server)
    echo "Certificaat van RzSense is veranderd; controleren met de sleutel" >&2
    p_opts=(-fsS -m 20 -X POST -k -H "X-Agent-Pin-Fail: 1")   # RzSense bepaalt de vingerafdruk dan meteen opnieuw
    resp=$(curl "${p_opts[@]}" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$send_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent")
    rc=$?
    h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
fi
# Gewone adres niet bereikbaar (geen verbinding, time-out, certificaat, of de proxy geeft 502-504)? Dan via het
# reserve-adres (agent 1.51.0), opnieuw versleuteld met een nieuwe nonce. RzSense ziet dat (X-Agent-Fallback) en meldt ⚠️.
if [ -n "$MONITOR_URL_FALLBACK" ] && { [[ " 6 7 28 35 52 56 60 " == *" $rc "* ]] || { [ "$rc" = 22 ] && [[ " 502 503 504 " == *" $h_code "* ]]; }; }; then
    echo "RzSense niet bereikbaar via $MONITOR_URL (curl $rc${h_code:+, HTTP $h_code}); via het reserve-adres $MONITOR_URL_FALLBACK" >&2
    if [ -n "$seal_n" ]; then seal "$body_file" "$payload_file.enc" || seal_n=""; fi
    spec_tls "$MONITOR_URL_FALLBACK" "$(url_spec "$MONITOR_URL_FALLBACK")"
    fb_opts=(-fsS -m 20 -X POST "${s_tls[@]}"); fb_used=1
    resp=$(curl "${fb_opts[@]}" -H "X-Agent-Fallback: 1" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$send_file" \
        -w '\n%{http_code}' "$MONITOR_URL_FALLBACK/api.php?action=agent")
    rc=$?
    h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
fi
# Geweigerd met de algemene sleutel van RzSense (bv. ouder dan de wisseltijd)? Na 3 keer die sleutel weggooien:
# dan gaat het volgende rapport onversleuteld (of met ENC_KEY). De eigen sleutel gooit de agent nooit weg; staat
# versleutelen uit (enc.off) en weigert RzSense het token, dan staat het weer aan: de volgende keer met de sleutel.
if [ "$rc" = 22 ] && [ -n "$seal_n" ] && [ -z "$kid" ] && [ -r "$enc_file" ]; then
    n_fail=$(( $(cat "$t_dir/enc.fail" 2>/dev/null || echo 0) + 1 ))
    if [ "$n_fail" -ge 3 ]; then rm -f "$enc_file" "$t_dir/enc.fail"; else echo "$n_fail" >"$t_dir/enc.fail"; fi
elif [ "$rc" = 22 ] && [ -z "$kid" ] && [ -n "$own_key" ] && [ -e "$t_dir/enc.off" ]; then
    rm -f "$t_dir/enc.off"
elif [ "$rc" = 22 ] && [ -n "$kid" ]; then
    echo "RzSense weigert dit rapport. De reden staat in RzSense bij Meldingen (bv. nieuw IP-adres, of de sleutel is vernieuwd: maak dan een koppelcode in de Agent Manager)." >&2
elif [ "$rc" = 0 ]; then
    rm -f "$t_dir/enc.fail"
fi
if [ "$rc" = 0 ]; then resp=$(open_resp "$resp") || rc=1; fi
# Kan RzSense gecomprimeerde rapporten lezen? Dan zegt hij "gz":1. Geweigerd terwijl het gecomprimeerd ging: de volgende
# keer weer gewoon, tot RzSense het opnieuw zegt. Zo werkt een oudere RzSense altijd.
if [ "$rc" = 0 ] && [[ "$resp" =~ \"gz\":[[:space:]]*1 ]]; then
    touch "$t_dir/gz.ok" 2>/dev/null
elif [ "$rc" = 0 ] || { [ "$gz_sent" = 1 ] && [ "$rc" = 22 ]; }; then
    rm -f "$t_dir/gz.ok" 2>/dev/null
fi
# Dit contact bijhouden voor --status (1.60.0): laatste rapport, de laatste 30 contacten, fouten, controle van RzSense
if mkdir -p "$st_dir" 2>/dev/null && [ -w "$st_dir" ]; then
    st_now=$(date +%s); st_ms=$(( $(now_ms) - run_t0 )); st_sms=$(( $(now_ms) - s_t0 ))
    st_err=""
    if [ "$rc" != 0 ]; then
        case "$rc" in
            22) st_err="RzSense weigerde het rapport (HTTP ${h_code:-?}); de reden staat in RzSense (Agent Manager)" ;;
            6) st_err="naam van RzSense niet gevonden (DNS)" ;; 7) st_err="geen verbinding (poort dicht of RzSense uit)" ;;
            28) st_err="geen antwoord binnen $REPORT_WAIT seconden (RzSense is te traag; zie RzSense → Instellingen → Overzicht → Gezondheid)" ;; 35|60) st_err="TLS/certificaat klopt niet" ;; 90) st_err="certificaat past niet bij de vingerafdruk" ;;
            1) st_err="antwoord van RzSense niet te vertrouwen (handtekening)" ;; *) st_err="curl $rc${h_code:+, HTTP $h_code}" ;;
        esac
    fi
    st_rcv=$(sed -n 's/.*"received":{\([^}]*\)}.*/\1/p' <<<"$resp" | head -c 300)
    (umask 077; printf 'time=%s\nrc=%s\nhttp=%s\nms=%s\nsend_ms=%s\nbytes=%s\nsent=%s\nenc=%s\nvia=%s\nerror=%s\nreceived=%s\nparts=%s\ngz=%s\n' \
        "$st_now" "$rc" "${h_code:-}" "$st_ms" "$st_sms" "$(wc -c <"$payload_file")" "$(wc -c <"$send_file")" "$( [ -n "$seal_n" ] && echo 1 || echo 0)" \
        "$( [ "$fb_used" = 1 ] && echo fallback || echo primary)" "$st_err" "$st_rcv" "$parts" "$gz_sent" >"$st_dir/last") 2>/dev/null
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$st_now" "$rc" "${h_code:-}" "$st_ms" "$(wc -c <"$send_file")" "$( [ -n "$seal_n" ] && echo 1 || echo 0)" \
        "$( [ "$fb_used" = 1 ] && echo fallback || echo primary)" >>"$st_dir/history" 2>/dev/null
    [ "$(wc -l 2>/dev/null <"$st_dir/history")" -gt 30 ] && { tail -n 30 "$st_dir/history" >"$st_dir/history.tmp" && mv -f "$st_dir/history.tmp" "$st_dir/history"; }
    if [ -n "$st_err" ]; then
        printf '%s\t%s\n' "$st_now" "$st_err" >>"$st_dir/errors" 2>/dev/null
        [ "$(wc -l 2>/dev/null <"$st_dir/errors")" -gt 20 ] && { tail -n 20 "$st_dir/errors" >"$st_dir/errors.tmp" && mv -f "$st_dir/errors.tmp" "$st_dir/errors"; }
    fi
    # De beveiligingscontrole van RzSense (regels tekst, RzSense 1.100.0+)
    if [ "$rc" = 0 ] && [[ "$resp" =~ \"sec\":\[([^]]*)\] ]]; then
        grep -o '"[^"]*"' <<<"${BASH_REMATCH[1]}" | sed 's/^"//; s/"$//; s#\\/#/#g' >"$st_dir/sec" 2>/dev/null
    fi
fi
# Agent 1.69.0: wachtrij bijwerken. Gelukt: wat meeging eraf. Mislukt: de metingen van nu erbij (CPU, RAM, disks), zodat
# de grafieken in RzSense later toch compleet zijn. Hooguit 2880 regels (een dag bij een tik van 30 s), de oudste gaan eruit.
if [ "$rc" = 0 ] && [ "$q_sent" -gt 0 ]; then
    tail -n +"$(( q_sent + 1 ))" "$q_file" >"$q_file.tmp" 2>/dev/null && mv -f "$q_file.tmp" "$q_file"
    [ -s "$q_file" ] || rm -f "$q_file"
elif [ "$rc" != 0 ] && [ -d "$k_dir" ]; then
    (umask 077; printf '{"t":%s,"cpu":%s,"mem":%s,"disks":[%s]}\n' "$(date +%s)" "$cpu_pct" "$mem" "${disks%,}" >>"$q_file") 2>/dev/null
    [ "$(wc -l 2>/dev/null <"$q_file" || echo 0)" -gt 2880 ] && { tail -n 2880 "$q_file" >"$q_file.tmp" && mv -f "$q_file.tmp" "$q_file"; }
fi
# Agent 1.82.0: update van RzSense. Alleen uit een versleuteld antwoord (een foutpagina of proxy kan het niet nadoen)
hold_now=$(date +%s)
if [ "$rc" = 0 ]; then
    if [ -n "$seal_n" ] && [[ "$resp" =~ \"rz_hold\":\{\"id\":\"([0-9a-f]{8,32})\",\"max\":([0-9]{1,6}) ]]; then
        h_max=${BASH_REMATCH[2]}; [ "$h_max" -gt 3600 ] && h_max=3600
        [ "${BASH_REMATCH[1]}" = "$hold_id" ] || hold_since=0
        hold_id=${BASH_REMATCH[1]}; hold_until=$(( hold_now + h_max ))
        (umask 077; printf '%s %s %s\n' "$hold_id" "$hold_until" "$hold_since" >"$hold_file") 2>/dev/null
    elif [ -n "$hold_id" ]; then
        if [ "$hold_since" -gt 0 ]; then
            hold_released=1
            echo "RzSense is bijgewerkt en weer bereikbaar na $(( hold_now - hold_since )) s; de wachtrij gaat mee" >&2
        fi
        rm -f "$hold_file"; hold_id=""; hold_until=0
    fi
elif [ -n "$hold_id" ] && [ "$hold_now" -lt "$hold_until" ]; then
    [ "$hold_since" -gt 0 ] || hold_since=$hold_now
    (umask 077; printf '%s %s %s\n' "$hold_id" "$hold_until" "$hold_since" >"$hold_file") 2>/dev/null
    echo "RzSense wordt bijgewerkt: meting in de wachtrij, wacht op de vrijgave (hooguit tot $(date -d "@$hold_until" +%H:%M 2>/dev/null || echo "$hold_until"))" >&2
fi
# Uitvoer (komt in het logboek): zonder sleutels
sed -E 's/"(agent_key|enc_key)":"[0-9a-f]{64}"/"\1":"…"/g' <<<"$resp"
if [ "$rc" -eq 0 ]; then
    # Versleuteling: eigen sleutel en vingerafdruk van RzSense bewaren (alleen uit een versleuteld antwoord), de
    # algemene sleutel (agent 1.49), of stoppen als het voor deze server uit staat
    if [ -n "$seal_n" ]; then
        store_key "$resp" && rm -f "$t_dir/enc.off"
        store_pin "$resp"
        # Per adres hoe het gecontroleerd wordt (RzSense 1.78.0): alleen voor het gewone en het reserve-adres
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
    # Heartbeats zijn binnen; namen zonder check in RzSense in het logboek zetten
    [ ${#hb_files[@]} -gt 0 ] && rm -f "${hb_files[@]}"
    if [[ "$resp" =~ \"hb_unknown\":\[([^]]*)\] ]]; then
        echo "rzmon-hb: RzSense heeft geen heartbeat-check met de naam ${BASH_REMATCH[1]//\"/} op deze server (＋ Check → Heartbeat)" >&2
    fi
    # Uitkomst van een overstap is doorgegeven; vraagt RzSense om naar een ander adres over te stappen (alleen versleuteld)?
    [ -n "$url_result" ] && rm -f "$k_dir/url.result"
    if [ -n "$seal_n" ] && [[ "$resp" =~ \"url_set\":\{\"url\":\"([^\"]+)\",\"fallback\":\"([^\"]*)\"(,\"pin\":\"([^\"]*)\",\"fb_pin\":\"([^\"]*)\")?\} ]]; then
        url_switch "$(sed 's#\\/#/#g' <<<"${BASH_REMATCH[1]}")" "$(sed 's#\\/#/#g' <<<"${BASH_REMATCH[2]}")" "${BASH_REMATCH[4]//\\/}" "${BASH_REMATCH[5]//\\/}"
    fi
    # Uitkomst is doorgegeven; vraagt RzSense om bij te werken?
    [ -n "$upd_result" ] && rm -f "$upd_dir/update.result"
    # IDS, bestanden bewaken: RzSense heeft de wijzigingen; dit is nu de stand om mee te vergelijken
    if [ -n "$fim_commit" ]; then
        mv -f "$fim_dir/state.new" "$fim_dir/state" && mv -f "$fim_dir/meta.new" "$fim_dir/meta" && mv -f "$fim_dir/roots.new" "$fim_dir/roots"
        [ "$fim_rh" != /dev/null ] && touch "$i_dir/rehash.used"
    fi
    # Instellingen in RzSense gewijzigd (bv. een service-check toegevoegd)? Lijst bij de volgende run opnieuw ophalen.
    rev=$(sed -n 's/.*"tests_rev":[[:space:]]*"\([0-9a-f]\{8,64\}\)".*/\1/p' <<<"$resp")
    if [ -n "$rev" ] && [ -n "${t_cache-}" ] && [ -r "$t_cache" ] && [ "$(sha1sum "$t_cache" | cut -c1-12)" != "$rev" ]; then
        : >"$t_dir/tests.stale" 2>/dev/null || rm -f "$t_cache"   # volgende run opnieuw ophalen; de oude lijst blijft tot dan
    fi
    [[ "$resp" =~ \"update\":[[:space:]]*\{ ]] && [ -z "$hold_id" ] && remote_update "$resp"   # 1.82.0: niet tijdens een update van RzSense
    # Softwarelijst: RzSense heeft deze stand nu (of vraagt om alles opnieuw); uitkomst van het bijwerken is doorgegeven
    if [ -n "$pk_hash" ]; then
        if [[ "$resp" =~ \"packages_resend\":[[:space:]]*true ]]; then : >"$pk_dir/resend"; rm -f "$pk_dir/sent"
        elif [[ "$resp" =~ \"packages_ok\":[[:space:]]*true ]]; then printf '%s' "$pk_hash" >"$pk_dir/sent"; rm -f "$pk_dir/tried"; fi
    fi
    [ -n "$pkg_result" ] && rm -f "$pk_dir/upgrade.result"
    # Endpoint-bewaking: de meegestuurde gebeurtenissen zijn binnen; vraagt RzSense om processen meteen te stoppen?
    # (alleen versleuteld, en alleen als de starttijd klopt: nooit een ander proces dat dat nummer later kreeg)
    if [ -n "$edr_json" ] && [ -s "$edr_dir/edr.sending" ]; then
        tail -n +$(( ${e_take:-500} + 1 )) "$edr_dir/edr.sending" >"$edr_dir/edr.rest" 2>/dev/null && mv -f "$edr_dir/edr.rest" "$edr_dir/edr.sending"
        [ -s "$edr_dir/edr.sending" ] || rm -f "$edr_dir/edr.sending"
        rm -f "$edr_dir/edr.drop"
    fi
    # Weblog (agent 1.87.0): ook de positie in de logbestanden pas nu bewaren
    if [ -n "${w_dir-}" ]; then
        for w_pos in "$w_dir"/*.pos.new; do [ -f "$w_pos" ] && mv -f "$w_pos" "${w_pos%.new}"; done
    fi
    # Suricata: de positie pas bewaren als het rapport is aangekomen
    [ -n "$edr_json" ] && [ -f "$edr_dir/eve.pos.new" ] && mv -f "$edr_dir/eve.pos.new" "$edr_dir/eve.pos"
    # YARA-regels van RzSense (alleen versleuteld): eerst controleren of yara ze kan lezen, dan pas vervangen
    if [ -n "$seal_n" ] && [ -n "$edr_json" ] && [[ "$resp" =~ \"edr_yara\":\"([A-Za-z0-9+/=]{20,})\" ]]; then
        printf '%s' "${BASH_REMATCH[1]}" | base64 -d >"$edr_dir/rzmon.yar.new" 2>/dev/null
        if command -v yara >/dev/null 2>&1 && timeout 20 yara -w "$edr_dir/rzmon.yar.new" /dev/null >/dev/null 2>&1; then
            chmod 600 "$edr_dir/rzmon.yar.new" && mv -f "$edr_dir/rzmon.yar.new" "$edr_dir/rzmon.yar"
        else
            rm -f "$edr_dir/rzmon.yar.new"
        fi
    fi
    if [ -n "$seal_n" ] && [ "$IDS_BLOCK" != 0 ] && [ "$(id -u)" = 0 ] && [[ "$resp" =~ \"edr_kill\":\[(\[[0-9]+,[0-9]+\],?)+\] ]]; then
        mkdir -p "$t_dir/ids" 2>/dev/null
        while IFS=, read -r k_pid k_start; do
            [[ "$k_pid" =~ ^[0-9]+$ ]] && [[ "$k_start" =~ ^[0-9]+$ ]] && [ "$k_pid" -gt 1 ] || continue
            [ -f "$t_dir/ids/killed.$k_pid.$k_start" ] && continue
            k_res=weg
            if [ "$(awk '{ print $22 }' "/proc/$k_pid/stat" 2>/dev/null)" = "$k_start" ]; then
                # Nooit iets van RzSense of de agent zelf (agent 1.65.0)
                if [[ "$(cat "/proc/$k_pid/cgroup" 2>/dev/null)" =~ /rzmon-[^/]*\.service ]]; then k_res=eigen
                elif kill -KILL "$k_pid" 2>/dev/null; then k_res=ok; else k_res=fout; fi
            fi
            : >"$t_dir/ids/killed.$k_pid.$k_start"
            printf '[%s,%s,"%s"],' "$k_pid" "$k_start" "$k_res" >>"$t_dir/ids/killed.pending"
        done < <(grep -o '\[[0-9]*,[0-9]*\]' <<<"${BASH_REMATCH[0]#*:}" | tr -d '[]')
    fi
    [[ "$resp" =~ \"pkg_upgrade\":[[:space:]]*\{ ]] && [ -n "$pk_mgr" ] && pkg_upgrade "$resp"
    [[ "$resp" =~ \"ha_update\":[[:space:]]*\{ ]] && [ "$HA_MODE" = 1 ] && ha_update "$resp"   # agent 1.92.0
    # Zelf herstellen: de uitkomsten zijn binnen; vraagt RzSense om een herstelcommando (alleen versleuteld)?
    if [ -s "$heal_dir/done.sending" ]; then tail -n +51 "$heal_dir/done.sending" >>"$heal_dir/done"; [ -s "$heal_dir/done" ] || rm -f "$heal_dir/done"; fi
    rm -f "$heal_dir/done.sending" "$t_dir/watchdog.gap"
    if [ -n "$act_json" ] && [ -s "$t_dir/act.sending" ]; then   # agent 1.89.0: verstuurde logboekregels weg, de rest blijft
        tail -n +101 "$t_dir/act.sending" >"$t_dir/act.sending.tmp" 2>/dev/null && mv -f "$t_dir/act.sending.tmp" "$t_dir/act.sending"
        [ -s "$t_dir/act.sending" ] || rm -f "$t_dir/act.sending"
    fi
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
                        s_err="$f valt buiten de toegestane mappen ($LOG_DIRS); voeg de map toe aan LOG_DIRS in /etc/default/rzmon-agent"
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
