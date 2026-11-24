<?php
declare(strict_types=1);

/**
 * Uitrolcodes (1.317.0, Windows-agent 0.5.0): één code waarmee veel servers zichzelf aanmelden, voor uitrollen met
 * groepsbeleid (de MSI) of een ander beheerprogramma. Een koppelcode hoort bij één server en geldt 15 minuten; een
 * uitrolcode maakt zelf een nieuwe server aan (naam = computernaam), geldt tot een datum en voor hooguit zoveel servers,
 * en eventueel alleen vanaf bepaalde netwerken.
 *
 * Veilig: RzSense bewaart alleen een sleutel die uit de code is afgeleid (SHA-256), niet de code zelf. Het verzoek is
 * versleuteld en ondertekend met die sleutel, net als bij een koppelcode; elke server krijgt daarna zijn eigen sleutel.
 * Een bestaande servernaam wordt nooit overgenomen (anders kon wie de code heeft een server "kapen"). Elke aanmelding
 * staat in het logboek en geeft een melding. Intrekken kan altijd.
 */
final class AgentEnroll
{
    public const MAX_DAYS = 90;
    public const MAX_HOSTS = 1000;

    /** Sleutel bij een uitrolcode (zelfde berekening als de agent: SHA-256 over "rzenroll1|CODE", zonder streepjes) */
    public static function key(string $code): string
    {
        return hash('sha256', 'rzenroll1|' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    /**
     * Nieuwe uitrolcode: de code (één keer te zien), of een fout
     * @param array<string, mixed> $in name, days, max, nets, grp
     * @return array{code: string, id: int, until: string}|string
     */
    public static function create(array $in, string $by): array|string
    {
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 100)) ?: t('Uitrol');
        $days = max(1, min(self::MAX_DAYS, (int) ($in['days'] ?? 30)));
        $max = max(1, min(self::MAX_HOSTS, (int) ($in['max'] ?? 50)));
        $nets = [];
        foreach (parse_list((string) ($in['nets'] ?? '')) as $n) {
            if (!self::validNet($n)) {
                return t('Geen geldig netwerk: {n}', ['n' => $n]);
            }
            $nets[] = $n;
        }
        $grp = trim(mb_substr((string) ($in['grp'] ?? ''), 0, 60));
        $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        foreach (str_split(random_bytes(25)) as $i => $b) {
            $code .= ($i && $i % 5 === 0 ? '-' : '') . $abc[ord($b) & 31];   // 125 bits, bv. K7QFM-2XA9R-TDHC4-PWE8N-3JKLM
        }
        $key = self::key($code);
        $until = date('Y-m-d H:i:s', time() + $days * 86400);
        q('INSERT INTO agent_enroll (name, kid, ekey, os, max_hosts, used, until, nets, grp, created_by, created_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
            [$name, AgentCrypto::keyId($key), $key, 'windows', $max, $until, implode(' ', $nets), $grp !== '' ? $grp : null, $by, now()]);
        $id = (int) db()->lastInsertId();
        AgentCommands::audit($by, 'Uitrolcode gemaakt', "$name · " . t('{n} servers', ['n' => $max]) . " · $until");
        return ['code' => $code, 'id' => $id, 'until' => $until];
    }

    /** Een IP-adres of netwerk (192.168.1.0/24, 2001:db8::/32) */
    private static function validNet(string $n): bool
    {
        [$ip, $bits] = array_pad(explode('/', $n, 2), 2, null);
        $bin = @inet_pton((string) $ip);
        return $bin !== false && ($bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8));
    }

    /** @return list<array<string, mixed>> de uitrolcodes (zonder sleutel) */
    public static function all(): array
    {
        $out = [];
        foreach (q('SELECT id, name, os, max_hosts, used, until, nets, grp, created_by, created_at, revoked, last_at FROM agent_enroll ORDER BY id DESC LIMIT 100') as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'os' => $r['os'], 'max' => (int) $r['max_hosts'], 'used' => (int) $r['used'], 'until' => $r['until'],
                'nets' => (string) $r['nets'], 'grp' => $r['grp'], 'by' => $r['created_by'], 'at' => $r['created_at'], 'last_at' => $r['last_at'],
                'active' => !(int) $r['revoked'] && $r['until'] > now() && (int) $r['used'] < (int) $r['max_hosts'], 'revoked' => (bool) (int) $r['revoked']];
        }
        return $out;
    }

    public static function revoke(int $id, string $by): bool
    {
        $name = q('SELECT name FROM agent_enroll WHERE id = ?', [$id])->fetchColumn();
        if ($name === false) {
            return false;
        }
        q('UPDATE agent_enroll SET revoked = 1 WHERE id = ?', [$id]);
        AgentCommands::audit($by, 'Uitrolcode ingetrokken', (string) $name);
        return true;
    }

    /** @return array<string, mixed>|null de geldige uitrolcode bij een kenmerk (header X-Agent-Enroll) */
    public static function find(string $kid): ?array
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $kid)) {
            return null;
        }
        $r = q('SELECT * FROM agent_enroll WHERE kid = ? AND revoked = 0 AND until > ?', [$kid, now()])->fetch();
        return $r ?: null;
    }

    /**
     * Een server meldt zich aan: de nieuwe server, of de reden van weigeren
     * @param array<string, mixed> $e de uitrolcode
     * @param array<string, mixed> $in wat de agent stuurt (hostname, os, agent_version)
     * @return array<string, mixed>|string
     */
    public static function enroll(array $e, array $in, string $ip): array|string
    {
        if ((int) $e['used'] >= (int) $e['max_hosts']) {
            return t('uitrolcode is op (hooguit {n} servers)', ['n' => (int) $e['max_hosts']]);
        }
        if ((string) $e['nets'] !== '' && !ip_in_list($ip, preg_split('/\s+/', (string) $e['nets']) ?: [])) {
            return t('uitrolcode geldt niet vanaf {ip}', ['ip' => $ip]);
        }
        $os = (string) ($in['os'] ?? '');
        if ($os !== (string) $e['os']) {
            return t('uitrolcode is voor {os}', ['os' => (string) $e['os']]);
        }
        $name = strtolower(trim((string) ($in['hostname'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $name)) {
            return t('ongeldige computernaam');
        }
        if (q('SELECT id FROM hosts WHERE LOWER(name) = ?', [$name])->fetchColumn()) {
            return t("er is al een server '{name}': koppel die met een koppelcode", ['name' => $name]);
        }
        // Zelfde volgorde als bij een nieuwe server met de hand: eerst de server, dan het token (wordt na koppelen weggehaald)
        q("INSERT INTO hosts (name, address, mode, enabled, os, grp) VALUES (?, ?, 'agent', 1, ?, ?)", [$name, mb_substr($ip, 0, 45), $os, $e['grp']]);
        $hid = (int) db()->lastInsertId();
        q('UPDATE agent_enroll SET used = used + 1, last_at = ? WHERE id = ?', [now(), $e['id']]);
        return q('SELECT * FROM hosts WHERE id = ?', [$hid])->fetch() ?: t('Server niet gevonden');
    }
}
