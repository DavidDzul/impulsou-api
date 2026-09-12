<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Bounded length + charset validation only (spec's explicit YAGNI decision,
 * design D3): no RENAPO/SAT checksum or structural validation. RFC is
 * optional — null/absent always passes; format is only checked when a value
 * is present.
 *
 * NOTE: implemented as a classic Illuminate\Contracts\Validation\Rule
 * (passes()/message()) rather than an invokable rule — this project targets
 * Laravel 8.83, and the single-method invokable rule contract was
 * introduced in Laravel 9.
 */
class Rfc implements Rule
{
    private const PATTERN = '/^[A-Z0-9]{12,13}$/';

    public function passes($attribute, $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function message(): string
    {
        return 'El campo :attribute debe tener 12 o 13 caracteres alfanuméricos en mayúsculas.';
    }
}
