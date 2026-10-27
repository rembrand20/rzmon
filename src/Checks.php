<?php
declare(strict_types=1);

/**
 * Voert individuele checks uit.
 * Iedere check geeft terug: ['status' => int, 'value' => ?float, 'message' => string]
 * Voor alle drempels geldt: hogere waarde = slechter (ms of % gebruikt).
 */
final class Checks
{
    public static function run(array $check, array $host): array
    {
        if ($host['mode'] === 'remote' && in_array($check['type'], ['disk', 'service', 'cpu', 'ram', 'reboot'], true)) {
            return self::result(STATUS_UNKNOWN, null, t('Disk/service/CPU/RAM checks vereisen host-type lokaal of agent'));
        }
        if ($host['mode'] === 'remote' && $check['type'] === 'process') {
            return self::result(STATUS_UNKNOWN, null, t('Een proces bewaken kan alleen op de RzMon-server zelf of op een server met de agent'));
        }
        if ($host['mode'] !== 'agent' && in_array($check['type'], ['podman', 'container', 'log', 'command'], true)) {
            return self::result(STATUS_UNKNOWN, null, t('Deze check werkt via de agent: zet de server op type "Remote met agent" en installeer de agent'));
        }
        try {
            return match ($check['type']) {
                'ping' => self::ping($check, $host),
                'tcp' => self::tcp($check, $host),
                'http' => self::http($check, $host),
                'ssl' => self::ssl($check, $host),
                'podman' => self::podman($check, $host),
                'container' => self::container($check, $host),
                'pve' => self::pve($check, $host),
                'log' => self::logCheck($check, $host),
                'command' => self::commandCheck($check, $host),
                'ha' => $host['mode'] === 'agent' ? self::haCheck($check, $host)
                    : self::result(STATUS_UNKNOWN, null, t('Deze check werkt via de Home Assistant-add-on: zet de server op type "Remote met agent"')),
                'disk' => $host['mode'] === 'agent' ? self::agentDisk($check, $host) : self::localDisk($check),
                'service' => $host['mode'] === 'agent' ? self::agentService($check, $host) : self::localService($check),
                'cpu' => $host['mode'] === 'agent' ? self::agentCpu($check, $host) : self::localCpu($check),
                'ram' => $host['mode'] === 'agent' ? self::agentRam($check, $host) : self::localRam($check),
                'snmp' => SnmpMonitor::check($check, $host),
                'reboot' => self::reboot($check, $host),
                'heartbeat' => Heartbeat::check($check),
                'process' => self::process($check, $host),
                default => self::result(STATUS_UNKNOWN, null, t('Onbekend checktype')),
            };
        } catch (Throwable $e) {
            return self::result(STATUS_UNKNOWN, null, t('Fout') . ': ' . $e->getMessage());
        }
    }

    /**
     * Herstart: de uptime in uren is de waarde (de check is altijd OK). Is hij korter dan bij de vorige controle,
     * dan is de server herstart: een info-melding (Instellingen of ✎ → 🔔 Meldingen → Info).
     */
    private static function reboot(array $check, array $host): array
    {
        if ($host['mode'] === 'agent') {
            if ($stale = self::agentStale($host)) {
                return $stale;
            }
            $boot = (int) self::agentMetric($host, 'boot');
            if ($boot <= 0) {
                return self::result(STATUS_UNKNOWN, null, t('De agent meldt zijn opstarttijd nog niet: werk de agent bij (1.46.0 of nieuwer)'));
            }
        } else {
            $up = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
            if ($up <= 0) {
                return self::result(STATUS_UNKNOWN, null, t('Kan de uptime van deze server niet lezen (/proc/uptime)'));
            }
            $boot = (int) round(time() - $up);
        }
        $s = max(0, time() - $boot);
        $h = round($s / 3600, 2);
        $res = self::result(STATUS_OK, $h, t('Aan sinds {t} · uptime {dur}', ['t' => date('d-m-Y H:i', $boot), 'dur' => SnmpMonitor::duration($s)]));
        return ($info = self::restartInfo($check, $h)) ? $res + ['info' => $info] : $res;
    }

    /**
     * Herstart gezien? De uptime (in uren) is korter dan bij de vorige controle. Geeft de tekst voor de info-melding.
     * @param float|null $wrap teller die overloopt (SNMP: na 497 dagen); vlak daarvoor is een kortere uptime geen herstart
     */
    public static function restartInfo(array $check, float $hours, ?float $wrap = null): ?string
    {
        $prev = $check['last_value'] ?? null;
        if ($prev === null || !is_numeric($prev) || $hours >= (float) $prev - 0.01 || ($wrap !== null && (float) $prev > $wrap * 0.95)) {
            return null;
        }
        return t('Herstart om {t} · was {dur} aan', ['t' => date('d-m H:i', time() - (int) round($hours * 3600)), 'dur' => SnmpMonitor::duration((int) round((float) $prev * 3600))]);
    }

    /** Checktypes waarbij "omgekeerd" kan: werkt het wel of niet (geen drempels) */
    public const REVERSIBLE = ['ping', 'tcp', 'http', 'service', 'container', 'podman', 'command', 'ha'];

    /**
     * Omgekeerde status: slaagt de check (OK of WARNING), dan krijgt hij reverse_ok;
     * mislukt hij (CRITICAL), dan reverse_fail. UNKNOWN blijft UNKNOWN.
     */
    public static function reverse(array $check, array $res): array
    {
        if ($check['reverse_ok'] === null || !in_array($check['type'], self::REVERSIBLE, true) || $res['status'] === STATUS_UNKNOWN) {
            return $res;
        }
        if ($res['status'] === STATUS_CRIT) {
            $res['status'] = (int) ($check['reverse_fail'] ?? STATUS_OK);
            $res['message'] = '⇄ ' . ($res['status'] === STATUS_OK ? t('Reageert niet, zoals bedoeld') : t('Reageert niet')) . ': ' . $res['message'];
        } else {
            $res['status'] = (int) $check['reverse_ok'];
            $res['message'] = '⇄ ' . ($res['status'] === STATUS_OK ? t('Reageert') : t('Reageert (dat mag niet)')) . ': ' . $res['message'];
        }
        return $res;
    }

    private static function result(int $status, ?float $value, string $message): array
    {
        return ['status' => $status, 'value' => $value, 'message' => $message];
    }

    private static function threshold(float $value, array $check): int
    {
        $crit = $check['crit_threshold'];
        $warn = $check['warn_threshold'];
        if ($crit !== null && $value >= (float) $crit) {
            return STATUS_CRIT;
        }
        if ($warn !== null && $value >= (float) $warn) {
            return STATUS_WARN;
        }
        return STATUS_OK;
    }

    private static function ping(array $check, array $host): array
    {
        $bin = config('ping_bin', '/bin/ping');
        $cmd = sprintf('%s -c 3 -i 0.3 -W 2 -q %s 2>&1', escapeshellcmd($bin), escapeshellarg($host['address']));
        exec($cmd, $out);
        $out = implode("\n", $out);

        if (!preg_match('/(\d+(?:\.\d+)?)% packet loss/', $out, $m)) {
            return self::result(STATUS_CRIT, null, t('Ping mislukt') . ': ' . trim(mb_substr($out, 0, 200)));
        }
        $loss = (float) $m[1];
        if ($loss >= 100) {
            return self::result(STATUS_CRIT, null, t('Host reageert niet (100% packet loss)'));
        }
        $avg = preg_match('#= [\d.]+/([\d.]+)/#', $out, $r) ? (float) $r[1] : null;
        $status = $avg !== null ? self::threshold($avg, $check) : STATUS_OK;
        if ($loss > 0) {
            $status = max($status, STATUS_WARN);
        }
        return self::result($status, $avg, sprintf(t('%s ms gemiddeld, %s%% packet loss'), $avg !== null ? round($avg, 1) : '?', $loss));
    }

    private static function tcp(array $check, array $host): array
    {
        $port = (int) $check['target'];
        if ($port < 1 || $port > 65535) {
            return self::result(STATUS_UNKNOWN, null, t('Ongeldige poort'));
        }
        $start = microtime(true);
        $fp = @fsockopen($host['address'], $port, $errno, $errstr, 5);
        $ms = (microtime(true) - $start) * 1000;
        if (!$fp) {
            return self::result(STATUS_CRIT, null, t('Poort {port} onbereikbaar', ['port' => $port]) . ': ' . $errstr);
        }
        fclose($fp);
        return self::result(self::threshold($ms, $check), $ms, sprintf(t('Poort %d open (%.1f ms)'), $port, $ms));
    }

    private static function http(array $check, array $host): array
    {
        $url = trim($check['target']);
        if ($url === '' || $url[0] === '/') {
            $url = 'http://' . $host['address'] . ($url ?: '/');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'RzMon/1.0',
            CURLOPT_SSL_VERIFYPEER => (int) ($check['verify_ssl'] ?? 1) === 1,
            CURLOPT_SSL_VERIFYHOST => (int) ($check['verify_ssl'] ?? 1) === 1 ? 2 : 0,
            CURLOPT_CERTINFO => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $err = curl_error($ch);
        $certs = curl_getinfo($ch, CURLINFO_CERTINFO);
        curl_close($ch);

        if ($code === 0) {
            return self::result(STATUS_CRIT, null, t('Geen antwoord') . ': ' . $err);
        }
        if ($code >= 400) {
            return self::result(STATUS_CRIT, $ms, "HTTP $code");
        }
        $status = self::threshold($ms, $check);
        $msg = sprintf(t('HTTP %d in %.0f ms'), $code, $ms);

        // SSL-certificaat: waarschuwen < 14 dagen, kritiek < 3 dagen
        if (!empty($certs[0]['Expire date'])) {
            $expires = strtotime($certs[0]['Expire date']);
            if ($expires) {
                $days = (int) floor(($expires - time()) / 86400);
                $msg .= ', ' . t('cert nog {n} dagen geldig', ['n' => $days]);
                if ($days < 3) {
                    $status = STATUS_CRIT;
                } elseif ($days < 14) {
                    $status = max($status, STATUS_WARN);
                }
            }
        }
        return self::result($status, $ms, $msg);
    }

    /**
     * SSL/TLS-certificaat: hoeveel dagen is het nog geldig? Drempels zijn hier "minder dan":
     * warning onder X dagen, critical onder Y dagen. Werkt op elke TLS-poort (443, 8443, 993, 8883…).
     * Op een agent-server komen certificaten die de agent meldt (bestanden, of host:poort gezien
     * vanaf die server) van de agent; al het andere meet RzMon zelf via het netwerk.
     */
    /**
     * Wat de agent voor deze SSL-check uitleest (1.98.0), of null als RzMon het zelf via het netwerk doet:
     * een bestand altijd; host:poort als "controleren vanaf" op de agent staat, of automatisch bij localhost/127.x.
     */
    public static function agentCertSource(array $check): ?string
    {
        $target = trim((string) $check['target']);
        $from = (json_decode((string) ($check['options'] ?? ''), true) ?: [])['from'] ?? '';
        if ($target !== '' && $target[0] === '/') {
            return preg_match('#^/\S{1,250}$#', $target) ? $target : null;
        }
        if ($from === 'rzmon') {
            return null;
        }
        $hp = self::hostPort($target !== '' ? $target : 'localhost');
        if ($hp === null) {
            return null;
        }
        $local = in_array(strtolower($hp[0]), ['localhost', '::1'], true) || str_starts_with($hp[0], '127.');
        return $from === 'agent' || ($local && $target !== '') ? self::fmtHostPort($hp) : null;
    }

    private static function ssl(array $check, array $host): array
    {
        $target = trim($check['target']) ?: $host['address'];
        // Op de RzMon-server zelf: een certificaatbestand rechtstreeks lezen
        if ($host['mode'] === 'local' && $target[0] === '/') {
            $pem = @file_get_contents($target);
            $info = $pem !== false ? @openssl_x509_parse($pem) : false;
            if (!$info) {
                return self::result(STATUS_UNKNOWN, null, t('Kan {cert} niet lezen (bestaat het, en mag de webserver het lezen?)', ['cert' => $target]));
            }
            $issuer = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '?');
            return self::certResult($check, (int) $info['validTo_time_t'], (string) ($info['subject']['CN'] ?? '?'), is_array($issuer) ? implode(', ', $issuer) : (string) $issuer, null);
        }
        // Agent-server: bestanden, en host:poort gezien vanaf de server zelf (1.98.0: de agent krijgt ze van RzMon)
        if ($host['mode'] === 'agent' && $host['id'] && ($src = self::agentCertSource($check)) !== null) {
            if (($raw = self::agentMetric($host, "cert:$src")) !== null) {
                return self::agentSsl($check, $host, json_decode($raw, true) ?: []);
            }
            return ($stale = self::agentStale($host)) ? $stale
                : self::result(STATUS_UNKNOWN, null, t('De agent leest {cert} uit bij zijn volgende rapport (agent 1.59.0 of nieuwer nodig)', ['cert' => $src]));
        }
        $from = (json_decode((string) ($check['options'] ?? ''), true) ?: [])['from'] ?? '';
        if ($host['mode'] === 'agent' && $host['id'] && $from !== 'rzmon') {
            // De agent meldt bestanden met hun pad en adressen als host:poort
            $hp = $target[0] === '/' ? null : self::hostPort($target);
            $metric = $target[0] === '/' ? "cert:$target" : ($hp ? 'cert:' . self::fmtHostPort($hp) : null);
            if ($metric !== null && ($raw = self::agentMetric($host, $metric)) !== null) {
                return self::agentSsl($check, $host, json_decode($raw, true) ?: []);
            }
            if ($target[0] === '/') {
                return ($stale = self::agentStale($host)) ? $stale
                    : self::result(STATUS_UNKNOWN, null, t('Agent meldt certificaat {cert} niet (staat het in CERTS op de server?)', ['cert' => $target]));
            }
        }
        $hp = self::hostPort($target);
        if ($hp === null) {
            return self::result(STATUS_UNKNOWN, null, t('Ongeldig adres; gebruik host of host:poort'));
        }
        [$name, $port] = $hp;
        $verify = (int) ($check['verify_ssl'] ?? 1) === 1;

        $fetch = static function (bool $verify) use ($name, $port): array {
            $ctx = stream_context_create(['ssl' => [
                'capture_peer_cert' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $name,
            ]]);
            $fp = @stream_socket_client('ssl://' . self::fmtHostPort([$name, $port]), $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) {
                $err = error_get_last()['message'] ?? $errstr;
                return [null, $errstr ?: $err];
            }
            $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
            fclose($fp);
            return [$cert ? openssl_x509_parse($cert) : null, null];
        };

        [$info, $err] = $fetch($verify);
        $invalid = null;
        if (!$info && $verify) {
            // Certificaat afgekeurd? Dan alsnog uitlezen om te laten zien waarom
            [$info] = $fetch(false);
            if ($info) {
                $invalid = t('certificaat niet vertrouwd of naam klopt niet');
            }
        }
        if (!$info) {
            return self::result(STATUS_CRIT, null, t('Geen TLS-verbinding met {addr}', ['addr' => "$name:$port"]) . ($err ? ': ' . trim(preg_replace('/^.*?: /', '', $err)) : ''));
        }
        $issuer = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '?');
        return self::certResult($check, (int) $info['validTo_time_t'], (string) ($info['subject']['CN'] ?? '?'),
            is_array($issuer) ? implode(', ', $issuer) : (string) $issuer, $invalid);
    }

    /** Certificaat zoals de agent het meldt. */
    private static function agentSsl(array $check, array $host, array $d): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        if (($d['error'] ?? '') !== '' || empty($d['not_after'])) {
            return self::result(STATUS_CRIT, null, 'Agent: ' . ($d['error'] ? AgentText::tr((string) $d['error']) : t('geen certificaatgegevens')));
        }
        $invalid = null;
        if ((int) ($check['verify_ssl'] ?? 1) === 1 && isset($d['verify']) && $d['verify'] !== 'ok') {
            $invalid = t('certificaat niet vertrouwd ({reason})', ['reason' => $d['verify']]);
        }
        // De hele keten (agent 1.59.0): verloopt een tussencertificaat eerder, dan telt dat
        $notAfter = (int) $d['not_after'];
        $chainNote = '';
        if (!empty($d['chain_min']) && (int) $d['chain_min'] < $notAfter) {
            $notAfter = (int) $d['chain_min'];
            $chainNote = ' · ' . t('let op: {cn} in de keten verloopt eerder', ['cn' => $d['chain_cn'] ?: '?']);
        }
        $r = self::certResult($check, $notAfter, (string) ($d['cn'] ?: '?'), (string) ($d['issuer'] ?: '?'), $invalid);
        $r['message'] .= $chainNote . ((int) ($d['chain_n'] ?? 0) > 1 ? ' · ' . t('keten van {n}', ['n' => (int) $d['chain_n']]) : '') . ' · ' . t('via agent');
        return $r;
    }

    /**
     * Proces (1.98.0): draait het (op naam, of een patroon in de opdrachtregel), hoeveel, CPU en geheugen, en is het
     * herstart (het oudste proces is jonger dan bij de vorige controle: een info-melding). Geen proces = CRITICAL;
     * minder of meer dan ingesteld, of boven een drempel = WARNING (of CRITICAL bij de critical-drempel).
     */
    private static function process(array $check, array $host): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        $name = (string) $check['target'];
        if ($host['mode'] === 'agent') {
            if ($stale = self::agentStale($host)) {
                return $stale;
            }
            $raw = self::agentMetric($host, 'proc:' . (int) $check['id']);
            if ($raw === null) {
                return self::result(STATUS_UNKNOWN, null, t('De agent meldt proces {p} nog niet (agent 1.59.0 of nieuwer; binnen ~2 minuten na het toevoegen)', ['p' => $name]));
            }
            $d = json_decode($raw, true) ?: [];
        } else {
            $d = self::localProcess($name, $o);
        }
        $n = (int) ($d['n'] ?? 0);
        if ($n === 0) {
            return self::result(STATUS_CRIT, 0, t('Proces {p} draait niet', ['p' => $name]) . (!empty($o['user']) ? ' (' . t('gebruiker {u}', ['u' => $o['user']]) . ')' : ''));
        }
        $status = STATUS_OK;
        $bad = [];
        if (isset($o['min']) && $n < (int) $o['min']) {
            $status = STATUS_WARN;
            $bad[] = t('{n} draaien, minimaal {m}', ['n' => $n, 'm' => (int) $o['min']]);
        }
        if (isset($o['max']) && $n > (int) $o['max']) {
            $status = STATUS_WARN;
            $bad[] = t('{n} draaien, maximaal {m}', ['n' => $n, 'm' => (int) $o['max']]);
        }
        $cpu = round((float) ($d['cpu'] ?? 0), 1);
        $mem = round((int) ($d['rss'] ?? 0) / 1024, 1);
        foreach (['cpu' => [$cpu, '%', 'CPU'], 'mem' => [$mem, 'MB', 'RAM']] as $k => [$v, $u, $l]) {
            [$w, $c] = array_pad((array) (($o['res'] ?? [])[$k] ?? []), 2, null);
            if ($c !== null && $v >= (float) $c) {
                $status = STATUS_CRIT;
                $bad[] = "$l $v $u ≥ $c";
            } elseif ($w !== null && $v >= (float) $w) {
                $status = max($status, STATUS_WARN);
                $bad[] = "$l $v $u ≥ $w";
            }
        }
        $start = isset($d['start']) ? (int) $d['start'] : null;
        $msg = ($bad ? implode(' · ', $bad) . ' · ' : '') . t('{n} × {p} · CPU {cpu}% · RAM {mem} MB', ['n' => $n, 'p' => $name, 'cpu' => $cpu, 'mem' => $mem])
            . ($start ? ' · ' . t('draait sinds {t}', ['t' => date('d-m H:i', $start)]) : '');
        $res = self::result($status, (float) $n, $msg);
        // Herstart: het oudste proces is (meer dan een minuut) later gestart dan bij de vorige controle
        $prev = isset($check['proc_start']) ? (int) $check['proc_start'] : 0;
        if ($start && $prev && $start > $prev + 60) {
            $res['info'] = t('Proces {p} is herstart om {t}', ['p' => $name, 't' => date('d-m H:i', $start)]);
        }
        if ($start && (int) ($check['id'] ?? 0) > 0 && abs($start - $prev) > 60) {
            q('UPDATE checks SET proc_start = ? WHERE id = ?', [$start, (int) $check['id']]);
        }
        return $res;
    }

    /** Processen op de server van RzMon zelf, voor de keuzelijst: [naam, aantal, gebruiker, voorbeeld-commandoregel] */
    public static function localProcList(): array
    {
        $by = [];
        foreach (explode("\n", (string) @shell_exec('ps -eo ppid=,user:32=,comm=,args= 2>/dev/null')) as $line) {
            $f = preg_split('/\s+/', trim($line), 4);
            if (count($f) < 4 || (int) $f[0] === 2 || str_starts_with($f[3], '[')) {
                continue;
            }
            $by[$f[2]] ??= [$f[2], 0, $f[1], mb_substr($f[3], 0, 200)];
            $by[$f[2]][1]++;
        }
        ksort($by);
        return array_slice(array_values($by), 0, 200);
    }

    /** Proces op de RzMon-server zelf (ps), zelfde uitkomst als de agent */
    private static function localProcess(string $name, array $o): array
    {
        $out = (string) @shell_exec('ps -eo pid=,ppid=,user:32=,etimes=,pcpu=,rss=,comm=,args= 2>/dev/null');
        $n = 0;
        $cpu = 0.0;
        $rss = 0;
        $age = 0;
        $pids = [];
        foreach (explode("\n", $out) as $line) {
            $f = preg_split('/\s+/', trim($line), 8);
            if (count($f) < 8 || (int) $f[1] === 2 || str_starts_with($f[7], '[') || (int) $f[0] === getmypid()) {
                continue;
            }
            if (!empty($o['user']) && $f[2] !== $o['user']) {
                continue;
            }
            if (($o['mode'] ?? '') === 'pattern') {
                if (!@preg_match('~' . str_replace('~', '\\~', $name) . '~', $f[7])) {
                    continue;
                }
            } elseif ($f[6] !== $name && basename(explode(' ', $f[7])[0]) !== $name && substr($name, 0, 15) !== $f[6]) {
                continue;
            }
            $n++;
            $pids[] = $f[0];
            $cpu += (float) $f[4];
            $rss += (int) $f[5];
            $age = max($age, (int) $f[3]);
        }
        return ['n' => $n, 'pids' => implode(' ', array_slice($pids, 0, 30)), 'cpu' => $cpu, 'rss' => $rss, 'start' => $n ? time() - $age : null];
    }

    /** Status bij een vervaldatum: dagen-drempels ("onder"), verlopen of ongeldig = CRITICAL. */
    private static function certResult(array $check, int $notAfter, string $cn, string $issuer, ?string $invalid): array
    {
        $days = ($notAfter - time()) / 86400;
        $msg = sprintf(t('%s geldig tot %s (%s) · %s'), $cn, date('d-m-Y', $notAfter),
            $days < 0 ? t('VERLOPEN') : t('nog {n} dagen', ['n' => (int) floor($days)]), $issuer);

        $status = STATUS_OK;
        if ($days < 0) {
            $status = STATUS_CRIT;
        } elseif ($check['crit_threshold'] !== null && $days < (float) $check['crit_threshold']) {
            $status = STATUS_CRIT;
        } elseif ($check['warn_threshold'] !== null && $days < (float) $check['warn_threshold']) {
            $status = STATUS_WARN;
        }
        if ($invalid) {
            $status = STATUS_CRIT;
            $msg = t("Ongeldig: {why} — bewust self-signed? Zet dan 'certificaat controleren' uit", ['why' => $invalid]) . " · $msg";
        }
        $value = floor($days * 10) / 10;   // afronden naar beneden, net als de tekst
        $res = self::result($status, $value, $msg);
        // Vernieuwd: er zijn ineens meer dagen over dan bij de vorige controle (info-melding)
        $prev = $check['last_value'] ?? null;
        if ($prev !== null && is_numeric($prev) && $value > (float) $prev + 1.5) {
            $res['info'] = t('Certificaat vernieuwd') . ': ' . $msg;
        }
        return $res;
    }

    /** "host", "host:poort", "[ipv6]:poort" of een URL → [host, poort], of null. */
    private static function hostPort(string $target): ?array
    {
        $target = preg_replace('#^[a-z]+://#i', '', trim($target));
        $target = explode('/', $target, 2)[0];
        if (preg_match('/^\[(.+)\](?::(\d+))?$/', $target, $m)) {
            [$name, $port] = [$m[1], (int) ($m[2] ?? 443)];
        } elseif (substr_count($target, ':') === 1) {
            [$name, $port] = explode(':', $target);
            $port = (int) $port;
        } else {
            [$name, $port] = [$target, 443];
        }
        return $name === '' || $port < 1 || $port > 65535 ? null : [$name, $port];
    }

    private static function fmtHostPort(array $hp): string
    {
        return (str_contains($hp[0], ':') ? "[{$hp[0]}]" : $hp[0]) . ':' . $hp[1];
    }

    // ------------------------------------------------------------------ Podman (via de agent)

    /** Algehele status: draait podman, en zijn er gestopte (exitcode ≠ 0), ongezonde of gepauzeerde containers? */
    /** Proxmox VE (1.105.0): overzicht, guest, back-ups, opslag of ZFS (zie Pve) */
    private static function pve(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        [$st, $val, $msg] = Pve::check($check, $host);
        return self::result($st, $val, $msg);
    }

    private static function podman(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $raw = self::agentMetric($host, 'podman');
        if ($raw === null) {
            return self::result(STATUS_UNKNOWN, null, t('Agent meldt geen Podman (staat podman op de server, en is de agent bijgewerkt?)'));
        }
        $p = json_decode($raw, true) ?: [];
        $all = $p['containers'] ?? [];
        $running = array_filter($all, fn($c) => ($c['state'] ?? '') === 'running');
        $problems = [];
        foreach ($all as $c) {
            $name = (($c['user'] ?? 'root') !== 'root' ? $c['user'] . '/' : '') . $c['name'];
            $state = $c['state'] ?? '';
            if ($state === 'running' && ($c['health'] ?? '') === 'unhealthy') {
                $problems[] = t('{name} ongezond', ['name' => $name]);
            } elseif ($state === 'paused') {
                $problems[] = t('{name} gepauzeerd', ['name' => $name]);
            } elseif ($state !== 'running' && (int) ($c['exit_code'] ?? 0) !== 0) {
                $problems[] = t('{name} gestopt (exit {code})', ['name' => $name, 'code' => $c['exit_code']]);
            }
        }
        foreach ($p['pods'] ?? [] as $pod) {
            if (!in_array($pod['status'] ?? '', ['Running', 'Exited', 'Created'], true)) {
                $problems[] = "pod {$pod['name']}: " . ($pod['status'] ?? '?');
            }
        }
        $msg = sprintf(t('Podman %s · %d van %d container(s) draaien'), $p['version'] ?? '?', count($running), count($all));
        if (!empty($p['pods'])) {
            $msg .= ' · ' . count($p['pods']) . ' pod(s)';
        }
        $msg .= sprintf(' · ' . t('images %s, volumes %s'), self::siBytes((float) ($p['images_size'] ?? 0)), self::siBytes((float) ($p['volumes_size'] ?? 0)));
        if (($p['reclaimable'] ?? 0) > 0) {
            $msg .= ' (' . t('{size} op te ruimen', ['size' => self::siBytes((float) $p['reclaimable'])]) . ')';
        }
        if (($p['error'] ?? '') !== '') {
            return self::result(count($all) ? STATUS_WARN : STATUS_CRIT, (float) count($running), t('Podman-fout') . ': ' . AgentText::tr((string) $p['error']) . " · $msg");
        }
        if ($problems) {
            return self::result(STATUS_WARN, (float) count($running), implode(', ', $problems) . " · $msg");
        }
        return self::result(STATUS_OK, (float) count($running), $msg);
    }

    /** Eén container: status (incl. health en herstarts), of CPU / RAM / disk met drempels. */
    private static function container(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $name = $check['target'];
        $raw = self::agentMetric($host, "container:$name");
        if ($raw === null) {
            return self::result(self::agentMetric($host, 'podman') === null ? STATUS_UNKNOWN : STATUS_CRIT, null,
                self::agentMetric($host, 'podman') === null ? t('Agent meldt geen Podman (agent bijwerken?)') : t('Container {name} bestaat niet (meer)', ['name' => $name]));
        }
        $c = json_decode($raw, true) ?: [];
        if (($check['metric'] ?? '') === '' || $check['metric'] === null) {
            return self::containerCombined($check, $c, $name);
        }
        $state = (string) ($c['state'] ?? 'unknown');
        $running = $state === 'running';
        $metric = $check['metric'] ?: 'status';
        $memText = isset($c['mem']) ? self::siBytes((float) $c['mem']) . (!empty($c['mem_limit']) ? ' / ' . self::siBytes((float) $c['mem_limit']) : '') : null;

        if ($metric === 'status') {
            $restarts = (int) ($c['restarts'] ?? 0);
            $up = !empty($c['started']) && $running ? ' · ' . t('draait sinds {t}', ['t' => date('d-m H:i', (int) $c['started'])]) : '';
            $extra = ($c['pod'] ?? '') !== '' ? " · pod {$c['pod']}" : '';
            if ($running && ($c['health'] ?? '') === 'unhealthy') {
                return self::result(STATUS_CRIT, (float) $restarts, t('{name} draait maar is ONGEZOND (healthcheck faalt)', ['name' => $name]) . $extra);
            }
            if ($state === 'paused') {
                return self::result(STATUS_WARN, (float) $restarts, t('{name} is gepauzeerd', ['name' => $name]) . $extra);
            }
            if (!$running) {
                return self::result(STATUS_CRIT, (float) $restarts, t('{name} is gestopt ({state}, exitcode {code})', ['name' => $name, 'state' => $state, 'code' => (int) ($c['exit_code'] ?? 0)]) . $extra);
            }
            // Automatisch herstart (restart-policy) in het afgelopen kwartier: WARNING, lang genoeg voor
            // "alarm na N fouten". Een crashende container blijft zo WARNING. Handmatig herstarten telt niet.
            if ($restarts > 0 && !empty($c['started']) && (int) $c['started'] > time() - 900) {
                $min = max(1, (int) round((time() - (int) $c['started']) / 60));
                return self::result(STATUS_WARN, (float) $restarts, t('{name} is {min} min geleden automatisch herstart (totaal {n}×)', ['name' => $name, 'min' => $min, 'n' => $restarts]) . $extra);
            }
            $health = ($c['health'] ?? '') !== '' ? " ({$c['health']})" : '';
            return self::result(STATUS_OK, (float) $restarts, t('{name} draait', ['name' => $name]) . "$health$up"
                . (isset($c['cpu']) ? sprintf(' · CPU %.1f%%', $c['cpu']) : '') . ($memText ? " · RAM $memText" : '') . $extra);
        }

        if ($metric === 'disk') {
            $bytes = (float) ($c['size_rw'] ?? 0) + (float) ($c['volumes'] ?? 0);
            $mb = round($bytes / 1e6, 1);
            return self::result(self::threshold($mb, $check), $mb, sprintf(t('%s: %s (container %s, volumes %s)'), $name, self::siBytes($bytes),
                self::siBytes((float) ($c['size_rw'] ?? 0)), self::siBytes((float) ($c['volumes'] ?? 0))));
        }

        // cpu / mem: alleen zinvol als de container draait (de status-check meldt het stoppen)
        if (!$running) {
            return self::result(STATUS_UNKNOWN, null, t('{name} draait niet ({state})', ['name' => $name, 'state' => $state]));
        }
        if ($metric === 'cpu') {
            if (!isset($c['cpu'])) {
                return self::result(STATUS_UNKNOWN, null, t('Geen CPU-gegevens voor {name} (rootless zonder cgroups v2?)', ['name' => $name]));
            }
            $pct = round((float) $c['cpu'], 1);
            return self::result(self::threshold($pct, $check), $pct, sprintf(t('%s: CPU %.1f%% (100%% = één core)%s'), $name, $pct,
                isset($c['pids']) ? ' · ' . t('{n} processen', ['n' => $c['pids']]) : ''));
        }
        if (empty($c['mem_limit']) || !isset($c['mem'])) {
            return self::result(STATUS_UNKNOWN, null, t('Geen geheugengegevens voor {name} (rootless zonder cgroups v2?)', ['name' => $name]));
        }
        $pct = round((float) $c['mem'] / (float) $c['mem_limit'] * 100, 1);
        return self::result(self::threshold($pct, $check), $pct, sprintf('%s: RAM %.1f%% (%s)', $name, $pct, $memText));
    }

    /**
     * Eén check per container: altijd de status (draait, healthcheck, herstarts), plus de gekozen
     * resources met eigen drempels (options.res = {"cpu": [warn, crit], "mem": [...], "disk": [...]}).
     * De slechtste uitkomst telt; wat over een drempel gaat staat vooraan in het bericht.
     * vals = de gemeten waarden (voor de grafiek met een lijn per meting).
     */
    private static function containerCombined(array $check, array $c, string $name): array
    {
        $res = (json_decode((string) ($check['options'] ?? ''), true) ?: [])['res'] ?? [];
        $state = (string) ($c['state'] ?? 'unknown');
        $running = $state === 'running';
        $restarts = (int) ($c['restarts'] ?? 0);
        $pod = ($c['pod'] ?? '') !== '' ? " · pod {$c['pod']}" : '';

        // 1. Status
        if ($running && ($c['health'] ?? '') === 'unhealthy') {
            [$status, $base] = [STATUS_CRIT, t('{name} draait maar is ONGEZOND (healthcheck faalt)', ['name' => $name])];
        } elseif ($state === 'paused') {
            [$status, $base] = [STATUS_WARN, t('{name} is gepauzeerd', ['name' => $name])];
        } elseif (!$running) {
            [$status, $base] = [STATUS_CRIT, t('{name} is gestopt ({state}, exitcode {code})', ['name' => $name, 'state' => $state, 'code' => (int) ($c['exit_code'] ?? 0)])];
        } elseif ($restarts > 0 && !empty($c['started']) && (int) $c['started'] > time() - 900) {
            $min = max(1, (int) round((time() - (int) $c['started']) / 60));
            [$status, $base] = [STATUS_WARN, t('{name} is {min} min geleden automatisch herstart (totaal {n}×)', ['name' => $name, 'min' => $min, 'n' => $restarts])];
        } else {
            $health = ($c['health'] ?? '') === 'healthy' ? ' (' . t('gezond') . ')' : (($c['health'] ?? '') !== '' ? " ({$c['health']})" : '');
            [$status, $base] = [STATUS_OK, t('{name} draait', ['name' => $name]) . $health];
        }

        // 2. Metingen (CPU en RAM alleen als hij draait)
        $memPct = $running && isset($c['mem']) && !empty($c['mem_limit']) ? round((float) $c['mem'] / (float) $c['mem_limit'] * 100, 1) : null;
        $diskBytes = (float) ($c['size_rw'] ?? 0) + (float) ($c['volumes'] ?? 0);
        $values = [
            'cpu' => $running && isset($c['cpu']) ? round((float) $c['cpu'], 1) : null,
            'mem' => $memPct,
            'disk' => round($diskBytes / 1e6, 1),
        ];
        $nl = static fn(float $v) => I18n::dec((string) $v);
        $label = ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'];
        $show = [
            'cpu' => $values['cpu'] !== null ? 'CPU ' . $nl($values['cpu']) . '%' : null,
            'mem' => $running && isset($c['mem']) ? 'RAM ' . self::siBytes((float) $c['mem']) . ($memPct !== null ? ' (' . $nl($memPct) . '%)' : '') : null,
            'disk' => 'disk ' . self::siBytes($diskBytes),
        ];
        $unit = ['cpu' => '%', 'mem' => '%', 'disk' => ' MB'];
        $rank = [STATUS_OK => 0, STATUS_UNKNOWN => 1, STATUS_WARN => 2, STATUS_CRIT => 3];
        $problems = [];
        $vals = [];
        foreach (['cpu', 'mem', 'disk'] as $m) {
            if (!isset($res[$m]) || $values[$m] === null) {
                continue;
            }
            $v = $values[$m];
            $vals[$m] = $v;
            [$w, $cr] = array_pad((array) $res[$m], 2, null);
            $st = $cr !== null && $v >= (float) $cr ? STATUS_CRIT : ($w !== null && $v >= (float) $w ? STATUS_WARN : STATUS_OK);
            if ($st !== STATUS_OK) {
                $problems[] = ($st === STATUS_CRIT ? '✖ ' : '⚠ ') . $label[$m] . ' ' . $nl($v) . trim($unit[$m])
                    . ' (' . ($st === STATUS_CRIT ? 'critical' : 'warning') . ' ' . t('vanaf') . ' ' . $nl((float) ($st === STATUS_CRIT ? $cr : $w)) . trim($unit[$m]) . ')';
                if ($rank[$st] > $rank[$status]) {
                    $status = $st;
                }
            }
        }
        $parts = $running ? array_filter([$show['cpu'], $show['mem'], $show['disk']]) : [$show['disk']];
        $message = implode(' · ', array_merge($problems, [$base], $parts)) . $pod;
        $value = $vals['cpu'] ?? $vals['mem'] ?? $vals['disk'] ?? null;
        return ['status' => $status, 'value' => $value, 'message' => $message, 'vals' => $vals ?: null];
    }

    /**
     * Home Assistant (via de add-on): laatste back-up (dagen), integraties die niet geladen zijn (aantal),
     * of de systeemstatus van de Supervisor (unhealthy = CRITICAL, unsupported of meldingen = WARNING).
     */
    /** Commando-check: wat de agent terugmeldde vergelijken met het verwachte antwoord (zie AgentCommands). */
    private static function commandCheck(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $cfg = json_decode((string) ($check['command'] ?? ''), true);
        if (!is_array($cfg) || ($cfg['cmd'] ?? '') === '') {
            return self::result(STATUS_UNKNOWN, null, t('Geen commando ingesteld (✎ bewerken)'));
        }
        $h = q('SELECT commands FROM hosts WHERE id = ?', [$host['id']])->fetch() ?: [];
        if (AgentCommands::modeFor($h) === 'off') {
            return self::result(STATUS_UNKNOWN, null, t("Commando's staan uit voor deze server. Zet ze aan onder Instellingen → Agents → Commando-checks, of per server in de Agent Manager."));
        }
        $raw = self::agentMetric($host, 'cmd:' . $check['id']);
        if ($raw === null) {
            return self::result(STATUS_UNKNOWN, null, t('Nog geen resultaat: de agent voert het commando uit bij zijn volgende rapport (agent 1.31.0 of nieuwer nodig).'));
        }
        $r = json_decode($raw, true) ?: [];
        if (!empty($r['blocked'])) {
            return self::result(STATUS_UNKNOWN, null, t('Geblokkeerd op de server') . ': ' . $r['blocked']);
        }
        if (!empty($r['error'])) {
            return self::result(STATUS_UNKNOWN, null, t('Niet uitgevoerd') . ': ' . AgentText::tr((string) $r['error']));
        }
        $maxAge = max(600, 3 * (int) $check['interval_sec']);
        if ((int) ($r['at'] ?? 0) < time() - $maxAge) {
            return self::result(STATUS_UNKNOWN, null, t('Geen recent resultaat (laatst uitgevoerd {t})', ['t' => empty($r['at']) ? t('nooit') : date('d-m H:i', (int) $r['at'])]));
        }
        [$status, $msg] = AgentCommands::judge($cfg, $r);
        return self::result($status, (float) (int) ($r['rc'] ?? 0), $msg);
    }

    private static function haCheck(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $m = (string) $check['metric'];
        if ($m === 'usb') {
            // USB-apparaat (Zigbee-/Z-Wave-stick…): weg = CRITICAL
            $dev = $check['target'];
            $raw = self::agentMetric($host, "usb:$dev");
            if ($raw === null) {
                return self::agentMetric($host, 'ha:os') === null
                    ? self::result(STATUS_UNKNOWN, null, t('De add-on meldt nog geen USB-apparaten (bijwerken naar 1.42.0 of nieuwer, rol manager)'))
                    : self::result(STATUS_CRIT, null, t('USB-apparaat {dev} is WEG: losgeraakt, kapot of niet herkend door Home Assistant OS', ['dev' => $dev]));
            }
            $u = json_decode($raw, true) ?: [];
            return self::result(STATUS_OK, null, trim(($u['vendor'] ?? '') . ' ' . ($u['name'] ?? '')) . ' ' . t('aanwezig') . (($u['path'] ?? '') !== '' ? " ({$u['path']})" : ''));
        }
        $raw = self::agentMetric($host, 'ha:' . (in_array($m, ['reboot', 'disklife', 'osupdate'], true) ? 'os' : $m));
        if ($raw === null) {
            return self::result(STATUS_UNKNOWN, null, in_array($m, ['reboot', 'disklife', 'osupdate'], true)
                ? t('De add-on meldt dit nog niet: werk de RzMon-add-on bij naar 1.42.0 of nieuwer (add-on winkel)')
                : t('De agent meldt dit niet. Is het de Home Assistant-add-on, versie 1.24.0 of nieuwer? (bijwerken via de add-on winkel)'));
        }
        $d = json_decode($raw, true) ?: [];
        if (!empty($d['error'])) {
            return self::result(STATUS_UNKNOWN, null, t('Niet beschikbaar') . ': ' . AgentText::tr((string) $d['error']));
        }
        $ago = static function (int $t): string {
            $s = max(0, time() - $t);
            $txt = $s < 3600 ? t('{n} min geleden', ['n' => max(1, intdiv($s, 60))]) : ($s < 172800 ? t('{n} uur geleden', ['n' => intdiv($s, 3600)]) : t('{n} dagen geleden', ['n' => intdiv($s, 86400)]));
            return "$txt (" . date('d-m H:i', $t) . ')';
        };

        if ($m === 'reboot') {
            // Onverwachte herstart van HA OS: een uur lang CRITICAL; door een OS-update: alleen vermelden
            $up = !empty($d['boot']) ? ' · ' . t('aan sinds {t}', ['t' => date('d-m H:i', (int) $d['boot'])]) : '';
            $r = is_array($d['last_reboot'] ?? null) ? $d['last_reboot'] : null;
            // Een uur vanaf het moment dat de add-on de herstart zag (start hij later dan HA OS, dan telt dat moment)
            if ($r && empty($r['planned']) && max((int) $r['t'], (int) ($r['seen'] ?? 0)) > time() - 3600) {
                return self::result(STATUS_CRIT, null, t('Home Assistant OS is ONVERWACHT herstart om {t} (stroom, crash of handmatig?) · vorige start {prev}',
                    ['t' => date('H:i', (int) $r['t']), 'prev' => date('d-m H:i', (int) $r['prev'])]));
            }
            // Uptime als waarde: korter dan de vorige keer = herstart (info; een onverwachte herstart is hierboven al CRITICAL)
            $hours = !empty($d['boot']) ? round(max(0, time() - (int) $d['boot']) / 3600, 2) : null;
            $info = $hours !== null ? self::restartInfo($check, $hours) : null;
            if ($r) {
                return ['info' => $info] + self::result(STATUS_OK, $hours, (!empty($r['planned']) ? t('Laatste herstart door de OS-update {from} → {to}', ['from' => $r['from'] ?? '?', 'to' => $r['to'] ?? '?'])
                    : t('Laatste onverwachte herstart')) . ' ' . t('op {t}', ['t' => date('d-m H:i', (int) $r['t'])]) . $up);
            }
            return ['info' => $info] + self::result(STATUS_OK, $hours, t('Geen herstart gezien sinds de add-on meekijkt') . $up);
        }
        if ($m === 'disklife') {
            if (!isset($d['disk_life']) || !is_numeric($d['disk_life'])) {
                return self::result(STATUS_UNKNOWN, null, t('Deze hardware meldt geen slijtage van de opslag (alleen eMMC en sommige SD-kaarten/SSD\'s doen dat)'));
            }
            $pct = round((float) $d['disk_life'], 1);
            return self::result(self::threshold($pct, $check), $pct, t('Opslag (SD-kaart/eMMC) is voor {pct} % versleten', ['pct' => $pct])
                . ($pct >= 90 ? ' — ' . t('vervang hem en maak een back-up!') : ($pct >= 75 ? ' — ' . t('plan vervanging') : '')));
        }
        if ($m === 'osupdate') {
            // A/B-bootslots: een niet-actieve slot met status "bad" en een nieuwere versie = mislukte update, teruggevallen
            $slots = is_array($d['slots'] ?? null) ? $d['slots'] : [];
            if (!$slots) {
                return self::result(($d['version'] ?? null) === null ? STATUS_UNKNOWN : STATUS_OK, null, ($d['version'] ?? null) === null
                    ? t('Geen Home Assistant OS (Supervised of container): geen bootslots')
                    : t('Home Assistant OS {v} (deze versie meldt geen bootslots; mislukte updates staan ook bij Systeemstatus)', ['v' => $d['version']]));
            }
            foreach ($slots as $name => $sl) {
                if (!is_array($sl) || ($sl['state'] ?? '') === 'active') {
                    continue;
                }
                if (($sl['status'] ?? '') === 'bad' && version_compare((string) ($sl['version'] ?? '0'), (string) ($d['version'] ?? '0'), '>')) {
                    return self::result(STATUS_CRIT, null, t('OS-update naar {to} is MISLUKT: teruggevallen op {from} (bootslot {slot} is "bad")', ['to' => $sl['version'], 'from' => $d['version'], 'slot' => $name]));
                }
            }
            return self::result(STATUS_OK, null, t('Home Assistant OS {v} draait van bootslot {slot}', ['v' => $d['version'] ?? '?', 'slot' => $d['slot'] ?? '?'])
                . (!empty($d['board']) ? " ({$d['board']})" : ''));
        }

        if ($m === 'backup') {
            if (empty($d['found'])) {
                return self::result(STATUS_UNKNOWN, null, t('Geen back-upsensoren gevonden. Nodig: Home Assistant 2025.2 of nieuwer met automatische back-ups (Instellingen → Systeem → Back-ups).'));
            }
            $next = !empty($d['next']) ? ' · ' . t('volgende: {t}', ['t' => date('d-m H:i', (int) $d['next'])]) : ' · ' . t('geen automatische back-up gepland');
            if (empty($d['last_ok'])) {
                return self::result(STATUS_CRIT, null, t('Nog geen geslaagde automatische back-up') . $next);
            }
            $ok = (int) $d['last_ok'];
            $days = round((time() - $ok) / 86400, 1);
            $status = self::threshold($days, $check);
            $failed = !empty($d['last_try']) && (int) $d['last_try'] > $ok + 60;
            if ($failed && $status === STATUS_OK) {
                $status = STATUS_WARN;
            }
            return self::result($status, $days, t('Laatste geslaagde back-up {when}', ['when' => $ago($ok)])
                . ($failed ? ' · ' . t('laatste poging {t} is MISLUKT', ['t' => date('d-m H:i', (int) $d['last_try'])]) : '') . $next);
        }

        if ($m === 'integrations') {
            $failed = (array) ($d['failed'] ?? []);
            $n = count($failed);
            $total = (int) ($d['total'] ?? 0);
            if (!$n) {
                return self::result(STATUS_OK, 0.0, t('Alle {n} integraties zijn geladen', ['n' => $total]));
            }
            $label = ['setup_error' => t('fout bij instellen'), 'setup_retry' => t('opnieuw proberen'), 'failed_unload' => t('uitladen mislukt')];
            $names = array_map(static fn($f) => ($f['title'] ?: $f['domain']) . ' (' . ($label[$f['state']] ?? $f['state']) . ')', array_slice($failed, 0, 6));
            return self::result(self::threshold((float) $n, $check), (float) $n,
                t('{n} van {total} integraties niet geladen', ['n' => $n, 'total' => $total]) . ': ' . implode(', ', $names) . ($n > 6 ? ', …' : ''));
        }

        // Systeemstatus (Supervisor)
        $nice = static fn(string $c) => [
            'free_space' => t('weinig vrije schijfruimte'), 'docker' => 'Docker', 'docker_version' => t('Docker-versie'),
            'docker_config' => t('Docker-configuratie'), 'os' => t('besturingssysteem'), 'network_manager' => 'NetworkManager',
            'systemd' => 'systemd', 'privileged' => t('Supervisor niet privileged'), 'supervisor' => 'Supervisor', 'setup' => t('installatie'),
            'untrusted' => t('niet-vertrouwde inhoud'), 'oserror_bad_message' => t('bestandssysteemfout'), 'reboot_required' => t('herstart nodig'),
            'multiple_data_disks' => t('meerdere datadisks'), 'no_current_backup' => t('geen recente back-up'), 'pwned' => t('gelekt wachtwoord'),
            'update_failed' => t('update mislukt'), 'update_rollback' => t('update teruggedraaid'), 'corrupt_repository' => t('beschadigde repository'),
            'trust' => t('vertrouwen'), 'security' => t('beveiliging'), 'connectivity_check' => t('verbindingscontrole'),
        ][$c] ?? str_replace('_', ' ', $c);
        $unhealthy = array_map($nice, (array) ($d['unhealthy'] ?? []));
        $unsupported = array_map($nice, (array) ($d['unsupported'] ?? []));
        $issues = array_map(static fn($i) => $nice((string) ($i['type'] ?? '?')) . (!empty($i['reference']) ? " ({$i['reference']})" : ''), (array) ($d['issues'] ?? []));
        $n = count($unhealthy) + count($unsupported) + count($issues);
        if (!$n) {
            return self::result(STATUS_OK, 0.0, t('Systeemstatus in orde: geen reparaties'));
        }
        $parts = array_filter([
            $unhealthy ? t('NIET GEZOND') . ': ' . implode(', ', $unhealthy) : '',
            $unsupported ? t('niet ondersteund') . ': ' . implode(', ', $unsupported) : '',
            $issues ? (count($issues) === 1 ? t('1 melding') : t('{n} meldingen', ['n' => count($issues)])) . ': ' . implode(', ', $issues) : '',
        ]);
        return self::result($unhealthy ? STATUS_CRIT : STATUS_WARN, (float) $n, implode(' · ', $parts));
    }

    /** Bytes zoals podman ze toont (SI: 1 MB = 1000 kB). */
    private static function siBytes(float $b): string
    {
        $u = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1000 && $i < count($u) - 1) {
            $b /= 1000;
            $i++;
        }
        return round($b, 1) . ' ' . $u[$i];
    }

    /**
     * Logbestand doorzoeken (via de agent): aantal treffers in de laatste N minuten (instelling
     * "log_window", standaard 15), met de IP-adressen die het vaakst voorkomen en de laatste regel.
     */
    private static function logCheck(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $raw = self::agentMetric($host, 'logrun:' . $check['id']);
        if ($raw === null) {
            return self::result(STATUS_UNKNOWN, null, t('Nog geen gegevens: de agent haalt nieuwe zoekopdrachten binnen 10 minuten op (agent bijgewerkt?)'));
        }
        $d = json_decode($raw, true) ?: [];
        if (($d['error'] ?? '') !== '') {
            return self::result(STATUS_UNKNOWN, null, 'Agent: ' . AgentText::tr((string) $d['error']));
        }
        $window = max(5, min(1440, (int) setting('log_window', 15)));
        $count = 0;
        $ips = [];
        foreach ($d['hist'] ?? [] as $h) {
            if ($h[0] >= time() - $window * 60) {
                $count += (int) $h[1];
                foreach ($h[2] ?? [] as $ip => $n) {
                    $ips[$ip] = ($ips[$ip] ?? 0) + (int) $n;
                }
            }
        }
        $period = $window >= 120 && $window % 60 === 0 ? t('{n} uur', ['n' => $window / 60]) : "$window min";
        if ($count === 0) {
            $last = !empty($d['last_hit']) ? ' (' . t('laatste treffer: {t}', ['t' => date('d-m H:i', (int) $d['last_hit'])]) . ')' : '';
            return self::result(STATUS_OK, 0.0, t('Geen treffers in de laatste {period}', ['period' => $period]) . $last);
        }
        arsort($ips);
        $top = array_slice($ips, 0, 3, true);
        $msg = ($count === 1 ? t('1 treffer in de laatste {period}', ['period' => $period]) : t('{n} treffers in de laatste {period}', ['n' => $count, 'period' => $period]));
        if ($top) {
            $msg .= ' · ' . t('meeste vanaf') . ' ' . implode(', ', array_map(fn($ip, $n) => "$ip ({$n}×)", array_keys($top), $top));
        }
        $lines = $d['lines'] ?? [];
        $lastLine = $lines ? end($lines) : null;
        if ($lastLine) {
            $msg .= ' · ' . t('laatste') . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string) $lastLine[1]), 0, 160);
        }
        return self::result(self::threshold((float) $count, $check), (float) $count, $msg);
    }

    private static function localDisk(array $check): array
    {
        $path = $check['target'] ?: '/';
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!$total || $free === false) {
            return self::result(STATUS_UNKNOWN, null, t('Kan {path} niet uitlezen', ['path' => $path]));
        }
        return self::diskResult($path, (float) $total, (float) $free, $check);
    }

    private static function diskResult(string $path, float $total, float $free, array $check): array
    {
        $pct = ($total - $free) / $total * 100;
        return self::result(
            self::threshold($pct, $check),
            round($pct, 1),
            sprintf(t('%s: %.1f%% gebruikt (%s vrij van %s)'), $path, $pct, self::bytes($free), self::bytes($total))
        );
    }

    private static function localService(array $check): array
    {
        $name = $check['target'];
        if (!preg_match('/^[A-Za-z0-9@._:-]+$/', $name)) {
            return self::result(STATUS_UNKNOWN, null, t('Ongeldige servicenaam'));
        }
        $state = trim(strtok((string) shell_exec('systemctl is-active ' . escapeshellarg($name) . ' 2>&1'), "\n") ?: 'unknown');
        return self::serviceResult($name, $state);
    }

    private static function serviceResult(string $name, string $state): array
    {
        // Home Assistant-agent: "update-available: Core 2026.10.3, Mosquitto"
        if (str_starts_with($state, 'update-available')) {
            return self::result(STATUS_WARN, 0, t('Updates beschikbaar') . ': ' . trim(substr($state, 17)));
        }
        if ($name === 'updates' && $state === 'active') {
            return self::result(STATUS_OK, 1, t('Alles is up-to-date'));
        }
        return $state === 'active'
            ? self::result(STATUS_OK, 1, t('{name} draait', ['name' => $name]))
            : self::result(STATUS_CRIT, 0, t('{name} is {state}', ['name' => $name, 'state' => $state]));
    }

    private static function agentStale(array $host): ?array
    {
        if ($host['agent_seen'] === null) {
            return self::result(STATUS_UNKNOWN, null, t('Agent heeft nog nooit gerapporteerd'));
        }
        if (strtotime($host['agent_seen']) < time() - agent_stale_seconds(isset($host['agent_tick']) ? (int) $host['agent_tick'] : null)) {
            return self::result(STATUS_CRIT, null, t('Agent stil sinds {t}', ['t' => date('d-m-Y H:i:s', strtotime((string) $host['agent_seen']))]));
        }
        return null;
    }

    private static function agentMetric(array $host, string $metric): ?string
    {
        $v = q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [$host['id'], $metric])->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private static function agentDisk(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $path = $check['target'] ?: '/';
        $raw = self::agentMetric($host, "disk:$path");
        if ($raw === null) {
            return self::result(STATUS_UNKNOWN, null, t('Agent meldt geen mount {path}', ['path' => $path]));
        }
        $d = json_decode($raw, true);
        if (empty($d['total'])) {
            return self::result(STATUS_UNKNOWN, null, t('Ongeldige diskdata voor {path}', ['path' => $path]));
        }
        return self::diskResult($path, (float) $d['total'], (float) $d['avail'], $check);
    }

    private static function agentService(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $state = self::agentMetric($host, 'service:' . $check['target']);
        if ($state === null) {
            $v = (string) ($host['agent_version'] ?? '');
            return self::result(STATUS_UNKNOWN, null, $v !== '' && version_compare($v, '1.20.0', '>=')
                ? t('Agent heeft service {name} nog niet gemeld (gebeurt binnen ~2 minuten na het toevoegen)', ['name' => $check['target']])
                : t('Agent meldt service {name} niet: werk de agent bij (⬆), dan bewaakt hij de services die je hier kiest', ['name' => $check['target']]));
        }
        $base = self::serviceResult($check['target'], $state);
        $opt = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        if ($base['status'] !== STATUS_OK || (int) ($opt['deep'] ?? 1) === 0) {
            return $base;
        }
        $raw = self::agentMetric($host, 'svcinfo:' . $check['target']);
        return $raw === null ? $base : self::deepService($check, $opt, json_decode($raw, true) ?: [], $host);
    }

    /**
     * Eén laag dieper dan "active": poorten, een test die bij de dienst past, herstarts, log-fouten,
     * een eigen test op de server en een extra test die bij de check is ingesteld.
     */
    private static function deepService(array $check, array $opt, array $d, array $host): array
    {
        $name = $check['target'];
        $status = STATUS_OK;
        $bad = [];     // wat niet goed is: vooraan in het bericht
        $info = [];    // overige details
        $raise = static function (int $s, string $msg) use (&$status, &$bad): void {
            $rank = [STATUS_OK => 0, STATUS_UNKNOWN => 1, STATUS_WARN => 2, STATUS_CRIT => 3];
            if ($rank[$s] > $rank[$status]) {
                $status = $s;
            }
            $bad[] = $msg;
        };

        // Hoofdproces: "active" maar niet "running" is bij gewone services verdacht (oneshot mag "exited")
        if (($d['sub'] ?? '') !== 'running' && !in_array($d['type'] ?? '', ['oneshot'], true) && ($d['sub'] ?? '') !== 'exited') {
            $raise(STATUS_WARN, t('proces') . ': ' . ($d['sub'] ?? '?'));
        }
        if (!empty($d['ports'])) {
            $info[] = t('luistert op {ports}', ['ports' => implode(', ', $d['ports'])]) . (!empty($d['manual']) ? ' (' . t('waarvan zelf opgegeven: {ports}', ['ports' => implode(', ', $d['manual'])]) . ')' : '');
        }
        // Zelf opgegeven sockets (bv. php-fpm): bestaat de socket en neemt hij verbindingen aan?
        if (!empty($d['sockets_fail'])) {
            $raise(STATUS_CRIT, 'socket ' . implode(', ', array_map(fn($x) => AgentText::tr((string) $x), $d['sockets_fail'])));
        } elseif (!empty($d['sockets'])) {
            $info[] = t('socket {s} neemt verbindingen aan', ['s' => implode(', ', $d['sockets'])]);
        }
        if (!empty($d['ports_fail'])) {
            $raise(STATUS_CRIT, t('poort {p} neemt geen verbindingen aan', ['p' => implode(', ', $d['ports_fail'])]));
        }
        // Test die bij de dienst past (HTTP, database, SSH, mail, DNS, tijd…)
        if (isset($d['probe_rc']) && $d['probe_rc'] !== null) {
            $rc = (int) $d['probe_rc'];
            if ($rc === 0) {
                $info[] = AgentText::tr((string) $d['probe_msg']);
            } else {
                $raise($rc >= 2 ? STATUS_CRIT : STATUS_WARN, AgentText::tr((string) $d['probe_msg']));
            }
        }
        if (($opt['ports'] ?? '') !== '' && empty($d['manual']) && empty($d['sockets'])) {
            $info[] = t('zelf opgegeven poorten: nog niet opgepikt (de agent haalt wijzigingen binnen 10 minuten op; agent bijgewerkt?)');
        }
        // Extra test die bij deze check in RzMon is ingesteld (URL of poort, uitgevoerd door de agent)
        if (in_array($opt['test_type'] ?? '', ['url', 'port'], true)) {
            $t = json_decode((string) self::agentMetric($host, 'svctest:' . $check['id']), true);
            if (!$t) {
                $info[] = t('extra test: nog geen resultaat (de agent haalt nieuwe tests binnen 10 minuten op)');
            } elseif ($t['ok']) {
                $info[] = AgentText::tr((string) $t['msg']);
            } else {
                $raise(STATUS_CRIT, AgentText::tr((string) $t['msg']));
            }
        }
        // Eigen test op de server (/etc/rzmon-agent/tests/<service>), in Nagios-stijl: 0/1/2
        if (isset($d['custom_rc']) && $d['custom_rc'] !== null) {
            $rc = (int) $d['custom_rc'];
            $msg = t('eigen test') . ': ' . ($d['custom_msg'] !== '' ? AgentText::tr((string) $d['custom_msg']) : "exitcode $rc");
            if ($rc === 0) {
                $info[] = $msg;
            } else {
                $raise($rc === 1 ? STATUS_WARN : ($rc === 2 ? STATUS_CRIT : STATUS_UNKNOWN), $msg);
            }
        }
        // Automatische herstarts (crash) in het afgelopen uur: vanaf 3 een WARNING
        $hist = array_values(array_filter($d['hist'] ?? [], fn($h) => $h[0] >= time() - 3600));
        $restarts = 0;
        if ($hist) {
            $first = (int) $hist[0][1];
            $last = (int) end($hist)[1];
            $restarts = max(0, $last - $first);
        }
        if ($restarts >= 3) {
            $raise(STATUS_WARN, t('{n}× herstart in het afgelopen uur', ['n' => $restarts]));
        } elseif ($restarts > 0) {
            $info[] = t('{n}× herstart in het afgelopen uur', ['n' => $restarts]);
        }
        // Foutregels in het systeemlog van het laatste kwartier
        if (isset($d['log_errors']) && $d['log_errors'] !== null) {
            if ((int) $d['log_errors'] > 0) {
                $raise(STATUS_WARN, t('{n} fout(en) in log (15 min)', ['n' => $d['log_errors']]) . ': ' . mb_substr((string) $d['log_last'], 0, 120));
            } else {
                $info[] = t('geen fouten in log');
            }
        }
        if (!empty($d['since'])) {
            $info[] = t('draait sinds {t}', ['t' => date('d-m H:i', (int) $d['since'])]);
        }

        $head = $status === STATUS_OK ? t('{name} draait', ['name' => $name]) : t('{name} draait, maar', ['name' => $name]) . ': ' . implode(' · ', $bad);
        return self::result($status, $status === STATUS_CRIT ? 0.0 : 1.0, $head . ($info ? ' · ' . implode(' · ', $info) : ''));
    }

    /** CPU-gebruik gemeten over 2 seconden via /proc/stat. */
    private static function localCpu(array $check): array
    {
        $read = static function (): ?array {
            $line = strtok((string) @file_get_contents('/proc/stat'), "\n");
            if (!$line || !str_starts_with($line, 'cpu ')) {
                return null;
            }
            $f = array_map('floatval', array_slice(preg_split('/\s+/', trim($line)), 1, 8));
            return [array_sum($f), $f[3] + ($f[4] ?? 0)]; // [totaal, idle + iowait]
        };
        $a = $read();
        sleep(2);
        $b = $read();
        if (!$a || !$b || $b[0] <= $a[0]) {
            return self::result(STATUS_UNKNOWN, null, t('Kan {path} niet uitlezen', ['path' => '/proc/stat']));
        }
        $pct = (1 - ($b[1] - $a[1]) / ($b[0] - $a[0])) * 100;
        $cores = preg_match_all('/^cpu\d+ /m', (string) file_get_contents('/proc/stat'));
        $load = sys_getloadavg() ?: [0, 0, 0];
        return self::cpuResult($pct, $load, $cores, $check);
    }

    private static function cpuResult(float $pct, array $load, int $cores, array $check): array
    {
        $pct = max(0, min(100, $pct));
        return self::result(
            self::threshold($pct, $check),
            round($pct, 1),
            sprintf('CPU %.1f%% · load %s (%d cores)', $pct, implode(' ', array_map(fn($l) => number_format((float) $l, 2), $load)), $cores)
        );
    }

    private static function localRam(array $check): array
    {
        $info = [];
        foreach (file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $info[$m[1]] = (float) $m[2] * 1024;
            }
        }
        if (empty($info['MemTotal']) || !isset($info['MemAvailable'])) {
            return self::result(STATUS_UNKNOWN, null, t('Kan {path} niet uitlezen', ['path' => '/proc/meminfo']));
        }
        return self::ramResult($info['MemTotal'], $info['MemAvailable'], $info['SwapTotal'] ?? 0, $info['SwapFree'] ?? 0, $check);
    }

    private static function ramResult(float $total, float $avail, float $swapTotal, float $swapFree, array $check): array
    {
        $pct = ($total - $avail) / $total * 100;
        $msg = sprintf(t('RAM %.1f%% gebruikt (%s vrij van %s)'), $pct, self::bytes($avail), self::bytes($total));
        if ($swapTotal > 0) {
            $msg .= sprintf(' · swap %.0f%%', ($swapTotal - $swapFree) / $swapTotal * 100);
        }
        return self::result(self::threshold($pct, $check), round($pct, 1), $msg);
    }

    private static function agentCpu(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $d = json_decode((string) self::agentMetric($host, 'cpu'), true);
        if (!isset($d['pct'])) {
            return self::result(STATUS_UNKNOWN, null, t('Agent meldt geen CPU-gegevens (agent bijwerken?)'));
        }
        return self::cpuResult((float) $d['pct'], (array) ($d['load'] ?? []), (int) ($d['cores'] ?? 0), $check);
    }

    private static function agentRam(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $d = json_decode((string) self::agentMetric($host, 'mem'), true);
        if (empty($d['total'])) {
            return self::result(STATUS_UNKNOWN, null, t('Agent meldt geen RAM-gegevens (agent bijwerken?)'));
        }
        return self::ramResult((float) $d['total'], (float) $d['avail'], (float) ($d['swap_total'] ?? 0), (float) ($d['swap_free'] ?? 0), $check);
    }

    public static function bytes(float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }
        return round($b, 1) . ' ' . $u[$i];
    }
}
