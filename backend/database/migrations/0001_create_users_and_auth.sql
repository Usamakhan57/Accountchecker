-- ---------------------------------------------------------------------------
-- 0001 - Users, roles and session storage.
--
-- Roles are a table rather than an enum column so SUPPORT/MANAGER/SUPER_ADMIN
-- can be added without a schema change. Every user has exactly one primary
-- role on users.role_id for the common check, with user_roles carrying any
-- additional grants.
-- ---------------------------------------------------------------------------

CREATE TABLE roles (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(32)      NOT NULL,
    name            VARCHAR(64)      NOT NULL,
    description     VARCHAR(255)     NOT NULL DEFAULT '',
    is_staff        TINYINT(1)       NOT NULL DEFAULT 0,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(64)       NOT NULL,
    description     VARCHAR(255)      NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id         TINYINT UNSIGNED  NOT NULL,
    permission_id   SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
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

CREATE TABLE user_roles (
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
CREATE TABLE user_sessions (
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
CREATE TABLE auth_tokens (
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
CREATE TABLE rate_limits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket_key      VARCHAR(191)    NOT NULL,
    attempts        INT UNSIGNED    NOT NULL DEFAULT 0,
    window_start    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP       NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_limits_key (bucket_key),
    KEY idx_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
