<?php

namespace App\Models;

use App\Enums\ScholarshipType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipProfile extends Model
{
    use HasFactory;

    protected $table = 'scholarship_profiles';

    protected $fillable = [
        'user_id',
        'scholarship_type',
        'monthly_amount',
        'monto_apoyo',
        'active_discount_percentage',
        'discount_reason',
        'discount_valid_until',
        'reticula_start_date',
        'reticula_end_date',
        'reticula_file_path',
        'reticula_original_name',
        // Legacy columns kept for backward compatibility with SQLite test migrations
        'payment_start_date',
        'payment_end_date',
    ];

    protected $casts = [
        'scholarship_type'           => ScholarshipType::class,
        'monthly_amount'             => 'decimal:2',
        'monto_apoyo'                => 'decimal:2',
        'active_discount_percentage' => 'decimal:2',
        'discount_valid_until'       => 'date',
        'reticula_start_date'        => 'date',
        'reticula_end_date'          => 'date',
    ];

    protected $appends = ['egreso_administrativo'];

    /**
     * Fecha límite administrativa: fin de retícula + 2 meses de gracia.
     * Null si no hay fecha de fin de retícula. No es columna de BD.
     */
    public function getEgresoAdministrativoAttribute(): ?string
    {
        return $this->reticula_end_date
            ? Carbon::parse($this->reticula_end_date)->addMonths(2)->toDateString()
            : null;
    }

    public static function createRules(): array
    {
        return [
            'user_id'                    => 'required|exists:users,id|unique:scholarship_profiles,user_id',
            'scholarship_type'           => 'required|string|in:IU,TELMEX',
            'monthly_amount'             => 'required|numeric|min:0',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_until'       => 'nullable|date',
        ];
    }

    public static function updateRules(int $id): array
    {
        return [
            'scholarship_type'           => 'sometimes|string|in:IU,TELMEX',
            'monthly_amount'             => 'sometimes|numeric|min:0',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_until'       => 'nullable|date',
        ];
    }

    // Relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function refrends(): HasMany
    {
        return $this->hasMany(ScholarshipRefrend::class, 'user_id', 'user_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->whereHas('user', fn($q) => $q->where('user_type', 'BEC_ACTIVE'));
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('scholarship_type', $type);
    }
}
