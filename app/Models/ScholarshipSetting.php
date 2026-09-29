<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row (id=1) typed settings table (sdd/scholarship-telmex-iu-split,
 * design D8). `telmex_base_amount` is display-only reference data — it MUST
 * NEVER be read by buildSnapshot(), validation, or any auto-fill logic.
 */
class ScholarshipSetting extends Model
{
    protected $table = 'scholarship_settings';

    protected $fillable = [
        'telmex_base_amount',
    ];

    protected $casts = [
        'telmex_base_amount' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
