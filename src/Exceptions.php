<?php
declare(strict_types=1);

/**
 * Uitzonderingen voor IDS en endpoint-bewaking (1.132.0): wat in een bepaalde situatie normaal is. Raak = geen melding,
 * niet ingrijpen en niet meetellen voor afwijkingen of ketens; wel gelogd als groene regel "toegestaan" in IDS Live.
 *
 * Een uitzondering geldt voor alle servers, een groep (soort apparaat) of één server; voor één regel (EDR: regel-id,
 * IDS: soort incident) of alle; en heeft voorwaarden die allemaal moeten kloppen (binnen een voorwaarde is één van de
 * waarden genoeg). Zonder voorwaarden kan niet. Malware (YARA), verborgen programma's (/dev/shm), lokaas, bekende
 * kwade adressen en ketens zijn nooit uit te zonderen.
 *
 * Sets: kant-en-klare groepen uitzonderingen die je met één knop toevoegt (en per regel weer uitzet). Voorstellen: wat de
 * laatste 30 dagen vaak terugkwam, met het aantal erachter, om in één keer toe te voegen.
 */
final class Exceptions
{
    /** Nooit uit te zonderen (EDR-regels en soorten IDS-incidenten) */
    public const NEVER_RULES = ['rz-yara', 'rz-yara-info', 'rz-drop-hidden'];
    public const NEVER_TYPES = ['decoy_port', 'out_bad', 'breakin', 'chain', 'web_attack'];
    /** Voorwaarden: programma, ouderproces, gebruiker, opdracht bevat, pad, IP/netwerk, poort, container, tekst bevat */
    public const KEYS = ['program', 'parent', 'user', 'cmd', 'path', 'ip', 'port', 'container', 'text'];
    public const SCOPES = ['all', 'group', 'host'];
    /** Paden die nooit als voorstel komen (met de hand kan het wel) */
    private const SENSITIVE = '~^/etc/(shadow|gshadow|passwd|group|sudoers|ssh/|pam\.d/|ld\.so\.preload)|/\.ssh/|authorized_keys|^/root/~';
    /** Voorstellen vanaf dit aantal keer staan al aangevinkt */
    public const PROPOSE_CHECKED = 20;

    /** Kant-en-klare sets: sleutel => [icoon, naam, uitleg, [regelsleutel => [naam, doel, regel, voorwaarden]]] */
    public const SETS = [
        'maint' => ['🔧', 'Systeemonderhoud', 'Pakketbeheer, kernel-updates en het vaste onderhoud van het besturingssysteem.', [
            'pkg' => ['Pakketbeheer en wat het start (dnf, rpm, apt, dpkg)', 'edr', null,
                ['parent' => ['dnf', 'dnf-automatic', 'yum', 'rpm', 'microdnf', 'apt', 'apt-get', 'dpkg', 'unattended-upgr', 'packagekitd']]],
            'pkgexe' => ['Pakketbeheer zelf', 'edr', null, ['program' => ['dnf', 'dnf-3', 'dnf-automatic', 'yum', 'rpm', 'microdnf', 'apt', 'apt-get', 'dpkg', 'unattended-upgrade']]],
            'dracut' => ['Kernel-update: nieuwe initramfs (dracut)', 'edr', 'rz-drop-elf', ['path' => ['/var/tmp/dracut.*/**', '/tmp/dracut.*/**']]],
            'kinst' => ['Kernel-update: dracut, kernel-install en grub', 'edr', null, ['parent' => ['dracut', 'kernel-install', 'grub2-mkconfig', 'grubby', 'update-initramfs']]],
            'privtmp' => ['Programma in de privé-tijdmap van een service (systemd)', 'edr', 'rz-drop-elf', ['path' => ['/var/tmp/systemd-private-*/**', '/tmp/systemd-private-*/**']]],
            'runparts' => ['cron.hourly/daily: run-parts schrijft zijn logregel (sed)', 'edr', null, ['parent' => ['run-parts'], 'program' => ['sed']]],
            'tidy' => ['Logrotatie, man-db, tmpfiles en updatedb', 'edr', null, ['parent' => ['logrotate', 'mandb', 'man-db', 'systemd-tmpfile', 'updatedb', 'fstrim']]],
        ]],
        'containers' => ['📦', 'Containers', 'Images ophalen bij container-registries (Docker Hub, GHCR, Quay …), in elk land.', [
            'pull' => ['Container-images ophalen (uitgaand, elk land)', 'ids', 'out_country', ['program' => ['podman', 'docker', 'dockerd', 'containerd', 'skopeo', 'buildah']]],
        ]],
        'plex' => ['🎬', 'Plex', 'Plex verbindt met servers van Plex en metadata-diensten over de hele wereld.', [
            'out' => ['Plex naar buiten (elk land)', 'ids', 'out_country', ['program' => ['Plex Media Serv*', 'Plex Tuner Serv*', 'Plex Media Scan*', 'Plex Transcoder*', 'Plex Script Hos*']]],
        ]],
        'backup' => ['☁️', 'Back-up naar de cloud', 'rclone, restic, borg en kopia sturen back-ups naar opslag in andere landen.', [
            'out' => ['Back-upprogramma naar buiten (elk land)', 'ids', 'out_country', ['program' => ['rclone', 'restic', 'borg', 'borgbackup', 'kopia']]],
        ]],
    ];

    /** @var array<int, array>|null actieve uitzonderingen (per verzoek één keer gelezen) */
    private static ?array $active = null;
    /** @var array<int, int> raak per uitzondering in dit verzoek (aan het eind weggeschreven) */
    private static array $hits = [];
    /** @var array<int, string> groep per server */
    private static array $groups = [];

    public static function reset(): void
    {
        self::$active = null;
        self::$groups = [];
    }

    private static function active(): array
    {
        if (self::$active === null) {
            self::$active = [];
            try {
                foreach (q('SELECT * FROM ids_exceptions WHERE enabled = 1 AND (until IS NULL OR until > NOW()) ORDER BY id')->fetchAll() as $r) {
                    $r['cond'] = json_decode((string) $r['cond'], true) ?: [];
                    self::$active[] = $r;
                }
            } catch (Throwable) {
                // tabel nog niet aangemaakt (vlak na een update)
            }
        }
        return self::$active;
    }

    private static function groupOf(int $hid): string
    {
        if (!isset(self::$groups[$hid])) {
            $h = q('SELECT name, mode, kind, kind_auto FROM hosts WHERE id = ?', [$hid])->fetch() ?: [];
            self::$groups[$hid] = $h ? HostKind::group($h) : '';
        }
        return self::$groups[$hid];
    }

    private static function inScope(array $x, int $hid): bool
    {
        return match ($x['scope']) {
            'host' => (int) $x['scope_val'] === $hid,
            'group' => (string) $x['scope_val'] === self::groupOf($hid),
            default => true,
        };
    }

    // ------------------------------------------------------------------ matchen

    /** Glob met ** (ook over /) en * (niet over /) */
    private static function glob(string $pat, string $s): bool
    {
        $re = strtr(preg_quote($pat, '~'), ['\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']);
        return (bool) preg_match('~^' . $re . '$~i', $s);
    }

    /** Kloppen alle voorwaarden met deze velden? (programma, ouder, gebruiker, opdracht, pad, ip, poort, container, tekst) */
    public static function matches(array $cond, array $f): bool
    {
        $any = false;
        foreach (self::KEYS as $k) {
            $want = array_values(array_filter(array_map('strval', (array) ($cond[$k] ?? [])), static fn($v) => $v !== ''));
            if (!$want) {
                continue;
            }
            $any = true;
            $v = (string) ($f[$k] ?? '');
            if ($v === '') {
                return false;
            }
            $ok = false;
            foreach ($want as $w) {
                $ok = match ($k) {
                    'program', 'parent', 'container' => self::glob($w, str_contains($w, '/') ? $v : basename($v)),
                    'path' => self::glob($w, $v),
                    'user' => strcasecmp($w, $v) === 0,
                    'cmd', 'text' => stripos($v, $w) !== false,
                    'ip' => @inet_pton($v) !== false && ip_in_list($v, [$w]),
                    'port' => $w === $v,
                    default => false,
                };
                if ($ok) {
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return $any;   // zonder voorwaarden nooit raak
    }

    /**
     * Endpoint-bewaking: is deze gebeurtenis een uitzondering? $rule = de regel die raak is (of null). Een uitzondering met
     * een regel geldt alleen als die regel raak is; zonder regel voor elke gebeurtenis die erop past.
     */
    public static function edr(array $host, string $kind, ?string $rule, array $ev, array $f): ?array
    {
        if ($rule !== null && in_array($rule, self::NEVER_RULES, true)) {
            return null;
        }
        $fields = [
            'program' => (string) ($f['Image'] ?? ($ev['exe'] ?? '')), 'parent' => (string) ($ev['pc'] ?? ($f['ParentImage'] ?? '')),
            'user' => (string) ($ev['u'] ?? ''), 'cmd' => (string) ($f['CommandLine'] ?? ($ev['cmd'] ?? '')),
            'path' => (string) ($f['TargetFilename'] ?? ($ev['name'] ?? '')), 'ip' => (string) ($ev['rip'] ?? ''), 'port' => (string) ($ev['rp'] ?? ''),
            'container' => (string) ($ev['name'] ?? ''), 'text' => (string) ($ev['cmd'] ?? ''),
        ];
        if ($kind === 'ctr') {
            $fields['program'] = (string) ($ev['img'] ?? '');
            $fields['path'] = '';
        }
        return self::find('edr', (int) $host['id'], $rule, $fields);
    }

    /** IDS: is dit incident een uitzondering? */
    public static function ids(array $host, string $type, string $key, string $title, string $detail, ?string $ip): ?array
    {
        if (in_array($type, self::NEVER_TYPES, true)) {
            return null;
        }
        return self::find('ids', (int) $host['id'], $type, self::idsFields($type, $key, $title, $detail, $ip));
    }

    /** Velden van een IDS-incident (uit de sleutel: programma|land, webserver|programma|opdracht, of een pad) */
    public static function idsFields(string $type, string $key, string $title, string $detail, ?string $ip): array
    {
        $p = explode('|', $key);
        $f = ['text' => trim("$title $detail"), 'ip' => (string) $ip];
        switch ($type) {
            case 'out_country':
            case 'outbound':
                $f['program'] = $p[0];
                break;
            case 'webshell':
                $f['parent'] = $p[0];
                $f['program'] = $p[1] ?? '';
                $f['cmd'] = $detail;
                break;
            case 'rootlogin':
            case 'newip':
                $f['user'] = 'root';
                break;
            default:
                if (str_starts_with($key, '/')) {
                    $f['path'] = $key;
                }
        }
        return $f;
    }

    private static function find(string $target, int $hid, ?string $rule, array $fields): ?array
    {
        foreach (self::active() as $x) {
            if ($x['target'] !== $target || !self::inScope($x, $hid)) {
                continue;
            }
            if ($x['rule'] !== null && $x['rule'] !== '' && $x['rule'] !== $rule) {
                continue;
            }
            if (self::matches($x['cond'], $fields)) {
                self::$hits[(int) $x['id']] = (self::$hits[(int) $x['id']] ?? 0) + 1;
                return $x;
            }
        }
        return null;
    }

    /** Tellers bijwerken (aan het eind van een rapport) */
    public static function flush(): void
    {
        foreach (self::$hits as $id => $n) {
            q('UPDATE ids_exceptions SET hits = hits + ?, last_hit = NOW() WHERE id = ?', [$n, $id]);
        }
        self::$hits = [];
    }

    /**
     * Een IDS-incident dat een uitzondering is: als groene regel in IDS Live (per uitzondering en server hooguit eens per
     * 10 minuten; het aantal staat bij de uitzondering)
     */
    public static function logIds(array $host, array $x, string $type, string $key, string $title, string $detail): void
    {
        $sig = mb_substr("exc{$x['id']}|$type", 0, 255);
        if (q("SELECT 1 FROM edr_events WHERE host_id = ? AND kind = 'i' AND sig = ? AND at > NOW() - INTERVAL 10 MINUTE LIMIT 1", [$host['id'], $sig])->fetchColumn()) {
            return;
        }
        q("INSERT INTO edr_events (host_id, at, kind, sig, data, rule, sev, own) VALUES (?, ?, 'i', ?, ?, NULL, 0, 'exc')",
            [$host['id'], now(), $sig, json_encode(['type' => $type, 'key' => mb_substr($key, 0, 200), 'title' => mb_substr($title, 0, 200),
                'detail' => mb_substr($detail, 0, 300), '_exc' => $x['name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }

    // ------------------------------------------------------------------ beheren

    /** Controleren en opschonen; geeft de rij voor de database, of gooit een fout met de reden */
    public static function clean(array $in): array
    {
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 100));
        if ($name === '') {
            throw new InvalidArgumentException(t('Geef de uitzondering een naam'));
        }
        $target = ($in['target'] ?? '') === 'ids' ? 'ids' : 'edr';
        $rule = trim((string) ($in['rule'] ?? ''));
        if ($rule !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $rule)) {
            throw new InvalidArgumentException(t('Ongeldige regel'));
        }
        if (in_array($rule, self::NEVER_RULES, true) || in_array($rule, self::NEVER_TYPES, true)) {
            throw new InvalidArgumentException(t('Deze regel kan niet uitgezonderd worden (malware, lokaas, bekende kwade adressen of ketens)'));
        }
        $scope = in_array($in['scope'] ?? '', self::SCOPES, true) ? $in['scope'] : 'all';
        $sv = null;
        if ($scope === 'host') {
            $sv = (string) (int) ($in['scope_val'] ?? 0);
            if (!q("SELECT 1 FROM hosts WHERE id = ?", [(int) $sv])->fetchColumn()) {
                throw new InvalidArgumentException(t('Server niet gevonden'));
            }
        } elseif ($scope === 'group') {
            $sv = (string) ($in['scope_val'] ?? '');
            if (!in_array($sv, HostKind::GROUPS, true)) {
                throw new InvalidArgumentException(t('Ongeldige groep'));
            }
        }
        $cond = [];
        foreach (self::KEYS as $k) {
            $vals = $in['cond'][$k] ?? [];
            if (is_string($vals)) {
                $vals = preg_split('/\s*[\n,]\s*/', $vals) ?: [];
            }
            $vals = array_values(array_unique(array_filter(array_map(static fn($v) => trim(mb_substr((string) $v, 0, 200)), array_slice((array) $vals, 0, 30)), static fn($v) => $v !== '')));
            foreach ($vals as $v) {
                if ($k === 'ip' && !preg_match('~^[0-9a-fA-F:.]+(/\d{1,3})?$~', $v)) {
                    throw new InvalidArgumentException(t('Ongeldig IP-adres of netwerk: {v}', ['v' => $v]));
                }
                if ($k === 'port' && !preg_match('/^\d{1,5}$/', $v)) {
                    throw new InvalidArgumentException(t('Ongeldige poort: {v}', ['v' => $v]));
                }
            }
            if ($vals) {
                $cond[$k] = $vals;
            }
        }
        if (!$cond) {
            throw new InvalidArgumentException(t('Geef minstens één voorwaarde op (programma, pad, IP-adres …): een uitzondering voor alles kan niet'));
        }
        $days = (int) ($in['days'] ?? 0);
        return ['name' => $name, 'target' => $target, 'rule' => $rule !== '' ? $rule : null, 'scope' => $scope, 'scope_val' => $sv,
            'cond' => json_encode($cond, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'until' => in_array($days, [1, 7, 30], true) ? date('Y-m-d H:i:s', time() + $days * 86400) : (array_key_exists('until', $in) && $in['until'] ? (string) $in['until'] : null),
            'reason' => trim(mb_substr((string) ($in['reason'] ?? ''), 0, 200))];
    }

    public static function save(array $in, string $by): int
    {
        $r = self::clean($in);
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            q('UPDATE ids_exceptions SET name = ?, target = ?, rule = ?, scope = ?, scope_val = ?, cond = ?, until = ?, reason = ? WHERE id = ?',
                [$r['name'], $r['target'], $r['rule'], $r['scope'], $r['scope_val'], $r['cond'], $r['until'], $r['reason'], $id]);
        } else {
            q('INSERT INTO ids_exceptions (name, target, rule, scope, scope_val, cond, until, reason, created_by, set_key, item_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$r['name'], $r['target'], $r['rule'], $r['scope'], $r['scope_val'], $r['cond'], $r['until'], $r['reason'], $by, $in['set_key'] ?? null, $in['item_key'] ?? null]);
            $id = (int) db()->lastInsertId();
        }
        AgentCommands::audit($by, $in['id'] ?? null ? 'Uitzondering gewijzigd' : 'Uitzondering toegevoegd', $r['name']);
        self::reset();
        return $id;
    }

    /** Een hele set in één keer toevoegen (wat er al van die set is, blijft zoals het is) */
    public static function addSet(string $key, string $by): int
    {
        $set = self::SETS[$key] ?? null;
        if (!$set) {
            throw new InvalidArgumentException(t('Onbekende set'));
        }
        $n = 0;
        foreach ($set[3] as $ik => [$name, $target, $rule, $cond]) {
            if (q('SELECT 1 FROM ids_exceptions WHERE set_key = ? AND item_key = ?', [$key, $ik])->fetchColumn()) {
                continue;
            }
            q('INSERT INTO ids_exceptions (name, target, rule, scope, cond, reason, created_by, set_key, item_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $target, $rule, 'all', json_encode($cond, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $set[1], $by, $key, $ik]);
            $n++;
        }
        AgentCommands::audit($by, 'Uitzonderingen: set toegevoegd', $set[1] . " ($n)");
        self::reset();
        return $n;
    }

    // ------------------------------------------------------------------ overzicht, voorstellen, export

    /** Incidenten van de laatste 30 dagen, samengevat per server, soort en sleutel (voor voorstellen en "zou raak zijn") */
    private static function recent(): array
    {
        static $rows = null;
        return $rows ??= q("SELECT i.host_id, h.name AS host, i.type, i.dkey, MAX(i.ip) AS ip, MAX(i.title) AS title, MAX(i.detail) AS detail,
                                   SUM(i.hits) AS hits, COUNT(*) AS n, MAX(i.last_seen) AS last
                            FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                            WHERE i.created_at >= NOW() - INTERVAL 30 DAY
                            GROUP BY i.host_id, i.type, i.dkey ORDER BY hits DESC LIMIT 5000")->fetchAll();
    }

    /** Velden van een samengevat incident: EDR (regel|programma<ouder of regel|pad) of IDS */
    private static function rowFields(array $r): array
    {
        if ($r['type'] === 'edr') {
            [$rule, $rest] = array_pad(explode('|', (string) $r['dkey'], 2), 2, '');
            $f = ['rule' => $rule, 'text' => $r['title'] . ' ' . $r['detail']];
            if (str_contains($rest, '<')) {
                [$f['program'], $f['parent']] = explode('<', $rest, 2);
            } elseif (str_starts_with($rest, '/')) {
                $f['path'] = $rest;
            } elseif (str_starts_with($rule, 'rz-ctr-') && preg_match('/(\S+)$/', $rest, $m)) {
                $f['container'] = $m[1];   // container: "exec gluetun"
            }
            return $f;
        }
        return self::idsFields((string) $r['type'], (string) $r['dkey'], (string) $r['title'], (string) $r['detail'], $r['ip']) + ['rule' => $r['type']];
    }

    /** Zou deze uitzondering raak zijn geweest? (aantal keer in 30 dagen, uit de incidenten) */
    private static function wouldHit(string $target, ?string $rule, array $cond, string $scope = 'all', ?string $sv = null): int
    {
        $n = 0;
        foreach (self::recent() as $r) {
            if (($r['type'] === 'edr') !== ($target === 'edr') || !self::inScope(['scope' => $scope, 'scope_val' => $sv], (int) $r['host_id'])) {
                continue;
            }
            $f = self::rowFields($r);
            if (($rule === null || $rule === '' || $rule === $f['rule']) && self::matches($cond, $f)) {
                $n += (int) $r['hits'];
            }
        }
        return $n;
    }

    /** Alles voor de pagina: eigen uitzonderingen, sets (met wat ze zouden raken) en de soorten/regels om uit te kiezen */
    public static function overview(): array
    {
        $list = array_map(static function ($r) {
            $r['cond'] = json_decode((string) $r['cond'], true) ?: [];
            $r['expired'] = $r['until'] !== null && strtotime((string) $r['until']) < time();
            return $r;
        }, q('SELECT x.*, h.name AS host_name FROM ids_exceptions x LEFT JOIN hosts h ON x.scope = \'host\' AND h.id = x.scope_val ORDER BY x.set_key IS NULL, x.set_key, x.id')->fetchAll());
        $have = [];
        foreach ($list as $r) {
            if ($r['set_key']) {
                $have[$r['set_key']][$r['item_key']] = true;
            }
        }
        $sets = [];
        foreach (self::SETS as $k => [$icon, $name, $desc, $items]) {
            $sets[] = ['key' => $k, 'icon' => $icon, 'name' => t($name), 'desc' => t($desc), 'added' => isset($have[$k]),
                'items' => array_map(static fn($ik, $it) => ['key' => $ik, 'name' => t($it[0]), 'target' => $it[1], 'rule' => $it[2], 'cond' => $it[3],
                    'would' => self::wouldHit($it[1], $it[2], $it[3]), 'added' => isset($have[$k][$ik])], array_keys($items), $items)];
        }
        return ['list' => $list, 'sets' => $sets, 'never_rules' => self::NEVER_RULES, 'never_types' => self::NEVER_TYPES,
            'hosts' => q("SELECT id, name FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll(), 'groups' => HostKind::GROUPS];
    }

    /** Pad met een willekeurig stuk (dracut.CbpkW9, systemd-private-7930…) algemener maken: /var/tmp/dracut.*\/** */
    private static function generalPath(string $p): string
    {
        $p = (string) preg_replace(['~\.[A-Za-z0-9]{6,}(?=/|$)~', '~-[0-9a-f]{12,}[^/]*~', '~/\d+(?=/|$)~'], ['.*', '-*', '/*'], $p);
        $parts = explode('/', trim($p, '/'));
        return count($parts) > 3 ? '/' . implode('/', array_slice($parts, 0, 3)) . '/**' : $p;
    }

    /**
     * Voorstellen uit de meldingen van de laatste 30 dagen: per regel/soort, programma en ouder (of pad, of adres) samengevat,
     * met het aantal keer en op hoeveel servers. Wat al uitgezonderd is, of nooit uitgezonderd kan worden, valt weg.
     */
    public static function proposals(): array
    {
        $out = [];
        foreach (self::recent() as $r) {
            $type = (string) $r['type'];
            if (in_array($type, self::NEVER_TYPES, true) || $type === 'proc_suspicious') {   // verdachte processen (memfd, /tmp): alleen met de hand
                continue;
            }
            $f = self::rowFields($r);
            $rule = $f['rule'] ?: null;
            if ($type === 'edr' && in_array($rule, self::NEVER_RULES, true)) {
                continue;
            }
            // Van RzMon of de agent zelf (van vóór 1.132.0 / agent 1.67.0; die herkennen ze nu vanzelf): geen voorstel
            $txt = $r['dkey'] . ' ' . $r['detail'];
            if (preg_match('~X-Agent-|rzmon-agent|rzmon:|/var/cache/rzmon-agent~', $txt) || str_contains($txt, SM_ROOT . '/')) {
                continue;
            }
            // Gevoelige bestanden (wachtwoorden, sudo, SSH-sleutels) nooit voorstellen: dat kan alleen met de hand
            if (!empty($f['path']) && preg_match(self::SENSITIVE, (string) $f['path'])) {
                continue;
            }
            $cond = [];
            if (!empty($f['path'])) {
                $cond['path'] = [self::generalPath($f['path'])];
            } elseif ($type === 'rootlogin' && $r['ip']) {
                $cond['ip'] = [$r['ip']];
            } else {
                foreach (['program', 'parent'] as $k) {
                    if (!empty($f[$k])) {
                        $cond[$k] = [$f[$k]];
                    }
                }
                if ($type === 'webshell' && ($w = trim((string) preg_replace('/#/', '', (string) (explode('|', (string) $r['dkey'])[2] ?? '')))) !== '') {
                    $cond['cmd'] = [$w];
                }
            }
            if (!empty($f['container'])) {
                $cond['container'] = [$f['container']];
            }
            if (!$cond) {
                continue;
            }
            $target = $type === 'edr' ? 'edr' : 'ids';
            $id = substr(sha1($target . '|' . $rule . '|' . json_encode($cond)), 0, 12);
            $o = &$out[$id];
            $title = in_array($type, ['out_country', 'outbound'], true) ? t('Uitgaand naar elk land: {p}', ['p' => $f['program'] ?? '?'])
                : ($type === 'rootlogin' ? t('Root-login via SSH vanaf {ip}', ['ip' => $r['ip']]) : (string) $r['title']);
            $o ??= ['id' => $id, 'target' => $target, 'rule' => $rule, 'cond' => $cond, 'title' => $title,
                'hits' => 0, 'n' => 0, 'hosts' => [], 'last' => ''];
            $o['hits'] += (int) $r['hits'];
            $o['n'] += (int) $r['n'];
            $o['hosts'][(int) $r['host_id']] = $r['host'];
            $o['last'] = max($o['last'], (string) $r['last']);
            unset($o);
        }
        $res = [];
        foreach ($out as $o) {
            $one = count($o['hosts']) === 1;
            $o['scope'] = $one ? 'host' : 'all';
            $o['scope_val'] = $one ? (string) array_key_first($o['hosts']) : null;
            $o['host_names'] = array_values($o['hosts']);
            $o['checked'] = $o['hits'] >= self::PROPOSE_CHECKED;
            unset($o['hosts']);
            // al gedekt door een bestaande uitzondering?
            $covered = false;
            foreach (self::active() as $x) {
                if ($x['target'] === $o['target'] && ($x['rule'] === null || $x['rule'] === $o['rule']) && ($x['scope'] === 'all' || ($x['scope'] === $o['scope'] && (string) $x['scope_val'] === (string) $o['scope_val']))
                    && self::matches($x['cond'], self::condAsFields($o['cond']))) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $res[] = $o;
            }
        }
        usort($res, static fn($a, $b) => $b['hits'] <=> $a['hits']);
        return array_slice($res, 0, 40);
    }

    /** Voorwaarden van een voorstel als velden (om te kijken of een bestaande uitzondering het al dekt) */
    private static function condAsFields(array $cond): array
    {
        $f = [];
        foreach ($cond as $k => $v) {
            $f[$k] = (string) ($v[0] ?? '');
        }
        if (isset($f['path'])) {
            $f['path'] = str_replace(['/**', '*'], ['/x', 'x'], $f['path']);
        }
        return $f;
    }

    /** Voorstellen in één keer toevoegen */
    public static function addProposals(array $ids, string $by): int
    {
        $want = array_flip(array_map('strval', $ids));
        $n = 0;
        foreach (self::proposals() as $p) {
            if (!isset($want[$p['id']])) {
                continue;
            }
            q('INSERT INTO ids_exceptions (name, target, rule, scope, scope_val, cond, reason, created_by, set_key, item_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [mb_substr($p['title'], 0, 100), $p['target'], $p['rule'], $p['scope'], $p['scope_val'], json_encode($p['cond'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    t('voorstel uit de meldingen ({n}× in 30 dagen)', ['n' => $p['hits']]), $by, 'found', $p['id']]);
            $n++;
        }
        AgentCommands::audit($by, 'Uitzonderingen: voorstellen toegevoegd', (string) $n);
        self::reset();
        return $n;
    }

    /** Exporteren (servers op naam, zodat het ook op een andere RzMon past) */
    public static function export(): array
    {
        return ['rzmon' => RZMON_VERSION, 'kind' => 'exceptions', 'exceptions' => array_map(static fn($r) => [
            'name' => $r['name'], 'target' => $r['target'], 'rule' => $r['rule'], 'scope' => $r['scope'],
            'scope_val' => $r['scope'] === 'host' ? $r['host_name'] : $r['scope_val'], 'cond' => json_decode((string) $r['cond'], true) ?: [],
            'enabled' => (bool) $r['enabled'], 'until' => $r['until'], 'reason' => $r['reason'], 'set_key' => $r['set_key'], 'item_key' => $r['item_key'],
        ], q("SELECT x.*, h.name AS host_name FROM ids_exceptions x LEFT JOIN hosts h ON x.scope = 'host' AND h.id = x.scope_val ORDER BY x.id")->fetchAll())];
    }

    /** Importeren: per regel controleren; servers op naam opzoeken (onbekend = overgeslagen) */
    public static function import(array $data, string $by): array
    {
        $ok = 0;
        $skip = [];
        foreach (array_slice((array) ($data['exceptions'] ?? []), 0, 500) as $e) {
            if (!is_array($e)) {
                continue;
            }
            try {
                if (($e['scope'] ?? '') === 'host') {
                    $hid = q('SELECT id FROM hosts WHERE name = ?', [(string) ($e['scope_val'] ?? '')])->fetchColumn();
                    if (!$hid) {
                        throw new InvalidArgumentException(t('server {name} bestaat hier niet', ['name' => (string) ($e['scope_val'] ?? '?')]));
                    }
                    $e['scope_val'] = (string) $hid;
                }
                $r = self::clean($e);
                q('INSERT INTO ids_exceptions (name, target, rule, scope, scope_val, cond, until, reason, created_by, enabled, set_key, item_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$r['name'], $r['target'], $r['rule'], $r['scope'], $r['scope_val'], $r['cond'], $r['until'], $r['reason'], $by, !empty($e['enabled']) || !isset($e['enabled']) ? 1 : 0,
                        isset($e['set_key']) && preg_match('/^[a-z]{1,20}$/', (string) $e['set_key']) ? $e['set_key'] : null,
                        isset($e['item_key']) && preg_match('/^[a-z0-9]{1,40}$/', (string) $e['item_key']) ? $e['item_key'] : null]);
                $ok++;
            } catch (InvalidArgumentException $ex) {
                $skip[] = mb_substr((string) ($e['name'] ?? '?'), 0, 60) . ': ' . $ex->getMessage();
            } catch (PDOException) {
                $skip[] = mb_substr((string) ($e['name'] ?? '?'), 0, 60) . ': ' . t('bestaat al');
            }
        }
        AgentCommands::audit($by, 'Uitzonderingen geïmporteerd', "$ok");
        self::reset();
        return ['added' => $ok, 'skipped' => $skip];
    }
}
