<?php
declare(strict_types=1);

/**
 * MITRE ATT&CK (1.111.0): tactieken en technieken (rules/mitre.json, gemaakt met tools/mitre-convert.py uit de
 * officiële STIX-gegevens) en welke technieken bij een IDS-incident horen. De namen van technieken blijven Engels
 * (zoals bij MITRE); de tactieken hebben een Nederlandse naam.
 */
final class Mitre
{
    private static ?array $data = null;

    /** Nederlandse namen van de tactieken (slug => naam) */
    public const TACTIC_NL = [
        'reconnaissance' => 'Verkennen', 'resource-development' => 'Voorbereiden', 'initial-access' => 'Binnenkomen',
        'execution' => 'Uitvoeren', 'persistence' => 'Blijven', 'privilege-escalation' => 'Rechten verhogen',
        'stealth' => 'Verbergen', 'defense-impairment' => 'Beveiliging uitschakelen', 'credential-access' => 'Wachtwoorden stelen',
        'discovery' => 'Rondkijken', 'lateral-movement' => 'Verder het netwerk in', 'collection' => 'Verzamelen',
        'command-and-control' => 'Op afstand besturen', 'exfiltration' => 'Gegevens weghalen', 'impact' => 'Schade',
    ];

    /** Technieken per soort IDS-incident (van vóór de endpoint-bewaking) */
    private const TYPES = [
        'bruteforce' => ['T1110.001'], 'breakin' => ['T1110', 'T1078'], 'rootlogin' => ['T1078.003'], 'newip' => ['T1078'],
        'ssh_login' => ['T1021.004', 'T1078'], 'decoy_user' => ['T1110.001'], 'decoy_port' => ['T1046'],
        'user_new' => ['T1136.001'], 'group_add' => ['T1098'], 'suid_new' => ['T1548.001'], 'module_new' => ['T1547.006'],
        'pkg_changed' => ['T1554'], 'proc_suspicious' => ['T1059.004'], 'revshell' => ['T1059.004'], 'webshell' => ['T1505.003'],
        'outbound' => ['T1071'], 'out_country' => ['T1071'], 'out_bad' => ['T1071'], 'port_new' => ['T1571'], 'kev_port' => ['T1190'],
        'web_attack' => ['T1190'], 'web_payload' => ['T1190'], 'fim' => ['T1565.001'], 'fim_bulk' => ['T1565.001'],
    ];

    public static function data(): array
    {
        if (self::$data === null) {
            $f = SM_ROOT . '/rules/mitre.json';
            self::$data = (is_file($f) ? json_decode((string) file_get_contents($f), true) : null) ?: ['tactics' => [], 'techniques' => []];
        }
        return self::$data;
    }

    /** Tactieken in de volgorde van de matrix: [slug, id, name (Engels), nl] */
    public static function tactics(): array
    {
        return array_map(static fn($t) => $t + ['nl' => t(self::TACTIC_NL[$t['slug']] ?? $t['name'])], self::data()['tactics']);
    }

    /** @return array{name: string, tactics: string[]}|null */
    public static function tech(string $id): ?array
    {
        $all = self::data()['techniques'];
        return $all[$id] ?? (str_contains($id, '.') ? $all[strstr($id, '.', true)] ?? null : null);
    }

    /** De tactieken van een lijst technieken (in de volgorde van de matrix) */
    public static function tacticsOf(array $techs): array
    {
        $set = [];
        foreach ($techs as $id) {
            foreach (self::tech((string) $id)['tactics'] ?? [] as $s) {
                $set[$s] = true;
            }
        }
        return array_values(array_filter(array_column(self::data()['tactics'], 'slug'), static fn($s) => isset($set[$s])));
    }

    /** Technieken bij een IDS-incident; bij een gewijzigd bestand naar wat het bestand is */
    public static function forIncident(string $type, string $key): array
    {
        if ($type === 'file' || $type === 'fim') {
            return match (true) {
                str_contains($key, 'authorized_keys') => ['T1098.004'],
                str_contains($key, 'sudoers') => ['T1548.003'],
                str_contains($key, 'shadow') || str_contains($key, 'passwd') || str_contains($key, '/group') => ['T1136.001'],
                str_contains($key, 'cron') => ['T1053.003'],
                str_contains($key, 'systemd') => ['T1543.002'],
                str_contains($key, 'pam') => ['T1556.003'],
                str_contains($key, 'ld.so.preload') => ['T1574.006'],
                str_contains($key, 'sshd_config') => ['T1098'],
                $type === 'fim' => ['T1565.001'],
                default => ['T1222.002'],
            };
        }
        if (str_starts_with($type, 'hard_')) {
            return [];
        }
        return self::TYPES[$type] ?? [];
    }

    /** Link naar de techniek bij MITRE */
    public static function url(string $id): string
    {
        return 'https://attack.mitre.org/techniques/' . str_replace('.', '/', $id) . '/';
    }
}
