<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\AuditLogRepository;

/**
 * The audit log.
 *
 * Read-only, and deliberately so: an audit trail an administrator can edit is
 * not an audit trail. There is no delete endpoint, and the retention policy is
 * a database matter rather than a button.
 */
final class LogController extends Controller
{
    public function __construct(private readonly AuditLogRepository $audit)
    {
    }

    public function index(Request $request): Response
    {
        $paginator = $this->paginator($request);

        $severity = strtoupper(trim((string) ($request->query('severity') ?? '')));
        $severity = in_array($severity, ['INFO', 'NOTICE', 'WARNING', 'CRITICAL'], true) ? $severity : null;

        $event = trim((string) ($request->query('event') ?? ''));
        $actorId = (int) ($request->query('actor_id') ?? 0);

        $page = $this->audit->paginate(
            $paginator,
            $event === '' ? null : $event,
            $severity,
            $actorId > 0 ? $actorId : null,
        );

        return Response::success(
            $paginator->envelope($page['items'], $page['total'])
            + ['events' => $this->audit->distinctEvents()],
            'Audit log',
        );
    }
}
