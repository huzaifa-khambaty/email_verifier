<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Verification\VerificationScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-domain verification breakdown and the operator ignore list.
 *
 * Exists so the decision "is this domain worth verifying?" can be made
 * from evidence on screen — a domain that is 100% catch-all costs a full
 * SMTP conversation per address and returns nothing usable, and at 6.5M
 * scale that choice is worth days of throughput.
 */
class DomainController extends Controller
{
    /** Domains with their status breakdown, worst-value first by default. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = DB::table('domains as d')
            ->leftJoin('emails as e', 'e.domain_id', '=', 'd.id')
            ->leftJoin('verification_jobs as vj', 'vj.email_id', '=', 'e.id')
            ->groupBy('d.id', 'd.name', 'd.is_ignored', 'd.ignored_at', 'd.is_catch_all',
                'd.unresponsive_until', 'd.cooling_down_until', 'd.delay_seconds', 'd.max_workers')
            ->select([
                'd.id',
                'd.name',
                'd.is_ignored',
                'd.ignored_at',
                'd.is_catch_all',
                'd.unresponsive_until',
                'd.cooling_down_until',
                'd.delay_seconds',
                'd.max_workers',
                DB::raw('COUNT(vj.id) as total'),
                DB::raw("SUM(vj.status IN ('PENDING','PROCESSING')) as pending"),
                DB::raw("SUM(vj.status = 'VALID') as valid"),
                DB::raw("SUM(vj.status = 'CATCH_ALL') as catch_all"),
                DB::raw("SUM(vj.status IN ('INVALID','NO_MX')) as invalid"),
                DB::raw("SUM(vj.status IN ('UNKNOWN','TEMP_FAILURE')) as unknown"),
                DB::raw("SUM(vj.status = 'IGNORED') as ignored"),
            ]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('d.name', 'like', '%'.$search.'%');
        }

        match ($request->query('filter')) {
            'ignored' => $query->where('d.is_ignored', true),
            'catch_all' => $query->where('d.is_catch_all', true),
            'problem' => $query->where(fn ($q) => $q
                ->where('d.is_catch_all', true)
                ->orWhereNotNull('d.unresponsive_until')),
            default => null,
        };

        // Biggest domains first: those are where an ignore decision
        // actually changes the workload.
        $query->orderByDesc(DB::raw('COUNT(vj.id)'));

        $domains = $query->paginate($perPage)->withQueryString();

        $domains->getCollection()->transform(function ($row) {
            $total = (int) $row->total;
            $catchAll = (int) $row->catch_all;
            $valid = (int) $row->valid;

            // Denominator is answers the server actually gave. UNKNOWN and
            // TEMP_FAILURE mean no answer arrived (timeout, dropped
            // connection, us being blocked) — counting those against the
            // domain reported "0% useful" in red for a domain we simply
            // never reached, which reads as "worthless" when the truth is
            // "unmeasured". The Unknown column and the Refusing badge
            // carry that signal instead.
            $answered = $catchAll + $valid + (int) $row->invalid;

            return [
                'id' => $row->id,
                'name' => $row->name,
                'total' => $total,
                'pending' => (int) $row->pending,
                'valid' => $valid,
                'catch_all' => $catchAll,
                'invalid' => (int) $row->invalid,
                'unknown' => (int) $row->unknown,
                'ignored' => (int) $row->ignored,
                'is_ignored' => (bool) $row->is_ignored,
                'is_catch_all' => (bool) $row->is_catch_all,
                'is_unresponsive' => $row->unresponsive_until !== null
                    && $row->unresponsive_until > now()->toDateTimeString(),
                'delay_seconds' => (int) $row->delay_seconds,
                'max_workers' => (int) $row->max_workers,
                // The number the decision hangs on: of the answers this
                // domain gave, how many were usable. 0% means every reply
                // it did give was worthless (all catch-all or invalid);
                // null means it has not answered yet at all.
                'useful_pct' => $answered > 0 ? (int) round($valid / $answered * 100) : null,
                // Rough cost of finishing it at the current per-domain rate.
                'hours_remaining' => $row->delay_seconds > 0 && $row->pending > 0
                    ? round((int) $row->pending / (60 / $row->delay_seconds * max(1, $row->max_workers)) / 60, 1)
                    : 0,
            ];
        });

        return response()->json($domains);
    }

    /** Adds or removes a domain from the ignore list. */
    public function update(Request $request, Domain $domain, VerificationScheduler $scheduler): JsonResponse
    {
        $validated = $request->validate([
            'is_ignored' => ['required', 'boolean'],
        ]);

        $ignore = $validated['is_ignored'];

        if ($ignore === $domain->is_ignored) {
            return response()->json([
                'domain' => $domain->name,
                'is_ignored' => $domain->is_ignored,
                'affected' => 0,
                'message' => 'No change.',
            ]);
        }

        $domain->update([
            'is_ignored' => $ignore,
            'ignored_at' => $ignore ? now() : null,
        ]);

        if ($ignore) {
            // Deliberately NOT rewritten here. Outstanding addresses stay
            // PENDING and are marked IGNORED by the worker as it reaches
            // each one, so the flag is read at processing time and always
            // reflects its current value — a domain ignored and then
            // un-ignored a minute later never churns statuses in between.
            // They bypass the connection throttles, so this drains quickly.
            $affected = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $domain->id)
                ->whereIn('verification_jobs.status', ['PENDING', 'PROCESSING'])
                ->count();

            $message = $affected > 0
                ? "{$domain->name} ignored; {$affected} queued address(es) will be marked ignored as they are processed."
                : "{$domain->name} ignored; new addresses on it will not be verified.";
        } else {
            // No worker path can undo an IGNORED row, so the reversal is
            // applied directly.
            $affected = $scheduler->restoreIgnoredDomain($domain->id);

            $message = "{$domain->name} restored; {$affected} address(es) returned to the queue.";
        }

        return response()->json([
            'domain' => $domain->name,
            'is_ignored' => $ignore,
            'affected' => $affected,
            'message' => $message,
        ]);
    }
}
