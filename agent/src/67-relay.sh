# ---------------------------------------------------------------- RzSense Relay (agent 1.109.0, RzSense 1.282.0)
lap relay
# Alleen in de RzSense Relay-container (RELAY_MODE=1, RELAY_DIR). Daar draait naast deze agent een klein https-programma
# (relay.py) dat het verkeer van de agents in dit netwerk doorstuurt naar RzSense. Deze agent geeft het door wat het
# moet weten, via bestanden in RELAY_DIR:
#  - allow:    uit welke netwerken de relay verkeer mag aannemen (van RzSense: "0 <tab> relay <tab> poort <tab> netwerken");
#  - upstream: naar welk adres van RzSense, met de controle van het certificaat (zoals deze agent zelf verbindt);
#  - sign.key: het kenmerk en een sleutel om mee te ondertekenen, afgeleid van de eigen sleutel (niet de sleutel zelf).
# Terug naar RzSense: de vingerafdruk van het certificaat van de relay, de poort, de adressen en hoe druk hij het heeft.
relay_json=""
if [ "${RELAY_MODE-}" = 1 ] && [ -n "${RELAY_DIR-}" ] && [ -d "$RELAY_DIR" ] && [ -w "$RELAY_DIR" ]; then
    rl_put() {  # rl_put <bestand> <inhoud>: alleen schrijven als het anders is (atomair, alleen voor deze gebruiker)
        [ "$(cat "$RELAY_DIR/$1" 2>/dev/null)" = "$2" ] && return 0
        (umask 077; printf '%s\n' "$2" >"$RELAY_DIR/$1.tmp" && mv -f "$RELAY_DIR/$1.tmp" "$RELAY_DIR/$1")
    }
    rl_line=""
    [ -r "${t_cache-}" ] && rl_line=$(awk -F'\t' '$1 == "0" && $2 == "relay" { print; exit }' "$t_cache")
    rl_allow=""
    if [ -n "$rl_line" ]; then
        IFS=$'\t' read -r _ _ _ rl_a <<<"$rl_line"
        [ "$rl_a" = "-" ] && rl_a=""
        rl_allow=$(tr ',' '\n' <<<"$rl_a" | grep -E '^[0-9a-fA-F.:]{2,45}(/[0-9]{1,3})?$' | head -n 64 | paste -sd, -)
        rl_put allow "$rl_allow"
    fi
    # Naar RzSense: het gewone adres en het reserve-adres, elk met hoe het certificaat gecontroleerd wordt
    rl_up=""
    for rl_u in "$MONITOR_URL" "${MONITOR_URL_FALLBACK-}"; do
        [[ "$rl_u" =~ ^https?://[^[:space:]\"]+$ ]] || continue
        rl_s=$(url_spec "$rl_u")
        [ -z "$rl_s" ] && [ "$rl_u" = "$MONITOR_URL" ] && [ -n "${pin-}" ] && rl_s="sha256//$pin"
        rl_up+="${rl_u%/} ${rl_s:--}"$'\n'
    done
    rl_put upstream "${rl_up%$'\n'}"
    if [ -n "$own_key" ] && [ -n "$kid" ]; then
        rl_put sign.key "$kid $(enc_sub "$own_key" relay)"
    fi
    rl_pin=$(tr -dc 'A-Za-z0-9+/=' <"$RELAY_DIR/pin" 2>/dev/null | head -c 44)
    [[ "$rl_pin" =~ ^[A-Za-z0-9+/]{43}=$ ]] || rl_pin=""
    rl_ips=$(ip -o addr show scope global 2>/dev/null | awk '{ sub(/\/.*/, "", $4); print $4 }' | grep -E '^[0-9a-fA-F.:]{2,45}$' | head -n 16 \
        | while read -r a; do printf '"%s",' "$a"; done)
    rl_st=$(head -c 2000 "$RELAY_DIR/stats.json" 2>/dev/null)
    [[ "$rl_st" =~ ^\{[-a-zA-Z0-9_:\",./\ ]*\}$ ]] || rl_st="{}"
    [ -n "$(find "$RELAY_DIR/stats.json" -mmin +2 2>/dev/null)" ] && rl_st="{}"   # doorstuurprogramma loopt niet meer
    relay_json="{\"pin\":\"$rl_pin\",\"port\":${RELAY_PORT:-8444},\"ips\":[${rl_ips%,}],\"ver\":\"$AGENT_VERSION\",\"allow\":\"$rl_allow\",\"st\":$rl_st}"
fi
