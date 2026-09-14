<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers PR3 (sdd/becario-payment-file-generation, Phase 3): the legacy
 * `bulk/pay` route is hardened with `permission:ADM_PROCESS_PAYMENTS` and its
 * controller body is extracted into BulkPayAction, but its HTTP contract
 * (response shape + 'BULK_PAY' log action) MUST NOT change — this is a
 * literal before/after behavioral equivalence test (design D2 backwards
 * compat requirement), not just "it still works".
 */
class BulkPayEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makePayableRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
            'curp'           => 'CURP010101HDFXXX01',
            'rfc'            => 'RFC010101ABC',
        ]);

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
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    // ── Backwards-compat: response shape + log action unchanged ───────────────

    /** @test */
    public function legacy_route_still_returns_paid_count_shape_and_logs_bulk_pay(): void
    {
        $refrend = $this->makePayableRefrend();

        $response = $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(200);
        $response->assertExactJson(['res' => true, 'data' => ['paid' => 1]]);

        $this->assertDatabaseHas('scholarship_refrend_logs', [
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'BULK_PAY',
        ]);
    }

    // ── Permission hardening (PR3: was zero-callers, zero-middleware) ─────────

    /** @test */
    public function returns_403_without_adm_process_payments_permission(): void
    {
        $refrend  = $this->makePayableRefrend();
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($noPermUser)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(403);

        $refrend->refresh();
        $this->assertSame('LISTO_PARA_PAGO', $refrend->workflow_status, 'Nothing should be mutated when the permission check fails.');
    }

    /** @test */
    public function root_administration_role_can_call_bulk_pay(): void
    {
        $refrend = $this->makePayableRefrend();

        $response = $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(200);
    }
}
