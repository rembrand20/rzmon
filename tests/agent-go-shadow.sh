#!/usr/bin/env bash
# shellcheck disable=SC2016  # PHP-code tussen enkele aanhalingstekens: $ hoort bij PHP, niet bij bash
# 1.339.0: de Go-agent voor Linux (het commando rzsense) tegen een draaiende RzSense, als proef naast de bash-agent.
# Een server "go-e2e" met een eigen sleutel aanmaken, de instellingen en sleutel van de "bash-agent" in een tijdelijke map
# zetten, en dan: rzsense test, doctor en shadow. Daarna controleren dat RzSense de proefronde apart bewaarde en dat de
# server zelf (contactmoment, versie, sleutel) en de bestanden van de bash-agent niet veranderd zijn.
#   BASE=http://127.0.0.1:8099 bash tests/agent-go-shadow.sh
set -euo pipefail
cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8099}
work=$(mktemp -d)
trap 'rm -rf "$work"; php -r "require \"src/bootstrap.php\"; q(\"DELETE FROM hosts WHERE name = ?\", [\"go-e2e\"]);" 2>/dev/null || true' EXIT
fail() { echo "✘ $1"; exit 1; }

(cd agent-win && CGO_ENABLED=0 go build -trimpath -o "$work/rzsense" .)
[ "$("$work/rzsense" version)" = "rzsense $(tr -d '[:space:]' < agent-win/VERSION)" ] || fail "rzsense version klopt niet met agent-win/VERSION"

key=$(php -r '
require "src/bootstrap.php";
q("DELETE FROM hosts WHERE name = ?", ["go-e2e"]);
q("INSERT INTO hosts (name, address, mode) VALUES (?, ?, ?)", ["go-e2e", "192.0.2.11", "agent"]);
echo AgentCrypto::newHostKey((int) db()->lastInsertId(), false);')
[[ "$key" =~ ^[0-9a-f]{64}$ ]] || fail "geen sleutel voor de testserver"
mkdir -p "$work/keys" "$work/data"
# 1.341.0: een certificaat met keten (bestand), een bron waar niets luistert, en twee proces-checks (naam en patroon)
mkdir -p "$work/certs" "$work/cache"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$work/certs/ca.key" -out "$work/certs/ca.pem" -days 30 -subj "/O=Proef BV/CN=Proef CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$work/certs/leaf.key" -out "$work/certs/leaf.csr" -subj "/CN=nas.voorbeeld" >/dev/null 2>&1
openssl x509 -req -in "$work/certs/leaf.csr" -CA "$work/certs/ca.pem" -CAkey "$work/certs/ca.key" -CAcreateserial -out "$work/certs/leaf.pem" -days 90 >/dev/null 2>&1
cat "$work/certs/leaf.pem" "$work/certs/ca.pem" >"$work/certs/fullchain.pem"
# Los van dit script gestart: de bash-agent slaat zijn eigen kinderen over, en hier is dit script "de agent"
# Onder een eigen naam: op een drukke machine draait er zomaar een andere "sleep" die tussen de twee metingen stopt
cp "$(command -v sleep)" "$work/rzproef-slaap"
("$work/rzproef-slaap" 31337 >/dev/null 2>&1 & echo $! >"$work/sleeper.pid")
sleeper=$(cat "$work/sleeper.pid")
trap 'kill "$sleeper" 2>/dev/null || true; rm -rf "$work"; php -r "require \"src/bootstrap.php\"; q(\"DELETE FROM hosts WHERE name = ?\", [\"go-e2e\"]);" 2>/dev/null || true' EXIT
CERT_SOURCES="$work/certs/fullchain.pem 127.0.0.1:1"
printf 'MONITOR_URL="%s"\nSERVICES="sshd"\nCERTS="%s"\n' "$BASE" "$CERT_SOURCES" >"$work/rzmon-agent"
printf '7\tsvc\tcron\t\n8\tival\t60\t\n21\tproc\tname\t%s\t\n22\tproc\tpattern\t%s\t\n23\tproc\tname\t%s\t\n' \
    "$(printf 'rzproef-slaap' | base64)" "$(printf 'rzproef-slaap 3133[7]$' | base64)" "$(printf 'bestaat-niet-xyz' | base64)" >"$work/cache/tests.cache"
printf '%s\n' "$key" >"$work/keys/agent.key"
before=$(cat "$work/rzmon-agent" "$work/keys/agent.key" "$work/cache/tests.cache" "$work/certs/fullchain.pem" | sha256sum)
export RZSENSE_BASH_CONF="$work/rzmon-agent" RZSENSE_BASH_KEYS="$work/keys" RZSENSE_DATA="$work/data" RZSENSE_BASH_CACHE="$work/cache"

out=$("$work/rzsense" test) || fail "rzsense test: $out"
grep -q 'herkent deze server als "go-e2e"' <<<"$out" || fail "rzsense test herkent de server niet: $out"
echo "✔ rzsense test: RzSense herkent de server, het antwoord is versleuteld met de eigen sleutel"

# Wat de bash-agent "net meldde": de metingen van deze machine zelf (zo horen de twee gelijk te zijn)
"$work/rzsense" collect >"$work/core.json"
CORE="$work/core.json" php -r '
require "src/bootstrap.php";
$c = json_decode((string) file_get_contents(getenv("CORE")), true);
$id = (int) q("SELECT id FROM hosts WHERE name = ?", ["go-e2e"])->fetchColumn();
AgentData::put($id, "cpu", $c["cpu"]);
AgentData::put($id, "mem", $c["mem"]);
AgentData::put($id, "boot", (string) $c["boot"]);
foreach ($c["disks"] as $d) { AgentData::put($id, "disk:" . $d["mount"], ["total" => $d["total"], "avail" => $d["avail"]]); }
foreach ($c["svc"] as $n => $d) { AgentData::put($id, "svcinfo:" . $n, $d); }   // 1.342.0: dienstdetails, extra tests, dienstenlijst
foreach ($c["svc_tests"] as $n => $d) { AgentData::put($id, "svctest:" . $n, $d); }
if ($c["svc_list"]) { AgentData::put($id, "svclist", $c["svc_list"]); }
AgentData::put($id, "ports", $c["ports"]);   // 1.340.0: poorten en diensten
foreach ($c["services"] as $n => $st) { AgentData::put($id, "service:" . $n, $st); }'
# 1.341.0: certificaten en processen meet hier de échte code van de bash-agent (uit agent/src), niet de Go-agent zelf
# shellcheck disable=SC2034,SC2154  # de variabelen horen bij de code van de bash-agent die hier wordt ingelezen
bash_out=$(
    set +eu
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^cert_line() {/,/^certs=""/p' agent/src/75-certificaten-processen-software.sh | sed '$d')
    cert_list="$CERT_SOURCES"
    printf 'CERTS\t[%s]\n' "$(collect_certs | sed 's/,$//')"
    HA_MODE=0; TEST=0; t_cache="$work/cache/tests.cache"
    # shellcheck disable=SC1090
    source <(sed -n '/^procs_json=""; proc_list=""/,/^# -\{10,\} Software/p' agent/src/75-certificaten-processen-software.sh | sed '$d')
    printf 'PROCS\t{%s}\n' "${procs_json%,}"
    printf 'PLIST\t[%s]\n' "${proc_list%,}"
)
BASH_OUT="$bash_out" php -r '
require "src/bootstrap.php";
$id = (int) q("SELECT id FROM hosts WHERE name = ?", ["go-e2e"])->fetchColumn();
$in = [];
foreach (explode("\n", (string) getenv("BASH_OUT")) as $l) { [$k, $v] = array_pad(explode("\t", $l, 2), 2, ""); $in[$k] = json_decode($v, true); }
if (!is_array($in["CERTS"] ?? null) || !is_array($in["PROCS"] ?? null) || !is_array($in["PLIST"] ?? null)) { fwrite(STDERR, "de bash-code gaf geen geldige JSON: " . getenv("BASH_OUT")); exit(1); }
foreach ($in["CERTS"] as $c) { AgentData::put($id, "cert:" . $c["source"], array_diff_key($c, ["source" => 1])); }
foreach ($in["PROCS"] as $pid => $p) { AgentData::put($id, "proc:" . (int) $pid, $p); }
AgentData::put($id, "proclist", $in["PLIST"]);
echo count($in["CERTS"]), " ", count($in["PROCS"]), " ", count($in["PLIST"]), "\n";' >"$work/bash.n" || fail "de bash-code voor certificaten en processen draaide niet"
read -r n_cert n_proc n_plist <"$work/bash.n"
if [ "$n_cert" != 2 ] || [ "$n_proc" != 3 ] || [ "$n_plist" -lt 3 ]; then fail "de bash-agent mat iets anders dan verwacht: $n_cert certificaten, $n_proc proces-checks, $n_plist processen"; fi
echo "✔ de bash-code mat $n_cert certificaten, $n_proc proces-checks en $n_plist processen om mee te vergelijken"
[ "$(php -r 'echo count(json_decode((string) file_get_contents($argv[1]), true)["services"]);' "$work/core.json")" = 2 ] || fail "de Go-agent vond de twee diensten uit de testlijst en SERVICES niet"
if command -v ss >/dev/null 2>&1; then
    want=$(ss -Htln | awk '{ a = $4; p = a; sub(/.*:/, "", p); sub(/:[^:]*$/, "", a); gsub(/[\[\]]/, "", a); print p "|" a }' | sort -u | tr '\n' ' ')
    got=$(php -r 'foreach (json_decode((string) file_get_contents($argv[1]), true)["ports"] as $p) { echo $p["port"], "|", $p["addr"], "\n"; }' "$work/core.json" | sort -u | tr '\n' ' ')
    [ "$want" = "$got" ] || fail "poorten verschillen van ss: ss [$want] go [$got]"
    echo "✔ dezelfde luisterende poorten als ss"
fi
seen=$(php -r 'require "src/bootstrap.php"; echo json_encode(q("SELECT agent_seen, agent_version, agent_key, agent_key_id, enc_seen FROM hosts WHERE name = ?", ["go-e2e"])->fetch());')

out=$("$work/rzsense" shadow) || fail "rzsense shadow: $out"
grep -Eq '✔ ([0-9]+) van \1 metingen gelijk' <<<"$out" || fail "de proefronde was niet helemaal gelijk: $out"
php -r '
require "src/bootstrap.php";
$id = (int) q("SELECT id FROM hosts WHERE name = ?", ["go-e2e"])->fetchColumn();
$k = array_column(AgentShadow::get($id)["shadow"]["items"], "k");
foreach (["certificaten: lijst", "proces-check #21", "proces-check #22", "proces-check #23", "processen: lijst"] as $want) { if (!in_array($want, $k, true)) { fwrite(STDERR, "niet vergeleken: $want\n"); exit(1); } }
if (count(array_filter($k, static fn($x) => str_starts_with($x, "certificaat "))) !== 2) { fwrite(STDERR, "niet beide certificaten vergeleken\n"); exit(1); }' || fail "certificaten of processen zijn niet vergeleken"
first=${out%%$'\n'*}
echo "✔ rzsense shadow: ${first#* ✔ }"
"$work/rzsense" shadow --quiet >/dev/null || fail "tweede proefronde mislukt"
[ -s "$work/data/certs.cache" ] || fail "de Go-agent bewaart de certificaten niet (dan leest hij ze elke minuut opnieuw)"

got=$(php -r '
require "src/bootstrap.php";
$id = (int) q("SELECT id FROM hosts WHERE name = ?", ["go-e2e"])->fetchColumn();
$s = AgentShadow::get($id)["shadow"];
echo implode(" ", [$s["rounds"], $s["rounds_ok"], $s["version"], $s["n"] >= 8 ? "ok" : "weinig", $s["live"] ? "live" : "oud"]);')
[ "$got" = "2 2 $(tr -d '[:space:]' < agent-win/VERSION) ok live" ] || fail "RzSense bewaarde iets anders dan verwacht: $got"
echo "✔ RzSense bewaarde de proef apart: 2 van 2 rondes gelijk"

after=$(php -r 'require "src/bootstrap.php"; echo json_encode(q("SELECT agent_seen, agent_version, agent_key, agent_key_id, enc_seen FROM hosts WHERE name = ?", ["go-e2e"])->fetch());')
[ "$seen" = "$after" ] || fail "de proefronde veranderde de server in RzSense (contactmoment, versie of sleutel)"
[ "$before" = "$(cat "$work/rzmon-agent" "$work/keys/agent.key" "$work/cache/tests.cache" "$work/certs/fullchain.pem" | sha256sum)" ] || fail "de bestanden van de bash-agent zijn veranderd"
[ "$(find "$work/keys" -type f | wc -l)" = 1 ] || fail "er is iets bijgeschreven in de sleutelmap van de bash-agent"
echo "✔ de server in RzSense en de bestanden van de bash-agent zijn niet veranderd"

# Een verkeerde sleutel wordt geweigerd, en doctor zegt wat er mis is
printf '%s\n' "$(printf 'ab%.0s' $(seq 32))" >"$work/keys/agent.key"
if "$work/rzsense" shadow --quiet 2>/dev/null; then fail "een proefronde met een verkeerde sleutel werd aangenomen"; fi
doc=$("$work/rzsense" doctor 2>&1) && fail "doctor vond niets bij een verkeerde sleutel"
if ! grep -q '✘ RzSense herkent deze server' <<<"$doc" || ! grep -q 'koppel' <<<"$doc"; then fail "doctor legt de verkeerde sleutel niet uit: $doc"; fi
echo "✔ een verkeerde sleutel wordt geweigerd; rzsense doctor zegt wat je eraan doet"
printf '%s\n' "$key" >"$work/keys/agent.key"
st=$("$work/rzsense" status)
grep -q 'Eigen sleutel: true' <<<"$st" || fail "rzsense status toont de sleutel niet: $st"
echo "✔ rzsense status"
