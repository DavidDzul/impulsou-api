<?php

namespace App\Services;

use App\Actions\Scholarship\GraduateBecarioAction;
use App\Exceptions\ReticulaPeriodOutOfRangeException;
use App\Exceptions\ScholarshipConfigPendingException;
use App\Exceptions\ScholarshipReticulaMissingException;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\CutAnchor;
use App\Services\Scholarship\CycleTerminationCanceller;
use App\Enums\WithholdingCancellationReason;
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
    private GraduateBecarioAction $graduateBecario;
    private CycleTerminationCanceller $terminationCanceller;

    public function __construct(
        ScholarshipCalculationService $calculationService,
        AttendancePenaltyService $penaltyService,
        GraduateBecarioAction $graduateBecario,
        CycleTerminationCanceller $terminationCanceller
    ) {
        $this->calculationService   = $calculationService;
        $this->penaltyService       = $penaltyService;
        $this->graduateBecario      = $graduateBecario;
        $this->terminationCanceller = $terminationCanceller;
    }

    /**
     * Genera refrendos DRAFT para todos los becarios activos con perfil de beca
     * para el periodo indicado. Idempotente: si ya existe, lo omite.
     *
     * sdd/verificacion-incidencias-ux, PR1: `skipped` keeps its original
     * meaning ("already existed"); a becario whose period falls outside
     * their retícula window is now counted separately under
     * `skipped_out_of_reticula` (reporting-only, not a real error) instead
     * of being lumped into `errors`. `failures[]` adds one entry per
     * becario NOT created (both kinds), for UI-level detail. `errors` keeps
     * counting every other \Throwable exactly as before.
     *
     * sdd/perfil-beca-montos-admin: a profile whose config_set_at marker is
     * NULL is counted under `skipped_config_pending` (always present, 0 when
     * none) with a failures[] entry of the same kind; not an error.
     *
     * sdd/egresar-boton-corte-ciclo: a profile with no reticula_start_date or
     * no reticula_end_date is counted under `skipped_reticula_missing` (always
     * present, 0 when none) with a failures[] entry of the same kind; not an
     * error.
     *
     * @return array{created: int, skipped: int, skipped_out_of_reticula: int, skipped_config_pending: int, skipped_reticula_missing: int, errors: int, failures: array<int, array{user_id: int, name: string, reason: string, kind: string}>}
     */
    public function generateForPeriod(
        int $year,
        int $month,
        ?string $campus = null,
        ?int $generationId = null
    ): array {
        $stats = [
            'created'                  => 0,
            'skipped'                  => 0,
            'skipped_out_of_reticula'  => 0,
            'skipped_config_pending'   => 0,
            'skipped_reticula_missing' => 0,
            'errors'                   => 0,
            'failures'                 => [],
        ];

        /** @var \Illuminate\Support\Collection<int, ScholarshipProfile> $profiles */
        // Deliberately does NOT filter by user_type — a becario whose
        // reticula ended but is still inside the 2-month "egreso
        // administrativo" grace window must keep getting generated/paid
        // 100% for those remaining months, even if user_type was separately
        // flipped away from BEC_ACTIVE. Only active=false ("permanently
        // withdrawn", set by RecordPaymentSituationAction's BAJA_DEFINITIVA)
        // stops generation — mirrors generateForUser()'s own guard.
        // sdd/egresado-baja-definitiva, design D3: openForPeriod() excludes a
        // becario whose cycle_closed_period stop marker is strictly before
        // this period, the same way the active=true filter above already
        // excludes a permanently-withdrawn becario.
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
            ->openForPeriod($year, $month)
            ->get();

        foreach ($profiles as $profile) {
            try {
                $result = $this->generateForUser($profile, $year, $month);
                $result ? $stats['created']++ : $stats['skipped']++;
            } catch (ReticulaPeriodOutOfRangeException $e) {
                $stats['skipped_out_of_reticula']++;
                $stats['failures'][] = $this->buildFailureEntry($profile, $e, 'skipped_out_of_reticula');
            } catch (ScholarshipConfigPendingException $e) {
                // sdd/perfil-beca-montos-admin: amounts never saved by
                // Administración — an expected skip, not an error (no
                // Log::error, `errors`/`skipped` untouched).
                $stats['skipped_config_pending']++;
                $stats['failures'][] = $this->buildFailureEntry($profile, $e, 'skipped_config_pending');
            } catch (ScholarshipReticulaMissingException $e) {
                // sdd/egresar-boton-corte-ciclo: retícula dates not loaded —
                // an expected skip, not an error (no Log::error).
                $stats['skipped_reticula_missing']++;
                $stats['failures'][] = $this->buildFailureEntry($profile, $e, 'skipped_reticula_missing');
            } catch (ScholarshipCycleClosedException $e) {
                // sdd/egresado-baja-definitiva, design D3: defense-in-depth —
                // openForPeriod() above already excludes these rows from the
                // query, so this is not expected to trigger in practice. A
                // cut becario is an expected skip, not an error: no failure
                // entry, no Log::error.
                $stats['skipped']++;
            } catch (\Throwable $e) {
                Log::error("Error generando refrendo para user_id={$profile->user_id}", [
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
                $stats['failures'][] = $this->buildFailureEntry($profile, $e, 'error');
            }
        }

        return $stats;
    }

    /**
     * Builds one failures[] entry for a becario not created during
     * generateForPeriod()'s bulk loop. `name` mirrors buildSnapshot()'s own
     * `snapshot_name` construction (trim(first_name . ' ' . last_name)) so
     * the reported name matches what would have been frozen on the refrend.
     */
    private function buildFailureEntry(ScholarshipProfile $profile, \Throwable $e, string $kind): array
    {
        $user = $profile->user;
        $name = $user ? trim("{$user->first_name} {$user->last_name}") : '';

        return [
            'user_id' => $profile->user_id,
            'name'    => $name,
            'reason'  => $e->getMessage(),
            'kind'    => $kind,
        ];
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
        $this->assertCycleOpen($profile, $year, $month);
        $this->assertConfigSet($profile);
        $this->assertReticulaSet($profile);

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
     *   Persisted to `scholarship_refrends.created_via` (GENERATION |
     *   ADVANCE_PAYMENT); it gates the graduation (egreso) check in
     *   createRefrendForPeriod().
     */
    public function generateFutureForAdvance(
        ScholarshipProfile $profile,
        int $year,
        int $month,
        string $createdVia = 'ADVANCE_PAYMENT'
    ): ScholarshipRefrend {
        $this->assertUserIsActive($profile);
        $this->assertCycleOpen($profile, $year, $month);
        $this->assertConfigSet($profile);
        $this->assertReticulaSet($profile);
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
     * sdd/egresado-baja-definitiva, design D3: guards generateForUser() and
     * generateFutureForAdvance() against a period strictly after the
     * profile's cycle_closed_period stop marker (design D1/D2). The exact
     * cut period itself (period == closed) is allowed — isCycleClosedFor()
     * already encodes that boundary. Shared by both single-user entry
     * points; generateForPeriod()'s bulk query filters the same condition
     * at the query level via ->openForPeriod() instead of hitting this.
     */
    private function assertCycleOpen(ScholarshipProfile $profile, int $year, int $month): void
    {
        if (!$profile->isCycleClosedFor($year, $month)) {
            return;
        }

        $closed = Carbon::parse($profile->cycle_closed_period)->toDateString();

        throw new ScholarshipCycleClosedException(
            "No se puede generar el refrendo de {$month}/{$year}: el ciclo del becario está cerrado desde {$closed}."
        );
    }

    /**
     * sdd/perfil-beca-montos-admin: a profile whose beca amounts were never
     * saved by Administración (config_set_at NULL) must not generate any
     * refrend — it would silently produce $0 (monthly_amount defaults to
     * 0.00). Evaluated right after assertCycleOpen(), so a closed cycle
     * keeps its own error. A configured $0 amount passes (marker set).
     */
    private function assertConfigSet(ScholarshipProfile $profile): void
    {
        if (!$profile->isConfigSet()) {
            throw new ScholarshipConfigPendingException();
        }
    }

    /**
     * sdd/egresar-boton-corte-ciclo: both retícula dates are required to
     * generate any refrend. A NULL start lets generation backfill months
     * before the career began; a NULL end leaves it unbounded forward — both
     * are money risks. Evaluated right after assertConfigSet() and before the
     * period guards, so a closed cycle and a pending config keep priority.
     */
    private function assertReticulaSet(ScholarshipProfile $profile): void
    {
        if ($profile->reticula_start_date === null || $profile->reticula_end_date === null) {
            throw new ScholarshipReticulaMissingException();
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

        // sdd/egresado-status-timing, design D2: computed once before the
        // transaction closure. When true, this period IS the becario's
        // retícula month+2 — the automatic $0 egreso refrendo.
        $isEgreso = $profile->isEgresoReticulaPeriod($year, $month);

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

        return DB::transaction(function () use ($profile, $year, $month, $createdVia, $snapshot, $referenceDate, $lastGrade, $attendanceSummary, $initialWorkflowStatus, $incidentDescription, $hasProfileDiscount, $isEgreso) {
            $refrend = ScholarshipRefrend::create([
                'user_id'                      => $profile->user_id,
                'period_year'                  => $year,
                'period_month'                 => $month,
                'refrend_type'                 => RefrendType::NORMAL->value,
                'created_via'                  => $createdVia,
                'status'                       => RefrendStatus::DRAFT->value,
                'workflow_status'              => $initialWorkflowStatus,
                'resolution_type'              => null,
                'snapshot_gross_amount'        => $snapshot['snapshot_gross_amount'],
                'snapshot_monto_apoyo'         => $snapshot['snapshot_monto_apoyo'],
                // sdd/telmex-cobertura-iu, design D2/PR1 bugfix: both fields
                // were computed by buildSnapshot() since the TELMEX_IU split
                // landed but never persisted here (pre-existing gap) — a
                // generated TELMEX/TELMEX_IU refrend's covered bookkeeping
                // and coverage FK were silently lost.
                'snapshot_telmex_covered_amount' => $snapshot['snapshot_telmex_covered_amount'],
                'snapshot_telmex_coverage_id'    => $snapshot['snapshot_telmex_coverage_id'],
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
                'attendance_summary_snapshot'  => $attendanceSummary,
            ]);

            // sdd/egresado-status-timing, design D2: a CLOSED $0 egreso row
            // must not carry a DESCUENTO_PERFIL incident nor attendance
            // penalties for a becario who graduated — skip both blocks.
            if (!$isEgreso && $hasProfileDiscount && $incidentDescription !== null) {
                $refrend->incidents()->create([
                    'incident_category' => 'ACADEMICO',
                    'incident_type'     => 'DESCUENTO_PERFIL',
                    'description'       => $incidentDescription,
                    'priority'          => 'LOW',
                    'created_by_id'     => null,
                ]);
            }

            $user = $profile->user;

            if (!$isEgreso) {
                // Evaluar penalizaciones automáticas de asistencia
                $this->penaltyService->applyPenaltyIfDue($refrend, $user, 100.0, $referenceDate);
                $this->penaltyService->applyAbsencePenaltyIfDue($refrend, $user, $year, $month);
            }

            // Recalcular montos finales
            $this->calculationService->recalculate($refrend);

            if ($isEgreso) {
                $notes = "Egreso automático por vencimiento de retícula al {$profile->egreso_administrativo}.";

                $refrend->update([
                    'status'                       => RefrendStatus::CANCELLED->value,
                    'final_amount'                 => 0,
                    'amount_pending_from_previous' => 0,
                    'advance_payment_amount'       => 0,
                    'resolution_type'              => ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA,
                    'resolution_notes'             => $notes,
                    'workflow_status'              => 'CLOSED',
                    'locked_at'                    => now(),
                    'locked_by_id'                 => null,
                ]);

                // R3: graduation (user_type flip) is gated on the GENERATION
                // path only — generateFutureForAdvance() shares this method,
                // and flipping user_type for a FUTURE month would be wrong.
                // The money mutation above stays ungated as defense-in-depth.
                if ($createdVia === 'GENERATION') {
                    // sdd/retenciones-pendientes (R5): the egreso also cancels
                    // the PENDING retentions and the advance payments, in this
                    // same transaction (system actor). A CycleCutBlockedException
                    // here rolls the whole egreso back for this becario.
                    $this->terminationCanceller->cancel(
                        CutAnchor::fromRefrend($refrend),
                        WithholdingCancellationReason::EGRESO_RETICULA,
                        null
                    );

                    $this->graduateBecario->execute($user, $refrend, $notes, null);
                }
            }

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
     * Lanza ReticulaPeriodOutOfRangeException (extends \DomainException) si está
     * fuera del rango — sdd/verificacion-incidencias-ux, PR1: same messages as
     * before, only the exception class changed, so every existing
     * `catch (\DomainException)` call site still matches. This is the ONLY
     * throw site generateForPeriod()'s loop classifies as
     * `skipped_out_of_reticula` instead of `errors`.
     */
    private function assertPeriodWithinReticula(ScholarshipProfile $profile, int $year, int $month): void
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();

        if ($profile->reticula_start_date && $periodStart->lt($profile->reticula_start_date)) {
            throw new ReticulaPeriodOutOfRangeException(
                "El periodo {$month}/{$year} es anterior al inicio de la retícula ({$profile->reticula_start_date->toDateString()})."
            );
        }

        // sdd/egresado-status-timing, design D1: shared cutoff primitive —
        // no other formula for this boundary may exist in the codebase.
        $egresoAdministrativo = $profile->egresoAdministrativoDate();

        if ($egresoAdministrativo && $periodStart->gt($egresoAdministrativo)) {
            throw new ReticulaPeriodOutOfRangeException(
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
