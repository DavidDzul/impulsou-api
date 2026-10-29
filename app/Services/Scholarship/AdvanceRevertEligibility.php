<?php

namespace App\Services\Scholarship;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Exceptions\AdvancePaymentException;
use App\Models\ScholarshipAdjustment;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendIncident;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use Illuminate\Support\Collection;

/**
 * sdd/revertir-pago-adelantado, design "Eligibility": the single predicate
 * that decides whether an advance payment can be reverted. The revert action
 * calls it under row locks (authoritative); RefrendBulkQueryService calls it
 * in batch (advisory UI flag). Both go through evaluateMany(), which runs a
 * constant number of queries regardless of how many origins it receives.
 *
 * Evaluation order (first failure wins, binding):
 *   NOT_FOUND, ADVANCE_NOT_FOUND, ADVANCE_CYCLE_CLOSED, ADVANCE_ORIGIN_PAID,
 *   ADVANCE_ORIGIN_LOCKED, ADVANCE_MONTH_SETTLED, ADVANCE_FUTURE_TELMEX_COVERED,
 *   ADVANCE_FUTURE_NOT_PRISTINE.
 *
 * Pristine future refrend: no resolution, approval, payment, lock,
 * notification, adjustment, withholding or withholding payment (voided ones
 * included), and no incident other than the automatic DESCUENTO_PERFIL one
 * (created_by_id NULL). Automatic attendance discounts are tolerated. Origin
 * incidents, resolution and discounts never matter.
 */
class AdvanceRevertEligibility
{
    private const AUTOMATIC_INCIDENT_TYPE = 'DESCUENTO_PERFIL';

    /** Paid = frozen into a payment batch, paid by cheque, or status PAID. */
    public static function isOriginPaid(ScholarshipRefrend $origin): bool
    {
        return $origin->payment_batch_id !== null
            || $origin->paid_payment_method === PaymentMethod::CHEQUE
            || $origin->status === RefrendStatus::PAID;
    }

    /** First blocking code for one origin refrend, or null when it can be reverted. */
    public function evaluate(int $originRefrendId): ?string
    {
        return $this->evaluateMany([$originRefrendId])[$originRefrendId];
    }

    /** @throws AdvancePaymentException */
    public function assertEligible(int $originRefrendId): void
    {
        $code = $this->evaluate($originRefrendId);

        if ($code !== null) {
            throw AdvancePaymentException::fromCode($code);
        }
    }

    /**
     * @param array<int, int> $originRefrendIds
     * @return array<int, string|null> originRefrendId => blocking code (null = revertable)
     */
    public function evaluateMany(array $originRefrendIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $originRefrendIds)));

        $origins  = ScholarshipRefrend::whereIn('id', $ids)->get()->keyBy('id');
        $headers  = ScholarshipAdvancePayment::whereIn('origin_refrend_id', $ids)->get()->keyBy('origin_refrend_id');
        $months   = ScholarshipAdvancePaymentMonth::whereIn('advance_payment_id', $headers->pluck('id')->all())
            ->get()
            ->groupBy('advance_payment_id');
        $profiles = ScholarshipProfile::whereIn('user_id', $origins->pluck('user_id')->unique()->all())
            ->get()
            ->keyBy('user_id');

        $futureIds = $months->flatten(1)->pluck('refrend_id')->filter()->unique()->values()->all();
        $futures   = ScholarshipRefrend::whereIn('id', $futureIds)->get()->keyBy('id');

        $dirtyFutureIds = $this->futureIdsWithActivity($futureIds);

        $result = [];
        foreach ($ids as $id) {
            $origin = $origins->get($id);
            $header = $headers->get($id);

            $result[$id] = $this->evaluateOne(
                $origin,
                $header,
                $header !== null ? ($months->get($header->id) ?? collect()) : collect(),
                $origin !== null ? $profiles->get($origin->user_id) : null,
                $futures,
                $dirtyFutureIds
            );
        }

        return $result;
    }

    /**
     * @param Collection<int, ScholarshipAdvancePaymentMonth> $months
     * @param Collection<int, ScholarshipRefrend>             $futures keyed by id
     * @param array<int, true>                                $dirtyFutureIds
     */
    private function evaluateOne(
        ?ScholarshipRefrend $origin,
        ?ScholarshipAdvancePayment $header,
        Collection $months,
        ?ScholarshipProfile $profile,
        Collection $futures,
        array $dirtyFutureIds
    ): ?string {
        if ($origin === null) {
            return AdvancePaymentException::NOT_FOUND;
        }

        if ($header === null) {
            return AdvancePaymentException::ADVANCE_NOT_FOUND;
        }

        if ($this->isCycleClosed($header, $months, $profile)) {
            return AdvancePaymentException::ADVANCE_CYCLE_CLOSED;
        }

        if (self::isOriginPaid($origin)) {
            return AdvancePaymentException::ADVANCE_ORIGIN_PAID;
        }

        if ($origin->isLocked() || $origin->locked_at !== null) {
            return AdvancePaymentException::ADVANCE_ORIGIN_LOCKED;
        }

        if ($months->contains(fn (ScholarshipAdvancePaymentMonth $m) => $m->status !== ScholarshipAdvancePaymentMonth::STATUS_PENDING)) {
            return AdvancePaymentException::ADVANCE_MONTH_SETTLED;
        }

        $monthFutures = $months
            ->map(fn (ScholarshipAdvancePaymentMonth $m) => $m->refrend_id !== null ? $futures->get($m->refrend_id) : null)
            ->filter();

        if ($monthFutures->contains(fn (ScholarshipRefrend $f) => $f->snapshot_telmex_coverage_id !== null)) {
            return AdvancePaymentException::ADVANCE_FUTURE_TELMEX_COVERED;
        }

        if ($monthFutures->contains(
            fn (ScholarshipRefrend $f) => $this->hasOwnActivity($f) || isset($dirtyFutureIds[$f->id])
        )) {
            return AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE;
        }

        return null;
    }

    /**
     * Header no longer ACTIVE, any month CANCELLED, or the user's cycle is
     * closed (same predicate CloseScholarshipCycleAction guards with).
     *
     * @param Collection<int, ScholarshipAdvancePaymentMonth> $months
     */
    private function isCycleClosed(
        ScholarshipAdvancePayment $header,
        Collection $months,
        ?ScholarshipProfile $profile
    ): bool {
        return $header->status !== ScholarshipAdvancePayment::STATUS_ACTIVE
            || $months->contains(fn (ScholarshipAdvancePaymentMonth $m) => $m->status === ScholarshipAdvancePaymentMonth::STATUS_CANCELLED)
            || ($profile !== null && $profile->hasClosedCycle());
    }

    /** Activity recorded on the refrend row itself (no extra queries). */
    private function hasOwnActivity(ScholarshipRefrend $future): bool
    {
        return $future->resolution_type !== null
            || $future->resolution_cause !== null
            || $future->resolution_notes !== null
            || $future->withholding_mode !== null
            || $future->atencion_reviewed_at !== null
            || $future->atencion_reviewed_by_id !== null
            || $future->pedagogia_reviewed_at !== null
            || $future->pedagogia_reviewed_by_id !== null
            || $future->workflow_status === 'LISTO_PARA_PAGO'
            || $future->isLocked()
            || $future->locked_at !== null
            || $future->status === RefrendStatus::PAID
            || $future->payment_batch_id !== null
            || $future->paid_payment_method !== null
            || $future->notified_at !== null
            || $future->notification_method !== null;
    }

    /**
     * Future refrends carrying child rows that make them non-pristine.
     * One query per child table, regardless of the number of futures.
     *
     * @param array<int, int> $futureIds
     * @return array<int, true>
     */
    private function futureIdsWithActivity(array $futureIds): array
    {
        if ($futureIds === []) {
            $futureIds = [0];
        }

        $manualIncidents = ScholarshipRefrendIncident::whereIn('scholarship_refrend_id', $futureIds)
            ->where(function ($q) {
                $q->where('incident_type', '!=', self::AUTOMATIC_INCIDENT_TYPE)
                    ->orWhereNotNull('created_by_id');
            })
            ->pluck('scholarship_refrend_id');

        $adjustments = ScholarshipAdjustment::whereIn('scholarship_refrend_id', $futureIds)
            ->pluck('scholarship_refrend_id');

        $withholdings = ScholarshipWithholding::whereIn('origin_refrend_id', $futureIds)
            ->pluck('origin_refrend_id');

        $withholdingPayments = ScholarshipWithholdingPayment::whereIn('applied_refrend_id', $futureIds)
            ->pluck('applied_refrend_id');

        $dirty = [];
        foreach ([$manualIncidents, $adjustments, $withholdings, $withholdingPayments] as $ids) {
            foreach ($ids as $id) {
                $dirty[(int) $id] = true;
            }
        }

        return $dirty;
    }
}
