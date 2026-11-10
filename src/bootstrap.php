<?php
declare(strict_types=1);

const SM_ROOT = __DIR__ . '/..';
const STATUS_OK = 0;
const STATUS_WARN = 1;
const STATUS_CRIT = 2;
const STATUS_UNKNOWN = 3;
const STATUS_LABELS = ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN'];
// Rollen, van meeste naar minste rechten. Beheerder: alles. Gebruiker: storingen afhandelen
// (bevestigen, nu checken, onderhoud, poortscan). Alleen lezen: kijken.
const ROLES = ['admin' => 3, 'user' => 2, 'readonly' => 1];
const ROLE_LABELS = ['admin' => 'Beheerder', 'user' => 'Gebruiker', 'readonly' => 'Alleen lezen'];
// "Onthoud mij": na zoveel dagen niet gebruikt wordt een apparaat vergeten (elk gebruik telt opnieuw)
const REMEMBER_DAYS = 30;
// Instellingen die nooit naar de browser gaan (alleen "ingevuld of niet")
const SECRET_SETTINGS = ['pushover_token', 'ntfy_token', 'smtp_pass', 'mqtt_pass', 'backup_pass', 'enc_key', 'enc_key_old', 'hibp_key', 'unifi_pass', 'unifi_key', 'adguard_pass', 'ti_abuseipdb_key', 'ti_greynoise_key', 'ti_abusech_key',
    'ai_claude_key', 'ai_openai_key', 'ai_gemini_key', 'ai_grok_key', 'ai_ollama_key', 'cve_github_token', 'cve_mastodon_token'];   // 1.171.0: AI-advies; 1.179.0: rugbaarheid

// RZMON_CONFIG: een ander configbestand (tests op een aparte database)
define('SM_CONFIG_FILE', getenv('RZMON_CONFIG') ?: SM_ROOT . '/config.php');
// Versie van RzSense: de enige bron is het bestand VERSION (bijwerken met tools/bump-version.sh)
define('RZMON_VERSION', trim((string) @file_get_contents(SM_ROOT . '/VERSION')) ?: '0.0.0');
// Nieuwste agent: eigen nummer, los van RzSense (agent/VERSION; bijwerken met tools/bump-version.sh agent …)
define('AGENT_LATEST', trim((string) @file_get_contents(SM_ROOT . '/agent/VERSION')) ?: RZMON_VERSION);

// Zonder config.php draait de app in setup-modus (zie public/setup.php).
// opcache: een gewijzigde config.php (bv. nieuw databasewachtwoord via de hulpdienst, een ander
// proces) meteen opnieuw inlezen, ook als opcache.validate_timestamps uit staat. Kost één stat().
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(SM_CONFIG_FILE, false);
}
$GLOBALS['sm_config'] = is_file(SM_CONFIG_FILE) ? (require SM_CONFIG_FILE) : [];
date_default_timezone_set(config('timezone', 'Europe/Amsterdam'));
foreach ((array) config('php', []) as $ini => $val) {
    if ($val !== '' && $val !== null) {
        @ini_set((string) $ini, (string) $val);
    }
}

require_once __DIR__ . '/net.php';
require_once __DIR__ . '/I18n.php';   // t() en th()
// Klassen pas inlezen als ze nodig zijn (1.119.0): een verzoek leest zo alleen wat het gebruikt, niet alle 1,5 MB code.
// Elk bestand in src/ heeft één klasse met dezelfde naam; een paar hulpklassen staan bij hun hoofdklasse.
spl_autoload_register(static function (string $class): void {
    $file = ['SnmpDown' => 'SnmpMonitor', 'SnmpConn' => 'SnmpMonitor', 'VulnFetchError' => 'Vulns'][$class] ?? $class;
    if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $file) && is_file(__DIR__ . "/$file.php")) {
        require_once __DIR__ . "/$file.php";
    }
});
SelfCheck::register();   // PHP-fouten bijhouden voor de systeemcheck (1.96.0)

function config(string $key, $default = null)
{
    return $GLOBALS['sm_config'][$key] ?? $default;
}

function is_configured(): bool
{
    return !empty($GLOBALS['sm_config']['db']['name']);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};port=" . (int) ($c['port'] ?? 3306) . ";dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // DB-tijd gelijk trekken met PHP-tijd. 1.224.3: liefst de tijdzone zelf (bv. Europe/Amsterdam), dan klopt het ook over
        // de overgang naar zomer- of wintertijd heen; dat kan als MariaDB de tijdzones kent (mysql_tzinfo_to_sql). Anders de
        // afstand tot UTC van nu, zoals voorheen.
        try {
            $pdo->prepare('SET time_zone = ?')->execute([date_default_timezone_get()]);
        } catch (PDOException) {
            $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/**
 * Opdracht die RzSense zelf start (1.132.0): met het geheime kenmerk van deze RzSense ervoor (": rzmon:<kenmerk>;", doet
 * niets). Zo herkent de agent op deze server alles wat RzSense start, en wat daaruit voortkomt, als "van RzSense"; een andere
 * webapp onder dezelfde gebruiker kent het kenmerk niet.
 */
function rz_sh(string $cmd): string
{
    $m = Edr::selfMark();
    return $m !== '' ? ": rzmon:$m; $cmd" : $cmd;
}

/**
 * Veel schrijfacties in één transactie (1.131.0): de database bevestigt dan één keer op schijf in plaats van na elke
 * regel. Op een VM-schijf scheelt dat seconden per agentrapport. Al in een transactie? Dan gewoon meedoen.
 */
/** 1.221.1: deadlock of lock-timeout van de database (dan mag de transactie opnieuw) */
function db_retryable(Throwable $e): bool
{
    $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    return in_array($code, [1205, 1213], true) || ($e instanceof PDOException && (string) $e->getCode() === '40001');
}

function in_tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function settings(): array
{
    if (!isset($GLOBALS['rz_settings'])) {
        $GLOBALS['rz_settings'] = [];
        foreach (q('SELECT name, value FROM settings') as $row) {
            $GLOBALS['rz_settings'][$row['name']] = $row['value'];
        }
    }
    return $GLOBALS['rz_settings'];
}

function setting(string $name, $default = null)
{
    return settings()[$name] ?? $default;
}

function save_setting(string $name, string $value): void
{
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
    if (isset($GLOBALS['rz_settings'])) {
        $GLOBALS['rz_settings'][$name] = $value;   // de rest van dit verzoek ziet de nieuwe waarde
    }
}

/** Rechten van een API-sleutel: status lezen, IDS lezen, IDS blokkeren, checks bedienen. */
const API_RIGHTS = ['read', 'ids_read', 'vulns_read', 'ids_block', 'checks'];

/** Keuzes voor hoe vaak een agent rapporteert (seconden). */
const AGENT_TICKS = [30, 60, 120, 300];

/** Hoe vaak deze agent rapporteert: de eigen instelling van de server, anders de algemene (standaard 60 s). */
function agent_tick(?int $own): int
{
    $t = $own ?: (int) setting('agent_tick', 60);
    return in_array($t, AGENT_TICKS, true) ? $t : 60;
}

/**
 * Contact met de agent (1.99.0): laatste en volgende contact, wanneer hij offline telt, en het ritme dat hij echt
 * aanhoudt (de mediaan van de laatste tussenpozen; extra rapporten door realtime tellen zo nauwelijks mee).
 */
function agent_contact(array $h): array
{
    $beats = array_values(array_filter(array_map('intval', explode(',', (string) ($h['agent_beats'] ?? ''))), static fn($t) => $t > 0));
    $gaps = [];
    for ($i = 1, $n = count($beats); $i < $n; $i++) {
        $gaps[] = $beats[$i] - $beats[$i - 1];
    }
    sort($gaps);
    $tick = agent_tick(isset($h['agent_tick']) ? (int) $h['agent_tick'] : null);
    $seen = $h['agent_seen'] ? strtotime((string) $h['agent_seen']) : null;
    return ['seen' => $seen ?: null, 'tick' => $tick, 'stale' => agent_stale_seconds(isset($h['agent_tick']) ? (int) $h['agent_tick'] : null),
        'rhythm' => count($gaps) >= 5 ? $gaps[intdiv(count($gaps), 2)] : null, 'n' => count($gaps), 'realtime' => (int) ($h['realtime'] ?? 0) === 1];
}

/** Na hoeveel seconden zonder rapport een agent "stil" is: de instelling, maar minstens 3 tikken. */
/**
 * 1.220.0: kort bewaren wat zwaar is om uit te rekenen en niet per seconde verandert (bv. IDS: hardening, aanvallers).
 * Een bestand in de tijdelijke map per installatie; mislukt lezen of schrijven, dan gewoon opnieuw uitrekenen.
 */
function memo(string $key, int $ttl, callable $fn): mixed
{
    $file = sys_get_temp_dir() . '/rzsense-memo-' . substr(md5(SM_ROOT . '|' . $key), 0, 16) . '.json';
    $raw = @file_get_contents($file);
    if ($raw !== false) {
        $d = json_decode($raw, true);
        if (is_array($d) && isset($d['t']) && array_key_exists('v', $d) && (int) $d['t'] > time() - $ttl) {
            return $d['v'];
        }
    }
    $v = $fn();
    @file_put_contents($file, (string) json_encode(['t' => time(), 'v' => $v]), LOCK_EX);
    return $v;
}

function agent_stale_seconds(?int $own): int
{
    return max((int) setting('agent_stale_minutes', 5) * 60, 3 * agent_tick($own) + 30);
}

/** Eenheid van de meetwaarde van een check (null = geen getal om te tonen). */
function check_unit(array $check): ?string
{
    if ($check['type'] === 'ha') {
        return ['backup' => 'dagen', 'integrations' => 'stuks', 'repairs' => 'stuks', 'disklife' => '%'][$check['metric'] ?? ''] ?? null;
    }
    if ($check['type'] === 'container') {
        return ['cpu' => '%', 'mem' => '%', 'disk' => 'MB'][$check['metric'] ?? ''] ?? null;
    }
    if ($check['type'] === 'heartbeat') {
        // Met een eenheid: de meegestuurde waarde; anders de duur van start tot klaar
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        return ($o['unit'] ?? '') !== '' ? $o['unit'] : 'min';
    }
    if ($check['type'] === 'snmp') {
        if (($check['metric'] ?? '') === 'oid') {
            $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
            return ($o['mode'] ?? 'gauge') === 'text' ? null : (($o['unit'] ?? '') . (($o['mode'] ?? '') === 'counter' ? '/s' : '') ?: null);
        }
        return ['uptime' => 'uur', 'if' => '%', 'cpu' => '%', 'mem' => '%', 'storage' => '%', 'temp' => '°C', 'ups_charge' => '%', 'ups_runtime' => 'min',
            'ups_load' => '%', 'ups_temp' => '°C', 'syno_system' => '°C', 'syno_raid' => '%', 'syno_disk' => '°C', 'printer' => '%', 'unifi_clients' => 'clients',
            'unifi_cu' => '%', 'unifi_traffic' => 'Mbit/s', 'unifi_quality' => '%'][$check['metric'] ?? ''] ?? null;
    }
    return ['ping' => 'ms', 'tcp' => 'ms', 'http' => 'ms', 'ssl' => 'dagen', 'disk' => '%', 'cpu' => '%', 'ram' => '%', 'log' => 'treffers',
        'dns' => 'ms', 'ntp' => 'ms', 'smart' => '°C', 'ups' => '%', 'mysql' => Db::UNITS[($check['metric'] ?? '') ?: 'overview'] ?? 'ms', 'domain' => ($check['metric'] ?? '') === 'mail' ? 'punten' : 'dagen', 'mqtt' => 'ms', 'vpn' => ($check['metric'] ?? '') === 'wg' ? 's' : null][$check['type']] ?? null;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Wachtwoorden (1.143.0): Argon2id als PHP dat kan, anders bcrypt. Bestaande (bcrypt-)wachtwoorden blijven werken;
 * bij de eerstvolgende geslaagde login zet RzSense ze ongemerkt om (password_needs_rehash).
 */
function password_algo(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function hash_password(string $password): string
{
    return password_hash($password, password_algo());
}

/** Eén willekeurige nonce per verzoek, voor de paar eigen inline scripts (CSP) */
function csp_nonce(): string
{
    static $n = null;
    return $n ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

/**
 * Beveiligingsheaders (1.143.0) voor de HTML-pagina's (en met $html = false voor de API):
 * - Content-Security-Policy: alleen scripts van RzSense zelf (en de inline scripts met de nonce van dit verzoek), geen plug-ins,
 *   formulieren en <base> alleen naar RzSense. Stijlen mogen inline (veel kleine style="…"). Noodknop: 'csp' => false in config.php.
 * - Insluiten in een frame (bv. een Home Assistant-paneel) blijft standaard mogelijk; beperken met
 *   'frame_ancestors' => "'self' https://ha.voorbeeld.nl" in config.php.
 * - nosniff, een zuinige Referrer-Policy en geen camera/microfoon/locatie.
 */
function security_headers(bool $html = true): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    $fa = trim((string) config('frame_ancestors', ''));
    if ($fa !== '' && !preg_match('/[;\r\n]/', $fa)) {
        header("Content-Security-Policy: frame-ancestors $fa", false);
        if ($fa === "'self'") {
            header('X-Frame-Options: SAMEORIGIN');
        }
    }
    if (!$html) {
        return;
    }
    if (config('csp', true) !== false) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self' blob:; "
            . "object-src 'none'; base-uri 'self'; form-action 'self'", false);
    }
}

// Geblokkeerde IP-adressen en netwerken (Instellingen → ⛔ Blokkades): heel RzSense geeft 403. Niet voor cron of de terminal.
// Daarna: beheer alleen via https (doorsturen naar het beheeradres, HSTS), zie src/Https.php.
if (PHP_SAPI !== 'cli' && is_configured()) {
    Security::enforce();
    Https::enforce();
}
