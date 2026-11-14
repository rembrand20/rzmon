#!/usr/bin/env bash
# Agenttest Suricata op het proxynetwerk (agent 1.101.0): met een nep-podman. De agent vindt de netwerkbrug van de proxy
# (hier "lo", die bestaat altijd), start niet bij te weinig geheugen, start de container met --network host op die brug,
# schrijft het regelprofiel (web zonder malwaregroepen, full zonder uitzonderingen), werkt de regels op de achtergrond bij en
# herstart daarna, leest de meldingen uit eve.json, maakt een te groot eve.json leeg, en haalt de container weg als hij uit gaat.
# Alleen als root; anders overgeslagen. Gebruik: bash tests/agent-nids.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ]; then echo "• Suricata (proxynetwerk): overgeslagen (geen root)"; exit 0; fi
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/cache" "$work/state"
cat >"$work/bin/podman" <<EOF
#!/usr/bin/env bash
S="$work/state"; N="$work/nids"
echo "\$*" >>"$work/podman.log"
case "\$1" in
    inspect)
        if [ "\$2" = -f ]; then
            case "\$3" in
                *State.Running*) [ -f "\$S/ctr" ] || exit 1; [ -f "\$S/stopped" ] && echo false || echo true ;;
                *State.Status*) echo "exited 1" ;;
                *NetworkSettings.Networks*) echo "swagnet rzsense " ;;
            esac
        else [ "\$2" = rzsense-suricata ] && { [ -f "\$S/ctr" ] || exit 1; }; fi ;;
    network) [ "\$2" = inspect ] && echo lo ;;
    run) touch "\$S/ctr"; printf '%s\n' "\$*" >"\$S/run"; mkdir -p "\$N/log"; : >"\$N/log/suricata.log"; : >"\$N/log/eve.json" ;;
    rm) rm -f "\$S/ctr" ;;
    restart) touch "\$S/restarted" ;;
    logs) printf 'i: suricata: starting\nE: af-packet: podman3: failed to open socket\n' ;;
    start) [ -f "\$S/startok" ] && rm -f "\$S/stopped"; touch "\$S/started" ;;
    exec) [ "\$3" = suricata-update ] && { mkdir -p "\$N/lib/rules"; printf 'alert tcp any any -> any any (sid:1;)\nalert tcp any any -> any any (sid:2;)\n' >"\$N/lib/rules/suricata.rules"; } ;;
esac
exit 0
EOF
chmod +x "$work/bin/podman"
awk '/^# -+ Suricata op het proxynetwerk/{f=1} f&&/^# -+ /&&!/Suricata op het proxynetwerk/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
fn() { awk -v n="$1" '$0 ~ "^" n "\\(\\) \\{" {f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh; }
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'; act_n=0; NIDS_DIR='$work/nids'"
    echo "PATH='$work/bin':\$PATH; px_kind=swag; px_eng=podman; px_ctr=swag"
    fn json_escape; fn act_log; fn edr_eve_awk
    echo ". '$work/mod.sh'"
    echo 'printf "%s" "$nids_json"'
} >"$work/run.sh"
run() { bash "$work/run.sh" >"$work/r-$1.json" 2>>"$work/err"; }
printf '0\tnids\ton\tweb\tauto\n' >"$work/cache/tests.cache"
NIDS_MIN_MB=99999999 run lowmem
NIDS_MIN_MB=1 run start
cp "$work/nids/etc/disable.conf" "$work/dis-web"
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -f "$work/nids/update.rc" ] && break; sleep 0.3; done
printf '{"timestamp":"2026-11-14T10:00:00","event_type":"alert","src_ip":"198.51.100.4","src_port":4444,"dest_ip":"10.89.0.5","dest_port":80,"proto":"TCP","alert":{"action":"allowed","signature_id":2012345,"signature":"ET WEB_SERVER SQL Injection","category":"Web Application Attack","severity":1}}\n{"event_type":"flow"}\n' >"$work/nids/log/eve.json"
NIDS_MIN_MB=1 run alerts
printf '0\tnids\ton\tfull\tlo,nope0\n' >"$work/cache/tests.cache"
NIDS_MIN_MB=1 NIDS_EVE_MB=0 run full
cp "$work/nids/etc/disable.conf" "$work/dis-full"
# 1.101.3: container gestopt: reden + logregels tonen, hooguit elke 10 minuten opnieuw starten
touch "$work/state/stopped"
NIDS_MIN_MB=1 run stopped
rm -f "$work/state/started"; NIDS_MIN_MB=1 run stopped2
[ -f "$work/state/started" ] && bad_retry=1 || bad_retry=0
rm -f "$work/nids/start.at"; touch "$work/state/startok"
NIDS_MIN_MB=1 run restarted
printf '0\tnids\toff\tweb\tauto\n' >"$work/cache/tests.cache"
run off
bad=""
[ "$bad_retry" = 1 ] && bad+="binnen 10 minuten opnieuw gestart | "
python3 - "$work" <<'PY' || bad+="$(cat "$work/py.err" 2>/dev/null) | "
import json, sys, os
w = sys.argv[1]
err = open(f'{w}/py.err', 'w')
try:
    r = {k: json.load(open(f'{w}/r-{k}.json')) for k in ('lowmem', 'start', 'alerts', 'full', 'stopped', 'restarted', 'off')}
    st = r['stopped']
    assert st['state'] == 'error' and 'exited 1' in st['msg'] and 'failed to open socket' in st['msg'] and 'podman logs' in st['msg'], st
    assert r['restarted']['state'] == 'starting' and r['restarted']['msg'] == 'opnieuw gestart', r['restarted']
    assert r['lowmem']['state'] == 'lowmem', r['lowmem']
    s = r['start']
    assert s['state'] in ('ok', 'starting') and s['ifaces'] == ['lo'] and s['found'] == ['lo'] and s['profile'] == 'web', s
    dw = open(f'{w}/dis-web').read()
    assert 'group:emerging-malware.rules' in dw and 'group:emerging-policy.rules' in dw and 'emerging-web_server' not in dw and 'emerging-exploit.rules' not in dw, dw
    a = r['alerts']
    assert a['rules'] == 2 and len(a['alerts']) == 1 and a['alerts'][0][1] == '198.51.100.4' and a['alerts'][0][6] == 2012345 and a['alerts'][0][9] == 1, a
    assert os.path.exists(f'{w}/state/restarted'), 'na het bijwerken van de regels herstart'
    f = r['full']
    assert f['ifaces'] == ['lo'] and f['profile'] == 'full' and os.path.getsize(f'{w}/nids/log/eve.json') == 0, f
    assert 'group:' not in open(f'{w}/dis-full').read(), 'full = niets uit'
    assert r['off']['state'] == 'off', r['off']
    run = open(f'{w}/state/run').read()
    assert '--network host' in run and '-i lo' in run and 'net_raw' in run and 'disable.conf' in run and 'nope0' not in run, run
    assert 'rm -f rzsense-suricata' in open(f'{w}/podman.log').read()
except AssertionError as e:
    err.write(str(e)[:1500]); sys.exit(1)
PY
grep -q 'Suricata (proxynetwerk) gestart' "$work/cache/act.log" && grep -q 'opnieuw gestart' "$work/cache/act.log" && grep -q 'Suricata (proxynetwerk) uit' "$work/cache/act.log" || bad+="logboek mist regels | "
if [ -n "$bad" ]; then echo "✘ Suricata (proxynetwerk): ${bad% | } $(tail -n 3 "$work/err" 2>/dev/null)"; exit 1; fi
echo "✔ Suricata (proxynetwerk): netwerkbrug van de proxy gevonden (niet die van de WAF), te weinig geheugen = niet starten, --network host op de brug, regelprofiel (web/full), regels op de achtergrond bijgewerkt en herstart, meldingen uit eve.json, groot eve.json geleegd, gestopt = reden en logregels + na 10 minuten opnieuw starten, onbekende interface genegeerd, uit = container weg, in het logboek"
