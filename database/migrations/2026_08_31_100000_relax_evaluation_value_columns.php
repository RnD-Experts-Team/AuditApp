<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `verdict` and `value` were MySQL ENUMs, so every new state needed a schema
 * change. We are adding `not_applicable` now and do not want to write this
 * migration again — switch to varchar(20) and let Laravel's `Rule::in(...)`
 * enforce the vocabulary in the application layer.
 *
 * Existing rows are untouched: every current value already fits varchar(20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_chart_verdicts', function (Blueprint $table) {
            $table->string('verdict', 20)->change();
        });

        Schema::table('evaluation_item_values', function (Blueprint $table) {
            $table->string('value', 20)->default('empty')->change();
        });
    }

    public function down(): void
    {
        // Back to the original enums. Any row holding a value the old enum did
        // not know (e.g. 'not_applicable') would be rejected by MySQL, so clear
        // those first rather than failing the rollback.
        if (Schema::hasTable('evaluation_chart_verdicts')) {
            \DB::table('evaluation_chart_verdicts')
                ->whereNotIn('verdict', ['pass', 'fail', 'auto_fail'])
                ->delete();
        }

        if (Schema::hasTable('evaluation_item_values')) {
            \DB::table('evaluation_item_values')
                ->whereNotIn('value', ['pass', 'fail', 'auto_fail', 'empty'])
                ->update(['value' => 'empty']);
        }

        Schema::table('evaluation_chart_verdicts', function (Blueprint $table) {
            $table->enum('verdict', ['pass', 'fail', 'auto_fail'])->change();
        });

        Schema::table('evaluation_item_values', function (Blueprint $table) {
            $table->enum('value', ['pass', 'fail', 'auto_fail', 'empty'])->default('empty')->change();
        });
    }
};
