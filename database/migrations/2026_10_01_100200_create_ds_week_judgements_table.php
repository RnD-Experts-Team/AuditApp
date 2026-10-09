<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The specialist's weekly judgement — 40% of a store's score, and the
        // only thing the specialist writes in this module.
        //
        // It has to live here because it exists nowhere else: LC_PIZZA_DATA does
        // not know it, the inventory system does not know it, Due&Key does not
        // know it. It is a judgement in a person's head, and the frontend cannot
        // fetch it from anywhere.
        //
        // The other 60% (the variance part) is NOT stored: the frontend reads the
        // counts from the inventory system and computes it on every view.
        Schema::create('ds_week_judgements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');

            // A TUESDAY. The accounting week runs Tuesday -> Monday and is
            // produced by AccountingCalendarService, never by isoWeek() — the two
            // agree for all of 2026 and then diverge on 2026-12-29.
            $table->date('week_start');

            // Nullable: a row can exist with one half judged and the other still
            // pending. The frontend shows what is missing.
            $table->enum('stickers_compliance', ['yes', 'no'])->nullable();
            $table->enum('dough_quality', ['pass', 'fail'])->nullable();

            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('judged_by');
            $table->dateTime('judged_at');

            $table->timestamps();

            $table->unique(['store_id', 'week_start'], 'ds_judge_unique');

            // The specialist's screen asks "all stores, this week".
            $table->index('week_start');

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ds_week_judgements');
    }
};
