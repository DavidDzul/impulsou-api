<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * curriculum_uploaded flag on the becario / egresado index endpoints:
 * true only when the user has a scholarship profile with reticula_file_path set.
 */
class CurriculumUploadedListFlagFeatureTest extends TestCase
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
    public function user_without_profile_has_curriculum_uploaded_false(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);

        $this->assertFalse($this->rows($resource, $key)[$user->id]['curriculum_uploaded']);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function profile_with_null_file_path_has_curriculum_uploaded_false_even_with_reticula_dates(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);
        ScholarshipProfile::factory()->create([
            'user_id'             => $user->id,
            'reticula_file_path'  => null,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
        ]);

        $row = $this->rows($resource, $key)[$user->id];

        $this->assertFalse($row['curriculum_uploaded']);
        $this->assertTrue($row['has_scholarship_profile']);
    }

    /**
     * @test
     * @dataProvider endpoints
     */
    public function profile_with_file_path_has_curriculum_uploaded_true(string $resource, string $type, string $key): void
    {
        $user = User::factory()->create(['user_type' => $type]);
        ScholarshipProfile::factory()->create([
            'user_id'            => $user->id,
            'reticula_file_path' => 'reticulas/constancia.pdf',
        ]);

        $this->assertTrue($this->rows($resource, $key)[$user->id]['curriculum_uploaded']);
    }
}
