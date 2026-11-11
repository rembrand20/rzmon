# ---------------------------------------------------------------- Onbekende programma's en jagen op sporen (agent 1.91.0)
# 1.95.0: van welk pakket zijn deze bestanden? $1 = bestand met paden (één per regel). Uitvoer: pad<TAB>pakket.
# In één keer: rpm via xargs, dpkg door alle .list-bestanden één keer te lezen (ook /bin ↔ /usr/bin), apk en pacman per bestand.
# Gebruikt door Programma's (64) en de pakketten die via het netwerk bereikbaar zijn (75).
pkg_owners() {
    local list=$1 f g
    [ -s "$list" ] || return 0
    if command -v rpm >/dev/null 2>&1 && { [ -d /var/lib/rpm ] || [ -d /usr/lib/sysimage/rpm ]; }; then
        tr '\n' '\0' <"$list" | xargs -0 -r timeout 60 rpm -qf --qf '%{NAME}\n' -- 2>/dev/null | paste "$list" - 2>/dev/null \
            | awk -F'\t' '$2 != "" && $2 !~ / / { print $1 "\t" $2 }'
    fi
    if [ -d /var/lib/dpkg/info ]; then
        timeout 60 awk 'NR == FNR { w[$0] = $0; if ($0 ~ /^\/usr\//) w[substr($0, 5)] = $0; else w["/usr" $0] = $0; next }
            ($0 in w) { n = FILENAME; sub(/^.*\//, "", n); sub(/\.list$/, "", n); sub(/:.*/, "", n); print w[$0] "\t" n }' \
            "$list" /var/lib/dpkg/info/*.list 2>/dev/null
    fi
    if command -v apk >/dev/null 2>&1; then
        while IFS= read -r f; do
            g=$(timeout 5 apk info -W -- "$f" 2>/dev/null | awk '/ is owned by / { print $NF; exit }')
            [ -n "$g" ] && printf '%s\t%s\n' "$f" "${g%-r[0-9]*}"
        done <"$list"
    fi
    if command -v pacman >/dev/null 2>&1; then
        while IFS= read -r f; do
            g=$(timeout 5 pacman -Qoq -- "$f" 2>/dev/null | head -n 1)
            [ -n "$g" ] && printf '%s\t%s\n' "$f" "$g"
        done <"$list"
    fi
    return 0
}

lap apps
# 1. Programma's (aan per server in RzSense: regel "0 <tab> apps <tab> stoppen 0|1"): elke ronde de programma's die nu
#    draaien (/proc/*/exe, niet in containers). Een nieuw of veranderd programma krijgt een vingerafdruk (sha256) en het
#    pakket waar het bij hoort (rpm, dpkg, apk, pacman; snap/flatpak/nix apart). Dat gaat één keer naar RzSense, die
#    beslist wat onbekend is (na de leerperiode). Staat de vingerafdruk op de blokkeerlijst van RzSense (regels
#    "0 <tab> appblock <tab> sha256") en staat stoppen aan, dan stopt de agent dat programma (alleen dat proces, op PID).
# 2. Jagen op sporen (regels "0 <tab> iochunt <tab> id", "iocsha <tab> sha256", "iocpath <tab> pad (base64)"): één keer per
#    opdracht zoekt de agent of die paden bestaan en of een draaiend programma of een bestand in de gebruikelijke plekken
#    (/tmp, /var/tmp, /dev/shm, /usr/local, /opt, /root, /home, /srv, /var/www) die vingerafdruk heeft. Hooguit 2 minuten,
#    met lage voorrang. Alles komt in het logboek.
apps_json=""; ioc_json=""
ap_dir="$t_dir/apps"
if [ "$HA_MODE" = 0 ] && [ "$(id -u)" = 0 ] && [ -r "${t_cache-}" ]; then
    mkdir -p "$ap_dir" 2>/dev/null && chmod 700 "$ap_dir" 2>/dev/null
    ap_line=""
    [ "${APPCTL:-1}" != 0 ] && ap_line=$(awk -F'\t' '$1 == "0" && $2 == "apps" { print; exit }' "$t_cache")
    ap_hunt=$(awk -F'\t' '$1 == "0" && $2 == "iochunt" && $3 ~ /^[0-9]+$/ { print $3; exit }' "$t_cache")
    [ -n "$ap_hunt" ] && [ "$ap_hunt" = "$(cat "$ap_dir/ioc.done" 2>/dev/null)" ] && ap_hunt=""
    if [ -n "$ap_line" ] || [ -n "$ap_hunt" ]; then
        # Wat er nu draait (niet in een container: die hebben een eigen mount-namespace)
        ap_mnt=$(readlink /proc/self/ns/mnt 2>/dev/null)
        : >"$ap_dir/cur.new"
        for ap_p in /proc/[0-9]*; do
            ap_exe=$(readlink "$ap_p/exe" 2>/dev/null) || continue
            case "$ap_exe" in ""|/memfd:*|*" (deleted)") continue ;; esac   # die ziet de IDS al (verdachte processen)
            [ -n "$ap_mnt" ] && [ "$(readlink "$ap_p/ns/mnt" 2>/dev/null)" != "$ap_mnt" ] && continue
            printf '%s\t%s\t%s\n' "$ap_exe" "${ap_p#/proc/}" "$(stat -c %U "$ap_p" 2>/dev/null)" >>"$ap_dir/cur.new"
        done
        sort -t$'\t' -k1,1 -u "$ap_dir/cur.new" >"$ap_dir/cur" 2>/dev/null; rm -f "$ap_dir/cur.new"
        # Vingerafdruk en pakket alleen voor wat nieuw of veranderd is (apparaat, inode, grootte, tijd)
        declare -A ap_key=() ap_sha=() ap_pkg=()
        if [ -f "$ap_dir/seen" ]; then
            while IFS=$'\t' read -r ap_f ap_k ap_s ap_g; do ap_key[$ap_f]=$ap_k; ap_sha[$ap_f]=$ap_s; ap_pkg[$ap_f]=$ap_g; done <"$ap_dir/seen"
        fi
        : >"$ap_dir/todo"
        while IFS=$'\t' read -r ap_f _ _; do
            ap_k=$(stat -L -c '%d:%i:%s:%Y' -- "$ap_f" 2>/dev/null) || continue
            [ "${ap_key[$ap_f]-}" = "$ap_k" ] && continue
            ap_key[$ap_f]=$ap_k
            printf '%s\n' "$ap_f" >>"$ap_dir/todo"
        done <"$ap_dir/cur"
        head -n 150 "$ap_dir/todo" >"$ap_dir/todo.1"
        ap_new=""
        if [ -s "$ap_dir/todo.1" ]; then
            # Pakketten in één keer opzoeken (dpkg: alle .list-bestanden in één keer lezen; ook /bin ↔ /usr/bin)
            pkg_owners "$ap_dir/todo.1" >"$ap_dir/pkg"   # 1.95.0: gedeeld met de lijst "bereikbaar via het netwerk"
            declare -A ap_npkg=()
            while IFS=$'\t' read -r ap_f ap_g; do [ -z "${ap_npkg[$ap_f]-}" ] && ap_npkg[$ap_f]=$ap_g; done <"$ap_dir/pkg"
            while IFS= read -r ap_l; do
                ap_s=${ap_l%% *}; ap_f=${ap_l#* }; ap_f=${ap_f# }; ap_f=${ap_f#\*}
                [[ "$ap_s" =~ ^[0-9a-f]{64}$ ]] || continue
                ap_g=${ap_npkg[$ap_f]-}
                case "$ap_f" in /snap/*) ap_g=${ap_g:-snap} ;; /var/lib/flatpak/*) ap_g=${ap_g:-flatpak} ;; /nix/store/*) ap_g=${ap_g:-nix} ;; esac
                ap_changed=0
                [ -n "${ap_sha[$ap_f]-}" ] && [ "${ap_sha[$ap_f]}" != "$ap_s" ] && ap_changed=1
                ap_sha[$ap_f]=$ap_s; ap_pkg[$ap_f]=$ap_g
                IFS=$'\t' read -r _ ap_pid ap_u < <(awk -F'\t' -v f="$ap_f" '$1 == f { print; exit }' "$ap_dir/cur")
                ap_cmd=$(tr '\0' ' ' 2>/dev/null <"/proc/$ap_pid/cmdline" | head -c 300 | json_text)
                ap_new+="{\"p\":\"$(json_escape "$ap_f")\",\"sha\":\"$ap_s\",\"pkg\":\"$(json_escape "$ap_g")\",\"u\":\"$(json_escape "$ap_u")\",\"cmd\":\"$ap_cmd\",\"ch\":$ap_changed},"
            done < <(tr '\n' '\0' <"$ap_dir/todo.1" | nice -n 19 xargs -0 -r timeout 60 sha256sum -- 2>/dev/null)
            # Alleen wat een vingerafdruk kreeg komt in "gezien"; de rest (meer dan 150) volgt de volgende ronde
            for ap_f in "${!ap_sha[@]}"; do
                [ -n "${ap_key[$ap_f]-}" ] && printf '%s\t%s\t%s\t%s\n' "$ap_f" "${ap_key[$ap_f]}" "${ap_sha[$ap_f]}" "${ap_pkg[$ap_f]-}"
            done | sort >"$ap_dir/seen.new" && mv -f "$ap_dir/seen.new" "$ap_dir/seen"
        fi
        rm -f "$ap_dir/todo" "$ap_dir/todo.1" "$ap_dir/pkg"
        if [ -n "$ap_line" ]; then
            IFS=$'\t' read -r _ _ ap_kill _ <<<"$ap_line"
            ap_block=" $(awk -F'\t' '$1 == "0" && $2 == "appblock" && length($3) == 64 && $3 ~ /^[0-9a-f]+$/ { printf "%s ", $3 }' "$t_cache")"
            # Wat er nu draait zonder pakket (vingerafdrukken), en geblokkeerde programma's stoppen
            ap_run=""; ap_blk=""; ap_n=0
            while IFS=$'\t' read -r ap_f ap_pid ap_u; do
                ap_n=$((ap_n + 1))
                ap_s=${ap_sha[$ap_f]-}
                [ -n "$ap_s" ] || continue
                [ -z "${ap_pkg[$ap_f]-}" ] && [ "${#ap_run}" -lt 14000 ] && ap_run+="\"$ap_s\","
                [[ "$ap_block" == *" $ap_s "* ]] || continue
                ap_k=0; ap_pids=""
                for ap_q in /proc/[0-9]*; do
                    [ "$(readlink "$ap_q/exe" 2>/dev/null)" = "$ap_f" ] && ap_pids+="${ap_q#/proc/} "
                done
                for ap_q in $ap_pids; do
                    [ "$ap_kill" = 1 ] && [ "$ap_q" -gt 1 ] && [ "$ap_q" != "$$" ] && [ "$ap_q" != "$PPID" ] || continue
                    case "$(cat "/proc/$ap_q/comm" 2>/dev/null)" in systemd*|sshd|init|rzmon*|agetty|login|dbus*|"") continue ;; esac
                    [ "$(readlink "/proc/$ap_q/exe" 2>/dev/null)" = "$ap_f" ] || continue   # nog steeds hetzelfde programma
                    if kill -KILL "$ap_q" 2>/dev/null; then
                        ap_k=$((ap_k + 1)); act_log appctl 1 "Geblokkeerd programma gestopt" "$ap_f · pid $ap_q · $ap_u · sha256 ${ap_s:0:16}…"
                    else
                        act_log appctl 0 "Geblokkeerd programma stoppen mislukt" "$ap_f · pid $ap_q"
                    fi
                done
                ap_blk+="{\"p\":\"$(json_escape "$ap_f")\",\"sha\":\"$ap_s\",\"u\":\"$(json_escape "$ap_u")\",\"killed\":$ap_k},"
            done <"$ap_dir/cur"
            apps_json="{\"on\":1,\"n\":$ap_n,\"kill\":${ap_kill:-0},\"new\":[${ap_new%,}],\"run\":[${ap_run%,}],\"blk\":[${ap_blk%,}]}"
        fi
        # Jagen op sporen: één keer per opdracht
        if [ -n "$ap_hunt" ]; then
            printf '%s' "$ap_hunt" >"$ap_dir/ioc.done"
            ap_t0=$(date +%s); ap_hits=""; ap_nh=0
            while IFS= read -r ap_b; do
                ap_f=$(printf '%s' "$ap_b" | base64 -d 2>/dev/null) || continue
                [ "${ap_f:0:1}" = / ] || continue
                if [ -e "$ap_f" ]; then ap_hits+="{\"t\":\"path\",\"v\":\"$(json_escape "$ap_f")\",\"p\":\"$(json_escape "$ap_f")\"},"; ap_nh=$((ap_nh + 1)); fi
            done < <(awk -F'\t' '$1 == "0" && $2 == "iocpath" { print $3 }' "$t_cache" | head -n 200)
            awk -F'\t' '$1 == "0" && $2 == "iocsha" && length($3) == 64 && $3 ~ /^[0-9a-f]+$/ { print $3 }' "$t_cache" | head -n 500 | sort -u >"$ap_dir/ioc.sha"
            ap_files=0
            if [ -s "$ap_dir/ioc.sha" ]; then
                {
                    cut -f1 "$ap_dir/cur"
                    ap_ds=()
                    for ap_d in /tmp /var/tmp /dev/shm /usr/local /opt /root /home /srv /var/www; do [ -d "$ap_d" ] && ap_ds+=("$ap_d"); done
                    [ "${#ap_ds[@]}" -gt 0 ] && timeout 60 nice -n 19 find "${ap_ds[@]}" -xdev -maxdepth 6 -type f -size -50M 2>/dev/null
                } 2>/dev/null | awk '!s[$0]++' | head -n 20000 >"$ap_dir/ioc.files"
                ap_files=$(wc -l <"$ap_dir/ioc.files")
                tr '\n' '\0' <"$ap_dir/ioc.files" | timeout 120 nice -n 19 xargs -0 -r sha256sum -- 2>/dev/null \
                    | awk 'NR == FNR { w[$1] = 1; next } ($1 in w) { s = $1; $1 = ""; sub(/^ +\*?/, ""); print s "\t" $0 }' "$ap_dir/ioc.sha" - | head -n 100 >"$ap_dir/ioc.hits"
                while IFS=$'\t' read -r ap_s ap_f; do
                    ap_hits+="{\"t\":\"sha\",\"v\":\"$ap_s\",\"p\":\"$(json_escape "$ap_f")\"},"; ap_nh=$((ap_nh + 1))
                done <"$ap_dir/ioc.hits"
            fi
            rm -f "$ap_dir/ioc.sha" "$ap_dir/ioc.files" "$ap_dir/ioc.hits"
            ap_secs=$(( $(date +%s) - ap_t0 ))
            ioc_json="{\"id\":$ap_hunt,\"files\":$ap_files,\"secs\":$ap_secs,\"hits\":[${ap_hits%,}]}"
            if [ "$ap_nh" -gt 0 ]; then
                act_log ioc 0 "Sporen gevonden" "$ap_nh treffers · $ap_files bestanden doorzocht in $ap_secs s"
            else
                act_log ioc 2 "Gezocht naar sporen" "geen treffers · $ap_files bestanden doorzocht in $ap_secs s"
            fi
        fi
    fi
fi
