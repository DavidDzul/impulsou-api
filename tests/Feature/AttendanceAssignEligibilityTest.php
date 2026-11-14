<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/clases-filtro-periodo-asignacion-manual, class-manual-assignment R2.4-R2.6.
 */
class AttendanceAssignEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Generation $generation;
    private Generation $otherGeneration;
    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true, 'campus' => 'MERIDA']);
        $this->generation = Generation::create(['generation_name' => 'G1', 'campus' => 'MERIDA', 'generation_active' => true]);
        $this->otherGeneration = Generation::create(['generation_name' => 'G2', 'campus' => 'MERIDA', 'generation_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Clase',
            'date' => '2026-11-20',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'campus' => 'MERIDA',
            'generation_id' => $this->generation->id,
        ]);
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

    private function assign(array $userIds)
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/attendance', [
            'class_id' => $this->class->id,
            'user_ids' => $userIds,
        ]);
    }

    private function assertRejected($response, array $ids): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('res', false)
            ->assertJsonPath('code', 'USERS_NOT_ELIGIBLE_FOR_CLASS')
            ->assertJsonPath('ineligible_user_ids', $ids);
        $this->assertSame(0, Attendance::count());
    }

    /** @test */
    public function rejects_bec_inactive_user(): void
    {
        $u = $this->makeUser(['user_type' => 'BEC_INACTIVE']);
        $this->assertRejected($this->assign([$u->id]), [$u->id]);
    }

    /** @test */
    public function rejects_inactive_user(): void
    {
        $u = $this->makeUser(['active' => false]);
        $this->assertRejected($this->assign([$u->id]), [$u->id]);
    }

    /** @test */
    public function rejects_non_scholarship_user_type(): void
    {
        $u = $this->makeUser(['user_type' => 'BUSINESS']);
        $this->assertRejected($this->assign([$u->id]), [$u->id]);
    }

    /** @test */
    public function rejects_other_campus(): void
    {
        $u = $this->makeUser(['campus' => 'VALLADOLID']);
        $this->assertRejected($this->assign([$u->id]), [$u->id]);
    }

    /** @test */
    public function rejects_other_generation(): void
    {
        $u = $this->makeUser(['generation_id' => $this->otherGeneration->id]);
        $this->assertRejected($this->assign([$u->id]), [$u->id]);
    }

    /** @test */
    public function mixed_valid_and_invalid_writes_nothing(): void
    {
        $ok = $this->makeUser();
        $bad = $this->makeUser(['user_type' => 'BUSINESS']);

        $this->assertRejected($this->assign([$ok->id, $bad->id]), [$bad->id]);
    }

    /** @test */
    public function eligible_user_is_assigned_as_absent(): void
    {
        $u = $this->makeUser();

        $this->assign([$u->id])
            ->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('data.0.user_id', $u->id)
            ->assertJsonPath('data.0.user.id', $u->id);

        $this->assertSame('ABSENT', Attendance::where('user_id', $u->id)->first()->status);
    }

    /** @test */
    public function existing_attendee_who_became_ineligible_is_kept(): void
    {
        $u = $this->makeUser();
        Attendance::create(['class_id' => $this->class->id, 'user_id' => $u->id]);
        $u->update(['user_type' => 'BUSINESS', 'active' => false]);

        $this->assign([$u->id])->assertOk();

        $this->assertSame(1, Attendance::where('class_id', $this->class->id)->where('user_id', $u->id)->count());
    }

    /** @test */
    public function omitted_attendees_are_removed_only_without_check_in(): void
    {
        $free = $this->makeUser();
        $locked = $this->makeUser();
        $keep = $this->makeUser();
        Attendance::create(['class_id' => $this->class->id, 'user_id' => $free->id]);
        Attendance::create(['class_id' => $this->class->id, 'user_id' => $locked->id, 'check_in' => '09:00:00', 'status' => 'PRESENT']);
        Attendance::create(['class_id' => $this->class->id, 'user_id' => $keep->id]);

        $this->assign([$keep->id])->assertOk();

        $this->assertDatabaseMissing('attendances', ['class_id' => $this->class->id, 'user_id' => $free->id]);
        $this->assertDatabaseHas('attendances', ['class_id' => $this->class->id, 'user_id' => $locked->id]);
        $this->assertDatabaseHas('attendances', ['class_id' => $this->class->id, 'user_id' => $keep->id]);
    }

    /** @test */
    public function duplicate_or_unknown_user_ids_fail_validation(): void
    {
        $u = $this->makeUser();

        $this->assign([$u->id, $u->id])->assertStatus(422);
        $this->assign([999999])->assertStatus(422);
        $this->assertSame(0, Attendance::count());
    }
}
