# ---------------------------------------------------------------- Certificaten
lap certs
# Per bron: onderwerp (CN), uitgever, vervaldatum (epoch) en of het certificaat vertrouwd wordt.
# RzSense rekent zelf het aantal dagen uit, dus een uur oude gegevens zijn geen probleem.
cert_line() {  # bron cn uitgever not_after verify fout [aantal in de keten, eerste vervaldatum in de keten, cn daarvan]
    printf '{"source":"%s","cn":"%s","issuer":"%s","not_after":%s,"verify":%s,"error":"%s","chain_n":%s,"chain_min":%s,"chain_cn":"%s"},' \
        "$(json_escape "$1")" "$(json_escape "$2")" "$(json_escape "$3")" "${4:-null}" \
        "$( [ -n "$5" ] && printf '"%s"' "$(json_escape "$5")" || echo null)" "$(json_escape "$6")" "${7:-0}" "${8:-null}" "$(json_escape "${9-}")"
}
# Alle certificaten in een PEM-tekst (stdin; fullchain of de keten van een server, 1.59.0): chain_n, en welk
# certificaat het eerst verloopt (chain_min, chain_cn). Zo valt ook een verlopen tussencertificaat op.
chain_info() {
    local tmp blk na
    chain_n=0; chain_min=""; chain_cn=""
    tmp=$(mktemp -d 2>/dev/null) || { cat >/dev/null; return 0; }
    awk -v d="$tmp" '/-----BEGIN CERTIFICATE-----/ { n++; f = d "/c" n } f { print > f } /-----END CERTIFICATE-----/ { if (f) close(f); f = ""; if (n >= 30) exit }'
    for blk in "$tmp"/c*; do
        [ -f "$blk" ] || continue
        na=$(openssl x509 -noout -enddate 2>/dev/null <"$blk" | sed -n 's/^notAfter=//p')
        na=$(date -d "$na" +%s 2>/dev/null) || continue
        chain_n=$((chain_n + 1))
        if [ -z "$chain_min" ] || [ "$na" -lt "$chain_min" ]; then
            chain_min=$na
            chain_cn=$(openssl x509 -noout -subject -nameopt RFC2253 2>/dev/null <"$blk" | grep -o 'CN=[^,]*' | head -1 | cut -c4-)
        fi
    done
    rm -rf "$tmp"
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
    for entry in $cert_list; do
        if [[ "$entry" == /* ]]; then
            # Bestand(en); een wildcard zonder treffers is geen fout (bv. geen Let's Encrypt)
            if [[ "$entry" == *[*?[]* ]]; then
                compgen -G "$entry" >/dev/null || continue
            fi
            for f in $entry; do
                if [ ! -r "$f" ]; then
                    cert_line "$f" "" "" "" "" "bestand niet gevonden of niet leesbaar"
                elif parse_cert <"$f"; then
                    chain_info <"$f"
                    cert_line "$f" "$cn" "$issuer" "$not_after" "" "" "$chain_n" "$chain_min" "$chain_cn"
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
            out=$(timeout 15 openssl s_client -connect "$src" -servername "$host" -verify_hostname "$host" -showcerts </dev/null 2>&1)
            rc=$?
            if parse_cert <<<"$out"; then
                code=$(sed -n 's/^ *Verify return code: \([0-9]*\) (\(.*\))/\1 \2/p' <<<"$out" | tail -1)
                [ "${code%% *}" = 0 ] && code=ok || code=${code#* }
                chain_info <<<"$out"
                cert_line "$src" "$cn" "$issuer" "$not_after" "${code:-onbekend}" "" "$chain_n" "$chain_min" "$chain_cn"
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
# Te controleren: CERTS van deze server, plus wat je in RzSense gekozen hebt (regels "0 <tab> cert <tab> bron", 1.59.0)
cert_list="$CERTS"
[ -r "${t_cache-}" ] && cert_list="$cert_list $(awk -F'\t' '$1 == "0" && $2 == "cert" && $3 !~ /[[:space:]]/ { print $3 }' "$t_cache" | head -200 | tr '\n' ' ')"
cert_list=$(tr -s ' ' <<<"$cert_list"); cert_list=${cert_list# }; cert_list=${cert_list% }
if [ -n "$cert_list" ]; then
    if ! command -v openssl >/dev/null 2>&1; then
        certs=$(cert_line "openssl" "" "" "" "" "openssl ontbreekt op deze server (installeer het pakket openssl)")
    else
        # Vaste cachemap (systemd: CacheDirectory); /tmp is bij systemd per run leeg (PrivateTmp)
        cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
        mkdir -p "$cache_dir" 2>/dev/null && [ -w "$cache_dir" ] || cache_dir="${TMPDIR:-/tmp}"
        cache="$cache_dir/certs.cache"
        key=$(printf '%s' "$cert_list" | cksum | cut -d' ' -f1)
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

# ---------------------------------------------------------------- Certificaten zoeken (1.59.0)
lap cert_search
# Bestanden met een certificaat in de mappen van CERT_DIRS (plus de mappen die je in RzSense opgeeft), om in RzSense
# uit te kiezen. Eén keer per dag, of meteen als RzSense erom vraagt (regel "0 <tab> certscan <tab> teller").
# Overgeslagen: de CA-lijsten van het systeem en van programmeertalen (Python, Ruby), testmappen, CA-bundels (meer dan
# 20 certificaten) en sleutels zonder certificaat.
cert_found=""
if [ "$TEST" != 1 ] && command -v openssl >/dev/null 2>&1; then
    cs_dirs="$CERT_DIRS"
    [ -r "${t_cache-}" ] && cs_dirs="$cs_dirs $(awk -F'\t' '$1 == "0" && $2 == "certdirs" { print $3; exit }' "$t_cache")"
    cs_req=$( [ -r "${t_cache-}" ] && awk -F'\t' '$1 == "0" && $2 == "certscan" { print $3; exit }' "$t_cache")
    cs_cache="${cache_dir:-${CACHE_DIRECTORY:-/var/cache/rzmon-agent}}/certfound.cache"
    mkdir -p "${cs_cache%/*}" 2>/dev/null
    cs_key=$(printf '%s|%s' "$cs_dirs" "$cs_req" | cksum | cut -d' ' -f1)
    if [ -r "$cs_cache" ] && [ "$(sed -n 1p "$cs_cache")" = "$cs_key" ] && [ $(( $(date +%s) - $(sed -n 2p "$cs_cache") )) -lt 86400 ]; then
        cert_found=$(sed -n 3p "$cs_cache")
    elif [ -n "${cs_dirs// /}" ]; then
        cs_ex=()
        for d in $cs_dirs; do [[ "$d" == /* && "$d" != *..* && -d "$d" ]] && cs_ex+=("$d"); done
        if [ "${#cs_ex[@]}" -gt 0 ]; then
            cs_n=0
            while IFS= read -r -d '' f; do
                [ -f "$f" ] && [ -r "$f" ] || continue
                grep -q -- '-----BEGIN CERTIFICATE-----' "$f" 2>/dev/null || continue
                [ "$(grep -c -- '-----BEGIN CERTIFICATE-----' "$f")" -le 20 ] || continue   # CA-bundel
                parse_cert <"$f" || continue
                chain_info <"$f"
                cert_found+=$(printf '{"path":"%s","cn":"%s","issuer":"%s","not_after":%s,"n":%s,"chain_min":%s},' "$(json_escape "$f")" "$(json_escape "$cn")" \
                    "$(json_escape "$issuer")" "$not_after" "$chain_n" "${chain_min:-null}")
                cs_n=$((cs_n + 1)); [ "$cs_n" -ge 300 ] && break
            done < <(timeout 120 find "${cs_ex[@]}" -xdev -maxdepth 8 \( -path /etc/ssl/certs -o -path /etc/pki/ca-trust -o -path /etc/letsencrypt/archive \
                -o -path '*/ca-certificates' -o -path /etc/pki/tls/certs/ca-bundle.crt -o -name node_modules -o -name .git -o -name site-packages \
                -o -name dist-packages -o -name ssl_certs -o -name certifi -o -iname 'cacert.pem' -o -iname '*ca-bundle*' -o -name test -o -name tests \) -prune \
                -o \( -type f -o -type l \) \( -iname '*.crt' -o -iname '*.pem' -o -iname '*.cer' -o -iname '*.cert' \) -size -200k -print0 2>/dev/null)
        fi
        (umask 077; printf '%s\n%s\n%s\n' "$cs_key" "$(date +%s)" "$cert_found" >"$cs_cache") 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------- Processen (1.59.0)
lap procs
# Proces-checks uit RzSense: "id <tab> proc <tab> name|pattern <tab> naam of patroon (base64) <tab> gebruiker".
# Per check: aantal, PID's, CPU (%), geheugen (kB) en wanneer het oudste proces startte (om een herstart te zien).
# Plus een lijst van wat er draait (zonder kernelprocessen), om in RzSense uit te kiezen.
procs_json=""; proc_list=""
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ]; then
    # LC_ALL=C (1.59.2): punt als decimaalteken en alleen ASCII (andere tekens als ?), zodat de JSON altijd klopt
    ps_raw=$(LC_ALL=C ps -eo pid=,ppid=,user:32=,etimes=,pcpu=,rss=,comm=,args= 2>/dev/null)
    if [ -n "$ps_raw" ]; then
        now_ts=$(date +%s)
        proc_list=$(LC_ALL=C awk -v self=$$ '
            function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); gsub(/\t/, " ", s); return s }
            $2 == 2 || $1 == 2 || $1 == self || $2 == self { next }
            { a = ""; for (i = 8; i <= NF; i++) a = a (i > 8 ? " " : "") $i
              if (a ~ /^\[/) next
              n[$7]++; if (!($7 in ex)) { ex[$7] = substr(a, 1, 120); us[$7] = $3 } }
            END { c = 0; for (k in n) { if (c++ >= 200) break; printf "[\"%s\",%d,\"%s\",\"%s\"],", esc(k), n[k], esc(us[k]), esc(ex[k]) } }' <<<"$ps_raw")
        if [ -r "${t_cache-}" ] && grep -q $'^[0-9]*\tproc\t' "$t_cache"; then
            while IFS=$'\t' read -r p_id p_type p_mode p_val p_user; do
                [ "$p_type" = proc ] && [[ "$p_id" =~ ^[0-9]+$ ]] || continue
                p_val=$(printf '%s' "$p_val" | base64 -d 2>/dev/null) || continue
                [ -n "$p_val" ] || continue
                # Het patroon via de omgeving: awk -v zou de backslashes in een regex opeten
                procs_json+=$(P_VAL="$p_val" LC_ALL=C awk -v id="$p_id" -v mode="$p_mode" -v usr="$p_user" -v self=$$ -v now="$now_ts" '
                    BEGIN { val = ENVIRON["P_VAL"] }
                    $1 == self || $2 == self || $2 == 2 { next }
                    { a = ""; for (i = 8; i <= NF; i++) a = a (i > 8 ? " " : "") $i
                      if (a ~ /^\[/) next
                      if (usr != "" && $3 != usr) next
                      if (mode == "pattern") { if (a !~ val) next }
                      else { b = $8; sub(/.*\//, "", b); sub(/:$/, "", b); if ($7 != val && b != val && substr(val, 1, 15) != $7) next }
                      n++; pids = pids (pids == "" ? "" : " ") $1; cpu += $5; rss += $6; if ($4 > age) age = $4 }
                    END { printf "\"%s\":{\"n\":%d,\"pids\":\"%s\",\"cpu\":%.1f,\"rss\":%d,\"start\":%s},", id, n, substr(pids, 1, 200), cpu, rss, n ? now - age : "null" }' <<<"$ps_raw")
            done <"$t_cache"
        fi
    fi
fi

# ---------------------------------------------------------------- Software (Kwetsbaarheden in RzSense)
lap software
# Geïnstalleerde pakketten met de distributie: RzSense zoekt er de bekende kwetsbaarheden (CVE's) bij.
# De hele lijst één keer per dag, of eerder als de pakketdatabase verandert; anders alleen het controlegetal.
pkgs_json=""; pk_hash=""; pk_mgr=""
pk_dir="$t_dir/pkgs"
if [ "$HA_MODE" = 0 ] && [ "$TEST" != 1 ] && mkdir -p "$pk_dir" 2>/dev/null && [ -w "$pk_dir" ]; then
    pk_db=""
    if command -v dpkg-query >/dev/null 2>&1 && [ -f /var/lib/dpkg/status ]; then
        pk_mgr=dpkg; pk_db=/var/lib/dpkg/status
    elif command -v rpm >/dev/null 2>&1; then
        pk_mgr=rpm
        for f in /var/lib/rpm/rpmdb.sqlite /var/lib/rpm/Packages /usr/lib/sysimage/rpm/rpmdb.sqlite; do [ -f "$f" ] && pk_db=$f && break; done
    fi
    pk_list="$pk_dir/list.json"
    if [ -n "$pk_mgr" ] && { [ ! -s "$pk_list" ] || [ -f "$pk_dir/resend" ] || { [ -n "$pk_db" ] && [ "$pk_db" -nt "$pk_list" ]; } \
            || [ $(( $(date +%s) - $(stat -c %Y "$pk_list" 2>/dev/null || echo 0) )) -ge 86400 ]; }; then
        rm -f "$pk_dir/resend"
        # [naam, versie, architectuur, bronpakket, bronversie]
        if [ "$pk_mgr" = dpkg ]; then
            pk_rows=$(dpkg-query -W -f='${db:Status-Abbrev}\t${Package}\t${Version}\t${Architecture}\t${source:Package}\t${source:Version}\n' 2>/dev/null \
                | awk -F'\t' '$1 ~ /^ii/ { printf "[\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"],", $2, $3, $4, $5, $6 }')
        else
            pk_rows=$(rpm -qa --qf '%{NAME}\t%{EPOCHNUM}:%{VERSION}-%{RELEASE}\t%{ARCH}\t%{SOURCERPM}\n' 2>/dev/null \
                | awk -F'\t' '$1 != "gpg-pubkey" { v = $2; sub(/^0:/, "", v); s = $4; sub(/-[^-]+-[^-]+\.src\.rpm$/, "", s)
                    printf "[\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"],", $1, v, $3, s, v }')
        fi
        pk_os=$( (. /etc/os-release 2>/dev/null; printf '{"id":"%s","version":"%s","like":"%s","name":"%s"}' \
            "$(json_escape "${ID-}")" "$(json_escape "${VERSION_ID-}")" "$(json_escape "${ID_LIKE-}")" "$(json_escape "${PRETTY_NAME-}")") )
        # Bereikbaar via het netwerk: pakketten van programma's die op een poort luisteren (niet alleen op localhost)
        pk_exp=""
        if command -v ss >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
            declare -A pk_owner=()
            while read -r proto local pids; do
                for pid in $pids; do
                    exe=$(readlink -f "/proc/$pid/exe" 2>/dev/null) || continue
                    [ -n "$exe" ] || continue
                    if [ -z "${pk_owner[$exe]+x}" ]; then
                        if [ "$pk_mgr" = dpkg ]; then
                            o=$(dpkg -S "$exe" 2>/dev/null | head -1 | cut -d: -f1)
                            [ -z "$o" ] && o=$(dpkg -S "${exe#/usr}" 2>/dev/null | head -1 | cut -d: -f1)   # /usr-merge
                        else
                            o=$(rpm -qf --qf '%{NAME}\n' "$exe" 2>/dev/null | head -1)
                            [[ "$o" == *" "* ]] && o=""
                        fi
                        pk_owner[$exe]=$o
                    fi
                    [ -n "${pk_owner[$exe]}" ] && pk_exp+="[\"${pk_owner[$exe]}\",${local##*:},\"$proto\"],"
                done
            done < <(ss -Htulnp 2>/dev/null | awk '{
                    local = $5; if (local !~ /:[0-9]+$/) next
                    addr = local; sub(/:[0-9]+$/, "", addr); gsub(/[\[\]]/, "", addr); sub(/%.*/, "", addr)
                    if (addr ~ /^127\./ || addr == "::1" || addr == "localhost") next
                    pids = ""; s = $0
                    while (match(s, /pid=[0-9]+/)) { pids = pids " " substr(s, RSTART + 4, RLENGTH - 4); s = substr(s, RSTART + RLENGTH) }
                    if (pids != "") print $1, local, pids }' | sort -u)
        fi
        # Herstart nodig: nieuwere kernel geïnstalleerd dan er draait; services met oude bibliotheken (als de hulpmiddelen er zijn)
        pk_kern=$(find /boot -maxdepth 1 -name 'vmlinuz-*' -printf '%f\n' 2>/dev/null | sed 's/^vmlinuz-//' | grep -v rescue | sort -V | tail -1)
        pk_rs=""
        if command -v needs-restarting >/dev/null 2>&1; then
            pk_rs=$(timeout 60 needs-restarting -s 2>/dev/null | grep -E '^[A-Za-z0-9@._:-]+$' | head -50)
        elif command -v needrestart >/dev/null 2>&1; then
            pk_rs=$(timeout 60 needrestart -b -r l 2>/dev/null | sed -n 's/^NEEDRESTART-SVC: *//p' | grep -E '^[A-Za-z0-9@._:-]+$' | head -50)
        fi
        pk_rs_json=$(printf '%s\n' $pk_rs | awk 'NF { printf "\"%s\",", $0 }')
        printf '{"mgr":"%s","os":%s,"kernel":{"running":"%s","latest":"%s"},"restart":[%s],"exposed":[%s],"list":[%s]}' \
            "$pk_mgr" "${pk_os:-{\}}" "$(uname -r)" "$pk_kern" "${pk_rs_json%,}" "${pk_exp%,}" "${pk_rows%,}" >"$pk_list.tmp" && mv -f "$pk_list.tmp" "$pk_list"
    fi
    if [ -s "$pk_list" ]; then
        pk_hash=$(sha1sum "$pk_list" | cut -c1-16)
        # Alles meesturen als RzSense deze stand nog niet heeft; anders alleen het controlegetal.
        # Agent 1.66.1: kwam er de vorige keer geen antwoord (bv. RzSense was te traag), dan eerst alleen het controlegetal:
        # vaak heeft RzSense de lijst toen toch opgeslagen. Anders bleef de agent elke minuut de hele lijst sturen.
        if [ "$(cat "$pk_dir/sent" 2>/dev/null)" != "$pk_hash" ] && [ "$(cat "$pk_dir/tried" 2>/dev/null)" = "$pk_hash" ]; then
            pkgs_json="{\"hash\":\"$pk_hash\"}"; rm -f "$pk_dir/tried"
        elif [ "$(cat "$pk_dir/sent" 2>/dev/null)" != "$pk_hash" ]; then
            pkgs_json="{\"hash\":\"$pk_hash\",\"data\":$(cat "$pk_list")}"
            printf '%s' "$pk_hash" >"$pk_dir/tried" 2>/dev/null
        else
            pkgs_json="{\"hash\":\"$pk_hash\"}"
        fi
    fi
fi
# Uitkomst van "pakketten bijwerken" (opdracht uit RzSense) terugmelden: id, ok/error en het einde van de uitvoer (base64)
pkg_result=""
if [ -r "$pk_dir/upgrade.result" ]; then
    IFS=$'\t' read -r pr_id pr_st pr_log <"$pk_dir/upgrade.result"
    [[ "$pr_id" =~ ^[0-9a-f]{8,32}$ ]] && [[ "$pr_log" =~ ^[A-Za-z0-9+/=]*$ ]] \
        && pkg_result="{\"id\":\"$pr_id\",\"ok\":$([ "$pr_st" = ok ] && echo true || echo false),\"log_b64\":\"$pr_log\"}"
fi

pkg_upgrade() {  # pkg_upgrade <antwoord van RzSense>: alleen dnf/yum/apt "upgrade" van de meegestuurde pakketnamen, als root
    # Agent 1.63.0: of "install" (mode), alleen voor de onderdelen van de endpoint-bewaking (vaste lijst hieronder)
    # Agent 1.66.0: met "own" (eigen pakketnamen, door de beheerder bevestigd in RzSense) geldt die lijst niet; de namen
    # zelf moeten wel gewone pakketnamen zijn (geen opties, geen paden), en COMMANDS mag het niet blokkeren
    local id names n=() out="$pk_dir/upgrade.result" why="" mode=upgrade own=0
    id=$(sed -n 's/.*"pkg_upgrade":[[:space:]]*{[^}]*"id":[[:space:]]*"\([0-9a-f]\{8,32\}\)".*/\1/p' <<<"$1")
    names=$(sed -n 's/.*"pkg_upgrade":[[:space:]]*{[^}]*"pkgs":[[:space:]]*\[\([^]]*\)\].*/\1/p' <<<"$1" | tr -d '"' | tr ',' ' ')
    [[ "$1" =~ \"pkg_upgrade\":[[:space:]]*\{[^}]*\"mode\":[[:space:]]*\"install\" ]] && mode=install
    [[ "$1" =~ \"pkg_upgrade\":[[:space:]]*\{[^}]*\"own\":[[:space:]]*true ]] && own=1
    [ -n "$id" ] && [ -d "$pk_dir" ] || return 0
    for p in $names; do
        [[ "$p" =~ ^[A-Za-z0-9][A-Za-z0-9.+_:~-]{0,99}$ ]] || continue
        if [ "$mode" = install ] && [ "$own" = 0 ]; then case "$p" in audit|auditd|inotify-tools|epel-release|yara) ;; *) continue ;; esac; fi
        n+=("$p")
    done
    case "$COMMANDS" in 0|off|no|nee|nobody) why="geblokkeerd op de server (COMMANDS=$COMMANDS in /etc/default/rzmon-agent)" ;; esac
    [ "$(id -u)" = 0 ] || why="de agent draait niet als root"
    [ "${#n[@]}" -gt 0 ] || why="geen geldige pakketnamen ontvangen"
    if [ -n "$why" ]; then
        printf '%s\terror\t%s\n' "$id" "$(printf '%s' "$why" | base64 -w0)" >"$out"
        return 0
    fi
    # Loopt er al een (minder dan 30 minuten geleden gestart)? Dan niet nog een.
    if [ -f "$pk_dir/upgrade.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$pk_dir/upgrade.lock") )) -lt 1800 ]; then return 0; fi
    : >"$pk_dir/upgrade.lock"
    # Adres en token voor de voortgang: in een bestand (alleen root), niet als argument (zichtbaar in ps)
    # Regel 2: wie we zijn (header met het sleutelkenmerk of het token), regel 3: -k, of de vingerafdruk van RzSense
    local tls_spec=0
    [ "${tls[0]-}" = -k ] && tls_spec=1
    [ "${tls[1]-}" = --pinnedpubkey ] && tls_spec=${tls[2]}
    (umask 077; printf '%s\n%s\n%s\n%s\n' "$MONITOR_URL" "${auth[1]}" "$tls_spec" "$enc_key" >"$pk_dir/upgrade.conf")
    # Het script meldt zijn voortgang elke paar seconden zelf aan RzSense (stap, procent, pakket, laatste uitvoer);
    # de uitkomst gaat daarnaast via upgrade.result mee met het volgende rapport (als de voortgang niet aankwam).
    # shellcheck disable=SC2016  # het script krijgt zijn waarden als argumenten mee, niet via de tekst
    local script='id=$1; mgr=${2%%:*}; mode=${2#*:}; dir=$3; shift 3
        [ "$mode" = "$2" ] && mode=upgrade
        url=""; tok=""; ins=0; enc_key=""; last=0; stage=update; pct=2; detail=""; count=""
        if [ -r "$dir/upgrade.conf" ]; then { read -r url; read -r tok; read -r ins; read -r enc_key; } <"$dir/upgrade.conf"; rm -f "$dir/upgrade.conf"; fi
        log="$dir/upgrade.log"; : >"$log"
        post() {   # post [extra JSON-velden]: voortgang naar RzSense (hooguit 4 seconden wachten)
            [ -n "$url" ] || return 0
            local k=(-fsS -m 4 -o /dev/null -X POST -H "$tok" -H "Content-Type: application/json")
            [ "$ins" = 1 ] && k+=(-k)
            [[ "$ins" == sha256//* ]] && k+=(-k --pinnedpubkey "$ins")
            printf "%s" "{\"id\":\"$id\",\"stage\":\"$stage\",\"pct\":$pct,\"detail\":\"$(printf "%s" "$detail" | tr -d "\"\\\\\\000-\\037" | cut -c1-120)\",\"count\":\"$count\",\"log_b64\":\"$(tail -c "${2:-800}" "$log" | base64 -w0)\"$1}" >"$dir/progress.json"
            local f="$dir/progress.json"
            if [ -n "$enc_key" ] && seal "$dir/progress.json" "$dir/progress.enc"; then f="$dir/progress.enc"; fi   # versleuteld, net als het rapport
            curl "${k[@]}" --data-binary "@$f" "${url%/}/api.php?action=agent_pkg_progress" >/dev/null 2>&1
            last=$(date +%s)
        }
        tick() { [ "$1" != "$stage" ] && { stage=$1; post; return; }; [ $(( $(date +%s) - last )) -ge 2 ] && post; }
        export LC_ALL=C
        post
        if [ "$mgr" = rpm ]; then
            cmd=(dnf -y upgrade "$@"); command -v dnf >/dev/null 2>&1 || cmd=(yum -y update "$@")
            if [ "$mode" = install ]; then
                # EPEL eerst apart (inotify-tools en yara staan daarin), dan de rest
                if [ "$1" = epel-release ]; then dnf -y install epel-release >>"$log" 2>&1 || yum -y install epel-release >>"$log" 2>&1; shift; fi
                cmd=(dnf -y install "$@"); command -v dnf >/dev/null 2>&1 || cmd=(yum -y install "$@")
            fi
            "${cmd[@]}" 2>&1 | while IFS= read -r l; do
                printf "%s\n" "$l" >>"$log"
                if [[ "$l" =~ ^\(([0-9]+)/([0-9]+)\):\ ([^ ]+) ]]; then          # (3/7): openssl-libs-3.2.2.rpm   downloaden
                    pct=$((15 + 30 * BASH_REMATCH[1] / BASH_REMATCH[2])); detail=${BASH_REMATCH[3]}; count="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"; tick download
                elif [[ "$l" =~ ^\ +([A-Z][a-z]+( scriptlet)?)\ *:\ ([^ ]+).*\ ([0-9]+)/([0-9]+)$ ]]; then   #   Upgrading : openssl 3/14
                    detail=${BASH_REMATCH[3]}; count="${BASH_REMATCH[4]}/${BASH_REMATCH[5]}"
                    if [ "${BASH_REMATCH[1]}" = Verifying ]; then pct=$((90 + 8 * BASH_REMATCH[4] / BASH_REMATCH[5])); tick verify
                    else pct=$((45 + 45 * BASH_REMATCH[4] / BASH_REMATCH[5])); tick install; fi
                elif [[ "$l" =~ ^\[\ *([0-9]+)/([0-9]+)\]\ (.*)$ ]]; then          # dnf5: [ 3/14] Upgrading openssl
                    pct=$((45 + 50 * BASH_REMATCH[1] / BASH_REMATCH[2])); detail=${BASH_REMATCH[3]}; count="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"; tick install
                fi
            done
            rc=${PIPESTATUS[0]}
        else
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -q >>"$log" 2>&1; rc=$?
            if [ "$rc" = 0 ]; then
                stage=download; pct=15; post
                # APT::Status-Fd: apt meldt zelf het percentage (dlstatus = downloaden, pmstatus = installeren per pakket)
                up=--only-upgrade; [ "$mode" = install ] && up=--no-install-recommends
                apt-get install -y -q "$up" -o Dpkg::Options::=--force-confold -o APT::Status-Fd=3 "$@" 3>&1 >>"$log" 2>&1 \
                    | while IFS=: read -r kind p1 p2 _; do
                        p2=${p2%%.*}; [[ "$p2" =~ ^[0-9]+$ ]] || continue
                        case "$kind" in
                            dlstatus) pct=$((15 + 30 * p2 / 100)); detail=""; count=""; tick download ;;
                            pmstatus) pct=$((45 + 53 * p2 / 100)); detail=$p1; count=""; tick install ;;
                        esac
                    done
                rc=${PIPESTATUS[0]}
            fi
        fi
        # auditd na het installeren meteen starten (de agent zet de regels bij de volgende run)
        if [ "$mode" = install ] && [ "$rc" = 0 ] && command -v auditctl >/dev/null 2>&1; then systemctl enable --now auditd >>"$log" 2>&1 || service auditd start >>"$log" 2>&1; fi
        printf "%s\t%s\t%s\n" "$id" "$([ "$rc" = 0 ] && echo ok || echo error)" "$(tail -c 1500 "$log" | base64 -w0)" >"$dir/upgrade.result.tmp" \
            && mv -f "$dir/upgrade.result.tmp" "$dir/upgrade.result"
        stage=done; pct=100; detail=""; count=""
        post ",\"done\":true,\"ok\":$([ "$rc" = 0 ] && echo true || echo false)" 1500
        rm -f "$dir/upgrade.lock" "$dir/progress.json" "$dir/progress.enc"'
    script="$(declare -f enc_sub seal)
$script"   # versleutelen kan het script dan zelf
    # Als bestand starten, niet met bash -c: systemd-run vult ${…} en %-codes op de opdrachtregel zelf in, waardoor
    # bv. "${cmd[@]}" leeg werd (1.48.0–1.49.0: "_: line 34: : command not found")
    (umask 077; printf '%s\n' "$script" >"$pk_dir/upgrade.sh") \
        || { printf '%s\terror\t%s\n' "$id" "$(printf 'kan upgrade.sh niet schrijven' | base64 -w0)" >"$out"; rm -f "$pk_dir/upgrade.lock"; return 0; }
    if [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --collect --unit="rzmon-pkg-upgrade-$(date +%s)" /bin/bash "$pk_dir/upgrade.sh" "$id" "$pk_mgr:$mode" "$pk_dir" "${n[@]}" >/dev/null 2>&1 \
            || { printf '%s\terror\t%s\n' "$id" "$(printf 'systemd-run mislukt' | base64 -w0)" >"$out"; rm -f "$pk_dir/upgrade.lock"; }
    else
        setsid /bin/bash "$pk_dir/upgrade.sh" "$id" "$pk_mgr:$mode" "$pk_dir" "${n[@]}" 9>&- </dev/null >/dev/null 2>&1 &
    fi
}

