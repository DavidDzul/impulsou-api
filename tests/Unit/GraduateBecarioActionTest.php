<?php

namespace Tests\Unit;

use App\Actions\Scholarship\GraduateBecarioAction;
use App\Enums\RefrendType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-status-timing, design D3, task 2.2.
 *
 * Writes the ScholarshipRefrendLog row DIRECTLY (mirrors
 * PersonController.php's pre-extraction inline pattern) — deliberately
 * does NOT add an optional performed_by_id fallback to
 * ScholarshipLoggingService::log() (design R4: a `?? auth()->id()`
 * fallback cannot express explicit null).
 */
class GraduateBecarioActionTest extends TestCase
{
    use RefreshDatabase;

    private GraduateBecarioAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(GraduateBecarioAction::class);
    }

    private function makeRefrend(int $userId, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'       => $userId,
            'period_year'   => 2026,
            'period_month'  => 9,
            'refrend_type'  => RefrendType::NORMAL->value,
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Test Becario',
        ], $overrides));
    }

    /** @test */
    public function flips_user_type_to_bec_inactive(): void
    {
        $person  = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $refrend = $this->makeRefrend($person->id);

        $this->action->execute($person, $refrend, 'Egreso automático.', null);

        $this->assertSame('BEC_INACTIVE', $person->fresh()->user_type);
    }

    /** @test */
    public function writes_a_graduated_log_with_null_performed_by_id_when_system_triggered(): void
    {
        $person  = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $refrend = $this->makeRefrend($person->id);

        $this->action->execute($person, $refrend, 'Egreso automático por vencimiento de retícula.', null);

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'graduated')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->performed_by_id);
        $this->assertSame('Egreso automático por vencimiento de retícula.', $log->notes);
    }

    /** @test */
    public function writes_a_graduated_log_with_the_provided_performed_by_id_when_manual(): void
    {
        $staff   = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $person  = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $refrend = $this->makeRefrend($person->id);

        $this->action->execute($person, $refrend, 'Egresó por terminar sus estudios.', $staff->id);

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'graduated')->first();

        $this->assertSame($staff->id, $log->performed_by_id);
    }

    /** @test */
    public function falls_back_to_the_users_latest_refrend_when_no_context_refrend_is_given(): void
    {
        $person        = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $olderRefrend  = $this->makeRefrend($person->id, ['period_month' => 1]);
        $olderRefrend->forceFill(['created_at' => now()->subDay()])->save();
        $latestRefrend = $this->makeRefrend($person->id, ['period_month' => 8]);

        $this->action->execute($person, null, 'Egreso sin refrendo activo.', null);

        $this->assertSame(
            1,
            ScholarshipRefrendLog::where('scholarship_refrend_id', $latestRefrend->id)->count()
        );
        $this->assertSame(
            0,
            ScholarshipRefrendLog::where('scholarship_refrend_id', $olderRefrend->id)->count()
        );
    }

    /** @test */
    public function is_a_silent_no_op_when_the_person_is_not_an_active_becario(): void
    {
        $person  = User::factory()->create(['user_type' => 'BEC_INACTIVE', 'active' => true]);
        $refrend = $this->makeRefrend($person->id);

        $result = $this->action->execute($person, $refrend, 'Intento repetido.', null);

        $this->assertSame('BEC_INACTIVE', $result->user_type);
        $this->assertSame(
            0,
            ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)->count(),
            'Idempotency: no log should be written for a person who is not currently BEC_ACTIVE.'
        );
    }

    /** @test */
    public function returns_the_fresh_user_model(): void
    {
        $person  = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $refrend = $this->makeRefrend($person->id);

        $result = $this->action->execute($person, $refrend, 'Egreso automático.', null);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('BEC_INACTIVE', $result->user_type);
    }
}
