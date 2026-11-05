# ---------------------------------------------------------------- Pad naar een doel (agent 1.78.0)
# Pad-checks uit RzMon: "id <tab> path <tab> doel <tab> tussenliggende IP's (komma's, of -) <tab> interval".
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
    # Iperf3-ontvanger op dit apparaat (aangevraagd door RzMon voor de pad-check van een andere server)
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
