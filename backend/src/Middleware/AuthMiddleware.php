<?php

declare(strict_types=1);

namespace AccountCheck\Middleware;

use AccountCheck\Auth\SessionManager;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Middleware;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;

/**
 * Requires a valid session and attaches the user to the request.
 *
 * Every authenticated route carries this. Nothing downstream re-reads the
 * cookie, so there is one place where a session becomes a user.
 */
final class AuthMiddleware implements Middleware
{
    public function __construct(private readonly SessionManager $sessions)
    {
    }

    public function handle(Request $request): ?Response
    {
        $user = $this->sessions->resolve($request);

        if ($user === null) {
            throw HttpException::unauthorized('Sign in to continue.', 'UNAUTHENTICATED');
        }

        if (!$user->isActive()) {
            throw HttpException::forbidden(
                'This account is not active. Contact support if you think that is wrong.',
                'ACCOUNT_INACTIVE',
            );
        }

        $request->setAttribute('user', $user);

        return null;
    }
}
