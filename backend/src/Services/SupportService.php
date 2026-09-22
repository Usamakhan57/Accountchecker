<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Core\HttpException;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\NotificationRepository;
use AccountCheck\Repositories\SupportRepository;
use AccountCheck\Support\Paginator;
use AccountCheck\Support\Presenter;
use AccountCheck\Support\Str;

/**
 * Support tickets.
 *
 * Two rules shape this. A user reaches only their own tickets, enforced in the
 * query rather than by comparing ids afterwards, and a ticket that cannot be
 * reached is reported as missing rather than forbidden so a uuid cannot be
 * probed. And a staff reply is attributed to the team rather than to the agent
 * who wrote it, so answering a ticket does not expose a colleague's name to a
 * customer.
 */
final class SupportService
{
    /** Open tickets one account may hold at once. */
    private const MAX_OPEN_TICKETS = 5;

    public function __construct(
        private readonly SupportRepository $tickets,
        private readonly NotificationRepository $notifications,
        private readonly ActivityLogRepository $activity,
    ) {
    }

    /**
     * Opens a ticket with its first message.
     *
     * @return array<string, mixed>
     */
    public function open(AuthenticatedUser $user, string $subject, string $body, string $priority): array
    {
        if ($this->tickets->openCountForUser($user->id) >= self::MAX_OPEN_TICKETS) {
            throw HttpException::conflict(
                sprintf(
                    'You already have %d tickets open. Please add to one of those rather than opening another.',
                    self::MAX_OPEN_TICKETS,
                ),
                'TOO_MANY_OPEN_TICKETS',
            );
        }

        $uuid = Str::uuid4();
        $ticketId = $this->tickets->create($user->id, $uuid, $subject, $priority);
        $this->tickets->addMessage($ticketId, $user->id, false, $body);

        $this->activity->record($user->id, 'support.ticket_opened', 'ticket', $ticketId, ['subject' => $subject]);

        return $this->show($user, $uuid);
    }

    /**
     * A user's reply on their own ticket.
     *
     * @return array<string, mixed>
     */
    public function reply(AuthenticatedUser $user, string $uuid, string $body): array
    {
        $ticket = $this->requireOwned($user, $uuid);

        if (!in_array((string) $ticket['status'], SupportRepository::REPLYABLE, true)) {
            throw HttpException::conflict(
                'This ticket is closed. Please open a new one and we will pick it up there.',
                'TICKET_CLOSED',
            );
        }

        $this->tickets->addMessage((int) $ticket['id'], $user->id, false, $body);

        return $this->show($user, $uuid);
    }

    /**
     * A staff reply, which also notifies the ticket's owner.
     *
     * @return array<string, mixed>
     */
    public function staffReply(AuthenticatedUser $agent, string $uuid, string $body): array
    {
        $ticket = $this->tickets->find($uuid);

        if ($ticket === null) {
            throw HttpException::notFound('That ticket does not exist.', 'TICKET_NOT_FOUND');
        }

        // The agent's own id is stored so the trail exists internally, while
        // is_staff = 1 is what the presenter renders, so the customer sees the
        // team rather than a colleague's name.
        $this->tickets->addMessage((int) $ticket['id'], $agent->id, true, $body);

        $this->notifications->create(
            (int) $ticket['user_id'],
            'support',
            'Support replied to your ticket',
            sprintf('There is a new reply on "%s".', (string) $ticket['subject']),
            '/support/' . (string) $ticket['uuid'],
        );

        return $this->present($this->tickets->find($uuid) ?? $ticket);
    }

    /**
     * Changes a ticket's state.
     *
     * A user may only close their own ticket. Everything else is a staff act,
     * which is enforced by the route rather than here.
     *
     * @return array<string, mixed>
     */
    public function setStatus(AuthenticatedUser $actor, string $uuid, string $status, bool $asStaff): array
    {
        $ticket = $asStaff ? $this->tickets->find($uuid) : $this->requireOwned($actor, $uuid);

        if ($ticket === null) {
            throw HttpException::notFound('That ticket does not exist.', 'TICKET_NOT_FOUND');
        }

        if (!$asStaff && $status !== 'CLOSED') {
            throw HttpException::forbidden(
                'You can close your ticket; support decides the rest.',
                'STATUS_NOT_ALLOWED',
            );
        }

        if (!$this->tickets->setStatus((int) $ticket['id'], $status)) {
            throw HttpException::badRequest('That is not a valid ticket status.', 'INVALID_STATUS');
        }

        if ($asStaff && in_array($status, ['RESOLVED', 'CLOSED'], true)) {
            $this->notifications->create(
                (int) $ticket['user_id'],
                'support',
                $status === 'RESOLVED' ? 'Your ticket was marked resolved' : 'Your ticket was closed',
                sprintf('"%s" is now %s.', (string) $ticket['subject'], strtolower($status)),
                '/support/' . (string) $ticket['uuid'],
            );
        }

        return $this->present($this->tickets->find($uuid) ?? $ticket);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginate(AuthenticatedUser $user, Paginator $paginator, ?string $status): array
    {
        $page = $this->tickets->paginateForUser($user->id, $paginator, $status);

        return [
            'items' => array_map([$this, 'present'], $page['items']),
            'total' => $page['total'],
        ];
    }

    /**
     * One ticket with its whole conversation.
     *
     * @return array<string, mixed>
     */
    public function show(AuthenticatedUser $user, string $uuid): array
    {
        $ticket = $this->requireOwned($user, $uuid);

        return [
            'ticket' => $this->present($ticket),
            'messages' => array_map(
                [Presenter::class, 'ticketMessage'],
                $this->tickets->messages((int) $ticket['id']),
            ),
        ];
    }

    /**
     * One ticket for a staff agent, with the customer attached.
     *
     * @return array<string, mixed>
     */
    public function showForStaff(string $uuid): array
    {
        $ticket = $this->tickets->find($uuid);

        if ($ticket === null) {
            throw HttpException::notFound('That ticket does not exist.', 'TICKET_NOT_FOUND');
        }

        return [
            'ticket' => $this->present($ticket) + [
                'user' => [
                    'uuid' => (string) ($ticket['user_uuid'] ?? ''),
                    'name' => (string) ($ticket['user_name'] ?? ''),
                    'email' => (string) ($ticket['user_email'] ?? ''),
                ],
            ],
            'messages' => array_map(
                [Presenter::class, 'ticketMessage'],
                $this->tickets->messages((int) $ticket['id']),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>
     */
    private function present(array $ticket): array
    {
        $presented = Presenter::ticket($ticket);
        $presented['last_reply_at'] = $ticket['last_reply_at'] ?? null;
        $presented['can_reply'] = in_array((string) $ticket['status'], SupportRepository::REPLYABLE, true);

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwned(AuthenticatedUser $user, string $uuid): array
    {
        $ticket = $this->tickets->findForUser($user->id, $uuid);

        if ($ticket === null) {
            // 404 rather than 403, so somebody else's ticket id cannot be
            // confirmed by the shape of the refusal.
            throw HttpException::notFound('That ticket does not exist.', 'TICKET_NOT_FOUND');
        }

        return $ticket;
    }
}
