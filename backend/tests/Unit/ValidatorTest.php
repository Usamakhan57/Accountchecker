<?php

declare(strict_types=1);

namespace AccountCheck\Tests\Unit;

use AccountCheck\Core\HttpException;
use AccountCheck\Support\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Input validation.
 *
 * The false-boolean cases exist because of a real defect: rules signalled
 * failure by returning false, so a field legitimately set to false was
 * indistinguishable from a rejected one and was silently dropped with no error
 * raised. That silently disabled the admin panel's checker switch.
 */
final class ValidatorTest extends TestCase
{
    public function test_a_boolean_false_survives_validation(): void
    {
        $result = Validator::validate(['is_enabled' => false], ['is_enabled' => 'boolean']);

        self::assertArrayHasKey('is_enabled', $result, 'A valid false must not be dropped.');
        self::assertFalse($result['is_enabled']);
    }

    /**
     * @dataProvider falsyBooleans
     */
    public function test_falsy_representations_all_arrive_as_false(mixed $input): void
    {
        $result = Validator::validate(['flag' => $input], ['flag' => 'boolean']);

        self::assertArrayHasKey('flag', $result);
        self::assertFalse($result['flag']);
    }

    /** @return array<string, array{mixed}> */
    public static function falsyBooleans(): array
    {
        return [
            'php false' => [false],
            'string false' => ['false'],
            'zero int' => [0],
            'zero string' => ['0'],
            'off' => ['off'],
            'no' => ['no'],
        ];
    }

    public function test_a_zero_integer_survives_validation(): void
    {
        $result = Validator::validate(['credit_cost' => 0], ['credit_cost' => 'integer']);

        self::assertSame(0, $result['credit_cost']);
    }

    public function test_a_rejected_value_still_reports_an_error(): void
    {
        try {
            Validator::validate(['age' => 'not a number'], ['age' => 'required|integer']);
            self::fail('Expected validation to fail.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('age', $e->errors());
        }
    }

    public function test_a_missing_required_field_is_reported(): void
    {
        $this->expectException(HttpException::class);

        Validator::validate([], ['email' => 'required|email']);
    }

    public function test_an_absent_optional_field_is_simply_absent(): void
    {
        $result = Validator::validate(['kept' => 'yes'], ['kept' => 'string', 'missing' => 'string']);

        self::assertSame(['kept' => 'yes'], $result);
    }

    public function test_strings_are_trimmed_and_emails_lowercased(): void
    {
        $result = Validator::validate(
            ['name' => '  Spaced  ', 'email' => '  MixedCase@Example.COM '],
            ['name' => 'required|string', 'email' => 'required|email'],
        );

        self::assertSame('Spaced', $result['name']);
        self::assertSame('mixedcase@example.com', $result['email']);
    }

    public function test_the_in_rule_rejects_a_value_outside_the_list(): void
    {
        $this->expectException(HttpException::class);

        Validator::validate(['status' => 'DELETED'], ['status' => 'required|string|in:OPEN,CLOSED']);
    }

    public function test_confirmation_must_match(): void
    {
        $ok = Validator::validate(
            ['password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!'],
            ['password' => 'required|string|confirmed'],
        );
        self::assertSame('Str0ng-Passw0rd!', $ok['password']);

        $this->expectException(HttpException::class);
        Validator::validate(
            ['password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'something-else'],
            ['password' => 'required|string|confirmed'],
        );
    }
}
