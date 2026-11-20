<?php
declare(strict_types=1);

/**
 * 1.293.0: velden uit een logregel, voor de velden-kolom op de pagina Logs en voor zoeken op een veld
 * (programma=sshd, ip:203.0.113.5, user=demo). Alleen bij het zoeken, niets wordt extra bewaard.
 *
 * - programma en pid: "naam[123]:" of "naam:" vooraan (syslog), ook na een tijd en een servernaam.
 * - ip: het eerste IPv4- of IPv6-adres.
 * - gebruiker: "for (invalid user) naam from", "user=naam", "(naam) CMD" (cron).
 * - methode, pad en status: een HTTP-verzoek ("GET /pad HTTP/1.1" 404).
 * - sleutel=waarde: elk paar (hooguit 10), sleutel in kleine letters.
 */
final class LogFields
{
    /** Velden die RzSense zelf herkent (zoeken kan ook met naam:waarde); de rest alleen als sleutel=waarde */
    public const KNOWN = ['programma', 'pid', 'ip', 'gebruiker', 'methode', 'pad', 'status'];
    /** Vaste velden van elke regel (niet uit de tekst) */
    public const FIXED = ['server', 'bron', 'niveau'];
    private const MAX_KV = 10;

    /** @return array<string, string> */
    public static function extract(string $msg): array
    {
        $f = [];
        $head = mb_substr($msg, 0, 160);
        // programma[pid]: of programma: vooraan, eventueel na een tijdstempel en een servernaam
        if (preg_match('/^(?:\S+\s+){0,4}?([A-Za-z_][\w.\/-]{0,40})\[(\d{1,10})\]:\s/', $head, $m)) {
            $f['programma'] = basename($m[1]);
            $f['pid'] = $m[2];
        } elseif (preg_match('/^(?:\S+\s+){0,4}?([A-Za-z_][\w.-]{1,40}):\s/', $head, $m) && !preg_match('/^\d/', $m[1])) {
            $f['programma'] = $m[1];
        }
        if (preg_match('/(?<![\w.:])((?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3})(?![\w.])/', $msg, $m)) {
            $f['ip'] = $m[1];
        } elseif (preg_match('/(?<![\w:])((?:[0-9a-fA-F]{1,4}:){2,7}[0-9a-fA-F]{1,4})(?![\w:])/', $msg, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $f['ip'] = strtolower($m[1]);
        }
        if (preg_match('/\bfor (?:invalid user )?([\w.@-]{1,64}) from\b/', $msg, $m)
            || preg_match('/\b(?:user|username|usr|login)[=:]\s?"?([\w.@\\\\-]{1,64})/i', $msg, $m)
            || preg_match('/\(([\w.-]{1,32})\) CMD(?:END)?\b/', $msg, $m)) {
            $f['gebruiker'] = $m[1];
        }
        if (preg_match('/"(GET|POST|PUT|DELETE|HEAD|PATCH|OPTIONS|CONNECT) (\S{1,300})(?: HTTP\/[\d.]+)?" (\d{3})\b/', $msg, $m)) {
            $f['methode'] = $m[1];
            $f['pad'] = (string) strtok($m[2], '?');
            $f['status'] = $m[3];
        }
        // sleutel=waarde (niet binnen een URL of pad)
        if (str_contains($msg, '=') && preg_match_all('/(?<![\w.\/?&=-])([A-Za-z][\w.-]{0,30})=("([^"]{0,100})"|\'([^\']{0,100})\'|([^\s,;"\']{1,100}))/', $msg, $all, PREG_SET_ORDER)) {
            $n = 0;
            foreach ($all as $kv) {
                $k = strtolower($kv[1]);
                if (isset($f[$k]) || in_array($k, self::FIXED, true) || (isset($f['gebruiker']) && in_array($k, ['user', 'username', 'usr', 'login'], true))) {
                    continue;
                }
                $f[$k] = ($kv[3] ?? '') !== '' ? $kv[3] : (($kv[4] ?? '') !== '' ? $kv[4] : (string) ($kv[5] ?? ''));
                if (++$n >= self::MAX_KV) {
                    break;
                }
            }
        }
        return $f;
    }

    /** Past een waarde op wat je zocht? Hoofdletters maken niet uit; * = wat dan ook */
    public static function valueMatch(string $value, string $want): bool
    {
        $value = mb_strtolower($value);
        if (!str_contains($want, '*')) {
            return $value === $want;
        }
        return preg_match('~^' . str_replace('\*', '.*', preg_quote($want, '~')) . '$~u', $value) === 1;
    }

    /**
     * Velden tellen over een steekproef van regels (de velden-kolom): per veld in hoeveel regels, hoeveel verschillende
     * waarden en de top 5.
     * @param list<array<string, string>> $rows per regel de velden (ook server, bron en niveau)
     * @return array<string, array{seen: int, n: int, top: list<array{0: string, 1: int}>}>
     */
    public static function stats(array $rows): array
    {
        $count = [];
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                $count[$k][$v] = ($count[$k][$v] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($count as $k => $vals) {
            arsort($vals);
            $top = [];
            foreach (array_slice($vals, 0, 5, true) as $v => $c) {
                $top[] = [(string) $v, (int) $c];
            }
            $out[(string) $k] = ['seen' => (int) array_sum($vals), 'n' => count($vals), 'top' => $top];
        }
        // Eerst de vaste velden, dan de bekende, dan de rest op hoe vaak ze voorkomen
        uksort($out, static function ($a, $b) use ($out) {
            $rank = static fn($k) => ($i = array_search($k, [...self::FIXED, ...self::KNOWN], true)) !== false ? (int) $i : 100;
            return [$rank($a), -$out[$a]['seen'], $a] <=> [$rank($b), -$out[$b]['seen'], $b];
        });
        return array_slice($out, 0, 40, true);
    }
}
