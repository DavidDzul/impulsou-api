<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * administradores-psicol PR1: the shared admin login (psicol-panel and administration-panel
 * both call api/admin/login) must refuse deactivated accounts with the same generic 422
 * the business login uses, so account state cannot be enumerated.
 */
class AdminLoginActiveCheckTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Las credenciales son incorrectas.';

    private function login(User $user, string $password = 'password')
    {
        return $this->postJson('api/admin/login', ['email' => $user->email, 'password' => $password]);
    }

    /** @test */
    public function active_admin_can_log_in(): void
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $this->login($user)->assertOk()->assertJson(['res' => true])->assertJsonStructure(['token']);
    }

    /** @test */
    public function inactive_admin_cannot_log_in_and_gets_the_generic_message(): void
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => false]);

        $this->login($user)
            ->assertStatus(422)
            ->assertJsonValidationErrors('msg')
            ->assertJsonPath('errors.msg.0', self::MESSAGE)
            ->assertJsonMissing(['token']);

        $this->assertSame(0, $user->tokens()->count());
    }

    /** @test */
    public function inactive_account_gets_the_same_response_as_a_wrong_password(): void
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => false]);
        $active = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $inactive = $this->login($user);
        $wrong = $this->login($active, 'not-the-password');

        $this->assertSame($wrong->status(), $inactive->status());
        $this->assertSame($wrong->json(), $inactive->json());
    }

    /** @test */
    public function inactive_business_user_is_still_refused_without_a_token(): void
    {
        $user = User::factory()->create(['user_type' => 'BUSINESS', 'active' => false]);

        $this->login($user)->assertStatus(422)->assertJsonPath('errors.msg.0', self::MESSAGE);
    }

    /** @test */
    public function active_business_user_is_still_denied_with_403(): void
    {
        $user = User::factory()->create(['user_type' => 'BUSINESS', 'active' => true]);

        $this->login($user)->assertStatus(403);
    }
}
