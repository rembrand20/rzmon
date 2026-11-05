<?php

declare(strict_types=1);

/**
 * 1.179.0: rugbaarheid van een CVE (0–100): hoeveel aandacht krijgt hij, en wordt hij al misbruikt? Alleen voor CVE's
 * die echt op je servers staan. Twee kanten:
 * - exploits (tot 60): actief misbruikt (CISA KEV), het oordeel van CISA (Vulnrichment/SSVC: misbruik none/poc/active),
 *   een exploit op Exploit-DB, een Nuclei-template (scanners zoeken er al naar) en openbare PoC's op GitHub;
 * - praat (tot 40): Hacker News (laatste 7 dagen en totaal) en Mastodon (de hashtag, per dag, op een instantie naar keuze).
 * Daarbovenop tot 10 punten voor de kans op misbruik (EPSS). Bronnen zonder sleutel werken altijd; met een GitHub-token
 * (Instellingen → Kwetsbaarheden) mag RzMon vaker zoeken, met een Mastodon-token telt hij ook berichten zonder hashtag.
 * Per CVE één keer per dag (actief misbruikt of high/critical: twee keer); Exploit-DB en Nuclei één keer per dag als lijst.
 * Melding (ℹ️ "veel aandacht") als een CVE de grens passeert of snel stijgt, per server, hooguit eens per 14 dagen.
 */
final class CveBuzz
{
    private const GITHUB = 'https://api.github.com/search/repositories';
    private const HN = 'https://hn.algolia.com/api/v1/search';
    private const VULNRICH = 'https://raw.githubusercontent.com/cisagov/vulnrichment/develop';
    private const NUCLEI = 'https://raw.githubusercontent.com/projectdiscovery/nuclei-templates/main/cves.json';
    private const EXPLOITDB = 'https://gitlab.com/exploit-database/exploitdb/-/raw/main/files_exploits.csv';
    /** Een CVE-nummer (alleen deze gaan naar buiten) */
    private const CVE_RE = '/^CVE-(\d{4})-(\d{4,7})$/';

    public static function on(): bool
    {
        return setting('cve_buzz_on', '1') === '1';
    }

    /** Grens voor "veel aandacht" (melding en het filter 🔥) */
    public static function threshold(): int
    {
        return max(20, min(95, (int) setting('cve_buzz_alert', '60')));
    }

    /** Elke minuut vanuit Vulns::cron, zolang er tijd over is */
    public static function cron(int $end): void
    {
        if (!self::on() || time() + 5 >= $end) {
            return;
        }
        q("INSERT IGNORE INTO vuln_buzz (cve) SELECT DISTINCT cve FROM vuln_findings WHERE cve LIKE 'CVE-%'");
        if (setting('cve_buzz_lists', '') !== date('Y-m-d') && time() + 30 < $end) {
            save_setting('cve_buzz_lists', date('Y-m-d'));
            self::refreshLists();
        }
        $max = setting('cve_github_token', '') !== '' ? 20 : 6;   // GitHub zonder token: 10 zoekvragen per minuut
        $due = q("SELECT b.cve FROM vuln_buzz b LEFT JOIN vuln_cve c ON c.cve = b.cve
                  WHERE b.cve IN (SELECT cve FROM vuln_findings)
                    AND (b.checked_at IS NULL OR b.checked_at < NOW() - INTERVAL IF(c.kev = 1 OR c.severity >= 3, 12, 24) HOUR)
                  ORDER BY b.checked_at IS NOT NULL, c.kev DESC, c.severity DESC, b.checked_at LIMIT $max")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($due as $cve) {
            if (time() + 6 >= $end) {
                break;
            }
            try {
                self::update((string) $cve);
            } catch (Throwable $e) {
                q('UPDATE vuln_buzz SET checked_at = ? WHERE cve = ?', [now(), $cve]);   // morgen opnieuw
            }
        }
        self::notify();
    }

    /** Eén CVE bijwerken: alle bronnen, de score en het verloop */
    public static function update(string $cve, ?array $fetched = null): array
    {
        if (!preg_match(self::CVE_RE, $cve, $m)) {
            throw new InvalidArgumentException('geen CVE');
        }
        $row = q('SELECT * FROM vuln_buzz WHERE cve = ?', [$cve])->fetch() ?: [];
        $old = json_decode((string) ($row['data'] ?? ''), true) ?: [];
        $d = $fetched ?? self::fetch($cve, $m[1], $m[2], $old);
        $c = q('SELECT kev, epss FROM vuln_cve WHERE cve = ?', [$cve])->fetch() ?: [];
        $d['kev'] = !empty($c['kev']);
        [$score, $exploit, $talk] = self::score($d, isset($c['epss']) ? (float) $c['epss'] : null);
        $hist = json_decode((string) ($row['history'] ?? ''), true) ?: [];
        $today = date('Y-m-d');
        $hist = array_values(array_filter($hist, static fn($h) => is_array($h) && ($h[0] ?? '') !== $today));
        $hist[] = [$today, $score];
        $hist = array_slice($hist, -30);
        q('INSERT INTO vuln_buzz (cve, score, exploit, talk, data, history, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE score = VALUES(score), exploit = VALUES(exploit), talk = VALUES(talk), data = VALUES(data), history = VALUES(history), checked_at = VALUES(checked_at)',
            [$cve, $score, $exploit, $talk, json_encode($d, JSON_UNESCAPED_SLASHES), json_encode($hist), now()]);
        return ['score' => $score, 'exploit' => $exploit, 'talk' => $talk, 'data' => $d];
    }

    /**
     * De score uit de gegevens. @return array{0: int, 1: int, 2: int} totaal, exploits, praat
     */
    public static function score(array $d, ?float $epss): array
    {
        $log = static fn(float $n, float $k, int $cap): int => (int) min($cap, round($k * log(1 + max(0.0, $n), 2)));
        $ssvc = (string) ($d['ssvc']['e'] ?? '');
        $exploit = min(60, (!empty($d['kev']) ? 25 : 0) + ($ssvc === 'active' ? 20 : ($ssvc === 'poc' ? 10 : 0))
            + (!empty($d['edb']) ? 10 : 0) + (!empty($d['nuclei']) ? 8 : 0) + $log((float) ($d['gh'] ?? 0), 5, 15));
        $talk = min(40, $log((float) ($d['hn7'] ?? 0), 6, 20) + $log((float) ($d['hn'] ?? 0), 2, 8) + $log((float) ($d['md7'] ?? 0), 6, 20));
        $e = $epss !== null ? (int) round(10 * sqrt(max(0.0, min(1.0, $epss)))) : 0;
        return [min(100, $exploit + $talk + $e), $exploit, $talk];
    }

    /** Alle bronnen voor één CVE (wat niet lukt, blijft zoals het was) */
    private static function fetch(string $cve, string $year, string $num, array $old): array
    {
        $d = ['edb' => !empty($old['edb']), 'nuclei' => !empty($old['nuclei'])];
        $tok = (string) setting('cve_github_token', '');
        $week = time() - 7 * 86400;
        $mUrl = rtrim((string) setting('cve_mastodon_url', 'https://infosec.exchange'), '/');
        $mTok = (string) setting('cve_mastodon_token', '');
        $tags = ['cve' . $year . $num, 'cve_' . $year . '_' . $num];
        $urls = [
            'gh' => [self::GITHUB . '?per_page=1&q=' . rawurlencode('"' . $cve . '"'), $tok !== '' ? ['Authorization: Bearer ' . $tok] : []],
            'hn' => [self::HN . '?hitsPerPage=0&query=' . rawurlencode('"' . $cve . '"'), []],
            'hn7' => [self::HN . '?hitsPerPage=0&query=' . rawurlencode('"' . $cve . '"') . '&numericFilters=' . rawurlencode('created_at_i>' . $week), []],
            'ssvc' => [self::VULNRICH . "/$year/" . self::bucket($num) . "/$cve.json", []],
        ];
        if (self::safeUrl($mUrl)) {
            foreach ($tags as $i => $tag) {
                $urls['tag' . $i] = [$mUrl . '/api/v1/tags/' . $tag, []];
            }
            if ($mTok !== '') {
                $urls['mds'] = [$mUrl . '/api/v2/search?type=statuses&limit=40&q=' . rawurlencode($cve), ['Authorization: Bearer ' . $mTok]];
            }
        }
        $r = self::multi($urls);
        $d['gh'] = isset($r['gh']['total_count']) ? (int) $r['gh']['total_count'] : (int) ($old['gh'] ?? 0);
        $d['hn'] = isset($r['hn']['nbHits']) ? (int) $r['hn']['nbHits'] : (int) ($old['hn'] ?? 0);
        $d['hn7'] = isset($r['hn7']['nbHits']) ? (int) $r['hn7']['nbHits'] : (int) ($old['hn7'] ?? 0);
        $d['ssvc'] = is_array($r['ssvc'] ?? null) ? self::ssvc($r['ssvc']) : ($old['ssvc'] ?? null);
        $md7 = null;
        foreach ([0, 1] as $i) {
            foreach ((array) ($r['tag' . $i]['history'] ?? []) as $h) {
                if ((int) ($h['day'] ?? 0) >= $week) {
                    $md7 = ($md7 ?? 0) + (int) ($h['uses'] ?? 0);
                }
            }
        }
        if (isset($r['mds']['statuses'])) {
            $n = count(array_filter((array) $r['mds']['statuses'], static fn($s) => strtotime((string) ($s['created_at'] ?? '')) >= $week));
            $md7 = max($md7 ?? 0, $n);
        }
        $d['md7'] = $md7 ?? (int) ($old['md7'] ?? 0);
        return $d;
    }

    /** Map in cisagov/vulnrichment: 3094 → 3xxx, 12345 → 12xxx, 123 → 0xxx */
    public static function bucket(string $num): string
    {
        $n = (int) $num;
        return intdiv($n, 1000) . 'xxx';
    }

    /** Het oordeel van CISA (SSVC) uit een Vulnrichment-bestand: misbruik (none/poc/active), automatiseerbaar, impact */
    public static function ssvc(array $j): ?array
    {
        foreach ((array) ($j['containers']['adp'] ?? []) as $adp) {
            foreach ((array) ($adp['metrics'] ?? []) as $m) {
                if (($m['other']['type'] ?? '') !== 'ssvc') {
                    continue;
                }
                $o = [];
                foreach ((array) ($m['other']['content']['options'] ?? []) as $opt) {
                    foreach ((array) $opt as $k => $v) {
                        $o[strtolower((string) $k)] = strtolower((string) $v);
                    }
                }
                return ['e' => $o['exploitation'] ?? '', 'a' => $o['automatable'] ?? '', 't' => $o['technical impact'] ?? ''];
            }
        }
        return null;
    }

    /** Eén keer per dag: welke van onze CVE's hebben een exploit op Exploit-DB en een Nuclei-template */
    private static function refreshLists(): void
    {
        $ours = array_flip(q("SELECT cve FROM vuln_buzz")->fetchAll(PDO::FETCH_COLUMN));
        if (!$ours) {
            return;
        }
        $sets = [];
        if (setting('cve_nuclei', '1') === '1') {
            $raw = self::get(self::NUCLEI, [], 60);
            if ($raw !== null && preg_match_all('/"ID"\s*:\s*"(CVE-\d{4}-\d{4,7})"/i', $raw, $m)) {
                $sets['nuclei'] = array_flip(array_map('strtoupper', $m[1]));
            }
        }
        if (setting('cve_exploitdb', '1') === '1') {
            $raw = self::get(self::EXPLOITDB, [], 60);
            if ($raw !== null && preg_match_all('/CVE-\d{4}-\d{4,7}/', $raw, $m)) {
                $sets['edb'] = array_flip($m[0]);
            }
        }
        foreach (array_keys($ours) as $cve) {
            $row = q('SELECT data FROM vuln_buzz WHERE cve = ?', [$cve])->fetchColumn();
            $d = json_decode((string) $row, true) ?: [];
            foreach ($sets as $k => $set) {
                $d[$k] = isset($set[$cve]);
            }
            q('UPDATE vuln_buzz SET data = ? WHERE cve = ?', [json_encode($d, JSON_UNESCAPED_SLASHES), $cve]);
        }
    }

    /** Stijging in 7 dagen (uit het verloop) */
    public static function trend(array $hist, int $score): int
    {
        $from = date('Y-m-d', time() - 7 * 86400);
        $base = null;
        foreach ($hist as $h) {
            if (is_array($h) && ($h[0] ?? '') <= $from) {
                $base = (int) $h[1];
            }
        }
        $base ??= isset($hist[0][1]) ? (int) $hist[0][1] : $score;
        return $score - $base;
    }

    /** ℹ️ Melding: een CVE op je servers krijgt veel aandacht (grens gepasseerd of snel gestegen) */
    private static function notify(): void
    {
        $lim = self::threshold();
        foreach (q('SELECT * FROM vuln_buzz WHERE score >= ? AND (alerted_at IS NULL OR alerted_at < NOW() - INTERVAL 14 DAY)', [$lim])->fetchAll() as $b) {
            $hist = json_decode((string) $b['history'], true) ?: [];
            $prev = count($hist) > 1 ? (int) $hist[count($hist) - 2][1] : 0;
            $trend = self::trend($hist, (int) $b['score']);
            if ($prev >= $lim && $trend < 15) {
                continue;   // stond er al boven en stijgt niet hard
            }
            $hosts = q('SELECT DISTINCT f.host_id, f.package, h.name FROM vuln_findings f JOIN hosts h ON h.id = f.host_id WHERE f.cve = ?', [$b['cve']])->fetchAll();
            $hosts = array_values(array_filter($hosts, static fn($h) => !Vulns::ignored((string) $b['cve'], (string) $h['package'], (int) $h['host_id'])));
            q('UPDATE vuln_buzz SET alerted_at = ? WHERE id = ?', [now(), $b['id']]);
            foreach ($hosts as $h) {
                $d = json_decode((string) $b['data'], true) ?: [];
                Monitor::infoHost(['id' => (int) $h['host_id'], 'name' => $h['name'], 'info_agent' => 1], static fn() => '🔥 ' . t('{cve} krijgt veel aandacht (rugbaarheid {s}{t}) en staat op deze server ({p})', [
                    'cve' => $b['cve'], 's' => (int) $b['score'], 't' => $trend > 0 ? ', +' . $trend . ' ' . t('in 7 dagen') : '', 'p' => $h['package']])
                    . (self::why($d) ? ': ' . self::why($d) : ''), 'vuln_buzz');
            }
        }
    }

    /** De belangrijkste redenen in een paar woorden */
    public static function why(array $d): string
    {
        $out = [];
        if (!empty($d['kev']) || ($d['ssvc']['e'] ?? '') === 'active') {
            $out[] = t('actief misbruikt');
        } elseif (($d['ssvc']['e'] ?? '') === 'poc' || !empty($d['edb']) || (int) ($d['gh'] ?? 0) > 0) {
            $out[] = t('openbare exploit');
        }
        if (!empty($d['nuclei'])) {
            $out[] = t('scanners zoeken ernaar');
        }
        $talk = (int) ($d['hn7'] ?? 0) + (int) ($d['md7'] ?? 0);
        if ($talk > 0) {
            $out[] = t('{n} keer besproken deze week', ['n' => $talk]);
        }
        return implode(', ', $out);
    }

    /** Voor de lijst: per CVE de score, de stijging, het id (voor AI) en het oordeel van de AI */
    public static function map(): array
    {
        $out = [];
        $ai = [];
        foreach (q("SELECT a.ref_id, a.verdict FROM ai_advice a JOIN (SELECT MAX(id) AS id FROM ai_advice WHERE kind = 'cve' AND status = 'done' GROUP BY ref_id) x ON x.id = a.id") as $r) {
            $ai[(int) $r['ref_id']] = (string) $r['verdict'];
        }
        foreach (q('SELECT id, cve, score, history, checked_at FROM vuln_buzz WHERE cve IN (SELECT cve FROM vuln_findings)') as $r) {
            $out[$r['cve']] = ['id' => (int) $r['id'], 'score' => $r['checked_at'] ? (int) $r['score'] : null,
                'trend' => $r['checked_at'] ? self::trend(json_decode((string) $r['history'], true) ?: [], (int) $r['score']) : 0, 'ai' => $ai[(int) $r['id']] ?? ''];
        }
        return $out;
    }

    /** Alles over één CVE, voor de popup (en zorgt dat er een rij is, voor het AI-advies) */
    public static function info(string $cve): ?array
    {
        if (!preg_match(self::CVE_RE, $cve)) {
            return null;
        }
        q('INSERT IGNORE INTO vuln_buzz (cve) VALUES (?)', [$cve]);
        $r = q('SELECT * FROM vuln_buzz WHERE cve = ?', [$cve])->fetch();
        $hist = json_decode((string) $r['history'], true) ?: [];
        $ai = q("SELECT verdict FROM ai_advice WHERE kind = 'cve' AND ref_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$r['id']])->fetchColumn();
        return ['id' => (int) $r['id'], 'on' => self::on(), 'checked_at' => $r['checked_at'], 'score' => (int) $r['score'], 'exploit' => (int) $r['exploit'], 'talk' => (int) $r['talk'],
            'data' => json_decode((string) $r['data'], true) ?: [], 'history' => $hist, 'trend' => self::trend($hist, (int) $r['score']),
            'threshold' => self::threshold(), 'ai' => $ai ? (string) $ai : '', 'mastodon' => rtrim((string) setting('cve_mastodon_url', 'https://infosec.exchange'), '/')];
    }

    /** Context voor het AI-advies "Raakt dit jou echt?" (Ai::context, soort cve) */
    public static function aiContext(int $id): array
    {
        $b = q('SELECT * FROM vuln_buzz WHERE id = ?', [$id])->fetch();
        if (!$b) {
            throw new RuntimeException(t('Niet gevonden'));
        }
        $det = Vulns::detail((string) $b['cve']) ?? [];
        $d = json_decode((string) $b['data'], true) ?: [];
        $lines = ['# ' . $b['cve'], (string) ($det['summary'] ?? ''), mb_substr((string) ($det['details'] ?? ''), 0, 1500), '', '## ' . t('Gegevens'),
            '- CVSS: ' . ($det['cvss'] ?? '?') . ($det['vector'] ? ' (' . $det['vector'] . ')' : ''),
            '- EPSS: ' . (isset($det['epss']) ? round((float) $det['epss'] * 100, 2) . '%' : '?'),
            '- ' . t('actief misbruikt (CISA KEV)') . ': ' . (!empty($det['kev']) ? t('ja') : t('nee')),
            '- SSVC (CISA): ' . ($d['ssvc'] ? json_encode($d['ssvc']) : '?'),
            '- ' . t('rugbaarheid') . ': ' . (int) $b['score'] . '/100 · GitHub-PoC\'s: ' . (int) ($d['gh'] ?? 0) . ' · Exploit-DB: ' . (!empty($d['edb']) ? t('ja') : t('nee'))
                . ' · Nuclei: ' . (!empty($d['nuclei']) ? t('ja') : t('nee')) . ' · Hacker News (7 d): ' . (int) ($d['hn7'] ?? 0) . ' · Mastodon (7 d): ' . (int) ($d['md7'] ?? 0),
            '', '## ' . t('Waar het op mijn servers staat')];
        $firstHost = null;
        foreach ((array) ($det['hosts'] ?? []) as $h) {
            $firstHost ??= (int) $h['host_id'];
            $inv = q('SELECT os_name, exposed FROM vuln_inventory WHERE host_id = ?', [$h['host_id']])->fetch() ?: [];
            $ports = [];
            foreach ((array) json_decode((string) ($inv['exposed'] ?? ''), true) as $e) {
                if (is_array($e) && isset($e[0], $e[1]) && ($e[0] === $h['package'] || str_starts_with((string) $e[0], $h['package']))) {
                    $ports[] = $e[1] . '/' . ($e[2] ?? 'tcp');
                }
            }
            $kind = (string) q('SELECT COALESCE(NULLIF(kind, \'\'), kind_auto) FROM hosts WHERE id = ?', [$h['host_id']])->fetchColumn();
            $lines[] = '- ' . $h['host'] . ' (' . ($inv['os_name'] ?? '?') . ($kind ? ', ' . $kind : '') . '): ' . $h['package'] . ' ' . $h['version']
                . ' → ' . ($h['fixed'] ?: t('nog geen oplossing')) . ' · ' . ($ports ? t('luistert op het netwerk: {p}', ['p' => implode(', ', $ports)]) : t('luistert niet op het netwerk'))
                . ($h['ignored'] ? ' · ' . t('risico geaccepteerd') : '');
        }
        return ['text' => implode("\n", array_filter($lines, static fn($l) => $l !== null)), 'host_id' => $firstHost, 'actions' => [], 'diag' => null];
    }

    /** Oordeel uit het antwoord: now (eerst doen) | later (kan wachten) | na (niet van toepassing) */
    public static function verdict(string $answer): string
    {
        if (!preg_match('/(?:OORDEEL|VERDICT)\s*:\s*\**\s*(eerst doen|nu doen|kan wachten|niet van toepassing|act now|fix now|can wait|not applicable)/iu', $answer, $m)) {
            return '';
        }
        return match (mb_strtolower($m[1])) {
            'eerst doen', 'nu doen', 'act now', 'fix now' => 'now',
            'kan wachten', 'can wait' => 'later',
            default => 'na',
        };
    }

    // ------------------------------------------------------------------ HTTP

    /** Alleen https naar een gewone hostnaam (geen IP-adres of poort: de Mastodon-instantie vult de beheerder zelf in) */
    public static function safeUrl(string $u): bool
    {
        return (bool) preg_match('~^https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$~i', $u) && !preg_match('~^https://[\d.]+$~', $u);
    }

    /** Eén GET als tekst (null bij een fout) */
    private static function get(string $url, array $hdr, int $timeout = 20): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => array_merge(['Accept: */*'], $hdr)]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $raw !== false && $code === 200 ? (string) $raw : null;
    }

    /** Meerdere GET-verzoeken tegelijk; JSON terug, null bij een fout of 404 */
    private static function multi(array $urls): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($urls as $k => [$url, $hdr]) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $hdr)]);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($hs as $k => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $out[$k] = $code === 200 ? json_decode((string) curl_multi_getcontent($ch), true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }
}
