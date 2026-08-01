<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'stored_path',
        'uploaded_by',
        'status',
        'total_rows',
        'last_row_offset',
        'imported_rows',
        'duplicate_count',
        'error_count',
        'error_report_path',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Emails whose FIRST-seen batch was this one — see DECISIONS.md "Dedup scope". */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'batch_id');
    }
}
