<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'priority',
        'delay_seconds',
        'max_workers',
        'active_workers',
        'last_dispatched_at',
        'consecutive_failures',
        'cooling_down_until',
    ];

    protected function casts(): array
    {
        return [
            'last_dispatched_at' => 'datetime',
            'cooling_down_until' => 'datetime',
        ];
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /** True while the circuit breaker (DECISIONS.md) has this domain paused. */
    public function isCoolingDown(): bool
    {
        return $this->cooling_down_until !== null && $this->cooling_down_until->isFuture();
    }
}
