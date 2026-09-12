<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Bounded length + charset validation only (spec's explicit YAGNI decision,
 * design D3): no RENAPO checksum or structural (birthdate/state/homonym
 * digit) validation. Revisit only if a downstream consumer needs stricter
 * validation.
 *
 * NOTE: implemented as a classic Illuminate\Contracts\Validation\Rule
 * (passes()/message()) rather than an invokable rule — this project targets
 * Laravel 8.83, and the single-method invokable rule contract was
 * introduced in Laravel 9.
 */
class Curp implements Rule
{
    private const PATTERN = '/^[A-Z0-9]{18}$/';

    public function passes($attribute, $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function message(): string
    {
        return 'El campo :attribute debe tener exactamente 18 caracteres alfanuméricos en mayúsculas.';
    }
}
