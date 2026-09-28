<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A row = a CONFIRMED daily plan line. The presence of the row IS the
        // state, so there is no `status` column — same as cleaning_completions
        // ("Only done rows exist; pending/overdue are computed on read").
        //
        // Everything else the plan screen shows — the four source days, their
        // average, the base — is fetched live from LC_PIZZA_DATA and computed in
        // the browser. Only the two numbers a human decided are kept here.
        //
        // `base_qty` is deliberately absent: it is derivable.
        //     base = planned_qty / (1 + buffer_pct/100)
        Schema::create('ds_plan_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');

            // The PRODUCTION date — the day this dough is for, not the day it
            // was confirmed. Dough is kneaded at night for the following day.
            $table->date('plan_date');

            $table->string('ingredient_key', 40);

            // ── The frozen snapshot ──
            // buffer_pct is copied, never referenced. If the manager raises his
            // buffer tomorrow, today's confirmed plan must keep the number the
            // night shift actually kneaded against — otherwise every past
            // variance silently recomputes against a target nobody was given.
            $table->decimal('buffer_pct', 5, 2);
            $table->decimal('planned_qty', 12, 4);

            $table->dateTime('confirmed_at');
            $table->unsignedBigInteger('confirmed_by');

            $table->timestamps();

            // One confirmed line per store + production date + ingredient.
            $table->unique(['store_id', 'plan_date', 'ingredient_key'], 'ds_plan_unique');

            // plan_date alone: the specialist's "all stores, one day" screen.
            // (store_id, plan_date): one store's week.
            $table->index('plan_date');
            $table->index(['store_id', 'plan_date']);

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ds_plan_days');
    }
};
