#!/usr/bin/env bash
# Agenttest (GitHub Actions, per Linux-versie): draait de agent één keer tegen een nep-RzSense en controleert of het
# rapport geldige JSON is, ook met lastige tekens (backslash, aanhalingsteken) in de opdrachtregel van een proces.
# De awk van de distributie telt mee (mawk op Debian, gawk op Rocky, busybox op Alpine): daar zat de fout van 1.61.1.
# Gebruik (als root): bash tests/agent-json.sh
set -u
cd "$(dirname "$0")/.." || exit 1
work=$(mktemp -d)
port=18099
python3 tests/fake_rzmon.py "$port" "$work" &
srv=$!
trap 'kill "$srv" "${proc:-}" 2>/dev/null; rm -rf "$work"' EXIT
# Een proces met een backslash en een aanhalingsteken in de opdrachtregel (zoals grep -E 'sshd\[[0-9]+\]'), met een
# eigen procesnaam via prctl (de agent toont per procesnaam één voorbeeld; werkt ook met BusyBox)
python3 -c 'import ctypes,time;ctypes.CDLL(None).prctl(15,b"rztestproc",0,0,0);time.sleep(120)#"x"\[1]' &
proc=$!
sleep 1
echo "awk: $(readlink -f "$(command -v awk)") · $(awk -W version 2>/dev/null | head -n 1 || true)"
MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
    HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
rc=$?
if [ ! -s "$work/payload.json" ]; then
    echo "✘ geen rapport ontvangen (agent rc=$rc)"; tail -n 30 "$work/out"; exit 1
fi
python3 - "$work/payload.json" <<'PY'
import json, sys
raw = open(sys.argv[1], 'rb').read()
try:
    d = json.loads(raw)
except ValueError as e:
    pos = getattr(e, 'pos', 0) or 0
    print('✘ rapport is geen geldige JSON:', e)
    print('  rond de fout:', raw[max(0, pos - 120):pos + 80])
    sys.exit(1)
for k in ('agent_version', 'hostname', 'cpu', 'mem', 'disks', 'services', 'proc_list', 'svc_list'):
    if k not in d:
        print('✘ ontbreekt in het rapport:', k); sys.exit(1)
info = (d.get('edr') or {}).get('info') or {}
if 'audit' not in info or not isinstance(d['edr'].get('ev'), list):
    print('✘ endpoint-bewaking ontbreekt in het rapport (edr.info/ev):', d.get('edr')); sys.exit(1)
st = d.get('selftest') or {}
if st.get('id') != 42 or not any(i.get('k') == 'url' and i.get('ok') == 'ok' for i in st.get('items', [])):
    print('✘ zelftest ontbreekt of het adres van RzSense was niet bereikbaar (selftest):', st); sys.exit(1)
print('✔ zelftest: ' + ', '.join('%s=%s' % (i['k'], i['ok']) for i in st['items']))
nic = d.get('nic')   # agent 1.80.0: netwerkkaarten (in een container zonder echte netwerkkaart mag het ontbreken)
if nic is not None and not (isinstance(nic.get('rx'), int) and isinstance(nic.get('tx'), int) and nic.get('ifs')):
    print('✘ netwerkverkeer (nic) is niet geldig:', nic); sys.exit(1)
print('✔ netwerkverkeer: ' + ('%s (↓ %d, ↑ %d bytes)' % (','.join(nic['ifs']), nic['rx'], nic['tx']) if nic else 'geen echte netwerkkaart (container)'))
odd = [p for p in d['proc_list'] if p[0] == 'rztestproc' and '\\[' in p[3] and '"x"' in p[3]]
print('✔ geldige JSON, %d bytes, %d processen, %d disks%s' % (len(raw), len(d['proc_list']), len(d['disks']),
      ', proces met backslash netjes meegestuurd' if odd else ''))
if not odd:
    print('✘ het testproces met een backslash ontbreekt in proc_list:', [p for p in d['proc_list'] if 'rztest' in str(p) or 'grep-test' in str(p)]); sys.exit(1)
PY
rc=$?
[ "$rc" = 0 ] || exit "$rc"
# Agent 1.73.0: tweede rapport, nu RzSense "gz":1 zei, moet gecomprimeerd komen (en uitgepakt weer geldige JSON zijn)
if command -v gzip >/dev/null 2>&1; then
    rm -f "$work/payload.json" "$work/gz.size"
    MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
        HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 COMPRESS_MIN=100 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
    if [ ! -s "$work/gz.size" ] || ! python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$work/payload.json" 2>/dev/null; then
        echo "✘ tweede rapport niet gecomprimeerd of geen geldige JSON"; tail -n 20 "$work/out"; exit 1
    fi
    read -r gz_c gz_u <"$work/gz.size"
    echo "✔ gecomprimeerd verstuurd: $gz_c bytes (uitgepakt $gz_u bytes, $(( 100 - gz_c * 100 / gz_u ))% kleiner)"
else
    echo "· gzip ontbreekt: compressie niet getest"
fi
# Agent 1.82.0: tijdens een (aangekondigde) update van RzSense gaat de meting in de wachtrij en wacht de agent op de vrijgave;
# daarna gaan de wachttijd en de wachtrij mee en is de aankondiging weg. (De aankondiging zelf komt alleen versleuteld;
# hier zetten we hem klaar zoals de agent hem na zo'n antwoord bewaart.)
hold_f="$work/agent/keys/rzhold"
[ -d "$work/agent/keys" ] || hold_f="$work/cache/rzhold"
printf 'abcdef012345 %s 0\n' "$(( $(date +%s) + 300 ))" >"$hold_f"
MONITOR_URL="http://127.0.0.1:1" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
    HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
read -r _ _ h_since _ <"$hold_f" 2>/dev/null
if [ "${h_since:-0}" -le 0 ] || ! grep -q 'wacht op de vrijgave' "$work/out"; then
    echo "✘ update van RzSense: de agent wachtte niet op de vrijgave"; tail -n 20 "$work/out"; exit 1
fi
rm -f "$work/payload.json"
MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
    HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 HOLD_JITTER=0 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
if ! python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); h=d["hold"]; assert h["id"]=="abcdef012345" and h["since"]>0 and h["q"]>=1 and d.get("backlog")' "$work/payload.json" 2>/dev/null || [ -e "$hold_f" ]; then
    echo "✘ update van RzSense: na de vrijgave geen wachttijd/wachtrij meegestuurd, of de aankondiging bleef staan"; tail -n 20 "$work/out"; exit 1
fi
echo "✔ update van RzSense: meting in de wachtrij, na de vrijgave nagestuurd met de wachttijd"
# Agent 1.83.0: logs verzamelen. Eerste run met de map toegestaan: bij het einde beginnen. Daarna alleen de nieuwe regels van
# niveau 2 (warning en erger); een halve laatste regel wacht. Het pakketje (gzip) ligt klaar; zonder sleutel gaat het niet weg.
mkdir -p "$work/logs"; : >"$work/logs/app.log"
for n in 1 2; do
    [ "$n" = 2 ] && printf 'INFO gewoon\nERROR kapot 1\nWARNING bijna vol\nhalf' >>"$work/logs/app.log"
    MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
        HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 LOG_DIRS="$work/logs" timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
done
ls_d="$work/agent/keys/logship"; [ -d "$ls_d" ] || ls_d="$work/cache/logship"
python3 - "$ls_d" <<'PY'
import glob, gzip, sys
lines, status = [], []
for b in sorted(glob.glob(sys.argv[1] + '/b*.gz')):
    rows = gzip.decompress(open(b, 'rb').read()).decode().split('\n')
    if not rows[0].startswith('rzlogs1\t'):
        print('✘ logpakketje zonder kop:', rows[0]); sys.exit(1)
    lines += [r.split('\t', 3)[3] for r in rows if r.startswith('L\t7\t')]
    status += [r for r in rows if r.startswith('S\t7\t')]
if lines != ['ERROR kapot 1', 'WARNING bijna vol']:
    print('✘ logregels kloppen niet (verwacht ERROR en WARNING, zonder INFO en de halve regel):', lines); sys.exit(1)
if not status or '\tok\t1\t' not in status[-1] + '\t':
    print('✘ status van de logbron klopt niet:', status); sys.exit(1)
print('✔ logs: alleen nieuwe regels van het gekozen niveau, halve regel wacht, pakketje klaar (gzip)')
PY
# Agent 1.84.0: logs vinden. Zonder lijst van vandaag zoekt de agent in LOG_DIRS en stuurt namen, groottes en tijden mee;
# gedraaide (.1, .gz) en binaire bestanden niet. De run daarna (zelfde dag, geen verzoek) stuurt de lijst niet opnieuw.
mkdir -p "$work/logs/nginx"; echo x >"$work/logs/nginx/access.log"; echo y >"$work/logs/app.log.1"; printf 'a\0\0b' >"$work/logs/wtmp"
rm -f "$work/cache/logscan.cache" "$work/payload.json"
for n in 1 2; do
    MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
        HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 LOG_DIRS="$work/logs" timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
    cp -f "$work/payload.json" "$work/payload.$n.json" 2>/dev/null
done
python3 - "$work/payload.1.json" "$work/payload.2.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
ls = d.get('log_scan')
if not ls:
    print('✘ logs vinden: geen log_scan in het rapport'); sys.exit(1)
names = sorted(f[0].rsplit('/', 1)[-1] for f in ls['files'])
if 'access.log' not in names or 'app.log' not in names or 'app.log.1' in names or 'wtmp' in names:
    print('✘ logs vinden: verkeerde bestanden:', names); sys.exit(1)
if 'log_scan' in json.load(open(sys.argv[2])):
    print('✘ logs vinden: de lijst ging de volgende run opnieuw mee'); sys.exit(1)
print('✔ logs vinden: bestanden in LOG_DIRS (zonder gedraaide en binaire), één keer meegestuurd')
PY
# Agent 1.89.0: logboek. Regels uit act.log gaan mee met het rapport (geldige JSON, met kenmerk) en zijn daarna weg;
# een kapotte regel gaat niet mee.
{ printf '{"id":"1700000000-1-1-1","t":1700000000,"m":"firewall","ok":1,"a":"Firewall bijgewerkt","d":"3 IP\x27s"}\n'
  printf 'kapot {"id":\n'; } >"$work/cache/act.log"
rm -f "$work/payload.json"
MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
    HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
if ! python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); a=d["act"]; assert len(a)==1 and a[0]["id"]=="1700000000-1-1-1" and a[0]["m"]=="firewall"' "$work/payload.json" 2>/dev/null \
    || [ -s "$work/cache/act.sending" ] || [ -s "$work/cache/act.log" ]; then
    echo "✘ logboek: regels niet (goed) meegestuurd, of na een geslaagd rapport niet weg"; ls -la "$work/cache" | grep act; exit 1
fi
echo "✔ logboek: wat de agent veranderde gaat mee met het rapport en is daarna weg (kapotte regels niet)"
