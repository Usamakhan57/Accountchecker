<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Core\HttpException;
use AccountCheck\Queue\JobProcessor;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * A job from submission to settlement, against the mock engine.
 *
 * Nothing here makes an outbound request: CHECKER_MODE is `mock` in the test
 * env, so every outcome is derived locally from a hash of the input.
 *
 * The invariant these tests exist to protect is the credit one. Credits are
 * reserved at submission and settled exactly once at the terminal transition,
 * so whatever path a job takes - finished, cancelled, or abandoned by a dead
 * worker - the user ends up charged for the records an authorized source
 * actually answered for and for nothing else.
 */
final class JobLifecycleTest extends DatabaseTestCase
{
    private function jobs(): JobService
    {
        return $this->make(JobService::class);
    }

    private function wallets(): WalletRepository
    {
        return $this->make(WalletRepository::class);
    }

    /** A list of distinct, valid Gmail addresses. */
    private function addresses(int $count, string $prefix = 'record'): string
    {
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $lines[] = sprintf('%s.%04d@gmail.com', $prefix, $i);
        }

        return implode("\n", $lines);
    }

    public function testSubmittingReservesCreditsWithoutSpendingThem(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(10));

        $wallet = $this->wallets()->find($user->id) ?? [];

        $this->assertSame(100, (int) $wallet['balance'], 'Nothing is spent at submission.');
        $this->assertSame(10, (int) $wallet['reserved']);
        $this->assertSame(90, (int) $wallet['available']);

        // A reservation is not a transaction, so the ledger stays empty until
        // the job settles.
        $this->assertSame(0, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :id AND type <> :credit',
            ['id' => $user->id, 'credit' => 'CREDIT'],
        ));

        $this->assertSame('QUEUED', (string) $created['job']['status']);
        $this->assertSame(10, (int) $created['job']['total_items']);
    }

    public function testSubmittingReturnsBeforeAnythingIsChecked(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(25));
        $jobId = (int) $created['job']['id'];

        $this->assertSame(0, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM checker_results WHERE job_id = :id',
            ['id' => $jobId],
        ));
        $this->assertSame(25, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM checker_job_items WHERE job_id = :id AND status = 'PENDING'",
            ['id' => $jobId],
        ));
    }

    public function testInvalidAndDuplicateLinesAreReportedAndNotCharged(): void
    {
        $user = $this->makeUser(credits: 100);

        $input = implode("\n", [
            'one@gmail.com',
            'one@gmail.com',      // duplicate
            'ONE@gmail.com',      // duplicate after normalization
            'not-an-address',     // invalid
            '@gmail.com',         // invalid
            'two@gmail.com',
        ]);

        $created = $this->jobs()->create($user, 'gmail', $input);

        $this->assertSame(2, (int) $created['job']['total_items']);
        $this->assertSame(2, (int) $created['skipped']['duplicate']);
        $this->assertSame(2, (int) $created['skipped']['invalid']);

        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(2, (int) $wallet['reserved'], 'Only the queued records are reserved for.');
    }

    public function testAJobLargerThanTheWalletIsRefusedAndQueuesNothing(): void
    {
        $user = $this->makeUser(credits: 5);

        try {
            $this->jobs()->create($user, 'gmail', $this->addresses(10));
            $this->fail('A job the wallet cannot cover should be refused.');
        } catch (HttpException $e) {
            $this->assertSame(402, $e->status());
            $this->assertSame('INSUFFICIENT_CREDITS', $e->errorCode());
        }

        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(5, (int) $wallet['balance']);
        $this->assertSame(0, (int) $wallet['reserved'], 'A refused job leaves no hold behind.');
        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM checker_jobs'));
    }

    public function testRunningAJobChargesOnlyForBillableResults(): void
    {
        $user = $this->makeUser(credits: 200);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(40));
        $jobId = (int) $created['job']['id'];

        $summary = $this->make(JobProcessor::class)->processNext('test-worker');

        $this->assertNotNull($summary);
        $this->assertSame('COMPLETED', $summary['status']);

        $job = $this->make(JobRepository::class)->find($jobId) ?? [];
        $spent = (int) $job['credits_spent'];
        $successful = (int) $job['successful_items'];
        $failed = (int) $job['failed_items'];

        $this->assertSame(40, (int) $job['processed_items']);
        $this->assertSame(40, $successful + $failed);
        $this->assertSame($successful, $spent, 'One credit per billable record, and none for the rest.');

        // The mock distribution includes UNAVAILABLE and ERROR, so a run of
        // this size must contain some records nobody was charged for. Without
        // that this test would pass even if everything were billed.
        $this->assertGreaterThan(0, $failed, 'The mock run produced no unbillable results to check against.');

        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(200 - $spent, (int) $wallet['balance']);
        $this->assertSame(0, (int) $wallet['reserved'], 'The unused part of the hold is released.');
        $this->assertSame(200 - $spent, (int) $wallet['available']);

        // Exactly one ledger entry for the job, written at settlement.
        $this->assertSame(1, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :id AND type = 'DEBIT'",
            ['id' => $user->id],
        ));
    }

    public function testEveryProcessedRecordGetsAStoredResult(): void
    {
        $user = $this->makeUser(credits: 200);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(30));
        $jobId = (int) $created['job']['id'];

        $this->make(JobProcessor::class)->processNext('test-worker');

        $this->assertSame(30, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM checker_results WHERE job_id = :id',
            ['id' => $jobId],
        ));
        $this->assertSame(0, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM checker_job_items WHERE job_id = :id AND status IN ('PENDING', 'PROCESSING')",
            ['id' => $jobId],
        ));
    }

    public function testCancellingBeforeAnyWorkReleasesTheWholeHold(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(20));
        $reference = (string) $created['job']['uuid'];

        $cancelled = $this->jobs()->cancel($user->id, $reference);

        $this->assertSame('CANCELLED', (string) $cancelled['status']);

        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(100, (int) $wallet['balance'], 'Nothing was checked, so nothing is charged.');
        $this->assertSame(0, (int) $wallet['reserved']);
        $this->assertSame(100, (int) $wallet['available']);
    }

    public function testACancelledJobCannotBeCancelledAgain(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(5));
        $reference = (string) $created['job']['uuid'];

        $this->jobs()->cancel($user->id, $reference);

        // The second cancel is the shape a double-click takes, and it must not
        // settle the reservation a second time.
        try {
            $this->jobs()->cancel($user->id, $reference);
            $this->fail('A finished job should not be cancellable.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->status());
        }

        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(100, (int) $wallet['balance']);
        $this->assertSame(100, (int) $wallet['available']);
    }

    public function testACancelledJobIsNotPickedUpByAWorker(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(20));
        $this->jobs()->cancel($user->id, (string) $created['job']['uuid']);

        $this->assertNull(
            $this->make(JobProcessor::class)->processNext('test-worker'),
            'The queue should be empty once the only job is cancelled.',
        );
        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM checker_results'));
    }

    public function testAJobAbandonedByADeadWorkerIsRecoveredAndSettledOnce(): void
    {
        $user = $this->makeUser(credits: 200);

        $created = $this->jobs()->create($user, 'gmail', $this->addresses(20));
        $jobId = (int) $created['job']['id'];

        $repository = $this->make(JobRepository::class);

        // Claim the job and then walk away, as a killed worker would. Ageing
        // the lease is what the recovery sweep looks for.
        $claimed = $repository->claimNextJob('dead-worker');
        $this->assertNotNull($claimed);
        $this->assertSame($jobId, (int) $claimed['id']);

        $this->database->run(
            'UPDATE checker_jobs SET locked_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = :id',
            ['id' => $jobId],
        );

        $recovered = $repository->recoverStale(300);
        $this->assertNotSame([], $recovered, 'The stale claim should have been swept back into the queue.');

        $summary = $this->make(JobProcessor::class)->processNext('live-worker');

        $this->assertNotNull($summary);
        $this->assertSame('COMPLETED', $summary['status']);

        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(0, (int) $wallet['reserved']);
        $this->assertSame(1, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :id AND type = 'DEBIT'",
            ['id' => $user->id],
        ), 'The recovered job settles exactly once.');
    }

    public function testTheActiveJobCapBoundsWhatOneAccountCanQueue(): void
    {
        $user = $this->makeUser(credits: 10000);

        $limit = max(1, $this->make(\AccountCheck\Core\Config::class)
            ->int('checkers.queue.max_active_jobs_per_user', 3));

        for ($i = 0; $i < $limit; $i++) {
            $this->jobs()->create($user, 'gmail', $this->addresses(3, 'batch' . $i));
        }

        try {
            $this->jobs()->create($user, 'gmail', $this->addresses(3, 'overflow'));
            $this->fail('The active job cap should have refused this one.');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status());
            $this->assertSame('TOO_MANY_ACTIVE_JOBS', $e->errorCode());
        }

        $this->assertSame($limit, (int) $this->database->scalar('SELECT COUNT(*) FROM checker_jobs'));
    }

    public function testADisabledCheckerRefusesNewJobs(): void
    {
        $user = $this->makeUser(credits: 100);

        $this->database->run("UPDATE checker_types SET is_enabled = 0 WHERE slug = 'gmail'");

        // A fresh container, because the registry caches a resolved checker
        // for the life of a request and the switch was thrown after that.
        $jobs = $this->makeFresh(JobService::class);

        try {
            $jobs->create($user, 'gmail', $this->addresses(5));
            $this->fail('A disabled checker should not accept work.');
        } catch (HttpException $e) {
            $this->assertContains($e->status(), [404, 409]);
        } finally {
            $this->database->run("UPDATE checker_types SET is_enabled = 1 WHERE slug = 'gmail'");
        }

        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM checker_jobs'));
        $wallet = $this->wallets()->find($user->id) ?? [];
        $this->assertSame(0, (int) $wallet['reserved']);
    }
}
