<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque PR3 (spec R3.1/R3.4, design Interfaces): mark a cheque
 * refrend as paid. Route follows the design path (`cheque-payment`).
 */
class ScholarshipChequePaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

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

    private function markPaid(ScholarshipRefrend|int $refrend, array $body = ['cheque_number' => 'A123'], ?User $as = null)
    {
        $id = $refrend instanceof ScholarshipRefrend ? $refrend->id : $refrend;

        return $this->actingAs($as ?? $this->admin)
            ->postJson("/api/admin/scholarship-payments/{$id}/cheque-payment", $body);
    }

    private function index()
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH, 'batch_type' => 'NORMAL',
        ]));
    }

    /** @test */
    public function marks_the_cheque_as_paid_and_returns_the_refrend_payload(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->markPaid($refrend, [
            'cheque_number'       => 'A123',
            'cheque_delivered_at' => '2026-05-10',
            'cheque_notes'        => 'Entregado',
        ]);

        $response->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('data.refrend_id', $refrend->id)
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.workflow_status', 'CLOSED')
            ->assertJsonPath('data.paid_payment_method', 'CHEQUE')
            ->assertJsonPath('data.cheque_number', 'A123')
            ->assertJsonPath('data.cheque_delivered_at', '2026-05-10')
            ->assertJsonPath('data.cheque_notes', 'Entregado');
        $this->assertNotNull($response->json('data.locked_at'));
        $this->assertSame(RefrendStatus::PAID, $refrend->fresh()->status);
        $this->assertDatabaseHas('scholarship_refrend_logs', [
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'CHEQUE_PAID',
            'performed_by_id'        => $this->admin->id,
        ]);
    }

    /** @test */
    public function delivered_at_defaults_to_today(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend)
            ->assertOk()
            ->assertJsonPath('data.cheque_delivered_at', now()->toDateString());
    }

    /** @test */
    public function future_delivered_at_is_rejected(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend, ['cheque_number' => 'A1', 'cheque_delivered_at' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_delivered_at');
        $this->assertSame(RefrendStatus::AUTHORIZED, $refrend->fresh()->status);
    }

    /** @test */
    public function missing_cheque_number_is_rejected(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend, [])->assertStatus(422)->assertJsonValidationErrors('cheque_number');
    }

    /** @test */
    public function oversized_fields_are_rejected(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend, ['cheque_number' => str_repeat('9', 51), 'cheque_notes' => str_repeat('n', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque_number', 'cheque_notes']);
    }

    /** @test */
    public function returns_403_without_adm_process_payments_permission(): void
    {
        $refrend = $this->makeRefrend();
        $noPerm  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $this->markPaid($refrend, ['cheque_number' => 'A1'], $noPerm)->assertStatus(403);
        $this->assertSame(RefrendStatus::AUTHORIZED, $refrend->fresh()->status);
    }

    /** @test */
    public function non_numeric_refrend_segment_returns_404_not_500(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-payments/abc/cheque-payment', ['cheque_number' => 'A123'])
            ->assertStatus(404);
        $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-payments/abc/cheque-payment/revert', ['reason' => 'Cheque extraviado, se reexpide'])
            ->assertStatus(404);
    }

    /** @test */
    public function returns_404_for_an_unknown_refrend(): void
    {
        $this->markPaid(999999)->assertStatus(404);
    }

    /** @test */
    public function double_submit_returns_409_already_paid_and_keeps_the_first_cheque(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend, ['cheque_number' => 'FIRST'])->assertOk();

        $this->markPaid($refrend, ['cheque_number' => 'SECOND'])
            ->assertStatus(409)
            ->assertJsonPath('res', false)
            ->assertJsonPath('code', 'ALREADY_PAID')
            ->assertJsonStructure(['res', 'msg', 'code', 'data']);

        $this->assertSame('FIRST', $refrend->fresh()->cheque_number);
        $this->assertSame(1, ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)->where('action', 'CHEQUE_PAID')->count());
    }

    /** @test */
    public function transfer_method_returns_422_not_cheque_method(): void
    {
        $refrend = $this->makeRefrend(cheque: false);

        $this->markPaid($refrend)
            ->assertStatus(422)
            ->assertJsonPath('res', false)
            ->assertJsonPath('code', 'NOT_CHEQUE_METHOD');
    }

    /** @test */
    public function unready_refrend_returns_422_not_payable_with_reasons(): void
    {
        $refrend = $this->makeRefrend(true, ['workflow_status' => 'CON_INCIDENCIA']);

        $response = $this->markPaid($refrend)
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOT_PAYABLE');

        $this->assertSame('NOT_APPROVED', $response->json('data.reasons.0.code'));
    }

    /** @test */
    public function non_candidate_resolution_returns_422_not_payable(): void
    {
        $refrend = $this->makeRefrend(true, ['resolution_type' => 'EGRESADO']);

        $this->markPaid($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_PAYABLE');
    }

    /** @test */
    public function pure_telmex_row_cannot_be_marked(): void
    {
        $refrend = $this->makeRefrend(true, ['snapshot_scholarship_type' => ScholarshipType::TELMEX->value]);

        $response = $this->markPaid($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_PAYABLE');

        $this->assertContains('TELMEX_NOT_PAYABLE', array_column($response->json('data.reasons'), 'code'));
    }

    /** @test */
    public function paid_cheque_shows_as_paid_in_cheque_rows_and_the_transfer_batch_still_processes(): void
    {
        $cheque = $this->makeRefrend(true, [], 'Cheque');
        $this->makeRefrend(false, [], 'Transfer 1');
        $this->makeRefrend(false, [], 'Transfer 2');

        $this->markPaid($cheque)->assertOk();

        $index = $this->index()->assertOk();
        $this->assertSame(1, $index->json('data.cheque_summary.paid'));
        $this->assertSame('PAID', $index->json('data.cheque_rows.0.status'));
        $this->assertSame('CHEQUE', $index->json('data.cheque_rows.0.payment_method'));
        $this->assertSame('A123', $index->json('data.cheque_rows.0.cheque_number'));
        $this->assertSame(0, $index->json('data.summary.blocking'));

        $this->actingAs($this->admin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => 'NORMAL', 'expected_count' => 2, 'expected_total' => '2000.00',
        ])->assertOk();

        $this->assertSame(PaymentMethod::CHEQUE, $cheque->fresh()->paid_payment_method);
        $this->assertNull($cheque->fresh()->payment_batch_id);
    }
}
