<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

/**
 * Search history: one row per finished job.
 *
 * Kept separate from checker_jobs so a user can clear their history without
 * destroying the jobs, the results or the billing record behind it. Clearing
 * history removes the user's view of what they ran, not the accounting.
 */
final class HistoryRepository extends Repository
{
    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginateForUser(int $userId, Paginator $paginator, array $filters = []): array
    {
        $conditions = ['h.user_id = :user_id'];
        $bindings = ['user_id' => $userId];

        $checker = isset($filters['checker']) ? strtolower(trim((string) $filters['checker'])) : '';

        if ($checker !== '') {
            $conditions[] = 'h.checker_slug = :checker';
            $bindings['checker'] = $checker;
        }

        $status = isset($filters['status']) ? strtoupper(trim((string) $filters['status'])) : '';

        if (in_array($status, ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
            $conditions[] = 'h.status = :status';
            $bindings['status'] = $status;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM search_history h WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT h.id, h.job_id, h.checker_slug, h.total_items, h.successful_items,
                    h.failed_items, h.status, h.credits_spent, h.created_at,
                    c.label AS checker_label
             FROM search_history h
             LEFT JOIN checker_types c ON c.id = h.checker_type_id
             WHERE ' . $where . '
             ORDER BY h.id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Removes one entry, if it belongs to this user. */
    public function delete(int $userId, int $entryId): bool
    {
        return $this->database->run(
            'DELETE FROM search_history WHERE id = :id AND user_id = :user_id',
            ['id' => $entryId, 'user_id' => $userId],
        )->rowCount() === 1;
    }

    /** Clears the user's history. Jobs, results and the ledger are untouched. */
    public function clear(int $userId): int
    {
        return $this->database->run(
            'DELETE FROM search_history WHERE user_id = :user_id',
            ['user_id' => $userId],
        )->rowCount();
    }

    /** @return array<string, int> */
    public function totalsForUser(int $userId): array
    {
        $row = $this->database->selectOne(
            'SELECT COUNT(*) AS jobs,
                    COALESCE(SUM(total_items), 0) AS records,
                    COALESCE(SUM(credits_spent), 0) AS credits
             FROM search_history
             WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        return [
            'jobs' => (int) ($row['jobs'] ?? 0),
            'records' => (int) ($row['records'] ?? 0),
            'credits' => (int) ($row['credits'] ?? 0),
        ];
    }
}
