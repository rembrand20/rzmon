<?php
declare(strict_types=1);

/**
 * Payload-inspectie van webverzoeken (1.110.0). De agent stuurt uit de SWAG-log (nginx) de verzoeken die er verdacht
 * uitzien: methode, pad met query, status, bytes, User-Agent en verwijzer. Hier worden ze ontleed (meerdere keren
 * URL-gedecodeerd) en beoordeeld op aanvalstechnieken (in de stijl van de OWASP Core Rule Set) en op bekende exploits
 * met hun CVE. Wat raak is, wordt een incident met het bewijs erbij (ids_evidence); in de modus Blokkeren wordt het IP
 * geblokkeerd. Geheimen in de query (wachtwoorden, tokens, sleutels) worden gemaskeerd voordat er iets bewaard wordt.
 * Payloads gaan nooit naar buiten.
 */
final class Inspect
{
    /** [id, naam, regex] op de gedecodeerde tekst (pad + query, of de User-Agent) */
    public const TECHNIQUES = [
        ['sqli', 'SQL-injectie', '~(\bunion\b[\s(+/*]{1,20}(all[\s+]+)?select\b|\bselect\b.{1,80}\bfrom\b.{1,80}\b(where|limit)\b|[\'"]\s*(or|and)\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d|\b(sleep|pg_sleep)\s*\(\s*\d|benchmark\s*\(\s*\d|waitfor\s+delay\s+\'|information_schema\.|\bdrop\s+table\b|\bxp_cmdshell\b|\'\s*;\s*--|\'\s*--\s*$|\bextractvalue\s*\(|\bupdatexml\s*\()~i'],
        ['xss', 'Cross-site scripting (XSS)', '~(<\s*script\b|javascript\s*:|\bon(error|load|mouseover|focus|toggle|animationstart)\s*=|<\s*(svg|img|iframe|body|details)\b[^>]*\bon\w+\s*=|document\.cookie|\balert\s*\(|\bprompt\s*\(\s*\d)~i'],
        ['cmdi', 'Command injection', '~((;|\||`|\$\(|&&|\n)\s*(cat|id|whoami|uname|wget|curl|nc|ncat|bash|sh|ping|echo|chmod|rm|python[0-9.]*|perl|busybox|tftp)\b|\$\{IFS\}|/bin/(ba)?sh\b|\bcmd\.exe\b|powershell(\.exe)?\s)~i'],
        ['traversal', 'Path traversal / bestanden lezen', '~(\.\./|\.\.\\\\|/etc/(passwd|shadow|hosts|group)\b|/proc/self/(environ|cmdline)|\bwin\.ini\b|\bboot\.ini\b|c:\\\\windows)~i'],
        ['wrapper', 'PHP-wrapper / bestandsinclusie', '~\b(php://(filter|input|stdin)|data://|expect://|zip://|phar://|file:///)~i'],
        ['rfi', 'Remote file inclusion', '~=\s*(https?|ftp)://[^&\s]+\.(txt|php|sh|pl|py)(\?|&|$)~i'],
        ['log4shell', 'Log4Shell (JNDI)', '~\$\{\s*(jndi|\$\{\s*(lower|upper|env|sys|::-)|env:|::-j)~i'],
        ['ssrf', 'Server-side request forgery', '~(169\.254\.169\.254|metadata\.google\.internal|\b(url|uri|dest|redirect|target|link|src|next|feed)=(https?|gopher|dict)(:|%3a)//(127\.|localhost|0\.0\.0\.0|\[::1\]|10\.|192\.168\.))~i'],
        ['shellshock', 'Shellshock', '~\(\)\s*\{\s*:?\s*;\s*\}~'],
        ['ssti', 'Template-injectie', '~(\{\{\s*(7\s*\*\s*7|config|self\b|request\.|\'\'\.__class__)|\$\{\s*7\s*\*\s*7\s*\}|#\{\s*7\s*\*\s*7\s*\}|<%=\s*7\s*\*\s*7)~i'],
        ['php', 'PHP-code in het verzoek', '~(<\?php|\b(eval|assert|system|passthru|shell_exec|base64_decode|call_user_func)\s*\(|allow_url_include|auto_prepend_file)~i'],
        ['ognl', 'OGNL/Struts-injectie', '~(%\{|\$\{)\s*[(#@]*\s*(context|_memberAccess|ognl|@java\.lang)~i'],
    ];

    /** [regex op het pad, CVE (of ''), omschrijving] */
    public const EXPLOITS = [
        ['~/vendor/phpunit/.*/eval-stdin\.php~i', 'CVE-2017-9841', 'PHPUnit eval-stdin (code uitvoeren)'],
        ['~/_ignition/execute-solution~i', 'CVE-2021-3129', 'Laravel Ignition (code uitvoeren)'],
        ['~/wp-file-manager/lib/php/connector\.minimal\.php~i', 'CVE-2020-25213', 'WordPress File Manager: bestanden uploaden zonder inloggen'],
        ['~/(cgi-bin|icons)/(\.%2e|%2e%2e|\.\.)/~i', 'CVE-2021-41773', 'Apache 2.4.49/50 path traversal'],
        ['~/remote/fgt_lang\?lang=/\.\./~i', 'CVE-2018-13379', 'Fortinet SSL-VPN bestanden lezen'],
        ['~/dana-na/~i', 'CVE-2019-11510', 'Pulse Secure VPN'],
        ['~/mgmt/tm/util/bash~i', 'CVE-2022-1388', 'F5 BIG-IP iControl REST'],
        ['~/\+CSCOT\+/~i', 'CVE-2020-3452', 'Cisco ASA/FTD bestanden lezen'],
        ['~/autodiscover/autodiscover\.json.*powershell~i', 'CVE-2021-34473', 'Exchange ProxyShell'],
        ['~/wls-wsat/CoordinatorPortType~i', 'CVE-2017-10271', 'Oracle WebLogic'],
        ['~/console/(css|images)/%25?2e%25?2e~i', 'CVE-2020-14882', 'Oracle WebLogic console'],
        ['~/api/jsonws/invoke~i', 'CVE-2020-7961', 'Liferay Portal'],
        ['~think\\\\app/invokefunction|invokefunction&function=call_user_func~i', 'CVE-2018-20062', 'ThinkPHP (code uitvoeren)'],
        ['~/Telerik\.Web\.UI\.WebResource\.axd\?type=rau~i', 'CVE-2019-18935', 'Telerik UI'],
        ['~/HNAP1~i', 'CVE-2015-2051', 'D-Link HNAP (routers)'],
        ['~/GponForm/diag_Form~i', 'CVE-2018-10562', 'GPON-routers (botnet)'],
        ['~/boaform/admin/formLogin~i', '', 'Boa-webserver van routers (botnet)'],
        ['~/(shell|cgi-bin/[a-z_]+\.cgi)\?.*(cd\+?/tmp|wget\+|busybox)~i', '', 'Mirai/Mozi-botnet (routers en camera\'s)'],
        ['~/tmUnblock\.cgi~i', '', 'Linksys TheMoon-worm'],
        ['~/setup\.cgi\?.*cmd=~i', '', 'Netgear setup.cgi (botnet)'],
        ['~/actuator/(gateway|env|heapdump|jolokia)~i', 'CVE-2022-22947', 'Spring Boot Actuator'],
        ['~/geoserver/.*(exec|wfs\?.*valueReference)~i', 'CVE-2024-36401', 'GeoServer (code uitvoeren)'],
        ['~/(\.env|\.env\.(bak|old|local|prod|production))(\?|$)~i', '', 'Zoekt naar .env (wachtwoorden en sleutels)'],
        ['~/\.git/(config|HEAD)~i', '', 'Zoekt naar een .git-map (broncode)'],
        ['~/\.aws/credentials|/\.ssh/id_(rsa|ed25519)~i', '', 'Zoekt naar sleutels'],
        ['~/(wp-login\.php|xmlrpc\.php)~i', '', 'WordPress-login (wachtwoorden raden)'],
        ['~/(phpmyadmin|pma|myadmin)/~i', '', 'Zoekt naar phpMyAdmin'],
        ['~/manager/html~i', '', 'Tomcat Manager (wachtwoorden raden)'],
        ['~/containers/json|/v1\.\d+/containers/~i', '', 'Docker-API van buiten'],
        ['~/(server-status|server-info)(\?|$)~i', '', 'Apache server-status (informatie)'],
        ['~[?&]XDEBUG_SESSION_START=~i', '', 'Xdebug op afstand'],
        ['~/(solr/admin/(cores|info)|solr/.*/config)~i', '', 'Apache Solr'],
        ['~/(owa/auth/(logon\.aspx|x\.js)|ecp/)~i', '', 'Exchange/Outlook Web Access'],
        ['~/cgi-bin/luci/;stok=~i', '', 'OpenWrt/LuCI-routers'],
        ['~/(jenkins/)?script(Text)?(\?|$)~i', '', 'Jenkins script-console'],
    ];

    /** Bekende scanners in de User-Agent */
    private const SCANNERS = '~(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|dirbuster|gobuster|ffuf|feroxbuster|l9explore|censysinspect|acunetix|netsparker|openvas|hydra|wfuzz|jaeles|xray)~i';

    /** Geheimen in de query maskeren: password=…, token=…, key=…, Authorization */
    public static function redact(string $s): string
    {
        return (string) preg_replace('~((?:^|[?&;])(?:pass(?:word|wd)?|pwd|token|access_token|api_?key|apikey|secret|key|auth|session|sid|jwt|code)=)[^&;#\s]*~i', '$1***', $s);
    }

    /** Meerdere keren decoderen (dubbel gecodeerde aanvallen) */
    private static function decode(string $s): string
    {
        for ($i = 0; $i < 3; $i++) {
            $d = html_entity_decode(rawurldecode(str_replace('+', ' ', $s)), ENT_QUOTES | ENT_HTML5);
            if ($d === $s) {
                break;
            }
            $s = $d;
        }
        return $s;
    }

    /**
     * Eén verzoek beoordelen. @return array{tech: list<array>, cve: ?array, scanner: string}
     * tech: [id, naam, gevonden stuk]; cve: [cve, omschrijving] of null
     */
    public static function analyze(string $method, string $uri, string $ua): array
    {
        $dec = self::decode($uri);
        $tech = [];
        foreach (self::TECHNIQUES as [$id, $name, $re]) {
            foreach ([$dec, self::decode($ua)] as $hay) {
                if ($hay !== '' && preg_match($re, $hay, $m)) {
                    $tech[] = [$id, $name, mb_substr($m[0], 0, 80)];
                    break;
                }
            }
        }
        $cve = null;
        foreach (self::EXPLOITS as [$re, $id, $label]) {
            if (preg_match($re, $uri) || preg_match($re, $dec)) {
                $cve = [$id, $label];
                break;
            }
        }
        $scanner = preg_match(self::SCANNERS, $ua, $m) ? strtolower($m[1]) : '';
        if (!preg_match('/^(GET|POST|HEAD|PUT|DELETE|OPTIONS|PATCH|PROPFIND|-)$/', $method)) {
            $tech[] = ['method', 'Vreemde HTTP-methode', mb_substr($method, 0, 20)];
        }
        return ['tech' => $tech, 'cve' => $cve, 'scanner' => $scanner, 'dec' => $dec !== $uri ? mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', '?', mb_convert_encoding($dec, 'UTF-8', 'UTF-8')), 0, 512) : ''];
    }

    /**
     * Verzoeken van de agent: [ip, tijd (unix), methode, pad+query, status, bytes, user-agent, verwijzer], hooguit 100.
     * Per IP één incident per dag (opgeteld), met de verzoeken als bewijs.
     */
    /**
     * 1.112.0: een aanval op een lek (CVE) dat deze server volgens de kwetsbaarheden-scan echt heeft: critical, want
     * dan kan het lukken. Een geaccepteerd risico (vuln_ignore) telt niet.
     */
    private static function cveHere(array $host, string $ip, array $reqs): void
    {
        if (setting('ti_cve', '1') !== '1') {
            return;
        }
        foreach (array_unique(array_filter(array_map(static fn($q) => $q['cve'][0] ?? '', $reqs))) as $cve) {
            $f = q('SELECT package, version, fixed_version FROM vuln_findings f WHERE host_id = ? AND cve = ?
                    AND NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.host_id IS NULL OR i.host_id = f.host_id)) LIMIT 1', [$host['id'], $cve])->fetch();
            if (!$f) {
                continue;
            }
            $ok = array_filter($reqs, static fn($q) => ($q['cve'][0] ?? '') === $cve && $q['status'] >= 200 && $q['status'] < 400);
            Ids::raise($host, 2, 'web_cve', "$cve|$ip", t('Aanval op een lek dat deze server heeft: {cve} vanaf {ip}', ['cve' => $cve, 'ip' => $ip]),
                t('{pkg} {ver} heeft dit lek{fix}. Werk het bij (tabblad Kwetsbaarheden) en kijk of de aanval lukte.', ['pkg' => $f['package'], 'ver' => $f['version'],
                    'fix' => $f['fixed_version'] ? ' ' . t('(opgelost in {v})', ['v' => $f['fixed_version']]) : '']) . ($ok ? ' · ' . t('{n} kreeg een gewoon antwoord (2xx/3xx): kijk of het lukte', ['n' => count($ok)]) : ''), $ip);
        }
    }

    public static function requests(array $host, array $list): void
    {
        if (!Ids::enabled() || setting('edr_web', '1') !== '1') {
            return;
        }
        $byIp = [];
        foreach (array_slice($list, 0, 100) as $r) {
            if (!is_array($r) || count($r) < 6) {
                continue;
            }
            $ip = (string) $r[0];
            if (@inet_pton($ip) === false || Ids::internalIp($ip) || Ids::protectedIp($ip)) {
                if (Ids::isRzmon($ip) && self::analyze((string) ($r[2] ?? ''), (string) ($r[3] ?? ''), (string) ($r[6] ?? ''))['tech']) {
                    Edr::allowed($host, 'w', "web|$ip", ['ip' => $ip, 'method' => (string) ($r[2] ?? ''), 'uri' => self::redact(mb_substr((string) ($r[3] ?? ''), 0, 300))]);
                }
                continue;
            }
            // nginx schrijft " en \ en vreemde tekens als \xHH; terugzetten, dan pas beoordelen
            $clean = static fn($v, int $n) => mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', '?',
                mb_convert_encoding((string) preg_replace_callback('/\\\\x([0-9A-Fa-f]{2})/', static fn($m) => chr(hexdec($m[1])), (string) $v), 'UTF-8', 'UTF-8')), 0, $n);
            // Tijd: "28/Oct/2026:10:00:00 +0100" uit de log (of al een unix-tijd)
            $at = is_numeric($r[1] ?? null) ? (int) $r[1] : ((DateTime::createFromFormat('d/M/Y:H:i:s O', (string) ($r[1] ?? '')) ?: new DateTime())->getTimestamp());
            $method = $clean($r[2] ?? '', 20);
            $uri = $clean($r[3] ?? '', 512);
            $ua = $clean($r[6] ?? '', 200);
            $a = self::analyze($method, $uri, $ua);
            if (!$a['tech'] && !$a['cve'] && $a['scanner'] === '') {
                continue;
            }
            $byIp[$ip][] = ['at' => $at, 'method' => $method, 'uri' => self::redact($uri), 'status' => (int) ($r[4] ?? 0),
                'bytes' => (int) ($r[5] ?? 0), 'ua' => $ua, 'ref' => self::redact($clean($r[7] ?? '', 200)), 'tech' => $a['tech'], 'cve' => $a['cve'], 'scanner' => $a['scanner'], 'dec' => self::redact($a['dec'])];
        }
        foreach ($byIp as $ip => $reqs) {
            $techs = array_values(array_unique(array_merge(...array_map(static fn($q) => array_column($q['tech'], 1), $reqs))));
            $cves = array_values(array_unique(array_filter(array_map(static fn($q) => $q['cve'] ? trim($q['cve'][0] . ' ' . $q['cve'][1]) : null, $reqs))));
            $what = $cves ? $cves[0] : ($techs ? $techs[0] : t('scanner {ua}', ['ua' => $reqs[0]['scanner']]));
            $ok = array_filter($reqs, static fn($q) => $q['status'] >= 200 && $q['status'] < 400);
            $detail = t('{n} verdachte verzoek(en)', ['n' => count($reqs)]) . ($techs ? ' · ' . implode(', ', array_map('t', $techs)) : '')
                . ($cves ? ' · ' . implode(', ', $cves) : '') . ($ok ? ' · ' . t('{n} kreeg een gewoon antwoord (2xx/3xx): kijk of het lukte', ['n' => count($ok)]) : '');
            $blocked = setting('edr_block', '1') === '1' ? Ids::autoBlock($host, $ip, t('Webaanval: {what}', ['what' => $what])) : null;
            $id = Ids::raise($host, 1, 'web_payload', "swag|$ip", t('Webaanval vanaf {ip}: {what}', ['ip' => $ip, 'what' => t($what)]),
                $detail . ($blocked ? " · ⛔ $blocked" : ''), $ip);
            foreach (array_slice($reqs, -10) as $q) {
                Edr::evidence($id, 'web', $q, $q['cve'][0] ?? ($q['tech'][0][0] ?? 'scanner'));
            }
            self::cveHere($host, $ip, $reqs);
        }
    }
}
