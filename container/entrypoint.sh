#!/bin/bash
# RzSense in een container (1.281.0): voorbereiden, dan php-fpm, nginx en de planner (elke minuut cron/run.php).
# Stopt een van de drie, dan stopt de container (en start Podman/systemd hem opnieuw).
set -euo pipefail

APP="${RZMON_APP:-/opt/rzsense}"           # vast in de image; instelbaar om te testen
DATA="${RZMON_DATA:-/var/lib/rzmon}"
FPM="${RZMON_FPM:-php-fpm}"
export TZ="${TZ:-Europe/Amsterdam}"
WEB_PORT="${RZMON_WEB_PORT:-8080}"
DOOR_PORT="${RZMON_DOOR_PORT:-8443}"
case "$WEB_PORT$DOOR_PORT" in *[!0-9]*) echo "[rzsense] RZMON_WEB_PORT en RZMON_DOOR_PORT moeten getallen zijn" >&2; exit 1 ;; esac

# 1. voorbereiden (config.php, database, eerste beheerder, back-up bij een nieuwe versie, certificaat nooddeur)
php "$APP/container/init.php"

# Wachtwoorden niet doorgeven aan php-fpm, nginx of de planner
unset RZMON_DB_PASS RZMON_ADMIN_PASS RZMON_DB_PASS_FILE RZMON_ADMIN_PASS_FILE

# 2. nginx-configuratie met de gekozen poorten
sed -e "s/@WEB_PORT@/$WEB_PORT/g" -e "s/@DOOR_PORT@/$DOOR_PORT/g" -e "s#@DATA@#$DATA#g" "$APP/container/nginx.conf.in" > /tmp/rzsense-nginx.conf
sed -e "s#@APP@#$APP#g" "$APP/container/site.conf" > /tmp/rzsense-site.conf
# Geen IPv6 op deze host? Dan alleen IPv4 (anders weigert nginx te starten)
if [ ! -e /proc/net/if_inet6 ]; then sed -i '/listen \[::\]/d' /tmp/rzsense-nginx.conf; fi

pids=()
stop() {
    for p in "${pids[@]}"; do kill -TERM "$p" 2>/dev/null || true; done
    wait || true
}
trap stop TERM INT

# shellcheck disable=SC2086  # RZMON_FPM_ARGS bewust gesplitst (alleen om te testen)
"$FPM" -F ${RZMON_FPM_ARGS:-} &
pids+=($!)
nginx -e /dev/stderr -c /tmp/rzsense-nginx.conf -g 'daemon off;' &
pids+=($!)

# 3. de planner: elke minuut, op de hele minuut (cron/run.php houdt zelf bij of een vorige ronde nog loopt)
(
    while :; do
        sleep $((60 - $(date +%s) % 60))
        php "$APP/cron/run.php" >/dev/null 2>&1 &
    done
) &
pids+=($!)

echo "[rzsense] klaar: web op poort $WEB_PORT, nooddeur (https) op poort $DOOR_PORT"
# Stopt één onderdeel, dan alles stoppen (de container start dan opnieuw)
wait -n
echo "[rzsense] een onderdeel is gestopt; de container stopt" >&2
stop
exit 1
