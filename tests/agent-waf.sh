#!/usr/bin/env bash
# Agenttest WAF op de reverse proxy (agent 1.98.0): met een nep-podman die een CrowdSec-container nadoet. De agent maakt het
# netwerk rzsense, hangt SWAG eraan, maakt de sleutel (alleen root), schrijft de regels per stand van de slider (detecteren,
# alleen zeker, streng), per site (alleen melden, uit) en de uitzonderingen, start de container, test met een nep-aanval of hij
# doet wat de slider zegt, ziet de koppeling met SWAG en stuurt de meldingen mee. Uit: de container weg.
# Alleen als root; anders overgeslagen. Gebruik: bash tests/agent-waf.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ]; then echo "• WAF: overgeslagen (geen root)"; exit 0; fi
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/cache" "$work/state" "$work/config/nginx"
# Nep-podman: onthoudt de container en het netwerk; wget in de container antwoordt volgens de regels die de agent schreef
cat >"$work/bin/podman" <<EOF
#!/usr/bin/env bash
S="$work/state"; W="$work/waf"
echo "\$*" >>"$work/podman.log"
case "\$1" in
    inspect)
        if [ "\$2" = -f ]; then
            case "\$3" in
                *State.Running*) [ -f "\$S/ctr" ] && echo true || exit 1 ;;
                *Networks*) if [ -f "\$S/connected" ]; then echo '{"podman":{},"rzsense":{}}'; else echo '{"podman":{}}'; fi ;;
            esac
        else [ "\$2" = rzsense-crowdsec ] && { [ -f "\$S/ctr" ] || exit 1; }; fi ;;
    network)
        case "\$2" in inspect) [ -f "\$S/net" ] || exit 1 ;; create) touch "\$S/net" ;; connect) touch "\$S/connected" ;; esac ;;
    run) touch "\$S/ctr"; printf '%s\n' "\$*" >"\$S/run" ;;
    rm) rm -f "\$S/ctr" ;;
    restart) touch "\$S/restarted" ;;
    exec)
        shift 2
        case "\$1" in
            wget)
                uri=\$(printf '%s\n' "\$@" | sed -n 's/^X-Crowdsec-Appsec-Uri: //p')
                inb=\$(sed -n '/^inband_rules:/,/^outofband_rules:/p' "\$W/rzsense.yaml")
                code=200
                if [[ "\$uri" == *eval-stdin* ]] && grep -q 'vpatch' <<<"\$inb"; then code=403; fi
                if [[ "\$uri" == *%27* ]] && grep -q 'crowdsecurity/crs' <<<"\$inb"; then code=403; fi
                echo "  HTTP/1.1 \$code X" >&2; [ "\$code" = 200 ] ;;
            cscli)
                case "\$2" in
                    bouncers) echo '[{"name": "swag", "ip_address": "10.89.0.2", "last_pull": "2026-11-14T10:00:00Z"}]' ;;
                    alerts) echo '[{"id": 7, "scenario": "crowdsecurity/vpatch-CVE-2017-9841", "source": {"ip": "198.51.100.7"}, "events": [{"meta": [{"key": "target_uri", "value": "/vendor/phpunit/x/eval-stdin.php"}, {"key": "target_host", "value": "blog.example.test"}]}]}]' ;;
                esac ;;
        esac ;;
esac
exit 0
EOF
chmod +x "$work/bin/podman"
awk '/^# -+ WAF op de reverse proxy/{f=1} f&&/^# -+ /&&!/WAF op de reverse proxy/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
fn() { awk -v n="$1" '$0 ~ "^" n "\\(\\) \\{" {f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh; }
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'; act_n=0; WAF_DIR='$work/waf'"
    echo "PATH='$work/bin':\$PATH; px_kind=swag; px_eng=podman; px_ctr=swag; px_root='$work/config/nginx'"
    echo 'lap() { :; }'
    fn json_escape; fn act_log
    echo ". '$work/mod.sh'"
    echo 'printf "%s" "$waf_json"'
} >"$work/run.sh"
lines() {  # lines <stand>
    printf '0\tproxylan\t192.168.1.0/24 10.8.0.5\n0\twaf\ton\t%s\t0\n' "$1"
    printf '0\twafsite\tnextcloud.*\tdetect\n0\twafsite\t*.intern.example.test\toff\n'
    printf '0\twafex\tnextcloud.*\t942100\n0\twafex\tblog.example.test\tcrowdsecurity/vpatch-CVE-2017-9841\n0\twafex\tblog.example.test\t1; rm -rf /\n'
}
bad=""
for m in detect sure strict; do
    lines "$m" >"$work/cache/tests.cache"
    bash "$work/run.sh" >"$work/r-$m.json" 2>>"$work/err"
    cp "$work/waf/rzsense.yaml" "$work/conf-$m.yaml"
done
cp "$work/state/run" "$work/run-args"
printf '0\twaf\toff\tdetect\t0\n' >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r-off.json" 2>>"$work/err"
python3 - "$work" <<'PY' || bad+="$(cat "$work/py.err" 2>/dev/null) | "
import json, sys, os
w = sys.argv[1]
err = open(f'{w}/py.err', 'w')
try:
    r = {m: json.load(open(f'{w}/r-{m}.json')) for m in ('detect', 'sure', 'strict', 'off')}
    for m in ('detect', 'sure', 'strict'):
        assert r[m]['state'] == 'ok' and r[m]['running'] == 1 and r[m]['bouncer'] == 'active', (m, r[m])
        assert r[m]['test'] == 'ok' and r[m]['mode'] == m, (m, r[m]['test'], r[m]['test_msg'])
        alerts = json.loads(__import__('base64').b64decode(r[m]['alerts']))
        assert alerts[0]['id'] == 7, alerts
    assert r['off']['state'] == 'off', r['off']
    c = {m: open(f'{w}/conf-{m}.yaml').read() for m in ('detect', 'sure', 'strict')}
    inb = lambda t: t.split('inband_rules:')[1].split('outofband_rules:')[0]
    assert 'vpatch' not in inb(c['detect']) and 'crs' not in inb(c['detect']), c['detect']
    assert 'vpatch' in inb(c['sure']) and 'crowdsecurity/crs' not in inb(c['sure']), c['sure']
    assert 'vpatch' in inb(c['strict']) and 'crowdsecurity/crs' in inb(c['strict']), c['strict']
    s = c['strict']
    assert 'IpInRange(req.RemoteAddr, "192.168.1.0/24")' in s and 'IpInRange(req.RemoteAddr, "10.8.0.5/32")' in s, s
    assert 'IsInBand && req.Host matches "(^nextcloud\\\\.)"' in s, s
    assert 'req.Host matches "(\\\\.intern\\\\.example\\\\.test$)"' in s, s
    assert 'RemoveInBandRuleByID(942100)' in s and 'RemoveOutBandRuleByName("crowdsecurity/vpatch-CVE-2017-9841")' in s, s
    assert 'rm -rf' not in s, 'ongeldige regel kwam erin'
    try:
        import yaml
        for t in c.values():
            y = yaml.safe_load(t)
            assert y['name'] == 'rzsense/appsec' and isinstance(y['on_match'], list), y
        y = yaml.safe_load(c['strict'])
        assert y['pre_eval'][0]['filter'] == 'req.Host matches "^nextcloud\\.$"' or 'nextcloud' in y['pre_eval'][0]['filter'], y['pre_eval']
        yaml.safe_load(open(f'{w}/waf/whitelist.yaml'))
        yaml.safe_load(open(f'{w}/waf/acquis.d/rzsense-appsec.yaml'))
    except ImportError:
        pass
    run = open(f'{w}/run-args').read()
    assert '--network rzsense' in run and 'BOUNCER_KEY_swag=' in run and 'DISABLE_ONLINE_API=true' in run and ':Z' in run, run
    log = open(f'{w}/podman.log').read()
    assert 'network create rzsense' in log and 'network connect rzsense swag' in log and 'restart rzsense-crowdsec' in log and 'rm -f rzsense-crowdsec' in log, log
    key = f'{w}/config/nginx/rzsense-waf/crowdsec.key'
    assert len(open(key).read()) == 64 and oct(os.stat(key).st_mode)[-3:] == '600', 'sleutel'
except AssertionError as e:
    err.write(str(e)[:1500]); sys.exit(1)
PY
grep -q 'WAF: CrowdSec gestart' "$work/cache/act.log" && grep -q 'WAF uit' "$work/cache/act.log" || bad+="logboek mist regels | "
if [ -n "$bad" ]; then echo "✘ WAF: ${bad% | } $(tail -n 3 "$work/err" 2>/dev/null)"; exit 1; fi
echo "✔ WAF: netwerk en koppeling met SWAG, sleutel alleen voor root, regels per stand van de slider (detecteren, alleen zeker, streng), per site alleen melden of uit, uitzonderingen (ongeldige weg), eigen netwerk nooit, zelftest klopt, meldingen mee, uit = container weg, in het logboek"
