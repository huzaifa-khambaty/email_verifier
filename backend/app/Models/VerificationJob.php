<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationJob extends Model
{
    use HasFactory;

    /** Statuses per the original v1 plan's mapping table, carried into v2. */
    public const STATUSES = [
        'PENDING', 'PROCESSING', 'VALID', 'INVALID',
        'NO_MX', 'CATCH_ALL', 'UNKNOWN', 'TEMP_FAILURE',
    ];

    /** Terminal, "settled" results — the only ones that stop the retry loop. */
    public const TERMINAL_STATUSES = ['VALID', 'INVALID', 'NO_MX', 'CATCH_ALL', 'UNKNOWN'];

    protected $fillable = [
        'email_id',
        'status',
        'smtp_code',
        'smtp_response',
        'mx_host',
        'attempts',
        'next_attempt_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function smtpLogs(): HasMany
    {
        return $this->hasMany(SmtpLog::class);
    }
}
