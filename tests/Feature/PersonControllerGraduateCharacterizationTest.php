<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-status-timing, design D3, task 2.1.
 *
 * Characterization test for the CURRENT `PersonController::graduate()`
 * behavior — written BEFORE extracting the user_type update + `graduated`
 * log into GraduateBecarioAction (task 2.3), so the extraction has a safety
 * net proving it doesn't change observable behavior. This test documents
 * behavior AS-IS, including the pre-existing null-FK fallback bug — it does
 * NOT fix anything (explicit out-of-scope boundary, spec + design).
 */
class PersonControllerGraduateCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function graduate(int $userId, string $comment = 'Egresó por terminar sus estudios.')
    {
        return $this->actingAs($this->admin)->postJson(
            "/api/admin/persons/{$userId}/graduate",
            ['comment' => $comment]
        );
    }

    /** @test */
    public function comment_is_required(): void
    {
        $person = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $response = $this->actingAs($this->admin)->postJson(
            "/api/admin/persons/{$person->id}/graduate",
            []
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function rejects_a_user_who_is_not_an_active_becario(): void
    {
        $person = User::factory()->create(['user_type' => 'BEC_INACTIVE', 'active' => true]);

        $response = $this->graduate($person->id);

        $response->assertStatus(422);
        $response->assertJson(['res' => false]);
        $this->assertSame('BEC_INACTIVE', $person->fresh()->user_type);
    }

    /** @test */
    public function graduating_with_an_active_refrend_in_the_current_period_cancels_it_and_writes_both_logs(): void
    {
        $person = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $activeRefrend = ScholarshipRefrend::create([
            'user_id'       => $person->id,
            'period_year'   => now()->year,
            'period_month'  => now()->month,
            'refrend_type'  => RefrendType::NORMAL->value,
            'status'        => 'DRAFT',
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Test Becario',
        ]);

        $response = $this->graduate($person->id, 'Egresó por terminar sus estudios.');

        $response->assertOk();
        $response->assertJson(['res' => true]);

        $this->assertSame('BEC_INACTIVE', $person->fresh()->user_type);
        $this->assertSame('CANCELLED', $activeRefrend->fresh()->status->value);

        $this->assertSame(2, ScholarshipRefrendLog::where('scholarship_refrend_id', $activeRefrend->id)->count());

        $cancelLog = ScholarshipRefrendLog::where('scholarship_refrend_id', $activeRefrend->id)
            ->where('action', 'cancelled_by_graduation')->first();
        $this->assertNotNull($cancelLog);
        $this->assertSame($this->admin->id, $cancelLog->performed_by_id);
        $this->assertSame('Egresó por terminar sus estudios.', $cancelLog->notes);

        $graduatedLog = ScholarshipRefrendLog::where('scholarship_refrend_id', $activeRefrend->id)
            ->where('action', 'graduated')->first();
        $this->assertNotNull($graduatedLog);
        $this->assertSame($this->admin->id, $graduatedLog->performed_by_id);
        $this->assertSame('Egresó por terminar sus estudios.', $graduatedLog->notes);
    }

    /** @test */
    public function graduating_without_an_active_refrend_but_with_a_past_refrend_writes_only_the_graduated_log_against_the_latest_refrend(): void
    {
        $person = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        // Past refrend, NOT in an active status / NOT the current period —
        // so it is not picked up as $activeRefrend, but IS the fallback
        // ::latest() target for the 'graduated' log.
        $pastRefrend = ScholarshipRefrend::create([
            'user_id'       => $person->id,
            'period_year'   => now()->subMonths(2)->year,
            'period_month'  => now()->subMonths(2)->month,
            'refrend_type'  => RefrendType::NORMAL->value,
            'status'        => 'PAID',
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Test Becario',
        ]);

        $response = $this->graduate($person->id, 'Egresó sin refrendo activo.');

        $response->assertOk();
        $this->assertSame('BEC_INACTIVE', $person->fresh()->user_type);

        $this->assertSame(1, ScholarshipRefrendLog::where('scholarship_refrend_id', $pastRefrend->id)->count());
        $graduatedLog = ScholarshipRefrendLog::where('scholarship_refrend_id', $pastRefrend->id)->first();
        $this->assertSame('graduated', $graduatedLog->action);
        $this->assertSame($this->admin->id, $graduatedLog->performed_by_id);
        $this->assertNull(
            ScholarshipRefrendLog::where('action', 'cancelled_by_graduation')->first(),
            'No active refrend existed, so the cancellation log must never be written.'
        );
    }

    /**
     * Pre-existing bug, characterized AS-IS, NOT fixed (explicit out-of-scope
     * boundary — spec "Out of Scope", design D3): when a becario has ZERO
     * refrend rows ever, the `?? ScholarshipRefrend::...->latest()->value('id')`
     * fallback resolves to null, and scholarship_refrend_logs.scholarship_refrend_id
     * is NOT NULL + FK-constrained (untouched by this change's migration),
     * so the insert raises a QueryException instead of a friendly response.
     */
    /** @test */
    public function graduating_a_becario_with_zero_refrends_ever_throws_due_to_the_preexisting_null_fk_bug(): void
    {
        $person = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        $this->graduate($person->id, 'Egresó sin historial de refrendos.');
    }
}
