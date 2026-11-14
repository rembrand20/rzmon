# ---------------------------------------------------------------- Reverse proxy beschermen (agent 1.97.0, RzSense 1.254.0)
lap proxy
# Alleen op een server die in RzSense de rol "reverse proxy" heeft (regel "0 <tab> proxy <tab> on|off"). De agent herkent
# de proxy (SWAG of Nginx Proxy Manager, in Podman of Docker), leest de sites uit en voert het beleid per site door:
#   0 <tab> proxylan <tab> netwerken                 je LAN (adminpagina's mogen alleen daarvandaan)
#   0 <tab> proxysite <tab> site <tab> off|paths|site <tab> /pad,/pad|- <tab> off|normal|strict <tab> 0|1 (slechte bots)
# Veilig doorvoeren:
#  - alleen eigen bestanden (rzsense-http.conf en de map rzsense/), plus één gemarkeerde regel (# rzsense-hook) in de
#    proxy die naar het eigen bestand verwijst (SWAG: ssl.conf, die in elke HTTPS-site zit; NPM: custom/http.conf en
#    custom/server_proxy.conf). Een kopie van alles wat verandert gaat eerst naar de cachemap;
#  - eerst "nginx -t" in de container; pas als dat goed is herladen; daarna kijken of de sites nog antwoorden. Antwoordt een
#    site die eerst werkte daarna niet meer (5xx of geen verbinding), dan gaat de vorige versie terug;
#  - "off" (of de rol uit in RzSense) haalt alles weer weg. Uit op de server: PROXY_GUARD=0 in /etc/default/rzmon-agent.
# Instelbaar in /etc/default/rzmon-agent: PROXY_CTR (welke container), PROXY_HTTPS_PORT (standaard 443), PROXY_CPATH (de
# configmap zoals de container hem ziet; standaard /config/nginx bij SWAG en /data bij NPM).
# Andere proxy's (Traefik, Caddy) worden herkend en gemeld als "nog niet ondersteund".
proxy_json=""
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ "${PROXY_GUARD:-1}" != 0 ] && [ -r "${t_cache-}" ] && grep -q $'^0\tproxy\t' "$t_cache"; then
    px_mode=$(awk -F'\t' '$1 == "0" && $2 == "proxy" { print $3; exit }' "$t_cache")
    px_kind=none; px_eng=""; px_ctr=""; px_root=""; px_cpath=""; px_state=pending; px_msg=""; px_sites=""; px_hook=0
    px_port=443; [[ "${PROXY_HTTPS_PORT-}" =~ ^[0-9]{1,5}$ ]] && px_port=$PROXY_HTTPS_PORT   # waar de sites op deze server antwoorden (controle na het herladen)
    px_find() {  # px_find <image-patroon> → "engine naam" van de eerste draaiende container (eerst podman, dan docker)
        local e out
        for e in podman docker; do
            command -v "$e" >/dev/null 2>&1 || continue
            out=$(timeout 15 "$e" ps --format '{{.Names}} {{.Image}}' 2>/dev/null | awk -v p="$1" 'tolower($2) ~ p { print $1; exit }')
            [ -n "$out" ] && { echo "$e $out"; return 0; }
        done
        return 1
    }
    px_mount() {  # px_mount <engine> <container> <pad in de container> → map op de host
        timeout 15 "$1" inspect -f '{{range .Mounts}}{{.Destination}} {{.Source}}{{"\n"}}{{end}}' "$2" 2>/dev/null | awk -v d="$3" '$1 == d { print $2; exit }'
    }
    px_env() {  # px_env <engine> <container> <naam> → waarde van een omgevingsvariabele van de container
        timeout 15 "$1" inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$2" 2>/dev/null | sed -n "s/^$3=//p" | head -n 1
    }
    # 1. Welke proxy? (PROXY_CTR in /etc/default/rzmon-agent wijst er zelf een aan)
    if [ -n "${PROXY_CTR-}" ] && [[ "$PROXY_CTR" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$ ]]; then
        for e in podman docker; do command -v "$e" >/dev/null 2>&1 && timeout 15 "$e" inspect "$PROXY_CTR" >/dev/null 2>&1 && { px_eng=$e; px_ctr=$PROXY_CTR; break; }; done
        if [ -n "$px_ctr" ]; then
            if [ -n "$(px_mount "$px_eng" "$px_ctr" /config)" ]; then px_kind=swag; else px_kind=npm; fi
        fi
    elif r=$(px_find 'swag|letsencrypt'); then px_kind=swag; px_eng=${r%% *}; px_ctr=${r#* }
    elif r=$(px_find 'nginx-proxy-manager|jc21/'); then px_kind=npm; px_eng=${r%% *}; px_ctr=${r#* }
    elif r=$(px_find 'traefik'); then px_kind=traefik; px_eng=${r%% *}; px_ctr=${r#* }
    elif r=$(px_find 'caddy'); then px_kind=caddy; px_eng=${r%% *}; px_ctr=${r#* }
    fi
    case "$px_kind" in
        swag) m=$(px_mount "$px_eng" "$px_ctr" /config); [ -n "$m" ] && px_root="$m/nginx"; px_cpath="${PROXY_CPATH:-/config/nginx}" ;;
        npm) m=$(px_mount "$px_eng" "$px_ctr" /data); [ -n "$m" ] && px_root="$m"; px_cpath="${PROXY_CPATH:-/data}" ;;
    esac
    if [ "$px_kind" = none ]; then
        px_state=unsupported; px_msg="geen reverse proxy gevonden (SWAG of Nginx Proxy Manager in Podman of Docker)"
    elif [ "$px_kind" = traefik ] || [ "$px_kind" = caddy ]; then
        px_state=unsupported; px_msg="$px_kind wordt herkend maar nog niet ondersteund"
    elif [ -z "$px_root" ] || [ ! -d "$px_root" ]; then
        px_state=error; px_msg="de configmap van $px_ctr is niet gevonden op deze server"
    fi
    # 2. Sites uitlezen: naam (server_name), alle namen, de app erachter en het bestand
    if [ -n "$px_root" ] && [ -d "$px_root" ]; then
        px_url=""; [ "$px_kind" = swag ] && px_url=$(px_env "$px_eng" "$px_ctr" URL | tr -dc 'A-Za-z0-9.-' | tr 'A-Z' 'a-z')
        if [ "$px_kind" = swag ]; then px_glob=("$px_root"/proxy-confs/*.conf "$px_root"/site-confs/*.conf)
        else px_glob=("$px_root"/nginx/proxy_host/*.conf); fi
        px_n=0; declare -A px_alias=()
        for f in "${px_glob[@]}"; do
            [ -f "$f" ] || continue
            case "${f##*/}" in *.sample|rzsense-*) continue ;; esac
            names=$(sed -n 's/^[[:space:]]*server_name[[:space:]]\+\([^;]*\);.*/\1/p' "$f" | tr ' ' '\n' | tr 'A-Z' 'a-z' \
                | grep -E '^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$' | sort -u | head -n 10)
            [ -n "$names" ] || continue
            if [ "$px_kind" = swag ]; then
                up=$(sed -n 's/^[[:space:]]*set[[:space:]]\+\$upstream_app[[:space:]]\+\([^;]*\);.*/\1/p' "$f" | head -n 1)
                up+=":$(sed -n 's/^[[:space:]]*set[[:space:]]\+\$upstream_port[[:space:]]\+\([^;]*\);.*/\1/p' "$f" | head -n 1)"
            else
                up=$(sed -n 's/^[[:space:]]*set[[:space:]]\+\$server[[:space:]]\+"\?\([^";]*\)"\?;.*/\1/p' "$f" | head -n 1)
                up+=":$(sed -n 's/^[[:space:]]*set[[:space:]]\+\$port[[:space:]]\+\([^;]*\);.*/\1/p' "$f" | head -n 1)"
            fi
            [ "$up" = ":" ] && up=""
            first=$(head -n 1 <<<"$names")
            px_alias[$first]=$(tr '\n' ' ' <<<"$names")
            px_sites+="{\"name\":\"$(json_escape "$first")\",\"names\":[$(awk '{ printf "%s\"%s\"", (n++ ? "," : ""), $0 }' <<<"$names")],\"up\":\"$(json_escape "${up:0:100}")\",\"file\":\"$(json_escape "${f##*/}")\"},"
            px_n=$((px_n + 1)); [ "$px_n" -ge 200 ] && break
        done
    fi
    # Bestanden van RzSense en de haakjes in de proxy
    if [ "$px_kind" = swag ]; then
        px_http="$px_root/site-confs/rzsense-http.conf"; px_srv="$px_root/rzsense/server.conf"
        px_hooks=("$px_root/ssl.conf:server")
    else
        px_http="$px_root/rzsense/http.conf"; px_srv="$px_root/rzsense/server.conf"
        px_hooks=("$px_root/nginx/custom/http.conf:http" "$px_root/nginx/custom/server_proxy.conf:server")
    fi
    px_hook_line() {  # px_hook_line http|server → de include-regel (pad zoals de container het ziet)
        if [ "$px_kind" = swag ]; then echo "include $px_cpath/rzsense/server.conf; # rzsense-hook"
        elif [ "$1" = http ]; then echo "include $px_cpath/rzsense/http.conf; # rzsense-hook"
        else echo "include $px_cpath/rzsense/server.conf; # rzsense-hook"; fi
    }
    px_hooked() { local h; for h in "${px_hooks[@]}"; do grep -q '# rzsense-hook' "${h%%:*}" 2>/dev/null || return 1; done; return 0; }
    px_hooked && px_hook=1
    px_exec() { timeout 30 "$px_eng" exec "$px_ctr" "$@"; }
    px_codes() {  # per site met een vaste naam (of naam.* met URL): HTTP-code via 127.0.0.1:443 → "naam code" per regel
        local n h code
        while IFS= read -r n; do
            h=$n
            if [[ "$h" == *'.*' ]]; then [ -n "$px_url" ] || continue; h="${h%.\*}.$px_url"; fi
            [[ "$h" == '*.'* ]] && continue
            code=$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 5 --resolve "$h:$px_port:127.0.0.1" "https://$h:$px_port/" 2>/dev/null)
            echo "$n ${code:-000}"
        done < <(grep -o '"name":"[^"]*"' <<<"$px_sites" | cut -d'"' -f4 | head -n 15)
    }
    px_backup() {  # kopie van alles wat verandert (bestaat het niet, dan een leeg merkteken)
        local b="$t_dir/proxy.bak" f h
        rm -rf "$b"; mkdir -p "$b"; chmod 700 "$b"
        for f in "$px_http" "$px_srv" "${px_hooks[@]%%:*}"; do
            h="$b/$(printf '%s' "$f" | md5sum | cut -c1-16)"
            if [ -f "$f" ]; then cp -p -- "$f" "$h"; else : >"$h.absent"; fi
            printf '%s\n' "$f" >>"$b/list"
        done
    }
    px_restore() {
        local b="$t_dir/proxy.bak" f h
        [ -s "$b/list" ] || return 1
        while IFS= read -r f; do
            h="$b/$(printf '%s' "$f" | md5sum | cut -c1-16)"
            if [ -f "$h.absent" ]; then rm -f -- "$f"; elif [ -f "$h" ]; then cat "$h" >"$f"; fi   # cat: eigenaar en rechten blijven
        done <"$b/list"
    }
    px_write() {  # px_write <bestand> <inhoud>: schrijven zonder de eigenaar van een bestaand bestand te veranderen
        mkdir -p "$(dirname "$1")"
        if [ -f "$1" ]; then printf '%s' "$2" >"$1"; else (umask 022; printf '%s' "$2" >"$1"); fi
    }
    px_unhook() {  # de gemarkeerde regels weghalen (de rest van het bestand blijft precies zoals het was)
        local h f
        for h in "${px_hooks[@]}"; do
            f=${h%%:*}
            [ -f "$f" ] && grep -q '# rzsense-hook' "$f" && px_write "$f" "$(grep -v '# rzsense-hook' "$f")"$'\n'
        done
    }
    px_test_reload() {  # 0 = goed en herladen; anders de fout in px_msg
        local out
        if ! out=$(px_exec nginx -t 2>&1); then px_msg="nginx -t: $(tail -n 3 <<<"$out" | tr '\n' ' ' | cut -c1-300)"; return 1; fi
        if ! out=$(px_exec nginx -s reload 2>&1); then px_msg="herladen mislukt: $(tail -n 2 <<<"$out" | tr '\n' ' ' | cut -c1-200)"; return 1; fi
        sleep 2
        return 0
    }
    px_apply_hash=$(awk -F'\t' '$1 == "0" && ($2 == "proxylan" || $2 == "proxysite")' "$t_cache" | sha1sum | cut -c1-12)
    px_applied=$(cat "$t_dir/proxy.applied" 2>/dev/null)
    # 3a. Uit: alles weghalen
    if [ "$px_state" = pending ] && [ "$px_mode" != on ]; then
        if [ "$px_hook" = 1 ] || [ -f "$px_http" ] || [ -f "$px_srv" ]; then
            px_backup; px_unhook; rm -f -- "$px_http" "$px_srv"; rmdir "$px_root/rzsense" 2>/dev/null
            if px_test_reload; then
                px_hook=0; px_state=off; px_msg="bescherming weggehaald"; rm -f "$t_dir/proxy.applied"
                act_log proxy 1 "Reverse proxy: bescherming uit" "$px_kind $px_ctr"
            else
                px_restore; px_exec nginx -s reload >/dev/null 2>&1; px_state=error
                act_log proxy 0 "Reverse proxy: uitzetten mislukt" "$px_msg"
            fi
        else
            px_state=off; px_msg=""
        fi
    # 3b. Aan: doorvoeren als het beleid veranderde of iets ontbreekt (een mislukte versie niet elke minuut opnieuw)
    elif [ "$px_state" = pending ]; then
        px_failed=$(cat "$t_dir/proxy.failed" 2>/dev/null)
        if [ "$px_applied" = "$px_apply_hash" ] && [ "$px_hook" = 1 ] && [ -f "$px_http" ] && [ -f "$px_srv" ]; then
            px_state=ok; px_msg="doorgevoerd"
        elif [ "${px_failed%% *}" = "$px_apply_hash" ] && [ $(( $(date +%s) - ${px_failed##* } )) -lt 3600 ]; then
            px_state=error; px_msg=$(cat "$t_dir/proxy.failmsg" 2>/dev/null)
        else
            # Beleid opbouwen (alles wat binnenkomt opnieuw controleren)
            px_geo=""; px_hosts=""; px_adm=""; px_bots=""; px_rate=""; px_id=0; declare -A px_seen=()
            for n in $(awk -F'\t' '$1 == "0" && $2 == "proxylan" { print $3; exit }' "$t_cache"); do
                [[ "$n" =~ ^[0-9a-fA-F:.]+(/[0-9]{1,3})?$ ]] && px_geo+="    $n 1;"$'\n'
            done
            while IFS=$'\t' read -r _ _ s_name s_mode s_paths s_rate s_bots; do
                [[ "$s_name" =~ ^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$ ]] || continue
                [[ "$s_mode" =~ ^(off|paths|site)$ ]] && [[ "$s_rate" =~ ^(off|normal|strict)$ ]] && [[ "$s_bots" =~ ^[01]$ ]] || continue
                px_id=$((px_id + 1)); sid="s$px_id"
                # Alle namen van de site (server_name), elke naam maar één keer (anders weigert nginx de map)
                for a in ${px_alias[$s_name]:-$s_name}; do
                    [ -n "${px_seen[$a]-}" ] && continue; px_seen[$a]=1
                    px_hosts+="    $a $sid;"$'\n'
                done
                if [ "$s_mode" = site ]; then
                    px_adm+="    \"~^$sid:\" 1;"$'\n'
                elif [ "$s_mode" = paths ] && [ "$s_paths" != - ]; then
                    IFS=',' read -r -a s_list <<<"$s_paths"
                    for p in "${s_list[@]}"; do
                        [[ "$p" =~ ^/[A-Za-z0-9._~/-]{0,100}$ ]] && [[ "$p" != *..* ]] || continue
                        px_adm+="    \"~*^$sid:$(sed 's/[.~]/\\&/g' <<<"${p%/}")(/|\$)\" 1;"$'\n'
                    done
                fi
                [ "$s_bots" = 1 ] && px_bots+="    $sid 1;"$'\n'
                [ "$s_rate" != off ] && px_rate+="    $sid $s_rate;"$'\n'
            done < <(awk -F'\t' '$1 == "0" && $2 == "proxysite"' "$t_cache" | head -n 200)
            px_conf_http="# RzSense: reverse proxy beschermen. Beheerd door de RzSense-agent; wijzigingen hier worden overschreven.
geo \$rzs_lan {
    default 0;
    127.0.0.1 1;
    ::1 1;
${px_geo}}
map \$host \$rzs_site {
    hostnames;
    default \"\";
${px_hosts}}
map \"\$rzs_site:\$uri\" \$rzs_adm {
    default 0;
${px_adm}}
map \"\$rzs_adm:\$rzs_lan\" \$rzs_deny_adm {
    default 0;
    \"1:0\" 1;
}
map \$http_user_agent \$rzs_badbot {
    default 0;
    \"~*(sqlmap|nikto|nuclei|zgrab|masscan|nmap scripting|dirbuster|gobuster|feroxbuster|wpscan|acunetix|netsparker|havij|zmeu|jorgee|fimap|w3af|openvas|nessus)\" 1;
}
map \$rzs_site \$rzs_bots_on {
    default 0;
${px_bots}}
map \"\$rzs_badbot:\$rzs_bots_on:\$rzs_lan\" \$rzs_deny_bot {
    default 0;
    \"1:1:0\" 1;
}
map \$rzs_site \$rzs_rate {
    default \"\";
${px_rate}}
map \"\$rzs_rate:\$rzs_lan\" \$rzs_key_n {
    default \"\";
    \"normal:0\" \$binary_remote_addr;
}
map \"\$rzs_rate:\$rzs_lan\" \$rzs_key_s {
    default \"\";
    \"strict:0\" \$binary_remote_addr;
}
limit_req_zone \$rzs_key_n zone=rzs_normal:10m rate=20r/s;
limit_req_zone \$rzs_key_s zone=rzs_strict:10m rate=5r/s;
"
            px_conf_srv="# RzSense: reverse proxy beschermen (per site). Beheerd door de RzSense-agent.
if (\$rzs_deny_adm) { return 403; }
if (\$rzs_deny_bot) { return 403; }
limit_req zone=rzs_normal burst=40 nodelay;
limit_req zone=rzs_strict burst=20 nodelay;
limit_req_status 429;
"
            px_before=$(px_codes)
            px_backup
            px_write "$px_http" "$px_conf_http"
            px_write "$px_srv" "$px_conf_srv"
            for h in "${px_hooks[@]}"; do
                f=${h%%:*}
                grep -q '# rzsense-hook' "$f" 2>/dev/null && continue
                if [ -f "$f" ]; then px_write "$f" "$(cat "$f")"$'\n'"$(px_hook_line "${h##*:}")"$'\n'
                else px_write "$f" "$(px_hook_line "${h##*:}")"$'\n'; fi
            done
            if px_test_reload; then
                # Antwoordt een site die eerst werkte nu niet meer? Dan terug.
                px_after=$(px_codes); px_broken=""
                while read -r n c; do
                    [ -n "$n" ] || continue
                    a=$(awk -v n="$n" '$1 == n { print $2; exit }' <<<"$px_after")
                    if [[ "$c" =~ ^[1-4] ]] && [[ ! "${a:-000}" =~ ^[1-4] ]]; then px_broken+="$n ($c → ${a:-000}) "; fi
                done <<<"$px_before"
                if [ -n "$px_broken" ]; then
                    px_restore; px_exec nginx -s reload >/dev/null 2>&1
                    px_state=error; px_msg="teruggezet: deze sites antwoordden daarna niet meer: $px_broken"
                else
                    px_state=ok; px_msg="doorgevoerd ($px_id sites)"; px_hook=1
                    echo "$px_apply_hash" >"$t_dir/proxy.applied"; rm -f "$t_dir/proxy.failed" "$t_dir/proxy.failmsg"
                    act_log proxy 1 "Reverse proxy: beleid doorgevoerd" "$px_kind $px_ctr, $px_id sites"
                fi
            else
                px_restore; px_state=error
            fi
            if [ "$px_state" = error ]; then
                echo "$px_apply_hash $(date +%s)" >"$t_dir/proxy.failed"; printf '%s' "$px_msg" >"$t_dir/proxy.failmsg"
                act_log proxy 0 "Reverse proxy: doorvoeren mislukt" "$px_msg"
                px_hooked && px_hook=1 || px_hook=0
            fi
        fi
    fi
    proxy_json="{\"kind\":\"$px_kind\",\"engine\":\"$px_eng\",\"ctr\":\"$(json_escape "$px_ctr")\",\"conf\":\"$(json_escape "$px_root")\",\"hook\":$px_hook,\"state\":\"$px_state\",\"msg\":\"$(json_escape "$px_msg")\",\"applied\":\"$(cat "$t_dir/proxy.applied" 2>/dev/null | tr -dc 'a-f0-9')\",\"sites\":[${px_sites%,}]}"
fi

# ---------------------------------------------------------------- WAF op de reverse proxy (agent 1.98.0, RzSense 1.255.0)
# CrowdSec AppSec met de OWASP Core Rule Set, als eigen container (rzsense-crowdsec) op een eigen netwerk (rzsense) waar ook
# SWAG aan hangt. SWAG vraagt via de mod swag-crowdsec bij elk verzoek of het mag. Regels uit RzSense:
#   0 <tab> waf <tab> on|off <tab> detect|sure|strict <tab> 0|1 (delen met de CrowdSec-community)
#   0 <tab> wafsite <tab> site <tab> detect|off
#   0 <tab> wafex <tab> site <tab> regel (CRS-id of naam)
# De agent schrijft alleen eigen bestanden in /var/lib/rzsense-crowdsec (de container leest ze), maakt de sleutel voor SWAG
# (in de configmap van SWAG, rzsense-waf/crowdsec.key, alleen voor root), en test na elke wijziging of de WAF doet wat de
# slider zegt (een nep-aanval vanaf 203.0.113.10 naar de WAF zelf). Uit: de container weg; je SWAG blijft gewoon werken.
waf_json=""
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ "${PROXY_GUARD:-1}" != 0 ] && [ -r "${t_cache-}" ] && grep -q $'^0\twaf\t' "$t_cache"; then
    IFS=$'\t' read -r wf_on wf_mode wf_share < <(awk -F'\t' '$1 == "0" && $2 == "waf" { print $3 "\t" $4 "\t" $5; exit }' "$t_cache")
    [[ "$wf_mode" =~ ^(detect|sure|strict)$ ]] || wf_mode=detect
    wf_ctr=rzsense-crowdsec; wf_net=rzsense; wf_dir="${WAF_DIR:-/var/lib/rzsense-crowdsec}"
    wf_img="${WAF_IMAGE:-docker.io/crowdsecurity/crowdsec:v1.6.4}"
    wf_state=error; wf_msg=""; wf_test=skip; wf_testmsg=""; wf_bouncer=none; wf_running=0; wf_alerts=""
    wf_eng=${px_eng-}
    if [ -z "$wf_eng" ]; then
        for e in podman docker; do command -v "$e" >/dev/null 2>&1 && timeout 15 "$e" inspect "$wf_ctr" >/dev/null 2>&1 && { wf_eng=$e; break; }; done
    fi
    wfx() { timeout "${WFX_T:-60}" "$wf_eng" "$@"; }
    wf_exists() { [ -n "$wf_eng" ] && wfx inspect "$wf_ctr" >/dev/null 2>&1; }
    if [ "$wf_on" != on ]; then
        if wf_exists; then
            wfx rm -f "$wf_ctr" >/dev/null 2>&1
            act_log proxy 1 "WAF uit" "container $wf_ctr weggehaald"
        fi
        rm -f "$wf_dir/env.hash" "$wf_dir/conf.hash"
        wf_state=off
    elif [ "${px_kind-}" != swag ] || [ -z "${px_eng-}" ] || [ -z "${px_root-}" ]; then
        wf_state=unsupported; wf_msg="de WAF werkt nu alleen met SWAG (gevonden: ${px_kind:-geen proxy})"
    else
        mkdir -p "$wf_dir/acquis.d" "$wf_dir/data" "$px_root/rzsense-waf"; chmod 700 "$wf_dir" "$px_root/rzsense-waf"
        # 1. Sleutel voor SWAG (de bouncer), alleen voor root leesbaar
        wf_keyf="$px_root/rzsense-waf/crowdsec.key"
        if [ ! -s "$wf_keyf" ]; then
            (umask 077; head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' >"$wf_keyf")
            act_log proxy 1 "WAF: sleutel voor SWAG gemaakt" "$px_root/rzsense-waf/crowdsec.key"
        fi
        wf_key=$(tr -dc 'a-f0-9' <"$wf_keyf" | head -c 64)
        # 2. Configuratie (alles opnieuw opgebouwd uit de regels, met controle van wat binnenkomt)
        wf_lan=""
        for n in $(awk -F'\t' '$1 == "0" && $2 == "proxylan" { print $3; exit }' "$t_cache") 127.0.0.1/32; do
            [[ "$n" =~ ^[0-9a-fA-F:.]+(/[0-9]{1,3})?$ ]] || continue
            [[ "$n" == */* ]] || n="$n/32"
            wf_lan+="${wf_lan:+ || }IpInRange(req.RemoteAddr, \"$n\")"
        done
        wf_re() {  # sitenaam (naam, *.domein of app.*) → regex op de Host, met dubbele backslashes voor YAML
            local s=$1 pre='^' suf='$' r
            if [[ "$s" == '*.'* ]]; then s=${s:2}; pre='\.'; fi
            if [[ "$s" == *'.*' ]]; then s=${s%.\*}; suf='\.'; fi
            s=$(sed 's/\./\\./g' <<<"$s")
            if [ "$pre" = '^' ]; then r="^$s$suf"; else r="$pre$s$suf"; fi
            printf '%s' "${r//\\/\\\\}"
        }
        wf_off=""; wf_det=""; wf_ex=""
        while IFS=$'\t' read -r _ _ s m; do
            [[ "$s" =~ ^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$ ]] || continue
            case "$m" in off) wf_off+="${wf_off:+|}$(wf_re "$s")" ;; detect) wf_det+="${wf_det:+|}$(wf_re "$s")" ;; esac
        done < <(awk -F'\t' '$1 == "0" && $2 == "wafsite"' "$t_cache" | head -n 200)
        while IFS=$'\t' read -r _ _ s r; do
            [[ "$s" =~ ^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*(\.\*)?$ ]] || continue
            if [[ "$r" =~ ^[0-9]{3,7}$ ]]; then a="RemoveInBandRuleByID($r)"$'\n'"      - RemoveOutBandRuleByID($r)"
            elif [[ "$r" =~ ^[a-z0-9_-]{1,40}/[A-Za-z0-9._-]{1,80}$ ]]; then a="RemoveInBandRuleByName(\"$r\")"$'\n'"      - RemoveOutBandRuleByName(\"$r\")"
            else continue; fi
            wf_ex+="  - filter: req.Host matches \"$(wf_re "$s")\""$'\n'"    apply:"$'\n'"      - $a"$'\n'
        done < <(awk -F'\t' '$1 == "0" && $2 == "wafex"' "$t_cache" | head -n 200)
        case "$wf_mode" in
            detect) wf_in="  - crowdsecurity/base-config"; wf_out="  - crowdsecurity/vpatch-*"$'\n'"  - crowdsecurity/crs" ;;
            sure) wf_in="  - crowdsecurity/base-config"$'\n'"  - crowdsecurity/vpatch-*"; wf_out="  - crowdsecurity/crs" ;;
            strict) wf_in="  - crowdsecurity/base-config"$'\n'"  - crowdsecurity/vpatch-*"$'\n'"  - crowdsecurity/crs"; wf_out="" ;;
        esac
        wf_conf="# RzSense: beheerd door de RzSense-agent (slider: $wf_mode). Wijzigingen hier worden overschreven.
name: rzsense/appsec
default_remediation: ban
inband_rules:
$wf_in
outofband_rules:${wf_out:+$'\n'$wf_out}${wf_out:- []}
${wf_ex:+pre_eval:$'\n'$wf_ex}on_match:
  - filter: $wf_lan
    apply:
      - SetRemediation(\"allow\")
      - CancelAlert()
      - CancelEvent()
${wf_off:+  - filter: req.Host matches \"($wf_off)\"
    apply:
      - SetRemediation(\"allow\")
      - CancelAlert()
      - CancelEvent()
}${wf_det:+  - filter: IsInBand && req.Host matches \"($wf_det)\"
    apply:
      - SetRemediation(\"allow\")
}"
        wf_acq="# RzSense: beheerd door de RzSense-agent
source: appsec
listen_addr: 0.0.0.0:7422
appsec_config: rzsense/appsec
name: rzsense-appsec
labels:
  type: appsec
"
        wf_wl="# RzSense: je eigen netwerk en RzSense nooit blokkeren
name: rzsense/whitelist
description: \"RzSense: eigen netwerk\"
whitelist:
  reason: \"RzSense: eigen netwerk\"
  cidr:
$(for n in $(awk -F'\t' '$1 == "0" && $2 == "proxylan" { print $3; exit }' "$t_cache") 127.0.0.1/32; do [[ "$n" =~ ^[0-9a-fA-F:.]+(/[0-9]{1,3})?$ ]] || continue; [[ "$n" == */* ]] || n="$n/32"; echo "    - \"$n\""; done)
"
        wf_write() { if [ -f "$1" ]; then printf '%s' "$2" >"$1"; else (umask 077; printf '%s' "$2" >"$1"); fi; }   # zelfde bestand (de container heeft het vast)
        wf_write "$wf_dir/rzsense.yaml" "$wf_conf"; wf_write "$wf_dir/acquis.d/rzsense-appsec.yaml" "$wf_acq"; wf_write "$wf_dir/whitelist.yaml" "$wf_wl"
        wf_chash=$(cat "$wf_dir/rzsense.yaml" "$wf_dir/acquis.d/rzsense-appsec.yaml" "$wf_dir/whitelist.yaml" | sha1sum | cut -c1-12)
        wf_ehash=$(printf '%s|%s|%s' "$wf_img" "$wf_share" "$wf_key" | sha1sum | cut -c1-12)
        # 3. Netwerk: rzsense, en SWAG eraan (ook opnieuw als SWAG opnieuw is aangemaakt)
        wfx network inspect "$wf_net" >/dev/null 2>&1 || wfx network create "$wf_net" >/dev/null 2>&1
        if ! wfx inspect -f '{{json .NetworkSettings.Networks}}' "$px_ctr" 2>/dev/null | grep -q "\"$wf_net\""; then
            if wfx network connect "$wf_net" "$px_ctr" >/dev/null 2>&1; then act_log proxy 1 "WAF: $px_ctr aan het netwerk $wf_net gehangen" ""
            else wf_msg="kon $px_ctr niet aan het netwerk $wf_net hangen"; fi
        fi
        # 4. Container: (opnieuw) aanmaken als hij er niet is of de omgeving veranderde; herstarten als alleen de regels veranderden
        if ! wf_exists || [ "$(cat "$wf_dir/env.hash" 2>/dev/null)" != "$wf_ehash" ]; then
            wfx rm -f "$wf_ctr" >/dev/null 2>&1
            wf_env=(-e "COLLECTIONS=crowdsecurity/appsec-virtual-patching crowdsecurity/appsec-generic-rules crowdsecurity/appsec-crs" -e "BOUNCER_KEY_swag=$wf_key")
            [ "$wf_share" = 1 ] || wf_env+=(-e "DISABLE_ONLINE_API=true")
            if out=$(WFX_T=600 wfx run -d --name "$wf_ctr" --network "$wf_net" --restart unless-stopped "${wf_env[@]}" \
                -v "$wf_dir/acquis.d:/etc/crowdsec/acquis.d:Z" -v "$wf_dir/rzsense.yaml:/etc/crowdsec/appsec-configs/rzsense.yaml:Z" \
                -v "$wf_dir/whitelist.yaml:/etc/crowdsec/parsers/s02-enrich/rzsense-whitelist.yaml:Z" -v "$wf_dir/data:/var/lib/crowdsec/data:Z" "$wf_img" 2>&1); then
                echo "$wf_ehash" >"$wf_dir/env.hash"; echo "$wf_chash" >"$wf_dir/conf.hash"
                act_log proxy 1 "WAF: CrowdSec gestart" "$wf_img, slider $wf_mode"
            else
                wf_msg="starten mislukt: $(tail -n 2 <<<"$out" | tr '\n' ' ' | cut -c1-200)"
            fi
        elif [ "$(cat "$wf_dir/conf.hash" 2>/dev/null)" != "$wf_chash" ]; then
            if wfx restart "$wf_ctr" >/dev/null 2>&1; then
                echo "$wf_chash" >"$wf_dir/conf.hash"; act_log proxy 1 "WAF: regels doorgevoerd" "slider $wf_mode"
            else wf_msg="herstarten mislukt"; fi
        fi
        # 5. Draait hij, antwoordt de WAF, is SWAG gekoppeld, en doet hij wat de slider zegt?
        [ "$(wfx inspect -f '{{.State.Running}}' "$wf_ctr" 2>/dev/null)" = true ] && wf_running=1
        wf_ask() {  # wf_ask <uri> → 200 (mag), 403 (tegengehouden) of 000 (geen antwoord)
            local o
            o=$(wfx exec "$wf_ctr" wget -q -S -O /dev/null --header "X-Crowdsec-Appsec-Ip: 203.0.113.10" --header "X-Crowdsec-Appsec-Uri: $1" \
                --header "X-Crowdsec-Appsec-Host: rzsense-selftest.invalid" --header "X-Crowdsec-Appsec-Verb: GET" \
                --header "X-Crowdsec-Appsec-Api-Key: $wf_key" http://127.0.0.1:7422/ 2>&1)
            if grep -q ' 200' <<<"$o"; then echo 200; elif grep -q ' 403' <<<"$o"; then echo 403; else echo 000; fi
        }
        if [ "$wf_running" = 1 ]; then
            wf_ok=$(wf_ask /)
            if [ "$wf_ok" = 000 ]; then
                wf_state=starting; wf_msg=${wf_msg:-"de WAF start nog (regels laden)"}
            else
                # Zelftest: alleen zeker = een bekend lek (CVE-2017-9841); streng = SQL-injectie; detecteren = SQL-injectie mag door
                case "$wf_mode" in
                    sure) wf_u='/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php'; wf_want=403 ;;
                    strict) wf_u='/?id=1%27%20OR%20%271%27%3D%271'; wf_want=403 ;;
                    *) wf_u='/?id=1%27%20OR%20%271%27%3D%271'; wf_want=200 ;;
                esac
                wf_got=$(wf_ask "$wf_u")
                if [ "$wf_got" = "$wf_want" ] && [ "$wf_ok" = 200 ]; then wf_test=ok; wf_testmsg="nep-aanval: $wf_got zoals verwacht"
                else wf_test=fail; wf_testmsg="nep-aanval gaf $wf_got, verwacht $wf_want (gewoon verzoek: $wf_ok)"; fi
                # SWAG gekoppeld? (de bouncer haalt elke paar seconden de lijst op)
                wf_bl=$(wfx exec "$wf_ctr" cscli bouncers list -o json 2>/dev/null)
                if grep -q '"name": *"swag"' <<<"$wf_bl"; then
                    wf_lp=$(tr -d '\n' <<<"$wf_bl" | grep -o '"name": *"swag"[^}]*' | grep -o '"last_pull": *"[^"]*"' | cut -d'"' -f4)
                    if [ -n "$wf_lp" ] && [ "${wf_lp:0:4}" != 0001 ]; then wf_bouncer=active; else wf_bouncer=waiting; fi
                fi
                if [ "$wf_bouncer" = active ]; then wf_state=ok; wf_msg=${wf_msg:-"actief, slider $wf_mode"}
                else wf_state=waiting; wf_msg=${wf_msg:-"draait; zet de koppeling aan in SWAG (zie RzSense)"}; fi
                # Nieuwe meldingen (RzSense slaat elke melding één keer op)
                wf_alerts=$(wfx exec "$wf_ctr" cscli alerts list -o json -l 100 --since 20m 2>/dev/null | head -c 300000 | base64 | tr -d '\n')
            fi
        else
            wf_msg=${wf_msg:-"de container draait niet"}
        fi
    fi
    waf_json="{\"state\":\"$wf_state\",\"running\":$wf_running,\"bouncer\":\"$wf_bouncer\",\"net\":\"$wf_net\",\"image\":\"$(json_escape "$wf_img")\",\"mode\":\"$wf_mode\",\"test\":\"$wf_test\",\"test_msg\":\"$(json_escape "$wf_testmsg")\",\"msg\":\"$(json_escape "$wf_msg")\",\"alerts\":\"$wf_alerts\"}"
fi
