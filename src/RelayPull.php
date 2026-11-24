<?php
declare(strict_types=1);

/**
 * 1.292.0: RzSense haalt op bij de relay (bijvoorbeeld in een DMZ). De relay belt nooit zelf naar RzSense:
 *
 *   agent ──▶ relay (bewaart)  ◀── RzSense haalt op (elke 15 s, instelbaar) en legt de antwoorden terug
 *
 * - De relay bewaart elk verzoek van een agent (versleuteld van agent tot RzSense; de relay kan het niet lezen) en geeft
 *   de agent het antwoord dat RzSense de vorige keer klaarlegde. Een antwoord komt dus één ronde later aan.
 * - RzSense haalt de verzoeken op (POST /rz/pull), verwerkt ze precies zoals een gewoon verzoek (cron/relay-item.php,
 *   dezelfde code als api.php) en legt de antwoorden terug (POST /rz/push). Wat niet aankwam, blijft in relay_outbox.
 * - Elk verzoek naar de relay en elk antwoord is ondertekend (HMAC-SHA256) met een sleutel die alleen RzSense en de relay
 *   kennen (relay_pull_key, in het installatiecommando). Het antwoord is gebonden aan het verzoek (nonce). Het certificaat
 *   van de relay wordt vastgezet; verandert het (vernieuwd), dan alleen overnemen als de handtekening klopt.
 * - De relay zet bij elk verzoek het IP-adres van de agent en het moment van ontvangst. Een verzoek mag dan tot een dag
 *   oud zijn (RzSense haalde een tijd niet op); een ouder rapport met een nieuwer erachter telt alleen voor de metingen.
 * - De relay levert zelf de agent, het installatiescript en hun handtekeningen uit aan de agents in zijn netwerk: RzSense
 *   stuurt ze mee als ze veranderd zijn.
 */
final class RelayPull
{
    public const PORT = 8445;
    public const EVERY = 15;
    /** Zo oud mag een verzoek bij de relay worden (seconden) */
    public const MAX_AGE = 86400;
    /** Agent die antwoorden van een eerdere ronde aanneemt */
    public const AGENT_MIN = '1.114.0';
    /** Acties die via de relay naar RzSense gaan (de rest levert de relay zelf uit) */
    public const ACTIONS = ['agent', 'agent_check', 'agent_tests', 'agent_pair', 'agent_enroll', 'agent_logs', 'agent_pkg_progress'];
    /** Kopteksten van de agent die meegaan (zoals relay.py ze doorstuurt) */
    public const HEADERS = ['Content-Type', 'X-Agent-Token', 'X-Agent-Key', 'X-Agent-Auth', 'X-Agent-Enc', 'X-Agent-Pair', 'X-Agent-Fallback', 'X-Agent-Pin-Fail'];
    private const MAX_ITEMS = 60;
    private const WORKERS = 4;
    /** Zo lang mag één keer ophalen verwerken (seconden); de rest komt de volgende keer */
    private const BUDGET = 40;
    private const PUSH_MAX = 12 * 1024 * 1024;

    private static ?bool $any = null;

    /** Is er een relay waar RzSense ophaalt? */
    public static function any(): bool
    {
        if (self::$any === null) {
            try {
                self::$any = (bool) q('SELECT 1 FROM hosts WHERE relay_on = 1 AND relay_pull = 1 AND enabled = 1 LIMIT 1')->fetchColumn();
            } catch (Throwable) {
                self::$any = false;
            }
        }
        return self::$any;
    }

    public static function newKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Handtekening van een verzoek van RzSense aan de relay (gelijk aan relay.py) */
    public static function sign(string $key, string $ts, string $nonce, string $path, string $body): string
    {
        return hash_hmac('sha256', "rzpull1\n$ts\n$nonce\n$path\n" . hash('sha256', $body), (string) hex2bin($key));
    }

    /** Handtekening van het antwoord van de relay, gebonden aan het verzoek */
    public static function signResp(string $key, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "rzpull1r\n$nonce\n" . hash('sha256', $body), (string) hex2bin($key));
    }

    public static function validUrl(string $u): bool
    {
        return (bool) preg_match('#^https://(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?/?$#', $u);
    }

    /** @param array<string, mixed> $h */
    public static function every(array $h): int
    {
        $s = (int) ($h['relay_pull_every'] ?? 0);
        return $s > 0 ? max(5, min(300, $s)) : self::EVERY;
    }

    /**
     * Relays waar het tijd is om op te halen.
     * @return list<array<string, mixed>>
     */
    public static function due(): array
    {
        $out = [];
        foreach (q("SELECT id, name, relay_pull_url, relay_pull_key, relay_pull_every, relay_pull_pin, relay_pull_try FROM hosts
                    WHERE relay_on = 1 AND relay_pull = 1 AND enabled = 1 AND relay_pull_url IS NOT NULL AND relay_pull_key IS NOT NULL")->fetchAll() as $h) {
            if ($h['relay_pull_try'] === null || strtotime((string) $h['relay_pull_try']) <= time() - self::every($h)) {
                $out[] = $h;
            }
        }
        return $out;
    }

    /** Seconden tot de volgende relay aan de beurt is (hooguit 5) */
    public static function nextIn(): int
    {
        $min = 5;
        foreach (q("SELECT relay_pull_every, relay_pull_try FROM hosts WHERE relay_on = 1 AND relay_pull = 1 AND enabled = 1 AND relay_pull_url IS NOT NULL")->fetchAll() as $h) {
            $min = min($min, max(1, ($h['relay_pull_try'] !== null ? strtotime((string) $h['relay_pull_try']) : 0) + self::every($h) - time()));
        }
        return $min;
    }

    // ------------------------------------------------------------------ verbinding met de relay

    /**
     * Ondertekend verzoek aan de relay. Het antwoord telt alleen als de relay het met dezelfde sleutel ondertekende.
     * @param array<string, mixed> $r de relay (hosts-rij)
     * @param array<string, mixed> $data
     * @return array{0: ?array<string, mixed>, 1: string} [antwoord, fout]
     */
    public static function call(array $r, string $path, array $data, int $timeout = 30): array
    {
        $key = (string) $r['relay_pull_key'];
        if (!preg_match('/^[0-9a-f]{64}$/', $key) || !self::validUrl((string) $r['relay_pull_url'])) {
            return [null, t('de relay heeft nog geen geldig adres of geen sleutel')];
        }
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $url = rtrim((string) $r['relay_pull_url'], '/') . $path;
        $pin = (string) ($r['relay_pull_pin'] ?? '');
        $once = static function (bool $pinned) use ($url, $body, $key, $ts, $nonce, $path, $pin, $timeout): array {
            $hdrs = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Rz-Pull-Ts: $ts", "X-Rz-Pull-Nonce: $nonce", 'X-Rz-Pull-Sig: ' . self::sign($key, $ts, $nonce, $path, $body), 'Expect:'],
                // Eigen certificaat van de relay: niet via een CA, maar vastgezet (zoals de agents). De sleutel bewijst wie het is.
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_CERTINFO => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$hdrs): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $hdrs[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
            ]);
            if ($pinned) {
                curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, "sha256//$pin");
            }
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            $seen = '';
            foreach ((array) curl_getinfo($ch, CURLINFO_CERTINFO) as $c) {
                if (!empty($c['Cert'])) {
                    $seen = (string) OwnCert::spkiPin((string) $c['Cert']);
                    break;
                }
            }
            return [is_string($resp) ? $resp : '', $code, $errno, $err, $hdrs, $seen];
        };
        [$resp, $code, $errno, $err, $hdrs, $seen] = $once($pin !== '');
        if ($errno === 90 && $pin !== '') {   // CURLE_SSL_PINNEDPUBKEYNOTMATCH: nieuw certificaat? Alleen met een geldige handtekening
            [$resp, $code, $errno, $err, $hdrs, $seen] = $once(false);
        }
        if ($errno !== 0) {
            return [null, t('relay niet bereikbaar: {e}', ['e' => mb_substr($err, 0, 150)])];
        }
        if (!hash_equals(self::signResp($key, $nonce, $resp), (string) ($hdrs['x-rz-pull-sig'] ?? ''))) {
            return [null, $code === 401 || $code === 403
                ? t('de relay weigert: de sleutel klopt niet (staat RELAY_PULL_KEY goed op de relay?)')
                : t('het antwoord klopt niet met de sleutel (HTTP {c}): is dit adres wel deze relay?', ['c' => $code])];
        }
        if ($seen !== '' && $seen !== $pin) {
            q('UPDATE hosts SET relay_pull_pin = ? WHERE id = ?', [$seen, $r['id']]);
            if ($pin !== '') {
                ActionLog::add((int) $r['id'], 'rzsense', 'agent', t('Certificaat van de relay vernieuwd'), t('nieuwe vingerafdruk {p}', ['p' => substr($seen, 0, 12) . '…']), true);
            }
        }
        $j = json_decode($resp, true);
        if ($code !== 200 || !is_array($j)) {
            return [null, t('relay antwoordt met HTTP {c}: {e}', ['c' => $code, 'e' => mb_substr(is_array($j) ? (string) ($j['error'] ?? '') : $resp, 0, 150)])];
        }
        return [$j, ''];
    }

    // ------------------------------------------------------------------ één keer ophalen

    /**
     * Ophalen, verwerken en de antwoorden terugleggen. @return string|null fout, of null als het lukte
     * @param array<string, mixed> $r
     */
    public static function run(array $r): ?string
    {
        $id = (int) $r['id'];
        $t0 = microtime(true);
        q('UPDATE hosts SET relay_pull_try = ? WHERE id = ?', [now(), $id]);
        q('DELETE FROM relay_outbox WHERE relay_id = ? AND created_at < NOW() - INTERVAL 1 DAY', [$id]);
        $files = self::files();
        // 1. wat de vorige keer niet aankwam eerst
        if ($err = self::push($r, [], [])) {
            return self::fail($r, $err);
        }
        [$j, $err] = self::call($r, '/rz/pull', ['max' => self::MAX_ITEMS, 'ver' => RZMON_VERSION]);
        if ($j === null) {
            return self::fail($r, $err);
        }
        $items = array_values(array_filter((array) ($j['items'] ?? []), 'is_array'));
        $pending = array_flip(array_map('strval', q('SELECT item FROM relay_outbox WHERE relay_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN)));
        $items = array_values(array_filter($items, static fn($it) => !isset($pending[(string) ($it['id'] ?? '')])));
        // 2. verwerken (zoals een gewoon verzoek), 3. antwoorden en de bestanden die de relay mist terugleggen
        $done = self::process($r, $items);
        $want = [];
        $have = is_array($j['files'] ?? null) ? $j['files'] : [];
        foreach ($files as $name => $content) {
            if (($have[$name] ?? '') !== hash('sha256', $content)) {
                $want[$name] = $content;
            }
        }
        if ($err = self::push($r, $done, $want)) {
            return self::fail($r, $err);
        }
        $q = is_array($j['q'] ?? null) ? $j['q'] : [];
        $st = ['at' => time(), 'n' => count($done), 'ms' => (int) round((microtime(true) - $t0) * 1000),
            'q' => max(0, (int) ($q['n'] ?? 0)), 'bytes' => max(0, (int) ($q['bytes'] ?? 0)), 'dropped' => max(0, (int) ($q['dropped'] ?? 0)),
            'agents' => max(0, (int) ($q['agents'] ?? 0)), 'ver' => preg_match('/^[0-9][0-9.]{0,15}$/', (string) ($j['ver'] ?? '')) ? (string) $j['ver'] : ''];
        q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'relay_pull', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
            [$id, json_encode($st), now()]);
        q('UPDATE hosts SET relay_pull_at = ?, relay_pull_err = NULL WHERE id = ?', [now(), $id]);
        return null;
    }

    /** @param array<string, mixed> $r */
    private static function fail(array $r, string $err): string
    {
        $prev = (string) q('SELECT relay_pull_err FROM hosts WHERE id = ?', [$r['id']])->fetchColumn();
        q('UPDATE hosts SET relay_pull_err = ? WHERE id = ?', [mb_substr($err, 0, 255), $r['id']]);
        if ($prev === '') {   // alleen bij de eerste fout in het logboek; "agent stil" van de relay volgt vanzelf
            ActionLog::add((int) $r['id'], 'rzsense', 'agent', t('Ophalen bij de relay mislukt'), $err, false);
        }
        return $err;
    }

    /**
     * Antwoorden (nieuwe en die nog in relay_outbox staan) en ontbrekende bestanden naar de relay. Gelukt: uit relay_outbox.
     * @param array<string, mixed> $r
     * @param list<array<string, mixed>> $done
     * @param array<string, string> $files
     */
    private static function push(array $r, array $done, array $files): ?string
    {
        $id = (int) $r['id'];
        foreach ($done as $d) {
            q('INSERT INTO relay_outbox (relay_id, item, ident, action, code, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $d['id'], $d['ident'], $d['action'], $d['code'], $d['body'], now()]);
        }
        $rows = q('SELECT id, item, ident, action, code, body FROM relay_outbox WHERE relay_id = ? ORDER BY id LIMIT 500', [$id])->fetchAll();
        if (!$rows && !$files) {
            return null;
        }
        $out = [];
        $ids = [];
        $size = 0;
        foreach ($rows as $row) {
            $size += strlen((string) $row['body']) * 4 / 3 + 200;
            if ($out && $size > self::PUSH_MAX) {
                break;
            }
            $out[] = ['id' => (string) $row['item'], 'ident' => (string) $row['ident'], 'action' => (string) $row['action'], 'code' => (int) $row['code'],
                'body' => $row['body'] !== null ? base64_encode((string) $row['body']) : null];
            $ids[] = (int) $row['id'];
        }
        [$j, $err] = self::call($r, '/rz/push', ['done' => $out, 'files' => array_map('base64_encode', $files)], 60);
        if ($j === null) {
            return $err;
        }
        if ($ids) {
            q('DELETE FROM relay_outbox WHERE relay_id = ? AND id IN (' . implode(',', $ids) . ')', [$id]);
        }
        return null;
    }

    /**
     * Wat de relay zelf uitlevert aan de agents in zijn netwerk: de agent, het installatiescript, hun handtekeningen en ping.
     * @return array<string, string>
     */
    public static function files(): array
    {
        $f = [];
        $read = static fn(string $p): ?string => is_file(SM_ROOT . '/' . $p) ? (string) file_get_contents(SM_ROOT . '/' . $p) : null;
        foreach (['agent_script' => $read('agent/rzmon-agent.sh'), 'agent_script_sig' => $read('agent/rzmon-agent.sh.sig'),
                  'agent_install' => Stealth::installScript(), 'agent_install_sig' => $read('agent/install-agent.sh.sig')] as $k => $v) {
            if ($v !== null && $v !== '') {
                $f[$k] = str_ends_with($k, '_sig') ? trim($v) : $v;
            }
        }
        $f['ping'] = (string) json_encode(['ok' => true, 'app' => 'RzMon', 'agent_version' => AGENT_LATEST, 'version' => RZMON_VERSION, 'configured' => true, 'via' => 'relay']);
        return $f;
    }

    // ------------------------------------------------------------------ verwerken

    /**
     * Is dit een geldig verzoek uit de relay? Dan [actie, verzoek voor cron/relay-item.php], anders null.
     * @param array<string, mixed> $it
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public static function job(int $relayId, array $it): ?array
    {
        $target = (string) ($it['target'] ?? '');
        if (!preg_match('/^[0-9a-f]{8,40}$/', (string) ($it['id'] ?? '')) || !preg_match('/^api\.php\?([A-Za-z0-9_=&%.-]{1,300})$/', $target, $m)) {
            return null;
        }
        parse_str($m[1], $get);
        $action = (string) ($get['action'] ?? '');
        $rts = (int) ($it['rts'] ?? 0);
        $method = (string) ($it['method'] ?? '');
        $ip = (string) ($it['ip'] ?? '');
        $body = base64_decode((string) ($it['body'] ?? ''), true);
        if (!in_array($action, self::ACTIONS, true) || !in_array($method, ['GET', 'POST'], true) || !filter_var($ip, FILTER_VALIDATE_IP)
            || $rts < time() - self::MAX_AGE || $rts > time() + 300 || $body === false) {
            return null;
        }
        $hdrs = [];
        foreach ((array) ($it['headers'] ?? []) as $k => $v) {
            if (in_array((string) $k, self::HEADERS, true) && is_string($v) && strlen($v) <= 512 && !preg_match('/[\r\n]/', $v)) {
                $hdrs[(string) $k] = $v;
            }
        }
        return [$action, ['relay' => $relayId, 'method' => $method, 'target' => $target, 'ip' => $ip, 'rts' => $rts, 'headers' => $hdrs,
            'body' => base64_encode($body), 'light' => $action === 'agent' && !empty($it['sup'])]];
    }

    /**
     * De verzoeken verwerken, elk in een eigen proces (cron/relay-item.php: dezelfde code als api.php), een paar tegelijk;
     * van één agent nooit twee tegelijk en in volgorde.
     * @param array<string, mixed> $r
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>> antwoorden voor de relay: id, ident, action, code, body (null = geen antwoord)
     */
    public static function process(array $r, array $items): array
    {
        usort($items, static fn($a, $b) => [(int) ($a['rts'] ?? 0), (string) ($a['id'] ?? '')] <=> [(int) ($b['rts'] ?? 0), (string) ($b['id'] ?? '')]);
        $done = [];
        $todo = [];
        foreach ($items as $it) {
            $ident = mb_substr(preg_replace('/[^A-Za-z0-9:._-]/', '', (string) ($it['ident'] ?? '')) ?? '', 0, 80);
            $job = self::job((int) $r['id'], $it);
            if ($job === null || $ident === '') {
                if (preg_match('/^[0-9a-f]{8,40}$/', (string) ($it['id'] ?? ''))) {
                    $done[] = ['id' => (string) $it['id'], 'ident' => $ident !== '' ? $ident : '-', 'action' => '-', 'code' => 400, 'body' => null];
                }
                continue;
            }
            $todo[] = ['id' => (string) $it['id'], 'ident' => $ident, 'action' => $job[0], 'job' => $job[1]];
        }
        $php = preg_match('/^php[\d.]*$/', basename(PHP_BINARY)) ? PHP_BINARY : '/usr/bin/php';   // onder php-fpm is PHP_BINARY niet de CLI
        $run = [];   // ident => [proc, pipes, task]
        $deadline = microtime(true) + self::BUDGET;
        while ($todo || $run) {
            foreach ($todo as $i => $task) {
                if (count($run) >= self::WORKERS || microtime(true) > $deadline) {
                    break;
                }
                if (isset($run[$task['ident']])) {
                    continue;
                }
                $p = proc_open([$php, SM_ROOT . '/cron/relay-item.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
                if (!is_resource($p)) {
                    break;
                }
                fwrite($pipes[0], (string) json_encode($task['job'], JSON_UNESCAPED_SLASHES));
                fclose($pipes[0]);
                $run[$task['ident']] = [$p, $pipes[1], $task];
                unset($todo[$i]);
            }
            if (!$run) {
                break;   // tijd op: de rest blijft bij de relay en komt de volgende keer
            }
            // Het eerste dat klaar is (stream_select), of even wachten
            $reads = array_map(static fn($x) => $x[1], $run);
            $w = $e = null;
            @stream_select($reads, $w, $e, 1);
            foreach ($run as $ident => [$p, $out, $task]) {
                if (!in_array($out, $reads, true)) {
                    continue;
                }
                $res = json_decode((string) stream_get_contents($out), true);
                fclose($out);
                proc_close($p);
                unset($run[$ident]);
                $ok = is_array($res) && isset($res['code']);
                $done[] = ['id' => $task['id'], 'ident' => $ident, 'action' => $task['action'], 'code' => $ok ? (int) $res['code'] : 500,
                    'body' => $ok ? (!empty($res['keep']) ? (string) base64_decode((string) ($res['body'] ?? ''), true) : null)
                        : (string) json_encode(['error' => t('RzSense kon dit verzoek niet verwerken')])];
            }
        }
        return $done;
    }

    // ------------------------------------------------------------------ instellen

    /**
     * Een relay waar RzSense ophaalt: adres, interval en (eerste keer) de sleutel.
     * @param array<string, mixed> $in
     */
    public static function save(int $id, array $in, string $by): ?string
    {
        $url = rtrim(trim((string) ($in['pull_url'] ?? '')), '/');
        if ($url !== '' && !self::validUrl($url)) {
            return t('Het ophaaladres begint met https:// en bevat alleen de machine en de poort, bijvoorbeeld https://10.0.5.10:8445');
        }
        $every = (int) ($in['pull_every'] ?? self::EVERY);
        if ($every < 5 || $every > 300) {
            return t('Ophalen kan elke 5 tot 300 seconden');
        }
        $h = q('SELECT relay_pull_url, relay_pull_key FROM hosts WHERE id = ? AND relay_on = 1', [$id])->fetch();
        if (!$h) {
            return t('Relay niet gevonden');
        }
        // Ander adres: het certificaat daar opnieuw vastzetten (de sleutel bewijst dat het de relay is)
        $pinReset = (string) $h['relay_pull_url'] !== $url;
        q('UPDATE hosts SET relay_pull = 1, relay_pull_url = ?, relay_pull_every = ?, relay_pull_key = COALESCE(relay_pull_key, ?)' . ($pinReset ? ', relay_pull_pin = NULL, relay_pull_err = NULL' : '') . ' WHERE id = ?',
            [$url !== '' ? $url : null, $every === self::EVERY ? null : $every, self::newKey(), $id]);
        ActionLog::add($id, 'rzsense', 'agent', t('Relay: RzSense haalt op'), ($url !== '' ? $url : t('nog geen adres')) . ' · ' . t('elke {s} s', ['s' => $every]), true, '', null, $by);
        return null;
    }

    /**
     * Status voor de pagina Netwerken en relays (zonder de sleutel).
     * @param array<string, mixed> $h hosts-rij met de relay_pull-kolommen
     * @return array<string, mixed>|null
     */
    public static function status(array $h): ?array
    {
        if (!(int) ($h['relay_pull'] ?? 0)) {
            return null;
        }
        $st = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'relay_pull'", [$h['id']])->fetchColumn(), true);
        return ['url' => $h['relay_pull_url'], 'every' => self::every($h), 'at' => $h['relay_pull_at'], 'err' => $h['relay_pull_err'],
            'pin' => $h['relay_pull_pin'], 'st' => is_array($st) ? $st : null,
            'outbox' => (int) q('SELECT COUNT(*) FROM relay_outbox WHERE relay_id = ?', [$h['id']])->fetchColumn()];
    }

    /** De bestanden van de losse relay-agent in een .tar.gz, om mee te nemen naar een machine die RzSense niet kan bereiken */
    public static function bundle(): string
    {
        $tar = '';
        foreach (self::bundleFiles() as $name => $src) {
            $data = (string) file_get_contents(SM_ROOT . '/' . $src);
            $mode = str_ends_with($name, '.sh') ? '0000755' : '0000644';
            $h = str_pad("rzsense-relay/$name", 100, "\0") . "$mode\0" . "0000000\0" . "0000000\0"
                . str_pad(decoct(strlen($data)), 11, '0', STR_PAD_LEFT) . "\0" . str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0"
                . '        ' . '0' . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
            $sum = array_sum(array_map('ord', str_split($h)));
            $h = substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
            $tar .= $h . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
        }
        return (string) gzencode($tar . str_repeat("\0", 1024), 9);
    }

    /** @return array<string, string> naam in de bundel => pad in RzSense */
    public static function bundleFiles(): array
    {
        $out = [];
        foreach (['install-relay.sh' => 'agent/install-relay.sh', 'rzmon-agent.sh' => 'agent/rzmon-agent.sh', 'relay.py' => 'relay/relay.py', 'entrypoint.sh' => 'relay/entrypoint.sh'] as $n => $src) {
            $out[$n] = $src;
            if (is_file(SM_ROOT . "/$src.sig")) {
                $out["$n.sig"] = "$src.sig";
            }
        }
        return $out;
    }

    /**
     * De sleutel en de poort, voor het installatiecommando (alleen beheerders)
     * @return array{pull_key: string, pull_port: int}|null
     */
    public static function installInfo(int $id): ?array
    {
        $h = q('SELECT relay_pull_key, relay_pull_url FROM hosts WHERE id = ? AND relay_on = 1 AND relay_pull = 1', [$id])->fetch();
        if (!$h) {
            return null;
        }
        $key = (string) $h['relay_pull_key'];
        if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
            $key = self::newKey();
            q('UPDATE hosts SET relay_pull_key = ? WHERE id = ?', [$key, $id]);
        }
        $port = (int) (parse_url((string) $h['relay_pull_url'], PHP_URL_PORT) ?: self::PORT);
        return ['pull_key' => $key, 'pull_port' => $port];
    }
}
