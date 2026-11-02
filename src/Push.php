<?php
declare(strict_types=1);

/**
 * Push (1.140.0): één kanaal voor pushmeldingen op je telefoon, via Pushover en/of ntfy (wat aan staat).
 * In de meldregels heet het kanaal nog 'pushover' (zo staat het opgeslagen); in het scherm heet het "Push".
 * Gelukt = minstens één van de twee kwam aan. Fouten krijgen de naam van de dienst ervoor.
 */
final class Push
{
    public static function enabled(): bool
    {
        return setting('pushover_enabled', '0') === '1' || Ntfy::enabled();
    }

    /** Welke diensten aan staan, voor in een overzicht: bv. "Pushover + ntfy" */
    public static function names(): string
    {
        return implode(' + ', array_filter([
            setting('pushover_enabled', '0') === '1' && setting('pushover_user', '') !== '' && setting('pushover_token', '') !== '' ? 'Pushover' : '',
            Ntfy::enabled() ? 'ntfy' : '']));
    }

    /** @return array{ok: bool, error: ?string, receipt: ?string} */
    public static function send(string $title, string $message, int $priority = 0, array $extra = []): array
    {
        $ok = false;
        $errors = [];
        $receipt = null;
        if (setting('pushover_enabled', '0') === '1') {
            $r = Pushover::send($title, $message, $priority, null, null, $extra);
            $ok = $r['ok'];
            $receipt = $r['receipt'] ?? null;
            $r['ok'] || $errors[] = 'Pushover: ' . $r['error'];
        }
        if (Ntfy::enabled()) {
            $r = Ntfy::send($title, $message, $priority, $extra);
            $ok = $ok || $r['ok'];
            $r['ok'] || $errors[] = 'ntfy: ' . $r['error'];
        } elseif (setting('ntfy_enabled', '0') === '1') {
            $errors[] = 'ntfy: ' . t('server of onderwerp ontbreekt');
        }
        if (!$ok && !$errors) {
            $errors[] = t('geen pushdienst aan');
        }
        return ['ok' => $ok, 'error' => $errors ? implode('; ', $errors) : null, 'receipt' => $receipt];
    }
}
