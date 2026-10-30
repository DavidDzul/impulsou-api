<?php

namespace App\Services\Scholarship;

/**
 * sdd/retenciones-pendientes: immutable outcome of PendingWithholdingCanceller.
 * totalRemaining is the sum of the (withheld - paid) balances that were
 * cancelled, as a 2-decimal string.
 */
final class WithholdingCancellationResult
{
    /**
     * @param array<int, int> $withholdingIds ids cancelled by this call, ascending
     */
    public function __construct(
        public readonly int $count,
        public readonly array $withholdingIds,
        public readonly string $totalRemaining
    ) {
    }

    public static function empty(): self
    {
        return new self(0, [], '0.00');
    }
}
