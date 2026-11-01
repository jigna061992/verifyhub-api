<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletLedger extends Model
{
    // The table only has created_at (no updated_at), ledger rows are never edited
    public $timestamps = false;

    protected $fillable = ['wallet_id', 'reference', 'type', 'amount', 'balance_after', 'description'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
