<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Characterizes GET api/admin/users (BEC_ACTIVE) and GET api/admin/graduates (BEC_INACTIVE).
 * isRoot() (ROOT or ROOT_ADMINISTRATION) is global on purpose: administration-panel reads
 * these lists with ADM_* roles. Everyone else sees their own campus only.
 */
class PersonsIndexCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $activeMerida;
    private User $activeValladolid;
    private User $graduateMerida;
    private User $graduateValladolid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->activeMerida = $this->makeUser('BEC_ACTIVE', 'MERIDA');
        $this->activeValladolid = $this->makeUser('BEC_ACTIVE', 'VALLADOLID');
        $this->graduateMerida = $this->makeUser('BEC_INACTIVE', 'MERIDA');
        $this->graduateValladolid = $this->makeUser('BEC_INACTIVE', 'VALLADOLID');

        // Noise that must never be listed.
        $this->makeUser('BUSINESS', 'MERIDA');
        $this->makeUser('ADMIN', 'VALLADOLID', 'ATTENDANCE');
    }

    private function makeUser(string $type, string $campus, ?string $role = null): User
    {
        $user = User::factory()->create(['user_type' => $type, 'campus' => $campus, 'active' => true]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function listed(User $actor, string $uri, string $key): array
    {
        Sanctum::actingAs($actor);
        $response = $this->getJson($uri);
        $response->assertOk()->assertJsonPath('res', true);

        return collect($response->json($key))->pluck('id')->sort()->values()->all();
    }

    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function actors(): array
    {
        return [
            'ROOT sees all campuses' => ['ROOT', 'MERIDA', 'all'],
            'ROOT_ADMINISTRATION sees all campuses' => ['ROOT_ADMINISTRATION', 'MERIDA', 'all'],
            'ROOT_CAMPUS MERIDA sees own campus' => ['ROOT_CAMPUS', 'MERIDA', 'MERIDA'],
            'ROOT_CAMPUS VALLADOLID sees own campus' => ['ROOT_CAMPUS', 'VALLADOLID', 'VALLADOLID'],
            'ADMIN_STUDENT MERIDA sees own campus' => ['ADMIN_STUDENT', 'MERIDA', 'MERIDA'],
            'roleless ADMIN sees own campus' => [null, 'MERIDA', 'MERIDA'],
        ];
    }

    /**
     * @test
     * @dataProvider actors
     */
    public function users_index_lists_only_becarios_in_actor_visibility(?string $role, string $campus, string $expect): void
    {
        $actor = $this->makeUser('ADMIN', $campus, $role);

        $expected = match ($expect) {
            'all' => $this->ids($this->activeMerida, $this->activeValladolid),
            'MERIDA' => $this->ids($this->activeMerida),
            'VALLADOLID' => $this->ids($this->activeValladolid),
        };

        $this->assertSame($expected, $this->listed($actor, '/api/admin/users', 'users'));
    }

    /**
     * @test
     * @dataProvider actors
     */
    public function graduates_index_lists_only_egresados_in_actor_visibility(?string $role, string $campus, string $expect): void
    {
        $actor = $this->makeUser('ADMIN', $campus, $role);

        $expected = match ($expect) {
            'all' => $this->ids($this->graduateMerida, $this->graduateValladolid),
            'MERIDA' => $this->ids($this->graduateMerida),
            'VALLADOLID' => $this->ids($this->graduateValladolid),
        };

        $this->assertSame($expected, $this->listed($actor, '/api/admin/graduates', 'graduates'));
    }
}
