<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

/**
 * Checker engine configuration.
 *
 * Credentials live here and never leave the server. The frontend learns only
 * what GET /api/checker/types exposes: slug, label, cost, limits and whether
 * the checker is configured at all.
 *
 * Runtime overrides (enabled flag, credit cost, rate limit) are stored in the
 * checker_types table and edited from the admin panel; this file supplies the
 * defaults used to seed that table and the provider settings that must not be
 * database-driven.
 */
return [
    // mock       - deterministic local results, no outbound calls.
    // production - authorized provider credentials required per checker.
    'mode' => Env::string('CHECKER_MODE', 'mock'),

    'http' => [
        'timeout' => Env::int('CHECKER_HTTP_TIMEOUT', 15),
        'max_retries' => Env::int('CHECKER_MAX_RETRIES', 3),
        'retry_base_delay_ms' => Env::int('CHECKER_RETRY_BASE_DELAY_MS', 500),
        'user_agent' => 'AccountCheck/1.0 (+authorized-verification)',
    ],

    'queue' => [
        'batch_size' => Env::int('QUEUE_BATCH_SIZE', 50),
        'max_job_items' => Env::int('QUEUE_MAX_JOB_ITEMS', 5000),
        'worker_sleep_seconds' => Env::int('WORKER_SLEEP_SECONDS', 3),
        'lock_ttl_seconds' => Env::int('WORKER_LOCK_TTL_SECONDS', 300),
        'max_runtime_seconds' => Env::int('WORKER_MAX_RUNTIME_SECONDS', 3600),
    ],

    'uploads' => [
        'max_bytes' => Env::int('UPLOAD_MAX_BYTES', 5_242_880),
        'allowed_extensions' => Env::list('UPLOAD_ALLOWED_EXTENSIONS', 'txt,csv'),
    ],

    'exports' => [
        'retention_hours' => Env::int('EXPORT_RETENTION_HOURS', 48),
        'formats' => ['csv', 'txt'],
    ],

    /**
     * Provider credentials, keyed by checker slug.
     *
     * An empty api_url means the checker has no authorized verification source
     * configured. In production mode it then reports UNAVAILABLE for every
     * item rather than attempting any unauthorized lookup.
     */
    'providers' => [
        'gmail' => [
            'api_url' => Env::string('GMAIL_VERIFY_API_URL', ''),
            'api_key' => Env::string('GMAIL_VERIFY_API_KEY', ''),
        ],
        'instagram' => [
            'api_url' => Env::string('INSTAGRAM_VERIFY_API_URL', ''),
            'api_key' => Env::string('INSTAGRAM_VERIFY_API_KEY', ''),
        ],
        'facebook' => [
            'api_url' => Env::string('FACEBOOK_VERIFY_API_URL', ''),
            'api_key' => Env::string('FACEBOOK_VERIFY_API_KEY', ''),
        ],
        'x' => [
            'api_url' => Env::string('X_VERIFY_API_URL', ''),
            'api_key' => Env::string('X_VERIFY_API_KEY', ''),
        ],
        'tiktok' => [
            'api_url' => Env::string('TIKTOK_VERIFY_API_URL', ''),
            'api_key' => Env::string('TIKTOK_VERIFY_API_KEY', ''),
        ],
        'threads' => [
            'api_url' => Env::string('THREADS_VERIFY_API_URL', ''),
            'api_key' => Env::string('THREADS_VERIFY_API_KEY', ''),
        ],
    ],

    /**
     * Defaults used to seed checker_types. The database row wins at runtime.
     *
     * credit_cost is in credits per item (1 credit = 1 unit of wallet balance).
     */
    'defaults' => [
        'gmail' => [
            'label' => 'Gmail Checker',
            'category' => 'email',
            'input_kind' => 'email',
            'credit_cost' => 1,
            'max_batch_size' => 5000,
            'rate_limit_per_minute' => 600,
            'description' => 'Validate Gmail address format and, where an authorized verification source is configured, its deliverability status.',
        ],
        'instagram' => [
            'label' => 'Instagram Checker',
            'category' => 'platform',
            'input_kind' => 'username',
            'credit_cost' => 2,
            'max_batch_size' => 5000,
            'rate_limit_per_minute' => 300,
            'description' => 'Check Instagram handles through an authorized platform API.',
        ],
        'facebook' => [
            'label' => 'Facebook Checker',
            'category' => 'platform',
            'input_kind' => 'username',
            'credit_cost' => 2,
            'max_batch_size' => 5000,
            'rate_limit_per_minute' => 300,
            'description' => 'Check Facebook profile handles through an authorized platform API.',
        ],
        'x' => [
            'label' => 'X (Twitter) Checker',
            'category' => 'platform',
            'input_kind' => 'username',
            'credit_cost' => 2,
            'max_batch_size' => 5000,
            'rate_limit_per_minute' => 300,
            'description' => 'Check X handles through an authorized platform API.',
        ],
        'tiktok' => [
            'label' => 'TikTok Checker',
            'category' => 'platform',
            'input_kind' => 'username',
            'credit_cost' => 2,
            'max_batch_size' => 5000,
            'rate_limit_per_minute' => 300,
            'description' => 'Check TikTok handles through an authorized platform API.',
        ],
        'threads' => [
            'label' => 'Threads Checker',
            'category' => 'platform',
            'input_kind' => 'username',
            'credit_cost' => 2,
            'max_batch_size' => 5000,
            'rate_limit_per_minute' => 300,
            'description' => 'Check Threads handles through an authorized platform API.',
        ],
    ],
];
