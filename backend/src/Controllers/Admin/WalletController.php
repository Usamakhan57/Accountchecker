<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\StatsRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Support\Presenter;

/**
 * The credit ledger across every account.
 *
 * Read-only. Credits are moved from the user's own admin page, where the
 * account being changed is on screen and a reason is required; a global ledger
 * is the wrong place to type an amount into.
 */
final class WalletController extends Controller
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly StatsRepository $stats,
    ) {
    }

    public function transactions(Request $request): Response
    {
        $paginator = $this->paginator($request);

        $type = strtoupper(trim((string) ($request->query('type') ?? '')));
        $type = in_array($type, ['CREDIT', 'DEBIT', 'REFUND', 'ADJUSTMENT'], true) ? $type : null;

        $userId = (int) ($request->query('user_id') ?? 0);

        $page = $this->wallets->allTransactions($paginator, $type, $userId > 0 ? $userId : null);

        $items = array_map(
            static function (array $row): array {
                $entry = Presenter::walletTransaction($row);
                $entry['user'] = [
                    'uuid' => (string) ($row['user_uuid'] ?? ''),
                    'email' => (string) ($row['user_email'] ?? ''),
                ];

                return $entry;
            },
            $page['items'],
        );

        $totals = $this->stats->platformTotals();

        return Response::success(
            $paginator->envelope($items, $page['total']) + ['credits' => $totals['credits']],
            'Credit ledger',
        );
    }
}
