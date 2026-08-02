<?php

namespace App\Console\Commands\Verify;

use App\Models\Domain;
use App\Services\Verification\VerificationScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Flags domains that refuse verification traffic, based on results
 * already on record, and settles their outstanding addresses.
 *
 * The live detection in VerificationScheduler only learns going forward.
 * This backfills from history — on the sample data it identifies
 * rediffmail.com, where every address failed with "SMTP connection
 * closed unexpectedly" and each burned five retries across hours of
 * cooldowns to reach a foregone conclusion.
 *
 * A domain that has ever produced a definitive result is excluded
 * outright: one success proves it does answer.
 */
class DetectUnresponsiveCommand extends Command
{
    protected $signature = 'verify:detect-unresponsive
        {--min-failures= : Exhausted failures required before flagging (defaults to config)}
        {--dry-run : Show what would be flagged without changing anything}';

    protected $description = 'Flag domains refusing verification connections and settle their pending addresses.';

    public function handle(VerificationScheduler $scheduler): int
    {
        $threshold = (int) ($this->option('min-failures')
            ?: config('verifier.scheduler.unresponsive_threshold', 10));
        $days = (int) config('verifier.scheduler.unresponsive_days', 7);
        $dryRun = (bool) $this->option('dry-run');

        // `= 0` on definitive results rather than a ratio: a single
        // VALID, INVALID or CATCH_ALL means the domain talks to us, which
        // disqualifies it however many timeouts sit alongside.
        $candidates = DB::table('domains as d')
            ->join('emails as e', 'e.domain_id', '=', 'd.id')
            ->join('verification_jobs as vj', 'vj.email_id', '=', 'e.id')
            ->groupBy('d.id', 'd.name', 'd.unresponsive_until')
            ->havingRaw('SUM(vj.status IN (?, ?, ?)) = 0', ['VALID', 'INVALID', 'CATCH_ALL'])
            ->havingRaw('SUM(vj.status = ?) >= ?', ['TEMP_FAILURE', $threshold])
            ->select([
                'd.id',
                'd.name',
                'd.unresponsive_until',
                DB::raw('SUM(vj.status = "TEMP_FAILURE") as failures'),
                DB::raw('SUM(vj.status = "PENDING") as pending'),
            ])
            ->orderByDesc(DB::raw('SUM(vj.status = "TEMP_FAILURE")'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No domains meet the unresponsive criteria.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d domain(s) are refusing verification traffic:', $candidates->count()));

        $flagged = 0;
        $settled = 0;

        foreach ($candidates as $row) {
            $already = $row->unresponsive_until !== null
                && $row->unresponsive_until > now()->toDateTimeString();

            $this->line(sprintf(
                '  %-24s %d exhausted failures, %d pending%s',
                $row->name,
                $row->failures,
                $row->pending,
                $already ? ' (already flagged)' : ''
            ));

            if ($dryRun) {
                continue;
            }

            if (! $already) {
                Domain::whereKey($row->id)->update([
                    'exhausted_failures' => $row->failures,
                    'unresponsive_until' => now()->addDays($days),
                ]);
                $flagged++;
            }

            $settled += $scheduler->resolvePendingForUnresponsiveDomain($row->id);
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Flagged {$flagged} domain(s) for {$days} days; settled {$settled} pending address(es).");
        $this->comment('Flags lapse automatically, so these domains are retried later without intervention.');

        return self::SUCCESS;
    }
}
