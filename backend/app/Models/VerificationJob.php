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
        'NO_MX', 'CATCH_ALL', 'UNKNOWN', 'TEMP_FAILURE', 'IGNORED',
    ];

    /** Terminal, "settled" results — the only ones that stop the retry loop. */
    public const TERMINAL_STATUSES = ['VALID', 'INVALID', 'NO_MX', 'CATCH_ALL', 'UNKNOWN'];

    /**
     * User-facing buckets for the Email List filters and stat cards. The
     * engine has 8 statuses, which is more granularity than is useful as
     * a filter, so they collapse into these groups.
     *
     * Every status belongs to exactly one group, so the group counts
     * always reconcile to the total — deliberately, so nothing is
     * invisible. CATCH_ALL in particular is its own group rather than
     * being folded into "valid": the domain accepts every address, so a
     * 250 there is not evidence that this specific mailbox exists, and
     * quietly counting it as deliverable would overstate the good list
     * (it's ~20% of a typical import).
     *
     * @var array<string, string[]>
     */
    public const STATUS_GROUPS = [
        'pending' => ['PENDING', 'PROCESSING'],
        'valid' => ['VALID'],
        'catch_all' => ['CATCH_ALL'],
        'invalid' => ['INVALID', 'NO_MX'],
        'unknown' => ['UNKNOWN', 'TEMP_FAILURE'],
        'ignored' => ['IGNORED'],
    ];

    /**
     * @return string[] the raw statuses a filter group covers, or [] for
     *                  "all" / an unrecognized group
     */
    public static function statusesForGroup(?string $group): array
    {
        return self::STATUS_GROUPS[$group] ?? [];
    }

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
