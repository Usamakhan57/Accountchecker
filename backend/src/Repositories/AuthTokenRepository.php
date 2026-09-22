<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

/**
 * Single-use tokens for password reset and email verification.
 *
 * Only the SHA-256 of a token is stored, and a token is consumed atomically so
 * the same reset link cannot be redeemed twice.
 */
final class AuthTokenRepository extends Repository
{
    public const PURPOSE_PASSWORD_RESET = 'PASSWORD_RESET';
    public const PURPOSE_EMAIL_VERIFICATION = 'EMAIL_VERIFICATION';

    public function create(int $userId, string $purpose, string $tokenHash, int $ttlSeconds): int
    {
        // Issuing a new token invalidates any outstanding one for the same
        // purpose, so an old email cannot still be used.
        $this->database->run(
            'UPDATE auth_tokens SET consumed_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND purpose = :purpose AND consumed_at IS NULL',
            ['user_id' => $userId, 'purpose' => $purpose],
        );

        return $this->database->insert('auth_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'expires_at' => $this->timestampIn($ttlSeconds),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findUsable(string $tokenHash, string $purpose): ?array
    {
        return $this->database->selectOne(
            'SELECT id, user_id, purpose, expires_at
             FROM auth_tokens
             WHERE token_hash = :token_hash
               AND purpose = :purpose
               AND consumed_at IS NULL
               AND expires_at > UTC_TIMESTAMP()',
            ['token_hash' => $tokenHash, 'purpose' => $purpose],
        );
    }

    /**
     * Marks a token used.
     *
     * The `consumed_at IS NULL` guard makes this the atomic step: two parallel
     * redemptions race here and exactly one gets a row.
     */
    public function consume(int $tokenId): bool
    {
        return $this->database->run(
            'UPDATE auth_tokens SET consumed_at = UTC_TIMESTAMP()
             WHERE id = :id AND consumed_at IS NULL',
            ['id' => $tokenId],
        )->rowCount() === 1;
    }

    public function purgeExpired(int $graceDays = 7): int
    {
        return $this->database->run(
            'DELETE FROM auth_tokens
             WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)',
            ['days' => $graceDays],
        )->rowCount();
    }
}
