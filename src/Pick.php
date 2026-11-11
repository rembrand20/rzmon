<?php

declare(strict_types=1);

/**
 * 1.239.0: waar slimme invulvelden uit kunnen kiezen, zodat je niets twee keer hoeft in te typen: je eigen netwerken,
 * de whitelist, je huidige IP-adres, de gebruikers (met e-mailadres), de eigen domeinen van RzSense en de apparaten uit
 * de netwerkscan en je servers. Alleen voor beheerders (de interface laadt het één keer per minuut).
 */
final class Pick
{
    public static function sources(): array
    {
        $wl = q('SELECT kind, value, reason FROM whitelist WHERE until IS NULL OR until > NOW() ORDER BY kind, value')->fetchAll();
        $users = q("SELECT username, email FROM users ORDER BY username")->fetchAll();
        $devices = [];
        try {
            foreach (q("SELECT ip, COALESCE(NULLIF(name, ''), NULLIF(hostname, ''), vendor, '') AS n, kind FROM net_devices WHERE ip <> '' ORDER BY INET_ATON(ip) IS NULL, INET_ATON(ip), ip LIMIT 500")->fetchAll() as $d) {
                $devices[] = ['v' => (string) $d['ip'], 'l' => (string) $d['n'], 'k' => (string) $d['kind']];
            }
        } catch (Throwable) {
            // netwerkscan nog niet gebruikt
        }
        $hosts = array_map(static fn($h) => ['v' => (string) $h['address'], 'l' => (string) $h['name']],
            q("SELECT name, address FROM hosts WHERE address <> '' ORDER BY name")->fetchAll());
        return [
            'lan' => trusted_networks() ?: PRIVATE_NETWORKS,
            'lan_set' => trusted_networks() !== [],
            'my_ip' => client_ip(),
            'wl_ips' => array_values(array_map(static fn($w) => ['v' => $w['value'], 'l' => (string) ($w['reason'] ?? '')], array_filter($wl, static fn($w) => $w['kind'] === 'ip'))),
            'wl_users' => array_values(array_map(static fn($w) => $w['value'], array_filter($wl, static fn($w) => $w['kind'] === 'user'))),
            'decoys' => parse_list((string) setting('ids_decoy_users', '')),
            'users' => array_map(static fn($u) => ['u' => (string) $u['username'], 'e' => (string) ($u['email'] ?? '')], $users),
            'emails' => self::emails($users),
            'domains' => self::ownDomains(),
            'devices' => $devices,
            'hosts' => $hosts,
            'adguard' => array_values(array_filter(parse_list((string) setting('adguard_urls', '')))),
        ];
    }

    /** E-mailadressen die RzSense al kent: van gebruikers en de algemene ontvangers */
    private static function emails(array $users): array
    {
        $out = [];
        foreach ($users as $u) {
            if (($u['email'] ?? '') !== '') {
                $out[strtolower((string) $u['email'])] = ['v' => strtolower((string) $u['email']), 'l' => (string) $u['username']];
            }
        }
        foreach (email_list((string) setting('mail_to', ''))['ok'] as $e) {
            $out[$e] ??= ['v' => $e, 'l' => t('algemene ontvanger')];
        }
        return array_values($out);
    }

    /** Alle namen (geen IP-adressen) waarop RzSense bereikbaar is: basis-URL, externe URL en beheeradres */
    public static function ownHosts(): array
    {
        $out = [];
        foreach ([config('base_url', ''), config('external_url', ''), setting('admin_url', '')] as $u) {
            $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));
            if ($h !== '') {
                $out[$h] = true;
            }
        }
        return array_keys($out);
    }

    /** Eigen domeinen: van de adressen van RzSense (geen IP), de afzender en het datalekken-domein */
    public static function ownDomains(): array
    {
        $out = [];
        $add = static function (string $d) use (&$out): void {
            $d = strtolower(trim($d, " .\t"));
            if ($d !== '' && @inet_pton($d) === false && valid_domain($d)) {
                $parts = explode('.', $d);
                $out[$d] = true;
                if (count($parts) > 2) {
                    $out[implode('.', array_slice($parts, -2))] = true;   // ook het hoofddomein (rzsense.voorbeeld.nl → voorbeeld.nl)
                }
            }
        };
        foreach (self::ownHosts() as $h) {
            $add($h);
        }
        foreach ([setting('mail_from', ''), setting('smtp_user', '')] as $m) {
            if (str_contains((string) $m, '@')) {
                $add(substr((string) $m, strrpos((string) $m, '@') + 1));
            }
        }
        $add((string) setting('hibp_domain', ''));
        return array_keys($out);
    }
}
