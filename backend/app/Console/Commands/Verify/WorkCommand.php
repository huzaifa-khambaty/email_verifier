<?php

namespace App\Console\Commands\Verify;

use App\Models\Domain;
use App\Models\WorkerStatus;
use App\Services\Verification\SmtpEmailVerifier;
use App\Services\Verification\VerificationScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The DB-polling worker loop from DECISIONS.md ("Queue driver — no Redis,
 * no broker"): claim -> verify -> record, repeated indefinitely. Run N
 * copies of this under Supervisor (see backend/README.md) — the process
 * count *is* the global concurrency cap, no separate setting needed.
 *
 * `php artisan verify:work --name=verify-work-1`
 */
class WorkCommand extends Command
{
    protected $signature = 'verify:work
        {--name= : Worker identity for worker_status / Supervisor numprocs, e.g. verify-work-1}
        {--iterations=0 : Stop after N claim cycles instead of running forever (0 = forever; for testing)}
        {--idle-sleep=3 : Seconds to sleep after an empty claim before trying again}';

    protected $description = 'Long-running verification worker: claims PENDING jobs and verifies them via SMTP.';

    private bool $shouldStop = false;

    public function handle(SmtpEmailVerifier $verifier, VerificationScheduler $scheduler): int
    {
        $workerName = $this->option('name') ?: ('verify-work-'.getmypid());
        $idleSleep = (int) $this->option('idle-sleep');
        $iterations = (int) $this->option('iterations');
        $batchSize = (int) config('verifier.scheduler.claim_batch_size');

        // pcntl (and its SIGTERM/SIGINT constants) is POSIX-only — absent
        // on Windows dev machines, present on the Ubuntu VPS where this
        // actually runs under Supervisor. Skip gracefully where missing.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () {
                $this->shouldStop = true;
            });
        }

        $status = $this->upsertWorkerStatus($workerName, 'RUNNING', 0);
        $this->info("[{$workerName}] starting, batch size {$batchSize}.");

        $processed = 0;
        $loop = 0;

        while (! $this->shouldStop) {
            $loop++;

            $batch = $scheduler->claimBatch($batchSize);

            if ($batch->isEmpty()) {
                $this->heartbeat($status, 'IDLE', $processed);
                if ($iterations > 0 && $loop >= $iterations) {
                    break;
                }
                sleep($idleSleep);

                continue;
            }

            $this->heartbeat($status, 'RUNNING', $processed, $batch->first()->email->domain_id ?? null);

            foreach ($batch as $job) {
                if ($this->shouldStop) {
                    break;
                }

                $email = $job->email;
                $result = $verifier->verify($email->email, $email->domain->name);
                $scheduler->recordResult($job, $result);

                $processed++;
                $this->line(sprintf(
                    '[%s] id=%d email=%s status=%s code=%s',
                    $workerName,
                    $job->id,
                    $email->email,
                    $result->status,
                    $result->smtpCode ?? 'n/a'
                ));
            }

            $this->heartbeat($status, 'RUNNING', $processed);

            if ($iterations > 0 && $loop >= $iterations) {
                break;
            }
        }

        $this->heartbeat($status, 'STOPPED', $processed);
        $this->info("[{$workerName}] stopped after {$processed} job(s).");

        return self::SUCCESS;
    }

    private function upsertWorkerStatus(string $name, string $status, int $processed): WorkerStatus
    {
        return WorkerStatus::updateOrCreate(
            ['worker_name' => $name],
            [
                'pid' => getmypid(),
                'status' => $status,
                'jobs_processed' => $processed,
                'started_at' => Carbon::now(),
                'last_heartbeat_at' => Carbon::now(),
            ]
        );
    }

    private function heartbeat(WorkerStatus $status, string $state, int $processed, ?int $currentDomainId = null): void
    {
        $status->update([
            'status' => $state,
            'jobs_processed' => $processed,
            'current_domain_id' => $currentDomainId,
            'last_heartbeat_at' => Carbon::now(),
        ]);
    }
}
