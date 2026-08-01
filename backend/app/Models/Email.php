<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Email extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'domain_id',
        'batch_id',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** The FIRST batch this address was ever seen in — see DECISIONS.md "Dedup scope". */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function verificationJobs(): HasMany
    {
        return $this->hasMany(VerificationJob::class);
    }

    /** Most recent verification attempt — treated as this email's "current" status. */
    public function latestVerificationJob(): HasOne
    {
        return $this->hasOne(VerificationJob::class)->latestOfMany();
    }
}
