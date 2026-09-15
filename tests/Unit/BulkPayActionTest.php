<?php

namespace Tests\Unit;

use App\Actions\Scholarship\BulkPayAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\PaymentReadinessEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for BulkPayAction (PR3, sdd/becario-payment-file-generation).
 *
 * Mirrors PaymentBatchServiceTest's fixture conventions (plain
 * ScholarshipRefrend::create(), User::factory(), a `makePayableRefrend()`
 * helper that is fully ready by default so overrides can flip one thing at a
 * time) and BulkApproveAction's shape convention
 * (compact('paid','skipped','errors'), analogous to
 * compact('approved','skipped','errors')).
 */
class BulkPayActionTest extends TestCase
{
    use RefreshDatabase;

    private BulkPayAction $action;
    private User $admin;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(BulkPayAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        // ScholarshipLoggingService::log() reads auth()->id() internally
        // (matches ApproveFullPaymentActionTest's setUp convention).
        $this->actingAs($this->admin);
    }

    /**
     * A becario + refrend that is fully payable by default (LISTO_PARA_PAGO,
     * unlocked, enrollment present, bank data present, not WITHHELD).
     */
    private function makePayableRefrend(
        array $refrendOverrides = [],
        array $userOverrides = [],
        bool $withPaymentData = true,
        string $accountNumber = '0123456789'
    ): ScholarshipRefrend {
        $user = User::factory()->create(array_merge([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => self::CAMPUS,
            'active'    => true,
        ], $userOverrides));

        if ($withPaymentData) {
            ScholarshipPaymentData::create([
                'user_id'        => $user->id,
                'bank_name'      => 'BBVA',
                'account_number' => $accountNumber,
                'curp'           => 'CURP010101HDFXXX01',
                'rfc'            => 'PEPJ800101ABC',
            ]);
        }

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $refrendOverrides));
    }

    // ── Success path ─────────────────────────────────────────────────────────

    /** @test */
    public function pays_an_eligible_refrend_and_writes_all_lock_fields(): void
    {
        $refrend = $this->makePayableRefrend();

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['paid']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['errors']);

        $refrend->refresh();
        $this->assertSame(RefrendStatus::PAID, $refrend->status);
        $this->assertSame('CLOSED', $refrend->workflow_status);
        $this->assertNotNull($refrend->locked_at);
        $this->assertSame($this->admin->id, $refrend->locked_by_id);
        $this->assertNull($refrend->payment_batch_id);
    }

    /** @test */
    public function stamps_payment_batch_id_when_a_batch_is_provided(): void
    {
        $refrend = $this->makePayableRefrend();
        $batch   = ScholarshipPaymentBatch::create([
            'generation_id'   => 1,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $this->admin->id,
            'processed_at'    => now(),
        ]);

        $this->action->execute([$refrend->id], $this->admin->id, $batch);

        $refrend->refresh();
        $this->assertSame($batch->id, $refrend->payment_batch_id);
    }

    /** @test */
    public function logs_bulk_pay_action_for_each_paid_refrend(): void
    {
        $refrend = $this->makePayableRefrend();

        $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertDatabaseHas('scholarship_refrend_logs', [
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'BULK_PAY',
        ]);
    }

    /** @test */
    public function withheld_refrend_with_positive_final_amount_is_paid(): void
    {
        $refrend = $this->makePayableRefrend([
            'status'          => RefrendStatus::WITHHELD->value,
            'discount_amount' => 300.00,
            'final_amount'    => 700.00,
        ]);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['paid']);
        $refrend->refresh();
        $this->assertSame(RefrendStatus::PAID, $refrend->status);
    }

    // ── Skip path — reuses PaymentReadinessEvaluator's codes ───────────────────

    /** @test */
    public function skips_a_refrend_not_yet_approved_with_the_correct_reason(): void
    {
        $refrend = $this->makePayableRefrend(['workflow_status' => 'PENDIENTE_NOTIFICACION']);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(0, $result['paid']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame($refrend->id, $result['errors'][0]['id']);
        $this->assertStringContainsString('Pendiente de aprobación', $result['errors'][0]['reason']);

        $refrend->refresh();
        $this->assertSame('PENDIENTE_NOTIFICACION', $refrend->workflow_status);
        $this->assertNull($refrend->locked_at);
    }

    /** @test */
    public function skips_a_refrend_missing_enrollment_with_the_correct_reason(): void
    {
        $refrend = $this->makePayableRefrend([], ['enrollment' => null]);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Sin matrícula registrada', $result['errors'][0]['reason']);
    }

    /** @test */
    public function skips_a_refrend_missing_payment_data_with_the_correct_reason(): void
    {
        $refrend = $this->makePayableRefrend([], [], withPaymentData: false);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Sin cuenta bancaria registrada', $result['errors'][0]['reason']);
    }

    /** @test */
    public function skips_a_withheld_refrend_with_zero_final_amount(): void
    {
        $refrend = $this->makePayableRefrend([
            'status'          => RefrendStatus::WITHHELD->value,
            'discount_amount' => 1000.00,
            'final_amount'    => 0.00,
        ]);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Monto retenido sin saldo por pagar', $result['errors'][0]['reason']);

        $refrend->refresh();
        $this->assertSame(RefrendStatus::WITHHELD, $refrend->status);
    }

    /** @test */
    public function skips_an_already_paid_refrend(): void
    {
        $refrend = $this->makePayableRefrend(['locked_at' => now()]);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Ya fue procesado en un pago anterior', $result['errors'][0]['reason']);
    }

    // ── Bank data rules (PR3, sdd/becario-payment-bank-file-export) ────────

    /** @test */
    public function skips_a_refrend_with_a_malformed_account_number(): void
    {
        // This becario would have been PAID before this batch — the new
        // bank-data validation is a real, intentional behavior change.
        $refrend = $this->makePayableRefrend(accountNumber: '12345678A');

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(0, $result['paid']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Número de cuenta inválido', $result['errors'][0]['reason']);

        $refrend->refresh();
        $this->assertNull($refrend->locked_at);
        $this->assertNull($refrend->payment_batch_id);
    }

    /** @test */
    public function pays_a_refrend_with_a_well_formed_account_number_and_rfc(): void
    {
        $refrend = $this->makePayableRefrend(accountNumber: '0123456789');

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(1, $result['paid']);
        $this->assertSame(0, $result['skipped']);
    }

    /** @test */
    public function processes_a_mixed_batch_with_correct_paid_and_skipped_counts(): void
    {
        $ready   = $this->makePayableRefrend();
        $blocked = $this->makePayableRefrend(['workflow_status' => 'DRAFT']);

        $result = $this->action->execute([$ready->id, $blocked->id], $this->admin->id);

        $this->assertSame(1, $result['paid']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame($blocked->id, $result['errors'][0]['id']);
    }

    // ── Re-evaluation under the lock (design's concurrency requirement) ───────

    /** @test */
    public function reevaluates_eligibility_under_the_lock_instead_of_trusting_the_caller(): void
    {
        // The caller passes an id that is NOT payable (already CLOSED) — the
        // action must not blindly trust the id list, it must re-check via
        // PaymentReadinessEvaluator under lockForUpdate().
        $refrend = $this->makePayableRefrend(['workflow_status' => 'CLOSED', 'status' => RefrendStatus::PAID->value]);

        $result = $this->action->execute([$refrend->id], $this->admin->id);

        $this->assertSame(0, $result['paid']);
        $this->assertSame(1, $result['skipped']);
    }

    // ── All-or-nothing transaction ─────────────────────────────────────────────

    /** @test */
    public function rolls_back_the_whole_transaction_when_a_mid_batch_failure_occurs(): void
    {
        $first  = $this->makePayableRefrend();
        $second = $this->makePayableRefrend();

        $callCount = 0;
        $evaluator = \Mockery::mock(PaymentReadinessEvaluator::class);
        $evaluator->shouldReceive('evaluate')->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 2) {
                throw new \RuntimeException('Simulated mid-batch failure');
            }

            return ['is_payable' => true, 'blocking_reasons' => []];
        });
        $this->app->instance(PaymentReadinessEvaluator::class, $evaluator);

        $action = $this->app->make(BulkPayAction::class);

        $caught = null;
        try {
            $action->execute([$first->id, $second->id], $this->admin->id);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected the mid-batch failure to propagate out of the transaction.');
        $this->assertSame('Simulated mid-batch failure', $caught->getMessage());

        $first->refresh();
        $second->refresh();

        $this->assertNotSame(RefrendStatus::PAID, $first->status, 'The first record must NOT be committed — the whole batch rolls back.');
        $this->assertNull($first->locked_at);
        $this->assertNull($second->locked_at);
        $this->assertDatabaseMissing('scholarship_refrend_logs', ['scholarship_refrend_id' => $first->id]);
    }
}
