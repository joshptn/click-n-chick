<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The application's password policy, in one place.
 *
 * Deliberately not Laravel's Password::mixedCase(), which also demands a
 * lowercase letter - the stated policy is 8+ characters with an uppercase
 * letter, a number and a symbol, and 'PASSWORD1!' satisfies that.
 *
 * The failure message names every unmet requirement at once so a caller is not
 * walked through them one rejection at a time.
 */
class StrongPassword implements ValidationRule
{
    public const MIN_LENGTH = 8;

    /** Anything that is not a letter or a digit counts as a symbol. */
    private const SYMBOL_PATTERN = '/[^A-Za-z0-9]/';

    /**
     * The policy in display form, mirrored by the register form's checklist.
     *
     * @return array<int, string>
     */
    public static function requirements(): array
    {
        return [
            'At least '.self::MIN_LENGTH.' characters',
            'One uppercase letter',
            'One number',
            'One symbol',
        ];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid password.');

            return;
        }

        $unmet = [];

        if (mb_strlen($value) < self::MIN_LENGTH) {
            $unmet[] = 'be at least '.self::MIN_LENGTH.' characters long';
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $unmet[] = 'contain an uppercase letter';
        }

        if (! preg_match('/\d/', $value)) {
            $unmet[] = 'contain a number';
        }

        if (! preg_match(self::SYMBOL_PATTERN, $value)) {
            $unmet[] = 'contain a symbol';
        }

        if ($unmet !== []) {
            $fail('The password must '.implode(', ', $unmet).'.');
        }
    }
}
