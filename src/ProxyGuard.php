<?php

declare(strict_types=1);

/**
 * 1.254.0 (agent 1.97.0): de reverse proxy beschermen. Eén of meer servers hebben de rol "reverse proxy"; de agent daar
 * herkent de proxy (SWAG of Nginx Proxy Manager, in Podman of Docker), leest de sites uit en voert per site het beleid uit
 * RzSense door: adminpagina's alleen vanaf het LAN, een rate limit en slechte bots weren. De agent schrijft alleen in eigen
 * bestanden, test de configuratie (nginx -t) vóór het herladen en zet de vorige versie terug als een site daarna niet meer
 * antwoordt. Alles staat in de beheerportal (Beveiliging → Reverse proxy) en op de pagina Reverse proxy; uit = alles weg.
 *
 * Naar de agent (alleen op een server met de rol):
 *   0 <tab> proxy <tab> on|off
 *   0 <tab> proxylan <tab> 192.168.1.0/24 10.8.0.0/24 …       (je LAN, de whitelist en extra netwerken)
 *   0 <tab> proxysite <tab> site <tab> off|paths|site <tab> /admin,/settings|- <tab> off|normal|strict <tab> 0|1
 * Terug (rapport "proxy"): soort, container, configmap, of de koppeling er staat, de stand (ok, error, off, unsupported),
 * een melding, het kenmerk van wat is doorgevoerd en de sites (naam, alle namen, de app erachter, het bestand).
 */
final class ProxyGuard
{
    public const RATES = ['off', 'normal', 'strict'];
    public const LAN_MODES = ['off', 'paths', 'site'];

    /** Bekende apps: adminpaden die standaard alleen vanaf het LAN mogen, of de hele site (beheerprogramma's) */
    private const APP_PATHS = [
        'vaultwarden' => '/admin', 'bitwarden' => '/admin',
        'nextcloud' => '/settings/admin,/index.php/settings/admin',
        'wordpress' => '/wp-admin,/wp-login.php',
        'gitea' => '/-/admin,/admin', 'forgejo' => '/-/admin,/admin',
        'jellyfin' => '/web/index.html#!/dashboard.html', 'grafana' => '/admin',
        'immich' => '/admin', 'paperless' => '/admin',
    ];
    private const ADMIN_APPS = '/portainer|adguard|pihole|pi-hole|unifi|proxmox|cockpit|webmin|phpmyadmin|adminer|webadmin|syncthing|netdata|truenas|uptime-kuma|dockge|yacht|rzsense|rzmon/';

    public static function enabled(): bool
    {
        return setting('proxy_on', '0') === '1';
    }

    // ------------------------------------------------------------------ naar de agent

    /** @return list<string> */
    public static function agentLines(int $hostId): array
    {
        $h = q('SELECT proxy_role FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h) {
            return [];
        }
        $rep = self::report($hostId);
        $role = (int) $h['proxy_role'] === 1;
        if (!$role) {
            // Rol uit, maar de agent had de koppeling nog staan: laten opruimen
            return $rep && !empty($rep['hook']) ? [implode("\t", [0, 'proxy', 'off'])] : [];
        }
        if (!self::enabled()) {
            return [implode("\t", [0, 'proxy', 'off'])];
        }
        $out = [implode("\t", [0, 'proxy', 'on']), implode("\t", [0, 'proxylan', implode(' ', self::lanNets())])];
        foreach (self::sites($hostId, $rep) as $s) {
            if (!$s['enabled']) {
                continue;
            }
            $out[] = implode("\t", [0, 'proxysite', $s['site'], $s['lan_mode'], $s['lan_paths'] !== '' ? $s['lan_paths'] : '-', $s['rate'], $s['bots'] ? 1 : 0]);
        }
        return $out;
    }

    /** Je LAN (Toegang → Lokale netwerken), de whitelist en extra netwerken; niets ingesteld = de privé-netwerken */
    public static function lanNets(): array
    {
        $all = array_merge(parse_list((string) setting('trusted_networks', '')), parse_list((string) setting('proxy_lan_extra', '')));
        if (class_exists('Whitelist')) {
            $all = array_merge($all, Whitelist::values('ip'));
        }
        $ok = [];
        foreach ($all as $n) {
            $n = trim((string) $n);
            if (self::validNet($n)) {
                $ok[$n] = $n;
            }
        }
        return $ok ? array_slice(array_values($ok), 0, 50) : PRIVATE_NETWORKS;
    }

    public static function validNet(string $n): bool
    {
        if (!preg_match('#^([0-9a-fA-F:.]+)(?:/(\d{1,3}))?$#', $n, $m) || !filter_var($m[1], FILTER_VALIDATE_IP)) {
            return false;
        }
        $max = str_contains($m[1], ':') ? 128 : 32;
        return !isset($m[2]) || ((int) $m[2] >= 1 && (int) $m[2] <= $max);
    }

    /** Adminpaden: /pad, gescheiden door komma of spatie; hooguit 10 */
    public static function cleanPaths(string $raw): ?string
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $p) {
            if ($p === '') {
                continue;
            }
            if (!preg_match('#^/[A-Za-z0-9._~/-]{0,100}$#', $p) || str_contains($p, '..') || str_contains($p, '//')) {
                return null;
            }
            $out[$p] = $p;
        }
        return implode(',', array_slice(array_values($out), 0, 10));
    }

    public static function validSite(string $s): bool
    {
        // naam, *.naam of naam.* (SWAG: server_name app.*)
        return (bool) preg_match('/^(\*\.)?[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})*(\.\*)?$/', $s) && strlen($s) <= 190;
    }

    // ------------------------------------------------------------------ van de agent

    public static function fromAgent(array $host, array $in): void
    {
        if (!isset($in['proxy']) || !is_array($in['proxy'])) {
            return;
        }
        $p = $in['proxy'];
        $sites = [];
        foreach (array_slice((array) ($p['sites'] ?? []), 0, 200) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $name = strtolower((string) ($s['name'] ?? ''));
            if (!self::validSite($name)) {
                continue;
            }
            $names = array_values(array_filter(array_map('strtolower', array_slice((array) ($s['names'] ?? []), 0, 10)), [self::class, 'validSite']));
            $sites[$name] = ['name' => $name, 'names' => $names ?: [$name], 'up' => mb_substr((string) ($s['up'] ?? ''), 0, 120),
                'file' => mb_substr((string) ($s['file'] ?? ''), 0, 120), 'code' => (int) ($s['code'] ?? 0)];
        }
        $old = self::report((int) $host['id']);
        $v = ['kind' => in_array($p['kind'] ?? '', ['swag', 'npm', 'nginx', 'traefik', 'caddy', 'none'], true) ? $p['kind'] : 'none',
            'ctr' => mb_substr((string) ($p['ctr'] ?? ''), 0, 100), 'engine' => in_array($p['engine'] ?? '', ['podman', 'docker', 'host'], true) ? $p['engine'] : '',
            'conf' => mb_substr((string) ($p['conf'] ?? ''), 0, 200), 'hook' => !empty($p['hook']),
            'state' => in_array($p['state'] ?? '', ['ok', 'error', 'off', 'unsupported', 'pending'], true) ? $p['state'] : 'pending',
            'msg' => mb_substr((string) ($p['msg'] ?? ''), 0, 500), 'applied' => preg_replace('/[^a-f0-9]/', '', (string) ($p['applied'] ?? '')),
            'sites' => array_values($sites), 'at' => time()];
        q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'proxy', ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
            [$host['id'], json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        // In het logboek als de stand verandert (doorgevoerd, fout, uitgezet)
        if (!$old || $old['state'] !== $v['state'] || ($v['state'] === 'ok' && ($old['applied'] ?? '') !== $v['applied'])) {
            $what = ['ok' => t('Reverse proxy: beleid doorgevoerd'), 'error' => t('Reverse proxy: doorvoeren mislukt'), 'off' => t('Reverse proxy: bescherming uit'),
                'unsupported' => t('Reverse proxy: niet ondersteund'), 'pending' => t('Reverse proxy: wacht')][$v['state']];
            ActionLog::add((int) $host['id'], 'agent', 'proxy', $what, $v['msg'], $v['state'] === 'error' ? false : ($v['state'] === 'ok' ? true : null));
        }
    }

    /** Het laatste rapport van de agent, of null */
    public static function report(int $hostId): ?array
    {
        $v = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'proxy'", [$hostId])->fetchColumn(), true);
        return is_array($v) ? $v : null;
    }

    // ------------------------------------------------------------------ beleid per site

    /** Standaardbeleid voor een site die nog niet is ingesteld (uit de beheerportal, en wat bij de app hoort) */
    public static function defaults(string $site, string $up = ''): array
    {
        $hay = strtolower($site . ' ' . $up);
        $lan = setting('proxy_def_lan', '1') === '1';
        $mode = 'off';
        $paths = '';
        if ($lan) {
            if (preg_match(self::ADMIN_APPS, $hay)) {
                $mode = 'site';
            } else {
                foreach (self::APP_PATHS as $app => $p) {
                    if (str_contains($hay, $app)) {
                        $mode = 'paths';
                        $paths = $p;
                        break;
                    }
                }
            }
        }
        $rate = (string) setting('proxy_def_rate', 'normal');
        return ['enabled' => true, 'lan_mode' => $mode, 'lan_paths' => $paths, 'rate' => in_array($rate, self::RATES, true) ? $rate : 'normal',
            'bots' => setting('proxy_def_bots', '1') === '1', 'custom' => false];
    }

    /** Alle sites die de agent vond, met het beleid (eigen instelling of de standaard) */
    public static function sites(int $hostId, ?array $rep = null): array
    {
        $rep ??= self::report($hostId);
        $own = [];
        foreach (q('SELECT * FROM proxy_sites WHERE host_id = ?', [$hostId]) as $r) {
            $own[$r['site']] = $r;
        }
        $out = [];
        foreach ((array) ($rep['sites'] ?? []) as $s) {
            $d = self::defaults($s['name'], (string) ($s['up'] ?? '') . ' ' . (string) ($s['file'] ?? ''));
            if (isset($own[$s['name']])) {
                $o = $own[$s['name']];
                $d = ['enabled' => (bool) $o['enabled'], 'lan_mode' => $o['lan_mode'], 'lan_paths' => $o['lan_paths'], 'rate' => $o['rate'], 'bots' => (bool) $o['bots'],
                    'custom' => true, 'updated_by' => $o['updated_by'], 'updated_at' => $o['updated_at']];
            }
            $out[] = ['site' => $s['name'], 'names' => $s['names'], 'up' => $s['up'], 'file' => $s['file'], 'code' => $s['code'] ?? 0] + $d;
        }
        return $out;
    }

    /** Beleid van één site opslaan. @return string|null fout */
    public static function saveSite(int $hostId, array $in, string $by): ?string
    {
        $site = strtolower(trim((string) ($in['site'] ?? '')));
        if (!self::validSite($site)) {
            return t('Ongeldige site');
        }
        $mode = (string) ($in['lan_mode'] ?? 'off');
        $rate = (string) ($in['rate'] ?? 'normal');
        if (!in_array($mode, self::LAN_MODES, true) || !in_array($rate, self::RATES, true)) {
            return t('Ongeldige keuze');
        }
        $paths = self::cleanPaths((string) ($in['lan_paths'] ?? ''));
        if ($paths === null) {
            return t('Ongeldig pad: gebruik paden zoals /admin, gescheiden door een komma (zonder spaties of ..)');
        }
        if ($mode === 'paths' && $paths === '') {
            return t('Vul minstens één adminpad in, of kies "hele site" of "uit"');
        }
        q('INSERT INTO proxy_sites (host_id, site, enabled, lan_mode, lan_paths, rate, bots, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), lan_mode = VALUES(lan_mode), lan_paths = VALUES(lan_paths), rate = VALUES(rate), bots = VALUES(bots),
            updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$hostId, $site, empty($in['enabled']) ? 0 : 1, $mode, $paths, $rate, empty($in['bots']) ? 0 : 1, mb_substr($by, 0, 100)]);
        ActionLog::by($by, $hostId, 'proxy', t('Reverse proxy: beleid van een site gewijzigd'), $site);
        return null;
    }

    /** Terug naar de standaard (de eigen instelling weg) */
    public static function resetSite(int $hostId, string $site, string $by): void
    {
        q('DELETE FROM proxy_sites WHERE host_id = ? AND site = ?', [$hostId, $site]);
        ActionLog::by($by, $hostId, 'proxy', t('Reverse proxy: site terug naar de standaard'), $site);
    }

    public static function setRole(int $hostId, bool $on, string $by): ?string
    {
        if (!q("SELECT 1 FROM hosts WHERE id = ? AND mode = 'agent'", [$hostId])->fetchColumn()) {
            return t('Alleen een server met de agent kan de reverse proxy zijn');
        }
        q('UPDATE hosts SET proxy_role = ? WHERE id = ?', [$on ? 1 : 0, $hostId]);
        ActionLog::by($by, $hostId, 'proxy', $on ? t('Reverse proxy: rol aangezet') : t('Reverse proxy: rol uitgezet'), '');
        return null;
    }

    /** Voor de pagina's: de servers met de rol (of die op een proxy lijken) en per server de sites */
    public static function overview(): array
    {
        $servers = [];
        foreach (q("SELECT id, name, proxy_role, agent_version FROM hosts WHERE mode = 'agent' ORDER BY name") as $h) {
            $rep = self::report((int) $h['id']);
            $looks = $rep && ($rep['kind'] ?? 'none') !== 'none';
            if (!(int) $h['proxy_role'] && !$looks) {
                $servers[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'role' => false, 'looks' => false, 'agent_ok' => self::agentOk($h['agent_version'])];
                continue;
            }
            $sites = self::sites((int) $h['id'], $rep);
            $servers[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'role' => (bool) $h['proxy_role'], 'looks' => $looks, 'agent_ok' => self::agentOk($h['agent_version']),
                'report' => $rep, 'sites' => $sites, 'in_sync' => $rep && ($rep['state'] ?? '') === 'ok' && ($rep['applied'] ?? '') === self::hash((int) $h['id'])];
        }
        return ['on' => self::enabled(), 'lan' => self::lanNets(), 'servers' => $servers,
            'defaults' => ['lan' => setting('proxy_def_lan', '1') === '1', 'rate' => setting('proxy_def_rate', 'normal'), 'bots' => setting('proxy_def_bots', '1') === '1']];
    }

    /** Kenmerk van het beleid zoals de agent het ook berekent (sha1 van de regels, eerste 12 tekens) */
    public static function hash(int $hostId): string
    {
        $lines = array_values(array_filter(self::agentLines($hostId), static fn($l) => !str_starts_with($l, "0\tproxy\t")));
        return substr(sha1(implode("\n", $lines) . "\n"), 0, 12);
    }

    public static function agentOk(?string $v): bool
    {
        return $v !== null && $v !== '' && version_compare($v, '1.97.0', '>=');
    }
}
