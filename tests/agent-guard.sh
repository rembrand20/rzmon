#!/usr/bin/env bash
# Agenttest bewaker (agent 1.118.0): een nep-systemctl. Rapporteert de agent gewoon: niets. Al 20 min geen rapport en een
# ronde die 15 min loopt: stoppen. Timer stil: weer starten. Geen ronde meer: er een starten. Rondes lopen maar het rapport
# komt niet aan: alleen een notitie (geen herstart). Na 3 ingrepen in een uur: stoppen met ingrijpen. Elke stap in het logboek.
# Gebruik: bash tests/agent-guard.sh
set -u
cd "$(dirname "$0")/.." || exit 1
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/cache/status"
cat >"$work/bin/systemctl" <<EOF
#!/usr/bin/env bash
echo "\$*" >>"$work/calls"
case "\$*" in
    "show -p ActiveState --value rzmon-agent.service") cat "$work/state" 2>/dev/null || echo inactive ;;
    "show -p ExecMainStartTimestamp --value rzmon-agent.service") cat "$work/started" 2>/dev/null ;;
    "is-active rzmon-agent.timer") cat "$work/timer" 2>/dev/null || echo active ;;
esac
exit 0
EOF
chmod +x "$work/bin/systemctl"
now=$(date +%s)
run() {  # run <naam>: één keer de bewaker
    : >"$work/calls"
    PATH="$work/bin:$PATH" CACHE_DIRECTORY="$work/cache" bash agent/rzmon-agent.sh --guard >/dev/null 2>"$work/err-$1"
    cp "$work/calls" "$work/calls-$1"
}
setlast() { printf 'time=%s\nrc=7\nerror=geen verbinding (poort dicht of RzSense uit)\n' "$1" >"$work/cache/status/last"; }
# 1. Gewoon gerapporteerd (2 min geleden)
echo $(( now - 120 )) >"$work/cache/status/ok"; setlast $(( now - 30 ))
run fine
# 2. 20 min geen rapport, een ronde loopt al 15 min
echo $(( now - 1200 )) >"$work/cache/status/ok"
echo activating >"$work/state"; date -d "@$(( now - 900 ))" '+%a %Y-%m-%d %H:%M:%S %Z' >"$work/started"
run hang
# 3. Timer stil
echo inactive >"$work/state"; echo inactive >"$work/timer"
run timer
# 4. Geen ronde meer sinds 10 min
echo active >"$work/timer"; setlast $(( now - 600 ))
run noround
# 5. Rondes lopen (net nog), maar het rapport komt niet aan: alleen een notitie, geen herstart
: >"$work/cache/guard.log"; setlast $(( now - 30 ))
run deliver
# 6. Al 3 ingrepen dit uur: niet nog eens
printf '%s\tfix\ta\n%s\tfix\tb\n%s\tfix\tc\n' $(( now - 900 )) $(( now - 600 )) $(( now - 300 )) >"$work/cache/guard.log"
setlast $(( now - 600 ))
run limit
# 7. GUARD=0: nooit
PATH="$work/bin:$PATH" CACHE_DIRECTORY="$work/cache" GUARD=0 bash agent/rzmon-agent.sh --guard >/dev/null 2>&1; : >"$work/calls-off"
bad=""
[ ! -s "$work/calls-fine" ] || bad+="ingegrepen terwijl de agent gewoon rapporteert | "
grep -q '^stop rzmon-agent.service$' "$work/calls-hang" || bad+="hangende ronde niet gestopt | "
grep -q '^start rzmon-agent.timer$' "$work/calls-timer" || bad+="timer niet gestart | "
grep -q '^start --no-block rzmon-agent.service$' "$work/calls-noround" || bad+="geen ronde gestart | "
grep -q '^start\|^stop' "$work/calls-deliver" && bad+="herstart terwijl de rondes gewoon lopen | "
grep -q '^start\|^stop' "$work/calls-limit" && bad+="na 3 ingrepen toch weer ingegrepen | "
grep -q 'rondes lopen, maar het rapport komt niet aan' "$work/cache/act.log" || bad+="notitie over het rapport mist in het logboek | "
grep -q 'ronde hing, gestopt' "$work/cache/act.log" && grep -q 'na 3 ingrepen' "$work/cache/guard.log" || bad+="logboek mist ingrepen | "
python3 -c "import json,sys; [json.loads(l) for l in open(sys.argv[1])]" "$work/cache/act.log" 2>/dev/null || bad+="logboekregels zijn geen geldige JSON | "
[ -s "$work/err-hang" ] && bad+="foutuitvoer: $(head -c 200 "$work/err-hang") | "
if [ -n "$bad" ]; then echo "✘ bewaker: ${bad% | }"; exit 1; fi
echo "✔ bewaker: niets als de agent rapporteert, hangende ronde gestopt, timer en ronde gestart, geen herstart als alleen het versturen mislukt (notitie), hooguit 3 ingrepen per uur, in het logboek"
