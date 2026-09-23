<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two cleanups the client asked for:
 *
 * 1. A cleaning task has no "auto fail" state.
 *
 *    On a chart task `auto_fail` was already ARITHMETICALLY IDENTICAL to `fail`:
 *    both entered the denominator and neither entered the numerator. It was a
 *    documented exception (cleaning-chart-module.md §12) that never did anything.
 *    So rewriting the existing rows to `fail` changes no score anywhere — it just
 *    removes a state the UI should never have offered.
 *
 * 2. The `auto_fail_zeroes_final` setting is gone, and so is the behaviour.
 *
 *    An auto_fail on an INSPECTION ITEM still zeroes the item half — that rule is
 *    untouched. What it no longer does is zero the FINAL score: a store should
 *    not lose everything on one cell, whatever the reason. The other half of the
 *    score now stands on its own.
 *
 * `down()` cannot restore which chart verdicts were auto_fail — that information
 * is deliberately discarded, because it never meant anything different from fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chart tasks: auto_fail behaved exactly like fail, so this is lossless.
        DB::table('evaluation_chart_verdicts')
            ->where('verdict', 'auto_fail')
            ->update(['verdict' => 'fail']);

        // The setting no longer exists; leaving a dead row would suggest it does.
        DB::table('cleaning_settings')
            ->where('key', 'auto_fail_zeroes_final')
            ->delete();
    }

    public function down(): void
    {
        // Restore the setting at its old default so a rollback is coherent.
        // The chart verdicts stay as `fail` — see the class comment.
        DB::table('cleaning_settings')->updateOrInsert(
            ['key' => 'auto_fail_zeroes_final'],
            ['value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        );
    }
};
