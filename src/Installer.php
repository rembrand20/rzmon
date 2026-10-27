<?php
declare(strict_types=1);

/** Gedeelde installatie-logica voor install/install.php (CLI) en public/setup.php. */
final class Installer
{
    public const CONFIG_DEFAULTS = [
        'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'rzmon', 'user' => 'rzmon', 'pass' => ''],
        'timezone' => 'Europe/Amsterdam',
        'ping_bin' => '/bin/ping',
        'base_url' => '',
        'php' => ['memory_limit' => '', 'max_execution_time' => '', 'display_errors' => '0'],
    ];

    /** Tabellen aanmaken of bijwerken (veilig om vaker uit te voeren). */
    public static function runSchema(?PDO $pdo = null): void
    {
        $pdo ??= db();
        // Bij een update blijven bestaande instellingen precies zoals ze waren (IDS aan, modus, meldingen…):
        // het schema en de migraties mogen alleen ontbrekende instellingen toevoegen
        try {
            $before = $pdo->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            $before = [];   // nieuwe installatie: de tabel bestaat nog niet
        }
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(SM_ROOT . '/install/schema.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
        self::migrate($pdo);
        $restore = $pdo->prepare('UPDATE settings SET value = ? WHERE name = ?');
        $after = $pdo->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($before as $name => $value) {
            if ($name !== 'db_version' && array_key_exists($name, $after) && $after[$name] !== $value) {
                $restore->execute([$value, $name]);
            }
        }
        $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['db_version', RZMON_VERSION]);
    }

    /**
     * Database bijwerken als die bij een oudere RzMon-versie hoort (na git pull / update).
     * Veilig om vaak aan te roepen: kost één query als alles al klopt.
     * @return string|null foutmelding, of null als alles in orde is
     */
    public static function ensureSchema(): ?string
    {
        try {
            $current = db()->query("SELECT value FROM settings WHERE name = 'db_version'")->fetchColumn();
        } catch (Throwable) {
            $current = false;   // bv. settings-tabel bestaat nog niet
        }
        if ($current === RZMON_VERSION) {
            return null;
        }
        try {
            self::runSchema();
            self::infoUpdated($current);
            return null;
        } catch (Throwable $e) {
            return t('Database bijwerken naar {v} mislukt', ['v' => RZMON_VERSION]) . ': ' . $e->getMessage();
        }
    }

    /** Info-melding "RzMon bijgewerkt" (niet bij een nieuwe installatie); één keer, ook als meerdere processen tegelijk bijwerken */
    private static function infoUpdated(string|false $from): void
    {
        if ($from === false || $from === '' || !Installer::userCount()) {
            return;
        }
        try {
            // 1 = nieuw, 2 = gewijzigd, 0 = een ander proces was eerst
            $n = q("INSERT INTO settings (name, value) VALUES ('info_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [RZMON_VERSION])->rowCount();
            if ($n > 0) {
                Monitor::infoSystem(fn() => t('RzMon bijgewerkt van {from} naar {to}', ['from' => $from, 'to' => RZMON_VERSION]));
            }
        } catch (Throwable) {
            // een melding mag het bijwerken nooit tegenhouden
        }
    }

    /** Kolommen van latere versies; werkt op MariaDB én MySQL (geen ADD COLUMN IF NOT EXISTS). */
    private const COLUMNS = [
        ['hosts', 'parent_id', 'INT UNSIGNED NULL AFTER mode'],
        ['hosts', 'maintenance_until', 'DATETIME NULL'],
        ['hosts', 'maintenance_reason', 'VARCHAR(200) NULL'],
        ['checks', 'maintenance_until', 'DATETIME NULL'],
        ['checks', 'maintenance_reason', 'VARCHAR(200) NULL'],
        ['checks', 'suppressed_by', 'INT UNSIGNED NULL'],
        ['alerts', 'webhook_ok', 'TINYINT(1) NULL'],
        ['alerts', 'kind', "VARCHAR(16) NOT NULL DEFAULT 'notify'"],
        ['checks', 'problem_since', 'DATETIME NULL'],
        ['checks', 'acked_at', 'DATETIME NULL'],
        ['checks', 'acked_by', 'VARCHAR(64) NULL'],
        ['checks', 'ack_comment', 'VARCHAR(200) NULL'],
        ['checks', 'pushover_receipt', 'VARCHAR(40) NULL'],
        ['hosts', 'agent_ip', 'VARCHAR(45) NULL'],
        ['hosts', 'agent_version', 'VARCHAR(20) NULL'],
        ['users', 'totp_secret', 'VARCHAR(64) NULL'],
        ['users', 'totp_last_step', 'BIGINT NULL'],
        ['users', 'last_login', 'DATETIME NULL'],
        ['checks', 'verify_ssl', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['alerts', 'email_ok', 'TINYINT(1) NULL'],
        ['checks', 'metric', 'VARCHAR(16) NULL'],   // container: status | cpu | mem | disk
        ['checks', 'options', 'VARCHAR(500) NULL'],  // JSON, bv. service: diepere controle en extra test
        ['checks', 'maintenance_auto', 'TINYINT(1) NOT NULL DEFAULT 0'],  // onderhoud automatisch gezet na herhaalde meldingen
        ['users', 'role', "VARCHAR(10) NOT NULL DEFAULT 'admin'"],   // admin | user | readonly (bestaande accounts: admin)
        ['results', 'vals', 'VARCHAR(255) NULL'],          // container: CPU, RAM en disk van één meting (JSON)
        ['checks', 'notify', 'VARCHAR(1000) NULL'],        // eigen meldregels (JSON), anders de algemene
        ['checks', 'status_since', 'DATETIME NULL'],       // sinds wanneer de huidige status geldt (voor "na X minuten")
        ['checks', 'flapping_since', 'DATETIME NULL'],     // anti-flapping: check wisselt steeds van status
        ['checks', 'flap_notified', 'VARCHAR(20) NULL'],   // statussen die tijdens het flapperen al gemeld zijn
        ['checks', 'command', 'TEXT NULL'],                // commando-check: JSON met cmd, expect, match, timeout, fail
        ['alerts', 'host_id', 'INT UNSIGNED NULL'],        // logins/sudo: van welke server (er hoort geen check bij)
        ['alerts', 'detail', 'TEXT NULL'],                 // logins/sudo: wie, wat, IP (JSON)
        ['hosts', 'commands', 'VARCHAR(10) NULL'],        // commando's op deze agent: off/nobody/root, NULL = algemene instelling
        ['checks', 'quiet', 'VARCHAR(1000) NULL'],         // stille uren voor deze check (JSON: mode global/own/never), anders algemeen
        ['checks', 'quiet_held', 'TINYINT NULL'],          // meldingen tegengehouden door stille uren: status die de gebruiker het laatst kreeg
        ['checks', 'flap_ended_at', 'DATETIME NULL'],      // weer stabiel: wisselingen van daarvoor tellen niet meer mee
        // Omgekeerde status: slaagt de check, dan deze status (NULL = normaal); mislukt hij, dan reverse_fail
        ['checks', 'reverse_ok', 'TINYINT NULL'],
        ['checks', 'reverse_fail', 'TINYINT NULL'],
        // IDS: bestanden bewaken (extra paden per server, JSON) en wat de agent daarvan meldt
        ['hosts', 'ids_fim', 'TEXT NULL'],
        ['hosts', 'agent_tick', 'SMALLINT UNSIGNED NULL'],   // hoe vaak de agent rapporteert (s); NULL = algemene instelling
        ['hosts', 'realtime', 'TINYINT(1) NOT NULL DEFAULT 0'],   // realtime-watcher aan (logins, services, bestanden direct)
        ['hosts', 'snmp', 'TEXT NULL'],   // SNMP: versie, poort, community of v3-gebruiker (JSON); NULL = uit
        // Melden na N mislukte checks: hoe vaak achter elkaar dezelfde status, en per check een eigen N (NULL = algemeen)
        ['checks', 'status_streak', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['checks', 'notify_fails', 'SMALLINT UNSIGNED NULL'],
        // Info-meldingen: per server uit te zetten voor agent-updates (1 = volgt de algemene info-regel)
        ['hosts', 'info_agent', 'TINYINT(1) NOT NULL DEFAULT 1'],
        // Info-check: geeft alleen info-meldingen, telt nooit als storing
        ['checks', 'info_only', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // Kwetsbaarheden: pakketten bijwerken via de agent (opdracht als JSON, en de uitkomst)
        ['hosts', 'vuln_upgrade_req', 'TEXT NULL'],
        ['hosts', 'vuln_upgrade_status', 'TEXT NULL'],
        // API-sleutels: rechten (read, ids_read, ids_block, checks), alleen vanaf (IP's/netwerken), geldig tot
        ['api_keys', 'rights', "VARCHAR(60) NOT NULL DEFAULT 'read'"],
        ['api_keys', 'allow_from', 'VARCHAR(500) NULL'],
        ['api_keys', 'expires_at', 'DATETIME NULL'],
        ['api_keys', 'created_by', 'VARCHAR(100) NULL'],
        ['ids_state', 'fim_epoch', 'INT UNSIGNED NULL'],     // verandert bij een nieuwe basismeting: agent stuurt dan alles opnieuw
        ['ids_state', 'fim_count', 'INT UNSIGNED NULL'],
        ['ids_state', 'fim_cand', 'VARCHAR(1000) NULL'],     // voorstellen die op de server bestaan (/usr/local/bin, /var/www…)
        ['users', 'email', 'VARCHAR(190) NULL'],         // voor "wachtwoord vergeten"
        ['users', 'pw_changed_at', 'DATETIME NULL'],     // sessies van vóór dit moment worden uitgelogd
        ['users', 'lang', 'VARCHAR(5) NULL'],            // taal van de interface (nl/en); NULL = die van de browser
        // Agent bijwerken vanuit RzMon: gevraagd (knop), naar de agent gestuurd, uitkomst, en hoe de agent dat kan
        ['hosts', 'agent_update_req', 'DATETIME NULL'],
        ['hosts', 'agent_update_sent', 'DATETIME NULL'],
        ['hosts', 'agent_update_status', 'VARCHAR(255) NULL'],
        ['hosts', 'agent_update_via', 'VARCHAR(16) NULL'],   // systemd | direct | off | ha | none (null = oude agent)
        // Tellers op de tabs: wanneer de gebruiker het Dashboard en de Meldingen voor het laatst zag
        ['users', 'seen_dashboard', 'DATETIME NULL'],
        ['users', 'seen_alerts', 'DATETIME NULL'],
        // Versleuteling per agent: NULL = algemene instelling, of on / required / off; en of het laatste rapport versleuteld was
        ['hosts', 'enc', 'VARCHAR(10) NULL'],
        ['hosts', 'enc_seen', 'TINYINT NULL'],
        ['alerts', 'ip', 'VARCHAR(45) NULL'],   // geweigerde verbinding of login: vanaf welk IP (voor ⛔ blokkeren)
        // Inlogbeveiliging: account geblokkeerd na te veel mislukte pogingen (tot locked_until, NULL = tot een beheerder deblokkeert)
        ['users', 'locked_at', 'DATETIME NULL'],
        ['users', 'locked_until', 'DATETIME NULL'],
        ['users', 'locked_ip', 'VARCHAR(45) NULL'],
        ['security_fails', 'user', 'VARCHAR(64) NULL'],
        ['rz_blocks', 'until', 'DATETIME NULL'],                     // automatische blokkade: tot wanneer (NULL = blijvend)
        ['rz_blocks', 'auto', 'TINYINT(1) NOT NULL DEFAULT 0'],      // door de inlogbeveiliging gezet
        // Beveiligde verbinding (1.64.0): een eigen sleutel per agent (kenmerk = AgentCrypto::keyId), de vorige nog 24 uur geldig
        ['hosts', 'agent_key', 'VARCHAR(64) NULL'],
        ['hosts', 'agent_key_id', 'CHAR(16) NULL'],
        ['hosts', 'agent_key_at', 'DATETIME NULL'],                  // wanneer de huidige sleutel is gemaakt (vernieuwen na X dagen)
        ['hosts', 'agent_key_seen', 'TINYINT NULL'],                 // 1 = de agent gebruikt zijn eigen sleutel (dan geldt een token alleen niet meer)
        ['hosts', 'agent_key_old', 'VARCHAR(64) NULL'],
        ['hosts', 'agent_key_old_id', 'CHAR(16) NULL'],
        ['hosts', 'agent_key_old_until', 'DATETIME NULL'],
        ['hosts', 'pair_key', 'VARCHAR(64) NULL'],                   // koppelcode (afgeleide sleutel), eenmalig en kort geldig
        ['hosts', 'pair_until', 'DATETIME NULL'],
        ['hosts', 'agent_ips', 'VARCHAR(255) NULL'],                 // toegestane IP-adressen van de agent (spatie-gescheiden)
        ['hosts', 'agent_ip_new', 'VARCHAR(45) NULL'],               // geweigerd: nieuw IP-adres, wacht op toestemming
        ['hosts', 'agent_ip_new_at', 'DATETIME NULL'],
        // Per server (1.65.0): certificaat vastzetten en vast IP-adres; NULL = algemene instelling, of on / off
        ['hosts', 'pin_mode', 'VARCHAR(3) NULL'],
        ['hosts', 'ip_lock', 'VARCHAR(3) NULL'],
        // 1.68.0: onthouden apparaat dat met 2FA is aangemaakt (van buiten je interne netwerken alleen dan geldig)
        ['remember_tokens', 'mfa', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // 1.69.0: per account alleen vanaf de interne netwerken (noodaccount), datalek-controle (nieuwe accounts: aan),
        // en het resultaat van de wachtwoordcontrole (aantal keer gelekt; verplicht een nieuw kiezen)
        ['users', 'local_only', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['users', 'hibp', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['users', 'pw_pwned', 'INT UNSIGNED NULL'],
        ['users', 'pw_pwned_at', 'DATETIME NULL'],
        ['users', 'pw_must_change', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // 1.70.0: welke vingerafdruk van het certificaat deze agent het laatst kreeg (versleuteld), en wanneer
        ['hosts', 'agent_pin_sent', 'VARCHAR(64) NULL'],
        ['hosts', 'agent_pin_sent_at', 'DATETIME NULL'],
        // 1.71.0: voorkeuren per gebruiker (JSON): bij openen alles dicht of slim, filters per pagina, opgeslagen filters
        ['users', 'prefs', 'MEDIUMTEXT NULL'],
        // 1.72.0: adres van RzMon zoals de agent het gebruikt (en zijn reserve-adres), een gevraagde overstap met de
        // uitkomst, hoe hij verbond (http, https, https via de proxy), sinds wanneer via het reserve-adres, en per
        // server "alleen via https" (NULL = algemene instelling, of on / off)
        ['hosts', 'agent_url', 'VARCHAR(255) NULL'],
        ['hosts', 'agent_url_fb', 'VARCHAR(255) NULL'],
        ['hosts', 'agent_url_want', 'VARCHAR(255) NULL'],
        ['hosts', 'agent_url_want_fb', 'VARCHAR(255) NULL'],
        ['hosts', 'agent_url_status', 'VARCHAR(255) NULL'],
        ['hosts', 'agent_url_at', 'DATETIME NULL'],
        ['hosts', 'agent_conn', 'VARCHAR(8) NULL'],
        ['hosts', 'agent_fb_at', 'DATETIME NULL'],
        ['hosts', 'agent_fb_notified', 'DATETIME NULL'],
        ['ids_state', 'decoy_state', 'VARCHAR(100) NULL'],
        ['ids_state', 'kev_at', 'DATETIME NULL'],              // 1.82.0: laatste controle open poorten + KEV
        ['ids_incidents', 'cc', 'CHAR(2) NULL'],                // 1.82.0: land van het IP
        ['ids_blocks', 'cc', 'CHAR(2) NULL'],   // 1.81.0: waarom de honeypot-poort hier niet werkt (bv. in gebruik)   // 1.78.0: wanneer de samengevatte ⚠️-melding over het reserve-adres ging
        ['ids_blocks', 'decoy_user', 'VARCHAR(64) NULL'],   // 1.83.0: geblokkeerd om deze honeypot-naam
        ['users', 'locked_reason', 'VARCHAR(200) NULL'],     // 1.83.0: waarom het account geblokkeerd is
        // 1.88.0: server isoleren (tot wanneer, door wie, welk IP van de beheerder mag erbij, stand bij de agent)
        ['hosts', 'isolate_until', 'DATETIME NULL'],
        ['hosts', 'isolate_by', 'VARCHAR(64) NULL'],
        ['hosts', 'isolate_allow', 'VARCHAR(255) NULL'],
        ['hosts', 'isolate_state', 'VARCHAR(255) NULL'],
        ['checks', 'template_item_id', 'INT UNSIGNED NULL'],     // uit welk sjabloon deze check komt (1.94.0)
        ['checks', 'tpl_override', 'TINYINT(1) NOT NULL DEFAULT 0'],   // per server aangepast: gaat niet meer mee met het sjabloon
        ['hosts', 'discover_ignore', 'TEXT NULL'],
        ['checks', 'run_ms', 'INT UNSIGNED NULL'],              // hoe lang de laatste uitvoering duurde (1.96.0)
        ['checks', 'proc_start', 'INT UNSIGNED NULL'],           // proces-check: start van het oudste proces (herstart zien, 1.98.0)
        ['hosts', 'certscan_req', 'INT UNSIGNED NOT NULL DEFAULT 0'],   // "🔄 Opnieuw zoeken" naar certificaten (1.98.0)
        ['hosts', 'agent_beats', 'VARCHAR(400) NULL'],           // laatste contactmomenten van de agent (unix-tijden, 1.99.0)
        ['hosts', 'agent_url_pin', 'VARCHAR(200) NULL'],   // 1.78.0: hoe de agent zijn adres controleert ("-" of sha256//…)
        ['hosts', 'agent_url_fb_pin', 'VARCHAR(200) NULL'],
        ['hosts', 'agent_https', 'VARCHAR(3) NULL'],
        ['checks', 'hb_token', 'CHAR(32) NULL'],      // geheime URL van een heartbeat-check (1.77.0)
        ['hosts', 'kind', 'VARCHAR(16) NULL'],        // soort apparaat, zelf gekozen (1.75.0); NULL = herkend
        ['hosts', 'kind_auto', 'VARCHAR(16) NULL'],   // soort apparaat, herkend via de agent of SNMP
        ['net_devices', 'blocked', 'TEXT NULL'],
        ['hosts', 'pve_cluster', 'VARCHAR(64) NULL'],   // Proxmox: losse nodes samen als cluster aangemerkt (1.105.0)      // geweerd van het netwerk (1.104.0): wanneer, tot wanneer, via wat
        // 1.107.0: slimme drempels (Smart). Bestaande checks blijven handmatig; daarna wordt de standaard 'auto' (zie migrate)
        ['checks', 'thresh_mode', "VARCHAR(8) NOT NULL DEFAULT 'manual'"],
        ['checks', 'guard_threshold', 'DOUBLE NULL'],     // vangnet: vanaf hier altijd CRITICAL
        ['checks', 'smart_sens', 'VARCHAR(6) NULL'],      // gevoeligheid; NULL = de standaard
        ['checks', 'heal', 'VARCHAR(80) NULL'],           // zelf herstellen: restart, of cmd:<naam> uit heal.d op de server
        ['checks', 'heal_state', 'VARCHAR(40) NULL'],     // herstelcommando: req:/sent:/done:<unix-tijd>
        ['hosts', 'watchdog', 'TINYINT(1) NOT NULL DEFAULT 0'],   // 🐕 deze agent bewaakt RzMon
        ['hosts', 'watchdog_min', 'SMALLINT UNSIGNED NULL'],      // na hoeveel minuten melden (NULL = 5)
        // 1.108.0: slim, deel 2
        ['checks', 'escalated', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'],   // escalatie: al een stap hoger gemeld voor deze storing
        ['checks', 'anom_streak', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'], // afwijking: zoveel metingen achter elkaar
    ];

    private static function migrate(PDO $pdo): void
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        foreach (self::COLUMNS as [$table, $column, $definition]) {
            $st->execute([$table, $column]);
            if (!(int) $st->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                if ("$table.$column" === 'users.lang') {
                    $pdo->exec("UPDATE users SET lang = 'nl'");   // bestaande accounts blijven Nederlands
                }
            }
        }
        // 1.107.0: nieuwe checks (elke manier van toevoegen) beginnen op 'auto': bij de eerste run de standaard drempelmodus
        $pdo->exec("ALTER TABLE checks ALTER COLUMN thresh_mode SET DEFAULT 'auto'");
        self::mergeContainerChecks($pdo);
        Whitelist::migrate($pdo);
        NotifyRules::migrate($pdo);
        self::moveAccessLog($pdo);
        AgentAudit::migrateServiceChecks($pdo);
    }

    /** 1.38.0: logins en sudo van de pagina Meldingen (alerts) naar IDS → Toegang (access_log). Eenmalig; daarna leeg. */
    private static function moveAccessLog(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO access_log (host_id, kind, type, title, message, detail, created_at)
                    SELECT a.host_id, a.kind, COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.detail, '$.type')), ''), a.title, a.message, a.detail, a.created_at
                    FROM alerts a JOIN hosts h ON h.id = a.host_id WHERE a.kind IN ('auth', 'authfail')");
        $pdo->exec("DELETE FROM alerts WHERE kind IN ('auth', 'authfail')");
    }

    /**
     * Sinds 1.23.0 is er één check per container (status + de gekozen CPU/RAM/disk). Oude losse checks
     * (metric status/cpu/mem/disk) worden per container samengevoegd. De drempels gaan mee, en de
     * geschiedenis van de losse metingen komt per minuut in results.vals van de overgebleven check.
     */
    private static function mergeContainerChecks(PDO $pdo): void
    {
        $rows = $pdo->query("SELECT * FROM checks WHERE type = 'container' AND metric IS NOT NULL
                             ORDER BY host_id, target, FIELD(metric, 'status', 'cpu', 'mem', 'disk'), id")->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['host_id'] . '|' . $r['target']][] = $r;
        }
        foreach ($groups as $g) {
            $find = $pdo->prepare("SELECT * FROM checks WHERE type = 'container' AND metric IS NULL AND host_id = ? AND target = ? ORDER BY id LIMIT 1");
            $find->execute([$g[0]['host_id'], $g[0]['target']]);
            $keep = $find->fetch(PDO::FETCH_ASSOC) ?: array_shift($g);
            $opts = json_decode((string) $keep['options'], true) ?: [];
            $res = $opts['res'] ?? [];
            $metricOf = static fn(array $c) => in_array($c['metric'], ['cpu', 'mem', 'disk'], true) ? $c['metric'] : null;
            $thr = static fn(array $c) => [$c['warn_threshold'] !== null ? (float) $c['warn_threshold'] : null, $c['crit_threshold'] !== null ? (float) $c['crit_threshold'] : null];

            // Eigen geschiedenis van de check die blijft
            if ($m = $metricOf($keep)) {
                $res[$m] = $thr($keep);
                $pdo->prepare("UPDATE results SET vals = JSON_OBJECT(?, value) WHERE check_id = ? AND value IS NOT NULL")->execute([$m, $keep['id']]);
            } elseif (($keep['metric'] ?? null) === 'status') {
                $pdo->prepare('UPDATE results SET value = NULL WHERE check_id = ?')->execute([$keep['id']]);   // was het aantal herstarts
            }
            $maint = [$keep['maintenance_until'], $keep['maintenance_reason']];
            foreach ($g as $o) {
                if ($m = $metricOf($o)) {
                    $res[$m] = $thr($o);
                    // Metingen per minuut koppelen aan de metingen van de check die blijft
                    $pdo->exec('CREATE TEMPORARY TABLE rz_merge (b BIGINT PRIMARY KEY, v DOUBLE NULL)');
                    $pdo->prepare('INSERT INTO rz_merge SELECT FLOOR(UNIX_TIMESTAMP(created_at) / 60), AVG(value) FROM results
                                   WHERE check_id = ? AND value IS NOT NULL GROUP BY 1')->execute([$o['id']]);
                    // $m komt uit de vaste lijst cpu/mem/disk (MariaDB wil een vast JSON-pad)
                    $pdo->prepare("UPDATE results s JOIN rz_merge t ON t.b = FLOOR(UNIX_TIMESTAMP(s.created_at) / 60)
                                   SET s.vals = JSON_SET(COALESCE(s.vals, '{}'), '$.$m', t.v) WHERE s.check_id = ?")->execute([$keep['id']]);
                    $pdo->exec('DROP TEMPORARY TABLE rz_merge');
                }
                if ($o['maintenance_until'] !== null && ($maint[0] === null || $o['maintenance_until'] > $maint[0])) {
                    $maint = [$o['maintenance_until'], $o['maintenance_reason']];
                }
                $pdo->prepare('UPDATE alerts SET check_id = ? WHERE check_id = ?')->execute([$keep['id'], $o['id']]);
                $pdo->prepare('DELETE FROM checks WHERE id = ?')->execute([$o['id']]);
            }
            // Waarde voor de minigrafiek: CPU, anders RAM, anders disk
            $pdo->prepare("UPDATE results SET value = COALESCE(JSON_EXTRACT(vals, '$.cpu'), JSON_EXTRACT(vals, '$.mem'), JSON_EXTRACT(vals, '$.disk')) + 0
                           WHERE check_id = ? AND vals IS NOT NULL")->execute([$keep['id']]);
            $name = str_starts_with((string) $keep['name'], '📦') ? '📦 ' . $keep['target'] : $keep['name'];
            $opts['res'] = (object) $res;
            $pdo->prepare('UPDATE checks SET metric = NULL, options = ?, name = ?, warn_threshold = NULL, crit_threshold = NULL,
                           maintenance_until = ?, maintenance_reason = ? WHERE id = ?')
                ->execute([json_encode($opts), mb_substr($name, 0, 100), $maint[0], $maint[1], $keep['id']]);
        }
    }

    public static function userCount(): int
    {
        return (int) q('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function saveUser(string $username, string $password, ?string $role = null): void
    {
        if (!preg_match('/^[\w.@-]{2,64}$/u', $username)) {
            throw new InvalidArgumentException('Gebruikersnaam: 2-64 tekens, letters/cijfers/._@-');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException(t('Wachtwoord moet minimaal 8 tekens zijn'));
        }
        // Nieuw account: datalek-controle standaard aan (bestaande accounts houden hun instelling)
        q('INSERT INTO users (username, password_hash, role, hibp) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), pw_pwned_at = NULL, pw_must_change = 0', [
            $username, password_hash($password, PASSWORD_DEFAULT), in_array($role, array_keys(ROLES), true) ? $role : 'admin',
        ]);
    }

    /** Eerste host (de monitor-server zelf) met standaard checks. */
    public static function seedLocalHost(): void
    {
        if (q('SELECT COUNT(*) FROM hosts')->fetchColumn()) {
            return;
        }
        q("INSERT INTO hosts (name, address, mode) VALUES (?, '127.0.0.1', 'local')", [gethostname() ?: 'Monitor']);
        $hid = (int) db()->lastInsertId();
        q("INSERT INTO checks (host_id, type, target, name, warn_threshold, crit_threshold) VALUES
            (?, 'disk', '/', 'Disk /', 80, 90),
            (?, 'service', 'mariadb', 'MariaDB', NULL, NULL),
            (?, 'cpu', '', 'CPU', 85, 95),
            (?, 'ram', '', 'RAM', 85, 95)", [$hid, $hid, $hid, $hid]);
    }

    /** Verbinding testen zonder de globale db() te gebruiken. */
    public static function connect(array $db, bool $withDatabase = true): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) ($db['port'] ?? 3306));
        if ($withDatabase) {
            $dsn .= ';dbname=' . $db['name'];
        }
        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /** Database + gebruiker aanmaken met een beheerdersaccount (bv. root). */
    public static function createDatabase(array $db, string $adminUser, string $adminPass, ?PDO $admin = null): void
    {
        if (!preg_match('/^\w{1,64}$/', $db['name']) || !preg_match('/^[\w.-]{1,80}$/', $db['user'])) {
            throw new InvalidArgumentException(t('Databasenaam/gebruiker mag alleen letters, cijfers en _ bevatten'));
        }
        $pdo = $admin ?? self::connect(['host' => $db['host'], 'port' => $db['port'] ?? 3306, 'user' => $adminUser, 'pass' => $adminPass], false);
        $host = in_array($db['host'], ['127.0.0.1', 'localhost', '::1'], true) ? 'localhost' : '%';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec(sprintf("CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY %s", $db['user'], $host, $pdo->quote($db['pass'])));
        $pdo->exec(sprintf("ALTER USER '%s'@'%s' IDENTIFIED BY %s", $db['user'], $host, $pdo->quote($db['pass'])));
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$db['name']}`.* TO '{$db['user']}'@'$host'");
        if ($host === 'localhost') {
            // Ook via TCP (127.0.0.1) laten werken
            $pdo->exec(sprintf("CREATE USER IF NOT EXISTS '%s'@'127.0.0.1' IDENTIFIED BY %s", $db['user'], $pdo->quote($db['pass'])));
            $pdo->exec(sprintf("ALTER USER '%s'@'127.0.0.1' IDENTIFIED BY %s", $db['user'], $pdo->quote($db['pass'])));
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$db['name']}`.* TO '{$db['user']}'@'127.0.0.1'");
        }
        $pdo->exec('FLUSH PRIVILEGES');
    }

    public static function configWritable(): bool
    {
        // writeConfig() schrijft via een tijdelijk bestand + rename, dus de map moet schrijfbaar zijn
        return is_writable(dirname(SM_CONFIG_FILE));
    }

    public static function renderConfig(array $cfg): string
    {
        return "<?php\n// Gegenereerd door RzMon setup op " . date('Y-m-d H:i:s') . "\nreturn " . var_export($cfg, true) . ";\n";
    }

    /** Schrijft config.php; geeft false terug als dat niet mag (dan handmatig plaatsen). */
    public static function writeConfig(array $cfg): bool
    {
        if (!self::configWritable()) {
            return false;
        }
        $tmp = SM_CONFIG_FILE . '.tmp';
        if (file_put_contents($tmp, self::renderConfig($cfg), LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        $ok = rename($tmp, SM_CONFIG_FILE);
        if ($ok && function_exists('opcache_invalidate')) {
            @opcache_invalidate(SM_CONFIG_FILE, true);
        }
        return $ok;
    }

    // ------------------------------------------------------------------ databasewachtwoord vernieuwen

    /** Map voor de hulpdienst (zie install/install-helper.sh): verzoek en resultaat van de wissel. */
    public const HELPER_DIR = '/var/lib/rzmon';

    /**
     * Nieuw databasewachtwoord: in de database, config.php en (als root) /root/rzmon-install.txt.
     * Daarna wordt de verbinding getest; lukt dat niet, dan gaat alles terug naar het oude wachtwoord.
     * Als root (terminal of hulpdienst) worden álle accounts van de databasegebruiker bijgewerkt
     * (bv. 'rzmon'@'localhost' en 'rzmon'@'127.0.0.1'), zodat het oude wachtwoord nergens meer werkt.
     * @return array{ok: bool, password: ?string, warnings: list<string>, error: ?string}
     */
    public static function rotateDbPassword(): array
    {
        $cfg = $GLOBALS['sm_config'];
        $db = $cfg['db'];
        $old = (string) $db['pass'];
        $new = self::randomPassword(32);
        $warnings = [];
        if (!is_writable(SM_CONFIG_FILE) && !self::configWritable()) {
            return ['ok' => false, 'password' => null, 'warnings' => [], 'error' => t('config.php kan niet worden opgeslagen (map vergrendeld). Gebruik de hulpdienst of de terminal (als root).')];
        }

        // Als root: met het MariaDB-beheeraccount (unix_socket, geen wachtwoord) alle accounts bijwerken
        $admin = self::rootPdo();
        $setPassword = static function (string $pass) use ($admin, $db): void {
            if ($admin) {
                $hosts = $admin->prepare('SELECT Host FROM mysql.user WHERE User = ?');
                $hosts->execute([$db['user']]);
                foreach ($hosts->fetchAll(PDO::FETCH_COLUMN) as $h) {
                    $admin->exec(sprintf('ALTER USER %s@%s IDENTIFIED BY %s', $admin->quote($db['user']), $admin->quote($h), $admin->quote($pass)));
                }
                $admin->exec('FLUSH PRIVILEGES');
            } else {
                // Zonder beheerrechten: alleen het account waarmee RzMon verbinding maakt
                self::connect($db)->exec('ALTER USER CURRENT_USER() IDENTIFIED BY ' . self::connect($db)->quote($pass));
            }
        };

        try {
            $setPassword($new);
        } catch (PDOException $e) {
            return ['ok' => false, 'password' => null, 'warnings' => [], 'error' => t('De database weigert de wijziging') . ': ' . $e->getMessage()];
        }

        // config.php bijwerken met dezelfde eigenaar, groep en rechten als het origineel
        $stat = @stat(SM_CONFIG_FILE);
        $cfg['db']['pass'] = $new;
        $tmp = SM_CONFIG_FILE . '.tmp';
        if (self::configWritable()) {
            // Via een tijdelijk bestand + rename: nooit een half geschreven config.php
            $written = file_put_contents($tmp, self::renderConfig($cfg), LOCK_EX) !== false;
            if ($written && $stat) {
                @chmod($tmp, $stat['mode'] & 0777);
                @chown($tmp, $stat['uid']);
                @chgrp($tmp, $stat['gid']);
            }
            $written = $written && @rename($tmp, SM_CONFIG_FILE);
        } else {
            $written = file_put_contents(SM_CONFIG_FILE, self::renderConfig($cfg), LOCK_EX) !== false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(SM_CONFIG_FILE, true);
        }

        // Werkt het nieuwe wachtwoord? Anders alles terug.
        $works = false;
        try {
            self::connect(['pass' => $new] + $db)->query('SELECT 1');
            $works = true;
        } catch (PDOException) {
        }
        if (!$written || !$works) {
            @unlink($tmp);
            try {
                $admin ? $setPassword($old) : self::connect(['pass' => $new] + $db)->exec('ALTER USER CURRENT_USER() IDENTIFIED BY ' . self::connect(['pass' => $new] + $db)->quote($old));
            } catch (PDOException) {
            }
            if ($written) {
                $cfg['db']['pass'] = $old;
                file_put_contents(SM_CONFIG_FILE, self::renderConfig($cfg), LOCK_EX);
            }
            return ['ok' => false, 'password' => null, 'warnings' => [],
                'error' => !$written ? t('config.php kon niet worden opgeslagen; het oude wachtwoord is teruggezet') : t('Verbinden met het nieuwe wachtwoord mislukte; het oude wachtwoord is teruggezet')];
        }
        $GLOBALS['sm_config']['db']['pass'] = $new;

        // Werkt het oude wachtwoord nog via een ander account (zonder beheerrechten niet bij te werken)?
        if (!$admin) {
            foreach (['localhost', '127.0.0.1'] as $h) {
                try {
                    self::connect(['host' => $h, 'pass' => $old] + $db);
                    $warnings[] = t('Het oude wachtwoord werkt nog voor {account}. Vernieuw via de hulpdienst of de terminal (als root), dan worden alle accounts bijgewerkt.', ['account' => "{$db['user']}@$h"]);
                } catch (PDOException) {
                }
            }
        }
        // Installatieoverzicht van install-rocky.sh bijwerken (alleen als root leesbaar)
        $summary = '/root/rzmon-install.txt';
        if (is_writable($summary)) {
            $txt = (string) file_get_contents($summary);
            file_put_contents($summary, preg_replace('/^(DB-wachtwoord\s*:\s*).*$/m', '${1}' . $new, $txt));
        }
        return ['ok' => true, 'password' => $new, 'warnings' => $warnings, 'error' => null];
    }

    /** Als root: het MariaDB-beheeraccount via de socket (unix_socket, geen wachtwoord), anders null. */
    public static function rootPdo(): ?PDO
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return null;
        }
        try {
            return new PDO('mysql:host=localhost', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (PDOException) {
            return null;
        }
    }

    /** Willekeurig wachtwoord van letters en cijfers (werkt overal, zonder aanhalingstekens-gedoe). */
    public static function randomPassword(int $length): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    public static function mergedConfig(array $changes): array
    {
        return array_replace_recursive(self::CONFIG_DEFAULTS, $GLOBALS['sm_config'] ?? [], $changes);
    }
}
