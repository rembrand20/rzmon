<?php
declare(strict_types=1);

final class Pushover
{
    private static function api(string $path): string
    {
        // 'pushover_api' in config.php alleen voor tests; standaard de echte API
        return rtrim((string) config('pushover_api', 'https://api.pushover.net/1'), '/') . $path;
    }

    /**
     * $extra: optioneel 'url' en 'url_title' (link onder het bericht, bv. "✔ Bevestigen").
     * @return array{ok: bool, error: ?string, receipt: ?string}
     */
    public static function send(string $title, string $message, int $priority = 0, ?string $user = null, ?string $token = null, array $extra = []): array
    {
        $user ??= (string) setting('pushover_user', '');
        $token ??= (string) setting('pushover_token', '');
        if ($user === '' || $token === '') {
            return ['ok' => false, 'error' => t('Pushover user key of API token ontbreekt'), 'receipt' => null];
        }

        $data = [
            'token' => $token,
            'user' => $user,
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr($message, 0, 1024),
            'priority' => max(-2, min(2, $priority)),
            'timestamp' => time(),
        ];
        // Emergency (2) vereist retry/expire
        if ($data['priority'] === 2) {
            $data['retry'] = 60;
            $data['expire'] = 3600;
        }
        $sound = (string) setting('pushover_sound', '');
        if ($sound !== '') {
            $data['sound'] = $sound;
        }
        if (!empty($extra['url'])) {
            $data['url'] = mb_substr((string) $extra['url'], 0, 512);
            $data['url_title'] = mb_substr((string) ($extra['url_title'] ?? ''), 0, 100);
        }

        $ch = curl_init(self::api('/messages.json'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => t('cURL-fout') . ": $err", 'receipt' => null];
        }
        $json = json_decode((string) $body, true);
        if ($code === 200 && ($json['status'] ?? 0) === 1) {
            // Bij Emergency (2) geeft Pushover een receipt om de bevestiging te volgen
            return ['ok' => true, 'error' => null, 'receipt' => $json['receipt'] ?? null];
        }
        $msg = isset($json['errors']) ? implode('; ', (array) $json['errors']) : "HTTP $code";
        return ['ok' => false, 'error' => $msg, 'receipt' => null];
    }

    /** Status van een Emergency-melding: is hij in de Pushover-app bevestigd? */
    public static function receipt(string $receipt): ?array
    {
        $url = self::api('/receipts/' . rawurlencode($receipt) . '.json?token=' . rawurlencode((string) setting('pushover_token', '')));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ($json['status'] ?? 0) === 1 ? $json : null;
    }

    /** Stopt het herhalen van een Emergency-melding (na bevestigen in RzMon of herstel). */
    public static function cancel(string $receipt): void
    {
        $ch = curl_init(self::api('/receipts/' . rawurlencode($receipt) . '/cancel.json'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['token' => (string) setting('pushover_token', '')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
