<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST api/admin/updateProfile edits ONLY the authenticated user. A body `id` must never
 * select another account (security review C1: ROOT takeover).
 */
class UpdateProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function staff(string $role, array $extra = []): User
    {
        $user = User::factory()->create(array_merge(['user_type' => 'ADMIN', 'campus' => 'MERIDA', 'active' => true], $extra));
        $user->assignRole($role);

        return $user;
    }

    /** @test */
    public function a_body_id_never_selects_another_account_including_root(): void
    {
        $root = $this->staff('ROOT', ['email' => 'root@example.test', 'password' => Hash::make('rootpass')]);
        $attacker = $this->staff('ROOT_CAMPUS', ['email' => 'attacker@example.test']);
        $rootHash = $root->password;
        Sanctum::actingAs($attacker);

        $this->postJson('/api/admin/updateProfile', ['id' => $root->id, 'email' => 'evil@example.test', 'password' => 'hacked123'])
            ->assertOk()
            ->assertJsonPath('user.id', $attacker->id);

        $root->refresh();
        $this->assertSame('root@example.test', $root->email);
        $this->assertSame($rootHash, $root->password);
        $attacker->refresh();
        $this->assertSame('evil@example.test', $attacker->email);
        $this->assertTrue(Hash::check('hacked123', $attacker->password));
    }

    /** @test */
    public function a_user_updates_their_own_profile_without_sending_an_id(): void
    {
        $user = $this->staff('ADMIN_JOB', ['first_name' => 'Old']);
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/updateProfile', ['first_name' => 'New'])->assertOk();

        $this->assertSame('New', $user->fresh()->first_name);
    }

    /** @test */
    public function a_matching_own_id_still_works_as_the_panels_send_it(): void
    {
        $user = $this->staff('ADMIN_JOB', ['first_name' => 'Old']);
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/updateProfile', ['id' => $user->id, 'first_name' => 'New'])->assertOk();

        $this->assertSame('New', $user->fresh()->first_name);
    }

    /** @test */
    public function an_unknown_body_id_is_ignored_not_a_lookup(): void
    {
        $user = $this->staff('ADMIN_JOB', ['first_name' => 'Old']);
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/updateProfile', ['id' => 999999, 'first_name' => 'New'])->assertOk();

        $this->assertSame('New', $user->fresh()->first_name);
    }

    /** @test */
    public function the_email_uniqueness_rule_is_checked_against_the_authenticated_user(): void
    {
        $other = $this->staff('ROOT', ['email' => 'taken@example.test']);
        $user = $this->staff('ADMIN_JOB');
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/updateProfile', ['id' => $other->id, 'email' => 'taken@example.test'])
            ->assertStatus(422);
    }
}
