<?php
declare(strict_types=1);

/**
 * Oorzaak erbij zoeken (1.108.0): bij de eerste melding van een storing kijkt RzMon wat er vlak ervoor (20 minuten) op
 * die server gebeurde, en zet dat in de melding: een agent- of RzMon-update, een herstart, bijgewerkte pakketten,
 * zelf herstellen, of een wijziging in RzMon (wijzigingslogboek). Bijvoorbeeld "🕵️ Kort ervoor: 3 min eerder: Agent
 * bijgewerkt van 1.61.1 naar 1.62.0". Aan/uit: Instellingen → Checks → Slimme voorstellen (context_on).
 */
final class Context
{
    private const WINDOW = 1200;   // 20 minuten

    public static function recent(array $check): string
    {
        if (setting('context_on', '1') !== '1') {
            return '';
        }
        $hid = (int) ($check['hid'] ?? $check['host_id'] ?? 0);
        $since = date('Y-m-d H:i:s', time() - self::WINDOW);
        $items = [];
        try {
            // Info-meldingen van deze server of zijn checks, en updates van RzMon zelf
            foreach (q("SELECT a.title, a.message, UNIX_TIMESTAMP(a.created_at) AS t FROM alerts a LEFT JOIN checks c ON c.id = a.check_id
                        WHERE a.kind = 'info' AND a.created_at >= ? AND (a.host_id = ? OR c.host_id = ? OR (a.host_id IS NULL AND a.check_id IS NULL AND a.title LIKE ?))
                        ORDER BY a.id DESC LIMIT 5", [$since, $hid, $hid, '%RzMon%']) as $a) {
                $items[] = [(int) $a['t'], self::short((string) $a['message'])];
            }
            // Wijzigingen in RzMon aan deze server of zijn checks (niet het leren van slimme drempels)
            foreach (q("SELECT l.label, UNIX_TIMESTAMP(l.at) AS t FROM change_log l LEFT JOIN checks c ON l.kind = 'check' AND c.id = l.ref_id
                        WHERE l.at >= ? AND l.action <> 'smart' AND ((l.kind = 'host' AND l.ref_id = ?) OR (l.kind = 'check' AND c.host_id = ?)) ORDER BY l.id DESC LIMIT 3",
                [$since, $hid, $hid]) as $l) {
                $items[] = [(int) $l['t'], t('gewijzigd in RzMon: {l}', ['l' => self::short((string) $l['label'])])];
            }
        } catch (Throwable) {
            return '';
        }
        if (!$items) {
            return '';
        }
        usort($items, static fn($a, $b) => $b[0] <=> $a[0]);
        $parts = [];
        foreach (array_slice($items, 0, 3) as [$t, $what]) {
            $parts[] = t('{m} min eerder: {w}', ['m' => max(0, (int) round((time() - $t) / 60)), 'w' => $what]);
        }
        return t('Kort ervoor') . ': ' . implode('; ', $parts);
    }

    /** Eerste regel, zonder de toevoeging "[niet verzonden: …]" */
    private static function short(string $s): string
    {
        $s = preg_replace('/\s*\[[^\]]*\]\s*$/u', '', strtok($s, "\n") ?: '');
        return mb_strlen($s) > 90 ? mb_substr($s, 0, 88) . '…' : $s;
    }
}
