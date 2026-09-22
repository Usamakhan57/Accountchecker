<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php backend/database/migrate.php            apply pending migrations
 *   php backend/database/migrate.php --status   list applied and pending
 *   php backend/database/migrate.php --seed     apply, then run seeds
 *
 * Migrations are the numbered .sql files in database/migrations, applied in
 * filename order and recorded in `migrations`. Applying is idempotent: a file
 * already recorded is skipped.
 *
 * This script is additive only. It creates and alters; it never drops a table
 * or truncates data, so running it against a populated database is safe.
 */

use AccountCheck\Core\Container;
use AccountCheck\Core\Database;
use AccountCheck\Core\DatabaseException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/bootstrap/app.php';

$database = $container->get(Database::class);

$options = array_slice($argv, 1);
$showStatus = in_array('--status', $options, true);
$runSeeds = in_array('--seed', $options, true);

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * Splits a migration file into individual statements.
 *
 * Line comments are stripped first so a `;` inside one cannot end a statement
 * early. The schema contains no stored routines, so no DELIMITER handling is
 * needed.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    $statements = [];
    foreach (explode(';', $withoutComments) as $statement) {
        $trimmed = trim($statement);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
    }

    return $statements;
}

try {
    $database->run(
        'CREATE TABLE IF NOT EXISTS migrations (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration   VARCHAR(191) NOT NULL,
            batch       INT UNSIGNED NOT NULL DEFAULT 1,
            applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_name (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
} catch (DatabaseException $e) {
    fail('Could not reach the database. Check DB_* in backend/.env. (' . $e->getMessage() . ')');
}

$applied = [];
foreach ($database->select('SELECT migration FROM migrations') as $row) {
    $applied[(string) $row['migration']] = true;
}

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($showStatus) {
    out('Migration status');
    out(str_repeat('-', 56));
    foreach ($files as $file) {
        $name = basename($file);
        out(sprintf('  [%s] %s', isset($applied[$name]) ? 'x' : ' ', $name));
    }
    exit(0);
}

$batch = (int) ($database->scalar('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations') ?? 1);
$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fail('Could not read migration ' . $name);
    }

    out('Applying ' . $name . ' …');

    // MySQL commits implicitly on DDL, so a failed migration cannot be rolled
    // back. Each file is applied in order and the runner stops at the first
    // failure, leaving the remaining files pending so the operator can fix the
    // cause and re-run.
    foreach (splitStatements($sql) as $statement) {
        try {
            $database->run($statement);
        } catch (DatabaseException $e) {
            fail(sprintf(
                "Migration %s failed.\n  %s\n\nThe statement that failed starts:\n  %s",
                $name,
                $e->getPrevious()?->getMessage() ?? $e->getMessage(),
                substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 160),
            ));
        }
    }

    $database->insert('migrations', ['migration' => $name, 'batch' => $batch]);
    $ran++;
}

out($ran === 0 ? 'Database is already up to date.' : sprintf('Applied %d migration(s).', $ran));

if ($runSeeds) {
    out('');
    require __DIR__ . '/seed.php';
}
