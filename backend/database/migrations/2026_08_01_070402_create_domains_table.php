<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per email domain seen (created on demand during CSV import).
     * Drives the anti-blacklist scheduler — see DECISIONS.md "Domain
     * priority & scheduler" and "Queue driver — no Redis, no broker" for
     * exactly how these columns are used in the verify:work claim query.
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. "gmail.com"

            // Admin-configurable scheduling order; higher pulls first but
            // never starves lower-priority domains (see DECISIONS.md).
            $table->integer('priority')->default(0);

            // Per-domain throttle: min seconds between requests to this
            // domain (±20% jitter applied at dispatch time, not stored).
            $table->unsignedSmallInteger('delay_seconds')->default(3);

            // Per-domain concurrency cap and live counter of in-flight
            // verification_jobs currently claimed against this domain.
            $table->unsignedSmallInteger('max_workers')->default(1);
            $table->unsignedSmallInteger('active_workers')->default(0);

            $table->timestamp('last_dispatched_at')->nullable();

            // Circuit breaker: consecutive TEMP_FAILURE/timeout results:
            // reset to 0 on any non-temp-failure result, and once it hits
            // the configured threshold the domain is paused until
            // cooling_down_until.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('cooling_down_until')->nullable();

            $table->timestamps();

            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
