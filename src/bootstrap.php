<?php
declare(strict_types=1);

const SM_ROOT = __DIR__ . '/..';
const STATUS_OK = 0;
const STATUS_WARN = 1;
const STATUS_CRIT = 2;
const STATUS_UNKNOWN = 3;
const STATUS_LABELS = ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN'];

const SM_CONFIG_FILE = SM_ROOT . '/config.php';

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

function now(): string
{
    return date('Y-m-d H:i:s');
}
