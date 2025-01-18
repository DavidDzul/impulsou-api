<?php

namespace Database\Seeders;

use App\Models\BusinessData;
use Illuminate\Database\Seeder;

class BusinessDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        BusinessData::create([
            "user_id" => "1",
            "bs_name" => "Impulso Universitario A.C.",
            "bs_director" => "Pilar Ibarra",
            "bs_rfc" => "IUN9901111M2",
            "bs_country" => "México",
            "bs_state" => "Yucatán",
            "bs_locality" => "Mérida",
            "bs_adrress" => "Calle 62 #383 por 47 y 45",
            "bs_telphone" => "9999289017",
            "bs_line" => "EDUCATIONAL",
            "bs_description" => "Somos una Asociación Civil fundada en 1999 en la ciudad de Mérida, Yucatán que nace de la comunión de ideas de un grupo de profesionales convencidos de que la educación con valores es un elemento importante para mejorar el desarrollo económico, social y cultural de un país, así como para lograr un ambiente de equidad y justicia para sus habitantes.",
            "bs_website" => "www.iu.org.mx"
        ]);
    }
}
