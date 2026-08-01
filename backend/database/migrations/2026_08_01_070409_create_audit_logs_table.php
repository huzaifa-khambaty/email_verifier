<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which actions get audited is still open (see TODO.md) — schema is
     * generic (action + context JSON) so that decision doesn't require a
     * migration change later.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action'); // e.g. "login", "csv.uploaded", "export.requested"
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
