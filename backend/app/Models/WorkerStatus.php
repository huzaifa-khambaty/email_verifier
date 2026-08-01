<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerStatus extends Model
{
    // Table is singular ("worker_status", per v2 §7's table list), not
    // Eloquent's default pluralized guess ("worker_statuses").
    protected $table = 'worker_status';

    protected $fillable = [
        'worker_name',
        'pid',
        'status',
        'current_domain_id',
        'jobs_processed',
        'last_heartbeat_at',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
        ];
    }

    public function currentDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'current_domain_id');
    }
}
