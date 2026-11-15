<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/clases-filtro-periodo-asignacion-manual, class-manual-assignment R2.2.
 */
class FilterUsersEligibleOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function filter_users_returns_only_active_scholarship_holders_of_campus_and_generation(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true, 'campus' => 'MERIDA']);
        $admin->assignRole('ADMIN_STUDENT');
        $g1 = Generation::create(['generation_name' => 'G1', 'campus' => 'MERIDA', 'generation_active' => true]);
        $g2 = Generation::create(['generation_name' => 'G2', 'campus' => 'MERIDA', 'generation_active' => true]);

        $base = ['user_type' => 'BEC_ACTIVE', 'active' => true, 'campus' => 'MERIDA', 'generation_id' => $g1->id];
        $eligible = User::factory()->create($base);
        User::factory()->create(array_merge($base, ['user_type' => 'BEC_INACTIVE']));
        User::factory()->create(array_merge($base, ['active' => false]));
        User::factory()->create(array_merge($base, ['user_type' => 'BUSINESS']));
        User::factory()->create(array_merge($base, ['campus' => 'VALLADOLID']));
        User::factory()->create(array_merge($base, ['generation_id' => $g2->id]));

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/filterUsers?campus=MERIDA&generation_id=' . $g1->id)
            ->assertOk()
            ->assertJsonPath('res', true);

        $this->assertSame([$eligible->id], collect($response->json('data'))->pluck('id')->all());
    }
}
