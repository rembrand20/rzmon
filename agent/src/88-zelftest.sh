# ---------------------------------------------------------------- Zelftest (agent 1.79.0)
# Op verzoek van RzSense (🧪 Test in de Agent Manager: regel "0 <tab> selftest <tab> id") test de agent zichzelf, één keer
# per vraag: planning, instellingenbestand, DNS, het adres en het reserve-adres van RzSense, de klok, vrije ruimte, de
# wachtrij en fouten in zijn log. Alleen vaste controles die lezen; RzSense stuurt geen commando's mee.
selftest_json=""
if [ "$TEST" != 1 ] && [ -r "${t_cache-}" ]; then
    st_line=$(awk -F'\t' '$1 == "0" && $2 == "selftest" { print $3; exit }' "$t_cache")
    if [[ "$st_line" =~ ^[0-9]{1,18}$ ]] && [ "$(cat "$t_dir/selftest.done" 2>/dev/null)" != "$st_line" ]; then
        st_items=""
        st_add() { st_items+="{\"k\":\"$1\",\"ok\":\"$2\",\"d\":\"$(json_escape "$3")\"},"; }
        # Planning: systemd-timer, cron, of de add-on / TrueNAS
        if [ "$HA_MODE" = 1 ]; then
            st_add sched ok "add-on van Home Assistant"
        elif [ -n "${AGENT_DIR-}" ]; then
            st_add sched ok "cronjob van TrueNAS ($AGENT_DIR)"
        elif command -v systemctl >/dev/null 2>&1 && systemctl cat rzmon-agent.timer >/dev/null 2>&1; then
            if systemctl is-active --quiet rzmon-agent.timer; then st_add sched ok "systemd-timer actief"
            else st_add sched bad "systemd-timer staat niet aan: sudo systemctl enable --now rzmon-agent.timer"; fi
        elif [ -f /etc/cron.d/rzmon-agent ]; then
            st_add sched ok "cron (/etc/cron.d/rzmon-agent)"
        else
            st_add sched warn "geen timer of cronjob gevonden: draait de agent alleen met de hand?"
        fi
        # Instellingenbestand: alleen leesbaar voor root
        if [ -n "${conf_file-}" ]; then
            st_perm=$(stat -c %a "$conf_file" 2>/dev/null)
            if [ "$st_perm" = 600 ]; then st_add conf ok "$conf_file (600)"
            else st_add conf warn "$conf_file heeft rechten ${st_perm:-?}: sudo chmod 600 $conf_file"; fi
        fi
        # Naam van RzSense opzoeken en het adres (en reserve-adres) bereiken
        st_host=${MONITOR_URL#*://}; st_host=${st_host%%/*}; st_host=${st_host%:*}; st_host=${st_host#[}; st_host=${st_host%]}
        if [[ "$st_host" =~ ^[0-9.]+$ || "$st_host" == *:* ]]; then
            st_add dns skip "adres is een IP-adres ($st_host)"
        elif command -v getent >/dev/null 2>&1; then
            if st_ip=$(timeout 5 getent hosts "$st_host" | awk '{ print $1; exit }') && [ -n "$st_ip" ]; then st_add dns ok "$st_host → $st_ip"
            else st_add dns bad "$st_host is niet op te zoeken"; fi
        fi
        st_reach() {  # st_reach <k> <adres>
            local code
            spec_tls "$2" "$(url_spec "$2")"
            code=$(curl -sS -o /dev/null -m 10 "${s_tls[@]}" -w '%{http_code} %{time_total}' "${2%/}/api.php?action=ping" 2>/dev/null)
            if [ "${code%% *}" != 000 ] && [ -n "$code" ]; then
                st_add "$1" ok "$2 bereikbaar in $(awk -v t="${code#* }" 'BEGIN { printf "%d", t * 1000 }') ms"
            else
                st_add "$1" "$( [ "$1" = url ] && echo bad || echo warn)" "$2 niet bereikbaar (curl $(curl -sS -o /dev/null -m 10 "${s_tls[@]}" "${2%/}/" 2>&1 | head -c 120))"
            fi
        }
        st_reach url "$MONITOR_URL"
        [ -n "$MONITOR_URL_FALLBACK" ] && st_reach fallback "$MONITOR_URL_FALLBACK"
        # Klok gesynchroniseerd?
        st_src=${time_json#*\"src\":\"}; st_src=${st_src%%\"*}
        if [[ "$time_json" == *'"sync":true'* ]]; then st_add clock ok "gesynchroniseerd ($st_src)"
        elif [ -n "$time_json" ]; then st_add clock warn "niet gesynchroniseerd met een tijdserver"
        else st_add clock skip "geen chrony of systemd-timesyncd"; fi
        # Vrije ruimte waar de agent zijn gegevens bewaart
        st_free=$(df -Pk "$t_dir" 2>/dev/null | awk 'NR == 2 { print int($4 / 1024) }')
        if [ -n "$st_free" ]; then
            if [ "$st_free" -lt 50 ]; then st_add disk bad "nog $st_free MB vrij in $t_dir"
            elif [ "$st_free" -lt 500 ]; then st_add disk warn "nog $st_free MB vrij in $t_dir"
            else st_add disk ok "$st_free MB vrij"; fi
        fi
        # Wachtrij van rapporten die niet aankwamen
        st_q=$(wc -l <"$k_dir/queue.jsonl" 2>/dev/null || echo 0)
        if [ "${st_q:-0}" -gt 0 ]; then st_add queue warn "$st_q metingen wachten nog (gaan met de volgende rapporten mee)"
        else st_add queue ok "leeg"; fi
        # Fouten in het log van de agent (laatste uur)
        if [ "$HA_MODE" = 0 ] && command -v journalctl >/dev/null 2>&1 && [ -z "${AGENT_DIR-}" ]; then
            st_err=$(timeout 10 journalctl -u rzmon-agent.service -p err --since -1h -q --no-pager 2>/dev/null | wc -l)
            st_last=$(timeout 10 journalctl -u rzmon-agent.service -p err --since -1h -q --no-pager -o cat 2>/dev/null | tail -n 1 | head -c 160)
            if [ "${st_err:-0}" -gt 0 ]; then st_add log warn "$st_err fout(en), laatste: $st_last"
            else st_add log ok "geen fouten"; fi
        fi
        # Hulpprogramma's die de agent nodig heeft
        st_miss=""
        for st_t in curl awk openssl sha256sum; do command -v "$st_t" >/dev/null 2>&1 || st_miss+="$st_t "; done
        if [ -n "$st_miss" ]; then st_add tools bad "ontbreekt: $st_miss"; else st_add tools ok "curl, awk, openssl, sha256sum"; fi
        selftest_json="{\"id\":$st_line,\"now\":$(date +%s),\"items\":[${st_items%,}]}"
        printf '%s' "$st_line" >"$t_dir/selftest.done" 2>/dev/null
    fi
fi
