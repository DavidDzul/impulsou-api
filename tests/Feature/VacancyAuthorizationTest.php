<?php

namespace Tests\Feature;

use App\Models\VacantPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\VacancyFixtures;
use Tests\TestCase;

/**
 * Vacancy by-id endpoints and gates: 401 -> gate 403 -> scope 404 -> 422 -> act.
 */
class VacancyAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use VacancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVacancyFixtures();
    }

    public function byIdRequests(): array
    {
        return [
            'updateVacant' => ['updateVacant', ['vacant_name' => 'Hacked']],
            'updatePractice' => ['updatePractice', ['vacant_name' => 'Hacked']],
            'updateVacantJr' => ['updateVacantJr', ['vacant_name' => 'Hacked']],
            'status' => ['status', ['candidate_type' => 'INTERNAL']],
            'reset' => ['reset', []],
        ];
    }

    // ---- scope on by-id endpoints ----

    /**
     * @test
     * @dataProvider byIdRequests
     */
    public function by_id_endpoints_are_404_on_a_vacancy_of_another_campus_and_change_nothing(string $route, array $payload): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');
        $before = $this->vacB->fresh()->toArray();

        $this->putJson("{$this->vacanciesUri}/{$this->vacB->id}/{$route}", $payload)->assertNotFound();

        $this->assertSame($before, $this->vacB->fresh()->toArray());
    }

    /** @test */
    public function show_is_404_on_a_vacancy_of_another_campus(): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');

        $this->getJson("{$this->vacanciesUri}/{$this->vacB->id}")->assertNotFound();
        $this->getJson("{$this->vacanciesUri}/{$this->vacA->id}")->assertOk()->assertJsonPath('vacant.id', $this->vacA->id);
    }

    /** @test */
    public function update_is_404_before_422_on_an_out_of_scope_vacancy(): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');

        $this->putJson("{$this->vacanciesUri}/{$this->vacB->id}/updateVacant", ['campus' => 'NOT_A_CAMPUS'])->assertNotFound();
        $this->putJson("{$this->vacanciesUri}/{$this->vacB->id}/status", [])->assertNotFound();
    }

    /** @test */
    public function own_campus_vacancy_updates_succeed(): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');

        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/updateVacant", ['vacant_name' => 'Renamed'])->assertOk();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/status", ['candidate_type' => 'INTERNAL'])->assertOk();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/reset")->assertOk();

        $this->assertSame('Renamed', $this->vacA->fresh()->vacant_name);
    }

    /** @test */
    public function update_cannot_reassign_the_owner_to_a_business_of_another_campus(): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');

        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/updateVacant", ['user_id' => $this->bizB->id, 'vacant_name' => 'X'])
            ->assertOk();

        $this->assertSame($this->bizA->id, $this->vacA->fresh()->user_id);
    }

    // ---- route surface ----

    /** @test */
    public function unused_resource_verbs_are_not_2xx(): void
    {
        $this->actor('ROOT', 'MERIDA');

        foreach ([
            $this->postJson($this->vacanciesUri, []),
            $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}", []),
            $this->patchJson("{$this->vacanciesUri}/{$this->vacA->id}", []),
            $this->deleteJson("{$this->vacanciesUri}/{$this->vacA->id}"),
        ] as $response) {
            $this->assertFalse($response->isSuccessful());
        }
        $this->assertNotNull(VacantPosition::find($this->vacA->id));
    }

    /** @test */
    public function non_numeric_id_is_404(): void
    {
        $this->actor('ROOT', 'MERIDA');

        $this->getJson("{$this->vacanciesUri}/1abc")->assertNotFound();
        $this->putJson("{$this->vacanciesUri}/1abc/status", ['candidate_type' => 'INTERNAL'])->assertNotFound();
        $this->postJson("{$this->vacanciesUri}/1abc/storeVacant", [])->assertNotFound();
    }

    // ---- gates ----

    /** @test */
    public function unauthenticated_gets_401(): void
    {
        $this->getJson($this->vacanciesUri)->assertUnauthorized();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/status", [])->assertUnauthorized();
    }

    /** @test */
    public function root_keeps_access_with_ps_vacant_permissions_revoked(): void
    {
        $role = Role::findByName('ROOT');
        foreach ($role->permissions->where('name', 'like', 'PS\_%VACANT') as $permission) {
            $role->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor('ROOT', 'MERIDA');

        $this->getJson($this->vacanciesUri)->assertOk();
        $this->getJson("{$this->vacanciesUri}/{$this->vacB->id}")->assertOk();
        $this->putJson("{$this->vacanciesUri}/{$this->vacB->id}/updateVacant", ['vacant_name' => 'Ok'])->assertOk();
        $this->postJson("{$this->vacanciesUri}/{$this->bizB->id}/storeVacant", $this->storeBody('storeVacant'))->assertOk();
    }

    public function forbiddenEverywhere(): array
    {
        return ['ROOT_ADMINISTRATION' => ['ROOT_ADMINISTRATION'], 'ATTENDANCE' => ['ATTENDANCE'], 'ADMIN_STUDENT' => ['ADMIN_STUDENT']];
    }

    /**
     * @test
     * @dataProvider forbiddenEverywhere
     */
    public function roles_without_vacancy_permissions_get_403_on_every_route(string $role): void
    {
        $this->actor($role, 'VALLADOLID');
        $before = $this->vacA->fresh()->toArray();

        $this->getJson($this->vacanciesUri)->assertForbidden();
        $this->getJson("{$this->vacanciesUri}/{$this->vacA->id}")->assertForbidden();
        $this->postJson("{$this->vacanciesUri}/{$this->bizA->id}/storeVacant", $this->storeBody('storeVacant'))->assertForbidden();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/updateVacant", ['vacant_name' => 'X'])->assertForbidden();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/status", ['candidate_type' => 'INTERNAL'])->assertForbidden();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/reset")->assertForbidden();

        $this->assertSame($before, $this->vacA->fresh()->toArray());
    }

    /** @test */
    public function root_administration_is_forbidden_on_the_index_even_from_the_central_campus(): void
    {
        $this->actor('ROOT_ADMINISTRATION', 'MERIDA');

        $this->getJson($this->vacanciesUri)->assertForbidden();
    }

    /** @test */
    public function yucatan_can_read_but_not_write(): void
    {
        $this->actor('YUCATAN', 'VALLADOLID');

        $this->getJson($this->vacanciesUri)->assertOk();
        $this->getJson("{$this->vacanciesUri}/{$this->vacA->id}")->assertOk();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/updateVacant", ['vacant_name' => 'X'])->assertForbidden();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/status", ['candidate_type' => 'INTERNAL'])->assertForbidden();
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/reset")->assertForbidden();
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function yucatan_cannot_store(string $route): void
    {
        $this->actor('YUCATAN', 'VALLADOLID');
        $before = VacantPosition::count();

        $this->postJson("{$this->vacanciesUri}/{$this->bizA->id}/{$route}", $this->storeBody($route))->assertForbidden();

        $this->assertSame($before, VacantPosition::count());
    }

    /** @test */
    public function the_gate_runs_before_the_scope(): void
    {
        $this->actor('YUCATAN', 'VALLADOLID');

        // 403 (gate), not 404 (scope), for an out-of-scope id.
        $this->putJson("{$this->vacanciesUri}/{$this->vacB->id}/status", ['candidate_type' => 'INTERNAL'])->assertForbidden();
    }

    /** @test */
    public function root_job_keeps_global_read_and_write(): void
    {
        $this->actor('ROOT_JOB', 'VALLADOLID');

        $this->getJson("{$this->vacanciesUri}/{$this->vacB->id}")->assertOk();
        $this->putJson("{$this->vacanciesUri}/{$this->vacB->id}/updateVacant", ['vacant_name' => 'RJ'])->assertOk();
    }
}
