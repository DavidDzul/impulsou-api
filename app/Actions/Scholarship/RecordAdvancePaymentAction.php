<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Services\GenerateMonthlyRefrendsService;
use App\Services\ScholarshipLoggingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a batch of 1-MAX_ADVANCED_MONTHS future months as advance-paid
 * against a given origin refrend (design "RecordAdvancePaymentAction").
 * Single-purpose: creates the ledger (header + children) and the future
 * refrend rows via GenerateMonthlyRefrendsService::generateFutureForAdvance().
 * Resolving the ORIGIN refrend's own current-month situation is NOT this
 * action's job — that stays with RecordPaymentSituationAction, called
 * separately by the dialog.
 */
class RecordAdvancePaymentAction
{
    /**
     * Cap on future months payable in a single advance-payment batch
     * (design D5). Exact precedent: PayableWithholdingWindow::MAX_PAYABLE —
     * a single named constant read by controller validation, this action's
     * own re-check, and the endpoint's response meta. Never an inline
     * literal, so raising the cap later touches only this line.
     */
    public const MAX_ADVANCED_MONTHS = 6;

    private const ALLOWED_WORKFLOW_STATUSES = ['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO'];

    public function __construct(
        private ScholarshipLoggingService $logging,
        private GenerateMonthlyRefrendsService $generator
    ) {}

    public function execute(ScholarshipRefrend $originRefrend, array $data, int $userId): ScholarshipAdvancePayment
    {
        if (!in_array($originRefrend->workflow_status, self::ALLOWED_WORKFLOW_STATUSES, true)) {
            throw new \DomainException(
                'Solo se puede registrar un pago adelantado sobre refrendos en estado DRAFT, CON_INCIDENCIA o LISTO_PARA_PAGO.'
            );
        }

        // sdd/telmex-cobertura-iu, design D6 / decisions-2 dec1: CERT/advance
        // payments are BLOCKED during coverage — arrival reconciliation
        // requires a $0 outcome (AdvancePaymentReconciler), which contradicts
        // "the covered part is never zeroed". Accepted consequence: a
        // TELMEX_IU becario in coverage also gets no advance of its IU part
        // during those months.
        if ($originRefrend->coveredPayableAmount() > 0) {
            throw new \DomainException(
                'No se puede registrar un pago adelantado: el refrendo de origen está cubierto por Telmex.'
            );
        }

        $months = $data['months'] ?? [];

        if (count($months) < 1 || count($months) > self::MAX_ADVANCED_MONTHS) {
            throw new \DomainException(
                'Debe indicar entre 1 y ' . self::MAX_ADVANCED_MONTHS . ' meses para el pago adelantado.'
            );
        }

        // Rollover-safe ordering idiom (same as PayableWithholdingWindow):
        // year*12 + month lets duplicate/ordering checks work across a
        // December -> January boundary without special-casing.
        $originAbsolute = ((int) $originRefrend->period_year) * 12 + (int) $originRefrend->period_month;
        $seenAbsolutes  = [];

        foreach ($months as $month) {
            $year     = (int) $month['year'];
            $mon      = (int) $month['month'];
            $absolute = $year * 12 + $mon;

            if (isset($seenAbsolutes[$absolute])) {
                throw new \DomainException('No se puede repetir el mismo mes dentro de una misma solicitud.');
            }
            $seenAbsolutes[$absolute] = ['year' => $year, 'month' => $mon];

            if ($absolute <= $originAbsolute) {
                throw new \DomainException(
                    "El mes {$mon}/{$year} debe ser posterior al mes de origen del refrendo."
                );
            }
        }

        $old = $this->logging->snapshotRefrend($originRefrend);

        return DB::transaction(function () use ($originRefrend, $months, $seenAbsolutes, $userId, $data, $old) {
            // Re-assert no advance batch already exists for this origin refrend
            // (mirrors scholarship_advance_payments.unique(origin_refrend_id)).
            // Locked inside the transaction to close the check-then-act window,
            // same pattern as RecordPaymentSituationAction's ledger re-check.
            $existingBatch = ScholarshipAdvancePayment::where('origin_refrend_id', $originRefrend->id)
                ->lockForUpdate()
                ->first();
            if ($existingBatch) {
                throw new \DomainException('Este refrendo ya tiene un pago adelantado registrado.');
            }

            // App-level pre-check for the double-claim guard. The DB-level
            // unique(user_id, period_year, period_month) constraint on
            // scholarship_advance_payment_months (PR2) is the final backstop
            // against a genuine race — this check only short-circuits the
            // common case with a friendly DomainException instead of a
            // QueryException.
            $claimed = ScholarshipAdvancePaymentMonth::where('user_id', $originRefrend->user_id)
                ->where(function ($query) use ($seenAbsolutes) {
                    foreach ($seenAbsolutes as $period) {
                        $query->orWhere(function ($sub) use ($period) {
                            $sub->where('period_year', $period['year'])
                                ->where('period_month', $period['month']);
                        });
                    }
                })
                ->lockForUpdate()
                ->exists();
            if ($claimed) {
                throw new \DomainException('Uno o más de los meses solicitados ya fue pagado por adelantado.');
            }

            $profile = ScholarshipProfile::where('user_id', $originRefrend->user_id)->firstOrFail();

            $childrenData = [];
            $totalAmount  = 0.0;

            foreach ($months as $month) {
                $year = (int) $month['year'];
                $mon  = (int) $month['month'];

                $existingRealRefrend = ScholarshipRefrend::where('user_id', $originRefrend->user_id)
                    ->where('period_year', $year)
                    ->where('period_month', $mon)
                    ->where('refrend_type', RefrendType::NORMAL->value)
                    ->exists();
                if ($existingRealRefrend) {
                    throw new \DomainException("Ya existe un refrendo para el periodo {$mon}/{$year}.");
                }

                // sdd/egresado-status-timing, design D6: reject a requested
                // future period landing ON OR AFTER the becario's retícula
                // month+2 boundary, before any future refrendo is generated.
                // A CLOSED EGRESO_RETICULA refrend is also ineligible as an
                // advance-payment ORIGIN, but that is already enforced by
                // ALLOWED_WORKFLOW_STATUSES above — no new code needed there.
                $cutoff = $profile->egresoAdministrativoDate();
                if ($cutoff !== null && Carbon::create($year, $mon, 1)->gte($cutoff->copy()->startOfMonth())) {
                    throw new \DomainException(
                        "El periodo {$mon}/{$year} no puede pagarse por adelantado: cae en o después del mes de egreso administrativo del becario ({$cutoff->toDateString()})."
                    );
                }

                $refrend = $this->generator->generateFutureForAdvance($profile, $year, $mon);

                // D6 (continued): a future month generated inside this same
                // batch can itself resolve to an active coverage even when
                // the origin didn't — reject and let the transaction roll
                // back every refrend created so far in this request.
                if ($refrend->coveredPayableAmount() > 0) {
                    throw new \DomainException(
                        "El periodo {$mon}/{$year} está cubierto por Telmex; no se puede adelantar."
                    );
                }

                $amount  = (float) $refrend->final_amount;
                $totalAmount += $amount;

                $childrenData[] = [
                    'user_id'      => $originRefrend->user_id,
                    'period_year'  => $year,
                    'period_month' => $mon,
                    'amount'       => number_format($amount, 2, '.', ''),
                    'refrend_id'   => $refrend->id,
                    'status'       => 'PENDING',
                ];
            }

            $header = ScholarshipAdvancePayment::create([
                'user_id'             => $originRefrend->user_id,
                'origin_refrend_id'   => $originRefrend->id,
                'origin_period_year'  => $originRefrend->period_year,
                'origin_period_month' => $originRefrend->period_month,
                'months_count'        => count($childrenData),
                'total_amount'        => number_format($totalAmount, 2, '.', ''),
                'status'              => 'ACTIVE',
                'cause'               => $data['cause'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'created_by_id'       => $userId,
            ]);

            foreach ($childrenData as $child) {
                $header->months()->create($child);
            }

            $originRefrend->update(['advance_payment_amount' => $header->total_amount]);

            $this->logging->log(
                $originRefrend,
                'ADVANCE_PAYMENT_RECORDED',
                $old,
                $this->logging->snapshotRefrend($originRefrend->fresh())
            );

            return $header->fresh('months');
        });
    }
}
