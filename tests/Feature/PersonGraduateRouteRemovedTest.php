<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * sdd/egresar-boton-corte-ciclo, REQ-PM-1 (S1, S2). The manual "graduate"
 * endpoint is gone; the only manual egreso is the Egresado payment situation
 * (CloseScholarshipCycleAction). GraduateController::store is unrelated and
 * must keep creating BEC_INACTIVE users.
 */
class PersonGraduateRouteRemovedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    /** @test */
    public function post_persons_graduate_is_not_routable_and_changes_nothing(): void
    {
        $person = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create(['user_id' => $person->id]);

        $response = $this->actingAs($this->admin)->postJson(
            "/api/admin/persons/{$person->id}/graduate",
            ['comment' => 'Egresó por terminar sus estudios.']
        );

        $response->assertStatus(404);
        $this->assertSame('BEC_ACTIVE', $person->fresh()->user_type);
        $this->assertSame(0, ScholarshipRefrend::where('user_id', $person->id)->count());
    }

    /** @test */
    public function graduate_controller_store_still_creates_a_bec_inactive_user(): void
    {
        Mail::fake();
        $generation = Generation::create([
            'generation_name'   => 'Gen Test',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/graduates', [
            'enrollment'    => '123456789',
            'first_name'    => 'Ana',
            'last_name'     => 'Perez',
            'email'         => 'ana.perez@example.com',
            'phone'         => '9990000000',
            'campus'        => 'MERIDA',
            'generation_id' => $generation->id,
            'password'      => 'password123',
        ]);

        $response->assertStatus(201);
        $this->assertSame('BEC_INACTIVE', User::where('enrollment', '123456789')->value('user_type'));
    }
}
