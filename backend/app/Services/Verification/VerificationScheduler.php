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
    /**
     * Attempts before giving up when MySQL picks one of these
     * transactions as the deadlock victim. Ten workers claiming and
     * releasing concurrently — against tables a running import is also
     * writing to — makes deadlocks a normal contention outcome rather
     * than a fault, and they clear on retry.
     */
    private const DEADLOCK_RETRIES = 5;

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
        $claimed = $this->attemptClaim($limit);

        // An empty claim is the exact signal that slots may have leaked:
        // there is work outstanding but nothing is schedulable. Reaping
        // here rather than on a timer means recovery is automatic and
        // costs nothing while the queue is flowing normally.
        if ($claimed->isEmpty() && $this->reapStaleClaims() > 0) {
            $claimed = $this->attemptClaim($limit);
        }

        return $claimed;
    }

    /**
     * Releases connection slots and jobs stranded by a worker that died
     * mid-job.
     *
     * active_workers is incremented at claim time and decremented in
     * recordResult(). A worker killed between the two — which Supervisor
     * does on every deploy once stopwaitsecs elapses — leaves the counter
     * permanently raised, so the domain reads as busy forever and is
     * never scheduled again. Observed in production: a deploy stranded
     * gmail.com and a dozen other domains at 1/1 with 785 addresses
     * queued behind a slot nothing was using.
     *
     * The threshold must exceed the longest a real check can take (MX
     * fallback across several hosts, each with connect+read timeouts), so
     * a slow-but-live job is never reaped out from under itself.
     */
    public function reapStaleClaims(): int
    {
        $staleBefore = Carbon::now()->subMinutes($this->config['stale_claim_minutes'] ?? 10);

        // Jobs whose worker never came back: return them to the queue.
        // attempts is untouched — the check never completed, so it
        // shouldn't count against the retry budget.
        $jobs = DB::table('verification_jobs')
            ->where('status', 'PROCESSING')
            ->where('updated_at', '<', $staleBefore)
            ->update(['status' => 'PENDING', 'updated_at' => Carbon::now()]);

        $domains = DB::table('domains')
            ->where('active_workers', '>', 0)
            ->where(fn ($q) => $q
                ->whereNull('last_dispatched_at')
                ->orWhere('last_dispatched_at', '<', $staleBefore))
            ->update(['active_workers' => 0, 'updated_at' => Carbon::now()]);

        return $jobs + $domains;
    }

    /** @return Collection<int, VerificationJob> */
    private function attemptClaim(int $limit): Collection
    {
        // Ten workers claiming concurrently, against the same tables a
        // running import is writing to, means MySQL will occasionally
        // pick this transaction as the deadlock victim. Retrying is the
        // correct response — the alternative was an exception per
        // occurrence, filling the log and skipping a poll cycle.
        return DB::transaction(function () use ($limit) {
            $poolSize = max($limit * 10, 100);
            $now = Carbon::now();

            $query = DB::table('verification_jobs as vj')
                ->join('emails as e', 'e.id', '=', 'vj.email_id')
                ->join('domains as d', 'd.id', '=', 'e.domain_id')
                ->where('vj.status', 'PENDING')
                // Ignored domains ARE claimed, and bypass every throttle
                // below. The ignore decision is applied when a record is
                // processed rather than when it is imported, so adding a
                // domain to the list after an upload still settles the
                // addresses already queued against it.
                //
                // Bypassing the throttles is the point: those exist to
                // pace real SMTP connections, and an ignored record makes
                // none. Leaving them gated would mean marking ~4,500
                // Yahoo addresses as ignored took ten hours at 8s each,
                // which defeats the purpose of ignoring them.
                ->where(fn ($outer) => $outer
                    ->where('d.is_ignored', true)
                    ->orWhere(fn ($gated) => $gated
                        ->whereColumn('d.active_workers', '<', 'd.max_workers')
                        ->where(fn ($q) => $q->whereNull('d.cooling_down_until')->orWhere('d.cooling_down_until', '<', $now))
                        // Domains refusing verification traffic are skipped
                        // until the flag lapses, rather than dialled for a
                        // connection known to be dropped. Comparing against
                        // $now (not just NULL) is what makes the flag
                        // self-healing: once it expires the domain flows
                        // through again with no intervention.
                        ->where(fn ($q) => $q->whereNull('d.unresponsive_until')->orWhere('d.unresponsive_until', '<=', $now))
                        ->where(fn ($q) => $q->whereNull('vj.next_attempt_at')->orWhere('vj.next_attempt_at', '<=', $now))
                    )
                )
                ->orderByDesc('d.priority')
                ->orderBy('vj.id')
                ->limit($poolSize)
                ->select(
                    'vj.id as job_id',
                    'd.id as domain_id',
                    'd.is_ignored',
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

                // Ignored records cost no connection, so neither the
                // per-domain connection cap nor the inter-request delay
                // applies — both exist purely to pace real SMTP traffic.
                if ($row->is_ignored) {
                    $selectedJobIds[] = $row->job_id;
                    continue;
                }

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
        }, self::DEADLOCK_RETRIES);
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

            // Captured BEFORE the update, because update() mutates the
            // in-memory model too — reading the flag afterwards would
            // always report "already catch-all" and the drain below
            // would never fire.
            $wasAlreadyCatchAll = $domain->is_catch_all;
            $wasAlreadyUnresponsive = $domain->unresponsive_until !== null
                && $domain->unresponsive_until->isFuture();

            $this->trackCatchAll($domain, $result->status, $updates);
            $this->trackUnresponsive($domain, $result->status, $finalStatus, $updates);

            $domain->update($updates);

            // Draining the backlog runs once, on the transition to
            // confirmed, rather than on every subsequent result — it
            // touches every pending row for the domain.
            if (($updates['is_catch_all'] ?? false) && ! $wasAlreadyCatchAll) {
                $this->resolvePendingForCatchAllDomain($domain->id);
            }

            if (! empty($updates['unresponsive_until']) && ! $wasAlreadyUnresponsive) {
                $this->resolvePendingForUnresponsiveDomain($domain->id);
            }
        }, self::DEADLOCK_RETRIES);
    }

    /**
     * Accumulates evidence about whether a domain accepts every
     * recipient, mutating $updates in place.
     *
     * A CATCH_ALL result means our random-probe address was accepted, so
     * that's a confirmation. A VALID or INVALID result is proof of the
     * opposite — the server distinguished a real mailbox from a fake one
     * — so it wipes the count and clears any existing flag. That reset is
     * what stops a transient "accepting everything during an outage"
     * blip from permanently mislabelling a domain. Inconclusive outcomes
     * (TEMP_FAILURE, NO_MX, UNKNOWN) are ignored either way.
     */
    private function trackCatchAll(Domain $domain, string $status, array &$updates): void
    {
        if ($status === 'CATCH_ALL') {
            if ($domain->is_catch_all) {
                return; // already settled, nothing to prove
            }

            $confirmations = $domain->catch_all_detections + 1;
            $updates['catch_all_detections'] = $confirmations;

            if ($confirmations >= ($this->config['catch_all_confirmations'] ?? 3)) {
                $updates['is_catch_all'] = true;
                $updates['catch_all_confirmed_at'] = Carbon::now();
            }

            return;
        }

        if (in_array($status, ['VALID', 'INVALID'], true)) {
            $updates['catch_all_detections'] = 0;

            if ($domain->is_catch_all) {
                $updates['is_catch_all'] = false;
                $updates['catch_all_confirmed_at'] = null;
            }
        }
    }

    /**
     * Accumulates evidence that a domain refuses verification traffic
     * altogether, mutating $updates in place.
     *
     * Counts only addresses that exhausted EVERY retry ($finalStatus
     * settling as terminal TEMP_FAILURE), not individual transient
     * failures — a single timeout means nothing, but ten addresses each
     * failing five times in a row is a provider that won't talk to us.
     *
     * Any definitive result (VALID/INVALID/CATCH_ALL) proves the domain
     * does answer, so it zeroes the counter and lifts the flag
     * immediately. That's the same self-correcting shape as the
     * catch-all detection.
     */
    private function trackUnresponsive(Domain $domain, string $resultStatus, string $finalStatus, array &$updates): void
    {
        if (in_array($resultStatus, ['VALID', 'INVALID', 'CATCH_ALL'], true)) {
            $updates['exhausted_failures'] = 0;

            if ($domain->unresponsive_until !== null) {
                $updates['unresponsive_until'] = null;
            }

            return;
        }

        // Only a TEMP_FAILURE that has run out of retries counts — while
        // finalStatus is still PENDING the address has attempts left.
        if ($resultStatus !== 'TEMP_FAILURE' || $finalStatus !== 'TEMP_FAILURE') {
            return;
        }

        $exhausted = $domain->exhausted_failures + 1;
        $updates['exhausted_failures'] = $exhausted;

        $threshold = $this->config['unresponsive_threshold'] ?? 10;

        if ($exhausted >= $threshold) {
            $updates['unresponsive_until'] = Carbon::now()->addDays($this->config['unresponsive_days'] ?? 7);
        }
    }

    /**
     * Settles every still-pending address on an unresponsive domain
     * instead of letting each one grind through its full retry schedule.
     *
     * These are recorded as TEMP_FAILURE — the same terminal state they
     * would have reached anyway — with a response explaining that the
     * verdict came from the domain's record rather than from a
     * connection, so the result is never mistaken for a real SMTP reply.
     */
    public function resolvePendingForUnresponsiveDomain(int $domainId): int
    {
        $resolved = 0;

        do {
            $ids = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $domainId)
                ->where('verification_jobs.status', 'PENDING')
                ->limit(5000)
                ->pluck('verification_jobs.id');

            if ($ids->isEmpty()) {
                break;
            }

            $resolved += DB::table('verification_jobs')
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'TEMP_FAILURE',
                    'smtp_response' => 'Domain is refusing verification connections; settled without further retries.',
                    'next_attempt_at' => null,
                    'processed_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        } while ($ids->count() === 5000);

        return $resolved;
    }

    /**
     * Returns previously-ignored addresses to the queue when a domain is
     * taken off the ignore list, so the decision is fully reversible.
     *
     * processed_at is cleared as well — leaving a timestamp on something
     * that is about to be verified for the first time would make the
     * throughput charts and date-range filters lie.
     */
    public function restoreIgnoredDomain(int $domainId): int
    {
        return $this->bulkUpdatePending($domainId, 'IGNORED', [
            'status' => 'PENDING',
            'smtp_response' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * Chunked status rewrite for one domain, so a domain holding
     * millions of rows never takes a single enormous lock.
     */
    private function bulkUpdatePending(int $domainId, string $fromStatus, array $updates): int
    {
        $changed = 0;
        $updates['updated_at'] = Carbon::now();

        do {
            $ids = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $domainId)
                ->where('verification_jobs.status', $fromStatus)
                ->limit(5000)
                ->pluck('verification_jobs.id');

            if ($ids->isEmpty()) {
                break;
            }

            $changed += DB::table('verification_jobs')->whereIn('id', $ids)->update($updates);
        } while ($ids->count() === 5000);

        return $changed;
    }

    /**
     * Marks every still-pending address on a confirmed catch-all domain
     * without opening a connection for each one.
     *
     * This is the whole point of the feature: on the sample data,
     * yahoo.com was 419/419 catch-all, and at 6.5M scale that pattern
     * represents millions of SMTP conversations that can only ever
     * return the same answer. Chunked rather than one statement so a
     * domain with millions of rows doesn't hold a single enormous lock.
     */
    public function resolvePendingForCatchAllDomain(int $domainId): int
    {
        $resolved = 0;

        do {
            $ids = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $domainId)
                ->where('verification_jobs.status', 'PENDING')
                ->limit(5000)
                ->pluck('verification_jobs.id');

            if ($ids->isEmpty()) {
                break;
            }

            $resolved += DB::table('verification_jobs')
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'CATCH_ALL',
                    'smtp_response' => 'Domain confirmed catch-all; resolved without an individual SMTP check.',
                    'processed_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        } while ($ids->count() === 5000);

        return $resolved;
    }
}
