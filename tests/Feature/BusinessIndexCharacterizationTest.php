<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserBusinessMap;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Characterizes who sees which businesses in GET api/admin/business.
 * Mirrors staging: ROOT_JOB lives in VALLADOLID, ROOT in MERIDA.
 */
class BusinessIndexCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $bizMerida;
    private User $bizValladolid;
    private User $bizTizimin;
    private User $notBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->bizMerida = $this->makeUser('BUSINESS', 'MERIDA');
        $this->bizValladolid = $this->makeUser('BUSINESS', 'VALLADOLID');
        $this->bizTizimin = $this->makeUser('BUSINESS', 'TIZIMIN');
        $this->notBusiness = $this->makeUser('ADMIN', 'MERIDA');
    }

    private function makeUser(string $type, string $campus, ?string $role = null): User
    {
        $user = User::factory()->create(['user_type' => $type, 'campus' => $campus, 'active' => true]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function listedIdsFor(User $actor): array
    {
        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/admin/business');
        $response->assertOk()->assertJsonPath('res', true);

        return collect($response->json('business'))->pluck('id')->sort()->values()->all();
    }

    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    /** @test */
    public function root_sees_all_businesses(): void
    {
        $root = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');

        $this->assertSame(
            $this->ids($this->bizMerida, $this->bizValladolid, $this->bizTizimin),
            $this->listedIdsFor($root)
        );
    }

    /** @test */
    public function root_job_in_another_campus_sees_all_businesses(): void
    {
        $rootJob = $this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_JOB');

        $this->assertSame(
            $this->ids($this->bizMerida, $this->bizValladolid, $this->bizTizimin),
            $this->listedIdsFor($rootJob)
        );
    }

    /** @test */
    public function root_campus_sees_only_own_campus(): void
    {
        $staff = $this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_CAMPUS');

        $this->assertSame($this->ids($this->bizValladolid), $this->listedIdsFor($staff));
    }

    /** @test */
    public function merida_root_campus_sees_only_merida(): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->assertSame($this->ids($this->bizMerida), $this->listedIdsFor($staff));
    }

    /** @test */
    public function merida_root_campus_with_view_all_campuses_sees_all_campuses(): void
    {
        // Intended change introduced with the permission: the only behaviour difference.
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');
        $staff->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->assertSame(
            $this->ids($this->bizMerida, $this->bizValladolid, $this->bizTizimin),
            $this->listedIdsFor($staff)
        );
    }

    /** @test */
    public function admin_job_sees_only_own_campus(): void
    {
        $staff = $this->makeUser('ADMIN', 'TIZIMIN', 'ADMIN_JOB');

        $this->assertSame($this->ids($this->bizTizimin), $this->listedIdsFor($staff));
    }

    /** @test */
    public function yucatan_sees_only_mapped_businesses_regardless_of_campus(): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'YUCATAN');
        UserBusinessMap::create(['user_id' => $staff->id, 'business_id' => $this->bizValladolid->id]);

        $this->assertSame($this->ids($this->bizValladolid), $this->listedIdsFor($staff));
    }

    /** @test */
    public function yucatan_without_mappings_sees_nothing(): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'YUCATAN');

        $this->assertSame([], $this->listedIdsFor($staff));
    }

    /** @test */
    public function non_business_users_are_never_listed(): void
    {
        $root = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');
        $campusStaff = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');

        $this->assertNotContains($this->notBusiness->id, $this->listedIdsFor($root));
        $this->assertNotContains($this->notBusiness->id, $this->listedIdsFor($campusStaff));
    }

    /** @test */
    public function payload_shape_exposes_business_data_and_single_role(): void
    {
        $root = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');
        $this->bizMerida->assignRole('GOLD');
        $this->bizMerida->businessData()->create([
            'bs_name' => 'Acme', 'bs_director' => 'D', 'bs_rfc' => 'R', 'bs_country' => 'C',
            'bs_state' => 'S', 'bs_locality' => 'L', 'bs_adrress' => 'A', 'bs_telphone' => 'T',
            'bs_line' => 'X', 'bs_description' => 'desc',
        ]);

        Sanctum::actingAs($root);
        $row = collect($this->getJson('/api/admin/business')->assertOk()->json('business'))
            ->firstWhere('id', $this->bizMerida->id);

        $this->assertSame('Acme', $row['business_data']['bs_name'] ?? $row['businessData']['bs_name']);
        $this->assertSame('GOLD', $row['role']['name']);
        $this->assertArrayNotHasKey('roles', $row);
    }
}
