<?php
declare(strict_types=1);

/**
 * Verbindingen en taken (1.91.0): wat er bij RzMon binnenkomt (agents, browsers, API-sleutels, heartbeats) en wat RzMon
 * zelf op de achtergrond doet (updates zoeken, blocklists, GeoIP, datalekken, kwetsbaarheden, certificaat, MQTT, back-up).
 * Lukt een taak te lang niet, dan volgt een ⚠️-melding via Systeeminfo (rij "Achtergrondtaak lukt niet"), en een ✅ als
 * het weer lukt. De back-up meldt zichzelf al (Instellingen → Back-up), die telt hier alleen in het overzicht mee.
 */
final class Tasks
{
    private const DAY = 86400;

    /** Alle taken met hun stand: key, label, on, every, last_ok, last_try, error, next, stale (s), alert, status */
    public static function list(): array
    {
        $ts = static fn($v) => ($v === null || $v === '' || $v === 0) ? null : (is_numeric($v) ? (int) $v : (strtotime((string) $v) ?: null));
        $at = static function (string $hm): int {
            $t = strtotime(date('Y-m-d') . ' ' . (preg_match('/^\d{2}:\d{2}$/', $hm) ? $hm : '03:30'));
            return $t > time() ? $t : $t + self::DAY;
        };
        // Vers uit de database: in cron zijn deze instellingen in dezelfde run net bijgewerkt (setting() onthoudt de oude waarde)
        $fresh = q('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $setting = static fn(string $name, string $default = '') => $fresh[$name] ?? $default;
        $out = [];

        $out[] = ['key' => 'cron', 'label' => t('Checks uitvoeren (cron)'), 'on' => true, 'every' => t('elke minuut'),
            'last_ok' => $ts($setting('cron_last_run', '')), 'stale' => 300, 'alert' => false,
            'note' => t('Als dit stilstaat, gebeurt er niets meer: geen checks en geen meldingen.')];

        $u = json_decode((string) $setting('update_cache', ''), true) ?: [];
        $out[] = ['key' => 'update', 'label' => t('Nieuwe versie zoeken (GitHub)'), 'on' => true,
            'every' => $setting('update_auto', '0') === '1' ? t('dagelijks; automatisch bijwerken om {t}', ['t' => $setting('update_time', '04:00')]) : t('dagelijks'),
            'last_ok' => $ts($u['ok_at'] ?? (empty($u['error']) ? ($u['checked_at'] ?? null) : null)), 'last_try' => $ts($u['checked_at'] ?? null),
            'error' => (string) ($u['error'] ?? ''), 'next' => isset($u['checked_at']) ? (int) $u['checked_at'] + self::DAY : null, 'stale' => 3 * self::DAY, 'alert' => true];

        $ip = json_decode((string) $setting('ipintel_state', ''), true) ?: [];
        $out[] = ['key' => 'blocklists', 'label' => t('Blocklists van bekende aanvallers'), 'on' => IpIntel::blocklistsOn(), 'every' => t('dagelijks'),
            'last_ok' => $ts($ip['bl_ok'] ?? null), 'last_try' => $ts($ip['bl_try'] ?? null), 'error' => (string) ($ip['bl_error'] ?? ''),
            'next' => isset($ip['bl_ok']) ? (int) $ip['bl_ok'] + self::DAY : null, 'stale' => 3 * self::DAY, 'alert' => true];
        $out[] = ['key' => 'geo', 'label' => t('GeoIP-database (land per IP)'), 'on' => IpIntel::geoOn(), 'every' => t('maandelijks'),
            'last_ok' => $ts($ip['geo_ok'] ?? null), 'last_try' => $ts($ip['geo_try'] ?? null), 'error' => (string) ($ip['geo_error'] ?? ''),
            'next' => isset($ip['geo_ok']) ? (int) $ip['geo_ok'] + 30 * self::DAY : null, 'stale' => 45 * self::DAY, 'alert' => true];

        $hibpOk = null;
        if (Hibp::enabled() && Hibp::hasKey()) {
            $hibpOk = max((int) $ts(q('SELECT MAX(checked_at) FROM hibp_emails')->fetchColumn()), (int) $ts($setting('hibp_domain_at', ''))) ?: null;
        }
        $out[] = ['key' => 'hibp', 'label' => t('Datalekken (Have I Been Pwned)'), 'on' => Hibp::enabled() && Hibp::hasKey(), 'every' => t('dagelijks, verspreid'),
            'last_ok' => $hibpOk, 'error' => (string) ($setting('hibp_last_error', '') ?: $setting('hibp_domain_error', '')), 'stale' => 3 * self::DAY, 'alert' => true];

        $out[] = ['key' => 'vulns', 'label' => t('Kwetsbaarheden zoeken (OSV, KEV, EPSS)'), 'on' => Vulns::enabled(),
            'every' => t('dagelijks om {t}', ['t' => $setting('vuln_time', '03:30')]), 'last_ok' => $ts($setting('vuln_last_scan', '')),
            'next' => $at((string) $setting('vuln_time', '03:30')), 'stale' => 3 * self::DAY, 'alert' => true];

        $out[] = ['key' => 'https', 'label' => t('Certificaat van het beheeradres'), 'on' => Https::on(), 'every' => t('dagelijks'),
            'last_ok' => $ts($setting('https_check_at', '')), 'stale' => 3 * self::DAY, 'alert' => true];

        $m = json_decode((string) $setting('mqtt_last', ''), true) ?: [];
        $out[] = ['key' => 'mqtt', 'label' => t('MQTT naar Home Assistant'), 'on' => $setting('mqtt_enabled', '0') === '1', 'every' => t('elke minuut; discovery elk uur'),
            'last_ok' => $ts($m['ok_at'] ?? null), 'last_try' => $ts($m['at'] ?? null), 'error' => (string) ($m['error'] ?? ''), 'stale' => 900, 'alert' => true];

        $freq = (string) $setting('backup_freq', 'off');
        $err = (string) $setting('backup_last_error', '');
        $out[] = ['key' => 'backup', 'label' => t('Automatische back-up'), 'on' => $freq !== 'off',
            'every' => ['daily' => t('dagelijks'), 'weekly' => t('wekelijks'), 'monthly' => t('maandelijks')][$freq] ?? '–',
            'last_ok' => $ts($setting('backup_last_auto', '')), 'error' => $err !== '' ? preg_replace('/^\S+ \S+ /', '', $err) : '',
            'next' => Backup::nextSlot(), 'stale' => (['daily' => 3, 'weekly' => 10, 'monthly' => 35][$freq] ?? 3) * self::DAY, 'alert' => false,
            'note' => t('Meldt zichzelf al als hij mislukt.')];

        $watch = json_decode((string) $setting('task_watch', ''), true) ?: [];
        foreach ($out as &$o) {
            $o += ['last_try' => null, 'error' => '', 'next' => null, 'note' => ''];
            $o['status'] = self::status($o, $watch[$o['key']]['since'] ?? null);
        }
        return $out;
    }

    /**
     * off (uit), wait (nog niet gedaan), ok, warn (laatste poging mislukt, eerder gelukt), bad (te lang niet gelukt:
     * langer dan 'stale' geleden, of nooit gelukt en al zo lang een fout).
     */
    private static function status(array $o, ?int $since): string
    {
        if (!$o['on']) {
            return 'off';
        }
        if ($o['last_ok'] && time() - $o['last_ok'] > $o['stale']) {
            return 'bad';
        }
        if (!$o['last_ok'] && $o['error'] !== '') {
            return $since && time() - $since > min($o['stale'], self::DAY) ? 'bad' : 'warn';
        }
        if ($o['error'] !== '') {
            return 'warn';
        }
        return $o['last_ok'] ? 'ok' : 'wait';
    }

    /** MQTT: uitkomst van de laatste publicatie bewaren (alleen bij een verandering, of elke 10 minuten) */
    public static function mqttResult(bool $ok, string $error = ''): void
    {
        $m = json_decode((string) setting('mqtt_last', ''), true) ?: [];
        if ($ok && ($m['error'] ?? '') === '' && (int) ($m['ok_at'] ?? 0) > time() - 600) {
            return;
        }
        $m['at'] = time();
        if ($ok) {
            $m['ok_at'] = time();
            $m['error'] = '';
        } else {
            $m['error'] = mb_substr($error, 0, 250);
        }
        save_setting('mqtt_last', json_encode($m, JSON_UNESCAPED_UNICODE));
    }

    /** Vanuit cron: lukt een taak te lang niet, één ⚠️-melding; lukt hij weer, een ✅. */
    public static function watch(): ?string
    {
        $watch = json_decode((string) setting('task_watch', ''), true) ?: [];
        $before = $watch;
        $said = [];
        foreach (self::list() as $o) {
            $k = $o['key'];
            $problem = $o['status'] === 'bad' || ($o['status'] === 'warn' && !$o['last_ok']);
            if ($problem) {
                $watch[$k]['since'] ??= time();
            }
            if ($o['status'] === 'bad' && $o['alert'] && empty($watch[$k]['alerted'])) {
                $watch[$k]['alerted'] = time();
                $why = $o['last_ok'] ? t('Laatst gelukt: {t}.', ['t' => date('d-m-Y H:i', $o['last_ok'])]) : t('Nog nooit gelukt.');
                Monitor::sysInfo('task_fail', null, null, '⚠️ RzMon: ' . t('{task} lukt niet', ['task' => $o['label']]),
                    fn() => $why . ($o['error'] !== '' ? ' ' . t('Fout: {e}', ['e' => $o['error']]) : '') . "\n" . t('Zie Instellingen → Overzicht → Verbindingen en taken.'));
                $said[] = "$k mislukt";
            } elseif (!$problem && isset($watch[$k])) {
                if (!empty($watch[$k]['alerted']) && $o['status'] !== 'off') {
                    Monitor::sysInfo('task_fail', null, null, '✅ RzMon: ' . t('{task} lukt weer', ['task' => $o['label']]), fn() => t('De taak is weer gelukt.'));
                    $said[] = "$k hersteld";
                }
                unset($watch[$k]);
            }
        }
        if ($watch !== $before) {
            save_setting('task_watch', json_encode($watch));
        }
        return $said ? 'Taken: ' . implode(', ', $said) : null;
    }

    /** Wat er bij RzMon binnenkomt */
    public static function inbound(): array
    {
        $agents = [];
        foreach (q("SELECT id, name, agent_seen, agent_tick, realtime, agent_version, agent_ip, " . HostKind::IS_HA_SQL . " FROM hosts
                    WHERE mode = 'agent' AND enabled = 1 ORDER BY name")->fetchAll() as $h) {
            $seen = $h['agent_seen'] ? strtotime((string) $h['agent_seen']) : null;
            $agents[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'ha' => (bool) $h['is_ha'], 'tick' => agent_tick(isset($h['agent_tick']) ? (int) $h['agent_tick'] : null),
                'realtime' => (bool) $h['realtime'], 'version' => $h['agent_version'], 'ip' => $h['agent_ip'], 'seen' => $h['agent_seen'],
                'stale' => !$seen || $seen < time() - agent_stale_seconds(isset($h['agent_tick']) ? (int) $h['agent_tick'] : null)];
        }
        $sessions = q('SELECT username, device, ip, MAX(last_seen) AS last_seen, COUNT(*) AS n FROM user_sessions WHERE last_seen >= NOW() - INTERVAL 1 DAY
                       GROUP BY username, device, ip ORDER BY last_seen DESC LIMIT 20')->fetchAll();
        $keys = q('SELECT name, key_hint, last_used FROM api_keys ORDER BY last_used IS NULL, last_used DESC')->fetchAll();
        $hb = q("SELECT c.id, c.name, h.name AS host, s.updated_at AS last FROM checks c JOIN hosts h ON h.id = c.host_id LEFT JOIN hb_state s ON s.check_id = c.id
                 WHERE c.type = 'heartbeat' AND c.enabled = 1 ORDER BY h.name, c.name")->fetchAll();
        return ['agents' => $agents, 'agent_tick' => agent_tick(null), 'sessions' => $sessions, 'api_keys' => $keys, 'heartbeats' => $hb];
    }
}
