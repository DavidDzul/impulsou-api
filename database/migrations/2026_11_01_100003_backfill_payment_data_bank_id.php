<?php

use App\Models\Bank;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * sdd/catalogo-bancos R4: link legacy payment data to the BBVA bank when the
 * free-text bank_name is a BBVA/Bancomer variant (case, accent, spacing and
 * punctuation insensitive). Anything else keeps bank_id NULL and is surfaced as
 * "falta seleccionar banco". Idempotent: only TRANSFER rows without bank_id are
 * touched, bank_name is never modified. Done in PHP to stay DB-agnostic.
 */
return new class extends Migration
{
    private const ALIASES = ['BBVA', 'BANCOMER'];

    public function up(): void
    {
        $bbvaId = DB::table('banks')->where('code', Bank::BATCH_BANK_CODE)->value('id');

        if ($bbvaId === null) {
            return;
        }

        DB::table('scholarship_payment_data')
            ->where('payment_method', 'TRANSFER')
            ->whereNull('bank_id')
            ->whereNotNull('bank_name')
            ->select(['id', 'bank_name'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($bbvaId) {
                $ids = [];

                foreach ($rows as $row) {
                    if ($this->isBbvaAlias((string) $row->bank_name)) {
                        $ids[] = $row->id;
                    }
                }

                if ($ids !== []) {
                    DB::table('scholarship_payment_data')->whereIn('id', $ids)->update(['bank_id' => $bbvaId]);
                }
            });
    }

    /** Data-only migration: nothing to undo (the column is dropped by its own migration). */
    public function down(): void
    {
    }

    private function isBbvaAlias(string $bankName): bool
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($bankName))) ?? '';

        foreach (self::ALIASES as $alias) {
            if (str_contains($normalized, $alias)) {
                return true;
            }
        }

        return false;
    }
};
