<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

/**
 * Admin-editable runtime settings.
 *
 * Values are cached per request, since several are read on most requests.
 * Only rows marked is_public may be exposed to the browser.
 */
final class SettingsRepository extends Repository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_int($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $settings = [];
        foreach ($this->database->select('SELECT setting_key, setting_value, value_type FROM system_settings') as $row) {
            $settings[(string) $row['setting_key']] = $this->cast($row['setting_value'], (string) $row['value_type']);
        }

        return $this->cache = $settings;
    }

    /**
     * Settings safe to send to the browser.
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $settings = [];
        foreach ($this->database->select(
            'SELECT setting_key, setting_value, value_type FROM system_settings WHERE is_public = 1',
        ) as $row) {
            $settings[(string) $row['setting_key']] = $this->cast($row['setting_value'], (string) $row['value_type']);
        }

        return $settings;
    }

    /** @return list<array<string, mixed>> */
    public function allWithMetadata(): array
    {
        $rows = $this->database->select(
            'SELECT setting_key, setting_value, value_type, description, is_public, updated_at
             FROM system_settings ORDER BY setting_key',
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['value'] = $this->cast($row['setting_value'], (string) $row['value_type']);
            $rows[$index]['is_public'] = (bool) $row['is_public'];
            unset($rows[$index]['setting_value']);
        }

        return $rows;
    }

    public function set(string $key, string $value, int $updatedBy): bool
    {
        $updated = $this->database->update(
            'system_settings',
            ['setting_value' => $value, 'updated_by' => $updatedBy],
            ['setting_key' => $key],
        );

        $this->cache = null;

        return $updated > 0;
    }

    public function exists(string $key): bool
    {
        return $this->database->scalar(
            'SELECT 1 FROM system_settings WHERE setting_key = :key LIMIT 1',
            ['key' => $key],
        ) !== null;
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            'integer' => (int) $value,
            'json' => json_decode((string) $value, true) ?? [],
            default => (string) $value,
        };
    }
}
