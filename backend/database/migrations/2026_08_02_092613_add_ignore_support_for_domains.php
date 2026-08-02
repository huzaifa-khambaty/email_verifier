<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator-controlled domain ignore list.
     *
     * Distinct from the automatic flags: catch-all and unresponsive are
     * conclusions the engine reaches from evidence, whereas this is a
     * deliberate decision ("don't spend throughput on this domain") that
     * only a person can make — e.g. a domain that is 100% catch-all is
     * technically verifiable but worth nothing, and at 6.5M scale it is
     * the difference between days of work and none.
     *
     * Addresses on an ignored domain get their own IGNORED status rather
     * than being left PENDING. Leaving them pending would be actively
     * misleading: the counter would imply work still queued when nothing
     * will ever process it, and "how much is left to do" is precisely
     * the number this feature exists to inform.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('is_ignored')->default(false)->after('name');
            $table->timestamp('ignored_at')->nullable()->after('is_ignored');
            $table->index('is_ignored');
        });

        $this->setStatusEnum([
            'PENDING', 'PROCESSING', 'VALID', 'INVALID',
            'NO_MX', 'CATCH_ALL', 'UNKNOWN', 'TEMP_FAILURE', 'IGNORED',
        ]);
    }

    public function down(): void
    {
        // Ignored rows have no meaning once the status is gone; return
        // them to PENDING so nothing is stranded on a value the column
        // can no longer hold.
        DB::table('verification_jobs')->where('status', 'IGNORED')->update([
            'status' => 'PENDING',
            'processed_at' => null,
        ]);

        $this->setStatusEnum([
            'PENDING', 'PROCESSING', 'VALID', 'INVALID',
            'NO_MX', 'CATCH_ALL', 'UNKNOWN', 'TEMP_FAILURE',
        ]);

        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['is_ignored']);
            $table->dropColumn(['is_ignored', 'ignored_at']);
        });
    }

    /**
     * SQLite (used by the test suite) has no ENUM type — the column is
     * plain text there and accepts the new value without a schema
     * change, so the ALTER is MySQL/MariaDB only.
     */
    private function setStatusEnum(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = implode(',', array_map(fn ($v) => "'".$v."'", $values));

        DB::statement(
            "ALTER TABLE verification_jobs MODIFY COLUMN status ENUM({$list}) NOT NULL DEFAULT 'PENDING'"
        );
    }
};
