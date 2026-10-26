<?php

namespace Tests\Unit;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserWithListFlagsScopeTest extends TestCase
{
    use RefreshDatabase;

    private function flagged(User $user): User
    {
        return User::withListFlags()->findOrFail($user->id);
    }

    /** @test */
    public function user_without_profile_has_all_flags_false(): void
    {
        $user = $this->flagged(User::factory()->create());

        $this->assertFalse($user->has_scholarship_profile);
        $this->assertFalse($user->amounts_configured);
        $this->assertFalse($user->curriculum_uploaded);
    }

    /** @test */
    public function profile_without_config_nor_reticula_only_sets_has_scholarship_profile(): void
    {
        $base = User::factory()->create();
        ScholarshipProfile::factory()->configPending()->create(['user_id' => $base->id, 'reticula_file_path' => null]);

        $user = $this->flagged($base);

        $this->assertTrue($user->has_scholarship_profile);
        $this->assertFalse($user->amounts_configured);
        $this->assertFalse($user->curriculum_uploaded);
    }

    /** @test */
    public function profile_with_config_and_reticula_sets_all_flags(): void
    {
        $base = User::factory()->create();
        ScholarshipProfile::factory()->create([
            'user_id'            => $base->id,
            'config_set_at'      => now(),
            'reticula_file_path' => 'reticulas/x.pdf',
        ]);

        $user = $this->flagged($base);

        $this->assertTrue($user->has_scholarship_profile);
        $this->assertTrue($user->amounts_configured);
        $this->assertTrue($user->curriculum_uploaded);
    }

    /** @test */
    public function scope_is_chainable_with_other_constraints(): void
    {
        User::factory()->create(['user_type' => 'BEC_ACTIVE']);
        User::factory()->create(['user_type' => 'BEC_INACTIVE']);

        $rows = User::withListFlags()->where('user_type', 'BEC_ACTIVE')->get();

        $this->assertCount(1, $rows);
    }
}
