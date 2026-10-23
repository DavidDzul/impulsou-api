<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresar-boton-corte-ciclo, REQ-RG-1 (S22/S23): the single-user generate
 * endpoint and the advance-payment endpoint answer 422 with the reticula
 * message when the profile has no reticula dates, and persist nothing.
 */
class ReticulaMissingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_MISSING = 'El becario no tiene fechas de retícula cargadas. Cárgalas en la sección Retícula de su perfil.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfileWithoutDates(): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::factory()->reticulaMissing()->create([
            'user_id'        => $user->id,
            'monthly_amount' => 2000,
            'monto_apoyo'    => 0,
        ]);
    }

    /** @test */
    public function generate_for_user_endpoint_returns_422_with_the_reticula_message(): void
    {
        $profile = $this->makeProfileWithoutDates();

        $response = $this->actingAs($this->admin)->postJson(
            "/api/admin/scholarship-refrends/generate/{$profile->user_id}",
            ['year' => 2026, 'month' => 9]
        );

        $response->assertStatus(422);
        $response->assertJson(['res' => false, 'msg' => self::MSG_MISSING]);
        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /** @test */
    public function advance_payment_endpoint_returns_422_and_persists_nothing(): void
    {
        $profile = $this->makeProfileWithoutDates();
        $origin  = ScholarshipRefrend::create([
            'user_id'                      => $profile->user_id,
            'period_year'                  => 2026,
            'period_month'                 => 9,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => 'IU',
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            "/api/admin/scholarship-refrends/{$origin->id}/advance-payment",
            ['months' => [['year' => 2026, 'month' => 12]], 'cause' => 'Viaje académico']
        );

        $response->assertStatus(422);
        $response->assertJson(['res' => false, 'msg' => self::MSG_MISSING]);
        $this->assertSame(1, ScholarshipRefrend::count());
        $this->assertSame(0, ScholarshipAdvancePayment::count());
    }
}
