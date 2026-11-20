<?php
declare(strict_types=1);

/**
 * 1.282.0: RzSense Relay. Een netwerk zonder verbinding met RzSense krijgt een relay: een container (of app) met de agent
 * erin en een klein https-programma dat het verkeer van de agents in dat netwerk doorstuurt naar RzSense.
 *
 *   agent op server ──▶ relay ──▶ RzSense   (en het antwoord dezelfde weg terug)
 *
 * - De rapporten zijn versleuteld van de agent tot RzSense; de relay ziet alleen dichte enveloppen.
 * - De relay zet bij elk doorgestuurd verzoek het IP-adres van de agent (X-Rz-Relay-For), ondertekend met een sleutel
 *   die is afgeleid van zijn eigen agentsleutel (HMAC-SHA256). Alleen dan gebruikt RzSense dat adres (IP-slot,
 *   blokkades, logboek); anders telt gewoon het adres waarvan het verzoek komt.
 * - Netwerken (relay_networks): adresreeksen met een relay en eventueel een reserve-relay. Een server hoort bij een
 *   netwerk door zijn IP-adres, of omdat je hem er zelf in zet (hosts.net_id).
 * - Valt een relay weg, dan worden de meldingen van de agents erachter onderdrukt (Monitor::upstreamDown); de relay
 *   zelf meldt zich als "agent stil", met hoeveel agents erachter zitten.
 */
final class Relay
{
    public const PORT = 8444;
    /** Agent in de relay-container die relayregels begrijpt */
    public const AGENT_MIN = '1.109.0';
    /** Zoveel seconden mag de klok van de relay afwijken */
    public const MAX_SKEW = 300;

    private static ?array $current = null;
    private static bool $checked = false;

    // ------------------------------------------------------------------ doorgestuurd verzoek

    /**
     * Komt dit verzoek via een relay die RzSense kent, met een geldige handtekening? Dan [id, name, for] (for = IP-adres
     * van de agent achter de relay), anders null. Eén keer per verzoek uitgerekend.
     */
    public static function current(): ?array
    {
        if (self::$checked) {
            return self::$current;
        }
        self::$checked = true;
        // 1.292.0: een verzoek dat RzSense zelf bij een relay ophaalde (cron/relay-item.php); de relay is al gecontroleerd
        if (isset($GLOBALS['rz_pull']['relay'], $GLOBALS['rz_pull']['for']) && PHP_SAPI === 'cli') {
            $name = q('SELECT name FROM hosts WHERE id = ? AND relay_on = 1 AND relay_pull = 1', [(int) $GLOBALS['rz_pull']['relay']])->fetchColumn();
            return self::$current = $name !== false ? ['id' => (int) $GLOBALS['rz_pull']['relay'], 'name' => (string) $name, 'for' => (string) $GLOBALS['rz_pull']['for']] : null;
        }
        $kid = (string) ($_SERVER['HTTP_X_RZ_RELAY'] ?? '');
        $for = (string) ($_SERVER['HTTP_X_RZ_RELAY_FOR'] ?? '');
        $ts = (string) ($_SERVER['HTTP_X_RZ_RELAY_TS'] ?? '');
        $sig = strtolower((string) ($_SERVER['HTTP_X_RZ_RELAY_SIG'] ?? ''));
        if (!preg_match('/^[0-9a-f]{16}$/', $kid) || !filter_var($for, FILTER_VALIDATE_IP) || !preg_match('/^\d{9,11}$/', $ts)
            || !preg_match('/^[0-9a-f]{64}$/', $sig) || abs(time() - (int) $ts) > self::MAX_SKEW || !function_exists('is_configured') || !is_configured()) {
            return null;
        }
        try {
            $h = q("SELECT id, name, enabled, relay_on, agent_key, agent_key_old, agent_key_old_until FROM hosts
                    WHERE mode = 'agent' AND (agent_key_id = ? OR (agent_key_old_id = ? AND agent_key_old_until > ?)) LIMIT 1", [$kid, $kid, now()])->fetch();
        } catch (Throwable) {
            return null;
        }
        if (!$h || !(int) $h['enabled'] || !(int) $h['relay_on']) {
            return null;
        }
        $msg = self::message($ts, $for, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), self::requestTarget(), hash('sha256', request_body()));
        foreach (AgentCrypto::hostKeys($h) as $key) {
            if (hash_equals(hash_hmac('sha256', $msg, (string) hex2bin(self::signKey($key))), $sig)) {
                return self::$current = ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'for' => $for];
            }
        }
        return null;
    }

    /** Alleen voor de tests: opnieuw bepalen */
    public static function reset(): void
    {
        self::$checked = false;
        self::$current = null;
    }

    /** Sleutel waarmee een relay ondertekent (afgeleid van zijn agentsleutel; de agent rekent hem zo ook uit) */
    public static function signKey(string $agentKey): string
    {
        return hash_hmac('sha256', 'relay', (string) hex2bin($agentKey));
    }

    /** Wat er ondertekend wordt; gelijk aan relay.py */
    public static function message(string $ts, string $for, string $method, string $target, string $bodySha): string
    {
        return "rzsense-relay\n$ts\n$for\n" . strtoupper($method) . "\n$target\n$bodySha";
    }

    /** "api.php?action=agent" (zonder map ervoor: RzSense kan achter een proxy in een submap staan) */
    public static function requestTarget(): string
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'api.php'));
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        return $qs !== '' ? "$script?$qs" : $script;
    }

    /** Na een gelukte aanmelding van een agent: via welke relay kwam hij binnen (of rechtstreeks) */
    public static function noteVia(array $host): void
    {
        $r = self::current();
        $via = $r && $r['id'] !== (int) $host['id'] ? $r['id'] : null;
        q('UPDATE hosts SET relay_via = ?, relay_via_at = ? WHERE id = ?', [$via, $via !== null ? now() : null, $host['id']]);
    }

    // ------------------------------------------------------------------ de relay zelf (zijn agent meldt)

    /** Uit het rapport van de agent in de relay-container: certificaat, poort, adressen en drukte */
    public static function fromAgent(array $host, array $in): void
    {
        if (!is_array($in['relay'] ?? null)) {
            return;
        }
        $r = $in['relay'];
        $ips = array_values(array_slice(array_filter(array_map('strval', (array) ($r['ips'] ?? [])), static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false), 0, 16));
        $st = [];
        foreach (['req', 'ok', 'err', 'deny', 'clients', 'up_err', 'busy'] as $k) {
            $st[$k] = max(0, (int) (($r['st'] ?? [])[$k] ?? 0));
        }
        $up = (string) (($r['st'] ?? [])['up'] ?? '');
        $kind = in_array($r['kind'] ?? '', ['container', 'agent'], true) ? (string) $r['kind'] : 'container';
        $mid = preg_match('/^[0-9a-f]{16,64}$/', (string) ($r['mid'] ?? '')) ? (string) $r['mid'] : '';
        $data = [
            'kind' => $kind, 'mid' => $mid,
            'pin' => preg_match('~^[A-Za-z0-9+/]{43}=$~', (string) ($r['pin'] ?? '')) ? (string) $r['pin'] : '',
            'port' => max(1, min(65535, (int) ($r['port'] ?? self::PORT))),
            'ips' => $ips,
            'ver' => preg_match('/^[0-9][0-9.]{0,15}$/', (string) ($r['ver'] ?? '')) ? (string) $r['ver'] : '',
            'st' => $st + ['up' => AgentCrypto::validUrl($up) ? $up : ''],
            'allow' => mb_substr(preg_replace('/[^0-9a-fA-F.:\/, ]/', '', (string) ($r['allow'] ?? '')), 0, 1000),
            'at' => time(),
        ];
        q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$host['id'], 'relay', json_encode($data, JSON_UNESCAPED_SLASHES), now()]);
        $h = q('SELECT relay_on, relay_port, relay_url, relay_of, relay_kind FROM hosts WHERE id = ?', [$host['id']])->fetch();
        if ($h && !(int) $h['relay_on']) {
            // Een relay meldt zich: vanaf nu is dit een relay
            q('UPDATE hosts SET relay_on = 1, relay_port = COALESCE(relay_port, ?) WHERE id = ?', [$data['port'], $host['id']]);
            ActionLog::add((int) $host['id'], 'agent', 'agent', t('Relay aangemeld'), t('poort {p}', ['p' => $data['port']]), true);
        }
        if ($h && ((string) $h['relay_kind'] !== $kind || (int) $h['relay_port'] !== $data['port'])) {
            q('UPDATE hosts SET relay_kind = ?, relay_port = ? WHERE id = ?', [$kind, $data['port'], $host['id']]);
        }
        // 1.283.0: losse relay-agent: bij welke server hoort hij? Die met dezelfde machine (machine-id, gehasht)
        if ($h && $h['relay_of'] === null && $kind === 'agent' && $mid !== '') {
            $srv = q("SELECT h.id FROM hosts h JOIN agent_data d ON d.host_id = h.id AND d.metric = 'mid'
                      WHERE d.value = ? AND h.id <> ? AND h.relay_on = 0 AND h.mode = 'agent' LIMIT 1", [$mid, $host['id']])->fetchColumn();
            if ($srv !== false) {
                q('UPDATE hosts SET relay_of = ? WHERE id = ?', [(int) $srv, $host['id']]);
            }
        }
        self::ensureCheck((int) $host['id']);
    }

    /** 1.283.0: machine-id (gehasht) van een gewone agent, om een losse relay-agent op dezelfde server te herkennen */
    public static function noteMid(array $host, array $in): void
    {
        $mid = (string) ($in['mid'] ?? '');
        if (preg_match('/^[0-9a-f]{16,64}$/', $mid) && !isset($in['relay'])) {
            q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
                [$host['id'], 'mid', $mid, now()]);
        }
    }

    /**
     * 1.283.0: een relay heeft minstens één check, anders komt er geen melding als hij wegvalt ("agent stil" komt van de
     * checks van een server). Een CPU-check met ruime drempels: wordt de agent stil, dan wordt hij critical.
     */
    public static function ensureCheck(int $hostId): void
    {
        if (!q('SELECT COUNT(*) FROM checks WHERE host_id = ?', [$hostId])->fetchColumn()) {
            q("INSERT INTO checks (host_id, type, name, warn_threshold, crit_threshold) VALUES (?, 'cpu', ?, 95, 99)", [$hostId, t('Relay')]);
        }
    }

    /** Opgeslagen gegevens van een relay (zie fromAgent), of [] */
    public static function data(int $hostId): array
    {
        return (array) json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'relay'", [$hostId])->fetchColumn(), true);
    }

    /** Vingerafdruk (sha256 van de publieke sleutel, base64) van het certificaat van een relay, of '' */
    public static function pin(int $hostId): string
    {
        return (string) (self::data($hostId)['pin'] ?? '');
    }

    /** "sha256//…" voor een adres dat bij een relay hoort (AgentCrypto::urlSpec), of null */
    public static function pinForUrl(string $url): ?string
    {
        $url = rtrim($url, '/');
        if ($url === '') {
            return null;
        }
        try {
            foreach (q("SELECT id, relay_url FROM hosts WHERE relay_on = 1 AND relay_url IS NOT NULL AND relay_url <> ''")->fetchAll() as $r) {
                if (rtrim((string) $r['relay_url'], '/') === $url) {
                    $p = self::pin((int) $r['id']);
                    return $p !== '' ? "sha256//$p" : null;
                }
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /** Is dit het adres van een relay? */
    public static function isRelayUrl(?string $url): bool
    {
        $url = rtrim((string) $url, '/');
        return $url !== '' && (bool) q("SELECT COUNT(*) FROM hosts WHERE relay_on = 1 AND TRIM(TRAILING '/' FROM relay_url) = ?", [$url])->fetchColumn();
    }

    /** Netwerken waar de relay verkeer uit mag aannemen: alle adresreeksen van de netwerken die hij bedient */
    public static function allowFor(int $relayId): array
    {
        $out = [];
        foreach (q('SELECT cidrs FROM relay_networks WHERE relay_id = ? OR relay_fb_id = ?', [$relayId, $relayId])->fetchAll(PDO::FETCH_COLUMN) as $c) {
            array_push($out, ...self::cidrList((string) $c));
        }
        return array_values(array_unique($out));
    }

    /** Regel voor de agent in de relay-container: "0 <tab> relay <tab> poort <tab> netwerken" (leeg netwerk = '-') */
    public static function agentLines(int $hostId): array
    {
        $h = q('SELECT relay_on, relay_port, agent_version FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || !(int) $h['relay_on']) {
            return [];
        }
        $allow = self::allowFor($hostId);
        $out = ["0\trelay\t" . ((int) $h['relay_port'] ?: self::PORT) . "\t" . ($allow ? implode(',', $allow) : '-')];
        if (version_compare((string) $h['agent_version'], self::CHECKS_AGENT, '>=')) {
            foreach (self::relayChecks($hostId) as $c) {
                $out[] = implode("\t", [$c['id'], 'rchk', $c['kind'], ...$c['args']]);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ 1.289.0: checks door de relay

    /** Agent die checks voor zijn netwerk uitvoert */
    public const CHECKS_AGENT = '1.112.0';
    /** Zoveel checks per relay (de relay doet ze elke ronde, naast elkaar) */
    public const CHECKS_MAX = 64;
    private const CHECK_TYPES = ['ping', 'tcp', 'http'];

    /** Is dit een adres of naam die de relay veilig kan gebruiken? (geen opties, geen spaties) */
    public static function safeTarget(string $t): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9.:_-]{0,252}$/', $t);
    }

    public static function safeUrl(string $u): bool
    {
        return strlen($u) <= 500 && (bool) preg_match('#^https?://[A-Za-z0-9][A-Za-z0-9.:_-]*(/[^\s"\'\\\\<>`]*)?$#', $u);
    }

    /**
     * De checks die deze relay uitvoert: ping, TCP en HTTP van de servers in zijn netwerken waar "checks via de relay" aan staat.
     * @return list<array{id: int, kind: string, args: list<string>}>
     */
    public static function relayChecks(int $relayId): array
    {
        $out = [];
        foreach (q("SELECT c.id, c.type, c.target, c.verify_ssl, h.address FROM checks c JOIN hosts h ON h.id = c.host_id
                    JOIN relay_networks n ON n.id = h.net_id
                    WHERE n.relay_checks = 1 AND n.relay_id = ? AND c.enabled = 1 AND h.enabled = 1 AND h.id <> ? AND c.type IN ('ping', 'tcp', 'http')
                    ORDER BY c.id LIMIT " . self::CHECKS_MAX, [$relayId, $relayId])->fetchAll() as $c) {
            $addr = trim((string) $c['address']);
            if ($c['type'] === 'http') {
                $url = trim((string) $c['target']);
                if ($url === '' || $url[0] === '/') {
                    $url = 'http://' . $addr . ($url ?: '/');
                }
                if (self::safeUrl($url)) {
                    $out[] = ['id' => (int) $c['id'], 'kind' => 'http', 'args' => [$url, (int) ($c['verify_ssl'] ?? 1) === 1 ? '1' : '0']];
                }
            } elseif (self::safeTarget($addr)) {
                $port = (int) $c['target'];
                if ($c['type'] === 'ping') {
                    $out[] = ['id' => (int) $c['id'], 'kind' => 'ping', 'args' => [$addr]];
                } elseif ($port >= 1 && $port <= 65535) {
                    $out[] = ['id' => (int) $c['id'], 'kind' => 'tcp', 'args' => [$addr, (string) $port]];
                }
            }
        }
        return $out;
    }

    /**
     * Gaat deze check door een relay? Dan die relay (id, naam, tick), anders null.
     * @param array<string, mixed> $check
     * @param array<string, mixed> $host
     * @return array{id: int, name: string, tick: int}|null
     */
    public static function checkVia(array $check, array $host): ?array
    {
        if (!in_array($check['type'] ?? '', self::CHECK_TYPES, true) || empty($host['net_id'])) {
            return null;
        }
        $r = q('SELECT r.id, r.name, r.agent_tick FROM relay_networks n JOIN hosts r ON r.id = n.relay_id AND r.relay_on = 1
                WHERE n.id = ? AND n.relay_checks = 1 AND r.id <> ?', [(int) $host['net_id'], (int) $host['id']])->fetch();
        return $r ? ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'tick' => agent_tick((int) ($r['agent_tick'] ?? 0) ?: null)] : null;
    }

    /**
     * Uitkomsten van de relay bewaren (alleen voor checks die hij echt moet doen)
     * @param array<string, mixed> $relay
     */
    public static function checkResults(array $relay, mixed $list): int
    {
        // De rij van agent_host() heeft niet alle kolommen: zelf kijken of dit een relay is
        if (!is_array($list) || !$list || !(int) q('SELECT relay_on FROM hosts WHERE id = ?', [(int) ($relay['id'] ?? 0)])->fetchColumn()) {
            return 0;
        }
        $mine = array_column(self::relayChecks((int) $relay['id']), 'kind', 'id');
        $n = 0;
        foreach (array_slice($list, 0, self::CHECKS_MAX) as $r) {
            $id = (int) ($r['id'] ?? 0);
            if (!is_array($r) || !isset($mine[$id]) || ($r['t'] ?? '') !== $mine[$id]) {
                continue;
            }
            $v = ['t' => $mine[$id], 'ok' => !empty($r['ok']), 'ms' => isset($r['ms']) && is_numeric($r['ms']) ? round((float) $r['ms'], 2) : null,
                'loss' => isset($r['loss']) && is_numeric($r['loss']) ? max(0.0, min(100.0, (float) $r['loss'])) : null,
                'code' => (int) ($r['code'] ?? 0), 'err' => mb_substr(preg_replace('/[^\P{C}]/u', '', (string) ($r['err'] ?? '')) ?? '', 0, 200)];
            q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
                [(int) $relay['id'], "rchk:$id", (string) json_encode($v), now()]);
            $n++;
        }
        return $n;
    }

    /**
     * De uitkomst van de relay als uitkomst van de check (zelfde drempels en teksten als wanneer RzSense het zelf doet)
     * @param array<string, mixed> $check
     * @param array{id: int, name: string, tick: int} $via
     * @return array<string, mixed>
     */
    public static function checkResult(array $check, array $via): array
    {
        $row = q('SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = ?', [$via['id'], 'rchk:' . (int) $check['id']])->fetch();
        $by = ' · ' . t('via relay {r}', ['r' => $via['name']]);
        if (!$row || strtotime((string) $row['updated_at']) < time() - max(180, 3 * $via['tick'])) {
            return Checks::result(STATUS_UNKNOWN, null, t('Nog geen uitkomst van de relay (de relay voert deze check uit vanaf agent {v})', ['v' => self::CHECKS_AGENT]) . $by);
        }
        $r = json_decode((string) $row['value'], true) ?: [];
        $ms = isset($r['ms']) ? (float) $r['ms'] : null;
        switch ($check['type']) {
            case 'ping':
                $loss = (float) ($r['loss'] ?? 100);
                if ($loss >= 100) {
                    return Checks::result(STATUS_CRIT, null, ($r['err'] ? t('Ping mislukt') . ': ' . $r['err'] : t('Host reageert niet (100% packet loss)')) . $by);
                }
                $st = $ms !== null ? Checks::threshold($ms, $check) : STATUS_OK;
                return Checks::result($loss > 0 ? max($st, STATUS_WARN) : $st, $ms, sprintf(t('%s ms gemiddeld, %s%% packet loss'), $ms !== null ? round($ms, 1) : '?', $loss) . $by);
            case 'tcp':
                $port = (int) $check['target'];
                return empty($r['ok']) ? Checks::result(STATUS_CRIT, null, t('Poort {port} onbereikbaar', ['port' => $port]) . ($r['err'] ? ': ' . $r['err'] : '') . $by)
                    : Checks::result(Checks::threshold((float) $ms, $check), $ms, sprintf(t('Poort %d open (%.1f ms)'), $port, (float) $ms) . $by);
            default:
                $code = (int) ($r['code'] ?? 0);
                if ($code === 0) {
                    return Checks::result(STATUS_CRIT, null, t('Geen antwoord') . ($r['err'] ? ': ' . $r['err'] : '') . $by);
                }
                if ($code >= 400) {
                    return Checks::result(STATUS_CRIT, $ms, "HTTP $code" . $by);
                }
                return Checks::result(Checks::threshold((float) $ms, $check), $ms, sprintf(t('HTTP %d in %.0f ms'), $code, (float) $ms) . $by);
        }
    }

    /** Is de relay van deze server weg (agent stil)? Dan [id, name]: de meldingen van deze server worden onderdrukt */
    public static function downFor(int $hostId): ?array
    {
        $r = q('SELECT r.id, r.name, r.enabled, r.agent_seen, r.agent_tick FROM hosts h JOIN hosts r ON r.id = h.relay_via
                WHERE h.id = ? AND r.relay_on = 1 AND h.relay_via_at > NOW() - INTERVAL 1 DAY', [$hostId])->fetch();
        if (!$r || !(int) $r['enabled']) {
            return null;
        }
        $stale = $r['agent_seen'] === null || strtotime((string) $r['agent_seen']) < time() - agent_stale_seconds($r['agent_tick'] !== null ? (int) $r['agent_tick'] : null);
        return $stale ? ['id' => (int) $r['id'], 'name' => (string) $r['name']] : null;
    }

    /** Hoeveel agents de laatste dag via deze relay binnenkwamen */
    public static function behind(int $relayId): int
    {
        return (int) q('SELECT COUNT(*) FROM hosts WHERE relay_via = ? AND relay_via_at > NOW() - INTERVAL 1 DAY AND enabled = 1', [$relayId])->fetchColumn();
    }

    // ------------------------------------------------------------------ netwerken

    /** Adresreeksen uit een tekstveld: alleen geldige (192.168.50.0/24, 10.0.0.0/8, fd00::/8 of een los adres) */
    public static function cidrList(string $text): array
    {
        $out = [];
        foreach (parse_list($text) as $c) {
            if (self::validCidr($c)) {
                $out[] = $c;
            }
        }
        return array_values(array_unique($out));
    }

    public static function validCidr(string $c): bool
    {
        [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $max = str_contains((string) $ip, ':') ? 128 : 32;
        return $bits === null || (ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max);
    }

    public static function networks(): array
    {
        return array_map(static fn($n) => [
            'id' => (int) $n['id'], 'name' => (string) $n['name'], 'cidrs' => self::cidrList((string) $n['cidrs']),
            'relay_id' => $n['relay_id'] !== null ? (int) $n['relay_id'] : null, 'relay_fb_id' => $n['relay_fb_id'] !== null ? (int) $n['relay_fb_id'] : null,
            'note' => (string) $n['note'], 'relay_checks' => !empty($n['relay_checks']),
        ], q('SELECT * FROM relay_networks ORDER BY name')->fetchAll());
    }

    /** Netwerk opslaan (nieuw zonder id). @return string|int fout, of het id */
    public static function saveNetwork(array $in, string $by): string|int
    {
        $id = (int) ($in['id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            return t('Geef het netwerk een naam (hooguit 80 tekens)');
        }
        $raw = parse_list((string) ($in['cidrs'] ?? ''));
        $bad = array_values(array_filter($raw, static fn($c) => !self::validCidr($c)));
        if ($bad) {
            return t('Geen geldige adresreeks: {c} (bijvoorbeeld 192.168.50.0/24)', ['c' => implode(', ', array_slice($bad, 0, 3))]);
        }
        $cidrs = self::cidrList(implode(' ', $raw));
        if (!$cidrs) {
            return t('Geef minstens één adresreeks op, bijvoorbeeld 192.168.50.0/24');
        }
        if (mb_strlen(implode(' ', $cidrs)) > 1000) {
            return t('Te veel adresreeksen');
        }
        $relay = (int) ($in['relay_id'] ?? 0) ?: null;
        $fb = (int) ($in['relay_fb_id'] ?? 0) ?: null;
        foreach ([$relay, $fb] as $r) {
            if ($r !== null && !q('SELECT COUNT(*) FROM hosts WHERE id = ? AND relay_on = 1', [$r])->fetchColumn()) {
                return t('Deze relay bestaat niet (meer)');
            }
        }
        if ($fb !== null && ($relay === null || $fb === $relay)) {
            $fb = null;
        }
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);
        $checks = !empty($in['relay_checks']) && $relay !== null ? 1 : 0;   // 1.289.0
        if ($id) {
            if (!q('SELECT COUNT(*) FROM relay_networks WHERE id = ?', [$id])->fetchColumn()) {
                return t('Netwerk niet gevonden');
            }
            q('UPDATE relay_networks SET name = ?, cidrs = ?, relay_id = ?, relay_fb_id = ?, note = ?, relay_checks = ?, updated_at = ? WHERE id = ?',
                [$name, implode(' ', $cidrs), $relay, $fb, $note, $checks, now(), $id]);
        } else {
            q('INSERT INTO relay_networks (name, cidrs, relay_id, relay_fb_id, note, relay_checks, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, implode(' ', $cidrs), $relay, $fb, $note, $checks, now()]);
            $id = (int) db()->lastInsertId();
        }
        ActionLog::add(null, 'rzsense', 'settings', t('Netwerk opgeslagen: {n}', ['n' => $name]), implode(', ', $cidrs) . ' · ' . ($relay ? t('via relay') : t('rechtstreeks')), true, '', null, $by);
        return $id;
    }

    public static function deleteNetwork(int $id, string $by): bool
    {
        $name = q('SELECT name FROM relay_networks WHERE id = ?', [$id])->fetchColumn();
        if ($name === false) {
            return false;
        }
        q('UPDATE hosts SET net_id = NULL WHERE net_id = ?', [$id]);
        q('DELETE FROM relay_networks WHERE id = ?', [$id]);
        ActionLog::add(null, 'rzsense', 'settings', t('Netwerk verwijderd: {n}', ['n' => $name]), '', true, '', null, $by);
        return true;
    }

    /** Netwerk van een server: zelf gekozen, anders het eerste netwerk waar zijn adres (of agent-IP) in valt */
    public static function netOf(array $host, array $nets): ?array
    {
        $byId = array_column($nets, null, 'id');
        if (!empty($host['net_id']) && isset($byId[(int) $host['net_id']])) {
            return $byId[(int) $host['net_id']] + ['auto' => false];
        }
        foreach ([(string) ($host['address'] ?? ''), (string) ($host['agent_ip'] ?? '')] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                foreach ($nets as $n) {
                    if (ip_in_list($ip, $n['cidrs'])) {
                        return $n + ['auto' => true];
                    }
                }
            }
        }
        return null;
    }

    /** Netwerk van een server zelf kiezen (0 = vanzelf op IP-adres) */
    public static function setNet(int $hostId, int $netId, string $by): ?string
    {
        if ($netId && !q('SELECT COUNT(*) FROM relay_networks WHERE id = ?', [$netId])->fetchColumn()) {
            return t('Netwerk niet gevonden');
        }
        $name = q('SELECT name FROM hosts WHERE id = ?', [$hostId])->fetchColumn();
        if ($name === false) {
            return t('Server niet gevonden');
        }
        q('UPDATE hosts SET net_id = ? WHERE id = ?', [$netId ?: null, $hostId]);
        ActionLog::add($hostId, 'rzsense', 'settings', t('Netwerk gekozen'), $netId ? (string) q('SELECT name FROM relay_networks WHERE id = ?', [$netId])->fetchColumn() : t('vanzelf (op IP-adres)'), true, '', null, $by);
        return null;
    }

    // ------------------------------------------------------------------ welke agent welk adres

    /**
     * Per agent: in welk netwerk, via welke relay het zou moeten, wat hij nu gebruikt en of hij moet overstappen.
     * Overstappen gaat met het bestaande "adres wisselen" (de agent test het nieuwe adres eerst).
     */
    public static function plan(): array
    {
        $nets = self::networks();
        $relays = [];
        foreach (q("SELECT id, name, enabled, relay_url FROM hosts WHERE relay_on = 1")->fetchAll() as $r) {
            $relays[(int) $r['id']] = $r;
        }
        $urlOf = static fn(?int $id) => $id !== null && isset($relays[$id]) && (int) $relays[$id]['enabled'] && trim((string) $relays[$id]['relay_url']) !== ''
            ? rtrim((string) $relays[$id]['relay_url'], '/') : null;
        $relayUrls = array_filter(array_map(static fn($r) => rtrim((string) $r['relay_url'], '/'), $relays));
        $out = [];
        foreach (q("SELECT id, name, address, enabled, agent_ip, agent_version, agent_seen, agent_key_seen, net_id, agent_url, agent_url_fb, agent_url_want,
                           agent_url_status, relay_via, relay_via_at, relay_direct_url, relay_on
                    FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll() as $h) {
            $net = self::netOf($h, $nets);
            $cur = rtrim((string) $h['agent_url'], '/');
            $curIsRelay = $cur !== '' && in_array($cur, $relayUrls, true);
            $want = $wantFb = null;
            $why = '';
            if ((int) $h['relay_on']) {
                $why = 'relay';   // de relay zelf praat rechtstreeks met RzSense
            } elseif ($net && $net['relay_id'] !== null) {
                $want = $urlOf($net['relay_id']);
                if ($want === null) {
                    $why = 'no_url';   // relay heeft nog geen adres (of staat uit)
                } else {
                    $wantFb = $urlOf($net['relay_fb_id']) ?? ($curIsRelay ? (rtrim((string) $h['relay_direct_url'], '/') ?: null) : ($cur ?: null));
                    if ($wantFb === $want) {
                        $wantFb = null;
                    }
                }
            } elseif ($curIsRelay) {
                // Netwerk zonder relay (of geen netwerk), maar de agent gebruikt nog een relay: terug naar rechtstreeks
                $want = rtrim((string) $h['relay_direct_url'], '/') ?: null;
                $why = $want === null ? 'no_direct' : '';
            }
            $state = 'ok';
            if ($want !== null && $want !== $cur) {
                $state = $h['agent_url_want'] ? 'pending'
                    : (!$h['agent_version'] || version_compare((string) $h['agent_version'], AgentCrypto::PINURL_MIN, '<') ? 'old'
                    : ((int) $h['agent_key_seen'] !== 1 ? 'nokey' : 'switch'));
            } elseif ($why === 'no_url' || $why === 'no_direct') {
                $state = $why;
            }
            $out[] = ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'address' => (string) $h['address'], 'enabled' => (int) $h['enabled'],
                'agent_ip' => (string) $h['agent_ip'], 'version' => (string) $h['agent_version'], 'seen' => $h['agent_seen'],
                'net_id' => $h['net_id'] !== null ? (int) $h['net_id'] : null, 'net' => $net ? ['id' => $net['id'], 'name' => $net['name'], 'auto' => $net['auto']] : null,
                'is_relay' => (bool) (int) $h['relay_on'], 'url' => $cur, 'url_fb' => rtrim((string) $h['agent_url_fb'], '/'), 'want' => $want, 'want_fb' => $wantFb,
                'via' => $h['relay_via'] !== null && $h['relay_via_at'] && strtotime((string) $h['relay_via_at']) > time() - 86400 ? (int) $h['relay_via'] : null,
                'state' => $state, 'status' => (string) $h['agent_url_status']];
        }
        return $out;
    }

    /** Agents laten overstappen naar het adres uit plan() (alleen state 'switch'). $ids leeg = alle. @return int aantal */
    public static function apply(array $ids, string $by): int
    {
        $n = 0;
        foreach (self::plan() as $p) {
            if ($p['state'] !== 'switch' || ($ids && !in_array($p['id'], $ids, true))) {
                continue;
            }
            // Het adres van nu onthouden als het rechtstreeks is: daar gaat hij heen als het netwerk geen relay meer heeft
            $direct = $p['url'] !== '' && !self::isRelayUrl($p['url']) ? $p['url'] : null;
            q('UPDATE hosts SET agent_url_want = ?, agent_url_want_fb = ?, agent_url_status = ?, agent_url_at = ?, relay_direct_url = COALESCE(?, relay_direct_url) WHERE id = ?',
                [$p['want'], (string) ($p['want_fb'] ?? ''), '⏳ ' . t('Overstap naar {url} klaargezet', ['url' => $p['want']]), now(), $direct, $p['id']]);
            ActionLog::add($p['id'], 'rzsense', 'agent', t('Adres van RzSense wisselen'), $p['want'] . ($p['want_fb'] ? ' · ' . t('reserve: {u}', ['u' => $p['want_fb']]) : ''), null, '', null, $by);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ relays beheren

    /** Nieuwe relay: een server van het type agent, die zich met de koppelcode uit het installatiecommando meldt */
    /**
     * Nieuwe relay. 1.292.0: $pull = RzSense haalt op bij de relay (DMZ), met het adres waar RzSense hem bereikt.
     * @param array<string, mixed> $pull ['pull_url' => …, 'pull_every' => …] of [] (de relay belt aan, zoals altijd)
     */
    public static function create(string $name, string $by, ?int $relayOf = null, array $pull = []): int|string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            return t('Geef de relay een naam (hooguit 100 tekens)');
        }
        if (q('SELECT COUNT(*) FROM hosts WHERE name = ?', [$name])->fetchColumn()) {
            return t('Er is al een server met deze naam');
        }
        if ($relayOf !== null && !q("SELECT COUNT(*) FROM hosts WHERE id = ? AND relay_on = 0", [$relayOf])->fetchColumn()) {
            return t('Server niet gevonden');
        }
        if ($pull && ($u = rtrim(trim((string) ($pull['pull_url'] ?? '')), '/')) !== '' && !RelayPull::validUrl($u)) {
            return t('Het ophaaladres begint met https:// en bevat alleen de machine en de poort, bijvoorbeeld https://10.0.5.10:8445');
        }
        $addr = $relayOf !== null ? (string) q('SELECT address FROM hosts WHERE id = ?', [$relayOf])->fetchColumn() : '';
        q("INSERT INTO hosts (name, address, mode, enabled, relay_on, relay_port, relay_of, relay_kind) VALUES (?, ?, 'agent', 1, 1, ?, ?, ?)",
            [$name, $addr, self::PORT, $relayOf, $relayOf !== null ? 'agent' : null]);
        $id = (int) db()->lastInsertId();
        self::ensureCheck($id);
        ActionLog::add($id, 'rzsense', 'agent', t('Relay toegevoegd'), $name, true, '', null, $by);
        if ($pull && ($e = RelayPull::save($id, $pull, $by)) !== null) {
            return $e;
        }
        return $id;
    }

    /** Adres en poort van een relay instellen (het adres waarop de agents achter de relay hem bereiken) */
    public static function saveRelay(int $id, array $in, string $by): ?string
    {
        $h = q('SELECT id, name FROM hosts WHERE id = ? AND relay_on = 1', [$id])->fetch();
        if (!$h) {
            return t('Relay niet gevonden');
        }
        $url = rtrim(trim((string) ($in['url'] ?? '')), '/');
        if ($url !== '' && (!str_starts_with(strtolower($url), 'https://') || !AgentCrypto::validUrl($url))) {
            return t('Het adres van de relay begint met https://, bijvoorbeeld https://192.168.50.10:8444');
        }
        $port = (int) ($in['port'] ?? self::PORT);
        if ($port < 1 || $port > 65535) {
            return t('Ongeldige poort');
        }
        q('UPDATE hosts SET relay_url = ?, relay_port = ? WHERE id = ?', [$url !== '' ? $url : null, $port, $id]);
        ActionLog::add($id, 'rzsense', 'agent', t('Relay ingesteld'), ($url !== '' ? $url : t('nog geen adres')) . " · " . t('poort {p}', ['p' => $port]), true, '', null, $by);
        return null;
    }

    /** Is dit een relay? Uitzetten: agents erachter gaan terug naar hun rechtstreekse adres (via plan/apply) */
    public static function disable(int $id, string $by): bool
    {
        $name = q('SELECT name FROM hosts WHERE id = ? AND relay_on = 1', [$id])->fetchColumn();
        if ($name === false) {
            return false;
        }
        q('UPDATE relay_networks SET relay_id = relay_fb_id, relay_fb_id = NULL WHERE relay_id = ?', [$id]);
        q('UPDATE relay_networks SET relay_fb_id = NULL WHERE relay_fb_id = ?', [$id]);
        q('UPDATE hosts SET relay_on = 0 WHERE id = ?', [$id]);
        ActionLog::add($id, 'rzsense', 'agent', t('Geen relay meer'), (string) $name, true, '', null, $by);
        return true;
    }

    /** Alles voor de pagina Netwerken en relays */
    public static function overview(): array
    {
        $relays = [];
        foreach (q("SELECT h.id, h.name, h.enabled, h.agent_seen, h.agent_tick, h.agent_version, h.agent_ip, h.agent_key_seen, h.relay_url, h.relay_port, h.relay_of, h.relay_kind,
                           h.agent_update_status, s.name AS of_name, h.relay_pull, h.relay_pull_url, h.relay_pull_every, h.relay_pull_pin, h.relay_pull_at, h.relay_pull_err
                    FROM hosts h LEFT JOIN hosts s ON s.id = h.relay_of WHERE h.relay_on = 1 ORDER BY h.name")->fetchAll() as $r) {
            $d = self::data((int) $r['id']);
            $stale = $r['agent_seen'] === null || strtotime((string) $r['agent_seen']) < time() - agent_stale_seconds($r['agent_tick'] !== null ? (int) $r['agent_tick'] : null);
            $relays[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'enabled' => (int) $r['enabled'], 'seen' => $r['agent_seen'], 'down' => $stale,
                'paired' => (int) $r['agent_key_seen'] === 1, 'version' => (string) $r['agent_version'], 'agent_ip' => (string) $r['agent_ip'],
                'url' => (string) $r['relay_url'], 'port' => (int) $r['relay_port'] ?: self::PORT, 'pin' => (string) ($d['pin'] ?? ''), 'ips' => (array) ($d['ips'] ?? []),
                'st' => (array) ($d['st'] ?? []), 'relay_ver' => (string) ($d['ver'] ?? ''), 'allow' => self::allowFor((int) $r['id']), 'behind' => self::behind((int) $r['id']),
                'kind' => (string) ($r['relay_kind'] ?? ($d['kind'] ?? '')), 'relay_of' => $r['relay_of'] !== null ? (int) $r['relay_of'] : null, 'of_name' => (string) ($r['of_name'] ?? ''),
                'outdated' => $r['agent_version'] && version_compare((string) $r['agent_version'], AGENT_LATEST, '<'), 'update_status' => (string) ($r['agent_update_status'] ?? ''),
                'pull' => RelayPull::status($r)];   // 1.292.0: RzSense haalt op (null = de relay belt aan)
        }
        return ['networks' => self::networks(), 'relays' => $relays, 'hosts' => self::plan(), 'port' => self::PORT, 'agent_min' => AgentCrypto::PINURL_MIN, 'latest' => AGENT_LATEST,
            'pull_port' => RelayPull::PORT, 'pull_every' => RelayPull::EVERY, 'pull_agent_min' => RelayPull::AGENT_MIN];
    }
}
