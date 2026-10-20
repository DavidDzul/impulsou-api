<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresar-boton-corte-ciclo, REQ-PM-2 (S3-S8) + has_scholarship_profile
 * flag on UserController / GraduateController index, show and update.
 */
class UserTypeChangeGuardFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKED_MESSAGE = 'Para egresar a un becario usa la situación Egresado en su refrendo.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function holder(string $type = 'BEC_ACTIVE'): User
    {
        $user = User::factory()->create(['user_type' => $type, 'first_name' => 'Original']);
        ScholarshipProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /** resource, from, to, response key of update */
    public function controllers(): array
    {
        return [
            'users'     => ['users', 'BEC_ACTIVE', 'BEC_INACTIVE', 'updateUser'],
            'graduates' => ['graduates', 'BEC_INACTIVE', 'BEC_ACTIVE', 'updateGraduate'],
        ];
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function changing_user_type_of_a_holder_returns_422_and_persists_nothing(
        string $resource,
        string $from,
        string $to
    ): void {
        $user = $this->holder($from);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/{$resource}/{$user->id}", [
            'user_type'  => $to,
            'first_name' => 'Changed',
        ]);

        $response->assertStatus(422)->assertExactJson([
            'res'     => false,
            'msg'     => self::LOCKED_MESSAGE,
            'message' => self::LOCKED_MESSAGE,
            'code'    => 'USER_TYPE_LOCKED',
        ]);
        $fresh = $user->fresh();
        $this->assertSame($from, $fresh->user_type);
        $this->assertSame('Original', $fresh->first_name);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function same_user_type_with_other_edits_returns_200_with_the_flag(
        string $resource,
        string $from,
        string $to,
        string $key
    ): void {
        $user = $this->holder($from);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/{$resource}/{$user->id}", [
            'user_type'  => $from,
            'first_name' => 'Changed',
        ]);

        $response->assertOk()
            ->assertJsonPath("{$key}.first_name", 'Changed')
            ->assertJsonPath("{$key}.has_scholarship_profile", true);
        $this->assertSame('Changed', $user->fresh()->first_name);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function user_without_profile_can_change_user_type_and_flag_is_false(
        string $resource,
        string $from,
        string $to,
        string $key
    ): void {
        $user = User::factory()->create(['user_type' => $from]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/{$resource}/{$user->id}", [
            'user_type' => $to,
        ]);

        $response->assertOk()->assertJsonPath("{$key}.has_scholarship_profile", false);
        $this->assertSame($to, $user->fresh()->user_type);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function update_without_user_type_key_and_active_remain_editable_for_a_holder(
        string $resource,
        string $from
    ): void {
        $user = $this->holder($from);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/{$resource}/{$user->id}", [
            'active' => false,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $user->fresh()->active);
        $this->assertSame($from, $user->fresh()->user_type);
    }

    /** @test */
    public function users_index_and_show_expose_the_flag(): void
    {
        $holder = $this->holder('BEC_ACTIVE');
        $plain = User::factory()->create(['user_type' => 'BEC_ACTIVE']);

        $index = $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk();
        $rows = collect($index->json('users'))->keyBy('id');
        $this->assertTrue($rows[$holder->id]['has_scholarship_profile']);
        $this->assertFalse($rows[$plain->id]['has_scholarship_profile']);

        $this->actingAs($this->admin)->getJson("/api/admin/users/{$holder->id}")
            ->assertOk()->assertJsonPath('user.has_scholarship_profile', true);
        $this->actingAs($this->admin)->getJson("/api/admin/users/{$plain->id}")
            ->assertOk()->assertJsonPath('user.has_scholarship_profile', false);
    }

    /** @test */
    public function graduates_index_and_show_expose_the_flag(): void
    {
        $holder = $this->holder('BEC_INACTIVE');
        $plain = User::factory()->create(['user_type' => 'BEC_INACTIVE']);

        $index = $this->actingAs($this->admin)->getJson('/api/admin/graduates')->assertOk();
        $rows = collect($index->json('graduates'))->keyBy('id');
        $this->assertTrue($rows[$holder->id]['has_scholarship_profile']);
        $this->assertFalse($rows[$plain->id]['has_scholarship_profile']);

        $this->actingAs($this->admin)->getJson("/api/admin/graduates/{$holder->id}")
            ->assertOk()->assertJsonPath('user.has_scholarship_profile', true);
        $this->actingAs($this->admin)->getJson("/api/admin/graduates/{$plain->id}")
            ->assertOk()->assertJsonPath('user.has_scholarship_profile', false);
    }
}
