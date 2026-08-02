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
     * Accepted spellings per target column, already normalized (see
     * normalizeHeader). Matching on an alias list rather than one exact
     * string because people reasonably write "First Name", "first_name",
     * "FirstName" or "fname" and all mean the same thing — an over-strict
     * matcher drops the column silently, which is worse than failing
     * loudly. Found live: a real import used "first_name,last_name,email"
     * and lost 994 names while still succeeding, because only `email`
     * matched.
     */
    private const COLUMN_ALIASES = [
        'email' => ['email', 'emailaddress', 'mail', 'mailaddress'],
        'first_name' => ['firstname', 'fname', 'givenname', 'forename'],
        'last_name' => ['lastname', 'lname', 'surname', 'familyname'],
    ];

    /**
     * Lowercases and strips everything that isn't a letter or digit, so
     * separators and casing stop mattering: "First Name", "first_name",
     * "First-Name" and "FIRSTNAME" all collapse to "firstname".
     */
    private function normalizeHeader(string $name, bool $isFirstColumn): string
    {
        if ($isFirstColumn) {
            // Excel/Windows tools commonly prepend a UTF-8 BOM to
            // exported CSVs, which would otherwise silently break
            // matching the first column's name (e.g. "Email" reads
            // as "\u{FEFF}Email" and never matches).
            $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
        }

        return strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? $name);
    }

    /**
     * Maps header names to column indices, tolerant of casing, spacing,
     * separators and common synonyms, so reordered or
     * differently-spelled-but-recognizable headers still work — see
     * DECISIONS.md "CSV import".
     *
     * @param  string[]  $header
     * @return array{email?:int, first_name?:int, last_name?:int}
     */
    private function mapColumns(array $header): array
    {
        $byName = [];
        foreach ($header as $index => $name) {
            $normalized = $this->normalizeHeader((string) $name, $index === 0);
            // First occurrence wins, so a stray duplicate column later in
            // the header can't silently steal the mapping.
            $byName[$normalized] ??= $index;
        }

        $map = [];
        foreach (self::COLUMN_ALIASES as $target => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($byName[$alias])) {
                    $map[$target] = $byName[$alias];
                    break;
                }
            }
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

            $existingRows = Email::whereIn('email', $emails)->get(['id', 'email', 'first_name', 'last_name']);
            $existing = $existingRows->pluck('email')->all();
            $duplicateCount += count($existing);

            $this->backfillMissingNames($existingRows, $candidates);

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

            // Addresses landing on a domain that's already on the ignore
            // list are stored exactly like any other — the address, name
            // and batch link are all kept — but their job starts as
            // IGNORED rather than PENDING. Creating them PENDING would
            // queue work the scheduler is guaranteed never to pick up,
            // so "still to do" would overstate the real backlog by
            // however many addresses sit on ignored domains (roughly half
            // a typical import). Un-ignoring the domain returns them to
            // PENDING, so nothing here is lost — it's a re-runnable
            // parking state, not a discard.
            $newEmails = Email::whereIn('email', array_keys($newRows))->get(['id', 'domain_id']);

            $ignoredDomainIds = Domain::whereIn('id', $newEmails->pluck('domain_id')->unique())
                ->where('is_ignored', true)
                ->pluck('id')
                ->flip();

            $jobRows = $newEmails->map(function ($email) use ($ignoredDomainIds, $now) {
                $isIgnored = $ignoredDomainIds->has($email->domain_id);

                return [
                    'email_id' => $email->id,
                    'status' => $isIgnored ? 'IGNORED' : 'PENDING',
                    'smtp_response' => $isIgnored
                        ? 'Domain is on the ignore list; not verified by choice.'
                        : null,
                    'processed_at' => $isIgnored ? $now : null,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

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
     * Fills in first/last name on already-known addresses when the
     * incoming CSV has a name and the stored record doesn't.
     *
     * This is deliberately narrow and does NOT contradict the dedup rule
     * in DECISIONS.md: verification status/history and the original
     * batch_id are untouched, and a name that's already stored is never
     * overwritten by a re-import. It only ever fills blanks — which makes
     * re-uploading a corrected file a way to recover names that an
     * earlier import dropped, instead of them being permanently lost to
     * dedup.
     *
     * @param  \Illuminate\Support\Collection<int, Email>  $existingRows
     * @param  array<string, array{first_name:?string, last_name:?string}>  $candidates
     */
    private function backfillMissingNames($existingRows, array $candidates): void
    {
        foreach ($existingRows as $row) {
            $incoming = $candidates[$row->email] ?? null;
            if ($incoming === null) {
                continue;
            }

            $updates = [];
            if (($row->first_name ?? '') === '' && ! empty($incoming['first_name'])) {
                $updates['first_name'] = $incoming['first_name'];
            }
            if (($row->last_name ?? '') === '' && ! empty($incoming['last_name'])) {
                $updates['last_name'] = $incoming['last_name'];
            }

            if (! empty($updates)) {
                Email::whereKey($row->id)->update($updates);
            }
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
