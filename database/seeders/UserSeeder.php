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
    }
}
