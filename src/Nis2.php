<?php
declare(strict_types=1);

/**
 * NIS2 (1.232.0): een hulpmiddel bij de zorgplicht uit artikel 21 van de NIS2-richtlijn (in Nederland de Cyberbeveiligingswet).
 * - De tien maatregelen van art. 21 lid 2 (a–j), elk met wat RzSense er zelf van ziet (bewijs uit de instellingen en
 *   metingen) en een eigen notitie per maatregel (wat je verder geregeld hebt, met wie en wanneer). Je kunt een
 *   maatregel ook zelf als geregeld markeren.
 * - De meldplicht (art. 23): een incident markeer je als "significant"; RzSense telt af naar de vroegtijdige
 *   waarschuwing (24 uur), de incidentmelding (72 uur) en het eindverslag (een maand na de melding), en geeft een melding
 *   als een termijn dichtbij komt. Je vinkt aan wat je verstuurd hebt; alles komt in het logboek.
 * - Een NIS2-rapport (HTML, af te drukken als PDF) bij Rapporten.
 * Dit is geen juridisch advies en geen certificering: het laat zien wat RzSense technisch ziet.
 * Aan/uit: nis2_on (Instellingen → Overzicht → NIS2 of Overzicht → Functies).
 */
final class Nis2
{
    public const CODES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];

    public static function enabled(): bool
    {
        return setting('nis2_on', '1') === '1';
    }

    /** [titel, uitleg] van een maatregel (art. 21 lid 2) */
    public static function measure(string $c): array
    {
        return match ($c) {
            'a' => [t('Risicoanalyse en beveiligingsbeleid'), t('Beleid voor risicoanalyse en de beveiliging van je informatiesystemen.')],
            'b' => [t('Incidentbehandeling'), t('Incidenten opmerken, beoordelen, afhandelen en melden.')],
            'c' => [t('Bedrijfscontinuïteit'), t('Back-ups, herstel na een ramp en crisisbeheer.')],
            'd' => [t('Beveiliging van de toeleveringsketen'), t('Afspraken met leveranciers en dienstverleners over beveiliging.')],
            'e' => [t('Veilig aanschaffen, ontwikkelen en onderhouden'), t('Ook het omgaan met en melden van kwetsbaarheden.')],
            'f' => [t('Beoordelen of de maatregelen werken'), t('Beleid en procedures om de effectiviteit te toetsen.')],
            'g' => [t('Cyberhygiëne en training'), t('Basispraktijken en bewustwording van medewerkers.')],
            'h' => [t('Cryptografie en versleuteling'), t('Beleid en procedures voor het gebruik van versleuteling.')],
            'i' => [t('Personeel, toegang en bedrijfsmiddelen'), t('Wie mag waarbij, en weten wat je hebt (inventaris).')],
            'j' => [t('Meerfactorauthenticatie en beveiligde communicatie'), t('MFA, beveiligde spraak-, video- en tekstcommunicatie, en noodcommunicatie.')],
            default => [$c, ''],
        };
    }

    /** Wat RzSense van een maatregel ziet: lijst van [goed?, tekst, link] */
    private static function evidence(string $c): array
    {
        $hosts = (int) q("SELECT COUNT(*) FROM hosts WHERE mode = 'agent' AND enabled = 1")->fetchColumn();
        switch ($c) {
            case 'a':
                $sc = array_filter(array_column(Hardening::scores(), 'score'), static fn($s) => $s !== null);
                $avg = $sc ? (int) round(array_sum($sc) / count($sc)) : null;
                return [
                    [$avg !== null && $avg >= 80, $avg === null ? t('Nog geen hardening-score (agent 1.57.0 of nieuwer)') : t('Gemiddelde hardening-score {n}/100 (CIS-controles per server)', ['n' => $avg]), 'ids'],
                    [Vulns::enabled(), Vulns::enabled() ? t('Kwetsbaarheden worden elke dag gezocht') : t('Kwetsbaarheden zoeken staat uit'), 'vulns'],
                    [Eol::enabled(), Eol::enabled() ? t('End-of-life wordt bijgehouden') : t('End-of-life staat uit'), 'vulns/eol'],
                ];
            case 'b':
                $old = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE status = 'open' AND severity >= 2 AND created_at < NOW() - INTERVAL 24 HOUR")->fetchColumn();
                $chan = setting('pushover_enabled', '0') === '1' || setting('email_enabled', '0') === '1' || setting('ntfy_enabled', '0') === '1';
                return [
                    [Ids::enabled(), Ids::enabled() ? t('IDS en endpoint-bewaking staan aan') : t('Het IDS staat uit'), 'ids'],
                    [$chan, $chan ? t('Meldingen gaan naar Pushover, ntfy of e-mail') : t('Geen meldkanaal (Pushover, ntfy of e-mail) ingesteld'), 'settings/channels'],
                    [$old === 0, $old ? t('{n} critical incidenten staan langer dan 24 uur open', ['n' => $old]) : t('Geen critical incidenten die langer dan 24 uur open staan'), 'ids'],
                    [true, t('Meldtermijnen (24 uur, 72 uur, een maand) worden hieronder bijgehouden'), null],
                ];
            case 'c':
                $bk = setting('backup_freq', 'off') !== 'off';
                $rs = Ransom::enabled() && (int) q("SELECT COUNT(*) FROM hosts WHERE ransom_paths IS NOT NULL AND ransom_paths <> '[]'")->fetchColumn() > 0;
                return [
                    [$bk, $bk ? t('RzSense maakt automatisch een back-up van zichzelf') : t('Geen automatische back-up van RzSense'), 'settings/backup'],
                    [$rs, $rs ? t('Ransomware-bewaking met snapshot staat aan op je shares') : t('Nog geen mappen bewaakt op ransomware'), 'settings/ids_ransom'],
                    [null, t('Back-ups van je eigen gegevens, een herstelplan en crisisbeheer: leg dat vast in de notitie'), null],
                ];
            case 'd':
                $sign = AgentSign::status();
                return [
                    [$sign['valid'], $sign['valid'] ? t('Updates van de agent zijn ondertekend') : t('Updates van de agent zijn niet (goed) ondertekend'), 'settings/agents'],
                    [Vulns::enabled(), t('Software van leveranciers wordt op bekende lekken gecontroleerd'), 'vulns'],
                    [null, t('Afspraken met leveranciers (verwerkersovereenkomst, beveiligingseisen): leg dat vast in de notitie'), null],
                ];
            case 'e':
                $kev = (int) q('SELECT COUNT(DISTINCT f.host_id, f.cve) FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve WHERE c.kev = 1 AND f.fixed_version IS NOT NULL')->fetchColumn();
                $auto = (int) q("SELECT COUNT(*) FROM hosts WHERE mode = 'agent' AND patch_mode IN ('window', 'auto')")->fetchColumn();
                return [
                    [$kev === 0, $kev ? t('{n} actief misbruikte lekken (KEV) met een update die nog niet geïnstalleerd is', ['n' => $kev]) : t('Geen openstaande actief misbruikte lekken (KEV)'), 'vulns'],
                    [Patch::enabled() && $auto > 0, t('{n} van {m} servers krijgen beveiligingsupdates in het onderhoudsvenster of dagelijks', ['n' => $auto, 'm' => $hosts]), 'vulns/patch'],
                    [AppControl::enabled(), AppControl::enabled() ? t('Onbekende programma\'s worden opgemerkt') : t('Onbekende programma\'s staat uit'), 'ids/apps'],
                ];
            case 'f':
                $days = ActionLog::days();
                return [
                    [Analytics::reportOn(), Analytics::reportOn() ? t('Er gaat elke maand een rapport uit') : t('Geen maandrapport ingesteld'), 'settings/reports'],
                    [$days >= 365, t('Het logboek per server wordt {n} dagen bewaard', ['n' => $days]), 'settings/actlog'],
                    [true, t('Het wijzigingslogboek houdt bij wie wat veranderde'), 'settings/actlog'],
                ];
            case 'g':
                return [
                    [Hibp::enabled(), Hibp::enabled() ? t('E-mailadressen worden gecontroleerd op datalekken') : t('Datalekken controleren staat uit'), 'settings/hibp'],
                    [null, t('Training en bewustwording van medewerkers: leg dat vast in de notitie (wie, wanneer)'), null],
                ];
            case 'h':
                $ms = MailSec::enabled();
                return [
                    [Https::on(), Https::on() ? t('Beheer van RzSense gaat via https') : t('Beheer via https staat nog niet aan'), 'settings/outside'],
                    [true, t('Agents versturen hun rapport versleuteld'), 'settings/agents'],
                    [$ms, $ms ? t('E-mailbeveiliging van je domein (SPF, DKIM, DMARC) wordt gecontroleerd') : t('E-mailbeveiliging staat uit'), 'settings/mailsec'],
                ];
            case 'i':
                $admins = (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                return [
                    [$hosts > 0, t('{n} servers met een agent in de inventaris', ['n' => $hosts]), 'agents'],
                    [NetScan::enabled(), NetScan::enabled() ? t('Apparaten op je netwerk worden gevonden (netwerkscan)') : t('Netwerkscan staat uit'), 'settings/netscan'],
                    [Sudo::enabled(), t('{n} beheerders; root-rechten op servers alleen tijdelijk (tijdelijk sudo)', ['n' => $admins]), 'settings/sudo'],
                ];
            case 'j':
                $users = (int) q('SELECT COUNT(*) FROM users')->fetchColumn();
                $mfa = (int) q('SELECT COUNT(*) FROM users u WHERE u.totp_secret IS NOT NULL OR EXISTS (SELECT 1 FROM passkeys p WHERE p.user_id = u.id)')->fetchColumn();
                return [
                    [$users > 0 && $mfa === $users, t('{n} van {m} gebruikers hebben 2FA of een passkey', ['n' => $mfa, 'm' => $users]), 'settings/users'],
                    [Https::mfaRequired(), Https::mfaRequired() ? t('2FA is verplicht bij inloggen van buiten') : t('2FA is (nog) niet verplicht bij inloggen van buiten'), 'settings/outside'],
                    [null, t('Beveiligde communicatie en noodcommunicatie binnen je organisatie: leg dat vast in de notitie'), null],
                ];
        }
        return [];
    }

    public static function notes(): array
    {
        return (array) json_decode((string) setting('nis2_notes', ''), true);
    }

    /** De tien maatregelen met status: ok, partial, missing (of manual als je hem zelf als geregeld markeerde) */
    public static function overview(): array
    {
        $notes = self::notes();
        $out = [];
        foreach (self::CODES as $c) {
            [$title, $desc] = self::measure($c);
            $ev = self::evidence($c);
            $known = array_filter($ev, static fn($e) => $e[0] !== null);
            $good = count(array_filter($known, static fn($e) => $e[0] === true));
            $n = $notes[$c] ?? [];
            $status = !empty($n['manual']) ? 'manual' : (!$known ? 'missing' : ($good === count($known) && (count($known) === count($ev) || trim((string) ($n['note'] ?? '')) !== '') ? 'ok'
                : ($good > 0 ? 'partial' : 'missing')));
            $out[] = ['code' => $c, 'title' => $title, 'desc' => $desc, 'status' => $status,
                'evidence' => array_map(static fn($e) => ['ok' => $e[0], 'text' => $e[1], 'go' => $e[2]], $ev),
                'note' => (string) ($n['note'] ?? ''), 'manual' => !empty($n['manual']), 'by' => $n['by'] ?? null, 'at' => $n['at'] ?? null];
        }
        $recent = array_map(static fn($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'at' => $r['opened_at'], 'host' => $r['host'], 'status' => $r['status']],
            q('SELECT i.id, i.title, i.opened_at, i.status, h.name AS host FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id WHERE i.nis2 = 0 AND i.opened_at > NOW() - INTERVAL 30 DAY ORDER BY i.opened_at DESC LIMIT 50')->fetchAll());
        return ['on' => self::enabled(), 'measures' => $out, 'incidents' => self::incidents(), 'recent' => $recent, 'org' => (string) setting('nis2_org', ''),
            'csirt' => (string) setting('nis2_csirt', '')];
    }

    public static function saveNote(string $c, string $note, bool $manual, string $by): void
    {
        if (!in_array($c, self::CODES, true)) {
            throw new InvalidArgumentException(t('Onbekende maatregel'));
        }
        $notes = self::notes();
        $before = $notes[$c] ?? null;
        $notes[$c] = ['note' => mb_substr(trim($note), 0, 4000), 'manual' => $manual, 'by' => $by, 'at' => now()];
        save_setting('nis2_notes', json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        ActionLog::by($by, null, 'nis2', t('NIS2: maatregel {c} bijgewerkt', ['c' => strtoupper($c)]), self::measure($c)[0] . ($manual ? ' · ' . t('zelf als geregeld gemarkeerd') : ''));
        ChangeLog::record('setting', 0, 'update', 'NIS2 ' . strtoupper($c), $before, $notes[$c]);
    }

    // ------------------------------------------------------------------ meldplicht (art. 23)

    /** Termijnen van een significant incident: [vroegtijdige waarschuwing, melding, eindverslag] als unix-tijd */
    public static function deadlines(array $i): array
    {
        $at = strtotime((string) $i['nis2_at']);
        $notify = $i['nis2_notify'] ? strtotime((string) $i['nis2_notify']) : $at + 72 * 3600;
        return ['warn' => $at + 24 * 3600, 'notify' => $at + 72 * 3600, 'final' => strtotime('+1 month', $notify)];
    }

    public static function incidents(): array
    {
        $rows = q('SELECT i.*, h.name AS host FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id WHERE i.nis2 = 1 ORDER BY i.nis2_at DESC LIMIT 50')->fetchAll();
        return array_map(static function ($i) {
            $d = self::deadlines($i);
            return ['id' => (int) $i['id'], 'title' => $i['title'], 'host' => $i['host'], 'status' => $i['status'], 'at' => $i['nis2_at'],
                'warn' => $i['nis2_warn'], 'notify' => $i['nis2_notify'], 'final' => $i['nis2_final'],
                'warn_due' => date('Y-m-d H:i:s', $d['warn']), 'notify_due' => date('Y-m-d H:i:s', $d['notify']), 'final_due' => date('Y-m-d H:i:s', $d['final'])];
        }, $rows);
    }

    /** Een incident significant maken (of niet meer) */
    public static function mark(int $id, bool $on, string $by): void
    {
        $i = q('SELECT * FROM incidents WHERE id = ?', [$id])->fetch();
        if (!$i) {
            throw new InvalidArgumentException(t('Incident niet gevonden'));
        }
        q('UPDATE incidents SET nis2 = ?, nis2_at = COALESCE(nis2_at, ?) WHERE id = ?', [$on ? 1 : 0, $on ? $i['opened_at'] : null, $id]);
        ActionLog::by($by, $i['host_id'] ? (int) $i['host_id'] : null, 'nis2', $on ? t('NIS2: incident als significant gemarkeerd') : t('NIS2: incident niet meer significant'), (string) $i['title']);
    }

    /** Vastleggen dat een melding verstuurd is (warn, notify, final) */
    public static function sent(int $id, string $which, bool $on, string $by): void
    {
        if (!in_array($which, ['warn', 'notify', 'final'], true)) {
            throw new InvalidArgumentException(t('Onbekende melding'));
        }
        $i = q('SELECT * FROM incidents WHERE id = ? AND nis2 = 1', [$id])->fetch();
        if (!$i) {
            throw new InvalidArgumentException(t('Incident niet gevonden'));
        }
        q("UPDATE incidents SET nis2_$which = ? WHERE id = ?", [$on ? now() : null, $id]);
        $label = ['warn' => t('vroegtijdige waarschuwing'), 'notify' => t('incidentmelding'), 'final' => t('eindverslag')][$which];
        ActionLog::by($by, $i['host_id'] ? (int) $i['host_id'] : null, 'nis2', $on ? t('NIS2: {w} verstuurd', ['w' => $label]) : t('NIS2: {w} niet verstuurd', ['w' => $label]), (string) $i['title']);
    }

    /** Elk uur: termijnen die binnen 6 uur (24/72 uur) of 3 dagen (eindverslag) verlopen, of verlopen zijn */
    public static function cron(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $n = 0;   // 1.237.0: "al gemeld" via Once
        foreach (q('SELECT * FROM incidents WHERE nis2 = 1 AND nis2_final IS NULL AND nis2_at > NOW() - INTERVAL 70 DAY')->fetchAll() as $i) {
            $d = self::deadlines($i);
            foreach (['warn' => 6 * 3600, 'notify' => 6 * 3600, 'final' => 3 * 86400] as $w => $before) {
                if ($i["nis2_$w"] || time() < $d[$w] - $before) {
                    continue;
                }
                $stage = time() > $d[$w] ? 'late' : 'soon';
                if (!Once::claim('nis2_notified', $i['id'] . ":$w", $stage)) {
                    continue;
                }
                $label = ['warn' => t('vroegtijdige waarschuwing (24 uur)'), 'notify' => t('incidentmelding (72 uur)'), 'final' => t('eindverslag (een maand)')][$w];
                Monitor::sysInfo('nis2', $i['host_id'] ? (int) $i['host_id'] : null, null, ($stage === 'late' ? '⛔ ' : '⏰ ') . t('NIS2: {w}', ['w' => $label]),
                    fn() => ($stage === 'late' ? t('De termijn voor de {w} is verlopen ({d}): {t}', ['w' => $label, 'd' => date('Y-m-d H:i', $d[$w]), 't' => $i['title']])
                        : t('De {w} moet uiterlijk {d} bij het CSIRT zijn: {t}', ['w' => $label, 'd' => date('Y-m-d H:i', $d[$w]), 't' => $i['title']])));
                $n++;
            }
        }
        return $n ? "NIS2: $n termijnen" : null;
    }

    /** Status per maatregel: icoon, tekst, kleur */
    private static function st(): array
    {
        return ['ok' => ['✔', t('geregeld'), '#1fa35d'], 'manual' => ['✔', t('zelf als geregeld gemarkeerd'), '#1fa35d'], 'partial' => ['◐', t('deels'), '#d98a0b'], 'missing' => ['✘', t('ontbreekt'), '#dc3b3b']];
    }

    /** De maatregelen en de meldingen als HTML-blok (alleen inline-opmaak: ook geschikt voor e-mail). @return array{0: string, 1: string} */
    private static function blocks(array $o): array
    {
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mu = 'color:#5f6b7f';
        $rows = '';
        foreach ($o['measures'] as $m) {
            [$ic, $lbl, $col] = self::st()[$m['status']];
            $ev = implode('', array_map(static fn($x) => '<li>' . ($x['ok'] === true ? '✔ ' : ($x['ok'] === false ? '✘ ' : '• ')) . $e($x['text']) . '</li>', $m['evidence']));
            $rows .= '<div style="margin:0 0 14px"><h3 style="font-size:15px;margin:14px 0 2px"><span style="color:' . $col . '">' . $ic . '</span> ' . strtoupper($m['code']) . '. ' . $e($m['title']) . ' <small style="color:' . $col . '">' . $e($lbl) . '</small></h3>'
                . '<p style="' . $mu . ';margin:2px 0">' . $e($m['desc']) . '</p><ul style="margin:4px 0">' . $ev . '</ul>'
                . ($m['note'] !== '' ? '<p style="margin:4px 0"><b>' . $e(t('Notitie')) . ':</b> ' . nl2br($e($m['note'])) . ($m['by'] ? ' <span style="' . $mu . '">(' . $e($m['by']) . ', ' . $e($m['at']) . ')</span>' : '') . '</p>' : '') . '</div>';
        }
        $inc = '';
        $td = 'border-bottom:1px solid #e1e6ee;padding:6px;text-align:left;vertical-align:top';
        foreach ($o['incidents'] as $i) {
            $c = static fn($sent, $due) => $sent ? '✔ ' . $e($sent) : '⏳ ' . $e(t('uiterlijk {d}', ['d' => $due]));
            $inc .= '<tr><td style="' . $td . '">' . $e($i['title']) . '<br><span style="' . $mu . '">' . $e($i['host'] ?? '') . ' · ' . $e($i['at']) . '</span></td><td style="' . $td . '">' . $c($i['warn'], $i['warn_due'])
                . '</td><td style="' . $td . '">' . $c($i['notify'], $i['notify_due']) . '</td><td style="' . $td . '">' . $c($i['final'], $i['final_due']) . '</td></tr>';
        }
        $incHtml = $inc !== '' ? '<table style="border-collapse:collapse;width:100%"><tr><th style="' . $td . '">' . $e(t('Incident')) . '</th><th style="' . $td . '">' . $e(t('24 uur')) . '</th><th style="' . $td . '">' . $e(t('72 uur')) . '</th><th style="' . $td . '">' . $e(t('Eindverslag')) . '</th></tr>' . $inc . '</table>'
            : '<p style="' . $mu . '">' . $e(t('Geen significante incidenten.')) . '</p>';
        return [$rows, $incHtml];
    }

    /** De disclaimer onder elk NIS2-rapport */
    private static function disclaimer(): string
    {
        return t('Dit overzicht laat zien wat RzSense technisch ziet van de maatregelen uit artikel 21 van de NIS2-richtlijn (Cyberbeveiligingswet), met je eigen notities. Het is geen juridisch advies en geen certificering.');
    }

    /**
     * 1.234.0: het NIS2-overzicht als gepland rapport (Instellingen → Rapporten): titel, onderwerp, platte tekst,
     * HTML-blok en een korte zin voor push.
     */
    public static function reportPart(): array
    {
        $o = self::overview();
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cnt = array_count_values(array_map(static fn($m) => $m['status'] === 'manual' ? 'ok' : $m['status'], $o['measures']));
        $open = 0;
        foreach ($o['incidents'] as $i) {
            foreach (['warn', 'notify', 'final'] as $w) {
                $open += $i[$w] ? 0 : 1;
            }
        }
        $short = t('{ok} geregeld · {p} deels · {m} ontbreekt', ['ok' => $cnt['ok'] ?? 0, 'p' => $cnt['partial'] ?? 0, 'm' => $cnt['missing'] ?? 0])
            . ($open ? ' · ' . t('{n} meldingen nog te versturen', ['n' => $open]) : '');
        $lines = [$short, ''];
        foreach ($o['measures'] as $m) {
            $lines[] = strtoupper($m['code']) . '. ' . $m['title'] . ': ' . self::st()[$m['status']][1];
            foreach ($m['evidence'] as $x) {
                $lines[] = '   ' . ($x['ok'] === true ? '✔' : ($x['ok'] === false ? '✘' : '•')) . ' ' . $x['text'];
            }
            if ($m['note'] !== '') {
                $lines[] = '   ' . t('Notitie') . ': ' . str_replace("\n", ' ', $m['note']);
            }
        }
        $lines[] = '';
        $lines[] = t('Significante incidenten en meldingen (art. 23)') . ':';
        foreach ($o['incidents'] as $i) {
            $lines[] = '- ' . $i['title'] . ' (' . $i['at'] . ')';
            foreach (['warn' => t('24 uur'), 'notify' => t('72 uur'), 'final' => t('Eindverslag')] as $w => $lbl) {
                $lines[] = "   $lbl: " . ($i[$w] ? '✔ ' . $i[$w] : '⏳ ' . t('uiterlijk {d}', ['d' => $i[$w . '_due']]));
            }
        }
        if (!$o['incidents']) {
            $lines[] = t('Geen significante incidenten.');
        }
        $lines[] = '';
        $lines[] = self::disclaimer();
        [$rows, $incHtml] = self::blocks($o);
        $tile = static fn($l, $v, $c) => '<div class="tile ' . $c . '"><small>' . $e($l) . '</small><b>' . $v . '</b></div>';
        $html = '<div class="tiles">' . $tile(t('geregeld'), (string) ($cnt['ok'] ?? 0), 'g') . $tile(t('deels'), (string) ($cnt['partial'] ?? 0), 'w')
            . $tile(t('ontbreekt'), (string) ($cnt['missing'] ?? 0), 'c') . $tile(t('Meldingen nog te versturen'), (string) $open, $open ? 'c' : 'g') . '</div>'
            . '<p style="border:1px solid #e1e6ee;border-radius:8px;padding:8px 10px;background:#f6f8fb">' . $e(self::disclaimer()) . '</p>'
            . ($o['csirt'] !== '' ? '<p>' . $e(t('Melden bij: {c}', ['c' => $o['csirt']])) . '</p>' : '')
            . $rows . '<h3 style="font-size:15px;margin:16px 0 4px">' . $e(t('Significante incidenten en meldingen (art. 23)')) . '</h3>' . $incHtml;
        return ['title' => t('NIS2-overzicht') . ($o['org'] !== '' ? ' – ' . $o['org'] : ''), 'subject' => '🇪🇺 ' . t('NIS2-overzicht') . ($o['org'] !== '' ? ' – ' . $o['org'] : '') . ' (' . date('Y-m-d') . ')',
            'text' => implode("\n", $lines), 'html' => $html, 'short' => $short];
    }

    /** Het NIS2-rapport: afdrukbare HTML (geen scripts) */
    public static function html(): string
    {
        $o = self::overview();
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        [$rows, $incHtml] = self::blocks($o);
        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><title>' . $e(t('NIS2-overzicht')) . '</title><style>body{font:14px/1.45 system-ui,sans-serif;max-width:900px;margin:2rem auto;color:#18202b}'
            . 'h1{font-size:1.5rem}h2{font-size:1.05rem;margin:1.4rem 0 .2rem}.muted{color:#5f6b7f}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #e1e6ee;padding:.4rem;text-align:left;vertical-align:top}'
            . '.disc{border:1px solid #e1e6ee;border-radius:8px;padding:.6rem .8rem;background:#f6f8fb}</style></head><body>'
            . '<h1>' . $e(t('NIS2-overzicht')) . ($o['org'] !== '' ? ' – ' . $e($o['org']) : '') . '</h1><p class="muted">' . $e(t('Gemaakt door RzSense op {d}', ['d' => date('Y-m-d H:i')])) . '</p>'
            . '<p class="disc">' . $e(self::disclaimer()) . '</p>'
            . $rows . '<h2>' . $e(t('Significante incidenten en meldingen (art. 23)')) . '</h2>' . $incHtml
            . '</body></html>';
    }
}
