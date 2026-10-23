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
 *
 * Beveiligde verbinding (agent 1.50.0+): elke agent krijgt een eigen sleutel (hosts.agent_key). De agent stuurt dan
 * geen token meer mee, alleen het kenmerk van zijn sleutel (X-Agent-Key bij een versleuteld bericht, X-Agent-Auth
 * met een handtekening bij een GET). Een nieuwe agent koppel je met een koppelcode (eenmalig, 15 minuten geldig):
 * de agent haalt zijn sleutel op in een bericht dat met de koppelcode is versleuteld, dus nooit leesbaar.
 * Een bestaande agent krijgt zijn eigen sleutel in het versleutelde antwoord (met de algemene sleutel).
 * De eigen sleutel wordt na agent_key_days dagen vanzelf vernieuwd; de vorige blijft dan nog 24 uur geldig.
 */
final class AgentCrypto
{
    public const MODES = ['off', 'on', 'required'];
    public const AGENT_MIN = '1.49.0';
    public const OWNKEY_MIN = '1.50.0';
    public const PAIR_MINUTES = 15;
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
    public static function open(string $raw, ?array $keys = null): array
    {
        $keys ??= self::keys();
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
        foreach ($keys as $k) {
            if (hash_equals(hash_hmac('sha256', "rzenc1|{$e['t']}|{$e['n']}|{$e['iv']}|{$e['d']}", (string) hex2bin(self::sub($k, 'mac'))), $e['m'])) {
                $used = $k;
                break;
            }
        }
        if ($used === null) {
            throw new RuntimeException($keys ? t('verkeerde sleutel of ongeldige handtekening') : t('versleuteld bericht, maar RzMon heeft nog geen sleutel'));
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

    // ---------------------------------------------------------------- Eigen sleutel per agent

    /** Geldige sleutels van één server: de eigen, en de vorige zolang die nog 24 uur meetelt */
    public static function hostKeys(array $host): array
    {
        $keys = [];
        if (preg_match('/^[0-9a-f]{64}$/', (string) ($host['agent_key'] ?? ''))) {
            $keys[] = $host['agent_key'];
        }
        if (preg_match('/^[0-9a-f]{64}$/', (string) ($host['agent_key_old'] ?? '')) && strtotime((string) ($host['agent_key_old_until'] ?? '')) > time()) {
            $keys[] = $host['agent_key_old'];
        }
        return $keys;
    }

    /**
     * Nieuwe eigen sleutel voor een server. $keepOld: de huidige blijft nog 24 uur geldig (vernieuwen, zodat de agent
     * de nieuwe bij zijn volgende rapport krijgt); anders vervalt hij meteen (opnieuw koppelen).
     */
    public static function newHostKey(int $hostId, bool $keepOld): string
    {
        $new = bin2hex(random_bytes(32));
        $cur = (string) q('SELECT agent_key FROM hosts WHERE id = ?', [$hostId])->fetchColumn();
        $old = $keepOld && preg_match('/^[0-9a-f]{64}$/', $cur) ? $cur : null;
        q('UPDATE hosts SET agent_key = ?, agent_key_id = ?, agent_key_at = ?, agent_key_seen = IF(? IS NULL, 0, agent_key_seen), agent_key_old = ?, agent_key_old_id = ?, agent_key_old_until = ? WHERE id = ?',
            [$new, self::keyId($new), now(), $old, $old, $old ? self::keyId($old) : null, $old ? date('Y-m-d H:i:s', time() + self::GRACE) : null, $hostId]);
        return $new;
    }

    /** Is de eigen sleutel aan vernieuwing toe? (agent_key_days, 0 = alleen met de hand) */
    public static function rotateDue(array $host): bool
    {
        $days = (int) setting('agent_key_days', '30');
        return $days > 0 && !empty($host['agent_key_at']) && strtotime((string) $host['agent_key_at']) < time() - $days * 86400;
    }

    /**
     * Handtekening van een GET-verzoek controleren. Header X-Agent-Auth: kenmerk:tijd:nonce:HMAC(mac, "rzget1|actie|tijd|nonce").
     * @return array{0:string,1:string} [sleutel, nonce]
     * @throws RuntimeException met de reden
     */
    public static function verifyGet(string $header, array $keys, string $action): array
    {
        if (!preg_match('/^([0-9a-f]{16}):(\d{9,11}):([0-9a-f]{32}):([0-9a-f]{64})$/', $header, $m)) {
            throw new RuntimeException(t('handtekening onvolledig'));
        }
        if (abs(time() - (int) $m[2]) > self::MAX_AGE) {
            throw new RuntimeException(t('versleuteld bericht is te oud of komt uit de toekomst (klopt de klok van de server?)'));
        }
        foreach ($keys as $k) {
            if (hash_equals(self::keyId($k), $m[1])
                && hash_equals(hash_hmac('sha256', "rzget1|$action|{$m[2]}|{$m[3]}", (string) hex2bin(self::sub($k, 'mac'))), $m[4])) {
                if (q('INSERT IGNORE INTO agent_nonces (nonce, created_at) VALUES (?, ?)', [$m[3], now()])->rowCount() === 0) {
                    throw new RuntimeException(t('dit bericht kwam al eens binnen (opnieuw afgespeeld?)'));
                }
                return [$k, $m[3]];
            }
        }
        throw new RuntimeException(t('verkeerde sleutel of ongeldige handtekening'));
    }

    // ---------------------------------------------------------------- Koppelcode

    /** Sleutel bij een koppelcode (zelfde berekening als de agent: SHA-256 over "rzpair1|CODE", zonder streepjes) */
    public static function pairKey(string $code): string
    {
        return hash('sha256', 'rzpair1|' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    /** Nieuwe koppelcode voor een server, 15 minuten geldig. Bv. K7QF-M2XA-9RTD-HC4P-WE8N (100 bits) */
    public static function pairCreate(int $hostId): array
    {
        $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // geen 0/O en 1/I: makkelijk over te typen
        $code = '';
        foreach (str_split(random_bytes(20)) as $i => $b) {
            $code .= ($i && $i % 4 === 0 ? '-' : '') . $abc[ord($b) & 31];
        }
        $until = date('Y-m-d H:i:s', time() + self::PAIR_MINUTES * 60);
        q('UPDATE hosts SET pair_key = ?, pair_until = ? WHERE id = ?', [self::pairKey($code), $until, $hostId]);
        return ['code' => $code, 'until' => $until];
    }

    /** Server bij het kenmerk van een koppelcode (header X-Agent-Pair), of null (onbekend of verlopen) */
    public static function pairHost(string $id): ?array
    {
        foreach (q('SELECT * FROM hosts WHERE pair_key IS NOT NULL AND pair_until > ?', [now()])->fetchAll() as $h) {
            if (hash_equals(self::keyId((string) $h['pair_key']), $id)) {
                return $h;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- Certificaat vastzetten

    /**
     * Vingerafdruk van het eigen (self-signed) certificaat van RzMon, voor curl --pinnedpubkey (sha256//base64).
     * '' = niet vastzetten: uitgezet, geen https, of een certificaat dat gewoon te controleren is (bv. Let's Encrypt).
     * null = onbekend (niet te bepalen): dan verandert de agent niets aan wat hij heeft.
     * $fresh: niet uit de cache (een uur), bv. als een agent een ander certificaat zag.
     * Alleen een verzoek van een agent bewaart de uitkomst: die komt binnen op het adres dat de agents gebruiken.
     * De beheerpagina (misschien via een ander adres, bv. http op het LAN) leest de bewaarde waarde; is er nog
     * niets bewaard, dan bepaalt hij hem alleen voor zichzelf (voor het installatiecommando).
     */
    public static function pin(bool $fresh = false, bool $agent = true): ?string
    {
        if (setting('agent_pin', '1') !== '1') {
            return '';
        }
        $at = (int) setting('agent_pin_at', '0');
        if (!$fresh && ($at > time() - 3600 || (!$agent && $at > 0))) {
            $v = (string) setting('agent_pin_value', '');
            return $v === '?' ? null : $v;
        }
        $v = self::detectPin();
        if ($agent) {
            save_setting('agent_pin_value', $v ?? '?');
            save_setting('agent_pin_at', (string) time());
        }
        return $v;
    }

    /** Het certificaat ophalen bij het adres waarop dit verzoek binnenkwam */
    private static function detectPin(): ?string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if (!$https && !$fwd) {
            return '';   // RzMon draait via http: niets vast te zetten
        }
        $name = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $name = trim($name, '[]');
        if ($name === '') {
            return null;
        }
        // Rechtstreeks (zelfde webserver): de eigen poort; via een proxy: poort 443 van dat adres
        $target = $https ? '127.0.0.1:' . (int) ($_SERVER['SERVER_PORT'] ?? 443) : (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$name]" : $name) . ':443';
        $get = static function (bool $verify) use ($target, $name) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify,
                'peer_name' => $name, 'SNI_enabled' => true, 'allow_self_signed' => !$verify]]);
            $c = @stream_socket_client("ssl://$target", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            if (!$c) {
                return false;
            }
            $cert = stream_context_get_params($c)['options']['ssl']['peer_certificate'] ?? null;
            fclose($c);
            return $cert ?: false;
        };
        if ($get(true) !== false) {
            return '';   // geldig certificaat van een bekende uitgever: gewone controle is genoeg
        }
        $cert = $get(false);
        if ($cert === false || !($pub = openssl_pkey_get_public($cert))) {
            return null;
        }
        $pem = (string) (openssl_pkey_get_details($pub)['key'] ?? '');
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        return $der ? base64_encode(hash('sha256', $der, true)) : null;
    }

    // ---------------------------------------------------------------- Vast IP-adres

    /** Mag deze server vanaf dit IP? Leert het eerste adres; een nieuw adres wacht op toestemming (Instellingen). */
    public static function ipAllowed(array $host, string $ip): bool
    {
        if (setting('agent_ip_lock', '1') !== '1') {
            return true;
        }
        $ips = preg_split('/\s+/', trim((string) ($host['agent_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        if (!$ips) {
            q('UPDATE hosts SET agent_ips = ? WHERE id = ?', [mb_substr($ip, 0, 45), $host['id']]);
            return true;
        }
        if (in_array($ip, $ips, true)) {
            return true;
        }
        q('UPDATE hosts SET agent_ip_new = ?, agent_ip_new_at = ? WHERE id = ?', [mb_substr($ip, 0, 45), now(), $host['id']]);
        return false;
    }

    /** Nieuw IP-adres toestaan (naast de bekende; hooguit 5) */
    public static function ipAllow(int $hostId, string $by): ?string
    {
        $h = q('SELECT name, agent_ips, agent_ip_new FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || !$h['agent_ip_new']) {
            return null;
        }
        $ips = preg_split('/\s+/', trim((string) $h['agent_ips']), -1, PREG_SPLIT_NO_EMPTY);
        $ips[] = $h['agent_ip_new'];
        $ips = array_slice(array_values(array_unique($ips)), -5);
        q('UPDATE hosts SET agent_ips = ?, agent_ip_new = NULL, agent_ip_new_at = NULL WHERE id = ?', [implode(' ', $ips), $hostId]);
        AgentCommands::audit($by, 'IP-adres van de agent toegestaan', "{$h['name']}: {$h['agent_ip_new']}");
        return $h['agent_ip_new'];
    }
}
