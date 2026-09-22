<?php

declare(strict_types=1);

namespace AccountCheck\Middleware;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Middleware;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Core\ResponseDecorator;

/**
 * Cross-site request forgery protection, by double-submit cookie.
 *
 * The API authenticates with a cookie, which the browser attaches to any
 * request to this origin no matter which page caused it. So a state-changing
 * request has to prove it came from our own code rather than from a form on
 * somebody else's site.
 *
 * How it works: a random token is issued in a cookie that JavaScript can read
 * (deliberately not HttpOnly, unlike the session), and our client echoes it in
 * the X-CSRF-Token header. Another origin's page can cause the cookie to be
 * sent, but the same-origin policy stops it reading the value, so it cannot
 * produce the header. The two are compared with hash_equals.
 *
 * SameSite=Lax on the session cookie already blocks cross-site POST in current
 * browsers. This is the second lock: it holds for a browser that ignores
 * SameSite, and for the same-site-but-different-subdomain case that SameSite
 * does not cover at all.
 *
 * GET, HEAD and OPTIONS are exempt because they must not change state. Any
 * handler that changes something in response to a GET is the bug, not this.
 */
final class CsrfMiddleware implements Middleware, ResponseDecorator
{
    /** Methods that must not change state, and so need no token. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const TOKEN_BYTES = 32;

    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request): ?Response
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return null;
        }

        if (in_array(strtoupper($request->method()), self::SAFE_METHODS, true)) {
            return null;
        }

        if ($this->isExempt($request->path())) {
            return null;
        }

        $cookie = (string) ($request->cookie($this->cookieName()) ?? '');
        $header = trim($request->header('x-csrf-token'));

        // A missing cookie means the client never performed a read first, which
        // our own app always does. It is reported the same way as a mismatch:
        // the client's remedy is identical either way.
        if ($cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
            throw new HttpException(
                'Your session could not be verified. Please reload the page and try again.',
                419,
                'CSRF_TOKEN_MISMATCH',
            );
        }

        return null;
    }

    /**
     * Issues the token when the request arrived without one.
     *
     * Attached on the way out of every response, so a client that has just
     * loaded the app gets a token from its first read and can immediately make
     * a write.
     */
    public function decorate(Response $response, Request $request): Response
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return $response;
        }

        $existing = (string) ($request->cookie($this->cookieName()) ?? '');

        if ($this->isValidToken($existing)) {
            return $response;
        }

        return $response->withCookie($this->buildCookie(bin2hex(random_bytes(self::TOKEN_BYTES))));
    }

    /**
     * A token is only reissued when the one presented is missing or malformed,
     * never merely because it is old: rotating it mid-session would break every
     * tab the user has open.
     */
    private function isValidToken(string $token): bool
    {
        return $token !== '' && preg_match('/^[a-f0-9]{' . (self::TOKEN_BYTES * 2) . '}$/', $token) === 1;
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->config->array('security.csrf.exempt') as $pattern) {
            if (fnmatch((string) $pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function cookieName(): string
    {
        return $this->config->string('security.csrf.cookie_name', 'accountcheck_csrf');
    }

    private function buildCookie(string $value): string
    {
        // Readable by JavaScript on purpose: our client has to echo it back in
        // a header, which is exactly what another origin cannot do. The session
        // cookie stays HttpOnly; this one carries no authority by itself.
        $parts = [
            $this->cookieName() . '=' . $value,
            'Path=' . $this->config->string('session.cookie.path', '/'),
        ];

        $domain = $this->config->string('session.cookie.domain', '');
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        $sameSite = $this->config->string('session.cookie.same_site', 'Lax');
        $parts[] = 'SameSite=' . (in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax');

        if ($this->config->bool('session.cookie.secure', false)) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
