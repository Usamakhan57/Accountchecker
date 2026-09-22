<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;

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
    public function __construct(private readonly CheckerRegistry $registry)
    {
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
     * committing to anything.
     */
    public function validateInput(Request $request): Response
    {
        $checker = $this->registry->get((string) $request->routeParam('slug', ''));
        $capabilities = $checker->getCapabilities();

        $input = $this->validate($request, [
            'input' => 'required|string|max:1000000',
        ]);

        $lines = \AccountCheck\Support\Str::lines((string) $input['input'], $capabilities->maxBatchSize * 2);

        $valid = [];
        $invalid = [];
        $seen = [];
        $duplicates = 0;

        foreach ($lines as $line) {
            $validation = $checker->validateInput($line);

            if (!$validation->isValid) {
                if (count($invalid) < 100) {
                    $invalid[] = ['input' => \AccountCheck\Support\Str::truncate($line, 120), 'reason' => $validation->reason];
                }
                continue;
            }

            if (isset($seen[$validation->normalized])) {
                $duplicates++;
                continue;
            }

            $seen[$validation->normalized] = true;
            $valid[] = $validation->normalized;
        }

        $acceptedCount = min(count($valid), $capabilities->maxBatchSize);

        return Response::success([
            'total_lines' => count($lines),
            'valid_count' => count($valid),
            'invalid_count' => count($lines) - count($valid) - $duplicates,
            'duplicate_count' => $duplicates,
            'accepted_count' => $acceptedCount,
            'over_limit' => count($valid) > $capabilities->maxBatchSize,
            'max_batch_size' => $capabilities->maxBatchSize,
            'credit_cost_each' => $capabilities->creditCost,
            'credits_required' => $acceptedCount * $capabilities->creditCost,
            // A sample, not the whole list: a 5,000-line paste does not need to
            // travel back to the browser to be confirmed.
            'sample' => array_slice($valid, 0, 10),
            'invalid_samples' => $invalid,
            'configured' => $capabilities->configured,
            'mode' => $capabilities->mode,
        ], 'Input reviewed');
    }
}
