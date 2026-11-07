<?php
declare(strict_types=1);

/**
 * Extra checks (1.138.0, agent 1.70.0):
 * - dns: geeft een naam antwoord (A, AAAA, MX, TXT, CNAME, NS), eventueel via een eigen DNS-server, met de verwachte waarde?
 * - ntp: loopt de klok goed? Op agent-servers de klok van de server (chrony / systemd-timesyncd), anders een NTP-server
 *   (bv. je router) rechtstreeks gevraagd (SNTP). De waarde is de afwijking in ms.
 * - smart: schijfgezondheid via de agent (smartctl): gezond, temperatuur, slijtage (SSD/NVMe), slechte sectoren.
 * - ups: UPS via NUT op de server (upsc): op het net of op de batterij, lading, resterende tijd, belasting.
 * - mysql: MariaDB/MySQL bereikbaar, inloggen lukt, hoeveel verbindingen er gebruikt worden, trage query's.
 * Plus de HTTP-check met methode, body en een verwachte tekst of JSON-waarde (Checks::http, via httpExtra()).
 */
final class ExtraChecks
{
    public const DNS_TYPES = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME, 'NS' => DNS_NS];
    /** Wachtwoord van een MariaDB-check gaat nooit naar de browser: dit staat er dan */
    public const MASK = '••••••••';

    public static function run(array $check, array $host): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        return match ($check['type']) {
            'dns' => self::dns($check, $o),
            'ntp' => self::ntp($check, $host),
            'smart' => self::smart($check, $host),
            'ups' => self::ups($check, $host),
            'mysql' => Db::check($check, $host),   // 1.200.0: MariaDB/MySQL en PostgreSQL, via RzSense of de agent
            default => Checks::result(STATUS_UNKNOWN, null, t('Onbekend checktype')),
        };
    }

    // ------------------------------------------------------------------ DNS

    private static function dns(array $check, array $o): array
    {
        $name = trim((string) $check['target']);
        if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', $name)) {
            return Checks::result(STATUS_UNKNOWN, null, t('Ongeldige naam'));
        }
        $type = isset(self::DNS_TYPES[$o['rtype'] ?? '']) ? $o['rtype'] : 'A';
        $server = trim((string) ($o['server'] ?? ''));
        $t0 = microtime(true);
        if ($server !== '') {
            if (!filter_var($server, FILTER_VALIDATE_IP)) {
                return Checks::result(STATUS_UNKNOWN, null, t('DNS-server moet een IP-adres zijn'));
            }
            $dig = trim((string) shell_exec(rz_sh('command -v dig 2>/dev/null')));
            if ($dig === '') {
                return Checks::result(STATUS_UNKNOWN, null, t('Voor een eigen DNS-server is "dig" nodig op de server van RzSense (bind-utils / dnsutils)'));
            }
            exec(rz_sh(sprintf('%s +time=3 +tries=1 +short %s %s %s 2>&1', escapeshellcmd($dig), escapeshellarg('@' . $server), escapeshellarg($name), escapeshellarg($type))), $out, $rc);
            $answers = $rc === 0 ? array_values(array_filter(array_map('trim', $out), static fn($l) => $l !== '' && !str_starts_with($l, ';'))) : [];
            if ($rc !== 0) {
                return Checks::result(STATUS_CRIT, null, t('Geen antwoord van {s}', ['s' => $server]) . ': ' . mb_substr(implode(' ', $out), 0, 150));
            }
        } else {
            $recs = @dns_get_record($name, self::DNS_TYPES[$type]) ?: [];
            $answers = array_map(static fn($r) => (string) ($r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? ''), $recs);
            if ($type === 'MX') {
                $answers = array_map(static fn($r) => ($r['pri'] ?? '') . ' ' . ($r['target'] ?? ''), $recs);
            }
        }
        $ms = (microtime(true) - $t0) * 1000;
        if (!$answers) {
            return Checks::result(STATUS_CRIT, $ms, t('{n}: geen {t}-record', ['n' => $name, 't' => $type]));
        }
        $got = implode(', ', array_slice($answers, 0, 5));
        $expect = trim((string) ($o['expect'] ?? ''));
        if ($expect !== '' && !array_filter($answers, static fn($a) => stripos(rtrim($a, '.'), rtrim($expect, '.')) !== false)) {
            return Checks::result(STATUS_CRIT, $ms, t('{n} {t}: verwacht {e}, kreeg {g}', ['n' => $name, 't' => $type, 'e' => $expect, 'g' => $got]));
        }
        return Checks::result(Checks::threshold($ms, $check), $ms, sprintf('%s %s → %s (%.0f ms)', $name, $type, $got, $ms));
    }

    // ------------------------------------------------------------------ Tijd

    private static function ntp(array $check, array $host): array
    {
        $target = trim((string) $check['target']);
        if ($host['mode'] === 'agent' && $target === '') {
            if ($stale = Checks::agentStale($host)) {
                return $stale;
            }
            $d = json_decode((string) Checks::agentMetric($host, 'time'), true);
            if (!is_array($d)) {
                return Checks::result(STATUS_UNKNOWN, null, t('Agent meldt de klok niet (agent 1.70.0 nodig, met chrony of systemd-timesyncd)'));
            }
            $off = isset($d['offset_ms']) && is_numeric($d['offset_ms']) ? abs((float) $d['offset_ms']) : null;
            if (empty($d['sync'])) {
                return Checks::result(STATUS_WARN, $off, t('Klok niet gesynchroniseerd ({src})', ['src' => $d['src'] ?? '?']) . ($off !== null ? sprintf(' · %.1f ms', $off) : ''));
            }
            if ($off === null) {
                return Checks::result(STATUS_OK, null, t('Klok gesynchroniseerd ({src})', ['src' => $d['src'] ?? '?']));
            }
            return Checks::result(Checks::threshold($off, $check), round($off, 2), t('Klok wijkt {ms} ms af ({src})', ['ms' => I18n::dec((string) round($off, 1)), 'src' => $d['src'] ?? '?']));
        }
        $server = $target !== '' ? $target : (string) $host['address'];
        $off = self::sntp($server);
        if (is_string($off)) {
            return Checks::result(STATUS_CRIT, null, t('NTP-server {s}: {e}', ['s' => $server, 'e' => $off]));
        }
        return Checks::result(Checks::threshold(abs($off), $check), round(abs($off), 2), t('NTP-server {s} wijkt {ms} ms af van RzSense', ['s' => $server, 'ms' => I18n::dec((string) round($off, 1))]));
    }

    /** SNTP (RFC 4330): afwijking van de NTP-server ten opzichte van deze klok in ms, of een foutmelding */
    public static function sntp(string $server): float|string
    {
        $fp = @fsockopen('udp://' . $server, 123, $errno, $err, 3);
        if (!$fp) {
            return $err ?: t('geen verbinding');
        }
        stream_set_timeout($fp, 3);
        $t1 = microtime(true);
        fwrite($fp, "\x1b" . str_repeat("\0", 47));   // versie 3, client
        $resp = fread($fp, 48);
        $t4 = microtime(true);
        fclose($fp);
        if (strlen((string) $resp) < 48) {
            return t('geen antwoord');
        }
        $ts = static function (string $b): float {
            $p = unpack('Nsec/Nfrac', $b);
            return $p['sec'] - 2208988800 + $p['frac'] / 4294967296;
        };
        $t2 = $ts(substr($resp, 32, 8));
        $t3 = $ts(substr($resp, 40, 8));
        if ($t3 < 1e9) {
            return t('ongeldig antwoord');
        }
        return (($t2 - $t1) + ($t3 - $t4)) / 2 * 1000;
    }

    // ------------------------------------------------------------------ Schijfgezondheid

    private static function smart(array $check, array $host): array
    {
        if ($stale = Checks::agentStale($host)) {
            return $stale;
        }
        $list = json_decode((string) Checks::agentMetric($host, 'smart'), true);
        if (!is_array($list)) {
            return Checks::result(STATUS_UNKNOWN, null, t('Agent meldt geen schijfgezondheid (agent 1.70.0 en smartctl nodig: smartmontools)'));
        }
        $dev = trim((string) $check['target']);
        $disks = array_values(array_filter($list, static fn($d) => $dev === '' || ($d['dev'] ?? '') === $dev));
        if (!$disks) {
            return Checks::result(STATUS_UNKNOWN, null, $dev !== '' ? t('Agent meldt schijf {d} niet', ['d' => $dev]) : t('Geen schijven gevonden'));
        }
        $status = STATUS_OK;
        $msgs = [];
        $maxTemp = null;
        foreach ($disks as $d) {
            $bad = [];
            $name = ($d['dev'] ?? '?') . (!empty($d['model']) ? ' (' . $d['model'] . ')' : '');
            if (!empty($d['error'])) {
                $msgs[] = $name . ': ' . $d['error'];   // niet uit te lezen (bv. achter een USB-adapter): geen status
                continue;
            }
            if (isset($d['passed']) && $d['passed'] === false) {
                $status = STATUS_CRIT;
                $bad[] = t('gezondheidstest MISLUKT');
            }
            foreach (['realloc' => t('{n} vervangen sectoren'), 'pending' => t('{n} twijfelachtige sectoren'), 'media_err' => t('{n} mediafouten')] as $k => $label) {
                if ((int) ($d[$k] ?? 0) > 0) {
                    $status = max($status, STATUS_WARN);
                    $bad[] = str_replace('{n}', (string) (int) $d[$k], $label);
                }
            }
            if (isset($d['wear']) && is_numeric($d['wear'])) {
                $w = (int) $d['wear'];
                if ($w >= 90) {
                    $status = STATUS_CRIT;
                } elseif ($w >= 80) {
                    $status = max($status, STATUS_WARN);
                }
                $bad[] = t('{n}% versleten', ['n' => $w]);
            }
            if (isset($d['temp']) && is_numeric($d['temp'])) {
                $maxTemp = max($maxTemp ?? -999, (float) $d['temp']);
                $status = max($status, Checks::threshold((float) $d['temp'], $check));
                $bad[] = (int) $d['temp'] . ' °C';
            }
            $msgs[] = $name . ($bad ? ': ' . implode(', ', $bad) : ': ' . t('gezond'));
        }
        return Checks::result($status, $maxTemp, implode(' · ', $msgs));
    }

    // ------------------------------------------------------------------ UPS

    private static function ups(array $check, array $host): array
    {
        if ($stale = Checks::agentStale($host)) {
            return $stale;
        }
        $list = json_decode((string) Checks::agentMetric($host, 'ups'), true);
        if (!is_array($list) || !$list) {
            return Checks::result(STATUS_UNKNOWN, null, t('Agent meldt geen UPS (agent 1.70.0 en NUT nodig: upsc)'));
        }
        $name = trim((string) $check['target']);
        $u = null;
        foreach ($list as $x) {
            if ($name === '' || ($x['name'] ?? '') === $name) {
                $u = $x;
                break;
            }
        }
        if (!$u) {
            return Checks::result(STATUS_UNKNOWN, null, t('Agent meldt UPS {n} niet', ['n' => $name]));
        }
        $st = (string) ($u['status'] ?? '');
        $charge = isset($u['charge']) && is_numeric($u['charge']) ? (float) $u['charge'] : null;
        $parts = [];
        $status = STATUS_OK;
        if (preg_match('/\bOB\b/', $st)) {
            $status = STATUS_WARN;
            $parts[] = t('op de batterij (stroomuitval)');
        } elseif (preg_match('/\bOL\b/', $st)) {
            $parts[] = t('op het net');
        }
        if (preg_match('/\bLB\b/', $st)) {
            $status = STATUS_CRIT;
            $parts[] = t('batterij bijna leeg');
        }
        if (preg_match('/\bRB\b/', $st)) {
            $status = max($status, STATUS_WARN);
            $parts[] = t('batterij vervangen');
        }
        if ($charge !== null) {
            // Lager is slechter: drempels "onder"
            if ($check['crit_threshold'] !== null && $charge <= (float) $check['crit_threshold']) {
                $status = STATUS_CRIT;
            } elseif ($check['warn_threshold'] !== null && $charge <= (float) $check['warn_threshold']) {
                $status = max($status, STATUS_WARN);
            }
            $parts[] = t('lading {n}%', ['n' => (int) $charge]);
        }
        if (isset($u['runtime']) && is_numeric($u['runtime'])) {
            $parts[] = t('nog {m} min', ['m' => (int) round($u['runtime'] / 60)]);
        }
        if (isset($u['load']) && is_numeric($u['load'])) {
            $parts[] = t('belasting {n}%', ['n' => (int) $u['load']]);
        }
        return Checks::result($status, $charge, ($u['name'] ?? 'UPS') . ': ' . implode(', ', $parts));
    }

    // ------------------------------------------------------------------ MariaDB / MySQL

    /** Opties zonder wachtwoord, voor de browser */
    public static function maskOptions(?string $options): ?string
    {
        $o = json_decode((string) $options, true);
        if (!is_array($o) || !isset($o['pass'])) {
            return $options;
        }
        $o['pass'] = $o['pass'] !== '' ? self::MASK : '';
        unset($o['_slow'], $o['_prev'], $o['_sz'], $o['_m']);
        return json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ HTTP: veilig adres, methode, body, verwachte inhoud

    /**
     * Mag RzSense dit adres opvragen? Niet: link-local (169.254.x.x, waaronder metadata-diensten van clouds), fe80::,
     * 0.0.0.0, multicast. Wel: je eigen LAN en internet (daar zijn checks voor). Null = in orde, anders de reden.
     */
    public static function urlBlocked(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return t('alleen http:// en https:// zijn toegestaan');
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(gethostbynamel($host) ?: [], array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        foreach ($ips as $ip) {
            if (ip_in_list($ip, ['169.254.0.0/16', '0.0.0.0/8', '224.0.0.0/4', 'fe80::/10', 'ff00::/8', '::/128'])) {
                return t('adres {ip} is niet toegestaan (link-local of metadata-dienst)', ['ip' => $ip]);
            }
        }
        return null;
    }

    /** Verwachte inhoud: tekst die erin moet staan, of een JSON-veld (pad met punten, bv. status of data.0.ok) met een waarde */
    public static function bodyCheck(string $body, array $o): ?string
    {
        $want = (string) ($o['expect'] ?? '');
        if ($want !== '' && !str_contains($body, $want)) {
            return t('tekst "{t}" niet gevonden', ['t' => mb_substr($want, 0, 60)]);
        }
        $path = trim((string) ($o['json'] ?? ''));
        if ($path === '') {
            return null;
        }
        $data = json_decode($body, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return t('antwoord is geen JSON');
        }
        foreach (explode('.', ltrim($path, '$.')) as $k) {
            if (!is_array($data) || !array_key_exists($k, $data)) {
                return t('JSON-veld {p} ontbreekt', ['p' => $path]);
            }
            $data = $data[$k];
        }
        $val = is_bool($data) ? ($data ? 'true' : 'false') : (is_scalar($data) || $data === null ? (string) json_encode($data) : json_encode($data));
        $val = is_string($data) ? $data : $val;
        $wantV = (string) ($o['json_value'] ?? '');
        if ($wantV !== '' && strcasecmp($val, $wantV) !== 0) {
            return t('JSON-veld {p} is "{v}", verwacht "{w}"', ['p' => $path, 'v' => mb_substr($val, 0, 60), 'w' => $wantV]);
        }
        return null;
    }
}
