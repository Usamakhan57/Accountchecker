<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Repositories\CheckerTypeRepository;
use AccountCheck\Support\Logger;

/**
 * Resolves a checker slug to a configured adapter.
 *
 * Runtime settings (enabled, credit cost, batch limit) come from the
 * checker_types table so an administrator can change them without a deploy.
 * Provider credentials come from configuration and never from the database, so
 * an admin panel compromise cannot exfiltrate an API key or point a checker at
 * an attacker's endpoint.
 *
 * Handle rules live here rather than in each adapter, which is what lets one
 * PlatformChecker class serve every platform.
 */
final class CheckerRegistry
{
    /**
     * Per-platform username rules, from each platform's published limits.
     *
     * @var array<string, array{min: int, max: int, pattern: string}>
     */
    private const HANDLE_RULES = [
        'instagram' => ['min' => 1, 'max' => 30, 'pattern' => '/^[A-Za-z0-9._]+$/'],
        'facebook' => ['min' => 5, 'max' => 50, 'pattern' => '/^[A-Za-z0-9.]+$/'],
        'x' => ['min' => 1, 'max' => 15, 'pattern' => '/^[A-Za-z0-9_]+$/'],
        'tiktok' => ['min' => 2, 'max' => 24, 'pattern' => '/^[A-Za-z0-9._]+$/'],
        'threads' => ['min' => 1, 'max' => 30, 'pattern' => '/^[A-Za-z0-9._]+$/'],
    ];

    /** @var array<string, CheckerInterface> */
    private array $resolved = [];

    public function __construct(
        private readonly Config $config,
        private readonly CheckerTypeRepository $types,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly MockEngine $mock,
    ) {
    }

    /**
     * @throws HttpException 404 when the slug is unknown or the checker is off.
     */
    public function get(string $slug): CheckerInterface
    {
        $checker = $this->find($slug);

        if ($checker === null) {
            throw HttpException::notFound('That checker does not exist.', 'CHECKER_NOT_FOUND');
        }

        return $checker;
    }

    public function find(string $slug): ?CheckerInterface
    {
        $slug = strtolower(trim($slug));

        if (isset($this->resolved[$slug])) {
            return $this->resolved[$slug];
        }

        $settings = $this->types->findBySlug($slug);

        if ($settings === null) {
            return null;
        }

        return $this->resolved[$slug] = $this->build($slug, $settings);
    }

    /**
     * Every checker the platform knows about, enabled or not.
     *
     * @return list<CheckerInterface>
     */
    public function all(): array
    {
        $checkers = [];

        foreach ($this->types->all() as $settings) {
            $slug = (string) $settings['slug'];
            $checkers[] = $this->resolved[$slug] ??= $this->build($slug, $settings);
        }

        return $checkers;
    }

    /**
     * Capabilities for the checker picker, enabled checkers first.
     *
     * @return list<array<string, mixed>>
     */
    public function capabilities(bool $includeDisabled = false): array
    {
        $capabilities = [];

        foreach ($this->all() as $checker) {
            $capability = $checker->getCapabilities();

            if (!$capability->enabled && !$includeDisabled) {
                continue;
            }

            $capabilities[] = $capability->toArray();
        }

        return $capabilities;
    }

    /** @param array<string, mixed> $settings */
    private function build(string $slug, array $settings): CheckerInterface
    {
        $mode = $this->mode();
        $provider = $this->providerConfig($slug);

        // Gmail has its own adapter because email validation, normalisation and
        // the FORMAT_INVALID vs UNAVAILABLE distinction are specific to it.
        if ($slug === 'gmail') {
            return new GmailChecker($mode, $provider, $settings, $this->http, $this->logger, $this->mock);
        }

        return new PlatformChecker(
            $slug,
            self::HANDLE_RULES[$slug] ?? ['min' => 1, 'max' => 60, 'pattern' => '/^[A-Za-z0-9._-]+$/'],
            $mode,
            $provider,
            $settings,
            $this->http,
            $this->logger,
            $this->mock,
        );
    }

    private function mode(): string
    {
        $mode = strtolower($this->config->string('checkers.mode', AbstractChecker::MODE_MOCK));

        // Anything other than an explicit "production" is treated as mock. A
        // typo in .env must not silently start sending live traffic.
        return $mode === AbstractChecker::MODE_PRODUCTION
            ? AbstractChecker::MODE_PRODUCTION
            : AbstractChecker::MODE_MOCK;
    }

    /** @return array{api_url?: string, api_key?: string} */
    private function providerConfig(string $slug): array
    {
        $provider = $this->config->array('checkers.providers.' . $slug);

        return [
            'api_url' => is_string($provider['api_url'] ?? null) ? $provider['api_url'] : '',
            'api_key' => is_string($provider['api_key'] ?? null) ? $provider['api_key'] : '',
        ];
    }
}
