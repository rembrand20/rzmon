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
esac
exit 0
EOS
chmod 755 "$work/bin/systemctl"
: >"$work/units/rzmon-agent.timer"; : >"$work/units/rzmon-agent-guard.timer"; : >"$work/fallback.timer"
# 1.346.0: updates installeren start de Go-agent via systemd-run; hier voert een namaak het commando gewoon uit
cat >"$work/bin/systemd-run" <<'EOS'
#!/bin/sh
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

# Softwarelijst (1.347.0): de hele lijst ging één keer mee; daarna alleen het controlegetal
got=$(take '$v = q("SELECT mgr, packages, hash FROM vuln_inventory WHERE host_id = ?", [$id])->fetch(); echo $v ? ((count((array) json_decode((string) $v["packages"], true)) > 50 ? "lijst" : "leeg") . " " . (preg_match("/^[0-9a-f]{16}$/", (string) $v["hash"]) ? "hash" : "geen-hash")) : "niets";')
[ "$got" = "lijst hash" ] || fail "de softwarelijst kwam niet bij RzSense aan: $got $(head -c 300 "$work/data/pkgs.json")"
[ -s "$work/data/pkgs.sent" ] || fail "de Go-agent onthield niet dat RzSense de softwarelijst heeft (zou hem elke minuut opnieuw sturen)"
echo "✔ softwarelijst: aangekomen bij RzSense; daarna gaat alleen het controlegetal mee"

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
