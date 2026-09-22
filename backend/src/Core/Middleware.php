<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * Middleware contract.
 *
 * Returning a Response short-circuits the pipeline; returning null lets the
 * request continue to the next middleware and ultimately the controller.
 */
interface Middleware
{
    public function handle(Request $request): ?Response;
}
