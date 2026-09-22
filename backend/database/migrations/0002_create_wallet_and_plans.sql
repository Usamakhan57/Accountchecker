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

CREATE TABLE wallets (
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

CREATE TABLE wallet_transactions (
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

CREATE TABLE plans (
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

CREATE TABLE subscriptions (
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
