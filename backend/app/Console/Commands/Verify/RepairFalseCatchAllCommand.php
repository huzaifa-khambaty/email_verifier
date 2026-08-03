<?php

namespace App\Console\Commands\Verify;

use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Undoes catch-all flags that the evidence contradicts, and re-queues the
 * addresses they caused to be skipped.
 *
 * The live detector originally flagged on three consecutive catch-all
 * probes alone. A domain returning catch-all for a minority of addresses
 * will produce three in a row eventually, so hotmail.com was flagged
 * despite 1,407 VALID and 292 INVALID results — and 8,082 of its
 * addresses were then resolved from the domain record instead of being
 * checked, replacing real answers with a fabricated one.
 *
 * A domain that has ever returned VALID or INVALID is not catch-all. This
 * finds any flagged domain meeting that description, clears the flag, and
 * returns every address it short-circuited to PENDING so they get a real
 * verification. Addresses whose CATCH_ALL came from an actual SMTP probe
 * are left alone — those results are genuine.
 */
class RepairFalseCatchAllCommand extends Command
{
    /**
     * Written by the short-circuit path, which is what makes a fabricated
     * verdict distinguishable from a real probe result.
     */
    private const SHORTCUT_MARKER = 'Domain confirmed catch-all; resolved without an individual SMTP check.';

    protected $signature = 'verify:repair-false-catch-all {--dry-run : Report what would change without changing it}';

    protected $description = 'Clear catch-all flags contradicted by real results and re-queue skipped addresses.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $suspect = Domain::where('is_catch_all', true)
            ->whereExists(fn ($q) => $q
                ->from('emails')
                ->join('verification_jobs', 'verification_jobs.email_id', '=', 'emails.id')
                ->whereColumn('emails.domain_id', 'domains.id')
                ->whereIn('verification_jobs.status', ['VALID', 'INVALID']))
            ->get();

        if ($suspect->isEmpty()) {
            $this->info('No catch-all flags are contradicted by real results.');

            return self::SUCCESS;
        }

        $this->warn($suspect->count().' domain(s) flagged catch-all despite giving definitive answers:');

        $totalRequeued = 0;

        foreach ($suspect as $domain) {
            $counts = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $domain->id)
                ->selectRaw("
                    SUM(verification_jobs.status = 'VALID') as valid,
                    SUM(verification_jobs.status = 'INVALID') as invalid,
                    SUM(verification_jobs.status = 'CATCH_ALL' AND verification_jobs.smtp_response = ?) as shortcut,
                    SUM(verification_jobs.status = 'CATCH_ALL' AND (verification_jobs.smtp_response <> ? OR verification_jobs.smtp_response IS NULL)) as probed
                ", [self::SHORTCUT_MARKER, self::SHORTCUT_MARKER])
                ->first();

            $this->line(sprintf(
                '  %-22s %s valid, %s invalid — %s addresses were skipped (%s genuinely probed, left alone)',
                $domain->name,
                number_format((int) $counts->valid),
                number_format((int) $counts->invalid),
                number_format((int) $counts->shortcut),
                number_format((int) $counts->probed)
            ));

            if ($dryRun) {
                continue;
            }

            $domain->update([
                'is_catch_all' => false,
                'catch_all_detections' => 0,
                'catch_all_confirmed_at' => null,
            ]);

            $totalRequeued += $this->requeueShortcut($domain->id);
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Cleared {$suspect->count()} flag(s); re-queued ".number_format($totalRequeued).' address(es) for real verification.');

        return self::SUCCESS;
    }

    /** Chunked so a domain with millions of rows never takes one huge lock. */
    private function requeueShortcut(int $domainId): int
    {
        $requeued = 0;

        do {
            $ids = DB::table('verification_jobs')
                ->join('emails', 'emails.id', '=', 'verification_jobs.email_id')
                ->where('emails.domain_id', $domainId)
                ->where('verification_jobs.status', 'CATCH_ALL')
                ->where('verification_jobs.smtp_response', self::SHORTCUT_MARKER)
                ->limit(5000)
                ->pluck('verification_jobs.id');

            if ($ids->isEmpty()) {
                break;
            }

            $requeued += DB::table('verification_jobs')
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'PENDING',
                    'smtp_response' => null,
                    'smtp_code' => null,
                    // Cleared so throughput charts and date filters don't
                    // carry a completion time for a check that never ran.
                    'processed_at' => null,
                    'updated_at' => now(),
                ]);
        } while ($ids->count() === 5000);

        return $requeued;
    }
}
