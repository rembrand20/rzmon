<?php
declare(strict_types=1);

/**
 * Back-ups als zip: database.sql (de hele database, eventueel zonder meethistorie), config.php en info.txt.
 * database.sql en config.php worden met AES-256 versleuteld als er een back-upwachtwoord is ingesteld.
 * Alles in PHP zelf (geen mysqldump nodig), dus het werkt ook vanuit de webserver onder SELinux.
 */
final class Backup
{
    /** Standaardmap, buiten de webmap (instelbaar met 'backup_dir' in config.php). */
    public const DEFAULT_DIR = Installer::HELPER_DIR . '/backups';
    /** Tabellen met meethistorie: alleen in de back-up als "Meethistorie meenemen" aan staat. */
    public const HISTORY_TABLES = ['results', 'agent_log'];
    public const TYPES = ['auto' => 'automatisch', 'manual' => 'handmatig', 'pre-restore' => 'voor herstel', 'upload' => 'geüpload'];
    /** Veiligheidsback-ups van vóór een herstel: zoveel blijven er bewaard. */
    private const KEEP_PRE_RESTORE = 3;
    private const SQL_HEADER = '-- RzMon-back-up';
    private const SQL_FOOTER = '-- einde';
    private const NAME_RE = '/^rzmon-backup-(\d{8}-\d{6})-(auto|manual|pre-restore|upload)\.zip$/';
    /** Grootste zip die je mag uploaden. */
    public const MAX_UPLOAD = 2 * 1024 ** 3;

    public static function dir(): string
    {
        return rtrim((string) config('backup_dir', self::DEFAULT_DIR), '/');
    }

    /** Wat er ontbreekt om back-ups te maken, of null als alles klaar is. */
    public static function problem(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return 'De PHP-extensie zip ontbreekt';
        }
        $d = self::dir();
        if (!is_dir($d) && @mkdir($d, 0770, true) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Door root aangemaakt (bv. herstel op een nieuwe server): ook de webserver moet erin kunnen
            foreach (['apache', 'www-data', 'nginx', 'http'] as $g) {
                if (posix_getgrnam($g)) {
                    @chgrp($d, $g);
                    if (@filegroup(dirname($d)) === 0) {
                        @chgrp(dirname($d), $g);
                    }
                    break;
                }
            }
        }
        if (!is_dir($d) || !is_writable($d)) {
            return "De back-upmap $d bestaat niet of RzMon mag er niet in schrijven";
        }
        return null;
    }

    /** Commando om het probleem van problem() op te lossen (als root). */
    public static function fixCommand(): string
    {
        if (!class_exists('ZipArchive')) {
            return is_file('/etc/redhat-release')
                ? 'sudo dnf -y install php-zip && sudo systemctl restart php-fpm httpd'
                : 'sudo apt install -y php-zip && sudo systemctl restart apache2';
        }
        if (self::dir() === self::DEFAULT_DIR) {
            return 'sudo bash ' . realpath(SM_ROOT) . '/install/install-helper.sh';   // map + SELinux in één keer
        }
        $group = 'apache';
        if (function_exists('posix_getegid') && posix_geteuid() !== 0) {
            $group = posix_getgrgid(posix_getegid())['name'] ?? $group;
        }
        return sprintf('sudo install -d -m 0770 -o root -g %s %s', $group, self::dir());
    }

    public static function encryptionSupported(): bool
    {
        return class_exists('ZipArchive') && defined('ZipArchive::EM_AES_256') && method_exists('ZipArchive', 'setEncryptionName');
    }

    /** Volledig pad van een back-up uit de lijst, of null (ook bij een ongeldige naam). */
    public static function path(string $file): ?string
    {
        if (!preg_match(self::NAME_RE, $file)) {
            return null;
        }
        $p = self::dir() . '/' . $file;
        return is_file($p) ? $p : null;
    }

    // ------------------------------------------------------------------ maken

    /**
     * Back-up maken.
     * @param string $type auto | manual | pre-restore
     * @param bool|null $history meethistorie meenemen (null = volgens de instelling)
     * @return array{ok: bool, file: ?string, size: int, error: ?string}
     */
    public static function create(string $type = 'manual', ?bool $history = null): array
    {
        if ($p = self::problem()) {
            return ['ok' => false, 'file' => null, 'size' => 0, 'error' => $p];
        }
        if (!self::lock()) {
            return ['ok' => false, 'file' => null, 'size' => 0, 'busy' => true, 'error' => 'Er loopt al een back-up of herstel; probeer het zo opnieuw'];
        }
        try {
            return self::make($type, $history ?? setting('backup_history', '1') === '1');
        } finally {
            self::unlock();
        }
    }

    /** @param string|null $keepSql database-export hier bewaren (voor terugdraaien bij een mislukt herstel) */
    private static function make(string $type, bool $history, ?string $keepSql = null): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $dir = self::dir();
        $free = @disk_free_space($dir);
        if ($free !== false && $free < 50 * 1024 * 1024) {
            return ['ok' => false, 'file' => null, 'size' => 0, 'error' => "Minder dan 50 MB vrij in $dir"];
        }
        $t = time();
        do {
            $file = sprintf('rzmon-backup-%s-%s.zip', date('Ymd-His', $t++), $type);
        } while (file_exists("$dir/$file"));
        $sql = "$dir/.$file.sql";
        $tmp = "$dir/.$file.tmp";
        try {
            $tables = self::dump($sql, $history);
            $pass = (string) setting('backup_pass', '');
            if ($pass !== '' && !self::encryptionSupported()) {
                throw new RuntimeException('Deze PHP kan geen versleutelde zip maken (libzip te oud)');
            }
            $config = is_readable(SM_CONFIG_FILE) ? file_get_contents(SM_CONFIG_FILE) : false;
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Kan het zip-bestand niet aanmaken');
            }
            $zip->addFile($sql, 'database.sql');
            if ($config !== false) {
                $zip->addFromString('config.php', $config);
            }
            $zip->addFromString('info.txt', self::infoText($type, $history, $pass !== '', $config !== false, $tables));
            if ($pass !== '') {
                foreach (['database.sql', 'config.php'] as $n) {
                    if ($zip->locateName($n) !== false && !$zip->setEncryptionName($n, ZipArchive::EM_AES_256, $pass)) {
                        throw new RuntimeException('Versleutelen mislukt');
                    }
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('Zip opslaan mislukt: ' . $zip->getStatusString());
            }
            if (!rename($tmp, "$dir/$file")) {
                throw new RuntimeException('Zip opslaan mislukt');
            }
        } catch (Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'file' => null, 'size' => 0, 'error' => $e->getMessage()];
        } finally {
            if ($keepSql === null || !@rename($sql, $keepSql)) {
                @unlink($sql);
            }
        }
        self::own("$dir/$file");
        self::prune();
        return ['ok' => true, 'file' => $file, 'size' => (int) filesize("$dir/$file"), 'error' => null];
    }

    /**
     * Database naar een SQL-bestand: per tabel DROP + CREATE + INSERTs (200 rijen per regel).
     * Elke INSERT staat op één regel (regeleinden in teksten worden \n), zodat herstellen regel voor regel kan.
     * @return array<string, int|null> rijen per tabel (null = zonder gegevens)
     */
    private static function dump(string $file, bool $history): array
    {
        $pdo = self::pdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // grote tabellen niet in het geheugen
        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new RuntimeException("Kan niet schrijven in " . dirname($file));
        }
        fwrite($fh, self::SQL_HEADER . ' ' . RZMON_VERSION . ' ' . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        foreach ($tables as $t) {
            if (!$history && in_array($t, self::HISTORY_TABLES, true)) {
                // Tabel blijft bij herstellen staan zoals hij is
                fwrite($fh, "-- $t: zonder gegevens (meethistorie niet meegenomen)\n");
                $counts[$t] = null;
                continue;
            }
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
            $st = $pdo->query("SELECT * FROM `$t`");
            $cols = null;
            $rows = [];
            $n = 0;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '(`' . implode('`, `', array_keys($row)) . '`)';
                $rows[] = '(' . implode(', ', array_map(
                    static fn($v) => $v === null ? 'NULL' : (is_int($v) ? (string) $v : $pdo->quote((string) $v)), $row)) . ')';
                $n++;
                if (count($rows) === 200) {
                    fwrite($fh, "INSERT INTO `$t` $cols VALUES " . implode(', ', $rows) . ";\n");
                    $rows = [];
                }
            }
            if ($rows) {
                fwrite($fh, "INSERT INTO `$t` $cols VALUES " . implode(', ', $rows) . ";\n");
            }
            $counts[$t] = $n;
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n" . self::SQL_FOOTER . "\n");
        if (!fclose($fh)) {
            throw new RuntimeException('Schrijven van de database-export mislukt (disk vol?)');
        }
        return $counts;
    }

    private static function infoText(string $type, bool $history, bool $encrypted, bool $config, array $tables): string
    {
        $lines = [
            'RzMon-back-up',
            'versie: ' . RZMON_VERSION,
            'gemaakt: ' . now(),
            'server: ' . (gethostname() ?: '?'),
            'soort: ' . $type,
            'meethistorie: ' . ($history ? 'ja' : 'nee'),
            'versleuteld: ' . ($encrypted ? 'ja (AES-256; open met 7-Zip of herstel via RzMon)' : 'nee'),
            'config.php: ' . ($config ? 'ja' : 'nee'),
            '',
            'Tabellen:',
        ];
        foreach ($tables as $t => $n) {
            $lines[] = "  $t: " . ($n === null ? 'niet meegenomen' : "$n rijen");
        }
        $lines[] = '';
        $lines[] = 'Herstellen: Instellingen → Back-up in RzMon, of: sudo php install/install.php --restore <dit bestand>';
        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------ lijst en opruimen

    /** @return list<array<string, mixed>> nieuwste eerst */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/rzmon-backup-*.zip') ?: [] as $p) {
            if ($i = self::info($p)) {
                $out[] = $i;
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }

    /** Gegevens van één back-up (uit de naam, info.txt en de zip zelf); null bij een ongeldig bestand. */
    public static function info(string $path): ?array
    {
        if (!preg_match(self::NAME_RE, basename($path), $m) || !class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        $meta = [];
        $encrypted = false;
        $valid = $zip->open($path, ZipArchive::RDONLY) === true;
        if ($valid) {
            foreach (explode("\n", (string) $zip->getFromName('info.txt')) as $l) {
                if (preg_match('/^([\w.]+): (.*)$/', $l, $kv)) {
                    $meta[$kv[1]] = $kv[2];
                }
            }
            $st = $zip->statName('database.sql');
            $valid = $st !== false;
            $encrypted = $valid && ($st['encryption_method'] ?? 0) !== 0;
            $zip->close();
        }
        $at = DateTime::createFromFormat('Ymd-His', $m[1]);
        return [
            'file' => basename($path),
            'at' => $at ? $at->format('Y-m-d H:i:s') : '',
            'size' => (int) filesize($path),
            'type' => $m[2],
            'type_label' => self::TYPES[$m[2]],
            'valid' => $valid,
            'version' => $meta['versie'] ?? null,
            'server' => $meta['server'] ?? null,
            'history' => ($meta['meethistorie'] ?? 'ja') === 'ja',
            'config' => ($meta['config.php'] ?? 'nee') === 'ja',
            'encrypted' => $encrypted,
        ];
    }

    public static function delete(string $file): bool
    {
        $p = self::path($file);
        return $p !== null && @unlink($p);
    }

    /** Oude automatische en veiligheidsback-ups weg; handmatige en geüploade blijven tot je ze verwijdert. */
    public static function prune(): void
    {
        $keep = ['auto' => max(1, (int) setting('backup_keep', 7)), 'pre-restore' => self::KEEP_PRE_RESTORE];
        $seen = [];
        foreach (self::list() as $b) {
            $seen[$b['type']] = ($seen[$b['type']] ?? 0) + 1;
            if (isset($keep[$b['type']]) && $seen[$b['type']] > $keep[$b['type']]) {
                @unlink(self::dir() . '/' . $b['file']);
            }
        }
        // Restjes van afgebroken back-ups en uploads
        foreach (glob(self::dir() . '/.*.{sql,tmp,part}', GLOB_BRACE) ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------ uploaden

    /**
     * Stukje van een upload toevoegen (de browser stuurt de zip in delen van 1 MB, dan maakt
     * de upload-limiet van PHP niet uit). Bij het laatste stuk wordt de zip gecontroleerd.
     * @return array{ok: bool, error: ?string, file: ?string}
     */
    public static function uploadChunk(string $id, int $offset, string $data, bool $final): array
    {
        if ($p = self::problem()) {
            return ['ok' => false, 'error' => $p, 'file' => null];
        }
        if (!preg_match('/^[a-f0-9]{16,32}$/', $id)) {
            return ['ok' => false, 'error' => 'Ongeldige upload', 'file' => null];
        }
        $part = self::dir() . "/.upload-$id.part";
        if ($offset === 0) {
            @unlink($part);
        }
        clearstatcache(true, $part);
        if ((is_file($part) ? filesize($part) : 0) !== $offset) {
            return ['ok' => false, 'error' => 'Upload onderbroken; probeer het opnieuw', 'file' => null];
        }
        if ($offset + strlen($data) > self::MAX_UPLOAD) {
            @unlink($part);
            return ['ok' => false, 'error' => 'Bestand is te groot (maximaal 2 GB)', 'file' => null];
        }
        if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Opslaan mislukt (disk vol?)', 'file' => null];
        }
        if (!$final) {
            return ['ok' => true, 'error' => null, 'file' => null];
        }
        $zip = new ZipArchive();
        $ok = $zip->open($part, ZipArchive::RDONLY) === true;
        $isBackup = $ok && $zip->statName('database.sql') !== false
            && str_starts_with((string) $zip->getFromName('info.txt'), 'RzMon-back-up');
        if ($ok) {
            $zip->close();
        }
        if (!$isBackup) {
            @unlink($part);
            return ['ok' => false, 'error' => $ok ? 'Dit is geen RzMon-back-up (database.sql of info.txt ontbreekt)' : 'Dit is geen geldig zip-bestand', 'file' => null];
        }
        $t = time();
        do {
            $file = sprintf('rzmon-backup-%s-upload.zip', date('Ymd-His', $t++));
        } while (file_exists(self::dir() . "/$file"));
        rename($part, self::dir() . "/$file");
        self::own(self::dir() . "/$file");
        return ['ok' => true, 'error' => null, 'file' => $file];
    }

    // ------------------------------------------------------------------ herstellen

    /**
     * Database terugzetten uit een back-up. Eerst wordt een veiligheidsback-up gemaakt (met historie).
     * De back-upinstellingen (schema, wachtwoord) blijven zoals ze nu zijn.
     * config.php wordt hier niet teruggezet: dat kan alleen als root (install.php --restore --with-config).
     * @return array{ok: bool, error: ?string, safety: ?string, need_password?: bool}
     */
    public static function restore(string $path, ?string $password = null, bool $safety = true): array
    {
        if ($p = self::problem()) {
            return ['ok' => false, 'error' => $p, 'safety' => null];
        }
        if (!self::lock()) {
            return ['ok' => false, 'error' => 'Er loopt al een back-up of herstel; probeer het zo opnieuw', 'safety' => null];
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $tmp = self::dir() . '/.restore-' . bin2hex(random_bytes(6)) . '.sql';
        $undo = "$tmp.undo.sql";
        try {
            $r = self::extract($path, 'database.sql', $tmp, $password);
            if (!$r['ok']) {
                return $r + ['safety' => null];
            }
            if ($err = self::validate($tmp)) {
                return ['ok' => false, 'error' => $err, 'safety' => null];
            }
            $safe = null;
            if ($safety) {
                $s = self::make('pre-restore', true, $undo);
                if (!$s['ok']) {
                    return ['ok' => false, 'error' => 'Veiligheidsback-up mislukt, er is niets veranderd: ' . $s['error'], 'safety' => null];
                }
                $safe = $s['file'];
            }
            try {
                $keepSettings = q("SELECT name, value FROM settings WHERE name LIKE 'backup\\_%'")->fetchAll();
            } catch (PDOException) {
                $keepSettings = [];   // lege database (nieuwe server)
            }
            try {
                self::apply($tmp);
            } catch (Throwable $e) {
                // Halverwege mislukt: de toestand van vlak ervoor terugzetten (alles of niets)
                $msg = 'Herstellen mislukt: ' . $e->getMessage();
                if ($safe && is_file($undo)) {
                    try {
                        self::apply($undo);
                        return ['ok' => false, 'safety' => $safe, 'error' => "$msg. Er is niets veranderd: de database is weer zoals hij was."];
                    } catch (Throwable $e2) {
                        $msg .= '; ook terugdraaien mislukte: ' . $e2->getMessage();
                    }
                }
                return ['ok' => false, 'safety' => $safe, 'error' => $msg . ($safe ? " — zet de veiligheidsback-up $safe terug" : '')];
            }
            foreach ($keepSettings as $s) {
                save_setting($s['name'], (string) $s['value']);
            }
            Installer::runSchema();   // back-up van een oudere versie: tabellen bijwerken
            return ['ok' => true, 'error' => null, 'safety' => $safe];
        } finally {
            @unlink($tmp);
            @unlink($undo);
            self::unlock();
        }
    }

    /**
     * Eén bestand uit de zip naar schijf, met het opgegeven of het ingestelde back-upwachtwoord.
     * @return array{ok: bool, error: ?string, need_password?: bool}
     */
    public static function extract(string $path, string $name, string $dest, ?string $password = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return ['ok' => false, 'error' => 'Dit is geen geldig zip-bestand'];
        }
        $st = $zip->statName($name);
        if ($st === false) {
            return ['ok' => false, 'error' => "$name ontbreekt in de back-up"];
        }
        $in = false;
        if (($st['encryption_method'] ?? 0) !== 0) {
            $tries = array_values(array_unique(array_filter([(string) $password, is_configured() ? (string) self::settingSafe('backup_pass') : ''], 'strlen')));
            foreach ($tries as $pw) {
                $zip->setPassword($pw);
                if ($in = $zip->getStream($name)) {
                    break;
                }
            }
            if (!$in) {
                return ['ok' => false, 'need_password' => true,
                    'error' => match (true) {
                        !$tries => 'Deze zip is versleuteld; vul het wachtwoord van de zip in',
                        (string) $password === '' => 'Deze zip heeft een ander wachtwoord dan het ingestelde zip-wachtwoord; vul het wachtwoord van de zip in',
                        default => 'Het wachtwoord van deze zip klopt niet',
                    }];
            }
        } else {
            $in = $zip->getStream($name);
        }
        $out = @fopen($dest, 'wb');
        if (!$in || !$out) {
            return ['ok' => false, 'error' => 'Uitpakken mislukt'];
        }
        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        $ok = fclose($out) && $ok;
        @chmod($dest, 0600);
        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'Uitpakken mislukt (beschadigde zip of verkeerd wachtwoord)'];
    }

    /** Is dit een complete RzMon-export met alleen de statements die wij zelf schrijven? */
    private static function validate(string $file): ?string
    {
        $fh = fopen($file, 'rb');
        if (!str_starts_with((string) fgets($fh), self::SQL_HEADER)) {
            fclose($fh);
            return 'database.sql is geen RzMon-export';
        }
        $last = '';
        $ok = true;
        foreach (self::statements($fh) as $stmt) {
            if (!preg_match('/^(DROP TABLE IF EXISTS `\w+`|CREATE TABLE `\w+` \(|INSERT INTO `\w+` \(|SET FOREIGN_KEY_CHECKS=[01]$)/', $stmt)) {
                $ok = false;
                break;
            }
            $last = $stmt;
        }
        fseek($fh, -64, SEEK_END);
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        if (!$ok) {
            return 'database.sql bevat onverwachte opdrachten; deze back-up wordt niet teruggezet';
        }
        if ($last !== 'SET FOREIGN_KEY_CHECKS=1' || !str_contains($tail, self::SQL_FOOTER)) {
            return 'database.sql is onvolledig (afgebroken back-up?)';
        }
        return null;
    }

    private static function apply(string $file): void
    {
        $pdo = self::pdo();
        $fh = fopen($file, 'rb');
        try {
            foreach (self::statements($fh) as $stmt) {
                $pdo->exec($stmt);
            }
        } finally {
            fclose($fh);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        // Back-up zonder meethistorie: historie van checks die niet meer bestaan opruimen
        $pdo->exec('DELETE r FROM results r LEFT JOIN checks c ON c.id = r.check_id WHERE c.id IS NULL');
    }

    /** Statements uit onze export: regels tot een regel die op ; eindigt; commentaar overslaan. */
    private static function statements($fh): Generator
    {
        $stmt = '';
        while (($line = fgets($fh)) !== false) {
            if ($stmt === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $stmt .= $line;
            $trim = rtrim($line, "\r\n");
            if (str_ends_with($trim, ';')) {
                yield substr(rtrim($stmt, "\r\n"), 0, -1);
                $stmt = '';
            }
        }
    }

    /**
     * config.php uit een back-up inlezen zonder er code uit uit te voeren: alleen een "return array(...)"
     * met teksten, getallen, true/false/null is toegestaan (wat setup en var_export schrijven).
     */
    public static function readConfig(string $file): ?array
    {
        $php = (string) file_get_contents($file);
        $allowed = [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_RETURN, T_ARRAY, T_CONSTANT_ENCAPSED_STRING,
            T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW];
        foreach (token_get_all($php) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_STRING && in_array(strtolower($t[1]), ['true', 'false', 'null'], true)) {
                    continue;
                }
                if (!in_array($t[0], $allowed, true)) {
                    return null;
                }
            } elseif (!in_array($t, ['(', ')', '[', ']', ',', ';', '-'], true)) {
                return null;
            }
        }
        $cfg = include $file;
        return is_array($cfg) && !empty($cfg['db']['name']) ? $cfg : null;
    }

    // ------------------------------------------------------------------ automatisch (cron)

    /** Laatste geplande moment op of vóór $now, of null als automatische back-ups uit staan. */
    public static function lastSlot(?int $now = null): ?int
    {
        $now ??= time();
        $freq = setting('backup_freq', 'off');
        [$h, $m] = array_map('intval', explode(':', (string) setting('backup_time', '03:15') . ':0'));
        $y = (int) date('Y', $now);
        $mo = (int) date('n', $now);
        $d = (int) date('j', $now);
        switch ($freq) {
            case 'daily':
                $slot = mktime($h, $m, 0, $mo, $d, $y);
                return $slot <= $now ? $slot : mktime($h, $m, 0, $mo, $d - 1, $y);
            case 'weekly':
                $back = ((int) date('N', $now) - (int) setting('backup_weekday', 7) + 7) % 7;
                $slot = mktime($h, $m, 0, $mo, $d - $back, $y);
                return $slot <= $now ? $slot : mktime($h, $m, 0, $mo, $d - $back - 7, $y);
            case 'monthly':
                $slot = mktime($h, $m, 0, $mo, 1, $y);
                return $slot <= $now ? $slot : mktime($h, $m, 0, $mo - 1, 1, $y);
        }
        return null;
    }

    /** Volgende geplande back-up (voor de instellingenpagina). */
    public static function nextSlot(): ?int
    {
        $last = self::lastSlot();
        if ($last === null) {
            return null;
        }
        [$y, $mo, $d, $h, $m] = array_map('intval', explode(' ', date('Y n j G i', $last)));
        return match (setting('backup_freq')) {
            'daily' => mktime($h, $m, 0, $mo, $d + 1, $y),
            'weekly' => mktime($h, $m, 0, $mo, $d + 7, $y),
            default => mktime($h, $m, 0, $mo + 1, 1, $y),
        };
    }

    /** Vanuit cron: back-up maken als het tijd is. Mislukt hij, dan elk uur opnieuw tot het lukt. */
    public static function runScheduled(): ?string
    {
        $slot = self::lastSlot();
        if ($slot === null) {
            return null;
        }
        $last = (string) setting('backup_last_auto', '');
        if ($last === '') {
            save_setting('backup_last_auto', now());   // net aangezet: wachten op het eerste geplande moment
            return null;
        }
        if (strtotime($last) >= $slot || strtotime((string) setting('backup_retry_at', '')) > time()) {
            return null;
        }
        $r = self::create('auto');
        if (!empty($r['busy'])) {
            return null;   // een andere run (of een handmatige back-up) is al bezig
        }
        if ($r['ok']) {
            save_setting('backup_last_auto', now());
            save_setting('backup_last_error', '');
            save_setting('backup_retry_at', '');
            if (setting('backup_fail_notified', '0') === '1') {
                save_setting('backup_fail_notified', '0');
                Monitor::notifySystem('✅ RzMon: back-up weer gelukt', "De automatische back-up is weer gelukt ({$r['file']}).", STATUS_OK, 'recovery');
            }
            return "Back-up gemaakt: {$r['file']}";
        }
        save_setting('backup_last_error', now() . ' ' . $r['error']);
        save_setting('backup_retry_at', date('Y-m-d H:i:s', time() + 3600));
        if (setting('backup_fail_notified', '0') !== '1') {
            save_setting('backup_fail_notified', '1');
            Monitor::notifySystem('❌ RzMon: back-up mislukt', "De automatische back-up is mislukt: {$r['error']}\n\nRzMon probeert het elk uur opnieuw. Zie Instellingen → Back-up.", STATUS_CRIT, 'problem');
        }
        return "Back-up mislukt: {$r['error']}";
    }

    // ------------------------------------------------------------------ hulpjes

    /** Eigen verbinding: geen meerdere statements per exec (veiliger bij herstellen). */
    private static function pdo(): PDO
    {
        $c = config('db');
        return new PDO(
            "mysql:host={$c['host']};port=" . (int) ($c['port'] ?? 3306) . ";dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]
        );
    }

    /** Eén back-up of herstel tegelijk (ook tussen webserver, cron en root). */
    private static function lock(): bool
    {
        return (int) db()->query("SELECT GET_LOCK('rzmon_backup', 0)")->fetchColumn() === 1;
    }

    private static function unlock(): void
    {
        db()->query("SELECT RELEASE_LOCK('rzmon_backup')");
    }

    /** Als root gemaakt: leesbaar voor de webserver (groep van de map), voor niemand anders. */
    private static function own(string $file): void
    {
        @chmod($file, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($g = @filegroup(dirname($file))) !== false) {
            @chgrp($file, $g);
        }
    }

    private static function settingSafe(string $name): ?string
    {
        try {
            return setting($name);
        } catch (Throwable) {
            return null;
        }
    }
}
