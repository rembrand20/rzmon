<?php
declare(strict_types=1);

/**
 * Versleutelde berichten tussen agent en RzMon (agent 1.49.0+), bovenop HTTPS.
 *
 * Eén sleutel (Instellingen → 🔐 Versleuteling, 32 bytes hex). Daaruit twee afgeleide sleutels:
 * enc = HMAC-SHA256(sleutel, "enc") voor AES-256-CBC, mac = HMAC-SHA256(sleutel, "mac") voor de handtekening.
 * Bericht van de agent:  {"rzenc":1,"t":<tijd>,"n":<nonce>,"iv":<hex>,"d":<base64>,"m":<HMAC over "rzenc1|t|n|iv|d">}
 * Antwoord van RzMon:    {"rzenc":1,"t":<tijd>,"rn":<nonce van het verzoek>,"iv":…,"d":…,"m":<HMAC over "rzenc1r|t|rn|iv|d">}
 * Eerst de handtekening controleren, pas dan uitpakken (encrypt-then-MAC). Een bericht ouder dan 5 minuten, of
 * met een nonce die al eens binnenkwam, wordt geweigerd. Na een nieuwe sleutel blijft de oude nog 24 uur geldig,
 * zodat elke agent de nieuwe (versleuteld met de oude) bij zijn volgende rapport krijgt.
 */
final class AgentCrypto
{
    public const MODES = ['off', 'on', 'required'];
    public const AGENT_MIN = '1.49.0';
    private const GRACE = 86400;
    private const MAX_AGE = 300;

    public static function globalMode(): string
    {
        $m = (string) setting('enc_mode', 'on');
        return in_array($m, self::MODES, true) ? $m : 'on';
    }

    /** Wat geldt voor deze server: zijn eigen keuze, anders de algemene */
    public static function hostMode(array $host): string
    {
        $m = $host['enc'] ?? null;
        return in_array($m, self::MODES, true) ? $m : self::globalMode();
    }

    public static function key(): string
    {
        $k = (string) setting('enc_key', '');
        return preg_match('/^[0-9a-f]{64}$/', $k) ? $k : '';
    }

    /** Geldige sleutels: de huidige, en de vorige zolang die nog 24 uur meetelt */
    public static function keys(): array
    {
        $keys = array_filter([self::key()]);
        $old = (string) setting('enc_key_old', '');
        if (preg_match('/^[0-9a-f]{64}$/', $old) && (int) setting('enc_key_old_until', '0') > time()) {
            $keys[] = $old;
        }
        return array_values($keys);
    }

    /** Kort kenmerk van een sleutel (geen geheim): zo weet RzMon met welke sleutel de agent werkt */
    public static function keyId(string $key): string
    {
        return substr(self::sub($key, 'id'), 0, 16);
    }

    /** Nieuwe sleutel. De vorige blijft nog 24 uur geldig. @return string de nieuwe sleutel */
    public static function generate(string $by): string
    {
        $new = bin2hex(random_bytes(32));
        if (($cur = self::key()) !== '') {
            save_setting('enc_key_old', $cur);
            save_setting('enc_key_old_until', (string) (time() + self::GRACE));
        }
        save_setting('enc_key', $new);
        AgentCommands::audit($by, 'Nieuwe sleutel voor de agents', t('de vorige blijft nog 24 uur geldig'));
        return $new;
    }

    private static function sub(string $key, string $label): string
    {
        return hash_hmac('sha256', $label, (string) hex2bin($key));
    }

    public static function isEnvelope(string $raw): bool
    {
        return str_starts_with(ltrim($raw), '{"rzenc":');
    }

    /**
     * Versleuteld bericht van een agent controleren en uitpakken.
     * @return array{0:string,1:string,2:string} [inhoud, gebruikte sleutel, nonce]
     * @throws RuntimeException met de reden (voor de ⚠️-melding)
     */
    public static function open(string $raw): array
    {
        $e = json_decode($raw, true);
        if (!is_array($e) || !is_int($e['t'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', (string) ($e['n'] ?? ''))
            || !preg_match('/^[0-9a-f]{32}$/', (string) ($e['iv'] ?? '')) || !preg_match('#^[A-Za-z0-9+/=]+$#', (string) ($e['d'] ?? ''))
            || !preg_match('/^[0-9a-f]{64}$/', (string) ($e['m'] ?? ''))) {
            throw new RuntimeException(t('versleuteld bericht is onvolledig'));
        }
        if (abs(time() - $e['t']) > self::MAX_AGE) {
            throw new RuntimeException(t('versleuteld bericht is te oud of komt uit de toekomst (klopt de klok van de server?)'));
        }
        $used = null;
        foreach (self::keys() as $k) {
            if (hash_equals(hash_hmac('sha256', "rzenc1|{$e['t']}|{$e['n']}|{$e['iv']}|{$e['d']}", (string) hex2bin(self::sub($k, 'mac'))), $e['m'])) {
                $used = $k;
                break;
            }
        }
        if ($used === null) {
            throw new RuntimeException(self::keys() ? t('verkeerde sleutel of ongeldige handtekening') : t('versleuteld bericht, maar RzMon heeft nog geen sleutel'));
        }
        if (q('INSERT IGNORE INTO agent_nonces (nonce, created_at) VALUES (?, ?)', [$e['n'], now()])->rowCount() === 0) {
            throw new RuntimeException(t('dit bericht kwam al eens binnen (opnieuw afgespeeld?)'));
        }
        $plain = openssl_decrypt((string) base64_decode($e['d'], true), 'aes-256-cbc', (string) hex2bin(self::sub($used, 'enc')), OPENSSL_RAW_DATA, (string) hex2bin($e['iv']));
        if ($plain === false) {
            throw new RuntimeException(t('versleuteld bericht is niet uit te pakken'));
        }
        return [$plain, $used, $e['n']];
    }

    /** Antwoord versleutelen, gebonden aan het verzoek (nonce) */
    public static function seal(string $plain, string $key, string $reqNonce): string
    {
        $iv = random_bytes(16);
        $t = time();
        $d = base64_encode((string) openssl_encrypt($plain, 'aes-256-cbc', (string) hex2bin(self::sub($key, 'enc')), OPENSSL_RAW_DATA, $iv));
        $ivh = bin2hex($iv);
        $m = hash_hmac('sha256', "rzenc1r|$t|$reqNonce|$ivh|$d", (string) hex2bin(self::sub($key, 'mac')));
        return '{"rzenc":1,"t":' . $t . ',"rn":"' . $reqNonce . '","iv":"' . $ivh . '","d":"' . $d . '","m":"' . $m . '"}';
    }

    /** Sleutel bij een kenmerk (header X-Agent-Enc van een GET-verzoek), of null */
    public static function keyById(string $id): ?string
    {
        foreach (self::keys() as $k) {
            if (hash_equals(self::keyId($k), $id)) {
                return $k;
            }
        }
        return null;
    }
}
