# ---------------------------------------------------------------- Updates (patchbeheer, agent 1.92.0)
lap patch
# Aan per server in RzSense (regel "0 <tab> patch <tab> 1"):
#  - Linux (als root): eens per uur welke updates er klaarstaan en welke daarvan beveiligingsupdates zijn (dnf
#    check-update [--security], of apt-get -s upgrade; de apt-lijsten worden hooguit eens per 6 uur ververst), en of
#    een herstart nodig is. Installeren gaat via pkg_upgrade (mode security of all, eventueel eerst een snapshot).
#  - Home Assistant (add-on): elke 15 minuten wat er voor Core en de add-ons klaarstaat (Supervisor). Vraagt RzSense
#    erom ("ha_update" in het antwoord), dan werkt de agent Core en/of add-ons bij via de Supervisor, altijd met een
#    back-up vooraf. Het besturingssysteem (OS) niet: dat herstart het hele systeem, dat doe je in Home Assistant zelf.
# Nooit een herstart, nooit op TrueNAS (apt kan TrueNAS kapotmaken).
patch_json=""; ha_upd_json=""
pt_dir="$t_dir/patch"
pt_line=""
[ -r "${t_cache-}" ] && pt_line=$(awk -F'\t' '$1 == "0" && $2 == "patch" && $3 == "1" { print; exit }' "$t_cache")
if [ -n "$pt_line" ] && { [ "$HA_MODE" = 1 ] || [ "$(id -u)" = 0 ]; } && ! command -v midclt >/dev/null 2>&1; then
    mkdir -p "$pt_dir" 2>/dev/null && chmod 700 "$pt_dir" 2>/dev/null
    pt_age=$([ "$HA_MODE" = 1 ] && echo 15 || echo 60)
    pt_fresh=0
    if [ ! -s "$pt_dir/avail.json" ] || [ -n "$(find "$pt_dir/avail.json" -mmin +"$pt_age" 2>/dev/null)" ] \
        || { [ -f "${pk_dir:-/nonexistent}/upgrade.result" ] && [ "${pk_dir}/upgrade.result" -nt "$pt_dir/avail.json" ]; } \
        || [ -f "$pt_dir/ha.result" ]; then
        pt_fresh=1
    fi
    if [ "$pt_fresh" = 1 ] && [ "$HA_MODE" = 0 ]; then
        : >"$pt_dir/list.new"; pt_mgr=""
        if command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
            pt_mgr=rpm; pt_dnf=dnf; command -v dnf >/dev/null 2>&1 || pt_dnf=yum
            # 3 kolommen: naam.arch versie repo; "--security" geeft alleen de beveiligingsupdates
            pt_parse() { awk 'NF == 3 && $1 ~ /\./ && $2 ~ /[0-9]/ { n = $1; sub(/\.[^.]+$/, "", n); print n "\t" $2 }'; }
            timeout 180 nice -n 19 "$pt_dnf" -q check-update 2>/dev/null | pt_parse | sort -u >"$pt_dir/all"
            { timeout 180 nice -n 19 "$pt_dnf" -q check-update --security 2>/dev/null || timeout 180 nice -n 19 "$pt_dnf" -q check-upgrade --security 2>/dev/null; } \
                | pt_parse | cut -f1 | sort -u >"$pt_dir/sec"
            awk -F'\t' 'NR == FNR { s[$1] = 1; next } { print $1 "\t" $2 "\t" (($1 in s) ? 1 : 0) }' "$pt_dir/sec" "$pt_dir/all" >"$pt_dir/list.new"
            pt_rb=0
            if command -v needs-restarting >/dev/null 2>&1; then timeout 60 needs-restarting -r >/dev/null 2>&1; [ $? = 1 ] && pt_rb=1; fi
        elif command -v apt-get >/dev/null 2>&1; then
            pt_mgr=dpkg
            if [ -z "$(find /var/lib/apt/lists -maxdepth 0 -mmin -360 2>/dev/null)" ]; then
                timeout 300 nice -n 19 apt-get update -qq >/dev/null 2>&1
            fi
            # Inst naam [oude versie] (nieuwe versie bron [arch]); bron met "security" = beveiligingsupdate
            timeout 120 nice -n 19 apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null \
                | awk '/^Inst / { v = $0; sub(/^[^(]*\(/, "", v); sub(/ .*/, "", v); print $2 "\t" v "\t" (($0 ~ /[Ss]ecurity/) ? 1 : 0) }' | sort -u >"$pt_dir/list.new"
            pt_rb=0; [ -f /var/run/reboot-required ] && pt_rb=1
        fi
        if [ -n "$pt_mgr" ]; then
            pt_n=$(wc -l <"$pt_dir/list.new"); pt_s=$(awk -F'\t' '$3 == 1' "$pt_dir/list.new" | wc -l)
            pt_list=$(head -n 300 "$pt_dir/list.new" | while IFS=$'\t' read -r a b c; do printf '["%s","%s",%s],' "$(json_escape "$a")" "$(json_escape "$b")" "${c:-0}"; done)
            printf '{"mgr":"%s","avail":%s,"sec":%s,"rb":%s,"at":%s,"list":[%s]}' "$pt_mgr" "$pt_n" "$pt_s" "${pt_rb:-0}" "$(date +%s)" "${pt_list%,}" >"$pt_dir/avail.json"
            patch_json=$(cat "$pt_dir/avail.json")
        fi
        rm -f "$pt_dir/list.new" "$pt_dir/all" "$pt_dir/sec"
    elif [ "$pt_fresh" = 1 ] && [ "$HA_MODE" = 1 ] && [ -n "${SUPERVISOR_TOKEN-}" ] && command -v jq >/dev/null 2>&1; then
        pt_get() { curl -fsS -m 20 -H "Authorization: Bearer $SUPERVISOR_TOKEN" "${SUPERVISOR_URL:-http://supervisor}$1" 2>/dev/null; }
        pt_list=$( {
            pt_get /core/info | jq -c 'select(.data.update_available) | ["core", .data.version_latest, 0, "Home Assistant Core"]'
            pt_get /os/info | jq -c 'select(.data.update_available) | ["os", .data.version_latest, 0, "Home Assistant OS"]'
            pt_get /addons | jq -c '.data.addons[] | select(.update_available) | ["addon:" + .slug, (.version_latest // ""), 0, .name]'
        } 2>/dev/null | paste -sd, -)
        pt_n=$(printf '%s' "$pt_list" | grep -o '\["' | wc -l)
        printf '{"mgr":"ha","avail":%s,"sec":0,"rb":0,"at":%s,"list":[%s]}' "$pt_n" "$(date +%s)" "$pt_list" >"$pt_dir/avail.json"
        patch_json=$(cat "$pt_dir/avail.json")
    fi
    # Uitkomst van het bijwerken van Home Assistant (op de achtergrond gedaan) terugmelden
    if [ -f "$pt_dir/ha.result" ] && [ ! -f "$pt_dir/ha.lock" ]; then
        IFS=$'\t' read -r pt_id pt_ok pt_bad <"$pt_dir/ha.result"
        if [[ "$pt_id" =~ ^[0-9a-f]{8,32}$ ]]; then
            ha_upd_json="{\"id\":\"$pt_id\",\"ok\":\"$(json_escape "$pt_ok")\",\"bad\":\"$(json_escape "$pt_bad")\"}"
        fi
        rm -f "$pt_dir/ha.result"
    fi
fi

ha_update() {  # ha_update <antwoord van RzSense>: Core en/of add-ons bijwerken via de Supervisor, met een back-up vooraf
    local id what w
    id=$(sed -n 's/.*"ha_update":[[:space:]]*{[^}]*"id":[[:space:]]*"\([0-9a-f]\{8,32\}\)".*/\1/p' <<<"$1")
    what=$(sed -n 's/.*"ha_update":[[:space:]]*{[^}]*"what":[[:space:]]*\[\([^]]*\)\].*/\1/p' <<<"$1" | tr -d '"' | tr ',' ' ')
    [ -n "$id" ] && [ -n "$what" ] && [ -n "${SUPERVISOR_TOKEN-}" ] && [ -d "$pt_dir" ] || return 0
    if [ -f "$pt_dir/ha.lock" ] && [ -z "$(find "$pt_dir/ha.lock" -mmin +120 2>/dev/null)" ]; then return 0; fi
    : >"$pt_dir/ha.lock"
    act_log patch 2 "Home Assistant bijwerken gestart" "$what (met back-up vooraf)"
    (
        ok=""; bad=""
        for w in $what; do
            case "$w" in
                core) ep=/core/update ;;
                addon:*) s=${w#addon:}; [[ "$s" =~ ^[a-z0-9_-]{1,64}$ ]] || continue; ep="/store/addons/$s/update" ;;
                *) continue ;;   # OS nooit: dat herstart het hele systeem
            esac
            code=$(curl -sS -m 3600 -o "$pt_dir/ha.out" -w '%{http_code}' -X POST -H "Authorization: Bearer $SUPERVISOR_TOKEN" \
                -H 'Content-Type: application/json' -d '{"backup":true}' "${SUPERVISOR_URL:-http://supervisor}$ep" 2>/dev/null)
            if [ "$code" = 200 ]; then
                ok+="$w "; act_log patch 1 "Bijgewerkt via de Supervisor" "$w (back-up vooraf)"
            else
                bad+="$w ($code) "; act_log patch 0 "Bijwerken via de Supervisor mislukt" "$w: HTTP $code $(head -c 200 "$pt_dir/ha.out" 2>/dev/null | tr -d '\n')"
            fi
        done
        printf '%s\t%s\t%s\n' "$id" "${ok% }" "${bad% }" >"$pt_dir/ha.result"
        rm -f "$pt_dir/ha.lock" "$pt_dir/ha.out" "$pt_dir/avail.json"
    ) </dev/null >/dev/null 2>&1 &
}
