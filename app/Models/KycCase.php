<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycCase extends Model
{
    protected $fillable = [
        'reference', 'retailer_id', 'service_id', 'status', 'current_step',
        'customer_name', 'customer_mobile', 'customer_mobile_last4',
        'price', 'provider_cost', 'failure_reason', 'expires_at', 'completed_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_name'   => 'encrypted',
            'customer_mobile' => 'encrypted',
            'price'           => 'decimal:2',
            'provider_cost'   => 'decimal:2',
            'expires_at'      => 'datetime',
            'completed_at'    => 'datetime',
            'closed_at'       => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(KycStep::class, 'case_id')->orderBy('id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(KycConsent::class, 'case_id')->orderBy('id');
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(VerificationService::class, 'service_id');
    }
}
