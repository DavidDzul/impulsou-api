<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateWithGenerationIdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Creates a Generation record.
     */
    private function makeGeneration(string $name = 'Gen Test'): Generation
    {
        return Generation::create([
            'generation_name'   => $name,
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);
    }

    /**
     * Creates an active becario user with a ScholarshipProfile.
     * Does NOT set reticula dates so no DomainException is thrown.
     */
    private function makeBecario(array $userOverrides = [], array $profileOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ], $userOverrides));

        ScholarshipProfile::create(array_merge([
            'user_id'                    => $user->id,
            'scholarship_type'           => ScholarshipType::IU->value,
            'monthly_amount'             => 2500.00,
            'active_discount_percentage' => null,
            'reticula_start_date'        => null,
            'reticula_end_date'          => null,
            // payment_start_date column still exists on SQLite (dropColumn skipped)
            'payment_start_date'         => now()->toDateString(),
        ], $profileOverrides));

        return $user;
    }

    private function postGenerate(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/generate', $params);
    }

    // ── TASK-11-3 Tests ───────────────────────────────────────────────────────

    /** @test */
    public function it_generates_for_all_active_users_when_no_generation_id_given(): void
    {
        $gen = $this->makeGeneration('Gen A');

        $user1 = $this->makeBecario(['generation_id' => $gen->id]);
        $user2 = $this->makeBecario(['generation_id' => $gen->id]);

        $response = $this->postGenerate([
            'year'  => 2026,
            'month' => 5,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['res' => true]);

        $created = ScholarshipRefrend::where('period_year', 2026)
            ->where('period_month', 5)
            ->count();

        $this->assertSame(2, $created, 'Should generate refrendos for all 2 active becarios.');
    }

    /** @test */
    public function it_generates_only_for_users_in_the_given_generation(): void
    {
        $genA = $this->makeGeneration('Gen A');
        $genB = $this->makeGeneration('Gen B');

        // 2 becarios in Gen A
        $this->makeBecario(['generation_id' => $genA->id]);
        $this->makeBecario(['generation_id' => $genA->id]);

        // 1 becario in Gen B
        $this->makeBecario(['generation_id' => $genB->id]);

        $response = $this->postGenerate([
            'year'          => 2026,
            'month'         => 5,
            'generation_id' => $genA->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.created'));
        $this->assertSame(0, $response->json('data.skipped'));

        // Ensure no refrend was created for Gen B users
        $total = ScholarshipRefrend::where('period_year', 2026)
            ->where('period_month', 5)
            ->count();

        $this->assertSame(2, $total, 'Only 2 refrendos should be created — for Gen A users only.');
    }

    /** @test */
    public function calling_generate_twice_with_same_params_does_not_duplicate(): void
    {
        $gen = $this->makeGeneration('Gen Idem');
        $this->makeBecario(['generation_id' => $gen->id]);

        $params = [
            'year'          => 2026,
            'month'         => 5,
            'generation_id' => $gen->id,
        ];

        // First call
        $this->postGenerate($params)->assertStatus(200);

        // Second call — must be idempotent
        $response = $this->postGenerate($params);
        $response->assertStatus(200);

        $this->assertSame(0, $response->json('data.created'),
            'Second generate call should skip already-existing refrendos.');
        $this->assertSame(1, $response->json('data.skipped'));

        $total = ScholarshipRefrend::where('period_year', 2026)
            ->where('period_month', 5)
            ->count();

        $this->assertSame(1, $total, 'Exactly 1 refrendo should exist after 2 calls.');
    }

    /** @test */
    public function it_sets_snapshot_generation_id_when_generation_id_provided(): void
    {
        $gen = $this->makeGeneration('Gen Snapshot');
        $this->makeBecario(['generation_id' => $gen->id]);

        $this->postGenerate([
            'year'          => 2026,
            'month'         => 5,
            'generation_id' => $gen->id,
        ]);

        $refrend = ScholarshipRefrend::where('period_year', 2026)
            ->where('period_month', 5)
            ->first();

        $this->assertNotNull($refrend, 'Refrendo should have been created.');
        $this->assertSame($gen->id, $refrend->snapshot_generation_id,
            'snapshot_generation_id should match the generation used at generation time.');
    }

    /** @test */
    public function it_returns_422_when_generation_id_does_not_exist(): void
    {
        $response = $this->postGenerate([
            'year'          => 2026,
            'month'         => 5,
            'generation_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['generation_id']);
    }

    /** @test */
    public function it_does_not_generate_for_inactive_users(): void
    {
        $gen = $this->makeGeneration('Gen Inactive');

        // Active becario
        $this->makeBecario(['generation_id' => $gen->id, 'active' => true]);

        // Inactive becario (same generation)
        $this->makeBecario(['generation_id' => $gen->id, 'active' => false]);

        $this->postGenerate([
            'year'          => 2026,
            'month'         => 5,
            'generation_id' => $gen->id,
        ]);

        $total = ScholarshipRefrend::where('period_year', 2026)
            ->where('period_month', 5)
            ->count();

        $this->assertSame(1, $total, 'Only active becarios should get refrendos.');
    }
}
