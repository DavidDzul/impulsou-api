<?php

namespace App\Services\Users;

use App\Enums\WithholdingCancellationReason;
use App\Exceptions\CycleCutBlockedException;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\Scholarship\BecarioLockOrder;
use App\Services\Scholarship\CutAnchor;
use App\Services\Scholarship\CycleTerminationCanceller;
use Illuminate\Support\Facades\DB;

/**
 * sdd/retenciones-pendientes (R6): single place that persists an admin user
 * update. When the update flips `active` from true to false for a user that
 * has a ScholarshipProfile, the pending retentions and the advance payments
 * are cancelled (USER_DEACTIVATED, acting admin) in the SAME transaction as the
 * user update. A blocked advance (later month already locked/batched) rolls the
 * whole update back and surfaces CycleCutBlockedException.
 *
 * Every other case (false->false, false->true, `active` absent or unchanged,
 * users without a profile) only saves the user, exactly like before.
 * Reactivation never restores anything. The manual cut deactivates through a
 * query-builder update, so it never reaches this service and is not
 * processed twice.
 */
class UserDeactivationService
{
    public function __construct(private CycleTerminationCanceller $canceller)
    {
    }

    /**
     * @param array<string, mixed> $data validated attributes to fill
     * @return array{user: User, effects: array{withholdings_cancelled:int, advance_months_cancelled:int, overpaid_amount:string}|null}
     *
     * @throws CycleCutBlockedException
     */
    public function save(User $user, array $data, int $actorId): array
    {
        try {
            return DB::transaction(function () use ($user, $data, $actorId) {
                // Global lock order (see BecarioLockOrder): users row FIRST.
                // Serializes against RecordAdvancePaymentAction, which locks the
                // same row first and rejects inactive users.
                $locked = BecarioLockOrder::lockUser((int) $user->getKey());

                $isDeactivation = (bool) $locked->active
                    && array_key_exists('active', $data)
                    && $data['active'] === false;

                $locked->fill($data)->save();

                if (! $isDeactivation || ! ScholarshipProfile::where('user_id', $locked->id)->exists()) {
                    return ['user' => $locked, 'effects' => null];
                }

                $result = $this->canceller->cancel(
                    CutAnchor::forDeactivation((int) $locked->id, now()),
                    WithholdingCancellationReason::USER_DEACTIVATED,
                    $actorId
                );

                return [
                    'user'    => $locked,
                    'effects' => [
                        'withholdings_cancelled'   => $result->withholdings->count,
                        'advance_months_cancelled' => count($result->advancePlan->months),
                        'overpaid_amount'          => $result->advancePlan->overpaidAmount,
                    ],
                ];
            });
        } catch (CycleCutBlockedException $e) {
            throw $this->asDeactivationBlock($e);
        }
    }

    private function asDeactivationBlock(CycleCutBlockedException $e): CycleCutBlockedException
    {
        /** @var array<int, array{year:int,month:int,reason:string}> $months */
        $months = $e->payload()['blocking_months'];
        $labels = array_map(fn ($m) => sprintf('%02d/%d', $m['month'], $m['year']), $months);

        return new CycleCutBlockedException(
            sprintf(
                'No se puede desactivar al usuario: tiene %s pagado(s) por adelantado o con movimientos posteriores; resuélvelo(s) antes de desactivarlo.',
                implode(' y ', $labels)
            ),
            $months
        );
    }
}
