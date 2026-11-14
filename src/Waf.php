<?php

declare(strict_types=1);

/**
 * 1.255.0 (agent 1.98.0): een WAF op de reverse proxy, met CrowdSec AppSec en de OWASP Core Rule Set.
 * De agent op de server met de rol "reverse proxy" draait CrowdSec als eigen container (rzsense-crowdsec) op een eigen
 * netwerk (rzsense) waar ook SWAG aan hangt; SWAG vraagt via de mod swag-crowdsec bij elk verzoek of het mag.
 *
 * De slider (waf_mode):
 *   detect  alles wordt bekeken en gemeld, niets tegengehouden;
 *   sure    alleen de virtuele patches (bekende lekken met een CVE) houden tegen, de rest wordt gemeld;
 *   strict  ook de Core Rule Set houdt tegen (SQL-injectie, XSS, …).
 * Per site: inherit (de slider), detect (alleen melden) of off. Uitzonderingen per site: regels die voor die app normaal zijn.
 * Je LAN en de whitelist worden nooit tegengehouden en nooit gemeld.
 *
 * Naar de agent:
 *   0 <tab> waf <tab> on|off <tab> detect|sure|strict <tab> 0|1 (delen met de CrowdSec-community)
 *   0 <tab> wafsite <tab> site <tab> detect|off
 *   0 <tab> wafex <tab> site <tab> regel-id
 * Terug (rapport "waf"): de stand, of de container draait, de koppeling met SWAG, de zelftest en de nieuwe meldingen.
 */
final class Waf
{
    public const MODES = ['detect', 'sure', 'strict'];
    public const SITE_MODES = ['inherit', 'detect', 'off'];
    /** De zelftest van de agent (nep-aanval vanaf dit documentatie-adres); nooit als aanval tellen */
    public const TEST_IP = '203.0.113.10';

    public static function enabled(): bool
    {
        return setting('waf_on', '0') === '1' && ProxyGuard::enabled();
    }

    public static function mode(): string
    {
        $m = (string) setting('waf_mode', 'detect');
        return in_array($m, self::MODES, true) ? $m : 'detect';
    }

    // ------------------------------------------------------------------ naar de agent

    /** @return list<string> */
    public static function agentLines(int $hostId): array
    {
        $h = q('SELECT proxy_role FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h) {
            return [];
        }
        $st = self::status($hostId);
        $installed = $st && in_array($st['state'] ?? '', ['ok', 'waiting', 'error', 'starting'], true);
        if (!(int) $h['proxy_role'] || !self::enabled()) {
            return $installed ? [implode("\t", [0, 'waf', 'off', self::mode(), 0])] : [];
        }
        $out = [implode("\t", [0, 'waf', 'on', self::mode(), setting('waf_share', '0') === '1' ? 1 : 0])];
        foreach (ProxyGuard::sites($hostId) as $s) {
            if (!$s['enabled']) {
                continue;
            }
            if (($s['waf'] ?? 'inherit') !== 'inherit') {
                $out[] = implode("\t", [0, 'wafsite', $s['site'], $s['waf']]);
            }
            foreach (self::exceptList((string) ($s['waf_except'] ?? '')) as $r) {
                $out[] = implode("\t", [0, 'wafex', $s['site'], $r]);
            }
        }
        return $out;
    }

    /** Regels als uitzondering: getallen (CRS-id's) of namen als crowdsecurity/vpatch-CVE-2017-9841 */
    public static function exceptList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $r) {
            if (preg_match('#^(\d{3,7}|[a-z0-9_-]{1,40}/[A-Za-z0-9._-]{1,80})$#', $r)) {
                $out[$r] = $r;
            }
        }
        return array_slice(array_values($out), 0, 50);
    }

    // ------------------------------------------------------------------ van de agent

    public static function fromAgent(array $host, array $in): void
    {
        if (!isset($in['waf']) || !is_array($in['waf'])) {
            return;
        }
        $w = $in['waf'];
        $hid = (int) $host['id'];
        $old = self::status($hid);
        $st = ['state' => in_array($w['state'] ?? '', ['ok', 'waiting', 'starting', 'error', 'off', 'unsupported'], true) ? $w['state'] : 'error',
            'running' => !empty($w['running']), 'bouncer' => in_array($w['bouncer'] ?? '', ['active', 'waiting', 'none'], true) ? $w['bouncer'] : 'none',
            'net' => mb_substr((string) ($w['net'] ?? ''), 0, 60), 'image' => mb_substr((string) ($w['image'] ?? ''), 0, 120),
            'test' => in_array($w['test'] ?? '', ['ok', 'fail', 'skip'], true) ? $w['test'] : 'skip', 'test_msg' => mb_substr((string) ($w['test_msg'] ?? ''), 0, 300),
            'mode' => in_array($w['mode'] ?? '', self::MODES, true) ? $w['mode'] : '', 'msg' => mb_substr((string) ($w['msg'] ?? ''), 0, 500), 'at' => time()];
        q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'waf', ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
            [$hid, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        if (!$old || $old['state'] !== $st['state'] || $old['test'] !== $st['test'] || ($old['mode'] ?? '') !== $st['mode']) {
            $what = ['ok' => t('WAF: actief'), 'waiting' => t('WAF: wacht op de koppeling met de proxy'), 'starting' => t('WAF: wordt gestart'), 'error' => t('WAF: fout'),
                'off' => t('WAF: uit'), 'unsupported' => t('WAF: niet ondersteund')][$st['state']];
            ActionLog::add($hid, 'agent', 'proxy', $what, trim($st['msg'] . ($st['test'] === 'fail' ? ' · ' . $st['test_msg'] : '')), $st['state'] === 'error' || $st['test'] === 'fail' ? false : null);
        }
        if (isset($w['alerts']) && is_string($w['alerts']) && $w['alerts'] !== '') {
            $raw = base64_decode($w['alerts'], true);
            $alerts = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($alerts)) {
                self::ingest($host, $alerts);
            }
        }
    }

    public static function status(int $hostId): ?array
    {
        $v = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'waf'", [$hostId])->fetchColumn(), true);
        return is_array($v) ? $v : null;
    }

    /**
     * Meldingen van CrowdSec (cscli alerts list -o json): elke nieuwe melding één keer opslaan en een IDS-incident
     * (webaanval, per IP samengevat). Of hij tegengehouden is, volgt uit de slider en de site: alleen zeker = alleen
     * virtuele patches, streng = alles; een site op "alleen melden" of je eigen netwerk nooit.
     */
    public static function ingest(array $host, array $alerts): int
    {
        $hid = (int) $host['id'];
        $sites = ProxyGuard::sites($hid);
        $lan = ProxyGuard::lanNets();
        $n = 0;
        foreach (array_slice($alerts, 0, 300) as $a) {
            if (!is_array($a) || !(int) ($a['id'] ?? 0)) {
                continue;
            }
            $meta = [];
            foreach ((array) ($a['events'] ?? []) as $ev) {
                foreach ((array) ($ev['meta'] ?? []) as $m) {
                    if (is_array($m) && isset($m['key'], $m['value'])) {
                        $meta[strtolower((string) $m['key'])][] = (string) $m['value'];
                    }
                }
            }
            $ip = (string) ($a['source']['ip'] ?? $a['source']['value'] ?? ($meta['source_ip'][0] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP) || ip_in_list($ip, $lan) || $ip === self::TEST_IP) {
                continue;   // ongeldig, je eigen netwerk, of de zelftest van de agent
            }
            $siteHost = strtolower((string) ($meta['target_host'][0] ?? $meta['host'][0] ?? ''));
            $uri = (string) ($meta['target_uri'][0] ?? $meta['uri'][0] ?? '');
            $rules = array_values(array_unique(array_merge($meta['rule_name'] ?? [], $meta['rule_ids'] ?? [], $meta['rule_id'] ?? [], [(string) ($a['scenario'] ?? '')])));
            $rules = array_values(array_filter($rules, static fn($r) => $r !== ''));
            $vpatch = (bool) preg_grep('/vpatch|CVE-\d{4}-\d+/i', $rules);
            $site = self::matchSite($siteHost, $sites);
            $blocked = self::wouldBlock(self::mode(), $site['waf'] ?? 'inherit', $vpatch);
            $at = date('Y-m-d H:i:s', strtotime((string) ($a['created_at'] ?? $a['start_at'] ?? 'now')) ?: time());
            $ins = q('INSERT IGNORE INTO waf_events (host_id, cs_id, at, ip, site, uri, rules, vpatch, blocked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$hid, (int) $a['id'], $at, $ip, mb_substr($site['site'] ?? $siteHost, 0, 190), mb_substr($uri, 0, 500), mb_substr(implode(' ', $rules), 0, 300), $vpatch ? 1 : 0, $blocked ? 1 : 0]);
            if ($ins->rowCount() === 0) {
                continue;   // al gezien
            }
            $n++;
            $title = $blocked ? t('WAF hield een aanval tegen op {s}', ['s' => $site['site'] ?? ($siteHost ?: '?')]) : t('WAF zag een aanval op {s} (alleen gemeld)', ['s' => $site['site'] ?? ($siteHost ?: '?')]);
            $detail = mb_substr($uri, 0, 200) . ($rules ? ' · ' . implode(', ', array_slice($rules, 0, 3)) : '');
            Ids::webAttack($host, 'waf', $ip, $title, $detail, t('WAF: {r}', ['r' => $rules[0] ?? 'appsec']));
        }
        return $n;
    }

    /** Zou de WAF dit tegenhouden? (zelfde regels als de configuratie die de agent maakt) */
    public static function wouldBlock(string $mode, string $siteMode, bool $vpatch): bool
    {
        if ($siteMode === 'detect' || $siteMode === 'off') {
            return false;
        }
        return $mode === 'strict' || ($mode === 'sure' && $vpatch);
    }

    /** Welke site hoort bij deze Host (ook app.* en *.domein) */
    public static function matchSite(string $host, array $sites): ?array
    {
        if ($host === '') {
            return null;
        }
        foreach ($sites as $s) {
            foreach ((array) ($s['names'] ?? [$s['site']]) as $n) {
                $re = '/^' . str_replace(['\*\.', '\.\*'], ['.+\.', '\..+'], preg_quote($n, '/')) . '$/i';
                if (preg_match($re, $host)) {
                    return $s;
                }
            }
        }
        return null;
    }

    /** Per site over de laatste dagen: gezien, tegengehouden, en wat "alleen zeker" en "streng" zouden tegenhouden */
    public static function stats(int $hostId, int $days = 14): array
    {
        $out = [];
        foreach (q('SELECT site, COUNT(*) AS n, SUM(blocked) AS b, SUM(vpatch) AS v, COUNT(DISTINCT ip) AS ips FROM waf_events WHERE host_id = ? AND at >= NOW() - INTERVAL ? DAY GROUP BY site',
            [$hostId, $days]) as $r) {
            $out[$r['site']] = ['seen' => (int) $r['n'], 'blocked' => (int) $r['b'], 'sure' => (int) $r['v'], 'strict' => (int) $r['n'], 'ips' => (int) $r['ips']];
        }
        return $out;
    }

    /** De laatste meldingen (voor de pagina) */
    public static function recent(int $hostId, int $limit = 30): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'at' => $r['at'], 'ip' => $r['ip'], 'site' => $r['site'], 'uri' => $r['uri'], 'rules' => $r['rules'],
            'vpatch' => (bool) $r['vpatch'], 'blocked' => (bool) $r['blocked']],
            q('SELECT * FROM waf_events WHERE host_id = ? ORDER BY at DESC, id DESC LIMIT ' . max(1, min(200, $limit)), [$hostId])->fetchAll());
    }

    /** Een regel is normaal voor deze site: als uitzondering bewaren (de agent zet hem in de configuratie) */
    public static function addException(int $hostId, string $site, string $rule, string $by): ?string
    {
        if (!ProxyGuard::validSite($site) || !self::exceptList($rule)) {
            return t('Ongeldige site of regel');
        }
        $cur = q('SELECT waf_except FROM proxy_sites WHERE host_id = ? AND site = ?', [$hostId, $site])->fetchColumn();
        if ($cur === false) {
            // Nog geen eigen instelling: eerst de standaard vastleggen, dan de uitzondering erbij
            $s = null;
            foreach (ProxyGuard::sites($hostId) as $x) {
                if ($x['site'] === $site) {
                    $s = $x;
                }
            }
            if (!$s) {
                return t('Onbekende site');
            }
            ProxyGuard::saveSite($hostId, ['site' => $site, 'enabled' => $s['enabled'], 'lan_mode' => $s['lan_mode'], 'lan_paths' => $s['lan_paths'], 'rate' => $s['rate'], 'bots' => $s['bots']], $by);
            $cur = '';
        }
        $list = self::exceptList($cur . ' ' . $rule);
        q('UPDATE proxy_sites SET waf_except = ? WHERE host_id = ? AND site = ?', [implode(',', $list), $hostId, $site]);
        ActionLog::by($by, $hostId, 'proxy', t('WAF: uitzondering toegevoegd'), "$site: $rule");
        return null;
    }

    /** Onderhoud: meldingen ouder dan 90 dagen weg */
    public static function purge(): void
    {
        q('DELETE FROM waf_events WHERE at < NOW() - INTERVAL 90 DAY');
    }
}
