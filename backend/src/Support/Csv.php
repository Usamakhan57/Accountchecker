<?php

declare(strict_types=1);

namespace AccountCheck\Support;

/**
 * CSV generation and parsing for exports and uploads.
 */
final class Csv
{
    /**
     * @param list<string> $headers
     * @param iterable<array<string, mixed>> $rows
     */
    public static function build(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_map(self::guard(...), $headers), ',', '"', '\\');

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $line[] = self::guard(is_scalar($value) || $value === null ? (string) $value : '');
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }

    /**
     * Parses a CSV/TXT upload into rows of trimmed values.
     *
     * @return list<list<string>>
     */
    public static function parse(string $contents, int $maxRows = 5000): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }

        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $clean = [];
            foreach ($row as $cell) {
                $clean[] = trim((string) $cell);
            }

            if (implode('', $clean) === '') {
                continue;
            }

            $rows[] = $clean;

            if (count($rows) >= $maxRows) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Neutralises spreadsheet formula injection.
     *
     * A cell starting with =, +, - or @ is executed as a formula by Excel and
     * Sheets, so a leading apostrophe is prepended to force a literal string.
     */
    public static function guard(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Control characters can also trigger formula evaluation in some
        // spreadsheet apps, so they are stripped first.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
