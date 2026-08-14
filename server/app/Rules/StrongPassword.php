<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    public const MIN_LENGTH = 8;

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

        if ($unmet !== []) {
            $fail('The password must '.implode(', ', $unmet).'.');
        }
    }
}
