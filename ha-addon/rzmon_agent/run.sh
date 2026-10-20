#!/usr/bin/env bash
# Start de RzMon agent in een lus met de opties uit de add-on configuratie.
set -u

OPTIONS=/data/options.json
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

MONITOR_URL=$(jq -r '.monitor_url // empty' "$OPTIONS")
TOKEN=$(jq -r '.token // empty' "$OPTIONS")
INTERVAL=$(jq -r '.interval // 60' "$OPTIONS")
INSECURE=$(jq -r 'if .verify_ssl == false then 1 else 0 end' "$OPTIONS")
# Certificaten: bestanden in /ssl en/of host:poort (ook op internet)
CERTS=$(jq -r '(.certs // []) | join(" ")' "$OPTIONS")
export MONITOR_URL TOKEN INSECURE CERTS

if [ -z "$TOKEN" ] || [ -z "$MONITOR_URL" ]; then
    log "FOUT: vul 'monitor_url' en 'token' in op het tabblad Configuratie."
    log "Het token vind je in RzMon bij de server (type 'Remote met agent') → 'Agent installeren'."
    exit 1
fi
if [ -z "${SUPERVISOR_TOKEN:-}" ]; then
    log "WAARSCHUWING: geen SUPERVISOR_TOKEN; add-on/update-status wordt niet gerapporteerd."
fi

log "RzMon agent gestart: rapporteert elke ${INTERVAL}s aan ${MONITOR_URL}"
last_ok=""
while true; do
    started=$(date +%s)
    if out=$(/usr/local/bin/rzmon-agent.sh 2>&1); then
        # Alleen loggen bij de eerste keer en na herstel, anders loopt het log vol
        [ "$last_ok" != 1 ] && log "Verbonden met RzMon ✔"
        last_ok=1
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
    sleep $(( INTERVAL > elapsed ? INTERVAL - elapsed : 1 ))
done
