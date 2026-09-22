<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Core\Database;

/**
 * Base for the data access layer.
 *
 * All SQL lives in repositories. Controllers and services never build queries,
 * and every value reaches the database as a bound parameter.
 */
abstract class Repository
{
    public function __construct(protected readonly Database $database)
    {
    }

    /**
     * Builds a WHERE fragment from a map of column => value.
     *
     * Column names come from code, never from a request; values are always
     * bound. Callers that need a user-chosen sort must pass it through
     * sortColumn() below.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function buildWhere(array $filters, string $prefix = 'f'): array
    {
        if ($filters === []) {
            return ['1 = 1', []];
        }

        $clauses = [];
        $bindings = [];
        $index = 0;

        foreach ($filters as $column => $value) {
            $placeholder = $prefix . $index++;

            if ($value === null) {
                $clauses[] = $this->database->quoteIdentifier($column) . ' IS NULL';
                continue;
            }

            $clauses[] = $this->database->quoteIdentifier($column) . ' = :' . $placeholder;
            $bindings[$placeholder] = $value;
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * Resolves a client-supplied sort key against an allow-list.
     *
     * Sort columns cannot be bound as parameters, so the only safe approach is
     * to map the request value onto a known column and fall back otherwise.
     *
     * @param array<string, string> $allowed Request key => qualified column.
     */
    protected function sortColumn(?string $requested, array $allowed, string $default): string
    {
        return ($requested !== null && isset($allowed[$requested])) ? $allowed[$requested] : $default;
    }

    protected function sortDirection(?string $requested): string
    {
        return strtolower((string) $requested) === 'asc' ? 'ASC' : 'DESC';
    }

    /**
     * Packs an IP for storage in a VARBINARY(16) column.
     *
     * inet_pton gives 4 bytes for IPv4 and 16 for IPv6; an unparseable value is
     * stored as NULL rather than as text.
     */
    protected function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    protected function unpackIp(mixed $packed): ?string
    {
        if (!is_string($packed) || $packed === '') {
            return null;
        }

        $address = @inet_ntop($packed);

        return $address === false ? null : $address;
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    protected function timestampIn(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }
}
