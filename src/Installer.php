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
        self::migrate($pdo);
        $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['db_version', RZMON_VERSION]);
    }

    /**
     * Database bijwerken als die bij een oudere RzMon-versie hoort (na git pull / update).
     * Veilig om vaak aan te roepen: kost één query als alles al klopt.
     * @return string|null foutmelding, of null als alles in orde is
     */
    public static function ensureSchema(): ?string
    {
        try {
            $current = db()->query("SELECT value FROM settings WHERE name = 'db_version'")->fetchColumn();
        } catch (Throwable) {
            $current = false;   // bv. settings-tabel bestaat nog niet
        }
        if ($current === RZMON_VERSION) {
            return null;
        }
        try {
            self::runSchema();
            return null;
        } catch (Throwable $e) {
            return 'Database bijwerken naar ' . RZMON_VERSION . ' mislukt: ' . $e->getMessage();
        }
    }

    /** Kolommen van latere versies; werkt op MariaDB én MySQL (geen ADD COLUMN IF NOT EXISTS). */
    private const COLUMNS = [
        ['hosts', 'parent_id', 'INT UNSIGNED NULL AFTER mode'],
        ['hosts', 'maintenance_until', 'DATETIME NULL'],
        ['hosts', 'maintenance_reason', 'VARCHAR(200) NULL'],
        ['checks', 'maintenance_until', 'DATETIME NULL'],
        ['checks', 'maintenance_reason', 'VARCHAR(200) NULL'],
        ['checks', 'suppressed_by', 'INT UNSIGNED NULL'],
        ['alerts', 'webhook_ok', 'TINYINT(1) NULL'],
        ['alerts', 'kind', "VARCHAR(16) NOT NULL DEFAULT 'notify'"],
        ['checks', 'problem_since', 'DATETIME NULL'],
        ['checks', 'acked_at', 'DATETIME NULL'],
        ['checks', 'acked_by', 'VARCHAR(64) NULL'],
        ['checks', 'ack_comment', 'VARCHAR(200) NULL'],
        ['checks', 'pushover_receipt', 'VARCHAR(40) NULL'],
        ['hosts', 'agent_ip', 'VARCHAR(45) NULL'],
        ['hosts', 'agent_version', 'VARCHAR(20) NULL'],
        ['users', 'totp_secret', 'VARCHAR(64) NULL'],
        ['users', 'totp_last_step', 'BIGINT NULL'],
        ['users', 'last_login', 'DATETIME NULL'],
        ['checks', 'verify_ssl', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['alerts', 'email_ok', 'TINYINT(1) NULL'],
        ['checks', 'metric', 'VARCHAR(16) NULL'],   // container: status | cpu | mem | disk
        ['checks', 'options', 'VARCHAR(500) NULL'],  // JSON, bv. service: diepere controle en extra test
    ];

    private static function migrate(PDO $pdo): void
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        foreach (self::COLUMNS as [$table, $column, $definition]) {
            $st->execute([$table, $column]);
            if (!(int) $st->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
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
