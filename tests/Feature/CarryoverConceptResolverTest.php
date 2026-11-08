<?php

namespace Tests\Feature;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use App\Services\Scholarship\CarryoverConceptResolver;
use App\Support\Scholarship\RefrendConceptSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.7, R5, D4-D6): the carryover
 * (pending_and_advance) split of a batch of rows from the withholding and
 * advance-payment ledgers, with exactly 2 queries.
 */
class CarryoverConceptResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
    }

    private function refrend(int $month, array $overrides = [], ?User $user = null): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => ($user ?? $this->user)->id,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 2000.00,
            'snapshot_gross_amount'        => 2200.00,
            'snapshot_monto_apoyo'         => 200.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 2200.00,
            'amount_pending_from_previous' => 0,
            'advance_payment_amount'       => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_scholarship_type'    => 'IU',
        ], $overrides));
    }

    /** @param array<int,array{0:ScholarshipRefrend,1:string}> $months child refrend, amount */
    private function advance(ScholarshipRefrend $origin, array $months, array $monthOverrides = []): ScholarshipAdvancePayment
    {
        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $origin->user_id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => $origin->period_month,
            'months_count'        => count($months),
            'total_amount'        => array_sum(array_map(static fn ($m) => (float) $m[1], $months)),
        ]);

        foreach ($months as $i => [$child, $amount]) {
            ScholarshipAdvancePaymentMonth::create(array_merge([
                'advance_payment_id' => $header->id,
                'user_id'            => $origin->user_id,
                'period_year'        => $child->period_year,
                'period_month'       => $child->period_month,
                'amount'             => $amount,
                'refrend_id'         => $child->id,
                'status'             => 'PENDING',
            ], $monthOverrides[$i] ?? []));
        }

        return $header;
    }

    private function withholding(ScholarshipRefrend $origin, string $withheld): ScholarshipWithholding
    {
        return ScholarshipWithholding::create([
            'user_id'           => $origin->user_id,
            'origin_refrend_id' => $origin->id,
            'period_year'       => 2026,
            'period_month'      => $origin->period_month,
            'withheld_amount'   => $withheld,
            'status'            => 'PENDING',
        ]);
    }

    private function release(ScholarshipWithholding $w, ScholarshipRefrend $applied, string $amount, bool $voided = false): void
    {
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $w->id,
            'applied_refrend_id' => $applied->id,
            'amount'             => $amount,
            'is_voided'          => $voided,
        ]);
    }

    private function resolve(ScholarshipRefrend ...$refrends): array
    {
        return $this->resolver()->forRows($this->rowsFor($refrends));
    }

    private function resolver(): CarryoverConceptResolver
    {
        return $this->app->make(CarryoverConceptResolver::class);
    }

    private function rowsFor(array $refrends): Collection
    {
        return DB::table('scholarship_refrends')->whereIn('id', array_map(static fn ($r) => $r->id, $refrends))->get();
    }

    private function pair(RefrendConceptSplit $s): array
    {
        return [$s->becaCents(), $s->apoyoCents()];
    }

    private function dropRefrendWithoutCascade(ScholarshipRefrend $refrend): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('scholarship_refrends')->where('id', $refrend->id)->delete();
        Schema::enableForeignKeyConstraints();
    }

    // ── advance ──────────────────────────────────────────────────────────────

    /** @test */
    public function sc8_advance_months_are_split_by_each_child_refrend(): void
    {
        $origin = $this->refrend(3, ['advance_payment_amount' => 3000.00]);
        $a      = $this->refrend(4, ['snapshot_gross_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00]);
        $b      = $this->refrend(5, ['snapshot_gross_amount' => 1500.00, 'snapshot_monto_apoyo' => 500.00]);
        $this->advance($origin, [[$a, '1500.00'], [$b, '1500.00']]);

        $this->assertSame([235000, 65000], $this->pair($this->resolve($origin)[$origin->id]));
    }

    /** @test */
    public function a_cancelled_advance_month_is_excluded(): void
    {
        $origin    = $this->refrend(3, ['advance_payment_amount' => 1000.00]);
        $valid     = $this->refrend(4, ['snapshot_gross_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00]);
        $cancelled = $this->refrend(5, ['snapshot_gross_amount' => 200.00, 'snapshot_monto_apoyo' => 200.00]);
        $this->advance($origin, [[$valid, '1000.00'], [$cancelled, '500.00']], [1 => ['status' => 'CANCELLED']]);

        $this->assertSame([90000, 10000], $this->pair($this->resolve($origin)[$origin->id]));
    }

    /** @test */
    public function an_advance_month_whose_child_refrend_is_gone_uses_the_current_row_ratio(): void
    {
        $origin = $this->refrend(3, [
            'advance_payment_amount' => 1000.00, 'snapshot_gross_amount' => 1000.00, 'snapshot_monto_apoyo' => 100.00,
        ]);
        $child = $this->refrend(4);
        $this->advance($origin, [[$child, '1000.00']]);
        $this->dropRefrendWithoutCascade($child);

        $this->assertSame([90000, 10000], $this->pair($this->resolve($origin)[$origin->id]));
    }

    // ── withholding releases ─────────────────────────────────────────────────

    /** @test */
    public function sc9_a_released_withholding_is_split_by_the_origin_month_ratio(): void
    {
        $origin = $this->refrend(1);
        $w      = $this->withholding($origin, '1000.00');
        $row    = $this->refrend(2, ['amount_pending_from_previous' => 300.00]);
        $this->release($w, $row, '300.00');

        $this->assertSame([27273, 2727], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function sc10_a_missing_origin_falls_back_to_the_current_row_ratio(): void
    {
        $origin = $this->refrend(1);
        $w      = $this->withholding($origin, '1000.00');
        $row    = $this->refrend(2, [
            'amount_pending_from_previous' => 100.00, 'snapshot_gross_amount' => 1000.00,
            'snapshot_monto_apoyo' => 100.00, 'final_amount' => 1000.00,
        ]);
        $this->release($w, $row, '100.00');
        $this->dropRefrendWithoutCascade($origin);

        $this->assertSame([9000, 1000], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function sc11_a_zero_weight_origin_falls_back_to_the_current_row_ratio(): void
    {
        $origin = $this->refrend(1, ['snapshot_gross_amount' => 0.00, 'snapshot_monto_apoyo' => 0.00, 'base_amount' => 0]);
        $w      = $this->withholding($origin, '100.00');
        $row    = $this->refrend(2, [
            'amount_pending_from_previous' => 100.00, 'snapshot_gross_amount' => 1000.00,
            'snapshot_monto_apoyo' => 100.00, 'final_amount' => 1000.00,
        ]);
        $this->release($w, $row, '100.00');

        $this->assertSame([9000, 1000], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function a_zero_weight_origin_and_a_zero_weight_row_send_everything_to_beca(): void
    {
        $zero   = ['snapshot_gross_amount' => 0.00, 'snapshot_monto_apoyo' => 0.00, 'base_amount' => 0, 'final_amount' => 0];
        $origin = $this->refrend(1, $zero);
        $w      = $this->withholding($origin, '100.00');
        $row    = $this->refrend(2, array_merge($zero, ['amount_pending_from_previous' => 100.00]));
        $this->release($w, $row, '100.00');

        $this->assertSame([10000, 0], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function sc12_the_residual_between_the_ledger_and_the_column_lands_in_beca(): void
    {
        $origin = $this->refrend(1);
        $w      = $this->withholding($origin, '2200.00');
        $row    = $this->refrend(2, ['amount_pending_from_previous' => 1000.00]);
        $this->release($w, $row, '990.00');

        // ledger 900.00 / 90.00 (S = 990.00) scaled to the 1000.00 column
        $this->assertSame([90909, 9091], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function sc13_only_the_origin_nominal_month_is_used_never_its_own_carryover(): void
    {
        $origin = $this->refrend(1, ['amount_pending_from_previous' => 500.00, 'advance_payment_amount' => 700.00]);
        $w      = $this->withholding($origin, '2200.00');
        $row    = $this->refrend(2, ['amount_pending_from_previous' => 220.00]);
        $this->release($w, $row, '220.00');

        $this->assertSame([20000, 2000], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function a_voided_withholding_payment_is_excluded(): void
    {
        $origin = $this->refrend(1);
        $w      = $this->withholding($origin, '1000.00');
        $row    = $this->refrend(2, [
            'amount_pending_from_previous' => 100.00, 'snapshot_gross_amount' => 200.00,
            'snapshot_monto_apoyo' => 100.00, 'final_amount' => 200.00,
        ]);
        $this->release($w, $row, '100.00', true);

        // the voided payment is ignored: ledger is empty, so the 1:1 row ratio applies
        $this->assertSame([5000, 5000], $this->pair($this->resolve($row)[$row->id]));
    }

    // ── shape, columns and queries ───────────────────────────────────────────

    /** @test */
    public function pending_and_advance_are_summed_and_always_equal_their_columns(): void
    {
        $origin = $this->refrend(1);
        $w      = $this->withholding($origin, '2200.00');
        $row    = $this->refrend(2, ['amount_pending_from_previous' => 220.00, 'advance_payment_amount' => 1000.00]);
        $this->release($w, $row, '220.00');
        $child = $this->refrend(3, ['snapshot_gross_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00]);
        $this->advance($row, [[$child, '1000.00']]);

        $split = $this->resolve($row)[$row->id];

        $this->assertSame([110000, 12000], $this->pair($split));
        $this->assertSame(122000, $split->totalCents());
    }

    /** @test */
    public function a_row_without_carryover_resolves_to_an_empty_split(): void
    {
        $row = $this->refrend(2);

        $this->assertSame([0, 0], $this->pair($this->resolve($row)[$row->id]));
    }

    /** @test */
    public function rows_can_be_keyed_by_refrend_id_as_the_payment_service_selects_it(): void
    {
        $origin = $this->refrend(3, ['advance_payment_amount' => 1000.00]);
        $child  = $this->refrend(4, ['snapshot_gross_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00]);
        $this->advance($origin, [[$child, '1000.00']]);

        $rows = $this->rowsFor([$origin])->map(static function ($r) {
            $r->refrend_id = $r->id;
            unset($r->id);

            return $r;
        });

        $this->assertSame([90000, 10000], $this->pair($this->resolver()->forRows($rows)[$origin->id]));
    }

    /** @test */
    public function an_empty_batch_runs_no_queries(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries) {
            $queries++;
        });

        $this->assertSame([], $this->resolver()->forRows(collect()));
        $this->assertSame(0, $queries);
    }

    /** @test */
    public function it_runs_exactly_two_queries_regardless_of_the_row_count(): void
    {
        $makeBatch = function (int $count): array {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $user   = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
                $origin = $this->refrend(1, [], $user);
                $w      = $this->withholding($origin, '1000.00');
                $row    = $this->refrend(2, ['amount_pending_from_previous' => 100.00, 'advance_payment_amount' => 1000.00], $user);
                $this->release($w, $row, '100.00');
                $child = $this->refrend(3, [], $user);
                $this->advance($row, [[$child, '1000.00']]);
                $rows[] = $row;
            }

            return $rows;
        };

        $counts = [];
        foreach ([1, 6] as $size) {
            $rows = $this->rowsFor($makeBatch($size));
            DB::flushQueryLog();
            DB::enableQueryLog();

            $result = $this->resolver()->forRows($rows);

            $this->assertCount($size, $result);
            $counts[$size] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame([1 => 2, 6 => 2], $counts);
    }
}
