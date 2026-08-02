<?php

namespace App\Services\Dashboard;

use App\Models\Domain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the raw counters into the observations an operator would
 * otherwise have to ask for: what the numbers mean, what is limiting
 * throughput, and what is worth doing about it.
 *
 * Every insight is derived from live data and states the evidence it is
 * based on, so a claim can always be checked rather than trusted. Where
 * there is nothing notable, nothing is emitted — an empty list means
 * "healthy", not "no data".
 */
class InsightGenerator
{
    /** @return array<int, array{level:string, title:string, detail:string}> */
    public function generate(array $stats, array $blockers, array $flags): array
    {
        $insights = [];

        foreach ([
            $this->catchAllShare($stats),
            $this->catchAllSavings($flags),
            $this->unresponsiveDomains($flags),
            $this->concentrationBottleneck(),
            $this->stalledQueue($stats, $blockers),
            $this->workerHealth($stats),
            $this->retryExhaustion($stats),
        ] as $insight) {
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }

    /**
     * Catch-all is the number most likely to be misread as "good", so it
     * gets called out whenever it's a large share of settled results.
     */
    private function catchAllShare(array $stats): ?array
    {
        $settled = $stats['verified'] + $stats['invalid'] + $stats['catch_all']
            + $stats['no_mx'] + $stats['unknown'] + $stats['temp_failure'];

        if ($settled === 0 || $stats['catch_all'] === 0) {
            return null;
        }

        $share = round($stats['catch_all'] / $settled * 100);
        if ($share < 25) {
            return null;
        }

        return [
            'level' => 'warning',
            'title' => "{$share}% of results are catch-all — treat these as unverifiable",
            'detail' => "{$stats['catch_all']} addresses sit on domains that accept every recipient, "
                ."so a 250 there is not evidence the mailbox exists. Only the {$stats['verified']} "
                .'marked Valid are confirmed deliverable. Export Valid separately if you need a '
                .'list you can rely on.',
        ];
    }

    private function catchAllSavings(array $flags): ?array
    {
        $domains = $flags['catch_all'] ?? [];
        if (empty($domains)) {
            return null;
        }

        $count = count($domains);
        $names = implode(', ', array_column(array_slice($domains, 0, 3), 'domain'));
        $more = $count > 3 ? ' and '.($count - 3).' more' : '';
        $verb = ($count === 1 && $more === '') ? 'is' : 'are';

        return [
            'level' => 'good',
            'title' => $count === 1
                ? '1 catch-all domain resolved without SMTP'
                : "{$count} catch-all domains resolved without SMTP",
            'detail' => "{$names}{$more} {$verb} confirmed catch-all, so their addresses are settled "
                .'from the domain record instead of one connection each. This is the single '
                .'largest throughput saving available, and it grows with list size.',
        ];
    }

    private function unresponsiveDomains(array $flags): ?array
    {
        $domains = $flags['unresponsive'] ?? [];
        if (empty($domains)) {
            return null;
        }

        $names = implode(', ', array_column(array_slice($domains, 0, 3), 'domain'));
        $affected = array_sum(array_column($domains, 'failures'));

        return [
            'level' => 'warning',
            'title' => count($domains).' domain(s) are refusing verification connections',
            'detail' => "{$names} dropped every connection across {$affected} addresses, so they're "
                .'paused rather than retried. Addresses there are marked Temp failure — that '
                ."reflects the provider blocking us, not a problem with the addresses. The pause "
                .'lapses automatically and they get retried later.',
        ];
    }

    /**
     * The structural throughput limit: one connection per domain means a
     * list concentrated on few domains can't be parallelised, however
     * many workers exist.
     */
    private function concentrationBottleneck(): ?array
    {
        $top = DB::table('emails')
            ->join('domains', 'domains.id', '=', 'emails.domain_id')
            ->where('domains.is_catch_all', false)
            ->groupBy('domains.name', 'domains.delay_seconds', 'domains.max_workers')
            ->select([
                'domains.name',
                'domains.delay_seconds',
                'domains.max_workers',
                DB::raw('COUNT(*) as total'),
            ])
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->first();

        $all = DB::table('emails')->count();
        if (! $top || $all === 0) {
            return null;
        }

        $share = round($top->total / $all * 100);
        if ($share < 15) {
            return null;
        }

        $perMinute = $top->delay_seconds > 0
            ? round(60 / $top->delay_seconds * max(1, $top->max_workers), 1)
            : 0;
        $hours = $perMinute > 0 ? round($top->total / $perMinute / 60, 1) : 0;

        return [
            'level' => 'info',
            'title' => "{$top->name} is {$share}% of the list and caps throughput at {$perMinute}/min",
            'detail' => "One connection every {$top->delay_seconds}s per domain means its "
                ."{$top->total} addresses need roughly {$hours}h regardless of worker count. "
                .'Raising that domain\'s connection limit is the lever — at the cost of a higher '
                .'risk of the provider blocking this IP.',
        ];
    }

    /**
     * The specific question that prompted all of this: work remains but
     * nothing appears to be happening.
     */
    private function stalledQueue(array $stats, array $blockers): ?array
    {
        $waiting = $stats['pending'] + $stats['processing'];
        if ($waiting === 0 || $stats['queue_speed_per_min'] > 0) {
            return null;
        }

        $blocked = array_filter($blockers, fn ($b) => $b['seconds_until'] !== null);
        if (empty($blocked)) {
            return null;
        }

        usort($blocked, fn ($a, $b) => $a['seconds_until'] <=> $b['seconds_until']);
        $next = $blocked[0];
        $minutes = max(1, (int) round($next['seconds_until'] / 60));

        return [
            'level' => 'info',
            'title' => "{$waiting} address(es) waiting — this is deliberate, not a fault",
            'detail' => "Nothing is eligible right now. {$next['domain']} is the next to free up, "
                ."in about {$minutes} minute(s): {$next['reason']}. Pauses like this are what "
                .'keep the sending IP off blocklists.',
        ];
    }

    private function workerHealth(array $stats): ?array
    {
        if ($stats['active_workers'] > 0) {
            return null;
        }

        return [
            'level' => 'critical',
            'title' => 'No workers are reporting in',
            'detail' => 'No verification worker has sent a heartbeat in the last two minutes, so '
                .'nothing will be processed. Check Supervisor on the server: '
                .'sudo supervisorctl status email-verifier:*',
        ];
    }

    private function retryExhaustion(array $stats): ?array
    {
        $settled = $stats['verified'] + $stats['invalid'] + $stats['catch_all']
            + $stats['no_mx'] + $stats['unknown'] + $stats['temp_failure'];

        if ($settled === 0 || $stats['temp_failure'] === 0) {
            return null;
        }

        $share = round($stats['temp_failure'] / $settled * 100);
        if ($share < 10) {
            return null;
        }

        return [
            'level' => 'warning',
            'title' => "{$share}% of addresses gave up after exhausting retries",
            'detail' => "{$stats['temp_failure']} addresses never got a usable answer — usually "
                .'servers that time out or drop the connection. Worth confirming this server\'s '
                .'reverse DNS and sender-domain MX are still correct, since both affect whether '
                .'mail servers will talk to us at all.',
        ];
    }
}
