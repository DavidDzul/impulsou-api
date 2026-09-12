<?php

namespace Database\Seeders;

use Dotenv\Util\Str;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "vinculacion.laboral@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "BUSINESS",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('DIAMOND');

        User::create([
            "enrollment" => "MER170209",
            "first_name" => "David",
            "last_name" => "Fernando",
            "email" => "david.dzul@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "BEC_ACTIVE",
            "generation_id" => null,
            "active" => 1,
        ]);

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "admin@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('ROOT');

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "campus@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('ROOT_CAMPUS');

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "students@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('ADMIN_STUDENT');

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "root_jobs@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('ROOT_JOB');

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "jobs@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('ADMIN_JOB');

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "yucatan@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911071509",
            "campus" => "MERIDA",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('YUCATAN');

        // This entry uses firstOrCreate so it is safe to seed onto an already-populated DB
        // (e.g. via `php artisan tinker`, targeting only this block). The rest of this seeder
        // class is NOT idempotent — do not run the full class via `db:seed` on a non-fresh DB.
        $administrationUser = User::firstOrCreate(
            ["email" => "administracion@iu.org.mx"],
            [
                "first_name" => "Impulso",
                "last_name" => "Universitario A.C.",
                "password" => Hash::make("abc123"),
                "phone" => "9911071509",
                "campus" => "MERIDA",
                "user_type" => "ADMIN",
                "generation_id" => null,
                "active" => 1,
            ]
        );
        // NOTE: renamed from 'ADMINISTRATION' to 'ROOT_ADMINISTRATION' alongside RoleSeeder.
        // This only affects fresh seeds. Any already-seeded/production database that ran the
        // old seeder has a real `administracion@iu.org.mx` user still assigned to the OLD
        // 'ADMINISTRATION' role row, which now carries zero permissions (all grants moved to
        // ROOT_ADMINISTRATION). `firstOrCreate` on the role name creates a NEW row rather than
        // renaming the old one in place, so that live assignment will NOT be picked up
        // automatically — see apply-progress risk log for the required follow-up reassignment.
        $administrationUser->assignRole('ROOT_ADMINISTRATION');
    }
}
