<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Core\HttpException;
use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Services\AdminService;
use AccountCheck\Services\JobService;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * The rules that bound what an administrator can do.
 *
 * These are the guard rails that stop an admin panel becoming the way an
 * installation is lost: nobody can lock themselves out, nobody can hand
 * themselves a rank they do not have, and a wallet is moved through the ledger
 * rather than written to.
 */
final class AdminTest extends DatabaseTestCase
{
    private function admin(): AdminService
    {
        return $this->make(AdminService::class);
    }

    public function testAnOrdinaryUserCannotActOnAnybodysAccount(): void
    {
        $actor = $this->makeUser(email: 'plain@example.test');
        $target = $this->makeUser(email: 'target@example.test');
        $row = $this->admin()->findUser($target->uuid);

        foreach (
            [
                fn () => $this->admin()->setUserStatus($actor, $row, 'SUSPENDED'),
                fn () => $this->admin()->setUserRole($actor, $row, 'ADMIN'),
                fn () => $this->admin()->adjustWallet($actor, $row, 500, 'Because'),
            ] as $attempt
        ) {
            try {
                $attempt();
                $this->fail('An ordinary user got through an admin guard.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status());
                $this->assertSame('PERMISSION_DENIED', $e->errorCode());
            }
        }

        $this->assertSame('ACTIVE', (string) $this->database->scalar(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $target->id],
        ));
    }

    public function testAnAdministratorCannotSuspendOrDemoteTheirOwnAccount(): void
    {
        $admin = $this->makeUser('ADMIN', email: 'self@example.test');
        $self = $this->admin()->findUser($admin->uuid);

        foreach (
            [
                'status' => fn () => $this->admin()->setUserStatus($admin, $self, 'SUSPENDED'),
                'role' => fn () => $this->admin()->setUserRole($admin, $self, 'USER'),
            ] as $what => $attempt
        ) {
            try {
                $attempt();
                $this->fail('An administrator changed their own ' . $what . '.');
            } catch (HttpException $e) {
                $this->assertSame('SELF_ACTION_REFUSED', $e->errorCode());
            }
        }

        $this->assertSame('ACTIVE', (string) $this->database->scalar(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $admin->id],
        ));
    }

    public function testAnAdministratorCannotActOnASuperAdministrator(): void
    {
        $admin = $this->makeUser('ADMIN', email: 'admin@example.test');
        $super = $this->makeUser('SUPER_ADMIN', email: 'super@example.test');
        $row = $this->admin()->findUser($super->uuid);

        try {
            $this->admin()->setUserStatus($admin, $row, 'SUSPENDED');
            $this->fail('An administrator suspended a super administrator.');
        } catch (HttpException $e) {
            $this->assertSame('SUPER_ADMIN_REQUIRED', $e->errorCode());
        }

        $this->assertSame('ACTIVE', (string) $this->database->scalar(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $super->id],
        ));
    }

    public function testOnlyASuperAdministratorGrantsSuperAdministrator(): void
    {
        $admin = $this->makeUser('ADMIN', email: 'granter@example.test');
        $target = $this->makeUser(email: 'grantee@example.test');
        $row = $this->admin()->findUser($target->uuid);

        try {
            $this->admin()->setUserRole($admin, $row, 'SUPER_ADMIN');
            $this->fail('An administrator granted super administrator.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }

        $this->assertSame('USER', (string) $this->database->scalar(
            'SELECT r.slug FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = :id',
            ['id' => $target->id],
        ));
    }

    public function testOnlyASuperAdministratorChangesSystemSettings(): void
    {
        $admin = $this->makeUser('ADMIN', email: 'settings-admin@example.test');

        try {
            $this->admin()->updateSetting($admin, 'registration_enabled', '0');
            $this->fail('An administrator changed a system setting.');
        } catch (HttpException $e) {
            $this->assertSame('SUPER_ADMIN_REQUIRED', $e->errorCode());
        }

        $super = $this->makeUser('SUPER_ADMIN', email: 'settings-super@example.test');
        $this->admin()->updateSetting($super, 'registration_enabled', '0');

        $this->assertSame('0', (string) $this->database->scalar(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'registration_enabled'",
        ));

        // Put it back so the rest of the suite registers normally.
        $this->admin()->updateSetting($super, 'registration_enabled', '1');
    }

    public function testAWalletAdjustmentGoesThroughTheLedger(): void
    {
        $super = $this->makeUser('SUPER_ADMIN', email: 'ledger-admin@example.test');
        $target = $this->makeUser(credits: 100, email: 'ledger-target@example.test');
        $row = $this->admin()->findUser($target->uuid);

        $result = $this->admin()->adjustWallet($super, $row, 250, 'Support goodwill');

        $this->assertSame(350, (int) $result['balance']);

        $entry = $this->database->selectOne(
            "SELECT * FROM wallet_transactions WHERE user_id = :id AND type = 'ADJUSTMENT'",
            ['id' => $target->id],
        );

        $this->assertNotNull($entry, 'An adjustment left no ledger entry.');
        $this->assertSame(250, (int) $entry['amount']);
        $this->assertSame(350, (int) $entry['balance_after']);

        // The audit log carries who did it, which is the point of routing it
        // through the service rather than the repository.
        $this->assertSame(1, (int) $this->database->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE event = 'admin.wallet.adjusted' AND actor_id = :id",
            ['id' => $super->id],
        ));
    }

    public function testAnAdjustmentBeyondTheCapIsRefused(): void
    {
        $super = $this->makeUser('SUPER_ADMIN', email: 'cap-admin@example.test');
        $target = $this->makeUser(credits: 100, email: 'cap-target@example.test');
        $row = $this->admin()->findUser($target->uuid);

        foreach ([0, 5_000_000, -5_000_000] as $amount) {
            try {
                $this->admin()->adjustWallet($super, $row, $amount, 'Slipped digit');
                $this->fail('An adjustment of ' . $amount . ' was accepted.');
            } catch (HttpException $e) {
                $this->assertSame(400, $e->status());
            }
        }

        $this->assertSame(100, (int) ($this->make(WalletRepository::class)->find($target->id)['balance'] ?? 0));
    }

    public function testAnAdjustmentCannotTakeCreditsHeldByARunningJob(): void
    {
        $super = $this->makeUser('SUPER_ADMIN', email: 'hold-admin@example.test');
        $target = $this->makeUser(credits: 100, email: 'hold-target@example.test');

        // 80 of the 100 credits are now reserved against a queued job.
        $lines = [];
        for ($i = 0; $i < 80; $i++) {
            $lines[] = sprintf('hold.%03d@gmail.com', $i);
        }
        $this->make(JobService::class)->create($target, 'gmail', implode("\n", $lines));

        $row = $this->admin()->findUser($target->uuid);

        try {
            $this->admin()->adjustWallet($super, $row, -50, 'Clawback');
            $this->fail('An adjustment took credits a running job was holding.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame('INSUFFICIENT_CREDITS', $e->errorCode());
        }

        $wallet = $this->make(WalletRepository::class)->find($target->id) ?? [];
        $this->assertSame(100, (int) $wallet['balance']);
        $this->assertSame(80, (int) $wallet['reserved']);

        // The 20 that are not held can still be taken.
        $this->admin()->adjustWallet($super, $row, -20, 'Clawback');

        $wallet = $this->make(WalletRepository::class)->find($target->id) ?? [];
        $this->assertSame(80, (int) $wallet['balance']);
        $this->assertSame(0, (int) $wallet['available']);
    }

    public function testACheckerCanBeSwitchedOffFromThePanel(): void
    {
        $super = $this->makeUser('SUPER_ADMIN', email: 'checker-admin@example.test');

        $this->admin()->updateChecker($super, 'gmail', ['is_enabled' => false]);

        // The boolean false has to survive validation and reach the row; a
        // dropped false is the failure mode this pins down.
        $this->assertSame(0, (int) $this->database->scalar(
            "SELECT is_enabled FROM checker_types WHERE slug = 'gmail'",
        ));

        $this->admin()->updateChecker($super, 'gmail', ['is_enabled' => true]);

        $this->assertSame(1, (int) $this->database->scalar(
            "SELECT is_enabled FROM checker_types WHERE slug = 'gmail'",
        ));
    }

    public function testAnUnknownUserReferenceIsANotFound(): void
    {
        try {
            $this->admin()->findUser('e4f1a0de-0000-4000-8000-000000000000');
            $this->fail('An unknown user reference resolved.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status());
        }
    }
}
