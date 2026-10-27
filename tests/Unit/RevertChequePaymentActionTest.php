<?php

namespace Tests\Unit;

use App\Actions\Scholarship\MarkChequePaidAction;
use App\Actions\Scholarship\RevertChequePaymentAction;
use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\TelmexCoverage;
use App\Models\TelmexCoveragePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque PR4 (spec R4, amendment A1): revert a cheque payment.
 */
class RevertChequePaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Cheque extraviado, se reexpide';

    private RevertChequePaymentAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(RevertChequePaymentAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    private function makeRefrend(array $overrides = [], bool $cheque = true): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => 'MERIDA',
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);

        $cheque
            ? ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $user->id])
            : ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '0123456789', 'rfc' => 'PEPJ800101ABC']);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Becario',
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function markPaid(ScholarshipRefrend $r): ScholarshipRefrend
    {
        return $this->app->make(MarkChequePaidAction::class)
            ->execute($r->id, $this->admin->id, 'A123', '2026-05-10', 'nota');
    }

    private function revert(ScholarshipRefrend $r, string $reason = self::REASON): ScholarshipRefrend
    {
        return $this->action->execute($r->id, $reason);
    }

    private function failure(callable $fn): ChequePaymentException
    {
        try {
            $fn();
        } catch (ChequePaymentException $e) {
            return $e;
        }
        $this->fail('Expected ChequePaymentException');
    }

    private function makeProfile(ScholarshipRefrend $r, array $overrides = []): ScholarshipProfile
    {
        return ScholarshipProfile::factory()->create(array_merge(['user_id' => $r->user_id], $overrides));
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    /** @test */
    public function unknown_refrend_throws_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->action->execute(999999, self::REASON);
    }

    /** @test */
    public function unpaid_refrend_is_rejected_with_not_cheque_paid(): void
    {
        $refrend = $this->makeRefrend();

        $e = $this->failure(fn () => $this->revert($refrend));

        $this->assertSame('NOT_CHEQUE_PAID', $e->code());
        $this->assertSame(422, $e->httpStatus());
    }

    /** @test */
    public function transfer_paid_refrend_is_rejected_with_not_cheque_paid(): void
    {
        $refrend = $this->makeRefrend([
            'status'              => RefrendStatus::PAID->value,
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'paid_payment_method' => PaymentMethod::TRANSFER->value,
        ], false);

        $e = $this->failure(fn () => $this->revert($refrend));

        $this->assertSame('NOT_CHEQUE_PAID', $e->code());
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function legacy_paid_refrend_without_method_snapshot_is_rejected(): void
    {
        $refrend = $this->makeRefrend([
            'status'          => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);

        $this->assertSame('NOT_CHEQUE_PAID', $this->failure(fn () => $this->revert($refrend))->code());
    }

    /** @test */
    public function refrend_paid_in_a_batch_is_rejected_even_if_flagged_cheque(): void
    {
        $refrend = $this->makeRefrend();
        $paid    = $this->markPaid($refrend);
        $batch   = ScholarshipPaymentBatch::create([
            'generation_id'   => 1,
            'campus'          => 'MERIDA',
            'period_year'     => 2026,
            'period_month'    => 5,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $this->admin->id,
            'processed_at'    => now(),
        ]);
        $paid->forceFill(['payment_batch_id' => $batch->id])->save();

        $this->assertSame('NOT_CHEQUE_PAID', $this->failure(fn () => $this->revert($paid))->code());
    }

    // ── Restore ─────────────────────────────────────────────────────────────

    /** @test */
    public function restores_authorized_and_clears_every_cheque_field(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());

        $reverted = $this->revert($refrend);

        $this->assertSame(RefrendStatus::DRAFT, $reverted->status);
        $this->assertSame('LISTO_PARA_PAGO', $reverted->workflow_status);
        $this->assertNull($reverted->locked_at);
        $this->assertNull($reverted->locked_by_id);
        $this->assertNull($reverted->paid_payment_method);
        $this->assertNull($reverted->cheque_number);
        $this->assertNull($reverted->cheque_delivered_at);
        $this->assertNull($reverted->cheque_notes);
        $this->assertNull($reverted->payment_batch_id);
    }

    /** @test */
    public function restores_withheld_with_listo_para_pago_exactly(): void
    {
        $refrend = $this->markPaid($this->makeRefrend([
            'status' => RefrendStatus::WITHHELD->value,
        ]));
        $this->assertSame(RefrendStatus::PAID, $refrend->status);

        $reverted = $this->revert($refrend);

        $this->assertSame(RefrendStatus::WITHHELD, $reverted->status);
        $this->assertSame('LISTO_PARA_PAGO', $reverted->workflow_status);
    }

    /** @test */
    public function missing_cheque_paid_log_is_rejected_and_nothing_changes(): void
    {
        $refrend = $this->makeRefrend([
            'status'              => RefrendStatus::PAID->value,
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'locked_by_id'        => $this->admin->id,
            'paid_payment_method' => PaymentMethod::CHEQUE->value,
            'cheque_number'       => 'X1',
        ]);

        $e = $this->failure(fn () => $this->revert($refrend));

        $this->assertSame('CHEQUE_LOG_MISSING', $e->code());
        $this->assertSame(422, $e->httpStatus());
        $fresh = $refrend->fresh();
        $this->assertSame(RefrendStatus::PAID, $fresh->status);
        $this->assertSame('X1', $fresh->cheque_number);
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'CHEQUE_PAYMENT_REVERTED')->count());
    }

    /** @test */
    public function log_with_invalid_prior_status_is_rejected(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $log     = ScholarshipRefrendLog::where('action', 'CHEQUE_PAID')->firstOrFail();
        $old     = $log->old_values;
        $old['status'] = 'CANCELLED';
        $log->update(['old_values' => $old]);

        $this->assertSame('CHEQUE_LOG_MISSING', $this->failure(fn () => $this->revert($refrend))->code());
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function log_with_a_removed_legacy_prior_status_yields_cheque_log_missing(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $log     = ScholarshipRefrendLog::where('action', 'CHEQUE_PAID')->firstOrFail();
        $old     = $log->old_values;
        $old['status'] = 'AUTHORIZED';
        $log->update(['old_values' => $old]);

        $e = $this->failure(fn () => $this->revert($refrend));

        $this->assertSame('CHEQUE_LOG_MISSING', $e->code());
        $this->assertSame(422, $e->httpStatus());
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function log_with_invalid_prior_workflow_is_rejected(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $log     = ScholarshipRefrendLog::where('action', 'CHEQUE_PAID')->firstOrFail();
        $old     = $log->old_values;
        $old['workflow_status'] = 'CON_INCIDENCIA';
        $log->update(['old_values' => $old]);

        $this->assertSame('CHEQUE_LOG_MISSING', $this->failure(fn () => $this->revert($refrend))->code());
    }

    /** @test */
    public function uses_the_latest_cheque_paid_log(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $this->revert($refrend);
        // Second payment cycle; the first CHEQUE_PAID log (id lower) must not win.
        $refrend->fresh()->update(['status' => RefrendStatus::WITHHELD->value]);
        $paid = $this->markPaid($refrend->fresh());

        $reverted = $this->revert($paid);

        $this->assertSame(RefrendStatus::WITHHELD, $reverted->status);
    }

    // ── Log ─────────────────────────────────────────────────────────────────

    /** @test */
    public function logs_cheque_payment_reverted_with_reason_and_snapshots(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());

        $this->revert($refrend);

        $log = ScholarshipRefrendLog::where('action', 'CHEQUE_PAYMENT_REVERTED')->firstOrFail();
        $this->assertSame($refrend->id, $log->scholarship_refrend_id);
        $this->assertSame($this->admin->id, $log->performed_by_id);
        $this->assertSame(self::REASON, $log->notes);
        $this->assertSame('PAID', $log->old_values['status']);
        $this->assertSame('A123', $log->old_values['cheque_number']);
        $this->assertSame('DRAFT', $log->new_values['status']);
        $this->assertNull($log->new_values['cheque_number']);
    }

    /** @test */
    public function can_be_marked_again_after_a_revert(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $this->revert($refrend);

        $again = $this->app->make(MarkChequePaidAction::class)
            ->execute($refrend->id, $this->admin->id, 'B999', null, null);

        $this->assertSame(RefrendStatus::PAID, $again->status);
        $this->assertSame('B999', $again->cheque_number);
        $this->assertSame(PaymentMethod::CHEQUE, $again->paid_payment_method);
    }

    // ── CYCLE_CLOSED (amendment A1) ─────────────────────────────────────────

    /** @test */
    public function closed_cycle_blocks_the_revert_with_409_and_changes_nothing(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $this->makeProfile($refrend, ['cycle_closed_period' => '2026-06-01']);

        $e = $this->failure(fn () => $this->revert($refrend));

        $this->assertSame('CYCLE_CLOSED', $e->code());
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame('El ciclo del becario está cerrado.', $e->getMessage());
        $fresh = $refrend->fresh();
        $this->assertSame(RefrendStatus::PAID, $fresh->status);
        $this->assertSame('A123', $fresh->cheque_number);
        $this->assertNotNull($fresh->locked_at);
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'CHEQUE_PAYMENT_REVERTED')->count());
    }

    /** @test */
    public function open_cycle_allows_the_revert(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $this->makeProfile($refrend, ['cycle_closed_period' => null]);

        $this->assertSame(RefrendStatus::DRAFT, $this->revert($refrend)->status);
    }

    /** @test */
    public function becario_without_profile_allows_the_revert(): void
    {
        $refrend = $this->markPaid($this->makeRefrend());
        $this->assertNull(ScholarshipProfile::where('user_id', $refrend->user_id)->first());

        $this->assertSame(RefrendStatus::DRAFT, $this->revert($refrend)->status);
    }

    /** @test */
    public function not_cheque_paid_guard_runs_before_cycle_closed(): void
    {
        $refrend = $this->makeRefrend();
        $this->makeProfile($refrend, ['cycle_closed_period' => '2026-06-01']);

        $this->assertSame('NOT_CHEQUE_PAID', $this->failure(fn () => $this->revert($refrend))->code());
    }

    // ── Telmex coverage ledger (review M1) ──────────────────────────────────

    private function makeCoveredRefrend(string $coverageStatus = 'ACTIVA'): array
    {
        $refrend = $this->makeRefrend();
        $coverage = TelmexCoverage::create([
            'user_id'                        => $refrend->user_id,
            'scholarship_type_at_activation' => 'TELMEX',
            'start_period'                   => '2026-01-01',
            'end_period'                     => '2026-05-01',
            'status'                         => $coverageStatus,
        ]);
        $refrend->update([
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'snapshot_telmex_covered_amount' => 300,
        ]);

        return [$refrend->fresh(), $coverage];
    }

    private function makeRepayment(TelmexCoverage $coverage, bool $voided): TelmexCoveragePayment
    {
        return TelmexCoveragePayment::create([
            'coverage_id'   => $coverage->id,
            'amount'        => 100,
            'paid_at'       => '2026-06-01',
            'created_by_id' => $this->admin->id,
            'is_voided'     => $voided,
        ]);
    }

    /** @test */
    public function non_voided_telmex_repayment_blocks_the_revert_with_409(): void
    {
        [$refrend, $coverage] = $this->makeCoveredRefrend();
        $refrend = $this->markPaid($refrend);
        $this->makeRepayment($coverage, false);

        $e = $this->failure(fn () => $this->revert($refrend));

        $this->assertSame('TELMEX_REPAYMENT_EXISTS', $e->code());
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame('El mes tiene reembolsos de Telmex registrados.', $e->getMessage());
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function voided_telmex_repayments_do_not_block_and_status_is_recomputed(): void
    {
        [$refrend, $coverage] = $this->makeCoveredRefrend('ACTIVA');
        $refrend = $this->markPaid($refrend);
        $this->makeRepayment($coverage, true);

        $this->assertSame(RefrendStatus::DRAFT, $this->revert($refrend)->status);
        // closed window (end_period set) + nothing advanced anymore -> EN_COBRO, not the stale ACTIVA
        $this->assertSame('EN_COBRO', $coverage->fresh()->status);
    }

    /** @test */
    public function mark_and_revert_on_a_telmex_covered_refrend_restores_everything(): void
    {
        [$refrend, $coverage] = $this->makeCoveredRefrend();

        $paid = $this->markPaid($refrend);
        $this->assertSame(RefrendStatus::PAID, $paid->status);

        $reverted = $this->revert($paid);
        $this->assertSame(RefrendStatus::DRAFT, $reverted->status);
        $this->assertNull($reverted->cheque_number);
        $this->assertSame($coverage->id, $reverted->snapshot_telmex_coverage_id);
    }

    /** @test */
    public function cycle_closed_guard_runs_before_telmex_repayment_guard(): void
    {
        [$refrend, $coverage] = $this->makeCoveredRefrend();
        $refrend = $this->markPaid($refrend);
        $this->makeRepayment($coverage, false);
        $this->makeProfile($refrend, ['cycle_closed_period' => '2026-06-01']);

        $this->assertSame('CYCLE_CLOSED', $this->failure(fn () => $this->revert($refrend))->code());
    }
}
