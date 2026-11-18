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
    /**
     * 1.221.0: snelle back-up bij een update: ook de grote geschiedenis, logs en gedownloade lijsten niet (die verandert een
     * update niet; bij herstellen blijven ze staan zoals ze zijn). Plus de logtabellen per dag (logd_JJJJMMDD).
     */
    public const UPDATE_SKIP = ['results', 'results_hourly', 'agent_log', 'edr_events', 'edr_hourly', 'ssh_events', 'net_traffic', 'net_events',
        'perf_stats', 'access_log', 'ids_attempts', 'weblog_attempts', 'ip_blocklist', 'ip_geo', 'mac_vendors', 'vuln_cache'];
    public const TYPES = ['auto' => 'automatisch', 'manual' => 'handmatig', 'pre-restore' => 'voor herstel', 'upload' => 'geüpload', 'update' => 'voor update'];
    /** Veiligheidsback-ups van vóór een herstel: zoveel blijven er bewaard. */
    private const KEEP_PRE_RESTORE = 3;
    private const SQL_HEADER = '-- RzMon-back-up';
    private const SQL_FOOTER = '-- einde';
    private const NAME_RE = '/^rzmon-backup-(\d{8}-\d{6})-(auto|manual|pre-restore|upload|update)\.zip$/';
    /** Grootste zip die je mag uploaden. */
    public const MAX_UPLOAD = 2 * 1024 ** 3;

    /** Bestandssystemen die over het netwerk gaan: dan moet de share gekoppeld zijn voor er geschreven wordt (1.280.0). */
    public const NET_FS = ['nfs', 'nfs4', 'cifs', 'smb3', 'smbfs', 'fuse.sshfs', 'glusterfs', 'ceph'];
    /** Mappen waar back-ups nooit mogen komen (1.280.0) */
    public const FORBIDDEN = ['/bin', '/boot', '/dev', '/etc', '/lib', '/lib64', '/proc', '/root', '/run', '/sbin', '/sys', '/usr', '/var/www', '/var/lib/mysql', '/tmp'];

    public static function dir(): string
    {
        $d = rtrim((string) config('backup_dir', ''), '/');
        return $d !== '' ? $d : self::DEFAULT_DIR;
    }

    /**
     * 1.280.0: op welke koppeling (mount) ligt dit pad? Kijkt naar de diepste map die bestaat.
     * @return array{mount: string, fs: string, network: bool}
     */
    public static function mountOf(string $path): array
    {
        $best = ['mount' => '/', 'fs' => '?'];
        foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $f = explode(' ', $l);
            if (count($f) < 3) {
                continue;
            }
            $m = str_replace(['\\040', '\\011'], [' ', "\t"], $f[1]);
            if (($path === $m || str_starts_with(rtrim($path, '/') . '/', rtrim($m, '/') . '/')) && strlen($m) >= strlen($best['mount'])) {
                $best = ['mount' => $m, 'fs' => $f[2]];
            }
        }
        return $best + ['network' => in_array($best['fs'], self::NET_FS, true)];
    }

    /** 1.280.0: is de netwerkschijf van de back-upmap gekoppeld? (anders zou RzSense ongemerkt op de systeemschijf schrijven) */
    public static function mountMissing(): ?string
    {
        $want = rtrim((string) config('backup_mount', ''), '/');
        if ($want === '') {
            return null;
        }
        return self::mountOf(self::dir())['mount'] === $want ? null : $want;
    }

    /**
     * 1.280.0: is dit een toegestane back-upmap? Absoluut, geen .., niet in systeemmappen of de webmap. '' = de standaard.
     * @return string het opgeschoonde pad ('' = standaard)
     */
    public static function cleanDir(string $d): string
    {
        $d = rtrim(trim($d), '/');
        if ($d === '' || $d === self::DEFAULT_DIR) {
            return '';
        }
        if (!preg_match('#^/[A-Za-z0-9._/-]{1,200}$#', $d) || preg_match('#(^|/)\.\.?(/|$)#', $d) || str_contains($d, '//')) {
            throw new InvalidArgumentException(t('Geef een volledig pad, zoals /mnt/nas/rzsense (letters, cijfers, . _ - en /)'));
        }
        $root = rtrim((string) (realpath(SM_ROOT) ?: SM_ROOT), '/');
        foreach ([...self::FORBIDDEN, $root] as $f) {
            if ($d === $f || str_starts_with($d . '/', $f . '/')) {
                throw new InvalidArgumentException(t('Back-ups mogen niet in {dir}', ['dir' => $f]));
            }
        }
        if (substr_count($d, '/') < 2) {
            throw new InvalidArgumentException(t('Kies een eigen map, niet direct onder / (bijvoorbeeld /mnt/nas/rzsense)'));
        }
        return $d;
    }

    /** 1.280.0: wat er over een (nieuwe) back-upmap te zeggen valt, voor de knop Testen en de kaart */
    public static function dirInfo(string $d): array
    {
        $m = self::mountOf($d);
        $free = @disk_free_space(is_dir($d) ? $d : $m['mount']);
        return ['dir' => $d, 'exists' => is_dir($d), 'writable' => is_dir($d) && is_writable($d), 'mount' => $m['mount'], 'fs' => $m['fs'], 'network' => $m['network'],
            'system' => $m['mount'] === '/', 'free' => $free === false ? null : (int) $free, 'mount_missing' => self::mountMissing()];
    }

    /**
     * 1.280.0 (als root, door de hulpdienst): de back-upmap klaarzetten: aanmaken, groep van de webserver, 0770 en SELinux.
     * @return string[] opmerkingen (geen fouten)
     */
    public static function prepareDir(string $d): array
    {
        $notes = [];
        $m = self::mountOf($d);
        if (!is_dir($d) && !@mkdir($d, 0770, true)) {
            throw new RuntimeException(t('Kan {dir} niet aanmaken', ['dir' => $d]) . ($m['network'] ? ' (' . t('maak de map op de NAS aan en geef de webserver schrijfrechten') . ')' : ''));
        }
        $grp = @filegroup(Installer::HELPER_DIR);
        if ($grp !== false && !@chgrp($d, $grp)) {
            $notes[] = t('Groep zetten lukte niet (bij NFS met root_squash normaal: zet de rechten op de NAS)');
        }
        @chmod($d, 0770);
        if (is_executable('/usr/sbin/selinuxenabled')) {
            $rc = 0;
            $o = [];
            exec('/usr/sbin/selinuxenabled 2>/dev/null', $o, $rc);
            if ($rc === 0) {
                if ($m['network']) {
                    $bool = str_starts_with($m['fs'], 'nfs') ? 'httpd_use_nfs' : 'httpd_use_cifs';
                    exec('/usr/sbin/setsebool -P ' . $bool . ' 1 2>&1', $o, $rc);
                    $notes[] = $rc === 0 ? "SELinux: $bool aan" : t('SELinux: {b} aanzetten mislukt', ['b' => $bool]);
                } else {
                    exec('/usr/sbin/semanage fcontext -a -t httpd_sys_rw_content_t ' . escapeshellarg($d . '(/.*)?') . ' 2>/dev/null', $o, $rc);
                    exec('/usr/sbin/restorecon -R ' . escapeshellarg($d) . ' 2>/dev/null || chcon -R -t httpd_sys_rw_content_t ' . escapeshellarg($d) . ' 2>/dev/null', $o, $rc);
                }
            }
        }
        return $notes;
    }

    /** 1.280.0: bestaande back-ups naar een andere map verhuizen. @return int aantal verhuisd */
    public static function moveAll(string $from, string $to): int
    {
        if (rtrim($from, '/') === rtrim($to, '/') || !is_dir($from) || !is_dir($to)) {
            return 0;
        }
        $n = 0;
        foreach (glob(rtrim($from, '/') . '/rzmon-backup-*.zip') ?: [] as $f) {
            if (preg_match(self::NAME_RE, basename($f)) && !file_exists($to . '/' . basename($f)) && @rename($f, $to . '/' . basename($f))) {
                $n++;
            }
        }
        return $n;
    }

    /** Wat er ontbreekt om back-ups te maken, of null als alles klaar is. */
    public static function problem(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return t('De PHP-extensie zip ontbreekt');
        }
        $d = self::dir();
        if (($mnt = self::mountMissing()) !== null) {   // 1.280.0: anders schrijven we ongemerkt naar de systeemschijf
            return t('De netwerkschijf {m} voor de back-ups is niet gekoppeld', ['m' => $mnt]);
        }
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
            return t('De back-upmap {dir} bestaat niet of RzSense mag er niet in schrijven', ['dir' => $d]);
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
        if (($mnt = self::mountMissing()) !== null) {
            return 'sudo mount ' . $mnt . '   # ' . t('of: sudo mount -a (staat de NAS in /etc/fstab?)');
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
        // setEncryptionName bestaat alleen als PHP met een libzip met versleuteling gebouwd is
        return class_exists('ZipArchive') && defined('ZipArchive::EM_AES_256') && method_exists('ZipArchive', 'setEncryptionName');   // @phpstan-ignore function.alreadyNarrowedType
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
     * @return array{ok: bool, file: ?string, size: int, error: ?string, busy?: bool}
     */
    public static function create(string $type = 'manual', ?bool $history = null, bool $lean = false): array
    {
        if ($p = self::problem()) {
            return ['ok' => false, 'file' => null, 'size' => 0, 'error' => $p];
        }
        if (!self::lock()) {
            return ['ok' => false, 'file' => null, 'size' => 0, 'busy' => true, 'error' => t('Er loopt al een back-up of herstel; probeer het zo opnieuw')];
        }
        try {
            return self::make($type, $history ?? setting('backup_history', '1') === '1', null, $lean);
        } finally {
            self::unlock();
        }
    }

    /** @param string|null $keepSql database-export hier bewaren (voor terugdraaien bij een mislukt herstel) */
    private static function make(string $type, bool $history, ?string $keepSql = null, bool $lean = false): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $dir = self::dir();
        self::prune();   // 1.280.0: ook vóór een back-up opruimen (anders blijft een volle schijf vol)
        $last = self::lastSize($history && !$lean);
        $min = max(50 * 1024 * 1024, (int) ($last * 1.5));
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $last * 9) {
            self::pruneForSpace($last * 9);   // de database-export staat even uitgepakt naast de zip
            clearstatcache();
            $free = @disk_free_space($dir);
        }
        if ($free !== false && $free < $min) {
            return ['ok' => false, 'file' => null, 'size' => 0, 'error' => t('Te weinig ruimte in {dir}: {free} vrij, ongeveer {need} nodig. Verwijder oude back-ups of kies een andere back-upmap (Instellingen → Systeem → Back-up).',
                ['dir' => $dir, 'free' => self::mb((int) $free), 'need' => self::mb($min)])];
        }
        $t = time();
        do {
            $file = sprintf('rzmon-backup-%s-%s.zip', date('Ymd-His', $t++), $type);
        } while (file_exists("$dir/$file"));
        $sql = "$dir/.$file.sql";
        $tmp = "$dir/.$file.tmp";
        try {
            $tables = self::dump($sql, $history, $lean);
            $pass = (string) setting('backup_pass', '');
            if ($pass !== '' && !self::encryptionSupported()) {
                throw new RuntimeException(t('Deze PHP kan geen versleutelde zip maken (libzip te oud)'));
            }
            $config = is_readable(SM_CONFIG_FILE) ? file_get_contents(SM_CONFIG_FILE) : false;
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(t('Kan het zip-bestand niet aanmaken'));
            }
            $zip->addFile($sql, 'database.sql');
            if ($config !== false) {
                $zip->addFromString('config.php', $config);
            }
            $zip->addFromString('info.txt', self::infoText($type, $history && !$lean, $pass !== '', $config !== false, $tables) . ($lean ? "snel (update): grote geschiedenis en logs niet meegenomen\n" : ''));
            if ($pass !== '') {
                foreach (['database.sql', 'config.php'] as $n) {
                    if ($zip->locateName($n) !== false && !$zip->setEncryptionName($n, ZipArchive::EM_AES_256, $pass)) {
                        throw new RuntimeException(t('Versleutelen mislukt'));
                    }
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException(t('Zip opslaan mislukt') . ': ' . $zip->getStatusString());
            }
            if (!rename($tmp, "$dir/$file")) {
                throw new RuntimeException(t('Zip opslaan mislukt'));
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
    private static function dump(string $file, bool $history, bool $lean = false): array
    {
        $pdo = self::pdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // grote tabellen niet in het geheugen
        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new RuntimeException(t('Kan niet schrijven in {dir}', ['dir' => dirname($file)]));
        }
        fwrite($fh, self::SQL_HEADER . ' ' . RZMON_VERSION . ' ' . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        foreach ($tables as $t) {
            if ((!$history && in_array($t, self::HISTORY_TABLES, true)) || ($lean && (in_array($t, self::UPDATE_SKIP, true) || preg_match('/^logd_\d{8}$/', (string) $t)))) {
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
            throw new RuntimeException(t('Schrijven van de database-export mislukt (disk vol?)'));
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
            'versleuteld: ' . ($encrypted ? 'ja (AES-256; open met 7-Zip of herstel via RzSense)' : 'nee'),
            'config.php: ' . ($config ? 'ja' : 'nee'),
            '',
            'Tabellen:',
        ];
        foreach ($tables as $t => $n) {
            $lines[] = "  $t: " . ($n === null ? 'niet meegenomen' : "$n rijen");
        }
        $lines[] = '';
        $lines[] = 'Herstellen: Instellingen → Back-up in RzSense, of: sudo php install/install.php --restore <dit bestand>';
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
            $encrypted = $valid && $st['encryption_method'] !== 0;
            $zip->close();
        }
        $at = DateTime::createFromFormat('Ymd-His', $m[1]);
        return [
            'file' => basename($path),
            'at' => $at ? $at->format('Y-m-d H:i:s') : '',
            'size' => (int) filesize($path),
            'type' => $m[2],
            'type_label' => t(self::TYPES[$m[2]]),
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

    /**
     * Oude automatische en veiligheidsback-ups weg; handmatige alleen als je een aantal instelde (1.133.0, backup_keep_manual,
     * 0 = alles bewaren); geüploade blijven tot je ze verwijdert. @return int aantal weggehaald
     */
    private static function mb(int $b): string
    {
        return $b >= 1024 ** 3 ? round($b / 1024 ** 3, 1) . ' GB' : (int) round($b / 1024 ** 2) . ' MB';
    }

    /** 1.280.0: grootte van de laatste vergelijkbare back-up (met of zonder meethistorie), 0 als er geen is */
    private static function lastSize(bool $history): int
    {
        $any = 0;
        foreach (self::list() as $b) {
            if ($b['valid'] && $b['history'] === $history) {
                return (int) $b['size'];
            }
            $any = $any ?: (int) $b['size'];
        }
        return $any;
    }

    /**
     * 1.280.0: ruimte maken: de oudste automatische, update- en herstel-back-ups weg (van elke soort blijft de nieuwste),
     * tot er $need bytes vrij is. Handmatige en geüploade blijven altijd. @return int aantal weggehaald
     */
    public static function pruneForSpace(int $need): int
    {
        $dir = self::dir();
        $list = array_reverse(self::list());   // oudste eerst
        $count = array_count_values(array_column($list, 'type'));
        $n = 0;
        foreach ($list as $b) {
            clearstatcache();
            $free = @disk_free_space($dir);
            if ($free === false || $free >= $need) {
                break;
            }
            if (in_array($b['type'], ['auto', 'update', 'pre-restore'], true) && $count[$b['type']] > 1 && @unlink($dir . '/' . $b['file'])) {
                $count[$b['type']]--;
                $n++;
            }
        }
        return $n;
    }

    /** 1.280.0: hooguit eens per dag opruimen vanuit cron, ook als er geen back-up gemaakt wordt */
    public static function pruneDaily(): void
    {
        if (setting('backup_pruned_on', '') === date('Y-m-d') || self::problem() !== null) {
            return;
        }
        save_setting('backup_pruned_on', date('Y-m-d'));
        self::prune();
    }

    public static function prune(): int
    {
        $keep = ['auto' => max(1, (int) setting('backup_keep', 7)), 'pre-restore' => self::KEEP_PRE_RESTORE, 'update' => 5];   // 1.221.0: de laatste 5 van updates
        if (($km = (int) setting('backup_keep_manual', '0')) > 0) {
            $keep['manual'] = $km;
        }
        $seen = [];
        $n = 0;
        foreach (self::list() as $b) {   // nieuwste eerst
            $seen[$b['type']] = ($seen[$b['type']] ?? 0) + 1;
            if (isset($keep[$b['type']]) && $seen[$b['type']] > $keep[$b['type']] && @unlink(self::dir() . '/' . $b['file'])) {
                $n++;
            }
        }
        // Restjes van afgebroken back-ups en uploads
        foreach (glob(self::dir() . '/.*.{sql,tmp,part}', GLOB_BRACE) ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $n;
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
            return ['ok' => false, 'error' => t('Upload onderbroken; probeer het opnieuw'), 'file' => null];
        }
        if ($offset + strlen($data) > self::MAX_UPLOAD) {
            @unlink($part);
            return ['ok' => false, 'error' => t('Bestand is te groot (maximaal 2 GB)'), 'file' => null];
        }
        if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) {
            return ['ok' => false, 'error' => t('Opslaan mislukt (disk vol?)'), 'file' => null];
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
            return ['ok' => false, 'error' => $ok ? t('Dit is geen RzSense-back-up (database.sql of info.txt ontbreekt)') : t('Dit is geen geldig zip-bestand'), 'file' => null];
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
            return ['ok' => false, 'error' => t('Er loopt al een back-up of herstel; probeer het zo opnieuw'), 'safety' => null];
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
                    return ['ok' => false, 'error' => t('Veiligheidsback-up mislukt, er is niets veranderd') . ': ' . $s['error'], 'safety' => null];
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
                $msg = t('Herstellen mislukt') . ': ' . $e->getMessage();
                if ($safe && is_file($undo)) {
                    try {
                        self::apply($undo);
                        return ['ok' => false, 'safety' => $safe, 'error' => "$msg. " . t('Er is niets veranderd: de database is weer zoals hij was.')];
                    } catch (Throwable $e2) {
                        $msg .= '; ook terugdraaien mislukte: ' . $e2->getMessage();
                    }
                }
                return ['ok' => false, 'safety' => $safe, 'error' => $msg . ($safe ? ' — ' . t('zet de veiligheidsback-up {file} terug', ['file' => $safe]) : '')];
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
            return ['ok' => false, 'error' => t('Dit is geen geldig zip-bestand')];
        }
        $st = $zip->statName($name);
        if ($st === false) {
            return ['ok' => false, 'error' => t('{file} ontbreekt in de back-up', ['file' => $name])];
        }
        $in = false;
        if ($st['encryption_method'] !== 0) {
            $tries = array_values(array_unique(array_filter([(string) $password, is_configured() ? (string) self::settingSafe('backup_pass') : ''], static fn(string $s): bool => $s !== '')));
            foreach ($tries as $pw) {
                $zip->setPassword($pw);
                if ($in = $zip->getStream($name)) {
                    break;
                }
            }
            if (!$in) {
                return ['ok' => false, 'need_password' => true,
                    'error' => match (true) {
                        !$tries => t('Deze zip is versleuteld; vul het wachtwoord van de zip in'),
                        (string) $password === '' => t('Deze zip heeft een ander wachtwoord dan het ingestelde zip-wachtwoord; vul het wachtwoord van de zip in'),
                        default => t('Het wachtwoord van deze zip klopt niet'),
                    }];
            }
        } else {
            $in = $zip->getStream($name);
        }
        $out = @fopen($dest, 'wb');
        if (!$in || !$out) {
            return ['ok' => false, 'error' => t('Uitpakken mislukt')];
        }
        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        $ok = fclose($out) && $ok;
        @chmod($dest, 0600);
        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => t('Uitpakken mislukt (beschadigde zip of verkeerd wachtwoord)')];
    }

    /** Is dit een complete RzSense-export met alleen de statements die wij zelf schrijven? */
    private static function validate(string $file): ?string
    {
        $fh = fopen($file, 'rb');
        if (!str_starts_with((string) fgets($fh), self::SQL_HEADER)) {
            fclose($fh);
            return t('database.sql is geen RzSense-export');
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
            return t('database.sql bevat onverwachte opdrachten; deze back-up wordt niet teruggezet');
        }
        if ($last !== 'SET FOREIGN_KEY_CHECKS=1' || !str_contains($tail, self::SQL_FOOTER)) {
            return t('database.sql is onvolledig (afgebroken back-up?)');
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
        self::pruneDaily();   // 1.280.0
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
                Monitor::notifySystem('✅ RzSense: ' . t('back-up weer gelukt'), t('De automatische back-up is weer gelukt ({file}).', ['file' => $r['file']]), STATUS_OK, 'recovery');
            }
            return "Back-up gemaakt: {$r['file']}";
        }
        save_setting('backup_last_error', now() . ' ' . $r['error']);
        save_setting('backup_retry_at', date('Y-m-d H:i:s', time() + 3600));
        if (setting('backup_fail_notified', '0') !== '1') {
            save_setting('backup_fail_notified', '1');
            Monitor::notifySystem('❌ RzSense: ' . t('back-up mislukt'), t('De automatische back-up is mislukt: {error}', ['error' => $r['error']]) . "\n\n" . t('RzSense probeert het elk uur opnieuw. Zie Instellingen → Back-up.'), STATUS_CRIT, 'problem');
        }
        return t('Back-up mislukt') . ": {$r['error']}";
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
