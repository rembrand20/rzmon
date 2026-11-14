<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): hulpfuncties van de API.
defined('RZ_API') || exit;

function out($data, int $code = 200): never
{
    // Agents van buiten: een fout als gewone 404 (niet herkenbaar, 1.86.0); de reden staat in RzSense (agentlog, ⚠️)
    if ($code >= 400 && !empty($GLOBALS['agent_req'])) {
        Stealth::denyOutside();
    }
    http_response_code($code);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    // Versleuteld verzoek van een agent: het antwoord gaat ook versleuteld terug (zelfde sleutel, gebonden aan het verzoek)
    if ($code < 400 && isset($GLOBALS['agent_seal'])) {
        [$key, $nonce] = $GLOBALS['agent_seal'];
        $json = AgentCrypto::seal((string) $json, $key, $nonce);
    }
    Perf::bytes(strlen((string) $json));
    echo $json;
    exit;
}

/** Inhoud van het verzoek: bij een agent de uitgepakte tekst (zie agent_body), anders zoals hij binnenkwam */
function raw_input(): string
{
    return $GLOBALS['agent_plain'] ?? (string) file_get_contents('php://input');
}

function input(): array
{
    static $in = null;
    // Een los ongeldig UTF-8-teken (bv. een afgeknipte é) maakt niet het hele verzoek onleesbaar (1.99.1)
    return $in ??= (json_decode(raw_input(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE) ?: []);
}

/**
 * Onleesbaar agentrapport (1.99.2): per onderdeel op het hoogste niveau ("disks", "proc_list" …) apart lezen en alleen
 * de kapotte onderdelen weglaten, zodat één fout (bv. een vreemd teken in een opdrachtregel) niet het hele rapport
 * tegenhoudt. @return array{0: array, 1: list<array{key: string, error: string, near: string}>}
 */
function agent_json_salvage(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '{') {
        return [[], []];
    }
    $out = $bad = [];
    $len = strlen($raw);
    $inStr = $esc = false;
    $depth = 0;
    $start = 1;
    $flush = static function (int $end) use ($raw, &$start, &$out, &$bad): void {
        $m = trim(substr($raw, $start, $end - $start));
        $start = $end + 1;
        if ($m === '') {
            return;
        }
        $d = json_decode('{' . $m . '}', true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($d)) {
            $out += $d;
            return;
        }
        $err = json_last_error_msg();
        preg_match('/^"([A-Za-z0-9_]{1,40})"/', $m, $k);
        // Het stukje waar het misgaat: begin van het onderdeel, of rond een stuurteken of een losse backslash
        // stuurteken, een losse backslash (oneven aantal) of een aanhalingsteken midden in een tekst
        $pos = 0;
        foreach (['/[\x00-\x1f]/', '/(?<!\\\\)(?:\\\\\\\\)*(\\\\)(?![\\\\\/"bfnrt]|u[0-9a-fA-F]{4})/', '/[^\[{,:\s\\\\]"[^,\]}:\s]/'] as $re) {
            if (preg_match($re, $m, $mm, PREG_OFFSET_CAPTURE)) {
                $pos = $mm[count($mm) - 1][1];
                break;
            }
        }
        $near = mb_substr(mb_convert_encoding(substr($m, max(0, $pos - 60), 140), 'UTF-8', 'UTF-8'), 0, 140);
        $bad[] = ['key' => $k[1] ?? '?', 'error' => $err, 'near' => preg_replace('/[\x00-\x1f]/', '␣', $near)];
    };
    for ($i = 1; $i < $len; $i++) {
        $c = $raw[$i];
        if ($inStr) {
            if ($esc) {
                $esc = false;
            } elseif ($c === '\\') {
                $esc = true;
            } elseif ($c === '"') {
                $inStr = false;
            }
            continue;
        }
        if ($c === '"') {
            $inStr = true;
        } elseif ($c === '{' || $c === '[') {
            $depth++;
        } elseif (($c === '}' || $c === ']') && $depth > 0) {
            $depth--;
        } elseif (($c === ',' && $depth === 0) || ($c === '}' && $depth === 0)) {
            $flush($i);
            if ($c === '}') {
                break;
            }
        }
    }
    if ($start < $len) {
        $flush($len);
    }
    return [$out, $bad];
}

function num($v): ?float
{
    return ($v === '' || $v === null) ? null : (float) $v;
}

/**
 * Zoekt de server bij een agent-verzoek; bij een fout: logboek, ⚠️-melding en 403 met de reden.
 * Agent 1.50.0+ met een eigen sleutel stuurt geen token, alleen het kenmerk van zijn sleutel (X-Agent-Key bij een
 * versleuteld bericht, X-Agent-Auth bij een GET); oudere agents het token (X-Agent-Token). Heeft een server een eigen
 * sleutel in gebruik, dan is een token alleen niet meer genoeg (tenzij versleuteling voor die server uit staat).
 * Daarna: komt de agent van een bekend IP-adres (Instellingen → Agents → Standaardinstellingen)?
 */
function agent_host(): array
{
    $token = trim((string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''));
    $kid = (string) ($_SERVER['HTTP_X_AGENT_KEY'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $kid)) {
        $kid = preg_match('/^([0-9a-f]{16}):/', (string) ($_SERVER['HTTP_X_AGENT_AUTH'] ?? ''), $m) ? $m[1] : '';
    }
    $ip = client_ip();
    $cols = 'id, name, mode, agent_version, info_agent, agent_seen, agent_beats, agent_ip, agent_tick, enc, agent_token,
             agent_key, agent_key_id, agent_key_at, agent_key_seen, agent_key_old, agent_key_old_until, agent_ips, pin_mode, ip_lock, agent_pin_sent, agent_https, agent_fb_at, watchdog, edr_on, edr_learn_from';
    if ($kid !== '') {
        $host = q("SELECT $cols FROM hosts WHERE agent_key_id = ? OR (agent_key_old_id = ? AND agent_key_old_until > ?) LIMIT 1", [$kid, $kid, now()])->fetch();
        $reason = $host ? null : t('onbekende sleutel (vernieuwd of opnieuw gekoppeld?): koppel de agent opnieuw met een koppelcode');
    } else {
        $host = $token !== '' ? q("SELECT $cols FROM hosts WHERE agent_token = ?", [$token])->fetch() : false;
        $reason = match (true) {
            $token === '' => t('geen token meegestuurd'),
            !$host => t('onbekend token (verkeerd gekopieerd, of in RzSense vernieuwd?)'),
            (int) $host['agent_key_seen'] === 1 && AgentCrypto::hostMode($host) !== 'off'
                => t('alleen een token, maar deze server heeft een eigen sleutel: koppel de agent opnieuw met een koppelcode'),
            // 1.100.0: alleen agents met een eigen sleutel (koppelen met een koppelcode blijft altijd mogelijk)
            setting('agent_ownkey_only', '0') === '1' => t('alleen agents met een eigen sleutel worden toegelaten: koppel deze agent met een koppelcode'),
            default => null,
        };
    }
    if ($reason === null && $host['mode'] !== 'agent') {
        $reason = t("server '{name}' staat niet op type 'Remote met agent'", ['name' => $host['name']]);
    }
    // Alleen via https (1.72.0); via het reserve-adres (X-Agent-Fallback) mag http wel, met een ⚠️ (zie het rapport)
    if ($reason === null && AgentCrypto::httpsOnly($host) && !Https::isHttps() && empty($_SERVER['HTTP_X_AGENT_FALLBACK'])) {
        $reason = t('via http, maar deze server mag alleen via https verbinden (Instellingen → Agents → Standaardinstellingen)');
    }
    if ($reason === null && !AgentCrypto::ipAllowed($host, $ip)) {
        $reason = t('nieuw IP-adres {ip} (bekend: {known}); toestaan kan bij Instellingen → Agents → Standaardinstellingen',
            ['ip' => $ip, 'known' => trim((string) $host['agent_ips'])]);
    }
    if ($reason !== null) {
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'] ?? null, mb_substr($ip, 0, 45), mb_substr($reason, 0, 255)]);
        Security::unauthorized('agent', $reason, $host ? [t('server') => $host['name']] : []);
        out(['error' => t('Geweigerd') . ": $reason", 'reason' => $reason], 403);
    }
    // Welke sleutels gelden: de eigen van deze server, of (token) de algemene
    $GLOBALS['agent_own'] = $kid !== '';
    $GLOBALS['agent_keys'] = $kid !== '' ? AgentCrypto::hostKeys($host) : AgentCrypto::keys();
    return $host;
}

/**
 * GET-verzoek van een agent met een eigen sleutel: de handtekening (X-Agent-Auth) controleren.
 * @return array{0:string,1:string}|null [sleutel, nonce] voor het versleutelde antwoord; null = agent met een token
 */
function agent_get_auth(array $host, string $action): ?array
{
    if (empty($GLOBALS['agent_own'])) {
        return null;
    }
    try {
        return AgentCrypto::verifyGet((string) ($_SERVER['HTTP_X_AGENT_AUTH'] ?? ''), $GLOBALS['agent_keys'], $action);
    } catch (RuntimeException $e) {
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'], mb_substr(client_ip(), 0, 45), mb_substr($e->getMessage(), 0, 255)]);
        Security::unauthorized('agent', $e->getMessage(), [t('server') => $host['name']]);
        out(['error' => t('Geweigerd') . ': ' . $e->getMessage(), 'enc_error' => true], 403);
    }
}

/**
 * Bericht van een agent: versleuteld (controleren en uitpakken) of gewoon. Versleuteld = het antwoord gaat ook
 * versleuteld terug. Moet deze server versleutelen en doet hij dat niet, of klopt de handtekening niet: 403 en ⚠️.
 * Een agent met een eigen sleutel moet altijd versleutelen (zijn sleutel is ook zijn handtekening).
 */
function agent_body(array $host): bool
{
    $raw = (string) file_get_contents('php://input');
    $refuse = static function (string $reason, array $extra = []) use ($host): never {
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'], mb_substr(client_ip(), 0, 45), mb_substr($reason, 0, 255)]);
        Security::unauthorized('agent', $reason, [t('server') => $host['name']]);
        out(['error' => t('Geweigerd') . ": $reason"] + $extra, 403);
    };
    if (AgentCrypto::isEnvelope($raw)) {
        try {
            [$plain, $key, $nonce] = AgentCrypto::open($raw, $GLOBALS['agent_keys'] ?? null);
        } catch (RuntimeException $e) {
            $refuse($e->getMessage(), ['enc_error' => true]);
        }
        $GLOBALS['agent_plain'] = agent_unzip($plain, $refuse);
        $GLOBALS['agent_seal'] = [$key, $nonce];
        // De agent gebruikt zijn eigen sleutel: vanaf nu is een token alleen niet meer genoeg
        if ($key === ($host['agent_key'] ?? null) && (int) $host['agent_key_seen'] !== 1) {
            q('UPDATE hosts SET agent_key_seen = 1, agent_token = NULL WHERE id = ?', [$host['id']]);   // 1.100.0: token is dan niet meer nodig
        }
        return true;
    }
    if (!empty($GLOBALS['agent_own'])) {
        $refuse(t('onversleuteld bericht met een sleutelkenmerk'), ['enc_error' => true]);
    }
    if (AgentCrypto::hostMode($host) === 'required') {
        $refuse(t('onversleuteld bericht, maar versleuteling is verplicht voor deze server'), ['enc_required' => true]);
    }
    $GLOBALS['agent_plain'] = agent_unzip($raw, $refuse);
    return false;
}

/**
 * 1.147.0: gecomprimeerd rapport (agent 1.73.0+, gzip, herkend aan de eerste twee bytes) uitpakken. Hooguit 64 MB
 * uitgepakt (geen "zip-bom"); kapot of te groot: geweigerd. De agent comprimeert pas als RzSense "gz":1 terugstuurt.
 */
function agent_unzip(string $s, callable $refuse): string
{
    $out = AgentCrypto::unzip($s);
    if ($out === null) {
        $refuse(t('gecomprimeerd rapport is kapot of te groot'));
    }
    return (string) $out;
}

/**
 * Adres van RzSense (1.72.0): wat de agent gebruikt, hoe hij verbond (http, https of https via de proxy) en of het via zijn
 * reserve-adres ging (⚠️ bij het begin, ✅ als het weer via het gewone adres gaat). De uitkomst van een overstap
 * verwerken; staat er een overstap klaar, dan @return array{url:string,fallback:string} de opdracht (alleen versleuteld).
 */
function agent_url_report(array $host, array $in, ?string $agentVersion, bool $encrypted): ?array
{
    // 1.131.1: "via de proxy" alleen als het verzoek echt is doorgestuurd (met X-Forwarded-…). Draait de proxy op dezelfde
    // server als de agent (bv. SWAG), dan komt die agent rechtstreeks binnen vanaf het IP van de proxy: dat is gewoon https
    $proxied = Https::viaProxy() && (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) || !empty($_SERVER['HTTP_FORWARDED']));
    $conn = Https::isHttps() ? ($proxied ? 'proxy' : 'https') : 'http';
    $fb = !empty($_SERVER['HTTP_X_AGENT_FALLBACK']);
    $url = AgentCrypto::validUrl((string) ($in['url'] ?? '')) ? (string) $in['url'] : null;
    $urlFb = AgentCrypto::validUrl((string) ($in['url_fallback'] ?? '')) ? (string) $in['url_fallback'] : null;
    q('UPDATE hosts SET agent_conn = ?, agent_url = COALESCE(?, agent_url), agent_url_fb = ? WHERE id = ?',
        [$conn, $url, $url !== null ? $urlFb : null, $host['id']]);
    // Via het reserve-adres: alleen bijhouden; de melding gaat pas na 5 minuten, voor alle agents samen (AgentCrypto::fallbackCron)
    if ($fb && empty($host['agent_fb_at'])) {
        q('UPDATE hosts SET agent_fb_at = ? WHERE id = ?', [now(), $host['id']]);
    } elseif (!$fb && !empty($host['agent_fb_at'])) {
        q('UPDATE hosts SET agent_fb_at = NULL WHERE id = ?', [$host['id']]);
    }
    // Hoe de agent (1.53.0) zijn adressen controleert: "-" (gewoon) of de vastgezette vingerafdruk(ken)
    $specOk = static fn($s) => is_string($s) && ($s === '-' || preg_match('~^sha256//[A-Za-z0-9+/=]{40,64}(;sha256//[A-Za-z0-9+/=]{40,64})?$~', $s));
    if (array_key_exists('url_pin', $in)) {
        q('UPDATE hosts SET agent_url_pin = ?, agent_url_fb_pin = ? WHERE id = ?',
            [$specOk($in['url_pin']) ? $in['url_pin'] : null, $specOk($in['url_fb_pin'] ?? null) ? $in['url_fb_pin'] : null, $host['id']]);
    }
    $h = q('SELECT agent_url_want, agent_url_want_fb, agent_url_status FROM hosts WHERE id = ?', [$host['id']])->fetch();
    $result = trim((string) ($in['url_result'] ?? ''));
    if ($result !== '') {
        $ok = str_starts_with($result, 'ok');
        // Agent 1.51.1 zet erachter met welk IP hij verbond ("[ip 1.2.3.4]"). Een publiek IP terwijl de agent uit je eigen
        // netwerk komt = via de router naar buiten en weer naar binnen: de naam wijst op die server niet naar de proxy.
        $tail = '';
        if (preg_match('/\s*\[ip ([0-9a-f.:]{2,45})\]\s*$/i', $result, $m)) {
            $result = substr($result, 0, -strlen($m[0]));
            $known = preg_split('/\s+/', trim((string) ($host['agent_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if (filter_var($m[1], FILTER_VALIDATE_IP) && !ip_in_list($m[1], PRIVATE_NETWORKS)
                && $known && !array_filter($known, fn($k) => !ip_in_list($k, PRIVATE_NETWORKS))) {
                $tail = ' — ⚠️ ' . t('via internet ({ip}): wijst de naam hier naar je proxy? (DNS-rewrite; AdGuard: filteren aan voor deze client)', ['ip' => $m[1]]);
            }
        }
        $status = $ok ? '✔ ' . t('Overgestapt naar {url}', ['url' => $url ?? '?']) : '✘ ' . preg_replace('/^error:?\s*/', '', $result);
        $status = mb_substr($status, 0, 254 - mb_strlen($tail)) . $tail;
        q('UPDATE hosts SET agent_url_status = ?, agent_url_at = ?, agent_url_want = NULL, agent_url_want_fb = NULL WHERE id = ?', [$status, now(), $host['id']]);
        AgentCommands::audit('agent', 'Adres van RzSense', "{$host['name']}: $status");
        return null;
    }
    // Mislukte overstap, maar de agent rapporteert nu toch gewoon via dat adres (bv. met de hand ingesteld): ✘ weg
    if (!$fb && $url !== null && !$h['agent_url_want'] && preg_match('/^✘ (\S+) /u', (string) $h['agent_url_status'], $m) && rtrim($m[1], '/') === $url) {
        q('UPDATE hosts SET agent_url_status = ?, agent_url_at = ? WHERE id = ?', ['✔ ' . t('Gebruikt nu {url}', ['url' => $url]), now(), $host['id']]);
    }
    $same = $h['agent_url_want'] && $h['agent_url_want'] === $url && (string) $h['agent_url_want_fb'] === (string) ($urlFb ?? '');
    if (!$h['agent_url_want'] || $same || !$encrypted || !$agentVersion || version_compare($agentVersion, AgentCrypto::URL_MIN, '<')) {
        if ($same) {
            q('UPDATE hosts SET agent_url_status = ?, agent_url_at = ?, agent_url_want = NULL, agent_url_want_fb = NULL WHERE id = ?',
                ['✔ ' . t('Gebruikt al {url}', ['url' => $url]), now(), $host['id']]);
        }
        return null;
    }
    $set = ['url' => $h['agent_url_want'], 'fallback' => (string) $h['agent_url_want_fb']];
    if (version_compare($agentVersion, AgentCrypto::PINURL_MIN, '>=')) {
        // "" = geen aanwijzing (de agent doet het zoals vroeger); bij een overstap test hij meteen met de vingerafdruk
        $set['pin'] = (string) AgentCrypto::urlSpec($set['url'], false);
        $set['fb_pin'] = $set['fallback'] !== '' ? (string) AgentCrypto::urlSpec($set['fallback'], false) : '';
    }
    return $set;
}

/**
 * Velden voor het (versleutelde) antwoord. Agent 1.50.0+: zijn eigen sleutel (de eerste keer, opnieuw als hij nog een
 * oude gebruikt, of een nieuwe als hij aan vernieuwing toe is) en de vingerafdruk van het certificaat van RzSense.
 * Agent 1.49.x: de algemene sleutel. Een sleutel gaat nooit leesbaar over de lijn: zonder versleuteling niets
 * (dan koppel je de agent met een koppelcode).
 */
function agent_enc_fields(array $host, ?string $agentVersion): array
{
    if (!$agentVersion || version_compare($agentVersion, AgentCrypto::AGENT_MIN, '<')) {
        return [];   // oudere agent: kan nog niet versleutelen
    }
    if (AgentCrypto::hostMode($host) === 'off') {
        return ['enc' => 'off'];
    }
    $used = $GLOBALS['agent_seal'][0] ?? null;
    if ($used === null) {
        return [];
    }
    if (version_compare($agentVersion, AgentCrypto::OWNKEY_MIN, '>=')) {
        $out = [];
        $cur = (string) ($host['agent_key'] ?? '');
        if ($cur === '') {
            $out['agent_key'] = AgentCrypto::newHostKey((int) $host['id'], false);   // overstap van de algemene sleutel
        } elseif ($used !== $cur) {
            $out['agent_key'] = $cur;                                                  // gebruikt nog de vorige of de algemene
        } elseif (AgentCrypto::rotateDue($host)) {
            $out['agent_key'] = AgentCrypto::newHostKey((int) $host['id'], true);     // vernieuwen; de huidige nog 24 uur geldig
        }
        // Zag de agent een ander certificaat? Dan opnieuw bepalen, niet uit de cache
        $pin = AgentCrypto::pin(!empty($_SERVER['HTTP_X_AGENT_PIN_FAIL']), true, $host);
        if ($pin !== null) {
            $out['pin'] = $pin;
            // Onthouden welke vingerafdruk deze agent nu kent (voor 🔐 Certificaat: per agent ✔ of ⏳)
            if (($host['agent_pin_sent'] ?? null) !== $pin) {
                q('UPDATE hosts SET agent_pin_sent = ?, agent_pin_sent_at = ? WHERE id = ?', [$pin, now(), $host['id']]);
            }
        }
        return $out;
    }
    $key = AgentCrypto::key();
    return $key === '' || $used === $key ? [] : ['enc_key' => $key];
}

/** @param bool $secure de lijst gaat versleuteld terug (dan mogen de gegevens voor de waakhond erin) */
function agent_tests_text(int $hostId, bool $secure = false): string
{
    $out = [];
    // Hoe vaak de agent rapporteert: 0 <tab> tick <tab> seconden
    $hr = q('SELECT agent_tick, realtime FROM hosts WHERE id = ?', [$hostId])->fetch() ?: [];
    $out[] = implode("\t", [0, 'tick', agent_tick((int) ($hr['agent_tick'] ?? 0) ?: null)]);
    // Realtime-watcher aan voor deze server: 0 <tab> realtime <tab> 1
    if (!empty($hr['realtime'])) {
        $out[] = implode("\t", [0, 'realtime', 1]);
    }
    // Interval per check voor zware onderdelen (diepere controle, extra tests, logs): id <tab> ival <tab> seconden
    foreach (q("SELECT id, interval_sec FROM checks WHERE host_id = ? AND type IN ('service', 'log') AND enabled = 1", [$hostId]) as $c) {
        $out[] = implode("\t", [$c['id'], 'ival', max(30, (int) $c['interval_sec'])]);
    }
    // Services met een service-check: id <tab> svc <tab> servicenaam
    foreach (q("SELECT id, target FROM checks WHERE host_id = ? AND type = 'service' AND enabled = 1", [$hostId]) as $c) {
        if (preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', $c['target'])) {
            $out[] = implode("\t", [$c['id'], 'svc', $c['target']]);
        }
    }
    // Logbestanden doorzoeken: id <tab> log <tab> bron <tab> zoekpatroon
    foreach (q("SELECT id, target, options FROM checks WHERE host_id = ? AND type = 'log' AND enabled = 1", [$hostId]) as $c) {
        $o = json_decode((string) $c['options'], true) ?: [];
        if (($o['pattern'] ?? '') !== '') {
            $out[] = implode("\t", [$c['id'], 'log', $c['target'], str_replace(["\t", "\n", "\r"], ' ', $o['pattern'])]);
        }
    }
    foreach (q("SELECT id, target, options FROM checks WHERE host_id = ? AND type = 'service' AND enabled = 1 AND options IS NOT NULL", [$hostId]) as $c) {
        $o = json_decode((string) $c['options'], true) ?: [];
        if (($o['ports'] ?? '') !== '' && ($o['deep'] ?? 1)) {
            // Zelf opgegeven poorten/sockets van de service (target = servicenaam)
            $out[] = implode("\t", [$c['id'], 'ports', $c['target'], $o['ports']]);
        }
        if (in_array($o['test_type'] ?? '', ['url', 'port'], true) && ($o['test_target'] ?? '') !== '') {
            $out[] = implode("\t", [$c['id'], $o['test_type'], str_replace(["\t", "\n"], ' ', $o['test_target']), str_replace(["\t", "\n"], ' ', (string) ($o['test_expect'] ?? ''))]);
        }
    }
    // Certificaten (1.98.0): wat de agent moet uitlezen (bestanden, en host:poort gezien vanaf de server zelf),
    // extra mappen om in te zoeken, en "opnieuw zoeken" (een teller die verandert)
    foreach (q("SELECT target, options FROM checks WHERE host_id = ? AND type = 'ssl' AND enabled = 1", [$hostId]) as $c) {
        $src = Checks::agentCertSource($c);
        if ($src !== null) {
            $out[] = implode("\t", [0, 'cert', $src]);
        }
    }
    $dirs = implode(' ', array_filter(preg_split('/\s+/', (string) setting('cert_dirs', '')) ?: [], static fn($d) => preg_match('#^/[A-Za-z0-9._@+/-]{1,200}$#', $d) && !str_contains($d, '..')));
    if ($dirs !== '') {
        $out[] = implode("\t", [0, 'certdirs', $dirs]);
    }
    $scan = (int) (q('SELECT certscan_req FROM hosts WHERE id = ?', [$hostId])->fetchColumn() ?: 0);
    if ($scan) {
        $out[] = implode("\t", [0, 'certscan', $scan]);
    }
    // Proces-checks: id <tab> proc <tab> name|pattern <tab> naam of patroon (base64) <tab> gebruiker
    foreach (q("SELECT id, target, options FROM checks WHERE host_id = ? AND type = 'process' AND enabled = 1", [$hostId]) as $c) {
        $o = json_decode((string) $c['options'], true) ?: [];
        $out[] = implode("\t", [$c['id'], 'proc', ($o['mode'] ?? '') === 'pattern' ? 'pattern' : 'name', base64_encode((string) $c['target']), (string) ($o['user'] ?? '')]);
    }
    // Logins, sudo en su: voor de pagina Meldingen en/of het IDS (0 <tab> auth <tab> 1)
    if (AuthEvents::enabled() || Ids::enabled()) {
        $out[] = implode("\t", [0, 'auth', 1]);
    }
    // IDS: aan + modus, en de IP's die de agent moet blokkeren
    array_push($out, ...Ids::agentLines($hostId));
    // 1.110.0: endpoint-sensoren (aan per server)
    array_push($out, ...Edr::agentLines(q('SELECT id, mode, edr_on FROM hosts WHERE id = ?', [$hostId])->fetch() ?: []));
    // Commando-checks: id <tab> cmd <tab> commando (base64) <tab> time-out <tab> nobody|root <tab> interval
    $mode = AgentCommands::modeFor(q('SELECT commands FROM hosts WHERE id = ?', [$hostId])->fetch() ?: []);
    if ($mode !== 'off') {
        foreach (q("SELECT id, command, interval_sec FROM checks WHERE host_id = ? AND type = 'command' AND enabled = 1", [$hostId]) as $c) {
            $cfg = json_decode((string) $c['command'], true);
            if (is_array($cfg) && ($cfg['cmd'] ?? '') !== '') {
                $out[] = implode("\t", [$c['id'], 'cmd', base64_encode($cfg['cmd']), (int) ($cfg['timeout'] ?? 10), $mode, (int) $c['interval_sec']]);
            }
        }
    }
    // 1.107.0: zelf herstellen (services en containers) en de waakhond
    array_push($out, ...Heal::testLines($hostId, $secure));
    array_push($out, ...Ai::diagLines($hostId));   // 1.171.0: diagnose voor AI-advies (agent 1.76.0)
    array_push($out, ...Paths::agentLines($hostId));   // 1.177.0: pad-checks en doorvoertests (agent 1.78.0)
    array_push($out, ...Db::agentLines($hostId, $secure));   // 1.200.0: databasechecks (agent 1.81.0); wachtwoord alleen versleuteld
    array_push($out, ...AgentTest::agentLines($hostId));   // 1.178.0: 🧪 Test uit de Agent Manager (agent 1.79.0)
    array_push($out, ...LogStore::agentLines($hostId));   // 1.210.0: logs verzamelen (agent 1.83.0)
    array_push($out, ...Ransom::agentLines($hostId));   // 1.228.0: ransomware herkennen (agent 1.90.0)
    array_push($out, ...AppControl::agentLines($hostId), ...Ioc::agentLines($hostId));   // 1.229.0: programma's en jagen (agent 1.91.0)
    array_push($out, ...Patch::agentLines($hostId));   // 1.230.0: updates (agent 1.92.0)
    array_push($out, ...Sudo::agentLines($hostId));   // 1.231.0: tijdelijk sudo (agent 1.93.0)
    array_push($out, ...ProxyGuard::agentLines($hostId));   // 1.254.0: reverse proxy beschermen (agent 1.97.0)
    array_push($out, ...Waf::agentLines($hostId));   // 1.255.0: WAF op de reverse proxy (agent 1.98.0)
    array_push($out, ...Av::agentLines($hostId));   // 1.257.0: ClamAV op de reverse proxy (agent 1.99.0)
    return $out ? implode("\n", $out) . "\n" : '';
}

/** Kenmerk van die lijst: verandert het, dan haalt de agent hem meteen opnieuw op. */
function agent_tests_rev(int $hostId, bool $secure = false): string
{
    // Zelfde inhoud als het cachebestand van de agent (die zet er één regeleinde achter)
    return substr(sha1(rtrim(agent_tests_text($hostId, $secure), "\n") . "\n"), 0, 12);
}

/**
 * Bijwerken op afstand: verwerkt wat de agent over updates meldt en bepaalt of hij nu moet bijwerken.
 * De agent haalt dan zelf de nieuwe versie bij RzSense op en controleert die met de SHA-256 hieronder.
 * @return array|null opdracht voor de agent, of null
 */
function agent_update_instruction(int $hostId, ?string $version, array $in): ?array
{
    $via = (string) ($in['update']['via'] ?? '');
    if ($via === 'test') {
        return null;   // verbindingstest (rzmon-agent-setup --test): niets veranderen
    }
    $via = in_array($via, ['systemd', 'direct', 'off', 'ha', 'none'], true) ? $via : null;   // null: agent van vóór 1.19.0
    $h = q('SELECT id, name, info_agent, agent_update_req, agent_update_sent, agent_update_status FROM hosts WHERE id = ?', [$hostId])->fetch();
    $status = null;
    $req = $h['agent_update_req'];
    $sent = $h['agent_update_sent'];

    // Uitkomst van een eerdere update ("ok 1.18.0 1.19.0" of "error <reden>")
    $result = trim((string) ($in['update_result'] ?? ''));
    if ($result !== '') {
        $status = preg_match('/^ok (\S*) (\S+)/', $result, $m)
            ? '✔ ' . date('d-m H:i') . ' ' . ($m[1] !== '' && $m[1] !== $m[2] ? t('bijgewerkt van {from} naar {to}', ['from' => $m[1], 'to' => $m[2]]) : t('bijgewerkt naar {to}', ['to' => $m[2]]))
            : '✘ ' . date('d-m H:i') . ' ' . t('bijwerken mislukt') . ': ' . AgentText::tr(mb_substr(preg_replace('/^error\s*/', '', $result), 0, 200));
        ActionLog::add($hostId, 'agent', 'update', str_starts_with($result, 'ok') ? t('Agent bijgewerkt') : t('Agent bijwerken mislukt'), ltrim((string) $status, '✔✘ '), str_starts_with($result, 'ok'), '', null, 'agent');   // 1.226.0
        if (str_starts_with($result, 'ok')) {
            $sent = null;   // gelukt: een volgende RzSense-versie mag meteen weer automatisch (de info gaat bij de versiewissel)
        } else {
            $why = mb_substr(preg_replace('/^error\s*/', '', $result), 0, 200);
            Monitor::infoHost($h, fn() => '✘ ' . t('Agent bijwerken mislukt') . ': ' . AgentText::tr($why), 'agent_update_fail');
        }
        $req = null;
    } elseif ($req !== null && $sent !== null && strtotime($sent) < time() - 600) {
        $status = '✘ ' . t('Geen antwoord van de update na 10 minuten. Kijk op de server: {cmd}', ['cmd' => 'journalctl -u "rzmon-agent-update-*"']);
        ActionLog::add($hostId, 'rzsense', 'update', t('Agent bijwerken: geen antwoord na 10 minuten'), '', false);
        Monitor::infoHost($h, fn() => '✘ ' . t('Agent bijwerken: geen antwoord na 10 minuten'), 'agent_update_fail');
        $req = null;
    }

    $outdated = $version === null || version_compare($version, AGENT_LATEST, '<');
    // 1.130.1: staat er nog een mislukte of hangende update (✘/⏳), maar draait de agent inmiddels de nieuwste versie
    // (bv. met de hand bijgewerkt), dan is die melding achterhaald
    if ($status === null && !$outdated && $req === null && preg_match('/^(✘|⏳)/u', (string) $h['agent_update_status'])) {
        $status = '✔ ' . date('d-m H:i') . ' ' . t('bijgewerkt naar {to}', ['to' => $version]);
        $sent = null;
    }
    // Automatisch alleen via een versleutelde verbinding (dan kan niemand onderweg de opdracht of het controlegetal
    // vervalsen); onversleuteld alleen als je zelf op de knop drukt
    $wanted = ($req !== null && $sent === null) || ($req === null && $outdated && setting('agent_auto_update', '0') === '1'
        && isset($GLOBALS['agent_seal']) && ($sent === null || strtotime($sent) < time() - 6 * 3600));   // automatisch: hooguit elke 6 uur een poging
    $instruction = null;
    if ($wanted && in_array($via, ['systemd', 'direct'], true)) {
        $instruction = [
            'version' => AGENT_LATEST,
            'agent_sha256' => hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh'),
            'setup_sha256' => Stealth::installSha(),
        ];
        $sent = now();
        $req ??= now();
        $status = '⏳ ' . t('Bijwerken naar {v} gestart…', ['v' => AGENT_LATEST]);
        ActionLog::add($hostId, $h['agent_update_req'] !== null ? 'user' : 'rzsense', 'update', t('Agent bijwerken naar {v} gestuurd', ['v' => AGENT_LATEST]), $version ? t('nu {v}', ['v' => $version]) : '', null, '', null, 'RzSense');
    } elseif ($req !== null && $sent === null) {
        // Gevraagd, maar deze agent kan het niet: uitleggen waarom
        $status = '✘ ' . match ($via) {
            'off' => t('Bijwerken op afstand staat uit op deze server (REMOTE_UPDATE=0 in /etc/default/rzmon-agent)'),
            'ha' => t('Home Assistant-add-on: bijwerken via de add-on winkel'),
            'none' => t('De agent kan zichzelf hier niet bijwerken (geen root of rzmon-agent-setup ontbreekt): sudo rzmon-agent-setup --update'),
            default => t('Deze agent kent bijwerken op afstand nog niet. Werk hem één keer bij op de server: sudo rzmon-agent-setup --update'),
        };
        $req = null;
    }
    q('UPDATE hosts SET agent_update_req = ?, agent_update_sent = ?, agent_update_status = COALESCE(?, agent_update_status), agent_update_via = ? WHERE id = ?',
        [$req, $sent, $status, $via, $hostId]);
    return $instruction;
}

/**
 * 1.166.0: de meest actieve aanvallers van de laatste 24 uur: pogingen (SSH) plus treffers in incidenten, per IP-adres,
 * met het land, of het een bekende aanvaller is en de server die het vaakst werd aangevallen. Nooit je eigen netwerk.
 * @param array<int, array<string, mixed>> $hosts
 * @return list<array<string, mixed>>
 */
function ids_top_attackers(array $hosts, int $limit = 5): array
{
    $names = [];
    foreach ($hosts as $h) {
        $names[(int) $h['id']] = (string) $h['name'];
    }
    $per = [];
    $rows = array_merge(
        q('SELECT ip, host_id, SUM(n) AS n FROM ids_attempts WHERE at >= NOW() - INTERVAL 1 DAY GROUP BY ip, host_id')->fetchAll(),
        q("SELECT ip, host_id, SUM(hits) AS n FROM ids_incidents WHERE ip IS NOT NULL AND ip <> '' AND last_seen >= NOW() - INTERVAL 1 DAY GROUP BY ip, host_id")->fetchAll()
    );
    foreach ($rows as $r) {
        $ip = (string) $r['ip'];
        if ($ip === '' || Ids::internalIp($ip)) {
            continue;
        }
        $per[$ip]['n'] = ($per[$ip]['n'] ?? 0) + (int) $r['n'];
        $per[$ip]['h'][(int) $r['host_id']] = ($per[$ip]['h'][(int) $r['host_id']] ?? 0) + (int) $r['n'];
    }
    uasort($per, static fn($a, $b) => $b['n'] <=> $a['n']);
    $out = [];
    foreach (array_slice($per, 0, $limit, true) as $ip => $p) {
        arsort($p['h']);
        $hid = (int) array_key_first($p['h']);
        $out[] = ['ip' => (string) $ip, 'n' => $p['n'], 'cc' => IpIntel::country((string) $ip), 'bad' => IpIntel::bad((string) $ip),
            'host' => $names[$hid] ?? ('#' . $hid), 'hosts' => count($p['h'])];
    }
    return $out;
}

/** 1.162.0: weergave-keuzes per account (eerst alleen in de browser): grootte per soort scherm, sortering en periode */
function clean_ui_prefs(array $in): array
{
    $size = '/^(small|large)$/';
    $sort = '/^[a-z_]{0,20}:-?1$/';
    $per = '/^[a-z0-9]{1,10}$/';
    $re = ['dens_blox_d' => $size, 'dens_blox_m' => $size, 'dens_l1_d' => $size, 'dens_l1_m' => $size, 'dash_sort' => '/^[a-z_]{1,20}$/',
        'alert_sort' => $sort, 'acc_sort' => $sort, 'hm_period' => $per, 'cc_period' => $per, 'an_period' => $per,
        'av_group' => '/^(kind|own)$/', 'av_period' => '/^(1|7|30|90)$/', 'av_target' => '/^(99|99\.5|99\.9|99\.95|99\.99|98|95)$/'];   // 1.169.0
    $out = [];
    foreach ($re as $k => $r) {
        if (isset($in[$k]) && is_string($in[$k]) && preg_match($r, $in[$k])) {
            $out[$k] = $in[$k];
        }
    }
    return $out;
}

/**
 * 1.158.0: indeling van de pagina's in de lay-out CC: per pagina de volgorde, verborgen kaarten en de breedte (3, 4, 6, 9 of 12 van 12).
 * Alleen korte, bekende tekens; hooguit 40 pagina's en 60 kaarten per pagina.
 */
function clean_page_layouts(array $in): array
{
    $out = [];
    $id = static fn($v) => preg_replace('/[^a-z0-9_]/', '', strtolower((string) $v));
    foreach (array_slice($in, 0, 40, true) as $page => $l) {
        $page = $id($page);
        if ($page === '' || !is_array($l)) {
            continue;
        }
        $keys = static fn($a) => array_values(array_slice(array_unique(array_filter(array_map($id, (array) $a))), 0, 60));
        $w = [];
        foreach (array_slice((array) ($l['w'] ?? []), 0, 60, true) as $k => $v) {
            if (($k = $id($k)) !== '' && in_array((int) $v, [3, 4, 6, 9, 12], true)) {
                $w[$k] = (int) $v;
            }
        }
        $out[$page] = ['order' => $keys($l['order'] ?? []), 'hide' => $keys($l['hide'] ?? []), 'w' => (object) $w];
    }
    return $out;
}

/**
 * Tellers op de tabs Dashboard en Meldingen: wat er nieuw is sinds de gebruiker die tab voor het laatst zag.
 * $seen = 'dashboard' of 'alerts': die tab staat nu open, dus alles daar is gezien.
 */
function tab_badges(array $user, array $hosts, string $seen): ?array
{
    if (empty($user['id'])) {
        return null;   // lokaal netwerk zonder account: niets om te onthouden
    }
    if (in_array($seen, ['dashboard', 'alerts'], true)) {
        q("UPDATE users SET seen_$seen = NOW() WHERE id = ?", [$user['id']]);
    }
    q('UPDATE users SET seen_dashboard = COALESCE(seen_dashboard, NOW()), seen_alerts = COALESCE(seen_alerts, NOW()) WHERE id = ?', [$user['id']]);
    $u = q('SELECT seen_dashboard, seen_alerts FROM users WHERE id = ?', [$user['id']])->fetch();
    if (!$u) {
        return null;
    }
    // Dashboard: checks die sinds het laatste bezoek een probleem werden en dat nog zijn (geen info-check, onderhoud of bevestigd)
    $dash = 0;
    $worst = 0;
    $rank = [0 => 0, 3 => 1, 1 => 2, 2 => 3];
    foreach ($hosts as $h) {
        foreach ($h['checks'] as $c) {
            $s = (int) $c['status'];
            if ((int) $h['enabled'] && (int) $c['enabled'] && !(int) $c['info_only'] && $s !== 0 && empty($c['maintenance'])
                && !$c['acked_at'] && $c['status_since'] && $c['status_since'] > $u['seen_dashboard']) {
                $dash++;
                $worst = $rank[$s] > $rank[$worst] ? $s : $worst;
            }
        }
    }
    // Meldingen: warning, critical, unknown en herstel (geen info en geen bevestigingen)
    $alerts = (int) q("SELECT COUNT(*) FROM alerts WHERE created_at > ? AND kind IN ('notify', 'ids', 'sec')", [$u['seen_alerts']])->fetchColumn();
    return ['dashboard' => $dash, 'dashboard_status' => $worst, 'alerts' => $alerts];
}
