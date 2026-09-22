<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

/**
 * Support tickets and their messages.
 *
 * Reads are scoped by user id in the query itself wherever a user is asking, so
 * a ticket belonging to somebody else cannot be reached by guessing a uuid: the
 * row simply is not returned, and the caller turns that into a 404.
 */
final class SupportRepository extends Repository
{
    /** @var list<string> */
    public const STATUSES = ['OPEN', 'PENDING', 'RESOLVED', 'CLOSED'];

    /** @var list<string> */
    public const PRIORITIES = ['LOW', 'NORMAL', 'HIGH'];

    /** States in which a user may still add to the conversation. */
    public const REPLYABLE = ['OPEN', 'PENDING', 'RESOLVED'];

    public function create(int $userId, string $uuid, string $subject, string $priority): int
    {
        return $this->database->insert('support_tickets', [
            'uuid' => $uuid,
            'user_id' => $userId,
            'subject' => $subject,
            'priority' => in_array($priority, self::PRIORITIES, true) ? $priority : 'NORMAL',
            'status' => 'OPEN',
            'last_reply_at' => $this->now(),
        ]);
    }

    public function addMessage(int $ticketId, ?int $userId, bool $isStaff, string $body): int
    {
        $id = $this->database->insert('support_messages', [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'is_staff' => $isStaff ? 1 : 0,
            'body' => $body,
        ]);

        // A reply moves the ticket: a user's reply puts it back in front of
        // support, a staff reply puts it back in front of the user. Both touch
        // last_reply_at so the queue can be ordered by who is waiting longest.
        $this->database->run(
            'UPDATE support_tickets
             SET status = CASE
                     WHEN status = :closed THEN status
                     WHEN :is_staff = 1 THEN :pending
                     ELSE :open
                 END,
                 last_reply_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'closed' => 'CLOSED',
                'is_staff' => $isStaff ? 1 : 0,
                'pending' => 'PENDING',
                'open' => 'OPEN',
                'id' => $ticketId,
            ],
        );

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, string $uuid): ?array
    {
        return $this->database->selectOne(
            $this->selectSql() . ' WHERE t.uuid = :uuid AND t.user_id = :user_id',
            ['uuid' => $uuid, 'user_id' => $userId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->database->selectOne(
            $this->selectSql() . ' WHERE t.uuid = :uuid',
            ['uuid' => $uuid],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginateForUser(int $userId, Paginator $paginator, ?string $status = null): array
    {
        $conditions = ['t.user_id = :user_id'];
        $bindings = ['user_id' => $userId];

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $conditions[] = 't.status = :status';
            $bindings['status'] = $status;
        }

        return $this->paginateWhere(implode(' AND ', $conditions), $bindings, $paginator);
    }

    /**
     * The support queue.
     *
     * Ordered by who has been waiting longest for a reply rather than by id, so
     * the oldest unanswered ticket is the first one an agent sees.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginateAll(Paginator $paginator, ?string $status = null): array
    {
        $conditions = ['1 = 1'];
        $bindings = [];

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $conditions[] = 't.status = :status';
            $bindings['status'] = $status;
        }

        return $this->paginateWhere(
            implode(' AND ', $conditions),
            $bindings,
            $paginator,
            "FIELD(t.status, 'OPEN', 'PENDING', 'RESOLVED', 'CLOSED'), t.last_reply_at ASC",
        );
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function paginateWhere(
        string $where,
        array $bindings,
        Paginator $paginator,
        string $order = 't.updated_at DESC, t.id DESC',
    ): array {
        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM support_tickets t WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            $this->selectSql() . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    public function messages(int $ticketId): array
    {
        return $this->database->select(
            'SELECT m.id, m.ticket_id, m.user_id, m.is_staff, m.body, m.created_at,
                    u.name AS author_name
             FROM support_messages m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.ticket_id = :ticket_id
             ORDER BY m.id',
            ['ticket_id' => $ticketId],
        );
    }

    public function setStatus(int $ticketId, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }

        return $this->database->update('support_tickets', ['status' => $status], ['id' => $ticketId]) > 0;
    }

    /** How many tickets a user already has open, to bound ticket spam. */
    public function openCountForUser(int $userId): int
    {
        return (int) ($this->database->scalar(
            "SELECT COUNT(*) FROM support_tickets
             WHERE user_id = :user_id AND status IN ('OPEN', 'PENDING')",
            ['user_id' => $userId],
        ) ?? 0);
    }

    public function messageCount(int $ticketId): int
    {
        return (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM support_messages WHERE ticket_id = :ticket_id',
            ['ticket_id' => $ticketId],
        ) ?? 0);
    }

    private function selectSql(): string
    {
        return 'SELECT t.id, t.uuid, t.user_id, t.subject, t.status, t.priority,
                       t.last_reply_at, t.created_at, t.updated_at,
                       u.name AS user_name, u.email AS user_email, u.uuid AS user_uuid,
                       (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS message_count
                FROM support_tickets t
                INNER JOIN users u ON u.id = t.user_id';
    }
}
