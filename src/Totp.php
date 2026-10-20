<?php
declare(strict_types=1);

/**
 * Tweestapsverificatie met tijdcodes (TOTP, RFC 6238): 6 cijfers, elke 30 seconden nieuw.
 * Werkt met elke authenticator-app (Google/Microsoft Authenticator, Authy, 1Password, Bitwarden…).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nieuw geheim van 160 bits, base32 (zoals authenticator-apps verwachten). */
    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new InvalidArgumentException('Ongeldig TOTP-geheim');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xf;
        $num = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Controleert een code (1 stap speling voor een klok die iets afwijkt).
     * @return int|null de gebruikte tijdstap, of null als de code niet klopt
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            // Een code mag maar één keer gebruikt worden (afgeluisterde code is waardeloos)
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** Link voor de QR-code die de authenticator-app scant. */
    public static function uri(string $secret, string $username): string
    {
        $issuer = 'RzMon';
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30',
            rawurlencode($issuer), rawurlencode($username), $secret, rawurlencode($issuer));
    }
}
