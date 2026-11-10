# ---------------------------------------------------------------- Ransomware herkennen (agent 1.90.0)
lap ransom
# Aan per server in RzSense (Agent Manager → Ransomware), met de mappen die je kiest (regels "0 <tab> ransompath <tab> pad
# (base64) <tab> drempel"). Elke ronde, per map:
#  1. lokaasbestanden: twee bestanden die niemand hoort aan te raken (vooraan en achteraan in de map, want ransomware gaat
#     vaak op alfabet). Gewijzigd, hernoemd of weg = meteen alarm;
#  2. hoeveel bestanden er sinds de vorige ronde veranderden of hernoemd werden (find -cnewer, hooguit 15 s, lage voorrang);
#  3. kenmerken: bekende ransomware-extensies, losgeldbriefjes, en tekstbestanden die ineens niet meer te comprimeren
#     zijn (versleuteld).
# Bij een sterk signaal, als je dat aanzette: meteen een snapshot (ZFS, of btrfs read-only) en/of de schrijvende processen
# stoppen (bij Samba de sessie van die pc, en dat IP in de blokkade-set van de firewall). Alles komt in het logboek.
# "0 <tab> ransom <tab> snapshot 0|1 <tab> stoppen 0|1 <tab> lokaas 0|1" zet het aan; zonder die regel ruimt de agent
# zijn lokaasbestanden weer op.
ransom_json=""; rs_cand_json=""
rs_dir="$t_dir/ransom"
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ "${RANSOM:-1}" != 0 ]; then
    mkdir -p "$rs_dir" 2>/dev/null && chmod 700 "$rs_dir" 2>/dev/null
    rs_line=""
    [ -r "${t_cache-}" ] && rs_line=$(awk -F'\t' '$1 == "0" && $2 == "ransom" { print; exit }' "$t_cache")
    # Mappen om uit te kiezen (eens per uur): Samba-shares, NFS-exports, de shares van TrueNAS en /home
    if [ ! -s "$rs_dir/cand" ] || [ -n "$(find "$rs_dir/cand" -mmin +60 2>/dev/null)" ]; then
        {
            command -v testparm >/dev/null 2>&1 && timeout 10 testparm -s 2>/dev/null \
                | awk -F'=' 'tolower($1) ~ /^[ \t]*path[ \t]*$/ { v = $2; sub(/^[ \t]+/, "", v); sub(/[ \t]+$/, "", v); print "smb\t" v }'
            [ -r /etc/exports ] && awk '$1 ~ /^\// { print "nfs\t" $1 }' /etc/exports
            if command -v midclt >/dev/null 2>&1; then
                timeout 15 midclt call sharing.smb.query 2>/dev/null | tr ',' '\n' | sed -n 's/.*"path": *"\(\/[^"]*\)".*/smb\t\1/p'
                timeout 15 midclt call sharing.nfs.query 2>/dev/null | tr ',' '\n' | sed -n 's/.*"path": *"\(\/[^"]*\)".*/nfs\t\1/p'
            fi
            [ -d /home ] && printf 'home\t/home\n'
        } 2>/dev/null | awk -F'\t' '$2 ~ /^\// && $2 !~ /\.\./ && !seen[$2]++' | head -n 50 >"$rs_dir/cand.new" && mv -f "$rs_dir/cand.new" "$rs_dir/cand"
    fi
    while IFS=$'\t' read -r rs_k rs_p; do
        [ -d "$rs_p" ] && rs_cand_json+="[\"$rs_k\",\"$(json_escape "$rs_p")\"],"
    done <"$rs_dir/cand"
    rs_cand_json="[${rs_cand_json%,}]"

    rs_ext_re='\.(locked|encrypted|crypted|crypt|enc[0-9]*|lockbit|conti|ryuk|akira|blackcat|alphv|phobos|djvu|cerber|wncry|wnry|wncryt|locky|zepto|odin|petya|sodinokibi|revil|maze|egregor|clop|cl0p|avaddon|babuk|hive|royal|rhysida|medusa|8base|basta|bianlian|ransom|hellokitty|play|lock|pay2key|nemty|makop|mallox|stop)$'
    rs_note_re='(how_to_decrypt|how_to_recover|how_to_restore|readme_to_restore|restore_files|restore-my-files|decrypt_instructions|decrypt-files|recover-files|read_me_now|!!!read|_readme\.txt|readme-warning|ransom_note|files_encrypted|info\.hta|decryption_instructions)'
    rs_text_re='\.(txt|csv|log|md|html?|xml|json|sql|conf|ini|cfg|php|py|js|sh|ya?ml|svg|tex|rtf)$'
    rs_c1='!0-RzSense-lokaas-niet-aanraken.txt'; rs_c2='zz-RzSense-lokaas-niet-aanraken.txt'
    rs_canary_make() {  # rs_canary_make <map> <naam>: lokaasbestand met de eigenaar van de map, en de vingerafdruk
        local f="$1/$2" own
        own=$(stat -c '%u:%g' "$1" 2>/dev/null)
        { printf 'Dit bestand hoort bij RzSense (bescherming tegen ransomware).\nNiet openen, wijzigen, hernoemen of weggooien: dan volgt een alarm.\n'
          printf 'This file belongs to RzSense (ransomware protection). Do not open, change, rename or delete it.\n%s\n' "$(head -c 24 /dev/urandom | base64)"; } >"$f" 2>/dev/null || return 1
        [ -n "$own" ] && chown "$own" "$f" 2>/dev/null
        chmod 664 "$f" 2>/dev/null
        printf '%s\t%s\n' "$f" "$(sha256sum "$f" | cut -d' ' -f1)"
    }
    rs_snapshot() {  # rs_snapshot <map>: snapshot van het ZFS-dataset (of btrfs-subvolume) van de map; geeft de naam
        local p=$1 ds mp best="" bl=0 ts
        ts=$(date +%Y%m%d-%H%M%S)
        if command -v zfs >/dev/null 2>&1; then
            while IFS=$'\t' read -r ds mp; do
                [ "$mp" = "/" ] || [ "${mp:0:1}" != "/" ] && continue
                case "$p/" in "$mp"/*) [ ${#mp} -gt "$bl" ] && { best=$ds; bl=${#mp}; } ;; esac
            done < <(zfs list -H -o name,mountpoint -t filesystem 2>/dev/null)
            if [ -n "$best" ] && zfs snapshot -r "$best@rzsense-ransom-$ts" 2>/dev/null; then echo "$best@rzsense-ransom-$ts"; return 0; fi
        fi
        if [ "$(stat -f -c %T "$p" 2>/dev/null)" = btrfs ] && command -v btrfs >/dev/null 2>&1 && [ "$(stat -c %i "$p" 2>/dev/null)" = 256 ]; then
            mkdir -p "$p/.rzsense-snapshots" 2>/dev/null
            if btrfs subvolume snapshot -r "$p" "$p/.rzsense-snapshots/ransom-$ts" >/dev/null 2>&1; then echo "$p/.rzsense-snapshots/ransom-$ts"; return 0; fi
        fi
        return 1
    }
    rs_stop() {  # rs_stop <map> <bestanden…>: processen die nu in deze bestanden schrijven stoppen; Samba: de sessie en het IP
        local p=$1 pid comm ip out=""; shift
        local pids=""
        if command -v lsof >/dev/null 2>&1; then
            pids=$(timeout 10 lsof -t -w -- "$@" 2>/dev/null | sort -u | head -n 20)
        fi
        if command -v smbstatus >/dev/null 2>&1; then   # sessies met een open bestand in deze map
            pids+=" $(timeout 10 smbstatus -L 2>/dev/null | awk -v p="$p" 'index($0, p) && $1 ~ /^[0-9]+$/ { print $1 }' | sort -u | head -n 20)"
        fi
        for pid in $pids; do
            [[ "$pid" =~ ^[0-9]+$ ]] && [ "$pid" -gt 1 ] && [ "$pid" != "$$" ] && [ "$pid" != "$PPID" ] || continue
            comm=$(cat "/proc/$pid/comm" 2>/dev/null)
            case "$comm" in systemd*|sshd|init|rzmon*|agetty|login|dbus*|"") continue ;; esac
            ip=""
            [ "$comm" = smbd ] && ip=$(timeout 10 smbstatus -p 2>/dev/null | awk -v pid="$pid" '$1 == pid { for (i = 1; i <= NF; i++) if ($i ~ /^(ipv4:)?[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(:[0-9]+)?$/) { sub(/^ipv4:/, "", $i); sub(/:[0-9]+$/, "", $i); print $i; exit } }')
            if kill -KILL "$pid" 2>/dev/null; then
                act_log ransom 1 "Proces gestopt" "pid $pid ($comm)${ip:+ · pc $ip}"
                out+="{\"pid\":$pid,\"comm\":\"$(json_escape "$comm")\",\"ip\":\"$ip\"},"
                if [ -n "$ip" ] && nft list table inet rzmon >/dev/null 2>&1 && nft add element inet rzmon bl4 "{ $ip }" 2>/dev/null; then
                    act_log ransom 1 "IP geblokkeerd" "$ip (Samba-sessie die bestanden versleutelde)"
                fi
            fi
        done
        printf '%s' "${out%,}"
    }

    # -cnewer ziet ook hernoemde bestanden (de statustijd verandert); BusyBox-find kent het niet: dan -newer
    rs_newer=-cnewer
    find "$rs_dir" -maxdepth 0 -cnewer "$rs_dir" >/dev/null 2>&1 || rs_newer=-newer
    rs_keep=" "
    if [ -n "$rs_line" ]; then
        IFS=$'\t' read -r _ _ rs_snap rs_kill rs_canary _ <<<"$rs_line"
        rs_paths=""
        while IFS=$'\t' read -r _ _ rs_b64 rs_n _; do
            rs_p=$(printf '%s' "$rs_b64" | base64 -d 2>/dev/null)
            [[ "$rs_p" == /* && "$rs_p" != *..* && "$rs_p" != / ]] && [ -d "$rs_p" ] && [ ! -L "$rs_p" ] || continue
            case "$rs_p" in /proc*|/sys*|/dev*|/run*) continue ;; esac
            [[ "$rs_n" =~ ^[0-9]+$ ]] || rs_n=300
            rs_k=$(printf '%s' "$rs_p" | sha1sum | cut -c1-12)
            rs_keep+="$rs_k "
            rs_st="$rs_dir/$rs_k"
            # 1. lokaasbestanden
            rs_cstate="uit"; rs_chit=""
            if [ "$rs_canary" = 1 ]; then
                rs_cstate=ok
                if [ ! -s "$rs_st.canary" ]; then
                    { rs_canary_make "$rs_p" "$rs_c1"; rs_canary_make "$rs_p" "$rs_c2"; } >"$rs_st.canary" 2>/dev/null
                    printf '%s\n' "$rs_p" >"$rs_st.path"
                    if [ -s "$rs_st.canary" ]; then act_log ransom 1 "Lokaasbestanden geplaatst" "$rs_p"; else rs_cstate=fout; fi
                else
                    while IFS=$'\t' read -r rs_f rs_sha; do
                        if [ ! -e "$rs_f" ]; then rs_chit+="${rs_f##*/} is weg; "
                        elif [ "$(sha256sum "$rs_f" 2>/dev/null | cut -d' ' -f1)" != "$rs_sha" ]; then rs_chit+="${rs_f##*/} is gewijzigd; "; fi
                    done <"$rs_st.canary"
                    if [ -n "$rs_chit" ]; then
                        rs_cstate=alarm
                        # na 10 minuten opnieuw plaatsen (dan zie je ook een tweede golf)
                        if [ ! -f "$rs_st.chit" ]; then : >"$rs_st.chit"
                        elif [ -n "$(find "$rs_st.chit" -mmin +10 2>/dev/null)" ]; then
                            rm -f "$rs_st.chit" "$rs_st.canary"
                        fi
                    fi
                fi
            fi   # lokaas uit: de lus onderaan ruimt de bestanden op
            # 2. en 3. wat er sinds de vorige ronde veranderde
            rs_n_ch=0; rs_ext=0; rs_note=0; rs_enc=0; rs_slow=0; rs_ex=""
            : >"$rs_st.stamp.new"
            if [ -f "$rs_st.stamp" ]; then
                timeout 15 nice -n 10 find "$rs_p" -xdev -maxdepth 8 "$rs_newer" "$rs_st.stamp" -type f \
                    -not -path '*/.zfs/*' -not -path '*/.rzsense-snapshots/*' -not -name "$rs_c1" -not -name "$rs_c2" -print 2>/dev/null | head -n 20000 >"$rs_st.list"
                [ "${PIPESTATUS[0]}" = 124 ] && rs_slow=1
                rs_n_ch=$(wc -l <"$rs_st.list")
                rs_ext=$(grep -ciE "$rs_ext_re" "$rs_st.list")
                rs_note=$(awk -F/ '{ print tolower($NF) }' "$rs_st.list" | grep -cE "$rs_note_re")
                while IFS= read -r rs_f; do   # tekstbestanden die niet meer te comprimeren zijn
                    rs_sz=$(stat -c %s "$rs_f" 2>/dev/null || echo 0)
                    [ "$rs_sz" -ge 4096 ] || continue
                    rs_gz=$(head -c 65536 "$rs_f" 2>/dev/null | gzip -c | wc -c)
                    rs_rd=$(( rs_sz < 65536 ? rs_sz : 65536 ))
                    [ $(( rs_gz * 100 )) -ge $(( rs_rd * 97 )) ] && rs_enc=$(( rs_enc + 1 ))
                done < <(grep -iE "$rs_text_re" "$rs_st.list" | head -n 15)
                rs_ex=$( { grep -iE "$rs_ext_re" "$rs_st.list"; awk -F/ 'tolower($NF) ~ /'"$rs_note_re"'/' "$rs_st.list"; } | head -n 5 | sed "s#^$rs_p/##" | cut -c1-120 \
                    | while IFS= read -r l; do printf '"%s",' "$(json_escape "$l")"; done)
            fi
            mv -f "$rs_st.stamp.new" "$rs_st.stamp"
            # Sterk signaal: lokaas geraakt, extensies, briefjes, versleutelde tekst, of heel veel tegelijk mét een kenmerk
            rs_hit=0
            if [ -n "$rs_chit" ] || [ "$rs_ext" -ge 3 ] || [ "$rs_note" -ge 2 ] || [ "$rs_enc" -ge 3 ] \
                || { [ "$rs_n_ch" -ge "$rs_n" ] && [ $(( rs_ext + rs_enc + rs_note )) -ge 1 ]; }; then rs_hit=1; fi
            rs_snapname=""; rs_killed=""
            if [ "$rs_hit" = 1 ]; then
                act_log ransom 0 "Mogelijke ransomware" "$rs_p · ${rs_chit}$rs_n_ch gewijzigd · $rs_ext met ransomware-extensie · $rs_note losgeldbriefjes · $rs_enc versleuteld"
                if [ "$rs_snap" = 1 ] && { [ ! -f "$rs_st.snap" ] || [ -n "$(find "$rs_st.snap" -mmin +30 2>/dev/null)" ]; }; then
                    if rs_snapname=$(rs_snapshot "$rs_p"); then
                        : >"$rs_st.snap"; act_log ransom 1 "Snapshot gemaakt" "$rs_snapname"
                    else
                        act_log ransom 0 "Snapshot maken mislukt" "$rs_p: geen ZFS-dataset of btrfs-subvolume gevonden"
                    fi
                fi
                if [ "$rs_kill" = 1 ]; then
                    mapfile -t rs_files < <( { grep -iE "$rs_ext_re" "$rs_st.list"; head -n 30 "$rs_st.list"; } 2>/dev/null | head -n 40)
                    rs_killed=$(rs_stop "$rs_p" "${rs_files[@]}")
                fi
            fi
            rs_paths+="{\"k\":\"$rs_k\",\"p\":\"$(json_escape "$rs_p")\",\"n\":$rs_n_ch,\"ext\":$rs_ext,\"notes\":$rs_note,\"enc\":$rs_enc,\"slow\":$rs_slow,\"canary\":\"$rs_cstate\",\"chit\":\"$(json_escape "$rs_chit")\",\"hit\":$rs_hit,\"snap\":\"$(json_escape "$rs_snapname")\",\"ex\":[${rs_ex%,}],\"killed\":[$rs_killed]},"
            [ "${#rs_paths}" -gt 60000 ] && break
        done < <(awk -F'\t' '$1 == "0" && $2 == "ransompath"' "$t_cache" | head -n 20)
        ransom_json="{\"on\":1,\"paths\":[${rs_paths%,}],\"cand\":$rs_cand_json}"
    else
        ransom_json="{\"on\":0,\"cand\":$rs_cand_json}"
    fi
    # Mappen die niet meer bewaakt worden (of lokaas uit): onze lokaasbestanden weg, alleen als ze nog ongewijzigd zijn
    for rs_cf in "$rs_dir"/*.canary; do
        [ -f "$rs_cf" ] || continue
        rs_k=${rs_cf##*/}; rs_k=${rs_k%.canary}
        [[ "$rs_keep" == *" $rs_k "* ]] && [ "${rs_canary:-0}" = 1 ] && continue
        while IFS=$'\t' read -r rs_f rs_sha; do
            [ -f "$rs_f" ] && [ "$(sha256sum "$rs_f" 2>/dev/null | cut -d' ' -f1)" = "$rs_sha" ] && rm -f -- "$rs_f"
        done <"$rs_cf"
        act_log ransom 1 "Lokaasbestanden weggehaald" "$(cat "$rs_dir/$rs_k.path" 2>/dev/null)"
        rm -f "$rs_cf" "$rs_dir/$rs_k.path" "$rs_dir/$rs_k.chit"
    done
    for rs_sf in "$rs_dir"/*.stamp; do   # stempels van mappen die weg zijn
        [ -f "$rs_sf" ] || continue
        rs_k=${rs_sf##*/}; rs_k=${rs_k%.stamp}
        [[ "$rs_keep" == *" $rs_k "* ]] || rm -f "$rs_sf" "$rs_dir/$rs_k.list" "$rs_dir/$rs_k.snap"
    done
fi
