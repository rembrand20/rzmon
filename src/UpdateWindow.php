<?php
declare(strict_types=1);

/**
 * 1.295.0: een update met dnf, yum, rpm of apt herkennen, zodat die geen aanval lijkt.
 *
 * Tijdens een update verschijnen nieuwe programma's (dracut kopieert ze bij een nieuwe kernel naar /var/tmp/dracut.…),
 * starten scriptlets van pakketten programma's, en maakt dnf nieuwe verbindingen naar de mirrors. Voor de afwijkingen
 * (Anomaly) en de ketens (Chain) zag dat eruit als "programma binnengehaald en daarna contact naar buiten", en dan
 * isoleerde RzSense de server. Nu:
 * - de tijdelijke mappen van dracut, rpm, dnf en apt tellen nooit als "nieuw bestand";
 * - programma's en verbindingen van het pakketbeheer (en wat het start) tellen tijdens een update en een kwartier erna
 *   (instelbaar) niet als afwijking;
 * - tijdens onderhoud van een server isoleert RzSense nooit automatisch.
 * De agent geeft door wanneer het pakketbeheer het laatst iets deed (ids.pkg: de logs van dnf en apt; vanaf agent
 * 1.115.0 ook "nu bezig" als dnf of apt draait).
 */
final class UpdateWindow
{
    /** Programma's van het pakketbeheer (naam van het programma of het proces) */
    public const PKG = ['dnf', 'dnf5', 'dnf-3', 'dnf-automatic', 'yum', 'rpm', 'rpmkeys', 'rpmdb', 'microdnf', 'packagekitd', 'pkcon',
        'apt', 'apt-get', 'aptitude', 'dpkg', 'unattended-upgr', 'unattended-upgrade', 'unattended-upgrades', 'zypper', 'apk', 'pacman'];
    /** Tijdelijke mappen van het pakketbeheer en dracut (nieuwe initramfs na een kernel-update) */
    private const TMP = '~^/(?:var/)?tmp/(?:dracut\.[A-Za-z0-9]{6}|rpm-tmp\.|dnf-|yum[-_]|apt[-.]|dpkg[-.]|tmp\.[A-Za-z0-9]{6,}/dracut)~';

    /** @var array<int, int> host => moment van het laatste pakketbeheer */
    private static array $at = [];

    /**
     * Uit een rapport: wanneer deed het pakketbeheer het laatst iets.
     * @param array<string, mixed> $host
     * @param array<string, mixed> $in
     */
    public static function note(array $host, array $in): void
    {
        $hid = (int) $host['id'];
        $t = max(0, (int) (is_array($in['ids'] ?? null) ? ($in['ids']['pkg'] ?? 0) : 0));
        if (!empty($in['ids']['pkg_busy'])) {
            $t = time();
        }
        $prev = self::at($hid);
        if ($t > $prev && $t > time() - 86400 && $t <= time() + 300) {
            AgentData::put((int) $hid, 'pkg_at', (string) $t);
            self::$at[$hid] = $t;
        }
    }

    public static function at(int $hid): int
    {
        if (!isset(self::$at[$hid])) {
            self::$at[$hid] = (int) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'pkg_at'", [$hid])->fetchColumn();
        }
        return self::$at[$hid];
    }

    /** Minuten na een update waarin het pakketbeheer nog als "uitgelegd" telt */
    public static function quietMinutes(): int
    {
        return max(0, min(120, (int) setting('edr_upd_quiet', '15')));
    }

    /** Is er op deze server nu een update bezig (of net klaar)? */
    public static function active(int $hid): bool
    {
        $t = self::at($hid);
        return $t > 0 && time() - $t <= self::quietMinutes() * 60 + 120;
    }

    /**
     * Komt deze gebeurtenis van het pakketbeheer (het programma zelf, of iets wat het startte)?
     * @param array<string, mixed> $ev
     */
    public static function isPkgEvent(array $ev): bool
    {
        foreach ([(string) ($ev['exe'] ?? ''), (string) ($ev['comm'] ?? ''), (string) ($ev['pc'] ?? '')] as $n) {
            if ($n !== '' && in_array(strtolower(basename($n)), self::PKG, true)) {
                return true;
            }
        }
        return false;
    }

    /** Een pad in een tijdelijke map van het pakketbeheer of dracut? */
    public static function isPkgPath(string $path): bool
    {
        return preg_match(self::TMP, $path) === 1;
    }

    /** Is deze server in onderhoud (Servers → Onderhoud)? Dan nooit automatisch isoleren */
    public static function maintenance(int $hid): bool
    {
        return (bool) q('SELECT 1 FROM hosts WHERE id = ? AND maintenance_until > NOW()', [$hid])->fetchColumn();
    }
}
