<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

final class NotificationRepository extends Repository
{
    /**
     * @param string|null $link An in-app path such as "/jobs/12". External
     *                          URLs are rejected so a notification can never
     *                          send a user off-site.
     */
    public function create(int $userId, string $type, string $title, string $body, ?string $link = null): int
    {
        return $this->database->insert('notifications', [
            'user_id' => $userId,
            'type' => substr($type, 0, 48),
            'title' => substr($title, 0, 160),
            'body' => substr($body, 0, 500),
            'link' => $this->sanitizeLink($link),
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginateForUser(int $userId, Paginator $paginator, bool $unreadOnly = false): array
    {
        $where = 'user_id = :user_id';
        $bindings = ['user_id' => $userId];

        if ($unreadOnly) {
            $where .= ' AND read_at IS NULL';
        }

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM notifications WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT id, type, title, body, link, read_at, created_at
             FROM notifications
             WHERE ' . $where . '
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function unreadCount(int $userId): int
    {
        return (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL',
            ['user_id' => $userId],
        ) ?? 0);
    }

    /** The user id scopes the update, so one account cannot mark another's. */
    public function markRead(int $userId, int $notificationId): bool
    {
        return $this->database->run(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND read_at IS NULL',
            ['id' => $notificationId, 'user_id' => $userId],
        )->rowCount() === 1;
    }

    public function markAllRead(int $userId): int
    {
        return $this->database->run(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND read_at IS NULL',
            ['user_id' => $userId],
        )->rowCount();
    }

    public function delete(int $userId, int $notificationId): bool
    {
        return $this->database->run(
            'DELETE FROM notifications WHERE id = :id AND user_id = :user_id',
            ['id' => $notificationId, 'user_id' => $userId],
        )->rowCount() === 1;
    }

    private function sanitizeLink(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }

        // Must be a site-relative path: one leading slash, no scheme, and no
        // protocol-relative "//host" form.
        if (!str_starts_with($link, '/') || str_starts_with($link, '//')) {
            return null;
        }

        return substr($link, 0, 191);
    }
}
