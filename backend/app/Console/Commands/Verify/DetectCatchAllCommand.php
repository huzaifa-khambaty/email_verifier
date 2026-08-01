<?php

namespace App\Console\Commands\Verify;

use App\Models\Domain;
use App\Services\Verification\VerificationScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Flags catch-all domains from verification results already on record,
 * then resolves their outstanding addresses in bulk.
 *
 * The live detection in VerificationScheduler only learns going forward.
 * This backfills from history, which matters after an import has already
 * been verified — on the sample data it identifies yahoo.com (419/419
 * catch-all) and immediately clears every remaining yahoo address
 * instead of spending an SMTP conversation on each.
 *
 * Safe to re-run: it only ever flags domains whose evidence still holds,
 * and a domain that has produced even one VALID/INVALID is excluded, so
 * a domain that stops being catch-all is never flagged.
 */
class DetectCatchAllCommand extends Command
{
    protected $signature = 'verify:detect-catch-all
        {--min-samples=3 : Minimum settled results a domain needs before it can be flagged}
        {--dry-run : Show what would be flagged without changing anything}';

    protected $description = 'Flag catch-all domains from existing results and bulk-resolve their pending addresses.';

    public function handle(VerificationScheduler $scheduler): int
    {
        $minSamples = max(1, (int) $this->option('min-samples'));
        $dryRun = (bool) $this->option('dry-run');

        // A domain qualifies only if EVERY settled result was CATCH_ALL.
        // One VALID or INVALID proves the server distinguishes real
        // mailboxes from fake ones, which disqualifies it outright —
        // hence `discriminating = 0` rather than a percentage threshold.
        // Inconclusive results (TEMP_FAILURE/NO_MX/UNKNOWN) are excluded
        // from the sample count instead of counting against it.
        $candidates = DB::table('domains as d')
            ->join('emails as e', 'e.domain_id', '=', 'd.id')
            ->join('verification_jobs as vj', 'vj.email_id', '=', 'e.id')
            ->whereIn('vj.status', ['CATCH_ALL', 'VALID', 'INVALID'])
            ->groupBy('d.id', 'd.name', 'd.is_catch_all')
            ->havingRaw('SUM(vj.status = ?) = 0', ['VALID'])
            ->havingRaw('SUM(vj.status = ?) = 0', ['INVALID'])
            ->havingRaw('COUNT(*) >= ?', [$minSamples])
            ->select([
                'd.id',
                'd.name',
                'd.is_catch_all',
                DB::raw('COUNT(*) as settled'),
            ])
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No domains meet the catch-all criteria yet.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d domain(s) qualify as catch-all:', $candidates->count()));

        $flagged = 0;
        $resolved = 0;

        foreach ($candidates as $row) {
            $pending = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $row->id)
                ->where('verification_jobs.status', 'PENDING')
                ->count();

            $this->line(sprintf(
                '  %-24s %d/%d catch-all%s, %d pending',
                $row->name,
                $row->settled,
                $row->settled,
                $row->is_catch_all ? ' (already flagged)' : '',
                $pending
            ));

            if ($dryRun) {
                continue;
            }

            if (! $row->is_catch_all) {
                Domain::whereKey($row->id)->update([
                    'is_catch_all' => true,
                    'catch_all_detections' => $row->settled,
                    'catch_all_confirmed_at' => now(),
                ]);
                $flagged++;
            }

            $resolved += $scheduler->resolvePendingForCatchAllDomain($row->id);
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Flagged {$flagged} new domain(s); resolved {$resolved} pending address(es) without SMTP.");

        return self::SUCCESS;
    }
}
