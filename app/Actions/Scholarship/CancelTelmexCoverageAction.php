<?php

namespace App\Actions\Scholarship;

use App\Models\TelmexCoverage;
use App\Services\Scholarship\TelmexCoverageRefrendSync;
use Illuminate\Support\Facades\DB;

/**
 * sdd/telmex-cobertura-iu, amendment #1918 am1 + tasks 3a.6. Cancels an
 * ACTIVA/EN_COBRO coverage. A reason is ALWAYS mandatory, even when nothing
 * was ever paid — there is no separate "CONDONADA" state; CANCELADA covers
 * both the mistaken-activation case and the "becario left / Telmex never
 * paid" case, and history (advanced/repaid) is always preserved, the
 * remaining balance just gets labeled "cancelado" by the ledger/UI layer.
 */
class CancelTelmexCoverageAction
{
    public function __construct(private TelmexCoverageRefrendSync $sync) {}

    public function execute(TelmexCoverage $coverage, string $reason, int $userId): TelmexCoverage
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new \DomainException('El motivo de cancelación debe tener entre 10 y 500 caracteres.');
        }

        return DB::transaction(function () use ($coverage, $reason, $userId) {
            $coverage = TelmexCoverage::lockForUpdate()->findOrFail($coverage->id);

            if ($coverage->status === 'CANCELADA') {
                throw new \DomainException('Esta cobertura ya está cancelada.');
            }
            if ($coverage->status === 'LIQUIDADA') {
                throw new \DomainException('Esta cobertura ya está liquidada; no se puede cancelar.');
            }

            $coverage->update([
                'status'          => 'CANCELADA',
                'cancel_reason'   => $reason,
                'cancelled_by_id' => $userId,
                'cancelled_at'    => now(),
            ]);

            // Status is already CANCELADA on the fresh instance, so sync's
            // isWithinWindow() detaches every still-detachable (unpaid,
            // unlocked, unresolved) covered row — paid/locked history stays
            // linked and visible as "saldo cancelado".
            $this->sync->sync($coverage->fresh());

            return $coverage->fresh();
        });
    }
}
