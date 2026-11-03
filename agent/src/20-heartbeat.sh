# ---------------------------------------------------------------- Heartbeat (rzmon-hb, agent 1.52.0)
# Een script of cronjob meldt zich bij RzMon: rzmon-hb NAAM [ok|start|fail] [bericht] [--value N].
# Er wordt alleen een regel in de wachtrij gezet (HB_DIR, iedereen mag erin schrijven maar niet lezen);
# de agent stuurt hem versleuteld mee met het volgende rapport. Zonder verbinding blijven de regels bewaard.
hb_dir="${HB_DIR:-${AGENT_DIR:+$AGENT_DIR/hb}}"
hb_dir="${hb_dir:-/var/spool/rzmon-agent}"
if [ "$(basename -- "$0")" = rzmon-hb ] || [ "${1-}" = --hb ]; then
    [ "${1-}" = --hb ] && shift
    hb_name=${1-}; hb_kind=${2:-ok}; hb_msg=""; hb_val=""
    [ $# -ge 2 ] && shift 2 || shift $#
    while [ $# -gt 0 ]; do
        case $1 in
            --value) hb_val=${2-}; shift 2 2>/dev/null || shift ;;
            *) hb_msg="${hb_msg:+$hb_msg }$1"; shift ;;
        esac
    done
    case $hb_kind in success|done) hb_kind=ok ;; failure|failed|error) hb_kind=fail ;; begin) hb_kind=start ;; esac
    if ! [[ "$hb_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || [[ " start ok fail " != *" $hb_kind "* ]]; then
        echo "Gebruik: rzmon-hb NAAM [ok|start|fail] [bericht] [--value GETAL]" >&2
        echo "  NAAM = de naam van de heartbeat-check in RzMon (letters, cijfers, punt, - of _), bv. backup-db" >&2
        exit 2
    fi
    if [ -n "$hb_val" ] && ! [[ "$hb_val" =~ ^-?[0-9]+([.][0-9]+)?$ ]]; then
        echo "rzmon-hb: --value moet een getal zijn (bv. 42 of 3.5); genegeerd" >&2; hb_val=""
    fi
    [ -d "$hb_dir" ] || { mkdir -p "$hb_dir" && chmod 1733 "$hb_dir"; } 2>/dev/null
    hb_f=$(mktemp "$hb_dir/hb.XXXXXXXX" 2>/dev/null) || { echo "rzmon-hb: kan niet schrijven in $hb_dir (is de agent geïnstalleerd?)" >&2; exit 1; }
    printf '{"n":"%s","s":"%s","t":%s%s,"m":"%s"}\n' "$hb_name" "$hb_kind" "$(date +%s)" "${hb_val:+,\"v\":$hb_val}" \
        "$(printf '%s' "$hb_msg" | head -c 300 | json_text)" >"$hb_f"
    chmod 644 "$hb_f" 2>/dev/null
    exit 0
fi

t_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
[ "$HA_MODE" = 1 ] && t_dir="${CACHE_DIRECTORY:-/data/rzmon-cache}"   # add-on: /data blijft bewaard
mkdir -p "$t_dir" 2>/dev/null && [ -w "$t_dir" ] || t_dir="${TMPDIR:-/tmp}"
t_cache="$t_dir/tests.cache"

