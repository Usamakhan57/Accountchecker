<?php

declare(strict_types=1);

/**
 * Seeds the reference data the application needs to function: roles,
 * permissions, checker types, plans and system settings.
 *
 *   php backend/database/seed.php
 *   php backend/database/seed.php --demo   also create a development account
 *
 * Every insert is idempotent (INSERT ... ON DUPLICATE KEY UPDATE), so re-running
 * refreshes reference rows without touching user data.
 *
 * The --demo flag creates one local development user. It refuses to run unless
 * APP_ENV is development or testing, and it prints a generated password rather
 * than shipping a known one.
 */

use AccountCheck\Core\Config;
use AccountCheck\Core\Container;
use AccountCheck\Core\Database;
use AccountCheck\Support\Str;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/** @var Container $container */
$container = $container ?? require dirname(__DIR__) . '/bootstrap/app.php';

$database = $container->get(Database::class);
$config = $container->get(Config::class);

$seedDemo = in_array('--demo', array_slice($argv, 1), true);

function seedOut(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

// Roles ---------------------------------------------------------------------
$roles = [
    ['slug' => 'USER', 'name' => 'User', 'description' => 'Runs checks and manages their own account.', 'is_staff' => 0],
    ['slug' => 'SUPPORT', 'name' => 'Support', 'description' => 'Answers tickets and reads user job history.', 'is_staff' => 1],
    ['slug' => 'MANAGER', 'name' => 'Manager', 'description' => 'Manages users, plans and checker configuration.', 'is_staff' => 1],
    ['slug' => 'ADMIN', 'name' => 'Administrator', 'description' => 'Full administrative access.', 'is_staff' => 1],
    ['slug' => 'SUPER_ADMIN', 'name' => 'Super administrator', 'description' => 'Full access including system settings.', 'is_staff' => 1],
];

foreach ($roles as $role) {
    $database->run(
        'INSERT INTO roles (slug, name, description, is_staff)
         VALUES (:slug, :name, :description, :is_staff)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_staff = VALUES(is_staff)',
        $role,
    );
}
seedOut(sprintf('Roles: %d', count($roles)));

// Permissions ---------------------------------------------------------------
$permissions = [
    'admin.access' => 'Open the admin panel.',
    'admin.users.manage' => 'View, suspend, reactivate and re-role users.',
    'admin.wallet.adjust' => 'Credit, debit and refund user wallets.',
    'admin.jobs.manage' => 'Inspect, cancel and retry any job.',
    'admin.checkers.manage' => 'Enable, disable and configure checkers.',
    'admin.plans.manage' => 'Create and edit pricing plans.',
    'admin.support.manage' => 'Read and answer every support ticket.',
    'admin.logs.view' => 'Read activity and audit logs.',
    'admin.settings.manage' => 'Change system settings.',
];

foreach ($permissions as $slug => $description) {
    $database->run(
        'INSERT INTO permissions (slug, description) VALUES (:slug, :description)
         ON DUPLICATE KEY UPDATE description = VALUES(description)',
        ['slug' => $slug, 'description' => $description],
    );
}

/** @var array<string, list<string>> $rolePermissions */
$rolePermissions = [
    'SUPPORT' => ['admin.access', 'admin.support.manage'],
    'MANAGER' => [
        'admin.access', 'admin.users.manage', 'admin.jobs.manage',
        'admin.checkers.manage', 'admin.plans.manage', 'admin.support.manage', 'admin.logs.view',
    ],
    'ADMIN' => array_keys($permissions),
    'SUPER_ADMIN' => array_keys($permissions),
];

foreach ($rolePermissions as $roleSlug => $slugs) {
    $roleId = $database->scalar('SELECT id FROM roles WHERE slug = :slug', ['slug' => $roleSlug]);
    if ($roleId === null) {
        continue;
    }

    foreach ($slugs as $permissionSlug) {
        $permissionId = $database->scalar(
            'SELECT id FROM permissions WHERE slug = :slug',
            ['slug' => $permissionSlug],
        );

        if ($permissionId === null) {
            continue;
        }

        $database->run(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
            ['role_id' => $roleId, 'permission_id' => $permissionId],
        );
    }
}
seedOut(sprintf('Permissions: %d', count($permissions)));

// Checker types -------------------------------------------------------------
// Defaults come from config/checkers.php so the file stays the single source of
// truth for what a checker is; the row then becomes admin-editable.
$sortOrder = 0;
$checkerDefaults = $config->array('checkers.defaults');

foreach ($checkerDefaults as $slug => $definition) {
    if (!is_array($definition)) {
        continue;
    }

    $database->run(
        'INSERT INTO checker_types
            (slug, label, category, input_kind, description, credit_cost, max_batch_size, rate_limit_per_minute, sort_order)
         VALUES
            (:slug, :label, :category, :input_kind, :description, :credit_cost, :max_batch_size, :rate_limit_per_minute, :sort_order)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            category = VALUES(category),
            input_kind = VALUES(input_kind),
            description = VALUES(description),
            sort_order = VALUES(sort_order)',
        [
            'slug' => (string) $slug,
            'label' => (string) ($definition['label'] ?? $slug),
            'category' => (string) ($definition['category'] ?? 'platform'),
            'input_kind' => (string) ($definition['input_kind'] ?? 'username'),
            'description' => (string) ($definition['description'] ?? ''),
            'credit_cost' => (int) ($definition['credit_cost'] ?? 1),
            'max_batch_size' => (int) ($definition['max_batch_size'] ?? 5000),
            'rate_limit_per_minute' => (int) ($definition['rate_limit_per_minute'] ?? 300),
            'sort_order' => $sortOrder += 10,
        ],
    );
}
seedOut(sprintf('Checker types: %d', count($checkerDefaults)));

// Plans ---------------------------------------------------------------------
// Pricing is database-driven; these are starting values an administrator edits.
$plans = [
    [
        'slug' => 'free',
        'name' => 'Free',
        'description' => 'Try the workspace and the free tools.',
        'price_cents' => 0,
        'credits' => 100,
        'features' => ['100 starter credits', 'Batches up to 100 records', 'Duplicate finder and name generator', 'CSV and TXT export'],
        'sort_order' => 10,
    ],
    [
        'slug' => 'starter',
        'name' => 'Starter',
        'description' => 'For occasional lists.',
        'price_cents' => 1900,
        'credits' => 5000,
        'features' => ['5,000 credits', 'Batches up to 2,000 records', 'Full result history', 'Email support'],
        'sort_order' => 20,
    ],
    [
        'slug' => 'professional',
        'name' => 'Professional',
        'description' => 'For regular batch work.',
        'price_cents' => 4900,
        'credits' => 15000,
        'features' => ['15,000 credits', 'Batches up to 5,000 records', 'Priority queue position', 'API access'],
        'sort_order' => 30,
    ],
    [
        'slug' => 'business',
        'name' => 'Business',
        'description' => 'For teams running lists continuously.',
        'price_cents' => 14900,
        'credits' => 50000,
        'features' => ['50,000 credits', 'Batches up to 5,000 records', 'Priority queue position', 'API access', 'Priority support'],
        'sort_order' => 40,
    ],
];

foreach ($plans as $plan) {
    $database->run(
        'INSERT INTO plans (slug, name, description, price_cents, currency, credits, features, sort_order)
         VALUES (:slug, :name, :description, :price_cents, :currency, :credits, :features, :sort_order)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            price_cents = VALUES(price_cents),
            credits = VALUES(credits),
            features = VALUES(features),
            sort_order = VALUES(sort_order)',
        [
            'slug' => $plan['slug'],
            'name' => $plan['name'],
            'description' => $plan['description'],
            'price_cents' => $plan['price_cents'],
            'currency' => 'USD',
            'credits' => $plan['credits'],
            'features' => json_encode($plan['features']),
            'sort_order' => $plan['sort_order'],
        ],
    );
}
seedOut(sprintf('Plans: %d', count($plans)));

// System settings -----------------------------------------------------------
$settings = [
    ['key' => 'registration_enabled', 'value' => '1', 'type' => 'boolean', 'public' => 1, 'description' => 'Allow new accounts to be created.'],
    ['key' => 'signup_bonus_credits', 'value' => '100', 'type' => 'integer', 'public' => 0, 'description' => 'Credits granted to a new account.'],
    ['key' => 'maintenance_mode', 'value' => '0', 'type' => 'boolean', 'public' => 1, 'description' => 'Refuse new jobs while maintenance is in progress.'],
    ['key' => 'support_enabled', 'value' => '1', 'type' => 'boolean', 'public' => 1, 'description' => 'Allow users to open support tickets.'],
    ['key' => 'max_concurrent_jobs_per_user', 'value' => '3', 'type' => 'integer', 'public' => 0, 'description' => 'How many jobs one account may have running at once.'],
];

foreach ($settings as $setting) {
    $database->run(
        'INSERT INTO system_settings (setting_key, setting_value, value_type, description, is_public)
         VALUES (:setting_key, :setting_value, :value_type, :description, :is_public)
         ON DUPLICATE KEY UPDATE description = VALUES(description), value_type = VALUES(value_type), is_public = VALUES(is_public)',
        [
            'setting_key' => $setting['key'],
            'setting_value' => $setting['value'],
            'value_type' => $setting['type'],
            'description' => $setting['description'],
            'is_public' => $setting['public'],
        ],
    );
}
seedOut(sprintf('System settings: %d', count($settings)));

// Development account -------------------------------------------------------
if ($seedDemo) {
    $environment = $config->string('app.env');

    if (!in_array($environment, ['development', 'testing', 'local'], true)) {
        fwrite(STDERR, 'Refusing to seed a demo account outside development. APP_ENV is "' . $environment . '".' . PHP_EOL);
        exit(1);
    }

    $email = 'owner@accountcheck.test';
    $existing = $database->selectOne('SELECT id FROM users WHERE email = :email', ['email' => $email]);

    if ($existing !== null) {
        seedOut('Demo account already exists: ' . $email);
    } else {
        // Generated, printed once, never committed anywhere.
        $password = Str::randomToken(9);
        $adminRoleId = (int) $database->scalar("SELECT id FROM roles WHERE slug = 'ADMIN'");

        $userId = $database->insert('users', [
            'uuid' => Str::uuid4(),
            'name' => 'Development Owner',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $adminRoleId,
            'status' => 'ACTIVE',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $walletId = $database->insert('wallets', ['user_id' => $userId, 'balance' => 10000, 'reserved' => 0]);
        $database->insert('wallet_transactions', [
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'amount' => 10000,
            'type' => 'CREDIT',
            'balance_after' => 10000,
            'description' => 'Development seed balance',
            'reference' => 'seed:development',
        ]);

        seedOut('');
        seedOut('Demo administrator created.');
        seedOut('  Email:    ' . $email);
        seedOut('  Password: ' . $password);
        seedOut('  This password is shown once. Change it after signing in.');
    }
}

seedOut('');
seedOut('Seeding complete.');
