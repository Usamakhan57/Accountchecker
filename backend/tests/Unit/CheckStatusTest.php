<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Unit;

use AccountCheck\Checkers\CheckStatus;
use PHPUnit\Framework\TestCase;

/**
 * What a user is charged for.
 *
 * The rule is that a credit buys a check that was actually performed. A record
 * we could not check through an authorized source, and a record that errored on
 * our side, are both free. This is a billing rule, so it gets a test of its own
 * rather than being left implicit in a service.
 */
final class CheckStatusTest extends TestCase
{
    public function test_a_completed_check_is_billable_whatever_the_answer(): void
    {
        self::assertTrue(CheckStatus::Valid->isBillable());
        self::assertTrue(CheckStatus::Invalid->isBillable());
        self::assertTrue(CheckStatus::Unknown->isBillable(), 'A definite "we looked and cannot tell" was still a check.');
    }

    public function test_an_unavailable_record_costs_nothing(): void
    {
        self::assertFalse(
            CheckStatus::Unavailable->isBillable(),
            'No authorized source was available, so no check happened, so there is nothing to charge for.',
        );
    }

    public function test_an_error_on_our_side_costs_nothing(): void
    {
        self::assertFalse(CheckStatus::Error->isBillable());
    }

    public function test_every_case_answers_the_question(): void
    {
        foreach (CheckStatus::cases() as $case) {
            // No case may be left undecided: an unhandled status would default
            // to billable and quietly charge for something.
            self::assertIsBool($case->isBillable(), $case->name . ' must decide whether it is billable.');
        }
    }
}
