<?php

namespace App\Services;

use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyRefrendsService
{
    private ScholarshipCalculationService $calculationService;
    private AttendancePenaltyService $penaltyService;

    public function __construct(
        ScholarshipCalculationService $calculationService,
        AttendancePenaltyService $penaltyService
    ) {
        $this->calculationService = $calculationService;
        $this->penaltyService     = $penaltyService;
    }

    /**
     * Genera refrendos DRAFT para todos los becarios activos con perfil de beca
     * para el periodo indicado. Idempotente: si ya existe, lo omite.
     *
     * @return array{created: int, skipped: int, errors: int}
     */
    public function generateForPeriod(
        int $year,
        int $month,
        ?string $campus = null,
        ?int $generationId = null
    ): array {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        /** @var \Illuminate\Support\Collection<int, ScholarshipProfile> $profiles */
        // Deliberately does NOT filter by user_type — a becario whose
        // reticula ended but is still inside the 2-month "egreso
        // administrativo" grace window must keep getting generated/paid
        // 100% for those remaining months, even if user_type was separately
        // flipped away from BEC_ACTIVE. Only active=false ("permanently
        // withdrawn", set by RecordPaymentSituationAction's BAJA_DEFINITIVA)
        // stops generation — mirrors generateForUser()'s own guard.
        $profiles = ScholarshipProfile::with('user')
            ->whereHas('user', function ($q) use ($campus, $generationId) {
                $q->where('active', true);
                if ($campus !== null) {
                    $q->where('campus', $campus);
                }
                if ($generationId !== null) {
                    $q->where('generation_id', $generationId);
                }
            })
            ->get();

        foreach ($profiles as $profile) {
            try {
                $result = $this->generateForUser($profile, $year, $month);
                $result ? $stats['created']++ : $stats['skipped']++;
            } catch (\Throwable $e) {
                Log::error("Error generando refrendo para user_id={$profile->user_id}", [
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Genera el refrendo de un becario para el periodo dado.
     * Retorna el refrendo creado o null si ya existía.
     * Lanza DomainException si el periodo está fuera del rango de la retícula.
     */
    public function generateForUser(
        ScholarshipProfile $profile,
        int $year,
        int $month
    ): ?ScholarshipRefrend {
        // Verificar si ya existe el refrendo NORMAL para ese periodo
        $exists = ScholarshipRefrend::where('user_id', $profile->user_id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('refrend_type', RefrendType::NORMAL->value)
            ->exists();

        if ($exists) {
            return null;
        }

        $this->assertUserIsActive($profile);

        // Validar que el periodo no sea futuro y esté dentro del rango de la
        // retícula. generateForUser() keeps ZERO new parameters and cannot be
        // opted out of the future-period guard — the only sanctioned bypass
        // is the separately named generateFutureForAdvance() below.
        $this->assertPeriodNotInFuture($year, $month);
        $this->assertPeriodWithinReticula($profile, $year, $month);

        return $this->createRefrendForPeriod($profile, $year, $month, 'GENERATION');
    }

    /**
     * Genera un refrendo para un periodo FUTURO, exclusivamente para el flujo
     * de pago adelantado (RecordAdvancePaymentAction). Requiere que el
     * periodo sea estrictamente posterior al mes en curso — lo opuesto de
     * generateForUser() — y sigue respetando el rango de la retícula, para
     * que un becario no pueda ser adelantado un periodo fuera de su ventana
     * académica.
     *
     * A diferencia de generateForUser(), este método NO es idempotente por
     * sí mismo (no hace exists()-skip): la deduplicación y el control de
     * concurrencia son responsabilidad del llamador (RecordAdvancePaymentAction),
     * que ya realiza esos checks dentro de su propia transacción.
     *
     * @param string $createdVia Provenance marker for the created refrend.
     *   Not yet persisted — `scholarship_refrends.created_via` does not exist
     *   until PR2's migration lands. Accepted now so this call site's
     *   contract is stable and PR2 only needs to wire the column write,
     *   not change this signature.
     */
    public function generateFutureForAdvance(
        ScholarshipProfile $profile,
        int $year,
        int $month,
        string $createdVia = 'ADVANCE_PAYMENT'
    ): ScholarshipRefrend {
        $this->assertUserIsActive($profile);
        $this->assertPeriodIsFuture($year, $month);
        $this->assertPeriodWithinReticula($profile, $year, $month);

        return $this->createRefrendForPeriod($profile, $year, $month, $createdVia);
    }

    /**
     * generateForPeriod() (bulk) already filters active=true in its initial
     * query, but generateForUser() itself never re-checked it — reachable
     * directly via the per-becario generate endpoint (no UI button today,
     * but a live API route), which could otherwise generate a refrend for a
     * becario already given de baja (users.active=false, set by
     * RecordPaymentSituationAction's BAJA_DEFINITIVA). Deliberately checks
     * ONLY `active`, not user_type: a becario can legitimately graduate
     * (user_type changing away from BEC_ACTIVE, a separate, still-undecided
     * concern) while still owed pending months within their reticula window
     * — `active=false` alone means "permanently withdrawn, stop everything."
     * Shared by both generateForUser() and generateFutureForAdvance().
     */
    private function assertUserIsActive(ScholarshipProfile $profile): void
    {
        $user = $profile->user;
        if (!$user || !$user->active) {
            throw new \DomainException(
                'No se puede generar el refrendo: el becario no está activo.'
            );
        }
    }

    /**
     * Crea el refrendo (snapshot, incidente de descuento, penalizaciones,
     * recálculo) para un periodo ya validado por el llamador. Extraído
     * verbatim del cuerpo de generateForUser() para que generateForUser() y
     * generateFutureForAdvance() compartan exactamente la misma lógica de
     * creación, parametrizada únicamente por cómo se originó la fila.
     */
    private function createRefrendForPeriod(
        ScholarshipProfile $profile,
        int $year,
        int $month,
        string $createdVia
    ): ScholarshipRefrend {
        $referenceDate = Carbon::create($year, $month, 1);
        // Vigencia (temporary increase + discount) must be evaluated against
        // the period being generated, not "today" — otherwise generating a
        // refrend for a past period would use today's date to decide whether
        // an increase/discount applies.
        $snapshot      = $this->calculationService->buildSnapshot($profile, $referenceDate);

        // Freeze academic snapshot at generation time
        $lastGrade       = $this->getLastSemesterGrade($profile->user_id);
        $attendanceSummary = $this->getAttendanceSummaryForPeriod(
            $profile->user_id,
            $year,
            $month
        );

        $hasProfileDiscount = isset($snapshot['snapshot_discount_percentage'])
            && $snapshot['snapshot_discount_percentage'] !== null
            && (float) $snapshot['snapshot_discount_percentage'] > 0;

        $initialWorkflowStatus = $hasProfileDiscount ? 'CON_INCIDENCIA' : 'DRAFT';
        $incidentDescription   = null;

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

        // NOTE: $createdVia is accepted but intentionally NOT written to the
        // `ScholarshipRefrend::create()` call below — `created_via` doesn't
        // exist as a column until PR2's migration. PR2 only needs to add
        // `'created_via' => $createdVia` to the array below; no signature
        // changes required here or on either public caller.
        return DB::transaction(function () use ($profile, $year, $month, $snapshot, $referenceDate, $lastGrade, $attendanceSummary, $initialWorkflowStatus, $incidentDescription, $hasProfileDiscount) {
            $refrend = ScholarshipRefrend::create([
                'user_id'                      => $profile->user_id,
                'period_year'                  => $year,
                'period_month'                 => $month,
                'refrend_type'                 => RefrendType::NORMAL->value,
                'status'                       => RefrendStatus::DRAFT->value,
                'workflow_status'              => $initialWorkflowStatus,
                'resolution_type'              => null,
                'snapshot_gross_amount'        => $snapshot['snapshot_gross_amount'],
                'snapshot_monto_apoyo'         => $snapshot['snapshot_monto_apoyo'],
                'snapshot_temporary_increase_amount' => $snapshot['snapshot_temporary_increase_amount'],
                'snapshot_temporary_increase_reason' => $snapshot['snapshot_temporary_increase_reason'],
                'base_amount'                  => $snapshot['base_amount'],
                'snapshot_discount_percentage' => $snapshot['snapshot_discount_percentage'],
                'snapshot_discount_reason'     => $snapshot['snapshot_discount_reason'],
                'discount_percentage'          => 0,
                'discount_amount'              => 0,
                'final_amount'                 => $snapshot['base_amount'],
                // Replaced by the retention ledger (scholarship_withholdings):
                // recalculating this here on every generation reinjected debt
                // already represented by ledger rows. New refrends always start
                // at 0; liquidation happens exclusively via withholding_payments[].
                'amount_pending_from_previous'  => 0,
                'snapshot_name'                => $snapshot['snapshot_name'],
                'snapshot_generation'          => $snapshot['snapshot_generation'],
                'snapshot_generation_id'       => $snapshot['snapshot_generation_id'] ?? null,
                'snapshot_campus'              => $snapshot['snapshot_campus'],
                'snapshot_scholarship_type'    => $snapshot['snapshot_scholarship_type'],
                'average_grade_snapshot'       => $lastGrade,
                'missing_subjects_snapshot'    => 0,
                'attendance_summary_snapshot'  => $attendanceSummary,
            ]);

            if ($hasProfileDiscount && $incidentDescription !== null) {
                $refrend->incidents()->create([
                    'incident_category' => 'ACADEMICO',
                    'incident_type'     => 'DESCUENTO_PERFIL',
                    'description'       => $incidentDescription,
                    'priority'          => 'LOW',
                    'created_by_id'     => null,
                ]);
            }

            // Evaluar penalizaciones automáticas de asistencia
            $user = $profile->user;
            $this->penaltyService->applyPenaltyIfDue($refrend, $user, 100.0, $referenceDate);
            $this->penaltyService->applyAbsencePenaltyIfDue($refrend, $user, $year, $month);

            // Recalcular montos finales
            $this->calculationService->recalculate($refrend);

            return $refrend->fresh();
        });
    }

    /**
     * Valida que el periodo (año/mes) NO sea futuro respecto al mes en curso.
     * Restored, committed guard — the sanctioned exception is
     * generateFutureForAdvance(), which uses the inverse assertPeriodIsFuture()
     * instead. No other call site may bypass this.
     */
    private function assertPeriodNotInFuture(int $year, int $month): void
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $currentMonthStart = Carbon::now()->startOfMonth();

        if ($periodStart->gt($currentMonthStart)) {
            throw new \DomainException(
                "No se puede generar el refrendo de {$month}/{$year} porque ese periodo aún no ha comenzado."
            );
        }
    }

    /**
     * Valida que el periodo (año/mes) SEA estrictamente futuro respecto al
     * mes en curso. Inverso de assertPeriodNotInFuture(); usado únicamente
     * por generateFutureForAdvance().
     */
    private function assertPeriodIsFuture(int $year, int $month): void
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $currentMonthStart = Carbon::now()->startOfMonth();

        if (!$periodStart->gt($currentMonthStart)) {
            throw new \DomainException(
                "No se puede registrar un pago adelantado para {$month}/{$year} porque ese periodo no es futuro."
            );
        }
    }

    /**
     * Valida que el periodo (año/mes) esté dentro del rango de la retícula del perfil.
     * Lanza DomainException si está fuera del rango.
     */
    private function assertPeriodWithinReticula(ScholarshipProfile $profile, int $year, int $month): void
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();

        if ($profile->reticula_start_date && $periodStart->lt($profile->reticula_start_date)) {
            throw new \DomainException(
                "El periodo {$month}/{$year} es anterior al inicio de la retícula ({$profile->reticula_start_date->toDateString()})."
            );
        }

        $egresoAdministrativo = $profile->reticula_end_date
            ? Carbon::parse($profile->reticula_end_date)->addMonths(2)
            : null;

        if ($egresoAdministrativo && $periodStart->gt($egresoAdministrativo)) {
            throw new \DomainException(
                "El periodo {$month}/{$year} está fuera del periodo académico. El egreso administrativo (fin de retícula + 2 meses) venció el {$egresoAdministrativo->toDateString()}."
            );
        }
    }

    /**
     * Returns the most recent semester grade for the user, or null if none exists.
     */
    private function getLastSemesterGrade(int $userId): ?float
    {
        $row = DB::table('scholarship_semester_grades')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('grade');

        return $row !== null ? (float) $row : null;
    }

    /**
     * Builds an attendance summary snapshot for the given period.
     * Frozen at generation time — only updated on explicit recalculate.
     *
     * @return array{present: int, late: int, absent: int, late_unconsumed: int, total: int, month_absent: int}
     */
    private function getAttendanceSummaryForPeriod(
        int $userId,
        int $year,
        int $month
    ): array {
        $start = $month <= 7
            ? Carbon::create($year, 1, 1)->toDateString()
            : Carbon::create($year, 8, 1)->toDateString();

        $monthPadded      = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $periodMonthStart = "{$year}-{$monthPadded}-01";
        // Bound parameter instead of DB::raw('CURDATE()') — CURDATE() is MySQL-only
        // syntax and errors ("no such function: CURDATE") against the SQLite
        // in-memory connection used by the test suite (phpunit.xml). Same semantics
        // in production (MySQL): "today", computed once so all three queries below
        // agree even across a midnight rollover during a slow request.
        $today = now()->toDateString();

        $rows = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('classes.date', '>=', $start)
            ->where('classes.date', '<=', $today)
            ->selectRaw('attendances.status, COUNT(*) as cnt')
            ->groupBy('attendances.status')
            ->get();

        $summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'late_unjustified' => 0];

        foreach ($rows as $row) {
            $cnt = (int) $row->cnt;
            match ($row->status) {
                'PRESENT'           => $summary['present'] += $cnt,
                'LATE'              => [$summary['late'] += $cnt, $summary['late_unjustified'] += $cnt],
                'JUSTIFIED_LATE'    => $summary['late'] += $cnt,
                'ABSENT'            => $summary['absent'] += $cnt,
                'JUSTIFIED_ABSENCE' => $summary['absent'] += $cnt,
                default             => null,
            };
        }

        $lateConsumed = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->join('scholarship_late_consumptions', 'scholarship_late_consumptions.attendance_id', '=', 'attendances.id')
            ->where('attendances.user_id', $userId)
            ->where('attendances.status', 'LATE')
            ->where('classes.date', '>=', $start)
            ->where('classes.date', '<=', $today)
            ->count();

        $monthAbsent = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('attendances.status', 'ABSENT')
            ->where('classes.date', '>=', $periodMonthStart)
            ->where('classes.date', '<=', $today)
            ->count();

        return [
            'present'         => $summary['present'],
            'late'            => $summary['late'],
            'absent'          => $summary['absent'],
            'late_unconsumed' => max(0, $summary['late_unjustified'] - $lateConsumed),
            'total'           => $summary['present'] + $summary['late'] + $summary['absent'],
            'month_absent'    => (int) $monthAbsent,
        ];
    }

}
