<?php

declare(strict_types=1);

/**
 * 1.258.0 (agent 1.100.0): virtual patching op de reverse proxy. Een pad met een bekend lek (of dat er nooit open hoort te
 * staan, zoals /.env of /.git) zet je tijdelijk dicht: iedereen buiten je eigen netwerk krijgt 403, ook als de app zelf nog
 * niet is bijgewerkt. Per site of voor alle sites, met een verloopdatum (of zonder). RzSense stelt paden voor uit wat de WAF
 * zag (misbruik van bekende lekken) en uit een lijst met paden die op een webserver nooit van buiten bereikbaar horen te zijn.
 */
final class VPatch
{
    /** Paden die nooit van buiten open horen te staan (voor alle sites) */
    public const COMMON = [
        '/.env' => 'Omgevingsbestand met wachtwoorden en sleutels',
        '/.git' => 'Broncode en geschiedenis van de site',
        '/.svn' => 'Broncode (Subversion)',
        '/.aws' => 'Cloudsleutels',
        '/.DS_Store' => 'Mapinhoud (macOS)',
        '/vendor/phpunit' => 'PHPUnit eval-stdin.php (CVE-2017-9841), veel misbruikt',
        '/server-status' => 'Interne status van de webserver',
    ];
    /** Per app: paden die bij die app bekend misbruikt worden (en die je normaal niet nodig hebt) */
    public const APPS = [
        'wordpress' => ['/xmlrpc.php' => 'XML-RPC van WordPress: wachtwoorden raden en DDoS', '/wp-content/debug.log' => 'Foutlog van WordPress'],
        'blog' => ['/xmlrpc.php' => 'XML-RPC van WordPress: wachtwoorden raden en DDoS'],
        'grafana' => ['/public/plugins/' => 'Path traversal in plugins (CVE-2021-43798)'],
        'gitlab' => ['/uploads/user' => 'Uploads zonder inloggen (CVE-2021-22205)'],
        'confluence' => ['/pages/createpage-entervariables.action' => 'OGNL-injectie (CVE-2022-26134)'],
        'jenkins' => ['/script' => 'Scriptconsole van Jenkins'],
        'spring' => ['/actuator' => 'Interne endpoints van Spring Boot'],
    ];
    public const DAYS = [7, 30, 90, 0];   // 0 = zonder einde

    public static function validPath(string $p): bool
    {
        return (bool) preg_match('#^/[A-Za-z0-9._~/-]{1,120}$#', $p) && !str_contains($p, '..');
    }

    /** @return list<string> regels voor de agent: per site (of *) de actieve paden */
    public static function agentLines(int $hostId): array
    {
        $by = [];
        foreach (q('SELECT site, path FROM proxy_vpatch WHERE host_id = ? AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY site, path', [$hostId]) as $r) {
            if (self::validPath((string) $r['path'])) {
                $by[$r['site']][] = $r['path'];
            }
        }
        $out = [];
        foreach ($by as $site => $paths) {
            foreach (array_chunk($paths, 20) as $chunk) {
                $out[] = implode("\t", [0, 'proxyblock', $site, implode(',', $chunk)]);
            }
        }
        return $out;
    }

    /** Dichtzetten. $days 0 = zonder einde. @return string|null fout */
    public static function add(int $hostId, string $site, string $path, int $days, string $reason, string $source, string $by): ?string
    {
        $site = strtolower(trim($site));
        $path = rtrim(trim($path), '/') ?: '/';
        if ($site !== '*' && !ProxyGuard::validSite($site)) {
            return t('Ongeldige site');
        }
        if (!self::validPath($path) || $path === '/') {
            return t('Ongeldig pad: bv. /xmlrpc.php of /vendor/phpunit (geen spaties of ..)');
        }
        if (!in_array($days, self::DAYS, true)) {
            $days = 30;
        }
        if (!q('SELECT proxy_role FROM hosts WHERE id = ?', [$hostId])->fetchColumn()) {
            return t('Deze server is niet de reverse proxy');
        }
        if ((int) q('SELECT COUNT(*) FROM proxy_vpatch WHERE host_id = ?', [$hostId])->fetchColumn() >= 200) {
            return t('Hooguit 200 dichtgezette paden per server');
        }
        q('INSERT INTO proxy_vpatch (host_id, site, path, reason, source, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at), created_by = VALUES(created_by), created_at = VALUES(created_at)',
            [$hostId, $site, $path, mb_substr($reason, 0, 200), in_array($source, ['user', 'waf', 'known', 'ai'], true) ? $source : 'user',
                $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null, mb_substr($by, 0, 64)]);
        ActionLog::by($by, $hostId, 'proxy', t('Reverse proxy: pad dichtgezet'), ($site === '*' ? t('alle sites') : $site) . " $path" . ($days > 0 ? ' · ' . t('{n} dagen', ['n' => $days]) : ''));
        return null;
    }

    public static function remove(int $id, string $by): void
    {
        $r = q('SELECT * FROM proxy_vpatch WHERE id = ?', [$id])->fetch();
        if ($r) {
            q('DELETE FROM proxy_vpatch WHERE id = ?', [$id]);
            ActionLog::by($by, (int) $r['host_id'], 'proxy', t('Reverse proxy: pad weer open'), "{$r['site']} {$r['path']}");
        }
    }

    public static function extend(int $id, int $days, string $by): void
    {
        $r = q('SELECT * FROM proxy_vpatch WHERE id = ?', [$id])->fetch();
        if ($r && in_array($days, self::DAYS, true)) {
            q('UPDATE proxy_vpatch SET expires_at = ? WHERE id = ?', [$days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null, $id]);
            ActionLog::by($by, (int) $r['host_id'], 'proxy', t('Reverse proxy: dichtgezet pad verlengd'), "{$r['site']} {$r['path']}");
        }
    }

    /** Is dit pad (voor deze site) al dicht? Een korter pad dekt een langer (prefix) */
    public static function covered(array $active, string $site, string $path): bool
    {
        foreach ($active as $a) {
            if (!$a['expired'] && ($a['site'] === '*' || $a['site'] === $site) && stripos($path, $a['path']) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Voor de pagina: de dichtgezette paden en voorstellen (uit de WAF en de bekende lijst) */
    public static function overview(int $hostId, array $sites): array
    {
        $active = array_map(static fn($r) => ['id' => (int) $r['id'], 'site' => $r['site'], 'path' => $r['path'], 'reason' => $r['reason'], 'source' => $r['source'],
            'expires' => $r['expires_at'], 'expired' => $r['expires_at'] !== null && strtotime((string) $r['expires_at']) <= time(), 'by' => $r['created_by']],
            q('SELECT * FROM proxy_vpatch WHERE host_id = ? ORDER BY site, path', [$hostId])->fetchAll());
        $sugg = [];
        // 1. Wat de WAF zag: misbruik van bekende lekken (per site het pad zonder vraag)
        foreach (q('SELECT site, uri, rules, blocked, COUNT(*) AS n, COUNT(DISTINCT ip) AS ips FROM waf_events WHERE host_id = ? AND vpatch = 1 AND at >= NOW() - INTERVAL 30 DAY
                    GROUP BY site, uri, rules, blocked ORDER BY n DESC LIMIT 100', [$hostId]) as $r) {
            $path = (string) preg_replace('/[?#].*$/', '', (string) $r['uri']);
            $path = rtrim($path, '/');
            if (!self::validPath($path) || self::covered($active, (string) $r['site'], $path) || isset($sugg[$r['site'] . '|' . $path])) {
                continue;
            }
            $rule = explode(' ', (string) $r['rules'])[0] ?? '';
            $sugg[$r['site'] . '|' . $path] = ['site' => $r['site'], 'path' => $path, 'source' => 'waf', 'n' => (int) $r['n'], 'ips' => (int) $r['ips'], 'blocked' => (bool) $r['blocked'],
                'reason' => t('WAF zag misbruik van een bekend lek ({r})', ['r' => $rule])];
        }
        // 2. Paden die nooit van buiten open horen te staan (alle sites)
        foreach (self::COMMON as $p => $why) {
            if (!self::covered($active, '*', $p)) {
                $sugg["*|$p"] = ['site' => '*', 'path' => $p, 'source' => 'known', 'n' => 0, 'ips' => 0, 'blocked' => false, 'reason' => t($why)];
            }
        }
        // 3. Per app (herkend aan de naam of de app erachter)
        foreach ($sites as $s) {
            $hay = strtolower($s['site'] . ' ' . ($s['up'] ?? ''));
            foreach (self::APPS as $app => $paths) {
                if (!str_contains($hay, $app)) {
                    continue;
                }
                foreach ($paths as $p => $why) {
                    if (!self::covered($active, $s['site'], $p) && !isset($sugg[$s['site'] . "|$p"])) {
                        $sugg[$s['site'] . "|$p"] = ['site' => $s['site'], 'path' => $p, 'source' => 'known', 'n' => 0, 'ips' => 0, 'blocked' => false, 'reason' => t($why)];
                    }
                }
            }
        }
        return ['active' => $active, 'suggest' => array_values(array_slice($sugg, 0, 60))];
    }

    public static function purge(): void
    {
        q('DELETE FROM proxy_vpatch WHERE expires_at IS NOT NULL AND expires_at < NOW() - INTERVAL 30 DAY');
    }
}
