<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * Handle checker for a social platform.
 *
 * One class covers every platform: they differ only in slug, handle rules and
 * label, which are supplied by the registry. Adding a platform is a registry
 * entry plus a config block — no new adapter class unless the platform's API
 * genuinely needs different handling, in which case it subclasses this.
 *
 * Every platform is checked through an authorized API endpoint configured for
 * it. Where none is configured, the adapter reports UNAVAILABLE. It does not
 * scrape profile pages, does not hit endpoints that require a signed-in
 * session, and does not rotate addresses or identities to get around a
 * platform's limits.
 */
class PlatformChecker extends AbstractChecker
{
    /**
     * @param array{api_url?: string, api_key?: string} $provider
     * @param array<string, mixed>                      $settings
     * @param array{min: int, max: int, pattern: string} $handleRules
     */
    public function __construct(
        private readonly string $slug,
        private readonly array $handleRules,
        string $mode,
        array $provider,
        array $settings,
        HttpClient $http,
        \AccountCheck\Support\Logger $logger,
        MockEngine $mock,
    ) {
        parent::__construct($mode, $provider, $settings, $http, $logger, $mock);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function validateInput(string $input): InputValidation
    {
        $handle = $this->extractHandle($input);

        if ($handle === '') {
            return InputValidation::invalid('No username could be read from this line.');
        }

        $min = (int) ($this->handleRules['min'] ?? 1);
        $max = (int) ($this->handleRules['max'] ?? 60);

        if (strlen($handle) < $min) {
            return InputValidation::invalid(sprintf('Username is shorter than %d characters.', $min));
        }

        if (strlen($handle) > $max) {
            return InputValidation::invalid(sprintf('Username is longer than %d characters.', $max));
        }

        $pattern = (string) ($this->handleRules['pattern'] ?? '/^[A-Za-z0-9._-]+$/');

        if (preg_match($pattern, $handle) !== 1) {
            return InputValidation::invalid('Username contains characters this platform does not allow.');
        }

        return InputValidation::valid(strtolower($handle));
    }

    protected function performCheck(string $normalizedInput): CheckOutcome
    {
        $response = $this->http->get(
            $this->apiUrl() . '/lookup',
            ['platform' => $this->slug, 'username' => $normalizedInput],
            $this->authHeaders(),
        );

        if (!$response->isSuccess()) {
            return $this->outcomeFromFailure($response);
        }

        return $this->normalizeResult($response->json())->withResponseTime($response->elapsedMs);
    }

    public function normalizeResult(array $providerResponse): CheckOutcome
    {
        $raw = strtolower(trim((string) (
            $providerResponse['status']
            ?? $providerResponse['state']
            ?? $providerResponse['result']
            ?? ''
        )));

        // Some providers answer with a bare boolean rather than a status word.
        if ($raw === '' && array_key_exists('exists', $providerResponse)) {
            $raw = $providerResponse['exists'] ? 'active' : 'not_found';
        }

        $metadata = $this->extractMetadata($providerResponse);

        return match ($raw) {
            'active', 'live', 'exists', 'found', 'public' => CheckOutcome::valid(
                $this->slug,
                'The account exists according to the authorized source.',
                $metadata,
            ),
            'not_found', 'unregistered', 'available', 'missing' => CheckOutcome::invalid(
                $this->slug,
                'No account exists for this username.',
                $metadata,
            ),
            'banned', 'suspended', 'disabled', 'deactivated' => CheckOutcome::invalid(
                $this->slug,
                'The account exists but is suspended or disabled.',
                $metadata,
            ),
            'private', 'restricted' => CheckOutcome::valid(
                $this->slug,
                'The account exists but its details are restricted.',
                $metadata,
            ),
            'unknown', 'indeterminate' => CheckOutcome::unknown(
                $this->slug,
                'The authorized source could not give a definite answer.',
                $metadata,
            ),
            default => CheckOutcome::unknown(
                $this->slug,
                'The authorized source returned a result we do not recognise.',
                $metadata,
            ),
        };
    }

    protected function supportsMetadata(): bool
    {
        return true;
    }

    /**
     * Normalizes the handful of profile fields worth surfacing.
     *
     * Only these keys are carried across: whatever else a provider returns
     * stays out of our database and out of exports.
     *
     * @param array<string, mixed> $providerResponse
     * @return array<string, mixed>
     */
    private function extractMetadata(array $providerResponse): array
    {
        $profile = is_array($providerResponse['profile'] ?? null) ? $providerResponse['profile'] : $providerResponse;

        $metadata = [
            'display_name' => $this->stringOrNull($profile['display_name'] ?? $profile['name'] ?? null),
            'followers' => $this->intOrNull($profile['followers'] ?? $profile['follower_count'] ?? null),
            'following' => $this->intOrNull($profile['following'] ?? $profile['following_count'] ?? null),
            'posts' => $this->intOrNull($profile['posts'] ?? $profile['post_count'] ?? null),
            'is_private' => isset($profile['is_private']) ? (bool) $profile['is_private'] : null,
            'is_verified' => isset($profile['is_verified']) ? (bool) $profile['is_verified'] : null,
        ];

        return array_filter($metadata, static fn ($value) => $value !== null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Accepts a bare handle, an @handle, or a profile URL, since people paste
     * all three.
     */
    private function extractHandle(string $input): string
    {
        $line = trim($input);

        if ($line === '') {
            return '';
        }

        // A pasted profile URL: take the first path segment.
        if (preg_match('#^https?://[^/]+/(?:@)?([A-Za-z0-9._-]+)#i', $line, $matches) === 1) {
            return $matches[1];
        }

        // Comma- or tab-separated export: the first field is the handle.
        $firstField = preg_split('/[\t,;|]/', $line)[0] ?? $line;

        return ltrim(trim((string) $firstField), '@');
    }
}
