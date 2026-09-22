<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * One provider response, or the reason there was not one.
 */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly int $elapsedMs = 0,
        public readonly ?string $transportError = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $transportErrorIsTransient = false,
    ) {
    }

    public static function failure(string $message, bool $transient, int $elapsedMs = 0): self
    {
        return new self(0, '', [], $elapsedMs, $message, null, $transient);
    }

    public function isSuccess(): bool
    {
        return $this->transportError === null && $this->status >= 200 && $this->status < 300;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * A 4xx other than 429 is the provider telling us the request was wrong;
     * repeating it verbatim will not change the answer.
     */
    public function shouldRetry(): bool
    {
        if ($this->transportError !== null) {
            return $this->transportErrorIsTransient;
        }

        return $this->isRateLimited() || $this->isServerError();
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
