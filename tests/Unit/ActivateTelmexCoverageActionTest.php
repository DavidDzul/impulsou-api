<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ActivateTelmexCoverageAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivateTelmexCoverageActionTest extends TestCase
{
    use RefreshDatabase;

    private ActivateTelmexCoverageAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(ActivateTelmexCoverageAction::class);
    }

    private function makeProfile(string $type = 'TELMEX', array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return ScholarshipProfile::create(array_merge([
            'user_id'            => $user->id,
            'scholarship_type'   => $type,
            'monthly_amount'     => 1800.00,
            'monto_apoyo'        => 150.00,
            'payment_start_date' => now()->toDateString(),
        ], $overrides));
    }

    /** @test */
    public function activates_a_new_coverage_for_a_telmex_becario(): void
    {
        $profile = $this->makeProfile();
        $staff   = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $coverage = $this->action->execute([
            'user_id'      => $profile->user_id,
            'start_period' => '2026-02-15',
            'notes'        => 'Activación inicial',
        ], $staff->id);

        $this->assertSame('ACTIVA', $coverage->status);
        $this->assertSame('2026-02-01', $coverage->start_period->toDateString());
        $this->assertNull($coverage->end_period);
        $this->assertSame($staff->id, $coverage->activated_by_id);
    }

    /** @test */
    public function rejects_an_iu_becario(): void
    {
        $profile = $this->makeProfile('IU', ['monto_apoyo' => null]);

        $this->expectException(\DomainException::class);
        $this->action->execute(['user_id' => $profile->user_id, 'start_period' => '2026-02-01'], 1);
    }

    /** @test */
    public function rejects_when_monthly_plus_apoyo_is_zero(): void
    {
        $profile = $this->makeProfile('TELMEX', ['monthly_amount' => 0, 'monto_apoyo' => 0]);

        $this->expectException(\DomainException::class);
        $this->action->execute(['user_id' => $profile->user_id, 'start_period' => '2026-02-01'], 1);
    }

    /** @test */
    public function rejects_a_second_coverage_while_one_is_active(): void
    {
        $profile = $this->makeProfile();
        TelmexCoverage::create([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2025-01-01',
            'status'                          => 'ACTIVA',
        ]);

        $this->expectException(\DomainException::class);
        $this->action->execute(['user_id' => $profile->user_id, 'start_period' => '2026-02-01'], 1);
    }

    /** @test */
    public function rejects_activation_when_a_cancelada_coverage_already_exists(): void
    {
        $profile = $this->makeProfile();
        TelmexCoverage::create([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2025-01-01',
            'status'                          => 'CANCELADA',
            'cancel_reason'                   => 'Activado por error inicialmente.',
        ]);

        try {
            $this->action->execute(['user_id' => $profile->user_id, 'start_period' => '2026-02-01'], 1);
            $this->fail('Expected DomainException.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reactivar', $e->getMessage());
        }
    }

    /** @test */
    public function syncs_an_already_generated_refrend_within_the_new_window(): void
    {
        $profile = $this->makeProfile();
        ScholarshipRefrend::create([
            'user_id'                        => $profile->user_id,
            'period_year'                    => 2026,
            'period_month'                   => 2,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::DRAFT->value,
            'workflow_status'                => 'DRAFT',
            'base_amount'                    => 0,
            'snapshot_gross_amount'           => 0,
            'snapshot_telmex_covered_amount' => 1950.0,
            'final_amount'                   => 0,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => 'TELMEX',
        ]);

        $coverage = $this->action->execute(['user_id' => $profile->user_id, 'start_period' => '2026-02-01'], 1);

        $refrend = ScholarshipRefrend::where('user_id', $profile->user_id)->first();
        $this->assertSame($coverage->id, $refrend->snapshot_telmex_coverage_id);
        $this->assertSame('1950.00', $refrend->final_amount);
    }
}
