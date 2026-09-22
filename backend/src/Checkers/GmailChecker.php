<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * Gmail address checker.
 *
 * Two things are kept apart deliberately:
 *
 *   FORMAT_INVALID           the address is not a valid Gmail address. Decided
 *                            locally, costs nothing, needs no provider.
 *   VERIFICATION_UNAVAILABLE the address is well-formed but no authorized
 *                            verification source is configured, so nothing was
 *                            checked.
 *
 * Reporting the second as the first would tell a user their address is bad when
 * all that happened is that we could not look.
 *
 * Mailbox existence is resolved only through a configured, authorized
 * verification API. This adapter does not probe SMTP, does not run recipient
 * enumeration against Gmail's servers, and does not attempt any sign-in.
 */
final class GmailChecker extends AbstractChecker
{
    /** Domains this checker accepts. */
    private const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function slug(): string
    {
        return 'gmail';
    }

    public function validateInput(string $input): InputValidation
    {
        $candidate = $this->extractAddress($input);

        if ($candidate === null) {
            return InputValidation::invalid('No email address could be read from this line.');
        }

        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
            return InputValidation::invalid('Not a valid email address.');
        }

        if (strlen($candidate) > 254) {
            return InputValidation::invalid('Email address is too long.');
        }

        [$local, $domain] = explode('@', $candidate, 2);
        $domain = strtolower($domain);

        if (!in_array($domain, self::GMAIL_DOMAINS, true)) {
            return InputValidation::invalid('Not a Gmail address. Use the platform checker for other domains.');
        }

        if ($local === '') {
            return InputValidation::invalid('Not a valid email address.');
        }

        // Gmail treats the local part case-insensitively and ignores dots, so
        // "First.Last@gmail.com" and "firstlast@gmail.com" are one mailbox.
        // Normalising means a list containing both is charged once.
        $normalizedLocal = str_replace('.', '', strtolower($local));

        // Everything from a '+' onward is a tag on the same mailbox.
        $plusAt = strpos($normalizedLocal, '+');
        if ($plusAt !== false) {
            $normalizedLocal = substr($normalizedLocal, 0, $plusAt);
        }

        if ($normalizedLocal === '') {
            return InputValidation::invalid('Not a valid email address.');
        }

        return InputValidation::valid($normalizedLocal . '@gmail.com');
    }

    protected function performCheck(string $normalizedInput): CheckOutcome
    {
        $response = $this->http->get(
            $this->apiUrl() . '/verify',
            ['email' => $normalizedInput],
            $this->authHeaders(),
        );

        if (!$response->isSuccess()) {
            return $this->outcomeFromFailure($response);
        }

        return $this->normalizeResult($response->json())->withResponseTime($response->elapsedMs);
    }

    public function normalizeResult(array $providerResponse): CheckOutcome
    {
        // Providers disagree on wording, so a small vocabulary is mapped rather
        // than assuming one shape. Anything unrecognised becomes UNKNOWN, never
        // an assumed VALID.
        $raw = strtolower(trim((string) (
            $providerResponse['status']
            ?? $providerResponse['result']
            ?? $providerResponse['state']
            ?? ''
        )));

        $metadata = array_filter([
            'deliverable' => isset($providerResponse['deliverable']) ? (bool) $providerResponse['deliverable'] : null,
            'disposable' => isset($providerResponse['disposable']) ? (bool) $providerResponse['disposable'] : null,
            'mx_present' => isset($providerResponse['mx_found']) ? (bool) $providerResponse['mx_found'] : null,
        ], static fn ($value) => $value !== null);

        return match ($raw) {
            'valid', 'deliverable', 'live', 'ok', 'exists' => CheckOutcome::valid(
                $this->slug(),
                'Confirmed by the configured verification source.',
                $metadata,
            ),
            'invalid', 'undeliverable', 'dead', 'not_found', 'unregistered' => CheckOutcome::invalid(
                $this->slug(),
                'The verification source reported this address as not deliverable.',
                $metadata,
            ),
            'disabled', 'suspended' => CheckOutcome::invalid(
                $this->slug(),
                'The verification source reported this mailbox as disabled.',
                $metadata,
            ),
            'unknown', 'risky', 'catch_all', 'accept_all', 'unverifiable' => CheckOutcome::unknown(
                $this->slug(),
                'The verification source could not give a definite answer.',
                $metadata,
            ),
            default => CheckOutcome::unknown(
                $this->slug(),
                'The verification source returned a result we do not recognise.',
                $metadata,
            ),
        };
    }

    protected function supportsMetadata(): bool
    {
        return true;
    }

    /**
     * Pulls an address out of a pasted line.
     *
     * Exports from other tools often carry extra columns
     * ("name,user@gmail.com,2024-01-01"), so the first address-looking token
     * wins and the original line is still stored for the export.
     */
    private function extractAddress(string $input): ?string
    {
        $line = trim($input);

        if ($line === '') {
            return null;
        }

        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $line, $matches) === 1) {
            return trim($matches[0], " \t\n\r\0\x0B.,;:<>\"'");
        }

        return null;
    }
}
