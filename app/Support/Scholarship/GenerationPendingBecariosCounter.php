<?php

namespace App\Support\Scholarship;

use App\Models\ScholarshipProfile;
use Carbon\Carbon;

/**
 * sdd/generaciones-activas-refrendos, R2: counts, per generation, the
 * becarios with pending payments as defined by
 * ScholarshipProfile::scopePendingPaymentsWindow() (the single source of
 * truth). One grouped query regardless of how many generations are asked for.
 */
class GenerationPendingBecariosCounter
{
    /**
     * @param  array<int, int>  $generationIds
     * @return array<int, int>  generation_id => pending count. Generations
     *                          with no pending becarios are ABSENT; callers
     *                          read with `?? 0`.
     */
    public static function countByGeneration(array $generationIds, ?Carbon $now = null): array
    {
        if ($generationIds === []) {
            return [];
        }

        return ScholarshipProfile::query()
            ->pendingPaymentsWindow($now)
            ->join('users', 'users.id', '=', 'scholarship_profiles.user_id')
            ->whereIn('users.generation_id', $generationIds)
            ->groupBy('users.generation_id')
            ->selectRaw('users.generation_id as generation_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'generation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public static function countFor(int $generationId, ?Carbon $now = null): int
    {
        return self::countByGeneration([$generationId], $now)[$generationId] ?? 0;
    }
}
