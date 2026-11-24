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
$id = (int) db()->lastInsertId();
// 1.314.0: een Windows-logboek als logbron: de agent stuurt (versleuteld) een logpakket met de stand van die bron
q("INSERT INTO log_sources (host_id, name, kind, spec, level) VALUES (?, ?, ?, ?, ?)", [$id, "System", "winevt", "System", 0]);
echo AgentCrypto::pairCreate($id)["code"];')
[ -n "$code" ] || { echo "✘ geen koppelcode"; exit 1; }
(cd agent-win && RZ_E2E_URL="$BASE" RZ_E2E_PAIR="$code" go test -count=1 -run 'EndToEnd' ./...)
row=$(php -r '
require "src/bootstrap.php";
$h = q("SELECT agent_version, enc_seen, agent_key_seen, os FROM hosts WHERE name = ?", ["win-e2e"])->fetch();
echo implode(" ", [$h["agent_version"] ?? "-", $h["enc_seen"], $h["agent_key_seen"], $h["os"] ?? "-"]);')
ver=$(tr -d '[:space:]' < agent-win/VERSION)
ls=$(php -r 'require "src/bootstrap.php"; echo (string) q("SELECT s.status FROM log_sources s JOIN hosts h ON h.id = s.host_id WHERE h.name = ?", ["win-e2e"])->fetchColumn();')
if [ "$ls" != ok ]; then
    echo "✘ logpakket van de Windows-agent niet (goed) aangekomen: status van de logbron = ${ls:-leeg}"
    exit 1
fi
if [ "$row" != "$ver 1 1 windows" ]; then
    echo "✘ RzSense bewaarde iets anders dan verwacht: $row (verwacht: $ver 1 1 windows)"
    exit 1
fi
php -r 'require "src/bootstrap.php"; q("DELETE FROM hosts WHERE name = ?", ["win-e2e"]);'
# 1.317.0: aanmelden met een uitrolcode (de server wordt aangemaakt met de computernaam)
hn=$(hostname | tr '[:upper:]' '[:lower:]')
enroll=$(HN="$hn" php -r '
require "src/bootstrap.php";
q("DELETE FROM hosts WHERE LOWER(name) = ?", [getenv("HN")]);
echo AgentEnroll::create(["name" => "e2e", "max" => 5, "days" => 1], "e2e")["code"];')
(cd agent-win && RZ_E2E_URL="$BASE" RZ_E2E_ENROLL="$enroll" go test -count=1 -run 'EnrollEndToEnd' ./...)
er=$(HN="$hn" php -r 'require "src/bootstrap.php"; $h = q("SELECT os, agent_key_seen, enc_seen FROM hosts WHERE LOWER(name) = ?", [getenv("HN")])->fetch() ?: [];
echo implode(" ", [$h["os"] ?? "-", $h["agent_key_seen"] ?? "-", $h["enc_seen"] ?? "-", q("SELECT used FROM agent_enroll WHERE name = ?", ["e2e"])->fetchColumn()]);
q("DELETE FROM hosts WHERE LOWER(name) = ?", [getenv("HN")]); q("DELETE FROM agent_enroll WHERE name = ?", ["e2e"]);')
if [ "$er" != "windows 1 1 1" ]; then
    echo "✘ aanmelden met een uitrolcode: RzSense bewaarde $er (verwacht: windows 1 1 1)"
    exit 1
fi
echo "✔ Windows-agent $ver: gekoppeld, testlijst opgehaald, twee rapporten en een logpakket versleuteld verstuurd; RzSense ziet een Windows-server; aanmelden met een uitrolcode werkt"
