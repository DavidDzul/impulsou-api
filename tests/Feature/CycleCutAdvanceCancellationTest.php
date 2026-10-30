<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S5 (spec R12-R17, S22-S36): the full HTTP flow
 * of a manual cycle cut over advance payments. POST .../situation with
 * BAJA_DEFINITIVA / EGRESADO cancels the advances instead of blocking.
 *
 * Fixture (decision 3b / S31): March origin, advance of April + May at 2500.00
 * each, cut recorded on the April refrend.
 */
class CycleCutAdvanceCancellationTest extends TestCase
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
     * @param array<string, mixed> $originOverrides
     * @return array{user: User, origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, refrends: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>}
     */
    private function makeAdvance(bool $paidOrigin, array $originOverrides = []): array
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

        $origin = $this->makeRefrend($user->id, 3, array_merge(['advance_payment_amount' => '5000.00'], $paid, $originOverrides));

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 3,
            'months_count'        => 2,
            'total_amount'        => '5000.00',
            'status'              => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);

        $refrends = [];
        $months   = [];
        foreach ([4, 5] as $month) {
            $refrends[$month] = $this->makeRefrend($user->id, $month, [
                'created_via'            => 'ADVANCE_PAYMENT',
                'final_amount'           => '0.00',
            ]);
            $months[$month] = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => '2500.00',
                'refrend_id'         => $refrends[$month]->id,
                'status'             => ScholarshipAdvancePaymentMonth::STATUS_PENDING,
            ]);
        }

        return ['user' => $user, 'origin' => $origin, 'header' => $header, 'refrends' => $refrends, 'months' => $months];
    }

    private function cut(ScholarshipRefrend $refrend, string $type = 'BAJA_DEFINITIVA', array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson(
            "/api/admin/scholarship-refrends/{$refrend->id}/situation",
            array_merge(['resolution_type' => $type], $extra)
        );
    }

    /** @test */
    public function a_baja_on_an_advance_cut_month_of_a_paid_origin_cancels_the_advance_and_records_the_overpayment(): void
    {
        $f = $this->makeAdvance(true);
        $originBefore = $f['origin']->fresh()->only(['final_amount', 'status', 'workflow_status', 'advance_payment_amount', 'payment_batch_id']);

        $this->cut($f['refrends'][4], 'BAJA_DEFINITIVA', ['resolution_notes' => 'Nota del personal'])
            ->assertOk();

        foreach ([4, 5] as $month) {
            $this->assertSame('CANCELLED', $f['months'][$month]->fresh()->status);
        }

        $header = $f['header']->fresh();
        $this->assertSame('CANCELLED', $header->status);
        $this->assertSame('5000.00', $header->overpaid_amount);
        $this->assertSame('5000.00', $header->cancelled_amount);
        $this->assertSame($f['refrends'][4]->id, $header->cancelled_cut_refrend_id);

        $this->assertEquals($originBefore, $f['origin']->fresh()->only(array_keys($originBefore)));

        $may = $f['refrends'][5]->fresh();
        $this->assertSame(RefrendStatus::CANCELLED, $may->status);
        $this->assertSame('CLOSED', $may->workflow_status);
        $this->assertSame('0.00', $may->final_amount);
        $this->assertNotNull($may->locked_at);
        $this->assertSame('Cancelado por BAJA DEFINITIVA del 04/2026', $may->resolution_notes);

        $april = $f['refrends'][4]->fresh();
        $this->assertSame('BAJA_DEFINITIVA', $april->resolution_type);
        $this->assertSame('Nota del personal', $april->resolution_notes);
        $this->assertSame('0.00', $april->final_amount);
    }

    /** @test */
    public function the_cut_writes_the_origin_log_with_months_amounts_and_overpayment(): void
    {
        $f = $this->makeAdvance(true);

        $this->cut($f['refrends'][4])->assertOk();

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $f['origin']->id)
            ->where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, (int) $log->performed_by_id);
        $this->assertStringContainsString('BAJA DEFINITIVA', $log->notes);
        $this->assertStringContainsString('04/2026', $log->notes);
        $this->assertSame('5000.00', $log->new_values['cancelled_amount']);
        $this->assertSame('5000.00', $log->new_values['overpaid_amount']);
        $this->assertSame('0.00', $log->new_values['advance_payment_reduction']);
        $this->assertTrue($log->new_values['origin_paid']);
        $this->assertSame(
            [['period_year' => 2026, 'period_month' => 4, 'amount' => '2500.00'], ['period_year' => 2026, 'period_month' => 5, 'amount' => '2500.00']],
            $log->new_values['months']
        );
        $this->assertSame('ACTIVE', $log->old_values['header_status']);
        $this->assertSame('5000.00', $log->old_values['advance_payment_amount']);
    }

    /** @test */
    public function the_cycle_closed_log_note_lists_the_cancelled_months(): void
    {
        $f = $this->makeAdvance(true);

        $this->cut($f['refrends'][4])->assertOk();

        $note = ScholarshipRefrendLog::where('scholarship_refrend_id', $f['refrends'][4]->id)
            ->where('action', 'CYCLE_CLOSED')
            ->value('notes');

        $this->assertStringContainsString('Meses adelantados cancelados: 04/2026, 05/2026', $note);
    }

    /** @test */
    public function an_unpaid_origin_has_its_advance_amount_reduced_including_the_cut_month(): void
    {
        $f = $this->makeAdvance(false);

        $this->cut($f['refrends'][4])->assertOk();

        $this->assertSame('0.00', $f['origin']->fresh()->advance_payment_amount);
        $header = $f['header']->fresh();
        $this->assertSame('0.00', $header->overpaid_amount);
        $this->assertSame('5000.00', $header->cancelled_amount);
        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $f['origin']->id)
            ->where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->first();
        $this->assertSame('5000.00', $log->new_values['advance_payment_reduction']);
        $this->assertFalse($log->new_values['origin_paid']);
    }

    /** @test */
    public function an_egresado_cut_uses_the_egreso_label_in_the_cascade_note(): void
    {
        $f = $this->makeAdvance(true);

        $this->cut($f['refrends'][4], 'EGRESADO')->assertOk();

        $this->assertSame('Cancelado por EGRESO del 04/2026', $f['refrends'][5]->fresh()->resolution_notes);
        $this->assertSame('CANCELLED', $f['months'][4]->fresh()->status);
        $this->assertSame('CANCELLED', $f['header']->fresh()->status);
    }

    /** @test */
    public function a_cut_in_the_middle_of_the_advance_leaves_earlier_months_and_marks_the_header_partial(): void
    {
        $f = $this->makeAdvance(false);

        // Cut in May: April stays an owed month, only May is cancelled.
        $this->cut($f['refrends'][5])->assertOk();

        $this->assertSame('PENDING', $f['months'][4]->fresh()->status);
        $this->assertSame('CANCELLED', $f['months'][5]->fresh()->status);
        $this->assertSame('PARTIALLY_CANCELLED', $f['header']->fresh()->status);
        $this->assertSame('2500.00', $f['origin']->fresh()->advance_payment_amount);
    }

    /** @test */
    public function reverting_an_advance_after_the_cut_is_rejected_as_cycle_closed(): void
    {
        $f = $this->makeAdvance(false);

        $this->cut($f['refrends'][4])->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$f['origin']->id}/advance-payment/revert", ['reason' => 'Intento de revertir tras el corte'])
            ->assertStatus(409)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_CYCLE_CLOSED']);

        $this->assertNotNull($f['header']->fresh());
    }

    /** @test */
    public function a_later_paid_advance_refrend_still_blocks_the_whole_cut_and_nothing_is_written(): void
    {
        $f = $this->makeAdvance(false);
        $f['refrends'][5]->update(['workflow_status' => 'CLOSED', 'status' => RefrendStatus::PAID->value, 'locked_at' => now()]);

        $this->cut($f['refrends'][4])
            ->assertStatus(422)
            ->assertJson([
                'res'  => false,
                'code' => 'CYCLE_CUT_BLOCKED',
                'data' => ['blocking_months' => [['year' => 2026, 'month' => 5, 'reason' => 'PAID']]],
            ]);

        $this->assertSame('ACTIVE', $f['header']->fresh()->status);
        $this->assertSame('PENDING', $f['months'][4]->fresh()->status);
        $this->assertSame('PENDING', $f['months'][5]->fresh()->status);
        $this->assertSame('5000.00', $f['origin']->fresh()->advance_payment_amount);
        $this->assertNull($f['refrends'][4]->fresh()->resolution_type);
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->count());
    }

    /** @test */
    public function the_cut_month_is_not_re_marked_by_the_reconciler_and_raises_no_divergence(): void
    {
        $f = $this->makeAdvance(true);

        // final_amount is 0 on a baja; the reconciler must still leave the
        // cancelled row alone (it would otherwise flip it to REACHED).
        $this->cut($f['refrends'][4])->assertOk();

        $april = $f['months'][4]->fresh();
        $this->assertSame('CANCELLED', $april->status);
        $this->assertNull($april->reached_at);
        $this->assertNull($april->settled_resolution_type);
    }

    /** @test */
    public function an_active_telmex_coverage_blocks_the_cut_and_nothing_is_cancelled(): void
    {
        $f = $this->makeAdvance(true);
        TelmexCoverage::create([
            'user_id'                        => $f['user']->id,
            'scholarship_type_at_activation' => 'TELMEX',
            'start_period'                   => '2026-01-01',
            'status'                         => 'ACTIVA',
        ]);

        $this->cut($f['refrends'][4])->assertStatus(422)->assertJson(['code' => 'CYCLE_CUT_ACTIVE_TELMEX_COVERAGE']);

        $this->assertSame('ACTIVE', $f['header']->fresh()->status);
        $this->assertSame('PENDING', $f['months'][4]->fresh()->status);
        $this->assertSame('0.00', $f['header']->fresh()->overpaid_amount);
    }

    /** @test */
    public function pending_withholdings_block_the_cut_and_nothing_is_cancelled(): void
    {
        $f = $this->makeAdvance(false);
        $old = $this->makeRefrend($f['user']->id, 1);
        ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $old->id,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '150.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);

        $this->cut($f['refrends'][4])->assertStatus(422)->assertJson(['code' => 'CYCLE_CUT_PENDING_WITHHOLDINGS']);

        $this->assertSame('PENDING', $f['months'][5]->fresh()->status);
        $this->assertSame('5000.00', $f['origin']->fresh()->advance_payment_amount);
        $this->assertSame('ACTIVE', $f['header']->fresh()->status);
    }

    /** @test */
    public function a_later_advance_refrend_with_abonos_applied_still_blocks_the_cut(): void
    {
        $f = $this->makeAdvance(false);
        $old = $this->makeRefrend($f['user']->id, 1);
        $withholding = ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $old->id,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '300.00',
            'status'            => 'PAID',
        ]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $withholding->id,
            'applied_refrend_id' => $f['refrends'][5]->id,
            'amount'             => '300.00',
            'created_by_id'      => $this->admin->id,
        ]);

        $this->cut($f['refrends'][4])
            ->assertStatus(422)
            ->assertJson(['code' => 'CYCLE_CUT_BLOCKED', 'data' => ['blocking_months' => [['year' => 2026, 'month' => 5, 'reason' => 'ABONOS_APPLIED']]]]);

        $this->assertSame('PENDING', $f['months'][5]->fresh()->status);
    }
}
