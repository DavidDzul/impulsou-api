<?php

namespace App\Services\Scholarship;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind GET /scholarship-payments/by-generation
 * (sdd/tabla-montos-por-generacion): the cards `summary` plus one amounts
 * row per becario.
 *
 * It composes PaymentBatchService instead of changing rows(): the batch flow
 * (Lotes de pago) keeps Filter A untouched, while this report additionally
 * lists the pure TELMEX refrends Filter A drops as NO_IU_PAYMENT rows. Those
 * rows never reach generationSummary(), so they cannot affect any figure; only
 * their count is added to the summary.
 */
class GenerationPaymentReport
{
    public const STATE_PAID          = 'PAID';
    public const STATE_PENDING       = 'PENDING';
    public const STATE_BLOCKED       = 'BLOCKED';
    public const STATE_NO_IU_PAYMENT = 'NO_IU_PAYMENT';

    private const STATE_BY_GROUP = [
        'paid'    => self::STATE_PAID,
        'pending' => self::STATE_PENDING,
        'blocked' => self::STATE_BLOCKED,
    ];

    public function __construct(private PaymentBatchService $batches)
    {
    }

    /**
     * @return array{summary: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    public function build(string $campus, int $year, int $month, int $generationId): array
    {
        $batchRows = $this->batches->rows($campus, $year, $month, $generationId);
        $noIuRows  = $this->noIuPaymentRows($campus, $year, $month, $generationId);

        $summary = $this->batches->generationSummary($batchRows);
        $summary['telmex_without_iu_payment'] = count($noIuRows);
        // Money still pending/advanced on those rows. It is NOT part of any
        // other figure, but it is real: Filter A keeps these refrends out of
        // the payment batch, so surfacing it avoids hiding an unpaid balance.
        $summary['telmex_without_iu_payment_pending_amount'] = self::money(
            array_sum(array_map(fn (array $row) => self::cents($row['total_to_pay']), $noIuRows))
        );

        $rows = array_merge(
            array_map(fn (array $row) => $this->paymentRow($row), $batchRows),
            $noIuRows
        );

        usort($rows, fn (array $a, array $b) => [self::sortKey($a['full_name']), $a['refrend_id']]
            <=> [self::sortKey($b['full_name']), $b['refrend_id']]);

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * One amounts row from a PaymentBatchService::rows() entry.
     *
     * Invariant (cents): beca + apoyo + pago_iu + temporary_increase
     *   + telmex_coverage - discounts + pending_and_advance = total_to_pay.
     *
     * `discounts` is a POSITIVE number that SUBTRACTS. It is the single
     * balancing line, i.e. academic discount + penalty/retention discount
     * - manual adjustment (a manual final_amount override that raised the
     * amount makes it negative). It is derived as the residual of the other
     * columns against the row's total_to_pay on purpose: that keeps the row
     * AND the summary (difference_amount = sum of discounts) reconciled by
     * construction, with no per-row model loading. The only deviation from
     * RefrendAmountBreakdown is PaymentAmountFloor (a 0.00 total is floored
     * to 0.01 for the bank file), which shows as a -0.01 shift in discounts.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function paymentRow(array $row): array
    {
        $type = $row['snapshot_scholarship_type'];
        $isIu = $type === ScholarshipType::IU->value;

        $beca     = $isIu ? self::cents($row['base_amount']) : 0;
        $apoyo    = $isIu ? self::cents($row['snapshot_monto_apoyo']) : 0;
        $pagoIu   = $type === ScholarshipType::TELMEX_IU->value ? self::cents($row['base_amount']) : 0;
        $increase = self::cents($row['snapshot_temporary_increase_amount']);
        $covered  = ($row['snapshot_telmex_coverage_id'] ?? null) !== null
            ? self::cents($row['snapshot_telmex_covered_amount'])
            : 0;
        $pendingAndAdvance = self::cents($row['amount_pending_from_previous'])
            + self::cents($row['advance_payment_amount']);
        $total = self::cents($row['total_to_pay']);

        $discounts = $beca + $apoyo + $pagoIu + $increase + $covered + $pendingAndAdvance - $total;

        return [
            'refrend_id'          => $row['refrend_id'],
            'user_id'             => $row['user_id'],
            'enrollment'          => $row['enrollment'],
            'full_name'           => $row['snapshot_name'],
            'scholarship_type'    => $type,
            'beca'                => self::money($beca),
            'apoyo'               => self::money($apoyo),
            'pago_iu'             => self::money($pagoIu),
            'temporary_increase'  => self::money($increase),
            'telmex_coverage'     => self::money($covered),
            'discounts'           => self::money($discounts),
            'pending_and_advance' => self::money($pendingAndAdvance),
            'total_to_pay'        => self::money($total),
            'state'               => self::STATE_BY_GROUP[$this->batches->partitionGroup($row)],
        ];
    }

    /**
     * Pure TELMEX refrends dropped by Filter A (no active increase, no
     * coverage) for the same campus/period/generation, minus Filter C's
     * excluded resolutions. The conditions are the exact negation of
     * TelmexPaymentPolicy::applyBatchCandidacy().
     *
     * Every money column is 0.00 (nothing is paid for the month itself)
     * EXCEPT pending_and_advance/total_to_pay: such a refrend can still carry
     * amount_pending_from_previous or advance_payment_amount, and hiding it
     * would misreport what the becario is owed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function noIuPaymentRows(string $campus, int $year, int $month, int $generationId): array
    {
        return DB::table('scholarship_refrends as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.snapshot_campus', $campus)
            ->where('r.period_year', $year)
            ->where('r.period_month', $month)
            ->where('r.snapshot_generation_id', $generationId)
            ->where('r.snapshot_scholarship_type', ScholarshipType::TELMEX->value)
            ->whereNull('r.snapshot_telmex_coverage_id')
            ->where(fn ($q) => $q->whereNull('r.snapshot_temporary_increase_amount')
                ->orWhere('r.snapshot_temporary_increase_amount', '<=', 0))
            ->where(fn ($q) => $q->whereNull('r.resolution_type')
                ->orWhereNotIn('r.resolution_type', ScholarshipRefrend::NON_CANDIDATE_RESOLUTIONS))
            ->get([
                'r.id as refrend_id', 'r.user_id', 'r.snapshot_name', 'u.enrollment',
                'r.amount_pending_from_previous', 'r.advance_payment_amount',
            ])
            ->map(function ($r) {
                $pendingAndAdvance = self::money(
                    self::cents($r->amount_pending_from_previous) + self::cents($r->advance_payment_amount)
                );

                return [
                    'refrend_id'          => $r->refrend_id,
                    'user_id'             => $r->user_id,
                    'enrollment'          => $r->enrollment,
                    'full_name'           => $r->snapshot_name,
                    'scholarship_type'    => ScholarshipType::TELMEX->value,
                    'beca'                => '0.00',
                    'apoyo'               => '0.00',
                    'pago_iu'             => '0.00',
                    'temporary_increase'  => '0.00',
                    'telmex_coverage'     => '0.00',
                    'discounts'           => '0.00',
                    'pending_and_advance' => $pendingAndAdvance,
                    'total_to_pay'        => $pendingAndAdvance,
                    'state'               => self::STATE_NO_IU_PAYMENT,
                ];
            })
            ->all();
    }

    /**
     * Accent-insensitive, case-insensitive sort key. Accents are stripped and
     * "ñ" becomes "n{" ("{" follows "z" in ASCII), so every word with an "ñ"
     * sorts after all words that only use a plain "n" at that position.
     */
    private static function sortKey(?string $name): string
    {
        return strtr(mb_strtolower((string) $name), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n{',
        ]);
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) ($value ?? 0) * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
