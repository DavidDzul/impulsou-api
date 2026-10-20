<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/generaciones-activas-refrendos, R2-R4: pending_becarios_count on the
 * generation endpoints and the active -> inactive deactivation guard.
 */
class GenerationDeactivationGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15');
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true, 'campus' => 'MERIDA']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeGeneration(string $name, bool $active = true): Generation
    {
        return Generation::create(['generation_name' => $name, 'campus' => 'MERIDA', 'generation_active' => $active]);
    }

    /** Pending becarios (inside the window) for the generation. */
    private function addPending(Generation $gen, int $n, array $userOverrides = [], array $profileOverrides = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $user = User::factory()->create(array_merge([
                'user_type' => 'BEC_ACTIVE', 'active' => true, 'campus' => 'MERIDA', 'generation_id' => $gen->id,
            ], $userOverrides));
            ScholarshipProfile::factory()->create(array_merge([
                'user_id' => $user->id, 'reticula_end_date' => '2026-12-31',
            ], $profileOverrides));
        }
    }

    // ---- R2: count exposure --------------------------------------------

    /** @test */
    public function index_exposes_the_pending_count_per_generation_and_zero_when_none(): void
    {
        $g1 = $this->makeGeneration('G1');
        $g2 = $this->makeGeneration('G2');
        $g3 = $this->makeGeneration('G3');
        $this->addPending($g1, 3);
        $this->addPending($g2, 1);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/generations');

        $response->assertOk();
        $byId = collect($response->json('generations'))->keyBy('id');
        $this->assertSame(3, $byId[$g1->id]['pending_becarios_count']);
        $this->assertSame(1, $byId[$g2->id]['pending_becarios_count']);
        $this->assertSame(0, $byId[$g3->id]['pending_becarios_count']);
    }

    /** @test */
    public function index_query_count_does_not_grow_with_the_number_of_generations(): void
    {
        $this->makeGeneration('G1');
        $this->addPending(Generation::first(), 1);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->getJson('/api/admin/generations')->assertOk();
        $queriesWithOne = count(DB::getQueryLog());

        foreach (['G2', 'G3'] as $name) {
            $this->addPending($this->makeGeneration($name), 2);
        }

        DB::flushQueryLog();
        $this->actingAs($this->admin)->getJson('/api/admin/generations')->assertOk();
        $queriesWithThree = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesWithOne, $queriesWithThree);
    }

    /** @test */
    public function show_and_update_include_the_same_count_as_index(): void
    {
        $gen = $this->makeGeneration('G1');
        $this->addPending($gen, 2);

        $show = $this->actingAs($this->admin)->getJson("/api/admin/generations/{$gen->id}");
        $update = $this->actingAs($this->admin)->putJson("/api/admin/generations/{$gen->id}", ['generation_name' => 'G1 renamed']);

        $show->assertOk();
        $this->assertSame(2, $show->json('generation.pending_becarios_count'));
        $update->assertOk();
        $this->assertSame(2, $update->json('data.pending_becarios_count'));
    }

    // ---- R3: guard ------------------------------------------------------

    /** @test */
    public function deactivating_a_generation_with_pending_becarios_is_rejected_with_422(): void
    {
        $gen = $this->makeGeneration('G1');
        $this->addPending($gen, 3);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/generations/{$gen->id}", ['generation_active' => false]);

        $response->assertStatus(422)->assertJson([
            'res'                    => false,
            'code'                   => 'GENERATION_HAS_PENDING_BECARIOS',
            'pending_becarios_count' => 3,
        ]);
        $this->assertNotEmpty($response->json('msg'));
        $this->assertSame($response->json('msg'), $response->json('message'));
        $this->assertTrue($gen->fresh()->generation_active);
    }

    /** @test */
    public function deactivating_a_generation_without_pending_becarios_succeeds(): void
    {
        $gen = $this->makeGeneration('G1');

        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/generations/{$gen->id}", ['generation_active' => false]);

        $response->assertOk();
        $this->assertFalse($gen->fresh()->generation_active);
    }

    /** @test */
    public function becarios_with_inactive_users_or_expired_window_do_not_block_deactivation(): void
    {
        $gen = $this->makeGeneration('G1');
        $this->addPending($gen, 1, ['active' => false]);
        $this->addPending($gen, 1, [], ['reticula_end_date' => '2026-07-31']);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/generations/{$gen->id}", ['generation_active' => false])
            ->assertOk();

        $this->assertFalse($gen->fresh()->generation_active);
    }

    // ---- R4: guard scope ------------------------------------------------

    /** @test */
    public function activating_an_inactive_generation_with_pending_becarios_is_allowed(): void
    {
        $gen = $this->makeGeneration('G1', false);
        $this->addPending($gen, 2);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/generations/{$gen->id}", ['generation_active' => true])
            ->assertOk();

        $this->assertTrue($gen->fresh()->generation_active);
    }

    /** @test */
    public function renaming_an_active_generation_with_pending_becarios_is_allowed(): void
    {
        $gen = $this->makeGeneration('G1');
        $this->addPending($gen, 2);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/generations/{$gen->id}", ['generation_name' => 'G1 renamed', 'generation_active' => true])
            ->assertOk();

        $fresh = $gen->fresh();
        $this->assertSame('G1 renamed', $fresh->generation_name);
        $this->assertTrue($fresh->generation_active);
    }

    /** @test */
    public function renaming_an_inactive_generation_with_pending_becarios_keeping_it_inactive_is_allowed(): void
    {
        $gen = $this->makeGeneration('G1', false);
        $this->addPending($gen, 2);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/generations/{$gen->id}", ['generation_name' => 'G1 renamed', 'generation_active' => false])
            ->assertOk();

        $fresh = $gen->fresh();
        $this->assertSame('G1 renamed', $fresh->generation_name);
        $this->assertFalse($fresh->generation_active);
    }
}
