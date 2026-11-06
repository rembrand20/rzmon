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
    echo "  RzMon-adres : $base$([ "$url_set_by_rzmon" = 1 ] && echo " (ingesteld vanuit RzMon; in de instellingen staat $conf_url)")"
    [ -n "$MONITOR_URL_FALLBACK" ] && echo "  Reserve     : $MONITOR_URL_FALLBACK (als het adres hierboven niet bereikbaar is)"
    for t_u in "$base" "$MONITOR_URL_FALLBACK"; do
        case "$(url_spec "$t_u")" in
            sha256//*) echo "  Controle    : $t_u → 📌 alleen het eigen certificaat van RzMon (vastgezet)" ;;
            -) [[ "$t_u" == https://* ]] && echo "  Controle    : $t_u → 🔒 geldig certificaat (gewone controle)" ;;
        esac
    done
    if [ -n "$kid" ]; then echo "  Sleutel     : 🔑 eigen sleutel (kenmerk $kid)"; else echo "  Token       : ${TOKEN:0:6}…${TOKEN: -4}"; fi
    echo

    # 1. Instellingen
    if [ "$base" = "http://rzmon.example.lan" ] || { [ "$TOKEN" = "VUL_HIER_HET_TOKEN_IN" ] && [ -z "$kid" ]; }; then
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

    curl_t=(-sS -m 15 -o /tmp/rzmon-test.$$ -w '%{http_code} %{redirect_url}' "${tls[@]}")

    # 5. Draait daar RzMon?
    read -r code redirect < <(curl "${curl_t[@]}" "$base/api.php?action=ping" 2>/tmp/rzmon-test-err.$$ || true)
    body=$(cat /tmp/rzmon-test.$$ 2>/dev/null); err=$(cat /tmp/rzmon-test-err.$$ 2>/dev/null)
    old_rzmon=0
    if [ "$code" = 200 ] && [[ "$body" == *'"app":"RzMon"'* ]]; then
        rz_version=$(sed -n 's/.*"version":"\([^"]*\)".*/\1/p' <<<"$body")
        # Nieuwste agent (eigen nummer sinds RzMon 1.46.0); oudere RzMon: gelijk aan de versie van RzMon
        rz_agent=$(sed -n 's/.*"agent_version":"\([^"]*\)".*/\1/p' <<<"$body")
        pass "RzMon ${rz_version:+$rz_version }gevonden op $base"
        if [ -n "${rz_agent:-$rz_version}" ] && [ "${rz_agent:-$rz_version}" != "$AGENT_VERSION" ]; then
            hint "Agent is versie $AGENT_VERSION, de nieuwste is ${rz_agent:-$rz_version}: bijwerken met  sudo rzmon-agent-setup --update"
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

    # 6. Token, of de eigen sleutel (dan is het antwoord versleuteld: zo weet de agent dat het echt RzMon is)
    if [ -n "$pin" ] && [ "${#tls[@]}" -gt 1 ]; then pass "Certificaat van RzMon vastgezet (📌 sha256//${pin:0:12}…)"
    elif [ -n "$pin" ]; then hint "Vingerafdruk van RzMon bekend, maar deze curl ($c_ver) kan geen certificaat vastzetten (7.44 of nieuwer nodig)"; fi
    curl_k=(-sS -m 15 "${tls[@]}")
    get_auth agent_check
    tk=$(curl "${curl_k[@]}" "${ga[@]}" -w '\n%{http_code}' "$base/api.php?action=agent_check" 2>/dev/null || true)
    tk_code=${tk##*$'\n'}; tk_body=${tk%$'\n'*}
    if [ "$tk_code" = 200 ] && [ -n "$kid" ]; then
        if tk_body=$(unseal "$tk_body" "$get_n"); then
            pass "🔑 Eigen sleutel geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzMon ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
        else
            fail "Het antwoord van RzMon is niet met de sleutel van deze server ondertekend: praat de agent wel met RzMon?"
        fi
    elif [ "$tk_code" = 200 ]; then
        pass "Token geldig voor server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$tk_body") (RzMon ziet dit IP: $(sed -n 's/.*"your_ip":"\([^"]*\)".*/\1/p' <<<"$tk_body"))"
    elif [ "$tk_code" = 403 ]; then
        fail "$([ -n "$kid" ] && echo "Sleutel" || echo "Token") geweigerd: $(sed -n 's/.*"reason":"\([^"]*\)".*/\1/p' <<<"$tk_body")"
        hint "Maak in RzMon een koppelcode (server → Agent Manager → 🔗 Koppelcode) en voer het commando uit"
        rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
        stop
    elif [ "$old_rzmon" = 1 ]; then
        hint "Tokencontrole overgeslagen (oudere RzMon-versie); het proefrapport hieronder test het token wel"
    else
        fail "Tokencontrole gaf HTTP $tk_code: ${tk_body:0:150}"
    fi
    rm -f /tmp/rzmon-test.$$ /tmp/rzmon-test-err.$$
fi

# ---------------------------------------------------------------- Status en instellingen (1.60.0)
# Elk contact met RzMon wordt bijgehouden in $st_dir (laatste rapport, de laatste 30 contacten, fouten en de
# beveiligingscontrole van RzMon), voor "rzmon-agent-setup --status". Daarnaast meldt de agent welke regels in
# zijn instellingen overbodig zijn (alleen de namen, nooit de waarden), zodat RzMon kan laten opruimen.
st_dir="$t_dir/status"
conf_file=""
if [ "$HA_MODE" != 1 ]; then
    if [ -n "${AGENT_DIR-}" ] && [ -f "$AGENT_DIR/agent.conf" ]; then conf_file="$AGENT_DIR/agent.conf"
    elif [ -f /etc/default/rzmon-agent ]; then conf_file=/etc/default/rzmon-agent; fi
fi
if [ -n "${AGENT_DIR-}" ]; then bk_dir="$AGENT_DIR/backup"; else bk_dir=/var/lib/rzmon-agent/backup; fi
now_ms() { local n; n=$(date +%s%3N 2>/dev/null); [[ "$n" =~ ^[0-9]{13}$ ]] && echo "$n" || echo $(( $(date +%s) * 1000 )); }
conf_has() { [ -n "$conf_file" ] && grep -q "^$1=" "$conf_file" 2>/dev/null; }
conf_val() { local v; v=$(grep "^$1=" "$conf_file" 2>/dev/null | tail -1); v=${v#*=}; v=${v#\"}; v=${v%\"}; printf '%s' "$v"; }
# Overbodige regels, met dezelfde regels als rzmon-agent-setup --cleanup
conf_obsolete() {
    local n v
    [ -n "$conf_file" ] && [ -r "$conf_file" ] || return 0
    for n in TOKEN ENC_KEY PAIR_CODE; do   # met een eigen sleutel niet meer nodig
        conf_has "$n" || continue
        v=$(conf_val "$n"); { [ -z "$v" ] || [ -n "$own_key" ]; } && echo "$n"
    done
    conf_has INTERVAL && echo INTERVAL   # de tik komt uit RzMon
    if conf_has PIN; then v=$(conf_val PIN); { [ -z "$v" ] || [ -s "$k_dir/pin" ] || [ -s "$k_dir/url.pins" ]; } && echo PIN; fi
    if conf_has INSECURE; then
        v=$(conf_val INSECURE); { [ "$v" != 1 ] || [[ "$MONITOR_URL" != https://* ]] || [ -n "$pin" ] || [ -n "$(url_spec "$MONITOR_URL")" ]; } && echo INSECURE
    fi
    conf_has SERVICES && echo SERVICES   # wordt een service-check in RzMon
    conf_has MONITOR_URL_FALLBACK && [ -z "$(conf_val MONITOR_URL_FALLBACK)" ] && echo MONITOR_URL_FALLBACK
    [ -n "$own_key" ] && [ -e "$enc_file" ] && echo enc.key   # bestand met de oude algemene sleutel
    return 0
}
cfg_sched() {
    if [ "$HA_MODE" = 1 ]; then echo ha
    elif [ -n "${AGENT_DIR-}" ]; then echo truenas
    elif [ -n "${INVOCATION_ID-}" ]; then echo systemd
    elif [ -f /etc/cron.d/rzmon-agent ]; then echo cron
    else echo manual; fi
}
cfg_json() {
    local ob sv sha perm bk=0 bl="" ins=0
    ob=$(conf_obsolete | awk 'NF { printf "%s\"%s\"", (n++ ? "," : ""), $0 }')
    sv=$(tr ' ' '\n' <<<"${SERVICES-}" | awk 'NF && /^[A-Za-z0-9@._:-]+$/ { printf "%s\"%s\"", (n++ ? "," : ""), $0 }')
    sha=$(sha256sum "${BASH_SOURCE[0]}" 2>/dev/null | cut -d' ' -f1)
    perm=$( [ -n "$conf_file" ] && stat -c '%a' "$conf_file" 2>/dev/null)
    if [ -d "$bk_dir" ]; then
        bk=$(find "$bk_dir" -maxdepth 1 -name 'agent-*.sh' 2>/dev/null | wc -l)
        bl=$(ls -1t "$bk_dir"/agent-*.sh 2>/dev/null | head -1); bl=${bl##*/}
    fi
    [ "${INSECURE-0}" = 1 ] && [[ "$MONITOR_URL" == https://* ]] && [ -z "$pin" ] && [ -z "$(url_spec "$MONITOR_URL")" ] && ins=1
    printf '{"conf":"%s","perm":"%s","obsolete":[%s],"services":[%s],"insecure":%d,"sha":"%s","backups":%d,"backup_last":"%s","sched":"%s"}' \
        "$(json_escape "$conf_file")" "$perm" "$ob" "$sv" "$ins" "$sha" "$bk" "$(json_escape "$bl")" "$(cfg_sched)"
}

# ---------------------------------------------------------------- --status (1.60.0)
if [ "${STATUS-0}" = 1 ]; then
    sl() { printf '  %-18s %s\n' "$1" "$2"; }
    if [ -t 1 ]; then b1=$'\e[1m'; b0=$'\e[0m'; else b1=""; b0=""; fi   # vet alleen op een terminal
    hd() { printf '\n%s── %s%s\n' "$b1" "$1" "$b0"; }
    dt() { date -d "@$1" '+%d-%m-%Y %H:%M:%S' 2>/dev/null || echo "$1"; }
    ago_s() { local s=$(( $(date +%s) - $1 )); if [ "$s" -lt 120 ]; then echo "$s s geleden"; elif [ "$s" -lt 7200 ]; then echo "$(( s / 60 )) min geleden"; elif [ "$s" -lt 172800 ]; then echo "$(( s / 3600 )) uur geleden"; else echo "$(( s / 86400 )) dagen geleden"; fi; }
    kb() { awk -v b="$1" 'BEGIN { if (b < 1024) printf "%d bytes", b; else printf "%.1f KB", b / 1024 }'; }
    st_get() { sed -n "s/^$1=//p" "$st_dir/last" 2>/dev/null | head -1; }
    printf '%sRzMon agent %s%s – status van %s op %s\n' "$b1" "$AGENT_VERSION" "$b0" "$(hostname)" "$(date '+%d-%m-%Y %H:%M:%S')"

    hd "Agent"
    sl "Versie:" "$AGENT_VERSION"
    sl "Script:" "${BASH_SOURCE[0]} (sha256 $(sha256sum "${BASH_SOURCE[0]}" 2>/dev/null | cut -c1-16)…)"
    case "$(cfg_sched)" in
        systemd|manual)
            if command -v systemctl >/dev/null 2>&1 && [ "$(systemctl is-active rzmon-agent.timer 2>/dev/null)" = active ]; then
                nx=$(systemctl show rzmon-agent.timer -p NextElapseUSecRealtime --value 2>/dev/null)
                sl "Gestart door:" "systemd-timer (actief${nx:+, volgende start: $nx})"
            elif [ -f /etc/cron.d/rzmon-agent ]; then sl "Gestart door:" "cron (/etc/cron.d/rzmon-agent)"
            else sl "Gestart door:" "⚠️ geen actieve timer of cronjob gevonden"; fi
            if command -v systemctl >/dev/null 2>&1; then
                sl "Realtime-watcher:" "$(systemctl is-active rzmon-watch.service 2>/dev/null || echo uit)"
            fi ;;
        cron) sl "Gestart door:" "cron (/etc/cron.d/rzmon-agent)" ;;
        truenas) sl "Gestart door:" "TrueNAS-cronjob (System → Advanced → Cron Jobs)" ;;
        ha) sl "Gestart door:" "Home Assistant-add-on" ;;
    esac
    if [ -n "$conf_file" ]; then
        ob=$(conf_obsolete | tr '\n' ' '); ob=${ob% }
        sl "Instellingen:" "$conf_file (rechten $(stat -c '%a %U' "$conf_file" 2>/dev/null))"
        if [ -n "$ob" ]; then sl "" "⚠️ overbodig: $ob → opruimen: rzmon-agent-setup --cleanup (of 🧹 in de Agent Manager)"
        else sl "" "✔ opgeruimd: geen overbodige regels"; fi
    fi
    if [ -d "$bk_dir" ] && ls "$bk_dir"/agent-*.sh >/dev/null 2>&1; then
        bl=$(ls -1t "$bk_dir"/agent-*.sh | head -1)
        sl "Reservekopie:" "$(find "$bk_dir" -maxdepth 1 -name 'agent-*.sh' | wc -l) × in $bk_dir (laatste: ${bl##*/}, $(dt "$(stat -c %Y "$bl")"))"
    fi

    hd "Verbinding met RzMon"
    sl "Adres:" "$MONITOR_URL"
    [ -n "$MONITOR_URL_FALLBACK" ] && sl "Reserve-adres:" "$MONITOR_URL_FALLBACK (als het gewone adres niet bereikbaar is)"
    if [[ "$MONITOR_URL" != https://* ]]; then sl "Certificaat:" "⚠️ via http: de inhoud is versleuteld, maar niet verborgen dat er verkeer is (zet RzMon op https)"
    elif [ -n "$pin" ]; then sl "Certificaat:" "🔒 vastgezet (sha256//${pin:0:12}…): alleen dit certificaat wordt geaccepteerd"
    elif [ "${INSECURE-0}" = 1 ]; then sl "Certificaat:" "⚠️ niet gecontroleerd (INSECURE=1, geen vingerafdruk)"
    else sl "Certificaat:" "✔ gewone controle (vertrouwd certificaat)"; fi
    pr=$(LC_ALL=C curl -sS "${tls[@]}" -m 10 -o /dev/null -w '%{http_code} %{time_namelookup} %{time_connect} %{time_appconnect} %{time_total} %{local_ip} %{remote_ip}' \
        "${MONITOR_URL%/}/api.php?action=ping" 2>&1)
    read -r p_code p_dns p_con p_tls p_tot p_lip p_rip <<<"$pr"
    if [ "$p_code" = 200 ]; then
        sl "Nu bereikbaar:" "✔ in $(awk -v t="$p_tot" 'BEGIN { printf "%d", t * 1000 }') ms (DNS $(awk -v t="$p_dns" 'BEGIN { printf "%d", t * 1000 }') ms, verbinden $(awk -v t="$p_con" 'BEGIN { printf "%d", t * 1000 }') ms$( [[ "$MONITOR_URL" == https://* ]] && printf ', TLS %d ms' "$(awk -v t="$p_tls" 'BEGIN { printf "%d", t * 1000 }')")), van $p_lip naar $p_rip"
    else
        pr=${pr#curl: }; sl "Nu bereikbaar:" "✘ $(head -1 <<<"$pr" | cut -c1-160)"
    fi
    if [[ "$MONITOR_URL" == https://* ]] && command -v openssl >/dev/null 2>&1; then
        hp=${MONITOR_URL#https://}; hp=${hp%%/*}; h_h=${hp%:*}; h_p=443; [[ "$hp" == *:* ]] && h_p=${hp##*:}
        so=$(LC_ALL=C timeout 10 openssl s_client -connect "$h_h:$h_p" -servername "$h_h" </dev/null 2>/dev/null)
        # OpenSSL 3: "New, TLSv1.3, Cipher is …"; ouder: "Protocol : …" en "Cipher : …"
        pv=$(sed -n 's/^New, \(TLSv[0-9.]*\), Cipher is \(.*\)$/\1, \2/p' <<<"$so" | head -1)
        [ -n "$pv" ] || pv="$(sed -n 's/^ *Protocol *: *//p' <<<"$so" | head -1), $(sed -n 's/^ *Cipher *: *//p' <<<"$so" | head -1)"
        [ "$pv" != ", " ] && sl "TLS:" "$pv"
        if crt=$(openssl x509 2>/dev/null <<<"$so"); then
            c_cn=$(openssl x509 -noout -subject -nameopt RFC2253 <<<"$crt" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
            c_na=$(date -d "$(openssl x509 -noout -enddate <<<"$crt" | cut -d= -f2)" '+%d-%m-%Y' 2>/dev/null)
            c_pin=$(openssl x509 -pubkey -noout <<<"$crt" | openssl pkey -pubin -outform der 2>/dev/null | openssl dgst -sha256 -binary | base64)
            pm=""; [ -n "$pin" ] && { [ "$c_pin" = "$pin" ] && pm=" · vingerafdruk klopt ✔" || pm=" · ⚠️ vingerafdruk wijkt af (RzMon stuurt de nieuwe bij het volgende rapport)"; }
            sl "Certificaat RzMon:" "${c_cn:-?}, geldig tot ${c_na:-?}$pm"
        fi
    fi

    hd "Versleuteling"
    if [ -n "$own_key" ]; then
        sl "Sleutel:" "🔑 eigen sleutel van deze server (kenmerk $(enc_sub "$own_key" id | cut -c1-16)), sinds $(dt "$(stat -c %Y "$own_file")")"
        sl "" "RzMon vernieuwt hem vanzelf; er gaat geen token of sleutel over de lijn"
    elif [ -n "$enc_key" ]; then sl "Sleutel:" "⚠️ alleen de oude algemene sleutel: koppel opnieuw met een koppelcode (rzmon-agent-setup --pair …)"
    else sl "Sleutel:" "⚠️ geen sleutel: rapporten gaan onversleuteld met een token. Koppel met: rzmon-agent-setup --pair …"; fi
    [ -n "$enc_key" ] && sl "Methode:" "AES-256-CBC + HMAC-SHA256; elk bericht uniek (nonce) en 5 minuten geldig; antwoorden van RzMon ondertekend"
    [ -n "$own_key" ] && [ -e "$enc_file" ] && sl "Oude sleutel:" "⚠️ het bestand met de algemene sleutel staat er nog ($enc_file) → --cleanup"

    hd "Contact"
    if [ -r "$st_dir/last" ]; then
        l_t=$(st_get time); l_rc=$(st_get rc)
        if [ "$l_rc" = 0 ]; then
            sl "Laatste rapport:" "✔ $(dt "$l_t") ($(ago_s "$l_t")), HTTP $(st_get http), $(st_get ms) ms (versturen $(st_get send_ms) ms)"
        else
            sl "Laatste rapport:" "✘ $(dt "$l_t") ($(ago_s "$l_t")): $(st_get error)"
        fi
        sl "Verstuurd:" "$(kb "$(st_get bytes)") ($( [ "$(st_get enc)" = 1 ] && echo "🔒 versleuteld $(kb "$(st_get sent)")" || echo "⚠️ onversleuteld")), via $( [ "$(st_get via)" = fallback ] && echo "⚠️ het reserve-adres" || echo "het gewone adres")"
        rv=$(st_get received | tr -d '"' | tr ',' '\n' | awk -F: '{ n = $1; sub(/^disks$/, "disks", n); sub(/^ports$/, "poorten", n); sub(/^certs$/, "certificaten", n)
            printf "%s%s %s", (NR > 1 ? ", " : ""), $2, n }')
        [ -n "$rv" ] && sl "RzMon ontving:" "$rv"
        pt=$(st_get parts | sed 's/ / · /g; s/=/: /g'); [ -n "$pt" ] && sl "Ook meegestuurd:" "$pt"
        ok_t=$(awk -F'\t' '$2 == 0 { t = $1 } END { print t }' "$st_dir/history" 2>/dev/null)
        [ -n "$ok_t" ] && [ "$l_rc" != 0 ] && sl "Laatst gelukt:" "$(dt "$ok_t") ($(ago_s "$ok_t"))"
    else
        sl "Laatste rapport:" "nog niet bijgehouden (komt na het volgende rapport)"
    fi
    # Agent 1.82.0: RzMon wordt bijgewerkt (aangekondigd door RzMon zelf)
    if [ -r "$k_dir/rzhold" ] && read -r _ h_u h_s _ <"$k_dir/rzhold" 2>/dev/null && [[ "$h_u" =~ ^[0-9]+$ && "$h_s" =~ ^[0-9]+$ ]]; then
        if [ "$h_s" -gt 0 ]; then sl "RzMon-update:" "wacht op de vrijgave sinds $(dt "$h_s"); alarm pas na $(dt "$h_u")"
        else sl "RzMon-update:" "aangekondigd; lukt een rapport niet, dan wacht de agent tot $(dt "$h_u")"; fi
        [ -s "$k_dir/queue.jsonl" ] && sl "Wachtrij:" "$(wc -l <"$k_dir/queue.jsonl") metingen"
    fi
    tk=$(awk -F'\t' '$1 == "0" && $2 == "tick" { print $3; exit }' "$t_cache" 2>/dev/null)
    if [ -n "$tk" ]; then
        sl "Ritme:" "elke $tk s (ingesteld in RzMon)$( [ -n "${l_t-}" ] && echo "; volgende rapport rond $(date -d "@$(( l_t + tk ))" '+%H:%M:%S' 2>/dev/null)")"
    fi
    if [ -s "$st_dir/history" ]; then
        echo "  Laatste contacten:"
        tail -n 10 "$st_dir/history" | tac 2>/dev/null | while IFS=$'\t' read -r h_t h_rc h_c h_ms h_b h_e h_v; do
            printf '    %s  %s  %6s ms  %9s  %s%s\n' "$(dt "$h_t")" "$( [ "$h_rc" = 0 ] && echo "✔ $h_c" || echo "✘ ${h_c:-curl $h_rc}")" "$h_ms" "$(kb "$h_b")" \
                "$( [ "$h_e" = 1 ] && echo 🔒 || echo ⚠️)" "$( [ "$h_v" = fallback ] && echo ' via reserve-adres')"
        done
    fi
    if [ -s "$st_dir/errors" ]; then
        n_e=$(awk -F'\t' -v d="$(( $(date +%s) - 86400 ))" '$1 >= d' "$st_dir/errors" | wc -l)
        if [ "$n_e" -gt 0 ]; then
            sl "Fouten (24 uur):" "$n_e×, laatste: $(tail -1 "$st_dir/errors" | cut -f2) ($(dt "$(tail -1 "$st_dir/errors" | cut -f1)"))"
        fi
    fi
    if [ -r "$t_cache" ]; then
        n_svc=$(grep -c $'^[0-9]*\tsvc\t' "$t_cache"); n_crt=$(grep -c $'^0\tcert\t' "$t_cache"); n_pr=$(grep -c $'^[0-9]*\tproc\t' "$t_cache")
        n_log=$(grep -c $'^[0-9]*\tlog\t' "$t_cache"); n_cmd=$(grep -c $'^[0-9]*\tcmd\t' "$t_cache")
        idsm=$(awk -F'\t' '$1 == "0" && $2 == "ids" { print $3; exit }' "$t_cache")
        case "$idsm" in block) idsm="aan, met blokkeren" ;; "") idsm=uit ;; *) idsm="aan ($idsm)" ;; esac
        sl "Bewaakt (RzMon):" "$n_svc services, $n_crt certificaten, $n_pr processen, $n_log logzoekopdrachten, $n_cmd commando's; IDS: $idsm"
        if command -v pvesh >/dev/null 2>&1 && [ "${PVE:-1}" != 0 ]; then
            if [ -s "$t_dir/pve/live" ]; then
                p_vm=$(grep -o '"vmid":[0-9]*' <(sed -n 's/.*"qemu":\(\[[^]]*\]\).*/\1/p' "$t_dir/pve/live") | wc -l)
                p_ct=$(grep -o '"vmid":[0-9]*' <(sed -n 's/.*"lxc":\(\[[^]]*\]\).*/\1/p' "$t_dir/pve/live") | wc -l)
                p_run=$(grep -o '"status":"running"' "$t_dir/pve/live" | wc -l)
                sl "Proxmox VE:" "$p_vm VM's en $p_ct containers ($p_run draaien), bijgewerkt $(ago_s "$(stat -c %Y "$t_dir/pve/live")")"
            else
                sl "Proxmox VE:" "nog niet uitgelezen (komt bij het volgende rapport)"
            fi
        fi
        sl "" "instellingen van RzMon opgehaald $(ago_s "$(stat -c %Y "$t_cache")")"
    fi

    hd "Beveiligingscontrole door RzMon"
    if [ -s "$st_dir/sec" ]; then sed 's/^/  /' "$st_dir/sec"
    else echo "  (komt mee met het volgende rapport; RzMon 1.100.0 of nieuwer)"; fi
    echo
    exit 0
fi

# Supervisor API (alleen HA-modus)
sv() { curl -fsS -m 10 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "$SUPERVISOR_URL$1" 2>/dev/null; }

