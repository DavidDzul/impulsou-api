<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReticulaToScholarshipProfilesTable extends Migration
{
    public function up()
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->date('reticula_start_date')->nullable()->after('discount_valid_until');
            $table->date('reticula_end_date')->nullable()->after('reticula_start_date');
            $table->string('reticula_file_path')->nullable()->after('reticula_end_date');
            $table->string('reticula_original_name')->nullable()->after('reticula_file_path');
        });
    }

    public function down()
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'reticula_start_date',
                'reticula_end_date',
                'reticula_file_path',
                'reticula_original_name',
            ]);
        });
    }
}
