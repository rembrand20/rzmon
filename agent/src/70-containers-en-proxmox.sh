# ---------------------------------------------------------------- Podman: containers, pods en opslag
lap containers
# Per context: versie, containers (status, health, herstarts, grootte, volumes), pods en opslag.
# Volumegroottes (du) worden een uur bewaard: die kunnen groot zijn en veranderen langzaam.
podman_json=""
# Agent 1.69.0: Docker in dezelfde regels als Podman (V versie, C containers, P compose-projecten als pods, D opslag, M volumes)
docker_lines() {
    local ver ids
    ver=$(pm docker version --format '{{.Server.Version}}' 2>/dev/null) || { printf 'E|docker|%s\n' "docker gaf geen antwoord"; return; }
    printf 'V|docker|docker %s\n' "$ver"
    ids=$(pm docker ps -aq --no-trunc 2>/dev/null)
    [ -n "$ids" ] || return
    # shellcheck disable=SC2086
    declare -A d_ins=()
    while IFS='|' read -r d_id d_exit d_rest d_start d_vols; do
        d_ins[$d_id]="$d_exit|$d_rest|$(date -d "$d_start" +%s 2>/dev/null || echo 0)"
        printf 'M|docker|%s|%s\n' "$d_id" "$d_vols"
    done < <(pm docker inspect --format '{{.Id}}|{{.State.ExitCode}}|{{.RestartCount}}|{{.State.StartedAt}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}' $ids 2>/dev/null)
    while IFS='|' read -r d_id d_name d_img d_state d_status d_proj d_size; do
        IFS='|' read -r d_exit d_rest d_start <<<"${d_ins[$d_id]:-0|0|0}"
        printf 'C|docker|%s|%s|%s|%s|%s|%s|%s|%s|false|%s|%s\n' "$d_id" "$d_name" "$d_img" "$d_state" "$d_status" "$d_exit" "$d_rest" "$d_proj" "$d_start" "$d_size"
    done < <(pm docker ps -a --size --no-trunc --format '{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.Label "com.docker.compose.project"}}|{{.Size}}' 2>/dev/null)
    pm docker ps -a --format '{{.Label "com.docker.compose.project"}}|{{.State}}' 2>/dev/null | awk -F'|' '$1 != "" { n[$1]++; if ($2 == "running") r[$1]++ }
        END { for (p in n) printf "P|docker|%s|%s|%d\n", p, (r[p] == n[p] ? "Running" : r[p] ? "Degraded" : "Exited"), n[p] }'
    pm docker system df --format '{{.Type}}|{{.Size}}|{{.Reclaimable}}|{{.TotalCount}}|{{.Active}}' 2>/dev/null |
        awk -F'|' "$docker_bytes"'{ printf "D|docker|%s|%.0f|%.0f|%s|%s\n", $1, db($2), db($3), $4, $5 }'
}
if [ ${#podman_ctx[@]} -gt 0 ]; then
    pod_stats_b=$(podman_stats b)
    vol_cache_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
    mkdir -p "$vol_cache_dir" 2>/dev/null && [ -w "$vol_cache_dir" ] || vol_cache_dir="${TMPDIR:-/tmp}"
    vol_cache="$vol_cache_dir/volumes.cache"
    now_s=$(date +%s)
    podman_raw=$(
        printf '%s\n' "$pod_stats_a" | sed 's/^S|/A|/'
        printf '%s\n' "$pod_stats_b" | sed 's/^S|/B|/'
        for ctx in "${podman_ctx[@]}"; do
            if [ "$ctx" = docker ]; then
                docker_lines
                continue
            fi
            if ! ver=$(pm "$ctx" version --format '{{.Client.Version}}' 2>/dev/null); then
                # Podman zet waarschuwingen vóór de echte fout; die echte fout is wat telt
                out=$(pm "$ctx" version 2>&1); rc=$?
                err=$(grep -m1 -iE 'error|denied|not permitted|read-only' <<<"$out" | sed 's/^time="[^"]*" level=[a-z]* msg="//; s/"$//')
                home=$(getent passwd "$ctx" | cut -d: -f6)
                if [ "$ctx" != root ] && [ -n "${INVOCATION_ID-}" ] && [ -n "$home" ] && ! [ -w "$home" ]; then
                    # systemd-service met ProtectHome=read-only (agent geïnstalleerd vóór podman)
                    err="home-map is alleen-lezen voor de agent; los op met: sudo rzmon-agent-setup --update${err:+ ($err)}"
                elif [ "$rc" = 124 ]; then
                    err="podman gaf binnen 60 s geen antwoord"
                elif [ -z "$err" ]; then
                    err=$(grep -v '^[[:space:]]*$' <<<"$out" | tail -1 | sed 's/^time="[^"]*" level=[a-z]* msg="//; s/"$//')
                    err="${err:-podman stopte met code $rc}"
                fi
                printf 'E|%s|%s\n' "$ctx" "$(tr '|' ' ' <<<"$err" | cut -c1-200)"
                continue
            fi
            printf 'V|%s|%s\n' "$ctx" "$ver"
            pm "$ctx" ps -a --pod --size --no-trunc --format '{{.ID}}|{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.ExitCode}}|{{.Restarts}}|{{.PodName}}|{{.IsInfra}}|{{.StartedAt}}|{{.Size}}' 2>/dev/null \
                | sed "s/^/C|$ctx|/"
            pm "$ctx" pod ps --format '{{.Name}}|{{.Status}}|{{.NumberOfContainers}}' 2>/dev/null | sed "s/^/P|$ctx|/"
            pm "$ctx" system df --format '{{.Type}}|{{.RawSize}}|{{.RawReclaimable}}|{{.Total}}|{{.Active}}' 2>/dev/null | sed "s/^/D|$ctx|/"
            ids=$(pm "$ctx" ps -aq --no-trunc 2>/dev/null)
            if [ -n "$ids" ]; then
                # shellcheck disable=SC2086
                pm "$ctx" inspect --format '{{.ID}}|{{range .Mounts}}{{if eq .Type "volume"}}{{.Source}};{{end}}{{end}}' $ids 2>/dev/null \
                    | sed "s/^/M|$ctx|/"
            fi
        done
    )
    # Volumegroottes: uit de cache als die minder dan een uur oud is
    vols=$(grep '^M|' <<<"$podman_raw" | cut -d'|' -f4 | tr ';' '\n' | grep -v '^$' | sort -u)
    if [ -n "$vols" ]; then
        if [ -r "$vol_cache" ] && [ $(( now_s - $(head -1 "$vol_cache") )) -lt 3600 ]; then
            vol_sizes=$(tail -n +2 "$vol_cache")
        else
            vol_sizes=$(while IFS= read -r v; do
                printf 'Z|%s|%s\n' "$v" "$(du -sb "$v" 2>/dev/null | cut -f1)"
            done <<<"$vols")
            (umask 077; { echo "$now_s"; echo "$vol_sizes"; } >"$vol_cache") 2>/dev/null || true
        fi
        podman_raw+=$'\n'"$vol_sizes"
    fi
    podman_json=$(awk -F'|' -v now="$now_s" '
        function esc(s) { gsub(/\\/, "&&", s); gsub(/"/, "\\\"", s); return s }
        function num(v) { return (v ~ /^[0-9]+(\.[0-9]+)?$/) ? v : 0 }
        # "11.8kB (virtual 8.12MB)" → bytes (podman gebruikt SI: kB = 1000)
        function bytes(s,   n, u) {
            if (!match(s, /^[0-9.]+[a-zA-Z]*/)) return 0
            s = substr(s, 1, RLENGTH); n = s + 0; u = s; sub(/^[0-9.]+/, "", u); u = tolower(u)
            return n * (u == "kb" ? 1e3 : u == "mb" ? 1e6 : u == "gb" ? 1e9 : u == "tb" ? 1e12 : 1)
        }
        $1 == "A" { a_cpu[$3] = $4; a_sys[$3] = $5 }
        $1 == "B" { b_cpu[$3] = $4; b_sys[$3] = $5; mem[$3] = $6; lim[$3] = $7; bi[$3] = $9; bo[$3] = $10; pids[$3] = $11
                    split($8, nio, " / "); ni[$3] = sprintf("%.0f", bytes(nio[1])); no[$3] = sprintf("%.0f", bytes(nio[2])) }
        $1 == "V" { if (version == "") version = $3; ctxs = ctxs (ctxs ? "," : "") "\"" esc($2) "\"" }
        $1 == "E" { errs = errs (errs ? "; " : "") $2 ": " $3 }
        $1 == "C" { n++; c_ctx[n] = $2; c_id[n] = $3; c_line[n] = $0 }
        $1 == "P" { pods = pods sprintf("{\"name\":\"%s\",\"user\":\"%s\",\"status\":\"%s\",\"containers\":%d},", esc($3), esc($2), esc($4), num($5)) }
        $1 == "D" { t = tolower($3); df_size[t] += num($4); reclaim += num($5); if (t == "images") images += num($6) }
        $1 == "M" { cvol[$3] = $4 }
        $1 == "Z" { vsize[$2] = num($3) }
        END {
            out = ""
            for (i = 1; i <= n; i++) {
                split(c_line[i], f, "|")
                if (f[11] == "true") continue            # infra-container van een pod
                id = f[3]; health = ""
                if (f[7] ~ /\(healthy\)/) health = "healthy"; else if (f[7] ~ /\(unhealthy\)/) health = "unhealthy"; else if (f[7] ~ /\(starting\)/) health = "starting"
                cpu = "null"
                if ((id in a_cpu) && (id in b_cpu) && b_sys[id] > a_sys[id])
                    cpu = sprintf("%.2f", (b_cpu[id] - a_cpu[id]) / (b_sys[id] - a_sys[id]) * 100)
                vb = 0; nv = split(cvol[id], vl, ";")
                for (j = 1; j <= nv; j++) if (vl[j] != "") vb += vsize[vl[j]]
                out = out sprintf("{\"name\":\"%s\",\"user\":\"%s\",\"pod\":\"%s\",\"image\":\"%s\",\"state\":\"%s\",\"status\":\"%s\",\"health\":\"%s\",\"exit_code\":%d,\"restarts\":%d,\"started\":%.0f,\"cpu\":%s,\"mem\":%s,\"mem_limit\":%s,\"net_in\":%s,\"net_out\":%s,\"blk_in\":%s,\"blk_out\":%s,\"pids\":%s,\"size_rw\":%.0f,\"volumes\":%.0f},",
                    esc(f[4]), esc(f[2]), esc(f[10]), esc(f[5]), esc(f[6]), esc(f[7]), health, num(f[8]), num(f[9]), num(f[12]), cpu,
                    (id in mem) ? num(mem[id]) : "null", (id in lim) ? num(lim[id]) : "null", (id in ni) ? num(ni[id]) : "null",
                    (id in no) ? num(no[id]) : "null", (id in bi) ? num(bi[id]) : "null", (id in bo) ? num(bo[id]) : "null",
                    (id in pids) ? num(pids[id]) : "null", bytes(f[13]), vb)
            }
            sub(/,$/, "", out); sub(/,$/, "", pods)
            printf "{\"version\":\"%s\",\"error\":\"%s\",\"users\":[%s],\"images\":%d,\"images_size\":%.0f,\"containers_size\":%.0f,\"volumes_size\":%.0f,\"reclaimable\":%.0f,\"pods\":[%s],\"containers\":[%s]}",
                esc(version), esc(errs), ctxs, images, df_size["images"], df_size["containers"], df_size["local volumes"], reclaim, pods, out
        }' <<<"$podman_raw")
fi

# ---------------------------------------------------------------- Proxmox VE (agent 1.61.0)
lap proxmox
# Alleen op een Proxmox-host, als root: guests (VM's en containers), opslag, ZFS-pools, de cluster, back-ups per guest en de
# back-uptaken. pvesh geeft JSON; dat gaat ongewijzigd mee (RzSense leest het), dus jq is niet nodig.
# Guests en opslag elke 2 minuten, back-ups en taken elk kwartier. PVE=0 in de instellingen zet het uit.
pve_json=""
if [ "$HA_MODE" = 0 ] && [ "${PVE:-1}" != 0 ] && command -v pvesh >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
    pve_dir="$t_dir/pve"
    mkdir -p "$pve_dir" 2>/dev/null
    pve_node=$(hostname -s)   # Proxmox noemt de node naar de korte hostnaam
    pj() {  # pj <tijd> <pad> [opties…]: JSON van pvesh, of null
        local to=$1 o; shift
        o=$(timeout "$to" pvesh get "$@" --output-format json 2>/dev/null)
        case "$o" in '['*|'{'*) printf '%s' "$o" ;; *) printf 'null' ;; esac
    }
    if ! due_cached "$pve_dir/live" 120; then
        # Uit de configuratiebestanden: automatisch starten, guest agent aan, sjabloon (alleen het deel vóór [snapshots])
        pve_conf=$(for f in /etc/pve/qemu-server/*.conf /etc/pve/lxc/*.conf; do
            [ -f "$f" ] || continue
            awk -v id="$(basename "$f" .conf)" -v t="$([[ "$f" == */lxc/* ]] && echo lxc || echo qemu)" '
                /^\[/ { exit } /^onboot: *1/ { ob = 1 } /^agent: *(1|enabled=1)/ { ag = 1 } /^template: *1/ { tp = 1 }
                END { if (id ~ /^[0-9]+$/) printf "[\"%s\",%s,%d,%d,%d],", t, id, ob, ag, tp }' "$f"
        done)
        # ZFS-pools: gezondheid, vulling en de laatste scrub
        pve_zfs=""
        if command -v zpool >/dev/null 2>&1; then
            while IFS=$'\t' read -r z_name z_health z_size z_alloc z_cap; do
                [ -n "$z_name" ] || continue
                z_scan=$(timeout 10 zpool status "$z_name" 2>/dev/null | sed -n 's/^ *scan: *//p' | head -1)
                pve_zfs+="{\"name\":\"$(json_escape "$z_name")\",\"health\":\"$(json_escape "$z_health")\",\"size\":${z_size:-0},\"alloc\":${z_alloc:-0},\"cap\":${z_cap%\%},\"scan\":\"$(json_escape "$z_scan")\"},"
            done < <(timeout 10 zpool list -Hp -o name,health,size,alloc,capacity 2>/dev/null)
        fi
        printf '"node":"%s","version":"%s","qemu":%s,"lxc":%s,"storage":%s,"cluster":%s,"conf":[%s],"zfs":[%s],' \
            "$(json_escape "$pve_node")" "$(json_escape "$(pveversion 2>/dev/null | head -1)")" \
            "$(pj 20 "/nodes/$pve_node/qemu")" "$(pj 20 "/nodes/$pve_node/lxc")" "$(pj 30 "/nodes/$pve_node/storage")" \
            "$(pj 15 /cluster/status)" "${pve_conf%,}" "${pve_zfs%,}" >"$pve_dir/live.tmp" && mv -f "$pve_dir/live.tmp" "$pve_dir/live"
    fi
    if ! due_cached "$pve_dir/slow" 900; then
        # Back-ups per guest: de nieuwste per VMID uit elke opslag met back-ups (vzdump-bestanden en Proxmox Backup Server)
        pve_bk=$(for st in $(timeout 20 pvesm status --content backup 2>/dev/null | awk 'NR > 1 && $3 == "active" { print $1 }'); do
            [[ "$st" =~ ^[A-Za-z0-9._-]+$ ]] || continue
            timeout 60 pvesm list "$st" --content backup 2>/dev/null | awk -v st="$st" 'NR > 1 { print st "\t" $1 "\t" $NF }'
        done | awk -F'\t' '
            {
                id = $3; d = ""
                # (geen {4} in de patronen: mawk op Debian kent dat niet altijd)
                if (match($2, /[0-9][0-9][0-9][0-9]_[0-9][0-9]_[0-9][0-9]-[0-9][0-9]_[0-9][0-9]_[0-9][0-9]/)) { d = substr($2, RSTART, RLENGTH); gsub(/_/, "-", d); d = substr(d, 1, 10) " " substr(d, 12, 2) ":" substr(d, 15, 2) ":" substr(d, 18, 2) }
                else if (match($2, /[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z/)) { d = substr($2, RSTART, 10) " " substr($2, RSTART + 11, 8) "Z" }
                if (id !~ /^[0-9]+$/ || d == "") next
                n[id]++; if (d > last[id]) { last[id] = d; where[id] = $1 }
            }
            END { for (id in n) printf "\"%s\":[\"%s\",%d,\"%s\"],", id, last[id], n[id], where[id] }')
        printf '"backups":{%s},"jobs":%s,"tasks":%s,' "${pve_bk%,}" "$(pj 15 /cluster/backup)" \
            "$(pj 20 "/nodes/$pve_node/tasks" --typefilter vzdump --limit 30)" >"$pve_dir/slow.tmp" && mv -f "$pve_dir/slow.tmp" "$pve_dir/slow"
        # Guest agent: antwoordt hij (alleen draaiende VM's met de agent aan)?
        pve_ga=""
        for f in /etc/pve/qemu-server/*.conf; do
            [ -f "$f" ] || continue
            id=$(basename "$f" .conf)
            awk '/^\[/ { exit } /^agent: *(1|enabled=1)/ { f = 1 } END { exit !f }' "$f" || continue
            [ -e "/var/run/qemu-server/$id.pid" ] || continue
            if timeout 4 qm agent "$id" ping >/dev/null 2>&1; then pve_ga+="\"$id\":1,"; else pve_ga+="\"$id\":0,"; fi
        done
        printf '"gagent":{%s},' "${pve_ga%,}" >>"$pve_dir/slow"
    fi
    pve_json="{$(cat "$pve_dir/live" "$pve_dir/slow" 2>/dev/null)\"at\":$(date +%s)}"
fi

