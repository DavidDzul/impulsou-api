<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipRefrendLog extends Model
{
    protected $table = 'scholarship_refrend_logs';

    protected $fillable = [
        'scholarship_refrend_id',
        'action',
        'notes',
        'old_values',
        'new_values',
        'performed_by_id',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'scholarship_refrend_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}
