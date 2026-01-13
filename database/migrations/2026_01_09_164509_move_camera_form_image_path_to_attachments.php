<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Create attachments table
        Schema::create('camera_form_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_form_id')
                ->constrained('camera_forms')
                ->cascadeOnDelete();

            $table->string('path'); // storage path on public disk
            $table->timestamps();

            $table->index(['camera_form_id']);
        });

        // 2) Migrate existing image_path -> camera_form_attachments
        if (Schema::hasColumn('camera_forms', 'image_path')) {
            $rows = DB::table('camera_forms')
                ->select('id', 'image_path', 'created_at', 'updated_at')
                ->whereNotNull('image_path')
                ->where('image_path', '!=', '')
                ->get();

            foreach ($rows as $row) {
                DB::table('camera_form_attachments')->insert([
                    'camera_form_id' => $row->id,
                    'path' => $row->image_path,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }

            // 3) Drop old column AFTER migrating
            Schema::table('camera_forms', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }

    public function down(): void
    {
        // Restore image_path column
        if (!Schema::hasColumn('camera_forms', 'image_path')) {
            Schema::table('camera_forms', function (Blueprint $table) {
                $table->string('image_path')->nullable()->after('note');
            });
        }

        // Put first attachment back into image_path (best-effort)
        if (Schema::hasTable('camera_form_attachments')) {
            $firstAttachments = DB::table('camera_form_attachments')
                ->select('camera_form_id', DB::raw('MIN(id) as min_id'))
                ->groupBy('camera_form_id')
                ->get();

            foreach ($firstAttachments as $fa) {
                $att = DB::table('camera_form_attachments')->where('id', $fa->min_id)->first();
                if ($att) {
                    DB::table('camera_forms')
                        ->where('id', $att->camera_form_id)
                        ->update(['image_path' => $att->path]);
                }
            }

            Schema::dropIfExists('camera_form_attachments');
        }
    }
};
