<?php
declare(strict_types=1);

/**
 * 1.323.0: Aan de slag. Wat de wizard en de checklist op Status en functies nodig hebben dat niet al in settings_health
 * zit: het gekozen profiel, of er een server met een werkende agent is, en of er een e-mail als proef is verstuurd.
 * De wizard geeft alleen advies: hier wordt niets aangezet, alleen bijgehouden hoe ver je bent.
 */
final class Onboard
{
    public const PROFILES = ['thuis', 'homelab', 'bedrijf'];

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $first = q("SELECT h.id, h.name, (SELECT COUNT(*) FROM checks c WHERE c.host_id = h.id AND c.enabled = 1) AS n
                      FROM hosts h WHERE h.mode = 'agent' AND h.agent_seen IS NOT NULL ORDER BY h.agent_seen LIMIT 1")->fetch();
        $agents = (int) q("SELECT COUNT(*) FROM hosts WHERE mode = 'agent' AND agent_seen IS NOT NULL")->fetchColumn();
        $hosts = (int) q("SELECT COUNT(*) FROM hosts WHERE mode <> 'local'")->fetchColumn();   // RzSense zelf telt niet mee
        $mail = setting('email_enabled', '0') === '1' && setting('smtp_host', '') !== '' && setting('mail_to', '') !== '';
        $channel = $mail || Push::names() !== '';
        $profile = setting('onboard_profile', '');
        return [
            'profile' => in_array($profile, self::PROFILES, true) ? $profile : '',
            'done' => setting('onboard_done', '0') === '1',
            // Een nieuwe installatie: nog geen servers en nog geen meldkanaal. Alleen dan opent de wizard vanzelf.
            'fresh' => $hosts === 0 && !$channel && setting('onboard_done', '0') !== '1',
            'agents' => $agents,
            'first' => $first ? ['name' => (string) $first['name'], 'checks' => (int) $first['n']] : null,
            'mail' => $mail,
            'mail_tested' => $mail && setting('onboard_mail_ok', '0') === '1',
        ];
    }

    /** Een geslaagde proefmail onthouden (vinkje bij "E-mail instellen en een proefmelding sturen") */
    public static function mailTested(): void
    {
        if (setting('onboard_mail_ok', '0') !== '1') {
            save_setting('onboard_mail_ok', '1');
        }
    }
}
