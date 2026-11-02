<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RazorpayPayment extends Model
{
    protected $fillable = [
        'retailer_id', 'order_id', 'payment_id', 'amount', 'amount_paise', 'currency', 'mode',
        'status', 'last_error', 'settled_via', 'topup_request_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'amount_paise' => 'integer',
            'paid_at'      => 'datetime',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function topup(): BelongsTo
    {
        return $this->belongsTo(WalletTopupRequest::class, 'topup_request_id');
    }
}
