<?php
declare(strict_types=1);

/**
 * Blokkadelijst voor AdGuard Home (1.231.0): RzSense maakt een filterlijst (AdGuard-formaat, ||domein^) met
 * - de malwaredomeinen uit de dreigingsinformatie (URLhaus, ThreatFox; dagelijks bijgewerkt);
 * - je eigen bewaakte domeinen (IDS → Jagen → Bewaken);
 * - domeinen die je zelf toevoegt (Instellingen → IDS → Dreigingsinformatie → AdGuard-lijst).
 * AdGuard haalt de lijst op via een geheime URL (public/dnsbl.php?t=…), alleen vanaf het lokale netwerk. Met één knop
 * voegt RzSense hem toe aan je AdGuard-instanties (Koppelingen → AdGuard).
 * Aan/uit: dnsbl_on (standaard uit).
 */
final class DnsList
{
    public static function enabled(): bool
    {
        return setting('dnsbl_on', '0') === '1';
    }

    /** Het geheime token (wordt aangemaakt als het er nog niet is) */
    public static function token(): string
    {
        $t = (string) setting('dnsbl_token', '');
        if (!preg_match('/^[0-9a-f]{40}$/', $t)) {
            $t = bin2hex(random_bytes(20));
            save_setting('dnsbl_token', $t);
        }
        return $t;
    }

    public static function newToken(string $by): string
    {
        save_setting('dnsbl_token', '');
        ActionLog::by($by, null, 'dns', t('AdGuard-lijst: nieuwe geheime URL'), t('de oude URL werkt niet meer'));
        return self::token();
    }

    public static function own(): array
    {
        return array_values(array_filter(array_map(static fn($d) => rtrim(strtolower(trim($d)), '.'), parse_list((string) setting('dnsbl_own', ''))),
            static fn($d) => (bool) preg_match('/^(?=.{4,253}$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)));
    }

    /** Alle domeinen, per bron */
    public static function domains(): array
    {
        $out = [];
        if (setting('dnsbl_ti', '1') === '1') {
            foreach (q("SELECT value, src FROM ti_list WHERE kind = 'd' LIMIT 200000")->fetchAll() as $r) {
                $out[(string) $r['value']] = (string) $r['src'];
            }
        }
        if (setting('dnsbl_ioc', '1') === '1') {
            foreach (q("SELECT value FROM ioc_watch WHERE kind = 'domain'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $out[(string) $d] = 'ioc';
            }
        }
        foreach (self::own() as $d) {
            $out[$d] = 'own';
        }
        // Nooit je eigen domeinen of die van RzSense blokkeren
        foreach (array_merge(parse_list((string) setting('dnsbl_allow', '')), [(string) parse_url((string) config('base_url', ''), PHP_URL_HOST)]) as $a) {
            unset($out[strtolower(trim((string) $a))]);
        }
        return $out;
    }

    /** De lijst zelf, in het formaat van AdGuard */
    public static function render(): string
    {
        $d = self::domains();
        ksort($d);
        $lines = ['! Title: RzSense', '! Description: ' . t('Malwaredomeinen (URLhaus, ThreatFox), bewaakte sporen en eigen domeinen'),
            '! Last modified: ' . gmdate('c'), '! Expires: 6 hours', '! Domains: ' . count($d), ''];
        foreach (array_keys($d) as $dom) {
            $lines[] = '||' . $dom . '^';
        }
        return implode("\n", $lines) . "\n";
    }

    public static function status(): array
    {
        $d = self::domains();
        $per = array_count_values($d);
        return ['on' => self::enabled(), 'n' => count($d), 'per' => $per, 'adguard' => NetLinks::adguardOn(),
            'fetched' => setting('dnsbl_fetched', '') ?: null, 'fetched_by' => setting('dnsbl_fetched_by', '') ?: null];
    }

    /** De lijst toevoegen aan alle AdGuard-instanties (filtering/add_url); bestaat hij al, dan niets */
    public static function addToAdguard(string $url, string $by): array
    {
        $res = [];
        foreach (NetLinks::adguardUrls() as $base) {
            $host = (string) parse_url($base, PHP_URL_HOST);
            [$st, $e] = NetLinks::ag($base, 'filtering/status');
            if ($e) {
                $res[] = ['host' => $host, 'ok' => false, 'msg' => $e];
                continue;
            }
            $have = array_filter((array) ($st['filters'] ?? []), static fn($f) => str_contains((string) ($f['url'] ?? ''), '/dnsbl.php'));
            if ($have) {
                $res[] = ['host' => $host, 'ok' => true, 'msg' => t('stond er al')];
                continue;
            }
            [, $e] = NetLinks::ag($base, 'filtering/add_url', ['name' => 'RzSense', 'url' => $url, 'whitelist' => false]);
            $res[] = ['host' => $host, 'ok' => !$e, 'msg' => $e ?: t('toegevoegd')];
        }
        ActionLog::by($by, null, 'dns', t('AdGuard-lijst toegevoegd aan AdGuard'), implode(' · ', array_map(static fn($r) => $r['host'] . ': ' . $r['msg'], $res)));
        return $res;
    }
}
