<?php

declare(strict_types=1);

namespace AccountCheck\Queue;

use AccountCheck\Core\Config;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Services\ExportService;
use AccountCheck\Support\Logger;
use Throwable;

/**
 * The long-running worker loop.
 *
 * One process, one job at a time, claiming from the database. Several may run
 * side by side on the same machine or on different ones: correctness comes from
 * the atomic claims in JobRepository, not from there being a single worker.
 *
 * Three properties make this safe to run under systemd or cron:
 *
 *   - It stops cleanly. SIGTERM and SIGINT set a flag; the current chunk
 *     finishes, the job is handed back to the queue, and the heartbeat row is
 *     removed. Nothing is left claimed.
 *   - It stops on its own. A max runtime bounds how long one process lives, so
 *     a slow leak in any dependency cannot accumulate indefinitely; the service
 *     manager starts a fresh one.
 *   - It recovers other workers' wreckage. Every idle pass sweeps leases that
 *     have outlived their TTL back into the queue, so a worker killed with
 *     -9 costs one TTL rather than a stuck job.
 */
final class WorkerRuntime
{
    private bool $stopRequested = false;
    private int $jobsProcessed = 0;
    private int $itemsProcessed = 0;
    private int $startedAt = 0;
    private string $workerId = '';

    public function __construct(
        private readonly JobProcessor $processor,
        private readonly JobRepository $jobs,
        private readonly ExportService $exports,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{
     *     once?: bool, max_jobs?: int, sleep?: int, worker_id?: string,
     *     max_runtime?: int, quiet?: bool
     * } $options
     * @return int Process exit code.
     */
    public function run(array $options = []): int
    {
        $this->startedAt = time();
        $this->workerId = $this->resolveWorkerId($options['worker_id'] ?? null);
        $this->processor->onShouldStop(fn (): bool => $this->stopRequested || $this->runtimeExhausted($options));

        $once = (bool) ($options['once'] ?? false);
        $quiet = (bool) ($options['quiet'] ?? false);
        $maxJobs = max(0, (int) ($options['max_jobs'] ?? 0));
        $sleepSeconds = max(1, (int) ($options['sleep'] ?? $this->config->int('checkers.queue.worker_sleep_seconds', 3)));

        $this->installSignalHandlers();

        $this->say($quiet, sprintf(
            'AccountCheck worker %s started (pid %d, queue depth %d).',
            $this->workerId,
            getmypid() ?: 0,
            $this->jobs->queueDepth(),
        ));

        $this->logger->info('Worker started', ['worker_id' => $this->workerId, 'pid' => getmypid() ?: 0]);
        $this->heartbeat();

        // Zero, so the first loop sweeps abandoned work and prunes before it
        // claims anything.
        $lastRecovery = 0;

        while (!$this->stopRequested) {
            if ($this->runtimeExhausted($options)) {
                $this->say($quiet, 'Maximum runtime reached; exiting for a clean restart.');
                break;
            }

            // Cheap, but not free: once per lock TTL is enough to bound how long
            // an abandoned job can sit, without every idle pass writing.
            $recoveryInterval = max(30, (int) floor($this->lockTtl() / 2));

            if (time() - $lastRecovery >= $recoveryInterval) {
                $this->recoverStale($quiet);
                $this->pruneExports($quiet);
                $lastRecovery = time();
            }

            try {
                $result = $this->processor->processNext($this->workerId);
            } catch (Throwable $e) {
                // processNext already finalises the job it was holding; this is
                // the last line of defence so the loop itself cannot die.
                $this->logger->error('Worker loop error', ['error' => $e->getMessage()]);
                $this->say($quiet, 'A job failed; see the worker log for details.');
                $result = null;
            }

            if ($result !== null) {
                $this->jobsProcessed++;
                $this->itemsProcessed += $result['items'];

                $this->say($quiet, sprintf(
                    'Job #%d (%s): %d records, %s.',
                    $result['job_id'],
                    $result['checker'],
                    $result['items'],
                    strtolower($result['status']),
                ));

                $this->heartbeat();

                if ($maxJobs > 0 && $this->jobsProcessed >= $maxJobs) {
                    $this->say($quiet, 'Job limit reached; exiting.');
                    break;
                }

                // Straight back round: there may be more waiting, and an idle
                // sleep after every job would halve throughput.
                continue;
            }

            $this->heartbeat();

            if ($once) {
                break;
            }

            $this->idle($sleepSeconds);
        }

        $this->shutdown($quiet);

        return 0;
    }

    /** Runs a single pass and exits: what a cron-driven deployment calls. */
    public function runOnce(?string $workerId = null): int
    {
        return $this->run(['once' => true, 'worker_id' => $workerId]);
    }

    // -- Loop internals -----------------------------------------------------

    /** Sleeps in one-second steps so a signal is acted on promptly. */
    private function idle(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->stopRequested) {
                return;
            }

            sleep(1);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    private function recoverStale(bool $quiet): void
    {
        try {
            $recovered = $this->jobs->recoverStale($this->lockTtl());
        } catch (Throwable $e) {
            $this->logger->warning('Stale lease recovery failed', ['error' => $e->getMessage()]);

            return;
        }

        if ($recovered['jobs'] === 0 && $recovered['items'] === 0) {
            return;
        }

        $this->logger->info('Recovered abandoned work', $recovered);
        $this->say($quiet, sprintf(
            'Recovered %d abandoned job(s) and %d record(s) from expired leases.',
            $recovered['jobs'],
            $recovered['items'],
        ));
    }

    /**
     * Deletes export files that have outlived their retention.
     *
     * Housekeeping belongs here rather than in a request: nobody should wait on
     * it, and it has to happen whether or not anyone is signed in.
     */
    private function pruneExports(bool $quiet): void
    {
        try {
            $pruned = $this->exports->pruneExpired();
        } catch (Throwable $e) {
            $this->logger->warning('Export pruning failed', ['error' => $e->getMessage()]);

            return;
        }

        if ($pruned > 0) {
            $this->logger->info('Pruned expired exports', ['count' => $pruned]);
            $this->say($quiet, sprintf('Removed %d expired export file(s).', $pruned));
        }
    }

    private function heartbeat(): void
    {
        try {
            $this->jobs->heartbeat(
                $this->workerId,
                (string) (gethostname() ?: 'unknown'),
                getmypid() ?: 0,
                $this->jobsProcessed,
                $this->itemsProcessed,
            );
        } catch (Throwable $e) {
            // A missed heartbeat degrades monitoring; it must not stop work.
            $this->logger->warning('Heartbeat failed', ['error' => $e->getMessage()]);
        }
    }

    private function shutdown(bool $quiet): void
    {
        // The row is removed rather than left stale, so the health endpoint
        // distinguishes "no worker running" from "worker died".
        try {
            $this->jobs->forgetHeartbeat($this->workerId);
            $this->jobs->pruneHeartbeats();
        } catch (Throwable $e) {
            $this->logger->warning('Heartbeat cleanup failed', ['error' => $e->getMessage()]);
        }

        $this->logger->info('Worker stopped', [
            'worker_id' => $this->workerId,
            'jobs_processed' => $this->jobsProcessed,
            'items_processed' => $this->itemsProcessed,
            'uptime_seconds' => time() - $this->startedAt,
        ]);

        $this->say($quiet, sprintf(
            'Worker %s stopped after %d job(s) and %d record(s).',
            $this->workerId,
            $this->jobsProcessed,
            $this->itemsProcessed,
        ));
    }

    // -- Environment --------------------------------------------------------

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            // Without pcntl the worker still runs; it just cannot be asked to
            // stop gracefully, so --max-runtime does the bounding instead.
            $this->logger->warning('pcntl is not available; graceful shutdown is disabled');

            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->stopRequested = true;
            $this->logger->info('Shutdown signal received', ['signal' => $signal]);
            fwrite(STDOUT, PHP_EOL . 'Finishing the current chunk, then stopping…' . PHP_EOL);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /** @param array<string, mixed> $options */
    private function runtimeExhausted(array $options): bool
    {
        $max = (int) ($options['max_runtime'] ?? $this->config->int('checkers.queue.max_runtime_seconds', 3600));

        return $max > 0 && (time() - $this->startedAt) >= $max;
    }

    private function lockTtl(): int
    {
        return max(60, $this->config->int('checkers.queue.lock_ttl_seconds', 300));
    }

    /**
     * A stable, unique id for this process.
     *
     * Host and pid make it readable in the admin panel; the random suffix keeps
     * it unique when pids are recycled or two containers share a hostname.
     */
    private function resolveWorkerId(?string $provided): string
    {
        if ($provided !== null && trim($provided) !== '') {
            return substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $provided) ?? 'worker', 0, 64);
        }

        $host = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) (gethostname() ?: 'host')) ?? 'host';

        return substr(sprintf('%s-%d-%s', $host, getmypid() ?: 0, bin2hex(random_bytes(3))), 0, 64);
    }

    private function say(bool $quiet, string $message): void
    {
        if ($quiet || PHP_SAPI !== 'cli') {
            return;
        }

        fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message . PHP_EOL);
    }
}
