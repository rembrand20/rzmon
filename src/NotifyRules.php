<?php
declare(strict_types=1);

/**
 * Wanneer en waarheen meldingen gaan, per status: warning, critical, onbekend en hersteld, plus info
 * (gebeurtenissen die geen storing zijn, zoals een herstart of een vernieuwd certificaat: alleen uit of direct).
 * Per status: nooit, direct, of pas na X minuten onafgebroken in die status (hersteld: X minuten OK),
 * een herhaling elke Y minuten (0 = nooit), en de kanalen (Pushover, e-mail, Home Assistant-webhook).
 * Algemeen in de instellingen (notify_rules, notify_channels); per check te vervangen (checks.notify).
 */
final class NotifyRules
{
    public const KEYS = [STATUS_WARN => 'warn', STATUS_CRIT => 'crit', STATUS_UNKNOWN => 'unknown', STATUS_OK => 'recovery'];
    public const CHANNELS = ['pushover', 'email', 'webhook'];
    /** Alle regels: de statussen hierboven en info */
    public const RULE_KEYS = ['warn', 'crit', 'unknown', 'recovery', 'info'];
    /** Extra kanaal alleen bij info: de pagina Meldingen (problemen staan daar altijd) */
    public const PAGE = 'page';

    /** Instellingen voor deze check: eigen regels als die er zijn, anders de algemene. */
    public static function forCheck(array $check): array
    {
        $global = self::global();
        $own = json_decode((string) ($check['notify'] ?? ''), true);
        if (!is_array($own)) {
            return $global;
        }
        return [
            'rules' => array_replace($global['rules'], self::cleanRules((array) ($own['rules'] ?? []))),
            'channels' => array_replace($global['channels'], self::cleanChannels((array) ($own['channels'] ?? []))),
            'flap' => isset($own['flap']) && is_array($own['flap']) ? self::cleanFlap($own['flap']) : $global['flap'],
            'own' => true,
        ];
    }

    public static function global(): array
    {
        return [
            'rules' => array_replace(self::defaults()['rules'], self::cleanRules((array) json_decode((string) setting('notify_rules', ''), true))),
            'channels' => array_replace(self::defaults()['channels'], self::cleanChannels((array) json_decode((string) setting('notify_channels', ''), true))),
            'flap' => self::cleanFlap((array) (json_decode((string) setting('notify_flap', ''), true) ?: self::defaults()['flap'])),
            'own' => false,
        ];
    }

    public static function defaults(): array
    {
        $on = array_fill_keys(self::CHANNELS, true);
        return [
            'rules' => [
                'warn' => ['mode' => 'after', 'min' => 1, 'repeat' => 60],
                'crit' => ['mode' => 'after', 'min' => 1, 'repeat' => 60],
                'unknown' => ['mode' => 'after', 'min' => 1, 'repeat' => 60],
                'recovery' => ['mode' => 'now', 'min' => 0, 'repeat' => 0],
                'info' => ['mode' => 'never', 'min' => 0, 'repeat' => 0],   // standaard uit
            ],
            'channels' => array_fill_keys(self::RULE_KEYS, $on) + ['info' => $on + [self::PAGE => true]],
            // Anti-flapping: flapt bij `changes` statuswisselingen binnen `window` minuten; stabiel na `stable` minuten
            'flap' => ['on' => true, 'changes' => 4, 'window' => 30, 'stable' => 15],
        ];
    }

    public static function cleanFlap(array $in): array
    {
        return [
            'on' => !empty($in['on']),
            'changes' => max(2, min(100, (int) ($in['changes'] ?? 4))),
            'window' => max(1, min(1440, (int) ($in['window'] ?? 30))),
            'stable' => max(1, min(1440, (int) ($in['stable'] ?? 15))),
        ];
    }

    // ------------------------------------------------------------------ stille uren

    /**
     * Stille uren: vensters waarin geen meldingen uitgaan. Dag 1 = maandag … 7 = zondag; een venster over
     * middernacht (23:00–07:00) hoort bij de dag waarop het begint. Van = tot betekent de hele dag.
     */
    public static function cleanQuiet(array $in): array
    {
        $windows = [];
        foreach (array_slice((array) ($in['windows'] ?? []), 0, 10) as $w) {
            if (!is_array($w)) {
                continue;
            }
            $t = static fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : null;
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($w['days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if (($from = $t($w['from'] ?? '')) && ($to = $t($w['to'] ?? '')) && $days) {
                $windows[] = ['from' => $from, 'to' => $to, 'days' => $days];
            }
        }
        return ['on' => !empty($in['on']) && $windows !== [], 'windows' => $windows];
    }

    public static function quietGlobal(): array
    {
        return self::cleanQuiet((array) json_decode((string) setting('notify_quiet', ''), true));
    }

    /** Per check: mode global (algemeen), own (eigen vensters) of never (nooit stil). */
    public static function cleanQuietOwn(array $in): ?array
    {
        $mode = $in['mode'] ?? 'global';
        return match ($mode) {
            'never' => ['mode' => 'never'],
            'own' => ['mode' => 'own'] + self::cleanQuiet(['on' => true] + $in),
            default => null,
        };
    }

    /** De stille uren die voor deze check gelden. */
    public static function quietFor(array $check): array
    {
        $own = json_decode((string) ($check['quiet'] ?? ''), true);
        if (is_array($own) && ($own['mode'] ?? '') === 'never') {
            return ['on' => false, 'windows' => []];
        }
        if (is_array($own) && ($own['mode'] ?? '') === 'own') {
            return self::cleanQuiet(['on' => true] + $own);
        }
        return self::quietGlobal();
    }

    /** Valt dit moment in de stille uren? Geeft dan het einde (timestamp), anders null. */
    public static function quietUntil(array $quiet, ?int $now = null): ?int
    {
        if (empty($quiet['on'])) {
            return null;
        }
        $until = self::windowEnd($quiet['windows'], $now ?? time());
        // Sluit er meteen een venster op aan (bv. 23:00–07:00 en 07:00–10:00)? Dan loopt de stilte door.
        for ($i = 0; $until !== null && $i < 14 && ($next = self::windowEnd($quiet['windows'], $until)) !== null; $i++) {
            $until = $next;
        }
        return $until;
    }

    /** Einde van het venster waar $now in valt (het langste bij overlap), of null. */
    private static function windowEnd(array $windows, int $now): ?int
    {
        $until = null;
        foreach ($windows as $w) {
            // Het venster dat gisteren begon (over middernacht) en het venster dat vandaag begint
            foreach ([-1, 0] as $offset) {
                $day = date('Y-m-d', strtotime(date('Y-m-d', $now) . " $offset day"));
                if (!in_array((int) date('N', strtotime($day)), $w['days'], true)) {
                    continue;
                }
                $start = strtotime("$day {$w['from']}");
                $end = strtotime("$day {$w['to']}");
                if ($end <= $start) {
                    $end = strtotime("$day {$w['to']} +1 day");
                }
                if ($now >= $start && $now < $end) {
                    $until = max($until ?? 0, $end);
                }
            }
        }
        return $until;
    }

    /** Alleen geldige waarden overhouden (invoer uit de browser of de database). */
    public static function cleanRules(array $in): array
    {
        $out = [];
        foreach (self::RULE_KEYS as $k) {
            if (!isset($in[$k]) || !is_array($in[$k])) {
                continue;
            }
            $r = $in[$k];
            // info: alleen uit of direct (een gebeurtenis duurt niet, dus geen "na X minuten" of herhalen)
            $modes = $k === 'info' ? ['never', 'now'] : ['never', 'now', 'after'];
            $mode = in_array($r['mode'] ?? '', $modes, true) ? $r['mode'] : ($k === 'info' ? 'never' : 'now');
            $out[$k] = [
                'mode' => $mode,
                'min' => $mode === 'after' ? max(1, min(10080, (int) ($r['min'] ?? 1))) : 0,
                'repeat' => in_array($k, ['recovery', 'info'], true) ? 0 : max(0, min(10080, (int) ($r['repeat'] ?? 0))),
            ];
        }
        return $out;
    }

    public static function cleanChannels(array $in): array
    {
        $out = [];
        foreach (self::RULE_KEYS as $k) {
            if (isset($in[$k]) && is_array($in[$k])) {
                foreach ($k === 'info' ? [...self::CHANNELS, self::PAGE] : self::CHANNELS as $c) {
                    $out[$k][$c] = !empty($in[$k][$c]);
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ systeeminfo (1.59.0)

    /** Systeeminfo-meldingen (geen checks), elk met eigen kanalen en Pushover-prioriteit (Instellingen → ℹ️ Systeeminfo) */
    public const SYSINFO = ['rzmon_update', 'agent_update', 'agent_update_fail', 'agent_connect', 'login_ok', 'login_fail',
        'account_lock', 'account_unlock', 'ip_block', 'ip_unblock', 'vuln_new', 'vuln_upgrade', 'cert', 'breach', 'local_only', 'sec', 'server_login'];
    /** Tot 1.58 altijd op de meldingenpagina, ook als de rij Info dat niet zei */
    private const SYSINFO_ALWAYS_PAGE = ['agent_connect', 'login_ok', 'login_fail', 'account_lock', 'account_unlock', 'ip_block', 'ip_unblock'];

    /**
     * Zoals het tot 1.58 werkte, uit de rij Info van de meldregels: wat toen naar een kanaal ging, blijft aangevinkt.
     * ⚠️ Niet-geautoriseerd ging altijd naar Pushover (met geluid) en op de pagina.
     */
    public static function sysinfoDefaults(?string $rulesJson, ?string $channelsJson): array
    {
        $rules = array_replace(self::defaults()['rules'], self::cleanRules((array) json_decode((string) $rulesJson, true)));
        $ch = array_replace(self::defaults()['channels'], self::cleanChannels((array) json_decode((string) $channelsJson, true)))['info'];
        $on = $rules['info']['mode'] === 'now';
        $out = [];
        foreach (self::SYSINFO as $k) {
            $out[$k] = $k === 'sec'
                ? ['pushover' => true, 'email' => false, 'webhook' => false, self::PAGE => true, 'prio' => 0]
                : ($k === 'server_login'   // 1.80.0: logins op je servers van binnen; veel berichten, dus standaard alleen op de pagina
                    ? ['pushover' => false, 'email' => false, 'webhook' => false, self::PAGE => true, 'prio' => -1]
                : ['pushover' => $on && $ch['pushover'], 'email' => $on && $ch['email'], 'webhook' => $on && $ch['webhook'],
                    self::PAGE => in_array($k, self::SYSINFO_ALWAYS_PAGE, true) || ($ch[self::PAGE] ?? true), 'prio' => -1]);
        }
        return $out;
    }

    /** Netjes maken: alleen bekende meldingen, kanalen als ja/nee, prioriteit stil (-1) of normaal (0). ⚠️ staat altijd op de pagina. */
    public static function cleanSysinfo(array $in, ?array $base = null): array
    {
        $base ??= self::sysinfoDefaults(setting('notify_rules', ''), setting('notify_channels', ''));
        foreach (self::SYSINFO as $k) {
            if (isset($in[$k]) && is_array($in[$k])) {
                foreach ([...self::CHANNELS, self::PAGE] as $c) {
                    if (array_key_exists($c, $in[$k])) {
                        $base[$k][$c] = !empty($in[$k][$c]);
                    }
                }
                if (isset($in[$k]['prio'])) {
                    $base[$k]['prio'] = (int) $in[$k]['prio'] >= 0 ? 0 : -1;
                }
            }
        }
        $base['sec'][self::PAGE] = true;   // de telling per IP (één melding per uur) staat op de meldingenpagina
        return $base;
    }

    public static function sysinfo(): array
    {
        return self::cleanSysinfo((array) json_decode((string) setting('sysinfo', ''), true));
    }

    /**
     * Eenmalig (1.25.0): de oude instellingen omzetten, zodat er niets verandert.
     * "Alarm na N fouten" → na N-1 minuten (de N-de meting van een check die elke minuut draait),
     * "ook bij WARNING" uit → warning en onbekend nooit, herhalen → bij alle problemen,
     * "melden bij herstel" uit → hersteld nooit, webhook "alleen critical en herstel" → kanalen.
     */
    public static function migrate(PDO $pdo): void
    {
        $s = [];
        foreach ($pdo->query('SELECT name, value FROM settings') as $row) {
            $s[$row['name']] = $row['value'];
        }
        // 1.26.0: anti-flapping standaard aan
        $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)')->execute(['notify_flap', json_encode(self::defaults()['flap'])]);
        // 1.59.0: systeeminfo per melding; eenmalig vastleggen zoals het werkte (de rij Info geldt daarna alleen voor checks)
        $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)')->execute(['sysinfo',
            json_encode(self::sysinfoDefaults($s['notify_rules'] ?? null, $s['notify_channels'] ?? null))]);
        if (isset($s['notify_rules'])) {
            return;
        }
        $after = max(1, (int) ($s['alert_after'] ?? 2));
        $repeat = max(0, (int) ($s['repeat_minutes'] ?? 0));
        $problem = $after <= 1 ? ['mode' => 'now', 'min' => 0, 'repeat' => $repeat] : ['mode' => 'after', 'min' => $after - 1, 'repeat' => $repeat];
        $warnOn = ($s['notify_warn'] ?? '1') === '1';
        $rules = [
            'warn' => $warnOn ? $problem : ['mode' => 'never', 'min' => 0, 'repeat' => 0],
            'crit' => $problem,
            'unknown' => $problem,
            'recovery' => ($s['notify_recovery'] ?? '1') === '1' ? ['mode' => 'now', 'min' => 0, 'repeat' => 0] : ['mode' => 'never', 'min' => 0, 'repeat' => 0],
        ];
        $critOnlyHook = ($s['ha_webhook_events'] ?? 'all') === 'crit';
        $channels = [];
        foreach (array_values(self::KEYS) as $k) {
            $channels[$k] = ['pushover' => true, 'email' => true, 'webhook' => !$critOnlyHook || in_array($k, ['crit', 'recovery'], true)];
        }
        $ins = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        $ins->execute(['notify_rules', json_encode($rules)]);
        $ins->execute(['notify_channels', json_encode($channels)]);
        $ins->execute(['notify_unknown', $warnOn ? '1' : '0']);
        $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)')->execute(['notify_group_seconds', '120']);
    }
}
