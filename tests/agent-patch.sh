#!/usr/bin/env bash
# Agenttest updates (agent 1.92.0, GitHub Actions per Linux-versie): met nep-dnf en nep-apt-get in PATH (er wordt niets
# echt bijgewerkt) controleren dat de agent ziet welke updates klaarstaan en welke daarvan beveiligingsupdates zijn,
# en dat "alle beveiligingsupdates installeren" (mode security) alleen de beveiligingspakketten bijwerkt,
# geen snapshot meer maakt (agent 1.95.1), nooit herstart en de uitkomst terugmeldt. Alleen als root; anders overgeslagen.
# Gebruik: bash tests/agent-patch.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ]; then echo "• updates: overgeslagen (geen root)"; exit 0; fi
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/cache" "$work/bin" "$work/pk"
# Nep-dnf: twee updates, waarvan één beveiligingsupdate; upgrade schrijft wat hij kreeg naar een bestand
cat >"$work/bin/dnf" <<'EOF'
#!/bin/bash
case " $* " in
    *" check-update --security "*) printf 'openssl-libs.x86_64   1:3.2.2-1.el9   baseos\n'; exit 100 ;;
    *" check-update "*) printf '\nopenssl-libs.x86_64   1:3.2.2-1.el9   baseos\nvim-minimal.x86_64   2:9.1.1-1.el9   baseos\n'; exit 100 ;;
    *" upgrade "*) printf '%s\n' "$*" >>"$RZT_DIR/dnf.calls"; echo "Complete!"; exit 0 ;;
esac
exit 0
EOF
# Nep-apt-get: -s upgrade geeft twee pakketten (één uit security); install schrijft wat hij kreeg
cat >"$work/bin/apt-get" <<'EOF'
#!/bin/bash
case " $* " in
    *" -s "*) printf 'Inst libssl3 [3.0.13-0ubuntu3.1] (3.0.13-0ubuntu3.4 Ubuntu:24.04/noble-security [amd64])\nInst vim [2:9.1] (2:9.1.1 Ubuntu:24.04/noble-updates [amd64])\nConf libssl3 (3.0.13)\n' ;;
    *" install "*|*" upgrade "*) printf '%s\n' "$*" >>"$RZT_DIR/apt.calls" ;;
esac
exit 0
EOF
chmod +x "$work/bin/dnf" "$work/bin/apt-get"
export RZT_DIR="$work"
awk '/^# -+ Updates \(patchbeheer/{f=1} f&&/^# -+ /&&!/Updates \(patchbeheer/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
fn() { awk -v n="$1" '$0 ~ "^" n "\\(\\) \\{" {f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh; }
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'; pk_dir='$work/pk'"
    echo 'lap() { :; }'
    fn json_escape; fn act_log | sed '1s/^/act_n=0\n/'
    echo ". '$work/mod.sh'"
    echo 'printf "%s" "$patch_json"'
} >"$work/run.sh"
printf '0\tpatch\t1\n' >"$work/cache/tests.cache"
bad=""
# 1. rpm: alleen als de nep-dnf gekozen wordt (eerst in PATH)
PATH="$work/bin:$PATH" bash "$work/run.sh" >"$work/rpm.json" 2>"$work/err" || bad+="uitlezen faalde: $(cat "$work/err") | "
python3 - "$work/rpm.json" <<'PY' || bad+="rpm: $(cat "$work/rpm.json") | "
import json, sys
d = json.load(open(sys.argv[1]))
assert d['mgr'] == 'rpm' and d['avail'] == 2 and d['sec'] == 1, d
assert ['openssl-libs', '1:3.2.2-1.el9', 1] in d['list'] and ['vim-minimal', '2:9.1.1-1.el9', 0] in d['list'], d
PY
# 2. Tweede ronde binnen het uur: niets opnieuw
PATH="$work/bin:$PATH" bash "$work/run.sh" >"$work/rpm2.json" 2>>"$work/err"
[ -s "$work/rpm2.json" ] && bad+="tweede ronde stuurde de lijst opnieuw | "
# 3. Installeren: mode security, via de echte pkg_upgrade en het script dat hij start (agent 1.95.1: "snap" van een oudere RzSense wordt genegeerd)
{
    echo "pk_dir='$work/pk'; pk_mgr=dpkg; MONITOR_URL=''; COMMANDS=root; enc_key=''; tls=(); auth=(-H 'X-Test: 1')"
    fn enc_sub; fn seal; fn pkg_upgrade
    echo 'pkg_upgrade "{\"pkg_upgrade\":{\"id\":\"0123456789abcdef\",\"pkgs\":[],\"mode\":\"security\",\"snap\":true}}"'
} >"$work/up.sh"
PATH="$work/bin:$PATH" bash "$work/up.sh" 2>>"$work/err"
for _ in $(seq 1 40); do [ -s "$work/pk/upgrade.result" ] && break; sleep 0.5; done
if [ ! -s "$work/pk/upgrade.result" ]; then
    bad+="geen uitkomst van het bijwerken | "
else
    IFS=$'\t' read -r r_id r_st r_log <"$work/pk/upgrade.result"
    log=$(printf '%s' "$r_log" | base64 -d)
    [ "$r_id" = 0123456789abcdef ] && [ "$r_st" = ok ] || bad+="uitkomst: $r_id $r_st | "
    grep -q 'install' "$work/apt.calls" 2>/dev/null && grep -q 'libssl3' "$work/apt.calls" && ! grep -q 'vim' "$work/apt.calls" \
        || bad+="security werkte niet alleen libssl3 bij: $(cat "$work/apt.calls" 2>/dev/null) | "
    printf '%s' "$log" | grep -qE 'Snapshot gemaakt|Geen snapshot mogelijk' && bad+="toch een snapshot geprobeerd: $log | "
fi
if [ -n "$bad" ]; then echo "✘ updates: ${bad% | }"; exit 1; fi
echo "✔ updates: klaarstaande updates en beveiligingsupdates gezien (dnf), niet elk uur opnieuw, alleen beveiligingsupdates geïnstalleerd (apt), geen snapshot meer, uitkomst terug"
