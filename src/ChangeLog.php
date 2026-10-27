<?php
declare(strict_types=1);

/**
 * Wijzigingslogboek (1.97.0): wie veranderde wat en wanneer, met de oude en nieuwe waarde, en terugzetten.
 * - Instellingen: api.php vergelijkt de instellingen vóór en na elke wijziging van een beheerder (snapshot/diff).
 *   Geheimen (wachtwoorden, tokens, sleutels) staan er nooit leesbaar in en zijn niet terug te zetten.
 * - Checks en servers: de hele rij; een verwijderde check of server komt terug met hetzelfde id (de meethistorie niet).
 * - Sjablonen: alleen ter info.
 * Bewaartermijn: Instellingen → Systeem → Wijzigingslogboek (changelog_days, standaard 90 dagen).
 */
final class ChangeLog
{
    /** Instellingen die RzMon zelf bijhoudt (geen wijziging van een mens) */
    private const VOLATILE = '/^(cron_|update_cache|update_auto_day|update_pinned_at|.*_state$|.*_seen$|.*_check_at$|.*_domain_at$|ipintel_|hibp_wait|hibp_last_error|hibp_domain_error|hibp_rpm|'
        . 'vuln_daily|vuln_last|vuln_kev|vuln_cache|selfcheck_|db_size_hist|task_watch|mqtt_last|mqtt_discovery|backup_last|backup_retry|backup_fail|templates_seeded|https_since|https_check|'
        . 'own_cert_|proxy_unknown|hsts_sent|cleanup_ignore|maintenance_|ids_weekly_last|agent_fb|enc_old|sec_|stealth_last|wl_last|tests_rev|.*_notified$)/';
    /** Geheimen: niet tonen, niet terugzetten */
    private const SECRET = '/(pass|token|secret|_key$|^enc_key|community|api_key|hibp_key|smtp_pass|mqtt_pass)/i';

    public static function settingsSnapshot(): array
    {
        return q('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Verschil tussen twee snapshots vastleggen (één regel per opslag) */
    public static function settingsDiff(array $before, array $after, string $action, ?string $label = null): void
    {
        $b = $a = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $k) {
            if (preg_match(self::VOLATILE, (string) $k) || ($before[$k] ?? null) === ($after[$k] ?? null)) {
                continue;
            }
            $secret = (bool) preg_match(self::SECRET, (string) $k);
            $b[$k] = $secret ? '••••••••' : ($before[$k] ?? null);
            $a[$k] = $secret ? '••••••••' : ($after[$k] ?? null);
        }
        if ($b || $a) {
            $label ??= ['settings_save' => t('Instellingen opgeslagen'), 'access_save' => t('Toegang en inlogbeveiliging'), 'sysinfo_save' => t('Systeeminfo')][$action] ?? t('Instellingen ({a})', ['a' => $action]);
            self::record('setting', null, 'update', $label, $b, $a);
        }
    }

    public static function record(string $kind, ?int $refId, string $action, string $label, ?array $before, ?array $after): void
    {
        try {
            $u = function_exists('current_user') ? current_user() : null;
            q('INSERT INTO change_log (at, username, ip, kind, ref_id, action, label, before_data, after_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                now(), mb_substr((string) ($u['username'] ?? (PHP_SAPI === 'cli' ? 'cli' : '?')), 0, 64), mb_substr(client_ip(), 0, 45), $kind, $refId, $action,
                mb_substr($label, 0, 200), $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable) {
            // het logboek mag een wijziging nooit tegenhouden
        }
    }

    public static function checkRow(int $id): ?array
    {
        $r = q('SELECT c.*, h.name AS _host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
        return $r ?: null;
    }

    public static function hostRow(int $id, bool $withChecks = false): ?array
    {
        $r = q('SELECT * FROM hosts WHERE id = ?', [$id])->fetch();
        if ($r && $withChecks) {
            $r['_checks'] = q('SELECT * FROM checks WHERE host_id = ?', [$id])->fetchAll();
        }
        return $r ?: null;
    }

    public static function list(string $kind = '', int $limit = 300): array
    {
        $rows = q('SELECT * FROM change_log' . ($kind !== '' ? ' WHERE kind = ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)), $kind !== '' ? [$kind] : [])->fetchAll();
        return array_map(static function ($r) {
            $b = json_decode((string) $r['before_data'], true);
            $a = json_decode((string) $r['after_data'], true);
            return ['id' => (int) $r['id'], 'at' => $r['at'], 'username' => $r['username'], 'ip' => $r['ip'], 'kind' => $r['kind'], 'ref_id' => $r['ref_id'] !== null ? (int) $r['ref_id'] : null,
                'action' => $r['action'], 'label' => $r['label'], 'undone_at' => $r['undone_at'], 'changes' => self::changes($r['kind'], $r['action'], $b, $a), 'can_undo' => self::canUndo($r, $b)];
        }, $rows);
    }

    /** Wat er veranderde, als [veld, oud, nieuw] (runtime-velden van checks en servers weggelaten) */
    private static function changes(string $kind, string $action, ?array $b, ?array $a): array
    {
        $skip = '/^(_|status|last_|fail_count|notified|problem_since|acked|pushover_receipt|status_|flap|quiet_held|suppressed_by|run_ms|agent_seen|agent_ip|agent_version|agent_key|agent_nonce|enc_seen|agent_conn|'
            . 'agent_update|agent_fb|agent_url_status|agent_url_at|agent_ip_new|hb_token|agent_token|tpl_override|template_item_id)/';
        $out = [];
        foreach (array_unique([...array_keys((array) $b), ...array_keys((array) $a)]) as $k) {
            if (($kind === 'check' || $kind === 'host') && preg_match($skip, (string) $k)) {
                continue;
            }
            $o = $b[$k] ?? null;
            $n = $a[$k] ?? null;
            if ($action === 'update' && $o == $n) {
                continue;
            }
            if ($action !== 'update' && $o === null && $n === null) {
                continue;
            }
            $out[] = [$k, $o === null ? null : mb_substr(is_scalar($o) ? (string) $o : json_encode($o), 0, 300), $n === null ? null : mb_substr(is_scalar($n) ? (string) $n : json_encode($n), 0, 300)];
        }
        return array_slice($out, 0, 40);
    }

    private static function canUndo(array $r, ?array $b): bool
    {
        if ($r['undone_at'] !== null || !in_array($r['action'], ['create', 'update', 'delete', 'smart'], true)) {
            return false;   // al teruggezet, of zelf een terugzetting / alleen info
        }
        return match ($r['kind']) {
            'setting' => (bool) array_filter((array) $b, static fn($v) => $v !== '••••••••'),
            'check' => $r['action'] !== 'smart' || isset($b['_model']),   // 1.107.0: slimme drempels, als er vorige waren
            'host' => $r['action'] !== 'smart',
            default => false,
        };
    }

    /** Terugzetten. @return string wat er gebeurde */
    public static function undo(int $id): string
    {
        $r = q('SELECT * FROM change_log WHERE id = ?', [$id])->fetch();
        if (!$r || !self::canUndo($r, json_decode((string) $r['before_data'], true))) {
            throw new InvalidArgumentException(t('Dit kan niet (meer) worden teruggezet'));
        }
        $b = (array) (json_decode((string) $r['before_data'], true) ?: []);
        $a = (array) (json_decode((string) $r['after_data'], true) ?: []);
        $before = self::settingsSnapshot();
        $msg = match ($r['kind'] . ':' . $r['action']) {
            'setting:update' => self::undoSettings($b),
            'check:update' => self::restoreRow('checks', (int) $r['ref_id'], $b, $a),
            'check:delete' => self::reinsert('checks', $b),
            'check:create' => self::drop('checks', (int) $r['ref_id']),
            'check:smart' => Smart::restore((int) $r['ref_id'], $b),
            'host:update' => self::restoreRow('hosts', (int) $r['ref_id'], $b, $a),
            'host:delete' => self::reinsertHost($b),
            'host:create' => self::drop('hosts', (int) $r['ref_id']),
            default => throw new InvalidArgumentException(t('Dit kan niet (meer) worden teruggezet')),
        };
        q('UPDATE change_log SET undone_at = ? WHERE id = ?', [now(), $id]);
        if ($r['kind'] === 'setting') {
            self::settingsDiff($before, self::settingsSnapshot(), 'undo', t('Teruggezet: {l}', ['l' => $r['label']]));
        } else {
            self::record((string) $r['kind'], $r['ref_id'] !== null ? (int) $r['ref_id'] : null, 'undo', t('Teruggezet: {l}', ['l' => $r['label']]), $a ?: null, $b ?: null);
        }
        return $msg;
    }

    private static function undoSettings(array $b): string
    {
        $n = 0;
        foreach ($b as $k => $v) {
            if ($v === '••••••••' || preg_match(self::SECRET, (string) $k)) {
                continue;
            }
            if ($v === null) {
                q('DELETE FROM settings WHERE name = ?', [$k]);
            } else {
                save_setting((string) $k, (string) $v);
            }
            $n++;
        }
        return t('{n} instelling(en) teruggezet', ['n' => $n]);
    }

    private static function columns(string $table): array
    {
        return q("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Alleen de velden die bij deze wijziging veranderden, terug naar de oude waarde */
    private static function restoreRow(string $table, int $id, array $b, array $a): string
    {
        if (!q("SELECT 1 FROM $table WHERE id = ?", [$id])->fetchColumn()) {
            throw new InvalidArgumentException(t('Bestaat niet meer'));
        }
        $cols = array_intersect(self::columns($table), array_keys($b));
        $set = [];
        foreach ($cols as $c) {
            if ($c !== 'id' && ($b[$c] ?? null) != ($a[$c] ?? null)) {
                $set[$c] = $b[$c];
            }
        }
        if ($set) {
            q("UPDATE $table SET " . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
        }
        return t('{n} veld(en) teruggezet', ['n' => count($set)]);
    }

    private static function reinsert(string $table, array $row): string
    {
        $cols = array_values(array_intersect(self::columns($table), array_keys($row)));
        if (isset($row['id']) && q("SELECT 1 FROM $table WHERE id = ?", [$row['id']])->fetchColumn()) {
            $cols = array_values(array_diff($cols, ['id']));   // id intussen bezet (zou niet moeten): een nieuw id
        }
        if ($table === 'checks' && !q('SELECT 1 FROM hosts WHERE id = ?', [$row['host_id'] ?? 0])->fetchColumn()) {
            throw new InvalidArgumentException(t('De server van deze check bestaat niet meer'));
        }
        q("INSERT INTO $table (" . implode(', ', array_map(static fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_map(static fn($c) => $row[$c], $cols));
        return t('Teruggezet');
    }

    private static function reinsertHost(array $row): string
    {
        $checks = (array) ($row['_checks'] ?? []);
        unset($row['_checks']);
        self::reinsert('hosts', $row);
        foreach ($checks as $c) {
            self::reinsert('checks', $c);
        }
        return t('Server teruggezet met {n} checks', ['n' => count($checks)]);
    }

    private static function drop(string $table, int $id): string
    {
        q("DELETE FROM $table WHERE id = ?", [$id]);
        return t('Verwijderd');
    }

    public static function purge(): void
    {
        $days = max(7, (int) setting('changelog_days', '90'));
        q('DELETE FROM change_log WHERE at < NOW() - INTERVAL ? DAY', [$days]);
    }
}
