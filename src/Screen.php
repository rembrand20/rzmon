<?php
declare(strict_types=1);

/**
 * 1.337.0: de schermlink. Een pagina voor een scherm aan de muur (/screen.php?k=<geheime sleutel>) die zonder inloggen
 * laat zien hoe het ervoor staat: Monitoring, Netwerk en IDS, om de beurt. Werkt zoals de Statuspagina: standaard uit,
 * een eigen geheime link die je kunt vervangen, en alleen lezen. Omdat er servernamen op staan, werkt de link standaard
 * alleen vanaf je eigen netwerk.
 */
final class Screen
{
    public const VIEWS = ['mon', 'net', 'ids'];

    public static function on(): bool
    {
        return setting('screen_on', '0') === '1' && preg_match('/^[a-f0-9]{32}$/', (string) setting('screen_token', '')) === 1;
    }

    public static function token(): string
    {
        $t = (string) setting('screen_token', '');
        return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : self::newToken();
    }

    public static function newToken(): string
    {
        $t = bin2hex(random_bytes(16));
        save_setting('screen_token', $t);
        return $t;
    }

    /** Alleen vanaf het eigen netwerk (de standaard) */
    public static function lanOnly(): bool
    {
        return setting('screen_lan_only', '1') === '1';
    }

    /** Hoe vaak het scherm wisselt, in seconden (0 = niet wisselen) */
    public static function rotate(): int
    {
        $s = (int) setting('screen_rotate', '30');
        return $s <= 0 ? 0 : max(10, min(600, $s));
    }

    /**
     * De schermen die meedoen (Netwerk en IDS alleen als er iets te zien is)
     * @return list<string>
     */
    public static function views(): array
    {
        $v = array_values(array_intersect(self::VIEWS, array_filter(explode(',', (string) setting('screen_views', 'mon,net,ids')))));
        return $v ?: ['mon'];
    }

    /** Klopt de sleutel, staat de link aan, en mag deze bezoeker (netwerk)? */
    public static function allowed(string $key, ?bool $private = null): bool
    {
        if (!self::on() || !preg_match('/^[a-f0-9]{32}$/', $key) || !hash_equals((string) setting('screen_token', ''), $key)) {
            return false;
        }
        return !self::lanOnly() || ($private ?? is_private_client());
    }

    /** @param array<string, mixed> $in */
    public static function save(array $in): void
    {
        save_setting('screen_on', !empty($in['on']) ? '1' : '0');
        save_setting('screen_lan_only', !isset($in['lan_only']) || !empty($in['lan_only']) ? '1' : '0');
        $r = (int) ($in['rotate'] ?? 30);
        save_setting('screen_rotate', (string) ($r <= 0 ? 0 : max(10, min(600, $r))));
        $v = array_values(array_intersect(self::VIEWS, array_map('strval', (array) ($in['views'] ?? self::VIEWS))));
        save_setting('screen_views', implode(',', in_array('mon', $v, true) ? $v : ['mon', ...$v]));
        if (!empty($in['on'])) {
            self::token();
        }
    }

    /**
     * Alles voor het scherm: de stand, de servers (ergste eerst), het netwerk en het IDS
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $h = Health::current();
        $down = array_column($h['down'], 'reason', 'id');
        $hosts = [];
        $rank = ['crit' => 0, 'warn' => 1, 'unk' => 2, 'ok' => 3];
        $rows = q('SELECT h.id, h.name, h.maintenance_until,
                     (SELECT COUNT(*) FROM checks c WHERE c.host_id = h.id AND c.enabled = 1) AS n,
                     (SELECT MAX(IF(c.info_only = 1, 0, IF(c.status = 3, 0, c.status))) FROM checks c WHERE c.host_id = h.id AND c.enabled = 1) AS worst,
                     (SELECT COUNT(*) FROM checks c WHERE c.host_id = h.id AND c.enabled = 1 AND c.status = 3) AS unk
                   FROM hosts h WHERE h.enabled = 1 ORDER BY h.name')->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $st = isset($down[$id]) ? 'crit' : ((int) $r['worst'] === 2 ? 'crit' : ((int) $r['worst'] === 1 ? 'warn' : ((int) $r['n'] === 0 || (int) $r['unk'] === (int) $r['n'] ? 'unk' : 'ok')));
            $text = '';
            if (isset($down[$id])) {
                $text = t('onbereikbaar');
            } elseif ($st === 'crit' || $st === 'warn') {
                $c = q('SELECT name, last_message, problem_since FROM checks WHERE host_id = ? AND enabled = 1 AND info_only = 0 AND status IN (1, 2) ORDER BY status DESC, problem_since LIMIT 1', [$id])->fetch();
                $text = $c ? trim($c['name'] . ' · ' . mb_substr((string) $c['last_message'], 0, 70), ' ·') : '';
            } elseif ($st === 'unk') {
                $text = t('geen gegevens');
            }
            if ($r['maintenance_until'] !== null && strtotime((string) $r['maintenance_until']) > time()) {
                $text = '🔧 ' . t('onderhoud') . ($text !== '' ? ' · ' . $text : '');
                $st = $st === 'crit' ? 'warn' : $st;
            }
            $hosts[] = ['name' => (string) $r['name'], 'st' => $st, 'text' => $text];
        }
        usort($hosts, static fn($a, $b) => [$rank[$a['st']], $a['name']] <=> [$rank[$b['st']], $b['name']]);
        $n = array_count_values(array_column($hosts, 'st')) + ['crit' => 0, 'warn' => 0, 'ok' => 0, 'unk' => 0];

        $net = null;
        try {
            $s = NetSpeed::tile();
            $net = ['tiles' => $s['tiles'] ?? null, 'problems' => (int) ($s['problems'] ?? 0)];
        } catch (Throwable) {
            // geen netwerksnelheid: dat scherm doet dan niet mee
        }
        $ids = null;
        try {
            if (Ids::enabled()) {
                $ids = ['blk_today' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE created_at >= CURDATE()')->fetchColumn(),
                    'blk_now' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchColumn(),
                    'open' => (int) q("SELECT COUNT(*) FROM ids_incidents WHERE status = 'open'")->fetchColumn(),
                    'last' => array_map(static fn($r) => ['title' => (string) $r['title'], 'sev' => min(2, max(1, (int) $r['severity'])), 'at' => (string) $r['last_seen']],
                        q("SELECT title, severity, last_seen FROM ids_incidents WHERE status = 'open' ORDER BY severity DESC, last_seen DESC LIMIT 8")->fetchAll())];
            }
        } catch (Throwable) {
            $ids = null;
        }
        return ['at' => now(), 'n' => $n, 'total' => count($hosts), 'hosts' => $hosts, 'net' => $net, 'ids' => $ids,
            'rotate' => self::rotate(), 'views' => self::views()];
    }
}
