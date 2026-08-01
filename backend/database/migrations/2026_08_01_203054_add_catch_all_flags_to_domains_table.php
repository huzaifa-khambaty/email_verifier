<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domain-level catch-all memory.
     *
     * A catch-all domain accepts every recipient, so verifying its
     * addresses one at a time costs a full SMTP conversation each and
     * returns the same answer every time. Measured on real data: 419/419
     * yahoo.com addresses came back CATCH_ALL — roughly 250 days of work
     * at 6.5M scale to learn what a single probe already proved.
     * Recording the verdict against the domain lets every subsequent
     * address on it resolve instantly.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Set only once catch_all_detections reaches the configured
            // confirmation threshold — a single probe could be a
            // transient accept-everything blip during an outage.
            $table->boolean('is_catch_all')->default(false)->after('name');

            // Consecutive catch-all confirmations. Reset to zero by any
            // definitive VALID/INVALID result, since a domain that
            // distinguishes real mailboxes from fake ones is by
            // definition not catch-all. That reset is the self-correcting
            // guard that stops a false positive sticking forever.
            $table->unsignedSmallInteger('catch_all_detections')->default(0)->after('is_catch_all');

            $table->timestamp('catch_all_confirmed_at')->nullable()->after('catch_all_detections');

            // The claim query filters on this on every poll.
            $table->index('is_catch_all');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['is_catch_all']);
            $table->dropColumn(['is_catch_all', 'catch_all_detections', 'catch_all_confirmed_at']);
        });
    }
};
