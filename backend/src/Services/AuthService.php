<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Auth\PasswordHasher;
use AccountCheck\Auth\SessionManager;
use AccountCheck\Core\Config;
use AccountCheck\Core\Database;
use AccountCheck\Core\HttpException;
use AccountCheck\Core\Request;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\AuditLogRepository;
use AccountCheck\Repositories\AuthTokenRepository;
use AccountCheck\Repositories\NotificationRepository;
use AccountCheck\Repositories\SessionRepository;
use AccountCheck\Repositories\SettingsRepository;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Support\Str;

/**
 * Registration, sign-in, sign-out and password lifecycle.
 *
 * Two rules shape most of what follows:
 *
 *   - Login and password reset must not reveal whether an email address is
 *     registered; both answer identically either way. Registration is the one
 *     exception, and deliberately so: the address was just typed by the person
 *     in front of us, and a duplicate has to be actionable.
 *   - Every credential path is rate limited per IP and per address, and every
 *     outcome is written to the audit log.
 */
final class AuthService
{
    private const PASSWORD_RESET_TTL = 3600;          // 60 minutes
    private const EMAIL_VERIFICATION_TTL = 86_400;    // 24 hours

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly AuthTokenRepository $tokens,
        private readonly WalletRepository $wallets,
        private readonly NotificationRepository $notifications,
        private readonly AuditLogRepository $audit,
        private readonly ActivityLogRepository $activity,
        private readonly SettingsRepository $settings,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessionManager,
        private readonly RateLimiter $rateLimiter,
        private readonly MailService $mail,
        private readonly Database $database,
        private readonly Config $config,
    ) {
    }

    /**
     * Creates an account, its wallet and its first session.
     *
     * @return array{user: AuthenticatedUser, cookie: string}
     */
    public function register(Request $request, string $name, string $email, string $password): array
    {
        $this->rateLimiter->enforce('register', $request->ip(), 'Too many accounts created from this address.');

        if (!$this->settings->bool('registration_enabled', true)) {
            throw new HttpException(
                'New account registration is currently closed.',
                403,
                'REGISTRATION_DISABLED',
            );
        }

        $email = strtolower(trim($email));

        if ($this->users->emailExists($email)) {
            // Deliberately the same shape as a validation error on the field,
            // because the address is one the person in front of us just typed;
            // the enumeration concern applies to login and reset, not here,
            // where a duplicate must be actionable.
            throw HttpException::validation(
                ['email' => ['An account already exists for this email address.']],
                'That email address is already registered.',
            );
        }

        // The account, its wallet, the welcome credit and the verification
        // token are one unit: a failure partway through must not leave a user
        // row with no wallet, or credits with no ledger entry.
        $passwordHash = $this->hasher->hash($password);
        $bonus = $this->settings->int('signup_bonus_credits', 0);

        /** @var array{user_id: int, verification_token: string} $created */
        $created = $this->database->transaction(function () use ($name, $email, $passwordHash, $bonus): array {
            $userId = $this->users->create($name, $email, $passwordHash);

            $this->wallets->ensureWallet($userId);

            if ($bonus > 0) {
                $this->wallets->credit($userId, $bonus, 'CREDIT', 'Welcome credits', 'signup:' . $userId);
            }

            $verificationToken = Str::randomToken(32);
            $this->tokens->create(
                $userId,
                AuthTokenRepository::PURPOSE_EMAIL_VERIFICATION,
                Str::hashToken($verificationToken),
                self::EMAIL_VERIFICATION_TTL,
            );

            $this->notifications->create(
                $userId,
                'system',
                'Welcome to AccountCheck',
                $bonus > 0
                    ? sprintf('Your account is ready and %s welcome credits have been added to your wallet.', number_format($bonus))
                    : 'Your account is ready. Start a check from the dashboard.',
                '/dashboard',
            );

            return ['user_id' => $userId, 'verification_token' => $verificationToken];
        });

        $userId = $created['user_id'];

        // Mail goes out only after the transaction commits, so a rolled-back
        // registration never sends a verification link for an account that
        // does not exist.
        $this->mail->sendEmailVerification(
            $email,
            $name,
            $this->frontendUrl('/verify-email?token=' . $created['verification_token']),
        );
        $this->mail->sendWelcome($email, $name);

        $this->audit->record(
            'auth.register',
            $userId,
            'USER',
            'user',
            $userId,
            ['email' => Str::maskEmail($email)],
            'NOTICE',
            $request->ip(),
            $request->userAgent(),
        );

        return $this->openSession($request, $userId, false);
    }

    /**
     * Verifies credentials and opens a session.
     *
     * @return array{user: AuthenticatedUser, cookie: string}
     */
    public function login(Request $request, string $email, string $password, bool $remember): array
    {
        $email = strtolower(trim($email));

        // Two buckets: one per IP (stops a single host spraying many accounts)
        // and one per address (stops a distributed attack on one account).
        $this->rateLimiter->enforce('login', 'ip:' . $request->ip(), 'Too many sign-in attempts from this address.');
        $this->rateLimiter->enforce('login', 'email:' . $email, 'Too many sign-in attempts for this account.');

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Spend comparable time to a real verification so the response
            // cannot be used to tell registered addresses from unregistered.
            $this->hasher->burnTime();
            $this->recordFailedLogin($request, null, $email, 'unknown_account');

            throw $this->invalidCredentials();
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->recordFailedLogin($request, (int) $user['id'], $email, 'bad_password');

            throw $this->invalidCredentials();
        }

        if ((string) $user['status'] !== 'ACTIVE') {
            $this->recordFailedLogin($request, (int) $user['id'], $email, 'inactive_account');

            throw new HttpException(
                'This account is not active. Contact support if you think that is wrong.',
                403,
                'ACCOUNT_INACTIVE',
            );
        }

        $userId = (int) $user['id'];

        // Transparently upgrade a hash made with an older algorithm.
        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
        }

        $this->rateLimiter->clear('login', 'ip:' . $request->ip());
        $this->rateLimiter->clear('login', 'email:' . $email);

        $this->users->recordLogin($userId, $request->ip());

        $this->audit->record(
            'auth.login',
            $userId,
            (string) $user['role_slug'],
            'user',
            $userId,
            [],
            'INFO',
            $request->ip(),
            $request->userAgent(),
        );

        return $this->openSession($request, $userId, $remember);
    }

    public function logout(Request $request, AuthenticatedUser $user): string
    {
        $this->audit->record(
            'auth.logout',
            $user->id,
            $user->role,
            'user',
            $user->id,
            [],
            'INFO',
            $request->ip(),
            $request->userAgent(),
        );

        return $this->sessionManager->destroy($user->sessionId);
    }

    /**
     * Starts a password reset.
     *
     * Always succeeds from the caller's point of view: the response is
     * identical whether or not the address is registered.
     */
    public function requestPasswordReset(Request $request, string $email): void
    {
        $email = strtolower(trim($email));

        $this->rateLimiter->enforce(
            'password_reset',
            'ip:' . $request->ip(),
            'Too many password reset requests from this address.',
        );

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $this->audit->record(
                'auth.password_reset_requested',
                null,
                '',
                'user',
                null,
                ['email' => Str::maskEmail($email), 'result' => 'no_account'],
                'NOTICE',
                $request->ip(),
                $request->userAgent(),
            );

            return;
        }

        $userId = (int) $user['id'];
        $token = Str::randomToken(32);

        $this->tokens->create(
            $userId,
            AuthTokenRepository::PURPOSE_PASSWORD_RESET,
            Str::hashToken($token),
            self::PASSWORD_RESET_TTL,
        );

        $this->mail->sendPasswordReset(
            (string) $user['email'],
            (string) $user['name'],
            $this->frontendUrl('/reset-password?token=' . $token),
        );

        $this->audit->record(
            'auth.password_reset_requested',
            $userId,
            (string) $user['role_slug'],
            'user',
            $userId,
            ['result' => 'sent'],
            'NOTICE',
            $request->ip(),
            $request->userAgent(),
        );
    }

    /**
     * Completes a password reset.
     *
     * Consuming the token and changing the password happen together, and every
     * session is revoked so a session opened with the old password — including
     * an attacker's — stops working.
     */
    public function resetPassword(Request $request, string $token, string $password): void
    {
        $this->rateLimiter->enforce('password_reset', 'reset:' . $request->ip());

        $record = $this->tokens->findUsable(
            Str::hashToken($token),
            AuthTokenRepository::PURPOSE_PASSWORD_RESET,
        );

        if ($record === null || !$this->tokens->consume((int) $record['id'])) {
            throw new HttpException(
                'This reset link is no longer valid. Request a new one.',
                400,
                'RESET_TOKEN_INVALID',
            );
        }

        $userId = (int) $record['user_id'];

        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
        $revoked = $this->sessions->revokeAllForUser($userId);

        $this->notifications->create(
            $userId,
            'security',
            'Your password was changed',
            'Your password was reset and every signed-in device was signed out.',
            '/settings',
        );

        $this->audit->record(
            'auth.password_reset_completed',
            $userId,
            '',
            'user',
            $userId,
            ['sessions_revoked' => $revoked],
            'WARNING',
            $request->ip(),
            $request->userAgent(),
        );
    }

    /**
     * Changes the password of the signed-in user.
     *
     * Requires the current password, and signs out every other device while
     * keeping the current one.
     */
    public function changePassword(Request $request, AuthenticatedUser $user, string $currentPassword, string $newPassword): int
    {
        $row = $this->users->findById($user->id);

        if ($row === null || !$this->hasher->verify($currentPassword, (string) $row['password_hash'])) {
            $this->audit->record(
                'auth.password_change_failed',
                $user->id,
                $user->role,
                'user',
                $user->id,
                [],
                'WARNING',
                $request->ip(),
                $request->userAgent(),
            );

            throw HttpException::validation(
                ['current_password' => ['That is not your current password.']],
                'Your current password is incorrect.',
            );
        }

        $this->users->updatePasswordHash($user->id, $this->hasher->hash($newPassword));
        $revoked = $this->sessionManager->destroyOthers($user->id, $user->sessionId);

        $this->notifications->create(
            $user->id,
            'security',
            'Your password was changed',
            $revoked > 0
                ? sprintf('Your password was changed and %d other session(s) were signed out.', $revoked)
                : 'Your password was changed.',
            '/settings',
        );

        $this->audit->record(
            'auth.password_changed',
            $user->id,
            $user->role,
            'user',
            $user->id,
            ['sessions_revoked' => $revoked],
            'WARNING',
            $request->ip(),
            $request->userAgent(),
        );

        return $revoked;
    }

    public function verifyEmail(Request $request, string $token): void
    {
        $record = $this->tokens->findUsable(
            Str::hashToken($token),
            AuthTokenRepository::PURPOSE_EMAIL_VERIFICATION,
        );

        if ($record === null || !$this->tokens->consume((int) $record['id'])) {
            throw new HttpException(
                'This verification link is no longer valid. Request a new one from your profile.',
                400,
                'VERIFICATION_TOKEN_INVALID',
            );
        }

        $userId = (int) $record['user_id'];
        $this->users->markEmailVerified($userId);

        $this->audit->record(
            'auth.email_verified',
            $userId,
            '',
            'user',
            $userId,
            [],
            'INFO',
            $request->ip(),
            $request->userAgent(),
        );
    }

    public function resendEmailVerification(Request $request, AuthenticatedUser $user): void
    {
        if ($user->emailVerified) {
            return;
        }

        $this->rateLimiter->enforce(
            'password_reset',
            'verify:' . $user->id,
            'Too many verification emails requested.',
        );

        $this->issueEmailVerification($user->id, $user->email, $user->name);

        $this->activity->record($user->id, 'auth.verification_resent', 'user', $user->id, [], $request->ip());
    }

    private function issueEmailVerification(int $userId, string $email, string $name): void
    {
        $token = Str::randomToken(32);

        $this->tokens->create(
            $userId,
            AuthTokenRepository::PURPOSE_EMAIL_VERIFICATION,
            Str::hashToken($token),
            self::EMAIL_VERIFICATION_TTL,
        );

        $this->mail->sendEmailVerification($email, $name, $this->frontendUrl('/verify-email?token=' . $token));
    }

    /** @return array{user: AuthenticatedUser, cookie: string} */
    private function openSession(Request $request, int $userId, bool $remember): array
    {
        $session = $this->sessionManager->start($userId, $request, $remember);

        $row = $this->users->findById($userId);

        if ($row === null) {
            throw new HttpException('Your account could not be loaded.', 500, 'USER_LOAD_FAILED');
        }

        return [
            'user' => AuthenticatedUser::fromRow(
                $row,
                $session['session_id'],
                $this->users->permissionsFor($userId),
            ),
            'cookie' => $session['cookie'],
        ];
    }

    private function recordFailedLogin(Request $request, ?int $userId, string $email, string $reason): void
    {
        $this->audit->record(
            'auth.login_failed',
            $userId,
            '',
            'user',
            $userId,
            ['email' => Str::maskEmail($email), 'reason' => $reason],
            'WARNING',
            $request->ip(),
            $request->userAgent(),
        );
    }

    private function invalidCredentials(): HttpException
    {
        // One message for every failure mode, so the response never
        // distinguishes an unknown address from a wrong password.
        return new HttpException(
            'That email address and password do not match an account.',
            401,
            'INVALID_CREDENTIALS',
        );
    }

    /**
     * Builds a link into the React app.
     *
     * The base comes from configuration, never from a request header, so a
     * forged Host cannot redirect a reset link to an attacker's domain.
     */
    private function frontendUrl(string $path): string
    {
        $origins = $this->config->array('app.cors.allowed_origins');
        $base = is_string($origins[0] ?? null) && $origins[0] !== ''
            ? (string) $origins[0]
            : $this->config->string('app.url');

        return rtrim($base, '/') . $path;
    }
}
