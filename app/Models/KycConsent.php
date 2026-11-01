<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Consent log rows are only created, never updated
class KycConsent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'case_id', 'type', 'purpose', 'text_version', 'text_hash', 'text', 'channel',
        'accepted_by', 'ip_address', 'user_agent', 'provider_ref', 'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'text'        => 'encrypted',
            'accepted_at' => 'datetime',
        ];
    }

    public function kycCase(): BelongsTo
    {
        return $this->belongsTo(KycCase::class, 'case_id');
    }
}
