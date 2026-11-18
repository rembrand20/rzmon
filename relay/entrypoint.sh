#!/bin/bash
# RzSense Relay (RzSense 1.282.0, agent 1.109.0): certificaat, dan het doorstuurprogramma (relay.py) en de agent
# (elke minuut). Stopt een van de twee, dan stopt de container (en start Podman/systemd hem opnieuw).
#
# Instellingen (omgeving, zie relay.env.example):
#   MONITOR_URL   adres van RzSense, zoals de relay het bereikt (internet/SWAG, VPN of een eigen route)
#   PAIR_CODE     koppelcode uit RzSense (alleen de eerste keer nodig)
#   PIN           vingerafdruk van het certificaat van RzSense (alleen bij een eigen certificaat)
#   MONITOR_URL_FALLBACK  reserve-adres van RzSense (optioneel)
#   RELAY_PORT    poort voor de agents in dit netwerk (standaard 8444)
set -euo pipefail

APP="${RELAY_APP:-/opt/rzsense-relay}"
DATA="${RELAY_DATA:-/data}"
export RELAY_DIR="$DATA/relay" RELAY_MODE=1 RELAY_PORT="${RELAY_PORT:-8444}"
export AGENT_DIR="$DATA/agent" CACHE_DIRECTORY="$DATA/cache" REMOTE_UPDATE=0
export RELAY_VERSION="${RELAY_VERSION:-$(cat "$APP/VERSION" 2>/dev/null || echo dev)}"
TICK="${RELAY_TICK:-60}"
case "$RELAY_PORT$TICK" in *[!0-9]*) echo "[relay] RELAY_PORT en RELAY_TICK moeten getallen zijn" >&2; exit 1 ;; esac
[ -n "${MONITOR_URL-}" ] || { echo "[relay] MONITOR_URL ontbreekt: het adres van RzSense (zie relay.env.example)" >&2; exit 1; }

umask 077
mkdir -p "$RELAY_DIR" "$AGENT_DIR" "$CACHE_DIRECTORY"

# 1. Eigen certificaat (EC, 2 jaar), vernieuwd als het binnen 30 dagen verloopt. De vingerafdruk (pin) gaat via de
#    agent naar RzSense; de agents in dit netwerk zetten hem vast.
crt="$RELAY_DIR/tls.crt" key="$RELAY_DIR/tls.key"
if [ ! -s "$crt" ] || [ ! -s "$key" ] || ! openssl x509 -in "$crt" -noout -checkend $((30 * 86400)) >/dev/null 2>&1; then
    san="DNS:localhost,DNS:$(hostname),IP:127.0.0.1"
    for ip in $(ip -o addr show scope global 2>/dev/null | awk '{ sub(/\/.*/, "", $4); print $4 }'); do san+=",IP:$ip"; done
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 730 -subj "/CN=RzSense Relay" \
        -addext "subjectAltName=$san" -keyout "$key.tmp" -out "$crt.tmp" >/dev/null 2>&1
    mv -f "$key.tmp" "$key"; mv -f "$crt.tmp" "$crt"
    echo "[relay] certificaat gemaakt ($san)"
fi
openssl x509 -in "$crt" -pubkey -noout | openssl pkey -pubin -outform der 2>/dev/null | openssl dgst -sha256 -binary | base64 >"$RELAY_DIR/pin"
echo "[relay] vingerafdruk van dit certificaat (pin): $(cat "$RELAY_DIR/pin")"

pids=()
stop() {
    for p in "${pids[@]}"; do kill -TERM "$p" 2>/dev/null || true; done
    wait || true
}
trap stop TERM INT

# 2. Doorsturen (python -I: geen code uit de huidige map of de omgeving)
python3 -I "$APP/relay.py" &
pids+=($!)

# 3. De agent: koppelt de eerste keer met PAIR_CODE, geeft de relay zijn instellingen door en meldt hoe het gaat
(
    while :; do
        if bash "$APP/rzmon-agent.sh" >"$DATA/agent-last.log" 2>&1; then
            [ -f "$DATA/.ok" ] || { echo "[relay] agent meldt zich bij RzSense"; : >"$DATA/.ok"; }
        else
            echo "[relay] agent: $(grep -v '^[[:space:]]*$' "$DATA/agent-last.log" | tail -n 1 | head -c 300)" >&2
            rm -f "$DATA/.ok"
        fi
        sleep $((TICK - $(date +%s) % TICK))
    done
) &
pids+=($!)

echo "[relay] klaar: agents in dit netwerk gebruiken https://<dit adres>:$RELAY_PORT"
wait -n
echo "[relay] een onderdeel is gestopt; de container stopt" >&2
stop
exit 1
