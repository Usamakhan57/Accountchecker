<?php

declare(strict_types=1);

namespace AccountCheck\Auth;

/**
 * Password hashing.
 *
 * PASSWORD_DEFAULT follows PHP's current recommendation (bcrypt today,
 * whatever replaces it later). needsRehash() lets an existing hash be upgraded
 * transparently the next time its owner signs in, so an algorithm change does
 * not require a password reset.
 */
final class PasswordHasher
{
    public function hash(string $plaintext): string
    {
        $hash = password_hash($plaintext, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public function verify(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Burns roughly the same time as a real verification.
     *
     * Called when no user matches the submitted email, so a wrong address and a
     * wrong password take comparable time and the response cannot be used to
     * enumerate registered emails.
     */
    public function burnTime(): void
    {
        password_verify(
            'timing-equalisation',
            '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7VqMBPtRW0eHlQbnVKt2SWuiwpBGYAG',
        );
    }
}
