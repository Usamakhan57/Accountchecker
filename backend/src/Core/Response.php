<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * JSON response envelope used by every endpoint.
 *
 * Success:  { success: true,  message, data }
 * Failure:  { success: false, message, error_code, errors? }
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private readonly int $status,
        private readonly mixed $payload,
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function success(mixed $data = null, string $message = 'Request completed successfully', int $status = 200): self
    {
        return new self($status, [
            'success' => true,
            'message' => $message,
            'data' => $data ?? new \stdClass(),
        ]);
    }

    public static function created(mixed $data = null, string $message = 'Resource created successfully'): self
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): self
    {
        return new self(204, null, '');
    }

    /**
     * @param array<string, list<string>> $errors Field-level validation detail.
     */
    public static function error(
        string $message,
        string $errorCode = 'REQUEST_FAILED',
        int $status = 400,
        array $errors = [],
    ): self {
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return new self($status, $payload);
    }

    /**
     * Raw body response used for file downloads (CSV/TXT exports).
     */
    public static function raw(string $body, string $contentType, ?string $downloadName = null): self
    {
        $response = new self(200, null, $body);
        $response->headers['Content-Type'] = $contentType;
        $response->headers['Content-Length'] = (string) strlen($body);

        if ($downloadName !== null) {
            // Only a sanitised basename ever reaches the header.
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($downloadName)) ?? 'download';
            $response->headers['Content-Disposition'] = 'attachment; filename="' . $safeName . '"';
        }

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function body(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }

        $encoded = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false
            ? '{"success":false,"message":"Response encoding failed.","error_code":"ENCODING_ERROR"}'
            : $encoded;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            if ($this->rawBody === null) {
                header('Content-Type: application/json; charset=utf-8');
            }

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->status !== 204) {
            echo $this->body();
        }
    }
}
