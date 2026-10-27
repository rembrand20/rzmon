<?php
declare(strict_types=1);

/**
 * Statuspagina (1.107.0): een eenvoudige pagina voor huisgenoten of klanten, via een geheime link (ook van buiten).
 * Alleen de onderdelen die je zelf kiest ("Internet", "NAS", "Plex"…), elk met een of meer checks erachter, met een
 * balk per dag over de laatste 90 dagen (uit de dagtotalen van Analyse) en een eigen mededeling. Geen IP-adressen,
 * servernamen of foutteksten. Instellingen → Koppelingen → Statuspagina.
 */
final class StatusPage
{
    public const DAYS = 90;

    public static function on(): bool
    {
        return setting('status_on', '0') === '1' && preg_match('/^[a-f0-9]{32}$/', (string) setting('status_token', '')) === 1;
    }

    public static function token(): string
    {
        $t = (string) setting('status_token', '');
        if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
            $t = bin2hex(random_bytes(16));
            save_setting('status_token', $t);
        }
        return $t;
    }

    public static function newToken(): string
    {
        $t = bin2hex(random_bytes(16));
        save_setting('status_token', $t);
        return $t;
    }

    /** Onderdelen: [['label' => 'Internet', 'checks' => [1, 2]], …] */
    public static function items(): array
    {
        $out = [];
        foreach ((array) json_decode((string) setting('status_items', '[]'), true) as $it) {
            $label = trim(mb_substr((string) ($it['label'] ?? ''), 0, 60));
            $checks = array_values(array_unique(array_filter(array_map('intval', (array) ($it['checks'] ?? [])))));
            if ($label !== '' && $checks) {
                $out[] = ['label' => $label, 'checks' => array_slice($checks, 0, 50)];
            }
        }
        return array_slice($out, 0, 30);
    }

    public static function save(array $in): void
    {
        $items = [];
        foreach (array_slice((array) ($in['items'] ?? []), 0, 30) as $it) {
            $label = trim(mb_substr((string) ($it['label'] ?? ''), 0, 60));
            $checks = array_values(array_unique(array_filter(array_map('intval', (array) ($it['checks'] ?? [])))));
            if ($label !== '') {
                $items[] = ['label' => $label, 'checks' => $checks];
            }
        }
        save_setting('status_items', json_encode($items, JSON_UNESCAPED_UNICODE));
        save_setting('status_on', !empty($in['on']) ? '1' : '0');
        save_setting('status_title', trim(mb_substr((string) ($in['title'] ?? ''), 0, 80)));
        save_setting('status_note', trim(mb_substr((string) ($in['note'] ?? ''), 0, 500)));
        self::token();
    }

    /** Status van een onderdeel nu: ok, warn, crit, maint (alles in onderhoud) of unknown */
    private static function now(array $checkIds): string
    {
        $rows = $checkIds ? q('SELECT c.status, c.enabled, c.info_only, c.maintenance_until, h.maintenance_until AS hm, h.enabled AS he
                    FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id IN (' . implode(',', array_map('intval', $checkIds)) . ')')->fetchAll() : [];
        $rank = ['ok' => 0, 'unknown' => 1, 'warn' => 2, 'crit' => 3];
        $worst = null;
        $maint = 0;
        foreach ($rows as $r) {
            if (!(int) $r['enabled'] || !(int) $r['he'] || (int) $r['info_only']) {
                continue;
            }
            if (($r['maintenance_until'] && strtotime((string) $r['maintenance_until']) > time()) || ($r['hm'] && strtotime((string) $r['hm']) > time())
                || Monitor::globalMaintenance()) {
                $maint++;
                continue;
            }
            $s = [STATUS_OK => 'ok', STATUS_WARN => 'warn', STATUS_CRIT => 'crit'][(int) $r['status']] ?? 'unknown';
            $worst = $worst === null || $rank[$s] > $rank[$worst] ? $s : $worst;
        }
        return $worst ?? ($maint ? 'maint' : 'unknown');
    }

    /** Per dag het deel dat niet critical was (uit stats_daily), of null zonder gegevens */
    private static function history(array $checkIds): array
    {
        $from = date('Y-m-d', strtotime('-' . (self::DAYS - 1) . ' days'));
        $days = [];
        if ($checkIds) {
            $ids = implode(',', array_map('intval', $checkIds));
            foreach (q("SELECT day, SUM(CASE WHEN metric = 'c_n' THEN v ELSE 0 END) AS n, SUM(CASE WHEN metric = 'c_crit' THEN v ELSE 0 END) AS crit
                        FROM stats_daily WHERE metric IN ('c_n', 'c_crit') AND ref IN ($ids) AND day >= ? GROUP BY day", [$from]) as $r) {
                if ((float) $r['n'] > 0) {
                    $days[$r['day']] = max(0.0, 1 - (float) $r['crit'] / (float) $r['n']);
                }
            }
        }
        $out = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[$d] = $days[$d] ?? null;
        }
        return $out;
    }

    /** Alles voor de pagina */
    public static function data(): array
    {
        $comps = [];
        foreach (self::items() as $it) {
            $hist = self::history($it['checks']);
            $vals = array_filter($hist, static fn($v) => $v !== null);
            $comps[] = ['label' => $it['label'], 'now' => self::now($it['checks']), 'days' => $hist,
                'pct' => $vals ? round(array_sum($vals) / count($vals) * 100, 2) : null];
        }
        $rank = ['ok' => 0, 'maint' => 0, 'unknown' => 1, 'warn' => 2, 'crit' => 3];
        $worst = 'ok';
        foreach ($comps as $c) {
            $worst = $rank[$c['now']] > $rank[$worst] ? $c['now'] : $worst;
        }
        return ['title' => (string) setting('status_title', '') ?: t('Status'), 'note' => (string) setting('status_note', ''), 'overall' => $worst,
            'components' => $comps, 'updated' => now()];
    }
}
