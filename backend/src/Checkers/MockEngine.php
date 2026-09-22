<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * Deterministic local checker engine for development and tests.
 *
 * No outbound request is ever made. The outcome is derived from a hash of the
 * checker slug and the input, so the same record always produces the same
 * result: a test can assert on a specific value, and a demo is reproducible.
 *
 * The distribution is chosen to exercise every branch of the UI — including
 * UNAVAILABLE and ERROR — rather than to imply anything about real-world rates.
 */
final class MockEngine
{
    /**
     * Cumulative thresholds over 1000. Roughly 55% VALID, 25% INVALID,
     * 10% UNKNOWN, 7% UNAVAILABLE, 3% ERROR.
     */
    private const DISTRIBUTION = [
        550 => CheckStatus::Valid,
        800 => CheckStatus::Invalid,
        900 => CheckStatus::Unknown,
        970 => CheckStatus::Unavailable,
        1000 => CheckStatus::Error,
    ];

    public function outcomeFor(string $slug, string $normalizedInput): CheckOutcome
    {
        $bucket = $this->bucket($slug . '|' . $normalizedInput);
        $status = $this->statusFor($bucket);

        // A stable, plausible-looking latency so the results table has
        // something to show without pretending to have measured a network.
        $responseMs = 40 + ($bucket % 260);

        return match ($status) {
            CheckStatus::Valid => CheckOutcome::valid(
                'mock',
                'Mock engine: deterministic result.',
                $this->metadataFor($slug, $normalizedInput, $bucket),
                $responseMs,
            ),
            CheckStatus::Invalid => CheckOutcome::invalid(
                'mock',
                'Mock engine: deterministic result.',
                [],
                $responseMs,
            ),
            CheckStatus::Unknown => CheckOutcome::unknown(
                'mock',
                'Mock engine: no definite result for this record.',
                [],
                $responseMs,
            ),
            CheckStatus::Unavailable => CheckOutcome::unavailable(
                'mock',
                'Mock engine: verification unavailable for this record.',
            ),
            CheckStatus::Error => CheckOutcome::error(
                'mock',
                'Mock engine: simulated transient failure.',
                false,
                $responseMs,
            ),
        };
    }

    /** Stable 0-999 bucket for an input. */
    private function bucket(string $key): int
    {
        // The first 8 hex digits give plenty of spread and fit an int on every
        // platform.
        return (int) hexdec(substr(hash('sha256', $key), 0, 8)) % 1000;
    }

    private function statusFor(int $bucket): CheckStatus
    {
        foreach (self::DISTRIBUTION as $threshold => $status) {
            if ($bucket < $threshold) {
                return $status;
            }
        }

        return CheckStatus::Unknown;
    }

    /**
     * Metadata shaped like what a platform adapter would normalize, so the
     * results table and exports can be exercised without a provider.
     *
     * @return array<string, mixed>
     */
    private function metadataFor(string $slug, string $normalizedInput, int $bucket): array
    {
        if ($slug === 'gmail') {
            return ['deliverable' => true, 'mx_present' => true];
        }

        return [
            'display_name' => ucfirst(explode('@', $normalizedInput)[0]),
            'followers' => $bucket * 37,
            'is_private' => $bucket % 7 === 0,
        ];
    }
}
