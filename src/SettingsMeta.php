<?php

declare(strict_types=1);

/**
 * 1.234.2: welke instellingen geheim zijn, welke RzSense zelf bijhoudt en welke bij deze installatie horen. Eerst stond dit
 * op drie plekken (bootstrap, ChangeLog, Transfer), elk een beetje anders. Gebruikt door het wijzigingslogboek
 * (ChangeLog) en export/import (Transfer).
 */
final class SettingsMeta
{
    /** Geheimen: nooit tonen, niet terugzetten, niet exporteren (naast de lijst SECRET_SETTINGS) */
    private const SECRET = '/(pass|token|secret|_key$|^enc_key|community|api_key|hibp_key|smtp_pass|mqtt_pass)/i';

    /** Wat RzSense zelf bijhoudt (geen wijziging van een mens): niet in het wijzigingslogboek, niet exporteren */
    private const BOOKKEEPING = '/^(cron_|update_cache|update_auto_day|update_pinned_at|.*_state$|.*_seen$|.*_check_at$|.*_domain_at$|ipintel_|hibp_wait|hibp_last_error|hibp_domain_error|hibp_rpm|'
        . 'vuln_daily|vuln_last|vuln_kev|vuln_cache|selfcheck_|db_size_hist|task_watch|mqtt_last|mqtt_discovery|backup_last|backup_retry|backup_fail|templates_seeded|https_since|https_check|'
        . 'own_cert_|proxy_unknown|hsts_sent|cleanup_ignore|maintenance_|ids_weekly_last|report_last|agent_fb|enc_old|sec_|stealth_last|wl_last|tests_rev|.*_notified$|'
        . 'rz_own_ips|trends_sent|ai_warned|reports_last|eol_last|net_wan_cap_alert|insight_seen_init)$/';

    /** Hoort bij deze installatie (adres, certificaat, sleutels, tijdstempels): niet exporteren */
    private const LOCAL = '/^(cron_|update_|.*_state$|.*_seen$|.*_at$|.*_day$|ipintel_|hibp_(wait|last|domain_error|rpm)|vuln_(daily|last|kev|cache)|selfcheck_|db_size_hist|task_watch|'
        . 'mqtt_last|mqtt_discovery|backup_last|backup_retry|backup_fail|templates_seeded|own_cert|proxy_|hsts_|cleanup_ignore|maintenance_|agent_fb|enc_|stealth_|https_|base_url|edr_self_mark|tests_rev|.*_notified$)/';

    public static function isSecret(string $k): bool
    {
        return in_array($k, SECRET_SETTINGS, true) || (bool) preg_match(self::SECRET, $k);
    }

    public static function isBookkeeping(string $k): bool
    {
        return (bool) preg_match(self::BOOKKEEPING, $k);
    }

    /** Niet meenemen bij export/import: geheim, zelf bijgehouden of van deze installatie */
    public static function skipTransfer(string $k): bool
    {
        return self::isSecret($k) || self::isBookkeeping($k) || (bool) preg_match(self::LOCAL, $k);
    }
}
