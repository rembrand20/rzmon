<?php
declare(strict_types=1);

/**
 * 1.268.0: de route van een aanval, voor het venster van een incident.
 *
 * Per station (firewall → proxy → WAF → Suricata → app → server erna, of firewall → inloggen → server erna) wat er met dít IP
 * gebeurde, uit wat RzSense al bewaart: ids_blocks, de proxyregels in wpath of het bewijs, waf_events, Suricata-incidenten
 * en incidenten op dezelfde server in het uur erna. Elk station krijgt een stand:
 *   stop = hier tegengehouden · pass = doorgelaten · warn = deels / opgemerkt · bad = gelukt of verdacht
 *   skip = niet bereikt (eerder tegengehouden) · na = geen gegevens of staat uit
 */
final class IncidentRoute
{
    private const WEB = ['web_attack', 'web_payload', 'web_cve', 'suricata'];
    private const LOGIN = ['bruteforce', 'breakin', 'rootlogin', 'newip', 'ssh_login'];
    private const OUT = ['outbound', 'out_bad', 'out_country', 'ti_ip', 'dns_bad', 'dns_nrd'];
    /** Wat er op een server gebeurt als een aanval lukte */
    private const AFTER = ['webshell', 'revshell', 'edr', 'app_new', 'out_bad', 'ransom', 'ransom_mass', 'user_new', 'uid0',
        'group_add', 'malware', 'ti_malware', 'ioc'];
    private const AFTER_MIN = 60;

    private static function tm(string $dt): string
    {
        return date('d-m H:i', (int) strtotime($dt));
    }

    /** @return array{kind:string,stations:list<array<string,mixed>>,ip:string}|null */
    public static function forIncident(array $inc, ?array $outcome = null): ?array
    {
        $ip = (string) ($inc['ip'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $type = (string) ($inc['type'] ?? '');
        $kind = in_array($type, self::WEB, true) || str_starts_with((string) ($inc['dkey'] ?? ''), 'swag|') ? 'web'
            : (in_array($type, self::LOGIN, true) || $type === 'decoy_user' ? 'login'
            : (in_array($type, self::OUT, true) || ($inc['dir'] ?? '') === 'out' ? 'out' : 'in'));
        $from = date('Y-m-d H:i:s', strtotime((string) $inc['created_at']) - 600);
        $to = date('Y-m-d H:i:s', strtotime((string) ($inc['last_seen'] ?: $inc['created_at'])) + 600);

        $st = [];
        if ($kind !== 'out') {
            $st[] = self::firewall($inc, $ip);
        }
        if ($kind === 'web') {
            [$proxy, $app] = self::proxyAndApp($inc);
            $st[] = $proxy;
            $st[] = self::waf($ip, $from, $to);
            $st[] = self::suricata($inc, $ip, $from, $to);
            $st[] = $app;
        } elseif ($kind === 'login') {
            $st[] = self::login($inc, $ip);
        } elseif ($kind === 'out') {
            $st[] = ['k' => 'proc', 'icon' => '🖥️', 'label' => t('Server'), 'state' => 'pass',
                'text' => t('Een proces op {host} maakte contact', ['host' => (string) ($inc['host_name'] ?? '')]), 'lines' => []];
            $st[] = self::destination($inc, $ip, $outcome);
        } else {
            $st[] = self::target($inc, $outcome);
        }
        $st[] = self::after($inc);
        // Wat na een station komt dat alles tegenhield, is niet bereikt
        $stopped = false;
        foreach ($st as &$s) {
            if ($stopped && in_array($s['state'], ['pass', 'na'], true)) {
                $s['state'] = 'skip';
                $s['text'] = t('Niet bereikt: eerder tegengehouden');
            }
            $stopped = $stopped || (!empty($s['all']) && $s['state'] === 'stop');
        }
        unset($s);
        return ['kind' => $kind, 'ip' => $ip, 'stations' => $st];
    }

    private static function firewall(array $inc, string $ip): array
    {
        $s = ['k' => 'fw', 'icon' => '🧱', 'label' => t('Firewall'), 'lines' => []];
        if (Ids::protectedIp($ip)) {
            return $s + ['state' => 'pass', 'text' => t('Je eigen netwerk, de whitelist of een eigen server: wordt nooit geblokkeerd')];
        }
        $at = strtotime((string) $inc['created_at']);
        $b = q('SELECT created_at, reason, until, removed_at FROM ids_blocks WHERE ip = ? ORDER BY created_at LIMIT 1', [$ip])->fetch();
        $list = IpIntel::bad($ip);
        if ($list !== null) {
            $s['lines'][] = t('Staat op de lijst {list} (bekende aanvallers)', ['list' => $list]);
        }
        if ($b && strtotime((string) $b['created_at']) <= $at - 60 && (!$b['removed_at'] || strtotime((string) $b['removed_at']) > $at)) {
            return ['state' => 'stop', 'all' => true, 'text' => t('Stond al geblokkeerd sinds {t}', ['t' => self::tm((string) $b['created_at'])])] + $s;
        }
        if ($b) {
            $s['lines'][] = t('Geblokkeerd om {t}: {why}', ['t' => self::tm((string) $b['created_at']), 'why' => (string) $b['reason']])
                . ($b['removed_at'] ? ' · ' . t('weer vrijgegeven') : ($b['until'] ? ' · ' . t('tot {t}', ['t' => self::tm((string) $b['until'])]) : ' · ' . t('permanent')));
            return ['state' => 'warn', 'text' => t('Doorgelaten; daarna geblokkeerd')] + $s;
        }
        return ['state' => 'pass', 'text' => t('Doorgelaten: het IP was niet geblokkeerd')] + $s;
    }

    /** Proxyregels en de app: uit het pad (webaanval in het proxylog) of uit het bewijs (verzoeken met hun statuscode) */
    private static function proxyAndApp(array $inc): array
    {
        $proxy = ['k' => 'proxy', 'icon' => '🔀', 'label' => t('Proxy-regels'), 'lines' => []];
        $app = ['k' => 'app', 'icon' => '📦', 'label' => t('App'), 'lines' => []];
        $codes = [];
        $w = WebLog::path($inc);
        if ($w && $w['sites']) {
            $refused = 0; $fwd = 0; $ok = 0;
            foreach ($w['sites'] as $x) {
                if ($x['site'] === '' || $x['up'] === '') {
                    $refused += $x['n'];
                    $proxy['lines'][] = t('{n}× zonder bekende site: niet doorgestuurd', ['n' => $x['n']]);
                } else {
                    $fwd += $x['n']; $ok += $x['ok'];
                    $app['lines'][] = t('{site} → {svc}: {n} verzoeken, {ok} met een gewoon antwoord',
                        ['site' => $x['site'], 'svc' => $x['svc'] ?: $x['up'], 'n' => $x['n'], 'ok' => $x['ok']]);
                }
            }
            $total = $refused + $fwd;
        } else {
            foreach (q("SELECT data FROM ids_evidence WHERE incident_id = ? AND kind = 'web' ORDER BY id DESC LIMIT 20", [(int) $inc['id']]) as $r) {
                $ev = (json_decode((string) $r['data'], true) ?: [])['ev'] ?? [];
                if (isset($ev['status'])) {
                    $codes[] = (int) $ev['status'];
                    if (count($app['lines']) < 4) {
                        $app['lines'][] = trim((string) ($ev['method'] ?? '') . ' ' . mb_substr((string) ($ev['uri'] ?? ''), 0, 90)) . ' → ' . (int) $ev['status'];
                    }
                }
            }
            if (!$codes) {
                return [$proxy + ['state' => 'na', 'text' => t('Geen verzoeken bekend (bijvoorbeeld een melding van Suricata)')],
                    $app + ['state' => 'na', 'text' => t('Geen antwoorden bekend')]];
            }
            $total = count($codes);
            $refused = count(array_filter($codes, static fn($c) => in_array($c, [403, 429, 444], true)));
            $ok = count(array_filter($codes, static fn($c) => $c >= 200 && $c < 400));
            $fwd = $total - $refused;
            $cnt = array_count_values($codes);
            $proxy['lines'][] = t('Statuscodes: {list}', ['list' => implode(', ', array_map(static fn($c, $n) => "$c ×$n", array_keys($cnt), $cnt))]);
        }
        $proxy += $refused >= $total ? ['state' => 'stop', 'all' => true, 'text' => t('Alle {n} verzoeken geweigerd (403, 429 of 444)', ['n' => $total])]
            : ($refused > 0 ? ['state' => 'warn', 'text' => t('{a} van de {n} verzoeken geweigerd', ['a' => $refused, 'n' => $total])]
            : ['state' => 'pass', 'text' => t('Doorgestuurd naar de app')]);
        $app += $fwd <= 0 ? ['state' => 'skip', 'text' => t('Niet bereikt: eerder tegengehouden')]
            : ($ok > 0 ? ['state' => 'bad', 'text' => t('Mogelijk gelukt: {n}× een gewoon antwoord (2xx/3xx)', ['n' => $ok])]
            : ['state' => 'stop', 'text' => t('Alleen foutcodes (zoals 404 of 401): waarschijnlijk niet gelukt')]);
        return [$proxy, $app];
    }

    private static function waf(string $ip, string $from, string $to): array
    {
        $s = ['k' => 'waf', 'icon' => '🛡️', 'label' => t('WAF'), 'lines' => []];
        $r = q('SELECT COUNT(*) AS n, COALESCE(SUM(blocked), 0) AS b, MAX(rules) AS rule FROM waf_events WHERE ip = ? AND at BETWEEN ? AND ?', [$ip, $from, $to])->fetch();
        $n = (int) ($r['n'] ?? 0);
        if ($n === 0) {
            return $s + (Waf::enabled() ? ['state' => 'pass', 'text' => t('Geen treffer: de WAF liet het door')] : ['state' => 'na', 'text' => t('De WAF staat uit')]);
        }
        $s['lines'][] = t('Regel: {r}', ['r' => explode(' ', trim((string) $r['rule']))[0]]);
        $b = (int) $r['b'];
        return $s + ($b >= $n ? ['state' => 'stop', 'all' => true, 'text' => t('{n}× tegengehouden', ['n' => $n])]
            : ($b > 0 ? ['state' => 'warn', 'text' => t('{b} van de {n} tegengehouden', ['b' => $b, 'n' => $n])]
            : ['state' => 'warn', 'text' => t('{n}× gezien, alleen gemeld (detecteren)', ['n' => $n])]));
    }

    private static function suricata(array $inc, string $ip, string $from, string $to): array
    {
        $s = ['k' => 'nids', 'icon' => '👁', 'label' => 'Suricata', 'lines' => []];
        $rows = ($inc['type'] ?? '') === 'suricata' ? [$inc]
            : q("SELECT title, detail FROM ids_incidents WHERE type = 'suricata' AND ip = ? AND last_seen >= ? AND created_at <= ? ORDER BY id DESC LIMIT 5", [$ip, $from, $to])->fetchAll();
        if (!$rows) {
            return $s + (Nids::enabled() ? ['state' => 'pass', 'text' => t('Geen alert')] : ['state' => 'na', 'text' => t('Suricata staat uit')]);
        }
        $blocked = false;
        foreach ($rows as $r) {
            $s['lines'][] = (string) $r['title'];
            $blocked = $blocked || str_contains((string) $r['detail'], t('Suricata blokkeerde het'));
        }
        return $s + ($blocked ? ['state' => 'stop', 'text' => t('Suricata blokkeerde het')] : ['state' => 'warn', 'text' => t('{n} alert(s), doorgelaten', ['n' => count($rows)])]);
    }

    private static function login(array $inc, string $ip): array
    {
        $s = ['k' => 'login', 'icon' => '🔑', 'label' => t('Inloggen'), 'lines' => []];
        $type = (string) $inc['type'];
        if ($type === 'decoy_user') {
            return $s + ['state' => 'stop', 'text' => t('Lokaasaccount: dat bestaat niet, inloggen kan niet')];
        }
        $in = q("SELECT title, created_at FROM ids_incidents WHERE host_id = ? AND ip = ? AND type IN ('breakin', 'rootlogin', 'newip', 'ssh_login') AND created_at >= ? ORDER BY id LIMIT 1",
            [(int) $inc['host_id'], $ip, date('Y-m-d H:i:s', strtotime((string) $inc['created_at']) - 600)])->fetch();
        if ($type === 'bruteforce') {
            $s['lines'][] = t('{n}× gezien', ['n' => (int) ($inc['hits'] ?? 1)]);
            return $s + ($in ? ['state' => 'bad', 'text' => t('Gelukt: {t}', ['t' => (string) $in['title']])]
                : ['state' => 'stop', 'text' => t('Alleen mislukte pogingen: niet binnengekomen')]);
        }
        return $s + ['state' => $type === 'breakin' || $type === 'rootlogin' ? 'bad' : 'warn', 'text' => t('Ingelogd: {t}', ['t' => (string) $inc['title']])];
    }

    /** Een verbinding naar binnen die niet via de proxy liep (lokaaspoort, nieuwe poort …) */
    private static function target(array $inc, ?array $outcome): array
    {
        $port = (int) ($inc['dport'] ?? 0);
        $what = $port ? trim($port . '/' . ($inc['proto'] ?: 'tcp') . ' ' . ($inc['service'] ?? '')) : (string) $inc['title'];
        $k = $outcome['k'] ?? '';
        return ['k' => 'target', 'icon' => '🎯', 'label' => t('Doel'), 'lines' => [(string) $inc['title']]]
            + (in_array($k, ['blocked', 'stopped', 'isolated'], true) ? ['state' => 'stop', 'text' => t('{w}: tegengehouden', ['w' => $what])]
            : ($k === 'maybe' ? ['state' => 'bad', 'text' => t('{w}: mogelijk gelukt', ['w' => $what])]
            : ['state' => 'warn', 'text' => t('{w}: opgemerkt', ['w' => $what])]));
    }

    /** Uitgaand: het adres waar de server naartoe ging */
    private static function destination(array $inc, string $ip, ?array $outcome): array
    {
        $s = ['k' => 'dest', 'icon' => '🌍', 'label' => t('Bestemming'), 'lines' => []];
        $port = (int) ($inc['dport'] ?? 0);
        $s['lines'][] = $ip . ($port ? ':' . $port : '') . (!empty($inc['cc']) ? ' · ' . $inc['cc'] : '');
        if (($l = IpIntel::bad($ip)) !== null) {
            $s['lines'][] = t('Staat op de lijst {list} (bekende aanvallers)', ['list' => $l]);
        }
        $k = $outcome['k'] ?? '';
        return $s + (in_array($k, ['blocked', 'stopped', 'isolated'], true) ? ['state' => 'stop', 'text' => t('Verbinding tegengehouden')]
            : (in_array($inc['type'] ?? '', ['out_bad', 'ti_ip', 'dns_bad'], true) ? ['state' => 'bad', 'text' => t('Contact met een bekend slecht adres')]
            : ['state' => 'warn', 'text' => t('Ongewone verbinding, doorgelaten')]));
    }

    /** Wat er in het uur na de aanval op dezelfde server(s) gebeurde */
    private static function after(array $inc): array
    {
        $s = ['k' => 'after', 'icon' => '🖥️', 'label' => t('Server erna'), 'lines' => []];
        $hosts = [(int) $inc['host_id']];
        $w = WebLog::path($inc);
        foreach ((array) ($w['sites'] ?? []) as $x) {   // de server waar de dienst achter de proxy draait
            if (!empty($x['svc_where']) && ($hid = q('SELECT id FROM hosts WHERE name = ? LIMIT 1', [$x['svc_where']])->fetchColumn())) {
                $hosts[] = (int) $hid;
            }
        }
        $hosts = array_values(array_unique($hosts));
        $start = strtotime((string) $inc['created_at']);
        $end = $start + self::AFTER_MIN * 60;
        $in = implode(',', array_fill(0, count($hosts), '?'));
        $tin = implode(',', array_fill(0, count(self::AFTER), '?'));
        $rows = q("SELECT i.id, i.title, i.created_at, h.name FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                   WHERE i.host_id IN ($in) AND i.type IN ($tin) AND i.type <> ? AND i.id <> ? AND i.created_at BETWEEN ? AND ? ORDER BY i.created_at LIMIT 5",
            array_merge($hosts, self::AFTER, [(string) $inc['type'], (int) $inc['id'], date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)]))->fetchAll();
        if ($rows) {
            foreach ($rows as $r) {
                $s['lines'][] = self::tm((string) $r['created_at']) . ' · ' . $r['name'] . ' · ' . $r['title'];
                $s['ids'][] = (int) $r['id'];
            }
            return $s + ['state' => 'bad', 'text' => t('{n} verdachte gebeurtenis(sen) binnen een uur', ['n' => count($rows)])];
        }
        $left = (int) ceil(($end - time()) / 60);
        return $s + ($left > 0 ? ['state' => 'pass', 'text' => t('Tot nu toe niets verdachts (nog {m} min bekijken)', ['m' => $left])]
            : ['state' => 'stop', 'text' => t('Niets verdachts gezien in het uur erna')]);
    }
}
