#!/usr/bin/env bash
# Agenttest meetpunt van buitenaf (agent 1.117.0): de module krijgt van "RzSense" een adres en poorten, probeert ze met een
# TCP-verbinding en geeft de open poort terug (en niet de dichte). Een privéadres en een kapotte regel worden overgeslagen,
# en dezelfde vraag wordt geen tweede keer uitgevoerd. Het adres is het eigen (niet-privé) adres van de machine; zonder zo'n
# adres of zonder python3 overgeslagen.
# Gebruik: bash tests/agent-exprobe.sh
set -u
cd "$(dirname "$0")/.." || exit 1
ip=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -Ev '^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|169\.254\.)' | grep -E '^[0-9.]+$' | head -1)
if [ -z "$ip" ] || ! command -v python3 >/dev/null 2>&1; then
    echo "• meetpunt van buitenaf: overgeslagen (geen openbaar of test-adres, of geen python3)"; exit 0
fi
work=$(mktemp -d)
open_p=18555; shut_p=18556
python3 -I -c "import socket,time; s=socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('$ip', $open_p)); s.listen(5); time.sleep(30)" &
srv=$!
trap 'kill "$srv" 2>/dev/null; rm -rf "$work"' EXIT
sleep 1
export t_dir="$work" TEST=0; t_cache="$work/cache"
printf '0\texprobe\t42\t%s\t%s,%s\n0\texprobe\t42\t192.168.1.1\t%s\n0\texprobe\t42\t1.2.3.4\t80;rm -rf /\n' "$ip" "$open_p" "$shut_p" "$open_p" >"$t_cache"
# shellcheck source=/dev/null
. agent/src/89-buitenprobe.sh
bad=""
[ "$exprobe_json" = "{\"id\":42,\"partial\":0,\"res\":{\"$ip\":[$open_p]}}" ] || bad+="antwoord niet goed: $exprobe_json | "
exprobe_json=""
# shellcheck source=/dev/null
. agent/src/89-buitenprobe.sh
[ -z "$exprobe_json" ] || bad+="dezelfde vraag nog eens uitgevoerd | "
if [ -z "$bad" ]; then
    echo "✔ meetpunt van buitenaf: open poort gevonden, dichte niet, privéadres en kapotte regel overgeslagen, één keer per vraag"
else
    echo "✘ meetpunt van buitenaf: $bad"; exit 1
fi
