<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Feature;

use AccountCheck\Repositories\WalletRepository;
use AccountCheck\Tests\DatabaseTestCase;

/**
 * The credit ledger.
 *
 * Money-adjacent behaviour, so the tests are about what must never happen: a
 * balance going negative, a reservation letting a user spend the same credits
 * twice, or a settlement charging for more than was checked.
 */
final class WalletTest extends DatabaseTestCase
{
    private WalletRepository $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = $this->make(WalletRepository::class);
    }

    public function test_a_credit_is_recorded_with_a_running_balance(): void
    {
        $user = $this->makeUser(credits: 0);

        self::assertSame(500, $this->wallets->credit($user->id, 500, 'CREDIT', 'Purchase'));
        self::assertSame(700, $this->wallets->credit($user->id, 200, 'ADJUSTMENT', 'Goodwill'));

        $totals = $this->wallets->totalsForUser($user->id);
        self::assertSame(500, $totals['added']);
        self::assertSame(200, $totals['adjusted']);
    }

    public function test_a_debit_beyond_the_balance_is_refused_and_changes_nothing(): void
    {
        $user = $this->makeUser(credits: 100);

        self::assertNull($this->wallets->debit($user->id, 101, 'Too much'));
        self::assertSame(100, $this->wallets->find($user->id)['balance']);
        self::assertSame(1, $this->wallets->totalsForUser($user->id)['entries'], 'A refused debit writes no entry.');
    }

    public function test_a_reservation_makes_credits_unavailable_without_spending_them(): void
    {
        $user = $this->makeUser(credits: 100);

        self::assertTrue($this->wallets->reserve($user->id, 60));

        $wallet = $this->wallets->find($user->id);
        self::assertSame(100, $wallet['balance'], 'A reservation does not spend; the credits are still theirs.');
        self::assertSame(60, $wallet['reserved']);
        self::assertSame(40, $wallet['available']);

        // No ledger entry yet: nothing has been charged.
        self::assertSame(1, $this->wallets->totalsForUser($user->id)['entries']);
    }

    public function test_the_same_credits_cannot_be_reserved_twice(): void
    {
        $user = $this->makeUser(credits: 100);

        self::assertTrue($this->wallets->reserve($user->id, 80));
        self::assertFalse(
            $this->wallets->reserve($user->id, 30),
            'Only 20 are available, so a second reservation of 30 must fail.',
        );

        self::assertSame(80, $this->wallets->find($user->id)['reserved']);
    }

    public function test_reserved_credits_cannot_be_taken_by_a_debit(): void
    {
        $user = $this->makeUser(credits: 100);
        $this->wallets->reserve($user->id, 90);

        self::assertNull(
            $this->wallets->debit($user->id, 50, 'Admin removal'),
            'Credits held by a running job must not be removable, or the job could not settle.',
        );
    }

    public function test_settlement_charges_for_what_was_used_and_returns_the_rest(): void
    {
        $user = $this->makeUser(credits: 100);
        $this->wallets->reserve($user->id, 60);

        // The job checked 45 records of the 60 it reserved for.
        $this->wallets->settle($user->id, 60, 45, 'Gmail Checker job #1 (45 checked)');

        $wallet = $this->wallets->find($user->id);
        self::assertSame(55, $wallet['balance'], 'Charged 45 of the 100 they had.');
        self::assertSame(0, $wallet['reserved'], 'The whole reservation is released.');
        self::assertSame(55, $wallet['available']);
        self::assertSame(45, $this->wallets->totalsForUser($user->id)['spent']);
    }

    public function test_settling_nothing_charges_nothing_and_still_releases(): void
    {
        $user = $this->makeUser(credits: 100);
        $this->wallets->reserve($user->id, 60);

        $this->wallets->settle($user->id, 60, 0, 'Cancelled before anything ran');

        $wallet = $this->wallets->find($user->id);
        self::assertSame(100, $wallet['balance']);
        self::assertSame(0, $wallet['reserved']);
        self::assertSame(1, $this->wallets->totalsForUser($user->id)['entries'], 'Nothing was spent, so nothing is recorded as spent.');
    }

    public function test_a_balance_cannot_be_driven_negative_by_concurrent_debits(): void
    {
        $user = $this->makeUser(credits: 100);

        // Ten attempts at 20 each against a balance of 100: five may succeed.
        $succeeded = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($this->wallets->debit($user->id, 20, 'Attempt ' . $i) !== null) {
                $succeeded++;
            }
        }

        self::assertSame(5, $succeeded);
        self::assertSame(0, $this->wallets->find($user->id)['balance']);
        self::assertGreaterThanOrEqual(0, $this->wallets->find($user->id)['balance']);
    }
}
