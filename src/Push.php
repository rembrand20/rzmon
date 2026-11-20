<?php
declare(strict_types=1);

/**
 * Push (1.140.0): één kanaal voor pushmeldingen op je telefoon, via Pushover, ntfy en/of Web Push (1.285.0; wat aan staat).
 * In de meldregels heet het kanaal nog 'pushover' (zo staat het opgeslagen); in het scherm heet het "Push".
 * Gelukt = minstens één van de twee kwam aan. Fouten krijgen de naam van de dienst ervoor.
 * 1.301.0: per dienst wat erheen gaat (pushover_what, ntfy_what, webpush_what): alles, alleen critical (en het herstel daarvan),
 * of alleen als reserve (als de andere diensten het niet afleverden). Bij Web Push daarnaast per apparaat (WebPush::send).
 */
final class Push
{
    public const WHAT = ['all', 'crit', 'fallback'];

    /** Wat gaat er naar deze dienst: all, crit of fallback */
    public static function what(string $svc): string
    {
        $w = (string) setting($svc . '_what', 'all');
        return in_array($w, self::WHAT, true) ? $w : 'all';
    }

    /**
     * Is dit een critical (of het herstel van een critical)? Zonder status (bv. een rapport): nee.
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool} $meta
     */
    public static function isCrit(array $meta): bool
    {
        $st = $meta['status'] ?? null;
        return $st === STATUS_CRIT || (($meta['event'] ?? '') === 'recovery' && ($meta['previous'] ?? null) === STATUS_CRIT);
    }

    /**
     * Mag deze melding naar deze dienst (zonder de reserve-regel)?
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool} $meta
     */
    public static function wants(string $svc, array $meta): bool
    {
        return !empty($meta['force']) || self::what($svc) !== 'crit' || self::isCrit($meta);
    }

    public static function enabled(): bool
    {
        return setting('pushover_enabled', '0') === '1' || Ntfy::enabled() || WebPush::enabled();
    }

    /** Welke diensten aan staan, voor in een overzicht: bv. "Pushover + ntfy" */
    public static function names(): string
    {
        return implode(' + ', array_filter([
            setting('pushover_enabled', '0') === '1' && setting('pushover_user', '') !== '' && setting('pushover_token', '') !== '' ? 'Pushover' : '',
            Ntfy::enabled() ? 'ntfy' : '', WebPush::enabled() ? 'Web Push' : '']));
    }

    /**
     * $meta (1.301.0): status, event, previous (status ervoor), ids (een IDS-melding) en force (naar elke dienst, bv. de noodlink).
     * Zonder $meta telt de melding als informatie: niet naar een dienst die alleen critical krijgt.
     * @return array{ok: bool, error: ?string, receipt: ?string, skipped: bool}
     * @param array<mixed> $extra
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool} $meta
     */
    public static function send(string $title, string $message, int $priority = 0, array $extra = [], array $meta = []): array
    {
        $ok = false;
        $errors = [];
        $receipt = null;
        $tried = false;
        $svcs = [];
        if (setting('pushover_enabled', '0') === '1') {
            $svcs[] = 'pushover';
        }
        if (Ntfy::enabled()) {
            $svcs[] = 'ntfy';
        } elseif (setting('ntfy_enabled', '0') === '1') {
            $errors[] = 'ntfy: ' . t('server of onderwerp ontbreekt');
        }
        if (WebPush::enabled()) {   // 1.285.0: rechtstreeks naar de aangemelde telefoons en browsers
            $svcs[] = 'webpush';
        }
        // Eerst de gewone diensten, daarna de reserve (alleen als nog niets aankwam)
        $main = array_values(array_filter($svcs, static fn($s) => !empty($meta['force']) || self::what($s) !== 'fallback'));
        $spare = array_values(array_diff($svcs, $main));
        foreach ([$main, $spare] as $round => $list) {
            if ($round === 1 && $ok) {
                break;
            }
            foreach ($list as $svc) {
                if (!self::wants($svc, $meta)) {
                    continue;
                }
                $tried = true;
                if ($svc === 'pushover') {
                    $r = Pushover::send($title, $message, $priority, null, null, $extra);
                    $ok = $ok || $r['ok'];
                    $receipt = $r['receipt'] ?? null;
                    $r['ok'] || $errors[] = 'Pushover: ' . $r['error'];
                } elseif ($svc === 'ntfy') {
                    $r = Ntfy::send($title, $message, $priority, $extra);
                    $ok = $ok || $r['ok'];
                    $r['ok'] || $errors[] = 'ntfy: ' . $r['error'];
                } else {
                    $r = WebPush::send($title, $message, $priority, $extra, null, $meta);
                    if (!empty($r['skipped'])) {
                        continue;   // geen apparaat wil deze melding: geen fout
                    }
                    $ok = $ok || $r['ok'];
                    $r['error'] === null || $errors[] = 'Web Push: ' . $r['error'];
                }
            }
        }
        if (!$ok && !$errors && !$tried && $svcs) {
            return ['ok' => false, 'error' => null, 'receipt' => null, 'skipped' => true];   // bewust naar geen enkele dienst (filter)
        }
        if (!$ok && !$errors) {
            $errors[] = t('geen pushdienst aan');
        }
        return ['ok' => $ok, 'error' => $errors ? implode('; ', $errors) : null, 'receipt' => $receipt, 'skipped' => false];
    }
}
