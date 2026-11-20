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
     * @param array{channels?: ?array, prio?: int, status?: int, event?: string, anchor?: string, push_opts?: array, mail_extra?: string, ids?: bool,
     *     webhook?: ?array, webhook_extra?: array, webhook_filter?: bool, host?: ?string, host_id?: ?int} $o
     *  - channels: ['pushover' => bool, 'webpush' => bool, 'email' => bool, 'webhook' => bool]; null = alle ingeschakelde kanalen
     *    ('pushover' = Pushover en ntfy; zonder 'webpush' volgt Web Push 'pushover')
     *  - anchor: deel na het adres van RzSense voor de link (bv. '#ids'); push_opts vervangt de link (bv. de bevestig-link)
     *  - webhook: een eigen payload; anders een standaard-payload (+ webhook_extra)
     *  - webhook_filter: "alleen CRITICAL en herstel" (Instellingen → Home Assistant) toepassen; standaard als channels null is
     *  - ids: true = een IDS-melding (Discord/Slack/Teams alleen als daar "ook IDS" aan staat)
     *  - previous: de status ervoor (1.301.0: het herstel van een critical gaat ook naar een pushdienst die alleen critical krijgt)
     * @return array{push: ?bool, email: ?bool, webhook: ?bool, chat: ?bool, errors: list<string>, receipt: ?string, push_skipped: bool}
     * @param array<mixed> $o
     */
    public static function send(string $title, string $message, array $o = []): array
    {
        $ch = $o['channels'] ?? null;
        $status = (int) ($o['status'] ?? STATUS_OK);
        $event = (string) ($o['event'] ?? 'info');
        $base = public_url();
        $out = ['push' => null, 'email' => null, 'webhook' => null, 'chat' => null, 'errors' => [], 'receipt' => null, 'push_skipped' => false];

        // 1.302.0: Pushover/ntfy en Web Push zijn twee kanalen. Zonder 'webpush' in channels volgt Web Push de keuze voor 'pushover'
        // (IDS, beveiliging en oudere meldregels). Kwam Web Push niet aan of staat het uit, dan via Pushover/ntfy (webpush_fallback).
        $wantClassic = $ch === null || !empty($ch['pushover']);
        $wantWeb = $ch === null || !empty($ch['webpush'] ?? $ch['pushover'] ?? false);
        $opts = $o['push_opts'] ?? ($base !== '' ? ['url' => $base . '/' . ltrim((string) ($o['anchor'] ?? ''), '/'), 'url_title' => t('Open RzSense')] : []);
        $meta = ['status' => $status, 'event' => $event, 'previous' => isset($o['previous']) ? (int) $o['previous'] : null, 'ids' => !empty($o['ids'])];
        $pushMsg = $message . "\n" . date('d-m-Y H:i:s');
        $classicDone = false;
        $results = [];
        if ($wantClassic && Push::classicEnabled()) {
            $results[] = $r = Push::send($title, $pushMsg, (int) ($o['prio'] ?? 0), $opts, $meta + ['svc' => Push::CLASSIC]);
            $classicDone = true;
            $out['receipt'] = $r['receipt'] ?? null;
        }
        if ($wantWeb) {
            $web = WebPush::enabled() ? Push::send($title, $pushMsg, (int) ($o['prio'] ?? 0), $opts, $meta + ['svc' => ['webpush']]) : null;
            $webOk = $web !== null && ($web['ok'] || !empty($web['skipped']));
            if ($webOk) {
                $results[] = $web;
            } elseif (!$classicDone && Push::classicEnabled() && setting('webpush_fallback', '1') === '1') {
                // Web Push staat uit, heeft geen apparaten of kwam niet aan: via Pushover/ntfy, met de reden erbij
                $why = $web === null ? t('Web Push staat uit') : t('Web Push kwam niet aan');
                $results[] = $r = Push::send($title, $pushMsg . "\n(" . $why . ')', (int) ($o['prio'] ?? 0), $opts, $meta + ['svc' => Push::CLASSIC]);
                $out['receipt'] = $r['receipt'] ?? null;
                if (!$r['ok'] && $web !== null) {
                    $results[] = $web;
                }
            } elseif ($web !== null) {
                $results[] = $web;
            } elseif (!$classicDone) {
                $results[] = ['ok' => false, 'error' => 'Web Push: ' . t('staat uit of er is geen apparaat aangemeld'), 'receipt' => null, 'skipped' => false];
            }
        }
        if ($results) {
            $sent = array_filter($results, static fn($x) => empty($x['skipped']));
            $out['push_skipped'] = !$sent;
            $out['push'] = $sent ? (bool) array_filter(array_column($sent, 'ok')) : null;
            foreach ($sent as $x) {
                if (!empty($x['error'])) {
                    $out['errors'][] = $x['error'];
                }
            }
        }

        // E-mail: altijd, of alleen als reserve wanneer push uit staat of mislukt
        if (setting('email_enabled', '0') === '1' && ($ch === null || !empty($ch['email'])) && (setting('email_mode', 'always') !== 'fallback' || ($out['push'] !== true && !$out['push_skipped']))) {
            $anchor = ltrim((string) ($o['anchor'] ?? ''), '/');
            $r = Mailer::send($title, $message . "\n\n" . t('Tijd') . ': ' . date('d-m-Y H:i:s')
                . (($o['mail_extra'] ?? '') !== '' ? "\n\n" . $o['mail_extra'] : '')
                . ($base !== '' ? "\n\nRzSense: $base/$anchor" : ''));
            $out['email'] = (bool) $r['ok'];
            if (!$r['ok']) {
                $out['errors'][] = 'E-mail: ' . $r['error'];
            }
        }

        // 1.284.0: Discord, Slack en Teams volgen de beslissing voor push (of 'chat' als die apart is meegegeven), met hun eigen filter
        if (Chat::enabled() && ($ch === null || !empty($ch['chat'] ?? (!empty($ch['pushover']) || !empty($ch['webpush']))))) {   // 1.302.0: een van de twee
            $link = (string) (($o['push_opts']['url'] ?? '') ?: ($base !== '' ? $base . '/' . ltrim((string) ($o['anchor'] ?? ''), '/') : ''));
            $r = Chat::send($title, $message, $status, $event, $link, $o['host'] ?? null, !empty($o['ids']));
            $out['chat'] = $r['ok'];
            array_push($out['errors'], ...$r['errors']);
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

    /**
     * Wat niet verstuurd kon worden, om achter de tekst in Meldingen te zetten ('' als alles goed ging)
     * @param array<mixed> $errors
     */
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
