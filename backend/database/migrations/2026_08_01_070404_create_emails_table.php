<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master address data — email is globally unique (DECISIONS.md
     * "Dedup scope"). batch_id reflects the FIRST batch an address was
     * ever seen in, not the most recent; re-uploads of a known address
     * don't move it or re-verify it. Verification status/history lives in
     * verification_jobs, not here, so re-verification is just a new job
     * row rather than mutating this one.
     */
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            $table->foreignId('domain_id')->constrained('domains');
            $table->foreignId('batch_id')->constrained('import_batches');

            $table->timestamps();

            $table->index('domain_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
