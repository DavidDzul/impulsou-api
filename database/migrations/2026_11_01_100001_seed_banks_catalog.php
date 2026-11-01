<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * sdd/catalogo-bancos PR1: seed the main Banxico banks. BBVA (012) is the
 * locked system bank. Idempotent: existing codes are left untouched so edits
 * made through the CRUD survive a re-run.
 */
return new class extends Migration
{
    private const BANKS = [
        '002' => 'Banamex',
        '012' => 'BBVA',
        '014' => 'Santander',
        '021' => 'HSBC',
        '030' => 'BanBajío',
        '036' => 'Inbursa',
        '042' => 'Mifel',
        '044' => 'Scotiabank',
        '058' => 'Banregio',
        '062' => 'Afirme',
        '072' => 'Banorte',
        '127' => 'Azteca',
        '130' => 'Compartamos',
        '137' => 'BanCoppel',
        '638' => 'Nu',
        '646' => 'STP',
        '722' => 'Mercado Pago',
    ];

    private const SYSTEM_CODE = '012';

    public function up(): void
    {
        $now = now();

        foreach (self::BANKS as $code => $name) {
            if (DB::table('banks')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('banks')->insert([
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'is_system' => $code === self::SYSTEM_CODE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('banks')->whereIn('code', array_keys(self::BANKS))->delete();
    }
};
