<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmtpLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'verification_job_id',
        'stage',
        'mx_host',
        'smtp_code',
        'message',
    ];

    public function verificationJob(): BelongsTo
    {
        return $this->belongsTo(VerificationJob::class);
    }
}
