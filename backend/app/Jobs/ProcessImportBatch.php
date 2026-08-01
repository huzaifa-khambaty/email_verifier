<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Thin dispatch wrapper around CsvImportService — uses Laravel's ordinary
 * `database` queue driver (see DECISIONS.md "Queue driver — no Redis, no
 * broker": this is the one place v2's "queue workers" does mean Laravel's
 * built-in queue, since CSV import is a one-shot background task rather
 * than the continuous claim/verify/release loop verify:work runs).
 *
 * Safe to re-dispatch on the same batch after a crash — CsvImportService
 * resumes from the batch's last_row_offset instead of starting over.
 */
class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1; // retries are explicit re-dispatches, not automatic (avoid double-processing a partial chunk)

    public function __construct(public readonly int $importBatchId)
    {
    }

    public function handle(CsvImportService $importer): void
    {
        $batch = ImportBatch::findOrFail($this->importBatchId);

        $importer->process($batch);
    }
}
