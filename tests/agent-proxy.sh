#!/usr/bin/env bash
# Agenttest reverse proxy beschermen (agent 1.97.0): een nagebootste SWAG (echte nginx, een nep-podman die ps, inspect en
# exec nadoet). De agent vindt de sites, schrijft het beleid (adminpad alleen LAN, hele site alleen LAN, slechte bots,
# rate limit), zet de haak in ssl.conf, test en herlaadt; daarna geeft /admin van buiten 403 en de site zelf gewoon 200.
# Een tweede ronde verandert niets; uit haalt alles weg en ssl.conf is weer precies zoals eerst. Een beleid dat nginx
# afkeurt wordt teruggezet. Alleen als root en met nginx, curl en openssl; anders overgeslagen.
# Gebruik: bash tests/agent-proxy.sh
set -u
cd "$(dirname "$0")/.." || exit 1
if [ "$(id -u)" != 0 ] || ! command -v nginx >/dev/null 2>&1 || ! command -v curl >/dev/null 2>&1 || ! command -v openssl >/dev/null 2>&1; then
    echo "• reverse proxy: overgeslagen (geen root, nginx, curl of openssl)"; exit 0
fi
work=$(mktemp -d)
port=18443
trap '[ -f "$work/nginx.pid" ] && kill "$(cat "$work/nginx.pid")" 2>/dev/null; rm -rf "$work"' EXIT
C="$work/config/nginx"
mkdir -p "$C/site-confs" "$C/proxy-confs" "$work/bin" "$work/cache" "$work/logs"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=test -keyout "$work/key.pem" -out "$work/cert.pem" >/dev/null 2>&1
# SWAG in het klein: nginx.conf neemt site-confs op; elke site neemt ssl.conf op
cat >"$work/nginx.conf" <<EOF
pid $work/nginx.pid;
error_log $work/logs/error.log;
events {}
http {
    access_log off;
    client_body_temp_path $work/logs; proxy_temp_path $work/logs; fastcgi_temp_path $work/logs; uwsgi_temp_path $work/logs; scgi_temp_path $work/logs;
    include $C/site-confs/*.conf;
}
EOF
printf '# ssl.conf van SWAG\nssl_certificate %s;\nssl_certificate_key %s;\n' "$work/cert.pem" "$work/key.pem" >"$C/ssl.conf"
cp "$C/ssl.conf" "$work/ssl.orig"
cat >"$C/site-confs/default.conf" <<EOF
include $C/proxy-confs/*.subdomain.conf;
EOF
for app in vaultwarden portainer blog; do
    cat >"$C/proxy-confs/$app.subdomain.conf" <<EOF
server {
    listen 127.0.0.1:$port ssl;
    server_name $app.*;
    include $C/ssl.conf;
    set \$upstream_app $app;
    set \$upstream_port 80;
    location / { return 200 "ok $app"; }
}
EOF
done
cp "$C/proxy-confs/blog.subdomain.conf" "$C/proxy-confs/oud.subdomain.conf.sample"
# Nep-podman: ps, inspect (mounts en env) en exec (nginx -t en herladen met de test-nginx)
cat >"$work/bin/podman" <<EOF
#!/usr/bin/env bash
case "\$1" in
    ps) echo "swag lscr.io/linuxserver/swag:latest" ;;
    inspect)
        case "\$*" in *Mounts*) echo "/config $work/config" ;; *Env*) echo "URL=example.test" ;; *) exit 0 ;; esac ;;
    exec)
        shift 2
        if [ "\$1 \$2" = "nginx -t" ]; then nginx -t -c "$work/nginx.conf" 2>&1
        elif [ "\$1 \$2 \$3" = "nginx -s reload" ]; then nginx -s reload -c "$work/nginx.conf" 2>&1; fi ;;
esac
EOF
chmod +x "$work/bin/podman"
nginx -c "$work/nginx.conf" 2>"$work/err" || { echo "✘ reverse proxy: test-nginx start niet: $(cat "$work/err")"; exit 1; }
awk '/^# -+ Reverse proxy beschermen/{f=1} f&&/^# -+ /&&!/Reverse proxy beschermen/{exit} f{print}' agent/rzmon-agent.sh >"$work/mod.sh"
fn() { awk -v n="$1" '$0 ~ "^" n "\\(\\) \\{" {f=1} f{print} f&&/^\}/{exit}' agent/rzmon-agent.sh; }
{
    echo "HA_MODE=0; t_dir='$work/cache'; t_cache='$work/cache/tests.cache'; act_n=0"
    echo "PATH='$work/bin':\$PATH; PROXY_HTTPS_PORT=$port; PROXY_CPATH='$C'"
    echo 'lap() { :; }'
    fn json_escape; fn act_log
    echo ". '$work/mod.sh'"
    echo 'printf "%s" "$proxy_json"'
} >"$work/run.sh"
code() { curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 5 --resolve "$1.example.test:$port:127.0.0.1" -H "X-Forwarded-For: 1.2.3.4" ${3:+-A "$3"} "https://$1.example.test:$port$2"; }
lan='127.0.0.2/32'   # de test komt van 127.0.0.1: buiten "het LAN" (127.0.0.1 telt alleen via de vaste regel, die hieronder niet geldt)
policy() {
    printf '0\tproxy\ton\n0\tproxylan\t%s\n' "$lan"
    printf '0\tproxysite\tvaultwarden.*\tpaths\t/admin\toff\t1\n'
    printf '0\tproxysite\tportainer.*\tsite\t-\tstrict\t1\n'
    printf '0\tproxysite\tblog.*\toff\t-\tnormal\t1\n'
}
policy >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r1.json" 2>>"$work/err"
# 127.0.0.1 hoort bij het LAN (vaste regel), dus voor de test: die regel eruit en opnieuw laden
sed -i '/^    127\.0\.0\.1 1;$/d; /^    ::1 1;$/d' "$C/site-confs/rzsense-http.conf" && nginx -s reload -c "$work/nginx.conf" 2>>"$work/err"; sleep 1
c_adm=$(code vaultwarden /admin); c_adm2=$(code vaultwarden /ADMIN/x); c_vw=$(code vaultwarden /); c_port=$(code portainer /); c_blog=$(code blog /)
c_bot=$(code blog / 'sqlmap/1.7'); c_ok=$(code blog / 'Mozilla/5.0')
cp "$C/site-confs/rzsense-http.conf" "$work/http.conf.1"
bash "$work/run.sh" >"$work/r2.json" 2>>"$work/err"   # tweede ronde: niets nieuws
# Een beleid dat nginx afkeurt (twee keer dezelfde naam kan niet, maar de agent filtert dat): kapotte ssl.conf nabootsen
printf '0\tproxy\ton\n0\tproxylan\t%s\n0\tproxysite\tblog.*\toff\t-\tstrict\t0\n' "$lan" >"$work/cache/tests.cache"
cp "$C/ssl.conf" "$work/ssl.good"; printf 'limit_req_status 429;\n' >>"$C/ssl.conf"   # dubbele richtlijn → nginx -t faalt
bash "$work/run.sh" >"$work/r3.json" 2>>"$work/err"
c_after_fail=$(code blog /)
cp "$work/ssl.good" "$C/ssl.conf"
printf '0\tproxy\toff\n' >"$work/cache/tests.cache"
bash "$work/run.sh" >"$work/r4.json" 2>>"$work/err"
c_adm_off=$(code vaultwarden /admin)
bad=""
python3 - "$work" <<'PY' || bad+="rapport: $(cat "$work"/r*.json) | "
import json, sys
w = sys.argv[1]
r1, r2, r3, r4 = (json.load(open(f'{w}/r{i}.json')) for i in (1, 2, 3, 4))
assert r1['kind'] == 'swag' and r1['engine'] == 'podman' and r1['ctr'] == 'swag', r1
assert r1['state'] == 'ok' and r1['hook'] == 1 and len(r1['applied']) == 12, r1
names = {s['name']: s for s in r1['sites']}
assert set(names) == {'vaultwarden.*', 'portainer.*', 'blog.*'}, names
assert names['vaultwarden.*']['up'] == 'vaultwarden:80', names
assert r2['state'] == 'ok' and r2['applied'] == r1['applied'], r2
assert r3['state'] == 'error' and 'nginx -t' in r3['msg'], r3
assert r4['state'] == 'off' and r4['hook'] == 0, r4
PY
[ "$c_adm" = 403 ] || bad+="/admin van buiten niet 403 ($c_adm) | "
[ "$c_adm2" = 403 ] || bad+="/ADMIN/x (andere schrijfwijze) niet 403 ($c_adm2) | "
[ "$c_vw" = 200 ] || bad+="vaultwarden / niet 200 ($c_vw) | "
[ "$c_port" = 403 ] || bad+="portainer (hele site alleen LAN) niet 403 ($c_port) | "
[ "$c_blog" = 200 ] && [ "$c_ok" = 200 ] || bad+="blog niet 200 ($c_blog/$c_ok) | "
[ "$c_bot" = 403 ] || bad+="sqlmap niet geweerd ($c_bot) | "
[ "$c_after_fail" = 200 ] || bad+="na een afgekeurd beleid werkt de site niet meer ($c_after_fail) | "
[ "$c_adm_off" = 200 ] || bad+="na uitzetten is /admin nog dicht ($c_adm_off) | "
cmp -s "$C/ssl.conf" "$work/ssl.orig" || bad+="ssl.conf niet terug zoals eerst | "
[ -e "$C/site-confs/rzsense-http.conf" ] || [ -e "$C/rzsense/server.conf" ] && bad+="eigen bestanden niet weg na uitzetten | "
grep -q 'rzsense-hook' "$work/ssl.orig" && bad+="haak al in het origineel? | "
grep -q 'Reverse proxy: beleid doorgevoerd' "$work/cache/act.log" && grep -q 'Reverse proxy: doorvoeren mislukt' "$work/cache/act.log" \
    && grep -q 'Reverse proxy: bescherming uit' "$work/cache/act.log" || bad+="logboek mist regels | "
if [ -n "$bad" ]; then echo "✘ reverse proxy: ${bad% | } $(tail -n 5 "$work/err")"; exit 1; fi
echo "✔ reverse proxy: SWAG in Podman herkend, sites uitgelezen, adminpad en hele site alleen LAN (ook andere schrijfwijze), bots geweerd, tweede ronde niets nieuws, afgekeurd beleid teruggezet, uit = alles weg en ssl.conf als eerst, in het logboek"
