#!/usr/bin/env bash
# Shellcheck in stukken (RzSense 1.270.1): elke module van de agent (agent/src/*.sh) en elk ander script apart, naast elkaar.
# Het gebouwde agent/rzmon-agent.sh (~7000 regels) in één keer controleren kostte zoveel geheugen dat de CI-stap bleef
# hangen. Wat shellcheck per module niet kan beoordelen, staat uit: een variabele die in een andere module wordt gezet
# (SC2154) of daar wordt gebruikt (SC2034). Het gebouwde bestand krijgt wel een syntaxcontrole (bash -n).
#   tools/shellcheck.sh        alles controleren (zelfde als CI)
#   JOBS=2 tools/shellcheck.sh minder tegelijk (weinig geheugen)
set -u
cd "$(dirname "$0")/.." || exit 2
command -v shellcheck >/dev/null || { echo "✘ shellcheck niet gevonden" >&2; exit 2; }
jobs=${JOBS:-$(nproc 2>/dev/null || echo 2)}
out=$(mktemp)
trap 'rm -f "$out"' EXIT

# 1. De modules van de agent (bash; samen vormen ze één script)
printf '%s\0' agent/src/[0-9][0-9]-*.sh | xargs -0 -P "$jobs" -n 1 shellcheck -S warning -s bash -e SC2034,SC2154 -f gcc >>"$out" 2>&1
# 2. De andere scripts: installer, add-on (niet de kopie van de agent), hulpscripts
others=(agent/install-agent.sh tools/*.sh)
for f in ha-addon/rzmon_agent/*.sh; do [ "$(basename "$f")" = rzmon-agent.sh ] || others+=("$f"); done
printf '%s\0' "${others[@]}" | xargs -0 -P "$jobs" -n 1 shellcheck -S warning -f gcc >>"$out" 2>&1
# 3. Het gebouwde bestand: alleen de syntax (licht)
bash -n agent/rzmon-agent.sh 2>>"$out"

if [ -s "$out" ]; then
    cat "$out"
    echo "✘ shellcheck: $(grep -c . "$out") melding(en)" >&2
    exit 1
fi
n=$(( $(printf '%s\n' agent/src/[0-9][0-9]-*.sh | wc -l) + ${#others[@]} ))
echo "✔ shellcheck: $n bestanden zonder meldingen (agent per module, $jobs tegelijk), gebouwde agent syntax ok"
