<?php

declare(strict_types=1);

namespace AccountCheck\Auth;

use AccountCheck\Core\Config;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\SessionRepository;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Support\Str;

/**
 * Opaque server-side sessions carried in an HttpOnly cookie.
 *
 * The cookie holds a 256-bit random token; the database holds only its
 * SHA-256. Nothing about the user is encoded in the cookie, so it cannot be
 * read or forged client-side, and revoking a session takes effect immediately
 * rather than at token expiry.
 */
final class SessionManager
{
    /** Only refresh last_active_at this often, to avoid a write per request. */
    private const TOUCH_THROTTLE_SECONDS = 300;

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly Config $config,
    ) {
    }

    /**
     * Opens a session and returns the cookie header value to send back.
     *
     * @return array{token: string, cookie: string, session_id: int}
     */
    public function start(int $userId, Request $request, bool $remember): array
    {
        $token = Str::randomToken(32);
        $lifetime = $this->lifetimeSeconds($remember);

        $sessionId = $this->sessions->create(
            $userId,
            Str::hashToken($token),
            $lifetime,
            $request->ip(),
            $request->userAgent(),
        );

        return [
            'token' => $token,
            'cookie' => $this->buildCookie($token, $remember ? $lifetime : null),
            'session_id' => $sessionId,
        ];
    }

    /**
     * Resolves the session cookie on an incoming request.
     *
     * Returns null for a missing, expired, revoked or unknown token, and for a
     * user whose account is no longer active.
     */
    public function resolve(Request $request): ?AuthenticatedUser
    {
        $token = $request->cookie($this->cookieName());

        if ($token === null || $token === '') {
            return null;
        }

        $row = $this->sessions->findActive(Str::hashToken($token));

        if ($row === null) {
            return null;
        }

        // A suspended account's existing sessions stop working at once.
        if ((string) $row['status'] !== 'ACTIVE') {
            return null;
        }

        $sessionId = (int) $row['session_id'];

        if ($this->isIdle($row)) {
            $this->sessions->revoke($sessionId);

            return null;
        }

        $this->touchIfStale($row, $sessionId);

        return AuthenticatedUser::fromRow(
            $row,
            $sessionId,
            $this->users->permissionsFor((int) $row['user_id']),
        );
    }

    public function destroy(int $sessionId): string
    {
        $this->sessions->revoke($sessionId);

        return $this->expiredCookie();
    }

    /** A Set-Cookie that clears the session cookie in the browser. */
    public function expiredCookie(): string
    {
        return $this->buildCookie('', 0);
    }

    /** Signs every other device out — used after a password change. */
    public function destroyOthers(int $userId, int $keepSessionId): int
    {
        return $this->sessions->revokeAllForUser($userId, $keepSessionId);
    }

    public function destroyAll(int $userId): int
    {
        return $this->sessions->revokeAllForUser($userId);
    }

    public function attachCookie(Response $response, string $cookie): Response
    {
        return $response->withHeader('Set-Cookie', $cookie);
    }

    public function cookieName(): string
    {
        return $this->config->string('session.cookie_name', 'accountcheck_session');
    }

    public function lifetimeSeconds(bool $remember): int
    {
        $minutes = $this->config->int('session.lifetime_minutes', 720);

        // A session the user did not ask to persist still needs a server-side
        // expiry; it simply gets no cookie Max-Age, so it dies with the browser.
        return $remember ? $minutes * 60 : min($minutes, 720) * 60;
    }

    /**
     * Builds the Set-Cookie header.
     *
     * @param int|null $maxAge Null for a session cookie, 0 to delete.
     */
    private function buildCookie(string $value, ?int $maxAge): string
    {
        $parts = [
            $this->cookieName() . '=' . $value,
            'Path=' . $this->config->string('session.cookie.path', '/'),
            // Not readable from JavaScript, so XSS cannot exfiltrate it.
            'HttpOnly',
        ];

        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . $maxAge;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge);
        }

        $domain = $this->config->string('session.cookie.domain', '');
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        $sameSite = $this->config->string('session.cookie.same_site', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        $secure = $this->config->bool('session.cookie.secure', false);

        // SameSite=None is only honoured alongside Secure, so the pair is
        // enforced here rather than trusting the deployment to get it right.
        if ($sameSite === 'None') {
            $secure = true;
        }

        $parts[] = 'SameSite=' . $sameSite;

        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    /** @param array<string, mixed> $row */
    private function isIdle(array $row): bool
    {
        $idleMinutes = $this->config->int('session.idle_timeout_minutes', 0);

        if ($idleMinutes <= 0) {
            return false;
        }

        $lastActive = strtotime((string) $row['last_active_at'] . ' UTC');

        return $lastActive !== false && (time() - $lastActive) > ($idleMinutes * 60);
    }

    /** @param array<string, mixed> $row */
    private function touchIfStale(array $row, int $sessionId): void
    {
        $lastActive = strtotime((string) $row['last_active_at'] . ' UTC');

        if ($lastActive === false || (time() - $lastActive) < self::TOUCH_THROTTLE_SECONDS) {
            return;
        }

        $this->sessions->touch($sessionId, $this->lifetimeSeconds(true));
    }
}
