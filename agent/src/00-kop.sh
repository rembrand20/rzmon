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
AGENT_VERSION="1.92.0"

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

