<?php

namespace App\Services\Verification;

use App\Models\Domain;
use App\Models\SmtpLog;
use App\Models\VerificationJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "no Redis, no broker" claim/release mechanism from DECISIONS.md
 * ("Queue driver — no Redis, no broker"). MariaDB itself is the
 * coordination point: claimBatch() atomically hands a bounded batch of
 * eligible jobs to a worker process, honoring domain priority, per-domain
 * concurrency caps, throttle delay, and the circuit breaker; recordResult()
 * writes the outcome back and updates that same scheduling state.
 */
class VerificationScheduler
{
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Claims up to $limit PENDING jobs. Candidate pool is fetched with a
     * row lock (FOR UPDATE SKIP LOCKED on MySQL/MariaDB, so concurrent
     * verify:work processes never double-claim), then filtered in PHP for
     * the per-domain delay/capacity rules that aren't practical to express
     * portably across MySQL and SQLite in a single query.
     *
     * @return Collection<int, VerificationJob>
     */
    public function claimBatch(int $limit): Collection
    {
        return DB::transaction(function () use ($limit) {
            $poolSize = max($limit * 10, 100);
            $now = Carbon::now();

            $query = DB::table('verification_jobs as vj')
                ->join('emails as e', 'e.id', '=', 'vj.email_id')
                ->join('domains as d', 'd.id', '=', 'e.domain_id')
                ->where('vj.status', 'PENDING')
                ->whereColumn('d.active_workers', '<', 'd.max_workers')
                ->where(fn ($q) => $q->whereNull('d.cooling_down_until')->orWhere('d.cooling_down_until', '<', $now))
                ->where(fn ($q) => $q->whereNull('vj.next_attempt_at')->orWhere('vj.next_attempt_at', '<=', $now))
                ->orderByDesc('d.priority')
                ->orderBy('vj.id')
                ->limit($poolSize)
                ->select(
                    'vj.id as job_id',
                    'd.id as domain_id',
                    'd.delay_seconds',
                    'd.last_dispatched_at',
                    'd.max_workers',
                    'd.active_workers'
                );

            // SQLite has no row-level locking (the whole-DB write lock
            // taken by this transaction already serializes writers), and
            // no SKIP LOCKED — only apply the MySQL/MariaDB locking clause
            // there. See DECISIONS.md porting notes on driver differences.
            if (DB::getDriverName() === 'mysql') {
                $query->lock('for update skip locked');
            }

            $candidates = $query->get();

            $remainingSlots = [];
            $domainClaimCounts = [];
            $selectedJobIds = [];

            foreach ($candidates as $row) {
                if (count($selectedJobIds) >= $limit) {
                    break;
                }

                $domainId = $row->domain_id;
                $remainingSlots[$domainId] ??= $row->max_workers - $row->active_workers;
                if ($remainingSlots[$domainId] <= 0) {
                    continue;
                }

                if ($row->last_dispatched_at !== null) {
                    $eligibleAt = Carbon::parse($row->last_dispatched_at)->addSeconds($row->delay_seconds);
                    if ($eligibleAt->isFuture()) {
                        continue;
                    }
                }

                $selectedJobIds[] = $row->job_id;
                $remainingSlots[$domainId]--;
                $domainClaimCounts[$domainId] = ($domainClaimCounts[$domainId] ?? 0) + 1;
            }

            if (empty($selectedJobIds)) {
                return new Collection();
            }

            DB::table('verification_jobs')
                ->whereIn('id', $selectedJobIds)
                ->update(['status' => 'PROCESSING', 'updated_at' => $now]);

            foreach ($domainClaimCounts as $domainId => $count) {
                DB::table('domains')->where('id', $domainId)->update([
                    'active_workers' => DB::raw("active_workers + {$count}"),
                    'last_dispatched_at' => $now,
                ]);
            }

            return VerificationJob::with(['email.domain'])->whereIn('id', $selectedJobIds)->get();
        });
    }

    /**
     * Writes a verification outcome back: the job's final status (with
     * exponential-backoff retry scheduling for TEMP_FAILURE, per
     * DECISIONS.md), the full SMTP transcript, and the domain's
     * active_workers/circuit-breaker state.
     */
    public function recordResult(VerificationJob $job, VerificationResult $result): void
    {
        DB::transaction(function () use ($job, $result) {
            $attempts = $job->attempts + 1;
            $finalStatus = $result->status;
            $nextAttemptAt = null;

            if ($finalStatus === 'TEMP_FAILURE' && $attempts < $this->config['max_attempts']) {
                $finalStatus = 'PENDING';
                $backoff = $this->config['retry_backoff_minutes'];
                $minutes = $backoff[$attempts - 1] ?? end($backoff);
                $nextAttemptAt = Carbon::now()->addMinutes($minutes);
            }

            $job->update([
                'status' => $finalStatus,
                'smtp_code' => $result->smtpCode,
                'smtp_response' => $result->smtpResponse,
                'mx_host' => $result->mxHost,
                'attempts' => $attempts,
                'next_attempt_at' => $nextAttemptAt,
                'processed_at' => Carbon::now(),
            ]);

            foreach ($result->log as $entry) {
                SmtpLog::create([
                    'verification_job_id' => $job->id,
                    'stage' => $entry['stage'],
                    'mx_host' => $entry['mx_host'],
                    'smtp_code' => $entry['smtp_code'],
                    'message' => $entry['message'],
                ]);
            }

            // Lock the domain row for this update so concurrent workers
            // processing other jobs on the same domain don't lose an
            // active_workers decrement or a circuit-breaker increment.
            $domain = Domain::whereKey($job->email->domain_id)->lockForUpdate()->first();
            if ($domain === null) {
                return;
            }

            $updates = ['active_workers' => max(0, $domain->active_workers - 1)];

            if ($result->status === 'TEMP_FAILURE') {
                $consecutive = $domain->consecutive_failures + 1;
                if ($consecutive >= $this->config['circuit_breaker_threshold']) {
                    $updates['consecutive_failures'] = 0;
                    $updates['cooling_down_until'] = Carbon::now()->addMinutes($this->config['circuit_breaker_cooldown_minutes']);
                } else {
                    $updates['consecutive_failures'] = $consecutive;
                }
            } else {
                $updates['consecutive_failures'] = 0;
            }

            $domain->update($updates);
        });
    }
}
