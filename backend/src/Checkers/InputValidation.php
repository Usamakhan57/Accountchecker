<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * The result of validating one raw input line.
 *
 * A line that fails validation never becomes a job item: it is reported back to
 * the user at submission time, so they are not charged for records that could
 * never have been checked.
 */
final class InputValidation
{
    private function __construct(
        public readonly bool $isValid,
        public readonly string $normalized,
        public readonly ?string $reason = null,
    ) {
    }

    public static function valid(string $normalized): self
    {
        return new self(true, $normalized);
    }

    /**
     * @param string $reason User-facing explanation, e.g. "Not a valid email
     *                       address." This is a format problem, which is
     *                       deliberately distinct from verification being
     *                       unavailable.
     */
    public static function invalid(string $reason): self
    {
        return new self(false, '', $reason);
    }
}
