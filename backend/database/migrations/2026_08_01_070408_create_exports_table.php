<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acceptance criteria: "Export by date range/status" (v2 §14). File
     * format/exact columns are still open (see TODO.md) — this schema is
     * format-agnostic (file_path + file_format) so that isn't blocking.
     */
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users');

            $table->json('filters')->nullable(); // e.g. {"status":"VALID","from":"2026-01-01","to":"2026-01-31"}
            $table->string('file_format')->default('csv');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            $table->enum('status', ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'])->default('PENDING');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
