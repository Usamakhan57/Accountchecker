<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Support\Paginator;
use AccountCheck\Support\Validator;

/**
 * Shared controller helpers.
 *
 * Controllers stay thin: they validate input, call a service and shape the
 * response. Business rules and SQL live in services and repositories.
 */
abstract class Controller
{
    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules): array
    {
        return Validator::validate($request->all(), $rules);
    }

    /**
     * The user attached by AuthMiddleware.
     *
     * Reaching a controller without one means the route is missing its auth
     * middleware, which is a wiring bug rather than a client error.
     */
    protected function user(Request $request): AuthenticatedUser
    {
        $user = $request->attribute('user');

        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    protected function paginator(Request $request): Paginator
    {
        return Paginator::fromInput($request->query('page'), $request->query('per_page'));
    }
}
