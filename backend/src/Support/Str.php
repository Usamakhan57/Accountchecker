<?php

declare(strict_types=1);

namespace AccountCheck\Support;

/**
 * String and token helpers shared across services.
 */
final class Str
{
    /** Cryptographically secure token, hex encoded. */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /**
     * Hash for a token stored at rest (session ids, reset tokens, API keys).
     *
     * SHA-256 over a 256-bit random token: the token has full entropy already,
     * so a slow KDF adds cost without adding resistance, and lookups stay
     * indexable.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', $separator, $value) ?? '';

        return trim($value, $separator);
    }

    /** Filesystem-safe basename with no directory component. */
    public static function safeFilename(string $value, string $fallback = 'file'): string
    {
        $base = basename(str_replace('\\', '/', $value));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? '';
        $base = ltrim($base, '.');

        return $base === '' ? $fallback : substr($base, 0, 120);
    }

    /** Masks all but the first and last character of a local part. */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $length = strlen($local);

        if ($length <= 2) {
            return str_repeat('*', $length) . $domain;
        }

        return $local[0] . str_repeat('*', $length - 2) . $local[$length - 1] . $domain;
    }

    /**
     * Splits pasted input into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    public static function lines(string $input, int $limit = 0): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $input);
        $lines = explode("\n", $normalised);

        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $result[] = $line;
            if ($limit > 0 && count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }
}
