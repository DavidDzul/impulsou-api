<?php

namespace Tests\Unit;

use App\Exceptions\UserTypeLockedException;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Support\Scholarship\UserTypeChangeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresar-boton-corte-ciclo, REQ-PM-2. A scholarship holder's user_type
 * is immutable through the user update endpoints.
 */
class UserTypeChangeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function holder(string $type): User
    {
        $user = User::factory()->create(['user_type' => $type]);
        ScholarshipProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /** @test */
    public function changing_user_type_of_a_holder_throws_in_both_directions(): void
    {
        foreach ([['BEC_ACTIVE', 'BEC_INACTIVE'], ['BEC_INACTIVE', 'BEC_ACTIVE']] as [$from, $to]) {
            $user = $this->holder($from);

            try {
                UserTypeChangeGuard::assertAllowed($user, ['user_type' => $to]);
                $this->fail("Expected UserTypeLockedException for {$from} -> {$to}");
            } catch (UserTypeLockedException $e) {
                $this->assertSame('USER_TYPE_LOCKED', UserTypeLockedException::CODE);
                $this->assertSame(
                    'Para egresar a un becario usa la situación Egresado en su refrendo.',
                    $e->getMessage()
                );
            }
        }
    }

    /** @test */
    public function same_value_passes_for_a_holder(): void
    {
        $user = $this->holder('BEC_ACTIVE');

        UserTypeChangeGuard::assertAllowed($user, ['user_type' => 'BEC_ACTIVE']);

        $this->assertTrue(true);
    }

    /** @test */
    public function missing_user_type_key_passes_for_a_holder(): void
    {
        $user = $this->holder('BEC_ACTIVE');

        UserTypeChangeGuard::assertAllowed($user, ['first_name' => 'Ana']);

        $this->assertTrue(true);
    }

    /** @test */
    public function change_without_scholarship_profile_passes(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE']);

        UserTypeChangeGuard::assertAllowed($user, ['user_type' => 'BEC_INACTIVE']);

        $this->assertTrue(true);
    }
}
