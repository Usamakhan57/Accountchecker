<?php

declare(strict_types=1);

use AccountCheck\Controllers\AuthController;
use AccountCheck\Controllers\CheckerController;
use AccountCheck\Controllers\DashboardController;
use AccountCheck\Controllers\HealthController;
use AccountCheck\Controllers\UserController;
use AccountCheck\Core\Router;
use AccountCheck\Middleware\AdminMiddleware;
use AccountCheck\Middleware\ApiRateLimitMiddleware;
use AccountCheck\Middleware\AuthMiddleware;

/**
 * API route table.
 *
 * Everything lives under /api. Route middleware runs in declaration order:
 * authentication first, then authorization, then the baseline rate limit.
 *
 * Authorization is enforced here and again inside each admin controller. A
 * route without AuthMiddleware is deliberately public.
 */
return static function (Router $router): void {
    $authenticated = [AuthMiddleware::class, ApiRateLimitMiddleware::class];
    $admin = [AuthMiddleware::class, AdminMiddleware::class, ApiRateLimitMiddleware::class];

    $router->group('/api', [], static function (Router $router) use ($authenticated, $admin): void {
        // Health ---------------------------------------------------------
        $router->get('/health', [HealthController::class, 'index']);
        $router->get('/health/database', [HealthController::class, 'database']);
        $router->get('/health/worker', [HealthController::class, 'worker']);

        // Authentication -------------------------------------------------
        // Public: each of these carries its own rate limit inside AuthService,
        // keyed on both the client address and the submitted email.
        $router->post('/auth/register', [AuthController::class, 'register']);
        $router->post('/auth/login', [AuthController::class, 'login']);
        $router->post('/auth/logout', [AuthController::class, 'logout']);
        $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        $router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);
        $router->post('/auth/verify-email', [AuthController::class, 'verifyEmail']);

        $router->post('/auth/change-password', [AuthController::class, 'changePassword'], $authenticated);
        $router->post('/auth/resend-verification', [AuthController::class, 'resendVerification'], $authenticated);

        // Account --------------------------------------------------------
        $router->get('/user/me', [UserController::class, 'me'], $authenticated);
        $router->put('/user/profile', [UserController::class, 'updateProfile'], $authenticated);
        $router->get('/user/sessions', [UserController::class, 'sessions'], $authenticated);
        $router->post('/user/sessions/revoke-others', [UserController::class, 'revokeOtherSessions'], $authenticated);
        $router->get('/user/activity', [UserController::class, 'activity'], $authenticated);

        // Dashboard ------------------------------------------------------
        $router->get('/dashboard', [DashboardController::class, 'index'], $authenticated);

        // Checkers -------------------------------------------------------
        $router->get('/checker/types', [CheckerController::class, 'types'], $authenticated);
        $router->get('/checker/types/{slug}', [CheckerController::class, 'show'], $authenticated);
        $router->post('/checker/{slug}/validate', [CheckerController::class, 'validateInput'], $authenticated);

        // Admin ----------------------------------------------------------
        // Routes are added by later phases; the middleware stack is fixed here
        // so every admin endpoint inherits the same gate.
        unset($admin);
    });
};
