-- Indexes added after measuring the hot queries against a populated database.
--
-- Each one here fixed a query that read far more rows than it returned. The
-- measurements were taken against 170,000 results across 2,000 jobs; the
-- numbers in the comments are medians from that run.

-- The admin checker table aggregates every result in a 30-day window, grouped
-- by checker. Without a composite index the only usable key is the foreign
-- key on checker_type_id alone, so the server reads each matching row to get
-- checked_at and status. This index carries all three, so the aggregate is
-- satisfied from the index without touching a row.
--
-- 407 ms -> 46 ms.
CREATE INDEX idx_results_type_checked_status
    ON checker_results (checker_type_id, checked_at, status);

-- The system health panel counts errors in the last hour across every
-- account. No existing index leads with status, so that count scanned the
-- table.
--
-- 29 ms -> under 1 ms.
CREATE INDEX idx_results_status_checked
    ON checker_results (status, checked_at);

-- The admin job list filters and orders by created_at across all accounts,
-- where every user-scoped index leads with user_id and so cannot be used.
CREATE INDEX idx_checker_jobs_created
    ON checker_jobs (created_at, id);

-- The admin ledger reads recent transactions across all accounts, and the
-- ledger's own indexes lead with user_id for the same reason.
CREATE INDEX idx_wallet_transactions_created
    ON wallet_transactions (created_at, id);
