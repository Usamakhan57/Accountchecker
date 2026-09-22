<?php

declare(strict_types=1);

namespace AccountCheck\Support;

use AccountCheck\Core\HttpException;

/**
 * Rule-based input validation.
 *
 * Rules are declared per field as "required|string|max:255". Validation always
 * runs server-side; the frontend's own checks are a convenience only.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @return array<string, mixed> The validated subset of the input.
     * @throws HttpException 422 with per-field messages when anything fails.
     */
    public static function validate(array $data, array $rules): array
    {
        $validator = new self($data, $rules);

        if (!$validator->passes()) {
            throw HttpException::validation($validator->errors());
        }

        return $validator->validated();
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleString);
            $isOptional = in_array('nullable', $rules, true) || !in_array('required', $rules, true);

            if ($this->isEmpty($value)) {
                if (!$isOptional) {
                    $this->addError($field, $this->label($field) . ' is required.');
                }
                continue;
            }

            $failed = false;
            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable' || $rule === '') {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $result = $this->applyRule($field, $name, $parameter, $value);

                if ($result === self::failure()) {
                    $failed = true;
                    break;
                }

                $value = $result;
            }

            if (!$failed) {
                $this->validated[$field] = $value;
            }
        }

        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * @return mixed The (possibly cast) value, or the failure sentinel when the rule rejects it.
     */
    private function applyRule(string $field, string $name, ?string $parameter, mixed $value): mixed
    {
        $label = $this->label($field);

        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    return $this->fail($field, $label . ' must be text.');
                }

                return trim($value);

            case 'email':
                $email = is_string($value) ? trim($value) : '';
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                    return $this->fail($field, $label . ' must be a valid email address.');
                }

                return strtolower($email);

            case 'integer':
                $isWholeNumber = is_int($value)
                    || (is_string($value) && preg_match('/^-?\\d+$/', trim($value)) === 1);

                if (!$isWholeNumber) {
                    return $this->fail($field, $label . ' must be a whole number.');
                }

                return (int) $value;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->fail($field, $label . ' must be a number.');
                }

                return (float) $value;

            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                if (in_array($value, ['1', 1, 'true', 'on', 'yes'], true)) {
                    return true;
                }
                if (in_array($value, ['0', 0, 'false', 'off', 'no', ''], true)) {
                    return false;
                }

                return $this->fail($field, $label . ' must be true or false.');

            case 'array':
                if (!is_array($value)) {
                    return $this->fail($field, $label . ' must be a list.');
                }

                return $value;

            case 'min':
                $min = (float) $parameter;
                if (is_string($value) && mb_strlen($value) < $min) {
                    return $this->fail($field, $label . ' must be at least ' . (int) $min . ' characters.');
                }
                if (is_int($value) || is_float($value)) {
                    if ($value < $min) {
                        return $this->fail($field, $label . ' must be at least ' . $min . '.');
                    }
                }
                if (is_array($value) && count($value) < $min) {
                    return $this->fail($field, $label . ' must contain at least ' . (int) $min . ' items.');
                }

                return $value;

            case 'max':
                $max = (float) $parameter;
                if (is_string($value) && mb_strlen($value) > $max) {
                    return $this->fail($field, $label . ' must not exceed ' . (int) $max . ' characters.');
                }
                if (is_int($value) || is_float($value)) {
                    if ($value > $max) {
                        return $this->fail($field, $label . ' must not exceed ' . $max . '.');
                    }
                }
                if (is_array($value) && count($value) > $max) {
                    return $this->fail($field, $label . ' must not contain more than ' . (int) $max . ' items.');
                }

                return $value;

            case 'in':
                $allowed = explode(',', (string) $parameter);
                if (!in_array((string) (is_scalar($value) ? $value : ''), $allowed, true)) {
                    return $this->fail($field, $label . ' is not one of the allowed values.');
                }

                return $value;

            case 'regex':
                if (!is_string($value) || @preg_match('#' . $parameter . '#', $value) !== 1) {
                    return $this->fail($field, $label . ' has an invalid format.');
                }

                return $value;

            case 'date':
                $timestamp = is_string($value) ? strtotime($value) : false;
                if ($timestamp === false) {
                    return $this->fail($field, $label . ' must be a valid date.');
                }

                return gmdate('Y-m-d H:i:s', $timestamp);

            case 'confirmed':
                $other = $this->data[$field . '_confirmation'] ?? null;
                if (!is_string($other) || !hash_equals((string) $value, $other)) {
                    return $this->fail($field, $label . ' confirmation does not match.');
                }

                return $value;

            case 'password':
                return $this->validatePassword($field, $label, $value);

            default:
                return $value;
        }
    }

    private function validatePassword(string $field, string $label, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $this->fail($field, $label . ' must be text.');
        }

        $messages = [];
        if (strlen($value) < 10) {
            $messages[] = 'be at least 10 characters';
        }
        if (preg_match('/[a-z]/', $value) !== 1) {
            $messages[] = 'contain a lowercase letter';
        }
        if (preg_match('/[A-Z]/', $value) !== 1) {
            $messages[] = 'contain an uppercase letter';
        }
        if (preg_match('/\d/', $value) !== 1) {
            $messages[] = 'contain a number';
        }
        if (strlen($value) > 200) {
            $messages[] = 'be no longer than 200 characters';
        }

        if ($messages !== []) {
            return $this->fail($field, $label . ' must ' . implode(', ', $messages) . '.');
        }

        return $value;
    }

    /**
     * Records a failure and returns the sentinel.
     *
     * The sentinel is an object rather than `false` on purpose: a rule that
     * legitimately produces `false` (a boolean field set to false, and nothing
     * else in the language is as easy to get wrong here) would otherwise be
     * indistinguishable from a rule that rejected the value, and the field
     * would be dropped from the validated set without an error to show for it.
     */
    private function fail(string $field, string $message): object
    {
        $this->addError($field, $message);

        return self::failure();
    }

    /** The single instance compared by identity in passes(). */
    private static function failure(): object
    {
        static $sentinel = null;

        return $sentinel ??= new \stdClass();
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
