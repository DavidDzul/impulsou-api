<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/clases-filtro-periodo-asignacion-manual, class-uniqueness R3.1-R3.2.
 */
class ClassStoreUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true, 'campus' => 'MERIDA']);
        $this->generation = Generation::create(['generation_name' => 'G1', 'campus' => 'MERIDA', 'generation_active' => true]);
    }

    private function payload(string $date = '2026-11-20'): array
    {
        return [
            'name' => 'Clase',
            'date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'campus' => 'MERIDA',
            'generation_id' => $this->generation->id,
        ];
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'user_type' => 'BEC_ACTIVE',
            'active' => true,
            'campus' => 'MERIDA',
            'generation_id' => $this->generation->id,
        ], $overrides));
    }

    /** @test */
    public function duplicate_triple_returns_422_with_friendly_message(): void
    {
        ClassModel::create($this->payload());

        $this->actingAs($this->admin)->postJson('/api/admin/class', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', ClassModel::duplicateDateMessage());

        $this->assertSame(1, ClassModel::count());
    }

    /** @test */
    public function race_on_unique_index_returns_422_and_leaves_no_orphans(): void
    {
        $this->makeUser();
        $payload = $this->payload();
        $fired = false;

        // Simulate the concurrent request winning: the twin row appears right before our insert.
        ClassModel::creating(function () use ($payload, &$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            // sqlite stores the date cast as 'Y-m-d 00:00:00'; mirror it so the index sees the same value.
            DB::table('classes')->insert(array_merge($payload, [
                'date' => $payload['date'] . ' 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });

        $this->actingAs($this->admin)->postJson('/api/admin/class', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', ClassModel::duplicateDateMessage());

        // The simulated twin shares this connection (so it rolls back with the transaction);
        // what matters is that the failed request leaves no class and no orphan attendances.
        $this->assertTrue($fired);
        $this->assertSame(0, ClassModel::count());
        $this->assertSame(0, Attendance::count());
    }

    /** @test */
    public function auto_assign_attaches_only_eligible_users_as_absent(): void
    {
        $eligible = $this->makeUser();
        $this->makeUser(['user_type' => 'BEC_INACTIVE']);
        $this->makeUser(['active' => false]);
        $this->makeUser(['campus' => 'TIZIMIN']);

        $this->actingAs($this->admin)->postJson('/api/admin/class', $this->payload('2026-11-21'))
            ->assertSuccessful();

        $this->assertSame([$eligible->id], Attendance::pluck('user_id')->all());
        $this->assertSame('ABSENT', Attendance::first()->status);
    }

    /** @test */
    public function happy_path_creates_class(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/class', $this->payload('2026-12-01'))
            ->assertSuccessful();

        $this->assertSame(1, ClassModel::count());
    }
}
