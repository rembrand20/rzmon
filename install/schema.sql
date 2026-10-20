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
    type            ENUM('ping','tcp','http','ssl','disk','service','cpu','ram','podman','container','log') NOT NULL,
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

-- Sleutels voor de REST API (alleen de SHA-256 hash wordt opgeslagen)
CREATE TABLE IF NOT EXISTS api_keys (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    key_hash   CHAR(64) NOT NULL UNIQUE,
    key_hint   VARCHAR(12) NOT NULL,
    last_used  DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent-rapporten die geweigerd zijn (voor de diagnose in "Agent installeren")
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

-- Upgrade van oudere versies (veilig om opnieuw uit te voeren)
ALTER TABLE checks MODIFY type ENUM('ping','tcp','http','ssl','disk','service','cpu','ram','podman','container','log') NOT NULL;
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
    ('agent_auto_update', '0');
