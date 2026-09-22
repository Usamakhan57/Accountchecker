<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Auth\SessionManager;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Services\AuthService;
use AccountCheck\Services\SessionPayloadService;

/**
 * Registration, sign-in and password endpoints.
 *
 * Session responses all carry the same payload — user, wallet and unread
 * notification count — so the client has everything it needs from one call.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionManager $sessions,
        private readonly SessionPayloadService $sessionPayload,
    ) {
    }

    public function register(Request $request): Response
    {
        $input = $this->validate($request, [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email',
            'password' => 'required|password|confirmed',
        ]);

        $result = $this->auth->register(
            $request,
            (string) $input['name'],
            (string) $input['email'],
            (string) $input['password'],
        );

        return Response::created(
            $this->sessionPayload->build($result['user']),
            'Your account is ready.',
        )->withHeader('Set-Cookie', $result['cookie']);
    }

    public function login(Request $request): Response
    {
        $input = $this->validate($request, [
            'email' => 'required|email',
            // No password rules here: an existing password made under older
            // rules must still be usable to sign in.
            'password' => 'required|string|max:200',
            'remember' => 'nullable|boolean',
        ]);

        $result = $this->auth->login(
            $request,
            (string) $input['email'],
            (string) $input['password'],
            (bool) ($input['remember'] ?? false),
        );

        return Response::success(
            $this->sessionPayload->build($result['user']),
            'Signed in.',
        )->withHeader('Set-Cookie', $result['cookie']);
    }

    /**
     * Signing out is deliberately a public route so an expired or already
     * revoked session still clears the cookie rather than answering 401. The
     * session is resolved here instead of by middleware, and the response is
     * the same either way.
     */
    public function logout(Request $request): Response
    {
        $user = $this->sessions->resolve($request);

        if (!$user instanceof AuthenticatedUser) {
            return Response::success(null, 'Signed out.')
                ->withHeader('Set-Cookie', $this->sessions->expiredCookie());
        }

        $cookie = $this->auth->logout($request, $user);

        return Response::success(null, 'Signed out.')->withHeader('Set-Cookie', $cookie);
    }

    public function forgotPassword(Request $request): Response
    {
        $input = $this->validate($request, ['email' => 'required|email']);

        $this->auth->requestPasswordReset($request, (string) $input['email']);

        // Identical response whether or not the address is registered.
        return Response::success(
            null,
            'If that address has an account, a reset link is on its way.',
        );
    }

    public function resetPassword(Request $request): Response
    {
        $input = $this->validate($request, [
            'token' => 'required|string|min:32|max:128',
            'password' => 'required|password|confirmed',
        ]);

        $this->auth->resetPassword($request, (string) $input['token'], (string) $input['password']);

        return Response::success(
            null,
            'Your password has been changed. Sign in with your new password.',
        );
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->user($request);

        $input = $this->validate($request, [
            'current_password' => 'required|string|max:200',
            'password' => 'required|password|confirmed',
        ]);

        $revoked = $this->auth->changePassword(
            $request,
            $user,
            (string) $input['current_password'],
            (string) $input['password'],
        );

        return Response::success(
            ['other_sessions_signed_out' => $revoked],
            $revoked > 0
                ? 'Password changed. Your other devices have been signed out.'
                : 'Password changed.',
        );
    }

    public function verifyEmail(Request $request): Response
    {
        $input = $this->validate($request, ['token' => 'required|string|min:32|max:128']);

        $this->auth->verifyEmail($request, (string) $input['token']);

        return Response::success(null, 'Your email address is verified.');
    }

    public function resendVerification(Request $request): Response
    {
        $this->auth->resendEmailVerification($request, $this->user($request));

        return Response::success(null, 'If your address still needs verifying, a new link is on its way.');
    }
}
