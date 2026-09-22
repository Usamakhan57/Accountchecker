<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

/**
 * Batch job and job item storage.
 *
 * The queue is the database. There is no broker, no daemon to keep alive and
 * no message that can be lost: a job is a row, its records are rows, and a
 * worker takes work by winning a conditional UPDATE.
 *
 * Two rules hold throughout:
 *
 *   1. Claiming is atomic. Every claim is an `UPDATE ... WHERE status = <free>`
 *      that stamps the caller's worker id. Whoever the database lets through
 *      owns the row; a loser's rowCount() is zero and it simply tries again.
 *      Nothing ever reads a row and then decides to take it.
 *
 *   2. A claim is a lease, not a lock. worker_id and locked_at are written
 *      together, and anything still held after the lease TTL is assumed to
 *      belong to a process that died and is returned to the queue. A crashed
 *      worker therefore costs one TTL, not a stuck job.
 *
 * Nothing here decides whether the user may see a job. Ownership is checked in
 * the service layer, and every user-facing read takes a user_id.
 */
final class JobRepository extends Repository
{
    /** Item states that still owe work. */
    private const OPEN_ITEM_STATES = "('PENDING', 'PROCESSING')";

    /** Job states a user may still cancel. */
    public const CANCELLABLE_STATES = ['PENDING', 'QUEUED', 'PROCESSING'];

    // -- Creation -----------------------------------------------------------

    /**
     * Creates the job row itself. Items are added separately, and the job is
     * only moved to QUEUED once they are all in, so a worker can never pick up
     * a half-populated job.
     *
     * @param array<string, mixed> $options Per-job settings stored as JSON.
     */
    public function create(
        int $userId,
        int $checkerTypeId,
        string $uuid,
        int $totalItems,
        int $creditsReserved,
        int $creditCostEach,
        string $source,
        array $options = [],
    ): int {
        return $this->database->insert('checker_jobs', [
            'uuid' => $uuid,
            'user_id' => $userId,
            'checker_type_id' => $checkerTypeId,
            'status' => 'PENDING',
            'total_items' => $totalItems,
            'credits_reserved' => $creditsReserved,
            'credit_cost_each' => $creditCostEach,
            'source' => $source,
            'options' => $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Inserts job items in multi-row batches.
     *
     * A 5,000-record job becomes a handful of statements rather than 5,000
     * round trips. The caller supplies the chunk size so the parameter count
     * per statement stays well inside the driver's limit.
     *
     * @param list<array{raw: string, normalized: string}> $items
     * @return int Number of rows written.
     */
    public function insertItems(int $jobId, int $userId, array $items, int $chunkSize = 500): int
    {
        $chunkSize = max(1, min(1000, $chunkSize));
        $written = 0;
        $position = 0;

        foreach (array_chunk($items, $chunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];
            $index = 0;

            foreach ($chunk as $item) {
                $placeholders[] = sprintf(
                    '(:job_id, :user_id, :position%1$d, :raw%1$d, :normalized%1$d)',
                    $index,
                );

                $bindings['position' . $index] = $position++;
                $bindings['raw' . $index] = mb_substr($item['raw'], 0, 512);
                $bindings['normalized' . $index] = mb_substr($item['normalized'], 0, 512);
                $index++;
            }

            $written += $this->database->run(
                'INSERT INTO checker_job_items (job_id, user_id, position, raw_input, normalized_input)
                 VALUES ' . implode(', ', $placeholders),
                $bindings + ['job_id' => $jobId, 'user_id' => $userId],
            )->rowCount();
        }

        return $written;
    }

    /** Publishes a fully populated job to the queue. */
    public function markQueued(int $jobId): bool
    {
        return $this->database->run(
            "UPDATE checker_jobs
             SET status = 'QUEUED', queued_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'PENDING'",
            ['id' => $jobId],
        )->rowCount() === 1;
    }

    // -- Reads --------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function find(int $jobId): ?array
    {
        return $this->database->selectOne($this->selectJobSql() . ' WHERE j.id = :id', ['id' => $jobId]);
    }

    /** Looks a job up by id or by uuid, scoped to its owner. */
    public function findForUser(int $userId, string $reference): ?array
    {
        if (ctype_digit($reference)) {
            return $this->database->selectOne(
                $this->selectJobSql() . ' WHERE j.id = :id AND j.user_id = :user_id',
                ['id' => (int) $reference, 'user_id' => $userId],
            );
        }

        return $this->database->selectOne(
            $this->selectJobSql() . ' WHERE j.uuid = :uuid AND j.user_id = :user_id',
            ['uuid' => $reference, 'user_id' => $userId],
        );
    }

    /**
     * The jobs list, paged at the database.
     *
     * @param array{status?: string, checker?: string, search?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginateForUser(int $userId, Paginator $paginator, array $filters = []): array
    {
        [$where, $bindings] = $this->jobFilters($filters, ['j.user_id = :user_id'], ['user_id' => $userId]);

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM checker_jobs j
             INNER JOIN checker_types c ON c.id = j.checker_type_id
             WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            $this->selectJobSql() . ' WHERE ' . $where . ' ORDER BY j.id DESC LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The narrow row the progress poller reads.
     *
     * The workspace polls this every couple of seconds while a job runs, so it
     * touches one indexed row and returns counters only.
     *
     * @return array<string, mixed>|null
     */
    public function progressForUser(int $userId, string $reference): ?array
    {
        $column = ctype_digit($reference) ? 'id' : 'uuid';
        $value = ctype_digit($reference) ? (int) $reference : $reference;

        return $this->database->selectOne(
            'SELECT id, uuid, status, total_items, processed_items, successful_items,
                    failed_items, credits_reserved, credits_spent, error_message,
                    started_at, completed_at
             FROM checker_jobs
             WHERE ' . $column . ' = :value AND user_id = :user_id',
            ['value' => $value, 'user_id' => $userId],
        );
    }

    /** Open jobs for a user, used to cap how many run at once. */
    public function activeJobCount(int $userId): int
    {
        return (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM checker_jobs
             WHERE user_id = :user_id AND status IN ('PENDING', 'QUEUED', 'PROCESSING')",
            ['user_id' => $userId],
        ) ?? 0);
    }

    // -- Worker: claiming ---------------------------------------------------

    /**
     * Takes ownership of the oldest queued job, if there is one.
     *
     * The UPDATE is the claim. Because the WHERE clause names the state being
     * left, exactly one worker can move a given row out of QUEUED; the read
     * that follows only fetches what this worker already owns.
     *
     * @return array<string, mixed>|null
     */
    public function claimNextJob(string $workerId): ?array
    {
        $claimed = $this->database->run(
            "UPDATE checker_jobs
             SET status = 'PROCESSING',
                 worker_id = :worker_id,
                 locked_at = UTC_TIMESTAMP(),
                 started_at = COALESCE(started_at, UTC_TIMESTAMP())
             WHERE status = 'QUEUED'
             ORDER BY id
             LIMIT 1",
            ['worker_id' => $workerId],
        )->rowCount();

        if ($claimed === 0) {
            return null;
        }

        // A worker runs one job at a time and clears worker_id when it lets go,
        // so this matches the row just claimed.
        return $this->database->selectOne(
            $this->selectJobSql() . " WHERE j.worker_id = :worker_id AND j.status = 'PROCESSING' ORDER BY j.id LIMIT 1",
            ['worker_id' => $workerId],
        );
    }

    /**
     * Claims up to $limit records of a job.
     *
     * attempts is incremented by the claim itself, which is what makes the
     * retry loop terminate: an item that keeps failing eventually stops being
     * claimable and is closed out as an error.
     *
     * @return list<array<string, mixed>>
     */
    public function claimItems(int $jobId, string $workerId, int $limit, int $maxAttempts): array
    {
        $claimed = $this->database->run(
            "UPDATE checker_job_items
             SET status = 'PROCESSING',
                 worker_id = :worker_id,
                 locked_at = UTC_TIMESTAMP(),
                 attempts = attempts + 1
             WHERE job_id = :job_id AND status = 'PENDING' AND attempts < :max_attempts
             ORDER BY id
             LIMIT :limit",
            [
                'worker_id' => $workerId,
                'job_id' => $jobId,
                'max_attempts' => $maxAttempts,
                'limit' => max(1, $limit),
            ],
        )->rowCount();

        if ($claimed === 0) {
            return [];
        }

        return $this->database->select(
            "SELECT id, job_id, user_id, position, raw_input, normalized_input, attempts
             FROM checker_job_items
             WHERE job_id = :job_id AND status = 'PROCESSING' AND worker_id = :worker_id
             ORDER BY id",
            ['job_id' => $jobId, 'worker_id' => $workerId],
        );
    }

    /** True while this worker still holds the job and nobody has cancelled it. */
    public function stillOwns(int $jobId, string $workerId): bool
    {
        return (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM checker_jobs
             WHERE id = :id AND worker_id = :worker_id AND status = 'PROCESSING'",
            ['id' => $jobId, 'worker_id' => $workerId],
        ) ?? 0) === 1;
    }

    /** Refreshes the lease so a long job is not mistaken for an abandoned one. */
    public function touchJobLease(int $jobId, string $workerId): void
    {
        $this->database->run(
            'UPDATE checker_jobs SET locked_at = UTC_TIMESTAMP() WHERE id = :id AND worker_id = :worker_id',
            ['id' => $jobId, 'worker_id' => $workerId],
        );
    }

    // -- Worker: item outcomes ----------------------------------------------

    /**
     * Closes one record, but only if it is still this worker's to close.
     *
     * The "still open" guard is what makes cancellation exact. A cancel marks
     * every open record SKIPPED before it finalises the job, so a record this
     * worker finished a moment too late fails the guard, is not counted and is
     * not charged. The caller uses the return value to decide whether the
     * result and the progress tally belong to this job at all.
     */
    public function completeItem(int $itemId, bool $failed): bool
    {
        return $this->database->run(
            "UPDATE checker_job_items
             SET status = :status, processed_at = UTC_TIMESTAMP(), worker_id = NULL, locked_at = NULL
             WHERE id = :id AND status IN " . self::OPEN_ITEM_STATES,
            ['status' => $failed ? 'FAILED' : 'DONE', 'id' => $itemId],
        )->rowCount() === 1;
    }

    /** Returns an item to the queue after a retryable failure. */
    public function releaseItemForRetry(int $itemId): void
    {
        $this->database->run(
            "UPDATE checker_job_items
             SET status = 'PENDING', worker_id = NULL, locked_at = NULL
             WHERE id = :id",
            ['id' => $itemId],
        );
    }

    /**
     * Adds a record's tally to the job counters in one statement.
     *
     * Counters are incremented rather than recomputed, so nothing is lost to a
     * read-then-write. The `status = 'PROCESSING'` guard stops a finished job's
     * numbers from moving after the fact, which is what keeps the credits a job
     * reports as spent identical to the credits actually charged.
     */
    public function addProgress(int $jobId, int $processed, int $successful, int $failed, int $creditsSpent): bool
    {
        return $this->database->run(
            "UPDATE checker_jobs
             SET processed_items = LEAST(total_items, processed_items + :processed),
                 successful_items = successful_items + :successful,
                 failed_items = failed_items + :failed,
                 credits_spent = credits_spent + :credits,
                 locked_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'PROCESSING'",
            [
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'credits' => $creditsSpent,
                'id' => $jobId,
            ],
        )->rowCount() === 1;
    }

    /** Records how many records are still owed work. */
    public function countOpenItems(int $jobId, ?int $maxAttempts = null): int
    {
        $sql = 'SELECT COUNT(*) FROM checker_job_items WHERE job_id = :job_id AND status IN '
            . self::OPEN_ITEM_STATES;
        $bindings = ['job_id' => $jobId];

        if ($maxAttempts !== null) {
            $sql .= ' AND attempts < :max_attempts';
            $bindings['max_attempts'] = $maxAttempts;
        }

        return (int) ($this->database->scalar($sql, $bindings) ?? 0);
    }

    /**
     * Closes out records that ran out of attempts.
     *
     * @return list<array<string, mixed>> The rows that were closed, so the
     *                                    caller can write an ERROR result for each.
     */
    public function exhaustedItems(int $jobId, int $maxAttempts): array
    {
        return $this->database->select(
            'SELECT id, job_id, user_id, raw_input, normalized_input, attempts
             FROM checker_job_items
             WHERE job_id = :job_id AND status IN ' . self::OPEN_ITEM_STATES . '
               AND attempts >= :max_attempts
             ORDER BY id',
            ['job_id' => $jobId, 'max_attempts' => $maxAttempts],
        );
    }

    /** Marks everything still open on a job as skipped (cancellation). */
    public function skipOpenItems(int $jobId): int
    {
        return $this->database->run(
            "UPDATE checker_job_items
             SET status = 'SKIPPED', worker_id = NULL, locked_at = NULL
             WHERE job_id = :job_id AND status IN " . self::OPEN_ITEM_STATES,
            ['job_id' => $jobId],
        )->rowCount();
    }

    // -- Terminal transitions -----------------------------------------------

    /**
     * Moves a job to a terminal state, once.
     *
     * Settlement of reserved credits hangs off this: the WHERE clause excludes
     * the terminal states, so whichever caller gets rowCount() === 1 is the
     * single owner of the transition and is the one that settles the wallet.
     * A cancelling user and a finishing worker racing each other therefore
     * cannot both refund or both charge.
     */
    public function finalize(int $jobId, string $status, ?string $errorMessage = null): bool
    {
        return $this->database->run(
            "UPDATE checker_jobs
             SET status = :status,
                 error_message = :error_message,
                 completed_at = UTC_TIMESTAMP(),
                 worker_id = NULL,
                 locked_at = NULL
             WHERE id = :id AND status NOT IN ('COMPLETED', 'FAILED', 'CANCELLED')",
            [
                'status' => $status,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
                'id' => $jobId,
            ],
        )->rowCount() === 1;
    }

    /** Same transition, but only for a job this worker still holds. */
    public function finalizeOwned(int $jobId, string $workerId, string $status, ?string $errorMessage = null): bool
    {
        return $this->database->run(
            "UPDATE checker_jobs
             SET status = :status,
                 error_message = :error_message,
                 completed_at = UTC_TIMESTAMP(),
                 worker_id = NULL,
                 locked_at = NULL
             WHERE id = :id AND worker_id = :worker_id AND status = 'PROCESSING'",
            [
                'status' => $status,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
                'id' => $jobId,
                'worker_id' => $workerId,
            ],
        )->rowCount() === 1;
    }

    /** Hands a job back to the queue (worker shutting down mid-job). */
    public function releaseJob(int $jobId, string $workerId): void
    {
        $this->database->run(
            "UPDATE checker_jobs
             SET status = 'QUEUED', worker_id = NULL, locked_at = NULL
             WHERE id = :id AND worker_id = :worker_id AND status = 'PROCESSING'",
            ['id' => $jobId, 'worker_id' => $workerId],
        );

        $this->database->run(
            "UPDATE checker_job_items
             SET status = 'PENDING', worker_id = NULL, locked_at = NULL
             WHERE job_id = :job_id AND status = 'PROCESSING' AND worker_id = :worker_id",
            ['job_id' => $jobId, 'worker_id' => $workerId],
        );
    }

    // -- Stale lease recovery -----------------------------------------------

    /**
     * Returns work whose lease expired.
     *
     * This is what makes a killed worker harmless: its rows look claimed only
     * until the TTL passes, after which the next worker's pass sweeps them back
     * into the queue. Items are swept first so a recovered job never comes back
     * with records stuck in PROCESSING.
     *
     * @return array{jobs: int, items: int}
     */
    public function recoverStale(int $lockTtlSeconds): array
    {
        $ttl = max(30, $lockTtlSeconds);

        $items = $this->database->run(
            "UPDATE checker_job_items
             SET status = 'PENDING', worker_id = NULL, locked_at = NULL
             WHERE status = 'PROCESSING'
               AND locked_at IS NOT NULL
               AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :ttl SECOND)",
            ['ttl' => $ttl],
        )->rowCount();

        $jobs = $this->database->run(
            "UPDATE checker_jobs
             SET status = 'QUEUED', worker_id = NULL, locked_at = NULL
             WHERE status = 'PROCESSING'
               AND locked_at IS NOT NULL
               AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :ttl SECOND)",
            ['ttl' => $ttl],
        )->rowCount();

        return ['jobs' => $jobs, 'items' => $items];
    }

    /** How many jobs are waiting, for the worker log and the admin panel. */
    public function queueDepth(): int
    {
        return (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM checker_jobs WHERE status = 'QUEUED'",
        ) ?? 0);
    }

    // -- Worker heartbeats --------------------------------------------------

    public function heartbeat(string $workerId, string $hostname, int $pid, int $jobsProcessed, int $itemsProcessed): void
    {
        $this->database->run(
            'INSERT INTO worker_heartbeats (worker_id, hostname, pid, jobs_processed, items_processed)
             VALUES (:worker_id, :hostname, :pid, :jobs, :items)
             ON DUPLICATE KEY UPDATE
                 hostname = VALUES(hostname),
                 pid = VALUES(pid),
                 jobs_processed = VALUES(jobs_processed),
                 items_processed = VALUES(items_processed),
                 last_seen_at = UTC_TIMESTAMP()',
            [
                'worker_id' => $workerId,
                'hostname' => mb_substr($hostname, 0, 120),
                'pid' => $pid,
                'jobs' => $jobsProcessed,
                'items' => $itemsProcessed,
            ],
        );
    }

    /** Drops heartbeat rows for workers that have been gone for a while. */
    public function pruneHeartbeats(int $olderThanSeconds = 86400): int
    {
        return $this->database->run(
            'DELETE FROM worker_heartbeats WHERE last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :ttl SECOND)',
            ['ttl' => max(300, $olderThanSeconds)],
        )->rowCount();
    }

    public function forgetHeartbeat(string $workerId): void
    {
        $this->database->run(
            'DELETE FROM worker_heartbeats WHERE worker_id = :worker_id',
            ['worker_id' => $workerId],
        );
    }

    // -- History ------------------------------------------------------------

    /** Writes the history row a finished job leaves behind. */
    public function recordHistory(array $job): void
    {
        $this->database->insert('search_history', [
            'user_id' => (int) $job['user_id'],
            'job_id' => (int) $job['id'],
            'checker_type_id' => (int) $job['checker_type_id'],
            'checker_slug' => (string) ($job['checker_slug'] ?? ''),
            'total_items' => (int) $job['total_items'],
            'successful_items' => (int) ($job['successful_items'] ?? 0),
            'failed_items' => (int) ($job['failed_items'] ?? 0),
            'status' => (string) $job['status'],
            'credits_spent' => (int) ($job['credits_spent'] ?? 0),
        ]);
    }

    // -- Internals ----------------------------------------------------------

    private function selectJobSql(): string
    {
        // worker_id and locked_at are deliberately absent: they are queue
        // internals and never leave the server.
        return 'SELECT j.id, j.uuid, j.user_id, j.checker_type_id, j.status, j.total_items,
                       j.processed_items, j.successful_items, j.failed_items,
                       j.credits_reserved, j.credits_spent, j.credit_cost_each,
                       j.options, j.source, j.error_message,
                       j.queued_at, j.started_at, j.completed_at, j.created_at,
                       c.slug AS checker_slug, c.label AS checker_label
                FROM checker_jobs j
                INNER JOIN checker_types c ON c.id = j.checker_type_id';
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $conditions
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function jobFilters(array $filters, array $conditions, array $bindings): array
    {
        $status = isset($filters['status']) ? strtoupper(trim((string) $filters['status'])) : '';

        if ($status !== '' && in_array($status, ['PENDING', 'QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED'], true)) {
            $conditions[] = 'j.status = :status';
            $bindings['status'] = $status;
        }

        $checker = isset($filters['checker']) ? strtolower(trim((string) $filters['checker'])) : '';

        if ($checker !== '') {
            $conditions[] = 'c.slug = :checker';
            $bindings['checker'] = $checker;
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
