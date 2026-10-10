<?php

namespace App\Actions\Scholarship;

use App\Models\TelmexCoverage;
use App\Models\TelmexCoveragePayment;
use App\Services\Scholarship\TelmexCoverageLedger;
use Illuminate\Support\Facades\DB;

/**
 * sdd/telmex-cobertura-iu, design D8 + tasks 3a.8 (status guard narrowed by
 * decisions-3 #1926 in PR3b). Records a becario's repayment (an external
 * deposit once Telmex pays the accumulated months, client-answers ca2/ca3
 * — never a deduction from future payments). Allowed ONLY on EN_COBRO —
 * the becario repays IU when Telmex deposits the accumulated months, which
 * by definition cannot have happened yet while still ACTIVA (Telmex hasn't
 * even started paying). Amount must be positive and must never exceed the
 * current balance (no over-repayment).
 */
class RegisterTelmexCoveragePaymentAction
{
    public function __construct(private TelmexCoverageLedger $ledger) {}

    public function execute(TelmexCoverage $coverage, array $data, int $userId): TelmexCoveragePayment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \DomainException('El monto del abono debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($coverage, $data, $amount, $userId) {
            $coverage = TelmexCoverage::lockForUpdate()->findOrFail($coverage->id);

            if ($coverage->status !== 'EN_COBRO') {
                throw new \DomainException(
                    'Solo se pueden registrar abonos cuando la cobertura está en cobro (Telmex ya empezó a pagar).'
                );
            }

            $balance = $this->ledger->balance($coverage);
            if ($amount > $balance + TelmexCoverageLedger::EPSILON) {
                throw new \DomainException('El abono no puede exceder el saldo pendiente.');
            }

            $payment = $coverage->payments()->create([
                'amount'        => number_format($amount, 2, '.', ''),
                'paid_at'       => $data['paid_at'] ?? now()->toDateString(),
                'reference'     => $data['reference'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'created_by_id' => $userId,
            ]);

            $this->ledger->recomputeStatus($coverage->fresh());

            return $payment->fresh();
        });
    }
}
