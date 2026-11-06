<?php
declare(strict_types=1);

/**
 * 1.205.0: agents wachten rustig tot een update van RzMon klaar is.
 *
 * - Aankondigen: bij Bijwerken zet RzMon eerst "update bezig" klaar (instelling update_hold) en geeft dat mee in elk
 *   versleuteld antwoord aan een agent (rz_hold: id en hoeveel seconden hij hooguit wacht). Het eigenlijke verzoek aan de
 *   hulpdienst gaat pas als alle agents die het moeten weten het gehoord hebben, of na LEAD seconden.
 * - De agent (1.82.0) zet metingen die niet aankomen in zijn wachtrij en alarmeert niet, tot de vrijgave (een antwoord
 *   zonder rz_hold) of tot de tijd voorbij is. Alleen uit een versleuteld antwoord: een foutpagina kan het niet nadoen.
 * - Na de update: een samenvatting als systeeminfo (duur, wie er wachtte, hoeveel metingen nagestuurd). Mislukt: vrijgeven
 *   en melden via de meldkanalen.
 * Per agent staat de stand in settings (update_hold_ack:<id>), zodat agents die tegelijk rapporteren elkaar niet overschrijven.
 */
final class UpdateHold
{
    /** Hooguit zo lang (seconden) wachten op agents voordat de update echt begint */
    public const LEAD = 75;
    /** Agent-versie die dit kent */
    public const AGENT_MIN = '1.82.0';
    /** Na de update hooguit zo lang wachten op de agents die nog moeten terugmelden */
    private const SUMMARY_WAIT = 600;

    /** Maximale wachttijd van de agents (minuten), Instellingen → Bijwerken: 5–60, standaard 5 */
    public static function minutes(): int
    {
        return max(5, min(60, (int) setting('update_hold_min', '5')));
    }

    public static function get(): ?array
    {
        $v = q("SELECT value FROM settings WHERE name = 'update_hold'")->fetchColumn();
        settings();   // 1.206.1: eerst alles laden, anders lijkt de rest van de instellingen leeg
        $GLOBALS['rz_settings']['update_hold'] = $v === false ? '' : (string) $v;
        $h = json_decode((string) $v, true);
        return is_array($h) && !empty($h['id']) ? $h : null;
    }

    private static function save(?array $h): void
    {
        save_setting('update_hold', $h ? (string) json_encode($h) : '');
    }

    /** Agents die het moeten horen: versleuteld, nieuw genoeg en de laatste 3 minuten gezien. @return int[] */
    public static function expected(): array
    {
        $ids = [];
        foreach (q("SELECT id, agent_version FROM hosts WHERE enabled = 1 AND enc_seen = 1 AND agent_seen >= ?", [date('Y-m-d H:i:s', time() - 180)])->fetchAll() as $r) {
            if ($r['agent_version'] && version_compare((string) $r['agent_version'], self::AGENT_MIN, '>=')) {
                $ids[] = (int) $r['id'];
            }
        }
        return $ids;
    }

    /** Agents die de laatste 10 minuten rapporteerden maar niet kunnen wachten (te oud of onversleuteld). @return string[] */
    public static function oldAgents(): array
    {
        $out = [];
        foreach (q("SELECT name, agent_version, enc_seen FROM hosts WHERE enabled = 1 AND agent_seen >= ? ORDER BY name", [date('Y-m-d H:i:s', time() - 600)])->fetchAll() as $r) {
            if (!(int) $r['enc_seen'] || !$r['agent_version'] || version_compare((string) $r['agent_version'], self::AGENT_MIN, '<')) {
                $out[] = (string) $r['name'];
            }
        }
        return $out;
    }

    /** Update aankondigen; het verzoek ($req) gaat later naar de hulpdienst (tick). */
    public static function announce(array $req, string $from, ?string $to): void
    {
        q("DELETE FROM settings WHERE name LIKE 'update_hold_ack:%'");
        $h = ['id' => bin2hex(random_bytes(6)), 'from' => $from, 'to' => $to, 'at' => time(), 'until' => time() + self::minutes() * 60,
            'req' => $req, 'need' => self::expected(), 'started' => 0, 'done' => 0];
        self::save($h);
        $label = $to ?: t('de nieuwste versie');
        Monitor::sysInfo('rzmon_update', null, null, 'ℹ️ INFO: RzMon', fn() => t('RzMon-update gestart ({from} → {to}). Agents houden hun metingen vast tot de vrijgave (hooguit {n} min).',
            ['from' => $from, 'to' => $label, 'n' => self::minutes()]), null, null, true);
        self::tick();
    }

    /** @return array<int, array> per host: ack (gehoord), wait/q (na de update, zoals de agent het meldt) */
    private static function acks(): array
    {
        $out = [];
        foreach (q("SELECT name, value FROM settings WHERE name LIKE 'update_hold_ack:%'")->fetchAll() as $r) {
            $v = json_decode((string) $r['value'], true);
            if (is_array($v)) {
                $out[(int) substr((string) $r['name'], 16)] = $v;
            }
        }
        return $out;
    }

    /** Hoeveel van de verwachte agents het al weten: [gehoord, verwacht] */
    public static function heard(?array $h = null): array
    {
        $h ??= self::get();
        if (!$h) {
            return [0, 0];
        }
        $acks = self::acks();
        $need = array_map('intval', (array) ($h['need'] ?? []));
        return [count(array_intersect($need, array_keys($acks))), count($need)];
    }

    /** Het verzoek neerzetten zodra iedereen het weet, of na LEAD seconden (cron, statusvraag en agents roepen dit aan) */
    public static function tick(): void
    {
        $h = self::get();
        if (!$h || !empty($h['started'])) {
            return;
        }
        [$heard, $need] = self::heard($h);
        if ($heard < $need && time() - (int) $h['at'] < self::LEAD) {
            return;
        }
        // Eén keer: wie de instelling als eerste van 0 naar het tijdstip zet, zet het verzoek neer
        $h['started'] = time();
        $n = q("UPDATE settings SET value = ? WHERE name = 'update_hold' AND value = ?", [json_encode($h), setting('update_hold', '')])->rowCount();
        settings();
        $GLOBALS['rz_settings']['update_hold'] = json_encode($h);
        if ($n === 1 && ($err = Updater::writeRequest((array) $h['req'])) !== true) {
            save_setting('update_request_error', (string) json_encode(['at' => time(), 'msg' => t('RzMon-update niet gestart: {err}. Agents zijn vrijgegeven.', ['err' => $err])]));   // 1.206.1: in het venster
            self::save(null);
            Monitor::infoSystem(fn() => t('RzMon-update niet gestart: {err}. Agents zijn vrijgegeven.', ['err' => $err]));
        }
    }

    /** Staat er een aangekondigde update klaar die nog niet aan de hulpdienst is gegeven? */
    public static function pending(): bool
    {
        $h = self::get();
        return $h !== null && empty($h['started']) && empty($h['done']);
    }

    /** Antwoord aan een agent: rz_hold zolang de update loopt (alleen versleuteld), en zijn eigen melding na afloop bewaren */
    public static function forAgent(array $host, bool $encrypted, array $in): array
    {
        $h = self::get();
        if (!$h) {
            return [];
        }
        $key = 'update_hold_ack:' . (int) $host['id'];
        $cur = json_decode((string) q('SELECT value FROM settings WHERE name = ?', [$key])->fetchColumn(), true) ?: [];
        // Na de update meldt de agent hoe lang hij wachtte en hoeveel metingen in zijn wachtrij stonden
        $back = is_array($in['hold'] ?? null) ? $in['hold'] : null;
        if ($back && ($back['id'] ?? '') === $h['id']) {
            $since = (int) ($back['since'] ?? 0);
            $cur['wait'] = $since > 0 && $since <= time() ? time() - $since : 0;
            $cur['q'] = max(0, min(100000, (int) ($back['q'] ?? 0)));
        }
        if (!empty($h['done']) || time() >= (int) $h['until'] || !$encrypted) {
            if ($back) {
                $cur['back'] = time();
                save_setting($key, (string) json_encode($cur));
            }
            return [];
        }
        $cur['ack'] = $cur['ack'] ?? time();
        save_setting($key, (string) json_encode($cur));
        self::tick();
        return ['rz_hold' => ['id' => (string) $h['id'], 'max' => max(30, (int) $h['until'] - time()), 'to' => (string) ($h['to'] ?? '')]];
    }

    /** RzMon draait de nieuwe versie (Installer): klaar; true = er was een aankondiging (de samenvatting volgt later) */
    public static function finished(string $from): bool
    {
        $h = self::get();
        if (!$h || !empty($h['done'])) {
            return false;
        }
        $h['done'] = time();
        $h['to'] = $h['to'] ?: RZMON_VERSION;
        $h['from'] = $h['from'] ?: $from;
        self::save($h);
        return true;
    }

    /** Valt de stilte van een agent (laatst gezien $seen) in een update? Dan geen "agent stil" (tot 5 min na afloop). */
    public static function covers(string $seen): bool
    {
        static $h = false;
        if ($h === false) {
            $h = self::get();   // één keer per verzoek: dit wordt per check gevraagd
        }
        if (!$h || strtotime($seen) < (int) $h['at'] - 600) {
            return false;
        }
        $end = !empty($h['done']) ? (int) $h['done'] + 300 : (int) $h['until'];
        return time() < $end;
    }

    /** Voor het update-venster: wachten op de agents */
    public static function status(): ?array
    {
        $h = self::get();
        if (!$h || !empty($h['started']) || !empty($h['done'])) {
            return null;
        }
        [$heard, $need] = self::heard($h);
        return ['state' => 'queued', 'step' => 'queued', 'at' => (int) $h['at'], 'from' => $h['from'], 'to' => $h['to'],
            'message' => $need ? t('Agents waarschuwen ({n} van {m})', ['n' => $heard, 'm' => $need]) : t('Wachten op de hulpdienst…'),
            'agents' => [$heard, $need]];
    }

    /** Elke minuut (cron): starten als het zover is, samenvatting na afloop, vrijgeven als het misging of te lang duurt */
    public static function cron(): ?string
    {
        self::tick();
        $h = self::get();
        if (!$h) {
            return null;
        }
        if (empty($h['done'])) {
            $st = Updater::status();
            $failed = !empty($h['started']) && $st && $st['state'] === 'error' && (int) ($st['at'] ?? 0) >= (int) $h['started'] - 5;
            // Opnieuw dezelfde versie (of terug zonder versiewissel): klaar zonder versiewissel
            $okSame = !empty($h['started']) && $st && $st['state'] === 'ok' && (int) ($st['at'] ?? 0) >= (int) $h['started'] - 5;
            if ($okSame) {
                self::finished((string) $h['from']);
                return null;
            }
            if ($failed || time() > (int) $h['until'] + 600) {
                self::clear();
                $why = $failed ? (string) ($st['message'] ?? '') : t('de update gaf geen teken van leven');
                Monitor::infoSystem(fn() => t('RzMon-update naar {to} mislukt: {why}. Agents zijn vrijgegeven; RzMon draait nog op {v}.',
                    ['to' => $h['to'] ?: '?', 'why' => $why, 'v' => RZMON_VERSION]));
                return t('Update mislukt; agents vrijgegeven');
            }
            return null;
        }
        // Klaar: wachten tot de agents die het wisten terug zijn (of hooguit SUMMARY_WAIT)
        $acks = self::acks();
        $waited = array_filter($acks, fn($a) => !empty($a['ack']));
        $missing = array_filter($waited, fn($a) => empty($a['back']));
        if ($missing && time() - (int) $h['done'] < self::SUMMARY_WAIT) {
            return null;
        }
        $held = array_filter($waited, fn($a) => (int) ($a['wait'] ?? 0) > 0);
        $names = $acks ? array_column(q('SELECT id, name FROM hosts WHERE id IN (' . implode(',', array_map('intval', array_keys($acks))) . ')')->fetchAll(), 'name', 'id') : [];
        $longId = null;
        foreach ($held as $id => $a) {
            if ($longId === null || (int) $a['wait'] > (int) $held[$longId]['wait']) {
                $longId = $id;
            }
        }
        $dur = max(1, (int) $h['done'] - (int) ($h['started'] ?: $h['at']));
        $q = array_sum(array_map(fn($a) => (int) ($a['q'] ?? 0), $held));
        $parts = [t('RzMon bijgewerkt van {from} naar {to} in {d}', ['from' => $h['from'], 'to' => $h['to'], 'd' => self::secs($dur)])];
        if ($waited) {
            $parts[] = count($waited) === 1 ? t('1 agent wist ervan') : t('{n} agents wisten ervan', ['n' => count($waited)]);
        }
        if ($held) {
            $parts[] = count($held) === 1 ? t('1 agent wachtte en stuurde {q} metingen na', ['q' => $q]) : t('{n} wachtten en stuurden {q} metingen na', ['n' => count($held), 'q' => $q]);
            $parts[] = t('langste wachttijd {d} ({host})', ['d' => self::secs((int) $held[$longId]['wait']), 'host' => $names[$longId] ?? '?']);
        }
        if ($missing) {
            $parts[] = t('nog niet terug: {names}', ['names' => implode(', ', array_map(fn($id) => $names[$id] ?? "#$id", array_keys($missing)))]);
        }
        self::clear();
        $msg = implode(' · ', $parts);
        // Alles goed: alleen op de meldingenpagina; agents die niet terugkwamen: ook via de meldkanalen
        Monitor::sysInfo('rzmon_update', null, null, 'ℹ️ INFO: RzMon', fn() => $msg, null, null, !$missing);
        return $msg;
    }

    private static function clear(): void
    {
        self::save(null);
        q("DELETE FROM settings WHERE name LIKE 'update_hold_ack:%'");
    }

    private static function secs(int $s): string
    {
        return $s >= 120 ? t('{n} min', ['n' => (int) round($s / 60)]) : t('{n} s', ['n' => $s]);
    }
}
