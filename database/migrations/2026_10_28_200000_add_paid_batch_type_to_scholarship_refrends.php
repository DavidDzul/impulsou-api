<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/cert-unid-historial PR1: a cheque payment freezes the refrend's
 * segment (NORMAL | CERT_UNID) so a later flip of the becario's flag cannot
 * move an already-paid cheque between tabs. NULL = not frozen (unpaid,
 * batch-paid — those follow payment_batch_id — or a legacy row).
 *
 * VARCHAR(20) + enum cast, like paid_payment_method: adding a segment later
 * does not need an ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->string('paid_batch_type', 20)->nullable()->after('payment_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn('paid_batch_type');
        });
    }
};
