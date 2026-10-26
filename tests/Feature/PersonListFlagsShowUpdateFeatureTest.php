<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * show and update of users / graduates must expose the same computed flags as
 * the list rows (has_scholarship_profile, amounts_configured,
 * curriculum_uploaded), because panels replace the list row with the response.
 */
class PersonListFlagsShowUpdateFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const FLAGS = ['has_scholarship_profile', 'amounts_configured', 'curriculum_uploaded'];

    /** resource, user_type, update envelope key */
    public function endpoints(): array
    {
        return [
            'users'     => ['users', 'BEC_ACTIVE', 'updateUser'],
            'graduates' => ['graduates', 'BEC_INACTIVE', 'updateGraduate'],
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function withFullProfile(string $type): User
    {
        $user = User::factory()->create(['user_type' => $type]);
        ScholarshipProfile::factory()->create([
            'user_id'            => $user->id,
            'config_set_at'      => now(),
            'reticula_file_path' => 'reticulas/x.pdf',
        ]);

        return $user;
    }

    private function assertFlags(array $payload, bool $expected): void
    {
        foreach (self::FLAGS as $flag) {
            $this->assertArrayHasKey($flag, $payload, "missing {$flag}");
            $this->assertSame($expected, $payload[$flag], $flag);
        }
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function show_without_profile_returns_all_flags_false(string $resource, string $type): void
    {
        $user = User::factory()->create(['user_type' => $type]);

        $json = $this->actingAs($this->admin())->getJson("/api/admin/{$resource}/{$user->id}")->assertOk()->json('user');

        $this->assertFlags($json, false);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function show_with_full_profile_returns_all_flags_true(string $resource, string $type): void
    {
        $user = $this->withFullProfile($type);

        $json = $this->actingAs($this->admin())->getJson("/api/admin/{$resource}/{$user->id}")->assertOk()->json('user');

        $this->assertFlags($json, true);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function update_without_profile_returns_all_flags_false(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);

        $json = $this->actingAs($this->admin())
            ->putJson("/api/admin/{$resource}/{$user->id}", ['first_name' => 'Nuevo'])
            ->assertOk()
            ->json($key);

        $this->assertSame('Nuevo', $json['first_name']);
        $this->assertFlags($json, false);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function update_with_full_profile_returns_all_flags_true(string $resource, string $type, string $key): void
    {
        $user = $this->withFullProfile($type);

        $json = $this->actingAs($this->admin())
            ->putJson("/api/admin/{$resource}/{$user->id}", ['first_name' => 'Nuevo'])
            ->assertOk()
            ->assertJsonPath('res', true)
            ->json($key);

        $this->assertSame('Nuevo', $json['first_name']);
        $this->assertFlags($json, true);
    }
}
