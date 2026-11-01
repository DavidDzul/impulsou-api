<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/catalogo-bancos R7: metadata of a transfer to a non-BBVA bank that is
 * paid manually. Same shape as the cheque columns, but a separate namespace
 * so reports and the uniqueness checks never mix cheques with transfers.
 */
class AddTransferColumnsToScholarshipRefrends extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->string('transfer_reference', 50)->nullable();
            $table->date('transfer_paid_at')->nullable();
            $table->string('transfer_notes', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn(['transfer_reference', 'transfer_paid_at', 'transfer_notes']);
        });
    }
}
