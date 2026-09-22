-- ---------------------------------------------------------------------------
-- 0003 - Checker registry, jobs, job items and results.
--
-- A job is the unit a user starts; a job item is one record inside it. The
-- worker claims items in chunks with an UPDATE ... WHERE guard, so two workers
-- can never take the same item. Provider credentials live in configuration,
-- not in this table.
-- ---------------------------------------------------------------------------

CREATE TABLE checker_types (
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

CREATE TABLE checker_jobs (
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

CREATE TABLE checker_job_items (
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

CREATE TABLE checker_results (
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
CREATE TABLE worker_heartbeats (
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
