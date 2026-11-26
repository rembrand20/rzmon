<?php
declare(strict_types=1);

/**
 * 1.332.1: de laatste gegevens van een server (tabel agent_data: één rij per server en onderwerp) op één plek lezen en
 * schrijven. Dit stond in twintig bestanden steeds opnieuw uitgeschreven.
 */
final class AgentData
{
    /** De waarde zoals hij is opgeslagen, of null als hij er niet is */
    public static function raw(int $hostId, string $metric): ?string
    {
        $v = q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [$hostId, $metric])->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /**
     * De waarde als array (JSON), of null als hij er niet is of geen array is
     * @return array<mixed>|null
     */
    public static function get(int $hostId, string $metric): ?array
    {
        $v = self::raw($hostId, $metric);
        $d = $v !== null ? json_decode($v, true) : null;
        return is_array($d) ? $d : null;
    }

    /**
     * Opslaan (een array als JSON, een tekst zoals hij is); null haalt de rij weg
     * @param array<mixed>|string|null $value
     */
    public static function put(int $hostId, string $metric, array|string|null $value): void
    {
        if ($value === null) {
            q('DELETE FROM agent_data WHERE host_id = ? AND metric = ?', [$hostId, $metric]);
            return;
        }
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$hostId, $metric, $value, now()]);
    }
}
