<?php
declare(strict_types=1);

/**
 * 1.288.0: de geschiedenis van een overname terug naar de hoofdserver.
 *
 * Deed de reserve het werk (1.287.0), dan staan de metingen, statuswisselingen, meldingen en de EDR- en SSH-gebeurtenissen
 * van die periode alleen op de reserve. Na het teruggeven:
 * - pakt de reserve ze in (JSON-regels, gzip) in delen van hooguit 32 MB ongecomprimeerd (gecomprimeerd veel kleiner, ruim
 *   onder de 50 MB);
 * - stuurt hij er één per minuut naar de hoofdserver (ondertekend en versleuteld, net als de rest);
 * - leest de hoofdserver ze rustig in, in zijn eigen cron: hooguit 30 seconden per minuut, in porties van 500 rijen, zonder
 *   dubbele rijen (wat er al is, blijft). Daarna rekent hij de uren van die periode opnieuw uit voor de grafieken.
 * Pas als alles binnen is, zet de reserve weer volledige kopieën terug.
 */
final class ReserveHistory
{
    /** Per tabel: de tijdkolom en de kolommen die samen zeggen "deze rij is er al" */
    public const TABLES = [
        'results' => ['time' => 'created_at', 'key' => ['check_id', 'created_at']],
        'status_changes' => ['time' => 'created_at', 'key' => ['check_id', 'created_at', 'to_status']],
        'alerts' => ['time' => 'created_at', 'key' => ['created_at', 'title']],
        'edr_events' => ['time' => 'at', 'key' => ['host_id', 'at', 'sig']],
        'ssh_events' => ['time' => 'at', 'key' => ['host_id', 'at', 'ip', 'username']],
    ];
    public const PART_BYTES = 32 * 1024 * 1024;
    /** Grootte van een deel (alleen in de tests kleiner) */
    public static int $partBytes = self::PART_BYTES;
    /** Hooguit zo groot (gecomprimeerd) neemt de hoofdserver een deel aan */
    public const MAX_PART = 50 * 1024 * 1024;
    private const BATCH = 500;
    private const BUDGET = 30;
    /** Metingen van vlak voor het overnemen (de wachtrij van de agents) horen er ook bij */
    private const MARGIN = 600;
    /** @var array<string, list<string>> */
    private static array $cols = [];
    /** @var array<int, int>|null */
    private static ?array $checks = null;
    /** @var array<int, int>|null */
    private static ?array $hosts = null;

    /** Wat insertNew onthoudt (kolommen, bestaande checks en servers) opnieuw ophalen */
    public static function reset(): void
    {
        self::$cols = [];
        self::$checks = self::$hosts = null;
    }

    // ------------------------------------------------------------------ reserve: inpakken en versturen

    /**
     * Een periode inpakken.
     * @param array{from: string, to: string, down?: string} $p
     * @return list<array{file: string, size: int, sha: string, rows: int}>
     */
    public static function export(array $p, string $pid): array
    {
        $dir = Reserve::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new RuntimeException(t('Reserve: kan de map {d} niet maken', ['d' => $dir]));
        }
        [$from, $to] = self::window($p);
        $parts = [];
        $gz = null;
        $bytes = 0;
        $rows = 0;
        $file = '';
        $open = static function () use (&$gz, &$bytes, &$rows, &$file, &$parts, $dir, $pid, $from, $to): void {
            $file = sprintf('out-%s-%03d.jsonl.gz', $pid, count($parts) + 1);
            $gz = gzopen("$dir/$file", 'wb6');
            if ($gz === false) {
                throw new RuntimeException(t('Reserve: schrijven mislukt (schijf vol?)'));
            }
            gzwrite($gz, json_encode(['h' => ['pid' => $pid, 'from' => $from, 'to' => $to, 'part' => count($parts) + 1]]) . "\n");
            $bytes = 0;
            $rows = 0;
        };
        $close = static function () use (&$gz, &$rows, &$file, &$parts, $dir): void {
            gzclose($gz);
            $parts[] = ['file' => $file, 'size' => (int) filesize("$dir/$file"), 'sha' => (string) hash_file('sha256', "$dir/$file"), 'rows' => $rows];
            $gz = null;
        };
        $open();
        $pdo = db();
        foreach (self::TABLES as $t => $d) {
            $st = $pdo->prepare("SELECT * FROM `$t` WHERE `{$d['time']}` BETWEEN ? AND ? ORDER BY `{$d['time']}`");
            $st->execute([$from, $to]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                unset($r['id']);
                $line = json_encode(['t' => $t, 'r' => $r], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                if ($bytes + strlen($line) > self::$partBytes && $rows > 0) {
                    $close();
                    $open();
                }
                gzwrite($gz, $line);
                $bytes += strlen($line);
                $rows++;
            }
        }
        $close();
        return $parts;
    }

    /**
     * @param array{from: string, to: string, down?: string} $p
     * @return array{string, string}
     */
    private static function window(array $p): array
    {
        $start = min(strtotime($p['from']) ?: time(), strtotime((string) ($p['down'] ?? '')) ?: PHP_INT_MAX) - self::MARGIN;
        return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', (strtotime($p['to']) ?: time()) + 60)];
    }

    /**
     * Op de reserve, elke minuut in stand-by: de geschiedenis van de oudste periode die nog niet terug is, één deel per keer.
     * @param callable(string, string, string): array{ok: bool, body: ?string, error: ?string, code: int} $send op, ruwe body → antwoord
     */
    public static function reserveStep(callable $send): ?string
    {
        $periods = json_decode((string) setting('reserve_periods', '[]'), true) ?: [];
        $idx = null;
        foreach ($periods as $i => $p) {
            if (empty($p['sent'])) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            if (setting('reserve_hold_full', '') === '1') {
                save_setting('reserve_hold_full', '');   // alles is terug: volledige kopieën mogen weer
            }
            return null;
        }
        $p = $periods[$idx];
        $st = json_decode((string) setting('reserve_hist', ''), true);
        if (!is_array($st) || ($st['from'] ?? '') !== $p['from']) {
            $pid = substr(hash('sha256', $p['from'] . '|' . $p['to'] . '|' . RZMON_VERSION), 0, 16);
            try {
                $parts = self::export($p, $pid);
            } catch (Throwable $e) {
                return t('Reserve: geschiedenis inpakken mislukt: {e}', ['e' => $e->getMessage()]);
            }
            [$wf, $wt] = self::window($p);
            $st = ['from' => $p['from'], 'to' => $p['to'], 'wfrom' => $wf, 'wto' => $wt, 'pid' => $pid, 'parts' => $parts, 'next' => 0,
                'rows' => array_sum(array_column($parts, 'rows'))];
            save_setting('reserve_hist', (string) json_encode($st));
        }
        $dir = Reserve::dir();
        $n = count($st['parts']);
        if ($st['next'] < $n) {
            $part = $st['parts'][$st['next']];
            $data = @file_get_contents("$dir/{$part['file']}");
            if ($data === false || !hash_equals($part['sha'], hash('sha256', $data))) {
                save_setting('reserve_hist', '');   // weg of beschadigd: opnieuw inpakken
                return t('Reserve: geschiedenis opnieuw inpakken');
            }
            $meta = ['pid' => $st['pid'], 'part' => $st['next'] + 1, 'parts' => $n, 'from' => $st['wfrom'], 'to' => $st['wto'], 'sha' => $part['sha']];
            $r = $send('hist', (string) json_encode($meta), $data);
            if (!$r['ok']) {
                return t('Reserve: geschiedenis versturen mislukt ({e}); de volgende minuut opnieuw', ['e' => $r['error']]);
            }
            $st['next']++;
            save_setting('reserve_hist', (string) json_encode($st));
            return t('Reserve: geschiedenis deel {i} van {n} verstuurd', ['i' => $st['next'], 'n' => $n]);
        }
        $r = $send('hist_done', (string) json_encode(['pid' => $st['pid'], 'parts' => $n]), '');
        $j = $r['ok'] ? json_decode((string) $r['body'], true) : null;
        if (!is_array($j) || empty($j['ok'])) {
            // De hoofdserver mist een deel: die opnieuw sturen
            $missing = is_array($j) ? array_map('intval', (array) ($j['missing'] ?? [])) : [];
            if ($missing) {
                $st['next'] = max(0, min($missing) - 1);
                save_setting('reserve_hist', (string) json_encode($st));
            }
            return t('Reserve: hoofdserver heeft nog niet alles ({e})', ['e' => $r['error'] ?? implode(',', $missing)]);
        }
        foreach ($st['parts'] as $part) {
            @unlink("$dir/{$part['file']}");
        }
        $periods[$idx]['sent'] = true;
        $periods[$idx]['rows'] = $st['rows'];
        save_setting('reserve_periods', (string) json_encode($periods));
        save_setting('reserve_hist', '');
        return t('Reserve: geschiedenis van {from} tot {to} is terug bij de hoofdserver ({n} rijen)', ['from' => $p['from'], 'to' => $p['to'], 'n' => $st['rows']]);
    }

    // ------------------------------------------------------------------ hoofdserver: ontvangen en inlezen

    /**
     * Een deel van de reserve aannemen (op schijf; inlezen gebeurt in de cron).
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null antwoord, of null (= 404)
     */
    public static function receive(array $meta, string $data): ?array
    {
        $pid = (string) ($meta['pid'] ?? '');
        $part = (int) ($meta['part'] ?? 0);
        $parts = (int) ($meta['parts'] ?? 0);
        if (!preg_match('/^[0-9a-f]{16}$/', $pid) || $part < 1 || $parts < 1 || $part > $parts || $parts > 10000 || strlen($data) > self::MAX_PART
            || !hash_equals((string) ($meta['sha'] ?? ''), hash('sha256', $data)) || !self::validTime((string) ($meta['from'] ?? '')) || !self::validTime((string) ($meta['to'] ?? ''))) {
            return null;
        }
        $dir = Reserve::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return ['ok' => false, 'error' => 'map'];
        }
        $f = sprintf('%s/in-%s-%03d.jsonl.gz', $dir, $pid, $part);
        if (file_put_contents("$f.tmp", $data) === false || !rename("$f.tmp", $f)) {
            return ['ok' => false, 'error' => 'schijf'];
        }
        $in = self::inbox();
        $in[$pid] = ($in[$pid] ?? ['from' => (string) $meta['from'], 'to' => (string) $meta['to'], 'parts' => $parts, 'complete' => false,
            'pos' => [1, 0], 'rows' => 0, 'added' => 0, 'at' => now()]);
        $in[$pid]['parts'] = $parts;
        self::saveInbox($in);
        return ['ok' => true];
    }

    /**
     * De reserve zegt "dat was alles": compleet als elk deel er is, anders welke ontbreken.
     * @return array<string, mixed>
     */
    public static function done(string $pid, int $parts): array
    {
        $in = self::inbox();
        if (!isset($in[$pid])) {
            return ['ok' => false, 'missing' => range(1, max(1, $parts))];
        }
        if (!empty($in[$pid]['finished'])) {
            return ['ok' => true];   // al ingelezen (de reserve vraagt het nog eens)
        }
        $missing = [];
        for ($i = max(1, (int) $in[$pid]['pos'][0]); $i <= $parts; $i++) {
            if (!is_file(sprintf('%s/in-%s-%03d.jsonl.gz', Reserve::dir(), $pid, $i))) {
                $missing[] = $i;
            }
        }
        if ($missing) {
            return ['ok' => false, 'missing' => $missing];
        }
        $in[$pid]['complete'] = true;
        $in[$pid]['parts'] = $parts;
        self::saveInbox($in);
        return ['ok' => true];
    }

    /** Hoofdserver, elke minuut: wat binnen is rustig inlezen (hooguit BUDGET seconden). */
    public static function importStep(): ?string
    {
        self::reset();
        $in = self::inbox();
        $t0 = microtime(true);
        foreach ($in as $pid => &$job) {
            if (empty($job['complete']) || !empty($job['finished'])) {
                continue;
            }
            [$part, $line] = [(int) $job['pos'][0], (int) $job['pos'][1]];
            while ($part <= (int) $job['parts'] && microtime(true) - $t0 < self::BUDGET) {
                $f = sprintf('%s/in-%s-%03d.jsonl.gz', Reserve::dir(), $pid, $part);
                [$line, $eof, $rows, $added] = self::importFile($f, $line, $t0);
                $job['rows'] += $rows;
                $job['added'] += $added;
                if (!$eof) {
                    break;   // tijd op: de volgende minuut verder op deze regel
                }
                @unlink($f);
                $part++;
                $line = 0;
            }
            $job['pos'] = [$part, $line];
            if ($part > (int) $job['parts']) {
                $job['finished'] = now();
                // De uren van deze periode opnieuw samenvatten (grafieken van een week of langer)
                $h = date('Y-m-d H:00:00', (int) strtotime($job['from']));
                if ((string) setting('hourly_rolled', '') > $h) {
                    save_setting('hourly_rolled', $h);   // via save_setting: ook de rest van deze cronronde ziet het
                }
                $msg = t('De metingen, meldingen en gebeurtenissen van {from} tot {to} staan weer hier: {a} rijen toegevoegd ({n} bekeken).',
                    ['from' => date('d-m-Y H:i', (int) strtotime($job['from'])), 'to' => date('d-m-Y H:i', (int) strtotime($job['to'])), 'a' => $job['added'], 'n' => $job['rows']]);
                self::saveInbox($in);
                I18n::forNotify(static fn() => Monitor::notifySystem('✅ ' . t('Geschiedenis van de reserve is terug'), $msg, STATUS_OK, 'system'));
                return $msg;
            }
            break;   // één periode per keer
        }
        unset($job);
        self::saveInbox($in);
        return null;
    }

    /**
     * Eén deel inlezen vanaf regel $skip.
     * @return array{int, bool, int, int} [regel waar we zijn, klaar, rijen bekeken, rijen toegevoegd]
     */
    private static function importFile(string $f, int $skip, float $t0): array
    {
        $gz = @gzopen($f, 'rb');
        if ($gz === false) {
            return [$skip, true, 0, 0];   // weg: overslaan
        }
        $line = 0;
        $seen = $added = 0;
        /** @var array<string, list<array<string, mixed>>> $batch */
        $batch = [];
        $eof = true;
        while (($l = gzgets($gz, 4 * 1024 * 1024)) !== false) {
            $line++;
            if ($line <= $skip) {
                continue;
            }
            $j = json_decode($l, true);
            if (is_array($j) && isset($j['t'], $j['r']) && isset(self::TABLES[$j['t']]) && is_array($j['r'])) {
                $batch[$j['t']][] = $j['r'];
                $seen++;
            }
            if ($seen > 0 && $seen % self::BATCH === 0) {
                $added += self::insertBatch($batch);
                $batch = [];
                if (microtime(true) - $t0 >= self::BUDGET) {
                    $eof = false;
                    break;
                }
            }
        }
        $added += self::insertBatch($batch);
        gzclose($gz);
        return [$line, $eof, $seen, $added];
    }

    /** @param array<string, list<array<string, mixed>>> $batch */
    private static function insertBatch(array $batch): int
    {
        $n = 0;
        foreach ($batch as $t => $rows) {
            $n += self::insertNew($t, $rows);
        }
        return $n;
    }

    /**
     * Rijen toevoegen die er nog niet zijn. Alleen bekende kolommen; checks en servers die niet meer bestaan: overslaan
     * (of bij meldingen leeg laten).
     * @param list<array<string, mixed>> $rows
     */
    public static function insertNew(string $t, array $rows): int
    {
        $d = self::TABLES[$t] ?? null;
        if ($d === null || !$rows) {
            return 0;
        }
        $cols = self::$cols[$t] ??= array_values(array_diff(q("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN), ['id']));
        $checks = self::$checks ??= array_flip(array_map('intval', q('SELECT id FROM checks')->fetchAll(PDO::FETCH_COLUMN)));
        $hosts = self::$hosts ??= array_flip(array_map('intval', q('SELECT id FROM hosts')->fetchAll(PDO::FETCH_COLUMN)));
        $ok = [];
        foreach ($rows as $r) {
            if (in_array($t, ['results', 'status_changes'], true) && !isset($checks[(int) ($r['check_id'] ?? 0)])) {
                continue;
            }
            if (in_array($t, ['edr_events', 'ssh_events'], true) && !isset($hosts[(int) ($r['host_id'] ?? 0)])) {
                continue;
            }
            if ($t === 'alerts') {
                if (isset($r['check_id']) && !isset($checks[(int) $r['check_id']])) {
                    $r['check_id'] = null;
                }
                if (isset($r['host_id']) && !isset($hosts[(int) $r['host_id']])) {
                    $r['host_id'] = null;
                }
            }
            $missingKey = false;
            foreach ($d['key'] as $k) {
                $missingKey = $missingKey || !isset($r[$k]);
            }
            if (!$missingKey) {
                $ok[] = array_intersect_key($r, array_flip($cols));
            }
        }
        if (!$ok) {
            return 0;
        }
        // Welke zijn er al? (in één vraag per portie)
        $kc = '`' . implode('`, `', $d['key']) . '`';
        $tuple = '(' . implode(', ', array_fill(0, count($d['key']), '?')) . ')';
        $args = [];
        foreach ($ok as $r) {
            foreach ($d['key'] as $k) {
                $args[] = $r[$k];
            }
        }
        $have = [];
        foreach (q("SELECT $kc FROM `$t` WHERE ($kc) IN (" . implode(', ', array_fill(0, count($ok), $tuple)) . ')', $args)->fetchAll(PDO::FETCH_NUM) as $row) {
            $have[implode("\x1f", array_map('strval', $row))] = true;
        }
        $n = 0;
        foreach ($ok as $r) {
            $key = implode("\x1f", array_map(static fn($k) => (string) $r[$k], $d['key']));
            if (isset($have[$key])) {
                continue;
            }
            $have[$key] = true;   // ook dubbel binnen dezelfde portie maar één keer
            $c = array_keys($r);
            q("INSERT INTO `$t` (`" . implode('`, `', $c) . '`) VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')', array_values($r));
            $n++;
        }
        return $n;
    }

    private static function validTime(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $s);
    }

    /** @return array<string, array<string, mixed>> */
    public static function inbox(): array
    {
        $in = json_decode((string) setting('reserve_hist_in', '{}'), true);
        return is_array($in) ? $in : [];
    }

    /** @param array<string, array<string, mixed>> $in */
    private static function saveInbox(array $in): void
    {
        // De laatste 10 (ingelezen) bewaren voor het scherm
        uasort($in, static fn($a, $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));
        save_setting('reserve_hist_in', (string) json_encode(array_slice($in, -10, null, true)));
    }
}
