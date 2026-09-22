<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * Dot-notation access over the files in /config.
 *
 * Config files are plain PHP arrays evaluated once at boot; they read from Env
 * so that environment handling stays in a single place.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(private readonly string $configPath)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if ($file === null || $file === '') {
            return $default;
        }

        if (!array_key_exists($file, $this->items)) {
            $this->items[$file] = $this->loadFile($file);
        }

        $value = $this->items[$file];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /** @return array<mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    private function loadFile(string $file): array
    {
        // Only [a-z_] filenames are ever requested by application code; the
        // guard keeps a malformed key from reaching the filesystem.
        if (preg_match('/^[a-z_]+$/', $file) !== 1) {
            return [];
        }

        $path = $this->configPath . '/' . $file . '.php';
        if (!is_file($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }
}
