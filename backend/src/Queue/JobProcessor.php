<?php

declare(strict_types=1);

namespace AccountCheck\Queue;

use AccountCheck\Checkers\AbstractChecker;
use AccountCheck\Checkers\CheckerInterface;
use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Checkers\CheckOutcome;
use AccountCheck\Checkers\CheckStatus;
use AccountCheck\Core\Config;
use AccountCheck\Core\Database;
use AccountCheck\Repositories\CheckerTypeRepository;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\ResultRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Support\Logger;
use Throwable;

/**
 * Runs one job to completion.
 *
 * This is the half of the queue that does the checking. It never runs inside a
 * web request: a 5,000-record job would blow past any sane request timeout, and
 * a browser that navigated away mid-request would abandon it. Instead the API
 * writes rows and this runs under `php worker.php`.
 *
 * The shape of the loop is deliberate:
 *
 *   - Work is taken in chunks, so a job's progress is visible while it runs
 *     rather than only at the end.
 *   - The job lease is refreshed after each chunk, so a long job is never
 *     mistaken for an abandoned one.
 *   - Ownership is re-checked before each chunk, so a cancellation takes effect
 *     within one chunk instead of at the end of the job.
 *   - Nothing is held in memory across chunks. Records are streamed out of the
 *     table and results are written back immediately, so peak memory is one
 *     chunk regardless of job size.
 */
final class JobProcessor
{
    /** @var callable(): bool */
    private $shouldStop;

    public function __construct(
        private readonly Database $database,
        private readonly JobRepository $jobs,
        private readonly ResultRepository $results,
        private readonly CheckerRegistry $registry,
        private readonly CheckerTypeRepository $checkerTypes,
        private readonly JobService $service,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
        $this->shouldStop = static fn (): bool => false;
    }

    /**
     * Lets the runtime interrupt a job between chunks on SIGTERM.
     *
     * @param callable(): bool $callback
     */
    public function onShouldStop(callable $callback): void
    {
        $this->shouldStop = $callback;
    }

    /**
     * Claims and runs the next queued job.
     *
     * @return array{job_id: int, checker: string, items: int, status: string}|null
     *         null when the queue is empty.
     */
    public function processNext(string $workerId): ?array
    {
        $job = $this->jobs->claimNextJob($workerId);

        if ($job === null) {
            return null;
        }

        $jobId = (int) $job['id'];
        $slug = (string) $job['checker_slug'];

        try {
            return $this->run($job, $workerId);
        } catch (Throwable $e) {
            // A job must never be left claimed. Whatever went wrong, the row
            // reaches a terminal state and the reservation is settled.
            $this->logger->error('Job processing failed', [
                'job_id' => $jobId,
                'checker' => $slug,
                'error' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            $this->finish($jobId, $workerId, 'FAILED', 'The job stopped unexpectedly. Any unused credits were returned.');

            return ['job_id' => $jobId, 'checker' => $slug, 'items' => 0, 'status' => 'FAILED'];
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array{job_id: int, checker: string, items: int, status: string}
     */
    private function run(array $job, string $workerId): array
    {
        $jobId = (int) $job['id'];
        $slug = (string) $job['checker_slug'];
        $checker = $this->registry->find($slug);

        if ($checker === null || !$checker->getCapabilities()->enabled) {
            $this->finish($jobId, $workerId, 'FAILED', 'This checker is no longer available. No credits were used.');

            return ['job_id' => $jobId, 'checker' => $slug, 'items' => 0, 'status' => 'FAILED'];
        }

        $maxAttempts = $this->service->maxItemAttempts();
        $batchSize = max(1, min(500, $this->config->int('checkers.queue.batch_size', 50)));
        $pacing = $this->pacingMicroseconds($slug, $checker);
        $processed = 0;

        while (true) {
            if (($this->shouldStop)()) {
                break;
            }

            // Re-read ownership rather than assuming it: the user may have
            // cancelled, or this worker's lease may have expired and been
            // recovered by another.
            if (!$this->jobs->stillOwns($jobId, $workerId)) {
                $this->logger->info('Job no longer owned by this worker', ['job_id' => $jobId]);

                return ['job_id' => $jobId, 'checker' => $slug, 'items' => $processed, 'status' => 'RELEASED'];
            }

            $items = $this->jobs->claimItems($jobId, $workerId, $batchSize, $maxAttempts);

            if ($items === []) {
                break;
            }

            $processed += $this->processChunk($job, $checker, $items, $maxAttempts, $pacing);
            $this->jobs->touchJobLease($jobId, $workerId);
        }

        // Records that used up their attempts get an explicit ERROR result, so
        // a job never finishes with rows that were silently dropped.
        $this->closeExhaustedItems($job, $maxAttempts);

        if (($this->shouldStop)() && $this->jobs->countOpenItems($jobId, $maxAttempts) > 0) {
            // Shutting down with work left: hand the job back rather than
            // finishing it short.
            $this->jobs->releaseJob($jobId, $workerId);

            return ['job_id' => $jobId, 'checker' => $slug, 'items' => $processed, 'status' => 'RELEASED'];
        }

        $this->finish($jobId, $workerId, 'COMPLETED', null, true);

        return ['job_id' => $jobId, 'checker' => $slug, 'items' => $processed, 'status' => 'COMPLETED'];
    }

    /**
     * Checks one chunk of records and writes their results.
     *
     * @param array<string, mixed>             $job
     * @param list<array<string, mixed>>       $items
     * @return int Records that reached a final state in this chunk.
     */
    private function processChunk(array $job, CheckerInterface $checker, array $items, int $maxAttempts, int $pacing): int
    {
        $jobId = (int) $job['id'];
        $userId = (int) $job['user_id'];
        $typeId = (int) $job['checker_type_id'];
        $costEach = (int) $job['credit_cost_each'];
        $slug = (string) $job['checker_slug'];

        $done = 0;

        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $attempts = (int) $item['attempts'];
            $startedAt = hrtime(true);

            try {
                $outcome = $checker->check((string) $item['normalized_input']);
            } catch (Throwable $e) {
                // An adapter bug must not take the whole job down, and the
                // driver message must not reach the results table.
                $this->logger->error('Checker threw while processing an item', [
                    'job_id' => $jobId,
                    'checker' => $slug,
                    'error' => $e->getMessage(),
                ]);

                $outcome = CheckOutcome::error($slug, 'The check could not be completed.', true);
            }

            // A transient failure with attempts left goes back to the queue and
            // is not counted as processed: the user sees no progress for a
            // record that has not actually been decided.
            if ($outcome->retryable && $attempts < $maxAttempts) {
                $this->jobs->releaseItemForRetry($itemId);
                continue;
            }

            $elapsedMs = $outcome->responseTimeMs ?? (int) ((hrtime(true) - $startedAt) / 1_000_000);

            // Billable and "successfully checked" are the same set by design:
            // the user is charged for records an authorized source answered
            // for, and for nothing else.
            $billable = $outcome->status->isBillable();

            // Closing the record, storing its result and moving the counters
            // happen together or not at all. That is what keeps a cancellation
            // exact: a cancel takes the record's row lock, so it either lands
            // entirely before this record (which is then skipped, uncounted and
            // unbilled) or entirely after it (in which case the counters it
            // settles against already include this record). Without the
            // transaction a record finished between the two could be charged
            // and not counted, or counted and not charged.
            $counted = $this->database->transaction(
                function () use ($item, $itemId, $jobId, $userId, $typeId, $slug, $outcome, $elapsedMs, $billable, $costEach): bool {
                    if (!$this->jobs->completeItem($itemId, $outcome->status === CheckStatus::Error)) {
                        // Cancelled out from under us; this record is not ours
                        // to record or to charge for.
                        return false;
                    }

                    $this->results->record(
                        $jobId,
                        $itemId,
                        $userId,
                        $typeId,
                        (string) $item['raw_input'],
                        (string) $item['normalized_input'],
                        $outcome->status->value,
                        $outcome->reason,
                        $outcome->source !== '' ? $outcome->source : $slug,
                        $elapsedMs,
                        $outcome->metadata,
                    );

                    return $this->jobs->addProgress(
                        $jobId,
                        1,
                        $billable ? 1 : 0,
                        $billable ? 0 : 1,
                        $billable ? $costEach : 0,
                    );
                },
            );

            if ($counted !== true) {
                continue;
            }

            $done++;

            if ($pacing > 0) {
                usleep($pacing);
            }
        }

        return $done;
    }

    /**
     * Writes an ERROR result for every record that ran out of attempts.
     *
     * @param array<string, mixed> $job
     */
    private function closeExhaustedItems(array $job, int $maxAttempts): void
    {
        $jobId = (int) $job['id'];
        $stranded = $this->jobs->exhaustedItems($jobId, $maxAttempts);

        if ($stranded === []) {
            return;
        }

        foreach ($stranded as $item) {
            $this->database->transaction(function () use ($item, $job, $jobId): void {
                if (!$this->jobs->completeItem((int) $item['id'], true)) {
                    return;
                }

                $this->results->record(
                    $jobId,
                    (int) $item['id'],
                    (int) $job['user_id'],
                    (int) $job['checker_type_id'],
                    (string) $item['raw_input'],
                    (string) $item['normalized_input'],
                    CheckStatus::Error->value,
                    'The check did not complete after several attempts.',
                    (string) $job['checker_slug'],
                );

                // Errors are not billable, so this moves failed_items only.
                $this->jobs->addProgress($jobId, 1, 0, 1, 0);
            });
        }
    }

    /**
     * Moves the job to its terminal state and settles the reservation.
     *
     * Settlement is tied to winning the transition, never to reaching the end
     * of this method, so a cancellation that beat us here has already settled
     * and we do nothing.
     */
    private function finish(int $jobId, string $workerId, string $status, ?string $error, bool $ownedOnly = false): void
    {
        $won = $ownedOnly
            ? $this->jobs->finalizeOwned($jobId, $workerId, $status, $error)
            : $this->jobs->finalize($jobId, $status, $error);

        if (!$won) {
            return;
        }

        $this->service->settle($jobId);
        $this->service->notifyFinished($jobId);
    }

    /**
     * Minimum gap between checks, from the checker's configured rate limit.
     *
     * Staying inside a provider's published limit is part of using it in an
     * authorized way, so the pacing is applied by the worker rather than left
     * to the provider to enforce. Mock mode makes no outbound calls and is not
     * paced.
     */
    private function pacingMicroseconds(string $slug, CheckerInterface $checker): int
    {
        if ($checker->getCapabilities()->mode !== AbstractChecker::MODE_PRODUCTION) {
            return 0;
        }

        $settings = $this->checkerTypes->findBySlug($slug);
        $perMinute = (int) ($settings['rate_limit_per_minute'] ?? 0);

        if ($perMinute <= 0) {
            return 0;
        }

        return (int) floor(60_000_000 / $perMinute);
    }
}
