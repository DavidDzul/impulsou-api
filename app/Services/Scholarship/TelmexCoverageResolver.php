<?php

namespace App\Services\Scholarship;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\TelmexCoverage;
use Carbon\Carbon;

/**
 * sdd/telmex-cobertura-iu, design D1: resolves the coverage (if any)
 * applicable to a becario's profile at a given reference date. Used by
 * ScholarshipCalculationService::buildSnapshot() to freeze
 * snapshot_telmex_coverage_id at generation/recalculation time —
 * deterministic by PERIOD, not by "status at generation", so regenerating
 * a past period always resolves the same coverage.
 */
class TelmexCoverageResolver
{
    /**
     * Returns the coverage applicable to `$profile` on `$on`'s month, or
     * null if none applies. Applicability (D1): status != CANCELADA,
     * start_period <= month(on), and (end_period NULL OR month(on) <=
     * end_period). Only TELMEX/TELMEX_IU profiles can ever resolve a
     * coverage — IU never does, short-circuited before any query.
     */
    public function resolve(ScholarshipProfile $profile, Carbon $on): ?TelmexCoverage
    {
        if (!in_array($profile->scholarship_type, [ScholarshipType::TELMEX, ScholarshipType::TELMEX_IU], true)) {
            return null;
        }

        $monthStart = $on->copy()->startOfMonth();

        // whereDate (not plain where): the model's 'date' cast persists
        // start_period/end_period as 'Y-m-d H:i:s' even on SQLite (Eloquent
        // always writes the connection's full date format on save), so a
        // raw string '<=' comparison against a bare 'Y-m-d' value would
        // incorrectly exclude an exact-month match. whereDate() normalizes
        // both sides to the date part before comparing.
        return TelmexCoverage::where('user_id', $profile->user_id)
            ->where('status', '!=', 'CANCELADA')
            ->whereDate('start_period', '<=', $monthStart->toDateString())
            ->where(function ($query) use ($monthStart) {
                $query->whereNull('end_period')
                    ->orWhereDate('end_period', '>=', $monthStart->toDateString());
            })
            ->first();
    }
}
