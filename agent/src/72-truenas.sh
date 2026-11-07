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
