<?php
declare(strict_types=1);

/**
 * ntfy (1.140.0): pushmeldingen via ntfy.sh of een eigen ntfy-server. Naast of in plaats van Pushover; samen heten ze
 * in RzSense "Push" (zie Push). Verstuurd als JSON naar de server (dan kan er gewoon een emoji in de titel).
 * Prioriteit volgt de Pushover-schaal: -2..2 wordt 1..5 (2 = emergency = 5, "max").
 * Toegang (optioneel): een token (tk_…) of gebruiker:wachtwoord.
 */
final class Ntfy
{
    public static function enabled(): bool
    {
        return setting('ntfy_enabled', '0') === '1' && self::server() !== '' && trim((string) setting('ntfy_topic', '')) !== '';
    }

    public static function server(?string $url = null): string
    {
        $url = rtrim(trim($url ?? (string) setting('ntfy_server', 'https://ntfy.sh')), '/');
        return preg_match('~^https?://[^\s/]+(/[^\s]*)?$~i', $url) ? $url : '';
    }

    /**
     * $extra: 'url' en 'url_title' worden de klik-actie en een knop onder het bericht.
     * $cfg (voor de testknop): server, topic, token uit het formulier in plaats van de opgeslagen instellingen.
     * @return array{ok: bool, error: ?string}
     */
    public static function send(string $title, string $message, int $priority = 0, array $extra = [], ?array $cfg = null): array
    {
        $server = self::server($cfg['server'] ?? null);
        $topic = trim((string) ($cfg['topic'] ?? setting('ntfy_topic', '')));
        $token = (string) ($cfg['token'] ?? setting('ntfy_token', ''));
        if ($server === '') {
            return ['ok' => false, 'error' => t('ntfy-server ontbreekt of is geen http(s)-adres')];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic)) {
            return ['ok' => false, 'error' => t('ntfy-onderwerp ontbreekt (alleen letters, cijfers, - en _)')];
        }
        $data = [
            'topic' => $topic,
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr($message, 0, 4000),
            'priority' => max(1, min(5, $priority + 3)),
        ];
        if (!empty($extra['url'])) {
            $data['click'] = (string) $extra['url'];
            $data['actions'] = [['action' => 'view', 'label' => mb_substr((string) ($extra['url_title'] ?? 'Open'), 0, 40) ?: 'Open', 'url' => (string) $extra['url']]];
        }
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: ' . (str_contains($token, ':') ? 'Basic ' . base64_encode($token) : 'Bearer ' . $token);
        }
        $ch = curl_init($server);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => t('cURL-fout') . ": $err"];
        }
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'error' => null];
        }
        $json = json_decode((string) $body, true);
        $msg = is_array($json) && isset($json['error']) ? (string) $json['error'] : "HTTP $code";
        if ($code === 401 || $code === 403) {
            $msg .= ' — ' . t('controleer het token of de gebruiker');
        }
        return ['ok' => false, 'error' => $msg];
    }
}
