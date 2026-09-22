<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\StatsRepository;
use AccountCheck\Services\SessionPayloadService;
use AccountCheck\Support\Presenter;

/**
 * The signed-in user's dashboard summary.
 *
 * Everything is scoped to the authenticated user id; there is no way to ask
 * this endpoint about somebody else's activity.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly StatsRepository $stats,
        private readonly SessionPayloadService $session,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Response::success([
            'wallet' => $this->session->wallet($user->id),
            'totals' => $this->stats->userTotals($user->id),
            'recent_jobs' => Presenter::jobs($this->stats->recentJobsForUser($user->id, 5)),
            'usage_by_day' => $this->stats->userUsageByDay($user->id, 14),
        ], 'Dashboard summary');
    }
}
