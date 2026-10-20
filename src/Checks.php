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
        if ($host['mode'] !== 'agent' && in_array($check['type'], ['podman', 'container'], true)) {
            return self::result(STATUS_UNKNOWN, null, 'Podman-checks werken via de agent: zet de server op type "Remote met agent" en installeer de agent');
        }
        try {
            return match ($check['type']) {
                'ping' => self::ping($check, $host),
                'tcp' => self::tcp($check, $host),
                'http' => self::http($check, $host),
                'ssl' => self::ssl($check, $host),
                'podman' => self::podman($check, $host),
                'container' => self::container($check, $host),
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
     * Op een agent-server komen certificaten die de agent meldt (bestanden, of host:poort gezien
     * vanaf die server) van de agent; al het andere meet RzMon zelf via het netwerk.
     */
    private static function ssl(array $check, array $host): array
    {
        $target = trim($check['target']) ?: $host['address'];
        if ($host['mode'] === 'agent' && $host['id']) {
            // De agent meldt bestanden met hun pad en adressen als host:poort
            $hp = $target[0] === '/' ? null : self::hostPort($target);
            $metric = $target[0] === '/' ? "cert:$target" : ($hp ? 'cert:' . self::fmtHostPort($hp) : null);
            if ($metric !== null && ($raw = self::agentMetric($host, $metric)) !== null) {
                return self::agentSsl($check, $host, json_decode($raw, true) ?: []);
            }
            if ($target[0] === '/') {
                return ($stale = self::agentStale($host)) ? $stale
                    : self::result(STATUS_UNKNOWN, null, "Agent meldt certificaat $target niet (staat het in CERTS op de server?)");
            }
        }
        $hp = self::hostPort($target);
        if ($hp === null) {
            return self::result(STATUS_UNKNOWN, null, 'Ongeldig adres; gebruik host of host:poort');
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
                $invalid = 'certificaat niet vertrouwd of naam klopt niet';
            }
        }
        if (!$info) {
            return self::result(STATUS_CRIT, null, "Geen TLS-verbinding met $name:$port" . ($err ? ': ' . trim(preg_replace('/^.*?: /', '', $err)) : ''));
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
            return self::result(STATUS_CRIT, null, 'Agent: ' . ($d['error'] ?: 'geen certificaatgegevens'));
        }
        $invalid = null;
        if ((int) ($check['verify_ssl'] ?? 1) === 1 && isset($d['verify']) && $d['verify'] !== 'ok') {
            $invalid = 'certificaat niet vertrouwd (' . $d['verify'] . ')';
        }
        $r = self::certResult($check, (int) $d['not_after'], (string) ($d['cn'] ?: '?'), (string) ($d['issuer'] ?: '?'), $invalid);
        $r['message'] .= ' · via agent';
        return $r;
    }

    /** Status bij een vervaldatum: dagen-drempels ("onder"), verlopen of ongeldig = CRITICAL. */
    private static function certResult(array $check, int $notAfter, string $cn, string $issuer, ?string $invalid): array
    {
        $days = ($notAfter - time()) / 86400;
        $msg = sprintf('%s geldig tot %s (%s) · %s', $cn, date('d-m-Y', $notAfter),
            $days < 0 ? 'VERLOPEN' : 'nog ' . (int) floor($days) . ' dagen', $issuer);

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
            $msg = "Ongeldig: $invalid — bewust self-signed? Zet dan 'certificaat controleren' uit · $msg";
        }
        return self::result($status, floor($days * 10) / 10, $msg);  // afronden naar beneden, net als de tekst
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
    private static function podman(array $check, array $host): array
    {
        if ($stale = self::agentStale($host)) {
            return $stale;
        }
        $raw = self::agentMetric($host, 'podman');
        if ($raw === null) {
            return self::result(STATUS_UNKNOWN, null, 'Agent meldt geen Podman (staat podman op de server, en is de agent bijgewerkt?)');
        }
        $p = json_decode($raw, true) ?: [];
        $all = $p['containers'] ?? [];
        $running = array_filter($all, fn($c) => ($c['state'] ?? '') === 'running');
        $problems = [];
        foreach ($all as $c) {
            $name = (($c['user'] ?? 'root') !== 'root' ? $c['user'] . '/' : '') . $c['name'];
            $state = $c['state'] ?? '';
            if ($state === 'running' && ($c['health'] ?? '') === 'unhealthy') {
                $problems[] = "$name ongezond";
            } elseif ($state === 'paused') {
                $problems[] = "$name gepauzeerd";
            } elseif ($state !== 'running' && (int) ($c['exit_code'] ?? 0) !== 0) {
                $problems[] = "$name gestopt (exit {$c['exit_code']})";
            }
        }
        foreach ($p['pods'] ?? [] as $pod) {
            if (!in_array($pod['status'] ?? '', ['Running', 'Exited', 'Created'], true)) {
                $problems[] = "pod {$pod['name']}: " . ($pod['status'] ?? '?');
            }
        }
        $msg = sprintf('Podman %s · %d van %d container(s) draaien', $p['version'] ?? '?', count($running), count($all));
        if (!empty($p['pods'])) {
            $msg .= ' · ' . count($p['pods']) . ' pod(s)';
        }
        $msg .= sprintf(' · images %s, volumes %s', self::siBytes((float) ($p['images_size'] ?? 0)), self::siBytes((float) ($p['volumes_size'] ?? 0)));
        if (($p['reclaimable'] ?? 0) > 0) {
            $msg .= ' (' . self::siBytes((float) $p['reclaimable']) . ' op te ruimen)';
        }
        if (($p['error'] ?? '') !== '') {
            return self::result(count($all) ? STATUS_WARN : STATUS_CRIT, (float) count($running), "Podman-fout: {$p['error']} · $msg");
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
                self::agentMetric($host, 'podman') === null ? 'Agent meldt geen Podman (agent bijwerken?)' : "Container $name bestaat niet (meer)");
        }
        $c = json_decode($raw, true) ?: [];
        $state = (string) ($c['state'] ?? 'unknown');
        $running = $state === 'running';
        $metric = $check['metric'] ?: 'status';
        $memText = isset($c['mem']) ? self::siBytes((float) $c['mem']) . (!empty($c['mem_limit']) ? ' / ' . self::siBytes((float) $c['mem_limit']) : '') : null;

        if ($metric === 'status') {
            $restarts = (int) ($c['restarts'] ?? 0);
            $up = !empty($c['started']) && $running ? ' · draait sinds ' . date('d-m H:i', (int) $c['started']) : '';
            $extra = ($c['pod'] ?? '') !== '' ? " · pod {$c['pod']}" : '';
            if ($running && ($c['health'] ?? '') === 'unhealthy') {
                return self::result(STATUS_CRIT, (float) $restarts, "$name draait maar is ONGEZOND (healthcheck faalt)$extra");
            }
            if ($state === 'paused') {
                return self::result(STATUS_WARN, (float) $restarts, "$name is gepauzeerd$extra");
            }
            if (!$running) {
                return self::result(STATUS_CRIT, (float) $restarts, "$name is gestopt ($state, exitcode " . (int) ($c['exit_code'] ?? 0) . ")$extra");
            }
            // Automatisch herstart (restart-policy) in het afgelopen kwartier: WARNING, lang genoeg voor
            // "alarm na N fouten". Een crashende container blijft zo WARNING. Handmatig herstarten telt niet.
            if ($restarts > 0 && !empty($c['started']) && (int) $c['started'] > time() - 900) {
                $min = max(1, (int) round((time() - (int) $c['started']) / 60));
                return self::result(STATUS_WARN, (float) $restarts, "$name is $min min geleden automatisch herstart (totaal {$restarts}×)$extra");
            }
            $health = ($c['health'] ?? '') !== '' ? " ({$c['health']})" : '';
            return self::result(STATUS_OK, (float) $restarts, "$name draait$health$up"
                . (isset($c['cpu']) ? sprintf(' · CPU %.1f%%', $c['cpu']) : '') . ($memText ? " · RAM $memText" : '') . $extra);
        }

        if ($metric === 'disk') {
            $bytes = (float) ($c['size_rw'] ?? 0) + (float) ($c['volumes'] ?? 0);
            $mb = round($bytes / 1e6, 1);
            return self::result(self::threshold($mb, $check), $mb, sprintf('%s: %s (container %s, volumes %s)', $name, self::siBytes($bytes),
                self::siBytes((float) ($c['size_rw'] ?? 0)), self::siBytes((float) ($c['volumes'] ?? 0))));
        }

        // cpu / mem: alleen zinvol als de container draait (de status-check meldt het stoppen)
        if (!$running) {
            return self::result(STATUS_UNKNOWN, null, "$name draait niet ($state)");
        }
        if ($metric === 'cpu') {
            if (!isset($c['cpu'])) {
                return self::result(STATUS_UNKNOWN, null, "Geen CPU-gegevens voor $name (rootless zonder cgroups v2?)");
            }
            $pct = round((float) $c['cpu'], 1);
            return self::result(self::threshold($pct, $check), $pct, sprintf('%s: CPU %.1f%% (100%% = één core)%s', $name, $pct,
                isset($c['pids']) ? " · {$c['pids']} processen" : ''));
        }
        if (empty($c['mem_limit']) || !isset($c['mem'])) {
            return self::result(STATUS_UNKNOWN, null, "Geen geheugengegevens voor $name (rootless zonder cgroups v2?)");
        }
        $pct = round((float) $c['mem'] / (float) $c['mem_limit'] * 100, 1);
        return self::result(self::threshold($pct, $check), $pct, sprintf('%s: RAM %.1f%% (%s)', $name, $pct, $memText));
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
            $raise(STATUS_WARN, 'proces: ' . ($d['sub'] ?? '?'));
        }
        if (!empty($d['ports'])) {
            $info[] = 'luistert op ' . implode(', ', $d['ports']);
        }
        if (!empty($d['ports_fail'])) {
            $raise(STATUS_CRIT, 'poort ' . implode(', ', $d['ports_fail']) . ' neemt geen verbindingen aan');
        }
        // Test die bij de dienst past (HTTP, database, SSH, mail, DNS, tijd…)
        if (isset($d['probe_rc']) && $d['probe_rc'] !== null) {
            $rc = (int) $d['probe_rc'];
            if ($rc === 0) {
                $info[] = (string) $d['probe_msg'];
            } else {
                $raise($rc >= 2 ? STATUS_CRIT : STATUS_WARN, (string) $d['probe_msg']);
            }
        }
        // Extra test die bij deze check in RzMon is ingesteld (URL of poort, uitgevoerd door de agent)
        if (in_array($opt['test_type'] ?? '', ['url', 'port'], true)) {
            $t = json_decode((string) self::agentMetric($host, 'svctest:' . $check['id']), true);
            if (!$t) {
                $info[] = 'extra test: nog geen resultaat (de agent haalt nieuwe tests binnen 10 minuten op)';
            } elseif ($t['ok']) {
                $info[] = $t['msg'];
            } else {
                $raise(STATUS_CRIT, $t['msg']);
            }
        }
        // Eigen test op de server (/etc/rzmon-agent/tests/<service>), in Nagios-stijl: 0/1/2
        if (isset($d['custom_rc']) && $d['custom_rc'] !== null) {
            $rc = (int) $d['custom_rc'];
            $msg = 'eigen test: ' . ($d['custom_msg'] !== '' ? $d['custom_msg'] : "exitcode $rc");
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
            $raise(STATUS_WARN, "{$restarts}× herstart in het afgelopen uur");
        } elseif ($restarts > 0) {
            $info[] = "{$restarts}× herstart in het afgelopen uur";
        }
        // Foutregels in het systeemlog van het laatste kwartier
        if (isset($d['log_errors']) && $d['log_errors'] !== null) {
            if ((int) $d['log_errors'] > 0) {
                $raise(STATUS_WARN, "{$d['log_errors']} fout(en) in log (15 min): " . mb_substr((string) $d['log_last'], 0, 120));
            } else {
                $info[] = 'geen fouten in log';
            }
        }
        if (!empty($d['since'])) {
            $info[] = 'draait sinds ' . date('d-m H:i', (int) $d['since']);
        }

        $head = $status === STATUS_OK ? "$name draait" : "$name draait, maar: " . implode(' · ', $bad);
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
