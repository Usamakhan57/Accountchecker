<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Services\DuplicateService;
use AccountCheck\Services\NameGeneratorService;
use AccountCheck\Services\RateLimiter;

/**
 * The free tools: duplicate finder and name generator.
 *
 * Neither costs credits, because neither verifies anything — they are text
 * processing over what the user already has. That also means neither starts a
 * job: the work is bounded, local and fast enough to answer in the request.
 *
 * Free does not mean unmetered. Both carry a rate limit so one account cannot
 * spend the server's CPU on everyone else's behalf.
 */
final class ToolController extends Controller
{
    public function __construct(
        private readonly DuplicateService $duplicates,
        private readonly NameGeneratorService $names,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** POST /api/tools/duplicates */
    public function duplicates(Request $request): Response
    {
        $user = $this->user($request);
        // Free, but not unmetered: the work is real CPU on a list that can run
        // to tens of thousands of lines.
        $this->limiter->enforce('tools', (string) $user->id);

        $input = $this->validate($request, [
            'input' => 'required|string|max:4000000',
            'mode' => 'string|in:exact,relaxed,checker',
        ]);

        return Response::success(
            $this->duplicates->analyse(
                (string) $input['input'],
                isset($input['mode']) ? (string) $input['mode'] : 'exact',
                is_string($request->input('checker')) ? $request->input('checker') : null,
            ),
            'List analysed',
        );
    }

    /** POST /api/tools/name-generator */
    public function names(Request $request): Response
    {
        $user = $this->user($request);
        $this->limiter->enforce('tools', (string) $user->id);

        $input = $this->validate($request, [
            'words' => 'required|string|max:400',
            'count' => 'integer',
        ]);

        $styles = $request->input('styles');

        return Response::success(
            $this->names->generate(
                preg_split('/[\s,;\n]+/', (string) $input['words'], -1, PREG_SPLIT_NO_EMPTY) ?: [],
                is_array($styles) ? array_map(static fn (mixed $s): string => (string) $s, $styles) : [],
                isset($input['count']) ? (int) $input['count'] : 60,
                is_string($request->input('platform')) && $request->input('platform') !== ''
                    ? (string) $request->input('platform')
                    : null,
                is_numeric($request->input('seed')) ? (int) $request->input('seed') : null,
            ),
            'Names generated',
        );
    }
}
