<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Services\AdminService;
use AccountCheck\Support\Paginator;
use AccountCheck\Support\Presenter;

/**
 * User administration.
 *
 * The rules that bound every change here live in AdminService, not in this
 * class: an administrator cannot act on their own account, only a super
 * administrator touches a super administrator, and every change is audited with
 * the actor attached. A controller is the wrong place for a rule that must hold
 * no matter which endpoint reaches it.
 *
 * Nothing here returns a password hash, a session token or a reset token.
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
        private readonly JobRepository $jobs,
        private readonly ActivityLogRepository $activity,
    ) {
    }

    public function index(Request $request): Response
    {
        $paginator = $this->paginator($request);

        $page = $this->users->paginate(
            $paginator,
            (string) ($request->query('search') ?? ''),
            (string) ($request->query('status') ?? ''),
            (string) ($request->query('role') ?? ''),
            (string) ($request->query('sort') ?? ''),
            (string) ($request->query('direction') ?? ''),
        );

        return Response::success(
            $paginator->envelope(array_map([Presenter::class, 'adminUser'], $page['items']), $page['total'])
            + [
                'counts' => $this->users->countsByStatus(),
                'roles' => $this->users->roles(),
            ],
            'Users',
        );
    }

    public function show(Request $request): Response
    {
        $user = $this->admin->findUser((string) $request->routeParam('id', ''));
        $userId = (int) $user['id'];

        $this->wallets->ensureWallet($userId);
        $recentJobs = $this->jobs->paginateAll(Paginator::fromInput(1, 10), ['user_id' => $userId]);

        return Response::success([
            'user' => Presenter::adminUser($user),
            'wallet' => $this->wallets->find($userId),
            'wallet_totals' => $this->wallets->totalsForUser($userId),
            'holds' => $this->wallets->activeHolds($userId),
            'recent_jobs' => Presenter::jobs($recentJobs['items']),
            'recent_activity' => $this->activity->paginateForUser($userId, Paginator::fromInput(1, 10))['items'],
            'roles' => $this->users->roles(),
        ], 'User details');
    }

    public function updateStatus(Request $request): Response
    {
        $user = $this->admin->findUser((string) $request->routeParam('id', ''));

        $input = $this->validate($request, [
            'status' => 'required|string|max:16',
        ]);

        $this->admin->setUserStatus(
            $this->user($request),
            $user,
            (string) $input['status'],
            $request->ip(),
        );

        return Response::success(
            ['user' => Presenter::adminUser($this->admin->findUser((string) $user['id']))],
            'Account status updated',
        );
    }

    public function updateRole(Request $request): Response
    {
        $user = $this->admin->findUser((string) $request->routeParam('id', ''));

        $input = $this->validate($request, [
            'role' => 'required|string|max:32',
        ]);

        $this->admin->setUserRole(
            $this->user($request),
            $user,
            (string) $input['role'],
            $request->ip(),
        );

        return Response::success(
            ['user' => Presenter::adminUser($this->admin->findUser((string) $user['id']))],
            'Role updated',
        );
    }

    /**
     * Credits or debits a wallet.
     *
     * The amount is signed and the reason is required, because this movement
     * shows up in the user's own credit history and an unexplained adjustment
     * there is indistinguishable from a bug.
     */
    public function adjustWallet(Request $request): Response
    {
        $user = $this->admin->findUser((string) $request->routeParam('id', ''));

        $input = $this->validate($request, [
            'amount' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $result = $this->admin->adjustWallet(
            $this->user($request),
            $user,
            (int) $input['amount'],
            (string) $input['reason'],
            $request->ip(),
        );

        return Response::success([
            'wallet' => $this->wallets->find((int) $user['id']),
            'amount' => $result['amount'],
        ], $result['amount'] > 0 ? 'Credits added' : 'Credits removed');
    }
}
