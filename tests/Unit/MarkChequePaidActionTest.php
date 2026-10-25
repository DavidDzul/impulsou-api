<?php

namespace Tests\Unit;

use App\Actions\Scholarship\MarkChequePaidAction;
use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque PR3 (spec R3.2/R3.3, design Actions).
 */
class MarkChequePaidActionTest extends TestCase
{
    use RefreshDatabase;

    private MarkChequePaidAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = $this->app->make(MarkChequePaidAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    private function makeRefrend(array $overrides = [], bool $cheque = true, bool $withPaymentData = true): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => 'MERIDA',
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);

        if ($withPaymentData) {
            $cheque
                ? ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $user->id])
                : ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '0123456789', 'rfc' => 'PEPJ800101ABC']);
        }

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::AUTHORIZED->value,
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

    private function mark(ScholarshipRefrend $r, string $number = 'A123', ?string $date = '2026-05-10', ?string $notes = null): ScholarshipRefrend
    {
        return $this->action->execute($r->id, $this->admin->id, $number, $date, $notes);
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

    /** @test */
    public function marks_an_authorized_ready_cheque_refrend_as_paid(): void
    {
        $refrend = $this->makeRefrend();

        $this->mark($refrend, 'A123', '2026-05-10', 'Entregado en ventanilla');

        $refrend->refresh();
        $this->assertSame(RefrendStatus::PAID, $refrend->status);
        $this->assertSame('CLOSED', $refrend->workflow_status);
        $this->assertNotNull($refrend->locked_at);
        $this->assertSame($this->admin->id, $refrend->locked_by_id);
        $this->assertSame(PaymentMethod::CHEQUE, $refrend->paid_payment_method);
        $this->assertNull($refrend->payment_batch_id);
        $this->assertSame('A123', $refrend->cheque_number);
        $this->assertSame('2026-05-10', $refrend->cheque_delivered_at->toDateString());
        $this->assertSame('Entregado en ventanilla', $refrend->cheque_notes);
    }

    /** @test */
    public function defaults_delivered_at_to_today(): void
    {
        $refrend = $this->makeRefrend();

        $this->mark($refrend, 'A1', null);

        $this->assertSame(now()->toDateString(), $refrend->fresh()->cheque_delivered_at->toDateString());
    }

    /** @test */
    public function accepts_a_withheld_refrend_listo_para_pago_with_balance(): void
    {
        $refrend = $this->makeRefrend(['status' => RefrendStatus::WITHHELD->value, 'final_amount' => 400.00]);

        $this->mark($refrend);

        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function logs_cheque_paid_with_restorable_old_snapshot(): void
    {
        $refrend = $this->makeRefrend(['status' => RefrendStatus::WITHHELD->value]);

        $this->mark($refrend, 'Z9');

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)->where('action', 'CHEQUE_PAID')->sole();
        $this->assertSame('WITHHELD', $log->old_values['status']);
        $this->assertSame('LISTO_PARA_PAGO', $log->old_values['workflow_status']);
        $this->assertSame('PAID', $log->new_values['status']);
        $this->assertSame('CLOSED', $log->new_values['workflow_status']);
        $this->assertSame('Z9', $log->new_values['cheque_number']);
        $this->assertSame($this->admin->id, $log->performed_by_id);
    }

    /** @test */
    public function rejects_an_already_paid_refrend_with_409_and_no_new_log(): void
    {
        $refrend = $this->makeRefrend();
        $this->mark($refrend);

        $e = $this->failure(fn () => $this->mark($refrend, 'B2'));

        $this->assertSame('ALREADY_PAID', $e->code());
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame(1, ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)->count());
        $this->assertSame('A123', $refrend->fresh()->cheque_number);
    }

    /** @test */
    public function treats_a_transfer_paid_refrend_as_already_paid(): void
    {
        $refrend = $this->makeRefrend(['status' => RefrendStatus::PAID->value, 'workflow_status' => 'CLOSED', 'locked_at' => now()]);

        $this->assertSame('ALREADY_PAID', $this->failure(fn () => $this->mark($refrend))->code());
    }

    /** @test */
    public function rejects_a_transfer_method_becario(): void
    {
        $refrend = $this->makeRefrend([], cheque: false);

        $e = $this->failure(fn () => $this->mark($refrend));

        $this->assertSame('NOT_CHEQUE_METHOD', $e->code());
        $this->assertSame(RefrendStatus::AUTHORIZED, $refrend->fresh()->status);
    }

    /** @test */
    public function rejects_a_becario_without_payment_data_as_not_cheque_method(): void
    {
        $refrend = $this->makeRefrend([], withPaymentData: false);

        $this->assertSame('NOT_CHEQUE_METHOD', $this->failure(fn () => $this->mark($refrend))->code());
    }

    /** @test */
    public function rejects_a_pending_approval_refrend_with_reasons(): void
    {
        $refrend = $this->makeRefrend(['workflow_status' => 'CON_INCIDENCIA']);

        $e = $this->failure(fn () => $this->mark($refrend));

        $this->assertSame('NOT_PAYABLE', $e->code());
        $this->assertSame(422, $e->httpStatus());
        $this->assertSame('NOT_APPROVED', $e->payload()['reasons'][0]['code']);
    }

    /** @test */
    public function rejects_withheld_without_balance(): void
    {
        $refrend = $this->makeRefrend(['status' => RefrendStatus::WITHHELD->value, 'final_amount' => 0]);

        $e = $this->failure(fn () => $this->mark($refrend));

        $this->assertSame('NOT_PAYABLE', $e->code());
        $this->assertContains('NOTHING_TO_PAY', array_column($e->payload()['reasons'], 'code'));
    }

    /**
     * @test
     * @dataProvider nonCandidateResolutions
     */
    public function rejects_non_candidate_resolutions(string $resolution): void
    {
        $refrend = $this->makeRefrend(['resolution_type' => $resolution]);

        $e = $this->failure(fn () => $this->mark($refrend));

        $this->assertSame('NOT_PAYABLE', $e->code());
        $this->assertSame(RefrendStatus::AUTHORIZED, $refrend->fresh()->status);
    }

    public static function nonCandidateResolutions(): array
    {
        return array_map(fn ($r) => [$r], ScholarshipRefrend::NON_CANDIDATE_RESOLUTIONS);
    }

    /** @test */
    public function rejects_a_pure_telmex_row_without_increase_or_coverage(): void
    {
        $refrend = $this->makeRefrend(['snapshot_scholarship_type' => ScholarshipType::TELMEX->value]);

        $e = $this->failure(fn () => $this->mark($refrend));

        $this->assertSame('NOT_PAYABLE', $e->code());
        $this->assertContains('TELMEX_NOT_PAYABLE', array_column($e->payload()['reasons'], 'code'));
    }

    /** @test */
    public function throws_not_found_for_an_unknown_refrend(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->action->execute(999999, $this->admin->id, 'A1', null, null);
    }
}
