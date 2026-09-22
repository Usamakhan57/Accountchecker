<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\NotificationRepository;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Repositories\WalletRepository;

/**
 * Builds the "who am I" payload returned by register, login and /api/user/me.
 *
 * One place, so the three endpoints can never drift apart and leave the client
 * guessing which shape it received.
 */
final class SessionPayloadService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
        private readonly NotificationRepository $notifications,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(AuthenticatedUser $user): array
    {
        $row = $this->users->findById($user->id);

        return [
            'user' => $user->toArray() + [
                'created_at' => $row['created_at'] ?? null,
                'last_login_at' => $row['last_login_at'] ?? null,
            ],
            'wallet' => $this->wallet($user->id),
            'unread_notifications' => $this->notifications->unreadCount($user->id),
        ];
    }

    /** @return array<string, mixed> */
    public function wallet(int $userId): array
    {
        $wallet = $this->wallets->find($userId);

        if ($wallet === null) {
            $this->wallets->ensureWallet($userId);
            $wallet = ['balance' => 0, 'reserved' => 0, 'available' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')];
        }

        return [
            'balance' => $wallet['balance'],
            'reserved' => $wallet['reserved'],
            'available' => $wallet['available'],
            'currency' => 'CREDITS',
            'updated_at' => $wallet['updated_at'],
        ];
    }
}
