<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a task is not due in a report period (a monthly task in 3 of the 4 weeks
 * of its period, or a task marked not_applicable), its weight is "out of play".
 * The auditor may hand that weight to the tasks that ARE in play.
 *
 * One row per (source -> target) pair so the report can say WHERE the extra
 * weight came from: "Fryer Deep Clean +3 (from Hood Deep Clean)". A plain
 * per-task weight override would be less code but could not be audited, and
 * could not enforce "what was taken equals what was given".
 *
 * No rows for a period simply means "not allocated" — the period scores out of a
 * smaller total, which is arithmetically identical to an even pro-rata split.
 * The feature can never block a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_weight_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('period_type', 10)->default('week');
            $table->string('period_key', 20);
            $table->unsignedBigInteger('source_task_id');   // the absent task giving up its weight
            $table->unsignedBigInteger('target_task_id');   // the present task receiving it
            $table->unsignedSmallInteger('amount');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'period_type', 'period_key', 'source_task_id', 'target_task_id'],
                'cwa_unique'
            );
            $table->index(['store_id', 'period_type', 'period_key'], 'cwa_period_index');

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('source_task_id')->references('id')->on('cleaning_tasks')->cascadeOnDelete();
            $table->foreign('target_task_id')->references('id')->on('cleaning_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_weight_allocations');
    }
};
