<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Unit;

use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Middleware\CsrfMiddleware;
use AccountCheck\Tests\TestCase;

/**
 * Double-submit CSRF protection.
 *
 * The property being pinned down is that a request from another origin cannot
 * pass. Such a request carries the cookie - the browser attaches it whatever
 * page caused the request - but cannot carry the header, because the same
 * origin policy stops the attacking page reading the cookie's value.
 */
final class CsrfMiddlewareTest extends TestCase
{
    private const COOKIE = 'accountcheck_csrf';

    private function middleware(): CsrfMiddleware
    {
        return $this->make(CsrfMiddleware::class);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function request(string $method, string $path = '/api/jobs', array $headers = [], array $cookies = []): Request
    {
        return new Request($method, $path, [], [], $headers, $cookies, [], '203.0.113.5', 'PHPUnit');
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function testASafeMethodNeedsNoToken(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertNull(
                $this->middleware()->handle($this->request($method)),
                $method . ' should not require a token.',
            );
        }
    }

    public function testAWriteCarryingTheMatchingTokenPasses(): void
    {
        $token = $this->token();

        $this->assertNull($this->middleware()->handle($this->request(
            'POST',
            '/api/jobs',
            ['x-csrf-token' => $token],
            [self::COOKIE => $token],
        )));
    }

    public function testAWriteWithTheCookieButNoHeaderIsRefused(): void
    {
        // This is the forged request: the browser sent the cookie, the
        // attacking page could not read it to build the header.
        $this->expectException(HttpException::class);

        $this->middleware()->handle($this->request(
            'POST',
            '/api/jobs',
            [],
            [self::COOKIE => $this->token()],
        ));
    }

    public function testAWriteWithAGuessedHeaderIsRefused(): void
    {
        try {
            $this->middleware()->handle($this->request(
                'POST',
                '/api/jobs',
                ['x-csrf-token' => $this->token()],
                [self::COOKIE => $this->token()],
            ));
            $this->fail('A mismatched token was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->status());
            $this->assertSame('CSRF_TOKEN_MISMATCH', $e->errorCode());
        }
    }

    public function testEveryUnsafeMethodIsChecked(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                $this->middleware()->handle($this->request($method, '/api/jobs'));
                $this->fail($method . ' was not checked for a token.');
            } catch (HttpException $e) {
                $this->assertSame(419, $e->status());
            }
        }
    }

    public function testTheRefusalSaysNothingAboutTheExpectedToken(): void
    {
        $cookie = $this->token();

        try {
            $this->middleware()->handle($this->request(
                'POST',
                '/api/jobs',
                ['x-csrf-token' => 'wrong'],
                [self::COOKIE => $cookie],
            ));
            $this->fail('A mismatched token was accepted.');
        } catch (HttpException $e) {
            $this->assertStringNotContainsString($cookie, $e->getMessage());
            $this->assertStringNotContainsString($cookie, json_encode($e->errors()) ?: '');
        }
    }

    public function testAResponseToAnUntokenedRequestIssuesOne(): void
    {
        $response = $this->middleware()->decorate(Response::success([], 'ok'), $this->request('GET', '/api/health'));

        $cookies = $response->cookies();

        $this->assertCount(1, $cookies);
        $this->assertStringStartsWith(self::COOKIE . '=', $cookies[0]);
        $this->assertStringContainsString('SameSite=', $cookies[0]);

        // The token cookie is readable by our own script on purpose - that is
        // what lets the client echo it back - so it must not be HttpOnly. The
        // session cookie, which does carry authority, is a different cookie.
        $this->assertStringNotContainsStringIgnoringCase('HttpOnly', $cookies[0]);
    }

    public function testAnExistingTokenIsNotRotated(): void
    {
        $token = $this->token();

        $response = $this->middleware()->decorate(
            Response::success([], 'ok'),
            $this->request('GET', '/api/health', [], [self::COOKIE => $token]),
        );

        $this->assertSame([], $response->cookies(), 'A valid token was rotated, which would break other open tabs.');
    }

    public function testAMalformedTokenIsReplaced(): void
    {
        $response = $this->middleware()->decorate(
            Response::success([], 'ok'),
            $this->request('GET', '/api/health', [], [self::COOKIE => 'not-a-token']),
        );

        $this->assertCount(1, $response->cookies());
        $this->assertStringNotContainsString('not-a-token', $response->cookies()[0]);
    }

    public function testTheIssuedTokenIsLongAndRandom(): void
    {
        $seen = [];

        for ($i = 0; $i < 20; $i++) {
            $cookie = $this->middleware()
                ->decorate(Response::success([], 'ok'), $this->request('GET', '/api/health'))
                ->cookies()[0];

            $value = explode(';', explode('=', $cookie, 2)[1], 2)[0];

            $this->assertSame(64, strlen($value), 'The token is shorter than 32 bytes of entropy.');
            $seen[$value] = true;
        }

        $this->assertCount(20, $seen, 'Issued tokens repeated.');
    }
}
