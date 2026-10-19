<?php
declare(strict_types=1);

/** Snelle TCP-poortscan met parallelle non-blocking connects. */
final class PortScan
{
    public const MAX_PORTS = 4096;

    public const SERVICES = [
        20 => 'FTP-data', 21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 67 => 'DHCP',
        80 => 'HTTP', 81 => 'HTTP-alt', 88 => 'Kerberos', 110 => 'POP3', 111 => 'RPC', 123 => 'NTP', 135 => 'MS-RPC',
        139 => 'NetBIOS', 143 => 'IMAP', 161 => 'SNMP', 389 => 'LDAP', 443 => 'HTTPS', 445 => 'SMB', 465 => 'SMTPS',
        514 => 'Syslog', 515 => 'LPD', 548 => 'AFP', 554 => 'RTSP', 587 => 'SMTP-submission', 631 => 'IPP/CUPS',
        636 => 'LDAPS', 873 => 'rsync', 993 => 'IMAPS', 995 => 'POP3S', 1080 => 'SOCKS', 1194 => 'OpenVPN',
        1433 => 'MSSQL', 1521 => 'Oracle', 1883 => 'MQTT', 1900 => 'UPnP', 2049 => 'NFS', 2375 => 'Docker',
        2376 => 'Docker TLS', 3000 => 'Grafana', 3128 => 'Squid', 3306 => 'MySQL/MariaDB', 3389 => 'RDP',
        3478 => 'STUN/TURN', 4369 => 'Erlang EPMD', 5000 => 'Synology/UPnP', 5001 => 'Synology HTTPS',
        5060 => 'SIP', 5353 => 'mDNS', 5432 => 'PostgreSQL', 5601 => 'Kibana', 5672 => 'RabbitMQ', 5900 => 'VNC',
        5938 => 'TeamViewer', 6379 => 'Redis', 6443 => 'Kubernetes', 6052 => 'ESPHome', 7681 => 'ttyd',
        8000 => 'HTTP-alt', 8006 => 'Proxmox', 8008 => 'HTTP-alt', 8080 => 'HTTP-proxy', 8081 => 'HTTP-alt',
        8083 => 'HTTP-alt', 8086 => 'InfluxDB', 8096 => 'Jellyfin', 8123 => 'Home Assistant', 8200 => 'Vault',
        8291 => 'MikroTik Winbox', 8443 => 'HTTPS-alt', 8883 => 'MQTT TLS', 8888 => 'HTTP-alt', 9000 => 'Portainer',
        9090 => 'Prometheus/Cockpit', 9091 => 'Transmission', 9100 => 'Node exporter/printer', 9200 => 'Elasticsearch',
        9443 => 'Portainer HTTPS', 10000 => 'Webmin', 11211 => 'Memcached', 19999 => 'Netdata', 27017 => 'MongoDB',
        32400 => 'Plex', 51820 => 'WireGuard',
    ];

    public const PRESETS = [
        'common' => 'Bekende poorten',
        'top1024' => 'Poort 1-1024',
        'custom' => 'Eigen lijst/range',
    ];

    /** "22, 80, 8000-8100" → [22, 80, 8000, ...] */
    public static function parsePorts(string $spec): array
    {
        $ports = [];
        foreach (preg_split('/[\s,;]+/', trim($spec)) as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $part, $m)) {
                [$a, $b] = [(int) $m[1], (int) $m[2]];
                if ($a > $b) {
                    [$a, $b] = [$b, $a];
                }
                if ($b - $a > self::MAX_PORTS) {
                    throw new InvalidArgumentException('Range te groot (max ' . self::MAX_PORTS . ' poorten)');
                }
                $ports = array_merge($ports, range($a, $b));
            } elseif (ctype_digit($part)) {
                $ports[] = (int) $part;
            } else {
                throw new InvalidArgumentException("Ongeldige poort: $part");
            }
        }
        $ports = array_values(array_unique(array_filter($ports, fn($p) => $p >= 1 && $p <= 65535)));
        if (count($ports) > self::MAX_PORTS) {
            throw new InvalidArgumentException('Maximaal ' . self::MAX_PORTS . ' poorten per scan');
        }
        sort($ports);
        return $ports;
    }

    public static function presetPorts(string $preset, string $custom = ''): array
    {
        return match ($preset) {
            'common' => array_keys(self::SERVICES),
            'top1024' => range(1, 1024),
            default => self::parsePorts($custom),
        };
    }

    public static function serviceName(int $port): string
    {
        return self::SERVICES[$port] ?? (getservbyport($port, 'tcp') ?: '');
    }

    /**
     * @return array<int, array{port:int, service:string, ms:float}>
     */
    public static function scan(string $host, array $ports, float $timeout = 1.5, int $parallel = 200): array
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Kan $host niet resolven");
        }
        $addr = str_contains($ip, ':') ? "[$ip]" : $ip;
        $open = [];

        foreach (array_chunk($ports, $parallel) as $batch) {
            $pending = [];
            $start = microtime(true);
            foreach ($batch as $port) {
                $s = @stream_socket_client("tcp://$addr:$port", $errno, $errstr, $timeout,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if ($s) {
                    stream_set_blocking($s, false);
                    $pending[(int) $s] = [$s, $port];
                }
            }
            while ($pending && ($left = $timeout - (microtime(true) - $start)) > 0) {
                $write = array_column($pending, 0);
                $read = $except = null;
                if (!@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6))) {
                    break;
                }
                foreach ($write as $s) {
                    [, $port] = $pending[(int) $s];
                    // Alleen een echte verbinding heeft een remote adres; "refused" niet
                    if (@stream_socket_get_name($s, true) !== false) {
                        $open[] = ['port' => $port, 'service' => self::serviceName($port), 'ms' => round((microtime(true) - $start) * 1000, 1)];
                    }
                    fclose($s);
                    unset($pending[(int) $s]);
                }
            }
            foreach ($pending as [$s]) {
                fclose($s);
            }
        }
        usort($open, fn($a, $b) => $a['port'] <=> $b['port']);
        return $open;
    }
}
