<?php

declare(strict_types=1);

/**
 * 1.237.0: "dit is al gemeld" op één manier. Eerst had elk onderdeel een eigen stukje code dat een JSON-instelling las,
 * opschoonde en weer opsloeg (trends, datalimiet, AI-budget, NIS2-termijnen …), en niet overal op hetzelfde moment.
 * Hier: één JSON-instelling per "emmer" ({sleutel: stempel}), en de claim wordt opgeslagen vóór er iets verstuurd wordt
 * (gaat het versturen mis, dan komt er in elk geval geen dubbele melding).
 */
final class Once
{
    /**
     * Eén keer per stempel: true als (sleutel, stempel) nog niet geclaimd was (en claimt hem dan), anders false.
     * Voorbeeld: Once::claim('ai_warned', 'budget', date('Y-m')) → één keer per maand.
     */
    public static function claim(string $bucket, string $key, string $stamp, int $keep = 200): bool
    {
        $m = self::read($bucket);
        if (($m[$key] ?? null) === $stamp) {
            return false;
        }
        unset($m[$key]);
        $m[$key] = $stamp;
        self::write($bucket, array_slice($m, -$keep, null, true));
        return true;
    }

    /** Eén keer per $ttl seconden per sleutel (de stempel is het tijdstip) */
    public static function claimFor(string $bucket, string $key, int $ttl, int $keep = 500): bool
    {
        $now = time();
        $m = array_filter(self::read($bucket), static fn($t) => (int) $t > $now - $ttl);
        if (isset($m[$key])) {
            return false;
        }
        $m[$key] = (string) $now;
        self::write($bucket, array_slice($m, -$keep, null, true));
        return true;
    }

    /** Een sleutel als gedaan markeren zonder te vragen (bv. 80% is ook gedaan als 100% gemeld is) */
    public static function mark(string $bucket, string $key, string $stamp): void
    {
        $m = self::read($bucket);
        $m[$key] = $stamp;
        self::write($bucket, $m);
    }

    private static function read(string $bucket): array
    {
        $m = json_decode((string) setting($bucket, ''), true);
        return is_array($m) ? array_map('strval', $m) : [];
    }

    private static function write(string $bucket, array $m): void
    {
        save_setting($bucket, json_encode($m ?: (object) []));
    }
}
