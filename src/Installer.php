<?php
declare(strict_types=1);

/** Gedeelde installatie-logica voor install/install.php (CLI) en public/setup.php. */
final class Installer
{
    public const CONFIG_DEFAULTS = [
        'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'rzmon', 'user' => 'rzmon', 'pass' => ''],
        'timezone' => 'Europe/Amsterdam',
        'ping_bin' => '/bin/ping',
        'base_url' => '',
        'php' => ['memory_limit' => '', 'max_execution_time' => '', 'display_errors' => '0'],
    ];

    /** Tabellen aanmaken of bijwerken (veilig om vaker uit te voeren). */
    public static function runSchema(?PDO $pdo = null): void
    {
        $pdo ??= db();
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(SM_ROOT . '/install/schema.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    public static function userCount(): int
    {
        return (int) q('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function saveUser(string $username, string $password): void
    {
        if (!preg_match('/^[\w.@-]{2,64}$/u', $username)) {
            throw new InvalidArgumentException('Gebruikersnaam: 2-64 tekens, letters/cijfers/._@-');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Wachtwoord moet minimaal 8 tekens zijn');
        }
        q('INSERT INTO users (username, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)', [
            $username, password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    /** Eerste host (de monitor-server zelf) met standaard checks. */
    public static function seedLocalHost(): void
    {
        if (q('SELECT COUNT(*) FROM hosts')->fetchColumn()) {
            return;
        }
        q("INSERT INTO hosts (name, address, mode) VALUES (?, '127.0.0.1', 'local')", [gethostname() ?: 'Monitor']);
        $hid = (int) db()->lastInsertId();
        q("INSERT INTO checks (host_id, type, target, name, warn_threshold, crit_threshold) VALUES
            (?, 'disk', '/', 'Disk /', 80, 90),
            (?, 'service', 'mariadb', 'MariaDB', NULL, NULL),
            (?, 'cpu', '', 'CPU', 85, 95),
            (?, 'ram', '', 'RAM', 85, 95)", [$hid, $hid, $hid, $hid]);
    }

    /** Verbinding testen zonder de globale db() te gebruiken. */
    public static function connect(array $db, bool $withDatabase = true): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) ($db['port'] ?? 3306));
        if ($withDatabase) {
            $dsn .= ';dbname=' . $db['name'];
        }
        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /** Database + gebruiker aanmaken met een beheerdersaccount (bv. root). */
    public static function createDatabase(array $db, string $adminUser, string $adminPass): void
    {
        if (!preg_match('/^\w{1,64}$/', $db['name']) || !preg_match('/^[\w.-]{1,80}$/', $db['user'])) {
            throw new InvalidArgumentException('Databasenaam/gebruiker mag alleen letters, cijfers en _ bevatten');
        }
        $pdo = self::connect(['host' => $db['host'], 'port' => $db['port'], 'user' => $adminUser, 'pass' => $adminPass], false);
        $host = in_array($db['host'], ['127.0.0.1', 'localhost', '::1'], true) ? 'localhost' : '%';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec(sprintf("CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY %s", $db['user'], $host, $pdo->quote($db['pass'])));
        $pdo->exec(sprintf("ALTER USER '%s'@'%s' IDENTIFIED BY %s", $db['user'], $host, $pdo->quote($db['pass'])));
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$db['name']}`.* TO '{$db['user']}'@'$host'");
        if ($host === 'localhost') {
            // Ook via TCP (127.0.0.1) laten werken
            $pdo->exec(sprintf("CREATE USER IF NOT EXISTS '%s'@'127.0.0.1' IDENTIFIED BY %s", $db['user'], $pdo->quote($db['pass'])));
            $pdo->exec(sprintf("ALTER USER '%s'@'127.0.0.1' IDENTIFIED BY %s", $db['user'], $pdo->quote($db['pass'])));
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$db['name']}`.* TO '{$db['user']}'@'127.0.0.1'");
        }
        $pdo->exec('FLUSH PRIVILEGES');
    }

    public static function configWritable(): bool
    {
        // writeConfig() schrijft via een tijdelijk bestand + rename, dus de map moet schrijfbaar zijn
        return is_writable(dirname(SM_CONFIG_FILE));
    }

    public static function renderConfig(array $cfg): string
    {
        return "<?php\n// Gegenereerd door RzMon setup op " . date('Y-m-d H:i:s') . "\nreturn " . var_export($cfg, true) . ";\n";
    }

    /** Schrijft config.php; geeft false terug als dat niet mag (dan handmatig plaatsen). */
    public static function writeConfig(array $cfg): bool
    {
        if (!self::configWritable()) {
            return false;
        }
        $tmp = SM_CONFIG_FILE . '.tmp';
        if (file_put_contents($tmp, self::renderConfig($cfg), LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        $ok = rename($tmp, SM_CONFIG_FILE);
        if ($ok && function_exists('opcache_invalidate')) {
            @opcache_invalidate(SM_CONFIG_FILE, true);
        }
        return $ok;
    }

    public static function mergedConfig(array $changes): array
    {
        return array_replace_recursive(self::CONFIG_DEFAULTS, $GLOBALS['sm_config'] ?? [], $changes);
    }
}
