<?php

namespace Tests\Feature;

use App\Models\VacantPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\VacancyFixtures;
use Tests\TestCase;

/**
 * Index visibility and store* owner resolution follow the OWNER business, never the vacancy campus
 * column. Each index test below is an explicit, intended delta from VacancyIndexCharacterizationTest.
 */
class VacancyScopeTest extends TestCase
{
    use RefreshDatabase;
    use VacancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVacancyFixtures();
    }

    /** @test */
    public function yucatan_sees_own_campus_vacancies_only(): void
    {
        $this->actor('YUCATAN');

        $this->assertSame([$this->vacA->id], $this->listedIds());
    }

    /** @test */
    public function merida_holder_of_view_all_campuses_sees_every_vacancy(): void
    {
        $staff = $this->actor('ADMIN_JOB', 'MERIDA');
        $staff->givePermissionTo('PS_VIEW_ALL_CAMPUSES');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(
            collect([$this->vacA->id, $this->vacB->id])->sort()->values()->all(),
            $this->listedIds()
        );
    }

    /** @test */
    public function vacancy_of_a_business_that_moved_campus_follows_the_business(): void
    {
        // Snapshot says A, owner now lives in B.
        $moved = $this->makeVacancy($this->bizB, 'VALLADOLID');

        $this->actor('ADMIN_JOB', 'VALLADOLID');
        $this->assertSame([$this->vacA->id], $this->listedIds());

        $this->actor('ADMIN_JOB', 'TIZIMIN');
        $this->assertSame(
            collect([$this->vacB->id, $moved->id])->sort()->values()->all(),
            $this->listedIds()
        );
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function store_for_a_business_of_another_campus_is_404_and_creates_nothing(string $route): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');
        $before = VacantPosition::count();

        $this->postJson("{$this->vacanciesUri}/{$this->bizB->id}/{$route}", $this->storeBody($route))->assertNotFound();

        $this->assertSame($before, VacantPosition::count());
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function store_for_a_non_business_owner_is_404_even_for_root(string $route): void
    {
        $this->actor('ROOT', 'MERIDA');
        $before = VacantPosition::count();

        foreach ([$this->staffTarget, $this->becario] as $target) {
            $this->postJson("{$this->vacanciesUri}/{$target->id}/{$route}", $this->storeBody($route))->assertNotFound();
        }

        $this->assertSame($before, VacantPosition::count());
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function store_is_404_before_422(string $route): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');

        $this->postJson("{$this->vacanciesUri}/{$this->bizB->id}/{$route}", [])->assertNotFound();
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function store_for_an_own_campus_business_succeeds(string $route): void
    {
        $this->actor('ADMIN_JOB', 'VALLADOLID');

        $this->postJson("{$this->vacanciesUri}/{$this->bizA->id}/{$route}", $this->storeBody($route))->assertOk();

        $this->assertSame(2, VacantPosition::where('user_id', $this->bizA->id)->count());
    }

    /**
     * @test
     * @dataProvider storeRoutes
     */
    public function root_can_store_for_a_business_of_any_campus(string $route): void
    {
        $this->actor('ROOT', 'MERIDA');

        $this->postJson("{$this->vacanciesUri}/{$this->bizB->id}/{$route}", $this->storeBody($route))->assertOk();
    }
}
