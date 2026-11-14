<?php

declare(strict_types=1);

/**
 * 1.258.0: veiligheidsscore per site achter de reverse proxy (0–100, A–D), met per onderdeel wat er ontbreekt en hoe je het
 * verbetert. Onderdelen: wie mag erbij (beheerprogramma's en adminpaden alleen vanaf je netwerk), rate limit, bots weren,
 * de WAF (en zijn stand), bekende lekken (misbruik dat de WAF zag en dat niet tegengehouden of dichtgezet is) en uploads
 * scannen (ClamAV). Een site die alleen vanaf je netwerk bereikbaar is, telt voor lekken en uploads als veilig.
 */
final class SiteScore
{
    /** De score bij alle sites van een server zetten */
    public static function addAll(int $hostId, array $sites, array $wafStats, array $avDirs, array $vpActive): array
    {
        $wafOn = setting('waf_on', '0') === '1';
        $mode = Waf::mode();
        $avOn = setting('av_on', '0') === '1';
        $avSites = [];
        foreach ($avDirs as $d) {
            if (($d['ok'] ?? null) !== false && ($d['site'] ?? '') !== '') {
                $avSites[$d['site']] = true;
            }
        }
        // Misbruik van bekende lekken dat niet tegengehouden werd (laatste 14 dagen), per site de paden
        $open = [];
        foreach (q('SELECT site, uri FROM waf_events WHERE host_id = ? AND vpatch = 1 AND blocked = 0 AND at >= NOW() - INTERVAL 14 DAY GROUP BY site, uri LIMIT 500', [$hostId]) as $r) {
            $p = rtrim((string) preg_replace('/[?#].*$/', '', (string) $r['uri']), '/');
            if (!VPatch::covered($vpActive, (string) $r['site'], $p)) {
                $open[$r['site']][$p] = true;
            }
        }
        foreach ($sites as &$s) {
            $s['score'] = self::score($s, ['waf_on' => $wafOn, 'mode' => $mode, 'av_on' => $avOn, 'av_site' => !empty($avSites[$s['site']]),
                'open' => count($open[$s['site']] ?? []), 'seen' => (int) ($wafStats[$s['site']]['seen'] ?? 0)]);
        }
        unset($s);
        return $sites;
    }

    /** @return array{n: int, grade: string, parts: list<array{k: string, got: int, max: int, tip: string}>} */
    public static function score(array $s, array $c): array
    {
        $parts = [];
        $add = static function (string $k, int $got, int $max, string $tip) use (&$parts): void {
            $parts[] = ['k' => $k, 'got' => $got, 'max' => $max, 'tip' => $got < $max ? $tip : ''];
        };
        if (empty($s['enabled'])) {
            return ['n' => 0, 'grade' => '–', 'parts' => [['k' => 'off', 'got' => 0, 'max' => 100, 'tip' => t('Bescherming voor deze site staat uit')]]];
        }
        $kind = ProxyGuard::appKind((string) $s['site'], (string) ($s['up'] ?? ''));
        $lanOnly = $s['lan_mode'] === 'site';
        // Wie mag erbij (25)
        $acc = match (true) {
            $lanOnly => 25,
            $kind === 'admin' => 0,
            $kind === 'paths' => $s['lan_mode'] === 'paths' ? 25 : 5,
            default => $s['lan_mode'] === 'paths' ? 25 : 18,
        };
        $add('access', $acc, 25, $kind === 'admin' ? t('Beheerprogramma: zet de hele site alleen open voor je netwerk') : t('Zet de adminpaden alleen open voor je netwerk'));
        $add('rate', $s['rate'] !== 'off' ? 10 : 0, 10, t('Zet een rate limit aan (normaal)'));
        $add('bots', !empty($s['bots']) ? 10 : 0, 10, t('Zet "bots weren" aan'));
        // WAF (25)
        $siteWaf = (string) ($s['waf'] ?? 'inherit');
        $waf = !$c['waf_on'] || $siteWaf === 'off' ? 0 : ($siteWaf === 'detect' || $c['mode'] === 'detect' ? 12 : ($c['mode'] === 'strict' ? 25 : 22));
        if ($lanOnly) {
            $waf = max($waf, 20);
        }
        $add('waf', $waf, 25, !$c['waf_on'] ? t('Zet de WAF aan') : ($siteWaf === 'off' ? t('De WAF staat uit voor deze site') : t('Zet de WAF-slider op blokkeren (alleen zeker)')));
        // Bekende lekken (15)
        $add('vuln', $lanOnly || $c['open'] === 0 ? 15 : 0, 15, t('{n} pad(en) met misbruik van een bekend lek staan nog open: zet ze dicht (virtual patching)', ['n' => $c['open']]));
        // Uploads scannen (15)
        $add('upload', $lanOnly || ($c['av_on'] && $c['av_site']) ? 15 : ($c['av_on'] ? 5 : 0), 15,
            $c['av_on'] ? t('Kies de uploadmap van deze site bij ClamAV') : t('Zet ClamAV aan en kies de uploadmap van deze site'));
        $n = array_sum(array_column($parts, 'got'));
        return ['n' => $n, 'grade' => $n >= 85 ? 'A' : ($n >= 70 ? 'B' : ($n >= 50 ? 'C' : 'D')), 'parts' => $parts];
    }
}
