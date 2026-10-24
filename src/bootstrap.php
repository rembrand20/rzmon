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
const SECRET_SETTINGS = ['pushover_token', 'smtp_pass', 'mqtt_pass', 'backup_pass', 'enc_key', 'enc_key_old', 'hibp_key'];

const SM_CONFIG_FILE = SM_ROOT . '/config.php';
// Versie van RzMon: de enige bron is het bestand VERSION (bijwerken met tools/bump-version.sh)
define('RZMON_VERSION', trim((string) @file_get_contents(SM_ROOT . '/VERSION')) ?: '0.0.0');
// Nieuwste agent: eigen nummer, los van RzMon (agent/VERSION; bijwerken met tools/bump-version.sh agent …)
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
require_once __DIR__ . '/Pushover.php';
require_once __DIR__ . '/Webhook.php';
require_once __DIR__ . '/Checks.php';
require_once __DIR__ . '/Monitor.php';
require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/PortScan.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Mqtt.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Backup.php';
require_once __DIR__ . '/PasswordReset.php';
require_once __DIR__ . '/NotifyRules.php';
require_once __DIR__ . '/Health.php';
require_once __DIR__ . '/Updater.php';
require_once __DIR__ . '/AgentCommands.php';
require_once __DIR__ . '/AuthEvents.php';
require_once __DIR__ . '/Ids.php';
require_once __DIR__ . '/SnmpMonitor.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AgentText.php';
require_once __DIR__ . '/Vulns.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/AgentCrypto.php';
require_once __DIR__ . '/Https.php';
require_once __DIR__ . '/Hibp.php';

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
        // DB-tijd gelijk trekken met PHP-tijd
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }
    return $cache;
}

function setting(string $name, $default = null)
{
    return settings()[$name] ?? $default;
}

function save_setting(string $name, string $value): void
{
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
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

/** Na hoeveel seconden zonder rapport een agent "stil" is: de instelling, maar minstens 3 tikken. */
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
    if ($check['type'] === 'snmp') {
        if (($check['metric'] ?? '') === 'oid') {
            $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
            return ($o['mode'] ?? 'gauge') === 'text' ? null : (($o['unit'] ?? '') . (($o['mode'] ?? '') === 'counter' ? '/s' : '') ?: null);
        }
        return ['uptime' => 'uur', 'if' => '%', 'cpu' => '%', 'mem' => '%', 'storage' => '%', 'temp' => '°C', 'ups_charge' => '%', 'ups_runtime' => 'min',
            'ups_load' => '%', 'ups_temp' => '°C', 'syno_system' => '°C', 'syno_raid' => '%', 'syno_disk' => '°C', 'printer' => '%', 'unifi_clients' => 'clients'][$check['metric'] ?? ''] ?? null;
    }
    return ['ping' => 'ms', 'tcp' => 'ms', 'http' => 'ms', 'ssl' => 'dagen', 'disk' => '%', 'cpu' => '%', 'ram' => '%', 'log' => 'treffers'][$check['type']] ?? null;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

// Geblokkeerde IP-adressen en netwerken (Instellingen → ⛔ Blokkades): heel RzMon geeft 403. Niet voor cron of de terminal.
// Daarna: beheer alleen via https (doorsturen naar het beheeradres, HSTS), zie src/Https.php.
if (PHP_SAPI !== 'cli' && is_configured()) {
    Security::enforce();
    Https::enforce();
}
