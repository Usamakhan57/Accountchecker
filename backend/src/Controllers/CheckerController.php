<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Services\JobService;

/**
 * The checker catalogue.
 *
 * What this returns is everything the client is allowed to know about a
 * checker: slug, label, credit cost, batch limit, whether it is switched on and
 * whether an authorized verification source is configured for it. Provider URLs
 * and API keys are not part of the payload and never leave the server.
 */
final class CheckerController extends Controller
{
    public function __construct(
        private readonly CheckerRegistry $registry,
        private readonly JobService $jobs,
    ) {
    }

    public function types(Request $request): Response
    {
        $capabilities = $this->registry->capabilities();

        // The UI needs to warn before a user spends credits on a checker that
        // has no authorized source configured, so the count is surfaced rather
        // than left to be derived.
        $unconfigured = count(array_filter(
            $capabilities,
            static fn (array $capability): bool => $capability['configured'] === false,
        ));

        return Response::success([
            'items' => $capabilities,
            'unconfigured_count' => $unconfigured,
        ], 'Available checkers');
    }

    public function show(Request $request): Response
    {
        $checker = $this->registry->get((string) $request->routeParam('slug', ''));

        return Response::success($checker->getCapabilities()->toArray(), 'Checker details');
    }

    /**
     * Validates a pasted list without creating a job.
     *
     * The workspace calls this before the review step so the user sees the
     * de-duplicated count, the malformed lines and the exact credit cost before
     * committing to anything. It runs the same preparation the submission path
     * runs, so the numbers shown here are the numbers that will be charged.
     */
    public function validateInput(Request $request): Response
    {
        $checker = $this->registry->get((string) $request->routeParam('slug', ''));
        $capabilities = $checker->getCapabilities();

        $input = $this->validate($request, [
            'input' => 'required|string|max:2000000',
        ]);

        $prepared = $this->jobs->prepareInput($checker, (string) $input['input'], $capabilities->maxBatchSize);
        $accepted = count($prepared['items']);

        return Response::success([
            'total_lines' => $prepared['total_lines'],
            'valid_count' => $accepted,
            'invalid_count' => $prepared['invalid_count'],
            'duplicate_count' => $prepared['duplicate_count'],
            'accepted_count' => $accepted,
            'over_limit' => $prepared['over_limit'],
            'max_batch_size' => $capabilities->maxBatchSize,
            'credit_cost_each' => $capabilities->creditCost,
            'credits_required' => $accepted * $capabilities->creditCost,
            // A sample, not the whole list: a 5,000-line paste does not need to
            // travel back to the browser to be confirmed.
            'sample' => array_slice(array_column($prepared['items'], 'normalized'), 0, 10),
            'invalid_samples' => $prepared['invalid_samples'],
            'configured' => $capabilities->configured,
            'mode' => $capabilities->mode,
        ], 'Input reviewed');
    }
}
