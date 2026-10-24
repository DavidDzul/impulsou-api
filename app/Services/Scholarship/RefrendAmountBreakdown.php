<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;

/**
 * READ-side "Desglose de monto" of the payment document. Pure read, no
 * writes. Every line is derived from the frozen snapshot so the document
 * RECONCILES:
 *
 *   base_amount + monto_apoyo (IU only) + temporary_increase_amount = gross_amount
 *   gross_amount - academic_discount_amount - discount_amount
 *       + telmex_covered_amount + manual_adjustment                 = final_amount
 *   final_amount + amount_pending_from_previous + advance_payment_amount = total_to_pay
 *
 * `manual_adjustment` is the residual: it is non-null only when final_amount
 * was edited by hand (ScholarshipRefrendController::patchInline's
 * final_amount_override). Every system path reconciles on its own, with one
 * exception handled explicitly below: the automatic EGRESO_RETICULA refrend
 * is forced to $0 without storing the discount that explains it.
 *
 * CROSS-REFERENCE: the derivation of "covered money actually included in
 * final_amount" mirrors RecordPaymentSituationAction (covered is NOT added
 * for the terminal cycle cuts) and ScholarshipCalculationService.
 */
class RefrendAmountBreakdown
{
    private const DEFAULT_DISCOUNT_LABEL = 'Descuentos por penalizaciones';

    private const DISCOUNT_LABELS = [
        'RETENIDA'                                  => 'Retención',
        'DESCUENTO_DEFINITIVO'                      => 'Descuento definitivo',
        'SIN_PAGO'                                  => 'Sin pago',
        'BAJA_DEFINITIVA'                           => 'Baja definitiva',
        'EGRESADO'                                  => 'Egreso',
        ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA => 'Egreso por retícula',
    ];

    private const CYCLE_CLOSE_LABEL = 'Cierre de ciclo';

    public function forRefrend(ScholarshipRefrend $refrend): array
    {
        $gross      = (float) ($refrend->snapshot_gross_amount ?? $refrend->base_amount);
        $academicPct = (float) ($refrend->snapshot_discount_percentage ?? 0);
        $hasAcademic = $academicPct > 0;

        $academicAmount = $hasAcademic
            ? round($gross - round($gross * (1 - $academicPct / 100), 2), 2)
            : 0.0;
        $due = round($gross - $academicAmount, 2);

        $type              = $refrend->snapshot_scholarship_type?->value;
        $isEgresoReticula  = $refrend->resolution_type === ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA;
        $isCycleCut        = in_array($refrend->resolution_type, ScholarshipRefrend::CYCLE_CUT_RESOLUTIONS, true);

        // Covered Telmex money ACTUALLY included in final_amount: none of the
        // $0 terminal paths (cycle cuts, cascade close, automatic egreso) add it.
        $covered = ($isEgresoReticula || $isCycleCut) ? 0.0 : $refrend->coveredPayableAmount();

        // The automatic egreso refrend is forced to $0 without a stored
        // discount (discount_amount stays 0): report the whole due amount as
        // the discount so the lines stay truthful instead of faking a
        // manual adjustment.
        $discountAmount     = $isEgresoReticula ? $due : (float) $refrend->discount_amount;
        $discountPercentage = $isEgresoReticula ? '100.00' : $refrend->discount_percentage;

        $final = (float) $refrend->final_amount;
        $manual = round($final - ($due - $discountAmount + $covered), 2);

        return [
            'scholarship_type'              => $type,
            'base_amount'                   => $refrend->base_amount,
            'monto_apoyo'                   => $type === 'IU' ? $this->money($refrend->snapshot_monto_apoyo) : null,
            'temporary_increase_amount'     => $refrend->snapshot_temporary_increase_amount,
            'temporary_increase_reason'     => $refrend->snapshot_temporary_increase_reason,
            'gross_amount'                  => $this->money($gross),
            'academic_discount_percentage'  => $hasAcademic ? $refrend->snapshot_discount_percentage : null,
            'academic_discount_reason'      => $hasAcademic ? $refrend->snapshot_discount_reason : null,
            'academic_discount_amount'      => $this->money($academicAmount),
            'discount_percentage'           => $discountPercentage,
            'discount_amount'               => $isEgresoReticula ? $this->money($discountAmount) : $refrend->discount_amount,
            'discount_label'                => $this->discountLabel($refrend),
            'telmex_covered_amount'         => $this->money($covered),
            'manual_adjustment'             => abs($manual) < 0.005 ? null : $this->money($manual),
            'final_amount'                  => $refrend->final_amount,
            'amount_pending_from_previous'  => $refrend->amount_pending_from_previous,
            'advance_payment_amount'        => $this->money($refrend->advance_payment_amount),
            'total_to_pay'                  => $refrend->total_to_pay,
        ];
    }

    private function discountLabel(ScholarshipRefrend $refrend): string
    {
        $resolution = $refrend->resolution_type;

        if ($this->isClosedInCascade($refrend)) {
            return self::CYCLE_CLOSE_LABEL;
        }

        return self::DISCOUNT_LABELS[$resolution] ?? self::DEFAULT_DISCOUNT_LABEL;
    }

    /**
     * CloseScholarshipCycleAction::cascadeCloseLaterRefrends stamps later
     * refrends with the SAME resolution_type as the cut itself (EGRESADO /
     * BAJA_DEFINITIVA) and no other marker. The structural tell is the
     * profile's cycle_closed_refrend_id: a cut-resolution refrend that is NOT
     * the refrend the cycle was closed on was closed in cascade.
     */
    private function isClosedInCascade(ScholarshipRefrend $refrend): bool
    {
        if (! in_array($refrend->resolution_type, ScholarshipRefrend::CYCLE_CUT_RESOLUTIONS, true)) {
            return false;
        }

        $cutRefrendId = ScholarshipProfile::where('user_id', $refrend->user_id)
            ->value('cycle_closed_refrend_id');

        return $cutRefrendId !== null && (int) $cutRefrendId !== (int) $refrend->id;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
