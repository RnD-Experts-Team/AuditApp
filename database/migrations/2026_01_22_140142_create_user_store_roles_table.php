<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_store_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();

            $table->unsignedBigInteger('user_id');

            // NULL store_id means "all stores"
            $table->unsignedBigInteger('store_id')->nullable();

            // store-scoped role coming from Auth system, ex: "qa_auditor"
            $table->string('role_name');

            $table->boolean('active')->default(true);

            // Optional: metadata from auth system events (json, source, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'store_id']);
            $table->index(['user_id', 'role_name']);
            $table->unique(['user_id', 'store_id', 'role_name'], 'usr_store_role_unique');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_store_roles');
    }
};
