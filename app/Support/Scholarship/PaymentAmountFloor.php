<?php

namespace App\Support\Scholarship;

/**
 * Single shared payable-floor rule (sdd/bank-file-minimum-deposit, design
 * D1/D2/D3), reused by every `totalToPay()` consumer —
 * `PaymentBatchService::rows()` and `PaymentBatchService::paidRows()` — so
 * the rule exists exactly once. Final class, static, pure: no DB, no
 * framework side effects.
 *
 * When `snapshot_scholarship_type` is IU or TELMEX_IU and the computed
 * `total_to_pay` equals exactly `0.00`, the amount becomes `0.01`. The floor
 * MUST NOT apply to TELMEX rows (Filter A/B precedence unchanged) and MUST
 * NOT apply to any negative `total_to_pay` (stays a hard failure).
 *
 * Delegates TELMEX detection to
 * `TelmexPaymentPolicy::isExcludedFromBankFile()` (read-only consumption —
 * `TelmexPaymentPolicy` itself is never modified by this class) instead of
 * re-implementing the enum-cast/raw-string unwrap. This makes `apply()`
 * idempotent and order-insensitive: a pure-TELMEX row short-circuits on the
 * exclusion check and is returned unchanged regardless of where in the
 * call chain `apply()` runs, which is what keeps the `excluded_from_bank_file`
 * chip (PaymentBatchService::rows(), line ~233) from being inverted by this
 * floor (design risk R1).
 */
final class PaymentAmountFloor
{
    public const MINIMUM = '0.01';

    public static function apply(mixed $type, string $totalToPay): string
    {
        if (TelmexPaymentPolicy::isExcludedFromBankFile($type, $totalToPay)) {
            return $totalToPay;
        }

        return (float) $totalToPay === 0.0 ? self::MINIMUM : $totalToPay;
    }
}
