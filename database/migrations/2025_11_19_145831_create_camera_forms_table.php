<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('camera_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('entity_id')->constrained('entities')->onDelete('no action');
            $table->foreignId('audit_id')->nullable()->constrained('audits')->onDelete('cascade');
            $table->foreignId('rating_id')->nullable()->constrained('ratings')->onDelete('set null');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('camera_forms');
    }
};
