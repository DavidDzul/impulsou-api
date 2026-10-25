<?php

use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/pago-cheque, design D1/D2/D6.
 *
 * `payment_method` (string(20), default TRANSFER, same pattern as
 * batch_type) + `cheque_beneficiary`; `bank_name`/`account_number` become
 * nullable because CHEQUE rows carry no bank data. Existing rows become
 * TRANSFER through the column default. Named class so the companion test
 * can require_once it (see 2026_10_12_100000).
 */
class AddPaymentMethodToScholarshipPaymentData extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_payment_data', function (Blueprint $table) {
            $table->string('payment_method', 20)
                ->default(PaymentMethod::TRANSFER->value)
                ->after('user_id');
            $table->string('cheque_beneficiary', 150)->nullable()->after('rfc');
        });

        Schema::table('scholarship_payment_data', function (Blueprint $table) {
            $table->string('bank_name', 100)->nullable()->change();
            $table->string('account_number', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows that cannot satisfy NOT NULL (CHEQUE, or without bank data)
        // are deleted first; accepted because existing data is test data.
        DB::table('scholarship_payment_data')
            ->where('payment_method', PaymentMethod::CHEQUE->value)
            ->orWhereNull('bank_name')
            ->orWhereNull('account_number')
            ->delete();

        Schema::table('scholarship_payment_data', function (Blueprint $table) {
            $table->string('bank_name', 100)->nullable(false)->change();
            $table->string('account_number', 20)->nullable(false)->change();
        });

        Schema::table('scholarship_payment_data', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'cheque_beneficiary']);
        });
    }
}
