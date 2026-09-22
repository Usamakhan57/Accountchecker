<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Support\Presenter;

/**
 * The credit wallet, as the account owner sees it.
 *
 * Every endpoint here is a read. There is deliberately no route by which a
 * request can change its own balance: credits arrive from a completed purchase
 * or from an administrator, both of which happen elsewhere, server-side. No
 * field on any request in this controller maps onto a balance, so no amount a
 * client sends can reach one.
 *
 * Three figures are reported rather than one, because "balance" alone is
 * misleading while a job is running:
 *
 *   balance   - credits the account owns.
 *   reserved  - credits committed to jobs that have not finished.
 *   available - what can actually be spent on something new.
 *
 * Reserved credits are still the user's. A job that is cancelled or that could
 * not be checked gives them back, which is why the ledger shows a charge only
 * when a job settles.
 */
final class WalletController extends Controller
{
    public function __construct(private readonly WalletRepository $wallets)
    {
    }

    /** GET /api/wallet */
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $this->wallets->ensureWallet($user->id);
        $wallet = $this->wallets->find($user->id) ?? [
            'balance' => 0,
            'reserved' => 0,
            'available' => 0,
            'updated_at' => '',
        ];

        $holds = array_map(
            static fn (array $row): array => [
                'uuid' => (string) $row['uuid'],
                'checker_label' => (string) $row['checker_label'],
                'status' => (string) $row['status'],
                'credits_reserved' => (int) $row['credits_reserved'],
                'credits_spent' => (int) $row['credits_spent'],
                'total_items' => (int) $row['total_items'],
                'processed_items' => (int) $row['processed_items'],
            ],
            $this->wallets->activeHolds($user->id),
        );

        return Response::success([
            'wallet' => [
                'balance' => (int) $wallet['balance'],
                'reserved' => (int) $wallet['reserved'],
                'available' => (int) $wallet['available'],
                'currency' => 'CREDITS',
                'updated_at' => (string) $wallet['updated_at'],
            ],
            'totals' => $this->wallets->totalsForUser($user->id),
            'spend_by_day' => $this->wallets->spendByDay($user->id),
            // What the reserved figure is actually holding, so it is an
            // explanation rather than a number the user has to trust.
            'holds' => $holds,
        ], 'Your wallet');
    }

    /** GET /api/wallet/transactions */
    public function transactions(Request $request): Response
    {
        $user = $this->user($request);
        $paginator = $this->paginator($request);

        $type = $request->query('type');
        $type = is_string($type) && in_array(strtoupper($type), ['CREDIT', 'DEBIT', 'REFUND', 'ADJUSTMENT'], true)
            ? strtoupper($type)
            : null;

        $page = $this->wallets->transactions($user->id, $paginator, $type);

        return Response::success(
            $paginator->envelope(
                array_map(Presenter::walletTransaction(...), $page['items']),
                $page['total'],
            ),
            'Your credit history',
        );
    }
}
