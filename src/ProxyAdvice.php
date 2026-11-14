<?php

declare(strict_types=1);

/**
 * 1.256.0: AI-advies voor de reverse proxy. De AI leest per site wat RzSense weet (de app erachter, het huidige beleid, wat
 * de WAF zag) en stelt per site een beleid voor: wie mag erbij (adminpaden of de hele site alleen vanaf je netwerk), rate
 * limit, bots weren en de WAF per site. Met een reden per site.
 *
 * - Veilig: de AI geeft alleen een JSON met keuzes uit vaste lijsten. RzSense controleert elke waarde (dezelfde controle als
 *   wanneer je het zelf instelt); wat niet klopt, valt weg. De AI voert nooit iets uit en schrijft nooit configuratie.
 * - Privacy: de AI ziet geen domeinnamen: elke site is een code (S + 6 tekens) met alleen het eerste deel van de naam (bv.
 *   "nextcloud"). IP-adressen en servernamen worden bij een cloudmodel gemaskeerd zoals bij ander AI-advies.
 * - Slider in de beheerportal: Zelf beheren = het advies staat klaar en jij past het per site toe (of alles in één keer);
 *   Automatisch = RzSense past het meteen toe, behalve bij sites die jij zelf hebt ingesteld.
 * - Automatisch vragen: als er een nieuwe site bij komt of het beleid verandert, en verder hooguit één keer per week als de
 *   WAF iets nieuws zag. Kosten, daglimiet, maandbudget en terugvallen op Ollama gaan zoals bij het andere AI-advies.
 */
final class ProxyAdvice
{
    public const FIELDS = ['lan_mode', 'lan_paths', 'rate', 'bots', 'waf'];

    public static function on(): bool
    {
        return setting('proxy_ai_on', '0') === '1' && Ai::on() && ProxyGuard::enabled();
    }

    public static function auto(): bool
    {
        return setting('proxy_ai_auto', '0') === '1';
    }

    /** Vaste code per site (de AI ziet de domeinnaam niet) */
    public static function code(string $site): string
    {
        return 'S' . substr(sha1('rzsense-proxy|' . $site), 0, 6);
    }

    /** Het herkenbare deel van een naam: "nextcloud.*" → nextcloud, "*.intern.example.nl" → intern, "app:443" → app */
    public static function hint(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('#^[a-z]+://#', '', $s) ?? $s;
        $s = preg_replace('/:\d+.*$/', '', $s) ?? $s;
        if (filter_var($s, FILTER_VALIDATE_IP)) {
            return $s;   // wordt bij een cloudmodel gemaskeerd
        }
        foreach (explode('.', $s) as $part) {
            if ($part !== '' && $part !== '*') {
                return mb_substr(preg_replace('/[^a-z0-9_-]/', '', $part) ?? '', 0, 40);
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ vraag aan de AI

    /** Context voor Ai::work (kind "proxy", ref = de server met de rol) */
    public static function aiContext(int $hostId): array
    {
        $h = q('SELECT id, name, proxy_role FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || !(int) $h['proxy_role']) {
            throw new RuntimeException(t('Deze server is niet de reverse proxy'));
        }
        $rep = ProxyGuard::report($hostId);
        $sites = ProxyGuard::sites($hostId, $rep);
        if (!$sites) {
            throw new RuntimeException(t('Nog geen sites van de agent'));
        }
        $own = [];
        foreach (q('SELECT site, source FROM proxy_sites WHERE host_id = ?', [$hostId]) as $r) {
            $own[$r['site']] = $r['source'];
        }
        $stats = Waf::stats($hostId);
        $rules = [];
        foreach (q('SELECT site, rules, COUNT(*) AS n, COUNT(DISTINCT ip) AS ips FROM waf_events WHERE host_id = ? AND at >= NOW() - INTERVAL 14 DAY GROUP BY site, rules ORDER BY n DESC LIMIT 200', [$hostId]) as $r) {
            if (count($rules[$r['site']] ?? []) < 5) {
                $rules[$r['site']][] = mb_substr((string) $r['rules'], 0, 80) . " ({$r['n']}x, {$r['ips']} IP)";
            }
        }
        $wafOn = setting('waf_on', '0') === '1';
        $lines = ['# ' . t('Reverse proxy') . ' (' . strtoupper((string) ($rep['kind'] ?? '?')) . ')',
            '- WAF: ' . ($wafOn ? Waf::mode() : 'off') . ' (detect = alleen melden, sure = alleen bekende lekken blokkeren, strict = ook OWASP CRS)',
            '- ' . t('Standaard voor nieuwe sites') . ': lan=' . (setting('proxy_def_lan', '1') === '1' ? 'ja' : 'nee') . ', rate=' . setting('proxy_def_rate', 'normal') . ', bots=' . (setting('proxy_def_bots', '1') === '1' ? 'ja' : 'nee'),
            '', '## Sites'];
        foreach ($sites as $s) {
            $names = array_values(array_unique(array_filter(array_map([self::class, 'hint'], (array) ($s['names'] ?? [$s['site']])))));
            $st = $stats[$s['site']] ?? null;
            $lines[] = '- id=' . self::code($s['site']) . ' | naam: ' . implode(', ', $names) . ' | app erachter: ' . (self::hint((string) ($s['up'] ?? '')) ?: '?')
                . ' | bestand: ' . self::hint((string) ($s['file'] ?? ''))
                . ' | nu: aan=' . ($s['enabled'] ? 'ja' : 'nee') . ', lan_mode=' . $s['lan_mode'] . ', lan_paths=' . ($s['lan_paths'] !== '' ? $s['lan_paths'] : '-')
                . ', rate=' . $s['rate'] . ', bots=' . ($s['bots'] ? 'ja' : 'nee') . ', waf=' . ($s['waf'] ?? 'inherit')
                . ' | ingesteld door: ' . (!$s['custom'] ? 'standaard' : (($own[$s['site']] ?? 'user') === 'ai' ? 'AI' : 'beheerder'))
                . ($st ? " | WAF 14 dagen: {$st['seen']} meldingen van {$st['ips']} IP's, {$st['sure']} bekende lekken" : ' | WAF 14 dagen: niets')
                . (!empty($rules[$s['site']]) ? ' | regels: ' . implode('; ', $rules[$s['site']]) : '');
        }
        return ['text' => implode("\n", $lines), 'host_id' => $hostId, 'diag' => null, 'actions' => []];
    }

    /** De opdracht aan het model: alleen JSON, alleen keuzes uit vaste lijsten */
    public static function system(string $lang, string $guard): string
    {
        return "Je bent een ervaren beveiligingsspecialist voor reverse proxies (nginx, SWAG, Nginx Proxy Manager) in een thuis- of kantoornetwerk. "
            . "Stel per site het beleid voor dat RzSense doorvoert. Antwoord met alleen één JSON-object, zonder uitleg ervoor of erna, in deze vorm:\n"
            . '{"summary":"<twee zinnen>","sites":[{"id":"<id uit de gegevens>","lan_mode":"off|paths|site","lan_paths":["/admin"],"rate":"off|normal|strict","bots":true,"waf":"inherit|detect|off","reason":"<één korte zin>"}]}' . "\n"
            . "Betekenis: lan_mode site = de hele site alleen vanaf het eigen netwerk (voor beheerprogramma's zoals Portainer, AdGuard, Proxmox, UniFi, Cockpit, phpMyAdmin, webmail-beheer); "
            . "paths = alleen de adminpaden in lan_paths (zoals /admin van Vaultwarden, /wp-admin van WordPress, /settings/admin van Nextcloud); off = iedereen. "
            . "rate normal = 20 verzoeken per seconde per adres, strict = 5 per seconde (alleen voor inlogpagina's of kleine sites). "
            . "Let op apps met mobiele apps of synchronisatie (Nextcloud, Home Assistant, Jellyfin, Plex, Immich, Vaultwarden, Syncthing): die doen veel verzoeken, dus geen strict en de site niet helemaal afsluiten. "
            . "waf detect = voor deze site alleen melden (bij veel meldingen die op vals alarm lijken: veel verschillende IP's op gewone paden, WebDAV, API's); off alleen als de WAF de app echt breekt; anders inherit. "
            . "bots false alleen als de site bedoeld is voor tools die op scanners lijken. Paden beginnen met / en bevatten alleen letters, cijfers en ._~/-. "
            . "Geef elke site uit de gegevens precies één keer terug, ook als er niets verandert. Twijfel je, laat het huidige beleid staan. "
            . "Schrijf summary en reason in het $lang. $guard";
    }

    // ------------------------------------------------------------------ antwoord verwerken

    /**
     * Het antwoord controleren: alleen bekende sites en geldige waarden. @return array{summary: string, sites: array<string, array>}|null
     */
    public static function parse(string $answer, array $sites): ?array
    {
        $a = strpos($answer, '{');
        $b = strrpos($answer, '}');
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }
        $j = json_decode(substr($answer, $a, $b - $a + 1), true);
        if (!is_array($j) || !is_array($j['sites'] ?? null)) {
            return null;
        }
        $byCode = [];
        foreach ($sites as $s) {
            $byCode[self::code($s['site'])] = $s;
        }
        $out = [];
        foreach (array_slice($j['sites'], 0, 300) as $x) {
            if (!is_array($x) || !isset($byCode[(string) ($x['id'] ?? '')])) {
                continue;
            }
            $s = $byCode[(string) $x['id']];
            $mode = (string) ($x['lan_mode'] ?? '');
            $rate = (string) ($x['rate'] ?? '');
            $waf = (string) ($x['waf'] ?? 'inherit');
            $raw = $x['lan_paths'] ?? '';
            $paths = ProxyGuard::cleanPaths(is_array($raw) ? implode(',', array_map('strval', array_filter($raw, 'is_scalar'))) : (string) $raw);
            if (!in_array($mode, ProxyGuard::LAN_MODES, true) || !in_array($rate, ProxyGuard::RATES, true) || !in_array($waf, Waf::SITE_MODES, true)
                || $paths === null || ($mode === 'paths' && $paths === '') || !is_bool($x['bots'] ?? null)) {
                continue;   // ongeldig: deze site niet
            }
            $reason = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($x['reason'] ?? ''))) ?? '');
            $out[$s['site']] = ['lan_mode' => $mode, 'lan_paths' => $mode === 'paths' ? $paths : '', 'rate' => $rate, 'bots' => $x['bots'], 'waf' => $waf,
                'reason' => mb_substr($reason, 0, 300)];
        }
        $sum = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($j['summary'] ?? ''))) ?? '');
        return ['summary' => mb_substr($sum, 0, 600), 'sites' => $out];
    }

    /** Welke velden het advies verandert ten opzichte van het huidige beleid */
    public static function diff(array $cur, array $adv): array
    {
        $d = [];
        foreach (self::FIELDS as $f) {
            $a = $adv[$f];
            $c = $cur[$f] ?? null;
            if ($f === 'lan_paths') {
                if (($adv['lan_mode'] ?? '') !== 'paths') {
                    continue;
                }
                $a = implode(',', array_unique(explode(',', (string) $a)));
            }
            if ($f === 'waf') {
                $c ??= 'inherit';
            }
            if ((is_bool($a) ? (bool) $c !== $a : (string) $c !== (string) $a)) {
                $d[] = $f;
            }
        }
        return $d;
    }

    /** Na een gelukt advies (Ai::afterDone): opslaan per site, en bij Automatisch meteen toepassen */
    public static function fromAi(array $r): void
    {
        $hid = (int) $r['ref_id'];
        $sites = ProxyGuard::sites($hid);
        $p = self::parse((string) ($r['answer'] ?? ''), $sites);
        if ($p === null) {
            q("UPDATE ai_advice SET error = ? WHERE id = ?", [t('De AI gaf geen bruikbaar antwoord (geen geldige JSON)'), (int) $r['id']]);
            return;
        }
        $cur = [];
        foreach ($sites as $s) {
            $cur[$s['site']] = $s;
        }
        foreach ($p['sites'] as $site => $adv) {
            $reason = $adv['reason'];
            unset($adv['reason']);
            $changes = self::diff($cur[$site], $adv);
            q("INSERT INTO proxy_advice (host_id, site, advice_id, data, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
               ON DUPLICATE KEY UPDATE advice_id = VALUES(advice_id), data = VALUES(data), reason = VALUES(reason), status = VALUES(status), created_at = VALUES(created_at), decided_at = NULL, decided_by = NULL",
                [$hid, $site, (int) $r['id'], json_encode($adv), $reason, $changes ? 'new' : 'same']);
        }
        $applied = self::auto() ? self::applyAll($hid, 'RzSense AI', true) : 0;
        $n = (int) q("SELECT COUNT(*) FROM proxy_advice WHERE host_id = ? AND status = 'new'", [$hid])->fetchColumn();
        ActionLog::add($hid, 'rzsense', 'proxy', t('Reverse proxy: AI-advies ontvangen'),
            self::auto() ? t('{n} sites aangepast (automatisch)', ['n' => $applied]) : t('{n} voorstellen klaar', ['n' => $n]), null);
    }

    // ------------------------------------------------------------------ toepassen

    /** Eén voorstel toepassen. $onlyFree: niet bij sites die de beheerder zelf instelde (Automatisch). @return string|null fout */
    public static function apply(int $hostId, string $site, string $by, bool $onlyFree = false): ?string
    {
        $a = q("SELECT * FROM proxy_advice WHERE host_id = ? AND site = ? AND status = 'new'", [$hostId, $site])->fetch();
        if (!$a) {
            return t('Geen open voorstel voor deze site');
        }
        $own = q('SELECT * FROM proxy_sites WHERE host_id = ? AND site = ?', [$hostId, $site])->fetch();
        if ($onlyFree && $own && $own['source'] !== 'ai') {
            return t('Zelf ingesteld: niet automatisch aangepast');
        }
        $cur = null;
        foreach (ProxyGuard::sites($hostId) as $s) {
            if ($s['site'] === $site) {
                $cur = $s;
            }
        }
        if ($cur === null) {
            return t('Onbekende site');
        }
        $d = (array) json_decode((string) $a['data'], true);
        $err = ProxyGuard::saveSite($hostId, ['site' => $site, 'enabled' => $cur['enabled'], 'lan_mode' => $d['lan_mode'] ?? '', 'lan_paths' => $d['lan_paths'] ?? '',
            'rate' => $d['rate'] ?? '', 'bots' => !empty($d['bots']), 'waf' => $d['waf'] ?? 'inherit'], str_starts_with($by, 'RzSense') ? $by : $by . ' (' . t('AI-advies') . ')');
        if ($err !== null) {
            return $err;
        }
        q("UPDATE proxy_sites SET source = 'ai', ai_reason = ? WHERE host_id = ? AND site = ?", [mb_substr((string) $a['reason'], 0, 300), $hostId, $site]);
        q("UPDATE proxy_advice SET status = 'applied', decided_at = NOW(), decided_by = ? WHERE id = ?", [mb_substr($by, 0, 64), (int) $a['id']]);
        return null;
    }

    /** Alle open voorstellen toepassen. @return int aantal */
    public static function applyAll(int $hostId, string $by, bool $onlyFree = false): int
    {
        $n = 0;
        foreach (q("SELECT site FROM proxy_advice WHERE host_id = ? AND status = 'new'", [$hostId])->fetchAll(PDO::FETCH_COLUMN) as $site) {
            if (self::apply($hostId, (string) $site, $by, $onlyFree) === null) {
                $n++;
            }
        }
        return $n;
    }

    public static function dismiss(int $hostId, string $site, string $by): bool
    {
        return q("UPDATE proxy_advice SET status = 'dismissed', decided_at = NOW(), decided_by = ? WHERE host_id = ? AND site = ? AND status = 'new'",
            [mb_substr($by, 0, 64), $hostId, $site])->rowCount() > 0;
    }

    // ------------------------------------------------------------------ automatisch vragen

    /** Elke minuut (Ai::cron): vragen als de sites veranderden, of hooguit wekelijks als de WAF iets nieuws zag */
    public static function cron(): void
    {
        if (!self::on()) {
            return;
        }
        foreach (q("SELECT id FROM hosts WHERE proxy_role = 1 AND mode = 'agent'")->fetchAll(PDO::FETCH_COLUMN) as $hid) {
            $hid = (int) $hid;
            $rep = ProxyGuard::report($hid);
            $names = array_map(static fn($s) => (string) $s['name'], (array) ($rep['sites'] ?? []));
            if (!$names) {
                continue;
            }
            sort($names);
            $week = date('o-W');
            $waf = (int) q('SELECT COUNT(*) FROM waf_events WHERE host_id = ? AND at >= NOW() - INTERVAL 7 DAY', [$hid])->fetchColumn();
            $stamp = sha1(implode(',', $names)) . ($waf > 0 ? "|$week" : '');
            $last = Ai::latest('proxy', $hid);
            if ($last && in_array($last['status'], ['pending', 'running'], true)) {
                continue;
            }
            if ($last && strtotime((string) $last['created_at']) > time() - 6 * 3600) {
                continue;   // hooguit om de 6 uur
            }
            if (!Once::claim('proxy_ai', "h$hid", $stamp)) {
                continue;
            }
            try {
                Ai::ask('proxy', $hid, 'auto', true, true);
            } catch (RuntimeException) {
                return;   // daglimiet, budget of geen model: later opnieuw
            }
        }
    }

    // ------------------------------------------------------------------ voor de pagina's

    public static function state(int $hostId): array
    {
        $last = Ai::latest('proxy', $hostId);
        $sum = '';
        if ($last && $last['status'] === 'done') {
            $p = self::parse((string) $last['answer'], ProxyGuard::sites($hostId));
            $sum = $p['summary'] ?? '';
        }
        $items = [];
        foreach (q("SELECT site, data, reason, status, created_at, decided_by FROM proxy_advice WHERE host_id = ? ORDER BY site", [$hostId]) as $r) {
            $items[$r['site']] = ['data' => json_decode((string) $r['data'], true), 'reason' => $r['reason'], 'status' => $r['status'], 'at' => $r['created_at'], 'by' => $r['decided_by']];
        }
        return ['last' => $last ? ['id' => (int) $last['id'], 'status' => $last['status'], 'at' => $last['done_at'] ?: $last['created_at'], 'provider' => $last['provider'], 'model' => $last['model'],
            'auto' => (bool) $last['auto'], 'error' => $last['error'], 'note' => $last['note'], 'summary' => $sum] : null, 'items' => (object) $items];
    }

    public static function purge(): void
    {
        q("DELETE FROM proxy_advice WHERE status IN ('applied', 'dismissed', 'same') AND created_at < NOW() - INTERVAL 90 DAY");
    }
}
