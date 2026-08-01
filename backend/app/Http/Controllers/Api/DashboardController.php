<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VerificationJob;
use App\Models\WorkerStatus;
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
    public function index(): JsonResponse
    {
        $counts = DB::table('verification_jobs as vj')
            ->select('vj.status', DB::raw('count(*) as total'))
            ->whereRaw('vj.id = (select max(id) from verification_jobs where email_id = vj.email_id)')
            ->groupBy('vj.status')
            ->pluck('total', 'status');

        $processedLastFiveMinutes = VerificationJob::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', now()->subMinutes(5))
            ->count();

        $activeWorkers = WorkerStatus::query()
            ->where('status', 'RUNNING')
            ->where('last_heartbeat_at', '>=', now()->subMinutes(2))
            ->count();

        $processedToday = VerificationJob::query()
            ->whereNotNull('processed_at')
            ->whereDate('processed_at', now()->toDateString())
            ->count();

        return response()->json([
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
        ]);
    }
}
