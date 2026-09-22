<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

use AccountCheck\Support\Logger;

/**
 * Shared behaviour for every adapter: mode handling, provider configuration and
 * the decision about whether a check may be attempted at all.
 *
 * The gate in check() is the enforcement point for the authorized-sources rule.
 * In production mode, an adapter with no configured provider returns
 * UNAVAILABLE. There is no fallback path, because the only fallbacks available
 * would be the ones this product does not do: scraping, enumeration against
 * endpoints that do not permit it, or probing a login form.
 */
abstract class AbstractChecker implements CheckerInterface
{
    public const MODE_MOCK = 'mock';
    public const MODE_PRODUCTION = 'production';

    /**
     * @param array{api_url?: string, api_key?: string} $provider
     * @param array<string, mixed>                      $settings Runtime row from checker_types.
     */
    public function __construct(
        protected readonly string $mode,
        protected readonly array $provider,
        protected readonly array $settings,
        protected readonly HttpClient $http,
        protected readonly Logger $logger,
        protected readonly MockEngine $mock,
    ) {
    }

    abstract public function slug(): string;

    abstract public function validateInput(string $input): InputValidation;

    abstract public function normalizeResult(array $providerResponse): CheckOutcome;

    /**
     * Calls the configured provider for one normalized input.
     *
     * Only reached when a provider is configured and the mode is production.
     */
    abstract protected function performCheck(string $normalizedInput): CheckOutcome;

    public function check(string $normalizedInput): CheckOutcome
    {
        if ($this->mode === self::MODE_MOCK) {
            return $this->mock->outcomeFor($this->slug(), $normalizedInput);
        }

        if (!$this->isConfigured()) {
            // Nothing is attempted. The user is told plainly, and the item is
            // not billable.
            return CheckOutcome::unavailable(
                $this->slug(),
                'No authorized verification source is configured for this checker.',
            );
        }

        $startedAt = microtime(true);

        try {
            $outcome = $this->performCheck($normalizedInput);
        } catch (\Throwable $e) {
            // An adapter bug must fail one item, not the whole job.
            $this->logger->channel('checker')->error('Checker adapter threw', [
                'checker' => $this->slug(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $outcome = CheckOutcome::error($this->slug(), 'The check could not be completed.', false);
        }

        return $outcome->responseTimeMs === null
            ? $outcome->withResponseTime((int) round((microtime(true) - $startedAt) * 1000))
            : $outcome;
    }

    public function getCapabilities(): CheckerCapabilities
    {
        return new CheckerCapabilities(
            slug: $this->slug(),
            label: (string) ($this->settings['label'] ?? $this->slug()),
            category: (string) ($this->settings['category'] ?? 'platform'),
            inputKind: (string) ($this->settings['input_kind'] ?? 'username'),
            description: (string) ($this->settings['description'] ?? ''),
            creditCost: (int) ($this->settings['credit_cost'] ?? 1),
            maxBatchSize: (int) ($this->settings['max_batch_size'] ?? 5000),
            enabled: (bool) ($this->settings['is_enabled'] ?? true),
            configured: $this->mode === self::MODE_MOCK || $this->isConfigured(),
            mode: $this->mode,
            supportsMetadata: $this->supportsMetadata(),
        );
    }

    /** True when an authorized verification endpoint is configured for this checker. */
    public function isConfigured(): bool
    {
        return trim((string) ($this->provider['api_url'] ?? '')) !== '';
    }

    public function creditCost(): int
    {
        return max(0, (int) ($this->settings['credit_cost'] ?? 1));
    }

    public function maxBatchSize(): int
    {
        return max(1, (int) ($this->settings['max_batch_size'] ?? 5000));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->settings['is_enabled'] ?? true);
    }

    protected function supportsMetadata(): bool
    {
        return false;
    }

    protected function apiUrl(): string
    {
        return rtrim((string) ($this->provider['api_url'] ?? ''), '/');
    }

    /**
     * Authorization headers for the provider.
     *
     * The key is read from server configuration at call time and never stored
     * on the instance in a form that could be serialised into a log or a
     * response.
     *
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        $key = (string) ($this->provider['api_key'] ?? '');

        if ($key === '') {
            return ['Accept' => 'application/json'];
        }

        return [
            'Authorization' => 'Bearer ' . $key,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Maps a non-success HTTP response onto an outcome.
     *
     * A 404 from a lookup endpoint is a real answer ("no such account"), so it
     * is INVALID rather than an error. Everything else the provider refuses is
     * UNKNOWN or ERROR — never a guess.
     */
    protected function outcomeFromFailure(HttpResponse $response): CheckOutcome
    {
        if ($response->transportError !== null) {
            return CheckOutcome::error(
                $this->slug(),
                $response->transportError,
                $response->transportErrorIsTransient,
                $response->elapsedMs,
            );
        }

        return match (true) {
            $response->status === 404 => CheckOutcome::invalid(
                $this->slug(),
                'The verification source reported no matching account.',
                [],
                $response->elapsedMs,
            ),
            $response->status === 429 => CheckOutcome::error(
                $this->slug(),
                'The verification service is rate limiting requests. The item will be retried.',
                true,
                $response->elapsedMs,
            ),
            $response->status === 401 || $response->status === 403 => CheckOutcome::unavailable(
                $this->slug(),
                'The configured verification source rejected our credentials.',
            ),
            $response->isServerError() => CheckOutcome::error(
                $this->slug(),
                'The verification service reported an error.',
                true,
                $response->elapsedMs,
            ),
            default => CheckOutcome::unknown(
                $this->slug(),
                'The verification source returned an unexpected response.',
                [],
                $response->elapsedMs,
            ),
        };
    }
}
