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
    rl_pin=""; [ -r "$RELAY_DIR/pin" ] && rl_pin=$(tr -dc 'A-Za-z0-9+/=' <"$RELAY_DIR/pin" | head -c 44)
    [[ "$rl_pin" =~ ^[A-Za-z0-9+/]{43}=$ ]] || rl_pin=""
    rl_ips=$(ip -o addr show scope global 2>/dev/null | awk '{ sub(/\/.*/, "", $4); print $4 }' | grep -E '^[0-9a-fA-F.:]{2,45}$' | head -n 16 \
        | while read -r a; do printf '"%s",' "$a"; done)
    rl_st=$(head -c 2000 "$RELAY_DIR/stats.json" 2>/dev/null)
    [[ "$rl_st" =~ ^\{[-a-zA-Z0-9_:\",./\ ]*\}$ ]] || rl_st="{}"
    [ -n "$(find "$RELAY_DIR/stats.json" -mmin +2 2>/dev/null)" ] && rl_st="{}"   # doorstuurprogramma loopt niet meer
    rl_kind=container; [ "${RELAY_KIND-}" = agent ] && rl_kind=agent   # agent 1.110.0: losse relay-agent (rzmon-agent-relay)
    relay_json="{\"kind\":\"$rl_kind\",\"mid\":\"${mid-}\",\"pin\":\"$rl_pin\",\"port\":${RELAY_PORT:-8444},\"ips\":[${rl_ips%,}],\"ver\":\"$AGENT_VERSION\",\"allow\":\"$rl_allow\",\"st\":$rl_st}"

    # Agent 1.112.0 (RzSense 1.289.0): checks voor dit netwerk. RzSense stuurt per check een regel
    # "id <tab> rchk <tab> ping|tcp|http <tab> …"; de relay voert ze naast elkaar uit (elk met een eigen tijdslimiet)
    # en stuurt de uitkomst mee. Alleen adressen en URL's zonder opties of spaties; nooit via een shell.
    rchk_json=""
    if [ -r "${t_cache-}" ] && grep -q $'\trchk\t' "$t_cache"; then
        rc_dir=$(mktemp -d "${TMPDIR:-/tmp}/rzrchk.XXXXXX") || rc_dir=""
    fi
    if [ -n "${rc_dir-}" ]; then
        rc_n=0; rc_pids=()
        while IFS=$'\t' read -r rc_id rc_k rc_t rc_a rc_b _; do
            [ "$rc_k" = rchk ] && [[ "$rc_id" =~ ^[0-9]{1,9}$ ]] || continue
            rc_n=$(( rc_n + 1 )); [ "$rc_n" -gt 64 ] && break
            case "$rc_t" in
                ping)
                    [[ "$rc_a" =~ ^[A-Za-z0-9][A-Za-z0-9.:_-]{0,252}$ ]] || continue
                    ( o=$(timeout 10 ping -c 3 -i 0.3 -W 2 -q "$rc_a" 2>&1); l=$(sed -n 's/.* \([0-9.]*\)% packet loss.*/\1/p' <<<"$o" | head -n1)
                      a=$(sed -n 's#.*= [0-9.]*/\([0-9.]*\)/.*#\1#p' <<<"$o" | head -n1)
                      e=""; [[ "$l" =~ ^[0-9.]+$ ]] || { l=100; e=$(head -n1 <<<"$o" | head -c 150 | tr -dc 'A-Za-z0-9 .:()/_-'); }
                      [[ "$a" =~ ^[0-9.]+$ ]] || a=null
                      printf '{"id":%s,"t":"ping","loss":%s,"ms":%s,"err":"%s"}' "$rc_id" "$l" "$a" "$e" >"$rc_dir/$rc_id" ) &
                    rc_pids+=($!)
                    ;;
                tcp)
                    [[ "$rc_a" =~ ^[A-Za-z0-9][A-Za-z0-9.:_-]{0,252}$ ]] && [[ "$rc_b" =~ ^[0-9]{1,5}$ ]] && [ "$rc_b" -ge 1 ] && [ "$rc_b" -le 65535 ] || continue
                    ( t0=$(now_ms)
                      if timeout 5 bash -c 'exec 3<>"/dev/tcp/$0/$1"' "$rc_a" "$rc_b" 2>/dev/null; then
                          printf '{"id":%s,"t":"tcp","ok":1,"ms":%s}' "$rc_id" "$(( $(now_ms) - t0 ))" >"$rc_dir/$rc_id"
                      else
                          printf '{"id":%s,"t":"tcp","ok":0,"err":"geen verbinding"}' "$rc_id" >"$rc_dir/$rc_id"
                      fi ) &
                    rc_pids+=($!)
                    ;;
                http)
                    rc_url_re='^https?://[A-Za-z0-9][A-Za-z0-9.:_-]*(/[^[:space:]"<>`\\]*)?$'
                    [[ "$rc_a" =~ $rc_url_re ]] && [ ${#rc_a} -le 500 ] || continue
                    rc_k_opt=(); [ "$rc_b" = 0 ] && rc_k_opt=(-k)
                    ( w=$(curl -sS -o /dev/null -L --max-redirs 5 -m 15 --connect-timeout 5 --proto '=http,https' "${rc_k_opt[@]}" \
                          -A "RzSense-relay/$AGENT_VERSION" -w '%{http_code} %{time_total}' --url "$rc_a" 2>"$rc_dir/.err.$rc_id"); r=$?
                      c=${w%% *}; tt=${w##* }; if [[ "$c" =~ ^[0-9]{3}$ ]]; then c=$(( 10#$c )); else c=0; fi; [[ "$tt" =~ ^[0-9.]+$ ]] || tt=0
                      e=""; [ "$r" != 0 ] && e=$(head -c 150 "$rc_dir/.err.$rc_id" | tr -dc 'A-Za-z0-9 .:()/_-')
                      printf '{"id":%s,"t":"http","code":%s,"ms":%s,"err":"%s"}' "$rc_id" "$c" "$(awk -v t="$tt" 'BEGIN { printf "%.1f", t * 1000 }')" "$e" >"$rc_dir/$rc_id" ) &
                    rc_pids+=($!)
                    ;;
            esac
        done <"$t_cache"
        # Alleen op deze checks wachten (niet op andere achtergrondtaken van de agent, zoals de hartslag)
        [ ${#rc_pids[@]} -gt 0 ] && wait "${rc_pids[@]}"
        rchk_json=$(find "$rc_dir" -maxdepth 1 -type f ! -name '.*' -exec cat {} + 2>/dev/null | sed 's/}{/},{/g')
        rm -rf -- "$rc_dir"
    fi
fi
