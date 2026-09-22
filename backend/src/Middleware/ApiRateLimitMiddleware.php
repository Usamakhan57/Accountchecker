<?php

declare(strict_types=1);

namespace AccountCheck\Middleware;

use AccountCheck\Core\Middleware;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Services\RateLimiter;

/**
 * Baseline per-caller request limit across the authenticated API.
 *
 * Keyed on the user id where there is one, so a shared office IP is not one
 * bucket. Credential endpoints have their own tighter limits inside
 * AuthService; this is the floor under everything else.
 */
final class ApiRateLimitMiddleware implements Middleware
{
    public function __construct(private readonly RateLimiter $rateLimiter)
    {
    }

    public function handle(Request $request): ?Response
    {
        $user = $request->attribute('user');
        $identifier = $user instanceof AuthenticatedUser ? 'user:' . $user->id : 'ip:' . $request->ip();

        $result = $this->rateLimiter->enforce('api', $identifier, 'You are sending requests too quickly.');

        $request->setAttribute('rate_limit_remaining', $result['remaining']);

        return null;
    }
}
