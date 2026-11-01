<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = ['retailer_id', 'available_balance', 'reserved_balance', 'version'];

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(WalletLedger::class);
    }
}