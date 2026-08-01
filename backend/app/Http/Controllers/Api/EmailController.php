<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VerificationJob;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailController extends Controller
{
    /**
     * Emails are joined 1:1 to verification_jobs rather than resolving a
     * "latest job per email" subquery, because that relationship IS 1:1
     * today: CsvImportService inserts exactly one job per newly-seen
     * address, and VerificationScheduler::recordResult() UPDATEs that
     * same row on retry instead of inserting another. A correlated
     * "max(id) per email_id" subquery would be correct-but-slow over
     * millions of rows for no present benefit.
     *
     * If re-verification is ever added (which would insert a second job
     * for an existing email), this join starts returning duplicate rows
     * and every query in this controller needs a latest-job guard.
     */
    private function baseQuery(Request $request): BuilderContract
    {
        $query = DB::table('emails')
            ->join('verification_jobs', 'verification_jobs.email_id', '=', 'emails.id')
            ->leftJoin('domains', 'domains.id', '=', 'emails.domain_id');

        if ($batchId = $request->query('batch_id')) {
            $query->where('emails.batch_id', $batchId);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('emails.email', 'like', '%'.$search.'%');
        }

        return $query;
    }

    private function applyGroupFilter(BuilderContract $query, ?string $group): BuilderContract
    {
        $statuses = VerificationJob::statusesForGroup($group);

        if (! empty($statuses)) {
            $query->whereIn('verification_jobs.status', $statuses);
        }

        return $query;
    }

    /** Paginated email list with its current verification result. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $emails = $this->applyGroupFilter($this->baseQuery($request), $request->query('status'))
            ->orderBy('emails.id')
            ->select([
                'emails.id',
                'emails.email',
                'emails.first_name',
                'emails.last_name',
                'emails.batch_id',
                'domains.name as domain',
                'verification_jobs.status',
                'verification_jobs.smtp_code',
                'verification_jobs.mx_host',
                'verification_jobs.attempts',
                'verification_jobs.processed_at',
            ])
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($emails);
    }

    /**
     * Counts per filter group, for the stat cards. Returns every group
     * (zero-filled) so the UI can render a stable set of cards rather
     * than having them appear and disappear as data changes.
     */
    public function stats(Request $request): JsonResponse
    {
        $rawCounts = $this->baseQuery($request)
            ->select('verification_jobs.status', DB::raw('count(*) as total'))
            ->groupBy('verification_jobs.status')
            ->pluck('total', 'status');

        $stats = ['total' => (int) $rawCounts->sum()];

        foreach (VerificationJob::STATUS_GROUPS as $group => $statuses) {
            $stats[$group] = (int) collect($statuses)->sum(fn ($s) => $rawCounts[$s] ?? 0);
        }

        return response()->json($stats);
    }

    /**
     * CSV export honoring the same filters as index().
     *
     * Streamed and chunked rather than built in memory — an unfiltered
     * export at this project's target scale (~6.5M addresses) would
     * otherwise exhaust PHP's memory limit and 500 partway through.
     */
    public function export(Request $request): StreamedResponse
    {
        $group = $request->query('status');
        $query = $this->applyGroupFilter($this->baseQuery($request), $group)
            ->orderBy('emails.id')
            ->select([
                'emails.id',
                'emails.email',
                'emails.first_name',
                'emails.last_name',
                'domains.name as domain',
                'verification_jobs.status',
                'verification_jobs.smtp_code',
                'verification_jobs.mx_host',
                'verification_jobs.attempts',
                'verification_jobs.processed_at',
            ]);

        $filename = sprintf('emails-%s-%s.csv', $group ?: 'all', now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'Email', 'First Name', 'Last Name', 'Domain',
                'Status', 'SMTP Code', 'MX Host', 'Attempts', 'Processed At',
            ]);

            // chunkById on the joined query needs an unambiguous column.
            $query->chunkById(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->email,
                        $row->first_name,
                        $row->last_name,
                        $row->domain,
                        $row->status,
                        $row->smtp_code,
                        $row->mx_host,
                        $row->attempts,
                        $row->processed_at,
                    ]);
                }
                flush();
            }, 'emails.id', 'id');

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }
}
