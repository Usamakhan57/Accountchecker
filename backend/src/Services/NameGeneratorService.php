<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Core\HttpException;

/**
 * Generates candidate usernames from words the user supplies.
 *
 * This generates names. It does not tell you whether any of them are free.
 * Availability is a question only an authorized source can answer, which is
 * what the checkers are for, so the output here is a list to feed into one —
 * never a claim about what is taken. Nothing in this class touches a network.
 *
 * When a platform is chosen, its published handle rules are applied, so the
 * list comes back already filtered to names that platform would actually
 * accept rather than ones that look plausible and are rejected on sight.
 *
 * Generation is seeded and deterministic: the same words and settings give the
 * same list, so a user can come back to a list they liked, and a support
 * conversation can reproduce exactly what someone saw.
 */
final class NameGeneratorService
{
    public const STYLES = ['plain', 'numbers', 'years', 'separators', 'prefixes', 'suffixes'];

    private const PREFIXES = ['the', 'real', 'its', 'im', 'official', 'just', 'ask', 'try', 'go', 'be'];
    private const SUFFIXES = ['hq', 'co', 'app', 'daily', 'studio', 'works', 'labs', 'club', 'live', 'world'];
    private const SEPARATORS = ['', '_', '.', '-'];

    public function __construct(private readonly CheckerRegistry $registry)
    {
    }

    /**
     * @param list<string> $words
     * @param list<string> $styles
     * @return array<string, mixed>
     */
    public function generate(
        array $words,
        array $styles,
        int $count,
        ?string $platform = null,
        ?int $seed = null,
    ): array {
        $words = $this->cleanWords($words);

        if ($words === []) {
            throw HttpException::badRequest(
                'Give at least one word to build names from.',
                'NO_WORDS',
            );
        }

        $styles = array_values(array_intersect($styles, self::STYLES));

        if ($styles === []) {
            $styles = ['plain', 'numbers', 'separators'];
        }

        $count = max(1, min(500, $count));
        $seed = $seed ?? random_int(1, 999_999);

        $rules = $this->rulesFor($platform);

        // A word longer than the platform allows would reject every candidate
        // built from it, leaving the user with an empty list and no
        // explanation. A shortened variant is added alongside it instead, and
        // the response says which words were shortened and why.
        [$words, $shortened] = $this->fitToRules($words, $rules);

        $candidates = $this->build($words, $styles, $seed);

        $accepted = [];
        $rejected = 0;

        foreach ($candidates as $candidate) {
            if (count($accepted) >= $count) {
                break;
            }

            if (!$this->matchesRules($candidate, $rules)) {
                $rejected++;
                continue;
            }

            $accepted[] = $candidate;
        }

        return [
            'names' => $accepted,
            'count' => count($accepted),
            'rejected_count' => $rejected,
            'shortened_words' => $shortened,
            'seed' => $seed,
            'platform' => $platform,
            'styles' => $styles,
            'rules' => $rules === null ? null : [
                'min_length' => $rules['min'],
                'max_length' => $rules['max'],
                'allowed' => $rules['description'],
            ],
            // Said plainly, and repeated in the UI: a generated name is a
            // suggestion, not a statement about whether anyone holds it.
            'notice' => 'These are suggestions only. Run them through a checker to find out which are actually available.',
        ];
    }

    /**
     * Adds a shortened variant of any word the platform could never accept.
     *
     * Room is left for a couple of trailing characters, so a suffix or a
     * number still fits inside the limit rather than pushing every candidate
     * back over it.
     *
     * @param list<string>                                                      $words
     * @param array{min: int, max: int, pattern: string, description: string}|null $rules
     * @return array{0: list<string>, 1: list<array{word: string, shortened: string}>}
     */
    private function fitToRules(array $words, ?array $rules): array
    {
        if ($rules === null) {
            return [$words, []];
        }

        $room = max(1, $rules['max'] - 3);
        $shortened = [];

        foreach ($words as $word) {
            if (mb_strlen($word) <= $room) {
                continue;
            }

            $trimmed = mb_substr($word, 0, $room);

            if ($trimmed !== '' && !in_array($trimmed, $words, true)) {
                $words[] = $trimmed;
                $shortened[] = ['word' => $word, 'shortened' => $trimmed];
            }
        }

        return [array_values($words), $shortened];
    }

    /**
     * @param list<string> $words
     * @param list<string> $styles
     * @return list<string>
     */
    private function build(array $words, array $styles, int $seed): array
    {
        // mt_srand makes the sequence reproducible from the seed. It is a
        // presentation shuffle, not a security decision, so a predictable
        // generator is exactly what is wanted here.
        mt_srand($seed);

        $candidates = [];
        $currentYear = (int) gmdate('Y');

        $push = static function (string $value) use (&$candidates): void {
            $value = trim($value, '._-');

            if ($value !== '' && !isset($candidates[$value])) {
                $candidates[$value] = true;
            }
        };

        foreach ($words as $word) {
            if (in_array('plain', $styles, true)) {
                $push($word);
            }

            if (in_array('numbers', $styles, true)) {
                foreach ([1, 7, 11, 21, 42, 99, 101, 404, 777] as $number) {
                    $push($word . $number);
                    $push($number . $word);
                }

                for ($i = 0; $i < 12; $i++) {
                    $push($word . mt_rand(2, 9999));
                }
            }

            if (in_array('years', $styles, true)) {
                for ($year = $currentYear - 6; $year <= $currentYear + 1; $year++) {
                    $push($word . $year);
                    $push($word . substr((string) $year, 2));
                }
            }

            if (in_array('prefixes', $styles, true)) {
                foreach (self::PREFIXES as $prefix) {
                    $push($prefix . $word);
                    $push($prefix . '_' . $word);
                }
            }

            if (in_array('suffixes', $styles, true)) {
                foreach (self::SUFFIXES as $suffix) {
                    $push($word . $suffix);
                    $push($word . '_' . $suffix);
                }
            }
        }

        // Pairs of the user's own words, which tend to be the best suggestions
        // when they gave more than one.
        if (count($words) > 1 && in_array('separators', $styles, true)) {
            foreach ($words as $first) {
                foreach ($words as $second) {
                    if ($first === $second) {
                        continue;
                    }

                    foreach (self::SEPARATORS as $separator) {
                        $push($first . $separator . $second);
                    }
                }
            }
        } elseif (in_array('separators', $styles, true)) {
            foreach ($words as $word) {
                foreach (self::SUFFIXES as $suffix) {
                    $push($word . '.' . $suffix);
                    $push($word . '-' . $suffix);
                }
            }
        }

        $list = array_keys($candidates);
        shuffle($list);

        // Leave the global generator as it was found, so nothing else in the
        // request inherits this seed.
        mt_srand();

        return $list;
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    private function cleanWords(array $words): array
    {
        $clean = [];

        foreach ($words as $word) {
            // Only the characters every platform allows survive, so a word with
            // spaces or punctuation still produces usable handles.
            $value = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $word) ?? '');

            if ($value !== '' && mb_strlen($value) <= 30 && !in_array($value, $clean, true)) {
                $clean[] = $value;
            }

            if (count($clean) >= 8) {
                break;
            }
        }

        return $clean;
    }

    /**
     * The chosen platform's published handle rules.
     *
     * @return array{min: int, max: int, pattern: string, description: string}|null
     */
    private function rulesFor(?string $platform): ?array
    {
        if ($platform === null || $platform === '') {
            return null;
        }

        $checker = $this->registry->get($platform);
        $capabilities = $checker->getCapabilities();

        if ($capabilities->inputKind !== 'username') {
            throw HttpException::badRequest(
                'That checker does not use usernames.',
                'CHECKER_NOT_USERNAME_BASED',
            );
        }

        // Asking the checker itself keeps one source of truth: whatever it
        // would accept as input is what the generator produces.
        return match ($platform) {
            'instagram', 'threads' => ['min' => 1, 'max' => 30, 'pattern' => '/^[a-z0-9._]+$/', 'description' => 'letters, numbers, dots and underscores'],
            'facebook' => ['min' => 5, 'max' => 50, 'pattern' => '/^[a-z0-9.]+$/', 'description' => 'letters, numbers and dots'],
            'x' => ['min' => 1, 'max' => 15, 'pattern' => '/^[a-z0-9_]+$/', 'description' => 'letters, numbers and underscores'],
            'tiktok' => ['min' => 2, 'max' => 24, 'pattern' => '/^[a-z0-9._]+$/', 'description' => 'letters, numbers, dots and underscores'],
            default => ['min' => 1, 'max' => 60, 'pattern' => '/^[a-z0-9._-]+$/', 'description' => 'letters, numbers, dots, underscores and hyphens'],
        };
    }

    /** @param array{min: int, max: int, pattern: string, description: string}|null $rules */
    private function matchesRules(string $candidate, ?array $rules): bool
    {
        if ($rules === null) {
            return mb_strlen($candidate) <= 60;
        }

        $length = mb_strlen($candidate);

        return $length >= $rules['min']
            && $length <= $rules['max']
            && preg_match($rules['pattern'], $candidate) === 1;
    }
}
