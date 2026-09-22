<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\NotificationRepository;
use AccountCheck\Support\Presenter;

/**
 * A user's own notifications.
 *
 * Every query is scoped to the authenticated user id, so an id belonging to
 * somebody else simply matches nothing. Notifications are created by the
 * application (a job finishing, a support reply), never by a request: there is
 * no endpoint here that writes one.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $paginator = $this->paginator($request);
        $unreadOnly = in_array((string) ($request->query('unread') ?? ''), ['1', 'true'], true);

        $page = $this->notifications->paginateForUser($user->id, $paginator, $unreadOnly);

        return Response::success(
            $paginator->envelope(
                array_map([Presenter::class, 'notification'], $page['items']),
                $page['total'],
            ) + ['unread_count' => $this->notifications->unreadCount($user->id)],
            'Notifications',
        );
    }

    public function markRead(Request $request): Response
    {
        $user = $this->user($request);
        $id = (int) $request->routeParam('id', '0');

        if (!$this->notifications->markRead($user->id, $id)) {
            throw HttpException::notFound('That notification does not exist.', 'NOTIFICATION_NOT_FOUND');
        }

        return Response::success(
            ['unread_count' => $this->notifications->unreadCount($user->id)],
            'Marked as read',
        );
    }

    public function markAllRead(Request $request): Response
    {
        $user = $this->user($request);
        $marked = $this->notifications->markAllRead($user->id);

        return Response::success(['marked' => $marked, 'unread_count' => 0], 'All marked as read');
    }

    public function destroy(Request $request): Response
    {
        $user = $this->user($request);
        $id = (int) $request->routeParam('id', '0');

        if (!$this->notifications->delete($user->id, $id)) {
            throw HttpException::notFound('That notification does not exist.', 'NOTIFICATION_NOT_FOUND');
        }

        return Response::success(
            ['unread_count' => $this->notifications->unreadCount($user->id)],
            'Notification removed',
        );
    }
}
