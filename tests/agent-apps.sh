#!/usr/bin/env bash
# Agenttest programma's en jagen (agent 1.91.0, GitHub Actions per Linux-versie): wat draait krijgt een vingerafdruk en
# een pakket, een eigen programma (niet uit een pakket) valt op, een tweede ronde stuurt niets opnieuw, een geblokkeerd
# programma wordt gestopt, en jagen vindt een bestand op vingerafdruk en een pad. Alleen als root; anders overgeslagen.
# Gebruik: bash tests/agent-apps.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ]; then echo "• programma's: overgeslagen (geen root)"; exit 0; fi
work=$(mktemp -d)
pid=""
trap '[ -n "$pid" ] && kill "$pid" 2>/dev/null; rm -rf "$work"' EXIT
mkdir -p "$work/cache"
awk '/^# -+ Onbekende programma/{f=1} f&&/^# -+ /&&!/Onbekende programma/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'"
    echo 'lap() { :; }'
    awk '/^json_escape\(\) \{/{f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh
    awk '/^json_text\(\) \{/{f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh
    awk '/^act_n=0/{f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh
    echo ". '$work/mod.sh'"
    echo 'printf "%s\n%s" "$apps_json" "$ioc_json"'
} >"$work/run.sh"
# Een eigen programma: een kopie van python3 op een plek zonder pakket (sleep is op Alpine en Rocky een multi-call-
# programma dat onder een andere naam niet start)
mkdir -p /usr/local/libexec
cp "$(readlink -f "$(command -v python3)")" /usr/local/libexec/rzsense-test-prog
/usr/local/libexec/rzsense-test-prog -c 'import time; time.sleep(300)' &
pid=$!; disown "$pid" 2>/dev/null
sleep 1
printf '0\tapps\t0\n' >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r1.json" 2>"$work/err" || { echo "✘ programma's: eerste ronde faalde"; cat "$work/err"; exit 1; }
bash "$work/run.sh" >"$work/r2.json" 2>>"$work/err"
sha=$(sha256sum /usr/local/libexec/rzsense-test-prog | cut -d' ' -f1)
printf '0\tapps\t1\n0\tappblock\t%s\n0\tiochunt\t7\n0\tiocsha\t%s\n0\tiocpath\t%s\n0\tiocpath\t%s\n' "$sha" "$sha" \
    "$(printf '/etc/passwd' | base64)" "$(printf '/geen/bestaand/pad' | base64)" >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r3.json" 2>>"$work/err"
sleep 1
alive=0; kill -0 "$pid" 2>/dev/null && alive=1
bash "$work/run.sh" >"$work/r4.json" 2>>"$work/err"
python3 - "$work" "$sha" "$alive" <<'PY'
import json, os, sys
w, sha, alive = sys.argv[1], sys.argv[2], sys.argv[3] == '1'
def rd(i):
    a, _, b = open(f'{w}/r{i}.json').read().partition('\n')
    return json.loads(a) if a else {}, json.loads(b) if b else {}
(a1, _), (a2, _), (a3, h3), (a4, h4) = rd(1), rd(2), rd(3), rd(4)
bad = []
mine = [x for x in a1.get('new', []) if x['p'] == '/usr/local/libexec/rzsense-test-prog']
if not mine or mine[0]['sha'] != sha or mine[0]['pkg'] != '':
    bad.append('eigen programma niet (goed) gezien: %r' % mine)
withpkg = [x for x in a1.get('new', []) if x['pkg']]
if (os.path.exists('/var/lib/dpkg/info') or os.path.exists('/var/lib/rpm') or os.path.exists('/lib/apk/db')) and not withpkg:
    bad.append('geen enkel programma met een pakket: %r' % a1.get('new', [])[:3])
if a2.get('new'):
    bad.append('tweede ronde stuurde opnieuw: %r' % a2['new'][:3])
if sha not in a1.get('run', []):
    bad.append('eigen programma staat niet bij "run"')
if not a3.get('blk') or a3['blk'][0].get('killed', 0) < 1 or alive:
    bad.append('geblokkeerd programma niet gestopt: %r alive=%r' % (a3.get('blk'), alive))
hits = h3.get('hits', [])
if h3.get('id') != 7 or not any(x['t'] == 'path' and x['v'] == '/etc/passwd' for x in hits) or not any(x['t'] == 'sha' and x['v'] == sha for x in hits) \
        or any(x['v'] == '/geen/bestaand/pad' for x in hits):
    bad.append('jagen: %r' % h3)
if h4:
    bad.append('jagen liep twee keer: %r' % h4)
log = [json.loads(l) for l in open(f'{w}/cache/act.log')] if os.path.exists(f'{w}/cache/act.log') else []
acts = [l['a'] for l in log]
for want in ('Geblokkeerd programma gestopt', 'Sporen gevonden'):
    if want not in acts:
        bad.append(f'logboek mist "{want}": {acts}')
if bad:
    print("✘ programma's: " + ' | '.join(bad)); sys.exit(1)
print("✔ programma's: vingerafdruk en pakket, eigen programma herkend, niets dubbel, geblokkeerd = gestopt, jagen vindt pad en vingerafdruk (één keer), alles in het logboek")
PY
rc=$?
rm -f /usr/local/libexec/rzsense-test-prog
exit $rc
