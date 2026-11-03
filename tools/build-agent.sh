#!/usr/bin/env bash
# De agent bouwen (RzMon 1.147.0 / agent 1.73.0): de modules in agent/src/*.sh, op volgorde van hun nummer,
# samengevoegd tot één bestand agent/rzmon-agent.sh. Dat ene bestand is wat servers krijgen (installeren, bijwerken,
# ondertekenen, de Home Assistant-add-on): daarvoor verandert er niets.
#   tools/build-agent.sh           bouwen
#   tools/build-agent.sh --check   alleen controleren of agent/rzmon-agent.sh klopt met agent/src (CI)
# Wijzig altijd de modules in agent/src, nooit agent/rzmon-agent.sh zelf.
set -eu
cd "$(dirname "$0")/.."

shopt -s nullglob
parts=(agent/src/[0-9][0-9]-*.sh)
[ "${#parts[@]}" -gt 0 ] || { echo "Geen modules in agent/src" >&2; exit 2; }

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
cat "${parts[@]}" >"$tmp"

if [ "${1-}" = --check ]; then
    if cmp -s "$tmp" agent/rzmon-agent.sh; then
        echo "✔ agent/rzmon-agent.sh klopt met agent/src (${#parts[@]} modules)"
        exit 0
    fi
    echo "✘ agent/rzmon-agent.sh wijkt af van agent/src: wijzig de modules in agent/src en voer tools/build-agent.sh uit" >&2
    diff <(cat "${parts[@]}") agent/rzmon-agent.sh | head -20 >&2 || true
    exit 1
fi

cp "$tmp" agent/rzmon-agent.sh
chmod 755 agent/rzmon-agent.sh
echo "✔ agent/rzmon-agent.sh gebouwd uit ${#parts[@]} modules"
