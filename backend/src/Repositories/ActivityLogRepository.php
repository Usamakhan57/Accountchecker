<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Logger;
use AccountCheck\Support\Paginator;

/**
 * Product activity: what a user did, for their own history and for support.
 * Security events go to AuditLogRepository instead.
 */
final class ActivityLogRepository extends Repository
{
    /** @param array<string, mixed> $context */
    public function record(
        ?int $userId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = [],
        ?string $ip = null,
    ): void {
        $this->database->insert('activity_logs', [
            'user_id' => $userId,
            'action' => substr($action, 0, 64),
            'subject_type' => $subjectType !== null ? substr($subjectType, 0, 48) : null,
            'subject_id' => $subjectId,
            'context' => $context === [] ? null : json_encode(Logger::redact($context)),
            'ip_address' => $this->packIp($ip),
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginateForUser(int $userId, Paginator $paginator): array
    {
        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM activity_logs WHERE user_id = :user_id',
            ['user_id' => $userId],
        ) ?? 0);

        $items = $this->database->select(
            'SELECT id, action, subject_type, subject_id, context, created_at
             FROM activity_logs
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset',
            ['user_id' => $userId, 'limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        foreach ($items as $index => $row) {
            $items[$index]['context'] = is_string($row['context']) ? json_decode($row['context'], true) : null;
        }

        return ['items' => $items, 'total' => $total];
    }
}
