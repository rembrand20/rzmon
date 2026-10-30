#!/usr/bin/env bash
# Agenttest endpoint-bewaking (agent 1.63.0, GitHub Actions per Linux-versie): de awk die het auditd-log leest, de
# awk die verdachte verzoeken uit de SWAG-log haalt en (1.64.0) de awk die Suricata-alerts leest, met de awk van de
# distributie (mawk, gawk, busybox).
# Gebruik: bash tests/agent-edr.sh
set -u
cd "$(dirname "$0")/.." || exit 1
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
echo 9999 >"$work/own.pids"   # agent 1.67.0: een run van de agent met PID 9999
CACHE_DIRECTORY="$work" EDR_ROOT=/opt/rzmon EDR_USER=www-data EDR_MARK=0123456789abcdef01234567 bash agent/rzmon-agent.sh --edr-parse audit <tests/data/audit.log >"$work/audit.out" 2>"$work/err" || { echo "✘ audit-parser faalde"; cat "$work/err"; exit 1; }
CACHE_DIRECTORY="$work" bash agent/rzmon-agent.sh --edr-parse swag <tests/data/swag.log >"$work/swag.out" 2>>"$work/err" || { echo "✘ swag-parser faalde"; cat "$work/err"; exit 1; }
CACHE_DIRECTORY="$work" bash agent/rzmon-agent.sh --edr-parse eve <tests/data/eve.json >"$work/eve.out" 2>>"$work/err" || { echo "✘ Suricata-parser faalde"; cat "$work/err"; exit 1; }
python3 - "$work/audit.out" "$work/swag.out" "$work/eve.out" <<'PY'
import json, sys
ev = [json.loads(l) for l in open(sys.argv[1]) if l.strip()]
bad = []
own = [e for e in ev if e['k'] == 'x' and e.get('own')]
x = [e for e in ev if e['k'] == 'x' and not e.get('own')]
a = [e for e in ev if e['k'] == 'a']
if len(x) != 5: bad.append('verwacht 5 gewone processen (webshell, één keer ls, nagemaakte agent, verkeerd kenmerk, kenmerk van een andere gebruiker), kreeg %d: %s' % (len(x), [e.get('cmd') for e in x]))
if [(e['cmd'], e['own']) for e in own] != [('bash /usr/local/bin/rzmon-agent.sh', 'agent'), ('ss -tnpH', 'agent'), ('php /opt/rzmon/cron/run.php', 'rzmon'), ('sh -c timeout 2 getent hosts 203.0.113.5', 'rzmon'),
        ('sh -c : rzmon:0123456789abcdef01234567; timeout 2 getent hosts 192.0.2.7', 'rzmon'), ('getent hosts 192.0.2.7', 'rzmon'),
        ('/bin/sh -c /usr/bin/php /opt/rzmon/cron/run.php', 'rzmon'), ('curl -fsS https://rzmon.example/api.php', 'agent')]:
    bad.append('eigen processen (agent, kind van de agent, RzMon-cron en zijn kind, kenmerk van RzMon en zijn kind, cron van RzMon via sh, kind van een agent-run) niet goed herkend: %r' % [(e.get('cmd'), e.get('own')) for e in own])
ws = next((e for e in x if e['pid'] == 4242), None)
if not ws or ws['argv'] != ['sh', '-c', 'id && cat "/etc/passwd"\\x'] or ws['exe'] != '/usr/bin/dash' or ws['uid'] != 33 or ws['cwd'] != '/var/www/html':
    bad.append('webshell-proces niet goed gelezen: %r' % ws)
if not any(e.get('exe') == '/tmp/.x/rzmon-agent.sh' for e in x): bad.append('nagemaakte rzmon-agent (gewone gebruiker) mag niet als eigen proces tellen')
if len(a) != 1 or a[0]['key'] != 'rzmon_id' or a[0]['name'] != '/etc/passwd' or a[0]['exe'] != '/usr/bin/vim.basic':
    bad.append('bewaakt pad niet goed gelezen: %r' % a)
req = json.loads('[' + open(sys.argv[2]).read().strip() + ']')
ips = [r[0] for r in req]
if ips != ['1.2.3.4', '9.9.9.9', '7.7.7.7']: bad.append('verdachte verzoeken: verwacht 1.2.3.4, 9.9.9.9 en 7.7.7.7, kreeg %s' % ips)
elif req[1][3] != '/?x=\\x22\\x5C' or not req[1][6].startswith('() {') or req[0][1] != '28/Oct/2026:10:00:00 +0100':
    bad.append('verzoek niet goed: %r' % req[:2])
eve = json.loads('[' + open(sys.argv[3]).read().strip() + ']')
if len(eve) != 2 or eve[0][:7] != ['2026-10-28T10:00:01.123456+0100', '203.0.113.5', 51234, '192.168.1.10', 22, 'TCP', 2001219] \
        or eve[1][7] != 'ET MALWARE Cobalt Strike "Beacon" C2 \\ test' or eve[1][9] != 1 or eve[1][10] != 'blocked':
    bad.append('Suricata-alerts niet goed gelezen: %r' % eve)
if bad:
    print('✘ ' + '\n✘ '.join(bad)); sys.exit(1)
print('✔ auditd: %d gebeurtenissen (proces met hex-argument, bewaakt pad, dubbel overgeslagen, eigen processen herkend) · SWAG: %d verdachte verzoeken · Suricata: %d alerts' % (len(ev), len(req), len(eve)))
PY
