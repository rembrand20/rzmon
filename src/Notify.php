<?php

declare(strict_types=1);

/**
 * 1.235.0: één route om een melding te versturen via Pushover/ntfy (Push), e-mail en de Home Assistant-webhook.
 * Eerst stond dit op negen plekken uitgeschreven en liep het uit elkaar (e-mail "alleen als push mislukt", het
 * webhookfilter "alleen critical", de link naar RzSense, de tijd in de e-mail). Wie verstuurt, beslist zelf over
 * stille uren en kanalen (NotifyRules) en schrijft zelf de regel in Meldingen; Notify doet alleen het versturen.
 */
final class Notify
{
    /**
     * @param array{channels?: ?array, prio?: int, status?: int, event?: string, anchor?: string, push_opts?: array, mail_extra?: string,
     *     webhook?: ?array, webhook_extra?: array, webhook_filter?: bool, host?: ?string, host_id?: ?int} $o
     *  - channels: ['pushover' => bool, 'email' => bool, 'webhook' => bool]; null = alle ingeschakelde kanalen
     *  - anchor: deel na het adres van RzSense voor de link (bv. '#ids'); push_opts vervangt de link (bv. de bevestig-link)
     *  - webhook: een eigen payload; anders een standaard-payload (+ webhook_extra)
     *  - webhook_filter: "alleen CRITICAL en herstel" (Instellingen → Home Assistant) toepassen; standaard als channels null is
     * @return array{push: ?bool, email: ?bool, webhook: ?bool, errors: list<string>, receipt: ?string}
     */
    public static function send(string $title, string $message, array $o = []): array
    {
        $ch = $o['channels'] ?? null;
        $status = (int) ($o['status'] ?? STATUS_OK);
        $event = (string) ($o['event'] ?? 'info');
        $base = public_url();
        $out = ['push' => null, 'email' => null, 'webhook' => null, 'errors' => [], 'receipt' => null];

        if (Push::enabled() && ($ch === null || !empty($ch['pushover']))) {
            $opts = $o['push_opts'] ?? ($base !== '' ? ['url' => $base . '/' . ltrim((string) ($o['anchor'] ?? ''), '/'), 'url_title' => t('Open RzSense')] : []);
            $r = Push::send($title, $message . "\n" . date('d-m-Y H:i:s'), (int) ($o['prio'] ?? 0), $opts);
            $out['push'] = (bool) $r['ok'];
            if (!empty($r['error'])) {
                $out['errors'][] = $r['error'];
            }
            $out['receipt'] = $r['receipt'] ?? null;
        }

        // E-mail: altijd, of alleen als reserve wanneer push uit staat of mislukt
        if (setting('email_enabled', '0') === '1' && ($ch === null || !empty($ch['email'])) && (setting('email_mode', 'always') !== 'fallback' || $out['push'] !== true)) {
            $anchor = ltrim((string) ($o['anchor'] ?? ''), '/');
            $r = Mailer::send($title, $message . "\n\n" . t('Tijd') . ': ' . date('d-m-Y H:i:s')
                . (($o['mail_extra'] ?? '') !== '' ? "\n\n" . $o['mail_extra'] : '')
                . ($base !== '' ? "\n\nRzSense: $base/$anchor" : ''));
            $out['email'] = (bool) $r['ok'];
            if (!$r['ok']) {
                $out['errors'][] = 'E-mail: ' . $r['error'];
            }
        }

        $filter = $o['webhook_filter'] ?? ($ch === null);
        if (setting('ha_webhook_enabled', '0') === '1' && ($ch === null || !empty($ch['webhook']))
            && (!$filter || setting('ha_webhook_events', 'all') !== 'crit' || $status === STATUS_CRIT || $event === 'recovery')) {
            $payload = $o['webhook'] ?? (['source' => 'rzmon', 'event' => $event, 'status' => $status, 'status_text' => STATUS_LABELS[$status] ?? '',
                'host' => $o['host'] ?? null, 'host_id' => $o['host_id'] ?? null, 'check' => null, 'title' => $title, 'message' => $message,
                'time' => date('c'), 'url' => $base ?: null] + ($o['webhook_extra'] ?? []));
            $r = Webhook::send($payload);
            $out['webhook'] = (bool) $r['ok'];
            if (!$r['ok']) {
                $out['errors'][] = 'Webhook: ' . $r['error'];
            }
        }
        return $out;
    }

    /** Wat niet verstuurd kon worden, om achter de tekst in Meldingen te zetten ('' als alles goed ging) */
    public static function errText(array $errors): string
    {
        return $errors ? ' [' . t('niet verzonden') . ': ' . implode('; ', $errors) . ']' : '';
    }

    /** Voor de kolommen sent_ok/email_ok/webhook_ok: null = kanaal niet gebruikt */
    public static function flag(?bool $v): ?int
    {
        return $v === null ? null : ($v ? 1 : 0);
    }

    /** Alleen een echt IP-adres bij een melding (geen poortnummer of naam; 1.223.3) */
    public static function ip(?string $ip): ?string
    {
        $ip = $ip !== null ? trim($ip, '[] ') : null;
        return $ip !== null && $ip !== '' && @inet_pton($ip) !== false ? mb_substr($ip, 0, 45) : null;
    }
}
