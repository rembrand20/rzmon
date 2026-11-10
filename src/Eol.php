<?php
declare(strict_types=1);

/**
 * End-of-life (1.227.0): welk besturingssysteem en welke software op je servers krijgt geen updates meer, of binnenkort niet?
 * - Uit de softwarelijst die de agent al stuurt (vuln_inventory): het besturingssysteem (Rocky, Alma, RHEL, Debian,
 *   Ubuntu, Alpine, Fedora, Proxmox VE …) en bekende software (PHP, MariaDB, MySQL, PostgreSQL, nginx, Node.js,
 *   Python, Docker, Redis, OpenSSL).
 * - De einddatums komen van endoflife.date (openbaar, alleen productnamen gaan de deur uit), eens per dag, in eol_cache.
 * - Oordeel: buiten ondersteuning = critical (besturingssysteem) of warning (software van de leverancier zelf, zoals
 *   docker-ce); binnen 90 dagen = warning. Software uit de pakketbron van je distributie krijgt vaak nog beveiligingsupdates
 *   van de distributie, ook als de maker zelf stopt: dan alleen ter info.
 * - Een info-melding (Systeemmeldingen → End-of-life) 90 en 30 dagen vooraf en op de dag zelf, één keer per stap.
 * Aan/uit: eol_on (Instellingen → Overzicht → Functies, of Beveiliging → Kwetsbaarheden).
 */
final class Eol
{
    private const API = 'https://endoflife.date/api/';
    private const SOON = 90;

    public static function enabled(): bool
    {
        return setting('eol_on', '1') === '1';
    }

    /** Versie zonder epoch en distributie-achtervoegsel: "2:8.2.7-1~deb12u1" → [8, 2, 7] */
    public static function nums(string $v): array
    {
        $v = (string) preg_replace('/^\d+:/', '', trim($v));
        preg_match_all('/\d+/', (string) preg_replace('/[-+~].*$/', '', $v), $m);
        return array_map('intval', array_slice($m[0], 0, 3));
    }

    /**
     * Wat er op een server staat dat RzSense kent.
     * @return list<array{product: string, label: string, cycle: string, version: string, kind: string, vendor: bool}>
     */
    public static function detect(array $inv): array
    {
        $out = [];
        $id = strtolower((string) ($inv['os_id'] ?? ''));
        $ver = (string) ($inv['os_version'] ?? '');
        $n = self::nums($ver);
        $osName = (string) ($inv['os_name'] ?? '');
        $os = match (true) {
            $id === 'rocky' => ['rocky-linux', 'Rocky Linux', (string) ($n[0] ?? '')],
            $id === 'almalinux' => ['almalinux', 'AlmaLinux', (string) ($n[0] ?? '')],
            $id === 'rhel' => ['rhel', 'Red Hat Enterprise Linux', (string) ($n[0] ?? '')],
            $id === 'centos' => stripos($osName, 'stream') !== false ? ['centos-stream', 'CentOS Stream', (string) ($n[0] ?? '')] : ['centos', 'CentOS', (string) ($n[0] ?? '')],
            $id === 'ol' => ['oracle-linux', 'Oracle Linux', (string) ($n[0] ?? '')],
            $id === 'fedora' => ['fedora', 'Fedora', (string) ($n[0] ?? '')],
            in_array($id, ['debian', 'raspbian'], true) => ['debian', $id === 'raspbian' ? 'Raspberry Pi OS (Debian)' : 'Debian', (string) ($n[0] ?? '')],
            $id === 'ubuntu' => ['ubuntu', 'Ubuntu', isset($n[1]) ? sprintf('%02d.%02d', $n[0], $n[1]) : ''],
            $id === 'alpine' => ['alpine', 'Alpine Linux', isset($n[1]) ? "$n[0].$n[1]" : ''],
            $id === 'opensuse-leap' => ['opensuse', 'openSUSE Leap', isset($n[1]) ? "$n[0].$n[1]" : ''],
            $id === 'amzn' => ['amazon-linux', 'Amazon Linux', (string) ($n[0] ?? '')],
            default => null,
        };
        if ($os && $os[2] !== '') {
            $out[] = ['product' => $os[0], 'label' => $os[1], 'cycle' => $os[2], 'version' => $ver, 'kind' => 'os', 'vendor' => true];
        }
        $seen = [];
        foreach ((array) json_decode((string) ($inv['packages'] ?? '[]'), true) as $p) {
            if (!is_array($p) || !isset($p[0], $p[1])) {
                continue;
            }
            [$name, $pv] = [strtolower((string) $p[0]), (string) $p[1]];
            $v = self::nums($pv);
            $pm = [];
            $isPhp = (bool) preg_match('/^php(\d\.\d)?(-cli|-fpm|-common)?$/', $name, $pm);
            $hit = match (true) {
                $name === 'pve-manager' => ['proxmox-ve', 'Proxmox VE', isset($v[0]) ? (string) $v[0] : '', 'os', true],
                $isPhp => ['php', 'PHP', !empty($pm[1]) ? $pm[1] : (isset($v[1]) ? "$v[0].$v[1]" : ''), 'pkg', false],
                (bool) preg_match('/^mariadb-server(-core)?(-\d+\.\d+)?$/', $name) => ['mariadb', 'MariaDB', isset($v[1]) ? "$v[0].$v[1]" : '', 'pkg', false],
                in_array($name, ['mysql-server', 'mysql-community-server', 'mysql-server-core'], true) => ['mysql', 'MySQL', isset($v[1]) ? "$v[0].$v[1]" : '', 'pkg', false],
                (bool) preg_match('/^postgresql(\d+)?(-server)?$|^postgresql-(\d+)$/', $name) => ['postgresql', 'PostgreSQL', isset($v[0]) ? (string) ($v[0] >= 10 ? $v[0] : "$v[0]." . ($v[1] ?? 0)) : '', 'pkg', false],
                $name === 'nginx' => ['nginx', 'nginx', isset($v[1]) ? "$v[0].$v[1]" : '', 'pkg', false],
                $name === 'nodejs' => ['nodejs', 'Node.js', isset($v[0]) ? (string) $v[0] : '', 'pkg', !str_contains($pv, 'deb') && !str_contains($pv, 'el')],
                (bool) preg_match('/^python3(\.\d+)?$/', $name) => ['python', 'Python', isset($v[1]) ? "$v[0].$v[1]" : '', 'pkg', false],
                $name === 'docker-ce' => ['docker-engine', 'Docker Engine', isset($v[0]) ? (string) $v[0] : '', 'pkg', true],
                in_array($name, ['redis-server', 'redis'], true) => ['redis', 'Redis', isset($v[1]) ? "$v[0].$v[1]" : '', 'pkg', false],
                $name === 'openssl' => ['openssl', 'OpenSSL', isset($v[1]) ? ($v[0] === 1 && $v[1] === 1 ? '1.1.1' : "$v[0].$v[1]") : '', 'pkg', false],
                default => null,
            };
            if ($hit && $hit[2] !== '' && !isset($seen[$hit[0]])) {
                $seen[$hit[0]] = true;
                $out[] = ['product' => $hit[0], 'label' => $hit[1], 'cycle' => $hit[2], 'version' => $pv, 'kind' => $hit[3], 'vendor' => $hit[4]];
            }
        }
        return $out;
    }

    /** De bewaarde gegevens van endoflife.date: product => ['at' => tijd, 'cycles' => [...]] */
    private static function cache(): array
    {
        return (array) json_decode((string) setting('eol_cache', '{}'), true);
    }

    /** De cycli van een product ophalen bij endoflife.date (alleen de velden die RzSense gebruikt) */
    private static function fetch(string $product): ?array
    {
        if (!preg_match('/^[a-z0-9-]{2,40}$/', $product)) {
            return null;
        }
        $ch = curl_init(self::API . $product . '.json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = is_string($body) && $code === 200 ? json_decode($body, true) : null;
        if (!is_array($j)) {
            return null;
        }
        $out = [];
        foreach (array_slice($j, 0, 200) as $c) {
            if (is_array($c) && isset($c['cycle'])) {
                $out[] = ['cycle' => (string) $c['cycle'], 'eol' => $c['eol'] ?? false, 'latest' => (string) ($c['latest'] ?? ''), 'lts' => $c['lts'] ?? false,
                    'support' => $c['support'] ?? null, 'release' => (string) ($c['releaseDate'] ?? '')];
            }
        }
        return $out;
    }

    /** Eens per dag (cron): de gebruikte producten bijwerken, daarna meldingen */
    public static function daily(): void
    {
        if (!self::enabled()) {
            return;
        }
        $last = (int) setting('eol_last', '0');
        if ($last > time() - 86400 + 600) {
            return;
        }
        save_setting('eol_last', (string) time());
        $cache = self::cache();
        $used = [];
        foreach (self::inventories() as $inv) {
            foreach (self::detect($inv) as $d) {
                $used[$d['product']] = true;
            }
        }
        $err = '';
        foreach (array_slice(array_keys($used), 0, 40) as $p) {
            if ((int) ($cache[$p]['at'] ?? 0) > time() - 72000) {   // al vandaag opgehaald (20 uur)
                continue;
            }
            $c = self::fetch($p);
            if ($c === null) {
                $err = t('endoflife.date niet bereikbaar voor {p}', ['p' => $p]);
                continue;
            }
            $cache[$p] = ['at' => time(), 'cycles' => $c];
        }
        save_setting('eol_cache', json_encode($cache, JSON_UNESCAPED_SLASHES));
        save_setting('eol_state', json_encode(['ok' => $err === '' ? time() : (json_decode((string) setting('eol_state', '{}'), true)['ok'] ?? null), 'try' => time(), 'error' => $err]));
        self::notify();
    }

    private static function inventories(): array
    {
        return q('SELECT i.host_id, h.name, h.info_agent, i.os_id, i.os_version, i.os_name, i.packages FROM vuln_inventory i JOIN hosts h ON h.id = i.host_id WHERE h.enabled = 1')->fetchAll();
    }

    /**
     * Het oordeel voor één product en cyclus.
     * @return array{eol: ?string, days: ?int, state: string, latest: string, successor: string, lts: bool, known: bool}
     */
    public static function judge(array $d, ?array $cycles, ?int $now = null): array
    {
        $now ??= time();
        $res = ['eol' => null, 'days' => null, 'state' => 'unknown', 'latest' => '', 'successor' => '', 'lts' => false, 'known' => false];
        if (!$cycles) {
            return $res;
        }
        $hit = null;
        foreach ($cycles as $c) {
            if ((string) $c['cycle'] === $d['cycle']) {
                $hit = $c;
                break;
            }
        }
        if (!$hit) {   // Debian "12" vs. cyclus "12", Proxmox "8" vs. "8.2": de eerste die met de major begint
            $major = explode('.', $d['cycle'])[0];
            foreach ($cycles as $c) {
                if (explode('.', (string) $c['cycle'])[0] === $major) {
                    $hit = $c;
                    break;
                }
            }
        }
        // De nieuwste nog ondersteunde cyclus: de opvolger
        foreach ($cycles as $c) {
            $e = $c['eol'];
            if ($e === false || (is_string($e) && strtotime($e) > $now)) {
                $res['successor'] = (string) $c['cycle'];
                break;
            }
        }
        if (!$hit) {
            return $res;
        }
        $res['known'] = true;
        $res['latest'] = (string) ($hit['latest'] ?? '');
        $res['lts'] = !empty($hit['lts']);
        $e = $hit['eol'];
        if ($e === true) {
            $res['state'] = 'eol';
        } elseif (is_string($e) && ($t = strtotime($e)) !== false) {
            $res['eol'] = date('Y-m-d', $t);
            $res['days'] = (int) floor(($t - $now) / 86400);
            $res['state'] = $res['days'] < 0 ? 'eol' : ($res['days'] <= self::SOON ? 'soon' : 'ok');
        } else {
            $res['state'] = 'ok';
        }
        if ($res['successor'] === (string) $hit['cycle']) {
            $res['successor'] = '';
        }
        return $res;
    }

    /** Hoe erg: crit, warn of info (zie de uitleg bovenaan) */
    public static function level(array $d, string $state): string
    {
        if ($state === 'eol') {
            return $d['kind'] === 'os' ? 'crit' : ($d['vendor'] ? 'warn' : 'info');
        }
        if ($state === 'soon') {
            return $d['kind'] === 'os' || $d['vendor'] ? 'warn' : 'info';
        }
        return 'ok';
    }

    /** Alles voor de pagina Kwetsbaarheden → Verouderd */
    public static function overview(): array
    {
        $cache = self::cache();
        $rows = [];
        foreach (self::inventories() as $inv) {
            foreach (self::detect($inv) as $d) {
                $j = self::judge($d, $cache[$d['product']]['cycles'] ?? null);
                $rows[] = ['host_id' => (int) $inv['host_id'], 'host' => (string) $inv['name']] + $d + $j + ['level' => self::level($d, $j['state'])];
            }
        }
        $rank = ['crit' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($rows, static fn($a, $b) => [$rank[$a['level']], $a['days'] ?? 99999, $a['host']] <=> [$rank[$b['level']], $b['days'] ?? 99999, $b['host']]);
        return ['on' => self::enabled(), 'rows' => $rows, 'state' => json_decode((string) setting('eol_state', '{}'), true) ?: [],
            'counts' => array_count_values(array_column($rows, 'level'))];
    }

    /** Info-melding 90 en 30 dagen vooraf en op de dag zelf (één keer per stap per server, product en cyclus) */
    private static function notify(): void
    {
        $done = (array) json_decode((string) setting('eol_notified', '{}'), true);
        $cache = self::cache();
        $keep = [];
        foreach (self::inventories() as $inv) {
            foreach (self::detect($inv) as $d) {
                $j = self::judge($d, $cache[$d['product']]['cycles'] ?? null);
                if (self::level($d, $j['state']) === 'info' || !$j['known']) {
                    continue;
                }
                $stage = $j['state'] === 'eol' ? 'eol' : ($j['days'] !== null && $j['days'] <= 30 ? '30' : ($j['state'] === 'soon' ? '90' : ''));
                $key = $inv['host_id'] . '|' . $d['product'] . '|' . $d['cycle'];
                if ($stage === '') {
                    continue;
                }
                $keep[$key] = $done[$key] ?? '';
                if (($done[$key] ?? '') === $stage) {
                    continue;
                }
                $keep[$key] = $stage;
                $what = $d['label'] . ' ' . $d['cycle'];
                $succ = $j['successor'] !== '' ? ' · ' . t('opvolger: {s}', ['s' => $d['label'] . ' ' . $j['successor']]) : '';
                Monitor::sysInfo('eol', (int) $inv['host_id'], (string) $inv['name'], ($stage === 'eol' ? '⛔ ' : '⏳ ') . t('End-of-life: {w} op {h}', ['w' => $what, 'h' => $inv['name']]),
                    fn() => ($stage === 'eol'
                        ? t('{w} op {h} krijgt geen updates meer (sinds {d}).', ['w' => $what, 'h' => $inv['name'], 'd' => $j['eol'] ?? '?'])
                        : t('{w} op {h} krijgt over {n} dagen geen updates meer ({d}).', ['w' => $what, 'h' => $inv['name'], 'n' => $j['days'], 'd' => $j['eol']])) . $succ);
                ActionLog::add((int) $inv['host_id'], 'rzsense', 'patch', t('End-of-life: {w}', ['w' => $what]), $stage === 'eol' ? t('geen updates meer') : t('nog {n} dagen', ['n' => $j['days']]), null);
            }
        }
        save_setting('eol_notified', json_encode($keep, JSON_UNESCAPED_SLASHES));
    }
}
