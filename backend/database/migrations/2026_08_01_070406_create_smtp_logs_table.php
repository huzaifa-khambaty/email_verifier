<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full SMTP dialogue transcript, one row per stage (GREETING, EHLO,
     * HELO, MAIL_FROM, RCPT_TO, CATCHALL_PROBE, QUIT), not just the final
     * result — satisfies v2 §6 "store SMTP response code, message and
     * timestamp" and is what v1 deferred as a future enhancement.
     */
    public function up(): void
    {
        Schema::create('smtp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_job_id')->constrained('verification_jobs');

            $table->enum('stage', [
                'GREETING',
                'EHLO',
                'HELO',
                'MAIL_FROM',
                'RCPT_TO',
                'RSET',
                'CATCHALL_PROBE',
                'QUIT',
            ]);

            $table->string('mx_host')->nullable();
            $table->smallInteger('smtp_code')->nullable();
            $table->text('message')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('verification_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_logs');
    }
};
