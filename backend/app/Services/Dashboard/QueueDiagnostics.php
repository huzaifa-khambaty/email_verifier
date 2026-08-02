<?php

namespace App\Services\Dashboard;

use App\Models\Domain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Explains the state of the verification queue in the terms an operator
 * actually asks about: why is anything still pending, what is it waiting
 * on, and when will it move.
 *
 * This exists because those answers previously required reading the
 * database by hand. Everything here is derived from the same columns the
 * scheduler consults when deciding what to claim, so what the dashboard
 * reports is what the scheduler will actually do.
 */
class QueueDiagnostics
{
    /**
     * Per-domain breakdown of everything still pending, annotated with
     * the reason it isn't being worked and when that lifts.
     *
     * The reasons are evaluated in the same precedence the claim query
     * uses, so the one reported is the one that would actually block the
     * job first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function blockers(int $limit = 10): array
    {
        $now = Carbon::now();

        $rows = DB::table('verification_jobs as vj')
            ->join('emails as e', 'e.id', '=', 'vj.email_id')
            ->join('domains as d', 'd.id', '=', 'e.domain_id')
            ->whereIn('vj.status', ['PENDING', 'PROCESSING'])
            ->groupBy('d.id', 'd.name', 'd.cooling_down_until', 'd.unresponsive_until',
                'd.active_workers', 'd.max_workers', 'd.delay_seconds', 'd.is_catch_all')
            ->select([
                'd.name',
                'd.cooling_down_until',
                'd.unresponsive_until',
                'd.active_workers',
                'd.max_workers',
                'd.delay_seconds',
                'd.is_catch_all',
                DB::raw('COUNT(*) as pending'),
                DB::raw('MIN(vj.next_attempt_at) as earliest_retry'),
                DB::raw('MAX(vj.attempts) as max_attempts_used'),
            ])
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($now) {
            [$reason, $until] = $this->reasonFor($row, $now);

            return [
                'domain' => $row->name,
                'pending' => (int) $row->pending,
                'reason' => $reason,
                'until' => $until?->toIso8601String(),
                'seconds_until' => $until ? max(0, $now->diffInSeconds($until, false)) : null,
                'attempts_used' => (int) $row->max_attempts_used,
                'delay_seconds' => (int) $row->delay_seconds,
            ];
        })->all();
    }

    /**
     * @return array{0:string, 1:?Carbon}
     */
    private function reasonFor(object $row, Carbon $now): array
    {
        $unresponsive = $row->unresponsive_until ? Carbon::parse($row->unresponsive_until) : null;
        if ($unresponsive && $unresponsive->isAfter($now)) {
            return ['Domain is refusing connections — paused', $unresponsive];
        }

        $cooldown = $row->cooling_down_until ? Carbon::parse($row->cooling_down_until) : null;
        if ($cooldown && $cooldown->isAfter($now)) {
            return ['Cooling down after repeated failures', $cooldown];
        }

        $retry = $row->earliest_retry ? Carbon::parse($row->earliest_retry) : null;
        if ($retry && $retry->isAfter($now)) {
            return ['Waiting for retry backoff', $retry];
        }

        if ($row->active_workers >= $row->max_workers) {
            return ['At its connection limit ('.$row->active_workers.'/'.$row->max_workers.')', null];
        }

        return ['Ready — will be picked up shortly', null];
    }

    /**
     * Domains currently under some restriction, for an at-a-glance list
     * of what the engine is deliberately avoiding.
     *
     * @return array<string, mixed>
     */
    public function domainFlags(): array
    {
        $now = Carbon::now();

        $catchAll = Domain::where('is_catch_all', true)
            ->orderByDesc('catch_all_detections')
            ->limit(10)
            ->get(['name', 'catch_all_detections', 'catch_all_confirmed_at']);

        $unresponsive = Domain::whereNotNull('unresponsive_until')
            ->where('unresponsive_until', '>', $now)
            ->orderByDesc('exhausted_failures')
            ->limit(10)
            ->get(['name', 'exhausted_failures', 'unresponsive_until']);

        $cooling = Domain::whereNotNull('cooling_down_until')
            ->where('cooling_down_until', '>', $now)
            ->orderBy('cooling_down_until')
            ->limit(10)
            ->get(['name', 'consecutive_failures', 'cooling_down_until']);

        return [
            'catch_all' => $catchAll->map(fn ($d) => [
                'domain' => $d->name,
                'confirmations' => (int) $d->catch_all_detections,
                'confirmed_at' => $d->catch_all_confirmed_at?->toIso8601String(),
            ])->all(),
            'unresponsive' => $unresponsive->map(fn ($d) => [
                'domain' => $d->name,
                'failures' => (int) $d->exhausted_failures,
                'until' => $d->unresponsive_until?->toIso8601String(),
                'seconds_until' => $d->unresponsive_until
                    ? max(0, $now->diffInSeconds($d->unresponsive_until, false))
                    : null,
            ])->all(),
            'cooling_down' => $cooling->map(fn ($d) => [
                'domain' => $d->name,
                'failures' => (int) $d->consecutive_failures,
                'until' => $d->cooling_down_until?->toIso8601String(),
                'seconds_until' => $d->cooling_down_until
                    ? max(0, $now->diffInSeconds($d->cooling_down_until, false))
                    : null,
            ])->all(),
        ];
    }

    /**
     * Throughput over the last hour, per minute — enough to tell
     * "running slowly" apart from "not running", which was previously
     * impossible to distinguish from the dashboard.
     *
     * @return array<int, array{minute:string, count:int}>
     */
    public function recentThroughput(int $minutes = 60): array
    {
        return DB::table('verification_jobs')
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', Carbon::now()->subMinutes($minutes))
            ->groupBy('minute')
            ->orderBy('minute')
            ->select([
                DB::raw("DATE_FORMAT(processed_at, '%H:%i') as minute"),
                DB::raw('COUNT(*) as count'),
            ])
            ->get()
            ->map(fn ($r) => ['minute' => $r->minute, 'count' => (int) $r->count])
            ->all();
    }
}
