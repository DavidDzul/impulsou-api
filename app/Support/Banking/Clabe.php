<?php

namespace App\Support\Banking;

/**
 * Mexican CLABE helper: 18 digits, bank code (3) + plaza (3) + account (11)
 * + check digit. Check digit uses Banxico weights 3,7,1 (mod 10).
 */
final class Clabe
{
    private const WEIGHTS = [3, 7, 1];

    public static function isValid(?string $value): bool
    {
        if ($value === null || !preg_match('/^\d{18}\z/', $value)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += ((int) $value[$i] * self::WEIGHTS[$i % 3]) % 10;
        }

        return (int) $value[17] === (10 - $sum % 10) % 10;
    }

    /** Banxico bank code (first 3 digits), or null when it cannot be read. */
    public static function bankCode(?string $value): ?string
    {
        if ($value === null || !preg_match('/^\d{3}/', $value)) {
            return null;
        }

        return substr($value, 0, 3);
    }
}
