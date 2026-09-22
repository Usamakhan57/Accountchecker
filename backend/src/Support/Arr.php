<?php

declare(strict_types=1);

namespace AccountCheck\Support;

final class Arr
{
    /**
     * @param array<array-key, mixed> $array
     * @param list<array-key> $keys
     * @return array<array-key, mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<array-key> $keys
     * @return array<array-key, mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Splits a list into fixed-size chunks for batched inserts.
     *
     * @param list<mixed> $items
     * @return list<list<mixed>>
     */
    public static function chunk(array $items, int $size): array
    {
        if ($size < 1) {
            return [$items];
        }

        /** @var list<list<mixed>> $chunks */
        $chunks = array_chunk($items, $size);

        return $chunks;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function intOr(array $array, string $key, int $default = 0): int
    {
        $value = $array[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function stringOr(array $array, string $key, string $default = ''): string
    {
        $value = $array[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
