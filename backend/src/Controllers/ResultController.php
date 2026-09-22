<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\ResultRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Support\Presenter;

/**
 * Check results.
 *
 * Two views over the same table: everything the user has ever checked, and the
 * results of one job. Both are paged, filtered and sorted in the database —
 * a result set can run to hundreds of thousands of rows, and none of these
 * endpoints can be made to return more than one page of them.
 *
 * Filtering and sorting are taken from the request but never trusted: the sort
 * key is resolved against an allow-list and every filter value is bound.
 */
final class ResultController extends Controller
{
    public function __construct(
        private readonly ResultRepository $results,
        private readonly JobService $jobs,
    ) {
    }

    /** GET /api/results */
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $paginator = $this->paginator($request);
        $filters = $this->filters($request);

        $page = $this->results->paginateForUser($user->id, $paginator, $filters);

        return Response::success(
            $paginator->envelope(Presenter::results($page['items']), $page['total']) + [
                // Counts across the whole filtered set, not just this page, so
                // the summary above the table does not change as you page.
                'breakdown' => $this->results->statusBreakdownForUser($user->id, $filters),
            ],
            'Your results',
        );
    }

    /** GET /api/jobs/{id}/results */
    public function forJob(Request $request): Response
    {
        $user = $this->user($request);
        $job = $this->jobs->findOwned($user->id, (string) $request->routeParam('id', ''));
        $paginator = $this->paginator($request);

        $page = $this->results->paginateForJob(
            (int) $job['id'],
            $user->id,
            $paginator,
            $this->filters($request),
        );

        return Response::success(
            $paginator->envelope(Presenter::results($page['items']), $page['total']) + [
                'job' => Presenter::job($job),
                'breakdown' => $this->results->statusBreakdownForJob((int) $job['id'], $user->id),
            ],
            'Job results',
        );
    }

    /**
     * Reads the filter vocabulary shared by the list and the export.
     *
     * Kept in one place so "export what I am looking at" really does export
     * what the table is showing.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'checker' => $request->query('checker'),
            'search' => $request->query('search'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
        ];
    }
}
