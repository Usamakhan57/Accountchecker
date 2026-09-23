<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Core\HttpException;
use AccountCheck\Services\ExportService;
use AccountCheck\Services\JobService;
use AccountCheck\Services\SupportService;
use AccountCheck\Support\Paginator;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * One account cannot see or touch another's work.
 *
 * Every read here is scoped by user id in the query itself rather than fetched
 * and then checked, so a reference belonging to someone else is answered the
 * same way as one that was never issued: 404, not 403. Telling an attacker that
 * a job id exists but is not theirs is telling them something.
 */
final class AuthorizationTest extends DatabaseTestCase
{
    public function testAnotherUsersJobIsIndistinguishableFromOneThatDoesNotExist(): void
    {
        $owner = $this->makeUser(credits: 100, email: 'owner@example.test');
        $stranger = $this->makeUser(credits: 100, email: 'stranger@example.test');

        $jobs = $this->make(JobService::class);
        $created = $jobs->create($owner, 'gmail', "one@gmail.com\ntwo@gmail.com");
        $reference = (string) $created['job']['uuid'];

        $real = null;
        $invented = null;

        try {
            $jobs->show($stranger->id, $reference);
        } catch (HttpException $e) {
            $real = $e;
        }

        try {
            $jobs->show($stranger->id, 'e4f1a0de-0000-4000-8000-000000000000');
        } catch (HttpException $e) {
            $invented = $e;
        }

        $this->assertNotNull($real, "Another user's job was readable.");
        $this->assertNotNull($invented);
        $this->assertSame(404, $real->status());
        $this->assertSame($invented->status(), $real->status());
        $this->assertSame($invented->getMessage(), $real->getMessage());
        $this->assertSame($invented->errorCode(), $real->errorCode());
    }

    public function testAnotherUsersJobCannotBePolledOrCancelled(): void
    {
        $owner = $this->makeUser(credits: 100, email: 'poll-owner@example.test');
        $stranger = $this->makeUser(credits: 100, email: 'poll-stranger@example.test');

        $jobs = $this->make(JobService::class);
        $created = $jobs->create($owner, 'gmail', "three@gmail.com\nfour@gmail.com");
        $reference = (string) $created['job']['uuid'];

        foreach (['progress', 'cancel'] as $method) {
            try {
                $jobs->{$method}($stranger->id, $reference);
                $this->fail(sprintf("A stranger was able to call %s on another user's job.", $method));
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status());
            }
        }

        // The job is untouched, and so is the owner's hold.
        $this->assertSame('QUEUED', (string) $this->database->scalar(
            'SELECT status FROM checker_jobs WHERE uuid = :uuid',
            ['uuid' => $reference],
        ));
    }

    public function testAJobListingOnlyEverContainsTheCallersOwnJobs(): void
    {
        $owner = $this->makeUser(credits: 100, email: 'list-owner@example.test');
        $stranger = $this->makeUser(credits: 100, email: 'list-stranger@example.test');

        $jobs = $this->make(JobService::class);
        $jobs->create($owner, 'gmail', "five@gmail.com");
        $jobs->create($stranger, 'gmail', "six@gmail.com");

        $page = $jobs->paginate($stranger->id, Paginator::fromInput(1, 50));

        $this->assertCount(1, $page['items']);
        $this->assertSame(1, (int) $page['pagination']['total']);
    }

    public function testResultsAreScopedToTheOwnerEvenWithTheRightJobId(): void
    {
        $owner = $this->makeUser(credits: 100, email: 'results-owner@example.test');
        $stranger = $this->makeUser(credits: 100, email: 'results-stranger@example.test');

        $jobs = $this->make(JobService::class);
        $created = $jobs->create($owner, 'gmail', "seven@gmail.com\neight@gmail.com");
        $jobId = (int) $created['job']['id'];

        $this->make(\AccountCheck\Queue\JobProcessor::class)->processNext('test-worker');

        $results = $this->make(\AccountCheck\Repositories\ResultRepository::class);

        $this->assertSame(2, (int) $results->paginateForJob($jobId, $owner->id, Paginator::fromInput(1, 50))['total']);
        $this->assertSame(
            0,
            (int) $results->paginateForJob($jobId, $stranger->id, Paginator::fromInput(1, 50))['total'],
            'A stranger read results by job id alone.',
        );
    }

    public function testAnotherUsersSupportTicketIsNotReadable(): void
    {
        $owner = $this->makeUser(credits: 0, email: 'ticket-owner@example.test');
        $stranger = $this->makeUser(credits: 0, email: 'ticket-stranger@example.test');

        $support = $this->make(SupportService::class);
        $ticket = $support->open($owner, 'Billing question', 'How do credits expire?', 'NORMAL');
        $uuid = (string) $ticket['ticket']['uuid'];

        try {
            $support->show($stranger, $uuid);
            $this->fail("A stranger read another user's support ticket.");
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status());
        }

        try {
            $support->reply($stranger, $uuid, 'Injecting a reply.');
            $this->fail("A stranger replied to another user's support ticket.");
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status());
        }

        $this->assertSame(1, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM support_messages m INNER JOIN support_tickets t ON t.id = m.ticket_id'
            . ' WHERE t.uuid = :uuid',
            ['uuid' => $uuid],
        ));
    }

    public function testAnotherUsersExportCannotBeDownloadedOrDeleted(): void
    {
        $owner = $this->makeUser(credits: 100, email: 'export-owner@example.test');
        $stranger = $this->makeUser(credits: 100, email: 'export-stranger@example.test');

        $jobs = $this->make(JobService::class);
        $jobs->create($owner, 'gmail', "nine@gmail.com\nten@gmail.com");
        $this->make(\AccountCheck\Queue\JobProcessor::class)->processNext('test-worker');

        $exports = $this->make(ExportService::class);
        $export = $exports->create($owner, 'csv');
        $uuid = (string) $export['uuid'];

        foreach (['download', 'delete'] as $method) {
            try {
                $exports->{$method}($stranger, $uuid);
                $this->fail(sprintf("A stranger was able to %s another user's export.", $method));
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status());
            }
        }

        $this->assertSame(1, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM exports WHERE uuid = :uuid',
            ['uuid' => $uuid],
        ));
    }

    public function testAnExportOnlyEverContainsTheCallersOwnResults(): void
    {
        $owner = $this->makeUser(credits: 100, email: 'csv-owner@example.test');
        $stranger = $this->makeUser(credits: 100, email: 'csv-stranger@example.test');

        $jobs = $this->make(JobService::class);
        $jobs->create($owner, 'gmail', "owner.record@gmail.com");
        $jobs->create($stranger, 'gmail', "stranger.record@gmail.com");

        $processor = $this->make(\AccountCheck\Queue\JobProcessor::class);
        $processor->processNext('test-worker');
        $processor->processNext('test-worker');

        $exports = $this->make(ExportService::class);
        $export = $exports->create($stranger, 'csv');

        $response = $exports->download($stranger, (string) $export['uuid']);
        $body = $response->body();
        $csv = is_string($body) ? $body : (string) json_encode($body);

        $this->assertStringContainsString('stranger.record@gmail.com', $csv);
        $this->assertStringNotContainsString('owner.record@gmail.com', $csv);
    }
}
