<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\AuditLogRepository;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Support\Presenter;

/**
 * Every job, across every account.
 *
 * The one mutation is cancellation, and it goes through the same JobService
 * path a user's own cancel does: open records are closed, the job is finalised
 * once, and the reservation is settled against what was actually checked. An
 * administrator cancelling a job must not be able to produce a billing outcome
 * a user cancelling the same job could not.
 */
final class JobController extends Controller
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobService $service,
        private readonly AuditLogRepository $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $paginator = $this->paginator($request);

        $filters = [
            'status' => (string) ($request->query('status') ?? ''),
            'checker' => (string) ($request->query('checker') ?? ''),
        ];

        $userId = (int) ($request->query('user_id') ?? 0);

        if ($userId > 0) {
            $filters['user_id'] = $userId;
        }

        $page = $this->jobs->paginateAll($paginator, $filters);

        // The owner travels with each row so the table can link to the account
        // without a request per line.
        $items = Presenter::adminJobs($page['items']);

        return Response::success(
            $paginator->envelope($items, $page['total']) + ['queue_depth' => $this->jobs->queueDepth()],
            'Jobs',
        );
    }

    public function cancel(Request $request): Response
    {
        $actor = $this->user($request);
        $reference = (string) $request->routeParam('id', '');

        $job = ctype_digit($reference) ? $this->jobs->find((int) $reference) : null;

        if ($job === null) {
            throw HttpException::notFound('That job does not exist.', 'JOB_NOT_FOUND');
        }

        // Cancelled as the job's owner, through the ordinary path, so the
        // settlement rules are identical.
        $cancelled = $this->service->cancel((int) $job['user_id'], (string) $job['uuid'], $request->ip());

        $this->audit->record(
            'admin.job.cancelled',
            $actor->id,
            $actor->role,
            'job',
            (int) $job['id'],
            ['owner_id' => (int) $job['user_id'], 'status_before' => $job['status']],
            'NOTICE',
            $request->ip(),
        );

        return Response::success(['job' => Presenter::adminJob($cancelled)], 'Job cancelled');
    }
}
