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
const SECRET_SETTINGS = ['pushover_token', 'smtp_pass', 'mqtt_pass'];

const SM_CONFIG_FILE = SM_ROOT . '/config.php';
// Versie van RzMon: de enige bron is het bestand VERSION (bijwerken met tools/bump-version.sh)
define('RZMON_VERSION', trim((string) @file_get_contents(SM_ROOT . '/VERSION')) ?: '0.0.0');

// Zonder config.php draait de app in setup-modus (zie public/setup.php)
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

/** Eenheid van de meetwaarde van een check (null = geen getal om te tonen). */
function check_unit(array $check): ?string
{
    if ($check['type'] === 'container') {
        return ['cpu' => '%', 'mem' => '%', 'disk' => 'MB'][$check['metric'] ?? ''] ?? null;
    }
    return ['ping' => 'ms', 'tcp' => 'ms', 'http' => 'ms', 'ssl' => 'dagen', 'disk' => '%', 'cpu' => '%', 'ram' => '%'][$check['type']] ?? null;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
