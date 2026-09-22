<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Checkers\CheckerInterface;
use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Core\Config;
use AccountCheck\Core\Database;
use AccountCheck\Core\HttpException;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\CheckerTypeRepository;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\NotificationRepository;
use AccountCheck\Repositories\ResultRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Support\Logger;
use AccountCheck\Support\Paginator;
use AccountCheck\Support\Presenter;
use AccountCheck\Support\Str;
use Throwable;

/**
 * Batch job lifecycle.
 *
 * A submission is never processed inside the request that made it. The request
 * validates the list, reserves the credits and writes the rows; a separate CLI
 * worker does the checking. That is what lets a 5,000-record job exist at all:
 * the HTTP response comes back in milliseconds and the browser polls progress.
 *
 * Credits follow a reserve/settle model rather than charge-up-front:
 *
 *   reserve  at submission - the credits become unavailable but are still the
 *            user's, and no ledger entry is written.
 *   settle   at the single terminal transition - what was actually checked is
 *            charged, the remainder is released.
 *
 * So a cancelled or failed job costs the user only what it really did, and a
 * job can never be started that the wallet could not cover.
 */
final class JobService
{
    /** Retries per record before it is closed out as an error. */
    private const MAX_ITEM_ATTEMPTS = 3;

    public function __construct(
        private readonly Database $database,
        private readonly JobRepository $jobs,
        private readonly ResultRepository $results,
        private readonly WalletRepository $wallets,
        private readonly CheckerRegistry $registry,
        private readonly CheckerTypeRepository $checkerTypes,
        private readonly ActivityLogRepository $activity,
        private readonly NotificationRepository $notifications,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function maxItemAttempts(): int
    {
        return max(1, min(10, $this->config->int('checkers.queue.max_item_attempts', self::MAX_ITEM_ATTEMPTS)));
    }

    // -- Submission ---------------------------------------------------------

    /**
     * Turns a pasted or uploaded list into a queued job.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> The created job, as the API publishes it.
     */
    public function create(
        AuthenticatedUser $user,
        string $slug,
        string $rawInput,
        string $source = 'PASTE',
        array $options = [],
        ?string $ip = null,
    ): array {
        $checker = $this->registry->get($slug);
        $capabilities = $checker->getCapabilities();

        if (!$capabilities->enabled) {
            throw new HttpException(
                'That checker is currently switched off.',
                409,
                'CHECKER_DISABLED',
            );
        }

        $activeLimit = max(1, $this->config->int('checkers.queue.max_active_jobs_per_user', 3));

        if ($this->jobs->activeJobCount($user->id) >= $activeLimit) {
            throw new HttpException(
                sprintf('You already have %d jobs running. Wait for one to finish before starting another.', $activeLimit),
                429,
                'TOO_MANY_ACTIVE_JOBS',
            );
        }

        $prepared = $this->prepareInput($checker, $rawInput, $capabilities->maxBatchSize);

        if ($prepared['items'] === []) {
            throw new HttpException(
                'None of the lines you submitted could be checked.',
                422,
                'NO_VALID_INPUT',
            );
        }

        if ($prepared['over_limit']) {
            throw new HttpException(
                sprintf('This checker accepts up to %s records per job.', number_format($capabilities->maxBatchSize)),
                422,
                'BATCH_TOO_LARGE',
            );
        }

        $itemCount = count($prepared['items']);
        $creditsRequired = $itemCount * $capabilities->creditCost;

        // The reservation happens before any job row exists, so a wallet that
        // cannot cover the batch leaves nothing behind to clean up.
        if (!$this->wallets->reserve($user->id, $creditsRequired)) {
            $wallet = $this->wallets->find($user->id);

            throw new HttpException(
                'You do not have enough credits for this job.',
                402,
                'INSUFFICIENT_CREDITS',
                [
                    'credits_required' => [(string) $creditsRequired],
                    'credits_available' => [(string) ($wallet['available'] ?? 0)],
                ],
            );
        }

        $checkerTypeId = $this->checkerTypes->idForSlug($capabilities->slug);

        if ($checkerTypeId === null) {
            $this->wallets->release($user->id, $creditsRequired);

            throw HttpException::notFound('That checker does not exist.', 'CHECKER_NOT_FOUND');
        }

        try {
            $jobId = $this->database->transaction(function () use (
                $user,
                $checkerTypeId,
                $itemCount,
                $creditsRequired,
                $capabilities,
                $source,
                $options,
                $prepared,
            ): int {
                $jobId = $this->jobs->create(
                    $user->id,
                    $checkerTypeId,
                    Str::uuid4(),
                    $itemCount,
                    $creditsRequired,
                    $capabilities->creditCost,
                    $source,
                    $options,
                );

                $this->jobs->insertItems(
                    $jobId,
                    $user->id,
                    $prepared['items'],
                    $this->config->int('checkers.queue.insert_chunk_size', 500),
                );

                return $jobId;
            });
        } catch (Throwable $e) {
            // Nothing was queued, so the hold must not survive the failure.
            $this->wallets->release($user->id, $creditsRequired);
            $this->logger->error('Job creation failed', [
                'user_id' => $user->id,
                'checker' => $capabilities->slug,
                'error' => $e->getMessage(),
            ]);

            throw new HttpException('The job could not be created. Please try again.', 500, 'JOB_CREATE_FAILED');
        }

        // Published only once every item is in the table, so a worker cannot
        // pick up a job that is still being filled.
        $this->jobs->markQueued($jobId);

        $this->activity->record(
            $user->id,
            'job.created',
            'checker_job',
            $jobId,
            [
                'checker' => $capabilities->slug,
                'items' => $itemCount,
                'credits_reserved' => $creditsRequired,
                'source' => $source,
            ],
            $ip,
        );

        $job = $this->jobs->find($jobId);

        return [
            'job' => $job === null ? [] : Presenter::job($job),
            'skipped' => [
                'invalid' => $prepared['invalid_count'],
                'duplicate' => $prepared['duplicate_count'],
            ],
            'mode' => $capabilities->mode,
            'configured' => $capabilities->configured,
        ];
    }

    /**
     * Validates, normalizes and de-duplicates a raw list.
     *
     * Malformed lines are reported rather than queued, so nobody is charged for
     * a record that could never have been checked.
     *
     * @return array{
     *     items: list<array{raw: string, normalized: string}>,
     *     total_lines: int,
     *     invalid_count: int,
     *     duplicate_count: int,
     *     invalid_samples: list<array{input: string, reason: string|null}>,
     *     over_limit: bool
     * }
     */
    public function prepareInput(CheckerInterface $checker, string $rawInput, int $maxBatchSize): array
    {
        // Read a little past the limit so an over-sized paste is reported as
        // over-sized instead of being silently truncated to the cap.
        $lines = Str::lines($rawInput, $maxBatchSize + 1000);

        $items = [];
        $invalidSamples = [];
        $invalidCount = 0;
        $duplicateCount = 0;
        $seen = [];

        foreach ($lines as $line) {
            $validation = $checker->validateInput($line);

            if (!$validation->isValid) {
                $invalidCount++;

                if (count($invalidSamples) < 50) {
                    $invalidSamples[] = [
                        'input' => Str::truncate($line, 120),
                        'reason' => $validation->reason,
                    ];
                }

                continue;
            }

            if (isset($seen[$validation->normalized])) {
                $duplicateCount++;
                continue;
            }

            $seen[$validation->normalized] = true;
            $items[] = ['raw' => $line, 'normalized' => $validation->normalized];
        }

        return [
            'items' => $items,
            'total_lines' => count($lines),
            'invalid_count' => $invalidCount,
            'duplicate_count' => $duplicateCount,
            'invalid_samples' => $invalidSamples,
            'over_limit' => count($items) > $maxBatchSize,
        ];
    }

    // -- Reads --------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function paginate(int $userId, Paginator $paginator, array $filters = []): array
    {
        $page = $this->jobs->paginateForUser($userId, $paginator, $filters);

        return $paginator->envelope(Presenter::jobs($page['items']), $page['total']);
    }

    /**
     * The job row, or a 404 if it is not this user's.
     *
     * Public so the results and export endpoints can resolve a reference to an
     * owned job without repeating the ownership check.
     *
     * @return array<string, mixed>
     */
    public function findOwned(int $userId, string $reference): array
    {
        return $this->requireJob($userId, $reference);
    }

    /** @return array<string, mixed> */
    public function show(int $userId, string $reference): array
    {
        $job = $this->requireJob($userId, $reference);

        return Presenter::job($job) + [
            'breakdown' => $this->results->statusBreakdownForJob((int) $job['id'], $userId),
            'options' => $this->decodeOptions($job['options'] ?? null),
        ];
    }

    /**
     * The polling payload.
     *
     * Deliberately small: the workspace hits this every couple of seconds while
     * a job runs, and it should stay one indexed row.
     *
     * @return array<string, mixed>
     */
    public function progress(int $userId, string $reference): array
    {
        $row = $this->jobs->progressForUser($userId, $reference);

        if ($row === null) {
            throw HttpException::notFound('That job does not exist.', 'JOB_NOT_FOUND');
        }

        $total = (int) $row['total_items'];
        $processed = (int) $row['processed_items'];
        $status = (string) $row['status'];

        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'status' => $status,
            'total_items' => $total,
            'processed_items' => $processed,
            'successful_items' => (int) $row['successful_items'],
            'failed_items' => (int) $row['failed_items'],
            'progress_percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
            'credits_reserved' => (int) $row['credits_reserved'],
            'credits_spent' => (int) $row['credits_spent'],
            'error_message' => $row['error_message'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            // Lets the client stop polling without knowing the status vocabulary.
            'is_finished' => in_array($status, ['COMPLETED', 'FAILED', 'CANCELLED'], true),
        ];
    }

    // -- Cancellation and completion ----------------------------------------

    /**
     * Cancels a job on the user's behalf.
     *
     * The terminal transition is the gate (see JobRepository::finalize), so a
     * cancel racing a worker's completion cannot double-settle: one of them
     * gets the row, the other gets a conflict.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $userId, string $reference, ?string $ip = null): array
    {
        $job = $this->requireJob($userId, $reference);
        $jobId = (int) $job['id'];

        if (!in_array((string) $job['status'], JobRepository::CANCELLABLE_STATES, true)) {
            throw HttpException::conflict('That job has already finished.', 'JOB_NOT_CANCELLABLE');
        }

        // Stop the remaining work first: anything the worker has not yet
        // claimed will never be claimed after this.
        $this->jobs->skipOpenItems($jobId);

        if (!$this->jobs->finalize($jobId, 'CANCELLED')) {
            throw HttpException::conflict('That job has already finished.', 'JOB_NOT_CANCELLABLE');
        }

        $this->settle($jobId);

        $this->activity->record($userId, 'job.cancelled', 'checker_job', $jobId, [], $ip);

        $fresh = $this->jobs->find($jobId);

        return $fresh === null ? [] : Presenter::job($fresh);
    }

    /**
     * Settles a finished job's credit reservation.
     *
     * Called exactly once per job, by whichever caller won the terminal
     * transition. Reads the spent total inside the settlement so it reflects
     * everything the worker recorded.
     */
    public function settle(int $jobId): void
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            return;
        }

        $reserved = (int) $job['credits_reserved'];
        $spent = (int) $job['credits_spent'];

        if ($reserved <= 0) {
            return;
        }

        $this->wallets->settle(
            (int) $job['user_id'],
            $reserved,
            $spent,
            sprintf('%s job #%d (%d checked)', (string) $job['checker_label'], $jobId, $spent > 0 ? (int) $job['successful_items'] : 0),
            'job:' . (string) $job['uuid'],
        );

        try {
            $this->jobs->recordHistory($job);
        } catch (Throwable $e) {
            // History is a convenience; failing to write it must not undo a
            // settlement that has already happened.
            $this->logger->warning('Job history could not be recorded', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Notifies the owner that a job reached a terminal state. */
    public function notifyFinished(int $jobId): void
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            return;
        }

        $status = (string) $job['status'];

        $title = match ($status) {
            'COMPLETED' => sprintf('%s job finished', (string) $job['checker_label']),
            'FAILED' => sprintf('%s job failed', (string) $job['checker_label']),
            default => sprintf('%s job cancelled', (string) $job['checker_label']),
        };

        $body = $status === 'COMPLETED'
            ? sprintf(
                '%s of %s records checked, %s credits used.',
                number_format((int) $job['processed_items']),
                number_format((int) $job['total_items']),
                number_format((int) $job['credits_spent']),
            )
            : (string) ($job['error_message'] ?? 'No further records were processed.');

        try {
            $this->notifications->create(
                (int) $job['user_id'],
                'job.' . strtolower($status),
                $title,
                $body,
                '/jobs/' . (string) $job['uuid'],
            );
        } catch (Throwable $e) {
            $this->logger->warning('Job notification could not be created', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -- Internals ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function requireJob(int $userId, string $reference): array
    {
        $job = $this->jobs->findForUser($userId, $reference);

        if ($job === null) {
            // Same response whether the job belongs to someone else or does not
            // exist, so job ids cannot be probed.
            throw HttpException::notFound('That job does not exist.', 'JOB_NOT_FOUND');
        }

        return $job;
    }

    /** @return array<string, mixed> */
    private function decodeOptions(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
