<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Repositories\RateLimitRepository;

/**
 * Fixed-window rate limiting.
 *
 * Buckets are named "<name>:<identifier>", where the identifier is the
 * authenticated user id where one exists and the client IP otherwise. Limits
 * come from config('app.rate_limits').
 */
final class RateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $repository,
        private readonly Config $config,
    ) {
    }

    /**
     * Counts one attempt against a bucket.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function attempt(string $bucket, string $identifier): array
    {
        [$max, $windowSeconds] = $this->limitFor($bucket);

        if ($max <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $state = $this->repository->hit($this->key($bucket, $identifier), $windowSeconds);
        $expiresAt = strtotime($state['expires_at'] . ' UTC');
        $retryAfter = $expiresAt === false ? $windowSeconds : max(0, $expiresAt - time());

        return [
            'allowed' => $state['attempts'] <= $max,
            'remaining' => max(0, $max - $state['attempts']),
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Counts an attempt and throws 429 when the bucket is exhausted.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function enforce(string $bucket, string $identifier, string $message = 'Too many requests. Please try again shortly.'): array
    {
        $result = $this->attempt($bucket, $identifier);

        if (!$result['allowed']) {
            throw new HttpException(
                $message . ' You can try again in ' . $this->humanise($result['retry_after']) . '.',
                429,
                'RATE_LIMITED',
            );
        }

        return $result;
    }

    /** Clears a bucket after the action it was guarding succeeded. */
    public function clear(string $bucket, string $identifier): void
    {
        $this->repository->clear($this->key($bucket, $identifier));
    }

    /** @return array{0: int, 1: int} [max attempts, window in seconds] */
    private function limitFor(string $bucket): array
    {
        $limit = $this->config->array('app.rate_limits.' . $bucket);

        $attempts = isset($limit['attempts']) && is_numeric($limit['attempts']) ? (int) $limit['attempts'] : 0;
        $minutes = isset($limit['window_minutes']) && is_numeric($limit['window_minutes'])
            ? (int) $limit['window_minutes']
            : 1;

        return [$attempts, max(1, $minutes) * 60];
    }

    private function key(string $bucket, string $identifier): string
    {
        // The identifier is hashed so a bucket key never stores a raw email or
        // IP, and so the column length is bounded whatever is passed in.
        return substr($bucket, 0, 40) . ':' . hash('sha256', $identifier);
    }

    private function humanise(int $seconds): string
    {
        if ($seconds < 60) {
            return max(1, $seconds) . ' second' . ($seconds === 1 ? '' : 's');
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
}
