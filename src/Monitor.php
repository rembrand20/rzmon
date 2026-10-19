<?php
declare(strict_types=1);

final class Monitor
{
    /** Voert alle checks uit die aan de beurt zijn (of alles bij $force). */
    public static function runDue(bool $force = false): int
    {
        $sql = 'SELECT c.*, h.name AS host_name, h.address, h.mode, h.agent_seen, h.id AS hid
                FROM checks c JOIN hosts h ON h.id = c.host_id
                WHERE c.enabled = 1 AND h.enabled = 1';
        if (!$force) {
            $sql .= ' AND (c.last_checked IS NULL OR c.last_checked <= NOW() - INTERVAL c.interval_sec SECOND + INTERVAL 15 SECOND)';
        }
        $count = 0;
        foreach (q($sql)->fetchAll() as $row) {
            self::runOne($row);
            $count++;
        }
        return $count;
    }

    public static function runCheckById(int $id): ?array
    {
        $row = q('SELECT c.*, h.name AS host_name, h.address, h.mode, h.agent_seen, h.id AS hid
                  FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
        return $row ? self::runOne($row) : null;
    }

    private static function runOne(array $row): array
    {
        $host = [
            'id' => $row['hid'],
            'name' => $row['host_name'],
            'address' => $row['address'],
            'mode' => $row['mode'],
            'agent_seen' => $row['agent_seen'],
        ];
        $res = Checks::run($row, $host);
        self::process($row, $res);
        return $res;
    }

    private static function process(array $check, array $res): void
    {
        $status = $res['status'];
        $failCount = $status === STATUS_OK ? 0 : (int) $check['fail_count'] + 1;
        $notified = (int) $check['notified_status'];
        $notifiedAt = $check['notified_at'];

        q('INSERT INTO results (check_id, status, value) VALUES (?, ?, ?)', [$check['id'], $status, $res['value']]);

        $s = settings();
        $title = sprintf('%s – %s', $check['host_name'], $check['name']);

        if ($status === STATUS_OK) {
            if ($notified !== STATUS_OK && ($s['notify_recovery'] ?? '1') === '1') {
                self::notify((int) $check['id'], STATUS_OK, "✅ HERSTELD: $title", $res['message'], -1);
            }
            $notified = STATUS_OK;
            $notifiedAt = null;
        } elseif ($failCount >= max(1, (int) ($s['alert_after'] ?? 2))) {
            $isWarnish = $status !== STATUS_CRIT;
            $allowed = !$isWarnish || ($s['notify_warn'] ?? '1') === '1';
            $repeat = (int) ($s['repeat_minutes'] ?? 0);
            $due = $status !== $notified
                || ($repeat > 0 && $notifiedAt !== null && strtotime($notifiedAt) <= time() - $repeat * 60);

            if ($allowed && $due) {
                $icon = [STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'][$status];
                $prio = (int) ($isWarnish ? ($s['pushover_priority_warn'] ?? 0) : ($s['pushover_priority_crit'] ?? 1));
                self::notify((int) $check['id'], $status, sprintf('%s %s: %s', $icon, STATUS_LABELS[$status], $title), $res['message'], $prio);
                $notified = $status;
                $notifiedAt = now();
            }
        }

        q('UPDATE checks SET status = ?, last_value = ?, last_message = ?, last_checked = ?, fail_count = ?, notified_status = ?, notified_at = ? WHERE id = ?', [
            $status, $res['value'], mb_substr($res['message'], 0, 500), now(), $failCount, $notified, $notifiedAt, $check['id'],
        ]);
    }

    private static function notify(int $checkId, int $status, string $title, string $message, int $priority): void
    {
        $sent = ['ok' => false, 'error' => 'Pushover uitgeschakeld'];
        if (setting('pushover_enabled', '0') === '1') {
            $sent = Pushover::send($title, $message . "\n" . date('d-m-Y H:i:s'), $priority);
        }
        q('INSERT INTO alerts (check_id, status, title, message, sent_ok) VALUES (?, ?, ?, ?, ?)', [
            $checkId, $status, mb_substr($title, 0, 250),
            mb_substr($message . ($sent['ok'] ? '' : ' [niet verzonden: ' . $sent['error'] . ']'), 0, 1000),
            $sent['ok'] ? 1 : 0,
        ]);
    }

    public static function purge(): void
    {
        $days = max(1, (int) setting('retention_days', 30));
        q('DELETE FROM results WHERE created_at < NOW() - INTERVAL ? DAY', [$days]);
        q('DELETE FROM alerts WHERE created_at < NOW() - INTERVAL ? DAY', [$days * 3]);
    }
}
