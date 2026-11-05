<?php

declare(strict_types=1);

/**
 * 1.180.0: alle rapporten op één plek (Instellingen → Rapporten). Per rapport: aan/uit, hoe vaak (week of maand), de dag
 * en de tijd, de kanalen (e-mail en/of push), en "Nu versturen". Daarnaast het Maandoverzicht: één bericht met de
 * onderdelen die je kiest. E-mail is HTML (met een platte versie erbij), een push krijgt een korte samenvatting.
 * Instellingen in één JSON-instelling `reports`; bij de eerste keer overgenomen uit de oude instellingen (IDS-weekoverzicht,
 * AI-weekoverzicht, maandrapport), zodat er na de update niets verandert. Laatst verstuurd per rapport in `reports_last`.
 */
final class Reports
{
    /** Alle rapporten: sleutel => [icoon, vaste frequentie of null (week en maand kiesbaar)] */
    public const LIST = [
        'ids_week' => ['🛡️', 'week'],
        'ai_week' => ['🤖', 'week'],
        'month' => ['📈', 'month'],
        'vulns' => ['🐞', null],
        'avail' => ['📶', null],
        'backup' => ['💾', null],
        'leaks' => ['🔓', null],
        'all' => ['📦', null],
    ];
    /** Onderdelen die in het Maandoverzicht kunnen */
    public const PARTS = ['month', 'ids_week', 'vulns', 'avail', 'backup', 'leaks'];

    public static function label(string $k): string
    {
        return match ($k) {
            'ids_week' => t('IDS-weekoverzicht'),
            'ai_week' => t('AI-weekoverzicht'),
            'month' => t('Maandrapport'),
            'vulns' => t('Kwetsbaarheden'),
            'avail' => t('Beschikbaarheid'),
            'backup' => t('Back-ups'),
            'leaks' => t('Datalekken'),
            'all' => t('Maandoverzicht (alles in één)'),
            default => $k,
        };
    }

    /** Standaard voor een rapport */
    private static function def(string $k): array
    {
        return ['on' => false, 'freq' => self::LIST[$k][1] ?? 'month', 'day' => 1, 'time' => '08:00', 'email' => true, 'push' => false]
            + ($k === 'all' ? ['parts' => self::PARTS] : []);
    }

    /** De instellingen van alle rapporten (bij de eerste keer uit de oude instellingen) */
    public static function cfg(): array
    {
        $raw = json_decode((string) setting('reports', ''), true);
        if (!is_array($raw)) {
            $iw = (string) setting('ids_weekly', 'email');
            $raw = [
                'ids_week' => ['on' => $iw !== 'off' && $iw !== '', 'day' => (int) setting('ids_weekly_day', '1'), 'time' => (string) setting('ids_weekly_time', '08:00'),
                    'email' => in_array($iw, ['email', 'both'], true), 'push' => in_array($iw, ['pushover', 'both'], true)],
                'ai_week' => ['on' => setting('ai_weekly', '1') === '1', 'day' => (int) setting('ai_weekly_day', '1'), 'time' => '08:00', 'email' => true, 'push' => true],
                'month' => ['on' => setting('report_monthly', '0') === '1', 'day' => 1, 'time' => '07:00', 'email' => true, 'push' => false],
            ];
            save_setting('reports', json_encode($raw));
        }
        $out = [];
        foreach (array_keys(self::LIST) as $k) {
            $out[$k] = self::clean($k, (array) ($raw[$k] ?? []));
        }
        return $out;
    }

    /** Eén rapport controleren en aanvullen */
    public static function clean(string $k, array $in): array
    {
        $d = self::def($k);
        $fixed = self::LIST[$k][1];
        $freq = $fixed ?? (in_array($in['freq'] ?? '', ['week', 'month'], true) ? $in['freq'] : $d['freq']);
        $out = [
            'on' => !empty($in['on'] ?? $d['on']), 'freq' => $freq,
            'day' => $freq === 'week' ? max(1, min(7, (int) ($in['day'] ?? 1))) : max(1, min(28, (int) ($in['day'] ?? 1))),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($in['time'] ?? '')) ? (string) $in['time'] : $d['time'],
            'email' => !empty($in['email'] ?? $d['email']), 'push' => !empty($in['push'] ?? $d['push']),
        ];
        if ($k === 'all') {
            $out['parts'] = array_values(array_intersect(self::PARTS, (array) ($in['parts'] ?? $d['parts'])));
        }
        return $out;
    }

    /** Opslaan vanuit de beheerportal */
    public static function save(array $in): array
    {
        $cur = self::cfg();
        foreach (array_keys(self::LIST) as $k) {
            if (isset($in[$k]) && is_array($in[$k])) {
                $cur[$k] = self::clean($k, $in[$k] + $cur[$k]);
            }
        }
        save_setting('reports', json_encode($cur));
        // De oude instellingen blijven kloppen (REST API, back-ups van vóór 1.180.0)
        save_setting('report_monthly', $cur['month']['on'] ? '1' : '0');
        save_setting('ai_weekly', $cur['ai_week']['on'] ? '1' : '0');
        save_setting('ai_weekly_day', (string) $cur['ai_week']['day']);
        save_setting('ids_weekly', !$cur['ids_week']['on'] ? 'off' : ($cur['ids_week']['email'] && $cur['ids_week']['push'] ? 'both' : ($cur['ids_week']['push'] ? 'pushover' : 'email')));
        save_setting('ids_weekly_day', (string) $cur['ids_week']['day']);
        save_setting('ids_weekly_time', $cur['ids_week']['time']);
        return $cur;
    }

    /** Is het rapport nu aan de beurt? @return string|null het kenmerk van deze periode (één keer per periode) */
    public static function due(string $k, array $c, ?int $now = null): ?string
    {
        $now ??= time();
        if (!$c['on'] || date('H:i', $now) < $c['time']) {
            return null;
        }
        if ($c['freq'] === 'week') {
            return (int) date('N', $now) === $c['day'] ? date('o-\WW', $now) : null;
        }
        return (int) date('j', $now) >= $c['day'] ? date('Y-m', $now) : null;
    }

    /** Elke minuut vanuit cron: wat aan de beurt is versturen (één keer per week of maand) */
    public static function cron(): ?string
    {
        $last = json_decode((string) setting('reports_last', ''), true) ?: [];
        $done = [];
        foreach (self::cfg() as $k => $c) {
            $id = self::due($k, $c);
            if ($id === null || ($last[$k] ?? '') === $id) {
                continue;
            }
            if ($k === 'ids_week' && !Ids::enabled()) {
                continue;
            }
            $last[$k] = $id;
            save_setting('reports_last', json_encode($last));
            if ($k === 'ai_week') {
                self::askAiWeek();   // de AI schrijft hem; versturen gebeurt als hij klaar is (afterAiWeek)
                $done[] = $k;
                continue;
            }
            $r = self::send($k);
            $done[] = $k . ($r['ok'] ? '' : ' (' . ($r['error'] ?? '?') . ')');
        }
        return $done ? t('Rapporten: {l}', ['l' => implode(', ', $done)]) : null;
    }

    // ------------------------------------------------------------------ versturen

    /**
     * Een rapport versturen via de ingestelde kanalen (of: alleen naar $to per e-mail, de knop "Naar mij").
     * @return array{ok: bool, error?: string, sent: array}
     */
    public static function send(string $k, ?string $to = null): array
    {
        if (!isset(self::LIST[$k])) {
            return ['ok' => false, 'error' => t('Onbekend rapport'), 'sent' => []];
        }
        if ($k === 'ai_week') {
            $a = q("SELECT * FROM ai_advice WHERE kind = 'week' AND status = 'done' ORDER BY id DESC LIMIT 1")->fetch();
            if (!$a) {
                if ($to === null) {
                    self::askAiWeek();
                }
                return ['ok' => false, 'error' => t('Er is nog geen AI-weekoverzicht. Het wordt nu gemaakt (AI-advies moet aan staan); probeer het over een paar minuten opnieuw.'), 'sent' => []];
            }
        }
        $c = self::cfg()[$k];
        $doc = I18n::forNotify(fn() => self::build($k));
        $sent = [];
        $err = null;
        if ($to !== null) {
            $r = Mailer::send($doc['subject'], $doc['text'], null, $to, $doc['html']);
            return ['ok' => $r['ok'], 'error' => $r['error'], 'sent' => $r['ok'] ? ['email'] : []];
        }
        $email = $c['email'] || !$c['push'];   // geen kanaal gekozen: dan e-mail
        if ($email) {
            $r = Mailer::send($doc['subject'], $doc['text'], null, null, $doc['html']);
            $r['ok'] ? $sent[] = 'email' : $err = 'E-mail: ' . $r['error'];
        }
        if ($c['push'] && Push::enabled()) {
            $r = Push::send($doc['subject'], $doc['short'], -1);
            !empty($r['ok']) ? $sent[] = 'push' : $err = ($err ? $err . ' · ' : '') . 'Push: ' . ($r['error'] ?? '?');
        }
        return ['ok' => (bool) $sent, 'error' => $err, 'sent' => $sent];
    }

    /** Het AI-weekoverzicht laten maken (zelfde week: niet opnieuw) */
    private static function askAiWeek(): void
    {
        if (!Ai::on() || !Ai::x('ai_weekly')) {
            return;
        }
        try {
            Ai::ask('week', (int) date('oW'), 'auto', false, true);
        } catch (Throwable $e) {
            error_log('RzMon: AI-weekoverzicht: ' . $e->getMessage());
        }
    }

    /** Het AI-weekoverzicht is klaar (Ai::afterDone): versturen via de kanalen van dit rapport */
    public static function afterAiWeek(): void
    {
        if (self::cfg()['ai_week']['on']) {
            self::send('ai_week');
        }
    }

    // ------------------------------------------------------------------ inhoud

    /** @return array{subject: string, text: string, html: string, short: string} */
    public static function build(string $k): array
    {
        $c = self::cfg()[$k];
        if ($k === 'all') {
            $secs = [];
            foreach ($c['parts'] as $p) {
                try {
                    $secs[$p] = self::part($p, 'month');
                } catch (Throwable $e) {
                    $secs[$p] = ['title' => self::label($p), 'text' => t('Niet beschikbaar: {e}', ['e' => $e->getMessage()]), 'html' => '', 'short' => ''];
                }
            }
            $subject = t('RzMon-maandoverzicht {m}', ['m' => self::month(date('Y-m', strtotime('first day of last month')))]);
            $text = implode("\n\n", array_map(static fn($s) => '== ' . $s['title'] . " ==\n" . $s['text'], $secs));
            $short = implode(' · ', array_filter(array_map(static fn($s) => $s['short'], $secs)));
            $short = mb_strlen($short) > 300 ? rtrim(mb_substr($short, 0, 299)) . '…' : $short;
            return ['subject' => $subject, 'text' => $text, 'html' => self::page($subject, $secs), 'short' => $short ?: t('Het hele overzicht staat in je e-mail.')];
        }
        $s = self::part($k, $c['freq']);
        $subject = $s['subject'] ?? $s['title'];
        return ['subject' => $subject, 'text' => $s['text'], 'html' => self::page($subject, [$k => $s]), 'short' => $s['short']];
    }

    /** Periode van het rapport: de afgelopen week of de vorige maand. @return array{0: string, 1: string} van en tot (Y-m-d) */
    public static function period(string $freq, ?int $now = null): array
    {
        $now ??= time();
        if ($freq === 'week') {
            return [date('Y-m-d', $now - 7 * 86400), date('Y-m-d', $now - 86400)];
        }
        $from = date('Y-m-01', strtotime('first day of last month', $now));
        return [$from, date('Y-m-t', strtotime($from))];
    }

    /** Eén onderdeel: titel, tekst, HTML-blok en een korte zin voor push */
    private static function part(string $k, string $freq): array
    {
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        [$from, $to] = self::period($freq);
        $when = $freq === 'week' ? t('afgelopen 7 dagen') : self::month(substr($from, 0, 7));
        switch ($k) {
            case 'ids_week':
                [$subject, $body, $short] = Ids::weeklyText();
                return ['title' => self::label($k), 'subject' => $subject, 'text' => $body, 'html' => self::pre($body), 'short' => $short];
            case 'ai_week':
                $a = q("SELECT answer, created_at FROM ai_advice WHERE kind = 'week' AND status = 'done' ORDER BY id DESC LIMIT 1")->fetch() ?: ['answer' => '', 'created_at' => ''];
                $sum = Ai::section((string) $a['answer'], ['Samenvatting', 'Summary'], 300);
                return ['title' => self::label($k), 'subject' => '📅 ' . t('Weekoverzicht van RzMon'), 'text' => (string) $a['answer'], 'html' => self::md((string) $a['answer']), 'short' => $sum];
            case 'month':
                $r = Analytics::report("$from..$to");
                $k2 = $r['kpi'];
                $av = $k2['avail'][0] ?? null;
                $short = t('Beschikbaar {a} · {i} storingen · {n} meldingen', ['a' => $av !== null ? I18n::dec((string) round((float) $av, 2)) . '%' : '–',
                    'i' => (int) ($k2['incidents'][0] ?? 0), 'n' => (int) ($k2['alerts'][0] ?? 0)]);
                return ['title' => self::label($k) . ' · ' . $when, 'subject' => t('RzMon-maandrapport {m}', ['m' => self::month(substr($from, 0, 7))]),
                    'text' => Analytics::monthlyTextFor($r, substr($from, 0, 7)), 'html' => Report::html("$from..$to", true), 'short' => $short];
            case 'vulns':
                return self::partVulns($when, $e);
            case 'avail':
                return self::partAvail($freq, $when, $e);
            case 'backup':
                return self::partBackup($from, $to, $when, $e);
            case 'leaks':
                return self::partLeaks($from, $when, $e);
        }
        throw new RuntimeException(t('Onbekend rapport'));
    }

    private static function partVulns(string $when, callable $e): array
    {
        if (!Vulns::enabled()) {
            return ['title' => self::label('vulns'), 'text' => t('Kwetsbaarheden staat uit.'), 'html' => '<p>' . $e(t('Kwetsbaarheden staat uit.')) . '</p>', 'short' => ''];
        }
        $o = Vulns::overview();
        $live = array_values(array_filter($o['findings'], static fn($f) => !$f['ignored']));
        $uniq = static fn(array $fs) => count(array_unique(array_map(static fn($f) => $f['host_id'] . '|' . $f['cve'], $fs)));
        $sev = [4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ([4, 3, 2, 1] as $s) {
            $sev[$s] = $uniq(array_filter($live, static fn($f) => $f['severity'] === $s));
        }
        $kev = $uniq(array_filter($live, static fn($f) => $f['kev']));
        $fix = $uniq(array_filter($live, static fn($f) => $f['fixed'] !== null));
        // Eén update, veel opgelost: per pakket het risico dat verdwijnt
        $pk = [];
        foreach ($live as $f) {
            if ($f['fixed'] === null) {
                continue;
            }
            $p = &$pk[$f['package']];
            $p ??= ['name' => $f['package'], 'cves' => [], 'hosts' => [], 'sum' => 0, 'kev' => 0];
            if (!isset($p['cves'][$f['cve']])) {
                $p['kev'] += $f['kev'] ? 1 : 0;
            }
            $p['cves'][$f['cve']] = 1;
            $p['hosts'][$f['host_id']] = 1;
            $p['sum'] += $f['risk'];
            unset($p);
        }
        usort($pk, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        $pk = array_slice($pk, 0, 5);
        $buzz = $o['buzz'] ? array_filter($o['buzz'], static fn($b) => $b['score'] !== null && $b['score'] >= (int) $o['buzz_min']) : [];
        arsort($buzz);
        $lines = [t('Open: {c} critical · {h} high · {m} medium · {l} low', ['c' => $sev[4], 'h' => $sev[3], 'm' => $sev[2], 'l' => $sev[1]]),
            t('Actief misbruikt: {n} · met oplossing: {f} · opgelost in 30 dagen: {r}', ['n' => $kev, 'f' => $fix, 'r' => (int) $o['resolved']])];
        if ($pk) {
            $lines[] = '';
            $lines[] = t('Eén update, veel opgelost:');
            foreach ($pk as $p) {
                $lines[] = '- ' . $p['name'] . ': ' . t('{n} kwetsbaarheden op {h} server(s)', ['n' => count($p['cves']), 'h' => count($p['hosts'])]) . ($p['kev'] ? ' · ⚠️ ' . t('{n} actief misbruikt', ['n' => $p['kev']]) : '');
            }
        }
        if ($buzz) {
            $lines[] = '';
            $lines[] = '🔥 ' . t('Veel aandacht:') . ' ' . implode(', ', array_map(static fn($c, $b) => "$c ({$b['score']})", array_keys(array_slice($buzz, 0, 5, true)), array_slice($buzz, 0, 5, true)));
        }
        $html = '<div class="tiles">' . self::tile(t('Critical'), (string) $sev[4], $sev[4] ? 'c' : '') . self::tile(t('High'), (string) $sev[3], $sev[3] ? 'w' : '')
            . self::tile(t('Actief misbruikt'), (string) $kev, $kev ? 'c' : '') . self::tile(t('Met oplossing'), (string) $fix, '') . self::tile(t('Opgelost (30 dagen)'), (string) (int) $o['resolved'], 'g') . '</div>'
            . ($pk ? '<h3>' . $e(t('Eén update, veel opgelost')) . '</h3><table><tr><th>' . $e(t('Pakket')) . '</th><th>' . $e(t('Kwetsbaarheden')) . '</th><th>' . $e(t('Servers')) . '</th><th>' . $e(t('Actief misbruikt')) . '</th></tr>'
                . implode('', array_map(static fn($p) => '<tr><td><b>' . $e($p['name']) . '</b></td><td>' . count($p['cves']) . '</td><td>' . count($p['hosts']) . '</td><td>' . ($p['kev'] ? '⚠️ ' . $p['kev'] : '–') . '</td></tr>', $pk)) . '</table>' : '')
            . ($buzz ? '<p>🔥 <b>' . $e(t('Veel aandacht:')) . '</b> ' . $e(implode(', ', array_map(static fn($c, $b) => "$c ({$b['score']})", array_keys(array_slice($buzz, 0, 5, true)), array_slice($buzz, 0, 5, true)))) . '</p>' : '');
        return ['title' => self::label('vulns'), 'text' => implode("\n", $lines), 'html' => $html,
            'short' => t('{c} critical, {k} actief misbruikt, {f} op te lossen', ['c' => $sev[4], 'k' => $kev, 'f' => $fix])];
    }

    private static function partAvail(string $freq, string $when, callable $e): array
    {
        $r = Availability::report($freq === 'week' ? 7 : 30);
        $names = q('SELECT id, name FROM hosts')->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = [];
        foreach ($r['hosts'] as $hid => $h) {
            if (!isset($names[$hid]) || $h['pct'] === null) {
                continue;
            }
            $rows[] = ['name' => $names[$hid], 'pct' => (float) $h['pct'], 'n' => (int) $h['n'], 'down' => (int) $h['down_s'], 'long' => (int) $h['longest_s']];
        }
        usort($rows, static fn($a, $b) => $a['pct'] <=> $b['pct'] ?: strcmp($a['name'], $b['name']));
        $avg = $rows ? array_sum(array_column($rows, 'pct')) / count($rows) : null;
        $dur = static fn(int $s) => $s >= 3600 ? I18n::dec((string) round($s / 3600, 1)) . ' ' . t('uur') : max(1, (int) round($s / 60)) . ' min';
        $pct = static fn(float $v) => I18n::dec((string) round($v, 2)) . '%';
        $bad = array_values(array_filter($rows, static fn($x) => $x['n'] > 0));
        $lines = [t('Gemiddeld beschikbaar: {p} ({w})', ['p' => $avg !== null ? $pct($avg) : '–', 'w' => $when]), t('{n} van {t} servers hadden een storing', ['n' => count($bad), 't' => count($rows)])];
        foreach (array_slice($bad, 0, 10) as $x) {
            $lines[] = '- ' . $x['name'] . ': ' . $pct($x['pct']) . ' · ' . t('{n} storing(en), samen {d}, langste {l}', ['n' => $x['n'], 'd' => $dur($x['down']), 'l' => $dur($x['long'])]);
        }
        $html = '<div class="tiles">' . self::tile(t('Gemiddeld beschikbaar'), $avg !== null ? $pct($avg) : '–', $avg !== null && $avg < 99 ? 'w' : 'g')
            . self::tile(t('Servers met een storing'), count($bad) . '/' . count($rows), $bad ? 'w' : 'g') . '</div>'
            . ($rows ? '<table><tr><th>' . $e(t('Server')) . '</th><th>' . $e(t('Beschikbaar')) . '</th><th>' . $e(t('Storingen')) . '</th><th>' . $e(t('Samen')) . '</th><th>' . $e(t('Langste')) . '</th></tr>'
                . implode('', array_map(static fn($x) => '<tr><td>' . $e($x['name']) . '</td><td class="' . ($x['pct'] < 99 ? 'w' : '') . '">' . $pct($x['pct']) . '</td><td>' . $x['n'] . '</td><td>' . ($x['n'] ? $dur($x['down']) : '–') . '</td><td>' . ($x['n'] ? $dur($x['long']) : '–') . '</td></tr>', array_slice($rows, 0, 25))) . '</table>' : '');
        return ['title' => self::label('avail') . ' · ' . $when, 'text' => implode("\n", $lines), 'html' => $html,
            'short' => t('Gemiddeld {p} beschikbaar, {n} server(s) met een storing', ['p' => $avg !== null ? $pct($avg) : '–', 'n' => count($bad)])];
    }

    private static function partBackup(string $from, string $to, string $when, callable $e): array
    {
        $all = Backup::list();
        $in = array_values(array_filter($all, static fn($b) => substr((string) $b['at'], 0, 10) >= $from && substr((string) $b['at'], 0, 10) <= $to));
        $size = array_sum(array_column($all, 'size'));
        $last = $all[0]['at'] ?? null;
        $problem = Backup::problem();
        $freq = (string) setting('backup_freq', 'off');
        $mb = static fn(int $b) => I18n::dec((string) round($b / 1048576, 1)) . ' MB';
        $lines = [t('{n} back-up(s) gemaakt ({w}) · {t} bewaard, samen {s}', ['n' => count($in), 'w' => $when, 't' => count($all), 's' => $mb((int) $size)]),
            t('Laatste: {t}', ['t' => $last ?: '–']), t('Automatisch: {f}', ['f' => $freq === 'off' ? t('uit') : $freq])];
        if ($problem) {
            $lines[] = '⚠️ ' . $problem;
        }
        $html = '<div class="tiles">' . self::tile(t('Gemaakt'), (string) count($in), count($in) ? 'g' : 'w') . self::tile(t('Bewaard'), (string) count($all), '')
            . self::tile(t('Ruimte'), $mb((int) $size), '') . self::tile(t('Laatste'), $last ? substr((string) $last, 0, 16) : '–', $last ? '' : 'w') . '</div>'
            . ($problem ? '<p class="w">⚠️ ' . $e($problem) . '</p>' : '') . ($freq === 'off' ? '<p class="w">' . $e(t('Automatische back-ups staan uit.')) . '</p>' : '');
        return ['title' => self::label('backup') . ' · ' . $when, 'text' => implode("\n", $lines), 'html' => $html,
            'short' => t('{n} back-up(s), laatste {t}', ['n' => count($in), 't' => $last ? substr((string) $last, 0, 16) : '–']) . ($problem ? ' · ⚠️' : '')];
    }

    private static function partLeaks(string $from, string $when, callable $e): array
    {
        if (!Hibp::enabled()) {
            return ['title' => self::label('leaks'), 'text' => t('Datalekken staat uit.'), 'html' => '<p>' . $e(t('Datalekken staat uit.')) . '</p>', 'short' => ''];
        }
        $o = Hibp::overview();
        $new = [];
        $open = 0;
        foreach ($o['emails'] as $m) {
            foreach ($m['breaches'] as $b) {
                if (!$b['handled_at']) {
                    $open++;
                }
                if ((string) $b['found_at'] >= $from) {
                    $new[] = ['email' => $m['email'], 'title' => $b['title'] ?: $b['name'], 'pw' => !empty($b['passwords']), 'date' => $b['breach_date']];
                }
            }
        }
        $lines = [t('{n} nieuw ({w}) · {o} nog niet afgehandeld · {e} adressen bewaakt', ['n' => count($new), 'w' => $when, 'o' => $open, 'e' => count($o['emails'])])];
        foreach (array_slice($new, 0, 15) as $x) {
            $lines[] = '- ' . $x['email'] . ': ' . $x['title'] . ($x['pw'] ? ' · ' . t('met wachtwoorden') : '');
        }
        $html = '<div class="tiles">' . self::tile(t('Nieuw'), (string) count($new), $new ? 'c' : 'g') . self::tile(t('Niet afgehandeld'), (string) $open, $open ? 'w' : 'g')
            . self::tile(t('Adressen'), (string) count($o['emails']), '') . '</div>'
            . ($new ? '<table><tr><th>' . $e(t('Adres')) . '</th><th>' . $e(t('Lek')) . '</th><th>' . $e(t('Wachtwoorden')) . '</th></tr>'
                . implode('', array_map(static fn($x) => '<tr><td>' . $e($x['email']) . '</td><td>' . $e($x['title']) . '</td><td>' . ($x['pw'] ? '⚠️ ' . $e(t('ja')) : '–') . '</td></tr>', array_slice($new, 0, 15))) . '</table>' : '');
        return ['title' => self::label('leaks') . ' · ' . $when, 'text' => implode("\n", $lines), 'html' => $html, 'short' => t('{n} nieuwe datalekken', ['n' => count($new)])];
    }

    /** "november 2026" (de functie staat bij Analytics) */
    private static function month(string $m): string
    {
        class_exists(Analytics::class);
        return strftime_month($m);
    }

    // ------------------------------------------------------------------ HTML

    private static function tile(string $label, string $value, string $cls): string
    {
        return '<div class="tile ' . $cls . '"><small>' . htmlspecialchars($label, ENT_QUOTES) . '</small><b>' . htmlspecialchars($value, ENT_QUOTES) . '</b></div>';
    }

    private static function pre(string $text): string
    {
        return '<div class="txt">' . nl2br(htmlspecialchars($text, ENT_QUOTES)) . '</div>';
    }

    /** Eenvoudige Markdown (van de AI) als HTML: kopjes, lijsten, vet */
    private static function md(string $md): string
    {
        $out = [];
        foreach (preg_split('/\R/', htmlspecialchars($md, ENT_QUOTES)) ?: [] as $l) {
            $l = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $l) ?? $l;
            if (preg_match('/^\s*#{1,4}\s*(.+)$/', $l, $m)) {
                $out[] = '<h4>' . $m[1] . '</h4>';
            } elseif (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.+)$/', $l, $m)) {
                $out[] = '<div class="li">• ' . $m[1] . '</div>';
            } elseif (trim($l) !== '') {
                $out[] = '<p>' . $l . '</p>';
            }
        }
        return implode('', $out);
    }

    /** Het hele document (ook geschikt voor e-mail: alles in één HTML-bestand, zonder scripts of externe bestanden) */
    public static function page(string $title, array $secs): string
    {
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $base = rtrim((string) config('base_url', ''), '/');
        $body = '';
        foreach ($secs as $s) {
            $body .= '<section><h2>' . $e($s['title']) . '</h2>' . $s['html'] . '</section>';
        }
        return '<!doctype html><html lang="' . $e(I18n::lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title><style>'
            . Report::css() . self::css() . '</style></head><body><main><h1>' . $e($title) . '</h1>' . $body
            . '<footer>' . $e(t('Verstuurd door RzMon {v}', ['v' => RZMON_VERSION])) . ($base !== '' ? ' · <a href="' . $e($base) . '/">' . $e($base) . '</a>' : '') . ' · '
            . $e(t('Instellen: Instellingen → Rapporten')) . '</footer></main></body></html>';
    }

    private static function css(): string
    {
        return 'section{margin:0 0 26px;padding:0 0 6px;border-bottom:1px solid #e3e7ef}.tiles{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px}'
            . '.tile{flex:1 1 120px;border:1px solid #dfe4ee;border-radius:10px;padding:8px 10px;background:#f7f9fc}.tile small{display:block;color:#5b6478;font-size:12px}'
            . '.tile b{font-size:20px}.tile.c b,td.c,.c{color:#c4283f}.tile.w b,td.w,p.w{color:#b8730a}.tile.g b{color:#1c8a5a}.txt{white-space:normal;font-size:14px;line-height:1.5}'
            . '.li{margin:2px 0 2px 6px}h4{margin:12px 0 4px}';
    }
}
