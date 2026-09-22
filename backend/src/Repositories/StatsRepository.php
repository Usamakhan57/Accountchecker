<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

/**
 * Aggregate queries behind the user dashboard and the admin overview.
 *
 * Every figure is counted from real rows. A new account sees zeros, which the
 * dashboard renders as an empty state rather than inventing activity.
 */
final class StatsRepository extends Repository
{
    /**
     * Totals for one user's dashboard.
     *
     * @return array<string, int>
     */
    public function userTotals(int $userId): array
    {
        $jobs = $this->database->selectOne(
            'SELECT
                COUNT(*) AS jobs,
                COALESCE(SUM(processed_items), 0) AS checks,
                COALESCE(SUM(credits_spent), 0) AS credits_spent
             FROM checker_jobs
             WHERE user_id = :user_id',
            ['user_id' => $userId],
        ) ?? [];

        $byStatus = $this->database->select(
            'SELECT status, COUNT(*) AS total
             FROM checker_results
             WHERE user_id = :user_id
             GROUP BY status',
            ['user_id' => $userId],
        );

        $statuses = ['VALID' => 0, 'INVALID' => 0, 'UNKNOWN' => 0, 'ERROR' => 0, 'UNAVAILABLE' => 0];
        foreach ($byStatus as $row) {
            $statuses[(string) $row['status']] = (int) $row['total'];
        }

        return [
            'jobs' => (int) ($jobs['jobs'] ?? 0),
            'checks' => (int) ($jobs['checks'] ?? 0),
            'credits_spent' => (int) ($jobs['credits_spent'] ?? 0),
            'valid' => $statuses['VALID'],
            'invalid' => $statuses['INVALID'],
            'unknown' => $statuses['UNKNOWN'],
            'errors' => $statuses['ERROR'],
            'unavailable' => $statuses['UNAVAILABLE'],
        ];
    }

    /**
     * Daily check counts for the usage chart.
     *
     * The gaps are filled in PHP so the series always has one point per day,
     * which keeps the chart honest about days with no activity.
     *
     * @return list<array{date: string, checks: int}>
     */
    public function userUsageByDay(int $userId, int $days = 14): array
    {
        $days = max(1, min(90, $days));

        $rows = $this->database->select(
            'SELECT DATE(checked_at) AS day, COUNT(*) AS total
             FROM checker_results
             WHERE user_id = :user_id
               AND checked_at >= DATE_SUB(UTC_DATE(), INTERVAL :days DAY)
             GROUP BY DATE(checked_at)
             ORDER BY day',
            ['user_id' => $userId, 'days' => $days],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['day']] = (int) $row['total'];
        }

        $series = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime('-' . $offset . ' days'));
            $series[] = ['date' => $date, 'checks' => $counts[$date] ?? 0];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> */
    public function recentJobsForUser(int $userId, int $limit = 5): array
    {
        return $this->database->select(
            'SELECT j.id, j.uuid, j.status, j.total_items, j.processed_items, j.successful_items,
                    j.failed_items, j.credits_reserved, j.credits_spent, j.error_message,
                    j.created_at, j.started_at, j.completed_at,
                    c.slug AS checker_slug, c.label AS checker_label
             FROM checker_jobs j
             INNER JOIN checker_types c ON c.id = j.checker_type_id
             WHERE j.user_id = :user_id
             ORDER BY j.id DESC
             LIMIT :limit',
            ['user_id' => $userId, 'limit' => max(1, min(25, $limit))],
        );
    }

    /**
     * Platform-wide figures for the admin overview.
     *
     * @return array<string, mixed>
     */
    public function platformTotals(): array
    {
        $users = $this->database->selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(status = \'ACTIVE\') AS active,
                SUM(status = \'SUSPENDED\') AS suspended,
                SUM(created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS new_this_week
             FROM users',
        ) ?? [];

        $jobs = $this->database->selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(status IN (\'PENDING\', \'QUEUED\')) AS queued,
                SUM(status = \'PROCESSING\') AS processing,
                SUM(status = \'FAILED\') AS failed,
                COALESCE(SUM(processed_items), 0) AS checks
             FROM checker_jobs',
        ) ?? [];

        $credits = $this->database->selectOne(
            'SELECT
                COALESCE(SUM(balance), 0) AS outstanding,
                COALESCE(SUM(reserved), 0) AS reserved
             FROM wallets',
        ) ?? [];

        $support = $this->database->selectOne(
            'SELECT
                SUM(status = \'OPEN\') AS open_tickets,
                SUM(status = \'PENDING\') AS pending_tickets
             FROM support_tickets',
        ) ?? [];

        return [
            'users' => [
                'total' => (int) ($users['total'] ?? 0),
                'active' => (int) ($users['active'] ?? 0),
                'suspended' => (int) ($users['suspended'] ?? 0),
                'new_this_week' => (int) ($users['new_this_week'] ?? 0),
            ],
            'jobs' => [
                'total' => (int) ($jobs['total'] ?? 0),
                'queued' => (int) ($jobs['queued'] ?? 0),
                'processing' => (int) ($jobs['processing'] ?? 0),
                'failed' => (int) ($jobs['failed'] ?? 0),
                'checks' => (int) ($jobs['checks'] ?? 0),
            ],
            'credits' => [
                'outstanding' => (int) ($credits['outstanding'] ?? 0),
                'reserved' => (int) ($credits['reserved'] ?? 0),
            ],
            'support' => [
                'open' => (int) ($support['open_tickets'] ?? 0),
                'pending' => (int) ($support['pending_tickets'] ?? 0),
            ],
        ];
    }

    /**
     * Queue depth and worker liveness for the admin health panel.
     *
     * @return array<string, mixed>
     */
    public function systemHealth(int $staleAfterSeconds): array
    {
        $heartbeat = $this->database->selectOne(
            'SELECT worker_id, hostname, jobs_processed, items_processed, started_at, last_seen_at
             FROM worker_heartbeats
             ORDER BY last_seen_at DESC
             LIMIT 1',
        );

        $secondsSince = null;
        if ($heartbeat !== null) {
            $lastSeen = strtotime((string) $heartbeat['last_seen_at'] . ' UTC');
            $secondsSince = $lastSeen === false ? null : max(0, time() - $lastSeen);
        }

        $backlog = (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM checker_job_items WHERE status IN ('PENDING', 'PROCESSING')",
        ) ?? 0);

        $recentErrors = (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM checker_results
             WHERE status = 'ERROR' AND checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
        ) ?? 0);

        $stalledJobs = (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM checker_jobs
             WHERE status = 'PROCESSING'
               AND locked_at IS NOT NULL
               AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :seconds SECOND)",
            ['seconds' => $staleAfterSeconds],
        ) ?? 0);

        return [
            'worker' => [
                'status' => ($secondsSince !== null && $secondsSince <= $staleAfterSeconds) ? 'ok' : 'stale',
                'worker_id' => $heartbeat['worker_id'] ?? null,
                'hostname' => $heartbeat['hostname'] ?? null,
                'seconds_since_heartbeat' => $secondsSince,
                'jobs_processed' => (int) ($heartbeat['jobs_processed'] ?? 0),
                'items_processed' => (int) ($heartbeat['items_processed'] ?? 0),
            ],
            'queue' => [
                'pending_items' => $backlog,
                'stalled_jobs' => $stalledJobs,
            ],
            'errors_last_hour' => $recentErrors,
        ];
    }

    /**
     * Per-checker usage over a window, for the admin checker table.
     *
     * @return list<array<string, mixed>>
     */
    public function checkerUsage(int $days = 30): array
    {
        return $this->database->select(
            'SELECT c.id, c.slug, c.label, c.is_enabled, c.credit_cost,
                    COUNT(r.id) AS checks,
                    SUM(r.status = \'VALID\') AS valid,
                    SUM(r.status = \'UNAVAILABLE\') AS unavailable,
                    SUM(r.status = \'ERROR\') AS errors
             FROM checker_types c
             LEFT JOIN checker_results r
                 ON r.checker_type_id = c.id
                AND r.checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)
             GROUP BY c.id, c.slug, c.label, c.is_enabled, c.credit_cost
             ORDER BY c.sort_order, c.id',
            ['days' => max(1, min(365, $days))],
        );
    }
}
