<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Services\Scholarship\AttendanceWindow;
use App\Services\Scholarship\RefrendAttendanceSummaryBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecalculateRefrendService
{
    public function __construct(
        private ScholarshipCalculationService $calculationService,
        private AttendancePenaltyService $penaltyService,
        private ScholarshipLoggingService $loggingService,
        private RefrendAttendanceSummaryBuilder $attendanceSummaryBuilder
    ) {}

    /**
     * Full recalculation pipeline. Only allowed in DRAFT state.
     * Refreshes all snapshots, clears and re-applies auto discounts,
     * then recalculates final amount. Preserves the same refrend_id.
     *
     * @throws \DomainException if the refrend is not in DRAFT.
     */
    public function fullRecalculate(ScholarshipRefrend $refrend): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'DRAFT') {
            throw new \DomainException('Solo se pueden recalcular refrendos en estado DRAFT.');
        }

        $profile       = ScholarshipProfile::where('user_id', $refrend->user_id)->firstOrFail();
        $user          = $profile->user()->with('roles')->first();
        $year          = $refrend->period_year;
        $month         = $refrend->period_month;
        $referenceDate = Carbon::create($year, $month, 1);

        // Reference date of the attendance window: the original generation
        // moment (reproducible). Advance-payment refrends keep their legacy
        // reference (period 1st) and never get the absence penalty.
        // created_via defaults to GENERATION in the DB; a model instance that
        // was just created in-process may not carry the default yet.
        $isGeneration = ($refrend->created_via ?? 'GENERATION') === 'GENERATION';
        $window       = $isGeneration && $refrend->created_at !== null
            ? AttendanceWindow::endingAt($refrend->created_at)
            : AttendanceWindow::endingAt($referenceDate);

        return DB::transaction(function () use ($refrend, $profile, $user, $referenceDate, $window, $isGeneration) {
            $old = $this->loggingService->snapshotRefrend($refrend);

            // 1. Refresh non-attendance snapshots. Vigencia (temporary increase
            // + discount) must be evaluated against the refrend's period, not
            // "today" — otherwise recalculating an old DRAFT would use today's
            // date to decide whether an increase/discount applies.
            $snapshot  = $this->calculationService->buildSnapshot($profile, $referenceDate);
            $lastGrade = $this->getLastSemesterGrade($refrend->user_id);

            $hasProfileDiscount = isset($snapshot['snapshot_discount_percentage'])
                && $snapshot['snapshot_discount_percentage'] !== null
                && (float) $snapshot['snapshot_discount_percentage'] > 0;

            // Incidents are permanent, so a human-filed one (created_by_id set) must
            // keep the refrend in CON_INCIDENCIA across recalculations. Machine rows
            // (created_by_id NULL) are re-derived below from the profile discount.
            $hasHumanIncident = $refrend->incidents()->whereNotNull('created_by_id')->exists();

            $newWorkflowStatus   = ($hasProfileDiscount || $hasHumanIncident) ? 'CON_INCIDENCIA' : 'DRAFT';
            $incidentDescription = null;

            if ($hasProfileDiscount) {
                $pct        = $snapshot['snapshot_discount_percentage'];
                $reason     = $snapshot['snapshot_discount_reason'] ?? null;
                $validUntil = $profile->discount_valid_until
                    ? Carbon::parse($profile->discount_valid_until)->format('d/m/Y')
                    : null;

                $parts = ["Descuento académico del {$pct}%"];
                if ($validUntil) {
                    $parts[] = "vigente hasta {$validUntil}";
                }
                if ($reason) {
                    $parts[] = "Motivo: {$reason}";
                }
                $incidentDescription = implode(', ', $parts) . '.';
            }

            $refrend->update([
                'snapshot_name'               => $snapshot['snapshot_name'],
                'snapshot_generation'         => $snapshot['snapshot_generation'],
                'snapshot_generation_id'      => $snapshot['snapshot_generation_id'] ?? null,
                'snapshot_campus'             => $snapshot['snapshot_campus'],
                'snapshot_scholarship_type'   => $snapshot['snapshot_scholarship_type'],
                'snapshot_gross_amount'        => $snapshot['snapshot_gross_amount'],
                'snapshot_monto_apoyo'         => $snapshot['snapshot_monto_apoyo'],
                // sdd/telmex-cobertura-iu, design D2/PR1 bugfix: pre-existing
                // gap — buildSnapshot() always computed these, Recalculate
                // never persisted them (verified 2026-10-09).
                'snapshot_telmex_covered_amount' => $snapshot['snapshot_telmex_covered_amount'],
                'snapshot_telmex_coverage_id'    => $snapshot['snapshot_telmex_coverage_id'],
                'snapshot_temporary_increase_amount' => $snapshot['snapshot_temporary_increase_amount'],
                'snapshot_temporary_increase_reason' => $snapshot['snapshot_temporary_increase_reason'],
                'base_amount'                  => $snapshot['base_amount'],
                'snapshot_discount_percentage' => $snapshot['snapshot_discount_percentage'],
                'snapshot_discount_reason'     => $snapshot['snapshot_discount_reason'],
                'average_grade_snapshot'       => $lastGrade,
                'workflow_status'             => $newWorkflowStatus,
                // Reset any manual amount override so recalculation starts clean
                'discount_percentage'         => 0,
                'discount_amount'             => 0,
                'final_amount'                => $snapshot['base_amount'],
            ]);

            // Idempotency: this incident is machine-generated and re-derived below, so
            // it must be cleared first — same unconditional-delete-then-reapply contract
            // as the discount rows in steps 2 and 3. Without this, every recalculation of
            // a becario with an active profile discount appends a duplicate (reachable via
            // ClearRefrendResolutionAction, which sets DRAFT then calls us). The
            // created_by_id IS NULL clause guarantees we only ever delete rows WE created
            // and never a human-filed incident that happens to share the type.
            $refrend->incidents()
                ->where('incident_type', 'DESCUENTO_PERFIL')
                ->whereNull('created_by_id')
                ->delete();

            // Invariant: a refrend has at most ONE incident. A human-filed incident
            // supersedes the machine discount note (the discount itself still applies
            // through the snapshot), so the machine row is only created when no human
            // incident exists.
            if ($hasProfileDiscount && !$hasHumanIncident && $incidentDescription !== null) {
                $refrend->incidents()->create([
                    'incident_category' => 'ACADEMICO',
                    'incident_type'     => 'DESCUENTO_PERFIL',
                    'description'       => $incidentDescription,
                    'priority'          => 'LOW',
                    'created_by_id'     => null,
                ]);
            }

            // 2. Remove RETARDOS discounts (cascade removes late consumptions automatically).
            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->where('discount_type', DiscountType::RETARDOS->value)
                ->delete();

            // 3. Remove academic and automatic absence discounts. A FALTA row is
            // automatic only when it owns consumption rows (cascade frees the
            // absences); manual retentions have none and must survive.
            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->where(function ($q) {
                    $q->where('discount_type', DiscountType::PROMEDIO_BAJO->value)
                        ->orWhere(function ($q) {
                            $q->where('discount_type', DiscountType::FALTA_INJUSTIFICADA->value)
                                ->whereHas('lateConsumptions');
                        });
                })
                ->delete();

            // 4. Re-apply automatic discounts on a fresh instance
            $fresh = $refrend->fresh();
            $this->penaltyService->applyPenaltyIfDue($fresh, $user, 100.0, $window);
            if ($isGeneration) {
                $this->penaltyService->applyAbsencePenaltyIfDue($fresh, $user, $window);
            }

            // 4.5 Compute attendance snapshot AFTER penalties are re-applied so
            // late_unconsumed reflects the actual post-recalculation state.
            $attendanceSummary = $this->attendanceSummaryBuilder->build($refrend->user_id, $window, $refrend->id);
            $fresh->update(['attendance_summary_snapshot' => $attendanceSummary]);

            // 5. Recalculate final amount from all remaining discounts
            $fresh = $this->calculationService->recalculate($fresh);

            // 6. Log the recalculation
            $this->loggingService->log(
                $fresh,
                'FULL_RECALCULATE',
                $old,
                $this->loggingService->snapshotRefrend($fresh)
            );

            return $fresh;
        });
    }

    private function getLastSemesterGrade(int $userId): ?float
    {
        $row = DB::table('scholarship_semester_grades')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('grade');

        return $row !== null ? (float) $row : null;
    }
}
