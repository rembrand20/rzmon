#!/usr/bin/env bash
# Controleert de versienummers van RzMon en van de agent, en of de changelogs bijgewerkt zijn.
# RzMon en de agent hebben elk een eigen nummer; de HA-add-on volgt de agent.
# Wordt ook door GitHub Actions uitgevoerd (.github/workflows/version-check.yml).
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
ADDON=ha-addon/rzmon_agent
check() {  # check "omschrijving" "gevonden waarde" "verwachte waarde"
    if [ "$2" = "$3" ]; then
        printf '  ✔ %-38s %s\n' "$1" "$2"
    else
        printf '  ✘ %-38s %s (verwacht %s)\n' "$1" "${2:-ontbreekt}" "$3"
        fail=1
    fi
}
top() { sed -n 's/^## \([0-9][0-9.]*\)\( .*\)\{0,1\}$/\1/p' "$1" | head -1; }
filled() {  # filled <changelog> <versie>: geen lege regel "- " meer in die sectie
    if sed -n "/^## $2\( \|$\)/,/^## /p" "$1" | grep -qE '^- *$'; then
        echo "  ✘ $1: de sectie van $2 heeft nog een lege regel '- '"
        fail=1
    fi
}
same() {  # same <origineel> <kopie>
    if cmp -s "$1" "$2"; then
        printf '  ✔ %-38s gelijk\n' "$2"
    else
        echo "  ✘ $2 wijkt af van $1 (kopiëren met: tools/bump-version.sh sync)"
        fail=1
    fi
}

version=$(tr -d '[:space:]' < VERSION)
agent=$(tr -d '[:space:]' < agent/VERSION)
for v in "$version" "$agent"; do
    [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "  ✘ $v is geen X.Y.Z"; exit 1; }
done

echo "RzMon: $version"
check "CHANGELOG.md (bovenste versie)" "$(top CHANGELOG.md)" "$version"
filled CHANGELOG.md "$version"

echo "Agent: $agent"
check "agent/rzmon-agent.sh (AGENT_VERSION)" "$(sed -n 's/^AGENT_VERSION="\([^"]*\)".*/\1/p' agent/rzmon-agent.sh)" "$agent"
check "$ADDON/config.yaml" "$(sed -n 's/^version: "\([^"]*\)".*/\1/p' "$ADDON/config.yaml")" "$agent"
check "agent/CHANGELOG.md (bovenste versie)" "$(top agent/CHANGELOG.md)" "$agent"
filled agent/CHANGELOG.md "$agent"
same agent/rzmon-agent.sh "$ADDON/rzmon-agent.sh"
same agent/CHANGELOG.md "$ADDON/CHANGELOG.md"
exit "$fail"
