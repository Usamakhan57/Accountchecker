-- ---------------------------------------------------------------------------
-- AccountCheck - consolidated schema
--
-- Every migration in database/migrations, in order, as one file. Use it to
-- provision an empty database in a single step:
--
--     mysql -u accountcheck_app -p accountcheck < backend/database/schema.sql
--     php backend/database/seed.php
--
-- For a database that already exists, run the migration runner instead — it
-- records what has been applied and skips it next time:
--
--     php backend/database/migrate.php
--
-- Note: loading this file directly does NOT populate the `migrations` table.
-- Run `php backend/database/migrate.php` afterwards; it creates that table and
-- every CREATE here is guarded, so nothing is applied twice.
--
-- Regenerate after adding a migration:
--
--     php backend/database/build-schema.php
--
-- Target: MySQL 8.0+ (verified against MariaDB 10.11 as well). InnoDB, utf8mb4.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-------------------------------------------------------------------------------
-- Source: database/migrations/0001_create_users_and_auth.sql
-------------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 0001 - Users, roles and session storage.
--
-- Roles are a table rather than an enum column so SUPPORT/MANAGER/SUPER_ADMIN
-- can be added without a schema change. Every user has exactly one primary
-- role on users.role_id for the common check, with user_roles carrying any
-- additional grants.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(32)      NOT NULL,
    name            VARCHAR(64)      NOT NULL,
    description     VARCHAR(255)     NOT NULL DEFAULT '',
    is_staff        TINYINT(1)       NOT NULL DEFAULT 0,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(64)       NOT NULL,
    description     VARCHAR(255)      NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id         TINYINT UNSIGNED  NOT NULL,
    permission_id   SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)         NOT NULL,
    name                VARCHAR(120)     NOT NULL,
    email               VARCHAR(254)     NOT NULL,
    -- password_hash() output; bcrypt is 60 chars, argon2id needs ~96, and the
    -- column is sized so a future algorithm change needs no migration.
    password_hash       VARCHAR(255)     NOT NULL,
    role_id             TINYINT UNSIGNED NOT NULL,
    status              ENUM('ACTIVE', 'SUSPENDED', 'PENDING') NOT NULL DEFAULT 'ACTIVE',
    email_verified_at   TIMESTAMP        NULL DEFAULT NULL,
    last_login_at       TIMESTAMP        NULL DEFAULT NULL,
    last_login_ip       VARBINARY(16)    NULL DEFAULT NULL,
    created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_uuid (uuid),
    KEY idx_users_role (role_id),
    KEY idx_users_status_created (status, created_at),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id     BIGINT UNSIGNED  NOT NULL,
    role_id     TINYINT UNSIGNED NOT NULL,
    granted_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions store only a SHA-256 of the cookie token, so a database leak does
-- not hand out live sessions.
CREATE TABLE IF NOT EXISTS user_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    ip_address      VARBINARY(16)   NULL DEFAULT NULL,
    user_agent      VARCHAR(255)    NOT NULL DEFAULT '',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_active_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP       NOT NULL,
    revoked_at      TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_token (token_hash),
    KEY idx_user_sessions_user (user_id, revoked_at),
    KEY idx_user_sessions_expiry (expires_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset and email verification tokens, hashed the same way.
CREATE TABLE IF NOT EXISTS auth_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    purpose     ENUM('PASSWORD_RESET', 'EMAIL_VERIFICATION') NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  TIMESTAMP       NOT NULL,
    consumed_at TIMESTAMP       NULL DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user_purpose (user_id, purpose, consumed_at),
    KEY idx_auth_tokens_expiry (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting counters. Keyed by "bucket:identifier" so the same table backs
-- per-IP login throttling and per-user API limits.
CREATE TABLE IF NOT EXISTS rate_limits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket_key      VARCHAR(191)    NOT NULL,
    attempts        INT UNSIGNED    NOT NULL DEFAULT 0,
    window_start    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP       NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_limits_key (bucket_key),
    KEY idx_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-------------------------------------------------------------------------------
-- Source: database/migrations/0002_create_wallet_and_plans.sql
-------------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 0002 - Wallet, transactions, plans and subscriptions.
--
-- Balances are whole credits in BIGINT, never a float: credit arithmetic must
-- be exact, and a fractional credit has no meaning in this product.
--
-- `reserved` holds credits committed to a running job. available = balance -
-- reserved, and the application only ever spends from what a job reserved, so
-- two concurrent jobs cannot both spend the same credits.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS wallets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    balance     BIGINT          NOT NULL DEFAULT 0,
    reserved    BIGINT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallets_user (user_id),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    -- Defence in depth: the service layer checks these too, but the database
    -- refuses to hold a negative or over-reserved balance whatever happens
    -- above it.
    CONSTRAINT ck_wallets_balance_non_negative CHECK (balance >= 0),
    CONSTRAINT ck_wallets_reserved_valid CHECK (reserved >= 0 AND reserved <= balance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wallet_id       BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    -- Signed: CREDIT and REFUND are positive, DEBIT negative, ADJUSTMENT either.
    amount          BIGINT          NOT NULL,
    type            ENUM('CREDIT', 'DEBIT', 'REFUND', 'ADJUSTMENT') NOT NULL,
    balance_after   BIGINT          NOT NULL,
    description     VARCHAR(255)    NOT NULL DEFAULT '',
    reference       VARCHAR(191)    NULL DEFAULT NULL,
    performed_by    BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wallet_transactions_user_created (user_id, created_at),
    KEY idx_wallet_transactions_wallet (wallet_id, id),
    KEY idx_wallet_transactions_type (type, created_at),
    KEY idx_wallet_transactions_reference (reference),
    CONSTRAINT fk_wallet_transactions_wallet
        FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_transactions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_transactions_performer
        FOREIGN KEY (performed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(48)       NOT NULL,
    name            VARCHAR(80)       NOT NULL,
    description     VARCHAR(255)      NOT NULL DEFAULT '',
    -- Money in minor units, never a float.
    price_cents     INT UNSIGNED      NOT NULL DEFAULT 0,
    currency        CHAR(3)           NOT NULL DEFAULT 'USD',
    credits         BIGINT            NOT NULL DEFAULT 0,
    -- JSON array of feature strings, rendered by the pricing page.
    features        JSON              NULL,
    is_active       TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order      SMALLINT          NOT NULL DEFAULT 0,
    created_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_slug (slug),
    KEY idx_plans_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED   NOT NULL,
    plan_id         SMALLINT UNSIGNED NOT NULL,
    status          ENUM('ACTIVE', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'ACTIVE',
    started_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at         TIMESTAMP         NULL DEFAULT NULL,
    cancelled_at    TIMESTAMP         NULL DEFAULT NULL,
    created_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscriptions_user_status (user_id, status),
    KEY idx_subscriptions_plan (plan_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-------------------------------------------------------------------------------
-- Source: database/migrations/0003_create_checkers_and_jobs.sql
-------------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 0003 - Checker registry, jobs, job items and results.
--
-- A job is the unit a user starts; a job item is one record inside it. The
-- worker claims items in chunks with an UPDATE ... WHERE guard, so two workers
-- can never take the same item. Provider credentials live in configuration,
-- not in this table.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS checker_types (
    id                      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug                    VARCHAR(48)       NOT NULL,
    label                   VARCHAR(80)       NOT NULL,
    category                ENUM('email', 'platform', 'utility') NOT NULL DEFAULT 'platform',
    input_kind              ENUM('email', 'username') NOT NULL DEFAULT 'username',
    description             VARCHAR(500)      NOT NULL DEFAULT '',
    credit_cost             INT UNSIGNED      NOT NULL DEFAULT 1,
    max_batch_size          INT UNSIGNED      NOT NULL DEFAULT 5000,
    rate_limit_per_minute   INT UNSIGNED      NOT NULL DEFAULT 300,
    -- An administrator can switch a checker off without deploying.
    is_enabled              TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order              SMALLINT          NOT NULL DEFAULT 0,
    created_at              TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checker_types_slug (slug),
    KEY idx_checker_types_enabled (is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checker_jobs (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)          NOT NULL,
    user_id             BIGINT UNSIGNED   NOT NULL,
    checker_type_id     SMALLINT UNSIGNED NOT NULL,
    status              ENUM('PENDING', 'QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED')
                        NOT NULL DEFAULT 'PENDING',
    total_items         INT UNSIGNED      NOT NULL DEFAULT 0,
    processed_items     INT UNSIGNED      NOT NULL DEFAULT 0,
    successful_items    INT UNSIGNED      NOT NULL DEFAULT 0,
    failed_items        INT UNSIGNED      NOT NULL DEFAULT 0,
    -- Credits held for this job, and what has actually been settled so far.
    credits_reserved    BIGINT            NOT NULL DEFAULT 0,
    credits_spent       BIGINT            NOT NULL DEFAULT 0,
    credit_cost_each    INT UNSIGNED      NOT NULL DEFAULT 1,
    -- Per-job options (output format, chosen detection type, …).
    options             JSON              NULL,
    source              ENUM('PASTE', 'UPLOAD', 'API') NOT NULL DEFAULT 'PASTE',
    -- User-facing failure reason; never a stack trace.
    error_message       VARCHAR(500)      NULL DEFAULT NULL,
    -- Worker lease. A job whose locked_at is older than the lock TTL is
    -- considered abandoned and is recovered on the next worker pass.
    worker_id           VARCHAR(64)       NULL DEFAULT NULL,
    locked_at           TIMESTAMP         NULL DEFAULT NULL,
    queued_at           TIMESTAMP         NULL DEFAULT NULL,
    started_at          TIMESTAMP         NULL DEFAULT NULL,
    completed_at        TIMESTAMP         NULL DEFAULT NULL,
    created_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checker_jobs_uuid (uuid),
    KEY idx_checker_jobs_user_created (user_id, created_at),
    KEY idx_checker_jobs_user_status (user_id, status, created_at),
    -- The worker's claim query: pending jobs, oldest first.
    KEY idx_checker_jobs_claim (status, locked_at, id),
    KEY idx_checker_jobs_type (checker_type_id, created_at),
    CONSTRAINT fk_checker_jobs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_checker_jobs_type FOREIGN KEY (checker_type_id) REFERENCES checker_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checker_job_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id          BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    position        INT UNSIGNED    NOT NULL DEFAULT 0,
    -- The line exactly as the user supplied it, so exports can echo it back.
    raw_input       VARCHAR(512)    NOT NULL,
    -- The value actually checked (lower-cased email, stripped @handle, …).
    normalized_input VARCHAR(512)   NOT NULL,
    status          ENUM('PENDING', 'PROCESSING', 'DONE', 'FAILED', 'SKIPPED') NOT NULL DEFAULT 'PENDING',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    worker_id       VARCHAR(64)     NULL DEFAULT NULL,
    locked_at       TIMESTAMP       NULL DEFAULT NULL,
    processed_at    TIMESTAMP       NULL DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_job_items_job_status (job_id, status, id),
    KEY idx_job_items_claim (status, locked_at, id),
    KEY idx_job_items_user (user_id, created_at),
    CONSTRAINT fk_job_items_job FOREIGN KEY (job_id) REFERENCES checker_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_items_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checker_results (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    job_id              BIGINT UNSIGNED   NOT NULL,
    job_item_id         BIGINT UNSIGNED   NOT NULL,
    user_id             BIGINT UNSIGNED   NOT NULL,
    checker_type_id     SMALLINT UNSIGNED NOT NULL,
    raw_input           VARCHAR(512)      NOT NULL,
    normalized_input    VARCHAR(512)      NOT NULL,
    -- UNAVAILABLE means no authorized verification source could be used, which
    -- is deliberately distinct from INVALID (the source said "no").
    status              ENUM('VALID', 'INVALID', 'UNKNOWN', 'ERROR', 'UNAVAILABLE') NOT NULL,
    reason              VARCHAR(255)      NULL DEFAULT NULL,
    -- Which authorized source answered, or 'mock' / 'local'.
    source              VARCHAR(64)       NOT NULL DEFAULT '',
    response_time_ms    INT UNSIGNED      NULL DEFAULT NULL,
    -- Normalized extra fields from the adapter (followers, display name, …).
    metadata            JSON              NULL,
    checked_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checker_results_item (job_item_id),
    -- The results table's own filter/sort path.
    KEY idx_results_user_checked (user_id, checked_at),
    KEY idx_results_job_status (job_id, status, id),
    KEY idx_results_user_status (user_id, status, checked_at),
    KEY idx_results_user_type (user_id, checker_type_id, checked_at),
    KEY idx_results_input (normalized_input(128)),
    CONSTRAINT fk_results_job FOREIGN KEY (job_id) REFERENCES checker_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_item FOREIGN KEY (job_item_id) REFERENCES checker_job_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_type FOREIGN KEY (checker_type_id) REFERENCES checker_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per running worker process, refreshed each loop. /api/health/worker
-- and the admin system-health panel read it.
CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_id       VARCHAR(64)     NOT NULL,
    hostname        VARCHAR(120)    NOT NULL DEFAULT '',
    pid             INT UNSIGNED    NOT NULL DEFAULT 0,
    jobs_processed  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    items_processed BIGINT UNSIGNED NOT NULL DEFAULT 0,
    started_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_id),
    KEY idx_worker_heartbeats_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-------------------------------------------------------------------------------
-- Source: database/migrations/0004_create_support_and_activity.sql
-------------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 0004 - History, notifications, support, exports, logging and settings.
-- ---------------------------------------------------------------------------

-- A denormalised row per job for the history page, so listing history never
-- joins across jobs, items and results.
CREATE TABLE IF NOT EXISTS search_history (
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

CREATE TABLE IF NOT EXISTS notifications (
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

CREATE TABLE IF NOT EXISTS support_tickets (
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

CREATE TABLE IF NOT EXISTS support_messages (
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
CREATE TABLE IF NOT EXISTS exports (
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
CREATE TABLE IF NOT EXISTS activity_logs (
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
CREATE TABLE IF NOT EXISTS audit_logs (
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
CREATE TABLE IF NOT EXISTS api_keys (
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

CREATE TABLE IF NOT EXISTS api_usage (
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
CREATE TABLE IF NOT EXISTS system_settings (
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

SET FOREIGN_KEY_CHECKS = 1;
