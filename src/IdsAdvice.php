<?php
declare(strict_types=1);

/**
 * Advies bij een IDS-incident (1.101.0): wat is het, hoe erg, wat te doen (met commando's om te kopiëren), hoe voorkom je
 * het, en wat te doen als jij het níet was. Plus gegevens over het IP-adres: omgekeerde DNS (en of die terugwijst),
 * eigenaar en netwerk (RDAP), land, bekende aanvaller, eerder gezien. Opzoeken met korte time-outs en 7 dagen bewaard.
 * Waarden uit het incident gaan altijd gequote in een commando (escapeshellarg): kopiëren voert nooit iets anders uit.
 */
final class IdsAdvice
{
    /** Bekende hosting- en cloudbedrijven: vandaar komen vooral scanners en gehackte servers */
    private const HOSTING = '/amazon|aws|google|microsoft|azure|digitalocean|ovh|hetzner|linode|akamai|contabo|alibaba|tencent|vultr|leaseweb|choopa|scaleway|oracle|m247|hostinger|ionos|datacamp|cdn77|serverius|worldstream|psychz|colocrossing|frantech|buyvm|aeza|stark industries|pfcloud/i';

    /** Waarde voor in een commando */
    private static function q(string $v): string
    {
        return escapeshellarg($v);
    }

    /** Gebruikersnaam uit de titel ("… {user} vanaf {ip}" / "… {user} from {ip}") */
    private static function userFromTitle(string $title): ?string
    {
        return preg_match('/:\s*([A-Za-z0-9._@-]{1,64})\s+(?:vanaf|from)\s/u', $title, $m) ? $m[1] : null;
    }

    /** IP-adres van het incident: de kolom, of uit de sleutel (proces|ip) */
    public static function ipOf(array $inc): ?string
    {
        if (!empty($inc['ip']) && @inet_pton((string) $inc['ip']) !== false) {
            return (string) $inc['ip'];
        }
        foreach (explode('|', (string) $inc['dkey']) as $p) {
            $p = trim($p);
            if (@inet_pton($p) !== false) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @return array{what: string, risk: string, steps: list<string>, cmds: list<array{0: string, 1: string}>, prevent: string,
     *               ask: bool, user: ?string, ip: ?string, emergency: list<string>, ecmds: list<array{0: string, 1: string}>}
     */
    public static function forIncident(array $inc): array
    {
        $type = (string) $inc['type'];
        $key = (string) $inc['dkey'];
        $ip = self::ipOf($inc);
        $parts = explode('|', $key);
        $user = null;
        $a = ['what' => '', 'risk' => '', 'steps' => [], 'cmds' => [], 'prevent' => '', 'ask' => true];
        // Noodstappen als het níet van jou was: bij een login (account overgenomen) of bij kwaadaardige software
        $loginEmergency = static fn(?string $u) => [
            t('Isoleer de server (knop hieronder): alleen RzMon, je eigen netwerk en jouw IP kunnen er dan nog bij.'),
            $u ? t('Vergrendel het account {user} (knop hieronder) en kies een nieuw wachtwoord.', ['user' => $u]) : t('Vergrendel het overgenomen account en kies een nieuw wachtwoord.'),
            t('Blokkeer het IP-adres, en kijk in de tijdlijn of het ook op andere servers kwam.'),
            t('Controleer de SSH-sleutels (authorized_keys), nieuwe gebruikers, cronjobs en diensten: daar zet een aanvaller zijn achterdeur.'),
            t('Twijfel je: zet belangrijke gegevens veilig en installeer de server opnieuw; een gehackte server is nooit meer helemaal te vertrouwen.'),
        ];
        $loginEcmds = static fn(?string $u) => array_values(array_filter([
            [t('Wie is er nu ingelogd'), 'who; last -n 20'],
            $u ? [t('Alle processen van deze gebruiker stoppen'), 'sudo pkill -KILL -u ' . self::q($u)] : null,
            $u ? [t('SSH-sleutels van deze gebruiker bekijken'), 'sudo cat ~' . preg_replace('/[^A-Za-z0-9._-]/', '', $u) . '/.ssh/authorized_keys'] : null,
            [t('Cronjobs en nieuwe diensten'), 'sudo ls -la /etc/cron.* /var/spool/cron* 2>/dev/null; systemctl list-unit-files --state=enabled | head -50'],
        ]));
        $malwareEmergency = [
            t('Isoleer de server (knop hieronder) zodat de aanvaller er niet meer bij kan en er niets meer uit gaat.'),
            t('Bekijk welke processen draaien en met wie ze verbonden zijn; stop wat je niet kent.'),
            t('Zoek uit hoe het binnenkwam: een lek in een website of dienst, een uitgelekt wachtwoord, een open poort. Werk alles bij.'),
            t('Zet belangrijke gegevens veilig en installeer de server opnieuw: na een hack is hij niet meer te vertrouwen.'),
        ];
        $malwareEcmds = [
            [t('Processen met hun verbindingen'), 'sudo ss -tupn | grep -v 127.0.0.1'],
            [t('Recent gewijzigde programma\'s in /tmp, /dev/shm en /var/tmp'), 'sudo find /tmp /dev/shm /var/tmp -type f -mtime -2 -ls 2>/dev/null | head -50'],
            [t('Cronjobs en diensten'), 'sudo ls -la /etc/cron.* /var/spool/cron* 2>/dev/null; systemctl list-unit-files --state=enabled | head -50'],
        ];
        $em = $malwareEmergency;
        $ec = $malwareEcmds;

        switch ($type) {
            case 'ssh_login':
            case 'newip':
            case 'rootlogin':
            case 'breakin':
                $user = $type === 'rootlogin' ? 'root' : ($parts[1] ?? null ? $parts[0] : self::userFromTitle((string) $inc['title']));
                $u = $user ?? '?';
                $a['what'] = match ($type) {
                    'breakin' => t('Na een reeks mislukte pogingen lukte het {user} om in te loggen vanaf {ip}. Dat is het klassieke patroon van een geslaagde inbraak: eerst raden, dan raak.', ['user' => $u, 'ip' => $ip ?? '?']),
                    'rootlogin' => t('Er is direct als root ingelogd via SSH vanaf {ip}. Root kan alles; een aanvaller die het wachtwoord of de sleutel van root heeft, heeft de hele server.', ['ip' => $ip ?? '?']),
                    'newip' => t('{user} logde in vanaf {ip}, een adres waarvandaan nog nooit op deze server is ingelogd.', ['user' => $u, 'ip' => $ip ?? '?']),
                    default => t('{user} logde via SSH in van buiten je eigen netwerk, vanaf {ip}.', ['user' => $u, 'ip' => $ip ?? '?']),
                };
                $a['risk'] = $type === 'breakin'
                    ? t('Ernstig, tenzij je zelf eerst een paar keer je wachtwoord fout typte. Een onbekend IP-adres met veel mislukte pogingen en dan een geslaagde login: ga uit van een inbraak.')
                    : t('Onschuldig als jij (of een collega) dit was, bijvoorbeeld onderweg of via je telefoon. Kijk naar het land en de eigenaar van het IP-adres hieronder: een hostingbedrijf of een vreemd land is verdacht.');
                $a['steps'] = [
                    t('Was jij dit? Klik dan "Ja, dit was ik": dit incident wordt afgehandeld en een login van deze gebruiker vanaf dit adres wordt niet meer gemeld.'),
                    t('Niet van jou: klik "Nee" voor de noodstappen.'),
                ];
                $a['cmds'] = array_values(array_filter([
                    [t('Laatste logins op deze server'), 'last -a -n 20'],
                    $ip ? [t('Alle logins van dit IP-adres'), 'sudo journalctl -u ssh -u sshd --since -7d | grep -F ' . self::q($ip)] : null,
                    $user ? [t('Wat deed deze gebruiker (bash-geschiedenis)'), 'sudo tail -50 ~' . preg_replace('/[^A-Za-z0-9._-]/', '', $user) . '/.bash_history'] : null,
                ]));
                $a['prevent'] = t('Log in met SSH-sleutels in plaats van wachtwoorden (PasswordAuthentication no), laat root niet direct inloggen (PermitRootLogin no), en zet SSH niet open naar internet: gebruik een VPN (WireGuard, Tailscale) of beperk de firewall tot je eigen adressen.');
                $em = $loginEmergency($user);
                $ec = $loginEcmds($user);
                break;

            case 'bruteforce':
                $a['ask'] = false;
                $a['what'] = t('Vanaf {ip} werd in korte tijd vaak geprobeerd in te loggen via SSH met verkeerde wachtwoorden of gebruikersnamen. Dat zijn geautomatiseerde aanvallen die het hele internet afgaan.', ['ip' => $ip ?? '?']);
                $a['risk'] = t('Op zich niet erg zolang er niemand binnenkomt (dan krijg je "Inlog na brute force"). Het laat wel zien dat SSH van buiten bereikbaar is.');
                $a['steps'] = [t('Blokkeer het IP-adres (in modus "blokkeren" doet het IDS dat al vanzelf).'), t('Controleer dat er vanaf dit adres niet is ingelogd (tijdlijn).')];
                $a['cmds'] = $ip ? [[t('Pogingen van dit IP-adres'), 'sudo journalctl -u ssh -u sshd --since -1d | grep -cF ' . self::q($ip)]] : [];
                $a['prevent'] = t('Alleen inloggen met sleutels, SSH niet open naar internet (VPN), of een andere poort plus het IDS in modus blokkeren.');
                $em = $loginEmergency(null);
                $ec = $loginEcmds(null);
                break;

            case 'decoy_user':
            case 'decoy_port':
                $a['ask'] = false;
                $int = $ip !== null && Ids::internalIp($ip);
                $a['what'] = $type === 'decoy_user'
                    ? t('Vanaf {ip} werd geprobeerd in te loggen met een lokaas-gebruiker: een naam die niemand echt gebruikt. Alleen een aanvaller (of een script dat namen raadt) doet dat.', ['ip' => $ip ?? '?'])
                    : t('Vanaf {ip} werd de lokaas-poort benaderd: een poort waar niets draait en waar niemand iets te zoeken heeft. Dat doen scanners en aanvallers die het netwerk verkennen.', ['ip' => $ip ?? '?']);
                $a['risk'] = $int
                    ? t('Ernstig: het komt uit je eigen netwerk. Een apparaat daar is mogelijk gehackt en zoekt verder. Zoek uit welk apparaat dit adres heeft (zie hieronder).')
                    : t('Van buiten: een scanner op internet. Blokkeren is genoeg.');
                $a['steps'] = $int
                    ? [t('Zoek het apparaat met dit IP-adres (router, DHCP-lijst, de DNS-naam hieronder).'), t('Haal het van het netwerk en controleer het; een server met een agent kun je hieronder isoleren.')]
                    : [t('Blokkeer het IP-adres.')];
                $a['prevent'] = t('Het lokaas doet zijn werk: het verraadt verkenning vroeg. Houd het aan.');
                break;

            case 'user_new':
            case 'uid0':
                $user = $key;
                $a['ask'] = $type === 'user_new';
                $a['what'] = $type === 'uid0'
                    ? t('Er is een tweede account met UID 0: {user}. UID 0 betekent root, met een andere naam. Dat is een bekende achterdeur.', ['user' => $user])
                    : t('Er is een nieuwe gebruiker bijgekomen: {user}.', ['user' => $user]);
                $a['risk'] = $type === 'uid0'
                    ? t('Ernstig: daar is bijna nooit een goede reden voor.')
                    : t('Normaal bij het installeren van software (veel pakketten maken een eigen gebruiker) of als je zelf iemand toevoegt. Verdacht als het een gewone gebruiker is met een shell, en niemand het deed.');
                $a['cmds'] = [
                    [t('Gegevens van het account'), 'getent passwd ' . self::q($user) . '; sudo chage -l ' . self::q($user)],
                    [t('Accounts met een shell'), "awk -F: '\$7 !~ /(nologin|false)\$/ {print \$1, \$3, \$7}' /etc/passwd"],
                    [t('Account op slot zetten'), 'sudo usermod -L -e 1 ' . self::q($user)],
                ];
                $a['steps'] = [t('Ken je het account (pakket of collega)? Klik "Ja, dit was ik".'), t('Niet bekend: vergrendel het (knop hieronder) en zoek uit hoe het erbij kwam.')];
                $a['prevent'] = t('Beperk wie root mag worden (sudo), en houd de server bijgewerkt.');
                $em = $loginEmergency($user);
                $ec = $loginEcmds($user);
                break;

            case 'group_add':
                [$g, $m] = $parts + [1 => '?'];
                $user = $m;
                $a['what'] = t('{user} is toegevoegd aan de groep {group}. Leden van groepen als sudo, wheel, docker of adm kunnen (bijna) alles wat root kan.', ['user' => $m, 'group' => $g]);
                $a['risk'] = t('Normaal als jij iemand beheerrechten gaf. Zo niet: een aanvaller die zichzelf rechten geeft.');
                $a['cmds'] = [[t('Leden van de groep'), 'getent group ' . self::q($g)], [t('Uit de groep halen'), 'sudo gpasswd -d ' . self::q($m) . ' ' . self::q($g)]];
                $a['steps'] = [t('Deed jij dit? Klik "Ja, dit was ik".'), t('Niet van jou: haal de gebruiker uit de groep en vergrendel het account.')];
                $a['prevent'] = t('Geef beheerrechten alleen aan wie ze echt nodig heeft; liever sudo voor één opdracht dan een hele groep.');
                $em = $loginEmergency($m);
                $ec = $loginEcmds($m);
                break;

            case 'port_new':
                $port = str_starts_with($key, 'proc:') ? null : $key;
                $a['what'] = $port !== null ? t('Er luistert een nieuwe dienst op poort {port}.', ['port' => $port]) : t('Een programma luistert op een nieuwe poort.');
                $a['risk'] = t('Normaal na het installeren of bijwerken van software. Verdacht als je niets installeerde: een achterdeur luistert vaak op een vreemde hoge poort.');
                $a['cmds'] = [[t('Welk programma luistert er'), $port !== null ? 'sudo ss -tulpn | grep -E ' . self::q(':' . $port . '\\b') : 'sudo ss -tulpn']];
                $a['steps'] = [t('Ken je het programma? Klik "Ja, dit was ik".'), t('Moet het niet van buiten bereikbaar zijn: laat het alleen op 127.0.0.1 luisteren of zet de poort dicht in de firewall.')];
                $a['prevent'] = t('Een firewall die alleen de poorten openzet die nodig zijn.');
                break;

            case 'kev_port':
                $a['ask'] = false;
                $a['what'] = t('Er luistert een dienst ({pkg}) met een kwetsbaarheid die nu actief door aanvallers wordt misbruikt (de KEV-lijst van CISA).', ['pkg' => $key]);
                $a['risk'] = t('Ernstig: dit soort lekken wordt binnen dagen geautomatiseerd uitgebuit.');
                $a['steps'] = [t('Werk het pakket bij (zie de pagina Kwetsbaarheden).'), t('Kan dat niet meteen: zet de poort dicht voor internet.')];
                $a['cmds'] = [[t('Bijwerken (Debian/Ubuntu)'), 'sudo apt update && sudo apt install --only-upgrade ' . self::q($key)], [t('Bijwerken (Rocky/RHEL)'), 'sudo dnf upgrade ' . self::q($key)]];
                $a['prevent'] = t('Automatische beveiligingsupdates aanzetten (zie de hardening-score).');
                break;

            case 'suid_new':
            case 'module_new':
            case 'unit_new':
            case 'pkg_changed':
                $a['what'] = match ($type) {
                    'suid_new' => t('Nieuw SUID-programma: {path}. Zo\'n programma draait als zijn eigenaar (vaak root), wie het ook start; een aanvaller zet er een neer om later weer root te worden.', ['path' => $key]),
                    'module_new' => t('Nieuwe kernelmodule geladen: {name}. Een module draait in de kern van het systeem; een rootkit verstopt zich zo.', ['name' => $key]),
                    'unit_new' => t('Nieuwe dienst die automatisch start: {name}. Zo zorgt een aanvaller ervoor dat zijn software na een herstart terugkomt.', ['name' => $key]),
                    default => t('Een systeembestand wijkt af van het geïnstalleerde pakket: {path}. Dat kan een met de hand aangepaste instelling zijn, maar ook een vervangen programma (bv. een nep-ssh).', ['path' => $key]),
                };
                $a['risk'] = t('Normaal na het installeren of bijwerken van software of drivers. Verdacht als er niets geïnstalleerd is.');
                $a['cmds'] = match ($type) {
                    'suid_new' => [[t('Bestand bekijken'), 'ls -la ' . self::q($key) . '; sudo dpkg -S ' . self::q($key) . ' 2>/dev/null || rpm -qf ' . self::q($key)], [t('SUID-bit weghalen'), 'sudo chmod u-s ' . self::q($key)]],
                    'module_new' => [[t('Module bekijken'), 'modinfo ' . self::q($key) . '; lsmod | grep -F ' . self::q($key)]],
                    'unit_new' => [[t('Dienst bekijken'), 'systemctl cat ' . self::q($key) . '; systemctl status ' . self::q($key)], [t('Stoppen en uitzetten'), 'sudo systemctl disable --now ' . self::q($key)]],
                    default => [[t('Bij welk pakket hoort het'), 'dpkg -S ' . self::q($key) . ' 2>/dev/null || rpm -qf ' . self::q($key)], [t('Opnieuw installeren (Debian/Ubuntu)'), 'sudo apt install --reinstall "$(dpkg -S ' . self::q($key) . ' | cut -d: -f1)"']],
                };
                $a['steps'] = [t('Weet je waar het vandaan komt? Klik "Ja, dit was ik".'), t('Niet bekend: bekijk het met de commando\'s hieronder, en zie de noodstappen.')];
                $a['prevent'] = t('Installeer alleen software uit de officiële bronnen, en houd de hardening-score hoog.');
                break;

            case 'fim':
            case 'fim_bulk':
            case 'file':
                $path = $key;
                $a['what'] = $type === 'fim_bulk'
                    ? t('Veel bewaakte bestanden tegelijk gewijzigd onder {path}.', ['path' => $path])
                    : t('Een bewaakt bestand is gewijzigd of nieuw: {path}.', ['path' => $path]);
                $a['risk'] = t('Normaal na een update of als je zelf iets aanpaste. Verdacht bij bestanden als /etc/passwd, /etc/shadow, sudoers, sshd_config of authorized_keys zonder dat iemand iets deed.');
                $a['cmds'] = [[t('Bestand bekijken'), 'sudo ls -la ' . self::q($path) . ' && sudo stat ' . self::q($path)], [t('Wie werkte erop (laatste logins)'), 'last -a -n 10']];
                $a['steps'] = [t('Deed jij dit (of een update)? Klik "Ja, dit was ik": de nieuwe inhoud wordt de norm.'), t('Niet bekend: vergelijk met een back-up en zie de noodstappen.')];
                $a['prevent'] = t('Wijzig systeembestanden alleen via je eigen beheer; bewaar back-ups zodat je kunt vergelijken.');
                break;

            case 'proc_suspicious':
            case 'revshell':
            case 'webshell':
                $a['what'] = match ($type) {
                    'revshell' => t('Een shell ({proc}) heeft een verbinding naar buiten ({ip}). Bij een "reverse shell" maakt de gehackte server zelf contact met de aanvaller, zodat die commando\'s kan geven.', ['proc' => $parts[0] ?? '?', 'ip' => $ip ?? '?']),
                    'webshell' => t('De webserver ({web}) start een programma ({tool}). Een website hoort dat bijna nooit te doen; een webshell (een kwaadaardig script op de website) wel.', ['web' => $parts[0] ?? '?', 'tool' => $parts[1] ?? '?']),
                    default => t('Er draait een verdacht programma: {path} (vanuit /tmp, /dev/shm, een verwijderd bestand of alleen in het geheugen). Malware verstopt zich zo.', ['path' => $key]),
                };
                $a['risk'] = $type === 'webshell'
                    ? t('Ernstig, tenzij de website dit bewust doet (bv. een beheerpaneel dat een back-up maakt). Dan: "Ja, dit was ik".')
                    : t('Ernstig: ga uit van een hack tot het tegendeel bewezen is.');
                $a['cmds'] = $type === 'webshell'
                    ? [[t('Recent gewijzigde PHP-bestanden in de website'), 'sudo find /var/www -name "*.php" -mtime -7 -ls 2>/dev/null | head -50'], [t('Processen van de webserver'), 'ps -ef | grep -E "www-data|apache|nginx" | grep -v grep']]
                    : [[t('Het proces en zijn verbindingen'), 'sudo ss -tupn | grep -v 127.0.0.1; ps -ef --forest | head -80']];
                $a['steps'] = [t('Isoleer de server als je het niet vertrouwt.'), t('Zoek het bestand of script en stop het proces.'), t('Zoek uit hoe het binnenkwam (verouderde website, plug-in, wachtwoord).')];
                $a['prevent'] = t('Houd websites, plug-ins en de server bijgewerkt; laat de webserver niet als root draaien; /tmp met noexec.');
                break;

            case 'outbound':
            case 'out_bad':
            case 'out_country':
                $a['ask'] = $type !== 'out_bad';
                $proc = $parts[0] ?? '?';
                $a['what'] = match ($type) {
                    'out_bad' => t('{proc} maakt verbinding met {ip}, een adres op een lijst van bekende aanvallers.', ['proc' => $proc, 'ip' => $ip ?? '?']),
                    'out_country' => t('{proc} maakt verbinding met een land dat niet bij je normale landen staat.', ['proc' => $proc]),
                    default => t('Een programma maakt verbinding naar een poort die vaak door malware wordt gebruikt (bv. een cryptominer of een verbinding naar de aanvaller).'),
                };
                $a['risk'] = $type === 'out_bad'
                    ? t('Ernstig: dit wijst vaak op malware of een cryptominer.')
                    : t('Vaak onschuldig (updates, cloud-diensten, een CDN). Kijk naar de eigenaar van het IP-adres hieronder.');
                $a['cmds'] = $ip ? [[t('Welk proces praat met dit adres'), 'sudo ss -tupn | grep -F ' . self::q($ip)]] : [];
                $a['steps'] = [t('Hoort dit bij het programma (bv. updates)? Klik "Ja, dit was ik" (of vrijgeven).'), t('Niet: blokkeer het IP-adres en zoek het programma.')];
                $a['prevent'] = t('Zet "uitgaand naar bekende aanvallers blokkeren" aan bij IDS → Instellingen.');
                break;

            case 'web_attack':
                $a['ask'] = false;
                $a['what'] = t('Vanaf {ip} werden aanvallen op een website gezien in het logboek (bv. zoeken naar bekende lekken, wp-login, .env-bestanden).', ['ip' => $ip ?? '?']);
                $a['risk'] = t('Meestal geautomatiseerde scanners. Ernstig alleen als de website een lek heeft waar ze naar zoeken.');
                $a['steps'] = [t('Blokkeer het IP-adres.'), t('Houd de website en plug-ins bijgewerkt.')];
                $a['prevent'] = t('Een web-application-firewall of fail2ban voor de website, en alles bijgewerkt.');
                break;

            case 'harden':
                $h = Hardening::help($key);
                $a['what'] = $h['what'];
                $a['risk'] = $h['why'];
                $a['cmds'] = $h['cmds'];
                $a['steps'] = [$h['fix']];
                $a['prevent'] = $h['caution'];
                break;

            default:
                $a['what'] = (string) $inc['detail'];
        }
        return $a + ['user' => $user, 'ip' => $ip, 'emergency' => $em, 'ecmds' => $ec];
    }

    // ------------------------------------------------------------------ IP-adres

    /** Alles wat RzMon zelf weet, plus (gecachet) DNS en RDAP. @param bool $fresh opnieuw opzoeken */
    public static function ipInfo(string $ip, bool $fresh = false): array
    {
        $local = Ids::timeline($ip);
        $out = ['ip' => $ip, 'cc' => $local['cc'], 'bad' => $local['bad'], 'internal' => $local['internal'], 'whitelist' => $local['whitelist'],
            'blocked_rz' => $local['blocked_rz'], 'blocked_ids' => $local['blocked_ids'], 'first' => $local['first'], 'events' => count($local['events']),
            'hosts' => $local['hosts']];
        // Een van je eigen servers?
        $out['rzmon_host'] = q("SELECT name FROM hosts WHERE address = ? OR CONCAT(' ', COALESCE(agent_ips, ''), ' ') LIKE ? LIMIT 1", [$ip, '% ' . $ip . ' %'])->fetchColumn() ?: null;
        $row = $fresh ? false : q('SELECT data, fetched_at FROM ip_info WHERE ip = ? AND fetched_at > NOW() - INTERVAL 7 DAY', [$ip])->fetch();
        // Een mislukte opzoeking (register niet bereikbaar) niet een week bewaren: na een uur opnieuw proberen
        if ($row && !empty(json_decode((string) $row['data'], true)['rdap']['error']) && strtotime((string) $row['fetched_at']) < time() - 3600) {
            $row = false;
        }
        if ($row) {
            $ext = json_decode((string) $row['data'], true) ?: [];
            $ext['cached_at'] = $row['fetched_at'];
        } else {
            $ext = ['rdns' => self::rdns($ip), 'rdap' => ip_in_list($ip, PRIVATE_NETWORKS) ? null : self::rdap($ip)];
            q('INSERT INTO ip_info (ip, data, fetched_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), fetched_at = VALUES(fetched_at)',
                [$ip, json_encode($ext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            if (random_int(1, 50) === 1) {
                q('DELETE FROM ip_info WHERE fetched_at < NOW() - INTERVAL 30 DAY');
            }
        }
        $org = (string) ($ext['rdap']['org'] ?? '') . ' ' . (string) ($ext['rdap']['name'] ?? '') . ' ' . (string) ($ext['rdns']['name'] ?? '');
        $ext['hosting'] = trim($org) !== '' && (bool) preg_match(self::HOSTING, $org);
        return $out + $ext;
    }

    /** Omgekeerde DNS, en of die naam weer naar dit IP wijst (anders is de naam niet te vertrouwen). Hooguit 2 s per stap. */
    private static function rdns(string $ip): ?array
    {
        if (!function_exists('shell_exec') || !is_executable('/usr/bin/timeout') && !is_executable('/bin/timeout')) {
            return null;
        }
        $r = trim((string) @shell_exec('timeout 2 getent hosts ' . escapeshellarg($ip) . ' 2>/dev/null'));
        $name = preg_split('/\s+/', $r)[1] ?? '';
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]{1,253}$/', $name)) {
            return ['name' => null, 'confirmed' => null];
        }
        $fw = (string) @shell_exec('timeout 2 getent ahosts ' . escapeshellarg($name) . ' 2>/dev/null');
        $ips = array_unique(array_map(static fn($l) => preg_split('/\s+/', trim($l))[0] ?? '', array_filter(explode("\n", $fw))));
        return ['name' => $name, 'confirmed' => in_array($ip, $ips, true)];
    }

    /** Eigenaar van het IP-adres via RDAP (de opvolger van whois), via rdap.org naar het juiste register. Hooguit ~5 s. */
    private static function rdap(string $ip): ?array
    {
        $ch = curl_init('https://rdap.org/ip/' . rawurlencode($ip));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => ['Accept: application/rdap+json']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200) {
            return ['error' => $code ? t('register antwoordde met HTTP {c}', ['c' => $code]) : t('register niet bereikbaar (time-out)')];
        }
        return self::parseRdap((array) json_decode($body, true));
    }

    /** Uit een RDAP-antwoord: netwerknaam, bereik, land, organisatie en het abuse-adres */
    public static function parseRdap(array $d): array
    {
        $vc = static function (array $e, string $field): ?string {
            foreach ((array) ($e['vcardArray'][1] ?? []) as $p) {
                if (($p[0] ?? '') === $field) {
                    $v = $p[3] ?? null;
                    return is_array($v) ? implode(' ', array_filter(array_map('strval', $v))) : (is_string($v) ? $v : null);
                }
            }
            return null;
        };
        $org = $abuse = null;
        $walk = static function (array $ents) use (&$walk, &$org, &$abuse, $vc): void {
            foreach ($ents as $e) {
                $roles = (array) ($e['roles'] ?? []);
                if ($org === null && array_intersect($roles, ['registrant', 'administrative'])) {
                    $org = $vc($e, 'fn');
                }
                if ($abuse === null && in_array('abuse', $roles, true)) {
                    $abuse = $vc($e, 'email');
                }
                $walk((array) ($e['entities'] ?? []));
            }
        };
        $walk((array) ($d['entities'] ?? []));
        $cidr = null;
        foreach ((array) ($d['cidr0_cidrs'] ?? []) as $c) {
            $cidr = ($c['v4prefix'] ?? $c['v6prefix'] ?? '') . '/' . ($c['length'] ?? '');
            break;
        }
        $clip = static fn($v, int $n) => $v === null ? null : mb_substr((string) $v, 0, $n);
        return ['name' => $clip($d['name'] ?? null, 80), 'handle' => $clip($d['handle'] ?? null, 80), 'org' => $clip($org, 120),
            'country' => isset($d['country']) && preg_match('/^[A-Z]{2}$/', (string) $d['country']) ? $d['country'] : null,
            'range' => isset($d['startAddress'], $d['endAddress']) ? $clip($d['startAddress'] . ' – ' . $d['endAddress'], 100) : null,
            'cidr' => $clip($cidr, 60), 'abuse' => $abuse !== null && filter_var($abuse, FILTER_VALIDATE_EMAIL) ? $abuse : null,
            'registry' => $clip(parse_url((string) ($d['links'][0]['href'] ?? ''), PHP_URL_HOST), 60)];
    }
}
