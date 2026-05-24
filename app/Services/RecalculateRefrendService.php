<?php

namespace App\Services;

use App\Models\ScholarshipRefrend;

class RecalculateRefrendService
{
    public function __construct(
        private ScholarshipCalculationService $calculationService
    ) {}

    /**
     * Recalculates discount and final amount for a non-locked refrend.
     *
     * @throws \DomainException if the refrend is locked and cannot be modified.
     */
    public function recalculate(ScholarshipRefrend $refrend): ScholarshipRefrend
    {
        if (in_array($refrend->status, ['PAID', 'CANCELLED'], true)) {
            throw new \DomainException('El refrendo está cerrado y no puede recalcularse.');
        }

        return $this->calculationService->recalculate($refrend);
    }
}
