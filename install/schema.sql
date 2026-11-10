-- RzSense database schema (MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(64) PRIMARY KEY,
    value MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- mode: local  = de monitor-server zelf (disk/service lokaal uitlezen)
--       agent  = remote server met rzmon-agent.sh (pusht disk/services)
--       remote = alleen netwerkchecks (ping/tcp/http)
CREATE TABLE IF NOT EXISTS hosts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    mode        ENUM('local','agent','remote') NOT NULL DEFAULT 'remote',
    agent_token CHAR(40) NULL UNIQUE,
    agent_seen  DATETIME NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- status: 0=OK 1=WARN 2=CRIT 3=UNKNOWN
CREATE TABLE IF NOT EXISTS checks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id         INT UNSIGNED NOT NULL,
    type            ENUM('ping','tcp','http','ssl','disk','service','cpu','ram','podman','container','log','ha','command','snmp','reboot','heartbeat','process','pve','dns','ntp','smart','ups','mysql','internet','domain','mqtt','vpn','truenas','path') NOT NULL,
    target          VARCHAR(255) NOT NULL DEFAULT '',
    name            VARCHAR(100) NOT NULL,
    warn_threshold  DOUBLE NULL,
    crit_threshold  DOUBLE NULL,
    interval_sec    INT UNSIGNED NOT NULL DEFAULT 60,
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    status          TINYINT UNSIGNED NOT NULL DEFAULT 3,
    last_value      DOUBLE NULL,
    last_message    VARCHAR(500) NULL,
    last_checked    DATETIME NULL,
    fail_count      INT UNSIGNED NOT NULL DEFAULT 0,
    notified_status TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notified_at     DATETIME NULL,
    CONSTRAINT fk_checks_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
    INDEX idx_checks_host (host_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS results (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id   INT UNSIGNED NOT NULL,
    status     TINYINT UNSIGNED NOT NULL,
    value      DOUBLE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_results_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
    INDEX idx_results_check_time (check_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Laatste gegevens van een agent (disk.<mount> / service.<naam>)
CREATE TABLE IF NOT EXISTS agent_data (
    host_id    INT UNSIGNED NOT NULL,
    metric     VARCHAR(190) NOT NULL,
    value      TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (host_id, metric),
    CONSTRAINT fk_agent_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id   INT UNSIGNED NULL,
    status     TINYINT UNSIGNED NOT NULL,
    title      VARCHAR(250) NOT NULL,
    message    VARCHAR(1000) NOT NULL,
    sent_ok    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alerts_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE SET NULL,
    INDEX idx_alerts_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Statuswijzigingen per check (voor de popup van een check), ook als er geen melding uitging
CREATE TABLE IF NOT EXISTS status_changes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id    INT UNSIGNED NOT NULL,
    from_status TINYINT UNSIGNED NOT NULL,
    to_status   TINYINT UNSIGNED NOT NULL,
    message     VARCHAR(500) NOT NULL DEFAULT '',
    value       DOUBLE NULL,
    note        VARCHAR(200) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sc_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
    INDEX idx_sc_check_time (check_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Toegang (pagina IDS → Toegang): logins, sudo en su van de agents; mislukte pogingen per uur opgeteld
CREATE TABLE IF NOT EXISTS access_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    kind       VARCHAR(10) NOT NULL,          -- auth (gelukt) | authfail (mislukt)
    type       VARCHAR(10) NOT NULL,          -- ssh | console | sudo | su
    title      VARCHAR(250) NOT NULL,
    message    VARCHAR(1000) NOT NULL,
    detail     TEXT NULL,                     -- JSON: user/users, ip, as, cmd, n, key
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_access_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
    INDEX idx_access_time (created_at),
    INDEX idx_access_host (host_id, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sleutels voor de REST API (alleen de SHA-256 hash wordt opgeslagen)
CREATE TABLE IF NOT EXISTS api_keys (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    key_hash   CHAR(64) NOT NULL UNIQUE,
    key_hint   VARCHAR(12) NOT NULL,
    last_used  DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent-rapporten die geweigerd zijn (voor de diagnose in "Agent Manager")
CREATE TABLE IF NOT EXISTS agent_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NULL,
    ip         VARCHAR(45) NOT NULL,
    reason     VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agent_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ingelogde sessies (1.79.0): elke sessie (wachtwoord, 2FA, onthouden apparaat, LAN), om ze te kunnen zien en uitloggen.
-- In de sessie staat een willekeurige sleutel; hier alleen de SHA-256 hash. user_id NULL = LAN zonder account (alleen lezen).
CREATE TABLE IF NOT EXISTS user_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sid_hash    CHAR(64) NOT NULL UNIQUE,
    user_id     INT UNSIGNED NULL,
    username    VARCHAR(64) NOT NULL DEFAULT '',
    how         VARCHAR(20) NOT NULL DEFAULT '',
    remember_id INT UNSIGNED NULL,
    device      VARCHAR(100) NOT NULL DEFAULT '',
    ip          VARCHAR(45) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL,
    last_seen   DATETIME NOT NULL,
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_user (user_id),
    INDEX idx_session_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Onthoud mij op dit apparaat": per apparaat een sleutel (alleen de SHA-256 hash wordt opgeslagen).
-- De sleutel wordt hooguit dagelijks vervangen; prev_hash blijft geldig tot de nieuwe sleutel terugkomt (1.85.1).
CREATE TABLE IF NOT EXISTS remember_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    selector   CHAR(24) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL,
    prev_hash  CHAR(64) NULL,
    rotated_at DATETIME NULL,
    device     VARCHAR(100) NOT NULL DEFAULT '',
    ip         VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used  DATETIME NOT NULL,
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_remember_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Meldingen die nog verstuurd moeten worden: per server gebundeld (Instellingen → Alarmgedrag)
CREATE TABLE IF NOT EXISTS notify_queue (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    check_id   INT UNSIGNED NOT NULL,
    status     TINYINT UNSIGNED NOT NULL,
    event      VARCHAR(16) NOT NULL,
    previous   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    title      VARCHAR(250) NOT NULL,
    message    VARCHAR(1000) NOT NULL,
    priority   TINYINT NOT NULL DEFAULT 0,
    value      DOUBLE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_host (host_id),
    CONSTRAINT fk_queue_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wachtwoord vergeten: herstellinks (alleen de SHA-256 hash) en een logboek van aanvragen
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    tries      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip         VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_reset_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    ident      VARCHAR(190) NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    event      VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_log_time (created_at),
    INDEX idx_reset_log_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SNMP: vorige meting van tellers (interfaceverkeer, eigen OID als teller), om er een waarde per seconde van te maken
CREATE TABLE IF NOT EXISTS snmp_state (
    check_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    data       VARCHAR(1000) NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_snmp_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Melden na zoveel mislukte checks achter elkaar (1 = zoals voorheen; per check aan te passen)
INSERT IGNORE INTO settings (name, value) VALUES ('notify_fails', '1');

-- Taal van meldingen en checkberichten (nl/en)
INSERT IGNORE INTO settings (name, value) VALUES ('notify_lang', 'nl');

-- Upgrade van oudere versies (veilig om opnieuw uit te voeren)
ALTER TABLE checks MODIFY type ENUM('ping','tcp','http','ssl','disk','service','cpu','ram','podman','container','log','ha','command','snmp','reboot','heartbeat','process','pve','dns','ntp','smart','ups','mysql','internet','domain','mqtt','vpn','truenas','path') NOT NULL;
ALTER TABLE agent_data MODIFY value TEXT NOT NULL;
-- 1.157.4: de lijst met MQTT-topics werd bij veel checks groter dan 64 KB (TEXT)
ALTER TABLE settings MODIFY value MEDIUMTEXT NULL;

INSERT IGNORE INTO settings (name, value) VALUES
    ('pushover_enabled', '0'),
    ('ntfy_enabled', '0'),
    ('ntfy_server', 'https://ntfy.sh'),
    ('ntfy_topic', ''),
    ('ntfy_token', ''),
    ('pushover_user', ''),
    ('pushover_token', ''),
    ('pushover_priority_crit', '1'),
    ('pushover_priority_warn', '0'),
    ('pushover_sound', ''),
    ('alert_after', '2'),
    ('repeat_minutes', '60'),
    ('notify_warn', '1'),
    ('notify_recovery', '1'),
    ('agent_stale_minutes', '5'),
    ('retention_days', '30'),
    ('lan_no_login', '0'),
    ('trusted_networks', '192.168.0.0/16\n10.0.0.0/8\n172.16.0.0/12\n127.0.0.1\n::1\nfc00::/7'),
    ('trusted_proxies', ''),
    ('maintenance_until', ''),
    ('maintenance_reason', ''),
    ('ha_webhook_enabled', '0'),
    ('ha_webhook_url', ''),
    ('ha_webhook_verify_ssl', '1'),
    ('ha_webhook_events', 'all'),
    ('notify_recovery_acked', '0'),
    ('ack_secret', ''),
    ('email_enabled', '0'),
    ('email_mode', 'always'),
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_security', 'starttls'),
    ('smtp_user', ''),
    ('smtp_pass', ''),
    ('smtp_verify_ssl', '1'),
    ('mail_from', ''),
    ('mail_to', ''),
    ('mqtt_enabled', '0'),
    ('mqtt_host', ''),
    ('mqtt_port', '1883'),
    ('mqtt_tls', '0'),
    ('mqtt_verify_ssl', '1'),
    ('mqtt_user', ''),
    ('mqtt_pass', ''),
    ('mqtt_prefix', 'homeassistant'),
    ('mqtt_base_topic', 'rzmon'),
    ('disk_forecast_days', '7'),
    ('auto_maint_enabled', '1'),
    ('auto_maint_after', '3'),
    ('lan_as_admin', '0'),
    ('log_window', '15'),
    -- Back-up: uit tot je hem aanzet, dan om 03:15, 7 bewaren, met meethistorie
    ('backup_freq', 'off'),
    ('backup_time', '03:15'),
    ('backup_weekday', '7'),
    ('backup_keep', '7'),
    ('backup_history', '1'),
    ('agent_auto_update', '0'),
    -- RzSense bijwerken: met de knop; automatisch uit (anders om 04:00), altijd eerst een back-up
    ('update_auto', '0'),
    ('update_time', '04:00'),
    ('update_backup', '1'),
    -- 1.205.0: zo lang (minuten) wachten agents tijdens een update van RzSense voordat de waakhond alsnog alarm geeft
    ('update_hold_min', '5'),
    -- 1.206.0: bevestigen bij acties: beheerders met een vinkje (zolang ze hun wachtwoord de laatste 60 min invulden)
    ('confirm_skip_admin', '1'),
    ('confirm_skip_user', '0'),
    ('confirm_skip_readonly', '0'),
    ('confirm_grace_min', '60'),
    -- Commando-checks op agents: uit tot je ze aanzet (uit | nobody | root), per server te overschrijven
    ('agent_commands', 'off'),
    -- Logins (SSH, console), sudo en su van de agents op de pagina Meldingen; auth_ignore: gebruikers/IP's
    ('auth_events', '1'),
    ('auth_ignore', ''),
    -- IDS: standaard uit; mode learn = alleen melden, block = ook blokkeren (lokaal, nftables)
    ('ids_enabled', '0'),
    ('ids_mode', 'learn'),
    ('ids_baseline_hours', '24'),
    ('ids_fail_count', '10'),
    ('ids_fail_minutes', '10'),
    ('ids_block_hours', '24'),
    ('ids_repeat_days', '7'),
    ('ids_allow', ''),
    ('ids_fim', '[]'),
    ('agent_tick', '60'),
    ('ids_fim_exclude', '*.log *.tmp *.swp *.pid cache/ sessions/');

-- Logboek van gevoelige wijzigingen (bv. commando's op agents toestaan, commando-checks wijzigen)
CREATE TABLE IF NOT EXISTS audit_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(100) NOT NULL,
    action     VARCHAR(50) NOT NULL,
    detail     VARCHAR(1000) NOT NULL,
    ip         VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDS: wat normaal is per server (bestanden, gebruikers, poorten, SUID, modules, login-IP's, uitzonderingen)
CREATE TABLE IF NOT EXISTS ids_baseline (
    host_id    INT UNSIGNED NOT NULL,
    kind       VARCHAR(20) NOT NULL,
    item       VARCHAR(255) NOT NULL,
    value      VARCHAR(255) NOT NULL DEFAULT '',
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (host_id, kind, item),
    CONSTRAINT fk_idsb_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDS: toestand per server (basismeting tot, firewall van de agent)
CREATE TABLE IF NOT EXISTS ids_state (
    host_id        INT UNSIGNED NOT NULL PRIMARY KEY,
    baseline_until DATETIME NOT NULL,
    fw_state       VARCHAR(20) NOT NULL DEFAULT '',
    fw_error       VARCHAR(255) NOT NULL DEFAULT '',
    last_report    DATETIME NULL,
    CONSTRAINT fk_idss_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDS: incidenten (severity 1 = warning, 2 = critical); dkey om herhalingen samen te voegen
CREATE TABLE IF NOT EXISTS ids_incidents (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    severity   TINYINT UNSIGNED NOT NULL,
    type       VARCHAR(30) NOT NULL,
    dkey       VARCHAR(255) NOT NULL,
    title      VARCHAR(250) NOT NULL,
    detail     VARCHAR(1000) NOT NULL DEFAULT '',
    ip         VARCHAR(45) NULL,
    hits       INT UNSIGNED NOT NULL DEFAULT 1,
    status     VARCHAR(10) NOT NULL DEFAULT 'open',
    handled_by VARCHAR(100) NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_idsi_time (created_at),
    INDEX idx_idsi_key (host_id, type, dkey),
    CONSTRAINT fk_idsi_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDS: geblokkeerde IP-adressen per server (until NULL = permanent; removed_at = gedeblokkeerd)
CREATE TABLE IF NOT EXISTS ids_blocks (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    reason     VARCHAR(255) NOT NULL DEFAULT '',
    auto       TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    until      DATETIME NULL,
    hits       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    removed_at DATETIME NULL,
    removed_by VARCHAR(100) NULL,
    INDEX idx_idsbl_host (host_id, removed_at),
    CONSTRAINT fk_idsbl_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDS: mislukte inlogpogingen per server en IP (voor brute force), een dag bewaard
CREATE TABLE IF NOT EXISTS ids_attempts (
    id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id INT UNSIGNED NOT NULL,
    ip      VARCHAR(45) NOT NULL,
    n       INT UNSIGNED NOT NULL,
    at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_idsa (host_id, ip, at),
    CONSTRAINT fk_idsa_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kwetsbaarheden: softwarelijst per server (van de agent), bevindingen, gegevens per kwetsbaarheid en per CVE, negeerlijst
CREATE TABLE IF NOT EXISTS vuln_inventory (
    host_id        INT UNSIGNED NOT NULL PRIMARY KEY,
    mgr            VARCHAR(10) NOT NULL,
    os_id          VARCHAR(40) NOT NULL DEFAULT '',
    os_version     VARCHAR(40) NOT NULL DEFAULT '',
    os_name        VARCHAR(120) NOT NULL DEFAULT '',
    ecosystem      VARCHAR(60) NULL,
    hash           CHAR(16) NOT NULL,
    packages       MEDIUMTEXT NOT NULL,
    exposed        TEXT NULL,
    restart_svc    TEXT NULL,
    kernel_running VARCHAR(100) NULL,
    kernel_latest  VARCHAR(100) NULL,
    updated_at     DATETIME NOT NULL,
    scanned_at     DATETIME NULL,
    scan_error     VARCHAR(300) NULL,
    CONSTRAINT fk_vinv_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vuln_findings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id       INT UNSIGNED NOT NULL,
    package       VARCHAR(150) NOT NULL,
    version       VARCHAR(120) NOT NULL,
    source        VARCHAR(150) NOT NULL,
    vuln_id       VARCHAR(80) NOT NULL,
    cve           VARCHAR(40) NOT NULL,
    fixed_version VARCHAR(120) NULL,
    first_seen    DATETIME NOT NULL,
    notified      TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_vf (host_id, package, vuln_id),
    INDEX idx_vf_cve (cve),
    CONSTRAINT fk_vf_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vuln_cache (
    vuln_id    VARCHAR(80) NOT NULL PRIMARY KEY,
    modified   VARCHAR(40) NOT NULL DEFAULT '',
    data       MEDIUMTEXT NOT NULL,
    fetched_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vuln_cve (
    cve        VARCHAR(40) NOT NULL PRIMARY KEY,
    severity   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cvss       DECIMAL(3,1) NULL,
    `vector`   VARCHAR(200) NULL,   -- tussen backticks: gereserveerd woord sinds MariaDB 11.7
    epss       DECIMAL(6,5) NULL,
    kev        TINYINT(1) NOT NULL DEFAULT 0,
    kev_added  DATE NULL,
    nvd_at     DATETIME NULL,
    epss_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vuln_ignore (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cve        VARCHAR(40) NOT NULL,
    package    VARCHAR(150) NULL,
    host_id    INT UNSIGNED NULL,
    reason     VARCHAR(300) NOT NULL DEFAULT '',
    created_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    INDEX idx_vi_cve (cve)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.141.0: opgeloste kwetsbaarheden (verdwenen bij een nieuwe scan), om te laten zien wat een update echt opgelost heeft
CREATE TABLE IF NOT EXISTS vuln_resolved (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id     INT UNSIGNED NOT NULL,
    package     VARCHAR(150) NOT NULL,
    version     VARCHAR(120) NOT NULL,
    cve         VARCHAR(40) NOT NULL,
    severity    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    first_seen  DATETIME NOT NULL,
    resolved_at DATETIME NOT NULL,
    INDEX idx_vr_host (host_id, resolved_at),
    CONSTRAINT fk_vr_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES ('vuln_enabled', '0'), ('vuln_time', '03:30'), ('vuln_min_severity', '0'), ('vuln_exclude', '');

-- 1.179.0: rugbaarheid per CVE (GitHub, Exploit-DB, Nuclei, CISA SSVC, Hacker News, Mastodon) en het verloop per dag
CREATE TABLE IF NOT EXISTS vuln_buzz (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cve        VARCHAR(40) NOT NULL,
    score      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    exploit    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    talk       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    data       TEXT NULL,
    history    TEXT NULL,
    checked_at DATETIME NULL,
    alerted_at DATETIME NULL,
    UNIQUE KEY uq_vb_cve (cve)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('cve_buzz_on', '1'), ('cve_buzz_alert', '60'), ('cve_github_token', ''), ('cve_mastodon_url', 'https://infosec.exchange'),
    ('cve_mastodon_token', ''), ('cve_exploitdb', '1'), ('cve_nuclei', '1');

-- Beveiliging (1.57.0): geblokkeerde IP-adressen en netwerken (heel RzSense), mislukte pogingen om te tellen,
-- en eenmalige nummers van versleutelde agent-berichten (tegen opnieuw afspelen)
CREATE TABLE IF NOT EXISTS rz_blocks (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    net        VARCHAR(64)  NOT NULL UNIQUE,      -- 203.0.113.7 of 203.0.113.0/24
    reason     VARCHAR(200) NULL,
    created_by VARCHAR(64)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hits       INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.83.0: whitelist (nooit blokkeren): IP-adressen/netwerken (RzSense en IDS op alle servers), accounts (nooit
-- vergrendelen) en namen die geen honeypot zijn; until NULL = altijd
CREATE TABLE IF NOT EXISTS whitelist (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(8)   NOT NULL,             -- ip | user | decoy
    value      VARCHAR(64)  NOT NULL,
    reason     VARCHAR(200) NULL,
    created_by VARCHAR(64)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    until      DATETIME     NULL,
    UNIQUE KEY uq_wl (kind, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.83.0: opgeheven en verlopen blokkades van RzSense zelf (IP's en accounts), voor "Eerder"; zo lang als de meldingen
CREATE TABLE IF NOT EXISTS block_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(8)   NOT NULL,             -- rz (IP of netwerk) | user (account)
    value      VARCHAR(64)  NOT NULL,
    reason     VARCHAR(255) NULL,
    ip         VARCHAR(45)  NULL,                 -- account: laatste poging vanaf
    created_by VARCHAR(100) NULL,
    created_at DATETIME     NULL,
    until      DATETIME     NULL,
    removed_at DATETIME     NOT NULL,
    removed_by VARCHAR(100) NULL,                 -- NULL = verlopen
    INDEX idx_bl_time (removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.85.0: PHP-sessies in de database (niet meer na 24 minuten weggegooid door de opruimtaak van het systeem);
-- hoe lang zonder activiteit: session_idle_minutes (30, 120, 480 of 1440)
CREATE TABLE IF NOT EXISTS php_sessions (
    id         VARCHAR(128)  NOT NULL PRIMARY KEY,
    data       MEDIUMBLOB    NOT NULL,
    updated_at INT UNSIGNED  NOT NULL,
    INDEX idx_phps_time (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('session_idle_minutes', '120');

-- 1.86.0: onzichtbaar van buiten. Toegestane apparaten (per gebruiker; de cookie bevat de sleutel, hier alleen de hash),
-- passkeys (WebAuthn, alleen de openbare sleutel) en noodlinks (eenmalig, 15 minuten)
CREATE TABLE IF NOT EXISTS trusted_devices (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    key_hash   CHAR(64)     NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL,
    created_ip VARCHAR(45)  NULL,
    last_seen  DATETIME     NOT NULL,
    last_ip    VARCHAR(45)  NULL,
    last_cc    CHAR(2)      NULL,
    CONSTRAINT fk_tdev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS passkeys (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    device_id  INT UNSIGNED NULL,
    name       VARCHAR(100) NOT NULL,
    cred_id    VARCHAR(255) NOT NULL UNIQUE,
    public_key TEXT         NOT NULL,
    alg        INT          NOT NULL,
    sign_count INT UNSIGNED NOT NULL DEFAULT 0,
    rp_id      VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL,
    last_used  DATETIME     NULL,
    CONSTRAINT fk_pk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stealth_doors (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    ip         VARCHAR(45)  NULL,
    CONSTRAINT fk_door_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('stealth_hide', '1'), ('stealth_ui', '0'), ('stealth_countries', '1'), ('stealth_door', '1'), ('passkeys_enabled', '1');

-- 1.87.0: IDS – processen die de agent moet stoppen (webshell, modus blokkeren); pid + starttijd, zodat nooit een ander
-- proces met hetzelfde nummer geraakt wordt
CREATE TABLE IF NOT EXISTS ids_kills (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    pid        INT UNSIGNED NOT NULL,
    start      BIGINT UNSIGNED NOT NULL,
    dkey       VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL,
    done_at    DATETIME     NULL,
    result     VARCHAR(100) NULL,
    UNIQUE KEY uq_kill (host_id, pid, start),
    CONSTRAINT fk_kill_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('ids_webshell', '1'), ('ids_webshell_kill', '0'), ('ids_persist', '1'),
    ('ids_outbound', '1'), ('ids_outbound_country', '1'), ('ids_outbound_block', '0');

-- 1.88.0: hardening per server (agent 1.57.0) en gebruikers die op een server vergrendeld moeten worden
CREATE TABLE IF NOT EXISTS ids_hardening (
    host_id    INT UNSIGNED NOT NULL,
    item       VARCHAR(32)  NOT NULL,
    value      VARCHAR(200) NULL,
    ok         TINYINT(1)   NULL,
    ignored    TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (host_id, item),
    CONSTRAINT fk_hard_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ids_userlocks (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    username   VARCHAR(32)  NOT NULL,
    action     VARCHAR(8)   NOT NULL,             -- lock | unlock
    created_by VARCHAR(64)  NULL,
    created_at DATETIME     NOT NULL,
    done_at    DATETIME     NULL,
    result     VARCHAR(50)  NULL,
    UNIQUE KEY uq_ul (host_id, username),
    CONSTRAINT fk_ul_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('ids_harden', '1');

-- 1.89.0: logs van webdiensten (SWAG, Home Assistant, Vaultwarden): waar gevonden per server, en pogingen per IP
CREATE TABLE IF NOT EXISTS weblog_state (
    host_id INT UNSIGNED NOT NULL,
    src     VARCHAR(8)   NOT NULL,
    path    VARCHAR(255) NULL,
    err     VARCHAR(255) NULL,
    hint    VARCHAR(500) NULL,
    seen_at DATETIME     NOT NULL,
    PRIMARY KEY (host_id, src),
    CONSTRAINT fk_wls_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weblog_attempts (
    id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id INT UNSIGNED NOT NULL,
    src     VARCHAR(8)  NOT NULL,
    kind    VARCHAR(8)  NOT NULL,
    ip      VARCHAR(45) NOT NULL,
    n       INT UNSIGNED NOT NULL,
    at      DATETIME    NOT NULL,
    INDEX idx_wla (host_id, src, kind, ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('weblog_swag', '1'), ('weblog_swag_path', 'auto'), ('weblog_ha', '1'), ('weblog_ha_path', 'auto'),
    ('weblog_vw', '1'), ('weblog_vw_path', 'auto'), ('weblog_block', '0'), ('wl_paths', '1'), ('wl_ua', '1'), ('wl_404', '1'), ('wl_404_n', '20'),
    ('wl_auth', '1'), ('wl_auth_n', '10'), ('wl_nohost', '1'), ('wl_nohost_n', '5'), ('wl_ha_n', '5'), ('wl_vw_n', '5'), ('wl_minutes', '10');

CREATE TABLE IF NOT EXISTS security_fails (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45) NOT NULL,
    kind       VARCHAR(16) NOT NULL,              -- login | agent | api
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sf (ip, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agent_nonces (
    nonce      CHAR(32) PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_an (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Versleuteling van de agents: off | on (wie een sleutel heeft, versleutelt) | required; de sleutel maak je zelf aan
INSERT IGNORE INTO settings (name, value) VALUES ('enc_mode', 'on'), ('enc_key', ''), ('enc_key_old', ''), ('enc_key_old_until', '');

-- Beveiligde verbinding (1.64.0): sleutel per agent na zoveel dagen vernieuwen (0 = alleen met de hand), het certificaat
-- van RzSense vastzetten bij een eigen (self-signed) certificaat, en agents alleen vanaf hun bekende IP-adres
INSERT IGNORE INTO settings (name, value) VALUES ('agent_key_days', '30'), ('agent_pin', '1'), ('agent_pin_value', ''), ('agent_pin_at', '0'),
    ('agent_ip_lock', '1');

-- Veilig beheer via https (1.68.0): beheeradres, alleen https (met HSTS), inloggen via http weigeren,
-- 2FA verplicht van buiten je interne netwerken, en de laatste controle van het certificaat
INSERT IGNORE INTO settings (name, value) VALUES ('admin_url', ''), ('https_only', '0'), ('https_since', ''), ('hsts_sent', '0'),
    ('http_login_block', '0'), ('mfa_external', '0'), ('https_state', ''), ('https_check_at', ''), ('proxy_unknown', '');

-- Inlogbeveiliging (1.58.0): na N mislukte pogingen binnen M minuten het account en/of het IP (van buiten het LAN) blokkeren,
-- voor X minuten (0 = tot een beheerder deblokkeert)
INSERT IGNORE INTO settings (name, value) VALUES ('login_lock_fails', '3'), ('login_lock_window', '10'), ('login_lock_account', '1'),
    ('login_lock_ip', '1'), ('login_lock_minutes', '60');

-- Logins per account (1.60.0), voor "Mijn laatste logins" op Mijn account; 90 dagen bewaard
CREATE TABLE IF NOT EXISTS login_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    ok         TINYINT(1)   NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    how        VARCHAR(40)  NOT NULL,
    device     VARCHAR(120) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ll (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Datalekken via Have I Been Pwned (1.69.0): hoofdschakelaar (standaard uit), drempel voor wachtwoorden, API-sleutel,
-- eigen lijst met e-mailadressen en een (bij HIBP geverifieerd) domein
INSERT IGNORE INTO settings (name, value) VALUES ('hibp_enabled', '0'), ('hibp_threshold', '1'), ('hibp_key', ''), ('hibp_emails', ''),
    ('hibp_domain', ''), ('hibp_rpm', '10'), ('hibp_check_at', ''), ('hibp_domain_at', ''), ('hibp_domain_error', ''), ('hibp_last_error', ''),
    ('hibp_wait_until', '0');

-- Gecontroleerde e-mailadressen (bron: user, list of domain) en hun lekken; de gegevens per lek komen van HIBP
CREATE TABLE IF NOT EXISTS hibp_emails (
    email      VARCHAR(190) PRIMARY KEY,
    source     VARCHAR(10) NOT NULL DEFAULT 'list',
    checked_at DATETIME NULL,
    error      VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hibp_breaches (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    name       VARCHAR(100) NOT NULL,
    source     VARCHAR(10) NOT NULL DEFAULT 'list',
    found_at   DATETIME NOT NULL,
    handled_at DATETIME NULL,
    handled_by VARCHAR(64) NULL,
    UNIQUE KEY uq_hibp (email, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hibp_catalog (
    name         VARCHAR(100) PRIMARY KEY,
    title        VARCHAR(200) NULL,
    domain       VARCHAR(190) NULL,
    breach_date  DATE NULL,
    added_date   DATETIME NULL,
    pwn_count    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    data_classes TEXT NULL,
    verified     TINYINT(1) NOT NULL DEFAULT 0,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    description  TEXT NULL,
    fetched_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agents alleen via https (1.72.0): verzoeken van agents via http weigeren, behalve via hun reserve-adres (met een ⚠️)
INSERT IGNORE INTO settings (name, value) VALUES ('agent_https_only', '0');
-- Alleen agents met een eigen sleutel (1.100.0); koppelen met een koppelcode blijft altijd kunnen
INSERT IGNORE INTO settings (name, value) VALUES ('agent_ownkey_only', '0');

-- Heartbeat-checks (1.77.0): laatste start, ok, fail, duur en bericht per check
CREATE TABLE IF NOT EXISTS hb_state (
    check_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    data       TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_hb_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDS (1.81.0): honeypot-gebruikers en -poort, automatische blokkades op alle servers, wekelijks overzicht
INSERT IGNORE INTO settings (name, value) VALUES ('ids_decoy_users', 'admin test guest user oracle ubuntu pi ftp support postgres');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_decoy_port', '2323');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_block_shared', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_weekly', 'email');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_weekly_day', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_weekly_time', '08:00');

-- IDS (1.82.0): bekende aanvallers (Spamhaus DROP, FireHOL level 1) en land per IP (DB-IP Lite), als bereiken (inet_pton)
CREATE TABLE IF NOT EXISTS ip_blocklist (
    fam      TINYINT UNSIGNED NOT NULL,
    start_ip VARBINARY(16) NOT NULL,
    end_ip   VARBINARY(16) NOT NULL,
    cidr     VARCHAR(90) NOT NULL,
    src      VARCHAR(20) NOT NULL,
    PRIMARY KEY (fam, start_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ip_geo (
    fam      TINYINT UNSIGNED NOT NULL,
    start_ip VARBINARY(16) NOT NULL,
    end_ip   VARBINARY(16) NOT NULL,
    cc       CHAR(2) NOT NULL,
    PRIMARY KEY (fam, start_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES ('ids_blocklists', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_geo', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('ids_geo_allowed', 'NL');

-- Check-sjablonen (1.94.0): een sjabloon is een lijst checks (kolommen als JSON); gekoppelde servers krijgen ze,
-- en een wijziging aan het sjabloon gaat mee naar de checks die niet per server zijn aangepast (checks.tpl_override)
CREATE TABLE IF NOT EXISTS check_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS template_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    data        TEXT NOT NULL,
    CONSTRAINT fk_titem_tpl FOREIGN KEY (template_id) REFERENCES check_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS host_templates (
    host_id     INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (host_id, template_id),
    CONSTRAINT fk_htpl_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
    CONSTRAINT fk_htpl_tpl FOREIGN KEY (template_id) REFERENCES check_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PHP-fouten van RzSense zelf (1.96.0), samengevat per soort; Instellingen → Systeem → Systeemcheck (7 dagen)
CREATE TABLE IF NOT EXISTS app_errors (
    hash     CHAR(40) NOT NULL PRIMARY KEY,
    level    VARCHAR(10) NOT NULL,
    message  VARCHAR(500) NOT NULL,
    file     VARCHAR(200) NOT NULL DEFAULT '',
    line     INT UNSIGNED NOT NULL DEFAULT 0,
    url      VARCHAR(200) NOT NULL DEFAULT '',
    count    INT UNSIGNED NOT NULL DEFAULT 1,
    first_at DATETIME NOT NULL,
    last_at  DATETIME NOT NULL,
    INDEX idx_app_errors_last (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wijzigingslogboek (1.97.0): wie veranderde wat, met de oude en nieuwe waarde (JSON), en of het is teruggezet
CREATE TABLE IF NOT EXISTS change_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    at          DATETIME NOT NULL,
    username    VARCHAR(64) NOT NULL DEFAULT '',
    ip          VARCHAR(45) NOT NULL DEFAULT '',
    kind        VARCHAR(16) NOT NULL,
    ref_id      INT UNSIGNED NULL,
    action      VARCHAR(40) NOT NULL,
    label       VARCHAR(200) NOT NULL DEFAULT '',
    before_data MEDIUMTEXT NULL,
    after_data  MEDIUMTEXT NULL,
    undone_at   DATETIME NULL,
    INDEX idx_change_log_at (at),
    INDEX idx_change_log_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('changelog_days', '90');

-- Certificaten zoeken (1.98.0): extra mappen voor de agents (spatie-gescheiden)
INSERT IGNORE INTO settings (name, value) VALUES ('cert_dirs', '');

-- Gegevens over een IP-adres (1.101.0): omgekeerde DNS en de eigenaar (RDAP), 7 dagen bewaard voor het incidentvenster
CREATE TABLE IF NOT EXISTS ip_info (
    ip         VARCHAR(45) NOT NULL PRIMARY KEY,
    data       TEXT NOT NULL,
    fetched_at DATETIME NOT NULL,
    KEY idx_ipinfo_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.102.0: apparaten die van buiten geweigerd werden (webinterface alleen op toegestane apparaten); een beheerder kan ze toestaan.
-- key_hash: sha256 van de wachtende sleutel in de cookie van dat apparaat; bij toestaan wordt dat een toegestaan apparaat.
CREATE TABLE IF NOT EXISTS device_requests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_hash    CHAR(64)     NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL DEFAULT '',
    ua          VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL,
    created_ip  VARCHAR(45)  NOT NULL DEFAULT '',
    last_seen   DATETIME     NOT NULL,
    last_ip     VARCHAR(45)  NOT NULL DEFAULT '',
    last_cc     CHAR(2)      NULL,
    hits        INT UNSIGNED NOT NULL DEFAULT 1,
    status      VARCHAR(10)  NOT NULL DEFAULT 'pending',
    handled_by  VARCHAR(64)  NULL,
    handled_at  DATETIME     NULL,
    KEY idx_devreq_status (status, last_seen),
    KEY idx_devreq_ip (created_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('stealth_requests', '1');

-- 1.103.0: netwerkscan – apparaten op je lokale netwerken (sleutel: MAC-adres, of het IP-adres als er geen MAC is)
CREATE TABLE IF NOT EXISTS net_devices (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mac         CHAR(17)     NULL UNIQUE,
    ip          VARCHAR(45)  NOT NULL,
    random_mac  TINYINT(1)   NOT NULL DEFAULT 0,
    vendor      VARCHAR(120) NULL,
    hostname    VARCHAR(100) NULL,
    name        VARCHAR(100) NULL,
    kind        VARCHAR(16)  NOT NULL DEFAULT 'unknown',
    kind_user   VARCHAR(16)  NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'new',
    ports       TEXT         NULL,
    info        TEXT         NULL,
    notes       VARCHAR(500) NULL,
    first_seen  DATETIME     NOT NULL,
    last_seen   DATETIME     NOT NULL,
    deep_at     DATETIME     NULL,
    KEY idx_netdev_ip (ip),
    KEY idx_netdev_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mac_vendors (
    prefix  VARCHAR(9)   NOT NULL PRIMARY KEY,
    vendor  VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('net_scan', '0'), ('net_sweep_minutes', '60'), ('net_deep_per_day', '4');
-- 1.104.0: koppelingen voor de netwerkscan (UniFi en AdGuard Home); zelf-ondertekende certificaten zijn daar normaal
INSERT IGNORE INTO settings (name, value) VALUES ('unifi_site', 'default'), ('unifi_verify_ssl', '0'), ('adguard_verify_ssl', '0');

-- 1.106.0: dagtotalen voor 📊 Analyse (een jaar bewaard); metric/ref zie src/Analytics.php
CREATE TABLE IF NOT EXISTS stats_daily (
    day    DATE         NOT NULL,
    metric VARCHAR(24)  NOT NULL,
    ref    INT UNSIGNED NOT NULL DEFAULT 0,
    v      DOUBLE       NOT NULL,
    PRIMARY KEY (day, metric, ref),
    KEY idx_sd_metric (metric, ref, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('report_monthly', '0');
INSERT IGNORE INTO settings (name, value) VALUES ('trend_alert_days', '30'), ('trend_ups_min', '10');   -- 1.224.0: Trends

-- 1.107.0: slimme drempels: wat RzSense per check geleerd heeft (per uur van de week)
CREATE TABLE IF NOT EXISTS check_smart (
    check_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    model      MEDIUMTEXT   NULL,
    learned_at DATETIME     NULL,
    CONSTRAINT fk_smart_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('thresh_default', 'guard');
INSERT IGNORE INTO settings (name, value) VALUES ('smart_sens', 'normal');
-- 1.156.0: drempels en melden na N zelf aanpassen (met wijzigingslogboek en ℹ️-melding)
INSERT IGNORE INTO settings (name, value) VALUES ('smart_auto', '1'), ('alert_auto', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('notify_bundle_hosts', '1');

-- 1.108.0: slimme voorstellen (melden na N, vaste onderhoudsvensters, afhankelijkheden); jij past ze toe of negeert ze
CREATE TABLE IF NOT EXISTS advice (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(16)  NOT NULL,
    ref_id     INT UNSIGNED NOT NULL,
    fp         VARCHAR(64)  NOT NULL,
    data       TEXT         NOT NULL,
    state      VARCHAR(10)  NOT NULL DEFAULT 'open',
    created_at DATETIME     NOT NULL,
    decided_at DATETIME     NULL,
    UNIQUE KEY uq_advice (kind, ref_id, fp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1.108.0: vaste (terugkerende) onderhoudsvensters, voor een server of één check
CREATE TABLE IF NOT EXISTS maint_windows (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope      VARCHAR(5)   NOT NULL,
    ref_id     INT UNSIGNED NOT NULL,
    dow        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    start      CHAR(5)      NOT NULL,
    minutes    SMALLINT UNSIGNED NOT NULL,
    reason     VARCHAR(200) NULL,
    created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('esc_on', '0'), ('esc_warn_min', '30'), ('esc_crit_min', '15'), ('esc_crit_prio', '2'), ('esc_quiet', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('smart_interval', '0'), ('smart_fast', '20'), ('smart_slow', '2');
INSERT IGNORE INTO settings (name, value) VALUES ('anomaly_on', '1'), ('disk_jump_on', '1'), ('disk_jump_pct', '5'), ('context_on', '1'), ('hb_learn', '1');

-- 1.110.0: endpoint-bewaking. Alle gebeurtenissen van de sensoren (Live, 3 dagen); rule/sev als een regel raak was
CREATE TABLE IF NOT EXISTS edr_events (
    id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id INT UNSIGNED NOT NULL,
    at      DATETIME     NOT NULL,
    kind    CHAR(1)      NOT NULL,
    sig     VARCHAR(255) NOT NULL,
    data    TEXT         NOT NULL,
    rule    VARCHAR(80)  NULL,
    sev     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_edre_at (at),
    INDEX idx_edre_host (host_id, id),
    CONSTRAINT fk_edre_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.110.0: wat een server al eerder deed (per soort en kenmerk), voor de anomaliedetectie (60 dagen)
CREATE TABLE IF NOT EXISTS edr_seen (
    host_id    INT UNSIGNED NOT NULL,
    kind       CHAR(1)      NOT NULL,
    sig        VARCHAR(255) NOT NULL,
    first_seen DATETIME     NOT NULL,
    last_seen  DATETIME     NOT NULL,
    n          INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (host_id, kind, sig),
    CONSTRAINT fk_edrs_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.110.0: bewijs bij een IDS-incident (het proces, de verbinding, het webverzoek …) met de regel die raak was
CREATE TABLE IF NOT EXISTS ids_evidence (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    at          DATETIME     NOT NULL,
    kind        VARCHAR(10)  NOT NULL,
    rule        VARCHAR(80)  NOT NULL DEFAULT '',
    data        TEXT         NOT NULL,
    INDEX idx_idse_inc (incident_id, id),
    CONSTRAINT fk_idse_inc FOREIGN KEY (incident_id) REFERENCES ids_incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('edr_on', '1'), ('edr_kill', '1'), ('edr_block', '1'), ('edr_isolate', '1'), ('edr_sigma', '1'), ('edr_web', '1'), ('edr_rules_off', '[]');

-- 1.111.0: signalen voor ketens en het MITRE-overzicht (regeltreffers, afwijkingen, IDS-incidenten), 90 dagen bewaard
CREATE TABLE IF NOT EXISTS ids_signals (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id  INT UNSIGNED NOT NULL,
    at       DATETIME     NOT NULL,
    src      VARCHAR(10)  NOT NULL,
    feature  VARCHAR(20)  NOT NULL DEFAULT '',
    tactics  VARCHAR(160) NOT NULL DEFAULT '',
    techs    VARCHAR(160) NOT NULL DEFAULT '',
    label    VARCHAR(250) NOT NULL,
    score    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip       VARCHAR(45)  NULL,
    ref      BIGINT UNSIGNED NULL,
    dkey     VARCHAR(255) NOT NULL DEFAULT '',
    data     TEXT         NULL,
    INDEX idx_idssig_host (host_id, at),
    INDEX idx_idssig_at (at),
    INDEX idx_idssig_ip (ip, at),
    CONSTRAINT fk_idssig_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.111.0: aantal gebeurtenissen per server per uur (voor "ongewoon veel" en "ongewoon tijdstip"), 30 dagen bewaard
CREATE TABLE IF NOT EXISTS edr_hourly (
    host_id INT UNSIGNED NOT NULL,
    hr      DATETIME     NOT NULL,
    kind    CHAR(1)      NOT NULL,
    n       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (host_id, hr, kind),
    CONSTRAINT fk_edrh_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('edr_anom', '1'), ('edr_learn_days', '14'), ('edr_anom_min', '40'), ('edr_chain', '1'), ('edr_chain_min', '60'), ('edr_chain_act', '1');

-- 1.112.0: dreigingsinformatie (AbuseIPDB, GreyNoise, Shodan, CIRCL, abuse.ch, Tor): per IP en bestand een dag/week bewaard
CREATE TABLE IF NOT EXISTS ti_ip (
    ip          VARCHAR(45)  NOT NULL PRIMARY KEY,
    checked_at  DATETIME     NOT NULL,
    score       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verdict     VARCHAR(20)  NOT NULL DEFAULT '',
    data        TEXT         NULL,
    reported_at DATETIME     NULL,
    INDEX idx_tiip_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ti_hash (
    sha        CHAR(64)     NOT NULL PRIMARY KEY,
    checked_at DATETIME     NOT NULL,
    verdict    VARCHAR(20)  NOT NULL DEFAULT '',
    data       TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lijsten die dagelijks worden opgehaald: i = IP (Tor, ThreatFox), d = domein (URLhaus, ThreatFox)
CREATE TABLE IF NOT EXISTS ti_list (
    kind  CHAR(1)      NOT NULL,
    value VARCHAR(253) NOT NULL,
    src   VARCHAR(20)  NOT NULL,
    info  VARCHAR(120) NOT NULL DEFAULT '',
    PRIMARY KEY (kind, value, src)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ti_queue (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind        CHAR(1)      NOT NULL,
    value       VARCHAR(253) NOT NULL,
    host_id     INT UNSIGNED NOT NULL,
    incident_id BIGINT UNSIGNED NULL,
    ctx         VARCHAR(250) NOT NULL DEFAULT '',
    added_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('ti_on', '1'), ('ti_greynoise', '1'), ('ti_shodan', '1'), ('ti_circl', '1'), ('ti_tor', '1'), ('ti_tor_block', '0'),
    ('ti_urlhaus', '1'), ('ti_feodo', '1'), ('ti_block', '1'), ('ti_block_score', '80'), ('ti_escalate', '1'), ('ti_close_benign', '1'), ('ti_report', '0'), ('ti_cve', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('dns_on', '1'), ('suricata_on', '1'), ('suricata_block', '1'), ('edr_yara', '1');

-- 1.117.0: moderne weergave van de IDS-pagina (uit = compact)
INSERT IGNORE INTO settings (name, value) VALUES ('ids_modern', '1');
-- 1.153.0: beweging in de live Attack Flow (0 = stil, 1 = rustig, 2 = vol)
INSERT IGNORE INTO settings (name, value) VALUES ('af_motion', '2');

-- 1.119.0: snelheidsmeter (per uur en per verzoek of cron-onderdeel opgeteld; twee dagen bewaard)
CREATE TABLE IF NOT EXISTS perf_stats (
    hour     DATETIME         NOT NULL,
    kind     CHAR(1)          NOT NULL,
    name     VARCHAR(40)      NOT NULL,
    n        INT UNSIGNED     NOT NULL DEFAULT 0,
    total_ms BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    max_ms   INT UNSIGNED     NOT NULL DEFAULT 0,
    bytes    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (hour, kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('perf_on', '1');

-- 1.123.0: per server verborgen meldingen over ontbrekende onderdelen van de endpoint-bewaking ({host_id: [onderdeel, …]})
INSERT IGNORE INTO settings (name, value) VALUES ('edr_note_hide', '{}');

-- 1.128.0: geheime link van de statuspagina met het lokale adres (base_url) of het externe adres (external_url)
INSERT IGNORE INTO settings (name, value) VALUES ('status_link', 'local');

-- 1.132.0: uitzonderingen voor IDS en endpoint-bewaking (IDS → Regels → Uitzonderingen)
-- target: edr (gebeurtenis) of ids (incident); rule: EDR-regel of soort incident (NULL = alle); scope: all, group, host;
-- cond: voorwaarden als JSON {program, parent, user, cmd, path, ip, port, container, text: [waarden]}
CREATE TABLE IF NOT EXISTS ids_exceptions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    target     VARCHAR(3)   NOT NULL DEFAULT 'edr',
    rule       VARCHAR(80)  NULL,
    scope      VARCHAR(10)  NOT NULL DEFAULT 'all',
    scope_val  VARCHAR(30)  NULL,
    cond       TEXT         NOT NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    until      DATETIME     NULL,
    reason     VARCHAR(200) NOT NULL DEFAULT '',
    created_by VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    set_key    VARCHAR(20)  NULL,
    item_key   VARCHAR(40)  NULL,
    hits       INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit   DATETIME     NULL,
    INDEX idx_exc_on (enabled),
    INDEX idx_exc_set (set_key, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.133.0: zoveel handmatige back-ups bewaren (0 = alles); de oudere worden opgeruimd
INSERT IGNORE INTO settings (name, value) VALUES ('backup_keep_manual', '0');

-- 1.136.0: uurgemiddelden per check (na de ruwe historie nog maanden grafieken); ruwe metingen gaan pas weg als hun uur hier staat
CREATE TABLE IF NOT EXISTS results_hourly (
    check_id INT UNSIGNED NOT NULL,
    hour     DATETIME NOT NULL,
    n        INT UNSIGNED NOT NULL,
    n_warn   INT UNSIGNED NOT NULL DEFAULT 0,
    n_crit   INT UNSIGNED NOT NULL DEFAULT 0,
    v_avg    DOUBLE NULL,
    v_min    DOUBLE NULL,
    v_max    DOUBLE NULL,
    PRIMARY KEY (check_id, hour),
    INDEX idx_rh_hour (hour),
    CONSTRAINT fk_rh_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1.136.0: uurgemiddelden 180 dagen, daggegevens (Analyse) 2 jaar
INSERT IGNORE INTO settings (name, value) VALUES ('hourly_days', '180'), ('daily_days', '730');

-- 1.142.0: incidenten (één lijst uit monitoring, IDS en kwetsbaarheden) met status, eigenaar en tijdlijn
CREATE TABLE IF NOT EXISTS incidents (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source      VARCHAR(10) NOT NULL,
    ref_key     VARCHAR(120) NOT NULL,
    ref_id      BIGINT UNSIGNED NULL,
    host_id     INT UNSIGNED NULL,
    severity    TINYINT UNSIGNED NOT NULL DEFAULT 2,
    title       VARCHAR(250) NOT NULL,
    detail      VARCHAR(1000) NOT NULL DEFAULT '',
    status      VARCHAR(10) NOT NULL DEFAULT 'open',
    owner       VARCHAR(64) NULL,
    opened_at   DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    updated_at  DATETIME NOT NULL,
    UNIQUE KEY uq_inc_ref (ref_key),
    INDEX idx_inc_status (status, opened_at),
    CONSTRAINT fk_inc_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incident_notes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    by_user     VARCHAR(100) NOT NULL,
    kind        VARCHAR(10) NOT NULL DEFAULT 'note',
    text        VARCHAR(2000) NOT NULL,
    created_at  DATETIME NOT NULL,
    INDEX idx_incn (incident_id),
    CONSTRAINT fk_incn_inc FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.144.0: samengevoegde IDS-meldingen (stappen) van een incident
CREATE TABLE IF NOT EXISTS incident_refs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    ref_key     VARCHAR(120) NOT NULL,
    ref_id      BIGINT UNSIGNED NULL,
    kind        VARCHAR(30) NOT NULL DEFAULT '',
    severity    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    title       VARCHAR(250) NOT NULL,
    ip          VARCHAR(45) NULL,
    at          DATETIME NOT NULL,
    UNIQUE KEY uq_incr_key (ref_key),
    INDEX idx_incr_inc (incident_id),
    CONSTRAINT fk_incr_inc FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.146.0: uitkomsten van zelf herstellen (om te zien hoe vaak het lukt) en stiltes van agents (SLO)
CREATE TABLE IF NOT EXISTS heal_log (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id  INT UNSIGNED NOT NULL,
    check_id INT UNSIGNED NOT NULL,
    ok       TINYINT(1) NOT NULL,
    msg      VARCHAR(200) NOT NULL DEFAULT '',
    at       DATETIME NOT NULL,
    INDEX idx_heal_log (at),
    CONSTRAINT fk_heal_log_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agent_gaps (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id  INT UNSIGNED NOT NULL,
    gap_from DATETIME NOT NULL,
    gap_to   DATETIME NOT NULL,
    INDEX idx_agent_gaps (gap_to),
    CONSTRAINT fk_agent_gaps_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.152.0 (agent 1.74.0): losse SSH-pogingen (ok = 0) en geslaagde sessies (ok = 1, closed_at = einde), 30 dagen bewaard.
-- Voor de aanvalskaart (Attack Flow, tijdlijn), de popup per IP en IDS → Toegang → SSH. port = bronpoort van de bezoeker.
CREATE TABLE IF NOT EXISTS ssh_events (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id   INT UNSIGNED NOT NULL,
    at        DATETIME NOT NULL,
    ok        TINYINT(1) NOT NULL DEFAULT 0,
    ip        VARCHAR(45) NOT NULL DEFAULT '',
    username  VARCHAR(64) NOT NULL DEFAULT '',
    port      SMALLINT UNSIGNED NULL,
    method    VARCHAR(30) NULL,
    closed_at DATETIME NULL,
    INDEX idx_sshe_host (host_id, at),
    INDEX idx_sshe_ip (ip, at),
    INDEX idx_sshe_at (at),
    CONSTRAINT fk_sshe_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.155.0: Analyse en advies. Per advies (vaste sleutel): wanneer het verscheen, de status die iemand gaf, wanneer het
-- vanzelf verdween (opgelost) en of de kritieke melding al is verstuurd. Plus de score per dag en wanneer een poort
-- voor het eerst open stond (om nieuwe poorten te herkennen).
CREATE TABLE IF NOT EXISTS insight_state (
    k           VARCHAR(190) NOT NULL PRIMARY KEY,
    title       VARCHAR(250) NULL,
    sev         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    dom         VARCHAR(12) NOT NULL DEFAULT '',
    status      VARCHAR(8) NULL,
    status_by   VARCHAR(100) NULL,
    status_at   DATETIME NULL,
    later_until DATETIME NULL,
    first_seen  DATETIME NOT NULL,
    last_seen   DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    notified_at DATETIME NULL,
    INDEX idx_insight_resolved (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS insight_score (
    day     DATE NOT NULL PRIMARY KEY,
    total   TINYINT UNSIGNED NOT NULL,
    domains VARCHAR(300) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS insight_seen (
    k          VARCHAR(190) NOT NULL PRIMARY KEY,
    first_seen DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.157.0: snelheidstesten van de internetlijn (Instellingen → Monitoring → Internet)
CREATE TABLE IF NOT EXISTS inet_speed (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    at      DATETIME NOT NULL,
    down    DOUBLE NULL,                 -- Mbit/s
    up      DOUBLE NULL,
    lat     DOUBLE NULL,                 -- ms naar de testserver
    mb_down DOUBLE NULL,
    mb_up   DOUBLE NULL,
    colo    VARCHAR(8) NULL,             -- locatie van de testserver (AMS …)
    err     VARCHAR(255) NULL,
    INDEX idx_inet_speed_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('inet_down', '1000'), ('inet_up', '1000'), ('inet_warn_pct', '70'), ('inet_crit_pct', '40'),
    ('inet_times', '03:30,09:30,13:30,17:30'), ('inet_speed_mb', '250');

-- 1.171.0: AI-advies (Claude, ChatGPT, Gemini, Grok, Ollama) bij checks, bundels, incidenten en IDS-incidenten
CREATE TABLE IF NOT EXISTS ai_advice (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(10)  NOT NULL,                    -- check | bundle (server) | incident | ids
    ref_id     BIGINT UNSIGNED NOT NULL,
    host_id    INT UNSIGNED NULL,
    status     VARCHAR(10)  NOT NULL DEFAULT 'pending',  -- pending | diag (wacht op de agent) | running | done | error
    provider   VARCHAR(12)  NOT NULL DEFAULT '',
    model      VARCHAR(80)  NOT NULL DEFAULT '',
    auto       TINYINT(1)   NOT NULL DEFAULT 0,
    answer     MEDIUMTEXT   NULL,
    actions    VARCHAR(500) NULL,                        -- voorgestelde bestaande herstelactie (JSON)
    diag       MEDIUMTEXT   NULL,
    error      VARCHAR(500) NULL,
    tok_in     INT UNSIGNED NULL,
    tok_out    INT UNSIGNED NULL,
    ms         INT UNSIGNED NULL,
    created_by VARCHAR(64)  NULL,
    created_at DATETIME     NOT NULL,
    started_at DATETIME     NULL,
    done_at    DATETIME     NULL,
    INDEX idx_ai_ref (kind, ref_id, id),
    INDEX idx_ai_status (status),
    INDEX idx_ai_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('ai_on', '0'), ('ai_provider', 'ollama'), ('ai_auto', '0'), ('ai_daily', '50'), ('ai_mask', '1'),
    ('ai_diag', '0'), ('ai_exec', '0'), ('ai_timeout', '120'), ('ai_max_tokens', '1500'), ('ai_ollama_url', 'http://127.0.0.1:11434');
-- 1.173.0: kosten (prijzen per model, tegoed, maandbudget) en de extra's, elk apart uit te zetten
INSERT IGNORE INTO settings (name, value) VALUES ('ai_prices', '{}'), ('ai_credit', '{}'), ('ai_budget_month', '0'), ('ai_memory', '1'), ('ai_cache', '1'),
    ('ai_cache_hours', '6'), ('ai_fallback', '1'), ('ai_notify', '1'), ('ai_weekly', '1'), ('ai_weekly_day', '1'), ('ai_triage', '0'), ('ai_report', '1');

-- 1.177.0: pad-check (agent 1.78.0): metingen per hop van de laatste dagen, en wat er veranderde
CREATE TABLE IF NOT EXISTS path_runs (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id INT UNSIGNED NOT NULL,
    at       DATETIME NOT NULL,
    kind     VARCHAR(4) NOT NULL DEFAULT 'run',   -- run (het pad) | tp (doorvoer)
    data     TEXT NULL,
    INDEX idx_pr_check (check_id, at),
    INDEX idx_pr_at (at),
    CONSTRAINT fk_pr_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS path_events (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_id INT UNSIGNED NOT NULL,
    at       DATETIME NOT NULL,
    kind     VARCHAR(10) NOT NULL,   -- path | speed | tp
    text     VARCHAR(300) NOT NULL DEFAULT '',
    INDEX idx_pe_check (check_id, id),
    CONSTRAINT fk_pe_check FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('iperf_port', '5201');

-- 1.183.0: Netwerk → Ontdekken: wat er verandert op het netwerk, en wanneer een apparaat online is
CREATE TABLE IF NOT EXISTS net_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id   INT UNSIGNED NOT NULL,
    kind        VARCHAR(16)  NOT NULL,
    detail      TEXT         NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_netev_time (created_at),
    KEY idx_netev_dev (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS net_presence (
    device_id   INT UNSIGNED NOT NULL,
    day         DATE         NOT NULL,
    hours       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (device_id, day),
    KEY idx_netpres_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.185.0: een check gaat pas naar warning/critical (of weer naar OK) na zoveel controles achter elkaar; leeg = meteen (zoals altijd)
INSERT IGNORE INTO settings (name, value) VALUES ('confirm_fails', ''), ('confirm_ok', '');

-- 1.186.0: netwerkverkeer per apparaat (uit UniFi): per uur (30 dagen), per dag (een jaar) en de laatste tellerstand
CREATE TABLE IF NOT EXISTS net_traffic (
    device_id INT UNSIGNED NOT NULL,
    hour      DATETIME     NOT NULL,
    rx        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (device_id, hour),
    KEY idx_nettr_hour (hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS net_traffic_day (
    device_id INT UNSIGNED NOT NULL,
    day       DATE         NOT NULL,
    rx        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (device_id, day),
    KEY idx_nettrd_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS net_counters (
    device_id INT UNSIGNED NOT NULL PRIMARY KEY,
    rx        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_rx   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_tx   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    at        INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('net_traffic', '1'), ('net_traffic_swap', '0');

-- 1.187.0: het verkeer van de internetlijn per dag (uit de UniFi-gateway, anders de som van de apparaten) en de datalimiet
CREATE TABLE IF NOT EXISTS net_wan_day (
    day DATE NOT NULL PRIMARY KEY,
    rx  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    src VARCHAR(8) NOT NULL DEFAULT 'unifi'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('net_wan_cap_gb', '');

-- 1.189.0: stille uren als profielen met een naam, te koppelen aan groepen, servers en checks
CREATE TABLE IF NOT EXISTS quiet_profiles (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(60)  NOT NULL,
    icon      VARCHAR(16)  NOT NULL DEFAULT '🌙',
    windows   TEXT         NULL,
    crit      TINYINT(1)   NOT NULL DEFAULT 0,
    date_from DATE         NULL,
    date_to   DATE         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS quiet_groups (
    gkey VARCHAR(90) NOT NULL PRIMARY KEY,
    val  VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('quiet_global', '');
-- 1.193.0: status bevestigen per soort check, en sneller bevestigen (twijfelende snelle checks elke 20 s)
INSERT IGNORE INTO settings (name, value) VALUES ('confirm_types', ''), ('confirm_fast', '1');

-- 1.210.0: logs van servers verzamelen (agent 1.83.0). De bronnen per server, het gebruik per dag (voor de daglimiet en
-- het overzicht) en de pakketjes die al binnen zijn (een pakketje dat twee keer komt, wordt één keer opgeslagen).
-- De regels zelf staan in een tabel per dag (logd_JJJJMMDD), gecomprimeerd; die maakt LogStore::table() zelf aan,
-- zodat een oude dag in één keer weg kan en een database zonder compressie toch werkt.
CREATE TABLE IF NOT EXISTS log_sources (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    kind       VARCHAR(10)  NOT NULL,
    spec       VARCHAR(300) NOT NULL,
    level      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    status     VARCHAR(8)   NULL,
    status_msg VARCHAR(300) NULL,
    files      SMALLINT UNSIGNED NULL,
    last_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ls_host (host_id),
    CONSTRAINT fk_ls_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS log_daily (
    host_id   INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    day       DATE NOT NULL,
    `lines`   INT UNSIGNED NOT NULL DEFAULT 0,
    bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    dropped   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (host_id, source_id, day),
    INDEX idx_ld_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS log_batches (
    host_id INT UNSIGNED NOT NULL,
    batch   VARCHAR(40)  NOT NULL,
    at      DATETIME     NOT NULL,
    PRIMARY KEY (host_id, batch),
    INDEX idx_lb_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1.212.0: vaste zoekacties op de logs die een melding geven (pagina Logs › Vaste zoekacties)
CREATE TABLE IF NOT EXISTS log_watches (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    q          VARCHAR(500) NOT NULL,
    mode       VARCHAR(8)   NOT NULL DEFAULT 'many',   -- many: te veel treffers, none: geen treffers
    threshold  INT UNSIGNED NOT NULL DEFAULT 10,
    win        INT UNSIGNED NOT NULL DEFAULT 5,        -- venster in minuten
    per_host   TINYINT(1)   NOT NULL DEFAULT 1,        -- per server apart, of alle servers samen
    severity   TINYINT UNSIGNED NOT NULL DEFAULT 1,    -- 0 info, 1 warning, 2 critical
    rest       INT UNSIGNED NOT NULL DEFAULT 30,       -- rustperiode in minuten
    recover    TINYINT(1)   NOT NULL DEFAULT 1,        -- melding vanzelf sluiten (met een herstelmelding)
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    slow       TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- zoveel pakketjes achter elkaar te traag
    off_reason VARCHAR(20)  NULL,
    created_by VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS log_watch_counts (
    watch_id INT UNSIGNED NOT NULL,
    host_id  INT UNSIGNED NOT NULL,
    minute   INT UNSIGNED NOT NULL,   -- unixtijd / 60
    n        INT UNSIGNED NOT NULL,
    PRIMARY KEY (watch_id, host_id, minute),
    INDEX idx_lwc_minute (minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS log_watch_state (
    watch_id  INT UNSIGNED NOT NULL,
    host_id   INT UNSIGNED NOT NULL,   -- 0 = alle servers samen
    open      TINYINT(1)   NOT NULL DEFAULT 0,
    opened_at DATETIME     NULL,
    last_sent DATETIME     NULL,
    last_hit  DATETIME     NULL,
    since     DATETIME     NULL,       -- sinds wanneer we kijken (voor geen treffers)
    peak      INT UNSIGNED NOT NULL DEFAULT 0,
    alert_id  BIGINT UNSIGNED NULL,
    PRIMARY KEY (watch_id, host_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('logs_on', '1'), ('logs_interval', '5'), ('logs_days', '14'), ('logs_max_gb', '5'),
    ('logs_day_mb', '200'), ('logs_mask', '1'), ('logs_mask_extra', '');

-- 1.222.0: aanvallen per site, uit de nginx-log van de reverse proxy met de site en de dienst erachter (agent 1.86.0).
-- Per uur, site en aanvaller (alleen verdachte IP-adressen van buiten); site '' = zonder site (je IP-adres, 444).
CREATE TABLE IF NOT EXISTS web_sites (
    host_id INT UNSIGNED NOT NULL,
    hour    DATETIME     NOT NULL,
    site    VARCHAR(190) NOT NULL,
    ip      VARCHAR(45)  NOT NULL,
    n       INT UNSIGNED NOT NULL DEFAULT 0,
    ok      INT UNSIGNED NOT NULL DEFAULT 0,
    up      VARCHAR(80)  NOT NULL DEFAULT '',
    ctr     VARCHAR(64)  NOT NULL DEFAULT '',
    top     VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (host_id, hour, site, ip),
    INDEX idx_ws_hour (hour),
    CONSTRAINT fk_ws_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.223.0: slimme oordelen bij IDS-incidenten, per soort (vingerafdruk): van jou (u, verloopt niet), van de AI (a, 30 dagen)
CREATE TABLE IF NOT EXISTS ids_triage (
    fp         CHAR(16)     NOT NULL PRIMARY KEY,
    verdict    VARCHAR(10)  NOT NULL,
    src        CHAR(1)      NOT NULL,
    why        VARCHAR(255) NOT NULL DEFAULT '',
    by_user    VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL,
    expires_at DATETIME     NULL,
    n          INT UNSIGNED NOT NULL DEFAULT 0,
    last_used  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('ai_triage_max', '20');

-- 1.226.0: logboek per server: wat de agent, RzSense of een beheerder op een server deed (acties, ingrepen, uitkomsten).
-- source: user (een beheerder), rzsense (RzSense zelf, bv. automatisch isoleren), agent (de agent meldt wat hij deed).
-- ok: 1 gelukt, 0 mislukt, NULL ter info. ref: kenmerk van de agent (tegen dubbel opslaan bij opnieuw versturen).
CREATE TABLE IF NOT EXISTS host_actions (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id  INT UNSIGNED NULL,
    at       DATETIME     NOT NULL,
    source   VARCHAR(10)  NOT NULL DEFAULT 'rzsense',
    module   VARCHAR(20)  NOT NULL DEFAULT 'agent',
    action   VARCHAR(80)  NOT NULL,
    detail   VARCHAR(1000) NOT NULL DEFAULT '',
    ok       TINYINT(1)   NULL,
    username VARCHAR(64)  NOT NULL DEFAULT '',
    ip       VARCHAR(45)  NOT NULL DEFAULT '',
    ref      VARCHAR(40)  NULL,
    UNIQUE KEY uq_host_actions_ref (host_id, ref),
    INDEX idx_host_actions_host (host_id, at),
    INDEX idx_host_actions_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('actlog_days', '365');
-- 1.227.0: e-mailbeveiliging (Domein-check) en end-of-life, standaard aan
INSERT IGNORE INTO settings (name, value) VALUES ('mailsec_on', '1'), ('eol_on', '1');
-- 1.228.0: ransomware herkennen (per server de mappen in de Agent Manager); ondergrens voor "heel veel tegelijk"
INSERT IGNORE INTO settings (name, value) VALUES ('ransom_on', '1'), ('ransom_min', '300');

-- 1.229.0: programma's per server (agent 1.91.0): wat er draaide, met vingerafdruk (sha256) en het pakket waar het bij hoort.
-- status: pkg (uit een pakket), learn (gezien in de leerperiode), ok (toegestaan), new (onbekend), block (geblokkeerd).
CREATE TABLE IF NOT EXISTS app_seen (
    host_id    INT UNSIGNED NOT NULL,
    sha        CHAR(64)     NOT NULL,
    path       VARCHAR(300) NOT NULL,
    pkg        VARCHAR(80)  NOT NULL DEFAULT '',
    username   VARCHAR(64)  NOT NULL DEFAULT '',
    cmd        VARCHAR(300) NOT NULL DEFAULT '',
    status     VARCHAR(5)   NOT NULL DEFAULT 'new',
    first_seen DATETIME     NOT NULL,
    last_seen  DATETIME     NOT NULL,
    PRIMARY KEY (host_id, sha, path),
    INDEX idx_apps_sha (sha),
    INDEX idx_apps_status (status, last_seen),
    CONSTRAINT fk_apps_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Wat je besliste: toestaan of blokkeren, op vingerafdruk of op pad (een pad dat eindigt op / = alles in die map);
-- host_id 0 = alle servers
CREATE TABLE IF NOT EXISTS app_rules (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL DEFAULT 0,
    sha        CHAR(64)     NOT NULL DEFAULT '',
    path       VARCHAR(300) NOT NULL DEFAULT '',
    action     VARCHAR(5)   NOT NULL,
    note       VARCHAR(200) NOT NULL DEFAULT '',
    username   VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL,
    INDEX idx_appr_sha (sha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1.229.0: jagen op sporen (IOC): bewaakte sporen (elke 10 minuten vergeleken met wat er nieuw binnenkwam) en eerdere jachten
CREATE TABLE IF NOT EXISTS ioc_watch (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(6)   NOT NULL,
    value      VARCHAR(253) NOT NULL,
    note       VARCHAR(200) NOT NULL DEFAULT '',
    username   VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL,
    last_hit   DATETIME     NULL,
    hits       INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_ioc_watch (kind, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ioc_hunts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME     NOT NULL,
    username   VARCHAR(100) NULL,
    iocs       TEXT         NOT NULL,
    days       SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    hits       INT UNSIGNED NOT NULL DEFAULT 0,
    result     MEDIUMTEXT   NULL,
    agents     TEXT         NULL,
    INDEX idx_iochunt_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('appctl_on', '1'), ('appctl_learn', '7'), ('ioc_on', '1');
-- 1.230.0: updates installeren (patchbeheer, per server in te stellen; standaard met de hand)
INSERT IGNORE INTO settings (name, value) VALUES ('patch_on', '1');

-- 1.231.0: tijdelijk sudo (agent 1.93.0): per server een gebruiker voor korte tijd sudo, met wie, waarom en de stand.
-- state: pending (wacht op de agent), active, expired (tijd voorbij), revoked (ingetrokken), replaced, failed
CREATE TABLE IF NOT EXISTS sudo_grants (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host_id    INT UNSIGNED NOT NULL,
    username   VARCHAR(32)  NOT NULL,
    until      DATETIME     NOT NULL,
    reason     VARCHAR(200) NOT NULL DEFAULT '',
    by_user    VARCHAR(100) NULL,
    created_at DATETIME     NOT NULL,
    state      VARCHAR(8)   NOT NULL DEFAULT 'pending',
    detail     VARCHAR(200) NULL,
    ended_at   DATETIME     NULL,
    ended_by   VARCHAR(100) NULL,
    INDEX idx_sudo_host (host_id, ended_at),
    CONSTRAINT fk_sudo_host FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1.231.0: nieuw geregistreerde domeinen (DnsWatch): welke hoofddomeinen al gezien zijn, en hun registratiedatum (RDAP)
CREATE TABLE IF NOT EXISTS dns_seen (
    domain      VARCHAR(253) NOT NULL PRIMARY KEY,
    first_seen  DATETIME     NOT NULL,
    client      VARCHAR(45)  NOT NULL DEFAULT '',
    client_name VARCHAR(100) NOT NULL DEFAULT '',
    checked_at  DATETIME     NULL,
    created     DATE         NULL,
    note        VARCHAR(100) NULL,
    INDEX idx_dnsseen_check (checked_at, first_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO settings (name, value) VALUES ('sudo_on', '1'), ('sudo_max', '240'), ('dnsbl_on', '0'), ('dnsbl_ti', '1'), ('dnsbl_ioc', '1'),
    ('dnsbl_own', ''), ('dnsbl_allow', ''), ('dns_nrd', '0'), ('dns_nrd_days', '30');
