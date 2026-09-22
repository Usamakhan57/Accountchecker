<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * The five outcomes a check can have.
 *
 * The distinction that matters most is INVALID vs UNAVAILABLE. INVALID means an
 * authorized source looked and said no. UNAVAILABLE means no authorized source
 * could be used at all, so nothing was attempted and nothing should be inferred
 * about the record. Collapsing the two would turn "we could not check" into "it
 * is bad", which is the single most misleading thing this product could do.
 */
enum CheckStatus: string
{
    /** An authorized source confirmed the record. */
    case Valid = 'VALID';

    /** An authorized source reported the record as not valid. */
    case Invalid = 'INVALID';

    /** The source answered, but without a definite result. */
    case Unknown = 'UNKNOWN';

    /** The check could not be completed (transport failure, retries spent). */
    case Error = 'ERROR';

    /** No authorized verification source exists or is configured. */
    case Unavailable = 'UNAVAILABLE';

    /** True when the item counts as successfully checked for job statistics. */
    public function isConclusive(): bool
    {
        return $this === self::Valid || $this === self::Invalid;
    }

    /**
     * True when the item should be charged for.
     *
     * A record nothing was attempted on is not billable, so UNAVAILABLE is
     * free. ERROR is free too: the user should not pay for our failure to
     * complete a check.
     */
    public function isBillable(): bool
    {
        return $this !== self::Unavailable && $this !== self::Error;
    }
}
