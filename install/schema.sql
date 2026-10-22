-- RzMon database schema (MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(64) PRIMARY KEY,
    value TEXT NULL
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
    type            ENUM('ping','tcp','http','ssl','disk','service','cpu','ram','podman','container','log','ha','command','snmp','reboot') NOT NULL,
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

-- "Onthoud mij op dit apparaat": per apparaat een sleutel (alleen de SHA-256 hash wordt opgeslagen).
-- De sleutel wordt bij elk gebruik vervangen; prev_hash blijft 60 s geldig voor gelijktijdige verzoeken.
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
ALTER TABLE checks MODIFY type ENUM('ping','tcp','http','ssl','disk','service','cpu','ram','podman','container','log','ha','command','snmp','reboot') NOT NULL;
ALTER TABLE agent_data MODIFY value TEXT NOT NULL;

INSERT IGNORE INTO settings (name, value) VALUES
    ('pushover_enabled', '0'),
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
    -- RzMon bijwerken: met de knop; automatisch uit (anders om 04:00), altijd eerst een back-up
    ('update_auto', '0'),
    ('update_time', '04:00'),
    ('update_backup', '1'),
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
