<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BankControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM_MSG = 'BBVA es un banco del sistema y no se puede eliminar, desactivar ni cambiar su código.';

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function noPermUser(): User
    {
        return User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function bbva(): Bank
    {
        return Bank::where('code', '012')->firstOrFail();
    }

    // ── Seed ─────────────────────────────────────────────────────────────

    /** @test */
    public function migration_seeds_bbva_as_active_system_bank_and_main_banxico_banks(): void
    {
        $bbva = $this->bbva();

        $this->assertSame('BBVA', $bbva->name);
        $this->assertTrue($bbva->is_system);
        $this->assertTrue($bbva->is_active);
        $this->assertSame(1, Bank::where('is_system', true)->count());
        $this->assertGreaterThanOrEqual(12, Bank::count());
        $this->assertSame('Banorte', Bank::where('code', '072')->value('name'));
    }

    // ── index ────────────────────────────────────────────────────────────

    /** @test */
    public function index_lists_banks_ordered_by_name_for_reader(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/banks');

        $response->assertStatus(200)->assertJsonPath('res', true);
        $names = collect($response->json('banks'))->pluck('name')->all();
        $this->assertContains('BBVA', $names);
        $sorted = $names;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names);
    }

    /** @test */
    public function index_requires_adm_read_banks(): void
    {
        $this->actingAs($this->noPermUser())->getJson('/api/admin/banks')->assertStatus(403);
    }

    // ── store ────────────────────────────────────────────────────────────

    /** @test */
    public function store_creates_an_active_non_system_bank(): void
    {
        $response = $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/banks', ['code' => '999', 'name' => 'Banco Nuevo']);

        $response->assertStatus(201)->assertJsonPath('bank.code', '999');
        $this->assertDatabaseHas('banks', ['code' => '999', 'is_active' => true, 'is_system' => false]);
    }

    /** @test */
    public function store_ignores_is_system_from_the_client(): void
    {
        $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/banks', ['code' => '998', 'name' => 'Otro', 'is_system' => true])
            ->assertStatus(201);

        $this->assertDatabaseHas('banks', ['code' => '998', 'is_system' => false]);
    }

    /** @test */
    public function store_rejects_duplicate_code(): void
    {
        $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/banks', ['code' => '012', 'name' => 'Distinto'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'El código ya está registrado.');
    }

    /** @test */
    public function store_rejects_duplicate_name_case_insensitively(): void
    {
        $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/banks', ['code' => '997', 'name' => 'bbva'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'El nombre ya está registrado.');
    }

    /** @test */
    public function store_requires_exactly_three_digit_code(): void
    {
        foreach (['12', '0123', 'abc', '01a'] as $code) {
            $this->actingAs($this->rootAdmin)
                ->postJson('/api/admin/banks', ['code' => $code, 'name' => 'X'.$code])
                ->assertStatus(422)
                ->assertJsonValidationErrors('code');
        }
    }

    /** @test */
    public function store_requires_manage_permission(): void
    {
        $this->actingAs($this->noPermUser())
            ->postJson('/api/admin/banks', ['code' => '999', 'name' => 'Nuevo'])
            ->assertStatus(403);
    }

    // ── update ───────────────────────────────────────────────────────────

    /** @test */
    public function update_edits_name_of_a_regular_bank(): void
    {
        $bank = Bank::where('code', '072')->firstOrFail();

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bank->id}", ['code' => '072', 'name' => 'Banorte SA'])
            ->assertStatus(200)
            ->assertJsonPath('bank.name', 'Banorte SA');
    }

    /** @test */
    public function update_can_deactivate_and_reactivate_a_regular_bank(): void
    {
        $bank = Bank::where('code', '072')->firstOrFail();

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bank->id}", ['code' => '072', 'name' => $bank->name, 'is_active' => false])
            ->assertStatus(200);
        $this->assertFalse($bank->fresh()->is_active);

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bank->id}", ['code' => '072', 'name' => $bank->name, 'is_active' => true])
            ->assertStatus(200);
        $this->assertTrue($bank->fresh()->is_active);
    }

    /** @test */
    public function update_allows_renaming_the_system_bank(): void
    {
        $bbva = $this->bbva();

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bbva->id}", ['code' => '012', 'name' => 'BBVA México'])
            ->assertStatus(200);

        $this->assertSame('BBVA México', $bbva->fresh()->name);
    }

    /** @test */
    public function update_rejects_changing_code_or_deactivating_the_system_bank(): void
    {
        $bbva = $this->bbva();

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bbva->id}", ['code' => '013', 'name' => 'BBVA'])
            ->assertStatus(422)
            ->assertJsonPath('msg', self::SYSTEM_MSG);

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bbva->id}", ['code' => '012', 'name' => 'BBVA', 'is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('msg', self::SYSTEM_MSG);

        $fresh = $bbva->fresh();
        $this->assertSame('012', $fresh->code);
        $this->assertTrue($fresh->is_active);
    }

    /** @test */
    public function update_unique_rules_ignore_the_bank_itself(): void
    {
        $bank = Bank::where('code', '072')->firstOrFail();

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bank->id}", ['code' => '072', 'name' => $bank->name])
            ->assertStatus(200);

        $this->actingAs($this->rootAdmin)
            ->putJson("/api/admin/banks/{$bank->id}", ['code' => '012', 'name' => $bank->name])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'El código ya está registrado.');
    }

    /** @test */
    public function update_requires_manage_permission(): void
    {
        $bank = Bank::where('code', '072')->firstOrFail();

        $this->actingAs($this->noPermUser())
            ->putJson("/api/admin/banks/{$bank->id}", ['code' => '072', 'name' => 'X'])
            ->assertStatus(403);
    }

    // ── destroy ──────────────────────────────────────────────────────────

    /** @test */
    public function destroy_removes_an_unreferenced_non_system_bank(): void
    {
        $bank = Bank::create(['code' => '999', 'name' => 'Temporal']);

        $this->actingAs($this->rootAdmin)
            ->deleteJson("/api/admin/banks/{$bank->id}")
            ->assertStatus(200)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('banks', ['id' => $bank->id]);
    }

    /** @test */
    public function destroy_rejects_the_system_bank(): void
    {
        $this->actingAs($this->rootAdmin)
            ->deleteJson('/api/admin/banks/'.$this->bbva()->id)
            ->assertStatus(422)
            ->assertJsonPath('msg', self::SYSTEM_MSG);

        $this->assertDatabaseHas('banks', ['code' => '012']);
    }

    /** @test */
    public function destroy_deactivates_instead_of_deleting_when_referenced_by_payment_data(): void
    {
        $bank = Bank::where('code', '072')->firstOrFail();
        $user = User::factory()->create();
        DB::table('scholarship_payment_data')->insert([
            'user_id' => $user->id,
            'bank_name' => 'Banorte',
            'account_number' => '072000000000000000',
            'curp' => 'ABCD010101HDFRRL09',
            'bank_id' => $bank->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->rootAdmin)
            ->deleteJson("/api/admin/banks/{$bank->id}")
            ->assertStatus(200)
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('bank.is_active', false);

        $this->assertDatabaseHas('banks', ['id' => $bank->id, 'is_active' => false]);
    }

    /** @test */
    public function destroy_requires_manage_permission(): void
    {
        $bank = Bank::create(['code' => '999', 'name' => 'Temporal']);

        $this->actingAs($this->noPermUser())->deleteJson("/api/admin/banks/{$bank->id}")->assertStatus(403);
    }

    // ── permissions seeding ──────────────────────────────────────────────

    /** @test */
    public function bank_permissions_are_granted_to_root_administration_only(): void
    {
        $granted = $this->rootAdmin->getAllPermissions()->pluck('name');
        $this->assertTrue($granted->contains('ADM_READ_BANKS'));
        $this->assertTrue($granted->contains('ADM_MANAGE_BANKS'));
        $this->assertSame(
            ['ROOT_ADMINISTRATION'],
            Permission::findByName('ADM_MANAGE_BANKS')->roles->pluck('name')->all()
        );
    }
}
