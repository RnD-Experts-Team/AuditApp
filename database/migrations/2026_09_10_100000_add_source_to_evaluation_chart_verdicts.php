<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO decided this verdict — a human, or the system?
 *
 * A chart task the store never marked complete is now failed automatically. On
 * the report that fail looks identical to one the auditor entered after seeing
 * bad work, and those are very different statements to make to a store:
 *
 *   "you did not do it"        → system
 *   "you did it badly"         → auditor
 *
 * Stores will ask which one it was, so it has to be recorded rather than
 * reconstructed.
 *
 * Nullable, and no backfill: every existing row was entered by a person, which
 * is exactly what a null reads as (see EvaluationChartVerdict::isSystemVerdict).
 * Backfilling 'auditor' would claim more certainty about old rows than we have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_chart_verdicts', function (Blueprint $table) {
            // varchar, not enum — the August work converted the verdict columns
            // away from enums precisely so adding a value never needs an ALTER.
            $table->string('source', 10)->nullable()->after('verdict');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_chart_verdicts', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
