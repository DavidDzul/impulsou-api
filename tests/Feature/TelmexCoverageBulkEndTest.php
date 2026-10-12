<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\TelmexCoverage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-lote, PR1 (phases 1-4). Covers
 * `POST api/admin/telmex-coverages/end-bulk`: request-shape 422s, 403
 * without permission, per-coverage independent processing (success, mixed,
 * unknown id, non-ACTIVA, DomainException, unexpected Throwable), double
 * submission, and becario_name presence on both ended and failed entries.
 */
class TelmexCoverageBulkEndTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;
    private User $noPermAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');

        $this->noPermAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
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

    private function makeCoverage(array $overrides = []): TelmexCoverage
    {
        $profile = $this->makeProfile();

        return TelmexCoverage::create(array_merge([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2026-01-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
            'activated_by_id'                 => $this->rootAdmin->id,
        ], $overrides));
    }

    private function endBulk(User $actor, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/admin/telmex-coverages/end-bulk', $payload);
    }

    // ── request shape (422) ──────────────────────────────────────────────

    /** @test */
    public function rejects_missing_coverage_ids(): void
    {
        $response = $this->endBulk($this->rootAdmin, ['telmex_start_period' => '2026-04-01']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['coverage_ids']);
    }

    /** @test */
    public function rejects_empty_coverage_ids(): void
    {
        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['coverage_ids']);
    }

    /** @test */
    public function rejects_more_than_200_ids(): void
    {
        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => range(1, 201),
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['coverage_ids']);
    }

    /** @test */
    public function rejects_duplicate_ids(): void
    {
        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [5, 5, 6],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['coverage_ids.0']);
    }

    /** @test */
    public function rejects_non_integer_ids(): void
    {
        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => ['abc'],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['coverage_ids.0']);
    }

    /** @test */
    public function rejects_missing_telmex_start_period(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->endBulk($this->rootAdmin, ['coverage_ids' => [$coverage->id]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['telmex_start_period']);
    }

    /** @test */
    public function rejects_invalid_telmex_start_period(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$coverage->id],
            'telmex_start_period' => 'not-a-date',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['telmex_start_period']);
    }

    /** @test */
    public function rejects_without_permission(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->endBulk($this->noPermAdmin, [
            'coverage_ids'        => [$coverage->id],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(403);
    }

    // ── per-coverage processing (always 200) ─────────────────────────────

    /** @test */
    public function ends_all_activa_coverages_matching_single_end_behavior(): void
    {
        $coverageA = $this->makeCoverage();
        $coverageB = $this->makeCoverage();

        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$coverageA->id, $coverageB->id],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.ended');
        $response->assertJsonCount(0, 'data.failed');

        // Same cast-format gotcha documented on
        // TelmexCoverageController::serializeCoverage() — the 'date' cast
        // still persists a full "Y-m-d H:i:s" string, not "Y-m-d".
        $this->assertDatabaseHas('scholarship_telmex_coverages', [
            'id'         => $coverageA->id,
            'end_period' => '2026-03-01 00:00:00',
            'status'     => 'EN_COBRO',
        ]);
        $this->assertDatabaseHas('scholarship_telmex_coverages', [
            'id'         => $coverageB->id,
            'end_period' => '2026-03-01 00:00:00',
            'status'     => 'EN_COBRO',
        ]);

        foreach ($response->json('data.ended') as $item) {
            $this->assertNotEmpty($item['becario_name']);
        }
    }

    /** @test */
    public function mixed_batch_reports_independent_results_and_persists_successes(): void
    {
        $okA               = $this->makeCoverage();
        $okB               = $this->makeCoverage();
        $enCobro           = $this->makeCoverage(['status' => 'EN_COBRO', 'end_period' => '2026-01-01']);
        $cancelada         = $this->makeCoverage(['status' => 'CANCELADA', 'cancel_reason' => 'Motivo de cancelación válido.']);
        $unknownId         = 999999;

        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$okA->id, $okB->id, $enCobro->id, $cancelada->id, $unknownId],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.ended');
        $response->assertJsonCount(3, 'data.failed');

        $endedIds = array_column($response->json('data.ended'), 'coverage_id');
        $this->assertEqualsCanonicalizing([$okA->id, $okB->id], $endedIds);

        $failed = collect($response->json('data.failed'))->keyBy('coverage_id');
        $this->assertSame('Ya no está activa', $failed[$enCobro->id]['reason']);
        $this->assertSame('Ya no está activa', $failed[$cancelada->id]['reason']);
        $this->assertNotEmpty($failed[$enCobro->id]['becario_name']);
        $this->assertNotEmpty($failed[$cancelada->id]['becario_name']);
        $this->assertNull($failed[$unknownId]['becario_name']);
        $this->assertSame('Cobertura no encontrada', $failed[$unknownId]['reason']);

        $this->assertDatabaseHas('scholarship_telmex_coverages', ['id' => $okA->id, 'status' => 'EN_COBRO']);
        $this->assertDatabaseHas('scholarship_telmex_coverages', ['id' => $okB->id, 'status' => 'EN_COBRO']);
        // Untouched coverages stay exactly as they were.
        $this->assertDatabaseHas('scholarship_telmex_coverages', ['id' => $enCobro->id, 'status' => 'EN_COBRO', 'end_period' => '2026-01-01 00:00:00']);
        $this->assertDatabaseHas('scholarship_telmex_coverages', ['id' => $cancelada->id, 'status' => 'CANCELADA']);
    }

    /** @test */
    public function one_coverages_domain_rejection_does_not_roll_back_others(): void
    {
        $healthy = $this->makeCoverage();

        $conflicting = $this->makeCoverage(['start_period' => '2026-05-01']);

        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$healthy->id, $conflicting->id],
            // end_period = 2026-03-01, which is before conflicting's start_period (2026-05-01)
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.ended');
        $response->assertJsonCount(1, 'data.failed');

        $failed = $response->json('data.failed.0');
        $this->assertSame($conflicting->id, $failed['coverage_id']);
        $this->assertStringContainsString('anterior al inicio', $failed['reason']);

        $this->assertDatabaseHas('scholarship_telmex_coverages', ['id' => $healthy->id, 'status' => 'EN_COBRO']);
        $this->assertDatabaseHas('scholarship_telmex_coverages', ['id' => $conflicting->id, 'status' => 'ACTIVA', 'end_period' => null]);
    }

    /** @test */
    public function double_submission_reports_all_as_no_longer_active_with_no_duplicate_writes(): void
    {
        $coverage = $this->makeCoverage();

        $first = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$coverage->id],
            'telmex_start_period' => '2026-04-01',
        ]);
        $first->assertStatus(200);
        $first->assertJsonCount(1, 'data.ended');

        $second = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$coverage->id],
            'telmex_start_period' => '2026-04-01',
        ]);
        $second->assertStatus(200);
        $second->assertJsonCount(0, 'data.ended');
        $second->assertJsonCount(1, 'data.failed');
        $this->assertSame('Ya no está activa', $second->json('data.failed.0.reason'));

        $this->assertDatabaseHas('scholarship_telmex_coverages', [
            'id'         => $coverage->id,
            'end_period' => '2026-03-01 00:00:00',
            'status'     => 'EN_COBRO',
        ]);
        $this->assertDatabaseCount('scholarship_telmex_coverages', 1);
    }

    /** @test */
    public function unexpected_throwable_is_logged_and_reported_without_blocking_other_ids(): void
    {
        $healthy = $this->makeCoverage();
        $boom    = $this->makeCoverage();

        $this->mock(\App\Actions\Scholarship\EndTelmexCoverageAction::class, function ($mock) use ($healthy, $boom) {
            $mock->shouldReceive('execute')
                ->withArgs(fn (TelmexCoverage $coverage) => $coverage->id === $healthy->id)
                ->andReturnUsing(function (TelmexCoverage $coverage) {
                    $coverage->update(['end_period' => '2026-03-01', 'status' => 'EN_COBRO']);

                    return $coverage->fresh();
                });

            $mock->shouldReceive('execute')
                ->withArgs(fn (TelmexCoverage $coverage) => $coverage->id === $boom->id)
                ->andThrow(new \RuntimeException('boom'));
        });

        \Illuminate\Support\Facades\Log::shouldReceive('error')->once();

        $response = $this->endBulk($this->rootAdmin, [
            'coverage_ids'        => [$healthy->id, $boom->id],
            'telmex_start_period' => '2026-04-01',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.ended');
        $response->assertJsonCount(1, 'data.failed');
        $this->assertSame('Error inesperado', $response->json('data.failed.0.reason'));
    }
}
