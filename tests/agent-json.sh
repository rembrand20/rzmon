#!/usr/bin/env bash
# Agenttest (GitHub Actions, per Linux-versie): draait de agent één keer tegen een nep-RzMon en controleert of het
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
    print('✘ zelftest ontbreekt of het adres van RzMon was niet bereikbaar (selftest):', st); sys.exit(1)
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
# Agent 1.73.0: tweede rapport, nu RzMon "gz":1 zei, moet gecomprimeerd komen (en uitgepakt weer geldige JSON zijn)
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
# Agent 1.82.0: tijdens een (aangekondigde) update van RzMon gaat de meting in de wachtrij en wacht de agent op de vrijgave;
# daarna gaan de wachttijd en de wachtrij mee en is de aankondiging weg. (De aankondiging zelf komt alleen versleuteld;
# hier zetten we hem klaar zoals de agent hem na zo'n antwoord bewaart.)
hold_f="$work/agent/keys/rzhold"
[ -d "$work/agent/keys" ] || hold_f="$work/cache/rzhold"
printf 'abcdef012345 %s 0\n' "$(( $(date +%s) + 300 ))" >"$hold_f"
MONITOR_URL="http://127.0.0.1:1" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
    HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
read -r _ _ h_since _ <"$hold_f" 2>/dev/null
if [ "${h_since:-0}" -le 0 ] || ! grep -q 'wacht op de vrijgave' "$work/out"; then
    echo "✘ update van RzMon: de agent wachtte niet op de vrijgave"; tail -n 20 "$work/out"; exit 1
fi
rm -f "$work/payload.json"
MONITOR_URL="http://127.0.0.1:$port" TOKEN=ci-test RZ_FORCE=1 CACHE_DIRECTORY="$work/cache" AGENT_DIR="$work/agent" \
    HEAL_DIR="$work/heal.d" IDS_BLOCK=0 PODMAN=0 HOLD_JITTER=0 timeout 300 bash agent/rzmon-agent.sh >"$work/out" 2>&1
if ! python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); h=d["hold"]; assert h["id"]=="abcdef012345" and h["since"]>0 and h["q"]>=1 and d.get("backlog")' "$work/payload.json" 2>/dev/null || [ -e "$hold_f" ]; then
    echo "✘ update van RzMon: na de vrijgave geen wachttijd/wachtrij meegestuurd, of de aankondiging bleef staan"; tail -n 20 "$work/out"; exit 1
fi
echo "✔ update van RzMon: meting in de wachtrij, na de vrijgave nagestuurd met de wachttijd"
