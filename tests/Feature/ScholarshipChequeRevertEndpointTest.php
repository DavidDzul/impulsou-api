<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque PR4 (spec R4, amendment A1): revert endpoint.
 */
class ScholarshipChequeRevertEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;
    private const REASON = 'Cheque extraviado, se reexpide';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makeRefrend(bool $cheque = true, array $overrides = [], string $name = 'Becario'): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);

        $cheque
            ? ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $user->id])
            : ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '0123456789', 'rfc' => 'PEPJ800101ABC']);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'AUTHORIZED',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => $name,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function markPaid(ScholarshipRefrend $refrend): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-payments/{$refrend->id}/cheque-payment", ['cheque_number' => 'A123'])
            ->assertOk();
    }

    private function revert(ScholarshipRefrend|int $refrend, array $body = ['reason' => self::REASON], ?User $as = null)
    {
        $id = $refrend instanceof ScholarshipRefrend ? $refrend->id : $refrend;

        return $this->actingAs($as ?? $this->admin)
            ->postJson("/api/admin/scholarship-payments/{$id}/cheque-payment/revert", $body);
    }

    private function index()
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH, 'batch_type' => 'NORMAL',
        ]));
    }

    /** @test */
    public function reverts_the_cheque_and_returns_the_refrend_payload(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend);

        $response = $this->revert($refrend);

        $response->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('data.refrend_id', $refrend->id)
            ->assertJsonPath('data.status', 'AUTHORIZED')
            ->assertJsonPath('data.workflow_status', 'LISTO_PARA_PAGO')
            ->assertJsonPath('data.paid_payment_method', null)
            ->assertJsonPath('data.cheque_number', null)
            ->assertJsonPath('data.cheque_delivered_at', null)
            ->assertJsonPath('data.cheque_notes', null)
            ->assertJsonPath('data.locked_at', null);
        $this->assertDatabaseHas('scholarship_refrend_logs', [
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'CHEQUE_PAYMENT_REVERTED',
            'notes'                  => self::REASON,
            'performed_by_id'        => $this->admin->id,
        ]);
    }

    /** @test */
    public function reason_is_required(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend);

        $this->revert($refrend, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function reason_must_have_a_minimum_and_maximum_length(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend);

        $this->revert($refrend, ['reason' => 'corto'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->revert($refrend, ['reason' => str_repeat('r', 501)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function returns_403_without_adm_process_payments_permission(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend);
        $noPerm = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $this->revert($refrend, ['reason' => self::REASON], $noPerm)->assertStatus(403);
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function returns_404_for_an_unknown_refrend(): void
    {
        $this->revert(999999)->assertStatus(404);
    }

    /** @test */
    public function unpaid_refrend_returns_422_not_cheque_paid(): void
    {
        $refrend = $this->makeRefrend();

        $this->revert($refrend)
            ->assertStatus(422)
            ->assertJsonPath('res', false)
            ->assertJsonPath('code', 'NOT_CHEQUE_PAID')
            ->assertJsonStructure(['res', 'msg', 'code', 'data']);
    }

    /** @test */
    public function transfer_paid_refrend_returns_422_not_cheque_paid(): void
    {
        $refrend = $this->makeRefrend(false, [
            'status'              => 'PAID',
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'paid_payment_method' => 'TRANSFER',
        ]);

        $this->revert($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_CHEQUE_PAID');
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
    }

    /** @test */
    public function batch_paid_refrend_returns_422_not_cheque_paid(): void
    {
        $batch = ScholarshipPaymentBatch::create([
            'generation_id'   => 1,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $this->admin->id,
            'processed_at'    => now(),
        ]);
        $refrend = $this->makeRefrend(false, [
            'status'           => 'PAID',
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
            'payment_batch_id' => $batch->id,
        ]);

        $this->revert($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_CHEQUE_PAID');
        $this->assertSame($batch->id, $refrend->fresh()->payment_batch_id);
    }

    /** @test */
    public function closed_cycle_returns_409_and_leaves_the_cheque_untouched(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend);
        ScholarshipProfile::factory()->create(['user_id' => $refrend->user_id, 'cycle_closed_period' => '2026-06-01']);

        $this->revert($refrend)
            ->assertStatus(409)
            ->assertJsonPath('res', false)
            ->assertJsonPath('code', 'CYCLE_CLOSED')
            ->assertJsonPath('msg', 'El ciclo del becario está cerrado.');

        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
        $this->assertSame('A123', $refrend->fresh()->cheque_number);
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'CHEQUE_PAYMENT_REVERTED')->count());
    }

    /** @test */
    public function reverted_row_reappears_unpaid_and_payable_in_cheque_rows(): void
    {
        $cheque = $this->makeRefrend(true, [], 'Cheque');
        $this->makeRefrend(false, [], 'Transfer');
        $this->markPaid($cheque);
        $this->assertSame(1, $this->index()->json('data.cheque_summary.paid'));

        $this->revert($cheque)->assertOk();

        $index = $this->index()->assertOk();
        $this->assertSame(0, $index->json('data.cheque_summary.paid'));
        $this->assertSame(1, $index->json('data.cheque_summary.ready'));
        $row = $index->json('data.cheque_rows.0');
        $this->assertSame('AUTHORIZED', $row['status']);
        $this->assertTrue($row['is_payable']);
        $this->assertNull($row['cheque_number']);
    }

    /** @test */
    public function transfer_batch_is_unaffected_by_a_cheque_revert(): void
    {
        $cheque = $this->makeRefrend(true, [], 'Cheque');
        $this->makeRefrend(false, [], 'Transfer 1');
        $this->makeRefrend(false, [], 'Transfer 2');
        $this->markPaid($cheque);
        $this->revert($cheque)->assertOk();

        $index = $this->index()->assertOk();
        $this->assertSame(0, $index->json('data.summary.blocking'));

        $this->actingAs($this->admin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => 'NORMAL', 'expected_count' => 2, 'expected_total' => '2000.00',
        ])->assertOk();

        $fresh = $cheque->fresh();
        $this->assertSame(RefrendStatus::AUTHORIZED, $fresh->status);
        $this->assertNull($fresh->payment_batch_id);
    }
}
