<?php

namespace Tests\Feature;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S6 (spec R20, S42-S47): read-only preview of a
 * manual cycle cut. GET .../cycle-cut-preview?reason=BAJA_DEFINITIVA|EGRESADO.
 *
 * Fixture (decision 3b): March origin, advance of April + May at 2500.00 each,
 * cut evaluated on the April refrend.
 */
class CycleCutPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeRefrend(int $userId, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    /**
     * @param array<int, string> $monthAmounts month => amount
     * @return array{user: User, origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, refrends: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>}
     */
    private function makeAdvance(bool $paidOrigin, array $monthAmounts = [4 => '2500.00', 5 => '2500.00'], string $originAmount = '5000.00'): array
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        $paid = [];
        if ($paidOrigin) {
            $batch = ScholarshipPaymentBatch::create([
                'campus'          => 'MERIDA',
                'period_year'     => 2026,
                'period_month'    => 3,
                'batch_type'      => 'NORMAL',
                'refrend_count'   => 1,
                'total_amount'    => '1000.00',
                'processed_by_id' => $this->admin->id,
                'processed_at'    => now(),
            ]);
            $paid = ['payment_batch_id' => $batch->id];
        }

        $origin = $this->makeRefrend($user->id, 3, array_merge(['advance_payment_amount' => $originAmount], $paid));

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 3,
            'months_count'        => count($monthAmounts),
            'total_amount'        => number_format(array_sum(array_map('floatval', $monthAmounts)), 2, '.', ''),
            'status'              => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);

        $refrends = [];
        $months   = [];
        foreach ($monthAmounts as $month => $amount) {
            $refrends[$month] = $this->makeRefrend($user->id, $month, [
                'created_via'  => 'ADVANCE_PAYMENT',
                'final_amount' => '0.00',
            ]);
            $months[$month] = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => $amount,
                'refrend_id'         => $refrends[$month]->id,
                'status'             => ScholarshipAdvancePaymentMonth::STATUS_PENDING,
            ]);
        }

        return ['user' => $user, 'origin' => $origin, 'header' => $header, 'refrends' => $refrends, 'months' => $months];
    }

    private function preview(int $refrendId, ?string $reason = 'BAJA_DEFINITIVA')
    {
        $query = $reason === null ? '' : '?reason=' . $reason;

        return $this->actingAs($this->admin)->getJson("/api/admin/scholarship-refrends/{$refrendId}/cycle-cut-preview{$query}");
    }

    /** @return array<string, mixed> */
    private function previewData(int $refrendId, string $reason = 'BAJA_DEFINITIVA'): array
    {
        return $this->preview($refrendId, $reason)->assertOk()->json('data');
    }

    /** @test */
    public function it_returns_the_exact_payload_shape_for_a_paid_origin(): void
    {
        $f = $this->makeAdvance(true);

        $this->preview($f['refrends'][4]->id)
            ->assertOk()
            ->assertExactJson([
                'res'  => true,
                'data' => [
                    'reason'   => 'BAJA_DEFINITIVA',
                    'can_cut'  => true,
                    'blockers' => [],
                    'advance'  => [
                        'months' => [
                            ['period_year' => 2026, 'period_month' => 4, 'amount' => '2500.00', 'is_cut_month' => true, 'origin_period_year' => 2026, 'origin_period_month' => 3],
                            ['period_year' => 2026, 'period_month' => 5, 'amount' => '2500.00', 'is_cut_month' => false, 'origin_period_year' => 2026, 'origin_period_month' => 3],
                        ],
                        'origins' => [
                            [
                                'origin_period_year'        => 2026,
                                'origin_period_month'       => 3,
                                'origin_paid'               => true,
                                'cancelled_amount'          => '5000.00',
                                'overpaid_amount'           => '5000.00',
                                'advance_payment_reduction' => '0.00',
                            ],
                        ],
                        'cancelled_amount'          => '5000.00',
                        'overpaid_amount'           => '5000.00',
                        'advance_payment_reduction' => '0.00',
                    ],
                    'withholdings_to_cancel' => ['rows' => [], 'total_remaining' => '0.00'],
                ],
            ]);
    }

    /** @test */
    public function an_unpaid_origin_reports_the_reduction_instead_of_the_overpayment(): void
    {
        $f = $this->makeAdvance(false);

        $data = $this->previewData($f['refrends'][4]->id, 'EGRESADO');

        $this->assertSame('EGRESADO', $data['reason']);
        $this->assertTrue($data['can_cut']);
        $this->assertFalse($data['advance']['origins'][0]['origin_paid']);
        $this->assertSame('0.00', $data['advance']['overpaid_amount']);
        $this->assertSame('5000.00', $data['advance']['advance_payment_reduction']);
    }

    /** @test */
    public function the_preview_performs_no_writes_and_leaves_the_data_untouched(): void
    {
        $f = $this->makeAdvance(true);
        $headerBefore = $f['header']->fresh()->getAttributes();
        $originBefore = $f['origin']->fresh()->getAttributes();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->preview($f['refrends'][4]->id)->assertOk();

        $this->assertSame([], $writes);
        $this->assertSame($headerBefore, $f['header']->fresh()->getAttributes());
        $this->assertSame($originBefore, $f['origin']->fresh()->getAttributes());
        $this->assertSame('PENDING', $f['months'][4]->fresh()->status);
    }

    /**
     * Parity (S44): what the preview announces is what the real cut produces.
     *
     * @return array<string, mixed> the state derived from the DB after the cut
     */
    private function realCutState(array $f, string $reason): array
    {
        $originBefore = $f['origin']->fresh()->advance_payment_amount;

        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$f['refrends'][4]->id}/situation", ['resolution_type' => $reason])
            ->assertOk();

        $header = $f['header']->fresh();
        $months = ScholarshipAdvancePaymentMonth::where('advance_payment_id', $header->id)
            ->where('status', ScholarshipAdvancePaymentMonth::STATUS_CANCELLED)
            ->orderBy('period_month')
            ->get();

        return [
            'months'    => $months->map(fn ($m) => [
                'period_year'  => (int) $m->period_year,
                'period_month' => (int) $m->period_month,
                'amount'       => (string) $m->amount,
            ])->all(),
            'cancelled' => (string) $header->cancelled_amount,
            'overpaid'  => (string) $header->overpaid_amount,
            'reduction' => bcsub((string) $originBefore, (string) $f['origin']->fresh()->advance_payment_amount, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function previewState(array $data): array
    {
        return [
            'months'    => array_map(
                fn ($m) => ['period_year' => $m['period_year'], 'period_month' => $m['period_month'], 'amount' => $m['amount']],
                $data['advance']['months']
            ),
            'cancelled' => $data['advance']['cancelled_amount'],
            'overpaid'  => $data['advance']['overpaid_amount'],
            'reduction' => $data['advance']['advance_payment_reduction'],
        ];
    }

    /** @test */
    public function the_preview_matches_the_real_cut_for_a_paid_origin(): void
    {
        $f = $this->makeAdvance(true);

        $preview = $this->previewState($this->previewData($f['refrends'][4]->id));
        $real    = $this->realCutState($f, 'BAJA_DEFINITIVA');

        $this->assertSame($real, $preview);
        $this->assertSame('5000.00', $preview['overpaid']);
        $this->assertSame('0.00', $preview['reduction']);
    }

    /** @test */
    public function the_preview_matches_the_real_cut_for_an_unpaid_origin(): void
    {
        $f = $this->makeAdvance(false);

        $preview = $this->previewState($this->previewData($f['refrends'][4]->id));
        $real    = $this->realCutState($f, 'BAJA_DEFINITIVA');

        $this->assertSame($real, $preview);
        $this->assertSame('0.00', $preview['overpaid']);
        $this->assertSame('5000.00', $preview['reduction']);
    }

    /** @test */
    public function the_preview_matches_the_real_cut_for_a_partial_header(): void
    {
        // Advance of Feb..May cut on the April refrend: Feb/Mar untouched.
        $f = $this->makeAdvance(false, [4 => '2500.00', 5 => '2500.00', 6 => '2500.00'], '7500.00');
        $first = $this->makeRefrend($f['user']->id, 2, ['created_via' => 'ADVANCE_PAYMENT', 'final_amount' => '0.00']);
        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $f['header']->id,
            'user_id'            => $f['user']->id,
            'period_year'        => 2026,
            'period_month'       => 2,
            'amount'             => '1000.00',
            'refrend_id'         => $first->id,
            'status'             => ScholarshipAdvancePaymentMonth::STATUS_PENDING,
        ]);

        $data = $this->previewData($f['refrends'][4]->id);
        $this->assertCount(3, $data['advance']['months']);

        $preview = $this->previewState($data);
        $real    = $this->realCutState($f, 'BAJA_DEFINITIVA');

        $this->assertSame($real, $preview);
        $this->assertSame('PARTIALLY_CANCELLED', $f['header']->fresh()->status);
    }

    /** @test */
    public function it_collects_every_blocker_together_and_disables_the_cut(): void
    {
        $f = $this->makeAdvance(true);

        TelmexCoverage::create([
            'user_id'                        => $f['user']->id,
            'scholarship_type_at_activation' => 'TELMEX',
            'start_period'                   => '2026-01-01',
            'status'                         => 'ACTIVA',
        ]);

        $old = $this->makeRefrend($f['user']->id, 1);
        $pending = ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $old->id,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '150.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);

        // Later refrend locked/batched (PAID) and an own abono on the cut refrend.
        $f['refrends'][5]->update(['workflow_status' => 'CLOSED']);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $pending->id,
            'applied_refrend_id' => $f['refrends'][4]->id,
            'amount'             => '50.00',
            'created_by_id'      => $this->admin->id,
        ]);

        ScholarshipProfile::factory()->create([
            'user_id'              => $f['user']->id,
            'cycle_closed_period'  => '2026-02-01',
            'cycle_closure_reason' => 'BAJA_DEFINITIVA',
        ]);

        $data = $this->previewData($f['refrends'][4]->id);

        $this->assertFalse($data['can_cut']);
        $codes = array_column($data['blockers'], 'code');
        $this->assertEqualsCanonicalizing(
            [
                'CYCLE_ALREADY_CLOSED',
                'CYCLE_CUT_OWN_ABONOS',
                'CYCLE_CUT_BLOCKED',
                'CYCLE_CUT_ACTIVE_TELMEX_COVERAGE',
            ],
            $codes
        );
        foreach ($data['blockers'] as $blocker) {
            $this->assertNotSame('', $blocker['code']);
            $this->assertNotSame('', $blocker['msg']);
            $this->assertIsArray($blocker['data']);
        }
        // The planner output is still reported next to the blockers.
        $this->assertCount(2, $data['advance']['months']);
    }

    /** @test */
    public function pending_withholdings_are_listed_for_cancellation_and_do_not_block_the_cut(): void
    {
        $f   = $this->makeAdvance(false);
        $old = $this->makeRefrend($f['user']->id, 1);
        $oldW = ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $old->id,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '100.00',
            'status'            => 'PENDING',
        ]);
        $ownW = ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $f['refrends'][4]->id,
            'period_year'       => 2026,
            'period_month'      => 4,
            'withheld_amount'   => '150.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
        // Already settled / cancelled rows are not part of the plan.
        ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $f['origin']->id,
            'period_year'       => 2026,
            'period_month'      => 3,
            'withheld_amount'   => '80.00',
            'paid_amount'       => '80.00',
            'status'            => 'PAID',
        ]);

        $data = $this->previewData($f['refrends'][4]->id);

        $this->assertTrue($data['can_cut']);
        $this->assertSame([], $data['blockers']);
        $this->assertSame('350.00', $data['withholdings_to_cancel']['total_remaining']);
        $this->assertSame([
            [
                'id' => $oldW->id, 'period_year' => 2026, 'period_month' => 1,
                'withheld_amount' => '300.00', 'paid_amount' => '100.00', 'remaining_amount' => '200.00',
                'is_current_refrend' => false,
            ],
            [
                'id' => $ownW->id, 'period_year' => 2026, 'period_month' => 4,
                'withheld_amount' => '150.00', 'paid_amount' => '0.00', 'remaining_amount' => '150.00',
                'is_current_refrend' => true,
            ],
        ], $data['withholdings_to_cancel']['rows']);
    }

    /** @test */
    public function the_withholding_preview_matches_what_the_real_cut_cancels(): void
    {
        $f   = $this->makeAdvance(false);
        $old = $this->makeRefrend($f['user']->id, 1);
        ScholarshipWithholding::create([
            'user_id' => $f['user']->id, 'origin_refrend_id' => $old->id,
            'period_year' => 2026, 'period_month' => 1,
            'withheld_amount' => '300.00', 'paid_amount' => '100.00', 'status' => 'PENDING',
        ]);
        ScholarshipWithholding::create([
            'user_id' => $f['user']->id, 'origin_refrend_id' => $f['refrends'][4]->id,
            'period_year' => 2026, 'period_month' => 4,
            'withheld_amount' => '150.00', 'paid_amount' => '0.00', 'status' => 'PENDING',
        ]);

        $preview = $this->previewData($f['refrends'][4]->id)['withholdings_to_cancel'];
        $this->realCutState($f, 'BAJA_DEFINITIVA');

        $cancelled = ScholarshipWithholding::where('user_id', $f['user']->id)
            ->where('cancellation_reason', 'CYCLE_CUT')
            ->orderBy('id')
            ->get();

        $this->assertSame(array_column($preview['rows'], 'id'), $cancelled->pluck('id')->all());
        $this->assertSame(
            $preview['total_remaining'],
            bcsub((string) $cancelled->sum('withheld_amount'), (string) $cancelled->sum('paid_amount'), 2)
        );
        $this->assertSame(0, ScholarshipWithholding::where('user_id', $f['user']->id)->where('status', 'PENDING')->count());
    }

    /** @test */
    public function a_single_blocker_is_reported_alone(): void
    {
        $f = $this->makeAdvance(false);
        TelmexCoverage::create([
            'user_id'                        => $f['user']->id,
            'scholarship_type_at_activation' => 'TELMEX',
            'start_period'                   => '2026-01-01',
            'status'                         => 'ACTIVA',
        ]);

        $data = $this->previewData($f['refrends'][4]->id);

        $this->assertFalse($data['can_cut']);
        $this->assertSame(['CYCLE_CUT_ACTIVE_TELMEX_COVERAGE'], array_column($data['blockers'], 'code'));
    }

    /** @test */
    public function a_later_paid_refrend_blocks_the_preview(): void
    {
        $f = $this->makeAdvance(false);
        $f['refrends'][5]->update(['workflow_status' => 'CLOSED', 'locked_at' => now()]);

        $data = $this->previewData($f['refrends'][4]->id);

        $this->assertFalse($data['can_cut']);
        $this->assertSame(['CYCLE_CUT_BLOCKED'], array_column($data['blockers'], 'code'));
    }

    /** @test */
    public function an_invalid_or_missing_reason_is_rejected_with_422(): void
    {
        $f = $this->makeAdvance(true);

        $this->preview($f['refrends'][4]->id, 'BECA_MES')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->preview($f['refrends'][4]->id, null)->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /** @test */
    public function an_unknown_refrend_answers_a_typed_404(): void
    {
        $this->preview(999999)
            ->assertStatus(404)
            ->assertJson(['res' => false, 'code' => 'NOT_FOUND']);
    }

    /** @test */
    public function a_non_numeric_refrend_id_does_not_match_the_route(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/scholarship-refrends/abc/cycle-cut-preview?reason=BAJA_DEFINITIVA')
            ->assertStatus(404);
    }

    /** @test */
    public function a_refrend_without_advances_returns_empty_months_and_can_cut(): void
    {
        $user    = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $refrend = $this->makeRefrend($user->id, 4);

        $this->preview($refrend->id)
            ->assertOk()
            ->assertExactJson([
                'res'  => true,
                'data' => [
                    'reason'   => 'BAJA_DEFINITIVA',
                    'can_cut'  => true,
                    'blockers' => [],
                    'advance'  => [
                        'months'                    => [],
                        'origins'                   => [],
                        'cancelled_amount'          => '0.00',
                        'overpaid_amount'           => '0.00',
                        'advance_payment_reduction' => '0.00',
                    ],
                    'withholdings_to_cancel' => ['rows' => [], 'total_remaining' => '0.00'],
                ],
            ]);
    }

    /** @test */
    public function the_endpoint_requires_an_admin(): void
    {
        $f = $this->makeAdvance(true);
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->actingAs($becario)
            ->getJson("/api/admin/scholarship-refrends/{$f['refrends'][4]->id}/cycle-cut-preview?reason=BAJA_DEFINITIVA")
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/scholarship-refrends/{$f['refrends'][4]->id}/cycle-cut-preview?reason=BAJA_DEFINITIVA")
            ->assertStatus(401);
    }
}
