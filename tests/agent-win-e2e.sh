#!/usr/bin/env bash
# shellcheck disable=SC2016  # PHP-code tussen enkele aanhalingstekens: $ hoort bij PHP, niet bij bash
# 1.313.0: de Windows-agent (Go) tegen een draaiende RzSense: een server "win-e2e" aanmaken, een koppelcode maken,
# en dan koppelen, de testlijst ophalen en twee rapporten versturen (agent-win/e2e_test.go). Daarna controleren wat
# RzSense ervan bewaarde: versie, versleuteld, eigen sleutel en os = windows.
#   BASE=http://127.0.0.1:8099 bash tests/agent-win-e2e.sh
set -euo pipefail
cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8099}
code=$(php -r '
require "src/bootstrap.php";
q("DELETE FROM hosts WHERE name = ?", ["win-e2e"]);
q("INSERT INTO hosts (name, address, mode) VALUES (?, ?, ?)", ["win-e2e", "192.0.2.10", "agent"]);
echo AgentCrypto::pairCreate((int) db()->lastInsertId())["code"];')
[ -n "$code" ] || { echo "✘ geen koppelcode"; exit 1; }
(cd agent-win && RZ_E2E_URL="$BASE" RZ_E2E_PAIR="$code" go test -count=1 -run 'EndToEnd' ./...)
row=$(php -r '
require "src/bootstrap.php";
$h = q("SELECT agent_version, enc_seen, agent_key_seen, os FROM hosts WHERE name = ?", ["win-e2e"])->fetch();
echo implode(" ", [$h["agent_version"] ?? "-", $h["enc_seen"], $h["agent_key_seen"], $h["os"] ?? "-"]);')
ver=$(tr -d '[:space:]' < agent-win/VERSION)
if [ "$row" != "$ver 1 1 windows" ]; then
    echo "✘ RzSense bewaarde iets anders dan verwacht: $row (verwacht: $ver 1 1 windows)"
    exit 1
fi
php -r 'require "src/bootstrap.php"; q("DELETE FROM hosts WHERE name = ?", ["win-e2e"]);'
echo "✔ Windows-agent $ver: gekoppeld, testlijst opgehaald, twee rapporten versleuteld verstuurd; RzSense ziet een Windows-server"
