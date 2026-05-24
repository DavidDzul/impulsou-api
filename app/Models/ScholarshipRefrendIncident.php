<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipRefrendIncident extends Model
{
    protected $table = 'scholarship_refrend_incidents';

    protected $fillable = [
        'scholarship_refrend_id',
        'incident_category',
        'incident_type',
        'incident_date',
        'description',
        'priority',
        'created_by_id',
        'is_resolved',
        'resolved_at',
        'resolved_by_id',
        'resolution_notes',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'is_resolved'   => 'boolean',
        'resolved_at'   => 'datetime',
    ];

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'scholarship_refrend_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
