<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A check whose provider answer is unknown (timeout). The money stays on hold until an admin resolves it.
class Reconciliation extends Model
{
    protected $fillable = [
        'verification_id', 'retailer_id', 'service_id', 'amount', 'status', 'reason',
        'outcome', 'note', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'resolved_at' => 'datetime'];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(VerificationService::class, 'service_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
