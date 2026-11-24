<?php
declare(strict_types=1);

/**
 * 1.295.0: isoleren bij een aanvalsketen: meteen, eerst vragen (aangeraden) of alleen melden.
 *
 * Eerst vragen: RzSense stuurt een melding (met hoge prioriteit) en toont bovenaan RzSense een balk met "Nu isoleren" en
 * "Hoort zo". Kiest niemand binnen de wachttijd (standaard 10 minuten), dan isoleert RzSense alsnog (of meldt alleen, als
 * je dat instelt). Tijdens onderhoud van een server isoleert RzSense nooit automatisch; tijdens een update vraagt het
 * altijd eerst. Een reverse shell, cryptominer of ransomware isoleert altijd meteen (die gaan niet via de keten).
 */
final class IsoAsk
{
    public const MODES = ['now', 'ask', 'notify'];

    /** Wat RzSense nu doet bij een keten op deze server: now, ask of notify */
    public static function mode(int $hid): string
    {
        if (UpdateWindow::maintenance($hid)) {
            return 'notify';
        }
        $m = (string) setting('edr_iso_mode', 'ask');
        $m = in_array($m, self::MODES, true) ? $m : 'ask';
        return $m === 'now' && UpdateWindow::active($hid) ? 'ask' : $m;
    }

    public static function waitMinutes(): int
    {
        return max(1, min(120, (int) setting('edr_iso_wait', '10')));
    }

    /**
     * Een vraag openen (één tegelijk per server) en melden. @return int|null id, of null als er al een open stond
     * @param array<string, mixed> $host
     */
    public static function open(array $host, ?int $incidentId, string $reason): ?int
    {
        $hid = (int) $host['id'];
        if (q("SELECT 1 FROM iso_ask WHERE host_id = ? AND state = 'open'", [$hid])->fetchColumn()) {
            return null;
        }
        $deadline = date('Y-m-d H:i:s', time() + self::waitMinutes() * 60);
        q("INSERT INTO iso_ask (host_id, incident_id, reason, created_at, deadline, state) VALUES (?, ?, ?, ?, ?, 'open')",
            [$hid, $incidentId, mb_substr($reason, 0, 255), now(), $deadline]);
        $id = (int) db()->lastInsertId();
        $name = (string) $host['name'];
        $after = setting('edr_iso_after', 'isolate') === 'notify' ? t('alleen gemeld') : t('geïsoleerd');
        ActionLog::add($hid, 'rzsense', 'isolate', t('Isoleren gevraagd'), $reason, true);
        // 1.320.0: via het beveiligingskanaal als dat aan staat (blijft komen tot iemand bevestigt); de knop opent RzSense
        if (I18n::forNotify(fn() => SecChannel::isoAsk($id, $hid, t('{name} isoleren?', ['name' => $name]),
            t('{reason}. Kies in RzSense "Nu isoleren" of "Hoort zo". Zonder keuze wordt {name} om {t} {after}.',
                ['reason' => $reason, 'name' => $name, 't' => date('H:i', strtotime($deadline)), 'after' => $after])))) {
            return $id;
        }
        Monitor::sysInfo('isolate', $hid, $name, '⚠️ ' . t('{name} isoleren?', ['name' => $name]),
            fn() => t('{reason}. Kies in RzSense "Nu isoleren" of "Hoort zo". Zonder keuze wordt {name} om {t} {after}.',
                ['reason' => $reason, 'name' => $name, 't' => date('H:i', strtotime($deadline)), 'after' => $after]), null, null, false, null, false, 1);
        return $id;
    }

    /** Beslissen: isolate (nu isoleren) of dismiss (hoort zo). @return string|null fout */
    public static function decide(int $id, string $what, string $by, string $byIp = ''): ?string
    {
        $a = q("SELECT a.*, h.name FROM iso_ask a JOIN hosts h ON h.id = a.host_id WHERE a.id = ? AND a.state = 'open'", [$id])->fetch();
        if (!$a) {
            return t('Deze vraag is al beantwoord of verlopen');
        }
        if ($what === 'isolate') {
            q("UPDATE iso_ask SET state = 'isolated', decided_by = ?, decided_at = ? WHERE id = ?", [mb_substr($by, 0, 64), now(), $id]);
            Ids::isolate((int) $a['host_id'], 24, $by, $byIp, (string) $a['reason']);
            return null;
        }
        q("UPDATE iso_ask SET state = 'dismissed', decided_by = ?, decided_at = ? WHERE id = ?", [mb_substr($by, 0, 64), now(), $id]);
        ActionLog::add((int) $a['host_id'], 'rzsense', 'isolate', t('Niet geïsoleerd: hoort zo'), (string) $a['reason'], true, '', null, $by);
        return null;
    }

    /** Elke minuut: vragen waarop niemand reageerde, alsnog isoleren (of alleen melden). @return int aantal */
    public static function cron(): int
    {
        $n = 0;
        foreach (q("SELECT a.*, h.name FROM iso_ask a JOIN hosts h ON h.id = a.host_id WHERE a.state = 'open' AND a.deadline <= NOW()")->fetchAll() as $a) {
            $n++;
            if (setting('edr_iso_after', 'isolate') === 'notify' || UpdateWindow::maintenance((int) $a['host_id'])) {
                q("UPDATE iso_ask SET state = 'expired', decided_at = ? WHERE id = ?", [now(), $a['id']]);
                ActionLog::add((int) $a['host_id'], 'rzsense', 'isolate', t('Niet geïsoleerd: geen antwoord'), (string) $a['reason'], true);
                continue;
            }
            q("UPDATE iso_ask SET state = 'isolated', decided_by = ?, decided_at = ? WHERE id = ?", ['RzSense', now(), $a['id']]);
            Ids::isolate((int) $a['host_id'], 24, 'RzSense (' . t('geen antwoord') . ')', '', (string) $a['reason']);
        }
        q("DELETE FROM iso_ask WHERE state <> 'open' AND created_at < NOW() - INTERVAL 90 DAY");
        return $n;
    }

    /**
     * Open vragen, voor de balk bovenaan.
     * @return list<array<string, mixed>>
     */
    public static function pending(): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'name' => (string) $r['name'], 'reason' => (string) $r['reason'],
            'deadline' => date('c', (int) strtotime((string) $r['deadline'])), 'incident_id' => $r['incident_id'] !== null ? (int) $r['incident_id'] : null,
            'after' => setting('edr_iso_after', 'isolate') === 'notify' ? 'notify' : 'isolate'],
            q("SELECT a.id, a.host_id, a.reason, a.deadline, a.incident_id, h.name FROM iso_ask a JOIN hosts h ON h.id = a.host_id WHERE a.state = 'open' ORDER BY a.deadline")->fetchAll());
    }
}
