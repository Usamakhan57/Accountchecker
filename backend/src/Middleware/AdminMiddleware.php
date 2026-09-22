<?php

declare(strict_types=1);

namespace AccountCheck\Middleware;

use AccountCheck\Core\HttpException;
use AccountCheck\Core\Middleware;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\AuditLogRepository;

/**
 * Requires the `admin.access` permission.
 *
 * Runs after AuthMiddleware. Per-endpoint permissions are checked in the admin
 * controllers themselves; this is the gate on the whole /api/admin branch.
 *
 * A rejected attempt is audited: a signed-in user probing admin endpoints is
 * worth a record.
 */
final class AdminMiddleware implements Middleware
{
    public function __construct(private readonly AuditLogRepository $audit)
    {
    }

    public function handle(Request $request): ?Response
    {
        $user = $request->attribute('user');

        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        if (!$user->can('admin.access')) {
            $this->audit->record(
                'admin.access_denied',
                $user->id,
                $user->role,
                'endpoint',
                null,
                ['path' => $request->path(), 'method' => $request->method()],
                'WARNING',
                $request->ip(),
                $request->userAgent(),
            );

            // 404 rather than 403: a non-admin has no business learning that
            // the admin surface exists at this path.
            throw HttpException::notFound();
        }

        return null;
    }
}
