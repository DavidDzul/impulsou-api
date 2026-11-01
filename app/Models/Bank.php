<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Bank catalog entry. `code` is the 3-digit Banxico code (CLABE prefix).
 * `is_system` marks the locked batch bank (BBVA, 012); it is never mass
 * assignable so clients cannot set it.
 */
class Bank extends Model
{
    use HasFactory;

    public const BATCH_BANK_CODE = '012';

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    /**
     * True when any payment data points at this bank. Guarded by hasColumn so
     * the catalog works before scholarship_payment_data.bank_id exists.
     */
    public function isReferenced(): bool
    {
        if (!Schema::hasColumn('scholarship_payment_data', 'bank_id')) {
            return false;
        }

        return ScholarshipPaymentData::where('bank_id', $this->id)->exists();
    }
}
