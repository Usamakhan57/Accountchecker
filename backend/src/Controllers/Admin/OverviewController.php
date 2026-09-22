<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Config;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\AuditLogRepository;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\StatsRepository;
use AccountCheck\Support\Paginator;
use AccountCheck\Support\Presenter;

/**
 * The administrator's landing view.
 *
 * Every figure here is counted from the database at request time. Nothing is
 * estimated, cached or filled in with a plausible-looking number: an admin
 * panel that lies about the queue is worse than one that shows nothing.
 */
final class OverviewController extends Controller
{
    public function __construct(
        private readonly StatsRepository $stats,
        private readonly JobRepository $jobs,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $recent = $this->jobs->paginateAll(Paginator::fromInput(1, 8), []);

        return Response::success([
            'totals' => $this->stats->platformTotals(),
            'health' => $this->stats->systemHealth(
                $this->config->int('checkers.queue.lock_ttl_seconds', 300),
            ),
            'checker_usage' => $this->stats->checkerUsage(30),
            'recent_jobs' => Presenter::adminJobs($recent['items']),
            'recent_events' => $this->audit->paginate(Paginator::fromInput(1, 8), null, null, null)['items'],
        ], 'Administration overview');
    }
}
