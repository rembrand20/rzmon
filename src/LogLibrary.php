<?php

declare(strict_types=1);

/**
 * 1.250.0: bibliotheek met kant-en-klare vaste zoekacties (Logs → Vaste zoekacties → 📚 Kies uit de bibliotheek).
 * Elk sjabloon heeft de zoekopdracht met de bijbehorende bronnen (bron: … voor Debian, Rocky en de journal),
 * de voorwaarde, de drempel, het venster, de ernst en de rustperiode. Toevoegen maakt gewone vaste zoekacties
 * (LogWatch::save); daarna zijn ze als elke andere aan te passen.
 */
final class LogLibrary
{
    private const SSH = 'bron:auth bron:secure bron:ssh bron:journal';
    private const SYS = 'bron:kern bron:journal bron:messages bron:syslog';
    private const AXIGEN = 'bron:axigen';

    /** Groepen in de volgorde van het venster */
    public static function groups(): array
    {
        return [
            'login' => '🔐 ' . t('Inloggen en toegang'),
            'system' => '🧠 ' . t('Systeem en kernel'),
            'web' => '🌐 ' . t('Webserver (nginx, Apache)'),
            'db' => '🗄️ ' . t('Database'),
            'mail' => '📧 ' . t('Mail'),
            'axigen' => '✉️ ' . t('Axigen-mailserver'),
            'sec' => '🛡️ ' . t('Beveiliging'),
            'must' => '✅ ' . t('Moet juist wél gebeuren'),
        ];
    }

    /**
     * De sjablonen. mode many = te veel treffers (threshold in win minuten), none = geen treffer in win minuten.
     * severity: 0 info, 1 warning, 2 critical. edit = de zoektekst moet je zelf invullen (bv. de tekst van je back-up).
     */
    public static function templates(): array
    {
        $W = STATUS_WARN;
        $C = STATUS_CRIT;
        $I = STATUS_OK;
        $T = static fn(string $key, string $group, string $name, string $why, string $q, string $mode, int $n, int $win, int $sev, int $rest, bool $edit = false) =>
            ['key' => $key, 'group' => $group, 'name' => $name, 'why' => $why, 'q' => $q, 'mode' => $mode, 'threshold' => $n, 'win' => $win,
             'severity' => $sev, 'rest' => $rest, 'edit' => $edit];
        return [
            $T('ssh_bruteforce', 'login', t('SSH: iemand raadt wachtwoorden'), t('Veel mislukte SSH-wachtwoorden in korte tijd: iemand probeert binnen te komen.'),
                '"failed password" ' . self::SSH, 'many', 10, 5, $W, 60),
            $T('ssh_root', 'login', t('SSH: root logt in'), t('Een geslaagde SSH-login als root. Meestal hoort dat niet: log in als gewone gebruiker en gebruik sudo.'),
                '/Accepted \S+ for root from/ ' . self::SSH, 'many', 1, 1, $C, 60),
            $T('ssh_invalid', 'login', t('SSH: onbekende gebruikers'), t('Inlogpogingen met gebruikersnamen die niet bestaan: een scanner probeert namen als admin of test.'),
                '"invalid user" ' . self::SSH, 'many', 20, 15, $W, 60),
            $T('sudo_wrong', 'login', t('sudo: verkeerd wachtwoord'), t('Iemand typt het sudo-wachtwoord meermaals fout. Een vergissing, of iemand die probeert root te worden.'),
                '/sudo.*(incorrect password attempt|authentication failure)/ ' . self::SSH, 'many', 3, 15, $W, 60),
            $T('user_new', 'login', t('Nieuwe gebruiker aangemaakt'), t('Er is een gebruiker bijgekomen op de server. Hoort dat bij iets wat je zelf deed?'),
                '/useradd.*new user|new user: name=/ ' . self::SSH, 'many', 1, 1, $W, 60),
            $T('user_sudo', 'login', t('Gebruiker krijgt sudo of wheel'), t('Een gebruiker is toegevoegd aan de groep sudo of wheel en kan daarmee alles op de server.'),
                '/usermod.*(sudo|wheel)|to group .?(sudo|wheel)/ ' . self::SSH, 'many', 1, 1, $C, 60),

            $T('oom', 'system', t('Geheugen op (OOM-killer)'), t('De kernel heeft een programma gestopt omdat het geheugen op was. Vaak verdwijnt er zo een database of webserver.'),
                '/out of memory|oom-kill|killed process/ ' . self::SYS, 'many', 1, 5, $C, 60),
            $T('disk_io', 'system', t('Schijffouten'), t('Lees- of schrijffouten van een schijf of een beschadigd bestandssysteem. Kijk naar SMART en maak een back-up.'),
                '/I\/O error|EXT4-fs error|XFS.*(error|corruption)|blk_update_request/ ' . self::SYS, 'many', 1, 5, $C, 240),
            $T('fs_ro', 'system', t('Bestandssysteem alleen-lezen'), t('Een bestandssysteem is op alleen-lezen gezet, meestal na een fout. Daarna kan niets er meer naartoe schrijven.'),
                '/remount(ing|ed)? .*read-only/ ' . self::SYS, 'many', 1, 5, $C, 240),
            $T('panic', 'system', t('Kernel loopt vast'), t('Een kernel panic, of taken die minutenlang vastzitten (hung task, soft lockup).'),
                '/kernel panic|blocked for more than \d+ seconds|soft lockup/ ' . self::SYS, 'many', 1, 5, $C, 240),
            $T('svc_crash', 'system', t('Dienst crasht herhaaldelijk'), t('systemd meldt meermaals dat een dienst mislukte of onverwacht stopte.'),
                '/failed with result|Main process exited, code=(killed|dumped)/ bron:journal bron:syslog bron:messages', 'many', 3, 15, $W, 60),
            $T('segfault', 'system', t('Programma crasht (segfault)'), t('Een programma is gecrasht. Af en toe kan, vaak achter elkaar wijst op een fout of een aanval.'),
                'segfault ' . self::SYS, 'many', 3, 60, $W, 240),

            $T('web_5xx', 'web', t('Veel 5xx-fouten'), t('De webserver geeft veel serverfouten (500–599): een site of de app erachter werkt niet goed.'),
                '/" 5\d\d / bron:nginx bron:httpd bron:apache bron:access', 'many', 50, 5, $W, 30),
            $T('web_upstream', 'web', t('Backend niet bereikbaar'), t('nginx kan de app achter zich niet bereiken (proxy, PHP-FPM, container).'),
                '/upstream timed out|connect\(\) failed|no live upstreams/ bron:nginx bron:httpd bron:apache bron:error', 'many', 10, 5, $W, 30),
            $T('web_scan', 'web', t('Scans naar gevoelige paden'), t('Veel verzoeken naar bekende doelen van scanners (.env, .git, wp-login, phpMyAdmin). Meestal ruis, maar goed om te zien.'),
                '/\/\.env|\/\.git\/|wp-login\.php|phpmyadmin|\/cgi-bin\// bron:nginx bron:httpd bron:apache bron:access', 'many', 30, 5, $I, 60),

            $T('db_crash', 'db', t('MariaDB/MySQL crasht'), t('De database is gecrasht of meldt beschadigde gegevens.'),
                '/mysqld got signal|Got signal \d+|InnoDB: (Assertion failure|Database page corruption)/ bron:mariadb bron:mysql bron:journal', 'many', 1, 5, $C, 240),
            $T('db_aborted', 'db', t('Veel afgebroken databaseverbindingen'), t('Verbindingen met MariaDB/MySQL breken af: een app die vastloopt, of iemand die wachtwoorden probeert.'),
                '"aborted connection" bron:mariadb bron:mysql bron:journal', 'many', 50, 60, $W, 240),
            $T('pg_fatal', 'db', t('PostgreSQL: FATAL'), t('PostgreSQL meldt FATAL: een verbinding of de server zelf stopt.'),
                'fatal bron:postgres bron:journal', 'many', 10, 15, $W, 60),

            $T('mail_deferred', 'mail', t('Mail blijft hangen'), t('Uitgaande mail wordt uitgesteld: de ontvanger weigert hem, of je server staat op een blokkadelijst.'),
                '"status=deferred" bron:mail bron:postfix bron:journal', 'many', 20, 60, $W, 240),
            $T('mail_auth', 'mail', t('Inlogfouten op de mailserver'), t('Mislukte logins op SMTP of IMAP: iemand probeert wachtwoorden van mailaccounts.'),
                '/authentication failed|SASL \w+ authentication failed/ bron:mail bron:postfix bron:dovecot bron:journal', 'many', 10, 15, $W, 60),

            // 1.253.0: Axigen (logs in /var/opt/axigen/log, bv. everything.txt; regels als "SMTP-IN:0001A2B3: Authentication failed …")
            $T('ax_auth', 'axigen', t('Axigen: iemand raadt wachtwoorden'), t('Veel mislukte logins op SMTP, IMAP, POP3 of webmail in korte tijd: iemand probeert wachtwoorden van mailaccounts.'),
                '/authentication failed|auth(entication)? (error|failure)|login failed|invalid (password|credentials)/ ' . self::AXIGEN, 'many', 20, 5, $W, 60),
            $T('ax_relay', 'axigen', t('Axigen: misbruik als relay'), t('Iemand probeert via je server mail naar andere domeinen te sturen zonder in te loggen (open relay). Axigen weigert het, maar het zegt wie het probeert.'),
                '/relay(ing)? (denied|not (allowed|permitted))/ ' . self::AXIGEN, 'many', 50, 60, $I, 240),
            $T('ax_deferred', 'axigen', t('Axigen: uitgaande mail blijft hangen'), t('Mail naar buiten wordt uitgesteld (een tijdelijke fout bij de ontvanger): de wachtrij groeit.'),
                'SMTP-OUT /deferr|will retry|temporar(y|ily) (error|fail)|try again later|\\b4\\d\\d[ -]4\\.\\d\\.\\d/ ' . self::AXIGEN, 'many', 20, 60, $W, 240),
            $T('ax_blocklisted', 'axigen', t('Axigen: je server staat op een blocklist'), t('Andere mailservers weigeren je mail omdat je IP-adres op een blocklist staat (bv. Spamhaus). Je mail komt dan niet aan.'),
                'SMTP-OUT /spamhaus|blocked using|listed (at|on|in|by)|black ?list|block ?list|poor reputation/ ' . self::AXIGEN, 'many', 3, 60, $C, 240),
            $T('ax_bounce', 'axigen', t('Axigen: veel mail onbestelbaar'), t('Veel mail naar buiten komt definitief niet aan (bestaat niet, mailbox vol of geweigerd).'),
                'SMTP-OUT /permanent(ly)? (error|fail)|user unknown|mailbox (unavailable|full)|\\b5\\d\\d[ -]5\\.\\d\\.\\d/ ' . self::AXIGEN, 'many', 20, 60, $I, 240),
            $T('ax_virus', 'axigen', t('Axigen: virussen gevonden'), t('De virusscanner van Axigen vond besmette mail. Af en toe is normaal; veel tegelijk wijst op een golf.'),
                '/virus (found|detected)|infected|malware (found|detected)/ ' . self::AXIGEN, 'many', 10, 60, $I, 240),
            $T('ax_restart', 'axigen', t('Axigen start of stopt'), t('De Axigen-dienst is gestopt of opnieuw gestart. Hoort dat bij een update of iets wat je zelf deed?'),
                '/(axigen|server) (is )?(starting|started|stopping|stopped|shutting down)|shutdown (initiated|complete)/ ' . self::AXIGEN, 'many', 1, 5, $W, 60),
            $T('ax_license', 'axigen', t('Axigen: licentie verloopt of klopt niet'), t('Axigen meldt dat de licentie verlopen, ongeldig of overschreden is. Dan kan de mailserver functies uitzetten.'),
                '/licen[cs]e.*(expir|invalid|exceed)/ ' . self::AXIGEN, 'many', 1, 1440, $C, 1440),
            $T('ax_disk', 'axigen', t('Axigen: schijf of wachtrij vol'), t('Geen ruimte meer voor mail of de wachtrij is vol: nieuwe mail wordt geweigerd.'),
                '/no space left|disk (is )?full|storage .*full|queue (is )?full/ ' . self::AXIGEN, 'many', 1, 15, $C, 240),
            $T('ax_silent', 'axigen', t('Axigen schrijft niets meer'), t('Een uur lang geen enkele regel in het Axigen-log: de dienst staat stil, of het log wordt niet meer verzameld.'),
                '/./ ' . self::AXIGEN, 'none', 1, 60, $W, 240),

            $T('f2b_bans', 'sec', t('Fail2ban blokkeert veel'), t('Fail2ban heeft veel adressen geblokkeerd: er is een golf aanvallen bezig.'),
                '/\] Ban / bron:fail2ban bron:journal', 'many', 20, 60, $I, 240),
            $T('critical', 'sec', t('Kritieke logregels'), t('Regels met het niveau critical, uit welke bron ook.'),
                'niveau:critical', 'many', 5, 15, $W, 60),

            $T('cron_none', 'must', t('Cron draait niet meer'), t('In een uur geen enkele regel van cron: geplande taken (back-ups, opruimen) draaien misschien niet.'),
                'cron bron:journal bron:syslog bron:cron', 'none', 1, 60, $W, 240),
            $T('logs_silent', 'must', t('Server stuurt geen logs meer'), t('Een uur lang geen enkele logregel: de agent, de server of het verzamelen staat stil.'),
                '/./', 'none', 1, 60, $W, 240),
            $T('backup_none', 'must', t('Back-up niet gelukt'), t('Geen melding van een geslaagde back-up in 26 uur. Vul de tekst in die je back-up schrijft als hij klaar is.'),
                '"backup completed"', 'none', 1, 1560, $W, 1440, true),
        ];
    }

    /**
     * Het venster: de sjablonen met per sjabloon of het bij je servers past (de bronnen bestaan) en of het al toegevoegd is.
     */
    public static function list(): array
    {
        $have = array_flip(array_map('strval', q('SELECT q FROM log_watches')->fetchAll(PDO::FETCH_COLUMN)));
        $haveName = array_flip(array_map('strval', q('SELECT name FROM log_watches')->fetchAll(PDO::FETCH_COLUMN)));   // ook met een eigen zoektekst
        $total = (int) q('SELECT COUNT(*) FROM log_sources')->fetchColumn();
        $out = [];
        foreach (self::templates() as $tp) {
            $p = LogStore::parseQuery($tp['q']);
            [, $sources, $src] = LogStore::scope($p, [], []);
            if ($p['sources']) {
                $ids = array_values(array_filter($sources, static fn($id) => $id > 0));
            } else {
                $ids = array_keys($src);
            }
            $hosts = array_values(array_unique(array_map(static fn($id) => (string) $src[$id]['host'], array_filter($ids, static fn($id) => isset($src[$id])))));
            sort($hosts);
            // Alleen via de journal (die alles bevat)? Dan past het minder zeker dan via een eigen logbestand
            $journalOnly = $p['sources'] && $ids && !array_filter($ids, static fn($id) => isset($src[$id]) && $src[$id]['kind'] !== 'journal');
            $out[] = $tp + ['fits' => count($ids) > 0, 'via_journal' => $journalOnly, 'sources' => count($ids), 'hosts' => $hosts, 'added' => isset($have[$tp['q']]) || isset($haveName[$tp['name']])];
        }
        return ['groups' => self::groups(), 'templates' => $out, 'sources_total' => $total, 'count' => (int) q('SELECT COUNT(*) FROM log_watches')->fetchColumn(), 'max' => LogWatch::MAX];
    }

    /**
     * Sjablonen toevoegen als vaste zoekacties. $items: [{key, threshold?, severity?, q?}] (q alleen bij sjablonen met edit).
     * @return array{added: list<string>, skipped: list<array{name: string, why: string}>}
     */
    public static function add(array $items, string $by): array
    {
        $byKey = array_column(self::templates(), null, 'key');
        $added = [];
        $skipped = [];
        foreach (array_slice($items, 0, 50) as $it) {
            $tp = $byKey[(string) ($it['key'] ?? '')] ?? null;
            if (!$tp) {
                continue;
            }
            $q = $tp['edit'] && trim((string) ($it['q'] ?? '')) !== '' ? trim((string) $it['q']) : $tp['q'];
            if ((int) q('SELECT COUNT(*) FROM log_watches WHERE q = ?', [$q])->fetchColumn() > 0) {
                $skipped[] = ['name' => $tp['name'], 'why' => t('bestaat al')];
                continue;
            }
            $r = LogWatch::save(null, ['name' => $tp['name'], 'q' => $q, 'mode' => $tp['mode'],
                'threshold' => isset($it['threshold']) && (int) $it['threshold'] > 0 ? (int) $it['threshold'] : $tp['threshold'], 'win' => $tp['win'],
                'severity' => isset($it['severity']) ? (int) $it['severity'] : $tp['severity'], 'rest' => $tp['rest'], 'per_host' => 1, 'recover' => 1], $by);
            if (is_string($r)) {
                $skipped[] = ['name' => $tp['name'], 'why' => $r];
                continue;
            }
            $added[] = $tp['name'];
        }
        return ['added' => $added, 'skipped' => $skipped];
    }
}
