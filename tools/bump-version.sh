#!/usr/bin/env bash
# Verhoogt het versienummer van RzMon of van de agent. Die hebben elk een eigen nummer.
#
#   tools/bump-version.sh rzmon patch|minor|major|X.Y.Z   # RzMon (webinterface, API, database)
#   tools/bump-version.sh agent patch|minor|major|X.Y.Z   # de agent en de Home Assistant-add-on
#   tools/bump-version.sh sync                            # agent en agent-changelog naar de add-on kopiëren
#
# rzmon: werkt VERSION bij en zet een lege sectie bovenaan CHANGELOG.md.
# agent: werkt agent/VERSION, AGENT_VERSION in agent/rzmon-agent.sh en de add-on-versie bij
#        (HA ziet alleen zo een update), kopieert de agent naar de add-on en zet een lege sectie
#        bovenaan agent/CHANGELOG.md.
# Vul daarna de changelog in en voer "sync" uit (de add-on krijgt een kopie van agent/CHANGELOG.md).
set -euo pipefail
cd "$(dirname "$0")/.."

ADDON=ha-addon/rzmon_agent

sync() {
    cp agent/rzmon-agent.sh "$ADDON/rzmon-agent.sh"
    cp agent/CHANGELOG.md "$ADDON/CHANGELOG.md"
}

# changelog_section <bestand> <versie>: lege sectie bovenaan, als die er nog niet is
changelog_section() {
    grep -q "^## $2\( \|$\)" "$1" && return 0
    local sub='\n### Nieuw'
    [ "$1" = agent/CHANGELOG.md ] && sub=''   # de agent-changelog heeft geen kopjes per soort
    awk -v head="## $2 – $(date +%F)$sub" '
        !done && /^## [0-9]/ { print head "\n- \n"; done = 1 }
        { print }' "$1" >"$1.tmp" && mv "$1.tmp" "$1"
}

what=${1-}
[ "$what" = sync ] && { sync; echo "Agent en agent-changelog gekopieerd naar $ADDON"; exit 0; }
case "$what" in
    rzmon) file=VERSION; log=CHANGELOG.md ;;
    agent) file=agent/VERSION; log=agent/CHANGELOG.md ;;
    *) echo "Gebruik: $0 rzmon|agent patch|minor|major|X.Y.Z   of   $0 sync" >&2
       echo "  (nu: RzMon $(tr -d '[:space:]' < VERSION), agent $(tr -d '[:space:]' < agent/VERSION))" >&2
       exit 1 ;;
esac

current=$(tr -d '[:space:]' < "$file")
IFS=. read -r major minor patch <<<"$current"
case "${2-}" in
    patch) new="$major.$minor.$((patch + 1))" ;;
    minor) new="$major.$((minor + 1)).0" ;;
    major) new="$((major + 1)).0.0" ;;
    [0-9]*.[0-9]*.[0-9]*) new=$2 ;;
    *) echo "Gebruik: $0 $what patch|minor|major|X.Y.Z   (nu: $current)" >&2; exit 1 ;;
esac
[[ "$new" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Ongeldig versienummer: $new" >&2; exit 1; }

printf '%s\n' "$new" >"$file"
if [ "$what" = agent ]; then
    sed -i "s/^AGENT_VERSION=\"[^\"]*\"/AGENT_VERSION=\"$new\"/" agent/rzmon-agent.sh
    sed -i "s/^version: \"[^\"]*\"/version: \"$new\"/" "$ADDON/config.yaml"
fi
changelog_section "$log" "$new"
[ "$what" = agent ] && sync

echo "Versie van $what: $current → $new"
echo "Vul nu $log in$([ "$what" = agent ] && echo ", voer '$0 sync' uit") en controleer met: tools/check-version.sh"
