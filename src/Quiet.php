<?php
declare(strict_types=1);

/**
 * 1.189.0: stille uren als profielen met een naam, te koppelen op vier niveaus. Het fijnste niveau wint:
 *   check → server → eigen groep → soort apparaat → algemeen.
 * Op elk niveau: niets (volgt het niveau erboven), een profiel, of "nooit stil".
 * Daarnaast "Nu stil" bij een server of check (tot een tijdstip): dat gaat voor alles.
 *
 * Een profiel: naam, icoon, vensters (dagen en tijden), "critical komt wel door" en eventueel een periode (datum van–tot:
 * alleen in die dagen actief; zonder vensters dan de hele dag).
 *
 * Opslag: quiet_profiles; setting quiet_global (id of leeg); quiet_groups (gkey 'grp:naam' of 'kind:groep' => 'never' of 'p:id');
 * hosts.quiet ('never', 'p:id' of NULL); checks.quiet (JSON {"mode":"profile","id":N} of {"mode":"never"}, of NULL);
 * hosts.quiet_until en checks.quiet_until (Nu stil).
 */
final class Quiet
{
    private static ?array $profiles = null;
    private static ?array $groups = null;
    /** @var array<int, array> */
    private static array $hosts = [];

    public static function reset(): void
    {
        self::$profiles = self::$groups = null;
        self::$hosts = [];
    }

    // ------------------------------------------------------------------ profielen

    /** Alle profielen: [id => [id, name, icon, windows, crit, date_from, date_to]] */
    public static function profiles(): array
    {
        if (self::$profiles === null) {
            self::migrate();
            self::$profiles = [];
            foreach (q('SELECT * FROM quiet_profiles ORDER BY name')->fetchAll() as $r) {
                self::$profiles[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'icon' => (string) $r['icon'],
                    'windows' => NotifyRules::cleanQuiet(['on' => true, 'windows' => json_decode((string) $r['windows'], true) ?: []])['windows'],
                    'crit' => (bool) $r['crit'], 'date_from' => $r['date_from'], 'date_to' => $r['date_to']];
            }
        }
        return self::$profiles;
    }

    public static function cleanProfile(array $in): array
    {
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) ($in['name'] ?? '')) ?? ''), 0, 60);
        if ($name === '') {
            throw new InvalidArgumentException(t('Geef het profiel een naam'));
        }
        $windows = NotifyRules::cleanQuiet(['on' => true, 'windows' => (array) ($in['windows'] ?? [])])['windows'];
        $d = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $from = $d($in['date_from'] ?? null);
        $to = $d($in['date_to'] ?? null);
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException(t('Vul bij een periode zowel de begindatum als de einddatum in'));
        }
        if ($from !== null && $to < $from) {
            throw new InvalidArgumentException(t('De einddatum ligt vóór de begindatum'));
        }
        if (!$windows && $from === null) {
            throw new InvalidArgumentException(t('Stille uren: vul minstens één venster in met een dag'));
        }
        $icon = mb_substr(trim((string) ($in['icon'] ?? '')), 0, 4) ?: '🌙';
        return ['name' => $name, 'icon' => $icon, 'windows' => $windows, 'crit' => !empty($in['crit']), 'date_from' => $from, 'date_to' => $to];
    }

    public static function saveProfile(?int $id, array $in): int
    {
        $p = self::cleanProfile($in);
        $args = [$p['name'], $p['icon'], json_encode($p['windows']), (int) $p['crit'], $p['date_from'], $p['date_to']];
        if ($id) {
            q('UPDATE quiet_profiles SET name = ?, icon = ?, windows = ?, crit = ?, date_from = ?, date_to = ? WHERE id = ?', [...$args, $id]);
        } else {
            q('INSERT INTO quiet_profiles (name, icon, windows, crit, date_from, date_to) VALUES (?, ?, ?, ?, ?, ?)', $args);
            $id = (int) db()->lastInsertId();
        }
        self::reset();
        return $id;
    }

    /** Profiel weg: wie het gebruikte, volgt weer het niveau erboven */
    public static function deleteProfile(int $id): void
    {
        q('DELETE FROM quiet_profiles WHERE id = ?', [$id]);
        q('DELETE FROM quiet_groups WHERE val = ?', ['p:' . $id]);
        q('UPDATE hosts SET quiet = NULL WHERE quiet = ?', ['p:' . $id]);
        q("UPDATE checks SET quiet = NULL WHERE quiet LIKE '%\"profile\"%' AND JSON_EXTRACT(quiet, '$.id') = ?", [$id]);
        if ((int) setting('quiet_global', '') === $id) {
            save_setting('quiet_global', '');
        }
        self::reset();
    }

    // ------------------------------------------------------------------ koppelen

    /** 'never', 'p:<id>' of null; alles wat geen bestaand profiel is, wordt null */
    public static function cleanVal(mixed $v): ?string
    {
        $v = (string) $v;
        if ($v === 'never') {
            return 'never';
        }
        return preg_match('/^p:(\d+)$/', $v, $m) && isset(self::profiles()[(int) $m[1]]) ? $v : null;
    }

    public static function groups(): array
    {
        if (self::$groups === null) {
            self::$groups = [];
            foreach (q('SELECT gkey, val FROM quiet_groups')->fetchAll() as $r) {
                self::$groups[(string) $r['gkey']] = (string) $r['val'];
            }
        }
        return self::$groups;
    }

    public static function setGroup(string $key, mixed $val): void
    {
        if (!preg_match('/^(grp:.{1,60}|kind:[a-z]{2,20})$/u', $key)) {
            throw new InvalidArgumentException(t('Onbekende groep'));
        }
        $v = self::cleanVal($val);
        if ($v === null) {
            q('DELETE FROM quiet_groups WHERE gkey = ?', [$key]);
        } else {
            q('INSERT INTO quiet_groups (gkey, val) VALUES (?, ?) ON DUPLICATE KEY UPDATE val = VALUES(val)', [$key, $v]);
        }
        self::reset();
    }

    /** Nu stil: tot dit tijdstip (null = opheffen) */
    public static function setNow(string $what, int $id, ?int $until): void
    {
        $tbl = $what === 'host' ? 'hosts' : 'checks';
        q("UPDATE $tbl SET quiet_until = ? WHERE id = ?", [$until && $until > time() ? date('Y-m-d H:i:s', min($until, time() + 31 * 86400)) : null, $id]);
        self::reset();
    }

    // ------------------------------------------------------------------ wat geldt er?

    private static function host(int $id): ?array
    {
        if (!array_key_exists($id, self::$hosts)) {
            self::$hosts[$id] = q('SELECT id, name, mode, kind, kind_auto, grp, quiet, quiet_until FROM hosts WHERE id = ?', [$id])->fetch() ?: null;
        }
        return self::$hosts[$id];
    }

    private static function fromVal(?string $v, string $src): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v === 'never') {
            return ['on' => false, 'windows' => [], 'crit' => false, 'name' => t('nooit stil'), 'src' => $src, 'profile' => null, 'never' => true];
        }
        if (preg_match('/^p:(\d+)$/', $v, $m) && ($p = self::profiles()[(int) $m[1]] ?? null)) {
            return ['on' => true, 'windows' => $p['windows'], 'crit' => $p['crit'], 'name' => $p['name'], 'icon' => $p['icon'], 'src' => $src, 'profile' => $p['id'],
                'date_from' => $p['date_from'], 'date_to' => $p['date_to']];
        }
        return null;
    }

    /** De algemene stille uren (★) */
    public static function global(): array
    {
        $g = (int) setting('quiet_global', '');
        self::profiles();
        return self::fromVal($g ? 'p:' . $g : null, 'global') ?? ['on' => false, 'windows' => [], 'crit' => false, 'name' => t('geen'), 'src' => 'global', 'profile' => null];
    }

    /** Wat geldt er voor deze server (zonder check)? */
    public static function forHost(array $h): array
    {
        if (!empty($h['quiet_until']) && strtotime((string) $h['quiet_until']) > time()) {
            return ['on' => true, 'windows' => [], 'crit' => false, 'name' => t('Nu stil'), 'src' => 'host_now', 'profile' => null, 'until' => strtotime((string) $h['quiet_until'])];
        }
        $g = self::groups();
        return self::fromVal($h['quiet'] ?? null, 'host')
            ?? (trim((string) ($h['grp'] ?? '')) !== '' ? self::fromVal($g['grp:' . trim((string) $h['grp'])] ?? null, 'grp') : null)
            ?? self::fromVal($g['kind:' . HostKind::group($h)] ?? null, 'kind')
            ?? self::global();
    }

    /** Wat geldt er voor deze check? */
    public static function forCheck(array $check): array
    {
        if (!empty($check['quiet_until']) && strtotime((string) $check['quiet_until']) > time()) {
            return ['on' => true, 'windows' => [], 'crit' => false, 'name' => t('Nu stil'), 'src' => 'check_now', 'profile' => null, 'until' => strtotime((string) $check['quiet_until'])];
        }
        $own = json_decode((string) ($check['quiet'] ?? ''), true);
        if (is_array($own)) {
            $mode = $own['mode'] ?? '';
            if ($mode === 'never') {
                return self::fromVal('never', 'check');
            }
            if ($mode === 'profile' && ($r = self::fromVal('p:' . (int) ($own['id'] ?? 0), 'check'))) {
                return $r;
            }
            if ($mode === 'own') {   // van vóór 1.189.0, tot de migratie
                return ['crit' => false, 'name' => t('eigen vensters'), 'src' => 'check', 'profile' => null] + NotifyRules::cleanQuiet(['on' => true] + $own);
            }
        }
        $hid = (int) ($check['hid'] ?? $check['host_id'] ?? 0);
        $h = $hid ? self::host($hid) : null;
        return $h ? self::forHost($h) : self::global();
    }

    /** Valt dit moment in de stilte? Het einde, of null. Houdt rekening met Nu stil en een periode. */
    public static function until(array $q, ?int $now = null): ?int
    {
        $now ??= time();
        if (isset($q['until'])) {
            return $now < (int) $q['until'] ? (int) $q['until'] : null;
        }
        if (empty($q['on'])) {
            return null;
        }
        if (!empty($q['date_from'])) {
            $day = date('Y-m-d', $now);
            if ($day < $q['date_from'] || $day > $q['date_to']) {
                return null;
            }
            if (!$q['windows']) {
                return strtotime($q['date_to'] . ' +1 day');   // de hele periode
            }
        }
        return NotifyRules::quietUntil(['on' => true, 'windows' => $q['windows']], $now);
    }

    /** Wordt een melding met deze status nu tegengehouden? (critical komt door als het profiel dat zegt) */
    public static function holds(array $check, int $status): bool
    {
        $q = self::forCheck($check);
        return self::until($q) !== null && !($status === STATUS_CRIT && !empty($q['crit']));
    }

    /** Korte samenvatting voor de interface */
    public static function summary(array $q): array
    {
        $u = self::until($q);
        return ['name' => $q['name'] ?? '', 'icon' => $q['icon'] ?? '', 'src' => $q['src'] ?? '', 'profile' => $q['profile'] ?? null, 'never' => !empty($q['never']),
            'crit' => !empty($q['crit']), 'now' => $u ? date('Y-m-d H:i:s', $u) : null, 'on' => !empty($q['on'])];
    }

    // ------------------------------------------------------------------ overzicht

    /** Voor Instellingen: profielen met waar ze gebruikt worden, de groepen en de uitzonderingen */
    public static function overview(): array
    {
        $profiles = self::profiles();
        $use = [];
        $add = static function (?string $val, string $type, string $label, ?int $id = null) use (&$use) {
            $k = $val === 'never' ? 'never' : (preg_match('/^p:(\d+)$/', (string) $val, $m) ? (int) $m[1] : null);
            if ($k !== null) {
                $use[$k][] = ['type' => $type, 'label' => $label, 'id' => $id];
            }
        };
        foreach (self::groups() as $k => $v) {
            $add($v, str_starts_with($k, 'grp:') ? 'grp' : 'kind', substr($k, strpos($k, ':') + 1));
        }
        $hosts = q('SELECT id, name, mode, kind, kind_auto, grp, quiet, quiet_until FROM hosts ORDER BY name')->fetchAll();
        foreach ($hosts as $h) {
            $add($h['quiet'], 'host', (string) $h['name'], (int) $h['id']);
        }
        foreach (q("SELECT c.id, c.name, c.quiet, h.name host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.quiet IS NOT NULL ORDER BY h.name, c.name")->fetchAll() as $c) {
            $o = json_decode((string) $c['quiet'], true) ?: [];
            $add(($o['mode'] ?? '') === 'never' ? 'never' : (($o['mode'] ?? '') === 'profile' ? 'p:' . (int) ($o['id'] ?? 0) : null), 'check', $c['host'] . ' – ' . $c['name'], (int) $c['id']);
        }
        $grps = [];
        foreach ($hosts as $h) {
            if (trim((string) $h['grp']) !== '') {
                $grps[trim((string) $h['grp'])] = ($grps[trim((string) $h['grp'])] ?? 0) + 1;
            }
        }
        ksort($grps);
        $kinds = [];
        foreach ($hosts as $h) {
            $g = HostKind::group($h);
            $kinds[$g] = ($kinds[$g] ?? 0) + 1;
        }
        $g = self::groups();
        $now = [];
        foreach ($hosts as $h) {
            if ($h['quiet_until'] && strtotime((string) $h['quiet_until']) > time()) {
                $now[] = ['type' => 'host', 'id' => (int) $h['id'], 'label' => $h['name'], 'until' => $h['quiet_until']];
            }
        }
        foreach (q('SELECT c.id, c.name, c.quiet_until, h.name host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.quiet_until > NOW()')->fetchAll() as $c) {
            $now[] = ['type' => 'check', 'id' => (int) $c['id'], 'label' => $c['host'] . ' – ' . $c['name'], 'until' => $c['quiet_until']];
        }
        return [
            'profiles' => array_values(array_map(fn($p) => $p + ['used' => $use[$p['id']] ?? [], 'active' => self::until(['on' => true] + $p) !== null], $profiles)),
            'never' => $use['never'] ?? [],
            'global' => (int) setting('quiet_global', '') ?: null,
            'groups' => array_merge(
                array_map(fn($n, $c) => ['key' => 'grp:' . $n, 'type' => 'grp', 'label' => (string) $n, 'hosts' => $c, 'val' => $g['grp:' . $n] ?? null], array_keys($grps), $grps),
                array_map(fn($k) => ['key' => 'kind:' . $k, 'type' => 'kind', 'label' => $k, 'hosts' => $kinds[$k] ?? 0, 'val' => $g['kind:' . $k] ?? null], HostKind::GROUPS)),
            'now' => $now,
        ];
    }

    // ------------------------------------------------------------------ van vóór 1.189.0

    /**
     * Eén keer: de algemene stille uren worden het profiel "Standaard" (★ als ze aan stonden), en elke check met eigen vensters
     * krijgt een eigen profiel ("Eigen: server – check"). Nooit stil blijft nooit stil. Er verandert dus niets.
     */
    public static function migrate(): void
    {
        if (setting('quiet_migrated', '') === '1') {
            return;
        }
        try {
            q('SELECT 1 FROM quiet_profiles LIMIT 1');
        } catch (Throwable) {
            return;   // tabel nog niet aangemaakt
        }
        save_setting('quiet_migrated', '1');
        $g = NotifyRules::cleanQuiet((array) json_decode((string) setting('notify_quiet', ''), true));
        $raw = (array) json_decode((string) setting('notify_quiet', ''), true);
        $wins = NotifyRules::cleanQuiet(['on' => true, 'windows' => (array) ($raw['windows'] ?? [])])['windows'];
        if ($wins) {
            q('INSERT INTO quiet_profiles (name, icon, windows, crit, date_from, date_to) VALUES (?, ?, ?, 0, NULL, NULL)', [t('Standaard'), '🌙', json_encode($wins)]);
            if ($g['on']) {
                save_setting('quiet_global', (string) db()->lastInsertId());
            }
        }
        foreach (q("SELECT c.id, c.name, c.quiet, h.name host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.quiet LIKE '%\"own\"%'")->fetchAll() as $c) {
            $o = json_decode((string) $c['quiet'], true) ?: [];
            $w = NotifyRules::cleanQuiet(['on' => true, 'windows' => (array) ($o['windows'] ?? [])])['windows'];
            if (!$w) {
                q('UPDATE checks SET quiet = NULL WHERE id = ?', [$c['id']]);
                continue;
            }
            q('INSERT INTO quiet_profiles (name, icon, windows, crit, date_from, date_to) VALUES (?, ?, ?, 0, NULL, NULL)',
                [mb_substr(t('Eigen: {name}', ['name' => $c['host'] . ' – ' . $c['name']]), 0, 60), '🌙', json_encode($w)]);
            q('UPDATE checks SET quiet = ? WHERE id = ?', [json_encode(['mode' => 'profile', 'id' => (int) db()->lastInsertId()]), $c['id']]);
        }
    }
}
