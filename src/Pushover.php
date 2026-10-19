<?php
declare(strict_types=1);

final class Pushover
{
    private const API = 'https://api.pushover.net/1/messages.json';

    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function send(string $title, string $message, int $priority = 0, ?string $user = null, ?string $token = null): array
    {
        $user ??= (string) setting('pushover_user', '');
        $token ??= (string) setting('pushover_token', '');
        if ($user === '' || $token === '') {
            return ['ok' => false, 'error' => 'Pushover user key of API token ontbreekt'];
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

        $ch = curl_init(self::API);
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
            return ['ok' => false, 'error' => "cURL fout: $err"];
        }
        $json = json_decode((string) $body, true);
        if ($code === 200 && ($json['status'] ?? 0) === 1) {
            return ['ok' => true, 'error' => null];
        }
        $msg = isset($json['errors']) ? implode('; ', (array) $json['errors']) : "HTTP $code";
        return ['ok' => false, 'error' => $msg];
    }
}
