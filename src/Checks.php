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
        if ($host['mode'] === 'remote' && in_array($check['type'], ['disk', 'service', 'cpu', 'ram'], true)) {
            return self::result(STATUS_UNKNOWN, null, 'Disk/service/CPU/RAM checks vereisen host-type lokaal of agent');
        }
        try {
            return match ($check['type']) {
                'ping' => self::ping($check, $host),
                'tcp' => self::tcp($check, $host),
                'http' => self::http($check, $host),
                'ssl' => self::ssl($check, $host),
                'disk' => $host['mode'] === 'agent' ? self::agentDisk($check, $host) : self::localDisk($check),
                'service' => $host['mode'] === 'agent' ? self::agentService($check, $host) : self::localService($check),
                'cpu' => $host['mode'] === 'agent' ? self::agentCpu($check, $host) : self::localCpu($check),
                'ram' => $host['mode'] === 'agent' ? self::agentRam($check, $host) : self::localRam($check),
                default => self::result(STATUS_UNKNOWN, null, 'Onbekend checktype'),
            };
        } catch (Throwable $e) {
            return self::result(STATUS_UNKNOWN, null, 'Fout: ' . $e->getMessage());
        }
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
            return self::result(STATUS_CRIT, null, 'Ping mislukt: ' . trim(mb_substr($out, 0, 200)));
        }
        $loss = (float) $m[1];
        if ($loss >= 100) {
            return self::result(STATUS_CRIT, null, 'Host reageert niet (100% packet loss)');
        }
        $avg = preg_match('#= [\d.]+/([\d.]+)/#', $out, $r) ? (float) $r[1] : null;
        $status = $avg !== null ? self::threshold($avg, $check) : STATUS_OK;
        if ($loss > 0) {
            $status = max($status, STATUS_WARN);
        }
        return self::result($status, $avg, sprintf('%s ms gemiddeld, %s%% packet loss', $avg !== null ? round($avg, 1) : '?', $loss));
    }

    private static function tcp(array $check, array $host): array
    {
        $port = (int) $check['target'];
        if ($port < 1 || $port > 65535) {
            return self::result(STATUS_UNKNOWN, null, 'Ongeldige poort');
        }
        $start = microtime(true);
        $fp = @fsockopen($host['address'], $port, $errno, $errstr, 5);
        $ms = (microtime(true) - $start) * 1000;
        if (!$fp) {
            return self::result(STATUS_CRIT, null, "Poort $port onbereikbaar: $errstr");
        }
        fclose($fp);
        return self::result(self::threshold($ms, $check), $ms, sprintf('Poort %d open (%.1f ms)', $port, $ms));
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
            return self::result(STATUS_CRIT, null, "Geen antwoord: $err");
        }
        if ($code >= 400) {
            return self::result(STATUS_CRIT, $ms, "HTTP $code");
        }
        $status = self::threshold($ms, $check);
        $msg = sprintf('HTTP %d in %.0f ms', $code, $ms);

        // SSL-certificaat: waarschuwen < 14 dagen, kritiek < 3 dagen
        if (!empty($certs[0]['Expire date'])) {
            $expires = strtotime($certs[0]['Expire date']);
            if ($expires) {
                $days = (int) floor(($expires - time()) / 86400);
                $msg .= ", cert nog $days dagen geldig";
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
     */
    private static function ssl(array $check, array $host): array
    {
        $target = trim($check['target']) ?: $host['address'];
        $target = preg_replace('#^[a-z]+://#i', '', $target);
        $target = explode('/', $target, 2)[0];
        if (preg_match('/^\[(.+)\](?::(\d+))?$/', $target, $m)) {           // [IPv6]:poort
            [$name, $port] = [$m[1], (int) ($m[2] ?? 443)];
        } elseif (substr_count($target, ':') === 1) {
            [$name, $port] = explode(':', $target);
            $port = (int) $port;
        } else {
            [$name, $port] = [$target, 443];
        }
        if ($name === '' || $port < 1 || $port > 65535) {
            return self::result(STATUS_UNKNOWN, null, 'Ongeldig adres; gebruik host of host:poort');
        }
        $verify = (int) ($check['verify_ssl'] ?? 1) === 1;

        $fetch = static function (bool $verify) use ($name, $port): array {
            $ctx = stream_context_create(['ssl' => [
                'capture_peer_cert' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $name,
            ]]);
            $remote = 'ssl://' . (str_contains($name, ':') ? "[$name]" : $name) . ":$port";
            $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
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
                $invalid = 'certificaat niet vertrouwd of naam klopt niet';
            }
        }
        if (!$info) {
            return self::result(STATUS_CRIT, null, "Geen TLS-verbinding met $name:$port" . ($err ? ': ' . trim(preg_replace('/^.*?: /', '', $err)) : ''));
        }

        $days = ($info['validTo_time_t'] - time()) / 86400;
        $cn = $info['subject']['CN'] ?? '?';
        $issuer = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '?');
        $msg = sprintf('%s geldig tot %s (%s) · %s', $cn, date('d-m-Y', $info['validTo_time_t']),
            $days < 0 ? 'VERLOPEN' : 'nog ' . (int) floor($days) . ' dagen', is_array($issuer) ? implode(', ', $issuer) : $issuer);

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
            $msg = "Ongeldig: $invalid (self-signed? zet 'certificaat controleren' uit) · $msg";
        }
        return self::result($status, floor($days * 10) / 10, $msg);  // afronden naar beneden, net als de tekst
    }

    private static function localDisk(array $check): array
    {
        $path = $check['target'] ?: '/';
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!$total || $free === false) {
            return self::result(STATUS_UNKNOWN, null, "Kan $path niet uitlezen");
        }
        return self::diskResult($path, (float) $total, (float) $free, $check);
    }

    private static function diskResult(string $path, float $total, float $free, array $check): array
    {
        $pct = ($total - $free) / $total * 100;
        return self::result(
            self::threshold($pct, $check),
            round($pct, 1),
            sprintf('%s: %.1f%% gebruikt (%s vrij van %s)', $path, $pct, self::bytes($free), self::bytes($total))
        );
    }

    private static function localService(array $check): array
    {
        $name = $check['target'];
        if (!preg_match('/^[A-Za-z0-9@._:-]+$/', $name)) {
            return self::result(STATUS_UNKNOWN, null, 'Ongeldige servicenaam');
        }
        $state = trim(strtok((string) shell_exec('systemctl is-active ' . escapeshellarg($name) . ' 2>&1'), "\n") ?: 'unknown');
        return self::serviceResult($name, $state);
    }

    private static function serviceResult(string $name, string $state): array
    {
        // Home Assistant-agent: "update-available: Core 2026.10.3, Mosquitto"
        if (str_starts_with($state, 'update-available')) {
            return self::result(STATUS_WARN, 0, 'Updates beschikbaar: ' . trim(substr($state, 17)));
        }
        if ($name === 'updates' && $state === 'active') {
            return self::result(STATUS_OK, 1, 'Alles is up-to-date');
        }
        return $state === 'active'
            ? self::result(STATUS_OK, 1, "$name draait")
            : self::result(STATUS_CRIT, 0, "$name is $state");
    }

    private static function agentStale(array $host): ?array
    {
        $staleMin = (int) setting('agent_stale_minutes', 5);
        if ($host['agent_seen'] === null) {
            return self::result(STATUS_UNKNOWN, null, 'Agent heeft nog nooit gerapporteerd');
        }
        if (strtotime($host['agent_seen']) < time() - $staleMin * 60) {
            return self::result(STATUS_CRIT, null, 'Agent stil sinds ' . $host['agent_seen']);
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
            return self::result(STATUS_UNKNOWN, null, "Agent meldt geen mount $path");
        }
        $d = json_decode($raw, true);
        if (empty($d['total'])) {
            return self::result(STATUS_UNKNOWN, null, "Ongeldige diskdata voor $path");
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
            return self::result(STATUS_UNKNOWN, null, "Agent meldt service {$check['target']} niet (staat hij in SERVICES?)");
        }
        return self::serviceResult($check['target'], $state);
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
            return self::result(STATUS_UNKNOWN, null, 'Kan /proc/stat niet uitlezen');
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
            return self::result(STATUS_UNKNOWN, null, 'Kan /proc/meminfo niet uitlezen');
        }
        return self::ramResult($info['MemTotal'], $info['MemAvailable'], $info['SwapTotal'] ?? 0, $info['SwapFree'] ?? 0, $check);
    }

    private static function ramResult(float $total, float $avail, float $swapTotal, float $swapFree, array $check): array
    {
        $pct = ($total - $avail) / $total * 100;
        $msg = sprintf('RAM %.1f%% gebruikt (%s vrij van %s)', $pct, self::bytes($avail), self::bytes($total));
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
            return self::result(STATUS_UNKNOWN, null, 'Agent meldt geen CPU-gegevens (agent bijwerken?)');
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
            return self::result(STATUS_UNKNOWN, null, 'Agent meldt geen RAM-gegevens (agent bijwerken?)');
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
