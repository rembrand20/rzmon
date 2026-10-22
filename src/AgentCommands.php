<?php
declare(strict_types=1);

/**
 * Commando-checks: een commando dat de agent op de server uitvoert, met een verwacht antwoord.
 *
 * Veiligheid: standaard staat het uit. Aanzetten kan algemeen (Instellingen → Agents) of per server,
 * als "nobody" (standaard) of als root; aanzetten vraagt om je wachtwoord en komt in het logboek.
 * Op de server zelf blokkeert COMMANDS=0 in de instellingen van de agent alles, wat RzMon ook zegt.
 * RzMon vergelijkt het antwoord; de agent voert alleen uit en stuurt uitvoer en exitcode terug.
 */
final class AgentCommands
{
    public const MODES = ['off' => 'uit', 'nobody' => 'als nobody', 'root' => 'als root'];
    public const MATCHES = ['equals' => 'is gelijk aan', 'contains' => 'bevat', 'regex' => 'komt overeen met (regex)', 'exit0' => 'exitcode is 0'];
    private const RANK = ['off' => 0, 'nobody' => 1, 'root' => 2];

    /** Wat deze server mag: off, nobody of root (eigen instelling, anders de algemene). */
    public static function modeFor(array $host): string
    {
        $own = (string) ($host['commands'] ?? '');
        $mode = isset(self::RANK[$own]) ? $own : (string) setting('agent_commands', 'off');
        return isset(self::RANK[$mode]) ? $mode : 'off';
    }

    /** Wordt het ruimer (bv. uit → nobody, nobody → root)? Dan is je wachtwoord nodig. */
    public static function widens(string $from, string $to): bool
    {
        return (self::RANK[$to] ?? 0) > (self::RANK[$from] ?? 0);
    }

    /**
     * Invoer van het checkformulier controleren.
     * @return array|string de opgeschoonde instellingen, of een foutmelding
     */
    public static function clean(array $in): array|string
    {
        $cmd = trim(str_replace("\r", '', (string) ($in['cmd'] ?? '')));
        if ($cmd === '' || str_contains($cmd, "\0")) {
            return t('Vul een commando in');
        }
        if (mb_strlen($cmd) > 1000) {
            return t('Het commando is te lang (hooguit 1000 tekens)');
        }
        $match = isset(self::MATCHES[$in['match'] ?? '']) ? $in['match'] : 'equals';
        $expect = str_replace("\r", '', (string) ($in['expect'] ?? ''));
        if (mb_strlen($expect) > 1000) {
            return t('Het verwachte antwoord is te lang (hooguit 1000 tekens)');
        }
        if ($match === 'regex' && @preg_match(self::regex($expect), '') === false) {
            return t('Ongeldige regex') . ': ' . $expect;
        }
        return [
            'cmd' => $cmd,
            'expect' => $expect,
            'match' => $match,
            'timeout' => max(1, min(60, (int) ($in['timeout'] ?? 10))),
            'fail' => ($in['fail'] ?? 'crit') === 'warn' ? 'warn' : 'crit',
        ];
    }

    /** Patroon zoals ingevuld (zonder scheidingstekens) als PHP-regex. */
    private static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~u';
    }

    /** Uitkomst van de agent beoordelen. @return array{0: int, 1: string} status en bericht */
    public static function judge(array $cfg, array $r): array
    {
        $fail = $cfg['fail'] === 'warn' ? STATUS_WARN : STATUS_CRIT;
        $rc = (int) ($r['rc'] ?? -1);
        $out = (string) ($r['out'] ?? '');
        $short = static fn(string $s) => mb_strlen($s) > 120 ? mb_substr($s, 0, 117) . '…' : $s;
        $shown = $short(trim(preg_replace('/\s+/u', ' ', $out)));
        $err = trim((string) ($r['err'] ?? ''));
        if (in_array($rc, [124, 137], true)) {
            return [$fail, t('Time-out: het commando duurde langer dan {n} s', ['n' => $cfg['timeout']])];
        }
        if ($rc === 127) {
            return [$fail, t('Commando niet gevonden') . ($err !== '' ? ': ' . $short($err) : '')];
        }
        $ok = match ($cfg['match']) {
            'exit0' => $rc === 0,
            'contains' => $cfg['expect'] === '' || str_contains($out, $cfg['expect']),
            'regex' => preg_match(self::regex($cfg['expect']), $out) === 1,
            default => self::norm($out) === self::norm($cfg['expect']),
        };
        $as = ($r['as'] ?? '') === 'root' ? ' (' . t('als root') . ')' : '';
        if ($ok) {
            return [STATUS_OK, ($cfg['match'] === 'exit0' ? 'Exitcode 0' : t('Antwoord klopt')) . ($shown !== '' ? ": $shown" : '') . $as];
        }
        if ($cfg['match'] === 'exit0') {
            return [$fail, "Exitcode $rc" . ($shown !== '' ? ": $shown" : '') . ($err !== '' && $shown === '' ? ': ' . $short($err) : '')];
        }
        $want = $short(trim(preg_replace('/\s+/u', ' ', $cfg['expect'])));
        return [$fail, t('Antwoord') . ': ' . ($shown !== '' ? "\"$shown\"" : '(' . t('leeg') . ')') . ' – ' . t('verwacht') . ': ' . t(self::MATCHES[$cfg['match']]) . " \"$want\""
            . ($rc !== 0 ? " · exitcode $rc" : '') . ($err !== '' && $shown === '' ? ' · ' . $short($err) : '')];
    }

    /** Spaties en regeleindes aan begin en eind tellen niet mee. */
    private static function norm(string $s): string
    {
        return trim(str_replace("\r\n", "\n", $s));
    }

    public static function audit(string $user, string $action, string $detail): void
    {
        q('INSERT INTO audit_log (username, action, detail, ip) VALUES (?, ?, ?, ?)',
            [mb_substr($user, 0, 100), mb_substr($action, 0, 50), mb_substr($detail, 0, 1000), mb_substr(client_ip(), 0, 45)]);
    }
}
