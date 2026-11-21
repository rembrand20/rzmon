<?php
declare(strict_types=1);

/**
 * 1.285.0: Web Push. Meldingen rechtstreeks op je telefoon of computer, zonder app van een andere dienst:
 * Android (Chrome, Firefox, Edge …), iPhone en iPad (iOS/iPadOS 16.4+, als RzSense op het beginscherm staat) en desktop.
 *
 * - Hoort bij het kanaal 📲 Push (samen met Pushover en ntfy): dezelfde meldregels, stille uren, prioriteit.
 * - Elke gebruiker meldt zijn eigen apparaten aan (Mijn account). De browser maakt een abonnement bij zijn eigen pushdienst
 *   (Google, Apple, Mozilla, Microsoft); RzSense stuurt daar het bericht heen.
 * - Versleuteld van RzSense tot het apparaat (RFC 8291, aes128gcm): de pushdienst kan het bericht niet lezen.
 *   RzSense ondertekent elk bericht met zijn eigen sleutel (VAPID, RFC 8292); de geheime helft blijft in RzSense.
 * - Alleen naar de echte adressen van die pushdiensten (https, gecontroleerd certificaat, geen doorverwijzingen):
 *   het adres komt uit de browser en mag RzSense nooit ergens anders heen laten sturen.
 * - In de VAPID-gegevens staat geen e-mailadres of domein van jou, alleen het adres van het project.
 * - Nodig: RzSense via https met een echt certificaat (bv. via SWAG). Een browser staat Web Push anders niet toe.
 */
final class WebPush
{
    /** Pushdiensten: exact, of met een punt ervoor: elk subdomein */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', '.push.apple.com', 'updates.push.services.mozilla.com',
        '.push.services.mozilla.com', '.notify.windows.com'];
    /** SubjectPublicKeyInfo voor een P-256-sleutel, vóór de 65 bytes van het punt */
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private const CONTACT = 'https://github.com/rembrand20/rzmon';
    private const MAX_PER_SEND = 50;

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? '' : $r;
    }

    public static function validEndpoint(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && (int) $p['port'] !== 443) || empty($p['path']) || strlen($url) > 1000) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        foreach (self::HOSTS as $h) {
            if ($h[0] === '.' ? str_ends_with($host, $h) && strlen($host) > strlen($h) : $host === $h) {
                return true;
            }
        }
        return false;
    }

    /** Aan (hoofdschakelaar) en er is minstens één apparaat */
    public static function enabled(): bool
    {
        if (setting('webpush_enabled', '1') !== '1') {
            return false;
        }
        try {
            return (bool) q('SELECT 1 FROM webpush_subs LIMIT 1')->fetchColumn();
        } catch (Throwable) {
            return false;   // tabel bestaat (nog) niet
        }
    }

    // ------------------------------------------------------------------ sleutels

    /** De VAPID-sleutel van RzSense (maakt hem de eerste keer) */
    private static function vapidKey(): OpenSSLAsymmetricKey
    {
        $pem = (string) setting('webpush_vapid_private', '');
        $k = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if ($k === false) {
            $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            if ($k === false || !openssl_pkey_export($k, $pem)) {
                throw new RuntimeException(t('Kon geen sleutel maken (OpenSSL)'));
            }
            save_setting('webpush_vapid_private', $pem);
            save_setting('webpush_vapid_public', self::b64u(self::rawPublic($k)));
        }
        return $k;
    }

    /** Publieke sleutel voor de browser (applicationServerKey) */
    public static function publicKey(): string
    {
        $k = self::vapidKey();
        $pub = self::b64u(self::rawPublic($k));
        if ($pub !== setting('webpush_vapid_public', '')) {
            save_setting('webpush_vapid_public', $pub);
        }
        return $pub;
    }

    /** Het punt (65 bytes: 0x04, x, y) van een EC-sleutel */
    public static function rawPublic(OpenSSLAsymmetricKey $k): string
    {
        $d = openssl_pkey_get_details($k);
        if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])) {
            throw new RuntimeException('geen EC-sleutel');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Een publieke sleutel (65 bytes) als OpenSSL-sleutel */
    public static function publicFromRaw(string $raw): ?OpenSSLAsymmetricKey
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        return $k === false ? null : $k;
    }

    // ------------------------------------------------------------------ versleutelen en ondertekenen

    /**
     * Bericht versleutelen voor één apparaat (RFC 8291, aes128gcm, één record).
     * $local en $salt alleen voor tests; anders nieuw per bericht.
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?OpenSSLAsymmetricKey $local = null, ?string $salt = null): string
    {
        $peer = self::publicFromRaw($uaPublic);
        if ($peer === null || strlen($authSecret) !== 16) {
            throw new RuntimeException(t('Ongeldige sleutels van het apparaat'));
        }
        $local ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: throw new RuntimeException('OpenSSL');
        $salt ??= random_bytes(16);
        $asPublic = self::rawPublic($local);
        $shared = openssl_pkey_derive($peer, $local, 32);
        if ($shared === false) {
            throw new RuntimeException('ECDH');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('AES-GCM');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
    }

    /** VAPID-kop (Authorization) voor dit adres */
    public static function vapidHeader(string $endpoint, ?int $now = null): string
    {
        $p = parse_url($endpoint);
        $aud = 'https://' . strtolower((string) ($p['host'] ?? ''));
        $k = self::vapidKey();
        $head = self::b64u('{"typ":"JWT","alg":"ES256"}');
        $claims = self::b64u((string) json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => self::CONTACT], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign("$head.$claims", $der, $k, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('ES256');
        }
        return 'vapid t=' . "$head.$claims." . self::b64u(self::derToRaw($der)) . ', k=' . self::b64u(self::rawPublic($k));
    }

    /** ECDSA-handtekening: DER (OpenSSL) naar r||s (JWT) */
    public static function derToRaw(string $der): string
    {
        $o = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$o + 1]);
            $v = ltrim(substr($der, $o + 2, $len), "\0");
            $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
            $o += 2 + $len;
        }
        return $out;
    }

    // ------------------------------------------------------------------ apparaten

    /**
     * Abonnement van de browser opslaan (of bijwerken). Geeft een foutmelding of null.
     * @param array<mixed> $sub
     */
    public static function subscribe(int $userId, array $sub, string $name): ?string
    {
        if ($userId <= 0 || !q('SELECT 1 FROM users WHERE id = ?', [$userId])->fetchColumn()) {
            return t('Log in met je eigen account om dit apparaat aan te melden');
        }
        $ep = (string) ($sub['endpoint'] ?? '');
        $p256 = (string) ($sub['keys']['p256dh'] ?? '');
        $auth = (string) ($sub['keys']['auth'] ?? '');
        if (!self::validEndpoint($ep)) {
            return t('Onbekende pushdienst: alleen Google, Apple, Mozilla en Microsoft');
        }
        if (self::publicFromRaw(self::unb64u($p256)) === null || strlen(self::unb64u($auth)) !== 16) {
            return t('Ongeldige sleutels van het apparaat');
        }
        if ((int) q('SELECT COUNT(*) FROM webpush_subs WHERE user_id = ?', [$userId])->fetchColumn() >= 20) {
            return t('Hooguit 20 apparaten per gebruiker');
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? ''), 0, 100);
        q('INSERT INTO webpush_subs (user_id, endpoint, ep_hash, p256dh, auth, name) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), name = VALUES(name), fails = 0, last_error = NULL',
            [$userId, $ep, hash('sha256', $ep), $p256, $auth, $name]);
        return null;
    }

    /** Afmelden met het adres uit de browser (alleen je eigen apparaat) */
    public static function unsubscribeEndpoint(int $userId, string $endpoint): void
    {
        q('DELETE FROM webpush_subs WHERE ep_hash = ? AND user_id = ?', [hash('sha256', $endpoint), $userId]);
    }

    /** Een apparaat weghalen: je eigen, of als beheerder elk apparaat */
    public static function delete(int $id, int $userId, bool $admin): bool
    {
        return q('DELETE FROM webpush_subs WHERE id = ?' . ($admin ? '' : ' AND user_id = ?'), $admin ? [$id] : [$id, $userId])->rowCount() > 0;
    }

    /**
     * De apparaten voor het scherm (nooit het adres of de sleutels; alleen de dienst).
     * $endpoint: het adres van deze browser, om "dit apparaat" te markeren.
     * @return array<mixed>
     */
    public static function devices(?int $userId, string $endpoint = ''): array
    {
        $mine = $endpoint !== '' ? hash('sha256', $endpoint) : '';
        $rows = q('SELECT w.id, w.user_id, u.username, w.endpoint, w.ep_hash, w.name, w.what, w.created_at, w.last_ok, w.last_error, w.last_error_at, w.fails
                   FROM webpush_subs w JOIN users u ON u.id = w.user_id' . ($userId !== null ? ' WHERE w.user_id = ?' : '') . ' ORDER BY u.username, w.created_at',
            $userId !== null ? [$userId] : [])->fetchAll();
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'user' => $r['username'], 'user_id' => (int) $r['user_id'], 'name' => $r['name'],
            'service' => self::service((string) $r['endpoint']), 'created_at' => $r['created_at'], 'last_ok' => $r['last_ok'],
            'last_error' => $r['last_error'], 'last_error_at' => $r['last_error_at'], 'fails' => (int) $r['fails'], 'what' => (string) ($r['what'] ?? 'all'),
            'this' => $mine !== '' && hash_equals((string) $r['ep_hash'], $mine)], $rows);
    }

    /** 1.301.0: wat een apparaat krijgt instellen (eigen apparaat, of elk apparaat als beheerder) */
    public static function setWhat(int $id, string $what, int $userId, bool $admin): bool
    {
        if (!in_array($what, self::WHAT, true)) {
            return false;
        }
        return q('UPDATE webpush_subs SET what = ? WHERE id = ?' . ($admin ? '' : ' AND user_id = ?'), $admin ? [$what, $id] : [$what, $id, $userId])->rowCount() > 0
            || (bool) q('SELECT 1 FROM webpush_subs WHERE id = ?' . ($admin ? '' : ' AND user_id = ?') . ' AND what = ?', $admin ? [$id, $what] : [$id, $userId, $what])->fetchColumn();
    }

    public static function service(string $endpoint): string
    {
        $h = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        return match (true) {
            str_ends_with($h, 'push.apple.com') => 'Apple',
            str_ends_with($h, 'googleapis.com') => 'Google',
            str_contains($h, 'mozilla.com') => 'Mozilla',
            str_ends_with($h, 'notify.windows.com') => 'Microsoft',
            default => '?',
        };
    }

    // ------------------------------------------------------------------ versturen

    /**
     * Het bericht dat de service worker toont (kort: een pushbericht mag hooguit 4 KB zijn)
     * @param array<mixed> $extra
     */
    public static function payload(string $title, string $message, int $priority, array $extra): string
    {
        $url = (string) ($extra['url'] ?? '');
        $base = public_url();
        $ack = $url !== '' && str_contains($url, 'ack.php') ? $url : '';
        $d = ['t' => mb_substr($title, 0, 120), 'b' => mb_substr($message, 0, 900), 'u' => $ack !== '' ? $ack : ($url !== '' ? $url : $base),
            'o' => $base, 'ol' => t('Open RzSense'), 'q' => $priority <= -1, 'r' => $priority >= 2, 'ts' => time()];
        if ($ack !== '') {
            $d['a'] = $ack;
            $d['al'] = '✔ ' . t('Bevestigen');
        }
        $json = (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        while (strlen($json) > 3000 && mb_strlen($d['b']) > 20) {   // ruim onder 4 KB, ook met veel emoji
            $d['b'] = mb_substr($d['b'], 0, (int) (mb_strlen($d['b']) * 0.7)) . '…';
            $json = (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return $json;
    }

    /** 1.301.0: wat een apparaat krijgt: alles, alleen critical (en het herstel daarvan), of alleen IDS-meldingen */
    public const WHAT = ['all', 'crit', 'ids'];

    /**
     * Wil een apparaat met deze keuze deze melding?
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool} $meta
     */
    public static function deviceWants(string $what, array $meta): bool
    {
        return !empty($meta['force']) || match ($what) {
            'crit' => Push::isCrit($meta),
            'ids' => !empty($meta['ids']),
            default => true,
        };
    }

    /**
     * Naar alle apparaten (of één: $onlyId, zonder filter). Gelukt = minstens één apparaat kreeg het.
     * skipped: geen enkel apparaat wil deze melding (geen fout).
     * @param array<mixed> $extra
     * @param array{status?: ?int, event?: string, previous?: ?int, force?: bool, ids?: bool} $meta
     * @return array{ok: bool, error: ?string, sent: int, skipped?: bool}
     */
    public static function send(string $title, string $message, int $priority = 0, array $extra = [], ?int $onlyId = null, array $meta = []): array
    {
        $rows = q('SELECT id, endpoint, p256dh, auth, name, what FROM webpush_subs' . ($onlyId !== null ? ' WHERE id = ?' : '') . ' ORDER BY id LIMIT ' . self::MAX_PER_SEND,
            $onlyId !== null ? [$onlyId] : [])->fetchAll();
        if (!$rows) {
            return ['ok' => false, 'error' => t('geen apparaat aangemeld'), 'sent' => 0];
        }
        if ($onlyId === null) {
            $rows = array_values(array_filter($rows, static fn($r) => self::deviceWants((string) ($r['what'] ?? 'all'), $meta)));
            if (!$rows) {
                return ['ok' => false, 'error' => null, 'sent' => 0, 'skipped' => true];
            }
        }
        $payload = self::payload($title, $message, $priority, $extra);
        $sent = 0;
        $errors = [];
        foreach ($rows as $r) {
            $res = self::post($r, $payload, $priority);
            if ($res['ok']) {
                $sent++;
                q('UPDATE webpush_subs SET last_ok = NOW(), fails = 0, last_error = NULL WHERE id = ?', [$r['id']]);
            } elseif ($res['gone']) {
                q('DELETE FROM webpush_subs WHERE id = ?', [$r['id']]);   // afgemeld of verlopen: weg ermee
                $errors[] = ($r['name'] ?: '#' . $r['id']) . ': ' . t('niet meer aangemeld, verwijderd');
            } else {
                q('UPDATE webpush_subs SET fails = fails + 1, last_error = ?, last_error_at = NOW() WHERE id = ?', [mb_substr((string) $res['error'], 0, 250), $r['id']]);
                $errors[] = ($r['name'] ?: '#' . $r['id']) . ': ' . $res['error'];
            }
        }
        return ['ok' => $sent > 0, 'error' => $errors ? implode('; ', $errors) : null, 'sent' => $sent];
    }

    /**
     * @return array{ok: bool, gone: bool, error: ?string}
     * @param array<mixed> $r
     */
    private static function post(array $r, string $payload, int $priority): array
    {
        $ep = (string) $r['endpoint'];
        if (!self::validEndpoint($ep)) {
            return ['ok' => false, 'gone' => true, 'error' => t('Onbekende pushdienst')];
        }
        try {
            $body = self::encrypt($payload, self::unb64u((string) $r['p256dh']), self::unb64u((string) $r['auth']));
            $auth = self::vapidHeader($ep);
        } catch (Throwable $e) {
            return ['ok' => false, 'gone' => false, 'error' => $e->getMessage()];
        }
        $ch = curl_init($ep);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400',
                'Urgency: ' . ($priority >= 1 ? 'high' : ($priority <= -1 ? 'low' : 'normal')), "Authorization: $auth"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'RzSense/' . RZMON_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return ['ok' => false, 'gone' => false, 'error' => t('cURL-fout') . ": $err"];
        }
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'gone' => false, 'error' => null];
        }
        return ['ok' => false, 'gone' => in_array($code, [404, 410], true), 'error' => "HTTP $code" . ($code === 403 ? ' (' . t('sleutel klopt niet; meld het apparaat opnieuw aan') . ')' : '')
            . ($code === 429 ? ' (' . t('te veel berichten, de dienst remt af') . ')' : '')];
    }
}
