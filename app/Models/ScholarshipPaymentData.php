<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipPaymentData extends Model
{
    use HasFactory;

    // Explicit table name required: Eloquent's default pluralization of
    // ScholarshipPaymentData resolves to `scholarship_payment_datas`, which
    // is wrong (design D1 / task 1a.3).
    protected $table = 'scholarship_payment_data';

    protected $attributes = [
        'payment_method' => 'TRANSFER',
    ];

    protected $casts = [
        'payment_method' => PaymentMethod::class,
    ];

    protected $fillable = [
        'user_id',
        'payment_method',
        'bank_name',
        'account_number',
        'curp',
        'rfc',
        'cheque_beneficiary',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
