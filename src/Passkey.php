<?php
declare(strict_types=1);

/**
 * Passkeys (WebAuthn, 1.86.0): inloggen met Face ID, vingerafdruk of een beveiligingssleutel, in plaats van wachtwoord
 * en 2FA (die blijven de reserve). De geheime sleutel blijft op het apparaat; RzMon bewaart alleen de openbare sleutel.
 * Een passkey werkt alleen voor het domein waarvoor hij is gemaakt, dus een nep-site kan er niets mee (phishing).
 * Werkt alleen via https met een domeinnaam (of http://localhost). Ondersteund: ES256 en RS256.
 */
final class Passkey
{
    public static function enabled(): bool
    {
        return setting('passkeys_enabled', '1') === '1';
    }

    /** Domein (rpId): de hostnaam waarmee RzMon nu geopend is */
    public static function rpId(): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    }

    private static function origin(): string
    {
        return (Https::isHttps() ? 'https' : 'http') . '://' . strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    /** Kan het hier? Niet op een IP-adres, en alleen via https (of localhost) */
    public static function possible(): bool
    {
        $h = self::rpId();
        return self::enabled() && @inet_pton($h) === false && (Https::isHttps() || $h === 'localhost');
    }

    // ------------------------------------------------------------------ aanmaken

    public static function registerOptions(array $user): array
    {
        $challenge = random_bytes(32);
        $_SESSION['pk_reg'] = ['challenge' => self::b64($challenge), 'user' => (int) $user['id'], 'at' => time()];
        $exclude = array_map(static fn($id) => ['type' => 'public-key', 'id' => $id],
            q('SELECT cred_id FROM passkeys WHERE user_id = ?', [$user['id']])->fetchAll(PDO::FETCH_COLUMN));
        return [
            'challenge' => self::b64($challenge),
            'rp' => ['id' => self::rpId(), 'name' => 'RzMon'],
            'user' => ['id' => self::b64('rzmon-user-' . $user['id']), 'name' => $user['username'], 'displayName' => $user['username']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => 120000,
            'excludeCredentials' => $exclude,
        ];
    }

    /** @return int|string id van de nieuwe passkey, of een foutmelding */
    public static function registerFinish(array $user, array $in, ?int $deviceId): int|string
    {
        $p = $_SESSION['pk_reg'] ?? null;
        unset($_SESSION['pk_reg']);
        if (!$p || $p['user'] !== (int) $user['id'] || $p['at'] < time() - 300) {
            return t('Begin opnieuw met het aanmaken van de passkey');
        }
        $cd = self::unb64((string) ($in['clientDataJSON'] ?? ''));
        $err = self::checkClient($cd, 'webauthn.create', $p['challenge']);
        if ($err !== null) {
            return $err;
        }
        try {
            $o = 0;
            $att = self::cbor(self::unb64((string) ($in['attestationObject'] ?? '')), $o);
            $auth = (string) ($att['authData'] ?? '');
            $err = self::checkAuth($auth);
            if ($err !== null) {
                return $err;
            }
            if (!(ord($auth[32]) & 0x40)) {
                return t('De passkey stuurde geen sleutel mee');
            }
            $len = unpack('n', substr($auth, 53, 2))[1];
            $credId = substr($auth, 55, $len);
            $o = 55 + $len;
            $cose = self::cbor($auth, $o);
            [$pem, $alg] = self::coseToPem($cose);
        } catch (Throwable) {
            return t('De passkey kon niet gelezen worden');
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 100) ?: device_name();
        q('INSERT INTO passkeys (user_id, device_id, name, cred_id, public_key, alg, sign_count, rp_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$user['id'], $deviceId, $name, self::b64($credId), $pem, $alg, unpack('N', substr($auth, 33, 4))[1], self::rpId(), now()]);
        $id = (int) db()->lastInsertId();
        AgentCommands::audit($user['username'], 'Passkey aangemaakt', $name);
        Monitor::sysInfo('passkey', null, null, '🔑 ' . t('Passkey aangemaakt: {name}', ['name' => $name]),
            fn() => t('{user} heeft een passkey aangemaakt ({name}) voor {rp}', ['user' => $user['username'], 'name' => $name, 'rp' => self::rpId()]), client_ip());
        return $id;
    }

    // ------------------------------------------------------------------ inloggen

    public static function loginOptions(): array
    {
        $challenge = random_bytes(32);
        start_session();
        $_SESSION['pk_login'] = ['challenge' => self::b64($challenge), 'at' => time()];
        return ['challenge' => self::b64($challenge), 'rpId' => self::rpId(), 'userVerification' => 'required', 'timeout' => 120000, 'allowCredentials' => []];
    }

    /** @return array|string de gebruiker (id, username, role, …), of een foutmelding */
    public static function loginFinish(array $in): array|string
    {
        start_session();
        $p = $_SESSION['pk_login'] ?? null;
        unset($_SESSION['pk_login']);
        if (!$p || $p['at'] < time() - 300) {
            return t('Probeer het opnieuw');
        }
        $k = q('SELECT p.*, u.username, u.role, u.locked_at, u.locked_until, u.local_only FROM passkeys p JOIN users u ON u.id = p.user_id WHERE p.cred_id = ?',
            [self::b64(self::unb64((string) ($in['id'] ?? '')))])->fetch();
        if (!$k) {
            return t('Deze passkey is niet (meer) bekend bij RzMon');
        }
        $cd = self::unb64((string) ($in['clientDataJSON'] ?? ''));
        $auth = self::unb64((string) ($in['authenticatorData'] ?? ''));
        $sig = self::unb64((string) ($in['signature'] ?? ''));
        $err = self::checkClient($cd, 'webauthn.get', $p['challenge']) ?? self::checkAuth($auth, (string) $k['rp_id']);
        if ($err !== null) {
            return $err;
        }
        // ES256 en RS256: allebei SHA-256 (ES256 levert de handtekening al als DER, zoals openssl hem wil)
        if (openssl_verify($auth . hash('sha256', $cd, true), $sig, (string) $k['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            Security::loginFailed((string) $k['username'], 'passkey: ongeldige handtekening', true);
            return t('De passkey klopt niet');
        }
        // Teller: een gekopieerde sleutel zou een lagere of gelijke teller geven (bij 0/0 doet het apparaat niet aan tellen)
        $count = unpack('N', substr($auth, 33, 4))[1];
        if (($count !== 0 || (int) $k['sign_count'] !== 0) && $count <= (int) $k['sign_count']) {
            Security::unauthorized('login', t('Passkey met een te lage teller (gekopieerd?)'), [t('gebruiker') => $k['username'], 'passkey' => $k['name']]);
            return t('De passkey klopt niet');
        }
        q('UPDATE passkeys SET sign_count = ?, last_used = ? WHERE id = ?', [$count, now(), $k['id']]);
        return ['id' => (int) $k['user_id'], 'username' => $k['username'], 'role' => $k['role'], 'locked_at' => $k['locked_at'],
            'locked_until' => $k['locked_until'], 'local_only' => $k['local_only'], 'passkey' => $k['name']];
    }

    // ------------------------------------------------------------------ beheer

    public static function list(?int $userId): array
    {
        return q('SELECT p.id, p.user_id, u.username, p.device_id, d.name AS device_name, p.name, p.rp_id, p.created_at, p.last_used
                  FROM passkeys p JOIN users u ON u.id = p.user_id LEFT JOIN trusted_devices d ON d.id = p.device_id'
            . ($userId !== null ? ' WHERE p.user_id = ?' : '') . ' ORDER BY u.username, p.created_at DESC', $userId !== null ? [$userId] : [])->fetchAll();
    }

    public static function remove(int $id, ?int $userId, string $by): bool
    {
        $k = q('SELECT p.name, u.username FROM passkeys p JOIN users u ON u.id = p.user_id WHERE p.id = ?' . ($userId !== null ? ' AND p.user_id = ?' : ''),
            $userId !== null ? [$id, $userId] : [$id])->fetch();
        if (!$k) {
            return false;
        }
        q('DELETE FROM passkeys WHERE id = ?', [$id]);
        AgentCommands::audit($by, 'Passkey verwijderd', "{$k['username']}: {$k['name']}");
        Monitor::sysInfo('passkey', null, null, '🔑 ' . t('Passkey verwijderd: {name}', ['name' => $k['name']]),
            fn() => t('De passkey {name} van {user} is verwijderd (door {by})', ['name' => $k['name'], 'user' => $k['username'], 'by' => $by]));
        return true;
    }

    public static function rename(int $id, ?int $userId, string $name): bool
    {
        $name = mb_substr(trim($name), 0, 100);
        return $name !== '' && q('UPDATE passkeys SET name = ? WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : ''),
            $userId !== null ? [$name, $id, $userId] : [$name, $id])->rowCount() > 0;
    }

    // ------------------------------------------------------------------ controles

    private static function checkClient(string $json, string $type, string $challenge): ?string
    {
        $c = json_decode($json, true);
        if (!is_array($c) || ($c['type'] ?? '') !== $type) {
            return t('Ongeldig antwoord van de passkey');
        }
        if (!hash_equals($challenge, self::b64(self::unb64((string) ($c['challenge'] ?? ''))))) {
            return t('Probeer het opnieuw');
        }
        if (strtolower((string) ($c['origin'] ?? '')) !== self::origin()) {
            return t('De passkey hoort bij een ander adres ({o})', ['o' => mb_substr((string) ($c['origin'] ?? '?'), 0, 100)]);
        }
        return null;
    }

    /** rpIdHash en vlaggen: aanwezig (UP) en geverifieerd met Face ID, vingerafdruk of pincode (UV) */
    private static function checkAuth(string $auth, ?string $rpId = null): ?string
    {
        if (strlen($auth) < 37 || !hash_equals(hash('sha256', $rpId ?? self::rpId(), true), substr($auth, 0, 32))) {
            return t('De passkey hoort bij een ander domein');
        }
        if ((ord($auth[32]) & 0x05) !== 0x05) {
            return t('Bevestig met Face ID, vingerafdruk of pincode');
        }
        return null;
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Kleine CBOR-lezer: genoeg voor WebAuthn (getallen, bytes, tekst, lijsten, mappen, true/false/null) */
    private static function cbor(string $d, int &$o): mixed
    {
        if ($o >= strlen($d)) {
            throw new RuntimeException('cbor: einde');
        }
        $b = ord($d[$o++]);
        $mt = $b >> 5;
        $ai = $b & 31;
        if ($mt === 7) {
            return match ($ai) {
                20 => false, 21 => true, 22, 23 => null,
                default => throw new RuntimeException('cbor: niet ondersteund'),
            };
        }
        $len = match (true) {
            $ai < 24 => $ai,
            $ai === 24 => ord($d[$o]),
            $ai === 25 => unpack('n', substr($d, $o, 2))[1],
            $ai === 26 => unpack('N', substr($d, $o, 4))[1],
            $ai === 27 => unpack('J', substr($d, $o, 8))[1],
            default => throw new RuntimeException('cbor: lengte'),
        };
        $o += [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai] ?? 0;
        switch ($mt) {
            case 0:
                return $len;
            case 1:
                return -1 - $len;
            case 2:
            case 3:
                $v = substr($d, $o, $len);
                $o += $len;
                return $v;
            case 4:
                $a = [];
                for ($i = 0; $i < $len; $i++) {
                    $a[] = self::cbor($d, $o);
                }
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = self::cbor($d, $o);
                    $m[$k] = self::cbor($d, $o);
                }
                return $m;
            default:   // 6: tag, de waarde erachter
                return self::cbor($d, $o);
        }
    }

    /** COSE-sleutel → PEM. @return array{0: string, 1: int} [pem, alg] */
    private static function coseToPem(array $k): array
    {
        if (($k[1] ?? 0) === 2 && ($k[-1] ?? 0) === 1 && strlen((string) ($k[-2] ?? '')) === 32 && strlen((string) ($k[-3] ?? '')) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
            return [self::pem($der), -7];
        }
        if (($k[1] ?? 0) === 3 && isset($k[-1], $k[-2])) {
            $int = static fn(string $v) => self::der(0x02, (ord($v[0]) & 0x80 ? "\x00" : '') . $v);
            $rsa = self::der(0x30, $int((string) $k[-1]) . $int((string) $k[-2]));
            $der = self::der(0x30, hex2bin('300d06092a864886f70d0101010500') . self::der(0x03, "\x00" . $rsa));
            return [self::pem($der), -257];
        }
        throw new RuntimeException('sleutelsoort niet ondersteund');
    }

    private static function der(int $tag, string $v): string
    {
        $l = strlen($v);
        $len = $l < 128 ? chr($l) : (($b = ltrim(pack('N', $l), "\x00")) !== '' ? chr(0x80 | strlen($b)) . $b : "\x00");
        return chr($tag) . $len . $v;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
