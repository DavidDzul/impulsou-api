<?php

namespace App\Actions\Scholarship;

use App\Models\TelmexCoverage;
use App\Models\TelmexCoveragePayment;
use App\Services\Scholarship\TelmexCoverageLedger;
use Illuminate\Support\Facades\DB;

/**
 * sdd/telmex-cobertura-iu, design D8 + tasks 3a.9. Reverts a single
 * repayment without affecting the coverage's other abonos — mirrors
 * VoidWithholdingPaymentAction. repaid is recomputed from the source-of-
 * truth child rows (never decremented directly), so voiding a settling
 * payment correctly reopens a LIQUIDADA coverage back to EN_COBRO.
 */
class VoidTelmexCoveragePaymentAction
{
    public function __construct(private TelmexCoverageLedger $ledger) {}

    public function execute(TelmexCoveragePayment $payment, string $reason, int $userId): TelmexCoveragePayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('El motivo de reversión es obligatorio.');
        }

        return DB::transaction(function () use ($payment, $reason, $userId) {
            // Lock order: abono -> cobertura, same spirit as
            // VoidWithholdingPaymentAction's abono -> retención -> refrendo.
            $payment = TelmexCoveragePayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->is_voided) {
                throw new \DomainException('Este abono ya fue revertido.');
            }

            $coverage = TelmexCoverage::lockForUpdate()->findOrFail($payment->coverage_id);
            if ($coverage->status === 'CANCELADA') {
                throw new \DomainException('No se pueden revertir abonos de una cobertura cancelada.');
            }

            $payment->update([
                'is_voided'    => true,
                'voided_at'    => now(),
                'voided_by_id' => $userId,
                'void_reason'  => $reason,
            ]);

            $this->ledger->recomputeStatus($coverage->fresh());

            return $payment->fresh();
        });
    }
}
