<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * Immutable-ish view over the incoming HTTP request.
 *
 * Route parameters and the authenticated user are attached by the router and
 * the auth middleware respectively.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, string> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, mixed>  $cookies
     * @param array<string, mixed>  $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $cookies,
        private readonly array $files,
        private readonly string $ip,
        private readonly string $userAgent,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $headers = self::readHeaders();
        $body = self::readBody($headers['content-type'] ?? '');

        return new self(
            $method,
            '/' . trim($path, '/'),
            $_GET,
            $body,
            $headers,
            $_COOKIE,
            $_FILES,
            self::clientIp(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $this->routeParams = $params;

        return $this;
    }

    public function routeParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeParamInt(string $key, int $default = 0): int
    {
        $value = $this->routeParams[$key] ?? null;

        return ($value !== null && ctype_digit($value)) ? (int) $value : $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if (stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));

            return $token === '' ? null : $token;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function readHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server]) && is_scalar($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private static function readBody(string $contentType): array
    {
        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * Behind Nginx and Cloudflare the socket address is the proxy, so the
     * forwarded headers are preferred. They are attacker-controllable when the
     * API is reachable directly, which is why rate limiting also keys on the
     * authenticated user where one exists.
     */
    private static function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                ? trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }
}
