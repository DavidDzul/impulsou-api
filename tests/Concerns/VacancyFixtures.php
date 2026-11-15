<?php

namespace Tests\Concerns;

use App\Models\BusinessData;
use App\Models\RoleConfiguration;
use App\Models\User;
use App\Models\VacantPosition;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * Shared fixtures for the vacancy authorization tests.
 * "A" = VALLADOLID (actor campus), "B" = TIZIMIN (other campus).
 */
trait VacancyFixtures
{
    private string $vacanciesUri = '/api/admin/vacantPositions';

    private User $bizA;
    private User $bizB;
    private User $becario;
    private User $staffTarget;
    private VacantPosition $vacA;
    private VacantPosition $vacB;

    private function seedVacancyFixtures(): void
    {
        $this->seed(RoleSeeder::class);

        RoleConfiguration::firstOrCreate(['role_id' => Role::findByName('BASIC')->id]);

        $this->bizA = $this->makeBusiness('VALLADOLID');
        $this->bizB = $this->makeBusiness('TIZIMIN');
        $this->becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'VALLADOLID']);
        $this->staffTarget = User::factory()->create(['user_type' => 'ADMIN', 'campus' => 'VALLADOLID']);
        $this->staffTarget->assignRole('ADMIN_JOB');
        $this->vacA = $this->makeVacancy($this->bizA);
        $this->vacB = $this->makeVacancy($this->bizB);
    }

    private function makeBusiness(string $campus): User
    {
        $user = User::factory()->create(['user_type' => 'BUSINESS', 'campus' => $campus, 'active' => true]);
        $user->assignRole('BASIC');
        BusinessData::create([
            'user_id' => $user->id, 'bs_name' => "Biz {$user->id}", 'bs_director' => 'D', 'bs_rfc' => 'ABC010101AB1',
            'bs_country' => 'Mexico', 'bs_state' => 'Yucatan', 'bs_locality' => 'Merida', 'bs_adrress' => 'Calle 1',
            'bs_telphone' => '9991234567', 'bs_line' => 'Tech', 'bs_description' => 'desc',
        ]);

        return $user;
    }

    private function makeVacancy(User $owner, ?string $campus = null): VacantPosition
    {
        return VacantPosition::create([
            'user_id' => $owner->id, 'vacant_name' => "Vacancy of {$owner->id}", 'mode' => 'IN_PERSON',
            'category' => 'JOB_POSITION', 'activities' => 'a', 'study_profile' => 's',
            'start_day' => 'Monday', 'end_day' => 'Friday', 'start_hour' => '9', 'start_minute' => '00',
            'end_hour' => '17', 'end_minute' => '00', 'skills' => 'x', 'campus' => $campus ?? $owner->campus,
            'status' => true,
        ]);
    }

    private function actor(string $role, string $campus = 'VALLADOLID'): User
    {
        $actor = User::factory()->create(['user_type' => 'ADMIN', 'campus' => $campus, 'active' => true]);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function listedIds(): array
    {
        $response = $this->getJson($this->vacanciesUri);
        $response->assertOk();

        return collect($response->json('positions'))->pluck('id')->sort()->values()->all();
    }

    private function storeBody(string $route): array
    {
        $base = [
            'vacant_name' => 'New', 'category' => 'JOB_POSITION', 'activities' => 'a', 'study_profile' => 's',
            'start_day' => 'Monday', 'end_day' => 'Friday', 'start_hour' => '9', 'start_minute' => '00',
            'end_hour' => '17', 'end_minute' => '00', 'skills' => 'x', 'mode' => 'IN_PERSON',
        ];

        return match ($route) {
            'storeVacant' => $base + ['net_salary' => '1000'],
            'storePractice' => $base + ['semester' => '5'],
            default => $base + ['net_salary' => '1000', 'semester' => '5'],
        };
    }

    public function storeRoutes(): array
    {
        return ['storeVacant' => ['storeVacant'], 'storePractice' => ['storePractice'], 'storeVacantJr' => ['storeVacantJr']];
    }
}
