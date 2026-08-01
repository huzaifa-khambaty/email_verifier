<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportBatch\StoreImportBatchRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ImportBatchController extends Controller
{
    public function index(): JsonResponse
    {
        $batches = ImportBatch::query()
            ->latest()
            ->paginate(20);

        return response()->json($batches);
    }

    public function store(StoreImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $batch = ImportBatch::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => 'pending', // placeholder until we know the batch id
            'uploaded_by' => $request->user()->id,
            'status' => 'PENDING',
        ]);

        $storedPath = $file->storeAs('imports', "{$batch->id}.csv", 'local');
        $batch->update(['stored_path' => $storedPath]);

        ProcessImportBatch::dispatch($batch->id);

        return response()->json($batch, 201);
    }

    public function show(ImportBatch $importBatch): JsonResponse
    {
        return response()->json($importBatch);
    }

    /** Download the rejected-rows report for a batch, if it has one. */
    public function errors(ImportBatch $importBatch): mixed
    {
        if ($importBatch->error_report_path === null) {
            return response()->json(['message' => 'No error report for this batch.'], 404);
        }

        return Storage::disk('local')->download(
            $importBatch->error_report_path,
            "import-{$importBatch->id}-errors.csv"
        );
    }
}
