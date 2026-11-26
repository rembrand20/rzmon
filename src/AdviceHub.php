<?php
declare(strict_types=1);

/**
 * 1.333.0: Advies op één plek. RzSense gaf advies op zeven plaatsen (Analyse en advies, slimme voorstellen, de
 * statuscontroles van de beheerportal, Aan de slag, trends, voorstellen voor de reverse proxy en wat RzSense zelf
 * aanpaste). Dit voegt ze samen tot één lijst voor het dashboard, met de belangrijkste bovenaan. De bronnen zelf blijven
 * bestaan en beslissen (toepassen, gedaan, later); dit is alleen het verzamelpunt.
 *
 * Een punt: id (bron:sleutel), src, sev (0 laag, 1 middel, 2 hoog), title, sub, go (waar je het regelt) en
 * acts (apply, go, later, na). "Later" (7 dagen) en "niet van toepassing" onthoudt de bron zelf (Analyse) of de
 * instelling hub_state.
 */
final class AdviceHub
{
    public const SOURCES = ['auto', 'start', 'status', 'smart', 'trends', 'proxy', 'analyse'];

    /**
     * Alle open adviezen, de belangrijkste eerst
     * @return list<array<string, mixed>>
     */
    public static function items(bool $admin): array
    {
        $cf = self::cacheFile($admin);
        if (is_file($cf) && filemtime($cf) > time() - 60 && is_array($c = json_decode((string) file_get_contents($cf), true))) {
            return $c;
        }
        $out = self::compute($admin);
        @file_put_contents($cf, json_encode($out));
        return $out;
    }

    /** Berekend per taal en per soort gebruiker, hooguit eens per minuut (de statuscontroles zijn niet gratis) */
    private static function cacheFile(bool $admin): string
    {
        return sys_get_temp_dir() . '/rzmon-hub-' . md5(SM_ROOT . '|' . I18n::lang() . '|' . ($admin ? 'a' : 'u') . '|' . (string) q('SELECT DATABASE()')->fetchColumn()) . '.json';
    }

    /** Na een beslissing: de bewaarde lijst vergeten */
    public static function forget(): void
    {
        foreach (glob(sys_get_temp_dir() . '/rzmon-hub-*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function compute(bool $admin): array
    {
        $out = [];
        foreach (self::SOURCES as $src) {
            try {
                $list = match ($src) {
                    'auto' => self::auto(), 'start' => self::start($admin), 'status' => self::status($admin), 'smart' => self::smart($admin),
                    'trends' => self::trends(), 'proxy' => self::proxy($admin), default => self::analyse(),
                };
                foreach ($list as $x) {
                    $out[] = $x + ['src' => $src, 'sub' => '', 'acts' => ['go']];
                }
            } catch (Throwable) {
                // één bron die het niet doet mag de rest niet tegenhouden
            }
        }
        $hidden = self::hidden();
        $out = array_values(array_filter($out, static fn($x) => !isset($hidden[$x['id']])));
        $order = array_flip(self::SOURCES);
        usort($out, static fn($a, $b) => [$b['sev'], $order[$a['src']]] <=> [$a['sev'], $order[$b['src']]]);
        return $out;
    }

    /**
     * Voor het dashboard: het aantal en de bovenste punten
     * @return array{n: int, high: int, top: list<array<string, mixed>>}
     */
    public static function summary(bool $admin, int $top = 5): array
    {
        $all = self::items($admin);
        return ['n' => count($all), 'high' => count(array_filter($all, static fn($x) => $x['sev'] === 2)), 'top' => array_slice($all, 0, $top)];
    }

    /** Later (7 dagen niet tonen), niet van toepassing, of weer gewoon (open) */
    public static function set(string $id, string $status, string $by): bool
    {
        if ($id === 'all' && $status === 'open') {   // alles wat hier verborgen is weer tonen (Analyse onthoudt zijn eigen status)
            save_setting('hub_state', '{}');
            self::forget();
            return true;
        }
        if (!preg_match('/^([a-z]+):(.{1,190})$/s', $id, $m) || !in_array($status, ['later', 'na', 'open'], true)) {
            return false;
        }
        self::forget();
        if ($m[1] === 'analyse') {
            return Insights::setStatus($m[2], $status, $by);
        }
        if (!in_array($m[1], ['start', 'status', 'trends', 'proxy', 'auto'], true)) {
            return false;
        }
        $st = self::state();
        unset($st[$id]);
        if ($status !== 'open') {
            $st[$id] = ['s' => $status, 'until' => $status === 'later' ? time() + 7 * 86400 : 0, 'by' => $by, 'at' => time()];
        }
        save_setting('hub_state', (string) json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return true;
    }

    /** Hoeveel er hier verborgen is (later dat nog loopt, of genegeerd) */
    public static function hiddenCount(): int
    {
        return count(self::hidden());
    }

    /** @return array<string, array<string, mixed>> */
    private static function state(): array
    {
        $st = json_decode((string) setting('hub_state', ''), true);
        return is_array($st) ? $st : [];
    }

    /** @return array<string, true> wat nu niet getoond wordt (later dat nog loopt, of niet van toepassing) */
    private static function hidden(): array
    {
        $out = [];
        foreach (self::state() as $id => $s) {
            if (($s['s'] ?? '') === 'na' || (int) ($s['until'] ?? 0) > time()) {
                $out[(string) $id] = true;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ de bronnen

    /**
     * Wat RzSense de laatste 24 uur zelf aanpaste (slimme drempels, melden na N): één regel, met het logboek erachter
     * @return list<array<string, mixed>>
     */
    private static function auto(): array
    {
        $rows = q("SELECT id, label FROM change_log WHERE at >= NOW() - INTERVAL 24 HOUR AND auto = 1 AND kind = 'check' AND undone_at IS NULL ORDER BY id DESC LIMIT 20")->fetchAll();
        if (!$rows) {
            return [];
        }
        $n = count($rows);
        return [['id' => 'auto:' . (int) $rows[0]['id'], 'sev' => 0, 'icon' => '🤖',
            'title' => $n === 1 ? t('RzSense paste 1 ding zelf aan') : t('RzSense paste {n} dingen zelf aan', ['n' => $n]),
            'sub' => implode(' · ', array_map(static fn($r) => (string) $r['label'], array_slice($rows, 0, 2))) . ($n > 2 ? ' …' : '') . ' · ' . t('terug te draaien in het logboek'),
            'go' => 'logboek', 'acts' => ['go', 'na']]];
    }

    /**
     * Aan de slag: wat een nieuwe installatie nog mist (profiel, eerste server, proefmelding)
     * @return list<array<string, mixed>>
     */
    private static function start(bool $admin): array
    {
        if (!$admin) {
            return [];
        }
        $ob = Onboard::data();
        if ($ob['done']) {
            return [];
        }
        $out = [];
        if ($ob['profile'] === '') {
            $out[] = ['id' => 'start:profile', 'sev' => 1, 'icon' => '🚀', 'title' => t('Kies een profiel'), 'sub' => t('Dan stelt de wizard voor wat bij je past.'), 'go' => 'wizard:1', 'acts' => ['go', 'na']];
        }
        if ($ob['agents'] === 0) {
            $out[] = ['id' => 'start:server', 'sev' => 1, 'icon' => '🚀', 'title' => t('Koppel je eerste server'), 'sub' => t('Plak de installatieopdracht op je server; de agent meldt zich binnen een minuut.'), 'go' => 'wizard:2', 'acts' => ['go', 'na']];
        }
        if ($ob['mail'] && !$ob['mail_tested']) {
            $out[] = ['id' => 'start:mail', 'sev' => 0, 'icon' => '🚀', 'title' => t('Stuur een proefmelding per e-mail'), 'sub' => t('Dan weet je zeker dat je vangnet werkt.'), 'go' => 'settings/channels', 'acts' => ['go', 'na']];
        }
        return $out;
    }

    /**
     * De statuscontroles van de beheerportal die niet in orde zijn
     * @return list<array<string, mixed>>
     */
    private static function status(bool $admin): array
    {
        if (!$admin) {
            return [];
        }
        $out = [];
        foreach (SetHealth::items() as $i) {
            if (!in_array($i['status'], ['bad', 'warn'], true)) {
                continue;
            }
            $out[] = ['id' => 'status:' . $i['key'], 'sev' => $i['status'] === 'bad' ? 2 : 1, 'icon' => '⚙️', 'title' => (string) $i['title'], 'sub' => strip_tags((string) $i['detail']),
                'go' => 'settings/' . $i['section'], 'acts' => ['go', 'later', 'na']];
        }
        return $out;
    }

    /**
     * Slimme voorstellen (melden na N, vast onderhoudsvenster, afhankelijkheid): toepassen of negeren
     * @return list<array<string, mixed>>
     */
    private static function smart(bool $admin): array
    {
        $out = [];
        foreach (Advice::list() as $a) {
            $out[] = ['id' => 'smart:' . $a['id'], 'sev' => 0, 'icon' => ['fails' => '🔔', 'noise' => '🔕', 'maint' => '🗓️', 'dep' => '🔗'][$a['kind']] ?? '💡', 'title' => (string) $a['label'], 'sub' => (string) $a['text'],
                'go' => 'settings/advice', 'acts' => $admin ? ['apply', 'ignore'] : ['go']];
        }
        return $out;
    }

    /**
     * Trends die binnen de meldtermijn een grens raken (schijf vol, accu te laag …)
     * @return list<array<string, mixed>>
     */
    private static function trends(): array
    {
        // alleen wat al berekend is (elke nacht, en als je Analyse → Trends opent): het dashboard rekent niet zelf
        $r = json_decode((string) setting('trends_cache_' . (I18n::lang() === 'en' ? 'en' : 'nl'), ''), true);
        if (!is_array($r)) {
            return [];
        }
        $days = max(1, (int) setting('trend_alert_days', '30'));
        $out = [];
        foreach ($r['items'] ?? [] as $x) {
            if (empty($x['bad']) || ($x['eta_days'] ?? null) === null || $x['eta_days'] > $days) {
                continue;
            }
            $out[] = ['id' => 'trends:' . $x['id'], 'sev' => $x['eta_days'] <= 7 ? 2 : 1, 'icon' => (string) ($x['icon'] ?? '📉'),
                'title' => trim((string) $x['title'] . ($x['sub'] !== '' ? ' · ' . $x['sub'] : '')), 'sub' => (string) ($x['short'] ?: $x['text']),
                'go' => 'trends', 'acts' => ['go', 'later']];
        }
        return $out;
    }

    /**
     * Open voorstellen voor sites achter de reverse proxy: één regel per proxy
     * @return list<array<string, mixed>>
     */
    private static function proxy(bool $admin): array
    {
        if (!$admin) {
            return [];
        }
        $out = [];
        foreach (q("SELECT a.host_id, h.name, COUNT(*) AS n FROM proxy_advice a JOIN hosts h ON h.id = a.host_id WHERE a.status = 'new' GROUP BY a.host_id, h.name") as $r) {
            $out[] = ['id' => 'proxy:' . (int) $r['host_id'] . ':' . (int) $r['n'], 'sev' => 0, 'icon' => '🔀',
                'title' => (int) $r['n'] === 1 ? t('1 voorstel voor een site achter {h}', ['h' => $r['name']]) : t('{n} voorstellen voor sites achter {h}', ['n' => (int) $r['n'], 'h' => $r['name']]),
                'sub' => t('Bescherming per site: bekijken en toepassen bij Reverse proxy.'), 'go' => 'proxy', 'acts' => ['go', 'later']];
        }
        return $out;
    }

    /**
     * Analyse en advies: de open adviezen (niet gedaan, niet later, niet "niet van toepassing")
     * @return list<array<string, mixed>>
     */
    private static function analyse(): array
    {
        $out = [];
        foreach (Insights::page()['advices'] as $a) {
            if (($a['status'] ?? null) !== null) {
                continue;
            }
            $out[] = ['id' => 'analyse:' . $a['key'], 'sev' => (int) $a['sev'], 'icon' => '💡', 'title' => strip_tags((string) $a['title']), 'sub' => strip_tags((string) ($a['do'] ?: ($a['why'][0] ?? ''))),
                'go' => 'insight:' . $a['key'], 'acts' => ['go', 'later', 'na']];
        }
        return $out;
    }
}
