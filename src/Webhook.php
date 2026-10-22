<?php
declare(strict_types=1);

/** Meldingen als JSON naar een Home Assistant-webhook (of een andere webhook-ontvanger). */
final class Webhook
{
    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function send(array $payload, ?string $url = null, ?bool $verifySsl = null): array
    {
        $url ??= trim((string) setting('ha_webhook_url', ''));
        $verifySsl ??= setting('ha_webhook_verify_ssl', '1') === '1';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => t('Geen geldige webhook-URL ingesteld')];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'RzMon/1.0',
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => t('cURL-fout') . ": $err"];
        }
        return $code >= 200 && $code < 300
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => "HTTP $code"];
    }
}
