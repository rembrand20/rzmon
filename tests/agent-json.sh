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
odd = [p for p in d['proc_list'] if p[0] == 'rztestproc' and '\\[' in p[3] and '"x"' in p[3]]
print('✔ geldige JSON, %d bytes, %d processen, %d disks%s' % (len(raw), len(d['proc_list']), len(d['disks']),
      ', proces met backslash netjes meegestuurd' if odd else ''))
if not odd:
    print('✘ het testproces met een backslash ontbreekt in proc_list:', [p for p in d['proc_list'] if 'rztest' in str(p) or 'grep-test' in str(p)]); sys.exit(1)
PY
