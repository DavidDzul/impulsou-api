<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * users/{id} and graduates/{id} only resolve becarios/egresados inside the actor's visibility
 * (same rule as the index). Anything else is an indistinguishable 404, before validation.
 */
class PersonsScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeUser(string $type, string $campus, ?string $role = null, array $extra = []): User
    {
        $user = User::factory()->create(array_merge(
            ['user_type' => $type, 'campus' => $campus, 'active' => true],
            $extra
        ));
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function as(User $actor): self
    {
        Sanctum::actingAs($actor);

        return $this;
    }

    public function resources(): array
    {
        return [
            'users' => ['users', 'BEC_ACTIVE'],
            'graduates' => ['graduates', 'BEC_INACTIVE'],
        ];
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function root_campus_cannot_take_over_root(string $resource): void
    {
        $root = $this->makeUser('ADMIN', 'MERIDA', 'ROOT', ['email' => 'root@example.test']);
        $hash = $root->password;
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)
            ->putJson("/api/admin/{$resource}/{$root->id}", ['email' => 'evil@example.test', 'password' => 'hacked123'])
            ->assertNotFound();

        $root->refresh();
        $this->assertSame('root@example.test', $root->email);
        $this->assertSame($hash, $root->password);
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function non_person_targets_are_404_for_every_actor_including_root(string $resource): void
    {
        $business = $this->makeUser('BUSINESS', 'MERIDA');
        $rootAdm = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION');
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'ATTENDANCE');
        $actors = [
            $this->makeUser('ADMIN', 'MERIDA', 'ROOT'),
            $this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION'),
            $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS'),
            $this->makeUser('ADMIN', 'MERIDA'),
        ];

        foreach ($actors as $actor) {
            foreach ([$business, $rootAdm, $staff] as $target) {
                $this->as($actor)->getJson("/api/admin/{$resource}/{$target->id}")->assertNotFound();
                $this->as($actor)
                    ->putJson("/api/admin/{$resource}/{$target->id}", ['user_type' => 'BEC_ACTIVE', 'first_name' => 'X'])
                    ->assertNotFound();
            }
        }

        $this->assertSame('BUSINESS', $business->fresh()->user_type);
        $this->assertSame('ADMIN', $staff->fresh()->user_type);
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function person_of_another_campus_is_404_on_show_and_update(string $resource, string $type): void
    {
        $other = $this->makeUser($type, 'VALLADOLID', null, ['first_name' => 'Original']);
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)->getJson("/api/admin/{$resource}/{$other->id}")->assertNotFound();
        $this->as($actor)
            ->putJson("/api/admin/{$resource}/{$other->id}", ['first_name' => 'Changed'])
            ->assertNotFound();
        $this->assertSame('Original', $other->fresh()->first_name);
    }

    /** @test */
    public function person_of_another_campus_is_404_on_fetch_pdf(): void
    {
        $other = $this->makeUser('BEC_ACTIVE', 'VALLADOLID');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)->putJson("/api/admin/users/{$other->id}/fetchPDF")->assertNotFound();
    }

    /** @test */
    public function fetch_pdf_of_non_person_is_404_even_for_root(): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'ATTENDANCE');
        $root = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');

        $this->as($root)->putJson("/api/admin/users/{$staff->id}/fetchPDF")->assertNotFound();
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function root_administration_can_read_a_person_of_any_campus(string $resource, string $type): void
    {
        $other = $this->makeUser($type, 'VALLADOLID');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION');

        $this->as($actor)->getJson("/api/admin/{$resource}/{$other->id}")
            ->assertOk()->assertJsonPath('user.id', $other->id);
    }

    /** @test */
    public function egresado_resolves_through_users_route_for_show_and_update(): void
    {
        $graduate = $this->makeUser('BEC_INACTIVE', 'MERIDA', null, ['first_name' => 'Old']);
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)->getJson("/api/admin/users/{$graduate->id}")->assertOk();
        $this->as($actor)->putJson("/api/admin/users/{$graduate->id}", ['first_name' => 'New'])->assertOk();
        $this->assertSame('New', $graduate->fresh()->first_name);
    }

    /** @test */
    public function becario_resolves_through_graduates_route_for_show(): void
    {
        $becario = $this->makeUser('BEC_ACTIVE', 'MERIDA');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)->getJson("/api/admin/graduates/{$becario->id}")->assertOk();
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function scope_is_empty_for_empty_campus_staff(string $resource, string $type): void
    {
        $person = $this->makeUser($type, 'MERIDA');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');
        $actor->campus = ''; // enum CHECK prevents persisting ''; resolve in memory.

        $this->assertSame(0, User::personsVisibleTo($actor)->count());
        $this->assertNotNull($person->id);
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function non_numeric_id_is_404(string $resource): void
    {
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');

        $this->as($actor)->getJson("/api/admin/{$resource}/1abc")->assertNotFound();
        $this->as($actor)->putJson("/api/admin/{$resource}/1abc", [])->assertNotFound();
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function delete_is_not_available(string $resource, string $type): void
    {
        $person = $this->makeUser($type, 'MERIDA');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');

        $status = $this->as($actor)->deleteJson("/api/admin/{$resource}/{$person->id}")->getStatusCode();

        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertNotNull($person->fresh());
    }

    /**
     * @test
     * @dataProvider resources
     */
    public function out_of_scope_target_is_404_before_validation(string $resource): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'ATTENDANCE');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)
            ->putJson("/api/admin/{$resource}/{$staff->id}", ['email' => 'not-an-email', 'user_type' => 'nope'])
            ->assertNotFound();
    }

    /** @test */
    public function out_of_scope_target_never_reaches_deactivation_or_type_guard(): void
    {
        $this->mock(\App\Services\Users\UserDeactivationService::class)->shouldNotReceive('save');
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'ATTENDANCE');
        $actor = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->as($actor)->putJson("/api/admin/users/{$staff->id}", ['active' => false])->assertNotFound();
        $this->as($actor)->putJson("/api/admin/graduates/{$staff->id}", ['active' => false])->assertNotFound();
    }
}
