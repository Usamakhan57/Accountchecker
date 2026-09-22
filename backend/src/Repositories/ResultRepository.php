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
     * A job's results, paged and filtered at the database.
     *
     * @param array{status?: string, search?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginateForJob(int $jobId, int $userId, Paginator $paginator, array $filters = []): array
    {
        [$where, $bindings] = $this->filters(
            $filters,
            ['r.job_id = :job_id', 'r.user_id = :user_id'],
            ['job_id' => $jobId, 'user_id' => $userId],
        );

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM checker_results r WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT r.id, r.job_id, r.raw_input, r.normalized_input, r.status, r.reason,
                    r.source, r.response_time_ms, r.metadata, r.checked_at,
                    c.slug AS checker_slug, c.label AS checker_label
             FROM checker_results r
             INNER JOIN checker_types c ON c.id = r.checker_type_id
             WHERE ' . $where . '
             ORDER BY r.id ASC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
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

        $breakdown = ['VALID' => 0, 'INVALID' => 0, 'UNKNOWN' => 0, 'ERROR' => 0, 'UNAVAILABLE' => 0];

        foreach ($rows as $row) {
            $breakdown[(string) $row['status']] = (int) $row['total'];
        }

        return $breakdown;
    }

    /**
     * Streams a job's results in id order for an export.
     *
     * A generator, not an array: a 5,000-row export must never hold the whole
     * result set in memory at once, on the server or in the browser.
     *
     * @return iterable<array<string, mixed>>
     */
    public function streamForJob(int $jobId, int $userId, ?string $status = null, int $chunkSize = 500): iterable
    {
        $lastId = 0;
        $chunkSize = max(50, min(1000, $chunkSize));

        while (true) {
            $bindings = [
                'job_id' => $jobId,
                'user_id' => $userId,
                'last_id' => $lastId,
                'limit' => $chunkSize,
            ];

            $sql = 'SELECT id, raw_input, normalized_input, status, reason, source, checked_at
                    FROM checker_results
                    WHERE job_id = :job_id AND user_id = :user_id AND id > :last_id';

            if ($status !== null && $status !== '') {
                $sql .= ' AND status = :status';
                $bindings['status'] = strtoupper($status);
            }

            $rows = $this->database->select($sql . ' ORDER BY id ASC LIMIT :limit', $bindings);

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

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            // Anchored so the query can use idx_results_input rather than
            // scanning every row the user owns.
            $conditions[] = 'r.normalized_input LIKE :search';
            $bindings['search'] = str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($search, 0, 120)) . '%';
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
