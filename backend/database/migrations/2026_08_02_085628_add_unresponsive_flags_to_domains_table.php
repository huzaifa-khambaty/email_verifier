<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domains that refuse verification traffic outright.
     *
     * Measured on real data: all 60 rediffmail.com addresses failed with
     * "SMTP connection closed unexpectedly" — the server accepts the TCP
     * connection then hangs up. Each address still burned 5 retry
     * attempts spread over hours of circuit-breaker cooldowns before
     * settling as TEMP_FAILURE, i.e. ~300 doomed connections to learn one
     * fact. At 6.5M scale a domain like that could waste days.
     *
     * Deliberately expiring rather than permanent: a provider that
     * refuses us today may not next month, and a permanent flag would
     * silently stop verifying a domain forever with no way to notice.
     * `unresponsive_until` lets the flag lapse so the domain is retried
     * naturally — if it still refuses, it simply re-flags.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Count of addresses that exhausted every retry with a
            // connection-level failure. Reset to zero by any definitive
            // result, since one success proves the domain does answer.
            $table->unsignedInteger('exhausted_failures')->default(0)->after('consecutive_failures');

            // Non-null and in the future = skip this domain's addresses
            // without dialling. Null = normal processing.
            $table->timestamp('unresponsive_until')->nullable()->after('exhausted_failures');

            $table->index('unresponsive_until');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['unresponsive_until']);
            $table->dropColumn(['exhausted_failures', 'unresponsive_until']);
        });
    }
};
