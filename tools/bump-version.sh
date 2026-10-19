#!/usr/bin/env bash
# Verhoogt het versienummer van RzMon overal tegelijk.
#
#   tools/bump-version.sh patch|minor|major     # 1.6.0 → 1.6.1 / 1.7.0 / 2.0.0
#   tools/bump-version.sh 1.7.0                 # exact nummer
#
# Werkt bij: VERSION, de agent (AGENT_VERSION), de kopie van de agent in de
# Home Assistant-add-on en de add-on-versie (HA ziet alleen zo een update).
# Zet ook een lege sectie bovenaan CHANGELOG.md die je daarna invult.
set -euo pipefail
cd "$(dirname "$0")/.."

current=$(tr -d '[:space:]' < VERSION)
IFS=. read -r major minor patch <<<"$current"

case "${1-}" in
    patch) new="$major.$minor.$((patch + 1))" ;;
    minor) new="$major.$((minor + 1)).0" ;;
    major) new="$((major + 1)).0.0" ;;
    [0-9]*.[0-9]*.[0-9]*) new=$1 ;;
    *) echo "Gebruik: $0 patch|minor|major|X.Y.Z   (nu: $current)" >&2; exit 1 ;;
esac
[[ "$new" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Ongeldig versienummer: $new" >&2; exit 1; }

printf '%s\n' "$new" > VERSION
sed -i "s/^AGENT_VERSION=\"[^\"]*\"/AGENT_VERSION=\"$new\"/" agent/rzmon-agent.sh
cp agent/rzmon-agent.sh ha-addon/rzmon_agent/rzmon-agent.sh
sed -i "s/^version: \"[^\"]*\"/version: \"$new\"/" ha-addon/rzmon_agent/config.yaml

if ! grep -q "^## $new " CHANGELOG.md; then
    awk -v v="$new" -v d="$(date +%F)" '
        !done && /^## [0-9]/ { print "## " v " – " d "\n### Nieuw\n- \n"; done = 1 }
        { print }' CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md
fi

echo "Versie $current → $new"
echo "Vul nu CHANGELOG.md in en controleer met: tools/check-version.sh"
