<?php

declare(strict_types=1);

/**
 * 1.237.0: één manier om "hooguit N keer per tijdvak" bij te houden (tabel rate_hits), in plaats van een eigen teller
 * per onderdeel. Gebruikt voor "Verstuur naar mij"; nieuwe limieten horen hier ook.
 */
final class RateLimit
{
    /** Mag het nog? Dan telt deze keer mee en is het antwoord true; anders false (en telt het niet mee). */
    public static function hit(string $scope, string $key, int $max, int $windowSeconds): bool
    {
        $n = (int) q('SELECT COUNT(*) FROM rate_hits WHERE scope = ? AND k = ? AND at > ?',
            [$scope, mb_substr($key, 0, 100), date('Y-m-d H:i:s', time() - $windowSeconds)])->fetchColumn();
        if ($n >= $max) {
            return false;
        }
        q('INSERT INTO rate_hits (scope, k, at) VALUES (?, ?, ?)', [mb_substr($scope, 0, 40), mb_substr($key, 0, 100), now()]);
        return true;
    }

    /** Dagelijks opruimen (de langste limiet is korter dan een dag) */
    public static function purge(): void
    {
        q('DELETE FROM rate_hits WHERE at < NOW() - INTERVAL 2 DAY');
    }
}
