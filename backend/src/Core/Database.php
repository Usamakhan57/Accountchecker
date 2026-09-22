<?php

declare(strict_types=1);

namespace AccountCheck\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO wrapper with a lazily opened connection.
 *
 * Every query in the application goes through here with bound parameters;
 * repositories never concatenate values into SQL.
 */
class Database
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($this->config['host'] ?? '127.0.0.1'),
            (int) ($this->config['port'] ?? 3306),
            (string) ($this->config['database'] ?? ''),
            (string) ($this->config['charset'] ?? 'utf8mb4'),
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements, so the driver never interpolates.
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
            // Timestamps are written as UTC by PHP (Repository::now) and by the
            // column defaults. Pinning the session zone keeps those two agreeing
            // whatever the database server's local zone happens to be.
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            // The driver message can carry credentials; it goes to the log only.
            throw new DatabaseException('Database connection failed.', 0, $e);
        }

        return $this->pdo;
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        try {
            [$sql, $bindings] = $this->expandRepeatedParameters($sql, $bindings);

            $statement = $this->pdo()->prepare($sql);

            // Bind each value with its PDO type rather than passing the array
            // to execute(), which would send everything as a string. With
            // emulated prepares off, MySQL rejects a string in LIMIT/OFFSET,
            // and an integer column compared against a string cannot use its
            // index.
            foreach ($bindings as $key => $value) {
                $statement->bindValue(
                    is_int($key) ? $key + 1 : $key,
                    $value,
                    match (true) {
                        is_int($value) => PDO::PARAM_INT,
                        is_bool($value) => PDO::PARAM_BOOL,
                        $value === null => PDO::PARAM_NULL,
                        default => PDO::PARAM_STR,
                    },
                );
            }

            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw new DatabaseException('Database query failed.', 0, $e);
        }
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_map(static fn (string $c) => ':' . $c, $columns)),
        );

        $this->run($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            return 0;
        }

        $set = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $bindings['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdentifier($column) . ' = :where_' . $column;
            $bindings['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $set),
            implode(' AND ', $conditions),
        );

        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Run a closure inside a transaction, nesting safely via a depth counter.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth = 0;
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * Gives each occurrence of a repeated named placeholder its own name.
     *
     * With emulated prepares off, PDO hands named parameters to MySQL as
     * positional ones, and MySQL then expects exactly as many values as there
     * are markers. A query that mentions the same name twice — `WHERE balance
     * - :amount >= 0 AND reserved + :amount <= balance`, or an email/name
     * search reusing `:search` — therefore fails with "Invalid parameter
     * number" even though the SQL is correct.
     *
     * Rewriting `:amount` to `:amount__1` / `:amount__2` and duplicating the
     * bound value keeps queries readable instead of forcing every caller to
     * invent a second name for the same value.
     *
     * @param array<string|int, mixed> $bindings
     * @return array{0: string, 1: array<string|int, mixed>}
     */
    private function expandRepeatedParameters(string $sql, array $bindings): array
    {
        if ($bindings === [] || array_is_list($bindings)) {
            return [$sql, $bindings];
        }

        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches) === 0) {
            return [$sql, $bindings];
        }

        /** @var array<string, int> $repeated */
        $repeated = array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1,
        );

        if ($repeated === []) {
            return [$sql, $bindings];
        }

        $expanded = $bindings;
        $seen = [];

        $rewritten = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $match) use (&$seen, &$expanded, $repeated, $bindings): string {
                $name = $match[1];

                // A marker with no bound value is left alone: it is either a
                // caller error, which PDO reports clearly, or not a parameter.
                if (!isset($repeated[$name]) || !array_key_exists($name, $bindings)) {
                    return $match[0];
                }

                $seen[$name] = ($seen[$name] ?? 0) + 1;
                $alias = $name . '__' . $seen[$name];
                $expanded[$alias] = $bindings[$name];

                return ':' . $alias;
            },
            $sql,
        );

        if ($rewritten === null) {
            return [$sql, $bindings];
        }

        foreach (array_keys($repeated) as $name) {
            unset($expanded[$name]);
        }

        return [$rewritten, $expanded];
    }

    /**
     * Identifiers are never user-supplied, but the whitelist keeps it that way
     * even if a future caller passes something through by mistake.
     */
    public function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new DatabaseException('Invalid SQL identifier.');
        }

        return '`' . $identifier . '`';
    }
}
