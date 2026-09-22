<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

final class SessionRepository extends Repository
{
    /**
     * @param string $tokenHash SHA-256 of the cookie value. The raw token is
     *                          never stored, so a database leak yields no
     *                          usable sessions.
     */
    public function create(int $userId, string $tokenHash, int $lifetimeSeconds, ?string $ip, string $userAgent): int
    {
        return $this->database->insert('user_sessions', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ip_address' => $this->packIp($ip),
            'user_agent' => substr($userAgent, 0, 255),
            'expires_at' => $this->timestampIn($lifetimeSeconds),
        ]);
    }

    /**
     * Looks up a live session together with its user and role.
     *
     * One query rather than two: this runs on every authenticated request.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(string $tokenHash): ?array
    {
        return $this->database->selectOne(
            'SELECT s.id AS session_id, s.user_id, s.created_at AS session_created_at,
                    s.last_active_at, s.expires_at,
                    u.id, u.uuid, u.name, u.email, u.status, u.email_verified_at,
                    r.slug AS role_slug
             FROM user_sessions s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN roles r ON r.id = u.role_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > UTC_TIMESTAMP()',
            ['token_hash' => $tokenHash],
        );
    }

    /**
     * Refreshes last_active_at, and extends the absolute expiry with it.
     *
     * Called at most once per throttle window (see SessionService) so an active
     * user does not cause a write on every request.
     */
    public function touch(int $sessionId, int $lifetimeSeconds): void
    {
        $this->database->run(
            'UPDATE user_sessions
             SET last_active_at = UTC_TIMESTAMP(), expires_at = :expires_at
             WHERE id = :id AND revoked_at IS NULL',
            ['expires_at' => $this->timestampIn($lifetimeSeconds), 'id' => $sessionId],
        );
    }

    public function revoke(int $sessionId): void
    {
        $this->database->run(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND revoked_at IS NULL',
            ['id' => $sessionId],
        );
    }

    /**
     * Revokes every session for a user.
     *
     * Used on password change and on suspension, so a stolen or shared session
     * stops working the moment either happens.
     */
    public function revokeAllForUser(int $userId, ?int $exceptSessionId = null): int
    {
        $sql = 'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                WHERE user_id = :user_id AND revoked_at IS NULL';
        $bindings = ['user_id' => $userId];

        if ($exceptSessionId !== null) {
            $sql .= ' AND id <> :except';
            $bindings['except'] = $exceptSessionId;
        }

        return $this->database->run($sql, $bindings)->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT id, ip_address, user_agent, created_at, last_active_at, expires_at
             FROM user_sessions
             WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY last_active_at DESC
             LIMIT 50',
            ['user_id' => $userId],
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['ip_address'] = $this->unpackIp($row['ip_address']);
        }

        return $rows;
    }

    /** Removes rows that are long past expiry. Run from the worker's maintenance pass. */
    public function purgeExpired(int $graceDays = 30): int
    {
        return $this->database->run(
            'DELETE FROM user_sessions
             WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)',
            ['days' => $graceDays],
        )->rowCount();
    }
}
