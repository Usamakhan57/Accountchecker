<?php

declare(strict_types=1);

namespace AccountCheck\Middleware;

use AccountCheck\Core\Config;
use AccountCheck\Core\Middleware;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Core\ResponseDecorator;

/**
 * Origin allow-list CORS.
 *
 * The API answers with a credentialed session cookie, so the wildcard origin is
 * never emitted: only an origin present in config('app.cors.allowed_origins')
 * is echoed back, and Vary: Origin keeps caches from crossing the streams.
 */
final class CorsMiddleware implements Middleware, ResponseDecorator
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method() !== 'OPTIONS') {
            return null;
        }

        // Preflight never reaches a controller.
        return Response::noContent()->withHeaders($this->preflightHeaders($request));
    }

    public function decorate(Response $response, Request $request): Response
    {
        $origin = $request->header('origin');

        if ($origin === '' || !$this->isAllowed($origin)) {
            return $response->withHeader('Vary', 'Origin');
        }

        $exposed = $this->stringList('app.cors.exposed_headers');

        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Vary' => 'Origin',
        ];

        if ($this->config->get('app.cors.supports_credentials') === true) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($exposed !== []) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposed);
        }

        return $response->withHeaders($headers);
    }

    /** @return array<string, string> */
    private function preflightHeaders(Request $request): array
    {
        $origin = $request->header('origin');

        if ($origin === '' || !$this->isAllowed($origin)) {
            return ['Vary' => 'Origin'];
        }

        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $this->stringList('app.cors.allowed_methods')),
            'Access-Control-Allow-Headers' => implode(', ', $this->stringList('app.cors.allowed_headers')),
            'Access-Control-Max-Age' => (string) $this->config->int('app.cors.max_age', 600),
            'Vary' => 'Origin',
        ];

        if ($this->config->get('app.cors.supports_credentials') === true) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    private function isAllowed(string $origin): bool
    {
        $allowed = $this->stringList('app.cors.allowed_origins');

        foreach ($allowed as $candidate) {
            // Exact, case-insensitive scheme+host+port comparison. No wildcard
            // and no prefix matching, so "https://app.example.com.evil.tld"
            // cannot pass.
            if (strcasecmp(rtrim($candidate, '/'), rtrim($origin, '/')) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $values = [];
        foreach ($this->config->array($key) as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
