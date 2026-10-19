<?php
declare(strict_types=1);

const PRIVATE_NETWORKS = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10'];

/** Controleert of $ip binnen $cidr valt (IPv4 en IPv6). Een los IP-adres mag ook. */
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton((string) $net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = 0xFF << (8 - $rest) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

function ip_in_list(string $ip, array $cidrs): bool
{
    // IPv4-mapped IPv6 (::ffff:192.168.1.5) als IPv4 behandelen
    if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
        $ip = $m[1];
    }
    foreach ($cidrs as $cidr) {
        if ($cidr !== '' && ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/** Splitst een tekstveld (komma's, spaties of regels) in een lijst. */
function parse_list(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $text))));
}

/**
 * IP-adres van de bezoeker. X-Forwarded-For wordt alleen vertrouwd als het
 * verzoek binnenkomt via een ingestelde reverse proxy.
 */
function client_ip(?array $trustedProxies = null): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $trustedProxies ??= is_configured() ? parse_list((string) setting('trusted_proxies', '')) : [];
    if ($trustedProxies && ip_in_list($ip, $trustedProxies) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Van rechts naar links: eerste adres dat geen vertrouwde proxy is
        foreach (array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']))) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) && !ip_in_list($hop, $trustedProxies)) {
                return $hop;
            }
        }
    }
    return $ip;
}

function is_private_client(): bool
{
    return ip_in_list(client_ip([]), PRIVATE_NETWORKS);
}
