<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection items get a weight, like chart tasks already have.
 *
 * The default is 1 ON PURPOSE: with every item at weight 1, the weighted score
 * is arithmetically identical to the old count-based score, so nothing changes
 * for any store on the day this deploys. Weights only start to matter once
 * someone edits one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('weight')->default(1)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_items', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
