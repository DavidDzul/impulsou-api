<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Security review M1: a campus-scoped actor cannot move a person to another campus
 * through users/{id} or graduates/{id}. Rejected with 422 (explicit, no silent discard);
 * global actors (ROOT) keep changing campus.
 */
class PersonsCampusImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function resources(): array
    {
        return [
            'users' => ['users', 'BEC_ACTIVE'],
            'graduates' => ['graduates', 'BEC_INACTIVE'],
        ];
    }

    private function actor(string $role, string $campus): User
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'campus' => $campus, 'active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function person(string $type, string $campus): User
    {
        return User::factory()->create(['user_type' => $type, 'campus' => $campus, 'active' => true]);
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function campus_scoped_actor_cannot_move_a_person_to_another_campus(string $resource, string $type): void
    {
        $person = $this->person($type, 'VALLADOLID');
        $this->actor('ROOT_CAMPUS', 'VALLADOLID');

        $this->putJson("/api/admin/{$resource}/{$person->id}", ['campus' => 'TIZIMIN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('campus');

        $this->assertSame('VALLADOLID', $person->fresh()->campus);
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function campus_scoped_actor_can_resend_their_own_campus(string $resource, string $type): void
    {
        $person = $this->person($type, 'VALLADOLID');
        $this->actor('ROOT_CAMPUS', 'VALLADOLID');

        $this->putJson("/api/admin/{$resource}/{$person->id}", ['campus' => 'VALLADOLID', 'first_name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $person->fresh()->first_name);
        $this->assertSame('VALLADOLID', $person->fresh()->campus);
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function root_can_still_change_a_persons_campus(string $resource, string $type): void
    {
        $person = $this->person($type, 'VALLADOLID');
        $this->actor('ROOT', 'MERIDA');

        $this->putJson("/api/admin/{$resource}/{$person->id}", ['campus' => 'TIZIMIN'])->assertOk();

        $this->assertSame('TIZIMIN', $person->fresh()->campus);
    }
}
