<?php

namespace Tests\Feature;

use App\Models\BusinessData;
use App\Models\User;
use App\Models\VacantPosition;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Characterizes GET api/admin/vacantPositions on the unmodified code. Every vacancy's campus
 * equals its owner's campus, so the campus snapshot and the owner rule agree.
 * ROOT and ROOT_JOB see all; campus staff see their own campus.
 */
class VacancyIndexCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $bizMerida;
    private User $bizValladolid;
    private VacantPosition $vacMerida;
    private VacantPosition $vacValladolid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->bizMerida = $this->makeBusiness('MERIDA');
        $this->bizValladolid = $this->makeBusiness('VALLADOLID');
        $this->vacMerida = $this->makeVacancy($this->bizMerida);
        $this->vacValladolid = $this->makeVacancy($this->bizValladolid);
    }

    private function makeBusiness(string $campus): User
    {
        $user = User::factory()->create(['user_type' => 'BUSINESS', 'campus' => $campus, 'active' => true]);
        BusinessData::create([
            'user_id' => $user->id, 'bs_name' => "Biz {$user->id}", 'bs_director' => 'D', 'bs_rfc' => 'ABC010101AB1',
            'bs_country' => 'Mexico', 'bs_state' => 'Yucatan', 'bs_locality' => 'Merida', 'bs_adrress' => 'Calle 1',
            'bs_telphone' => '9991234567', 'bs_line' => 'Tech', 'bs_description' => 'desc',
        ]);

        return $user;
    }

    private function makeVacancy(User $owner): VacantPosition
    {
        return VacantPosition::create([
            'user_id' => $owner->id, 'vacant_name' => "Vacancy of {$owner->id}", 'mode' => 'IN_PERSON',
            'category' => 'JOB_POSITION', 'activities' => 'a', 'study_profile' => 's',
            'start_day' => 'Monday', 'end_day' => 'Friday', 'start_hour' => '9', 'start_minute' => '00',
            'end_hour' => '17', 'end_minute' => '00', 'skills' => 'x', 'campus' => $owner->campus, 'status' => true,
        ]);
    }

    private function listed(string $role, string $campus): array
    {
        $actor = User::factory()->create(['user_type' => 'ADMIN', 'campus' => $campus, 'active' => true]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $response = $this->getJson('/api/admin/vacantPositions');
        $response->assertOk()->assertJsonPath('res', true);

        return collect($response->json('positions'))->pluck('id')->sort()->values()->all();
    }

    private function ids(VacantPosition ...$vacancies): array
    {
        return collect($vacancies)->pluck('id')->sort()->values()->all();
    }

    /** @test */
    public function root_sees_all_vacancies(): void
    {
        $this->assertSame($this->ids($this->vacMerida, $this->vacValladolid), $this->listed('ROOT', 'MERIDA'));
    }

    /** @test */
    public function root_job_sees_all_vacancies_from_any_campus(): void
    {
        $this->assertSame($this->ids($this->vacMerida, $this->vacValladolid), $this->listed('ROOT_JOB', 'VALLADOLID'));
    }

    /** @test */
    public function root_campus_sees_own_campus_only(): void
    {
        $this->assertSame($this->ids($this->vacMerida), $this->listed('ROOT_CAMPUS', 'MERIDA'));
        $this->assertSame($this->ids($this->vacValladolid), $this->listed('ROOT_CAMPUS', 'VALLADOLID'));
    }

    /** @test */
    public function admin_job_sees_own_campus_only(): void
    {
        $this->assertSame($this->ids($this->vacMerida), $this->listed('ADMIN_JOB', 'MERIDA'));
        $this->assertSame($this->ids($this->vacValladolid), $this->listed('ADMIN_JOB', 'VALLADOLID'));
    }

    /** @test */
    public function response_shape_is_pinned(): void
    {
        $actor = User::factory()->create(['user_type' => 'ADMIN', 'campus' => 'MERIDA', 'active' => true]);
        $actor->assignRole('ROOT');
        Sanctum::actingAs($actor);

        $row = $this->getJson('/api/admin/vacantPositions')->assertOk()->json('positions.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'vacant_name', 'status', 'category', 'created_at', 'business'],
            array_keys($row)
        );
        $this->assertEqualsCanonicalizing(['id', 'user_id', 'bs_name'], array_keys($row['business']));
    }
}
