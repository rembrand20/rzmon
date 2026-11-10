# ---------------------------------------------------------------- Versturen
lap ""
[ -n "$beat_pid" ] && kill "$beat_pid" 2>/dev/null   # het echte rapport gaat nu; geen hartslag meer nodig
# Opstarttijd (epoch) voor de check Herstart in RzSense
read -r boot_up _ < /proc/uptime 2>/dev/null || boot_up=0
boot_ts=0; [ "${boot_up%.*}" -gt 0 ] 2>/dev/null && boot_ts=$(( $(date +%s) - ${boot_up%.*} ))
# Wat er naast de vaste onderdelen meegaat, voor --status (1.60.0)
parts="processen=$(grep -o '\["' <<<"$proc_list" | wc -l) certificaatbestanden=$(grep -o '"path"' <<<"$cert_found" | wc -l) softwarelijst=$( [ -n "$pkgs_json" ] && echo ja || echo ongewijzigd) heartbeats=${#hb_files[@]} ids=$( [ -n "$ids_json" ] && echo ja || echo nee)"
# Agent 1.69.0: wat deze agent hier kan (RzSense toont het in de Agent Manager en kiest er checks bij)
caps=""
for c_tool in podman docker zpool smartctl nvme auditctl nft upsc chronyc timedatectl systemctl apt-get dnf apk pveversion midclt mtr traceroute iperf3 lldpctl; do
    command -v "$c_tool" >/dev/null 2>&1 && caps+="\"$c_tool\","
done
# Wachtrij (store-and-forward): metingen van rapporten die niet aankwamen gaan met dit rapport mee (hooguit 120)
q_file="$k_dir/queue.jsonl"; q_sent=0; backlog=""
if [ -s "$q_file" ]; then
    backlog=$(head -n 120 "$q_file" | grep '^{"t":[0-9]' | paste -sd, -)
    q_sent=$(head -n 120 "$q_file" | wc -l)
fi
# Agent 1.82.0: RzSense wordt bijgewerkt (dat zei hij zelf, in een versleuteld antwoord). Dan gaan metingen die niet aankomen
# in de wachtrij zonder alarm, tot de vrijgave (een antwoord zonder "rz_hold") of tot de tijd die RzSense meegaf (hooguit een uur).
hold_file="$k_dir/rzhold"; hold_id=""; hold_until=0; hold_since=0; hold_json=""; hold_released=0
if [ -r "$hold_file" ]; then
    read -r hold_id hold_until hold_since _ <"$hold_file" 2>/dev/null
    if ! [[ "$hold_id" =~ ^[0-9a-f]{8,32}$ && "$hold_until" =~ ^[0-9]+$ && "$hold_since" =~ ^[0-9]+$ ]]; then
        hold_id=""; hold_until=0; hold_since=0; rm -f "$hold_file"
    fi
fi
if [ -n "$hold_id" ] && [ "$hold_since" -gt 0 ]; then
    hold_json="{\"id\":\"$hold_id\",\"since\":$hold_since,\"q\":$( [ -s "$q_file" ] && wc -l <"$q_file" || echo 0)}"
    # Na de update niet allemaal op dezelfde seconde terug: 0-24 s wachten
    [ "$TEST" != 1 ] && [ "${HOLD_JITTER:-1}" != 0 ] && sleep $(( RANDOM % 25 ))
fi
run_ms=$(( $(now_ms) - run_t0 ))
payload="{\"agent_version\":\"$AGENT_VERSION\",\"hostname\":\"$(json_escape "$hostname")\",\"boot\":$boot_ts,\"cpu\":{\"pct\":$cpu_pct,\"load\":[$l1,$l5,$l15],\"cores\":$cores},\"mem\":$mem,\"disks\":[${disks%,}],\"ports\":[${ports%,}],\"services\":{${services%,}},\"certs\":[${certs%,}]${podman_json:+,\"podman\":$podman_json}${rt_json:+,\"realtime\":$rt_json}${tools_json:+,\"tools\":$tools_json}${podman_ha:+,\"podman\":$podman_ha}${usb_json:+,\"usb\":$usb_json}${pve_json:+,\"pve\":$pve_json}${truenas_json:+,\"truenas\":$truenas_json},\"svc\":{${svc_detail%,}},\"svc_tests\":{${svc_tests%,}},\"logs\":{${log_results%,}},\"cmd\":{${cmd_results%,}},${auth_json}${iso_json}${weblog_json}${ids_json}\"svc_list\":[${svc_list%,}],\"cert_found\":[${cert_found%,}],${logscan_json:+\"log_scan\":$logscan_json,}\"procs\":{${procs_json%,}},\"proc_list\":[${proc_list%,}],\"cfg\":$(cfg_json),${ha_json:+\"ha\":$ha_json,}\"update\":{\"via\":\"$upd_via\"},\"url\":\"$(json_escape "$MONITOR_URL")\",\"url_fallback\":\"$(json_escape "$MONITOR_URL_FALLBACK")\",\"url_pin\":\"$(url_spec "$MONITOR_URL")\",\"url_fb_pin\":\"$(url_spec "$MONITOR_URL_FALLBACK")\"${url_result:+,\"url_result\":\"$(json_escape "$url_result")\"}${upd_result:+,\"update_result\":\"$(json_escape "$upd_result")\"}${pkgs_json:+,\"packages\":$pkgs_json}${pkg_result:+,\"pkg_result\":$pkg_result}${hb_json:+,\"hb\":[$hb_json]}${heal_json:+,\"heal\":[$heal_json]}${act_json:+,\"act\":[$act_json]}${ransom_json:+,\"ransom\":$ransom_json}${apps_json:+,\"apps\":$apps_json}${ioc_json:+,\"ioc\":$ioc_json}${patch_json:+,\"patch\":$patch_json}${ha_upd_json:+,\"ha_upd_result\":$ha_upd_json}${sudo_json:+,\"sudo\":[${sudo_json%,}]}${heal_cmds:+,\"heal_cmds\":[$heal_cmds]}${wd_gap:+,\"watchdog\":$wd_gap}${edr_json:+,\"edr\":$edr_json}${time_json:+,\"time\":$time_json}$( [ -r "${CACHE_DIRECTORY:-/var/cache/rzmon-agent}/update.sig" ] && printf ',"update_sig":"%s"' "$(head -c 20 "${CACHE_DIRECTORY:-/var/cache/rzmon-agent}/update.sig" | tr -dc 'a-z_')")${smart_json:+,\"smart\":$smart_json}${ups_json:+,\"ups\":$ups_json}${wg_json:+,\"wg\":$wg_json}${diag_json:+,\"diag\":$diag_json}${path_json:+,\"path\":$path_json}${db_json:+,\"db\":$db_json}${iperfs_json:+,\"iperfs\":$iperfs_json}${selftest_json:+,\"selftest\":$selftest_json}${nic_json:+,\"nic\":$nic_json}${hold_json:+,\"hold\":$hold_json},\"caps\":[${caps%,}],\"timing\":{\"total\":$run_ms,\"parts\":{${laps%,}}}${backlog:+,\"backlog\":[$backlog]}}"

# Agent 1.66.1: 50 seconden wachten op RzSense (was 20): een druk bezette RzSense verwerkt een groot rapport soms trager;
# na 20 seconden gaf de agent het op en kreeg hij het antwoord (opdrachten, instellingen, "softwarelijst ontvangen") nooit
REPORT_WAIT=50
curl_opts=(-fsS -m "$REPORT_WAIT" -X POST "${tls[@]}")
# Rapport via een tijdelijk bestand versturen, niet als argument: op sommige systemen (TrueNAS SCALE)
# wordt curl met een lang argument stil afgebroken en komt er niets aan.
payload_file=$(mktemp "${TMPDIR:-/tmp}/rzmon-report.XXXXXX") || { echo "Kan geen tijdelijk bestand maken" >&2; exit 1; }
trap 'rm -f "$payload_file" "$payload_file.enc" "$payload_file.u" "$payload_file.z"; [ -n "$beat_pid" ] && kill "$beat_pid" 2>/dev/null' EXIT
printf '%s' "$payload" >"$payload_file"
# Alleen geldige UTF-8 versturen (1.59.1): een afgeknipt teken (mawk knipt op bytes) maakte het hele rapport onleesbaar
if command -v iconv >/dev/null 2>&1; then
    iconv -f UTF-8 -t UTF-8 -c <"$payload_file" >"$payload_file.u" 2>/dev/null
    [ -s "$payload_file.u" ] && mv -f "$payload_file.u" "$payload_file"
    rm -f "$payload_file.u"
fi
# Agent 1.73.0: comprimeren (gzip) als RzSense dat kan (dat zei hij in zijn vorige antwoord: "gz":1, RzSense 1.147.0+) en
# het rapport groter is dan 2 kB. Vóór het versleutelen: versleutelde gegevens zijn niet meer te comprimeren, en zo valt
# de gecomprimeerde inhoud ook onder de handtekening. RzSense herkent het aan de eerste bytes. Uitzetten: COMPRESS=0;
# de grens (bytes) is COMPRESS_MIN (de test zet hem laag, want in een kale container is een rapport klein).
body_file=$payload_file; gz_sent=0
if [ -e "$t_dir/gz.ok" ] && [ "${COMPRESS:-1}" != 0 ] && command -v gzip >/dev/null 2>&1 && [ "$(wc -c <"$payload_file")" -gt "${COMPRESS_MIN:-2048}" ]; then
    if gzip -c -6 <"$payload_file" >"$payload_file.z" 2>/dev/null && [ -s "$payload_file.z" ]; then
        body_file="$payload_file.z"; gz_sent=1
    fi
fi
send_file=$body_file; seal_n=""
if [ -n "$enc_key" ]; then
    if seal "$body_file" "$payload_file.enc"; then send_file="$payload_file.enc"
    else echo "Versleutelen mislukt (openssl); rapport gaat onversleuteld" >&2; seal_n=""; fi
fi
# Antwoord van RzSense: versleuteld (dan eerst controleren) of gewoon. Klopt de handtekening niet: niets mee doen.
open_resp() {
    if [[ "$1" == '{"rzenc":'* ]]; then
        unseal "$1" "$seal_n" || { echo "Antwoord van RzSense niet te vertrouwen (handtekening klopt niet); genegeerd" >&2; return 1; }
    elif [ -n "$seal_n" ]; then
        # Versleuteld verstuurd, maar onversleuteld antwoord: dat komt niet van RzSense (die antwoordt dan altijd versleuteld)
        echo "Antwoord van RzSense niet te vertrouwen (niet versleuteld); genegeerd" >&2; return 1
    else
        printf '%s' "$1"
    fi
}

if [ "$TEST" = 1 ]; then
    # 7. Verzamelde gegevens tonen
    n_disks=$(grep -o '"mount"' <<<"$disks" | wc -l)
    n_ports=$(grep -o '"port"' <<<"$ports" | wc -l)
    pass "Gegevens verzameld: CPU ${cpu_pct}% (${cores} cores), $n_disks disk(s), $n_ports luisterende poort(en)"
    [ -n "$services" ] && hint "Services: $(sed 's/"//g; s/,$//; s/,/, /g' <<<"$services")"
    command -v ss >/dev/null 2>&1 || hint "ss ontbreekt: poorten worden niet gemeld (Rocky: dnf install iproute)"
    if [ -n "$svc_detail" ]; then
        pass "Diepere controle van services:"
        # Per service: poorten en het resultaat van de test (✘ = probleem)
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${svc_detail%,}" | sed -nE \
            's/^"([^"]+)":\{"sub":"running".*"ports":\[([0-9,]*)\],"ports_fail":\[([0-9,]*)\].*"sockets":\[([^]]*)\],"sockets_fail":\[([^]]*)\].*"probe_rc":([0-9]|null),"probe_msg":"([^"]*)".*"log_errors":([0-9]+|null).*/\1|\2|\3|\4|\5|\6|\7|\8/p' |
            while IFS='|' read -r n p pf so sf rc m le; do
                printf '%s %s: poorten %s%s%s%s%s\n' "$( [ -n "$pf$sf" ] || [ "$rc" = 2 ] && echo ✘ || echo ✔)" "$n" "${p:-geen}" \
                    "${pf:+ (dicht: $pf)}" "$( [ -n "$sf" ] && echo " · socket: ${sf//\"/}" || { [ -n "$so" ] && echo " · socket ${so//\"/} ok"; })" \
                    "${m:+ · $m}" "$( [ "$le" != null ] && [ "$le" != 0 ] && echo " · $le fout(en) in log")"
            done)
    fi
    if [ -n "$cmd_results" ]; then
        pass "Commando-checks:"
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${cmd_results%,}" | sed -nE \
            -e 's/^"([0-9]+)":\{"blocked":"([^"]*)".*/check \1: geblokkeerd (\2)/p' \
            -e 's/^"([0-9]+)":\{"error":"([^"]*)".*/check \1: \2/p' \
            -e 's/^"([0-9]+)":\{"rc":(-?[0-9]+),"out":"(([^"\\]|\\.){0,60}).*"ms":([0-9]+),"as":"([a-z]+)".*/check \1: exitcode \2 in \5 ms als \6: \3/p')
    elif grep -q $'^[0-9]*\tcmd\t' "${t_cache:-/dev/null}" 2>/dev/null; then
        hint "Commando-checks: nog niets uitgevoerd"
    fi
    if [ -n "$log_results" ]; then
        pass "Logbestanden doorzocht:"
        while IFS= read -r line; do hint "$line"; done < <(sed 's/},"/}\n"/g' <<<"${log_results%,}" |
            sed -nE 's/^"([0-9]+)":\{"new":([0-9]+).*"error":"([^"]*)"\}?$/\1|\2|\3/p' |
            while IFS='|' read -r i n e; do
                if [ -n "$e" ]; then echo "✘ zoekopdracht $i: $e"; else echo "zoekopdracht $i: $n nieuwe treffer(s)"; fi
            done)
    fi
    if [ -n "$podman_json" ]; then
        n_run=$(grep -o '"state":"running"' <<<"$podman_json" | wc -l)
        n_all=$(grep -o '"state":"' <<<"$podman_json" | wc -l)
        pass "Podman $(sed -n 's/^{"version":"\([^"]*\)".*/\1/p' <<<"$podman_json"): $n_run van $n_all container(s) draaien (${podman_ctx[*]})"
        p_err=$(sed -n 's/^{"version":"[^"]*","error":"\([^"]*\)".*/\1/p' <<<"$podman_json")
        [ -n "$p_err" ] && note "Podman-fout: $p_err"
    elif command -v podman >/dev/null 2>&1; then
        hint "Podman staat erop, maar wordt niet gemeld (PODMAN=0 of HA-modus)"
    fi
    if [ -n "$certs" ]; then
        n_certs=$(grep -o '"source"' <<<"$certs" | wc -l)
        pass "Certificaten: $n_certs uitgelezen"
        while IFS= read -r line; do
            hint "$line"
        # Per veld uitlezen (agent 1.66.1): sinds de keten-velden staat "error" niet meer achteraan
        done < <(sed 's/},{/}\n{/g' <<<"${certs%,}" | while IFS= read -r c; do
                src=$(sed -n 's/.*"source":"\([^"]*\)".*/\1/p' <<<"$c"); cn=$(sed -n 's/.*"cn":"\([^"]*\)".*/\1/p' <<<"$c")
                na=$(sed -n 's/.*"not_after":\([0-9][0-9]*\).*/\1/p' <<<"$c"); err=$(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' <<<"$c")
                vf=$(sed -n 's/.*"verify":"\([^"]*\)".*/\1/p' <<<"$c")
                if [ -n "$err" ]; then echo "✘ $src: $err"
                elif [ -n "$na" ]; then echo "$src: ${cn:-?}, nog $(( (na - $(date +%s)) / 86400 )) dagen geldig${vf:+ ($vf)}"
                else echo "$src: ${cn:-?}"; fi
            done)
    elif [ -n "$CERTS" ]; then
        hint "Certificaten: geen gevonden voor CERTS=\"$CERTS\""
    fi

    # 8. Echt rapport versturen
    resp=$(curl "${curl_k[@]}" -m 50 -X POST -H "Content-Type: application/json" "${auth[@]}" \
        --data-binary "@$send_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent" 2>&1)
    r_rc=$?
    r_code=${resp##*$'\n'}; r_body=${resp%$'\n'*}
    if [ "$r_code" = 200 ] && [[ "$r_body" == '{"rzenc":'* ]]; then
        r_body=$(open_resp "$r_body") && pass "Versleuteld verstuurd en het antwoord van RzSense klopt (🔒)"
    elif [ -n "$enc_key" ]; then hint "Sleutel aanwezig, maar het antwoord kwam onversleuteld terug"
    else hint "Niet versleuteld (geen sleutel): koppel de agent met een koppelcode (RzSense → Agent Manager → 🔗 Koppelcode)"; fi
    if [ -n "$kid" ]; then pass "🔑 Eigen sleutel van deze server (geen token meer nodig)"
    elif [ -n "$enc_key" ]; then hint "Nog de algemene sleutel: RzSense stuurt bij het volgende rapport een eigen sleutel mee"; fi
    if [ -z "$resp" ]; then
        fail "Rapport niet verstuurd: curl stopte zonder uitvoer (exitcode $r_rc, rapport $(wc -c <"$payload_file") bytes)"
    elif [ "$r_code" = 200 ] && [[ "$r_body" == *'"ok":true'* ]]; then
        pass "Rapport ontvangen door RzSense: $r_body"
        echo
        echo "  Alles werkt. Zie je in RzSense toch niets? Klap de server open → 'Agent Manager':"
        echo "  daar staat wanneer het laatste rapport binnenkwam en vanaf welk IP."
    else
        fail "Rapport niet geaccepteerd (HTTP $r_code): ${r_body:0:200}"
    fi
    exit "$TEST_FAILED"
fi

s_t0=$(now_ms); fb_used=0
resp=$(curl "${curl_opts[@]}" \
    -H "Content-Type: application/json" \
    "${auth[@]}" \
    --data-binary "@$send_file" \
    -w '\n%{http_code}' \
    "${MONITOR_URL%/}/api.php?action=agent")
rc=$?
h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
# Certificaat van RzSense past niet meer bij de vingerafdruk (curl 90), bv. een nieuw certificaat: één keer opnieuw
# zonder vastzetten, maar alleen versleuteld. Het antwoord moet dan met onze sleutel ondertekend zijn (dat kan
# alleen RzSense), en daarin staat de nieuwe vingerafdruk. Een ander certificaat met een vals antwoord: geweigerd.
if [ "$rc" = 90 ] && [ -n "$seal_n" ] && [ -n "$kid" ]; then   # agent 1.87.0: alleen met de eigen sleutel (geen token naar een onbekende server)
    echo "Certificaat van RzSense is veranderd; controleren met de sleutel" >&2
    p_opts=(-fsS -m 20 -X POST -k -H "X-Agent-Pin-Fail: 1")   # RzSense bepaalt de vingerafdruk dan meteen opnieuw
    resp=$(curl "${p_opts[@]}" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$send_file" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent")
    rc=$?
    h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
fi
# Gewone adres niet bereikbaar (geen verbinding, time-out, certificaat, of de proxy geeft 502-504)? Dan via het
# reserve-adres (agent 1.51.0), opnieuw versleuteld met een nieuwe nonce. RzSense ziet dat (X-Agent-Fallback) en meldt ⚠️.
if [ -n "$MONITOR_URL_FALLBACK" ] && { [[ " 6 7 28 35 52 56 60 " == *" $rc "* ]] || { [ "$rc" = 22 ] && [[ " 502 503 504 " == *" $h_code "* ]]; }; }; then
    echo "RzSense niet bereikbaar via $MONITOR_URL (curl $rc${h_code:+, HTTP $h_code}); via het reserve-adres $MONITOR_URL_FALLBACK" >&2
    if [ -n "$seal_n" ]; then seal "$body_file" "$payload_file.enc" || seal_n=""; fi
    spec_tls "$MONITOR_URL_FALLBACK" "$(url_spec "$MONITOR_URL_FALLBACK")"
    fb_opts=(-fsS -m 20 -X POST "${s_tls[@]}"); fb_used=1
    resp=$(curl "${fb_opts[@]}" -H "X-Agent-Fallback: 1" -H "Content-Type: application/json" "${auth[@]}" --data-binary "@$send_file" \
        -w '\n%{http_code}' "$MONITOR_URL_FALLBACK/api.php?action=agent")
    rc=$?
    h_code=${resp##*$'\n'}; resp=${resp%$'\n'*}
fi
# Geweigerd met de algemene sleutel van RzSense (bv. ouder dan de wisseltijd)? Na 3 keer die sleutel weggooien:
# dan gaat het volgende rapport onversleuteld (of met ENC_KEY). De eigen sleutel gooit de agent nooit weg; staat
# versleutelen uit (enc.off) en weigert RzSense het token, dan staat het weer aan: de volgende keer met de sleutel.
if [ "$rc" = 22 ] && [ -n "$seal_n" ] && [ -z "$kid" ] && [ -r "$enc_file" ]; then
    n_fail=$(( $(cat "$t_dir/enc.fail" 2>/dev/null || echo 0) + 1 ))
    if [ "$n_fail" -ge 3 ]; then rm -f "$enc_file" "$t_dir/enc.fail"; else echo "$n_fail" >"$t_dir/enc.fail"; fi
elif [ "$rc" = 22 ] && [ -z "$kid" ] && [ -n "$own_key" ] && [ -e "$t_dir/enc.off" ]; then
    rm -f "$t_dir/enc.off"
elif [ "$rc" = 22 ] && [ -n "$kid" ]; then
    echo "RzSense weigert dit rapport. De reden staat in RzSense bij Meldingen (bv. nieuw IP-adres, of de sleutel is vernieuwd: maak dan een koppelcode in de Agent Manager)." >&2
elif [ "$rc" = 0 ]; then
    rm -f "$t_dir/enc.fail"
fi
if [ "$rc" = 0 ]; then resp=$(open_resp "$resp") || rc=1; fi
# Kan RzSense gecomprimeerde rapporten lezen? Dan zegt hij "gz":1. Geweigerd terwijl het gecomprimeerd ging: de volgende
# keer weer gewoon, tot RzSense het opnieuw zegt. Zo werkt een oudere RzSense altijd.
if [ "$rc" = 0 ] && [[ "$resp" =~ \"gz\":[[:space:]]*1 ]]; then
    touch "$t_dir/gz.ok" 2>/dev/null
elif [ "$rc" = 0 ] || { [ "$gz_sent" = 1 ] && [ "$rc" = 22 ]; }; then
    rm -f "$t_dir/gz.ok" 2>/dev/null
fi
# Dit contact bijhouden voor --status (1.60.0): laatste rapport, de laatste 30 contacten, fouten, controle van RzSense
if mkdir -p "$st_dir" 2>/dev/null && [ -w "$st_dir" ]; then
    st_now=$(date +%s); st_ms=$(( $(now_ms) - run_t0 )); st_sms=$(( $(now_ms) - s_t0 ))
    st_err=""
    if [ "$rc" != 0 ]; then
        case "$rc" in
            22) st_err="RzSense weigerde het rapport (HTTP ${h_code:-?}); de reden staat in RzSense (Agent Manager)" ;;
            6) st_err="naam van RzSense niet gevonden (DNS)" ;; 7) st_err="geen verbinding (poort dicht of RzSense uit)" ;;
            28) st_err="geen antwoord binnen $REPORT_WAIT seconden (RzSense is te traag; zie RzSense → Instellingen → Overzicht → Gezondheid)" ;; 35|60) st_err="TLS/certificaat klopt niet" ;; 90) st_err="certificaat past niet bij de vingerafdruk" ;;
            1) st_err="antwoord van RzSense niet te vertrouwen (handtekening)" ;; *) st_err="curl $rc${h_code:+, HTTP $h_code}" ;;
        esac
    fi
    st_rcv=$(sed -n 's/.*"received":{\([^}]*\)}.*/\1/p' <<<"$resp" | head -c 300)
    (umask 077; printf 'time=%s\nrc=%s\nhttp=%s\nms=%s\nsend_ms=%s\nbytes=%s\nsent=%s\nenc=%s\nvia=%s\nerror=%s\nreceived=%s\nparts=%s\ngz=%s\n' \
        "$st_now" "$rc" "${h_code:-}" "$st_ms" "$st_sms" "$(wc -c <"$payload_file")" "$(wc -c <"$send_file")" "$( [ -n "$seal_n" ] && echo 1 || echo 0)" \
        "$( [ "$fb_used" = 1 ] && echo fallback || echo primary)" "$st_err" "$st_rcv" "$parts" "$gz_sent" >"$st_dir/last") 2>/dev/null
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$st_now" "$rc" "${h_code:-}" "$st_ms" "$(wc -c <"$send_file")" "$( [ -n "$seal_n" ] && echo 1 || echo 0)" \
        "$( [ "$fb_used" = 1 ] && echo fallback || echo primary)" >>"$st_dir/history" 2>/dev/null
    [ "$(wc -l 2>/dev/null <"$st_dir/history")" -gt 30 ] && { tail -n 30 "$st_dir/history" >"$st_dir/history.tmp" && mv -f "$st_dir/history.tmp" "$st_dir/history"; }
    if [ -n "$st_err" ]; then
        printf '%s\t%s\n' "$st_now" "$st_err" >>"$st_dir/errors" 2>/dev/null
        [ "$(wc -l 2>/dev/null <"$st_dir/errors")" -gt 20 ] && { tail -n 20 "$st_dir/errors" >"$st_dir/errors.tmp" && mv -f "$st_dir/errors.tmp" "$st_dir/errors"; }
    fi
    # De beveiligingscontrole van RzSense (regels tekst, RzSense 1.100.0+)
    if [ "$rc" = 0 ] && [[ "$resp" =~ \"sec\":\[([^]]*)\] ]]; then
        grep -o '"[^"]*"' <<<"${BASH_REMATCH[1]}" | sed 's/^"//; s/"$//; s#\\/#/#g' >"$st_dir/sec" 2>/dev/null
    fi
fi
# Agent 1.69.0: wachtrij bijwerken. Gelukt: wat meeging eraf. Mislukt: de metingen van nu erbij (CPU, RAM, disks), zodat
# de grafieken in RzSense later toch compleet zijn. Hooguit 2880 regels (een dag bij een tik van 30 s), de oudste gaan eruit.
if [ "$rc" = 0 ] && [ "$q_sent" -gt 0 ]; then
    tail -n +"$(( q_sent + 1 ))" "$q_file" >"$q_file.tmp" 2>/dev/null && mv -f "$q_file.tmp" "$q_file"
    [ -s "$q_file" ] || rm -f "$q_file"
elif [ "$rc" != 0 ] && [ -d "$k_dir" ]; then
    (umask 077; printf '{"t":%s,"cpu":%s,"mem":%s,"disks":[%s]}\n' "$(date +%s)" "$cpu_pct" "$mem" "${disks%,}" >>"$q_file") 2>/dev/null
    [ "$(wc -l 2>/dev/null <"$q_file" || echo 0)" -gt 2880 ] && { tail -n 2880 "$q_file" >"$q_file.tmp" && mv -f "$q_file.tmp" "$q_file"; }
fi
# Agent 1.82.0: update van RzSense. Alleen uit een versleuteld antwoord (een foutpagina of proxy kan het niet nadoen)
hold_now=$(date +%s)
if [ "$rc" = 0 ]; then
    if [ -n "$seal_n" ] && [[ "$resp" =~ \"rz_hold\":\{\"id\":\"([0-9a-f]{8,32})\",\"max\":([0-9]{1,6}) ]]; then
        h_max=${BASH_REMATCH[2]}; [ "$h_max" -gt 3600 ] && h_max=3600
        [ "${BASH_REMATCH[1]}" = "$hold_id" ] || hold_since=0
        hold_id=${BASH_REMATCH[1]}; hold_until=$(( hold_now + h_max ))
        (umask 077; printf '%s %s %s\n' "$hold_id" "$hold_until" "$hold_since" >"$hold_file") 2>/dev/null
    elif [ -n "$hold_id" ]; then
        if [ "$hold_since" -gt 0 ]; then
            hold_released=1
            echo "RzSense is bijgewerkt en weer bereikbaar na $(( hold_now - hold_since )) s; de wachtrij gaat mee" >&2
        fi
        rm -f "$hold_file"; hold_id=""; hold_until=0
    fi
elif [ -n "$hold_id" ] && [ "$hold_now" -lt "$hold_until" ]; then
    [ "$hold_since" -gt 0 ] || hold_since=$hold_now
    (umask 077; printf '%s %s %s\n' "$hold_id" "$hold_until" "$hold_since" >"$hold_file") 2>/dev/null
    echo "RzSense wordt bijgewerkt: meting in de wachtrij, wacht op de vrijgave (hooguit tot $(date -d "@$hold_until" +%H:%M 2>/dev/null || echo "$hold_until"))" >&2
fi
# Uitvoer (komt in het logboek): zonder sleutels
sed -E 's/"(agent_key|enc_key)":"[0-9a-f]{64}"/"\1":"…"/g' <<<"$resp"
if [ "$rc" -eq 0 ]; then
    # Versleuteling: eigen sleutel en vingerafdruk van RzSense bewaren (alleen uit een versleuteld antwoord), de
    # algemene sleutel (agent 1.49), of stoppen als het voor deze server uit staat
    if [ -n "$seal_n" ]; then
        store_key "$resp" && rm -f "$t_dir/enc.off"
        store_pin "$resp"
        # Per adres hoe het gecontroleerd wordt (RzSense 1.78.0): alleen voor het gewone en het reserve-adres
        if [[ "$resp" =~ \"url_pins\":\{([^}]*)\} ]]; then
            p_rest=${BASH_REMATCH[1]}; p_args=()
            while [[ "$p_rest" =~ \"([^\"]+)\":\"([^\"]*)\" ]]; do
                p_u=${BASH_REMATCH[1]//\\/}; p_s=${BASH_REMATCH[2]//\\/}
                p_rest=${p_rest#*"${BASH_REMATCH[0]}"}
                [ "$p_u" = "$MONITOR_URL" ] || [ "$p_u" = "$MONITOR_URL_FALLBACK" ] || continue
                [ "$(url_spec "$p_u")" = "$p_s" ] || p_args+=("$p_u" "$p_s")
            done
            [ ${#p_args[@]} -gt 0 ] && url_pins_put "${p_args[@]}"
        fi
    fi
    if [[ "$resp" =~ \"enc_key\":\"([0-9a-f]{64})\" ]] && [ -n "$seal_n" ]; then
        (umask 077; printf '%s\n' "${BASH_REMATCH[1]}" >"$enc_file.tmp" && mv -f "$enc_file.tmp" "$enc_file") && rm -f "$t_dir/enc.off"
    elif [[ "$resp" =~ \"enc\":\"off\" ]]; then
        rm -f "$enc_file"; : >"$t_dir/enc.off"
    fi
    # Lokaas-poort: de gemelde IP's zijn binnen, uit de set halen (een nieuwe verbinding zet ze er weer in)
    [ -n "$dc_sent4" ] && nft delete element inet rzmon dc4 "{ $dc_sent4 }" 2>/dev/null
    [ -n "$dc_sent6" ] && nft delete element inet rzmon dc6 "{ $dc_sent6 }" 2>/dev/null
    # Heartbeats zijn binnen; namen zonder check in RzSense in het logboek zetten
    [ ${#hb_files[@]} -gt 0 ] && rm -f "${hb_files[@]}"
    if [[ "$resp" =~ \"hb_unknown\":\[([^]]*)\] ]]; then
        echo "rzmon-hb: RzSense heeft geen heartbeat-check met de naam ${BASH_REMATCH[1]//\"/} op deze server (＋ Check → Heartbeat)" >&2
    fi
    # Uitkomst van een overstap is doorgegeven; vraagt RzSense om naar een ander adres over te stappen (alleen versleuteld)?
    [ -n "$url_result" ] && rm -f "$k_dir/url.result"
    if [ -n "$seal_n" ] && [[ "$resp" =~ \"url_set\":\{\"url\":\"([^\"]+)\",\"fallback\":\"([^\"]*)\"(,\"pin\":\"([^\"]*)\",\"fb_pin\":\"([^\"]*)\")?\} ]]; then
        url_switch "$(sed 's#\\/#/#g' <<<"${BASH_REMATCH[1]}")" "$(sed 's#\\/#/#g' <<<"${BASH_REMATCH[2]}")" "${BASH_REMATCH[4]//\\/}" "${BASH_REMATCH[5]//\\/}"
    fi
    # Uitkomst is doorgegeven; vraagt RzSense om bij te werken?
    [ -n "$upd_result" ] && rm -f "$upd_dir/update.result"
    # IDS, bestanden bewaken: RzSense heeft de wijzigingen; dit is nu de stand om mee te vergelijken
    if [ -n "$fim_commit" ]; then
        mv -f "$fim_dir/state.new" "$fim_dir/state" && mv -f "$fim_dir/meta.new" "$fim_dir/meta" && mv -f "$fim_dir/roots.new" "$fim_dir/roots"
        [ "$fim_rh" != /dev/null ] && touch "$i_dir/rehash.used"
    fi
    # Instellingen in RzSense gewijzigd (bv. een service-check toegevoegd)? Lijst bij de volgende run opnieuw ophalen.
    rev=$(sed -n 's/.*"tests_rev":[[:space:]]*"\([0-9a-f]\{8,64\}\)".*/\1/p' <<<"$resp")
    if [ -n "$rev" ] && [ -n "${t_cache-}" ] && [ -r "$t_cache" ] && [ "$(sha1sum "$t_cache" | cut -c1-12)" != "$rev" ]; then
        : >"$t_dir/tests.stale" 2>/dev/null || rm -f "$t_cache"   # volgende run opnieuw ophalen; de oude lijst blijft tot dan
    fi
    [[ "$resp" =~ \"update\":[[:space:]]*\{ ]] && [ -z "$hold_id" ] && remote_update "$resp"   # 1.82.0: niet tijdens een update van RzSense
    # Softwarelijst: RzSense heeft deze stand nu (of vraagt om alles opnieuw); uitkomst van het bijwerken is doorgegeven
    if [ -n "$pk_hash" ]; then
        if [[ "$resp" =~ \"packages_resend\":[[:space:]]*true ]]; then : >"$pk_dir/resend"; rm -f "$pk_dir/sent"
        elif [[ "$resp" =~ \"packages_ok\":[[:space:]]*true ]]; then printf '%s' "$pk_hash" >"$pk_dir/sent"; rm -f "$pk_dir/tried"; fi
    fi
    [ -n "$pkg_result" ] && rm -f "$pk_dir/upgrade.result"
    # Endpoint-bewaking: de meegestuurde gebeurtenissen zijn binnen; vraagt RzSense om processen meteen te stoppen?
    # (alleen versleuteld, en alleen als de starttijd klopt: nooit een ander proces dat dat nummer later kreeg)
    if [ -n "$edr_json" ] && [ -s "$edr_dir/edr.sending" ]; then
        tail -n +$(( ${e_take:-500} + 1 )) "$edr_dir/edr.sending" >"$edr_dir/edr.rest" 2>/dev/null && mv -f "$edr_dir/edr.rest" "$edr_dir/edr.sending"
        [ -s "$edr_dir/edr.sending" ] || rm -f "$edr_dir/edr.sending"
        rm -f "$edr_dir/edr.drop"
    fi
    # Weblog (agent 1.87.0): ook de positie in de logbestanden pas nu bewaren
    if [ -n "${w_dir-}" ]; then
        for w_pos in "$w_dir"/*.pos.new; do [ -f "$w_pos" ] && mv -f "$w_pos" "${w_pos%.new}"; done
    fi
    # Suricata: de positie pas bewaren als het rapport is aangekomen
    [ -n "$edr_json" ] && [ -f "$edr_dir/eve.pos.new" ] && mv -f "$edr_dir/eve.pos.new" "$edr_dir/eve.pos"
    # YARA-regels van RzSense (alleen versleuteld): eerst controleren of yara ze kan lezen, dan pas vervangen
    if [ -n "$seal_n" ] && [ -n "$edr_json" ] && [[ "$resp" =~ \"edr_yara\":\"([A-Za-z0-9+/=]{20,})\" ]]; then
        printf '%s' "${BASH_REMATCH[1]}" | base64 -d >"$edr_dir/rzmon.yar.new" 2>/dev/null
        if command -v yara >/dev/null 2>&1 && timeout 20 yara -w "$edr_dir/rzmon.yar.new" /dev/null >/dev/null 2>&1; then
            chmod 600 "$edr_dir/rzmon.yar.new" && mv -f "$edr_dir/rzmon.yar.new" "$edr_dir/rzmon.yar"
        else
            rm -f "$edr_dir/rzmon.yar.new"
        fi
    fi
    if [ -n "$seal_n" ] && [ "$IDS_BLOCK" != 0 ] && [ "$(id -u)" = 0 ] && [[ "$resp" =~ \"edr_kill\":\[(\[[0-9]+,[0-9]+\],?)+\] ]]; then
        mkdir -p "$t_dir/ids" 2>/dev/null
        while IFS=, read -r k_pid k_start; do
            [[ "$k_pid" =~ ^[0-9]+$ ]] && [[ "$k_start" =~ ^[0-9]+$ ]] && [ "$k_pid" -gt 1 ] || continue
            [ -f "$t_dir/ids/killed.$k_pid.$k_start" ] && continue
            k_res=weg
            if [ "$(awk '{ print $22 }' "/proc/$k_pid/stat" 2>/dev/null)" = "$k_start" ]; then
                # Nooit iets van RzSense of de agent zelf (agent 1.65.0)
                if [[ "$(cat "/proc/$k_pid/cgroup" 2>/dev/null)" =~ /rzmon-[^/]*\.service ]]; then k_res=eigen
                elif kill -KILL "$k_pid" 2>/dev/null; then k_res=ok; else k_res=fout; fi
            fi
            : >"$t_dir/ids/killed.$k_pid.$k_start"
            printf '[%s,%s,"%s"],' "$k_pid" "$k_start" "$k_res" >>"$t_dir/ids/killed.pending"
        done < <(grep -o '\[[0-9]*,[0-9]*\]' <<<"${BASH_REMATCH[0]#*:}" | tr -d '[]')
    fi
    [[ "$resp" =~ \"pkg_upgrade\":[[:space:]]*\{ ]] && [ -n "$pk_mgr" ] && pkg_upgrade "$resp"
    [[ "$resp" =~ \"ha_update\":[[:space:]]*\{ ]] && [ "$HA_MODE" = 1 ] && ha_update "$resp"   # agent 1.92.0
    # Zelf herstellen: de uitkomsten zijn binnen; vraagt RzSense om een herstelcommando (alleen versleuteld)?
    if [ -s "$heal_dir/done.sending" ]; then tail -n +51 "$heal_dir/done.sending" >>"$heal_dir/done"; [ -s "$heal_dir/done" ] || rm -f "$heal_dir/done"; fi
    rm -f "$heal_dir/done.sending" "$t_dir/watchdog.gap"
    if [ -n "$act_json" ] && [ -s "$t_dir/act.sending" ]; then   # agent 1.89.0: verstuurde logboekregels weg, de rest blijft
        tail -n +101 "$t_dir/act.sending" >"$t_dir/act.sending.tmp" 2>/dev/null && mv -f "$t_dir/act.sending.tmp" "$t_dir/act.sending"
        [ -s "$t_dir/act.sending" ] || rm -f "$t_dir/act.sending"
    fi
    if [ -n "$seal_n" ] && [ "$HEAL" != 0 ] && [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [[ "$resp" =~ \"heal_run\":\[(\[[0-9]+,\"[A-Za-z0-9._-]+\"\],?)+\] ]]; then
        while IFS=, read -r hr_id hr_name; do
            hr_id=${hr_id//[^0-9]/}; hr_name=${hr_name//\"/}; hr_name=${hr_name%]}
            [[ "$hr_id" =~ ^[0-9]+$ ]] && [[ "$hr_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || continue
            heal_ok "$hr_id" || continue
            hr_f="$HEAL_DIR/$hr_name"
            if ! heal_cmd_ok "$hr_f"; then
                heal_log "$hr_id" 0 "herstelcommando $hr_f niet gebruikt: bestaat niet, of is niet uitvoerbaar, niet van root of door anderen te wijzigen"
                continue
            fi
            hr_out=$(timeout 120 "$hr_f" 2>&1); hr_rc=$?
            hr_msg=$(grep -v '^[[:space:]]*$' <<<"$hr_out" | head -n 1 | cut -c1-150)
            if [ "$hr_rc" = 0 ]; then heal_log "$hr_id" 1 "herstelcommando $hr_name uitgevoerd${hr_msg:+: $hr_msg}"
            elif [ "$hr_rc" = 124 ]; then heal_log "$hr_id" 0 "herstelcommando $hr_name duurde langer dan 120 seconden"
            else heal_log "$hr_id" 0 "herstelcommando $hr_name mislukt (code $hr_rc)${hr_msg:+: $hr_msg}"; fi
        done < <(grep -o '\[[0-9]*,"[A-Za-z0-9._-]*"\]' <<<"${BASH_REMATCH[0]#*:}" | tr -d '[]')
    fi
elif [ -s "$heal_dir/done.sending" ]; then
    cat "$heal_dir/done.sending" >>"$heal_dir/done" 2>/dev/null && rm -f "$heal_dir/done.sending"   # volgende keer opnieuw
fi

