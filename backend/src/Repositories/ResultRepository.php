<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

/**
 * Check results.
 *
 * One row per job item, enforced by a unique key on job_item_id. Writes go
 * through an upsert so a record that is retried, or replayed after a worker
 * died mid-chunk, overwrites its own row instead of creating a second one.
 */
final class ResultRepository extends Repository
{
    /** @param array<string, mixed> $metadata */
    public function record(
        int $jobId,
        int $jobItemId,
        int $userId,
        int $checkerTypeId,
        string $rawInput,
        string $normalizedInput,
        string $status,
        ?string $reason,
        string $source,
        ?int $responseTimeMs = null,
        array $metadata = [],
    ): void {
        $this->database->run(
            'INSERT INTO checker_results
                 (job_id, job_item_id, user_id, checker_type_id, raw_input, normalized_input,
                  status, reason, source, response_time_ms, metadata)
             VALUES
                 (:job_id, :job_item_id, :user_id, :checker_type_id, :raw_input, :normalized_input,
                  :status, :reason, :source, :response_time_ms, :metadata)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 reason = VALUES(reason),
                 source = VALUES(source),
                 response_time_ms = VALUES(response_time_ms),
                 metadata = VALUES(metadata),
                 checked_at = UTC_TIMESTAMP()',
            [
                'job_id' => $jobId,
                'job_item_id' => $jobItemId,
                'user_id' => $userId,
                'checker_type_id' => $checkerTypeId,
                'raw_input' => mb_substr($rawInput, 0, 512),
                'normalized_input' => mb_substr($normalizedInput, 0, 512),
                'status' => $status,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'source' => mb_substr($source, 0, 64),
                'response_time_ms' => $responseTimeMs,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * Sort keys the client may ask for, mapped onto real columns.
     *
     * An allow-list rather than a passthrough: a sort column cannot be bound
     * as a parameter, so the request value is never allowed near the SQL.
     */
    private const SORTABLE = [
        'checked_at' => 'r.id',
        'input' => 'r.normalized_input',
        'status' => 'r.status',
        'response_time' => 'r.response_time_ms',
    ];

    /**
     * A job's results, paged, filtered and sorted at the database.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginateForJob(int $jobId, int $userId, Paginator $paginator, array $filters = []): array
    {
        return $this->paginateWhere(
            ['r.job_id = :job_id', 'r.user_id = :user_id'],
            ['job_id' => $jobId, 'user_id' => $userId],
            $paginator,
            $filters,
            // Within a job the natural order is the order the list was
            // submitted in, so results line up with the user's own file.
            'r.id',
            'asc',
        );
    }

    /**
     * Every result the user owns, across all their jobs.
     *
     * This is the results page: one table over the whole history, filtered by
     * checker, status, text and date. It is always paged at the database —
     * a user with a hundred thousand rows must never be asked to load them.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginateForUser(int $userId, Paginator $paginator, array $filters = []): array
    {
        return $this->paginateWhere(
            ['r.user_id = :user_id'],
            ['user_id' => $userId],
            $paginator,
            $filters,
            'r.id',
            'desc',
        );
    }

    /**
     * @param list<string>         $conditions
     * @param array<string, mixed> $bindings
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function paginateWhere(
        array $conditions,
        array $bindings,
        Paginator $paginator,
        array $filters,
        string $defaultSort,
        string $defaultDirection,
    ): array {
        [$where, $bindings] = $this->filters($filters, $conditions, $bindings);

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM checker_results r WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $sort = $this->sortColumn($filters['sort'] ?? null, self::SORTABLE, $defaultSort);
        $direction = $this->sortDirection($filters['direction'] ?? $defaultDirection);

        // A stable tie-break, so paging through equal values cannot show the
        // same row twice or skip one.
        $orderBy = $sort === 'r.id' ? 'r.id ' . $direction : $sort . ' ' . $direction . ', r.id ' . $direction;

        $items = $this->database->select(
            'SELECT r.id, r.job_id, r.raw_input, r.normalized_input, r.status, r.reason,
                    r.source, r.response_time_ms, r.metadata, r.checked_at,
                    c.slug AS checker_slug, c.label AS checker_label
             FROM checker_results r
             INNER JOIN checker_types c ON c.id = r.checker_type_id
             WHERE ' . $where . '
             ORDER BY ' . $orderBy . '
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Counts per status across everything the filters match.
     *
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function statusBreakdownForUser(int $userId, array $filters = []): array
    {
        [$where, $bindings] = $this->filters($filters, ['r.user_id = :user_id'], ['user_id' => $userId]);

        $rows = $this->database->select(
            'SELECT r.status, COUNT(*) AS total
             FROM checker_results r
             WHERE ' . $where . '
             GROUP BY r.status',
            $bindings,
        );

        return $this->breakdown($rows);
    }

    /**
     * How many results a job has in each status.
     *
     * @return array<string, int>
     */
    public function statusBreakdownForJob(int $jobId, int $userId): array
    {
        $rows = $this->database->select(
            'SELECT status, COUNT(*) AS total
             FROM checker_results
             WHERE job_id = :job_id AND user_id = :user_id
             GROUP BY status',
            ['job_id' => $jobId, 'user_id' => $userId],
        );

        return $this->breakdown($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function breakdown(array $rows): array
    {
        $breakdown = ['VALID' => 0, 'INVALID' => 0, 'UNKNOWN' => 0, 'ERROR' => 0, 'UNAVAILABLE' => 0];

        foreach ($rows as $row) {
            $breakdown[(string) $row['status']] = (int) $row['total'];
        }

        return $breakdown;
    }

    /**
     * Streams matching results in id order for an export.
     *
     * A generator, not an array: an export must never hold the whole result
     * set in memory at once, however many rows it covers.
     *
     * @param array<string, mixed> $filters
     * @return iterable<array<string, mixed>>
     */
    public function stream(int $userId, array $filters = [], int $chunkSize = 500): iterable
    {
        $lastId = 0;
        $chunkSize = max(50, min(1000, $chunkSize));

        [$where, $baseBindings] = $this->filters($filters, ['r.user_id = :user_id'], ['user_id' => $userId]);

        while (true) {
            // Keyset paging, not OFFSET: the cost of each chunk stays the same
            // whether it is the first or the ten-thousandth row.
            $rows = $this->database->select(
                'SELECT r.id, r.raw_input, r.normalized_input, r.status, r.reason, r.source,
                        r.response_time_ms, r.checked_at, c.slug AS checker_slug, c.label AS checker_label
                 FROM checker_results r
                 INNER JOIN checker_types c ON c.id = r.checker_type_id
                 WHERE ' . $where . ' AND r.id > :last_id
                 ORDER BY r.id ASC
                 LIMIT :limit',
                $baseBindings + ['last_id' => $lastId, 'limit' => $chunkSize],
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield $row;
            }
        }
    }

    /**
     * How many rows an export would contain.
     *
     * @param array<string, mixed> $filters
     */
    public function countForUser(int $userId, array $filters = []): int
    {
        [$where, $bindings] = $this->filters($filters, ['r.user_id = :user_id'], ['user_id' => $userId]);

        return (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM checker_results r WHERE ' . $where,
            $bindings,
        ) ?? 0);
    }

    /**
     * Turns request filters into a WHERE fragment with bound values.
     *
     * Every filter is either matched against a fixed allow-list or bound as a
     * parameter; nothing from the request is ever concatenated into the SQL.
     *
     * @param array<string, mixed> $filters
     * @param list<string>         $conditions
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(array $filters, array $conditions, array $bindings): array
    {
        $status = isset($filters['status']) ? strtoupper(trim((string) $filters['status'])) : '';

        if (in_array($status, ['VALID', 'INVALID', 'UNKNOWN', 'ERROR', 'UNAVAILABLE'], true)) {
            $conditions[] = 'r.status = :status';
            $bindings['status'] = $status;
        }

        $checker = isset($filters['checker']) ? strtolower(trim((string) $filters['checker'])) : '';

        if ($checker !== '') {
            // Resolved through a subquery rather than the join, so the filter
            // works on the COUNT query too and can still use
            // idx_results_user_type.
            $conditions[] = 'r.checker_type_id = (SELECT id FROM checker_types WHERE slug = :checker)';
            $bindings['checker'] = $checker;
        }

        if (isset($filters['job_id']) && is_numeric($filters['job_id'])) {
            $conditions[] = 'r.job_id = :filter_job_id';
            $bindings['filter_job_id'] = (int) $filters['job_id'];
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            // Anchored, so the query uses idx_results_input rather than
            // scanning every row the user owns. The wildcard characters are
            // escaped so a search for "a_b" means "a_b" and not "a<any>b".
            $conditions[] = "r.normalized_input LIKE :search ESCAPE '!'";
            $bindings['search'] = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($search, 0, 120)) . '%';
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $date = isset($filters[$key]) ? trim((string) $filters[$key]) : '';

            if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $conditions[] = 'r.checked_at ' . $operator . ' :date_' . $key;
            // "to" is inclusive of the whole day the user picked.
            $bindings['date_' . $key] = $key === 'from' ? $date . ' 00:00:00' : $date . ' 23:59:59';
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
