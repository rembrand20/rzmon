<?php
declare(strict_types=1);

/**
 * Zelf herstellen en de waakhond (1.107.0, agent 1.62.0).
 *
 * Zelf herstellen, per check (checks.heal):
 * - restart: een service of container die niet draait, herstart de agent zelf, vóór zijn rapport. Lukt dat, dan blijft
 *   de check OK en komt er een ℹ️-melding (Systeeminfo: heal); lukt het niet, dan gaat de gewone melding uit.
 * - cmd:<naam>: een eigen herstelcommando dat alleen óp de server staat (/etc/rzmon-agent/heal.d/<naam>, van root).
 *   Gaat de check mis, dan vraagt RzMon de agent (alleen in een versleuteld antwoord) om het uit te voeren, en wacht
 *   met melden tot de uitkomst er is (hooguit 5 minuten). Zo kan iemand die in RzMon binnenkomt geen willekeurige
 *   commando's op je servers uitvoeren: hij kan alleen kiezen uit wat daar al staat.
 * De agent doet hooguit één poging per 10 minuten en 3 per dag per check.
 *
 * Waakhond (hosts.watchdog): de agent stuurt zelf een Pushover-bericht als hij RzMon een tijd niet bereikt, of als de
 * checks in RzMon stilstaan (cron). De Pushover-gegevens gaan alleen versleuteld naar de agent.
 */
final class Heal
{
    public const WAIT = 300;   // zo lang wachten op de uitkomst van een herstelcommando voor er gemeld wordt
    public const NAME_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    /** Kan deze check met een herstart hersteld worden (service of container op een agent-server)? */
    public static function canRestart(array $check): bool
    {
        return in_array($check['type'] ?? '', ['service', 'container'], true);
    }

    /** Waarde uit het formulier netjes maken: null, restart of cmd:<naam> */
    public static function clean(array $check, mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === 'restart' && self::canRestart($check)) {
            return 'restart';
        }
        if (str_starts_with($v, 'cmd:') && preg_match(self::NAME_RE, substr($v, 4))) {
            return $v;
        }
        return null;
    }

    /** Regels voor de lijst die de agent ophaalt (agent_tests) */
    public static function testLines(int $hostId, bool $secure): array
    {
        $out = [];
        foreach (q("SELECT id, type, target, heal, maintenance_until FROM checks WHERE host_id = ? AND enabled = 1 AND heal = 'restart'", [$hostId]) as $c) {
            if ($c['maintenance_until'] && strtotime((string) $c['maintenance_until']) > time()) {
                continue;   // in onderhoud: niet zelf herstellen (misschien staat hij expres uit)
            }
            if ($c['type'] === 'service' && preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', (string) $c['target'])) {
                $out[] = implode("\t", [$c['id'], 'heal', 'svc', $c['target']]);
            } elseif ($c['type'] === 'container' && preg_match('#^(([a-z_][a-z0-9_.-]*)/)?([A-Za-z0-9][A-Za-z0-9_.-]*)$#', (string) $c['target'], $m)) {
                $out[] = implode("\t", [$c['id'], 'heal', 'ctr', ($m[2] !== '' ? $m[2] : 'root') . '/' . $m[3]]);
            }
        }
        // 🐕 Waakhond: 0 <tab> watchdog <tab> minuten <tab> Pushover-gebruiker <tab> token <tab> prioriteit (geheimen alleen versleuteld)
        $h = q('SELECT watchdog, watchdog_min FROM hosts WHERE id = ?', [$hostId])->fetch();
        if ($h && (int) $h['watchdog'] === 1) {
            $po = setting('pushover_enabled', '0') === '1' && setting('pushover_user', '') !== '' && setting('pushover_token', '') !== '';
            $out[] = implode("\t", [0, 'watchdog', max(2, min(120, (int) ($h['watchdog_min'] ?: 5))),
                $secure && $po ? (string) setting('pushover_user', '') : '-', $secure && $po ? (string) setting('pushover_token', '') : '-',
                max(-1, min(2, (int) setting('pushover_priority_crit', 1)))]);
        }
        return $out;
    }

    /**
     * Herstelcommando nodig? Voor Monitor::process bij een probleem dat gemeld zou worden: true = nog niet melden
     * (verzoek staat uit of is net gedaan), false = melden (geen commando, al geprobeerd, of te lang geen antwoord).
     */
    public static function hold(array $check): bool
    {
        $heal = (string) ($check['heal'] ?? '');
        if (!str_starts_with($heal, 'cmd:') || ($check['mode'] ?? '') !== 'agent') {
            return false;
        }
        $since = strtotime((string) ($check['problem_since'] ?: now()));
        $state = (string) ($check['heal_state'] ?? '');
        [$what, $ts] = array_pad(explode(':', $state, 2), 2, '0');
        $ts = (int) $ts;
        if ($state === '' || $ts < $since) {
            q('UPDATE checks SET heal_state = ? WHERE id = ?', ['req:' . time(), $check['id']]);   // nieuwe storing: eerst herstellen
            return true;
        }
        return in_array($what, ['req', 'sent'], true) && time() - $ts < self::WAIT;
    }

    /** Herstelcommando's die de agent nu moet uitvoeren (alleen in een versleuteld antwoord): [[id, naam], …] */
    public static function runList(int $hostId): array
    {
        $out = [];
        foreach (q("SELECT id, heal, heal_state FROM checks WHERE host_id = ? AND heal LIKE 'cmd:%' AND heal_state LIKE 'req:%'", [$hostId]) as $c) {
            $name = substr((string) $c['heal'], 4);
            if (preg_match(self::NAME_RE, $name)) {
                $out[] = [(int) $c['id'], $name];
                q('UPDATE checks SET heal_state = ? WHERE id = ?', ['sent:' . time(), $c['id']]);
            }
        }
        return $out;
    }

    /** Uit het rapport van de agent: uitkomsten van herstelpogingen, de herstelcommando's op de server, en de waakhond */
    public static function fromAgent(array $host, array $in, PDOStatement $st, string $now): void
    {
        // Welke herstelcommando's er op de server staan (om in RzMon uit te kiezen)
        if (isset($in['heal_cmds']) && is_array($in['heal_cmds'])) {
            $names = array_values(array_filter(array_slice($in['heal_cmds'], 0, 100), static fn($n) => is_string($n) && preg_match(self::NAME_RE, $n)));
            $st->execute([$host['id'], 'heal_cmds', json_encode($names), $now]);
        }
        // Al gezien (de agent stuurt opnieuw als het antwoord op zijn rapport niet aankwam): niet nog eens melden
        $seen = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'heal_seen'", [$host['id']])->fetchColumn(), true) ?: [];
        foreach (array_slice((array) ($in['heal'] ?? []), 0, 50) as $h) {
            if (!is_array($h) || !ctype_digit((string) ($h['id'] ?? '')) || (int) ($h['t'] ?? 0) <= (int) ($seen[(string) $h['id']] ?? 0)) {
                continue;
            }
            $seen[(string) $h['id']] = (int) $h['t'];
            $c = q('SELECT id, name, heal FROM checks WHERE id = ? AND host_id = ?', [(int) $h['id'], $host['id']])->fetch();
            if (!$c) {
                continue;
            }
            $ok = !empty($h['ok']);
            $msg = mb_substr((string) ($h['m'] ?? ''), 0, 200);
            if (str_starts_with((string) $c['heal'], 'cmd:')) {
                q('UPDATE checks SET heal_state = ? WHERE id = ?', ['done:' . time(), $c['id']]);
                if ($ok) {
                    q('UPDATE checks SET last_checked = NULL WHERE id = ?', [$c['id']]);   // meteen opnieuw checken
                }
            }
            $name = (string) $c['name'];
            Monitor::sysInfo('heal', (int) $host['id'], (string) $host['name'], ($ok ? '🩹 ' : '⚠️ ') . "{$host['name']} – $name",
                fn() => ($ok ? t('Zelf hersteld') : t('Zelf herstellen mislukt')) . ": $msg");
        }
        $st->execute([$host['id'], 'heal_seen', json_encode($seen), $now]);
        // 🐕 De waakhond kon RzMon een tijd niet bereiken (nu weer wel)
        if (is_array($in['watchdog'] ?? null) && ($d = $in['watchdog']) && (int) ($d['down'] ?? 0) > 0 && (int) ($d['up'] ?? 0) >= (int) $d['down']) {
            $min = max(1, (int) ceil(((int) $d['up'] - (int) $d['down']) / 60));
            $from = date('d-m H:i', (int) $d['down']);
            Monitor::sysInfo('watchdog', (int) $host['id'], (string) $host['name'], '🐕 ' . $host['name'],
                fn() => !empty($d['self'])
                    ? t('{h} was zelf offline: kon RzMon en Pushover {m} min niet bereiken (vanaf {t})', ['h' => $host['name'], 'm' => $min, 't' => $from])
                    : t('{h} kon RzMon {m} min niet bereiken (vanaf {t}){s}', ['h' => $host['name'], 'm' => $min, 't' => $from,
                        's' => !empty($d['sent']) ? ' · ' . t('de waakhond heeft het via Pushover gemeld') : '']));
        }
    }

    /** Leeftijd van de laatste cron-ronde (s), voor de waakhond */
    public static function cronAge(): int
    {
        $t = strtotime((string) setting('cron_last_run', ''));
        return $t ? max(0, time() - $t) : 999999;
    }
}
