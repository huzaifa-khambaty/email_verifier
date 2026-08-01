<?php

namespace App\Services\Import;

use App\Models\Domain;
use App\Models\Email;
use App\Models\ImportBatch;
use App\Support\EmailSyntax;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Parses a batch's CSV (First Name, Last Name, Email — DECISIONS.md "CSV
 * import") in chunks, upserting by email (DECISIONS.md "Dedup scope": a
 * known address is neither duplicated nor moved to the new batch, and
 * doesn't get re-verified) and creating one PENDING verification_jobs row
 * per newly-seen address.
 *
 * Resumable (v2 §4): last_row_offset is a byte offset into the stored
 * file, checkpointed after each chunk, so re-dispatching this on the same
 * batch after a crash picks up with fseek() rather than re-reading
 * everything already processed.
 */
class CsvImportService
{
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly array $schedulerConfig)
    {
    }

    public function process(ImportBatch $batch): void
    {
        $path = Storage::disk('local')->path($batch->stored_path);

        $handle = null;
        $errorHandle = null;

        try {
            // fopen() failure (missing file, permission denied) raises a
            // PHP warning that Laravel's error handler converts to an
            // ErrorException — that exception was previously thrown from
            // *outside* this try block (fopen used to sit above it), so
            // the batch was never marked FAILED and stayed stuck at
            // PENDING forever. Found live in production: an
            // storage/app/private ownership mismatch between the web
            // server (uploads) and the queue worker (processes) meant
            // every import silently hung. Moving fopen() in here ensures
            // any failure to open the file is caught the same as every
            // other failure mode below.
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Could not open stored CSV for batch {$batch->id}: {$path}");
            }

            $batch->update([
                'status' => 'IMPORTING',
                'started_at' => $batch->started_at ?? now(),
            ]);

            $header = fgetcsv($handle);
            if ($header === false) {
                throw new RuntimeException('CSV file is empty.');
            }

            $columnMap = $this->mapColumns($header);
            if (! isset($columnMap['email'])) {
                throw new RuntimeException('CSV must have an "Email" column.');
            }

            $headerEndOffset = ftell($handle);
            $resumeOffset = $batch->last_row_offset > 0 ? $batch->last_row_offset : $headerEndOffset;
            fseek($handle, $resumeOffset);

            $errorHandle = $this->openErrorFile($batch);

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;

                if (count($rows) >= self::CHUNK_SIZE) {
                    $this->processChunk($batch, $rows, $columnMap, $errorHandle);
                    $rows = [];
                    $batch->update(['last_row_offset' => ftell($handle)]);
                }
            }

            if (! empty($rows)) {
                $this->processChunk($batch, $rows, $columnMap, $errorHandle);
            }

            $batch->refresh();
            $batch->update([
                'last_row_offset' => ftell($handle),
                'total_rows' => $batch->imported_rows + $batch->duplicate_count + $batch->error_count,
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $batch->update(['status' => 'FAILED']);

            throw $e;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($errorHandle !== null) {
                fclose($errorHandle);
            }
        }
    }

    /**
     * Maps header names to column indices, case-insensitively, so
     * reordered (but recognizable) headers still work — see DECISIONS.md
     * "CSV import".
     *
     * @param  string[]  $header
     * @return array{email?:int, first_name?:int, last_name?:int}
     */
    private function mapColumns(array $header): array
    {
        $byName = [];
        foreach ($header as $index => $name) {
            $byName[strtolower(trim((string) $name))] = $index;
        }

        $map = [];
        if (isset($byName['email'])) {
            $map['email'] = $byName['email'];
        }
        if (isset($byName['first name'])) {
            $map['first_name'] = $byName['first name'];
        }
        if (isset($byName['last name'])) {
            $map['last_name'] = $byName['last name'];
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array{email:int, first_name?:int, last_name?:int}  $columnMap
     * @param  resource|null  $errorHandle
     */
    private function processChunk(ImportBatch $batch, array $rows, array $columnMap, $errorHandle): void
    {
        $candidates = [];
        $errorCount = 0;

        foreach ($rows as $row) {
            $email = trim((string) ($row[$columnMap['email']] ?? ''));
            $firstName = isset($columnMap['first_name']) ? trim((string) ($row[$columnMap['first_name']] ?? '')) : null;
            $lastName = isset($columnMap['last_name']) ? trim((string) ($row[$columnMap['last_name']] ?? '')) : null;

            if (! EmailSyntax::isValid($email)) {
                $errorCount++;
                if ($errorHandle !== null) {
                    fputcsv($errorHandle, [$email, $firstName, $lastName, 'Invalid or missing email']);
                }

                continue;
            }

            // Last occurrence within a chunk wins if the same address
            // appears twice in one chunk; the rest count as duplicates
            // below via the "already seen" check against $candidates.
            $candidates[strtolower($email)] = [
                'email' => strtolower($email),
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name' => $lastName !== '' ? $lastName : null,
                'domain' => EmailSyntax::domain($email),
            ];
        }

        $duplicateCount = count($rows) - $errorCount - count($candidates);

        DB::transaction(function () use ($batch, $candidates, &$duplicateCount) {
            if (empty($candidates)) {
                return;
            }

            $emails = array_keys($candidates);

            $existing = Email::whereIn('email', $emails)->pluck('email')->all();
            $duplicateCount += count($existing);

            $newRows = array_diff_key($candidates, array_flip($existing));
            if (empty($newRows)) {
                return;
            }

            $domainIds = $this->resolveDomainIds(array_unique(array_column($newRows, 'domain')));

            $now = now();
            $insertRows = array_map(fn (array $row) => [
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'domain_id' => $domainIds[$row['domain']],
                'batch_id' => $batch->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($newRows));

            Email::insert($insertRows);

            $newIds = Email::whereIn('email', array_keys($newRows))->pluck('id');
            $jobRows = $newIds->map(fn ($id) => [
                'email_id' => $id,
                'status' => 'PENDING',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('verification_jobs')->insert($jobRows);

            $batch->increment('imported_rows', count($newRows));
        });

        if ($errorCount > 0) {
            $batch->increment('error_count', $errorCount);
        }
        if ($duplicateCount > 0) {
            $batch->increment('duplicate_count', $duplicateCount);
        }
    }

    /**
     * @param  string[]  $names
     * @return array<string, int> domain name => id
     */
    private function resolveDomainIds(array $names): array
    {
        $existing = Domain::whereIn('name', $names)->pluck('id', 'name')->all();

        foreach (array_diff($names, array_keys($existing)) as $name) {
            $isMajor = in_array($name, $this->schedulerConfig['major_providers'], true);

            $domain = Domain::firstOrCreate(['name' => $name], [
                'delay_seconds' => $isMajor
                    ? $this->schedulerConfig['major_provider_delay_seconds']
                    : $this->schedulerConfig['default_delay_seconds'],
                'max_workers' => $this->schedulerConfig['default_max_workers'],
            ]);

            $existing[$name] = $domain->id;
        }

        return $existing;
    }

    /**
     * @return resource|null
     */
    private function openErrorFile(ImportBatch $batch)
    {
        $relativePath = "imports/errors/{$batch->id}.csv";
        Storage::disk('local')->makeDirectory('imports/errors');

        $absolutePath = Storage::disk('local')->path($relativePath);
        $isNew = ! file_exists($absolutePath);

        $handle = fopen($absolutePath, 'ab');
        if ($handle === false) {
            return null;
        }

        if ($isNew) {
            fputcsv($handle, ['email', 'first_name', 'last_name', 'reason']);
        }

        if ($batch->error_report_path !== $relativePath) {
            $batch->update(['error_report_path' => $relativePath]);
        }

        return $handle;
    }
}
