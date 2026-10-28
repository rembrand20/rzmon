<?php
declare(strict_types=1);

/**
 * Bescherming (1.115.0): wat het IDS allemaal doet, als kaarten (pagina 🛡️ IDS → Bescherming), en per incident
 * de uitleg in drie delen:
 * - Waarom: de regel in gewone taal, met de drempel die werd overschreden;
 * - Trigger: de feiten die het deden afgaan (uit het incident, zonder de acties);
 * - Actie: wat RzMon deed, met de stand van nu (bevestigd, mislukt, verlopen, opgeheven), of in de modus Leren wat
 *   RzMon in de modus Blokkeren zou hebben gedaan.
 * De acties worden niet apart bewaard maar steeds afgeleid uit de blokkades, gestopte processen, isolatie en status;
 * zo klopt de stand altijd, ook bij oude incidenten.
 */
final class Protect
{
    /** Acties: [icoon, tekst] */
    public const ACTS = ['block' => ['🚫', 'IP blokkeren'], 'kill' => ['⛔', 'proces stoppen'], 'isolate' => ['🧱', 'server isoleren'], 'notify' => ['🔔', 'alleen melden'],
        'close' => ['✔', 'vanzelf afhandelen'], 'preblock' => ['🚫', 'vooraf blokkeren'], 'escalate' => ['⚠️', 'critical maken'], 'report' => ['📣', 'melden bij AbuseIPDB']];
    public const GROUPS = ['login' => ['🔑', 'Inloggen'], 'web' => ['🌐', 'Web'], 'proc' => ['⚙️', 'Processen en bestanden'], 'net' => ['🧭', 'Netwerk en DNS'],
        'anom' => ['🧬', 'Afwijkingen en ketens'], 'ti' => ['🌐', 'Dreigingsinformatie']];
    private static ?array $cards = null;

    private static function rule(array $inc, array $ids): bool
    {
        if ($inc['type'] !== 'edr') {
            return false;
        }
        $r = strstr((string) $inc['dkey'], '|', true);
        return in_array($r, $ids, true);
    }

    private static function ruleWhat(string $id): ?string
    {
        foreach (EdrRules::all() as $r) {
            if ($r['id'] === $id) {
                return t($r['what']);
            }
        }
        foreach (Sigma::rules() as $r) {
            if ($r['id'] === $id) {
                return trim('Sigma: ' . $r['title'] . '. ' . ($r['desc'] ?? ''));
            }
        }
        return null;
    }

    /**
     * Alle kaarten: id, groep, titel, wat (kort), ernst, acties, schakelaar [instelling, standaard, label], m (hoort een
     * incident bij deze kaart?), why (waarom, met drempels), en eventueel stat (eigen cijfer) en attention (aandachtspunt).
     */
    public static function cards(): array
    {
        if (self::$cards !== null) {
            return self::$cards;
        }
        $in = static fn(array $types) => static fn(array $i) => in_array($i['type'], $types, true);
        $c = [
            // 🔑 Inloggen
            ['id' => 'bruteforce', 'g' => 'login', 'title' => 'Brute force op SSH', 'what' => 'Te veel mislukte SSH-logins vanaf één IP.', 'sev' => 1, 'acts' => ['block'],
                'toggle' => null, 'm' => $in(['bruteforce']),
                'why' => static fn() => t('Meer dan {n} mislukte SSH-logins binnen {m} minuten vanaf één IP.', ['n' => (int) setting('ids_fail_count', 10), 'm' => (int) setting('ids_fail_minutes', 10)])
                    . ' ' . t('Blokkade: {h} uur, bij herhaling binnen 30 dagen {d} dagen.', ['h' => (int) setting('ids_block_hours', 24), 'd' => (int) setting('ids_repeat_days', 7)])],
            ['id' => 'breakin', 'g' => 'login', 'title' => 'Inlog na brute force', 'what' => 'Een geslaagde login vanaf een IP dat net veel mislukte pogingen deed.', 'sev' => 2,
                'acts' => ['notify'], 'toggle' => null, 'm' => $in(['breakin'])],
            ['id' => 'decoy', 'g' => 'login', 'title' => 'Honeypot (lokaas)', 'what' => 'Een inlog met een naam die niet bestaat (admin, test …) of een verbinding naar de honeypot-poort: dat doen alleen aanvallers.',
                'sev' => 1, 'acts' => ['block'], 'toggle' => null, 'm' => $in(['decoy_user', 'decoy_port'])],
            ['id' => 'ti_login', 'g' => 'login', 'title' => 'Login vanaf een bekende aanvaller', 'what' => 'Een geslaagde login vanaf een IP dat AbuseIPDB, GreyNoise of de lijsten als aanvaller kennen.',
                'sev' => 2, 'acts' => ['escalate', 'block'], 'toggle' => ['ti_escalate', '1', 'critical maken'],
                'm' => static fn(array $i) => in_array($i['type'], ['ssh_login', 'newip', 'rootlogin'], true) && str_contains((string) $i['detail'], '⚠️')],
            ['id' => 'login_out', 'g' => 'login', 'title' => 'Login van buiten, nieuw IP of als root', 'what' => 'Een geslaagde SSH-login van buiten je netwerk, vanaf een IP dat hier nooit inlogde, of als root.',
                'sev' => 1, 'acts' => ['notify'], 'toggle' => null, 'm' => $in(['ssh_login', 'newip', 'rootlogin'])],
            ['id' => 'blocklists', 'g' => 'login', 'title' => 'Bekende aanvallers vooraf blokkeren', 'what' => 'Spamhaus DROP, FireHOL level 1 en abuse.ch Feodo Tracker staan vooraf in de firewall van de agents.',
                'sev' => 0, 'acts' => ['preblock'], 'toggle' => ['ids_blocklists', '1', 'vooraf blokkeren'], 'm' => null,
                'stat' => static fn() => t('{n} bereiken op de lijsten', ['n' => number_format(IpIntel::status()['bl_n'], 0, ',', '.')])],
            // 🌐 Web
            ['id' => 'web_cve', 'g' => 'web', 'title' => 'Aanval op een lek dat de server heeft', 'what' => 'Een webaanval met een CVE die volgens de kwetsbaarheden-scan op deze server openstaat.',
                'sev' => 2, 'acts' => ['block'], 'toggle' => ['ti_cve', '1', 'critical maken'], 'm' => $in(['web_cve'])],
            ['id' => 'web_payload', 'g' => 'web', 'title' => 'Webaanval met een payload', 'what' => 'Een verzoek via SWAG met een aanval erin: SQL-injectie, XSS, Log4Shell, path traversal, bekende exploits …',
                'sev' => 1, 'acts' => ['block'], 'toggle' => ['edr_block', '1', 'IP blokkeren'], 'm' => $in(['web_payload'])],
            ['id' => 'web_attack', 'g' => 'web', 'title' => 'Verdachte webverzoeken in de logs', 'what' => 'Aanvalspaden, scanners, veel 404\'s of mislukte logins in de logs van SWAG, Home Assistant en Vaultwarden.',
                'sev' => 1, 'acts' => ['block'], 'toggle' => ['weblog_block', '0', 'IP blokkeren'], 'm' => $in(['web_attack'])],
            ['id' => 'scanner', 'g' => 'web', 'title' => 'Onschuldige scanner', 'what' => 'Een webaanval van een bekende, onschuldige scanner (GreyNoise: Shodan, Censys …).',
                'sev' => 1, 'acts' => ['close'], 'toggle' => ['ti_close_benign', '1', 'vanzelf afhandelen'],
                'm' => static fn(array $i) => $i['status'] === 'done' && str_starts_with((string) $i['handled_by'], 'RzMon (') && in_array($i['type'], ['web_attack', 'web_payload'], true)],
            // ⚙️ Processen en bestanden
            ['id' => 'webshell', 'g' => 'proc', 'title' => 'Webshell', 'what' => 'Een webserver die een shell start, of een nieuw script met webshell-code in een webmap.', 'sev' => 2,
                'acts' => ['kill'], 'toggle' => ['edr_kill', '1', 'proces stoppen'],
                'm' => static fn(array $i) => $i['type'] === 'webshell' || self::rule($i, ['rz-webshell', 'rz-drop-webshell'])],
            ['id' => 'revshell', 'g' => 'proc', 'title' => 'Reverse shell of cryptominer', 'what' => 'Een shell die naar buiten belt, of een programma dat cryptomunten maakt.', 'sev' => 2,
                'acts' => ['kill', 'isolate'], 'toggle' => ['edr_isolate', '1', 'server isoleren'],
                'm' => static fn(array $i) => $i['type'] === 'revshell' || self::rule($i, ['rz-revshell', 'rz-miner', 'rz-net-shell', 'rz-net-port'])],
            ['id' => 'dropper', 'g' => 'proc', 'title' => 'Binnenhalen en uitvoeren', 'what' => 'curl/wget … | sh, programma\'s uit /tmp of /dev/shm, verborgen programma\'s.', 'sev' => 2,
                'acts' => ['kill'], 'toggle' => ['edr_kill', '1', 'proces stoppen'],
                'm' => static fn(array $i) => $i['type'] === 'proc_suspicious' || self::rule($i, ['rz-dlexec', 'rz-shm-exec', 'rz-tmp-exec', 'rz-hidden-exec', 'rz-drop-hidden', 'rz-drop-elf'])],
            ['id' => 'malware', 'g' => 'proc', 'title' => 'Bekende malware', 'what' => 'Een nieuw bestand dat YARA of MalwareBazaar als malware herkent.', 'sev' => 2,
                'acts' => ['notify'], 'toggle' => ['edr_yara', '1', 'YARA-regels'],
                'm' => static fn(array $i) => $i['type'] === 'ti_malware' || self::rule($i, ['rz-yara', 'rz-yara-info'])],
            ['id' => 'persist', 'g' => 'proc', 'title' => 'Achterdeuren en wijzigingen', 'what' => 'Nieuwe gebruikers, sudo, SSH-sleutels, cron, systemd, kernelmodules, SUID en gewijzigde bestanden.',
                'sev' => 1, 'acts' => ['notify'], 'toggle' => ['ids_persist', '1', 'achterdeuren zoeken'],
                'm' => static fn(array $i) => in_array($i['type'], ['file', 'fim', 'fim_bulk', 'user_new', 'group_add', 'suid_new', 'module_new', 'pkg_changed'], true)
                    || self::rule($i, ['rz-persist', 'rz-sshkey', 'rz-preload', 'rz-kmod', 'rz-setuid', 'rz-history'])],
            ['id' => 'containers', 'g' => 'proc', 'title' => 'Containers', 'what' => 'Een shell in een container, een container met volledige rechten, of uitbreken naar de server.',
                'sev' => 1, 'acts' => ['notify'], 'toggle' => null, 'm' => static fn(array $i) => self::rule($i, ['rz-ctr-priv', 'rz-ctr-exec', 'rz-escape'])],
            ['id' => 'sigma', 'g' => 'proc', 'title' => 'Sigma-regels', 'what' => '121 Linux-regels van SigmaHQ; bij een critical regel stopt RzMon het proces.', 'sev' => 1,
                'acts' => ['notify', 'kill'], 'toggle' => ['edr_sigma', '1', 'Sigma-regels'],
                'm' => static fn(array $i) => $i['type'] === 'edr' && !str_starts_with((string) $i['dkey'], 'rz-')],
            ['id' => 'harden', 'g' => 'proc', 'title' => 'Beveiliging verslechterd', 'what' => 'Een instelling van de server werd minder veilig (SSH, firewall, updates …).', 'sev' => 1,
                'acts' => ['notify'], 'toggle' => ['ids_harden', '1', 'controleren'], 'm' => $in(['harden'])],
            // 🧭 Netwerk en DNS
            ['id' => 'c2', 'g' => 'net', 'title' => 'Verbinding met een botnet-server', 'what' => 'Een programma praat met een adres op de lijsten (Feodo, ThreatFox, Spamhaus …).', 'sev' => 2,
                'acts' => ['kill'], 'toggle' => ['ti_block', '1', 'ingrijpen'], 'm' => $in(['ti_ip', 'out_bad'])],
            ['id' => 'outbound', 'g' => 'net', 'title' => 'Uitgaande verbindingen en poorten', 'what' => 'Verbindingen naar malware-poorten of een ongewoon land, en nieuwe luisterpoorten.', 'sev' => 1,
                'acts' => ['notify'], 'toggle' => ['ids_outbound', '1', 'controleren'], 'm' => $in(['outbound', 'out_country', 'port_new', 'kev_port'])],
            ['id' => 'suricata', 'g' => 'net', 'title' => 'Suricata', 'what' => 'Alerts van Suricata op een server; ernst 1 is critical.', 'sev' => 2, 'acts' => ['block'],
                'toggle' => ['suricata_block', '1', 'IP blokkeren'], 'm' => $in(['suricata'])],
            ['id' => 'dns', 'g' => 'net', 'title' => 'DNS via AdGuard', 'what' => 'Elk apparaat: domeinen van malware of phishing, DNS-tunnels en verzonnen domeinen.', 'sev' => 2,
                'acts' => ['notify'], 'toggle' => ['dns_on', '1', 'DNS-controle'], 'm' => $in(['dns_bad', 'dns_odd']),
                'attention' => static fn() => NetLinks::adguardOn() ? null : t('Nog geen AdGuard gekoppeld (Koppelingen → AdGuard).')],
            // 🧬 Afwijkingen en ketens
            ['id' => 'anomaly', 'g' => 'anom', 'title' => 'Afwijkingen', 'what' => 'Iets wat een server in de leerperiode nooit deed.', 'sev' => 1, 'acts' => ['notify'],
                'toggle' => ['edr_anom', '1', 'afwijkingen'], 'm' => $in(['anomaly']),
                'why' => static fn() => t('Na {d} dagen leren: iets wat deze server nog nooit deed, met een score van {n} of hoger (lager staat alleen op de pagina).',
                    ['d' => Anomaly::learnDays(), 'n' => (int) setting('edr_anom_min', '40')])],
            ['id' => 'chain_act', 'g' => 'anom', 'title' => 'Keten: webshell of binnengehaald programma', 'what' => 'Webaanval → code op de webserver, of binnenhalen → contact naar buiten.', 'sev' => 2,
                'acts' => ['block', 'isolate'], 'toggle' => ['edr_chain_act', '1', 'ingrijpen'],
                'm' => static fn(array $i) => $i['type'] === 'chain' && (str_starts_with((string) $i['dkey'], 'web|') || $i['dkey'] === 'dropper')],
            ['id' => 'chain', 'g' => 'anom', 'title' => 'Andere ketens', 'what' => 'Inloggen → achterdeur, meerdere stappen van een aanval, veel afwijkingen, verder het netwerk in.',
                'sev' => 2, 'acts' => ['notify'], 'toggle' => ['edr_chain', '1', 'ketens'], 'm' => $in(['chain'])],
            // 🌐 Dreigingsinformatie
            ['id' => 'ti_block', 'g' => 'ti', 'title' => 'Bekende aanvaller', 'what' => 'Het IP van een incident is volgens AbuseIPDB, GreyNoise, Shodan of abuse.ch een aanvaller.', 'sev' => 0,
                'acts' => ['block'], 'toggle' => ['ti_block', '1', 'IP blokkeren'],
                'm' => static fn(array $i) => str_contains((string) $i['detail'], '🌐 ' . t('bekende aanvaller')),
                'why' => static fn() => t('Een IP met een score van {n} of hoger (AbuseIPDB-percentage, of 80–90 bij GreyNoise, Shodan en abuse.ch).', ['n' => (int) setting('ti_block_score', '80')]),
                'attention' => static fn() => ThreatIntel::on() && trim((string) setting('ti_abuseipdb_key', '')) === '' ? t('Nog geen AbuseIPDB-sleutel (Instellingen → IDS → Dreigingsinformatie).') : null],
            ['id' => 'ti_report', 'g' => 'ti', 'title' => 'Melden bij AbuseIPDB', 'what' => 'Wie RzMon automatisch blokkeert, gaat naar AbuseIPDB (hooguit één keer per dag per IP).', 'sev' => 0,
                'acts' => ['report'], 'toggle' => ['ti_report', '0', 'melden'], 'm' => null,
                'stat' => static fn() => t('{n} gemeld', ['n' => ThreatIntel::status()['reported']])],
        ];
        return self::$cards = $c;
    }

    /** De kaart van een incident (de eerste die past), of null */
    public static function cardFor(array $inc): ?array
    {
        foreach (self::cards() as $c) {
            if ($c['m'] && ($c['m'])($inc)) {
                return $c;
            }
        }
        return null;
    }

    /** Waarom: de regel in gewone taal (eigen regel, Sigma, keten, of de kaart) */
    private static function why(array $inc, ?array $card): string
    {
        if ($inc['type'] === 'edr' && ($w = self::ruleWhat((string) strstr((string) $inc['dkey'], '|', true)))) {
            return $w;
        }
        if ($inc['type'] === 'chain') {
            $sc = Chain::scenarios()[explode('|', (string) $inc['dkey'])[0]] ?? null;
            if ($sc) {
                return t($sc['what']);
            }
        }
        if ($card && isset($card['why'])) {
            return ($card['why'])();
        }
        return $card ? t($card['what']) : '';
    }

    /** Trigger: de feiten uit het incident, zonder wat RzMon deed en zonder de uitleg die al bij Waarom staat */
    private static function trigger(array $inc, string $why): string
    {
        $parts = array_filter(array_map('trim', explode(' · ', (string) $inc['detail'])), static fn($p) => $p !== '' && $p !== $why
            && !preg_match('/^(⛔|⚠️|🚫|🧱)|modus Leren|Learn mode|^Sigma-regel van|^Sigma rule by/u', $p));
        return mb_substr(implode(' · ', $parts), 0, 600);
    }

    /**
     * Wat RzMon deed, met de stand van nu. $ctx (optioneel, voor een lange lijst): voorgeladen blokkades, gestopte
     * processen en isolatie. @return array<array{t: string, icon: string, text: string, ok: ?bool}>
     */
    public static function actions(array $inc, ?array $card, ?array $ctx = null): array
    {
        $out = [];
        $from = strtotime((string) $inc['created_at']) - 300;
        $to = strtotime((string) ($inc['last_seen'] ?? $inc['created_at'])) + 600;
        $ip = (string) ($inc['ip'] ?? '');
        // Blokkades van dit IP rond de tijd van het incident (alle servers)
        if ($ip !== '') {
            $all = $ctx !== null ? ($ctx['blocks'][$ip] ?? []) : q('SELECT b.*, h.name AS host FROM ids_blocks b JOIN hosts h ON h.id = b.host_id WHERE b.ip = ?', [$ip])->fetchAll();
            $rows = array_filter($all, static fn($b) => (int) $b['auto'] === 1 && ($t = strtotime((string) $b['created_at'])) >= $from && $t <= $to);
            $earlier = array_filter($all, static fn($b) => strtotime((string) $b['created_at']) < $from && $b['removed_at'] === null
                && ($b['until'] === null || strtotime((string) $b['until']) > strtotime((string) $inc['created_at'])));
            if (!$rows && $earlier) {
                $until = max(array_map(static fn($b) => (string) ($b['until'] ?? '9999'), $earlier));
                $out[] = ['t' => 'block', 'icon' => '🚫', 'ok' => true, 'text' => $until === '9999' ? t('IP was al geblokkeerd (permanent)')
                    : (strtotime($until) > time() ? t('IP was al geblokkeerd tot {t}', ['t' => date('d-m H:i', strtotime($until))]) : t('IP was toen al geblokkeerd'))];
            }
            if ($rows) {
                $active = array_filter($rows, static fn($b) => $b['removed_at'] === null && ($b['until'] === null || strtotime((string) $b['until']) > time()));
                $removed = array_filter($rows, static fn($b) => $b['removed_at'] !== null);
                $hosts = count(array_unique(array_column($rows, 'host_id')));
                $on = $hosts > 1 ? ' ' . t('op {n} servers', ['n' => $hosts]) : '';
                if ($active) {
                    $until = max(array_map(static fn($b) => (string) ($b['until'] ?? '9999'), $active));
                    $out[] = ['t' => 'block', 'icon' => '🚫', 'ok' => true, 'text' => ($until === '9999' ? t('IP geblokkeerd (permanent)') : t('IP geblokkeerd tot {t}', ['t' => date('d-m H:i', strtotime($until))])) . $on];
                } elseif ($removed) {
                    $b = end($removed);
                    $out[] = ['t' => 'block', 'icon' => '↩', 'ok' => null, 'text' => t('IP gedeblokkeerd door {by} ({t})', ['by' => $b['removed_by'] ?? '?', 't' => date('d-m H:i', strtotime((string) $b['removed_at']))])];
                } else {
                    $out[] = ['t' => 'block', 'icon' => '⌛', 'ok' => null, 'text' => t('blokkade verlopen')];
                }
            }
        }
        // Gestopte processen (endpoint-bewaking, botnet-verbinding)
        $prefix = $inc['type'] === 'edr' ? strstr((string) $inc['dkey'], '|', true) . '|' : ($inc['type'] === 'ti_ip' ? "ti|$ip" : null);
        if ($prefix !== null) {
            $rows = $ctx !== null ? ($ctx['kills'][(int) $inc['host_id']] ?? []) : q('SELECT * FROM ids_kills WHERE host_id = ? AND created_at >= ?', [$inc['host_id'], date('Y-m-d H:i:s', $from)])->fetchAll();
            foreach ($rows as $k) {
                $t = strtotime((string) $k['created_at']);
                if ($t < $from || $t > $to || !str_starts_with((string) $k['dkey'], $prefix)) {
                    continue;
                }
                $res = (string) ($k['result'] ?? '');
                $out[] = $k['done_at'] === null ? ['t' => 'kill', 'icon' => '⛔', 'ok' => null, 'text' => t('proces {pid} wordt gestopt (wacht op de agent)', ['pid' => $k['pid']])]
                    : ['t' => 'kill', 'icon' => '⛔', 'ok' => $res === t('gestopt') ? true : ($res === t('stoppen mislukt') ? false : null),
                        'text' => t('proces {pid}: {res}', ['pid' => $k['pid'], 'res' => $res])];
            }
        }
        // Isolatie
        if (preg_match('/geïsoleerd|isolated/u', (string) $inc['detail'])) {
            $until = $ctx !== null ? ($ctx['iso'][(int) $inc['host_id']] ?? null) : q('SELECT isolate_until FROM hosts WHERE id = ?', [$inc['host_id']])->fetchColumn();
            $out[] = $until && strtotime((string) $until) > time() ? ['t' => 'isolate', 'icon' => '🧱', 'ok' => true, 'text' => t('server geïsoleerd tot {t}', ['t' => date('d-m H:i', strtotime((string) $until))])]
                : ['t' => 'isolate', 'icon' => '🧱', 'ok' => null, 'text' => t('server was geïsoleerd (voorbij of opgeheven)')];
        }
        if (str_contains((string) $inc['detail'], '⚠️')) {
            $out[] = ['t' => 'escalate', 'icon' => '⚠️', 'ok' => true, 'text' => t('critical gemaakt (bekende aanvaller)')];
        }
        if ($inc['status'] === 'done' && str_starts_with((string) $inc['handled_by'], 'RzMon (')) {
            $out[] = ['t' => 'close', 'icon' => '✔', 'ok' => true, 'text' => t('vanzelf afgehandeld: {why}', ['why' => trim(substr((string) $inc['handled_by'], 7), ' ()')])];
        }
        // Niets gedaan terwijl de kaart dat wel kan: in de modus Leren zeggen wat er zou gebeuren
        $can = array_values(array_intersect($card['acts'] ?? [], ['block', 'kill', 'isolate']));
        $did = array_intersect(array_column($out, 't'), ['block', 'kill', 'isolate']);
        if ($can && !$did) {
            $learn = ($inc['mode'] ?? null) === 'l' || preg_match('/modus Leren|Learn mode/u', (string) $inc['detail']);
            $would = implode(', ', array_map(static fn($a) => t(self::ACTS[$a][1]), $can));
            $out[] = $learn ? ['t' => 'learn', 'icon' => '🎓', 'ok' => null, 'text' => t('alleen gemeld (modus Leren; in Blokkeren: {acts})', ['acts' => $would])]
                : ['t' => 'notify', 'icon' => '🔔', 'ok' => null, 'text' => t('alleen gemeld')];
        } elseif (!$out) {
            $out[] = ['t' => 'notify', 'icon' => '🔔', 'ok' => null, 'text' => (int) $inc['severity'] === 2 ? t('gemeld (ook via Pushover)') : t('gemeld op de pagina IDS')];
        }
        return $out;
    }

    /** Alles voor één incident (venster, Pushover) */
    public static function describe(array $inc, ?array $ctx = null): array
    {
        $card = self::cardFor($inc);
        $why = self::why($inc, $card);
        return ['card' => $card ? ['id' => $card['id'], 'title' => t($card['title']), 'g' => $card['g']] : null, 'why' => $why,
            'trigger' => self::trigger($inc, $why), 'actions' => self::actions($inc, $card, $ctx)];
    }

    /** Voor de lijst: compact (alleen de acties), met de gegevens in één keer voorgeladen */
    public static function describeAll(array $incidents): array
    {
        if (!$incidents) {
            return [];
        }
        $since = date('Y-m-d H:i:s', min(array_map(static fn($i) => strtotime((string) $i['created_at']), $incidents)) - 600);
        $ctx = ['blocks' => [], 'kills' => [], 'iso' => array_column(q('SELECT id, isolate_until FROM hosts')->fetchAll(), 'isolate_until', 'id')];
        foreach (q('SELECT b.*, h.name AS host FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                    WHERE b.created_at >= ? OR (b.removed_at IS NULL AND (b.until IS NULL OR b.until >= ?))', [$since, $since])->fetchAll() as $b) {
            $ctx['blocks'][$b['ip']][] = $b;
        }
        foreach (q('SELECT * FROM ids_kills WHERE created_at >= ?', [$since])->fetchAll() as $k) {
            $ctx['kills'][(int) $k['host_id']][] = $k;
        }
        $out = [];
        foreach ($incidents as $i) {
            $card = self::cardFor($i);
            $out[(int) $i['id']] = ['card' => $card['id'] ?? null, 'acts' => array_map(static fn($a) => [$a['icon'], $a['text'], $a['t'], $a['ok']], self::actions($i, $card, $ctx))];
        }
        return $out;
    }

    /** Voor Pushover en de melding: titel blijft, daaronder Waarom / Trigger / Actie */
    public static function pushText(int $incidentId): ?string
    {
        $i = q('SELECT * FROM ids_incidents WHERE id = ?', [$incidentId])->fetch();
        if (!$i) {
            return null;
        }
        $d = self::describe($i);
        return implode("\n", array_filter([
            $d['why'] !== '' ? t('Waarom') . ': ' . mb_substr($d['why'], 0, 300) : null,
            $d['trigger'] !== '' ? t('Trigger') . ': ' . mb_substr($d['trigger'], 0, 400) : null,
            t('Actie') . ': ' . implode(', ', array_map(static fn($a) => $a['icon'] . ' ' . $a['text'], $d['actions'])),
        ]));
    }

    /** De pagina Bescherming: kaarten met aan/uit, cijfers van 7 dagen en aandachtspunten */
    public static function overview(): array
    {
        $recent = q('SELECT id, host_id, severity, type, dkey, title, detail, ip, status, handled_by, created_at, last_seen FROM ids_incidents WHERE created_at >= NOW() - INTERVAL 7 DAY ORDER BY id DESC LIMIT 20000')->fetchAll();
        $n = [];
        $last = [];
        foreach ($recent as $i) {
            foreach (self::cards() as $c) {   // een incident kan bij meer kaarten horen (bv. een login én een bekende aanvaller)
                if ($c['m'] && ($c['m'])($i)) {
                    $n[$c['id']] = ($n[$c['id']] ?? 0) + 1;
                    $last[$c['id']] ??= ['at' => $i['created_at'], 'title' => $i['title'], 'id' => (int) $i['id']];
                }
            }
        }
        // Onderdelen die op sommige servers ontbreken (auditd, inotify)
        $miss = ['audit' => [], 'file' => []];
        foreach (q("SELECT name, edr_info FROM hosts WHERE mode = 'agent' AND edr_on = 1 AND edr_info IS NOT NULL")->fetchAll() as $h) {
            $i = json_decode((string) $h['edr_info'], true) ?: [];
            foreach (['audit', 'file'] as $k) {
                if (($i[$k] ?? '') === 'missing') {
                    $miss[$k][] = $h['name'];
                }
            }
        }
        $edrMiss = $miss['audit'] ? t('auditd ontbreekt op {list}', ['list' => implode(', ', $miss['audit'])]) : null;
        $cards = [];
        foreach (self::cards() as $c) {
            $tog = $c['toggle'];
            $att = isset($c['attention']) ? ($c['attention'])() : null;
            if ($att === null && $c['g'] === 'proc' && $edrMiss && in_array($c['id'], ['webshell', 'revshell', 'dropper', 'persist', 'sigma'], true)) {
                $att = $edrMiss;
            }
            $cards[] = ['id' => $c['id'], 'g' => $c['g'], 'title' => t($c['title']), 'what' => t($c['what']), 'why' => isset($c['why']) ? ($c['why'])() : null, 'sev' => $c['sev'],
                'acts' => array_map(static fn($a) => [self::ACTS[$a][0], t(self::ACTS[$a][1]), $a], $c['acts']),
                'toggle' => $tog ? ['key' => $tog[0], 'on' => setting($tog[0], $tog[1]) === '1', 'label' => t($tog[2])] : null,
                'n' => $n[$c['id']] ?? 0, 'last' => $last[$c['id']] ?? null, 'stat' => isset($c['stat']) ? ($c['stat'])() : null, 'attention' => $att];
        }
        $on = count(array_filter($cards, static fn($c) => !$c['toggle'] || $c['toggle']['on']));
        return ['enabled' => Ids::enabled(), 'mode' => (string) setting('ids_mode', 'learn'), 'cards' => $cards, 'on' => $on, 'total' => count($cards),
            'attention' => count(array_unique(array_filter(array_column($cards, 'attention')))),
            'groups' => array_map(static fn($g) => [$g[0], t($g[1])], self::GROUPS)];
    }

    /** In de tests */
    public static function reset(): void
    {
        self::$cards = null;
    }
}
