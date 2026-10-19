#!/usr/bin/env bash
# Controleert of alle versienummers gelijk zijn en de changelog bijgewerkt is.
# Wordt ook door GitHub Actions uitgevoerd (.github/workflows/version-check.yml).
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
version=$(tr -d '[:space:]' < VERSION)
check() {  # check "omschrijving" "gevonden waarde"
    if [ "$2" = "$version" ]; then
        printf '  ✔ %-38s %s\n' "$1" "$2"
    else
        printf '  ✘ %-38s %s (verwacht %s)\n' "$1" "${2:-ontbreekt}" "$version"
        fail=1
    fi
}

echo "VERSION: $version"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "  ✘ VERSION is geen X.Y.Z"; exit 1; }
check "agent/rzmon-agent.sh (AGENT_VERSION)" "$(sed -n 's/^AGENT_VERSION="\([^"]*\)".*/\1/p' agent/rzmon-agent.sh)"
check "ha-addon/rzmon_agent/config.yaml" "$(sed -n 's/^version: "\([^"]*\)".*/\1/p' ha-addon/rzmon_agent/config.yaml)"
check "CHANGELOG.md (bovenste versie)" "$(sed -n 's/^## \([0-9][0-9.]*\) .*/\1/p' CHANGELOG.md | head -1)"
if cmp -s agent/rzmon-agent.sh ha-addon/rzmon_agent/rzmon-agent.sh; then
    echo "  ✔ agent-kopie in de HA-add-on is gelijk"
else
    echo "  ✘ ha-addon/rzmon_agent/rzmon-agent.sh wijkt af van agent/rzmon-agent.sh"
    fail=1
fi
if sed -n "/^## $version /,/^## /p" CHANGELOG.md | grep -qE '^- *$'; then
    echo "  ✘ CHANGELOG.md: de sectie van $version heeft nog een lege regel '- '"
    fail=1
fi
exit "$fail"
