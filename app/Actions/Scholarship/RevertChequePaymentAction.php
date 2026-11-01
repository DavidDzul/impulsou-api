<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipRefrend;

/**
 * sdd/pago-cheque PR4 (spec R4, amendment A1): undoes a cheque payment on ONE
 * refrend. CHEQUE-only entry point that delegates to the generalized
 * RevertManualPaymentAction (sdd/catalogo-bancos R7), which enforces the
 * guards in order: cheque-paid (422 NOT_CHEQUE_PAID), cycle open (409
 * CYCLE_CLOSED), no Telmex repayments (409 TELMEX_REPAYMENT_EXISTS) and a
 * valid prior state in the latest CHEQUE_PAID log (422 CHEQUE_LOG_MISSING).
 */
class RevertChequePaymentAction
{
    public function __construct(private RevertManualPaymentAction $revert)
    {
    }

    /**
     * @throws ChequePaymentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(int $refrendId, string $reason): ScholarshipRefrend
    {
        return $this->revert->execute($refrendId, $reason, PaymentMethod::CHEQUE);
    }
}
