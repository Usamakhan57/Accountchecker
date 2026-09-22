<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\SupportRepository;
use AccountCheck\Services\SupportService;

/**
 * A user's support tickets.
 *
 * Scoping happens in the service, in the query, so nothing here compares ids
 * after the fact. A ticket that is not the caller's is reported as missing.
 */
final class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support)
    {
    }

    public function index(Request $request): Response
    {
        $paginator = $this->paginator($request);
        $status = strtoupper(trim((string) ($request->query('status') ?? '')));
        $status = in_array($status, SupportRepository::STATUSES, true) ? $status : null;

        $page = $this->support->paginate($this->user($request), $paginator, $status);

        return Response::success($paginator->envelope($page['items'], $page['total']), 'Your tickets');
    }

    public function store(Request $request): Response
    {
        $input = $this->validate($request, [
            'subject' => 'required|string|min:4|max:160',
            'body' => 'required|string|min:10|max:5000',
            'priority' => 'nullable|string|in:LOW,NORMAL,HIGH',
        ]);

        return Response::created(
            $this->support->open(
                $this->user($request),
                (string) $input['subject'],
                (string) $input['body'],
                (string) ($input['priority'] ?? 'NORMAL'),
            ),
            'Ticket opened',
        );
    }

    public function show(Request $request): Response
    {
        return Response::success(
            $this->support->show($this->user($request), (string) $request->routeParam('id', '')),
            'Ticket',
        );
    }

    public function reply(Request $request): Response
    {
        $input = $this->validate($request, [
            'body' => 'required|string|min:2|max:5000',
        ]);

        return Response::success(
            $this->support->reply(
                $this->user($request),
                (string) $request->routeParam('id', ''),
                (string) $input['body'],
            ),
            'Reply sent',
        );
    }

    public function close(Request $request): Response
    {
        return Response::success(
            ['ticket' => $this->support->setStatus(
                $this->user($request),
                (string) $request->routeParam('id', ''),
                'CLOSED',
                false,
            )],
            'Ticket closed',
        );
    }
}
