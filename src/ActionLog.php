<?php
declare(strict_types=1);

/**
 * Logboek per server (1.226.0): wat er op een server gebeurde en wie het deed.
 * - Acties en ingrepen (tabel host_actions): isoleren, gebruikers vergrendelen, processen stoppen, zelf herstellen,
 *   pakketten bijwerken, de agent bijwerken, en (vanaf agent 1.89.0) wat de agent zelf op de server veranderde.
 *   Bron: een beheerder (user), RzSense zelf (rzsense) of de agent (agent).
 * - Wijzigingen aan de server en zijn checks komen uit het wijzigingslogboek (change_log) en staan er samen mee in één lijst.
 * Te zien in de Agent Manager (kaart Logboek) en bij Instellingen → Agents → Logboek. Bewaren: actlog_days (standaard 365 dagen).
 */
final class ActionLog
{
    /** Onderdelen (module) die in het logboek voorkomen; de agent mag alleen deze gebruiken */
    public const MODULES = ['agent', 'settings', 'checks', 'isolate', 'userlock', 'kill', 'heal', 'update', 'packages', 'edr', 'firewall', 'logs',
        'ransom', 'patch', 'sudo', 'appctl', 'ioc', 'dns', 'harden', 'nis2', 'report', 'admin'];   // 1.235.0: admin = acties van beheerders (was audit_log)
    public const SOURCES = ['user', 'rzsense', 'agent'];

    /** Eén regel vastleggen. $ok: true gelukt, false mislukt, null ter info. Mag nooit iets tegenhouden. */
    public static function add(?int $hostId, string $source, string $module, string $action, string $detail = '', ?bool $ok = null, string $ref = '', ?string $at = null, ?string $user = null): void
    {
        try {
            $u = $user ?? (PHP_SAPI === 'cli' ? 'cli' : (function_exists('current_user') ? (string) ((current_user() ?? [])['username'] ?? '') : ''));
            q('INSERT IGNORE INTO host_actions (host_id, at, source, module, action, detail, ok, username, ip, ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $hostId ?: null, $at ?? now(), in_array($source, self::SOURCES, true) ? $source : 'rzsense', in_array($module, self::MODULES, true) ? $module : 'agent',
                mb_substr($action, 0, 80), mb_substr($detail, 0, 1000), $ok === null ? null : ($ok ? 1 : 0),
                mb_substr($u, 0, 64), $source === 'user' && PHP_SAPI !== 'cli' ? mb_substr(client_ip(), 0, 45) : '', $ref !== '' ? mb_substr($ref, 0, 40) : null]);
        } catch (Throwable) {
            // het logboek mag een actie nooit tegenhouden
        }
    }

    /** Door een beheerder (of RzSense zelf als $by met "RzSense" begint) */
    public static function by(string $by, ?int $hostId, string $module, string $action, string $detail = '', ?bool $ok = null): void
    {
        $auto = $by === '' || str_starts_with($by, 'RzSense');
        self::add($hostId, $auto ? 'rzsense' : 'user', $module, $action, $detail, $ok, '', null, $auto ? 'RzSense' : $by);
    }

    /**
     * Wat de agent zelf deed (agent 1.89.0): "act": [{"id":"…","t":epoch,"m":"module","a":"actie","ok":0|1|2,"d":"detail"}].
     * Het id voorkomt dubbele regels als de agent een rapport opnieuw verstuurt (store-and-forward).
     */
    public static function fromAgent(array $host, array $in): void
    {
        foreach (array_slice((array) ($in['act'] ?? []), 0, 200) as $e) {
            if (!is_array($e) || !preg_match('/^[A-Za-z0-9._-]{4,40}$/', (string) ($e['id'] ?? ''))) {
                continue;
            }
            $t = (int) ($e['t'] ?? 0);
            $t = $t > time() + 300 || $t < time() - 90 * 86400 ? time() : $t;   // klok van de server ernaast: dan nu
            $m = (string) ($e['m'] ?? 'agent');
            $ok = (int) ($e['ok'] ?? 2);
            self::add((int) $host['id'], 'agent', in_array($m, self::MODULES, true) ? $m : 'agent', self::clean((string) ($e['a'] ?? '')), self::clean((string) ($e['d'] ?? '')),
                $ok === 1 ? true : ($ok === 0 ? false : null), (string) $e['id'], date('Y-m-d H:i:s', $t), 'agent');
        }
    }

    /** Geen stuurtekens of regeleindes uit een agent */
    private static function clean(string $s): string
    {
        return trim((string) preg_replace('/\s{2,}/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s)));
    }

    /**
     * Eén lijst: acties en wijzigingen, nieuwste eerst.
     * $f: host_id (0 = alle), module, source (user/rzsense/agent), days (1–3650), q (zoeken), ok ('0' = alleen mislukt), limit (≤ 1000)
     * @return list<array<string, mixed>>
     */
    public static function list(array $f): array
    {
        $hid = (int) ($f['host_id'] ?? 0);
        $mod = (string) ($f['module'] ?? '');
        $src = (string) ($f['source'] ?? '');
        $days = max(1, min(3650, (int) ($f['days'] ?? 30)));
        $limit = max(1, min(1000, (int) ($f['limit'] ?? 300)));
        $qs = trim(mb_substr((string) ($f['q'] ?? ''), 0, 100));
        $onlyFail = (string) ($f['ok'] ?? '') === '0';
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $names = q('SELECT id, name FROM hosts')->fetchAll(PDO::FETCH_KEY_PAIR);

        $out = [];
        if (!in_array($mod, ['settings', 'checks', 'config'], true)) {
            $where = ['a.at >= ?'];
            $args = [$since];
            if ($hid > 0) {
                $where[] = 'a.host_id = ?';
                $args[] = $hid;
            }
            if ($mod !== '') {
                $where[] = 'a.module = ?';
                $args[] = $mod;
            }
            if ($src !== '') {
                $where[] = 'a.source = ?';
                $args[] = $src;
            }
            if ($onlyFail) {
                $where[] = 'a.ok = 0';
            }
            if ($qs !== '') {
                $where[] = '(a.action LIKE ? OR a.detail LIKE ? OR a.username LIKE ?)';
                array_push($args, "%$qs%", "%$qs%", "%$qs%");
            }
            foreach (q('SELECT a.* FROM host_actions a WHERE ' . implode(' AND ', $where) . " ORDER BY a.at DESC, a.id DESC LIMIT $limit", $args)->fetchAll() as $r) {
                $out[] = ['kind' => 'act', 'id' => (int) $r['id'], 'at' => $r['at'], 'host_id' => $r['host_id'] !== null ? (int) $r['host_id'] : null,
                    'host' => $r['host_id'] !== null ? (string) ($names[(int) $r['host_id']] ?? ('#' . $r['host_id'])) : '', 'source' => $r['source'], 'module' => $r['module'],
                    'action' => $r['action'], 'detail' => $r['detail'], 'ok' => $r['ok'] === null ? null : (bool) $r['ok'], 'username' => $r['username'], 'ip' => $r['ip']];
            }
        }
        // Wijzigingen aan servers en checks (door een beheerder), uit het wijzigingslogboek
        // 1.235.0: ook RzSense-instellingen, sjablonen en systeem (module "config"), zodat het Wijzigingslogboek hier in staat
        if (($mod === '' || in_array($mod, ['settings', 'checks', 'config'], true)) && ($src === '' || $src === 'user') && !$onlyFail) {
            $kinds = match ($mod) {
                'settings' => "('host')",
                'checks' => "('check')",
                'config' => "('setting', 'template', 'system')",
                default => $hid > 0 ? "('host', 'check')" : "('host', 'check', 'setting', 'template', 'system')",
            };
            $args = [$since];
            $like = '';
            if ($qs !== '') {
                $like = ' AND (label LIKE ? OR username LIKE ?)';
                array_push($args, "%$qs%", "%$qs%");
            }
            foreach (q("SELECT id, at, username, ip, kind, ref_id, action, label, before_data, after_data, undone_at FROM change_log WHERE kind IN $kinds AND at >= ?$like ORDER BY id DESC LIMIT " . ($limit * 3), $args)->fetchAll() as $r) {
                $b = json_decode((string) $r['before_data'], true) ?: [];
                $a = json_decode((string) $r['after_data'], true) ?: [];
                $h = $r['kind'] === 'host' ? (int) $r['ref_id'] : (int) ($a['host_id'] ?? $b['host_id'] ?? 0);
                if ($hid > 0 && $h !== $hid) {
                    continue;
                }
                $out[] = ['kind' => 'change', 'id' => (int) $r['id'], 'at' => $r['at'], 'host_id' => $h ?: null, 'host' => $h ? (string) ($names[$h] ?? ($b['name'] ?? $a['name'] ?? "#$h")) : '',
                    'source' => 'user', 'module' => match ($r['kind']) { 'host' => 'settings', 'check' => 'checks', default => 'config' },
                    'action' => self::changeTitle($r), 'detail' => self::changeDetail($r['kind'], $b, $a),
                    'ok' => null, 'username' => $r['username'], 'ip' => $r['ip'], 'undone' => $r['undone_at'] !== null, 'can_undo' => ChangeLog::canUndoRow($r)];
            }
        }
        usort($out, static fn($x, $y) => strcmp((string) $y['at'], (string) $x['at']) ?: ($y['id'] <=> $x['id']));
        return array_slice($out, 0, $limit);
    }

    private static function changeTitle(array $r): string
    {
        $what = match ((string) $r['kind']) { 'host' => t('Server'), 'check' => t('Check'), 'template' => t('Sjabloon'), 'system' => t('Systeem'), default => t('Instelling') };
        return match ((string) $r['action']) {
            'create' => t('{what} aangemaakt', ['what' => $what]),
            'delete' => t('{what} verwijderd', ['what' => $what]),
            'undo' => t('{what} teruggezet', ['what' => $what]),
            'smart' => t('Slimme drempels geleerd'),
            default => t('{what} gewijzigd', ['what' => $what]),
        } . ': ' . (string) $r['label'];
    }

    /** Wat er veranderde, kort: "veld: oud → nieuw" (runtime-velden en geheimen weggelaten) */
    private static function changeDetail(string $kind, array $b, array $a): string
    {
        $skip = '/^(_|id$|status|last_|fail_count|notified|problem_since|acked|pushover_receipt|flap|quiet_held|suppressed_by|run_ms|agent_seen|agent_ip|agent_version|agent_key|agent_nonce|enc_seen|agent_conn|'
            . 'agent_update|agent_fb|agent_url_status|agent_url_at|agent_ip_new|hb_token|agent_token|tpl_override|template_item_id|isolate_state|vuln_upgrade|created_at|updated_at|.*_at$|.*_seen$|.*_state$|.*_cache$)/';
        $parts = [];
        foreach (array_unique([...array_keys($b), ...array_keys($a)]) as $k) {
            if (preg_match($skip, (string) $k) || ($b[$k] ?? null) == ($a[$k] ?? null) || preg_match('/(pass|token|secret|_key$|community)/i', (string) $k)) {
                continue;
            }
            $v = static fn($x) => $x === null || $x === '' ? '–' : mb_substr(is_scalar($x) ? (string) $x : (string) json_encode($x), 0, 60);
            $parts[] = "$k: " . ($b ? $v($b[$k] ?? null) . ' → ' : '') . $v($a[$k] ?? null);
            if (count($parts) >= 8) {
                $parts[] = '…';
                break;
            }
        }
        return implode(' · ', $parts);
    }

    /** CSV van een lijst (voor downloaden) */
    public static function csv(array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['tijd', 'server', 'bron', 'onderdeel', 'actie', 'detail', 'uitkomst', 'gebruiker', 'ip'], ';', '"', '');
        foreach ($rows as $r) {
            $cells = [$r['at'], $r['host'], $r['source'], $r['module'], $r['action'], $r['detail'], $r['ok'] === null ? '' : ($r['ok'] ? 'ok' : 'mislukt'), $r['username'], $r['ip']];
            // Geen formules in Excel: een cel die met = + - @ begint krijgt een ' ervoor
            fputcsv($f, array_map(static fn($c) => preg_match('/^[=+\-@\t\r]/', (string) $c) ? "'" . $c : (string) $c, $cells), ';', '"', '');
        }
        rewind($f);
        return (string) stream_get_contents($f);
    }

    /** Hoeveel er de laatste 24 uur mislukte, per server (voor de Agent Manager en de agenttabel) */
    public static function failsPerHost(): array
    {
        return array_map('intval', q('SELECT host_id, COUNT(*) FROM host_actions WHERE ok = 0 AND at >= NOW() - INTERVAL 1 DAY AND host_id IS NOT NULL GROUP BY host_id')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public static function days(): int
    {
        return max(30, min(3650, (int) setting('actlog_days', '365')));
    }

    public static function purge(): void
    {
        q('DELETE FROM host_actions WHERE at < ?', [date('Y-m-d H:i:s', time() - self::days() * 86400)]);
    }
}
