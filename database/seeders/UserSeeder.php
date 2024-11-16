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
            "email" => "vinculacion@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "+529911071509",
            "campus" => "MERIDA",
            "user_type" => "BUSINESS",
            "active" => 1,
        ])->assignRole('DIAMOND');

        User::create([
            "first_name" => "David",
            "last_name" => "Fernando",
            "email" => "david@iu.org.mx",
            "password" => Hash::make("abc123"),
            "phone" => "+529911071509",
            "campus" => "MERIDA",
            "user_type" => "BEC_ACTIVE",
            "active" => 1,
        ]);
    }
}