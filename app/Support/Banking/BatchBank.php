<?php

namespace App\Support\Banking;

use App\Enums\PaymentMethod;

/**
 * The bank whose fixed-width layout the payment batch file uses (BBVA, Banxico
 * code 012). Single place that decides which payment channel a becario is in.
 */
final class BatchBank
{
    public const CODE = '012';

    /**
     * CHEQUE stays CHEQUE; TRANSFER to BBVA stays TRANSFER (batch);
     * TRANSFER to another bank is a MANUAL_TRANSFER; TRANSFER without a bank
     * stays TRANSFER so the readiness evaluator blocks it as MISSING_BANK.
     */
    public static function channelFor(PaymentMethod $method, ?string $bankCode): PaymentMethod
    {
        if ($method !== PaymentMethod::TRANSFER) {
            return $method;
        }

        if ($bankCode === null || $bankCode === self::CODE) {
            return PaymentMethod::TRANSFER;
        }

        return PaymentMethod::MANUAL_TRANSFER;
    }
}
