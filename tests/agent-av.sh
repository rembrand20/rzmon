#!/usr/bin/env bash
# Agenttest ClamAV op de reverse proxy (agent 1.99.0): met een nep-podman die een ClamAV-container nadoet (alles met de
# tekst EICAR-RZTEST is "besmet"). Te weinig geheugen = niet starten; mappen alleen binnen het plafond; de eerste keer alleen
# een beginpunt; daarna nieuwe bestanden scannen, besmet = quarantaine (of alleen melden); terugzetten (en dan niet opnieuw),
# definitief weghalen, een volledige scan op de achtergrond, voorstellen uit de mappen van andere containers; uit = container weg.
# Alleen als root; anders overgeslagen. Gebruik: bash tests/agent-av.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ]; then echo "• ClamAV: overgeslagen (geen root)"; exit 0; fi
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/cache" "$work/state" "$work/data/up/sub" "$work/data/nc" "$work/outside"
cat >"$work/bin/podman" <<EOF
#!/usr/bin/env bash
S="$work/state"; A="$work/av"
echo "\$*" >>"$work/podman.log"
case "\$1" in
    inspect)
        if [ "\$2" = -f ]; then
            case "\$3" in
                *State.Running*) [ -f "\$S/ctr" ] && echo true || exit 1 ;;
                *Mounts*) [ "\$4" = nextcloud ] && echo "$work/data/nc" && echo /etc/nextcloud-config ;;
            esac
        else [ "\$2" = rzsense-clamav ] && { [ -f "\$S/ctr" ] || exit 1; }; fi ;;
    ps) printf 'swag\nnextcloud\nrzsense-clamav\n' ;;
    run) touch "\$S/ctr"; printf '%s\n' "\$*" >"\$S/run" ;;
    rm) rm -f "\$S/ctr" ;;
    exec)
        shift 2
        [ "\$1" = clamdscan ] || exit 2
        case "\$*" in
            *--version*) echo "ClamAV 1.4.1/27470/Fri Nov 14 2026" ;;
            *--file-list=*) rc=0; while IFS= read -r f; do grep -q EICAR-RZTEST "\$f" 2>/dev/null && { echo "\$f: Eicar-Test-Signature FOUND"; rc=1; }; done <"\$A/work/list.txt"; exit \$rc ;;
            *--multiscan*) shift 4; grep -rl EICAR-RZTEST "\$@" 2>/dev/null | sed 's/\$/: Win.Test.Full FOUND/' ;;
            *) exit 0 ;;
        esac ;;
esac
exit 0
EOF
chmod +x "$work/bin/podman"
awk '/^# -+ ClamAV op de reverse proxy/{f=1} f&&/^# -+ /&&!/ClamAV op de reverse proxy/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
fn() { awk -v n="$1" '$0 ~ "^" n "\\(\\) \\{" {f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh; }
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'; act_n=0; AV_DIR='$work/av'; AV_DIRS_MAX='$work/data'"
    echo "PATH='$work/bin':\$PATH; px_kind=swag; px_eng=podman; px_ctr=swag"
    fn json_escape; fn act_log
    echo ". '$work/mod.sh'"
    echo 'printf "%s" "$av_json"'
} >"$work/run.sh"
run() { bash "$work/run.sh" >"$work/r-$1.json" 2>>"$work/err"; }   # run <naam>
base() { printf '0\tav\ton\t1\t%s\n0\tavdir\t%s\n0\tavdir\t/etc\n0\tavdir\t%s\n' "$1" "$work/data/up" "$work/outside"; }
base 1 >"$work/cache/tests.cache"
AV_MIN_MB=99999999 run lowmem
AV_MIN_MB=1 run first
echo 'gewoon' >"$work/data/up/clean.txt"; sleep 1.1
echo 'X5O EICAR-RZTEST' >"$work/data/up/sub/evil.php"; echo 'gewoon' >"$work/data/up/ok.txt"
AV_MIN_MB=1 run scan
[ -e "$work/data/up/sub/evil.php" ] && echo gone-not >"$work/still-there"
qid=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['found'][0]['qid'])" "$work/r-scan.json" 2>/dev/null)
stat -c %a "$work/av/quarantine/$qid/file" >"$work/qmode" 2>/dev/null
{ base 1; printf '0\tavrestore\t%s\n' "$qid"; } >"$work/cache/tests.cache"
AV_MIN_MB=1 run restore
touch "$work/data/up/sub/evil.php"; sleep 1.1
base 0 >"$work/cache/tests.cache"
echo 'EICAR-RZTEST twee' >"$work/data/up/evil2.php"
AV_MIN_MB=1 run report
echo 'EICAR-RZTEST oud' >"$work/data/up/old.bin"; touch -d '2 days ago' "$work/data/up/old.bin"
{ base 1; printf '0\tavfull\tf1\n'; } >"$work/cache/tests.cache"
AV_MIN_MB=1 run fullstart
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -f "$work/av/full.rc" ] && break; sleep 0.5; done
AV_MIN_MB=1 run fulldone
qold=$(python3 -c "import json,sys; print([f['qid'] for f in json.load(open(sys.argv[1]))['found'] if f['p'].endswith('old.bin')][0])" "$work/r-fulldone.json" 2>/dev/null)
{ base 1; printf '0\tavdelete\t%s\n0\tavdelete\t1; rm -rf /\n' "$qold"; } >"$work/cache/tests.cache"
AV_MIN_MB=1 run delete
printf '0\tav\toff\t1\t1\n' >"$work/cache/tests.cache"
run off
bad=""
python3 - "$work" <<'PY' || bad+="$(cat "$work/py.err" 2>/dev/null) | "
import json, sys, os
w = sys.argv[1]
err = open(f'{w}/py.err', 'w')
try:
    r = {k: json.load(open(f'{w}/r-{k}.json')) for k in ('lowmem', 'first', 'scan', 'restore', 'report', 'fullstart', 'fulldone', 'delete', 'off')}
    assert r['lowmem']['state'] == 'lowmem', r['lowmem']
    f = r['first']
    assert f['state'] == 'ok' and f['found'] == [] and 'ClamAV 1.4.1' in f['sigs'], f
    dirs = {d['p']: d for d in f['dirs']}
    assert dirs[f'{w}/data/up']['ok'] == 1 and dirs['/etc']['ok'] == 0 and 'systeemmap' in dirs['/etc']['why'] and 'plafond' in dirs[f'{w}/outside']['why'], dirs
    assert [s['p'] for s in f['suggest']] == [f'{w}/data/nc'], f['suggest']
    s = r['scan']
    assert len(s['found']) == 1 and s['found'][0]['p'].endswith('sub/evil.php') and s['found'][0]['moved'] == 1 and s['new'] == 3, s
    assert not os.path.exists(f'{w}/still-there') and os.path.exists(f'{w}/data/up/ok.txt'), 'quarantaine'
    q = s['found'][0]['qid']
    assert open(f'{w}/qmode').read().strip() == '0' and len(s['quarantine']) == 1, 'rechten'
    rs = r['restore']
    assert rs['ops'] == [{'qid': q, 'op': 'restore', 'ok': 1, 'msg': 'teruggezet'}] and os.path.exists(f'{w}/data/up/sub/evil.php') and rs['quarantine'] == [], rs
    rp = r['report']
    assert [x['p'][-9:] for x in rp['found']] == ['evil2.php'] and rp['found'][0]['moved'] == 0, rp['found']
    assert r['fullstart']['full'] == 'running', r['fullstart']
    fd = r['fulldone']
    old = [x for x in fd['found'] if x['p'].endswith('old.bin')]
    assert fd['full'] == 'done' and old and old[0]['moved'] == 1 and not any(x['p'].endswith('sub/evil.php') for x in fd['found']), fd['found']
    d = r['delete']
    assert d['ops'][0]['op'] == 'delete' and d['ops'][0]['ok'] == 1 and len(d['ops']) == 1 and not os.path.exists(f'{w}/av/quarantine/{old[0]["qid"]}'), d['ops']
    assert r['off']['state'] == 'off' and os.path.isdir(f'{w}/av/quarantine'), r['off']
    run = open(f'{w}/state/run').read()
    assert f'{w}/data/up:{w}/data/up:ro' in run and 'label=disable' in run and '/etc' not in run.replace('/etc/', '') and ':Z' not in run, run
    log = open(f'{w}/podman.log').read()
    assert 'rm -f rzsense-clamav' in log, log
except AssertionError as e:
    err.write(str(e)[:1500]); sys.exit(1)
PY
grep -q 'ClamAV gestart' "$work/cache/act.log" && grep -q 'ClamAV: teruggezet' "$work/cache/act.log" && grep -q 'ClamAV uit' "$work/cache/act.log" || bad+="logboek mist regels | "
if [ -n "$bad" ]; then echo "✘ ClamAV: ${bad% | } $(tail -n 3 "$work/err" 2>/dev/null)"; exit 1; fi
echo "✔ ClamAV: te weinig geheugen = niet starten, mappen alleen binnen het plafond (systeemmap en buiten geweigerd), voorstellen uit andere containers, eerst een beginpunt, nieuwe bestanden gescand, besmet naar quarantaine (000) of alleen gemeld, terugzetten (niet opnieuw), definitief weghalen (ongeldige id genegeerd), volledige scan op de achtergrond, uit = container weg en quarantaine blijft, in het logboek"
