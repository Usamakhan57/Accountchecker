<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Core\HttpException;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Services\AuthService;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * Registration and sign-in.
 *
 * The properties worth pinning down here are the ones a refactor could quietly
 * break without any test noticing: that the password never exists in the
 * database as text, that a wrong password and an unknown address are answered
 * identically, and that a session is a server-side row rather than something
 * the cookie asserts on its own.
 */
final class AuthTest extends DatabaseTestCase
{
    private const PASSWORD = 'Str0ng-Passw0rd!';

    private function auth(): AuthService
    {
        return $this->make(AuthService::class);
    }

    public function testRegistrationStoresOnlyAHashOfThePassword(): void
    {
        $email = 'new-user@example.test';

        $this->auth()->register($this->makeRequest(), 'New User', $email, self::PASSWORD);

        $stored = (string) $this->database->scalar(
            'SELECT password_hash FROM users WHERE email = :email',
            ['email' => $email],
        );

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored);
        $this->assertTrue(password_verify(self::PASSWORD, $stored));

        // And nothing else on the row carries it either.
        $row = $this->database->selectOne('SELECT * FROM users WHERE email = :email', ['email' => $email]) ?? [];

        foreach ($row as $column => $value) {
            if ($column === 'password_hash' || !is_string($value)) {
                continue;
            }

            $this->assertStringNotContainsString(self::PASSWORD, $value, $column . ' contains the password');
        }
    }

    public function testRegistrationGivesTheAccountAWalletAndASession(): void
    {
        $result = $this->auth()->register($this->makeRequest(), 'New User', 'wallet@example.test', self::PASSWORD);

        $userId = $result['user']->id;

        $this->assertNotSame('', $result['cookie']);
        $this->assertSame(1, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM wallets WHERE user_id = :id',
            ['id' => $userId],
        ));
        $this->assertSame(1, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = :id',
            ['id' => $userId],
        ));
    }

    public function testRegisteringAnAddressTwiceCreatesNoSecondAccount(): void
    {
        $email = 'taken@example.test';
        $this->auth()->register($this->makeRequest(), 'First', $email, self::PASSWORD);

        try {
            $this->auth()->register($this->makeRequest(ip: '203.0.113.11'), 'Second', $email, self::PASSWORD);
            $this->fail('A duplicate registration should not succeed.');
        } catch (HttpException $e) {
            // Registration tells the person the address is taken on purpose:
            // they just typed it, and a duplicate has to be actionable. The
            // non-disclosure rule applies to login and password reset, which
            // the tests below cover.
            $this->assertSame(422, $e->status());
        }

        $this->assertSame(1, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM users WHERE email = :email',
            ['email' => $email],
        ));
    }

    public function testPasswordResetDoesNotRevealWhetherAnAddressIsRegistered(): void
    {
        $this->auth()->register($this->makeRequest(), 'Known', 'reset-known@example.test', self::PASSWORD);

        // Neither call may throw, and neither may say anything different from
        // the other: the caller cannot tell the two apart.
        $this->auth()->requestPasswordReset($this->makeRequest(ip: '198.51.100.1'), 'reset-known@example.test');
        $this->auth()->requestPasswordReset($this->makeRequest(ip: '198.51.100.2'), 'reset-nobody@example.test');

        $this->assertSame(0, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM users WHERE email = :email',
            ['email' => 'reset-nobody@example.test'],
        ));
    }

    public function testLoginAnswersAWrongPasswordAndAnUnknownAddressTheSameWay(): void
    {
        $this->auth()->register($this->makeRequest(), 'Known', 'known@example.test', self::PASSWORD);

        $wrongPassword = null;
        $unknownAddress = null;

        try {
            $this->auth()->login($this->makeRequest(ip: '203.0.113.21'), 'known@example.test', 'Wr0ng-Passw0rd!', false);
        } catch (HttpException $e) {
            $wrongPassword = $e;
        }

        try {
            $this->auth()->login($this->makeRequest(ip: '203.0.113.22'), 'nobody@example.test', 'Wr0ng-Passw0rd!', false);
        } catch (HttpException $e) {
            $unknownAddress = $e;
        }

        $this->assertNotNull($wrongPassword);
        $this->assertNotNull($unknownAddress);
        $this->assertSame($wrongPassword->getMessage(), $unknownAddress->getMessage());
        $this->assertSame($wrongPassword->status(), $unknownAddress->status());
        $this->assertSame($wrongPassword->errorCode(), $unknownAddress->errorCode());
    }

    public function testLoginSucceedsWithTheRightPassword(): void
    {
        $this->auth()->register($this->makeRequest(), 'Known', 'signin@example.test', self::PASSWORD);

        $result = $this->auth()->login(
            $this->makeRequest(ip: '203.0.113.31'),
            'signin@example.test',
            self::PASSWORD,
            false,
        );

        $this->assertSame('signin@example.test', $result['user']->email);
        $this->assertNotSame('', $result['cookie']);
    }

    public function testRepeatedFailedLoginsAreRateLimited(): void
    {
        $this->auth()->register($this->makeRequest(), 'Known', 'bruteforce@example.test', self::PASSWORD);

        $limited = false;

        // Far more attempts than any sane threshold; the point is that the
        // attempts stop being answered, not the exact number allowed.
        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                $this->auth()->login(
                    $this->makeRequest(ip: '203.0.113.41'),
                    'bruteforce@example.test',
                    'Wr0ng-Passw0rd!',
                    false,
                );
            } catch (HttpException $e) {
                if ($e->status() === 429) {
                    $limited = true;
                    break;
                }
            }
        }

        $this->assertTrue($limited, 'Repeated failed sign-ins were never rate limited.');

        // The correct password is refused too while the limit holds, so the
        // lockout cannot be walked around by simply guessing right.
        $this->expectException(HttpException::class);
        $this->auth()->login(
            $this->makeRequest(ip: '203.0.113.41'),
            'bruteforce@example.test',
            self::PASSWORD,
            false,
        );
    }

    public function testLogoutRevokesTheSessionServerSide(): void
    {
        $registered = $this->auth()->register($this->makeRequest(), 'Leaver', 'logout@example.test', self::PASSWORD);
        $user = $registered['user'];

        $before = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = :id AND revoked_at IS NULL',
            ['id' => $user->id],
        );
        $this->assertSame(1, $before);

        $token = $this->database->scalar(
            'SELECT token_hash FROM user_sessions WHERE user_id = :id',
            ['id' => $user->id],
        );
        $this->assertNotNull($token);

        $this->auth()->logout(
            $this->makeRequest(cookies: $this->sessionCookie($registered['cookie'])),
            $user,
        );

        $this->assertSame(0, (int) $this->database->scalar(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = :id AND revoked_at IS NULL',
            ['id' => $user->id],
        ));
    }

    public function testChangingThePasswordInvalidatesTheOldOne(): void
    {
        $registered = $this->auth()->register($this->makeRequest(), 'Rotator', 'rotate@example.test', self::PASSWORD);

        $this->auth()->changePassword(
            $this->makeRequest(cookies: $this->sessionCookie($registered['cookie'])),
            $registered['user'],
            self::PASSWORD,
            'An0ther-Passw0rd!',
        );

        $users = $this->make(UserRepository::class);
        $row = $users->findById($registered['user']->id) ?? [];

        $this->assertFalse(password_verify(self::PASSWORD, (string) $row['password_hash']));
        $this->assertTrue(password_verify('An0ther-Passw0rd!', (string) $row['password_hash']));
    }

    /**
     * Turns a Set-Cookie value into the cookie jar a later request would send.
     *
     * @return array<string, string>
     */
    private function sessionCookie(string $setCookie): array
    {
        $pair = explode(';', $setCookie, 2)[0];
        [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

        return [trim($name) => urldecode($value)];
    }
}
