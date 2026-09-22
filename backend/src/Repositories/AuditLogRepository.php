<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Logger;
use AccountCheck\Support\Paginator;

/**
 * Security-relevant events: sign-ins, role and status changes, wallet
 * adjustments, checker configuration changes.
 *
 * Context is redacted before it is written, so a caller that passes a password
 * or token by mistake does not persist it.
 */
final class AuditLogRepository extends Repository
{
    /** @param array<string, mixed> $context */
    public function record(
        string $event,
        ?int $actorId = null,
        string $actorRole = '',
        ?string $targetType = null,
        ?int $targetId = null,
        array $context = [],
        string $severity = 'INFO',
        ?string $ip = null,
        string $userAgent = '',
    ): void {
        $this->database->insert('audit_logs', [
            'actor_id' => $actorId,
            'actor_role' => substr($actorRole, 0, 32),
            'event' => substr($event, 0, 64),
            'target_type' => $targetType !== null ? substr($targetType, 0, 48) : null,
            'target_id' => $targetId,
            'severity' => in_array($severity, ['INFO', 'NOTICE', 'WARNING', 'CRITICAL'], true) ? $severity : 'INFO',
            'context' => $context === [] ? null : json_encode(Logger::redact($context)),
            'ip_address' => $this->packIp($ip),
            'user_agent' => substr($userAgent, 0, 255),
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginate(Paginator $paginator, ?string $event, ?string $severity, ?int $actorId): array
    {
        $conditions = ['1 = 1'];
        $bindings = [];

        if ($event !== null && $event !== '') {
            $conditions[] = 'a.event = :event';
            $bindings['event'] = $event;
        }

        if ($severity !== null && $severity !== '') {
            $conditions[] = 'a.severity = :severity';
            $bindings['severity'] = $severity;
        }

        if ($actorId !== null) {
            $conditions[] = 'a.actor_id = :actor_id';
            $bindings['actor_id'] = $actorId;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM audit_logs a WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT a.id, a.event, a.severity, a.actor_id, a.actor_role, a.target_type, a.target_id,
                    a.context, a.ip_address, a.created_at, u.email AS actor_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE ' . $where . '
             ORDER BY a.id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        foreach ($items as $index => $row) {
            $items[$index]['ip_address'] = $this->unpackIp($row['ip_address']);
            $items[$index]['context'] = is_string($row['context']) ? json_decode($row['context'], true) : null;
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<string> */
    public function distinctEvents(): array
    {
        $rows = $this->database->select('SELECT DISTINCT event FROM audit_logs ORDER BY event LIMIT 100');

        return array_map(static fn (array $row): string => (string) $row['event'], $rows);
    }
}
