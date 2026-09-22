<?php

declare(strict_types=1);

/**
 * Regenerates database/schema.sql from the migration files.
 *
 *     php backend/database/build-schema.php
 *
 * schema.sql is the same DDL as the migrations concatenated in order, with each
 * CREATE TABLE made conditional so the file is safe to re-apply. Keeping it
 * generated (rather than dumped from a live server) means it stays portable
 * MySQL 8 DDL instead of picking up one server's dialect.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$header = <<<'SQL'
-- ---------------------------------------------------------------------------
-- AccountCheck - consolidated schema
--
-- Every migration in database/migrations, in order, as one file. Use it to
-- provision an empty database in a single step:
--
--     mysql -u accountcheck_app -p accountcheck < backend/database/schema.sql
--     php backend/database/seed.php
--
-- For a database that already exists, run the migration runner instead — it
-- records what has been applied and skips it next time:
--
--     php backend/database/migrate.php
--
-- Note: loading this file directly does NOT populate the `migrations` table.
-- Run `php backend/database/migrate.php` afterwards; it creates that table and
-- every CREATE here is guarded, so nothing is applied twice.
--
-- Regenerate after adding a migration:
--
--     php backend/database/build-schema.php
--
-- Target: MySQL 8.0+ (verified against MariaDB 10.11 as well). InnoDB, utf8mb4.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SQL;

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, 'No migrations found.' . PHP_EOL);
    exit(1);
}

$output = $header;

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, 'Could not read ' . basename($file) . PHP_EOL);
        exit(1);
    }

    $guarded = preg_replace(
        '/^CREATE TABLE (\w+) \(/m',
        'CREATE TABLE IF NOT EXISTS $1 (',
        $sql,
    ) ?? $sql;

    $output .= "\n\n" . str_repeat('-', 79) . "\n";
    $output .= '-- Source: database/migrations/' . basename($file) . "\n";
    $output .= str_repeat('-', 79) . "\n\n";
    $output .= rtrim($guarded) . "\n";
}

$output .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

file_put_contents(__DIR__ . '/schema.sql', $output);

fwrite(STDOUT, sprintf('schema.sql regenerated from %d migration(s).' . PHP_EOL, count($files)));
