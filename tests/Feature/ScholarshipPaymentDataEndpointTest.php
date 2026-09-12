<?php

namespace Tests\Feature;

use App\Models\ScholarshipPaymentData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers design obs #1583 (D4/D5) + spec obs #1582 (R2/R3): the HTTP surface
 * that makes `api/admin/scholarship-payment-data` live, permission-gated via
 * the `permission` middleware alias registered in Kernel.php (D4 blocker),
 * backed by App\Http\Middleware\CheckPermission (see its docblock for a
 * second, independently-discovered blocker: Spatie's own
 * PermissionMiddleware does not correctly recognize role-granted
 * permissions in this spatie/laravel-permission version).
 */
class ScholarshipPaymentDataEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function becario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'active'    => true,
        ]);
    }

    // ── Kernel alias / permission enforcement (D4 verified blocker) ────────

    /** @test */
    public function unauthenticated_request_gets_401_not_500(): void
    {
        $becario = $this->becario();

        $response = $this->getJson("/api/admin/scholarship-payment-data/{$becario->id}");

        $response->assertStatus(401);
    }

    /** @test */
    public function caller_without_read_permission_gets_clean_403_not_500_on_show(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $becario    = $this->becario();

        $response = $this->actingAs($noPermUser)->getJson("/api/admin/scholarship-payment-data/{$becario->id}");

        // The Kernel `permission` alias must resolve to Spatie's middleware
        // and reject cleanly. If the alias is missing, Laravel throws
        // "Target class [permission] does not exist" which surfaces as a
        // 500, not a 403 — asserting the exact status catches that failure
        // mode instead of a false-positive on "any non-200".
        $response->assertStatus(403);
        $response->assertJsonMissing(['res' => true]);
    }

    /** @test */
    public function caller_without_edit_permission_gets_clean_403_not_500_on_store(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $becario    = $this->becario();

        $response = $this->actingAs($noPermUser)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function caller_without_edit_permission_gets_clean_403_not_500_on_update(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $becario    = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($noPermUser)->putJson(
            "/api/admin/scholarship-payment-data/{$becario->id}",
            ['bank_name' => 'Santander']
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function caller_with_only_read_permission_cannot_write(): void
    {
        $readOnlyUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $readOnlyUser->givePermissionTo('ADM_READ_PAYMENT_DATA');
        $becario = $this->becario();

        $response = $this->actingAs($readOnlyUser)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function caller_with_read_permission_gets_200_or_404_never_403(): void
    {
        $readOnlyUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $readOnlyUser->givePermissionTo('ADM_READ_PAYMENT_DATA');
        $becario = $this->becario();

        $response = $this->actingAs($readOnlyUser)->getJson("/api/admin/scholarship-payment-data/{$becario->id}");

        $response->assertStatus(404);
    }

    // ── show() ───────────────────────────────────────────────────────────

    /** @test */
    public function show_returns_404_when_no_row_exists(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->getJson("/api/admin/scholarship-payment-data/{$becario->id}");

        $response->assertStatus(404);
        $response->assertJson(['res' => false]);
    }

    /** @test */
    public function show_returns_200_with_data_when_row_exists(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create([
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
            'rfc'            => 'ABCD010101AB1',
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson("/api/admin/scholarship-payment-data/{$becario->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'res'  => true,
            'data' => [
                'bank_name'      => 'BBVA',
                'account_number' => '012345678901234567',
                'curp'           => 'ABCD010101HDFRRL09',
                'rfc'            => 'ABCD010101AB1',
            ],
        ]);
    }

    // ── store() ──────────────────────────────────────────────────────────

    /** @test */
    public function store_creates_a_new_row_and_returns_201(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['res' => true]);

        $this->assertDatabaseHas('scholarship_payment_data', [
            'user_id'   => $becario->id,
            'bank_name' => 'BBVA',
        ]);
    }

    /** @test */
    public function store_accepts_a_missing_rfc(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.rfc'));
    }

    /** @test */
    public function store_rejects_missing_required_fields_with_422(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payment-data', [
            'user_id' => $becario->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['bank_name', 'account_number', 'curp']);
    }

    /** @test */
    public function store_rejects_invalid_curp_format(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'too-short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('curp');
    }

    /** @test */
    public function store_rejects_invalid_rfc_format(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
            'rfc'            => 'bad',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rfc');
    }

    /** @test */
    public function store_rejects_a_second_row_for_the_same_user_cleanly(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payment-data', [
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');

        $this->assertSame(1, ScholarshipPaymentData::where('user_id', $becario->id)->count());
    }

    // ── update() ─────────────────────────────────────────────────────────

    /** @test */
    public function update_modifies_the_existing_row_in_place_and_returns_200(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create([
            'user_id'   => $becario->id,
            'bank_name' => 'BBVA',
        ]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-payment-data/{$becario->id}",
            ['bank_name' => 'Santander']
        );

        $response->assertStatus(200);
        $response->assertJson(['res' => true, 'data' => ['bank_name' => 'Santander']]);

        $this->assertSame(1, ScholarshipPaymentData::where('user_id', $becario->id)->count());
        $this->assertDatabaseHas('scholarship_payment_data', [
            'user_id'   => $becario->id,
            'bank_name' => 'Santander',
        ]);
    }

    /** @test */
    public function update_rejects_invalid_curp_format(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-payment-data/{$becario->id}",
            ['curp' => 'nope']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('curp');
    }
}
