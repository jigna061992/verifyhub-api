<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationService extends Model
{
    protected $fillable = ['code', 'name', 'provider', 'enabled', 'default_price', 'provider_cost'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    // All checks done with this service (used for usage and success rate)
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class, 'service_id');
    }
}
