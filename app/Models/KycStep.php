<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycStep extends Model
{
    protected $fillable = [
        'case_id', 'step', 'status', 'attempts', 'otp_sends', 'otp_sent_at',
        'input_masked', 'result', 'meta', 'provider_ref', 'failure_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result'       => 'encrypted:array',
            'meta'         => 'encrypted:array',
            'otp_sent_at'  => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function kycCase(): BelongsTo
    {
        return $this->belongsTo(KycCase::class, 'case_id');
    }
}
