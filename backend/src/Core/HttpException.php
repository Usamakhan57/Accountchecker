<?php

declare(strict_types=1);

namespace AccountCheck\Core;

use RuntimeException;
use Throwable;

/**
 * Exception carrying a status code and a machine-readable error code.
 *
 * The message is written for end users - it is rendered straight into the API
 * response, so it must never contain internals.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly string $errorCode = 'REQUEST_FAILED',
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function toResponse(): Response
    {
        return Response::error($this->getMessage(), $this->errorCode, $this->status, $this->errors);
    }

    public static function badRequest(string $message = 'Invalid request.', string $code = 'INVALID_INPUT'): self
    {
        return new self($message, 400, $code);
    }

    public static function unauthorized(string $message = 'Authentication required.', string $code = 'UNAUTHENTICATED'): self
    {
        return new self($message, 401, $code);
    }

    public static function forbidden(string $message = 'You do not have access to this resource.', string $code = 'FORBIDDEN'): self
    {
        return new self($message, 403, $code);
    }

    public static function notFound(string $message = 'Resource not found.', string $code = 'NOT_FOUND'): self
    {
        return new self($message, 404, $code);
    }

    public static function conflict(string $message = 'The request conflicts with the current state.', string $code = 'CONFLICT'): self
    {
        return new self($message, 409, $code);
    }

    public static function tooManyRequests(string $message = 'Too many requests. Please slow down.', string $code = 'RATE_LIMITED'): self
    {
        return new self($message, 429, $code);
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors, string $message = 'The submitted data is invalid.'): self
    {
        return new self($message, 422, 'VALIDATION_FAILED', $errors);
    }
}
