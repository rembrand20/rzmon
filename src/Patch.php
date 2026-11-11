<?php
declare(strict_types=1);

/**
 * Updates installeren (patchbeheer, 1.230.0, agent 1.92.0). Bouwt voort op "pakketten bijwerken" (Vulns: dezelfde
 * opdracht, voortgang en uitkomst), met:
 * - per server wat er klaarstaat (alle updates en de beveiligingsupdates) en of een herstart nodig is;
 * - per server een keuze: met de hand (standaard), in het vaste onderhoudsvenster van die server, of elke dag om een
 *   tijd; alleen beveiligingsupdates (standaard) of alles; eerst een snapshot (standaard aan);
 * - Home Assistant via de Supervisor (Core en add-ons, altijd met een back-up vooraf; het OS niet, dat herstart alles);
 * - nooit TrueNAS (apt maakt TrueNAS kapot), de RzSense-server zelf alleen met de hand, en nooit een herstart.
 * Aan/uit: patch_on (Instellingen → Beveiliging → Updates of Overzicht → Functies), en per server (Agent Manager).
 */
final class Patch
{
    public const AGENT = '1.92.0';
    public const MODES = ['manual', 'window', 'auto'];
    public const SCOPES = ['security', 'all'];

    public static function enabled(): bool
    {
        return setting('patch_on', '1') === '1';
    }

    /** linux, ha (Home Assistant-add-on) of truenas */
    public static function kind(array $host): string
    {
        return match (true) {
            ($host['agent_update_via'] ?? '') === 'ha' => 'ha',
            Truenas::is((int) $host['id']) => 'truenas',
            default => 'linux',
        };
    }

    /** Is dit de server waar RzSense zelf op draait? Die werk je alleen met de hand bij. */
    public static function isSelf(array $host): bool
    {
        $me = array_map('strtolower', array_filter([gethostname() ?: '', ...OwnCert::serverIps()]));
        $addr = strtolower((string) ($host['address'] ?? ''));
        return in_array($addr, ['127.0.0.1', '::1', 'localhost'], true) || in_array($addr, $me, true) || in_array(strtolower((string) $host['name']), $me, true);
    }

    public static function agentOk(array $host): bool
    {
        return !empty($host['agent_version']) && version_compare((string) $host['agent_version'], self::AGENT, '>=');
    }

    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $h = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        return $h && self::kind($h) !== 'truenas' ? ["0\tpatch\t1"] : [];
    }

    /** Wat de agent meldt: de klaarstaande updates, en de uitkomst van Home Assistant bijwerken */
    public static function fromAgent(array $host, array $in, PDOStatement $st, string $now): void
    {
        $hid = (int) $host['id'];
        if (is_array($in['patch'] ?? null)) {
            $p = $in['patch'];
            $list = [];
            foreach (array_slice((array) ($p['list'] ?? []), 0, 300) as $x) {
                if (is_array($x) && isset($x[0]) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_:~@-]{0,99}$/', (string) $x[0])) {
                    $list[] = [(string) $x[0], mb_substr((string) ($x[1] ?? ''), 0, 60), (int) !empty($x[2]), mb_substr((string) ($x[3] ?? ''), 0, 80)];
                }
            }
            $st->execute([$hid, 'patch', json_encode(['at' => time(), 'mgr' => preg_replace('/[^a-z]/', '', (string) ($p['mgr'] ?? '')),
                'avail' => max(0, (int) ($p['avail'] ?? 0)), 'sec' => max(0, (int) ($p['sec'] ?? 0)), 'rb' => !empty($p['rb']), 'list' => $list],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
        }
        $r = $in['ha_upd_result'] ?? null;
        if (is_array($r)) {
            $req = json_decode((string) q('SELECT patch_ha_req FROM hosts WHERE id = ?', [$hid])->fetchColumn(), true);
            if (is_array($req) && ($r['id'] ?? '') === $req['id']) {
                q('UPDATE hosts SET patch_ha_req = NULL WHERE id = ?', [$hid]);
                $ok = trim((string) ($r['ok'] ?? ''));
                $bad = trim((string) ($r['bad'] ?? ''));
                ActionLog::add($hid, 'agent', 'patch', $bad === '' ? t('Home Assistant bijgewerkt') : t('Home Assistant bijwerken deels mislukt'),
                    ($ok !== '' ? t('gelukt: {w}', ['w' => $ok]) : '') . ($bad !== '' ? ($ok !== '' ? ' · ' : '') . t('mislukt: {w}', ['w' => $bad]) : ''), $bad === '');
                Monitor::infoHost(['info_agent' => 1] + $host, fn() => ($bad === '' ? '✔ ' . t('Home Assistant bijgewerkt: {w}', ['w' => $ok])
                    : '✘ ' . t('Home Assistant bijwerken mislukt: {w}', ['w' => $bad])), 'vuln_upgrade');
            }
        }
    }

    /** Voor het antwoord aan de agent: een openstaande opdracht voor Home Assistant (één keer meesturen) */
    public static function agentResponse(array $host): array
    {
        $req = json_decode((string) q('SELECT patch_ha_req FROM hosts WHERE id = ?', [$host['id']])->fetchColumn(), true);
        if (!is_array($req)) {
            return [];
        }
        if (!empty($req['sent'])) {
            if (strtotime((string) $req['sent']) < time() - 7200) {   // na 2 uur zonder uitkomst vervalt hij
                q('UPDATE hosts SET patch_ha_req = NULL WHERE id = ?', [$host['id']]);
                ActionLog::add((int) $host['id'], 'rzsense', 'patch', t('Home Assistant bijwerken: geen uitkomst na 2 uur'), implode(' ', (array) $req['what']), false);
            }
            return [];
        }
        $req['sent'] = now();
        q('UPDATE hosts SET patch_ha_req = ? WHERE id = ?', [json_encode($req), $host['id']]);
        return ['ha_update' => ['id' => $req['id'], 'what' => array_values((array) $req['what'])]];
    }

    private static function avail(int $hostId): array
    {
        return (array) json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'patch'", [$hostId])->fetchColumn(), true);
    }

    /** Waarom deze server nu niet bijgewerkt kan worden, of null */
    public static function blocked(array $host): ?string
    {
        $kind = self::kind($host);
        return match (true) {
            !self::enabled() => t('Updates installeren staat uit (Instellingen → Beveiliging → Updates)'),
            $kind === 'truenas' => t('TrueNAS werk je bij met een TrueNAS-update (System → Update), nooit met apt'),
            !self::agentOk($host) => t('agent {v} of nieuwer nodig', ['v' => self::AGENT]),
            $kind === 'linux' && ($why = Vulns::upgradeBlocked($host)) !== null => $why,
            default => null,
        };
    }

    /**
     * Updates laten installeren. $scope: security of all (Linux); bij Home Assistant Core en de add-ons met een update.
     * @return true|string true, of de reden waarom niet
     */
    public static function request(array $host, ?string $scope, string $by, bool $auto = false): true|string
    {
        if ($why = self::blocked($host)) {
            return $why;
        }
        if ($auto && self::isSelf($host)) {
            return t('dit is de RzSense-server zelf: alleen met de hand');
        }
        $hid = (int) $host['id'];
        $a = self::avail($hid);
        $snap = (bool) ($host['patch_snap'] ?? 1);
        if (self::kind($host) === 'ha') {
            if (q('SELECT patch_ha_req FROM hosts WHERE id = ?', [$hid])->fetchColumn()) {
                return t('Er loopt al een update');
            }
            $what = array_values(array_filter(array_column((array) ($a['list'] ?? []), 0), static fn($w) => $w === 'core' || str_starts_with((string) $w, 'addon:')));
            if (!$what) {
                return t('Er staan geen updates klaar voor Core of add-ons');
            }
            $req = ['id' => bin2hex(random_bytes(8)), 'what' => array_slice($what, 0, 50), 'by' => $by, 'at' => now(), 'sent' => null];
            q('UPDATE hosts SET patch_ha_req = ?, patch_last = ? WHERE id = ?', [json_encode($req), now(), $hid]);
            ActionLog::add($hid, $auto ? 'rzsense' : 'user', 'patch', t('Home Assistant bijwerken gevraagd'), implode(' ', $req['what']) . ' · ' . t('met back-up vooraf'), null, '', null, $auto ? null : $by);
            return true;
        }
        $scope = in_array($scope, self::SCOPES, true) ? $scope : (string) ($host['patch_scope'] ?? 'security');
        if (q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$hid])->fetchColumn()) {
            return t('Er loopt al een update');
        }
        $names = array_values(array_map(static fn($x) => (string) $x[0], array_filter((array) ($a['list'] ?? []), static fn($x) => $scope === 'all' || !empty($x[2]))));
        if ($a && !$names) {
            return $scope === 'all' ? t('Er staan geen updates klaar') : t('Er staan geen beveiligingsupdates klaar');
        }
        $req = ['id' => bin2hex(random_bytes(8)), 'pkgs' => array_slice($names, 0, 200), 'mode' => $scope, 'snap' => $snap, 'by' => $by, 'at' => now(), 'sent' => null];
        q('UPDATE hosts SET vuln_upgrade_req = ?, vuln_upgrade_status = ?, patch_last = ? WHERE id = ?',
            [json_encode($req), json_encode(['state' => 'queued', 'at' => now(), 'pkgs' => $req['pkgs'], 'mode' => $scope]), now(), $hid]);
        ActionLog::add($hid, $auto ? 'rzsense' : 'user', 'patch', $scope === 'all' ? t('Alle updates installeren gevraagd') : t('Beveiligingsupdates installeren gevraagd'),
            t('{n} pakketten', ['n' => count($names)]) . ($snap ? ' · ' . t('eerst een snapshot') : '') . ($auto ? ' · ' . t('automatisch') : ''), null, '', null, $auto ? null : $by);
        return true;
    }

    /** Uitkomst van "updates installeren" (via Vulns::upgradeResult): de snapshot en het resultaat in het logboek */
    public static function result(array $host, array $req, bool $ok, string $log): void
    {
        $hid = (int) $host['id'];
        if (preg_match('/^Snapshot gemaakt: (\S+)/m', $log, $m)) {
            ActionLog::add($hid, 'agent', 'patch', t('Snapshot gemaakt voor de updates'), $m[1], true);
        } elseif (str_contains($log, 'Geen snapshot mogelijk')) {
            ActionLog::add($hid, 'agent', 'patch', t('Geen snapshot mogelijk'), t('geen ZFS, snapper of btrfs op /; toch bijgewerkt'));
        }
        $all = ($req['mode'] ?? '') === 'all';
        ActionLog::add($hid, 'agent', 'patch', $ok ? ($all ? t('Alle updates geïnstalleerd') : t('Beveiligingsupdates geïnstalleerd')) : t('Updates installeren mislukt'),
            t('{n} pakketten', ['n' => count((array) $req['pkgs'])]) . ' · ' . t('geen herstart: doe dat zelf als het nodig is'), $ok);
    }

    /** Is het nu tijd om deze server automatisch bij te werken? */
    public static function due(array $host, ?int $ts = null): bool
    {
        $ts ??= time();
        $last = $host['patch_last'] ? strtotime((string) $host['patch_last']) : 0;
        if (($host['patch_mode'] ?? 'manual') === 'auto') {
            $at = strtotime(date('Y-m-d', $ts) . ' ' . ($host['patch_time'] ?: '03:30') . ':00');
            return $ts >= $at && $ts < $at + 3 * 3600 && $last < $at;
        }
        if (($host['patch_mode'] ?? 'manual') === 'window') {
            return Advice::windowFor(0, (int) $host['id'], $ts) !== null && $last < $ts - 20 * 3600;
        }
        return false;
    }

    /** Elke minuut: servers die automatisch (of in hun venster) aan de beurt zijn */
    public static function cron(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $n = 0;
        foreach (q("SELECT * FROM hosts WHERE mode = 'agent' AND enabled = 1 AND patch_mode IN ('window', 'auto')")->fetchAll() as $h) {
            if (!self::due($h)) {
                continue;
            }
            q('UPDATE hosts SET patch_last = ? WHERE id = ?', [now(), $h['id']]);
            $a = self::avail((int) $h['id']);
            $count = self::kind($h) === 'ha' ? count(array_filter((array) ($a['list'] ?? []), static fn($x) => ($x[0] ?? '') !== 'os'))
                : (($h['patch_scope'] ?? 'security') === 'all' ? (int) ($a['avail'] ?? 0) : (int) ($a['sec'] ?? 0));
            if (!$count) {
                continue;   // niets te doen: niet elke dag een regel in het logboek
            }
            $r = self::request($h, null, 'RzSense', true);
            if ($r === true) {
                $n++;
            } else {
                ActionLog::add((int) $h['id'], 'rzsense', 'patch', t('Automatisch bijwerken overgeslagen'), $r, false);
            }
        }
        return $n ? "Updates: $n servers bijgewerkt" : null;
    }

    /** Per server alles voor de pagina en de Agent Manager */
    public static function info(array $h): array
    {
        $a = self::avail((int) $h['id']);
        $st = json_decode((string) ($h['vuln_upgrade_status'] ?? ''), true) ?: null;
        $windows = array_values(array_filter(Advice::windows(), static fn($w) => $w['scope'] === 'host' && $w['ref_id'] === (int) $h['id']));
        return ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'kind' => self::kind($h), 'self' => self::isSelf($h), 'agent_ok' => self::agentOk($h),
            'mode' => (string) ($h['patch_mode'] ?? 'manual'), 'scope' => (string) ($h['patch_scope'] ?? 'security'), 'snap' => (bool) ($h['patch_snap'] ?? 1),
            'time' => (string) ($h['patch_time'] ?? '03:30'), 'last' => $h['patch_last'] ?? null, 'windows' => $windows,
            'avail' => (int) ($a['avail'] ?? 0), 'sec' => (int) ($a['sec'] ?? 0), 'rb' => !empty($a['rb']), 'at' => $a['at'] ?? null, 'mgr' => (string) ($a['mgr'] ?? ''),
            'list' => array_slice((array) ($a['list'] ?? []), 0, 300), 'status' => $st, 'ha_req' => (bool) ($h['patch_ha_req'] ?? null), 'blocked' => self::blocked($h)];
    }

    public static function overview(): array
    {
        return ['on' => self::enabled(), 'hosts' => array_map([self::class, 'info'], q("SELECT * FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll())];
    }

    /** Keuze per server opslaan (Agent Manager / Updates) */
    public static function save(int $hostId, array $in, string $by): array
    {
        $mode = in_array($in['mode'] ?? '', self::MODES, true) ? (string) $in['mode'] : 'manual';
        $scope = in_array($in['scope'] ?? '', self::SCOPES, true) ? (string) $in['scope'] : 'security';
        $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($in['time'] ?? '')) ? (string) $in['time'] : '03:30';
        $h = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h) {
            throw new InvalidArgumentException(t('Server niet gevonden'));
        }
        if ($mode !== 'manual' && self::isSelf($h)) {
            throw new InvalidArgumentException(t('De RzSense-server zelf werk je alleen met de hand bij: als er iets misgaat, ziet niemand het meer.'));
        }
        if ($mode !== 'manual' && self::kind($h) === 'truenas') {
            throw new InvalidArgumentException(t('TrueNAS werk je bij met een TrueNAS-update (System → Update), nooit met apt'));
        }
        $before = ['patch_mode' => $h['patch_mode'], 'patch_scope' => $h['patch_scope'], 'patch_snap' => (int) $h['patch_snap'], 'patch_time' => $h['patch_time']];
        $new = ['patch_mode' => $mode, 'patch_scope' => $scope, 'patch_snap' => !empty($in['snap']) ? 1 : 0, 'patch_time' => $time];
        q('UPDATE hosts SET patch_mode = ?, patch_scope = ?, patch_snap = ?, patch_time = ? WHERE id = ?', [...array_values($new), $hostId]);
        if ($before != $new) {
            $label = ['manual' => t('met de hand'), 'window' => t('in het onderhoudsvenster'), 'auto' => t('elke dag om {t}', ['t' => $time])][$mode];
            ActionLog::by($by, $hostId, 'patch', t('Updates ingesteld'), $label . ' · ' . ($scope === 'all' ? t('alle updates') : t('alleen beveiligingsupdates'))
                . ' · ' . t('snapshot') . ' ' . ($new['patch_snap'] ? t('aan') : t('uit')));
            ChangeLog::record('host', $hostId, 'update', (string) $h['name'] . ' (🩹 ' . t('Updates') . ')', $before, $new);
        }
        return self::info(q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch());
    }

    /**
     * 1.238.0: updates voor meerdere servers tegelijk instellen. Elke dag om een tijd met spreiding per server (zodat ze
     * nooit allemaal tegelijk bijwerken en herstarten), in hun onderhoudsvenster, of met de hand. Volgorde: Linux-servers
     * op naam, Home Assistant als laatste en altijd met snapshot. De RzSense-server zelf en TrueNAS blijven met de hand.
     * @param list<int> $ids leeg = alle servers met de agent
     * @return list<array{id: int, name: string, kind: string, ok: bool, time: ?string, snap: bool, why: string}>
     */
    public static function plan(array $ids, array $in): array
    {
        $mode = in_array($in['mode'] ?? '', self::MODES, true) ? (string) $in['mode'] : 'manual';
        $start = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($in['time'] ?? '')) ? (string) $in['time'] : '03:00';
        $step = max(0, min(120, (int) ($in['step'] ?? 20)));
        $hosts = q("SELECT * FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll();
        if ($ids) {
            $hosts = array_values(array_filter($hosts, static fn($h) => in_array((int) $h['id'], $ids, true)));
        }
        usort($hosts, static fn($a, $b) => (self::kind($a) === 'ha') <=> (self::kind($b) === 'ha') ?: strcasecmp((string) $a['name'], (string) $b['name']));
        [$hh, $mm] = array_map('intval', explode(':', $start));
        $out = [];
        $k = 0;
        foreach ($hosts as $h) {
            $kind = self::kind($h);
            $row = ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'kind' => $kind, 'ok' => true, 'time' => null, 'snap' => $kind === 'ha' || !empty($in['snap']), 'why' => ''];
            if ($mode !== 'manual' && self::isSelf($h)) {
                $row = ['ok' => false, 'why' => t('de RzSense-server zelf werk je met de hand bij')] + $row;
            } elseif ($mode !== 'manual' && $kind === 'truenas') {
                $row = ['ok' => false, 'why' => t('TrueNAS werk je bij met een TrueNAS-update')] + $row;
            } elseif ($mode === 'auto') {
                $t = ($hh * 60 + $mm + $k * $step) % 1440;
                $row['time'] = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                $k++;
            } elseif ($mode === 'window' && !array_filter(Advice::windows(), static fn($w) => $w['scope'] === 'host' && $w['ref_id'] === (int) $h['id'])) {
                $row['why'] = t('nog geen onderhoudsvenster: stel er een in, anders wordt er niet bijgewerkt');
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Het plan uitvoeren (elke server via save(), dus met logboek en terugzetten). @return list<array> het plan */
    public static function saveBulk(array $ids, array $in, string $by): array
    {
        $plan = self::plan($ids, $in);
        foreach ($plan as $p) {
            if (!$p['ok']) {
                continue;
            }
            self::save($p['id'], ['mode' => $in['mode'] ?? 'manual', 'scope' => $in['scope'] ?? 'security', 'snap' => $p['snap'],
                'time' => $p['time'] ?? (string) (q('SELECT patch_time FROM hosts WHERE id = ?', [$p['id']])->fetchColumn() ?: '03:30')], $by);
        }
        return $plan;
    }
}
