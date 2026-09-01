<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the item's weight into the graded cell, exactly like
 * `evaluation_chart_verdicts.weight` already does for chart tasks.
 *
 * Without this, raising "Restroom" from 1 to 10 in September would silently
 * re-score every August report that was already sent to the stores.
 *
 * Existing rows get 1, which is what every item weighs before this release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_item_values', function (Blueprint $table) {
            $table->unsignedTinyInteger('weight')->default(1)->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_item_values', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
