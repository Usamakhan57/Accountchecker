<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Response;
use AccountCheck\Middleware\SecurityHeadersMiddleware;
use AccountCheck\Queue\JobProcessor;
use AccountCheck\Repositories\ResultRepository;
use AccountCheck\Services\JobService;
use AccountCheck\Services\SupportService;
use AccountCheck\Support\Paginator;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * Hostile input reaching the parts of the API that take it.
 *
 * Every query is a prepared statement and every sortable column comes from an
 * allow-list, so these strings should be data and nothing else: stored and
 * returned exactly as they arrived, never interpreted.
 */
final class InputSafetyTest extends DatabaseTestCase
{
    /** @return list<string> */
    private static function payloads(): array
    {
        return [
            "' OR '1'='1",
            "'; DROP TABLE users; --",
            '" UNION SELECT password_hash FROM users --',
            "1' AND (SELECT SLEEP(5)) --",
            '\\',
            '%',
            '_',
            "admin'--",
            '<script>alert(1)</script>',
            '${jndi:ldap://example.invalid/a}',
        ];
    }

    public function testInjectionThroughResultFiltersChangesNothing(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->make(JobService::class)->create($user, 'gmail', "safe.one@gmail.com\nsafe.two@gmail.com");
        $jobId = (int) $created['job']['id'];
        $this->make(JobProcessor::class)->processNext('test-worker');

        $results = $this->make(ResultRepository::class);
        $usersBefore = (int) $this->database->scalar('SELECT COUNT(*) FROM users');

        foreach (self::payloads() as $payload) {
            $page = $results->paginateForJob($jobId, $user->id, Paginator::fromInput(1, 25), [
                'search' => $payload,
                'status' => $payload,
                'sort' => $payload,
                'direction' => $payload,
            ]);

            // A filter nobody's data matches returns nothing. What matters is
            // that it returns, rather than executing.
            $this->assertIsArray($page['items']);
            $this->assertLessThanOrEqual(2, (int) $page['total']);
        }

        // Every table is still there and untouched.
        $this->assertSame($usersBefore, (int) $this->database->scalar('SELECT COUNT(*) FROM users'));
        $this->assertSame(2, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM checker_results WHERE job_id = :id',
            ['id' => $jobId],
        ));
    }

    public function testAnUnknownSortColumnFallsBackRatherThanReachingTheQuery(): void
    {
        $user = $this->makeUser(credits: 100);

        $created = $this->make(JobService::class)->create($user, 'gmail', "sort.one@gmail.com\nsort.two@gmail.com");
        $jobId = (int) $created['job']['id'];
        $this->make(JobProcessor::class)->processNext('test-worker');

        $results = $this->make(ResultRepository::class);

        $hostile = $results->paginateForJob($jobId, $user->id, Paginator::fromInput(1, 25), [
            'sort' => 'r.id; DROP TABLE checker_results',
        ]);
        $default = $results->paginateForJob($jobId, $user->id, Paginator::fromInput(1, 25));

        $this->assertSame(
            array_column($default['items'], 'id'),
            array_column($hostile['items'], 'id'),
            'An unrecognised sort should fall back to the default ordering.',
        );
    }

    public function testASqlWildcardInASearchIsTreatedAsALiteral(): void
    {
        $user = $this->makeUser(credits: 100);

        $this->make(JobService::class)->create($user, 'gmail', "wildcard.one@gmail.com\nwildcard.two@gmail.com");
        $this->make(JobProcessor::class)->processNext('test-worker');

        $results = $this->make(ResultRepository::class);

        // '%' matches everything when it is a wildcard and nothing when it is
        // a character, which is what tells the two apart.
        $page = $results->paginateForUser($user->id, Paginator::fromInput(1, 25), ['search' => '%']);

        $this->assertSame(0, (int) $page['total'], 'A percent sign was interpreted as a wildcard.');
    }

    public function testHostileTextInASupportTicketIsStoredVerbatim(): void
    {
        $user = $this->makeUser(credits: 0);
        $support = $this->make(SupportService::class);

        $payload = '<script>alert(document.cookie)</script>';

        $ticket = $support->open($user, 'Question about ' . $payload, 'Body with ' . $payload, 'NORMAL');
        $uuid = (string) $ticket['ticket']['uuid'];

        $stored = $this->database->selectOne(
            'SELECT subject FROM support_tickets WHERE uuid = :uuid',
            ['uuid' => $uuid],
        ) ?? [];

        // Stored exactly as sent, with no escaping baked into the data: the
        // API returns JSON and React escapes on render, so mangling it here
        // would only corrupt legitimate text.
        $this->assertStringContainsString($payload, (string) $stored['subject']);

        // And it comes back inside a JSON string, which is what makes it
        // inert: the response is application/json with nosniff, so a browser
        // never parses it as markup, and React escapes it on render. Encoding
        // it in the database instead would only corrupt legitimate text that
        // happened to contain an angle bracket.
        $response = Response::success($support->show($user, $uuid), 'Ticket');
        $decorated = (new SecurityHeadersMiddleware($this->make(Config::class)))
            ->decorate($response, $this->makeRequest('GET', '/api/support/' . $uuid));

        $this->assertStringStartsWith('application/json', (string) ($decorated->headers()['Content-Type'] ?? ''));
        $this->assertSame('nosniff', (string) ($decorated->headers()['X-Content-Type-Options'] ?? ''));
        $this->assertStringContainsString(
            $payload,
            $decorated->body(),
            'The stored text did not survive the round trip intact.',
        );
    }

    public function testAnInvalidRecordIsRejectedRatherThanQueued(): void
    {
        $user = $this->makeUser(credits: 100);

        $input = implode("\n", array_merge(self::payloads(), ['genuine@gmail.com']));

        $created = $this->make(JobService::class)->create($user, 'gmail', $input);

        $this->assertSame(1, (int) $created['job']['total_items']);
        $this->assertSame(count(self::payloads()), (int) $created['skipped']['invalid']);

        $queued = $this->database->select(
            'SELECT normalized_input FROM checker_job_items WHERE job_id = :id',
            ['id' => (int) $created['job']['id']],
        );

        $this->assertSame([['normalized_input' => 'genuine@gmail.com']], $queued);
    }

    public function testAnErrorResponseCarriesNoInternals(): void
    {
        $user = $this->makeUser(credits: 100);

        try {
            $this->make(JobService::class)->show($user->id, "' OR 1=1 --");
            $this->fail('A nonsense job reference resolved.');
        } catch (HttpException $e) {
            $body = json_encode($e->toResponse()->body()) ?: '';

            foreach (['SQLSTATE', 'SELECT ', 'PDO', '/home/', '.php', 'Stack trace', 'accountcheck_test'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, 'An error response leaked ' . $leak . '.');
            }
        }
    }

    public function testAnOverlongFieldIsRefusedRatherThanTruncated(): void
    {
        $user = $this->makeUser(credits: 0);

        try {
            $this->make(SupportService::class)->open($user, str_repeat('a', 5000), 'Body', 'NORMAL');
            $this->fail('An overlong subject was accepted.');
        } catch (HttpException | \AccountCheck\Core\DatabaseException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM support_tickets'));
    }
}
