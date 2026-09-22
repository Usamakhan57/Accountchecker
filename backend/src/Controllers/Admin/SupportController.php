<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\SupportRepository;
use AccountCheck\Services\SupportService;
use AccountCheck\Support\Presenter;

/**
 * The support queue.
 *
 * Ordered by who has waited longest for a reply, so the oldest unanswered
 * ticket is the first one an agent sees rather than the newest.
 *
 * A reply from here is attributed to the team, not to the agent. The agent's
 * account id is recorded on the row so the internal trail exists; what the
 * customer reads is "AccountCheck support".
 */
final class SupportController extends Controller
{
    public function __construct(
        private readonly SupportService $support,
        private readonly SupportRepository $tickets,
    ) {
    }

    public function index(Request $request): Response
    {
        $paginator = $this->paginator($request);
        $status = strtoupper(trim((string) ($request->query('status') ?? '')));
        $status = in_array($status, SupportRepository::STATUSES, true) ? $status : null;

        $page = $this->tickets->paginateAll($paginator, $status);

        $items = array_map(
            static function (array $row): array {
                $ticket = Presenter::ticket($row);
                $ticket['last_reply_at'] = $row['last_reply_at'] ?? null;
                $ticket['user'] = [
                    'uuid' => (string) ($row['user_uuid'] ?? ''),
                    'name' => (string) ($row['user_name'] ?? ''),
                    'email' => (string) ($row['user_email'] ?? ''),
                ];

                return $ticket;
            },
            $page['items'],
        );

        return Response::success($paginator->envelope($items, $page['total']), 'Support queue');
    }

    public function show(Request $request): Response
    {
        return Response::success(
            $this->support->showForStaff((string) $request->routeParam('id', '')),
            'Ticket',
        );
    }

    public function reply(Request $request): Response
    {
        $this->requireSupportPermission($request);

        $input = $this->validate($request, [
            'body' => 'required|string|min:2|max:5000',
        ]);

        $this->support->staffReply(
            $this->user($request),
            (string) $request->routeParam('id', ''),
            (string) $input['body'],
        );

        return Response::success(
            $this->support->showForStaff((string) $request->routeParam('id', '')),
            'Reply sent',
        );
    }

    public function updateStatus(Request $request): Response
    {
        $this->requireSupportPermission($request);

        $input = $this->validate($request, [
            'status' => 'required|string|in:OPEN,PENDING,RESOLVED,CLOSED',
        ]);

        return Response::success(
            ['ticket' => $this->support->setStatus(
                $this->user($request),
                (string) $request->routeParam('id', ''),
                (string) $input['status'],
                true,
            )],
            'Ticket updated',
        );
    }

    /**
     * Reading the queue needs admin access; answering a customer needs the
     * support permission specifically.
     */
    private function requireSupportPermission(Request $request): void
    {
        if (!$this->user($request)->can('admin.support.manage')) {
            throw HttpException::forbidden(
                'You do not have permission to answer tickets.',
                'PERMISSION_DENIED',
            );
        }
    }
}
