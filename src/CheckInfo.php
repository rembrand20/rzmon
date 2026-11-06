<?php
declare(strict_types=1);

/**
 * 1.176.0: extra's voor de popup van een check: hoe vaak gecheckt, beschikbaarheid en storingen over 30 dagen,
 * melden (melden na N, laatst gemeld, escalatie), het laatste AI-advies, de laatste wijziging en details per soort.
 */
final class CheckInfo
{
    /** @return array<string, mixed> */
    public static function extra(array $c): array
    {
        $id = (int) $c['id'];
        $raw = q('SELECT SUM(created_at >= NOW() - INTERVAL 1 DAY) AS n1, SUM(created_at >= NOW() - INTERVAL 1 DAY AND status <> 0) AS f1,
                         COUNT(*) AS n7, SUM(status <> 0) AS f7
                  FROM results WHERE check_id = ? AND created_at >= NOW() - INTERVAL 7 DAY', [$id])->fetch() ?: [];
        $hr = q('SELECT SUM(n) AS n, SUM(n_warn + n_crit) AS f, MIN(hour) AS since,
                        SUM(IF(hour >= NOW() - INTERVAL 30 DAY, n, 0)) AS n30, SUM(IF(hour >= NOW() - INTERVAL 30 DAY, n_crit, 0)) AS c30
                 FROM results_hourly WHERE check_id = ?', [$id])->fetch() ?: [];
        $n = (int) ($hr['n'] ?? 0);
        $counts = [
            'n24' => (int) ($raw['n1'] ?? 0), 'f24' => (int) ($raw['f1'] ?? 0),
            'n7' => (int) ($raw['n7'] ?? 0), 'f7' => (int) ($raw['f7'] ?? 0),
            // Alles wat bewaard is (uurtotalen); minstens de laatste 7 dagen, ook als de uurtotalen nog niet bijgewerkt zijn
            'total' => max($n, (int) ($raw['n7'] ?? 0)), 'total_fail' => max((int) ($hr['f'] ?? 0), (int) ($raw['f7'] ?? 0)),
            'since' => $hr['since'] ?? null,
        ];
        $up30 = (int) ($hr['n30'] ?? 0) > 0 ? round(100 * ((int) $hr['n30'] - (int) $hr['c30']) / (int) $hr['n30'], 2) : null;

        // Storingen (niet OK) in de laatste 30 dagen: begin = naar een probleem, einde = terug naar OK
        $outages = [];
        $open = null;
        $prev = q('SELECT to_status FROM status_changes WHERE check_id = ? AND created_at < NOW() - INTERVAL 30 DAY ORDER BY created_at DESC, id DESC LIMIT 1', [$id])->fetchColumn();
        if ($prev !== false && (int) $prev !== STATUS_OK) {
            $open = time() - 30 * 86400;
        }
        foreach (q('SELECT to_status, created_at FROM status_changes WHERE check_id = ? AND created_at >= NOW() - INTERVAL 30 DAY ORDER BY created_at, id', [$id]) as $ch) {
            $t = (int) strtotime((string) $ch['created_at']);
            if ((int) $ch['to_status'] !== STATUS_OK && $open === null) {
                $open = $t;
            } elseif ((int) $ch['to_status'] === STATUS_OK && $open !== null) {
                $outages[] = $t - $open;
                $open = null;
            }
        }
        $ongoing = $open !== null ? time() - $open : null;
        $all = $ongoing !== null ? [...$outages, $ongoing] : $outages;
        $out = [
            'count' => count($all),
            'mttr' => $outages ? (int) round(array_sum($outages) / count($outages)) : null,
            'longest' => $all ? max($all) : null,
            'up30' => $up30,
        ];

        // Melden
        $last = q("SELECT created_at, status, kind, sent_ok, email_ok, webhook_ok FROM alerts WHERE check_id = ? AND kind NOT IN ('ack', 'info')
                   ORDER BY id DESC LIMIT 1", [$id])->fetch() ?: null;
        $st = (int) $c['status'];
        $esc = null;
        if (setting('esc_on', '0') === '1' && in_array($st, [STATUS_WARN, STATUS_CRIT], true) && $c['acked_at'] === null && (int) ($c['escalated'] ?? 0) === 0) {
            $min = (int) setting($st === STATUS_CRIT ? 'esc_crit_min' : 'esc_warn_min', $st === STATUS_CRIT ? '15' : '30');
            $age = $c['status_since'] ? time() - (int) strtotime((string) $c['status_since']) : 0;
            $esc = $min > 0 ? ['in' => max(0, $min * 60 - $age), 'min' => $min] : null;
        }
        $notify = [
            'needed' => Monitor::failsNeeded($c), 'streak' => (int) ($c['status_streak'] ?? 0),
            'own' => !empty($c['notify']), 'last' => $last, 'escalation' => $esc, 'escalated' => (int) ($c['escalated'] ?? 0) === 1,
        ];

        // Laatste AI-advies (alleen als AI aan staat)
        $ai = null;
        if (setting('ai_on', '0') === '1') {
            $a = q("SELECT id, created_at, provider, model, answer, cost, verdict FROM ai_advice WHERE kind = 'check' AND ref_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$id])->fetch();
            if ($a) {
                $ai = ['id' => (int) $a['id'], 'at' => $a['created_at'], 'provider' => $a['provider'], 'model' => $a['model'],
                    'cost' => $a['cost'] !== null ? (float) $a['cost'] : null, 'verdict' => $a['verdict'], 'summary' => Ai::summary((string) $a['answer'])];
            }
        }

        // Laatste wijziging van de check
        $chg = q("SELECT at, username, action FROM change_log WHERE kind = 'check' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$id])->fetch() ?: null;

        return ['counts' => $counts, 'outages' => $out, 'notify' => $notify, 'ai' => $ai, 'changed' => $chg, 'detail' => self::detail($c)];
    }

    /** Details per soort check: container (uit de agent), disk (wanneer vol), certificaat en domein (dagen) */
    private static function detail(array $c): ?array
    {
        $type = (string) $c['type'];
        if ($type === 'container') {
            $v = q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [$c['host_id'], 'container:' . $c['target']])->fetchColumn();
            $d = $v !== false ? json_decode((string) $v, true) : null;
            if (!is_array($d)) {
                return null;
            }
            $cs = Checks::containerState($d);
            return ['kind' => 'container', 'state' => $cs, 'raw_state' => (string) ($d['state'] ?? ''), 'image' => (string) ($d['image'] ?? ''),
                'exit_code' => (int) ($d['exit_code'] ?? 0), 'health' => (string) ($d['health'] ?? ''), 'restarts' => (int) ($d['restarts'] ?? 0),
                'started' => !empty($d['started']) ? (int) $d['started'] : null, 'pod' => (string) ($d['pod'] ?? ''),
                'cpu' => $cs === 'running' && isset($d['cpu']) ? round((float) $d['cpu'], 1) : null,
                'mem' => $cs === 'running' && isset($d['mem']) ? (float) $d['mem'] : null, 'mem_limit' => isset($d['mem_limit']) ? (float) $d['mem_limit'] : null,
                'size_rw' => (float) ($d['size_rw'] ?? 0), 'volumes' => (float) ($d['volumes'] ?? 0),
                'runtime' => ($d['user'] ?? '') === 'docker' ? 'docker' : 'podman'];
        }
        if ($type === 'disk' && $c['last_value'] !== null) {
            $f = Stats::diskForecast((int) $c['id'], (float) $c['last_value']);
            return ['kind' => 'disk', 'pct' => (float) $c['last_value'], 'per_day' => $f['per_day'] ?? null, 'days_to_full' => $f['days_to_full'] ?? null];
        }
        // 1.200.0: database: het beeld van de laatste meting (Db::check bewaart het in agent_data)
        if ($type === 'mysql') {
            $o = Db::opts($c);
            $st = Db::state($c);
            if (is_array($st['m'] ?? null)) {
                return ['kind' => 'db', 'via' => $o['via'], 'where' => $o['where'], 'container' => $o['container']] + $st['m'];
            }
            return null;
        }
        if (in_array($type, ['ssl', 'domain'], true) && $c['last_value'] !== null) {
            return ['kind' => 'days', 'days' => (float) $c['last_value']];
        }
        return null;
    }
}
