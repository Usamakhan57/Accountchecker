<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * Minimal .env loader.
 *
 * Values are read once into a static map so no filesystem access happens per
 * lookup. Nothing here ever reaches the HTTP response - callers are responsible
 * for deciding what is safe to expose.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = self::stripInlineComment(trim($parts[1]));

            if ($key === '') {
                continue;
            }

            self::$values[$key] = self::unquote($value);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $fromServer = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return ($fromServer === false || $fromServer === null) ? $default : $fromServer;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, null);

        return ($value === null || $value === '' || !is_numeric($value)) ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, null);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public static function list(string $key, string $default = ''): array
    {
        $raw = self::string($key, $default);
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Test seam: replace the loaded map without touching the filesystem.
     *
     * @param array<string, string> $values
     */
    public static function replace(array $values): void
    {
        self::$values = $values;
        self::$loaded = true;
    }

    private static function stripInlineComment(string $value): string
    {
        // A '#' only starts a comment when it is not inside quotes.
        if ($value === '' || $value[0] === '"' || $value[0] === "'") {
            return $value;
        }

        $hashAt = strpos($value, '#');

        return $hashAt === false ? $value : rtrim(substr($value, 0, $hashAt));
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
