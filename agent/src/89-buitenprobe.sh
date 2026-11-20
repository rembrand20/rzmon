# ---------------------------------------------------------------- Meetpunt van buitenaf (agent 1.117.0, RzSense 1.300.0)
# Is deze server in RzSense gekozen als meetpunt (Instellingen → Bescherming → Van buitenaf), dan geeft RzSense regels mee:
# "0 <tab> exprobe <tab> id <tab> ip <tab> poorten". De agent probeert elke poort met een gewone TCP-verbinding (bash /dev/tcp,
# 64 tegelijk, 2 seconden per poort, geen extra programma's, geen gegevens versturen) en stuurt de open poorten terug.
# Eén keer per vraag; na 50 seconden stopt hij met nieuwe adressen (dan "partial"). Alleen openbare adressen.
exprobe_json=""
if [ "$TEST" != 1 ] && [ -r "${t_cache-}" ]; then
    xp_id=$(awk -F'\t' '$1 == "0" && $2 == "exprobe" { print $3; exit }' "$t_cache")
    if [[ "$xp_id" =~ ^[0-9]{1,18}$ ]] && [ "$(cat "$t_dir/exprobe.done" 2>/dev/null)" != "$xp_id" ]; then
        xp_res=""; xp_part=0; xp_end=$(( $(date +%s) + 50 ))
        while IFS=$'\t' read -r _ _ _ xp_ip xp_ports; do
            # Alleen een IP-adres (v4 of v6) en een lijst getallen; privé- en lokale adressen niet
            if [[ ! "$xp_ip" =~ ^[0-9a-fA-F:.]{3,45}$ ]] || [[ ! "$xp_ports" =~ ^[0-9][0-9,]*$ ]] || [ "${#xp_ports}" -gt 12000 ]; then continue; fi
            [[ "$xp_ip" =~ ^(10\.|127\.|0\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|::1$|[fF][cdCD]|[fF][eE]80) ]] && continue
            if [ "$(date +%s)" -ge "$xp_end" ]; then xp_part=1; break; fi
            # shellcheck disable=SC2016  # $0 en $1 horen bij de bash -c hieronder
            xp_open=$(tr ',' '\n' <<<"$xp_ports" | head -2000 | xargs -P 64 -I{} timeout 2 bash -c 'exec 3<>"/dev/tcp/$0/$1" && echo "$1"' "$xp_ip" {} 2>/dev/null | sort -n | paste -sd, -)
            xp_res+="\"$xp_ip\":[${xp_open}],"
        done < <(awk -F'\t' -v id="$xp_id" '$1 == "0" && $2 == "exprobe" && $3 == id' "$t_cache" | head -32)
        exprobe_json="{\"id\":$xp_id,\"partial\":$xp_part,\"res\":{${xp_res%,}}}"
        printf '%s' "$xp_id" >"$t_dir/exprobe.done" 2>/dev/null
    fi
fi
