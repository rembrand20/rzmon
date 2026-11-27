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
cp "$(command -v sleep)" /tmp/rzproef-ids-verdacht 2>/dev/null && (/tmp/rzproef-ids-verdacht 40 >/dev/null 2>&1 & echo $! >"$I/pid")
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
