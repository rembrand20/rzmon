#!/usr/bin/env bash
# Agenttest tijdelijk sudo (agent 1.93.0, GitHub Actions per Linux-versie): een gevraagde gebruiker krijgt een eigen,
# door visudo goedgekeurd bestand in /etc/sudoers.d, een tweede ronde laat het staan, ingetrokken of verlopen = weg,
# root of een onbekende gebruiker nooit, en andere bestanden in /etc/sudoers.d blijven onaangeroerd.
# Alleen als root en met visudo en useradd; anders overgeslagen.
# Gebruik: bash tests/agent-sudo.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ] || ! command -v visudo >/dev/null 2>&1 || ! command -v useradd >/dev/null 2>&1; then
    echo "• tijdelijk sudo: overgeslagen (geen root, visudo of useradd)"; exit 0
fi
work=$(mktemp -d)
u=rzsjit$$
mkdir -p /etc/sudoers.d
trap 'rm -rf "$work"; rm -f /etc/sudoers.d/rzsense-jit-* /etc/sudoers.d/zz-rzsense-test; userdel "$u" >/dev/null 2>&1' EXIT
useradd -M "$u" >/dev/null 2>&1 || { echo "• tijdelijk sudo: overgeslagen (useradd lukt niet)"; exit 0; }
printf '# niet van RzSense\n' >/etc/sudoers.d/zz-rzsense-test; chmod 0440 /etc/sudoers.d/zz-rzsense-test
mkdir -p "$work/cache"
awk '/^# -+ Tijdelijk sudo/{f=1} f&&/^# -+ /&&!/Tijdelijk sudo/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
fn() { awk -v n="$1" '$0 ~ "^" n "\\(\\) \\{" {f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh; }
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'; act_n=0"
    echo 'lap() { :; }'
    fn json_escape; fn act_log
    echo "systemd-run() { :; }"   # geen echte timer in de test
    echo ". '$work/mod.sh'"
    echo 'printf "[%s]" "${sudo_json%,}"'
} >"$work/run.sh"
until=$(( $(date +%s) + 600 ))
printf '0\tsudo\t41\t%s\t%s\n0\tsudo\t42\troot\t%s\n0\tsudo\t43\tbestaatniet9\t%s\n' "$u" "$until" "$until" "$until" >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r1.json" 2>"$work/err"
cp "/etc/sudoers.d/rzsense-jit-$u" "$work/file" 2>/dev/null
bash "$work/run.sh" >"$work/r2.json" 2>>"$work/err"
: >"$work/cache/tests.cache"   # ingetrokken
bash "$work/run.sh" >"$work/r3.json" 2>>"$work/err"
printf '0\tsudo\t44\t%s\t%s\n' "$u" "$(( $(date +%s) - 5 ))" >"$work/cache/tests.cache"   # al verlopen: niet geven
bash "$work/run.sh" >"$work/r4.json" 2>>"$work/err"
bad=""
grep -qx "$u ALL=(ALL:ALL) ALL" "$work/file" 2>/dev/null && [ "$(stat -c %a "$work/file")" = 440 ] || bad+="bestand niet goed: $(cat "$work/file" 2>/dev/null) | "
visudo -cqf "$work/file" >/dev/null 2>&1 || bad+="visudo keurt het af | "
python3 - "$work" <<'PY' || bad+="rapport: $(cat "$work"/r*.json) | "
import json, sys
w = sys.argv[1]
r1, r2, r3, r4 = (json.load(open(f'{w}/r{i}.json')) for i in (1, 2, 3, 4))
assert {'id': 41, 'st': 'active'} in r1, r1
assert any(x['id'] == 43 and x['st'] == 'error' for x in r1), r1
assert not any(x['id'] == 42 for x in r1), r1
assert r2 == [{'id': 41, 'st': 'active'}], r2
assert {'id': 41, 'st': 'removed'} in r3, r3
assert r4 == [], r4
PY
[ -e /etc/sudoers.d/rzsense-jit-root ] && bad+="root kreeg een bestand | "
[ -e "/etc/sudoers.d/rzsense-jit-$u" ] && bad+="na intrekken niet weg | "
[ -f /etc/sudoers.d/zz-rzsense-test ] || bad+="ander bestand weggehaald | "
ls /etc/sudoers.d/.rzsense-jit.* >/dev/null 2>&1 && bad+="tijdelijke bestanden blijven liggen | "
grep -q 'Tijdelijk sudo gegeven' "$work/cache/act.log" && grep -q 'Tijdelijk sudo weggehaald' "$work/cache/act.log" || bad+="logboek mist regels | "
if [ -n "$bad" ]; then echo "✘ tijdelijk sudo: ${bad% | } $(cat "$work/err")"; exit 1; fi
echo "✔ tijdelijk sudo: eigen bestand (visudo, 0440), blijft staan, weg bij intrekken, nooit root of onbekend of verlopen, andere bestanden onaangeroerd, in het logboek"
