//go:build linux

package main

// Gemaakt door tools/gen-proxy-tpl.py uit agent/src/69-proxy.sh: de nginx-sjablonen van "Reverse proxy beschermen",
// letterlijk dezelfde tekst als de bash-agent schrijft. Niet met de hand wijzigen; ${px_…} wordt bij het schrijven ingevuld.

const proxyTplVersion = "3"

const proxyTplHTTP = `# RzSense: reverse proxy beschermen. Beheerd door de RzSense-agent; wijzigingen hier worden overschreven.
geo $rzs_lan {
    default 0;
    127.0.0.1 1;
    ::1 1;
${px_geo}}
map $host $rzs_site {
    hostnames;
    default "";
${px_hosts}}
map "$rzs_site:$uri" $rzs_adm {
    default 0;
${px_open}${px_adm}}
map "$rzs_site:$uri" $rzs_blk {
    default 0;
${px_blk}}
map "$rzs_blk:$rzs_lan" $rzs_deny_blk {
    default 0;
    "1:0" 1;
}
map "$rzs_site:$cookie_pxt" $rzs_tag {
    default 0;
${px_tag}}
map "$rzs_site:$uri:$arg_nood:$arg_door" $rzs_nood {
    default 0;
${px_nood}}
map $cookie_pxt $rzs_trust {
    default 0;
${px_trust}}
map $rzs_site $rzs_dev_on {
    default 0;
${px_dev}}
map "$rzs_dev_on:$rzs_trust" $rzs_devok {
    default 0;
    "1:1" 1;
}
map "$rzs_lan:$rzs_devok" $rzs_free {
    default 1;
    "0:0" 0;
}
map "$rzs_adm:$rzs_lan:$rzs_tag:$rzs_nood:$rzs_devok" $rzs_deny_adm {
    default 0;
    "1:0:0:0:0" 1;
}
map $rzs_site $rzs_is_rz {
    default 0;
${px_rzs}}
map "$rzs_is_rz:$rzs_deny_adm" $rzs_req {
    default "";
    "1:1" '<p><a class=b href=/access.php>Toegang aanvragen <span>/ Request access</span></a></p>';
}
map $http_user_agent $rzs_badbot {
    default 0;
    "~*(sqlmap|nikto|nuclei|zgrab|masscan|nmap scripting|dirbuster|gobuster|feroxbuster|wpscan|acunetix|netsparker|havij|zmeu|jorgee|fimap|w3af|openvas|nessus)" 1;
}
map $rzs_site $rzs_bots_on {
    default 0;
${px_bots}}
map "$rzs_badbot:$rzs_bots_on:$rzs_lan" $rzs_deny_bot {
    default 0;
    "1:1:0" 1;
}
map $rzs_site $rzs_rate {
    default "";
${px_rate}}
map $uri $rzs_static {
    default 0;
    "~*\.(js|mjs|css|map|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif|ico)$" 1;
    "~^/(frontend_latest|frontend_es5|static|hacsfiles)/" 1;
}
map $uri $rzs_login {
    default 0;
    "~*(^|/)(auth|login|signin|sign_in|sso|oauth2?|identity|token|wp-login\.php)(/|$|\.)" 1;
}
map "$rzs_rate:$rzs_free:$rzs_static:$rzs_login" $rzs_key_n {
    default "";
    "~^normal:0:0:" $binary_remote_addr;
    "app:0:0:1" $binary_remote_addr;
}
map "$rzs_rate:$rzs_free:$rzs_static:$rzs_login" $rzs_key_s {
    default "";
    "~^strict:0:0:" $binary_remote_addr;
}
map "$rzs_rate:$rzs_free:$rzs_static:$rzs_login" $rzs_key_a {
    default "";
    "app:0:0:0" $binary_remote_addr;
}
limit_req_zone $rzs_key_n zone=rzs_normal:10m rate=20r/s;
limit_req_zone $rzs_key_s zone=rzs_strict:10m rate=5r/s;
limit_req_zone $rzs_key_a zone=rzs_app:10m rate=50r/s;
map "$rzs_deny_adm$rzs_deny_blk$rzs_deny_bot" $rzs_why {
    default "Je hebt geen toegang tot deze pagina.<br><span>You do not have access to this page.</span>";
    "~^1" "Deze pagina is alleen bereikbaar vanaf het eigen netwerk of een toegestaan apparaat.<br><span>This page can only be reached from the home network or an allowed device.</span>";
    "~^01" "Je adres is tijdelijk geblokkeerd na verdachte verzoeken.<br><span>Your address is temporarily blocked after suspicious requests.</span>";
    "~^001" "Automatische scanners zijn hier niet welkom.<br><span>Automated scanners are not welcome here.</span>";
}
`

const proxyTplSrv = `# RzSense: reverse proxy beschermen (per site). Beheerd door de RzSense-agent.
if ($rzs_deny_adm) { return 403; }
if ($rzs_deny_blk) { return 403; }
if ($rzs_deny_bot) { return 403; }
error_page 403 @rzs403;
location @rzs403 {
    default_type text/html;
    add_header Cache-Control "no-store" always;
    add_header X-Content-Type-Options "nosniff" always;
    return 403 '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>403 Forbidden</title><style>body{font:16px/1.5 system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fa;color:#1f2633}main{max-width:32rem;margin:1rem;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08)}h1{font-size:1.3rem;margin:0 0 .6rem}span{color:#5b6475}p.k{font-size:.85rem;color:#5b6475;margin-top:1.4rem}a.b{display:inline-block;margin-top:.4rem;padding:.65rem 1.1rem;border-radius:10px;background:#2f6fed;color:#fff;font-weight:600;text-decoration:none}a.b span{color:#dbe6ff}@media(prefers-color-scheme:dark){body{background:#12151c;color:#e6e9ef}main{background:#1b2029}span,p.k{color:#9aa3b2}}</style></head><body><main><h1>&#128274; Geen toegang <span>/ Access denied</span></h1><p>$rzs_why</p>$rzs_req<p class="k">403 Forbidden &middot; $host &middot; $remote_addr &middot; $time_iso8601</p></main></body></html>';
}
limit_req zone=rzs_normal burst=40 nodelay;
limit_req zone=rzs_strict burst=20 nodelay;
limit_req zone=rzs_app burst=300 nodelay;
limit_req_status 429;
`
