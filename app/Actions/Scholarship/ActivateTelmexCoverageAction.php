<?php

namespace App\Actions\Scholarship;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\TelmexCoverage;
use App\Services\Scholarship\TelmexCoverageRefrendSync;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * sdd/telmex-cobertura-iu, design D7 + tasks 3a.4. Activates a brand-new
 * coverage for a becario with NO existing coverage row at all (any status).
 * scholarship_telmex_coverages has a UNIQUE(user_id) constraint — a becario
 * whose only row is CANCELADA must go through ReactivateTelmexCoverageAction
 * instead, which reuses that row rather than inserting a second one.
 */
class ActivateTelmexCoverageAction
{
    public function __construct(private TelmexCoverageRefrendSync $sync) {}

    public function execute(array $data, int $userId): TelmexCoverage
    {
        $profile = ScholarshipProfile::where('user_id', $data['user_id'])->firstOrFail();

        if (!in_array($profile->scholarship_type, [ScholarshipType::TELMEX, ScholarshipType::TELMEX_IU], true)) {
            throw new \DomainException('Solo becarios TELMEX o TELMEX_IU pueden tener cobertura.');
        }

        $monthlyAmount = (float) $profile->monthly_amount;
        $montoApoyo    = (float) ($profile->monto_apoyo ?? 0);
        if ($monthlyAmount + $montoApoyo <= 0) {
            throw new \DomainException(
                'El becario debe tener monthly_amount + monto_apoyo mayor a cero para activar la cobertura.'
            );
        }

        $startPeriod = Carbon::parse($data['start_period'])->startOfMonth();

        return DB::transaction(function () use ($profile, $data, $startPeriod, $userId) {
            // Locked inside the transaction to close the check-then-act window
            // against a concurrent activation for the same becario (same
            // precedent as RecordAdvancePaymentAction's existing-batch guard).
            $existing = TelmexCoverage::where('user_id', $profile->user_id)->lockForUpdate()->first();
            if ($existing) {
                throw new \DomainException(
                    $existing->status === 'CANCELADA'
                        ? 'Este becario ya tiene una cobertura cancelada; use reactivar en vez de activar una nueva.'
                        : 'Este becario ya tiene una cobertura vigente.'
                );
            }

            $coverage = TelmexCoverage::create([
                'user_id'                        => $profile->user_id,
                'scholarship_type_at_activation'  => $profile->scholarship_type->value,
                'start_period'                    => $startPeriod,
                'end_period'                      => null,
                'status'                          => 'ACTIVA',
                'notes'                           => $data['notes'] ?? null,
                'activated_by_id'                 => $userId,
            ]);

            $this->sync->sync($coverage->fresh());

            return $coverage->fresh();
        });
    }
}
