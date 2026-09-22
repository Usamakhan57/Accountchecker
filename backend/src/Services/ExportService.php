<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Response;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\ExportRepository;
use AccountCheck\Repositories\ResultRepository;
use AccountCheck\Support\Csv;
use AccountCheck\Support\Logger;
use AccountCheck\Support\Paginator;
use AccountCheck\Support\Str;
use Throwable;

/**
 * Result exports.
 *
 * Rows are streamed from the database straight to a file on disk, a chunk at a
 * time, so an export's memory cost does not grow with its size. Nothing is
 * assembled in a string and nothing is held in the browser.
 *
 * The filesystem is kept entirely out of the client's reach:
 *
 *   - A file is named after its uuid, never after anything the user typed. The
 *     name they see on download is a separate, sanitised display name.
 *   - Downloads are addressed by uuid and resolved through the database, which
 *     carries the owner. A uuid belonging to someone else reads as missing.
 *   - The resolved path is checked to be inside the export directory before it
 *     is opened, so a stored value can never point somewhere else.
 *
 * Exports expire. The worker prunes them on its idle passes, so a stale file
 * with somebody's list of addresses in it does not sit on disk indefinitely.
 */
final class ExportService
{
    /** CSV columns, in order. Also the keys read from each result row. */
    private const COLUMNS = [
        'input' => 'Input',
        'normalized_input' => 'Checked value',
        'checker' => 'Checker',
        'status' => 'Status',
        'reason' => 'Detail',
        'source' => 'Source',
        'response_time_ms' => 'Response time (ms)',
        'checked_at' => 'Checked at (UTC)',
    ];

    public function __construct(
        private readonly ExportRepository $exports,
        private readonly ResultRepository $results,
        private readonly ActivityLogRepository $activity,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Writes an export file and records it.
     *
     * @param array<string, mixed> $filters Same vocabulary as the results list,
     *                                      so "export what I am looking at"
     *                                      means exactly that.
     * @return array<string, mixed>
     */
    public function create(AuthenticatedUser $user, string $format, array $filters = [], ?string $ip = null): array
    {
        $format = strtolower(trim($format));

        if (!in_array($format, $this->formats(), true)) {
            throw HttpException::badRequest('Choose either CSV or TXT.', 'EXPORT_FORMAT_NOT_SUPPORTED');
        }

        // Writing files is cheap but not free, and an export carries real data
        // off the platform; a per-hour cap keeps both in check.
        $hourlyLimit = max(1, $this->config->int('checkers.exports.max_per_hour', 20));

        if ($this->exports->countSince($user->id, 3600) >= $hourlyLimit) {
            throw HttpException::tooManyRequests(
                'You have made a lot of exports in the last hour. Try again shortly.',
                'EXPORT_RATE_LIMITED',
            );
        }

        $rowCount = $this->results->countForUser($user->id, $filters);

        if ($rowCount === 0) {
            throw HttpException::conflict('There are no results matching those filters.', 'EXPORT_EMPTY');
        }

        $uuid = Str::uuid4();
        $path = $this->pathFor($uuid, $format);

        $this->ensureDirectory();

        try {
            $written = $format === 'csv'
                ? $this->writeCsv($path, $user->id, $filters)
                : $this->writeText($path, $user->id, $filters);
        } catch (Throwable $e) {
            @unlink($path);
            $this->logger->error('Export failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            throw new HttpException('The export could not be created.', 500, 'EXPORT_FAILED');
        }

        $displayName = sprintf(
            'accountcheck-%s-%s.%s',
            Str::slug((string) ($filters['checker'] ?? 'results')) ?: 'results',
            gmdate('Ymd-His'),
            $format,
        );

        $exportId = $this->exports->create(
            $user->id,
            $uuid,
            isset($filters['job_id']) && is_numeric($filters['job_id']) ? (int) $filters['job_id'] : null,
            $format,
            $displayName,
            $written,
            (int) (@filesize($path) ?: 0),
            $this->retentionHours(),
        );

        $this->activity->record($user->id, 'export.created', 'export', $exportId, [
            'format' => $format,
            'rows' => $written,
        ], $ip);

        $record = $this->exports->findForUser($user->id, $uuid);

        return $record === null ? [] : $this->present($record);
    }

    /**
     * Serves an export the user owns.
     *
     * The file is read from a path this service built from the uuid, not from
     * anything stored or submitted, so there is no path for a request to
     * traverse.
     */
    public function download(AuthenticatedUser $user, string $uuid): Response
    {
        $export = $this->exports->findForUser($user->id, $this->normalizeUuid($uuid));

        if ($export === null || (string) $export['status'] === 'DELETED') {
            throw HttpException::notFound('That export is no longer available.', 'EXPORT_NOT_FOUND');
        }

        if ((string) $export['status'] === 'EXPIRED' || strtotime((string) $export['expires_at']) < time()) {
            throw HttpException::conflict(
                'That export has expired. Generate a new one.',
                'EXPORT_EXPIRED',
            );
        }

        $path = $this->pathFor((string) $export['uuid'], (string) $export['format']);

        if (!$this->isInsideExportDirectory($path) || !is_file($path)) {
            $this->exports->markStatus((int) $export['id'], 'EXPIRED');

            throw HttpException::notFound('That export is no longer available.', 'EXPORT_NOT_FOUND');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new HttpException('The export could not be read.', 500, 'EXPORT_UNREADABLE');
        }

        return Response::raw(
            $contents,
            (string) $export['format'] === 'csv' ? 'text/csv; charset=utf-8' : 'text/plain; charset=utf-8',
            (string) $export['filename'],
        );
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>} */
    public function paginate(AuthenticatedUser $user, Paginator $paginator): array
    {
        $page = $this->exports->paginateForUser($user->id, $paginator);

        return $paginator->envelope(array_map($this->present(...), $page['items']), $page['total']);
    }

    public function delete(AuthenticatedUser $user, string $uuid): void
    {
        $export = $this->exports->findForUser($user->id, $this->normalizeUuid($uuid));

        if ($export === null) {
            throw HttpException::notFound('That export is no longer available.', 'EXPORT_NOT_FOUND');
        }

        $this->removeFile((string) $export['uuid'], (string) $export['format']);
        $this->exports->markStatus((int) $export['id'], 'DELETED');
    }

    /**
     * Deletes expired export files.
     *
     * Called from the worker's idle pass rather than from a request, so nobody
     * waits on it and it happens whether or not anyone is signed in.
     *
     * @return int Number of exports pruned.
     */
    public function pruneExpired(): int
    {
        $pruned = 0;

        foreach ($this->exports->expired() as $export) {
            $this->removeFile((string) $export['uuid'], (string) $export['format']);
            $this->exports->markStatus((int) $export['id'], 'EXPIRED');
            $pruned++;
        }

        return $pruned;
    }

    // -- Writers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return int Rows written.
     */
    private function writeCsv(string $path, int $userId, array $filters): int
    {
        $handle = $this->open($path);
        $written = 0;

        try {
            // A BOM, so Excel opens UTF-8 addresses correctly instead of
            // mangling any non-ASCII character in them.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_values(self::COLUMNS), ',', '"', '\\');

            foreach ($this->results->stream($userId, $filters) as $row) {
                $line = [];

                foreach (array_keys(self::COLUMNS) as $column) {
                    // Csv::guard neutralises spreadsheet formula injection: a
                    // handle such as "=cmd|..." is data, not a formula.
                    $line[] = Csv::guard((string) ($this->cell($row, $column) ?? ''));
                }

                fputcsv($handle, $line, ',', '"', '\\');
                $written++;
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * Plain-text export: the checked values, one per line.
     *
     * This is the format people paste into another tool, so it carries the
     * values and nothing else.
     *
     * @param array<string, mixed> $filters
     * @return int Rows written.
     */
    private function writeText(string $path, int $userId, array $filters): int
    {
        $handle = $this->open($path);
        $written = 0;

        try {
            foreach ($this->results->stream($userId, $filters) as $row) {
                fwrite($handle, (string) $row['normalized_input'] . PHP_EOL);
                $written++;
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * @param resource|false $handle
     * @return resource
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new HttpException('The export could not be created.', 500, 'EXPORT_FAILED');
        }

        return $handle;
    }

    /** @param array<string, mixed> $row */
    private function cell(array $row, string $column): ?string
    {
        return match ($column) {
            'input' => (string) $row['raw_input'],
            'checker' => (string) ($row['checker_label'] ?? $row['checker_slug'] ?? ''),
            'response_time_ms' => isset($row['response_time_ms']) ? (string) $row['response_time_ms'] : '',
            default => isset($row[$column]) ? (string) $row[$column] : '',
        };
    }

    // -- Filesystem ---------------------------------------------------------

    private function directory(): string
    {
        return rtrim($this->config->string('app.storage.exports', dirname(__DIR__, 2) . '/storage/exports'), '/');
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new HttpException('The export could not be created.', 500, 'EXPORT_FAILED');
        }
    }

    /** The on-disk name is the uuid, so nothing a user typed becomes a path. */
    private function pathFor(string $uuid, string $format): string
    {
        return $this->directory() . '/' . $this->normalizeUuid($uuid) . '.' . ($format === 'txt' ? 'txt' : 'csv');
    }

    /** A last check that a path really is under the export directory. */
    private function isInsideExportDirectory(string $path): bool
    {
        $directory = realpath($this->directory());
        $resolved = realpath($path);

        return $directory !== false && $resolved !== false && str_starts_with($resolved, $directory . DIRECTORY_SEPARATOR);
    }

    private function removeFile(string $uuid, string $format): void
    {
        $path = $this->pathFor($uuid, $format);

        if ($this->isInsideExportDirectory($path) && is_file($path)) {
            @unlink($path);
        }
    }

    /** Rejects anything that is not a uuid before it is used to build a path. */
    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw HttpException::notFound('That export is no longer available.', 'EXPORT_NOT_FOUND');
        }

        return $uuid;
    }

    // -- Settings and shaping -----------------------------------------------

    /** @return list<string> */
    private function formats(): array
    {
        $formats = $this->config->array('checkers.exports.formats', ['csv', 'txt']);

        return array_values(array_filter(
            array_map(static fn (mixed $f): string => strtolower((string) $f), $formats),
            static fn (string $f): bool => in_array($f, ['csv', 'txt'], true),
        ));
    }

    private function retentionHours(): int
    {
        return max(1, $this->config->int('checkers.exports.retention_hours', 48));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $expiresAt = (string) $row['expires_at'];
        $expired = (string) $row['status'] !== 'READY' || strtotime($expiresAt) < time();

        return [
            'uuid' => (string) $row['uuid'],
            'job_id' => isset($row['job_id']) ? (int) $row['job_id'] : null,
            'format' => (string) $row['format'],
            'filename' => (string) $row['filename'],
            'row_count' => (int) $row['row_count'],
            'size_bytes' => (int) $row['size_bytes'],
            'status' => $expired && (string) $row['status'] === 'READY' ? 'EXPIRED' : (string) $row['status'],
            'expires_at' => $expiresAt,
            'created_at' => (string) $row['created_at'],
            // The client builds its link from the uuid; no path ever leaves
            // the server.
            'download_path' => $expired ? null : '/api/exports/' . (string) $row['uuid'] . '/download',
        ];
    }
}
