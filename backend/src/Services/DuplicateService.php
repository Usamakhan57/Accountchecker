<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Support\Str;

/**
 * Finds duplicate entries in a pasted list.
 *
 * This costs nothing and starts no job: it is text processing, not
 * verification, so there is no authorized source to consult and nothing to
 * charge for. It runs inside the request because it is bounded work on a
 * bounded input — no network, no database.
 *
 * The interesting part is what counts as a duplicate. Three modes:
 *
 *   exact    - byte-for-byte after trimming.
 *   relaxed  - case and surrounding punctuation ignored.
 *   checker  - the checker's own normalisation, which is the one that
 *              actually matters. "First.Last+news@gmail.com" and
 *              "firstlast@gmail.com" are one Gmail address and would be
 *              charged once, so the tool that tells you what you are about to
 *              submit has to agree with the checker that will bill you.
 */
final class DuplicateService
{
    public const MODES = ['exact', 'relaxed', 'checker'];

    public function __construct(
        private readonly CheckerRegistry $registry,
        private readonly Config $config,
    ) {
    }

    public function maxLines(): int
    {
        return max(100, min(50_000, $this->config->int('checkers.tools.max_lines', 20_000)));
    }

    /**
     * @return array<string, mixed>
     */
    public function analyse(string $input, string $mode = 'exact', ?string $checkerSlug = null): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'exact';
        $normalizer = $this->normalizerFor($mode, $checkerSlug);

        $limit = $this->maxLines();
        $lines = Str::lines($input, $limit + 1);
        $truncated = count($lines) > $limit;

        if ($truncated) {
            $lines = array_slice($lines, 0, $limit);
        }

        /** @var array<string, array{value: string, count: int, lines: list<string>, positions: list<int>}> $groups */
        $groups = [];
        $unique = [];
        $unreadable = 0;
        $position = 0;

        foreach ($lines as $line) {
            $position++;
            $key = $normalizer($line);

            if ($key === null) {
                // Checker mode can reject a line outright. It is reported
                // rather than silently grouped with everything else that
                // failed, which would invent a duplicate that is not there.
                $unreadable++;
                continue;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = ['value' => $key, 'count' => 0, 'lines' => [], 'positions' => []];
                $unique[] = $key;
            }

            $groups[$key]['count']++;

            if (count($groups[$key]['lines']) < 5) {
                $groups[$key]['lines'][] = Str::truncate($line, 160);
                $groups[$key]['positions'][] = $position;
            }
        }

        $repeated = array_values(array_filter($groups, static fn (array $g): bool => $g['count'] > 1));

        // Worst offenders first: that is what someone cleaning a list wants to
        // see, not the order they happened to appear in.
        usort($repeated, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $duplicateLines = array_sum(array_map(static fn (array $g): int => $g['count'] - 1, $repeated));

        return [
            'mode' => $mode,
            'checker' => $checkerSlug,
            'total_lines' => count($lines),
            'unique_count' => count($unique),
            'duplicate_count' => $duplicateLines,
            'repeated_values' => count($repeated),
            'unreadable_count' => $unreadable,
            'truncated' => $truncated,
            'max_lines' => $limit,
            // The whole de-duplicated list, because copying it out is the
            // point of the tool.
            'unique' => $unique,
            // A sample of the groups, so a list with thousands of repeats does
            // not produce a response nobody can read.
            'groups' => array_map(
                static fn (array $g): array => [
                    'value' => $g['value'],
                    'count' => $g['count'],
                    'lines' => $g['lines'],
                    'positions' => $g['positions'],
                ],
                array_slice($repeated, 0, 200),
            ),
        ];
    }

    /**
     * @return callable(string): ?string
     */
    private function normalizerFor(string $mode, ?string $checkerSlug): callable
    {
        if ($mode === 'exact') {
            return static fn (string $line): ?string => trim($line) === '' ? null : trim($line);
        }

        if ($mode === 'relaxed') {
            return static function (string $line): ?string {
                $value = trim(mb_strtolower($line), " \t\n\r\0\x0B\"'<>,;");

                return $value === '' ? null : $value;
            };
        }

        if ($checkerSlug === null || $checkerSlug === '') {
            throw HttpException::badRequest(
                'Choose a checker to compare against.',
                'CHECKER_REQUIRED',
            );
        }

        $checker = $this->registry->get($checkerSlug);

        return static function (string $line) use ($checker): ?string {
            $validation = $checker->validateInput($line);

            return $validation->isValid ? $validation->normalized : null;
        };
    }
}
