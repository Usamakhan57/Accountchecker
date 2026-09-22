<?php

declare(strict_types=1);

use AccountCheck\Controllers\AuthController;
use AccountCheck\Controllers\CheckerController;
use AccountCheck\Controllers\DashboardController;
use AccountCheck\Controllers\ExportController;
use AccountCheck\Controllers\HistoryController;
use AccountCheck\Controllers\HealthController;
use AccountCheck\Controllers\JobController;
use AccountCheck\Controllers\ResultController;
use AccountCheck\Controllers\ToolController;
use AccountCheck\Controllers\WalletController;
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
        $router->post('/checker/{slug}/start', [JobController::class, 'start'], $authenticated);

        // Jobs ------------------------------------------------------------
        // A job is started above and then polled here; nothing in this group
        // runs a check itself. {id} accepts either the numeric id or the uuid,
        // and every handler is scoped to the authenticated owner.
        $router->get('/jobs', [JobController::class, 'index'], $authenticated);
        $router->get('/jobs/{id}', [JobController::class, 'show'], $authenticated);
        $router->get('/jobs/{id}/progress', [JobController::class, 'progress'], $authenticated);
        $router->post('/jobs/{id}/cancel', [JobController::class, 'cancel'], $authenticated);
        $router->get('/jobs/{id}/results', [ResultController::class, 'forJob'], $authenticated);
        $router->post('/jobs/{id}/export', [ExportController::class, 'storeForJob'], $authenticated);

        // Results and history --------------------------------------------
        // Both are paged in the database. Filters and sort keys arrive as
        // query parameters and are resolved against allow-lists, never
        // concatenated into SQL.
        $router->get('/results', [ResultController::class, 'index'], $authenticated);
        $router->get('/history', [HistoryController::class, 'index'], $authenticated);
        $router->delete('/history', [HistoryController::class, 'clear'], $authenticated);
        $router->delete('/history/{id}', [HistoryController::class, 'destroy'], $authenticated);

        // Wallet -----------------------------------------------------------
        // Read-only by design. Credits arrive from a settled purchase or from
        // an administrator; no request field here maps onto a balance, so a
        // client cannot change its own.
        $router->get('/wallet', [WalletController::class, 'index'], $authenticated);
        $router->get('/wallet/transactions', [WalletController::class, 'transactions'], $authenticated);

        // Free tools -------------------------------------------------------
        // No credits and no job: these process the user's own list locally
        // and verify nothing, so there is no authorized source involved.
        $router->post('/tools/duplicates', [ToolController::class, 'duplicates'], $authenticated);
        $router->post('/tools/name-generator', [ToolController::class, 'names'], $authenticated);

        // Exports ---------------------------------------------------------
        // Addressed by uuid and resolved through the database, which carries
        // the owner; no path or filename from a request reaches the disk.
        $router->get('/exports', [ExportController::class, 'index'], $authenticated);
        $router->post('/exports', [ExportController::class, 'store'], $authenticated);
        $router->get('/exports/{uuid}/download', [ExportController::class, 'download'], $authenticated);
        $router->delete('/exports/{uuid}', [ExportController::class, 'destroy'], $authenticated);

        // Admin ----------------------------------------------------------
        // Routes are added by later phases; the middleware stack is fixed here
        // so every admin endpoint inherits the same gate.
        unset($admin);
    });
};
