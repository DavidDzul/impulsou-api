<?php

namespace App\Actions\Scholarship;

use App\Enums\CycleClosureReason;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use Carbon\Carbon;

/**
 * sdd/egresado-baja-definitiva, design D4 (PR2a minimal scope). Owns every
 * EGRESADO/BAJA_DEFINITIVA cut side effect beyond the refrend's own money
 * fields (already set by RecordPaymentSituationAction's switch branches
 * before this is called): the profile stop marker (design D1/D2) and the
 * user-level effect (GraduateBecarioAction for EGRESADO, active=false for
 * BAJA_DEFINITIVA, design D10).
 *
 * PR2b scope (amendments #1987/#1988), deliberately NOT implemented here:
 * blocking-later-months guard (D7), pending-withholdings guard (D8), active
 * Telmex coverage guard (amendment 3), cascade-closing later refrends,
 * CYCLE_CLOSED logging (D13). Those guards run BEFORE this action in the
 * final design; until PR2b lands, a cut has no guards beyond
 * CYCLE_ALREADY_CLOSED — acceptable per the tasks artifact because only the
 * tracker branch merges to main.
 *
 * Tolerates a missing ScholarshipProfile: several call sites (unit tests
 * exercising only the money math in RecordPaymentSituationAction, and in
 * principle any becario without a profile row) never create one — the
 * marker is simply not persisted in that case, but the user-level effect
 * still runs. In production every becario reaching a resolution flow has a
 * profile (required for monthly_amount), so this is a defensive no-op, not
 * an expected path.
 */
class CloseScholarshipCycleAction
{
    public function __construct(private GraduateBecarioAction $graduate)
    {
    }

    public function execute(ScholarshipRefrend $refrend, string $reason, int $userId): void
    {
        $profile = ScholarshipProfile::where('user_id', $refrend->user_id)->first();

        if ($profile !== null) {
            // D10: a second cut on an already-closed profile is rejected —
            // the marker is set exactly once, by whichever cut happens first.
            if ($profile->cycle_closed_period !== null) {
                throw new ScholarshipCycleClosedException(
                    'Este becario ya tiene un ciclo cerrado previamente.'
                );
            }

            $profile->update([
                'cycle_closed_period'     => Carbon::create($refrend->period_year, $refrend->period_month, 1),
                'cycle_closure_reason'    => $reason,
                'cycle_closed_refrend_id' => $refrend->id,
            ]);
        }

        if ($reason === CycleClosureReason::EGRESADO->value) {
            $this->graduate->execute(
                $refrend->user,
                $refrend,
                'Egreso registrado vía cierre de ciclo (EGRESADO).',
                $userId
            );

            return;
        }

        $refrend->user()->update(['active' => false]);
    }
}
