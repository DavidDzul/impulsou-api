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
            "enrollment" => "MER240001",
            "first_name" => "Nallely",
            "last_name" => "Garrido",
            "email" => "nallely.garrido@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911011122",
            "campus" => "MERIDA",
            "user_type" => "BEC_ACTIVE",
            "generation_id" => null,
            "active" => 1,
        ]);

        User::create([
            "enrollment" => "MER240002",
            "first_name" => "María",
            "last_name" => "May",
            "email" => "maria.may@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "9911011122",
            "campus" => "MERIDA",
            "user_type" => "BEC_ACTIVE",
            "generation_id" => null,
            "active" => 1,
        ]);

        User::create([
            "first_name" => "Impulso",
            "last_name" => "Universitario A.C.",
            "email" => "root@iu.org.mx",
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
            "campus" => "VALLADOLID",
            "user_type" => "ADMIN",
            "generation_id" => null,
            "active" => 1,
        ])->assignRole('CAMPUS');

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