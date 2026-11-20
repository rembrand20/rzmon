<?php
declare(strict_types=1);

/**
 * Push (1.140.0): pushmeldingen op je telefoon via Pushover, ntfy en/of Web Push (1.285.0; wat aan staat).
 * 1.302.0: Web Push is een eigen kanaal in de meldregels (kolom "Web Push" naast "Pushover / ntfy"); Notify kiest per melding
 * welke diensten ($meta['svc']). Zonder svc: alle diensten die aan staan (rapporten, de noodlink).
 * Gelukt = minstens één dienst kwam aan. Fouten krijgen de naam van de dienst ervoor.
 */
final class Push
{
    /** Pushover en ntfy (zonder Web Push) */
    public const CLASSIC = ['pushover', 'ntfy'];

    /**
     * Is dit een critical (of het herstel van een critical)? Zonder status (bv. een rapport): nee. Voor de keuze per Web Push-apparaat.
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool} $meta
     */
    public static function isCrit(array $meta): bool
    {
        $st = $meta['status'] ?? null;
        return $st === STATUS_CRIT || (($meta['event'] ?? '') === 'recovery' && ($meta['previous'] ?? null) === STATUS_CRIT);
    }

    public static function enabled(): bool
    {
        return self::classicEnabled() || WebPush::enabled();
    }

    /** Staat Pushover of ntfy aan? */
    public static function classicEnabled(): bool
    {
        return setting('pushover_enabled', '0') === '1' || Ntfy::enabled();
    }

    /** Welke diensten aan staan, voor in een overzicht: bv. "Pushover + ntfy" */
    public static function names(): string
    {
        return implode(' + ', array_filter([
            setting('pushover_enabled', '0') === '1' && setting('pushover_user', '') !== '' && setting('pushover_token', '') !== '' ? 'Pushover' : '',
            Ntfy::enabled() ? 'ntfy' : '', WebPush::enabled() ? 'Web Push' : '']));
    }

    /**
     * $meta: status, event, previous (status ervoor), ids (een IDS-melding; voor de keuze per Web Push-apparaat), force (elk apparaat)
     * en svc (1.302.0: welke diensten, bv. ['pushover', 'ntfy'] of ['webpush']; leeg = alle).
     * skipped: geen enkel Web Push-apparaat wil deze melding (geen fout).
     * @return array{ok: bool, error: ?string, receipt: ?string, skipped: bool}
     * @param array<mixed> $extra
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool, svc?: list<string>} $meta
     */
    public static function send(string $title, string $message, int $priority = 0, array $extra = [], array $meta = []): array
    {
        $want = $meta['svc'] ?? [...self::CLASSIC, 'webpush'];
        $ok = false;
        $errors = [];
        $receipt = null;
        $tried = false;
        if (in_array('pushover', $want, true) && setting('pushover_enabled', '0') === '1') {
            $tried = true;
            $r = Pushover::send($title, $message, $priority, null, null, $extra);
            $ok = $r['ok'];
            $receipt = $r['receipt'] ?? null;
            $r['ok'] || $errors[] = 'Pushover: ' . $r['error'];
        }
        if (in_array('ntfy', $want, true)) {
            if (Ntfy::enabled()) {
                $tried = true;
                $r = Ntfy::send($title, $message, $priority, $extra);
                $ok = $ok || $r['ok'];
                $r['ok'] || $errors[] = 'ntfy: ' . $r['error'];
            } elseif (setting('ntfy_enabled', '0') === '1') {
                $errors[] = 'ntfy: ' . t('server of onderwerp ontbreekt');
            }
        }
        $skipped = false;
        if (in_array('webpush', $want, true) && WebPush::enabled()) {   // 1.285.0: rechtstreeks naar de aangemelde telefoons en browsers
            $r = WebPush::send($title, $message, $priority, $extra, null, $meta);
            if (!empty($r['skipped'])) {
                $skipped = true;   // geen apparaat wil deze melding: geen fout
            } else {
                $tried = true;
                $ok = $ok || $r['ok'];
                $r['error'] === null || $errors[] = 'Web Push: ' . $r['error'];
            }
        }
        if (!$ok && !$errors && !$tried && $skipped) {
            return ['ok' => false, 'error' => null, 'receipt' => null, 'skipped' => true];
        }
        if (!$ok && !$errors) {
            $errors[] = t('geen pushdienst aan');
        }
        return ['ok' => $ok, 'error' => $errors ? implode('; ', $errors) : null, 'receipt' => $receipt, 'skipped' => false];
    }
}
