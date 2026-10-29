<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): never
{
    // Agents van buiten: een fout als gewone 404 (niet herkenbaar, 1.86.0); de reden staat in RzMon (agentlog, ⚠️)
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

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$GLOBALS['agent_req'] = $action === 'ping' || str_starts_with((string) $action, 'agent');
if (is_configured()) {
    Perf::request($GLOBALS['agent_req'] ? 'g' : 'a', (string) $action);   // snelheidsmeter (1.119.0)
}

// Taal: vóór het inloggen die van dit apparaat of de browser; een agent (geen gebruiker) krijgt de taal van
// meldingen, want wat hij oplevert (checkberichten, IDS) gaat naar iedereen. Na het inloggen: die van de gebruiker.
I18n::init(null);
if (str_starts_with($action, 'agent_') && is_configured()) {
    try {
        I18n::set(I18n::notifyLang());
    } catch (Throwable) {
        // database nog niet klaar: dan de standaard
    }
}

// ---------- Agent en installatiescript (openbaar: bevatten niets geheims) ----------
// Zo kan een server de agent rechtstreeks van RzMon installeren, ook als de
// GitHub-repository privé is.
if ($action === 'ping') {
    // Voor de verbindingstest van de agent: "draait hier RzMon?". Van buiten (niet herkenbaar) alleen voor bekende agents.
    if (!Stealth::pingFull()) {
        Stealth::notFound();
    }
    out(['ok' => true, 'app' => 'RzMon', 'agent_version' => AGENT_LATEST, 'version' => RZMON_VERSION, 'configured' => is_configured(), 'time' => date('c')]);
}
if ($action === 'agent_script' || $action === 'agent_install') {
    // Van buiten (niet herkenbaar): alleen voor bekende agents of met de downloadsleutel uit de installatiecommando's
    if (!Stealth::scriptAllowed()) {
        Stealth::notFound();
    }
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Cache-Control: no-store');
    // Het installatiescript krijgt de downloadsleutel mee, zodat het daarna ook de agent zelf kan ophalen
    echo $action === 'agent_install' && is_configured() ? Stealth::installScript() : file_get_contents(SM_ROOT . '/agent/' . ($action === 'agent_script' ? 'rzmon-agent.sh' : 'install-agent.sh'));
    exit;
}

if (!is_configured()) {
    out(['error' => t('RzMon is nog niet ingesteld'), 'setup' => true], 503);
}

// ---------- Agent endpoint (token-auth, geen sessie) ----------
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
            !$host => t('onbekend token (verkeerd gekopieerd, of in RzMon vernieuwd?)'),
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
        $GLOBALS['agent_plain'] = $plain;
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
    $GLOBALS['agent_plain'] = $raw;
    return false;
}

/**
 * Adres van RzMon (1.72.0): wat de agent gebruikt, hoe hij verbond (http, https of https via de proxy) en of het via zijn
 * reserve-adres ging (⚠️ bij het begin, ✅ als het weer via het gewone adres gaat). De uitkomst van een overstap
 * verwerken; staat er een overstap klaar, dan @return array{url:string,fallback:string} de opdracht (alleen versleuteld).
 */
function agent_url_report(array $host, array $in, ?string $agentVersion, bool $encrypted): ?array
{
    $conn = Https::isHttps() ? (Https::viaProxy() ? 'proxy' : 'https') : 'http';
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
        AgentCommands::audit('agent', 'Adres van RzMon', "{$host['name']}: $status");
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
 * oude gebruikt, of een nieuwe als hij aan vernieuwing toe is) en de vingerafdruk van het certificaat van RzMon.
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

// Extra tests (URL of poort) die bij service-checks van deze server zijn ingesteld, voor de agent.
// Platte tekst, één test per regel: id <tab> type <tab> doel <tab> verwachte tekst.
/**
 * Wat de agent van RzMon moet weten, één regel per item (tab-gescheiden):
 * welke services hij bewaakt, logzoekopdrachten, eigen poorten van services en extra tests.
 */
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
    return $out ? implode("\n", $out) . "\n" : '';
}

/** Kenmerk van die lijst: verandert het, dan haalt de agent hem meteen opnieuw op. */
function agent_tests_rev(int $hostId, bool $secure = false): string
{
    // Zelfde inhoud als het cachebestand van de agent (die zet er één regeleinde achter)
    return substr(sha1(rtrim(agent_tests_text($hostId, $secure), "\n") . "\n"), 0, 12);
}

// Koppelen met een koppelcode (agent 1.50.0+): het bericht is versleuteld met een sleutel uit de koppelcode (X-Agent-Pair:
// het kenmerk daarvan). Het antwoord, ook versleuteld, bevat de eigen sleutel van de agent. De code werkt één keer.
if ($action === 'agent_pair') {
    $pid = (string) ($_SERVER['HTTP_X_AGENT_PAIR'] ?? '');
    $host = preg_match('/^[0-9a-f]{16}$/', $pid) ? AgentCrypto::pairHost($pid) : null;
    $ip = client_ip();
    $deny = static function (string $reason, ?array $h = null) use ($ip): never {
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$h['id'] ?? null, mb_substr($ip, 0, 45), mb_substr($reason, 0, 255)]);
        Security::unauthorized('agent', $reason, $h ? [t('server') => $h['name']] : []);
        out(['error' => t('Geweigerd') . ": $reason", 'reason' => $reason], 403);
    };
    if (!$host) {
        $deny(t('ongeldige of verlopen koppelcode'));
    }
    if ($host['mode'] !== 'agent') {
        $deny(t("server '{name}' staat niet op type 'Remote met agent'", ['name' => $host['name']]), $host);
    }
    try {
        [$plain, $pkey, $nonce] = AgentCrypto::open((string) file_get_contents('php://input'), [$host['pair_key']]);
    } catch (RuntimeException $e) {
        $deny($e->getMessage(), $host);
    }
    $in = json_decode($plain, true) ?: [];
    $GLOBALS['agent_seal'] = [$pkey, $nonce];
    // Nieuwe sleutel (de vorige vervalt meteen), het IP-adres van nu wordt het bekende, de code is op
    $key = AgentCrypto::newHostKey((int) $host['id'], false);
    // 1.100.0: met de eigen sleutel is het token niet meer nodig (agent_token = NULL)
    q('UPDATE hosts SET agent_key_seen = 1, agent_token = NULL, pair_key = NULL, pair_until = NULL, agent_ips = ?, agent_ip_new = NULL, agent_ip_new_at = NULL WHERE id = ?',
        [mb_substr($ip, 0, 45), $host['id']]);
    $ver = preg_match('/^\d+\.\d+\.\d+$/', (string) ($in['agent_version'] ?? '')) ? $in['agent_version'] : '?';
    AgentCommands::audit('agent', 'Agent gekoppeld', "{$host['name']} vanaf $ip (agent $ver)");
    Monitor::sysInfo('agent_connect', (int) $host['id'], (string) $host['name'], 'ℹ️ ' . t('Agent gekoppeld: {name}', ['name' => $host['name']]),
        fn() => t('Agent op {name} is gekoppeld met een koppelcode en heeft een eigen sleutel', ['name' => $host['name']]) . " · IP $ip · agent $ver", $ip);
    out(['ok' => true, 'host' => $host['name'], 'agent_key' => $key, 'pin' => AgentCrypto::pin(false, true, $host) ?? '',
        'agent_sha256' => hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh')]);
}

if ($action === 'agent_tests') {
    $host = agent_host();
    // Eigen sleutel: ondertekend verzoek. Anders (token) een agent met de algemene sleutel (header X-Agent-Enc: kenmerk:nonce).
    // Met een sleutel gaat de lijst versleuteld terug.
    if ($auth = agent_get_auth($host, 'agent_tests')) {
        [$key, $m[2]] = $auth;
    } else {
        $hdr = (string) ($_SERVER['HTTP_X_AGENT_ENC'] ?? '');
        $key = preg_match('/^([0-9a-f]{16}):([0-9a-f]{32})$/', $hdr, $m) ? AgentCrypto::keyById($m[1]) : null;
    }
    if ($key === null && AgentCrypto::hostMode($host) === 'required') {
        Security::unauthorized('agent', t('onversleuteld bericht, maar versleuteling is verplicht voor deze server'), [t('server') => $host['name']]);
        out(['error' => t('Geweigerd')], 403);
    }
    header('Content-Type: text/plain; charset=utf-8');
    $text = agent_tests_text((int) $host['id'], $key !== null);
    echo $key !== null ? AgentCrypto::seal($text, $key, $m[2]) : $text;
    exit;
}

// Alleen het token controleren, zonder gegevens te versturen (verbindingstest)
if ($action === 'agent_check') {
    $host = agent_host();
    if ($auth = agent_get_auth($host, 'agent_check')) {
        $GLOBALS['agent_seal'] = $auth;   // antwoord versleuteld: zo weet de agent dat hij echt met RzMon praat
    }
    out(['ok' => true, 'host' => $host['name'], 'your_ip' => client_ip(), 'own_key' => (bool) $auth]);
}

// Voortgang van "pakketten bijwerken" (agent 1.48.0+): elke paar seconden tijdens apt/dnf, en aan het eind de uitkomst
if ($action === 'agent_pkg_progress') {
    $host = agent_host();
    agent_body($host);
    Vulns::upgradeProgress($host, input());
    out(['ok' => true]);
}

if ($action === 'agent') {
    $host = agent_host();
    $encrypted = agent_body($host);
    $in = input();
    if (!$in) {
        // Agent 1.20.0–1.20.3 zette servicenamen als systemd-fsck@dev-disk-by\x2duuid… ongewijzigd in de JSON.
        // Zo'n losse backslash herstellen, dan blijft die agent werken (en kan hij met de knop bijgewerkt worden).
        $raw = raw_input();
        $fixed = preg_replace_callback('/\\\\(.?)/s', static fn($m) => $m[1] !== '' && str_contains('"\\\\/bfnrtu', $m[1]) ? $m[0] : '\\' . $m[0], $raw);
        $in = json_decode((string) $fixed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE) ?: [];
    }
    $salvaged = [];
    if (!$in) {
        // Nog steeds onleesbaar: per onderdeel lezen en alleen wat kapot is weglaten (1.99.2)
        $jsonErr = json_last_error_msg();
        [$in, $salvaged] = agent_json_salvage(raw_input());
        if (!isset($in['agent_version'])) {
            $in = [];   // te weinig over om als rapport te gebruiken
        }
    }
    if (!$in) {
        // Onleesbaar rapport: niets wegschrijven (anders lijken alle gegevens verdwenen) en de reden loggen
        $reason = 'onleesbaar rapport (JSON: ' . ($jsonErr ?? json_last_error_msg()) . ($salvaged ? ', bij ' . implode(', ', array_column($salvaged, 'key')) : '') . '); werk de agent bij';
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'], mb_substr(client_ip(), 0, 45), $reason]);
        out(['error' => "Geweigerd: $reason"], 400);
    }
    Perf::part('rapport:ontvangen');   // 1.129.0: aanmelden, ontsleutelen en inlezen
    $now = now();
    $st = db()->prepare('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)');
    foreach (($in['disks'] ?? []) as $d) {
        if (!empty($d['mount'])) {
            $st->execute([$host['id'], mb_substr('disk:' . $d['mount'], 0, 190),
                json_encode(['total' => (float) ($d['total'] ?? 0), 'avail' => (float) ($d['avail'] ?? 0)]), $now]);
        }
    }
    // Opstarttijd (agent 1.46.0+): voor de check Herstart
    if (isset($in['boot']) && is_numeric($in['boot']) && (int) $in['boot'] > 0) {
        $st->execute([$host['id'], 'boot', (string) (int) $in['boot'], $now]);
    }
    if (isset($in['cpu']['pct'])) {
        $st->execute([$host['id'], 'cpu', json_encode([
            'pct' => (float) $in['cpu']['pct'],
            'load' => array_map('floatval', array_slice((array) ($in['cpu']['load'] ?? []), 0, 3)),
            'cores' => (int) ($in['cpu']['cores'] ?? 0),
        ]), $now]);
    }
    if (!empty($in['mem']['total'])) {
        $st->execute([$host['id'], 'mem', json_encode(array_map('floatval', array_intersect_key(
            (array) $in['mem'], array_flip(['total', 'avail', 'swap_total', 'swap_free'])))), $now]);
    }
    if (isset($in['ports']) && is_array($in['ports'])) {
        $ports = [];
        foreach (array_slice($in['ports'], 0, 500) as $lp) {
            $port = (int) ($lp['port'] ?? 0);
            if ($port > 0 && $port < 65536) {
                $ports[] = ['port' => $port, 'addr' => mb_substr((string) ($lp['addr'] ?? ''), 0, 64),
                    'proc' => mb_substr((string) ($lp['proc'] ?? ''), 0, 64)];
            }
        }
        $st->execute([$host['id'], 'ports', json_encode($ports), $now]);
    }
    // Certificaten (bestanden of host:poort) die de agent heeft uitgelezen
    foreach (array_slice((array) ($in['certs'] ?? []), 0, 100) as $c) {
        $src = trim((string) ($c['source'] ?? ''));
        if ($src === '' || mb_strlen($src) > 180) {
            continue;
        }
        $st->execute([$host['id'], 'cert:' . $src, json_encode([
            'cn' => mb_substr((string) ($c['cn'] ?? ''), 0, 200),
            'issuer' => mb_substr((string) ($c['issuer'] ?? ''), 0, 200),
            'not_after' => isset($c['not_after']) && is_numeric($c['not_after']) ? (int) $c['not_after'] : null,
            'verify' => isset($c['verify']) ? mb_substr((string) $c['verify'], 0, 120) : null,
            'error' => mb_substr((string) ($c['error'] ?? ''), 0, 200),
            // De hele keten (agent 1.59.0): hoeveel certificaten, en welk het eerst verloopt
            'chain_n' => (int) ($c['chain_n'] ?? 0),
            'chain_min' => isset($c['chain_min']) && is_numeric($c['chain_min']) ? (int) $c['chain_min'] : null,
            'chain_cn' => mb_substr((string) ($c['chain_cn'] ?? ''), 0, 200),
        ], JSON_UNESCAPED_UNICODE), $now]);
    }
    // Gevonden certificaatbestanden (agent 1.59.0), om bij een SSL-check uit te kiezen
    if (isset($in['cert_found']) && is_array($in['cert_found'])) {
        $found = [];
        foreach (array_slice($in['cert_found'], 0, 300) as $c) {
            $path = (string) ($c['path'] ?? '');
            if (is_array($c) && preg_match('#^/[^\s]{1,250}$#', $path)) {
                $found[] = ['path' => $path, 'cn' => mb_substr((string) ($c['cn'] ?? ''), 0, 120), 'issuer' => mb_substr((string) ($c['issuer'] ?? ''), 0, 120),
                    'not_after' => is_numeric($c['not_after'] ?? null) ? (int) $c['not_after'] : null, 'n' => (int) ($c['n'] ?? 1),
                    'chain_min' => is_numeric($c['chain_min'] ?? null) ? (int) $c['chain_min'] : null];
            }
        }
        $st->execute([$host['id'], 'certfound', json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
    }
    // Processen (agent 1.59.0): per proces-check de meting, en een lijst van wat er draait (om uit te kiezen)
    foreach (array_slice((array) ($in['procs'] ?? []), 0, 200, true) as $pid => $pv) {
        if (ctype_digit((string) $pid) && is_array($pv)) {
            $st->execute([$host['id'], 'proc:' . (int) $pid, json_encode(['n' => (int) ($pv['n'] ?? 0), 'pids' => mb_substr((string) ($pv['pids'] ?? ''), 0, 200),
                'cpu' => (float) ($pv['cpu'] ?? 0), 'rss' => (int) ($pv['rss'] ?? 0), 'start' => is_numeric($pv['start'] ?? null) ? (int) $pv['start'] : null]), $now]);
        }
    }
    if (isset($in['proc_list']) && is_array($in['proc_list'])) {
        $pl = [];
        foreach (array_slice($in['proc_list'], 0, 300) as $e) {
            if (is_array($e) && preg_match('/^[^\x00-\x1f]{1,64}$/u', (string) ($e[0] ?? ''))) {
                $pl[] = [(string) $e[0], (int) ($e[1] ?? 1), mb_substr((string) ($e[2] ?? ''), 0, 32), mb_substr((string) ($e[3] ?? ''), 0, 120)];
            }
        }
        usort($pl, static fn($a, $b) => strcasecmp($a[0], $b[0]));
        $st->execute([$host['id'], 'proclist', json_encode($pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
    }
    // Podman: overzicht, en per container en pod een eigen regel (sleutel: naam, of gebruiker/naam bij rootless)
    // Proxmox VE (agent 1.61.0): guests, opslag, ZFS, back-ups en taken
    if (isset($in['pve']) && is_array($in['pve'])) {
        try {
            Pve::fromAgent($host, $in['pve'], $st, $now);
        } catch (Throwable $e) {
            error_log('RzMon: Proxmox-gegevens niet verwerkt: ' . $e->getMessage());
        }
    }
    if (isset($in['podman']) && is_array($in['podman'])) {
        $pd = $in['podman'];
        $key = static fn(array $x): string => mb_substr((($x['user'] ?? 'root') !== 'root' ? $x['user'] . '/' : '') . ($x['name'] ?? ''), 0, 150);
        $summary = array_intersect_key($pd, array_flip(['version', 'error', 'users', 'images', 'images_size', 'containers_size', 'volumes_size', 'reclaimable', 'pods']));
        $summary['containers'] = [];
        foreach (array_slice((array) ($pd['containers'] ?? []), 0, 300) as $c) {
            if (!is_array($c) || ($c['name'] ?? '') === '') {
                continue;
            }
            $c = array_intersect_key($c, array_flip(['name', 'user', 'pod', 'image', 'state', 'status', 'health', 'exit_code', 'restarts',
                'started', 'cpu', 'mem', 'mem_limit', 'net_in', 'net_out', 'blk_in', 'blk_out', 'pids', 'size_rw', 'volumes']));
            $st->execute([$host['id'], 'container:' . $key($c), json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
            // Kort overzicht voor de Podman-check (zonder de meetwaarden)
            $summary['containers'][] = array_intersect_key($c, array_flip(['name', 'user', 'pod', 'state', 'health', 'exit_code']));
        }
        $st->execute([$host['id'], 'podman', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
    }
    // Services: details van de diepere controle, met een uur herstart-historie (voor "≥ 3 per uur")
    if (isset($in['svc']) && is_array($in['svc'])) {
        $prev = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'svcinfo:%'", [$host['id']]) as $r) {
            $prev[substr($r['metric'], 8)] = json_decode($r['value'], true) ?: [];
        }
        foreach (array_slice($in['svc'], 0, 100, true) as $name => $d) {
            if (!is_array($d) || !preg_match('/^[A-Za-z0-9@._:-]{1,150}$/', (string) $name)) {
                continue;
            }
            $d = array_intersect_key($d, array_flip(['sub', 'type', 'pid', 'restarts', 'since', 'ports', 'ports_fail', 'manual', 'sockets', 'sockets_fail', 'probe', 'probe_rc',
                'probe_msg', 'log_errors', 'log_last', 'custom_rc', 'custom_msg']));
            $hist = array_values(array_filter($prev[$name]['hist'] ?? [], fn($h) => is_array($h) && $h[0] >= time() - 3900));
            if (isset($d['restarts']) && is_numeric($d['restarts'])) {
                $hist[] = [time(), (int) $d['restarts']];
            }
            $d['hist'] = array_slice($hist, -70);
            $st->execute([$host['id'], 'svcinfo:' . $name, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Logbestanden: treffers per rapport bijhouden (24 uur), zodat RzMon over een periode kan tellen
    if (isset($in['logs']) && is_array($in['logs'])) {
        $prevLogs = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'logrun:%'", [$host['id']]) as $r) {
            $prevLogs[substr($r['metric'], 7)] = json_decode($r['value'], true) ?: [];
        }
        foreach (array_slice($in['logs'], 0, 100, true) as $id => $l) {
            if (!ctype_digit((string) $id) || !is_array($l)) {
                continue;
            }
            if (!empty($l['skip'])) {
                // Niet aan de beurt (interval van de check): vorige stand bewaren
                q("UPDATE agent_data SET updated_at = ? WHERE host_id = ? AND metric = ?", [$now, $host['id'], 'logrun:' . $id]);
                continue;
            }
            $p = $prevLogs[$id] ?? [];
            $n = max(0, (int) ($l['new'] ?? 0));
            $ips = [];
            foreach (array_slice((array) ($l['ips'] ?? []), 0, 3) as $ip) {
                if (is_array($ip) && filter_var($ip[0] ?? '', FILTER_VALIDATE_IP)) {
                    $ips[$ip[0]] = (int) ($ip[1] ?? 0);
                }
            }
            $hist = array_values(array_filter($p['hist'] ?? [], fn($h) => is_array($h) && $h[0] >= time() - 86400));
            $hist[] = $n > 0 ? [time(), $n, $ips] : [time(), 0];
            $lines = $p['lines'] ?? [];
            foreach (array_slice((array) ($l['lines'] ?? []), -5) as $line) {
                $lines[] = [time(), mb_substr((string) $line, 0, 300)];
            }
            $st->execute([$host['id'], 'logrun:' . $id, json_encode([
                'hist' => array_slice($hist, -1500),
                'lines' => array_slice($lines, -5),
                'last_hit' => $n > 0 ? time() : ($p['last_hit'] ?? null),
                'error' => mb_substr((string) ($l['error'] ?? ''), 0, 250),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    Perf::part('rapport:meetwaarden');
    // Logins, sudo en su: naar de pagina Meldingen
    if (is_array($in['auth'] ?? null)) {
        AuthEvents::store($host, $in['auth']);
    }
    Perf::part('rapport:logins');
    // IDS: vergelijken met de basismeting, incidenten, brute force blokkeren
    if (is_array($in['ids'] ?? null)) {
        Ids::process($host, $in['ids'], (array) ($in['auth'] ?? []), (array) ($in['ports'] ?? []));
    }
    // Isoleren en gebruikers vergrendelen (agent 1.57.0): de stand bij de agent
    Ids::agentState($host, $in);
    Perf::part('rapport:ids');
    // Logs van webdiensten (agent 1.58.0): SWAG, Home Assistant, Vaultwarden
    if (is_array($in['weblog'] ?? null)) {
        WebLog::process($host, $in['weblog']);
        // 1.110.0: payload-inspectie van verdachte verzoeken (SWAG)
        if (is_array($in['weblog']['swag']['req'] ?? null)) {
            Inspect::requests($host, $in['weblog']['swag']['req']);
        }
    }
    Perf::part('rapport:weblogs');
    // 1.110.0: endpoint-sensoren (processen, netwerk, bestanden, containers)
    $edrResp = is_array($in['edr'] ?? null) ? Edr::process($host, $in['edr']) : [];
    // 1.111.0: vormen de signalen van deze server (regels, afwijkingen, IDS) samen een keten?
    Perf::part('rapport:edr');
    Chain::check($host);
    Perf::part('rapport:ketens');
    // Commando-checks: uitvoer en exitcode (RzMon vergelijkt met het verwachte antwoord)
    foreach ((array) ($in['cmd'] ?? []) as $id => $r) {
        if (ctype_digit((string) $id) && is_array($r)) {
            $st->execute([$host['id'], 'cmd:' . $id, json_encode([
                'rc' => isset($r['rc']) ? (int) $r['rc'] : null,
                'out' => mb_substr((string) ($r['out'] ?? ''), 0, 4096),
                'err' => mb_substr((string) ($r['err'] ?? ''), 0, 500),
                'ms' => (int) ($r['ms'] ?? 0),
                'as' => ($r['as'] ?? '') === 'root' ? 'root' : 'nobody',
                'at' => (int) ($r['at'] ?? 0),
                'blocked' => mb_substr((string) ($r['blocked'] ?? ''), 0, 200),
                'error' => mb_substr((string) ($r['error'] ?? ''), 0, 200),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now]);
        }
    }
    foreach ((array) ($in['svc_tests'] ?? []) as $id => $t) {
        if (ctype_digit((string) $id) && is_array($t)) {
            $st->execute([$host['id'], 'svctest:' . $id, json_encode(['ok' => !empty($t['ok']), 'msg' => mb_substr((string) ($t['msg'] ?? ''), 0, 200)], JSON_UNESCAPED_UNICODE), $now]);
        }
    }
    foreach (($in['services'] ?? []) as $name => $state) {
        $st->execute([$host['id'], mb_substr('service:' . $name, 0, 190), mb_substr((string) $state, 0, 255), $now]);
    }
    // Home Assistant-add-on: laatste back-up, integraties en systeemstatus
    foreach ((array) ($in['ha'] ?? []) as $k => $v) {
        if (in_array($k, ['backup', 'integrations', 'repairs', 'os'], true) && is_array($v)) {
            $st->execute([$host['id'], "ha:$k", json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Wat er voor realtime nodig is (inotify-tools, EPEL op Rocky/Alma/RHEL)
    if (is_array($in['tools'] ?? null)) {
        $tl = $in['tools'];
        $st->execute([$host['id'], 'tools', json_encode(['inotify' => !empty($tl['inotify']), 'epel' => isset($tl['epel']) ? (bool) $tl['epel'] : null,
            'family' => ($tl['family'] ?? '') === 'rhel' ? 'rhel' : 'other', 'no_epel' => !empty($tl['no_epel'])]), $now]);
    }
    // Realtime-watcher: draait hij, en volgt hij het systemlog en de bestanden?
    if (is_array($in['realtime'] ?? null)) {
        $rt = $in['realtime'];
        $st->execute([$host['id'], 'realtime', json_encode(['state' => mb_substr((string) ($rt['state'] ?? ''), 0, 20), 'since' => (int) ($rt['since'] ?? 0),
            'journal' => mb_substr((string) ($rt['journal'] ?? ''), 0, 20), 'files' => mb_substr((string) ($rt['files'] ?? ''), 0, 20)]), $now]);
    }
    // Home Assistant: USB-apparaten (Zigbee-/Z-Wave-sticks, Bluetooth), elk een eigen regel om te kiezen en te bewaken
    foreach (array_slice((array) ($in['usb'] ?? []), 0, 50) as $u) {
        $uid = (string) ($u['id'] ?? '');
        if (is_array($u) && preg_match('/^[A-Za-z0-9._:+@-]{1,150}$/', $uid)) {
            $st->execute([$host['id'], 'usb:' . $uid, json_encode(['name' => mb_substr((string) ($u['name'] ?? ''), 0, 100),
                'vendor' => mb_substr((string) ($u['vendor'] ?? ''), 0, 100), 'path' => mb_substr((string) ($u['path'] ?? ''), 0, 250)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Overzicht van de services op de server (om bij "Check toevoegen" uit te kiezen): [naam, status, enabled]
    if (isset($in['svc_list']) && is_array($in['svc_list'])) {
        $list = [];
        foreach (array_slice($in['svc_list'], 0, 1000) as $e) {
            if (is_array($e) && preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', (string) ($e[0] ?? ''))) {
                $list[] = [(string) $e[0], preg_replace('/[^a-z-]/', '', (string) ($e[1] ?? '')), (int) !empty($e[2])];
            }
        }
        usort($list, static fn($a, $b) => strcmp($a[0], $b[0]));
        $st->execute([$host['id'], 'svclist', json_encode($list), $now]);
    }
    // Deels onleesbaar rapport (1.99.2): wat er werd weggelaten en waarom, voor de Agent Manager
    if ($salvaged) {
        $st->execute([$host['id'], 'report_warn', json_encode($salvaged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now]);
    }
    // 1.107.0: zelf herstellen (uitkomsten, herstelcommando's op de server) en de waakhond
    Heal::fromAgent($host, $in, $st, $now);
    // Vorige stand van de verbindingscontrole (1.100.0), vóór de oude regels verdwijnen
    $prevSec = q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'sec_state'", [$host['id']])->fetchColumn();
    q('DELETE FROM agent_data WHERE host_id = ? AND updated_at < ?', [$host['id'], $now]);
    $agentVersion = preg_match('/^\d+\.\d+\.\d+$/', (string) ($in['agent_version'] ?? '')) ? $in['agent_version'] : null;
    // ℹ️ als de agent (opnieuw) verbindt: de eerste keer, na stil te zijn geweest, of vanaf een ander IP
    Security::agentConnected($host, $encrypted);
    // Contactmomenten (1.99.0): de laatste 21, om het echte ritme te zien
    $beats = array_slice([...array_filter(explode(',', (string) ($host['agent_beats'] ?? ''))), (string) time()], -21);
    q('UPDATE hosts SET agent_seen = ?, agent_ip = ?, agent_version = ?, enc_seen = ?, agent_beats = ? WHERE id = ?',
        [$now, mb_substr(client_ip(), 0, 45), $agentVersion, $encrypted ? 1 : 0, implode(',', $beats), $host['id']]);
    // Andere versie dan vorige keer: bijgewerkt (met de knop, automatisch, via de add-on winkel of met de hand) → info
    if ($agentVersion !== null && $host['agent_version'] !== null && $agentVersion !== $host['agent_version']) {
        $from = (string) $host['agent_version'];
        Monitor::infoHost($host, fn() => t('Agent bijgewerkt van {from} naar {to}', ['from' => $from, 'to' => $agentVersion]));
    }
    $resp = ['ok' => true, 'host' => $host['name'], 'rzmon_version' => RZMON_VERSION, 'received' => [
        'disks' => count($in['disks'] ?? []), 'services' => count($in['services'] ?? []), 'ports' => count($in['ports'] ?? []),
        'certs' => count((array) ($in['certs'] ?? [])), 'containers' => count((array) ($in['podman']['containers'] ?? [])),
    ]];
    $resp['tests_rev'] = agent_tests_rev((int) $host['id'], $encrypted);
    // 1.107.0: herstelcommando's (alleen versleuteld) en, voor de waakhond, hoe lang de checks al niet gedraaid hebben
    if ($encrypted && ($run = Heal::runList((int) $host['id']))) {
        $resp['heal_run'] = $run;
    }
    // 1.110.0: processen die RzMon meteen laat stoppen (alleen versleuteld; anders via de testlijst, bij de volgende run)
    if ($encrypted && $edrResp) {
        $resp += $edrResp;
    }
    if ((int) ($host['watchdog'] ?? 0) === 1) {
        $resp['cron_age'] = Heal::cronAge();
    }
    $resp += agent_enc_fields($host, $agentVersion);
    if ($u = agent_url_report($host, $in, $agentVersion, $encrypted)) {
        $resp['url_set'] = $u;
    }
    // Verbindingscontrole (1.100.0): instellingen op de server, SERVICES omzetten, ⚠️/✅ bij een wisseling; de uitkomst
    // gaat mee terug voor rzmon-agent-setup --status
    try {
        $resp['sec'] = AgentAudit::fromAgent($host, $in, $st, $now, $prevSec === false ? null : (string) $prevSec);
    } catch (Throwable $e) {
        error_log('RzMon: verbindingscontrole mislukt: ' . $e->getMessage());
    }
    // Agent 1.53.0: per adres hoe hij het moet controleren (vastzetten of gewoon); alleen versleuteld
    if ($encrypted && $agentVersion && version_compare($agentVersion, AgentCrypto::PINURL_MIN, '>=')) {
        $pins = [];
        foreach ([(string) ($in['url'] ?? ''), (string) ($in['url_fallback'] ?? '')] as $u2) {
            $u2 = rtrim($u2, '/');
            if ($u2 !== '' && AgentCrypto::validUrl($u2) && ($s = AgentCrypto::urlSpec($u2, false)) !== null) {
                $pins[$u2] = $s;
            }
        }
        if ($pins) {
            $resp['url_pins'] = $pins;
        }
    }
    // Heartbeats van rzmon-hb op de server (agent 1.52.0); namen zonder check gaan terug naar het log van de agent
    if (!empty($in['hb']) && is_array($in['hb'])) {
        $unknown = Heartbeat::fromAgent($host, $in['hb']);
        if ($unknown) {
            $resp['hb_unknown'] = $unknown;
        }
    }
    Perf::part('rapport:overig');
    // Soort apparaat (1.75.0): de HA-add-on is smart home, een gewone agent een server
    HostKind::setAuto((int) $host['id'], !empty($in['ha']) || ($in['update']['via'] ?? '') === 'ha' ? 'smarthome' : 'server');
    // Agent-checks van deze server meteen beoordelen (niet wachten op de volgende cron-minuut)
    try {
        Monitor::runAgentHost((int) $host['id']);
    } catch (Throwable $e) {
        error_log('RzMon: agent-checks direct beoordelen mislukt: ' . $e->getMessage());
    }
    Perf::part('rapport:checks');
    if ($u = agent_update_instruction($host['id'], $agentVersion, $in)) {
        $resp['update'] = $u;
    }
    // Kwetsbaarheden: softwarelijst opslaan, uitkomst van "bijwerken", en eventueel een nieuwe opdracht
    try {
        $resp += Vulns::agentReport($host, $in);
    } catch (Throwable $e) {
        error_log('RzMon: softwarelijst verwerken mislukt: ' . $e->getMessage());
    }
    Perf::part('rapport:software');
    out($resp);
}

/**
 * Bijwerken op afstand: verwerkt wat de agent over updates meldt en bepaalt of hij nu moet bijwerken.
 * De agent haalt dan zelf de nieuwe versie bij RzMon op en controleert die met de SHA-256 hieronder.
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
        if (str_starts_with($result, 'ok')) {
            $sent = null;   // gelukt: een volgende RzMon-versie mag meteen weer automatisch (de info gaat bij de versiewissel)
        } else {
            $why = mb_substr(preg_replace('/^error\s*/', '', $result), 0, 200);
            Monitor::infoHost($h, fn() => '✘ ' . t('Agent bijwerken mislukt') . ': ' . AgentText::tr($why), 'agent_update_fail');
        }
        $req = null;
    } elseif ($req !== null && $sent !== null && strtotime($sent) < time() - 600) {
        $status = '✘ ' . t('Geen antwoord van de update na 10 minuten. Kijk op de server: {cmd}', ['cmd' => 'journalctl -u "rzmon-agent-update-*"']);
        Monitor::infoHost($h, fn() => '✘ ' . t('Agent bijwerken: geen antwoord na 10 minuten'), 'agent_update_fail');
        $req = null;
    }

    $outdated = $version === null || version_compare($version, AGENT_LATEST, '<');
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

// ---------- Webinterface van buiten: alleen op een toegestaan apparaat (1.86.0); anders een 404 ----------
Stealth::gate();

// ---------- Login ----------
// Inloggen via http uitgezet (Instellingen → Beveiliging → Certificaat en https): wachtwoorden alleen via https
if (in_array($action, ['login', 'login_totp', 'pw_reset'], true) && Https::loginBlocked()) {
    $to = Https::adminUrl();
    out(['error' => t('Inloggen via http staat uit: je wachtwoord zou leesbaar over het netwerk gaan.') . ($to !== '' ? ' ' . t('Open {url}', ['url' => $to]) : ''), 'https' => $to ?: null], 403);
}
if ($action === 'login' && $method === 'POST') {
    $in = input();
    $r = login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''), !empty($in['remember']));
    if ($r === 'totp') {
        out(['ok' => true, 'totp_required' => true]);
    }
    if ($r === 'ok') {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    if ($r === 'locked') {
        out(['error' => t('Dit account is geblokkeerd na te veel mislukte pogingen. Vraag een beheerder om het te deblokkeren.'), 'locked' => true], 403);
    }
    if ($r === 'local_only') {
        out(['error' => t('Dit account mag alleen vanaf het interne netwerk inloggen.'), 'local_only' => true], 403);
    }
    if ($r === 'device') {
        out(['error' => t('Dit apparaat is van buiten niet toegestaan voor dit account')], 403);
    }
    if ($r === 'need_2fa') {
        out(['error' => t('Van buiten je interne netwerken is tweestapsverificatie (2FA) verplicht. Stel eerst 2FA in vanaf je thuisnetwerk (Mijn account).'), 'need_2fa' => true], 403);
    }
    out(['error' => t('Onjuiste gebruikersnaam of wachtwoord')], 401);
}
if ($action === 'login_totp' && $method === 'POST') {
    $r = login_totp((string) (input()['code'] ?? ''));
    if ($r === true) {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    // restart: opnieuw beginnen met het wachtwoord (de browser kijkt hiernaar, niet naar de tekst)
    $p = $_SESSION['pending_2fa'] ?? null;
    out(['error' => $r, 'restart' => !$p || $p['tries'] >= 5], 401);
}

// ---------- Inloggen met een passkey (1.86.0): in plaats van wachtwoord en 2FA ----------
if ($action === 'passkey_login_options' && $method === 'POST') {
    Passkey::possible() ? out(Passkey::loginOptions()) : out(['error' => t('Passkeys zijn hier niet beschikbaar')], 422);
}
if ($action === 'passkey_login' && $method === 'POST') {
    if (!Passkey::possible()) {
        out(['error' => t('Passkeys zijn hier niet beschikbaar')], 422);
    }
    $in = input();
    $u = Passkey::loginFinish($in);
    if (is_string($u)) {
        out(['error' => $u], 401);
    }
    if (!Stealth::userAllowed($u['id'])) {
        out(['error' => t('Dit apparaat is van buiten niet toegestaan voor dit account')], 403);
    }
    if (Security::accountLocked($u) && !Security::fromLan()) {
        out(['error' => t('Dit account is geblokkeerd na te veel mislukte pogingen. Vraag een beheerder om het te deblokkeren.'), 'locked' => true], 403);
    }
    if ((int) $u['local_only'] && !Https::internal()) {
        out(['error' => t('Dit account mag alleen vanaf het interne netwerk inloggen.'), 'local_only' => true], 403);
    }
    start_session();
    finish_login($u, !empty($in['remember']), 'passkey');
    out(['ok' => true, 'csrf' => csrf_token()]);
}

// ---------- Wachtwoord vergeten (zonder login) ----------
if ($action === 'pw_forgot' && $method === 'POST') {
    // Altijd hetzelfde antwoord: niemand kan zo uitzoeken welke accounts er bestaan
    $why = PasswordReset::unavailable();
    $send = $why === null ? PasswordReset::request((string) (input()['ident'] ?? '')) : null;
    if ($why !== null) {
        out(['ok' => true, 'no_mail' => true]);   // geen mail mogelijk: "vraag een beheerder"
    }
    http_response_code(200);
    echo json_encode(['ok' => true]);
    // De mail pas na het antwoord versturen: zo duurt het antwoord even lang, of het account nu bestaat of niet
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if ($send) {
        $send();
    }
    exit;
}
if ($action === 'pw_reset_check' && $method === 'POST') {
    $r = PasswordReset::check((string) (input()['token'] ?? ''));
    $r ? out(['ok' => true] + $r) : out(['error' => t('Deze link is verlopen of al gebruikt. Vraag een nieuwe aan via "Wachtwoord vergeten?".')], 410);
}
if ($action === 'pw_reset' && $method === 'POST') {
    $in = input();
    $who = PasswordReset::check((string) ($in['token'] ?? ''));
    $r = PasswordReset::reset((string) ($in['token'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['code'] ?? ''));
    // dead: de link werkt niet meer (verlopen, gebruikt of te veel pogingen); pwned: het nieuwe wachtwoord staat in een datalek
    $r === true ? out(['ok' => true, 'pwned' => $who ? (int) q('SELECT COALESCE(pw_pwned, 0) FROM users WHERE username = ? AND hibp = 1', [$who['username']])->fetchColumn() : 0])
        : out(['error' => $r, 'dead' => PasswordReset::check((string) ($in['token'] ?? '')) === null], 422);
}

$user = current_user();
// Het CSRF-token van deze sessie gaat met elk antwoord mee (1.84.2). Is de sessie intussen vernieuwd (bv. na een
// tijd stil opnieuw ingelogd via "onthoud mij", met een nieuw token), dan neemt de pagina het nieuwe over.
header('X-CSRF-Token: ' . csrf_token());
if (!$user) {
    out(['error' => t('Niet ingelogd')], 401);
}
if ($method !== 'GET' && !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    out(['error' => t('Ongeldig CSRF token'), 'csrf' => true], 403);
}

// Wachtwoord staat in een datalek: eerst een nieuw kiezen (alleen dat, uitloggen en de taal mogen)
if (must_change_password($user) && !in_array($action, ['pw_forced', 'logout', 'lang_set'], true)) {
    out(['error' => t('Je wachtwoord staat in een datalek: kies eerst een nieuw wachtwoord'), 'must_change' => true], 403);
}

// Rechten per actie. Alles wat hier niet staat, is alleen voor beheerders.
$need = match ($action) {
    'state', 'rev', 'contacts', 'alerts', 'ids', 'ids_incident_info', 'edr_feed', 'ids_anom', 'ids_mitre', 'ids_protect', 'ip_info', 'ids_access', 'harden_detail', 'ip_timeline', 'check_info', 'history', 'stats', 'podman', 'logout', 'password', 'users', 'totp_setup', 'totp_confirm', 'totp_reset',
    'devices', 'device_forget', 'device_forget_all', 'sessions', 'my_access', 'device_trust', 'device_revoke', 'device_rename',
    'passkey_reg_options', 'passkey_reg_finish', 'passkey_delete', 'passkey_rename', 'session_logout', 'sessions_logout_user', 'user_email', 'lang_set', 'my_logins', 'pw_forced', 'prefs_save' => 'readonly',
    'net_devices', 'pve_overview', 'analytics', 'smart_proposals', 'ack', 'ack_host', 'unack', 'check_run', 'run_all', 'maintenance_set', 'port_scan', 'vulns', 'vuln_packages', 'vuln_detail', 'vuln_ignore', 'vuln_unignore', 'vuln_upgrade_status' => 'user',
    default => 'admin',
};
if (!has_role($user, $need)) {
    out(['error' => t('Daar heb je geen rechten voor (je bent {role})', ['role' => mb_strtolower(t(ROLE_LABELS[$user['role']] ?? '?'))])], 403);
}
$isAdmin = has_role($user, 'admin');
I18n::init($user);
// 1.119.0: alleen lezen? Dan de sessie meteen vrijgeven. PHP laat andere verzoeken van dezelfde browser anders wachten
// tot dit verzoek klaar is; één trage pagina maakte zo alles traag.
if ($method === 'GET' && in_array($action, ['state', 'rev', 'contacts', 'alerts', 'ids', 'ids_incident_info', 'edr_feed', 'ids_anom', 'ids_mitre', 'ids_protect', 'ip_info',
    'ids_access', 'harden_detail', 'ip_timeline', 'check_info', 'history', 'stats', 'analytics', 'vulns', 'vuln_packages', 'vuln_detail', 'net_devices', 'pve_overview',
    'smart_proposals', 'setup_info', 'settings', 'edr_rules', 'ti_status'], true)) {
    session_write_close();
}
// Wijzigingslogboek (1.97.0): instellingen vóór en na elke wijziging van een beheerder vergelijken
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['prefs_save', 'check_try', 'changelog_undo', 'state'], true)) {
    $settingsBefore = ChangeLog::settingsSnapshot();
    register_shutdown_function(static function () use ($settingsBefore, $action): void {
        try {
            ChangeLog::settingsDiff($settingsBefore, ChangeLog::settingsSnapshot(), $action);
        } catch (Throwable) {
        }
    });
}
/** Niet-beheerders mogen alleen hun eigen account (2FA) beheren. */
$ownOnly = static function (int $id) use ($user, $isAdmin): void {
    if (!$isAdmin && $id !== $user['id']) {
        out(['error' => t('Je kunt alleen je eigen account beheren')], 403);
    }
};

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

/**
 * Bevestiging voor een gevoelige actie: je eigen wachtwoord, of een woord typen
 * als je automatisch beheerder bent via het LAN (dan heb je geen wachtwoord ingevuld).
 */
$confirm = static function (string $word) use ($user): void {
    $c = (string) (input()['confirm'] ?? '');
    if (is_lan_session()) {
        if ($c !== $word && $c !== t($word)) {   // het Nederlandse woord, of het woord in de taal van de gebruiker
            out(['error' => t('Typ {word} om te bevestigen', ['word' => t($word)])], 422);
        }
        return;
    }
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
    if (!$hash || !password_verify($c, $hash)) {
        usleep(500000);
        out(['error' => t('Je wachtwoord klopt niet')], 422);
    }
};

/**
 * Een check uit het formulier controleren (1.94.0): de kolommen voor de tabel checks, en of er een nieuwe heartbeat-URL moet komen.
 * Gebruikt door check_save, sjablonen (template_item_save) en kopiëren. Bij een fout: out() met de foutmelding.
 */
$checkCols = static function (array $in, bool $dry = false) use ($user): array {   // $dry: alleen proberen (geen logboek)
            $type = $in['type'] ?? '';
            if (!in_array($type, ['ping', 'tcp', 'http', 'ssl', 'disk', 'service', 'cpu', 'ram', 'podman', 'container', 'log', 'ha', 'command', 'snmp', 'reboot', 'heartbeat', 'process', 'pve'], true)) {
                out(['error' => t('Ongeldig type')], 422);
            }
            // Commando-check: commando, verwacht antwoord, manier van vergelijken, time-out en status bij afwijking
            $command = null;
            if ($type === 'command') {
                $cmdCfg = AgentCommands::clean((array) ($in['command'] ?? []));
                if (is_string($cmdCfg)) {
                    out(['error' => $cmdCfg], 422);
                }
                $command = json_encode($cmdCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $prev = !empty($in['id']) ? q('SELECT command FROM checks WHERE id = ?', [(int) $in['id']])->fetchColumn() : null;
                if ($prev !== $command && !$dry) {
                    AgentCommands::audit($user['username'], empty($in['id']) ? 'commando-check aangemaakt' : 'commando-check gewijzigd',
                        'server ' . (int) ($in['host_id'] ?? 0) . ': ' . $cmdCfg['cmd'] . ' → ' . AgentCommands::MATCHES[$cmdCfg['match']] . ' "' . $cmdCfg['expect'] . '"');
                }
            }
            $metric = null;   // container: één check met status + gekozen resources (options.res)
            if ($type === 'ha') {
                $metric = (string) ($in['metric'] ?? '');
                if (!in_array($metric, ['backup', 'integrations', 'repairs', 'reboot', 'disklife', 'osupdate', 'usb'], true)) {
                    out(['error' => t('Kies wat je van Home Assistant wilt bewaken')], 422);
                }
                if ($metric === 'usb' && !preg_match('/^[A-Za-z0-9._:+@-]{1,150}$/', trim((string) ($in['target'] ?? '')))) {
                    out(['error' => t('Kies het USB-apparaat uit de lijst')], 422);
                }
            }
            $target = trim((string) ($in['target'] ?? ''));
            // Proxmox VE (1.105.0): wat, en voor een guest de VMID
            if ($type === 'pve') {
                $metric = (string) ($in['metric'] ?? 'overview');
                if (!in_array($metric, Pve::METRICS, true)) {
                    out(['error' => t('Kies wat je van Proxmox wilt bewaken')], 422);
                }
                if ($metric === 'guest' && !preg_match('/^\d{1,9}$/', $target)) {
                    out(['error' => t('Vul de VMID van de guest in (bv. 100)')], 422);
                }
                if ($metric === 'backups' && $target !== '' && !preg_match('/^\d{1,9}$/', $target)) {
                    out(['error' => t('Laat leeg voor alle guests, of vul één VMID in')], 422);
                }
                if ($metric === 'storage' && $target !== '' && !preg_match('/^[A-Za-z0-9._-]{1,60}$/', $target)) {
                    out(['error' => t('Ongeldige naam van de opslag')], 422);
                }
                if (in_array($metric, ['overview', 'zfs'], true)) {
                    $target = '';
                }
            }
            // SNMP: wat (metric) en, waar nodig, de index uit de discovery of de eigen OID
            $snmpLow = false;
            if ($type === 'snmp') {
                $metric = (string) ($in['metric'] ?? '');
                $sm = SnmpMonitor::METRICS[$metric] ?? null;
                if (!$sm) {
                    out(['error' => t('Kies wat je via SNMP wilt bewaken')], 422);
                }
                if ($metric === 'oid') {
                    $target = ltrim($target, '.');
                    if (!preg_match('/^1(\.\d{1,10}){3,60}$/', $target)) {
                        out(['error' => t('Geef een numerieke OID, bv. 1.3.6.1.4.1.6574.1.2.0 (namen als sysName.0 werken niet)')], 422);
                    }
                } elseif ($sm['index'] === 'name') {
                    // UniFi: band of SSID als doel (leeg = totaal, alleen bij clients)
                    if (($target === '' && $metric !== 'unifi_clients') || mb_strlen($target) > 100 || preg_match('/[\x00-\x1f]/', $target)) {
                        out(['error' => t('Kies de band of SSID via ＋ SNMP-checks')], 422);
                    }
                } elseif ($sm['index'] && !preg_match('/^\d{1,10}(\.\d{1,10}){0,10}$/', $target)) {
                    out(['error' => t('Kies de interface, schijf of voorraad via ＋ SNMP-checks')], 422);
                } elseif (!$sm['index']) {
                    $target = '';
                }
                $snmpLow = $sm['low'];
            }
            if ($type === 'tcp' && ((int) $target < 1 || (int) $target > 65535)) {
                out(['error' => t('Geef een geldige poort op (1-65535)')], 422);
            }
            if ($type === 'service' && !preg_match('/^[A-Za-z0-9@._:-]+$/', $target)) {
                out(['error' => t('Geef een geldige servicenaam op')], 422);
            }
            if ($type === 'container' && !preg_match('#^([a-z_][a-z0-9_.-]*/)?[A-Za-z0-9][A-Za-z0-9_.-]*$#', $target)) {
                out(['error' => t('Geef de naam van een container op (rootless: gebruiker/naam)')], 422);
            }
            $warn = num($in['warn_threshold'] ?? null);
            $crit = num($in['crit_threshold'] ?? null);
            $options = null;
            if ($type === 'snmp' && $metric === 'oid') {
                // Eigen OID: getal, teller (per seconde) of tekst vergelijken
                $mode = in_array($in['oid_mode'] ?? '', ['gauge', 'counter', 'text'], true) ? $in['oid_mode'] : 'gauge';
                $o = ['mode' => $mode];
                if ($mode === 'text') {
                    $o['match'] = in_array($in['oid_match'] ?? '', ['equals', 'contains', 'not_contains', 'regex'], true) ? $in['oid_match'] : 'equals';
                    $o['expect'] = mb_substr(trim((string) ($in['oid_expect'] ?? '')), 0, 150);
                    $o['fail'] = ($in['oid_fail'] ?? '') === 'warn' ? 'warn' : 'crit';
                    if ($o['match'] === 'regex' && @preg_match('~' . str_replace('~', '\\~', $o['expect']) . '~i', '') === false) {
                        out(['error' => t('De regex klopt niet')], 422);
                    }
                    $warn = $crit = null;
                } else {
                    $scale = num($in['oid_scale'] ?? null);
                    if ($scale !== null && $scale != 1) {
                        $o['scale'] = $scale;
                    }
                    $unit = mb_substr(trim((string) ($in['oid_unit'] ?? '')), 0, 12);
                    if ($unit !== '') {
                        $o['unit'] = $unit;
                    }
                    if (!empty($in['oid_low'])) {
                        $o['low'] = 1;
                        $snmpLow = true;
                    }
                }
                $options = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($type === 'snmp' && $metric === 'ups_status') {
                $warn = $crit = null;   // UPS-status: geen drempels
            }
            if ($type === 'ssl' || $snmpLow) {
                // Hier geldt "minder = slechter" (dagen, batterij, toner…)
                if ($warn !== null && $crit !== null && $warn < $crit) {
                    out(['error' => $type === 'ssl' ? t('Bij een certificaat moet de warning-drempel (dagen) hoger zijn dan de critical-drempel')
                        : t('Hier is lager slechter: de warning-drempel moet hoger zijn dan de critical-drempel')], 422);
                }
            } elseif ($warn !== null && $crit !== null && $warn > $crit) {
                out(['error' => t('Warning-drempel moet lager zijn dan critical-drempel')], 422);
            }
            if ($type === 'ssl' && $target !== '' && !preg_match('#^(https?://)?(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.\-]+)(:\d{1,5})?/?$#', $target)
                && !preg_match('#^/[^\s\'"]+$#', $target)) {
                out(['error' => t('Geef een host op, eventueel met poort (example.com of example.com:8443), of het pad van een certificaatbestand dat de agent meldt')], 422);
            }
            if (in_array($type, ['ping', 'cpu', 'ram', 'podman', 'ha', 'command', 'reboot'], true)) {
                $target = '';
            }
            if ($type === 'command' || $type === 'reboot') {
                $warn = $crit = null;   // geen drempels: een commando vergelijkt tekst, een herstart geeft alleen info
            }
            if ($type === 'ha' && $metric === 'repairs') {
                $warn = $crit = null;   // systeemstatus: unhealthy = critical, de rest warning
            }
            if ($type === 'container') {
                // Status altijd; CPU, RAM en disk alleen als aangevinkt, elk met eigen drempels
                $warn = $crit = null;
                $res = [];
                foreach (['cpu' => '%', 'mem' => '%', 'disk' => 'MB'] as $k => $u) {
                    $r = $in['res'][$k] ?? null;
                    if (!is_array($r) || empty($r['on'])) {
                        continue;
                    }
                    $w = num($r['warn'] ?? null);
                    $c = num($r['crit'] ?? null);
                    if ($w === null && $c === null) {
                        out(['error' => t('Vul bij {what} minstens één drempel in', ['what' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'][$k]])], 422);
                    }
                    if ($w !== null && $c !== null && $w > $c) {
                        out(['error' => t('Bij {what} moet de warning-drempel lager zijn dan de critical-drempel', ['what' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'][$k]])], 422);
                    }
                    $res[$k] = [$w, $c];
                }
                $options = json_encode(['res' => (object) $res]);
                // Eén check per container: bestaat er al een, dan die aanpassen
                $dup = q("SELECT id FROM checks WHERE host_id = ? AND type = 'container' AND target = ? AND id <> ?",
                    [(int) ($in['host_id'] ?? q('SELECT host_id FROM checks WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn()), $target, (int) ($in['id'] ?? 0)])->fetchColumn();
                if ($dup) {
                    out(['error' => t('Container {name} wordt al bewaakt. Pas die check aan (✎).', ['name' => $target]), 'existing_id' => (int) $dup], 409);
                }
            }
            if ($type === 'log') {
                // Bron: een logbestand (absoluut pad), het systemlog (journal), het log van één service, of kernelmeldingen
                if (!preg_match('#^(/[A-Za-z0-9._/@+-]{1,250}|journal|journal:[A-Za-z0-9@._:-]{1,100}|kernel|ha:(host|core|supervisor|addon:[a-z0-9_]{1,100}))$#', $target)) {
                    out(['error' => t('Bron: geef een logbestand (bv. /var/log/secure), "journal", "journal:sshd" of "kernel"; bij Home Assistant "ha:host", "ha:core", "ha:supervisor" of "ha:addon:<slug>"')], 422);
                }
                $pattern = trim((string) ($in['pattern'] ?? ''));
                if ($pattern === '' || mb_strlen($pattern) > 300) {
                    out(['error' => t('Geef aan waarnaar gezocht moet worden (maximaal 300 tekens)')], 422);
                }
                if (@preg_match('~' . str_replace('~', '\\~', $pattern) . '~i', '') === false) {
                    out(['error' => t('Het zoekpatroon klopt niet (regex). Tip: gebruik | tussen alternatieven, bv. Failed password|Invalid user')], 422);
                }
                $options = json_encode(['pattern' => $pattern, 'preset' => mb_substr((string) ($in['preset'] ?? ''), 0, 30)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($type === 'service') {
                $testType = in_array($in['test_type'] ?? '', ['url', 'port'], true) ? $in['test_type'] : '';
                $testTarget = trim((string) ($in['test_target'] ?? ''));
                if ($testType === 'url' && !preg_match('#^https?://[^\s]+$#i', $testTarget)) {
                    out(['error' => t('Extra test: geef een URL die met http:// of https:// begint, bv. http://localhost:8080/health')], 422);
                }
                if ($testType === 'port' && !preg_match('#^(([A-Za-z0-9.\-]+|\[[0-9a-fA-F:]+\]):)?\d{1,5}$#', $testTarget)) {
                    out(['error' => t('Extra test: geef een poort (8080) of host:poort (localhost:8080)')], 422);
                }
                // Eigen poorten/sockets: "8080, 127.0.0.1:9000, [::1]:80, /run/php-fpm/www.sock" (max. 10)
                $ports = [];
                foreach (preg_split('/[\s,;]+/', trim((string) ($in['ports'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $pt) {
                    $isSocket = preg_match('#^/[A-Za-z0-9._/@+-]{1,200}$#', $pt);
                    $isPort = preg_match('#^(([A-Za-z0-9.\-]+|\[[0-9a-fA-F:]+\]):)?(\d{1,5})$#', $pt, $pm) && (int) $pm[3] >= 1 && (int) $pm[3] <= 65535;
                    if (!$isSocket && !$isPort) {
                        out(['error' => t('Poort of socket "{p}" klopt niet. Gebruik bv. 8080, 127.0.0.1:9000 of /run/php-fpm/www.sock', ['p' => $pt])], 422);
                    }
                    $ports[] = $pt;
                }
                if (count($ports) > 10) {
                    out(['error' => t('Geef maximaal 10 poorten of sockets op')], 422);
                }
                $options = json_encode(['deep' => ($in['deep'] ?? true) ? 1 : 0] + ($ports ? ['ports' => implode(' ', array_unique($ports))] : []) + ($testType !== '' ? [
                    'test_type' => $testType, 'test_target' => mb_substr($testTarget, 0, 300),
                    'test_expect' => mb_substr(trim((string) ($in['test_expect'] ?? '')), 0, 100),
                ] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            // Heartbeat (1.77.0): naam voor rzmon-hb, schema, speling, maximale duur; de geheime URL maakt RzMon zelf
            $hbNew = false;
            if ($type === 'heartbeat') {
                if (!preg_match(Heartbeat::NAME, $target)) {
                    out(['error' => t('Geef een naam voor de heartbeat: letters, cijfers, punt, - of _ (bv. backup-db)')], 422);
                }
                $hid = (int) ($in['host_id'] ?? q('SELECT host_id FROM checks WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn());
                if (q("SELECT id FROM checks WHERE host_id = ? AND type = 'heartbeat' AND target = ? AND id <> ?", [$hid, $target, (int) ($in['id'] ?? 0)])->fetchColumn()) {
                    out(['error' => t('Deze server heeft al een heartbeat met de naam {n}', ['n' => $target])], 409);
                }
                $hb = Heartbeat::clean($in);
                if (is_string($hb)) {
                    out(['error' => $hb], 422);
                }
                if (!isset($hb['unit'])) {
                    $warn = $crit = null;   // drempels alleen voor een meegestuurde waarde
                }
                $options = json_encode($hb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $hbNew = !empty($in['hb_new_token']);
            }
            // Proces (1.98.0): op naam of op een patroon in de opdrachtregel, eventueel van één gebruiker; aantal en CPU/RAM
            if ($type === 'process') {
                $pmode = ($in['proc_mode'] ?? '') === 'pattern' ? 'pattern' : 'name';
                if ($pmode === 'name' && !preg_match('/^[A-Za-z0-9@._:+-]{1,100}$/', $target)) {
                    out(['error' => t('Geef de naam van het proces, bv. nginx of python3 (of kies "patroon" voor een deel van de opdrachtregel)')], 422);
                }
                if ($pmode === 'pattern' && ($target === '' || mb_strlen($target) > 200 || preg_match('/[\t\n\r]/', $target) || @preg_match('~' . str_replace('~', '\\~', $target) . '~', '') === false)) {
                    out(['error' => t('Het patroon klopt niet (regex), bv. python3 .*main\.py')], 422);
                }
                $puser = trim((string) ($in['proc_user'] ?? ''));
                if ($puser !== '' && !preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $puser)) {
                    out(['error' => t('Ongeldige gebruikersnaam')], 422);
                }
                $pmin = trim((string) ($in['proc_min'] ?? '')) === '' ? null : max(1, min(10000, (int) $in['proc_min']));
                $pmax = trim((string) ($in['proc_max'] ?? '')) === '' ? null : max(1, min(10000, (int) $in['proc_max']));
                if ($pmin !== null && $pmax !== null && $pmin > $pmax) {
                    out(['error' => t('Het minimum mag niet hoger zijn dan het maximum')], 422);
                }
                $res = [];
                foreach (['cpu', 'mem'] as $k) {
                    $r = $in['res'][$k] ?? null;
                    if (is_array($r) && !empty($r['on'])) {
                        $w = num($r['warn'] ?? null);
                        $c = num($r['crit'] ?? null);
                        if ($w !== null && $c !== null && $w > $c) {
                            out(['error' => t('Bij {what} moet de warning-drempel lager zijn dan de critical-drempel', ['what' => $k === 'cpu' ? 'CPU' : 'RAM'])], 422);
                        }
                        $res[$k] = [$w, $c];
                    }
                }
                $options = json_encode(['mode' => $pmode] + ($puser !== '' ? ['user' => $puser] : []) + ($pmin !== null ? ['min' => $pmin] : []) + ($pmax !== null ? ['max' => $pmax] : [])
                    + ($res ? ['res' => $res] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $warn = $crit = null;
            }
            if ($type === 'pve' && $metric === 'backups') {
                $per = trim(mb_substr((string) ($in['pve_per'] ?? ''), 0, 500));
                $options = $per !== '' ? json_encode(['per' => $per]) : null;
            }
            // Certificaat (1.98.0): vanaf RzMon of vanaf de server zelf (agent); leeg = automatisch (bestand of localhost → agent)
            if ($type === 'ssl') {
                if (preg_match('/\s/', $target)) {
                    out(['error' => t('Een pad of adres zonder spaties')], 422);
                }
                $from = in_array($in['cert_from'] ?? '', ['agent', 'rzmon'], true) ? $in['cert_from'] : '';
                $options = $from !== '' ? json_encode(['from' => $from]) : null;
            }
            if ($type === 'pve' && trim((string) ($in['name'] ?? '')) === '') {
                $g = $metric === 'guest' ? q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [(int) ($in['host_id'] ?? 0), "pveg:$target"])->fetchColumn() : false;
                $g = $g ? json_decode((string) $g, true) : null;
                $in['name'] = ['overview' => 'Proxmox', 'guest' => $g ? ($g['kind'] === 'ct' ? '📦 ' : '🖥️ ') . ($g['name'] ?: "VM $target") : "VM $target",
                    'backups' => $target !== '' ? t('Back-up {id}', ['id' => $target]) : t('Proxmox-back-ups'), 'storage' => t('Proxmox-opslag') . ($target !== '' ? " $target" : ''), 'zfs' => 'ZFS'][$metric];
            }
            $name = trim((string) ($in['name'] ?? '')) ?: ['ping' => 'Ping', 'tcp' => 'Poort', 'process' => t('Proces'), 'http' => 'HTTP', 'ssl' => 'Certificaat', 'disk' => 'Disk', 'service' => 'Service', 'podman' => 'Podman',
                'container' => '📦', 'cpu' => 'CPU', 'ram' => 'RAM', 'log' => 'Log', 'command' => 'Commando', 'reboot' => t('Herstart'), 'heartbeat' => 'Heartbeat', 'snmp' => t(SnmpMonitor::METRICS[$metric]['label'] ?? 'SNMP'),
                'ha' => ['backup' => 'HA back-up', 'integrations' => 'HA integraties', 'repairs' => 'HA systeemstatus', 'reboot' => 'HA OS herstart',
                    'disklife' => 'SD-kaart/eMMC', 'osupdate' => 'HA OS-update', 'usb' => 'USB'][$metric] ?? 'HA'][$type] . ($target !== '' ? " $target" : '');
            // Eigen meldregels voor deze check (null = de algemene uit Instellingen → Alarmgedrag)
            $notify = null;
            if (is_array($in['notify'] ?? null)) {
                $notify = json_encode(['rules' => NotifyRules::cleanRules((array) ($in['notify']['rules'] ?? [])),
                    'channels' => NotifyRules::cleanChannels((array) ($in['notify']['channels'] ?? []))]
                    + (is_array($in['notify']['flap'] ?? null) ? ['flap' => NotifyRules::cleanFlap($in['notify']['flap'])] : []));
            }
            // Stille uren: null = algemeen, of eigen vensters / nooit stil
            $quiet = is_array($in['quiet'] ?? null) && ($qo = NotifyRules::cleanQuietOwn($in['quiet'])) !== null ? json_encode($qo) : null;
            // Omgekeerde status (alleen bij werkt-het-wel-of-niet-checks): status als hij slaagt / mislukt
            $revOk = in_array($type, Checks::REVERSIBLE, true) && in_array((int) ($in['reverse_ok'] ?? 0), [STATUS_WARN, STATUS_CRIT], true) ? (int) $in['reverse_ok'] : null;
            $revFail = $revOk !== null ? max(STATUS_OK, min(STATUS_CRIT, (int) ($in['reverse_fail'] ?? STATUS_OK))) : null;
            // Melden na zoveel mislukte checks achter elkaar; leeg = de algemene instelling
            $fails = trim((string) ($in['notify_fails'] ?? '')) === '' ? null : max(1, min(100, (int) $in['notify_fails']));
    return [['type' => $type, 'target' => $target, 'name' => mb_substr($name, 0, 100), 'warn_threshold' => $warn, 'crit_threshold' => $crit,
        'interval_sec' => max(30, (int) ($in['interval_sec'] ?? 60)), 'enabled' => !empty($in['enabled']) ? 1 : 0, 'verify_ssl' => ($in['verify_ssl'] ?? true) ? 1 : 0,
        'metric' => $metric, 'options' => $options, 'notify' => $notify, 'quiet' => $quiet, 'command' => $command, 'reverse_ok' => $revOk, 'reverse_fail' => $revFail,
        'notify_fails' => $fails, 'info_only' => !empty($in['info_only']) ? 1 : 0], $hbNew];
};


switch ($action) {
    case 'logout':
        logout();
        out(['ok' => true]);

    case 'contacts':   // 1.99.0: laatste contact per agent, voor de live tijden (elke 5 seconden)
        $out = [];
        foreach (q("SELECT id, agent_seen, agent_tick, realtime, agent_beats FROM hosts WHERE mode = 'agent'")->fetchAll() as $h) {
            $out[(int) $h['id']] = agent_contact($h);
        }
        out(['hosts' => (object) $out, 'now_ts' => time()]);

    case 'state':
        $hosts = q('SELECT id, name, address, mode, parent_id, agent_token, agent_seen, agent_version, agent_update_req, agent_update_status, agent_update_via, agent_tick, realtime, info_agent, enc, enc_seen, watchdog, watchdog_min,
                    agent_key_seen, agent_key_at, agent_ip_new, pin_mode, ip_lock, agent_https, agent_conn, agent_fb_at, agent_url, agent_url_fb, agent_url_want, agent_url_status, agent_url_at, agent_url_pin, agent_url_fb_pin,
                    enabled, maintenance_until, maintenance_reason, snmp, kind, kind_auto, agent_beats, edr_on, edr_info FROM hosts ORDER BY name')->fetchAll();
        $checks = q('SELECT c.*, h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                     FROM checks c JOIN hosts h ON h.id = c.host_id ORDER BY c.name')->fetchAll();
        $global = Monitor::globalMaintenance();
        $agent = q("SELECT host_id, metric FROM agent_data")->fetchAll();
        $byHost = [];
        foreach ($checks as $c) {
            // Effectief onderhoud (globaal, server of check) voor de weergave
            $c['maintenance'] = Monitor::maintenanceFor($c);
            unset($c['host_maintenance_until'], $c['host_maintenance_reason']);
            if (!$isAdmin) {
                unset($c['hb_token']);   // geheime heartbeat-URL alleen voor beheerders
            }
            $byHost[$c['host_id']][] = $c;
        }
        $metrics = [];
        foreach ($agent as $a) {
            $metrics[$a['host_id']][] = $a['metric'];
        }
        $discover = $isAdmin ? Discover::counts() : [];   // 1.95.0: nog niet bewaakt (alleen beheerders voegen checks toe)
        foreach ($hosts as &$h) {
            $h['discover_n'] = $discover[(int) $h['id']] ?? 0;
            $h['checks'] = $byHost[$h['id']] ?? [];
            $h['metrics'] = $metrics[$h['id']] ?? [];
            $own = $h['maintenance_until'] && strtotime($h['maintenance_until']) > time()
                ? ['scope' => 'host', 'until' => $h['maintenance_until'], 'reason' => (string) $h['maintenance_reason']] : null;
            $h['maintenance'] = $global ?? $own;
            $h['snmp'] = SnmpMonitor::masked($h['snmp']);   // community en wachtwoorden gaan nooit naar de browser
            $h['contact'] = $h['mode'] === 'agent' ? agent_contact($h) : null;
            unset($h['agent_beats']);
        }
        unset($h);
        if (!$isAdmin) {
            // Agent-tokens zijn geheim: alleen beheerders installeren agents
            foreach ($hosts as &$h) {
                $h['agent_token'] = null;
            }
            unset($h);
        }
        $lastRun = q('SELECT MAX(last_checked) FROM checks')->fetchColumn();
        out(['hosts' => $hosts, 'badges' => tab_badges($user, $hosts, (string) ($_GET['seen'] ?? '')), 'last_run' => $lastRun, 'user' => $user['username'],
             'base_url' => config('base_url', ''), 'lan_session' => is_lan_session(), 'maintenance' => $global,
             'version' => RZMON_VERSION, 'agent_latest' => AGENT_LATEST, 'edr_note_hide' => json_decode((string) setting('edr_note_hide', '{}'), true) ?: (object) [], 'role' => $user['role'], 'user_id' => $user['id'], 'health' => Health::current(), 'health_groups' => Health::groups(),
             'agent_tick' => agent_tick(null), 'now_ts' => time(),
             // Sjablonen (1.94.0): item → naam van het sjabloon, en welke sjablonen per server
             'templates' => q('SELECT id, name FROM check_templates ORDER BY name')->fetchAll(),
             'tpl_items' => (object) array_column(q('SELECT ti.id, t.name FROM template_items ti JOIN check_templates t ON t.id = ti.template_id')->fetchAll(), 'name', 'id'),
             'host_tpls' => (object) array_reduce(q('SELECT host_id, template_id FROM host_templates')->fetchAll(), static function ($a, $r) { $a[$r['host_id']][] = (int) $r['template_id']; return $a; }, []),
             'notify_fails' => max(1, (int) setting('notify_fails', '1')),
             'thresh_default' => (string) setting('thresh_default', 'guard'), 'smart_sens' => (string) setting('smart_sens', 'normal'),
             // Open critical IDS-incidenten (rood bolletje op het tabblad 🛡️ IDS)
             'net_new' => NetScan::enabled() ? NetScan::newCount() : 0,
             'ids_open' => Ids::enabled() ? (int) q("SELECT COUNT(*) FROM ids_incidents WHERE status = 'open' AND severity = 2")->fetchColumn() : 0,
             // Voor beheerders: label "⬆ x.y.z" bovenin als er een nieuwere versie is
             'update_running' => $isAdmin && Updater::running(),
             // Voor het installatiecommando in de Agent Manager (--key): alleen beheerders, net als de tokens
             'enc_key' => $isAdmin && AgentCrypto::globalMode() !== 'off' ? (AgentCrypto::key() ?: null) : null,
             'dl_key' => $isAdmin ? Stealth::dlKey() : null,   // downloadsleutel voor de installatiecommando's (1.86.0)
             'conn_pin' => AgentCrypto::pinOn(), 'conn_ip_lock' => AgentCrypto::ipLockOn(), 'conn_https' => AgentCrypto::httpsOnly(),
             'url_min' => AgentCrypto::URL_MIN,
             'update' => $isAdmin && ($uc = json_decode((string) setting('update_cache', ''), true)) && !empty($uc['latest'])
                 && version_compare($uc['latest'], RZMON_VERSION, '>') ? $uc['latest'] : null]);

    // Meldingen van de bewaartermijn (Instellingen → Alarmgedrag), nieuwste eerst; de pagina groepeert per dag
    case 'alerts':
        // 1.119.0: standaard de laatste 7 dagen; ouder op verzoek (?days)
        $maxDays = Monitor::alertDays();
        $days = max(1, min($maxDays, (int) ($_GET['days'] ?? $maxDays)));
        $rows = q('SELECT a.*, c.name AS check_name, h.name AS host_name FROM alerts a
                   LEFT JOIN checks c ON c.id = a.check_id LEFT JOIN hosts h ON h.id = COALESCE(c.host_id, a.host_id)
                   WHERE a.created_at >= CURDATE() - INTERVAL ? DAY AND a.kind NOT IN (\'auth\', \'authfail\')
                   ORDER BY a.created_at DESC, a.id DESC LIMIT 10000', [$days - 1])->fetchAll();
        // Meldingen met een IP (geweigerde verbinding, login): staat dat IP al op de blokkadelijst?
        $nets = array_column(q('SELECT net FROM rz_blocks WHERE until IS NULL OR until > NOW()')->fetchAll(), 'net');
        foreach ($rows as &$a) {
            $a['blocked'] = $a['ip'] !== null && $a['ip'] !== '' && $nets && ip_in_list((string) $a['ip'], $nets);
        }
        unset($a);
        out(['days' => $days, 'max_days' => $maxDays, 'alerts' => $rows, 'today' => date('Y-m-d'), 'truncated' => count($rows) >= 10000]);

    // Popup van een check: gegevens, beschikbaarheid en de laatste 5 statuswijzigingen met hun meldingen
    case 'check_info':
        $id = (int) ($_GET['id'] ?? 0);
        $c = q('SELECT c.*, h.name AS host_name, h.mode FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
        if (!$c) {
            out(['error' => t('Deze check bestaat niet meer')], 404);
        }
        $up = q('SELECT SUM(created_at >= NOW() - INTERVAL 1 DAY) AS n1, SUM(created_at >= NOW() - INTERVAL 1 DAY AND status <> 2) AS ok1,
                        COUNT(*) AS n7, SUM(status <> 2) AS ok7
                 FROM results WHERE check_id = ? AND created_at >= NOW() - INTERVAL 7 DAY', [$id])->fetch();
        $pct = static fn($ok, $n) => (int) $n > 0 ? round(100 * (int) $ok / (int) $n, 1) : null;
        $changes = q('SELECT * FROM status_changes WHERE check_id = ? ORDER BY created_at DESC, id DESC LIMIT 5', [$id])->fetchAll();
        $end = null;   // de wijziging erna (nieuwste eerst), of nu
        foreach ($changes as &$ch) {
            // Meldingen die bij deze wijziging horen: tussen deze en de volgende wijziging
            $ch['alerts'] = q("SELECT id, kind, status, title, created_at, sent_ok, email_ok, webhook_ok FROM alerts
                               WHERE check_id = ? AND created_at >= ?" . ($end ? ' AND created_at < ?' : '') . ' ORDER BY created_at, id LIMIT 5',
                array_merge([$id, $ch['created_at']], $end ? [$end] : []))->fetchAll();
            $ch['ended_at'] = $end;
            $end = $ch['created_at'];
        }
        unset($ch);
        out(['check' => $c, 'uptime' => ['d1' => $pct($up['ok1'], $up['n1']), 'd7' => $pct($up['ok7'], $up['n7'])],
             'changes' => $changes, 'now' => now(), 'smart' => Smart::info($c)]);

    case 'history':
        $id = (int) ($_GET['check_id'] ?? 0);
        $hours = min(24 * 30, max(1, (int) ($_GET['hours'] ?? 24)));
        $rows = q('SELECT UNIX_TIMESTAMP(created_at) AS t, status AS s, value AS v, vals AS x FROM results
                   WHERE check_id = ? AND created_at >= NOW() - INTERVAL ? HOUR ORDER BY created_at', [$id, $hours])->fetchAll();
        // Downsamplen naar max ~300 punten
        $step = max(1, (int) ceil(count($rows) / 300));
        $pts = [];
        for ($i = 0; $i < count($rows); $i += $step) {
            $slice = array_slice($rows, $i, $step);
            $vals = array_filter(array_column($slice, 'v'), fn($v) => $v !== null);
            // Container: per meting (CPU, RAM, disk) het gemiddelde
            $x = [];
            foreach ($slice as $r) {
                foreach ((array) json_decode((string) $r['x'], true) as $k => $xv) {
                    if (is_numeric($xv)) {
                        $x[$k][] = (float) $xv;
                    }
                }
            }
            $pts[] = [
                't' => (int) $slice[0]['t'],
                's' => max(array_map('intval', array_column($slice, 's'))),
                'v' => $vals ? array_sum($vals) / count($vals) : null,
            ] + ($x ? ['x' => array_map(fn($a) => round(array_sum($a) / count($a), 2), $x)] : []);
        }
        $up = $rows ? count(array_filter($rows, fn($r) => (int) $r['s'] !== STATUS_CRIT)) / count($rows) * 100 : null;
        $type = q('SELECT type, last_value, metric, options FROM checks WHERE id = ?', [$id])->fetch();
        // Container: welke metingen bewaakt worden, met eenheid en drempels (een lijn per meting)
        $series = null;
        if ($type && $type['type'] === 'container' && $type['metric'] === null) {
            $series = [];
            foreach ((array) ((json_decode((string) $type['options'], true) ?: [])['res'] ?? []) as $k => $t) {
                $series[] = ['key' => $k, 'label' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'Disk'][$k] ?? $k,
                    'unit' => $k === 'disk' ? 'MB' : '%', 'warn' => $t[0] ?? null, 'crit' => $t[1] ?? null];
            }
        }
        $forecast = $type && $type['type'] === 'disk' && $type['last_value'] !== null ? Stats::diskForecast($id, (float) $type['last_value']) : null;
        $logLines = null;
        if ($type && $type['type'] === 'log') {
            $hid = q('SELECT host_id FROM checks WHERE id = ?', [$id])->fetchColumn();
            $lr = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = ?", [$hid, "logrun:$id"])->fetchColumn(), true);
            $logLines = array_map(fn($l) => ['time' => date('Y-m-d H:i:s', (int) $l[0]), 'line' => $l[1]], $lr['lines'] ?? []);
        }
        // 1.107.0: slimme drempels: de normale band (10e–95e percentiel) en de warning op het uur van elk punt
        $band = null;
        if ($type && ($m = Smart::model($id)) && isset($m['w']) && (int) ($m['days'] ?? 0) >= Smart::MIN_DAYS) {
            $band = array_map(static function ($p) use ($m) {
                $h = Smart::how($p['t']);
                return [$m['lo'][$h], $m['hi'][$h], $m['w'][$h], $m['c'][$h]];
            }, $pts);
        }
        out(['points' => $pts, 'uptime' => $up, 'forecast' => $forecast, 'log_lines' => $logLines, 'series' => $series, 'band' => $band]);

    case 'stats':
        // Beschikbaarheid per server en minigrafieken per check; 2 minuten bewaard
        $cache = json_decode((string) setting('stats_cache', ''), true);
        if (!$cache || ($cache['at'] ?? 0) < time() - 120) {
            $cache = ['at' => time(), 'uptime' => Stats::uptime(), 'sparks' => Stats::sparks()];
            save_setting('stats_cache', json_encode($cache));
        }
        out($cache);

    // Taal van de interface: bij je account (op elk apparaat), en op dit apparaat (ook zonder account)
    case 'lang_set':
        $lang = (string) (input()['lang'] ?? '');
        if (!in_array($lang, I18n::LANGS, true)) {
            out(['error' => t('Onbekende taal')], 422);
        }
        if ((int) ($user['id'] ?? 0) > 0) {
            q('UPDATE users SET lang = ? WHERE id = ?', [$lang, (int) $user['id']]);
        }
        I18n::remember($lang);
        out(['ok' => true, 'lang' => $lang]);

    case 'host_save':
        $in = input();
        $hostBefore = !empty($in['id']) ? ChangeLog::hostRow((int) $in['id']) : null;   // wijzigingslogboek (1.97.0)
        $mode = in_array($in['mode'] ?? '', ['local', 'agent', 'remote'], true) ? $in['mode'] : 'remote';
        $name = trim((string) ($in['name'] ?? ''));
        $addr = trim((string) ($in['address'] ?? ''));
        if ($name === '' || $addr === '' || !preg_match('/^[A-Za-z0-9.:\-\[\]]+$/', $addr)) {
            out(['error' => t('Naam en geldig adres (hostname/IP) zijn verplicht')], 422);
        }
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $parent = !empty($in['parent_id']) ? (int) $in['parent_id'] : null;
        // SNMP: alleen als het formulier het meestuurt; gemaskeerde geheimen blijven zoals ze waren
        $snmp = false;
        if (array_key_exists('snmp', $in)) {
            $oldSnmp = !empty($in['id']) ? SnmpMonitor::config(q('SELECT snmp FROM hosts WHERE id = ?', [(int) $in['id']])->fetchColumn() ?: null) : null;
            $snmp = SnmpMonitor::clean($in['snmp'], $oldSnmp);
            if (is_string($snmp)) {
                out(['error' => $snmp], 422);
            }
        }
        if ($parent !== null) {
            if (!q('SELECT 1 FROM hosts WHERE id = ?', [$parent])->fetchColumn()) {
                out(['error' => t('Bovenliggende server bestaat niet')], 422);
            }
            // Geen kringen: de parent mag niet (indirect) onder deze server hangen
            for ($p = $parent, $i = 0; $p !== null && $i < 50; $i++) {
                if (!empty($in['id']) && $p === (int) $in['id']) {
                    out(['error' => t('Een server kan niet van zichzelf afhankelijk zijn (ook niet via een andere server)')], 422);
                }
                $next = q('SELECT parent_id FROM hosts WHERE id = ?', [$p])->fetchColumn();
                $p = $next ? (int) $next : null;
            }
        }
        if (!empty($in['id'])) {
            q('UPDATE hosts SET name = ?, address = ?, mode = ?, enabled = ?, parent_id = ? WHERE id = ?', [$name, $addr, $mode, $enabled, $parent, (int) $in['id']]);
            $id = (int) $in['id'];
        } else {
            q('INSERT INTO hosts (name, address, mode, enabled, parent_id) VALUES (?, ?, ?, ?, ?)', [$name, $addr, $mode, $enabled, $parent]);
            $id = (int) db()->lastInsertId();
            if (!empty($in['add_ping'])) {
                q("INSERT INTO checks (host_id, type, name, warn_threshold, crit_threshold) VALUES (?, 'ping', 'Ping', 100, 500)", [$id]);
            }
        }
        if ($mode === 'agent') {
            q('UPDATE hosts SET agent_token = COALESCE(agent_token, ?) WHERE id = ?', [bin2hex(random_bytes(20)), $id]);
        }
        if ($snmp !== false) {
            q('UPDATE hosts SET snmp = ? WHERE id = ?', [$snmp ? json_encode($snmp, JSON_UNESCAPED_UNICODE) : null, $id]);
        }
        // Soort apparaat: leeg = vanzelf (herkend), anders zelf gekozen
        if (array_key_exists('kind', $in)) {
            $kind = (string) $in['kind'];
            q('UPDATE hosts SET kind = ? WHERE id = ?', [isset(HostKind::KINDS[$kind]) ? $kind : null, $id]);
        }
        if (array_key_exists('kind_auto', $in) && isset(HostKind::KINDS[(string) $in['kind_auto']])) {
            HostKind::setAuto($id, (string) $in['kind_auto']);   // herkend bij 🔍 Test SNMP in het formulier
        }
        // IDS: extra bestanden bewaken op deze server
        if (array_key_exists('ids_fim', $in)) {
            $fimOwn = Ids::cleanFim($in['ids_fim']);
            q('UPDATE hosts SET ids_fim = ? WHERE id = ?', [$fimOwn ? json_encode($fimOwn, JSON_UNESCAPED_SLASHES) : null, $id]);
        }
        ChangeLog::record('host', $id, $hostBefore ? 'update' : 'create', $name, $hostBefore, ChangeLog::hostRow($id));
        out(['ok' => true, 'id' => $id]);

    // SNMP testen met de instellingen uit het formulier (nog niet opgeslagen; gemaskeerde geheimen van de server zelf)
    case 'snmp_test':
        $in = input();
        $old = !empty($in['id']) ? SnmpMonitor::config(q('SELECT snmp FROM hosts WHERE id = ?', [(int) $in['id']])->fetchColumn() ?: null) : null;
        $cfg = SnmpMonitor::clean(['on' => 1] + (array) ($in['snmp'] ?? []), $old);
        $addr = trim((string) ($in['address'] ?? ''));
        if (is_string($cfg) || $cfg === null) {
            out(['error' => $cfg ?: t('Vul de SNMP-instellingen in')], 422);
        }
        if ($addr === '' || !preg_match('/^[A-Za-z0-9.:\-\[\]]+$/', $addr)) {
            out(['error' => t('Vul eerst het adres van de server in')], 422);
        }
        try {
            $sys = SnmpMonitor::test($cfg, $addr);
            if (!empty($in['id'])) {
                HostKind::setAuto((int) $in['id'], $sys['kind']);
            }
            out(['ok' => true, 'sys' => $sys]);
        } catch (Throwable $e) {
            out(['error' => $e->getMessage()], 422);
        }

    // Wat er via SNMP op deze server te bewaken valt (＋ SNMP-checks)
    case 'snmp_discover':
        $h = q('SELECT address, snmp FROM hosts WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        $cfg = $h ? SnmpMonitor::config($h['snmp']) : null;
        if (!$cfg) {
            out(['error' => t('SNMP staat uit voor deze server (✎ Server bewerken → 📡 SNMP)')], 422);
        }
        try {
            $d = SnmpMonitor::discover($cfg, (string) $h['address']);
            HostKind::setAuto((int) ($_GET['id'] ?? 0), $d['sys']['kind']);
            out($d);
        } catch (Throwable $e) {
            out(['error' => $e->getMessage()], 422);
        }

    // Realtime-watcher voor deze server aan of uit (standaard uit)
    case 'host_realtime':
        $in = input();
        q('UPDATE hosts SET realtime = ? WHERE id = ?', [!empty($in['on']) ? 1 : 0, (int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], 'Realtime', (!empty($in['on']) ? 'aan' : 'uit') . ' voor server ' . (int) ($in['id'] ?? 0));
        out(['ok' => true]);

    // 🐕 Waakhond (1.107.0): deze agent bewaakt RzMon (Pushover als RzMon onbereikbaar is of de checks stilstaan)
    case 'host_watchdog':
        $in = input();
        $hid = (int) ($in['id'] ?? 0);
        $before = ChangeLog::hostRow($hid);
        q('UPDATE hosts SET watchdog = ?, watchdog_min = ? WHERE id = ? AND mode = ?', [!empty($in['on']) ? 1 : 0, max(2, min(120, (int) ($in['min'] ?? 5))), $hid, 'agent']);
        if ($before) {
            ChangeLog::record('host', $hid, 'update', $before['name'] . ' (🐕 ' . t('waakhond') . ')', $before, ChangeLog::hostRow($hid));
        }
        out(['ok' => true]);

    // Info-melding bij agent-updates van deze server (volgt verder de algemene info-regel)
    // ---------- Beveiliging: versleuteling van de agents en blokkades (alleen beheerders) ----------
    // Instellingen → Hoe staat het ervoor?: punten om na te lopen, elk met het onderdeel waar je het regelt
    case 'connections':   // 1.91.0: Instellingen → Overzicht → Verbindingen en taken
        out(['inbound' => Tasks::inbound(), 'tasks' => Tasks::list(), 'now' => now()]);

    case 'settings_health':
        $items = [];
        // $extra (1.78.0): act + label (knop om het meteen te regelen) of code (om te kopiëren)
        $add = static function (string $key, string $st, string $title, string $detail, string $section, array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'status' => $st, 'title' => $title, 'detail' => $detail, 'section' => $section] + $extra;
        };
        $po = setting('pushover_enabled', '0') === '1' && setting('pushover_user', '') !== '' && setting('pushover_token', '') !== '';
        $mail = setting('email_enabled', '0') === '1' && setting('smtp_host', '') !== '' && setting('mail_to', '') !== '';
        $add('channels', $po || $mail ? 'ok' : 'bad', t('Meldkanaal'), $po || $mail
            ? implode(' + ', array_filter([$po ? 'Pushover' : '', $mail ? t('e-mail') : '']))
            : t('Pushover en e-mail staan uit: storingen zie je alleen op het dashboard'), 'channels');
        $last = Backup::list()[0]['at'] ?? '';
        $age = $last !== '' ? (time() - strtotime($last)) / 86400 : null;
        $auto = setting('backup_freq', 'off') !== 'off';
        $add('backup', $auto && $age !== null && $age < 8 ? 'ok' : 'warn', t('Back-up'),
            ($auto ? t('automatisch') : t('niet automatisch')) . ' · ' . ($last !== '' ? t('laatste: {d}', ['d' => $last]) : t('nog geen back-up')), 'backup');
        // Accounts die alleen lokaal mogen inloggen (bv. een noodaccount) tellen niet mee: van buiten kan daar niemand mee in
        $no2fa = q("SELECT username FROM users WHERE role = 'admin' AND local_only = 0 AND (totp_secret IS NULL OR totp_secret = '') ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
        $local = q("SELECT username FROM users WHERE role = 'admin' AND local_only = 1 AND (totp_secret IS NULL OR totp_secret = '') ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
        $admins = (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($admins > 0) {
            $add('2fa', $no2fa ? 'warn' : 'ok', t('Tweestapsverificatie'), ($no2fa
                ? t('beheerders zonder 2FA: {names}', ['names' => implode(', ', $no2fa)]) : t('alle beheerders hebben 2FA'))
                . ($local ? ' · ' . t('alleen lokaal, zonder 2FA: {names}', ['names' => implode(', ', $local)]) : ''), 'users');
        }
        $locks = setting('login_lock_account', '1') === '1' || setting('login_lock_ip', '1') === '1';
        $add('lock', $locks ? 'ok' : 'warn', t('Inlogbeveiliging'), $locks
            ? t('blokkeren na {n} mislukte pogingen', ['n' => (int) setting('login_lock_fails', '3')]) : t('staat uit: wachtwoorden raden wordt niet afgeremd'), 'access');
        $cache = json_decode((string) setting('update_cache', ''), true) ?: [];
        $newer = !empty($cache['latest']) && version_compare($cache['latest'], RZMON_VERSION, '>');
        $add('update', $newer ? 'warn' : 'ok', t('RzMon-versie'), $newer
            ? t('{v} staat klaar (je hebt {cur})', ['v' => $cache['latest'], 'cur' => RZMON_VERSION]) : t('{cur} is de nieuwste', ['cur' => RZMON_VERSION]), 'update');
        $agents = q("SELECT name, agent_version, enc, enc_seen, agent_key_seen, agent_ip_new FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL")->fetchAll();
        if ($agents) {
            $old = array_column(array_filter($agents, static fn($h) => $h['agent_version'] && version_compare($h['agent_version'], AGENT_LATEST, '<')), 'name');
            $add('agents', $old ? 'warn' : 'ok', t('Agents bijgewerkt'), $old
                ? t('{n} van {t} op een oudere versie: {names}', ['n' => count($old), 't' => count($agents), 'names' => implode(', ', array_slice($old, 0, 5)) . (count($old) > 5 ? ' …' : '')])
                : t('alle {t} op {v}', ['t' => count($agents), 'v' => AGENT_LATEST]), 'agents');
            $plain = array_column(array_filter($agents, static fn($h) => (int) $h['enc_seen'] !== 1), 'name');
            $off = AgentCrypto::key() === '' || AgentCrypto::globalMode() === 'off';
            $add('enc', $off ? 'warn' : ($plain ? 'warn' : 'ok'), t('Versleuteling van de agents'), $off
                ? t('staat uit of er is nog geen sleutel') : ($plain
                    ? t('{n} onversleuteld: {names}', ['n' => count($plain), 'names' => implode(', ', array_slice($plain, 0, 5)) . (count($plain) > 5 ? ' …' : '')])
                    : t('alle agents versleuteld')), 'enc');
            $nokey = array_column(array_filter($agents, static fn($h) => (int) $h['agent_key_seen'] !== 1), 'name');
            $add('ownkey', $nokey ? 'warn' : 'ok', t('Eigen sleutel per agent'), $nokey
                ? t('{n} nog zonder eigen sleutel: {names} (agent {v} nodig; anders opnieuw koppelen)', ['n' => count($nokey), 'v' => AgentCrypto::OWNKEY_MIN,
                    'names' => implode(', ', array_slice($nokey, 0, 5)) . (count($nokey) > 5 ? ' …' : '')])
                : t('alle agents hebben een eigen sleutel'), 'enc');
            // Verbinding (1.72.0): via http, of via het reserve-adres
            $conn = q("SELECT name, agent_conn, agent_fb_at FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL")->fetchAll();
            $viaFb = array_column(array_filter($conn, static fn($h) => !empty($h['agent_fb_at'])), 'name');
            $viaHttp = array_column(array_filter($conn, static fn($h) => $h['agent_conn'] === 'http' && empty($h['agent_fb_at'])), 'name');
            $add('agent_https', $viaFb ? 'bad' : ($viaHttp ? 'warn' : 'ok'), t('Agents via https'), $viaFb
                ? t('via het reserve-adres: {names} (werkt de reverse proxy?)', ['names' => implode(', ', $viaFb)])
                : ($viaHttp ? t('{n} nog via http: {names}', ['n' => count($viaHttp), 'names' => implode(', ', array_slice($viaHttp, 0, 5)) . (count($viaHttp) > 5 ? ' …' : '')])
                    : t('alle agents via https')), 'enc');
            // Alle agents al via https, maar "alleen via https" staat nog uit (1.78.0): voorstellen om het aan te zetten
            if (!$viaFb && !$viaHttp && setting('agent_https_only', '0') !== '1') {
                $add('agent_https_only', 'warn', t('Agents alleen via https'),
                    t('alle agents verbinden al via https; zet "alleen via https" aan, dan weigert RzMon rapporten die via http komen'), 'enc',
                    ['act' => 'agent_https_only', 'label' => t('Aanzetten')]);
            }
            // Verbindingscontrole (1.100.0): overbodige instellingen, gewijzigd script, tokens; en alleen agents met eigen sleutel
            $audit = AgentAudit::all();
            $viaLabel = t('Via https');   // heeft hieronder een eigen punt
            foreach ($audit as &$a) {
                $a['issues'] = array_values(array_diff($a['issues'], [$viaLabel]));
            }
            unset($a);
            $issues = array_filter($audit, static fn($a) => (bool) $a['issues']);
            $fix = array_filter($audit, static fn($a) => $a['fixable']);
            $add('agent_audit', $issues ? 'warn' : 'ok', t('Verbindingscontrole agents'), $issues
                ? implode(' · ', array_map(static fn($a) => $a['name'] . ': ' . implode(', ', $a['issues']), array_slice($issues, 0, 4))) . (count($issues) > 4 ? ' …' : '')
                : t('alle {t} agents in orde (sleutel, versleuteling, versie, instellingen, script)', ['t' => count($audit)]), 'agents',
                $fix ? ['act' => 'agents_cleanup', 'label' => '🧹 ' . t('Alles opruimen')] : []);
            if (!$nokey && setting('agent_ownkey_only', '0') !== '1') {
                $add('agent_ownkey_only', 'warn', t('Alleen agents met een eigen sleutel'),
                    t('alle agents hebben een eigen sleutel; zet dit aan, dan weigert RzMon agents die zich met alleen een token melden (koppelen met een koppelcode blijft kunnen)'), 'enc',
                    ['act' => 'agent_ownkey_only', 'label' => t('Aanzetten')]);
            }
            $newip = array_column(array_filter($agents, static fn($h) => !empty($h['agent_ip_new'])), 'name');
            if ($newip) {
                $add('agent_ip', 'bad', t('Agent van een nieuw IP-adres'), t('geweigerd, wacht op je toestemming: {names}', ['names' => implode(', ', $newip)]), 'enc');
            }
        }
        // Beheer via https (1.68.0)
        $hs = json_decode((string) setting('https_state', ''), true) ?: [];
        $adminUrl = Https::adminUrl();
        $add('https', Https::on() ? 'ok' : 'warn', t('Beheer via https'), Https::on()
            ? t('alleen via https') . ' · HSTS ' . (Https::hstsFullAt() ? t('1 dag (testfase tot {d})', ['d' => date('d-m', (int) Https::hstsFullAt())]) : t('1 jaar'))
            : ($adminUrl === '' ? t('geen beheeradres ingesteld: de beheerpagina is via http bereikbaar') : t('de beheerpagina is ook via http bereikbaar')), 'https');
        if ($adminUrl !== '' && isset($hs['admin'])) {
            $c = $hs['admin'];
            $add('cert', $hs['problem'] ?? null ? 'bad' : ($c['days'] <= Https::WARN_DAYS ? 'warn' : 'ok'), t('Certificaat'), ($hs['problem'] ?? null)
                ? ucfirst((string) $hs['problem']) : ($c['days'] <= Https::WARN_DAYS ? t('verloopt over {n} dagen ({d})', ['n' => $c['days'], 'd' => date('d-m-Y', $c['to'])])
                    : t('geldig tot {d} ({issuer})', ['d' => date('d-m-Y', $c['to']), 'issuer' => $c['issuer']])), 'https');
        }
        if ($adminUrl !== '' && isset($hs['dns'])) {
            $ips = implode(', ', (array) $hs['dns']['ips']);
            $add('dns', $hs['dns']['private'] ? 'ok' : 'warn', t('Lokale DNS-rewrite'), !$hs['dns']['ips']
                ? t('{host} is vanaf de RzMon-server niet te vinden', ['host' => Https::adminHost()])
                : ($hs['dns']['private'] ? t('{host} wijst intern naar {ip}', ['host' => Https::adminHost(), 'ip' => $ips])
                    : t('{host} wijst naar {ip} (openbaar): zet een DNS-rewrite naar het LAN-IP van je proxy', ['host' => Https::adminHost(), 'ip' => $ips])), 'https');
        }
        // De proxy stuurt door via http (1.78.0): het laatste stuk (proxy → RzMon) is dan onversleuteld
        $leg = json_decode((string) setting('proxy_http_leg', ''), true) ?: [];
        $legOk = (int) ((json_decode((string) setting('proxy_https_leg', ''), true) ?: [])['at'] ?? 0);
        if ((int) ($leg['at'] ?? 0) > time() - 7 * 86400 && (int) $leg['at'] > $legOk) {
            $sip = Https::serverIp() ?: (OwnCert::serverIps()[0] ?? t('<IP-adres van RzMon>'));
            $add('proxy_leg', 'warn', t('Proxy → RzMon via http'),
                t('je proxy ({ip}) stuurt het verkeer via http door naar RzMon: wachtwoorden en sessies gaan het laatste stuk onversleuteld over je netwerk. Laat de proxy doorsturen naar https (voor SWAG: deze regels in rzmon.subdomain.conf)', ['ip' => $leg['ip']]),
                'https', ['code' => "set \$upstream_app $sip;\nset \$upstream_port 443;\nset \$upstream_proto https;\nproxy_pass \$upstream_proto://\$upstream_app:\$upstream_port;"]);
        }
        // Inloggen via http (1.78.0): de knop zet "Inloggen via http weigeren" aan (alleen als je nu via https zit)
        if (setting('http_login_block', '0') !== '1') {
            $add('http_login', 'warn', t('Inloggen via http'),
                t('toegestaan: wie RzMon via http opent, stuurt zijn wachtwoord leesbaar over het netwerk (ook via wifi)')
                . (Https::isHttps() ? '' : ' · ' . t('open RzMon via https om het hier met één klik te weigeren')), 'https',
                Https::isHttps() ? ['act' => 'http_login_block', 'label' => t('Weigeren')] : []);
        }
        // Tip (1.78.0): via een IP-adres geopend terwijl er een beheeradres is: overal hetzelfde adres met een DNS-rewrite
        $vh = Https::host();
        if ($adminUrl !== '' && filter_var($vh, FILTER_VALIDATE_IP) && ip_in_list($vh, PRIVATE_NETWORKS)) {
            $add('split_dns', 'info', t('Tip: overal hetzelfde adres'),
                t('je opent RzMon nu via {ip}. Laat je DNS (bv. AdGuard: Filters → DNS-herschrijvingen) {host} naar het LAN-IP van je proxy wijzen; dan gebruik je thuis en onderweg {url}, met een geldig certificaat en zonder waarschuwingen',
                    ['ip' => $vh, 'host' => Https::adminHost(), 'url' => $adminUrl]), 'https');
        }
        $pu = json_decode((string) setting('proxy_unknown', ''), true) ?: [];
        if ((int) ($pu['at'] ?? 0) > time() - 7 * 86400 && !ip_in_list((string) $pu['ip'], parse_list((string) setting('trusted_proxies', '')))) {
            $add('proxy', 'bad', t('Reverse proxy'), t('verkeer via {ip}, maar dat staat niet bij Vertrouwde proxy\'s: die bezoekers gelden als extern', ['ip' => $pu['ip']]), 'access');
        } elseif (Https::proxyNoProto()) {
            $add('proxy', 'warn', t('Reverse proxy'), t('je proxy meldt geen https (X-Forwarded-Proto ontbreekt): de sessiecookie is dan niet beveiligd'), 'https');
        } elseif (parse_list((string) setting('trusted_proxies', ''))) {
            $add('proxy', 'ok', t('Reverse proxy'), t('vertrouwd: {ips}', ['ips' => implode(', ', parse_list((string) setting('trusted_proxies', '')))]), 'access');
        }
        if ($adminUrl !== '') {
            $noMfa = q("SELECT username FROM users WHERE totp_secret IS NULL OR totp_secret = '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
            $mfaOn = setting('mfa_external', '0') === '1';
            $add('mfa_ext', !$mfaOn ? 'warn' : ($noMfa ? 'info' : 'ok'), t('2FA van buiten'), !$mfaOn
                ? t('niet verplicht: van buiten is een wachtwoord genoeg')
                : ($noMfa ? t('verplicht · zonder 2FA alleen thuis: {names}', ['names' => implode(', ', array_slice($noMfa, 0, 5)) . (count($noMfa) > 5 ? ' …' : '')]) : t('verplicht · alle gebruikers hebben 2FA')), 'https');
        }
        // Datalekken (1.69.0)
        if (Hibp::enabled()) {
            $hs = Hibp::summary();
            $add('hibp_pw', $hs['pw_users'] ? 'bad' : 'ok', t('Gelekte wachtwoorden'), $hs['pw_users']
                ? t('in een datalek: {names} (moeten bij het inloggen een nieuw kiezen)', ['names' => implode(', ', $hs['pw_users'])])
                : t('geen bekende gelekte wachtwoorden'), 'hibp');
            $add('hibp_mail', !Hibp::hasKey() ? 'info' : (setting('hibp_last_error', '') !== '' ? 'warn' : ($hs['with_passwords'] ? 'bad' : ($hs['open'] ? 'warn' : 'ok'))),
                t('Gelekte e-mailadressen'), !Hibp::hasKey() ? t('geen API-sleutel: alleen wachtwoorden worden gecontroleerd')
                : (setting('hibp_last_error', '') !== '' ? (string) setting('hibp_last_error', '')
                    : ($hs['open'] ? t('{n} open lekken op {e} adressen ({p} met wachtwoorden)', ['n' => $hs['open'], 'e' => $hs['emails'], 'p' => $hs['with_passwords']])
                        : t('{n} adressen gecontroleerd, geen open lekken', ['n' => $hs['checked']]))), 'hibp');
        }
        if (!NetScan::enabled()) {   // 1.103.0
            $add('netscan', 'info', t('Netwerkscan'), t('staat uit: zet hem aan om te zien wat er allemaal op je netwerk zit'), 'netscan');
        } elseif (($nnew = NetScan::newCount()) > 0) {
            $add('netscan', 'warn', t('Netwerkscan'), t('{n} nieuwe apparaten nog niet beoordeeld (tabblad Netwerk)', ['n' => $nnew]), 'netscan');
        } else {
            $add('netscan', 'ok', t('Netwerkscan'), t('alle apparaten beoordeeld'), 'netscan');
        }
        if (Stealth::uiLocked() && ($nreq = Stealth::requestCount()) > 0) {   // 1.102.0
            $add('device_requests', 'warn', t('Wachtende apparaten'), t('{n} apparaat/apparaten van buiten geweigerd: toestaan, negeren of blokkeren', ['n' => $nreq]), 'outside');
        }
        $add('vulns', Vulns::enabled() ? 'ok' : 'info', t('Kwetsbaarheden'), Vulns::enabled() ? t('scan staat aan') : t('scan staat uit'), 'vulns');
        $add('ids', Ids::enabled() ? 'ok' : 'info', 'IDS', Ids::enabled() ? t('staat aan') : t('staat uit'), 'ids');
        $tl = array_filter(Tasks::list(), static fn($o) => in_array($o['status'], ['bad', 'warn'], true));
        $bad = array_filter($tl, static fn($o) => $o['status'] === 'bad');
        $add('tasks', $bad ? 'bad' : ($tl ? 'warn' : 'ok'), t('Achtergrondtaken'), $tl ? implode(', ', array_column($tl, 'label')) : t('alles loopt'), 'conn');
        out(['items' => $items]);

    case 'security':
        $key = AgentCrypto::key();
        $until = (int) setting('enc_key_old_until', '0');
        out([
            'enc' => ['mode' => AgentCrypto::globalMode(), 'key' => $key, 'key_id' => $key !== '' ? AgentCrypto::keyId($key) : null,
                'old_until' => $until > time() ? date('Y-m-d H:i:s', $until) : null, 'agent_min' => AgentCrypto::AGENT_MIN],
            'agent_tick' => agent_tick(null),
            'hosts' => array_map(static fn($h) => array_diff_key($h, ['agent_beats' => 1]) + ['key_days' => $h['agent_key_at'] ? (int) floor((time() - strtotime($h['agent_key_at'])) / 86400) : null,
                'contact' => agent_contact($h)],
                q("SELECT id, name, agent_version, agent_seen, agent_tick, realtime, agent_beats, agent_ip, enc, enc_seen, agent_key_seen, agent_key_at, agent_ips, agent_ip_new, agent_ip_new_at, pin_mode, ip_lock,
                          agent_https, agent_conn, agent_fb_at, agent_url, agent_url_fb, agent_url_want, agent_url_status, agent_url_at,
                          agent_url_pin, agent_url_fb_pin, (pair_until > NOW()) AS pairing FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll()),
            'conn' => ['key_days' => (int) setting('agent_key_days', '30'), 'pin' => setting('agent_pin', '1') === '1', 'ip_lock' => setting('agent_ip_lock', '1') === '1',
                'https_only' => setting('agent_https_only', '0') === '1', 'url_min' => AgentCrypto::URL_MIN, 'base_url' => rtrim((string) config('base_url', ''), '/'),
                'pin_value' => AgentCrypto::pin(false, false, ['pin_mode' => 'on']), 'https' => Https::isHttps(),
                'ownkey_min' => AgentCrypto::OWNKEY_MIN, 'pinurl_min' => AgentCrypto::PINURL_MIN, 'admin_url' => Https::adminUrl(),
                'ownkey_only' => setting('agent_ownkey_only', '0') === '1'],
            'audit' => (object) AgentAudit::all(),
            'blocks' => Security::blocks(),
            'my_ip' => client_ip(),
            'now_ts' => time(),
        ]);

    case 'enc_mode':
        $m = (string) (input()['mode'] ?? '');
        if (!in_array($m, AgentCrypto::MODES, true)) {
            out(['error' => t('Ongeldige keuze')], 422);
        }
        save_setting('enc_mode', $m);
        AgentCommands::audit($user['username'], 'Versleuteling agents', $m);
        out(['ok' => true]);

    case 'enc_generate':
        $confirm('SLEUTEL');
        out(['ok' => true, 'key' => AgentCrypto::generate($user['username'])]);

    case 'host_enc':
        $in = input();
        $m = (string) ($in['enc'] ?? '');
        if ($m !== '' && !in_array($m, AgentCrypto::MODES, true)) {
            out(['error' => t('Ongeldige keuze')], 422);
        }
        $name = q("SELECT name FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['host_id'] ?? 0)])->fetchColumn();
        if ($name === false) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        q('UPDATE hosts SET enc = ? WHERE id = ?', [$m === '' ? null : $m, (int) $in['host_id']]);
        AgentCommands::audit($user['username'], 'Versleuteling agent', "$name: " . ($m ?: 'algemeen'));
        out(['ok' => true]);

    // Beveiligde verbinding: sleutels vernieuwen na X dagen, certificaat vastzetten, vast IP-adres.
    // Iets uitzetten maakt de verbinding minder veilig: dan om je wachtwoord vragen.
    case 'conn_save':
        $in = input();
        $days = (int) ($in['key_days'] ?? 30);
        if (!in_array($days, [0, 7, 30, 90], true)) {
            out(['error' => t('Ongeldige keuze')], 422);
        }
        $pin = !empty($in['pin']) ? '1' : '0';
        $lock = !empty($in['ip_lock']) ? '1' : '0';
        $https = array_key_exists('https_only', $in) ? (!empty($in['https_only']) ? '1' : '0') : setting('agent_https_only', '0');
        if (($pin === '0' && setting('agent_pin', '1') === '1') || ($lock === '0' && setting('agent_ip_lock', '1') === '1')
            || ($https === '0' && setting('agent_https_only', '0') === '1')) {
            $confirm('UITZETTEN');
        }
        // Alleen via https aanzetten: alleen als er geen agent zonder reserve-adres meer via http komt (anders valt hij weg)
        if ($https === '1' && setting('agent_https_only', '0') !== '1' && empty($in['force'])) {
            $plain = q("SELECT name FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_conn = 'http' AND agent_fb_at IS NULL AND (agent_https IS NULL OR agent_https <> 'off')")->fetchAll(PDO::FETCH_COLUMN);
            if ($plain) {
                out(['error' => t('Deze agents komen nog via http: {names}. Zet ze eerst over (🌐 Adres), of zet "alleen via https" per server uit.', ['names' => implode(', ', $plain)]), 'plain' => $plain], 409);
            }
        }
        // Alleen agents met een eigen sleutel (1.100.0): aan alleen als alle agents er een hebben (anders vallen ze weg)
        $ownOnly = array_key_exists('ownkey_only', $in) ? (!empty($in['ownkey_only']) ? '1' : '0') : setting('agent_ownkey_only', '0');
        if ($ownOnly === '0' && setting('agent_ownkey_only', '0') === '1') {
            $confirm('UITZETTEN');
        }
        if ($ownOnly === '1' && setting('agent_ownkey_only', '0') !== '1') {
            $nokey = q("SELECT name FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_key_seen <> 1")->fetchAll(PDO::FETCH_COLUMN);
            if ($nokey) {
                out(['error' => t('Deze agents hebben nog geen eigen sleutel: {names}. Koppel ze eerst met een koppelcode.', ['names' => implode(', ', $nokey)])], 409);
            }
        }
        foreach (['agent_key_days' => (string) $days, 'agent_pin' => $pin, 'agent_ip_lock' => $lock, 'agent_https_only' => $https, 'agent_ownkey_only' => $ownOnly] as $k => $v) {
            if (setting($k, '') !== $v) {
                save_setting($k, $v);
                AgentCommands::audit($user['username'], 'Beveiligde verbinding', "$k = $v");
            }
        }
        save_setting('agent_pin_at', '0');   // vingerafdruk opnieuw bepalen
        out(['ok' => true]);

    // Koppelcode voor een server (15 minuten geldig) en wat er nodig is voor het installatiecommando
    case 'agent_pair_code':
        $id = (int) (input()['id'] ?? 0);
        $ph = q("SELECT name, pin_mode FROM hosts WHERE id = ? AND mode = 'agent'", [$id])->fetch();
        if (!$ph) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $name = $ph['name'];
        $p = AgentCrypto::pairCreate($id);
        AgentCommands::audit($user['username'], 'Koppelcode gemaakt', (string) $name);
        out($p + ['setup_sha256' => Stealth::installSha(), 'agent_sha256' => hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh'),
            'pin' => AgentCrypto::pin(false, false, $ph) ?? '', 'minutes' => AgentCrypto::PAIR_MINUTES]);

    // Per server: certificaat vastzetten en vast IP-adres ('' = algemene instelling, on, off).
    // Uitzetten (waar het nu geldt) maakt de verbinding minder veilig: dan om je wachtwoord vragen.
    case 'host_conn':
        $in = input();
        $h = q("SELECT id, name, pin_mode, ip_lock, agent_https FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $new = $h;
        foreach (['pin_mode' => 'pin', 'ip_lock' => 'ip_lock', 'agent_https' => 'https'] as $col => $k) {
            if (array_key_exists($k, $in)) {
                $v = (string) $in[$k];
                if (!in_array($v, ['', 'on', 'off'], true)) {
                    out(['error' => t('Ongeldige keuze')], 422);
                }
                $new[$col] = $v === '' ? null : $v;
            }
        }
        if ((AgentCrypto::pinOn($h) && !AgentCrypto::pinOn($new)) || (AgentCrypto::ipLockOn($h) && !AgentCrypto::ipLockOn($new))
            || (AgentCrypto::httpsOnly($h) && !AgentCrypto::httpsOnly($new))) {
            $confirm('UITZETTEN');
        }
        q('UPDATE hosts SET pin_mode = ?, ip_lock = ?, agent_https = ? WHERE id = ?', [$new['pin_mode'], $new['ip_lock'], $new['agent_https'], $h['id']]);
        AgentCommands::audit($user['username'], 'Beveiligde verbinding per server', "{$h['name']}: vastzetten " . ($new['pin_mode'] ?? 'algemeen') . ', IP-slot ' . ($new['ip_lock'] ?? 'algemeen'));
        out(['ok' => true]);

    // Adressen om uit te kiezen (1.78.0): IP-adressen van RzMon (https met het eigen certificaat, of http), de namen die
    // daarbij horen (DNS), de hostnaam, eigen namen uit het certificaat en het beheeradres; elk met wat er op staat
    case 'agent_url_options':
        $path = rtrim((string) parse_url((string) config('base_url', ''), PHP_URL_PATH), '/');
        $cand = [];
        $ips = OwnCert::serverIps();
        if (($sip = Https::serverIp()) !== '' && !in_array($sip, $ips, true)) {
            array_unshift($ips, $sip);
        }
        foreach (array_slice($ips, 0, 4) as $ip) {
            $h = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$ip]" : $ip;
            $cand["https://$h$path"] = 'ip';
            $rev = @gethostbyaddr($ip);
            if (is_string($rev) && $rev !== $ip && OwnCert::validName($rev) && str_contains($rev, '.')) {
                $cand["https://$rev$path"] = 'dns';
            }
        }
        $hn = strtolower((string) gethostname());
        if (str_contains($hn, '.') && OwnCert::validName($hn)) {
            $cand["https://$hn$path"] = 'host';
        }
        foreach (OwnCert::extraNames() as $n) {
            if (!str_starts_with($n, '*') && !filter_var($n, FILTER_VALIDATE_IP)) {
                $cand['https://' . strtolower($n) . $path] = 'cert';
            }
        }
        if (Https::adminUrl() !== '') {
            $cand[Https::adminUrl()] = 'admin';
        }
        foreach (array_slice($ips, 0, 4) as $ip) {
            $cand['http://' . (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$ip]" : $ip) . $path] = 'ip';
        }
        $list = [];
        foreach (array_slice($cand, 0, 12, true) as $u => $src) {
            $list[] = ['url' => $u, 'source' => $src] + AgentCrypto::urlTrust($u);
        }
        out(['options' => $list, 'pinurl_min' => AgentCrypto::PINURL_MIN, 'url_min' => AgentCrypto::URL_MIN]);

    // Een adres testen (vanaf RzMon): welk certificaat staat erop?
    case 'agent_url_test':
        $u = rtrim(trim((string) (input()['url'] ?? '')), '/');
        out(['url' => $u] + AgentCrypto::urlTrust($u, true));

    // Adres van RzMon voor een agent (of alle agents) wijzigen: de agent test het nieuwe adres eerst (1.72.0)
    case 'agent_url_set':
        $in = input();
        $url = rtrim(trim((string) ($in['url'] ?? '')), '/');
        $fb = rtrim(trim((string) ($in['fallback'] ?? '')), '/');
        if (!AgentCrypto::validUrl($url)) {
            out(['error' => t('Ongeldig adres: gebruik https://naam of http://adres')], 422);
        }
        if ($fb !== '' && (!AgentCrypto::validUrl($fb) || $fb === $url)) {
            out(['error' => t('Ongeldig reserve-adres (leeg laten mag)')], 422);
        }
        // Wat staat er op die adressen (1.78.0)? Een onbekend certificaat: weigeren. Eigen certificaat: agent 1.53.0 nodig.
        $own = [];
        foreach (array_filter([$url, $fb]) as $u) {
            $tr = AgentCrypto::urlTrust($u, true);
            if ($tr['kind'] === 'other') {
                out(['error' => t('Op {url} staat een onbekend certificaat (niet van RzMon en niet vertrouwd); de agents zouden het weigeren', ['url' => $u])], 422);
            }
            if ($tr['kind'] === 'own') {
                $own[] = $u;
            }
        }
        $all = ($in['id'] ?? '') === 'all';
        $rows = q("SELECT id, name, agent_version, agent_url, agent_url_fb FROM hosts WHERE mode = 'agent'" . ($all ? '' : ' AND id = ?'), $all ? [] : [(int) ($in['id'] ?? 0)])->fetchAll();
        $n = 0;
        $skip = [];
        $min = $own ? AgentCrypto::PINURL_MIN : AgentCrypto::URL_MIN;
        foreach ($rows as $r) {
            if (!$r['agent_version'] || version_compare($r['agent_version'], $min, '<')) {
                $skip[] = $r['name'];
                continue;
            }
            // Reserve-adres: opgegeven, anders (met "keepfb") het adres dat de agent nu gebruikt
            $f = $fb !== '' ? $fb : (!empty($in['keep_fb']) && $r['agent_url'] && $r['agent_url'] !== $url ? $r['agent_url'] : '');
            q('UPDATE hosts SET agent_url_want = ?, agent_url_want_fb = ?, agent_url_status = ?, agent_url_at = ? WHERE id = ?',
                [$url, $f, '⏳ ' . t('Wacht op de agent (test {url})', ['url' => $url]), now(), $r['id']]);
            $n++;
        }
        if (!$n) {
            out(['error' => t('Agent {v} of nieuwer nodig (eerst bijwerken)', ['v' => $min])], 422);
        }
        AgentCommands::audit($user['username'], 'Adres van RzMon voor agents', ($all ? 'alle' : $rows[0]['name']) . ": $url" . ($fb !== '' ? " (reserve $fb)" : ''));
        out(['ok' => true, 'n' => $n, 'skipped' => $skip]);

    case 'agent_url_cancel':
        q('UPDATE hosts SET agent_url_want = NULL, agent_url_want_fb = NULL, agent_url_status = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'agent_key_renew':
        $id = (int) (input()['id'] ?? 0);
        $h = q("SELECT name, agent_key FROM hosts WHERE id = ? AND mode = 'agent'", [$id])->fetch();
        if (!$h || !$h['agent_key']) {
            out(['error' => t('Deze server heeft nog geen eigen sleutel')], 409);
        }
        AgentCrypto::newHostKey($id, true);
        AgentCommands::audit($user['username'], 'Sleutel van de agent vernieuwd', $h['name'] . ' (de vorige nog 24 uur geldig)');
        out(['ok' => true]);

    case 'agent_ip_allow':
        $ip = AgentCrypto::ipAllow((int) (input()['id'] ?? 0), $user['username']);
        $ip !== null ? out(['ok' => true, 'ip' => $ip]) : out(['error' => t('Er wacht geen nieuw IP-adres')], 409);

    case 'agent_ip_reset':
        $id = (int) (input()['id'] ?? 0);
        q('UPDATE hosts SET agent_ips = NULL, agent_ip_new = NULL, agent_ip_new_at = NULL WHERE id = ?', [$id]);
        AgentCommands::audit($user['username'], 'IP-adressen van de agent vergeten', (string) $id);
        out(['ok' => true]);

    // ---------- Certificaat van RzMon voor de agents (1.70.0): bekijken, downloaden, vernieuwen ----------
    case 'agent_cert':
        $base = rtrim((string) config('base_url', ''), '/');
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        $local = OwnCert::current();
        unset($local['pem']);
        $agentCert = null;
        if ($scheme === 'https') {
            $h = (string) parse_url($base, PHP_URL_HOST);
            $agentCert = Https::fetchCert($h, (int) (parse_url($base, PHP_URL_PORT) ?: 443), $h, true);
        }
        // De vingerafdruk die de agents vastzetten: alleen bij https met een certificaat dat niet al vertrouwd is
        $pin = $agentCert && $agentCert['ok'] && !$agentCert['trusted'] && !empty($agentCert['pem']) ? OwnCert::spkiPin($agentCert['pem']) : '';
        unset($agentCert['pem']);
        out([
            'base' => $base, 'scheme' => $scheme ?: null, 'agent_cert' => $agentCert, 'local' => $local,
            'own' => $agentCert && !empty($local['fp']) && $agentCert['ok'] && $agentCert['fp'] === $local['fp'],
            'pin_on' => setting('agent_pin', '1') === '1', 'pin' => $pin,
            'hosts' => q("SELECT id, name, agent_version, agent_seen, pin_mode, agent_pin_sent, agent_pin_sent_at, agent_url, agent_url_pin, agent_url_fb_pin FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll(),
            'paths' => OwnCert::paths(), 'via' => OwnCert::via(), 'cli' => OwnCert::cliCommand(),
            'names' => OwnCert::names(), 'extra' => implode("\n", OwnCert::extraNames()), 'days' => OwnCert::DAYS, 'warn_days' => Https::WARN_DAYS,
            'next' => OwnCert::next(), 'pinned' => OwnCert::pinnedAgents(), 'next_days' => OwnCert::NEXT_DAYS,
        ]);

    case 'agent_cert_names':
        $list = [];
        foreach (parse_list((string) (input()['names'] ?? '')) as $n) {
            if (!OwnCert::validName($n)) {
                out(['error' => t('Geen geldige naam of IP-adres: {n}', ['n' => mb_substr($n, 0, 100)])], 422);
            }
            $list[strtolower($n)] = true;
        }
        save_setting('cert_extra_names', implode("\n", array_keys(array_slice($list, 0, 30, true))));
        out(['ok' => true]);

    case 'agent_cert_download':
        $confirm('DOWNLOADEN');
        $c = OwnCert::current();
        if (empty($c['pem'])) {
            out(['error' => t('Geen certificaat op poort 443 van RzMon')], 404);
        }
        AgentCommands::audit($user['username'], 'Certificaat van RzMon gedownload', $c['fp'] ?? '');
        out(['ok' => true, 'pem' => $c['pem'], 'name' => (preg_replace('/[^a-z0-9.-]/i', '_', (string) gethostname()) ?: 'rzmon') . '.crt']);

    // Vernieuwen (1.78.0): met vastgezette agents eerst klaarzetten (prepare), anders meteen (renew). "Nu wisselen":
    // activate (het klaargezette certificaat) of now (meteen een nieuw, zonder te wachten op de agents).
    case 'agent_cert_renew':
    case 'agent_cert_activate':
        $in = input();
        $confirm($action === 'agent_cert_activate' ? 'WISSELEN' : 'VERNIEUWEN');
        $what = $action === 'agent_cert_activate' ? 'activate' : (!empty($in['now']) || !OwnCert::pinnedAgents() ? 'renew' : 'prepare');
        $via = OwnCert::via();
        if ($via === 'helper') {
            // Via de hulpdienst (als root): verzoek neerzetten, het resultaat komt binnen een paar seconden
            $h = Installer::HELPER_DIR;
            @unlink("$h/cert.result");
            $nonce = bin2hex(random_bytes(16));
            if (file_put_contents("$h/cert.request", json_encode(['nonce' => $nonce, 'by' => $user['username'], 'action' => $what])) === false) {
                out(['error' => t('Kan geen verzoek neerzetten in {dir}', ['dir' => $h])], 500);
            }
            $_SESSION['cert_renew'] = ['nonce' => $nonce, 'at' => time()];
            out(['ok' => true, 'pending' => true]);
        }
        if ($via === 'direct') {
            $r = $what === 'activate' ? OwnCert::activate($user['username']) : ($what === 'prepare' ? OwnCert::prepare($user['username']) : OwnCert::renew($user['username']));
            out($r, $r['ok'] ? 200 : 422);
        }
        $cmd = OwnCert::cliCommand() . ($what === 'activate' ? ' --activate' : ($what === 'renew' ? ' --now' : ''));
        out(['error' => t('Vernieuwen kan hier alleen als root. Voer op de server uit: {cmd}', ['cmd' => $cmd]), 'cli' => $cmd], 409);

    case 'agent_cert_cancel':
        if (OwnCert::next()) {
            save_setting('own_cert_next', '');
            AgentCommands::audit($user['username'], 'Klaargezet certificaat van RzMon geannuleerd', '');
        }
        out(['ok' => true]);

    case 'agent_cert_renew_status':
        $p = $_SESSION['cert_renew'] ?? null;
        if (!$p) {
            out(['error' => t('Er wordt geen certificaat vernieuwd')], 404);
        }
        $res = json_decode((string) @file_get_contents(Installer::HELPER_DIR . '/cert.result'), true);
        if ($res && hash_equals($p['nonce'], (string) ($res['nonce'] ?? ''))) {
            @unlink(Installer::HELPER_DIR . '/cert.result');
            unset($_SESSION['cert_renew'], $res['nonce']);
            out($res, $res['ok'] ? 200 : 422);
        }
        if (time() - $p['at'] > 45) {
            unset($_SESSION['cert_renew']);
            @unlink(Installer::HELPER_DIR . '/cert.request');
            out(['error' => t('De hulpdienst reageert niet. Controleer op de server: systemctl status rzmon-cert.path')], 504);
        }
        out(['pending' => true]);

    case 'block_add':
        $in = input();
        $r = Security::block((string) ($in['net'] ?? ''), (string) ($in['reason'] ?? ''), $user['username']);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 422);

    case 'block_remove':
        Security::unblock((int) (input()['id'] ?? 0), $user['username']);
        out(['ok' => true]);

    // Blokkades en whitelist (1.83.0)
    case 'blocks_overview':
        out(Security::overview());

    case 'block_lift':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $ok = match ((string) ($in['src'] ?? '')) {
            'rz' => Security::unblock($id, $user['username']),
            'user' => Security::unlockUser($id, $user['username']),
            'ids' => (static function () use ($id, $user): bool {
                $b = Ids::unblock($id, $user['username']);
                $b && AgentCommands::audit($user['username'], 'IDS: gedeblokkeerd', "{$b['ip']} op server {$b['host_id']}");
                return (bool) $b;
            })(),
            default => false,
        };
        $ok ? out(['ok' => true]) : out(['error' => t('Blokkade niet gevonden')], 404);

    case 'whitelist_add':
        $in = input();
        $confirm('WHITELIST');
        $r = Whitelist::add((string) ($in['kind'] ?? ''), (string) ($in['value'] ?? ''), (int) ($in['days'] ?? 0), (string) ($in['reason'] ?? ''), $user['username']);
        isset($r['error']) ? out($r, 422) : out($r);

    case 'whitelist_remove':
        Whitelist::remove((int) (input()['id'] ?? 0), $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'host_info_agent':
        $in = input();
        q('UPDATE hosts SET info_agent = ? WHERE id = ?', [!empty($in['on']) ? 1 : 0, (int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], 'Info bij agent-updates', (!empty($in['on']) ? 'aan' : 'uit') . ' voor server ' . (int) ($in['id'] ?? 0));
        out(['ok' => true]);

    // Hoe vaak de agent van deze server rapporteert (0 = algemene instelling)
    case 'host_tick':
        $in = input();
        $t = (int) ($in['tick'] ?? 0);
        if ($t !== 0 && !in_array($t, AGENT_TICKS, true)) {
            out(['error' => t('Kies 30, 60, 120 of 300 seconden')], 422);
        }
        q('UPDATE hosts SET agent_tick = ? WHERE id = ?', [$t ?: null, (int) ($in['id'] ?? 0)]);
        out(['ok' => true, 'tick' => agent_tick($t ?: null)]);

    case 'agent_services':
        // Services op een agent-server, om bij "Check toevoegen" uit te kiezen
        $v = q("SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = 'svclist'", [(int) ($_GET['id'] ?? 0)])->fetch();
        out(['list' => $v ? json_decode($v['value'], true) : null, 'updated' => $v['updated_at'] ?? null]);

    case 'agent_update':
        // Eén server (id) of alle verouderde agents (all): bij het volgende rapport werkt de agent zichzelf bij
        $in = input();
        if (!empty($in['all'])) {
            $ids = [];
            foreach (q("SELECT id, agent_version FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL") as $h) {
                if ($h['agent_version'] === null || version_compare($h['agent_version'], AGENT_LATEST, '<')) {
                    $ids[] = (int) $h['id'];
                }
            }
        } else {
            $ids = q("SELECT id FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['id'] ?? 0)])->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                out(['error' => t('Geen agent-server gevonden')], 404);
            }
        }
        foreach ($ids as $id) {
            q("UPDATE hosts SET agent_update_req = ?, agent_update_sent = NULL, agent_update_status = '⏳ Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport' WHERE id = ?", [now(), $id]);
        }
        out(['ok' => true, 'count' => count($ids)]);

    // 🧹 Opruimen (1.100.0): één server (id) of alle agents. Tokens en (bij alle) de algemene sleutel wissen, agents opnieuw
    // laten installeren met een reservekopie en opgeruimde instellingen
    case 'agents_cleanup':
        $in = input();
        $hid = !empty($in['id']) ? (int) $in['id'] : null;
        if ($hid === null) {
            $confirm('OPRUIMEN');
        }
        $r = AgentAudit::cleanup($hid);
        AgentCommands::audit($user['username'], 'Agents opruimen', ($hid ? "server $hid" : 'alle') . ': ' . json_encode($r));
        ChangeLog::record('system', $hid, 'info', t('Agents opgeruimd'), null, $r);
        out(['ok' => true] + $r);

    case 'agent_update_cancel':
        q('UPDATE hosts SET agent_update_req = NULL, agent_update_sent = NULL, agent_update_status = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'agent_status':
        $id = (int) ($_GET['id'] ?? 0);
        $h = q('SELECT agent_seen, agent_ip, agent_version, mode, agent_update_req, agent_update_status, agent_update_via, realtime, watchdog, watchdog_min, enc_seen FROM hosts WHERE id = ?', [$id])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        // Geweigerde pogingen (laatste 24 uur, en ná het laatste geslaagde rapport): voor deze
        // server of met een onbekend token. Gelijke pogingen samengevoegd met een aantal.
        $fails = q('SELECT ip, reason, MAX(created_at) AS created_at, COUNT(*) AS n FROM agent_log
                    WHERE (host_id = ? OR host_id IS NULL) AND created_at >= NOW() - INTERVAL 1 DAY
                      AND (? IS NULL OR created_at > ?)
                    GROUP BY ip, reason ORDER BY MAX(id) DESC LIMIT 6', [$id, $h['agent_seen'], $h['agent_seen']])->fetchAll();
        out(['seen' => $h['agent_seen'], 'ip' => $h['agent_ip'], 'mode' => $h['mode'], 'failures' => $fails, 'now' => now(),
             'agent_version' => $h['agent_version'], 'version' => AGENT_LATEST,
             'update' => ['pending' => $h['agent_update_req'] !== null, 'status' => $h['agent_update_status'], 'via' => $h['agent_update_via']],
             'audit' => AgentAudit::items(q('SELECT ' . AgentAudit::HOST_COLS . ' FROM hosts WHERE id = ?', [$id])->fetch() ?: [], AgentAudit::cfg($id)),
             'report_warn' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'report_warn'", [$id])->fetchColumn(), true),
             'realtime' => ['on' => (bool) $h['realtime'], 'status' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'realtime'", [$id])->fetchColumn(), true),
                 'tools' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'tools'", [$id])->fetchColumn(), true)],
             'watchdog' => ['on' => (bool) $h['watchdog'], 'min' => (int) ($h['watchdog_min'] ?: 5), 'enc' => (bool) $h['enc_seen'],
                 'pushover' => setting('pushover_enabled', '0') === '1' && setting('pushover_user', '') !== '' && setting('pushover_token', '') !== ''],
             'heal_cmds' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'heal_cmds'", [$id])->fetchColumn(), true) ?: []]);

    case 'podman':
        // Overzicht van alle containers die de agent van deze server meldt
        $id = (int) ($_GET['id'] ?? 0);
        $sum = q("SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = 'podman'", [$id])->fetch();
        if (!$sum) {
            out(['error' => t('Deze server meldt (nog) geen Podman')], 404);
        }
        $containers = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'container:%' ORDER BY metric", [$id]) as $r) {
            $containers[] = ['key' => substr($r['metric'], 10)] + (json_decode($r['value'], true) ?: []);
        }
        $summary = json_decode($sum['value'], true) ?: [];
        unset($summary['containers']);
        out(['summary' => $summary, 'updated_at' => $sum['updated_at'], 'containers' => $containers,
             'checks' => q("SELECT id, type, target, metric, options FROM checks WHERE host_id = ? AND type IN ('podman', 'container')", [$id])->fetchAll()]);

    case 'host_token':
        q('UPDATE hosts SET agent_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), (int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'host_delete':
        $id = (int) (input()['id'] ?? 0);
        // Afhankelijke servers hangen daarna aan de parent van de verwijderde server
        $grand = q('SELECT parent_id FROM hosts WHERE id = ?', [$id])->fetchColumn() ?: null;
        q('UPDATE hosts SET parent_id = ? WHERE parent_id = ?', [$grand, $id]);
        q('UPDATE checks SET suppressed_by = NULL WHERE suppressed_by = ?', [$id]);
        if ($row = ChangeLog::hostRow($id, true)) {
            ChangeLog::record('host', $id, 'delete', (string) $row['name'], $row, null);
        }
        q('DELETE FROM hosts WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'ack':
        $in = input();
        if (!Monitor::acknowledge((int) ($in['id'] ?? 0), $user['username'], (string) ($in['comment'] ?? ''))) {
            out(['error' => t('Deze check heeft geen storing (meer)')], 422);
        }
        out(['ok' => true]);

    case 'ack_host':
        // Alle onbevestigde storingen van een server in één keer
        $in = input();
        $n = 0;
        foreach (q('SELECT id FROM checks WHERE host_id = ? AND enabled = 1 AND status <> 0 AND acked_at IS NULL', [(int) ($in['id'] ?? 0)])->fetchAll() as $c) {
            $n += Monitor::acknowledge((int) $c['id'], $user['username'], (string) ($in['comment'] ?? '')) ? 1 : 0;
        }
        out(['ok' => true, 'count' => $n]);

    case 'unack':
        Monitor::unacknowledge((int) (input()['id'] ?? 0));
        out(['ok' => true]);

    case 'maintenance_set':
        // scope: all | host | check ; minutes = 0 beëindigt het onderhoud
        $in = input();
        $scope = (string) ($in['scope'] ?? '');
        $minutes = (int) ($in['minutes'] ?? 0);
        if (!empty($in['until'])) {
            $ts = strtotime((string) $in['until']);
            if (!$ts || $ts <= time()) {
                out(['error' => t('Kies een eindtijd in de toekomst')], 422);
            }
            $minutes = (int) ceil(($ts - time()) / 60);
        }
        if ($minutes < 0 || $minutes > 60 * 24 * 90) {
            out(['error' => t('Onderhoud kan maximaal 90 dagen duren')], 422);
        }
        $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        $reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 200) ?: null;
        $id = (int) ($in['id'] ?? 0);
        match ($scope) {
            'all' => [save_setting('maintenance_until', (string) $until), save_setting('maintenance_reason', (string) $reason)],
            'host' => q('UPDATE hosts SET maintenance_until = ?, maintenance_reason = ? WHERE id = ?', [$until, $reason, $id]),
            // Handmatig ingesteld of beëindigd: niet (meer) automatisch
            'check' => q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 0 WHERE id = ?', [$until, $reason, $id]),
            default => out(['error' => t('Onbekend bereik')], 422),
        };
        out(['ok' => true, 'until' => $until]);

    case 'check_save':
        $in = input();
        [$cols, $hbNew] = $checkCols($in);
        $checkBefore = !empty($in['id']) ? ChangeLog::checkRow((int) $in['id']) : null;
        if (!empty($in['id'])) {
            // Uit een sjabloon (1.94.0): met de hand aangepast = los van het sjabloon, tot "terug naar het sjabloon"
            Templates::updateCheck((int) $in['id'], $cols, true);
            $id = (int) $in['id'];
        } else {
            $id = Templates::insertCheck((int) ($in['host_id'] ?? 0), $cols);
        }
        Smart::saveForm($id, $in);   // 1.107.0: drempelmodus, vangnet en gevoeligheid
        if (array_key_exists('heal', $in)) {   // 1.107.0: zelf herstellen (alleen op een server met de agent)
            $hc = q('SELECT c.type, h.mode FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
            q('UPDATE checks SET heal = ?, heal_state = NULL WHERE id = ?', [$hc && $hc['mode'] === 'agent' ? Heal::clean($hc, $in['heal']) : null, $id]);
        }
        if ($cols['type'] === 'heartbeat') {
            // Geheime URL: bij het aanmaken, of nieuw op verzoek (de oude werkt dan meteen niet meer)
            q('UPDATE checks SET hb_token = ? WHERE id = ? AND (hb_token IS NULL OR ?)', [Heartbeat::newToken(), $id, $hbNew ? 1 : 0]);
        }
        $checkAfter = ChangeLog::checkRow($id);
        ChangeLog::record('check', $id, $checkBefore ? 'update' : 'create', ($checkAfter['_host'] ?? '') . ' · ' . $cols['name'], $checkBefore, $checkAfter);
        Monitor::runCheckById($id);
        out(['ok' => true, 'id' => $id]);

    // ---------------------------------------------------------------- Sjablonen en meerdere checks tegelijk (1.94.0)
    case 'templates':
        out(['templates' => Templates::list(), 'hosts' => q('SELECT id, name, address, mode FROM hosts ORDER BY name')->fetchAll()]);

    case 'template_save':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon opgeslagen') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        try {
            out(['ok' => true, 'id' => Templates::save((int) ($in['id'] ?? 0) ?: null, (string) ($in['name'] ?? ''), (string) ($in['description'] ?? ''))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'template_delete':
        $in = input();
        if (empty($in['keep'])) {
            $confirm('VERWIJDEREN');   // de checks op de servers gaan mee weg
        }
        $tplName = (string) q('SELECT name FROM check_templates WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn();
        Templates::delete((int) ($in['id'] ?? 0), !empty($in['keep']));
        ChangeLog::record('template', (int) ($in['id'] ?? 0) ?: null, 'info', t('Sjabloon verwijderd') . ": $tplName", null, ['keep' => !empty($in['keep'])]);
        out(['ok' => true]);

    case 'template_item_save':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Check in sjabloon opgeslagen') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        $tid = (int) ($in['template_id'] ?? 0);
        if (!q('SELECT 1 FROM check_templates WHERE id = ?', [$tid])->fetchColumn()) {
            out(['error' => t('Sjabloon bestaat niet')], 404);
        }
        [$cols] = $checkCols(['host_id' => 0, 'id' => 0] + $in);
        out(['ok' => true, 'id' => Templates::itemSave($tid, (int) ($in['item_id'] ?? 0) ?: null, $cols)]);

    case 'template_item_delete':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Check uit sjabloon gehaald') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        Templates::itemDelete((int) ($in['id'] ?? 0), !empty($in['keep']));
        out(['ok' => true]);

    case 'template_link':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon gekoppeld') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        try {
            out(['ok' => true] + Templates::link((int) ($in['host_id'] ?? 0), (int) ($in['template_id'] ?? 0)));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'template_unlink':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon losgekoppeld') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        Templates::unlink((int) ($in['host_id'] ?? 0), (int) ($in['template_id'] ?? 0), !empty($in['keep']));
        out(['ok' => true]);

    case 'template_from_host':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon gemaakt van een server') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        try {
            out(['ok' => true, 'id' => Templates::fromHost((int) ($in['host_id'] ?? 0), (string) ($in['name'] ?? ''), array_map('intval', (array) ($in['check_ids'] ?? [])))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'check_tpl_reset':
        try {
            Templates::reset((int) (input()['id'] ?? 0));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        out(['ok' => true]);

    case 'discover':   // 1.95.0: wat er op deze server nog niet bewaakt wordt
        $host = q('SELECT * FROM hosts WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$host) {
            out(['error' => t('Server bestaat niet')], 404);
        }
        $ign = (array) (json_decode((string) $host['discover_ignore'], true) ?: []);
        out(['items' => Discover::forHost($host), 'ignored' => count($ign)]);

    case 'discover_ignore':
        $in = input();
        Discover::ignore((int) ($in['host_id'] ?? 0), (array) ($in['keys'] ?? []), empty($in['undo']));
        out(['ok' => true]);

    // 1.98.0: certificaatbestanden en processen die de agent op de server vond (voor de keuzelijst in het checkformulier)
    case 'agent_certs':
    case 'agent_procs':
        $hid = (int) ($_GET['id'] ?? 0);
        $host = q('SELECT id, mode, agent_version FROM hosts WHERE id = ?', [$hid])->fetch();
        if (!$host) {
            out(['error' => t('Server bestaat niet')], 404);
        }
        $metric = $action === 'agent_certs' ? 'certfound' : 'proclist';
        $row = q('SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = ?', [$hid, $metric])->fetch();
        $items = $row ? (array) (json_decode((string) $row['value'], true) ?: []) : [];
        if ($action === 'agent_certs') {
            $have = array_flip(q("SELECT target FROM checks WHERE host_id = ? AND type = 'ssl'", [$hid])->fetchAll(PDO::FETCH_COLUMN));
            foreach ($items as &$it) {
                $it['monitored'] = isset($have[$it['path'] ?? '']);
            }
            unset($it);
        } elseif ($host['mode'] === 'local') {
            $items = Checks::localProcList();
        }
        out(['items' => $items, 'at' => $row['updated_at'] ?? null, 'mode' => $host['mode'], 'agent_version' => $host['agent_version'],
            'pending' => $action === 'agent_certs' ? (int) q('SELECT certscan_req FROM hosts WHERE id = ?', [$hid])->fetchColumn() : 0]);

    case 'certscan':   // 1.98.0: de agent zoekt bij zijn volgende rapport opnieuw naar certificaatbestanden
        $hid = (int) (input()['host_id'] ?? 0);
        q('UPDATE hosts SET certscan_req = certscan_req + 1 WHERE id = ?', [$hid]);
        out(['ok' => true]);

    // ---------------------------------------------------------------- Wijzigingslogboek, export/import, meldketen (1.97.0)
    case 'changelog':
        out(['items' => ChangeLog::list(preg_replace('/[^a-z]/', '', (string) ($_GET['kind'] ?? ''))), 'days' => (int) setting('changelog_days', '90')]);

    case 'changelog_days':
        $d = (int) (input()['days'] ?? 90);
        save_setting('changelog_days', (string) (in_array($d, [30, 90, 365, 1095], true) ? $d : 90));
        out(['ok' => true]);

    case 'changelog_undo':
        try {
            out(['ok' => true, 'message' => ChangeLog::undo((int) (input()['id'] ?? 0))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'export':
        $data = Transfer::export(($_GET['settings'] ?? '') === '1');
        ChangeLog::record('system', null, 'info', t('Geëxporteerd'), null, ['servers' => count($data['hosts']), 'checks' => count($data['checks'])]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rzmon-export-' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    case 'import':
        $in = input();
        $apply = !empty($in['apply']);
        if ($apply) {
            $confirm('IMPORTEREN');
        }
        try {
            $n = Transfer::import((array) ($in['data'] ?? []), !empty($in['settings']), $apply);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        if ($apply) {
            ChangeLog::record('system', null, 'info', t('Geïmporteerd'), null, $n);
        }
        out(['ok' => true, 'counts' => $n, 'applied' => $apply]);

    case 'chain_test':
        // Eén testmelding door alle kanalen die aan staan, met de opgeslagen instellingen
        session_write_close();
        $res = [];
        $title = '🧪 RzMon: ' . t('test van de meldketen');
        $body = t('Als je dit ziet, werkt dit kanaal. Verstuurd op {time}.', ['time' => date('d-m-Y H:i:s')]);
        $res['pushover'] = setting('pushover_enabled', '0') === '1' ? Pushover::send($title, $body, 0) : null;
        $res['email'] = setting('email_enabled', '0') === '1' ? Mailer::send($title, $body) : null;
        if (setting('ha_webhook_enabled', '0') === '1') {
            $fake = ['id' => 0, 'hid' => 0, 'host_name' => 'RzMon', 'name' => t('Testmelding'), 'type' => 'ping', 'target' => ''];
            $res['webhook'] = Webhook::send(Monitor::webhookPayload($fake, ['value' => null], STATUS_OK, $title, $body, 'test', STATUS_OK));
        } else {
            $res['webhook'] = null;
        }
        if (setting('mqtt_enabled', '0') === '1') {
            $r = Mqtt::publishAll(null, false);
            $res['mqtt'] = ['ok' => $r['ok'], 'error' => $r['error'] ?? null];
        } else {
            $res['mqtt'] = null;
        }
        q('INSERT INTO alerts (check_id, status, title, message, sent_ok, kind, created_at) VALUES (NULL, 0, ?, ?, 1, ?, ?)', [$title, $body, 'info', now()]);
        $res['page'] = ['ok' => true];
        out(['channels' => array_map(static fn($r) => $r === null ? null : ['ok' => (bool) ($r['ok'] ?? false), 'error' => (string) ($r['error'] ?? '')], $res)]);

    case 'cleanup':   // 1.96.0: opschoonadvies
        out(['items' => Cleanup::advice()]);

    case 'cleanup_ignore':
        $in = input();
        Cleanup::ignore((int) ($in['check_id'] ?? 0), preg_replace('/[^a-z]/', '', (string) ($in['kind'] ?? '')));
        out(['ok' => true]);

    case 'checks_all':
        out(['checks' => q('SELECT c.id, c.host_id, h.name AS host_name, c.type, c.target, c.name, c.metric, c.warn_threshold, c.crit_threshold, c.interval_sec, c.enabled,
                c.status, c.notify IS NOT NULL AS own_notify, c.notify_fails, c.info_only, c.maintenance_until, c.last_checked, c.template_item_id, c.tpl_override, t.name AS template_name
            FROM checks c JOIN hosts h ON h.id = c.host_id LEFT JOIN template_items ti ON ti.id = c.template_item_id LEFT JOIN check_templates t ON t.id = ti.template_id
            ORDER BY h.name, c.name')->fetchAll(), 'hosts' => q('SELECT id, name, address, mode FROM hosts ORDER BY name')->fetchAll()]);

    case 'checks_bulk':
        // Meerdere checks tegelijk: set (velden), maintenance, delete of copy (naar servers)
        $in = input();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
        if (!$ids || count($ids) > 2000) {
            out(['error' => t('Kies een of meer checks')], 422);
        }
        $list = implode(',', $ids);
        $act = (string) ($in['action'] ?? '');
        // Wijzigingslogboek: elke check apart, zodat je ook één ervan kunt terugzetten
        $bulkBefore = [];
        foreach ($ids as $bid) {
            if ($r = ChangeLog::checkRow($bid)) {
                $bulkBefore[$bid] = $r;
            }
        }
        $bulkLog = static function (string $what) use ($bulkBefore): void {
            foreach ($bulkBefore as $bid => $r) {
                $after = $what === 'delete' ? null : ChangeLog::checkRow($bid);
                ChangeLog::record('check', $bid, $what === 'delete' ? 'delete' : 'update', $r['_host'] . ' · ' . $r['name'] . ' (' . t('meerdere tegelijk') . ')', $r, $after);
            }
        };
        if ($act === 'delete') {
            $confirm('VERWIJDEREN');
            $bulkLog('delete');
            $n = q("DELETE FROM checks WHERE id IN ($list)")->rowCount();
            out(['ok' => true, 'n' => $n]);
        }
        if ($act === 'maintenance') {
            $minutes = max(0, min(60 * 24 * 90, (int) ($in['minutes'] ?? 0)));
            $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
            $n = q("UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 0 WHERE id IN ($list)",
                [$until, mb_substr(trim((string) ($in['reason'] ?? '')), 0, 200) ?: null])->rowCount();
            $bulkLog('update');
            out(['ok' => true, 'n' => $n]);
        }
        if ($act === 'copy') {
            $n = 0;
            foreach ($ids as $id) {
                $n += Templates::copy($id, (array) ($in['host_ids'] ?? []));
            }
            out(['ok' => true, 'n' => $n]);
        }
        if ($act !== 'set') {
            out(['error' => t('Onbekende actie')], 422);
        }
        $v = (array) ($in['values'] ?? []);
        $set = [];
        if (isset($v['interval_sec'])) {
            $set['interval_sec'] = max(30, min(86400, (int) $v['interval_sec']));
        }
        foreach (['enabled', 'info_only'] as $b) {
            if (isset($v[$b])) {
                $set[$b] = $v[$b] ? 1 : 0;
            }
        }
        if (array_key_exists('notify_fails', $v)) {
            $set['notify_fails'] = $v['notify_fails'] === null || $v['notify_fails'] === '' ? null : max(1, min(100, (int) $v['notify_fails']));
        }
        if (!empty($v['notify_default'])) {
            $set['notify'] = null;   // terug naar de algemene meldregels
        }
        if (array_key_exists('warn_threshold', $v) || array_key_exists('crit_threshold', $v)) {
            // Drempels alleen voor checks van één soort (anders betekenen de getallen iets anders)
            $types = q("SELECT DISTINCT CONCAT(type, '|', COALESCE(metric, '')) FROM checks WHERE id IN ($list)")->fetchAll(PDO::FETCH_COLUMN);
            if (count($types) !== 1) {
                out(['error' => t('Drempels kun je alleen voor checks van hetzelfde type tegelijk aanpassen')], 422);
            }
            $w = num($v['warn_threshold'] ?? null);
            $c = num($v['crit_threshold'] ?? null);
            $low = str_starts_with($types[0], 'ssl|');
            if ($w !== null && $c !== null && ($low ? $w < $c : $w > $c)) {
                out(['error' => $low ? t('Bij een certificaat moet de warning-drempel (dagen) hoger zijn dan de critical-drempel') : t('Warning-drempel moet lager zijn dan critical-drempel')], 422);
            }
            $set['warn_threshold'] = $w;
            $set['crit_threshold'] = $c;
        }
        // 1.107.0: drempelmodus en gevoeligheid (checks die niet kunnen leren, blijven handmatig)
        $smartN = null;
        if (isset($v['thresh_mode']) || array_key_exists('smart_sens', $v)) {
            $smartN = 0;
            foreach ($ids as $bid) {
                $c = q('SELECT * FROM checks WHERE id = ?', [$bid])->fetch();
                if ($c && Smart::eligible($c)) {
                    Smart::saveForm($bid, array_intersect_key($v, array_flip(['thresh_mode', 'smart_sens'])));
                    $smartN++;
                }
            }
        }
        if (!$set && $smartN === null) {
            out(['error' => t('Niets om aan te passen')], 422);
        }
        if (!$set) {
            $bulkLog('update');
            out(['ok' => true, 'n' => $smartN]);
        }
        $cols = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($set)));
        // Met de hand aangepast: sjabloonchecks gaan daarna niet meer mee met het sjabloon
        q("UPDATE checks SET $cols, tpl_override = IF(template_item_id IS NULL, 0, 1) WHERE id IN ($list)", array_values($set));
        $bulkLog('update');
        out(['ok' => true, 'n' => count($ids)]);

    case 'check_try':   // 1.95.0: proberen vóór opslaan, er wordt niets bewaard
        $in = input();
        [$cols] = $checkCols(['id' => (int) ($in['check_id'] ?? 0)] + $in, true);
        try {
            $r = Monitor::tryCheck((int) ($in['host_id'] ?? 0), (int) ($in['check_id'] ?? 0), $cols);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        out(['status' => (int) $r['status'], 'value' => $r['value'], 'message' => (string) $r['message']]);

    case 'check_delete':
        $id = (int) (input()['id'] ?? 0);
        if ($row = ChangeLog::checkRow($id)) {
            ChangeLog::record('check', $id, 'delete', $row['_host'] . ' · ' . $row['name'], $row, null);
        }
        q('DELETE FROM checks WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'check_run':
        $res = Monitor::runCheckById((int) (input()['id'] ?? 0));
        out(['ok' => $res !== null, 'result' => $res]);

    case 'run_all':
        out(['ok' => true, 'count' => Monitor::runDue(true)]);

    case 'settings':
        $s = settings();
        // Wachtwoorden en tokens nooit terugsturen, alleen of ze ingevuld zijn
        foreach (SECRET_SETTINGS as $k) {
            $s[$k] = ($s[$k] ?? '') !== '' ? '••••••••' : '';
        }
        unset($s['ack_secret'], $s['mqtt_topics'], $s['mqtt_hash'], $s['stats_cache'], $s['update_cache']);
        $s['alerts_days'] = (string) Monitor::alertDays();
        $s['sysinfo'] = json_encode(NotifyRules::sysinfo());
        out($s);

    case 'settings_save':
        $in = input();
        if (isset($in['sysinfo'])) {
            // Systeeminfo: alleen bekende meldingen en kanalen bewaren
            $in['sysinfo'] = json_encode(NotifyRules::cleanSysinfo((array) json_decode((string) $in['sysinfo'], true), NotifyRules::sysinfo()));
        }
        $allowed = ['sysinfo', 'pushover_enabled', 'pushover_user', 'pushover_token', 'pushover_priority_crit', 'pushover_priority_warn',
            'pushover_sound', 'alert_after', 'repeat_minutes', 'notify_warn', 'notify_recovery', 'agent_stale_minutes', 'agent_tick', 'cert_dirs', 'retention_days', 'alerts_days', 'auth_events', 'auth_ignore', 'ids_baseline_hours', 'ids_fail_count', 'ids_fail_minutes', 'ids_block_hours', 'ids_repeat_days', 'ids_fim', 'ids_fim_exclude', 'ids_decoy_users', 'ids_decoy_port', 'ids_block_shared', 'ids_weekly', 'ids_weekly_day', 'ids_weekly_time', 'ids_blocklists', 'ids_geo', 'ids_geo_allowed', 'ids_webshell', 'ids_webshell_kill', 'ids_persist', 'ids_outbound', 'ids_outbound_country', 'ids_outbound_block', 'ids_harden', 'weblog_swag', 'weblog_swag_path', 'weblog_ha', 'weblog_ha_path', 'weblog_vw', 'weblog_vw_path', 'weblog_block', 'wl_paths', 'wl_ua', 'wl_404', 'wl_404_n', 'wl_auth', 'wl_auth_n', 'wl_nohost', 'wl_nohost_n', 'wl_ha_n', 'wl_vw_n', 'wl_minutes',
            'ha_webhook_enabled', 'ha_webhook_url', 'ha_webhook_verify_ssl', 'ha_webhook_events', 'notify_recovery_acked',
            'email_enabled', 'email_mode', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_user', 'smtp_pass', 'smtp_verify_ssl', 'mail_from', 'mail_to',
            'mqtt_enabled', 'mqtt_host', 'mqtt_port', 'mqtt_tls', 'mqtt_verify_ssl', 'mqtt_user', 'mqtt_pass', 'mqtt_prefix', 'mqtt_base_topic',
            'disk_forecast_days', 'auto_maint_enabled', 'auto_maint_after', 'log_window', 'agent_auto_update',
            'backup_freq', 'backup_time', 'backup_weekday', 'backup_keep', 'backup_history', 'backup_pass',
            'notify_rules', 'notify_channels', 'notify_unknown', 'notify_group_seconds', 'notify_bundle_hosts', 'notify_fails', 'notify_flap', 'notify_quiet', 'notify_lang',
            'vuln_time', 'vuln_min_severity', 'vuln_exclude', 'net_scan', 'net_sweep_minutes', 'net_deep_per_day', 'report_monthly', 'thresh_default', 'smart_sens', 'esc_on', 'esc_warn_min', 'esc_crit_min', 'esc_crit_prio', 'esc_quiet', 'smart_interval', 'smart_fast', 'smart_slow', 'anomaly_on', 'disk_jump_on', 'disk_jump_pct', 'context_on', 'hb_learn', 'edr_on', 'edr_kill', 'edr_block', 'edr_isolate', 'edr_sigma', 'edr_web', 'edr_anom', 'edr_learn_days', 'edr_anom_min', 'edr_chain', 'edr_chain_min', 'edr_chain_act', 'ti_on', 'ti_abuseipdb_key', 'ti_greynoise', 'ti_greynoise_key', 'ti_shodan', 'ti_circl', 'ti_tor', 'ti_tor_block', 'ti_urlhaus', 'ti_feodo', 'ti_abusech_key', 'ti_block', 'ti_block_score', 'ti_escalate', 'ti_close_benign', 'ti_report', 'ti_cve', 'dns_on', 'suricata_on', 'suricata_block', 'edr_yara', 'ids_modern', 'perf_on', 'edr_note_hide', 'unifi_url', 'unifi_site', 'unifi_user', 'unifi_pass', 'unifi_key', 'unifi_verify_ssl', 'adguard_urls', 'adguard_user', 'adguard_pass', 'adguard_verify_ssl',
            'update_auto', 'update_time', 'update_backup'];
        if (isset($in['update_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['update_time'])) {
            out(['error' => t('Tijdstip voor automatisch bijwerken: gebruik uu:mm, bv. 04:00')], 422);
        }
        if (isset($in['notify_quiet'])) {
            $in['notify_quiet'] = json_encode(NotifyRules::cleanQuiet((array) (is_string($in['notify_quiet']) ? json_decode($in['notify_quiet'], true) : $in['notify_quiet'])));
        }
        if (isset($in['notify_flap'])) {
            $in['notify_flap'] = json_encode(NotifyRules::cleanFlap((array) (is_string($in['notify_flap']) ? json_decode($in['notify_flap'], true) : $in['notify_flap'])));
        }
        // Meldregels en kanalen per status: alleen geldige waarden opslaan
        if (isset($in['notify_rules'])) {
            $in['notify_rules'] = json_encode(array_replace(NotifyRules::defaults()['rules'],
                NotifyRules::cleanRules((array) (is_string($in['notify_rules']) ? json_decode($in['notify_rules'], true) : $in['notify_rules']))));
        }
        if (isset($in['notify_channels'])) {
            $in['notify_channels'] = json_encode(array_replace(NotifyRules::defaults()['channels'],
                NotifyRules::cleanChannels((array) (is_string($in['notify_channels']) ? json_decode($in['notify_channels'], true) : $in['notify_channels']))));
        }
        foreach (['esc_warn_min' => [0, 1440], 'esc_crit_min' => [0, 1440], 'esc_crit_prio' => [-1, 2], 'smart_fast' => [10, 300], 'smart_slow' => [1, 10], 'disk_jump_pct' => [1, 50], 'edr_learn_days' => [1, 90], 'edr_anom_min' => [1, 100], 'edr_chain_min' => [5, 1440], 'ti_block_score' => [10, 100]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        if (isset($in['thresh_default']) && !in_array($in['thresh_default'], Smart::MODES, true)) {
            $in['thresh_default'] = 'guard';
        }
        if (isset($in['smart_sens']) && !in_array($in['smart_sens'], Smart::SENS, true)) {
            $in['smart_sens'] = 'normal';
        }
        if (isset($in['auth_ignore'])) {
            $in['auth_ignore'] = implode(' ', array_slice(parse_list(mb_substr((string) $in['auth_ignore'], 0, 2000)), 0, 100));
        }
        foreach (['net_sweep_minutes' => [15, 1440], 'net_deep_per_day' => [1, 24], 'ids_baseline_hours' => [0, 720], 'ids_fail_count' => [2, 1000], 'ids_fail_minutes' => [1, 1440], 'ids_block_hours' => [1, 8760], 'ids_repeat_days' => [1, 365]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        if (isset($in['agent_tick'])) {
            $in['agent_tick'] = (string) (in_array((int) $in['agent_tick'], AGENT_TICKS, true) ? (int) $in['agent_tick'] : 60);
        }
        if (isset($in['ids_fim'])) {
            $in['ids_fim'] = json_encode(Ids::cleanFim(is_array($in['ids_fim']) ? $in['ids_fim'] : json_decode((string) $in['ids_fim'], true)), JSON_UNESCAPED_SLASHES);
        }
        if (isset($in['ids_fim_exclude'])) {
            $in['ids_fim_exclude'] = Ids::cleanFimExclude((string) $in['ids_fim_exclude']);
        }
        // Eigen mappen waarin de agent naar certificaatbestanden zoekt (1.98.0): absolute paden zonder spaties, hooguit 20
        if (isset($in['cert_dirs'])) {
            $in['cert_dirs'] = implode(' ', array_slice(array_values(array_unique(array_filter(preg_split('/[\s,]+/', mb_substr((string) $in['cert_dirs'], 0, 4000)),
                static fn($d) => (bool) preg_match('#^/[A-Za-z0-9._@+/-]{1,200}$#', $d) && !str_contains($d, '..')))), 0, 20));
        }
        // IDS (1.81.0): honeypot-gebruikers (namen), honeypot-poort (leeg = uit), wekelijks overzicht
        if (isset($in['ids_decoy_users'])) {
            $in['ids_decoy_users'] = implode(' ', array_slice(array_filter(parse_list(mb_strtolower(mb_substr((string) $in['ids_decoy_users'], 0, 2000))),
                fn($x) => (bool) preg_match('/^[a-z0-9._-]{1,32}$/', $x)), 0, 100));
        }
        if (isset($in['ids_decoy_port'])) {
            $dp = trim((string) $in['ids_decoy_port']);
            $in['ids_decoy_port'] = $dp === '' || (int) $dp < 1 || (int) $dp > 65535 ? '' : (string) (int) $dp;
        }
        if (isset($in['ids_geo_allowed'])) {
            $in['ids_geo_allowed'] = implode(' ', array_slice(array_filter(array_map('strtoupper', parse_list((string) $in['ids_geo_allowed'])),
                fn($c) => (bool) preg_match('/^[A-Z]{2}$/', $c)), 0, 60)) ?: 'NL';
        }
        if (isset($in['ids_weekly'])) {
            $in['ids_weekly'] = in_array($in['ids_weekly'], ['off', 'email', 'pushover', 'both'], true) ? $in['ids_weekly'] : 'email';
        }
        if (isset($in['ids_weekly_day'])) {
            $in['ids_weekly_day'] = (string) max(1, min(7, (int) $in['ids_weekly_day']));
        }
        if (isset($in['ids_weekly_time'])) {
            $in['ids_weekly_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['ids_weekly_time']) ? (string) $in['ids_weekly_time'] : '08:00';
        }
        // Logs van webdiensten (1.89.0): aantallen binnen grenzen, paden zonder spaties (leeg = zelf zoeken)
        foreach (['wl_404_n' => [3, 10000], 'wl_auth_n' => [3, 10000], 'wl_nohost_n' => [1, 10000], 'wl_ha_n' => [2, 1000], 'wl_vw_n' => [2, 1000], 'wl_minutes' => [1, 1440]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        foreach (['weblog_swag_path', 'weblog_ha_path', 'weblog_vw_path'] as $k) {
            if (isset($in[$k])) {
                $v = trim((string) $in[$k]);
                $in[$k] = $v === '' || preg_match('#^[A-Za-z0-9_./:@-]{1,250}$#', $v) ? ($v ?: 'auto') : 'auto';
            }
        }
        if (isset($in['alerts_days'])) {
            $in['alerts_days'] = (string) max(1, min(3650, (int) $in['alerts_days']));
        }
        if (isset($in['notify_fails'])) {
            $in['notify_fails'] = (string) max(1, min(100, (int) $in['notify_fails']));
        }
        if (isset($in['vuln_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['vuln_time'])) {
            out(['error' => t('Scantijd voor kwetsbaarheden: gebruik uu:mm, bv. 03:30')], 422);
        }
        if (isset($in['vuln_min_severity'])) {
            $in['vuln_min_severity'] = (string) max(0, min(4, (int) $in['vuln_min_severity']));
        }
        if (isset($in['vuln_exclude'])) {
            $in['vuln_exclude'] = implode(',', array_unique(array_filter(array_map('intval', is_array($in['vuln_exclude']) ? $in['vuln_exclude'] : explode(',', (string) $in['vuln_exclude'])))));
        }
        if (isset($in['notify_lang'])) {
            $in['notify_lang'] = in_array($in['notify_lang'], I18n::LANGS, true) ? $in['notify_lang'] : 'nl';
        }
        if (isset($in['notify_group_seconds'])) {
            $in['notify_group_seconds'] = (string) max(0, min(3600, (int) $in['notify_group_seconds']));
        }
        if (($in['ha_webhook_url'] ?? '') !== '' && !preg_match('#^https?://\S+$#i', trim((string) $in['ha_webhook_url']))) {
            out(['error' => t('Webhook-URL moet met http:// of https:// beginnen')], 422);
        }
        // Velden die samen moeten kloppen: er komt (bij meteen opslaan) alleen mee wat veranderd is, dus voor de rest
        // de opgeslagen waarde gebruiken. Anders lukt bv. "e-mail aan" nooit, ook al staan server en ontvanger er al.
        $eff = static fn(string $k): string => trim((string) (array_key_exists($k, $in) ? $in[$k] : setting($k, '')));
        if ($eff('ha_webhook_enabled') === '1' && $eff('ha_webhook_url') === '') {
            out(['error' => t('Vul een webhook-URL in of zet de webhook uit')], 422);
        }
        if ($eff('email_enabled') === '1' && ($eff('smtp_host') === '' || $eff('mail_to') === '')) {
            out(['error' => t('Vul de SMTP-server en een ontvanger in, of zet e-mail uit')], 422);
        }
        if ($eff('mqtt_enabled') === '1' && $eff('mqtt_host') === '') {
            out(['error' => t('Vul de MQTT-server in, of zet MQTT uit')], 422);
        }
        foreach (['mqtt_prefix', 'mqtt_base_topic'] as $k) {
            if (isset($in[$k]) && !preg_match('#^[A-Za-z0-9_\-/]{1,64}$#', trim((string) $in[$k]))) {
                out(['error' => t('Een MQTT-topic mag alleen letters, cijfers, _ - en / bevatten')], 422);
            }
        }
        if (isset($in['backup_freq']) && !in_array($in['backup_freq'], ['off', 'daily', 'weekly', 'monthly'], true)) {
            out(['error' => t('Ongeldige back-upfrequentie')], 422);
        }
        if (isset($in['backup_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['backup_time'])) {
            out(['error' => t('Vul de back-uptijd in als uu:mm, bv. 03:15')], 422);
        }
        if (isset($in['backup_keep']) && ((int) $in['backup_keep'] < 1 || (int) $in['backup_keep'] > 365)) {
            out(['error' => t('Aantal back-ups bewaren: 1 tot 365')], 422);
        }
        if (isset($in['backup_weekday']) && ((int) $in['backup_weekday'] < 1 || (int) $in['backup_weekday'] > 7)) {
            out(['error' => t('Ongeldige dag voor de back-up')], 422);
        }
        if (($in['backup_pass'] ?? '') !== '' && $in['backup_pass'] !== '••••••••' && mb_strlen((string) $in['backup_pass']) < 8) {
            out(['error' => t('Het back-upwachtwoord moet minstens 8 tekens hebben')], 422);
        }
        // Schema gewijzigd: tellen vanaf nu (anders volgt meteen een "ingehaalde" back-up)
        foreach (['backup_freq', 'backup_time', 'backup_weekday'] as $k) {
            if (isset($in[$k]) && (string) $in[$k] !== (string) setting($k, '')) {
                save_setting('backup_last_auto', now());
                save_setting('backup_retry_at', '');
                break;
            }
        }
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            if (in_array($k, SECRET_SETTINGS, true) && $v === '••••••••') {
                continue;
            }
            save_setting($k, $v);
        }
        out(['ok' => true]);

    // 🧪 bij een rij van ℹ️ Systeeminfo: een voorbeeld naar de aangevinkte kanalen (wat nu in het formulier staat), ook tijdens stille uren
    case 'sysinfo_test':
        $in = input();
        $event = (string) ($in['event'] ?? '');
        if (!in_array($event, NotifyRules::SYSINFO, true)) {
            out(['error' => t('Onbekende melding')], 422);
        }
        $cfg = NotifyRules::cleanSysinfo([$event => (array) ($in['cfg'] ?? [])], NotifyRules::sysinfo())[$event];
        $label = mb_substr(trim((string) ($in['label'] ?? $event)), 0, 80);
        Monitor::sysInfo($event, null, null, '🧪 ' . t('Test') . ": $label", fn() => t('Zo ziet een melding "{label}" eruit. Als je dit ziet, werkt dit kanaal.', ['label' => $label]),
            null, null, false, $cfg, true);
        $row = q("SELECT message, sent_ok, email_ok, webhook_ok FROM alerts WHERE kind = 'info' ORDER BY id DESC LIMIT 1")->fetch();
        out(['ok' => true, 'page' => $cfg[NotifyRules::PAGE], 'result' => $row ?: null]);

    case 'webhook_test':
        $in = input();
        $url = trim((string) ($in['ha_webhook_url'] ?? '')) ?: null;
        $verify = isset($in['ha_webhook_verify_ssl']) ? $in['ha_webhook_verify_ssl'] === '1' : null;
        $fake = ['id' => 0, 'hid' => 0, 'host_name' => 'RzMon', 'name' => t('Testmelding'), 'type' => 'ping', 'target' => ''];
        $res = Webhook::send(Monitor::webhookPayload($fake, ['value' => null], STATUS_OK, '🧪 RzMon test',
            t('Als je dit in Home Assistant ziet, werkt de webhook! 🎉'), 'test', STATUS_OK), $url, $verify);
        out($res, $res['ok'] ? 200 : 422);

    case 'email_test':
        // Test met de (nog niet opgeslagen) waarden uit het formulier
        $in = input();
        $cfg = Mailer::config();
        foreach (array_keys($cfg) as $k) {
            if (isset($in[$k]) && !($k === 'smtp_pass' && $in[$k] === '••••••••')) {
                $cfg[$k] = trim((string) $in[$k]);
            }
        }
        session_write_close();
        $res = Mailer::send('🧪 RzMon test', t('Als je dit leest, werken e-mailmeldingen van RzMon! 🎉') . "\n\n" . t('Verstuurd op {time}.', ['time' => date('d-m-Y H:i:s')]), $cfg);
        out($res, $res['ok'] ? 200 : 422);

    case 'mqtt_test':
        $in = input();
        $cfg = Mqtt::config();
        foreach (array_keys($cfg) as $k) {
            if (isset($in[$k]) && !($k === 'mqtt_pass' && $in[$k] === '••••••••')) {
                $cfg[$k] = trim((string) $in[$k]);
            }
        }
        session_write_close();
        $r = Mqtt::publishAll($cfg, true);
        if (!$r['ok']) {
            out(['error' => $r['error']], 422);
        }
        out(['ok' => true, 'message' => t('Verbonden ✔ — {n} sensoren voor {hosts} server(s) naar Home Assistant gestuurd', ['n' => $r['entities'], 'hosts' => $r['hosts']])]);

    case 'pushover_test':
        $in = input();
        $token = ($in['pushover_token'] ?? '') === '••••••••' || ($in['pushover_token'] ?? '') === ''
            ? (string) setting('pushover_token', '') : (string) $in['pushover_token'];
        $res = Pushover::send('🧪 RzMon test', t('Als je dit leest werkt Pushover! 🎉'), 0,
            trim((string) ($in['pushover_user'] ?? '')) ?: null, $token ?: null);
        out($res, $res['ok'] ? 200 : 422);

    case 'access_info':
        out([
            'lan_no_login' => setting('lan_no_login', '0') === '1',
            'lan_as_admin' => setting('lan_as_admin', '0') === '1',
            'trusted_networks' => (string) setting('trusted_networks', ''),
            'trusted_proxies' => (string) setting('trusted_proxies', ''),
            'client_ip' => client_ip(),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'client_trusted' => Https::internal(),
            'unknown_proxy' => Https::unknownProxy(),
            'lan_session' => is_lan_session(),
            'lock' => Security::lockConfig(),
            'session_idle' => (int) (session_ttl() / 60), 'session_idle_choices' => SESSION_IDLE,
        ]);

    case 'access_save':
        $in = input();
        $networks = parse_list((string) ($in['trusted_networks'] ?? ''));
        $proxies = parse_list((string) ($in['trusted_proxies'] ?? ''));
        foreach (array_merge($networks, $proxies) as $cidr) {
            [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            $max = str_contains($ip, ':') ? 128 : 32;
            if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $max))) {
                out(['error' => t('Ongeldig netwerk: {net} (gebruik bv. 192.168.1.0/24 of 10.0.0.5)', ['net' => $cidr])], 422);
            }
        }
        $enable = !empty($in['lan_no_login']);
        if ($enable && !$networks) {
            out(['error' => t('Geef minimaal één lokaal netwerk op')], 422);
        }
        foreach ($networks as $cidr) {
            if (str_ends_with($cidr, '/0')) {
                out(['error' => t('{net} zou iedereen op internet zonder wachtwoord binnenlaten', ['net' => $cidr])], 422);
            }
        }
        save_setting('lan_no_login', $enable ? '1' : '0');
        save_setting('lan_as_admin', !empty($in['lan_as_admin']) ? '1' : '0');
        save_setting('trusted_networks', implode("\n", $networks));
        save_setting('trusted_proxies', implode("\n", $proxies));
        $pu = json_decode((string) setting('proxy_unknown', ''), true) ?: [];
        if (!empty($pu['ip']) && ip_in_list((string) $pu['ip'], $proxies)) {
            save_setting('proxy_unknown', '');   // die proxy staat er nu bij: de ⚠️ in "Hoe staat het ervoor?" mag weg
        }
        // Inlogbeveiliging
        if (isset($in['login_lock_fails'])) {
            save_setting('login_lock_fails', (string) max(1, min(50, (int) $in['login_lock_fails'])));
            save_setting('login_lock_window', (string) max(1, min(1440, (int) ($in['login_lock_window'] ?? 10))));
            save_setting('login_lock_minutes', (string) max(0, min(525600, (int) ($in['login_lock_minutes'] ?? 60))));
            save_setting('login_lock_account', !empty($in['login_lock_account']) ? '1' : '0');
            save_setting('login_lock_ip', !empty($in['login_lock_ip']) ? '1' : '0');
        }
        if (isset($in['session_idle']) && in_array((int) $in['session_idle'], SESSION_IDLE, true)) {
            save_setting('session_idle_minutes', (string) (int) $in['session_idle']);
        }
        out(['ok' => true]);

    // ---------- Beheer via https (1.68.0): beheeradres, alleen https, inloggen via http weigeren, 2FA van buiten ----------
    case 'https_info':
        $state = json_decode((string) setting('https_state', ''), true) ?: [];
        // Het IP-adres van RzMon zelf (voor de nooddeur), zoals de browser of proxy het bereikte
        $sa = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        if (filter_var($sa, FILTER_VALIDATE_IP) && !ip_in_list($sa, ['127.0.0.0/8', '::1/128']) && ($state['server_ip'] ?? '') !== $sa) {
            $state['server_ip'] = $sa;
            save_setting('https_state', json_encode($state, JSON_UNESCAPED_UNICODE));
        }
        $full = Https::hstsFullAt();
        out([
            'admin_url' => Https::adminUrl(), 'https_only' => setting('https_only', '0') === '1', 'http_login_block' => setting('http_login_block', '0') === '1',
            'mfa_external' => setting('mfa_external', '0') === '1',
            'req' => ['https' => Https::isHttps(), 'host' => Https::host(), 'on_admin' => Https::onAdminUrl(), 'via_proxy' => Https::viaProxy(),
                'unknown_proxy' => Https::unknownProxy(), 'no_proto' => Https::proxyNoProto(), 'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
                'client_ip' => client_ip(), 'internal' => Https::internal()],
            'proxies' => parse_list((string) setting('trusted_proxies', '')),
            'proxy_unknown' => json_decode((string) setting('proxy_unknown', ''), true) ?: null,
            'hsts' => Https::on() ? ['age' => Https::hstsAge(), 'full_at' => $full ? date('Y-m-d H:i', $full) : null] : null,
            'state' => $state ?: null, 'checked_at' => setting('https_check_at', '') ?: null,
            'door' => Https::door(),
            'no2fa' => q("SELECT username FROM users WHERE totp_secret IS NULL OR totp_secret = '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN),
            'my_2fa' => (bool) q("SELECT totp_secret IS NOT NULL AND totp_secret <> '' FROM users WHERE id = ?", [$user['id']])->fetchColumn(),
            'warn_days' => Https::WARN_DAYS,
        ]);

    case 'https_check':
        out(['ok' => true, 'state' => Https::check()]);

    case 'https_save':
        $in = input();
        $url = rtrim(trim((string) ($in['admin_url'] ?? Https::adminUrl())), '/');
        if ($url !== '' && !preg_match('~^https://[a-z0-9.\-]+(:\d{1,5})?(/[^?#\s]*)?$~i', $url)) {
            out(['error' => t('Beheeradres: gebruik https:// met een naam, bv. https://rzmon.jouwdomein.nl')], 422);
        }
        $only = !empty($in['https_only']);
        $block = !empty($in['http_login_block']);
        $mfa = !empty($in['mfa_external']);
        $was = ['only' => setting('https_only', '0') === '1', 'block' => setting('http_login_block', '0') === '1', 'mfa' => setting('mfa_external', '0') === '1'];
        $urlChanged = $url !== Https::adminUrl();
        // Minder veilig maken: bevestigen met je wachtwoord
        if (($was['only'] && !$only) || ($was['block'] && !$block) || ($was['mfa'] && !$mfa)) {
            $confirm('UITZETTEN');
        }
        if ($only && $url === '') {
            out(['error' => t('Vul eerst het beheeradres in')], 422);
        }
        if ($only && (!$was['only'] || $urlChanged)) {
            // Alleen aanzetten als het nu al werkt: je zit via het beheeradres met https, en het certificaat klopt
            if (!Https::onAdminUrl($url)) {
                out(['error' => t('Open RzMon eerst via {url} en zet het daar aan. Zo weet je zeker dat het werkt voordat http dicht gaat.', ['url' => $url])
                    . (Https::proxyNoProto() ? ' ' . t('Je proxy meldt geen https (X-Forwarded-Proto ontbreekt).') : '')
                    . (Https::unknownProxy() ? ' ' . t('Je komt via een proxy die niet bij Vertrouwde proxy\'s staat.') : '')], 422);
            }
            $s = Https::check(false, $url);
            if ($s['problem'] !== null) {
                Https::check();
                out(['error' => t('Niet aangezet: {p}. RzMon controleert het certificaat zelf; kan de RzMon-server {url} niet bereiken, zet de DNS-rewrite dan ook voor de RzMon-server (of een regel in /etc/hosts).', ['p' => $s['problem'], 'url' => $url])], 422);
            }
            save_setting('https_since', now());
        }
        if ($block && !$was['block'] && !Https::isHttps()) {
            out(['error' => t('Zet dit aan terwijl je RzMon via https open hebt; anders sluit je jezelf nu buiten')], 422);
        }
        if ($mfa && !$was['mfa'] && !Https::internal()
            && !q("SELECT totp_secret IS NOT NULL AND totp_secret <> '' FROM users WHERE id = ?", [$user['id']])->fetchColumn()) {
            out(['error' => t('Je zit nu buiten je interne netwerken en je eigen account heeft nog geen 2FA: stel eerst 2FA in (Mijn account)')], 422);
        }
        foreach (['admin_url' => $url, 'https_only' => $only ? '1' : '0', 'http_login_block' => $block ? '1' : '0', 'mfa_external' => $mfa ? '1' : '0'] as $k => $v) {
            if (setting($k, '') !== $v) {
                save_setting($k, $v);
                AgentCommands::audit($user['username'], 'Beheer via https', "$k = $v");
            }
        }
        if ($urlChanged && $url !== '' && !$only) {
            Https::check(false, $url);
        }
        out(['ok' => true]);

    // ---------- Datalekken via Have I Been Pwned (1.69.0) ----------
    case 'hibp_info':
        out([
            'enabled' => Hibp::enabled(), 'threshold' => Hibp::threshold(), 'has_key' => Hibp::hasKey(),
            'emails' => (string) setting('hibp_emails', ''), 'domain' => Hibp::domain(), 'rpm' => (int) setting('hibp_rpm', '10'),
            'last_error' => (string) setting('hibp_last_error', '') ?: null, 'domain_error' => (string) setting('hibp_domain_error', '') ?: null,
            'check_at' => setting('hibp_check_at', '') ?: null, 'domain_at' => setting('hibp_domain_at', '') ?: null,
            'count' => count(Hibp::emails()), 'summary' => Hibp::enabled() ? Hibp::summary() : null,
        ]);

    case 'hibp_save':
        $in = input();
        if (array_key_exists('enabled', $in) && !empty($in['enabled']) !== Hibp::enabled()) {
            save_setting('hibp_enabled', !empty($in['enabled']) ? '1' : '0');
            AgentCommands::audit($user['username'], 'Datalekken', 'controle ' . (!empty($in['enabled']) ? 'aan' : 'uit'));
        }
        if (isset($in['threshold'])) {
            save_setting('hibp_threshold', (string) max(1, min(1000000, (int) $in['threshold'])));
        }
        if (isset($in['emails'])) {
            $list = [];
            foreach (parse_list((string) $in['emails']) as $e) {
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    out(['error' => t('Ongeldig e-mailadres: {e}', ['e' => mb_substr($e, 0, 100)])], 422);
                }
                $list[strtolower($e)] = true;
            }
            save_setting('hibp_emails', implode("\n", array_keys($list)));
        }
        if (isset($in['domain'])) {
            $d = strtolower(trim((string) $in['domain']));
            if ($d !== '' && !preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) {
                out(['error' => t('Ongeldig domein (bv. jouwdomein.nl)')], 422);
            }
            if ($d !== Hibp::domain()) {
                save_setting('hibp_domain', $d);
                save_setting('hibp_domain_at', '');
                save_setting('hibp_domain_error', '');
            }
        }
        if (isset($in['key'])) {
            $key = trim((string) $in['key']);
            if ($key !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
                out(['error' => t('Dat lijkt geen API-sleutel van HIBP (32 letters en cijfers)')], 422);
            }
            save_setting('hibp_key', $key);
            save_setting('hibp_last_error', '');
            save_setting('hibp_wait_until', '0');
            AgentCommands::audit($user['username'], 'Datalekken', $key === '' ? 'API-sleutel verwijderd' : 'API-sleutel ingesteld');
        }
        out(['ok' => true]);

    case 'hibp_test':
        $key = trim((string) (input()['key'] ?? ''));
        try {
            $s = Hibp::subscription($key !== '' ? $key : null);
            out(['ok' => true, 'name' => $s['SubscriptionName'] ?? '?', 'until' => $s['SubscribedUntil'] ?? null, 'rpm' => $s['Rpm'] ?? null,
                'domain_max' => $s['DomainSearchMaxBreachedAccounts'] ?? null]);
        } catch (Throwable $e) {
            out(['error' => 'HIBP: ' . $e->getMessage()], 422);
        }

    // Nu controleren: het domein en een eerste ronde adressen (de rest volgt via cron)
    case 'hibp_run':
        if (!Hibp::enabled() || !Hibp::hasKey()) {
            out(['error' => t('Zet de controle aan en vul een API-sleutel in')], 422);
        }
        q('UPDATE hibp_emails SET checked_at = NULL');
        save_setting('hibp_domain_at', '');
        save_setting('hibp_wait_until', '0');
        $msg = Hibp::cron(8);
        out(['ok' => true, 'message' => $msg, 'left' => count(array_filter(Hibp::emails(), static fn($s) => $s !== 'domain'))
            - (int) q("SELECT COUNT(*) FROM hibp_emails WHERE checked_at IS NOT NULL AND source <> 'domain'")->fetchColumn()]);

    case 'hibp_overview':
        if (!Hibp::enabled()) {
            out(['error' => t('De controle op datalekken staat uit')], 422);
        }
        out(Hibp::overview());

    case 'hibp_handle':
        $in = input();
        $done = !empty($in['handled']);
        q('UPDATE hibp_breaches SET handled_at = ?, handled_by = ? WHERE email = ? AND name = ?',
            [$done ? now() : null, $done ? $user['username'] : null, (string) ($in['email'] ?? ''), (string) ($in['name'] ?? '')]);
        out(['ok' => true]);

    case 'port_scan':
        $in = input();
        $host = q('SELECT id, address, mode FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$host) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        try {
            $ports = PortScan::presetPorts((string) ($in['preset'] ?? 'common'), (string) ($in['ports'] ?? ''));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        if (!$ports) {
            out(['error' => t('Geen poorten opgegeven')], 422);
        }
        session_write_close(); // andere verzoeken niet blokkeren tijdens de scan
        set_time_limit(180);
        $start = microtime(true);
        try {
            $open = PortScan::scan($host['address'], $ports, max(0.3, min(5.0, (float) ($in['timeout'] ?? 1.5))));
        } catch (RuntimeException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        $monitored = array_map('intval', q("SELECT target FROM checks WHERE host_id = ? AND type = 'tcp'", [$host['id']])->fetchAll(PDO::FETCH_COLUMN));
        $listening = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'ports'", [$host['id']])->fetchColumn(), true) ?: [];
        foreach ($listening as &$lp) {
            $lp['service'] = PortScan::serviceName((int) $lp['port']);
        }
        unset($lp);
        out([
            'scanned' => count($ports),
            'seconds' => round(microtime(true) - $start, 2),
            'open' => $open,
            'monitored' => $monitored,
            'listening' => $host['mode'] === 'agent' ? $listening : null,
        ]);

    case 'api_keys':
        out(q('SELECT id, name, key_hint, last_used, created_at, rights, allow_from, expires_at, created_by FROM api_keys ORDER BY id')->fetchAll());

    // Sleutel maken of aanpassen: rechten, alleen vanaf (IP's/netwerken) en geldigheid (6, 12 of 24 maanden)
    case 'api_key_create':
    case 'api_key_update':
        $in = input();
        $rights = array_values(array_intersect(API_RIGHTS, array_map('strval', (array) ($in['rights'] ?? []))));
        if (!$rights) {
            out(['error' => t('Kies minstens één recht')], 422);
        }
        $from = implode(' ', array_slice(array_filter(parse_list(mb_substr((string) ($in['allow_from'] ?? ''), 0, 500)),
            fn($x) => @inet_pton(explode('/', $x)[0]) !== false), 0, 20));
        $months = (int) ($in['months'] ?? 0);
        if ($months && !in_array($months, [6, 12, 24], true)) {
            out(['error' => t('Kies 6, 12 of 24 maanden')], 422);
        }
        if ($action === 'api_key_create') {
            $name = trim((string) ($in['name'] ?? ''));
            if ($name === '') {
                out(['error' => t('Geef de sleutel een naam (bv. "Home Assistant")')], 422);
            }
            if (in_array('ids_block', $rights, true)) {
                $confirm('BLOKKEREN');
            }
            $key = 'rzm_' . bin2hex(random_bytes(20));
            q('INSERT INTO api_keys (name, key_hash, key_hint, rights, allow_from, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                mb_substr($name, 0, 100), hash('sha256', $key), substr($key, 0, 8) . '…', implode(',', $rights), $from ?: null,
                date('Y-m-d H:i:s', strtotime('+' . ($months ?: 12) . ' months')), $user['username'],
            ]);
            AgentCommands::audit($user['username'], 'API-sleutel gemaakt', "$name: " . implode(', ', $rights) . ($from ? " · alleen vanaf $from" : '') . ' · ' . ($months ?: 12) . ' maanden');
            out(['ok' => true, 'key' => $key]);
        }
        $k = q('SELECT * FROM api_keys WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
        if (!$k) {
            out(['error' => t('Sleutel niet gevonden')], 404);
        }
        // Blokkeren erbij geven vraagt om je wachtwoord, net als bij een nieuwe sleutel
        if (in_array('ids_block', $rights, true) && !in_array('ids_block', explode(',', (string) $k['rights']), true)) {
            $confirm('BLOKKEREN');
        }
        q('UPDATE api_keys SET rights = ?, allow_from = ?, expires_at = ? WHERE id = ?', [implode(',', $rights), $from ?: null,
            $months ? date('Y-m-d H:i:s', strtotime("+$months months")) : $k['expires_at'], $k['id']]);
        AgentCommands::audit($user['username'], 'API-sleutel aangepast', "{$k['name']}: " . implode(', ', $rights) . ($from ? " · alleen vanaf $from" : '')
            . ($months ? " · verlengd met $months maanden" : ''));
        out(['ok' => true]);

    case 'api_key_delete':
        $k = q('SELECT name FROM api_keys WHERE id = ?', [(int) (input()['id'] ?? 0)])->fetchColumn();
        q('DELETE FROM api_keys WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        if ($k !== false) {
            AgentCommands::audit($user['username'], 'API-sleutel ingetrokken', (string) $k);
        }
        out(['ok' => true]);

    // ---------- Gebruikers en tweestapsverificatie ----------
    case 'users':
        $rows = q('SELECT id, username, email, role, totp_secret IS NOT NULL AS totp, created_at, last_login, locked_at, locked_until, locked_ip,
                          local_only, hibp, pw_pwned, pw_pwned_at,
                          (SELECT COUNT(*) FROM remember_tokens r WHERE r.user_id = users.id) AS remembered FROM users'
            . ($isAdmin ? '' : ' WHERE id = ' . (int) $user['id']) . ' ORDER BY username')->fetchAll();
        $perUser = [];
        foreach (session_list($isAdmin ? null : (int) $user['id']) as $s) {
            if ($s['user_id'] !== null) {
                $perUser[$s['user_id']] = ($perUser[$s['user_id']] ?? 0) + 1;
            }
        }
        foreach ($rows as &$r) {
            $r['devices'] = $perUser[(int) $r['id']] ?? 0;   // ingelogde apparaten (1.79.0)
            $r['totp'] = (bool) $r['totp'];
            $r['me'] = (int) $r['id'] === $user['id'];
            $r['local_only'] = (bool) $r['local_only'];
            $r['hibp'] = (bool) $r['hibp'];
            $r['locked'] = Security::accountLocked($r);
            if (!$r['locked']) {
                $r['locked_at'] = $r['locked_until'] = $r['locked_ip'] = null;
            }
        }
        unset($r);
        out(['users' => $rows, 'hibp' => Hibp::enabled(), 'internal' => Https::internal()]);

    // Per account: alleen vanaf de interne netwerken, en de datalek-controle (alleen beheerders)
    case 'user_flags':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $u = q('SELECT id, username, role, local_only, hibp FROM users WHERE id = ?', [$id])->fetch();
        if (!$u) {
            out(['error' => t('Gebruiker niet gevonden')], 404);
        }
        if (array_key_exists('local_only', $in)) {
            $lo = !empty($in['local_only']);
            if ($lo && $id === $user['id'] && !Https::internal()) {
                out(['error' => t('Je zit nu buiten je interne netwerken: dan sluit je jezelf buiten')], 422);
            }
            if ($lo && $u['role'] === 'admin' && !(int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0 AND id <> ?", [$id])->fetchColumn()) {
                out(['error' => t('Er moet minstens één beheerder overblijven die ook van buiten mag inloggen')], 422);
            }
            q('UPDATE users SET local_only = ? WHERE id = ?', [$lo ? 1 : 0, $id]);
            AgentCommands::audit($user['username'], 'Alleen lokaal inloggen', "{$u['username']} = " . ($lo ? 'aan' : 'uit'));
        }
        if (array_key_exists('hibp', $in)) {
            $on = !empty($in['hibp']);
            // Uit: niets meer controleren en geen verplicht wijzigen; aan: bij de volgende login opnieuw controleren
            q('UPDATE users SET hibp = ?, pw_pwned = NULL, pw_pwned_at = NULL, pw_must_change = 0 WHERE id = ?', [$on ? 1 : 0, $id]);
            AgentCommands::audit($user['username'], 'Datalek-controle', "{$u['username']} = " . ($on ? 'aan' : 'uit'));
        }
        out(['ok' => true]);

    // Voorkeuren van de gebruiker (1.71.0): open_mode (closed | smart), fx (filters per pagina), fxviews (opgeslagen filters)
    case 'prefs_save':
        if (empty($user['id'])) {
            out(['error' => t('Zonder account worden voorkeuren alleen in deze browser bewaard')], 422);
        }
        $in = input();
        $key = (string) ($in['key'] ?? '');
        if (!in_array($key, ['open_mode', 'fx', 'fxviews', 'dash_filter', 'alert_filter', 'vuln_open'], true)) {
            out(['error' => t('Onbekende voorkeur')], 422);
        }
        $prefs = json_decode((string) q('SELECT prefs FROM users WHERE id = ?', [$user['id']])->fetchColumn(), true) ?: [];
        $prefs[$key] = match ($key) {
            'open_mode' => $in['value'] === 'smart' ? 'smart' : 'closed',
            'dash_filter', 'alert_filter' => preg_match('/^[a-z:]{1,20}$/', (string) $in['value']) ? (string) $in['value'] : 'all',   // keuze bovenaan het dashboard (1.75.0) en de meldingen (1.84.0)
            // Open blokken op Kwetsbaarheden (servers) en Datalekken (e-mailadressen), 1.93.0
            'vuln_open' => ['h' => array_slice(array_values(array_unique(array_map('intval', (array) ($in['value']['h'] ?? [])))), -500),
                'e' => array_slice(array_values(array_unique(array_map(static fn($e) => mb_substr((string) $e, 0, 254), (array) ($in['value']['e'] ?? [])))), -500)],
            default => (array) ($in['value'] ?? []),
        };
        $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 200000) {
            out(['error' => t('Te veel opgeslagen filters')], 422);
        }
        q('UPDATE users SET prefs = ? WHERE id = ?', [$json, $user['id']]);
        out(['ok' => true]);

    // Nieuw wachtwoord kiezen omdat het huidige in een datalek staat; een gelekt wachtwoord wordt hier geweigerd
    case 'pw_forced':
        $new = (string) (input()['new'] ?? '');
        if (strlen($new) < 8) {
            out(['error' => t('Nieuw wachtwoord moet minimaal 8 tekens zijn')], 422);
        }
        $hash = (string) q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
        if (password_verify($new, $hash)) {
            out(['error' => t('Kies een ander wachtwoord dan je huidige')], 422);
        }
        $n = Hibp::pwnedCount($new);
        if ($n !== null && $n >= Hibp::threshold()) {
            out(['error' => t('Dit wachtwoord staat ook in datalekken ({n}×). Kies een ander, bv. een zin van een paar woorden.', ['n' => I18n::lang() === 'en' ? number_format($n) : number_format($n, 0, ',', '.')])], 422);
        }
        q('UPDATE users SET password_hash = ?, pw_changed_at = ?, pw_must_change = 0, pw_pwned = ?, pw_pwned_at = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), now(), $n === null ? null : 0, $n === null ? null : now(), $user['id']]);
        $_SESSION['auth_at'] = time();
        session_logout('user', (int) $user['id']);   // andere sessies en onthouden apparaten; deze blijft
        out(['ok' => true]);

    // ---------- Onzichtbaar van buiten (1.86.0): toegestane apparaten en passkeys ----------
    case 'my_access':
        $uid = (int) ($user['id'] ?? 0);
        $dev = Stealth::device();
        out([
            'devices' => $uid ? Stealth::devices($uid) : [], 'passkeys' => $uid ? Passkey::list($uid) : [],
            'current' => $dev && (int) $dev['user_id'] === $uid ? (int) $dev['id'] : null,
            'can_trust' => $uid > 0 && !is_lan_session() && (!Stealth::outside() || Stealth::viaDoor($uid)),
            'internal' => !Stealth::outside(), 'ui_locked' => Stealth::uiLocked(), 'door' => setting('stealth_door', '1') === '1',
            'passkey_possible' => Passkey::possible(), 'passkeys_enabled' => Passkey::enabled(), 'rp_id' => Passkey::rpId(),
            'door_url' => $uid ? (Https::adminUrl() ?: rtrim((string) config('base_url', ''), '/')) . '/?nood=' . rawurlencode((string) $user['username']) : null,
        ]);

    case 'device_trust':
        $uid = (int) ($user['id'] ?? 0);
        if (!$uid || is_lan_session()) {
            out(['error' => t('Log in met je eigen account')], 403);
        }
        if (Stealth::outside() && !Stealth::viaDoor($uid)) {
            out(['error' => t('Een apparaat toestaan kan alleen thuis (vanaf je eigen netwerk), of na een noodlink')], 403);
        }
        $confirm('TOESTAAN');
        out(['ok' => true, 'id' => Stealth::trust($user, (string) (input()['name'] ?? ''))]);

    case 'device_revoke':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Stealth::revoke((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'device_rename':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Stealth::rename((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], (string) ($in['name'] ?? '')) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'passkey_reg_options':
        if (empty($user['id']) || is_lan_session()) {
            out(['error' => t('Log in met je eigen account')], 403);
        }
        if (!Passkey::possible()) {
            out(['error' => t('Passkeys werken alleen via https met een domeinnaam (bv. je beheeradres), niet via een IP-adres')], 422);
        }
        $confirm('PASSKEY');
        out(Passkey::registerOptions($user));

    case 'passkey_reg_finish':
        $in = input();
        $dev = Stealth::device();
        $r = Passkey::registerFinish($user, $in, $dev && (int) $dev['user_id'] === (int) $user['id'] ? (int) $dev['id'] : null);
        is_int($r) ? out(['ok' => true, 'id' => $r]) : out(['error' => $r], 422);

    case 'passkey_delete':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Passkey::remove((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'passkey_rename':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Passkey::rename((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], (string) ($in['name'] ?? '')) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    // Beheerders: alle toegestane apparaten en passkeys, en de instellingen
    case 'stealth_info':
        out([
            'hide' => Stealth::hide(), 'ui' => Stealth::uiLocked(), 'countries' => setting('stealth_countries', '1') === '1', 'door' => setting('stealth_door', '1') === '1',
            'passkeys' => Passkey::enabled(), 'allowed_countries' => IpIntel::allowedCountries(), 'geo_data' => IpIntel::lookupCountry('8.8.8.8') !== null,
            'devices_all' => Stealth::devices(null), 'passkeys_all' => Passkey::list(null), 'internal' => !Stealth::outside(),
            'requests_on' => setting('stealth_requests', '1') === '1', 'requests' => Stealth::requests(),
            'users' => q('SELECT id, username, role FROM users ORDER BY username')->fetchAll(),
        ]);

    // 1.103.0: netwerkscan – alle apparaten op je lokale netwerken
    case 'net_devices':
        $nets = NetScan::networks();
        out(['enabled' => NetScan::enabled(), 'devices' => NetScan::list(), 'state' => NetScan::state(), 'networks' => $nets,
            'risky_reasons' => NetScan::riskyReasons(), 'kinds' => NetScan::KINDS, 'now' => now(), 'vendors' => (int) q('SELECT COUNT(*) FROM mac_vendors')->fetchColumn(),
            'links' => ['unifi' => NetLinks::unifiOn(), 'unifi_block' => NetLinks::unifiCanBlock(), 'adguard' => NetLinks::adguardOn()], 'my_ip' => client_ip()]);

    // 1.106.0: 📊 Analyse – alles voor één periode (5 minuten bewaard per periode), en het maandrapport
    case 'advice_list':   // 1.108.0: slimme voorstellen en vaste onderhoudsvensters
        out(['list' => Advice::list(), 'windows' => Advice::windows(), 'last' => (string) setting('advice_day', '')]);

    case 'advice_decide':
        $in = input();
        try {
            out(['ok' => true, 'message' => Advice::decide((int) ($in['id'] ?? 0), !empty($in['apply']))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'advice_run':   // nu opnieuw bepalen (anders elke nacht)
        out(['ok' => true, 'n' => Advice::cron(true)]);

    case 'window_save':
        try {
            out(['ok' => true, 'id' => Advice::windowSave(input())]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'window_delete':
        Advice::windowDelete((int) (input()['id'] ?? 0));
        out(['ok' => true]);

    // ---------- 1.110.0: endpoint-bewaking ----------
    case 'edr_host':   // per server: aan/uit, sensoren, wat ontbreekt
        $h = q('SELECT * FROM hosts WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        out(Edr::status($h) + ['ids' => Ids::enabled(), 'global' => setting('edr_on', '1') === '1', 'blocking' => Ids::blocking(),
            'install' => json_decode((string) ($h['vuln_upgrade_status'] ?? ''), true)]);

    case 'edr_host_set':
        $in = input();
        $h = q('SELECT id, name FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        q('UPDATE hosts SET edr_on = ? WHERE id = ?', [!empty($in['on']) ? 1 : 0, $h['id']]);
        AgentCommands::audit($user['username'], !empty($in['on']) ? 'Endpoint-bewaking aan' : 'Endpoint-bewaking uit', (string) $h['name']);
        out(['ok' => true]);

    case 'edr_install':   // ontbrekend onderdeel via de agent installeren (bevestigen, net als bijwerken)
        $in = input();
        $h = q('SELECT * FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $confirm('INSTALLEREN');
        $own = mb_substr((string) ($in['pkgs'] ?? ''), 0, 1000);
        $r = Edr::install($h, (string) ($in['comp'] ?? ''), $user['username'], $own);
        if ($r !== true) {
            out(['error' => $r], 422);
        }
        AgentCommands::audit($user['username'], 'Endpoint-bewaking: installeren', $h['name'] . ': ' . ((string) ($in['comp'] ?? '')) . (trim($own) !== '' ? ' (' . trim($own) . ')' : ''));
        out(['ok' => true]);

    case 'edr_feed':   // Live: de laatste gebeurtenissen
        out(['events' => Edr::feed((int) ($_GET['limit'] ?? 200), ($hid = (int) ($_GET['host'] ?? 0)) ?: null, ($_GET['hits'] ?? '') === '1', (int) ($_GET['after'] ?? 0), ($_GET['own'] ?? '1') !== '0'),
            'on' => Ids::enabled() && setting('edr_on', '1') === '1']);

    // ---------- 1.115.0: bescherming ----------
    case 'ids_protect':
        out(Protect::overview());

    // ---------- 1.112.0: dreigingsinformatie ----------
    case 'ti_status':
        out(ThreatIntel::status() + ['on' => ThreatIntel::on(), 'dns' => DnsWatch::status()]);

    case 'ti_test':   // werkt de sleutel? (eerst opslaan, dan testen)
        out(ThreatIntel::test((string) (input()['p'] ?? '')));

    case 'ti_lists':   // lijsten nu bijwerken
        out(['msg' => ThreatIntel::updateLists()]);

    // ---------- 1.111.0: afwijkingen, ketens en MITRE ----------
    case 'ids_anom':   // Afwijkingen: leerstatus per server, ketens en afwijkingen
        $hid = (int) ($_GET['host'] ?? 0) ?: null;
        $days = (int) ($_GET['days'] ?? 7) ?: 7;
        out(['hosts' => Anomaly::hosts(), 'chains' => Chain::list(max(30, $days), $hid), 'anomalies' => Anomaly::list($days, $hid),
            'on' => Ids::enabled() && setting('edr_on', '1') === '1', 'anom' => Anomaly::on(), 'chain' => Chain::on(), 'learn_days' => Anomaly::learnDays(),
            'min' => (int) setting('edr_anom_min', '40'), 'scenarios' => array_map(static fn($s) => ['title' => t($s['title']), 'what' => t($s['what'])], Chain::scenarios())]);

    case 'ids_mitre':   // MITRE ATT&CK: wat er gezien is per tactiek en techniek, en de dekking van de regels
        $hid = (int) ($_GET['host'] ?? 0) ?: null;
        $days = (int) ($_GET['days'] ?? 30) ?: 30;
        if (isset($_GET['tech']) || isset($_GET['tactic'])) {
            out(['signals' => Chain::signals($days, $hid, ($_GET['tactic'] ?? '') ?: null, isset($_GET['tech']) ? (string) $_GET['tech'] : null)]);
        }
        out(Chain::overview($days, $hid));

    case 'edr_learn':   // leren afronden of opnieuw beginnen
        $in = input();
        $h = q('SELECT id, name FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $finish = ($in['action'] ?? '') === 'finish';
        Anomaly::relearn((int) $h['id'], $finish);
        AgentCommands::audit($user['username'], $finish ? 'Leren afgerond (afwijkingen)' : 'Opnieuw leren (afwijkingen)', (string) $h['name']);
        out(['ok' => true]);

    case 'edr_rules':   // Regels: eigen en Sigma, met aan/uit
        $off = array_flip((array) json_decode((string) setting('edr_rules_off', '[]'), true));
        $hits = array_column(q("SELECT rule, COUNT(*) AS n FROM edr_events WHERE rule IS NOT NULL GROUP BY rule")->fetchAll(), 'n', 'rule');
        $list = [];
        foreach (EdrRules::all() as $r) {
            $list[] = ['id' => $r['id'], 'src' => 'rzmon', 'title' => t($r['title']), 'what' => t($r['what']), 'sev' => $r['sev'], 'act' => $r['act'], 'tags' => $r['tags'],
                'kinds' => $r['kinds'], 'on' => !isset($off[$r['id']]), 'hits' => (int) ($hits[$r['id']] ?? 0)];
        }
        foreach (Sigma::rules() as $r) {
            $list[] = ['id' => $r['id'], 'src' => 'sigma', 'title' => $r['title'], 'what' => $r['desc'], 'sev' => $r['level'] >= 2 ? 2 : 1, 'act' => $r['level'] >= 3 ? ['kill'] : [],
                'tags' => $r['tags'], 'kinds' => $r['kinds'], 'on' => !isset($off[$r['id']]), 'hits' => (int) ($hits[$r['id']] ?? 0), 'author' => $r['author'], 'url' => $r['src'], 'level' => $r['level']];
        }
        out(['rules' => $list, 'sigma_on' => setting('edr_sigma', '1') === '1']);

    case 'edr_rule_set':
        $in = input();
        $id = (string) ($in['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{3,64}$/', $id)) {
            out(['error' => t('Onbekende regel')], 422);
        }
        $off = (array) json_decode((string) setting('edr_rules_off', '[]'), true);
        $off = array_values(array_unique(!empty($in['on']) ? array_diff($off, [$id]) : [...$off, $id]));
        save_setting('edr_rules_off', json_encode($off));
        Edr::reset();
        out(['ok' => true]);

    case 'status_get':   // 1.107.0: statuspagina (instellingen)
        $base = rtrim((string) config('base_url', ''), '/');
        $ext = rtrim((string) config('external_url', ''), '/');
        $path = '/status.php?k=' . StatusPage::token();
        out(['on' => setting('status_on', '0') === '1', 'title' => (string) setting('status_title', ''), 'note' => (string) setting('status_note', ''),
            'items' => StatusPage::items(), 'url' => $base . $path, 'has_base' => $base !== '',
            'url_ext' => $ext !== '' ? $ext . $path : null, 'link' => setting('status_link', 'local') === 'external' && $ext !== '' ? 'external' : 'local']);

    case 'status_save':
        StatusPage::save(input());
        out(['ok' => true]);

    case 'status_link':   // 1.128.0: lokaal of extern adres in de geheime link
        $in = input();
        if (($in['link'] ?? '') === 'external' && trim((string) config('external_url', '')) === '') {
            out(['error' => t('Vul eerst de externe URL in (Instellingen → Server en PHP)')], 422);
        }
        save_setting('status_link', ($in['link'] ?? '') === 'external' ? 'external' : 'local');
        out(['ok' => true]);

    case 'status_token_new':
        StatusPage::newToken();
        out(['ok' => true]);

    case 'rev':   // 1.107.0: live bijwerken: een klein kenmerk; verandert het, dan haalt de browser de stand opnieuw op
        // 1.109.0: ook nieuwe meetwaarden (MAX(id) van results is snel: primaire sleutel), zodat waarden live meelopen
        // 1.119.0: per onderdeel een kenmerk, zodat de browser alleen herlaadt wat veranderde (de IDS meldt bijna elke
        // minuut nieuwe gebeurtenissen; daarvoor hoeven het dashboard en de meldingen niet opnieuw)
        $r = q("SELECT (SELECT MAX(id) FROM status_changes), (SELECT MAX(id) FROM change_log), (SELECT MAX(id) FROM results),
                       (SELECT COUNT(*) FROM checks WHERE acked_at IS NOT NULL), (SELECT COUNT(*) FROM checks WHERE maintenance_until > NOW()),
                       (SELECT COUNT(*) FROM checks WHERE enabled = 1), (SELECT value FROM settings WHERE name = 'cron_last_run'),
                       (SELECT MAX(id) FROM alerts), (SELECT CONCAT(COUNT(*), '.', SUM(status = 'open'), '.', COALESCE(MAX(id), 0), '.', COALESCE(MAX(last_seen), ''), '.', COALESCE(MAX(handled_at), '')) FROM ids_incidents),
                       (SELECT CONCAT(COUNT(*), '.', COALESCE(MAX(id), 0)) FROM ids_blocks WHERE removed_at IS NULL), (SELECT MAX(id) FROM edr_events)")->fetch(PDO::FETCH_NUM);
        $h = static fn(array $x) => substr(md5(implode('|', array_map('strval', $x))), 0, 10);
        out(['rev' => $h(array_slice($r, 0, 7)), 'parts' => ['a' => (string) $r[7], 'i' => $h([$r[8], $r[9]]), 'e' => (string) $r[10]]]);

    case 'smart_proposals':   // 1.107.0: handmatige checks die op slim kunnen (bestaande checks blijven handmatig tot je ze omzet)
        out(['list' => Smart::proposals(), 'default' => (string) setting('thresh_default', 'guard'), 'sens' => (string) setting('smart_sens', 'normal')]);

    case 'smart_set':
        $in = input();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
        try {
            $n = Smart::setMode($ids, (string) ($in['mode'] ?? ''));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        out(['ok' => true, 'n' => $n]);

    case 'analytics':
        $period = (string) ($_GET['period'] ?? '30d');
        if (!preg_match('/^(1d|7d|30d|90d|365d|\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2})$/', $period)) {
            $period = '30d';
        }
        $cf = sys_get_temp_dir() . '/rzmon-analytics-' . md5(SM_ROOT . '|' . $period . '|' . I18n::lang()) . '.json';
        if (empty($_GET['fresh']) && is_file($cf) && filemtime($cf) > time() - 300 && ($c = json_decode((string) file_get_contents($cf), true))) {
            out($c + ['cached' => true]);
        }
        if (!q('SELECT 1 FROM stats_daily LIMIT 1')->fetchColumn()) {
            @set_time_limit(300);
            Analytics::cron();   // de eerste keer meteen berekenen, niet op cron wachten
        }
        $r = Analytics::report($period) + ['report_monthly' => Analytics::reportOn(), 'mail' => setting('email_enabled', '0') === '1' && setting('mail_to', '') !== ''];
        @file_put_contents($cf, json_encode($r));
        out($r);

    case 'analytics_report_send':
        $m = (string) (input()['month'] ?? date('Y-m', strtotime('first day of last month')));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            out(['error' => t('Ongeldige maand')], 422);
        }
        $r = Analytics::sendMonthly($m);
        $r['ok'] ? out(['ok' => true]) : out(['error' => $r['error'] ?? t('Versturen mislukt')], 422);

    // 1.105.0: Proxmox VE – overzicht van een node (of de cluster) en losse nodes als cluster aanmerken
    case 'pve_overview':
        $o = Pve::overview((int) ($_GET['host_id'] ?? 0));
        $o ? out($o) : out(['error' => t('Niet gevonden')], 404);

    case 'pve_cluster_set':
        $in = input();
        Pve::setCluster((int) ($in['host_id'] ?? 0), (string) ($in['name'] ?? ''), $user['username']);
        out(['ok' => true]);

    // 1.104.0: UniFi en AdGuard Home testen, een apparaat weren of weer toelaten, en de domeinen van een apparaat
    case 'net_links_test':
        out(NetLinks::test());

    case 'net_block':
        $in = input();
        $confirm('WEREN');
        $r = NetScan::block((int) ($in['id'] ?? 0), ['unifi' => !empty($in['unifi']), 'adguard' => !empty($in['adguard']), 'rzmon' => !empty($in['rzmon'])],
            max(0, min(3650, (int) ($in['days'] ?? 1))), (string) ($in['reason'] ?? ''), $user['username'], client_ip());
        $r['ok'] ? out($r) : out(['error' => implode(' · ', $r['errors']) ?: t('Niets gekozen om mee te weren')], 422);

    case 'net_unblock':
        $r = NetScan::unblock((int) (input()['id'] ?? 0), $user['username']);
        out($r);

    case 'net_domains':
        $d = q('SELECT ip FROM net_devices WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$d) {
            out(['error' => t('Niet gevonden')], 404);
        }
        if (!NetLinks::adguardOn()) {
            out(['error' => t('AdGuard Home is niet ingesteld (Instellingen → Koppelingen → UniFi en AdGuard Home)')], 422);
        }
        out(NetLinks::adguardDomains((string) $d['ip']));

    case 'net_device_save':
        $in = input();
        NetScan::save((int) ($in['id'] ?? 0), $in, $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'net_approve_all':
        out(['ok' => true, 'n' => NetScan::approveAll($user['username'])]);

    case 'net_forget':
        NetScan::forget((int) (input()['id'] ?? 0), $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'net_scan_now':
        if (!NetScan::networks()['scan']) {
            out(['error' => t('Geen netwerk om te scannen: zet je netwerk (bv. 192.168.1.0/24) bij Toegang → Lokale netwerken')], 422);
        }
        @set_time_limit(120);
        $msg = NetScan::sweep();
        if (!empty(input()['deep'])) {
            $msg .= ' · ' . NetScan::deep();
        }
        out(['ok' => true, 'message' => $msg]);

    // 1.102.0: apparaten die van buiten geweigerd werden (wachtende apparaten)
    case 'device_request_approve':
        $in = input();
        $confirm('TOESTAAN');
        ($err = Stealth::approveRequest((int) ($in['id'] ?? 0), (int) ($in['user_id'] ?? 0), (string) ($in['name'] ?? ''), $user['username'])) === null
            ? out(['ok' => true]) : out(['error' => $err], 404);

    case 'device_request_ignore':
        Stealth::ignoreRequest((int) (input()['id'] ?? 0), $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'stealth_save':
        $in = input();
        $map = ['hide' => 'stealth_hide', 'ui' => 'stealth_ui', 'countries' => 'stealth_countries', 'door' => 'stealth_door', 'passkeys' => 'passkeys_enabled', 'requests' => 'stealth_requests'];
        // Minder veilig maken (niet herkenbaar of de webinterface op slot uitzetten): eerst bevestigen
        foreach (['hide', 'ui'] as $k) {
            if (array_key_exists($k, $in) && empty($in[$k]) && setting($map[$k], $k === 'hide' ? '1' : '0') === '1') {
                $confirm('UITZETTEN');
            }
        }
        foreach ($map as $k => $name) {
            if (array_key_exists($k, $in)) {
                save_setting($name, !empty($in[$k]) ? '1' : '0');
                AgentCommands::audit($user['username'], 'Onzichtbaar van buiten', "$name = " . (!empty($in[$k]) ? 'aan' : 'uit'));
            }
        }
        out(['ok' => true]);

    case 'my_logins':
        out(!empty($user['id']) && !is_lan_session() ? Security::loginHistory((int) $user['id']) : []);

    case 'user_unlock':
        Security::unlockUser((int) (input()['id'] ?? 0), $user['username']);
        out(['ok' => true]);

    case 'user_create':
        $in = input();
        $email = trim((string) ($in['email'] ?? ''));
        try {
            if (q('SELECT 1 FROM users WHERE username = ?', [trim((string) ($in['username'] ?? ''))])->fetchColumn()) {
                throw new InvalidArgumentException(t('Deze gebruikersnaam bestaat al'));
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(t('Ongeldig e-mailadres'));
            }
            if ($email !== '' && q('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)', [$email])->fetchColumn()) {
                throw new InvalidArgumentException(t('Dit e-mailadres hoort al bij een ander account'));
            }
            Installer::saveUser(trim((string) ($in['username'] ?? '')), (string) ($in['password'] ?? ''),
                in_array($in['role'] ?? '', array_keys(ROLES), true) ? $in['role'] : 'user');
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        $id = (int) q('SELECT id FROM users WHERE username = ?', [trim((string) ($in['username'] ?? ''))])->fetchColumn();
        if ($email !== '') {
            q('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
        }
        out(['ok' => true, 'id' => $id, 'pwned' => Hibp::checkUser($id, (string) ($in['password'] ?? '')) ?? 0]);

    case 'user_password':
        $in = input();
        if (strlen((string) ($in['password'] ?? '')) < 8) {
            out(['error' => t('Wachtwoord moet minimaal 8 tekens zijn')], 422);
        }
        q('UPDATE users SET password_hash = ?, pw_changed_at = ?, pw_must_change = 0 WHERE id = ?', [password_hash((string) $in['password'], PASSWORD_DEFAULT), now(), (int) ($in['id'] ?? 0)]);
        if ((int) ($in['id'] ?? 0) === $user['id']) {
            $_SESSION['auth_at'] = time();   // eigen account: deze sessie blijft
        }
        // Nieuw wachtwoord gezet door een beheerder: sessies en onthouden apparaten van die gebruiker weg (je eigen sessie blijft)
        session_logout('user', (int) ($in['id'] ?? 0));
        out(['ok' => true, 'pwned' => Hibp::checkUser((int) ($in['id'] ?? 0), (string) $in['password']) ?? 0]);

    case 'user_email':
        // E-mailadres voor "wachtwoord vergeten" (beheerder: iedereen; anderen: alleen zichzelf)
        $id = (int) (input()['id'] ?? 0);
        $ownOnly($id);
        $email = trim((string) (input()['email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            out(['error' => t('Ongeldig e-mailadres')], 422);
        }
        if ($email !== '' && q('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?', [$email, $id])->fetchColumn()) {
            out(['error' => t('Dit e-mailadres hoort al bij een ander account')], 422);
        }
        q('UPDATE users SET email = ? WHERE id = ?', [$email !== '' ? $email : null, $id]);
        out(['ok' => true]);

    case 'pw_reset_info':
        out(['unavailable' => PasswordReset::unavailable(),
             'log' => q('SELECT l.created_at, l.ident, l.ip, l.event, u.username FROM password_reset_log l
                         LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 50')->fetchAll()]);

    case 'user_role':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $role = (string) ($in['role'] ?? '');
        if (!isset(ROLES[$role])) {
            out(['error' => t('Onbekende rol')], 422);
        }
        if ($id === $user['id']) {
            out(['error' => t('Je kunt je eigen rol niet wijzigen (laat een andere beheerder dat doen)')], 422);
        }
        if ($role !== 'admin' && q("SELECT role FROM users WHERE id = ?", [$id])->fetchColumn() === 'admin'
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            out(['error' => t('Er moet minimaal één beheerder overblijven')], 422);
        }
        if ($role !== 'admin' && !(int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0 AND id <> ?", [$id])->fetchColumn()
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0")->fetchColumn()) {
            out(['error' => t('Er moet minstens één beheerder overblijven die ook van buiten mag inloggen')], 422);
        }
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        out(['ok' => true]);

    case 'user_delete':
        $id = (int) (input()['id'] ?? 0);
        if ($id === $user['id']) {
            out(['error' => t('Je kunt jezelf niet verwijderen')], 422);
        }
        if ((int) q('SELECT COUNT(*) FROM users')->fetchColumn() <= 1) {
            out(['error' => t('Er moet minimaal één gebruiker overblijven')], 422);
        }
        if (q("SELECT role FROM users WHERE id = ?", [$id])->fetchColumn() === 'admin'
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            out(['error' => t('Er moet minimaal één beheerder overblijven')], 422);
        }
        if (!(int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0 AND id <> ?", [$id])->fetchColumn()
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0")->fetchColumn()) {
            out(['error' => t('Er moet minstens één beheerder overblijven die ook van buiten mag inloggen')], 422);
        }
        q('DELETE FROM users WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'totp_setup':
        // Stap 1: nieuw geheim (nog niet actief) → QR-code scannen
        $ownOnly((int) (input()['id'] ?? 0));
        $u = q('SELECT id, username FROM users WHERE id = ?', [(int) (input()['id'] ?? 0)])->fetch();
        if (!$u) {
            out(['error' => t('Gebruiker niet gevonden')], 404);
        }
        $secret = Totp::newSecret();
        $_SESSION['totp_setup'][(int) $u['id']] = $secret;
        out(['ok' => true, 'secret' => $secret, 'uri' => Totp::uri($secret, $u['username'])]);

    case 'totp_confirm':
        // Stap 2: pas actief na een geldige code, zodat je jezelf niet buitensluit
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $ownOnly($id);
        $secret = $_SESSION['totp_setup'][$id] ?? null;
        if (!$secret) {
            out(['error' => t('Begin opnieuw met instellen')], 422);
        }
        $step = Totp::verify($secret, (string) ($in['code'] ?? ''));
        if ($step === null) {
            out(['error' => t('Code klopt niet. Staat de klok van je telefoon goed?')], 422);
        }
        q('UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?', [$secret, $step, $id]);
        unset($_SESSION['totp_setup'][$id]);
        out(['ok' => true]);

    // ---------- Onthouden apparaten ----------
    case 'devices':
        $uid = (int) ($_GET['user_id'] ?? $user['id']);
        $ownOnly($uid);
        $rows = q('SELECT id, device, ip, created_at, last_used FROM remember_tokens WHERE user_id = ? ORDER BY last_used DESC', [$uid])->fetchAll();
        foreach ($rows as &$r) {
            $r['current'] = (int) $r['id'] === (int) ($_SESSION['remember_id'] ?? 0);
        }
        unset($r);
        out($rows);

    case 'device_forget':
        $id = (int) (input()['id'] ?? 0);
        $owner = q('SELECT user_id FROM remember_tokens WHERE id = ?', [$id])->fetchColumn();
        if ($owner === false) {
            out(['error' => t('Apparaat niet gevonden')], 404);
        }
        $ownOnly((int) $owner);
        q('DELETE FROM remember_tokens WHERE id = ?', [$id]);
        q('DELETE FROM user_sessions WHERE remember_id = ? AND id <> ?', [$id, session_current()]);   // ook meteen uitloggen
        if ((int) $owner !== (int) $user['id']) {
            AgentCommands::audit($user['username'], 'Apparaat vergeten', (string) q('SELECT username FROM users WHERE id = ?', [$owner])->fetchColumn());
        }
        out(['ok' => true]);

    // Ingelogde apparaten (1.79.0): je eigen (of als beheerder die van een gebruiker), of van iedereen (alleen beheerders)
    case 'sessions':
        $uid = (int) ($_GET['user_id'] ?? $user['id']);
        $ownOnly($uid);
        out(session_list($uid));

    case 'sessions_all':
        out(['sessions' => session_list(null), 'ttl' => session_ttl()]);

    case 'session_logout':
        $id = (int) (input()['id'] ?? 0);
        $s = q('SELECT user_id, username, device FROM user_sessions WHERE id = ?', [$id])->fetch();
        if (!$s) {
            out(['error' => t('Deze sessie bestaat niet meer')], 404);
        }
        if ($s['user_id'] === null && !$isAdmin) {
            out(['error' => t('Daar heb je geen rechten voor')], 403);
        }
        if ($s['user_id'] !== null) {
            $ownOnly((int) $s['user_id']);
        }
        if ($id === session_current()) {
            out(['error' => t('Dit is je huidige sessie: gebruik Uitloggen in het accountmenu')], 409);
        }
        session_logout('session', $id);
        if ((int) $s['user_id'] !== (int) $user['id']) {
            AgentCommands::audit($user['username'], 'Sessie uitgelogd', "{$s['username']}: {$s['device']}");
        }
        out(['ok' => true]);

    // Alles van een gebruiker uitloggen (sessies en onthouden apparaten), behalve dit apparaat
    case 'device_forget_all':
    case 'sessions_logout_user':
        $uid = (int) (input()['user_id'] ?? $user['id']);
        $ownOnly($uid);
        $n = session_logout('user', $uid);
        if ($uid !== (int) $user['id']) {
            AgentCommands::audit($user['username'], 'Alle sessies uitgelogd', (string) q('SELECT username FROM users WHERE id = ?', [$uid])->fetchColumn());
        }
        out(['ok' => true, 'n' => $n]);

    // Noodknop: iedereen uitloggen behalve dit apparaat (met je wachtwoord)
    case 'sessions_logout_all':
        $confirm('UITLOGGEN');
        $n = session_logout('all');
        AgentCommands::audit($user['username'], 'Iedereen uitgelogd', "$n sessies en apparaten");
        out(['ok' => true, 'n' => $n]);

    case 'totp_reset':
        $ownOnly((int) (input()['id'] ?? 0));
        if ((int) (input()['id'] ?? 0) === (int) $user['id'] && Https::mfaRequired()) {
            out(['error' => t('Van buiten je interne netwerken kun je je eigen 2FA niet uitzetten')], 403);
        }
        q('UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    // ---------- Databasewachtwoord vernieuwen (alleen beheerders) ----------
    case 'setup_info':   // 1.92.0: de setup-pagina in de portal (Instellingen → Systeem)
        out(['checks' => array_map(static fn($c) => ['label' => $c[0], 'ok' => $c[1], 'detail' => $c[2]], Setup::checks(true, null)),
            'php_env' => Setup::phpInfo(), 'cron' => Setup::cronLine(), 'self' => SelfCheck::report(), 'perf' => Perf::report()] + Setup::config());

    case 'db_optimize':   // 1.119.0: de grootste tabellen opnieuw opbouwen (beheerders)
        if ($method !== 'POST') {
            out(['error' => 'POST'], 405);
        }
        session_write_close();
        $r = Perf::optimize();
        AgentCommands::audit($user['username'], 'Database geoptimaliseerd', implode(', ', array_column($r, 'name')));
        out(['ok' => true, 'tables' => $r]);

    case 'setup_db_test':
        $in = input();
        try {
            out(Setup::dbTest($in));
        } catch (PDOException $e) {
            out(['error' => 'Database: ' . $e->getMessage()], 422);
        }

    case 'setup_db_save':
        $confirm('DATABASE');
        $in = input();
        try {
            $r = Setup::dbSave($in);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            out(['error' => 'Database: ' . $e->getMessage()], 422);
        }
        AgentCommands::audit($user['username'], 'Databaseverbinding', ($in['db_user'] ?? '') . '@' . ($in['db_host'] ?? '') . '/' . ($in['db_name'] ?? ''));
        out($r);

    case 'setup_schema':
        Installer::runSchema();
        out(['ok' => true, 'message' => t('Tabellen bijgewerkt')]);

    case 'setup_app_save':
        $in = input();
        try {
            out(Setup::appSave($in));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'db_info':
        $h = Installer::HELPER_DIR;
        $root = realpath(SM_ROOT);
        $db = config('db');
        out([
            'helper' => is_file("$h/helper.installed") && is_writable($h),
            'direct' => Installer::configWritable() || is_writable(SM_CONFIG_FILE),
            'db' => ['host' => $db['host'], 'name' => $db['name'], 'user' => $db['user']],
            'cli_cmd' => "sudo php $root/install/install.php --rotate-db-password",
            'helper_cmd' => "sudo bash $root/install/install-helper.sh",
        ]);

    // ---------------------------------------------------------- IDS
    case 'ids':
        // 1.119.0: standaard de laatste 14 dagen (plus alles wat nog open staat); ouder op verzoek
        $maxDays = Monitor::alertDays();
        $days = max(1, min($maxDays, (int) ($_GET['days'] ?? $maxDays)));
        $hosts = q("SELECT h.id, h.name, h.agent_seen, h.agent_update_via = 'ha' AS ha, h.ids_fim, h.realtime,
                           (SELECT value FROM agent_data ad WHERE ad.host_id = h.id AND ad.metric = 'realtime') AS rt,
                           (SELECT value FROM agent_data ad WHERE ad.host_id = h.id AND ad.metric = 'tools') AS tools, s.fim_count, s.fim_cand, s.baseline_until, s.fw_state, s.fw_error, s.decoy_state, s.last_report
                    FROM hosts h LEFT JOIN ids_state s ON s.host_id = h.id WHERE h.mode = 'agent' AND h.enabled = 1 ORDER BY h.name")->fetchAll();
        $open = q("SELECT host_id, severity, COUNT(*) AS n FROM ids_incidents WHERE status = 'open' GROUP BY host_id, severity")->fetchAll();
        $blocks = Ids::activeBlocks();
        $hard = Hardening::enabled() ? Hardening::scores() : [];
        $iso = q('SELECT id, isolate_until, isolate_by, isolate_state FROM hosts')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($hosts as &$h) {
            $h['hard'] = $hard[(int) $h['id']] ?? null;
            $h['iso'] = ($iso[$h['id']]['isolate_until'] ?? null) ? $iso[$h['id']] : (str_starts_with((string) ($iso[$h['id']]['isolate_state'] ?? ''), 'fout') ? $iso[$h['id']] : null);
            $h['open'] = [1 => 0, 2 => 0];
            foreach ($open as $o) {
                if ((int) $o['host_id'] === (int) $h['id']) {
                    $h['open'][(int) $o['severity']] = (int) $o['n'];
                }
            }
            $h['blocks'] = count(array_filter($blocks, fn($b) => (int) $b['host_id'] === (int) $h['id']));
            $h['ha'] = (bool) $h['ha'];   // Home Assistant-add-on: geen IDS mogelijk (container)
            $h['learning'] = $h['baseline_until'] !== null && strtotime($h['baseline_until']) > time();
        }
        unset($h);
        out([
            'enabled' => Ids::enabled(), 'mode' => (string) setting('ids_mode', 'learn'), 'today' => date('Y-m-d'), 'days' => $days, 'max_days' => $maxDays,
            'modern' => setting('ids_modern', '1') === '1',   // 1.117.0: moderne weergave (anders compact)
            'fim' => Ids::cleanFim(json_decode((string) setting('ids_fim', '[]'), true)),
            'hosts' => $hosts,
            'incidents' => $incs = q('SELECT i.*, h.name AS host_name FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                              WHERE i.created_at >= CURDATE() - INTERVAL ? DAY OR i.status = \'open\' ORDER BY i.created_at DESC, i.id DESC LIMIT 5000', [$days - 1])->fetchAll(),
            'prot' => Protect::describeAll($incs),   // 1.115.0: per incident de kaart en wat RzMon deed
            'blocks' => Ids::readable($blocks),
            'removed' => Ids::readable(q('SELECT b.*, h.name AS host_name FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                            WHERE b.removed_at IS NOT NULL OR (b.until IS NOT NULL AND b.until <= NOW()) ORDER BY COALESCE(b.removed_at, b.until) DESC LIMIT 30')->fetchAll()),
            'my_ip' => client_ip(),
        ]);

    // Toegang: logins, sudo en su (zonder wat inmiddels op de lijst Negeren staat)
    // ---------- Kwetsbaarheden (beheerders en gebruikers; bijwerken en scannen alleen beheerders) ----------
    case 'vulns':
        out(Vulns::enabled() ? Vulns::overview() : ['enabled' => false]);

    case 'vuln_packages':
        out(['packages' => Vulns::packages((int) ($_GET['host_id'] ?? 0))]);

    // Voortgang van "bijwerken" op één server (het venster vraagt dit elke paar seconden)
    case 'vuln_upgrade_status':
        $h = q('SELECT h.agent_version, h.vuln_upgrade_status, i.scanned_at, i.updated_at FROM hosts h LEFT JOIN vuln_inventory i ON i.host_id = h.id WHERE h.id = ?',
            [(int) ($_GET['host_id'] ?? 0)])->fetch();
        $h ? out(['status' => json_decode((string) $h['vuln_upgrade_status'], true), 'scanned_at' => $h['scanned_at'], 'updated_at' => $h['updated_at'],
                  'live' => $h['agent_version'] && version_compare((string) $h['agent_version'], Vulns::PROGRESS_MIN, '>=')])
           : out(['error' => t('Server niet gevonden')], 404);

    case 'vuln_detail':
        $d = Vulns::detail(mb_substr(trim((string) ($_GET['cve'] ?? '')), 0, 80));
        $d ? out($d) : out(['error' => t('Kwetsbaarheid niet gevonden')], 404);

    case 'vuln_ignore':
        $in = input();
        $cve = mb_substr(trim((string) ($in['cve'] ?? '')), 0, 40);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]+$/', $cve)) {
            out(['error' => t('Ongeldige kwetsbaarheid')], 422);
        }
        $pkg = trim((string) ($in['package'] ?? '')) ?: null;
        $hid = (int) ($in['host_id'] ?? 0) ?: null;
        q('INSERT INTO vuln_ignore (cve, package, host_id, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$cve, $pkg !== null ? mb_substr($pkg, 0, 150) : null, $hid, mb_substr(trim((string) ($in['reason'] ?? '')), 0, 300), $user['username'], now()]);
        AgentCommands::audit($user['username'], 'Kwetsbaarheid: risico geaccepteerd', $cve . ($pkg ? " · $pkg" : '') . ($hid ? " · server $hid" : ' · alle servers'));
        out(['ok' => true]);

    case 'vuln_unignore':
        $in = input();
        q('DELETE FROM vuln_ignore WHERE id = ?', [(int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], 'Kwetsbaarheid: niet meer genegeerd', '#' . (int) ($in['id'] ?? 0));
        out(['ok' => true]);

    // Kwetsbaarheden aan of uit (Instellingen → Beveiliging → Kwetsbaarheden); aan = meteen de softwarelijsten laten (her)sturen en scannen
    case 'vuln_set':
        $on = !empty(input()['enabled']);
        save_setting('vuln_enabled', $on ? '1' : '0');
        AgentCommands::audit($user['username'], 'Kwetsbaarheden', $on ? 'aan' : 'uit');
        out(['ok' => true, 'enabled' => $on]);

    case 'vuln_scan':
        if (!Vulns::enabled()) {
            out(['error' => t('Kwetsbaarheden staat uit (Instellingen → Beveiliging → Kwetsbaarheden)')], 422);
        }
        session_write_close();
        q('UPDATE vuln_inventory SET scanned_at = NULL');
        $msg = Vulns::cron(25);
        out(['ok' => true, 'message' => $msg, 'pending' => (int) q('SELECT COUNT(*) FROM vuln_inventory WHERE scanned_at IS NULL')->fetchColumn()]);

    case 'vuln_upgrade':
        $in = input();
        $h = q('SELECT id, name, mode, agent_version, agent_update_via, commands FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $confirm('BIJWERKEN');
        $inv = q('SELECT mgr, packages FROM vuln_inventory WHERE host_id = ?', [$h['id']])->fetch();
        // Welke pakketten: de meegegeven bevindingen, of alles waarvoor een oplossing is
        $names = !empty($in['all'])
            ? q('SELECT DISTINCT package FROM vuln_findings WHERE host_id = ? AND fixed_version IS NOT NULL', [$h['id']])->fetchAll(PDO::FETCH_COLUMN)
            : array_map('strval', array_slice((array) ($in['packages'] ?? []), 0, 200));
        $bins = $inv ? Vulns::binaries((array) json_decode((string) $inv['packages'], true), (string) $inv['mgr'], $names) : [];
        $r = Vulns::requestUpgrade($h, $bins, $user['username']);
        if ($r !== true) {
            out(['error' => $r, 'command' => $inv && $bins ? Vulns::upgradeCommand((string) $inv['mgr'], $bins) : null], 422);
        }
        AgentCommands::audit($user['username'], 'Pakketten bijwerken gevraagd', "{$h['name']}: " . implode(' ', $bins));
        out(['ok' => true, 'packages' => $bins]);

    case 'ids_access':
        $days = Monitor::alertDays();
        $rows = q('SELECT a.id, a.host_id, a.kind, a.type, a.title, a.message, a.detail, a.created_at, h.name AS host_name
                   FROM access_log a JOIN hosts h ON h.id = a.host_id
                   WHERE a.created_at >= CURDATE() - INTERVAL ? DAY ORDER BY a.created_at DESC, a.id DESC LIMIT 10000', [$days - 1])->fetchAll();
        out(['enabled' => AuthEvents::enabled(), 'days' => $days, 'today' => date('Y-m-d'), 'truncated' => count($rows) >= 10000,
             'rows' => array_values(array_filter($rows, fn($r) => !AuthEvents::rowIgnored($r)))]);

    // Toegang: gebruiker of IP-adres voortaan niet meer tonen
    case 'ids_ignore':
        $add = array_filter(parse_list(mb_substr((string) (input()['add'] ?? ''), 0, 300)),
            fn($x) => preg_match('/^[A-Za-z0-9._@\\$-]{1,64}$/', $x) || @inet_pton(explode('/', $x)[0]) !== false);
        if (!$add) {
            out(['error' => t('Niets om te negeren')], 422);
        }
        $list = array_values(array_unique(array_merge(parse_list((string) setting('auth_ignore', '')), $add)));
        save_setting('auth_ignore', implode(' ', array_slice($list, 0, 100)));
        AgentCommands::audit($user['username'], 'IDS: toegang negeren', implode(' ', $add));
        out(['ok' => true, 'ignore' => setting('auth_ignore', '')]);

    case 'ids_set':
        $in = input();
        if (array_key_exists('enabled', $in)) {
            $on = !empty($in['enabled']);
            save_setting('ids_enabled', $on ? '1' : '0');
            AgentCommands::audit($user['username'], 'IDS', $on ? 'aangezet' : 'uitgezet');
        }
        if (isset($in['mode'])) {
            $mode = $in['mode'] === 'block' ? 'block' : 'learn';
            if ($mode === 'block' && setting('ids_mode', 'learn') !== 'block') {
                $confirm('BLOKKEREN');
            }
            save_setting('ids_mode', $mode);
            AgentCommands::audit($user['username'], 'IDS', $mode === 'block' ? 'modus blokkeren' : 'modus leren (alleen melden)');
        }
        out(['ok' => true]);

    // Bekende aanvallers en land per IP (1.82.0): stand, en nu bijwerken
    case 'ipintel_status':
        out(IpIntel::status());

    case 'ipintel_update':
        @set_time_limit(300);
        session_write_close();
        $msg = [IpIntel::updateBlocklists()];
        if (!empty(input()['geo'])) {
            $msg[] = IpIntel::updateGeo();
        }
        out(['ok' => true, 'result' => implode(' · ', $msg)] + IpIntel::status());

    // Wekelijks IDS-overzicht nu versturen (1.81.0), om te zien hoe het eruitziet
    case 'ids_weekly_test':
        $via = (string) setting('ids_weekly', 'email');
        out(['ok' => true, 'result' => Ids::sendWeekly($via === 'off' ? 'both' : $via), 'text' => Ids::weeklyText()[1]]);

    case 'ids_incident':
        $in = input();
        $inc = q('SELECT * FROM ids_incidents WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
        if (!$inc) {
            out(['error' => t('Incident niet gevonden')], 404);
        }
        $act = (string) ($in['action'] ?? '');
        if ($act === 'reopen') {
            q("UPDATE ids_incidents SET status = 'open', handled_by = NULL, handled_at = NULL WHERE id = ?", [$inc['id']]);
        } else {
            q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE id = ?", [$user['username'], $inc['id']]);
            if ($act === 'normal') {
                // Voortaan niet meer melden (deze soort met deze sleutel, op deze server)
                q("INSERT IGNORE INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'ignore', ?, ?)",
                    [$inc['host_id'], mb_substr($inc['type'] . '|' . $inc['dkey'], 0, 255), mb_substr($user['username'], 0, 255)]);
                AgentCommands::audit($user['username'], 'IDS: dit is normaal', "server {$inc['host_id']}: {$inc['title']}");
                // Webshell vrijgegeven: een proces dat nog gestopt zou worden, met rust laten
                q("UPDATE ids_kills SET done_at = NOW(), result = ? WHERE host_id = ? AND dkey = ? AND done_at IS NULL", [t('vrijgegeven'), $inc['host_id'], $inc['dkey']]);
            }
        }
        out(['ok' => true]);

    // Incidentvenster (1.101.0): het incident met advies; de gegevens over het IP-adres apart (ip_info), met time-outs
    case 'ids_incident_info':
        $inc = q('SELECT i.*, h.name AS host_name, h.mode AS host_mode FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$inc) {
            out(['error' => t('Incident niet gevonden')], 404);
        }
        out(['incident' => $inc, 'advice' => IdsAdvice::forIncident($inc), 'my_ip' => client_ip(), 'evidence' => Edr::evidenceFor((int) $inc['id']), 'explain' => Protect::describe($inc),
            'ignored' => (bool) q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'ignore' AND item = ?", [$inc['host_id'], mb_substr($inc['type'] . '|' . $inc['dkey'], 0, 255)])->fetchColumn()]);

    case 'ip_info':
        $ip = trim((string) ($_GET['ip'] ?? ''));
        if (@inet_pton($ip) === false) {
            out(['error' => t('Ongeldig IP-adres')], 422);
        }
        $fresh = ($_GET['fresh'] ?? '') === '1' && $isAdmin;
        // 1.112.0: dreigingsinformatie (uit de cache; nu opzoeken als het er nog niet is, of bij "opnieuw opzoeken")
        out(IdsAdvice::ipInfo($ip, $fresh) + ['ti' => ThreatIntel::on() && !Ids::internalIp($ip) ? ($fresh ? ThreatIntel::lookupIp($ip) : ThreatIntel::ip($ip, true)) : null,
            'ti_on' => ThreatIntel::on()]);

    case 'ids_block':
        $in = input();
        $hours = (int) ($in['hours'] ?? 24);
        $r = Ids::block((int) ($in['host_id'] ?? 0), (string) ($in['ip'] ?? ''), $hours > 0 ? $hours : null, trim((string) ($in['reason'] ?? '')), $user['username'], client_ip());
        if ($r !== true) {
            out(['error' => $r], 422);
        }
        AgentCommands::audit($user['username'], 'IDS: geblokkeerd', ($in['ip'] ?? '') . ' op server ' . (int) $in['host_id'] . ($hours > 0 ? " voor $hours uur" : ' permanent'));
        out(['ok' => true, 'active' => Ids::blocking()]);

    case 'ids_unblock':
        $b = Ids::unblock((int) (input()['id'] ?? 0), $user['username']);
        if (!$b) {
            out(['error' => t('Blokkade niet gevonden')], 404);
        }
        AgentCommands::audit($user['username'], 'IDS: gedeblokkeerd', "{$b['ip']} op server {$b['host_id']}");
        out(['ok' => true]);

    // ---------- Hardening, isoleren, gebruiker vergrendelen en de tijdlijn per IP (1.88.0) ----------
    case 'harden_detail':
        $hid = (int) ($_GET['host_id'] ?? 0);
        out(Hardening::detail($hid) + ['iso' => q('SELECT isolate_until, isolate_by, isolate_state FROM hosts WHERE id = ?', [$hid])->fetch() ?: null,
            'userlocks' => Ids::userLocks($hid), 'my_ip' => client_ip()]);

    case 'harden_ignore':
        $in = input();
        Hardening::ignore((int) ($in['host_id'] ?? 0), (string) ($in['item'] ?? ''), !empty($in['ignored']));
        AgentCommands::audit($user['username'], 'Hardening negeren', ($in['item'] ?? '') . ' op server ' . (int) ($in['host_id'] ?? 0) . (!empty($in['ignored']) ? ' aan' : ' uit'));
        out(['ok' => true]);

    case 'host_isolate':
        $in = input();
        $confirm('ISOLEREN');
        $hid = (int) ($in['host_id'] ?? 0);
        if (!($ih = q("SELECT address, agent_ips FROM hosts WHERE id = ? AND mode = 'agent'", [$hid])->fetch())) {
            out(['error' => t('Kies een server met agent')], 422);
        }
        // 1.105.2: de RzMon-server zelf isoleren legt alle checks stil (ping, SNMP, poorten naar andere netwerken)
        foreach (array_merge([(string) $ih['address']], parse_list((string) $ih['agent_ips'])) as $a) {
            if ($a !== '' && NetScan::isOwnIp(filter_var($a, FILTER_VALIDATE_IP) ? $a : (string) gethostbyname($a))) {
                out(['error' => t('Dit is de RzMon-server zelf: isoleren zou al je checks stilleggen')], 422);
            }
        }
        Ids::isolate($hid, (int) ($in['hours'] ?? 24), $user['username'], client_ip());
        out(['ok' => true]);

    case 'host_unisolate':
        Ids::unisolate((int) (input()['host_id'] ?? 0), $user['username']);
        out(['ok' => true]);

    case 'host_userlock':
        $in = input();
        $lock = !empty($in['lock']);
        if ($lock) {
            $confirm('VERGRENDELEN');
        }
        $r = Ids::userLock((int) ($in['host_id'] ?? 0), strtolower(trim((string) ($in['user'] ?? ''))), $lock, $user['username']);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 422);

    case 'weblog_status':
        out(WebLog::status());

    case 'ip_timeline':
        $ip = trim((string) ($_GET['ip'] ?? ''));
        @inet_pton($ip) !== false ? out(Ids::timeline($ip)) : out(['error' => t('Ongeldig IP-adres')], 422);

    case 'ids_rebaseline':
        $hid = (int) (input()['host_id'] ?? 0);
        q("DELETE FROM ids_baseline WHERE host_id = ? AND kind <> 'ignore'", [$hid]);
        q('DELETE FROM ids_state WHERE host_id = ?', [$hid]);
        AgentCommands::audit($user['username'], 'IDS: basismeting opnieuw', "server $hid");
        out(['ok' => true]);

    // ---------------------------------------------------------- Commando's op agents
    case 'agent_commands':
        $hosts = q("SELECT id, name, commands FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll();
        out([
            'global' => (string) setting('agent_commands', 'off'),
            'hosts' => array_map(fn($h) => ['id' => (int) $h['id'], 'name' => $h['name'], 'own' => $h['commands'], 'mode' => AgentCommands::modeFor($h)], $hosts),
            'log' => q('SELECT username, action, detail, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 20')->fetchAll(),
        ]);

    case 'agent_commands_set':
        $in = input();
        $mode = (string) ($in['mode'] ?? '');
        $hostId = (int) ($in['host_id'] ?? 0);
        if ($hostId > 0) {
            $h = q("SELECT id, name, commands FROM hosts WHERE id = ? AND mode = 'agent'", [$hostId])->fetch();
            if (!$h) {
                out(['error' => t('Server niet gevonden')], 404);
            }
            if ($mode !== 'global' && !isset(AgentCommands::MODES[$mode])) {
                out(['error' => t('Ongeldige keuze')], 422);
            }
            $from = AgentCommands::modeFor($h);
            $to = AgentCommands::modeFor(['commands' => $mode === 'global' ? null : $mode]);
            if (AgentCommands::widens($from, $to)) {
                $confirm('TOESTAAN');
            }
            q('UPDATE hosts SET commands = ? WHERE id = ?', [$mode === 'global' ? null : $mode, $hostId]);
            AgentCommands::audit($user['username'], "commando's per server", "{$h['name']}: " . ($mode === 'global' ? 'algemene instelling' : AgentCommands::MODES[$mode]) . " (was: " . AgentCommands::MODES[$from] . ')');
        } else {
            if (!isset(AgentCommands::MODES[$mode])) {
                out(['error' => t('Ongeldige keuze')], 422);
            }
            $from = (string) setting('agent_commands', 'off');
            if (AgentCommands::widens($from, $mode)) {
                $confirm('TOESTAAN');
            }
            save_setting('agent_commands', $mode);
            AgentCommands::audit($user['username'], "commando's algemeen", AgentCommands::MODES[$mode] . ' (was: ' . (AgentCommands::MODES[$from] ?? $from) . ')');
        }
        out(['ok' => true]);

    // ---------------------------------------------------------- RzMon bijwerken
    case 'update_info':
        $cache = Updater::check(!empty($_GET['force']));
        out([
            'current' => RZMON_VERSION,
            'latest' => $cache['latest'] ?? null,
            'available' => !empty($cache['latest']) && version_compare($cache['latest'], RZMON_VERSION, '>'),
            'checked_at' => isset($cache['checked_at']) ? date('Y-m-d H:i:s', (int) $cache['checked_at']) : null,
            'error' => $cache['error'] ?? null,
            'notes' => $cache['notes'] ?? [],
            'versions' => $cache['versions'] ?? [],
            'pinned' => (string) setting('update_pinned', ''),
            'unavailable' => Updater::unavailable(),
            'status' => Updater::status(),
            'backup' => setting('update_backup', '1') === '1',
            'source' => implode('@', Updater::source()),
            'install_dir' => SM_ROOT,
        ]);

    case 'update_start':
        $backup = !empty(input()['backup']);
        save_setting('update_backup', $backup ? '1' : '0');   // de schakelaar onthouden
        $ver = trim((string) (input()['version'] ?? ''));
        if ($ver !== '' && version_compare($ver, RZMON_VERSION, '<')) {
            $confirm('TERUGZETTEN');   // naar een oudere versie: eerst bevestigen
        }
        $r = Updater::request($backup, $user['username'], $ver !== '' ? $ver : null);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 409);

    case 'update_status':
        out(['status' => Updater::status(), 'version' => RZMON_VERSION,
            'log' => is_readable(Installer::HELPER_DIR . '/update.log') ? mb_substr((string) file_get_contents(Installer::HELPER_DIR . '/update.log'), -6000) : '']);

    case 'db_rotate':
        $confirm('VERNIEUWEN');
        $h = Installer::HELPER_DIR;
        if (is_file("$h/helper.installed") && is_writable($h)) {
            // Via de hulpdienst (als root): verzoek neerzetten, het resultaat komt binnen een paar seconden
            @unlink("$h/rotate.result");
            $nonce = bin2hex(random_bytes(16));
            if (file_put_contents("$h/rotate.request", $nonce) === false) {
                out(['error' => t('Kan geen verzoek neerzetten in {dir}', ['dir' => $h])], 500);
            }
            $_SESSION['db_rotate'] = ['nonce' => $nonce, 'at' => time()];
            out(['ok' => true, 'pending' => true]);
        }
        if (Installer::configWritable() || is_writable(SM_CONFIG_FILE)) {
            session_write_close();
            $r = Installer::rotateDbPassword();
            out($r, $r['ok'] ? 200 : 422);
        }
        out(['error' => t('De RzMon-map is vergrendeld en de hulpdienst is niet geïnstalleerd'), 'need_helper' => true], 409);

    case 'db_rotate_status':
        $p = $_SESSION['db_rotate'] ?? null;
        $h = Installer::HELPER_DIR;
        if (!$p) {
            out(['error' => t('Geen wachtwoordwissel bezig')], 404);
        }
        $res = json_decode((string) @file_get_contents("$h/rotate.result"), true);
        if ($res && hash_equals($p['nonce'], (string) ($res['nonce'] ?? ''))) {
            // Eén keer tonen: resultaat meteen weg
            @unlink("$h/rotate.result");
            unset($_SESSION['db_rotate'], $res['nonce'], $res['at']);
            out($res, $res['ok'] ? 200 : 422);
        }
        if (time() - $p['at'] > 30) {
            unset($_SESSION['db_rotate']);
            @unlink("$h/rotate.request");
            out(['error' => t('De hulpdienst reageert niet. Controleer op de server: systemctl status rzmon-helper.path')], 504);
        }
        out(['pending' => true]);

    // ---------- Back-up en herstel (alleen beheerders) ----------
    case 'backup_info':
        $root = realpath(SM_ROOT);
        $unlock = (int) ($_SESSION['backup_unlock'] ?? 0) + 600 - time();
        $next = Backup::nextSlot();
        out([
            'problem' => Backup::problem(),
            'fix_cmd' => Backup::fixCommand(),
            'dir' => Backup::dir(),
            'encryption' => Backup::encryptionSupported(),
            'has_pass' => (string) setting('backup_pass', '') !== '',
            'next' => $next ? date('Y-m-d H:i:s', $next) : null,
            'last_auto' => setting('backup_last_auto'),
            'last_error' => (string) setting('backup_last_error', ''),
            'unlocked' => max(0, $unlock),
            'max_upload' => Backup::MAX_UPLOAD,
            'list' => Backup::problem() === null ? Backup::list() : [],
            'cli_backup' => "sudo php $root/install/install.php --backup",
            'cli_restore' => "sudo php $root/install/install.php --restore /" . t('pad/naar') . '/rzmon-backup-….zip',
        ]);

    case 'backup_create':
        session_write_close();   // andere verzoeken van deze gebruiker niet blokkeren
        $r = Backup::create('manual', isset(input()['history']) ? (bool) input()['history'] : null);
        out($r, $r['ok'] ? 200 : 422);

    case 'backup_delete':
        if (!Backup::delete((string) (input()['file'] ?? ''))) {
            out(['error' => t('Back-up niet gevonden')], 404);
        }
        out(['ok' => true]);

    case 'backup_unlock':
        // Downloaden kan 10 minuten na het bevestigen (de zip bevat config.php met het databasewachtwoord)
        $confirm('DOWNLOADEN');
        $_SESSION['backup_unlock'] = time();
        out(['ok' => true, 'unlocked' => 600]);

    case 'backup_download':
        $path = Backup::path((string) ($_GET['file'] ?? ''));
        if ((int) ($_SESSION['backup_unlock'] ?? 0) < time() - 600) {
            out(['error' => t('Bevestig eerst met je wachtwoord')], 403);
        }
        if ($path === null) {
            out(['error' => t('Back-up niet gevonden')], 404);
        }
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;

    case 'backup_upload':
        // De zip komt in stukken van 1 MB (ruwe body), zie app.js
        session_write_close();
        $r = Backup::uploadChunk((string) ($_GET['id'] ?? ''), (int) ($_GET['offset'] ?? -1),
            (string) file_get_contents('php://input'), ($_GET['final'] ?? '') === '1');
        out($r, $r['ok'] ? 200 : 422);

    case 'backup_restore':
        $confirm('HERSTELLEN');
        $path = Backup::path((string) (input()['file'] ?? ''));
        if ($path === null) {
            out(['error' => t('Back-up niet gevonden')], 404);
        }
        session_write_close();
        $r = Backup::restore($path, trim((string) (input()['zip_pass'] ?? '')) ?: null);
        out($r, $r['ok'] ? 200 : 422);

    case 'password':
        if ($user['id'] === 0) {
            out(['error' => t('Je bent automatisch ingelogd (lokaal netwerk). Log in met je eigen account om je wachtwoord te wijzigen.')], 422);
        }
        $in = input();
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!$row || !password_verify((string) ($in['current'] ?? ''), $row['password_hash'])) {
            out(['error' => t('Huidig wachtwoord onjuist')], 422);
        }
        if (strlen((string) ($in['new'] ?? '')) < 8) {
            out(['error' => t('Nieuw wachtwoord moet minimaal 8 tekens zijn')], 422);
        }
        // Andere sessies van dit account worden uitgelogd (pw_changed_at); deze blijft
        q('UPDATE users SET password_hash = ?, pw_changed_at = ?, pw_must_change = 0 WHERE id = ?', [password_hash((string) $in['new'], PASSWORD_DEFAULT), now(), $user['id']]);
        $_SESSION['auth_at'] = time();
        // Eigen wachtwoord gewijzigd: alle andere sessies en onthouden apparaten weg (dit apparaat blijft)
        session_logout('user', (int) $user['id']);
        out(['ok' => true, 'pwned' => Hibp::checkUser((int) $user['id'], (string) $in['new']) ?? 0]);

    default:
        out(['error' => t('Onbekende actie')], 404);
}
