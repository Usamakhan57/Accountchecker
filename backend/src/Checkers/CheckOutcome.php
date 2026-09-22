<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * The normalized result of checking one record.
 *
 * Every adapter returns this shape regardless of what its provider sent back,
 * so the worker, the results table and the exports never deal in per-provider
 * formats.
 */
final class CheckOutcome
{
    /**
     * @param string               $source   Which source answered ('gmail-api', 'mock', 'local').
     * @param array<string, mixed> $metadata Normalized extras (display name, follower count, …).
     */
    public function __construct(
        public readonly CheckStatus $status,
        public readonly ?string $reason = null,
        public readonly string $source = '',
        public readonly array $metadata = [],
        public readonly ?int $responseTimeMs = null,
        /** True when the failure is worth retrying (timeout, 429, 5xx). */
        public readonly bool $retryable = false,
    ) {
    }

    public static function valid(string $source, ?string $reason = null, array $metadata = [], ?int $ms = null): self
    {
        return new self(CheckStatus::Valid, $reason, $source, $metadata, $ms);
    }

    public static function invalid(string $source, string $reason, array $metadata = [], ?int $ms = null): self
    {
        return new self(CheckStatus::Invalid, $reason, $source, $metadata, $ms);
    }

    public static function unknown(string $source, string $reason, array $metadata = [], ?int $ms = null): self
    {
        return new self(CheckStatus::Unknown, $reason, $source, $metadata, $ms);
    }

    public static function error(string $source, string $reason, bool $retryable = false, ?int $ms = null): self
    {
        return new self(CheckStatus::Error, $reason, $source, [], $ms, $retryable);
    }

    /**
     * No authorized verification source could be used.
     *
     * The reason is written for a user reading a results table, not for a
     * developer reading a log.
     */
    public static function unavailable(string $source = '', ?string $reason = null): self
    {
        return new self(
            CheckStatus::Unavailable,
            $reason ?? 'Verification unavailable through an authorized source.',
            $source,
        );
    }

    public function withResponseTime(int $milliseconds): self
    {
        return new self(
            $this->status,
            $this->reason,
            $this->source,
            $this->metadata,
            $milliseconds,
            $this->retryable,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'source' => $this->source,
            'metadata' => $this->metadata,
            'response_time_ms' => $this->responseTimeMs,
        ];
    }
}
