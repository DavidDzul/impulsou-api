<?php

namespace Tests\Feature;

use App\Models\RoleConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\VacancyFixtures;
use Tests\TestCase;

/**
 * general_knowledge accepts true/false/null (typo "nnullable" used to 500), and the store endpoints
 * answer 403 instead of 500 when the owner has no role or the role has no configuration.
 */
class VacancyStoreGuardsTest extends TestCase
{
    use RefreshDatabase;
    use VacancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVacancyFixtures();
        $this->actor('ROOT_JOB', 'VALLADOLID');
    }

    public function generalKnowledgeStoreCases(): array
    {
        $cases = [];
        foreach (['storeVacant', 'storeVacantJr'] as $route) {
            foreach (['true' => true, 'false' => false, 'null' => null] as $label => $value) {
                $cases["{$route} {$label}"] = [$route, $value];
            }
        }

        return $cases;
    }

    public function generalKnowledgeUpdateCases(): array
    {
        $cases = [];
        foreach (['updateVacant', 'updateVacantJr'] as $route) {
            foreach (['true' => true, 'false' => false, 'null' => null] as $label => $value) {
                $cases["{$route} {$label}"] = [$route, $value];
            }
        }

        return $cases;
    }

    /**
     * @test
     * @dataProvider generalKnowledgeStoreCases
     */
    public function general_knowledge_is_accepted_on_create(string $route, ?bool $value): void
    {
        $body = $this->storeBody($route) + ['general_knowledge' => $value];

        $this->postJson("{$this->vacanciesUri}/{$this->bizA->id}/{$route}", $body)
            ->assertOk()->assertJsonPath('res', true);
    }

    /**
     * @test
     * @dataProvider generalKnowledgeUpdateCases
     */
    public function general_knowledge_is_accepted_on_update(string $route, ?bool $value): void
    {
        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/{$route}", ['general_knowledge' => $value])
            ->assertOk();
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function owner_without_role_gets_403(string $route): void
    {
        $this->bizA->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson("{$this->vacanciesUri}/{$this->bizA->id}/{$route}", $this->storeBody($route))
            ->assertForbidden()->assertJsonPath('res', false);
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function owner_with_role_without_configuration_gets_403(string $route): void
    {
        RoleConfiguration::where('role_id', Role::findByName('BASIC', 'web')->id)->delete();

        $this->postJson("{$this->vacanciesUri}/{$this->bizA->id}/{$route}", $this->storeBody($route))
            ->assertForbidden()->assertJsonPath('res', false);
    }
}
