<?php
declare(strict_types=1);

/**
 * Hardening-score per server (1.88.0, agent 1.57.0). De agent stuurt elk uur een paar instellingen die het verschil
 * maken; hier staat wat goed is, hoe zwaar het weegt en hoe je het dichtzet. Per server kun je een punt negeren.
 * Wordt een punt slechter (bv. root mag weer via SSH inloggen), dan volgt een IDS-incident; de rest staat alleen in de score.
 */
final class Hardening
{
    /** item => [gewicht, ernst bij verslechtering] */
    private const ITEMS = [
        'ssh_pw' => [3, 1], 'ssh_root' => [3, 1], 'ssh_empty' => [3, 2], 'firewall' => [3, 1], 'autoupdate' => [2, 1],
        'open_db' => [3, 1], 'docker_sock' => [3, 2], 'empty_pw' => [3, 2], 'sudo_nopw' => [1, 1], 'tmp_noexec' => [1, 1], 'selinux' => [1, 1],
    ];

    public static function enabled(): bool
    {
        return setting('ids_harden', '1') === '1';
    }

    /** @return bool|null goed, fout, of null (onbekend op deze server) */
    private static function ok(string $item, ?string $v): ?bool
    {
        if ($v === null) {
            return null;
        }
        return match ($item) {
            'ssh_pw', 'ssh_empty' => $v === '' ? null : $v !== 'yes',
            'ssh_root' => $v === '' ? null : $v !== 'yes',
            'firewall', 'autoupdate' => $v !== 'none',
            'open_db', 'empty_pw', 'sudo_nopw' => $v === '',
            'docker_sock' => $v === 'ok',
            'tmp_noexec' => $v === 'yes',
            'selinux' => $v === '' ? null : strtolower($v) !== 'disabled',
            default => null,
        };
    }

    /** [titel, uitleg en hoe je het dichtzet] */
    public static function text(string $item): array
    {
        return match ($item) {
            'ssh_pw' => [t('SSH: inloggen met een wachtwoord staat uit'), t('Met alleen sleutels heeft wachtwoorden raden geen zin. In /etc/ssh/sshd_config: PasswordAuthentication no, dan systemctl reload sshd. Test eerst of je met je sleutel binnenkomt.')],
            'ssh_root' => [t('SSH: root kan niet direct inloggen'), t('In /etc/ssh/sshd_config: PermitRootLogin no (of prohibit-password), dan systemctl reload sshd. Log in als gewone gebruiker en gebruik sudo.')],
            'ssh_empty' => [t('SSH: geen lege wachtwoorden'), t('In /etc/ssh/sshd_config: PermitEmptyPasswords no.')],
            'firewall' => [t('Firewall actief'), t('Rocky/RHEL: systemctl enable --now firewalld. Debian/Ubuntu: ufw allow ssh && ufw enable. Alleen de poorten open zetten die echt nodig zijn.')],
            'autoupdate' => [t('Automatische beveiligingsupdates'), t('Rocky/RHEL: dnf install dnf-automatic && systemctl enable --now dnf-automatic-install.timer. Debian/Ubuntu: apt install unattended-upgrades && dpkg-reconfigure unattended-upgrades.')],
            'open_db' => [t('Geen database open op alle netwerken'), t('Laat MySQL/MariaDB, PostgreSQL, Redis, MongoDB of Elasticsearch alleen op 127.0.0.1 luisteren (bind-address), of zet de poort dicht in de firewall. Open databases worden binnen uren gevonden.')],
            'docker_sock' => [t('Docker-socket afgeschermd'), t('Wie de Docker-socket kan gebruiken, is root. Niet voor iedereen schrijfbaar maken (chmod 660) en Docker nooit via tcp (poort 2375) zonder TLS.')],
            'empty_pw' => [t('Geen accounts zonder wachtwoord'), t('Een account met een leeg wachtwoord kan zonder iets binnen. Zet het op slot: passwd -l <naam>.')],
            'sudo_nopw' => [t('sudo vraagt altijd een wachtwoord'), t('NOPASSWD in /etc/sudoers(.d) betekent: wie die gebruiker overneemt, is meteen root. Alleen gebruiken voor één vaste opdracht.')],
            'tmp_noexec' => [t('/tmp zonder uitvoeren (noexec)'), t('Veel malware wordt in /tmp gezet en daar gestart. Mount /tmp met noexec,nosuid,nodev (bv. systemctl enable tmp.mount en de opties in /etc/fstab).')],
            'selinux' => [t('SELinux aan'), t('SELinux beperkt wat een gehackte dienst kan. Zet SELINUX=enforcing in /etc/selinux/config en herstart (eerst permissive om te testen).')],
            default => [$item, ''],
        };
    }

    /**
     * Uitgebreid advies (1.101.0): wat is het, waarom belangrijk, hoe controleer je het, hoe zet je het dicht (commando's per
     * systeem) en waar je op moet letten.
     * @return array{what: string, why: string, check: string, fix: string, cmds: list<array{0: string, 1: string}>, caution: string}
     */
    public static function help(string $item): array
    {
        [$title, $short] = self::text($item);
        $h = match ($item) {
            'ssh_pw' => [t('Met SSH kun je inloggen met alleen een wachtwoord.'), t('Wachtwoorden worden op internet de hele dag geraden. Met alleen SSH-sleutels heeft raden geen zin: zonder de sleutel kom je niet binnen.'),
                'sudo sshd -T | grep -i passwordauthentication',
                [[t('1. Sleutel maken (op je eigen computer) en naar de server zetten'), 'ssh-keygen -t ed25519 && ssh-copy-id gebruiker@server'],
                 [t('2. Wachtwoorden uitzetten'), "echo 'PasswordAuthentication no' | sudo tee /etc/ssh/sshd_config.d/10-rzmon.conf && { sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd; }"]],
                t('Test in een tweede venster of je met je sleutel binnenkomt vóór je het oude venster sluit, anders sluit je jezelf buiten.')],
            'ssh_root' => [t('root kan direct inloggen via SSH.'), t('root bestaat op elke server, dus aanvallers hoeven alleen het wachtwoord te raden. Wie als root binnenkomt, kan alles.'),
                'sudo sshd -T | grep -i permitrootlogin',
                [[t('Root-login uitzetten'), "echo 'PermitRootLogin no' | sudo tee /etc/ssh/sshd_config.d/11-rzmon.conf && { sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd; }"]],
                t('Zorg eerst dat je een gewone gebruiker hebt die sudo mag (sudo -v werkt).')],
            'ssh_empty' => [t('SSH staat inloggen met een leeg wachtwoord toe.'), t('Een account zonder wachtwoord is dan van buiten bereikbaar zonder dat iemand iets hoeft te raden.'),
                'sudo sshd -T | grep -i permitemptypasswords',
                [[t('Uitzetten'), "echo 'PermitEmptyPasswords no' | sudo tee /etc/ssh/sshd_config.d/12-rzmon.conf && { sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd; }"]], ''],
            'firewall' => [t('Er draait geen firewall.'), t('Elke dienst die per ongeluk op alle netwerken luistert, is dan meteen bereikbaar. Een firewall laat alleen door wat je openzet.'),
                'sudo ufw status 2>/dev/null; sudo firewall-cmd --state 2>/dev/null; sudo nft list ruleset | head',
                [[t('Debian/Ubuntu'), 'sudo ufw allow OpenSSH && sudo ufw enable'], [t('Rocky/RHEL'), 'sudo systemctl enable --now firewalld && sudo firewall-cmd --list-all']],
                t('Zet SSH (en wat je verder nodig hebt, zoals 80/443) eerst open, anders kom je er niet meer bij. Proxmox heeft een eigen firewall in de webinterface.')],
            'autoupdate' => [t('Beveiligingsupdates worden niet automatisch geïnstalleerd.'), t('De meeste hacks gebruiken lekken waarvoor al een update is. Automatisch bijwerken sluit die binnen een dag.'),
                'systemctl list-timers | grep -E "apt|dnf"',
                [[t('Debian/Ubuntu'), 'sudo apt install -y unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades'],
                 [t('Rocky/RHEL'), "sudo dnf install -y dnf-automatic && sudo sed -i 's/^apply_updates.*/apply_updates = yes/' /etc/dnf/automatic.conf && sudo systemctl enable --now dnf-automatic.timer"]],
                t('Op Proxmox liever met de hand bijwerken (of alleen beveiligingsupdates), en altijd na een back-up.')],
            'open_db' => [t('Een database luistert op alle netwerken.'), t('Databases op internet worden binnen uren gevonden en leeggehaald of versleuteld voor losgeld.'),
                'sudo ss -tlpn | grep -E ":(3306|5432|6379|27017|9200)\\b"',
                [[t('MariaDB/MySQL alleen lokaal'), "echo -e '[mysqld]\\nbind-address = 127.0.0.1' | sudo tee /etc/mysql/conf.d/rzmon-bind.cnf && sudo systemctl restart mariadb"],
                 [t('Redis alleen lokaal'), "sudo sed -i 's/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf && sudo systemctl restart redis"]],
                t('Hebben andere servers de database nodig: zet de poort alleen voor hun IP-adressen open in de firewall.')],
            'docker_sock' => [t('De Docker-socket is te ruim toegankelijk.'), t('Wie de Docker-socket kan gebruiken, kan een container starten met de hele schijf erin: dat is root.'),
                'ls -l /var/run/docker.sock; sudo ss -tlpn | grep -E ":(2375|2376)\\b"',
                [[t('Rechten herstellen'), 'sudo chmod 660 /var/run/docker.sock && sudo chown root:docker /var/run/docker.sock']],
                t('Docker nooit via tcp (2375) zonder TLS; en alleen vertrouwde gebruikers in de groep docker.')],
            'empty_pw' => [t('Er is een account zonder wachtwoord.'), t('Zo\'n account kan (lokaal, of via diensten die wachtwoorden gebruiken) zonder iets binnen.'),
                "sudo awk -F: '(\$2 == \"\") {print \$1}' /etc/shadow",
                [[t('Account op slot zetten'), 'sudo passwd -l NAAM']], t('Vervang NAAM door de naam uit de controle hierboven.')],
            'sudo_nopw' => [t('Een gebruiker mag sudo zonder wachtwoord.'), t('Wie die gebruiker overneemt (bv. via een gestolen SSH-sleutel) is meteen root, zonder nog iets te hoeven weten.'),
                'sudo grep -rn NOPASSWD /etc/sudoers /etc/sudoers.d/',
                [[t('Bewerken (veilig, met controle)'), 'sudo visudo -f /etc/sudoers.d/BESTAND']],
                t('Laat NOPASSWD alleen staan voor één vaste opdracht, bv. voor een back-upscript.')],
            'tmp_noexec' => [t('/tmp mag programma\'s uitvoeren.'), t('Malware wordt vaak in /tmp gezet (daar mag iedereen schrijven) en daar gestart. Met noexec lukt dat niet.'),
                'findmnt /tmp',
                [[t('/tmp als tmpfs met noexec'), "echo 'tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size=1G 0 0' | sudo tee -a /etc/fstab && sudo mount -o remount /tmp 2>/dev/null || sudo mount /tmp"]],
                t('Sommige installers draaien vanuit /tmp; zet het dan even terug (mount -o remount,exec /tmp).')],
            'selinux' => [t('SELinux staat uit.'), t('SELinux beperkt wat een dienst mag, ook als hij gehackt is: een gehackte webserver kan dan niet zomaar overal bij.'),
                'getenforce; sestatus',
                [[t('Eerst testen (permissive), daarna afdwingen'), "sudo sed -i 's/^SELINUX=.*/SELINUX=permissive/' /etc/selinux/config && sudo touch /.autorelabel && sudo reboot"]],
                t('Na de herstart: kijk een paar dagen in de logboeken (ausearch -m avc), en zet het daarna op enforcing.')],
            default => [$short, '', '', [], ''],
        };
        return ['title' => $title, 'what' => $h[0], 'why' => $h[1], 'check' => $h[2], 'fix' => $short, 'cmds' => $h[3], 'caution' => $h[4]];
    }

    /** Rapport van de agent verwerken: opslaan, en bij een verslechtering een incident (het eerste rapport is stil) */
    public static function store(array $host, array $hard, callable $incident): void
    {
        $hid = (int) $host['id'];
        $prev = [];
        foreach (q('SELECT item, ok, ignored FROM ids_hardening WHERE host_id = ?', [$hid])->fetchAll() as $r) {
            $prev[$r['item']] = $r;
        }
        $up = db()->prepare('INSERT INTO ids_hardening (host_id, item, value, ok, updated_at) VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE value = VALUES(value), ok = VALUES(ok), updated_at = VALUES(updated_at)');
        if (!$hard) {
            return;   // niets gemeten (bv. de lijst kon niet worden opgeslagen)
        }
        foreach (self::ITEMS as $item => [, $sev]) {
            $v = array_key_exists($item, $hard) ? mb_substr((string) $hard[$item], 0, 200) : null;
            $ok = self::ok($item, $v);
            $up->execute([$hid, $item, $v, $ok === null ? null : (int) $ok, now()]);
            $was = $prev[$item] ?? null;
            if ($ok === false && $was && $was['ok'] !== null && (int) $was['ok'] === 1 && !(int) $was['ignored']) {
                [$title, $advice] = self::text($item);
                $incident($sev, 'harden', $item, t('Beveiliging verslechterd: {what}', ['what' => $title]) . ($v !== '' ? " ($v)" : ''), $advice);
            }
        }
    }

    /** Score en details per server */
    public static function detail(int $hostId): array
    {
        $rows = q('SELECT item, value, ok, ignored, updated_at FROM ids_hardening WHERE host_id = ?', [$hostId])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if (!isset(self::ITEMS[$r['item']])) {
                continue;
            }
            [$title, $advice] = self::text($r['item']);
            $out[] = ['item' => $r['item'], 'title' => $title, 'advice' => $advice, 'help' => self::help($r['item']), 'value' => $r['value'], 'ok' => $r['ok'] === null ? null : (bool) $r['ok'],
                'ignored' => (bool) $r['ignored'], 'weight' => self::ITEMS[$r['item']][0], 'updated_at' => $r['updated_at']];
        }
        usort($out, static fn($a, $b) => [$a['ok'] === false && !$a['ignored'] ? 0 : 1, -$a['weight']] <=> [$b['ok'] === false && !$b['ignored'] ? 0 : 1, -$b['weight']]);
        return ['score' => self::score($out), 'items' => $out];
    }

    /** 0–100, of null als er nog niets bekend is */
    public static function score(array $items): ?int
    {
        $all = $good = 0;
        foreach ($items as $i) {
            if ($i['ok'] === null || $i['ignored']) {
                continue;
            }
            $all += $i['weight'];
            $good += $i['ok'] ? $i['weight'] : 0;
        }
        return $all ? (int) round($good / $all * 100) : null;
    }

    /** Scores van alle servers: host_id => [score, aantal punten fout] */
    public static function scores(): array
    {
        $by = [];
        foreach (q('SELECT host_id, item, ok, ignored FROM ids_hardening')->fetchAll() as $r) {
            if (isset(self::ITEMS[$r['item']])) {
                $by[(int) $r['host_id']][] = ['ok' => $r['ok'] === null ? null : (bool) $r['ok'], 'ignored' => (bool) $r['ignored'], 'weight' => self::ITEMS[$r['item']][0]];
            }
        }
        return array_map(static fn($items) => ['score' => self::score($items), 'bad' => count(array_filter($items, static fn($i) => $i['ok'] === false && !$i['ignored']))], $by);
    }

    public static function ignore(int $hostId, string $item, bool $on): bool
    {
        return isset(self::ITEMS[$item]) && q('UPDATE ids_hardening SET ignored = ? WHERE host_id = ? AND item = ?', [$on ? 1 : 0, $hostId, $item])->rowCount() >= 0;
    }
}
