#!/usr/bin/env bash
# Agenttest statistieken van de reverse proxy (agent 1.102.0): de telling uit het proxylog (rzsense.log-formaat, met site en
# dienst). Per site het aantal per statuscode en slechte bots (403), de aangevallen paden zonder querystring, en nieuwe unieke
# bezoekers van vandaag (een tweede keer dezelfde IP's telt niet). Met de awk van dit systeem (CI: per distro).
set -u
cd "$(dirname "$0")/.." || exit 1
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
# Het awk-programma uit de gebouwde agent halen (tussen "w_stat=$(printf" en het eind van dat commando)
awk '/w_stat=\$\(printf/{f=1} f{print} f&&/}'"'"'\)$/{exit}' agent/rzmon-agent.sh \
    | sed '1s/.*w_stat=\$(printf .%s\\n. "\$w_lines" | /pxstat() { /; $s/)$/; }/' >"$work/fn.sh"
grep -q '^pxstat() {' "$work/fn.sh" || { echo "✘ proxy-statistieken: awk niet gevonden in de agent"; exit 1; }
L='[15/Nov/2026:10:00:00 +0100]'
lines() {
    echo "203.0.113.5 - - $L \"GET /wp-login.php?pwd=geheim HTTP/1.1\" 404 10 \"-\" \"curl\" \"blog.example.nl\" \"ghost:2368\" 0.01"
    echo "203.0.113.5 - - $L \"GET /.env HTTP/1.1\" 403 10 \"-\" \"sqlmap/1.7\" \"blog.example.nl\" \"\" 0.01"
    echo "198.51.100.7 - - $L \"GET / HTTP/1.1\" 200 10 \"-\" \"Mozilla\" \"blog.example.nl\" \"ghost:2368\" 0.01"
    echo "198.51.100.7 - - $L \"GET /api HTTP/1.1\" 429 10 \"-\" \"Mozilla\" \"vw.example.nl\" \"\" 0.01"
    echo "198.51.100.8 - - $L \"GET /admin HTTP/1.1\" 403 10 \"-\" \"Mozilla\" \"vw.example.nl\" \"\" 0.01"
    echo "198.51.100.8 - - $L \"GET / HTTP/1.1\" 502 10 \"-\" \"Mozilla\" \"vw.example.nl\" \"vw:80\" 0.01"
    echo "198.51.100.9 - - $L \"GET / HTTP/1.1\" 444 0 \"-\" \"Mozilla\" \"_\" \"\" 0.01"
    echo "geen-ip - - $L \"GET / HTTP/1.1\" 200 0 \"-\" \"Mozilla\" \"x\" \"\" 0.01"
}
{
    echo "w_dir='$work'; w_today=20261115"
    cat "$work/fn.sh"
    echo 'lines | pxstat >"$1"'
} >"$work/run.sh"
bash -c "$(declare -f lines); L='$L'; . '$work/run.sh' '$work/r1.json'"
bash -c "$(declare -f lines); L='$L'; . '$work/run.sh' '$work/r2.json'"
bad=""
python3 - "$work" <<'PY' || bad=$(cat "$work/py.err" 2>/dev/null)
import json, sys
w = sys.argv[1]
err = open(f'{w}/py.err', 'w')
try:
    r = json.load(open(f'{w}/r1.json')); r2 = json.load(open(f'{w}/r2.json'))
    t = r['t']
    # [totaal, 2xx, 3xx, 4xx-overig, 403, 404, 429, 444, 5xx, 403 door bot]
    assert t['blog.example.nl'] == [3, 1, 0, 0, 1, 1, 0, 0, 0, 1], t
    assert t['vw.example.nl'] == [3, 0, 0, 0, 1, 0, 1, 0, 1, 0], t
    assert t['-'] == [1, 0, 0, 0, 0, 0, 0, 1, 0, 0], t
    p = {(x[0], x[1]): x[2:] for x in r['p']}
    assert ('blog.example.nl', '/wp-login.php') in p and not any('geheim' in k[1] for k in p), p
    assert p[('blog.example.nl', '/.env')] == [1, 1, 1, 1] and p[('vw.example.nl', '/api')] == [1, 1, 0, 0], p
    assert ('blog.example.nl', '/') not in p, 'een gewoon 200-verzoek is geen aangevallen pad'
    assert r['u'] == 4 and r2['u'] == 0, (r['u'], r2['u'])
except (AssertionError, KeyError) as e:
    err.write(str(e)[:1500]); sys.exit(1)
PY
if [ -n "$bad" ]; then echo "✘ proxy-statistieken: $bad"; exit 1; fi
echo "✔ proxy-statistieken: per site de statuscodes en bots, aangevallen paden zonder querystring, unieke bezoekers per dag (tweede keer niet opnieuw), ongeldige regels overgeslagen"
