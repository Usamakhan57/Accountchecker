<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * Applied to every outgoing response, including errors and 404s.
 */
interface ResponseDecorator
{
    public function decorate(Response $response, Request $request): Response;
}
