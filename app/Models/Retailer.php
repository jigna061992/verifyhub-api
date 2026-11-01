<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Retailer extends Model
{
    protected $fillable = ['user_id', 'business_name', 'address', 'pan', 'gst', 'status'];

    // PAN and GST are stored encrypted in the database
    protected function casts(): array
    {
        return ['pan' => 'encrypted', 'gst' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }
}
