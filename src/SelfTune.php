<?php
declare(strict_types=1);

/**
 * 1.334.0: Zelf instellen. RzSense past drie dingen zelf aan, en dit is de plek waar de regels daarvoor staan:
 *  - thresh: een handmatige check omzetten naar slim met vangnet (Smart::autoSwitch, instelling smart_auto);
 *  - fails:  "melden na N" bijstellen uit de storingen van 30 dagen (Advice, instelling alert_auto);
 *  - noise:  ruis dempen: een check die in 7 dagen vaak meldde en steeds binnen 5 minuten herstelde (noise_auto).
 *
 * De regels: de hoofdschakelaar (self_tune) en de schakelaar van de soort staan aan; de check mag het (checks.self_tune);
 * en hooguit één aanpassing per check per week. Elke aanpassing staat in het logboek en is terug te draaien. Draai je er
 * een terug, dan stelt RzSense die check niet meer zelf in tot je hem weer "slim" zet. RzSense zet nooit een check uit en
 * verschuift nooit een bestaand vangnet.
 */
final class SelfTune
{
    public const KINDS = ['thresh' => 'smart_auto', 'fails' => 'alert_auto', 'noise' => 'noise_auto'];
    private const WEEK = 7 * 86400;

    /** Staat deze soort aan (hoofdschakelaar en de eigen schakelaar)? */
    public static function on(string $kind): bool
    {
        return isset(self::KINDS[$kind]) && setting('self_tune', '1') === '1' && setting(self::KINDS[$kind], '1') === '1';
    }

    /** Mag RzSense deze check nu aanpassen? */
    public static function allowed(int $checkId, string $kind): bool
    {
        if (!self::on($kind)) {
            return false;
        }
        $c = q('SELECT self_tune, self_tuned_at FROM checks WHERE id = ?', [$checkId])->fetch();
        return $c && (int) $c['self_tune'] === 1 && ($c['self_tuned_at'] === null || strtotime((string) $c['self_tuned_at']) <= time() - self::WEEK);
    }

    /** Een aanpassing is gedaan: deze week niet nog een keer */
    public static function mark(int $checkId): void
    {
        q('UPDATE checks SET self_tuned_at = ? WHERE id = ?', [now(), $checkId]);
    }

    /** Teruggedraaid in het logboek: deze check stel je voortaan zelf in */
    public static function off(int $checkId): void
    {
        q('UPDATE checks SET self_tune = 0, self_off_at = ? WHERE id = ?', [now(), $checkId]);
    }

    /** "Weer slim": RzSense mag deze check weer zelf instellen */
    public static function resume(int $checkId): bool
    {
        $before = ChangeLog::checkRow($checkId);
        if (!$before || (int) ($before['self_tune'] ?? 1) === 1) {
            return false;
        }
        q('UPDATE checks SET self_tune = 1, self_off_at = NULL, self_tuned_at = NULL WHERE id = ?', [$checkId]);
        ChangeLog::record('check', $checkId, 'update', $before['_host'] . ' · ' . $before['name'] . ' (🧠 ' . t('weer zelf instellen') . ')', $before, ChangeLog::checkRow($checkId));
        return true;
    }

    /**
     * De checks die je zelf instelt
     * @return list<array{id: int, name: string, host: string, off_at: ?string}>
     */
    public static function manual(): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'host' => (string) $r['host'], 'off_at' => $r['self_off_at']],
            q('SELECT c.id, c.name, c.self_off_at, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.self_tune = 0 ORDER BY c.self_off_at DESC, h.name, c.name')->fetchAll());
    }

    /** Einde van de proefweek voor ruis dempen: de eerste 7 dagen alleen voorstellen (in Advies), daarna zelf toepassen */
    public static function trialUntil(): int
    {
        $t = (int) setting('noise_trial_until', '0');
        if ($t <= 0) {
            $t = time() + self::WEEK;
            save_setting('noise_trial_until', (string) $t);
        }
        return $t;
    }

    public static function inTrial(): bool
    {
        return self::trialUntil() > time();
    }

    /**
     * Voor de beheerportal: de schakelaars, de proefweek, wat er de laatste 30 dagen zelf is aangepast en wat je zelf instelt
     * @return array<string, mixed>
     */
    public static function state(): array
    {
        return ['on' => setting('self_tune', '1') === '1', 'thresh' => setting('smart_auto', '1') === '1', 'fails' => setting('alert_auto', '1') === '1',
            'noise' => setting('noise_auto', '1') === '1', 'trial_until' => date('Y-m-d H:i:s', self::trialUntil()), 'trial' => self::inTrial(),
            'changes_30d' => (int) q('SELECT COUNT(*) FROM change_log WHERE auto = 1 AND at >= NOW() - INTERVAL 30 DAY')->fetchColumn(),
            'undone_30d' => (int) q('SELECT COUNT(*) FROM change_log WHERE auto = 1 AND undone_at IS NOT NULL AND at >= NOW() - INTERVAL 30 DAY')->fetchColumn(),
            'manual' => self::manual()];
    }

    /**
     * Ruismakers: een check met minstens 5 meldingen in 7 dagen, waarvan elke storing binnen 5 minuten vanzelf herstelde.
     * Het voorstel: melden na N, lang genoeg om die korte storingen over te slaan (hooguit 6 metingen).
     * @param array<int, list<array{0: int, 1: int}>> $episodes storingen per check uit de laatste 7 dagen: [begin, duur in seconden]
     * @return list<array{0: string, 1: int, 2: string, 3: array<string, mixed>}> [soort, check, vingerafdruk, gegevens]
     */
    public static function noise(array $episodes): array
    {
        $out = [];
        $alerts = q('SELECT check_id, COUNT(*) FROM alerts WHERE check_id IS NOT NULL AND status <> ? AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY check_id HAVING COUNT(*) >= 5', [STATUS_OK])->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$alerts) {
            return [];
        }
        $checks = q('SELECT id, interval_sec, notify_fails, self_tune FROM checks WHERE enabled = 1 AND info_only = 0')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($alerts as $id => $n) {
            $c = $checks[$id] ?? null;
            $list = $episodes[$id] ?? [];
            if (!$c || (int) $c['self_tune'] !== 1 || count($list) < 5 || array_filter($list, static fn($e) => $e[1] > 300)) {
                continue;
            }
            $cur = Monitor::failsNeeded($c);
            $iv = max(30, (int) $c['interval_sec']);
            $want = max(2, min(6, (int) ceil(max(array_map(static fn($e) => $e[1], $list)) / $iv) + 1));
            if ($want > $cur) {
                $out[] = ['noise', (int) $id, "n$want", ['n' => $want, 'cur' => $cur, 'episodes' => count($list), 'short' => count($list), 'alerts' => (int) $n]];
            }
        }
        return $out;
    }
}
