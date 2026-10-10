<?php

namespace App\Actions\Scholarship;

use App\Models\TelmexCoverage;
use App\Services\Scholarship\TelmexCoverageLedger;
use App\Services\Scholarship\TelmexCoverageRefrendSync;
use Illuminate\Support\Facades\DB;

/**
 * sdd/telmex-cobertura-iu, decisions-2 #1921 dec3 (overrides design's
 * "cannot reactivate"). A CANCELADA coverage can be reactivated ONLY if no
 * covered month was ever paid — once a month was actually disbursed and
 * then cancelled, cancellation is final. Reuses the SAME row (UNIQUE
 * user_id prevents a second insert) and restores its original
 * start_period/end_period window via TelmexCoverageRefrendSync.
 */
class ReactivateTelmexCoverageAction
{
    public function __construct(
        private TelmexCoverageRefrendSync $sync,
        private TelmexCoverageLedger $ledger
    ) {}

    // $userId is accepted for signature parity with the other lifecycle
    // actions (controller passes the authenticated actor uniformly); there
    // is no dedicated reactivated_by_id column on the migration, so it is
    // not persisted here.
    public function execute(TelmexCoverage $coverage, int $userId): TelmexCoverage
    {
        return DB::transaction(function () use ($coverage) {
            $coverage = TelmexCoverage::lockForUpdate()->findOrFail($coverage->id);

            if ($coverage->status !== 'CANCELADA') {
                throw new \DomainException('Solo una cobertura cancelada puede reactivarse.');
            }

            if ($this->ledger->hasPaidCoveredMonth($coverage)) {
                throw new \DomainException(
                    'No se puede reactivar: ya se pagó al menos un mes cubierto bajo esta cobertura.'
                );
            }

            $coverage->update([
                'status'          => 'ACTIVA',
                'cancel_reason'   => null,
                'cancelled_by_id' => null,
                'cancelled_at'    => null,
                'settled_at'      => null,
            ]);

            $this->sync->sync($coverage->fresh());

            return $this->ledger->recomputeStatus($coverage->fresh());
        });
    }
}
