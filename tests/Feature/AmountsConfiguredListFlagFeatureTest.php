<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * amounts_configured flag on the becario / egresado index endpoints:
 * true only when the user has a scholarship profile with config_set_at set.
 */
class AmountsConfiguredListFlagFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** resource, user_type, response key */
    public function endpoints(): array
    {
        return [
            'users'     => ['users', 'BEC_ACTIVE', 'users'],
            'graduates' => ['graduates', 'BEC_INACTIVE', 'graduates'],
        ];
    }

    private function rows(string $resource, string $key)
    {
        $admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        return collect(
            $this->actingAs($admin)->getJson("/api/admin/{$resource}")->assertOk()->json($key)
        )->keyBy('id');
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function user_without_profile_has_amounts_configured_false(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);

        $this->assertFalse($this->rows($resource, $key)[$user->id]['amounts_configured']);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function profile_with_null_config_set_at_has_amounts_configured_false(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);
        ScholarshipProfile::factory()->configPending()->create(['user_id' => $user->id]);

        $row = $this->rows($resource, $key)[$user->id];

        $this->assertFalse($row['amounts_configured']);
        $this->assertTrue($row['has_scholarship_profile']);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function profile_with_config_set_at_has_amounts_configured_true(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);
        ScholarshipProfile::factory()->create(['user_id' => $user->id, 'config_set_at' => now()]);

        $this->assertTrue($this->rows($resource, $key)[$user->id]['amounts_configured']);
    }
}
