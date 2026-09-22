<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\HistoryRepository;
use AccountCheck\Support\Presenter;

/**
 * Search history.
 *
 * One entry per finished job. Clearing history removes the user's record of
 * what they ran; it does not touch the jobs, the results or the credit ledger,
 * which are the accounting and are kept.
 */
final class HistoryController extends Controller
{
    public function __construct(private readonly HistoryRepository $history)
    {
    }

    /** GET /api/history */
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $paginator = $this->paginator($request);

        $page = $this->history->paginateForUser($user->id, $paginator, [
            'checker' => $request->query('checker'),
            'status' => $request->query('status'),
        ]);

        $items = array_map(Presenter::historyEntry(...), $page['items']);

        return Response::success(
            $paginator->envelope($items, $page['total']) + [
                'totals' => $this->history->totalsForUser($user->id),
            ],
            'Your history',
        );
    }

    /** DELETE /api/history/{id} */
    public function destroy(Request $request): Response
    {
        $user = $this->user($request);

        if (!$this->history->delete($user->id, $request->routeParamInt('id'))) {
            throw HttpException::notFound('That history entry does not exist.', 'HISTORY_NOT_FOUND');
        }

        return Response::success(null, 'History entry removed');
    }

    /** DELETE /api/history */
    public function clear(Request $request): Response
    {
        $user = $this->user($request);

        return Response::success(
            ['removed' => $this->history->clear($user->id)],
            'History cleared',
        );
    }
}
