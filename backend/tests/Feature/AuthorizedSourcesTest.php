<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Checkers\AbstractChecker;
use AccountCheck\Checkers\CheckStatus;
use AccountCheck\Checkers\HttpClient;
use AccountCheck\Checkers\MockEngine;
use AccountCheck\Checkers\PlatformChecker;
use AccountCheck\Queue\JobProcessor;
use AccountCheck\Repositories\JobRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Support\Logger;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * A checker with no authorized verification source does nothing and charges
 * nothing.
 *
 * This is the rule the whole product rests on. Where a platform offers no
 * authorized way to verify a handle, the answer is UNAVAILABLE - not a scrape,
 * not an enumeration probe, not a login attempt. These tests pin down that the
 * unconfigured path makes no outbound call at all, and that UNAVAILABLE is
 * never billable, so nobody pays to be told a check could not be made.
 */
final class AuthorizedSourcesTest extends DatabaseTestCase
{
    /** A production-mode platform checker with no provider configured. */
    private function unconfiguredChecker(): PlatformChecker
    {
        return new PlatformChecker(
            'instagram',
            ['min' => 1, 'max' => 30, 'pattern' => '/^[A-Za-z0-9._]+$/'],
            AbstractChecker::MODE_PRODUCTION,
            [],
            ['label' => 'Instagram Checker', 'credit_cost' => 2, 'is_enabled' => 1],
            $this->make(HttpClient::class),
            $this->make(Logger::class),
            $this->make(MockEngine::class),
        );
    }

    public function testAnUnconfiguredCheckerReportsUnavailableWithoutCallingAnything(): void
    {
        $checker = $this->unconfiguredChecker();

        $this->assertFalse($checker->isConfigured());
        $this->assertFalse($checker->getCapabilities()->configured, 'The UI must be told up front.');

        $startedAt = hrtime(true);
        $outcome = $checker->check('someone');
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $this->assertSame(CheckStatus::Unavailable, $outcome->status);
        $this->assertNotSame('', (string) $outcome->reason);

        // Two things say no request was attempted. The configured path stamps
        // a response time onto every outcome it returns, and this one has
        // none; and the call returned immediately, where any attempt - even a
        // refused connection - would have cost a round trip.
        $this->assertNull($outcome->responseTimeMs);
        $this->assertLessThan(50, $elapsedMs);
    }

    public function testAnUnavailableResultIsNeverBillable(): void
    {
        $this->assertFalse(CheckStatus::Unavailable->isBillable());
        $this->assertFalse(CheckStatus::Error->isBillable());
    }

    public function testRecordsThatCouldNotBeCheckedAreRefundedAtSettlement(): void
    {
        $user = $this->makeUser(credits: 500);

        $lines = [];
        for ($i = 0; $i < 60; $i++) {
            $lines[] = sprintf('refund.%03d@gmail.com', $i);
        }

        $created = $this->make(JobService::class)->create($user, 'gmail', implode("\n", $lines));
        $jobId = (int) $created['job']['id'];

        $this->make(JobProcessor::class)->processNext('test-worker');

        $job = $this->make(JobRepository::class)->find($jobId) ?? [];

        $unbillable = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM checker_results WHERE job_id = :id AND status IN ('UNAVAILABLE', 'ERROR')",
            ['id' => $jobId],
        );

        $this->assertGreaterThan(0, $unbillable, 'This run produced nothing unbillable to check against.');
        $this->assertSame(60 - (int) $job['failed_items'], (int) $job['credits_spent']);

        $wallet = $this->make(WalletRepository::class)->find($user->id) ?? [];
        $this->assertSame(500 - (int) $job['credits_spent'], (int) $wallet['balance']);
        $this->assertSame(0, (int) $wallet['reserved']);
    }

    public function testEveryCheckerAdvertisesWhetherItIsConfigured(): void
    {
        foreach ($this->make(\AccountCheck\Checkers\CheckerRegistry::class)->capabilities(true) as $capability) {
            $this->assertArrayHasKey('configured', $capability);
            $this->assertArrayHasKey('mode', $capability);
            $this->assertIsBool($capability['configured']);
            $this->assertContains($capability['mode'], [AbstractChecker::MODE_MOCK, AbstractChecker::MODE_PRODUCTION]);
        }
    }

    public function testACheckersProviderCredentialsAreNeverPublished(): void
    {
        $capabilities = $this->make(\AccountCheck\Checkers\CheckerRegistry::class)->capabilities(true);
        $serialised = json_encode($capabilities) ?: '';

        foreach (['api_key', 'api_url', 'apiKey', 'secret', 'token', 'password'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $serialised,
                'The checker catalogue exposed ' . $forbidden . '.',
            );
        }
    }
}
