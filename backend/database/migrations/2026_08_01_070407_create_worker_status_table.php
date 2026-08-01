<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per verify:work process (Supervisor-managed, see
     * DECISIONS.md "Process model"). Each worker upserts its own row on a
     * heartbeat so the dashboard's "Workers" panel (v2 §8) can show live
     * status without a broker.
     */
    public function up(): void
    {
        Schema::create('worker_status', function (Blueprint $table) {
            $table->id();
            $table->string('worker_name')->unique(); // e.g. "verify-work-3" (Supervisor numprocs index)
            $table->unsignedInteger('pid')->nullable();

            $table->enum('status', ['IDLE', 'RUNNING', 'STOPPED'])->default('IDLE');
            $table->foreignId('current_domain_id')->nullable()->constrained('domains');

            $table->unsignedBigInteger('jobs_processed')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_status');
    }
};
