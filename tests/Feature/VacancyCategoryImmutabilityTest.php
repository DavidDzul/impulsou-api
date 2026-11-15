<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VacancyFixtures;
use Tests\TestCase;

/**
 * Security review M2: `category` is fixed at creation. Update endpoints ignore it, so a plain
 * vacancy cannot become JR_POSITION and skip the storeVacantJr tier/quota checks.
 */
class VacancyCategoryImmutabilityTest extends TestCase
{
    use RefreshDatabase;
    use VacancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVacancyFixtures();
    }

    public function updateRoutes(): array
    {
        return [
            'updateVacant' => ['updateVacant'],
            'updatePractice' => ['updatePractice'],
            'updateVacantJr' => ['updateVacantJr'],
        ];
    }

    /**
     * @test
     * @dataProvider updateRoutes
     */
    public function category_cannot_change_on_update(string $route): void
    {
        $this->actor('ROOT_JOB', 'VALLADOLID');

        $this->putJson("{$this->vacanciesUri}/{$this->vacA->id}/{$route}", ['category' => 'JR_POSITION', 'vacant_name' => 'Renamed'])
            ->assertOk();

        $fresh = $this->vacA->fresh();
        $this->assertSame('JOB_POSITION', $fresh->category);
        $this->assertSame('Renamed', $fresh->vacant_name);
    }
}
