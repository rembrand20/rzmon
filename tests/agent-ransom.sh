#!/usr/bin/env bash
# Agenttest ransomware (agent 1.90.0, GitHub Actions per Linux-versie): lokaasbestanden plaatsen, een "aanval" (versleutelen,
# hernoemen naar .lockbit, een losgeldbriefje, het lokaas wijzigen) herkennen, rustige rondes zonder alarm, en opruimen als
# de bewaking uit gaat (alleen ongewijzigde lokaasbestanden). Draait de module los, met de awk/find/gzip van de distributie.
# Alleen als root (zoals de agent zelf); anders overgeslagen.
# Gebruik: bash tests/agent-ransom.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ]; then echo "• ransomware: overgeslagen (geen root)"; exit 0; fi
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/share/docs" "$work/cache"
for i in $(seq 1 30); do yes "regel $i met gewone tekst die je goed kunt comprimeren" | head -n 300 >"$work/share/docs/doc$i.txt"; done
awk '/^# -+ Ransomware herkennen/{f=1} f&&/^# -+ /&&!/Ransomware/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'"
    echo 'lap() { :; }'
    awk '/^json_escape\(\) \{/{f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh
    awk '/^act_n=0/{f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh
    echo ". '$work/mod.sh'"
    echo 'printf "%s" "$ransom_json"'
} >"$work/run.sh"
printf '0\transom\t1\t0\t1\n0\transompath\t%s\t300\n' "$(printf '%s' "$work/share" | base64 | tr -d '\n')" >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r1.json" 2>"$work/err" || { echo "✘ ransomware: eerste ronde faalde"; cat "$work/err"; exit 1; }
sleep 1
bash "$work/run.sh" >"$work/r2.json" 2>>"$work/err"
sleep 1
for i in $(seq 1 10); do head -c 20000 /dev/urandom >"$work/share/docs/doc$i.txt"; done
for i in 11 12 13 14; do mv "$work/share/docs/doc$i.txt" "$work/share/docs/doc$i.txt.lockbit"; done
echo "betaal" >"$work/share/HOW_TO_DECRYPT.txt"
echo x >>"$work/share/!0-RzSense-lokaas-niet-aanraken.txt"
bash "$work/run.sh" >"$work/r3.json" 2>>"$work/err"
: >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r4.json" 2>>"$work/err"
python3 - "$work" <<'PY'
import json, os, sys
w = sys.argv[1]
r = [json.load(open(f'{w}/r{i}.json')) for i in (1, 2, 3, 4)]
bad = []
p1, p2, p3 = (x['paths'][0] for x in r[:3])
if p1['canary'] != 'ok' or p1['hit']:
    bad.append('ronde 1: lokaas niet geplaatst of onterecht alarm: %r' % p1)
if p2['hit'] or p2['n'] != 0 or p2['canary'] != 'ok':
    bad.append('ronde 2 (rustig): %r' % p2)
cnewer = os.system(f'find {w} -maxdepth 0 -cnewer {w} >/dev/null 2>&1') == 0   # BusyBox-find: geen -cnewer, dan zijn hernoemde bestanden niet te zien
if not p3['hit'] or p3['canary'] != 'alarm' or (cnewer and p3['ext'] < 4) or p3['notes'] < 1 or p3['enc'] < 3:
    bad.append('ronde 3 (aanval) niet goed herkend: %r' % p3)
if r[3].get('on') != 0 or os.path.exists(f'{w}/share/zz-RzSense-lokaas-niet-aanraken.txt') or not os.path.exists(f'{w}/share/!0-RzSense-lokaas-niet-aanraken.txt'):
    bad.append('uitzetten: ongewijzigd lokaas niet weg, of het gewijzigde lokaas toch weggegooid')
log = [json.loads(l) for l in open(f'{w}/cache/act.log')] if os.path.exists(f'{w}/cache/act.log') else []
acts = [l['a'] for l in log]
for want in ('Lokaasbestanden geplaatst', 'Mogelijke ransomware', 'Lokaasbestanden weggehaald'):
    if want not in acts:
        bad.append(f'logboek mist "{want}": {acts}')
if bad:
    print('✘ ransomware: ' + ' | '.join(bad)); sys.exit(1)
print('✔ ransomware: lokaas geplaatst, rustig = geen alarm, aanval herkend (lokaas, extensies, briefje, versleuteld), opruimen bij uitzetten, alles in het logboek')
PY
