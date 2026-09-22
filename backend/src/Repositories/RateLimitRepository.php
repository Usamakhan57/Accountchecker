<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

/**
 * Fixed-window counters backing every rate limit in the application.
 *
 * A single upsert both starts a new window and increments an existing one, so
 * two concurrent requests cannot each believe they opened the window.
 */
final class RateLimitRepository extends Repository
{
    /**
     * Records one attempt and returns the state of the window.
     *
     * @return array{attempts: int, expires_at: string}
     */
    public function hit(string $key, int $windowSeconds): array
    {
        $expiresAt = $this->timestampIn($windowSeconds);

        // The window resets in the same statement it is incremented in: if the
        // stored expiry has passed, attempts restart at 1 and the window moves.
        $this->database->run(
            'INSERT INTO rate_limits (bucket_key, attempts, window_start, expires_at)
             VALUES (:bucket_key, 1, UTC_TIMESTAMP(), :expires_at)
             ON DUPLICATE KEY UPDATE
                attempts = IF(expires_at <= UTC_TIMESTAMP(), 1, attempts + 1),
                window_start = IF(expires_at <= UTC_TIMESTAMP(), UTC_TIMESTAMP(), window_start),
                expires_at = IF(expires_at <= UTC_TIMESTAMP(), VALUES(expires_at), expires_at)',
            ['bucket_key' => $key, 'expires_at' => $expiresAt],
        );

        $row = $this->database->selectOne(
            'SELECT attempts, expires_at FROM rate_limits WHERE bucket_key = :bucket_key',
            ['bucket_key' => $key],
        );

        return [
            'attempts' => (int) ($row['attempts'] ?? 1),
            'expires_at' => (string) ($row['expires_at'] ?? $expiresAt),
        ];
    }

    /**
     * Reads the current window without counting against it.
     *
     * @return array{attempts: int, expires_at: string}|null
     */
    public function peek(string $key): ?array
    {
        $row = $this->database->selectOne(
            'SELECT attempts, expires_at FROM rate_limits
             WHERE bucket_key = :bucket_key AND expires_at > UTC_TIMESTAMP()',
            ['bucket_key' => $key],
        );

        return $row === null
            ? null
            : ['attempts' => (int) $row['attempts'], 'expires_at' => (string) $row['expires_at']];
    }

    /** Clears a bucket — used after a successful sign-in. */
    public function clear(string $key): void
    {
        $this->database->run('DELETE FROM rate_limits WHERE bucket_key = :bucket_key', ['bucket_key' => $key]);
    }

    public function purgeExpired(): int
    {
        return $this->database->run(
            'DELETE FROM rate_limits WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)',
        )->rowCount();
    }
}
