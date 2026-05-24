<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSnapshotFieldsToScholarshipRefrends extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('average_grade_snapshot', 5, 2)->nullable()->after('snapshot_scholarship_type');
            $table->unsignedSmallInteger('missing_subjects_snapshot')->default(0)->after('average_grade_snapshot');
            $table->json('attendance_summary_snapshot')->nullable()->after('missing_subjects_snapshot');
        });
    }

    public function down()
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn(['average_grade_snapshot', 'missing_subjects_snapshot', 'attendance_summary_snapshot']);
        });
    }
}
