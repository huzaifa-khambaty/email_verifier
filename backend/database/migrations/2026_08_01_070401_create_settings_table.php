<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simple key/value store for global, admin-configurable defaults —
     * see DECISIONS.md "Domain priority & scheduler" and "Reference
     * defaults" for the values this seeds (delay/worker/timeout/retry
     * defaults). Per-domain overrides live on the `domains` table itself.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
