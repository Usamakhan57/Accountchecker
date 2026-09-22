<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

use AccountCheck\Support\Logger;

/**
 * cURL client for authorized provider APIs.
 *
 * Retries only what is worth retrying (timeouts, 429, 5xx) with exponential
 * backoff plus jitter, and honours a Retry-After header when the provider sends
 * one. Providers publish rate limits so that clients respect them; this client
 * backs off rather than hammering, and never tries to work around a limit.
 */
final class HttpClient
{
    public function __construct(
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxRetries = 3,
        private readonly int $retryBaseDelayMs = 500,
        private readonly string $userAgent = 'AccountCheck/1.0',
    ) {
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return HttpResponse
     */
    public function get(string $url, array $query = [], array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, $query, null, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $body, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, [], $body, $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, array $query, ?array $body, array $headers): HttpResponse
    {
        if (!$this->isSafeUrl($url)) {
            return HttpResponse::failure('The configured provider URL is not usable.', false);
        }

        if ($query !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query);
        }

        $attempt = 0;
        $lastFailure = HttpResponse::failure('The request could not be completed.', false);

        while ($attempt <= $this->maxRetries) {
            $response = $this->execute($method, $url, $body, $headers);

            if (!$response->shouldRetry()) {
                return $response;
            }

            $lastFailure = $response;
            $attempt++;

            if ($attempt > $this->maxRetries) {
                break;
            }

            $this->sleep($this->delayFor($attempt, $response->retryAfterSeconds));
        }

        $this->logger->channel('checker')->warning('Provider request failed after retries', [
            'method' => $method,
            'host' => parse_url($url, PHP_URL_HOST),
            'attempts' => $attempt,
            'status' => $lastFailure->status,
        ]);

        return $lastFailure;
    }

    /** @param array<string, mixed>|null $body */
    private function execute(string $method, string $url, ?array $body, array $headers): HttpResponse
    {
        $handle = curl_init();

        if ($handle === false) {
            return HttpResponse::failure('The HTTP client could not be initialised.', false);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            // A newline in a configured header value would let it inject more
            // headers, so they are stripped rather than trusted.
            $headerLines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => $this->userAgent,
            // TLS verification stays on. A provider with a broken certificate
            // is a provider we do not talk to.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Redirects are not followed: a provider redirecting an API call is
            // unexpected, and following one could reach an unintended host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_HEADERFUNCTION => static function ($_curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        if ($body !== null) {
            $encoded = json_encode($body);
            $options[CURLOPT_POSTFIELDS] = $encoded === false ? '{}' : $encoded;
        }

        curl_setopt_array($handle, $options);

        $startedAt = microtime(true);
        $rawBody = curl_exec($handle);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);

        curl_close($handle);

        if ($errorNumber !== 0 || $rawBody === false) {
            $isTransient = in_array($errorNumber, [
                CURLE_OPERATION_TIMEDOUT,
                CURLE_COULDNT_CONNECT,
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_GOT_NOTHING,
                CURLE_SEND_ERROR,
                CURLE_RECV_ERROR,
            ], true);

            $this->logger->channel('checker')->warning('Provider transport error', [
                'host' => parse_url($url, PHP_URL_HOST),
                'curl_errno' => $errorNumber,
                // The cURL message names the host, not a secret, and stays in
                // the log; the user sees a generic reason.
                'curl_error' => $errorMessage,
            ]);

            return HttpResponse::failure(
                $isTransient ? 'The verification service did not respond.' : 'The verification service could not be reached.',
                $isTransient,
                $elapsedMs,
            );
        }

        $retryAfter = isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])
            ? (int) $responseHeaders['retry-after']
            : null;

        return new HttpResponse(
            $status,
            (string) $rawBody,
            $responseHeaders,
            $elapsedMs,
            null,
            $retryAfter,
        );
    }

    /**
     * Exponential backoff with jitter.
     *
     * Jitter matters when a batch hits a 429: without it, every queued item
     * retries at the same moment and the provider is hit with a second spike.
     */
    private function delayFor(int $attempt, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null) {
            return min($retryAfterSeconds * 1000, 30_000);
        }

        $base = $this->retryBaseDelayMs * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int) max(1, $base * 0.25));

        return (int) min($base + $jitter, 30_000);
    }

    private function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Only absolute HTTPS URLs with a hostname are accepted.
     *
     * Provider URLs come from server configuration rather than from a request,
     * but the check keeps a typo in .env from turning into a request to an
     * unexpected scheme or a local socket.
     */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? '';

        return $host !== '' && !str_contains($host, ' ');
    }
}
