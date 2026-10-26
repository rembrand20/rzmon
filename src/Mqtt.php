<?php
declare(strict_types=1);

/**
 * Publiceert de status van RzMon naar een MQTT-broker, met Home Assistant MQTT discovery:
 * elke server wordt een apparaat in HA met sensoren voor zijn checks.
 *
 * Minimale MQTT 3.1.1-client (alleen publiceren, QoS 0), zonder extra bibliotheken.
 * Wordt elke minuut door cron/run.php aangeroepen.
 */
final class Mqtt
{
    /** Sensoren worden "niet beschikbaar" in HA als RzMon zo lang niets publiceert (cron gestopt?). */
    private const EXPIRE_AFTER = 600;
    private const STATES = ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN', 'ONDERHOUD'];
    private const ICONS = ['disk' => 'mdi:harddisk', 'cpu' => 'mdi:cpu-64-bit', 'ram' => 'mdi:memory', 'container' => 'mdi:docker'];
    private const RANK = [0 => 0, 3 => 1, 1 => 2, 2 => 3];

    /** @var resource */
    private $fp;

    // ------------------------------------------------------------------ protocol

    private function __construct(array $cfg)
    {
        $tls = $cfg['mqtt_tls'] === '1';
        $port = (int) $cfg['mqtt_port'] ?: ($tls ? 8883 : 1883);
        $verify = $cfg['mqtt_verify_ssl'] !== '0';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'peer_name' => $cfg['mqtt_host'],
        ]]);
        $fp = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $cfg['mqtt_host'] . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException(t('Geen verbinding met {addr}', ['addr' => "{$cfg['mqtt_host']}:$port"]) . ($errstr ? " ($errstr)" : '')
                . ($tls && $verify ? ' — ' . t('TLS-certificaat niet vertrouwd? Bij self-signed: zet certificaatcontrole uit') : ''));
        }
        $this->fp = $fp;
        stream_set_timeout($fp, 10);

        $flags = 0x02; // clean session
        $payload = self::str('rzmon-' . bin2hex(random_bytes(4)));
        if ($cfg['mqtt_user'] !== '') {
            $flags |= 0x80;
            $payload .= self::str($cfg['mqtt_user']);
            if ($cfg['mqtt_pass'] !== '') {
                $flags |= 0x40;
                $payload .= self::str($cfg['mqtt_pass']);
            }
        }
        $this->packet(0x10, self::str('MQTT') . chr(4) . chr($flags) . pack('n', 30) . $payload);

        $ack = $this->read(4);
        if (strlen($ack) < 4 || ord($ack[0]) !== 0x20) {
            throw new RuntimeException(t('Geen geldig antwoord van de broker (is dit wel een MQTT-poort?') . ($tls ? '' : ' ' . t('Of verwacht de broker TLS?')) . ')');
        }
        $rc = ord($ack[3]);
        if ($rc !== 0) {
            throw new RuntimeException(t('Broker weigert de verbinding') . ': ' . t([
                1 => 'protocolversie niet ondersteund',
                2 => 'client-ID geweigerd',
                3 => 'broker niet beschikbaar',
                4 => 'gebruikersnaam of wachtwoord onjuist',
                5 => 'geen toegang (gebruiker/wachtwoord nodig of niet toegestaan)',
            ][$rc] ?? "code $rc"));
        }
    }

    private static function str(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private function packet(int $type, string $body): void
    {
        $len = strlen($body);
        $enc = '';
        do {
            $byte = $len % 128;
            $len = intdiv($len, 128);
            $enc .= chr($len > 0 ? $byte | 0x80 : $byte);
        } while ($len > 0);
        $data = chr($type) . $enc . $body;
        for ($done = 0; $done < strlen($data);) {
            $n = @fwrite($this->fp, substr($data, $done));
            if (!$n) {
                throw new RuntimeException(t('Verbinding met de broker verbroken'));
            }
            $done += $n;
        }
    }

    private function read(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n && !feof($this->fp)) {
            $chunk = fread($this->fp, $n - strlen($buf));
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($this->fp)['timed_out'])) {
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function publish(string $topic, string $payload, bool $retain = true): void
    {
        $this->packet(0x30 | ($retain ? 1 : 0), self::str($topic) . $payload);
    }

    /** PINGREQ/PINGRESP: bevestigt dat de broker alle berichten hiervoor heeft verwerkt. */
    private function close(): void
    {
        $this->packet(0xC0, '');
        $resp = $this->read(2);
        $this->packet(0xE0, '');
        fclose($this->fp);
        if ($resp !== "\xD0\x00") {
            throw new RuntimeException(t('De broker heeft de verbinding verbroken (geen schrijfrechten op deze topics?)'));
        }
    }

    // ------------------------------------------------------------------ RzMon → Home Assistant

    public static function config(): array
    {
        $out = [];
        foreach (['mqtt_host' => '', 'mqtt_port' => '1883', 'mqtt_tls' => '0', 'mqtt_verify_ssl' => '1', 'mqtt_user' => '',
                     'mqtt_pass' => '', 'mqtt_prefix' => 'homeassistant', 'mqtt_base_topic' => 'rzmon'] as $k => $d) {
            $out[$k] = trim((string) setting($k, $d));
        }
        return $out;
    }

    /**
     * Status van alle servers en checks publiceren; discovery bij wijzigingen of elk uur.
     * @return array{ok: bool, error: ?string, entities: int, hosts: int}
     */
    public static function publishAll(?array $cfg = null, bool $forceDiscovery = false): array
    {
        $cfg ??= self::config();
        $cfg['mqtt_prefix'] = trim($cfg['mqtt_prefix'], '/') ?: 'homeassistant';
        $cfg['mqtt_base_topic'] = trim($cfg['mqtt_base_topic'], '/') ?: 'rzmon';
        if ($cfg['mqtt_host'] === '') {
            return ['ok' => false, 'error' => t('Geen MQTT-server ingesteld'), 'entities' => 0, 'hosts' => 0];
        }
        try {
            [$discovery, $states] = self::build($cfg);
            $hash = md5(json_encode($discovery));
            $doDiscovery = $forceDiscovery || $hash !== setting('mqtt_hash', '')
                || strtotime((string) setting('mqtt_discovery_at', '')) < time() - 3600;

            $m = new self($cfg);
            $current = array_merge(array_keys($discovery), array_keys($states));
            if ($doDiscovery) {
                // Verwijderde servers/checks ook uit HA halen (leeg retained bericht)
                $old = json_decode((string) setting('mqtt_topics', '[]'), true) ?: [];
                foreach (array_diff($old, $current) as $topic) {
                    $m->publish($topic, '');
                }
                foreach ($discovery as $topic => $payload) {
                    $m->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
            foreach ($states as $topic => $payload) {
                $m->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $m->close();

            if ($doDiscovery) {
                save_setting('mqtt_topics', json_encode($current));
                save_setting('mqtt_hash', $hash);
                save_setting('mqtt_discovery_at', now());
            }
            save_setting('mqtt_last_ok', now());
            save_setting('mqtt_last_error', '');
            $hosts = count(array_filter(array_keys($states), fn($t) => str_contains($t, '/host/')));
            return ['ok' => true, 'error' => null, 'entities' => count($discovery), 'hosts' => $hosts];
        } catch (RuntimeException $e) {
            save_setting('mqtt_last_error', mb_substr($e->getMessage(), 0, 250));
            return ['ok' => false, 'error' => $e->getMessage(), 'entities' => 0, 'hosts' => 0];
        }
    }

    /** @return array{0: array<string, array>, 1: array<string, array>} discovery-configs en statusberichten per topic */
    private static function build(array $cfg): array
    {
        $base = $cfg['mqtt_base_topic'];
        $prefix = $cfg['mqtt_prefix'];
        $node = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
        $url = rtrim((string) config('base_url', ''), '/');
        $iso = static fn(?string $dt) => $dt ? date('c', strtotime($dt)) : null;

        $hub = ['identifiers' => ["{$node}_hub"], 'name' => 'RzMon', 'manufacturer' => 'RzMon', 'model' => 'Monitoring',
            'sw_version' => RZMON_VERSION] + ($url !== '' ? ['configuration_url' => $url . '/'] : []);
        $disc = [];
        $states = [];
        $entity = static function (string $component, string $object, array $device, array $conf) use (&$disc, $prefix, $node): void {
            $disc["$prefix/$component/$node/$object/config"] = $conf + [
                'unique_id' => "{$node}_$object",
                'device' => $device,
                'expire_after' => self::EXPIRE_AFTER,
            ];
        };

        $hosts = q('SELECT * FROM hosts WHERE enabled = 1 ORDER BY name')->fetchAll();
        $checks = q('SELECT c.*, h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                     FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND h.enabled = 1 ORDER BY c.name')->fetchAll();
        $byHost = [];
        foreach ($checks as $c) {
            $byHost[$c['host_id']][] = $c;
        }

        $total = [0, 0, 0, 0, 0];
        foreach ($hosts as $h) {
            $hid = (int) $h['id'];
            $device = ['identifiers' => ["{$node}_host_$hid"], 'name' => $h['name'], 'manufacturer' => 'RzMon',
                'model' => t(['local' => 'Lokale server', 'agent' => 'Server met agent', 'remote' => 'Netwerkapparaat'][$h['mode']] ?? $h['mode']),
                'via_device' => "{$node}_hub"] + ($h['agent_version'] ? ['sw_version' => 'agent ' . $h['agent_version']] : [])
                + ($url !== '' ? ['configuration_url' => $url . '/'] : []);
            $n = [0, 0, 0, 0, 0];
            $worst = null;
            foreach ($byHost[$hid] ?? [] as $c) {
                $cid = (int) $c['id'];
                $maint = Monitor::maintenanceFor($c);
                $st = $maint ? 4 : ((int) $c['info_only'] ? STATUS_OK : (int) $c['status']);   // info-check: telt als OK
                $n[$st]++;
                $total[$st]++;
                // Ernst: OK < UNKNOWN < WARNING < CRITICAL (onderhoud telt niet mee)
                if ($st !== 4 && ($worst === null || self::RANK[$st] > self::RANK[$worst])) {
                    $worst = $st;
                }
                $topic = "$base/check/$cid";
                $states[$topic] = [
                    'status' => self::STATES[$maint ? 4 : (int) $c['status']],
                    'info' => (bool) $c['info_only'],
                    'value' => $c['last_value'] !== null ? round((float) $c['last_value'], 2) : null,
                    'message' => (string) $c['last_message'],
                    'type' => $c['type'],
                    'target' => $c['target'],
                    'last_checked' => $iso($c['last_checked']),
                    'acknowledged' => $c['acked_at'] !== null,
                    'maintenance' => $maint !== null,
                ];
                // HA kent "d" voor dagen; de rest is gelijk
                $unit = check_unit($c) === 'dagen' ? 'd' : check_unit($c);
                $entity('sensor', "check_{$cid}_status", $device, [
                    'name' => $c['name'] . ($unit !== null ? ' status' : ''),
                    'state_topic' => $topic,
                    'value_template' => '{{ value_json.status }}',
                    'json_attributes_topic' => $topic,
                    'device_class' => 'enum',
                    'options' => self::STATES,
                    'icon' => 'mdi:list-status',
                ]);
                if ($unit !== null) {
                    $looks = match ($unit) {
                        'ms', 'd' => ['device_class' => 'duration'],
                        'MB' => ['device_class' => 'data_size'],
                        default => ['icon' => self::ICONS[$c['type']] ?? 'mdi:gauge'],
                    };
                    $entity('sensor', "check_{$cid}_value", $device, [
                        'name' => $c['name'],
                        'state_topic' => $topic,
                        'value_template' => '{{ value_json.value }}',
                        'unit_of_measurement' => $unit,
                        'state_class' => 'measurement',
                    ] + $looks);
                }
            }
            $hostStatus = $worst ?? ($n[4] ? 4 : 3);
            $topic = "$base/host/$hid";
            $states[$topic] = [
                'status' => self::STATES[$hostStatus],
                'problem' => in_array($hostStatus, [1, 2], true) ? 'ON' : 'OFF',
                'ok' => $n[0], 'warning' => $n[1], 'critical' => $n[2], 'unknown' => $n[3], 'maintenance' => $n[4],
                'address' => $h['address'],
                'agent_seen' => $iso($h['agent_seen']),
            ];
            $entity('binary_sensor', "host_{$hid}_problem", $device, [
                'name' => t('Probleem'), 'state_topic' => $topic, 'value_template' => '{{ value_json.problem }}',
                'device_class' => 'problem', 'json_attributes_topic' => $topic,
            ]);
            $entity('sensor', "host_{$hid}_status", $device, [
                'name' => 'Status', 'state_topic' => $topic, 'value_template' => '{{ value_json.status }}',
                'device_class' => 'enum', 'options' => self::STATES, 'icon' => 'mdi:server',
            ]);
        }

        $topic = "$base/summary";
        $states[$topic] = [
            'status' => $total[2] ? 'CRITICAL' : ($total[1] ? 'WARNING' : ($total[3] ? 'UNKNOWN' : 'OK')),
            'problem' => $total[1] + $total[2] > 0 ? 'ON' : 'OFF',
            'ok' => $total[0], 'warning' => $total[1], 'critical' => $total[2], 'unknown' => $total[3], 'maintenance' => $total[4],
            'last_run' => date('c'),
            'version' => RZMON_VERSION,
        ];
        $entity('binary_sensor', 'summary_problem', $hub, ['name' => t('Probleem'), 'state_topic' => $topic,
            'value_template' => '{{ value_json.problem }}', 'device_class' => 'problem', 'json_attributes_topic' => $topic]);
        $entity('sensor', 'summary_critical', $hub, ['name' => t('Kritiek'), 'state_topic' => $topic,
            'value_template' => '{{ value_json.critical }}', 'state_class' => 'measurement', 'icon' => 'mdi:alert-octagon']);
        $entity('sensor', 'summary_warning', $hub, ['name' => t('Waarschuwingen'), 'state_topic' => $topic,
            'value_template' => '{{ value_json.warning }}', 'state_class' => 'measurement', 'icon' => 'mdi:alert']);
        $entity('sensor', 'summary_last_run', $hub, ['name' => t('Laatste controle'), 'state_topic' => $topic,
            'value_template' => '{{ value_json.last_run }}', 'device_class' => 'timestamp', 'entity_category' => 'diagnostic']);


        // Algehele gezondheid: toestand als status, score en details als attributen
        $topic = "$base/health";
        $cache = json_decode((string) setting('stats_cache', ''), true);
        $health = Health::current(is_array($cache['uptime'] ?? null) ? $cache['uptime'] : null);
        $states[$topic] = ['status' => $health['label']] + $health;
        $entity('sensor', 'health', $hub, ['name' => t('Algehele status'), 'state_topic' => $topic,
            'value_template' => '{{ value_json.status }}', 'json_attributes_topic' => $topic,
            // Zelfde taal als de status zelf (die wordt vertaald), anders weigert Home Assistant de waarde (1.76.2)
            'device_class' => 'enum', 'options' => array_map('t', array_values(Health::LABELS)), 'icon' => 'mdi:heart-pulse']);
        $entity('sensor', 'health_score', $hub, ['name' => t('Gezondheid'), 'state_topic' => $topic,
            'value_template' => '{{ value_json.score }}', 'unit_of_measurement' => '%', 'state_class' => 'measurement', 'icon' => 'mdi:heart-pulse']);

        // Kwetsbaarheden (Instellingen → Beveiliging → Kwetsbaarheden): per server en in totaal
        if (Vulns::enabled()) {
            $vt = ['critical' => 0, 'high' => 0, 'exploited' => 0];
            $names = ['critical' => [t('Kwetsbaarheden critical'), 'mdi:bug'], 'high' => [t('Kwetsbaarheden high'), 'mdi:bug-outline'],
                'exploited' => [t('Kwetsbaarheden actief misbruikt'), 'mdi:shield-alert']];
            foreach (Vulns::summary() as $v) {
                $topic = "$base/vulns/{$v['host_id']}";
                $states[$topic] = $v;
                $dev = ['identifiers' => ["{$node}_host_{$v['host_id']}"], 'name' => $v['host']];
                foreach ($names as $k => [$n, $icon]) {
                    $entity('sensor', "host_{$v['host_id']}_vuln_$k", $dev, ['name' => $n, 'state_topic' => $topic, 'value_template' => "{{ value_json.$k }}",
                        'state_class' => 'measurement', 'icon' => $icon, 'json_attributes_topic' => $topic]);
                    $vt[$k] += $v[$k];
                }
            }
            $topic = "$base/vulns";
            $states[$topic] = $vt;
            foreach ($names as $k => [$n, $icon]) {
                $entity('sensor', "vulns_$k", $hub, ['name' => $n, 'state_topic' => $topic, 'value_template' => "{{ value_json.$k }}", 'state_class' => 'measurement', 'icon' => $icon]);
            }
        }

        // Datalekken (Have I Been Pwned, 1.69.0): open lekken, en of er een gelekt wachtwoord in gebruik is
        if (Hibp::enabled()) {
            $hs = Hibp::summary();
            $topic = "$base/breaches";
            $states[$topic] = ['open' => $hs['open'], 'with_passwords' => $hs['with_passwords'], 'emails' => $hs['emails'],
                'pw_leaked' => $hs['pw_users'] ? 'ON' : 'OFF', 'pw_users' => implode(', ', $hs['pw_users'])];
            $entity('sensor', 'breaches', $hub, ['name' => t('Datalekken'), 'state_topic' => $topic, 'value_template' => '{{ value_json.open }}',
                'state_class' => 'measurement', 'icon' => 'mdi:lock-open-alert', 'json_attributes_topic' => $topic]);
            $entity('binary_sensor', 'pw_leaked', $hub, ['name' => t('Gelekt wachtwoord'), 'state_topic' => $topic, 'value_template' => '{{ value_json.pw_leaked }}',
                'device_class' => 'problem', 'icon' => 'mdi:form-textbox-password']);
        }

        return [$disc, $states];
    }
}
