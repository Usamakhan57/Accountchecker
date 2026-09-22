<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

/**
 * Generated export files.
 *
 * The row is the only handle a client ever gets: downloads are addressed by
 * uuid and resolved to a path here, so no request value reaches the filesystem.
 * Files expire, and the worker prunes them on its idle passes.
 */
final class ExportRepository extends Repository
{
    public function create(
        int $userId,
        string $uuid,
        ?int $jobId,
        string $format,
        string $filename,
        int $rowCount,
        int $sizeBytes,
        int $retentionHours,
    ): int {
        return $this->database->insert('exports', [
            'uuid' => $uuid,
            'user_id' => $userId,
            'job_id' => $jobId,
            'format' => $format,
            'filename' => mb_substr($filename, 0, 160),
            'row_count' => $rowCount,
            'size_bytes' => $sizeBytes,
            'status' => 'READY',
            'expires_at' => $this->timestampIn(max(1, $retentionHours) * 3600),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $userId, string $uuid): ?array
    {
        return $this->database->selectOne(
            'SELECT id, uuid, user_id, job_id, format, filename, row_count, size_bytes,
                    status, expires_at, created_at
             FROM exports
             WHERE uuid = :uuid AND user_id = :user_id',
            ['uuid' => $uuid, 'user_id' => $userId],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginateForUser(int $userId, Paginator $paginator): array
    {
        $total = (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM exports WHERE user_id = :user_id AND status <> 'DELETED'",
            ['user_id' => $userId],
        ) ?? 0);

        $items = $this->database->select(
            "SELECT id, uuid, job_id, format, filename, row_count, size_bytes,
                    status, expires_at, created_at
             FROM exports
             WHERE user_id = :user_id AND status <> 'DELETED'
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset",
            ['user_id' => $userId, 'limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function markStatus(int $exportId, string $status): void
    {
        $this->database->update('exports', ['status' => $status], ['id' => $exportId]);
    }

    /**
     * Rows whose files are past their expiry and still marked READY.
     *
     * @return list<array<string, mixed>>
     */
    public function expired(int $limit = 200): array
    {
        return $this->database->select(
            "SELECT id, uuid, format FROM exports
             WHERE status = 'READY' AND expires_at < UTC_TIMESTAMP()
             ORDER BY id
             LIMIT :limit",
            ['limit' => max(1, min(1000, $limit))],
        );
    }

    /** How many exports the user made recently, for the per-user rate cap. */
    public function countSince(int $userId, int $seconds): int
    {
        return (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM exports
             WHERE user_id = :user_id AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :seconds SECOND)',
            ['user_id' => $userId, 'seconds' => max(1, $seconds)],
        ) ?? 0);
    }
}
