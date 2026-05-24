<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDiffColumnsToScholarshipRefrendLogs extends Migration
{
    public function up()
    {
        Schema::table('scholarship_refrend_logs', function (Blueprint $table) {
            $table->json('old_values')->nullable()->after('action');
            $table->json('new_values')->nullable()->after('old_values');
        });
    }

    public function down()
    {
        Schema::table('scholarship_refrend_logs', function (Blueprint $table) {
            $table->dropColumn(['old_values', 'new_values']);
        });
    }
}
