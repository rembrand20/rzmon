<?php
declare(strict_types=1);

/**
 * Soort apparaat (1.75.0): wat een server is (server, router, switch, access point, NAS …), los van hoe hij bewaakt
 * wordt (agent, remote, lokaal). Zelf gekozen (hosts.kind) gaat voor herkend (hosts.kind_auto: agent of SNMP);
 * zonder beide: een agent of de RzMon-server zelf is een server, de rest "overig".
 */
final class HostKind
{
    /** Soort => groep op het dashboard */
    public const KINDS = ['server' => 'server', 'router' => 'network', 'switch' => 'network', 'ap' => 'network', 'firewall' => 'network',
        'nas' => 'storage', 'ups' => 'power', 'camera' => 'camera', 'smarthome' => 'smarthome', 'printer' => 'other', 'other' => 'other'];
    public const GROUPS = ['server', 'network', 'storage', 'power', 'camera', 'smarthome', 'other'];
    /** Naam die op een camera wijst (1.76.0): voorstel als er verder niets bekend is */
    public const CAMERA_NAME = '/\b(ip)?cam(era)?s?[-_ ]?\d*\b|cctv|deurbel|doorbell/i';
    /** Extra kolom bij een SELECT op hosts: is het Home Assistant (add-on of HA-checks)? */
    public const IS_HA_SQL = "(hosts.agent_update_via = 'ha' OR EXISTS (SELECT 1 FROM checks hc WHERE hc.host_id = hosts.id AND hc.type = 'ha')) AS is_ha";

    public static function effective(array $h): string
    {
        foreach (['kind', 'kind_auto'] as $k) {
            if (!empty($h[$k]) && isset(self::KINDS[$h[$k]])) {
                return $h[$k];
            }
        }
        if (!empty($h['is_ha'])) {
            return 'smarthome';   // Home Assistant waarvan de add-on nog niet opnieuw rapporteerde
        }
        if (in_array($h['mode'] ?? '', ['agent', 'local'], true)) {
            return 'server';
        }
        return preg_match(self::CAMERA_NAME, (string) ($h['name'] ?? '')) ? 'camera' : 'other';
    }

    public static function group(array $h): string
    {
        return self::KINDS[self::effective($h)];
    }

    /** Herkende soort bewaren (de zelf gekozen soort blijft altijd voorgaan) */
    public static function setAuto(int $hostId, string $kind): void
    {
        if (isset(self::KINDS[$kind])) {
            q('UPDATE hosts SET kind_auto = ? WHERE id = ? AND (kind_auto IS NULL OR kind_auto <> ?)', [$kind, $hostId, $kind]);
        }
    }
}
