<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Verification extends Model
{
    protected $fillable = [
        'retailer_id', 'service_id', 'reference', 'status', 'stage', 'input_masked',
        'result', 'meta', 'provider_ref', 'attempts', 'otp_sends', 'otp_sent_at', 'expires_at',
        'failure_reason', 'price', 'provider_cost',
    ];

    protected function casts(): array
    {
        return [
            'result'        => 'encrypted:array',
            'meta'          => 'encrypted:array',
            'price'         => 'decimal:2',
            'provider_cost' => 'decimal:2',
            'otp_sent_at'   => 'datetime',
            'expires_at'    => 'datetime',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(VerificationService::class, 'service_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(VerificationConsent::class)->orderBy('id');
    }
}
