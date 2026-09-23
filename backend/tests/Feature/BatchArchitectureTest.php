<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Queue\JobProcessor;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Support\Paginator;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * The 5,000-record batch, end to end, against mocks only.
 *
 * CHECKER_MODE is `mock` in the test environment, so every one of these checks
 * is a local hash and not a single outbound request is made.
 *
 * The architecture claim this suite exists to prove is that a batch of the
 * maximum size is ordinary work rather than a special case:
 *
 *   - submission returns without checking anything, so no request runs long;
 *   - the records are written in chunks rather than one statement per row;
 *   - the worker claims work in chunks and holds one chunk in memory, so peak
 *     memory does not scale with the size of the job;
 *   - progress is observable while the job runs, which is what the browser
 *     polls instead of holding a socket open;
 *   - results are read back a page at a time, so nothing ever loads 5,000 rows
 *     into a browser;
 *   - and the credit reservation settles exactly once at the end.
 *
 * @group slow
 */
final class BatchArchitectureTest extends DatabaseTestCase
{
    /** The advertised per-job maximum. */
    private const BATCH = 5000;

    /** A list of `count` distinct, valid Gmail addresses. */
    private function addresses(int $count): string
    {
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $lines[] = sprintf('batch.record.%05d@gmail.com', $i);
        }

        return implode("\n", $lines);
    }

    public function testTheAdvertisedMaximumIsFiveThousand(): void
    {
        $capabilities = $this->make(\AccountCheck\Checkers\CheckerRegistry::class)
            ->get('gmail')
            ->getCapabilities();

        $this->assertSame(self::BATCH, $capabilities->maxBatchSize);
        $this->assertSame(
            self::BATCH,
            $this->make(Config::class)->int('checkers.queue.max_job_items', 0),
            'The queue cap and the advertised cap must agree.',
        );
    }

    public function testSubmittingFiveThousandRecordsReturnsWithoutCheckingAnything(): void
    {
        $user = $this->makeUser(credits: 10000);

        $startedAt = hrtime(true);
        $created = $this->make(JobService::class)->create($user, 'gmail', $this->addresses(self::BATCH));
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $jobId = (int) $created['job']['id'];

        $this->assertSame(self::BATCH, (int) $created['job']['total_items']);
        $this->assertSame('QUEUED', (string) $created['job']['status']);

        // The submission does no checking at all: every record is still
        // waiting and not one result exists.
        $this->assertSame(self::BATCH, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM checker_job_items WHERE job_id = :id AND status = 'PENDING'",
            ['id' => $jobId],
        ));
        $this->assertSame(0, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM checker_results WHERE job_id = :id',
            ['id' => $jobId],
        ));

        // A generous ceiling - the point is that this is a write, not 5,000
        // checks, so it cannot be anywhere near a request timeout.
        $this->assertLessThan(
            15_000,
            $elapsedMs,
            'Submitting a full batch took ' . $elapsedMs . 'ms, which suggests work that belongs in the worker.',
        );

        // And the credits are held, not spent.
        $wallet = $this->make(WalletRepository::class)->find($user->id) ?? [];
        $this->assertSame(10000, (int) $wallet['balance']);
        $this->assertSame(self::BATCH, (int) $wallet['reserved']);
    }

    public function testOneRecordOverTheMaximumIsRefusedAndQueuesNothing(): void
    {
        $user = $this->makeUser(credits: 10000);

        try {
            $this->make(JobService::class)->create($user, 'gmail', $this->addresses(self::BATCH + 1));
            $this->fail('A batch above the advertised maximum should be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame('BATCH_TOO_LARGE', $e->errorCode());
        }

        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM checker_jobs'));
        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM checker_job_items'));

        $wallet = $this->make(WalletRepository::class)->find($user->id) ?? [];
        $this->assertSame(0, (int) $wallet['reserved'], 'A refused batch leaves no hold behind.');
    }

    public function testTheRecordsAreWrittenInChunksRatherThanOnePerStatement(): void
    {
        $user = $this->makeUser(credits: 10000);

        $chunkSize = $this->make(Config::class)->int('checkers.queue.insert_chunk_size', 500);
        $this->assertGreaterThan(1, $chunkSize);

        $before = $this->statementCount();
        $this->make(JobService::class)->create($user, 'gmail', $this->addresses(self::BATCH));
        $written = $this->statementCount() - $before;

        // One statement per record would be 5,000 on its own. Allow a wide
        // margin for the rest of the submission and still catch that.
        $ceiling = (int) ceil(self::BATCH / $chunkSize) + 100;

        $this->assertLessThan(
            $ceiling,
            $written,
            sprintf('Submission ran %d statements for %d records.', $written, self::BATCH),
        );
    }

    public function testTheWorkerRunsAFullBatchInChunksWithBoundedMemory(): void
    {
        $user = $this->makeUser(credits: 10000);

        $created = $this->make(JobService::class)->create($user, 'gmail', $this->addresses(self::BATCH));
        $jobId = (int) $created['job']['id'];

        $jobs = $this->make(JobRepository::class);
        $service = $this->make(JobService::class);
        $batchSize = max(1, min(500, $this->make(Config::class)->int('checkers.queue.batch_size', 50)));

        $baseline = memory_get_usage(true);
        $peak = $baseline;
        $progressSamples = [];

        // Run the job as the worker does, but stop between chunks to observe
        // it: a real worker's loop is the same claim, process, repeat.
        $processor = $this->make(JobProcessor::class);
        $chunks = 0;

        $processor->onShouldStop(function () use (
            &$chunks,
            &$peak,
            &$progressSamples,
            $service,
            $user,
            $created
        ): bool {
            $chunks++;
            $peak = max($peak, memory_get_usage(true));

            // Sample progress a few times rather than on every chunk, since
            // each sample is a query.
            if ($chunks % 25 === 1) {
                $progressSamples[] = $service->progress($user->id, (string) $created['job']['uuid']);
            }

            return false;
        });

        $summary = $processor->processNext('batch-worker');

        $this->assertNotNull($summary);
        $this->assertSame('COMPLETED', $summary['status']);
        $this->assertSame(self::BATCH, $summary['items']);

        // The loop really did run in chunks rather than claiming everything.
        $this->assertGreaterThanOrEqual(
            (int) floor(self::BATCH / $batchSize),
            $chunks,
            'The worker did not claim the batch in chunks.',
        );

        // Memory is the architectural claim: one chunk at a time, so growth
        // over a 5,000-record job is a few megabytes and not proportional to
        // the job. 32 MB is far above one chunk and far below 5,000 rows of
        // results held in memory.
        $growthBytes = $peak - $baseline;
        $this->assertLessThan(
            32 * 1024 * 1024,
            $growthBytes,
            sprintf('Memory grew by %.1f MB while running the batch.', $growthBytes / 1_048_576),
        );

        // Progress was visible while the job ran, which is what the browser
        // polls. The samples must climb rather than jump from 0 to done.
        $this->assertGreaterThan(1, count($progressSamples));
        $this->assertFalse((bool) $progressSamples[0]['is_finished']);

        $processedSeries = array_map(
            static fn (array $sample): int => (int) $sample['processed_items'],
            $progressSamples,
        );
        $sorted = $processedSeries;
        sort($sorted);
        $this->assertSame($sorted, $processedSeries, 'Progress went backwards while the job ran.');
        $this->assertGreaterThan(0, end($processedSeries));
        $this->assertLessThan(self::BATCH, $processedSeries[0]);

        // Every record reached a final state with a stored result.
        $job = $jobs->find($jobId) ?? [];
        $this->assertSame('COMPLETED', (string) $job['status']);
        $this->assertSame(self::BATCH, (int) $job['processed_items']);
        $this->assertSame(
            self::BATCH,
            (int) $job['successful_items'] + (int) $job['failed_items'],
        );
        $this->assertSame(self::BATCH, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM checker_results WHERE job_id = :id',
            ['id' => $jobId],
        ));

        // The reservation settled once, charging for the billable records only.
        $spent = (int) $job['credits_spent'];
        $this->assertSame((int) $job['successful_items'], $spent);
        $this->assertGreaterThan(0, self::BATCH - $spent, 'Every record was billed, which the mock mix rules out.');

        $wallet = $this->make(WalletRepository::class)->find($user->id) ?? [];
        $this->assertSame(10000 - $spent, (int) $wallet['balance']);
        $this->assertSame(0, (int) $wallet['reserved']);
        $this->assertSame(1, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :id AND type = 'DEBIT'",
            ['id' => $user->id],
        ));

        // And the results come back a page at a time. A caller asking for the
        // whole job in one response gets a page, which is what keeps 5,000
        // rows out of a browser.
        $paginator = Paginator::fromInput(1, self::BATCH);
        $this->assertLessThanOrEqual(Paginator::MAX_PER_PAGE, $paginator->limit());

        $results = $this->make(\AccountCheck\Repositories\ResultRepository::class)
            ->paginateForJob($jobId, $user->id, $paginator);

        $this->assertSame(self::BATCH, (int) $results['total']);
        $this->assertCount($paginator->limit(), $results['items']);
    }

    /** Statements this connection has run, from the server's own counter. */
    private function statementCount(): int
    {
        $row = $this->database->selectOne("SHOW SESSION STATUS LIKE 'Questions'");

        return (int) ($row['Value'] ?? 0);
    }
}
