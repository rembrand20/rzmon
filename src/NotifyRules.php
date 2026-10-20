<?php
declare(strict_types=1);

/**
 * Wanneer en waarheen meldingen gaan, per status: warning, critical, onbekend en hersteld.
 * Per status: nooit, direct, of pas na X minuten onafgebroken in die status (hersteld: X minuten OK),
 * een herhaling elke Y minuten (0 = nooit), en de kanalen (Pushover, e-mail, Home Assistant-webhook).
 * Algemeen in de instellingen (notify_rules, notify_channels); per check te vervangen (checks.notify).
 */
final class NotifyRules
{
    public const KEYS = [STATUS_WARN => 'warn', STATUS_CRIT => 'crit', STATUS_UNKNOWN => 'unknown', STATUS_OK => 'recovery'];
    public const CHANNELS = ['pushover', 'email', 'webhook'];

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
            'own' => true,
        ];
    }

    public static function global(): array
    {
        return [
            'rules' => array_replace(self::defaults()['rules'], self::cleanRules((array) json_decode((string) setting('notify_rules', ''), true))),
            'channels' => array_replace(self::defaults()['channels'], self::cleanChannels((array) json_decode((string) setting('notify_channels', ''), true))),
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
            ],
            'channels' => array_fill_keys(array_values(self::KEYS), $on),
        ];
    }

    /** Alleen geldige waarden overhouden (invoer uit de browser of de database). */
    public static function cleanRules(array $in): array
    {
        $out = [];
        foreach (array_values(self::KEYS) as $k) {
            if (!isset($in[$k]) || !is_array($in[$k])) {
                continue;
            }
            $r = $in[$k];
            $mode = in_array($r['mode'] ?? '', ['never', 'now', 'after'], true) ? $r['mode'] : 'now';
            $out[$k] = [
                'mode' => $mode,
                'min' => $mode === 'after' ? max(1, min(10080, (int) ($r['min'] ?? 1))) : 0,
                'repeat' => $k === 'recovery' ? 0 : max(0, min(10080, (int) ($r['repeat'] ?? 0))),
            ];
        }
        return $out;
    }

    public static function cleanChannels(array $in): array
    {
        $out = [];
        foreach (array_values(self::KEYS) as $k) {
            if (isset($in[$k]) && is_array($in[$k])) {
                foreach (self::CHANNELS as $c) {
                    $out[$k][$c] = !empty($in[$k][$c]);
                }
            }
        }
        return $out;
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
