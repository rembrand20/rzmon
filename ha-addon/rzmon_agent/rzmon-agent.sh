#!/usr/bin/env bash
# RzMon agent – rapporteert CPU, RAM, disk usage, luisterende poorten, service-status en
# vervaldatums van SSL-certificaten (bestanden en host:poort, ook op internet) aan de monitor.
#
# Linux-server:
#   sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
#   Zet MONITOR_URL, TOKEN, SERVICES en CERTS in /etc/default/rzmon-agent (zie "Agent installeren" in de webinterface)
#   echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon-agent
#   Vereist: bash, curl, df, systemctl, ss (iproute2); voor certificaten: openssl
#
# Home Assistant OS / Supervised:
#   Draait als add-on (map ha-addon/rzmon_agent). Zodra SUPERVISOR_TOKEN bestaat schakelt de agent
#   over op HA-modus: disk via de Supervisor, en "services" zijn Home Assistant Core, de Supervisor,
#   elke add-on (addon:<slug>) en beschikbare updates. Vereist daarnaast: jq.
#
# Verbindingstest:  sudo rzmon-agent.sh --test

# Versie: wordt bijgewerkt door tools/bump-version.sh (gelijk aan VERSION van RzMon)
AGENT_VERSION="1.8.0"

TEST=0
[ "${1-}" = "--test" ] && TEST=1
if [ "${1-}" = "--version" ]; then
    echo "rzmon-agent $AGENT_VERSION"
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
# Spatie-gescheiden lijst met systemd services om te controleren (niet gebruikt in HA-modus)
SERVICES="${SERVICES:-ssh cron}"
# INSECURE=1 accepteert een self-signed certificaat van RzMon
INSECURE="${INSECURE:-0}"
# Certificaten om te bewaken (spatie-gescheiden): bestanden (wildcards mogen) en/of host:poort,
# lokaal of op internet, bv. "/etc/letsencrypt/live/*/cert.pem localhost:8443 example.com:443".
# Niet ingesteld = alle Let's Encrypt-certificaten op deze server; leeg ("") = geen.
CERTS="${CERTS-/etc/letsencrypt/live/*/cert.pem}"
# Certificaten veranderen zelden: elk uur opnieuw uitlezen is genoeg (seconden)
CERT_INTERVAL="${CERT_INTERVAL:-3600}"
SUPERVISOR_URL="${SUPERVISOR_URL:-http://supervisor}"

HA_MODE=0
[ -n "${SUPERVISOR_TOKEN:-}" ] && HA_MODE=1

json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

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
    echo "RzMon agent $AGENT_VERSION – verbindingstest"
    echo "  RzMon-adres : $base"
    echo "  Token       : ${TOKEN:0:6}…${TOKEN: -4}"
    echo

    # 1. Instellingen
    if [ "$base" = "http://rzmon.example.lan" ] || [ "$TOKEN" = "VUL_HIER_HET_TOKEN_IN" ]; then
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
        localhost|127.*|::1) note "Het adres wijst naar deze server zelf ($host): alleen goed als RzMon óp deze server draait"
            hint "Anders: gebruik het IP-adres of de naam van de RzMon-server, bv. http://192.168.1.10" ;;
    esac

    # 3. DNS
    if [[ "$host" =~ ^[0-9.]+$ ]] || getent hosts "$host" >/dev/null 2>&1; then
        pass "Naam opzoeken: $host → $(getent hosts "$host" 2>/dev/null | awk '{print $1; exit}' || echo "$host")"
    else
        fail "De naam '$host' kan niet worden opgezocht (DNS)"
        hint "Gebruik het IP-adres van RzMon, of zet de naam in /etc/hosts"
        stop
    fi

    # 4. Netwerk: kan deze server de poort bereiken?
    if timeout 5 bash -c "exec 3<>/dev/tcp/$host/$port" 2>/dev/null; then
        pass "Verbinding met $host poort $port"
    else
        fail "Geen verbinding met $host poort $port"
        hint "Draait de webserver op RzMon? (systemctl status httpd/apache2/nginx)"
        hint "Firewall op de RzMon-server? Rocky: sudo firewall-cmd --permanent --add-service=http && sudo firewall-cmd --reload"
        hint "Zitten deze server en RzMon in hetzelfde netwerk / mag het verkeer ertussen (VLAN, router)?"
        stop
    fi
    if [ -n "${http_proxy:-}${HTTP_PROXY:-}${https_proxy:-}${HTTPS_PROXY:-}" ]; then
        hint "Let op: er is een proxy ingesteld (http_proxy/https_proxy). Zet RzMon zo nodig in no_proxy."
    fi

    curl_t=(-sS -m 15 -o /tmp/rzmon-test.$$ -w '%{http_code} %{redirect_url}')
    [ "$INSECURE" = 1 ] && curl_t+=(-k)

    # 5. Draait daar RzMon?
    read -r code redirect < <(curl "${curl_t[@]}" "$base/api.php?action=ping" 2>/tmp/rzmon-test-err.$$ || true)
    body=$(cat /tmp/rzmon-test.$$ 2>/dev/null); err=$(cat /tmp/rzmon-test-err.$$ 2>/dev/null)
    old_rzmon=0
    if [ "$code" = 200 ] && [[ "$body" == *'"app":"RzMon"'* ]]; then
        rz_version=$(sed -n 's/.*"version":"\([^"]*\)".*/\1/p' <<<"$body")
        pass "RzMon ${rz_version:+$rz_version }gevonden op $base"
        if [ -n "$rz_version" ] && [ "$rz_version" != "$AGENT_VERSION" ]; then
            hint "Agent is versie $AGENT_VERSION, RzMon $rz_version: bijwerken met  sudo rzmon-agent-setup --update"
        fi
    elif [ "$code" = 401 ] && [[ "$body" == *'"error"'* ]]; then
        pass "RzMon gevonden op $base (oudere versie: werk RzMon bij voor een volledige test)"
        old_rzmon=1
    elif [[ "$code" =~ ^30[1278]$ ]]; then
        fail "RzMon stuurt door naar: $redirect"
        hint "Gebruik dat adres als MONITOR_URL (bv. https:// i.p.v. http://): sudo rzmon-agent-setup --url ${redirect%/api.php*}"
    elif [ "$code" = 404 ]; then
        fail "Er draait wel een webserver op $base, maar niet RzMon (404)"
        hint "Klopt het pad? RzMon staat op de hoofd-URL van de vhost, bv. http://192.168.1.10/ — niet /rzmon/public/"
    elif [[ "$err" == *SSL* || "$err" == *certificate* ]]; then
        fail "Certificaatprobleem: ${err#curl: }"
        hint "Self-signed certificaat? Zet INSECURE=\"1\" in /etc/default/rzmon-agent (of: sudo rzmon-agent-setup --insecure)"
    elif [ "$code" = 000 ]; then
        fail "Geen antwoord van $base: ${err#curl: }"
    else
        fail "Onverwacht antwoord van $base (HTTP $code)"
        [ -n "$body" ] && hint "${body:0:200}"
    fi
    if [ "$TEST_FAILED" = 1 ]; then rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$; stop; fi

    # 6. Token
    curl_k=(-sS -m 15); [ "$INSECURE" = 1 ] && curl_k+=(-k)
    tk=$(curl "${curl_k[@]}" -H "X-Agent-Token: $TOKEN" -w '\n%{http_code}' "$base/api.php?action=agent_check" 2>/dev/null || true)
    tk_code=${tk##*$'\n'}; tk_body=${tk%$'\n'*}
    if [ "$tk_code" = 200 ]; then
        pass "Token geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzMon ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
    elif [ "$tk_code" = 403 ]; then
        fail "Token geweigerd: $(sed -n 's/.*"reason":"\([^"]*\)".*/\1/p' <<<"$tk_body")"
        hint "Kopieer het commando opnieuw uit RzMon (server → Agent installeren) en voer het uit"
        rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
        stop
    elif [ "$old_rzmon" = 1 ]; then
        hint "Tokencontrole overgeslagen (oudere RzMon-versie); het proefrapport hieronder test het token wel"
    else
        fail "Tokencontrole gaf HTTP $tk_code: ${tk_body:0:150}"
    fi
    rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
fi

# Supervisor API (alleen HA-modus)
sv() { curl -fsS -m 10 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SUPERVISOR_URL$1" 2>/dev/null; }

# ---------------------------------------------------------------- Disk
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

# ---------------------------------------------------------------- CPU
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
# Geheugen uit /proc/meminfo (kB -> bytes)
mem=$(awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ { v[$1] = $2 * 1024 }
    END { printf "{\"total\":%.0f,\"avail\":%.0f,\"swap_total\":%.0f,\"swap_free\":%.0f}",
          v["MemTotal:"], v["MemAvailable:"], v["SwapTotal:"], v["SwapFree:"] }' /proc/meminfo)

# ---------------------------------------------------------------- Poorten
# Luisterende TCP-poorten (procesnamen alleen zichtbaar als root, niet in de HA-add-on)
ports=""
if command -v ss >/dev/null 2>&1; then
    ports=$(ss -Htlnp 2>/dev/null | awk '{
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

# ---------------------------------------------------------------- Services
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

    # Beschikbare updates (Core, Supervisor, OS en add-ons) → WARNING in RzMon
    updates=$( {
        sv /core/info | jq -r 'select(.data.update_available) | "Core \(.data.version_latest)"'
        jq -r 'select(.data.update_available) | "Supervisor"' <<<"$sup_info"
        sv /os/info | jq -r 'select(.data.update_available) | "OS \(.data.version_latest)"'
        [ -n "$addons" ] && jq -r '.data.addons[] | select(.update_available) | .name' <<<"$addons"
    } 2>/dev/null | paste -sd, - | sed 's/,/, /g')
    if [ -n "$updates" ]; then
        services+="\"updates\":\"$(json_escape "update-available: $updates")\","
    else
        services+="\"updates\":\"active\","
    fi
else
    for s in $SERVICES; do
        state=$(systemctl is-active "$s" 2>/dev/null)
        services+="\"$(json_escape "$s")\":\"$(json_escape "${state:-unknown}")\","
    done
fi

# ---------------------------------------------------------------- Certificaten
# Per bron: onderwerp (CN), uitgever, vervaldatum (epoch) en of het certificaat vertrouwd wordt.
# RzMon rekent zelf het aantal dagen uit, dus een uur oude gegevens zijn geen probleem.
cert_line() {  # bron cn uitgever not_after verify fout
    printf '{"source":"%s","cn":"%s","issuer":"%s","not_after":%s,"verify":%s,"error":"%s"},' \
        "$(json_escape "$1")" "$(json_escape "$2")" "$(json_escape "$3")" "${4:-null}" \
        "$( [ -n "$5" ] && printf '"%s"' "$(json_escape "$5")" || echo null)" "$(json_escape "$6")"
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
    for entry in $CERTS; do
        if [[ "$entry" == /* ]]; then
            # Bestand(en); een wildcard zonder treffers is geen fout (bv. geen Let's Encrypt)
            if [[ "$entry" == *[*?[]* ]]; then
                compgen -G "$entry" >/dev/null || continue
            fi
            for f in $entry; do
                if [ ! -r "$f" ]; then
                    cert_line "$f" "" "" "" "" "bestand niet gevonden of niet leesbaar"
                elif parse_cert <"$f"; then
                    cert_line "$f" "$cn" "$issuer" "$not_after" "" ""
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
            out=$(timeout 15 openssl s_client -connect "$src" -servername "$host" -verify_hostname "$host" </dev/null 2>&1)
            rc=$?
            if parse_cert <<<"$out"; then
                code=$(sed -n 's/^ *Verify return code: \([0-9]*\) (\(.*\))/\1 \2/p' <<<"$out" | tail -1)
                [ "${code%% *}" = 0 ] && code=ok || code=${code#* }
                cert_line "$src" "$cn" "$issuer" "$not_after" "${code:-onbekend}" ""
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
if [ -n "$CERTS" ]; then
    if ! command -v openssl >/dev/null 2>&1; then
        certs=$(cert_line "openssl" "" "" "" "" "openssl ontbreekt op deze server (installeer het pakket openssl)")
    else
        # Vaste cachemap (systemd: CacheDirectory); /tmp is bij systemd per run leeg (PrivateTmp)
        cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
        mkdir -p "$cache_dir" 2>/dev/null && [ -w "$cache_dir" ] || cache_dir="${TMPDIR:-/tmp}"
        cache="$cache_dir/certs.cache"
        key=$(printf '%s' "$CERTS" | cksum | cut -d' ' -f1)
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

# ---------------------------------------------------------------- Versturen
payload="{\"agent_version\":\"$AGENT_VERSION\",\"hostname\":\"$(json_escape "$hostname")\",\"cpu\":{\"pct\":$cpu_pct,\"load\":[$l1,$l5,$l15],\"cores\":$cores},\"mem\":$mem,\"disks\":[${disks%,}],\"ports\":[${ports%,}],\"services\":{${services%,}},\"certs\":[${certs%,}]}"

curl_opts=(-fsS -m 20 -X POST)
[ "$INSECURE" = 1 ] && curl_opts+=(-k)

if [ "$TEST" = 1 ]; then
    # 7. Verzamelde gegevens tonen
    n_disks=$(grep -o '"mount"' <<<"$disks" | wc -l)
    n_ports=$(grep -o '"port"' <<<"$ports" | wc -l)
    pass "Gegevens verzameld: CPU ${cpu_pct}% (${cores} cores), $n_disks disk(s), $n_ports luisterende poort(en)"
    [ -n "$services" ] && hint "Services: $(sed 's/"//g; s/,$//; s/,/, /g' <<<"$services")"
    command -v ss >/dev/null 2>&1 || hint "ss ontbreekt: poorten worden niet gemeld (Rocky: dnf install iproute)"
    if [ -n "$certs" ]; then
        n_certs=$(grep -o '"source"' <<<"$certs" | wc -l)
        pass "Certificaten: $n_certs uitgelezen"
        while IFS= read -r line; do
            hint "$line"
        done < <(sed 's/},{/}\n{/g' <<<"${certs%,}" | sed -E 's/^\{"source":"([^"]*)","cn":"([^"]*)",.*"not_after":([0-9]+|null).*"error":"([^"]*)"\}?,?$/\1|\2|\3|\4/' |
            while IFS='|' read -r src cn na err; do
                if [ -n "$err" ]; then echo "✘ $src: $err"
                else echo "$src: ${cn:-?}, nog $(( (na - $(date +%s)) / 86400 )) dagen geldig"; fi
            done)
    elif [ -n "$CERTS" ]; then
        hint "Certificaten: geen gevonden voor CERTS=\"$CERTS\""
    fi

    # 8. Echt rapport versturen
    resp=$(curl "${curl_k[@]}" -m 20 -X POST -H "Content-Type: application/json" -H "X-Agent-Token: $TOKEN" \
        --data "$payload" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent" 2>&1 || true)
    r_code=${resp##*$'\n'}; r_body=${resp%$'\n'*}
    if [ "$r_code" = 200 ] && [[ "$r_body" == *'"ok":true'* ]]; then
        pass "Rapport ontvangen door RzMon: $r_body"
        echo
        echo "  Alles werkt. Zie je in RzMon toch niets? Klap de server open → 'Agent installeren':"
        echo "  daar staat wanneer het laatste rapport binnenkwam en vanaf welk IP."
    else
        fail "Rapport niet geaccepteerd (HTTP $r_code): ${r_body:0:200}"
    fi
    exit "$TEST_FAILED"
fi

curl "${curl_opts[@]}" \
    -H "Content-Type: application/json" \
    -H "X-Agent-Token: $TOKEN" \
    --data "$payload" \
    "${MONITOR_URL%/}/api.php?action=agent"
