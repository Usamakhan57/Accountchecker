<?php

declare(strict_types=1);

namespace AccountCheck\Middleware;

use AccountCheck\Core\Config;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Core\ResponseDecorator;

/**
 * Security headers for the JSON API.
 *
 * The API serves no HTML, so the policy is the restrictive "deny everything"
 * form. The React app is served by Nginx and carries its own CSP; see
 * docs/DEPLOYMENT.md for that vhost.
 */
final class SecurityHeadersMiddleware implements ResponseDecorator
{
    public function __construct(private readonly Config $config)
    {
    }

    public function decorate(Response $response, Request $request): Response
    {
        $headers = [
            // No document, script, style or frame should ever load from an API
            // response, so everything is denied outright.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Cache-Control' => 'no-store, max-age=0',
        ];

        // HSTS only once HTTPS is actually terminating in front of the API;
        // sending it over plain HTTP would pin a scheme that does not work.
        if ($this->isSecureProduction($request)) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $response->withHeaders($headers);
    }

    private function isSecureProduction(Request $request): bool
    {
        if ($this->config->string('app.env') !== 'production') {
            return false;
        }

        if (str_starts_with($this->config->string('app.url'), 'https://')) {
            return true;
        }

        return strtolower($request->header('x-forwarded-proto')) === 'https';
    }
}
