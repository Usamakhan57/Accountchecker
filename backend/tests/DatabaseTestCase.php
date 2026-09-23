<?php

declare(strict_types=1);

namespace AccountCheck\Tests;

use AccountCheck\Core\Config;
use AccountCheck\Core\Database;
use AccountCheck\Core\Request;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Support\Str;
use PDOException;
use Throwable;

/**
 * Base for tests that need a real database.
 *
 * The suite runs against a dedicated test database and truncates between cases,
 * which is why it refuses to run against a database whose name does not say it
 * is for testing. Losing a development database to a test run is a mistake that
 * should be impossible rather than unlikely.
 *
 * With no test database configured the whole group skips itself, so the suite
 * stays green on a checkout that has not set one up.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?bool $available = null;

    private static bool $tablesChecked = false;

    /**
     * Tables emptied between cases, children before parents.
     *
     * Reference data the migrations seed - roles, permissions, plans,
     * checker_types, system_settings - is deliberately absent: it is part of
     * the schema as far as a test is concerned.
     */
    private const TRUNCATE = [
        'api_usage',
        'api_keys',
        'support_messages',
        'support_tickets',
        'notifications',
        'exports',
        'search_history',
        'checker_results',
        'checker_job_items',
        'checker_jobs',
        'wallet_transactions',
        'wallets',
        'auth_tokens',
        'user_sessions',
        'activity_logs',
        'audit_logs',
        'rate_limits',
        'worker_heartbeats',
        'subscriptions',
        'user_roles',
        'users',
    ];

    protected Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->databaseAvailable()) {
            $this->markTestSkipped(
                'No test database configured. Copy .env.testing.example to .env.testing and set DB_NAME.',
            );
        }

        $this->database = $this->make(Database::class);
        $this->truncate();
    }

    private function databaseAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $config = $this->make(Config::class);
        $name = $config->string('database.database', '');

        if ($name === '') {
            return self::$available = false;
        }

        // The guard that makes truncation safe: a database not named for
        // testing is assumed to be somebody's real data.
        if (!str_contains($name, 'test')) {
            throw new \RuntimeException(
                sprintf(
                    'Refusing to run the database suite against "%s": the name does not contain "test". '
                    . 'Point DB_NAME in .env.testing at a dedicated database.',
                    $name,
                ),
            );
        }

        try {
            $this->make(Database::class)->scalar('SELECT 1');
        } catch (PDOException | Throwable) {
            return self::$available = false;
        }

        return self::$available = true;
    }

    protected function truncate(): void
    {
        // A name in the list that no longer matches a table would leave that
        // table's rows in place and every test after the first would run
        // against somebody else's data, so it is an error rather than a
        // condition to skip past.
        $this->assertTablesExist();

        $this->database->run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TRUNCATE as $table) {
            $this->database->run('TRUNCATE TABLE ' . $table);
        }

        $this->database->run('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function assertTablesExist(): void
    {
        if (self::$tablesChecked) {
            return;
        }

        self::$tablesChecked = true;

        $existing = array_map(
            static fn (array $row): string => (string) reset($row),
            $this->database->select(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()',
            ),
        );

        $missing = array_values(array_diff(self::TRUNCATE, $existing));

        if ($missing !== []) {
            throw new \RuntimeException(
                'The test database is missing these tables: ' . implode(', ', $missing)
                . '. Run the migrations against it before running the suite.',
            );
        }
    }

    /**
     * Builds a Request the way the front controller would have.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, mixed>  $cookies
     */
    protected function makeRequest(
        string $method = 'POST',
        string $path = '/api/test',
        array $body = [],
        array $headers = [],
        array $cookies = [],
        string $ip = '203.0.113.10',
    ): Request {
        return new Request(
            strtoupper($method),
            $path,
            [],
            $body,
            $headers,
            $cookies,
            [],
            $ip,
            'PHPUnit',
        );
    }

    /**
     * Creates a user with a wallet, and returns the model the middleware would
     * have attached to a request.
     */
    protected function makeUser(
        string $role = 'USER',
        int $credits = 1000,
        ?string $email = null,
    ): AuthenticatedUser {
        $users = $this->make(UserRepository::class);
        $wallets = $this->make(WalletRepository::class);

        $email ??= 'user-' . bin2hex(random_bytes(6)) . '@example.test';
        $userId = $users->create('Test User', $email, password_hash('Str0ng-Passw0rd!', PASSWORD_BCRYPT), $role);

        $this->database->run(
            'UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $userId],
        );

        $wallets->ensureWallet($userId);

        if ($credits > 0) {
            $wallets->credit($userId, $credits, 'CREDIT', 'Test credits');
        }

        $row = $users->findById($userId) ?? [];

        return new AuthenticatedUser(
            $userId,
            (string) ($row['uuid'] ?? Str::uuid4()),
            'Test User',
            $email,
            $role,
            'ACTIVE',
            true,
            0,
            $users->permissionsFor($userId),
        );
    }
}
