#!/usr/bin/env bash
# De interface bouwen (RzSense 1.225.0): de modules in public/assets/src/*.js, op volgorde van hun nummer,
# samengevoegd tot één bestand public/assets/app.js. Dat ene bestand laadt de browser; daarvoor verandert er niets
# (geen build-stap op de server nodig, geen extra verzoeken).
#   tools/build-js.sh           bouwen
#   tools/build-js.sh --check   alleen controleren of public/assets/app.js klopt met public/assets/src (CI)
# Wijzig altijd de modules in public/assets/src, nooit public/assets/app.js zelf.
set -eu
cd "$(dirname "$0")/.."

shopt -s nullglob
parts=(public/assets/src/[0-9][0-9]-*.js)
[ "${#parts[@]}" -gt 0 ] || { echo "Geen modules in public/assets/src" >&2; exit 2; }

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
cat "${parts[@]}" >"$tmp"

if [ "${1-}" = --check ]; then
    if cmp -s "$tmp" public/assets/app.js; then
        echo "✔ public/assets/app.js klopt met public/assets/src (${#parts[@]} modules)"
        exit 0
    fi
    echo "✘ public/assets/app.js wijkt af van public/assets/src: wijzig de modules en voer tools/build-js.sh uit" >&2
    diff <(cat "${parts[@]}") public/assets/app.js | head -20 >&2 || true
    exit 1
fi

cp "$tmp" public/assets/app.js
chmod 644 public/assets/app.js
echo "✔ public/assets/app.js gebouwd uit ${#parts[@]} modules"
