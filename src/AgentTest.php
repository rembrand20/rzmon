<?php

declare(strict_types=1);

/**
 * 1.178.0: 🧪 Test in de Agent Manager. Twee delen:
 * - RzMon kijkt meteen naar wat hij al weet: contact, versie, sleutel, versleuteling, https, IP-slot, geweigerde
 *   pogingen, bijwerken, de duur van een ronde, en (als SNMP aan staat) een echte SNMP-vraag aan het apparaat;
 * - de agent test zichzelf bij zijn volgende ronde (agent 1.79.0): planning, instellingen, DNS, het adres en het
 *   reserve-adres van RzMon, de klok, vrije ruimte, de wachtrij en fouten in zijn log. Alleen vaste, lezende controles.
 * agent_data: selftest_req (de vraag, {id, at}) en selftest (het antwoord van de agent).
 */
final class AgentTest
{
    /** Zo lang (seconden) blijft een vraag staan; daarna geeft de popup het op */
    public const WAIT = 300;
    /** Agent die zichzelf kan testen */
    public const AGENT_MIN = '1.79.0';

    /** Test starten: RzMon-deel meteen, het agentdeel bij de volgende ronde */
    public static function start(int $hostId): int
    {
        $id = (int) (microtime(true) * 1000);
        self::put($hostId, 'selftest_req', ['id' => $id, 'at' => time()]);
        return $id;
    }

    /** Regel voor de agent ("0 selftest id") zolang er een vraag openstaat */
    public static function agentLines(int $hostId): array
    {
        $rq = self::get($hostId, 'selftest_req');
        if (!$rq || time() - (int) ($rq['at'] ?? 0) > self::WAIT) {
            return [];
        }
        $done = self::get($hostId, 'selftest');
        return $done && (int) ($done['id'] ?? 0) === (int) $rq['id'] ? [] : ["0\tselftest\t" . (int) $rq['id']];
    }

    /** Antwoord van de agent bewaren (met het verschil tussen zijn klok en die van RzMon) */
    public static function fromAgent(array $host, array $in): void
    {
        $st = $in['selftest'] ?? null;
        if (!is_array($st) || !isset($st['id']) || !is_array($st['items'] ?? null)) {
            return;
        }
        $items = [];
        foreach (array_slice($st['items'], 0, 30) as $it) {
            if (!is_array($it) || !preg_match('/^[a-z_]{1,20}$/', (string) ($it['k'] ?? ''))) {
                continue;
            }
            $items[] = ['k' => (string) $it['k'], 'ok' => in_array($it['ok'] ?? '', ['ok', 'warn', 'bad', 'skip'], true) ? $it['ok'] : 'skip',
                'd' => mb_substr((string) ($it['d'] ?? ''), 0, 300)];
        }
        $skew = isset($st['now']) && is_numeric($st['now']) ? time() - (int) $st['now'] : null;
        self::put((int) $host['id'], 'selftest', ['id' => (int) $st['id'], 'at' => time(), 'items' => $items, 'skew' => $skew]);
    }

    /** Wat de popup toont: het RzMon-deel, en het agentdeel zodra het er is */
    public static function result(int $hostId, int $id): ?array
    {
        $h = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h) {
            return null;
        }
        $agent = null;
        $pending = false;
        if ($h['mode'] === 'agent') {
            $done = self::get($hostId, 'selftest');
            if ($done && (int) ($done['id'] ?? 0) === $id) {
                $agent = self::agentItems($done);
            } else {
                $rq = self::get($hostId, 'selftest_req');
                $pending = $rq && (int) ($rq['id'] ?? 0) === $id && time() - (int) $rq['at'] <= self::WAIT;
            }
        }
        $v = (string) ($h['agent_version'] ?? '');
        return ['server' => self::serverItems($h), 'agent' => $agent, 'pending' => $pending,
            'can' => $h['mode'] !== 'agent' ? null : ($v !== '' && version_compare($v, self::AGENT_MIN, '>=')),
            'tick' => (int) ($h['agent_tick'] ?: setting('agent_tick', '30')), 'seen' => $h['agent_seen'], 'now' => now()];
    }

    /** Vertaalde titels bij de controles van de agent */
    private static function agentItems(array $done): array
    {
        $titles = ['sched' => t('Planning (timer of cron)'), 'conf' => t('Instellingenbestand'), 'dns' => t('Naam van RzMon opzoeken (DNS)'),
            'url' => t('Adres van RzMon'), 'fallback' => t('Reserve-adres'), 'clock' => t('Klok'), 'disk' => t('Vrije ruimte voor de agent'),
            'queue' => t('Wachtrij (niet verstuurde rapporten)'), 'log' => t('Fouten in het log (laatste uur)'), 'tools' => t('Hulpprogramma\'s')];
        $out = [];
        foreach ($done['items'] as $it) {
            $out[] = ['k' => $it['k'], 'ok' => $it['ok'], 't' => $titles[$it['k']] ?? $it['k'], 'd' => $it['d']];
        }
        if ($done['skew'] !== null) {
            $s = abs((int) $done['skew']);
            $out[] = ['k' => 'skew', 'ok' => $s > 120 ? 'bad' : ($s > 20 ? 'warn' : 'ok'), 't' => t('Klok tegenover RzMon'),
                'd' => $s <= 5 ? t('gelijk') : t('{n} seconden verschil (inclusief de verzendtijd)', ['n' => $s])];
        }
        return $out;
    }

    /** Wat RzMon zelf weet of meteen kan testen */
    private static function serverItems(array $h): array
    {
        $out = [];
        $add = static function (string $k, string $ok, string $t, string $d) use (&$out): void {
            $out[] = ['k' => $k, 'ok' => $ok, 't' => $t, 'd' => $d];
        };
        $id = (int) $h['id'];
        if ($h['mode'] === 'agent') {
            $tick = (int) ($h['agent_tick'] ?: setting('agent_tick', '30'));
            $stale = (int) setting('agent_stale_minutes', '5') * 60;
            $age = $h['agent_seen'] ? time() - strtotime((string) $h['agent_seen']) : null;
            $add('contact', $age === null ? 'bad' : ($age <= max(90, $tick * 3) ? 'ok' : ($age <= $stale ? 'warn' : 'bad')), t('Laatste rapport'),
                $age === null ? t('nog nooit een rapport ontvangen') : t('{t} geleden (verwacht elke {n} s)', ['t' => self::ago($age), 'n' => $tick]));
            foreach (AgentAudit::items(q('SELECT ' . AgentAudit::HOST_COLS . ' FROM hosts WHERE id = ?', [$id])->fetch() ?: [], AgentAudit::cfg($id)) as $a) {
                $add($a['key'], $a['ok'] === null ? 'skip' : ($a['ok'] ? 'ok' : 'warn'), $a['label'], $a['detail']);
            }
            $fails = (int) q('SELECT COUNT(*) FROM agent_log WHERE host_id = ? AND created_at >= NOW() - INTERVAL 1 DAY AND (? IS NULL OR created_at > ?)',
                [$id, $h['agent_seen'], $h['agent_seen']])->fetchColumn();
            $add('refused', $fails ? 'warn' : 'ok', t('Geweigerde rapporten (24 uur)'), $fails ? t('{n} na het laatste goede rapport: zie de status hierboven', ['n' => $fails]) : t('geen'));
            $timing = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'timing'", [$id])->fetchColumn(), true);
            if (is_array($timing) && isset($timing['total'])) {
                $ms = (int) $timing['total'];
                $add('runtime', $ms > $tick * 1000 ? 'warn' : 'ok', t('Duur van een ronde'), t('{s} s', ['s' => number_format($ms / 1000, 1)]) . ($ms > $tick * 1000 ? ' · ' . t('langer dan het interval') : ''));
            }
            if ($h['agent_update_status'] && str_contains(mb_strtolower((string) $h['agent_update_status']), 'fout')) {
                $add('update', 'warn', t('Bijwerken'), (string) $h['agent_update_status']);
            }
        }
        $cfg = SnmpMonitor::config($h['snmp'] ?? null);
        if ($cfg !== null) {
            $src = SnmpMonitor::usesGlobal($h['snmp']) ? t('algemene instelling') : t('eigen instellingen');
            try {
                $t0 = microtime(true);
                $sys = SnmpMonitor::test($cfg, (string) $h['address']);
                $add('snmp', 'ok', 'SNMP', t('antwoordt in {ms} ms ({src}, v{v})', ['ms' => (int) round((microtime(true) - $t0) * 1000), 'src' => $src, 'v' => $cfg['v']])
                    . ($sys['name'] !== '' ? ' · ' . $sys['name'] : ''));
            } catch (Throwable $e) {
                $add('snmp', 'bad', 'SNMP', $src . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    private static function ago(int $s): string
    {
        return $s < 90 ? t('{n} s', ['n' => $s]) : ($s < 5400 ? t('{n} min', ['n' => (int) round($s / 60)]) : t('{n} uur', ['n' => (int) round($s / 3600)]));
    }

    private static function get(int $hostId, string $metric): ?array
    {
        $v = json_decode((string) q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [$hostId, $metric])->fetchColumn(), true);
        return is_array($v) ? $v : null;
    }

    private static function put(int $hostId, string $metric, array $v): void
    {
        q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$hostId, $metric, json_encode($v, JSON_UNESCAPED_UNICODE), now()]);
    }
}
