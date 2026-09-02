<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the three scores onto the evaluation when it is finalized.
 *
 * Until now the final score existed nowhere: it was recomputed every time
 * someone opened a report. That means changing the formula silently rewrites
 * every past report — a store could be told 23.8% and later shown 45.9% for the
 * same week.
 *
 * There are no sent reports yet, so nothing is at risk today. That is exactly
 * why this is cheap to add now and expensive to add after go-live.
 *
 * `score_formula` records WHICH formula produced the numbers, so a future change
 * is self-documenting rather than a mystery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->decimal('item_score', 5, 1)->nullable()->after('finalized_by');
            $table->decimal('chart_score', 5, 1)->nullable()->after('item_score');
            $table->decimal('final_score', 5, 1)->nullable()->after('chart_score');
            $table->string('score_formula', 20)->nullable()->after('final_score');
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn(['item_score', 'chart_score', 'final_score', 'score_formula']);
        });
    }
};
