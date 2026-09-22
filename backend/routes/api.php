<?php

declare(strict_types=1);

use AccountCheck\Controllers\HealthController;
use AccountCheck\Core\Router;

/**
 * API route table.
 *
 * Everything lives under /api. Route middleware runs in declaration order:
 * authentication first, then authorization, then rate limiting.
 */
return static function (Router $router): void {
    $router->group('/api', [], static function (Router $router): void {
        // Health ---------------------------------------------------------
        $router->get('/health', [HealthController::class, 'index']);
        $router->get('/health/database', [HealthController::class, 'database']);
        $router->get('/health/worker', [HealthController::class, 'worker']);
    });
};
