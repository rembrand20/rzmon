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

    // ------------------------------------------------------------------ 1.259.0: advies voor alle velden van de beheerportal

    /** De velden met hun toegestane waarden (bool = aan/uit) */
    public const SET_FIELDS = ['proxy_on' => 'bool', 'proxy_def_lan' => 'bool', 'proxy_def_rate' => ['off', 'normal', 'strict'], 'proxy_def_bots' => 'bool',
        'waf_on' => 'bool', 'waf_mode' => ['detect', 'sure', 'strict'], 'waf_share' => 'bool', 'proxy_ai_on' => 'bool', 'proxy_ai_auto' => 'bool',
        'av_on' => 'bool', 'av_notify' => 'bool', 'av_move' => 'bool', 'nids_on' => 'bool', 'nids_profile' => ['web', 'balanced', 'full'], 'nids_min_sev' => ['1', '2', '3']];
    /** 1.260.0: velden die de AI niet kiest (adressen en interfaces ziet hij niet); RzSense geeft het advies zelf */
    public const FREE_FIELDS = ['proxy_lan_extra', 'nids_iface'];

    /** Wat RzSense weet over de proxy (voor de regels en voor de AI) */
    public static function facts(): array
    {
        $f = ['servers' => 0, 'swag' => false, 'sites' => 0, 'admin' => 0, 'paths' => 0, 'mem' => null, 'waf_days' => 0, 'waf_events' => 0, 'waf_sure' => 0, 'waf_strict' => 0, 'apps' => [], 'bridges' => []];
        foreach (q("SELECT id FROM hosts WHERE proxy_role = 1 AND mode = 'agent'")->fetchAll(PDO::FETCH_COLUMN) as $hid) {
            $hid = (int) $hid;
            $f['servers']++;
            $rep = ProxyGuard::report($hid);
            $f['swag'] = $f['swag'] || ($rep['kind'] ?? '') === 'swag';
            foreach (ProxyGuard::sites($hid, $rep) as $s) {
                $f['sites']++;
                $k = ProxyGuard::appKind((string) $s['site'], (string) ($s['up'] ?? ''));
                if ($k !== '') {
                    $f[$k]++;
                }
                $f['apps'][] = self::hint((string) ($s['up'] ?? '')) ?: self::hint((string) $s['site']);
            }
            $nd = Nids::status($hid);
            if (isset($nd['mem']) && (int) $nd['mem'] > 0) {
                $f['mem'] = max((int) $f['mem'], (int) $nd['mem']);
            }
            $f['bridges'] = array_merge($f['bridges'], (array) ($nd['found'] ?? []));
            $av = Av::status($hid);
            if (isset($av['mem']) && (int) $av['mem'] > 0) {
                $f['mem'] = max((int) $f['mem'], (int) $av['mem']);
            }
            foreach (Waf::stats($hid) as $st) {
                $f['waf_events'] += $st['seen'];
                $f['waf_sure'] += $st['sure'];
                $f['waf_strict'] += $st['strict'];
            }
            $first = q('SELECT MIN(at) FROM waf_events WHERE host_id = ?', [$hid])->fetchColumn();
            if ($first) {
                $f['waf_days'] = max($f['waf_days'], (int) floor((time() - strtotime((string) $first)) / 86400));
            }
        }
        $f['apps'] = array_slice(array_values(array_unique(array_filter($f['apps']))), 0, 40);
        return $f;
    }

    /** Standaardadvies (zonder AI): vaste regels op basis van wat RzSense weet. @return array<string, array{value: mixed, reason: string}> */
    public static function standard(?array $f = null): array
    {
        $f ??= self::facts();
        $wafLong = $f['waf_days'] >= 14;
        $memOk = $f['mem'] === null || $f['mem'] >= 1400;
        return [
            'proxy_on' => ['value' => true, 'reason' => t('Zonder de hoofdschakelaar doet de rest niets.')],
            'proxy_def_lan' => ['value' => true, 'reason' => t('Beheerpagina\'s horen niet open op internet; je eigen netwerk blijft er altijd bij.')],
            'proxy_def_rate' => ['value' => 'normal', 'reason' => t('Normaal remt wachtwoorden raden en scanners af zonder gewone gebruikers te hinderen; streng kan apps met synchronisatie hinderen.')],
            'proxy_def_bots' => ['value' => true, 'reason' => t('Bekende aanvalstools hebben op je sites niets te zoeken.')],
            'waf_on' => ['value' => $f['swag'], 'reason' => $f['swag'] ? t('Je proxy is SWAG: de WAF herkent aanvallen in het verkeer.') : t('De WAF werkt nu alleen met SWAG.')],
            'waf_mode' => ['value' => $wafLong ? 'sure' : 'detect', 'reason' => $wafLong
                ? t('De WAF draait {d} dagen: alleen bekende lekken tegenhouden geeft vrijwel nooit vals alarm.', ['d' => $f['waf_days']])
                : t('Eerst twee weken alleen melden en kijken wat er tegengehouden zou worden.')],
            'waf_share' => ['value' => false, 'reason' => t('Uit: alles blijft op je eigen server.')],
            'proxy_ai_on' => ['value' => Ai::on(), 'reason' => Ai::on() ? t('AI-advies staat aan: laat de AI per site meedenken.') : t('AI-advies staat uit; zet dat eerst aan als je advies per site wilt.')],
            'proxy_ai_auto' => ['value' => false, 'reason' => t('Zelf beheren: je beoordeelt elk voorstel eerst zelf.')],
            'av_on' => ['value' => $memOk, 'reason' => $memOk ? ($f['mem'] === null ? t('Uploads scannen; de agent kijkt eerst of er genoeg geheugen is.') : t('Er is genoeg geheugen vrij ({m} MB).', ['m' => $f['mem']]))
                : t('Te weinig vrij geheugen ({m} MB, nodig 1400 MB).', ['m' => $f['mem']])],
            'av_notify' => ['value' => true, 'reason' => t('Een vondst wil je weten.')],
            'av_move' => ['value' => true, 'reason' => t('Een besmet bestand meteen afschermen; terugzetten kan altijd.')],
            'nids_on' => ['value' => $f['mem'] === null || $f['mem'] >= ($memOk ? 2200 : 800), 'reason' => $f['mem'] === null || $f['mem'] >= ($memOk ? 2200 : 800)
                ? t('Suricata ziet het onversleutelde verkeer tussen de proxy en je apps, ook wat je apps zelf naar buiten sturen.')
                : t('Te weinig vrij geheugen voor Suricata ({m} MB; nodig ± 800 MB, samen met ClamAV ± 2200 MB).', ['m' => $f['mem']])],
            'nids_profile' => ['value' => 'balanced', 'reason' => t('Gebalanceerd: aanvallen op je webapps én malware of cryptominers in je apps die naar buiten bellen, zonder de lawaaierige beleidsregels.')],
            'nids_min_sev' => ['value' => '2', 'reason' => t('Ernstig en middel worden een incident; de lichte meldingen (meestal informatief) niet.')],
        ];
    }

    /** Advies voor de vrije velden (zonder AI): wat er nu staat blijft, met uitleg */
    public static function freeAdvice(array $f): array
    {
        $lan = (string) setting('proxy_lan_extra', '');
        $if = (string) setting('nids_iface', 'auto');
        return [
            'proxy_lan_extra' => ['value' => $lan, 'source' => 'standard', 'reason' => $lan !== '' ? t('Dit stelde je zelf in; alleen jij weet welke netwerken (bv. je VPN) erbij horen.')
                : t('Leeg is goed als je geen VPN gebruikt: je lokale netwerken en de whitelist tellen al mee.')],
            'nids_iface' => ['value' => 'auto', 'source' => 'standard', 'reason' => $f['bridges']
                ? t('Auto: de agent vond {b}, de bruggen van je proxy.', ['b' => implode(', ', array_unique($f['bridges']))])
                : t('Auto: de agent zoekt zelf de netwerkbrug van je proxy.')],
        ];
    }

    public static function setContext(): array
    {
        $f = self::facts();
        $cur = [];
        foreach (array_keys(self::SET_FIELDS) as $k) {
            $cur[] = "$k=" . setting($k, '');
        }
        $lines = ['# ' . t('Reverse proxy'), "- servers met de rol: {$f['servers']}, SWAG: " . ($f['swag'] ? 'ja' : 'nee'),
            "- sites: {$f['sites']} (beheerprogramma's: {$f['admin']}, apps met adminpaden: {$f['paths']})", '- apps: ' . implode(', ', $f['apps']),
            '- vrij geheugen op de proxy: ' . ($f['mem'] === null ? 'onbekend' : $f['mem'] . ' MB') . ' (ClamAV heeft 1400 MB nodig)',
            "- WAF: {$f['waf_days']} dagen gegevens, {$f['waf_events']} meldingen in 14 dagen, zou tegenhouden: {$f['waf_sure']} (alleen zeker) / {$f['waf_strict']} (streng)",
            '- AI-advies (module) aan: ' . (Ai::on() ? 'ja' : 'nee'), '- nu: ' . implode(', ', $cur)];
        return ['text' => implode("\n", $lines), 'host_id' => null, 'diag' => null, 'actions' => []];
    }

    public static function setSystem(string $lang, string $guard): string
    {
        $fields = [];
        foreach (self::SET_FIELDS as $k => $t) {
            $fields[] = $k . ': ' . ($t === 'bool' ? 'true|false' : implode('|', $t));
        }
        return "Je bent een ervaren beveiligingsspecialist en stelt de instellingen voor van de reverse-proxybescherming van RzSense in een thuis- of kantoornetwerk. "
            . "Velden: " . implode('; ', $fields) . ". Betekenis: proxy_on = hoofdschakelaar; proxy_def_* = standaard voor nieuwe sites (beheerpagina's alleen LAN, rate limit, bots weren); "
            . "waf_mode detect = alleen melden, sure = alleen bekende lekken blokkeren, strict = ook OWASP CRS (meer vals alarm); waf_share = aanvallers delen met CrowdSec; "
            . "proxy_ai_on/proxy_ai_auto = AI-advies per site en of het automatisch wordt toegepast; av_* = ClamAV (heeft 1400 MB vrij geheugen nodig), melden en verplaatsen naar quarantaine. "
            . "nids_* = Suricata op het netwerk tussen de proxy en de apps (± 800 MB); nids_profile web = alleen aanvallen op webapps, balanced = ook malware/C2/cryptominers van de apps naar buiten, full = alle Emerging Threats-regels (veel lawaai); nids_min_sev = vanaf welke ernst (1 hoog, 3 laag) een melding een incident wordt. "
            . "Weeg de gegevens: een WAF met minder dan 14 dagen gegevens hoort op detect; strict alleen als 'zou tegenhouden (streng)' weinig vals alarm laat zien. "
            . "Antwoord met alleen één JSON-object: {\"summary\":\"<twee zinnen>\",\"fields\":{\"<veld>\":{\"value\":<waarde>,\"reason\":\"<één korte zin>\"}}} met elk veld precies één keer. "
            . "Schrijf summary en reason in het $lang. $guard";
    }

    /** Het AI-antwoord controleren; ontbrekende of ongeldige velden krijgen het standaardadvies */
    public static function parseSet(string $answer, array $std): array
    {
        $a = strpos($answer, '{');
        $b = strrpos($answer, '}');
        $j = $a !== false && $b !== false && $b > $a ? json_decode(substr($answer, $a, $b - $a + 1), true) : null;
        $out = [];
        $n = 0;
        foreach (self::SET_FIELDS as $k => $t) {
            $x = is_array($j['fields'][$k] ?? null) ? $j['fields'][$k] : null;
            $v = $x['value'] ?? null;
            $ok = $t === 'bool' ? is_bool($v) : (is_string($v) && in_array($v, $t, true));
            if ($k === 'av_on' && $ok && $v === true && $std['av_on']['value'] === false) {
                $ok = false;   // te weinig geheugen: dan niet aan, wat de AI ook zegt
            }
            if ($k === 'nids_on' && $ok && $v === true && $std['nids_on']['value'] === false) {
                $ok = false;   // te weinig geheugen
            }
            if ($k === 'waf_on' && $ok && $v === true && $std['waf_on']['value'] === false) {
                $ok = false;   // geen SWAG
            }
            $reason = $ok ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($x['reason'] ?? ''))) ?? ''), 0, 240) : '';
            $out[$k] = $ok ? ['value' => $v, 'reason' => $reason !== '' ? $reason : $std[$k]['reason'], 'source' => 'ai'] : $std[$k] + ['source' => 'standard'];
            $n += $ok ? 1 : 0;
        }
        $sum = is_array($j) ? mb_substr(trim(strip_tags((string) ($j['summary'] ?? ''))), 0, 500) : '';
        return ['fields' => $out, 'summary' => $sum, 'ai_fields' => $n];
    }

    /**
     * Advies voor alle velden: met AI als die aan staat (anders, of als de AI niets bruikbaars geeft, de standaard), plus de
     * uploadmappen die bij een site horen, en (met AI-advies per site aan) het advies per site laten maken.
     */
    public static function fill(bool $useAi, string $by): array
    {
        $f = self::facts();
        $std = self::standard($f);
        $res = ['source' => 'standard', 'summary' => '', 'note' => '', 'fields' => []];
        foreach ($std as $k => $v) {
            $res['fields'][$k] = $v + ['source' => 'standard'];
        }
        if ($useAi && Ai::on()) {
            try {
                $r = Ai::ask('proxyset', 1, $by, true);
                $r = Ai::work((int) $r['id']);
                if ($r['status'] === 'done') {
                    $p = self::parseSet((string) $r['answer'], $std);
                    if ($p['ai_fields'] > 0) {
                        $res = ['source' => 'ai', 'summary' => $p['summary'], 'note' => t('🤖 Advies van {m}', ['m' => (string) ($r['model'] ?? '')]) . ($r['note'] ? ' · ' . $r['note'] : ''), 'fields' => $p['fields']];
                    } else {
                        $res['note'] = t('De AI gaf geen bruikbaar antwoord; dit is het standaardadvies.');
                    }
                } else {
                    $res['note'] = t('AI-advies lukte niet ({e}); dit is het standaardadvies.', ['e' => mb_substr((string) $r['error'], 0, 160)]);
                }
            } catch (RuntimeException $e) {
                $res['note'] = t('AI-advies lukte niet ({e}); dit is het standaardadvies.', ['e' => $e->getMessage()]);
            }
        } elseif ($useAi) {
            $res['note'] = t('AI-advies staat uit; dit is het standaardadvies.');
        }
        $res['fields'] += self::freeAdvice($f);
        foreach ($res['fields'] as $k => &$v) {
            $cur = setting($k, null);
            $v['current'] = (self::SET_FIELDS[$k] ?? '') === 'bool' ? $cur === '1' : (string) ($cur ?? '');
        }
        unset($v);
        // Uploadmappen die bij een site horen (uit de voorstellen van de agent)
        $res['dirs'] = [];
        foreach (q("SELECT id FROM hosts WHERE proxy_role = 1 AND mode = 'agent'")->fetchAll(PDO::FETCH_COLUMN) as $hid) {
            $ov = Av::overview((int) $hid, ProxyGuard::sites((int) $hid));
            foreach ($ov['suggest'] as $s) {
                if ($s['site'] !== '') {
                    $res['dirs'][] = ['host_id' => (int) $hid, 'site' => $s['site'], 'path' => $s['path'], 'ctr' => $s['ctr']];
                }
            }
            // Advies per site (komt binnen een paar minuten op de pagina Reverse proxy)
            if ($res['source'] === 'ai' && self::on()) {
                try {
                    Ai::ask('proxy', (int) $hid, $by, true);
                    $res['sites_asked'] = true;
                } catch (RuntimeException) {
                }
            }
        }
        $res['dirs'] = array_slice($res['dirs'], 0, 30);
        return $res;
    }

    public static function purge(): void
    {
        q("DELETE FROM proxy_advice WHERE status IN ('applied', 'dismissed', 'same') AND created_at < NOW() - INTERVAL 90 DAY");
    }
}
