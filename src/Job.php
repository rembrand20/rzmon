<?php

declare(strict_types=1);

/**
 * 1.252.0: voortgang van een lange taak (netwerkscan, poortscan), zodat de pagina kan laten zien hoe ver hij is en hem kan
 * stoppen. Eén rij per taak (sleutel), bijgewerkt hooguit een paar keer per seconde. De taak zelf vraagt met stopped()
 * of iemand op Stoppen klikte, en stopt dan netjes (wat al klaar is, blijft bewaard).
 */
final class Job
{
    /** Na zoveel seconden zonder teken van leven geldt een taak als vastgelopen (PHP afgebroken, server herstart) */
    private const STALE = 90;

    private static array $last = [];

    /** Beginnen. false als er al een (levende) taak met deze sleutel loopt. */
    public static function start(string $k, string $who, int $steps): bool
    {
        $cur = self::get($k);
        if ($cur && $cur['state'] === 'running' && !$cur['stale']) {
            return false;
        }
        q("REPLACE INTO jobs (k, state, who, stage, step, steps, done, total, label, msg, stop, started_at, updated_at)
            VALUES (?, 'running', ?, '', 0, ?, 0, 0, '', NULL, 0, NOW(), NOW())", [$k, mb_substr($who, 0, 100), $steps]);
        self::$last[$k] = 0.0;
        return true;
    }

    /** Een nieuwe stap (1, 2 …) met een naam; done/total = 0 = (nog) onbekend hoeveel */
    public static function step(string $k, int $step, string $stage, int $total = 0, string $label = ''): void
    {
        q('UPDATE jobs SET step = ?, stage = ?, done = 0, total = ?, label = ?, updated_at = NOW() WHERE k = ?', [$step, mb_substr($stage, 0, 40), $total, mb_substr($label, 0, 200), $k]);
        self::$last[$k] = 0.0;   // de eerste stand van een nieuwe stap altijd meteen tonen
    }

    /** Hoe ver in deze stap; schrijft hooguit 4 keer per seconde (en altijd als hij klaar is) */
    public static function tick(string $k, int $done, string $label = ''): void
    {
        $now = microtime(true);
        if ($now - (self::$last[$k] ?? 0) < 0.25) {
            return;
        }
        self::$last[$k] = $now;
        q('UPDATE jobs SET done = ?, label = IF(? = \'\', label, ?), updated_at = NOW() WHERE k = ?', [$done, $label, mb_substr($label, 0, 200), $k]);
    }

    /** Klikte iemand op Stoppen? */
    public static function stopped(string $k): bool
    {
        return (bool) q('SELECT stop FROM jobs WHERE k = ?', [$k])->fetchColumn();
    }

    public static function finish(string $k, string $state, string $msg = ''): void
    {
        q('UPDATE jobs SET state = ?, msg = ?, updated_at = NOW() WHERE k = ?', [$state, mb_substr($msg, 0, 2000), $k]);
    }

    /** Stoppen vragen (de taak stopt bij zijn volgende stapje) */
    public static function requestStop(string $k): bool
    {
        return q("UPDATE jobs SET stop = 1 WHERE k = ? AND state = 'running'", [$k])->rowCount() > 0;
    }

    /** @return array|null de stand, met elapsed (seconden) en stale (vastgelopen) */
    public static function get(string $k): ?array
    {
        $r = q('SELECT *, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS idle FROM jobs WHERE k = ?', [$k])->fetch();
        if (!$r) {
            return null;
        }
        $stale = $r['state'] === 'running' && (int) $r['idle'] > self::STALE;
        return ['key' => $r['k'], 'state' => $stale ? 'error' : $r['state'], 'who' => $r['who'], 'stage' => $r['stage'], 'step' => (int) $r['step'], 'steps' => (int) $r['steps'],
            'done' => (int) $r['done'], 'total' => (int) $r['total'], 'label' => $r['label'], 'msg' => $stale ? t('Vastgelopen: de taak gaf al een tijd geen teken van leven') : (string) $r['msg'],
            'stop' => (bool) $r['stop'], 'elapsed' => (int) $r['elapsed'], 'idle' => (int) $r['idle'], 'stale' => $stale, 'running' => $r['state'] === 'running' && !$stale];
    }
}
