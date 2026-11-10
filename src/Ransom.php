<?php
declare(strict_types=1);

/**
 * Ransomware herkennen (1.228.0, agent 1.90.0).
 * - Per server kies je in de Agent Manager welke mappen bewaakt worden (de agent stelt Samba-shares, NFS-exports, de shares
 *   van TrueNAS en /home voor), en wat de agent bij een sterk signaal doet: een snapshot maken (standaard aan) en/of de
 *   schrijvende processen stoppen (standaard uit; aanzetten met je wachtwoord). Lokaasbestanden staan standaard aan.
 * - De agent meldt per map: hoeveel bestanden er sinds de vorige ronde veranderden, ransomware-extensies, losgeldbriefjes,
 *   versleutelde tekstbestanden en de lokaasbestanden. RzSense leert per map wat normaal is (het hoogste aantal per uur,
 *   7 dagen) en geeft de agent een drempel: max(ransom_min, 4 × het 95e percentiel).
 * - Sterk signaal = critical IDS-incident 'ransom' (met push); alleen heel veel wijzigingen = warning 'ransom_mass'.
 * Aan/uit: ransom_on (Instellingen → Overzicht → Functies of IDS → Ransomware).
 */
final class Ransom
{
    public static function enabled(): bool
    {
        return setting('ransom_on', '1') === '1';
    }

    /** Ingestelde mappen van een server */
    public static function paths(array $host): array
    {
        return array_values(array_filter((array) json_decode((string) ($host['ransom_paths'] ?? ''), true), 'is_string'));
    }

    /** Sleutel van een map, zoals de agent hem maakt (sha1, 12 tekens) */
    public static function key(string $path): string
    {
        return substr(sha1($path), 0, 12);
    }

    /** Regels voor de agent */
    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $h = q('SELECT id, ransom_paths, ransom_snap, ransom_kill, ransom_canary FROM hosts WHERE id = ?', [$hostId])->fetch();
        $paths = $h ? self::paths($h) : [];
        if (!$paths) {
            return [];
        }
        $base = self::base($hostId);
        $out = [implode("\t", [0, 'ransom', (int) $h['ransom_snap'], (int) $h['ransom_kill'], (int) $h['ransom_canary']])];
        foreach (array_slice($paths, 0, 20) as $p) {
            $out[] = implode("\t", [0, 'ransompath', base64_encode($p), self::threshold($base[self::key($p)] ?? null)]);
        }
        return $out;
    }

    private static function base(int $hostId): array
    {
        return (array) json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'ransom_base'", [$hostId])->fetchColumn(), true);
    }

    /** Drempel voor "heel veel tegelijk" uit wat normaal is (hoogste aantal per uur, laatste 7 dagen) */
    public static function threshold(?array $b): int
    {
        $min = max(20, (int) setting('ransom_min', '300'));
        $hist = array_map('intval', (array) ($b['hist'] ?? []));
        if (count($hist) < 72) {   // nog geen 3 dagen: de vaste ondergrens
            return $min;
        }
        sort($hist);
        $p95 = $hist[(int) floor(0.95 * (count($hist) - 1))];
        return max($min, 4 * $p95);
    }

    /** Het rapport van de agent verwerken */
    public static function fromAgent(array $host, array $in, PDOStatement $st, string $now): void
    {
        $r = $in['ransom'] ?? null;
        if (!is_array($r)) {
            return;
        }
        $hid = (int) $host['id'];
        $paths = array_slice((array) ($r['paths'] ?? []), 0, 20);
        $cand = array_values(array_filter(array_map(static fn($c) => is_array($c) && isset($c[1]) && is_string($c[1]) && str_starts_with($c[1], '/')
            ? [preg_replace('/[^a-z]/', '', (string) ($c[0] ?? '')), mb_substr($c[1], 0, 300)] : null, array_slice((array) ($r['cand'] ?? []), 0, 50))));
        $st->execute([$hid, 'ransom', json_encode(['at' => time(), 'on' => !empty($r['on']), 'cand' => $cand, 'paths' => array_map(static fn($p) => is_array($p) ? array_intersect_key($p,
            array_flip(['k', 'p', 'n', 'ext', 'notes', 'enc', 'slow', 'canary', 'chit', 'hit', 'snap'])) : [], $paths)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        if (!$paths || !self::enabled()) {
            return;
        }
        // Wat normaal is: het hoogste aantal per uur, 168 uur
        $base = self::base($hid);
        $hour = date('YmdH');
        foreach ($paths as $p) {
            if (!is_array($p) || !preg_match('/^[0-9a-f]{12}$/', (string) ($p['k'] ?? ''))) {
                continue;
            }
            $k = (string) $p['k'];
            $n = max(0, (int) ($p['n'] ?? 0));
            $b = (array) ($base[$k] ?? ['hour' => $hour, 'cur' => 0, 'hist' => []]);
            if (($b['hour'] ?? '') !== $hour) {
                $b['hist'] = array_slice([...(array) ($b['hist'] ?? []), (int) ($b['cur'] ?? 0)], -168);
                $b['hour'] = $hour;
                $b['cur'] = 0;
            }
            $threshold = self::threshold($b);
            if (empty($p['hit'])) {   // een aanval telt niet mee als "normaal"
                $b['cur'] = max((int) ($b['cur'] ?? 0), $n);
            }
            $base[$k] = $b;
            $path = mb_substr((string) ($p['p'] ?? ''), 0, 300);
            $signals = array_filter([
                (string) ($p['chit'] ?? '') !== '' ? '🪤 ' . t('lokaasbestand: {w}', ['w' => rtrim((string) $p['chit'], '; ')]) : '',
                (int) ($p['ext'] ?? 0) > 0 ? t('{n} bestanden met een ransomware-extensie', ['n' => (int) $p['ext']]) : '',
                (int) ($p['notes'] ?? 0) > 0 ? t('{n} losgeldbriefjes', ['n' => (int) $p['notes']]) : '',
                (int) ($p['enc'] ?? 0) > 0 ? t('{n} tekstbestanden versleuteld', ['n' => (int) $p['enc']]) : '',
                t('{n} bestanden gewijzigd of hernoemd in één ronde', ['n' => $n]),
            ]);
            $ex = array_slice(array_map('strval', (array) ($p['ex'] ?? [])), 0, 5);
            $acts = array_filter([
                (string) ($p['snap'] ?? '') !== '' ? '📸 ' . t('snapshot gemaakt: {s}', ['s' => (string) $p['snap']]) : '',
                ($p['killed'] ?? []) ? '⛔ ' . t('gestopt: {w}', ['w' => implode(', ', array_map(static fn($x) => ($x['comm'] ?? '?') . ' ' . ($x['pid'] ?? '')
                    . (!empty($x['ip']) ? ' (' . $x['ip'] . ')' : ''), array_slice((array) $p['killed'], 0, 5)))]) : '',
            ]);
            $ip = '';
            foreach ((array) ($p['killed'] ?? []) as $x) {
                if (!empty($x['ip']) && filter_var($x['ip'], FILTER_VALIDATE_IP)) {
                    $ip = (string) $x['ip'];
                }
            }
            if (!empty($p['hit'])) {
                Ids::raise($host, 2, 'ransom', $k, t('Mogelijke ransomware in {p}', ['p' => $path]),
                    implode(' · ', [...$signals, ...$acts]) . ($ex ? ' · ' . t('bijvoorbeeld: {e}', ['e' => implode(', ', $ex)]) : '')
                    . ' · ' . t('Kijk meteen wie er in deze map schrijft; haal de pc of de server van het netwerk als je het niet vertrouwt.'), $ip !== '' ? $ip : null);
            } elseif ($n >= $threshold) {
                Ids::raise($host, 1, 'ransom_mass', $k, t('Heel veel bestanden tegelijk gewijzigd in {p}', ['p' => $path]),
                    t('{n} bestanden in één ronde (normaal hooguit {m}). Geen kenmerken van ransomware gezien; is dit een back-up, synchronisatie of terugzetten?', ['n' => $n, 'm' => $threshold]));
            }
        }
        $st->execute([$hid, 'ransom_base', json_encode($base, JSON_UNESCAPED_SLASHES), $now]);
    }

    /** Voor de Agent Manager: instellingen, voorgestelde mappen en de laatste stand per map */
    public static function info(int $hostId): array
    {
        $h = q('SELECT id, ransom_paths, ransom_snap, ransom_kill, ransom_canary, agent_version FROM hosts WHERE id = ?', [$hostId])->fetch() ?: [];
        $last = (array) json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'ransom'", [$hostId])->fetchColumn(), true);
        $base = self::base($hostId);
        $state = [];
        foreach ((array) ($last['paths'] ?? []) as $p) {
            if (is_array($p) && isset($p['k'])) {
                $state[(string) $p['k']] = $p;
            }
        }
        $paths = array_map(static fn($p) => ['p' => $p, 'k' => self::key($p), 'threshold' => self::threshold($base[self::key($p)] ?? null),
            'learned' => count((array) (($base[self::key($p)] ?? [])['hist'] ?? [])), 'last' => $state[self::key($p)] ?? null], self::paths($h));
        return ['on' => self::enabled(), 'paths' => $paths, 'snap' => (bool) ($h['ransom_snap'] ?? 1), 'kill' => (bool) ($h['ransom_kill'] ?? 0),
            'canary' => (bool) ($h['ransom_canary'] ?? 1), 'cand' => (array) ($last['cand'] ?? []), 'at' => $last['at'] ?? null,
            'agent_ok' => !empty($h['agent_version']) && version_compare((string) $h['agent_version'], '1.90.0', '>=')];
    }

    /** Overzicht voor Instellingen → IDS → Ransomware: per server de mappen en de laatste stand */
    public static function overview(): array
    {
        $out = [];
        foreach (q("SELECT id, name, ransom_paths, ransom_snap, ransom_kill, ransom_canary, agent_version FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll() as $h) {
            $last = (array) json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'ransom'", [$h['id']])->fetchColumn(), true);
            $alarm = count(array_filter((array) ($last['paths'] ?? []), static fn($p) => is_array($p) && (!empty($p['hit']) || ($p['canary'] ?? '') === 'alarm')));
            $out[] = ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'paths' => self::paths($h), 'snap' => (bool) $h['ransom_snap'], 'kill' => (bool) $h['ransom_kill'],
                'canary' => (bool) $h['ransom_canary'], 'cand' => count((array) ($last['cand'] ?? [])), 'at' => $last['at'] ?? null, 'alarm' => $alarm,
                'agent_ok' => !empty($h['agent_version']) && version_compare((string) $h['agent_version'], '1.90.0', '>=')];
        }
        return $out;
    }

    /** Instellen (Agent Manager). Mappen: absoluut, geen .., hooguit 20. */
    public static function save(int $hostId, array $in, string $by): array
    {
        $paths = [];
        foreach (array_slice((array) ($in['paths'] ?? []), 0, 20) as $p) {
            $p = rtrim(trim((string) $p), '/');
            if ($p === '' || $p[0] !== '/' || str_contains($p, '..') || preg_match('#^/(proc|sys|dev|run)(/|$)#', $p) || preg_match('/[\x00-\x1F]/', $p) || mb_strlen($p) > 300) {
                throw new InvalidArgumentException(t('Ongeldige map: {p}', ['p' => mb_substr($p, 0, 80)]));
            }
            $paths[$p] = true;
        }
        $before = q('SELECT ransom_paths, ransom_snap, ransom_kill, ransom_canary FROM hosts WHERE id = ?', [$hostId])->fetch() ?: [];
        $new = ['ransom_paths' => $paths ? json_encode(array_keys($paths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ransom_snap' => !empty($in['snap']) ? 1 : 0, 'ransom_kill' => !empty($in['kill']) ? 1 : 0, 'ransom_canary' => !empty($in['canary']) ? 1 : 0];
        q('UPDATE hosts SET ransom_paths = ?, ransom_snap = ?, ransom_kill = ?, ransom_canary = ? WHERE id = ?', [...array_values($new), $hostId]);
        $parts = [];
        if (($before['ransom_paths'] ?? null) !== $new['ransom_paths']) {
            $parts[] = $paths ? t('mappen: {p}', ['p' => implode(', ', array_keys($paths))]) : t('geen mappen meer (bewaking uit)');
        }
        foreach (['ransom_snap' => t('snapshot'), 'ransom_kill' => t('processen stoppen'), 'ransom_canary' => t('lokaasbestanden')] as $c => $label) {
            if ((int) ($before[$c] ?? -1) !== $new[$c]) {
                $parts[] = "$label " . ($new[$c] ? t('aan') : t('uit'));
            }
        }
        if ($parts) {
            ActionLog::by($by, $hostId, 'ransom', t('Ransomware-bewaking ingesteld'), implode(' · ', $parts));
            ChangeLog::record('host', $hostId, 'update', (string) q('SELECT name FROM hosts WHERE id = ?', [$hostId])->fetchColumn() . ' (🧬 ' . t('Ransomware') . ')', $before, $new);
        }
        return self::info($hostId);
    }
}
