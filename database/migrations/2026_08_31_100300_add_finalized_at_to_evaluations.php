<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `finalize` used to only fire a notification and write nothing, so an
 * evaluation stayed editable forever — including after the store had been told
 * its score. These two columns are the lock.
 *
 * Existing rows stay NULL = "never finalized" = still editable, so nothing in
 * production becomes read-only on deploy. Only newly finalized evaluations lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('created_by');
            $table->unsignedBigInteger('finalized_by')->nullable()->after('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn(['finalized_at', 'finalized_by']);
        });
    }
};
