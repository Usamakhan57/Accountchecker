-- ---------------------------------------------------------------------------
-- 0004 - History, notifications, support, exports, logging and settings.
-- ---------------------------------------------------------------------------

-- A denormalised row per job for the history page, so listing history never
-- joins across jobs, items and results.
CREATE TABLE search_history (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED   NOT NULL,
    job_id              BIGINT UNSIGNED   NULL DEFAULT NULL,
    checker_type_id     SMALLINT UNSIGNED NULL DEFAULT NULL,
    checker_slug        VARCHAR(48)       NOT NULL DEFAULT '',
    total_items         INT UNSIGNED      NOT NULL DEFAULT 0,
    successful_items    INT UNSIGNED      NOT NULL DEFAULT 0,
    failed_items        INT UNSIGNED      NOT NULL DEFAULT 0,
    status              VARCHAR(24)       NOT NULL DEFAULT '',
    credits_spent       BIGINT            NOT NULL DEFAULT 0,
    created_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_search_history_user_created (user_id, created_at),
    KEY idx_search_history_job (job_id),
    CONSTRAINT fk_search_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_search_history_job FOREIGN KEY (job_id) REFERENCES checker_jobs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(48)     NOT NULL,
    title       VARCHAR(160)    NOT NULL,
    body        VARCHAR(500)    NOT NULL DEFAULT '',
    -- In-app path such as /jobs/12, never an external URL.
    link        VARCHAR(191)    NULL DEFAULT NULL,
    read_at     TIMESTAMP       NULL DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_created (user_id, created_at),
    -- The unread-count query.
    KEY idx_notifications_user_unread (user_id, read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    subject         VARCHAR(160)    NOT NULL,
    status          ENUM('OPEN', 'PENDING', 'RESOLVED', 'CLOSED') NOT NULL DEFAULT 'OPEN',
    priority        ENUM('LOW', 'NORMAL', 'HIGH') NOT NULL DEFAULT 'NORMAL',
    assigned_to     BIGINT UNSIGNED NULL DEFAULT NULL,
    last_reply_at   TIMESTAMP       NULL DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_tickets_uuid (uuid),
    KEY idx_support_tickets_user (user_id, created_at),
    KEY idx_support_tickets_status (status, updated_at),
    KEY idx_support_tickets_assignee (assigned_to, status),
    CONSTRAINT fk_support_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_support_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_messages (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NULL DEFAULT NULL,
    -- True when written by staff, so the thread can be rendered two-sided
    -- without exposing which staff account replied.
    is_staff    TINYINT(1)      NOT NULL DEFAULT 0,
    body        TEXT            NOT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_support_messages_ticket (ticket_id, created_at),
    CONSTRAINT fk_support_messages_ticket
        FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_support_messages_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generated export files. Only the basename is stored; the directory comes
-- from configuration, so a stored value can never point outside it.
CREATE TABLE exports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    job_id          BIGINT UNSIGNED NULL DEFAULT NULL,
    format          ENUM('csv', 'txt') NOT NULL DEFAULT 'csv',
    filename        VARCHAR(160)    NOT NULL,
    row_count       INT UNSIGNED    NOT NULL DEFAULT 0,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('READY', 'EXPIRED', 'DELETED') NOT NULL DEFAULT 'READY',
    expires_at      TIMESTAMP       NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exports_uuid (uuid),
    KEY idx_exports_user_created (user_id, created_at),
    KEY idx_exports_expiry (status, expires_at),
    CONSTRAINT fk_exports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_exports_job FOREIGN KEY (job_id) REFERENCES checker_jobs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product activity: what a user did. Never holds credentials or tokens.
CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL DEFAULT NULL,
    action      VARCHAR(64)     NOT NULL,
    subject_type VARCHAR(48)    NULL DEFAULT NULL,
    subject_id  BIGINT UNSIGNED NULL DEFAULT NULL,
    context     JSON            NULL,
    ip_address  VARBINARY(16)   NULL DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_logs_user_created (user_id, created_at),
    KEY idx_activity_logs_action (action, created_at),
    CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security-relevant events: sign-ins, role changes, wallet adjustments,
-- checker configuration changes. Append-only by convention.
CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id        BIGINT UNSIGNED NULL DEFAULT NULL,
    actor_role      VARCHAR(32)     NOT NULL DEFAULT '',
    event           VARCHAR(64)     NOT NULL,
    target_type     VARCHAR(48)     NULL DEFAULT NULL,
    target_id       BIGINT UNSIGNED NULL DEFAULT NULL,
    severity        ENUM('INFO', 'NOTICE', 'WARNING', 'CRITICAL') NOT NULL DEFAULT 'INFO',
    context         JSON            NULL,
    ip_address      VARBINARY(16)   NULL DEFAULT NULL,
    user_agent      VARCHAR(255)    NOT NULL DEFAULT '',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_created (created_at),
    KEY idx_audit_logs_actor (actor_id, created_at),
    KEY idx_audit_logs_event (event, created_at),
    KEY idx_audit_logs_severity (severity, created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Programmatic access. Only the hash is stored; the key is shown once.
CREATE TABLE api_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(80)     NOT NULL,
    key_prefix      CHAR(12)        NOT NULL,
    key_hash        CHAR(64)        NOT NULL,
    last_used_at    TIMESTAMP       NULL DEFAULT NULL,
    expires_at      TIMESTAMP       NULL DEFAULT NULL,
    revoked_at      TIMESTAMP       NULL DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_hash (key_hash),
    KEY idx_api_keys_user (user_id, revoked_at),
    KEY idx_api_keys_prefix (key_prefix),
    CONSTRAINT fk_api_keys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_usage (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL DEFAULT NULL,
    api_key_id      BIGINT UNSIGNED NULL DEFAULT NULL,
    endpoint        VARCHAR(160)    NOT NULL,
    method          VARCHAR(8)      NOT NULL DEFAULT 'GET',
    status_code     SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    duration_ms     INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_usage_user_created (user_id, created_at),
    KEY idx_api_usage_endpoint (endpoint, created_at),
    CONSTRAINT fk_api_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_api_usage_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Runtime settings an administrator can change. `is_public` marks the few that
-- may be sent to the browser; everything else stays server-side.
CREATE TABLE system_settings (
    setting_key     VARCHAR(80)  NOT NULL,
    setting_value   TEXT         NULL,
    value_type      ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string',
    description     VARCHAR(255) NOT NULL DEFAULT '',
    is_public       TINYINT(1)   NOT NULL DEFAULT 0,
    updated_by      BIGINT UNSIGNED NULL DEFAULT NULL,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    KEY idx_system_settings_public (is_public),
    CONSTRAINT fk_system_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
