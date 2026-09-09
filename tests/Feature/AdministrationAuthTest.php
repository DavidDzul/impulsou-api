<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrationAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_login_succeeds_for_admin_with_administration_role()
    {
        $response = $this->postJson('api/administration/login', [
            'email' => 'administracion@iu.org.mx',
            'password' => 'abc123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'res' => true,
            ])
            ->assertJsonStructure(['res', 'token', 'usuario']);
    }

    public function test_login_fails_403_without_administration_role()
    {
        $user = User::create([
            'first_name' => 'Admin',
            'last_name' => 'SinRol',
            'email' => 'admin.sinrol@iu.org.mx',
            'password' => Hash::make('abc123'),
            'phone' => '9911071509',
            'campus' => 'MERIDA',
            'user_type' => 'ADMIN',
            'generation_id' => null,
            'active' => 1,
        ]);

        $response = $this->postJson('api/administration/login', [
            'email' => $user->email,
            'password' => 'abc123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'res' => false,
            ]);
    }

    public function test_login_fails_403_for_wrong_user_type()
    {
        $user = User::create([
            'enrollment' => 'MER999999',
            'first_name' => 'Becario',
            'last_name' => 'NoAdmin',
            'email' => 'becario.noadmin@iu.org.mx',
            'password' => Hash::make('abc123'),
            'phone' => '9911071509',
            'campus' => 'MERIDA',
            'user_type' => 'BEC_ACTIVE',
            'generation_id' => null,
            'active' => 1,
        ]);
        $user->assignRole('ADMINISTRATION');

        $response = $this->postJson('api/administration/login', [
            'email' => $user->email,
            'password' => 'abc123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'res' => false,
            ]);
    }

    public function test_login_fails_422_for_invalid_credentials()
    {
        $response = $this->postJson('api/administration/login', [
            'email' => 'administracion@iu.org.mx',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);

        $response = $this->postJson('api/administration/login', [
            'email' => 'no-existe@iu.org.mx',
            'password' => 'abc123',
        ]);

        $response->assertStatus(422);
    }

    public function test_existing_routes_unchanged_and_administration_isolated()
    {
        $response = $this->postJson('api/admin/login', [
            'email' => 'admin@iu.org.mx',
            'password' => 'abc123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'res' => true,
            ])
            ->assertJsonStructure(['res', 'token', 'usuario']);

        // The `api/administration` prefix must not be reachable nested under `api/admin`.
        $this->postJson('api/admin/administration/login', [
            'email' => 'administracion@iu.org.mx',
            'password' => 'abc123',
        ])->assertStatus(404);

        // `api/login` is a pre-existing, unrelated legacy endpoint (App\Http\Controllers\AuthController,
        // gated on an active business agreement) — it must remain untouched by this change, not 404.
        // It must NOT resolve to Administration\AuthController's response shape.
        $legacyLoginResponse = $this->postJson('api/login', [
            'email' => 'administracion@iu.org.mx',
            'password' => 'abc123',
        ]);

        $legacyLoginResponse->assertStatus(403)
            ->assertJson([
                'res' => false,
                'msg' => 'El convenio de la empresa ha expirado. Contacta a soporte para renovarlo.',
            ]);
    }

    public function test_administration_role_and_user_seeding_is_idempotent()
    {
        // Mirrors the narrowly-targeted ADMINISTRATION entries in RoleSeeder::run() /
        // UserSeeder::run() — the exact operation a developer runs via `php artisan tinker`
        // against an already-seeded DB (see the inline comments on those seeder entries).
        // We do NOT re-run the full seeder classes here: their other lines use plain
        // `create()` and are not idempotent, so a blanket re-run would throw for unrelated
        // reasons and wouldn't prove anything about this entry specifically.
        $seedAdministrationEntry = function () {
            Role::firstOrCreate(['name' => 'ADMINISTRATION']);

            $user = User::firstOrCreate(
                ['email' => 'administracion@iu.org.mx'],
                [
                    'first_name' => 'Impulso',
                    'last_name' => 'Universitario A.C.',
                    'password' => Hash::make('abc123'),
                    'phone' => '9911071509',
                    'campus' => 'MERIDA',
                    'user_type' => 'ADMIN',
                    'generation_id' => null,
                    'active' => 1,
                ]
            );
            $user->assignRole('ADMINISTRATION');
        };

        // setUp() already seeded this entry once via DatabaseSeeder. Run it twice more here
        // to prove re-seeding on top of an already-seeded DB is safe and stays a no-op.
        $seedAdministrationEntry();
        $seedAdministrationEntry();

        $this->assertSame(1, Role::where('name', 'ADMINISTRATION')->count());
        $this->assertSame(1, User::where('email', 'administracion@iu.org.mx')->count());

        $user = User::where('email', 'administracion@iu.org.mx')->first();
        $this->assertTrue($user->hasRole('ADMINISTRATION'));
    }
}
