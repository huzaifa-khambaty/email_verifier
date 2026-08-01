<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per CSV upload. Tracks import (not verification) progress —
     * see v2 §4 "support resume after interruption": the stored file plus
     * last_row_offset let a crashed/interrupted import pick back up
     * without re-reading rows it already processed.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('stored_path'); // original CSV kept for audit/resume
            $table->foreignId('uploaded_by')->constrained('users');

            $table->enum('status', [
                'PENDING',    // uploaded, not yet parsed
                'IMPORTING',  // actively reading rows
                'COMPLETED',
                'FAILED',
            ])->default('PENDING');

            $table->unsignedInteger('total_rows')->nullable();
            // Cursor into stored_path for resuming an interrupted import.
            $table->unsignedInteger('last_row_offset')->default(0);

            // Outcome tallies (see DECISIONS.md "Dedup scope" for why
            // duplicates don't create a new emails/verification_jobs row).
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            // CSV of rejected rows (missing/malformed email) for review.
            $table->string('error_report_path')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
