<?php
declare(strict_types=1);

/**
 * Verbindingscontrole per agent (1.100.0): is de verbinding met RzMon zo veilig als hij kan zijn, en staat er niets
 * overbodigs meer in de instellingen op de server? Te zien in de agenttabel, de Agent Manager, "Hoe staat het
 * ervoor?" en op de server zelf (rzmon-agent-setup --status, want de uitkomst gaat met elk antwoord mee).
 * - De agent (1.60.0+) meldt welke regels in zijn instellingen overbodig zijn (alleen de namen) en de SHA-256 van
 *   zijn script; RzMon vergelijkt die met zijn eigen agent (is het script op de server ongewijzigd?).
 * - Services uit de verouderde regel SERVICES worden service-checks in RzMon; daarna kan de regel weg.
 * - Wordt een agent onveilig (geen eigen sleutel, onversleuteld, script gewijzigd, certificaat niet gecontroleerd),
 *   dan een ⚠️-melding via de kanalen van Systeeminfo (agent_sec), en ✅ als het weer goed is.
 * - Opruimen: tokens van agents met een eigen sleutel wissen, de algemene sleutel als niemand hem meer nodig heeft,
 *   en de agents opnieuw laten installeren (met een reservekopie van de oude agent en instellingen op de server).
 */
final class AgentAudit
{
    /** Agent die meldt wat er in zijn instellingen overbodig is, en --status kent */
    public const CFG_MIN = '1.60.0';
    /** Punten waarvoor een ⚠️-melding gaat */
    private const CRITICAL = ['ownkey', 'enc', 'integrity', 'tls'];

    /** SHA-256 van de agent zoals RzMon hem levert */
    public static function agentSha(): string
    {
        static $sha = null;
        return $sha ??= (string) hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh');
    }

    /** cfg uit het laatste rapport, of null (agent ouder dan 1.60.0) */
    public static function cfg(int $hostId): ?array
    {
        $v = q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'cfg'", [$hostId])->fetchColumn();
        $d = $v ? json_decode((string) $v, true) : null;
        return is_array($d) ? $d : null;
    }

    /**
     * De controle voor één agent.
     * @return list<array{key: string, ok: ?bool, label: string, detail: string}> ok: true goed, false aandacht, null niet van toepassing
     */
    public static function items(array $h, ?array $cfg): array
    {
        $out = [];
        $add = static function (string $key, ?bool $ok, string $label, string $detail) use (&$out): void {
            $out[] = ['key' => $key, 'ok' => $ok, 'label' => $label, 'detail' => $detail];
        };
        $own = (int) ($h['agent_key_seen'] ?? 0) === 1;
        $add('ownkey', $own, t('Eigen sleutel'), $own ? t('geen token of sleutel over de lijn') : t('meldt zich met een token: koppel met een koppelcode (rzmon-agent-setup --pair …)'));
        $enc = (int) ($h['enc_seen'] ?? 0) === 1;
        $add('enc', $enc, t('Versleuteld'), $enc ? t('AES-256 + HMAC-SHA256, elk bericht uniek en 5 minuten geldig') : t('het laatste rapport kwam onversleuteld binnen'));
        $conn = (string) ($h['agent_conn'] ?? '');
        $fb = !empty($h['agent_fb_at']);
        $add('https', $conn === '' ? null : ($fb ? false : $conn !== 'http'), t('Via https'), match (true) {
            $conn === '' => t('nog niet bekend'),
            $fb => t('via het reserve-adres (werkt het gewone adres nog?)'),
            $conn === 'proxy' => t('via de reverse proxy'),
            $conn === 'https' => AgentCrypto::pinOn($h) ? t('rechtstreeks, met het certificaat van RzMon vastgezet') : t('rechtstreeks'),
            default => t('via http: de inhoud is versleuteld, maar zet de agent over op https (🌐 Adres)'),
        });
        if ($cfg !== null && (int) ($cfg['insecure'] ?? 0) === 1) {
            $add('tls', false, t('Certificaat van RzMon'), t('wordt niet gecontroleerd (INSECURE=1 zonder vingerafdruk): koppel opnieuw met een koppelcode'));
        }
        $lock = AgentCrypto::ipLockOn($h);
        $add('iplock', $lock, t('IP-slot'), $lock ? t('alleen vanaf {ip}', ['ip' => trim((string) ($h['agent_ips'] ?? '')) ?: '?']) : t('staat uit voor deze server'));
        $v = (string) ($h['agent_version'] ?? '');
        $add('version', $v === '' ? null : version_compare($v, AGENT_LATEST, '>='), t('Versie'),
            $v === '' ? t('onbekend') : (version_compare($v, AGENT_LATEST, '>=') ? $v : t('{v}, nieuwste is {latest}', ['v' => $v, 'latest' => AGENT_LATEST])));
        $days = (int) setting('agent_key_days', '30');
        if ($own) {
            $age = !empty($h['agent_key_at']) ? (int) floor((time() - strtotime((string) $h['agent_key_at'])) / 86400) : null;
            $add('keyage', $days === 0 ? null : ($age === null ? null : $age <= $days + 3), t('Sleutel vernieuwd'),
                $days === 0 ? t('vernieuwen staat uit') : ($age === null ? t('onbekend') : t('{n} dagen oud (wordt elke {d} dagen vernieuwd)', ['n' => $age, 'd' => $days])));
            $tok = !empty($h['agent_token']);
            $add('token', !$tok, t('Geen token meer'), $tok ? t('RzMon bewaart nog een token voor deze server: 🧹 Opruimen wist het') : t('alleen de eigen sleutel geldt'));
        }
        if ($cfg === null) {
            $add('config', null, t('Instellingen op de server'), t('agent {v} of nieuwer nodig', ['v' => self::CFG_MIN]));
        } else {
            $ob = array_values(array_filter((array) ($cfg['obsolete'] ?? []), 'is_string'));
            $perm = (string) ($cfg['perm'] ?? '');
            $bad = $ob || ($perm !== '' && $perm !== '600');
            $add('config', !$bad, t('Instellingen op de server'), $bad
                ? trim(($ob ? t('overbodig: {list}', ['list' => implode(', ', $ob)]) : '') . ($perm !== '' && $perm !== '600' ? ' · ' . t('rechten {p} (moet 600 zijn)', ['p' => $perm]) : ''), ' ·')
                    . ' → ' . t('🧹 Opruimen')
                : t('opgeruimd ({f})', ['f' => $cfg['conf'] ?? '?']));
            $sha = (string) ($cfg['sha'] ?? '');
            $same = $v !== '' && version_compare($v, AGENT_LATEST, '=');
            $add('integrity', $sha === '' || !$same ? null : hash_equals(self::agentSha(), $sha), t('Agent ongewijzigd'), match (true) {
                $sha === '' => t('onbekend'),
                !$same => t('vergelijken kan bij de nieuwste versie ({v})', ['v' => AGENT_LATEST]),
                hash_equals(self::agentSha(), $sha) => t('gelijk aan agent {v} van RzMon (SHA-256 {s}…)', ['v' => AGENT_LATEST, 's' => substr($sha, 0, 12)]),
                default => t('het script op de server wijkt af van agent {v} van RzMon: met de hand aangepast? ⬆ Opnieuw installeren zet het goed', ['v' => AGENT_LATEST]),
            });
            $bk = (int) ($cfg['backups'] ?? 0);
            $add('backup', $bk > 0 ? true : null, t('Reservekopie'), $bk > 0 ? t('{n} op de server, laatste: {f}', ['n' => $bk, 'f' => $cfg['backup_last'] ?? '?']) : t('komt bij de volgende update'));
        }
        return $out;
    }

    /** Regels voor rzmon-agent-setup --status */
    public static function lines(array $items): array
    {
        return array_map(static fn($i) => ($i['ok'] === true ? '✅' : ($i['ok'] === false ? '⚠️' : '·')) . ' ' . $i['label'] . ': '
            . str_replace(['"', '\\'], ["'", '/'], $i['detail']), $items);
    }

    /** [goed, aandacht] */
    public static function score(array $items): array
    {
        return [count(array_filter($items, static fn($i) => $i['ok'] === true)), count(array_filter($items, static fn($i) => $i['ok'] === false))];
    }

    /** Kolommen van hosts die de controle nodig heeft */
    public const HOST_COLS = 'id, name, mode, agent_version, agent_key_seen, agent_key_at, agent_token, enc_seen, agent_conn, agent_fb_at, agent_ips, pin_mode, ip_lock, agent_https';

    /**
     * Bij elk rapport: cfg bewaren, SERVICES omzetten, meldingen bij een wisseling. @return list<string> regels voor de agent
     * $prevState: de vorige stand van de kritieke punten (uit agent_data, vóór het opruimen van oude regels gelezen)
     */
    public static function fromAgent(array $host, array $in, PDOStatement $st, string $now, ?string $prevState): array
    {
        $hid = (int) $host['id'];
        $cfg = null;
        if (isset($in['cfg']) && is_array($in['cfg'])) {
            $c = $in['cfg'];
            $cfg = [
                'conf' => mb_substr((string) ($c['conf'] ?? ''), 0, 200),
                'perm' => preg_match('/^[0-7]{3,4}$/', (string) ($c['perm'] ?? '')) ? (string) $c['perm'] : '',
                'obsolete' => array_values(array_filter(array_map('strval', array_slice((array) ($c['obsolete'] ?? []), 0, 20)), static fn($n) => (bool) preg_match('/^[A-Za-z0-9_.]{1,40}$/', $n))),
                'services' => array_values(array_filter(array_map('strval', array_slice((array) ($c['services'] ?? []), 0, 100)), static fn($n) => (bool) preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', $n))),
                'insecure' => (int) !empty($c['insecure']),
                'sha' => preg_match('/^[0-9a-f]{64}$/', (string) ($c['sha'] ?? '')) ? (string) $c['sha'] : '',
                'backups' => max(0, (int) ($c['backups'] ?? 0)),
                'backup_last' => mb_substr((string) ($c['backup_last'] ?? ''), 0, 120),
                'sched' => preg_replace('/[^a-z]/', '', (string) ($c['sched'] ?? '')),
            ];
            $st->execute([$hid, 'cfg', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
            self::servicesToChecks($host, $cfg['services']);
        }
        $h = q('SELECT ' . self::HOST_COLS . ' FROM hosts WHERE id = ?', [$hid])->fetch() ?: $host;
        $items = self::items($h, $cfg);
        // Meldingen: alleen als de kritieke punten veranderen
        $bad = array_values(array_map(static fn($i) => $i['key'], array_filter($items, static fn($i) => $i['ok'] === false && in_array($i['key'], self::CRITICAL, true))));
        $state = implode(',', $bad);
        $st->execute([$hid, 'sec_state', $state, $now]);
        if ($prevState !== null && $prevState !== $state) {
            $labels = array_column(array_filter($items, static fn($i) => in_array($i['key'], $bad, true)), 'detail', 'label');
            if ($bad) {
                Monitor::sysInfo('agent_sec', $hid, (string) $host['name'], '⚠️ ' . t('Agent {name} verbindt niet veilig', ['name' => $host['name']]),
                    fn() => implode("\n", array_map(static fn($l, $d) => "$l: $d", array_keys($labels), $labels)));
            } else {
                Monitor::sysInfo('agent_sec', $hid, (string) $host['name'], '✅ ' . t('Agent {name} verbindt weer veilig', ['name' => $host['name']]),
                    fn() => t('Alle punten van de verbindingscontrole zijn weer in orde.'));
            }
        }
        return self::lines($items);
    }

    /** Services uit de verouderde regel SERVICES: een service-check in RzMon als die er nog niet is */
    private static function servicesToChecks(array $host, array $services): void
    {
        if (!$services) {
            return;
        }
        $have = array_flip(q("SELECT target FROM checks WHERE host_id = ? AND type = 'service'", [(int) $host['id']])->fetchAll(PDO::FETCH_COLUMN));
        foreach ($services as $s) {
            if (isset($have[$s])) {
                continue;
            }
            $id = Templates::insertCheck((int) $host['id'], ['type' => 'service', 'target' => $s, 'name' => $s, 'interval_sec' => 60, 'enabled' => 1, 'verify_ssl' => 1,
                'options' => json_encode(['deep' => true])]);
            ChangeLog::record('check', $id, 'create', t('Service-check {s} op {host} (uit SERVICES op de server)', ['s' => $s, 'host' => $host['name']]), null, ChangeLog::checkRow($id));
        }
    }

    /** Overzicht voor de agenttabel en "Hoe staat het ervoor?": per server de controle */
    public static function all(): array
    {
        $out = [];
        foreach (q('SELECT ' . self::HOST_COLS . " FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL ORDER BY name")->fetchAll() as $h) {
            $items = self::items($h, self::cfg((int) $h['id']));
            [$good, $bad] = self::score($items);
            $badItems = array_filter($items, static fn($i) => $i['ok'] === false);
            $out[(int) $h['id']] = ['name' => $h['name'], 'good' => $good, 'bad' => $bad,
                'issues' => array_values(array_map(static fn($i) => $i['label'], $badItems)),
                // wat 🧹 Opruimen oplost (opnieuw installeren, token wissen); https heeft een eigen punt en knop
                'fixable' => (bool) array_intersect(array_column($badItems, 'key'), ['config', 'token', 'integrity', 'version'])];
        }
        return $out;
    }

    /**
     * 🧹 Opruimen (op jouw verzoek): tokens van agents met een eigen sleutel wissen, de algemene sleutel als geen agent hem
     * nog nodig heeft, "alleen agents met een eigen sleutel" aan als ze die allemaal hebben, en de agents opnieuw laten
     * installeren (reservekopie + opgeruimde instellingen op de server). $hostId: één server, of null voor alle.
     * @return array{tokens: int, global_key: bool, ownkey_only: bool, reinstall: int}
     */
    public static function cleanup(?int $hostId): array
    {
        $where = "mode = 'agent'" . ($hostId ? ' AND id = ' . $hostId : '');
        $tokens = q("UPDATE hosts SET agent_token = NULL WHERE $where AND agent_key_seen = 1 AND agent_token IS NOT NULL")->rowCount();
        $res = ['tokens' => $tokens, 'global_key' => false, 'ownkey_only' => false, 'reinstall' => 0];
        $without = (int) q("SELECT COUNT(*) FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_key_seen <> 1")->fetchColumn();
        if ($hostId === null && $without === 0) {
            if (AgentCrypto::key() !== '' || setting('enc_key_old', '') !== '') {
                save_setting('enc_key', '');
                save_setting('enc_key_old', '');
                save_setting('enc_key_old_until', '');
                $res['global_key'] = true;
            }
            if (setting('agent_ownkey_only', '0') !== '1') {
                save_setting('agent_ownkey_only', '1');
                $res['ownkey_only'] = true;
            }
        }
        // Opnieuw installeren: alleen agents die zichzelf kunnen bijwerken
        foreach (q("SELECT id FROM hosts WHERE $where AND enabled = 1 AND agent_update_via IN ('systemd', 'direct')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            q("UPDATE hosts SET agent_update_req = ?, agent_update_sent = NULL, agent_update_status = ? WHERE id = ?",
                [now(), '⏳ ' . t('Opruimen gevraagd: de agent installeert zichzelf opnieuw (met reservekopie) bij zijn volgende rapport'), $id]);
            $res['reinstall']++;
        }
        return $res;
    }
}
