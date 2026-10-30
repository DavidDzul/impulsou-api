<?php

namespace App\Services\Scholarship;

use App\Models\User;

/**
 * Canonical lock-acquisition order for any transaction that touches a becario.
 *
 * GLOBAL ORDER (InnoDB takes row locks in statement order, so two paths that
 * lock the same rows in opposite order can deadlock, error 1213):
 *
 *   1. the becario's `users` row           <- always FIRST, via lockUser()
 *   2. the origin ScholarshipRefrend
 *   3. the ScholarshipProfile
 *   4. withholdings / later refrends (by id)
 *   5. advance header, then its months
 *
 * Paths that lock or update `users` (directly, or indirectly by graduating /
 * deactivating the becario) MUST call lockUser() at the very start of their
 * transaction. Paths that never touch `users` keep their own order from
 * step 2 on. Users: UserDeactivationService, RecordAdvancePaymentAction,
 * RecordPaymentSituationAction (terminal cycle cuts), and the automatic egreso
 * in GenerateMonthlyRefrendsService.
 */
final class BecarioLockOrder
{
    /**
     * Must be called inside the caller's DB::transaction, before any other
     * lock of that transaction.
     */
    public static function lockUser(int $userId): User
    {
        return User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }
}
