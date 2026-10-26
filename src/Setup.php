<?php
declare(strict_types=1);

/**
 * Setup (1.92.0): de systeemcheck, de databaseverbinding en de server- en PHP-instellingen. Gedeeld door setup.php
 * (eerste installatie, of als de database niet bereikbaar is) en de beheerportal (Instellingen → Systeem).
 */
final class Setup
{
    public static function fnEnabled(string $fn): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists($fn) && !in_array($fn, $disabled, true);
    }

    public static function runAs(): string
    {
        return function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
    }

    /** @return list<array{0: string, 1: ?bool, 2: string}> [label, ok (null = waarschuwing), detail] */
    public static function checks(bool $installed, ?string $dbError): array
    {
        $pingBin = (string) config('ping_bin', '/bin/ping');
        $systemctl = self::fnEnabled('shell_exec') ? trim((string) shell_exec('command -v systemctl 2>/dev/null')) : '';
        $cronLast = null;
        if ($dbError === null && is_configured()) {
            try {
                $cronLast = q("SELECT value FROM settings WHERE name = 'cron_last_run'")->fetchColumn() ?: null;
            } catch (Throwable) {
            }
        }
        return [
            [t('PHP-versie ≥ 8.1'), PHP_VERSION_ID >= 80100, PHP_VERSION . ' (' . PHP_SAPI . ')'],
            [t('Extensie {ext}', ['ext' => 'pdo_mysql']), extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? t('Geladen') : 'apt install php-mysql'],
            [t('Extensie {ext}', ['ext' => 'curl']), extension_loaded('curl'), extension_loaded('curl') ? t('Geladen') : 'apt install php-curl (' . t('nodig voor HTTP-checks en Pushover') . ')'],
            [t('Extensie {ext}', ['ext' => 'mbstring']), extension_loaded('mbstring'), extension_loaded('mbstring') ? t('Geladen') : 'apt install php-mbstring'],
            [t('exec() / shell_exec() toegestaan'), self::fnEnabled('exec') && self::fnEnabled('shell_exec'),
                self::fnEnabled('exec') && self::fnEnabled('shell_exec') ? t('Toegestaan') : t('Nodig voor ping en services; verwijder uit disable_functions')],
            [t('Ping beschikbaar'), is_executable($pingBin) ?: null, $pingBin . (is_executable($pingBin) ? '' : ' ' . t('niet gevonden') . ' — apt install iputils-ping')],
            [t('systemctl beschikbaar'), $systemctl !== '' ?: null, $systemctl ?: t('Niet gevonden: lokale service-checks werken niet')],
            // Vóór de installatie is schrijven handig; daarna hoort de map weer dicht
            $installed
                ? [t('Installatiemap vergrendeld'), !Installer::configWritable() ?: null,
                    Installer::configWritable() ? t('De webserver mag nog schrijven in {dir}. Vergrendel na de setup: {cmd}', ['dir' => realpath(SM_ROOT), 'cmd' => 'sudo chown root ' . realpath(SM_ROOT)])
                        . ' (Rocky Linux: sudo bash ' . realpath(SM_ROOT) . '/install/install-rocky.sh --lock)' : 'OK']
                : [t('config.php schrijfbaar'), Installer::configWritable() ?: null,
                    Installer::configWritable() ? realpath(dirname(SM_CONFIG_FILE)) . '/config.php' : t('Niet schrijfbaar: je krijgt de inhoud om zelf te plaatsen')],
            [t('Database verbinding'), is_configured() ? ($dbError === null) : null, is_configured() ? ($dbError ?? 'OK') : t('Nog niet ingesteld')],
            [t('Cron draait'), $cronLast ? (strtotime((string) $cronLast) > time() - 300 ?: null) : null,
                $cronLast ? t('Laatste run: {time}', ['time' => $cronLast]) : t('Nog geen run gezien (zie stap Cron)')],
        ];
    }

    public static function phpInfo(): array
    {
        return [
            'php.ini' => php_ini_loaded_file() ?: '(' . t('geen') . ')',
            t('Draait als gebruiker') => self::runAs(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . ' s',
            'display_errors' => ini_get('display_errors') ?: '0',
            t('Tijdzone') => date_default_timezone_get() . ' (' . date('H:i') . ')',
        ];
    }

    /** De regel voor /etc/cron.d/rzmon */
    public static function cronLine(): string
    {
        // Onder php-fpm/mod_php is PHP_BINARY niet de CLI; dan het standaardpad gebruiken
        $phpBin = PHP_BINARY && preg_match('/^php[\d.]*$/', basename(PHP_BINARY)) ? PHP_BINARY : '/usr/bin/php';
        $runAs = self::runAs();
        return "echo '* * * * * " . ($runAs !== '?' ? $runAs : 'www-data') . " $phpBin " . realpath(SM_ROOT) . "/cron/run.php >/dev/null 2>&1' | sudo tee /etc/cron.d/rzmon";
    }

    /** Huidige configuratie, zonder het databasewachtwoord */
    public static function config(): array
    {
        $cfg = Installer::mergedConfig([]);
        $db = $cfg['db'];
        $db['has_pass'] = ($db['pass'] ?? '') !== '';
        unset($db['pass']);
        return ['db' => $db, 'timezone' => $cfg['timezone'], 'base_url' => $cfg['base_url'], 'ping_bin' => $cfg['ping_bin'],
            'php' => $cfg['php'] + ['memory_limit' => '', 'max_execution_time' => '', 'display_errors' => '0'],
            'php_now' => ['memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')],
            'timezones' => timezone_identifiers_list()];
    }

    private static function dbFromInput(array $in): array
    {
        $cur = config('db', Installer::CONFIG_DEFAULTS['db']);
        return [
            'host' => trim((string) ($in['db_host'] ?? '')) ?: '127.0.0.1',
            'port' => (int) ($in['db_port'] ?? 3306) ?: 3306,
            'name' => trim((string) ($in['db_name'] ?? '')),
            'user' => trim((string) ($in['db_user'] ?? '')),
            // Leeg wachtwoordveld = huidig wachtwoord behouden
            'pass' => (string) ($in['db_pass'] ?? '') !== '' ? (string) $in['db_pass'] : (string) ($cur['pass'] ?? ''),
        ];
    }

    /** config.php schrijven; lukt dat niet (map vergrendeld), dan de inhoud om zelf te plaatsen */
    private static function saveConfig(array $changes): array
    {
        $cfg = Installer::mergedConfig($changes);
        if (Installer::writeConfig($cfg)) {
            return ['ok' => true, 'written' => true];
        }
        return ['ok' => true, 'written' => false, 'path' => realpath(dirname(SM_CONFIG_FILE)) . '/config.php', 'user' => self::runAs(),
            'content' => Installer::renderConfig($cfg)];
    }

    public static function dbTest(array $in): array
    {
        $db = self::dbFromInput($in);
        $pdo = Installer::connect($db, false);
        return ['ok' => true, 'version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
            'db_exists' => (bool) $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($db['name']))->fetchColumn()];
    }

    /** Opslaan en de tabellen installeren; met $create eerst de database en gebruiker aanmaken (alleen bij de installatie) */
    public static function dbSave(array $in, bool $create = false): array
    {
        $db = self::dbFromInput($in);
        if ($db['name'] === '' || $db['user'] === '') {
            throw new InvalidArgumentException(t('Databasenaam en gebruiker zijn verplicht'));
        }
        if ($create) {
            Installer::createDatabase($db, (string) ($in['admin_user'] ?? 'root'), (string) ($in['admin_pass'] ?? ''));
        }
        $pdo = Installer::connect($db);
        Installer::runSchema($pdo);
        return self::saveConfig(['db' => $db]) + ['message' => t('Verbinding OK, tabellen aangemaakt/bijgewerkt')];
    }

    public static function appSave(array $in): array
    {
        $tz = (string) ($in['timezone'] ?? 'Europe/Amsterdam');
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(t('Ongeldige tijdzone'));
        }
        $ping = trim((string) ($in['ping_bin'] ?? '/bin/ping'));
        if ($ping !== '' && !preg_match('#^/[\w./-]+$#', $ping)) {
            throw new InvalidArgumentException(t('Ping-pad moet een absoluut pad zijn'));
        }
        $base = rtrim(trim((string) ($in['base_url'] ?? '')), '/');
        if ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(t('Basis-URL is geen geldige URL'));
        }
        $mem = trim((string) ($in['memory_limit'] ?? ''));
        if ($mem !== '' && !preg_match('/^(-1|\d+[KMG]?)$/i', $mem)) {
            throw new InvalidArgumentException(t('memory_limit: bv. 256M of -1'));
        }
        $met = trim((string) ($in['max_execution_time'] ?? ''));
        if ($met !== '' && !ctype_digit($met)) {
            throw new InvalidArgumentException(t('max_execution_time moet een getal (seconden) zijn'));
        }
        return self::saveConfig([
            'timezone' => $tz,
            'ping_bin' => $ping ?: '/bin/ping',
            'base_url' => $base,
            'php' => ['memory_limit' => $mem, 'max_execution_time' => $met, 'display_errors' => !empty($in['display_errors']) ? '1' : '0'],
        ]);
    }
}
