<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-editable scoring settings.
 *
 * These live in a TABLE, not a config file and not a separate database:
 *   - a config file would need a code change + deploy to alter a percentage
 *   - a separate database would break joins and transactions, complicate
 *     backups, and add a connection to maintain — all to hold four rows
 *
 * A table in the same database gives the "change it without touching code"
 * property with none of that cost, and leaves room for an admin settings screen
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('value', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_settings');
    }
};
