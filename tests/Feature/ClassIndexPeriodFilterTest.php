<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/clases-filtro-periodo-asignacion-manual, class-list-period-filter R1.1-R1.4.
 */
class ClassIndexPeriodFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true, 'campus' => 'MERIDA']);
        $this->generation = Generation::create(['generation_name' => 'G1', 'campus' => 'MERIDA', 'generation_active' => true]);

        $this->makeClass('Oct', '2026-10-15');
        $this->makeClass('Nov', '2026-11-20');
        $this->makeClass('Prev year', '2025-11-20');
    }

    private function makeClass(string $name, string $date): ClassModel
    {
        return ClassModel::create([
            'name' => $name,
            'date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'campus' => 'MERIDA',
            'generation_id' => $this->generation->id,
        ]);
    }

    private function list(array $extra = [])
    {
        $query = array_merge(['campus' => 'MERIDA', 'generation_id' => $this->generation->id], $extra);

        return $this->actingAs($this->admin)->getJson('/api/admin/class?' . http_build_query($query));
    }

    private function names($response): array
    {
        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    /** @test */
    public function year_and_month_return_only_that_month(): void
    {
        $response = $this->list(['year' => 2026, 'month' => 11])->assertOk();

        $this->assertSame(['Nov'], $this->names($response));
    }

    /** @test */
    public function year_only_returns_the_whole_year(): void
    {
        $response = $this->list(['year' => 2026])->assertOk();

        $this->assertSame(['Nov', 'Oct'], $this->names($response));
    }

    /** @test */
    public function no_params_returns_all_classes(): void
    {
        $response = $this->list()->assertOk();

        $this->assertSame(['Nov', 'Oct', 'Prev year'], $this->names($response));
    }

    /**
     * @test
     * @dataProvider invalidPeriods
     */
    public function invalid_period_params_are_rejected(array $params): void
    {
        $this->list($params)->assertStatus(422);
    }

    public function invalidPeriods(): array
    {
        return [
            'month without year' => [['month' => 11]],
            'month 13' => [['year' => 2026, 'month' => 13]],
            'month 0' => [['year' => 2026, 'month' => 0]],
            'year 1999' => [['year' => 1999]],
            'year 2101' => [['year' => 2101]],
        ];
    }
}
