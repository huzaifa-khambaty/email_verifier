<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VerificationJob;
use App\Models\WorkerStatus;
use App\Services\Dashboard\InsightGenerator;
use App\Services\Dashboard\QueueDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Stat tiles from v2 §8. Response is flat JSON (no envelope), matching
     * the example in v2 §9. "Current status" per email = its latest
     * verification_jobs row, not every row ever created for it — a
     * re-verified email shouldn't count twice.
     */
    public function index(
        QueueDiagnostics $diagnostics,
        InsightGenerator $insights,
        \App\Services\Verification\ConnectionBudget $budget
    ): JsonResponse {
        $counts = DB::table('verification_jobs as vj')
            ->select('vj.status', DB::raw('count(*) as total'))
            ->whereRaw('vj.id = (select max(id) from verification_jobs where email_id = vj.email_id)')
            ->groupBy('vj.status')
            ->pluck('total', 'status');

        $processedLastFiveMinutes = VerificationJob::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', now()->subMinutes(5))
            ->count();

        // Counts workers that are ALIVE, not just mid-job. A worker with
        // nothing to claim sets itself to IDLE (see WorkCommand), so
        // filtering on RUNNING alone reported "Active workers: 0" while
        // all ten were up and heartbeating — which reads as "the system
        // is dead" rather than "the queue is empty". The heartbeat
        // recency check is what actually distinguishes alive from gone.
        $activeWorkers = WorkerStatus::query()
            ->whereIn('status', ['RUNNING', 'IDLE'])
            ->where('last_heartbeat_at', '>=', now()->subMinutes(2))
            ->count();

        $processedToday = VerificationJob::query()
            ->whereNotNull('processed_at')
            ->whereDate('processed_at', now()->toDateString())
            ->count();

        $stats = [
            'pending' => (int) ($counts['PENDING'] ?? 0),
            'processing' => (int) ($counts['PROCESSING'] ?? 0),
            'verified' => (int) ($counts['VALID'] ?? 0),
            'invalid' => (int) ($counts['INVALID'] ?? 0),
            'unknown' => (int) ($counts['UNKNOWN'] ?? 0),
            'catch_all' => (int) ($counts['CATCH_ALL'] ?? 0),
            'no_mx' => (int) ($counts['NO_MX'] ?? 0),
            'temp_failure' => (int) ($counts['TEMP_FAILURE'] ?? 0),
            'queue_speed_per_min' => intdiv($processedLastFiveMinutes, 5),
            'active_workers' => $activeWorkers,
            'processed_today' => $processedToday,
        ];

        // Diagnostics answer "why is anything still pending, and when
        // will it move" on screen, rather than requiring someone to read
        // the database to find out.
        $blockers = $diagnostics->blockers();
        $flags = $diagnostics->domainFlags();

        return response()->json($stats + [
            'total' => array_sum($counts->all()),
            'workers_expected' => WorkerStatus::count(),
            'blockers' => $blockers,
            'domain_flags' => $flags,
            'throughput' => $diagnostics->recentThroughput(),
            'insights' => $insights->generate($stats, $blockers, $flags),
            // Outbound allowance, so the rate being sent to mail providers
            // is visible rather than inferred — this is the figure quoted
            // to the hosting provider.
            'connection_budget' => [
                'used' => $budget->used(),
                'limit' => $budget->limit(),
                'remaining' => $budget->remaining(),
                'exhausted' => $budget->exhausted(),
                'resets_in_seconds' => $budget->secondsUntilReset(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
