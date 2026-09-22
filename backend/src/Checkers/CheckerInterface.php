<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * Contract every checker adapter implements.
 *
 * Adapters are the only place that talks to an external provider. Controllers
 * and the worker deal in this interface, so adding a platform means adding one
 * class and one registry entry — no change anywhere else.
 *
 * Every implementation must honour one rule: verification happens through
 * official or authorized APIs and publicly permitted sources only. An adapter
 * that has no authorized route to an answer returns UNAVAILABLE. It does not
 * attempt a login, test a credential, work around a rate limit or a CAPTCHA, or
 * scrape a protected endpoint.
 */
interface CheckerInterface
{
    /** The stable identifier used in the API, the database and the UI. */
    public function slug(): string;

    /**
     * Checks one raw input line and normalizes it.
     *
     * Runs at submission time, before any job is created, so malformed records
     * are reported to the user rather than consuming credits.
     */
    public function validateInput(string $input): InputValidation;

    /**
     * Performs the check for one already-validated, normalized input.
     *
     * Implementations must not throw for an expected failure: a timeout, a
     * rate-limited provider or a missing configuration is a CheckOutcome, not
     * an exception.
     */
    public function check(string $normalizedInput): CheckOutcome;

    /**
     * Maps a provider's raw response onto the normalized outcome.
     *
     * Separate from check() so the mapping can be tested against captured
     * responses without any network access.
     *
     * @param array<string, mixed> $providerResponse
     */
    public function normalizeResult(array $providerResponse): CheckOutcome;

    public function getCapabilities(): CheckerCapabilities;
}
