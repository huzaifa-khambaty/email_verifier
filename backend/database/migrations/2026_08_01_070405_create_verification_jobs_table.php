<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per verification attempt-cycle for an email. This is what
     * verify:work claims from (DECISIONS.md "Queue driver — no Redis, no
     * broker"). Usually 1:1 with emails at import time; re-verification
     * later is just a new PENDING row rather than mutating history.
     */
    public function up(): void
    {
        Schema::create('verification_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('emails');

            $table->enum('status', [
                'PENDING',
                'PROCESSING',
                'VALID',
                'INVALID',
                'NO_MX',
                'CATCH_ALL',
                'UNKNOWN',
                'TEMP_FAILURE',
            ])->default('PENDING');

            $table->smallInteger('smtp_code')->nullable();
            // TEXT, not VARCHAR(255): v1 truncated SMTP responses to fit a
            // 255-char column (see DECISIONS.md porting notes) — this
            // deliberately avoids that so nothing gets silently cut.
            $table->text('smtp_response')->nullable();
            $table->string('mx_host')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            // Exponential backoff gate for TEMP_FAILURE retries (5m -> 15m
            // -> 45m -> 2h, see DECISIONS.md): the claim query requires
            // next_attempt_at IS NULL OR <= NOW().
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('processed_at');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_jobs');
    }
};
