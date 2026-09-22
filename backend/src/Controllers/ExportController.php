<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Services\ExportService;
use AccountCheck\Services\JobService;

/**
 * Result exports.
 *
 * Creating an export and downloading it are separate steps: the file is built
 * server-side and then fetched by uuid, so a large export never holds a request
 * open while the browser waits for bytes, and the same file can be fetched
 * again without regenerating it.
 */
final class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exports,
        private readonly JobService $jobs,
    ) {
    }

    /** GET /api/exports */
    public function index(Request $request): Response
    {
        $paginator = $this->paginator($request);

        return Response::success(
            $this->exports->paginate($this->user($request), $paginator),
            'Your exports',
        );
    }

    /**
     * POST /api/exports
     *
     * Exports whatever the results table is currently showing, using the same
     * filter vocabulary as GET /api/results.
     */
    public function store(Request $request): Response
    {
        $user = $this->user($request);

        $input = $this->validate($request, [
            'format' => 'required|string|in:csv,txt',
        ]);

        return Response::created(
            $this->exports->create($user, (string) $input['format'], [
                'status' => $request->input('status'),
                'checker' => $request->input('checker'),
                'search' => $request->input('search'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ], $request->ip()),
            'Export ready',
        );
    }

    /** POST /api/jobs/{id}/export */
    public function storeForJob(Request $request): Response
    {
        $user = $this->user($request);
        $job = $this->jobs->findOwned($user->id, (string) $request->routeParam('id', ''));

        $input = $this->validate($request, [
            'format' => 'required|string|in:csv,txt',
        ]);

        return Response::created(
            $this->exports->create($user, (string) $input['format'], [
                'job_id' => (int) $job['id'],
                'status' => $request->input('status'),
            ], $request->ip()),
            'Export ready',
        );
    }

    /** GET /api/exports/{uuid}/download */
    public function download(Request $request): Response
    {
        return $this->exports->download(
            $this->user($request),
            (string) $request->routeParam('uuid', ''),
        );
    }

    /** DELETE /api/exports/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->exports->delete($this->user($request), (string) $request->routeParam('uuid', ''));

        return Response::success(null, 'Export removed');
    }
}
