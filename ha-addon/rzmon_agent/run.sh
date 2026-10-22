#!/usr/bin/env bash
# Start de RzMon agent in een lus met de opties uit de add-on configuratie.
set -u

OPTIONS=/data/options.json
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

MONITOR_URL=$(jq -r '.monitor_url // empty' "$OPTIONS")
TOKEN=$(jq -r '.token // empty' "$OPTIONS")
# Tik als RzMon er geen doorgeeft; normaal stel je hem in RzMon in (Agent Manager)
INTERVAL=$(jq -r '.interval // 60' "$OPTIONS")
INSECURE=$(jq -r 'if .verify_ssl == false then 1 else 0 end' "$OPTIONS")
# Certificaten: bestanden in /ssl en/of host:poort (ook op internet)
CERTS=$(jq -r '(.certs // []) | join(" ")' "$OPTIONS")
export MONITOR_URL TOKEN INSECURE CERTS INTERVAL

if [ -z "$TOKEN" ] || [ -z "$MONITOR_URL" ]; then
    log "FOUT: vul 'monitor_url' en 'token' in op het tabblad Configuratie."
    log "Het token vind je in RzMon bij de server (type 'Remote met agent') → 'Agent Manager'."
    exit 1
fi
if [ -z "${SUPERVISOR_TOKEN:-}" ]; then
    log "WAARSCHUWING: geen SUPERVISOR_TOKEN; add-on/update-status wordt niet gerapporteerd."
fi

log "RzMon agent gestart: rapporteert aan ${MONITOR_URL} (hoe vaak: in RzMon → Agent Manager)"
# Elke 30 s starten; de agent rapporteert alleen als de tik uit RzMon om is (anders stopt hij meteen)
BASE=30
CACHE=/data/rzmon-cache
SV=http://supervisor
# Realtime (aan/uit per server in RzMon): elke 5 s kijken of er iets verandert aan de add-ons, Core of de
# boot-tijd van HA OS; zo ja, meteen een rapport. Zonder realtime: gewoon wachten tot de volgende keer.
rt_on() { grep -q $'^0\trealtime\t1' "$CACHE/tests.cache" 2>/dev/null; }
rt_digest() {
    { curl -fsS -m 5 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SV/addons" | jq -r '.data.addons[]? | "\(.slug)=\(.state)"'
      curl -sS -m 5 -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SV/core/api/"
      curl -fsS -m 5 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SV/host/info" | jq -r '.data.boot_timestamp'
    } 2>/dev/null | md5sum
}
rt_since=$(date +%s)
force=0
last_ok=""
while true; do
    started=$(date +%s)
    # Status van realtime voor RzMon (zelfde vorm als de watcher op servers)
    if rt_on; then rt_state=active; rt_src=supervisor; else rt_state=off; rt_src=-; fi
    export RT_HA="$rt_state $rt_since $rt_src -"
    if [ "$force" = 1 ]; then export RZ_FORCE=1; else unset RZ_FORCE; fi
    force=0
    if out=$(/usr/local/bin/rzmon-agent.sh 2>&1); then
        # Alleen loggen bij het eerste echte rapport en na herstel (niet als de tik nog niet om was)
        if [[ "$out" == *'"ok":true'* ]]; then
            [ "$last_ok" != 1 ] && log "Verbonden met RzMon ✔ (rapport ontvangen)"
            last_ok=1
        fi
    else
        case "$out" in
            *"error: 403"*) hint=" → token onjuist, of de server staat in RzMon niet op type 'Remote met agent'" ;;
            *"Could not resolve"*|*"Failed to connect"*|*"timed out"*) hint=" → RzMon niet bereikbaar, controleer monitor_url" ;;
            *"SSL"*|*"certificate"*) hint=" → certificaatprobleem; zet verify_ssl uit bij een self-signed certificaat" ;;
            *) hint="" ;;
        esac
        log "Versturen mislukt: ${out}${hint}"
        last_ok=0
    fi
    elapsed=$(( $(date +%s) - started ))
    wait_s=$(( BASE > elapsed ? BASE - elapsed : 1 ))
    if rt_on; then
        last_d=$(rt_digest)
        while [ "$wait_s" -gt 0 ]; do
            sleep 5; wait_s=$(( wait_s - 5 ))
            d=$(rt_digest)
            if [ "$d" != "$last_d" ]; then force=1; break; fi
        done
    else
        sleep "$wait_s"
    fi
done
