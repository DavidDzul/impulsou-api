<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipRefrendDiscount extends Model
{
    use HasFactory;

    protected $table = 'scholarship_refrend_discounts';

    protected $fillable = [
        'scholarship_refrend_id',
        'discount_type',
        'discount_percentage',
        'description',
    ];

    protected $casts = [
        'discount_type'       => DiscountType::class,
        'discount_percentage' => 'decimal:2',
    ];

    // Relations

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'scholarship_refrend_id');
    }

    public function lateConsumptions(): HasMany
    {
        return $this->hasMany(ScholarshipLateConsumption::class, 'scholarship_refrend_discount_id');
    }
}
