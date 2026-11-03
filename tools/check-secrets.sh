#!/usr/bin/env bash
# Geheimen in de repo? (1.143.0) Zoekt in alle bestanden die git bijhoudt naar dingen die er nooit in mogen:
# privésleutels, config.php, en tokens van bekende diensten. Draait in GitHub Actions (workflow Beveiliging) en lokaal:
#   tools/check-secrets.sh
# Een treffer die echt geen geheim is (bv. een testsleutel)? Zet "check-secrets: ok" op dezelfde regel.
set -u
cd "$(dirname "$0")/.." || exit 2

fail=0
hit() { printf '✘ %s\n' "$1"; fail=1; }

# config.php bevat wachtwoorden en hoort nooit in git
if git ls-files --error-unmatch config.php >/dev/null 2>&1; then
    hit "config.php staat in git (hoort in .gitignore)"
fi

# Patronen: naam|regex (grep -E)
patterns=(
    'privésleutel|-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
    'AWS-sleutel|AKIA[0-9A-Z]{16}'
    'GitHub-token|(ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{60,}'
    'Slack-token|xox[abprs]-[A-Za-z0-9-]{10,}'
    'Google-API-sleutel|AIza[0-9A-Za-z_-]{35}'
    'Stripe-sleutel|sk_live_[0-9A-Za-z]{20,}'
    'OpenAI/Anthropic-sleutel|sk-(ant-)?[A-Za-z0-9_-]{32,}'
)

mapfile -t files < <(git ls-files | grep -vE '^(public/assets/vendor/|.*\.(png|jpg|jpeg|gif|ico|svg|woff2?|gz|zip|pdf))$')
for p in "${patterns[@]}"; do
    name=${p%%|*}
    re=${p#*|}
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        case $line in *check-secrets:\ ok*) continue ;; esac
        hit "$name: ${line:0:160}"
    done < <(grep -nIE -- "$re" "${files[@]}" 2>/dev/null | grep -v '^tools/check-secrets.sh:')
done

if [ "$fail" = 0 ]; then
    echo "✔ geen geheimen gevonden in ${#files[@]} bestanden"
fi
exit "$fail"
